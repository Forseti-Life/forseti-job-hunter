<?php

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

// Bootstrap Drupal
$autoloader = require_once 'vendor/autoload.php';
$kernel = new DrupalKernel('prod', $autoloader);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$kernel->boot();

// Get the form display configuration
$config = \Drupal::configFactory()->getEditable('core.entity_form_display.user.user.default');

// Get current content configuration
$content = $config->get('content') ?: [];

# Add the field_primary_resume_text with proper configuration
$content['field_primary_resume_text'] = [
  'type' => 'text_textarea',
  'weight' => 25,
  'region' => 'content', 
  'settings' => [],
  'third_party_settings' => [],
];

// Update the configuration
$config->set('content', $content);

// Remove from hidden if it exists
$hidden = $config->get('hidden') ?: [];
if (isset($hidden['field_primary_resume_text'])) {
  unset($hidden['field_primary_resume_text']);
  $config->set('hidden', $hidden);
}

// Save the configuration
$config->save();

echo "Field display configuration updated successfully.\n";