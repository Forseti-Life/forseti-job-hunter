<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\job_hunter\Traits\JobHunterLoggerTrait;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for Google Cloud Talent Solution API integration.
 * 
 * Google Cloud Project: forseti-483518
 * Service Account: forseti-life@forseti-483518.iam.gserviceaccount.com
 * API: Cloud Talent Solution (jobs.googleapis.com)
 */
class CloudTalentSolutionService {

  use JobHunterLoggerTrait;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Google Cloud project ID.
   */
  const PROJECT_ID = 'forseti-483518';

  /**
   * Cloud Talent Solution API base URL.
   */
  const API_BASE_URL = 'https://jobs.googleapis.com/v4';

  /**
   * Service account email.
   */
  const SERVICE_ACCOUNT = 'forseti-life@forseti-483518.iam.gserviceaccount.com';

  /**
   * Constructs a CloudTalentSolutionService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    ClientInterface $http_client,
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->httpClient = $http_client;
    $this->database = $database;
    $this->logger = $logger_factory->get('job_hunter');
    $this->configFactory = $config_factory;
  }

  /**
   * Get access token for API authentication.
   *
   * @return string
   *   The access token.
   *
   * @throws \Exception
   *   If authentication fails.
   */
  protected function getAccessToken() {
    $credentials_json = $this->configFactory->get('job_hunter.settings')->get('google_cloud_credentials');
    
    if (empty($credentials_json)) {
      throw new \Exception('Google Cloud credentials not configured. Please upload your service account JSON key in Job Hunter settings.');
    }

    $credentials = json_decode($credentials_json, TRUE);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \Exception('Invalid Google Cloud credentials JSON.');
    }

