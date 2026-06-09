<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Centralized service for orchestrating job searches across multiple sources.
 *
 * This service provides a unified interface for searching jobs from multiple
 * sources (internal database, external APIs) and stores results for analytics.
 */
class SearchAggregatorService {

  /**
   * Sources that should be persisted to search staging cache.
   *
   * @var string[]
   */
  protected const CACHEABLE_EXTERNAL_SOURCES = [
    'Google Jobs',
    'Adzuna',
    'USAJobs',
    'Google Jobs (SerpAPI)',
  ];

  /**
   * Search source keys supported by the live job discovery UI.
   *
   * @var string[]
   */
  protected const SUPPORTED_SEARCH_SOURCES = [
    'forseti',
    'serpapi',
    'adzuna',
    'usajobs',
  ];

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The Google Cloud Talent Solution service.
   *
   * @var \Drupal\job_hunter\Service\CloudTalentSolutionService
   */
  protected $googleCloudService;

  /**
   * The Adzuna API service.
   *
   * @var \Drupal\job_hunter\Service\AdzunaApiService
   */
  protected $adzunaService;

  /**
   * The USAJobs API service.
   *
   * @var \Drupal\job_hunter\Service\UsaJobsApiService
   */
  protected $usaJobsService;

  /**
   * The SerpAPI service.
   *
   * @var \Drupal\job_hunter\Service\SerpApiService
   */
  protected $serpApiService;

  /**
   * Constructs a SearchAggregatorService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\job_hunter\Service\CloudTalentSolutionService $google_cloud_service
   *   The Google Cloud service.
   * @param \Drupal\job_hunter\Service\AdzunaApiService $adzuna_service
   *   The Adzuna API service.
   * @param \Drupal\job_hunter\Service\UsaJobsApiService $usajobs_service
   *   The USAJobs API service.
   * @param \Drupal\job_hunter\Service\SerpApiService $serpapi_service
   *   The SerpAPI service.
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
    CloudTalentSolutionService $google_cloud_service,
    AdzunaApiService $adzuna_service,
    UsaJobsApiService $usajobs_service,
    SerpApiService $serpapi_service
  ) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('job_hunter');
    $this->googleCloudService = $google_cloud_service;
    $this->adzunaService = $adzuna_service;
    $this->usaJobsService = $usajobs_service;
    $this->serpApiService = $serpapi_service;
  }

  /**
   * Applies saved per-user search preferences when the request does not
   * explicitly override them.
   *
   * Special keys prefixed with "_" are internal controller hints and are
   * removed from the returned parameter array.
   *
   * @param array $params
   *   Raw search parameters.
   *
   * @return array
   *   Normalized search parameters.
   */
  public function normalizeSearchParameters(array $params): array {
    $explicit_sources = !empty($params['_explicit_sources']);
    $explicit_salary_min = !empty($params['_explicit_salary_min']);
    $explicit_remote_preference = !empty($params['_explicit_remote_preference']);

    $params['sources'] = $this->normalizeSourceList((array) ($params['sources'] ?? []));

    if ($this->currentUser->isAuthenticated()) {
      $preferences = $this->loadSourcePreferencesForUser((int) $this->currentUser->id());

      if (!$explicit_sources && empty($params['sources']) && !empty($preferences['sources_enabled'])) {
        $params['sources'] = $preferences['sources_enabled'];
      }

      if (
        !$explicit_salary_min &&
        (($params['salary_min'] ?? '') === '' || ($params['salary_min'] ?? NULL) === NULL) &&
        $preferences['min_salary'] !== NULL
      ) {
        $params['salary_min'] = $preferences['min_salary'];
      }

      if (
        !$explicit_remote_preference &&
        (($params['remote_preference'] ?? '') === '' || ($params['remote_preference'] ?? NULL) === NULL)
      ) {
        $params['remote_preference'] = $preferences['remote_preference'];
      }
    }

    if (!$explicit_sources && empty($params['sources'])) {
      $params['sources'] = $this->getDefaultSources();
    }

    $params['remote_preference'] = $this->normalizeRemotePreference((string) ($params['remote_preference'] ?? ''));

    unset(
      $params['_explicit_sources'],
      $params['_explicit_salary_min'],
      $params['_explicit_remote_preference']
    );

    return $params;
  }

