<?php

/**
 * @file
 * Script to calculate z-score normalized values for all non-demographic metrics.
 * 
 * Run via: drush php:script calculate_normalized_values.php
 */

use Drupal\Core\Database\Database;

$database = Database::getConnection();

// Get all non-demographic metrics with mean and stddev
$query = $database->select('individual_metrics_master', 'm')
  ->fields('m', ['id', 'metric_name', 'dimension', 'population_mean', 'population_stddev'])
  ->condition('dimension', 'DEMOGRAPHIC', '<>')
  ->condition('population_mean', NULL, 'IS NOT NULL')
  ->condition('population_stddev', NULL, 'IS NOT NULL')
  ->condition('population_stddev', 0, '>');

$metrics = $query->execute()->fetchAll();

$updated = 0;
$skipped = 0;

echo "Processing " . count($metrics) . " non-demographic metrics...\n";

foreach ($metrics as $metric) {
  // At population mean, z-score = 0
  // normalized = 50 + (0 * 16.67) = 50
  // This represents the "average" on a 0-100 scale
  $normalized_mean = 50.0;
  
  // Update the record
  $database->update('individual_metrics_master')
    ->fields(['normalized_mean' => $normalized_mean])
    ->condition('id', $metric->id)
    ->execute();
  
  $updated++;
  
  if ($updated % 100 == 0) {
    echo "  Processed {$updated} metrics...\n";
  }
}

echo "\nComplete!\n";
echo "  Updated: {$updated} metrics\n";
echo "  Skipped: {$skipped} metrics (missing data)\n";

// Verify a few samples
echo "\nSample normalized values:\n";
$samples = $database->select('individual_metrics_master', 'm')
  ->fields('m', ['metric_name', 'dimension', 'population_mean', 'population_stddev', 'normalized_mean'])
  ->condition('dimension', 'SAFE')
  ->condition('normalized_mean', NULL, 'IS NOT NULL')
  ->range(0, 5)
  ->execute()
  ->fetchAll();

foreach ($samples as $sample) {
  echo sprintf(
    "  %s [%s]: mean=%.2f, stddev=%.2f, normalized=%.2f\n",
    substr($sample->metric_name, 0, 40),
    $sample->dimension,
    $sample->population_mean,
    $sample->population_stddev,
    $sample->normalized_mean
  );
}
