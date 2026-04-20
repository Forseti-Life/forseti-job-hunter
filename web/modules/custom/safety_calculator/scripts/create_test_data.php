<?php

/**
 * @file
 * Script to create test data for individual metrics.
 */

use Drupal\user\Entity\User;

// Get user 1.
$user = User::load(1);
if (!$user) {
  echo "❌ User 1 not found\n";
  exit(1);
}
echo "✅ User: " . $user->getAccountName() . " (ID: " . $user->id() . ")\n";

// Get repository service.
$repo = \Drupal::service('safety_calculator.individual_metrics_repository');

// Create assessment.
$assessmentId = $repo->createAssessment(1);
echo "✅ Created assessment ID: $assessmentId\n";

// Get some metrics to test with.
$dimensions = ['SAFE', 'ENERGIZED', 'CONNECTED'];
$totalSaved = 0;

foreach ($dimensions as $dimension) {
  $metrics = $repo->getMetricsByDimension($dimension);
  echo "✅ Found " . count($metrics) . " $dimension metrics\n";
  
  // Add test responses for first 10 metrics of each dimension.
  $saved = 0;
  foreach (array_slice($metrics, 0, 10) as $metric) {
    $value = null;
    
    switch ($metric['data_type']) {
      case 'numeric':
        $value = rand(1, 100);
        break;
      case 'scale':
        $value = rand(0, 10);
        break;
      case 'boolean':
        $value = rand(0, 1);
        break;
      case 'select':
        // Try to parse options from validation_rules.
        $rules = json_decode($metric['validation_rules'], true);
        if (isset($rules['options']) && !empty($rules['options'])) {
          $value = $rules['options'][array_rand($rules['options'])];
        } else {
          $value = 'option1';
        }
        break;
      case 'text':
        $value = 'Test response ' . rand(1, 100);
        break;
    }
    
    if ($value !== null) {
      try {
        $repo->saveResponse(1, $assessmentId, $metric['id'], $value);
        $saved++;
        $totalSaved++;
      } catch (\Exception $e) {
        echo "  ⚠️  Error saving metric {$metric['metric_name']}: {$e->getMessage()}\n";
      }
    }
  }
  
  echo "  ✅ Saved $saved $dimension responses\n";
}

echo "\n🎉 Total responses saved: $totalSaved\n";
echo "📊 Assessment ID: $assessmentId\n";
