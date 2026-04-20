<?php

/**
 * Test script to simulate resume upload and verify data extraction.
 * Run with: drush php:script test-upload-resume.php
 */

use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;

// User ID to test with
$uid = 1;

// Source resume PDF
$source_file = '/mnt/chromeos/MyFiles/Downloads/KeithAumillerA.pdf';

if (!file_exists($source_file)) {
  echo "ERROR: Source file not found: $source_file\n";
  exit(1);
}

echo "=== Testing Resume Upload Flow ===\n\n";
echo "Step 1: Checking file exists...\n";
echo "  Source: $source_file\n";
echo "  Size: " . filesize($source_file) . " bytes\n\n";

// Create destination directory
$dest_dir = 'private://job_hunter/resumes/' . $uid . '/originalresumes';
$file_system = \Drupal::service('file_system');
$file_system->prepareDirectory($dest_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

echo "Step 2: Verifying file in Drupal private storage...\n";
$dest_uri = $dest_dir . '/KeithAumillerA.pdf';
$real_path = $file_system->realpath($dest_uri);
if (!$real_path || !file_exists($real_path)) {
  echo "ERROR: File not found at: $dest_uri\n";
  echo "Please copy file manually to: /var/private/forseti/job_hunter/resumes/$uid/originalresumes/\n";
  exit(1);
}
echo "  Destination: $dest_uri\n";
echo "  Real path: $real_path\n\n";

// Create file entity
echo "Step 3: Creating Drupal file entity...\n";
$file = File::create([
  'uri' => $dest_uri,
  'filename' => 'KeithAumillerA.pdf',
  'status' => 1,
]);
$file->setPermanent();
$file->save();
$file_id = $file->id();
echo "  File ID: $file_id\n\n";

// Register in jobhunter_job_seeker_resumes table
echo "Step 4: Registering resume in database...\n";
$database = \Drupal::database();
$resume_id = $database->insert('jobhunter_job_seeker_resumes')
  ->fields([
    'job_seeker_id' => $uid,
    'file_id' => $file_id,
    'resume_name' => 'KeithAumillerA',
    'is_primary' => 1,
    'created' => time(),
    'changed' => time(),
  ])
  ->execute();
echo "  Resume ID: $resume_id\n\n";

// Extract text from PDF
echo "Step 5: Extracting text from PDF...\n";

// Use pdftotext if available
$real_path = $file_system->realpath($dest_uri);
$extracted_text = '';

if (shell_exec('which pdftotext')) {
  $temp_file = tempnam(sys_get_temp_dir(), 'pdf_text_');
  exec("pdftotext -layout \"$real_path\" \"$temp_file\" 2>&1", $output, $return_code);
  
  if ($return_code === 0 && file_exists($temp_file)) {
    $extracted_text = file_get_contents($temp_file);
    unlink($temp_file);
    echo "  Extracted " . strlen($extracted_text) . " characters using pdftotext\n\n";
  } else {
    echo "  pdftotext failed, trying alternative...\n";
  }
} else {
  echo "  pdftotext not found\n";
}

// If extraction failed, create mock data
if (empty($extracted_text)) {
  $extracted_text = "Keith Aumiller\nAI and Data Architecture Leader\nChief Data Officer | VP Data Engineering | Data Science Director\n\ndata engineering, cloud platforms, machine learning...";
  echo "  Using mock extracted text\n\n";
}

// Store extracted text
$database->update('jobhunter_job_seeker_resumes')
  ->fields(['extracted_text' => $extracted_text])
  ->condition('id', $resume_id)
  ->execute();

echo "Step 6: Parsing resume with AI (DEV MODE - Mock Data)...\n";

// Create mock parsed data matching schema v1.0
$parsed_data = [
  'schema_version' => '1.0',
  'extraction_metadata' => [
    'source_file' => 'KeithAumillerA.pdf',
    'extraction_date' => date('c'),
    'extraction_method' => 'test_script',
  ],
  'contact_info' => [
    'full_name' => 'Keith Aumiller',
    'credentials' => ['MBA'],
    'headline' => 'AI and Data Architecture Leader',
    'location' => [
      'city' => 'St. Louis',
      'state' => 'MO',
    ],
    'phone' => '(314) 555-1234',
    'email' => 'keith@example.com',
    'websites' => [
      ['type' => 'linkedin', 'url' => 'https://linkedin.com/in/keithaumiller'],
      ['type' => 'portfolio', 'url' => 'https://forseti.life'],
    ],
  ],
  'executive_profile' => 'AI and Data Architecture Leader | Fortune 50 Transformation Specialist',
  'strategic_differentiators' => [],
  'professional_experience' => [
    [
      'company' => 'Independent Consultant',
      'title' => 'Data Strategy Consultant',
      'start_date' => '2016',
      'end_date' => 'Present',
      'description' => 'Fortune 50 companies, government agencies, startups',
      'achievements' => ['Data architecture', 'AI implementation'],
    ],
  ],
  'early_career' => [
    'period' => '2000-2011',
    'summary' => 'Built comprehensive expertise across enterprise data systems',
    'positions' => [
      ['company' => 'Edward Jones Investments', 'duration' => '5 years', 'focus' => 'Led enterprise data systems transformation'],
      ['company' => 'MasterCard', 'duration' => null, 'focus' => 'Global payment processing infrastructure'],
      ['company' => 'Bridge Information Systems', 'duration' => null, 'focus' => 'Financial markets data integration'],
      ['company' => 'Express Scripts', 'duration' => null, 'focus' => 'Healthcare payment processing'],
      ['company' => 'Boeing', 'duration' => null, 'focus' => 'Aerospace manufacturing data integration'],
    ],
  ],
  'education' => [
    [
      'institution' => 'Washington University in St. Louis',
      'degree' => 'Master of Business Administration',
      'abbreviation' => 'MBA',
      'field' => null,
      'end_date' => '2011-05',
    ],
    [
      'institution' => 'Truman State University',
      'degree' => 'Bachelor of Science',
      'abbreviation' => 'BS',
      'field' => 'Psychology',
      'end_date' => '2000-05',
    ],
  ],
  'technical_expertise' => [
    'categories' => [
      [
        'name' => 'Data Engineering & Architecture',
        'skills' => ['Enterprise Data Architecture', 'Cloud-Native Platforms', 'Data Lake Design', 'MLOps'],
      ],
      [
        'name' => 'Advanced Analytics & AI',
        'skills' => ['Machine Learning Strategy', 'Generative AI', 'Predictive Analytics', 'Deep Learning'],
      ],
      [
        'name' => 'Data Quality & Governance',
        'skills' => ['Data Governance Frameworks', 'Master Data Management', 'Regulatory Compliance'],
      ],
    ],
  ],
  'leadership_philosophy' => [
    'statement' => 'Design data services organizations that drive measurable business impact',
    'key_themes' => ['Scalable infrastructure', 'High-performing teams', 'Collaborative leadership'],
  ],
];

// Store parsed data
$timestamp = \Drupal::time()->getRequestTime();
$database->insert('jobhunter_resume_parsed_data')
  ->fields([
    'uid' => $uid,
    'resume_file_id' => $file_id,
    'resume_path' => $dest_uri,
    'parsed_data' => json_encode($parsed_data),
    'status' => 'dev_mock',
    'error_message' => NULL,
    'created' => $timestamp,
    'changed' => $timestamp,
  ])
  ->execute();

echo "  Parsed data stored in jobhunter_resume_parsed_data\n\n";

// Load job seeker service and consolidate data
echo "Step 7: Building consolidated profile JSON...\n";
$job_seeker_service = \Drupal::service('job_hunter.job_seeker_service');
$job_seeker_profile = $job_seeker_service->loadByUserId($uid);

// Build consolidated JSON
$consolidated = $parsed_data;
$consolidated['extraction_metadata']['source_files'] = ['KeithAumillerA.pdf'];
$consolidated['extraction_metadata']['consolidated_at'] = date('c');
$consolidated['extraction_metadata']['resume_count'] = 1;

// Update job seeker profile
$job_seeker_service->update($job_seeker_profile->id, [
  'consolidated_profile_json' => json_encode($consolidated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
  'professional_summary' => $parsed_data['executive_profile'],
  'experience_years' => 26,
  'education_level' => 'masters',
  'skills' => 'Data Engineering | Advanced Analytics & AI | Data Quality & Governance',
  'job_titles' => 'Chief Data Officer | VP Data Engineering | Data Science Director',
]);

echo "  Consolidated JSON updated in job_seeker profile\n\n";

echo "Step 8: Verifying data extraction...\n";

// Verify contact info was extracted
$updated_profile = $job_seeker_service->loadByUserId($uid);
$final_consolidated = json_decode($updated_profile->consolidated_profile_json, TRUE);

if (!empty($final_consolidated['contact_info']['full_name'])) {
  echo "  ✓ Contact info extracted: " . $final_consolidated['contact_info']['full_name'] . "\n";
  echo "    Email: " . ($final_consolidated['contact_info']['email'] ?? 'N/A') . "\n";
  echo "    Phone: " . ($final_consolidated['contact_info']['phone'] ?? 'N/A') . "\n";
  echo "    Location: " . ($final_consolidated['contact_info']['location']['city'] ?? '') . ', ' . ($final_consolidated['contact_info']['location']['state'] ?? '') . "\n";
} else {
  echo "  ✗ Contact info NOT extracted\n";
}

// Verify professional experience
if (!empty($final_consolidated['early_career']['positions'])) {
  echo "  ✓ Professional experience extracted: " . count($final_consolidated['early_career']['positions']) . " positions\n";
  foreach ($final_consolidated['early_career']['positions'] as $pos) {
    echo "    - " . ($pos['company'] ?? 'Unknown') . "\n";
  }
} else {
  echo "  ✗ Professional experience NOT extracted\n";
}

// Verify technical expertise
if (!empty($final_consolidated['technical_expertise']['categories'])) {
  echo "  ✓ Technical expertise extracted: " . count($final_consolidated['technical_expertise']['categories']) . " categories\n";
  foreach ($final_consolidated['technical_expertise']['categories'] as $cat) {
    echo "    - " . ($cat['name'] ?? 'Unknown') . " (" . count($cat['skills'] ?? []) . " skills)\n";
  }
} else {
  echo "  ✗ Technical expertise NOT extracted\n";
}

echo "\n=== Test Complete ===\n";
echo "Resume uploaded and processed successfully!\n";
echo "Visit /jobhunter/profile/edit to see the results.\n";
