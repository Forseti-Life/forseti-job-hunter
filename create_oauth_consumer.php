<?php

/**
 * Create OAuth Consumer for AmISafe Mobile App
 * Run with: vendor/bin/drush php:script create_oauth_consumer.php
 */

// Create OAuth Consumer for AmISafe Mobile App
$consumer_storage = \Drupal::entityTypeManager()->getStorage('consumer');

// Check if consumer already exists
$existing_consumers = $consumer_storage->loadByProperties(['client_id' => 'amisafe_mobile']);

if (empty($existing_consumers)) {
  try {
    $consumer = $consumer_storage->create([
      'label' => 'AmISafe Mobile App',
      'description' => 'OAuth consumer for AmISafe mobile application user authentication and profile management',
      'client_id' => 'amisafe_mobile',
      'is_default' => FALSE,
      'confidential' => FALSE, // Public client for mobile app
      'user_id' => 1, // Associate with admin user
    ]);
    
    $consumer->save();
    
    echo "✅ SUCCESS: OAuth Consumer 'AmISafe Mobile App' created!\n";
    echo "📱 Client ID: amisafe_mobile\n";
    echo "🔒 Type: Public (non-confidential) - perfect for mobile apps\n";
    echo "🎯 Consumer UUID: " . $consumer->uuid() . "\n\n";
    
    // Display the consumer details
    echo "🔧 Consumer Configuration:\n";
    echo "   - Label: " . $consumer->label() . "\n";
    echo "   - Client ID: " . $consumer->getClientId() . "\n";
    echo "   - UUID: " . $consumer->uuid() . "\n";
    echo "   - Confidential: " . ($consumer->isConfidential() ? 'Yes' : 'No') . "\n";
    echo "   - Default: " . ($consumer->isDefault() ? 'Yes' : 'No') . "\n\n";
    
  } catch (Exception $e) {
    echo "❌ ERROR creating OAuth consumer: " . $e->getMessage() . "\n";
    echo "🔍 This might happen if the consumer entity type isn't properly configured.\n";
  }
} else {
  $existing_consumer = reset($existing_consumers);
  echo "ℹ️  OAuth Consumer 'AmISafe Mobile App' already exists!\n";
  echo "📱 Client ID: " . $existing_consumer->getClientId() . "\n";
  echo "🎯 Consumer UUID: " . $existing_consumer->uuid() . "\n";
}

echo "\n🚀 Your mobile app can now authenticate using:\n";
echo "   Endpoint: POST /oauth/token\n";
echo "   Client ID: amisafe_mobile\n";
echo "   Grant Type: password\n";
echo "   Example request:\n";
echo "   {\n";
echo "     \"grant_type\": \"password\",\n";
echo "     \"client_id\": \"amisafe_mobile\",\n";
echo "     \"username\": \"user@example.com\",\n";
echo "     \"password\": \"userpassword\"\n";
echo "   }\n\n";

// Verify Simple OAuth configuration
$oauth_config = \Drupal::config('simple_oauth.settings');
echo "🔑 OAuth Configuration Status:\n";
echo "   - Public Key: " . ($oauth_config->get('public_key') ?: 'Not configured') . "\n";
echo "   - Private Key: " . ($oauth_config->get('private_key') ?: 'Not configured') . "\n";
echo "   - Access Token Expiration: " . ($oauth_config->get('access_token_expiration') ?: '3600') . " seconds\n";
echo "   - Refresh Token Expiration: " . ($oauth_config->get('refresh_token_expiration') ?: '1209600') . " seconds\n\n";

echo "🎉 AmISafe Mobile App Authentication Setup Complete!\n";