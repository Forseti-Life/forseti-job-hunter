<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\job_hunter\Service\CloudTalentSolutionService;
use Drupal\job_hunter\Traits\JobHunterLoggerTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for Google Jobs search via Cloud Talent Solution API.
 */
class GoogleJobsSearchController extends ControllerBase {
  use JobHunterControllerTrait;
  use JobHunterLoggerTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The Cloud Talent Solution service.
   *
   * @var \Drupal\job_hunter\Service\CloudTalentSolutionService
   */
  protected $cloudTalentService;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructs a GoogleJobsSearchController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\job_hunter\Service\CloudTalentSolutionService $cloud_talent_service
   *   The Cloud Talent Solution service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   */
  public function __construct(Connection $database, CloudTalentSolutionService $cloud_talent_service, CacheBackendInterface $cache) {
    $this->database = $database;
    $this->cloudTalentService = $cloud_talent_service;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('job_hunter.cloud_talent_solution'),
      $container->get('cache.data')
    );
  }

  /**
   * Maximum allowed search query length (characters).
   */
  const MAX_QUERY_LENGTH = 256;

  /**
   * Results per page for Google Jobs search.
   */
  const ITEMS_PER_PAGE = 10;

  /**
   * Cache TTL for page tokens (1 hour).
   */
  const PAGE_TOKEN_TTL = 3600;

  /**
   * Google Jobs search page with server-side rendering.
   *
   * Handles ?q= (search query) and ?page=N (pagination) GET parameters.
   * Validates inputs, calls the Cloud Talent API, and renders results
   * including result count, pagination controls, empty state, and error state.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return array
   *   Render array for the page.
   */
  public function searchPage(Request $request) {
    // Validate and sanitize the search query.
    $raw_query = $request->query->get('q', '');
    $query = strip_tags(trim($raw_query));
    $query = substr($query, 0, self::MAX_QUERY_LENGTH);

    // Validate page parameter: must be a positive integer.
    $raw_page = $request->query->get('page', 1);
    $page = (is_numeric($raw_page) && (int) $raw_page >= 1) ? (int) $raw_page : 1;

    $results = [];
    $total_results = 0;
    $total_pages = 0;
    $error_message = NULL;
    $has_search = !empty($query);

    if ($has_search) {
      try {
        $page_token = '';

        // For pages > 1, retrieve the cached page token.
        if ($page > 1) {
          $token_cache_key = 'gjobs_pt:' . md5($query) . ':' . $page;
          $cached = $this->cache->get($token_cache_key);
          if ($cached && !empty($cached->data)) {
            $page_token = $cached->data;
          }
          else {
            // No cached token — fall back to page 1.
            $page = 1;
          }
        }

        $params = [
          'query' => $query,
          'page_size' => self::ITEMS_PER_PAGE,
        ];
        if (!empty($page_token)) {
          $params['page_token'] = $page_token;
        }

        $api_results = $this->cloudTalentService->searchJobs($params);
        $results = $api_results['jobs'] ?? [];
        $total_results = (int) ($api_results['total_size'] ?? count($results));
        $total_pages = $total_results > 0
          ? (int) ceil($total_results / self::ITEMS_PER_PAGE)
          : ($results ? 1 : 0);

        // Cache the next page token for subsequent navigation.
        if (!empty($api_results['next_page_token'])) {
          $next_key = 'gjobs_pt:' . md5($query) . ':' . ($page + 1);
          $this->cache->set(
            $next_key,
            $api_results['next_page_token'],
            time() + self::PAGE_TOKEN_TTL,
            ['job_hunter:google_search']
          );
        }
      }
      catch (\Exception $e) {
        // Log only error code + class — no query content or API key fragments.
        $this->logError('Google Jobs search failed: @type (code @code)', [
          '@type' => get_class($e),
          '@code' => $e->getCode(),
        ]);
        $error_message = $this->t('An error occurred while searching for jobs. Please try again later.');
      }
    }

    $content = [
      '#theme' => 'google_jobs_search',
      '#query' => $query,
      '#results' => $results,
      '#total_results' => $total_results,
      '#current_page' => $page,
      '#total_pages' => $total_pages,
      '#page_size' => self::ITEMS_PER_PAGE,
      '#has_search' => $has_search,
      '#error_message' => $error_message,
      '#saved_searches' => $this->loadSavedSearches(),
      '#save_search_url' => Url::fromRoute('job_hunter.saved_search_save')->toString(),
      '#max_saved_searches' => self::MAX_SAVED_SEARCHES,
      '#cache' => ['max-age' => 0],
      '#attached' => ['library' => ['job_hunter/google_jobs_search']],
    ];

    return $this->wrapWithNavigation($content, ['job_hunter/google_jobs_search']);
  }

  /**
   * List page for saved searches.
   *
   * @return array
   *   Render array wrapped in navigation.
   */
  public function savedSearches(): array {
    $saved_searches = $this->loadSavedSearches();
    $content = [
      '#theme' => 'saved_searches_page',
      '#saved_searches' => $saved_searches,
      '#saved_searches_count' => count($saved_searches),
      '#max_saved_searches' => self::MAX_SAVED_SEARCHES,
      '#cache' => ['max-age' => 0],
    ];
    return $this->wrapWithNavigation($content);
  }

  /**
   * Maximum saved searches per user.
   */
  const MAX_SAVED_SEARCHES = 10;

  /**
   * Load saved searches for the current user.
   *
   * @return array
   *   Array of saved search objects (id, uid, keywords, location, created).
   */
  private function loadSavedSearches(): array {
    $uid = (int) $this->currentUser()->id();
    return $this->database->select('jobhunter_saved_searches', 's')
      ->fields('s', ['id', 'keywords', 'location', 'created'])
      ->condition('s.uid', $uid)
      ->orderBy('s.created', 'DESC')
      ->range(0, self::MAX_SAVED_SEARCHES)
      ->execute()
      ->fetchAll();
  }

  /**
   * Save a search (POST, CSRF-guarded).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function savedSearchSave(Request $request) {
    $uid = (int) $this->currentUser()->id();

    $existing_count = (int) $this->database->select('jobhunter_saved_searches', 's')
      ->condition('s.uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($existing_count >= self::MAX_SAVED_SEARCHES) {
      $this->messenger()->addWarning($this->t('Maximum saved searches reached. Delete one to save a new search.'));
      return new RedirectResponse(Url::fromRoute('job_hunter.google_jobs_search')->toString());
    }

    $keywords = strip_tags((string) $request->request->get('keywords', ''));
    $keywords = substr($keywords, 0, 256);
    $location = strip_tags((string) $request->request->get('location', ''));
    $location = substr($location, 0, 128);

    if ($keywords === '') {
      $this->messenger()->addWarning($this->t('Cannot save an empty search.'));
      return new RedirectResponse(Url::fromRoute('job_hunter.google_jobs_search')->toString());
    }

    $this->database->insert('jobhunter_saved_searches')
      ->fields([
        'uid' => $uid,
        'keywords' => $keywords,
        'location' => $location,
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    $this->messenger()->addStatus($this->t('Search saved.'));

    $redirect_url = Url::fromRoute('job_hunter.google_jobs_search', [], [
      'query' => array_filter(['q' => $keywords, 'location' => $location]),
    ])->toString();
    return new RedirectResponse($redirect_url);
  }

  /**
   * Delete a saved search (POST, CSRF-guarded).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param int $saved_search_id
   *   The saved search ID (integer enforced by routing pattern \d+).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function savedSearchDelete(Request $request, $saved_search_id) {
    $uid = (int) $this->currentUser()->id();
    $id = (int) $saved_search_id;

    $row = $this->database->select('jobhunter_saved_searches', 's')
      ->fields('s', ['id', 'uid'])
      ->condition('s.id', $id)
      ->execute()
      ->fetchObject();

    if (!$row || (int) $row->uid !== $uid) {
      throw new AccessDeniedHttpException();
    }

    $this->database->delete('jobhunter_saved_searches')
      ->condition('id', $id)
      ->condition('uid', $uid)
      ->execute();

    $this->messenger()->addStatus($this->t('Saved search deleted.'));
    return new RedirectResponse(Url::fromRoute('job_hunter.google_jobs_search')->toString());
  }

  /**
   * Job detail page for a Google Jobs-imported job (user-facing).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param int $job_id
   *   The numeric job ID from jobhunter_job_requirements.
   *
   * @return array
   *   Render array for the page.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When the job does not exist or belongs to a different user.
   */
  public function searchJobDetail(Request $request, $job_id) {
    $job_id = (int) $job_id;
    $user_id = (int) $this->currentUser()->id();

    // Verify ownership via jobhunter_saved_jobs (jobhunter_job_requirements has no user_id column).
    $owned = $this->database->select('jobhunter_saved_jobs', 'sj')
      ->fields('sj', ['id'])
      ->condition('sj.uid', $user_id)
      ->condition('sj.job_id', $job_id)
      ->execute()
      ->fetchField();

    if (!$owned) {
      throw new NotFoundHttpException();
    }

    $job = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j')
      ->condition('j.id', $job_id)
      ->execute()
      ->fetchObject();

    if (!$job) {
      throw new NotFoundHttpException();
    }

    $company = NULL;
    if (!empty($job->company_id)) {
      $company = $this->database->select('jobhunter_companies', 'c')
        ->fields('c')
        ->condition('c.id', $job->company_id)
        ->execute()
        ->fetchObject();
    }

    $content = [
      '#theme' => 'google_jobs_search_detail',
      '#job' => $job,
      '#company' => $company,
      '#cache' => ['max-age' => 0],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * API endpoint for searching Google Jobs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with search results.
   */
  public function apiSearch(Request $request) {
    try {
      // Get search parameters from request
      $query = trim($request->query->get('q', ''));
      $location = $request->query->get('location', '');
      $page_token = $request->query->get('page_token', '');
      $page_size = (int) $request->query->get('page_size', 10);
      $employment_types = $request->query->get('employment_types', '');

      // Validate query parameter
      if (empty($query)) {
        return new JsonResponse([
          'error' => 'Search query is required',
        ], 400);
      }

      if (strlen($query) < 2) {
        return new JsonResponse([
          'error' => 'Search query must be at least 2 characters',
        ], 400);
      }

      if (strlen($query) > 500) {
        return new JsonResponse([
          'error' => 'Search query is too long (max 500 characters)',
        ], 400);
      }

      // Validate page size parameter
      if ($page_size < 1) {
        $page_size = 10;
      }
      if ($page_size > 100) {
        $page_size = 100;
      }

      // Perform search via Cloud Talent Solution
      $params = [
        'query' => $query,
        'location' => $location,
        'page_size' => $page_size,
      ];

      if (!empty($page_token)) {
        $params['page_token'] = $page_token;
      }

      if (!empty($employment_types)) {
        $params['employment_types'] = explode(',', $employment_types);
      }

      // Generate cache key from search parameters
      $cache_key = 'job_hunter:google_search:' . md5(json_encode($params));
      $cache_tags = ['job_hunter:google_search'];

      // Check cache first
      $cached = $this->cache->get($cache_key);
      if ($cached && !empty($cached->data)) {
        $results = $cached->data;
      }
      else {
        // Perform search
        $results = $this->cloudTalentService->searchJobs($params);

        // Cache results for 1 hour
        $expire = time() + 3600;
        $this->cache->set($cache_key, $results, $expire, $cache_tags);
      }

      // Check which jobs are already imported
      $job_names = array_column(array_column($results['jobs'], 'job'), 'name');
      $imported_jobs = [];
      
      if (!empty($job_names)) {
        $imported = $this->database->select('jobhunter_job_requirements', 'j')
          ->fields('j', ['external_job_id', 'id'])
          ->condition('external_source', 'cloud_talent_solution')
          ->condition('external_job_id', $job_names, 'IN')
          ->execute()
          ->fetchAllKeyed();
        
        $imported_jobs = array_keys($imported);
      }

      // Add imported flag to results
      foreach ($results['jobs'] as &$job_match) {
        $job_name = $job_match['job']['name'] ?? '';
        $job_match['is_imported'] = in_array($job_name, $imported_jobs);
      }

      return new JsonResponse([
        'success' => TRUE,
        'data' => $results,
      ]);

    }
    catch (\Exception $e) {
      $this->logError('Google Jobs search failed: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * API endpoint for importing a job from Google Jobs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with import result.
   */
  public function apiImport(Request $request) {
    try {
      // Get job data from POST body
      $content = json_decode($request->getContent(), TRUE);
      
      if (empty($content['job_data'])) {
        return new JsonResponse([
          'error' => 'Job data is required',
        ], 400);
      }

      $job_data = $content['job_data'];
      $user_id = $this->currentUser()->id();

      // Import the job
      $job_id = $this->cloudTalentService->importJob($job_data, $user_id);

      if ($job_id) {
        return new JsonResponse([
          'success' => TRUE,
          'job_id' => $job_id,
          'message' => 'Job imported successfully',
        ]);
      }
      else {
        return new JsonResponse([
          'error' => 'Failed to import job',
        ], 500);
      }

    }
    catch (\Exception $e) {
      $this->logError('Job import failed: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * API endpoint for batch importing jobs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with batch import results.
   */
  public function apiBatchImport(Request $request) {
    try {
      $content = json_decode($request->getContent(), TRUE);
      
      if (empty($content['jobs']) || !is_array($content['jobs'])) {
        return new JsonResponse([
          'error' => 'Jobs array is required',
        ], 400);
      }

      $user_id = $this->currentUser()->id();
      $imported = [];
      $skipped = [];
      $errors = [];

      foreach ($content['jobs'] as $job_data) {
        try {
          $job_id = $this->cloudTalentService->importJob($job_data, $user_id);
          
          if ($job_id) {
            $imported[] = [
              'job_id' => $job_id,
              'title' => $job_data['title'],
            ];
          }
          else {
            $skipped[] = $job_data['title'];
          }
        }
        catch (\Exception $e) {
          $errors[] = [
            'title' => $job_data['title'] ?? 'Unknown',
            'error' => $e->getMessage(),
          ];
        }
      }

      return new JsonResponse([
        'success' => TRUE,
        'imported_count' => count($imported),
        'skipped_count' => count($skipped),
        'error_count' => count($errors),
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => $errors,
      ]);

    }
    catch (\Exception $e) {
      $this->logError('Batch import failed: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * API endpoint for getting job details.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $job_name
   *   The Cloud Talent Solution job resource name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with job details.
   */
  public function apiGetJobDetails(Request $request, $job_name) {
    try {
      $details = $this->cloudTalentService->getJob($job_name);

      return new JsonResponse([
        'success' => TRUE,
        'data' => $details,
      ]);

    }
    catch (\Exception $e) {
      $this->logError('Get job details failed: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 500);
    }
  }

}
