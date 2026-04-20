<?php

namespace Drupal\Tests\job_hunter\Functional\Controller;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional smoke tests for application submission route access control.
 *
 * Verifies that the five wizard-flow application submission GET routes:
 *   - return HTTP 200 for authenticated users with 'access job hunter'
 *   - return HTTP 403 for anonymous users
 *
 * A non-existent job_id (99999) is used so controllers reach the graceful
 * "job not found" render path (HTTP 200) without requiring fixture data.
 *
 * @group job_hunter
 */
class ApplicationSubmissionRouteTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'starterkit_theme';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'user',
    'field',
    'text',
    'file',
    'datetime',
    'options',
    'system',
    'views',
    'job_hunter',
  ];

  /**
   * Authenticated user with 'access job hunter' permission.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $authenticatedUser;

  /**
   * Non-existent job_id; controllers return graceful 200 (not found).
   */
  private const JOB_ID = 99999;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->authenticatedUser = $this->drupalCreateUser(['access job hunter']);
  }

  // ── Authenticated user — expect HTTP 200 ──────────────────────────────────

  /**
   * Authenticated user: step2 resolve-redirect-chain → 200.
   */
  public function testStep2AllowsAuthenticatedUser(): void {
    $this->drupalLogin($this->authenticatedUser);
    $this->drupalGet('/jobhunter/application-submission/' . self::JOB_ID . '/resolve-redirect-chain');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Authenticated user: step3 identify-auth-path → 200.
   */
  public function testStep3AllowsAuthenticatedUser(): void {
    $this->drupalLogin($this->authenticatedUser);
    $this->drupalGet('/jobhunter/application-submission/' . self::JOB_ID . '/identify-auth-path');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Authenticated user: step4 create-account → 200.
   */
  public function testStep4AllowsAuthenticatedUser(): void {
    $this->drupalLogin($this->authenticatedUser);
    $this->drupalGet('/jobhunter/application-submission/' . self::JOB_ID . '/create-account');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Authenticated user: step5 submit-application → 200.
   */
  public function testStep5AllowsAuthenticatedUser(): void {
    $this->drupalLogin($this->authenticatedUser);
    $this->drupalGet('/jobhunter/application-submission/' . self::JOB_ID . '/submit-application');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Authenticated user: step_stub (step 1, informational) → 200.
   *
   * Step 1 is in the stub step_map and does not redirect, making it the
   * safe step number for access-control smoke testing.
   */
  public function testStepStubAllowsAuthenticatedUser(): void {
    $this->drupalLogin($this->authenticatedUser);
    $this->drupalGet('/jobhunter/application-submission/' . self::JOB_ID . '/step/1');
    $this->assertSession()->statusCodeEquals(200);
  }

  // ── Anonymous user — expect HTTP 403 ──────────────────────────────────────

  /**
   * Anonymous user: step2 → 403.
   */
  public function testStep2DeniesAnonymousUser(): void {
    $this->drupalGet('/jobhunter/application-submission/' . self::JOB_ID . '/resolve-redirect-chain');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Anonymous user: step3 → 403.
   */
  public function testStep3DeniesAnonymousUser(): void {
    $this->drupalGet('/jobhunter/application-submission/' . self::JOB_ID . '/identify-auth-path');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Anonymous user: step4 → 403.
   */
  public function testStep4DeniesAnonymousUser(): void {
    $this->drupalGet('/jobhunter/application-submission/' . self::JOB_ID . '/create-account');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Anonymous user: step5 → 403.
   */
  public function testStep5DeniesAnonymousUser(): void {
    $this->drupalGet('/jobhunter/application-submission/' . self::JOB_ID . '/submit-application');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Anonymous user: step_stub → 403.
   */
  public function testStepStubDeniesAnonymousUser(): void {
    $this->drupalGet('/jobhunter/application-submission/' . self::JOB_ID . '/step/1');
    $this->assertSession()->statusCodeEquals(403);
  }

}
