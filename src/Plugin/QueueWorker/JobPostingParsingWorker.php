<?php

namespace Drupal\job_hunter\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\job_hunter\Traits\QueueWorkerBaseTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Job Posting GenAI parsing queue worker.
 *
 * Processes job posting parsing via AWS Bedrock in the background.
 *
 * @QueueWorker(
 *   id = "job_hunter_job_posting_parsing",
 *   title = @Translation("Job Posting GenAI Parsing"),
 *   cron = {"time" = 120}
 * )
 */
class JobPostingParsingWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
    $job_id = $data['job_id'];
    $raw_posting_text = $data['raw_posting_text'] ?? '';

    $logger = \Drupal::logger('job_hunter');
    $connection = \Drupal::database();

    // If the queue item has no text, fall back to DB columns (handles scraped jobs).
    if (empty($raw_posting_text)) {
      $job_row = $connection->select('jobhunter_job_requirements', 'j')
        ->fields('j', ['raw_posting_text', 'job_description'])
        ->condition('id', $job_id)
        ->execute()
        ->fetchObject();
      $raw_posting_text = ($job_row->raw_posting_text ?? '') ?: ($job_row->job_description ?? '');
    }

    if (empty($raw_posting_text)) {
      $logger->error('❌ Job @id has no text to parse — skipping.', ['@id' => $job_id]);
      $connection->update('jobhunter_job_requirements')
        ->fields(['ai_extraction_status' => 'failed', 'updated' => time()])
        ->condition('id', $job_id)
        ->execute();
      return;
    }

    // Get job preview for logging
    $text_preview = substr($raw_posting_text, 0, 100);
    $text_preview = preg_replace('/\s+/', ' ', $text_preview);

    $logger->info('🔄 Queue: Starting GenAI parsing for job @id. Preview: "@preview..."', [
      '@id' => $job_id,
      '@preview' => $text_preview,
    ]);

    try {
      // Update status to processing
      $connection->update('jobhunter_job_requirements')
        ->fields(['ai_extraction_status' => 'processing'])
        ->condition('id', $job_id)
        ->execute();

      // Parse the job posting
      $parsed_data = $this->parseJobPosting($raw_posting_text, $job_id);

      if (!$parsed_data) {
        // Mark this specific job as failed so the queue can continue with other items.
        // SuspendQueueException would block ALL pending jobs — wrong behavior here.
        $connection->update('jobhunter_job_requirements')
          ->fields(['ai_extraction_status' => 'failed', 'updated' => time()])
          ->condition('id', $job_id)
          ->execute();
        \Drupal::logger('job_hunter')->error(
          '❌ Job posting parsing returned no data for @id — marked failed. Check AIApiService logs.',
          ['@id' => $job_id]
        );
        return;
      }

      // Update job record with extracted data
      $update_fields = [
        'ai_extraction_status' => 'completed',
        'updated' => time(),
      ];

      if (!empty($parsed_data['extracted_json'])) {
        $update_fields['extracted_json'] = json_encode($parsed_data['extracted_json']);
        
        // Also populate individual fields from extracted data
        $extracted = $parsed_data['extracted_json'];
        if (!empty($extracted['job_title'])) {
          $update_fields['job_title'] = $extracted['job_title'];
        }
        if (!empty($extracted['company_name'])) {
          // Look up or create company
          $company_id = $this->findOrCreateCompany($extracted['company_name'], $extracted, $connection);
          if ($company_id) {
            $update_fields['company_id'] = $company_id;
          }
        }
        if (!empty($extracted['job_description'])) {
          $update_fields['job_description'] = $extracted['job_description'];
        }
        if (!empty($extracted['requirements'])) {
          $update_fields['requirements'] = is_array($extracted['requirements']) 
            ? implode("\n", $extracted['requirements']) 
            : $extracted['requirements'];
        }
        if (!empty($extracted['salary_range'])) {
          $update_fields['salary_range'] = $extracted['salary_range'];
        }
        if (!empty($extracted['location'])) {
          $update_fields['location'] = is_array($extracted['location']) 
            ? implode(', ', array_filter($extracted['location']))
            : $extracted['location'];
        }
        if (!empty($extracted['remote_option'])) {
          $update_fields['remote_option'] = $extracted['remote_option'];
        }
        if (!empty($extracted['employment_type'])) {
          $update_fields['employment_type'] = $extracted['employment_type'];
        }
        if (!empty($extracted['job_url'])) {
          $update_fields['job_url'] = $extracted['job_url'];
        }
      }

      if (!empty($parsed_data['skills_json'])) {
        $update_fields['skills_required_json'] = json_encode($parsed_data['skills_json']);
      }

      if (!empty($parsed_data['keywords_json'])) {
        $update_fields['keywords_json'] = json_encode($parsed_data['keywords_json']);
      }

      $connection->update('jobhunter_job_requirements')
        ->fields($update_fields)
        ->condition('id', $job_id)
        ->execute();

      // Check for duplicate jobs after parsing is complete
      if (!empty($parsed_data['extracted_json'])) {
        $duplicates = $this->findDuplicateJobs($job_id, $parsed_data['extracted_json'], $connection);
        if (!empty($duplicates)) {
          $connection->update('jobhunter_job_requirements')
            ->fields(['potential_duplicates_json' => json_encode($duplicates)])
            ->condition('id', $job_id)
            ->execute();
          
          $logger->info('📋 Found @count potential duplicate(s) for job @id', [
            '@count' => count($duplicates),
            '@id' => $job_id,
          ]);
        }
      }

      $logger->info('✅ Queue: Completed parsing job posting @id', ['@id' => $job_id]);

    } catch (\Exception $e) {
      $logger->error('❌ Queue: Job posting parsing failed for @id: @error', [
        '@id' => $job_id,
        '@error' => $e->getMessage(),
      ]);

      $connection->update('jobhunter_job_requirements')
        ->fields([
          'ai_extraction_status' => 'failed',
          'updated' => time(),
        ])
        ->condition('id', $job_id)
        ->execute();
    }
  }

  /**
   * Parse job posting using AWS Bedrock.
   */
  private function parseJobPosting($raw_posting_text, $job_id) {
    $logger = \Drupal::logger('job_hunter');

    // CALL 1: Extract job details
    $logger->info('📄 Queue Job: Call 1/2 - Extracting job details for job @id via AIApiService', ['@id' => $job_id]);
    $details_prompt = $this->buildJobDetailsPrompt($raw_posting_text);
    $extracted_json = $this->callBedrockAndParse($details_prompt, 'job_details', $job_id);

    if (!$extracted_json) {
      throw new \Exception('Failed to extract job details');
    }

    // CALL 2: Extract skills and keywords
    $company = $extracted_json['company_name'] ?? 'Unknown Company';
    $job_title = $extracted_json['job_title'] ?? 'Unknown Position';
    $logger->info('💼 Queue Job: Call 2/2 - Extracting skills for "@title" at @company (job @id) via AIApiService', [
      '@title' => $job_title,
      '@company' => $company,
      '@id' => $job_id,
    ]);
    $skills_prompt = $this->buildSkillsKeywordsPrompt($raw_posting_text);
    $skills_data = $this->callBedrockAndParse($skills_prompt, 'skills_keywords', $job_id);

    if (!$skills_data) {
      throw new \Exception('Failed to extract skills and keywords');
    }

    return [
      'extracted_json' => $extracted_json,
      'skills_json' => $skills_data['skills'] ?? [],
      'keywords_json' => $skills_data['keywords'] ?? [],
    ];
  }

  /**
   * Build prompt for job details extraction.
   */
  private function buildJobDetailsPrompt($raw_text) {
    return <<<PROMPT
You are a professional job posting parser. Extract structured data from this job posting into JSON.

REQUIREMENTS:
1. Extract all key information accurately
2. Use null for missing optional fields
3. Return ONLY valid JSON conforming to RFC 8259 - NO markdown code blocks, USE proper JSON escaping (\n for newlines, \" for quotes)

JSON SCHEMA:
{
  "job_title": "Exact job title",
  "company_name": "Company name",
  "company_description": "Brief company description if provided",
  "job_description": "Full job description/summary",
  "requirements": ["requirement 1", "requirement 2"],
  "responsibilities": ["responsibility 1", "responsibility 2"],
  "qualifications": {
    "required": ["required qualification 1"],
    "preferred": ["preferred qualification 1"]
  },
  "salary_range": "Salary range if provided",
  "location": {
    "city": "City",
    "state": "State",
    "country": "Country",
    "full_location": "Full location string"
  },
  "remote_option": "remote|hybrid|onsite|flexible",
  "employment_type": "full-time|part-time|contract|internship",
  "experience_years": "X+ years or range",
  "industry": "Industry/sector",
  "job_url": "URL if present in posting",
  "benefits": ["benefit 1", "benefit 2"],
  "application_deadline": "Deadline if mentioned",
  "visa_sponsorship": true|false|null
}

JOB POSTING TEXT:
---
{$raw_text}
---

Return the JSON object with extracted job details.
PROMPT;
  }

  /**
   * Build prompt for skills and keywords extraction.
   */
  private function buildSkillsKeywordsPrompt($raw_text) {
    return <<<PROMPT
You are a professional job posting analyzer. Extract skills and keywords for resume optimization.

REQUIREMENTS:
1. Identify all technical and soft skills mentioned
2. Extract keywords that should appear in a tailored resume
3. Categorize skills by type and priority
4. Return ONLY valid JSON conforming to RFC 8259 - NO markdown code blocks, USE proper JSON escaping (\n for newlines, \" for quotes)

JSON SCHEMA:
{
  "skills": {
    "must_have": [
      {"skill": "skill name", "category": "technical|soft|domain", "mentions": 1}
    ],
    "nice_to_have": [
      {"skill": "skill name", "category": "technical|soft|domain", "mentions": 1}
    ],
    "tech_stack": ["technology 1", "technology 2"],
    "tools": ["tool 1", "tool 2"],
    "certifications": ["cert 1", "cert 2"],
    "languages": ["programming language 1"]
  },
  "keywords": {
    "high_frequency": ["keyword that appears multiple times"],
    "action_verbs": ["lead", "manage", "develop"],
    "industry_terms": ["domain-specific term"],
    "key_phrases": ["important phrase to include"],
    "ats_keywords": ["keywords for ATS optimization"]
  }
}

JOB POSTING TEXT:
---
{$raw_text}
---

Return the JSON object with skills and keywords.
PROMPT;
  }

  /**
   * Call AWS Bedrock via AIApiService and parse JSON response.
   */
  private function callBedrockAndParse($prompt, $chunk_name, $job_id = 0) {
    $logger = \Drupal::logger('job_hunter');

    // Get max_tokens from centralized ai_conversation config
    $config = $this->configFactory->get('ai_conversation.settings');
    $max_tokens = $config->get('max_tokens_job_parsing') ?? 8000;

    // Use centralized AIApiService
    $result = $this->aiApiService->invokeModelDirect(
      $prompt,
      'job_hunter',
      'job_posting_parsing',
      [
        'job_id' => $job_id,
        'chunk' => $chunk_name,
        'queue' => 'job_hunter_job_posting_parsing',
        'item_key' => "job_posting_{$job_id}_{$chunk_name}",
      ],
      [
        'max_tokens' => $max_tokens,
      ]
    );

    if (!$result['success']) {
      $logger->error('❌ Queue Job @chunk: AIApiService call failed: @error', [
        '@chunk' => $chunk_name,
        '@error' => $result['error'] ?? 'Unknown error',
      ]);
      return NULL;
    }

    $response_text = $result['response'];
    $stop_reason = $result['stop_reason'];

    // Check if response was truncated due to max_tokens limit
    if ($stop_reason === 'max_tokens') {
      $logger->error('❌ Queue Job @chunk hit max_tokens limit! Response truncated at @len chars. Increase max_tokens to fix this.', [
        '@chunk' => $chunk_name,
        '@len' => strlen($response_text),
      ]);
    }

    $logger->info('🔍 Queue Job @chunk response: @len chars, stop_reason: @reason', [
      '@chunk' => $chunk_name,
      '@len' => strlen($response_text),
      '@reason' => $stop_reason,
    ]);

    $json_text = $this->extractJsonFromResponse($response_text);

    if ($json_text) {
      $parsed_data = json_decode($json_text, TRUE);
      if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_data)) {
        return $parsed_data;
      }
    }

    return NULL;
  }

  /**
   * Find or create company from extracted data.
   */
  private function findOrCreateCompany($company_name, $extracted_data, $connection) {

    // Check if company exists
    $existing = $connection->select('jobhunter_companies', 'c')
      ->fields('c', ['id'])
      ->condition('name', $company_name)
      ->execute()
      ->fetchField();

    if ($existing) {
      return $existing;
    }

    // Create new company
    try {
      $company_id = $connection->insert('jobhunter_companies')
        ->fields([
          'name' => $company_name,
          'industry' => $extracted_data['industry'] ?? NULL,
          'created' => time(),
          'updated' => time(),
        ])
        ->execute();

      \Drupal::logger('job_hunter')->info('✅ Created company: @name (ID: @id)', [
        '@name' => $company_name ?? 'Unknown',
        '@id' => $company_id,
      ]);

      return $company_id;
    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->warning('Could not create company @name: @error', [
        '@name' => $company_name ?? 'Unknown',
        '@error' => $e->getMessage() ?? 'Unknown error',
      ]);
      return NULL;
    }
  }

  /**
   * Find potential duplicate jobs by comparing extracted JSON.
   *
   * @param int $current_job_id
   *   The current job ID to exclude from comparison.
   * @param array $extracted_json
   *   The extracted JSON data for the current job.
   * @param \Drupal\Core\Database\Connection $connection
   *   Database connection.
   *
   * @return array
   *   Array of potential duplicates with job_id and similarity_score.
   */
  private function findDuplicateJobs($current_job_id, array $extracted_json, $connection) {
    $duplicates = [];
    
    // Get key fields for comparison (handle arrays by converting to string)
    $current_title = $this->extractStringValue($extracted_json['position']['title'] ?? $extracted_json['job_title'] ?? '');
    $current_company = $this->extractStringValue($extracted_json['company']['name'] ?? $extracted_json['company_name'] ?? '');
    $current_location = $this->extractStringValue($extracted_json['position']['location_requirements'] ?? $extracted_json['location'] ?? '');
    $current_requirements = $extracted_json['requirements'] ?? [];
    $current_responsibilities = $extracted_json['responsibilities'] ?? [];
    
    if (empty($current_title) && empty($current_company)) {
      return [];
    }

    try {
    // Query other jobs with extracted_json populated (already parsed)
    $query = $connection->select('jobhunter_job_requirements', 'j')
      ->fields('j', ['id', 'job_title', 'extracted_json'])
      ->condition('id', $current_job_id, '<>')
      ->condition('extracted_json', '', '<>')
      ->isNotNull('extracted_json');
    
    $results = $query->execute()->fetchAll();
    
    $skipped_count = 0;
    foreach ($results as $job) {
      $other_json = json_decode($job->extracted_json, TRUE);
      if (!$other_json) {
        $skipped_count++;
        continue;
      }
      
      // Get comparison fields (handle arrays by converting to string)
      $other_title = $this->extractStringValue($other_json['position']['title'] ?? $other_json['job_title'] ?? '');
      $other_company = $this->extractStringValue($other_json['company']['name'] ?? $other_json['company_name'] ?? '');
      $other_location = $this->extractStringValue($other_json['position']['location_requirements'] ?? $other_json['location'] ?? '');
      $other_requirements = $other_json['requirements'] ?? [];
      $other_responsibilities = $other_json['responsibilities'] ?? [];
      
      // Calculate similarity score
      $score = $this->calculateSimilarityScore(
        $current_title, $other_title,
        $current_company, $other_company,
        $current_location, $other_location,
        $current_requirements, $other_requirements,
        $current_responsibilities, $other_responsibilities
      );
      
      // 95%+ match is a potential duplicate
      if ($score >= 95) {
        $duplicates[] = [
          'job_id' => (int) $job->id,
          'job_title' => $job->job_title ?: ($other_json['position']['title'] ?? 'Unknown'),
          'company' => $other_json['company']['name'] ?? $other_json['company_name'] ?? 'Unknown',
          'similarity_score' => $score,
          'is_exact_match' => ($score === 100),
        ];
      }
    }

    if ($skipped_count > 0) {
      \Drupal::logger('job_hunter')->warning(
        'Skipped @count job(s) with invalid JSON during duplicate detection for job @id',
        ['@count' => $skipped_count, '@id' => $current_job_id]
      );
    }

    // Sort by similarity score descending
    usort($duplicates, fn($a, $b) => $b['similarity_score'] <=> $a['similarity_score']);

    return $duplicates;

    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error(
        'Duplicate detection failed for job @id: @error',
        ['@id' => $current_job_id, '@error' => $e->getMessage()]
      );
      return [];
    }
  }

  /**
   * Calculate similarity score between two jobs.
   *
   * @return int
   *   Similarity score from 0-100.
   */
  private function calculateSimilarityScore(
    string $title1, string $title2,
    string $company1, string $company2,
    string $location1, string $location2,
    array $requirements1, array $requirements2,
    array $responsibilities1, array $responsibilities2
  ): int {
    $score = 0;
    $max_score = 0;
    
    // Title comparison (40% weight)
    $max_score += 40;
    if (!empty($title1) && !empty($title2)) {
      $title_similarity = $this->stringSimilarity($title1, $title2);
      $score += (int) ($title_similarity * 40);
    }
    
    // Company comparison (30% weight)
    $max_score += 30;
    if (!empty($company1) && !empty($company2)) {
      $company_similarity = $this->stringSimilarity($company1, $company2);
      $score += (int) ($company_similarity * 30);
    }
    
    // Location comparison (10% weight)
    $max_score += 10;
    if (!empty($location1) && !empty($location2)) {
      $location_similarity = $this->stringSimilarity($location1, $location2);
      $score += (int) ($location_similarity * 10);
    }
    
    // Requirements comparison (10% weight)
    $max_score += 10;
    if (!empty($requirements1) && !empty($requirements2)) {
      $req_similarity = $this->arrayContentSimilarity($requirements1, $requirements2);
      $score += (int) ($req_similarity * 10);
    }
    
    // Responsibilities comparison (10% weight)
    $max_score += 10;
    if (!empty($responsibilities1) && !empty($responsibilities2)) {
      $resp_similarity = $this->arrayContentSimilarity($responsibilities1, $responsibilities2);
      $score += (int) ($resp_similarity * 10);
    }
    
    return $max_score > 0 ? (int) round(($score / $max_score) * 100) : 0;
  }

  /**
   * Calculate string similarity using Levenshtein distance.
   *
   * @return float
   *   Similarity from 0.0 to 1.0.
   */
  private function stringSimilarity(string $str1, string $str2): float {
    if ($str1 === $str2) {
      return 1.0;
    }
    
    $max_len = max(strlen($str1), strlen($str2));
    if ($max_len === 0) {
      return 1.0;
    }
    
    $distance = levenshtein($str1, $str2);
    return 1.0 - ($distance / $max_len);
  }

  /**
   * Calculate array content similarity.
   *
   * @return float
   *   Similarity from 0.0 to 1.0.
   */
  private function arrayContentSimilarity(array $arr1, array $arr2): float {
    // Flatten arrays to strings for comparison
    $str1 = strtolower(implode(' ', array_map(function($item) {
      return is_array($item) ? json_encode($item) : (string) $item;
    }, $arr1)));
    
    $str2 = strtolower(implode(' ', array_map(function($item) {
      return is_array($item) ? json_encode($item) : (string) $item;
    }, $arr2)));
    
    // Use similar_text for longer content
    if (strlen($str1) > 255 || strlen($str2) > 255) {
      $percent = 0;
      similar_text($str1, $str2, $percent);
      return $percent / 100;
    }
    
    return $this->stringSimilarity($str1, $str2);
  }

  /**
   * Extract a string value from a potentially mixed type.
   *
   * Handles cases where JSON fields might be strings, arrays, or nested structures.
   *
   * @param mixed $value
   *   The value to extract a string from.
   *
   * @return string
   *   A lowercase trimmed string representation.
   */
  private function extractStringValue($value): string {
    if (is_string($value)) {
      return strtolower(trim($value));
    }
    
    if (is_array($value)) {
      // For arrays, try to get meaningful string content
      if (isset($value['value'])) {
        return strtolower(trim((string) $value['value']));
      }
      if (isset($value['name'])) {
        return strtolower(trim((string) $value['name']));
      }
      if (isset($value[0]) && is_string($value[0])) {
        // First element of simple string array
        return strtolower(trim($value[0]));
      }
      // Flatten array to string
      $flat = array_filter(array_map(function($item) {
        return is_string($item) ? $item : '';
      }, $value));
      return strtolower(trim(implode(' ', $flat)));
    }
    
    if (is_numeric($value)) {
      return (string) $value;
    }
    
    return '';
  }

}
