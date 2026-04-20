#!/bin/bash

cd /home/keithaumiller/forseti.life/sites/forseti

success=0
fail=0

echo "Running 50 consecutive enrollment tests..."
echo ""

for i in {1..50}; do
  printf "Test %2d/50: " "$i"
  
  result=$(vendor/bin/drush php:eval "\$c = \Drupal::service('controller_resolver')->getControllerFromDefinition('\Drupal\nfr\Controller\NFRValidationController::testFullEnrollmentFlow'); \$r = call_user_func(\$c); \$d = json_decode(\$r->getContent(), true); echo \$d['success'] ? '1' : '0';" 2>/dev/null | tail -1)
  
  if [ "$result" = "1" ]; then
    echo "✅ PASS"
    ((success++))
  else
    echo "❌ FAIL"
    ((fail++))
  fi
done

echo ""
echo "============================================================"
echo "FINAL RESULTS"
echo "============================================================"
echo "Total Tests:  50"
echo "Passed:       $success ($(( success * 100 / 50 ))%)"
echo "Failed:       $fail ($(( fail * 100 / 50 ))%)"
echo "============================================================"
