<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\job_hunter\Service\JobDiscoveryService;
use Drupal\job_hunter\Service\OpportunityManagementService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for opportunity management interface.
 * 
 * Provides admin interface for managing saved jobs, search history, and cached results.
 * All AJAX endpoints require POST requests and validate input parameters.
 * 
 * @package Drupal\job_hunter\Controller
 */
class OpportunityManagementController extends ControllerBase {

  use JobHunterControllerTrait;

  /**
   * The job discovery service.
   *
   * @var \Drupal\job_hunter\Service\JobDiscoveryService
   */
  protected JobDiscoveryService $jobDiscoveryService;

  /**
   * The opportunity management service.
   *
   * @var \Drupal\job_hunter\Service\OpportunityManagementService
   */
  protected OpportunityManagementService $managementService;

  /**
   * Constructs an OpportunityManagementController.
   *
   * @param \Drupal\job_hunter\Service\JobDiscoveryService $job_discovery_service
   *   The job discovery service.
   * @param \Drupal\job_hunter\Service\OpportunityManagementService $management_service
   *   The opportunity management service.
   */
  public function __construct(
    JobDiscoveryService $job_discovery_service,
    OpportunityManagementService $management_service
  ) {
    $this->jobDiscoveryService = $job_discovery_service;
    $this->managementService = $management_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('job_hunter.job_discovery_service'),
      $container->get('job_hunter.opportunity_management_service')
    );
  }

  /**
   * Main opportunity management page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   Render array for the page.
   */
  public function managementPage(Request $request): array {
    // Get statistics
    $stats = $this->managementService->getManagementStats();

    // Get filters from request
    $filters = [
      'company' => $request->query->get('company', ''),
      'status' => $request->query->get('status', ''),
      'external_source' => $request->query->get('external_source', ''),
      'date_range' => $request->query->get('date_range', 'all'),
    ];

    // Get saved jobs using existing service
    $saved_jobs = $this->jobDiscoveryService->getSavedJobs($filters);

    // Get search history
    $search_history = $this->managementService->getSearchHistory([
      'date_range' => $filters['date_range'],
    ]);

    // Get company names for filter dropdown
    $companies = $this->jobDiscoveryService->getCompanyNames();

    $content = [
      '#theme' => 'opportunity_management_page',
      '#stats' => $stats,
      '#saved_jobs' => $saved_jobs,
      '#search_history' => $search_history,
      '#companies' => $companies,
      '#filters' => $filters,
      '#attached' => [
        'library' => [
          'job_hunter/opportunity-management',
        ],
      ],
      '#cache' => [
        'max-age' => 0, // Don't cache this page
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * AJAX endpoint to delete a single job.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success status.
   */
  public function deleteJobAjax(Request $request): JsonResponse {
    $job_id = $request->request->get('job_id');

    // Validate job ID
    $validation = $this->validateId($job_id, 'Job ID');
    if ($validation !== TRUE) {
      return $validation;
    }

    $success = $this->managementService->deleteJob((int) $job_id);

    return new JsonResponse([
      'success' => $success,
      'message' => $success 
        ? $this->t('Job deleted successfully.') 
        : $this->t('Failed to delete job.'),
    ]);
  }

  /**
   * AJAX endpoint to delete search history.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success status.
   */
  public function deleteSearchHistoryAjax(Request $request): JsonResponse {
    $search_id = $request->request->get('search_id');

    // Validate search ID
    $validation = $this->validateId($search_id, 'Search ID');
    if ($validation !== TRUE) {
      return $validation;
    }

    $success = $this->managementService->deleteSearchHistory((int) $search_id);

    return new JsonResponse([
      'success' => $success,
      'message' => $success 
        ? $this->t('Search history and cached results deleted successfully.') 
        : $this->t('Failed to delete search history.'),
    ]);
  }

  /**
   * AJAX endpoint for bulk delete operations.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with operation results.
   */
  public function bulkDeleteAjax(Request $request): JsonResponse {
    $type = $request->request->get('type'); // 'jobs' or 'searches'
    $ids = $request->request->get('ids', []);

    if (empty($type) || empty($ids) || !is_array($ids)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('Invalid request parameters.'),
      ], 400);
    }

    // Enforce max limit
    if (count($ids) > OpportunityManagementService::MAX_BULK_DELETE) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('Maximum @max records allowed per bulk operation.', [
          '@max' => OpportunityManagementService::MAX_BULK_DELETE,
        ]),
      ], 400);
    }

    $result = match($type) {
      'jobs' => $this->managementService->bulkDeleteJobs($ids),
      'searches' => $this->managementService->bulkDeleteSearches($ids),
      default => ['success' => 0, 'failed' => count($ids)],
    };

    return new JsonResponse([
      'success' => $result['success'] > 0,
      'message' => $this->t('Deleted @success records. @failed failed.', [
        '@success' => $result['success'],
        '@failed' => $result['failed'],
      ]),
      'stats' => $result,
    ]);
  }

  /**
   * Validate an ID parameter.
   *
   * @param mixed $id
   *   The ID to validate.
   * @param string $label
   *   The label for the ID (for error messages).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|bool
   *   JsonResponse if validation fails, TRUE if valid.
   */
  protected function validateId($id, string $label) {
    if (empty($id)) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('@label is required.', ['@label' => $label]),
      ], 400);
    }

    if (!is_numeric($id) || (int) $id <= 0) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('@label must be a valid positive integer.', ['@label' => $label]),
      ], 400);
    }

    return TRUE;
  }

}
