<?php

/**
 * @file
 * Test script for metrics import function.
 * 
 * Usage: drush php:script test_import.php
 */

use Drupal\Core\Database\Database;

echo "🧪 Testing metrics import function...\n\n";

// Include the install file
$module_path = \Drupal::service('extension.list.module')->getPath('safety_calculator');
require_once DRUPAL_ROOT . '/' . $module_path . '/safety_calculator.install';

$database = Database::getConnection();

// Check current count
$count_before = $database->select('individual_metrics_master', 'm')
  ->countQuery()
  ->execute()
  ->fetchField();

echo "📊 Metrics in database before: $count_before\n";

// Test 1: Import when data already exists (should skip gracefully)
echo "\n🔬 Test 1: Import with existing data\n";
_safety_calculator_import_metrics_data();

$count_after = $database->select('individual_metrics_master', 'm')
  ->countQuery()
  ->execute()
  ->fetchField();

echo "📊 Metrics in database after: $count_after\n";

if ($count_after > $count_before) {
  echo "❌ FAIL: Import added duplicate data!\n";
}
else {
  echo "✅ PASS: Import handled existing data correctly\n";
}

// Test 2: Verify data integrity
echo "\n🔬 Test 2: Data integrity check\n";
$sample = $database->select('individual_metrics_master', 'm')
  ->fields('m')
  ->range(0, 1)
  ->execute()
  ->fetchAssoc();

if ($sample && isset($sample['reference_url']) && isset($sample['population_mean'])) {
  echo "✅ PASS: Reference columns exist and populated\n";
  echo "   Sample metric: {$sample['metric_name']}\n";
  echo "   Reference: {$sample['reference_url']}\n";
  echo "   Population: {$sample['population_definition']}\n";
  echo "   Mean: {$sample['population_mean']}\n";
}
else {
  echo "❌ FAIL: Reference columns missing or not populated\n";
}

// Test 3: Check SQL file exists
echo "\n🔬 Test 3: SQL file check\n";
$sql_file = DRUPAL_ROOT . '/' . $module_path . '/data/individual_metrics_master.sql';
if (file_exists($sql_file)) {
  $size = filesize($sql_file);
  $lines = count(file($sql_file));
  echo "✅ PASS: SQL file exists\n";
  echo "   Path: $sql_file\n";
  echo "   Size: " . number_format($size) . " bytes\n";
  echo "   Lines: $lines\n";
}
else {
  echo "❌ FAIL: SQL file not found at $sql_file\n";
}

echo "\n🎉 Import function tests complete!\n";
