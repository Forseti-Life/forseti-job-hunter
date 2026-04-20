#!/usr/bin/env php
<?php

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'web/autoload.php';
$kernel = new DrupalKernel('prod', $autoloader, FALSE);
$request = Request::createFromGlobals();
$kernel->setSitePath('sites/forseti');
$kernel->boot();
$kernel->prepareLegacyRequest($request);

echo "Testing Fill Rates Data Collection\n";
echo str_repeat('=', 70) . "\n\n";

$connection = \Drupal::database();

// Test each table query
$tables = [
  'nfr_user_profile' => 'Profile records',
  'nfr_questionnaire' => 'Questionnaire records',
  'nfr_work_history' => 'Work history records',
  'nfr_job_titles' => 'Job title records',
  'nfr_major_incidents' => 'Major incident records',
  'nfr_other_employment' => 'Other employment records',
  'nfr_cancer_diagnoses' => 'Cancer diagnosis records',
  'nfr_consent' => 'Consent records',
  'nfr_section_completion' => 'Section completion records',
];

foreach ($tables as $table => $label) {
  try {
    if ($table === 'nfr_user_profile' || $table === 'nfr_questionnaire' || $table === 'nfr_work_history') {
      $count = $connection->query("SELECT COUNT(*) FROM {$table} WHERE uid > 2")->fetchField();
    } else {
      $count = $connection->query("SELECT COUNT(*) FROM {$table}")->fetchField();
    }
    printf("%-30s %6d records\n", $label, $count);
  } catch (\Exception $e) {
    printf("%-30s ERROR: %s\n", $label, $e->getMessage());
  }
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "Testing data parsing from questionnaire record...\n\n";

// Get a sample questionnaire record
$sample = $connection->query("SELECT * FROM nfr_questionnaire WHERE uid > 2 LIMIT 1")->fetch();

if ($sample) {
  echo "Sample UID: {$sample->uid}\n\n";
  
  // Test JSON parsing
  echo "Direct Columns:\n";
  echo "  military_service: " . ($sample->military_service ? 'yes' : 'no') . "\n";
  echo "  military_branch: " . ($sample->military_branch ?? 'NULL') . "\n";
  echo "  cancer_diagnosis: " . ($sample->cancer_diagnosis ? 'yes' : 'no') . "\n";
  echo "  alcohol_use: " . ($sample->alcohol_use ?? 'NULL') . "\n\n";
  
  echo "JSON Columns:\n";
  
  if (!empty($sample->data)) {
    $data = json_decode($sample->data, TRUE);
    echo "  data column: " . (is_array($data) ? "Parsed (" . count($data) . " sections)" : "FAILED") . "\n";
    if (is_array($data)) {
      if (isset($data['exposure'])) {
        echo "    - exposure section present\n";
      }
      if (isset($data['military'])) {
        echo "    - military section present\n";
      }
      if (isset($data['health'])) {
        echo "    - health section present\n";
      }
      if (isset($data['lifestyle'])) {
        echo "    - lifestyle section present\n";
      }
    }
  } else {
    echo "  data column: empty\n";
  }
  
  if (!empty($sample->ppe_practices)) {
    $ppe = json_decode($sample->ppe_practices, TRUE);
    echo "  ppe_practices: " . (is_array($ppe) ? "Parsed (" . count($ppe) . " items)" : "FAILED") . "\n";
  } else {
    echo "  ppe_practices: empty\n";
  }
  
  if (!empty($sample->decon_practices)) {
    $decon = json_decode($sample->decon_practices, TRUE);
    echo "  decon_practices: " . (is_array($decon) ? "Parsed (" . count($decon) . " items)" : "FAILED") . "\n";
  } else {
    echo "  decon_practices: empty\n";
  }
  
  if (!empty($sample->smoking_history)) {
    $smoking = json_decode($sample->smoking_history, TRUE);
    echo "  smoking_history: " . (is_array($smoking) ? "Parsed (" . count($smoking) . " items)" : "FAILED") . "\n";
  } else {
    echo "  smoking_history: empty\n";
  }
} else {
  echo "No sample data found!\n";
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "All data collection tests completed!\n";
