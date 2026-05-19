<?php

namespace Drupal\job_hunter\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\job_hunter\Traits\JobHunterLoggerTrait;
use Drupal\job_hunter\Traits\QueueWorkerBaseTrait;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resume Tailoring GenAI queue worker.
 *
 * Processes resume tailoring via AWS Bedrock in the background.
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    $instance->aiApiService = $container->get('ai_conversation.ai_api_service');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Validate required fields — permanent failure if malformed.
    foreach (['uid', 'job_id', 'profile_json', 'job_data'] as $field) {
      if (empty($data[$field])) {
        $this->logError('Queue: Resume tailoring discarded — missing required field "@field" in queue item data (job @job_id)', [
          '@field' => $field,
          '@job_id' => $data['job_id'] ?? 'unknown',
        ]);
        return;
      }
    }

    $uid = $data['uid'];
    $job_id = $data['job_id'];
    $profile_json = $data['profile_json'];
    $job_data = $data['job_data'];
    $retry_count = (int) ($data['retry_count'] ?? 0);

    // Respect exponential backoff — if this is a retried item that should not
    // be processed yet, re-delay it and release back to the queue.
    $process_after = (int) ($data['process_after'] ?? 0);
    if ($process_after > time()) {
      throw new DelayedRequeueException($process_after - time(), "Backoff delay not yet elapsed for job {$job_id}");
    }

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
      // Update status to processing
      $this->updateDatabaseStatus($connection, 'jobhunter_tailored_resumes', $uid, $job_id, 'processing');

      // Parse job data (extracted already parsed above for logging)
      $skills = !empty($job_data['skills_required_json']) ? json_decode($job_data['skills_required_json'], TRUE) : [];
      $keywords = !empty($job_data['keywords_json']) ? json_decode($job_data['keywords_json'], TRUE) : [];

      // Build the GenAI request payload
      $genai_payload = [
        'action' => 'generate_tailored_resume',
        'job_requisition' => [
          'id' => (int) $job_id,
          'job_title' => (string) ($job_data['job_title'] ?? ''),
          'company_name' => (string) ($job_data['company_name'] ?? ''),
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

      // Save the tailored resume
      $this->updateDatabaseStatus(
        $connection,
        'jobhunter_tailored_resumes',
        $uid,
        $job_id,
        'completed',
        ['tailored_resume_json' => json_encode($tailored_result['tailored_resume_json'])]
      );

      $this->logInfo('✅ Queue: Resume tailoring complete for @username → "@title" at @company (job @job_id)', [
        '@username' => $context['username'],
        '@title' => $context['job_title'],
        '@company' => $context['company'],
        '@job_id' => $job_id,
      ]);

    }
    catch (\Exception $e) {
      $this->handleQueueExceptionWithRetry($e, $connection, $data, $retry_count, $context, $uid, $job_id);
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
   */
  private function handleQueueExceptionWithRetry(\Exception $e, $connection, array $data, int $retry_count, array $context, int $uid, int $job_id): void {
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

      $retry_data = array_merge($data, [
        'retry_count' => $retry_count + 1,
        'process_after' => time() + $backoff_seconds,
      ]);

      \Drupal::queue('job_hunter_resume_tailoring')->createItem($retry_data);

      $this->logError('⏳ Queue: Scheduled retry @retry/@max for job @job_id in @backoff seconds', [
        '@retry' => $retry_count + 1,
        '@max' => $max_retries,
        '@job_id' => $job_id,
        '@backoff' => $backoff_seconds,
      ]);

      // Reset DB status to pending so the item does not appear stuck as 'processing'.
      $this->updateDatabaseStatus($connection, 'jobhunter_tailored_resumes', $uid, $job_id, 'pending');
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
      $companies = $resume['professional_experience'] ?? [];
      $company_count = count($companies);
      
      $this->logInfo('📦 Batches 2-{$count}: Generating {$count} professional experience entries', [
        '{$count}' => $company_count,
      ]);
      
      foreach ($companies as $index => $company) {
        $batch_num = $index + 2;
        $company_name = $company['company'] ?? 'Unknown';
        $this->logInfo('📦 Batch @num/@total: Generating experience for @company', [
          '@num' => $batch_num,
          '@total' => $company_count + 2,
          '@company' => $company_name,
        ]);
        
        $exp_result = $this->callBatchedSection(
          $this->buildExperiencePrompt($payload, $company, $index),
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

      // Normalize technical_expertise to renderer-safe structure.
      // Some model responses still return categories as bare strings. Convert
      // to objects ({name, skills[]}) and backfill skills from source profile.
      $tailored_resume = $this->normalizeTechnicalExpertise($tailored_resume, $resume);
      
      $this->logInfo('✅ Successfully combined @count batches into final tailored resume', [
        '@count' => $final_batch_num,
      ]);
      
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
   * Call AI API for a specific batched section.
   */
  private function callBatchedSection(string $prompt, int $uid, int $job_id, string $section_name) {
    try {
      // Get max_tokens from centralized ai_conversation config
      // Use lower limit since we're generating smaller sections
      $config = $this->configFactory->get('ai_conversation.settings');
      $max_tokens = 4000; // Stay under Claude's 4,096 hard limit

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
        [
          'max_tokens' => $max_tokens,
        ]
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
        
        // Check if response was truncated due to max_tokens limit
        if ($stop_reason === 'max_tokens') {
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
   * Build the prompt for generating a tailored resume JSON.
   */
  private function buildTailoredResumePrompt(array $payload) {
    $job = $payload['job_requisition'] ?? [];
    $resume = $payload['user_resume']['consolidated_profile_json'] ?? [];

    $job_title = $job['extracted_json']['position']['title'] ?? $job['extracted_json']['job_title'] ?? $job['job_title'] ?? 'the position';
    $company_name = $job['extracted_json']['company']['name'] ?? $job['extracted_json']['company_name'] ?? $job['company_name'] ?? 'the company';
    $job_skills = json_encode($job['skills_required_json'] ?? [], JSON_PRETTY_PRINT);
    $job_keywords = json_encode($job['keywords_json'] ?? [], JSON_PRETTY_PRINT);
    $job_description = $job['raw_posting_text'] ?? '';
    // Use compact JSON encoding to reduce prompt size
    $resume_json = json_encode($resume, JSON_UNESCAPED_SLASHES);
    $job_id = $job['id'] ?? 0;
    
    // Log prompt size for debugging
    $prompt_size = strlen($resume_json) + strlen($job_description) + 2000;
    $this->logInfo('Queue: Building prompt with resume JSON size: @resume_size chars, job desc: @job_size chars, estimated total: @total chars', [
      '@resume_size' => strlen($resume_json),
      '@job_size' => strlen($job_description),
      '@total' => $prompt_size,
    ]);

    return <<<PROMPT
You are an expert resume tailoring AI. Your task is to create a tailored version of the candidate's resume optimized for a specific job posting.

## Job Information
**Position:** {$job_title}
**Company:** {$company_name}

**Required Skills:**
{$job_skills}

**Key Keywords:**
{$job_keywords}

**Job Description:**
{$job_description}

## Candidate's Current Resume (JSON)
{$resume_json}

## Your Task

Generate a TAILORED version of the candidate's resume as a JSON object. The output must:

1. **Match the RESUME_JSON_SCHEMA.md structure** exactly with these sections:
   - `schema_version`: "1.0"
   - `tailoring_metadata`: Object with job_id, job_title, company, tailored_at timestamp, and guidance array
   - `contact_info`: Keep unchanged from original
   - `executive_profile`: Rewrite summary to emphasize relevant experience for this role
   - `strategic_differentiators`: Prioritize/reword to match job requirements
   - `professional_experience`: Reorder achievements, emphasize relevant technologies/metrics
   - `consulting_practice`: Include if relevant to role
   - `early_career`: Include if relevant
   - `education`: Keep unchanged
   - `technical_expertise`: Reorder categories to prioritize job-relevant skills. Each category must be an object with "name" (string) and "skills" (array of skill name strings). Preserve all skills from the source profile.
   - `leadership_philosophy`: Tailor if relevant
   - `demonstration_projects`: Include if relevant
   - `publications`: Include if candidate has publications and they're relevant to the role
   - `patents`: Include if candidate has patents and they're relevant to the role
   - `certifications`: Include if candidate has certifications and they're relevant to the role
   - `awards_and_honors`: Include if relevant to demonstrate excellence in the field
   - `languages`: Include if job requires or values language skills

2. **Tailoring Guidelines:**
   - Incorporate keywords from the job posting naturally
   - Prioritize achievements that match required skills
   - Quantified metrics should be preserved and highlighted when relevant
   - Technologies mentioned in job posting should be emphasized
   - Maintain professional tone and factual accuracy
   - DO NOT fabricate information - only reorganize and emphasize existing content
   - For publications, patents, certifications, awards, and languages: only include if they exist in source resume AND are relevant to the position
   - **Be concise**: Keep descriptions focused and impactful. Avoid unnecessary verbosity while maintaining professional quality.
   - **Optimize length**: Aim for a balanced, professional resume that highlights the most relevant content for this role.

3. **Add tailoring_metadata section:**
   ```json
   "tailoring_metadata": {
     "job_id": {$job_id},
     "job_title": "{$job_title}",
     "company": "{$company_name}",
     "tailored_at": "ISO-8601 timestamp",
     "match_score": 0-100,
     "guidance": [
       "Key suggestion 1",
       "Key suggestion 2"
     ],
     "emphasized_skills": ["skill1", "skill2"],
     "emphasized_achievements": ["achievement summary 1"]
   }
   ```

## Output Format

**CRITICAL**: Return ONLY valid JSON conforming to RFC 8259:
- Start immediately with `{` and end with `}`
- NO markdown code blocks (no ```json or ```)
- NO explanatory text before or after the JSON
- NO double-escaping (don't wrap JSON as a string)
- **USE PROPER JSON ESCAPING**: `\n` for newlines, `\t` for tabs, `\"` for quotes within strings
- Ensure all braces, brackets, and quotes are properly balanced
- Multi-line string values MUST use `\n` escape sequences, NOT literal newlines
- All special characters in strings MUST be properly escaped per JSON spec

The output must parse successfully with `json_decode()` without any preprocessing.

PROMPT;
  }

  /**
   * Build prompt for metadata + contact + profile + differentiators sections.
   * 
   * This is Batch 1 of the batched resume generation.
   */
  private function buildMetadataPrompt(array $payload) {
    $job = $payload['job_requisition'] ?? [];
    $resume = $payload['user_resume']['consolidated_profile_json'] ?? [];

    $job_title = $job['extracted_json']['position']['title'] ?? $job['extracted_json']['job_title'] ?? $job['job_title'] ?? 'the position';
    $company_name = $job['extracted_json']['company']['name'] ?? $job['extracted_json']['company_name'] ?? $job['company_name'] ?? 'the company';
    $job_skills = json_encode($job['skills_required_json'] ?? [], JSON_UNESCAPED_SLASHES);
    $job_keywords = json_encode($job['keywords_json'] ?? [], JSON_UNESCAPED_SLASHES);
    
    // Extract only needed sections (NO raw_posting_text)
    $extracted_position = json_encode($job['extracted_json']['position'] ?? [], JSON_UNESCAPED_SLASHES);
    $extracted_requirements = json_encode($job['extracted_json']['requirements'] ?? [], JSON_UNESCAPED_SLASHES);
    
    $contact_json = json_encode($resume['contact_info'] ?? [], JSON_UNESCAPED_SLASHES);
    $profile_text = $resume['executive_profile']['summary'] ?? '';
    $differentiators = json_encode($resume['strategic_differentiators'] ?? [], JSON_UNESCAPED_SLASHES);
    $job_id = $job['id'] ?? 0;
    
    return <<<PROMPT
You are an expert resume tailoring AI. Generate the METADATA + CONTACT + PROFILE + DIFFERENTIATORS sections of a tailored resume.

## Job Context
**Position:** {$job_title}
**Company:** {$company_name}
**Position Details:** {$extracted_position}
**Requirements:** {$extracted_requirements}
**Skills:** {$job_skills}
**Keywords:** {$job_keywords}

## Current Resume Data
**Contact Info:** {$contact_json}
**Executive Profile Summary:** {$profile_text}
**Strategic Differentiators:** {$differentiators}

## Your Task
Generate ONLY these sections as valid JSON:
```
{
  "schema_version": "1.0",
  "tailoring_metadata": {
    "job_id": {$job_id},
    "job_title": "{$job_title}",
    "company": "{$company_name}",
    "tailored_at": "ISO-8601 timestamp",
    "match_score": 0-100,
    "guidance": ["Key suggestion 1", "Key suggestion 2"],
    "emphasized_skills": ["skill1", "skill2"],
    "emphasized_achievements": ["achievement 1"]
  },
  "contact_info": { ...keep unchanged from source... },
  "executive_profile": {
    "summary": "TAILORED version emphasizing relevance to this role"
  },
  "strategic_differentiators": [
    "TAILORED differentiator 1 (prioritize job-relevant items)",
    "TAILORED differentiator 2"
  ]
}
```

**CRITICAL**: 
- Return ONLY valid JSON (start with `{`, end with `}`)
- NO markdown code blocks
- Rewrite executive_profile summary to emphasize fit for this role
- Reorder/reword strategic_differentiators to match job requirements
- Keep contact_info unchanged
- Use proper JSON escaping for special characters
PROMPT;
  }

  /**
   * Build prompt for a single professional experience entry (one company).
   * 
   * This is Batch 2-N of the batched resume generation.
   */
  private function buildExperiencePrompt(array $payload,Array $company, int $index) {
    $job = $payload['job_requisition'] ?? [];

    $job_title = $job['extracted_json']['position']['title'] ?? $job['extracted_json']['job_title'] ?? $job['job_title'] ?? 'the position';
    $company_name = $job['extracted_json']['company']['name'] ?? $job['extracted_json']['company_name'] ?? $job['company_name'] ?? 'the company';
    $job_skills = json_encode($job['skills_required_json'] ?? [], JSON_UNESCAPED_SLASHES);
    $job_keywords = json_encode($job['keywords_json'] ?? [], JSON_UNESCAPED_SLASHES);
    
    // Extract only needed sections (NO raw_posting_text)
    $extracted_position = json_encode($job['extracted_json']['position'] ?? [], JSON_UNESCAPED_SLASHES);
    $extracted_requirements = json_encode($job['extracted_json']['requirements'] ?? [], JSON_UNESCAPED_SLASHES);
    
    $company_json = json_encode($company, JSON_UNESCAPED_SLASHES);
    $company_employer_name = $company['company'] ?? 'Unknown Company';
    
    return <<<PROMPT
You are an expert resume tailoring AI. Generate a SINGLE professional experience entry tailored for a specific job.

## Job Context
**Position:** {$job_title}
**Company:** {$company_name}
**Position Details:** {$extracted_position}
**Requirements:** {$extracted_requirements}
**Skills:** {$job_skills}
**Keywords:** {$job_keywords}

## Experience Entry to Tailor
**Company:** {$company_employer_name}
**Original Entry:** {$company_json}

## Your Task
Generate a TAILORED version of this ONE experience entry as valid JSON:
```
{
  "company": "Company Name",
  "positions": [
    {
      "title": "Title",
      "start_date": "YYYY-MM",
      "end_date": "YYYY-MM or Present",
      "duration": "X years Y months",
      "location": "City, State",
      "achievements": [
        "TAILORED achievement emphasizing job-relevant skills/metrics",
        "Another tailored achievement"
      ],
      "technologies": ["tech1", "tech2"],
      "business_impact": {
        "revenue": "...",
        "efficiency": "...",
        "team_size": "..."
      }
    }
  ]
}
```

**CRITICAL**:
- Return ONLY valid JSON (start with `{`, end with `}`)
- NO markdown code blocks
- Emphasize achievements matching job requirements
- Incorporate job keywords naturally
- Preserve quantified metrics
- Highlight technologies mentioned in job posting
- DO NOT fabricate information - only reorganize and emphasize existing content
PROMPT;
  }

  /**
   * Build prompt for education + technical + other sections.
   * 
   * This is the final batch of the batched resume generation.
   */
  private function buildOtherSectionsPrompt(array $payload) {
    $job = $payload['job_requisition'] ?? [];
    $resume = $payload['user_resume']['consolidated_profile_json'] ?? [];

    $job_title = $job['extracted_json']['position']['title'] ?? $job['extracted_json']['job_title'] ?? $job['job_title'] ?? 'the position';
    $company_name = $job['extracted_json']['company']['name'] ?? $job['extracted_json']['company_name'] ?? $job['company_name'] ?? 'the company';
    $job_skills = json_encode($job['skills_required_json'] ?? [], JSON_UNESCAPED_SLASHES);
    $job_keywords = json_encode($job['keywords_json'] ?? [], JSON_UNESCAPED_SLASHES);
    
    // Extract only needed sections (NO raw_posting_text)
    $extracted_position = json_encode($job['extracted_json']['position'] ?? [], JSON_UNESCAPED_SLASHES);
    $extracted_requirements = json_encode($job['extracted_json']['requirements'] ?? [], JSON_UNESCAPED_SLASHES);
    
    $education = json_encode($resume['education'] ?? [], JSON_UNESCAPED_SLASHES);
    $technical = json_encode($resume['technical_expertise'] ?? [], JSON_UNESCAPED_SLASHES);
    $consulting = json_encode($resume['consulting_practice'] ?? [], JSON_UNESCAPED_SLASHES);
    $early_career = json_encode($resume['early_career'] ?? [], JSON_UNESCAPED_SLASHES);
    $leadership = json_encode($resume['leadership_philosophy'] ?? [], JSON_UNESCAPED_SLASHES);
    $demos = json_encode($resume['demonstration_projects'] ?? [], JSON_UNESCAPED_SLASHES);
    
    return <<<PROMPT
You are an expert resume tailoring AI. Generate EDUCATION + TECHNICAL SKILLS + OTHER SECTIONS tailored for a specific job.

## Job Context
**Position:** {$job_title}
**Company:** {$company_name}
**Position Details:** {$extracted_position}
**Requirements:** {$extracted_requirements}
**Skills:** {$job_skills}
**Keywords:** {$job_keywords}

## Current Resume Data
**Education:** {$education}
**Technical Expertise:** {$technical}
**Consulting Practice:** {$consulting}
**Early Career:** {$early_career}
**Leadership Philosophy:** {$leadership}
**Demonstration Projects:** {$demos}

## Your Task
Generate ONLY these sections as valid JSON:
```
{
  "education": [...keep unchanged or omit if not relevant...],
  "technical_expertise": {
    "categories": [
      {
        "name": "Category Name",
        "skills": ["Skill 1", "Skill 2", "Skill 3"]
      }
    ]
  },
  "consulting_practice": {...include if relevant...},
  "early_career": {...include if relevant...},
  "leadership_philosophy": {...tailor if relevant...},
  "demonstration_projects": [...include if relevant...]
}
```

**CRITICAL**:
- Return ONLY valid JSON (start with `{`, end with `}`)
- NO markdown code blocks
- technical_expertise.categories MUST be an array of objects, each with "name" (string) and "skills" (array of strings). Do NOT return bare strings.
- Reorder categories to prioritize job-relevant skills. Consolidate the source data into logical groups.
- Pull actual skill names from the source Technical Expertise data — do NOT drop them. Every skill from the source should appear under an appropriate category.
- Include optional sections (consulting, early_career, leadership, demos) ONLY if relevant to this role
- Keep education unchanged unless specific optimization needed
- Use proper JSON escaping
PROMPT;
  }

  /**
   * Extract JSON from AI response that may contain markdown or text.
   */
  private function extractJsonFromResponse($response) {
    $response_text = trim($response);
    $original_length = strlen($response_text);
    
    \Drupal::logger('job_hunter')->info('🔍 extractJsonFromResponse START: input_length=@len', ['@len' => $original_length]);
    
    if (empty($response_text)) {
      \Drupal::logger('job_hunter')->error('❌ extractJsonFromResponse: Empty response after trim');
      return NULL;
    }

    // AGGRESSIVE normalization of escaped sequences
    // The AI sometimes returns JSON with literal escape sequences that need to be processed
    $has_literal_newlines = strpos($response_text, "\\n") !== FALSE;
    $has_literal_quotes = strpos($response_text, '\\"') !== FALSE;
    $has_literal_tabs = strpos($response_text, "\\t") !== FALSE;
    $has_actual_newlines = strpos($response_text, "\n") !== FALSE;
    
    \Drupal::logger('job_hunter')->info('🔍 ESCAPE DETECTION: literal_newlines=@ln, literal_quotes=@lq, literal_tabs=@lt, actual_newlines=@an', [
      '@ln' => $has_literal_newlines ? 'YES' : 'NO',
      '@lq' => $has_literal_quotes ? 'YES' : 'NO',
      '@lt' => $has_literal_tabs ? 'YES' : 'NO',
      '@an' => $has_actual_newlines ? 'YES' : 'NO',
    ]);
    
    // If we have literal escapes without actual whitespace, the response is string-escaped
    if ($has_literal_newlines || $has_literal_quotes || $has_literal_tabs) {
      $before_length = strlen($response_text);
      $response_text = stripcslashes($response_text);
      $response_text = trim($response_text);
      $after_length = strlen($response_text);
      
      \Drupal::logger('job_hunter')->warning('🟡 APPLIED stripcslashes normalization: before_len=@before, after_len=@after, diff=@diff (literal escape sequences: newlines=@n, quotes=@q, tabs=@t)', [
        '@before' => $before_length,
        '@after' => $after_length,
        '@diff' => $before_length - $after_length,
        '@n' => $has_literal_newlines ? 'YES' : 'NO',
        '@q' => $has_literal_quotes ? 'YES' : 'NO',
        '@t' => $has_literal_tabs ? 'YES' : 'NO',
      ]);
    }
    else {
      \Drupal::logger('job_hunter')->info('🔍 SKIPPED escape normalization (no literal escapes detected)');
    }
    
    // If the response starts with { and ends with }, try parsing it directly first
    $first_char = isset($response_text[0]) ? $response_text[0] : 'EMPTY';
    $last_char = strlen($response_text) > 0 ? $response_text[strlen($response_text) - 1] : 'EMPTY';
    
    \Drupal::logger('job_hunter')->info('🔍 DIRECT PARSE CHECK: first_char="@first", last_char="@last", length=@len', [
      '@first' => $first_char,
      '@last' => $last_char,
      '@len' => strlen($response_text),
    ]);
    
    if ($response_text[0] === '{' && $response_text[strlen($response_text) - 1] === '}') {
      \Drupal::logger('job_hunter')->info('🔍 ATTEMPTING direct json_decode (response starts with { and ends with })');
      
      // Test if it's valid JSON by trying to decode it
      $test_decode = json_decode($response_text, TRUE);
      $error_code = json_last_error();
      $error_msg = json_last_error_msg();
      
      if ($error_code === JSON_ERROR_NONE) {
        \Drupal::logger('job_hunter')->info('✅ DIRECT PARSE SUCCESS! Returning valid JSON');
        return $response_text; // It's already valid JSON!
      }
      
      // Log why direct parsing failed with detailed context
      \Drupal::logger('job_hunter')->warning('🟡 DIRECT PARSE FAILED: error=@error (code: @code), First 200 chars: "@start", Last 200 chars: "@end"', [
        '@error' => $error_msg,
        '@code' => $error_code,
        '@start' => substr($response_text, 0, 200),
        '@end' => substr($response_text, -200),
      ]);
    }
    else {
      // Log why we didn't try direct parsing
      \Drupal::logger('job_hunter')->warning('🟡 SKIPPED direct parse. First: "@first" (expected: "{"), Last: "@last" (expected: "}"), First 100: "@start", Last 100: "@end"', [
        '@first' => $first_char,
        '@last' => $last_char,
        '@start' => substr($response_text, 0, 100),
        '@end' => substr($response_text, -100),
      ]);
    }
    
    // Try markdown code fence
    \Drupal::logger('job_hunter')->info('🔍 CHECKING for markdown code fence...');
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/s', $response_text, $matches)) {
      $extracted = trim($matches[1]);
      \Drupal::logger('job_hunter')->info('✅ FOUND markdown code fence! Extracted @len chars', ['@len' => strlen($extracted)]);
      return $extracted;
    }
    \Drupal::logger('job_hunter')->info('🔍 No markdown code fence found, proceeding to brace counting');
    
    // Find balanced JSON using brace counting (handles truncated responses)
    $start_pos = strpos($response_text, '{');
    if ($start_pos === FALSE) {
      \Drupal::logger('job_hunter')->error('❌ BRACE COUNTING: No opening brace found in response');
      return NULL;
    }
    
    \Drupal::logger('job_hunter')->info('🔍 BRACE COUNTING START: opening_brace at position @pos, will scan @chars chars', [
      '@pos' => $start_pos,
      '@chars' => strlen($response_text) - $start_pos,
    ]);

    $depth = 0;
    $in_string = FALSE;
    $escape_next = FALSE;
    $len = strlen($response_text);
    $last_quote_pos = -1;
    $last_open_brace_pos = -1;
    $last_close_brace_pos = -1;
    $last_logged_at = 0;

    for ($i = $start_pos; $i < $len; $i++) {
      $char = $response_text[$i];

      // Log progress every 10000 characters
      if ($i - $last_logged_at >= 10000) {
        \Drupal::logger('job_hunter')->info('🔍 BRACE COUNTING PROGRESS: position @pos/@total (@pct%), depth=@depth, in_string=@str, last_char="@char"', [
          '@pos' => $i,
          '@total' => $len,
          '@pct' => round(($i / $len) * 100, 1),
          '@depth' => $depth,
          '@str' => $in_string ? 'YES' : 'NO',
          '@char' => $char,
        ]);
        $last_logged_at = $i;
      }

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
        $last_quote_pos = $i;
        continue;
      }
      if ($in_string) {
        continue;
      }
      if ($char === '{') {
        $depth++;
        $last_open_brace_pos = $i;
      }
      elseif ($char === '}') {
        $depth--;
        $last_close_brace_pos = $i;
        if ($depth === 0) {
          $extracted_json = substr($response_text, $start_pos, $i - $start_pos + 1);
          \Drupal::logger('job_hunter')->info('✅ BRACE COUNTING SUCCESS: Found complete JSON at positions @start to @end (@len chars)', [
            '@start' => $start_pos,
            '@end' => $i,
            '@len' => strlen($extracted_json),
          ]);
          return $extracted_json;
        }
      }
    }

    // If we got here, loop completed without finding balanced JSON
    \Drupal::logger('job_hunter')->warning('🟡 BRACE COUNTING ENDED: Loop completed without finding balanced JSON');
    \Drupal::logger('job_hunter')->warning('🟡 FINAL STATE: depth=@depth, in_string=@str, escape_next=@esc, last_quote_pos=@qpos, last_open_brace=@opos, last_close_brace=@cpos', [
      '@depth' => $depth,
      '@str' => $in_string ? 'YES' : 'NO',
      '@esc' => $escape_next ? 'YES' : 'NO',
      '@qpos' => $last_quote_pos,
      '@opos' => $last_open_brace_pos,
      '@cpos' => $last_close_brace_pos,
    ]);
    
    // If we got here AND we're stuck in_string, it might be a parsing error
    // Try to validate if the JSON up to the last quote is valid
    if ($in_string && $last_quote_pos > 0 && $depth > 0) {
      \Drupal::logger('job_hunter')->warning('🟡 Stuck in string state. Attempting JSON recovery by scanning backwards from last close brace...');
      
      // Try to find the last valid complete JSON object by working backwards
      $recovery_attempts = 0;
      for ($i = $len - 1; $i > $start_pos; $i--) {
        if ($response_text[$i] === '}') {
          $recovery_attempts++;
          $candidate = substr($response_text, $start_pos, $i - $start_pos + 1);
          $test = json_decode($candidate, TRUE);
          $test_error = json_last_error();
          
          if ($test_error === JSON_ERROR_NONE) {
            \Drupal::logger('job_hunter')->info('✅ RECOVERY SUCCESS: Found valid JSON by truncating at position @pos (after @attempts attempts)', [
              '@pos' => $i,
              '@attempts' => $recovery_attempts,
            ]);
            return $candidate;
          }
          
          // Log first few failed recovery attempts
          if ($recovery_attempts <= 3) {
            \Drupal::logger('job_hunter')->info('🔍 Recovery attempt @num at pos @pos failed: @error', [
              '@num' => $recovery_attempts,
              '@pos' => $i,
              '@error' => json_last_error_msg(),
            ]);
          }
        }
      }
      \Drupal::logger('job_hunter')->warning('🟡 JSON recovery failed after @attempts attempts', ['@attempts' => $recovery_attempts]);
    }

    // If we got here, brace counting failed but response looks like JSON
    // Log the final state for debugging with maximum context
    $context_radius = 200;
    $last_char_pos = $len - 1;
    \Drupal::logger('job_hunter')->error('❌ BRACE COUNTING FAILED - Final state: depth=@depth, in_string=@str, total_length=@len', [
      '@depth' => $depth,
      '@str' => $in_string ? 'YES' : 'NO',
      '@len' => $len,
    ]);
    \Drupal::logger('job_hunter')->error('❌ CONTEXT at failure: Last @radius chars: "@end"', [
      '@radius' => $context_radius,
      '@end' => substr($response_text, -$context_radius),
    ]);
    
    if ($last_close_brace_pos > 0) {
      \Drupal::logger('job_hunter')->error('❌ Last closing brace at position @pos, context around it: "@context"', [
        '@pos' => $last_close_brace_pos,
        '@context' => substr($response_text, max(0, $last_close_brace_pos - 50), 100),
      ]);
    }

    return NULL;
  }

}
