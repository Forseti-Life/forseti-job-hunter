<?php

/**
 * @file
 * Verify form field data extraction from consolidated JSON.
 * 
 * Run with: drush php:script verify-form-display.php
 */

echo "=================================================================\n";
echo "FORM DATA DISPLAY VERIFICATION\n";
echo "=================================================================\n\n";

$uid = 1;
$connection = \Drupal::database();

// Load job seeker profile
$profile = $connection->select('jobhunter_job_seeker', 'js')
  ->fields('js')
  ->condition('uid', $uid)
  ->execute()
  ->fetchObject();

if (!$profile) {
  echo "❌ No profile found for user {$uid}\n";
  exit(1);
}

echo "Profile ID: {$profile->id}\n";
echo "User ID: {$profile->uid}\n";
echo "JSON Size: " . strlen($profile->consolidated_profile_json) . " characters\n\n";

$consolidated = json_decode($profile->consolidated_profile_json, TRUE);

if (!$consolidated) {
  echo "❌ Failed to decode JSON\n";
  exit(1);
}

echo "Consolidated JSON Structure:\n";
echo "-------------------------------------------------------------------\n";
echo "Schema version: " . ($consolidated['schema_version'] ?? 'MISSING') . "\n\n";

// Contact Info
echo "CONTACT INFO:\n";
echo "  Name: " . ($consolidated['contact_info']['full_name'] ?? 'MISSING') . "\n";
echo "  Email: " . ($consolidated['contact_info']['email'] ?? 'MISSING') . "\n";
echo "  Phone: " . ($consolidated['contact_info']['phone'] ?? 'MISSING') . "\n";
if (isset($consolidated['contact_info']['location'])) {
  echo "  Location: " . ($consolidated['contact_info']['location']['city'] ?? '') . ", " . ($consolidated['contact_info']['location']['state'] ?? '') . "\n";
}
echo "\n";

// Executive Profile
echo "EXECUTIVE PROFILE:\n";
if (isset($consolidated['executive_profile']['summary'])) {
  $summary = $consolidated['executive_profile']['summary'];
  echo "  Summary: " . substr($summary, 0, 100) . "...\n";
  echo "  Length: " . strlen($summary) . " characters\n";
} else {
  echo "  ❌ MISSING\n";
}
echo "\n";

// Strategic Differentiators
echo "STRATEGIC DIFFERENTIATORS:\n";
$diff_count = count($consolidated['strategic_differentiators'] ?? []);
echo "  Count: {$diff_count}\n";
if ($diff_count > 0) {
  foreach ($consolidated['strategic_differentiators'] as $i => $diff) {
    echo "  " . ($i+1) . ". " . ($diff['title'] ?? 'NO TITLE') . "\n";
  }
} else {
  echo "  ❌ EMPTY\n";
}
echo "\n";

// Technical Expertise
echo "TECHNICAL EXPERTISE:\n";
$cat_count = count($consolidated['technical_expertise']['categories'] ?? []);
echo "  Categories: {$cat_count}\n";
$total_skills = 0;
foreach (($consolidated['technical_expertise']['categories'] ?? []) as $cat) {
  $skill_count = count($cat['skills'] ?? []);
  $total_skills += $skill_count;
  echo "  - " . ($cat['name'] ?? 'NO NAME') . ": {$skill_count} skills\n";
}
echo "  Total skills: {$total_skills}\n\n";

// Professional Experience
echo "PROFESSIONAL EXPERIENCE:\n";
$exp_count = count($consolidated['professional_experience'] ?? []);
echo "  Jobs: {$exp_count}\n";
if ($exp_count > 0) {
  foreach ($consolidated['professional_experience'] as $i => $job) {
    echo "  " . ($i+1) . ". " . ($job['company'] ?? 'NO COMPANY') . " - " . ($job['title'] ?? 'NO TITLE') . "\n";
  }
} else {
  echo "  ❌ EMPTY\n";
}
echo "\n";

// Early Career
echo "EARLY CAREER:\n";
if (isset($consolidated['early_career']['positions'])) {
  $pos_count = count($consolidated['early_career']['positions']);
  echo "  Positions: {$pos_count}\n";
  if ($pos_count > 0) {
    foreach ($consolidated['early_career']['positions'] as $pos) {
      echo "  - " . ($pos['company'] ?? 'NO COMPANY') . "\n";
    }
  }
} else {
  echo "  Not present\n";
}
echo "\n";

