#!/usr/bin/env php
<?php

/**
 * Direct Resume Parsing Test Script
 * Bypasses Drupal and Drush overhead for instant feedback
 */

// Get arguments
if ($argc < 2) {
  echo "❌ Usage: php fast-parse.php <file_id> [--user-id=UID] [--force]\n";
  echo "\n   Example: php fast-parse.php 1\n";
  echo "            php fast-parse.php 2 --user-id=10\n";
  echo "            php fast-parse.php 3 --force\n";
  exit(1);
}

$file_id = $argv[1];
$uid = NULL;
$force = FALSE;

// Parse additional arguments
for ($i = 2; $i < $argc; $i++) {
  if (str_starts_with($argv[$i], '--user-id=')) {
    $uid = (int)substr($argv[$i], 10);
  } elseif ($argv[$i] === '--force') {
    $force = TRUE;
  }
}

// Setup Drupal (Drupal 9+ style)
require_once __DIR__ . '/web/index.php';

// Get Drupal services
$container = \Drupal::getContainer();

echo "\n=== 🚀 Direct Resume Parser (Drupal Fast Path) ===\n\n";

try {
  // Get the command service via container
  $command = $container->get('job_hunter.resume_parse_command');
  
  if (!$command) {
    throw new Exception('Failed to load job_hunter.resume_parse_command service from container');
  }
  
  // Configure options
  $options = ['user-id' => $uid, 'force' => $force];
  
  echo "📄 File ID: $file_id\n";
  if ($uid) echo "👤 User ID: $uid\n";
  if ($force) echo "🔄 Force re-parsing enabled\n";
  echo "\n";
  
  // Execute the parsing command
  $result = $command->parseResume($file_id, $options);
  
  echo "\n✅ Parsing complete!\n";
  exit($result || 0);
  
} catch (\Exception $e) {
  echo "❌ Error: " . $e->getMessage() . "\n\n";
  echo "   Debug Info:\n";
  echo "   File: " . $e->getFile() . "\n";
  echo "   Line: " . $e->getLine() . "\n";
  echo "\n   Stack Trace:\n";
  $lines = explode("\n", $e->getTraceAsString());
  foreach (array_slice($lines, 0, 5) as $line) {
    echo "   " . trim($line) . "\n";
  }
  exit(1);
}

