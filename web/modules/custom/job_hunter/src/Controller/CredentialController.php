<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller for ATS credential management at /jobhunter/settings/credentials.
 *
 * Allows users to store, view, and delete encrypted ATS login credentials
 * needed for Phase 2 automated application submission on login-required
 * platforms (Workday, iCIMS, Taleo, etc.).
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
   * Main credentials management page.
   *
   * Route: GET /jobhunter/settings/credentials
   */
  public function credentialsPage() {
    $uid = $this->currentUser->id();

    /** @var \Drupal\job_hunter\Service\CredentialManagementService $cred_service */
    $cred_service = \Drupal::service('job_hunter.credential_management_service');

    // Load stored credentials (metadata only — never decrypted for display).
    $raw_credentials = $cred_service->listUserCredentials($uid);

    // Enrich with company name.
    $credentials = [];
    foreach ($raw_credentials as $id => $cred) {
      $company_name = $this->database->select('jobhunter_companies', 'c')
        ->fields('c', ['name'])
        ->condition('id', $cred['company_id'])
        ->execute()
        ->fetchField();

      $credentials[$id] = $cred + [
        'company_name'     => $company_name ?: 'Unknown Company',
        'platform_label'   => self::LOGIN_REQUIRED_PLATFORMS[$cred['credential_type']] ?? ucfirst($cred['credential_type']),
        'status_badge'     => $this->getStatusBadge($cred['verification_status'] ?? 'unverified'),
        'delete_url'       => Url::fromRoute('job_hunter.credentials_delete', ['credential_id' => $id])->toString(),
      ];
    }

    // Build the add-credential form.
    $add_form = $this->formBuilder()->getForm('Drupal\job_hunter\Form\CredentialForm');

    // Load companies that have saved jobs (for the add form dropdown).
    $companies = $this->database->select('jobhunter_companies', 'c')
      ->fields('c', ['id', 'name'])
      ->orderBy('name')
      ->execute()
      ->fetchAllKeyed();

    $content = [
      '#theme'       => 'credentials_management',
      '#credentials' => $credentials,
      '#companies'   => $companies,
      '#ats_platforms' => self::LOGIN_REQUIRED_PLATFORMS,
      '#add_form'    => $add_form,
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
