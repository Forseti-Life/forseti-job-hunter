<?php

/**
 * @file
 * Script to create multiple test users with response data.
 */

use Drupal\user\Entity\User;

$repo = \Drupal::service('safety_calculator.individual_metrics_repository');

// Create 5 test users with responses.
$userCount = 5;
$dimensionsToTest = ['SAFE', 'ENERGIZED'];

for ($i = 1; $i <= $userCount; $i++) {
  // Use existing user 1 or create test users 2-5.
  if ($i == 1) {
    $user = User::load(1);
  } else {
    $user = User::load($i);
    if (!$user) {
      // User doesn't exist, skip for now (we'll use user 1 multiple times).
      $user = User::load(1);
    }
  }
  
  $userId = (int) $user->id();
  
  // Create assessment for this iteration.
  $assessmentId = $repo->createAssessment($userId);
  echo "✅ User $userId - Assessment $assessmentId\n";
  
  $totalSaved = 0;
  
  foreach ($dimensionsToTest as $dimension) {
    $metrics = $repo->getMetricsByDimension($dimension);
    
    // Add varied responses for first 15 metrics.
    foreach (array_slice($metrics, 0, 15) as $metric) {
      $value = null;
      
      switch ($metric['data_type']) {
        case 'numeric':
          // Vary the values by user for realistic distribution.
          $base = 50;
          $variation = ($i - 1) * 10;
          $value = $base + $variation + rand(-20, 20);
          break;
          
        case 'scale':
          // User 1: 3-5, User 2: 4-7, User 3: 5-8, etc.
          $min = 2 + $i;
          $max = min(10, $min + 3);
          $value = rand($min, $max);
          break;
          
        case 'boolean':
          $value = rand(0, 1);
          break;
          
        case 'select':
          $rules = json_decode($metric['validation_rules'], true);
          if (isset($rules['options']) && !empty($rules['options'])) {
            $value = $rules['options'][array_rand($rules['options'])];
          }
          break;
          
        case 'text':
          $value = "Response from user $userId - " . rand(1, 100);
          break;
      }
      
      if ($value !== null) {
        try {
          $repo->saveResponse($userId, $assessmentId, $metric['id'], $value);
          $totalSaved++;
        } catch (\Exception $e) {
          // Skip errors.
        }
      }
    }
  }
  
  echo "  💾 Saved $totalSaved responses\n";
}

echo "\n🎉 Test data creation complete!\n";
echo "📊 Created data for multiple assessment runs\n";
