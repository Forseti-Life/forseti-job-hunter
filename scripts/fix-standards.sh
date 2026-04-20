#!/bin/bash
# Fix coding standards for custom modules and themes
echo "Fixing coding standards..."

# Fix custom modules
if [ -d "../web/modules/custom" ]; then
    echo "Fixing custom modules..."
    ../vendor/bin/phpcbf --standard=Drupal ../web/modules/custom
fi

# Fix custom themes
if [ -d "../web/themes/custom" ]; then
    echo "Fixing custom themes..."
    ../vendor/bin/phpcbf --standard=Drupal ../web/themes/custom
fi

echo "Standards fixing completed!"
