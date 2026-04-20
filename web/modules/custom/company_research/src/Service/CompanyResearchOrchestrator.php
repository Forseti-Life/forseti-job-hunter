<?php

namespace Drupal\company_research\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Main orchestration service for company research workflow.
 */
class CompanyResearchOrchestrator {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

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
   * The company discovery service.
   *
   * @var \Drupal\company_research\Service\CompanyDiscoveryService
   */
  protected $companyDiscovery;

  /**
   * The careers page discovery service.
   *
   * @var \Drupal\company_research\Service\CareersPageDiscoveryService
   */
  protected $careersDiscovery;

  /**
   * The ATS detection service.
   *
   * @var \Drupal\company_research\Service\ATSDetectionService
   */
  protected $atsDetection;

  /**
   * The authentication analysis service.
   *
   * @var \Drupal\company_research\Service\AuthenticationAnalysisService
   */
  protected $authAnalysis;

  /**
   * Constructs a CompanyResearchOrchestrator object.
   */
  public function __construct(
    Connection $database,
    LoggerChannelInterface $logger,
    ConfigFactoryInterface $config_factory,
    CompanyDiscoveryService $company_discovery,
    CareersPageDiscoveryService $careers_discovery,
    ATSDetectionService $ats_detection,
    AuthenticationAnalysisService $auth_analysis
  ) {
    $this->database = $database;
    $this->logger = $logger;
    $this->config = $config_factory->get('company_research.settings');
    $this->companyDiscovery = $company_discovery;
    $this->careersDiscovery = $careers_discovery;
    $this->atsDetection = $ats_detection;
    $this->authAnalysis = $auth_analysis;
  }

