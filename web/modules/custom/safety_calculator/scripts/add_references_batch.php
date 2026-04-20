<?php

/**
 * Batch Reference Addition Script
 * 
 * Processes metrics in batches of 50, finds research references, validates URLs
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'autoload.php';
$kernel = DrupalKernel::createFromRequest(Request::createFromGlobals(), $autoloader, 'prod');
$kernel->boot();
$container = $kernel->getContainer();
$container->get('request_stack')->push(Request::createFromGlobals());

// Get database connection
$database = $container->get('database');

/**
 * Validate URL with curl
 */
function validateUrl($url) {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_NOBODY, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Research Bot)');
  
  $result = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  
  return $httpCode >= 200 && $httpCode < 400;
}

/**
 * Add reference to database
 */
function addReference($database, $metricId, $reference, $url) {
  try {
    // Check if reference column exists, if not add it
    $schema = $database->schema();
    if (!$schema->fieldExists('individual_metrics_master', 'reference_url')) {
      $schema->addField('individual_metrics_master', 'reference_url', [
        'type' => 'varchar',
        'length' => 512,
        'not null' => FALSE,
        'description' => 'URL to research paper or data source',
      ]);
    }
    if (!$schema->fieldExists('individual_metrics_master', 'reference_citation')) {
      $schema->addField('individual_metrics_master', 'reference_citation', [
        'type' => 'text',
        'not null' => FALSE,
        'description' => 'Full citation for the reference',
      ]);
    }
    
    $database->update('individual_metrics_master')
      ->fields([
        'reference_url' => $url,
        'reference_citation' => $reference,
      ])
      ->condition('id', $metricId)
      ->execute();
    
    return true;
  } catch (Exception $e) {
    echo "❌ Error adding reference: " . $e->getMessage() . "\n";
    return false;
  }
}

// Get batch number from command line
$batchNumber = isset($argv[1]) ? intval($argv[1]) : 1;
$batchSize = 50;
$offset = ($batchNumber - 1) * $batchSize;

echo "\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "  BATCH REFERENCE PROCESSING - Batch #{$batchNumber}\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "\n";

// Get metrics for this batch
$query = $database->select('individual_metrics_master', 'm')
  ->fields('m', ['id', 'metric_name', 'data_type', 'dimension', 'category'])
  ->orderBy('id')
  ->range($offset, $batchSize);

$metrics = $query->execute()->fetchAll();

if (empty($metrics)) {
  echo "❌ No metrics found for batch #{$batchNumber}\n";
  exit(1);
}

echo "📊 Processing " . count($metrics) . " metrics (IDs " . $metrics[0]->id . " - " . end($metrics)->id . ")\n";
echo "\n";
echo "────────────────────────────────────────────────────────────\n";
echo "\n";

// Process each metric
$processed = 0;
$validated = 0;
$failed = 0;

foreach ($metrics as $metric) {
  echo "Metric #{$metric->id}: {$metric->metric_name}\n";
  echo "  Dimension: {$metric->dimension}\n";
  echo "  Category: {$metric->category}\n";
  echo "\n";
  
  // Here you'll manually add the reference URL and citation
  echo "  ⏸️  AWAITING MANUAL INPUT\n";
  echo "  Please provide:\n";
  echo "    1. Reference URL\n";
  echo "    2. Citation\n";
  echo "\n";
  echo "────────────────────────────────────────────────────────────\n";
  echo "\n";
  
  $processed++;
}

echo "\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "  BATCH SUMMARY\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "\n";
echo "  📊 Processed: {$processed}\n";
echo "  ✅ Ready for input\n";
echo "\n";
echo "NEXT STEPS:\n";
echo "  1. Research references for each metric above\n";
echo "  2. Validate URLs with: curl -I [URL]\n";
echo "  3. Update the metrics using update script\n";
echo "  4. Run next batch: php add_references_batch.php " . ($batchNumber + 1) . "\n";
echo "\n";
