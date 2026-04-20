<?php

namespace Drupal\company_research\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Service for discovering careers pages from company websites.
 */
class CareersPageDiscoveryService {

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
   * Constructs a CareersPageDiscoveryService object.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelInterface $logger
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * Discover careers pages for a company domain.
   *
   * @param string $domain
   *   Company domain.
   *
   * @return array
   *   Array of careers page URLs with metadata.
   */
  public function discoverCareersPages(string $domain): array {
    $this->logger->info('Discovering careers pages for: @domain', [
      '@domain' => $domain,
    ]);

    $careers_pages = [];

    // Check common URL patterns.
    $patterns = [
      '/careers',
      '/jobs',
      '/opportunities',
      '/work-with-us',
    ];

    foreach ($patterns as $pattern) {
      $url = 'https://www.' . $domain . $pattern;
      if ($this->checkUrl($url)) {
        $careers_pages[] = [
          'url' => $url,
          'type' => 'primary',
          'accessible' => TRUE,
        ];
        break; // Found one, that's enough for now.
      }
    }

    // Check subdomain patterns if primary not found.
    if (empty($careers_pages)) {
      $subdomains = ['careers', 'jobs'];
      foreach ($subdomains as $subdomain) {
        $url = 'https://' . $subdomain . '.' . $domain;
        if ($this->checkUrl($url)) {
          $careers_pages[] = [
            'url' => $url,
            'type' => 'subdomain',
            'accessible' => TRUE,
          ];
          break;
        }
      }
    }

    return $careers_pages;
  }

  /**
   * Check if a URL is accessible.
   *
   * @param string $url
   *   URL to check.
   *
   * @return bool
   *   TRUE if accessible.
   */
  protected function checkUrl(string $url): bool {
    try {
      $response = $this->httpClient->request('HEAD', $url, [
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
