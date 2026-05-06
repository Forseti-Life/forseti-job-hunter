<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\job_hunter\Service\GoogleJobsService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Google Jobs Integration.
 * 
 * Provides UI and API endpoints for integrating job postings with Google for Jobs
 * via Schema.org JobPosting structured data.
 */
class GoogleJobsIntegrationController extends ControllerBase {
  use JobHunterControllerTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The Google Jobs service.
   *
   * @var \Drupal\job_hunter\Service\GoogleJobsService
   */
  protected $googleJobsService;

  /**
   * Constructs a GoogleJobsIntegrationController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\job_hunter\Service\GoogleJobsService $google_jobs_service
   *   The Google Jobs service.
   */
  public function __construct(Connection $database, GoogleJobsService $google_jobs_service) {
    $this->database = $database;
    $this->googleJobsService = $google_jobs_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('job_hunter.google_jobs_service')
    );
  }

  /**
   * Google Jobs Integration home page.
   *
   * @return array
   *   Render array for the page.
   */
  public function home() {
    try {
      // Get statistics
      $stats = $this->getIntegrationStatistics();
      
      // Get recent job postings with sync status
      $recent_jobs = $this->getRecentJobsWithSyncStatus(10);
      
      // Build content
      $content = [
        '#theme' => 'google_jobs_integration_home',
        '#stats' => $stats,
        '#recent_jobs' => $recent_jobs,
        '#documentation_url' => Url::fromRoute('job_hunter.documentation.google_jobs')->toString(),
        '#attached' => [
          'library' => [
            'job_hunter/google_jobs_integration',
            'job_hunter/job-hunter-home',
          ],
        ],
      ];
      
      return $this->wrapWithNavigation($content, ['job_hunter/google_jobs_integration']);
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('Error loading Google Jobs home page: @error', [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An error occurred while loading the Google Jobs integration page.'));
      
      // Return minimal page with error message
      return $this->wrapWithNavigation([
        '#markup' => '<p>' . $this->t('Unable to load Google Jobs integration data at this time.') . '</p>',
      ]);
    }
  }

  /**
   * Get integration statistics.
   *
   * @return array
   *   Statistics array.
   */
  protected function getIntegrationStatistics() {
    try {
      // Total job postings
      $total_jobs = $this->database->select('jobhunter_job_requirements', 'j')
        ->countQuery()
        ->execute()
        ->fetchField();
      
      // Get all sync statistics in a single query to avoid N+1 pattern
      $query = "
        SELECT 
          COUNT(CASE WHEN is_enabled = 1 THEN 1 END) as enabled_count,
          COUNT(CASE WHEN validation_status = 'valid' AND is_enabled = 1 THEN 1 END) as valid_count,
          COUNT(CASE WHEN validation_status = 'invalid' AND is_enabled = 1 THEN 1 END) as invalid_count,
          COUNT(CASE WHEN google_indexing_status = 'indexed' AND is_enabled = 1 THEN 1 END) as indexed_count,
          COALESCE(SUM(impressions_count), 0) as total_impressions,
          COALESCE(SUM(clicks_count), 0) as total_clicks
        FROM {jobhunter_google_jobs_sync}
      ";
      
      $stats = $this->database->query($query)->fetchObject();
      
      $impressions = (int) $stats->total_impressions;
      $clicks = (int) $stats->total_clicks;
      $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0;
      
      return [
        'total_jobs' => (int) $total_jobs,
        'enabled_count' => (int) $stats->enabled_count,
        'valid_count' => (int) $stats->valid_count,
        'invalid_count' => (int) $stats->invalid_count,
        'indexed_count' => (int) $stats->indexed_count,
        'total_impressions' => $impressions,
        'total_clicks' => $clicks,
        'ctr' => $ctr,
      ];
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('Error fetching Google Jobs statistics: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      // Return default values on error
      return [
        'total_jobs' => 0,
        'enabled_count' => 0,
        'valid_count' => 0,
        'invalid_count' => 0,
        'indexed_count' => 0,
        'total_impressions' => 0,
        'total_clicks' => 0,
        'ctr' => 0,
      ];
    }
  }

  /**
   * Get recent job postings with sync status.
   *
   * @param int $limit
   *   Number of jobs to return.
   *
   * @return array
   *   Array of job data.
   */
  protected function getRecentJobsWithSyncStatus($limit = 10) {
    $company_name_field = $this->database->schema()->fieldExists('jobhunter_companies', 'name')
      ? 'name'
      : 'company_name';

    $query = $this->database->select('jobhunter_job_requirements', 'j');
    $query->leftJoin('jobhunter_companies', 'c', 'j.company_id = c.id');
    $query->leftJoin('jobhunter_google_jobs_sync', 'g', 'j.id = g.job_id');
    $query->addField('c', $company_name_field, 'company_name');
    
    $query->fields('j', ['id', 'job_title', 'created'])
      ->fields('g', [
        'is_enabled',
        'validation_status',
        'last_validated',
        'google_indexing_status',
        'impressions_count',
        'clicks_count',
      ])
      ->orderBy('j.created', 'DESC')
      ->range(0, $limit);
    
    $results = $query->execute()->fetchAll();
    
    $jobs = [];
    foreach ($results as $row) {
      $jobs[] = [
        'id' => $row->id,
        'title' => $row->job_title,
        'company' => $row->company_name,
        'created' => $row->created,
        'enabled' => $row->is_enabled ?? 0,
        'validation_status' => $row->validation_status ?? 'pending',
        'last_validated' => $row->last_validated,
        'indexing_status' => $row->google_indexing_status ?? 'unknown',
        'impressions' => $row->impressions_count ?? 0,
        'clicks' => $row->clicks_count ?? 0,
        'view_url' => Url::fromRoute('job_hunter.google_jobs_job_detail', ['job_id' => $row->id])->toString(),
      ];
    }
    
    return $jobs;
  }

  /**
   * Job detail page for Google Jobs integration.
   *
   * @param int $job_id
   *   The job ID.
   *
   * @return array
   *   Render array.
   */
  public function jobDetail($job_id) {
    try {
      // Validate job_id
      if (!is_numeric($job_id)) {
        $this->messenger()->addError($this->t('Invalid job ID.'));
        return $this->redirect('job_hunter.google_jobs_home');
      }
      
      // Get job data
      $job = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j')
        ->condition('id', $job_id)
        ->execute()
        ->fetchObject();
      
      if (!$job) {
        $this->messenger()->addError($this->t('Job not found.'));
        return $this->redirect('job_hunter.google_jobs_home');
      }
      
      // Get company
      $company = $this->database->select('jobhunter_companies', 'c')
        ->fields('c')
        ->condition('id', $job->company_id)
        ->execute()
        ->fetchObject();
      
      // Get sync status
      $sync = $this->database->select('jobhunter_google_jobs_sync', 'g')
        ->fields('g')
        ->condition('job_id', $job_id)
        ->execute()
        ->fetchObject();
      
      // Get validation history
      $validation_log = $this->database->select('jobhunter_google_jobs_validation_log', 'v')
        ->fields('v')
        ->condition('job_id', $job_id)
        ->orderBy('created', 'DESC')
        ->range(0, 10)
        ->execute()
        ->fetchAll();
      
      // Pre-decode JSON fields for Twig (json_decode filter doesn't exist in Drupal).
      foreach ($validation_log as $log) {
        $log->errors_decoded = !empty($log->errors) ? json_decode($log->errors, TRUE) : [];
        $log->warnings_decoded = !empty($log->warnings) ? json_decode($log->warnings, TRUE) : [];
      }

      // Pre-decode sync validation errors for Twig.
      $sync_validation_errors = [];
      if ($sync && !empty($sync->validation_errors)) {
        $sync_validation_errors = json_decode($sync->validation_errors, TRUE) ?: [];
      }

      // Build content
      $content = [
        '#theme' => 'google_jobs_job_detail',
        '#job' => $job,
        '#company' => $company,
        '#sync' => $sync,
        '#validation_log' => $validation_log,
        '#sync_validation_errors' => $sync_validation_errors,
        '#attached' => [
          'library' => [
            'job_hunter/google_jobs_integration',
          ],
        ],
      ];
      
      return $this->wrapWithNavigation($content, ['job_hunter/google_jobs_integration']);
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('Error loading job detail page for job @job_id: @error', [
        '@job_id' => $job_id,
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An error occurred while loading the job details.'));
      return $this->redirect('job_hunter.google_jobs_home');
    }
  }

  /**
   * AJAX: Enable/disable Google Jobs integration for a job.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function toggleJobSync(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);
      
      // Validate input
      if (!is_array($data)) {
        return new JsonResponse(['error' => 'Invalid request data'], 400);
      }
      
      $job_id = $data['job_id'] ?? NULL;
      $enabled = $data['enabled'] ?? 1;
      
      if (!$job_id || !is_numeric($job_id)) {
        return new JsonResponse(['error' => 'Missing or invalid job_id'], 400);
      }
      
      // Verify job exists
      $job_exists = $this->database->select('jobhunter_job_requirements', 'j')
        ->condition('id', $job_id)
        ->countQuery()
        ->execute()
        ->fetchField();
      
      if (!$job_exists) {
        return new JsonResponse(['error' => 'Job not found'], 404);
      }
      
      // Check if sync record exists
      $exists = $this->database->select('jobhunter_google_jobs_sync', 'g')
        ->condition('job_id', $job_id)
        ->countQuery()
        ->execute()
        ->fetchField();
      
      // Use merge for safer upsert operation
      $this->database->merge('jobhunter_google_jobs_sync')
        ->key(['job_id' => $job_id])
        ->fields([
          'is_enabled' => $enabled ? 1 : 0,
          'updated' => time(),
        ])
        ->insertFields([
          'created' => time(),
          'validation_status' => 'pending',
        ])
        ->execute();
      
      $this->getLogger('job_hunter')->info('Google Jobs sync toggled for job @job_id: @status', [
        '@job_id' => $job_id,
        '@status' => $enabled ? 'enabled' : 'disabled',
      ]);
      
      return new JsonResponse([
        'success' => TRUE,
        'message' => $enabled ? 'Google Jobs integration enabled' : 'Google Jobs integration disabled',
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('Error toggling Google Jobs sync: @error', [
        '@error' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'An error occurred while updating sync status',
      ], 500);
    }
  }

  /**
   * AJAX: Generate structured data for a job.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with structured data.
   */
  public function generateStructuredData(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);
      
      // Validate input
      if (!is_array($data)) {
        return new JsonResponse(['error' => 'Invalid request data'], 400);
      }
      
      $job_id = $data['job_id'] ?? NULL;
      
      if (!$job_id || !is_numeric($job_id)) {
        return new JsonResponse(['error' => 'Missing or invalid job_id'], 400);
      }
      
      $structured_data = $this->googleJobsService->generateJobPostingJsonLd($job_id);
      
      // Save to sync table
      $this->database->merge('jobhunter_google_jobs_sync')
        ->key(['job_id' => $job_id])
        ->fields([
          'structured_data_json' => json_encode($structured_data, JSON_PRETTY_PRINT),
          'updated' => time(),
        ])
        ->insertFields([
          'created' => time(),
          'is_enabled' => 1,
          'validation_status' => 'pending',
        ])
        ->execute();
      
      $this->getLogger('job_hunter')->info('Generated structured data for job @job_id', [
        '@job_id' => $job_id,
      ]);
      
      return new JsonResponse([
        'success' => TRUE,
        'structured_data' => $structured_data,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('Error generating structured data: @error', [
        '@error' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * AJAX: Validate structured data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with validation results.
   */
  public function validateStructuredData(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);
      
      // Validate input
      if (!is_array($data)) {
        return new JsonResponse(['error' => 'Invalid request data'], 400);
      }
      
      $job_id = $data['job_id'] ?? NULL;
      
      if (!$job_id || !is_numeric($job_id)) {
        return new JsonResponse(['error' => 'Missing or invalid job_id'], 400);
      }
      
      $validation_result = $this->googleJobsService->validateJobPosting($job_id);
      
      // Get sync ID
      $sync_id = $this->database->select('jobhunter_google_jobs_sync', 'g')
        ->fields('g', ['id'])
        ->condition('job_id', $job_id)
        ->execute()
        ->fetchField();
      
      if ($sync_id) {
        // Update sync record
        $this->database->update('jobhunter_google_jobs_sync')
          ->fields([
            'validation_status' => $validation_result['status'],
            'validation_errors' => json_encode($validation_result['errors'] ?? []),
            'last_validated' => time(),
            'updated' => time(),
          ])
          ->condition('id', $sync_id)
          ->execute();
        
        // Log validation attempt
        $this->database->insert('jobhunter_google_jobs_validation_log')
          ->fields([
            'sync_id' => $sync_id,
            'job_id' => $job_id,
            'validation_type' => 'schema',
            'status' => $validation_result['status'],
            'errors' => json_encode($validation_result['errors'] ?? []),
            'warnings' => json_encode($validation_result['warnings'] ?? []),
            'created' => time(),
          ])
          ->execute();
        
        $this->getLogger('job_hunter')->info('Validated structured data for job @job_id: @status', [
          '@job_id' => $job_id,
          '@status' => $validation_result['status'],
        ]);
      }
      
      return new JsonResponse($validation_result);
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('Error validating structured data: @error', [
        '@error' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * AJAX: Get list of all jobs with sync status.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with jobs list.
   */
  public function getJobsList() {
    try {
      $jobs = $this->getRecentJobsWithSyncStatus(100);
      return new JsonResponse(['jobs' => $jobs]);
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('Error fetching jobs list: @error', [
        '@error' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'An error occurred while fetching jobs',
      ], 500);
    }
  }

}