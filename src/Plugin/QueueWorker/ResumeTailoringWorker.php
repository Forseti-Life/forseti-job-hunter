<?php

namespace Drupal\job_hunter\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\job_hunter\Service\ResumeLengthPolicy;
use Drupal\job_hunter\Service\TailoringRunService;
use Drupal\job_hunter\Traits\JobHunterLoggerTrait;
use Drupal\job_hunter\Traits\QueueWorkerBaseTrait;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resume Tailoring GenAI queue worker.
 *
 * Processes resume tailoring via DeepSeek in the background: section
 * drafts, then a final whole-resume editing pass verified against the
 * rendered page count.
 *
 * @QueueWorker(
 *   id = "job_hunter_resume_tailoring",
 *   title = @Translation("Resume Tailoring GenAI"),
 *   cron = {"time" = 180}
 * )
 */
class ResumeTailoringWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use JobHunterLoggerTrait;
  use QueueWorkerBaseTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The AI API service.
   *
   * @var \Drupal\ai_conversation\Service\AIApiService
   */
  protected $aiApiService;

  /**
   * The tailoring run service.
   *
   * @var \Drupal\job_hunter\Service\TailoringRunService
   */
  protected $tailoringRunService;

  /**
   * The tailored resume length policy.
   *
   * @var \Drupal\job_hunter\Service\ResumeLengthPolicy
   */
  protected $resumeLengthPolicy;

  /**
   * The resume PDF service, used to verify the rendered page count.
   *
   * @var \Drupal\job_hunter\Service\ResumePdfService
   */
  protected $resumePdfService;

  /**
   * DeepSeek model used for every resume tailoring call.
   */
  private const TAILORING_MODEL = 'deepseek-v4-pro';

  /**
   * Output budgets. Thinking is disabled for section drafts so reasoning
   * tokens cannot consume the budget meant for the JSON response.
   */
  private const SECTION_MAX_TOKENS = 8000;
  private const FINAL_PASS_MAX_TOKENS = 32000;

  /**
   * Total word budget for the final pass; about 1,200 words renders to four
   * pages in the default resume style.
   */
  private const FINAL_PASS_WORD_BUDGET = 1400;

  /**
   * Concise-writing rules shared by every tailoring prompt.
   */
  private const WRITING_RULES = <<<'RULES'