// Education
echo "EDUCATION:\n";
$edu_count = count($consolidated['education'] ?? []);
echo "  Entries: {$edu_count}\n";
if ($edu_count > 0) {
  foreach ($consolidated['education'] as $edu) {
    echo "  - " . ($edu['degree'] ?? 'NO DEGREE') . " from " . ($edu['institution'] ?? 'NO INSTITUTION') . "\n";
  }
} else {
  echo "  ❌ EMPTY\n";
}
echo "\n";

// Now test field mappings that would be used in the form
echo "=================================================================\n";
echo "FORM FIELD VALUE SIMULATION\n";
echo "=================================================================\n\n";

// Test professional_summary mapping
echo "field_professional_summary:\n";
if (isset($consolidated['executive_profile']['summary'])) {
  $summary = $consolidated['executive_profile']['summary'];
  echo "  ✅ Would display: " . substr($summary, 0, 100) . "...\n";
} else {
  echo "  ❌ Would be blank\n";
}
echo "\n";

// Test target_job_titles mapping
echo "field_target_job_titles:\n";
if (isset($consolidated['job_search_preferences']['target_titles'])) {
  echo "  ✅ Would display: " . (is_array($consolidated['job_search_preferences']['target_titles']) ? implode("\n", $consolidated['job_search_preferences']['target_titles']) : $consolidated['job_search_preferences']['target_titles']) . "\n";
} else {
  echo "  ⚠️  Not set (user preference field)\n";
}
echo "\n";

// Test cover letter template
echo "field_cover_letter_template:\n";
if (isset($consolidated['job_search_preferences']['cover_letter_template'])) {
  echo "  ✅ Would display: " . substr($consolidated['job_search_preferences']['cover_letter_template'], 0, 100) . "...\n";
} else {
  echo "  ⚠️  Not set (needs generation)\n";
}
echo "\n";

// Database fields
echo "=================================================================\n";
echo "DATABASE FIELD VALUES\n";
echo "=================================================================\n\n";

echo "professional_summary: " . (empty($profile->professional_summary) ? "❌ NULL/EMPTY" : "✅ " . strlen($profile->professional_summary) . " chars") . "\n";
echo "skills: " . (empty($profile->skills) ? "❌ NULL/EMPTY" : "✅ " . strlen($profile->skills) . " chars") . "\n";
echo "experience_years: " . (empty($profile->experience_years) ? "❌ NULL/EMPTY" : "✅ " . $profile->experience_years) . "\n";
echo "work_authorization: " . (empty($profile->work_authorization) ? "❌ NULL/EMPTY" : "✅ " . $profile->work_authorization) . "\n";

echo "\n";
echo "=================================================================\n";
echo "DIAGNOSIS\n";
echo "=================================================================\n\n";

$issues = [];

if (empty($profile->professional_summary)) {
  $issues[] = "professional_summary database field is empty (should be populated from executive_profile)";
}

if (empty($profile->skills)) {
  $issues[] = "skills database field is empty (should be populated from technical_expertise)";
}

if (empty($profile->experience_years)) {
  $issues[] = "experience_years database field is empty";
}

if (count($issues) > 0) {
  echo "❌ ISSUES FOUND:\n";
  foreach ($issues as $issue) {
    echo "  - {$issue}\n";
  }
  echo "\n";
  echo "RECOMMENDATION: The consolidated_profile_json has complete data,\n";
  echo "but the denormalized database fields need to be populated.\n";
  echo "The form may be trying to read from these fields instead of the JSON.\n\n";
} else {
  echo "✅ All database fields are populated\n\n";
}

echo "JSON data quality: " . ($consolidated ? "✅ GOOD" : "❌ INVALID") . "\n";
echo "Strategic differentiators: " . ($diff_count > 0 ? "✅ POPULATED" : "❌ EMPTY") . "\n";
echo "Technical skills: " . ($total_skills > 0 ? "✅ POPULATED ({$total_skills} skills)" : "❌ EMPTY") . "\n";
echo "Professional experience: " . ($exp_count > 0 ? "✅ POPULATED ({$exp_count} jobs)" : "❌ EMPTY") . "\n";
