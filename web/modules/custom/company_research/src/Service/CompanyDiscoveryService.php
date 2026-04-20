<?php

namespace Drupal\company_research\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for discovering company websites from company names.
 */
class CompanyDiscoveryService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Constructs a CompanyDiscoveryService object.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelInterface $logger,
    ConfigFactoryInterface $config_factory
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->config = $config_factory->get('company_research.settings');
  }

  /**
   * Discover company website from company name.
   *
   * @param string $company_name
   *   Company name.
   *
   * @return array
   *   Array with keys:
   *   - domain: Company domain (e.g., "acme.com")
   *   - url: Full company URL (e.g., "https://www.acme.com")
   *   - valid: Whether domain is valid and accessible
   *   - method: Discovery method used
   *
   * @throws \Exception
   *   If company website cannot be discovered.
   */
  public function discoverCompanyWebsite(string $company_name): array {
    $this->logger->info('Discovering website for: @company', [
      '@company' => $company_name,
    ]);

    // Try manual domain patterns.
    $result = $this->tryManualPatterns($company_name);
    if ($result) {
      return $result;
    }

    throw new \Exception('Could not discover company website for: ' . $company_name);
  }

  /**
   * Try manual domain patterns.
   *
   * @param string $company_name
   *   Company name.
   *
   * @return array|null
   *   Company info or NULL if not found.
   */
  protected function tryManualPatterns(string $company_name): ?array {
    // Generate common domain patterns.
    $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $company_name));

    $patterns = [
      $normalized . '.com',
      'the' . $normalized . '.com',
      $normalized . '.net',
      $normalized . '.org',
    ];

    foreach ($patterns as $domain) {
      if ($this->validateDomain($domain)) {
        return [
          'domain' => $domain,
          'url' => 'https://www.' . $domain,
          'valid' => TRUE,
          'method' => 'manual_pattern',
        ];
      }
    }

    return NULL;
  }

  /**
   * Validate that a domain is accessible.
   *
   * @param string $domain
   *   Domain to validate.
   *
   * @return bool
   *   TRUE if domain is valid and accessible.
   */
  protected function validateDomain(string $domain): bool {
    try {
      $response = $this->httpClient->request('HEAD', 'https://www.' . $domain, [
        'timeout' => 5,
        'http_errors' => FALSE,
      ]);

      $status_code = $response->getStatusCode();
      return $status_code >= 200 && $status_code < 400;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

}
