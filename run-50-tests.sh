#!/bin/bash

cd /home/keithaumiller/forseti.life/sites/forseti

success_count=0
fail_count=0

echo "Running 50 consecutive random enrollment tests..."
echo ""

for i in {1..50}; do
  echo -n "Test $i/50... "
  
  result=$(vendor/bin/drush php:eval "
    \$controller = \Drupal::service('controller_resolver')->getControllerFromDefinition('\Drupal\nfr\Controller\NFRValidationController::testFullEnrollmentFlow');
    \$response = call_user_func(\$controller);
    \$result = json_decode(\$response->getContent(), true);
    echo \$result['success'] ? '1' : '0';
  " 2>&1 | tail -1)
  
  if [ "$result" == "1" ]; then
    echo "✅ PASS"
    ((success_count++))
  else
    echo "❌ FAIL"
    ((fail_count++))
  fi
  
  sleep 0.1
done

echo ""
echo "============================================================"
echo "FINAL RESULTS:"
echo "============================================================"
echo "Total Tests: 50"
echo "Passed: $success_count ($((success_count * 100 / 50))%)"
echo "Failed: $fail_count ($((fail_count * 100 / 50))%)"
