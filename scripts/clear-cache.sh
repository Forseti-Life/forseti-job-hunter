#!/bin/bash
# Clear Drupal cache
echo "Clearing Drupal cache..."
../vendor/bin/drush cache:rebuild
echo "Cache cleared successfully!"