  /**
   * Search for jobs across multiple sources.
   *
   * @param array $params
   *   Search parameters containing:
   *   - query: (string) Search keywords
   *   - location: (string) Location filter
   *   - sources: (array) Array of source identifiers to search
   *   - employment_type: (string) Employment type filter
   *   - salary_min: (int) Minimum salary
   *   - salary_max: (int) Maximum salary
   *   - remote_preference: (string) Remote work preference
   *   - date_posted: (string) Date posted filter
   *   - company: (string) Company filter (Forseti only)
   *   - relocation_willing: (bool) Relocation preference
   *   - page: (int) Page number
   *   - next_page_token: (string) Pagination token for SerpAPI
   *
   * @return array
   *   Array containing:
   *   - results: Array of normalized job results
   *   - total: Total number of results
   *   - sources_searched: Array of sources that were searched
   *   - diagnostics: Diagnostic information if no results found
   *   - pagination: Pagination metadata (for sources that support it)
   */
  public function searchJobs(array $params): array {
    $params = $this->normalizeSearchParameters($params);
    $sources = $params['sources'] ?? $this->getDefaultSources();
    $all_results = [];
    $pagination_metadata = [];

    $this->logger->info('🔍 SearchAggregator: Starting search with sources: @sources', [
      '@sources' => implode(', ', $sources),
    ]);

    // Search each requested source
    foreach ($sources as $source) {
      switch ($source) {
        case 'forseti':
          $results = $this->searchForsetiDatabase($params);
          $all_results = array_merge($all_results, $results);
          break;

        case 'google_cloud':
          $results = $this->searchGoogleCloud($params);
          $all_results = array_merge($all_results, $results);
          break;

        case 'adzuna':
          $results = $this->searchAdzuna($params);
          $all_results = array_merge($all_results, $results);
          break;

        case 'usajobs':
          $results = $this->searchUsaJobs($params);
          $all_results = array_merge($all_results, $results);
          break;

        case 'serpapi':
          $serpapi_data = $this->searchSerpApi($params);
          $all_results = array_merge($all_results, $serpapi_data['results'] ?? []);
          // Store pagination metadata for SerpAPI
          if (!empty($serpapi_data['pagination'])) {
            $pagination_metadata['serpapi'] = $serpapi_data['pagination'];
          }
          break;
      }
    }

    $this->logger->info('✅ SearchAggregator: Total results from all sources: @count', [
      '@count' => count($all_results),
    ]);

    // Store search results for analytics
    $this->storeSearchResults($params, $all_results);

    // Immediately import newly cached external results so they become
    // searchable in Forseti jobs during the same request.
    $this->importRecentResults();

    // Prepare diagnostics if no results
    $diagnostics = [];
    if (empty($all_results)) {
      $diagnostics = $this->generateDiagnostics($sources);
    }

    return [
      'results' => $all_results,
      'total' => count($all_results),
      'sources_searched' => $sources,
      'diagnostics' => $diagnostics,
      'pagination' => $pagination_metadata,
    ];
  }

