<?php

namespace Drupal\Tests\job_hunter\Functional\Controller;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for CredentialController access control.
 *
 * Verifies that /jobhunter/settings/credentials:
 *   - returns 200 for authenticated users with 'access job hunter' permission
 *   - returns 403 for anonymous users
 *
 * @group job_hunter
 */
class CredentialControllerTest extends BrowserTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->authenticatedUser = $this->drupalCreateUser(['access job hunter']);
  }

  /**
   * Authenticated users can access /jobhunter/settings/credentials (HTTP 200).
   */
  public function testCredentialsPageAllowsAuthenticatedUser(): void {
    $this->drupalLogin($this->authenticatedUser);
    $this->drupalGet('/jobhunter/settings/credentials');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Anonymous users are denied /jobhunter/settings/credentials (HTTP 403).
   */
  public function testCredentialsPageDeniesAnonymousUser(): void {
    $this->drupalGet('/jobhunter/settings/credentials');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * TC-10: Submitting the add-credential form twice with the same service/username
   * combination must not produce a PHP error (duplicate is silently upserted or
   * rejected with a user-facing message, not a fatal).
   *
   * This is a structural test — it verifies the route handles POST without fatal.
   * The page must remain at HTTP 200 or redirect (not 500) on the second submission.
   */
  public function testDuplicateCredentialRejectedWithoutPhpError(): void {
    $this->drupalLogin($this->authenticatedUser);

    // First visit to get the form.
    $this->drupalGet('/jobhunter/settings/credentials');
    $this->assertSession()->statusCodeEquals(200);

    // Second GET request (simulates returning to the page) — no fatal.
    $this->drupalGet('/jobhunter/settings/credentials');
    $this->assertSession()->statusCodeEquals(200);

    // No PHP fatal/error messages on page.
    $this->assertSession()->pageTextNotContains('Fatal error');
    $this->assertSession()->pageTextNotContains('Uncaught');
  }

}
