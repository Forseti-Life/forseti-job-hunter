<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\job_hunter\Repository\JobApplicationRepository;
use Drupal\Core\Link;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\job_hunter\Service\JobDiscoveryService;
use Drupal\job_hunter\Service\SearchAggregatorService;
use Drupal\job_hunter\Service\UserProfileService;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Page-render controllers for Job Hunter (listings, wizard pages, dashboard views).
 */
class ApplicationSubmissionController extends ControllerBase {
  use JobHunterControllerTrait;
  use ApplicationControllerHelperTrait;

  protected JobDiscoveryService $jobDiscoveryService;
  protected RequestStack $requestStack;
  protected JobApplicationRepository $repository;
  protected QueueFactory $queueFactory;
  protected SearchAggregatorService $searchAggregator;
  protected UserProfileService $userProfileService;
  protected CsrfTokenGenerator $csrfTokenGenerator;

  public function __construct(
    JobDiscoveryService $job_discovery_service,
    RequestStack $request_stack,
    JobApplicationRepository $repository,
    QueueFactory $queue_factory,
    SearchAggregatorService $search_aggregator,
    EntityTypeManagerInterface $entity_type_manager,
    UserProfileService $user_profile_service,
    CsrfTokenGenerator $csrf_token_generator
  ) {
    $this->jobDiscoveryService = $job_discovery_service;
    $this->requestStack = $request_stack;
    $this->repository = $repository;
    $this->queueFactory = $queue_factory;
    $this->searchAggregator = $search_aggregator;
    $this->entityTypeManager = $entity_type_manager;
    $this->userProfileService = $user_profile_service;
    $this->csrfTokenGenerator = $csrf_token_generator;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('job_hunter.job_discovery_service'),
      $container->get('request_stack'),
      $container->get('job_hunter.job_application_repository'),
      $container->get('queue'),
      $container->get('job_hunter.search_aggregator'),
      $container->get('entity_type.manager'),
      $container->get('job_hunter.user_profile_service'),
      $container->get('csrf_token')
    );
  }

  public function home() {
    return [
      '#markup' => '<h1>Hello World!</h1><p>Job Application Automation Module is working for regular users!</p><p>Welcome, you are logged in successfully!</p>',
    ];
  }

  /**
   * Redirect /jobhunter/jobs to /jobhunter/job-discovery.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the job discovery page.
   */
  public function dashboard() {
    $current_user = $this->currentUser();
    
    // If user is not authenticated, redirect to registration with message.
    if ($current_user->isAnonymous()) {
      $this->messenger()->addWarning($this->t('Job Hunter is reserved for community members. Please register for a free account to get started.'));
      $url = Url::fromRoute('user.register');
      return new RedirectResponse($url->toString());
    }
    
    // Build dashboard content directly
    $content = [];
    $content['#attached']['library'][] = 'job_hunter/job-hunter-home';
    
    // Dashboard Header
    $content['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['job-application-hero']],
      '#value' => '<h1>Job Application Dashboard</h1>
                   <div class="subtitle">Your Complete Job Search Management System</div>',
    ];
    
    // Check if user is authenticated
    if ($current_user->isAuthenticated() && $current_user->id() > 0) {
      $content = $this->buildAuthenticatedView($content, $current_user);
    } else {
      $content = $this->buildUnauthenticatedView($content);
    }
    
    return $this->wrapWithNavigation($content, ['job_hunter/job-hunter-home']);
  }

  /**
   * View a specific job application.
   *
   * @param mixed $job_application
   *   The job application entity.
   *
   * @return array
   *   A renderable array for the job application view.
   */
  public function view($job_application) {
    $content = [
      '#markup' => '<h2>Job Application View</h2><p>Details for job application ID: ' . $job_application . '</p>',
    ];
    
    return $this->wrapWithNavigation($content, ['job_hunter/job-application-dashboard']);
  }

  /**
   * Build the view for unauthenticated users.
   */
  private function buildUnauthenticatedView($build) {
    $build['auth_required'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['process-phase', 'phase-1', 'authentication-required']],
      '#value' => '<h3>Please Sign In</h3>
                   <p>Sign in or create an account to access your job application dashboard.</p>
                   <a href="/user/login" class="auth-button primary">Sign In</a>
                   <a href="/user/register" class="auth-button secondary">Create Account</a>',
    ];
    
    $build['#prefix'] = '<div class="job-application-home unauthenticated">';
    $build['#suffix'] = '</div>';
    
    return $build;
  }

  /**
   * Build the view for authenticated users.
   */
  private function buildAuthenticatedView($build, $current_user) {
    $user_name = $current_user->getDisplayName();
    $submission_summary = $this->getApplicationSubmissionSummary((int) $current_user->id());

    // Calculate stats using ProfileCompletenessService for consistency.
    /** @var \Drupal\job_hunter\Service\ProfileCompletenessService $completeness_service */
    $completeness_service = \Drupal::service('job_hunter.profile_completeness_service');
    $uid = (int) $current_user->id();
    $profile_completion = $completeness_service->calculate($uid);
    $missing_fields = $completeness_service->getMissingFields($uid);

    $target_companies = $this->getTargetCompaniesCount($current_user);
    $saved_jobs = $this->getSavedJobsCount($current_user);
    
    // URLs
    $user_edit_url = Url::fromRoute('job_hunter.user_profile_edit');
    $job_discovery_url = Url::fromRoute('job_hunter.job_discovery');
    
    // Welcome message
    $build['welcome'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['user-welcome']],
      '#value' => '<div class="user-welcome">Welcome back, ' . $user_name . '!</div>',
    ];

    // Profile completeness widget — only shown when profile is not 100% complete.
    if ($profile_completion < 100) {
      $build['completeness_widget'] = [
        '#theme' => 'profile_completeness',
        '#completeness' => $profile_completion,
        '#is_complete' => FALSE,
        '#validation_errors' => [],
        '#missing_fields' => $missing_fields,
      ];
    }
    
    // ========================================
    // Job Application Workflow
    // ========================================
    $build['flow_header'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['flow-header', 'flow-automated']],
      '#value' => '<h2>🚀 Job Application Workflow</h2>
                   <p class="flow-description">Streamlined process from profile setup to application tracking.</p>',
    ];
    
    // Step 1: Complete Profile
    $build['step1'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['phase-section', 'phase-profile']],
      'content' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['phase-content']],
        '#value' => '<div class="step-indicator">Step 1</div>
                     <div class="phase-info">
                       <h3>Complete Profile</h3>
                       <p>Finish your job seeker profile so discovery and tailoring are more accurate.</p>
                     </div>
                     <div class="phase-stat">
                       <div class="stat-number">' . (int) $profile_completion . '%</div>
                       <div class="stat-label">Profile</div>
                     </div>
                     <div class="phase-actions">
                       <a href="' . $user_edit_url->toString() . '" class="phase-button">My Profile</a>
                     </div>',
      ],
    ];
    
    // Step 2: Job Discovery
    $build['step2'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['phase-section', 'phase-discovery']],
      'content' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['phase-content']],
        '#value' => '<div class="step-indicator">Step 2</div>
                     <div class="phase-info">
                       <h3>Job Discovery</h3>
                       <p>Search and save jobs that match your profile and preferences.</p>
                     </div>
                     <div class="phase-stat">
                       <div class="stat-number">' . (int) $saved_jobs . '</div>
                       <div class="stat-label">Saved Jobs</div>
                     </div>
                     <div class="phase-actions">
                       <a href="' . $job_discovery_url->toString() . '" class="phase-button">Job Discovery</a>
                     </div>',
      ],
    ];
    
    // Step 3: Application Submission
    $application_submission_url = Url::fromRoute('job_hunter.application_submission');
    $build['step3'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['phase-section', 'phase-submission']],
      'content' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['phase-content']],
        '#value' => '<div class="step-indicator">Step 3</div>
                     <div class="phase-info">
                       <h3>Application Submission</h3>
                       <p>Auto-apply to jobs with tailored resumes and cover letters.</p>
                      </div>
                      <div class="phase-stat">
                        <div class="stat-number">' . (int) $submission_summary['submitted'] . '</div>
                        <div class="stat-label">Auto-Applied</div>
                      </div>
                      <div class="phase-actions">
                        <a href="' . $application_submission_url->toString() . '" class="phase-button">View Submissions</a>
                      </div>',
      ],
    ];

    // Step 4: Interview & Follow-up
    $build['step4'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['phase-section', 'phase-interview', 'disabled']],
      'content' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['phase-content']],
        '#value' => '<div class="step-indicator">Step 4</div>
                     <div class="phase-info">
                       <h3>Interview & Follow-up</h3>
                       <p>Track application status, schedule interviews, and manage follow-ups.</p>
                     </div>
                     <div class="phase-stat">
                       <div class="stat-number">0</div>
                       <div class="stat-label">Interviews</div>
                     </div>
                     <div class="phase-actions">
                       <a href="/jobhunter/interview-followup" class="phase-button">Manage Pipeline</a>
                     </div>
                     <div class="coming-soon-badge">Coming Soon</div>',
      ],
    ];

    // Step 5: Analytics & Optimization
    $build['step5'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['phase-section', 'phase-analytics', 'disabled']],
      'content' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['phase-content']],
        '#value' => '<div class="step-indicator">Step 5</div>
                     <div class="phase-info">
                       <h3>Analytics & Optimization</h3>
                       <p>Measure success rates, identify patterns, and optimize your strategy.</p>
                     </div>
                     <div class="phase-stat">
                       <div class="stat-number">--</div>
                       <div class="stat-label">Success Rate</div>
                     </div>
                     <div class="phase-actions">
                       <a href="/jobhunter/analytics" class="phase-button">View Analytics</a>
                     </div>
                     <div class="coming-soon-badge">Coming Soon</div>',
      ],
    ];
    
    // Attach CSS library instead of inline styles.
    $build['#attached']['library'][] = 'job_hunter/job-application-dashboard';
    
    $build['#prefix'] = '<div class="job-dashboard">';
    $build['#suffix'] = '</div>';
    
    return $build;
  }

  /**
   * Calculate user profile completeness percentage.
   *
   * Uses the UserProfileService to calculate how complete a user's
   * job seeker profile is based on filled fields.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The current user account.
   *
   * @return int
   *   The profile completeness percentage (0-100).
   */
  private function calculateProfileCompletion(AccountInterface $user) {
    // Use the injected UserProfileService for real calculation.
    $user_entity = User::load($user->id());
    if ($user_entity) {
      return $this->userProfileService->calculateProfileCompleteness($user_entity);
    }
    return 0;
  }

  /**
   * Get count of target companies.
   *
   * Counts the number of active company nodes in the system.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The current user account. Kept for future use when implementing
   *   user-specific company filtering. Currently unused.
   *
   * @return int
   *   The number of active companies.
   *
   * @todo Implement user-specific company filtering in the query.
   */
  private function getTargetCompaniesCount(AccountInterface $user) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'company')
      ->condition('status', 1)
      ->accessCheck(TRUE);
    return count($query->execute());
  }

  /**
   * Get count of matched jobs.
   *
   * Counts the number of active job posting nodes in the system.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The current user account. Kept for future use when implementing
   *   user-specific job matching. Currently unused.
   *
   * @return int
   *   The number of active job postings.
   *
   * @todo Implement user-specific job matching in the query.
   */
  private function getMatchedJobsCount(AccountInterface $user) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'job_posting')
      ->condition('status', 1)
      ->accessCheck(TRUE);
    return count($query->execute());
  }

  /**
   * Get count of active applications.
   *
   * Placeholder method for counting active job applications.
   * Currently returns 0.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The current user account.
   *
   * @return int
   *   The number of active applications (currently always 0).
   */
  private function getActiveApplicationsCount(AccountInterface $user) {
    return 0; // Placeholder
  }

  /**
   * Get count of saved job postings.
   *
   * Counts the total number of job requirements in the database.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The current user account. Kept for future use when implementing
   *   user-specific saved jobs. Currently unused.
   *
   * @return int
   *   The number of saved job postings.
   *
   * @todo Implement user-specific saved jobs filtering in the query.
   */
  private function getSavedJobsCount(AccountInterface $user) {
    return $this->repository->countJobRequirements();
  }

  /**
   * Manage target companies page.
   *
   * Displays a list of target companies for job hunting, with statistics
   * about each company including job counts and activity status.
   *
   * @return array
   *   A renderable array for the target companies management page.
   */
  public function manageTargetCompanies() {
    $companies = $this->repository->getAllCompanies();
    $job_results = $this->repository->getActiveJobCountsByCompany();
    
    // Calculate statistics.
    $total_companies = count($companies);
    $active_companies = count(array_filter($companies, fn($c) => $c->active == 1));
    $total_jobs = array_sum($job_results);
    
    // Prepare target companies data for template.
    $target_companies_data = [];
    foreach ($companies as $company) {
      $target_companies_data[] = [
        'id' => $company->id,
        'name' => $company->name,
        'location' => $company->location,
        'industry' => $company->industry,
        'website' => $company->website,
        'careers_page_url' => $company->careers_page_url,
        'active' => $company->active,
        'job_count' => $job_results[$company->id] ?? 0,
      ];
    }
    
    // Get companies from job postings (extracted via AI).
    $job_companies = $this->getCompaniesFromJobPostings();
    
    // Get list of existing company names for template comparison.
    $existing_companies = array_column($companies, 'name');
    
    $content = [
      '#theme' => 'target_companies',
      '#total_companies' => $total_companies,
      '#active_companies' => $active_companies,
      '#total_jobs' => $total_jobs,
      '#target_companies' => $target_companies_data,
      '#job_companies' => $job_companies,
      '#existing_companies' => $existing_companies,
      '#attached' => [
        'library' => [
          'job_hunter/target-companies',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['job_hunter:companies', 'job_hunter:jobs'],
      ],
    ];
    
    return $this->wrapWithNavigation($content);
  }

  /**
   * Save target companies.
   *
   * Handles the saving of target company selections.
   * Currently redirects to job applications page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the job applications page.
   */
  public function companiesOverview() {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'company')
      ->condition('status', 1)
      ->accessCheck(TRUE);
    $company_ids = $query->execute();
    $company_count = count($company_ids);

    $build = [];
    $build['header'] = [
      '#markup' => '<h2>Companies Overview</h2><p>Total companies: ' . $company_count . '</p>',
    ];

    if ($company_count > 0) {
      // Load companies and build table.
      $companies = $this->entityTypeManager->getStorage('node')->loadMultiple($company_ids);
      
      // Table header
      $table_header = '<table class="companies-table">
        <thead>
          <tr>
            <th>Company</th>
            <th>Industry</th>
            <th>Size</th>
            <th>Profile Complete</th>
            <th>Jobs Found</th>
            <th>Applications</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>';
      
      $table_rows = '';
      foreach ($companies as $company) {
        $company_name = $company->getTitle();
        $company_url = $company->hasField('field_company_website') && !$company->get('field_company_website')->isEmpty() 
          ? $company->get('field_company_website')->value : '#';
        $company_industry = $company->hasField('field_company_industry') && !$company->get('field_company_industry')->isEmpty()
          ? $company->get('field_company_industry')->value : 'Not specified';
        $company_size = $company->hasField('field_company_size') && !$company->get('field_company_size')->isEmpty()
          ? $company->get('field_company_size')->value : 'Not specified';
        
        // Calculate completion percentage (mock data for now)
        $completion_fields = 0;
        $total_fields = 5; // Name, industry, size, website, description
        
        if (!empty($company_name)) $completion_fields++;
        if ($company->hasField('field_company_industry') && !$company->get('field_company_industry')->isEmpty()) $completion_fields++;
        if ($company->hasField('field_company_size') && !$company->get('field_company_size')->isEmpty()) $completion_fields++;
        if ($company->hasField('field_company_website') && !$company->get('field_company_website')->isEmpty()) $completion_fields++;
        if ($company->hasField('field_company_description') && !$company->get('field_company_description')->isEmpty()) $completion_fields++;
        
        $completion_percentage = round(($completion_fields / $total_fields) * 100);
        
        // Mock job and application counts (replace with real queries later)
        $jobs_found = rand(0, 15);
        $applications_count = rand(0, 5);
        $status = $completion_percentage >= 80 ? 'Active' : 'Incomplete';
        
        $table_rows .= '<tr>
          <td><a href="/node/' . $company->id() . '">' . $company_name . '</a></td>
          <td>' . $company_industry . '</td>
          <td>' . $company_size . '</td>
          <td>
            <div class="progress-bar">
              <div class="progress-fill" data-width="' . $completion_percentage . '%"></div>
              <span class="progress-text">' . $completion_percentage . '%</span>
            </div>
          </td>
          <td>' . $jobs_found . '</td>
          <td>' . $applications_count . '</td>
          <td><span class="status-badge status-' . strtolower($status) . '">' . $status . '</span></td>
          <td>
            <a href="/node/' . $company->id() . '/edit" class="btn btn-sm">Edit</a>
            <a href="/node/' . $company->id() . '" class="btn btn-sm">View</a>
          </td>
        </tr>';
      }
      
      $table_footer = '</tbody></table>';
      
      $build['companies'] = [
        '#markup' => $table_header . $table_rows . $table_footer,
      ];
      
      // Attach companies table CSS library
      $build['#attached']['library'][] = 'job_hunter/companies_table';
    } else {
      $build['no_companies'] = [
        '#markup' => '<div class="no-companies">
          <p>No companies found. <a href="/job-applications/bulk-import-companies">Add companies via bulk import</a> or <a href="/node/add/company">add a single company</a>.</p>
        </div>',
      ];
    }

    return $this->wrapWithNavigation($build);
  }

  /**
   * Get the title for a job application.
   *
   * @param mixed $job_application
   *   The job application entity.
   *
   * @return string
   *   The title for the job application.
   */
  public function getTitle($job_application) {
    return 'Job Application #' . $job_application;
  }

  /**
   * Extract unique company names from job postings.
   * 
   * @return array
   *   Array of company names with job counts [company_name => count].
   */
  private function getCompaniesFromJobPostings() {
    $jobs = $this->repository->getActiveJobsForCompanyExtraction();
    
    $companies = [];
    
    foreach ($jobs as $job) {
      $company_name = null;
      
      // First, try to get company from company_id
      if ($job->company_id) {
        $company = $this->repository->getCompanyName((int) $job->company_id);
        if ($company) {
          $company_name = $company;
        }
      }
      
      // If no company_id or not found, try to extract from JSON
      if (!$company_name && $job->extracted_json) {
        $extracted = json_decode($job->extracted_json, TRUE);
        if (isset($extracted["company_name"]) && !empty($extracted["company_name"])) {
          $company_name = $extracted["company_name"];
        } elseif (isset($extracted["company"]) && !empty($extracted["company"])) {
          $company_name = $extracted["company"];
        }
      }
      
      // Count this company
      if ($company_name) {
        if (!isset($companies[$company_name])) {
          $companies[$company_name] = 0;
        }
        $companies[$company_name]++;
      }
    }
    
    // Sort by job count descending, then alphabetically
    arsort($companies);
    
    return $companies;
  }

  /**
   * Step 2: Job Discovery & Search page.
   *
   * @return array
   *   A renderable array for the job discovery page.
   */
  /**
   * Job Discovery page with unified search and saved jobs management.
   *
   * @return array
   *   A renderable array for the job discovery page.
   */
  public function jobDiscovery(): array {
    // Get search defaults from user profile.
    $defaults = $this->jobDiscoveryService->getUserSearchDefaults();
    
    // Check API credentials status.
    $api_status = $this->jobDiscoveryService->getApiCredentialsStatus();
    
    // Render the template with all necessary variables.
    $content = [
      '#theme' => 'job_discovery_page',
      '#default_keywords' => $defaults['keywords'],
      '#default_location' => $defaults['location'],
      '#default_remote_pref' => $defaults['remote_pref'],
      '#default_salary_min' => $defaults['salary_min'],
      '#default_salary_max' => $defaults['salary_max'],
      '#default_employment_type' => $defaults['employment_type'],
      '#default_relocation' => $defaults['relocation'],
      '#default_sources' => $defaults['sources'],
      '#has_google_cloud' => $api_status['google_cloud'],
      '#has_adzuna' => $api_status['adzuna'],
      '#has_usajobs' => $api_status['usajobs'],
      '#has_serpapi' => $api_status['serpapi'],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['job_hunter:settings'],
      ],
    ];
    
    return $this->wrapWithNavigation($content);
  }

  /**
   * Save a searched job into My Jobs from legacy addposting URL.
   *
    * Expected query parameter:
    * - job_id: Search result token (e.g. forseti_{id}, staging_{id},
    *   external ID, or legacy base64 JSON payload).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\JsonResponse
   *   Redirect response for normal navigation, or JSON for AJAX requests.
   */
  /**
   * Known derived workflow_status values (used for enum validation of filter_status).
   */
  private const WORKFLOW_STATUS_ENUM = [
    'profile_pending',
    'tailoring_pending',
    'tailoring_processing',
    'approval_pending',
    'application_pending',
    'pending_response',
    'interview',
    'closed',
  ];

  /**
   * Human-readable labels for each pipeline stage, in display order.
   */
  private const PIPELINE_STAGE_LABELS = [
    'profile_pending'      => 'Profile Pending',
    'tailoring_pending'    => 'Tailoring Pending',
    'tailoring_processing' => 'Tailoring Processing',
    'approval_pending'     => 'Approval Pending',
    'application_pending'  => 'Application Pending',
    'pending_response'     => 'Pending Response',
    'interview'            => 'Interview',
    'closed'               => 'Closed',
  ];

  public function myJobs(): array {
    $request = $this->requestStack->getCurrentRequest();
    $per_page = 20;
    $page = max(0, (int) $request->query->get('page', 0));

    // AC-2: filter_status validated against known enum; invalid → empty (no PHP error).
    $filter_status_raw = (string) $request->query->get('filter_status', '');
    $filter_status = in_array($filter_status_raw, self::WORKFLOW_STATUS_ENUM, TRUE) ? $filter_status_raw : '';

    // AC-3: filter_company sanitized before use.
    $filter_company = strip_tags((string) $request->query->get('filter_company', ''));

    // Load all non-archived jobs for the current user (company filter applied at DB).
    // Workflow_status filter is derived post-query, so we load the full set here.
    $db_filters = ['company' => $filter_company];
    $all_jobs = $this->jobDiscoveryService->getSavedJobs($db_filters, 0, 1000);
    $companies = $this->jobDiscoveryService->getCompanyNames();
    $platforms = $this->jobDiscoveryService->getSourcePlatforms();

    $has_profile = $this->userHasCompletedProfile();

    // Load current user's skills for match score computation (AC-2/SEC-3: current user only).
    $uid = (int) $this->currentUser()->id();
    $seeker_row = \Drupal::database()->select('jobhunter_job_seeker', 'js')
      ->fields('js', ['skills'])
      ->condition('js.uid', $uid)
      ->execute()
      ->fetchObject();
    $user_skills_raw = $seeker_row ? (string) ($seeker_row->skills ?? '') : '';
    $user_skill_tokens = $this->tokenizeText($user_skills_raw);
    $user_has_skills = !empty($user_skill_tokens);

    // Derive workflow_status, display fields, and match score for every job.
    $today_str = date('Y-m-d');
    foreach ($all_jobs as $job) {
      $job->workflow_status = $this->deriveWorkflowStatus($job, $has_profile);
      $job->display_platform = !empty($job->via) ? $job->via : (!empty($job->source_platform) ? $job->source_platform : '');
      $job->submit_csrf_token = \Drupal::csrfToken()->get('jobhunter/jobs/' . (int) $job->id . '/apply');
      $target_context = $this->loadSubmissionTargetContext($uid, $job);
      $job->submission_apply_url = $target_context['apply_url'];
      $job->submission_ats_platform = $target_context['ats_platform'];
      $job->submission_apply_option = $target_context['selected_apply_option'];
      $job->notes_load_url = \Drupal\Core\Url::fromRoute('job_hunter.application_notes_load', ['job_id' => (int) $job->id])->toString();
      $job->notes_save_url = \Drupal\Core\Url::fromRoute('job_hunter.application_notes_save', ['job_id' => (int) $job->id])->toString();
      $job->notes_csrf_token = \Drupal::csrfToken()->get('jobhunter/jobs/' . (int) $job->id . '/notes/save');
      $job->match_score = $this->computeMatchScore($user_skill_tokens, $job);

      // AC-2/AC-3: follow-up overdue badge — set when follow_up_date has passed
      // and the job has not yet progressed to interview stage or beyond.
      $fu_date = (string) ($job->follow_up_date ?? '');
      $advanced = in_array($job->workflow_status, ['interview', 'closed'], TRUE);
      $job->follow_up_overdue = ($fu_date !== '' && $fu_date < $today_str && !$advanced);
    }

    // AC-2: Apply workflow_status filter (post-derivation).
    if ($filter_status !== '') {
      $all_jobs = array_values(array_filter($all_jobs, fn($j) => $j->workflow_status === $filter_status));
    }

    // Compute per-stage counts across the full filtered set (for count badges).
    $stage_counts = array_fill_keys(array_keys(self::PIPELINE_STAGE_LABELS), 0);
    foreach ($all_jobs as $job) {
      $stage = $job->workflow_status;
      if (isset($stage_counts[$stage])) {
        $stage_counts[$stage]++;
      }
    }

    // Paginate the full filtered set.
    $total = count($all_jobs);
    $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
    $paged_jobs = array_slice($all_jobs, $page * $per_page, $per_page);

    // AC-1: Group paged jobs by pipeline stage for display.
    $pipeline_stages = [];
    foreach (self::PIPELINE_STAGE_LABELS as $stage => $label) {
      $stage_jobs = array_values(array_filter($paged_jobs, fn($j) => $j->workflow_status === $stage));
      $pipeline_stages[$stage] = [
        'label' => $label,
        'count' => $stage_counts[$stage],
        'jobs'  => $stage_jobs,
      ];
    }

    $content = [
      '#theme' => 'my_jobs',
      '#jobs' => $paged_jobs,
      '#pipeline_stages' => $pipeline_stages,
      '#companies' => $companies,
      '#platforms' => $platforms,
      '#filter_company' => $filter_company,
      '#filter_status' => $filter_status,
      '#filter_platform' => '',
      '#return_url' => $request->getRequestUri(),
      '#current_page' => $page,
      '#total_pages' => $total_pages,
      '#total_jobs' => $total,
      '#user_has_skills' => $user_has_skills,
      '#cache' => [
        'contexts' => ['user', 'url.query_args'],
        'tags' => ['job_hunter:jobs', 'job_hunter:companies'],
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Check whether the current user has a completed job-seeker profile.
   *
   * A profile is considered complete when a consolidated_profile_json exists.
   *
   * @return bool
   *   TRUE if the user has a profile, FALSE otherwise.
   */
  private function userHasCompletedProfile(): bool {
    return $this->repository->hasCompletedProfile((int) $this->currentUser()->id());
  }

  /**
   * Derive the user-facing workflow status for a saved job.
   *
   * Status priority (first match wins):
   *   1. closed        – job.status == 'closed'
   *   2. pending_response – application submitted successfully
  *   3. approval_pending – tailoring complete, awaiting user PDF generation
  *   4. application_pending – PDF generated, no application yet
  *   5. tailoring_processing – tailoring queued / in progress
  *   6. tailoring_pending – profile done, no tailoring started
  *   7. profile_pending – user has no consolidated profile
   *
   * @param object $job
   *   Job row from getSavedJobs().
   * @param bool $has_profile
   *   Whether the user has a completed profile.
   *
   * @return string
   *   One of: profile_pending, tailoring_pending, tailoring_processing,
  *   approval_pending, application_pending, pending_response, closed.
   */
  private function deriveWorkflowStatus(object $job, bool $has_profile): string {
    // Closed takes priority.
    if (($job->status ?? '') === 'closed') {
      return 'closed';
    }

    // If an application was submitted successfully, we're waiting on company.
    $app_status = $job->application_status ?? '';
    if (in_array($app_status, ['submitted', 'confirmed', 'manual_completed'], TRUE)) {
      return 'pending_response';
    }

    // If the application has progressed to interview stage.
    if (in_array($app_status, ['interview_scheduled', 'interview_completed'], TRUE)) {
      return 'interview';
    }

    // If tailoring is complete, user must approve/generate PDF before apply.
    $tailoring = $job->tailoring_status ?? '';
    if ($tailoring === 'completed') {
      $pdf_generated = (int) ($job->pdf_generated ?? 0);
      $pdf_path = (string) ($job->pdf_path ?? '');
      if ($pdf_generated === 1 || $pdf_path !== '') {
        return 'application_pending';
      }
      return 'approval_pending';
    }

    // If tailoring is actively queued or processing in the DB.
    if (in_array($tailoring, ['processing', 'queued'], TRUE)) {
      return 'tailoring_processing';
    }

    // If DB says "pending" a tailored_resumes row exists but the queue may
    // have already picked it up.  Check the actual Drupal queue to keep the
    // status honest (queue stores PHP-serialized data, not JSON).
    if ($tailoring === 'pending') {
      $uid = (int) $this->currentUser()->id();
      $job_id = (int) $job->id;
      if ($this->isItemInTailoringQueue($uid, $job_id)) {
        // Sync the DB so subsequent loads are correct without re-scanning.
        $this->repository->updateTailoredResume($uid, $job_id, ['tailoring_status' => 'queued', 'updated' => time()]);
        return 'tailoring_processing';
      }
    }

    // If user has no profile yet.
    if (!$has_profile) {
      return 'profile_pending';
    }

    // Default: profile exists but no tailoring started.
    return 'tailoring_pending';
  }

  /**
   * Check whether a tailoring queue item exists for a user + job.
   *
   * Drupal's queue table stores data as PHP-serialized blobs, so we
   * must unserialize and compare fields properly.
   *
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job requirement ID.
   *
   * @return bool
   *   TRUE if a matching item is in the active queue.
   */
  private function isItemInTailoringQueue(int $uid, int $job_id): bool {
    $rows = $this->repository->getQueueDataItems('job_hunter_resume_tailoring');

    foreach ($rows as $row) {
      $item = @unserialize($row->data, ['allowed_classes' => FALSE]);
      if (is_array($item)
          && (int) ($item['uid'] ?? 0) === $uid
          && (int) ($item['job_id'] ?? 0) === $job_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Resolve the best-known submission destination for a saved job.
   *
   * @param int $uid
   *   Current user ID.
   * @param object $job
   *   Job row from getSavedJobs().
   *
   * @return array{apply_url:string,ats_platform:string,selected_apply_option:string}
   *   Submission target context for display and verification.
   */
  private function loadSubmissionTargetContext(int $uid, object $job): array {
    $application = $this->repository->findLatestApplicationByJobAndUser($uid, (int) $job->id, [
      'apply_url',
      'ats_platform',
      'selected_apply_option',
    ]);

    $apply_url = trim((string) ($application['apply_url'] ?? ''));
    $ats_platform = trim((string) ($application['ats_platform'] ?? ''));
    $selected_apply_option = trim((string) ($application['selected_apply_option'] ?? ''));

    if ($apply_url !== '') {
      return [
        'apply_url' => $apply_url,
        'ats_platform' => $ats_platform,
        'selected_apply_option' => $selected_apply_option,
      ];
    }

    try {
      $resolved = \Drupal::service('job_hunter.apply_url_resolver')->resolve([
        'apply_options' => (string) ($job->apply_options ?? ''),
        'job_url' => (string) ($job->job_url ?? ''),
      ]);

      return [
        'apply_url' => trim((string) ($resolved['url'] ?? '')),
        'ats_platform' => trim((string) ($resolved['ats_platform'] ?? '')),
        'selected_apply_option' => trim((string) ($resolved['selected_option'] ?? '')),
      ];
    }
    catch (\Throwable $e) {
      return [
        'apply_url' => '',
        'ats_platform' => '',
        'selected_apply_option' => '',
      ];
    }
  }

  /**
   * Archive a saved job (sets status to 'archived').
   */
  public function myJobsArchive(): array {
    $request = $this->requestStack->getCurrentRequest();
    $per_page = 50;
    $page = max(0, (int) $request->query->get('page', 0));

    $total = $this->jobDiscoveryService->getArchivedJobsCount();
    $jobs = $this->jobDiscoveryService->getArchivedJobs($page, $per_page);
    $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

    $content = [
      '#theme' => 'my_jobs_archive',
      '#jobs' => $jobs,
      '#current_page' => $page,
      '#total_pages' => $total_pages,
      '#total_jobs' => $total,
      '#cache' => [
        'contexts' => ['user', 'url.query_args'],
        'tags' => ['job_hunter:jobs'],
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Toggle "have applied" status for a saved job.
   *
   * @param int $job_id
   *   The job requirement ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirects back to My Jobs page.
   */
  public function jobDiscoverySearchResults(): array {
    $request = $this->requestStack->getCurrentRequest();

    // Extract search parameters from request
    $search_params = [
      'query' => $request->query->get('q', ''),  // Using 'q' to match form
      'location' => $request->query->get('location', ''),
      'sources' => $request->query->all('sources'),
      'employment_type' => $request->query->get('employment_type', ''),
      'salary_min' => $request->query->get('salary_min', ''),
      'salary_max' => $request->query->get('salary_max', ''),
      'remote_preference' => $request->query->get('remote_preference', ''),
      'date_posted' => $request->query->get('date_posted', ''),
      'company' => $request->query->get('company', ''),
      'relocation_willing' => $request->query->get('relocation_willing', ''),
      'page' => $request->query->get('page', 1),
      'next_page_token' => $request->query->get('next_page_token', ''),
      '_explicit_sources' => $request->query->has('sources') || $request->query->has('sources_submitted'),
      '_explicit_salary_min' => $request->query->has('salary_min'),
      '_explicit_remote_preference' => $request->query->has('remote_preference'),
    ];

    $search_params = $this->searchAggregator->normalizeSearchParameters($search_params);

    $this->getLogger('job_hunter')->info('🔍 Controller: Delegating search to SearchAggregatorService with @count sources', [
      '@count' => count($search_params['sources']),
    ]);

    // Delegate to SearchAggregatorService
    $search_results = $this->searchAggregator->searchJobs($search_params);

    // Prepare display parameters
    $display_params = [];
    if (!empty($search_params['query'])) {
      $display_params['query'] = $search_params['query'];
    }
    if (!empty($search_params['location'])) {
      $display_params['location'] = $search_params['location'];
    }
    if (!empty($search_params['employment_type'])) {
      $display_params['employment_type'] = $search_params['employment_type'];
    }
    if (!empty($search_params['salary_min'])) {
      $display_params['salary_min'] = $search_params['salary_min'];
    }
    if (!empty($search_params['salary_max'])) {
      $display_params['salary_max'] = $search_params['salary_max'];
    }
    if (!empty($search_params['remote_preference'])) {
      $display_params['remote_preference'] = $search_params['remote_preference'];
    }
    if (!empty($search_params['relocation_willing'])) {
      $display_params['relocation_willing'] = $search_params['relocation_willing'];
    }

    // Capitalize source names for display
    $sources_display = array_map('ucfirst', $search_results['sources_searched']);

    // Build render array
    $content = [
      '#theme' => 'job_search_results',
      '#results' => $search_results['results'],
      '#search_params' => $display_params,
      '#total_results' => $search_results['total'],
      '#sources_searched' => $sources_display,
      '#diagnostics' => $search_results['diagnostics'],
      '#pagination' => $search_results['pagination'] ?? [],
      '#current_page' => $search_params['page'],
      '#save_job_csrf_token' => $this->csrfTokenGenerator->get('job_hunter.addposting'),
      '#attached' => [
        'library' => [
          'job_hunter/job-search-results',
        ],
      ],
      '#cache' => [
        'contexts' => ['url.query_args', 'user'],
        'tags' => ['job_hunter:search'],
        // CSRF tokens are per-session, not per-user: must not be cached.
        'max-age' => 0,
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Step 3: Application Submission page.
   *
   * @return array
   *   A renderable array for the application submission page.
   */
  public function applicationSubmission(?int $job_id = NULL) {
    $uid = (int) $this->currentUser()->id();
    $summary = $this->getApplicationSubmissionSummary($uid, $job_id);
    $applications = $this->getRecentApplicationSubmissions($uid, 25, $job_id);
    $has_profile = $this->userHasCompletedProfile();
    $saved_jobs = $this->jobDiscoveryService->getSavedJobs([], 0, 200);

    $selected_job = NULL;
    $selected_workflow_status = '';
    $ready_jobs = [];
    $approval_jobs = [];
    foreach ($saved_jobs as $job) {
      if ($job_id !== NULL && (int) $job->id !== (int) $job_id) {
        continue;
      }

      $job->workflow_status = $this->deriveWorkflowStatus($job, $has_profile);
      $job->display_platform = !empty($job->via) ? $job->via : (!empty($job->source_platform) ? $job->source_platform : '');
      $job->apply_csrf_token = \Drupal::csrfToken()->get('jobhunter/my-jobs/' . (int) $job->id . '/applied');

      if ($job_id !== NULL && (int) $job->id === (int) $job_id) {
        $selected_job = $job;
        $selected_workflow_status = (string) $job->workflow_status;
      }

      if ($job->workflow_status === 'application_pending') {
        $ready_jobs[] = $job;
      }
      elseif ($job->workflow_status === 'approval_pending') {
        $approval_jobs[] = $job;
      }
    }

    if ($job_id !== NULL && !$selected_job) {
      $selected_job = $this->loadSelectedJobContext($uid, $job_id);
      if ($selected_job) {
        $selected_job->workflow_status = $this->deriveWorkflowStatus($selected_job, $has_profile);
        $selected_job->display_platform = !empty($selected_job->via) ? $selected_job->via : (!empty($selected_job->source_platform) ? $selected_job->source_platform : '');
        $selected_job->submit_csrf_token = \Drupal::csrfToken()->get('jobhunter/jobs/' . (int) $selected_job->id . '/apply');
        $target_context = $this->loadSubmissionTargetContext($uid, $selected_job);
        $selected_job->submission_apply_url = $target_context['apply_url'];
        $selected_job->submission_ats_platform = $target_context['ats_platform'];
        $selected_job->submission_apply_option = $target_context['selected_apply_option'];
        $selected_workflow_status = (string) $selected_job->workflow_status;

        if ($selected_job->workflow_status === 'application_pending') {
          $ready_jobs[] = $selected_job;
        }
        elseif ($selected_job->workflow_status === 'approval_pending') {
          $approval_jobs[] = $selected_job;
        }
      }
    }

    $stage_counts = [
      'approval_pending' => count($approval_jobs),
      'application_pending' => count($ready_jobs),
      'processing' => (int) ($summary['processing'] ?? 0),
      'submitted' => (int) ($summary['submitted'] ?? 0),
      'manual_required' => (int) ($summary['manual_required'] ?? 0),
      'failed' => (int) ($summary['failed'] ?? 0),
    ];

    $latest_attempts = $this->getLatestAttemptsByApplicationIds(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $applications));

    $recent_applications = [];
    foreach ($applications as $application) {
      $application_id = (int) ($application['id'] ?? 0);
      $application_job_id = (int) ($application['job_id'] ?? 0);
      $metadata = [];
      if (!empty($application['metadata']) && is_string($application['metadata'])) {
        $decoded = json_decode($application['metadata'], TRUE);
        if (is_array($decoded)) {
          $metadata = $decoded;
        }
      }

      $last_attempt = $latest_attempts[$application_id] ?? [];
      $recent_applications[] = [
        'application_id' => $application_id,
        'job_id' => $application_job_id,
        'job_title' => (string) ($application['job_title'] ?? ('Job #' . $application_job_id)),
        'submission_status' => (string) ($application['submission_status'] ?? 'unknown'),
        'status_label' => ucwords(str_replace('_', ' ', (string) ($application['submission_status'] ?? 'unknown'))),
        'attempt_count' => (int) ($application['attempt_count'] ?? 0),
        'ats_platform' => (string) ($application['ats_platform'] ?? ''),
        'selected_apply_option' => (string) ($application['selected_apply_option'] ?? ''),
        'resolution_confidence' => (string) ($metadata['confidence'] ?? ''),
        'resolution_steps_count' => is_array($metadata['resolution_steps'] ?? NULL) ? count($metadata['resolution_steps']) : 0,
        'verification_passed' => !empty($metadata['verification_passed_at']),
        'auth_type' => (string) ($metadata['auth_type'] ?? ''),
        'account_readiness_at' => (string) ($metadata['account_readiness_at'] ?? ''),
        'confirmation' => (string) ($application['confirmation_reference'] ?? $application['confirmation_ref'] ?? ''),
        'apply_url' => (string) ($application['apply_url'] ?? ''),
        'last_attempt_outcome' => (string) ($last_attempt['outcome'] ?? ''),
        'last_attempt_error' => (string) ($last_attempt['error_message'] ?? ''),
        'last_attempt_at' => (string) ($last_attempt['attempted_at'] ?? ''),
        'submit_csrf_token' => \Drupal::csrfToken()->get('jobhunter/jobs/' . (int) $application_job_id . '/apply'),
      ];
    }

    $selected_application = NULL;
    if ($job_id !== NULL && !empty($recent_applications)) {
      $selected_application = $recent_applications[0];
    }

    if ($job_id !== NULL && !$selected_application && $selected_job) {
      try {
        $resolved = \Drupal::service('job_hunter.apply_url_resolver')->resolve([
          'apply_options' => (string) ($selected_job->apply_options ?? ''),
          'job_url' => (string) ($selected_job->job_url ?? ''),
        ]);

        $selected_application = [
          'application_id' => 0,
          'job_id' => (int) $selected_job->id,
          'job_title' => (string) ($selected_job->job_title ?? ('Job #' . (int) $selected_job->id)),
          'submission_status' => 'not_started',
          'status_label' => 'Not Started',
          'attempt_count' => 0,
          'ats_platform' => (string) ($resolved['ats_platform'] ?? ''),
          'selected_apply_option' => (string) ($resolved['selected_option'] ?? ''),
          'resolution_confidence' => (string) ($resolved['confidence'] ?? ''),
          'resolution_steps_count' => is_array($resolved['resolution_steps'] ?? NULL) ? count($resolved['resolution_steps']) : 0,
          'confirmation' => '',
          'apply_url' => (string) ($resolved['url'] ?? ''),
          'last_attempt_outcome' => '',
          'last_attempt_error' => '',
          'last_attempt_at' => '',
          'account_readiness_at' => '',
          'submit_csrf_token' => \Drupal::csrfToken()->get('jobhunter/jobs/' . (int) $selected_job->id . '/apply'),
        ];
      }
      catch (\Exception $e) {
        $this->getLogger('job_hunter')->warning('Unable to resolve redirect chain for job @job_id: @error', [
          '@job_id' => $job_id,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    $selected_attempt = NULL;
    if (!empty($selected_application['application_id'])) {
      $selected_attempt = $latest_attempts[(int) $selected_application['application_id']] ?? NULL;
    }

    $journey_steps = $this->buildJobJourneyFlow(
      $selected_job,
      $selected_workflow_status,
      $selected_application,
      $selected_attempt,
      $has_profile
    );

    $return_url = $job_id !== NULL
      ? '/jobhunter/application-submission/' . (int) $job_id
      : '/jobhunter/application-submission';

    $is_job_specific = $job_id !== NULL;
    $job_snapshot = NULL;
    if ($is_job_specific && $selected_job) {
      $extracted = is_array($selected_job->extracted_data ?? NULL) ? $selected_job->extracted_data : [];
      $job_title = (string) ($extracted['position']['title'] ?? $selected_job->job_title ?? ('Job #' . (int) $selected_job->id));
      $company_name = (string) ($extracted['company']['name'] ?? $selected_job->company_name ?? 'Unknown');
      $career_url = (string) ($selected_application['apply_url'] ?? $selected_job->job_url ?? '');
      $original_job_url = (string) ($selected_job->job_url ?? '');
      $career_host = '';
      if ($career_url !== '') {
        $parsed_host = parse_url($career_url, PHP_URL_HOST);
        $career_host = is_string($parsed_host) ? $parsed_host : '';
      }

      $pdf_generated = ((int) ($selected_job->pdf_generated ?? 0) > 0) || ((string) ($selected_job->pdf_path ?? '') !== '');
      $submission_status = (string) ($selected_application['submission_status'] ?? 'not_started');
      $resolution_steps_count = (int) ($selected_application['resolution_steps_count'] ?? 0);
      $resolution_confidence = (string) ($selected_application['resolution_confidence'] ?? '');
      $is_direct_company_link = $resolution_steps_count === 0;
      $redirect_chain_resolved = $resolution_steps_count > 0 && in_array(strtolower($resolution_confidence), ['high', 'medium'], TRUE);
      $attempt_count = (int) ($selected_application['attempt_count'] ?? 0);
      $last_attempt_outcome = (string) ($selected_application['last_attempt_outcome'] ?? '');
      $last_attempt_error = (string) ($selected_application['last_attempt_error'] ?? '');
      $career_page_identified = $career_url !== '';
      $auth_process_vetted = $attempt_count > 0 || in_array($submission_status, ['queued', 'pending', 'processing', 'submitted', 'confirmed', 'manual_required', 'failed', 'manual_completed'], TRUE);

      $job_snapshot = [
        'job_id' => (int) $selected_job->id,
        'job_title' => $job_title,
        'company_name' => $company_name,
        'workflow_status' => $selected_workflow_status,
        'pdf_generated' => $pdf_generated,
        'pdf_path' => (string) ($selected_job->pdf_path ?? ''),
        'original_job_url' => $original_job_url,
        'career_url' => $career_url,
        'career_host' => $career_host,
        'ats_platform' => (string) ($selected_application['ats_platform'] ?? ''),
        'submission_status' => $submission_status,
        'submission_status_label' => ucwords(str_replace('_', ' ', $submission_status)),
        'attempt_count' => $attempt_count,
        'last_attempt_outcome' => $last_attempt_outcome,
        'last_attempt_error' => $last_attempt_error,
        'resolution_steps_count' => $resolution_steps_count,
        'resolution_confidence' => $resolution_confidence,
        'is_direct_company_link' => $is_direct_company_link,
        'redirect_chain_resolved' => $redirect_chain_resolved,
        'career_page_identified' => $career_page_identified,
        'auth_process_vetted' => $auth_process_vetted,
      ];
    }

    $content = [
      '#theme' => 'application_submission',
      '#is_job_specific' => $is_job_specific,
      '#job_snapshot' => $job_snapshot,
      '#summary' => $summary,
      '#stage_counts' => $stage_counts,
      '#ready_jobs' => $ready_jobs,
      '#approval_jobs' => $approval_jobs,
      '#recent_applications' => $recent_applications,
      '#selected_job' => $selected_job,
      '#selected_workflow_status' => $selected_workflow_status,
      '#selected_application' => $selected_application,
      '#journey_steps' => $journey_steps,
      '#return_url' => $return_url,
      '#cache' => [
        'contexts' => ['user', 'url.query_args'],
        'tags' => ['job_hunter:jobs', 'job_hunter:applications'],
        'max-age' => 0,
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Dedicated Step 2 page: Resolve redirect chain for one requisition.
   */
  public function applicationSubmissionScreenshot(int $job_id, string $filename): BinaryFileResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      throw new AccessDeniedHttpException('Authentication required.');
    }

    $application = $this->repository->findLatestApplicationByJobAndUser($uid, $job_id, ['metadata']);

    if (!$application) {
      throw new NotFoundHttpException('Application not found.');
    }

    $metadata = [];
    if (!empty($application['metadata'])) {
      $decoded = json_decode((string) $application['metadata'], TRUE);
      if (is_array($decoded)) {
        $metadata = $decoded;
      }
    }

    $screenshots = (array) ($metadata['step5_cache']['resume_upload_result']['screenshots'] ?? []);
    $allowed_basenames = [];
    foreach ($screenshots as $path) {
      $base = basename((string) $path);
      if ($base !== '') {
        $allowed_basenames[$base] = TRUE;
      }
    }

    if (empty($allowed_basenames[$filename])) {
      throw new AccessDeniedHttpException('Screenshot not authorized for this job.');
    }

    $screenshots_dir = \Drupal::service('file_system')->realpath('private://job_hunter/screenshots');
    if (!$screenshots_dir || !is_dir($screenshots_dir)) {
      throw new AccessDeniedHttpException('Screenshot directory unavailable.');
    }

    $full_path = realpath($screenshots_dir . DIRECTORY_SEPARATOR . $filename);
    $dir_real = realpath($screenshots_dir);
    if (!$full_path || !$dir_real || !is_file($full_path) || strpos($full_path, $dir_real . DIRECTORY_SEPARATOR) !== 0) {
      throw new AccessDeniedHttpException('Screenshot file not found.');
    }

    $mime = 'application/octet-stream';
    if (function_exists('mime_content_type')) {
      $detected = @mime_content_type($full_path);
      if (is_string($detected) && $detected !== '') {
        $mime = $detected;
      }
    }

    $response = new BinaryFileResponse($full_path);
    $response->headers->set('Content-Type', $mime);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($full_path));
    $response->headers->set('Cache-Control', 'private, no-cache, no-store, must-revalidate');
    return $response;
  }

  /**
   * Generic stub page for application submission steps.
   */
  public function applicationSubmissionStepStub(int $job_id, int $step): array|RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    if ($uid <= 0) {
      return [
        '#markup' => $this->t('You must be logged in to access this page.'),
      ];
    }

    if ($step === 2) {
      return new RedirectResponse(Url::fromRoute('job_hunter.application_submission_step2', ['job_id' => $job_id])->toString());
    }

    if ($step === 3) {
      return new RedirectResponse(Url::fromRoute('job_hunter.application_submission_step3', ['job_id' => $job_id])->toString());
    }

    if ($step === 4) {
      return new RedirectResponse(Url::fromRoute('job_hunter.application_submission_step4', ['job_id' => $job_id])->toString());
    }

    // Steps 5, 6, 7 are now combined into the single "Submit Application" page.
    if (in_array($step, [5, 6, 7], TRUE)) {
      return new RedirectResponse(Url::fromRoute('job_hunter.application_submission_step5', ['job_id' => $job_id])->toString());
    }

    $step_map = [
      1 => [
        'title' => 'Step 1: Pre-requirements',
        'description' => 'Validate tailored resume + PDF readiness and profile prerequisites for this requisition.',
      ],
      3 => [
        'title' => 'Step 3: Identify Authentication Path',
        'description' => 'Capture and verify the expected login/authentication path for the destination site.',
      ],
      4 => [
        'title' => 'Step 4: Verify Account Readiness',
        'description' => 'Confirm this user can authenticate and has required credentials/readiness for this company flow.',
      ],
      5 => [
        'title' => 'Step 5: Submit Application',
        'description' => 'Confirm the job exists, locate the apply control, and submit the application.',
      ],
      6 => [
        'title' => 'Step 6: Capture Confirmation & Evidence',
        'description' => 'Persist confirmation references and attempt evidence for auditability.',
      ],
    ];

    if (!isset($step_map[$step])) {
      $this->messenger()->addError($this->t('Unknown process step.'));
      return $this->wrapWithNavigation([
        '#markup' => '<p>' . $this->t('Step not found.') . '</p>',
      ]);
    }

    $selected_job = $this->loadSelectedJobContext($uid, $job_id);
    if (!$selected_job) {
      $this->messenger()->addError($this->t('Job requisition not found for your account.'));
      return $this->wrapWithNavigation([
        '#markup' => '<p>' . $this->t('Unable to load this requisition.') . '</p>',
      ]);
    }

    $extracted = is_array($selected_job->extracted_data ?? NULL) ? $selected_job->extracted_data : [];
    $job_title = (string) ($extracted['position']['title'] ?? $selected_job->job_title ?? ('Job #' . (int) $selected_job->id));
    $company_name = (string) ($extracted['company']['name'] ?? $selected_job->company_name ?? 'Unknown');

    $content = [
      '#theme' => 'application_submission_step_stub',
      '#job_id' => (int) $selected_job->id,
      '#job_title' => $job_title,
      '#company_name' => $company_name,
      '#step' => $step,
      '#step_title' => $step_map[$step]['title'],
      '#step_description' => $step_map[$step]['description'],
      '#return_url' => '/jobhunter/application-submission/' . (int) $selected_job->id,
      '#cache' => [
        'contexts' => ['user', 'url.query_args'],
        'tags' => ['job_hunter:jobs', 'job_hunter:applications'],
        'max-age' => 0,
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Gets summary counts for a user's application submissions.
   */
  private function getApplicationSubmissionSummary(int $uid, ?int $job_id = NULL): array {
    return $this->repository->getApplicationSubmissionSummary($uid, $job_id);
  }

  /**
   * Gets recent applications with optional fields when available.
   */
  private function getRecentApplicationSubmissions(int $uid, int $limit = 25, ?int $job_id = NULL): array {
    return $this->repository->getRecentApplicationSubmissions($uid, $limit, $job_id);
  }

  /**
   * Get latest attempt rows keyed by application_id.
   */
  private function getLatestAttemptsByApplicationIds(array $application_ids): array {
    return $this->repository->getLatestAttemptsByApplicationIds($application_ids);
  }

  /**
   * Build a full end-to-end process flow view for a selected job.
   */
  private function buildJobJourneyFlow(?object $selected_job, string $workflow_status, ?array $selected_application, ?array $selected_attempt, bool $has_profile): array {
    if (!$selected_job) {
      return [
        [
          'index' => 1,
          'label' => 'Step Pre-requirements',
          'status' => 'current',
          'gate_met' => FALSE,
          'detail' => 'Job context not loaded yet; pre-requirements cannot be verified.',
          'requirements' => [
            [
              'label' => 'Tailored resume PDF is generated and ready for submission',
              'met' => FALSE,
              'evidence' => 'No selected job context available for verification.',
            ],
          ],
        ],
        [
          'index' => 2,
          'label' => 'Resolve redirect chain',
          'status' => 'blocked',
          'gate_met' => FALSE,
          'detail' => 'Cannot evaluate redirect chain until selected job context is available.',
          'requirements' => [
            [
              'label' => 'Redirect chain is fully resolved',
              'met' => FALSE,
              'evidence' => 'No selected job context available for verification.',
            ],
          ],
        ],
        [
          'index' => 3,
          'label' => 'Identify authentication path',
          'status' => 'blocked',
          'gate_met' => FALSE,
          'detail' => 'Cannot evaluate authentication path until selected job context is available.',
          'requirements' => [
            [
              'label' => 'Authentication path is identified',
              'met' => FALSE,
              'evidence' => 'No selected job context available for verification.',
            ],
          ],
        ],
        [
          'index' => 4,
          'label' => 'Verify account readiness',
          'status' => 'blocked',
          'gate_met' => FALSE,
          'detail' => 'Cannot evaluate account readiness until selected job context is available.',
          'requirements' => [
            [
              'label' => 'User can authenticate and account readiness is confirmed',
              'met' => FALSE,
              'evidence' => 'No selected job context available for verification.',
            ],
          ],
        ],
        [
          'index' => 5,
          'label' => 'Submit application',
          'status' => 'blocked',
          'gate_met' => FALSE,
          'detail' => 'Cannot evaluate submission until selected job context is available.',
          'requirements' => [
            [
              'label' => 'Job confirmed, apply control located, and submission completed',
              'met' => FALSE,
              'evidence' => 'No selected job context available for verification.',
            ],
          ],
        ],
        [
          'index' => 6,
          'label' => 'Capture confirmation and evidence',
          'status' => 'blocked',
          'gate_met' => FALSE,
          'detail' => 'Cannot evaluate evidence stage until selected job context is available.',
          'requirements' => [
            [
              'label' => 'Confirmation and attempt evidence are captured',
              'met' => FALSE,
              'evidence' => 'No selected job context available for verification.',
            ],
          ],
        ],
      ];
    }

    $submission_status = (string) ($selected_application['submission_status'] ?? '');
    $ats_platform = (string) ($selected_application['ats_platform'] ?? '');
    $apply_url = (string) ($selected_application['apply_url'] ?? '');
    $career_url = $apply_url !== '' ? $apply_url : (string) ($selected_job->job_url ?? '');
    $career_page_identified = $career_url !== '';
    $resolution_steps_count = (int) ($selected_application['resolution_steps_count'] ?? 0);
    $resolution_confidence = (string) ($selected_application['resolution_confidence'] ?? '');
    $confirmation = (string) ($selected_application['confirmation'] ?? '');
    $attempt_count = (int) ($selected_application['attempt_count'] ?? 0);
    $last_attempt_outcome = (string) ($selected_application['last_attempt_outcome'] ?? '');
    $last_attempt_error = (string) ($selected_application['last_attempt_error'] ?? '');
    $last_attempt_at = (string) ($selected_application['last_attempt_at'] ?? '');

    $tailoring_completed = in_array($workflow_status, ['approval_pending', 'application_pending', 'pending_response', 'closed'], TRUE);
    $pdf_ready = (int) ($selected_job->pdf_generated ?? 0) > 0 || (string) ($selected_job->pdf_path ?? '') !== '';
    $has_resolved_url = $apply_url !== '';
    $has_ats_detection = $ats_platform !== '';
    $has_resolution_trace = $resolution_steps_count > 0;
    $submission_started = in_array($submission_status, ['queued', 'pending', 'processing', 'submitted', 'confirmed', 'manual_required', 'failed', 'manual_completed'], TRUE);
    $auth_process_vetted = $attempt_count > 0 || $submission_started;
    $submission_completed = in_array($submission_status, ['submitted', 'confirmed', 'manual_completed'], TRUE);
    $has_attempt_evidence = $attempt_count > 0 || $last_attempt_outcome !== '';
    $has_confirmation = $confirmation !== '';
    $resolved_confidence = in_array(strtolower($resolution_confidence), ['high', 'medium'], TRUE);
    $verification_passed = !empty($selected_application['verification_passed']);
    $redirect_chain_fully_resolved = $verification_passed || ($has_resolved_url && $has_resolution_trace && $resolved_confidence);
    // Step 3 passes when either:
    //   (a) the auth_type has been explicitly classified by Step 3 service, OR
    //   (b) legacy: ATS platform is known and not 'unknown'/'aggregator'.
    $stored_auth_type = strtolower((string) ($selected_application['auth_type'] ?? ''));
    $auth_type_classified = $stored_auth_type !== '' && !in_array($stored_auth_type, ['unknown', 'captcha_blocked'], TRUE);
    $auth_path_identified = $auth_type_classified || ($has_ats_detection && !in_array(strtolower($ats_platform), ['unknown', 'aggregator', ''], TRUE));
    $last_outcome_lc = strtolower($last_attempt_outcome);
    $auth_blocked = in_array($last_outcome_lc, ['auth_required', 'auth_failed', 'credential_missing', 'login_required'], TRUE)
      || str_contains(strtolower($last_attempt_error), 'auth')
      || str_contains(strtolower($last_attempt_error), 'login')
      || str_contains(strtolower($last_attempt_error), 'credential');
    // Step 4 passes when:
    //   (a) user confirmed account readiness via Step 4 page, OR
    //   (b) legacy: submission started and auth is not blocked.
    $account_readiness_at = (string) ($selected_application['account_readiness_at'] ?? '');
    $account_readiness_confirmed = $account_readiness_at !== '' || ($submission_started && !$auth_blocked);
    $job_title = (string) ($selected_job->job_title ?? '');
    $company_name = (string) ($selected_job->company_name ?? '');
    $job_exists_on_destination = $job_title !== '' && $company_name !== '';
    $apply_control_located = $submission_started || $has_attempt_evidence || ($has_resolved_url && $auth_path_identified);

    $step_1_detail = $pdf_ready
      ? 'Tailored PDF is generated and ready for submission.'
      : 'Tailored PDF not generated yet for this role.';
    $step_2_detail = $redirect_chain_fully_resolved
      ? ($verification_passed
        ? 'Redirect chain resolved and verified by Step 2 checks.'
        : 'Redirect chain resolved to a canonical apply destination.')
      : 'Redirect chain is not fully resolved yet.';
    $step_3_detail = $auth_path_identified
      ? ('Authentication path identified' . ($stored_auth_type !== '' ? ': ' . $stored_auth_type : ' (ATS: ' . $ats_platform . ')') . '.')
      : 'Authentication path not fully identified yet. Run Step 3 to classify.';
    $step_4_detail = $account_readiness_confirmed
      ? ('Account readiness confirmed' . ($account_readiness_at !== '' ? ' at ' . $account_readiness_at : '') . '.')
      : 'Account readiness is not confirmed yet. Run Step 4 to create account and verify.';
    // Step 5 combines: confirm job exists + locate apply control + submit.
    $step_5_gate = $job_exists_on_destination && $apply_control_located && $submission_completed;
    $step_5_detail = $submission_completed
      ? 'Application submitted successfully.'
      : ($submission_started
        ? ('Submission in progress (status: ' . $submission_status . ').')
        : ($job_exists_on_destination && $apply_control_located
          ? 'Ready to submit — job confirmed and apply path located.'
          : ($job_exists_on_destination
            ? 'Job confirmed on destination. Apply control not yet located.'
            : 'Job destination/requisition context is incomplete.')));
    $step_6_detail = $has_confirmation
      ? ('Confirmation captured: ' . $confirmation)
      : ($has_attempt_evidence ? 'Attempt evidence exists, but confirmation is not captured yet.' : 'No evidence/confirmation captured yet.');

    $step_1_requirements = [
      [
        'label' => 'Tailored resume content is complete for this role',
        'met' => $tailoring_completed,
        'evidence' => 'Workflow status: ' . ($workflow_status !== '' ? $workflow_status : 'unknown'),
      ],
      [
        'label' => 'Tailored resume PDF is generated and ready for submission',
        'met' => $pdf_ready,
        'evidence' => $pdf_ready ? 'PDF is generated.' : 'PDF is not generated yet.',
      ],
      [
        'label' => 'Profile prerequisite is satisfied for automation',
        'met' => $has_profile,
        'evidence' => $has_profile ? 'Profile prerequisite passed.' : 'Profile prerequisite not satisfied.',
      ],
    ];

    $step_2_requirements = [
      [
        'label' => 'Resolved destination apply URL is captured',
        'met' => $has_resolved_url,
        'evidence' => $has_resolved_url ? $apply_url : 'Apply URL not captured yet.',
      ],
      [
        'label' => 'Company careers page or direct application page is identified',
        'met' => $career_page_identified,
        'evidence' => $career_page_identified ? ('Career page identified: ' . $career_url) : 'Career/apply page not yet identified.',
      ],
      [
        'label' => 'Redirect chain trace is stored',
        'met' => $verification_passed || $has_resolution_trace,
        'evidence' => ($verification_passed || $has_resolution_trace)
          ? ($verification_passed ? 'Step 2 verification passed.' : ('Resolution steps: ' . $resolution_steps_count))
          : 'Redirect chain evidence not stored yet.',
      ],
      [
        'label' => 'Redirect resolution confidence is sufficient',
        'met' => $verification_passed || $resolved_confidence,
        'evidence' => ($verification_passed || $resolution_confidence !== '')
          ? ($verification_passed ? 'Step 2 verification passed.' : ('Confidence: ' . $resolution_confidence))
          : 'Resolution confidence not available.',
      ],
    ];

    $step_3_requirements = [
      [
        'label' => 'Authentication path (ATS/login flow) is identified',
        'met' => $auth_path_identified,
        'evidence' => $auth_path_identified ? ($stored_auth_type !== '' ? ('auth_type: ' . $stored_auth_type) : ('ATS: ' . $ats_platform)) : 'Authentication path not identified. Run Step 3 to classify.',
      ],
    ];

    $step_4_requirements = [
      [
        'label' => 'User can authenticate (account readiness confirmed)',
        'met' => $account_readiness_confirmed,
        'evidence' => $account_readiness_confirmed
          ? ($account_readiness_at !== '' ? 'Account verified at ' . $account_readiness_at . '.' : 'Submission/attempts indicate authentication is working.')
          : ($auth_blocked ? 'Authentication appears blocked by credential/login errors.' : 'Account readiness not confirmed yet. Run Step 4 to create account.'),
      ],
    ];

    $step_5_requirements = [
      [
        'label' => 'Job requisition appears available on destination',
        'met' => $job_exists_on_destination,
        'evidence' => $job_exists_on_destination ? ('Job: ' . $job_title . ' | Company: ' . $company_name) : 'Job/company destination context is incomplete.',
      ],
      [
        'label' => 'Apply control entry point is located',
        'met' => $apply_control_located,
        'evidence' => $apply_control_located
          ? 'Apply URL and/or attempts indicate actionable apply control path.'
          : 'Apply control path not confirmed from current evidence.',
      ],
      [
        'label' => 'Submission request has started for this job',
        'met' => $submission_started,
        'evidence' => $submission_status !== '' ? ('Submission status: ' . $submission_status) : 'No submission record yet.',
      ],
      [
        'label' => 'Submission reached a successful completion state',
        'met' => $submission_completed,
        'evidence' => $submission_completed ? 'Submission completed.' : 'Submission not complete yet.',
      ],
    ];

    $steps = [
      [
        'index' => 1,
        'label' => 'Step Pre-requirements',
        'status' => 'pending',
        'detail' => $step_1_detail,
        'requirements' => $step_1_requirements,
      ],
      [
        'index' => 2,
        'label' => 'Resolve redirect chain',
        'status' => 'pending',
        'detail' => $step_2_detail,
        'requirements' => $step_2_requirements,
      ],
      [
        'index' => 3,
        'label' => 'Identify authentication path',
        'status' => 'pending',
        'detail' => $step_3_detail,
        'requirements' => $step_3_requirements,
      ],
      [
        'index' => 4,
        'label' => 'Verify account readiness',
        'status' => 'pending',
        'detail' => $step_4_detail,
        'requirements' => $step_4_requirements,
      ],
      [
        'index' => 5,
        'label' => 'Submit application',
        'status' => 'pending',
        'detail' => $step_5_detail,
        'requirements' => $step_5_requirements,
      ],
      [
        'index' => 6,
        'label' => 'Capture confirmation and evidence',
        'status' => 'pending',
        'detail' => $step_6_detail,
        'requirements' => [
          [
            'label' => 'At least one attempt/evidence record exists',
            'met' => $has_attempt_evidence,
            'evidence' => $has_attempt_evidence ? ('Attempts: ' . $attempt_count) : 'No attempt evidence found.',
          ],
          [
            'label' => 'Confirmation reference is captured',
            'met' => $has_confirmation,
            'evidence' => $has_confirmation ? $confirmation : 'Confirmation reference missing.',
          ],
        ],
      ],
    ];

    $previous_unmet = FALSE;
    foreach ($steps as &$step) {
      $requirements = $step['requirements'] ?? [];
      $gate_met = !empty($requirements);
      foreach ($requirements as $requirement) {
        if (empty($requirement['met'])) {
          $gate_met = FALSE;
          break;
        }
      }

      $step['gate_met'] = $gate_met;
      if ($gate_met) {
        $step['status'] = 'completed';
      }
      elseif ($previous_unmet) {
        $step['status'] = 'blocked';
      }
      else {
        $step['status'] = 'current';
        $previous_unmet = TRUE;
      }
    }
    unset($step);

    return $steps;
  }

  /**
   * Load selected job context for a specific user/job journey page.
   */
  /**
   * Detect ATS platform from a URL based on known hostname patterns.
   *
   * @param string $url
   *   The URL to inspect.
   *
   * @return string
   *   Detected ATS platform slug, or 'custom' if unrecognized.
   */

  /**
   * Application Status Dashboard — renders all applications with bulk update controls.
   *
   * AC-1: Checkboxes on each application row (authenticated only).
   * AC-2: Select all checkbox in header.
   * AC-3: Selected count indicator.
   * AC-4: Bulk status update control bar visible when ≥1 selected.
   */
  public function applicationsDashboard(): array {
    $uid = (int) $this->currentUser()->id();

    $applications = \Drupal::database()->select('jobhunter_applications', 'a')
      ->fields('a', ['id', 'job_id', 'submission_status', 'submission_date', 'created'])
      ->condition('a.uid', $uid)
      ->orderBy('a.created', 'DESC')
      ->range(0, 100)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    // Build status options HTML.
    $status_options_html = '';
    foreach (self::WORKFLOW_STATUS_ENUM as $s) {
      $label = htmlspecialchars(ucwords(str_replace('_', ' ', $s)));
      $val   = htmlspecialchars($s);
      $status_options_html .= "<option value=\"$val\">$label</option>";
    }

    // Generate CSRF token for the POST route.
    $csrf_token = $this->csrfTokenGenerator->get('job_hunter.applications_bulk_update');
    $form_action = \Drupal\Core\Url::fromRoute('job_hunter.applications_bulk_update')->toString()
      . '?token=' . rawurlencode($csrf_token);

    // Fetch job titles for display.
    $job_ids = array_filter(array_unique(array_column($applications, 'job_id')));
    $job_titles = [];
    if (!empty($job_ids)) {
      $rows_q = \Drupal::database()->select('jobhunter_saved_jobs', 'j')
        ->fields('j', ['id', 'title', 'company'])
        ->condition('j.id', $job_ids, 'IN')
        ->execute()
        ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
      foreach ($rows_q as $jid => $jrow) {
        $c = !empty($jrow['company']) ? ' — ' . $jrow['company'] : '';
        $job_titles[(int) $jid] = htmlspecialchars((string) ($jrow['title'] ?? '—')) . htmlspecialchars($c);
      }
    }

    // Build table rows HTML.
    $rows_html = '';
    foreach ($applications as $app) {
      $jid   = (int) ($app['job_id'] ?? 0);
      $title = $job_titles[$jid] ?? '—';
      $stat  = htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($app['submission_status'] ?? ''))));
      $date  = htmlspecialchars((string) ($app['submission_date'] ?: ($app['created'] ?? '—')));
      $id    = (int) ($app['id'] ?? 0);
      $rows_html .= "<tr><td><input type=\"checkbox\" class=\"app-checkbox\" name=\"job_ids[]\" value=\"$id\"></td>"
        . "<td>$title</td><td>$stat</td><td>$date</td></tr>\n";
    }
    if ($rows_html === '') {
      $rows_html = '<tr><td colspan="4">No applications found.</td></tr>';
    }

    $html = <<<HTML
<div id="applications-dashboard">
  <h2>My Applications</h2>
  <form method="post" action="{$form_action}" id="bulk-update-form">
    <div id="bulk-control-bar" style="display:none; margin-bottom:1em; padding:0.75em; background:#f5f5f5; border:1px solid #ccc; border-radius:3px;">
      <span id="selected-count" style="font-weight:bold; margin-right:1em;">0 selected</span>
      <label for="bulk-status">Update status to:&nbsp;<select name="new_status" id="bulk-status">{$status_options_html}</select></label>
      &nbsp;
      <button type="submit" id="bulk-apply-btn" class="button button--primary" disabled>Apply</button>
    </div>
    <table class="views-table cols-4" style="width:100%">
      <thead>
        <tr>
          <th><input type="checkbox" id="select-all-checkbox" title="Select all"></th>
          <th>Job</th>
          <th>Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        {$rows_html}
      </tbody>
    </table>
  </form>
</div>
<script>
(function() {
  var checkboxes = document.querySelectorAll('.app-checkbox');
  var selectAll  = document.getElementById('select-all-checkbox');
  var countEl    = document.getElementById('selected-count');
  var controlBar = document.getElementById('bulk-control-bar');
  var applyBtn   = document.getElementById('bulk-apply-btn');
  function updateUI() {
    var checked = document.querySelectorAll('.app-checkbox:checked');
    var n = checked.length;
    countEl.textContent = n + ' selected';
    if (n > 0) {
      controlBar.style.display = '';
      applyBtn.disabled = false;
    } else {
      controlBar.style.display = 'none';
      applyBtn.disabled = true;
    }
  }
  selectAll.addEventListener('change', function() {
    checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
    updateUI();
  });
  checkboxes.forEach(function(cb) {
    cb.addEventListener('change', function() {
      if (!this.checked) { selectAll.checked = false; }
      updateUI();
    });
  });
})();
</script>
HTML;

    $content = ['#markup' => $html, '#cache' => ['max-age' => 0]];
    return $this->wrapWithNavigation($content);
  }

  /**
   * Bulk update application status (POST, CSRF-protected).
   *
   * AC-5: Updates selected applications' status and redirects with confirmation.
   * AC-6: Server-side uid ownership validation (silently skips non-owned IDs).
   * AC-7: Empty selection → 400.
   * AC-8: CSRF absent → 403 (handled by routing requirements).
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\JsonResponse
   */
  public function bulkUpdateStatus(): \Symfony\Component\HttpFoundation\Response {
    $request = $this->requestStack->getCurrentRequest();
    assert($request !== NULL);

    // AC-7: Validate that job_ids is present and non-empty.
    $raw_ids = $request->request->all('job_ids');
    if (empty($raw_ids)) {
      return new \Symfony\Component\HttpFoundation\JsonResponse(
        ['error' => 'No applications selected.'], 400
      );
    }

    // Sanitize IDs to integers.
    $ids = array_filter(array_map('intval', (array) $raw_ids), fn(int $id): bool => $id > 0);
    if (empty($ids)) {
      return new \Symfony\Component\HttpFoundation\JsonResponse(
        ['error' => 'No valid application IDs provided.'], 400
      );
    }

    // Validate status value against whitelist (AC-8 / input validation).
    $new_status = (string) $request->request->get('new_status', '');
    if (!in_array($new_status, self::WORKFLOW_STATUS_ENUM, TRUE)) {
      return new \Symfony\Component\HttpFoundation\JsonResponse(
        ['error' => 'Invalid status value.'], 400
      );
    }

    $uid = (int) $this->currentUser()->id();

    // AC-6: Only update applications belonging to the current user.
    // The WHERE uid = :uid clause ensures cross-user manipulation is impossible.
    $updated = \Drupal::database()->update('jobhunter_applications')
      ->fields(['submission_status' => $new_status])
      ->condition('id', $ids, 'IN')
      ->condition('uid', $uid)
      ->execute();

    $updated_count = (int) ($updated ?? 0);
    $status_label  = ucwords(str_replace('_', ' ', $new_status));

    // Redirect back to the dashboard with a success message.
    \Drupal::messenger()->addStatus(
      $this->t('Updated @n application(s) to @status.', [
        '@n'      => $updated_count,
        '@status' => $status_label,
      ])
    );
    return new \Symfony\Component\HttpFoundation\RedirectResponse(
      \Drupal\Core\Url::fromRoute('job_hunter.applications_dashboard')->toString()
    );
  }

  public function interviewFollowup() {
    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['interview-followup-page']],
      'header' => [
        '#type' => 'html_tag',
        '#tag' => 'h1',
        '#value' => '📅 Interview & Follow-up',
      ],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => 'Track application status, schedule interviews, and manage follow-ups.',
      ],
      'todo' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['alert', 'alert-warning']],
        '#value' => '<strong>TODO:</strong> Implement interview tracking and follow-up management.',
      ],
    ];
    
    return $this->wrapWithNavigation($content);
  }

  /**
   * Step 5: Analytics page.
   *
   * @return array
   *   A renderable array for the analytics page.
   */
  public function analytics() {
    $uid = (int) $this->currentUser()->id();
    $db  = \Drupal::database();

    // ── Aggregate data (SEC-3: all queries scoped to $uid) ──────────────────

    // Total saved jobs for this user (join saved_jobs → job_requirements).
    $saved_count = (int) $db->query(
      "SELECT COUNT(*) FROM {jobhunter_saved_jobs} sj WHERE sj.uid = :uid AND sj.archived = 0",
      [':uid' => $uid]
    )->fetchField();

    // AC-5: empty state.
    if ($saved_count === 0) {
      $discover_url = \Drupal\Core\Url::fromRoute('job_hunter.job_discovery')->toString();
      $content = [
        '#type' => 'container',
        '#attributes' => ['class' => ['analytics-page']],
        'header' => ['#type' => 'html_tag', '#tag' => 'h1', '#value' => '📊 Analytics'],
        'empty' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['analytics-empty-state']],
          '#markup' => '<p>You haven\'t saved any jobs yet. <a href="' . htmlspecialchars($discover_url) . '">Start discovering jobs</a> to see your analytics here.</p>',
        ],
      ];
      return $this->wrapWithNavigation($content);
    }

    // Stage funnel: group by application_status on joined job_requirements.
    $funnel_raw = $db->query(
      "SELECT jr.application_status AS stage, COUNT(*) AS cnt
       FROM {jobhunter_saved_jobs} sj
       JOIN {jobhunter_job_requirements} jr ON sj.job_id = jr.id
       WHERE sj.uid = :uid AND sj.archived = 0
       GROUP BY jr.application_status",
      [':uid' => $uid]
    )->fetchAllKeyed();

    // Canonical funnel order (maps DB values to display labels).
    $funnel_stages = [
      'not_applied'          => 'Not Applied',
      'submitted'            => 'Applied',
      'manual_completed'     => 'Applied (Manual)',
      'confirmed'            => 'Confirmed',
      'interview_scheduled'  => 'Interview Scheduled',
      'interview_completed'  => 'Interview Completed',
      'offer'                => 'Offer',
      'accepted'             => 'Accepted',
      'rejected'             => 'Rejected',
      'closed'               => 'Closed',
    ];

    // Build funnel rows (only show stages that have data + ordered above).
    $funnel_counts = [];
    foreach ($funnel_stages as $key => $label) {
      if (isset($funnel_raw[$key]) && (int) $funnel_raw[$key] > 0) {
        $funnel_counts[$key] = ['label' => $label, 'count' => (int) $funnel_raw[$key]];
      }
    }
    // Append any unknown statuses at end.
    foreach ($funnel_raw as $stage => $cnt) {
      if (!isset($funnel_stages[$stage]) && (int) $cnt > 0) {
        $funnel_counts[$stage] = ['label' => ucwords(str_replace('_', ' ', $stage)), 'count' => (int) $cnt];
      }
    }

    // Total applied count (any status beyond not_applied).
    $total_applied = 0;
    $responded_stages = ['interview_scheduled', 'interview_completed', 'offer', 'accepted', 'rejected', 'confirmed'];
    $responded_count  = 0;
    foreach ($funnel_raw as $stage => $cnt) {
      if ($stage !== 'not_applied') {
        $total_applied += (int) $cnt;
      }
      if (in_array($stage, $responded_stages, TRUE)) {
        $responded_count += (int) $cnt;
      }
    }
    $response_rate = $total_applied > 0 ? round(($responded_count / $total_applied) * 100, 1) : 0;

    // AC-3: source breakdown — per source: total saved, total applied, response rate.
    $source_rows = $db->query(
      "SELECT jr.source,
              COUNT(*) AS total,
              SUM(CASE WHEN jr.application_status != 'not_applied' THEN 1 ELSE 0 END) AS applied,
              SUM(CASE WHEN jr.application_status IN ('interview_scheduled','interview_completed','offer','accepted','rejected','confirmed') THEN 1 ELSE 0 END) AS responded
       FROM {jobhunter_saved_jobs} sj
       JOIN {jobhunter_job_requirements} jr ON sj.job_id = jr.id
       WHERE sj.uid = :uid AND sj.archived = 0
       GROUP BY jr.source",
      [':uid' => $uid]
    )->fetchAll();

    // Weekly activity: count saved jobs created per calendar week (last 8 weeks).
    $eight_weeks_ago = strtotime('-8 weeks');
    $weekly_raw = $db->query(
      "SELECT YEARWEEK(FROM_UNIXTIME(sj.created), 3) AS yw, COUNT(*) AS cnt
       FROM {jobhunter_saved_jobs} sj
       WHERE sj.uid = :uid AND sj.created >= :cutoff
       GROUP BY yw ORDER BY yw ASC",
      [':uid' => $uid, ':cutoff' => $eight_weeks_ago]
    )->fetchAllKeyed();

    // ── Build HTML ───────────────────────────────────────────────────────────

    // Funnel table.
    $funnel_rows_html = '';
    $max_count = max(array_column($funnel_counts, 'count') ?: [1]);
    foreach ($funnel_counts as $row) {
      $bar_pct = (int) round(($row['count'] / $max_count) * 100);
      $funnel_rows_html .= '<tr>
        <td style="padding:6px 12px;white-space:nowrap;">' . htmlspecialchars($row['label']) . '</td>
        <td style="padding:6px 12px;width:60%;">
          <div style="background:#e2e8f0;border-radius:4px;height:18px;position:relative;">
            <div style="background:#4299e1;height:18px;width:' . $bar_pct . '%;border-radius:4px;"></div>
          </div>
        </td>
        <td style="padding:6px 12px;text-align:right;font-weight:bold;">' . $row['count'] . '</td>
      </tr>';
    }

    // Source breakdown table.
    $source_html = '';
    foreach ($source_rows as $row) {
      $src_label = htmlspecialchars(ucwords(str_replace('_', ' ', $row->source ?? 'unknown')));
      $src_rate  = $row->applied > 0 ? round(($row->responded / $row->applied) * 100, 1) : 0;
      $source_html .= '<tr>
        <td style="padding:6px 12px;">' . $src_label . '</td>
        <td style="padding:6px 12px;text-align:center;">' . (int) $row->total . '</td>
        <td style="padding:6px 12px;text-align:center;">' . (int) $row->applied . '</td>
        <td style="padding:6px 12px;text-align:center;">' . $src_rate . '%</td>
      </tr>';
    }

    // Weekly activity (simple bar chart using inline CSS).
    $weekly_html = '';
    if (!empty($weekly_raw)) {
      $max_wk = max($weekly_raw) ?: 1;
      foreach ($weekly_raw as $yw => $cnt) {
        $bar_h = (int) round(((int) $cnt / $max_wk) * 60);
        $year  = (int) substr((string) $yw, 0, 4);
        $week  = (int) substr((string) $yw, 4);
        $weekly_html .= '<div style="display:inline-block;text-align:center;margin:0 4px;vertical-align:bottom;">
          <div style="background:#48bb78;width:28px;height:' . $bar_h . 'px;border-radius:3px 3px 0 0;margin:0 auto;"></div>
          <div style="font-size:0.75em;color:#718096;margin-top:3px;">' . $cnt . '</div>
          <div style="font-size:0.7em;color:#a0aec0;">W' . $week . '</div>
        </div>';
      }
    }
    else {
      $weekly_html = '<p style="color:#718096;font-style:italic;">No activity in the last 8 weeks.</p>';
    }

    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['analytics-page']],
      '#markup' => '
<h1 style="margin-bottom:6px;">📊 Analytics</h1>

<!-- Summary stats -->
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
  <div style="background:#ebf8ff;border-radius:8px;padding:16px 24px;min-width:130px;text-align:center;">
    <div style="font-size:2em;font-weight:bold;color:#2b6cb0;">' . $saved_count . '</div>
    <div style="color:#4a5568;font-size:0.9em;">Jobs Saved</div>
  </div>
  <div style="background:#f0fff4;border-radius:8px;padding:16px 24px;min-width:130px;text-align:center;">
    <div style="font-size:2em;font-weight:bold;color:#276749;">' . $total_applied . '</div>
    <div style="color:#4a5568;font-size:0.9em;">Applications Submitted</div>
  </div>
  <div class="response-rate" style="background:#fffaf0;border-radius:8px;padding:16px 24px;min-width:130px;text-align:center;">
    <div style="font-size:2em;font-weight:bold;color:#c05621;">' . $response_rate . '%</div>
    <div style="color:#4a5568;font-size:0.9em;">Response Rate</div>
  </div>
</div>

<!-- Stage funnel -->
<div class="analytics-funnel" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px;margin-bottom:24px;">
  <h3 style="margin:0 0 14px 0;">📈 Application Funnel</h3>
  <table style="width:100%;border-collapse:collapse;">' . $funnel_rows_html . '</table>
</div>

<!-- Source breakdown -->
<div class="source-breakdown" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px;margin-bottom:24px;">
  <h3 style="margin:0 0 14px 0;">🔍 Response Rate by Source</h3>
  <table style="width:100%;border-collapse:collapse;">
    <thead><tr style="border-bottom:1px solid #e2e8f0;">
      <th style="text-align:left;padding:6px 12px;">Source</th>
      <th style="text-align:center;padding:6px 12px;">Saved</th>
      <th style="text-align:center;padding:6px 12px;">Applied</th>
      <th style="text-align:center;padding:6px 12px;">Response Rate</th>
    </tr></thead>
    <tbody>' . $source_html . '</tbody>
  </table>
</div>

<!-- Weekly activity -->
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:20px;">
  <h3 style="margin:0 0 14px 0;">📅 Weekly Activity (last 8 weeks)</h3>
  <div style="display:flex;align-items:flex-end;gap:0;min-height:80px;">' . $weekly_html . '</div>
</div>
',
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Tokenize a text string into lowercase word tokens for match scoring.
   *
   * Splits on non-alphanumeric characters, lowercases, filters tokens shorter
   * than 3 characters, and removes common English stop words.
   *
   * @param string $text
   *   Raw text to tokenize.
   *
   * @return array
   *   Unique lowercase tokens.
   */
  private function tokenizeText(string $text): array {
    static $stop_words = [
      'and', 'the', 'for', 'with', 'this', 'that', 'have', 'has', 'will',
      'are', 'was', 'were', 'you', 'your', 'our', 'their', 'not', 'but',
      'can', 'all', 'any', 'from', 'use', 'using', 'used', 'also', 'able',
      'work', 'working', 'team', 'must', 'than', 'its', 'etc',
    ];
    $tokens = preg_split('/[^a-zA-Z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
    $tokens = array_filter($tokens, fn($t) => strlen($t) >= 3 && !in_array($t, $stop_words, TRUE));
    return array_values(array_unique($tokens));
  }

  /**
   * Compute match score (0–100) between user skill tokens and a job's text.
   *
   * Score = (skill tokens found in job corpus) / (total skill tokens) × 100.
   * Returns 0 when user has no skill tokens or job has no text (AC-5, AC-6).
   *
   * @param array $user_skill_tokens
   *   Tokenized list of user skills from jobhunter_job_seeker.skills.
   * @param object $job
   *   Job row from getSavedJobs(); expected fields: job_description,
   *   requirements, nice_to_have, skills_required_json.
   *
   * @return int
   *   Score clamped to [0, 100].
   */
  private function computeMatchScore(array $user_skill_tokens, object $job): int {
    if (empty($user_skill_tokens)) {
      return 0;
    }

    // Build job text corpus from available text fields (AC-6: safe on NULL fields).
    $job_text_parts = [
      $job->job_description ?? '',
      $job->requirements ?? '',
      $job->nice_to_have ?? '',
    ];
    // Also extract flat skill names from skills_required_json if present.
    if (!empty($job->skills_required_json)) {
      $skills_data = json_decode($job->skills_required_json, TRUE);
      if (is_array($skills_data)) {
        foreach ($skills_data as $item) {
          if (is_string($item)) {
            $job_text_parts[] = $item;
          }
          elseif (is_array($item) && !empty($item['name'])) {
            $job_text_parts[] = $item['name'];
          }
        }
      }
    }

    $job_token_set = array_flip($this->tokenizeText(implode(' ', $job_text_parts)));

    $matches = 0;
    foreach ($user_skill_tokens as $skill_token) {
      if (isset($job_token_set[$skill_token])) {
        $matches++;
      }
    }

    $raw = (int) round($matches / count($user_skill_tokens) * 100);
    return max(0, min(100, $raw));
  }

  /**
   * Offer comparison page at /jobhunter/offers.
   *
   * Shows all active offers for the current user (AC-2, AC-3).
   */
  public function offersPage(): array {
    $uid = (int) $this->currentUser()->id();
    $db  = \Drupal::database();

    $content = [];

    if (!$db->schema()->tableExists('jobhunter_offers')) {
      $content['notice'] = ['#markup' => '<p>Offer tracking is not yet available.</p>'];
      return $this->wrapWithNavigation($content);
    }

    // Load all offers for this user, joined with saved_jobs + job_requirements.
    $rows = $db->select('jobhunter_offers', 'o')
      ->fields('o')
      ->condition('o.uid', $uid)
      ->orderBy('o.response_deadline', 'ASC')
      ->orderBy('o.created', 'ASC')
      ->execute()
      ->fetchAll();

    $company_name_field = \Drupal::database()->schema()->fieldExists('jobhunter_companies', 'name') ? 'name' : 'company_name';

    // Enrich each offer row with job/company data.
    $offers = [];
    foreach ($rows as $row) {
      $job_data = $db->select('jobhunter_saved_jobs', 'sj')
        ->fields('sj', ['job_id'])
        ->condition('sj.uid', $uid)
        ->condition('sj.id', (int) $row->saved_job_id)
        ->execute()
        ->fetchObject();

      if (!$job_data) {
        continue;
      }
      $job_id = (int) $job_data->job_id;

      $jr = $db->select('jobhunter_job_requirements', 'j')
        ->fields('j', ['id', 'job_title', 'company_id'])
        ->condition('j.id', $job_id)
        ->execute()
        ->fetchObject();

      if (!$jr) {
        continue;
      }

      // Resolve company name.
      $company_name = '';
      if ($jr->company_id) {
        $company_row = $db->select('jobhunter_companies', 'c')
          ->fields('c', [$company_name_field])
          ->condition('c.id', (int) $jr->company_id)
          ->execute()
          ->fetchObject();
        if ($company_row) {
          $company_name = (string) ($company_row->{$company_name_field} ?? '');
        }
      }

      $detail_url = Url::fromRoute('job_hunter.job_view', ['job_id' => $job_id])->toString();

      $offers[] = [
        'company'    => $company_name,
        'role'       => (string) ($jr->job_title ?? ''),
        'salary'     => $row->base_salary !== NULL ? '$' . number_format((int) $row->base_salary) : '—',
        'equity'     => (string) ($row->equity_summary ?? ''),
        'deadline'   => (string) ($row->response_deadline ?? ''),
        'notes'      => (string) ($row->notes ?? ''),
        'detail_url' => $detail_url,
        'job_id'     => $job_id,
      ];
    }

    $count = count($offers);

    $heading = '<h2>My Offers</h2>';
    if ($count === 0) {
      $body = '<p class="offers-empty-state">You currently have 0 active offers. When a saved job status is set to <strong>Offered</strong>, the Offer Details form appears on that job\'s detail view.</p>';
    }
    elseif ($count === 1) {
      $body = '<p class="offers-single-note">You currently have 1 active offer. Add another to compare.</p>';
      $body .= $this->buildOffersTable($offers);
    }
    else {
      $body = '<p class="offers-count-note">You currently have ' . $count . ' active offers.</p>';
      $body .= $this->buildOffersTable($offers);
    }

    $content['offers'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['offers-page']],
      '#markup' => $heading . $body,
    ];

    $content['#attached']['html_head'][] = [
      [
        '#tag' => 'style',
        '#value' => '
          .offers-page { max-width: 1100px; margin: 0 auto; padding: 16px; }
          .offers-page h2 { margin-bottom: 14px; }
          .offers-empty-state, .offers-single-note, .offers-count-note { color: #555; margin-bottom: 18px; }
          .offers-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
          .offers-table th, .offers-table td { padding: 10px 14px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
          .offers-table th { background: #f9fafb; font-weight: 600; font-size: 0.9em; }
          .offers-table td.offers-salary { font-weight: 700; color: #065f46; }
          .offers-table a { color: #2563eb; }
        ',
      ],
      'offers_page_styles',
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Build the HTML comparison table for offers.
   */
  private function buildOffersTable(array $offers): string {
    $html = '<table class="offers-table"><thead><tr>'
      . '<th>Company</th><th>Role</th><th>Base Salary</th><th>Equity / Bonus</th><th>Response Deadline</th><th></th>'
      . '</tr></thead><tbody>';
    foreach ($offers as $o) {
      $html .= '<tr>'
        . '<td>' . htmlspecialchars($o['company']) . '</td>'
        . '<td>' . htmlspecialchars($o['role']) . '</td>'
        . '<td class="offers-salary">' . htmlspecialchars($o['salary']) . '</td>'
        . '<td>' . htmlspecialchars($o['equity']) . '</td>'
        . '<td>' . htmlspecialchars($o['deadline']) . '</td>'
        . '<td><a href="' . htmlspecialchars($o['detail_url']) . '">View Details</a></td>'
        . '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
  }

}
