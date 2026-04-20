# Company Research Path - Implementation Examples

**Document Version:** 1.0  
**Created:** February 13, 2026  
**Status:** Design Reference (Implementation Examples)  
**Related Documents:**
- [COMPANY_RESEARCH_PATH_DESIGN.md](./COMPANY_RESEARCH_PATH_DESIGN.md)
- [COMPANY_RESEARCH_DIAGRAMS.md](./COMPANY_RESEARCH_DIAGRAMS.md)

This document provides code examples and implementation patterns for the company research path. These examples are for reference only and should be adapted to fit the actual implementation.

---

## Table of Contents
1. [Service Implementation Examples](#service-implementation-examples)
2. [Controller Examples](#controller-examples)
3. [Database Schema Examples](#database-schema-examples)
4. [API Client Examples](#api-client-examples)
5. [Configuration Examples](#configuration-examples)
6. [Testing Examples](#testing-examples)

---

## Service Implementation Examples

### 1. CompanyResearchService (Orchestrator)

**File:** `src/Service/CompanyResearchService.php`

```php
<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Main service for orchestrating company research workflow.
 */
class CompanyResearchService {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Company discovery service.
   *
   * @var \Drupal\job_hunter\Service\CompanyDiscoveryService
   */
  protected CompanyDiscoveryService $companyDiscovery;

  /**
   * Careers page discovery service.
   *
   * @var \Drupal\job_hunter\Service\CareersPageDiscoveryService
   */
  protected CareersPageDiscoveryService $careersDiscovery;

  /**
   * ATS detection service.
   *
   * @var \Drupal\job_hunter\Service\ATSDetectionService
   */
  protected ATSDetectionService $atsDetection;

  /**
   * API discovery service.
   *
   * @var \Drupal\job_hunter\Service\APIDiscoveryService
   */
  protected APIDiscoveryService $apiDiscovery;

  /**
   * Authentication analysis service.
   *
   * @var \Drupal\job_hunter\Service\AuthenticationAnalysisService
   */
  protected AuthenticationAnalysisService $authAnalysis;

  /**
   * Constructs a CompanyResearchService object.
   */
  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    CompanyDiscoveryService $company_discovery,
    CareersPageDiscoveryService $careers_discovery,
    ATSDetectionService $ats_detection,
    APIDiscoveryService $api_discovery,
    AuthenticationAnalysisService $auth_analysis
  ) {
    $this->database = $database;
    $this->logger = $logger_factory->get('job_hunter_research');
    $this->config = $config_factory->get('job_hunter.company_research.settings');
    $this->companyDiscovery = $company_discovery;
    $this->careersDiscovery = $careers_discovery;
    $this->atsDetection = $ats_detection;
    $this->apiDiscovery = $api_discovery;
    $this->authAnalysis = $auth_analysis;
  }

  /**
   * Execute complete company research workflow.
   *
   * @param string $company_name
   *   Company name to research.
   * @param array $options
   *   Optional parameters:
   *   - refresh: Force refresh cached data (default: FALSE)
   *   - deep_scan: Perform comprehensive analysis (default: TRUE)
   *   - timeout: Maximum execution time in seconds (default: 300)
   *
   * @return array
   *   Research results with structure:
   *   - company_name: string
   *   - company_domain: string
   *   - careers_pages: array
   *   - ats_detection: array
   *   - api_endpoints: array
   *   - authentication: array
   *   - automation_readiness: array
   *   - metadata: array
   *
   * @throws \Exception
   *   If critical steps fail.
   */
  public function executeResearch(string $company_name, array $options = []): array {
    $start_time = microtime(TRUE);
    
    // Normalize options
    $options += [
      'refresh' => FALSE,
      'deep_scan' => TRUE,
      'timeout' => $this->config->get('research_timeout') ?? 300,
    ];

    $this->logger->info('Starting research for company: @company', [
      '@company' => $company_name,
    ]);

    try {
      // Step 1: Check cache (unless refresh requested)
      if (!$options['refresh']) {
        $cached = $this->getCachedResearch($company_name);
        if ($cached !== NULL) {
          $this->logger->info('Returning cached results for: @company', [
            '@company' => $company_name,
          ]);
          return $cached;
        }
      }

      // Step 2: Discover company website
      $this->logger->info('Step 1/6: Discovering company website');
      $company_info = $this->companyDiscovery->discoverCompanyWebsite($company_name);
      
      if (empty($company_info['domain'])) {
        throw new \Exception('Could not discover company website');
      }

      // Step 3: Discover careers pages
      $this->logger->info('Step 2/6: Discovering careers pages');
      $careers_pages = $this->careersDiscovery->discoverCareersPages($company_info['domain']);
      
      if (empty($careers_pages)) {
        $this->logger->warning('No careers pages found for: @domain', [
          '@domain' => $company_info['domain'],
        ]);
        // Continue with partial results
      }

      // Step 4: Detect ATS platform
      $this->logger->info('Step 3/6: Detecting ATS platform');
      $ats_info = NULL;
      if (!empty($careers_pages)) {
        $primary_careers_url = $careers_pages[0]['url'];
        $ats_info = $this->atsDetection->detectATSPlatform($primary_careers_url);
      }

      // Step 5: Discover API endpoints (if ATS detected)
      $this->logger->info('Step 4/6: Discovering API endpoints');
      $api_info = NULL;
      if ($ats_info && $options['deep_scan']) {
        $api_info = $this->apiDiscovery->discoverAPIEndpoints(
          $primary_careers_url,
          $ats_info['platform']
        );
      }

      // Step 6: Analyze authentication requirements
      $this->logger->info('Step 5/6: Analyzing authentication');
      $auth_info = NULL;
      if (!empty($careers_pages) && $options['deep_scan']) {
        $auth_info = $this->authAnalysis->analyzeAuthentication(
          $primary_careers_url,
          $ats_info['platform'] ?? 'unknown'
        );
      }

      // Step 7: Calculate automation readiness
      $this->logger->info('Step 6/6: Calculating automation readiness');
      $readiness = $this->calculateAutomationReadiness($ats_info, $api_info, $auth_info);

      // Step 8: Compile results
      $results = [
        'company_name' => $company_name,
        'research_date' => date('c'),
        'company_domain' => $company_info['domain'],
        'careers_pages' => $careers_pages,
        'ats_detection' => $ats_info,
        'api_endpoints' => $api_info,
        'authentication' => $auth_info,
        'automation_readiness' => $readiness,
        'metadata' => [
          'research_duration_ms' => round((microtime(TRUE) - $start_time) * 1000),
          'deep_scan' => $options['deep_scan'],
          'timestamp' => time(),
        ],
      ];

      // Step 9: Store results
      $this->storeResearchResults($company_name, $results);

      $this->logger->info('Research completed for: @company', [
        '@company' => $company_name,
      ]);

      return $results;

    }
    catch (\Exception $e) {
      $this->logger->error('Research failed for @company: @error', [
        '@company' => $company_name,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Get cached research results if available and fresh.
   *
   * @param string $company_name
   *   Company name.
   *
   * @return array|null
   *   Cached results or NULL if not cached/expired.
   */
  public function getCachedResearch(string $company_name): ?array {
    $cache_ttl = $this->config->get('cache_ttl') ?? 2592000; // 30 days default
    
    try {
      $result = $this->database->select('jobhunter_company_research', 'r')
        ->fields('r')
        ->condition('company_name', $company_name)
        ->condition('created_at', time() - $cache_ttl, '>')
        ->orderBy('created_at', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      if ($result) {
        // Decode JSON fields
        $result['careers_page_urls'] = json_decode($result['careers_page_urls'], TRUE);
        $result['api_endpoints'] = json_decode($result['api_endpoints'], TRUE);
        $result['auth_methods'] = json_decode($result['auth_methods'], TRUE);
        $result['verification_requirements'] = json_decode($result['verification_requirements'], TRUE);
        $result['metadata'] = json_decode($result['metadata'], TRUE);
        
        return $result;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Cache lookup failed: @error', ['@error' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Store research results in database.
   *
   * @param string $company_name
   *   Company name.
   * @param array $results
   *   Research results.
   *
   * @return bool
   *   TRUE on success.
   */
  public function storeResearchResults(string $company_name, array $results): bool {
    try {
      $this->database->insert('jobhunter_company_research')
        ->fields([
          'company_name' => $company_name,
          'research_date' => time(),
          'company_domain' => $results['company_domain'] ?? NULL,
          'careers_page_urls' => json_encode($results['careers_pages'] ?? []),
          'ats_platform' => $results['ats_detection']['platform'] ?? NULL,
          'ats_version' => $results['ats_detection']['version'] ?? NULL,
          'ats_confidence_level' => $results['ats_detection']['confidence'] ?? NULL,
          'api_base_url' => $results['api_endpoints']['base_url'] ?? NULL,
          'api_endpoints' => json_encode($results['api_endpoints'] ?? []),
          'auth_methods' => json_encode($results['authentication']['methods'] ?? []),
          'verification_requirements' => json_encode($results['authentication']['verification'] ?? []),
          'captcha_type' => $results['authentication']['bot_prevention']['captcha_type'] ?? NULL,
          'automation_readiness' => $results['automation_readiness']['level'] ?? NULL,
          'metadata' => json_encode($results['metadata'] ?? []),
          'created_at' => time(),
          'updated_at' => time(),
        ])
        ->execute();

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to store research results: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Calculate automation readiness based on research results.
   *
   * @param array|null $ats_info
   *   ATS detection information.
   * @param array|null $api_info
   *   API discovery information.
   * @param array|null $auth_info
   *   Authentication analysis information.
   *
   * @return array
   *   Automation readiness assessment.
   */
  protected function calculateAutomationReadiness($ats_info, $api_info, $auth_info): array {
    $ready_features = [];
    $blocked_features = [];
    $blockers = [];
    
    // Check if we have API access
    $has_api = !empty($api_info['endpoints']);
    
    // Check authentication complexity
    $has_captcha = !empty($auth_info['bot_prevention']['captcha_type']);
    $has_email_verification = !empty($auth_info['verification']['email_verification']['required']);
    $has_phone_verification = !empty($auth_info['verification']['phone_verification']['required']);
    $has_sso_only = empty($auth_info['methods']) || 
                    (count($auth_info['methods']) === 1 && $auth_info['methods'][0] !== 'email_password');

    // Determine readiness level
    if ($has_api && !$has_captcha && !$has_email_verification && !$has_phone_verification) {
      $level = 'ready';
      $ready_features = ['job_listing_retrieval', 'auto_application', 'api_access'];
    }
    elseif ($has_api || (!$has_phone_verification && !$has_sso_only)) {
      $level = 'partial';
      
      if ($has_api) {
        $ready_features[] = 'job_listing_retrieval';
        $ready_features[] = 'api_access';
      }
      
      if ($has_captcha) {
        $blocked_features[] = 'auto_registration';
        $blockers[] = 'recaptcha_required';
      }
      
      if ($has_email_verification) {
        $blocked_features[] = 'auto_application';
        $blockers[] = 'email_verification_required';
      }
    }
    else {
      $level = 'manual';
      $blocked_features = ['job_listing_retrieval', 'auto_registration', 'auto_application'];
      
      if (!$has_api) {
        $blockers[] = 'no_api_available';
      }
      if ($has_captcha) {
        $blockers[] = 'captcha_required';
      }
      if ($has_phone_verification) {
        $blockers[] = 'phone_verification_required';
      }
      if ($has_sso_only) {
        $blockers[] = 'sso_only';
      }
    }

    return [
      'level' => $level,
      'ready_features' => $ready_features,
      'blocked_features' => $blocked_features,
      'blockers' => $blockers,
      'recommendations' => $this->generateRecommendations($level, $blockers),
    ];
  }

  /**
   * Generate recommendations based on automation readiness.
   *
   * @param string $level
   *   Readiness level.
   * @param array $blockers
   *   Array of blockers.
   *
   * @return array
   *   Array of recommendation strings.
   */
  protected function generateRecommendations(string $level, array $blockers): array {
    $recommendations = [];

    if ($level === 'ready') {
      $recommendations[] = 'Full automation possible';
      $recommendations[] = 'Use API for job listing retrieval';
      $recommendations[] = 'Auto-application can be enabled';
    }
    elseif ($level === 'partial') {
      $recommendations[] = 'Partial automation possible';
      
      if (in_array('recaptcha_required', $blockers)) {
        $recommendations[] = 'Manual CAPTCHA solving required';
        $recommendations[] = 'Consider CAPTCHA solving service integration';
      }
      
      if (in_array('email_verification_required', $blockers)) {
        $recommendations[] = 'Email verification integration needed';
        $recommendations[] = 'Consider automated email processing';
      }
    }
    else {
      $recommendations[] = 'Manual process required';
      $recommendations[] = 'Consider manual account creation';
      
      if (in_array('no_api_available', $blockers)) {
        $recommendations[] = 'Web scraping may be possible but not recommended';
      }
    }

    return $recommendations;
  }

}
```

---

### 2. CompanyDiscoveryService

**File:** `src/Service/CompanyDiscoveryService.php`

```php
<?php

namespace Drupal\job_hunter\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for discovering company websites from company names.
 */
class CompanyDiscoveryService {

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructs a CompanyDiscoveryService object.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('job_hunter_research');
    $this->config = $config_factory->get('job_hunter.company_research.settings');
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

    // Try Google Search API first
    if ($this->config->get('google_search_api.enabled')) {
      try {
        $result = $this->searchViaGoogle($company_name);
        if ($result) {
          return $result;
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Google Search failed: @error', [
          '@error' => $e->getMessage(),
        ]);
      }
    }

    // Try LinkedIn API as fallback
    if ($this->config->get('linkedin_api.enabled')) {
      try {
        $result = $this->searchViaLinkedIn($company_name);
        if ($result) {
          return $result;
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('LinkedIn Search failed: @error', [
          '@error' => $e->getMessage(),
        ]);
      }
    }

    // Try manual domain patterns as last resort
    $result = $this->tryManualPatterns($company_name);
    if ($result) {
      return $result;
    }

    throw new \Exception('Could not discover company website for: ' . $company_name);
  }

  /**
   * Search for company website via Google Custom Search API.
   *
   * @param string $company_name
   *   Company name.
   *
   * @return array|null
   *   Company info or NULL if not found.
   */
  protected function searchViaGoogle(string $company_name): ?array {
    $api_key = $this->config->get('google_search_api.api_key');
    $search_engine_id = $this->config->get('google_search_api.search_engine_id');

    if (empty($api_key) || empty($search_engine_id)) {
      return NULL;
    }

    $query = $company_name . ' official website';
    $url = 'https://www.googleapis.com/customsearch/v1';
    
    try {
      $response = $this->httpClient->request('GET', $url, [
        'query' => [
          'key' => $api_key,
          'cx' => $search_engine_id,
          'q' => $query,
          'num' => 3,
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      
      if (!empty($data['items'])) {
        // Get first result
        $first_result = $data['items'][0];
        $domain = parse_url($first_result['link'], PHP_URL_HOST);
        $domain = preg_replace('/^www\./', '', $domain);

        // Validate domain
        if ($this->validateDomain($domain)) {
          return [
            'domain' => $domain,
            'url' => $first_result['link'],
            'valid' => TRUE,
            'method' => 'google_search',
          ];
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Google Search API error: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Search for company via LinkedIn Company API.
   *
   * @param string $company_name
   *   Company name.
   *
   * @return array|null
   *   Company info or NULL if not found.
   */
  protected function searchViaLinkedIn(string $company_name): ?array {
    // LinkedIn API implementation would go here
    // This requires OAuth authentication and Company API access
    // For now, return NULL
    return NULL;
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
    // Generate common domain patterns
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
  public function validateDomain(string $domain): bool {
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
```

---

### 3. ATSDetectionService

**File:** `src/Service/ATSDetectionService.php`

```php
<?php

namespace Drupal\job_hunter\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Service for detecting ATS platforms from careers pages.
 */
class ATSDetectionService {

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Platform detection patterns.
   *
   * @var array
   */
  protected array $platformSignatures;

  /**
   * Constructs an ATSDetectionService object.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('job_hunter_research');
    $this->platformSignatures = $this->getPlatformSignatures();
  }

  /**
   * Detect ATS platform from careers page URL.
   *
   * @param string $careers_url
   *   Careers page URL.
   *
   * @return array
   *   Array with keys:
   *   - platform: Platform name (e.g., "Workday")
   *   - version: Platform version (if detectable)
   *   - confidence: Detection confidence (high/medium/low)
   *   - detection_method: Method used for detection
   *
   * @throws \Exception
   *   If URL cannot be accessed.
   */
  public function detectATSPlatform(string $careers_url): array {
    $this->logger->info('Detecting ATS platform for: @url', [
      '@url' => $careers_url,
    ]);

    // Try domain-based detection first (highest confidence)
    $result = $this->detectViaDomain($careers_url);
    if ($result) {
      return $result;
    }

    // Fetch page HTML for further analysis
    try {
      $response = $this->httpClient->request('GET', $careers_url, [
        'timeout' => 30,
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (compatible; JobHunterBot/1.0)',
        ],
      ]);
      $html = $response->getBody()->getContents();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch careers page: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }

    // Try HTML pattern detection
    $result = $this->detectViaHTMLPatterns($html);
    if ($result) {
      return $result;
    }

    // Try JavaScript library detection
    $result = $this->detectViaJavaScript($html);
    if ($result) {
      return $result;
    }

    // Try meta tag detection
    $result = $this->detectViaMetaTags($html);
    if ($result) {
      return $result;
    }

    // Could not detect - return unknown
    return [
      'platform' => 'CUSTOM_OR_UNKNOWN',
      'version' => NULL,
      'confidence' => 'low',
      'detection_method' => 'none',
    ];
  }

  /**
   * Detect ATS platform via domain analysis.
   *
   * @param string $url
   *   Page URL.
   *
   * @return array|null
   *   Platform info or NULL if not detected.
   */
  protected function detectViaDomain(string $url): ?array {
    $host = parse_url($url, PHP_URL_HOST);

    foreach ($this->platformSignatures as $platform => $signatures) {
      if (!empty($signatures['domains'])) {
        foreach ($signatures['domains'] as $domain_pattern) {
          if (strpos($host, $domain_pattern) !== FALSE) {
            return [
              'platform' => $platform,
              'version' => NULL,
              'confidence' => 'high',
              'detection_method' => 'domain',
            ];
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Detect ATS platform via HTML/CSS patterns.
   *
   * @param string $html
   *   HTML content.
   *
   * @return array|null
   *   Platform info or NULL if not detected.
   */
  protected function detectViaHTMLPatterns(string $html): ?array {
    $crawler = new Crawler($html);

    foreach ($this->platformSignatures as $platform => $signatures) {
      if (!empty($signatures['selectors'])) {
        foreach ($signatures['selectors'] as $selector) {
          try {
            if ($crawler->filter($selector)->count() > 0) {
              return [
                'platform' => $platform,
                'version' => NULL,
                'confidence' => 'medium',
                'detection_method' => 'html_pattern',
              ];
            }
          }
          catch (\Exception $e) {
            // Invalid selector, continue
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Detect ATS platform via JavaScript libraries.
   *
   * @param string $html
   *   HTML content.
   *
   * @return array|null
   *   Platform info or NULL if not detected.
   */
  protected function detectViaJavaScript(string $html): ?array {
    foreach ($this->platformSignatures as $platform => $signatures) {
      if (!empty($signatures['scripts'])) {
        foreach ($signatures['scripts'] as $script_pattern) {
          if (stripos($html, $script_pattern) !== FALSE) {
            return [
              'platform' => $platform,
              'version' => NULL,
              'confidence' => 'low',
              'detection_method' => 'javascript',
            ];
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Detect ATS platform via meta tags.
   *
   * @param string $html
   *   HTML content.
   *
   * @return array|null
   *   Platform info or NULL if not detected.
   */
  protected function detectViaMetaTags(string $html): ?array {
    $crawler = new Crawler($html);

    try {
      $meta_tags = $crawler->filter('meta')->each(function (Crawler $node) {
        return [
          'name' => $node->attr('name'),
          'content' => $node->attr('content'),
          'property' => $node->attr('property'),
        ];
      });

      foreach ($meta_tags as $tag) {
        foreach ($this->platformSignatures as $platform => $signatures) {
          if (!empty($signatures['meta_patterns'])) {
            foreach ($signatures['meta_patterns'] as $pattern) {
              if (stripos($tag['content'] ?? '', $pattern) !== FALSE) {
                return [
                  'platform' => $platform,
                  'version' => NULL,
                  'confidence' => 'low',
                  'detection_method' => 'meta_tag',
                ];
              }
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      // Meta tag parsing failed
    }

    return NULL;
  }

  /**
   * Get platform detection signatures.
   *
   * @return array
   *   Array of platform signatures.
   */
  protected function getPlatformSignatures(): array {
    return [
      'Workday' => [
        'domains' => ['workday.com'],
        'selectors' => ['[data-automation-id*="workday"]', '.WORKDAY-theme'],
        'scripts' => ['workday.js', 'wd-'],
        'meta_patterns' => ['Workday'],
      ],
      'Greenhouse' => [
        'domains' => ['greenhouse.io', 'boards.greenhouse.io'],
        'selectors' => ['#grnhse_app', '.greenhouse-application'],
        'scripts' => ['greenhouse.io'],
        'meta_patterns' => ['Greenhouse'],
      ],
      'Oracle Taleo' => [
        'domains' => ['taleo.net'],
        'selectors' => ['#taleo-', '.taleoContent'],
        'scripts' => ['taleo.net'],
        'meta_patterns' => ['Taleo', 'Oracle'],
      ],
      'Lever' => [
        'domains' => ['lever.co'],
        'selectors' => ['.lever-jobs', '[data-lever]'],
        'scripts' => ['lever.co'],
        'meta_patterns' => ['Lever'],
      ],
      'SmartRecruiters' => [
        'domains' => ['smartrecruiters.com'],
        'selectors' => ['#st-app', '.smartrecruiters'],
        'scripts' => ['smartrecruiters.com'],
        'meta_patterns' => ['SmartRecruiters'],
      ],
      'iCIMS' => [
        'domains' => ['icims.com'],
        'selectors' => ['.icims-', '#icims-'],
        'scripts' => ['icims.com'],
        'meta_patterns' => ['iCIMS'],
      ],
    ];
  }

}
```

---

## Controller Examples

### CompanyResearchServiceController

**File:** `src/Controller/CompanyResearchServiceController.php`

```php
<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\job_hunter\Service\CompanyResearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for company research operations.
 */
class CompanyResearchServiceController extends ControllerBase {

  /**
   * Company research service.
   *
   * @var \Drupal\job_hunter\Service\CompanyResearchService
   */
  protected CompanyResearchService $researchService;

  /**
   * Constructs a CompanyResearchServiceController object.
   */
  public function __construct(CompanyResearchService $research_service) {
    $this->researchService = $research_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('job_hunter.company_research')
    );
  }

  /**
   * Start company research form page.
   *
   * @return array
   *   Render array.
   */
  public function startResearch(): array {
    return [
      '#theme' => 'company_research_form',
      '#attached' => [
        'library' => [
          'job_hunter/company-research',
        ],
      ],
    ];
  }

  /**
   * Execute company research (AJAX endpoint).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with research results.
   */
  public function executeResearch(Request $request): JsonResponse {
    $company_name = $request->request->get('company_name');
    $refresh = (bool) $request->request->get('refresh', FALSE);

    if (empty($company_name)) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Company name is required',
      ], 400);
    }

    try {
      $results = $this->researchService->executeResearch($company_name, [
        'refresh' => $refresh,
      ]);

      return new JsonResponse([
        'success' => TRUE,
        'data' => $results,
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Display research results page.
   *
   * @param int $company_id
   *   Company ID.
   *
   * @return array
   *   Render array.
   */
  public function displayResults(int $company_id): array {
    // Fetch company research results from database
    $results = $this->fetchResearchResults($company_id);

    if (!$results) {
      return [
        '#markup' => $this->t('No research results found for company ID @id', [
          '@id' => $company_id,
        ]),
      ];
    }

    return [
      '#theme' => 'company_research_results',
      '#company_name' => $results['company_name'],
      '#results' => $results,
      '#attached' => [
        'library' => [
          'job_hunter/company-research',
        ],
      ],
    ];
  }

  /**
   * Fetch research results from database.
   *
   * @param int $company_id
   *   Company ID.
   *
   * @return array|null
   *   Research results or NULL.
   */
  protected function fetchResearchResults(int $company_id): ?array {
    // Implementation would query the database
    // This is a placeholder
    return NULL;
  }

}
```

---

## Database Schema Examples

### Installation Hook

**File:** `job_hunter.install`

```php
/**
 * Create company research table.
 */
function job_hunter_update_9005() {
  $schema = Database::getConnection()->schema();
  
  if (!$schema->tableExists('jobhunter_company_research')) {
    $table_schema = [
      'description' => 'Stores company research results including ATS detection and authentication analysis',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'description' => 'Primary Key: Unique research record ID',
        ],
        'company_name' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'description' => 'Company name',
        ],
        'company_domain' => [
          'type' => 'varchar',
          'length' => 255,
          'description' => 'Company domain (e.g., acme.com)',
        ],
        'research_date' => [
          'type' => 'int',
          'not null' => TRUE,
          'description' => 'Timestamp when research was conducted',
        ],
        'careers_page_urls' => [
          'type' => 'text',
          'size' => 'big',
          'description' => 'JSON array of careers page URLs',
        ],
        'ats_platform' => [
          'type' => 'varchar',
          'length' => 100,
          'description' => 'Detected ATS platform name',
        ],
        'ats_version' => [
          'type' => 'varchar',
          'length' => 50,
          'description' => 'ATS platform version',
        ],
        'ats_confidence_level' => [
          'type' => 'varchar',
          'length' => 20,
          'description' => 'Detection confidence: high, medium, low',
        ],
        'api_base_url' => [
          'type' => 'varchar',
          'length' => 255,
          'description' => 'Base URL for API endpoints',
        ],
        'api_endpoints' => [
          'type' => 'text',
          'size' => 'big',
          'description' => 'JSON array of API endpoints',
        ],
        'auth_methods' => [
          'type' => 'text',
          'size' => 'big',
          'description' => 'JSON array of authentication methods',
        ],
        'verification_requirements' => [
          'type' => 'text',
          'size' => 'big',
          'description' => 'JSON object of verification requirements',
        ],
        'captcha_type' => [
          'type' => 'varchar',
          'length' => 50,
          'description' => 'Type of CAPTCHA detected',
        ],
        'automation_readiness' => [
          'type' => 'varchar',
          'length' => 20,
          'description' => 'Automation readiness level: ready, partial, manual',
        ],
        'metadata' => [
          'type' => 'text',
          'size' => 'big',
          'description' => 'JSON object with additional metadata',
        ],
        'created_at' => [
          'type' => 'int',
          'not null' => TRUE,
          'description' => 'Creation timestamp',
        ],
        'updated_at' => [
          'type' => 'int',
          'not null' => TRUE,
          'description' => 'Last update timestamp',
        ],
      ],
      'primary key' => ['id'],
      'indexes' => [
        'company_name' => ['company_name'],
        'ats_platform' => ['ats_platform'],
        'automation_readiness' => ['automation_readiness'],
        'research_date' => ['research_date'],
      ],
    ];
    
    $schema->createTable('jobhunter_company_research', $table_schema);
  }
}
```

---

## Configuration Examples

### Configuration Schema

**File:** `config/schema/job_hunter.schema.yml`

```yaml
job_hunter.company_research.settings:
  type: config_object
  label: 'Company Research Settings'
  mapping:
    cache_ttl:
      type: integer
      label: 'Cache TTL in seconds'
    research_timeout:
      type: integer
      label: 'Research timeout in seconds'
    max_concurrent_jobs:
      type: integer
      label: 'Maximum concurrent research jobs'
    deep_scan_enabled:
      type: boolean
      label: 'Enable deep scanning'
    google_search_api:
      type: mapping
      label: 'Google Custom Search API Settings'
      mapping:
        enabled:
          type: boolean
          label: 'Enabled'
        api_key:
          type: string
          label: 'API Key'
        search_engine_id:
          type: string
          label: 'Search Engine ID'
    linkedin_api:
      type: mapping
      label: 'LinkedIn API Settings'
      mapping:
        enabled:
          type: boolean
          label: 'Enabled'
        client_id:
          type: string
          label: 'Client ID'
        client_secret:
          type: string
          label: 'Client Secret'
    network_analysis:
      type: mapping
      label: 'Network Analysis Settings'
      mapping:
        enabled:
          type: boolean
          label: 'Enabled'
        headless_browser:
          type: string
          label: 'Headless browser type'
        timeout:
          type: integer
          label: 'Timeout in seconds'
```

### Default Configuration

**File:** `config/install/job_hunter.company_research.settings.yml`

```yaml
cache_ttl: 2592000  # 30 days
research_timeout: 300  # 5 minutes
max_concurrent_jobs: 5
deep_scan_enabled: true

google_search_api:
  enabled: false
  api_key: ''
  search_engine_id: ''

linkedin_api:
  enabled: false
  client_id: ''
  client_secret: ''

network_analysis:
  enabled: true
  headless_browser: 'chrome'
  timeout: 60
```

---

## Testing Examples

### Unit Test Example

**File:** `tests/src/Unit/CompanyResearchServiceTest.php`

```php
<?php

namespace Drupal\Tests\job_hunter\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Service\CompanyResearchService;

/**
 * @coversDefaultClass \Drupal\job_hunter\Service\CompanyResearchService
 * @group job_hunter
 */
class CompanyResearchServiceTest extends UnitTestCase {

  /**
   * Test automation readiness calculation.
   *
   * @covers ::calculateAutomationReadiness
   * @dataProvider automationReadinessProvider
   */
  public function testCalculateAutomationReadiness($ats_info, $api_info, $auth_info, $expected_level) {
    // Mock dependencies
    $database = $this->createMock('\Drupal\Core\Database\Connection');
    $logger_factory = $this->createMock('\Drupal\Core\Logger\LoggerChannelFactoryInterface');
    $config_factory = $this->getConfigFactoryStub();
    
    // Mock services
    $company_discovery = $this->createMock('\Drupal\job_hunter\Service\CompanyDiscoveryService');
    $careers_discovery = $this->createMock('\Drupal\job_hunter\Service\CareersPageDiscoveryService');
    $ats_detection = $this->createMock('\Drupal\job_hunter\Service\ATSDetectionService');
    $api_discovery = $this->createMock('\Drupal\job_hunter\Service\APIDiscoveryService');
    $auth_analysis = $this->createMock('\Drupal\job_hunter\Service\AuthenticationAnalysisService');

    $service = new CompanyResearchService(
      $database,
      $logger_factory,
      $config_factory,
      $company_discovery,
      $careers_discovery,
      $ats_detection,
      $api_discovery,
      $auth_analysis
    );

    // Use reflection to test protected method
    $method = new \ReflectionMethod($service, 'calculateAutomationReadiness');
    $method->setAccessible(TRUE);
    
    $result = $method->invoke($service, $ats_info, $api_info, $auth_info);

    $this->assertEquals($expected_level, $result['level']);
  }

  /**
   * Data provider for automation readiness tests.
   */
  public function automationReadinessProvider() {
    return [
      // Full automation: Has API, no CAPTCHA, no verification
      [
        ['platform' => 'Greenhouse'],
        ['endpoints' => ['/jobs', '/applications']],
        ['bot_prevention' => [], 'verification' => []],
        'ready',
      ],
      // Partial automation: Has API but CAPTCHA
      [
        ['platform' => 'Workday'],
        ['endpoints' => ['/jobs']],
        ['bot_prevention' => ['captcha_type' => 'recaptcha_v3'], 'verification' => []],
        'partial',
      ],
      // Manual only: No API, has CAPTCHA
      [
        ['platform' => 'Custom'],
        [],
        ['bot_prevention' => ['captcha_type' => 'recaptcha_v2'], 'verification' => ['phone_verification' => ['required' => TRUE]]],
        'manual',
      ],
    ];
  }

}
```

---

## Template Examples

### Research Results Template

**File:** `templates/company-research-results.html.twig`

```twig
{#
/**
 * @file
 * Theme implementation for company research results.
 *
 * Available variables:
 * - company_name: Company name
 * - results: Research results array
 */
#}

<div class="company-research-results">
  <header class="results-header">
    <h1>{{ company_name }}</h1>
    <div class="research-date">
      Research Date: {{ results.research_date }}
    </div>
  </header>

  <section class="careers-pages">
    <h2>Careers Pages</h2>
    {% if results.careers_pages %}
      <ul class="careers-list">
        {% for page in results.careers_pages %}
          <li>
            <a href="{{ page.url }}" target="_blank">{{ page.url }}</a>
            <span class="page-type">{{ page.type }}</span>
            <span class="accessibility">
              {{ page.accessible ? '✓ Accessible' : '✗ Not Accessible' }}
            </span>
          </li>
        {% endfor %}
      </ul>
    {% else %}
      <p>No careers pages found.</p>
    {% endif %}
  </section>

  <section class="ats-detection">
    <h2>ATS Platform</h2>
    {% if results.ats_detection %}
      <div class="ats-info">
        <div class="platform-name">{{ results.ats_detection.platform }}</div>
        {% if results.ats_detection.version %}
          <div class="platform-version">Version: {{ results.ats_detection.version }}</div>
        {% endif %}
        <div class="confidence-badge confidence-{{ results.ats_detection.confidence }}">
          Confidence: {{ results.ats_detection.confidence|upper }}
        </div>
        <div class="detection-method">
          Detected via: {{ results.ats_detection.detection_method }}
        </div>
      </div>
    {% else %}
      <p>ATS platform not detected.</p>
    {% endif %}
  </section>

  <section class="api-endpoints">
    <h2>API Endpoints</h2>
    {% if results.api_endpoints.endpoints %}
      <div class="api-info">
        <div class="base-url">
          Base URL: <code>{{ results.api_endpoints.base_url }}</code>
        </div>
        <ul class="endpoints-list">
          {% for endpoint in results.api_endpoints.endpoints %}
            <li>
              <code class="method">{{ endpoint.method }}</code>
              <code class="path">{{ endpoint.path }}</code>
              {% if endpoint.description %}
                <span class="description">{{ endpoint.description }}</span>
              {% endif %}
            </li>
          {% endfor %}
        </ul>
      </div>
    {% else %}
      <p>No API endpoints discovered.</p>
    {% endif %}
  </section>

  <section class="authentication">
    <h2>Authentication</h2>
    {% if results.authentication %}
      <div class="auth-methods">
        <h3>Supported Methods</h3>
        <ul>
          {% for method in results.authentication.methods %}
            <li>{{ method|replace({'_': ' '})|title }}</li>
          {% endfor %}
        </ul>
      </div>

      {% if results.authentication.bot_prevention.captcha_type %}
        <div class="captcha-info">
          <h3>Bot Prevention</h3>
          <p>CAPTCHA Type: {{ results.authentication.bot_prevention.captcha_type }}</p>
        </div>
      {% endif %}

      <div class="verification">
        <h3>Verification Requirements</h3>
        <ul>
          {% if results.authentication.verification.email_verification.required %}
            <li>✓ Email verification required</li>
          {% endif %}
          {% if results.authentication.verification.phone_verification.required %}
            <li>✓ Phone verification required</li>
          {% endif %}
          {% if results.authentication.verification.two_factor.required %}
            <li>✓ Two-factor authentication required</li>
          {% endif %}
        </ul>
      </div>
    {% endif %}
  </section>

  <section class="automation-readiness">
    <h2>Automation Readiness</h2>
    {% if results.automation_readiness %}
      <div class="readiness-badge readiness-{{ results.automation_readiness.level }}">
        {{ results.automation_readiness.level|upper }}
      </div>

      <div class="ready-features">
        <h3>✓ Ready Features</h3>
        <ul>
          {% for feature in results.automation_readiness.ready_features %}
            <li>{{ feature|replace({'_': ' '})|title }}</li>
          {% endfor %}
        </ul>
      </div>

      {% if results.automation_readiness.blocked_features %}
        <div class="blocked-features">
          <h3>✗ Blocked Features</h3>
          <ul>
            {% for feature in results.automation_readiness.blocked_features %}
              <li>{{ feature|replace({'_': ' '})|title }}</li>
            {% endfor %}
          </ul>
        </div>
      {% endif %}

      {% if results.automation_readiness.blockers %}
        <div class="blockers">
          <h3>Blockers</h3>
          <ul>
            {% for blocker in results.automation_readiness.blockers %}
              <li>{{ blocker|replace({'_': ' '})|title }}</li>
            {% endfor %}
          </ul>
        </div>
      {% endif %}

      {% if results.automation_readiness.recommendations %}
        <div class="recommendations">
          <h3>Recommendations</h3>
          <ul>
            {% for recommendation in results.automation_readiness.recommendations %}
              <li>{{ recommendation }}</li>
            {% endfor %}
          </ul>
        </div>
      {% endif %}
    {% endif %}
  </section>
</div>
```

---

## Service Registration Example

**File:** `job_hunter.services.yml`

```yaml
services:
  # Main orchestrator service
  job_hunter.company_research:
    class: Drupal\job_hunter\Service\CompanyResearchService
    arguments:
      - '@database'
      - '@logger.factory'
      - '@config.factory'
      - '@job_hunter.company_discovery'
      - '@job_hunter.careers_discovery'
      - '@job_hunter.ats_detection'
      - '@job_hunter.api_discovery'
      - '@job_hunter.auth_analysis'

  # Individual service components
  job_hunter.company_discovery:
    class: Drupal\job_hunter\Service\CompanyDiscoveryService
    arguments:
      - '@http_client'
      - '@logger.factory'
      - '@config.factory'

  job_hunter.careers_discovery:
    class: Drupal\job_hunter\Service\CareersPageDiscoveryService
    arguments:
      - '@http_client'
      - '@logger.factory'

  job_hunter.ats_detection:
    class: Drupal\job_hunter\Service\ATSDetectionService
    arguments:
      - '@http_client'
      - '@logger.factory'

  job_hunter.api_discovery:
    class: Drupal\job_hunter\Service\APIDiscoveryService
    arguments:
      - '@http_client'
      - '@logger.factory'

  job_hunter.auth_analysis:
    class: Drupal\job_hunter\Service\AuthenticationAnalysisService
    arguments:
      - '@http_client'
      - '@logger.factory'
```

---

## Route Example

**File:** `job_hunter.routing.yml`

```yaml
# Company research routes
job_hunter.company_research.start:
  path: '/job-hunter/company-research/start'
  defaults:
    _controller: '\Drupal\job_hunter\Controller\CompanyResearchServiceController::startResearch'
    _title: 'Start Company Research'
  requirements:
    _permission: 'access company research'

job_hunter.company_research.execute:
  path: '/job-hunter/company-research/execute'
  defaults:
    _controller: '\Drupal\job_hunter\Controller\CompanyResearchServiceController::executeResearch'
  methods: [POST]
  requirements:
    _permission: 'access company research'

job_hunter.company_research.results:
  path: '/job-hunter/company-research/results/{company_id}'
  defaults:
    _controller: '\Drupal\job_hunter\Controller\CompanyResearchServiceController::displayResults'
    _title: 'Research Results'
  requirements:
    _permission: 'access company research'
    company_id: \d+
```

---

## Permission Example

**File:** `job_hunter.permissions.yml`

```yaml
access company research:
  title: 'Access company research tools'
  description: 'Allows users to research companies and view results'

administer company research:
  title: 'Administer company research'
  description: 'Configure company research settings and manage research data'
  restrict access: true
```

---

**End of Implementation Examples Document**

This document provides concrete code examples that developers can reference when implementing the company research path design. All examples follow Drupal best practices and the architecture outlined in the main design document.
