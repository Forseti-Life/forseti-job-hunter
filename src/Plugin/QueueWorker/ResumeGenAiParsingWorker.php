<?php

namespace Drupal\job_hunter\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\file\Entity\File;
use Drupal\job_hunter\Traits\QueueWorkerBaseTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resume GenAI parsing queue worker.
 *
 * Processes resume parsing via AWS Bedrock in the background.
 *
 * @QueueWorker(
 *   id = "job_hunter_genai_parsing",
 *   title = @Translation("Resume GenAI Parsing"),
 *   cron = {"time" = 120}
 * )
 */
class ResumeGenAiParsingWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use QueueWorkerBaseTrait;

  /**
   * Maximum characters for core profile chunks.
   */
  private const CORE_CHUNK_SIZE = 8000;

  /**
   * Maximum characters for professional experience chunks.
   */
  private const EXPERIENCE_CHUNK_SIZE = 6000;

  /**
   * Max tokens for core profile responses.
   */
  private const CORE_MAX_TOKENS = 6000;

  /**
   * Max tokens for professional experience responses.
   */
  private const EXPERIENCE_MAX_TOKENS = 7000;

  /**
   * Maximum retry depth for splitting experience chunks.
   */
  private const EXPERIENCE_SPLIT_DEPTH = 2;

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
    $uid = $data['uid'];
    $resume_id = $data['resume_id'];
    $file_id = $data['file_id'];
    $extracted_text = $data['extracted_text'];
    $filename = $data['filename'];

    $logger = \Drupal::logger('job_hunter');
    $logger->info('🔄 Queue: Starting GenAI parsing for @filename (user @uid)', [
      '@filename' => $filename,
      '@uid' => $uid,
    ]);

    try {
      // Update status to processing
      $connection = \Drupal::database();
      $connection->update('jobhunter_resume_parsed_data')
        ->fields(['status' => 'processing', 'changed' => \Drupal::time()->getRequestTime()])
        ->condition('resume_file_id', $file_id)
        ->condition('uid', $uid)
        ->execute();

      // Call GenAI parsing
      $result = $this->parseResumeProdMode($extracted_text, $filename, $uid);
      $parsed_data = $result['parsed_data'];
      $raw_responses = $result['raw_responses'];
      $raw_core_responses = $raw_responses['core'] ?? [];
      $raw_experience_responses = $raw_responses['experience'] ?? [];

      // Store successful result with raw responses for debugging
      $core_raw_text = $this->formatRawResponses($raw_core_responses);
      
      $connection->update('jobhunter_resume_parsed_data')
        ->fields([
          'parsed_data' => json_encode($parsed_data),
          'raw_genai_response_core' => $core_raw_text,
          'raw_genai_response_experience' => json_encode($raw_experience_responses),
          'status' => 'complete',
          'error_message' => NULL,
          'changed' => \Drupal::time()->getRequestTime(),
        ])
        ->condition('resume_file_id', $file_id)
        ->condition('uid', $uid)
        ->execute();

      $logger->info('✅ Queue: GenAI parsing complete for @filename', ['@filename' => $filename]);

      // Check if all queued items are complete before consolidating
      $pending_count = $connection->select('jobhunter_resume_parsed_data', 'rpd')
        ->condition('uid', $uid)
        ->condition('status', ['queued', 'processing'], 'IN')
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($pending_count == 0) {
        // All files complete - consolidate all parsed data
        $logger->info('🔄 Queue: All files complete for user @uid, running consolidation', ['@uid' => $uid]);
        $this->consolidateAllParsedData($uid);
      } else {
        $logger->info('⏳ Queue: @count files still pending for user @uid, deferring consolidation', [
          '@count' => $pending_count,
          '@uid' => $uid,
        ]);
      }

    } catch (\Exception $e) {
      $logger->error('❌ Queue: GenAI parsing failed for @filename: @error', [
        '@filename' => $filename,
        '@error' => $e->getMessage(),
      ]);

      // Store error status
      $connection = \Drupal::database();
      $connection->update('jobhunter_resume_parsed_data')
        ->fields([
          'status' => 'error',
          'error_message' => $e->getMessage(),
          'changed' => \Drupal::time()->getRequestTime(),
        ])
        ->condition('resume_file_id', $file_id)
        ->condition('uid', $uid)
        ->execute();

      // Re-throw - if it's a SuspendQueueException, preserve it
      throw $e;
    }
  }

  /**
   * Parse resume using GenAI (chunked approach).
   * 
   * @return array
   *   Array with 'parsed_data' and 'raw_responses' keys.
   */
  private function parseResumeProdMode($extracted_text, $filename, $uid) {
    $logger = \Drupal::logger('job_hunter');

    // Get username for logging
    $user = \Drupal\user\Entity\User::load($uid);
    $username = $user ? $user->getAccountName() : "uid:$uid";
    
    // Track raw responses for debugging
    $raw_responses = [
      'core' => [],
      'experience' => [],
    ];

    // Parse core profile data in smaller chunks to avoid token limits.
    $core_data = $this->parseCoreProfileFromChunks(
      $extracted_text,
      $filename,
      $uid,
      $username,
      $raw_responses
    );

    // Parse professional experience in smaller passes with retry splits.
    $experience_result = $this->parseProfessionalExperienceChunks(
      $extracted_text,
      $filename,
      $uid,
      $username
    );
    $all_experiences = $experience_result['experiences'];
    $raw_responses['experience'] = $experience_result['raw_responses'];

    if (!$core_data) {
      // Suspend queue - GenAI may have succeeded but JSON parsing failed
      throw new SuspendQueueException('Failed to parse core profile sections from any chunk. Check logs for JSON parsing errors. Clear cache if prompt needs adjustment.');
    }

    // Add all collected experiences to final data
    $core_data['professional_experience'] = $this->dedupeProfessionalExperience($all_experiences);
    $logger->info('✅ Total jobs collected: @count', ['@count' => count($core_data['professional_experience'])]);

    return [
      'parsed_data' => $core_data,
      'raw_responses' => $raw_responses,
    ];
  }

  /**
   * Split resume text into chunks of max_chars, breaking at newlines.
   * 
   * @param string $text
   *   The full resume text.
   * @param int $max_chars
   *   Maximum characters per chunk (default 10000).
   * 
   * @return array
   *   Array of text chunks.
   */
  private function chunkResumeText($text, $max_chars = 10000) {
    $chunks = [];
    $current_chunk = '';
    $lines = explode("\n", $text);
    
    foreach ($lines as $line) {
      // If adding this line would exceed max, save current chunk and start new one
      if (strlen($current_chunk) + strlen($line) + 1 > $max_chars && strlen($current_chunk) > 0) {
        $chunks[] = $current_chunk;
        $current_chunk = $line;
      } else {
        $current_chunk .= ($current_chunk ? "\n" : '') . $line;
      }
    }
    
    // Add the last chunk if not empty
    if (strlen($current_chunk) > 0) {
      $chunks[] = $current_chunk;
    }
    
    return $chunks;
  }

  /**
   * Call AWS Bedrock via AIApiService and parse JSON response.
   * 
   * @param int $max_tokens
   *   Maximum tokens for response (default 20000 for chunked processing)
   * 
   * @return array
   *   Array with 'parsed_data' and 'raw_response' keys.
   */
  private function callBedrockAndParse($prompt, $chunk_name, $filename = '', $username = '', $uid = 0, $max_tokens = 20000) {
    $logger = \Drupal::logger('job_hunter');

    $context_msg = '';
    if ($filename && $username) {
      $context_msg = " for $filename (user $username)";
    }

    $logger->info('⏳ Queue @chunk: Sending request to GenAI API via AIApiService (max_tokens: @tokens)@context', [
      '@chunk' => $chunk_name,
      '@tokens' => $max_tokens,
      '@context' => $context_msg,
    ]);

    // Use centralized AIApiService
    $result = $this->aiApiService->invokeModelDirect(
      $prompt,
      'job_hunter',
      'resume_parsing',
      [
        'uid' => $uid,
        'filename' => $filename,
        'chunk' => $chunk_name,
        'queue' => 'job_hunter_genai_parsing',
        'item_key' => "resume_parsing_{$uid}_{$filename}_{$chunk_name}",
      ],
      [
        'max_tokens' => $max_tokens,
      ]
    );

    if (!$result['success']) {
      $logger->error('❌ Queue @chunk: AIApiService call failed: @error@context', [
        '@chunk' => $chunk_name,
        '@error' => $result['error'] ?? 'Unknown error',
        '@context' => $context_msg,
      ]);
      return [
        'success' => FALSE,
        'parsed_data' => NULL,
        'raw_response' => $result['error'] ?? 'Unknown error',
        'stop_reason' => $result['stop_reason'] ?? NULL,
        'error' => $result['error'] ?? 'Unknown error',
      ];
    }

    $response_text = $result['response'];
    $stop_reason = $result['stop_reason'];

    // Check if response was truncated due to max_tokens limit
    if ($stop_reason === 'max_tokens') {
      $logger->error('❌ Queue @chunk hit max_tokens limit! Response truncated at @len chars@context. Increase max_tokens to fix this.', [
        '@chunk' => $chunk_name,
        '@len' => strlen($response_text),
        '@context' => $context_msg,
      ]);
    }

    $logger->info('🔍 Queue @chunk response: @len chars, stop_reason: @reason@context', [
      '@chunk' => $chunk_name,
      '@len' => strlen($response_text),
      '@reason' => $stop_reason,
      '@context' => $context_msg,
    ]);

    $json_text = $this->extractJsonFromResponse($response_text);

    if ($json_text) {
      $parsed_data = json_decode($json_text, TRUE);
      if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_data)) {
        $logger->info('✅ Queue @chunk JSON parsed successfully: @keys top-level keys', [
          '@chunk' => $chunk_name,
          '@keys' => count($parsed_data),
        ]);
        return [
          'success' => TRUE,
          'parsed_data' => $parsed_data,
          'raw_response' => $response_text,
          'stop_reason' => $stop_reason,
          'error' => NULL,
        ];
      } else {
        $logger->error('🔴 Queue @chunk JSON decode error: @error. JSON length: @len chars, First 500 chars: @sample', [
          '@chunk' => $chunk_name,
          '@error' => json_last_error_msg(),
          '@len' => strlen($json_text),
          '@sample' => substr($json_text, 0, 500),
        ]);
      }
    } else {
      // Log why extraction failed
      $starts_with_brace = (strpos(trim($response_text), '{') === 0) ? 'YES' : 'NO';
      $logger->error('🔴 Queue @chunk failed to extract JSON. Response length: @len chars, Starts with brace: @brace, Sample (first 500 chars): @sample', [
        '@chunk' => $chunk_name,
        '@len' => strlen($response_text),
        '@brace' => $starts_with_brace,
        '@sample' => substr($response_text, 0, 500),
      ]);
    }

    return [
      'success' => TRUE,
      'parsed_data' => NULL,
      'raw_response' => $response_text,
      'stop_reason' => $stop_reason,
      'error' => NULL,
    ];
  }

  /**
   * Parse core profile sections from smaller chunks until contact info is found.
   */
  private function parseCoreProfileFromChunks($extracted_text, $filename, $uid, $username, array &$raw_responses) {
    $logger = \Drupal::logger('job_hunter');
    $chunks = $this->chunkResumeText($extracted_text, self::CORE_CHUNK_SIZE);
    $logger->info('📊 Core profile parsing in @count chunks', ['@count' => count($chunks)]);

    $merged_core = [];
    $found_any = FALSE;
    $chunk_num = 0;
    foreach ($chunks as $chunk) {
      $chunk_num++;
      $chunk_name = "core_chunk_{$chunk_num}";
      $logger->info('🔄 Core chunk @num/@total: @chars chars', [
        '@num' => $chunk_num,
        '@total' => count($chunks),
        '@chars' => strlen($chunk),
      ]);

      $prompt = $this->buildCoreProfilePrompt($chunk, $filename);
      $result = $this->callBedrockAndParse($prompt, $chunk_name, $filename, $username, $uid, self::CORE_MAX_TOKENS);
      $raw_responses['core'][$chunk_name] = $result['raw_response'] ?? '';

      if (!empty($result['parsed_data']) && is_array($result['parsed_data'])) {
        $found_any = TRUE;
        $this->mergeCoreChunkParsedData($merged_core, $result['parsed_data']);
        $logger->info('✅ Core profile data merged from @chunk', ['@chunk' => $chunk_name]);
      }

      if ($this->isTokenLimitResult($result)) {
        $logger->warning('⚠️ Core chunk @chunk hit token limit; continuing with next chunk', [
          '@chunk' => $chunk_name,
        ]);
      }
    }

    if (!$found_any) {
      return NULL;
    }

    if (!empty($merged_core['education']) && is_array($merged_core['education'])) {
      $merged_core['education'] = $this->dedupeEducationEntries(
        $this->filterEducationEntries($merged_core['education'])
      );
    }
    if (!empty($merged_core['certifications']) && is_array($merged_core['certifications'])) {
      $merged_core['certifications'] = $this->dedupeCertificationEntries($merged_core['certifications']);
    }
    if (!empty($merged_core['languages']) && is_array($merged_core['languages'])) {
      $merged_core['languages'] = $this->dedupeLanguageEntries($merged_core['languages']);
    }
    if (!empty($merged_core['technical_expertise']) && is_array($merged_core['technical_expertise'])) {
      $merged_core['technical_expertise'] = $this->normalizeTechnicalExpertise($merged_core['technical_expertise']);
    }

    if (empty($merged_core['schema_version'])) {
      $merged_core['schema_version'] = '1.0';
    }

    return $merged_core;
  }

  /**
   * Merge parsed core chunk data into a single consolidated core payload.
   */
  private function mergeCoreChunkParsedData(array &$merged, array $chunk_data): void {
    foreach ($chunk_data as $section => $value) {
      if ($section === 'professional_experience') {
        continue;
      }

      if ($value === NULL || $value === '' || $value === []) {
        continue;
      }

      if (!isset($merged[$section])) {
        $merged[$section] = $value;
        continue;
      }

      if (is_array($merged[$section]) && is_array($value)) {
        $merged[$section] = $this->mergeArrayPreferExisting($merged[$section], $value);
      }
      elseif (empty($merged[$section])) {
        $merged[$section] = $value;
      }
    }
  }

  /**
   * Merge arrays while preserving existing non-empty values.
   */
  private function mergeArrayPreferExisting(array $base, array $incoming): array {
    if ($this->isListArray($base) || $this->isListArray($incoming)) {
      $merged = $base;
      foreach ($incoming as $item) {
        $exists = FALSE;
        foreach ($merged as $existing) {
          if (json_encode($existing) === json_encode($item)) {
            $exists = TRUE;
            break;
          }
        }
        if (!$exists) {
          $merged[] = $item;
        }
      }
      return $merged;
    }

    foreach ($incoming as $key => $value) {
      if (!array_key_exists($key, $base) || $base[$key] === NULL || $base[$key] === '' || $base[$key] === []) {
        $base[$key] = $value;
        continue;
      }

      if (is_array($base[$key]) && is_array($value)) {
        $base[$key] = $this->mergeArrayPreferExisting($base[$key], $value);
      }
    }

    return $base;
  }

  /**
   * Determine whether an array is a list (numeric sequential keys).
   */
  private function isListArray(array $value): bool {
    return array_keys($value) === range(0, count($value) - 1);
  }

  /**
   * Parse professional experience in smaller passes with split retries.
   */
  private function parseProfessionalExperienceChunks($extracted_text, $filename, $uid, $username) {
    $logger = \Drupal::logger('job_hunter');
    $chunks = $this->chunkResumeText($extracted_text, self::EXPERIENCE_CHUNK_SIZE);
    $logger->info('📊 Experience parsing in @count chunks', ['@count' => count($chunks)]);

    $all_experiences = [];
    $raw_responses = [];

    $chunk_num = 0;
    foreach ($chunks as $chunk) {
      $chunk_num++;
      $chunk_name = "experience_chunk_{$chunk_num}";
      $logger->info('🔄 Experience chunk @num/@total: @chars chars', [
        '@num' => $chunk_num,
        '@total' => count($chunks),
        '@chars' => strlen($chunk),
      ]);

      $result = $this->parseExperienceChunkWithRetries(
        $chunk,
        $chunk_name,
        $filename,
        $username,
        $uid,
        0
      );

      $all_experiences = array_merge($all_experiences, $result['experiences']);
      $raw_responses = array_merge($raw_responses, $result['raw_responses']);
    }

    return [
      'experiences' => $all_experiences,
      'raw_responses' => $raw_responses,
    ];
  }

  /**
   * Parse a single experience chunk, retrying with smaller splits on token limits.
   */
  private function parseExperienceChunkWithRetries($chunk_text, $chunk_name, $filename, $username, $uid, $depth) {
    $logger = \Drupal::logger('job_hunter');
    $prompt = $this->buildProfessionalExperiencePrompt($chunk_text, $filename);
    $result = $this->callBedrockAndParse(
      $prompt,
      $chunk_name,
      $filename,
      $username,
      $uid,
      self::EXPERIENCE_MAX_TOKENS
    );

    $raw_responses = [$chunk_name => $result['raw_response'] ?? ''];

    if (!empty($result['parsed_data']['professional_experience'])) {
      $logger->info('✅ Experience parsed in @chunk: @count jobs', [
        '@chunk' => $chunk_name,
        '@count' => count($result['parsed_data']['professional_experience']),
      ]);
      return [
        'experiences' => $result['parsed_data']['professional_experience'],
        'raw_responses' => $raw_responses,
      ];
    }

    if ($this->isTokenLimitResult($result) && $depth < self::EXPERIENCE_SPLIT_DEPTH) {
      $logger->warning('⚠️ Experience chunk @chunk hit token limit, splitting (depth @depth)', [
        '@chunk' => $chunk_name,
        '@depth' => $depth,
      ]);
      $subchunks = $this->splitChunkForRetry($chunk_text);
      $split_experiences = [];
      foreach ($subchunks as $index => $subchunk) {
        $child_name = $chunk_name . '_part_' . ($index + 1);
        $child_result = $this->parseExperienceChunkWithRetries(
          $subchunk,
          $child_name,
          $filename,
          $username,
          $uid,
          $depth + 1
        );
        $split_experiences = array_merge($split_experiences, $child_result['experiences']);
        $raw_responses = array_merge($raw_responses, $child_result['raw_responses']);
      }

      return [
        'experiences' => $split_experiences,
        'raw_responses' => $raw_responses,
      ];
    }

    $fallback_experiences = $this->extractProfessionalExperienceFromChunkText((string) $chunk_text, (string) $chunk_name);
    if (!empty($fallback_experiences)) {
      $logger->warning('⚠️ Experience fallback parser recovered @count role(s) from @chunk', [
        '@count' => count($fallback_experiences),
        '@chunk' => $chunk_name,
      ]);
      return [
        'experiences' => $fallback_experiences,
        'raw_responses' => $raw_responses,
      ];
    }

    return [
      'experiences' => [],
      'raw_responses' => $raw_responses,
    ];
  }

  /**
   * Split a chunk into smaller pieces for retry.
   */
  private function splitChunkForRetry($chunk_text) {
    $lines = explode("\n", $chunk_text);
    if (count($lines) > 1) {
      $mid = (int) ceil(count($lines) / 2);
      return [
        implode("\n", array_slice($lines, 0, $mid)),
        implode("\n", array_slice($lines, $mid)),
      ];
    }

    $length = strlen($chunk_text);
    if ($length <= 1) {
      return [$chunk_text];
    }

    $mid = (int) floor($length / 2);
    return [
      substr($chunk_text, 0, $mid),
      substr($chunk_text, $mid),
    ];
  }

  /**
   * Check if the GenAI response indicates a token limit failure.
   */
  private function isTokenLimitResult(array $result) {
    if (!empty($result['stop_reason']) && $result['stop_reason'] === 'max_tokens') {
      return TRUE;
    }

    $error = strtolower((string) ($result['error'] ?? ''));
    return strpos($error, 'token') !== FALSE || strpos($error, 'queue_tokens') !== FALSE;
  }

  /**
   * Build a single raw response string for logging and storage.
   */
  private function formatRawResponses(array $responses) {
    $output = '';
    foreach ($responses as $chunk_name => $raw_response) {
      $output .= "=== {$chunk_name} ===\n" . $raw_response . "\n\n";
    }

    return $output;
  }

  /**
   * De-duplicate professional experiences by company, title, and start date.
   */
  private function dedupeProfessionalExperience(array $experiences) {
    $unique_experiences = [];
    $index_by_key = [];

    foreach ($experiences as $exp) {
      if (!is_array($exp)) {
        continue;
      }

      $key = $this->buildProfessionalExperienceDedupeKey($exp);
      if (!isset($index_by_key[$key])) {
        $index_by_key[$key] = count($unique_experiences);
        $unique_experiences[] = $exp;
        continue;
      }

      $existing_index = $index_by_key[$key];
      $unique_experiences[$existing_index] = $this->mergeProfessionalExperienceEntries(
        $unique_experiences[$existing_index],
        $exp
      );
    }

    return array_values(array_map(function (array $entry): array {
      return $this->normalizeProfessionalExperienceDetails($entry);
    }, $unique_experiences));
  }

  /**
   * Build a stable de-duplication key for professional experience entries.
   */
  private function buildProfessionalExperienceDedupeKey(array $experience): string {
    $company = $this->normalizeExperienceToken($experience['company'] ?? '');
    $start_date = $this->normalizeExperienceToken($this->extractExperienceDate($experience, 'start'));
    $end_date = $this->normalizeExperienceToken($this->extractExperienceDate($experience, 'end'));

    return implode('|', [
      $company,
      $start_date,
      $end_date,
    ]);
  }

  /**
   * Extract start/end date from normalized or nested date shapes.
   */
  private function extractExperienceDate(array $experience, string $which): string {
    $key = $which === 'end' ? 'end_date' : 'start_date';
    if (!empty($experience[$key]) && is_string($experience[$key])) {
      return $experience[$key];
    }

    if (!empty($experience['dates']) && is_array($experience['dates']) && !empty($experience['dates'][$key]) && is_string($experience['dates'][$key])) {
      return $experience['dates'][$key];
    }

    return '';
  }

  /**
   * Normalize comparison tokens to reduce trivial duplicate variance.
   */
  private function normalizeExperienceToken($value): string {
    if (!is_string($value)) {
      return '';
    }

    $normalized = mb_strtolower(trim($value));
    if ($normalized === '') {
      return '';
    }

    if (in_array($normalized, ['unknown', 'n/a', 'na', 'none', 'null', 'not provided', 'not specified', 'tbd', 'present', 'current', 'now'], TRUE)) {
      return '';
    }

    // Normalize abbreviations and remove punctuation-only differences.
    $normalized = preg_replace('/\bst\.?\b/u', 'saint', $normalized);
    $normalized = preg_replace('/[\.,\-\/\(\)]/u', ' ', $normalized);
    $normalized = preg_replace('/\b(inc|llc|ltd|corp|corporation|company|co)\b/u', ' ', $normalized);
    $normalized = preg_replace('/\s+/u', ' ', $normalized);

    return trim($normalized);
  }

  /**
   * Merge duplicate professional experience entries, favoring richer content.
   */
  private function mergeProfessionalExperienceEntries(array $existing, array $incoming): array {
    if ($this->scoreProfessionalExperienceEntry($incoming) > $this->scoreProfessionalExperienceEntry($existing)) {
      $base = $incoming;
      $other = $existing;
    }
    else {
      $base = $existing;
      $other = $incoming;
    }

    foreach (['company', 'title', 'location', 'company_context', 'employment_type', 'via_company', 'start_date', 'end_date'] as $field) {
      if ((empty($base[$field]) || $base[$field] === NULL) && !empty($other[$field])) {
        $base[$field] = $other[$field];
      }
    }

    // Also support nested date shape used by some editors.
    if (empty($base['dates']) && !empty($other['dates']) && is_array($other['dates'])) {
      $base['dates'] = $other['dates'];
    }

    if (empty($base['responsibility_categories']) && !empty($other['responsibility_categories']) && is_array($other['responsibility_categories'])) {
      $base['responsibility_categories'] = $other['responsibility_categories'];
    }

    if (empty($base['description']) && !empty($other['description']) && is_string($other['description'])) {
      $base['description'] = $other['description'];
    }

    foreach (['highlights', 'key_achievements', 'technologies'] as $field) {
      if (empty($base[$field]) && !empty($other[$field])) {
        $base[$field] = $other[$field];
      }
    }

    return $this->normalizeProfessionalExperienceDetails($base);
  }

  /**
   * Ensure normalized derived details exist on each professional experience row.
   */
  private function normalizeProfessionalExperienceDetails(array $entry): array {
    $achievement_lines = [];

    if (!empty($entry['key_achievements']) && is_array($entry['key_achievements'])) {
      foreach ($entry['key_achievements'] as $line) {
        $text = trim((string) $line);
        if ($text !== '') {
          $achievement_lines[] = $text;
        }
      }
    }

    if (empty($achievement_lines)) {
      $achievement_lines = $this->extractAchievementTextsFromResponsibilityCategories($entry);
    }

    if (!empty($achievement_lines)) {
      $entry['key_achievements'] = array_values(array_unique($achievement_lines));
    }

    if (empty($entry['technologies']) || !is_array($entry['technologies'])) {
      $entry['technologies'] = $this->extractTechnologiesFromResponsibilityCategories($entry);
    }
    else {
      $entry['technologies'] = array_values(array_unique(array_filter(array_map(function ($technology) {
        return trim((string) $technology);
      }, $entry['technologies']))));
    }

    $highlights = trim((string) ($entry['highlights'] ?? ''));
    if ($highlights === '') {
      if (!empty($entry['description']) && is_string($entry['description'])) {
        $highlights = trim($entry['description']);
      }
      elseif (!empty($entry['key_achievements']) && is_array($entry['key_achievements'])) {
        $highlights = implode("\n", array_slice($entry['key_achievements'], 0, 2));
      }
    }
    if ($highlights !== '') {
      $entry['highlights'] = $highlights;
    }

    return $entry;
  }

  /**
   * Extract achievement texts from nested responsibility categories.
   */
  private function extractAchievementTextsFromResponsibilityCategories(array $entry): array {
    $lines = [];
    $categories = $entry['responsibility_categories'] ?? NULL;
    if (!is_array($categories)) {
      return [];
    }

    foreach ($categories as $category) {
      if (!is_array($category) || empty($category['achievements']) || !is_array($category['achievements'])) {
        continue;
      }

      foreach ($category['achievements'] as $achievement) {
        if (is_array($achievement)) {
          $text = trim((string) ($achievement['text'] ?? ''));
          if ($text !== '') {
            $lines[] = $text;
          }
        }
        elseif (is_string($achievement)) {
          $text = trim($achievement);
          if ($text !== '') {
            $lines[] = $text;
          }
        }
      }
    }

    return array_values(array_unique($lines));
  }

  /**
   * Extract technologies from nested responsibility categories.
   */
  private function extractTechnologiesFromResponsibilityCategories(array $entry): array {
    $technologies = [];
    $categories = $entry['responsibility_categories'] ?? NULL;
    if (!is_array($categories)) {
      return [];
    }

    foreach ($categories as $category) {
      if (!is_array($category) || empty($category['achievements']) || !is_array($category['achievements'])) {
        continue;
      }

      foreach ($category['achievements'] as $achievement) {
        if (!is_array($achievement) || empty($achievement['technologies']) || !is_array($achievement['technologies'])) {
          continue;
        }

        foreach ($achievement['technologies'] as $technology) {
          $name = trim((string) $technology);
          if ($name !== '') {
            $technologies[] = $name;
          }
        }
      }
    }

    return array_values(array_unique($technologies));
  }

  /**
   * Rough completeness score for selecting the better duplicate record.
   */
  private function scoreProfessionalExperienceEntry(array $entry): int {
    $score = 0;
    foreach (['company', 'title', 'location', 'company_context', 'employment_type', 'via_company', 'start_date', 'end_date'] as $field) {
      if (!empty($entry[$field])) {
        $score += 1;
      }
    }

    if (!empty($entry['dates']) && is_array($entry['dates'])) {
      if (!empty($entry['dates']['start_date'])) {
        $score += 1;
      }
      if (!empty($entry['dates']['end_date'])) {
        $score += 1;
      }
    }

    if (!empty($entry['responsibility_categories']) && is_array($entry['responsibility_categories'])) {
      $score += count($entry['responsibility_categories']);
    }

    if (!empty($entry['highlights'])) {
      if (is_array($entry['highlights'])) {
        $score += count($entry['highlights']);
      }
      elseif (is_string($entry['highlights'])) {
        $score += 1;
      }
    }

    if (!empty($entry['key_achievements']) && is_array($entry['key_achievements'])) {
      $score += count($entry['key_achievements']);
    }

    if (!empty($entry['technologies']) && is_array($entry['technologies'])) {
      $score += count($entry['technologies']);
    }

    return $score;
  }

  /**
   * Deterministically recover structured experience from unparseable chunk text.
   */
  private function extractProfessionalExperienceFromChunkText(string $chunk_text, string $chunk_name): array {
    $lines = preg_split('/\R+/', $chunk_text) ?: [];
    $trimmed_lines = [];
    foreach ($lines as $line) {
      $text = trim((string) $line);
      if ($text !== '') {
        $trimmed_lines[] = $text;
      }
    }

    if (count($trimmed_lines) < 3) {
      return [];
    }

    $blocks = preg_split('/\R\s*\R/u', $chunk_text) ?: [];
    $recovered = [];

    foreach ($blocks as $block) {
      $entry = $this->buildProfessionalExperienceFallbackEntry($block, $chunk_name);
      if ($entry !== NULL) {
        $recovered[] = $this->normalizeProfessionalExperienceDetails($entry);
      }
    }

    return $this->dedupeProfessionalExperience($recovered);
  }

  /**
   * Build one fallback experience row from a text block.
   */
  private function buildProfessionalExperienceFallbackEntry(string $block_text, string $chunk_name): ?array {
    $lines = preg_split('/\R+/', $block_text) ?: [];
    $lines = array_values(array_filter(array_map(static function ($line) {
      return trim((string) $line);
    }, $lines), static function ($line) {
      return $line !== '';
    }));

    if (count($lines) < 2) {
      return NULL;
    }

    $date_candidate = '';
    foreach ($lines as $line) {
      if (preg_match('/\b((?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\s+\d{4}|\d{4})\b\s*(?:-|to|–|—)\s*\b((?:present|current|now|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\s+\d{4}|\d{4}))\b/i', $line)) {
        $date_candidate = $line;
        break;
      }
    }

    $achievement_lines = [];
    foreach ($lines as $line) {
      if (preg_match('/^(?:[-*•]|\d+[\.)])\s+/', $line)) {
        $achievement_lines[] = preg_replace('/^(?:[-*•]|\d+[\.)])\s+/', '', $line);
      }
    }

    if ($date_candidate === '' && empty($achievement_lines)) {
      return NULL;
    }

    $header_lines = [];
    foreach ($lines as $line) {
      if ($line === $date_candidate) {
        continue;
      }
      if (preg_match('/^(?:[-*•]|\d+[\.)])\s+/', $line)) {
        continue;
      }
      $header_lines[] = $line;
      if (count($header_lines) >= 2) {
        break;
      }
    }

    $company = '';
    $title = '';
    if (!empty($header_lines)) {
      $first_header = $header_lines[0];
      if (stripos($first_header, ' at ') !== FALSE) {
        [$title_part, $company_part] = array_map('trim', explode(' at ', $first_header, 2));
        $title = (string) $title_part;
        $company = (string) $company_part;
      }
      elseif (strpos($first_header, '|') !== FALSE) {
        [$left, $right] = array_map('trim', explode('|', $first_header, 2));
        $title = (string) $left;
        $company = (string) $right;
      }
      else {
        $title = $first_header;
        if (!empty($header_lines[1])) {
          $company = $header_lines[1];
        }
      }
    }

    $start_date = NULL;
    $end_date = NULL;
    if ($date_candidate !== '') {
      if (preg_match('/\b((?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\s+\d{4}|\d{4})\b\s*(?:-|to|–|—)\s*\b((?:present|current|now|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\s+\d{4}|\d{4}))\b/i', $date_candidate, $m)) {
        $start_date = $this->normalizeResumeDateValue((string) $m[1]);
        $end_raw = strtolower(trim((string) $m[2]));
        $end_date = in_array($end_raw, ['present', 'current', 'now'], TRUE)
          ? NULL
          : $this->normalizeResumeDateValue((string) $m[2]);
      }
    }

    $description_lines = [];
    foreach ($lines as $line) {
      if (preg_match('/^(?:[-*•]|\d+[\.)])\s+/', $line)) {
        continue;
      }
      if ($line === $date_candidate) {
        continue;
      }
      if (in_array($line, $header_lines, TRUE)) {
        continue;
      }
      $description_lines[] = $line;
      if (count($description_lines) >= 2) {
        break;
      }
    }

    $description = !empty($description_lines) ? implode("\n", $description_lines) : NULL;
    $highlights = !empty($achievement_lines) ? implode("\n", array_slice($achievement_lines, 0, 2)) : $description;

    if ($company === '' && $title === '' && empty($achievement_lines)) {
      return NULL;
    }

    return [
      'company' => $company !== '' ? $company : 'Recovered from ' . $chunk_name,
      'title' => $title !== '' ? $title : 'Role',
      'start_date' => $start_date,
      'end_date' => $end_date,
      'description' => $description,
      'highlights' => $highlights,
      'key_achievements' => $achievement_lines,
      'technologies' => [],
      'employment_type' => NULL,
      'via_company' => NULL,
      'location' => NULL,
      'company_context' => NULL,
      'responsibility_categories' => !empty($achievement_lines) ? [[
        'category' => 'General Responsibilities',
        'achievements' => array_map(static function ($line) {
          return [
            'text' => $line,
            'metrics' => [],
            'technologies' => [],
            'keywords' => [],
          ];
        }, $achievement_lines),
      ]] : [],
    ];
  }

  /**
   * Normalize freeform date text into YYYY-MM or YYYY.
   */
  private function normalizeResumeDateValue(string $value): ?string {
    $raw = trim($value);
    if ($raw === '') {
      return NULL;
    }

    if (preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/', $raw)) {
      return $raw;
    }

    if (preg_match('/^(19|20)\d{2}$/', $raw)) {
      return $raw;
    }

    $month_map = [
      'jan' => '01', 'january' => '01',
      'feb' => '02', 'february' => '02',
      'mar' => '03', 'march' => '03',
      'apr' => '04', 'april' => '04',
      'may' => '05',
      'jun' => '06', 'june' => '06',
      'jul' => '07', 'july' => '07',
      'aug' => '08', 'august' => '08',
      'sep' => '09', 'sept' => '09', 'september' => '09',
      'oct' => '10', 'october' => '10',
      'nov' => '11', 'november' => '11',
      'dec' => '12', 'december' => '12',
    ];

    if (preg_match('/^([a-zA-Z]+)\s+((?:19|20)\d{2})$/', $raw, $m)) {
      $month_key = strtolower((string) $m[1]);
      if (isset($month_map[$month_key])) {
        return (string) $m[2] . '-' . $month_map[$month_key];
      }
    }

    return NULL;
  }

  /**
   * Consolidate ALL parsed resume data for a user.
   *
   * This is called only when all queued items are complete,
   * ensuring we have all data before building the consolidated profile.
   */
  private function consolidateAllParsedData($uid) {
    try {
      $connection = \Drupal::database();
      $logger = \Drupal::logger('job_hunter');

      // Start from existing consolidated profile so user-entered preferences
      // (work authorization, sponsorship, etc.) are never lost during resume
      // re-consolidation.
      $existing_profile = $connection->select('jobhunter_job_seeker', 'js')
        ->fields('js', ['consolidated_profile_json'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchAssoc();

      $consolidated = [];
      if (!empty($existing_profile['consolidated_profile_json'])) {
        $decoded = json_decode($existing_profile['consolidated_profile_json'], TRUE);
        if (is_array($decoded)) {
          $consolidated = $decoded;
        }
      }

      // Get all completed parsed data for this user
      $results = $connection->select('jobhunter_resume_parsed_data', 'rpd')
        ->fields('rpd', ['parsed_data', 'resume_file_id'])
        ->condition('uid', $uid)
        ->condition('status', 'complete')
        ->orderBy('created', 'ASC')  // Oldest first, newest overwrites
        ->execute()
        ->fetchAll();

      if (empty($results)) {
        $logger->warning('Queue: No completed parsed data found for user @uid', ['@uid' => $uid]);
        return;
      }

      $professional_experiences = [];
      $education_entries = is_array($consolidated['education'] ?? NULL) ? $consolidated['education'] : [];
      $certification_entries = is_array($consolidated['certifications'] ?? NULL) ? $consolidated['certifications'] : [];
      $language_entries = is_array($consolidated['languages'] ?? NULL) ? $consolidated['languages'] : [];
      $publication_entries = is_array($consolidated['publications'] ?? NULL) ? $consolidated['publications'] : [];
      $patent_entries = is_array($consolidated['patents'] ?? NULL) ? $consolidated['patents'] : [];
      $award_entries = is_array($consolidated['awards_and_honors'] ?? NULL) ? $consolidated['awards_and_honors'] : [];
      $resume_texts_by_file_id = [];
      $source_files = [];

      foreach ($results as $row) {
        $parsed_data = json_decode($row->parsed_data, TRUE);
        if (!$parsed_data || !is_array($parsed_data)) {
          continue;
        }

        // Track source filenames for status display
        if (!empty($parsed_data['extraction_metadata']['source_filename'])) {
          $source_files[] = $parsed_data['extraction_metadata']['source_filename'];
        } else {
          // Fallback: get filename from file entity
          $file = \Drupal\file\Entity\File::load($row->resume_file_id);
          if ($file) {
            $source_files[] = $file->getFilename();
          }
        }

        // Collect professional experiences from all resumes
        if (!empty($parsed_data['professional_experience'])) {
          $professional_experiences = array_merge(
            $professional_experiences,
            $parsed_data['professional_experience']
          );
          unset($parsed_data['professional_experience']);
        }

        // Collect education entries additively across all parsed resumes.
        if (!empty($parsed_data['education']) && is_array($parsed_data['education'])) {
          $education_entries = array_merge($education_entries, $this->filterEducationEntries($parsed_data['education']));
          unset($parsed_data['education']);
        }

        if (!empty($parsed_data['certifications']) && is_array($parsed_data['certifications'])) {
          $certification_entries = array_merge($certification_entries, $parsed_data['certifications']);
          unset($parsed_data['certifications']);
        }

        if (!empty($parsed_data['languages']) && is_array($parsed_data['languages'])) {
          $language_entries = array_merge($language_entries, $parsed_data['languages']);
          unset($parsed_data['languages']);
        }

        if (!empty($parsed_data['publications']) && is_array($parsed_data['publications'])) {
          $publication_entries = array_merge($publication_entries, $parsed_data['publications']);
          unset($parsed_data['publications']);
        }

        if (!empty($parsed_data['patents']) && is_array($parsed_data['patents'])) {
          $patent_entries = array_merge($patent_entries, $parsed_data['patents']);
          unset($parsed_data['patents']);
        }

        if (!empty($parsed_data['awards_and_honors']) && is_array($parsed_data['awards_and_honors'])) {
          $award_entries = array_merge($award_entries, $parsed_data['awards_and_honors']);
          unset($parsed_data['awards_and_honors']);
        }

        if (!isset($resume_texts_by_file_id[(int) $row->resume_file_id])) {
          $resume_text = $connection->select('jobhunter_job_seeker_resumes', 'jsr')
            ->fields('jsr', ['extracted_text'])
            ->condition('file_id', (int) $row->resume_file_id)
            ->orderBy('id', 'DESC')
            ->range(0, 1)
            ->execute()
            ->fetchField();
          if (!empty($resume_text)) {
            $resume_texts_by_file_id[(int) $row->resume_file_id] = (string) $resume_text;
          }
        }

        // Merge other sections. Preserve user-managed preferences/demographics
        // from existing profile data.
        foreach ($parsed_data as $section => $section_value) {
          if (in_array($section, ['job_search_preferences', 'demographics'], TRUE)) {
            continue;
          }

          // Do not overwrite existing sections with empty/null values.
          if ($section_value === NULL || $section_value === '' || $section_value === []) {
            continue;
          }

          if (!isset($consolidated[$section])) {
            $consolidated[$section] = $section_value;
            continue;
          }

          if (is_array($consolidated[$section]) && is_array($section_value)) {
            if ($this->isListArray($consolidated[$section]) || $this->isListArray($section_value)) {
              $consolidated[$section] = $this->mergeArrayPreferExisting($consolidated[$section], $section_value);
            }
            else {
              // Preserve existing behavior for associative sections: latest complete
              // object from newer resumes overwrites prior object values.
              $consolidated[$section] = $section_value;
            }
            continue;
          }

          $consolidated[$section] = $section_value;
        }
      }

      // De-duplicate professional experiences with normalization-aware matching.
      $unique_experiences = $this->dedupeProfessionalExperience($professional_experiences);

      // Sort by start_date descending (most recent first)
      usort($unique_experiences, function($a, $b) {
        return ($b['start_date'] ?? '') <=> ($a['start_date'] ?? '');
      });

      $consolidated['professional_experience'] = $unique_experiences;
      $consolidated['education'] = $this->dedupeEducationEntries($education_entries);
      $all_extracted_text = implode("\n", array_values($resume_texts_by_file_id));

      $certification_entries = $this->augmentCertificationsFromText(
        $this->dedupeCertificationEntries($certification_entries),
        $all_extracted_text,
        $consolidated
      );
      $consolidated['certifications'] = !empty($certification_entries) ? $certification_entries : [];

      $language_entries = $this->augmentLanguagesFromText(
        $this->dedupeLanguageEntries($language_entries),
        $all_extracted_text
      );
      $consolidated['languages'] = !empty($language_entries) ? $language_entries : [];

      if (!empty($consolidated['technical_expertise']) && is_array($consolidated['technical_expertise'])) {
        $consolidated['technical_expertise'] = $this->normalizeTechnicalExpertise($consolidated['technical_expertise']);
      }
      $publication_entries = $this->augmentPublicationsFromText(
        $this->dedupeNamedEntries($publication_entries, ['title', 'publication_venue', 'date']),
        $all_extracted_text
      );
      $consolidated['publications'] = !empty($publication_entries)
        ? $this->dedupeNamedEntries($publication_entries, ['title', 'publication_venue', 'date'])
        : [];

      $patent_entries = $this->augmentPatentsFromText(
        $this->dedupeNamedEntries($patent_entries, ['patent_number', 'title', 'filing_date']),
        $all_extracted_text
      );
      $consolidated['patents'] = !empty($patent_entries)
        ? $this->dedupeNamedEntries($patent_entries, ['patent_number', 'title', 'filing_date'])
        : [];

      $award_entries = $this->augmentAwardsFromText(
        $this->dedupeNamedEntries($award_entries, ['title', 'issuing_organization', 'date']),
        $all_extracted_text
      );
      $consolidated['awards_and_honors'] = !empty($award_entries)
        ? $this->dedupeNamedEntries($award_entries, ['title', 'issuing_organization', 'date'])
        : [];
      if (!empty($consolidated['demonstration_projects']) && is_array($consolidated['demonstration_projects'])) {
        $consolidated['demonstration_projects'] = $this->dedupeNamedEntries($consolidated['demonstration_projects'], ['name', 'url']);
      }
      $consolidated['last_updated'] = date('c');
      $consolidated['resume_count'] = count($results);
      
      // Build extraction_metadata with source_files for status tracking
      $consolidated['extraction_metadata'] = [
        'source_files' => array_unique($source_files),
        'consolidated_at' => date('c'),
        'resume_count' => count($results),
      ];

      // Save consolidated profile
      $connection->update('jobhunter_job_seeker')
        ->fields(['consolidated_profile_json' => json_encode($consolidated)])
        ->condition('uid', $uid)
        ->execute();

      // Update projection columns for query-friendly access.
      try {
        /** @var \Drupal\job_hunter\Service\JobSeekerService $job_seeker_service */
        $job_seeker_service = \Drupal::service('job_hunter.job_seeker_service');
        $job_seeker_service->updateProfileProjections((int) $uid, $consolidated);
      }
      catch (\Exception $e) {
        $logger->warning('⚠️ Queue: Consolidation projections update failed for user @uid: @error', [
          '@uid' => $uid,
          '@error' => $e->getMessage(),
        ]);
      }

      $logger->info('✅ Queue: Consolidated @count resumes for user @uid (@exp experiences)', [
        '@count' => count($results),
        '@uid' => $uid,
        '@exp' => count($unique_experiences),
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Queue: Failed to consolidate all parsed data: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * Build chunk parsing prompt (handles both core and experience).
   */
  private function buildChunkPrompt($chunk_text, $filename) {
    $timestamp = date('c');
    $char_count = strlen($chunk_text);
    
    return <<<PROMPT
You are a professional resume parser. Extract ALL information from this resume chunk.

IMPORTANT: This is part of a larger resume that has been split into chunks. Extract whatever information is present in this chunk. Some fields may not be present - return null or empty arrays for missing data.

REQUIREMENTS:
1. Preserve ALL information - do not summarize
2. Use YYYY-MM format for dates
3. Use null for missing optional fields
4. Return ONLY valid JSON conforming to RFC 8259 - NO markdown code blocks, USE proper JSON escaping (\n for newlines, \" for quotes)
5. For professional experience: Extract complete job entries even if split across chunks
6. For each professional_experience entry, always include:
  - highlights: concise 1-2 line summary (string)
  - key_achievements: array of concrete achievement lines
  - technologies: deduplicated array of tools/technologies used
7. For responsibility_categories, create at least one category when bullets exist; if uncategorized use "General Responsibilities"
8. For every achievement object, include "text" and include arrays for "metrics", "technologies", and "keywords" (empty arrays when none)
9. For education entries, include institution and degree for every item; include end_date whenever graduation/completion timing appears in the text

JSON SCHEMA:
{
  "schema_version": "1.0",
  "extraction_metadata": {
    "source_filename": "{$filename}",
    "extracted_at": "{$timestamp}",
    "character_count": {$char_count}
  },
  "contact_info": {
    "full_name": "First Last",
    "credentials": ["MBA", "PMP"],
    "headline": "Professional title/tagline",
    "location": {"city": "City", "state": "ST"},
    "phone": "(xxx) xxx-xxxx",
    "email": "email@example.com",
    "websites": [{"type": "linkedin|github|personal", "url": "https://..."}]
  },
  "executive_profile": {
    "summary": "Full executive summary text",
    "industry_focus": ["industry1", "industry2"],
    "key_metrics": [{"metric": "name", "value": "XXM+", "context": "explanation"}]
  },
  "strategic_differentiators": [
    {"title": "Title", "description": "Description"}
  ],
  "consulting_practice": {
    "company": "Company Name",
    "title": "Title",
    "start_date": "YYYY-MM",
    "end_date": null,
    "description": "Description",
    "notable_engagements": [{"client": "Client", "role": "Role", "description": "Desc"}]
  },
  "early_career": {
    "period": "YYYY-YYYY",
    "summary": "Summary text",
    "positions": [{"company": "Company", "duration": "X years", "focus": "Role desc"}]
  },
  "education": [
    {"institution": "University", "degree": "Degree Name", "abbreviation": "MBA", "field": "Field", "end_date": "YYYY-MM"}
  ],
  "technical_expertise": {
    "categories": [{"name": "Category", "skills": ["skill1", "skill2"]}]
  },
  "leadership_philosophy": {
    "statement": "Philosophy text",
    "key_themes": ["theme1", "theme2"]
  },
  "demonstration_projects": [
    {"name": "Project", "url": "https://...", "technologies": ["tech1"], "description": "Desc"}
  ],
  "professional_experience": [
    {
      "company": "Company Name",
      "title": "Job Title",
      "employment_type": "direct|consulting",
      "via_company": null,
      "start_date": "YYYY-MM",
      "end_date": "YYYY-MM or null if current",
      "location": "City, ST",
      "company_context": "Brief company description if provided",
      "highlights": "1-2 line impact summary for this role",
      "key_achievements": ["Achievement line 1", "Achievement line 2"],
      "technologies": ["Python", "AWS"],
      "responsibility_categories": [
        {
          "category": "Category Name",
          "achievements": [
            {
              "text": "Full bullet point text",
              "metrics": ["\$3.2M revenue", "30% improvement"],
              "technologies": ["Python", "AWS"],
              "keywords": ["AI strategy", "data governance"]
            }
          ]
        }
      ]
    }
  ]
}

RESUME CHUNK:
---
{$chunk_text}
---

Return the JSON object with whatever sections are present in this chunk. Use null or empty arrays for missing sections.
PROMPT;
  }

  /**
   * De-duplicate and enrich education entries across parsed resumes.
   */
  private function dedupeEducationEntries(array $educations): array {
    $unique = [];
    $index_by_key = [];

    foreach ($educations as $education) {
      if (!is_array($education)) {
        continue;
      }

      if ($this->isLowQualityEducationEntry($education)) {
        continue;
      }

      $fuzzy_idx = $this->findMatchingEducationEntryIndex($unique, $education);
      if ($fuzzy_idx !== NULL) {
        $unique[$fuzzy_idx] = $this->mergeEducationEntries($unique[$fuzzy_idx], $education);
        continue;
      }

      $institution = $this->normalizeExperienceToken((string) ($education['institution'] ?? ''));
      $degree = $this->normalizeExperienceToken((string) ($education['degree'] ?? ''));
      $end_date = $this->normalizeExperienceToken((string) ($education['end_date'] ?? ''));
      $key = $institution . '|' . $degree . '|' . $end_date;

      if (!isset($index_by_key[$key])) {
        $index_by_key[$key] = count($unique);
        $unique[] = $education;
        continue;
      }

      $idx = $index_by_key[$key];
      $unique[$idx] = $this->mergeEducationEntries($unique[$idx], $education);
    }

    // Drop weak degree-only rows when a stronger institution-backed row exists
    // for the same degree identity token.
    $strong_tokens = [];
    foreach ($unique as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $institution = trim((string) ($entry['institution'] ?? ''));
      if ($institution === '') {
        continue;
      }
      foreach ($this->extractEducationIdentityTokens($entry) as $token) {
        $strong_tokens[$token] = TRUE;
      }
    }

    $filtered = [];
    foreach ($unique as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $institution = trim((string) ($entry['institution'] ?? ''));
      if ($institution === '') {
        $drop = FALSE;
        foreach ($this->extractEducationIdentityTokens($entry) as $token) {
          if (isset($strong_tokens[$token])) {
            $drop = TRUE;
            break;
          }
        }
        if ($drop) {
          continue;
        }
      }
      $filtered[] = $entry;
    }

    return array_values($filtered);
  }

  /**
   * Find a likely matching education entry in an existing set.
   */
  private function findMatchingEducationEntryIndex(array $existing_entries, array $candidate): ?int {
    foreach ($existing_entries as $idx => $existing) {
      if (!is_array($existing)) {
        continue;
      }
      if ($this->areEducationEntriesEquivalent($existing, $candidate)) {
        return $idx;
      }
    }

    return NULL;
  }

  /**
   * Determine if two education entries represent the same item.
   */
  private function areEducationEntriesEquivalent(array $first, array $second): bool {
    $first_institution = $this->normalizeInstitutionIdentity((string) ($first['institution'] ?? ''));
    $second_institution = $this->normalizeInstitutionIdentity((string) ($second['institution'] ?? ''));
    if ($first_institution === '' || $second_institution === '') {
      return FALSE;
    }

    if ($first_institution !== $second_institution
      && !str_contains($first_institution, $second_institution)
      && !str_contains($second_institution, $first_institution)) {
      return FALSE;
    }

    $first_end_date = $this->normalizeExperienceToken((string) ($first['end_date'] ?? ''));
    $second_end_date = $this->normalizeExperienceToken((string) ($second['end_date'] ?? ''));
    if ($first_end_date !== '' && $second_end_date !== '' && $first_end_date !== $second_end_date) {
      return FALSE;
    }

    $first_tokens = $this->extractEducationIdentityTokens($first);
    $second_tokens = $this->extractEducationIdentityTokens($second);
    if (!empty($first_tokens) && !empty($second_tokens) && empty(array_intersect($first_tokens, $second_tokens))) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Normalize institution text for robust matching.
   */
  private function normalizeInstitutionIdentity(string $institution): string {
    $normalized = $this->normalizeExperienceToken($institution);
    if ($normalized === '') {
      return '';
    }

    $parts = explode(',', $normalized);
    return trim((string) ($parts[0] ?? $normalized));
  }

  /**
   * Merge sparse education fields from a duplicate entry.
   */
  private function mergeEducationEntries(array $existing, array $incoming): array {
    $merged = $existing;

    foreach (['institution', 'degree', 'abbreviation', 'field', 'location', 'start_date', 'end_date'] as $field_name) {
      if ((empty($merged[$field_name]) || $merged[$field_name] === NULL) && !empty($incoming[$field_name])) {
        $merged[$field_name] = $incoming[$field_name];
      }
    }

    return $merged;
  }

  /**
   * Build identity tokens for matching education entries across weak/strong rows.
   */
  private function extractEducationIdentityTokens(array $education): array {
    $tokens = [];
    $degree = trim((string) ($education['degree'] ?? ''));
    $abbreviation = trim((string) ($education['abbreviation'] ?? ''));

    if ($degree !== '') {
      $normalized_degree = $this->normalizeExperienceToken($degree);
      if ($normalized_degree !== '') {
        $tokens[] = $normalized_degree;
        foreach (preg_split('/\s+/', $normalized_degree) ?: [] as $part) {
          $part = trim((string) $part);
          if ($part !== '' && strlen($part) >= 2) {
            $tokens[] = $part;
          }
        }

        // Common degree aliases to align weak/strong parsed variants.
        if (str_contains($normalized_degree, 'bachelor') && str_contains($normalized_degree, 'science')) {
          $tokens[] = 'bs';
        }
        if (str_contains($normalized_degree, 'master') && str_contains($normalized_degree, 'business') && str_contains($normalized_degree, 'administration')) {
          $tokens[] = 'mba';
        }
        if (str_contains($normalized_degree, 'master') && str_contains($normalized_degree, 'science')) {
          $tokens[] = 'ms';
        }
        if (str_contains($normalized_degree, 'doctor') || str_contains($normalized_degree, 'phd')) {
          $tokens[] = 'phd';
        }
      }
    }

    if ($abbreviation !== '') {
      $abbr = $this->normalizeExperienceToken($abbreviation);
      if ($abbr !== '') {
        $tokens[] = $abbr;
      }
    }

    return array_values(array_unique($tokens));
  }

  /**
   * Remove low-quality education entries that lack essential identity fields.
   */
  private function filterEducationEntries(array $educations): array {
    $filtered = [];
    foreach ($educations as $education) {
      if (!is_array($education)) {
        continue;
      }
      if ($this->isLowQualityEducationEntry($education)) {
        continue;
      }
      $filtered[] = $education;
    }

    return $filtered;
  }

  /**
   * Determine if an education entry is too incomplete to trust.
   */
  private function isLowQualityEducationEntry(array $education): bool {
    $institution = trim((string) ($education['institution'] ?? ''));
    $degree = trim((string) ($education['degree'] ?? ''));

    // Only discard rows with neither institution nor degree.
    // Keep degree-only rows as a fallback until parser quality improves.
    return $institution === '' && $degree === '';
  }

  /**
   * De-duplicate and enrich certifications by name/issuer/date identity.
   */
  private function dedupeCertificationEntries(array $certifications): array {
    $unique = [];
    $index_by_key = [];

    foreach ($certifications as $certification) {
      if (!is_array($certification)) {
        continue;
      }

      $name = $this->normalizeExperienceToken((string) ($certification['name'] ?? ''));
      $issuer = $this->normalizeExperienceToken((string) ($certification['issuing_organization'] ?? ''));
      $date = $this->normalizeExperienceToken((string) ($certification['date'] ?? ''));
      if ($name === '') {
        continue;
      }

      $key = $name . '|' . $issuer . '|' . $date;
      if (isset($index_by_key[$key])) {
        $idx = $index_by_key[$key];
        $unique[$idx] = $this->mergeCertificationEntries($unique[$idx], $certification);
        continue;
      }

      $fuzzy_idx = $this->findMatchingCertificationEntryIndex($unique, $certification);
      if ($fuzzy_idx !== NULL) {
        $unique[$fuzzy_idx] = $this->mergeCertificationEntries($unique[$fuzzy_idx], $certification);
        continue;
      }

      $index_by_key[$key] = count($unique);
      $unique[] = $certification;
    }

    return array_values($unique);
  }

  /**
   * Find likely certification duplicate by normalized identity.
   */
  private function findMatchingCertificationEntryIndex(array $existing_entries, array $candidate): ?int {
    $candidate_name = $this->normalizeExperienceToken((string) ($candidate['name'] ?? ''));
    $candidate_issuer = $this->normalizeExperienceToken((string) ($candidate['issuing_organization'] ?? ''));
    $candidate_date = $this->normalizeExperienceToken((string) ($candidate['date'] ?? ''));

    foreach ($existing_entries as $idx => $existing) {
      if (!is_array($existing)) {
        continue;
      }

      $existing_name = $this->normalizeExperienceToken((string) ($existing['name'] ?? ''));
      if ($candidate_name === '' || $existing_name === '' || $candidate_name !== $existing_name) {
        continue;
      }

      $existing_issuer = $this->normalizeExperienceToken((string) ($existing['issuing_organization'] ?? ''));
      if ($candidate_issuer !== '' && $existing_issuer !== '' && $candidate_issuer !== $existing_issuer) {
        continue;
      }

      $existing_date = $this->normalizeExperienceToken((string) ($existing['date'] ?? ''));
      if ($candidate_date !== '' && $existing_date !== '' && $candidate_date !== $existing_date) {
        continue;
      }

      return $idx;
    }

    return NULL;
  }

  /**
   * Merge sparse certification fields from duplicate entries.
   */
  private function mergeCertificationEntries(array $existing, array $incoming): array {
    $merged = $existing;
    foreach (['name', 'issuing_organization', 'date', 'expiration', 'verification_url', 'credential_id'] as $field_name) {
      if ((empty($merged[$field_name]) || $merged[$field_name] === NULL) && !empty($incoming[$field_name])) {
        $merged[$field_name] = $incoming[$field_name];
      }
    }
    return $merged;
  }

  /**
   * De-duplicate language entries and preserve strongest proficiency value.
   */
  private function dedupeLanguageEntries(array $languages): array {
    $unique = [];

    foreach ($languages as $language_entry) {
      if (!is_array($language_entry)) {
        continue;
      }

      $language = trim((string) ($language_entry['language'] ?? ''));
      if ($language === '') {
        continue;
      }

      $key = $this->normalizeExperienceToken($language);
      if (!isset($unique[$key])) {
        $unique[$key] = $language_entry;
        continue;
      }

      $existing_rank = $this->languageProficiencyRank((string) ($unique[$key]['proficiency'] ?? ''));
      $incoming_rank = $this->languageProficiencyRank((string) ($language_entry['proficiency'] ?? ''));
      if ($incoming_rank > $existing_rank) {
        $unique[$key]['proficiency'] = $language_entry['proficiency'];
      }
    }

    return array_values($unique);
  }

  /**
   * Rank language proficiency for deterministic duplicate resolution.
   */
  private function languageProficiencyRank(string $proficiency): int {
    $normalized = $this->normalizeExperienceToken($proficiency);
    $map = [
      'native' => 5,
      'bilingual' => 5,
      'fluent' => 4,
      'professional' => 3,
      'intermediate' => 2,
      'elementary' => 1,
      'basic' => 1,
    ];

    return $map[$normalized] ?? 0;
  }

  /**
   * Normalize technical expertise categories and de-duplicate skills.
   */
  private function normalizeTechnicalExpertise(array $technical_expertise): array {
    $categories = $technical_expertise['categories'] ?? [];
    if (!is_array($categories)) {
      return $technical_expertise;
    }

    $by_name = [];
    foreach ($categories as $category) {
      if (!is_array($category)) {
        continue;
      }

      $name = trim((string) ($category['name'] ?? ''));
      $key = $this->normalizeExperienceToken($name);
      if ($key === '') {
        continue;
      }

      if (!isset($by_name[$key])) {
        $by_name[$key] = [
          'name' => $name,
          'skills' => [],
        ];
      }

      $existing_skills = is_array($by_name[$key]['skills']) ? $by_name[$key]['skills'] : [];
      $incoming_skills = is_array($category['skills'] ?? NULL) ? $category['skills'] : [];
      $merged_skills = array_merge($existing_skills, $incoming_skills);

      $skill_keys = [];
      $deduped_skills = [];
      foreach ($merged_skills as $skill) {
        $skill_text = trim((string) $skill);
        if ($skill_text === '') {
          continue;
        }
        $skill_key = $this->normalizeExperienceToken($skill_text);
        if (isset($skill_keys[$skill_key])) {
          continue;
        }
        $skill_keys[$skill_key] = TRUE;
        $deduped_skills[] = $skill_text;
      }

      $by_name[$key]['skills'] = $deduped_skills;
    }

    $technical_expertise['categories'] = array_values($by_name);
    return $technical_expertise;
  }

  /**
   * Add deterministic certification entries when model output is sparse.
   */
  private function augmentCertificationsFromText(array $certifications, string $all_extracted_text, array $consolidated): array {
    $seed = $certifications;
    $lower = mb_strtolower($all_extracted_text);

    $credential_strings = [];
    $credentials = $consolidated['contact_info']['credentials'] ?? [];
    if (is_array($credentials)) {
      foreach ($credentials as $credential) {
        $credential_text = trim((string) $credential);
        if ($credential_text !== '') {
          $credential_strings[] = $credential_text;
        }
      }
    }

    $known_cert_map = [
      'pmp' => ['name' => 'Project Management Professional (PMP)', 'issuing_organization' => 'Project Management Institute'],
      'csm' => ['name' => 'Certified ScrumMaster (CSM)', 'issuing_organization' => 'Scrum Alliance'],
      'cissp' => ['name' => 'Certified Information Systems Security Professional (CISSP)', 'issuing_organization' => '(ISC)²'],
      'aws certified solutions architect' => ['name' => 'AWS Certified Solutions Architect', 'issuing_organization' => 'Amazon Web Services'],
      'azure administrator associate' => ['name' => 'Microsoft Certified: Azure Administrator Associate', 'issuing_organization' => 'Microsoft'],
      'gcp professional' => ['name' => 'Google Cloud Professional Certification', 'issuing_organization' => 'Google Cloud'],
    ];

    foreach ($known_cert_map as $needle => $cert_template) {
      $in_text = str_contains($lower, $needle);
      $in_credentials = FALSE;
      foreach ($credential_strings as $credential) {
        if (str_contains($this->normalizeExperienceToken($credential), $this->normalizeExperienceToken($needle))) {
          $in_credentials = TRUE;
          break;
        }
      }

      if ($in_text || $in_credentials) {
        $seed[] = $cert_template;
      }
    }

    return $this->dedupeCertificationEntries($seed);
  }

  /**
   * Add deterministic language entries when model output is sparse.
   */
  private function augmentLanguagesFromText(array $languages, string $all_extracted_text): array {
    $seed = $languages;
    $text = mb_strtolower($all_extracted_text);

    $language_map = [
      'english' => 'English',
      'spanish' => 'Spanish',
      'french' => 'French',
      'german' => 'German',
      'portuguese' => 'Portuguese',
      'mandarin' => 'Mandarin',
      'chinese' => 'Chinese',
      'hindi' => 'Hindi',
      'arabic' => 'Arabic',
      'japanese' => 'Japanese',
      'korean' => 'Korean',
      'russian' => 'Russian',
      'italian' => 'Italian',
    ];

    foreach ($language_map as $needle => $label) {
      if (!preg_match('/\\b' . preg_quote($needle, '/') . '\\b/u', $text)) {
        continue;
      }

      $proficiency = NULL;
      if (preg_match('/\\b(native|fluent|professional|elementary|basic|bilingual)\\b.{0,24}\\b' . preg_quote($needle, '/') . '\\b/u', $text, $m)
        || preg_match('/\\b' . preg_quote($needle, '/') . '\\b.{0,24}\\b(native|fluent|professional|elementary|basic|bilingual)\\b/u', $text, $m)) {
        $proficiency = ucfirst(strtolower((string) $m[1]));
      }

      $seed[] = [
        'language' => $label,
        'proficiency' => $proficiency,
      ];
    }

    return $this->dedupeLanguageEntries($seed);
  }

  /**
   * Generic object-list dedupe by normalized composite key.
   */
  private function dedupeNamedEntries(array $items, array $key_fields): array {
    $unique = [];
    $index_by_key = [];

    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $parts = [];
      foreach ($key_fields as $field) {
        $parts[] = $this->normalizeExperienceToken((string) ($item[$field] ?? ''));
      }
      $non_empty = array_filter($parts, static fn($value) => $value !== '');
      if (empty($non_empty)) {
        continue;
      }

      $key = implode('|', $parts);
      if (!isset($index_by_key[$key])) {
        $index_by_key[$key] = count($unique);
        $unique[] = $item;
        continue;
      }

      $idx = $index_by_key[$key];
      foreach ($item as $field => $value) {
        if ((empty($unique[$idx][$field]) || $unique[$idx][$field] === NULL) && !empty($value)) {
          $unique[$idx][$field] = $value;
        }
      }
    }

    return array_values($unique);
  }

  /**
   * Add deterministic publication entries from extracted text headings.
   */
  private function augmentPublicationsFromText(array $publications, string $all_extracted_text): array {
    $seed = $publications;
    $lines = $this->extractSectionLinesFromText(
      $all_extracted_text,
      ['publications?', 'articles?', 'papers?'],
      ['patents?', 'awards?', 'honors?', 'certifications?', 'languages?', 'education', 'experience']
    );

    foreach ($lines as $line) {
      $title = trim((string) preg_replace('/\s*[-|].*$/', '', $line));
      if ($title === '' || mb_strlen($title) < 6) {
        continue;
      }

      $entry = [
        'title' => $title,
        'publication_venue' => NULL,
        'date' => NULL,
      ];

      if (preg_match('/\b(19|20)\d{2}(?:[-\/]\d{2})?\b/', $line, $m)) {
        $entry['date'] = str_replace('/', '-', (string) $m[0]);
      }

      if (preg_match('/[-|]\s*([^|\-]{3,})$/', $line, $m)) {
        $venue = trim((string) $m[1]);
        if ($venue !== '' && !preg_match('/\b(19|20)\d{2}\b/', $venue)) {
          $entry['publication_venue'] = $venue;
        }
      }

      $seed[] = $entry;
    }

    return $this->dedupeNamedEntries($seed, ['title', 'publication_venue', 'date']);
  }

  /**
   * Add deterministic patent entries from extracted text headings.
   */
  private function augmentPatentsFromText(array $patents, string $all_extracted_text): array {
    $seed = $patents;
    $lines = $this->extractSectionLinesFromText(
      $all_extracted_text,
      ['patents?'],
      ['awards?', 'honors?', 'certifications?', 'languages?', 'education', 'experience', 'publications?']
    );

    foreach ($lines as $line) {
      $entry = [
        'title' => NULL,
        'patent_number' => NULL,
        'status' => NULL,
        'filing_date' => NULL,
      ];

      if (preg_match('/\b(?:US\s*)?\d{1,2}[,\d]{4,}\b/', $line, $m)) {
        $entry['patent_number'] = trim((string) $m[0]);
      }

      if (preg_match('/\b(granted|pending|abandoned)\b/i', $line, $m)) {
        $entry['status'] = strtolower((string) $m[1]);
      }

      if (preg_match('/\b(19|20)\d{2}(?:[-\/]\d{2})?\b/', $line, $m)) {
        $entry['filing_date'] = str_replace('/', '-', (string) $m[0]);
      }

      $title = trim((string) preg_replace('/\s*[-|].*$/', '', $line));
      if ($title !== '' && !preg_match('/^patents?$/i', $title)) {
        $entry['title'] = $title;
      }

      if (!empty($entry['title']) || !empty($entry['patent_number'])) {
        $seed[] = $entry;
      }
    }

    return $this->dedupeNamedEntries($seed, ['patent_number', 'title', 'filing_date']);
  }

  /**
   * Add deterministic award/honor entries from extracted text headings.
   */
  private function augmentAwardsFromText(array $awards, string $all_extracted_text): array {
    $seed = $awards;
    $lines = $this->extractSectionLinesFromText(
      $all_extracted_text,
      ['awards?', 'honors?', 'recognitions?'],
      ['patents?', 'certifications?', 'languages?', 'education', 'experience', 'publications?']
    );

    foreach ($lines as $line) {
      $title = trim((string) preg_replace('/\s*[-|].*$/', '', $line));
      if ($title === '' || mb_strlen($title) < 4) {
        continue;
      }

      $entry = [
        'title' => $title,
        'issuing_organization' => NULL,
        'date' => NULL,
        'description' => NULL,
      ];

      if (preg_match('/[-|]\s*([^|\-]{3,})$/', $line, $m)) {
        $issuer = trim((string) $m[1]);
        if ($issuer !== '' && !preg_match('/\b(19|20)\d{2}\b/', $issuer)) {
          $entry['issuing_organization'] = $issuer;
        }
      }

      if (preg_match('/\b(19|20)\d{2}(?:[-\/]\d{2})?\b/', $line, $m)) {
        $entry['date'] = str_replace('/', '-', (string) $m[0]);
      }

      $seed[] = $entry;
    }

    return $this->dedupeNamedEntries($seed, ['title', 'issuing_organization', 'date']);
  }

  /**
   * Extract candidate lines from named sections in freeform resume text.
   */
  private function extractSectionLinesFromText(string $text, array $start_patterns, array $stop_patterns): array {
    if (trim($text) === '') {
      return [];
    }

    $lines = preg_split('/\R+/', $text) ?: [];
    $candidates = [];
    $active = FALSE;
    $captured = 0;

    foreach ($lines as $line) {
      $trimmed = trim((string) $line);
      if ($trimmed === '') {
        continue;
      }

      $normalized = $this->normalizeExperienceToken($trimmed);
      if ($normalized === '') {
        continue;
      }

      if (!$active && $this->matchesSectionHeading($normalized, $start_patterns)) {
        $active = TRUE;
        $captured = 0;
        continue;
      }

      if ($active && $this->matchesSectionHeading($normalized, $stop_patterns)) {
        $active = FALSE;
        continue;
      }

      if (!$active) {
        continue;
      }

      if (mb_strlen($trimmed) < 4) {
        continue;
      }

      $candidates[] = $trimmed;
      $captured++;
      if ($captured >= 20) {
        $active = FALSE;
      }
    }

    return $candidates;
  }

  /**
   * Determine if normalized line text matches any heading regex.
   */
  private function matchesSectionHeading(string $normalized_line, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if (preg_match('/\b' . $pattern . '\b/i', $normalized_line)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Build core profile prompt.
   */
  private function buildCoreProfilePrompt($extracted_text, $filename) {
    $timestamp = date('c');
    $char_count = strlen($extracted_text);

    return <<<PROMPT
You are a professional resume parser. Extract the CORE PROFILE sections from this resume into JSON.

IMPORTANT: Do NOT include professional_experience in this response. That will be extracted separately.

REQUIREMENTS:
1. Preserve ALL information - do not summarize
2. Use YYYY-MM format for dates
3. Use null for missing optional fields
4. Return ONLY valid JSON conforming to RFC 8259 - NO markdown code blocks, USE proper JSON escaping (\n for newlines, \" for quotes)
5. For education entries, include institution and degree for every item; include end_date whenever graduation/completion timing appears in the text

JSON SCHEMA:
{
  "schema_version": "1.0",
  "extraction_metadata": {
    "source_filename": "{$filename}",
    "extracted_at": "{$timestamp}",
    "character_count": {$char_count}
  },
  "contact_info": {
    "full_name": "First Last",
    "credentials": ["MBA", "PMP"],
    "headline": "Professional title/tagline",
    "location": {"city": "City", "state": "ST"},
    "phone": "(xxx) xxx-xxxx",
    "email": "email@example.com",
    "websites": [{"type": "linkedin|github|personal", "url": "https://..."}]
  },
  "executive_profile": {
    "summary": "Full executive summary text",
    "industry_focus": ["industry1", "industry2"],
    "key_metrics": [{"metric": "name", "value": "XXM+", "context": "explanation"}]
  },
  "strategic_differentiators": [
    {"title": "Title", "description": "Description"}
  ],
  "consulting_practice": {
    "company": "Company Name",
    "title": "Title",
    "start_date": "YYYY-MM",
    "end_date": null,
    "description": "Description",
    "notable_engagements": [{"client": "Client", "role": "Role", "description": "Desc"}]
  },
  "early_career": {
    "period": "YYYY-YYYY",
    "summary": "Summary text",
    "positions": [{"company": "Company", "duration": "X years", "focus": "Role desc"}]
  },
  "education": [
    {"institution": "University", "degree": "Degree Name", "abbreviation": "MBA", "field": "Field", "end_date": "YYYY-MM"}
  ],
  "technical_expertise": {
    "categories": [{"name": "Category", "skills": ["skill1", "skill2"]}]
  },
  "leadership_philosophy": {
    "statement": "Philosophy text",
    "key_themes": ["theme1", "theme2"]
  },
  "demonstration_projects": [
    {"name": "Project", "url": "https://...", "technologies": ["tech1"], "description": "Desc"}
  ],
  "publications": [
    {"title": "Publication Title", "authors": ["Author1", "Author2"], "publication_venue": "Journal/Conference Name", "date": "YYYY-MM", "url": "https://...", "doi": "doi:xx.xxxx/xxxxx"}
  ],
  "certifications": [
    {"name": "Certification Name", "issuing_organization": "Organization", "date": "YYYY-MM", "expiration": "YYYY-MM or null", "verification_url": "https://..."}
  ],
  "patents": [
    {"title": "Patent Title", "patent_number": "US7,123,456", "status": "granted|pending|abandoned", "filing_date": "YYYY-MM", "inventors": ["Inventor1", "Inventor2"]}
  ],
  "awards_and_honors": [
    {"title": "Award Name", "issuing_organization": "Organization", "date": "YYYY-MM", "description": "Award description"}
  ],
  "languages": [
    {"language": "Language Name", "proficiency": "native|fluent|professional|elementary"}
  ]
}

RESUME TEXT:
---
{$extracted_text}
---

Return the JSON object with all core profile sections including publications, certifications, patents, awards, and languages. Do NOT include professional_experience.
PROMPT;
  }

  /**
   * Build professional experience prompt.
   */
  private function buildProfessionalExperiencePrompt($extracted_text, $filename) {
    return <<<PROMPT
You are a professional resume parser. Extract ONLY the professional work experience from this resume.

REQUIREMENTS:
1. Preserve ALL job details and achievements - do not summarize
2. Extract metrics (dollar amounts, percentages, team sizes) into metrics arrays
3. Identify technologies mentioned in each achievement
4. Extract searchable keywords from each achievement
5. Use YYYY-MM format for dates
6. Return ONLY valid JSON conforming to RFC 8259 - USE proper JSON escaping (\n for newlines, \" for quotes)
7. For each role include highlights (string), key_achievements (array), and technologies (array)
8. If bullets are present but categories are not explicit, use one category named "General Responsibilities"
9. Every achievement object must include "text" and arrays for metrics, technologies, and keywords (use [] if none)

JSON SCHEMA:
{
  "professional_experience": [
    {
      "company": "Company Name",
      "title": "Job Title",
      "employment_type": "direct|consulting",
      "via_company": null,
      "start_date": "YYYY-MM",
      "end_date": "YYYY-MM or null if current",
      "location": "City, ST",
      "company_context": "Brief company description if provided",
      "highlights": "1-2 line impact summary for this role",
      "key_achievements": ["Achievement line 1", "Achievement line 2"],
      "technologies": ["Python", "AWS"],
      "responsibility_categories": [
        {
          "category": "Category Name",
          "achievements": [
            {
              "text": "Full bullet point text",
              "metrics": ["\$3.2M revenue", "30% improvement"],
              "technologies": ["Python", "AWS"],
              "keywords": ["AI strategy", "data governance"]
            }
          ]
        }
      ]
    }
  ]
}

RESUME TEXT:
---
{$extracted_text}
---

Return the JSON object with professional_experience array containing ALL jobs and achievements.
PROMPT;
  }

}