    // Use Google Auth Library to get access token
    // This will need google/auth composer package installed
    try {
      $client = new \Google\Auth\Credentials\ServiceAccountCredentials(
        'https://www.googleapis.com/auth/cloud-platform',
        $credentials
      );
      
      $token = $client->fetchAuthToken();
      
      if (isset($token['access_token'])) {
        return $token['access_token'];
      }
      
      throw new \Exception('Failed to obtain access token.');
    }
    catch (\Exception $e) {
      $this->logError('Google Cloud authentication failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Get tenant name from configuration.
   *
   * @return string
   *   The full tenant resource name (e.g., projects/forseti-483518/tenants/76d39aae-4a00-0000-0000-00527559cb6e).
   *
   * @throws \Exception
   *   If tenant name is not configured.
   */
  protected function getTenantName() {
    $tenant_name = $this->configFactory->get('job_hunter.settings')->get('tenant_name');
    
    if (empty($tenant_name)) {
      throw new \Exception('Google Cloud Tenant name not configured. Please set the tenant name in Job Hunter settings or create a tenant using the "Create Tenant" button.');
    }

    return $tenant_name;
  }

  /**
   * Test minimal Google Cloud API search (debugging helper).
   *
   * Sends simplest possible request to verify API connectivity
   * and tenant configuration without any filters.
   *
   * @return array
   *   Search results with total count.
   *
   * @throws \Exception
   *   If API request fails.
   */
  public function testSimpleSearch() {
    $access_token = $this->getAccessToken();
    
    // Absolute minimal request - just required fields, no jobQuery
    $request_body = [
      'requestMetadata' => [
        'userId' => 'test-user-1',
        'sessionId' => 'test-session-' . time(),
        'domain' => 'forseti.life',
      ],
      'searchMode' => 'JOB_SEARCH',
    ];
    
    $this->logInfo('🧪 Testing minimal API request (no jobQuery): @body', [
      '@body' => json_encode($request_body),
    ]);
    
    try {
      $url = self::API_BASE_URL . '/' . $this->getTenantName() . '/jobs:search';
      
      $response = $this->httpClient->request('POST', $url, [
        'json' => $request_body,
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 30,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      
      $this->logInfo('✅ Minimal test succeeded! Total jobs in tenant: @total', [
        '@total' => $data['totalSize'] ?? 0,
      ]);
      
      return [
        'jobs' => $data['matchingJobs'] ?? [],
        'total_size' => $data['totalSize'] ?? 0,
        'metadata' => $data['metadata'] ?? [],
      ];
    }
    catch (RequestException $e) {
      $error_body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      
      $this->logError('❌ Minimal test failed: @error. Full response: @body', [
        '@error' => $e->getMessage(),
        '@body' => $error_body,
      ]);
      
      throw $e;
    }
  }

  /**
   * Search for jobs via Cloud Talent Solution API.
   *
   * @param array $params
   *   Search parameters:
   *   - query: Job search query
   *   - location: Location filter
   *   - employment_types: Array of employment types
   *   - page_size: Number of results (default 10, max 100)
   *   - page_token: Pagination token
   *
   * @return array
   *   Array containing:
   *   - jobs: Array of job listings
   *   - next_page_token: Token for next page
   *   - metadata: Search metadata
   *
   * @throws \Exception
   *   If API request fails.
   */
  public function searchJobs(array $params) {
    $access_token = $this->getAccessToken();
    $start_time = microtime(true);
    $uid = \Drupal::currentUser()->id();
    
    // Build request body for job search
    $request_body = [
      'requestMetadata' => [
        'userId' => 'user-' . $uid,
        'sessionId' => session_id(),
        'domain' => \Drupal::request()->getHost(),
      ],
      'searchMode' => 'JOB_SEARCH',
    ];
    
    // Build jobQuery object separately
    $job_query = [];

    // Add query string if provided
    if (!empty($params['query'])) {
      $job_query['query'] = $params['query'];
    }

    // Add location filter
    if (!empty($params['location'])) {
      $job_query['locationFilters'] = [
        [
          'address' => $params['location'],
        ]
      ];
    }

    // Add employment types filter
    if (!empty($params['employment_types'])) {
      $job_query['employmentTypes'] = $params['employment_types'];
    }

    // Add compensation filter
    if (!empty($params['salary_min']) || !empty($params['salary_max'])) {
      $compensation_range = [];
      if (!empty($params['salary_min'])) {
        $compensation_range['minCompensation'] = [
          'units' => 'USD',
          'nanos' => 0,
        ];
        $compensation_range['minCompensation']['currencyCode'] = 'USD';
        $compensation_range['minCompensation']['units'] = (int) $params['salary_min'];
      }
      if (!empty($params['salary_max'])) {
        $compensation_range['maxCompensation'] = [
          'units' => 'USD',
          'nanos' => 0,
        ];
        $compensation_range['maxCompensation']['currencyCode'] = 'USD';
        $compensation_range['maxCompensation']['units'] = (int) $params['salary_max'];
      }
      $job_query['compensationFilter'] = [
        'type' => 'ANNUALIZED_BASE_AMOUNT',
        'units' => ['ANNUAL'],
        'range' => $compensation_range,
      ];
    }

    // Add publish time range filter (date posted)
    if (!empty($params['date_posted'])) {
      $now = time();
      $start_time = null;
      
      switch ($params['date_posted']) {
        case 'past_24_hours':
          $start_time = $now - (24 * 3600);
          break;
        case 'past_week':
          $start_time = $now - (7 * 24 * 3600);
          break;
        case 'past_month':
          $start_time = $now - (30 * 24 * 3600);
          break;
      }
      
      if ($start_time) {
        $job_query['publishTimeRange'] = [
          'startTime' => date('c', $start_time),
          'endTime' => date('c', $now),
        ];
      }
    }

    // Add telecommute preference for remote jobs
    if (!empty($params['remote_preference'])) {
      if ($params['remote_preference'] === 'remote' || $params['remote_preference'] === 'hybrid') {
        // For location filters, add telecommute preference
        if (isset($job_query['locationFilters'][0])) {
          $job_query['locationFilters'][0]['telecommutePreference'] = 'TELECOMMUTE_ALLOWED';
        } else {
          // If no location specified but remote requested, create location filter with telecommute
          $job_query['locationFilters'] = [
            ['telecommutePreference' => 'TELECOMMUTE_ALLOWED']
          ];
        }
      }
    }
    
    // Add jobQuery to request body only if not empty
    if (!empty($job_query)) {
      $request_body['jobQuery'] = $job_query;
    }

    // Pagination
    $page_size = 10;
    if (!empty($params['page_size'])) {
      $page_size = min((int) $params['page_size'], 100);
      $request_body['maxPageSize'] = $page_size;
    }
    
    if (!empty($params['page_token'])) {
      $request_body['pageToken'] = $params['page_token'];
    }

    try {
      $this->logInfo('Cloud Talent Solution search request: @query', [
        '@query' => json_encode($request_body),
      ]);

      // Make API request
      $url = self::API_BASE_URL . '/' . $this->getTenantName() . '/jobs:search';
      
      $response = $this->httpClient->request('POST', $url, [
        'json' => $request_body,
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 30,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      
      $end_time = microtime(true);
      $response_time_ms = (int) (($end_time - $start_time) * 1000);
      
      $result = [
        'jobs' => $data['matchingJobs'] ?? [],
        'next_page_token' => $data['nextPageToken'] ?? NULL,
        'metadata' => $data['metadata'] ?? [],
        'total_size' => $data['totalSize'] ?? 0,
      ];

      // Log the search query to database
      $search_query_id = $this->logSearchQuery(
        $uid,
        $params,
        $request_body,
        $result,
        $response_time_ms,
        'completed'
      );

      // Log each search result
      $this->logSearchResults($search_query_id, $result['jobs']);

      return $result;

    }
    catch (RequestException $e) {
      $end_time = microtime(true);
      $response_time_ms = (int) (($end_time - $start_time) * 1000);
      
      // Get full error response body
      $error_body = '';
      if ($e->hasResponse()) {
        $error_body = (string) $e->getResponse()->getBody();
      }
      
      $this->logError('Cloud Talent Solution search failed: @error. Response body: @body. Request: @request', [
        '@error' => $e->getMessage(),
        '@body' => $error_body,
        '@request' => json_encode($request_body),
      ]);
      
      // Log failed search query
      $this->logSearchQuery(
        $uid,
        $params,
        $request_body,
        [],
        $response_time_ms,
        'error',
        $e->getMessage()
      );
      
      throw new \Exception('Failed to search jobs via Cloud Talent Solution: ' . $e->getMessage() . ' - See logs for full API response.');
    }
  }

  /**
   * Log search query to database.
   *
   * @param int $uid
   *   User ID who performed the search.
   * @param array $params
   *   Search parameters.
   * @param array $request_body
   *   Complete API request body.
   * @param array $result
   *   API response result.
   * @param int $response_time_ms
   *   Response time in milliseconds.
   * @param string $status
   *   Status: completed, error, timeout.
   * @param string|null $error_message
   *   Error message if status is error.
   *
   * @return int
   *   Search query ID.
   */
  protected function logSearchQuery($uid, array $params, array $request_body, array $result, $response_time_ms, $status, $error_message = NULL) {
    $query_record = [
      'uid' => $uid,
      'query_text' => $params['query'] ?? NULL,
      'location' => $params['location'] ?? NULL,
      'search_params_json' => json_encode($request_body),
      'company_name' => $params['company_name'] ?? NULL,
      'employment_types' => !empty($params['employment_types']) ? implode(',', $params['employment_types']) : NULL,
      'job_categories' => !empty($params['job_categories']) ? implode(',', $params['job_categories']) : NULL,
      'page_token' => $params['page_token'] ?? NULL,
      'page_size' => $params['page_size'] ?? 10,
      'total_results' => $result['total_size'] ?? 0,
      'next_page_token' => $result['next_page_token'] ?? NULL,
      'api_response_time_ms' => $response_time_ms,
      'status' => $status,
      'error_message' => $error_message,
      'created' => time(),
    ];

    return $this->database->insert('jobhunter_job_search_queries')
      ->fields($query_record)
      ->execute();
  }

  /**
   * Log search results to database.
   *
   * @param int $search_query_id
   *   Search query ID.
   * @param array $jobs
   *   Array of job results from API.
   */
  protected function logSearchResults($search_query_id, array $jobs) {
    if (empty($jobs)) {
      return;
    }

    $position = 1;
    foreach ($jobs as $job_match) {
      $job = $job_match['job'] ?? [];
      
      $result_record = [
        'search_query_id' => $search_query_id,
        'external_job_id' => $job['name'] ?? '',
        'job_title' => $job['title'] ?? '',
        'company_name' => $job['companyDisplayName'] ?? '',
        'location' => !empty($job['addresses']) ? implode(', ', $job['addresses']) : '',
        'job_data_json' => json_encode($job),
        'rank_position' => $position,
        'created' => time(),
      ];

      $this->database->insert('jobhunter_job_search_results')
        ->fields($result_record)
        ->execute();
      
      $position++;
    }
  }

  /**
   * Get detailed job information.
   *
   * @param string $job_name
   *   The job resource name (format: projects/{project}/tenants/{tenant}/jobs/{job}).
   *
   * @return array
   *   Job details.
   *
   * @throws \Exception
   *   If API request fails.
   */
  public function getJob($job_name) {
    $access_token = $this->getAccessToken();

    try {
      $url = self::API_BASE_URL . '/' . $job_name;
      
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
        'timeout' => 30,
      ]);

      return json_decode($response->getBody()->getContents(), TRUE);

    }
    catch (RequestException $e) {
      $this->logError('Cloud Talent Solution search failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw new \Exception('Failed to fetch job details: ' . $e->getMessage());
    }
  }

  /**
   * Create a job posting via Cloud Talent Solution API.
   *
   * @param array $job_data
   *   Job data to create.
   * @param int $company_id
   *   Internal company ID.
   *
   * @return array
   *   Created job response.
   *
   * @throws \Exception
   *   If API request fails.
   */
  public function createJob(array $job_data, $company_id) {
    $access_token = $this->getAccessToken();
    
    // Get company resource name (create if needed)
    $company_name = $this->getOrCreateCompany($company_id);

    // Build job request
    $job_request = [
      'job' => [
        'company' => $company_name,
        'requisitionId' => 'job-' . uniqid(),
        'title' => $job_data['title'],
        'description' => $job_data['description'],
        'addresses' => [$job_data['location'] ?? 'United States'],
        'applicationInfo' => [
          'uris' => [$job_data['application_url'] ?? ''],
        ],
      ],
    ];

    // Add optional fields
    if (!empty($job_data['employment_types'])) {
      $job_request['job']['employmentTypes'] = $job_data['employment_types'];
    }

    if (!empty($job_data['posting_region'])) {
      $job_request['job']['postingRegion'] = $job_data['posting_region'];
    }
    else {
      $job_request['job']['postingRegion'] = 'NATION';
    }

    try {
      $url = self::API_BASE_URL . '/' . $this->getTenantName() . '/jobs';
      
      $response = $this->httpClient->request('POST', $url, [
        'json' => $job_request,
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 30,
      ]);

      $result = json_decode($response->getBody()->getContents(), TRUE);
      
      $this->logger->info('Created job in Cloud Talent Solution: @title', [
        '@title' => $job_data['title'],
      ]);

      return $result;

    }
    catch (RequestException $e) {
      $this->logger->error('Failed to create job: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw new \Exception('Failed to create job: ' . $e->getMessage());
    }
  }

  /**
   * Get or create a company in Cloud Talent Solution.
   *
   * @param int $company_id
   *   Internal company ID.
   *
   * @return string
   *   Company resource name.
   *
   * @throws \Exception
   *   If operation fails.
   */
  protected function getOrCreateCompany($company_id) {
    // Check if company already has a Cloud Talent Solution resource name
    $company = $this->database->select('jobhunter_companies', 'c')
      ->fields('c')
      ->condition('id', $company_id)
      ->execute()
      ->fetchObject();

    if (!$company) {
      throw new \Exception("Company not found with ID: $company_id");
    }

    // Check if we already have a resource name stored
    if (!empty($company->cloud_talent_company_name)) {
      return $company->cloud_talent_company_name;
    }

    // Create company in Cloud Talent Solution
    $access_token = $this->getAccessToken();
    
    $company_request = [
      'company' => [
        'displayName' => $company->company_name,
        'externalId' => 'company-' . $company_id,
      ],
    ];

    if (!empty($company->website)) {
      $company_request['company']['websiteUri'] = $company->website;
    }

    try {
      $url = self::API_BASE_URL . '/' . $this->getTenantName() . '/companies';
      
      $response = $this->httpClient->request('POST', $url, [
        'json' => $company_request,
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 30,
      ]);

      $result = json_decode($response->getBody()->getContents(), TRUE);
      $company_name = $result['name'];

      // Store the resource name for future use
      $this->database->update('jobhunter_companies')
        ->fields(['cloud_talent_company_name' => $company_name])
        ->condition('id', $company_id)
        ->execute();

      return $company_name;

    }
    catch (RequestException $e) {
      $this->logger->error('Failed to create company: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw new \Exception('Failed to create company: ' . $e->getMessage());
    }
  }

  /**
   * Import a job from Cloud Talent Solution search results.
   *
   * @param array $job_data
   *   Job data from API search results.
   * @param int $user_id
   *   The user ID importing the job.
   *
   * @return int|null
   *   The created job ID, or NULL if import failed.
   */
  public function importJob(array $job_data, $user_id) {
    try {
      $job = $job_data['job'] ?? $job_data;
      
      // Check if job already exists
      $external_id = $job['name'] ?? '';
      
      if (!empty($external_id)) {
        $existing = $this->database->select('jobhunter_job_requirements', 'j')
          ->fields('j', ['id'])
          ->condition('external_source', 'cloud_talent_solution')
          ->condition('external_job_id', $external_id)
          ->execute()
          ->fetchField();

        if ($existing) {
          $this->logger->info('Job already imported: @title', [
            '@title' => $job['title'] ?? 'Unknown',
          ]);
          
          // Update search result tracking if exists
          $this->updateSearchResultImport($external_id, $existing, $user_id);
          
          return $existing;
        }
      }

      // Get or create company
      $company_name = $job['companyDisplayName'] ?? 'Unknown Company';
      $company_id = $this->getOrCreateLocalCompany($company_name, $job['company'] ?? '');

      // Prepare job data
      $job_insert = [
        'company_id' => $company_id,
        'job_title' => $job['title'] ?? '',
        'job_description' => $job['description'] ?? '',
        'location' => implode(', ', $job['addresses'] ?? []),
        'employment_type' => $job['employmentTypes'][0] ?? 'FULL_TIME',
        'application_url' => $job['applicationInfo']['uris'][0] ?? '',
        'external_source' => 'cloud_talent_solution',
        'external_job_id' => $external_id,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'created_by_user_id' => $user_id,
        'extracted_json' => json_encode($job),
      ];

      // Insert job
      $job_id = $this->database->insert('jobhunter_job_requirements')
        ->fields($job_insert)
        ->execute();

      $this->logger->info('Imported job from Cloud Talent Solution: @title (ID: @id)', [
        '@title' => $job['title'] ?? 'Unknown',
        '@id' => $job_id,
      ]);
      
      // Update search result tracking if exists
      $this->updateSearchResultImport($external_id, $job_id, $user_id);

      return $job_id;

    }
    catch (\Exception $e) {
      $this->logError('Failed to create job: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Update search result tracking when job is imported.
   *
   * @param string $external_job_id
   *   External job ID from Cloud Talent Solution.
   * @param int $job_id
   *   Internal job ID.
   * @param int $user_id
   *   User ID who imported the job.
   */
  protected function updateSearchResultImport($external_job_id, $job_id, $user_id) {
    try {
      // Update all matching search results to track the import
      $this->database->update('jobhunter_job_search_results')
        ->fields([
          'imported_to_job_id' => $job_id,
          'imported_at' => time(),
          'imported_by_uid' => $user_id,
        ])
        ->condition('external_job_id', $external_job_id)
        ->isNull('imported_to_job_id')
        ->execute();
    }
    catch (\Exception $e) {
      // Log but don't fail the import if tracking fails
      $this->logger->warning('Failed to update search result tracking: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Get or create a local company record.
   *
   * @param string $company_name
   *   Company name.
   * @param string $cloud_company_name
   *   Cloud Talent Solution company resource name.
   *
   * @return int
   *   Company ID.
   */
  protected function getOrCreateLocalCompany($company_name, $cloud_company_name = '') {
    // Try to find existing company
    $company_id = $this->database->select('jobhunter_companies', 'c')
      ->fields('c', ['id'])
      ->condition('name', $company_name)
      ->execute()
      ->fetchField();

    if ($company_id) {
      // Update cloud company name if we have it
      if (!empty($cloud_company_name)) {
        $this->database->update('jobhunter_companies')
          ->fields(['cloud_talent_company_name' => $cloud_company_name])
          ->condition('id', $company_id)
          ->execute();
      }
      return $company_id;
    }

    // Create new company
    $company_insert = [
      'name' => $company_name,
      'cloud_talent_company_name' => $cloud_company_name,
      'created' => time(),
      'updated' => time(),
    ];

    return $this->database->insert('jobhunter_companies')
      ->fields($company_insert)
      ->execute();
  }

  /**
   * Check API credentials validity.
   *
   * @return bool
   *   TRUE if credentials are valid.
   */
  public function checkApiCredentials() {
    try {
      // Try to list companies as a test
      $access_token = $this->getAccessToken();
      
      $url = self::API_BASE_URL . '/' . $this->getTenantName() . '/companies';
      
      $response = $this->httpClient->request('GET', $url, [
        'query' => ['pageSize' => 1],
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
        'timeout' => 10,
      ]);

      return $response->getStatusCode() === 200;

    }
    catch (\Exception $e) {
      $this->logError('API credentials validation failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
