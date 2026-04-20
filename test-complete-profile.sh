#!/bin/bash

# Complete End-to-End Profile Generation Test
# Uploads resume, creates profile, extracts text, parses with GenAI, and verifies

cd /home/keithaumiller/forseti.life/sites/forseti

echo "================================================================="
echo "COMPLETE END-TO-END PROFILE GENERATION TEST"
echo "================================================================="
echo ""

# Step 1: Check if resume file exists
RESUME_PATH="/mnt/chromeos/MyFiles/Downloads/KeithAumillerA.pdf"
if [ ! -f "$RESUME_PATH" ]; then
  echo "❌ ERROR: Resume file not found at $RESUME_PATH"
  exit 1
fi

echo "Step 1: Resume file found"
ls -lh "$RESUME_PATH"
echo ""

# Step 2: Run the complete test script
echo "Step 2: Running complete profile generation test..."
echo ""

./vendor/bin/drush php:script test-complete-profile-generation.php

echo ""
echo "================================================================="
echo "Test complete!"
echo "================================================================="
