<?php
/**
 * REST API Authentication Test Script
 * Tests Drupal REST API with Basic Authentication for mobile app
 */

// Container IP for local testing
$base_url = 'http://100.115.92.198';

echo "=== Drupal REST API Authentication Test ===\n\n";

// Test 1: Check JSON:API root endpoint (no auth required)
echo "Test 1: JSON:API Root Endpoint\n";
echo "GET {$base_url}/jsonapi\n";

$ch = curl_init("{$base_url}/jsonapi");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Status: {$http_code}\n";
if ($http_code == 200) {
    echo "✅ JSON:API is accessible\n";
} else {
    echo "❌ JSON:API failed\n";
}
echo "\n";

// Test 2: Check CORS headers
echo "Test 2: CORS Configuration\n";
echo "OPTIONS {$base_url}/jsonapi\n";

$ch = curl_init("{$base_url}/jsonapi");
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "OPTIONS");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Origin: http://localhost:19006',
    'Access-Control-Request-Method: GET',
    'Access-Control-Request-Headers: Authorization, Content-Type'
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Status: {$http_code}\n";
if (strpos($response, 'Access-Control-Allow-Origin') !== false) {
    echo "✅ CORS headers present\n";
} else {
    echo "❌ CORS headers missing\n";
}
echo "\n";

// Test 3: Test Basic Authentication with user endpoint
echo "Test 3: Basic Authentication\n";
$username = 'testuser';
$password = 'testpass123';
echo "GET {$base_url}/jsonapi/user/user (with Basic Auth)\n";
echo "Username: {$username}\n";

$ch = curl_init("{$base_url}/jsonapi/user/user");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Basic ' . base64_encode("{$username}:{$password}"),
    'Content-Type: application/vnd.api+json',
    'Accept: application/vnd.api+json'
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Status: {$http_code}\n";
if ($http_code == 200) {
    echo "✅ Basic Auth working\n";
} elseif ($http_code == 401) {
    echo "⚠️  Authentication failed - user may not exist\n";
} else {
    echo "❌ Unexpected response\n";
}
echo "\n";

// Test 4: User login endpoint
echo "Test 4: User Login Endpoint\n";
echo "GET {$base_url}/user/login\n";

$ch = curl_init("{$base_url}/user/login");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Status: {$http_code}\n";
if ($http_code == 200) {
    echo "✅ Login endpoint accessible\n";
} else {
    echo "⚠️  Status {$http_code}\n";
}
echo "\n";

echo "=== Test Complete ===\n\n";
echo "Next Steps:\n";
echo "1. Create test user: drush user:create testuser --mail=\"test@example.com\" --password=\"testpass123\"\n";
echo "2. Verify REST UI module: drush pm:enable restui\n";
echo "3. Check services.yml CORS settings in web/sites/default/services.yml\n";
echo "4. Test from React Native with fetch() using Authorization header\n";
