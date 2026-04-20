<?php

namespace Drupal\company_research\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\ai_conversation\Service\AIApiService;

/**
 * Service for analyzing authentication and account creation requirements.
 */
class AuthenticationAnalysisService {

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
   * Constructs an AuthenticationAnalysisService object.
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
   * Analyze authentication requirements.
   *
   * @param string $careers_url
   *   Careers page URL.
   * @param string $ats_platform
   *   ATS platform name.
   *
   * @return array
   *   Authentication analysis results.
   */
  public function analyzeAuthentication(string $careers_url, string $ats_platform): array {
    $this->logger->info('Analyzing authentication for: @url', [
      '@url' => $careers_url,
    ]);

    try {
      // Fetch page HTML.
      $response = $this->httpClient->request('GET', $careers_url, [
        'timeout' => 30,
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (compatible; CompanyResearchBot/1.0)',
        ],
      ]);
      $html = $response->getBody()->getContents();

      // Basic pattern detection for CAPTCHA.
      $captcha_type = $this->detectCaptcha($html);

      // Use AI to analyze authentication methods.
      $auth_methods = $this->analyzeAuthMethodsViaAI($html, $ats_platform);

      return [
        'methods' => $auth_methods['methods'] ?? ['email_password'],
        'captcha_type' => $captcha_type,
        'verification' => [
          'email_required' => $auth_methods['email_verification'] ?? FALSE,
          'phone_required' => $auth_methods['phone_verification'] ?? FALSE,
        ],
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Authentication analysis failed: @error', [
        '@error' => $e->getMessage(),
      ]);

      // Return default values on error.
      return [
        'methods' => ['email_password'],
        'captcha_type' => NULL,
        'verification' => [
          'email_required' => FALSE,
          'phone_required' => FALSE,
        ],
      ];
    }
  }

  /**
   * Detect CAPTCHA implementation.
   *
   * @param string $html
   *   HTML content.
   *
   * @return string|null
   *   CAPTCHA type or NULL.
   */
  protected function detectCaptcha(string $html): ?string {
    if (stripos($html, 'recaptcha/api.js') !== FALSE) {
      if (stripos($html, '?render=') !== FALSE) {
        return 'recaptcha_v3';
      }
      return 'recaptcha_v2';
    }

    if (stripos($html, 'hcaptcha.com') !== FALSE) {
      return 'hcaptcha';
    }

    return NULL;
  }

  /**
   * Analyze authentication methods using AI.
   *
   * @param string $html
   *   HTML content.
   * @param string $ats_platform
   *   ATS platform name.
   *
   * @return array
   *   Analysis results.
   */
  protected function analyzeAuthMethodsViaAI(string $html, string $ats_platform): array {
    try {
      // Truncate HTML for AI analysis.
      $html_sample = substr($html, 0, 5000);

      $prompt = "Analyze this HTML from a {$ats_platform} careers page and determine:
1. What authentication methods are available (e.g., email/password, Google SSO, LinkedIn SSO)?
2. Is email verification required for new accounts?
3. Is phone verification required?

Respond in JSON format with keys: methods (array), email_verification (boolean), phone_verification (boolean).

HTML Sample:
{$html_sample}";

      $response = $this->aiService->sendMessage($prompt, 'company_research', 'auth_analysis');

      if (!empty($response)) {
        // Try to parse JSON response.
        $decoded = json_decode($response, TRUE);
        if ($decoded && is_array($decoded)) {
          return $decoded;
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('AI auth analysis failed: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    // Return default structure.
    return [
      'methods' => ['email_password'],
      'email_verification' => FALSE,
      'phone_verification' => FALSE,
    ];
  }

}
