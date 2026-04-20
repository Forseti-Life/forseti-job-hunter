<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\job_hunter\Repository\JobApplicationRepository;
use Drupal\Core\Link;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\job_hunter\Service\JobDiscoveryService;
use Drupal\job_hunter\Service\SearchAggregatorService;
use Drupal\job_hunter\Service\UserProfileService;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * AJAX and action endpoints for Job Hunter (form submissions, status toggles, wizard steps).
 */
class ApplicationActionController extends ControllerBase {
  use JobHunterControllerTrait;
  use ApplicationControllerHelperTrait;

  protected JobDiscoveryService $jobDiscoveryService;
  protected RequestStack $requestStack;
  protected JobApplicationRepository $repository;
  protected QueueFactory $queueFactory;
  protected SearchAggregatorService $searchAggregator;
  protected UserProfileService $userProfileService;
  protected CsrfTokenGenerator $csrfTokenGenerator;

  public function __construct(
    JobDiscoveryService $job_discovery_service,
    RequestStack $request_stack,
    JobApplicationRepository $repository,
    QueueFactory $queue_factory,
    SearchAggregatorService $search_aggregator,
    EntityTypeManagerInterface $entity_type_manager,
    UserProfileService $user_profile_service,
    CsrfTokenGenerator $csrf_token_generator
  ) {
    $this->jobDiscoveryService = $job_discovery_service;
    $this->requestStack = $request_stack;
    $this->repository = $repository;
    $this->queueFactory = $queue_factory;
    $this->searchAggregator = $search_aggregator;
    $this->entityTypeManager = $entity_type_manager;
    $this->userProfileService = $user_profile_service;
    $this->csrfTokenGenerator = $csrf_token_generator;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('job_hunter.job_discovery_service'),
      $container->get('request_stack'),
      $container->get('job_hunter.job_application_repository'),
      $container->get('queue'),
      $container->get('job_hunter.search_aggregator'),
      $container->get('entity_type.manager'),
      $container->get('job_hunter.user_profile_service'),
      $container->get('csrf_token')
    );
  }

  public function listJobsRedirect() {
    return new RedirectResponse(Url::fromRoute('job_hunter.job_discovery')->toString());
  }

  /**
   * Returns an administrative dashboard for job applications.
   *
   * @return array
   *   A comprehensive renderable array for the administrative dashboard.
   */
  public function saveTargetCompanies() {
    return new \Symfony\Component\HttpFoundation\RedirectResponse('/job-applications');
  }

  /**
   * Companies overview page.
   *
   * Displays a comprehensive overview of all companies in the system,
   * including completion percentages, job counts, and application statistics.
   *
   * @return array
   *   A renderable array for the companies overview page.
   */
  public function addPostingFromSearch(): RedirectResponse|JsonResponse {
    $request = $this->requestStack->getCurrentRequest();
    $is_ajax = $request->isXmlHttpRequest();

    if ($request->isMethod('POST')) {
      $csrf_token = $request->headers->get('X-CSRF-Token', '') ?: (string) $request->request->get('csrf_token', '');
      if (!$this->csrfTokenGenerator->validate($csrf_token, 'job_hunter.addposting')) {
        if ($is_ajax) {
          return new JsonResponse([
            'success' => FALSE,
            'message' => (string) $this->t('Security token validation failed. Refresh and try again.'),
          ], 403);
        }
        $this->messenger()->addError($this->t('Security token validation failed. Refresh and try again.'));
        return new RedirectResponse('/jobhunter/job-discovery/search');
      }
    }

    if ($this->currentUser()->isAnonymous()) {
      if ($is_ajax) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => (string) $this->t('You must be logged in to save jobs.'),
          'redirect' => '/user/login',
        ], 401);
      }
      $this->messenger()->addError($this->t('You must be logged in to save jobs.'));
      return new RedirectResponse('/user/login');
    }

    $encoded = (string) ($request->request->get('job_id') ?? $request->query->get('job_id', ''));
    if ($encoded === '') {
      if ($is_ajax) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => (string) $this->t('Missing job payload.'),
        ], 400);
      }
      $this->messenger()->addError($this->t('Missing job payload.'));
      return new RedirectResponse('/jobhunter/job-discovery');
    }

    $uid = (int) $this->currentUser()->id();

    try {
      $target_job_id = $this->resolveTargetJobIdFromToken($encoded);

      if (!$target_job_id) {
        $target_job_id = $this->createJobFromSearchPayload($encoded);
      }

      if (!$target_job_id) {
        if ($is_ajax) {
          return new JsonResponse([
            'success' => FALSE,
            'message' => (string) $this->t('Job not found in Forseti jobs yet. Refresh search and try again.'),
          ], 404);
        }
        $this->messenger()->addError($this->t('Job not found in Forseti jobs yet. Refresh search and try again.'));
        return new RedirectResponse('/jobhunter/job-discovery/search');
      }

      // User-specific save mapping.
      $existing_mapping = $this->repository->findSavedJobMappingId($uid, $target_job_id);

      if ($existing_mapping) {
        if ($is_ajax) {
          return new JsonResponse([
            'success' => TRUE,
            'already_saved' => TRUE,
            'message' => (string) $this->t('Job is already in My Jobs.'),
          ]);
        }
        $this->messenger()->addStatus($this->t('Job is already in My Jobs.'));
        return new RedirectResponse('/jobhunter/my-jobs');
      }

      $this->repository->insertSavedJob($uid, $target_job_id);

      if ($is_ajax) {
        return new JsonResponse([
          'success' => TRUE,
          'already_saved' => FALSE,
          'message' => (string) $this->t('Job added to My Jobs.'),
        ]);
      }

      $this->messenger()->addStatus($this->t('Job added to My Jobs.'));
      return new RedirectResponse('/jobhunter/my-jobs');
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('Failed to add posting from search payload: @error', [
        '@error' => $e->getMessage(),
      ]);

      if ($is_ajax) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => (string) $this->t('Unable to save this job right now.'),
        ], 500);
      }

      $this->messenger()->addError($this->t('Unable to save this job right now.'));
      return new RedirectResponse('/jobhunter/job-discovery');
    }
  }

  /**
   * Resolve a Forseti job ID from a search result token.
   *
   * @param string $encoded
   *   Search result token from query string.
   *
   * @return int|null
   *   Forseti job ID or NULL if unresolved.
   */
  private function resolveTargetJobIdFromToken(string $encoded): ?int {
    if (preg_match('/^forseti_(\d+)$/', $encoded, $matches)) {
      return (int) $matches[1];
    }

    if (preg_match('/^staging_(\d+)$/', $encoded, $matches)) {
      $imported_job_id = $this->repository->getImportedJobIdFromStaging((int) $matches[1]);
      if ($imported_job_id > 0) {
        return $imported_job_id;
      }
    }

    $job_id = $this->findJobIdByExternalId($this->normalizeExternalJobId($encoded));
    if ($job_id !== NULL) {
      return $job_id;
    }

    $job_data = $this->decodeSearchPayloadToken($encoded);
    if (is_array($job_data)) {
      $embedded_external_id = trim((string) ($job_data['htidocid'] ?? $job_data['job_id'] ?? $job_data['id'] ?? ''));
      if ($embedded_external_id !== '') {
        return $this->findJobIdByExternalId($this->normalizeExternalJobId($embedded_external_id));
      }
    }

    return NULL;
  }

  /**
   * Find Forseti job ID by normalized external job identifier.
   *
   * @param string $external_id
   *   Normalized external identifier.
   *
   * @return int|null
   *   Matching Forseti job ID or NULL.
   */
  private function findJobIdByExternalId(string $external_id): ?int {
    return $this->repository->findJobIdByExternalId($external_id);
  }

  /**
   * Decode legacy search payload token if it is base64-encoded JSON.
   *
   * @param string $encoded
   *   Raw token from query string.
   *
   * @return array|null
   *   Decoded payload array or NULL.
   */
  private function decodeSearchPayloadToken(string $encoded): ?array {
    $encoded = urldecode($encoded);
    $raw_json = json_decode($encoded, TRUE);
    if (is_array($raw_json)) {
      return $raw_json;
    }
    // Query parsing may convert "+" to spaces; restore before base64 decode.
    $encoded = str_replace(' ', '+', trim($encoded));
    $remainder = strlen($encoded) % 4;
    if ($remainder > 0) {
      $encoded .= str_repeat('=', 4 - $remainder);
    }
    $decoded = base64_decode(strtr($encoded, '-_', '+/'), TRUE);
    if ($decoded === FALSE) {
      $decoded = base64_decode($encoded, TRUE);
    }

    if ($decoded === FALSE) {
      return NULL;
    }

    $job_data = json_decode($decoded, TRUE);
    return is_array($job_data) ? $job_data : NULL;
  }

  /**
   * Create a minimal Forseti job record from a legacy search payload.
   *
   * @param string $encoded
   *   Raw search token from query string.
   *
   * @return int|null
   *   Created Forseti job ID or NULL if payload is not usable.
   */
  private function createJobFromSearchPayload(string $encoded): ?int {
    $job_data = $this->decodeSearchPayloadToken($encoded);
    $job_data = is_array($job_data) ? $job_data : [];

    $job_title = trim((string) ($job_data['job_title'] ?? $job_data['title'] ?? 'Imported External Job'));

    $external_job_id = trim((string) ($job_data['htidocid'] ?? $job_data['job_id'] ?? $job_data['id'] ?? $encoded));
    if ($external_job_id === '' || preg_match('/^(forseti|staging)_\d+$/', $external_job_id)) {
      return NULL;
    }
    $location = trim((string) ($job_data['address_city'] ?? $job_data['location'] ?? ''));
    $job_url = trim((string) ($job_data['job_url'] ?? $job_data['link'] ?? $job_data['url'] ?? ''));
    $source_platform = '';
    if ($job_url !== '') {
      $parsed_host = parse_url($job_url, PHP_URL_HOST);
      if (is_string($parsed_host) && $parsed_host !== '') {
        $source_platform = substr(preg_replace('/^www\./', '', strtolower($parsed_host)), 0, 100);
      }
    }
    $now = time();

    $fields = [
      'job_title' => $job_title,
      'status' => 'active',
      'created' => $now,
      'updated' => $now,
      'external_source' => 'Google Jobs (SerpAPI)',
      'source_platform' => $source_platform,
    ];

    if ($location !== '') {
      $fields['location'] = $location;
    }
    if ($job_url !== '') {
      $fields['job_url'] = substr($job_url, 0, 512);
    }
    if ($external_job_id !== '') {
      $fields['external_job_id'] = $this->normalizeExternalJobId($external_job_id);
    }
    if (!empty($job_data['description'])) {
      $fields['job_description'] = (string) $job_data['description'];
    }

    return $this->repository->insertJobRequirement($fields);
  }

  /**
   * Normalize external job IDs to fit schema constraints safely.
   *
   * @param string $external_job_id
   *   Source-provided external job identifier.
   *
   * @return string
   *   A schema-safe external job ID.
   */
  private function normalizeExternalJobId(string $external_job_id): string {
    if (strlen($external_job_id) <= 255) {
      return $external_job_id;
    }

    return 'hash_' . hash('sha256', $external_job_id);
  }

  /**
   * My Jobs page - displays user's saved job postings.
   *
   * Derives a workflow status per job based on profile, tailoring, and
   * application state:
   *   profile_pending → tailoring_pending → tailoring_processing →
   *   application_pending → pending_response → closed
   *
   * @return array
   *   Renderable array for the my jobs page.
   */

  /**
   * Bulk archive selected jobs (POST-only, CSRF-protected).
   *
   * AC-4: Validates CSRF via routing (split-route pattern). Job IDs are
   * validated as integers and cross-checked against current user ownership.
   * Non-integer or unowned IDs are silently discarded (no error exposure).
   */
  public function myJobsBulkArchive(): RedirectResponse {
    if ($this->currentUser()->isAnonymous()) {
      throw new AccessDeniedHttpException();
    }

    $request = $this->requestStack->getCurrentRequest();
    $job_ids_raw = (array) ($request->request->all()['job_ids'] ?? []);

    // Validate: accept only positive integers.
    $job_ids = [];
    foreach ($job_ids_raw as $raw) {
      $int_id = (int) $raw;
      if ($int_id > 0 && (string) $int_id === (string) $raw) {
        $job_ids[] = $int_id;
      }
    }

    $return_to = (string) $request->request->get('return_to', '/jobhunter/my-jobs');
    if (!preg_match('/^\/(?!\/)/', $return_to)) {
      $return_to = '/jobhunter/my-jobs';
    }

    if (empty($job_ids)) {
      $this->messenger()->addWarning($this->t('No jobs selected for archiving.'));
      return new RedirectResponse($return_to);
    }

    $uid = (int) $this->currentUser()->id();
    $archived_count = 0;

    foreach ($job_ids as $job_id) {
      // Verify ownership before archiving (cross-user access prevention).
      $owned = $this->repository->findSavedJobMappingId($uid, $job_id);
      if ($owned) {
        try {
          $this->repository->setJobArchivedForUser($uid, $job_id, TRUE);
          $archived_count++;
        }
        catch (\Exception $e) {
          $this->getLogger('job_hunter')->error('Bulk archive failed for job @id: @error', [
            '@id' => $job_id,
            '@error' => $e->getMessage(),
          ]);
        }
      }
    }

    if ($archived_count > 0) {
      $this->messenger()->addMessage($this->t('@count job(s) archived successfully.', ['@count' => $archived_count]));
    }

    return new RedirectResponse($return_to);
  }

  public function archiveJob(int $job_id): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    $return_to = (string) $request->query->get('return_to', '/jobhunter/my-jobs');
    if (!preg_match('/^\/(?!\/)/', $return_to)) {
      $return_to = '/jobhunter/my-jobs';
    }

    if ($this->currentUser()->isAnonymous()) {
      return new RedirectResponse('/user/login');
    }

    try {
      // Verify ownership via jobhunter_saved_jobs before updating status.
      $owned = $this->repository->findSavedJobMappingId((int) $this->currentUser()->id(), $job_id);

      if (!$owned) {
        $this->messenger()->addError($this->t('Job not found.'));
        return new RedirectResponse($return_to);
      }

      $this->repository->setJobArchivedForUser((int) $this->currentUser()->id(), $job_id, TRUE);

      $this->messenger()->addMessage($this->t('Job archived.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to archive job. Please try again.'));
      $this->getLogger('job_hunter')->error('Failed to archive job @id: @error', [
        '@id' => $job_id,
        '@error' => $e->getMessage(),
      ]);
    }

    return new RedirectResponse($return_to);
  }

  /**
   * Unarchive a job (sets status back to 'active').
   */
  public function unarchiveJob(int $job_id): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    $return_to = (string) $request->query->get('return_to', '/jobhunter/my-jobs/archive');
    if (!preg_match('/^\/(?!\/)/', $return_to)) {
      $return_to = '/jobhunter/my-jobs/archive';
    }

    if ($this->currentUser()->isAnonymous()) {
      return new RedirectResponse('/user/login');
    }

    try {
      $owned = $this->repository->findSavedJobMappingId((int) $this->currentUser()->id(), $job_id);

      if (!$owned) {
        $this->messenger()->addError($this->t('Job not found.'));
        return new RedirectResponse($return_to);
      }

      $this->repository->setJobArchivedForUser((int) $this->currentUser()->id(), $job_id, FALSE);

      $this->messenger()->addMessage($this->t('Job restored to My Jobs.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to restore job. Please try again.'));
      $this->getLogger('job_hunter')->error('Failed to unarchive job @id: @error', [
        '@id' => $job_id,
        '@error' => $e->getMessage(),
      ]);
    }

    return new RedirectResponse($return_to);
  }

  /**
   * Archive page — shows archived jobs with pagination.
   */
  public function toggleJobApplied(int $job_id): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    $return_to = (string) $request->request->get('return_to', '/jobhunter/my-jobs');

    if ($this->currentUser()->isAnonymous()) {
      $this->messenger()->addError($this->t('You must be logged in to update job status.'));
      return new RedirectResponse('/user/login');
    }

    if (!preg_match('/^\/(?!\/)/', $return_to)) {
      $return_to = '/jobhunter/my-jobs';
    }

    try {
      $saved_mapping_exists = (bool) $this->repository->findSavedJobMappingId((int) $this->currentUser()->id(), $job_id);

      if (!$saved_mapping_exists) {
        $this->messenger()->addError($this->t('Job not found in your saved jobs.'));
        return new RedirectResponse($return_to);
      }

      $job = $this->repository->getJobById($job_id, ['id', 'status', 'applied_on_date']);
      if (!$job) {
        $this->messenger()->addError($this->t('Job not found or access denied.'));
        return new RedirectResponse($return_to);
      }

      $have_applied = (bool) $request->request->get('have_applied');
      $applied_on_date = trim((string) $request->request->get('applied_on_date', ''));
      $is_valid_date = $applied_on_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $applied_on_date) === 1;

      $update_fields = [
        'status' => $have_applied ? 'applied' : 'active',
        'applied_on_date' => $have_applied ? ($is_valid_date ? $applied_on_date : date('Y-m-d')) : NULL,
        'updated' => time(),
      ];

      $this->repository->updateJobRequirement($job_id, $update_fields);

      if ($have_applied) {
        $this->messenger()->addStatus($this->t('Marked as applied.'));
      }
      else {
        $this->messenger()->addStatus($this->t('Marked as not applied.'));
      }
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('Failed to toggle applied status for job @job_id: @error', [
        '@job_id' => $job_id,
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Unable to update applied status right now.'));
    }

    return new RedirectResponse($return_to);
  }

  /**
   * Job Discovery Search Results page.
   *
   * This method now uses the SearchAggregatorService to centralize
   * all search logic and API orchestration. The controller is simplified
   * to only handle request parameter extraction and result rendering.
   *
   * @return array
   *   A renderable array for the job search results page.
   */
  public function applicationSubmissionResolveRedirectChain(int $job_id): array {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      return [
        '#markup' => $this->t('You must be logged in to access this page.'),
      ];
    }

    $selected_job = $this->loadSelectedJobContext($uid, $job_id);
    if (!$selected_job) {
      $this->messenger()->addError($this->t('Job requisition not found for your account.'));
      return $this->wrapWithNavigation([
        '#markup' => '<p>' . $this->t('Unable to load this requisition.') . '</p>',
      ]);
    }

    $extracted = is_array($selected_job->extracted_data ?? NULL) ? $selected_job->extracted_data : [];
    $job_title = (string) ($extracted['position']['title'] ?? $selected_job->job_title ?? ('Job #' . (int) $selected_job->id));
    $company_name = (string) ($extracted['company']['name'] ?? $selected_job->company_name ?? 'Unknown');
    $original_job_url = (string) ($selected_job->job_url ?? '');

    $request = $this->requestStack->getCurrentRequest();
    $run_step2_requested = FALSE;
    if ($request->isMethod('POST') && (string) $request->request->get('run_step2') === '1') {
      $token = (string) $request->request->get('csrf_token', '');
      if ($token !== '' && $this->csrfTokenGenerator->validate($token, 'job_hunter_step2_run_' . (int) $selected_job->id)) {
        $run_step2_requested = TRUE;
      }
      else {
        $this->messenger()->addError($this->t('Unable to run Step 2 checks because the request token is invalid. Refresh and try again.'));
      }
    }

    $existing_application = $this->repository->findLatestApplicationByJobAndUser($uid, (int) $selected_job->id, ['id', 'apply_url', 'ats_platform', 'metadata']);

    $metadata_base = [];
    if (!empty($existing_application['metadata'])) {
      $decoded_meta = json_decode((string) $existing_application['metadata'], TRUE);
      if (is_array($decoded_meta)) {
        $metadata_base = $decoded_meta;
      }
    }

    $step2_cache = is_array($metadata_base['step2_cache'] ?? NULL) ? $metadata_base['step2_cache'] : [];
    $has_cached_step2 = !empty($step2_cache);

    $resolved_url = (string) ($step2_cache['resolved_url'] ?? $existing_application['apply_url'] ?? '');
    $ats_platform = (string) ($step2_cache['ats_platform'] ?? $existing_application['ats_platform'] ?? 'unknown');
    $confidence = (string) ($step2_cache['confidence'] ?? $metadata_base['confidence'] ?? 'none');
    $resolution_steps = is_array($step2_cache['resolution_steps'] ?? NULL)
      ? $step2_cache['resolution_steps']
      : (is_array($metadata_base['resolution_steps'] ?? NULL) ? $metadata_base['resolution_steps'] : []);

    $verification = is_array($step2_cache['verification'] ?? NULL) ? $step2_cache['verification'] : [];
    if (empty($verification)) {
      $verification = [
        'final_pass' => !empty($metadata_base['verification_passed_at']),
        'decision_mode' => (string) ($metadata_base['verification_mode'] ?? ''),
        'error' => '',
        'checks' => [],
        'genai' => [
          'used' => FALSE,
          'available' => FALSE,
          'success' => FALSE,
          'confirmed' => FALSE,
          'confidence' => 'none',
          'response' => '',
          'evidence' => '',
        ],
      ];
    }

    if ($run_step2_requested || !$has_cached_step2) {
      $resolved = [];
      try {
        $resolved = \Drupal::service('job_hunter.apply_url_resolver')->resolve([
          'apply_options' => (string) ($selected_job->apply_options ?? ''),
          'job_url' => $original_job_url,
        ]);
      }
      catch (\Exception $e) {
        $this->messenger()->addError($this->t('Failed to resolve redirect chain: @error', ['@error' => $e->getMessage()]));
        $resolved = [
          'url' => $original_job_url,
          'ats_platform' => 'unknown',
          'resolution_steps' => [],
          'confidence' => 'none',
        ];
      }

      $resolution_steps = is_array($resolved['resolution_steps'] ?? NULL) ? $resolved['resolution_steps'] : [];
      $resolved_url = (string) ($resolved['url'] ?? '');
      $confidence = (string) ($resolved['confidence'] ?? 'none');
      $ats_platform = (string) ($resolved['ats_platform'] ?? 'unknown');

      try {
        @set_time_limit(120);
        $verification = \Drupal::service('job_hunter.application_location_verification_service')->verify((int) $selected_job->id, [
          'genai_fallback' => TRUE,
          'min_description_overlap' => 0.15,
          'timeout' => 45,
        ]);
      }
      catch (\Throwable $e) {
        $verification = [
          'final_pass' => FALSE,
          'decision_mode' => 'error',
          'error' => $e->getMessage(),
          'checks' => [],
          'genai' => [
            'used' => FALSE,
            'available' => FALSE,
            'success' => FALSE,
            'confirmed' => FALSE,
            'confidence' => 'none',
            'response' => '',
            'evidence' => '',
          ],
        ];
      }

      $redirect_hops_runtime = 0;
      foreach ($resolution_steps as $step) {
        if (($step['action'] ?? '') === 'following_redirect') {
          $redirect_hops_runtime++;
        }
      }
      $is_direct_link_runtime = $redirect_hops_runtime === 0;
      $has_career_page_runtime = $resolved_url !== '';
      $is_resolved_runtime = $has_career_page_runtime && in_array(strtolower($confidence), ['high', 'medium'], TRUE);

      $now = date('Y-m-d H:i:s');
      $effective_url = (string) ($verification['effective_url'] ?? $resolved_url);
      $effective_ats = (string) ($verification['ats_platform'] ?? $ats_platform ?: 'custom');

      $metadata_base['step2_cache'] = [
        'ran_at' => $now,
        'resolved_url' => $effective_url !== '' ? $effective_url : $resolved_url,
        'ats_platform' => $effective_ats,
        'confidence' => $confidence,
        'resolution_steps' => $resolution_steps,
        'is_direct_link' => $is_direct_link_runtime,
        'has_career_page' => $has_career_page_runtime,
        'is_resolved' => $is_resolved_runtime,
        'verification' => $verification,
      ];

      $metadata_base['confidence'] = !empty($verification['final_pass']) ? 'high' : $confidence;
      $metadata_base['resolution_steps'] = $resolution_steps;

      if (!empty($verification['final_pass'])) {
        $metadata_base['verification_passed_at'] = $now;
        $metadata_base['verification_mode'] = (string) ($verification['decision_mode'] ?? '');
      }

      if ($existing_application) {
        $this->repository->updateApplication((int) $existing_application['id'], [
          'apply_url' => $effective_url !== '' ? $effective_url : $resolved_url,
          'ats_platform' => $effective_ats,
          'metadata' => json_encode($metadata_base),
          'changed' => $now,
        ]);
      }
      else {
        $this->repository->insertApplication([
          'uid' => $uid,
          'job_id' => (int) $selected_job->id,
          'submission_status' => 'not_started',
          'submission_method' => 'pending',
          'apply_url' => $effective_url !== '' ? $effective_url : $resolved_url,
          'ats_platform' => $effective_ats,
          'attempt_count' => 0,
          'metadata' => json_encode($metadata_base),
          'created' => $now,
          'changed' => $now,
        ]);
      }

      if ($run_step2_requested) {
        $this->messenger()->addStatus($this->t('Step 2 checks completed and cached.'));
      }

      $has_cached_step2 = TRUE;
    }

    $redirect_hops = 0;
    foreach ($resolution_steps as $step) {
      if (($step['action'] ?? '') === 'following_redirect') {
        $redirect_hops++;
      }
    }

    $is_direct_link = !empty($step2_cache['is_direct_link']) || $redirect_hops === 0;
    $has_career_page = !empty($step2_cache['has_career_page']) || $resolved_url !== '';
    $is_resolved = !empty($step2_cache['is_resolved']) || ($has_career_page && in_array(strtolower($confidence), ['high', 'medium'], TRUE));

    if (!$run_step2_requested && !$has_cached_step2) {
      $verification = [
        'final_pass' => FALSE,
        'decision_mode' => 'not_run',
        'error' => 'Step 2 checks have not been run yet. Use the button above to execute and cache results.',
        'checks' => [],
        'genai' => [
          'used' => FALSE,
          'available' => FALSE,
          'success' => FALSE,
          'confirmed' => FALSE,
          'confidence' => 'none',
          'response' => '',
          'evidence' => '',
        ],
      ];
    }

    $content = [
      '#theme' => 'application_submission_step2',
      '#job_id' => (int) $selected_job->id,
      '#job_title' => $job_title,
      '#company_name' => $company_name,
      '#original_job_url' => $original_job_url,
      '#resolved_url' => $resolved_url,
      '#ats_platform' => $ats_platform,
      '#confidence' => $confidence,
      '#resolution_steps' => $resolution_steps,
      '#is_direct_link' => $is_direct_link,
      '#has_career_page' => $has_career_page,
      '#is_resolved' => $is_resolved,
      '#verification' => $verification,
      '#step2_cache_exists' => $has_cached_step2,
      '#step2_last_run_at' => (string) (($metadata_base['step2_cache']['ran_at'] ?? '')),
      '#step2_ran_this_request' => $run_step2_requested,
      '#run_step2_csrf_token' => $this->csrfTokenGenerator->get('job_hunter_step2_run_' . (int) $selected_job->id),
      '#return_url' => '/jobhunter/application-submission/' . (int) $selected_job->id,
      '#cache' => [
        'contexts' => ['user', 'url.query_args'],
        'tags' => ['job_hunter:jobs', 'job_hunter:applications'],
        'max-age' => 0,
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Step 3: Identify authentication path for a job application.
   *
   * Uses AuthPathIdentificationService to launch a stealth browser, click the
   * Apply button, and classify the auth mechanism (email/password, SSO, etc.).
   * Result is persisted to jobhunter_applications.metadata so the main
   * dashboard Step 3 gate reflects the outcome.
   *
   * @param int $job_id
   *   The job requisition ID.
   *
   * @return array
   *   A render array.
   */
  public function applicationSubmissionIdentifyAuthPath(int $job_id): array {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      return [
        '#markup' => $this->t('You must be logged in to access this page.'),
      ];
    }

    $selected_job = $this->loadSelectedJobContext($uid, $job_id);
    if (!$selected_job) {
      $this->messenger()->addError($this->t('Job requisition not found for your account.'));
      return $this->wrapWithNavigation([
        '#markup' => '<p>' . $this->t('Unable to load this requisition.') . '</p>',
      ]);
    }

    $extracted = is_array($selected_job->extracted_data ?? NULL) ? $selected_job->extracted_data : [];
    $job_title = (string) ($extracted['position']['title'] ?? $selected_job->job_title ?? ('Job #' . (int) $selected_job->id));
    $company_name = (string) ($extracted['company']['name'] ?? $selected_job->company_name ?? 'Unknown');

    // ── Check for POST run request ──────────────────────────────────────────
    $request = $this->requestStack->getCurrentRequest();
    $run_step3_requested = FALSE;
    if ($request->isMethod('POST') && (string) $request->request->get('run_step3') === '1') {
      $token = (string) $request->request->get('csrf_token', '');
      if ($token !== '' && $this->csrfTokenGenerator->validate($token, 'job_hunter_step3_run_' . (int) $selected_job->id)) {
        $run_step3_requested = TRUE;
      }
      else {
        $this->messenger()->addError($this->t('Unable to run Step 3 checks because the request token is invalid. Refresh and try again.'));
      }
    }

    // ── Load existing application row + metadata ────────────────────────────
    $existing_application = $this->repository->findLatestApplicationByJobAndUser($uid, (int) $selected_job->id, ['id', 'apply_url', 'ats_platform', 'metadata']);

    $apply_url = (string) ($existing_application['apply_url'] ?? '');

    $metadata_base = [];
    if (!empty($existing_application['metadata'])) {
      $decoded_meta = json_decode((string) $existing_application['metadata'], TRUE);
      if (is_array($decoded_meta)) {
        $metadata_base = $decoded_meta;
      }
    }

    // ── Read cached Step 3 result ───────────────────────────────────────────
    $step3_cache = is_array($metadata_base['step3_cache'] ?? NULL) ? $metadata_base['step3_cache'] : [];
    $has_cached_step3 = !empty($step3_cache);

    // Default auth identification from cache (or empty).
    $auth_identification = $step3_cache ? $step3_cache['auth_identification'] ?? [] : [];

    // ── Run the stealth browser if requested or no cache ────────────────────
    if ($run_step3_requested) {
      try {
        @set_time_limit(120);
        $auth_identification = \Drupal::service('job_hunter.auth_path_identification_service')->identify(
          (int) $selected_job->id,
          ['timeout' => 45]
        );
      }
      catch (\Throwable $e) {
        $auth_identification = [
          'job_id'        => (int) $selected_job->id,
          'ok'            => FALSE,
          'auth_type'     => 'unknown',
          'sso_providers' => [],
          'form_fields'   => [],
          'auth_url'      => $apply_url,
          'page_title'    => '',
          'evidence'      => '',
          'html_excerpt'  => '',
          'error'         => $e->getMessage(),
        ];
      }

      // Persist the result to jobhunter_applications.metadata.
      try {
        $now  = date('Y-m-d H:i:s');
        $meta = $metadata_base;

        $meta['auth_type']               = (string) ($auth_identification['auth_type'] ?? 'unknown');
        $meta['auth_url']                = (string) ($auth_identification['auth_url'] ?? $auth_identification['apply_url'] ?? $apply_url);
        $meta['sso_providers']           = (array)  ($auth_identification['sso_providers'] ?? []);
        $meta['auth_identification_at']  = $now;
        $meta['step3_cache'] = [
          'ran_at' => $now,
          'auth_identification' => $auth_identification,
        ];

        // Detect ATS platform from the auth URL discovered by the stealth browser.
        $detected_ats = $this->detectAtsPlatformFromUrl((string) $meta['auth_url']);

        if ($existing_application) {
          $this->repository->updateApplication((int) $existing_application['id'], [
            'ats_platform' => $detected_ats,
            'metadata' => json_encode($meta),
            'changed'  => $now,
          ]);
        }
        else {
          $this->repository->insertApplication([
            'uid'              => $uid,
            'job_id'           => (int) $selected_job->id,
            'submission_status'=> 'not_started',
            'submission_method'=> 'pending',
            'apply_url'        => $apply_url,
            'ats_platform'     => $detected_ats,
            'attempt_count'    => 0,
            'metadata'         => json_encode($meta),
            'created'          => $now,
            'changed'          => $now,
          ]);
        }
      }
      catch (\Throwable $e) {
        // Non-fatal — continue to render the page even if persist fails.
      }

      $has_cached_step3 = TRUE;
      $this->messenger()->addStatus($this->t('Step 3 checks completed and cached.'));
    }

    $content = [
      '#theme'                 => 'application_submission_step3',
      '#job_id'                => (int) $selected_job->id,
      '#job_title'             => $job_title,
      '#company_name'          => $company_name,
      '#apply_url'             => $apply_url,
      '#auth_identification'   => $auth_identification,
      '#step3_cache_exists'    => $has_cached_step3,
      '#step3_last_run_at'     => (string) ($step3_cache['ran_at'] ?? ''),
      '#step3_ran_this_request'=> $run_step3_requested,
      '#run_step3_csrf_token'  => $this->csrfTokenGenerator->get('job_hunter_step3_run_' . (int) $selected_job->id),
      '#return_url'            => '/jobhunter/application-submission/' . (int) $selected_job->id,
      '#cache'                 => [
        'contexts' => ['user', 'url.query_args'],
        'tags'     => ['job_hunter:jobs', 'job_hunter:applications'],
        'max-age'  => 0,
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Step 4: Create account on the ATS platform.
   *
   * Loads the user's email / phone from jobhunter_job_seeker, reads
   * cached Step 3 auth type, and facilitates account creation on the
   * destination ATS (Workday, Greenhouse, etc.).
   *
   * Follows the same cache-first + POST-trigger model as Steps 2 & 3.
   * On POST (run_step4=1) persists account-readiness results to
   * metadata.step4_cache so the dashboard gate reflects the outcome.
   *
   * @param int $job_id
   *   The job requisition ID.
   *
   * @return array
   *   A render array.
   */
  public function applicationSubmissionCreateAccount(int $job_id): array {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      return [
        '#markup' => $this->t('You must be logged in to access this page.'),
      ];
    }

    $selected_job = $this->loadSelectedJobContext($uid, $job_id);
    if (!$selected_job) {
      $this->messenger()->addError($this->t('Job requisition not found for your account.'));
      return $this->wrapWithNavigation([
        '#markup' => '<p>' . $this->t('Unable to load this requisition.') . '</p>',
      ]);
    }

    $extracted = is_array($selected_job->extracted_data ?? NULL) ? $selected_job->extracted_data : [];
    $job_title = (string) ($extracted['position']['title'] ?? $selected_job->job_title ?? ('Job #' . (int) $selected_job->id));
    $company_name = (string) ($extracted['company']['name'] ?? $selected_job->company_name ?? 'Unknown');
    $company_id = (int) ($selected_job->company_id ?? 0);

    // ── Load user profile (email, phone, name) from jobhunter_job_seeker ──
    $seeker = $this->repository->getJobSeekerProfile($uid, ['contact_email', 'contact_phone', 'full_name']) ?? [];

    $user_email = (string) ($seeker['contact_email'] ?? '');
    $user_phone = (string) ($seeker['contact_phone'] ?? '');
    $user_name  = (string) ($seeker['full_name'] ?? '');

    // Fall back to Drupal user entity email if seeker record is missing.
    if ($user_email === '') {
      $user = \Drupal\user\Entity\User::load($uid);
      if ($user) {
        $user_email = (string) $user->getEmail();
      }
    }

    // ── Load existing application row + metadata ──────────────────────────
    $existing_application = $this->repository->findLatestApplicationByJobAndUser($uid, (int) $selected_job->id, ['id', 'apply_url', 'ats_platform', 'metadata']);

    $apply_url = (string) ($existing_application['apply_url'] ?? '');
    $ats_platform = (string) ($existing_application['ats_platform'] ?? 'unknown');

    $metadata_base = [];
    if (!empty($existing_application['metadata'])) {
      $decoded_meta = json_decode((string) $existing_application['metadata'], TRUE);
      if (is_array($decoded_meta)) {
        $metadata_base = $decoded_meta;
      }
    }

    // Read auth type from Step 3 result.
    $auth_type = (string) ($metadata_base['auth_type'] ?? 'unknown');
    $auth_url  = (string) ($metadata_base['auth_url'] ?? $apply_url);

    // Re-detect ATS platform from auth URL if stored value is unhelpful.
    if (in_array($ats_platform, ['custom', 'unknown', ''], TRUE)) {
      $detected = $this->detectAtsPlatformFromUrl($auth_url);
      if ($detected !== 'custom') {
        $ats_platform = $detected;
        // Persist the corrected platform to the DB row.
        if ($existing_application) {
          $this->repository->updateApplication((int) $existing_application['id'], ['ats_platform' => $ats_platform]);
        }
      }
    }

    // ── Check for stored credentials via CredentialManagementService ──────
    /** @var \Drupal\job_hunter\Service\CredentialManagementService $cred_service */
    $cred_service = \Drupal::service('job_hunter.credential_management_service');

    $stored_credential = NULL;
    $has_stored_credential = FALSE;
    $stored_username = '';
    if ($company_id > 0) {
      $stored_credential = $cred_service->retrieveCredential($uid, $company_id, 'basic');
      if ($stored_credential) {
        $has_stored_credential = TRUE;
        $stored_username = (string) ($stored_credential['username'] ?? '');
      }
    }

    // ── Read cached Step 4 result ─────────────────────────────────────────
    $step4_cache = is_array($metadata_base['step4_cache'] ?? NULL) ? $metadata_base['step4_cache'] : [];
    $has_cached_step4 = !empty($step4_cache);

    $account_status      = (string) ($step4_cache['account_status'] ?? 'unknown');
    $account_evidence    = (string) ($step4_cache['account_evidence'] ?? '');
    $email_verified      = (bool)   ($step4_cache['email_verified'] ?? FALSE);
    $phone_verified      = (bool)   ($step4_cache['phone_verified'] ?? FALSE);
    $account_created_at  = (string) ($step4_cache['account_created_at'] ?? '');
    $verification_method = (string) ($step4_cache['verification_method'] ?? '');

    // If credentials are already stored, set status accordingly.
    if ($has_stored_credential && $account_status === 'unknown') {
      $account_status = 'verified';
      $account_evidence = 'Stored credentials found for ' . $company_name . '.';
    }

    // ── Check for POST actions ────────────────────────────────────────────
    $request = $this->requestStack->getCurrentRequest();
    $run_step4_requested = FALSE;
    $verification_result_data = [];
    if ($request->isMethod('POST')) {
      $token = (string) $request->request->get('csrf_token', '');
      if ($token !== '' && $this->csrfTokenGenerator->validate($token, 'job_hunter_step4_run_' . (int) $selected_job->id)) {
        $run_step4_requested = TRUE;
      }
      else {
        $this->messenger()->addError($this->t('Invalid request token. Refresh and try again.'));
      }
    }

    if ($run_step4_requested) {
      $now = date('Y-m-d H:i:s');
      $action = (string) $request->request->get('step4_action', '');
      $verification_result_data = [];

      // ── ACTION: Store new credentials ─────────────────────────────────
      if ($action === 'store_credentials') {
        $input_username = trim((string) $request->request->get('credential_username', ''));
        $input_password = trim((string) $request->request->get('credential_password', ''));

        if ($input_username === '' || $input_password === '') {
          $this->messenger()->addError($this->t('Username and password are both required.'));
        }
        elseif ($company_id <= 0) {
          $this->messenger()->addError($this->t('Cannot store credentials — no company linked to this job.'));
        }
        else {
          $result = $cred_service->storeCredential(
            $uid,
            $company_id,
            'basic',
            ['username' => $input_username, 'password' => $input_password],
            $auth_url
          );

          if (!empty($result['success'])) {
            $has_stored_credential = TRUE;
            $stored_username = $input_username;
            $account_status = 'verified';
            $email_verified = TRUE;
            $account_created_at = $now;
            $account_evidence = 'Credentials stored for ' . $company_name . ' (username: ' . $input_username . ') at ' . $now . '.';
            $this->messenger()->addStatus($this->t('Credentials securely stored. Account marked as ready.'));
          }
          else {
            $this->messenger()->addError($this->t('Failed to store credentials: @error', ['@error' => $result['error'] ?? 'Unknown error']));
          }
        }
      }

      // ── ACTION: Confirm existing account ──────────────────────────────
      elseif ($action === 'confirm_existing') {
        $account_status = 'verified';
        $email_verified = TRUE;
        $account_created_at = $now;
        $account_evidence = 'Existing account confirmed by user at ' . $now . '. Stored credentials present.';
        $this->messenger()->addStatus($this->t('Existing account confirmed and marked as ready.'));
      }

      // ── ACTION: Verify authentication via Playwright ──────────────────
      elseif ($action === 'verify_authentication') {
        /** @var \Drupal\job_hunter\Service\AccountVerificationService $verify_svc */
        $verify_svc = \Drupal::service('job_hunter.account_verification_service');
        $verify_result = $verify_svc->verify((int) $selected_job->id, $uid, ['timeout' => 90]);

        if (!empty($verify_result['verified'])) {
          $account_status = 'verified';
          $email_verified = TRUE;
          $account_created_at = $now;
          $verification_method = 'playwright_browser';
          $account_evidence = 'Browser verification confirmed: logged in as '
            . ($verify_result['verified_email'] ?: 'unknown')
            . ' at ' . ($verify_result['user_home_url'] ?: 'user home')
            . '. ' . ($verify_result['evidence'] ?: '') . ' [' . $now . ']';
          $this->messenger()->addStatus($this->t('Authentication verified! Logged in as @email.', [
            '@email' => $verify_result['verified_email'] ?: 'the expected user',
          ]));
        }
        elseif (!empty($verify_result['ok'])) {
          // Script ran successfully but couldn't verify identity.
          $account_status = 'pending_verification';
          $account_evidence = 'Browser ran but could not confirm identity. '
            . ($verify_result['error'] ?: $verify_result['evidence'] ?: 'No email match found.')
            . ' [' . $now . ']';
          $this->messenger()->addWarning($this->t('Browser connected but could not verify your identity. Check credentials and try again.'));
        }
        else {
          $account_evidence = 'Browser verification failed: '
            . ($verify_result['error'] ?: 'Unknown error') . ' [' . $now . ']';
          $this->messenger()->addError($this->t('Verification failed: @error', [
            '@error' => $verify_result['error'] ?: 'Unknown error',
          ]));
        }

        // Store the raw verification result for template display.
        $verification_result_data = $verify_result;
      }

      // Determine verification method from auth_type.
      if ($verification_method === '') {
        if (in_array($auth_type, ['email_password', 'email_only'], TRUE)) {
          $verification_method = 'email';
        }
        elseif (str_starts_with($auth_type, 'sso_')) {
          $verification_method = 'sso_provider';
        }
        elseif ($auth_type === 'registration_first') {
          $verification_method = 'email';
        }
        elseif ($auth_type === 'direct') {
          $verification_method = 'none';
        }
        else {
          $verification_method = 'manual';
        }
      }

      // Persist to metadata.
      $meta = $metadata_base;
      $meta['step4_cache'] = [
        'ran_at'              => $now,
        'account_status'      => $account_status,
        'account_evidence'    => $account_evidence,
        'email_verified'      => $email_verified,
        'phone_verified'      => $phone_verified,
        'account_created_at'  => $account_created_at,
        'verification_method' => $verification_method,
        'user_email'          => $user_email,
        'user_phone'          => $user_phone,
        'stored_username'     => $stored_username,
        'verification_result' => $verification_result_data,
      ];
      $meta['account_readiness_at'] = in_array($account_status, ['verified', 'not_required'], TRUE) ? $now : '';

      try {
        if ($existing_application) {
          $this->repository->updateApplication((int) $existing_application['id'], [
            'metadata' => json_encode($meta),
            'changed'  => $now,
          ]);
        }
        else {
          $this->repository->insertApplication([
            'uid'              => $uid,
            'job_id'           => (int) $selected_job->id,
            'submission_status'=> 'not_started',
            'submission_method'=> 'pending',
            'apply_url'        => $apply_url,
            'ats_platform'     => $ats_platform,
            'attempt_count'    => 0,
            'metadata'         => json_encode($meta),
            'created'          => $now,
            'changed'          => $now,
          ]);
        }
        $step4_cache = $meta['step4_cache'];
      }
      catch (\Throwable $e) {
        // Non-fatal.
      }

      $has_cached_step4 = TRUE;
    }

    // Prerequisite readiness checks for display.
    $prerequisites = [];
    $prerequisites[] = [
      'label' => 'User email address available',
      'met' => $user_email !== '',
      'value' => $user_email !== '' ? $user_email : 'Missing — update your profile.',
    ];
    $prerequisites[] = [
      'label' => 'User phone number available',
      'met' => $user_phone !== '',
      'value' => $user_phone !== '' ? $user_phone : 'Missing — update your profile.',
    ];
    $prerequisites[] = [
      'label' => 'Authentication path identified (Step 3)',
      'met' => !in_array($auth_type, ['unknown', 'captcha_blocked'], TRUE),
      'value' => $auth_type,
    ];
    $prerequisites[] = [
      'label' => 'ATS destination URL available',
      'met' => $auth_url !== '',
      'value' => $auth_url !== '' ? $auth_url : 'Not available — complete Step 2/3.',
    ];

    $account_ready = in_array($account_status, ['verified', 'not_required'], TRUE);

    // Default credential values for the "create new account" form.
    $default_username = $user_email !== '' ? $user_email : 'keith.aumiller';
    $default_password = 'Unsecure01!abc';

    $content = [
      '#theme'                   => 'application_submission_step4',
      '#job_id'                  => (int) $selected_job->id,
      '#job_title'               => $job_title,
      '#company_name'            => $company_name,
      '#company_id'              => $company_id,
      '#apply_url'               => $apply_url,
      '#auth_url'                => $auth_url,
      '#auth_type'               => $auth_type,
      '#ats_platform'            => $ats_platform,
      '#user_email'              => $user_email,
      '#user_phone'              => $user_phone,
      '#user_name'               => $user_name,
      '#prerequisites'           => $prerequisites,
      '#account_status'          => $account_status,
      '#account_evidence'        => $account_evidence,
      '#account_ready'           => $account_ready,
      '#email_verified'          => $email_verified,
      '#phone_verified'          => $phone_verified,
      '#verification_method'     => $verification_method,
      '#account_created_at'      => $account_created_at,
      '#has_stored_credential'   => $has_stored_credential,
      '#stored_username'         => $stored_username,
      '#default_username'        => $default_username,
      '#default_password'        => $default_password,
      '#step4_cache_exists'      => $has_cached_step4,
      '#step4_last_run_at'       => (string) ($step4_cache['ran_at'] ?? ''),
      '#step4_ran_this_request'  => $run_step4_requested,
      '#run_step4_csrf_token'    => $this->csrfTokenGenerator->get('job_hunter_step4_run_' . (int) $selected_job->id),
      '#verification_result'     => !empty($verification_result_data) ? $verification_result_data : (is_array($step4_cache['verification_result'] ?? NULL) ? $step4_cache['verification_result'] : []),
      '#return_url'              => '/jobhunter/application-submission/' . (int) $selected_job->id,
      '#cache'                   => [
        'contexts' => ['user', 'url.query_args'],
        'tags'     => ['job_hunter:jobs', 'job_hunter:applications'],
        'max-age'  => 0,
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Step 5: Submit Application (combined Confirm Job / Locate Apply / Submit).
   *
   * Merges former Steps 5-7 into a single page with three sections:
   *   A. Confirm the job still exists on the destination ATS.
   *   B. Locate the apply control / entry point.
   *   C. Submit the application.
   *
   * POST actions via step5_action:
   *   - confirm_job_exists:        Mark that the job is verified on-site.
   *   - upload_resume_continue:     Upload tailored resume to ATS and click Continue.
  *   - run_wd_wizard_auto:         Auto-progress remaining Workday steps (2-7).
   *   - run_wd_step:                Run Playwright automation for a Workday wizard step (2-7).
   *   - advance_wd_step:            Manually mark a Workday wizard step as complete.
   *   - submit_application:         Trigger ApplicationSubmissionService.
   *   - mark_manual_submission:     Record a manual submission.
   *
   * @param int $job_id
   *   The job requisition ID.
   *
   * @return array
   *   A render array.
   */
  public function applicationSubmissionSubmitApplication(int $job_id): array {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      return ['#markup' => $this->t('You must be logged in to access this page.')];
    }

    $selected_job = $this->loadSelectedJobContext($uid, $job_id);
    if (!$selected_job) {
      $this->messenger()->addError($this->t('Job requisition not found for your account.'));
      return $this->wrapWithNavigation(['#markup' => '<p>' . $this->t('Unable to load this requisition.') . '</p>']);
    }

    $extracted = is_array($selected_job->extracted_data ?? NULL) ? $selected_job->extracted_data : [];
    $job_title = (string) ($extracted['position']['title'] ?? $selected_job->job_title ?? ('Job #' . (int) $selected_job->id));
    $company_name = (string) ($extracted['company']['name'] ?? $selected_job->company_name ?? 'Unknown');
    $company_id = (int) ($selected_job->company_id ?? 0);

    // ── Load application row + metadata ───────────────────────────────────
    $existing_application = $this->repository->findLatestApplicationByJobAndUser($uid, (int) $selected_job->id, ['id', 'apply_url', 'ats_platform', 'metadata', 'submission_status', 'confirmation_reference', 'confirmation_ref', 'attempt_count']);

    $apply_url        = (string) ($existing_application['apply_url'] ?? '');
    $ats_platform     = (string) ($existing_application['ats_platform'] ?? 'unknown');
    $submission_status = (string) ($existing_application['submission_status'] ?? 'not_started');
    $confirmation     = (string) ($existing_application['confirmation_reference'] ?? $existing_application['confirmation_ref'] ?? '');
    $attempt_count    = (int) ($existing_application['attempt_count'] ?? 0);

    // Derive last attempt details from the attempts table (if it exists).
    $last_outcome    = '';
    $last_error      = '';
    $last_attempt_at = '';
    if ($existing_application) {
      try {
        $last_attempt = $this->repository->getLastAttempt((int) $existing_application['id']);
        if ($last_attempt) {
          $last_outcome    = (string) ($last_attempt['outcome'] ?? '');
          $last_error      = (string) ($last_attempt['error_message'] ?? '');
          $last_attempt_at = (string) ($last_attempt['attempted_at'] ?? '');
        }
      }
      catch (\Throwable $e) {
        // Attempts table may not exist yet — non-fatal.
      }
    }

    $metadata_base = [];
    if (!empty($existing_application['metadata'])) {
      $decoded = json_decode((string) $existing_application['metadata'], TRUE);
      if (is_array($decoded)) {
        $metadata_base = $decoded;
      }
    }

    $auth_url  = (string) ($metadata_base['auth_url'] ?? $apply_url);
    $auth_type = (string) ($metadata_base['auth_type'] ?? 'unknown');

    // Re-detect ATS platform from auth URL if stored value is unhelpful.
    if (in_array($ats_platform, ['custom', 'unknown', ''], TRUE)) {
      $detected = $this->detectAtsPlatformFromUrl($auth_url);
      if ($detected !== 'custom') {
        $ats_platform = $detected;
        if ($existing_application) {
          $this->repository->updateApplication((int) $existing_application['id'], ['ats_platform' => $ats_platform]);
        }
      }
    }

    // ── Read cached Step 5 result ─────────────────────────────────────────
    $step5_cache = is_array($metadata_base['step5_cache'] ?? NULL) ? $metadata_base['step5_cache'] : [];
    $has_cached_step5 = !empty($step5_cache);

    $job_confirmed_on_site  = (bool) ($step5_cache['job_confirmed_on_site'] ?? FALSE);
    $apply_control_located  = (bool) ($step5_cache['apply_control_located'] ?? FALSE);
    $submission_attempted   = (bool) ($step5_cache['submission_attempted'] ?? FALSE);
    $submission_result_data = (array) ($step5_cache['submission_result'] ?? []);

    // Derive from application row data too.
    $submission_started   = in_array($submission_status, ['queued', 'pending', 'processing', 'submitted', 'confirmed', 'manual_required', 'failed', 'manual_completed', 'resume_uploaded'], TRUE);
    $submission_completed = in_array($submission_status, ['submitted', 'confirmed', 'manual_completed'], TRUE);

    // Job exists if we have title + company.
    if (!$job_confirmed_on_site && $job_title !== '' && $company_name !== '' && $company_name !== 'Unknown') {
      $job_confirmed_on_site = TRUE;
    }

    // Apply control located if we have a resolved URL + auth path.
    $auth_path_identified = !in_array($auth_type, ['unknown', 'captcha_blocked'], TRUE);
    if (!$apply_control_located && $apply_url !== '' && $auth_path_identified) {
      $apply_control_located = TRUE;
    }
    if (!$apply_control_located && ($submission_started || $attempt_count > 0)) {
      $apply_control_located = TRUE;
    }

    // ── Check for stored credentials ──────────────────────────────────────
    /** @var \Drupal\job_hunter\Service\CredentialManagementService $cred_service */
    $cred_service = \Drupal::service('job_hunter.credential_management_service');
    $has_stored_credential = FALSE;
    if ($company_id > 0) {
      $stored_credential = $cred_service->retrieveCredential($uid, $company_id, 'basic');
      $has_stored_credential = !empty($stored_credential);
    }

    // ── Upstream gate checks ──────────────────────────────────────────────
    $step4_cache = is_array($metadata_base['step4_cache'] ?? NULL) ? $metadata_base['step4_cache'] : [];
    $account_readiness_at = (string) ($metadata_base['account_readiness_at'] ?? '');
    $account_ready = $account_readiness_at !== '' || $has_stored_credential;

    $verification_result = is_array($step4_cache['verification_result'] ?? NULL) ? $step4_cache['verification_result'] : [];
    $browser_verified = !empty($verification_result['verified']);

    $prerequisites_met = $apply_url !== '' && $auth_path_identified && $account_ready;

    // ── POST Actions ──────────────────────────────────────────────────────
    $request = $this->requestStack->getCurrentRequest();
    $run_step5_requested = FALSE;
    if ($request->isMethod('POST')) {
      $token = (string) $request->request->get('csrf_token', '');
      if ($token !== '' && $this->csrfTokenGenerator->validate($token, 'job_hunter_step5_run_' . (int) $selected_job->id)) {
        $run_step5_requested = TRUE;
      }
      else {
        $this->messenger()->addError($this->t('Invalid request token. Refresh and try again.'));
      }
    }

    if ($run_step5_requested) {
      $now = date('Y-m-d H:i:s');
      $action = (string) $request->request->get('step5_action', '');
      $wizard_auto_completed_steps = [];
      $wizard_auto_last_url = '';

      // ── ACTION: Confirm job exists ──────────────────────────────────────
      if ($action === 'confirm_job_exists') {
        $job_confirmed_on_site = TRUE;
        $this->messenger()->addStatus($this->t('Job confirmed as existing on the destination site.'));
      }

      // ── ACTION: Submit application ──────────────────────────────────────
      elseif ($action === 'submit_application') {
        if (!$prerequisites_met) {
          $this->messenger()->addError($this->t('Prerequisites not met. Complete Steps 2-4 first.'));
        }
        else {
          /** @var \Drupal\job_hunter\Service\ApplicationSubmissionService $submission_svc */
          $submission_svc = \Drupal::service('job_hunter.application_submission_service');
          $submit_result = $submission_svc->submitApplication($uid, (int) $selected_job->id, TRUE);

          $submission_attempted = TRUE;
          $submission_result_data = $submit_result;

          if (!empty($submit_result['success'])) {
            $submission_started = TRUE;
            $apply_control_located = TRUE;
            $submission_status = (string) ($submit_result['status'] ?? 'queued');
            $this->messenger()->addStatus($this->t('Application submitted successfully. Status: @status', [
              '@status' => $submit_result['status'] ?? 'queued',
            ]));
          }
          else {
            $this->messenger()->addError($this->t('Submission failed: @error', [
              '@error' => $submit_result['message'] ?? 'Unknown error',
            ]));
          }
        }
      }

      // ── ACTION: Mark manual submission ──────────────────────────────────
      elseif ($action === 'mark_manual_submission') {
        $manual_confirmation = trim((string) $request->request->get('manual_confirmation', ''));
        $submission_attempted = TRUE;
        $submission_started = TRUE;
        $submission_completed = TRUE;
        $submission_result_data = [
          'success' => TRUE,
          'status' => 'manual_completed',
          'message' => 'Manually marked as submitted.',
          'manual_confirmation' => $manual_confirmation,
        ];

        // Update the application row directly.
        if ($existing_application) {
          $this->repository->updateApplication((int) $existing_application['id'], [
            'submission_status' => 'manual_completed',
            'confirmation_ref' => $manual_confirmation !== '' ? $manual_confirmation : 'Manual submission at ' . $now,
            'changed' => $now,
          ]);
        }

        $this->messenger()->addStatus($this->t('Application marked as manually submitted.'));
      }

      // ── ACTION: Upload resume and click Continue ─────────────────────
      elseif ($action === 'upload_resume_continue') {
        if (!$prerequisites_met) {
          $this->messenger()->addError($this->t('Prerequisites not met. Complete Steps 2-4 first.'));
        }
        else {
          /** @var \Drupal\job_hunter\Service\ResumeUploadService $resume_upload_svc */
          $resume_upload_svc = \Drupal::service('job_hunter.resume_upload_service');
          $upload_result = $resume_upload_svc->uploadResume((int) $selected_job->id, $uid);

          $submission_result_data = $upload_result;
          $job_confirmed_on_site = TRUE;
          $apply_control_located = TRUE;

          if (!empty($upload_result['ok'])) {
            $submission_attempted = TRUE;
            $submission_started = TRUE;
            $this->messenger()->addStatus($this->t('Resume uploaded and Continue clicked successfully. Auth verified: @email. File: @file', [
              '@email' => $upload_result['verified_email'] ?? 'unknown',
              '@file' => $upload_result['upload_filename'] ?? 'unknown',
            ]));

            // Update application row.
            if ($existing_application) {
              $this->repository->updateApplication((int) $existing_application['id'], [
                'submission_status' => 'resume_uploaded',
                'changed' => $now,
              ]);
              $submission_status = 'resume_uploaded';
            }

            // After Step 1 succeeds, auto-progress Workday steps 2-7.
            /** @var \Drupal\job_hunter\Service\WorkdayWizardService $wz_service */
            $wz_service = \Drupal::service('job_hunter.workday_wizard_service');
            $current_wizard_url = (string) ($upload_result['post_continue_url'] ?? '');
            $wizard_session_result = $wz_service->advanceWizardAutoSingleSession((int) $selected_job->id, $uid, 'my_information', [
              'apply_url' => $current_wizard_url,
              'timeout' => 320,
            ]);

            $submission_result_data = $wizard_session_result;
            $step_results = (array) ($wizard_session_result['step_results'] ?? []);
            $ordered_wd_steps = ['my_information', 'my_experience', 'application_questions', 'voluntary_disclosures', 'self_identify', 'review_submit'];
            $auto_success_count = 0;
            foreach ($ordered_wd_steps as $auto_step_key) {
              $step_result = (array) ($step_results[$auto_step_key] ?? []);
              if ((string) ($step_result['status'] ?? '') === 'pass') {
                $wizard_auto_completed_steps[$auto_step_key] = [
                  'status' => 'pass',
                  'completed_at' => $now,
                  'result' => $step_result,
                ];
                $auto_success_count++;
              }
              elseif (!empty($step_result)) {
                $wizard_auto_completed_steps[$auto_step_key] = [
                  'status' => 'failed',
                  'completed_at' => $now,
                  'result' => $step_result,
                ];
                break;
              }
            }

            $next_url = trim((string) ($wizard_session_result['post_continue_url'] ?? ''));
            if ($next_url !== '') {
              $wizard_auto_last_url = $next_url;
            }

            $review_status = (string) (($step_results['review_submit']['status'] ?? ''));
            if ($review_status === 'pass') {
              $submission_attempted = TRUE;
              $submission_started = TRUE;
              $submission_completed = TRUE;
              if ($existing_application) {
                $this->repository->updateApplication((int) $existing_application['id'], ['submission_status' => 'submitted', 'changed' => $now]);
                $submission_status = 'submitted';
              }
            }
            elseif ($auto_success_count > 0 || !empty($wizard_session_result['error'])) {
              $this->messenger()->addError($this->t('Wizard auto-progress failed in single-session mode: @error', [
                '@error' => (string) ($wizard_session_result['error'] ?? 'Unknown error'),
              ]));
            }

            if ($auto_success_count > 0) {
              $this->messenger()->addStatus($this->t('Wizard auto-progress completed @count step(s) after Autofill (single session).', [
                '@count' => $auto_success_count,
              ]));
            }
          }
          else {
            $this->messenger()->addError($this->t('Resume upload failed: @error', [
              '@error' => $upload_result['error'] ?? 'Unknown error',
            ]));
          }
        }
      }

      // ── ACTION: Auto-progress remaining Workday wizard steps ──────────
      elseif ($action === 'run_wd_wizard_auto') {
        if (!$prerequisites_met) {
          $this->messenger()->addError($this->t('Prerequisites not met. Complete Steps 2-4 first.'));
        }
        else {
          $wd_steps_cached = is_array($step5_cache['wd_flow_steps'] ?? NULL) ? $step5_cache['wd_flow_steps'] : [];
          $ordered_steps = ['autofill_resume', 'my_information', 'my_experience', 'application_questions', 'voluntary_disclosures', 'self_identify', 'review_submit'];

          // Determine first incomplete step.
          $first_incomplete_index = -1;
          foreach ($ordered_steps as $idx => $k) {
            $status = (string) (($wd_steps_cached[$k]['status'] ?? 'not_started'));
            if ($status !== 'pass') {
              $first_incomplete_index = $idx;
              break;
            }
          }

          if ($first_incomplete_index === -1) {
            $this->messenger()->addStatus($this->t('All Workday wizard steps are already complete.'));
          }
          elseif ($first_incomplete_index === 0) {
            $this->messenger()->addError($this->t('Run Step 1 (Autofill with Resume) first.'));
          }
          else {
            /** @var \Drupal\job_hunter\Service\WorkdayWizardService $wz_service */
            $wz_service = \Drupal::service('job_hunter.workday_wizard_service');
            $current_wizard_url = (string) ($step5_cache['wd_last_url'] ?? $step5_cache['resume_upload_result']['post_continue_url'] ?? '');
            // Even if cache says later steps passed, Workday can reopen earlier
            // pages in a new session. Start from My Information to keep flow stable.
            $start_step_key = 'my_information';

            $wizard_session_result = $wz_service->advanceWizardAutoSingleSession((int) $selected_job->id, $uid, $start_step_key, [
              'apply_url' => $current_wizard_url,
              'timeout' => 320,
            ]);

            $submission_result_data = $wizard_session_result;
            $step_results = (array) ($wizard_session_result['step_results'] ?? []);
            $ordered_wd_steps = ['my_information', 'my_experience', 'application_questions', 'voluntary_disclosures', 'self_identify', 'review_submit'];
            $auto_success_count = 0;
            foreach ($ordered_wd_steps as $step_key) {
              $step_result = (array) ($step_results[$step_key] ?? []);
              if ((string) ($step_result['status'] ?? '') === 'pass') {
                $wizard_auto_completed_steps[$step_key] = [
                  'status' => 'pass',
                  'completed_at' => $now,
                  'result' => $step_result,
                ];
                $submission_started = TRUE;
                $auto_success_count++;
              }
              elseif (!empty($step_result)) {
                $wizard_auto_completed_steps[$step_key] = [
                  'status' => 'failed',
                  'completed_at' => $now,
                  'result' => $step_result,
                ];
                break;
              }
            }

            $next_url = trim((string) ($wizard_session_result['post_continue_url'] ?? ''));
            if ($next_url !== '') {
              $wizard_auto_last_url = $next_url;
            }

            if ((string) (($step_results['review_submit']['status'] ?? '')) === 'pass') {
              $submission_attempted = TRUE;
              $submission_completed = TRUE;
              if ($existing_application) {
                $this->repository->updateApplication((int) $existing_application['id'], ['submission_status' => 'submitted', 'changed' => $now]);
                $submission_status = 'submitted';
              }
            }
            else {
              $this->messenger()->addError($this->t('Wizard auto-progress failed in single-session mode: @error', [
                '@error' => (string) ($wizard_session_result['error'] ?? 'Unknown error'),
              ]));
            }

            if ($auto_success_count > 0) {
              $this->messenger()->addStatus($this->t('Wizard auto-progress completed @count step(s) in single session.', [
                '@count' => $auto_success_count,
              ]));
            }
          }
        }
      }

      // ── ACTION: Run Workday wizard step automation ──────────────────
      elseif ($action === 'run_wd_step') {
        $wd_step_key = (string) $request->request->get('wd_step_key', '');
        $wd_automatable_steps = ['my_information', 'my_experience', 'application_questions', 'voluntary_disclosures', 'self_identify', 'review_submit'];

        if (!in_array($wd_step_key, $wd_automatable_steps, TRUE)) {
          $this->messenger()->addError($this->t('Invalid step key for automation.'));
        }
        elseif (!$prerequisites_met) {
          $this->messenger()->addError($this->t('Prerequisites not met. Complete Steps 2-4 first.'));
        }
        else {
          /** @var \Drupal\job_hunter\Service\WorkdayWizardService $wz_service */
          $wz_service = \Drupal::service('job_hunter.workday_wizard_service');
          $wz_result = $wz_service->advanceStep((int) $selected_job->id, $uid, $wd_step_key, [
            'timeout' => ($wd_step_key === 'review_submit') ? 220 : 120,
          ]);

          $submission_result_data = $wz_result;
          $job_confirmed_on_site = TRUE;
          $apply_control_located = TRUE;

          if (!empty($wz_result['ok'])) {
            $submission_started = TRUE;
            $next_url = trim((string) ($wz_result['post_continue_url'] ?? ''));
            if ($next_url !== '') {
              $wizard_auto_last_url = $next_url;
            }
            $this->messenger()->addStatus($this->t('Workday step "@step" automated successfully. Page: @page', [
              '@step' => $wd_step_key,
              '@page' => $wz_result['detected_page'] ?? 'unknown',
            ]));

            // If review_submit completed, mark the application as submitted.
            if ($wd_step_key === 'review_submit') {
              $submission_attempted = TRUE;
              $submission_completed = TRUE;
              if ($existing_application) {
                $this->repository->updateApplication((int) $existing_application['id'], ['submission_status' => 'submitted', 'changed' => $now]);
                $submission_status = 'submitted';
              }
            }
          }
          else {
            $needs_manual = !empty($wz_result['needs_manual_review']);
            if ($needs_manual) {
              $this->messenger()->addWarning($this->t('Workday step "@step" needs manual review. Fields skipped: @fields', [
                '@step' => $wd_step_key,
                '@fields' => implode(', ', $wz_result['fields_skipped'] ?? []),
              ]));
            }
            else {
              $this->messenger()->addError($this->t('Workday step "@step" automation failed: @error', [
                '@step' => $wd_step_key,
                '@error' => $wz_result['error'] ?? 'Unknown error',
              ]));
            }
          }
        }
      }

      // ── ACTION: Manually advance a Workday wizard step ──────────────
      elseif ($action === 'advance_wd_step') {
        $wd_step_key = (string) $request->request->get('wd_step_key', '');
        $wd_step_labels = [
          'my_information'        => 'My Information',
          'my_experience'         => 'My Experience',
          'application_questions' => 'Application Questions',
          'voluntary_disclosures' => 'Voluntary Disclosures',
          'self_identify'         => 'Self-Identify',
          'review_submit'         => 'Review & Submit',
        ];
        if (isset($wd_step_labels[$wd_step_key])) {
          $this->messenger()->addStatus($this->t('Workday step "@step" marked as complete.', [
            '@step' => $wd_step_labels[$wd_step_key],
          ]));
          // If this is review_submit, mark the application as submitted.
          if ($wd_step_key === 'review_submit') {
            $submission_attempted = TRUE;
            $submission_started = TRUE;
            $submission_completed = TRUE;
            $submission_result_data = [
              'success' => TRUE,
              'status' => 'submitted',
              'message' => 'Application submitted via Workday wizard flow.',
            ];
            if ($existing_application) {
              $this->repository->updateApplication((int) $existing_application['id'], [
                'submission_status' => 'submitted',
                'changed' => $now,
              ]);
              $submission_status = 'submitted';
            }
          }
        }
        else {
          $this->messenger()->addError($this->t('Unknown Workday step key.'));
        }
      }

      // Persist Step 5 cache.
      $meta = $metadata_base;
      // Update Workday flow step statuses based on action.
      $wd_steps_update = is_array($step5_cache['wd_flow_steps'] ?? NULL) ? $step5_cache['wd_flow_steps'] : [];
      if ($action === 'upload_resume_continue' && !empty($submission_result_data['ok'])) {
        $wd_steps_update['autofill_resume'] = [
          'status' => 'pass',
          'completed_at' => $now,
          'result' => $submission_result_data,
        ];
      }
      elseif ($action === 'run_wd_step' && !empty($submission_result_data['ok'])) {
        $wd_step_key = (string) $request->request->get('wd_step_key', '');
        if ($wd_step_key !== '') {
          $wd_steps_update[$wd_step_key] = [
            'status' => 'pass',
            'completed_at' => $now,
            'result' => $submission_result_data,
          ];
        }
      }
      elseif ($action === 'advance_wd_step') {
        $wd_step_key = (string) $request->request->get('wd_step_key', '');
        if ($wd_step_key !== '' && in_array($wd_step_key, ['my_information', 'my_experience', 'application_questions', 'voluntary_disclosures', 'self_identify', 'review_submit'], TRUE)) {
          $wd_steps_update[$wd_step_key] = [
            'status' => 'pass',
            'completed_at' => $now,
          ];
        }
      }
      elseif ($action === 'mark_manual_submission') {
        // Mark all WD steps as pass when manually submitted.
        foreach (['autofill_resume', 'my_information', 'my_experience', 'application_questions', 'voluntary_disclosures', 'self_identify', 'review_submit'] as $k) {
          if (empty($wd_steps_update[$k])) {
            $wd_steps_update[$k] = ['status' => 'pass', 'completed_at' => $now];
          }
        }
      }

      if (!empty($wizard_auto_completed_steps)) {
        foreach ($wizard_auto_completed_steps as $k => $v) {
          $wd_steps_update[$k] = $v;
        }
      }

      $meta['step5_cache'] = [
        'ran_at'                => $now,
        'job_confirmed_on_site' => $job_confirmed_on_site,
        'apply_control_located' => $apply_control_located,
        'submission_attempted'  => $submission_attempted,
        'submission_result'     => $submission_result_data,
        'resume_upload_result'  => ($action === 'upload_resume_continue') ? $submission_result_data : ($step5_cache['resume_upload_result'] ?? []),
        'wd_last_url'           => $wizard_auto_last_url !== ''
          ? $wizard_auto_last_url
          : (
            trim((string) ($submission_result_data['post_continue_url'] ?? '')) !== ''
            ? trim((string) ($submission_result_data['post_continue_url'] ?? ''))
            : (
              trim((string) ($step5_cache['wd_last_url'] ?? '')) !== ''
              ? trim((string) ($step5_cache['wd_last_url'] ?? ''))
              : trim((string) ($step5_cache['resume_upload_result']['post_continue_url'] ?? ''))
            )
          ),
        'wd_flow_steps'         => $wd_steps_update,
      ];

      try {
        if ($existing_application) {
          $this->repository->updateApplication((int) $existing_application['id'], [
            'metadata' => json_encode($meta),
            'changed'  => $now,
          ]);
        }
        $step5_cache = $meta['step5_cache'];
      }
      catch (\Throwable $e) {
        // Non-fatal.
      }

      $has_cached_step5 = TRUE;
    }

    // ── Section readiness ─────────────────────────────────────────────────
    $section_a_status = $job_confirmed_on_site ? 'pass' : 'incomplete';
    $section_b_status = $apply_control_located ? 'pass' : 'incomplete';
    $section_c_status = $submission_completed ? 'pass' : ($submission_started ? 'in_progress' : 'incomplete');

    $all_pass = $section_a_status === 'pass' && $section_b_status === 'pass' && $section_c_status === 'pass';

    // ── Resolve resume availability ───────────────────────────────────────
    $has_tailored_resume = FALSE;
    $resume_pdf_basename = '';
    try {
      $resume_uri = $this->repository->getResumeUri($uid, (int) $selected_job->id);
      if ($resume_uri) {
        $real_path = \Drupal::service('file_system')->realpath($resume_uri);
        if ($real_path && file_exists($real_path)) {
          $has_tailored_resume = TRUE;
          $resume_pdf_basename = basename($real_path);
        }
      }
    }
    catch (\Throwable $e) {
      // Non-fatal.
    }

    // Resume upload result (from cache or this request).
    $resume_upload_result = (array) ($step5_cache['resume_upload_result'] ?? []);
    $resume_uploaded = !empty($resume_upload_result['ok']);

    // ── Build Workday flow steps tracker ───────────────────────────────────
    $wd_steps_cached = is_array($step5_cache['wd_flow_steps'] ?? NULL) ? $step5_cache['wd_flow_steps'] : [];
    $wd_flow_definition = [
      ['key' => 'autofill_resume',       'label' => 'Autofill with Resume',    'number' => 1],
      ['key' => 'my_information',        'label' => 'My Information',          'number' => 2],
      ['key' => 'my_experience',         'label' => 'My Experience',           'number' => 3],
      ['key' => 'application_questions', 'label' => 'Application Questions',   'number' => 4],
      ['key' => 'voluntary_disclosures', 'label' => 'Voluntary Disclosures',   'number' => 5],
      ['key' => 'self_identify',         'label' => 'Self-Identify',           'number' => 6],
      ['key' => 'review_submit',         'label' => 'Review & Submit',         'number' => 7],
    ];

    $wd_flow_steps = [];
    $wd_current_step = 1;
    $wd_all_complete = TRUE;
    foreach ($wd_flow_definition as $step_def) {
      $step_data = $wd_steps_cached[$step_def['key']] ?? [];
      $step_status = (string) ($step_data['status'] ?? 'not_started');
      if ($step_status !== 'pass') {
        $wd_all_complete = FALSE;
      }
      $wd_flow_steps[] = [
        'key'          => $step_def['key'],
        'label'        => $step_def['label'],
        'number'       => $step_def['number'],
        'status'       => $step_status,
        'completed_at' => (string) ($step_data['completed_at'] ?? ''),
      ];
    }
    // Determine current active step (first non-pass).
    foreach ($wd_flow_steps as $s) {
      if ($s['status'] !== 'pass') {
        $wd_current_step = $s['number'];
        break;
      }
    }
    if ($wd_all_complete) {
      $wd_current_step = 8; // All done.
    }

    // Refine section C status based on WD flow step progress.
    $wd_completed_count = 0;
    foreach ($wd_flow_steps as $s) {
      if ($s['status'] === 'pass') {
        $wd_completed_count++;
      }
    }
    if ($wd_all_complete && !$submission_completed) {
      // All WD steps done but DB not yet updated (edge case) — mark pass.
      $section_c_status = 'pass';
    }
    elseif ($wd_completed_count > 0 && $section_c_status === 'incomplete') {
      $section_c_status = 'in_progress';
    }
    // Re-derive all_pass after potential section C upgrade.
    $all_pass = $section_a_status === 'pass' && $section_b_status === 'pass' && $section_c_status === 'pass';

    $content = [
      '#theme'                   => 'application_submission_step5',
      '#job_id'                  => (int) $selected_job->id,
      '#job_title'               => $job_title,
      '#company_name'            => $company_name,
      '#company_id'              => $company_id,
      '#apply_url'               => $apply_url,
      '#auth_url'                => $auth_url,
      '#auth_type'               => $auth_type,
      '#ats_platform'            => $ats_platform,
      '#account_ready'           => $account_ready,
      '#browser_verified'        => $browser_verified,
      '#has_stored_credential'   => $has_stored_credential,
      '#prerequisites_met'       => $prerequisites_met,
      '#job_confirmed_on_site'   => $job_confirmed_on_site,
      '#apply_control_located'   => $apply_control_located,
      '#submission_attempted'    => $submission_attempted,
      '#submission_started'      => $submission_started,
      '#submission_completed'    => $submission_completed,
      '#submission_status'       => $submission_status,
      '#submission_result'       => $submission_result_data,
      '#confirmation'            => $confirmation,
      '#attempt_count'           => $attempt_count,
      '#last_outcome'            => $last_outcome,
      '#last_error'              => $last_error,
      '#last_attempt_at'         => $last_attempt_at,
      '#section_a_status'        => $section_a_status,
      '#section_b_status'        => $section_b_status,
      '#section_c_status'        => $section_c_status,
      '#all_pass'                => $all_pass,
      '#step5_cache_exists'      => $has_cached_step5,
      '#step5_last_run_at'       => (string) ($step5_cache['ran_at'] ?? ''),
      '#step5_ran_this_request'  => $run_step5_requested,
      '#run_step5_csrf_token'    => $this->csrfTokenGenerator->get('job_hunter_step5_run_' . (int) $selected_job->id),
      '#return_url'              => '/jobhunter/application-submission/' . (int) $selected_job->id,
      '#has_tailored_resume'     => $has_tailored_resume,
      '#resume_pdf_basename'     => $resume_pdf_basename,
      '#resume_upload_result'    => $resume_upload_result,
      '#resume_uploaded'         => $resume_uploaded,
      '#wd_flow_steps'           => $wd_flow_steps,
      '#wd_current_step'         => $wd_current_step,
      '#wd_all_complete'         => $wd_all_complete,
      '#cache'                   => [
        'contexts' => ['user', 'url.query_args'],
        'tags'     => ['job_hunter:jobs', 'job_hunter:applications'],
        'max-age'  => 0,
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Securely streams Step 5 screenshot files for the authenticated owner.
   */
  private function detectAtsPlatformFromUrl(string $url): string {
    if ($url === '') {
      return 'custom';
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '' || $host === FALSE) {
      return 'custom';
    }
    $patterns = [
      'myworkdayjobs'  => ['myworkdayjobs.com', 'wd5.myworkdayjobs', 'wd3.myworkdayjobs', 'wd1.myworkdayjobs'],
      'greenhouse'     => ['greenhouse.io', 'boards.greenhouse.io'],
      'lever'          => ['lever.co', 'jobs.lever.co'],
      'icims'          => ['icims.com'],
      'taleo'          => ['taleo.net'],
      'smartrecruiters' => ['smartrecruiters.com'],
      'ashbyhq'        => ['ashbyhq.com'],
      'bamboohr'       => ['bamboohr.com'],
      'jobvite'        => ['jobvite.com'],
      'ultipro'        => ['ultipro.com'],
      'successfactors'  => ['successfactors.com', 'successfactors.eu'],
      'brassring'      => ['brassring.com'],
    ];
    foreach ($patterns as $platform => $domains) {
      foreach ($domains as $domain) {
        if (str_contains($host, $domain)) {
          return $platform;
        }
      }
    }
    return 'custom';
  }

}
