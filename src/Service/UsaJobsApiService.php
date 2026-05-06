<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for USAJobs API integration.
 * 
 * USAJobs is the official job site for U.S. federal government positions.
 * Free API, no rate limits.
 * Get API key at: https://developer.usajobs.gov/
 */
class UsaJobsApiService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

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
   * USAJobs API base URL.
   */
  const API_BASE_URL = 'https://data.usajobs.gov/api/search';

  /**
   * Constructs a UsaJobsApiService object.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('job_hunter');
    $this->configFactory = $config_factory;
  }

  /**
   * Search for jobs using USAJobs API.
   *
   * @param array $params
   *   Search parameters:
   *   - query: Keywords  
   *   - location: Location code or name
   *   - page: Page number (1-based)
   *   - results_per_page: Results per page (default 25, max 500)
   *
   * @return array
   *   Search results.
   *
   * @throws \Exception
   *   If API request fails.
   */
  public function searchJobs(array $params) {
    $config = $this->configFactory->get('job_hunter.settings');
    $api_key = $config->get('usajobs_api_key');
    $email = $config->get('usajobs_email');

    if (empty($api_key)) {
      throw new \Exception('USAJobs API key not configured. Get your free API key at https://developer.usajobs.gov/');
    }

    if (empty($email)) {
      $email = 'noreply@forseti.life'; // Fallback
    }

    // Build query parameters
    $query_params = [
      'ResultsPerPage' => $params['results_per_page'] ?? 25,
      'Page' => $params['page'] ?? 1,
    ];

    // Add keyword search
    if (!empty($params['query'])) {
      $query_params['Keyword'] = $params['query'];
    }

    // Add location
    if (!empty($params['location'])) {
      $query_params['LocationName'] = $params['location'];
    }

    // Add date posted filter
    if (!empty($params['date_posted'])) {
      $days_map = [
        'past_24_hours' => 1,
        'past_week' => 7,
        'past_month' => 30,
      ];
      if (isset($days_map[$params['date_posted']])) {
        $query_params['DatePosted'] = $days_map[$params['date_posted']];
      }
    }

    try {
      $url = self::API_BASE_URL . '?' . http_build_query($query_params);
      
      $this->logger->info('🏛️ USAJobs API request: @params', [
        '@params' => json_encode($query_params),
      ]);

      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'Authorization-Key' => $api_key,
          'User-Agent' => $email,
          'Host' => 'data.usajobs.gov',
        ],
        'timeout' => 10,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      $search_result = $data['SearchResult'] ?? [];
      $jobs = $search_result['SearchResultItems'] ?? [];

      $this->logger->info('📊 USAJobs returned @count results (total: @total)', [
        '@count' => count($jobs),
        '@total' => $search_result['SearchResultCount'] ?? 0,
      ]);

      return [
        'jobs' => $jobs,
        'total' => $search_result['SearchResultCount'] ?? 0,
        'page' => $query_params['Page'],
      ];

    } catch (RequestException $e) {
      $error_body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      
      $this->logger->error('❌ USAJobs API failed: @error. Response: @body', [
        '@error' => $e->getMessage(),
        '@body' => $error_body,
      ]);
      
      throw new \Exception('USAJobs API error: ' . $e->getMessage());
    }
  }
}
