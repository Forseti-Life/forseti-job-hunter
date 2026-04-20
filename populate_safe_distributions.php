<?php

/**
 * Generate distribution data for normalized SAFE metrics.
 * Creates a bell curve distribution based on normalized mean (50) and population stddev.
 */

use Drupal\Core\Database\Database;

$database = Database::getConnection();

echo "Generating distribution data for SAFE metrics...\n\n";

// Fetch first 5 SAFE metrics with their actual population values
$metrics_query = $database->select('individual_metrics_master', 'imm')
  ->fields('imm', ['id', 'metric_name', 'normalized_mean', 'population_mean', 'population_stddev'])
  ->condition('dimension', 'SAFE')
  ->condition('data_type', ['numeric', 'scale'], 'IN')
  ->range(0, 5)
  ->execute();

$metrics = [];
foreach ($metrics_query as $row) {
  $metrics[] = [
    'id' => $row->id,
    'name' => $row->metric_name,
    'normalized_mean' => $row->normalized_mean,
    'population_mean' => $row->population_mean,
    'stddev' => $row->population_stddev,
  ];
}

/**
 * Generate a normal distribution bell curve on the 0-100 normalized scale.
 * 0 = -3 SD, 50 = mean, 100 = +3 SD
 * Returns array with distribution points and metadata for labels.
 */
function generateNormalizedDistribution($mean = 50, $population_mean, $population_stddev) {
  $distribution = [];
  
  // Generate points every 5 units from 0 to 100
  // This creates 21 points for a smooth bell curve
  for ($x = 0; $x <= 100; $x += 5) {
    // Convert normalized score to z-score
    // normalized = 50 + (z × 16.67)
    // z = (normalized - 50) / 16.67
    $z = ($x - 50) / 16.67;
    
    // Calculate probability density function for normal distribution
    // f(z) = (1 / sqrt(2π)) × e^(-z²/2)
    $pdf = (1 / sqrt(2 * M_PI)) * exp(-0.5 * $z * $z);
    
    // Scale to percentage (multiply by 100 for readability)
    // Normalize so the peak is around 100% for better visualization
    $percentage = $pdf * 250; // Scale factor to make peak ~100%
    
    $distribution[(string)$x] = round($percentage, 2);
  }
  
  // Add metadata for chart labels
  // 0 = mean - 3*SD, 50 = mean, 100 = mean + 3*SD
  $distribution['_metadata'] = [
    'mean' => round($population_mean, 2),
    'min_value' => round($population_mean - (3 * $population_stddev), 2),
    'max_value' => round($population_mean + (3 * $population_stddev), 2),
  ];
  
  return $distribution;
}

$updated = 0;
$skipped = 0;

foreach ($metrics as $metric) {
  // Check if metric exists
  $exists = $database->select('individual_metrics_master', 'imm')
    ->fields('imm', ['id'])
    ->condition('id', $metric['id'])
    ->execute()
    ->fetchField();
    
  if (!$exists) {
    echo "⚠ Metric ID {$metric['id']} ({$metric['name']}) - NOT FOUND\n";
    $skipped++;
    continue;
  }
  
  // Generate distribution
  $distribution = generateNormalizedDistribution(
    $metric['normalized_mean'],
    $metric['population_mean'],
    $metric['stddev']
  );
  
  // For bell curve, the peak is always at the mean (50)
  $most_common_category = '50';
  // Filter out metadata when finding max
  $values_only = array_filter($distribution, function($key) {
    return $key !== '_metadata';
  }, ARRAY_FILTER_USE_KEY);
  $most_common_percentage = max($values_only);
  
  // Update the metric
  $database->update('individual_metrics_master')
    ->fields([
      'distribution_data' => json_encode($distribution),
      'most_common_value' => $most_common_category,
      'most_common_percentage' => $most_common_percentage,
    ])
    ->condition('id', $metric['id'])
    ->execute();
  
  echo "✓ {$metric['name']} - {$most_common_category} ({$most_common_percentage}%)\n";
  $updated++;
}

echo "\n============================================================\n";
echo "Complete!\n";
echo "  Updated: {$updated} metrics\n";
echo "  Skipped: {$skipped} metrics\n";
echo "============================================================\n";
