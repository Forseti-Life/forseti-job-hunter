<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Url;

/**
 * Controller for legacy ATS credential routes.
 *
 * Primary credential management now lives on the profile page. This controller
 * keeps the legacy endpoints available and forwards users to the profile flow.
 */
class CredentialController extends ControllerBase {
  use JobHunterControllerTrait;

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * ATS platforms that support credential-based login.
   * Must match BrowserAutomationService::LOGIN_REQUIRED_PLATFORMS.
   */
  const LOGIN_REQUIRED_PLATFORMS = [
    'workday'        => 'Workday',
    'icims'          => 'iCIMS',
    'taleo'          => 'Oracle Taleo',
    'successfactors' => 'SAP SuccessFactors',
    'ultipro'        => 'UKG Pro (UltiPro)',
    'paylocity'      => 'Paylocity',
    'usajobs'        => 'USAJobs.gov',
    'bamboohr'       => 'BambooHR',
  ];

  public function __construct(Connection $database, AccountProxyInterface $current_user) {
    $this->database    = $database;
    $this->currentUser = $current_user;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('current_user')
    );
  }

  /**
   * Legacy credentials page.
   *
   * Route: GET /jobhunter/settings/credentials
   */
  public function credentialsPage() {
    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['job-hunter-credentials-redirect']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h1',
        '#value' => $this->t('ATS Credentials Moved'),
      ],
      'description' => [
        '#markup' => '<p>' . $this->t('Credential storage is now part of your profile page. Set your default automation user ID, default password, and default email there.') . '</p>',
      ],
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Open Profile Settings'),
        '#url' => Url::fromRoute('job_hunter.user_profile_edit'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * AJAX: Delete a stored credential.
   *
   * Route: POST /jobhunter/settings/credentials/{credential_id}/delete
   */
  public function deleteCredential($credential_id) {
    $uid = $this->currentUser->id();

    // Verify ownership.
    $owner = $this->database->select('jobhunter_employer_credentials', 'c')
      ->fields('c', ['uid', 'company_id', 'credential_type'])
      ->condition('id', (int) $credential_id)
      ->execute()
      ->fetchAssoc();

    if (!$owner || (int) $owner['uid'] !== $uid) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Not found or permission denied.'], 403);
    }

    /** @var \Drupal\job_hunter\Service\CredentialManagementService $cred_service */
    $cred_service = \Drupal::service('job_hunter.credential_management_service');
    $deleted = $cred_service->deleteCredential($uid, (int) $owner['company_id'], $owner['credential_type']);

    return new JsonResponse(['success' => $deleted]);
  }

  /**
   * AJAX: Queue a credential test.
   *
   * Route: POST /jobhunter/settings/credentials/{credential_id}/test
   */
  public function testCredential($credential_id) {
    $uid = $this->currentUser->id();

    $cred_row = $this->database->select('jobhunter_employer_credentials', 'c')
      ->fields('c', ['uid', 'company_id', 'credential_type', 'submission_url'])
      ->condition('id', (int) $credential_id)
      ->execute()
      ->fetchAssoc();

    if (!$cred_row || (int) $cred_row['uid'] !== $uid) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Not found.'], 403);
    }

    /** @var \Drupal\job_hunter\Service\CredentialManagementService $cred_service */
    $cred_service = \Drupal::service('job_hunter.credential_management_service');
    $result = $cred_service->testCredential(
      $uid,
      (int) $cred_row['company_id'],
      $cred_row['credential_type'],
      $cred_row['submission_url'] ?: ''
    );

    return new JsonResponse($result);
  }

  /**
   * Returns a status badge string for display.
   */
  protected function getStatusBadge(string $status): string {
    $badges = [
      'verified'   => '✅ Verified',
      'unverified' => '⚪ Unverified',
      'invalid'    => '❌ Invalid',
      'expired'    => '⏰ Expired',
    ];
    return $badges[$status] ?? '⚪ Unknown';
  }

}
