<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Database\DatabaseException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Controller for company research and intelligence gathering.
 */
class CompanyResearchController extends ControllerBase {
  use JobHunterControllerTrait;

  /**
   * The cache TTL for research data in seconds (30 days).
   */
  const RESEARCH_CACHE_TTL = 2592000;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a CompanyResearchController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(Connection $database, LoggerInterface $logger) {
    $this->database = $database;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('logger.channel.job_hunter')
    );
  }

  /**
   * Main company research page.
   *
   * Displays all companies with their associated statistics including
   * job counts and application counts, plus company research data.
   *
   * @return array
   *   A render array for the company research page.
   */
  public function researchPage() {
    $companies = $this->fetchCompaniesWithStats();
    
    if (empty($companies)) {
      return [
        '#markup' => $this->t('Unable to load company data. Please try again later.'),
      ];
    }

    $research_data = $this->fetchResearchData($companies);
    $company_cards = $this->buildCompanyCards($companies, $research_data);
    
    $content = [
      '#theme' => 'company_research_page',
      '#companies' => $company_cards,
      '#attached' => [
        'library' => [
          'job_hunter/company-research',
        ],
      ],
    ];
    
    return $this->wrapWithNavigation($content);
  }

  /**
   * Fetches companies with job and application statistics.
   *
   * @return array
   *   Array of company objects with statistics.
   */
  protected function fetchCompaniesWithStats() {
    try {
      // Get companies with job and application counts in a single optimized query
      // This eliminates the N+1 query problem by using JOINs and aggregation
      $query = $this->database->select('jobhunter_companies', 'c');
      $query->fields('c', ['id', 'name', 'industry', 'location', 'website', 'description', 'notes', 'active']);
      $query->leftJoin('jobhunter_job_requirements', 'j', 'j.company_id = c.id');
      $query->leftJoin('jobhunter_applications', 'a', 'a.job_id = j.id');
      $query->addExpression('COUNT(DISTINCT j.id)', 'job_count');
      $query->addExpression('COUNT(DISTINCT a.id)', 'app_count');
      $query->groupBy('c.id');
      $query->orderBy('c.name', 'ASC');
      
      return $query->execute()->fetchAll();
    }
    catch (DatabaseException $e) {
      $this->logger->error('Failed to fetch companies: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Fetches research data for companies.
   *
   * @param array $companies
   *   Array of company objects.
   *
   * @return array
   *   Associative array of research data indexed by company name.
   */
  protected function fetchResearchData(array $companies) {
    if (empty($companies)) {
      return [];
    }

    $company_names = array_map(function($company) {
      return $company->name;
    }, $companies);
    
    try {
      // Guard against missing table (schema not yet created).
      if (!\Drupal::database()->schema()->tableExists('company_research_results')) {
        return [];
      }

      $research_results = $this->database->select('company_research_results', 'r')
        ->fields('r', ['id', 'company_name', 'ats_platform', 'automation_readiness', 'created_at', 'research_date'])
        ->condition('company_name', $company_names, 'IN')
        ->orderBy('created_at', 'DESC')
        ->execute()
        ->fetchAll();
      
      // Index by company name (keep most recent)
      $research_data = [];
      foreach ($research_results as $result) {
        if (!isset($research_data[$result->company_name])) {
          $research_data[$result->company_name] = $result;
        }
      }
      
      return $research_data;
    }
    catch (DatabaseException $e) {
      $this->logger->warning('Failed to fetch research data: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Builds company card data for template rendering.
   *
   * @param array $companies
   *   Array of company objects.
   * @param array $research_data
   *   Research data indexed by company name.
   *
   * @return array
   *   Array of formatted company card data.
   */
  protected function buildCompanyCards(array $companies, array $research_data) {
    $company_cards = [];
    $current_time = time();
    
    foreach ($companies as $company) {
      $research = $research_data[$company->name] ?? NULL;
      $research_info = $this->processResearchInfo($research, $current_time);
      
      $company_cards[] = [
        'id' => $company->id,
        'name' => Html::escape($company->name),
        'industry' => $company->industry ? Html::escape($company->industry) : $this->t('Not specified'),
        'location' => $company->location ? Html::escape($company->location) : $this->t('Not specified'),
        'website' => $this->sanitizeWebsiteUrl($company->website),
        'description' => $company->description ? Html::escape($company->description) : NULL,
        'notes' => $company->notes ? Html::escape($company->notes) : NULL,
        'job_count' => $company->job_count,
        'app_count' => $company->app_count,
        'active' => $company->active,
      ] + $research_info;
    }
    
    return $company_cards;
  }

  /**
   * Processes research information for a company.
   *
   * @param object|null $research
   *   The research data object or NULL.
   * @param int $current_time
   *   Current Unix timestamp.
   *
   * @return array
   *   Processed research information.
   */
  protected function processResearchInfo($research, $current_time) {
    if (!$research) {
      return [
        'has_research' => FALSE,
        'research_stale' => FALSE,
        'research_id' => NULL,
        'ats_platform' => NULL,
        'automation_readiness' => NULL,
        'research_date' => NULL,
      ];
    }

    $research_age = $current_time - $research->created_at;
    $research_stale = $research_age > self::RESEARCH_CACHE_TTL;
    
    return [
      'has_research' => TRUE,
      'research_stale' => $research_stale,
      'research_id' => $research->id,
      'ats_platform' => Html::escape($research->ats_platform ?? ''),
      'automation_readiness' => Html::escape($research->automation_readiness ?? ''),
      'research_date' => $research->research_date,
    ];
  }

  /**
   * Sanitizes a website URL for safe output.
   *
   * @param string|null $url
   *   The URL to sanitize.
   *
   * @return string|null
   *   Sanitized URL or NULL.
   */
  protected function sanitizeWebsiteUrl($url) {
    if (!$url) {
      return NULL;
    }
    
    // stripDangerousProtocols removes dangerous protocol schemes (javascript:, data:, etc.)
    // Html::escape provides additional protection for HTML context output
    return Html::escape(UrlHelper::stripDangerousProtocols($url));
  }

}
