<?php

/**
 * @file
 * Complete end-to-end test: Create profile, upload resume, extract, parse, verify.
 * 
 * Run with: drush php:script test-complete-profile-generation.php
 */

use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;

echo "=================================================================\n";
echo "COMPLETE PROFILE GENERATION TEST\n";
echo "=================================================================\n\n";

$uid = 1;
$connection = \Drupal::database();
$file_system = \Drupal::service('file_system');
$config_factory = \Drupal::configFactory();

try {
  // ===================================================================
  // STEP 0: Clean up prior test data for this user
  // ===================================================================
  echo "STEP 0: Cleanup Existing Data for User {$uid}\n";
  echo "-------------------------------------------------------------------\n";
  
  $existing_profiles = $connection->select('jobhunter_job_seeker', 'js')
    ->fields('js', ['id'])
    ->condition('uid', $uid)
    ->execute()
    ->fetchCol();
  
  if (!empty($existing_profiles)) {
    $resume_file_ids = $connection->select('jobhunter_job_seeker_resumes', 'jsr')
      ->fields('jsr', ['file_id'])
      ->condition('job_seeker_id', $existing_profiles, 'IN')
      ->execute()
      ->fetchCol();
    
    $connection->delete('jobhunter_resume_parsed_data')
      ->condition('uid', $uid)
      ->execute();
    
    $connection->delete('jobhunter_job_seeker_resumes')
      ->condition('job_seeker_id', $existing_profiles, 'IN')
      ->execute();
    
    $connection->delete('jobhunter_job_seeker')
      ->condition('uid', $uid)
      ->execute();
    
    if (!empty($resume_file_ids)) {
      $file_storage = \Drupal::entityTypeManager()->getStorage('file');
      foreach ($resume_file_ids as $file_id) {
        if ($file_id) {
          $file_entity = $file_storage->load($file_id);
          if ($file_entity) {
            $file_entity->delete();
          }
        }
      }
    }
    
    echo "✅ Removed existing profiles and related resume data\n\n";
  } else {
    echo "✅ No existing profile data found for user {$uid}\n\n";
  }

  // ===================================================================
  // STEP 1: Create Job Seeker Profile if not exists
  // ===================================================================
  echo "STEP 1: Create/Verify Job Seeker Profile\n";
  echo "-------------------------------------------------------------------\n";
  
  $profile = $connection->select('jobhunter_job_seeker', 'js')
    ->fields('js')
    ->condition('uid', $uid)
    ->execute()
    ->fetchObject();
  
  if (!$profile) {
    echo "Creating new job seeker profile for user {$uid}...\n";
    $profile_id = $connection->insert('jobhunter_job_seeker')
      ->fields([
        'uid' => $uid,
        'created' => time(),
        'changed' => time(),
      ])
      ->execute();
    
    echo "✅ Created profile ID: {$profile_id}\n\n";
    
    $profile = $connection->select('jobhunter_job_seeker', 'js')
      ->fields('js')
      ->condition('id', $profile_id)
      ->execute()
      ->fetchObject();
  } else {
    echo "✅ Profile already exists (ID: {$profile->id})\n\n";
  }
  
  // ===================================================================
  // STEP 2: Upload Resume File
  // ===================================================================
  echo "STEP 2: Upload Resume File\n";
  echo "-------------------------------------------------------------------\n";
  
  $source_path = '/mnt/chromeos/MyFiles/Downloads/KeithAumillerA.pdf';
  if (!file_exists($source_path)) {
    throw new \Exception("Resume file not found at: {$source_path}");
  }
  
  echo "Source file: {$source_path}\n";
  echo "File size: " . number_format(filesize($source_path)) . " bytes\n";
  
  $private_dir = '/var/private/forseti/job_hunter/resumes/' . $uid . '/originalresumes';
  $private_file_path = $private_dir . '/KeithAumillerA.pdf';
  $file_uri = 'private://forseti/job_hunter/resumes/' . $uid . '/originalresumes/KeithAumillerA.pdf';
  
  if (!file_exists($private_dir)) {
    if (!mkdir($private_dir, 0770, TRUE)) {
      throw new \Exception("Failed to create directory: {$private_dir}");
    }
  }
  
  if (!file_exists($private_file_path)) {
    if (!copy($source_path, $private_file_path)) {
      throw new \Exception("Failed to copy resume to: {$private_file_path}");
    }
  }
  
  echo "Using file URI: {$file_uri}\n";
  echo "Using physical path: {$private_file_path}\n";
  
  // Create file entity
  $file = File::create([
    'uid' => $uid,
    'filename' => 'KeithAumillerA.pdf',
    'uri' => $file_uri,
    'status' => 1,
    'filemime' => 'application/pdf',
  ]);
  $file->save();
  
  echo "✅ Created file entity (ID: {$file->id()})\n\n";
  
  // ===================================================================
  // STEP 3: Create Resume Record
  // ===================================================================
  echo "STEP 3: Create Resume Record\n";
  echo "-------------------------------------------------------------------\n";
  
  $resume_id = $connection->insert('jobhunter_job_seeker_resumes')
    ->fields([
      'job_seeker_id' => $profile->id,
      'file_id' => $file->id(),
      'created' => time(),
    ])
    ->execute();
  
  echo "✅ Created resume record (ID: {$resume_id})\n\n";
  
  // ===================================================================
  // STEP 4: Extract Text from PDF
  // ===================================================================
  echo "STEP 4: Extract Text from PDF\n";
  echo "-------------------------------------------------------------------\n";
  
  $real_path = $private_file_path;
  echo "Extracting from: {$real_path}\n";
  
  if (!file_exists($real_path)) {
    throw new \Exception("Physical file not found at: {$real_path}");
  }
  
  $output = [];
  $return_var = 0;
  exec("pdftotext -layout " . escapeshellarg($real_path) . " -", $output, $return_var);
  
  if ($return_var !== 0) {
    throw new \Exception("pdftotext failed with return code: {$return_var}");
  }
  
  $extracted_text = implode("\n", $output);
  $text_length = strlen($extracted_text);
  
  echo "Extracted {$text_length} characters\n";
  echo "Preview: " . substr($extracted_text, 0, 100) . "...\n";
  
  // Store extracted text
  $connection->update('jobhunter_job_seeker_resumes')
    ->fields(['extracted_text' => $extracted_text])
    ->condition('id', $resume_id)
    ->execute();
  
  echo "✅ Stored extracted text in database\n\n";
  
  // ===================================================================
  // STEP 5: Parse with GenAI (Production Mode)
  // ===================================================================
  echo "STEP 5: Parse Resume with GenAI\n";
  echo "-------------------------------------------------------------------\n";
  echo "This will take approximately 2 minutes (two GenAI API calls)...\n\n";
  
  $filename = $file->getFilename();
  $timestamp = date('c');
  
  // Build core profile prompt
  $core_prompt = <<<PROMPT
You are a professional resume parser. Extract the CORE PROFILE sections from this resume into JSON.

IMPORTANT: Do NOT include professional_experience in this response. That will be extracted separately.

REQUIREMENTS:
1. Preserve ALL information - do not summarize
2. Extract ALL skills from technical sections
3. Extract ALL strategic differentiators mentioned
4. Use YYYY-MM format for dates
5. Return ONLY valid JSON conforming to RFC 8259

JSON SCHEMA:
{
  "schema_version": "1.0",
  "contact_info": {"full_name": "", "email": "", "phone": "", "location": {}},
  "executive_profile": {"summary": "", "industry_focus": [], "key_metrics": []},
  "strategic_differentiators": [{"title": "", "description": ""}],
  "consulting_practice": {},
  "early_career": {"positions": []},
  "education": [],
  "technical_expertise": {"categories": [{"name": "", "skills": []}]},
  "leadership_philosophy": {},
  "demonstration_projects": []
}

RESUME TEXT:
---
{$extracted_text}
---

Return complete JSON with ALL data extracted.
PROMPT;

  // Build experience prompt
  $experience_prompt = <<<PROMPT
You are a professional resume parser. Extract ONLY the professional work experience from this resume.

REQUIREMENTS:
1. Preserve ALL job details and achievements
2. Extract ALL metrics, technologies, keywords
3. Use YYYY-MM format for dates
4. Return ONLY valid JSON conforming to RFC 8259

JSON SCHEMA:
{
  "professional_experience": [
    {
      "company": "",
      "title": "",
      "start_date": "YYYY-MM",
      "end_date": "YYYY-MM or null",
      "responsibility_categories": [
        {"category": "", "achievements": [{"text": "", "metrics": [], "technologies": [], "keywords": []}]}
      ]
    }
  ]
}

RESUME TEXT:
---
{$extracted_text}
---

Return complete JSON with ALL jobs and achievements.
PROMPT;

  // Get AWS config
  $config = $config_factory->get('ai_conversation.settings');
  $aws_access_key = $config->get('aws_access_key_id');
  $aws_secret_key = $config->get('aws_secret_access_key');
  $aws_region = $config->get('aws_region') ?: 'us-east-1';
  $model = $config->get('aws_model') ?: 'anthropic.claude-3-5-sonnet-20240620-v1:0';
  
  if (empty($aws_access_key) || empty($aws_secret_key)) {
    throw new \Exception("AWS credentials not configured");
  }
  
  echo "Using model: {$model}\n";
  echo "Region: {$aws_region}\n\n";
  
  $sdk = new \Aws\Sdk([
    'region' => $aws_region,
    'version' => 'latest',
    'credentials' => [
      'key' => $aws_access_key,
      'secret' => $aws_secret_key,
    ],
  ]);
  
  $bedrock = $sdk->createBedrockRuntime();
  
  // Call 1: Core profile
  echo "GenAI Call 1/2: Parsing core profile...\n";
  $start = microtime(true);
  
  $response = $bedrock->invokeModel([
    'modelId' => $model,
    'contentType' => 'application/json',
    'accept' => 'application/json',
    'body' => json_encode([
      'anthropic_version' => 'bedrock-2023-05-31',
      'max_tokens' => 16000,
      'temperature' => 0.0,
      'messages' => [['role' => 'user', 'content' => $core_prompt]],
    ]),
  ]);
  
  $elapsed = round(microtime(true) - $start, 2);
  $body = json_decode($response['body']->getContents(), TRUE);
  
  $ai_text = '';
  foreach ($body['content'] ?? [] as $item) {
    if ($item['type'] === 'text') {
      $ai_text .= $item['text'];
    }
  }
  
  $core_data = json_decode($ai_text, TRUE);
  if (!$core_data) {
    throw new \Exception("Failed to parse core profile JSON");
  }
  
  echo "✅ Core profile parsed ({$elapsed}s)\n";
  echo "   Strategic differentiators: " . count($core_data['strategic_differentiators'] ?? []) . "\n";
  echo "   Technical categories: " . count($core_data['technical_expertise']['categories'] ?? []) . "\n\n";
  
  // Call 2: Professional experience
  echo "GenAI Call 2/2: Parsing professional experience...\n";
  $start = microtime(true);
  
  $response = $bedrock->invokeModel([
    'modelId' => $model,
    'contentType' => 'application/json',
    'accept' => 'application/json',
    'body' => json_encode([
      'anthropic_version' => 'bedrock-2023-05-31',
      'max_tokens' => 16000,
      'temperature' => 0.0,
      'messages' => [['role' => 'user', 'content' => $experience_prompt]],
    ]),
  ]);
  
  $elapsed = round(microtime(true) - $start, 2);
  $body = json_decode($response['body']->getContents(), TRUE);
  
  $ai_text = '';
  foreach ($body['content'] ?? [] as $item) {
    if ($item['type'] === 'text') {
      $ai_text .= $item['text'];
    }
  }
  
  $experience_data = json_decode($ai_text, TRUE);
  if (!$experience_data) {
    throw new \Exception("Failed to parse experience JSON");
  }
  
  echo "✅ Professional experience parsed ({$elapsed}s)\n";
  echo "   Jobs extracted: " . count($experience_data['professional_experience'] ?? []) . "\n\n";
  
  // Merge results
  $merged_data = $core_data;
  $merged_data['professional_experience'] = $experience_data['professional_experience'] ?? [];
  
  $json_output = json_encode($merged_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  
  // ===================================================================
  // STEP 6: Store Parsed Data
  // ===================================================================
  echo "STEP 6: Store Parsed Data\n";
  echo "-------------------------------------------------------------------\n";
  
  $connection->insert('jobhunter_resume_parsed_data')
    ->fields([
      'uid' => $uid,
      'resume_file_id' => $file->id(),
      'resume_path' => $file_uri,
      'parsed_data' => $json_output,
      'status' => 'completed',
      'error_message' => NULL,
      'created' => time(),
      'changed' => time(),
    ])
    ->execute();
  
  echo "✅ Stored parsed data (" . number_format(strlen($json_output)) . " chars)\n\n";
  
  // ===================================================================
  // STEP 7: Update Consolidated Profile
  // ===================================================================
  echo "STEP 7: Update Consolidated Profile\n";
  echo "-------------------------------------------------------------------\n";
  
  $connection->update('jobhunter_job_seeker')
    ->fields(['consolidated_profile_json' => $json_output])
    ->condition('id', $profile->id)
    ->execute();
  
  echo "✅ Updated consolidated_profile_json\n\n";
  
  // ===================================================================
  // STEP 8: Verify Complete Profile
  // ===================================================================
  echo "STEP 8: Verification Summary\n";
  echo "-------------------------------------------------------------------\n";
  
  $total_skills = 0;
  foreach (($merged_data['technical_expertise']['categories'] ?? []) as $cat) {
    $total_skills += count($cat['skills'] ?? []);
  }
  
  echo "Profile Statistics:\n";
  echo "  ✅ Strategic differentiators: " . count($merged_data['strategic_differentiators'] ?? []) . "\n";
  echo "  ✅ Technical skills: {$total_skills} across " . count($merged_data['technical_expertise']['categories'] ?? []) . " categories\n";
  echo "  ✅ Professional experience: " . count($merged_data['professional_experience'] ?? []) . " jobs\n";
  echo "  ✅ Education: " . count($merged_data['education'] ?? []) . " entries\n";
  echo "  ✅ Contact info: " . ($merged_data['contact_info']['full_name'] ?? 'N/A') . "\n";
  echo "  ✅ Email: " . ($merged_data['contact_info']['email'] ?? 'N/A') . "\n\n";
  
  echo "=================================================================\n";
  echo "✅ SUCCESS: COMPLETE PROFILE GENERATION FINISHED\n";
  echo "=================================================================\n\n";
  
  echo "Next steps:\n";
  echo "  1. Clear cache: ./vendor/bin/drush cr\n";
  echo "  2. Visit: /jobhunter/profile/edit\n";
  echo "  3. Test cover letter generation button\n";
  echo "  4. Verify all sections are populated and readable\n\n";

} catch (\Exception $e) {
  echo "\n=================================================================\n";
  echo "❌ TEST FAILED\n";
  echo "=================================================================\n\n";
  echo "Error: " . $e->getMessage() . "\n\n";
  echo "Stack trace:\n";
  echo $e->getTraceAsString() . "\n\n";
  exit(1);
}