  /**
   * Execute complete company research workflow.
   *
   * @param string $company_name
   *   Company name to research.
   * @param array $options
   *   Optional parameters:
   *   - refresh: Force refresh cached data (default: FALSE).
   *
   * @return array
   *   Research results with 'id' key for the saved record.
   *
   * @throws \Exception
   *   If critical steps fail.
   */
  public function executeResearch(string $company_name, array $options = []): array {
    $start_time = microtime(TRUE);
    $options += ['refresh' => FALSE];

    $this->logger->info('Starting research for company: @company', [
      '@company' => $company_name,
    ]);

    try {
      // Step 1: Check cache (unless refresh requested).
      if (!$options['refresh']) {
        $cached = $this->getCachedResearch($company_name);
        if ($cached !== NULL) {
          $this->logger->info('Returning cached results for: @company', [
            '@company' => $company_name,
          ]);
          return $cached;
        }
      }

      // Step 2: Discover company website.
      $this->logger->info('Step 1/4: Discovering company website');
      $company_info = $this->companyDiscovery->discoverCompanyWebsite($company_name);

      if (empty($company_info['domain'])) {
        throw new \Exception('Could not discover company website');
      }

      // Step 3: Discover careers pages.
      $this->logger->info('Step 2/4: Discovering careers pages');
      $careers_pages = $this->careersDiscovery->discoverCareersPages($company_info['domain']);

      if (empty($careers_pages)) {
        $this->logger->warning('No careers pages found for: @domain', [
          '@domain' => $company_info['domain'],
        ]);
      }

      // Step 4: Detect ATS platform.
      $this->logger->info('Step 3/4: Detecting ATS platform');
      $ats_info = NULL;
      if (!empty($careers_pages)) {
        $primary_careers_url = $careers_pages[0]['url'];
        $ats_info = $this->atsDetection->detectATSPlatform($primary_careers_url);
      }

      // Step 5: Analyze authentication requirements.
      $this->logger->info('Step 4/4: Analyzing authentication');
      $auth_info = NULL;
      if (!empty($careers_pages)) {
        $auth_info = $this->authAnalysis->analyzeAuthentication(
          $primary_careers_url,
          $ats_info['platform'] ?? 'unknown'
        );
      }

      // Step 6: Calculate automation readiness.
      $readiness = $this->calculateAutomationReadiness($ats_info, $auth_info);

      // Step 7: Store results.
      $research_id = $this->storeResearchResults($company_name, [
        'company_domain' => $company_info['domain'],
        'careers_pages' => $careers_pages,
        'ats_info' => $ats_info,
        'auth_info' => $auth_info,
        'readiness' => $readiness,
        'duration_ms' => round((microtime(TRUE) - $start_time) * 1000),
      ]);

      $this->logger->info('Research completed for: @company', [
        '@company' => $company_name,
      ]);

      // Return results with ID.
      return [
        'id' => $research_id,
        'company_name' => $company_name,
        'company_domain' => $company_info['domain'],
        'careers_pages' => $careers_pages,
        'ats_info' => $ats_info,
        'auth_info' => $auth_info,
        'readiness' => $readiness,
      ];

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
  protected function getCachedResearch(string $company_name): ?array {
    $cache_ttl = 2592000; // 30 days default.

    try {
      $result = $this->database->select('company_research_results', 'r')
        ->fields('r')
        ->condition('company_name', $company_name)
        ->condition('created_at', time() - $cache_ttl, '>')
        ->orderBy('created_at', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      if ($result) {
        // Decode JSON fields.
        $result['careers_page_urls'] = json_decode($result['careers_page_urls'] ?? '[]', TRUE);
        $result['auth_methods'] = json_decode($result['auth_methods'] ?? '[]', TRUE);
        $result['verification_requirements'] = json_decode($result['verification_requirements'] ?? '{}', TRUE);
        $result['metadata'] = json_decode($result['metadata'] ?? '{}', TRUE);

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
   * @param array $data
   *   Research data.
   *
   * @return int
   *   The inserted research ID.
   */
  protected function storeResearchResults(string $company_name, array $data): int {
    $timestamp = time();

    return $this->database->insert('company_research_results')
      ->fields([
        'company_name' => $company_name,
        'company_domain' => $data['company_domain'] ?? NULL,
        'research_date' => $timestamp,
        'careers_page_urls' => json_encode($data['careers_pages'] ?? []),
        'ats_platform' => $data['ats_info']['platform'] ?? NULL,
        'ats_version' => $data['ats_info']['version'] ?? NULL,
        'ats_confidence_level' => $data['ats_info']['confidence'] ?? NULL,
        'auth_methods' => json_encode($data['auth_info']['methods'] ?? []),
        'verification_requirements' => json_encode($data['auth_info']['verification'] ?? []),
        'captcha_type' => $data['auth_info']['captcha_type'] ?? NULL,
        'automation_readiness' => $data['readiness']['level'] ?? NULL,
        'metadata' => json_encode(['duration_ms' => $data['duration_ms'] ?? 0]),
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
      ])
      ->execute();
  }

  /**
   * Calculate automation readiness based on research results.
   *
   * @param array|null $ats_info
   *   ATS detection information.
   * @param array|null $auth_info
   *   Authentication analysis information.
   *
   * @return array
   *   Automation readiness assessment.
   */
  protected function calculateAutomationReadiness($ats_info, $auth_info): array {
    $ready_features = [];
    $blocked_features = [];
    $blockers = [];

    // Check authentication complexity.
    $has_captcha = !empty($auth_info['captcha_type']);
    $has_email_verification = !empty($auth_info['verification']['email_required']);

    // Determine readiness level.
    if ($ats_info && !$has_captcha && !$has_email_verification) {
      $level = 'ready';
      $ready_features = ['job_listing_retrieval', 'auto_application'];
    }
    elseif ($ats_info || !$has_captcha) {
      $level = 'partial';
      $ready_features[] = 'job_listing_retrieval';

      if ($has_captcha) {
        $blocked_features[] = 'auto_registration';
        $blockers[] = 'captcha_required';
      }

      if ($has_email_verification) {
        $blocked_features[] = 'auto_application';
        $blockers[] = 'email_verification_required';
      }
    }
    else {
      $level = 'manual';
      $blocked_features = ['job_listing_retrieval', 'auto_registration', 'auto_application'];
      $blockers[] = 'no_automation_possible';
    }

    return [
      'level' => $level,
      'ready_features' => $ready_features,
      'blocked_features' => $blocked_features,
      'blockers' => $blockers,
    ];
  }

}
