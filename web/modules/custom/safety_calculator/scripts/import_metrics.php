<?php

/**
 * @file
 * Script to import individual metrics into database.
 * 
 * Usage: drush php:script import_metrics.php
 */

use Drupal\Core\Database\Database;

// Path to the metrics table file
$module_path = \Drupal::service('extension.list.module')->getPath('safety_calculator');
$file_path = DRUPAL_ROOT . '/' . $module_path . '/data/individual_metrics_table.md';

if (!file_exists($file_path)) {
  echo "❌ Error: Metrics table file not found: $file_path\n";
  exit(1);
}

echo "📂 Reading metrics from: $file_path\n";

$content = file_get_contents($file_path);
$lines = explode("\n", $content);

$metrics = [];

// Parse the markdown table
foreach ($lines as $line_num => $line) {
  // Skip empty lines, headers, and separators
  if (empty(trim($line)) || strpos($line, '---') === 0 || strpos($line, 'Metric Name') !== FALSE) {
    continue;
  }
  
  // Only process lines that start with pipe
  if (strpos($line, '|') === 0) {
    $parts = array_map('trim', explode('|', trim($line, '|')));
    
    // Should have 7 or 11 parts (original 7, or 7 + 4 new reference columns)
    if (count($parts) >= 7) {
      $metric_name = $parts[0];
      $metric_description = $parts[1];
      $category = $parts[2];
      $question_id = (int) $parts[3];
      $question_title = $parts[4];
      $parent_node = $parts[5] === 'None (universal)' ? NULL : $parts[5];
      $dimension = $parts[6];
      
      // Extract new reference fields if present
      $reference_url = (count($parts) >= 8 && !empty($parts[7])) ? $parts[7] : NULL;
      $population_definition = (count($parts) >= 9 && !empty($parts[8])) ? $parts[8] : NULL;
      $population_mean = (count($parts) >= 10 && !empty($parts[9]) && is_numeric($parts[9])) ? (float) $parts[9] : NULL;
      $population_stddev = (count($parts) >= 11 && !empty($parts[10]) && is_numeric($parts[10])) ? (float) $parts[10] : NULL;
      
      // Skip if no question ID
      if ($question_id === 0 || empty($metric_name)) {
        continue;
      }
      
      // Determine data type
      $data_type = guess_data_type($metric_description);
      
      // Build validation rules
      $validation_rules = build_validation_rules($metric_description, $data_type);
      
      $metrics[] = [
        'question_id' => $question_id,
        'metric_name' => sanitize_metric_name($metric_name),
        'metric_description' => $metric_description,
        'category' => $category,
        'dimension' => $dimension,
        'parent_node' => $parent_node,
        'data_type' => $data_type,
        'validation_rules' => $validation_rules ? json_encode($validation_rules) : NULL,
        'reference_url' => $reference_url,
        'population_definition' => $population_definition,
        'population_mean' => $population_mean,
        'population_stddev' => $population_stddev,
        'created' => time(),
      ];
    }
  }
}

echo "✅ Parsed " . count($metrics) . " metrics from file.\n\n";

// Get database connection
$database = Database::getConnection();

// Clear existing metrics
echo "🗑️  Clearing existing metrics...\n";
$database->truncate('individual_metrics_master')->execute();

// Insert metrics in batches
$batch_size = 100;
$batches = array_chunk($metrics, $batch_size);
$imported = 0;
$skipped = 0;

echo "💾 Importing metrics in batches...\n";

foreach ($batches as $batch_num => $batch) {
  $query = $database->insert('individual_metrics_master')
    ->fields([
      'question_id',
      'metric_name',
      'metric_description',
      'category',
      'dimension',
      'parent_node',
      'data_type',
      'validation_rules',
      'reference_url',
      'population_definition',
      'population_mean',
      'population_stddev',
      'created',
    ]);
  
  foreach ($batch as $metric) {
    $query->values($metric);
  }
  
  try {
    $query->execute();
    $imported += count($batch);
    echo ".";
  }
  catch (\Exception $e) {
    echo "❌ Error importing batch " . ($batch_num + 1) . ": " . $e->getMessage() . "\n";
    $skipped += count($batch);
  }
}

echo "\n\n✅ Import complete: $imported metrics imported, $skipped skipped.\n\n";

// Verify counts by dimension
echo "📊 Metrics by dimension:\n";
$result = $database->query("
  SELECT dimension, COUNT(*) as count 
  FROM {individual_metrics_master} 
  GROUP BY dimension 
  ORDER BY dimension
");

foreach ($result as $row) {
  echo sprintf("   %s: %d metrics\n", str_pad($row->dimension, 12), $row->count);
}

// Verify counts by question
$total_questions = $database->query("
  SELECT COUNT(DISTINCT question_id) as count 
  FROM {individual_metrics_master}
")->fetchField();

echo "\n📝 Total questions: $total_questions\n";

// Helper functions
function sanitize_metric_name($name) {
  return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $name));
}

function guess_data_type($description) {
  $lower = strtolower($description);
  
  if (strpos($lower, 'yes/no') !== FALSE) {
    return 'boolean';
  }
  
  if (preg_match('/(scale|rating)\s+(\d+)-(\d+)/i', $lower) || preg_match('/\(0-10\)|\(0-100\)|\(1-5\)/i', $lower)) {
    return 'scale';
  }
  
  if (preg_match('/\b(number|count|age|years|months|days|hours|dollars|percentage|miles|feet|square|times|frequency)\b/i', $lower)) {
    return 'numeric';
  }
  
  if (preg_match('/(never|rarely|sometimes|often|always)/i', $lower) || preg_match('/:|;|,.*,/i', $description)) {
    return 'select';
  }
  
  return 'text';
}

function build_validation_rules($description, $data_type) {
  $rules = [];
  
  if ($data_type === 'numeric' && preg_match('/(\d+)-(\d+)/', $description, $matches)) {
    $rules['min'] = (int) $matches[1];
    $rules['max'] = (int) $matches[2];
  }
  
  if ($data_type === 'scale' && preg_match('/\((\d+)-(\d+)\)/', $description, $matches)) {
    $rules['min'] = (int) $matches[1];
    $rules['max'] = (int) $matches[2];
    $rules['step'] = 1;
  }
  
  if ($data_type === 'boolean') {
    $rules['type'] = 'boolean';
  }
  
  return !empty($rules) ? $rules : NULL;
}

echo "\n🎉 Import complete!\n";
