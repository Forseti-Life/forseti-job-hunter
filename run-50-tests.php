<?php

/**
 * Run enrollment test 50 times
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'autoload.php';
$kernel = new DrupalKernel('prod', $autoloader);
$request = Request::createFromGlobals();
$kernel->boot();
$kernel->prepareLegacyRequest($request);

$controller = \Drupal::service('controller_resolver')->getControllerFromDefinition('\Drupal\nfr\Controller\NFRValidationController::testFullEnrollmentFlow');

$success_count = 0;
$fail_count = 0;
$failures = [];

for ($i = 1; $i <= 50; $i++) {
  echo "Running test $i/50...\n";
  $response = call_user_func($controller);
  $result = json_decode($response->getContent(), true);
  
  if ($result['success']) {
    $success_count++;
    echo "  ✅ PASS\n";
  } else {
    $fail_count++;
    echo "  ❌ FAIL\n";
    $failures[] = [
      'test_num' => $i,
      'errors' => $result['errors'] ?? [],
      'steps' => $result['steps'] ?? []
    ];
  }
  
  // Small delay
  usleep(100000);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "FINAL RESULTS:\n";
echo str_repeat('=', 60) . "\n";
echo "Total Tests: 50\n";
echo "Passed: $success_count (" . ($success_count/50*100) . "%)\n";
echo "Failed: $fail_count (" . ($fail_count/50*100) . "%)\n";

if ($fail_count > 0) {
  echo "\nFAILURE DETAILS:\n";
  foreach ($failures as $failure) {
    echo "\nTest #{$failure['test_num']}:\n";
    if (!empty($failure['errors'])) {
      foreach ($failure['errors'] as $error) {
        echo "  - $error\n";
      }
    }
  }
}