Writing rules (the complete resume must fit within 5 pages and focus on the most recent 10 years):
- Write complete, clear sentences. Lead with the outcome, then how it was achieved.
- One idea per sentence and per bullet. Use concrete metrics from the candidate data when available.
- No filler, buzzword chains, ellipses, or content repeated across sections.
- If content does not fit the budget, leave out the least job-relevant item instead of compressing it into fragments.
RULES;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    $instance->aiApiService = $container->get('ai_conversation.ai_api_service');
    $instance->tailoringRunService = $container->get('job_hunter.tailoring_run_service');
    $instance->resumeLengthPolicy = $container->get('job_hunter.resume_length_policy');
    $instance->resumePdfService = $container->get('job_hunter.resume_pdf_service');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $retry_count = (int) ($data['retry_count'] ?? 0);

    // Respect exponential backoff — if this is a retried item that should not
    // be processed yet, re-delay it and release back to the queue.
    $process_after = (int) ($data['process_after'] ?? 0);
    if ($process_after > time()) {
      $job_id_for_log = $data['job_id'] ?? ('run ' . ($data['run_id'] ?? 'n/a'));
      throw new DelayedRequeueException($process_after - time(), "Backoff delay not yet elapsed for job {$job_id_for_log}");
    }

    $run_id = $data['run_id'] ?? NULL;

    if ($run_id) {
      // Canonical event-driven path: the queue payload only ever carries a
      // run_id. Resolve the actual uid/job_id/profile/job inputs fresh from
      // the database via the run record.
      $resolved = $this->tailoringRunService->resolveRunInputs($run_id);
      if (!$resolved) {
        $this->logError('Queue: Resume tailoring discarded — could not resolve inputs for run @run_id', [
          '@run_id' => $run_id,
        ]);
        return;
      }
      $uid = $resolved['uid'];
      $job_id = $resolved['job_id'];
      $profile_json = $resolved['profile_json'];
      $job_data = $resolved['job_data'];
    }
    else {
      // Narrow migration path only: adopts a full-payload queue item that
      // predates the run/outbox model (or was enqueued by a producer that
      // has not been migrated) into a run record, so that every subsequent
      // lifecycle transition — including retries — is uniformly run-based.
      // This is NOT a supported permanent producer path; all current
      // dispatch goes through TailoringRunService::createOrReuseRun() and
      // carries only a run_id.
      foreach (['uid', 'job_id', 'profile_json', 'job_data'] as $field) {
        if (empty($data[$field])) {
          $this->logError('Queue: Resume tailoring discarded — missing required field "@field" in legacy queue item data (job @job_id)', [
            '@field' => $field,
            '@job_id' => $data['job_id'] ?? 'unknown',
          ]);
          return;
        }
      }

      $uid = (int) $data['uid'];
      $job_id = (int) $data['job_id'];
      $profile_json = $data['profile_json'];
      $job_data = $data['job_data'];

      $run_id = $this->tailoringRunService->adoptLegacyQueueItem($uid, $job_id);

      $this->logWarning('Queue: Adopted legacy full-payload queue item for uid @uid job @job_id into run @run_id. This path is for draining pre-existing items only.', [
        '@uid' => $uid,
        '@job_id' => $job_id,
        '@run_id' => $run_id,
      ]);
    }

    // From this point forward $run_id is always populated: all lifecycle
    // transitions (processing/completed/failed/retry) are tracked on the
    // run record, whether this item arrived via the canonical run_id
    // payload or was just adopted from a legacy full payload above.

    // Get logging context (username, company, job_title)
    $context = $this->getLoggingContext($uid, $job_data);
    
    $this->logInfo('🔄 Queue: Starting resume tailoring for @username → "@title" at @company (job @job_id, attempt @attempt)', [
      '@username' => $context['username'],
      '@title' => $context['job_title'],
      '@company' => $context['company'],
      '@job_id' => $job_id,
      '@attempt' => $retry_count + 1,
    ]);
    
    // Parse job extracted data for payload
    $extracted = !empty($job_data['extracted_json']) ? json_decode($job_data['extracted_json'], TRUE) : [];

    $connection = \Drupal::database();

    try {
      // Update status to processing. Keep run_uuid current so the resume
      // record always points at whichever run is actively driving it,
      // even after a prior run on this uid/job_id failed.
      $record_fields = [];
      if ($connection->schema()->fieldExists('jobhunter_tailored_resumes', 'run_uuid')) {
        $record_fields['run_uuid'] = $run_id;
      }
      $this->updateDatabaseStatus($connection, 'jobhunter_tailored_resumes', $uid, $job_id, 'processing', $record_fields);

      // Parse job data (extracted already parsed above for logging)
      $skills = !empty($job_data['skills_required_json']) ? json_decode($job_data['skills_required_json'], TRUE) : [];
      $keywords = !empty($job_data['keywords_json']) ? json_decode($job_data['keywords_json'], TRUE) : [];

      // Build the GenAI request payload
      $genai_payload = [
        'action' => 'generate_tailored_resume',
        'job_requisition' => [
          'id' => (int) $job_id,
          'extracted_json' => $extracted,
          'skills_required_json' => $skills,
          'keywords_json' => $keywords,
          'raw_posting_text' => $job_data['raw_posting_text'] ?? '',
        ],
        'user_resume' => [
          'consolidated_profile_json' => $profile_json,
        ],
      ];

      // Call AWS Bedrock via AIApiService
      $tailored_result = $this->callGenAiTailoringService($genai_payload, $uid, $job_id);

      if (!$tailored_result || !isset($tailored_result['tailored_resume_json'])) {
        // AI service returned an unusable result — treat as transient (JSON parse
        // failure or truncated response) so retry logic applies.
        throw new \RuntimeException("GenAI returned no usable result for job {$job_id}. JSON parse may have failed; see prior log entries.");
      }

      // Save the tailored resume. Clear any stale error_message from a
      // previous failed run on this uid/job_id, now that this run succeeded.
      $this->updateDatabaseStatus(
        $connection,
        'jobhunter_tailored_resumes',
        $uid,
        $job_id,
        'completed',
        $record_fields + [
          'tailored_resume_json' => json_encode($tailored_result['tailored_resume_json']),
          'error_message' => NULL,
        ]
      );

      if ($run_id) {
        $this->tailoringRunService->markRunStatus($run_id, 'completed');
      }

      $this->logInfo('✅ Queue: Resume tailoring complete for @username → "@title" at @company (job @job_id)', [
        '@username' => $context['username'],
        '@title' => $context['job_title'],
        '@company' => $context['company'],
        '@job_id' => $job_id,
      ]);

    }
    catch (\Exception $e) {
      $this->handleQueueExceptionWithRetry($e, $connection, $data, $retry_count, $context, $uid, $job_id, $run_id);
    }
  }

  /**
   * Classify an exception as 'transient' or 'permanent' for retry decisions.
   *
   * Transient: HTTP 5xx, connection timeout, rate limit (429).
   * Permanent: HTTP 4xx auth errors, malformed data, or unknown errors.
   *
   * @param \Exception $e
   *   The caught exception.
   *
   * @return string
   *   'transient' or 'permanent'.
   */
  private function classifyException(\Exception $e): string {
    if ($e instanceof \LengthException) {
      return 'permanent';
    }
    // Guzzle HTTP server errors (5xx) are always transient.
    if ($e instanceof ServerException) {
      return 'transient';
    }
    // Network/connection errors are always transient.
    if ($e instanceof ConnectException) {
      return 'transient';
    }
    // Guzzle HTTP client errors: 429 rate-limit is transient; other 4xx are permanent.
    if ($e instanceof ClientException) {
      $code = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
      return ($code === 429) ? 'transient' : 'permanent';
    }

    // Fall back to message inspection for non-Guzzle exceptions.
    $message = strtolower($e->getMessage());
    $transient_patterns = ['timeout', 'timed out', 'connection', '503', '502', '500', '429', 'rate limit', 'throttl', 'unavailable', 'no usable result'];
    foreach ($transient_patterns as $pattern) {
      if (strpos($message, $pattern) !== FALSE) {
        return 'transient';
      }
    }

    $permanent_patterns = ['unauthorized', '401', '403', 'forbidden', 'missing required'];
    foreach ($permanent_patterns as $pattern) {
      if (strpos($message, $pattern) !== FALSE) {
        return 'permanent';
      }
    }

    // Default: transient (allows retry for unknown errors).
    return 'transient';
  }

  /**
   * Handle queue exception with retry/backoff logic.
   *
   * Transient failures with remaining retries are re-queued with exponential
   * backoff. Permanent failures (or exhausted retries) discard the item and
   * record 'failed' status. No exception is re-thrown so the item is consumed.
   *
   * @param \Exception $e
   *   The caught exception.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param array $data
   *   The original queue item data.
   * @param int $retry_count
   *   Current retry attempt count (0-based).
   * @param array $context
   *   Logging context (username, company, job_title).
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job ID.
   * @param string $run_id
   *   The tailoring run UUID. Always populated: canonical run_id payloads
   *   carry it directly, and legacy full payloads are adopted into a run
   *   before this method is ever reached (see processItem()).
   */
  private function handleQueueExceptionWithRetry(\Exception $e, $connection, array $data, int $retry_count, array $context, int $uid, int $job_id, string $run_id): void {
    $error_type = $this->classifyException($e);

    $this->logError('❌ Queue: Resume tailoring @error_type failure for @username → job @job_id (attempt @attempt/3): @error', [
      '@error_type' => $error_type,
      '@username' => $context['username'] ?? 'unknown',
      '@job_id' => $job_id,
      '@attempt' => $retry_count + 1,
      '@error' => $e->getMessage(),
    ]);

    $max_retries = 3;

    if ($error_type === 'transient' && $retry_count < $max_retries) {
      // Exponential backoff: 30s, 60s, 120s for attempts 1/2/3.
      $backoff_seconds = (int) pow(2, $retry_count) * 30;

      // Retry payload is always the canonical run_id-only shape, regardless
      // of whether the original item arrived as a run_id payload or was
      // adopted from a legacy full payload — this guarantees legacy items
      // are drained into the canonical shape after their first retry.
      $retry_data = [
        'run_id' => $run_id,
        'retry_count' => $retry_count + 1,
        'process_after' => time() + $backoff_seconds,
      ];

      \Drupal::queue('job_hunter_resume_tailoring')->createItem($retry_data);

      $this->logError('⏳ Queue: Scheduled retry @retry/@max for job @job_id in @backoff seconds', [
        '@retry' => $retry_count + 1,
        '@max' => $max_retries,
        '@job_id' => $job_id,
        '@backoff' => $backoff_seconds,
      ]);

      // Reset DB status to pending so the item does not appear stuck as 'processing'.
      $this->updateDatabaseStatus($connection, 'jobhunter_tailored_resumes', $uid, $job_id, 'pending');

      if ($run_id) {
        // Reset run status back to 'queued' so status polling reflects the
        // pending backoff retry rather than an indefinite 'processing'.
        $this->tailoringRunService->markRunStatus($run_id, 'queued');
      }
    }
    else {
      $reason = ($error_type === 'transient') ? "max retries exhausted ({$max_retries}/{$max_retries})" : "permanent failure ({$error_type})";

      $this->logError('🚫 Queue: Resume tailoring discarded for job @job_id — @reason', [
        '@job_id' => $job_id,
        '@reason' => $reason,
      ]);

      $this->updateDatabaseStatus($connection, 'jobhunter_tailored_resumes', $uid, $job_id, 'failed', [
        'error_message' => substr($e->getMessage(), 0, 500),
      ]);

      if ($run_id) {
        $this->tailoringRunService->markRunStatus($run_id, 'failed', [
          'error_message' => substr($e->getMessage(), 0, 500),
        ]);
      }
    }
    // Do NOT re-throw — item is consumed (deleted) from the queue.
  }

  /**
   * Call AWS Bedrock for resume tailoring via AIApiService.
   * Uses batched approach to avoid Claude 4,096 output token limit.
   */
  private function callGenAiTailoringService(array $payload, int $uid, int $job_id) {
    return $this->batchedTailoredResume($payload, $uid, $job_id);
  }

  /**
   * Generate tailored resume using batched API calls.
   * 
   * Splits generation into multiple smaller requests to avoid 4,096 output token limit:
   * - Batch 1: Metadata + contact + profile + differentiators
   * - Batch 2-N: One batch per company (professional experience)
   * - Batch N+1: Education + technical skills + other sections
   */
  private function batchedTailoredResume(array $payload, int $uid, int $job_id) {
    try {
      $resume = $payload['user_resume']['consolidated_profile_json'] ?? [];
      $job = $payload['job_requisition'] ?? [];
      
      $this->logInfo('🔀 Starting BATCHED resume generation (to avoid 4,096 token output limit)');
      
      // BATCH 1: Metadata + contact + profile + differentiators
      $this->logInfo('📦 Batch 1: Generating metadata + contact + profile + differentiators');
      $metadata_result = $this->callBatchedSection(
        $this->buildMetadataPrompt($payload),
        $uid,
        $job_id,
        'metadata'
      );
      if (!$metadata_result) {
        $this->logError('❌ Failed to generate metadata section');
        return NULL;
      }
      
      // BATCH 2-N: One batch per company in professional_experience
      $experience_entries = [];
      $companies = $this->resumeLengthPolicy->selectExperience($resume['professional_experience'] ?? []);
      $company_count = count($companies);
      
      $this->logInfo('📦 Batches 2-{$count}: Generating {$count} professional experience entries', [
        '{$count}' => $company_count,
      ]);
      
      foreach ($companies as $index => $experience_selection) {
        $company = $experience_selection['entry'];
        $is_recent = $experience_selection['is_recent'];
        $batch_num = $index + 2;
        $company_name = $company['company'] ?? 'Unknown';
        $this->logInfo('📦 Batch @num/@total: Generating experience for @company', [
          '@num' => $batch_num,
          '@total' => $company_count + 2,
          '@company' => $company_name,
        ]);
        
        $exp_result = $this->callBatchedSection(
          $this->buildExperiencePrompt($payload, $company, $index, $is_recent),
          $uid,
          $job_id,
          "experience_{$index}"
        );
        
        if (!$exp_result) {
          $this->logError('❌ Failed to generate experience for @company', ['@company' => $company_name]);
          return NULL;
        }
        
        $experience_entries[] = $exp_result;
      }
      
      // BATCH N+1: Education + technical + other sections
      $final_batch_num = $company_count + 2;
      $this->logInfo('📦 Batch @num/@total: Generating education + technical + other sections', [
        '@num' => $final_batch_num,
        '@total' => $final_batch_num,
      ]);
      
      $other_result = $this->callBatchedSection(
        $this->buildOtherSectionsPrompt($payload),
        $uid,
        $job_id,
        'other_sections'
      );
      
      if (!$other_result) {
        $this->logError('❌ Failed to generate other sections');
        return NULL;
      }
      
      // Combine all batches into final resume JSON
      $tailored_resume = array_merge(
        $metadata_result,
        ['professional_experience' => $experience_entries],
        $other_result
      );

      // Normalize schema drift before saving. The model is allowed to return
      // a plausible JSON structure that still does not match the canonical
      // renderer contract expected by the UI/PDF layer. Normalize here so the
      // persisted record matches the actual consumer contract.
      $tailored_resume = $this->normalizeTailoredResumeSchema($tailored_resume, $resume);
      $tailored_resume = $this->normalizeTechnicalExpertise($tailored_resume, $resume);
      $tailored_resume = $this->resumeLengthPolicy->apply($tailored_resume);

      $this->logInfo('✅ Combined @count batches into a draft; starting final whole-resume pass', [
        '@count' => $final_batch_num,
      ]);

      $tailored_resume = $this->finalizeResume($payload, $tailored_resume, $resume, $uid, $job_id);
      if ($tailored_resume === NULL) {
        return NULL;
      }
      
      return [
        'tailored_resume_json' => $tailored_resume,
        'tailoring_guidance' => $tailored_resume['tailoring_metadata']['guidance'] ?? NULL,
      ];
    }
    catch (\Exception $e) {
      $this->logError('Batched resume generation failed: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Normalize the model's response into the canonical resume schema consumed by
   * the UI and PDF renderer.
   *
   * @param array $tailored_resume
   *   Tailored resume JSON assembled from model batches.
   * @param array $source_resume
   *   Source consolidated profile JSON.
   *
   * @return array
   *   Tailored resume normalized to the canonical schema.
   */
  private function normalizeTailoredResumeSchema(array $tailored_resume, array $source_resume): array {
    $normalized = $tailored_resume;

    if (isset($normalized['contact_info']) && is_array($normalized['contact_info'])) {
      $contact = $normalized['contact_info'];
      $source_contact = is_array($source_resume['contact_info'] ?? NULL) ? $source_resume['contact_info'] : [];

      $full_name = trim((string) ($contact['full_name'] ?? $contact['name'] ?? $source_contact['full_name'] ?? $source_contact['name'] ?? ''));
      $email = trim((string) ($contact['email'] ?? $source_contact['email'] ?? ''));
      $phone = trim((string) ($contact['phone'] ?? $source_contact['phone'] ?? ''));
      $headline = trim((string) ($contact['headline'] ?? $source_contact['headline'] ?? ''));
      $location = $contact['location'] ?? $source_contact['location'] ?? [];
      $websites = [];
      if (!empty($contact['websites']) && is_array($contact['websites'])) {
        $websites = $this->normalizeWebsites($contact['websites']);
      }
      elseif (!empty($contact['website'])) {
        $websites = $this->normalizeWebsites([$contact['website']]);
      }
      elseif (!empty($source_contact['websites']) && is_array($source_contact['websites'])) {
        $websites = $this->normalizeWebsites($source_contact['websites']);
      }

      $linkedin = $contact['linkedin'] ?? $source_contact['linkedin'] ?? [];
      if (is_string($linkedin)) {
        $linkedin = ['url' => $linkedin, 'followers' => 0];
      }
      elseif (!is_array($linkedin)) {
        $linkedin = [];
      }

      $normalized['contact_info'] = [
        'full_name' => $full_name,
        'credentials' => array_values(array_filter(array_map('trim', (array) ($contact['credentials'] ?? [])))),
        'headline' => $headline,
        'location' => $this->normalizeLocationObject($location),
        'phone' => $phone,
        'email' => $email,
        'websites' => $websites,
        'linkedin' => $linkedin,
      ];
    }

    if (!empty($normalized['professional_experience'])) {
      $normalized['professional_experience'] = $this->normalizeProfessionalExperienceEntries($normalized['professional_experience']);
    }

    if (!empty($normalized['strategic_differentiators'])) {
      $normalized['strategic_differentiators'] = $this->normalizeStrategicDifferentiators($normalized['strategic_differentiators']);
    }

    if (!empty($normalized['executive_profile']) && is_string($normalized['executive_profile'])) {
      $normalized['executive_profile'] = ['summary' => $normalized['executive_profile']];
    }

    return $normalized;
  }

  /**
   * Normalize professional experience entries into canonical schema.
   */
  private function normalizeProfessionalExperienceEntries(array $entries): array {
    $normalized = [];

    foreach ($entries as $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $position_entries = [];
      if (!empty($entry['positions']) && is_array($entry['positions'])) {
        $position_entries = $entry['positions'];
      }
      else {
        $position_entries[] = $entry;
      }

      foreach ($position_entries as $position) {
        if (!is_array($position)) {
          continue;
        }

        $company = trim((string) ($position['company'] ?? $entry['company'] ?? ''));
        $title = trim((string) ($position['title'] ?? $position['role'] ?? $position['position'] ?? $entry['title'] ?? ''));
        if ($company === '' && $title !== '') {
          $company = trim((string) ($entry['company'] ?? ''));
        }

        $location = trim((string) ($position['location'] ?? $entry['location'] ?? ''));
        $tenure = $position['tenure'] ?? $position['duration'] ?? $entry['tenure'] ?? $entry['duration'] ?? null;
        $range = $this->resumeLengthPolicy->parseDateRange($tenure, $position['start_date'] ?? $entry['start_date'] ?? null, $position['end_date'] ?? $entry['end_date'] ?? null);
        $company_context = trim((string) ($position['company_context'] ?? $position['summary'] ?? $entry['company_context'] ?? $entry['summary'] ?? ''));

        $responsibility_categories = $this->normalizeResponsibilityCategories($position['responsibility_categories'] ?? $entry['responsibility_categories'] ?? []);
        if ($responsibility_categories === [] && !empty($position['achievements']) && is_array($position['achievements'])) {
          $responsibility_categories[] = [
            'category' => 'Key Achievements',
            'achievements' => $this->normalizeAchievementList($position['achievements']),
          ];
        }
        if ($responsibility_categories === [] && !empty($entry['achievements']) && is_array($entry['achievements'])) {
          $responsibility_categories[] = [
            'category' => 'Key Achievements',
            'achievements' => $this->normalizeAchievementList($entry['achievements']),
          ];
        }

        $normalized[] = [
          'title' => $title,
          'company' => $company,
          'start_date' => $range['start_date'] ?? '',
          'end_date' => $range['end_date'] ?? 'Present',
          'location' => $location,
          'company_context' => $company_context,
          'responsibility_categories' => $responsibility_categories,
        ];
      }
    }

    return $normalized;
  }

  /**
   * Normalize strategic differentiator strings/objects to the canonical schema.
   */
  private function normalizeStrategicDifferentiators(array $items): array {
    $normalized = [];

    foreach ($items as $item) {
      if (is_array($item)) {
        $title = trim((string) ($item['title'] ?? ''));
        $description = trim((string) ($item['description'] ?? $item['summary'] ?? ''));
        if ($title !== '' || $description !== '') {
          $normalized[] = [
            'title' => $title,
            'description' => $description,
          ];
        }
        continue;
      }

      $text = trim((string) $item);
      if ($text === '') {
        continue;
      }

      $parts = preg_split('/\s*:\s*/', $text, 2);
      $title = trim((string) ($parts[0] ?? ''));
      $description = trim((string) ($parts[1] ?? $text));
      $normalized[] = [
        'title' => $title,
        'description' => $description,
      ];
    }

    return $normalized;
  }

  /**
   * Normalize websites into the canonical array of {url} objects.
   */
  private function normalizeWebsites(array $items): array {
    $normalized = [];
    foreach ($items as $item) {
      if (is_array($item) && !empty($item['url'])) {
        $url = $this->coerceString($item['url']);
        if ($url !== '') {
          $normalized[] = ['url' => $url];
        }
        continue;
      }

      $url = $this->coerceString($item);
      if ($url !== '') {
        $normalized[] = ['url' => $url];
      }
    }

    return $normalized;
  }

  /**
   * Normalize a location value to the object schema used by the renderer.
   */
  private function normalizeLocationObject($location): array {
    if (is_array($location)) {
      return [
        'city' => $this->coerceString($location['city'] ?? $location['name'] ?? ''),
        'state' => $this->coerceString($location['state'] ?? ''),
        'country' => $this->coerceString($location['country'] ?? ''),
      ];
    }

    $location_text = $this->coerceString($location);
    if ($location_text === '') {
      return [];
    }

    $parts = preg_split('/\s*,\s*/', $location_text, 2);
    return [
      'city' => $this->coerceString($parts[0] ?? $location_text),
      'state' => $this->coerceString($parts[1] ?? ''),
      'country' => '',
    ];
  }

  /**
   * Coerce mixed scalar/array values into a safe string.
   */
  private function coerceString($value): string {
    if (is_array($value)) {
      foreach ($value as $candidate) {
        $text = $this->coerceString($candidate);
        if ($text !== '') {
          return $text;
        }
      }
      return '';
    }

    if ($value === NULL) {
      return '';
    }

    return trim((string) $value);
  }

  /**
   * Convert mixed responsibility category shapes to the canonical array.
   */
  private function normalizeResponsibilityCategories(array $categories): array {
    $normalized = [];

    foreach ($categories as $category) {
      if (!is_array($category)) {
        continue;
      }

      $name = trim((string) ($category['category'] ?? $category['name'] ?? 'Key Responsibilities'));
      if ($name === '') {
        $name = 'Key Responsibilities';
      }

      $achievement_entries = [];
      if (!empty($category['achievements']) && is_array($category['achievements'])) {
        $achievement_entries = $this->normalizeAchievementList($category['achievements']);
      }
      elseif (!empty($category['items']) && is_array($category['items'])) {
        $achievement_entries = $this->normalizeAchievementList($category['items']);
      }
      elseif (!empty($category['description'])) {
        $achievement_entries = [['text' => trim((string) $category['description'])]];
      }

      $normalized[] = [
        'category' => $name,
        'achievements' => $achievement_entries,
      ];
    }

    return $normalized;
  }

  /**
   * Convert mixed achievement values into canonical {text} objects.
   */
  private function normalizeAchievementList(array $items): array {
    $normalized = [];

    foreach ($items as $item) {
      if (is_array($item)) {
        $text = trim((string) ($item['text'] ?? $item['description'] ?? $item['achievement'] ?? ''));
        if ($text !== '') {
          $normalized[] = ['text' => $text];
        }
        continue;
      }

      $text = trim((string) $item);
      if ($text !== '') {
        $normalized[] = ['text' => $text];
      }
    }

    return $normalized;
  }

  /**
   * Normalize technical_expertise into expected schema for UI/PDF renderers.
   *
   * Expected schema:
   * {
   *   "technical_expertise": {
   *     "categories": [
   *       {"name": "Category", "skills": ["Skill 1", "Skill 2"]}
   *     ]
   *   }
   * }
   *
   * @param array $tailored_resume
   *   Tailored resume JSON assembled from model batches.
   * @param array $source_resume
   *   Source consolidated profile JSON.
   *
   * @return array
   *   Tailored resume with normalized technical_expertise structure.
   */
  private function normalizeTechnicalExpertise(array $tailored_resume, array $source_resume): array {
    if (empty($tailored_resume['technical_expertise'])) {
      return $tailored_resume;
    }

    $technical = $tailored_resume['technical_expertise'];
    if (!is_array($technical)) {
      return $tailored_resume;
    }

    $categories = $technical['categories'] ?? NULL;
    if (!is_array($categories)) {
      return $tailored_resume;
    }

    $source_map = $this->buildTechnicalCategoryMap($source_resume['technical_expertise'] ?? []);

    $normalized_categories = [];
    $category_seen = [];
    $core_skills = [];

    foreach ($categories as $entry) {
      // Proper object shape already.
      if (is_array($entry) && !empty($entry['name']) && isset($entry['skills']) && is_array($entry['skills'])) {
        $name = trim((string) $entry['name']);
        $skills = array_values(array_unique(array_filter(array_map('trim', $entry['skills']))));

        if ($skills === []) {
          $source_key = $this->normalizeLabelKey($name);
          $skills = $source_map[$source_key]['skills'] ?? [];
        }

        if ($name !== '' && !isset($category_seen[$this->normalizeLabelKey($name)])) {
          $normalized_categories[] = [
            'name' => $name,
            'skills' => $skills,
          ];
          $category_seen[$this->normalizeLabelKey($name)] = TRUE;
        }
        continue;
      }

      // Bare string: could be a category label OR a skill.
      if (is_string($entry)) {
        $label = trim($entry);
        if ($label === '') {
          continue;
        }

        $lookup = $this->normalizeLabelKey($label);
        if (isset($source_map[$lookup])) {
          if (!isset($category_seen[$lookup])) {
            $normalized_categories[] = [
              'name' => $source_map[$lookup]['display'],
              'skills' => $source_map[$lookup]['skills'],
            ];
            $category_seen[$lookup] = TRUE;
          }
        }
        else {
          $core_skills[] = $label;
        }
      }
    }

    // Any stray skills become a single category to preserve model emphasis.
    if ($core_skills !== []) {
      $normalized_categories[] = [
        'name' => 'Core Technical Skills',
        'skills' => array_values(array_unique($core_skills)),
      ];
    }

    // Hard fallback: if everything collapsed, rebuild from source map.
    if ($normalized_categories === [] && $source_map !== []) {
      foreach ($source_map as $mapped) {
        $normalized_categories[] = [
          'name' => $mapped['display'],
          'skills' => $mapped['skills'],
        ];
      }
    }

    $tailored_resume['technical_expertise']['categories'] = $normalized_categories;
    return $tailored_resume;
  }

  /**
   * Build category -> skills map from mixed source technical_expertise shapes.
   *
   * @param mixed $source_technical
   *   Source technical_expertise section.
   *
  * @return array
  *   Map keyed by normalized category label:
  *   [key => ['display' => string, 'skills' => string[]]].
   */
  private function buildTechnicalCategoryMap($source_technical): array {
    if (!is_array($source_technical)) {
      return [];
    }

    $map = [];
    foreach ($source_technical as $key => $value) {
      // Skip legacy list of category names if present.
      if ($key === 'categories' && is_array($value)) {
        continue;
      }

      // Numeric entries may use {category, skills:[{name,...}]}
      if (is_int($key) || ctype_digit((string) $key)) {
        if (is_array($value) && !empty($value['category'])) {
          $name = trim((string) $value['category']);
          $skills = $this->extractSkillNames($value['skills'] ?? []);
          if ($name !== '' && $skills !== []) {
            $map[$this->normalizeLabelKey($name)] = [
              'display' => $name,
              'skills' => $skills,
            ];
          }
        }
        continue;
      }

      // Associative entries map category -> [skills].
      $name = trim((string) $key);
      $skills = $this->extractSkillNames($value);
      if ($name !== '' && $skills !== []) {
        $map[$this->normalizeLabelKey($name)] = [
          'display' => $name,
          'skills' => $skills,
        ];
      }
    }

    return $map;
  }

  /**
   * Extract a flat string skill list from mixed skill entry shapes.
   */
  private function extractSkillNames($value): array {
    if (!is_array($value)) {
      return [];
    }

    $skills = [];
    foreach ($value as $item) {
      if (is_string($item)) {
        $item = trim($item);
        if ($item !== '') {
          $skills[] = $item;
        }
      }
      elseif (is_array($item) && !empty($item['name']) && is_string($item['name'])) {
        $name = trim($item['name']);
        if ($name !== '') {
          $skills[] = $name;
        }
      }
    }

    return array_values(array_unique($skills));
  }

  /**
   * Normalize labels for safe map lookups.
   */
  private function normalizeLabelKey(string $label): string {
    return strtolower(trim($label));
  }

  /**
   * Extract JSON from AI response that may contain markdown or text.
   */
  private function extractJsonFromResponse($response) {
    $response_text = trim((string) $response);
    $original_length = strlen($response_text);

    \Drupal::logger('job_hunter')->info('🔍 extractJsonFromResponse START: input_length=@len', ['@len' => $original_length]);

    if ($response_text === '') {
      \Drupal::logger('job_hunter')->error('❌ extractJsonFromResponse: Empty response after trim');
      return NULL;
    }

    // Aggressively normalize string-escaped JSON returned by models.
    $has_literal_newlines = strpos($response_text, "\n") !== FALSE;
    $has_literal_quotes = strpos($response_text, '\"') !== FALSE;
    $has_literal_tabs = strpos($response_text, "\t") !== FALSE;
    if ($has_literal_newlines || $has_literal_quotes || $has_literal_tabs) {
      $before_length = strlen($response_text);
      $response_text = stripcslashes($response_text);
      $response_text = trim($response_text);
      \Drupal::logger('job_hunter')->warning('🟡 APPLIED stripcslashes normalization: before_len=@before, after_len=@after', [
        '@before' => $before_length,
        '@after' => strlen($response_text),
      ]);
    }

    // Strip markdown fences if present.
    if (preg_match('/^```(?:json)?\s*(\{[\s\S]*\})\s*```\s*$/', $response_text, $matches)) {
      $response_text = trim($matches[1]);
      \Drupal::logger('job_hunter')->info('✅ Removed markdown wrapper before parse');
    }

    $decoded = $this->decodeJsonCandidate($response_text);
    if ($decoded !== NULL) {
      return $decoded;
    }

    $start_pos = strpos($response_text, '{');
    if ($start_pos === FALSE) {
      \Drupal::logger('job_hunter')->error('❌ BRACE COUNTING: No opening brace found in response');
      return NULL;
    }

    $len = strlen($response_text);
    $depth = 0;
    $in_string = FALSE;
    $escape_next = FALSE;
    $last_close_brace_pos = -1;

    for ($i = $start_pos; $i < $len; $i++) {
      $char = $response_text[$i];

      if ($escape_next) {
        $escape_next = FALSE;
        continue;
      }
      if ($char === '\\') {
        $escape_next = TRUE;
        continue;
      }
      if ($char === '"') {
        $in_string = !$in_string;
        continue;
      }
      if ($in_string) {
        continue;
      }
      if ($char === '{') {
        $depth++;
      }
      elseif ($char === '}') {
        $depth--;
        $last_close_brace_pos = $i;

        // A valid JSON object may exist before a later malformed tail.
        $candidate = substr($response_text, $start_pos, $i - $start_pos + 1);
        $decoded = $this->decodeJsonCandidate($candidate);
        if ($decoded !== NULL) {
          \Drupal::logger('job_hunter')->info('✅ RECOVERY SUCCESS: Found valid JSON candidate ending at position @pos', ['@pos' => $i]);
          return $decoded;
        }
      }
    }

    // Final fallback: trim recoverable trailing defects before a final decode.
    if ($last_close_brace_pos > $start_pos) {
      $candidate = substr($response_text, $start_pos, $last_close_brace_pos - $start_pos + 1);
      $sanitized = preg_replace('/,\s*([}\]])/', '$1', $candidate);
      $decoded = $this->decodeJsonCandidate($sanitized ?? $candidate);
      if ($decoded !== NULL) {
        \Drupal::logger('job_hunter')->info('✅ RECOVERY SUCCESS: Sanitized trailing comma JSON');
        return $decoded;
      }
    }

    \Drupal::logger('job_hunter')->warning('🟡 extractJsonFromResponse could not recover a valid JSON candidate');
    return NULL;
  }

  /**
   * Try to decode a JSON candidate while stripping recoverable defects.
   */
  private function decodeJsonCandidate(string $candidate): ?string {
    $candidate = trim($candidate);
    if ($candidate === '') {
      return NULL;
    }

    $decoded = json_decode($candidate, TRUE);
    if (json_last_error() === JSON_ERROR_NONE && $decoded !== NULL) {
      return $candidate;
    }

    $sanitized = preg_replace('/,\s*([}\]])/', '$1', $candidate);
    if ($sanitized !== NULL && $sanitized !== $candidate) {
      $decoded = json_decode($sanitized, TRUE);
      if (json_last_error() === JSON_ERROR_NONE && $decoded !== NULL) {
        return $sanitized;
      }
    }

    return NULL;
  }

  /**
   * Call AI API for a specific batched section.
   */
  private function callBatchedSection(string $prompt, int $uid, int $job_id, string $section_name, array $options = []) {
    try {
      $options += [
        'provider' => 'deepseek',
        'model_id' => self::TAILORING_MODEL,
        'thinking' => 'disabled',
        'max_tokens' => self::SECTION_MAX_TOKENS,
      ];

      // Use centralized AIApiService (with automatic caching)
      $result = $this->aiApiService->invokeModelDirect(
        $prompt,
        'job_hunter',
        'resume_tailoring',
        [
          'uid' => $uid,
          'job_id' => $job_id,
          'queue' => 'job_hunter_resume_tailoring',
          'item_key' => "resume_tailoring_{$uid}_{$job_id}_{$section_name}",
        ],
        $options
      );

      if (!$result['success']) {
        $this->logError('AIApiService call failed for section @section: @error', [
          '@section' => $section_name,
          '@error' => $result['error'] ?? 'Unknown error',
        ]);
        return NULL;
      }

      $ai_response = $result['response'];
      $stop_reason = $result['stop_reason'];
        
        // 🔍 VERBOSE: Log raw response statistics
        $response_length = strlen($ai_response);
        $first_char = substr($ai_response, 0, 1);
        $last_char = substr($ai_response, -1);
        $has_opening_brace = strpos($ai_response, '{') !== FALSE;
        $has_closing_brace = strpos($ai_response, '}') !== FALSE;
        $opening_brace_pos = strpos($ai_response, '{');
        $closing_brace_pos = strrpos($ai_response, '}');
        
        $this->logInfo('🔍 RAW AI RESPONSE: length=@len, stop_reason=@reason, first_char="@first", last_char="@last", has_braces={@open:YES/NO @close:YES/NO}, brace_positions={open:@opos close:@cpos}', [
          '@len' => $response_length,
          '@reason' => $stop_reason,
          '@first' => $first_char,
          '@last' => $last_char,
          '@open' => $has_opening_brace ? 'YES' : 'NO',
          '@close' => $has_closing_brace ? 'YES' : 'NO',
          '@opos' => $opening_brace_pos !== FALSE ? $opening_brace_pos : 'NONE',
          '@cpos' => $closing_brace_pos !== FALSE ? $closing_brace_pos : 'NONE',
        ]);
        
        // Truncated output: Claude reports 'max_tokens', DeepSeek 'length'.
        if (in_array($stop_reason, ['max_tokens', 'length'], TRUE)) {
          $this->logError('❌ Section @section hit max_tokens limit! Response truncated at @len chars. This should not happen with batched generation.', [
            '@section' => $section_name,
            '@len' => strlen($ai_response),
          ]);
          // Return null immediately - don't try to parse truncated JSON
          return NULL;
        }
        
        // Debug: Log first 500 chars and last 200 chars of response
        $this->logInfo('🔍 AI RESPONSE PREVIEW (first 500 chars): @preview', [
          '@preview' => substr($ai_response, 0, 500),
        ]);
        $this->logInfo('🔍 AI RESPONSE TAIL (last 200 chars): @tail', [
          '@tail' => substr($ai_response, -200),
        ]);
        
        $this->logInfo('🔍 CALLING extractJsonFromResponse with @len char response', ['@len' => strlen($ai_response)]);
        $json_str = $this->extractJsonFromResponse($ai_response);

        if ($json_str) {
          $this->logInfo('🔍 extractJsonFromResponse RETURNED: length=@len, first_100="@preview", last_100="@tail"', [
            '@len' => strlen($json_str),
            '@preview' => substr($json_str, 0, 100),
            '@tail' => substr($json_str, -100),
          ]);
          
          $this->logInfo('🔍 ATTEMPTING json_decode on extracted string...');
          $tailored_resume = json_decode($json_str, TRUE);
          $json_error = json_last_error();
          $json_error_msg = json_last_error_msg();
          
          $this->logInfo('🔍 json_decode RESULT: error_code=@code, error_msg="@msg", is_array=@is_array', [
            '@code' => $json_error,
            '@msg' => $json_error_msg,
            '@is_array' => is_array($tailored_resume) ? 'YES' : 'NO',
          ]);

          if ($json_error === JSON_ERROR_NONE && $tailored_resume) {
            $this->logInfo('✅ Successfully generated section: @section', ['@section' => $section_name]);
            return $tailored_resume;
          }
          
          // Log JSON parse error with context
          $this->logError('❌ JSON parse error: @error (code: @code). Extracted JSON length: @len', [
            '@error' => $json_error_msg,
            '@code' => $json_error,
            '@len' => strlen($json_str),
          ]);
        }
        else {
          $this->logError('❌ extractJsonFromResponse returned NULL. Original response length: @len', [
            '@len' => strlen($ai_response),
          ]);
        }

        $this->logError('Queue: Failed to parse section @section JSON from AI response', ['@section' => $section_name]);
        return NULL;

    }
    catch (\Exception $e) {
      $this->logError('Queue: GenAI API call failed for section @section: @error', [
        '@section' => $section_name,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }



  /**
   * Run the final whole-resume pass and verify the rendered page count.
   *
   * One corrective pass is allowed when the edited resume still renders over
   * the page limit; after that the run fails with the measured page count.
   *
   * @return array|null
   *   The finalized resume, or NULL when the model returned no usable JSON.
   *
   * @throws \LengthException
   *   When the resume still exceeds the page limit after the corrective pass.
   */
  private function finalizeResume(array $payload, array $draft, array $source_resume, int $uid, int $job_id): ?array {
    $feedback = NULL;

    for ($attempt = 1; $attempt <= 2; $attempt++) {
      $edited = $this->callBatchedSection(
        $this->buildFinalPassPrompt($payload, $draft, $feedback),
        $uid,
        $job_id,
        "final_pass_{$attempt}",
        [
          'max_tokens' => self::FINAL_PASS_MAX_TOKENS,
          'skip_cache' => TRUE,
        ]
      );
      if (!$edited) {
        $this->logError('❌ Final pass @attempt returned no usable JSON for job @job_id', [
          '@attempt' => $attempt,
          '@job_id' => $job_id,
        ]);
        return NULL;
      }

      $this->assertFinalPassKeptFacts($draft, $edited, $job_id);

      $edited['tailoring_metadata'] = $draft['tailoring_metadata'];
      $edited = $this->normalizeTailoredResumeSchema($edited, $source_resume);
      $edited = $this->normalizeTechnicalExpertise($edited, $source_resume);
      $edited = $this->resumeLengthPolicy->apply($edited);

      $pages = $this->resumePdfService->countPages($edited);
      $this->logInfo('📄 Final pass @attempt for job @job_id renders to @pages pages', [
        '@attempt' => $attempt,
        '@job_id' => $job_id,
        '@pages' => $pages,
      ]);
      if ($pages <= ResumeLengthPolicy::MAX_PAGES) {
        return $edited;
      }

      $draft = $edited;
      $feedback = sprintf(
        'The previous edit rendered to %d pages. It must be at most %d. Remove the least job-relevant content until it fits.',
        $pages,
        ResumeLengthPolicy::MAX_PAGES
      );
    }

    throw new \LengthException(sprintf(
      'Tailored resume for job %d still rendered over %d pages after the corrective final pass.',
      $job_id,
      ResumeLengthPolicy::MAX_PAGES
    ));
  }

  /**
   * Fail when the final pass invents employers or changes role dates.
   *
   * The final pass may drop roles to fit the page budget; it may not add or
   * alter them.
   */
  private function assertFinalPassKeptFacts(array $draft, array $edited, int $job_id): void {
    if (!isset($edited['professional_experience']) || !is_array($edited['professional_experience']) || $edited['professional_experience'] === []) {
      throw new \RuntimeException("Final pass for job {$job_id} returned no professional_experience; the draft had " . count($draft['professional_experience'] ?? []) . ' roles.');
    }

    $key = static fn(array $role): string => strtolower(trim((string) ($role['company'] ?? ''))) . '|' . trim((string) ($role['start_date'] ?? '')) . '|' . trim((string) ($role['end_date'] ?? ''));
    $draft_roles = array_map($key, array_filter($draft['professional_experience'] ?? [], 'is_array'));

    foreach ($edited['professional_experience'] as $role) {
      if (!is_array($role) || !in_array($key($role), $draft_roles, TRUE)) {
        throw new \RuntimeException(sprintf(
          'Final pass for job %d returned a role not present in the draft (%s); it may only drop roles, not add or change them.',
          $job_id,
          is_array($role) ? $key($role) : gettype($role)
        ));
      }
    }
  }

  /**
   * Build the final whole-resume editing prompt.
   */
  private function buildFinalPassPrompt(array $payload, array $draft, ?string $feedback): string {
    $job = $payload['job_requisition'] ?? [];
    $job_title = $job['extracted_json']['position']['title'] ?? $job['extracted_json']['job_title'] ?? 'the position';
    $company_name = $job['extracted_json']['company']['name'] ?? $job['extracted_json']['company_name'] ?? 'the company';
    $job_description = $job['raw_posting_text'] ?? '';
    $writing_rules = self::WRITING_RULES;
    $word_budget = self::FINAL_PASS_WORD_BUDGET;
    $max_pages = ResumeLengthPolicy::MAX_PAGES;
    $unedited = $draft;
    unset($unedited['tailoring_metadata']);
    $draft_json = json_encode($unedited, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $feedback_block = $feedback !== NULL ? "\nCorrection required: {$feedback}\n" : '';

    return <<<PROMPT
You are the final editor of a tailored resume. Return only valid JSON with no markdown fences and no prose.

Target role: {$job_title}
Target company: {$company_name}

Job description:
{$job_description}

{$writing_rules}
{$feedback_block}
Edit the complete draft below as one document:
- The whole resume must fit within {$max_pages} pages: at most {$word_budget} words of content in total.
- Remove achievements and claims repeated across roles or sections; keep the strongest instance.
- Make the executive profile and strategic differentiators consistent with the experience that follows.
- Use one consistent voice and tense: past tense for past roles, present tense for current roles, no first person.
- To meet the budget, drop the least job-relevant items first, starting with the oldest roles; do not shorten sentences into fragments.
- You may remove roles, but never add roles or change any company, title, start_date, or end_date.
- Do not invent facts, metrics, or qualifications.
- Return the same JSON structure and keys as the draft.

Draft resume JSON:
{$draft_json}
PROMPT;
  }

  /**
   * Candidate profile limited to the experience the resume will include.
   *
   * Keeps older roles out of the model context so generated summaries focus
   * on the most recent ten years.
   */
  private function focusedProfile(array $resume): array {
    $resume['professional_experience'] = array_column(
      $this->resumeLengthPolicy->selectExperience($resume['professional_experience'] ?? []),
      'entry'
    );
    return $resume;
  }

  /**
   * Build the metadata-focused prompt for the first resume tailoring batch.
   */
  private function buildMetadataPrompt(array $payload): string {
    $job = $payload['job_requisition'] ?? [];
    $resume = $payload['user_resume']['consolidated_profile_json'] ?? [];
    $job_title = $job['extracted_json']['position']['title'] ?? $job['extracted_json']['job_title'] ?? 'the position';
    $company_name = $job['extracted_json']['company']['name'] ?? $job['extracted_json']['company_name'] ?? 'the company';
    $job_skills = json_encode($job['skills_required_json'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $job_keywords = json_encode($job['keywords_json'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $job_description = $job['raw_posting_text'] ?? '';
    $resume_json = json_encode($this->focusedProfile($resume), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $writing_rules = self::WRITING_RULES;

    return <<<PROMPT
You are an expert resume-tailoring assistant. Return only valid JSON with no markdown fences and no prose.

Target role: {$job_title}
Target company: {$company_name}
{$writing_rules}

Job requirements:
{$job_skills}

Priority keywords:
{$job_keywords}

Job description:
{$job_description}

Candidate profile JSON:
{$resume_json}

Budgets: executive profile summary is 3 sentences and at most 70 words; at most 4 strategic differentiators, each description one sentence of at most 25 words; leadership philosophy is one sentence of at most 30 words; at most 2 demonstration projects, each description one sentence of at most 30 words. Preserve candidate facts and do not invent qualifications.

Return a JSON object that matches the canonical resume schema exactly. Use these field names and structures:
{
  "schema_version": "1.0",
  "tailoring_metadata": {
    "job_id": "job id",
    "job_title": "matching title",
    "company": "company name",
    "tailored_at": "ISO timestamp",
    "guidance": ["brief guidance strings"]
  },
  "contact_info": {
    "full_name": "candidate name",
    "credentials": ["credential 1", "credential 2"],
    "headline": "targeted headline",
    "location": {"city": "city", "state": "state", "country": "country"},
    "phone": "phone number",
    "email": "email",
    "websites": [{"url": "https://example.com"}],
    "linkedin": {"url": "https://linkedin.com/in/name", "followers": 0}
  },
  "executive_profile": {"summary": "3 sentences, at most 70 words, aligned to the role."},
  "strategic_differentiators": [{"title": "Differentiator", "description": "short description aligned to requirements"}],
  "leadership_philosophy": "One sentence, at most 30 words.",
  "demonstration_projects": [{"name": "Project name", "description": "relevant project summary"}]
}
PROMPT;
  }

  /**
   * Build the experience-specific prompt for a single company entry.
   */
  private function buildExperiencePrompt(array $payload, array $company, int $index, bool $is_recent): string {
    $job = $payload['job_requisition'] ?? [];
    $resume = $payload['user_resume']['consolidated_profile_json'] ?? [];
    $company_name = $company['company'] ?? 'Unknown Company';
    $position_title = $company['title'] ?? 'Senior leader';
    $job_title = $job['extracted_json']['position']['title'] ?? $job['extracted_json']['job_title'] ?? 'the position';
    $company_json = json_encode($company, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $resume_json = json_encode($this->focusedProfile($resume), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $writing_rules = self::WRITING_RULES;
    $job_keywords = json_encode($job['keywords_json'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $history_guidance = $is_recent
      ? 'This role falls within the most recent 10 years. Give it priority: company_context is one sentence of at most 30 words; no more than 2 responsibility categories and 4 achievements total.'
      : 'This is earlier career history. Keep it brief: company_context is one sentence of at most 20 words; 1 category and 1 achievement.';

    return <<<PROMPT
You are an expert resume-tailoring assistant. Return only valid JSON with no markdown fences and no prose.

Target role: {$job_title}
Current company being tailored: {$company_name}
{$writing_rules}
History guidance: {$history_guidance}

Company experience JSON to tailor:
{$company_json}

Relevant job keywords:
{$job_keywords}

Candidate resume JSON:
{$resume_json}

Each achievement is one sentence of at most 25 words. Preserve dates, employers, titles, and facts from the candidate data. Do not invent qualifications.

Return a JSON object describing only this experience entry in the canonical resume schema. Use this exact structure:
{
  "company": "Company name",
  "title": "Role title",
  "location": "Location or null",
  "start_date": "YYYY-MM or YYYY-MM-DD",
  "end_date": "YYYY-MM or Present",
  "company_context": "One sentence of context",
  "responsibility_categories": [
    {
      "category": "Category name",
      "achievements": [{"text": "achievement text with metrics when available"}]
    }
  ]
}
PROMPT;
  }

  /**
   * Build the prompt for the final resume sections (education, technical expertise, etc.).
   */
  private function buildOtherSectionsPrompt(array $payload): string {
    $job = $payload['job_requisition'] ?? [];
    $resume = $payload['user_resume']['consolidated_profile_json'] ?? [];
    $job_title = $job['extracted_json']['position']['title'] ?? $job['extracted_json']['job_title'] ?? 'the position';
    $company_name = $job['extracted_json']['company']['name'] ?? $job['extracted_json']['company_name'] ?? 'the company';
    $resume_json = json_encode($this->focusedProfile($resume), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $writing_rules = self::WRITING_RULES;
    $job_skills = json_encode($job['skills_required_json'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $job_keywords = json_encode($job['keywords_json'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return <<<PROMPT
You are an expert resume-tailoring assistant. Return only valid JSON with no markdown fences and no prose.

Target role: {$job_title}
Target company: {$company_name}
{$writing_rules}

Required skills:
{$job_skills}

Priority keywords:
{$job_keywords}

Candidate profile JSON:
{$resume_json}

Prioritize only job-relevant content. Return no more than 3 education entries, 6 technical categories with 10 skills each, 2 consulting engagements, 6 certifications, 3 publications, 3 awards, and 4 languages. Each description is one sentence of at most 30 words. Preserve candidate facts and do not invent qualifications.

Return a JSON object with the final resume sections using the canonical schema. Include keys like:
{
  "education": [{"institution": "...", "degree": "...", "field": "...", "end_date": "..."}],
  "technical_expertise": {"categories": [{"name": "Category", "skills": ["skill1", "skill2"]}]},
  "consulting_practice": {"engagements": [{"name": "...", "description": "..."}]},
  "certifications": [{"name": "...", "issuer": "..."}],
  "publications": [{"title": "...", "source": "..."}],
  "awards_and_honors": [{"title": "...", "organization": "..."}],
  "languages": [{"language": "...", "proficiency": "..."}],
  "leadership_philosophy": "Tailored statement",
  "executive_profile": {"summary": "Tailored summary"}
}
PROMPT;
  }


}