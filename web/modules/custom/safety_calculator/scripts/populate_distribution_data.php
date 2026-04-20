<?php

/**
 * @file
 * Populate sample distribution data for boolean and select demographics.
 * 
 * Run via: drush php:script populate_distribution_data.php
 */

use Drupal\Core\Database\Database;

$database = Database::getConnection();

// Sample distribution data based on typical US demographics
$distributions = [
  // Q213: Gender
  'transgender_status' => ['json' => '{"Cisgender": 98.5, "Transgender": 1.5}', 'common' => 'Cisgender', 'pct' => 98.5],
  'lgbtq__identification' => ['json' => '{"No": 92.0, "Yes": 8.0}', 'common' => 'No', 'pct' => 92.0],
  
  // Q216: Ethnicity
  'ethnicity_hispanic_latino' => ['json' => '{"No": 81.0, "Yes": 19.0}', 'common' => 'No', 'pct' => 81.0],
  
  // Q217: Income
  'poverty_status' => ['json' => '{"No": 88.9, "Yes": 11.1}', 'common' => 'No', 'pct' => 88.9],
  
  // Q219: Education
  'currently_enrolled_in_school' => ['json' => '{"No": 85.0, "Yes": 15.0}', 'common' => 'No', 'pct' => 85.0],
  
  // Q220: Employment
  'multiple_jobs_status' => ['json' => '{"No": 82.0, "Yes": 18.0}', 'common' => 'No', 'pct' => 82.0],
  
  // Q222: Marital
  'cohabitation_status' => ['json' => '{"No": 83.0, "Yes": 17.0}', 'common' => 'No', 'pct' => 83.0],
  
  // Q225: Caregiving
  'primary_caregiver_status' => ['json' => '{"No": 70.0, "Yes": 30.0}', 'common' => 'No', 'pct' => 70.0],
  'elder_care_responsibilities' => ['json' => '{"No": 85.0, "Yes": 15.0}', 'common' => 'No', 'pct' => 85.0],
  'special_needs_caregiving' => ['json' => '{"No": 88.0, "Yes": 12.0}', 'common' => 'No', 'pct' => 88.0],
  
  // Q230: Housing
  'subsidized_housing' => ['json' => '{"No": 93.0, "Yes": 7.0}', 'common' => 'No', 'pct' => 93.0],
  
  // Q233: Disability
  'disability_status_overall' => ['json' => '{"No": 74.0, "Yes": 26.0}', 'common' => 'No', 'pct' => 74.0],
  'disability_type___physical' => ['json' => '{"No": 87.0, "Yes": 13.0}', 'common' => 'No', 'pct' => 87.0],
  'disability_type___sensory' => ['json' => '{"No": 93.0, "Yes": 7.0}', 'common' => 'No', 'pct' => 93.0],
  'disability_type___cognitive' => ['json' => '{"No": 92.0, "Yes": 8.0}', 'common' => 'No', 'pct' => 92.0],
  'disability_type___mental_health' => ['json' => '{"No": 83.0, "Yes": 17.0}', 'common' => 'No', 'pct' => 83.0],
  
  // Q234: Insurance
  'insurance_adequacy' => ['json' => '{"Yes": 67.0, "No": 33.0}', 'common' => 'Yes', 'pct' => 67.0],
  
  // Q235: Veteran
  'veteran_status' => ['json' => '{"No": 93.0, "Yes": 7.0}', 'common' => 'No', 'pct' => 93.0],
  
  // Q239: Technology
  'internet_access_at_home' => ['json' => '{"Yes": 85.0, "No": 15.0}', 'common' => 'Yes', 'pct' => 85.0],
  
  // Q240: Transportation
  'vehicle_ownership' => ['json' => '{"Yes": 89.0, "No": 11.0}', 'common' => 'Yes', 'pct' => 89.0],
];

$updated = 0;

echo "Populating distribution data for boolean/select demographics...\n";

foreach ($distributions as $metric_name => $data) {
  $result = $database->update('individual_metrics_master')
    ->fields([
      'distribution_data' => $data['json'],
      'most_common_value' => $data['common'],
      'most_common_percentage' => $data['pct'],
    ])
    ->condition('metric_name', $metric_name)
    ->condition('dimension', 'DEMOGRAPHIC')
    ->execute();
  
  if ($result) {
    $updated++;
    echo "  ✓ {$metric_name}\n";
  }
}

echo "\nComplete! Updated {$updated} metrics with distribution data.\n";
