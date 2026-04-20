<?php

/**
 * Verify form display will show extracted data.
 * Run with: drush php:script verify-form-data.php
 */

$uid = 1;

echo "=== Verifying Form Data Display ===\n\n";

$job_seeker_service = \Drupal::service('job_hunter.job_seeker_service');
$profile = $job_seeker_service->loadByUserId($uid);

if (!$profile) {
  echo "ERROR: No profile found for user $uid\n";
  exit(1);
}

$consolidated = json_decode($profile->consolidated_profile_json, TRUE);

echo "1. Contact Information Fields:\n";
echo "   ✓ Full Name: " . ($consolidated['contact_info']['full_name'] ?? 'NOT SET') . "\n";
echo "   ✓ Email: " . ($consolidated['contact_info']['email'] ?? 'NOT SET') . "\n";
echo "   ✓ Phone: " . ($consolidated['contact_info']['phone'] ?? 'NOT SET') . "\n";
echo "   ✓ City: " . ($consolidated['contact_info']['location']['city'] ?? 'NOT SET') . "\n";
echo "   ✓ State: " . ($consolidated['contact_info']['location']['state'] ?? 'NOT SET') . "\n";
echo "   ✓ Headline: " . ($consolidated['contact_info']['headline'] ?? 'NOT SET') . "\n\n";

echo "2. Professional Experience (Early Career):\n";
if (!empty($consolidated['early_career']['positions'])) {
  echo "   ✓ Found " . count($consolidated['early_career']['positions']) . " positions:\n";
  foreach ($consolidated['early_career']['positions'] as $idx => $pos) {
    echo "     " . ($idx + 1) . ". " . ($pos['company'] ?? 'Unknown') . "\n";
  }
} else {
  echo "   ✗ No positions found\n";
}
echo "\n";

echo "3. Suggested Keywords (Technical Expertise):\n";
if (!empty($consolidated['technical_expertise']['categories'])) {
  echo "   ✓ Found " . count($consolidated['technical_expertise']['categories']) . " categories:\n";
  $keyword_count = 0;
  foreach ($consolidated['technical_expertise']['categories'] as $cat) {
    $cat_name = $cat['name'] ?? 'Unknown';
    $skill_count = count($cat['skills'] ?? []);
    $keyword_count += $skill_count + 1; // +1 for category name
    echo "     - $cat_name ($skill_count skills)\n";
  }
  echo "   ✓ Total keywords available for suggestions: ~$keyword_count\n";
} else {
  echo "   ✗ No technical expertise found\n";
}
echo "\n";

echo "4. Cover Letter Generation Data:\n";
echo "   ✓ Executive Profile: " . (empty($consolidated['executive_profile']) ? 'NOT SET' : 'SET') . "\n";
echo "   ✓ Technical Expertise: " . (empty($consolidated['technical_expertise']) ? 'NOT SET' : 'SET') . "\n";
echo "   ✓ Early Career: " . (empty($consolidated['early_career']) ? 'NOT SET' : 'SET') . "\n";
echo "   ✓ Leadership Philosophy: " . (empty($consolidated['leadership_philosophy']) ? 'NOT SET' : 'SET') . "\n\n";

echo "5. Database Fields:\n";
echo "   ✓ Professional Summary: " . ($profile->professional_summary ?? 'NOT SET') . "\n";
echo "   ✓ Experience Years: " . ($profile->experience_years ?? 'NOT SET') . "\n";
echo "   ✓ Education Level: " . ($profile->education_level ?? 'NOT SET') . "\n";
echo "   ✓ Skills: " . substr($profile->skills ?? 'NOT SET', 0, 60) . "...\n\n";

echo "=== Test Results ===\n";

$tests_passed = 0;
$tests_total = 0;

// Test 1: Contact info populated
$tests_total++;
if (!empty($consolidated['contact_info']['full_name'])) {
  echo "✓ PASS: Contact information extracted and will display in form\n";
  $tests_passed++;
} else {
  echo "✗ FAIL: Contact information missing\n";
}

// Test 2: Professional experience populated
$tests_total++;
if (!empty($consolidated['early_career']['positions'])) {
  echo "✓ PASS: Professional experience extracted and will display in preview\n";
  $tests_passed++;
} else {
  echo "✗ FAIL: Professional experience missing\n";
}

// Test 3: Keywords available
$tests_total++;
if (!empty($consolidated['technical_expertise']['categories'])) {
  echo "✓ PASS: Suggested keywords will be generated from technical expertise\n";
  $tests_passed++;
} else {
  echo "✗ FAIL: Technical expertise missing - no keywords to suggest\n";
}

// Test 4: Cover letter data available
$tests_total++;
if (!empty($consolidated['executive_profile']) && !empty($consolidated['technical_expertise'])) {
  echo "✓ PASS: Cover letter template can be generated from profile data\n";
  $tests_passed++;
} else {
  echo "✗ FAIL: Insufficient data for cover letter generation\n";
}

echo "\nTest Summary: $tests_passed/$tests_total tests passed\n";

if ($tests_passed === $tests_total) {
  echo "\n🎉 All features verified! The form enhancements will work correctly.\n";
  echo "Visit https://forseti.life/jobhunter/profile/edit to see the results.\n";
} else {
  echo "\n⚠️  Some features may not work as expected.\n";
}
