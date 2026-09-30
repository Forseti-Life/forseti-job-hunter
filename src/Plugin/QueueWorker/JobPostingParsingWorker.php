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
   * Links new jobs to matching records in each user's recent history.
   *
   * @var \Drupal\job_hunter\Service\RecentJobDuplicateLinker
   */
  protected $recentJobDuplicateLinker;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    $instance->aiApiService = $container->get('ai_conversation.ai_api_service');
    $instance->recentJobDuplicateLinker = $container->get('job_hunter.recent_job_duplicate_linker');
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

      // Relink exact user + title + company duplicates to the prior saved job.
      if (!empty($parsed_data['extracted_json'])) {
        $extracted = $parsed_data['extracted_json'];
        $job_title = $this->extractStringValue(
          $extracted['position']['title'] ?? $extracted['job_title'] ?? ''
        );
        $company_name = $this->extractStringValue(
          $extracted['company']['name'] ?? $extracted['company_name'] ?? ''
        );
        $links = $this->recentJobDuplicateLinker->linkToRecentMatches(
          (int) $job_id,
          $job_title,
          $company_name
        );
        if ($links) {
          $connection->update('jobhunter_job_requirements')
            ->fields(['potential_duplicates_json' => json_encode($links)])
            ->condition('id', $job_id)
            ->execute();

          $logger->info('Linked @count saved-job mapping(s) for duplicate job @id to prior records.', [
            '@count' => count($links),
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
