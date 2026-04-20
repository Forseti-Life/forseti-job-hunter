<?php

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'autoload.php';
$kernel = new DrupalKernel('prod', $autoloader, FALSE);
$request = Request::createFromGlobals();
$kernel->setSitePath('sites/forseti');
$kernel->boot();
$kernel->prepareLegacyRequest($request);

$db = \Drupal::database();

// Get all questionnaire records
$total = $db->query('SELECT COUNT(*) FROM nfr_questionnaire WHERE uid > 2')->fetchField();

echo "Questionnaire Fill Rate Analysis\n";
echo str_repeat('=', 70) . "\n";
echo "Total Questionnaires: $total\n\n";

// Check each direct column field
$fields = [
  'race_ethnicity' => 'Race/Ethnicity',
  'height_inches' => 'Height (inches)',
  'weight_pounds' => 'Weight (pounds)',
  'military_service' => 'Military Service',
  'military_branch' => 'Military Branch',
  'military_years' => 'Military Years',
  'other_employment_data' => 'Other Employment Data',
  'ppe_practices' => 'PPE Practices',
  'decon_practices' => 'Decontamination Practices',
  'cancer_diagnosis' => 'Cancer Diagnosis',
  'cancer_details' => 'Cancer Details',
  'family_cancer_history' => 'Family Cancer History',
  'smoking_history' => 'Smoking History',
  'alcohol_use' => 'Alcohol Use',
  'education_level' => 'Education Level',
  'marital_status' => 'Marital Status',
  'questionnaire_completed' => 'Questionnaire Completed',
  'last_section_completed' => 'Last Section Completed',
];

echo "Direct Column Fields:\n";
echo str_repeat('-', 70) . "\n";
printf("%-35s %10s %10s\n", 'Field', 'Filled', 'Fill Rate');
echo str_repeat('-', 70) . "\n";

foreach ($fields as $field => $label) {
  $filled = $db->query("SELECT COUNT(*) FROM nfr_questionnaire WHERE uid > 2 AND $field IS NOT NULL AND $field != '' AND $field != 0")->fetchField();
  $rate = $total > 0 ? round(($filled / $total) * 100, 1) : 0;
  printf("%-35s %10d %9.1f%%\n", $label, $filled, $rate);
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "JSON Data Field Analysis\n";
echo str_repeat('-', 70) . "\n";

// Analyze the JSON data field
$records = $db->query('SELECT data FROM nfr_questionnaire WHERE uid > 2')->fetchAll();

$section_counts = [];
$field_counts = [];

foreach ($records as $record) {
  $data = json_decode($record->data, true);
  
  if ($data) {
    // Count sections
    $sections = [
      'demographics' => 'Section 1 - Demographics',
      'work_history' => 'Section 2 - Work History',
      'exposure' => 'Section 3 - Exposure',
      'military' => 'Section 4 - Military',
      'other_employment' => 'Section 5 - Other Employment',
      'ppe' => 'Section 6 - PPE',
      'decontamination' => 'Section 7 - Decontamination',
      'health' => 'Section 8 - Health',
      'lifestyle' => 'Section 9 - Lifestyle',
    ];
    
    foreach ($sections as $key => $name) {
      if (!isset($section_counts[$name])) {
        $section_counts[$name] = 0;
      }
      if (isset($data[$key]) && !empty($data[$key])) {
        $section_counts[$name]++;
      }
    }
    
    // Count specific fields in each section
    $all_sections = ['demographics', 'exposure', 'military', 'lifestyle', 'work_history'];
    
    foreach ($all_sections as $section) {
      if (isset($data[$section])) {
        foreach ($data[$section] as $field => $value) {
          $key = $section . '.' . $field;
          if (!isset($field_counts[$key])) {
            $field_counts[$key] = 0;
          }
          if ($value !== null && $value !== '' && $value !== []) {
            $field_counts[$key]++;
          }
        }
      }
    }
  }
}

echo "\nSection Presence:\n";
printf("%-40s %10s %10s\n", 'Section', 'Filled', 'Fill Rate');
echo str_repeat('-', 70) . "\n";
foreach ($section_counts as $section => $count) {
  $rate = $total > 0 ? round(($count / $total) * 100, 1) : 0;
  printf("%-40s %10d %9.1f%%\n", $section, $count, $rate);
}

echo "\nKey Field Details:\n";
printf("%-40s %10s %10s\n", 'Field', 'Filled', 'Fill Rate');
echo str_repeat('-', 70) . "\n";
ksort($field_counts);
foreach ($field_counts as $field => $count) {
  $rate = $total > 0 ? round(($count / $total) * 100, 1) : 0;
  printf("%-40s %10d %9.1f%%\n", $field, $count, $rate);
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "Overall Summary:\n";
echo str_repeat('-', 70) . "\n";
$total_fields = count($field_counts);
$fully_filled = 0;
foreach ($field_counts as $count) {
  if ($count == $total) {
    $fully_filled++;
  }
}
$overall_rate = $total_fields > 0 ? round(($fully_filled / $total_fields) * 100, 1) : 0;
echo "Total fields tracked: $total_fields\n";
echo "Fields 100% filled: $fully_filled\n";
echo "Overall completion rate: $overall_rate%\n";
