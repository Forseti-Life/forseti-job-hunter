#!/bin/bash

# Re-parse Resume with Production GenAI Extraction
# Uses Drush to execute PHP code with proper Drupal bootstrap

cd /home/keithaumiller/forseti.life/sites/forseti

echo "================================================================="
echo "Re-parsing Resume with Production GenAI Extraction"
echo "================================================================="
echo ""

./vendor/bin/drush php:script reparse-resume-drush.php
