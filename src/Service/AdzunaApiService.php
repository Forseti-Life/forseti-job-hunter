<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for Adzuna API integration.
 * 
 * Adzuna aggregates jobs from multiple sources including Indeed, Monster, etc.
 * Free tier: 250 calls/month
 * Get API keys at: https://developer.adzuna.com/
 */
class AdzunaApiService {

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
   * Adzuna API base URL.
   */
  const API_BASE_URL = 'https://api.adzuna.com/v1/api/jobs/us/search';

  /**
   * Constructs an AdzunaApiService object.
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
   * Search for jobs using Adzuna API.
   *
   * @param array $params
   *   Search parameters:
   *   - query: Keywords
   *   - location: Location (city, state, or country)
   *   - page: Page number (1-based)
   *   - results_per_page: Results per page (default 10, max 50)
   *
   * @return array
   *   Search results.
   *
   * @throws \Exception
   *   If API request fails.
   */
  public function searchJobs(array $params) {
    $config = $this->configFactory->get('job_hunter.settings');
    $app_id = $config->get('adzuna_app_id');
    $app_key = $config->get('adzuna_app_key');

    if (empty($app_id) || empty($app_key)) {
      throw new \Exception('Adzuna API credentials not configured. Get your free API keys at https://developer.adzuna.com/');
    }

    // Build query parameters
    $query_params = [
      'app_id' => $app_id,
      'app_key' => $app_key,
      'results_per_page' => $params['results_per_page'] ?? 10,
      'page' => $params['page'] ?? 1,
    ];

    // Add keyword search
    if (!empty($params['query'])) {
      $query_params['what'] = $params['query'];
    }

    // Add location
    if (!empty($params['location'])) {
      $query_params['where'] = $params['location'];
    }

    // Add salary filter
    if (!empty($params['salary_min'])) {
      $query_params['salary_min'] = $params['salary_min'];
    }
    if (!empty($params['salary_max'])) {
      $query_params['salary_max'] = $params['salary_max'];
    }

    // Add contract type filter
    if (!empty($params['employment_type'])) {
      $contract_map = [
        'FULL_TIME' => 'permanent',
        'PART_TIME' => 'part_time',
        'CONTRACT' => 'contract',
        'TEMPORARY' => 'temporary',
      ];
      if (isset($contract_map[$params['employment_type']])) {
        $query_params['contract_type'] = $contract_map[$params['employment_type']];
      }
    }

    try {
      $url = self::API_BASE_URL . '/1?' . http_build_query($query_params);
      
      $this->logger->info('🔍 Adzuna API request: @url', ['@url' => $url]);

      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      $this->logger->info('📊 Adzuna returned @count results (total: @total)', [
        '@count' => count($data['results'] ?? []),
        '@total' => $data['count'] ?? 0,
      ]);

      return [
        'jobs' => $data['results'] ?? [],
        'total' => $data['count'] ?? 0,
        'page' => $query_params['page'],
      ];

    } catch (RequestException $e) {
      $error_body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
      
      $this->logger->error('❌ Adzuna API failed: @error. Response: @body', [
        '@error' => $e->getMessage(),
        '@body' => $error_body,
      ]);
      
      throw new \Exception('Adzuna API error: ' . $e->getMessage());
    }
  }
}
