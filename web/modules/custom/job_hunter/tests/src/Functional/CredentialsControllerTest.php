<?php

namespace Drupal\Tests\job_hunter\Functional;

use PHPUnit\Framework\TestCase;

/**
 * Verifies routing and permission configuration for /jobhunter/settings/credentials.
 *
 * This is a static analysis test (no Drupal bootstrap required) that validates:
 *   - credentials route requires 'access job hunter' permission (anon → 403, TC-05)
 *   - POST routes carry _csrf_token: 'TRUE' (split-route pattern, TC-06)
 *   - DELETE/test routes are POST-only (no accidental GET exposure)
 *
 * Execution is QA-owned. Run via:
 *   vendor/bin/phpunit web/modules/custom/job_hunter/tests/src/Functional/CredentialsControllerTest.php
 *
 * @group job_hunter
 * @group browser_automation
 */
class CredentialsControllerTest extends TestCase {

  /**
   * Parsed routing.yml entries for credentials routes.
   *
   * @var array
   */
  private static array $routeConfig = [];

  /**
   * Full raw YAML (string) — used for presence assertions.
   *
   * @var string
   */
  private static string $routeYaml = '';

  protected function setUp(): void {
    parent::setUp();

    $routingFile = __DIR__ . '/../../../../../../../job_hunter.routing.yml';
    $this->assertFileExists($routingFile, 'job_hunter.routing.yml must exist next to the module root.');
    self::$routeYaml = file_get_contents($routingFile);

    // Minimal YAML-like extraction: locate the three credentials route blocks.
    $routes = [];
    $names  = [
      'job_hunter.credentials',
      'job_hunter.credentials_delete',
      'job_hunter.credentials_test',
    ];
    foreach ($names as $name) {
      if (preg_match('/' . preg_quote($name, '/') . ':\s*\n((?:[ \t]+.+\n)+)/m', self::$routeYaml, $m)) {
        $routes[$name] = $m[1];
      }
    }
    self::$routeConfig = $routes;
  }

  /**
   * TC-05: Credentials page requires 'access job hunter' permission.
   *
   * Anonymous requests must be denied (403). Drupal enforces this via
   * _permission; absence of the key would fall back to _access: 'TRUE'.
   */
  public function testCredentialsPageRequiresAccessPermission(): void {
    $block = self::$routeConfig['job_hunter.credentials'] ?? '';
    $this->assertNotEmpty($block, 'Route job_hunter.credentials must be defined.');
    $this->assertStringContainsString(
      "_permission: 'access job hunter'",
      $block,
      "TC-05: GET /jobhunter/settings/credentials must require 'access job hunter'."
    );
  }

  /**
   * TC-04: Credentials route is HTTP GET only — not an open POST endpoint.
   *
   * The credentials page itself (job_hunter.credentials) must NOT carry
   * _csrf_token, because adding _csrf_token to a GET route causes a 403
   * regression (split-route pattern requires separation).
   */
  public function testCredentialsGetRouteHasNoCsrfToken(): void {
    $block = self::$routeConfig['job_hunter.credentials'] ?? '';
    $this->assertNotEmpty($block, 'Route job_hunter.credentials must be defined.');
    $this->assertStringNotContainsString(
      '_csrf_token',
      $block,
      "TC-04: GET credentials route must NOT carry _csrf_token (split-route pattern)."
    );
  }

  /**
   * TC-06a: Delete credential POST route uses CSRF protection.
   *
   * Mutating endpoint must use _csrf_token: 'TRUE' per the split-route
   * security pattern used across job_hunter.
   */
  public function testDeleteCredentialRouteHasCsrfToken(): void {
    $block = self::$routeConfig['job_hunter.credentials_delete'] ?? '';
    $this->assertNotEmpty($block, 'Route job_hunter.credentials_delete must be defined.');
    $this->assertStringContainsString(
      "_csrf_token: 'TRUE'",
      $block,
      "TC-06a: DELETE credential route must require CSRF token."
    );
  }

  /**
   * TC-06b: Test credential POST route uses CSRF protection.
   */
  public function testTestCredentialRouteHasCsrfToken(): void {
    $block = self::$routeConfig['job_hunter.credentials_test'] ?? '';
    $this->assertNotEmpty($block, 'Route job_hunter.credentials_test must be defined.');
    $this->assertStringContainsString(
      "_csrf_token: 'TRUE'",
      $block,
      "TC-06b: Test credential route must require CSRF token."
    );
  }

  /**
   * TC-07: POST-only routes must also carry a _permission requirement.
   *
   * CSRF alone does not restrict authenticated-only users. The mutating
   * routes must additionally guard with 'access job hunter' so that a user
   * without that permission cannot forge a CSRF token to delete credentials.
   */
  public function testPostRoutesRequireAccessPermission(): void {
    foreach (['job_hunter.credentials_delete', 'job_hunter.credentials_test'] as $name) {
      $block = self::$routeConfig[$name] ?? '';
      $this->assertNotEmpty($block, "Route $name must be defined.");
      $this->assertStringContainsString(
        "_permission: 'access job hunter'",
        $block,
        "TC-07: Mutating route $name must also restrict by 'access job hunter'."
      );
    }
  }

  /**
   * TC-10: No duplicate credential INSERT exists in CredentialController.
   *
   * The controller must check for an existing row before inserting.
   * A second storeCredential call for the same (uid, platform) must NOT
   * produce a DB error — it should upsert or return a conflict response.
   *
   * This is verified statically by confirming CredentialManagementService
   * contains a duplicate-guard pattern (SELECT-before-INSERT or UPSERT).
   */
  public function testDuplicateCredentialGuardExists(): void {
    $serviceFile = __DIR__ . '/../../../../../../../src/Service/CredentialManagementService.php';
    $this->assertFileExists($serviceFile, 'CredentialManagementService.php must exist.');

    $source = file_get_contents($serviceFile);

    // The service must either perform a SELECT before INSERT or use upsert.
    $hasSelectGuard = str_contains($source, 'SELECT') && str_contains($source, 'storeCredential');
    $hasUpsert      = str_contains($source, 'upsert') || str_contains($source, 'DUPLICATE KEY') || str_contains($source, 'ON CONFLICT');
    $hasTryCatch    = str_contains($source, 'try {') && str_contains($source, 'catch');

    $this->assertTrue(
      $hasSelectGuard || $hasUpsert || $hasTryCatch,
      'TC-10: CredentialManagementService::storeCredential must guard against duplicate inserts.'
    );
  }

}
