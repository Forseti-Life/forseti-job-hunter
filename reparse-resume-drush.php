<?php

/**
 * @file
 * Drush script to re-parse resume with production GenAI extraction.
 * 
 * Run with: drush php:script reparse-resume-drush.php
 */

use Drupal\file\Entity\File;

$uid = 1;
$connection = \Drupal::database();
$logger = \Drupal::logger('job_hunter');
$config_factory = \Drupal::configFactory();

echo "Step 1: Loading resume record...\n";

$resume = $connection->select('jobhunter_job_seeker_resumes', 'r')
  ->fields('r', ['id', 'file_id', 'extracted_text'])
  ->condition('job_seeker_id', $uid)
  ->orderBy('id', 'DESC')
  ->execute()
  ->fetchObject();

if (!$resume || empty($resume->extracted_text)) {
  throw new \Exception('No resume found with extracted text for user ID ' . $uid);
}

$text_length = strlen($resume->extracted_text);
echo "Found resume ID {$resume->id} with {$text_length} characters of extracted text\n";

echo "\nStep 2: Loading file entity...\n";

$file = File::load($resume->file_id);

if (!$file) {
  throw new \Exception('File entity not found for file ID ' . $resume->file_id);
}

$filename = $file->getFilename();
echo "Filename: {$filename}\n";

echo "\nStep 3: Preparing GenAI prompts...\n";

$timestamp = date('c');
$char_count = $text_length;

// Core profile prompt
$core_prompt = <<<PROMPT
You are a professional resume parser. Extract the CORE PROFILE sections from this resume into JSON.

IMPORTANT: Do NOT include professional_experience in this response. That will be extracted separately.

REQUIREMENTS:
1. Preserve ALL information - do not summarize
2. Extract ALL skills from technical sections - do not limit to a small number
3. Extract ALL strategic differentiators mentioned
4. Use YYYY-MM format for dates
5. Use null for missing optional fields
6. Return ONLY valid JSON conforming to RFC 8259 - NO markdown code blocks, USE proper JSON escaping (\n for newlines, \" for quotes)

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
    "categories": [{"name": "Category", "skills": ["skill1", "skill2", "skill3"]}]
  },
  "leadership_philosophy": {
    "statement": "Philosophy text",
    "key_themes": ["theme1", "theme2"]
  },
  "demonstration_projects": [
    {"name": "Project", "url": "https://...", "technologies": ["tech1"], "description": "Desc"}
  ],
  "publications": [],
  "certifications": [],
  "patents": [],
  "awards_and_honors": [],
  "languages": []
}

RESUME TEXT:
---
{$resume->extracted_text}
---

Return the JSON object with all core profile sections. Extract ALL skills and differentiators.
PROMPT;

// Professional experience prompt
$experience_prompt = <<<PROMPT
You are a professional resume parser. Extract ONLY the professional work experience from this resume.

