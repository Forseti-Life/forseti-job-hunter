#!/bin/bash
# Check coding standards for custom modules and themes
echo "Checking coding standards..."

# Check custom modules
if [ -d "../web/modules/custom" ]; then
    echo "Checking custom modules..."
    ../vendor/bin/phpcs --standard=Drupal ../web/modules/custom
fi

# Check custom themes
if [ -d "../web/themes/custom" ]; then
    echo "Checking custom themes..."
    ../vendor/bin/phpcs --standard=Drupal ../web/themes/custom
fi

echo "Standards check completed!"
