<?php

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'vendor/autoload.php';
$kernel = new DrupalKernel('prod', $autoloader);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$kernel->boot();

$company = \Drupal::entityTypeManager()->getStorage('node')->load(60);
echo "Company title: " . $company->getTitle() . PHP_EOL;

// Test all field access methods
$fields = ['field_description', 'field_industry', 'field_size', 'field_website'];

foreach ($fields as $field_name) {
    echo "\n--- Testing $field_name ---" . PHP_EOL;
    
    // Check if field exists
    if ($company->hasField($field_name)) {
        echo "Field exists: YES" . PHP_EOL;
        
        $field = $company->get($field_name);
        echo "Field isEmpty: " . ($field->isEmpty() ? 'TRUE' : 'FALSE') . PHP_EOL;
        
        if (!$field->isEmpty()) {
            echo "Field type: " . get_class($field) . PHP_EOL;
            echo "Field count: " . $field->count() . PHP_EOL;
            
            // Try different access methods
            try {
                echo "field->value: " . $field->value . PHP_EOL;
            } catch (Exception $e) {
                echo "field->value ERROR: " . $e->getMessage() . PHP_EOL;
            }
            
            try {
                echo "field->getString(): " . $field->getString() . PHP_EOL;
            } catch (Exception $e) {
                echo "field->getString() ERROR: " . $e->getMessage() . PHP_EOL;
            }
            
            try {
                $first = $field->first();
                if ($first) {
                    echo "field->first()->value: " . $first->value . PHP_EOL;
                    if ($field_name === 'field_website') {
                        echo "field->first()->uri: " . $first->uri . PHP_EOL;
                    }
                }
            } catch (Exception $e) {
                echo "field->first() ERROR: " . $e->getMessage() . PHP_EOL;
            }
        }
    } else {
        echo "Field exists: NO" . PHP_EOL;
    }
}