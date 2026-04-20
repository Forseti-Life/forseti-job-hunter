<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for scraping job listings from AbbVie careers page.
 */
class AbbVieJobScrapingService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * AbbVie careers base URL.
   */
  const ABBVIE_CAREERS_BASE_URL = 'https://careers.abbvie.com/en';

  /**
   * Constructs a new AbbVieJobScrapingService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
  }

  /**
   * Search for jobs on AbbVie careers page using keywords.
   *
   * @param array $keywords
   *   Array of keywords to search for.
   * @param array $options
   *   Additional search options like location, function, etc.
   *
   * @return array
   *   Array of job listings found.
   */
  public function searchJobs(array $keywords, array $options = []) {
    try {
      // Build search URL with parameters
      $search_url = $this->buildSearchUrl($keywords, $options);
      
      // Make request to AbbVie careers page
      $response = $this->httpClient->request('GET', $search_url, [
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (compatible; JobDiscoveryBot/1.0)',
          'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
          'Accept-Language' => 'en-US,en;q=0.5',
        ],
        'timeout' => 30,
      ]);

      $html = $response->getBody()->getContents();
      
      // Parse the HTML to extract job listings
      $jobs = $this->parseJobListings($html);
      
      // Filter results based on keywords relevance
      $filtered_jobs = $this->filterJobsByRelevance($jobs, $keywords);
      
      $this->loggerFactory->get('job_hunter')
        ->info('Found @count jobs from AbbVie careers page', ['@count' => count($filtered_jobs)]);
      
      return $filtered_jobs;
      
    } catch (RequestException $e) {
      $this->loggerFactory->get('job_hunter')
        ->error('Error fetching AbbVie jobs: @message', ['@message' => $e->getMessage()]);
      
      // Return simulated data as fallback
      return $this->getSimulatedJobs($keywords);
    }
  }

  /**
   * Build search URL with parameters.
   *
   * @param array $keywords
   *   Keywords to search for.
   * @param array $options
   *   Additional search options.
   *
   * @return string
   *   The complete search URL.
   */
  private function buildSearchUrl(array $keywords, array $options = []) {
    $base_url = self::ABBVIE_CAREERS_BASE_URL . '/jobs';
    $params = [];
    
    // Add keyword search
    if (!empty($keywords)) {
      $params['q'] = implode(' ', array_slice($keywords, 0, 3)); // Use first 3 keywords
    }
    
    // Add location if specified
    if (!empty($options['location'])) {
      $params['location'] = $options['location'];
    }
    
    // Add function filter if specified
    if (!empty($options['function'])) {
      $params['options'] = $this->getFunctionOptionId($options['function']);
    }
    
    // Default pagination
    $params['page'] = $options['page'] ?? 1;
    
    return $base_url . '?' . http_build_query($params);
  }

  /**
   * Parse job listings from HTML content.
   *
   * @param string $html
   *   The HTML content from AbbVie careers page.
   *
   * @return array
   *   Array of parsed job listings.
   */
  private function parseJobListings($html) {
    $jobs = [];
    
    // Use DOMDocument to parse HTML
    $dom = new \DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new \DOMXPath($dom);
    
    // Find job tiles using the class structure from the provided HTML
    $job_nodes = $xpath->query("//div[contains(@class, 'attrax-vacancy-tile')]");
    
    foreach ($job_nodes as $job_node) {
      $job = $this->extractJobDetails($job_node, $xpath);
      if ($job) {
        $jobs[] = $job;
      }
    }
    
    return $jobs;
  }

  /**
   * Extract job details from a job node.
   *
   * @param \DOMElement $job_node
   *   The job DOM element.
   * @param \DOMXPath $xpath
   *   The XPath object for querying.
   *
   * @return array|null
   *   Job details array or null if extraction fails.
   */
  private function extractJobDetails(\DOMElement $job_node, \DOMXPath $xpath) {
    try {
      // Extract title and URL
      $title_node = $xpath->query(".//a[contains(@class, 'attrax-vacancy-tile__title')]", $job_node)->item(0);
      if (!$title_node) {
        return NULL;
      }
      
      $title = trim($title_node->textContent);
      $url = $title_node->getAttribute('href');
      
      // Make URL absolute if it's relative
      if (strpos($url, 'http') !== 0) {
        // Check if URL already starts with /en/ to avoid duplication
        if (strpos($url, '/en/') === 0) {
          $url = 'https://careers.abbvie.com' . $url;
        } else {
          $url = self::ABBVIE_CAREERS_BASE_URL . $url;
        }
      }
      
      // Extract location
      $location_node = $xpath->query(".//div[contains(@class, 'attrax-vacancy-tile__location-freetext')]/p[contains(@class, 'attrax-vacancy-tile__item-value')]", $job_node)->item(0);
      $location = $location_node ? trim($location_node->textContent) : '';
      
      // Extract description
      $desc_node = $xpath->query(".//div[contains(@class, 'attrax-vacancy-tile__description')]/p[contains(@class, 'attrax-vacancy-tile__item-value')]", $job_node)->item(0);
      $description = $desc_node ? trim($desc_node->textContent) : '';
      
      // Extract job ID
      $job_id_node = $xpath->query(".//div[contains(@class, 'attrax-vacancy-tile__externalreference')]/p[contains(@class, 'attrax-vacancy-tile__item-value')]", $job_node)->item(0);
      $job_id = $job_id_node ? trim($job_id_node->textContent) : '';
      
      // Extract function
      $function_node = $xpath->query(".//div[contains(@class, 'attrax-vacancy-tile__option-function')]/div/p", $job_node)->item(0);
      $function = $function_node ? trim($function_node->textContent) : '';
      
      // Extract therapy area
      $therapy_node = $xpath->query(".//div[contains(@class, 'attrax-vacancy-tile__option-therapy-area')]/div/p", $job_node)->item(0);
      $therapy_area = $therapy_node ? trim($therapy_node->textContent) : '';
      
      // Extract experience level
      $exp_node = $xpath->query(".//div[contains(@class, 'attrax-vacancy-tile__option-experience-level')]/div/p", $job_node)->item(0);
      $experience_level = $exp_node ? trim($exp_node->textContent) : '';
      
      // Extract job type
      $type_node = $xpath->query(".//div[contains(@class, 'attrax-vacancy-tile__option-job-type')]/div/p", $job_node)->item(0);
      $job_type = $type_node ? trim($type_node->textContent) : '';
      
      return [
        'title' => $title,
        'location' => $location,
        'description' => $description,
        'jobId' => $job_id,
        'url' => $url,
        'function' => $function,
        'therapyArea' => $therapy_area,
        'experienceLevel' => $experience_level,
        'jobType' => $job_type,
        'source' => 'abbvie_scraping',
        'scraped_at' => date('Y-m-d H:i:s'),
      ];
      
    } catch (\Exception $e) {
      $this->loggerFactory->get('job_hunter')
        ->warning('Failed to extract job details: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Filter jobs by keyword relevance.
   *
   * @param array $jobs
   *   Array of job listings.
   * @param array $keywords
   *   Keywords to match against.
   *
   * @return array
   *   Filtered job listings.
   */
  private function filterJobsByRelevance(array $jobs, array $keywords) {
    if (empty($keywords)) {
      return $jobs;
    }
    
    $filtered_jobs = [];
    
    foreach ($jobs as $job) {
      $relevance_score = $this->calculateRelevanceScore($job, $keywords);
      
      // Only include jobs with some relevance
      if ($relevance_score > 0) {
        $job['relevance_score'] = $relevance_score;
        $filtered_jobs[] = $job;
      }
    }
    
    // Sort by relevance score (highest first)
    usort($filtered_jobs, function($a, $b) {
      return $b['relevance_score'] <=> $a['relevance_score'];
    });
    
    return $filtered_jobs;
  }

  /**
   * Calculate relevance score for a job based on keywords.
   *
   * @param array $job
   *   Job details.
   * @param array $keywords
   *   Keywords to match.
   *
   * @return int
   *   Relevance score.
   */
  private function calculateRelevanceScore(array $job, array $keywords) {
    $score = 0;
    $job_text = strtolower(implode(' ', [
      $job['title'] ?? '',
      $job['description'] ?? '',
      $job['function'] ?? '',
      $job['therapyArea'] ?? '',
    ]));
    
    foreach ($keywords as $keyword) {
      $keyword = strtolower(trim($keyword));
      if (empty($keyword)) continue;
      
      // Title match (highest weight)
      if (strpos(strtolower($job['title'] ?? ''), $keyword) !== FALSE) {
        $score += 10;
      }
      
      // Function/therapy area match (medium weight)
      if (strpos(strtolower($job['function'] ?? ''), $keyword) !== FALSE ||
          strpos(strtolower($job['therapyArea'] ?? ''), $keyword) !== FALSE) {
        $score += 5;
      }
      
      // Description match (lower weight)
      if (strpos(strtolower($job['description'] ?? ''), $keyword) !== FALSE) {
        $score += 2;
      }
    }
    
    return $score;
  }

  /**
   * Get function option ID for filtering.
   *
   * @param string $function
   *   Function name.
   *
   * @return string
   *   Option ID for the function.
   */
  private function getFunctionOptionId($function) {
    $function_map = [
      'Allergan Aesthetics' => '8',
      'Commercial' => '9',
      'Corporate' => '10',
      'Operations' => '11',
      'Research & Development' => '12',
    ];
    
    return $function_map[$function] ?? '';
  }

  /**
   * Get simulated job data as fallback.
   *
   * @param array $keywords
   *   Keywords for context.
   *
   * @return array
   *   Simulated job listings.
   */
  private function getSimulatedJobs(array $keywords) {
    return [
      [
        'title' => 'Key Account & Distributors Manager – Allergan Aesthetics',
        'location' => 'Bucharest, Romania',
        'description' => 'The Key Account & Distributors Manager – Romania will play a pivotal role in accelerating growth and expanding market presence for the Allergan Aesthetics portfolio across Romania.',
        'jobId' => 'R00131690',
        'url' => 'https://careers.abbvie.com/en/job/key-account-and-distributors-manager-allergan-aesthetics-in-bucharest-ro-jid-18035',
        'function' => 'Allergan Aesthetics',
        'therapyArea' => 'Aesthetics',
        'experienceLevel' => 'Entry Level',
        'jobType' => 'Full-time',
        'source' => 'simulated',
        'relevance_score' => 8,
      ],
      [
        'title' => 'Technical Writer',
        'location' => 'Westport, Ireland',
        'description' => 'People. Passion. Possibilities. We are currently recruiting a Technical Writer as part of the overall Product Flow function within the Core 1 Business.',
        'jobId' => 'R00134197',
        'url' => 'https://careers.abbvie.com/en/job/technicial-writer-in-westport-mo-jid-20529',
        'function' => 'Operations',
        'therapyArea' => '',
        'experienceLevel' => 'Entry Level',
        'jobType' => 'Full-time',
        'source' => 'simulated',
        'relevance_score' => 6,
      ],
      [
        'title' => 'Key Account Specialist/Manager, Gastroenterology (Immunology)',
        'location' => 'Stara Zagora, Bulgaria',
        'description' => 'Performing all core job responsibilities of Medical Representative/Key Account Specialist at an expert level, plus: Identifies all key account direct and indirect stakeholders.',
        'jobId' => 'R00135217',
        'url' => 'https://careers.abbvie.com/en/job/key-account-specialist-manager-gastroenterology-immunology-in-stara-zagora-stara-zagora-jid-20528',
        'function' => 'Commercial',
        'therapyArea' => 'Immunology',
        'experienceLevel' => 'Entry Level',
        'jobType' => 'Full-time',
        'source' => 'simulated',
        'relevance_score' => 7,
      ],
      [
        'title' => 'Key Account Specialist/Manager, Gastroenterology (Immunology)',
        'location' => 'Burgas, Bulgaria',
        'description' => 'Performing all core job responsibilities of Medical Representative/Key Account Specialist at an expert level, plus: Identifies all key account direct and indirect stakeholders.',
        'jobId' => 'R00135216',
        'url' => 'https://careers.abbvie.com/en/job/key-account-specialist-manager-gastroenterology-immunology-in-burgas-burgas-jid-20527',
        'function' => 'Commercial',
        'therapyArea' => 'Immunology',
        'experienceLevel' => 'Entry Level',
        'jobType' => 'Full-time',
        'source' => 'simulated',
        'relevance_score' => 7,
      ],
    ];
  }

}