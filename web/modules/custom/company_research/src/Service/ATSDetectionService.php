<?php

namespace Drupal\company_research\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ai_conversation\Service\AIApiService;

/**
 * Service for detecting ATS platforms from careers pages.
 */
class ATSDetectionService {

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
   * The AI API service.
   *
   * @var \Drupal\ai_conversation\Service\AIApiService
   */
  protected $aiService;

  /**
   * Platform signatures for detection.
   *
   * @var array
   */
  protected $platformSignatures = [
    'Workday' => [
      'domains' => ['workday.com'],
      'patterns' => ['workday', 'WORKDAY'],
    ],
    'Greenhouse' => [
      'domains' => ['greenhouse.io', 'boards.greenhouse.io'],
      'patterns' => ['greenhouse', 'grnhse'],
    ],
    'Oracle Taleo' => [
      'domains' => ['taleo.net'],
      'patterns' => ['taleo', 'taleoContent'],
    ],
    'Lever' => [
      'domains' => ['lever.co'],
      'patterns' => ['lever', 'lever-jobs'],
    ],
    'SmartRecruiters' => [
      'domains' => ['smartrecruiters.com'],
      'patterns' => ['smartrecruiters', 'st-app'],
    ],
    'iCIMS' => [
      'domains' => ['icims.com'],
      'patterns' => ['icims'],
    ],
  ];

  /**
   * Constructs an ATSDetectionService object.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelInterface $logger,
    AIApiService $ai_service
  ) {
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->aiService = $ai_service;
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

    // Try domain-based detection first (highest confidence).
    $result = $this->detectViaDomain($careers_url);
    if ($result) {
      return $result;
    }

    // Fetch page HTML for further analysis.
    try {
      $response = $this->httpClient->request('GET', $careers_url, [
        'timeout' => 30,
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (compatible; CompanyResearchBot/1.0)',
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

    // Try HTML pattern detection.
    $result = $this->detectViaHTMLPatterns($html);
    if ($result) {
      return $result;
    }

    // Try AI-powered detection as fallback.
    $result = $this->detectViaAI($html, $careers_url);
    if ($result) {
      return $result;
    }

    // Could not detect - return unknown.
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

    return NULL;
  }

  /**
   * Detect ATS platform via HTML patterns.
   *
   * @param string $html
   *   HTML content.
   *
   * @return array|null
   *   Platform info or NULL if not detected.
   */
  protected function detectViaHTMLPatterns(string $html): ?array {
    foreach ($this->platformSignatures as $platform => $signatures) {
      foreach ($signatures['patterns'] as $pattern) {
        if (stripos($html, $pattern) !== FALSE) {
          return [
            'platform' => $platform,
            'version' => NULL,
            'confidence' => 'medium',
            'detection_method' => 'html_pattern',
          ];
        }
      }
    }

    return NULL;
  }

  /**
   * Detect ATS platform using AI analysis.
   *
   * @param string $html
   *   HTML content.
   * @param string $url
   *   Page URL.
   *
   * @return array|null
   *   Platform info or NULL if not detected.
   */
  protected function detectViaAI(string $html, string $url): ?array {
    try {
      // Truncate HTML to first 5000 characters for AI analysis.
      $html_sample = substr($html, 0, 5000);

      $prompt = "Analyze this HTML snippet from a company careers page and identify which Application Tracking System (ATS) is being used. Look for known platforms like Workday, Greenhouse, Taleo, Lever, SmartRecruiters, iCIMS, or others. Respond with ONLY the platform name, or 'UNKNOWN' if you cannot determine it.\n\nURL: {$url}\n\nHTML Sample:\n{$html_sample}";

      $response = $this->aiService->sendMessage($prompt, 'company_research', 'ats_detection');

      if (!empty($response)) {
        $platform = trim($response);
        if ($platform !== 'UNKNOWN') {
          return [
            'platform' => $platform,
            'version' => NULL,
            'confidence' => 'low',
            'detection_method' => 'ai_analysis',
          ];
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('AI detection failed: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

}
