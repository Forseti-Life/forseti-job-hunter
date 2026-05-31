<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Service for job discovery data operations.
 * 
 * Handles data fetching and processing for the job discovery page,
 * including user profile data, job listings, and API credentials.
 */
class JobDiscoveryService {

  /**
   * Logger channel name.
   *
   * @var string
   */
  protected const LOGGER_CHANNEL = 'job_hunter';

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Constructs a JobDiscoveryService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $config_factory,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Get default search parameters from user profile.
   *
   * @return array
   *   Array containing default search values:
   *   - keywords: string
   *   - location: string
   *   - remote_pref: string
   *   - salary_min: int
   *   - salary_max: int
   *   - employment_type: string
   *   - relocation: string
   */
  public function getUserSearchDefaults(): array {
    $defaults = [
      'keywords' => '',
      'location' => '',
      'remote_pref' => '',
      'salary_min' => '',
      'salary_max' => '',
      'employment_type' => '',
      'relocation' => '',
      'sources' => ['forseti'],
    ];

    try {
      $profile = $this->database->select('jobhunter_job_seeker', 'js')
        ->fields('js')
        ->condition('uid', $this->currentUser->id())
        ->execute()
        ->fetchObject();

      if ($profile && !empty($profile->consolidated_profile_json)) {
        $consolidated = json_decode($profile->consolidated_profile_json, TRUE) ?: [];

        // Don't pre-populate keywords/job titles - let users enter their own
        // $titles = $consolidated['job_search_preferences']['target_titles'] ?? '';
        // $keywords = $consolidated['job_search_preferences']['keywords'] ?? '';
        // $titles_array = is_array($titles) ? $titles : ($titles ? explode("\n", $titles) : []);
        // $keywords_array = is_array($keywords) ? $keywords : ($keywords ? explode("\n", $keywords) : []);
        // $combined = array_filter(array_merge($titles_array, $keywords_array));
        // if (!empty($combined)) {
        //   $defaults['keywords'] = implode(', ', array_slice($combined, 0, 3));
        // }

        // Extract location from contact info.
        if (isset($consolidated['contact_info']['location'])) {
          $location_parts = [];
          if (!empty($consolidated['contact_info']['location']['city'])) {
            $location_parts[] = $consolidated['contact_info']['location']['city'];
          }
          if (!empty($consolidated['contact_info']['location']['state'])) {
            $location_parts[] = $consolidated['contact_info']['location']['state'];
          }
          if (!empty($location_parts)) {
            $defaults['location'] = implode(', ', $location_parts);
          }
        }

        // Get remote preference.
        $defaults['remote_pref'] = $consolidated['job_search_preferences']['remote_preference'] ?? '';
        if ($defaults['remote_pref'] === 'remote' && empty($defaults['location'])) {
          $defaults['location'] = 'Remote';
        }

        // Get salary expectations.
        $salary_min = $consolidated['job_search_preferences']['salary_expectation_min'] ?? '';
        $salary_max = $consolidated['job_search_preferences']['salary_expectation_max'] ?? '';
        if ($salary_min && is_numeric($salary_min)) {
          $defaults['salary_min'] = (int) $salary_min;
        }
        if ($salary_max && is_numeric($salary_max)) {
          $defaults['salary_max'] = (int) $salary_max;
        }

        // Get relocation preference.
        $defaults['relocation'] = $consolidated['job_search_preferences']['relocation_willing'] ?? '';
      }
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Error loading profile for search: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    $source_preferences = $this->getSourcePreferences((int) $this->currentUser->id());
    if (!empty($source_preferences['sources_enabled']) || $source_preferences['has_row']) {
      $defaults['sources'] = $source_preferences['sources_enabled'];
    }
    if ($source_preferences['min_salary'] !== NULL) {
      $defaults['salary_min'] = $source_preferences['min_salary'];
    }
    if ($source_preferences['remote_preference'] !== '') {
      $defaults['remote_pref'] = $source_preferences['remote_preference'];
    }

    return $defaults;
  }

  /**
   * Check which external API credentials are configured.
   *
   * @return array
   *   Array with boolean keys:
   *   - google_cloud: boolean
   *   - adzuna: boolean
   *   - usajobs: boolean
   *   - serpapi: boolean
   */
  public function getApiCredentialsStatus(): array {
    $status = [
      'google_cloud' => FALSE,
      'adzuna' => FALSE,
      'usajobs' => FALSE,
      'serpapi' => FALSE,
    ];

    try {
      $config = $this->configFactory->get('job_hunter.settings');

      // Check Google Cloud credentials.
      $google_credentials = $config->get('google_cloud_credentials');
      if (!empty($google_credentials)) {
        $status['google_cloud'] = TRUE;
      }

      // Check Adzuna credentials.
      $adzuna_app_id = $config->get('adzuna_app_id');
      $adzuna_app_key = $config->get('adzuna_app_key');
      if (!empty($adzuna_app_id) && !empty($adzuna_app_key)) {
        $status['adzuna'] = TRUE;
      }

      // Check USAJobs credentials.
      $usajobs_api_key = $config->get('usajobs_api_key');
      $usajobs_email = $config->get('usajobs_email');
      if (!empty($usajobs_api_key) && !empty($usajobs_email)) {
        $status['usajobs'] = TRUE;
      }

      // Check SerpAPI credentials.
      $serpapi_api_key = $config->get('serpapi_api_key');
      if (!empty($serpapi_api_key)) {
        $status['serpapi'] = TRUE;
      }
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Error checking credentials: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $status;
  }

  /**
   * Get count of saved jobs for current user.
   *
   * @return int
   *   Number of saved jobs.
   */
  public function getSavedJobsCount(): int {
    try {
      return (int) $this->database->select('jobhunter_saved_jobs', 'sj')
        ->condition('sj.uid', $this->currentUser->id())
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Error counting saved jobs: @error', [
        '@error' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Get count of target companies for current user.
   *
   * @return int
   *   Number of target companies.
   */
  public function getTargetCompaniesCount(): int {
    try {
      return (int) $this->database->select('jobhunter_companies', 'c')
        ->condition('uid', $this->currentUser->id())
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Error counting target companies: @error', [
        '@error' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Get saved jobs with optional filters.
   *
   * @param array $filters
   *   Array of filter criteria:
   *   - company: string, company name filter
   *   - status: string, job status filter
   *   - ai_status: string, AI extraction status filter
   *   - tailoring: string, tailoring status filter
   *
   * @return array
   *   Array of job objects with company and tailoring information.
   */
  public function getSavedJobs(array $filters = [], int $page = 0, int $per_page = 50): array {
    try {
      $company_name_field = $this->getCompanyNameField();

      $query = $this->database->select('jobhunter_saved_jobs', 'sj');
      $query->innerJoin('jobhunter_job_requirements', 'j', 'sj.job_id = j.id');
      $query->fields('j')
        ->condition('sj.uid', $this->currentUser->id());

      // Include per-saved-job fields needed for follow-up and deadline display.
      $query->addField('sj', 'follow_up_date', 'follow_up_date');
      $query->addField('sj', 'deadline_date', 'sj_deadline_date');

      $query->leftJoin('jobhunter_companies', 'c', 'j.company_id = c.id');
      $query->addField('c', $company_name_field, 'company_name');
      
      // Join tailored resumes for current user.
      $query->leftJoin('jobhunter_tailored_resumes', 'tr', 'j.id = tr.job_id AND tr.uid = :uid', [
        ':uid' => $this->currentUser->id(),
      ]);
      $query->addField('tr', 'tailoring_status');
      $query->addField('tr', 'tailored_resume_json');
      $query->addField('tr', 'pdf_path');
      $query->addField('tr', 'pdf_generated');

      // Join application records for current user.
      $query->leftJoin('jobhunter_applications', 'app', 'j.id = app.job_id AND app.uid = :app_uid', [
        ':app_uid' => $this->currentUser->id(),
      ]);
      $query->addField('app', 'submission_status', 'application_status');
      if ($this->database->schema()->fieldExists('jobhunter_applications', 'ats_platform')) {
        $query->addField('app', 'ats_platform', 'application_ats');
      }
      else {
        $query->addExpression("''", 'application_ats');
      }

      // Apply filters.
      if (!empty($filters['company'])) {
        $query->condition('c.' . $company_name_field, '%' . $this->database->escapeLike($filters['company']) . '%', 'LIKE');
      }
      if (!empty($filters['status'])) {
        $query->condition('j.status', $filters['status']);
      }
      else {
        $query->condition('sj.archived', 0);
      }
      if (!empty($filters['platform'])) {
        // Filter by source_platform or via field.
        $platform_group = $query->orConditionGroup()
          ->condition('j.source_platform', '%' . $this->database->escapeLike($filters['platform']) . '%', 'LIKE')
          ->condition('j.via', '%' . $this->database->escapeLike($filters['platform']) . '%', 'LIKE');
        $query->condition($platform_group);
      }

      $query->orderBy('c.' . $company_name_field, 'ASC');
      $query->orderBy('j.job_title', 'ASC');

      // Paginate.
      $query->range($page * $per_page, $per_page);

      $results = $query->execute()->fetchAll();
      
      // Decode JSON fields for template use.
      foreach ($results as $job) {
        if (!empty($job->extracted_json)) {
          $job->extracted_data = json_decode($job->extracted_json, TRUE);
        }
        if (!empty($job->tailored_resume_json)) {
          $job->tailored_data = json_decode($job->tailored_resume_json, TRUE);
        }
      }
      
      return $results;
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Error fetching saved jobs: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Count saved (non-archived) jobs for the current user, respecting filters.
   */
  public function getSavedJobsFiltered(array $filters = []): int {
    try {
      $company_name_field = $this->getCompanyNameField();
      $query = $this->database->select('jobhunter_saved_jobs', 'sj');
      $query->innerJoin('jobhunter_job_requirements', 'j', 'sj.job_id = j.id');
      $query->leftJoin('jobhunter_companies', 'c', 'j.company_id = c.id');
      $query->leftJoin('jobhunter_tailored_resumes', 'tr', 'j.id = tr.job_id AND tr.uid = :uid', [
        ':uid' => $this->currentUser->id(),
      ]);
      $query->condition('sj.uid', $this->currentUser->id());
      if (!empty($filters['company'])) {
        $query->condition('c.' . $company_name_field, '%' . $this->database->escapeLike($filters['company']) . '%', 'LIKE');
      }
      if (!empty($filters['status'])) {
        $query->condition('j.status', $filters['status']);
      }
      else {
        $query->condition('sj.archived', 0);
      }
      if (!empty($filters['platform'])) {
        $platform_group = $query->orConditionGroup()
          ->condition('j.source_platform', '%' . $this->database->escapeLike($filters['platform']) . '%', 'LIKE')
          ->condition('j.via', '%' . $this->database->escapeLike($filters['platform']) . '%', 'LIKE');
        $query->condition($platform_group);
      }
      $query->addExpression('COUNT(*)', 'total');
      return (int) $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Get archived jobs for the current user with pagination.
   */
  public function getArchivedJobs(int $page = 0, int $per_page = 50): array {
    try {
      $company_name_field = $this->getCompanyNameField();
      $query = $this->database->select('jobhunter_saved_jobs', 'sj');
      $query->innerJoin('jobhunter_job_requirements', 'j', 'sj.job_id = j.id');
      $query->fields('j')->condition('sj.uid', $this->currentUser->id());
      $query->leftJoin('jobhunter_companies', 'c', 'j.company_id = c.id');
      $query->addField('c', $company_name_field, 'company_name');
      $query->leftJoin('jobhunter_tailored_resumes', 'tr', 'j.id = tr.job_id AND tr.uid = :uid', [
        ':uid' => $this->currentUser->id(),
      ]);
      $query->addField('tr', 'tailoring_status');
      $query->condition('sj.archived', 1);
      $query->orderBy('c.' . $company_name_field, 'ASC');
      $query->orderBy('j.job_title', 'ASC');
      $query->range($page * $per_page, $per_page);
      return $query->execute()->fetchAll();
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Error fetching archived jobs: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Count archived jobs for the current user.
   */
  public function getArchivedJobsCount(): int {
    try {
      $query = $this->database->select('jobhunter_saved_jobs', 'sj');
      $query->innerJoin('jobhunter_job_requirements', 'j', 'sj.job_id = j.id');
      $query->condition('sj.uid', $this->currentUser->id());
      $query->condition('sj.archived', 1);
      $query->addExpression('COUNT(*)', 'total');
      return (int) $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Get list of all company names for filter dropdown.
   *
   * @return array
   *   Array of company names.
   */
  public function getCompanyNames(): array {
    try {
      $company_name_field = $this->getCompanyNameField();

      $query = $this->database->select('jobhunter_saved_jobs', 'sj');
      $query->innerJoin('jobhunter_job_requirements', 'j', 'sj.job_id = j.id');
      $query->innerJoin('jobhunter_companies', 'c', 'j.company_id = c.id');
      $query->addField('c', $company_name_field, 'company_name');
      $query->condition('sj.uid', $this->currentUser->id());
      $query->distinct();
      $query->orderBy('company_name', 'ASC');

      return $query->execute()->fetchCol();
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Error fetching company names: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get distinct source platforms for the filter dropdown.
   *
   * Combines source_platform and via fields into a unified list.
   *
   * @return array
   *   Array of platform names.
   */
  public function getSourcePlatforms(): array {
    try {
      // Keyed by lowercase-normalised slug → display name.
      // via values (friendly names) take priority over source_platform (domains).
      $platforms = [];

      // Collect from via field first (user-friendly names).
      $via_query = $this->database->select('jobhunter_saved_jobs', 'sj');
      $via_query->innerJoin('jobhunter_job_requirements', 'j', 'sj.job_id = j.id');
      $via_query->addField('j', 'via');
      $via_query->condition('sj.uid', $this->currentUser->id());
      $via_query->condition('j.via', '', '!=');
      $via_query->isNotNull('j.via');
      $via_query->distinct();
      $via_results = $via_query->execute()->fetchCol();
      foreach ($via_results as $v) {
        $display = trim($v);
        $slug = $this->normalizePlatformSlug($display);
        // Friendly names always win.
        $platforms[$slug] = $display;
      }

      // Collect from source_platform field — only add if no friendly name already.
      $sp_query = $this->database->select('jobhunter_saved_jobs', 'sj');
      $sp_query->innerJoin('jobhunter_job_requirements', 'j', 'sj.job_id = j.id');
      $sp_query->addField('j', 'source_platform');
      $sp_query->condition('sj.uid', $this->currentUser->id());
      $sp_query->condition('j.source_platform', '', '!=');
      $sp_query->isNotNull('j.source_platform');
      $sp_query->distinct();
      $sp_results = $sp_query->execute()->fetchCol();
      foreach ($sp_results as $sp) {
        $display = trim($sp);
        $slug = $this->normalizePlatformSlug($display);
        if (!isset($platforms[$slug])) {
          $platforms[$slug] = $display;
        }
      }

      $values = array_values($platforms);
      sort($values, SORT_STRING | SORT_FLAG_CASE);
      return $values;
    }
    catch (\Exception $e) {
      $this->getLogger()->error('Error fetching source platforms: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Normalise a platform name to a lowercase slug for deduplication.
   *
   * Strips common TLD suffixes (.com, .org, etc.), leading "www.", and
   * lowercases so that e.g. "ZipRecruiter" and "ziprecruiter.com" collapse
   * into the same bucket.
   *
   * @param string $name
   *   Raw platform name (e.g. "ziprecruiter.com", "ZipRecruiter").
   *
   * @return string
   *   Normalised slug (e.g. "ziprecruiter").
   */
  private function normalizePlatformSlug(string $name): string {
    $slug = strtolower(trim($name));
    // Strip leading www.
    $slug = preg_replace('/^www\./', '', $slug);
    // Strip trailing TLD.
    $slug = preg_replace('/\.(com|org|net|io|co|jobs|app)$/', '', $slug);
    // Remove any remaining non-alphanumeric chars (hyphens, dots, underscores).
    $slug = preg_replace('/[^a-z0-9]/', '', $slug);
    return $slug;
  }

  /**
   * Resolve company display field from schema.
   *
   * @return string
   *   Company name field key.
   */
  protected function getCompanyNameField(): string {
    return $this->database->schema()->fieldExists('jobhunter_companies', 'name')
      ? 'name'
      : 'company_name';
  }

  /**
   * Get logger channel instance.
   *
   * @return \Psr\Log\LoggerInterface
   *   Logger instance.
   */
  protected function getLogger() {
    return $this->loggerFactory->get(self::LOGGER_CHANNEL);
  }

  /**
   * Returns the source preferences for a given user.
   *
   * AC-3: callers (job discovery dispatchers) should call this before invoking
   * source-specific adapters and skip any adapter whose key is not in
   * `sources_enabled`. When no preferences row exists all sources are enabled.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array{
   *   has_row: bool,
   *   sources_enabled: string[],
   *   min_salary: int|null,
   *   remote_preference: string,
   *   location_radius_km: int|null
   * }
   */
  public function getSourcePreferences(int $uid): array {
    try {
      $row = $this->database->select('jobhunter_source_preferences', 'sp')
        ->fields('sp', ['sources_enabled', 'min_salary', 'remote_preference', 'location_radius_km'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchObject();
    }
    catch (\Exception $e) {
      $this->getLogger()->error('getSourcePreferences failed: uid=@uid error=@error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      $row = NULL;
    }

    if (!$row) {
      // No preferences saved — preserve the live discovery default.
      return [
        'has_row'            => FALSE,
        'sources_enabled'    => ['forseti'],
        'min_salary'         => NULL,
        'remote_preference'  => '',
        'location_radius_km' => NULL,
      ];
    }

    $sources = [];
    $decoded = [];
    if (!empty($row->sources_enabled)) {
      $decoded = json_decode($row->sources_enabled, TRUE);
      if (is_array($decoded)) {
        foreach ($decoded as $source) {
          $source = strtolower(trim((string) $source));
          if (in_array($source, ['forseti', 'serpapi', 'adzuna', 'usajobs'], TRUE) && !in_array($source, $sources, TRUE)) {
            $sources[] = $source;
          }
        }
      }
    }
    if (!empty($decoded) && empty($sources)) {
      $sources = ['forseti'];
    }

    $remote_preference = (string) ($row->remote_preference ?? 'any');
    if ($remote_preference === 'remote_only') {
      $remote_preference = 'remote';
    }
    elseif (!in_array($remote_preference, ['remote', 'hybrid', 'onsite'], TRUE)) {
      $remote_preference = '';
    }

    return [
      'has_row'            => TRUE,
      'sources_enabled'    => $sources,
      'min_salary'         => $row->min_salary !== NULL ? (int) $row->min_salary : NULL,
      'remote_preference'  => $remote_preference,
      'location_radius_km' => $row->location_radius_km !== NULL ? (int) $row->location_radius_km : NULL,
    ];
  }

}