REQUIREMENTS:
1. Preserve ALL job details and achievements - do not summarize
2. Extract ALL metrics (dollar amounts, percentages, team sizes) into metrics arrays
3. Identify ALL technologies mentioned in each achievement
4. Extract ALL searchable keywords from each achievement
5. Use YYYY-MM format for dates
6. Return ONLY valid JSON conforming to RFC 8259 - NO markdown code blocks, USE proper JSON escaping (\n for newlines, \" for quotes)

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
      "responsibility_categories": [
        {
          "category": "Category Name",
          "achievements": [
            {
              "text": "Full bullet point text",
              "metrics": ["$3.2M revenue", "30% improvement"],
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
{$resume->extracted_text}
---

Return the JSON object with professional_experience array containing ALL jobs and achievements with complete details.
PROMPT;

echo "Prompts prepared (core: " . strlen($core_prompt) . " chars, experience: " . strlen($experience_prompt) . " chars)\n";

echo "\nStep 4: Calling GenAI for core profile extraction...\n";
echo "(This may take 30-60 seconds)\n";

// Get AWS configuration
$config = $config_factory->get('ai_conversation.settings');
$aws_access_key = $config->get('aws_access_key_id') ?: getenv('AWS_ACCESS_KEY_ID');
$aws_secret_key = $config->get('aws_secret_access_key') ?: getenv('AWS_SECRET_ACCESS_KEY');
$aws_region = $config->get('aws_region') ?: getenv('AWS_DEFAULT_REGION') ?: 'us-east-1';

$sdk_config = [
  'region' => $aws_region,
  'version' => 'latest',
];

if (!empty($aws_access_key) && !empty($aws_secret_key)) {
  $sdk_config['credentials'] = [
    'key' => $aws_access_key,
    'secret' => $aws_secret_key,
  ];
}

$sdk = new \Aws\Sdk($sdk_config);
$bedrock = $sdk->createBedrockRuntime();
$model = $config->get('aws_model') ?: 'anthropic.claude-3-5-sonnet-20240620-v1:0';

echo "Using model: {$model}\n";
echo "Using region: {$aws_region}\n";

// Make Bedrock API call for core profile
$request_body = [
  'anthropic_version' => 'bedrock-2023-05-31',
  'max_tokens' => 16000,
  'temperature' => 0.0,
  'messages' => [
    [
      'role' => 'user',
      'content' => $core_prompt,
    ],
  ],
];

$start_time = microtime(true);
$response = $bedrock->invokeModel([
  'modelId' => $model,
  'contentType' => 'application/json',
  'accept' => 'application/json',
  'body' => json_encode($request_body),
]);
$elapsed = round(microtime(true) - $start_time, 2);

$response_body = json_decode($response['body']->getContents(), TRUE);
$ai_response_text = '';

if (isset($response_body['content']) && is_array($response_body['content'])) {
  foreach ($response_body['content'] as $content_item) {
    if (isset($content_item['type']) && $content_item['type'] === 'text' && isset($content_item['text'])) {
      $ai_response_text .= $content_item['text'];
    }
  }
}

if (empty($ai_response_text)) {
  throw new \Exception('GenAI returned empty response for core profile');
}

echo "Core profile response received ({$elapsed}s, " . strlen($ai_response_text) . " chars)\n";

// Parse JSON response
$core_data = json_decode($ai_response_text, TRUE);
if (!$core_data) {
  throw new \Exception('Failed to parse core profile JSON: ' . json_last_error_msg());
}

echo "Core profile parsed successfully\n";
echo "  - Strategic differentiators: " . count($core_data['strategic_differentiators'] ?? []) . "\n";
echo "  - Technical categories: " . count($core_data['technical_expertise']['categories'] ?? []) . "\n";

echo "\nStep 5: Calling GenAI for professional experience extraction...\n";
echo "(This may take 30-60 seconds)\n";

$request_body['messages'][0]['content'] = $experience_prompt;

$start_time = microtime(true);
$response = $bedrock->invokeModel([
  'modelId' => $model,
  'contentType' => 'application/json',
  'accept' => 'application/json',
  'body' => json_encode($request_body),
]);
$elapsed = round(microtime(true) - $start_time, 2);

$response_body = json_decode($response['body']->getContents(), TRUE);
$ai_response_text = '';

if (isset($response_body['content']) && is_array($response_body['content'])) {
  foreach ($response_body['content'] as $content_item) {
    if (isset($content_item['type']) && $content_item['type'] === 'text' && isset($content_item['text'])) {
      $ai_response_text .= $content_item['text'];
    }
  }
}

if (empty($ai_response_text)) {
  throw new \Exception('GenAI returned empty response for professional experience');
}

echo "Professional experience response received ({$elapsed}s, " . strlen($ai_response_text) . " chars)\n";

// Parse JSON response
$experience_data = json_decode($ai_response_text, TRUE);
if (!$experience_data) {
  throw new \Exception('Failed to parse professional experience JSON: ' . json_last_error_msg());
}

echo "Professional experience parsed successfully\n";
echo "  - Jobs extracted: " . count($experience_data['professional_experience'] ?? []) . "\n";

echo "\nStep 6: Merging core profile and professional experience...\n";

$merged_data = $core_data;
$merged_data['professional_experience'] = $experience_data['professional_experience'] ?? [];

$json_output = json_encode($merged_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
echo "Merged JSON size: " . strlen($json_output) . " characters\n";

echo "\nStep 7: Updating database with comprehensive parsed data...\n";

// Delete old dev_mock record
$connection->delete('jobhunter_resume_parsed_data')
  ->condition('uid', $uid)
  ->condition('resume_file_id', $resume->file_id)
  ->execute();

echo "Deleted old dev_mock record\n";

// Insert new production record
$timestamp_unix = time();
$connection->insert('jobhunter_resume_parsed_data')
  ->fields([
    'uid' => $uid,
    'resume_file_id' => $resume->file_id,
    'resume_path' => $file->getFileUri(),
    'parsed_data' => $json_output,
    'status' => 'completed',
    'error_message' => NULL,
    'created' => $timestamp_unix,
    'changed' => $timestamp_unix,
  ])
  ->execute();

echo "Inserted new production-parsed record\n";

echo "\nStep 8: Updating consolidated profile JSON...\n";

$profile = $connection->select('jobhunter_job_seeker', 'js')
  ->fields('js', ['id', 'consolidated_profile_json'])
  ->condition('uid', $uid)
  ->execute()
  ->fetchObject();

if (!$profile) {
  throw new \Exception('Job seeker profile not found for user ID ' . $uid);
}

// Replace consolidated JSON with new parsed data
$connection->update('jobhunter_job_seeker')
  ->fields(['consolidated_profile_json' => $json_output])
  ->condition('id', $profile->id)
  ->execute();

echo "Updated consolidated_profile_json\n";

echo "\n=================================================================\n";
echo "SUCCESS: Resume re-parsed with production GenAI extraction\n";
echo "=================================================================\n\n";

echo "Summary:\n";
echo "  - Original mock data: 2,443 characters\n";
echo "  - New parsed data: " . strlen($json_output) . " characters\n";
echo "  - Strategic differentiators: " . count($merged_data['strategic_differentiators'] ?? []) . "\n";
echo "  - Technical categories: " . count($merged_data['technical_expertise']['categories'] ?? []) . "\n";
$total_skills = 0;
foreach (($merged_data['technical_expertise']['categories'] ?? []) as $cat) {
  $total_skills += count($cat['skills'] ?? []);
}
echo "  - Total technical skills: {$total_skills}\n";
echo "  - Professional experience entries: " . count($merged_data['professional_experience'] ?? []) . "\n";
echo "  - Education entries: " . count($merged_data['education'] ?? []) . "\n";
echo "\nNext steps:\n";
echo "  1. Clear Drupal cache: ./vendor/bin/drush cr\n";
echo "  2. View the profile form at /jobhunter/profile/edit\n";
echo "  3. Verify all fields are populated with detailed data\n\n";
