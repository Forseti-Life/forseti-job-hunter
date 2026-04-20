<?php

/**
 * Setup script for AmISafe Mobile App OAuth Consumer
 */

use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'vendor/autoload.php';

$kernel = new DrupalKernel('prod', $autoloader);
$request = Request::createFromGlobals();
Settings::initialize(dirname(__FILE__), DrupalKernel::findSitePath($request), $autoloader);
$kernel->boot();
$kernel->preHandle($request);

// Create OAuth Consumer for AmISafe Mobile App
$consumer_storage = \Drupal::entityTypeManager()->getStorage('consumer');

// Check if consumer already exists
$existing_consumers = $consumer_storage->loadByProperties(['label' => 'AmISafe Mobile App']);

if (empty($existing_consumers)) {
  $consumer = $consumer_storage->create([
    'label' => 'AmISafe Mobile App',
    'description' => 'OAuth consumer for AmISafe mobile application user authentication',
    'client_id' => 'amisafe_mobile',
    'is_default' => TRUE,
    'grant_types' => ['password', 'refresh_token'],
    'scopes' => ['basic_auth'],
    'redirect' => '',
    'confidential' => FALSE, // Public client for mobile app
  ]);
  
  $consumer->save();
  
  echo "✅ OAuth Consumer 'AmISafe Mobile App' created successfully!\n";
  echo "Client ID: amisafe_mobile\n";
  echo "Grant Types: password, refresh_token\n";
  echo "This consumer can now be used by your React Native app for authentication.\n\n";
} else {
  echo "ℹ️  OAuth Consumer 'AmISafe Mobile App' already exists.\n";
}

// Configure Simple OAuth settings
$config = \Drupal::configFactory()->getEditable('simple_oauth.settings');
$config->set('public_key', '../keys/public.key');
$config->set('private_key', '../keys/private.key');
$config->set('access_token_expiration', 3600); // 1 hour
$config->set('refresh_token_expiration', 2419200); // 4 weeks
$config->save();

echo "✅ OAuth keys configured:\n";
echo "Public Key: ../keys/public.key\n";
echo "Private Key: ../keys/private.key\n";
echo "Access Token Expiration: 1 hour\n";
echo "Refresh Token Expiration: 4 weeks\n\n";

// Enable CORS for JSON:API
$cors_config = \Drupal::configFactory()->getEditable('cors.config');
$cors_config->set('enabled', TRUE);
$cors_config->set('allowedHeaders', ['*']);
$cors_config->set('allowedMethods', ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS']);
$cors_config->set('allowedOrigins', ['*']); // In production, specify your mobile app domains
$cors_config->set('supportsCredentials', TRUE);
$cors_config->save();

echo "✅ CORS configured for mobile app requests\n\n";

echo "🚀 Setup Complete! Your mobile app can now:\n";
echo "1. Register users via JSON:API\n";
echo "2. Authenticate via OAuth 2.0 (POST /oauth/token)\n";
echo "3. Access user profiles via JSON:API endpoints\n";
echo "4. Make cross-origin requests from React Native\n\n";

echo "📱 Mobile App Configuration:\n";
echo "Base URL: https://forseti.com\n";
echo "OAuth Endpoint: /oauth/token\n";
echo "Client ID: amisafe_mobile\n";
echo "JSON:API Endpoints: /jsonapi/*\n";