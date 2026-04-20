<?php

/**
 * Generate distribution data for ALL normalized safety metrics.
 * Creates bell curve distributions for all non-demographic numeric/scale metrics.
 */

use Drupal\Core\Database\Database;

$database = Database::getConnection();

echo "Generating bell curve distributions for ALL safety metrics...\n";
echo "=============================================================\n\n";

// Fetch all non-demographic numeric/scale metrics
$metrics_query = $database->select('individual_metrics_master', 'imm')
  ->fields('imm', ['id', 'metric_name', 'dimension', 'normalized_mean', 'population_mean', 'population_stddev'])
  ->condition('dimension', 'DEMOGRAPHIC', '!=')
  ->condition('data_type', ['numeric', 'scale'], 'IN')
  ->orderBy('dimension')
  ->orderBy('id')
  ->execute();

$metrics = [];
$dimension_counts = [];

foreach ($metrics_query as $row) {
  $metrics[] = [
    'id' => $row->id,
    'name' => $row->metric_name,
    'dimension' => $row->dimension,
    'normalized_mean' => $row->normalized_mean,
    'population_mean' => $row->population_mean,
    'stddev' => $row->population_stddev,
  ];
  
  if (!isset($dimension_counts[$row->dimension])) {
    $dimension_counts[$row->dimension] = 0;
  }
  $dimension_counts[$row->dimension]++;
}

echo "Found " . count($metrics) . " metrics to process:\n";
foreach ($dimension_counts as $dimension => $count) {
  echo "  - {$dimension}: {$count} metrics\n";
}
echo "\n";

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
$current_dimension = null;

foreach ($metrics as $metric) {
  // Print dimension header when it changes
  if ($current_dimension !== $metric['dimension']) {
    if ($current_dimension !== null) {
      echo "\n";
    }
    echo "Processing {$metric['dimension']} dimension...\n";
    $current_dimension = $metric['dimension'];
  }
  
  // Check if already has distribution data (skip if so)
  $existing = $database->select('individual_metrics_master', 'imm')
    ->fields('imm', ['distribution_data'])
    ->condition('id', $metric['id'])
    ->execute()
    ->fetchField();
    
  if (!empty($existing) && $existing !== null) {
    echo "  ⏭ {$metric['name']} - ALREADY HAS DISTRIBUTION\n";
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
  
  echo "  ✓ {$metric['name']} - Mean: {$metric['population_mean']}\n";
  $updated++;
}

echo "\n============================================================\n";
echo "Complete!\n";
echo "  Updated: {$updated} metrics\n";
echo "  Skipped: {$skipped} metrics (already had distribution data)\n";
echo "  Total: " . count($metrics) . " metrics\n";
echo "============================================================\n\n";

echo "Distribution by dimension:\n";
foreach ($dimension_counts as $dimension => $count) {
  echo "  {$dimension}: {$count} bell curves\n";
}
echo "\n";
