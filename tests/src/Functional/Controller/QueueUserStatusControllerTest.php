<?php

namespace Drupal\Tests\job_hunter\Functional\Controller;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for the navigation queue-status endpoint.
 *
 * Verifies that /jobhunter/queue/user-status returns JSON for both anonymous
 * and authenticated visitors so the sidebar can populate without an access
 * denial fallback.
 *
 * @group job_hunter
 */
class QueueUserStatusControllerTest extends BrowserTestBase {

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
   * Anonymous visitors can read the queue status JSON.
   */
  public function testAnonymousVisitorReceivesQueueStatusJson(): void {
    $this->drupalGet('/jobhunter/queue/user-status');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('"success":true');
    $this->assertSession()->responseContains('"total_items":0');
  }

  /**
   * Authenticated visitors can also read the queue status JSON.
   */
  public function testAuthenticatedVisitorReceivesQueueStatusJson(): void {
    $user = $this->drupalCreateUser();
    $this->drupalLogin($user);

    $this->drupalGet('/jobhunter/queue/user-status');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('"success":true');
  }

}
