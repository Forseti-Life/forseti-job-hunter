<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\job_hunter\Service\UserProfileService;
use Drupal\job_hunter\Service\JobSeekerService;

/**
 * Controller for user profile management functionality.
 */
class UserProfileController extends ControllerBase {
  use JobHunterControllerTrait;

  /**
   * Builds a CSRF-protected URL for a fixed path.
   *
   * @param string $path
   *   Internal path beginning with '/'.
   *
   * @return string
   *   URL including a valid token query argument.
   */
  protected function buildCsrfPathUrl(string $path): string {
    $normalized_path = ltrim($path, '/');
    $token = \Drupal::service('csrf_token')->get($normalized_path);

    return Url::fromUserInput($path, [
      'query' => [
        'token' => $token,
      ],
    ])->toString();
  }

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The user profile service.
   *
   * @var \Drupal\job_hunter\Service\UserProfileService
   */
  protected $userProfileService;

  /**
   * The job seeker service.
   *
   * @var \Drupal\job_hunter\Service\JobSeekerService
   */
  protected $jobSeekerService;

  /**
   * The AI API service.
   *
   * @var \Drupal\ai_conversation\Service\AIApiService
   */
  protected $aiApiService;

  /**
   * Constructs a new UserProfileController object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\job_hunter\Service\UserProfileService $user_profile_service
   *   The user profile service.
   * @param \Drupal\job_hunter\Service\JobSeekerService $job_seeker_service
   *   The job seeker service.
   * @param \Drupal\ai_conversation\Service\AIApiService $ai_api_service
   *   The AI API service.
   */
  public function __construct(AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, UserProfileService $user_profile_service, JobSeekerService $job_seeker_service, $ai_api_service = NULL) {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->userProfileService = $user_profile_service;
    $this->jobSeekerService = $job_seeker_service;
    $this->aiApiService = $ai_api_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Check if ai_conversation service is available
    $ai_service = NULL;
    if ($container->has('ai_conversation.ai_api_service')) {
      $ai_service = $container->get('ai_conversation.ai_api_service');
    }
    
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('job_hunter.user_profile_service'),
      $container->get('job_hunter.job_seeker_service'),
      $ai_service
    );
  }

  /**
   * Displays the user profile dashboard.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   *
   * @return array
   *   A render array for the profile dashboard.
   */
  public function dashboard($user = NULL) {
    // Load the user entity - either specified user or current user
    $uid = $user ? $user->id() : $this->currentUser->id();
    $user_entity = $user ?: User::load($uid);

    if (!$user_entity) {
      $this->messenger()->addError($this->t('User not found.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Check access - users can only view their own profile unless admin
    if ($uid != $this->currentUser->id() && !$this->currentUser->hasPermission('administer users')) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }

    $content = [];

    // Page header
    $content['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['profile-dashboard-header']],
    ];

    $content['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('My Job Application Profile'),
      '#attributes' => ['class' => ['profile-title']],
    ];

    // Profile completeness widget
    $completeness = $this->userProfileService->calculateProfileCompleteness($user_entity);
    $content['completeness'] = $this->buildCompletenessWidget($user_entity, $completeness);

    // Quick stats
    $content['stats'] = $this->buildProfileStats($user_entity);

    // Profile sections summary
    $content['sections'] = $this->buildProfileSections($user_entity);

    // Actions
    $content['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['profile-actions']],
    ];

    $content['actions']['edit_profile'] = [
      '#type' => 'link',
      '#title' => $this->t('Edit Profile'),
      '#url' => Url::fromRoute('job_hunter.user_profile_edit'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    $content['actions']['view_applications'] = [
      '#type' => 'link',
      '#title' => $this->t('View My Applications'),
      '#url' => Url::fromRoute('job_hunter.dashboard'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    // Add CSS and JS
    $content['#attached']['library'][] = 'job_hunter/user_profile';

    // Use custom template for professional styling
    $content['#theme'] = 'user_profile_dashboard';
    $content['#user'] = $user_entity;

    return $this->wrapWithNavigation($content, ['job_hunter/user_profile']);
  }

  /**
   * Builds the profile completeness widget.
   *
   * @param \Drupal\user\Entity\User $user_entity
   *   The user entity.
   * @param int $completeness
   *   The completeness percentage.
   *
   * @return array
   *   Render array for the completeness widget.
   */
  protected function buildCompletenessWidget(User $user_entity, $completeness) {
    $status_class = 'low';
    $status_text = $this->t('Getting Started');
    
    if ($completeness >= 70) {
      $status_class = 'high';
      $status_text = $this->t('Profile Complete');
    } elseif ($completeness >= 40) {
      $status_class = 'medium';
      $status_text = $this->t('Almost There');
    }

    $widget = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['profile-completeness-widget', "completeness-{$status_class}"],
      ],
    ];

    $widget['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Profile Completeness'),
      '#attributes' => ['class' => ['widget-title']],
    ];

    $widget['progress_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['progress-container']],
    ];

    $widget['progress_container']['progress_bar'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['progress-bar'],
      ],
    ];

    $widget['progress_container']['progress_bar']['fill'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['progress-fill'],
        'style' => "width: {$completeness}%",
      ],
    ];

    $widget['progress_container']['progress_text'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('@percent% Complete', ['@percent' => $completeness]),
      '#attributes' => ['class' => ['progress-text']],
    ];

    $widget['status'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $status_text,
      '#attributes' => ['class' => ['completeness-status']],
    ];

    // Add recommendations for improvement
    if ($completeness < 70) {
      $missing_fields = $this->userProfileService->getMissingFieldRecommendations($user_entity);
      if (!empty($missing_fields)) {
        $widget['recommendations'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['completeness-recommendations']],
        ];
        
        $widget['recommendations']['title'] = [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Complete these to reach 70%:'),
          '#attributes' => ['class' => ['recommendations-title']],
        ];

        $widget['recommendations']['list'] = [
          '#theme' => 'item_list',
          '#items' => array_map(function($rec) { return $rec['impact']; }, $missing_fields),
          '#attributes' => ['class' => ['recommendations-list']],
        ];
      }
    }

    return $widget;
  }

  /**
   * Builds profile statistics.
   *
   * @param \Drupal\user\Entity\User $user_entity
   *   The user entity.
   *
   * @return array
   *   Render array for profile stats.
   */
  protected function buildProfileStats(User $user_entity) {
    $stats = [
      '#type' => 'container',
      '#attributes' => ['class' => ['profile-stats']],
    ];

    $stats['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Profile Overview'),
      '#attributes' => ['class' => ['section-title']],
    ];

    $stats['grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stats-grid']],
    ];

    // Last updated
    $last_update = 'Never';
    if ($user_entity->hasField('field_last_profile_update') && !$user_entity->get('field_last_profile_update')->isEmpty()) {
      $last_update_timestamp = $user_entity->get('field_last_profile_update')->value;
      $last_update = \Drupal::service('date.formatter')->format(strtotime($last_update_timestamp), 'short');
    }

    $stats['grid']['last_updated'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-item']],
    ];
    $stats['grid']['last_updated']['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Last Updated'),
      '#attributes' => ['class' => ['stat-label']],
    ];
    $stats['grid']['last_updated']['value'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $last_update,
      '#attributes' => ['class' => ['stat-value']],
    ];

    // Work Authorization
    $work_auth = $this->t('Not specified');
    if ($user_entity->hasField('field_work_authorization') && !$user_entity->get('field_work_authorization')->isEmpty()) {
      $work_auth_value = $user_entity->get('field_work_authorization')->value;
      $work_auth_options = [
        'us_citizen' => $this->t('US Citizen'),
        'permanent_resident' => $this->t('Permanent Resident'),
        'h1b' => $this->t('Work Visa (H1B)'),
        'f1' => $this->t('Student Visa (F1)'),
        'visa_required' => $this->t('Visa Sponsorship Required'),
        'other' => $this->t('Other'),
      ];
      $work_auth = $work_auth_options[$work_auth_value] ?? $work_auth_value;
    }

    $stats['grid']['work_auth'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-item']],
    ];
    $stats['grid']['work_auth']['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Work Authorization'),
      '#attributes' => ['class' => ['stat-label']],
    ];
    $stats['grid']['work_auth']['value'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $work_auth,
      '#attributes' => ['class' => ['stat-value']],
    ];

    // Experience Years
    $experience = $this->t('Not specified');
    if ($user_entity->hasField('field_experience_years') && !$user_entity->get('field_experience_years')->isEmpty()) {
      $years = $user_entity->get('field_experience_years')->value;
      $experience = $this->formatPlural($years, '1 year', '@count years');
    }

    $stats['grid']['experience'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-item']],
    ];
    $stats['grid']['experience']['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Experience'),
      '#attributes' => ['class' => ['stat-label']],
    ];
    $stats['grid']['experience']['value'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $experience,
      '#attributes' => ['class' => ['stat-value']],
    ];

    // Remote Preference
    $remote_pref = $this->t('Not specified');
    if ($user_entity->hasField('field_remote_preference') && !$user_entity->get('field_remote_preference')->isEmpty()) {
      $remote_value = $user_entity->get('field_remote_preference')->value;
      $remote_options = [
        'remote' => $this->t('Remote'),
        'hybrid' => $this->t('Hybrid'),
        'onsite' => $this->t('On-site'),
        'no_preference' => $this->t('No Preference'),
      ];
      $remote_pref = $remote_options[$remote_value] ?? $remote_value;
    }

    $stats['grid']['remote_pref'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-item']],
    ];
    $stats['grid']['remote_pref']['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Remote Preference'),
      '#attributes' => ['class' => ['stat-label']],
    ];
    $stats['grid']['remote_pref']['value'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $remote_pref,
      '#attributes' => ['class' => ['stat-value']],
    ];

    return $stats;
  }

  /**
   * Builds profile sections summary.
   *
   * @param \Drupal\user\Entity\User $user_entity
   *   The user entity.
   *
   * @return array
   *   Render array for profile sections.
   */
  protected function buildProfileSections(User $user_entity) {
    $sections = [
      '#type' => 'container',
      '#attributes' => ['class' => ['profile-sections']],
    ];

    $sections['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Profile Sections'),
      '#attributes' => ['class' => ['section-title']],
    ];

    // Core Information
    $has_resume = $user_entity->hasField('field_resume_file') && !$user_entity->get('field_resume_file')->isEmpty();
    $has_summary = $user_entity->hasField('field_professional_summary') && !$user_entity->get('field_professional_summary')->isEmpty();
    $has_skills = $user_entity->hasField('field_skills_summary') && !$user_entity->get('field_skills_summary')->isEmpty();
    
    $core_completed = 0;
    if ($has_resume) $core_completed++;
    if ($has_summary) $core_completed++;
    if ($has_skills) $core_completed++;

    $sections['core'] = $this->buildSectionSummary(
      $this->t('Core Professional Information'),
      $this->t('Resume, professional summary, and skills'),
      $core_completed,
      3,
      'core'
    );

    // Employment Preferences
    $employment_fields = ['field_work_authorization', 'field_salary_expectation_min', 'field_remote_preference'];
    $employment_completed = 0;
    foreach ($employment_fields as $field) {
      if ($user_entity->hasField($field) && !$user_entity->get($field)->isEmpty()) {
        $employment_completed++;
      }
    }

    $sections['employment'] = $this->buildSectionSummary(
      $this->t('Employment Preferences'),
      $this->t('Work authorization, salary expectations, and preferences'),
      $employment_completed,
      count($employment_fields),
      'employment'
    );

    // Online Presence
    $online_fields = ['field_linkedin_url', 'field_portfolio_url', 'field_github_url'];
    $online_completed = 0;
    foreach ($online_fields as $field) {
      if ($user_entity->hasField($field) && !$user_entity->get($field)->isEmpty()) {
        $uri = $user_entity->get($field)->uri;
        if (!empty($uri)) {
          $online_completed++;
        }
      }
    }

    $sections['online'] = $this->buildSectionSummary(
      $this->t('Online Presence'),
      $this->t('LinkedIn, portfolio, and GitHub profiles'),
      $online_completed,
      count($online_fields),
      'online'
    );

    return $sections;
  }

  /**
   * Builds a section summary widget.
   *
   * @param string $title
   *   The section title.
   * @param string $description
   *   The section description.
   * @param int $completed
   *   Number of completed items.
   * @param int $total
   *   Total number of items.
   * @param string $section_key
   *   The section key for CSS classes.
   *
   * @return array
   *   Render array for the section summary.
   */
  protected function buildSectionSummary($title, $description, $completed, $total, $section_key) {
    $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;
    $status = $percentage == 100 ? 'complete' : ($percentage > 0 ? 'partial' : 'empty');

    $section = [
      '#type' => 'container',
      '#attributes' => ['class' => ['section-summary', "section-{$section_key}", "status-{$status}"]],
    ];

    $section['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $title,
      '#attributes' => ['class' => ['section-summary-title']],
    ];

    $section['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $description,
      '#attributes' => ['class' => ['section-summary-description']],
    ];

    $section['progress'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('@completed of @total completed (@percent%)', [
        '@completed' => $completed,
        '@total' => $total,
        '@percent' => $percentage,
      ]),
      '#attributes' => ['class' => ['section-summary-progress']],
    ];

    $section['progress_bar'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['section-progress-bar']],
    ];

    $section['progress_bar']['fill'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['section-progress-fill'],
        'style' => "width: {$percentage}%",
      ],
    ];

    return $section;
  }

  /**
   * Redirects to current user's profile dashboard.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the current user's profile.
   */
  public function myProfile() {
    return $this->redirect('job_hunter.user_profile_dashboard');
  }

  /**
   * Redirects to current user's profile edit form.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the current user's profile edit form.
   */
  public function myProfileEdit() {
    return $this->redirect('job_hunter.user_profile_edit');
  }

  /**
   * Displays the job seeker profile in view mode (not edit mode).
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   *
   * @return array
   *   The render array for the profile view.
   */
  public function viewJobSeekerProfile() {
    $user = User::load($this->currentUser->id());
    $profile = $this->jobSeekerService->loadByUserId($user->id());

    if (!$profile) {
      // If no profile exists, redirect to create one
      return $this->redirect('job_hunter.user_profile_edit');
    }

    // Parse consolidated JSON for display
    $consolidated = [];
    if (!empty($profile->consolidated_profile_json)) {
      $consolidated = json_decode($profile->consolidated_profile_json, TRUE) ?: [];
    }
    
    // Build the profile view
    $content = [
      '#theme' => 'job_seeker_profile',
      '#profile' => $profile,
      '#consolidated' => $consolidated,
      '#user' => $user,
      '#edit_url' => Url::fromRoute('job_hunter.user_profile_edit'),
    ];
    
    return $this->wrapWithNavigation($content);
  }

  /**
   * Redirect to profile edit page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response to edit page.
   */
  public function redirectToEdit() {
    return $this->redirect('job_hunter.user_profile_edit');
  }

  /**
   * Start job discovery page - shows company selection.
   *
   * @return array
   *   The render array for the company selection page.
   */
  public function startJobDiscovery() {
    $user = User::load($this->currentUser->id());
    
    // Load user's job seeker profile
    $profile = $this->jobSeekerService->loadByUserId($user->id());
    
    if (!$profile) {
      $this->messenger()->addError($this->t('Please complete your job seeker profile first before starting job discovery.'));
      return $this->redirect('job_hunter.user_profile_edit');
    }

    // Load available companies
    $companies = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'company',
      'status' => 1, // Published
    ]);

    // Extract keywords from profile for preview
    $keywords = !empty($profile->skills) ? $profile->skills : [];
    
    // Build the render array for the company selection page
    $content = [
      '#theme' => 'job_discovery_company_selection',
      '#user' => $user,
      '#profile' => $profile,
      '#companies' => $companies,
      '#keywords' => $keywords,
      '#attached' => [
        'library' => [
          'job_hunter/job_discovery',
          'job_hunter/job-hunter-home',
        ],
      ],
    ];
    
    return $this->wrapWithNavigation($content, ['job_hunter/job_discovery']);
  }

  /**
   * Company-specific job discovery page.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   * @param \Drupal\node\Entity\Node $company
   *   The company entity.
   *
   * @return array
   *   The render array for the company job discovery page.
   */
  public function companyJobDiscovery(User $user, $company) {
    // Check access - user can only access their own job discovery
    if ($user->id() != $this->currentUser->id() && !$this->currentUser->hasPermission('administer users')) {
      throw new AccessDeniedHttpException();
    }

    // Load company entity
    $company_entity = $this->entityTypeManager->getStorage('node')->load($company);
    if (!$company_entity || $company_entity->bundle() !== 'company') {
      throw new NotFoundHttpException();
    }

    // Load user's job seeker profile
    $profile = $this->jobSeekerService->loadByUserId($user->id());
    
    if (!$profile) {
      $this->messenger()->addError($this->t('Please complete your job seeker profile first before starting job discovery.'));
      return $this->redirect('job_hunter.user_profile_edit');
    }

    // Load job opportunities for this specific company
    $job_opportunities = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'job_posting',
      'status' => 1, // Published
      'field_company_ref' => $company_entity->id(),
    ]);
    
    // Build the render array for the company-specific job discovery page
    $content = [
      '#theme' => 'job_discovery_company_search',
      '#user' => $user,
      '#company' => $company_entity,
      '#job_opportunities' => $job_opportunities,
      '#attached' => [
        'library' => [
          'job_hunter/job_discovery',
          'job_hunter/job-hunter-home',
        ],
      ],
    ];
    
    return $this->wrapWithNavigation($content, ['job_hunter/job_discovery']);
  }

  /**
   * AJAX endpoint for job discovery search.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with job search results.
   */
  public function jobDiscoverySearch() {
    try {
      $request = \Drupal::request();
      $user_id = $request->request->get('user_id');
      $company_id = $request->request->get('company_id');
      
      // Debug logging
      \Drupal::logger('job_hunter')->info('Job discovery search started for user @user_id, company @company_id', [
        '@user_id' => $user_id,
        '@company_id' => $company_id,
      ]);
      
      if (!$user_id || !is_numeric($user_id)) {
        \Drupal::logger('job_hunter')->error('Invalid user ID: @user_id', [
          '@user_id' => $user_id,
        ]);
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'Invalid user ID',
        ], 400);
      }

      if (!$company_id || !is_numeric($company_id)) {
        \Drupal::logger('job_hunter')->error('Invalid company ID: @company_id', [
          '@company_id' => $company_id,
        ]);
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'Invalid company ID',
        ], 400);
      }

      // Load user and company
      $user = \Drupal\user\Entity\User::load($user_id);
      if (!$user) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'User not found',
        ], 404);
      }

      $company = $this->entityTypeManager->getStorage('node')->load($company_id);
      if (!$company || $company->bundle() !== 'company') {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'Company not found',
        ], 404);
      }
      
      // Load job seeker profile
      $profile_storage = $this->entityTypeManager->getStorage('profile');
      $profiles = $profile_storage->loadByProperties([
        'uid' => $user->id(),
        'type' => 'jobhunter_job_seeker',
      ]);
      
      $profile = reset($profiles);
      $keywords = [];
      
      if ($profile) {
        // Extract keywords from profile
        $keywords = $this->extractKeywordsFromProfile($profile);
      }
      
      // If no keywords found, use default ones for testing
      if (empty($keywords)) {
        $keywords = ['Data Science', 'Analytics', 'Machine Learning'];
        \Drupal::logger('job_hunter')->info('Using default keywords for testing: @keywords', [
          '@keywords' => implode(', ', $keywords),
        ]);
      }
      
      // Debug log the final keywords being used
      \Drupal::logger('job_hunter')->info('Final keywords being passed to scraping service: @keywords', [
        '@keywords' => print_r($keywords, true),
      ]);
      
      // Determine which scraping service to use based on company
      $company_name = strtolower($company->getTitle());
      $jobs = [];
      
      if ($company_name === 'abbvie') {
        // Use AbbVie scraping service
        $scraping_service = \Drupal::service('job_hunter.abbvie_job_scraping_service');
        $jobs = $scraping_service->searchJobs($keywords, [
          'company' => 'abbvie',
        ]);
      } else {
        // For other companies, return a message indicating scraping is not yet implemented
        \Drupal::logger('job_hunter')->info('Job scraping not yet implemented for company: @company', [
          '@company' => $company->getTitle(),
        ]);
        
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'jobs' => [],
          'keywords_used' => $keywords,
          'total_found' => 0,
          'message' => 'Job scraping for ' . $company->getTitle() . ' is coming soon! Currently only AbbVie is supported.',
        ]);
      }
      
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'jobs' => $jobs,
        'keywords_used' => $keywords,
        'total_found' => count($jobs),
      ]);
      
    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Job discovery search error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'error' => 'Search failed: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Extract keywords from job seeker profile.
   *
   * @param \Drupal\profile\Entity\Profile $profile
   *   The job seeker profile.
   *
   * @return array
   *   Array of keywords extracted from profile fields.
   */
  private function extractKeywordsFromProfile($profile) {
    $keywords = [];
    
    // Extract from various fields that contain relevant keywords
    // Using actual field names from the job seeker profile
    $keyword_fields = [
      'field_target_job_titles',        // "Desired job titles and roles"
      'field_keywords_interested',      // "Job Search Keywords"
      'field_skills_summary',           // Skills summary
      'field_professional_summary',     // Professional summary
      'field_certifications',           // Certifications
    ];
    
    foreach ($keyword_fields as $field_name) {
      if ($profile->hasField($field_name) && !$profile->get($field_name)->isEmpty()) {
        $field_value = $profile->get($field_name)->value;
        if (!empty($field_value)) {
          // Strip HTML tags and decode HTML entities
          $field_value = html_entity_decode(strip_tags($field_value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
          
          // Split by common delimiters and clean up
          $field_keywords = preg_split('/[,;\n\r]+/', $field_value);
          foreach ($field_keywords as $keyword) {
            $keyword = trim($keyword);
            // Remove surrounding quotes
            $keyword = trim($keyword, '"\'');
            if (strlen($keyword) > 2) { // Only include meaningful keywords
              $keywords[] = $keyword;
            }
          }
        }
      }
    }
    
    // Remove duplicates and return
    return array_unique($keywords);
  }

  /**
   * Save a job from job discovery to job_posting content type.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success/error status.
   */
  public function saveJob() {
    try {
      // Check if user is authenticated
      if ($this->currentUser->isAnonymous()) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'User must be logged in to save jobs',
        ], 403);
      }

      $request = \Drupal::request();
      $job_data = json_decode($request->getContent(), TRUE);
      
      if (!$job_data || empty($job_data['jobId'])) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'Invalid job data provided',
        ], 400);
      }

      // Check if job already exists to avoid duplicates
      $existing_job = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties([
          'type' => 'job_posting',
          'field_job_id' => $job_data['jobId'],
          'uid' => $this->currentUser->id(),
        ]);

      if (!empty($existing_job)) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'success' => true,
          'message' => 'Job already saved to your dashboard',
          'node_id' => reset($existing_job)->id(),
        ]);
      }

      // Create new job posting node
      $node = \Drupal\node\Entity\Node::create([
        'type' => 'job_posting',
        'title' => $job_data['title'],
        'uid' => $this->currentUser->id(),
      ]);

      // Map job discovery data to job posting fields
      if (!empty($job_data['title'])) {
        $node->set('field_job_title', $job_data['title']);
      }

      if (!empty($job_data['jobId'])) {
        $node->set('field_job_id', $job_data['jobId']);
      }

      if (!empty($job_data['location'])) {
        $node->set('field_location', $job_data['location']);
      }

      if (!empty($job_data['description'])) {
        $node->set('field_job_description', [
          'value' => $job_data['description'],
          'format' => 'basic_html',
        ]);
      }

      if (!empty($job_data['url'])) {
        $node->set('field_job_url', [
          'uri' => $job_data['url'],
          'title' => 'View Job at AbbVie',
        ]);
      }

      // Map additional fields if available
      if (!empty($job_data['jobType'])) {
        $node->set('field_employment_type', $job_data['jobType']);
      }

      if (!empty($job_data['experienceLevel'])) {
        $node->set('field_experience_level', $job_data['experienceLevel']);
      }

      // Set posting date to current date
      $node->set('field_posting_date', date('Y-m-d\TH:i:s'));

      // Set job status to saved
      $node->set('field_job_status', 'saved');

      // Save the node
      $node->save();

      \Drupal::logger('job_hunter')->info('Job saved: @title (@job_id) for user @uid', [
        '@title' => $job_data['title'],
        '@job_id' => $job_data['jobId'],
        '@uid' => $this->currentUser->id(),
      ]);

      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => true,
        'message' => 'Job saved to your dashboard successfully',
        'node_id' => $node->id(),
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Error saving job: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'error' => 'Failed to save job: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Display user's job search profile dashboard.
   *
   * @return array
   *   The render array for the profile dashboard.
   */
  public function profileDashboard() {
    // Get current user
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    if (!$user) {
      throw new AccessDeniedHttpException();
    }

    /** @var \Drupal\job_hunter\Service\ProfileCompletenessService $completeness_service */
    $completeness_service = \Drupal::service('job_hunter.profile_completeness_service');
    /** @var \Drupal\job_hunter\Service\UserJobProfileService $profile_service */
    $profile_service = \Drupal::service('job_hunter.user_job_profile_service');

    $uid = (int) $user->id();
    $completeness = $completeness_service->calculate($uid);
    $missing_fields = $completeness_service->getMissingFields($uid);
    $is_complete = $completeness >= 100;

    // Keep legacy validation_errors for backwards-compat with any other callers.
    $validation_errors = $profile_service->validateProfile($user);
    $profile_summary = $profile_service->getProfileSummary($user);

    // Build render array
    $build = [];

    // Profile completeness section
    $build['completeness'] = [
      '#theme' => 'profile_completeness',
      '#completeness' => $completeness,
      '#is_complete' => $is_complete,
      '#validation_errors' => $validation_errors,
      '#missing_fields' => $missing_fields,
    ];

    // Profile summary
    $build['summary'] = [
      '#theme' => 'profile_summary',
      '#summary' => $profile_summary,
      '#user' => $user,
    ];

    // Action buttons
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['profile-actions']],
    ];

    $build['actions']['edit'] = [
      '#type' => 'link',
      '#title' => t('Edit Profile'),
      '#url' => Url::fromRoute('entity.user.edit_form', ['user' => $user->id()]),
      '#attributes' => ['class' => ['button', 'button-primary']],
    ];

    if ($is_complete) {
      $build['actions']['browse_jobs'] = [
        '#type' => 'link',
        '#title' => t('Browse Available Jobs'),
        '#url' => Url::fromRoute('job_hunter.job_browser'),
        '#attributes' => ['class' => ['button', 'button-primary']],
      ];
    }

    $build['#cache'] = [
      'max-age' => 0,
    ];

    return $build;
  }

  /**
   * Tailor resume for a specific job opportunity.
   *
   * @param int $job
   *   The job requirement ID from jobhunter_job_requirements table.
   *
   * @return array
   *   The render array for the tailor resume page.
   */
  public function tailorResume($job) {
    $database = \Drupal::database();
    
    // Get current user
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    // Load job from custom table
    $job_data = $database->select('jobhunter_job_requirements', 'j')
      ->fields('j')
      ->condition('id', $job)
      ->execute()
      ->fetchObject();
    
    if (!$job_data) {
      throw new NotFoundHttpException();
    }
    
    // Parse JSON data
    $extracted = $job_data->extracted_json ? json_decode($job_data->extracted_json, TRUE) : [];
    $skills = $job_data->skills_required_json ? json_decode($job_data->skills_required_json, TRUE) : [];
    $keywords = $job_data->keywords_json ? json_decode($job_data->keywords_json, TRUE) : [];

    // Load user's tailored resume for this job (if exists)
    $tailored_record = $database->select('jobhunter_tailored_resumes', 'tr')
      ->fields('tr')
      ->condition('uid', $user->id())
      ->condition('job_id', $job)
      ->execute()
      ->fetchObject();
    
    $tailored = $tailored_record && $tailored_record->tailored_resume_json 
      ? json_decode($tailored_record->tailored_resume_json, TRUE) 
      : NULL;
    $tailoring_status = $tailored_record ? $tailored_record->tailoring_status : 'pending';
    
    // Get actual queue status by checking queue table, suspended queue, and database
    $queue_status = $this->getActualQueueStatus($user->id(), $job);
    
    // Update status in database if it's out of sync
    if ($queue_status['should_update_db']) {
      $database->update('jobhunter_tailored_resumes')
        ->fields(['tailoring_status' => $queue_status['status'], 'updated' => time()])
        ->condition('uid', $user->id())
        ->condition('job_id', $job)
        ->execute();
      $tailoring_status = $queue_status['status'];
      
      \Drupal::logger('job_hunter')->notice('Synced tailoring status from @old to @new for user @uid job @job (in_queue: @q, suspended: @s)', [
        '@old' => $queue_status['db_status'],
        '@new' => $tailoring_status,
        '@uid' => $user->id(),
        '@job' => $job,
        '@q' => $queue_status['in_queue'] ? 'yes' : 'no',
        '@s' => $queue_status['suspended'] ? 'yes' : 'no',
      ]);
    }
    
    // Get PDF info
    $pdf_path = $tailored_record && !empty($tailored_record->pdf_path) ? $tailored_record->pdf_path : NULL;
    $pdf_generated = $tailored_record && !empty($tailored_record->pdf_generated) ? $tailored_record->pdf_generated : NULL;

    // Get PDF history for this job
    $pdf_history = $database->select('jobhunter_pdf_history', 'ph')
      ->fields('ph')
      ->condition('uid', $user->id())
      ->condition('job_id', $job)
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll();

    // Load user's job seeker profile from jobhunter_job_seeker table
    $job_seeker_profile = $database->select('jobhunter_job_seeker', 'js')
      ->fields('js')
      ->condition('uid', $user->id())
      ->execute()
      ->fetchObject();
    
    if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
      $this->messenger()->addError($this->t('Please complete your job seeker profile first before tailoring your resume.'));
      return $this->redirect('job_hunter.user_job_seeker_view');
    }
    
    $profile_json = json_decode($job_seeker_profile->consolidated_profile_json, TRUE) ?: [];

    // Calculate skills gap - find job skills not in user's profile
    $skills_gap = $this->calculateSkillsGap($skills, $profile_json);

    // Load existing tailoring feedback (if any) for pre-population.
    $tailored_resume_id = $tailored_record ? (int) $tailored_record->id : 0;
    $existing_feedback = NULL;
    if ($tailored_resume_id) {
      $existing_feedback = $database->select('jobhunter_tailoring_feedback', 'tf')
        ->fields('tf', ['rating', 'note'])
        ->condition('tf.uid', $user->id())
        ->condition('tf.tailored_resume_id', $tailored_resume_id)
        ->execute()
        ->fetchObject();
    }
    $feedback_save_url = \Drupal\Core\Url::fromRoute('job_hunter.tailoring_feedback_save')->toString();
    $feedback_csrf_token = \Drupal::csrfToken()->get('jobhunter/tailor-feedback');

    // Build the render array for the tailor resume page
    $content = [
      '#theme' => 'tailor_resume',
      '#user' => $user,
      '#profile' => $job_seeker_profile,
      '#profile_json' => $profile_json,
      '#job' => $job_data,
      '#job_id' => $job,
      '#job_extracted' => $extracted,
      '#job_skills' => $skills,
      '#job_keywords' => $keywords,
      '#skills_gap' => $skills_gap,
      '#tailored_resume' => $tailored,
      '#tailoring_status' => $tailoring_status,
      '#pdf_path' => $pdf_path,
      '#pdf_generated' => $pdf_generated,
      '#pdf_history' => $pdf_history,
      '#tailored_resume_id' => $tailored_resume_id,
      '#feedback_rating' => $existing_feedback ? $existing_feedback->rating : '',
      '#feedback_note' => $existing_feedback ? $existing_feedback->note : '',
      '#feedback_save_url' => $feedback_save_url,
      '#feedback_csrf_token' => $feedback_csrf_token,
      '#attached' => [
        'library' => [
          'job_hunter/tailor_resume',
        ],
        'drupalSettings' => [
          'jobHunterTailorResume' => [
            'ajaxUrl' => $this->buildCsrfPathUrl('/jobhunter/tailor-resume/ajax'),
            'statusUrl' => Url::fromRoute('job_hunter.tailor_resume_status_ajax')->toString(),
            'refreshSkillsGapUrl' => $this->buildCsrfPathUrl('/jobhunter/tailor-resume/refresh-skills-gap'),
            'addSkillUrl' => $this->buildCsrfPathUrl('/jobhunter/profile/add-skill'),
          ],
        ],
      ],
    ];

    return $this->wrapWithNavigation($content, ['job_hunter/tailor_resume']);
  }

  /**
   * AJAX endpoint that generates a tailored response with tailored resume.
   */
  public function tailorResumeAjax() {
    try {
      $request = \Drupal::request();
      $job_id = $request->request->get('job_id');
      $force = $request->request->get('force', 0);
      $user_id = $this->currentUser->id();
      $database = \Drupal::database();
      $cover_letter_table_exists = $database->schema()->tableExists('jobhunter_cover_letters');
      
      // Load job from custom table
      $job_data = $database->select('jobhunter_job_requirements', 'j')
        ->fields('j')
        ->condition('id', $job_id)
        ->execute()
        ->fetchObject();
      
      if (!$job_data) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'Job not found',
        ], 400);
      }

      // Load user's job seeker profile from jobhunter_job_seeker table
      $job_seeker_profile = $database->select('jobhunter_job_seeker', 'js')
        ->fields('js')
        ->condition('uid', $user_id)
        ->execute()
        ->fetchObject();
      
      if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'Profile not found. Please complete your job seeker profile first.',
        ], 400);
      }

      // Check if already completed (skip if force regenerate)
      $existing = $database->select('jobhunter_tailored_resumes', 'tr')
        ->fields('tr')
        ->condition('uid', $user_id)
        ->condition('job_id', $job_id)
        ->execute()
        ->fetchObject();

      if (!$force && $existing && $existing->tailoring_status === 'completed' && !empty($existing->tailored_resume_json)) {
        $tailored = json_decode($existing->tailored_resume_json, TRUE);
        $extracted = $job_data->extracted_json ? json_decode($job_data->extracted_json, TRUE) : [];
        $job_title = $extracted['position']['title'] ?? $extracted['job_title'] ?? 'this position';
        
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'success' => TRUE,
          'status' => 'completed',
          'message' => "Tailored resume already exists for {$job_title}!",
          'tailored_resume' => $tailored,
        ]);
      }

      // Check if already processing (don't allow regenerate while processing)
      if ($existing && $existing->tailoring_status === 'processing') {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'success' => TRUE,
          'status' => 'processing',
          'message' => 'Resume tailoring is already in progress. Please wait...',
        ]);
      }

      // Parse JSON data for queue payload
      $profile = json_decode($job_seeker_profile->consolidated_profile_json, TRUE) ?: [];
      
      // Get cover letter template from profile
      $cover_letter_template = '';
      if (isset($profile['job_search_preferences']['cover_letter_template'])) {
        $cover_letter_template = $profile['job_search_preferences']['cover_letter_template'];
      }

      $resume_already_queued = $this->hasQueuedTailoringItem('job_hunter_resume_tailoring', $user_id, (int) $job_id);
      $cover_already_queued = $cover_letter_table_exists
        ? $this->hasQueuedTailoringItem('job_hunter_cover_letter_tailoring', $user_id, (int) $job_id)
        : FALSE;

      // Queue the resume tailoring job for background processing
      if (!$resume_already_queued) {
        $resume_queue = \Drupal::queue('job_hunter_resume_tailoring');
        $resume_queue->createItem([
          'uid' => $user_id,
          'job_id' => $job_id,
          'profile_json' => $profile,
          'job_data' => [
            'extracted_json' => $job_data->extracted_json,
            'skills_required_json' => $job_data->skills_required_json,
            'keywords_json' => $job_data->keywords_json,
            'raw_posting_text' => $job_data->raw_posting_text ?? '',
          ],
        ]);
      }
      
      // Queue the cover letter generation job if table exists.
      if ($cover_letter_table_exists) {
        if (!$cover_already_queued) {
          $cover_queue = \Drupal::queue('job_hunter_cover_letter_tailoring');
          $cover_queue->createItem([
            'uid' => $user_id,
            'job_id' => $job_id,
            'profile_json' => $profile,
            'cover_letter_template' => $cover_letter_template,
            'job_data' => [
              'extracted_json' => $job_data->extracted_json,
              'skills_required_json' => $job_data->skills_required_json,
              'keywords_json' => $job_data->keywords_json,
              'raw_posting_text' => $job_data->raw_posting_text ?? '',
            ],
          ]);
        }
      }

      // Create/update pending record for resume
      $now = \Drupal::time()->getRequestTime();
      if ($existing) {
        $database->update('jobhunter_tailored_resumes')
          ->fields([
            'tailoring_status' => 'queued',
            'updated' => $now,
          ])
          ->condition('id', $existing->id)
          ->execute();
      }
      else {
        $database->insert('jobhunter_tailored_resumes')
          ->fields([
            'uid' => $user_id,
            'job_id' => $job_id,
            'tailoring_status' => 'queued',
            'created' => $now,
            'updated' => $now,
          ])
          ->execute();
      }
      
      // Create/update pending record for cover letter if table exists.
      if ($cover_letter_table_exists) {
        $existing_cover = $database->select('jobhunter_cover_letters', 'cl')
          ->fields('cl')
          ->condition('uid', $user_id)
          ->condition('job_id', $job_id)
          ->execute()
          ->fetchObject();

        if ($existing_cover) {
          $database->update('jobhunter_cover_letters')
            ->fields([
              'tailoring_status' => 'queued',
              'updated' => $now,
            ])
            ->condition('id', $existing_cover->id)
            ->execute();
        }
        else {
          $database->insert('jobhunter_cover_letters')
            ->fields([
              'uid' => $user_id,
              'job_id' => $job_id,
              'tailoring_status' => 'queued',
              'created' => $now,
              'updated' => $now,
            ])
            ->execute();
        }
      }
      else {
        \Drupal::logger('job_hunter')->warning('Cover letter queue skipped because jobhunter_cover_letters table is missing.');
      }

      $extracted = $job_data->extracted_json ? json_decode($job_data->extracted_json, TRUE) : [];
      $job_title = $extracted['position']['title'] ?? $extracted['job_title'] ?? 'this position';
      
      \Drupal::logger('job_hunter')->info('Queued resume tailoring and cover letter generation for user @uid, job @job_id (@title)', [
        '@uid' => $user_id,
        '@job_id' => $job_id,
        '@title' => $job_title,
      ]);

      $queued_message = $cover_letter_table_exists
        ? "Resume and cover letter tailoring queued for {$job_title}. Processing will begin shortly..."
        : "Resume tailoring queued for {$job_title}. Processing will begin shortly...";

      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => TRUE,
        'status' => 'queued',
        'message' => $queued_message,
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Error queuing tailored resume: @error', ['@error' => $e->getMessage()]);
      
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'error' => 'Failed to queue tailored resume: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * AJAX endpoint to check tailoring status.
   */
  public function tailorResumeStatusAjax() {
    try {
      $request = \Drupal::request();
      $job_id = $request->query->get('job_id');
      $user_id = $this->currentUser->id();
      $database = \Drupal::database();
      $cover_letter_table_exists = $database->schema()->tableExists('jobhunter_cover_letters');

      // Get actual queue status by checking all sources
      $queue_status = $this->getActualQueueStatus($user_id, $job_id);
      
      // Update database if status is out of sync
      if ($queue_status['should_update_db']) {
        $database->update('jobhunter_tailored_resumes')
          ->fields(['tailoring_status' => $queue_status['status'], 'updated' => time()])
          ->condition('uid', $user_id)
          ->condition('job_id', $job_id)
          ->execute();
        
        \Drupal::logger('job_hunter')->info('AJAX status check synced status from @old to @new for user @uid job @job', [
          '@old' => $queue_status['db_status'],
          '@new' => $queue_status['status'],
          '@uid' => $user_id,
          '@job' => $job_id,
        ]);
      }
      
      $record = $database->select('jobhunter_tailored_resumes', 'tr')
        ->fields('tr')
        ->condition('uid', $user_id)
        ->condition('job_id', $job_id)
        ->execute()
        ->fetchObject();
        
      // Also check cover letter status when table exists.
      $cover_letter = NULL;
      if ($cover_letter_table_exists) {
        $cover_letter = $database->select('jobhunter_cover_letters', 'cl')
          ->fields('cl')
          ->condition('uid', $user_id)
          ->condition('job_id', $job_id)
          ->execute()
          ->fetchObject();
      }

      if (!$record) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'status' => 'not_started',
          'message' => 'No tailoring request found for this job.',
          'cover_letter_status' => $cover_letter ? $cover_letter->tailoring_status : 'not_started',
          'in_queue' => $queue_status['in_queue'],
          'suspended' => $queue_status['suspended'],
        ]);
      }

      // Use actual status from queue check
      $actual_status = $queue_status['status'];

      $response = [
        'status' => $actual_status,
        'updated' => $record->updated,
        'cover_letter_status' => $cover_letter ? $cover_letter->tailoring_status : 'not_started',
        'in_queue' => $queue_status['in_queue'],
        'suspended' => $queue_status['suspended'],
      ];

      if ($actual_status === 'completed' && !empty($record->tailored_resume_json)) {
        $response['tailored_resume'] = json_decode($record->tailored_resume_json, TRUE);
        $response['message'] = 'Resume tailoring completed!';
        
        // Include cover letter if available
        if ($cover_letter && $cover_letter->tailoring_status === 'completed' && !empty($cover_letter->cover_letter_text)) {
          $response['cover_letter_text'] = $cover_letter->cover_letter_text;
          $response['cover_letter_html'] = $cover_letter->cover_letter_html;
          $response['message'] = 'Resume and cover letter completed!';
        }
      }
      elseif ($actual_status === 'processing') {
        $response['message'] = $cover_letter_table_exists
          ? 'AI is generating your tailored resume and cover letter...'
          : 'AI is generating your tailored resume...';
      }
      elseif ($actual_status === 'queued') {
        $response['message'] = 'Waiting in queue for processing...';
      }
      elseif ($actual_status === 'failed') {
        $response['message'] = $queue_status['suspended'] 
          ? 'Tailoring suspended after multiple failures. Check queue management page.'
          : 'Tailoring failed. Please try again.';
      }
      else {
        $response['message'] = 'Status: ' . $actual_status;
      }

      return new \Symfony\Component\HttpFoundation\JsonResponse($response);

    } catch (\Exception $e) {
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * DEPRECATED: Direct AWS Bedrock call - DO NOT USE.
   * 
   * This method is no longer used. Resume tailoring now happens via:
   * - ResumeTailoringWorker queue worker (asynchronous)
   * - AIApiService for centralized logging and caching
   * 
   * Kept for reference only. Remove in future cleanup.
   *
   * @deprecated Use ResumeTailoringWorker queue worker instead.
   * 
   * @param array $payload
   *   The request payload matching JOB_REQUISITION_JSON_SCHEMA.md.
   *
   * @return array|null
   *   The response from GenAI with tailored_resume_json, or null on failure.
   */
  private function callGenAiTailoringService(array $payload) {
    // DEPRECATED - This code path is never called.
    // Resume tailoring is handled by ResumeTailoringWorker queue worker.
    \Drupal::logger('job_hunter')->warning('DEPRECATED: callGenAiTailoringService() was called. This should use ResumeTailoringWorker queue worker instead.');
    
    throw new \Exception('Direct GenAI tailoring is deprecated. Use queue worker instead.');
  }

  /**
   * Build the prompt for generating a tailored resume JSON.
   *
   * @param array $payload
   *   The tailoring request payload.
   *
   * @return string
   *   The prompt for AWS Bedrock Claude.
   */
  private function buildTailoredResumePrompt(array $payload) {
    $job = $payload['job_requisition'] ?? [];
    $resume = $payload['user_resume']['consolidated_profile_json'] ?? [];
    
    $job_title = $job['extracted_json']['position']['title'] ?? 'the position';
    $company_name = $job['extracted_json']['company']['name'] ?? 'the company';
    $job_skills = json_encode($job['skills_required_json'] ?? [], JSON_PRETTY_PRINT);
    $job_keywords = json_encode($job['keywords_json'] ?? [], JSON_PRETTY_PRINT);
    $job_description = $job['raw_posting_text'] ?? '';
    // Use compact JSON encoding to reduce prompt size
    $resume_json = json_encode($resume, JSON_UNESCAPED_SLASHES);
    
    return <<<PROMPT
You are an expert resume tailoring AI. Your task is to create a tailored version of the candidate's resume optimized for a specific job posting.

## Job Information
**Position:** {$job_title}
**Company:** {$company_name}

**Required Skills:**
{$job_skills}

**Key Keywords:**
{$job_keywords}

**Job Description:**
{$job_description}

## Candidate's Current Resume (JSON)
{$resume_json}

## Your Task

Generate a TAILORED version of the candidate's resume as a JSON object. The output must:

1. **Match the RESUME_JSON_SCHEMA.md structure** exactly with these sections:
   - `schema_version`: "1.0"
   - `tailoring_metadata`: Object with job_id, job_title, company, tailored_at timestamp, and guidance array
   - `contact_info`: Keep unchanged from original
   - `executive_profile`: Rewrite summary to emphasize relevant experience for this role
   - `strategic_differentiators`: Prioritize/reword to match job requirements — each item MUST be an object `{"title": "...", "description": "..."}` NOT a plain string
   - `professional_experience`: Reorder achievements, emphasize relevant technologies/metrics — each entry MUST be a flat object with `title`, `company`, `start_date`, `end_date`, `location`, `company_context`, `responsibility_categories`. Do NOT wrap positions inside a `positions[]` array. Each position held = one flat entry in the array.
   - `consulting_practice`: Include if relevant to role
   - `early_career`: Include if relevant
   - `education`: Keep unchanged
   - `technical_expertise`: Reorder categories to prioritize job-relevant skills
   - `leadership_philosophy`: Tailor if relevant
   - `demonstration_projects`: Include if relevant
   - `publications`: Include if candidate has publications and they're relevant to the role
   - `patents`: Include if candidate has patents and they're relevant to the role
   - `certifications`: Include if candidate has certifications and they're relevant to the role
   - `awards_and_honors`: Include if relevant to demonstrate excellence in the field
   - `languages`: Include if job requires or values language skills

2. **Tailoring Guidelines:**
   - Incorporate keywords from the job posting naturally
   - Prioritize achievements that match required skills
   - Quantified metrics should be preserved and highlighted when relevant
   - Technologies mentioned in job posting should be emphasized
   - Maintain professional tone and factual accuracy
   - DO NOT fabricate information - only reorganize and emphasize existing content
   - For publications, patents, certifications, awards, and languages: only include if they exist in source resume AND are relevant to the position
   - **Be concise**: Keep descriptions focused and impactful. Avoid unnecessary verbosity while maintaining professional quality.
   - **Optimize length**: Aim for a balanced, professional resume that highlights the most relevant content for this role.

3. **Add tailoring_metadata section:**
   ```json
   "tailoring_metadata": {
     "job_id": {job_id},
     "job_title": "{job_title}",
     "company": "{company_name}",
     "tailored_at": "ISO-8601 timestamp",
     "match_score": 0-100,
     "guidance": [
       "Key suggestion 1",
       "Key suggestion 2"
     ],
     "emphasized_skills": ["skill1", "skill2"],
     "emphasized_achievements": ["achievement summary 1"]
   }
   ```

## Output Format

**CRITICAL**: Return ONLY valid JSON conforming to RFC 8259:
- Start immediately with `{` and end with `}`
- NO markdown code blocks (no ```json or ```)
- NO explanatory text before or after the JSON
- NO double-escaping (don't wrap JSON as a string)
- **USE PROPER JSON ESCAPING**: `\n` for newlines, `\t` for tabs, `\"` for quotes within strings
- Ensure all braces, brackets, and quotes are properly balanced
- Multi-line string values MUST use `\n` escape sequences, NOT literal newlines
- All special characters in strings MUST be properly escaped per JSON spec

The output must parse successfully with `json_decode()` without any preprocessing.

PROMPT;
  }

  /**
   * Extract JSON from AI response that may contain markdown or text.
   *
   * @param string $response
   *   The raw AI response.
   *
   * @return string|null
   *   Extracted JSON string or null.
   */
  private function extractJsonFromResponse($response) {
    $response_text = trim($response);
    
    if (empty($response_text)) {
      return NULL;
    }

    // AGGRESSIVE normalization of escaped sequences
    $has_literal_newlines = strpos($response_text, "\\n") !== FALSE;
    $has_literal_quotes = strpos($response_text, '\\"') !== FALSE;
    $has_literal_tabs = strpos($response_text, "\\t") !== FALSE;
    
    if ($has_literal_newlines || $has_literal_quotes || $has_literal_tabs) {
      $response_text = stripcslashes($response_text);
      $response_text = trim($response_text);
      \Drupal::logger('job_hunter')->warning('🟡 Normalized escaped JSON response (literal escapes detected)');
    }
    
    // Try direct parse first
    $decoded = json_decode($response_text, TRUE);
    if (json_last_error() === JSON_ERROR_NONE) {
      return $response_text;
    }
    
    // Try extracting from markdown code block
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/', $response_text, $matches)) {
      $candidate = trim($matches[1]);
      $decoded = json_decode($candidate, TRUE);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $candidate;
      }
    }
    
    // Try brace counting with recovery
    $start_pos = strpos($response_text, '{');
    if ($start_pos === FALSE) {
      return NULL;
    }

    $depth = 0;
    $in_string = FALSE;
    $escape_next = FALSE;
    $len = strlen($response_text);
    $last_quote_pos = -1;

    for ($i = $start_pos; $i < $len; $i++) {
      $char = $response_text[$i];

      if ($escape_next) {
        $escape_next = FALSE;
        continue;
      }
      if ($char === '\\') {
        $escape_next = TRUE;
        continue;
      }
      if ($char === '"') {
        $in_string = !$in_string;
        $last_quote_pos = $i;
        continue;
      }
      if ($in_string) {
        continue;
      }
      if ($char === '{') {
        $depth++;
      }
      elseif ($char === '}') {
        $depth--;
        if ($depth === 0) {
          return substr($response_text, $start_pos, $i - $start_pos + 1);
        }
      }
    }

    // Recovery: Try to find last valid JSON if stuck in string state
    if ($in_string && $last_quote_pos > 0 && $depth > 0) {
      for ($i = $len - 1; $i > $start_pos; $i--) {
        if ($response_text[$i] === '}') {
          $candidate = substr($response_text, $start_pos, $i - $start_pos + 1);
          $test = json_decode($candidate, TRUE);
          if (json_last_error() === JSON_ERROR_NONE) {
            \Drupal::logger('job_hunter')->info('✅ Recovered valid JSON from truncated response');
            return $candidate;
          }
        }
      }
    }
    
    return NULL;
  }

  /**
   * Check if we're in a development environment.
   */
  private function isDevelopmentEnvironment() {
    // Check if this is our development workspace
    $workspace_path = '/workspaces/stlouisintegration.com';
    if (file_exists($workspace_path)) {
      return TRUE;
    }
    
    // Check environment variables that indicate development
    $env_indicators = ['CODESPACE_NAME', 'GITPOD_WORKSPACE_ID', 'C9_USER'];
    foreach ($env_indicators as $indicator) {
      if (getenv($indicator)) {
        return TRUE;
      }
    }
    
    // Check if we're in local development
    $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    if (in_array($host, ['localhost', '127.0.0.1', 'local.dev'])) {
      return TRUE;
    }
    
    return FALSE;
  }

  /**
   * Calculate skills gap between job requirements and user profile.
   *
   * @param array $job_skills
   *   The job skills from skills_required_json.
   * @param array $profile_json
   *   The user's consolidated profile JSON.
   *
   * @return array
   *   Array with 'must_have' and 'nice_to_have' missing skills.
   */
  private function calculateSkillsGap(array $job_skills, array $profile_json): array {
    $gap = [
      'must_have' => [],
      'nice_to_have' => [],
    ];

    // Build a list of user's skills (normalized to lowercase for comparison)
    $user_skills = [];
    
    // From technical_expertise categories
    if (!empty($profile_json['technical_expertise'])) {
      foreach ($profile_json['technical_expertise'] as $category) {
        if (!empty($category['skills'])) {
          foreach ($category['skills'] as $skill) {
            $skill_name = is_array($skill) ? ($skill['name'] ?? '') : $skill;
            if ($skill_name) {
              $user_skills[] = strtolower(trim($skill_name));
            }
          }
        }
      }
    }

    // From skills array (flat list)
    if (!empty($profile_json['skills'])) {
      foreach ($profile_json['skills'] as $skill) {
        $skill_name = is_array($skill) ? ($skill['name'] ?? $skill['skill'] ?? '') : $skill;
        if ($skill_name) {
          $user_skills[] = strtolower(trim($skill_name));
        }
      }
    }

    // From certifications
    if (!empty($profile_json['certifications'])) {
      foreach ($profile_json['certifications'] as $cert) {
        $cert_name = is_array($cert) ? ($cert['name'] ?? '') : $cert;
        if ($cert_name) {
          $user_skills[] = strtolower(trim($cert_name));
        }
      }
    }

    // Check must_have skills
    if (!empty($job_skills['must_have'])) {
      foreach ($job_skills['must_have'] as $skill) {
        $skill_name = is_array($skill) ? ($skill['skill'] ?? $skill['name'] ?? '') : $skill;
        if ($skill_name && !$this->skillExistsInProfile($skill_name, $user_skills)) {
          $gap['must_have'][] = [
            'skill' => $skill_name,
            'category' => is_array($skill) ? ($skill['category'] ?? 'technical') : 'technical',
          ];
        }
      }
    }

    // Check nice_to_have skills
    if (!empty($job_skills['nice_to_have'])) {
      foreach ($job_skills['nice_to_have'] as $skill) {
        $skill_name = is_array($skill) ? ($skill['skill'] ?? $skill['name'] ?? '') : $skill;
        if ($skill_name && !$this->skillExistsInProfile($skill_name, $user_skills)) {
          $gap['nice_to_have'][] = [
            'skill' => $skill_name,
            'category' => is_array($skill) ? ($skill['category'] ?? 'technical') : 'technical',
          ];
        }
      }
    }

    // Check tech_stack
    if (!empty($job_skills['tech_stack'])) {
      foreach ($job_skills['tech_stack'] as $tech) {
        $tech_name = is_array($tech) ? ($tech['name'] ?? '') : $tech;
        if ($tech_name && !$this->skillExistsInProfile($tech_name, $user_skills)) {
          $gap['nice_to_have'][] = [
            'skill' => $tech_name,
            'category' => 'technical',
          ];
        }
      }
    }

    return $gap;
  }

  /**
   * Check if a skill exists in the user's profile (fuzzy match).
   */
  private function skillExistsInProfile(string $skill_name, array $user_skills): bool {
    $normalized = strtolower(trim($skill_name));
    
    // Direct match
    if (in_array($normalized, $user_skills)) {
      return TRUE;
    }

    // Fuzzy match - check if skill is contained in any user skill or vice versa
    foreach ($user_skills as $user_skill) {
      if (strpos($user_skill, $normalized) !== FALSE || strpos($normalized, $user_skill) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * AJAX endpoint to add a skill to user's profile.
   */
  public function addSkillToProfileAjax() {
    try {
      $request = \Drupal::request();
      $skill_name = $request->request->get('skill');
      $skill_category = $request->request->get('category', 'technical');
      $user_id = $this->currentUser->id();
      $database = \Drupal::database();

      if (empty($skill_name)) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'Skill name is required',
        ], 400);
      }

      // Load user's profile
      $job_seeker_profile = $database->select('jobhunter_job_seeker', 'js')
        ->fields('js')
        ->condition('uid', $user_id)
        ->execute()
        ->fetchObject();

      if (!$job_seeker_profile) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'Profile not found',
        ], 400);
      }

      $profile_json = json_decode($job_seeker_profile->consolidated_profile_json, TRUE) ?: [];

      // Add skill to technical_expertise
      if (!isset($profile_json['technical_expertise'])) {
        $profile_json['technical_expertise'] = [];
      }

      // Find or create the category
      $category_found = FALSE;
      $category_map = [
        'technical' => 'Technical Skills',
        'soft' => 'Soft Skills',
        'domain' => 'Domain Expertise',
        'tools' => 'Tools & Platforms',
      ];
      $category_label = $category_map[$skill_category] ?? 'Technical Skills';

      foreach ($profile_json['technical_expertise'] as &$category) {
        if (isset($category['category']) && $category['category'] === $category_label) {
          if (!isset($category['skills'])) {
            $category['skills'] = [];
          }
          // Check if skill already exists
          foreach ($category['skills'] as $existing) {
            $existing_name = is_array($existing) ? ($existing['name'] ?? '') : $existing;
            if (strtolower($existing_name) === strtolower($skill_name)) {
              return new \Symfony\Component\HttpFoundation\JsonResponse([
                'success' => TRUE,
                'message' => "Skill '{$skill_name}' already exists in your profile.",
                'already_exists' => TRUE,
              ]);
            }
          }
          $category['skills'][] = ['name' => $skill_name, 'proficiency' => 'intermediate'];
          $category_found = TRUE;
          break;
        }
      }

      if (!$category_found) {
        // Create new category
        $profile_json['technical_expertise'][] = [
          'category' => $category_label,
          'skills' => [['name' => $skill_name, 'proficiency' => 'intermediate']],
        ];
      }

      // Save updated profile - use 'changed' column (not 'updated')
      $database->update('jobhunter_job_seeker')
        ->fields([
          'consolidated_profile_json' => json_encode($profile_json),
          'changed' => time(),
        ])
        ->condition('uid', $user_id)
        ->execute();

      \Drupal::logger('job_hunter')->info('Added skill "@skill" to user @uid profile', [
        '@skill' => $skill_name,
        '@uid' => $user_id,
      ]);

      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => TRUE,
        'message' => "Added '{$skill_name}' to your profile!",
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Error adding skill to profile: @error', ['@error' => $e->getMessage()]);
      
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'error' => 'Failed to add skill: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * AJAX endpoint to refresh skills gap analysis after adding skills.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with updated skills gap.
   */
  public function refreshSkillsGapAjax() {
    try {
      $request = \Drupal::request();
      $job_id = $request->request->get('job_id');
      $user_id = $this->currentUser->id();
      $database = \Drupal::database();

      if (empty($job_id)) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'Job ID is required',
        ], 400);
      }

      // Load job data
      $job_data = $database->select('jobhunter_job_requirements', 'j')
        ->fields('j')
        ->condition('id', $job_id)
        ->execute()
        ->fetchObject();

      if (!$job_data) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'Job not found',
        ], 404);
      }

      // Load user's profile
      $job_seeker_profile = $database->select('jobhunter_job_seeker', 'js')
        ->fields('js')
        ->condition('uid', $user_id)
        ->execute()
        ->fetchObject();

      if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
        return new \Symfony\Component\HttpFoundation\JsonResponse([
          'error' => 'Profile not found',
        ], 400);
      }

      // Parse JSON data
      $skills = $job_data->skills_required_json ? json_decode($job_data->skills_required_json, TRUE) : [];
      $profile_json = json_decode($job_seeker_profile->consolidated_profile_json, TRUE) ?: [];

      // Recalculate skills gap
      $skills_gap = $this->calculateSkillsGap($skills, $profile_json);

      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => TRUE,
        'skills_gap' => $skills_gap,
        'must_have_count' => count($skills_gap['must_have']),
        'nice_to_have_count' => count($skills_gap['nice_to_have']),
        'message' => 'Skills gap refreshed successfully',
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Error refreshing skills gap: @error', ['@error' => $e->getMessage()]);
      
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'error' => 'Failed to refresh skills gap: ' . $e->getMessage(),
      ], 500);
    }
  }


  /**
   * Download a resume file.
   *
   * @param int $file
   *   The file ID.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   The file response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the file is not found.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If access is denied.
   */
  public function downloadResume($file) {
    $current_user_id = $this->currentUser->id();
    
    // Load the file entity.
    $file_entity = \Drupal\file\Entity\File::load($file);
    if (!$file_entity) {
      throw new NotFoundHttpException();
    }
    
    // Check ownership - user can only download their own resume unless admin.
    $job_seeker_profile = $this->jobSeekerService->loadByUserId($current_user_id);
    if (!$job_seeker_profile || $job_seeker_profile->resume_node_id != $file) {
      // Allow admins to override.
      if (!$this->currentUser->hasPermission('administer job application automation')) {
        throw new AccessDeniedHttpException();
      }
    }
    
    // Serve the file.
    $uri = $file_entity->getFileUri();
    $filename = $file_entity->getFilename();
    $headers = [
      'Content-Type' => $file_entity->getMimeType(),
      'Content-Disposition' => 'inline; filename="' . $filename . '"',
      'Content-Length' => $file_entity->getSize(),
      'Cache-Control' => 'private',
    ];
    
    return new \Symfony\Component\HttpFoundation\BinaryFileResponse($uri, 200, $headers, true);
  }


  /**
   * Delete a resume file.
   *
   * @param int $resume_id
   *   The resume ID from job_seeker_resumes table.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to profile edit page.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   If the resume is not found.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If access is denied.
   */
  public function deleteResume($resume_id) {
    $current_user_id = $this->currentUser->id();
    $database = \Drupal::database();
    
    // Load the resume entry
    $resume = $database->select('jobhunter_job_seeker_resumes', 'jsr')
      ->fields('jsr')
      ->condition('id', $resume_id)
      ->execute()
      ->fetchObject();
    
    if (!$resume) {
      throw new NotFoundHttpException();
    }
    
    // Check ownership
    $job_seeker_profile = $this->jobSeekerService->loadByUserId($current_user_id);
    if (!$job_seeker_profile || $job_seeker_profile->id != $resume->job_seeker_id) {
      // Allow admins to override
      if (!$this->currentUser->hasPermission('administer job application automation')) {
        throw new AccessDeniedHttpException();
      }
    }
    
    // Delete the file entity
    $file = \Drupal\file\Entity\File::load($resume->file_id);
    if ($file) {
      $file->delete();
    }
    
    // Delete the resume entry
    $database->delete('jobhunter_job_seeker_resumes')
      ->condition('id', $resume_id)
      ->execute();
    
    // If this was the primary resume, set another as primary
    if ($resume->is_primary) {
      $next_resume = $database->select('jobhunter_job_seeker_resumes', 'jsr')
        ->fields('jsr', ['id'])
        ->condition('job_seeker_id', $resume->job_seeker_id)
        ->orderBy('created', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchField();
      
      if ($next_resume) {
        $database->update('jobhunter_job_seeker_resumes')
          ->fields(['is_primary' => 1, 'changed' => time()])
          ->condition('id', $next_resume)
          ->execute();
      }
    }
    
    \Drupal::messenger()->addMessage($this->t('Resume deleted successfully.'));
    
    return new \Symfony\Component\HttpFoundation\RedirectResponse(
      \Drupal\Core\Url::fromRoute('job_hunter.user_profile_edit')->toString()
    );
  }

  /**
   * Get actual queue status by checking queue table, suspended queue, and database record.
   * 
   * This ensures status is always in sync with reality, preventing out-of-date status displays.
   *
   * @param int $user_id
   *   The user ID.
   * @param int $job_id
   *   The job ID.
   * @param string $queue_name
   *   The queue name to check (e.g., 'job_hunter_resume_tailoring').
   *
   * @return array
   *   Array with keys: 'status', 'in_queue', 'suspended', 'should_update_db'.
   */
  private function getActualQueueStatus($user_id, $job_id, $queue_name = 'job_hunter_resume_tailoring') {
    $database = \Drupal::database();
    $job_id = (int) $job_id;
    $user_id = (int) $user_id;
    
    // Check if item is in the active queue.
    // Drupal stores queue data as PHP-serialized blobs, so we must
    // unserialize each row and compare uid + job_id properly.
    $in_queue = FALSE;
    $queue_rows = $database->select('queue', 'q')
      ->fields('q', ['item_id', 'data'])
      ->condition('name', $queue_name)
      ->execute()
      ->fetchAll();
    foreach ($queue_rows as $row) {
      $item_data = @unserialize($row->data, ['allowed_classes' => FALSE]);
      if (is_array($item_data)
          && (int) ($item_data['uid'] ?? 0) === $user_id
          && (int) ($item_data['job_id'] ?? 0) === $job_id) {
        $in_queue = TRUE;
        break;
      }
    }
    
    // Check if item is in suspended queue (also PHP-serialized).
    $suspended = FALSE;
    $suspended_rows = $database->select('jobhunter_queue_suspended', 'qs')
      ->fields('qs', ['id', 'item_data'])
      ->condition('queue_name', $queue_name)
      ->execute()
      ->fetchAll();
    foreach ($suspended_rows as $row) {
      $item_data = @unserialize($row->item_data, ['allowed_classes' => FALSE]);
      if (is_array($item_data)
          && (int) ($item_data['uid'] ?? 0) === $user_id
          && (int) ($item_data['job_id'] ?? 0) === $job_id) {
        $suspended = TRUE;
        break;
      }
    }
    
    // Get current database status
    $db_record = $database->select('jobhunter_tailored_resumes', 'tr')
      ->fields('tr', ['tailoring_status', 'tailored_resume_json'])
      ->condition('uid', $user_id)
      ->condition('job_id', $job_id)
      ->execute()
      ->fetchObject();
    
    $db_status = $db_record ? $db_record->tailoring_status : 'pending';
    $has_tailored_resume = $db_record && !empty($db_record->tailored_resume_json);
    
    // Determine actual status based on queue state
    $actual_status = $db_status;
    $should_update_db = FALSE;
    
    if ($in_queue) {
      // Item is actively queued
      if (!in_array($db_status, ['queued', 'processing'])) {
        $actual_status = 'queued';
        $should_update_db = TRUE;
      }
    }
    elseif ($suspended) {
      // Item is suspended (failed too many times)
      if ($db_status !== 'failed') {
        $actual_status = 'failed';
        $should_update_db = TRUE;
      }
    }
    else {
      // Not in queue or suspended
      if (in_array($db_status, ['queued', 'processing'])) {
        // Stuck status - item was processed but status not updated
        if ($has_tailored_resume) {
          $actual_status = 'completed';
        }
        else {
          $actual_status = 'pending';
        }
        $should_update_db = TRUE;
      }
    }
    
    return [
      'status' => $actual_status,
      'in_queue' => (bool) $in_queue,
      'suspended' => (bool) $suspended,
      'should_update_db' => $should_update_db,
      'db_status' => $db_status,
      'has_resume' => $has_tailored_resume,
    ];
  }

  /**
   * Checks whether a tailoring queue already has an item for uid + job_id.
   *
   * @param string $queue_name
   *   The queue name.
   * @param int $user_id
   *   The user id.
   * @param int $job_id
   *   The job id.
   *
   * @return bool
   *   TRUE when a matching queue item exists.
   */
  private function hasQueuedTailoringItem(string $queue_name, int $user_id, int $job_id): bool {
    $database = \Drupal::database();

    $rows = $database->select('queue', 'q')
      ->fields('q', ['data'])
      ->condition('name', $queue_name)
      ->execute()
      ->fetchAll();

    foreach ($rows as $row) {
      $item_data = @unserialize($row->data, ['allowed_classes' => FALSE]);
      if (!is_array($item_data)) {
        continue;
      }

      $queued_uid = isset($item_data['uid']) ? (int) $item_data['uid'] : 0;
      $queued_job_id = isset($item_data['job_id']) ? (int) $item_data['job_id'] : 0;

      if ($queued_uid === $user_id && $queued_job_id === $job_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * AJAX endpoint: save thumbs-up/thumbs-down rating for a tailored resume.
   *
   * POST /jobhunter/tailor-feedback
   * Body params: tailored_resume_id (int), rating ('up'|'down'), note (string, max 500)
   */
  public function tailoringFeedbackSave(): JsonResponse {
    $uid = (int) $this->currentUser->id();
    $request = \Drupal::request();
    $body = json_decode($request->getContent(), TRUE) ?: [];

    $tailored_resume_id = (int) ($body['tailored_resume_id'] ?? 0);
    $rating_raw = (string) ($body['rating'] ?? '');
    $note_raw   = (string) ($body['note'] ?? '');

    if (!$tailored_resume_id) {
      return new JsonResponse(['error' => 'Missing tailored_resume_id.'], 400);
    }

    if (!in_array($rating_raw, ['up', 'down'], TRUE)) {
      return new JsonResponse(['error' => 'Invalid rating. Must be "up" or "down".'], 422);
    }

    // SEC-5: cap note at 500 chars before strip to prevent bypass
    if (mb_strlen($note_raw) > 500) {
      return new JsonResponse(['error' => 'Note exceeds maximum length'], 422);
    }
    $note = strip_tags($note_raw);

    // SEC-3: ownership check — confirm tailored_resume belongs to current user
    $database = \Drupal::database();
    $owner_uid = $database->select('jobhunter_tailored_resumes', 'tr')
      ->fields('tr', ['uid'])
      ->condition('tr.id', $tailored_resume_id)
      ->execute()
      ->fetchField();

    if (!$owner_uid || (int) $owner_uid !== $uid) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    $now = time();
    $existing_id = $database->select('jobhunter_tailoring_feedback', 'tf')
      ->fields('tf', ['id'])
      ->condition('tf.uid', $uid)
      ->condition('tf.tailored_resume_id', $tailored_resume_id)
      ->execute()
      ->fetchField();

    if ($existing_id) {
      $database->update('jobhunter_tailoring_feedback')
        ->fields(['rating' => $rating_raw, 'note' => $note, 'changed' => $now])
        ->condition('id', $existing_id)
        ->execute();
    }
    else {
      $database->insert('jobhunter_tailoring_feedback')
        ->fields([
          'uid'                => $uid,
          'tailored_resume_id' => $tailored_resume_id,
          'rating'             => $rating_raw,
          'note'               => $note,
          'created'            => $now,
          'changed'            => $now,
        ])
        ->execute();
    }

    // SEC-5: log only uid and tailored_resume_id, never note content
    $this->getLogger('job_hunter')->info('Tailoring feedback saved: uid=@uid tailored_resume_id=@rid', [
      '@uid' => $uid,
      '@rid' => $tailored_resume_id,
    ]);

    return new JsonResponse(['success' => TRUE, 'rating' => $rating_raw]);
  }

}
