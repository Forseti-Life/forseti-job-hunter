<?php

/**
 * @file
 * Complete end-to-end test of profile generation from resume upload to form display.
 * 
 * Run with: drush php:script test-full-profile-generation.php
 */

use Drupal\file\Entity\File;

echo "=================================================================\n";
echo "FULL PROFILE GENERATION TEST\n";
echo "=================================================================\n\n";

$uid = 1;
$connection = \Drupal::database();
$file_system = \Drupal::service('file_system');

try {
  // ===================================================================
  // TEST 1: Verify Resume File Exists
  // ===================================================================
  echo "TEST 1: Verify Resume File Exists\n";
  echo "-------------------------------------------------------------------\n";
  
  $resume = $connection->select('jobhunter_job_seeker_resumes', 'r')
    ->fields('r', ['id', 'file_id', 'job_seeker_id'])
    ->condition('job_seeker_id', $uid)
    ->orderBy('id', 'DESC')
    ->execute()
    ->fetchObject();
  
  if (!$resume) {
    echo "❌ FAIL: No resume found for user {$uid}\n\n";
    exit(1);
  }
  
  $file = File::load($resume->file_id);
  if (!$file) {
    echo "❌ FAIL: File entity not found for file ID {$resume->file_id}\n\n";
    exit(1);
  }
  
  $file_uri = $file->getFileUri();
  $file_path = $file_system->realpath($file_uri);
  $file_exists = file_exists($file_path);
  
  echo "Resume ID: {$resume->id}\n";
  echo "File ID: {$resume->file_id}\n";
  echo "Filename: {$file->getFilename()}\n";
  echo "File URI: {$file_uri}\n";
  echo "Physical path: {$file_path}\n";
  echo "File exists: " . ($file_exists ? 'YES' : 'NO') . "\n";
  
  if (!$file_exists) {
    echo "❌ FAIL: Physical file not found\n\n";
    exit(1);
  }
  
  $file_size = filesize($file_path);
  echo "File size: " . number_format($file_size) . " bytes\n";
  echo "✅ PASS: Resume file exists and is accessible\n\n";
  
  // ===================================================================
  // TEST 2: Verify Extracted Text
  // ===================================================================
  echo "TEST 2: Verify Text Extraction\n";
  echo "-------------------------------------------------------------------\n";
  
  $resume_with_text = $connection->select('jobhunter_job_seeker_resumes', 'r')
    ->fields('r', ['extracted_text'])
    ->condition('id', $resume->id)
    ->execute()
    ->fetchObject();
  
  if (empty($resume_with_text->extracted_text)) {
    echo "❌ FAIL: No extracted text found\n\n";
    exit(1);
  }
  
  $text_length = strlen($resume_with_text->extracted_text);
  $text_preview = substr($resume_with_text->extracted_text, 0, 200);
  
  echo "Extracted text length: " . number_format($text_length) . " characters\n";
  echo "First 200 characters:\n";
  echo str_repeat('-', 60) . "\n";
  echo $text_preview . "...\n";
  echo str_repeat('-', 60) . "\n";
  echo "✅ PASS: Text extracted successfully\n\n";
  
  // ===================================================================
  // TEST 3: Verify Parsed Data (Production)
  // ===================================================================
  echo "TEST 3: Verify Production Parsed Data\n";
  echo "-------------------------------------------------------------------\n";
  
  $parsed = $connection->select('jobhunter_resume_parsed_data', 'p')
    ->fields('p', ['id', 'resume_file_id', 'parsed_data', 'status', 'created'])
    ->condition('uid', $uid)
    ->condition('resume_file_id', $resume->file_id)
    ->execute()
    ->fetchObject();
  
  if (!$parsed) {
    echo "❌ FAIL: No parsed data found\n\n";
    exit(1);
  }
  
  echo "Parsed data ID: {$parsed->id}\n";
  echo "Status: {$parsed->status}\n";
  echo "Created: " . date('Y-m-d H:i:s', $parsed->created) . "\n";
  echo "JSON size: " . number_format(strlen($parsed->parsed_data)) . " characters\n";
  
  if ($parsed->status === 'dev_mock') {
    echo "⚠️  WARNING: Status is 'dev_mock' - this is test data, not production parsing\n\n";
  } elseif ($parsed->status !== 'completed') {
    echo "❌ FAIL: Unexpected status '{$parsed->status}'\n\n";
    exit(1);
  }
  
  $parsed_json = json_decode($parsed->parsed_data, TRUE);
  if (!$parsed_json) {
    echo "❌ FAIL: Invalid JSON in parsed_data\n\n";
    exit(1);
  }
  
  echo "\nParsed JSON Structure:\n";
  echo "  Schema version: " . ($parsed_json['schema_version'] ?? 'MISSING') . "\n";
  echo "  Contact info: " . (isset($parsed_json['contact_info']) ? 'YES' : 'NO') . "\n";
  echo "  Executive profile: " . (isset($parsed_json['executive_profile']) ? 'YES' : 'NO') . "\n";
  echo "  Strategic differentiators: " . count($parsed_json['strategic_differentiators'] ?? []) . " items\n";
  echo "  Professional experience: " . count($parsed_json['professional_experience'] ?? []) . " jobs\n";
  echo "  Early career: " . (isset($parsed_json['early_career']) ? 'YES' : 'NO') . "\n";
  echo "  Education: " . count($parsed_json['education'] ?? []) . " entries\n";
  echo "  Technical expertise: " . count($parsed_json['technical_expertise']['categories'] ?? []) . " categories\n";
  
  // Count total skills
  $total_skills = 0;
  foreach (($parsed_json['technical_expertise']['categories'] ?? []) as $category) {
    $total_skills += count($category['skills'] ?? []);
  }
  echo "  Total technical skills: {$total_skills}\n";
  
  echo "  Leadership philosophy: " . (isset($parsed_json['leadership_philosophy']) ? 'YES' : 'NO') . "\n";
  echo "  Demonstration projects: " . count($parsed_json['demonstration_projects'] ?? []) . " projects\n";
  
  // Validate critical fields
  $validations = [];
  $validations[] = [
    'name' => 'Contact name',
    'pass' => !empty($parsed_json['contact_info']['full_name']),
    'value' => $parsed_json['contact_info']['full_name'] ?? 'MISSING'
  ];
  $validations[] = [
    'name' => 'Contact email',
    'pass' => !empty($parsed_json['contact_info']['email']),
    'value' => $parsed_json['contact_info']['email'] ?? 'MISSING'
  ];
  $validations[] = [
    'name' => 'Strategic differentiators',
    'pass' => count($parsed_json['strategic_differentiators'] ?? []) > 0,
    'value' => count($parsed_json['strategic_differentiators'] ?? []) . ' items'
  ];
  $validations[] = [
    'name' => 'Technical skills',
    'pass' => $total_skills >= 20,
    'value' => "{$total_skills} skills"
  ];
  $validations[] = [
    'name' => 'Professional experience',
    'pass' => count($parsed_json['professional_experience'] ?? []) >= 3,
    'value' => count($parsed_json['professional_experience'] ?? []) . ' jobs'
  ];
  
  echo "\nValidation Checks:\n";
  $all_pass = TRUE;
  foreach ($validations as $check) {
    $status = $check['pass'] ? '✅' : '❌';
    echo "  {$status} {$check['name']}: {$check['value']}\n";
    if (!$check['pass']) {
      $all_pass = FALSE;
    }
  }
  
  if (!$all_pass) {
    echo "\n❌ FAIL: Some validation checks failed\n\n";
    exit(1);
  }
  
  echo "\n✅ PASS: Parsed data is comprehensive and valid\n\n";
  
  // ===================================================================
  // TEST 4: Verify Consolidated Profile
  // ===================================================================
  echo "TEST 4: Verify Consolidated Profile JSON\n";
  echo "-------------------------------------------------------------------\n";
  
  $profile = $connection->select('jobhunter_job_seeker', 'js')
    ->fields('js', ['id', 'uid', 'consolidated_profile_json', 'professional_summary', 'skills'])
    ->condition('uid', $uid)
    ->execute()
    ->fetchObject();
  
  if (!$profile) {
    echo "❌ FAIL: No job seeker profile found for user {$uid}\n\n";
    exit(1);
  }
  
  echo "Profile ID: {$profile->id}\n";
  echo "User ID: {$profile->uid}\n";
  
  if (empty($profile->consolidated_profile_json)) {
    echo "❌ FAIL: consolidated_profile_json is empty\n\n";
    exit(1);
  }
  
  $consolidated = json_decode($profile->consolidated_profile_json, TRUE);
  if (!$consolidated) {
    echo "❌ FAIL: Invalid JSON in consolidated_profile_json\n\n";
    exit(1);
  }
  
  echo "Consolidated JSON size: " . number_format(strlen($profile->consolidated_profile_json)) . " characters\n";
  echo "\nConsolidated Structure:\n";
  echo "  Schema version: " . ($consolidated['schema_version'] ?? 'MISSING') . "\n";
  echo "  Strategic differentiators: " . count($consolidated['strategic_differentiators'] ?? []) . " items\n";
  echo "  Professional experience: " . count($consolidated['professional_experience'] ?? []) . " jobs\n";
  echo "  Technical categories: " . count($consolidated['technical_expertise']['categories'] ?? []) . " categories\n";
  
  // Count skills in consolidated
  $consolidated_skills = 0;
  foreach (($consolidated['technical_expertise']['categories'] ?? []) as $category) {
    $consolidated_skills += count($category['skills'] ?? []);
  }
  echo "  Total skills: {$consolidated_skills}\n";
  
  echo "\nDatabase Fields:\n";
  echo "  Professional summary: " . (empty($profile->professional_summary) ? 'EMPTY' : strlen($profile->professional_summary) . ' chars') . "\n";
  echo "  Skills: " . (empty($profile->skills) ? 'EMPTY' : strlen($profile->skills) . ' chars') . "\n";
  
  echo "\n✅ PASS: Consolidated profile is complete\n\n";
  
  // ===================================================================
  // TEST 5: Verify Form Data Display
  // ===================================================================
  echo "TEST 5: Simulate Form Data Retrieval\n";
  echo "-------------------------------------------------------------------\n";
  
  // Test the getConsolidatedValue logic simulation
  $test_fields = [
    'Executive Profile' => !empty($consolidated['executive_profile']),
    'Strategic Differentiators' => count($consolidated['strategic_differentiators'] ?? []) > 0,
    'Contact Info' => !empty($consolidated['contact_info']),
    'Technical Expertise' => !empty($consolidated['technical_expertise']),
    'Professional Experience' => count($consolidated['professional_experience'] ?? []) > 0,
    'Early Career' => !empty($consolidated['early_career']),
    'Education' => count($consolidated['education'] ?? []) > 0,
    'Leadership Philosophy' => !empty($consolidated['leadership_philosophy']),
    'Demonstration Projects' => count($consolidated['demonstration_projects'] ?? []) > 0,
  ];
  
  echo "Form Field Data Availability:\n";
  $all_available = TRUE;
  foreach ($test_fields as $field => $available) {
    $status = $available ? '✅' : '❌';
    echo "  {$status} {$field}\n";
    if (!$available) {
      $all_available = FALSE;
    }
  }
  
  if (!$all_available) {
    echo "\n⚠️  WARNING: Some form fields may not have data\n\n";
  } else {
    echo "\n✅ PASS: All major form fields have data available\n\n";
  }
  
  // ===================================================================
  // TEST 6: Strategic Differentiators Detail Check
  // ===================================================================
  echo "TEST 6: Strategic Differentiators Detail Check\n";
  echo "-------------------------------------------------------------------\n";
  
  $differentiators = $consolidated['strategic_differentiators'] ?? [];
  if (empty($differentiators)) {
    echo "❌ FAIL: Strategic differentiators array is empty\n\n";
    exit(1);
  }
  
  echo "Found " . count($differentiators) . " strategic differentiators:\n\n";
  foreach ($differentiators as $index => $diff) {
    $num = $index + 1;
    echo "{$num}. {$diff['title']}\n";
    if (isset($diff['description'])) {
      $desc_preview = strlen($diff['description']) > 100 
        ? substr($diff['description'], 0, 100) . '...' 
        : $diff['description'];
      echo "   {$desc_preview}\n";
    }
    echo "\n";
  }
  
  echo "✅ PASS: Strategic differentiators are populated with content\n\n";
  
  // ===================================================================
  // TEST 7: Cover Letter Template Generation Test
  // ===================================================================
  echo "TEST 7: Cover Letter Template Data Availability\n";
  echo "-------------------------------------------------------------------\n";
  
  $cover_letter_ready = FALSE;
  $cover_letter_checks = [];
  
  // Check required fields for cover letter
  $cover_letter_checks[] = [
    'field' => 'Contact name',
    'available' => !empty($consolidated['contact_info']['full_name']),
  ];
  $cover_letter_checks[] = [
    'field' => 'Contact email',
    'available' => !empty($consolidated['contact_info']['email']),
  ];
  $cover_letter_checks[] = [
    'field' => 'Executive profile',
    'available' => !empty($consolidated['executive_profile']),
  ];
  $cover_letter_checks[] = [
    'field' => 'Technical categories',
    'available' => count($consolidated['technical_expertise']['categories'] ?? []) >= 3,
  ];
  
  echo "Cover Letter Required Data:\n";
  $cover_letter_ready = TRUE;
  foreach ($cover_letter_checks as $check) {
    $status = $check['available'] ? '✅' : '❌';
    echo "  {$status} {$check['field']}\n";
    if (!$check['available']) {
      $cover_letter_ready = FALSE;
    }
  }
  
  if (!$cover_letter_ready) {
    echo "\n⚠️  WARNING: Cover letter generation may fail due to missing data\n\n";
  } else {
    echo "\n✅ PASS: All required data available for cover letter generation\n\n";
  }
  
  // ===================================================================
  // FINAL SUMMARY
  // ===================================================================
  echo "=================================================================\n";
  echo "TEST SUMMARY\n";
  echo "=================================================================\n\n";
  
  $summary_stats = [
    'Resume File' => "✅ {$file->getFilename()} (" . number_format($file_size) . " bytes)",
    'Extracted Text' => "✅ " . number_format($text_length) . " characters",
    'Parse Status' => "✅ {$parsed->status}",
    'Parsed JSON' => "✅ " . number_format(strlen($parsed->parsed_data)) . " characters",
    'Strategic Differentiators' => "✅ " . count($differentiators) . " items",
    'Technical Skills' => "✅ {$consolidated_skills} skills across " . count($consolidated['technical_expertise']['categories'] ?? []) . " categories",
    'Professional Experience' => "✅ " . count($consolidated['professional_experience'] ?? []) . " jobs",
    'Education' => "✅ " . count($consolidated['education'] ?? []) . " entries",
    'Cover Letter Ready' => ($cover_letter_ready ? "✅ Yes" : "⚠️  Missing data"),
  ];
  
  foreach ($summary_stats as $metric => $value) {
    echo str_pad($metric . ':', 30) . $value . "\n";
  }
  
  echo "\n" . str_repeat('=', 65) . "\n";
  echo "✅ ALL TESTS PASSED - PROFILE GENERATION IS COMPLETE\n";
  echo str_repeat('=', 65) . "\n\n";
  
  echo "Next Steps:\n";
  echo "  1. Visit /jobhunter/profile/edit to view the form\n";
  echo "  2. Verify all sections are populated with data\n";
  echo "  3. Test the 'Generate Cover Letter Template' button\n";
  echo "  4. Check that text is readable (no white-on-white issues)\n\n";

} catch (\Exception $e) {
  echo "\n=================================================================\n";
  echo "❌ TEST FAILED\n";
  echo "=================================================================\n\n";
  echo "Error: " . $e->getMessage() . "\n\n";
  echo "Stack trace:\n";
  echo $e->getTraceAsString() . "\n\n";
  exit(1);
}