  /**
   * Loads stored source preferences for a user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array{
   *   sources_enabled: string[],
   *   min_salary: int|null,
   *   remote_preference: string
   * }
   *   Stored preference values normalized for live search.
   */
  protected function loadSourcePreferencesForUser(int $uid): array {
    try {
      $row = $this->database->select('jobhunter_source_preferences', 'sp')
        ->fields('sp', ['sources_enabled', 'min_salary', 'remote_preference'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchObject();
    }
    catch (\Exception $e) {
      $this->logger->error('❌ Failed to load source preferences for uid @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      $row = NULL;
    }

    if (!$row) {
      return [
        'sources_enabled' => [],
        'min_salary' => NULL,
        'remote_preference' => '',
      ];
    }

    $decoded_sources = [];
    if (!empty($row->sources_enabled)) {
      $decoded = json_decode($row->sources_enabled, TRUE);
      if (is_array($decoded)) {
        $decoded_sources = $decoded;
      }
    }

    $normalized_sources = $this->normalizeSourceList($decoded_sources);
    if (!empty($decoded_sources) && empty($normalized_sources)) {
      $normalized_sources = $this->getDefaultSources();
    }

    return [
      'sources_enabled' => $normalized_sources,
      'min_salary' => $row->min_salary !== NULL ? (int) $row->min_salary : NULL,
      'remote_preference' => $this->normalizeRemotePreference((string) ($row->remote_preference ?? '')),
    ];
  }

  /**
   * Keeps only supported search source keys while preserving order.
   *
   * @param array $sources
   *   Candidate source list.
   *
   * @return string[]
   *   Valid source keys.
   */
  protected function normalizeSourceList(array $sources): array {
    $normalized = [];
    foreach ($sources as $source) {
      $source = strtolower(trim((string) $source));
      if ($source === '' || !in_array($source, self::SUPPORTED_SEARCH_SOURCES, TRUE)) {
        continue;
      }
      if (!in_array($source, $normalized, TRUE)) {
        $normalized[] = $source;
      }
    }
    return $normalized;
  }

  /**
   * Returns default search sources based on configured credentials.
   *
   * @return string[]
   *   Source keys in preferred default order.
   */
  protected function getDefaultSources(): array {
    $sources = ['forseti'];
    $config = $this->configFactory->get('job_hunter.settings');

    if (!empty($config->get('serpapi_api_key'))) {
      $sources[] = 'serpapi';
    }
    if (!empty($config->get('adzuna_app_id')) && !empty($config->get('adzuna_app_key'))) {
      $sources[] = 'adzuna';
    }
    if (!empty($config->get('usajobs_api_key')) && !empty($config->get('usajobs_email'))) {
      $sources[] = 'usajobs';
    }

    return $sources;
  }

  /**
   * Normalizes remote preference values for live search consumers.
   *
   * @param string $remote_preference
   *   Stored or requested remote preference value.
   *
   * @return string
   *   One of: "", "remote", "hybrid", "onsite".
   */
  protected function normalizeRemotePreference(string $remote_preference): string {
    $remote_preference = strtolower(trim($remote_preference));

    if ($remote_preference === 'remote_only') {
      return 'remote';
    }

    if (!in_array($remote_preference, ['remote', 'hybrid', 'onsite'], TRUE)) {
      return '';
    }

    return $remote_preference;
  }

  /**
   * Search Forseti internal database.
   *
   * @param array $params
   *   Search parameters.
   *
   * @return array
   *   Array of normalized job results.
   */
  protected function searchForsetiDatabase(array $params): array {
    $results = [];

    try {
      // Query main job requirements table
      $query = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j');

      // Apply filters
      if (!empty($params['query'])) {
        $query->condition('job_title', '%' . $this->database->escapeLike($params['query']) . '%', 'LIKE');
      }

      if (!empty($params['location'])) {
        $query->condition('location', '%' . $this->database->escapeLike($params['location']) . '%', 'LIKE');
      }

      if (!empty($params['employment_type'])) {
        $query->condition('employment_type', $params['employment_type']);
      }

      if (!empty($params['company'])) {
        $query->condition('company_id', $params['company']);
      }

      if (!empty($params['remote_preference']) && $params['remote_preference'] === 'remote') {
        $query->condition('remote_ok', 1);
      }

      if (!empty($params['date_posted'])) {
        $days = $this->convertDatePostedToDays($params['date_posted']);
        if ($days) {
          $date_threshold = strtotime("-{$days} days");
          $query->condition('created', $date_threshold, '>=');
        }
      }

      $query->orderBy('created', 'DESC');
      $query->range(0, 50);

      $job_rows = $query->execute()->fetchAll();

      // Also query staging table for recent unimported results (last 24 hours)
      $staging_timestamp_field = $this->database->schema()->fieldExists('jobhunter_job_search_results', 'created') ? 'created' : 'retrieved';
      $staging_query = $this->database->select('jobhunter_job_search_results', 's')
        ->fields('s');
      
      $staging_query->condition($staging_timestamp_field, time() - 86400, '>='); // Last 24 hours
      $staging_query->isNull('imported_to_job_id');
      
      // Apply same filters to staging results
      if (!empty($params['query'])) {
        $staging_query->condition('job_title', '%' . $this->database->escapeLike($params['query']) . '%', 'LIKE');
      }

      if (!empty($params['location'])) {
        $staging_query->condition('location', '%' . $this->database->escapeLike($params['location']) . '%', 'LIKE');
      }

      $staging_query->orderBy($staging_timestamp_field, 'DESC');
      $staging_query->range(0, 25); // Limit staging results

      $staging_rows = $staging_query->execute()->fetchAll();
      $seen_pending_keys = [];

      $this->logger->info('📊 Forseti DB returned @main_count main jobs + @staging_count staging jobs', [
        '@main_count' => count($job_rows),
        '@staging_count' => count($staging_rows),
      ]);

      // Normalize main table results
      foreach ($job_rows as $job) {
        // Get company name
        $company_name = 'Unknown';
        if (!empty($job->company_id)) {
          $company_name_field = $this->database->schema()->fieldExists('jobhunter_companies', 'name') ? 'name' : 'company_name';
          $company = $this->database->select('jobhunter_companies', 'c')
            ->fields('c', [$company_name_field])
            ->condition('id', $job->company_id)
            ->execute()
            ->fetchField();
          $company_name = $company ?: 'Unknown';
        }

        $results[] = [
          'id' => 'forseti_' . $job->id,
          'title' => $job->job_title ?? 'No title',
          'company' => $company_name,
          'location' => $job->location ?? 'Not specified',
          'employment_type' => $job->employment_type ?? 'Not specified',
          'salary_range' => $this->formatSalaryRange($job->min_salary ?? null, $job->max_salary ?? null),
          'description' => $this->truncateText($job->job_description ?? '', 200),
          'source' => 'Forseti Jobs',
          'posted_date' => !empty($job->created) ? date('M j, Y', $job->created) : 'Unknown',
          'url' => '/jobhunter/job/' . $job->id,
        ];
      }

      // Normalize staging table results (pending import)
      foreach ($staging_rows as $staging_job) {
        $staging_payload = [];
        if (!empty($staging_job->job_data_json)) {
          $decoded = json_decode($staging_job->job_data_json, TRUE);
          if (is_array($decoded)) {
            $staging_payload = $decoded;
          }
        }

        $pending_key = $this->buildPendingResultDeduplicationKey($staging_job, $staging_payload);
        if (isset($seen_pending_keys[$pending_key])) {
          continue;
        }
        $seen_pending_keys[$pending_key] = TRUE;

        $posted_timestamp = $staging_timestamp_field === 'created'
          ? ($staging_job->created ?? 0)
          : ($staging_job->retrieved ?? 0);

        $results[] = [
          'id' => 'staging_' . $staging_job->id,
          'title' => $staging_job->job_title ?? 'No title',
          'company' => $staging_job->company_name ?? 'Unknown',
          'location' => $staging_job->location ?? 'Not specified',
          'employment_type' => $staging_payload['employment_type'] ?? 'Not specified',
          'salary_range' => '',
          'description' => $this->truncateText($staging_payload['description'] ?? '', 200),
          'source' => 'Forseti Jobs (Pending)',
          'posted_date' => !empty($posted_timestamp) ? date('M j, Y', $posted_timestamp) : 'Unknown',
          'url' => $staging_payload['url'] ?? '#',
        ];
      }
    }
    catch (\Exception $e) {
      $this->logger->error('❌ Forseti database search failed: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Search Google Cloud Talent Solution API.
   *
   * @param array $params
   *   Search parameters.
   *
   * @return array
   *   Array of normalized job results.
   */
  protected function searchGoogleCloud(array $params): array {
    $results = [];

    try {
      $config = $this->configFactory->get('job_hunter.settings');
      $google_credentials = $config->get('google_cloud_credentials');

      if (empty($google_credentials)) {
        $this->logger->warning('⚠️ Google Cloud search skipped: no credentials configured');
        return [];
      }

      // Run diagnostic check
      try {
        $diagnostic_results = $this->googleCloudService->testSimpleSearch();
        $this->logger->info('🔍 Google Cloud diagnostic: Tenant has @total total jobs available', [
          '@total' => $diagnostic_results['total_size'] ?? 0,
        ]);
      }
      catch (\Exception $e) {
        $this->logger->warning('⚠️ Google Cloud diagnostic failed: @error', [
          '@error' => $e->getMessage(),
        ]);
      }

      // Build API parameters
      $google_params = [];
      if (!empty($params['query'])) {
        $google_params['query'] = $params['query'];
      }
      if (!empty($params['location'])) {
        $google_params['location'] = $params['location'];
      }
      if (!empty($params['employment_type'])) {
        $google_params['employment_types'] = [$params['employment_type']];
      }
      if (!empty($params['salary_min'])) {
        $google_params['salary_min'] = $params['salary_min'];
      }
      if (!empty($params['salary_max'])) {
        $google_params['salary_max'] = $params['salary_max'];
      }
      if (!empty($params['remote_preference'])) {
        $google_params['remote_preference'] = $params['remote_preference'];
      }
      if (!empty($params['date_posted'])) {
        $google_params['date_posted'] = $params['date_posted'];
      }

      $google_results = $this->googleCloudService->searchJobs($google_params);

      $this->logger->info('📊 Google Cloud API returned @count results', [
        '@count' => count($google_results['jobs'] ?? []),
      ]);

      // Normalize Google Cloud results
      foreach ($google_results['jobs'] ?? [] as $google_job) {
        $job_data = $google_job['job'] ?? [];
        $results[] = [
          'id' => $job_data['name'] ?? uniqid('google_'),
          'title' => $job_data['title'] ?? 'No title',
          'company' => $job_data['companyDisplayName'] ?? 'Unknown',
          'location' => !empty($job_data['addresses']) ? implode(', ', $job_data['addresses']) : 'Not specified',
          'employment_type' => !empty($job_data['employmentTypes']) ? implode(', ', $job_data['employmentTypes']) : 'Not specified',
          'salary_range' => 'Not specified',
          'description' => $this->truncateText($job_data['description'] ?? '', 200),
          'source' => 'Google Jobs',
          'posted_date' => !empty($job_data['postingPublishTime']) ? date('M j, Y', strtotime($job_data['postingPublishTime'])) : 'Unknown',
          'url' => $job_data['applicationInfo']['uris'][0] ?? '',
        ];
      }
    }
    catch (\Exception $e) {
      $this->logger->error('❌ Google Cloud search failed: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Search Adzuna API.
   *
   * @param array $params
   *   Search parameters.
   *
   * @return array
   *   Array of normalized job results.
   */
  protected function searchAdzuna(array $params): array {
    $results = [];

    try {
      $adzuna_params = [
        'query' => $params['query'] ?? '',
        'location' => $params['location'] ?? '',
        'employment_type' => $params['employment_type'] ?? '',
        'page' => 1,
        'results_per_page' => 25,
      ];

      $this->logger->info('🔍 Searching Adzuna API');

      $adzuna_results = $this->adzunaService->searchJobs($adzuna_params);

      $this->logger->info('📥 Adzuna returned @count jobs', [
        '@count' => $adzuna_results['total'] ?? 0,
      ]);

      // Normalize Adzuna results
      foreach ($adzuna_results['jobs'] ?? [] as $job_data) {
        $results[] = [
          'id' => 'adzuna_' . ($job_data['id'] ?? uniqid()),
          'title' => $job_data['title'] ?? 'Unknown',
          'company' => $job_data['company']['display_name'] ?? 'Unknown',
          'location' => $job_data['location']['display_name'] ?? 'Unknown',
          'employment_type' => 'Not specified',
          'salary_range' => $this->formatSalaryRange($job_data['salary_min'] ?? null, $job_data['salary_max'] ?? null),
          'description' => $this->truncateText($job_data['description'] ?? '', 200),
          'source' => 'Adzuna',
          'posted_date' => !empty($job_data['created']) ? date('M j, Y', strtotime($job_data['created'])) : 'Unknown',
          'url' => $job_data['redirect_url'] ?? '',
        ];
      }
    }
    catch (\Exception $e) {
      $this->logger->error('❌ Adzuna API search failed: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Search USAJobs API.
   *
   * @param array $params
   *   Search parameters.
   *
   * @return array
   *   Array of normalized job results.
   */
  protected function searchUsaJobs(array $params): array {
    $results = [];

    try {
      $usajobs_params = [
        'query' => $params['query'] ?? '',
        'location' => $params['location'] ?? '',
        'page' => 1,
        'results_per_page' => 25,
      ];

      $this->logger->info('🔍 Searching USAJobs API');

      $usajobs_results = $this->usaJobsService->searchJobs($usajobs_params);

      $this->logger->info('📥 USAJobs returned @count jobs', [
        '@count' => $usajobs_results['total'] ?? 0,
      ]);

      // Normalize USAJobs results
      foreach ($usajobs_results['jobs'] ?? [] as $job_data) {
        $matched_job = $job_data['MatchedObjectDescriptor'] ?? [];

        $salary_range = 'Not specified';
        if (!empty($matched_job['PositionRemuneration'])) {
          $remuneration = $matched_job['PositionRemuneration'][0] ?? [];
          $min_range = $remuneration['MinimumRange'] ?? null;
          $max_range = $remuneration['MaximumRange'] ?? null;
          $salary_range = $this->formatSalaryRange($min_range, $max_range);
        }

        $results[] = [
          'id' => 'usajobs_' . ($matched_job['PositionID'] ?? uniqid()),
          'title' => $matched_job['PositionTitle'] ?? 'Unknown',
          'company' => $matched_job['OrganizationName'] ?? 'U.S. Government',
          'location' => $matched_job['PositionLocationDisplay'] ?? 'Washington, DC',
          'employment_type' => 'Not specified',
          'salary_range' => $salary_range,
          'description' => $this->truncateText($matched_job['UserArea']['Details']['JobSummary'] ?? '', 200),
          'source' => 'USAJobs',
          'posted_date' => !empty($matched_job['PublicationStartDate']) ? date('M j, Y', strtotime($matched_job['PublicationStartDate'])) : 'Unknown',
          'url' => $matched_job['PositionURI'] ?? '',
        ];
      }
    }
    catch (\Exception $e) {
      $this->logger->error('❌ USAJobs API search failed: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $results;
  }

  /**
   * Search SerpAPI (Google Jobs).
   *
   * @param array $params
   *   Search parameters.
   *
   * @return array
   *   Array with 'results' and 'pagination' keys.
   */
  protected function searchSerpApi(array $params): array {
    $results = [];
    $pagination = [];

    try {
      $serpapi_params = [
        'query' => $params['query'] ?? '',
        'location' => $params['location'] ?? '',
        'employment_type' => $params['employment_type'] ?? '',
        'page' => $params['page'] ?? 1,
        'results_per_page' => 10, // SerpAPI standard
      ];

      // Pass through next_page_token if provided
      if (!empty($params['next_page_token'])) {
        $serpapi_params['next_page_token'] = $params['next_page_token'];
      }

      $this->logger->info('🔍 Searching SerpAPI (Google Jobs)');

      $serpapi_results = $this->serpApiService->searchJobs($serpapi_params);

      $this->logger->info('📥 SerpAPI returned @count jobs', [
        '@count' => $serpapi_results['total'] ?? 0,
      ]);

      // Store pagination info
      $pagination = [
        'current_page' => $serpapi_results['page'] ?? 1,
        'next_page_token' => $serpapi_results['next_page_token'] ?? NULL,
        'has_more' => $serpapi_results['has_more'] ?? FALSE,
      ];

      // Normalize SerpAPI results
      foreach ($serpapi_results['jobs'] ?? [] as $job_data) {
        $salary_range = 'Not specified';
        if (!empty($job_data['detected_extensions']['salary'])) {
          $salary_range = $job_data['detected_extensions']['salary'];
        }

        $posted_date = 'Unknown';
        if (!empty($job_data['detected_extensions']['posted_at'])) {
          $posted_date = $job_data['detected_extensions']['posted_at'];
        }

        // Extract employment type from schedule_type or extensions
        $employment_type = 'Not specified';
        if (!empty($job_data['detected_extensions']['schedule_type'])) {
          $employment_type = $job_data['detected_extensions']['schedule_type'];
        }

        // Generate content-based hash for deduplication
        $job_hash = $this->generateJobHash(
          $job_data['company_name'] ?? '',
          $job_data['title'] ?? '',
          $job_data['location'] ?? ''
        );

        $results[] = [
          // Use native SerpAPI job_id instead of random uniqid()
          'id' => $job_data['job_id'] ?? 'serpapi_' . uniqid(),
          'title' => $job_data['title'] ?? 'Unknown',
          'company' => $job_data['company_name'] ?? 'Unknown',
          'location' => $job_data['location'] ?? 'Unknown',
          'employment_type' => $employment_type,
          'salary_range' => $salary_range,
          // Store FULL description without truncation
          'description' => $job_data['description'] ?? '',
          'source' => 'Google Jobs (SerpAPI)',
          'posted_date' => $posted_date,
          // Use apply_options first, fallback to share_link
          'url' => $job_data['apply_options'][0]['link'] ?? $job_data['share_link'] ?? '',
          // NEW: Content-based hash for deduplication
          'job_hash' => $job_hash,
          // NEW: Rich metadata fields
          'via' => $job_data['via'] ?? '',
          'thumbnail' => $job_data['thumbnail'] ?? '',
          'share_link' => $job_data['share_link'] ?? '',
          // NEW: Detected extensions (work from home, benefits, etc.)
          'work_from_home' => $job_data['detected_extensions']['work_from_home'] ?? false,
          'health_insurance' => $job_data['detected_extensions']['health_insurance'] ?? false,
          'dental_coverage' => $job_data['detected_extensions']['dental_coverage'] ?? false,
          'paid_time_off' => $job_data['detected_extensions']['paid_time_off'] ?? false,
          // NEW: Structured highlights
          'job_highlights' => $job_data['job_highlights'] ?? [],
          // NEW: Multiple application links
          'apply_options' => $job_data['apply_options'] ?? [],
          // Store complete raw data for future use
          'raw_data' => $job_data,
        ];
      }
    }
    catch (\Exception $e) {
      $this->logger->error('❌ SerpAPI search failed: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return [
      'results' => $results,
      'pagination' => $pagination,
    ];
  }

  /**
   * Store search results for analytics and caching.
   *
   * Stores both search metadata AND individual job results so they can be:
   * - Searched later without hitting external APIs
   * - Imported into jobhunter_job_requirements via cron
   * - Used for analytics and trending job data
   *
   * @param array $params
   *   Search parameters used.
   * @param array $results
   *   Results found.
   */
  protected function storeSearchResults(array $params, array $results): void {
    try {
      // Store search history metadata
      $search_history_id = $this->database->insert('jobhunter_search_history')
        ->fields([
          'uid' => $this->currentUser->id(),
          'search_query' => $params['query'] ?? '',
          'location' => $params['location'] ?? '',
          'sources' => implode(',', $params['sources'] ?? []),
          'results_count' => count($results),
          'created' => time(),
        ])
        ->execute();

      $this->logger->info('💾 Stored search history (ID @id): @count results', [
        '@id' => $search_history_id,
        '@count' => count($results),
      ]);

      // Store individual job results for caching and future import
      if (!empty($results)) {
        $stored_count = 0;
        foreach ($results as $position => $result) {
          try {
            // Only store external API results (not Forseti DB results which are already stored)
            if (isset($result['source']) && in_array($result['source'], self::CACHEABLE_EXTERNAL_SOURCES, TRUE)) {
              $this->database->insert('jobhunter_job_search_results')
                ->fields([
                  'search_query_id' => $search_history_id,
                  'external_job_id' => $this->normalizeExternalJobId($result['id'] ?? uniqid('job_')),
                  'job_title' => substr($result['title'] ?? '', 0, 255),
                  'company_name' => substr($result['company'] ?? '', 0, 255),
                  'location' => substr($result['location'] ?? '', 0, 255),
                  'job_data_json' => json_encode($result),
                  'rank_position' => $position + 1,
                  'imported_to_job_id' => NULL,
                  'imported_at' => NULL,
                  'imported_by_uid' => NULL,
                  'created' => time(),
                ])
                ->execute();
              $stored_count++;
            }
          }
          catch (\Exception $e) {
            // Log but continue storing other results
            $this->logger->warning('⚠️ Failed to store job result @title: @error', [
              '@title' => $result['title'] ?? 'unknown',
              '@error' => $e->getMessage(),
            ]);
          }
        }

        $this->logger->info('💾 Stored @count individual job results for future import', [
          '@count' => $stored_count,
        ]);
      }
    }
    catch (\Exception $e) {
      // Non-critical - log but don't fail the search
      $this->logger->warning('⚠️ Failed to store search results: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Build a stable deduplication key for pending search rows.
   *
   * @param object $staging_job
   *   Raw staging table row.
   * @param array $staging_payload
   *   Decoded job payload.
   *
   * @return string
   *   Stable deduplication key.
   */
  protected function buildPendingResultDeduplicationKey(object $staging_job, array $staging_payload): string {
    $external_id = trim((string) ($staging_job->external_job_id ?? ''));
    if ($external_id !== '') {
      return 'external:' . mb_strtolower($external_id);
    }

    $title = trim((string) ($staging_job->job_title ?? ''));
    $company = trim((string) ($staging_job->company_name ?? ''));
    $location = trim((string) ($staging_job->location ?? ''));
    $url = trim((string) ($staging_payload['url'] ?? ''));

    return 'fallback:' . mb_strtolower($title . '|' . $company . '|' . $location . '|' . $url);
  }

  /**
   * Generate diagnostic information when no results found.
   *
   * @param array $sources
   *   Sources that were searched.
   *
   * @return array
   *   Diagnostic information.
   */
  protected function generateDiagnostics(array $sources): array {
    $diagnostics = [];

    // Check Forseti database total
    try {
      $forseti_total = $this->database->select('jobhunter_job_requirements', 'j')
        ->countQuery()
        ->execute()
        ->fetchField();
      $diagnostics['forseti_total'] = $forseti_total;
    }
    catch (\Exception $e) {
      $diagnostics['forseti_error'] = 'Error checking database';
    }

    // Check Google Cloud if it was searched
    if (in_array('google_cloud', $sources)) {
      try {
        $config = $this->configFactory->get('job_hunter.settings');
        $google_credentials = $config->get('google_cloud_credentials');

        if (!empty($google_credentials)) {
          $diagnostic_check = $this->googleCloudService->testSimpleSearch();
          $diagnostics['google_cloud_total'] = $diagnostic_check['total_size'] ?? 0;
        }
        else {
          $diagnostics['google_cloud_error'] = 'Not configured (no credentials)';
        }
      }
      catch (\Exception $e) {
        $diagnostics['google_cloud_error'] = 'Service error';
      }
    }

    return $diagnostics;
  }

  /**
   * Format salary range for display.
   *
   * @param int|null $min
   *   Minimum salary.
   * @param int|null $max
   *   Maximum salary.
   *
   * @return string
   *   Formatted salary range.
   */
  protected function formatSalaryRange($min, $max): string {
    if (!empty($min) && !empty($max)) {
      return '$' . number_format($min) . '-$' . number_format($max);
    }
    elseif (!empty($min)) {
      return '$' . number_format($min) . '+';
    }
    elseif (!empty($max)) {
      return 'Up to $' . number_format($max);
    }
    return 'Not specified';
  }

  /**
   * Truncate text to specified length.
   *
   * @param string $text
   *   Text to truncate.
   * @param int $length
   *   Maximum length.
   *
   * @return string
   *   Truncated text.
   */
  protected function truncateText(string $text, int $length): string {
    if (strlen($text) <= $length) {
      return $text;
    }
    return substr($text, 0, $length) . '...';
  }

  /**
   * Generate content-based hash for job deduplication.
   *
   * Creates MD5 hash from normalized company, title, and location.
   * Same job from different sources will generate same hash.
   *
   * @param string $company
   *   Company name.
   * @param string $title
   *   Job title.
   * @param string $location
   *   Job location.
   *
   * @return string
   *   32-character MD5 hash for deduplication.
   */
  protected function generateJobHash(string $company, string $title, string $location): string {
    // Normalize company name: lowercase, remove common suffixes
    $normalized_company = strtolower(trim($company));
    $normalized_company = preg_replace('/\b(inc|llc|ltd|corp|corporation|company|co)\b\.?/i', '', $normalized_company);
    $normalized_company = trim(preg_replace('/\s+/', ' ', $normalized_company));

    // Normalize title and location: lowercase, trim whitespace
    $normalized_title = strtolower(trim($title));
    $normalized_location = strtolower(trim($location));

    // Generate hash from normalized values
    return md5($normalized_company . '|' . $normalized_title . '|' . $normalized_location);
  }

  /**
   * Convert date_posted string to number of days.
   *
   * @param string $date_posted
   *   Date posted filter value.
   *
   * @return int|null
   *   Number of days, or null if invalid.
   */
  protected function convertDatePostedToDays(string $date_posted): ?int {
    $map = [
      'today' => 1,
      'last_3_days' => 3,
      'last_week' => 7,
      'last_14_days' => 14,
      'last_month' => 30,
    ];
    return $map[$date_posted] ?? null;
  }

  /**
   * Normalize external job IDs to fit schema constraints safely.
   *
   * Some providers (notably SerpAPI) return long encoded identifiers that can
   * exceed VARCHAR(255). This method preserves short IDs and uses a stable hash
   * for oversized values.
   *
   * @param string $external_job_id
   *   Source-provided external job identifier.
   *
   * @return string
   *   A schema-safe external job ID.
   */
  protected function normalizeExternalJobId(string $external_job_id): string {
    if (strlen($external_job_id) <= 255) {
      return $external_job_id;
    }

    return 'hash_' . hash('sha256', $external_job_id);
  }

  /**
   * Extract a normalized registrable domain from a URL.
   *
   * Example: https://www.roberthalf.com/... => roberthalf.com
   *
   * @param string $url
   *   The source URL.
   *
   * @return string|null
   *   Normalized domain or NULL when unavailable.
   */
  protected function extractSecondarySourceDomain(string $url): ?string {
    if ($url === '') {
      return NULL;
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
      return NULL;
    }

    $host = strtolower($host);
    $host = preg_replace('/^www\./', '', $host);

    return $host !== '' ? substr($host, 0, 100) : NULL;
  }

  /**
   * Returns the live source field name for imported job requirements.
   */
  protected function getImportedJobSourceField(): string {
    return $this->database->schema()->fieldExists('jobhunter_job_requirements', 'external_source')
      ? 'external_source'
      : 'source';
  }

  /**
   * Resolves or creates the canonical company row for an imported job.
   */
  protected function resolveImportedCompanyId(?string $company_name): ?int {
    $company_name = trim((string) $company_name);
    if ($company_name === '') {
      return NULL;
    }

    $company_name_field = $this->database->schema()->fieldExists('jobhunter_companies', 'name') ? 'name' : 'company_name';
    $existing_company = $this->database->select('jobhunter_companies', 'c')
      ->fields('c', ['id'])
      ->condition($company_name_field, $company_name)
      ->execute()
      ->fetchField();

    if ($existing_company) {
      return (int) $existing_company;
    }

    $now = time();
    return (int) $this->database->insert('jobhunter_companies')
      ->fields([
        $company_name_field => substr($company_name, 0, 255),
        'created' => $now,
        'updated' => $now,
        'active' => 1,
      ])
      ->execute();
  }

  /**
   * Import recent unimported external job results immediately.
   * 
   * This makes external API results immediately searchable in Forseti DB
   * instead of waiting for cron to import them.
   */
  protected function importRecentResults(): void {
    try {
      // Get unimported results from last hour (just stored)
      $results = $this->database->select('jobhunter_job_search_results', 'r')
        ->fields('r')
        ->isNull('imported_to_job_id')
        ->condition('created', time() - 3600, '>')
        ->execute()
        ->fetchAll();

      if (empty($results)) {
        return;
      }

      $imported = 0;
      $skipped = 0;
      $queued = 0;
      $source_field = $this->getImportedJobSourceField();
      $parsing_queue = \Drupal::queue('job_hunter_job_posting_parsing');

      foreach ($results as $result) {
        $job_data = json_decode($result->job_data_json, TRUE);
        
        if (empty($job_data)) {
          $skipped++;
          continue;
        }

        // Map source
        $source_map = [
          'Google Jobs' => 'google_cloud',
          'Adzuna' => 'adzuna',
          'USAJobs' => 'usajobs',
          'Google Jobs (SerpAPI)' => 'serpapi',
        ];
        $external_source = $source_map[$job_data['source'] ?? ''] ?? 'external_api';

        $external_job_id = substr((string) ($result->external_job_id ?? ''), 0, 512);
        $job_url = substr((string) ($job_data['url'] ?? ''), 0, 512);
        $secondary_source = $this->extractSecondarySourceDomain($job_url);

        // Check for duplicates using stable identifiers.
        $job_hash = $job_data['job_hash'] ?? NULL;
        $existing = $this->findExistingImportedJobId($job_hash, $external_job_id, $job_url, $external_source);
        if ($existing) {
          // Mark as imported (duplicate)
          $this->database->update('jobhunter_job_search_results')
            ->fields(['imported_to_job_id' => $existing, 'imported_at' => time()])
            ->condition('id', $result->id)
            ->execute();
          $skipped++;
          continue;
        }

        // Get or create company
        $company_id = $this->resolveImportedCompanyId($job_data['company'] ?? NULL);

        $job_title = substr((string) ($job_data['title'] ?? 'Unknown'), 0, 255);
        $location = substr((string) ($job_data['location'] ?? 'Unknown'), 0, 255);
        $employment_type = substr((string) ($job_data['employment_type'] ?? 'Full-time'), 0, 50);
        $via = !empty($job_data['via']) ? substr((string) $job_data['via'], 0, 255) : NULL;
        $thumbnail = !empty($job_data['thumbnail']) ? substr((string) $job_data['thumbnail'], 0, 512) : NULL;
        $share_link = !empty($job_data['share_link']) ? substr((string) $job_data['share_link'], 0, 512) : NULL;

        // Insert into main table
        $new_job_id = $this->database->insert('jobhunter_job_requirements')
          ->fields([
            'company_id' => $company_id,
            'job_title' => $job_title,
            'job_description' => $job_data['description'] ?? '',
            'requirements' => '',
            'salary_range' => $job_data['salary_range'] ?? 'Not specified',
            'location' => $location,
            'remote_option' => (stripos((string) ($job_data['location'] ?? ''), 'remote') !== FALSE) ? 'remote' : 'onsite',
            'employment_type' => $employment_type,
            'job_url' => $job_url,
            'status' => 'active',
            'created' => time(),
            'updated' => time(),
            $source_field => $external_source,
            'source_platform' => $secondary_source,
            'external_job_id' => $external_job_id,
            'job_hash' => $job_hash,
            'ai_extraction_status' => 'pending',
            'via' => $via,
            'thumbnail' => $thumbnail,
            'share_link' => $share_link,
            'work_from_home' => !empty($job_data['work_from_home']) ? 1 : 0,
            'health_insurance' => !empty($job_data['health_insurance']) ? 1 : 0,
            'dental_coverage' => !empty($job_data['dental_coverage']) ? 1 : 0,
            'paid_time_off' => !empty($job_data['paid_time_off']) ? 1 : 0,
            'job_highlights' => !empty($job_data['job_highlights']) ? json_encode($job_data['job_highlights']) : NULL,
            'apply_options' => !empty($job_data['apply_options']) ? json_encode($job_data['apply_options']) : NULL,
          ])
          ->execute();

        // Mark as imported
        $this->database->update('jobhunter_job_search_results')
          ->fields([
            'imported_to_job_id' => $new_job_id,
            'imported_at' => time(),
            'imported_by_uid' => $this->currentUser->id(),
          ])
          ->condition('id', $result->id)
          ->execute();

        // Immediately queue imported jobs for AI parsing.
        $posting_text = (string) ($job_data['description'] ?? '');
        if ($posting_text !== '') {
          try {
            $parsing_queue->createItem([
              'job_id' => $new_job_id,
              'raw_posting_text' => $posting_text,
            ]);

            $this->database->update('jobhunter_job_requirements')
              ->fields([
                'ai_extraction_status' => 'queued',
                'updated' => time(),
              ])
              ->condition('id', $new_job_id)
              ->execute();

            $queued++;
          }
          catch (\Exception $e) {
            // Keep job as pending if queue insertion fails.
            $this->logger->warning('⚠️ Imported job @id but failed to queue parsing: @error', [
              '@id' => $new_job_id,
              '@error' => $e->getMessage(),
            ]);
          }
        }

        $imported++;
      }

      if ($imported > 0) {
        $this->logger->info('⚡ Immediately imported @count external job results into Forseti DB (@queued queued for parsing)', [
          '@count' => $imported,
          '@queued' => $queued,
        ]);
      }
    }
    catch (\Exception $e) {
      // Non-critical - log but don't fail
      $this->logger->warning('⚠️ Failed to import recent results: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Resolve existing Forseti job ID from external identifiers.
   *
   * @param string|null $job_hash
   *   Optional canonical job hash.
   * @param string $external_job_id
   *   Source job identifier from staging table.
   * @param string $job_url
   *   Source job URL.
   * @param string $external_source
   *   Normalized external source key.
   *
   * @return int|null
   *   Existing job ID when found.
   */
  protected function findExistingImportedJobId(?string $job_hash, string $external_job_id, string $job_url, string $external_source): ?int {
    $source_field = $this->getImportedJobSourceField();

    if (!empty($job_hash) && $this->database->schema()->fieldExists('jobhunter_job_requirements', 'job_hash')) {
      $existing = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j', ['id'])
        ->condition('job_hash', $job_hash)
        ->execute()
        ->fetchField();
      if (!empty($existing)) {
        return (int) $existing;
      }
    }

    if ($external_job_id !== '') {
      $existing = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j', ['id'])
        ->condition($source_field, $external_source)
        ->condition('external_job_id', $external_job_id)
        ->execute()
        ->fetchField();
      if (!empty($existing)) {
        return (int) $existing;
      }
    }

    if ($job_url !== '') {
      $existing = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j', ['id'])
        ->condition($source_field, $external_source)
        ->condition('job_url', $job_url)
        ->execute()
        ->fetchField();
      if (!empty($existing)) {
        return (int) $existing;
      }
    }

    return NULL;
  }

}
