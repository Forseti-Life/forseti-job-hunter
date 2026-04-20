<?php

namespace Drupal\job_hunter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\job_hunter\Service\UserProfileService;
use Drupal\job_hunter\Service\JobSeekerService;
use Drupal\job_hunter\Traits\JobHunterLoggerTrait;
use Drupal\job_hunter\Plugin\QueueWorker\ResumeGenAiParsingWorker;
use Drupal\job_hunter\Form\Subform\EducationHistorySubform;
use Drupal\job_hunter\Form\Subform\ResumeUploadSubform;
use Drupal\job_hunter\Repository\UserProfileRepository;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;

/**
 * Provides a form for editing user job application profile.
 */
class UserProfileForm extends FormBase {

  use JobHunterLoggerTrait;

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
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The user profile service.
   *
   * @var \Drupal\job_hunter\Service\UserProfileService
   */
  protected $userProfileService;

  /**
   * The AI API service.
   *
   * @var \Drupal\ai_conversation\Service\AIApiService|null
   */
  protected $aiApiService;

  /**
   * The job seeker service.
   *
   * @var \Drupal\job_hunter\Service\JobSeekerService
   */
  protected $jobSeekerService;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The user profile repository.
   *
   * @var \Drupal\job_hunter\Repository\UserProfileRepository
   */
  protected UserProfileRepository $userProfileRepository;

  /**
   * The education history subform handler.
   *
   * @var \Drupal\job_hunter\Form\Subform\EducationHistorySubform
   */
  protected EducationHistorySubform $educationHistorySubform;

  /**
   * The resume upload subform handler.
   *
   * @var \Drupal\job_hunter\Form\Subform\ResumeUploadSubform
   */
  protected ResumeUploadSubform $resumeUploadSubform;

  /**
   * Constructs a new UserProfileForm.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\job_hunter\Service\UserProfileService $user_profile_service
   *   The user profile service.
   * @param \Drupal\job_hunter\Service\JobSeekerService $job_seeker_service
   *   The job seeker service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\ai_conversation\Service\AIApiService|null $ai_api_service
   *   The AI API service.
   */
  public function __construct(AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, UserProfileService $user_profile_service, JobSeekerService $job_seeker_service, $config_factory, $database, UserProfileRepository $userProfileRepository, $ai_api_service = NULL) {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->userProfileService = $user_profile_service;
    $this->jobSeekerService = $job_seeker_service;
    $this->configFactory = $config_factory;
    $this->userProfileRepository = $userProfileRepository;
    $this->aiApiService = $ai_api_service;
    $this->educationHistorySubform = new EducationHistorySubform($database);
    $this->resumeUploadSubform = new ResumeUploadSubform($job_seeker_service, $database);
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
      $container->get('messenger'),
      $container->get('job_hunter.user_profile_service'),
      $container->get('job_hunter.job_seeker_service'),
      $container->get('config.factory'),
      $container->get('database'),
      $container->get('job_hunter.user_profile_repository'),
      $ai_service
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_hunter_user_profile_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $user = NULL) {
    // Load the user entity - either specified user or current user
    $uid = $user ?: $this->currentUser->id();
    $user_entity = User::load($uid);

    if (!$user_entity) {
      $this->messenger->addError($this->t('User not found.'));
      return [];
    }

    // Store user entity for submit handler
    $form_state->set('user_entity', $user_entity);

    $form['#prefix'] = '<div class="jh-profile">';
    $form['#suffix'] = '</div>';
    $form['#attached']['library'][] = 'job_hunter/user_profile';

    // Profile completion progress
    $completeness = $this->userProfileService->calculateProfileCompleteness($user_entity);
    
    // Debug logging for profile completeness
    $this->logDebug('🔍 DEBUG: Profile completeness calculation: @percent% for user @uid', [
      '@percent' => $completeness,
      '@uid' => $uid,
    ]);
    
    $form['profile_progress'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['jh-profile__progress']],
      '#weight' => -200,
    ];
    $form['profile_progress']['progress'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Profile Completeness: @percent%', ['@percent' => $completeness]),
      '#attributes' => [
        'class' => ['jh-profile__progress-text'],
        'data-progress' => $completeness,
      ],
    ];
    $form['profile_progress']['bar'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['jh-profile__progress-bar'],
      ],
    ];
    $form['profile_progress']['bar']['fill'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['jh-profile__progress-fill'],
        'style' => "width: {$completeness}%",
      ],
    ];

    // Top quick actions for long profile forms.
    $form['quick_actions_top'] = [
      '#type' => 'actions',
      '#weight' => -150,
      '#attributes' => [
        'class' => ['jh-profile__actions-top'],
      ],
    ];

    $form['quick_actions_top']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Profile'),
      '#button_type' => 'primary',
    ];

    $form['#prefix'] = '<div id="profile-form-wrapper">' . $form['#prefix'];
    $form['#suffix'] .= '</div>';

    // Load job seeker profile data
    $job_seeker_profile = $this->jobSeekerService->loadByUserId($uid);

    // Repair legacy resume parsing state and consolidate if everything is parsed but merged metadata is missing.
    $this->normalizeResumeParsedDataStatuses($uid);
    $this->ensureResumeConsolidationUpToDate($uid);
    // Reload job seeker profile in case consolidation updated consolidated_profile_json.
    $job_seeker_profile = $this->jobSeekerService->loadByUserId($uid);
    
    // Resume Management Section — delegated to ResumeUploadSubform.
    $this->resumeUploadSubform->buildFormElements($form, $form_state, $job_seeker_profile, $uid);

    // Load consolidated profile JSON for pre-populating fields
    $consolidated = [];
    if ($job_seeker_profile && !empty($job_seeker_profile->consolidated_profile_json)) {
      $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE) ?: [];
    }

    $get_profile_value = function($property, $default = '') use ($job_seeker_profile) {
      if ($job_seeker_profile && isset($job_seeker_profile->$property) && $job_seeker_profile->$property !== '') {
        return $job_seeker_profile->$property;
      }
      return $default;
    };

    // Automated Search Assist Section (user-entered, not derived from resume)
    $form['search_assist'] = [
      '#type' => 'details',
      '#title' => $this->t('🤖 Automated Search Assist'),
      '#description' => $this->t('These preferences power your automated job search. Fill them out to improve job matching across all search sources.'),
      '#attributes' => ['class' => ['search-assist-section', 'no-toggle-fieldset']],
      '#open' => TRUE,
      '#weight' => 10,
    ];

    $form['search_assist']['field_work_authorization'] = [
      '#type' => 'select',
      '#title' => $this->t('Work Authorization'),
      '#description' => $this->t('Your legal work authorization status'),
      '#required' => FALSE,
      '#options' => [
        '' => $this->t('- Select -'),
        'us_citizen' => $this->t('US Citizen'),
        'permanent_resident' => $this->t('Permanent Resident'),
        'h1b' => $this->t('Work Visa (H1B)'),
        'f1' => $this->t('Student Visa (F1)'),
        'visa_required' => $this->t('Visa Sponsorship Required'),
        'other' => $this->t('Other'),
      ],
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_work_authorization'),
    ];

    $form['search_assist']['field_us_work_authorized'] = [
      '#type' => 'radios',
      '#title' => $this->t('Are you either a US citizen or an alien lawfully authorized to work in the US?'),
      '#required' => FALSE,
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
      ],
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_us_work_authorized') ?: NULL,
    ];

    $form['search_assist']['field_requires_sponsorship'] = [
      '#type' => 'radios',
      '#title' => $this->t('Do you now or will you at any time in the future require sponsorship?'),
      '#required' => FALSE,
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
      ],
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_requires_sponsorship') ?: NULL,
    ];

    $form['search_assist']['field_age_18_or_older'] = [
      '#type' => 'radios',
      '#title' => $this->t('Are you at least 18 years old?'),
      '#required' => FALSE,
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
      ],
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_age_18_or_older') ?: NULL,
    ];

    $form['search_assist']['field_hear_about_us'] = [
      '#type' => 'textfield',
      '#title' => $this->t('How Did You Hear About Us?'),
      '#description' => $this->t('Used for ATS application questions (examples: LinkedIn, company careers site, referral).'),
      '#required' => FALSE,
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_hear_about_us'),
    ];

    $form['search_assist']['field_prior_company_employment'] = [
      '#type' => 'radios',
      '#title' => $this->t('Have you ever been employed with this company before?'),
      '#required' => FALSE,
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
      ],
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_prior_company_employment') ?: NULL,
    ];

    $form['search_assist']['field_prior_company_wwid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Prior Company WWID (if applicable)'),
      '#required' => FALSE,
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_prior_company_wwid'),
    ];

    $form['search_assist']['field_prior_company_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Prior Company Email (if applicable)'),
      '#required' => FALSE,
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_prior_company_email'),
    ];

    $form['search_assist']['field_phone_device_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Phone Device Type'),
      '#required' => FALSE,
      '#options' => [
        '' => $this->t('- Select -'),
        'mobile' => $this->t('Mobile'),
        'home' => $this->t('Home'),
        'work' => $this->t('Work'),
        'other' => $this->t('Other'),
      ],
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_phone_device_type'),
    ];

    $form['search_assist']['salary_range'] = [
      '#type' => 'container',
      '#title' => $this->t('Salary Expectations'),
      '#title_display' => 'above',
      '#description' => $this->t('All salary values are in USD.'),
    ];

    $salary_min = $this->getConsolidatedValue($job_seeker_profile, 'field_salary_expectation_min');
    $salary_max = $this->getConsolidatedValue($job_seeker_profile, 'field_salary_expectation_max');
    if (empty($salary_min) && empty($salary_max) && $job_seeker_profile && isset($job_seeker_profile->salary_expectation)) {
      $parts = explode(' - ', $job_seeker_profile->salary_expectation);
      $salary_min = isset($parts[0]) && $parts[0] !== '0' ? $parts[0] : '';
      $salary_max = isset($parts[1]) && $parts[1] !== 'Open' ? $parts[1] : '';
    }

    $form['search_assist']['salary_range']['field_salary_expectation_min'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum Salary Expectation'),
      '#description' => $this->t('Annual salary'),
      '#min' => 0,
      '#max' => 999999,
      '#step' => 1000,
      '#default_value' => $salary_min,
    ];

    $form['search_assist']['salary_range']['field_salary_expectation_max'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Salary Expectation'),
      '#description' => $this->t('Annual salary'),
      '#min' => 0,
      '#max' => 999999,
      '#step' => 1000,
      '#default_value' => $salary_max,
    ];

    $form['search_assist']['field_salary_change_minimum'] = [
      '#type' => 'number',
      '#title' => $this->t('Salary Requirement to Change Organizations'),
      '#description' => $this->t('Minimum annual salary required to switch jobs'),
      '#min' => 0,
      '#max' => 999999,
      '#step' => 1000,
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_salary_change_minimum'),
    ];

    $form['search_assist']['field_available_start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Available Start Date'),
      '#description' => $this->t('Earliest date you can start work'),
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_available_start_date'),
    ];

    $form['search_assist']['field_remote_preference'] = [
      '#type' => 'select',
      '#title' => $this->t('Remote Work Preference'),
      '#description' => $this->t('Your preference for remote work arrangements'),
      '#options' => [
        '' => $this->t('- Select -'),
        'remote' => $this->t('Remote'),
        'hybrid' => $this->t('Hybrid'),
        'onsite' => $this->t('On-site'),
        'no_preference' => $this->t('No Preference'),
      ],
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_remote_preference'),
    ];

    $relocation_value = $this->getConsolidatedValue($job_seeker_profile, 'field_relocation_willing', NULL);
    $relocation_default = NULL;
    if ($relocation_value !== NULL && $relocation_value !== '') {
      if (is_bool($relocation_value)) {
        $relocation_default = $relocation_value ? 'yes' : 'no';
      } elseif (is_numeric($relocation_value)) {
        $relocation_default = ((int) $relocation_value === 1) ? 'yes' : 'no';
      } elseif (is_string($relocation_value)) {
        $normalized = strtolower(trim($relocation_value));
        if (in_array($normalized, ['yes', 'no'], TRUE)) {
          $relocation_default = $normalized;
        } elseif (in_array($normalized, ['true', 'false'], TRUE)) {
          $relocation_default = $normalized === 'true' ? 'yes' : 'no';
        } elseif (in_array($normalized, ['1', '0'], TRUE)) {
          $relocation_default = $normalized === '1' ? 'yes' : 'no';
        }
      }
    }

    $form['search_assist']['field_relocation_willing'] = [
      '#type' => 'radios',
      '#title' => $this->t('Willing to Relocate'),
      '#description' => $this->t('Are you willing to relocate for the right opportunity?'),
      '#required' => FALSE,
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
      ],
      '#default_value' => $relocation_default,
    ];

    $form['search_assist']['field_keywords_interested'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Job Keywords of Interest'),
      '#description' => $this->t('Keywords and job types you are interested in (one per line)'),
      '#rows' => 4,
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_keywords_interested'),
    ];
    
    // Add suggested keywords from resume parsing
    $suggested_keywords = $this->getSuggestedKeywords($job_seeker_profile);
    if (!empty($suggested_keywords)) {
      $form['search_assist']['suggested_keywords'] = [
        '#type' => 'details',
        '#title' => $this->t('💡 Suggested Keywords (from your resume)'),
        '#description' => $this->t('These keywords were extracted from your resume. Click any keyword to add it to your list above.'),
        '#open' => TRUE,
        '#attributes' => ['class' => ['suggested-keywords-section']],
      ];
      
      $keywords_markup = '<div class="jh-profile__keywords">';
      foreach ($suggested_keywords as $keyword) {
        $keywords_markup .= '<span class="jh-profile__keyword-chip" data-keyword="' . htmlspecialchars(addslashes($keyword), ENT_QUOTES) . '">' . htmlspecialchars($keyword) . '</span>';
      }
      $keywords_markup .= '</div>';
      
      $form['search_assist']['suggested_keywords']['keywords_display'] = [
        '#type' => 'markup',
        '#markup' => $keywords_markup,
      ];
      
      // Attach the user-profile library which handles keyword click behavior
      $form['search_assist']['suggested_keywords']['#attached']['library'][] = 'job_hunter/user_profile';
    }

    $form['search_assist']['field_target_job_titles'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Target Job Titles'),
      '#description' => $this->t('Desired job titles and roles (one per line)'),
      '#rows' => 4,
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_target_job_titles'),
    ];

    $auto_cover_letter = $form_state->get('generated_cover_letter');
    if (!$auto_cover_letter && $job_seeker_profile && !empty($consolidated)) {
      $existing_cover_letter = $consolidated['job_search_preferences']['cover_letter_template'] ?? '';
      if (empty($existing_cover_letter)) {
        $generated_cover_letter = $this->buildCoverLetterTemplate($consolidated);
        if (!empty($generated_cover_letter)) {
          $auto_cover_letter = $generated_cover_letter;
          $form_state->set('generated_cover_letter', $generated_cover_letter);

          $updated_consolidated = $consolidated;
          if (!isset($updated_consolidated['job_search_preferences'])) {
            $updated_consolidated['job_search_preferences'] = [];
          }
          $updated_consolidated['job_search_preferences']['cover_letter_template'] = $generated_cover_letter;

          $this->jobSeekerService->update($job_seeker_profile->id, [
            'consolidated_profile_json' => json_encode($updated_consolidated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
          ]);
        }
      }
    }

    $form['search_assist']['field_cover_letter_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cover Letter Template'),
      '#description' => $this->t('Default cover letter template for applications'),
      '#rows' => 6,
      '#default_value' => $auto_cover_letter ?: $this->getConsolidatedValue($job_seeker_profile, 'field_cover_letter_template'),
    ];

    $form['search_assist']['field_references_available'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('References Available Upon Request'),
      '#description' => $this->t('Check if you can provide professional references'),
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_references_available', 0),
    ];

    $form['search_assist']['demographic_info'] = [
      '#type' => 'details',
      '#title' => $this->t('📋 Demographic Information (Optional - For EEO Purposes)'),
      '#description' => $this->t('This information is optional and used for Equal Employment Opportunity (EEO) reporting. Providing this information is voluntary and will not affect your job search.'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['jh-profile__demographic-info']],
    ];

    $form['search_assist']['demographic_info']['field_gender'] = [
      '#type' => 'select',
      '#title' => $this->t('Gender Identity'),
      '#description' => $this->t('Optional - For EEO purposes only'),
      '#options' => [
        '' => $this->t('- Prefer not to answer -'),
        'male' => $this->t('Male'),
        'female' => $this->t('Female'),
        'non_binary' => $this->t('Non-binary'),
        'other' => $this->t('Other'),
        'prefer_not_to_say' => $this->t('Prefer not to say'),
      ],
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_gender', ''),
    ];

    $form['search_assist']['demographic_info']['field_race_ethnicity'] = [
      '#type' => 'select',
      '#title' => $this->t('Race/Ethnicity'),
      '#description' => $this->t('Optional - For EEO purposes only'),
      '#options' => [
        '' => $this->t('- Prefer not to answer -'),
        'american_indian' => $this->t('American Indian or Alaska Native'),
        'asian' => $this->t('Asian'),
        'black' => $this->t('Black or African American'),
        'hispanic' => $this->t('Hispanic or Latino'),
        'native_hawaiian' => $this->t('Native Hawaiian or Other Pacific Islander'),
        'white' => $this->t('White'),
        'two_or_more' => $this->t('Two or More Races'),
        'prefer_not_to_say' => $this->t('Prefer not to say'),
      ],
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_race_ethnicity', ''),
    ];

    $form['search_assist']['demographic_info']['field_veteran_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Veteran Status'),
      '#description' => $this->t('Optional - For EEO purposes only'),
      '#options' => [
        '' => $this->t('- Prefer not to answer -'),
        'not_veteran' => $this->t('I am not a protected veteran'),
        'veteran' => $this->t('I identify as one or more of the classifications of protected veteran'),
        'prefer_not_to_say' => $this->t('Prefer not to say'),
      ],
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_veteran_status', ''),
    ];

    $form['search_assist']['demographic_info']['field_disability_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Disability Status'),
      '#description' => $this->t('Optional - For EEO purposes only'),
      '#options' => [
        '' => $this->t('- Prefer not to answer -'),
        'no_disability' => $this->t('No, I do not have a disability'),
        'yes_disability' => $this->t('Yes, I have a disability (or previously had a disability)'),
        'prefer_not_to_say' => $this->t('Prefer not to say'),
      ],
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_disability_status', ''),
    ];

    // Core Information Section - Contact Info + Professional Summary
    $form['core_info'] = [
      '#type' => 'details',
      '#title' => $this->t('👤 Contact & Professional Summary'),
      '#description' => $this->t('Your contact information and professional overview'),
      '#open' => FALSE,
      '#weight' => 20,
      '#prefix' => '<div id="job-hunter-core-info">',
      '#suffix' => '</div>',
    ];
    
    // Contact info fields
    $contact_info = $consolidated['contact_info'] ?? [];
    $form['core_info']['field_full_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full Name'),
      '#default_value' => $contact_info['full_name'] ?? '',
    ];
    $form['core_info']['field_headline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Professional Headline'),
      '#default_value' => $contact_info['headline'] ?? '',
    ];
    $form['core_info']['field_credentials'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Credentials (comma-separated)'),
      '#default_value' => is_array($contact_info['credentials'] ?? null) ? implode(', ', $contact_info['credentials']) : ($contact_info['credentials'] ?? ''),
    ];
    $form['core_info']['field_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone'),
      '#default_value' => $contact_info['phone'] ?? '',
    ];
    $form['core_info']['field_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $contact_info['email'] ?? '',
    ];
    $form['core_info']['location_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];
    $form['core_info']['location_container']['field_city'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City'),
      '#default_value' => $contact_info['location']['city'] ?? '',
      '#size' => 20,
    ];
    $form['core_info']['location_container']['field_state'] = [
      '#type' => 'textfield',
      '#title' => $this->t('State'),
      '#default_value' => $contact_info['location']['state'] ?? '',
      '#size' => 10,
    ];
    $form['core_info']['location_container']['field_country'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Country'),
      '#default_value' => $contact_info['location']['country'] ?? '',
      '#size' => 16,
    ];

    $form['core_info']['field_professional_summary'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Professional Summary'),
      '#description' => $this->t('Professional summary or objective statement'),
      '#rows' => 6,
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_professional_summary'),
    ];

    $form['core_info']['field_skills_summary'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Skills Summary'),
      '#description' => $this->t('Overview of your technical and professional skills'),
      '#rows' => 5,
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_skills_summary'),
    ];

    // Experience & Education & Credentials Section
    $form['experience_education'] = [
      '#type' => 'details',
      '#title' => $this->t('🎓 Experience, Education & Credentials'),
      '#open' => TRUE,
      '#weight' => 40,
    ];

    // Calculate years of experience from education history (graduation date)
    $calculated_years = 0;
    if ($job_seeker_profile && !empty($job_seeker_profile->id)) {
      $calculated_years = $this->calculateYearsOfExperience($job_seeker_profile->id);
    }

    // Use calculated value if available, otherwise fall back to stored value
    $experience_years = $calculated_years > 0 ? $calculated_years : $this->getConsolidatedValue($job_seeker_profile, 'field_experience_years');

    $form['experience_education']['field_experience_years'] = [
      '#type' => 'number',
      '#title' => $this->t('Years of Professional Experience'),
      '#description' => $calculated_years > 0
        ? $this->t('Automatically calculated from your earliest graduation date (@years years). This field is read-only.', ['@years' => $calculated_years])
        : $this->t('Will be automatically calculated when you add education history with graduation dates.'),
      '#min' => 0,
      '#max' => 50,
      '#default_value' => $experience_years,
      '#disabled' => $calculated_years > 0,
    ];

    $form['experience_education']['field_education_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Education Level'),
      '#description' => $this->t('Highest level of education completed'),
      '#options' => [
        '' => $this->t('- Select -'),
        'high_school' => $this->t('High School'),
        'associates' => $this->t('Associates Degree'),
        'bachelors' => $this->t('Bachelors Degree'),
        'masters' => $this->t('Masters Degree'),
        'doctoral' => $this->t('Doctoral Degree'),
        'professional' => $this->t('Professional Degree'),
      ],
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_education_level'),
    ];

    // Education History Section — delegated to EducationHistorySubform.
    $this->educationHistorySubform->buildFormElements(
      $form,
      $form_state,
      $job_seeker_profile,
      $this->formatSectionForTextarea($consolidated, 'education')
    );

    // Professional Experience Section (editable JSON)
    $form['professional_experience'] = [
      '#type' => 'details',
      '#title' => $this->t('💼 Professional Experience'),
      '#description' => $this->t('Edit core fields directly. Optional fields are under Advanced details. Save to persist changes.'),
      '#open' => TRUE,
      '#weight' => 30,
    ];

    $form['professional_experience']['experience_editor'] = [
      '#type' => 'container',
      '#prefix' => '<div id="professional-experience-editor-wrapper">',
      '#suffix' => '</div>',
    ];

    $action_message = (string) ($form_state->get('professional_experience_action_message') ?? '');
    if ($action_message !== '') {
      $form['professional_experience']['experience_editor']['action_status'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--status">' . htmlspecialchars($action_message) . '</div>',
      ];
    }

    $existing_roles = $form_state->get('professional_experience_entries_state');
    if (!is_array($existing_roles)) {
      $existing_roles = $consolidated['professional_experience'] ?? [];
      if (!is_array($existing_roles)) {
        $existing_roles = [];
      }
      $existing_roles = array_values($existing_roles);
      $form_state->set('professional_experience_entries_state', $existing_roles);
    }

    $existing_roles = $this->ensureProfessionalExperienceRowIds($existing_roles);
    $this->setProfessionalExperienceEntriesState($form_state, $existing_roles, FALSE);

    // Track number of rows in form state (AJAX add/remove).
    $row_count = $form_state->get('professional_experience_row_count');
    if ($row_count === NULL) {
      $row_count = max(1, count($existing_roles));
      $form_state->set('professional_experience_row_count', $row_count);
    }

    $form['professional_experience']['experience_editor']['professional_experience_entries'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    for ($i = 0; $i < $row_count; $i++) {
      $role = is_array($existing_roles[$i] ?? NULL) ? $existing_roles[$i] : [];
      $entry_label = $this->buildProfessionalExperienceEntryLabel($role, $i);
      $entry_slug = $this->slugifyProfessionalExperienceLabel($entry_label);
      $entry_row_id = (string) ($role['_row_id'] ?? '');

      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['professional-experience-entry']],
      ];

      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['entry_label'] = [
        '#type' => 'markup',
        '#markup' => '<h4>' . htmlspecialchars($entry_label) . '</h4>',
      ];

      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['company'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Company'),
        '#default_value' => $role['company'] ?? '',
      ];
      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Title'),
        '#default_value' => $role['title'] ?? '',
      ];

      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['dates'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['container-inline']],
      ];
      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['dates']['start_date'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Start (YYYY-MM)'),
        '#size' => 10,
        '#default_value' => $role['start_date'] ?? '',
      ];
      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['dates']['end_date'] = [
        '#type' => 'textfield',
        '#title' => $this->t('End (YYYY-MM or blank)'),
        '#size' => 10,
        '#default_value' => $role['end_date'] ?? '',
      ];

      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['location'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Location'),
        '#default_value' => $role['location'] ?? '',
      ];
      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['_row_id'] = [
        '#type' => 'hidden',
        '#value' => $entry_row_id,
      ];

      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['advanced'] = [
        '#type' => 'container',
      ];
      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['advanced']['advanced_label'] = [
        '#type' => 'markup',
        '#markup' => '<strong>' . $this->t('Advanced details (optional)') . '</strong>',
      ];
      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['advanced']['employment_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Employment Type'),
        '#options' => [
          '' => $this->t('- Select -'),
          'direct' => $this->t('Direct'),
          'consulting' => $this->t('Consulting'),
        ],
        '#default_value' => $role['employment_type'] ?? '',
      ];
      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['advanced']['via_company'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Via Company (optional)'),
        '#default_value' => $role['via_company'] ?? '',
      ];

      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['advanced']['company_context'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Company Context (optional)'),
        '#rows' => 2,
        '#default_value' => $role['company_context'] ?? '',
      ];

      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['highlights'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Highlights (optional)'),
        '#rows' => 4,
        '#default_value' => ((string) ($role['highlights'] ?? '') !== '')
          ? (string) $role['highlights']
          : (((string) ($role['description'] ?? '') !== '')
            ? (string) $role['description']
            : $this->buildFallbackHighlightsFromRole($role)),
      ];

      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['key_achievements'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Key Achievements (one per line)'),
        '#rows' => 4,
        '#default_value' => is_array($role['key_achievements'] ?? NULL)
          ? implode("\n", $role['key_achievements'])
          : ((string) ($role['key_achievements'] ?? '') !== ''
            ? $role['key_achievements']
            : implode("\n", $this->extractExperienceAchievementTexts($role))),
      ];

      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['technologies'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Technologies (comma or newline separated)'),
        '#rows' => 3,
        '#default_value' => is_array($role['technologies'] ?? NULL)
          ? implode("\n", $role['technologies'])
          : ((string) ($role['technologies'] ?? '') !== ''
            ? $role['technologies']
            : implode("\n", $this->extractExperienceTechnologies($role))),
      ];

      $form['professional_experience']['experience_editor']['professional_experience_entries'][$i]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove @label', ['@label' => $entry_label]),
        '#name' => 'remove_professional_experience_' . $i . '_' . $entry_slug,
        '#submit' => ['::removeProfessionalExperienceRow'],
        '#limit_validation_errors' => [],
        '#attributes' => [
          'class' => ['button'],
          'data-remove-index' => (string) $i,
          'data-remove-row-id' => $entry_row_id,
          'data-remove-name' => $entry_label,
          'data-remove-key' => $entry_slug,
        ],
      ];
    }

    $form['professional_experience']['experience_editor']['add_role'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Role'),
      '#submit' => ['::addProfessionalExperienceRow'],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    // Technical Expertise Section (editable text)
    $form['technical_expertise_section'] = [
      '#type' => 'details',
      '#title' => $this->t('🛠️ Technical Expertise'),
      '#open' => FALSE,
      '#weight' => 50,
    ];
    $form['technical_expertise_section']['info'] = [
      '#markup' => '<p class="description"><em>Edit one item per line. Save to apply changes.</em></p>',
    ];
    $form['technical_expertise_section']['field_technical_expertise_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Technical Expertise'),
      '#default_value' => $this->formatSectionForTextarea($consolidated, 'technical_expertise'),
      '#rows' => 15,
      
    ];

    // Strategic Differentiators Section (editable text)
    $form['strategic_differentiators_section'] = [
      '#type' => 'details',
      '#title' => $this->t('🎯 Strategic Differentiators'),
      '#open' => FALSE,
      '#weight' => 60,
    ];
    $form['strategic_differentiators_section']['info'] = [
      '#markup' => '<p class="description"><em>Edit one item per line. Save to apply changes.</em></p>',
    ];
    $form['strategic_differentiators_section']['field_strategic_differentiators_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Strategic Differentiators'),
      '#default_value' => $this->formatSectionForTextarea($consolidated, 'strategic_differentiators'),
      '#rows' => 10,
      
    ];

    // Leadership Philosophy Section (editable text)
    $form['leadership_section'] = [
      '#type' => 'details',
      '#title' => $this->t('🧭 Leadership Philosophy'),
      '#open' => FALSE,
      '#weight' => 70,
    ];
    $form['leadership_section']['info'] = [
      '#markup' => '<p class="description"><em>Edit one item per line. Save to apply changes.</em></p>',
    ];
    $form['leadership_section']['field_leadership_philosophy_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Leadership Philosophy'),
      '#default_value' => $this->formatSectionForTextarea($consolidated, 'leadership_philosophy'),
      '#rows' => 8,
      
    ];

    // Demonstration Projects Section (editable text)
    $form['demonstration_projects_section'] = [
      '#type' => 'details',
      '#title' => $this->t('🚀 Demonstration Projects'),
      '#open' => FALSE,
      '#weight' => 80,
    ];
    $form['demonstration_projects_section']['info'] = [
      '#markup' => '<p class="description"><em>Edit one project per line. Save to apply changes.</em></p>',
    ];
    $form['demonstration_projects_section']['field_demonstration_projects_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Demonstration Projects'),
      '#default_value' => $this->formatSectionForTextarea($consolidated, 'demonstration_projects'),
      '#rows' => 10,
      
    ];

    // Publications Section (editable text)
    $form['publications_section'] = [
      '#type' => 'details',
      '#title' => $this->t('📚 Publications & Research'),
      '#open' => FALSE,
      '#weight' => 90,
    ];
    $form['publications_section']['info'] = [
      '#markup' => '<p class="description"><em>Edit one publication per line. Save to apply changes.</em></p>',
    ];
    $form['publications_section']['field_publications_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Publications'),
      '#description' => $this->t('One publication per line.'),
      '#default_value' => $this->formatSectionForTextarea($consolidated, 'publications'),
      '#rows' => 10,
      
    ];

    // Certifications Section (editable text)
    $form['certifications_section'] = [
      '#type' => 'details',
      '#title' => $this->t('🏆 Certifications & Licenses'),
      '#open' => FALSE,
      '#weight' => 100,
    ];
    $form['certifications_section']['info'] = [
      '#markup' => '<p class="description"><em>Edit one certification per line. Save to apply changes.</em></p>',
    ];

    $certifications_default = $this->formatSectionForTextarea($consolidated, 'certifications');
    if ($certifications_default === '' && !empty($consolidated['job_search_preferences']['certifications'])) {
      $legacy_certs = $consolidated['job_search_preferences']['certifications'];
      if (is_array($legacy_certs)) {
        $certifications_default = implode("\n", array_values(array_filter(array_map(function ($cert): string {
          if (is_array($cert)) {
            return trim((string) ($cert['name'] ?? ''));
          }
          return trim((string) $cert);
        }, $legacy_certs))));
      }
      elseif (is_string($legacy_certs)) {
        $certifications_default = $legacy_certs;
      }
    }

    $form['certifications_section']['field_certifications_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Certifications'),
      '#description' => $this->t('One certification per line.'),
      '#default_value' => $certifications_default,
      '#rows' => 8,
      
    ];

    // Patents Section (editable text)
    $form['patents_section'] = [
      '#type' => 'details',
      '#title' => $this->t('🔬 Patents & Intellectual Property'),
      '#open' => FALSE,
      '#weight' => 110,
    ];
    $form['patents_section']['info'] = [
      '#markup' => '<p class="description"><em>Edit one patent per line. Save to apply changes.</em></p>',
    ];
    $form['patents_section']['field_patents_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Patents'),
      '#description' => $this->t('One patent per line.'),
      '#default_value' => $this->formatSectionForTextarea($consolidated, 'patents'),
      '#rows' => 8,
      
    ];

    // Awards & Honors Section (editable text)
    $form['awards_section'] = [
      '#type' => 'details',
      '#title' => $this->t('🏅 Awards & Honors'),
      '#open' => FALSE,
      '#weight' => 130,
    ];
    $form['awards_section']['info'] = [
      '#markup' => '<p class="description"><em>Edit one award per line. Save to apply changes.</em></p>',
    ];
    $form['awards_section']['field_awards_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Awards & Honors'),
      '#description' => $this->t('One award per line.'),
      '#default_value' => $this->formatSectionForTextarea($consolidated, 'awards_and_honors'),
      '#rows' => 8,
      
    ];

    // Languages Section (editable text)
    $form['languages_section'] = [
      '#type' => 'details',
      '#title' => $this->t('🌍 Languages & Proficiencies'),
      '#open' => FALSE,
      '#weight' => 140,
    ];
    $form['languages_section']['info'] = [
      '#markup' => '<p class="description"><em>Edit one language per line. Save to apply changes.</em></p>',
    ];
    $form['languages_section']['field_languages_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Languages'),
      '#description' => $this->t('One language per line.'),
      '#default_value' => $this->formatSectionForTextarea($consolidated, 'languages'),
      '#rows' => 6,
      
    ];

    // Consulting Practice Section (editable text)
    $form['consulting_practice_section'] = [
      '#type' => 'details',
      '#title' => $this->t('💼 Consulting Practice'),
      '#open' => FALSE,
      '#weight' => 120,
    ];
    $form['consulting_practice_section']['info'] = [
      '#markup' => '<p class="description"><em>Edit one engagement per line. Save to apply changes.</em></p>',
    ];
    $form['consulting_practice_section']['field_consulting_practice_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Consulting Practice'),
      '#default_value' => $this->formatSectionForTextarea($consolidated, 'consulting_practice'),
      '#rows' => 10,
      
    ];

    // Early Career Section (editable text)
    $form['early_career_section'] = [
      '#type' => 'details',
      '#title' => $this->t('📜 Early Career'),
      '#open' => FALSE,
      '#weight' => 150,
    ];
    $form['early_career_section']['info'] = [
      '#markup' => '<p class="description"><em>Edit one item per line. Save to apply changes.</em></p>',
    ];
    $form['early_career_section']['field_early_career_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Early Career'),
      '#default_value' => $this->formatSectionForTextarea($consolidated, 'early_career'),
      '#rows' => 6,
      
    ];

    // Online Presence Section
    $form['online_presence'] = [
      '#type' => 'details',
      '#title' => $this->t('🌐 Online Presence'),
      '#open' => FALSE,
      '#weight' => 160,
    ];

    $form['online_presence']['field_portfolio_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Portfolio / Website'),
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_portfolio_url'),
    ];

    $form['online_presence']['field_linkedin_url'] = [
      '#type' => 'url',
      '#title' => $this->t('LinkedIn'),
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_linkedin_url'),
    ];

    $form['online_presence']['field_github_url'] = [
      '#type' => 'url',
      '#title' => $this->t('GitHub'),
      '#default_value' => $this->getConsolidatedValue($job_seeker_profile, 'field_github_url'),
    ];

    // Actions
    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 170,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Profile'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('entity.user.canonical', ['user' => $uid]),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    // DANGER ZONE - Delete All Data Section
    $form['danger_zone'] = [
      '#type' => 'details',
      '#title' => $this->t('⚠️ DANGER ZONE'),
      '#open' => FALSE,
      '#weight' => 1000,
      '#attributes' => [
        'class' => ['jh-profile__danger'],
      ],
    ];

    $form['danger_zone']['warning'] = [
      '#markup' => '<div class="jh-profile__danger-warning"><strong class="jh-profile__danger-title">' . $this->t('⚠️ WARNING: PERMANENT DATA DELETION') . '</strong><p class="jh-profile__danger-text">' . $this->t('The button below will <strong>permanently delete</strong> all your profile data, uploaded resumes, and parsed information. This action cannot be undone.') . '</p></div>',
    ];

    $form['danger_zone']['delete_all_resumes'] = [
      '#type' => 'submit',
      '#value' => $this->t('🗑️ DELETE ALL PROFILE & RESUME DATA'),
      '#submit' => ['::deleteAllResumeDataSubmit'],
      '#limit_validation_errors' => [],
      '#validate' => [],
      '#attributes' => [
        'class' => ['button', 'button--danger', 'jh-profile__danger-btn'],
        'data-confirm-message' => $this->t('⚠️ FINAL WARNING: Are you ABSOLUTELY SURE you want to delete ALL profile and resume data? This will permanently remove all uploaded resume files, all parsed resume data, and all profile information. This action CANNOT be undone.'),
      ],
    ];

    // Standardize JSON editing + formatted display inside accordion sections.
    $this->standardizeAccordionJsonFields($form);

    return $form;
  }

  /**
   * Standardize JSON field editing and display inside accordion sections.
   *
   * For each JSON textarea inside a details section, inject a display element
   * immediately above it:
   * - If JSON is valid: show pretty-printed JSON.
   * - If JSON is invalid: show the parse error message.
   *
   * The JSON textarea remains editable.
   */
  private function standardizeAccordionJsonFields(array &$form): void {
    $this->walkForJsonFields($form, FALSE);
  }

  /**
   * Recursive walk to find JSON textareas under details sections.
   */
  private function walkForJsonFields(array &$element, bool $in_details): void {
    $current_in_details = $in_details;
    if (isset($element['#type']) && $element['#type'] === 'details') {
      $current_in_details = TRUE;
    }

    foreach ($element as $key => &$child) {
      if (!is_string($key) || str_starts_with($key, '#') || !is_array($child)) {
        continue;
      }

      // Recurse into children first.
      $this->walkForJsonFields($child, $current_in_details);

      if (!$current_in_details) {
        continue;
      }

      if (($child['#type'] ?? NULL) !== 'textarea') {
        continue;
      }

      if (!empty($child['#job_hunter_json_standardized'])) {
        continue;
      }

      if (!$this->isJsonFieldKey($key)) {
        continue;
      }

      // Ensure JSON fields are editable.
      if (isset($child['#disabled']) && $child['#disabled'] === TRUE) {
        $child['#disabled'] = FALSE;
      }

      $this->wrapJsonTextareaInAccordion($child);
      $child['#job_hunter_json_standardized'] = TRUE;
    }
    unset($child);
  }

  /**
   * Determine whether a form element key represents a JSON field.
   */
  private function isJsonFieldKey(string $key): bool {
    $key_lower = strtolower($key);
    if ($key_lower === 'consolidated_profile_json') {
      return TRUE;
    }
    return str_contains($key_lower, 'json') || str_ends_with($key_lower, '_json');
  }

  /**
   * Render the JSON display markup.
   */
  private function wrapJsonTextareaInAccordion(array &$textarea): void {
    if (!empty($textarea['#jh_json_wrapped'])) {
      return;
    }

    $title = (string) ($textarea['#title'] ?? $this->t('JSON'));
    $raw = (string) ($textarea['#default_value'] ?? '');
    $formatted_markup = $this->buildJsonFormattedMarkup($raw);

    // Hide the field label and use the accordion summary as the label.
    $textarea['#title_display'] = 'invisible';

    $textarea['#prefix'] =
      '<details class="jh-json-field">'
      . '<summary>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</summary>'
      . '<div class="details-wrapper">'
      . $formatted_markup
      . '<div class="jh-json-editor">';

    $textarea['#suffix'] = '</div></div></details>';
    $textarea['#jh_json_wrapped'] = TRUE;
  }

  /**
   * Build a theme-styled, human-readable rendering of JSON.
   * If JSON is invalid, returns an error block.
   */
  private function buildJsonFormattedMarkup(string $raw): string {
    $raw_trimmed = trim($raw);
    if ($raw_trimmed === '') {
      return '<div class="jh-json-render"><em>'
        . htmlspecialchars((string) $this->t('No data yet.'), ENT_QUOTES, 'UTF-8')
        . '</em></div>';
    }

    $decoded = json_decode($raw_trimmed, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return '<div class="jh-json-render"><div class="messages messages--error">'
        . htmlspecialchars((string) $this->t('Invalid JSON: @error', ['@error' => json_last_error_msg()]), ENT_QUOTES, 'UTF-8')
        . '</div></div>';
    }

    return '<div class="jh-json-render">' . $this->renderJsonValue($decoded) . '</div>';
  }

  /**
   * Render a decoded JSON value as HTML.
   */
  private function renderJsonValue(mixed $value, int $depth = 0): string {
    if ($depth > 6) {
      return '<em>' . htmlspecialchars((string) $this->t('…'), ENT_QUOTES, 'UTF-8') . '</em>';
    }

    if (is_array($value)) {
      $is_assoc = array_keys($value) !== range(0, count($value) - 1);
      $html = $is_assoc ? '<dl class="jh-json-dl">' : '<ul class="jh-json-ul">';
      foreach ($value as $k => $v) {
        if ($is_assoc) {
          $html .= '<dt>' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '</dt>';
          $html .= '<dd>' . $this->renderJsonValue($v, $depth + 1) . '</dd>';
        }
        else {
          $html .= '<li>' . $this->renderJsonValue($v, $depth + 1) . '</li>';
        }
      }
      $html .= $is_assoc ? '</dl>' : '</ul>';
      return $html;
    }

    if (is_bool($value)) {
      return htmlspecialchars($value ? 'true' : 'false', ENT_QUOTES, 'UTF-8');
    }
    if ($value === NULL) {
      return '<em>null</em>';
    }

    // Numbers and strings.
    return nl2br(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'));
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate consolidated JSON if provided
    $consolidated_json = $form_state->getValue('consolidated_profile_json');
    if (!empty($consolidated_json)) {
      $decoded = json_decode($consolidated_json, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $form_state->setErrorByName('consolidated_profile_json', 
          $this->t('Consolidated JSON must be valid JSON format. Error: @error', 
            ['@error' => json_last_error_msg()]));
      }
    }
    
    // Validate individual parsed JSON fields
    $values = $form_state->getValues();
    foreach ($values as $key => $value) {
      if (strpos($key, 'parsed_data_') === 0 && !empty($value)) {
        $decoded = json_decode($value, TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
          $form_state->setErrorByName($key, 
            $this->t('Parsed JSON must be valid JSON format. Error: @error', 
              ['@error' => json_last_error_msg()]));
        }
      }
    }
    
    // Section editors now accept plain text (one item per line), so no JSON
    // validation is required for those fields.

    // Validate professional experience entry dates.
    $entries = $form_state->getValue(['professional_experience', 'experience_editor', 'professional_experience_entries']);
    if (!is_array($entries)) {
      $entries = $form_state->getValue('professional_experience_entries');
    }
    if (is_array($entries)) {
      foreach ($entries as $idx => $entry) {
        $start = trim((string) ($entry['dates']['start_date'] ?? ''));
        $end = trim((string) ($entry['dates']['end_date'] ?? ''));
        if ($start !== '' && !preg_match('/^\d{4}-\d{2}$/', $start)) {
          $form_state->setErrorByName('professional_experience', $this->t('Role @num: Start date must be in YYYY-MM format.', ['@num' => $idx + 1]));
        }
        if ($end !== '' && !preg_match('/^\d{4}-\d{2}$/', $end)) {
          $form_state->setErrorByName('professional_experience', $this->t('Role @num: End date must be in YYYY-MM format (or blank).', ['@num' => $idx + 1]));
        }
      }
    }
    
    // Validate salary range
    $min_salary = $form_state->getValue('field_salary_expectation_min');
    $max_salary = $form_state->getValue('field_salary_expectation_max');

    if (!empty($min_salary) && !empty($max_salary) && $min_salary > $max_salary) {
      $form_state->setErrorByName('field_salary_expectation_max', 
        $this->t('Maximum salary must be greater than minimum salary.'));
    }

    // Validate URLs
    $urls = [
      'field_portfolio_url' => 'Portfolio URL',
      'field_linkedin_url' => 'LinkedIn URL',
      'field_github_url' => 'GitHub URL',
    ];

    foreach ($urls as $field => $label) {
      $url = $form_state->getValue($field);
      if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
        $form_state->setErrorByName($field, 
          $this->t('@label must be a valid URL.', ['@label' => $label]));
      }
    }

    // Validate LinkedIn URL format
    $linkedin_url = $form_state->getValue('field_linkedin_url');
    if (!empty($linkedin_url) && strpos($linkedin_url, 'linkedin.com') === FALSE) {
      $form_state->setErrorByName('field_linkedin_url', 
        $this->t('LinkedIn URL should contain linkedin.com'));
    }

    // Validate GitHub URL format
    $github_url = $form_state->getValue('field_github_url');
    if (!empty($github_url) && strpos($github_url, 'github.com') === FALSE) {
      $form_state->setErrorByName('field_github_url', 
        $this->t('GitHub URL should contain github.com'));
    }
  }

  /**
   * AJAX callback to refresh Step 2 after file upload.
   * Automatically registers and parses the uploaded resume.
   */
  public function refreshStep2Callback(array &$form, FormStateInterface $form_state) {
    $this->logInfo('📁 refreshStep2Callback called - Auto-register and parse');
    
    $uid = \Drupal::currentUser()->id();
    
    // Make uploaded file permanent
    $resume_file = $form_state->getValue('field_resume_file');
    
    if (!empty($resume_file[0])) {
      $file = \Drupal\file\Entity\File::load($resume_file[0]);
      if ($file) {
        // Set file to permanent status
        $file->setPermanent();
        $file->save();
        
        $this->logInfo('📁 File uploaded and made permanent: @filename (fid: @fid)', [
          '@filename' => $file->getFilename(),
          '@fid' => $file->id(),
        ]);
        
        // AUTO-REGISTER: Create/load job seeker profile
        $job_seeker_profile = $this->jobSeekerService->loadByUserId($uid);
        if (!$job_seeker_profile) {
          $job_seeker_id = $this->jobSeekerService->create(['uid' => $uid]);
          $job_seeker_profile = $this->jobSeekerService->load($job_seeker_id);
          $this->logInfo('📁 Auto-created job seeker profile for uid: @uid', ['@uid' => $uid]);
        }
        $job_seeker_id = (int) $job_seeker_profile->id;
        
        // Check if already registered
        if (!$this->userProfileRepository->resumeFileRegistered($job_seeker_id, $file->id())) {
          // Check if this is the first resume
          $existing_count = $this->userProfileRepository->countResumesForJobSeeker($job_seeker_id);
          
          $is_primary = ($existing_count == 0) ? 1 : 0;
          
          // Register resume
          $resume_id = $this->userProfileRepository->createResumeRecord(
            $job_seeker_id,
            $file->id(),
            pathinfo($file->getFilename(), PATHINFO_FILENAME),
            $is_primary
          );
          
          $this->logInfo('📁 Auto-registered resume: @filename (resume_id: @id)', [
            '@filename' => $file->getFilename(),
            '@id' => $resume_id,
          ]);
          
          // AUTO-PARSE: Extract text and parse with AI
          try {
            $extracted_text = $this->extractTextFromFile($file);
            
            if (!empty($extracted_text)) {
              // Store extracted text
              $this->userProfileRepository->updateResumeExtractedText($resume_id, $extracted_text);
              
              $this->logInfo('📁 Auto-extracted @chars characters from: @filename', [
                '@chars' => strlen($extracted_text),
                '@filename' => $file->getFilename(),
              ]);
              
              // Always queue for background processing (dev and prod both use Bedrock).
              $timestamp = \Drupal::time()->getRequestTime();

              // Create placeholder record with 'queued' status
              $this->userProfileRepository->insertParsedDataRecord(
                $uid,
                $file->id(),
                $file->getFileUri(),
                json_encode(['status' => 'queued']),
                'queued',
                NULL,
                $timestamp
              );

              // Queue the GenAI parsing job
              $queue = \Drupal::queue('job_hunter_genai_parsing');
              $queue->createItem([
                'uid' => $uid,
                'resume_id' => $resume_id,
                'file_id' => $file->id(),
                'extracted_text' => $extracted_text,
                'filename' => $file->getFilename(),
              ]);

              $this->logInfo('📁 Queued resume for GenAI parsing: @filename', [
                '@filename' => $file->getFilename(),
              ]);
            }
          } catch (\Exception $e) {
            $this->logError('📁 Auto-parse failed: @error', [
              '@error' => $e->getMessage(),
            ]);
          }
        }
      }
    }
    
    // Clear the upload field and show appropriate message
    $form_state->setValue('field_resume_file', []);
    
    \Drupal::messenger()->addStatus($this->t('Resume uploaded! AI parsing has been queued. Please check back in 2-3 minutes for results.'));
    
    // Return AJAX response with redirect to force page reload
    $response = new \Drupal\Core\Ajax\AjaxResponse();
    $response->addCommand(new \Drupal\Core\Ajax\RedirectCommand(\Drupal\Core\Url::fromRoute('job_hunter.user_profile_edit')->toString()));
    return $response;
  }

  /**
   * AJAX callback to refresh the resume workflow section after file upload.
   * Triggers a page reload to show updated status.
   */
  public function refreshResumeWorkflowCallback(array &$form, FormStateInterface $form_state) {
    $this->logInfo('📁 refreshResumeWorkflowCallback called');
    
    // Call the existing processing logic
    return $this->refreshStep2Callback($form, $form_state);
  }

  /**
   * AJAX callback to refresh the upload field for adding another file.
   */
  public function refreshUploadFieldCallback(array &$form, FormStateInterface $form_state) {
    $this->logInfo('📁 refreshUploadFieldCallback called - Ready for new upload');
    
    // Return the upload field element (cleared and ready for new file)
    return $form['resume_workflow']['field_resume_file'];
  }

  /**
   * Submit handler for processing uploaded resume files.
   *
   * Delegated to ResumeUploadSubform.
   */
  public function processUploadedFilesSubmit(array &$form, FormStateInterface $form_state) {
    $this->resumeUploadSubform->processUploadedFilesSubmit($form, $form_state);
  }

  /**
   * Submit handler for "Add Another Resume" button.
   *
   * Delegated to ResumeUploadSubform.
   */
  public function addAnotherResumeSubmit(array &$form, FormStateInterface $form_state) {
    $this->resumeUploadSubform->addAnotherResumeSubmit($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user_entity = $form_state->get('user_entity');
    $uid = $user_entity->id();
    
    // All data saved to consolidated_profile_json only
    $job_seeker_data = [];
    
    // Handle file upload for resume
    $resume_file = $form_state->getValue('field_resume_file');
    if (!empty($resume_file[0])) {
      $file = \Drupal\file\Entity\File::load($resume_file[0]);
      if ($file) {
        $file->setPermanent();
        $file->save();
        $job_seeker_data['resume_node_id'] = $resume_file[0];
      }
    }
    
    // Sync all form fields to consolidated JSON (single source of truth)
    $this->syncFormFieldsToConsolidatedJson($form_state, [], $job_seeker_data);
    
    // Handle consolidated JSON update from textarea (manual edits)
    $consolidated_json = $form_state->getValue('consolidated_profile_json');
    if ($consolidated_json !== NULL && $consolidated_json !== '') {
      // Manual edit takes precedence - merge with synced values
      $manual_json = json_decode($consolidated_json, TRUE);
      if ($manual_json && is_array($manual_json)) {
        // If we have synced data, merge it but manual textarea wins for conflicts
        if (!empty($job_seeker_data['consolidated_profile_json'])) {
          $synced_json = json_decode($job_seeker_data['consolidated_profile_json'], TRUE);
          if ($synced_json && is_array($synced_json)) {
            // Deep merge - manual edits take precedence
            $job_seeker_data['consolidated_profile_json'] = json_encode(
              array_replace_recursive($synced_json, $manual_json),
              JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
          } else {
            $job_seeker_data['consolidated_profile_json'] = $consolidated_json;
          }
        } else {
          $job_seeker_data['consolidated_profile_json'] = $consolidated_json;
        }
      }
    }
    
    // Handle individual parsed JSON updates
    $values = $form_state->getValues();
    foreach ($values as $key => $value) {
      if (strpos($key, 'parsed_data_') === 0 && $value !== NULL && $value !== '') {
        $parsed_id = str_replace('parsed_data_', '', $key);
        $this->userProfileRepository->updateParsedResumeData((int) $parsed_id, (string) $value);
      }
    }
    
    // Check if profile exists
    $existing_profile = $this->jobSeekerService->loadByUserId($uid);
    
    if ($existing_profile) {
      // Update existing profile
      $this->jobSeekerService->update($existing_profile->id, $job_seeker_data);
      $this->logInfo('Updated job_seeker profile for user @uid. Data: @data', [
        '@uid' => $uid,
        '@data' => json_encode($job_seeker_data),
      ]);
    } else {
      // Create new profile
      $job_seeker_data['uid'] = $uid;
      $this->jobSeekerService->create($job_seeker_data);
      $this->logInfo('Created job_seeker profile for user @uid. Data: @data', [
        '@uid' => $uid,
        '@data' => json_encode($job_seeker_data),
      ]);
    }

    $this->messenger->addMessage($this->t('Your profile has been saved successfully.'));

    // Stay on the same page - rebuild the form to show updated values
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for adding a resume.
   *
   * Delegated to ResumeUploadSubform.
   */
  public function addResumeSubmit(array &$form, FormStateInterface $form_state) {
    $this->resumeUploadSubform->addResumeSubmit($form, $form_state);
  }

  /**
   * AJAX callback for resume upload.
   */
  public function uploadResumeCallback(array &$form, FormStateInterface $form_state) {
    \Drupal::logger('job_hunter_debug')->info('=== AJAX CALLBACK CALLED ===');
    \Drupal::logger('job_hunter_debug')->info('AJAX callback: Returning core_info fieldset for rebuild');
    
    // Return the form element to trigger rebuild
    return $form['core_info'];
  }

  /**
   * Submit handler for registering a resume file to the database.
   *
   * Also automatically extracts text and parses with AI.
   * Delegated to ResumeUploadSubform.
   */
  public function registerResumeSubmit(array &$form, FormStateInterface $form_state) {
    $this->resumeUploadSubform->registerResumeSubmit($form, $form_state);
  }

  /**
   * Submit handler for deleting a resume file.
   *
   * Delegated to ResumeUploadSubform.
   */
  public function deleteResumeFileSubmit(array &$form, FormStateInterface $form_state) {
    $this->resumeUploadSubform->deleteResumeFileSubmit($form, $form_state);
  }
    
  /**
   * Submit handler for extracting text from a resume file.
   */
  public function extractTextSubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $resume_id = $triggering_element['#attributes']['data-resume-id'] ?? NULL;
    
    // Debug logging
    $this->logInfo('Extract Text Submit - Triggering element: @element, Resume ID: @resume_id', [
      '@element' => print_r($triggering_element, TRUE),
      '@resume_id' => $resume_id ?? 'NULL',
    ]);
    
    if (!$resume_id) {
      \Drupal::messenger()->addError($this->t('Could not identify resume for text extraction. Button attributes missing.'));
      return;
    }

    try {
      $uid = \Drupal::currentUser()->id();
      
      // Load resume record
      $resume_record = $this->loadResumeRecord($resume_id, $uid);
      
      // Load file and validate
      $file = $this->loadAndValidateFile($resume_record['file_id']);
      $file_uri = $file->getFileUri();
      $filename = basename($file_uri);
      
      // Extract text
      $extracted_text = $this->extractTextFromFile($file);
      if (empty($extracted_text)) {
        throw new \Exception("Unable to extract text from resume file: {$filename}");
      }
      
      // Store extracted text
      $this->storeExtractedText($resume_record['id'], $extracted_text, $filename);
      
      \Drupal::messenger()->addStatus($this->t('Text extracted successfully from "@filename" (@chars characters).', [
        '@filename' => $filename,
        '@chars' => number_format(strlen($extracted_text)),
      ]));
      
    } catch (\Exception $e) {
      $this->logError('Text extraction failed: @message', ['@message' => $e->getMessage()]);
      \Drupal::messenger()->addError($this->t('Failed to extract text: @message', ['@message' => $e->getMessage()]));
    }
    
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for parsing JSON only (assumes text already extracted).
   */
  public function parseJsonOnlySubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $resume_id = $triggering_element['#attributes']['data-resume-id'] ?? NULL;
    
    if (!$resume_id) {
      \Drupal::messenger()->addError($this->t('Could not identify resume for JSON parsing.'));
      return;
    }

    try {
      $uid = \Drupal::currentUser()->id();
      
      // Load resume record
      $resume_record = $this->loadResumeRecord($resume_id, $uid);
      
      // Verify text has been extracted
      if (empty($resume_record['extracted_text'])) {
        throw new \Exception('No extracted text found. Please extract text first.');
      }
      
      // Load file for filename
      $file = $this->loadAndValidateFile($resume_record['file_id']);
      $filename = basename($file->getFileUri());
      $file_uri = $file->getFileUri();
      
      // Parse with AI (skip text extraction step)
      $parsed_data = $this->parseResumeProdMode($resume_record['extracted_text'], $filename);
      
      if (empty($parsed_data)) {
        throw new \Exception('AI parsing returned no data.');
      }
      
      // Store parsed data
      $this->storeParsedResults($uid, $resume_record['file_id'], $file_uri, $parsed_data, $filename);
      
      \Drupal::messenger()->addStatus($this->t('Resume JSON parsed successfully from "@filename".', [
        '@filename' => $filename,
      ]));
      
    } catch (\Exception $e) {
      $this->logError('JSON parsing failed: @message', ['@message' => $e->getMessage()]);
      \Drupal::messenger()->addError($this->t('Failed to parse JSON: @message', ['@message' => $e->getMessage()]));
    }
    
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for consolidating resume data into profile JSON.
   */
  public function consolidateSubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $resume_id = $triggering_element['#attributes']['data-resume-id'] ?? NULL;
    
    if (!$resume_id) {
      \Drupal::messenger()->addError($this->t('Could not identify resume for consolidation.'));
      return;
    }

    try {
      $uid = \Drupal::currentUser()->id();
      
      // First, get the file_id from the resume record
      $resume_record = $this->loadResumeRecord($resume_id, $uid);
      $file_id = $resume_record['file_id'];
      
      // Get the latest parsed data using the file_id
      $parsed_record = $this->userProfileRepository->getLatestParsedDataByFileId($uid, $file_id);
      
      if (empty($parsed_record) || empty($parsed_record['parsed_data'])) {
        throw new \Exception('No parsed data found for this resume.');
      }
      
      $latest_parsed_data = json_decode($parsed_record['parsed_data'], TRUE);
      if (!$latest_parsed_data) {
        throw new \Exception('Unable to decode parsed data JSON.');
      }
      
      // Run consolidation
      $this->buildConsolidatedJsonAndApplyToProfile($uid, $latest_parsed_data);
      
      \Drupal::messenger()->addStatus($this->t('Resume data has been consolidated into your profile.'));
      
    } catch (\Exception $e) {
      $this->logError('Consolidation failed: @message', ['@message' => $e->getMessage()]);
      \Drupal::messenger()->addError($this->t('Failed to consolidate data: @message', ['@message' => $e->getMessage()]));
    }
    
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for deleting all resume data and profile information for the current user.
   */
  public function deleteAllResumeDataSubmit(array &$form, FormStateInterface $form_state) {
    $this->resumeUploadSubform->deleteAllResumeDataSubmit($form, $form_state);
  }

  /**
   * Submit handler for generating cover letter template from resume data.
   */
  public function generateCoverLetterSubmit(array &$form, FormStateInterface $form_state) {
    $uid = \Drupal::currentUser()->id();
    
    try {
      // Load job seeker profile
      $job_seeker_profile = $this->jobSeekerService->loadByUserId($uid);
      if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
        \Drupal::messenger()->addWarning($this->t('No resume data found. Please upload and parse your resume first.'));
        $this->logWarning('Cover letter generation attempted but no resume data for uid @uid', ['@uid' => $uid]);
        return;
      }
      
      $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE);
      if (!$consolidated) {
        throw new \Exception('Unable to parse consolidated profile JSON.');
      }
      
      $this->logInfo('Generating cover letter for uid @uid with consolidated data', ['@uid' => $uid]);
      
      // Generate cover letter template
      $cover_letter = $this->buildCoverLetterTemplate($consolidated);
      
      if (empty($cover_letter)) {
        \Drupal::messenger()->addWarning($this->t('Unable to generate cover letter. Please ensure your resume contains profile information.'));
        $this->logWarning('Cover letter generation returned empty for uid @uid', ['@uid' => $uid]);
        return;
      }
      
      $this->logInfo('Generated cover letter with @chars characters', ['@chars' => strlen($cover_letter)]);
      
      // Update consolidated JSON with cover letter
      $updated_consolidated = $consolidated;
      if (!isset($updated_consolidated['job_search_preferences'])) {
        $updated_consolidated['job_search_preferences'] = [];
      }
      $updated_consolidated['job_search_preferences']['cover_letter_template'] = $cover_letter;
      
      // Update in database
      $update_result = $this->jobSeekerService->update($job_seeker_profile->id, [
        'consolidated_profile_json' => json_encode($updated_consolidated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
      ]);
      
      if (!$update_result) {
        throw new \Exception('Failed to update job seeker profile in database');
      }
      
      // Update the field value in form state so it shows immediately
      $form_state->setValue('field_cover_letter_template', $cover_letter);
      
      // Force the form element to update its default value
      $form_state->set('generated_cover_letter', $cover_letter);
      
      $this->logInfo('Successfully generated and saved cover letter template for user @uid', ['@uid' => $uid]);
      \Drupal::messenger()->addStatus($this->t('✨ Cover letter template generated successfully! Review and customize it below.'));
      
    } catch (\Exception $e) {
      $this->logError('Error generating cover letter: @error', ['@error' => $e->getMessage()]);
      \Drupal::messenger()->addError($this->t('Error generating cover letter: @error', ['@error' => $e->getMessage()]));
    }
    
    // Rebuild form to show generated content
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler for parsing a resume with AI.
   * 
   * Process Flow:
   * 1. Load resume record from database
   * 2. Load file entity and validate
   * 3. Extract text from PDF/DOC/DOCX
   * 4. Store extracted text in resume table
   * 5. Send text to GenAI service (or mock in dev)
   * 6. Store parsed results
   */
  public function parseResumeSubmit(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $resume_id = $triggering_element['#attributes']['data-resume-id'] ?? NULL;
    
    if (!$resume_id) {
      \Drupal::messenger()->addError($this->t('Could not identify resume to parse.'));
      return;
    }

    try {
      $uid = \Drupal::currentUser()->id();
      
      // ===================================================================
      // STEP 1: Load Resume Record from Database
      // ===================================================================
      $resume_record = $this->loadResumeRecord($resume_id, $uid);
      
      // ===================================================================
      // STEP 2: Load File Entity and Validate Physical File
      // ===================================================================
      $file = $this->loadAndValidateFile($resume_record['file_id']);
      $file_uri = $file->getFileUri();
      $filename = basename($file_uri);
      
      // ===================================================================
      // STEP 3: Extract Text from File
      // ===================================================================
      $extracted_text = $this->extractTextFromFile($file);
      if (empty($extracted_text)) {
        throw new \Exception("Unable to extract text from resume file: {$filename}");
      }
      
      // ===================================================================
      // STEP 4: Store Extracted Text in Resume Table
      // ===================================================================
      $this->storeExtractedText($resume_record['id'], $extracted_text, $filename);
      
      // ===================================================================
      // STEP 5: Parse Resume with GenAI Service (Bedrock)
      // ===================================================================
      $parsed_data = $this->parseResumeProdMode($extracted_text, $filename);
      
      // ===================================================================
      // STEP 6: Store Individual Resume Parsed Data
      // ===================================================================
      $timestamp = \Drupal::time()->getRequestTime();
      $this->userProfileRepository->insertParsedDataRecord(
        $uid,
        $resume_record['file_id'],
        $file_uri,
        json_encode($parsed_data),
        'complete',
        NULL,
        $timestamp
      );
      
      $this->logInfo('📝 Stored parsed data for resume: @filename', ['@filename' => $filename]);
      
      // ===================================================================
      // STEP 7: Build Consolidated JSON and Apply to Profile
      // ===================================================================
      $this->buildConsolidatedJsonAndApplyToProfile($uid, $parsed_data);
      
      \Drupal::messenger()->addStatus($this->t('Resume "@filename" has been parsed and profile updated!', ['@filename' => $filename]));
      
      // Redirect to prevent form resubmission
      $form_state->setRedirect('job_hunter.user_profile_edit');
      
    } catch (\Exception $e) {
      $this->logError('Error parsing resume: @error', [
        '@error' => $e->getMessage(),
      ]);
      \Drupal::messenger()->addError($this->t('Error parsing resume: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Calculate years of professional experience based on earliest graduation date.
   *
   * @param int $job_seeker_id
   *   The job seeker profile ID.
   *
   * @return int
   *   Years of experience calculated from graduation date.
   */
  private function calculateYearsOfExperience($job_seeker_id) {
    return $this->educationHistorySubform->calculateYearsOfExperience($job_seeker_id);
  }

  /**
   * Extract suggested keywords from resume technical expertise and other profile data.
   *
   * @param object|null $job_seeker_profile
   *   The job seeker profile object.
   *
   * @return array
   *   Array of suggested keyword strings.
   */
  private function getSuggestedKeywords($job_seeker_profile) {
    if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
      return [];
    }

    $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE);
    if (!$consolidated) {
      return [];
    }

    $keywords = [];

    // Extract from technical expertise categories
    if (!empty($consolidated['technical_expertise']['categories'])) {
      foreach ($consolidated['technical_expertise']['categories'] as $category) {
        // Add category name as a keyword
        if (!empty($category['name'])) {
          $keywords[] = $category['name'];
        }
        
        // Add top skills from each category (limit to 5 per category to avoid overwhelming)
        if (!empty($category['skills']) && is_array($category['skills'])) {
          $top_skills = array_slice($category['skills'], 0, 5);
          foreach ($top_skills as $skill) {
            if (is_string($skill)) {
              $keywords[] = $skill;
            } elseif (is_array($skill) && !empty($skill['name'])) {
              $keywords[] = $skill['name'];
            }
          }
        }
      }
    }

    // Extract from executive profile (industry focus)
    if (!empty($consolidated['executive_profile'])) {
      $exec_profile = $consolidated['executive_profile'];
      if (is_array($exec_profile)) {
        // Check for industry_focus array
        if (!empty($exec_profile['industry_focus']) && is_array($exec_profile['industry_focus'])) {
          $keywords = array_merge($keywords, $exec_profile['industry_focus']);
        }
      }
    }

    // Extract from job titles (split pipe-separated titles)
    if (!empty($consolidated['job_search_preferences']['target_titles'])) {
      $titles = $consolidated['job_search_preferences']['target_titles'];
      if (is_array($titles)) {
        foreach ($titles as $title_group) {
          // Split by pipe if it's a combined string
          $split_titles = preg_split('/\s*\|\s*/', $title_group);
          $keywords = array_merge($keywords, $split_titles);
        }
      } elseif (is_string($titles)) {
        $split_titles = preg_split('/\s*\|\s*/', $titles);
        $keywords = array_merge($keywords, $split_titles);
      }
    }

    // Extract from early career companies (Fortune 50, notable companies)
    if (!empty($consolidated['early_career']['positions']) && is_array($consolidated['early_career']['positions'])) {
      foreach ($consolidated['early_career']['positions'] as $position) {
        if (!empty($position['company'])) {
          $keywords[] = $position['company'];
        }
      }
    }

    // Extract from leadership philosophy key themes
    if (!empty($consolidated['leadership_philosophy']['key_themes']) && is_array($consolidated['leadership_philosophy']['key_themes'])) {
      $keywords = array_merge($keywords, $consolidated['leadership_philosophy']['key_themes']);
    }

    // Clean and deduplicate keywords
    $keywords = array_map('trim', $keywords);
    $keywords = array_filter($keywords); // Remove empty strings
    $keywords = array_unique($keywords);
    
    // Sort alphabetically
    sort($keywords);

    // Limit to top 30 keywords to avoid overwhelming the user
    return array_slice($keywords, 0, 30);
  }

  /**
   * Build a professional cover letter template from resume data.
   *
   * @param array $consolidated
   *   The consolidated profile JSON data.
   *
   * @return string
   *   The generated cover letter template.
   */
  private function buildCoverLetterTemplate(array $consolidated) {
    $template = '';
    
    // Header with contact info
    $contact = $consolidated['contact_info'] ?? [];
    $full_name = $contact['full_name'] ?? 'Your Name';
    $email = $contact['email'] ?? 'your.email@example.com';
    $phone = $contact['phone'] ?? '(555) 555-5555';
    
    $location = '';
    if (!empty($contact['location']['city']) && !empty($contact['location']['state'])) {
      $location = $contact['location']['city'] . ', ' . $contact['location']['state'];
    } elseif (!empty($contact['location']['city'])) {
      $location = $contact['location']['city'];
    } elseif (!empty($contact['location']['state'])) {
      $location = $contact['location']['state'];
    }
    
    // Build header
    $template .= "{$full_name}\n";
    if ($location) {
      $template .= "{$location}\n";
    }
    $template .= "{$email} | {$phone}\n\n";
    $template .= "[Date]\n\n";
    $template .= "[Hiring Manager Name]\n";
    $template .= "[Company Name]\n";
    $template .= "[Company Address]\n\n";
    $template .= "Dear Hiring Manager,\n\n";
    
    // Opening paragraph - Express interest and highlight experience
    $exec_profile = '';
    if (!empty($consolidated['executive_profile'])) {
      if (is_string($consolidated['executive_profile'])) {
        $exec_profile = $consolidated['executive_profile'];
      } elseif (is_array($consolidated['executive_profile']) && !empty($consolidated['executive_profile'][0]['summary'])) {
        $exec_profile = $consolidated['executive_profile'][0]['summary'];
      }
    }
    
    $years = $consolidated['job_search_preferences']['experience_years'] ?? '10+';
    $template .= "I am writing to express my strong interest in the [Position Title] role at [Company Name]. ";
    $template .= "As a {$exec_profile}, I bring {$years} years of proven expertise in delivering transformative results. ";
    $template .= "I am excited about the opportunity to contribute to your organization's continued success.\n\n";
    
    // Second paragraph - Highlight key skills and achievements
    $template .= "Throughout my career, I have developed deep expertise across several critical areas:\n\n";
    
    // Extract top 3 technical categories
    if (!empty($consolidated['technical_expertise']['categories'])) {
      $categories = array_slice($consolidated['technical_expertise']['categories'], 0, 3);
      foreach ($categories as $category) {
        if (!empty($category['name'])) {
          $template .= "• {$category['name']}";
          if (!empty($category['skills']) && is_array($category['skills'])) {
            $top_skills = array_slice($category['skills'], 0, 3);
            $skills_str = is_array($top_skills[0]) 
              ? implode(', ', array_column($top_skills, 'name'))
              : implode(', ', $top_skills);
            $template .= " - including {$skills_str}";
          }
          $template .= "\n";
        }
      }
      $template .= "\n";
    }
    
    // Third paragraph - Notable experience and achievements
    $template .= "In my recent roles, I have:\n\n";
    
    // Extract from early career or consulting practice
    if (!empty($consolidated['early_career']['positions'])) {
      $notable_companies = array_slice($consolidated['early_career']['positions'], 0, 3);
      foreach ($notable_companies as $position) {
        if (!empty($position['company']) && !empty($position['focus'])) {
          $template .= "• At {$position['company']}: {$position['focus']}\n";
        }
      }
      $template .= "\n";
    } elseif (!empty($consolidated['consulting_practice']['notable_engagements'])) {
      $engagements = array_slice($consolidated['consulting_practice']['notable_engagements'], 0, 3);
      foreach ($engagements as $engagement) {
        if (!empty($engagement['client']) && !empty($engagement['description'])) {
          $template .= "• {$engagement['client']}: {$engagement['description']}\n";
        }
      }
      $template .= "\n";
    }
    
    // Fourth paragraph - Leadership philosophy (if available)
    if (!empty($consolidated['leadership_philosophy']['statement'])) {
      $philosophy = $consolidated['leadership_philosophy']['statement'];
      // Shorten if too long
      if (strlen($philosophy) > 300) {
        $philosophy = substr($philosophy, 0, 297) . '...';
      }
      $template .= "My leadership approach focuses on {$philosophy}\n\n";
    }
    
    // Closing paragraph
    $template .= "I am confident that my experience, technical expertise, and proven track record of ";
    $template .= "delivering measurable business impact make me an ideal candidate for this role. ";
    $template .= "I would welcome the opportunity to discuss how my skills and background align with ";
    $template .= "[Company Name]'s goals and how I can contribute to your team's continued success.\n\n";
    
    $template .= "Thank you for considering my application. I look forward to speaking with you soon.\n\n";
    $template .= "Sincerely,\n";
    $template .= $full_name;
    
    return $template;
  }

  /**
   * Get a value from consolidated JSON with fallback to database column.
   *
   * Maps form fields to their locations in the schema v1.0 consolidated JSON.
   *
   * @param object|null $job_seeker_profile
   *   The job seeker profile object.
   * @param string $field_name
   *   The form field name (e.g., 'field_professional_summary').
   * @param mixed $default
   *   Default value if not found.
   *
   * @return mixed
   *   The value from JSON or database fallback.
   */
  private function getConsolidatedValue($job_seeker_profile, string $field_name, $default = '') {
    if (!$job_seeker_profile) {
      return $default;
    }

    // Parse consolidated JSON once
    $consolidated = null;
    if (!empty($job_seeker_profile->consolidated_profile_json)) {
      $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE);
    }

    // Define mapping from form fields to JSON paths and DB column fallbacks
    // Format: 'form_field' => ['json_path' => [...], 'db_column' => 'column_name', 'transform' => callable]
    $field_map = [
      'field_professional_summary' => [
        'json_path' => ['executive_profile'],
        'db_column' => 'professional_summary',
        'transform' => function($val) {
          // executive_profile is array of objects with 'summary' key
          if (is_array($val) && !empty($val)) {
            $summaries = array_map(function($item) {
              return is_array($item) ? ($item['summary'] ?? '') : $item;
            }, $val);
            return implode("\n\n", array_filter($summaries));
          }
          return is_string($val) ? $val : '';
        },
      ],
      'field_skills_summary' => [
        'json_path' => ['technical_expertise'],
        'db_column' => 'skills',
        'transform' => function($val) {
          // technical_expertise is object with category keys containing skill arrays
          if (is_array($val)) {
            $all_skills = [];
            foreach ($val as $category => $skills) {
              if (is_array($skills)) {
                foreach ($skills as $skill) {
                  // Only add string values, skip nested arrays
                  if (is_string($skill)) {
                    $all_skills[] = $skill;
                  } elseif (is_array($skill) && isset($skill['name'])) {
                    $all_skills[] = $skill['name'];
                  }
                }
              } elseif (is_string($skills)) {
                $all_skills[] = $skills;
              }
            }
            return implode(', ', array_unique($all_skills));
          }
          return is_string($val) ? $val : '';
        },
      ],
      'field_work_authorization' => [
        'json_path' => ['job_search_preferences', 'work_authorization'],
        'db_column' => 'work_authorization',
      ],
      'field_us_work_authorized' => [
        'json_path' => ['job_search_preferences', 'us_work_authorized'],
        'db_column' => 'us_work_authorized',
      ],
      'field_requires_sponsorship' => [
        'json_path' => ['job_search_preferences', 'requires_sponsorship'],
        'db_column' => 'requires_sponsorship',
      ],
      'field_age_18_or_older' => [
        'json_path' => ['job_search_preferences', 'age_18_or_older'],
        'db_column' => 'age_18_or_older',
      ],
      'field_hear_about_us' => [
        'json_path' => ['job_search_preferences', 'hear_about_us'],
        'db_column' => '',
      ],
      'field_prior_company_employment' => [
        'json_path' => ['job_search_preferences', 'prior_company_employment'],
        'db_column' => '',
      ],
      'field_prior_company_wwid' => [
        'json_path' => ['job_search_preferences', 'prior_company_wwid'],
        'db_column' => '',
      ],
      'field_prior_company_email' => [
        'json_path' => ['job_search_preferences', 'prior_company_email'],
        'db_column' => '',
      ],
      'field_phone_device_type' => [
        'json_path' => ['job_search_preferences', 'phone_device_type'],
        'db_column' => '',
      ],
      'field_experience_years' => [
        'json_path' => ['job_search_preferences', 'experience_years'],
        'db_column' => 'experience_years',
        'transform' => function($val) {
          // Try to extract years from professional experience
          return is_numeric($val) ? (int)$val : '';
        },
      ],
      'field_education_level' => [
        'json_path' => ['education'],
        'db_column' => 'education_level',
        'transform' => function($val) {
          // Extract highest degree from education array
          if (is_array($val) && !empty($val)) {
            $degrees = ['doctoral' => 6, 'professional' => 5, 'masters' => 4, 'bachelors' => 3, 'associates' => 2, 'high_school' => 1];
            $highest = '';
            $highest_rank = 0;
            foreach ($val as $edu) {
              $degree = strtolower($edu['degree'] ?? '');
              foreach ($degrees as $level => $rank) {
                if (stripos($degree, $level) !== false || stripos($degree, str_replace('_', ' ', $level)) !== false) {
                  if ($rank > $highest_rank) {
                    $highest_rank = $rank;
                    $highest = $level;
                  }
                }
              }
              // Check for PhD, MBA, etc.
              if (stripos($degree, 'ph.d') !== false || stripos($degree, 'phd') !== false) {
                $highest = 'doctoral';
                $highest_rank = 6;
              } elseif (stripos($degree, 'mba') !== false || stripos($degree, 'm.s.') !== false || stripos($degree, 'master') !== false) {
                if ($highest_rank < 4) { $highest = 'masters'; $highest_rank = 4; }
              } elseif (stripos($degree, 'b.s.') !== false || stripos($degree, 'b.a.') !== false || stripos($degree, 'bachelor') !== false) {
                if ($highest_rank < 3) { $highest = 'bachelors'; $highest_rank = 3; }
              }
            }
            return $highest;
          }
          return is_string($val) ? $val : '';
        },
      ],
      'field_linkedin_url' => [
        'json_path' => ['contact_info'],
        'db_column' => 'linkedin_url',
        'transform' => function($val) {
          // Extract LinkedIn URL from websites array (new schema) or direct property (old schema)
          if (is_array($val)) {
            // Check new schema: contact_info.websites array
            if (isset($val['websites']) && is_array($val['websites'])) {
              foreach ($val['websites'] as $site) {
                if (is_array($site) && isset($site['type']) && $site['type'] === 'linkedin' && !empty($site['url'])) {
                  return $site['url'];
                }
              }
            }
            // Check old schema: contact_info.linkedin as direct property
            if (isset($val['linkedin']) && is_string($val['linkedin'])) {
              return $val['linkedin'];
            }
          }
          return '';
        },
      ],
      'field_github_url' => [
        'json_path' => ['contact_info'],
        'db_column' => 'github_url',
        'transform' => function($val) {
          // Extract GitHub URL from websites array (new schema) or direct property (old schema)
          if (is_array($val)) {
            // Check new schema: contact_info.websites array
            if (isset($val['websites']) && is_array($val['websites'])) {
              foreach ($val['websites'] as $site) {
                if (is_array($site) && isset($site['type']) && $site['type'] === 'github' && !empty($site['url'])) {
                  return $site['url'];
                }
              }
            }
            // Check old schema: contact_info.github as direct property
            if (isset($val['github']) && is_string($val['github'])) {
              return $val['github'];
            }
          }
          return '';
        },
      ],
      'field_portfolio_url' => [
        'json_path' => ['contact_info'],
        'db_column' => 'portfolio_url',
        'transform' => function($val) {
          // Extract portfolio/personal website URL from websites array (new schema) or direct property (old schema)
          if (is_array($val)) {
            // Check new schema: contact_info.websites array
            if (isset($val['websites']) && is_array($val['websites'])) {
              foreach ($val['websites'] as $site) {
                if (is_array($site) && isset($site['type']) && in_array($site['type'], ['portfolio', 'personal']) && !empty($site['url'])) {
                  return $site['url'];
                }
              }
            }
            // Check old schema: contact_info.portfolio as direct property
            if (isset($val['portfolio']) && is_string($val['portfolio'])) {
              return $val['portfolio'];
            }
          }
          return '';
        },
      ],
      'field_target_job_titles' => [
        'json_path' => ['job_search_preferences', 'target_titles'],
        'db_column' => 'job_titles',
        'transform' => function($val) {
          return is_array($val) ? implode("\n", $val) : (is_string($val) ? $val : '');
        },
      ],
      'field_keywords_interested' => [
        'json_path' => ['job_search_preferences', 'keywords'],
        'db_column' => 'keywords_interested',
        'transform' => function($val) {
          return is_array($val) ? implode("\n", $val) : (is_string($val) ? $val : '');
        },
      ],
      'field_cover_letter_template' => [
        'json_path' => ['job_search_preferences', 'cover_letter_template'],
        'db_column' => 'cover_letter_template',
      ],
      'field_available_start_date' => [
        'json_path' => ['job_search_preferences', 'available_start_date'],
        'db_column' => 'availability',
      ],
      'field_remote_preference' => [
        'json_path' => ['job_search_preferences', 'remote_preference'],
        'db_column' => 'remote_preference',
      ],
      'field_relocation_willing' => [
        'json_path' => ['job_search_preferences', 'relocation_willing'],
        'db_column' => 'relocation_willing',
      ],
      'field_salary_expectation_min' => [
        'json_path' => ['job_search_preferences', 'salary_min'],
        'db_column' => null, // Derived from salary_expectation
      ],
      'field_salary_expectation_max' => [
        'json_path' => ['job_search_preferences', 'salary_max'],
        'db_column' => null, // Derived from salary_expectation
      ],
      'field_salary_change_minimum' => [
        'json_path' => ['job_search_preferences', 'salary_change_minimum'],
        'db_column' => 'salary_change_minimum',
      ],
      'field_references_available' => [
        'json_path' => ['job_search_preferences', 'references_available'],
        'db_column' => 'references_available',
      ],
      'field_gender' => [
        'json_path' => ['demographics', 'gender'],
        'db_column' => 'gender',
      ],
      'field_race_ethnicity' => [
        'json_path' => ['demographics', 'race_ethnicity'],
        'db_column' => 'race_ethnicity',
      ],
      'field_veteran_status' => [
        'json_path' => ['demographics', 'veteran_status'],
        'db_column' => 'veteran_status',
      ],
      'field_disability_status' => [
        'json_path' => ['demographics', 'disability_status'],
        'db_column' => 'disability_status',
      ],
    ];

    if (!isset($field_map[$field_name])) {
      // Unknown field, try direct DB column access
      $db_col = str_replace('field_', '', $field_name);
      return isset($job_seeker_profile->$db_col) ? $job_seeker_profile->$db_col : $default;
    }

    $config = $field_map[$field_name];
    $json_value = null;

    // Try to get value from consolidated JSON
    if ($consolidated && !empty($config['json_path'])) {
      $json_value = $consolidated;
      foreach ($config['json_path'] as $key) {
        if (is_array($json_value) && isset($json_value[$key])) {
          $json_value = $json_value[$key];
        } else {
          $json_value = null;
          break;
        }
      }
    }

    // Apply transform if value found and transform exists
    if ($json_value !== null && isset($config['transform'])) {
      $json_value = $config['transform']($json_value);
    }

    // Safety: Ensure we don't return arrays for form fields that expect strings
    if (is_array($json_value)) {
      // If it's a simple indexed array, try to join it
      if (array_keys($json_value) === range(0, count($json_value) - 1)) {
        // Check if all values are strings
        $all_strings = true;
        foreach ($json_value as $v) {
          if (!is_string($v) && !is_numeric($v)) {
            $all_strings = false;
            break;
          }
        }
        if ($all_strings) {
          $json_value = implode(', ', $json_value);
        } else {
          // Complex array, can't convert - use default
          $json_value = null;
        }
      } else {
        // Associative array - can't use as form value
        $json_value = null;
      }
    }

    // Return JSON value if found and not empty
    if ($json_value !== null && $json_value !== '' && $json_value !== []) {
      return $json_value;
    }

    // Fallback to database column
    if (!empty($config['db_column'])) {
      $db_col = $config['db_column'];
      if (isset($job_seeker_profile->$db_col) && $job_seeker_profile->$db_col !== '') {
        return $job_seeker_profile->$db_col;
      }
    }

    // Final fallback for online presence: derive URLs directly from uploaded resume(s)
    // when GenAI didn't populate contact_info.websites.
    if (in_array($field_name, ['field_linkedin_url', 'field_github_url', 'field_portfolio_url'], TRUE)) {
      $uid = (int) ($job_seeker_profile->uid ?? \Drupal::currentUser()->id());
      $derived = $this->deriveOnlinePresenceFromResumes($uid, $consolidated);
      if ($field_name === 'field_linkedin_url' && !empty($derived['linkedin'])) {
        return $derived['linkedin'];
      }
      if ($field_name === 'field_github_url' && !empty($derived['github'])) {
        return $derived['github'];
      }
      if ($field_name === 'field_portfolio_url' && !empty($derived['portfolio'])) {
        return $derived['portfolio'];
      }
    }

    return $default;
  }

  /**
   * Derive online presence URLs from extracted resume text and other consolidated fields.
   *
   * If extracted_text is missing for a resume record, this will attempt extraction
   * from the file and backfill the resume table.
   */
  private function deriveOnlinePresenceFromResumes(int $uid, ?array $consolidated = NULL): array {
    static $cache = [];
    if (isset($cache[$uid])) {
      return $cache[$uid];
    }

    $derived = [
      'linkedin' => '',
      'github' => '',
      'portfolio' => '',
    ];

    // First, try to infer a portfolio/personal site from consolidated fields.
    if (is_array($consolidated)) {
      $cp = $consolidated['consulting_practice'] ?? NULL;
      if (is_array($cp)) {
        foreach (['website', 'url'] as $k) {
          if (empty($derived['portfolio']) && !empty($cp[$k]) && is_string($cp[$k])) {
            $derived['portfolio'] = $cp[$k];
          }
        }
      }

      if (empty($derived['portfolio']) && !empty($consolidated['demonstration_projects']) && is_array($consolidated['demonstration_projects'])) {
        foreach ($consolidated['demonstration_projects'] as $proj) {
          if (is_array($proj) && !empty($proj['url']) && is_string($proj['url'])) {
            $derived['portfolio'] = $proj['url'];
            break;
          }
        }
      }
    }

    $job_seeker_profile = $this->jobSeekerService->loadByUserId($uid);
    $job_seeker_ids = array_values(array_unique(array_filter([
      (int) $uid,
      (int) ($job_seeker_profile->id ?? 0),
    ])));
    $rows = $this->userProfileRepository->getResumeRowsForJobSeeker($job_seeker_ids);

    $all_text = '';
    foreach ($rows as $row) {
      $text = is_string($row->extracted_text ?? NULL) ? $row->extracted_text : '';
      if ($text === '' && !empty($row->file_id)) {
        $file = \Drupal\file\Entity\File::load((int) $row->file_id);
        if ($file) {
          $text = $this->extractTextFromFile($file);
          if (is_string($text) && $text !== '') {
            $this->userProfileRepository->updateResumeExtractedText((int) $row->id, $text);
          }
        }
      }
      if (is_string($text) && $text !== '') {
        $all_text .= "\n" . $text;
      }
    }

    $urls = $this->extractUrlsFromText($all_text);
    foreach ($urls as $url) {
      $lower = strtolower($url);
      if (empty($derived['linkedin']) && str_contains($lower, 'linkedin.com')) {
        $derived['linkedin'] = $url;
      }
      if (empty($derived['github']) && str_contains($lower, 'github.com')) {
        $derived['github'] = $url;
      }
    }

    if (empty($derived['portfolio'])) {
      foreach ($urls as $url) {
        $lower = strtolower($url);
        if (str_contains($lower, 'linkedin.com') || str_contains($lower, 'github.com')) {
          continue;
        }
        $derived['portfolio'] = $url;
        break;
      }
    }

    // Persist into consolidated_profile_json if websites are missing.
    // This makes the rest of the form (and future loads) consistent.
    if (is_array($consolidated)) {
      $existing_sites = $consolidated['contact_info']['websites'] ?? NULL;
      $needs_write = empty($existing_sites) && (
        !empty($derived['linkedin']) ||
        !empty($derived['github']) ||
        !empty($derived['portfolio'])
      );

      if ($needs_write) {
        if (!isset($consolidated['contact_info']) || !is_array($consolidated['contact_info'])) {
          $consolidated['contact_info'] = [];
        }
        $sites = [];
        if (!empty($derived['linkedin'])) {
          $sites[] = ['type' => 'linkedin', 'url' => $derived['linkedin']];
        }
        if (!empty($derived['github'])) {
          $sites[] = ['type' => 'github', 'url' => $derived['github']];
        }
        if (!empty($derived['portfolio'])) {
          $sites[] = ['type' => 'personal', 'url' => $derived['portfolio']];
        }
        $consolidated['contact_info']['websites'] = $sites;

        try {
          $this->userProfileRepository->saveConsolidatedProfileJson($uid, json_encode($consolidated));
        } catch (\Exception $e) {
          // Non-fatal; derived values still returned for this request.
        }
      }
    }

    $cache[$uid] = $derived;
    return $derived;
  }

  /**
   * Extract and normalize URLs from plain text.
   */
  private function extractUrlsFromText(string $text): array {
    if ($text === '') {
      return [];
    }

    $candidates = [];

    if (preg_match_all('~https?://[^\s<>()\[\]"\'`]+~i', $text, $m)) {
      $candidates = array_merge($candidates, $m[0]);
    }
    if (preg_match_all('~\bwww\.[^\s<>()\[\]"\'`]+~i', $text, $m2)) {
      foreach ($m2[0] as $u) {
        $candidates[] = 'https://' . $u;
      }
    }
    if (preg_match_all('~\blinkedin\.com/[^\s<>()\[\]"\'`]+~i', $text, $m3)) {
      foreach ($m3[0] as $u) {
        $candidates[] = 'https://' . $u;
      }
    }
    if (preg_match_all('~\bgithub\.com/[^\s<>()\[\]"\'`]+~i', $text, $m4)) {
      foreach ($m4[0] as $u) {
        $candidates[] = 'https://' . $u;
      }
    }

    $normalized = [];
    foreach ($candidates as $u) {
      if (!is_string($u) || $u === '') {
        continue;
      }
      $u = rtrim($u, ".,);:]\"'>");
      // Very basic sanity.
      if (!preg_match('~^https?://~i', $u)) {
        continue;
      }
      $normalized[] = $u;
    }

    $normalized = array_values(array_unique($normalized));
    return $normalized;
  }

  /**
   * Update consolidated JSON with form field values.
   *
   * @param array $consolidated
   *   The consolidated JSON array (modified by reference).
   * @param string $field_name
   *   The form field name.
   * @param mixed $value
   *   The value to set.
   */
  private function setConsolidatedValue(array &$consolidated, string $field_name, $value) {
    // Handle JSON editor fields - these replace entire sections
    $json_fields = [
      'field_technical_expertise_json' => 'technical_expertise',
      'field_strategic_differentiators_json' => 'strategic_differentiators',
      'field_leadership_philosophy_json' => 'leadership_philosophy',
      'field_demonstration_projects_json' => 'demonstration_projects',
      'field_publications_json' => 'publications',
      'field_certifications_json' => 'certifications',
      'field_patents_json' => 'patents',
      'field_awards_json' => 'awards_and_honors',
      'field_languages_json' => 'languages',
      'field_consulting_practice_json' => 'consulting_practice',
      'field_early_career_json' => 'early_career',
      'field_education_json' => 'education',
    ];
    
    if (isset($json_fields[$field_name])) {
      $decoded = json_decode((string) $value, TRUE);
      if (json_last_error() === JSON_ERROR_NONE) {
        $consolidated[$json_fields[$field_name]] = $decoded;
      }
      else {
        $consolidated[$json_fields[$field_name]] = $this->parsePlainSectionInput($field_name, (string) $value);
      }

      if ($field_name === 'field_certifications_json') {
        $legacy = [];
        $certifications = $consolidated['certifications'] ?? [];
        if (is_array($certifications)) {
          foreach ($certifications as $cert) {
            if (is_array($cert)) {
              $name = trim((string) ($cert['name'] ?? ''));
              if ($name !== '') {
                $legacy[] = $name;
              }
            }
            elseif (is_string($cert) && trim($cert) !== '') {
              $legacy[] = trim($cert);
            }
          }
        }
        if (!isset($consolidated['job_search_preferences']) || !is_array($consolidated['job_search_preferences'])) {
          $consolidated['job_search_preferences'] = [];
        }
        $consolidated['job_search_preferences']['certifications'] = array_values(array_unique($legacy));
      }

      return;
    }
    
    // Handle contact info fields
    $contact_fields = [
      'field_full_name' => 'full_name',
      'field_headline' => 'headline',
      'field_phone' => 'phone',
      'field_email' => 'email',
    ];
    
    if (isset($contact_fields[$field_name])) {
      if (!isset($consolidated['contact_info'])) {
        $consolidated['contact_info'] = [];
      }
      $consolidated['contact_info'][$contact_fields[$field_name]] = $value;
      return;
    }
    
    // Handle credentials (comma-separated to array)
    if ($field_name === 'field_credentials') {
      if (!isset($consolidated['contact_info'])) {
        $consolidated['contact_info'] = [];
      }
      $consolidated['contact_info']['credentials'] = array_filter(array_map('trim', explode(',', $value)));
      return;
    }
    
    // Handle location fields
    if ($field_name === 'field_city') {
      if (!isset($consolidated['contact_info'])) {
        $consolidated['contact_info'] = [];
      }
      if (!isset($consolidated['contact_info']['location'])) {
        $consolidated['contact_info']['location'] = [];
      }
      $consolidated['contact_info']['location']['city'] = $value;
      return;
    }
    
    if ($field_name === 'field_state') {
      if (!isset($consolidated['contact_info'])) {
        $consolidated['contact_info'] = [];
      }
      if (!isset($consolidated['contact_info']['location'])) {
        $consolidated['contact_info']['location'] = [];
      }
      $consolidated['contact_info']['location']['state'] = $value;
      return;
    }

    if ($field_name === 'field_country') {
      if (!isset($consolidated['contact_info'])) {
        $consolidated['contact_info'] = [];
      }
      if (!isset($consolidated['contact_info']['location'])) {
        $consolidated['contact_info']['location'] = [];
      }
      $consolidated['contact_info']['location']['country'] = $value;
      return;
    }
    
    // Define reverse mapping from form fields to JSON paths
    $field_map = [
      'field_professional_summary' => ['executive_profile'],
      'field_skills_summary' => ['technical_expertise'],
      'field_work_authorization' => ['job_search_preferences', 'work_authorization'],
      'field_us_work_authorized' => ['job_search_preferences', 'us_work_authorized'],
      'field_requires_sponsorship' => ['job_search_preferences', 'requires_sponsorship'],
      'field_age_18_or_older' => ['job_search_preferences', 'age_18_or_older'],
      'field_hear_about_us' => ['job_search_preferences', 'hear_about_us'],
      'field_prior_company_employment' => ['job_search_preferences', 'prior_company_employment'],
      'field_prior_company_wwid' => ['job_search_preferences', 'prior_company_wwid'],
      'field_prior_company_email' => ['job_search_preferences', 'prior_company_email'],
      'field_phone_device_type' => ['job_search_preferences', 'phone_device_type'],
      'field_experience_years' => ['job_search_preferences', 'experience_years'],
      'field_education_level' => ['job_search_preferences', 'education_level'],
      'field_linkedin_url' => ['contact_info', 'linkedin'],
      'field_github_url' => ['contact_info', 'github'],
      'field_portfolio_url' => ['contact_info', 'portfolio'],
      'field_target_job_titles' => ['job_search_preferences', 'target_titles'],
      'field_keywords_interested' => ['job_search_preferences', 'keywords'],
      'field_cover_letter_template' => ['job_search_preferences', 'cover_letter_template'],
      'field_available_start_date' => ['job_search_preferences', 'available_start_date'],
      'field_remote_preference' => ['job_search_preferences', 'remote_preference'],
      'field_relocation_willing' => ['job_search_preferences', 'relocation_willing'],
      'field_salary_expectation_min' => ['job_search_preferences', 'salary_min'],
      'field_salary_expectation_max' => ['job_search_preferences', 'salary_max'],
      'field_salary_change_minimum' => ['job_search_preferences', 'salary_change_minimum'],
      'field_references_available' => ['job_search_preferences', 'references_available'],
      'field_gender' => ['demographics', 'gender'],
      'field_race_ethnicity' => ['demographics', 'race_ethnicity'],
      'field_veteran_status' => ['demographics', 'veteran_status'],
      'field_disability_status' => ['demographics', 'disability_status'],
    ];

    if (!isset($field_map[$field_name])) {
      return;
    }

    $path = $field_map[$field_name];
    
    // Navigate/create the path and set value
    $ref = &$consolidated;
    for ($i = 0; $i < count($path) - 1; $i++) {
      $key = $path[$i];
      if (!isset($ref[$key]) || !is_array($ref[$key])) {
        $ref[$key] = [];
      }
      $ref = &$ref[$key];
    }
    
    // Set the final value
    $final_key = $path[count($path) - 1];
    
    // Handle special transforms for setting values
    if ($field_name === 'field_target_job_titles' || $field_name === 'field_keywords_interested') {
      // Convert newline-separated string to array
      $value = array_filter(array_map('trim', explode("\n", $value)));
    }

    if ($field_name === 'field_relocation_willing') {
      if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['yes', 'true', '1'], TRUE)) {
          $value = TRUE;
        } elseif (in_array($normalized, ['no', 'false', '0'], TRUE)) {
          $value = FALSE;
        }
      } elseif (is_numeric($value)) {
        $value = ((int) $value === 1);
      }
    }
    
    $ref[$final_key] = $value;
  }

  /**
   * Sync form field values to consolidated JSON.
   *
   * Updates the consolidated_profile_json with values from form fields.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $field_mappings
   *   Mapping of form fields to DB columns.
   * @param array &$job_seeker_data
   *   The job seeker data array (modified by reference).
   */
  private function syncFormFieldsToConsolidatedJson(FormStateInterface $form_state, array $field_mappings, array &$job_seeker_data) {
    // Get current consolidated JSON
    $uid = \Drupal::currentUser()->id();
    $profile = $this->userProfileRepository->getConsolidatedProfileJsonRow((int) $uid);
    
    $consolidated = [];
    if ($profile && !empty($profile['consolidated_profile_json'])) {
      $consolidated = json_decode($profile['consolidated_profile_json'], TRUE) ?: [];
    }
    
    // Initialize schema v1.0 structure if needed
    if (empty($consolidated) || empty($consolidated['schema_version'])) {
      $consolidated = [
        'schema_version' => '1.0',
        'extraction_metadata' => ['consolidated_at' => date('c')],
        'contact_info' => [],
        'executive_profile' => [],
        'job_search_preferences' => [],
        'demographics' => [],
      ];
    }
    
    // Fields that should sync to consolidated JSON
    $json_sync_fields = [
      'field_professional_summary',
      'field_skills_summary',
      'field_work_authorization',
      'field_us_work_authorized',
      'field_requires_sponsorship',
      'field_age_18_or_older',
      'field_hear_about_us',
      'field_prior_company_employment',
      'field_prior_company_wwid',
      'field_prior_company_email',
      'field_phone_device_type',
      'field_experience_years',
      'field_education_level',
      'field_linkedin_url',
      'field_github_url',
      'field_portfolio_url',
      'field_target_job_titles',
      'field_keywords_interested',
      'field_cover_letter_template',
      'field_available_start_date',
      'field_remote_preference',
      'field_relocation_willing',
      'field_salary_expectation_min',
      'field_salary_expectation_max',
      'field_salary_change_minimum',
      'field_references_available',
      'field_gender',
      'field_race_ethnicity',
      'field_veteran_status',
      'field_disability_status',
      // Contact info fields
      'field_full_name',
      'field_headline',
      'field_credentials',
      'field_phone',
      'field_email',
      'field_city',
      'field_state',
      'field_country',
      // JSON editor fields
      'field_technical_expertise_json',
      'field_strategic_differentiators_json',
      'field_leadership_philosophy_json',
      'field_demonstration_projects_json',
      'field_publications_json',
      'field_certifications_json',
      'field_patents_json',
      'field_awards_json',
      'field_languages_json',
      'field_consulting_practice_json',
      'field_early_career_json',
      'field_education_json',
    ];
    
    $has_changes = false;
    foreach ($json_sync_fields as $field_name) {
      $value = $form_state->getValue($field_name);
      if ($value !== NULL && $value !== '') {
        $this->setConsolidatedValue($consolidated, $field_name, $value);
        $has_changes = true;
      }
    }
    
    if ($has_changes) {
      $consolidated['extraction_metadata']['last_form_sync'] = date('c');
      $job_seeker_data['consolidated_profile_json'] = json_encode($consolidated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    // Sync structured professional experience editor.
    $experience_entries = $form_state->getValue(['professional_experience', 'experience_editor', 'professional_experience_entries']);
    if (!is_array($experience_entries)) {
      $experience_entries = $form_state->getValue('professional_experience_entries');
    }
    if (is_array($experience_entries)) {
      $existing_roles = $consolidated['professional_experience'] ?? [];
      if (!is_array($existing_roles)) {
        $existing_roles = [];
      }

      $roles = [];
      foreach ($experience_entries as $idx => $entry) {
        if (!is_array($entry)) {
          continue;
        }
        $company = trim((string) ($entry['company'] ?? ''));
        $title = trim((string) ($entry['title'] ?? ''));
        if ($company === '' && $title === '') {
          continue;
        }

        $start = trim((string) ($entry['dates']['start_date'] ?? ''));
        $end = trim((string) ($entry['dates']['end_date'] ?? ''));
        $tech_raw = (string) ($entry['technologies'] ?? '');
        $ach_raw = (string) ($entry['key_achievements'] ?? '');

        $technologies = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $tech_raw))));
        $achievements = array_values(array_filter(array_map('trim', preg_split('/\R+/', $ach_raw))));

        $role = is_array($existing_roles[$idx] ?? NULL) ? $existing_roles[$idx] : [];
        $role['company'] = $company;
        $role['title'] = $title;
        $advanced = is_array($entry['advanced'] ?? NULL) ? $entry['advanced'] : [];
        $role['employment_type'] = trim((string) ($advanced['employment_type'] ?? $entry['employment_type'] ?? ''));
        $role['via_company'] = trim((string) ($advanced['via_company'] ?? $entry['via_company'] ?? ''));
        $role['start_date'] = $start;
        $role['end_date'] = $end === '' ? NULL : $end;
        $role['location'] = trim((string) ($entry['location'] ?? ''));
        $role['company_context'] = trim((string) ($advanced['company_context'] ?? $entry['company_context'] ?? ''));
        $role['highlights'] = trim((string) ($entry['highlights'] ?? ''));
        if ($role['highlights'] === '') {
          $role['highlights'] = $this->buildFallbackHighlightsFromRole($role);
        }
        $role['key_achievements'] = $achievements;
        $role['technologies'] = $technologies;
        unset($role['_row_id']);

        $roles[] = $role;
      }

      if (!empty($roles)) {
        $consolidated['professional_experience'] = $roles;
      }
      else {
        $consolidated['professional_experience'] = [];
      }

      $consolidated['extraction_metadata']['last_form_sync'] = date('c');
      $job_seeker_data['consolidated_profile_json'] = json_encode($consolidated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
  }

  /**
   * Format a consolidated section as plain text lines for editing.
   */
  private function formatSectionForTextarea(array $consolidated, string $section): string {
    $data = $consolidated[$section] ?? [];
    if (!is_array($data) || empty($data)) {
      return '';
    }

    $lines = [];

    $to_text = static function($value): string {
      if (is_scalar($value)) {
        return trim((string) $value);
      }
      return '';
    };

    $normalize_scalar_list = static function(array $values): array {
      $normalized = [];
      foreach ($values as $value) {
        if (is_scalar($value)) {
          $normalized[] = trim((string) $value);
        }
      }
      return array_values(array_filter($normalized));
    };

    if ($section === 'technical_expertise') {
      // Newer schema shape: { categories: [ { name, skills[] } ] }
      if (isset($data['categories']) && is_array($data['categories'])) {
        foreach ($data['categories'] as $category_entry) {
          if (!is_array($category_entry)) {
            continue;
          }
          $label = $to_text($category_entry['name'] ?? 'General');
          if ($label === '') {
            $label = 'General';
          }
          $skills = !empty($category_entry['skills']) && is_array($category_entry['skills'])
            ? $normalize_scalar_list($category_entry['skills'])
            : [];

          if (!empty($skills)) {
            $lines[] = $label . ': ' . implode(', ', $skills);
          }
          elseif ($label !== '') {
            $lines[] = $label;
          }
        }
      }
      // Legacy schema shape: { "Category": ["skill1", "skill2"] }
      else {
        foreach ($data as $category => $skills) {
          if (!is_array($skills)) {
            continue;
          }

          $normalized_skills = $normalize_scalar_list($skills);
          if (!empty($normalized_skills)) {
            $label = is_string($category) ? trim($category) : 'General';
            $lines[] = $label . ': ' . implode(', ', $normalized_skills);
          }
        }
      }

      return implode("\n", array_values(array_unique(array_filter($lines))));
    }

    if ($section === 'consulting_practice') {
      $engagements = [];
      if (isset($data['engagements']) && is_array($data['engagements'])) {
        $engagements = $data['engagements'];
      }
      elseif (isset($data['notable_engagements']) && is_array($data['notable_engagements'])) {
        $engagements = $data['notable_engagements'];
      }

      foreach ($engagements as $engagement) {
        if (is_array($engagement) && !empty($engagement['client'])) {
          $client = $to_text($engagement['client']);
          if ($client !== '') {
            $lines[] = $client;
          }
        }
      }
      return implode("\n", array_values(array_unique($lines)));
    }

    if ($section === 'education') {
      foreach ($data as $item) {
        if (is_string($item)) {
          $lines[] = $item;
          continue;
        }
        if (!is_array($item)) {
          continue;
        }

        $institution = $to_text($item['institution'] ?? '');
        $degree = $to_text($item['degree'] ?? '');
        $end_date = $to_text($item['end_date'] ?? '');

        if ($institution === '' && $degree === '' && $end_date === '') {
          continue;
        }

        if ($institution !== '' || $degree !== '' || $end_date !== '') {
          $line_parts = [
            $institution,
            $degree,
            $end_date,
          ];
          $line_parts = array_map('trim', $line_parts);
          while (!empty($line_parts) && end($line_parts) === '') {
            array_pop($line_parts);
          }
          $lines[] = implode(' | ', $line_parts);
        }
      }

      return implode("\n", array_values(array_unique(array_filter($lines))));
    }

    foreach ($data as $item) {
      if (is_string($item)) {
        $lines[] = $item;
        continue;
      }
      if (!is_array($item)) {
        continue;
      }

      foreach (['institution', 'title', 'name', 'language', 'principle', 'company'] as $key) {
        if (!empty($item[$key]) && is_string($item[$key])) {
          $lines[] = $item[$key];
          break;
        }
      }
    }

    return implode("\n", array_values(array_unique(array_filter($lines))));
  }

  /**
   * Parse plain text section editor input into structured data.
   */
  private function parsePlainSectionInput(string $field_name, string $value): array {
    $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines)));

    if (empty($lines)) {
      return [];
    }

    switch ($field_name) {
      case 'field_technical_expertise_json':
        $result = [];
        foreach ($lines as $line) {
          if (strpos($line, ':') !== FALSE) {
            [$category, $skills_raw] = array_map('trim', explode(':', $line, 2));
            $skills = array_values(array_filter(array_map('trim', explode(',', $skills_raw))));
            if ($category !== '' && !empty($skills)) {
              $result[$category] = $skills;
            }
          }
          else {
            $result['General'][] = $line;
          }
        }
        return $result;

      case 'field_strategic_differentiators_json':
        return array_map(function(string $line): array {
          return ['title' => $line, 'description' => ''];
        }, $lines);

      case 'field_leadership_philosophy_json':
        return array_map(function(string $line): array {
          return ['principle' => $line];
        }, $lines);

      case 'field_demonstration_projects_json':
        return array_map(function(string $line): array {
          return ['name' => $line, 'description' => ''];
        }, $lines);

      case 'field_publications_json':
        return array_map(function(string $line): array {
          return ['title' => $line];
        }, $lines);

      case 'field_certifications_json':
        return array_map(function(string $line): array {
          return ['name' => $line];
        }, $lines);

      case 'field_patents_json':
        return array_map(function(string $line): array {
          return ['title' => $line];
        }, $lines);

      case 'field_awards_json':
        return array_map(function(string $line): array {
          return ['title' => $line];
        }, $lines);

      case 'field_languages_json':
        return array_map(function(string $line): array {
          return ['language' => $line, 'proficiency' => ''];
        }, $lines);

      case 'field_consulting_practice_json':
        return [
          'engagements' => array_map(function(string $line): array {
            return ['client' => $line, 'project_name' => '', 'role' => '', 'description' => ''];
          }, $lines),
        ];

      case 'field_early_career_json':
        return array_map(function(string $line): array {
          return ['company' => $line, 'title' => ''];
        }, $lines);

      case 'field_education_json':
        return array_map(function(string $line): array {
          $parts = array_map('trim', explode('|', $line));
          return [
            'institution' => $parts[0] ?? $line,
            'degree' => $parts[1] ?? '',
            'end_date' => $parts[2] ?? '',
          ];
        }, $lines);

      default:
        return $lines;
    }
  }

  /**
   * AJAX callback for professional experience editor.
   */
  public function professionalExperienceAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['professional_experience']['experience_editor'];
  }

  /**
   * Add another professional experience row.
   */
  public function addProfessionalExperienceRow(array &$form, FormStateInterface $form_state): void {
    $entries = $form_state->get('professional_experience_entries_state');
    if (!is_array($entries)) {
      $entries = [];
    }

    $submitted_entries = $this->getProfessionalExperienceEntriesFromInput($form_state);
    if (is_array($submitted_entries)) {
      $entries = $submitted_entries;
    }

    $entries[] = [];
    $entries = $this->ensureProfessionalExperienceRowIds($entries);
    $this->setProfessionalExperienceEntriesState($form_state, $entries, TRUE);
    $this->persistProfessionalExperienceEntries($entries);
    $form_state->set('professional_experience_action_message', (string) $this->t('Added a new role entry.'));
    $form_state->setRebuild(TRUE);
  }

  /**
   * Remove a professional experience row.
   */
  public function removeProfessionalExperienceRow(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $idx = $this->resolveTriggeredRowIndex($trigger, 'remove_professional_experience_', 'professional_experience_entries');
    $row_id = '';
    if (!empty($trigger['#attributes']['data-remove-row-id'])) {
      $row_id = (string) $trigger['#attributes']['data-remove-row-id'];
    }

    $removed = FALSE;

    $entries = $form_state->get('professional_experience_entries_state');
    if (!is_array($entries)) {
      $entries = [];
    }

    $submitted_entries = $this->getProfessionalExperienceEntriesFromInput($form_state);
    if (is_array($submitted_entries)) {
      $entries = $submitted_entries;
    }
    $entries = $this->ensureProfessionalExperienceRowIds($entries);

    if ($row_id !== '') {
      $resolved_idx = $this->findProfessionalExperienceRowIndexById($entries, $row_id);
      if ($resolved_idx >= 0) {
        $idx = $resolved_idx;
      }
    }

    if ($idx >= 0) {
      if (isset($entries[$idx])) {
        unset($entries[$idx]);
        $entries = array_values($entries);
        $this->setProfessionalExperienceEntriesState($form_state, $entries, TRUE);
        $this->persistProfessionalExperienceEntries($entries);
        $form_state->set('professional_experience_action_message', (string) $this->t('Removed role entry successfully.'));

        $removed = TRUE;
      }
    }

    if (!$removed) {
      $this->setProfessionalExperienceEntriesState($form_state, $entries, FALSE);
      $form_state->set('professional_experience_action_message', (string) $this->t('No role was removed. Please try again.'));
    }
    $form_state->setRebuild(TRUE);
  }

  /**
   * Resolve the clicked row index from a triggering form element.
   */
  private function resolveTriggeredRowIndex(array $trigger, string $name_prefix, string $array_parent_key): int {
    if (isset($trigger['#attributes']['data-remove-index'])) {
      return (int) $trigger['#attributes']['data-remove-index'];
    }

    if (!empty($trigger['#name'])) {
      $name = (string) $trigger['#name'];
      $quoted_prefix = preg_quote($name_prefix, '/');
      if (preg_match('/^' . $quoted_prefix . '(\\d+)(?:_.+)?$/', $name, $matches)) {
        return (int) $matches[1];
      }
    }

    if (!empty($trigger['#array_parents']) && is_array($trigger['#array_parents'])) {
      $entry_pos = array_search($array_parent_key, $trigger['#array_parents'], TRUE);
      if ($entry_pos !== FALSE && isset($trigger['#array_parents'][$entry_pos + 1])) {
        return (int) $trigger['#array_parents'][$entry_pos + 1];
      }
    }

    return -1;
  }

  /**
   * Build display label for a professional experience entry.
   */
  private function buildProfessionalExperienceEntryLabel(array $role, int $index): string {
    $company = trim((string) ($role['company'] ?? ''));
    $title = trim((string) ($role['title'] ?? ''));

    if ($company !== '' && $title !== '') {
      return $company . ' — ' . $title;
    }
    if ($company !== '') {
      return $company;
    }
    if ($title !== '') {
      return $title;
    }

    return (string) $this->t('Role @num', ['@num' => $index + 1]);
  }

  /**
   * Create a safe slug for button names/attributes.
   */
  private function slugifyProfessionalExperienceLabel(string $label): string {
    $slug = mb_strtolower($label);
    $slug = preg_replace('/[^a-z0-9]+/u', '_', $slug);
    $slug = trim((string) $slug, '_');

    if ($slug === '') {
      return 'role';
    }

    return $slug;
  }

  /**
   * Get professional experience entries from current raw submitted input.
   */
  private function getProfessionalExperienceEntriesFromInput(FormStateInterface $form_state): ?array {
    $user_input = $form_state->getUserInput();

    if (isset($user_input['professional_experience_entries'])
      && is_array($user_input['professional_experience_entries'])) {
      return array_values($user_input['professional_experience_entries']);
    }

    if (isset($user_input['professional_experience']['experience_editor']['professional_experience_entries'])
      && is_array($user_input['professional_experience']['experience_editor']['professional_experience_entries'])) {
      return array_values($user_input['professional_experience']['experience_editor']['professional_experience_entries']);
    }

    return NULL;
  }

  /**
   * Persist professional experience entries state and optionally sync form input.
   */
  private function setProfessionalExperienceEntriesState(FormStateInterface $form_state, array $entries, bool $sync_user_input): void {
    $entries = $this->ensureProfessionalExperienceRowIds(array_values($entries));
    $form_state->set('professional_experience_entries_state', $entries);
    $form_state->set('professional_experience_row_count', max(1, count($entries)));
    $form_state->setValue('professional_experience_entries', $entries);
    $form_state->setValue(['professional_experience', 'experience_editor', 'professional_experience_entries'], $entries);

    if (!$sync_user_input) {
      return;
    }

    $user_input = $form_state->getUserInput();
    if (!is_array($user_input)) {
      return;
    }

    $user_input['professional_experience_entries'] = $entries;

    if (!isset($user_input['professional_experience']) || !is_array($user_input['professional_experience'])) {
      $user_input['professional_experience'] = [];
    }
    if (!isset($user_input['professional_experience']['experience_editor']) || !is_array($user_input['professional_experience']['experience_editor'])) {
      $user_input['professional_experience']['experience_editor'] = [];
    }

    $user_input['professional_experience']['experience_editor']['professional_experience_entries'] = $entries;
    $form_state->setUserInput($user_input);
  }

  /**
   * Persist professional experience entries immediately to consolidated JSON.
   */
  private function persistProfessionalExperienceEntries(array $entries): void {
    $uid = (int) \Drupal::currentUser()->id();
    if ($uid <= 0) {
      return;
    }

    $profile = $this->jobSeekerService->loadByUserId($uid);
    if (!$profile || empty($profile->id)) {
      return;
    }

    $consolidated = [];
    if (!empty($profile->consolidated_profile_json)) {
      $decoded = json_decode((string) $profile->consolidated_profile_json, TRUE);
      $consolidated = is_array($decoded) ? $decoded : [];
    }

    $existing_roles = $consolidated['professional_experience'] ?? [];
    if (!is_array($existing_roles)) {
      $existing_roles = [];
    }

    $roles = [];
    foreach ($entries as $idx => $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $company = trim((string) ($entry['company'] ?? ''));
      $title = trim((string) ($entry['title'] ?? ''));
      if ($company === '' && $title === '') {
        continue;
      }

      $advanced = is_array($entry['advanced'] ?? NULL) ? $entry['advanced'] : [];
      $start = trim((string) ($entry['dates']['start_date'] ?? $entry['start_date'] ?? ''));
      $end = trim((string) ($entry['dates']['end_date'] ?? $entry['end_date'] ?? ''));
      $tech_raw = (string) ($entry['technologies'] ?? '');
      $ach_raw = (string) ($entry['key_achievements'] ?? '');

      $technologies = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $tech_raw))));
      $achievements = array_values(array_filter(array_map('trim', preg_split('/\R+/', $ach_raw))));

      $role = is_array($existing_roles[$idx] ?? NULL) ? $existing_roles[$idx] : [];
      $role['company'] = $company;
      $role['title'] = $title;
      $role['employment_type'] = trim((string) ($advanced['employment_type'] ?? $entry['employment_type'] ?? ''));
      $role['via_company'] = trim((string) ($advanced['via_company'] ?? $entry['via_company'] ?? ''));
      $role['start_date'] = $start;
      $role['end_date'] = $end === '' ? NULL : $end;
      $role['location'] = trim((string) ($entry['location'] ?? ''));
      $role['company_context'] = trim((string) ($advanced['company_context'] ?? $entry['company_context'] ?? ''));
      $role['highlights'] = trim((string) ($entry['highlights'] ?? ''));
      if ($role['highlights'] === '') {
        $role['highlights'] = $this->buildFallbackHighlightsFromRole($role);
      }
      $role['key_achievements'] = $achievements;
      $role['technologies'] = $technologies;
      unset($role['_row_id']);

      $roles[] = $role;
    }

    $consolidated['professional_experience'] = $roles;
    if (!isset($consolidated['extraction_metadata']) || !is_array($consolidated['extraction_metadata'])) {
      $consolidated['extraction_metadata'] = [];
    }
    $consolidated['extraction_metadata']['last_form_sync'] = date('c');

    $this->jobSeekerService->update((int) $profile->id, [
      'consolidated_profile_json' => json_encode($consolidated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ]);
  }

  /**
   * Extract achievement text lines from nested responsibility categories.
   */
  private function extractExperienceAchievementTexts(array $role): array {
    $lines = [];
    $categories = $role['responsibility_categories'] ?? NULL;
    if (!is_array($categories)) {
      return [];
    }

    foreach ($categories as $category) {
      if (!is_array($category) || empty($category['achievements']) || !is_array($category['achievements'])) {
        continue;
      }
      foreach ($category['achievements'] as $achievement) {
        if (is_array($achievement)) {
          $text = trim((string) ($achievement['text'] ?? ''));
          if ($text !== '') {
            $lines[] = $text;
          }
        }
        elseif (is_string($achievement)) {
          $text = trim($achievement);
          if ($text !== '') {
            $lines[] = $text;
          }
        }
      }
    }

    return array_values(array_unique($lines));
  }

  /**
   * Extract technologies from nested responsibility categories.
   */
  private function extractExperienceTechnologies(array $role): array {
    $technologies = [];
    $categories = $role['responsibility_categories'] ?? NULL;
    if (!is_array($categories)) {
      return [];
    }

    foreach ($categories as $category) {
      if (!is_array($category) || empty($category['achievements']) || !is_array($category['achievements'])) {
        continue;
      }
      foreach ($category['achievements'] as $achievement) {
        if (!is_array($achievement)) {
          continue;
        }
        $tech_list = $achievement['technologies'] ?? NULL;
        if (!is_array($tech_list)) {
          continue;
        }
        foreach ($tech_list as $tech) {
          $name = trim((string) $tech);
          if ($name !== '') {
            $technologies[] = $name;
          }
        }
      }
    }

    return array_values(array_unique($technologies));
  }

  /**
   * Build fallback highlights text from achievement lines when empty.
   */
  private function buildFallbackHighlightsFromRole(array $role): string {
    $lines = [];

    $key_achievements = $role['key_achievements'] ?? NULL;
    if (is_array($key_achievements)) {
      foreach ($key_achievements as $line) {
        $text = trim((string) $line);
        if ($text !== '') {
          $lines[] = $text;
        }
      }
    }

    if (empty($lines)) {
      $lines = $this->extractExperienceAchievementTexts($role);
    }

    if (empty($lines)) {
      return '';
    }

    return implode("\n", array_slice($lines, 0, 2));
  }

  /**
   * Ensure each professional experience entry has a stable internal row ID.
   */
  private function ensureProfessionalExperienceRowIds(array $entries): array {
    foreach ($entries as $index => $entry) {
      if (!is_array($entry)) {
        $entry = [];
      }
      if (empty($entry['_row_id']) || !is_string($entry['_row_id'])) {
        $entry['_row_id'] = 'row_' . md5(microtime(TRUE) . '|' . (string) $index . '|' . random_int(1000, 999999));
      }
      $entries[$index] = $entry;
    }

    return $entries;
  }

  /**
   * Resolve row index by stable row ID.
   */
  private function findProfessionalExperienceRowIndexById(array $entries, string $row_id): int {
    foreach ($entries as $index => $entry) {
      if (is_array($entry) && isset($entry['_row_id']) && (string) $entry['_row_id'] === $row_id) {
        return (int) $index;
      }
    }

    return -1;
  }

  /**
   * Helper: Recursively delete a directory and all its contents.
   */
  private function deleteDirectoryRecursive($dir) {
    $this->resumeUploadSubform->deleteDirectoryRecursive($dir);
  }

  /**
   * STEP 1 Helper: Load resume record from database.
   */
  private function loadResumeRecord($resume_id, $uid): array {
    $job_seeker_profile = $this->jobSeekerService->loadByUserId($uid);
    $job_seeker_ids = array_values(array_unique(array_filter([
      (int) $uid,
      (int) ($job_seeker_profile->id ?? 0),
    ])));
    return $this->userProfileRepository->loadResumeRecord((int) $resume_id, $job_seeker_ids);
  }

  /**
   * STEP 2 Helper: Load file entity and validate physical file exists.
   */
  private function loadAndValidateFile($file_id) {
    $file = \Drupal\file\Entity\File::load($file_id);
    if (!$file) {
      throw new \Exception("File entity not found (file_id: {$file_id})");
    }

    $file_uri = $file->getFileUri();

    $file_path = \Drupal::service('file_system')->realpath($file_uri);
    if (!$file_path || !file_exists($file_path)) {
      throw new \Exception("Resume file not found: " . basename($file_uri));
    }
    
    return $file;
  }

  /**
   * STEP 4 Helper: Store extracted text in resume table.
   */
  private function storeExtractedText($resume_id, $extracted_text, $filename) {
    $this->userProfileRepository->updateResumeExtractedText((int) $resume_id, $extracted_text);

    $this->logInfo('✅ STEP 4: Stored @chars characters of extracted text for: @filename', [
      '@chars' => strlen($extracted_text),
      '@filename' => $filename,
    ]);
  }

  /**
   * STEP 5A Helper: Parse resume in development mode (mock data).
   */
  private function parseResumeDevMode($extracted_text, $filename, $uid, $resume_record) {
    $logger = \Drupal::logger('job_hunter');
    
    $logger->info('🔧 STEP 5A: DEVELOPMENT MODE - Preparing mock AI request', [
      'filename' => $filename,
      'text_length' => strlen($extracted_text),
      'user_id' => $uid,
      'resume_id' => $resume_record['id'],
      'file_id' => $resume_record['file_id'],
    ]);

    // Generate mock parsed data for development
    $parsed_data = $this->generateMockResumeData($filename);
    
    $logger->info('🔧 STEP 5A: Mock AI response generated', [
      'parsed_fields' => array_keys($parsed_data),
      'total_jobs' => count($parsed_data['work_history'] ?? []),
      'total_education' => count($parsed_data['education'] ?? []),
    ]);

    \Drupal::messenger()->addStatus($this->t('🔧 DEV MODE: Resume "@filename" parsed with mock AI. Check logs for details.', [
      '@filename' => $filename,
    ]));
    
    return $parsed_data;
  }

  /**
   * STEP 5B Helper: Parse resume in production mode (GenAI service).
   *
   * Delegates to ResumeGenAiParsingWorker to keep one shared parsing flow.
   */
  private function parseResumeProdMode($extracted_text, $filename) {
    $logger = \Drupal::logger('job_hunter');
    $uid = (int) $this->currentUser->id();

    $logger->info('🚀 STEP 5B: PRODUCTION MODE - Delegating to queue worker parser', [
      'filename' => $filename,
      'text_length' => strlen($extracted_text),
      'uid' => $uid,
    ]);

    try {
      $worker = $this->createResumeGenAiWorker();
      $reflection = new \ReflectionClass($worker);
      $method = $reflection->getMethod('parseResumeProdMode');
      $method->setAccessible(TRUE);

      $result = $method->invoke($worker, $extracted_text, $filename, $uid);

      if (!is_array($result) || empty($result['parsed_data'])) {
        throw new \Exception('Resume parsing returned empty data.');
      }

      return $result['parsed_data'];
    } catch (\Exception $e) {
      $logger->error('GenAI service error: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Create a ResumeGenAiParsingWorker instance with injected dependencies.
   */
  private function createResumeGenAiWorker(): ResumeGenAiParsingWorker {
    $worker = new ResumeGenAiParsingWorker([], 'job_hunter_genai_parsing', []);
    $worker->configFactory = $this->configFactory;
    $worker->aiApiService = $this->aiApiService;

    return $worker;
  }

  /**
   * Call AIApiService and parse the JSON response.
   *
   * @param string $prompt
   *   The prompt to send.
   * @param string $filename
   *   The source filename for logging.
   * @param string $chunk_name
   *   Name of this chunk for logging (e.g., 'core', 'experience').
   * @param int $uid
   *   The user ID.
   * @param int $max_tokens
   *   Maximum tokens for the response.
   *
   * @return array|null
   *   Parsed JSON data or null on failure.
   */
  private function callAIApiServiceAndParse($prompt, $filename, $chunk_name, $uid, $max_tokens = 20000) {
    $logger = \Drupal::logger('job_hunter');
    
    // Use centralized AIApiService with proper logging
    $result = $this->aiApiService->invokeModelDirect(
      $prompt,
      'job_hunter',
      'resume_parsing_quick',
      [
        'uid' => $uid,
        'filename' => $filename,
        'chunk' => $chunk_name,
        'source' => 'profile_form_upload',
        'item_key' => "resume_quick_parse_{$uid}_{$chunk_name}_" . md5($filename),
      ],
      [
        'max_tokens' => $max_tokens,
      ]
    );

    if (!$result['success']) {
      $logger->error('❌ AIApiService call failed for @chunk: @error', [
        '@chunk' => $chunk_name,
        '@error' => $result['error'] ?? 'Unknown error',
      ]);
      return NULL;
    }

    $response_text = $result['response'];
    $stop_reason = $result['stop_reason'];

    $logger->info('🔍 GenAI @chunk response: @len chars, stop_reason: @reason', [
      '@chunk' => $chunk_name,
      '@len' => strlen($response_text),
      '@reason' => $stop_reason,
    ]);

    // Check for truncation
    if ($stop_reason === 'max_tokens') {
      $logger->warning('⚠️  @chunk hit max_tokens limit! Response may be incomplete.', [
        '@chunk' => $chunk_name,
      ]);
    }

    // Extract and parse JSON
    $json_text = $this->extractJsonFromResponse($response_text);
    
    if ($json_text) {
      $parsed_data = json_decode($json_text, TRUE);
      if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_data)) {
        return $parsed_data;
      }
      $logger->warning('JSON decode error in @chunk: @error', [
        '@chunk' => $chunk_name,
        '@error' => json_last_error_msg(),
      ]);
    }
    else {
      $logger->warning('No JSON found in @chunk response. Preview: @preview', [
        '@chunk' => $chunk_name,
        '@preview' => substr($response_text, 0, 300),
      ]);
    }

    return NULL;
  }

  /**
   * Extract clean JSON from a GenAI response that may contain markdown or other text.
   *
   * @param string $response_text
   *   The raw response text from GenAI.
   *
   * @return string|null
   *   The extracted JSON string, or null if no valid JSON found.
   */
  private function extractJsonFromResponse($response_text) {
    // Strategy 1: Try to extract JSON from markdown code fences
    // Match ```json ... ``` or ``` ... ```
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/s', $response_text, $matches)) {
      return trim($matches[1]);
    }

    // Strategy 2: Find balanced JSON object using brace counting
    $start_pos = strpos($response_text, '{');
    if ($start_pos === FALSE) {
      return NULL;
    }

    $depth = 0;
    $in_string = FALSE;
    $escape_next = FALSE;
    $len = strlen($response_text);
    $json_end = -1;

    for ($i = $start_pos; $i < $len; $i++) {
      $char = $response_text[$i];

      if ($escape_next) {
        $escape_next = FALSE;
        continue;
      }

      if ($char === '\\' && $in_string) {
        $escape_next = TRUE;
        continue;
      }

      if ($char === '"') {
        $in_string = !$in_string;
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
          $json_end = $i;
          break;
        }
      }
    }

    if ($json_end > $start_pos) {
      return substr($response_text, $start_pos, $json_end - $start_pos + 1);
    }

    // Strategy 3: Fallback to greedy regex (last resort)
    if (preg_match('/\{[\s\S]*\}/s', $response_text, $matches)) {
      return $matches[0];
    }

    return NULL;
  }

  /**
   * Build the comprehensive resume parsing prompt for GenAI.
   *
   * Uses JSON schema v1.0 as defined in docs/RESUME_JSON_SCHEMA.md
   *
   * @param string $extracted_text
   *   The extracted text from the resume file.
   * @param string $filename
   *   The source filename.
   *
   * @return string
   *   The complete prompt for GenAI.
   */
  private function buildResumeParsingPrompt($extracted_text, $filename) {
    $file_id = 0; // Will be populated from context
    $timestamp = date('c');
    $char_count = strlen($extracted_text);

    $prompt = <<<PROMPT
You are a professional resume parser. Analyze the following resume text and extract ALL data into a comprehensive JSON structure.

CRITICAL REQUIREMENTS:
1. Preserve ALL information from the resume - do not summarize or omit details
2. Extract quantified metrics (dollar amounts, percentages, team sizes, etc.) into the metrics arrays
3. Identify technologies mentioned in each achievement
4. Extract searchable keywords from each achievement
5. Use YYYY-MM format for all dates (e.g., "2022-06")
6. Use null for missing optional fields, not empty strings
7. Return ONLY valid JSON conforming to RFC 8259 - NO markdown code blocks, USE proper JSON escaping (\n for newlines, \" for quotes)
8. For each professional_experience entry, always include highlights (string), key_achievements (array), and technologies (array)
9. For responsibility_categories, create at least one category when bullets exist; if uncategorized use "General Responsibilities"
10. Every achievement object must include text and arrays for metrics, technologies, and keywords (use [] if none)

JSON SCHEMA (v1.0):
{
  "schema_version": "1.0",
  "extraction_metadata": {
    "source_file_id": {$file_id},
    "source_filename": "{$filename}",
    "extracted_at": "{$timestamp}",
    "character_count": {$char_count}
  },
  "contact_info": {
    "full_name": "First Last",
    "credentials": ["MBA", "PMP"],
    "headline": "Professional title/tagline",
    "location": {"city": "City", "state": "ST"},
    "phone": "(xxx) xxx-xxxx",
    "email": "email@example.com",
    "websites": [
      {"type": "personal|github|linkedin|demo|portfolio", "url": "https://..."}
    ],
    "linkedin": {
      "followers": "count if mentioned",
      "groups_administered": ["group names"]
    }
  },
  "executive_profile": {
    "summary": "Full executive summary text",
    "industry_focus": ["industry1", "industry2"],
    "key_metrics": [
      {"metric": "metric_name", "value": "XXM+", "context": "explanation"}
    ]
  },
  "strategic_differentiators": [
    {"title": "Differentiator Title", "description": "Full description"}
  ],
  "professional_experience": [
    {
      "company": "Company Name",
      "title": "Job Title",
      "employment_type": "direct|consulting",
      "via_company": null,
      "start_date": "YYYY-MM",
      "end_date": "YYYY-MM or null if current",
      "location": "City, ST",
      "company_context": "Brief company description if provided",
      "highlights": "1-2 line impact summary for this role",
      "key_achievements": ["Achievement line 1", "Achievement line 2"],
      "technologies": ["Snowflake", "Python"],
      "responsibility_categories": [
        {
          "category": "Category Name (from resume headers)",
          "achievements": [
            {
              "text": "Full bullet point text",
              "metrics": ["$3.2M revenue", "30% improvement"],
              "technologies": ["Snowflake", "Python"],
              "keywords": ["AI strategy", "data governance"]
            }
          ]
        }
      ]
    }
  ],
  "consulting_practice": {
    "company": "Consulting Company Name",
    "title": "Founder & Principal",
    "start_date": "YYYY-MM",
    "end_date": null,
    "is_current": true,
    "location": "City, ST",
    "website": "https://...",
    "description": "Practice description",
    "notable_engagements": [
      {"client": "Client Name", "role": "Role Title", "description": "Engagement description"}
    ]
  },
  "early_career": {
    "period": "YYYY-YYYY",
    "summary": "Career summary text",
    "positions": [
      {"company": "Company", "duration": "X years or null", "focus": "Role description"}
    ]
  },
  "education": [
    {
      "institution": "University Name",
      "location": "City, ST (if provided)",
      "degree": "Master of Business Administration",
      "abbreviation": "MBA",
      "field": "Field of study or null",
      "start_date": "YYYY-MM",
      "end_date": "YYYY-MM"
    }
  ],
  "technical_expertise": {
    "categories": [
      {
        "name": "Category Name",
        "skills": ["skill1", "skill2"],
        "subcategories": [
          {"industry": "Industry Name", "skills": ["skill1", "skill2"]}
        ],
        "frameworks": ["framework1", "framework2"]
      }
    ]
  },
  "leadership_philosophy": {
    "statement": "Full leadership philosophy text",
    "influences": ["influence1", "influence2"],
    "key_themes": ["theme1", "theme2"]
  },
  "demonstration_projects": [
    {
      "name": "Project Name",
      "url": "https://...",
      "technologies": ["tech1", "tech2"],
      "description": "Project description"
    }
  ],
  "publications": [
    {
      "title": "Publication Title",
      "authors": ["Author1", "Author2"],
      "publication": "Journal/Conference Name",
      "date": "YYYY-MM",
      "url": "https://..." or null,
      "doi": "DOI identifier" or null,
      "citation_count": "number if mentioned" or null,
      "description": "Brief description if provided"
    }
  ],
  "patents": [
    {
      "title": "Patent Title",
      "patent_number": "US1234567",
      "status": "granted|pending|filed",
      "filing_date": "YYYY-MM",
      "grant_date": "YYYY-MM" or null,
      "inventors": ["Inventor1", "Inventor2"],
      "assignee": "Company Name" or null,
      "url": "https://..." or null,
      "description": "Brief description"
    }
  ],
  "certifications": [
    {
      "name": "Certification Name",
      "issuing_organization": "Organization Name",
      "credential_id": "ID number" or null,
      "issue_date": "YYYY-MM",
      "expiration_date": "YYYY-MM" or null,
      "verification_url": "https://..." or null
    }
  ],
  "awards_and_honors": [
    {
      "title": "Award Title",
      "issuing_organization": "Organization Name",
      "date": "YYYY-MM",
      "description": "Brief description of the award/recognition"
    }
  ],
  "languages": [
    {
      "language": "Language Name",
      "proficiency": "native|fluent|professional|intermediate|basic"
    }
  ]
}

RESUME TEXT TO PARSE:
---
{$extracted_text}
---

Return the complete JSON object. Ensure all achievements are captured with their full text.
PROMPT;

    return $prompt;
  }

  /**
   * Build prompt for core profile sections (everything except professional_experience).
   *
   * @param string $extracted_text
   *   The extracted text from the resume file.
   * @param string $filename
   *   The source filename.
   *
   * @return string
   *   The prompt for GenAI.
   */
  private function buildCoreProfilePrompt($extracted_text, $filename) {
    $timestamp = date('c');
    $char_count = strlen($extracted_text);

    $prompt = <<<PROMPT
You are a professional resume parser. Extract the CORE PROFILE sections from this resume into JSON.

IMPORTANT: Do NOT include professional_experience in this response. That will be extracted separately.

REQUIREMENTS:
1. Preserve ALL information - do not summarize
2. Use YYYY-MM format for dates
3. Use null for missing optional fields
4. Return ONLY valid JSON conforming to RFC 8259 - NO markdown code blocks, USE proper JSON escaping (\n for newlines, \" for quotes)

JSON SCHEMA:
{
  "schema_version": "1.0",
  "extraction_metadata": {
    "source_filename": "{$filename}",
    "extracted_at": "{$timestamp}",
    "character_count": {$char_count}
  },
  "contact_info": {
    "full_name": "First Last",
    "credentials": ["MBA", "PMP"],
    "headline": "Professional title/tagline",
    "location": {"city": "City", "state": "ST"},
    "phone": "(xxx) xxx-xxxx",
    "email": "email@example.com",
    "websites": [{"type": "linkedin|github|personal", "url": "https://..."}]
  },
  "executive_profile": {
    "summary": "Full executive summary text",
    "industry_focus": ["industry1", "industry2"],
    "key_metrics": [{"metric": "name", "value": "XXM+", "context": "explanation"}]
  },
  "strategic_differentiators": [
    {"title": "Title", "description": "Description"}
  ],
  "consulting_practice": {
    "company": "Company Name",
    "title": "Title",
    "start_date": "YYYY-MM",
    "end_date": null,
    "description": "Description",
    "notable_engagements": [{"client": "Client", "role": "Role", "description": "Desc"}]
  },
  "early_career": {
    "period": "YYYY-YYYY",
    "summary": "Summary text",
    "positions": [{"company": "Company", "duration": "X years", "focus": "Role desc"}]
  },
  "education": [
    {"institution": "University", "degree": "Degree Name", "abbreviation": "MBA", "field": "Field", "end_date": "YYYY-MM"}
  ],
  "technical_expertise": {
    "categories": [{"name": "Category", "skills": ["skill1", "skill2"]}]
  },
  "leadership_philosophy": {
    "statement": "Philosophy text",
    "key_themes": ["theme1", "theme2"]
  },
  "demonstration_projects": [
    {"name": "Project", "url": "https://...", "technologies": ["tech1"], "description": "Desc"}
  ]
}

RESUME TEXT:
---
{$extracted_text}
---

Return the JSON object with all core profile sections. Do NOT include professional_experience.
PROMPT;

    return $prompt;
  }

  /**
   * Build prompt for professional experience section only.
   *
   * @param string $extracted_text
   *   The extracted text from the resume file.
   * @param string $filename
   *   The source filename.
   *
   * @return string
   *   The prompt for GenAI.
   */
  private function buildProfessionalExperiencePrompt($extracted_text, $filename) {
    $prompt = <<<PROMPT
You are a professional resume parser. Extract ONLY the professional work experience from this resume.

REQUIREMENTS:
1. Preserve ALL job details and achievements - do not summarize
2. Extract metrics (dollar amounts, percentages, team sizes) into metrics arrays
3. Identify technologies mentioned in each achievement
4. Extract searchable keywords from each achievement
5. Use YYYY-MM format for dates
6. Return ONLY valid JSON conforming to RFC 8259 - NO markdown code blocks, USE proper JSON escaping (\n for newlines, \" for quotes)
7. For each role include highlights (string), key_achievements (array), and technologies (array)
8. If bullets are present but categories are not explicit, use one category named "General Responsibilities"
9. Every achievement object must include text and arrays for metrics, technologies, and keywords (use [] if none)

JSON SCHEMA:
{
  "professional_experience": [
    {
      "company": "Company Name",
      "title": "Job Title",
      "employment_type": "direct|consulting",
      "via_company": null,
      "start_date": "YYYY-MM",
      "end_date": "YYYY-MM or null if current",
      "location": "City, ST",
      "company_context": "Brief company description if provided",
      "highlights": "1-2 line impact summary for this role",
      "key_achievements": ["Achievement line 1", "Achievement line 2"],
      "technologies": ["Python", "AWS"],
      "responsibility_categories": [
        {
          "category": "Category Name",
          "achievements": [
            {
              "text": "Full bullet point text",
              "metrics": ["$3.2M revenue", "30% improvement"],
              "technologies": ["Python", "AWS"],
              "keywords": ["AI strategy", "data governance"]
            }
          ]
        }
      ]
    }
  ]
}

RESUME TEXT:
---
{$extracted_text}
---

Return the JSON object with professional_experience array containing ALL jobs and achievements.
PROMPT;

    return $prompt;
  }

  /**
   * Build consolidated JSON by merging resume data (schema v1.0).
   *
   * Additively merges individual resume JSON into consolidated profile JSON.
   * No data is removed - only new unique items are added.
   *
   * @param int $uid
   *   The user ID.
   * @param array $latest_parsed_data
   *   The parsed JSON data from the resume being consolidated.
   *
   * @return int
   *   Number of new items added during merge.
   */
  private function buildConsolidatedJsonAndApplyToProfile($uid, array $latest_parsed_data) {
    try {
      // Get current profile
      $profile = $this->userProfileRepository->getConsolidatedProfileJsonRow($uid);
      
      if (!$profile) {
        \Drupal::logger('job_hunter')->warning('Cannot build consolidated JSON: no job seeker profile found for uid @uid', ['@uid' => $uid]);
        return 0;
      }
      
      // Decode existing consolidated JSON
      $consolidated = [];
      if (!empty($profile['consolidated_profile_json'])) {
        $consolidated = json_decode($profile['consolidated_profile_json'], TRUE) ?: [];
      }
      
      // Ensure schema v1.0 structure while preserving existing user data.
      if (empty($consolidated) || empty($consolidated['schema_version'])) {
        $consolidated['schema_version'] = '1.0';
      }
      if (empty($consolidated['extraction_metadata']) || !is_array($consolidated['extraction_metadata'])) {
        $consolidated['extraction_metadata'] = [];
      }
      if (empty($consolidated['extraction_metadata']['source_files']) || !is_array($consolidated['extraction_metadata']['source_files'])) {
        $consolidated['extraction_metadata']['source_files'] = [];
      }
      $consolidated['extraction_metadata']['consolidated_at'] = date('c');

      $required_sections = [
        'contact_info' => [],
        'executive_profile' => [],
        'organizational_philosophy' => [],
        'strategic_differentiators' => [],
        'professional_experience' => [],
        'consulting_practice' => [],
        'early_career' => [],
        'education' => [],
        'technical_expertise' => [],
        'leadership_philosophy' => [],
        'demonstration_projects' => [],
        'publications' => [],
        'certifications' => [],
        'patents' => [],
        'awards_and_honors' => [],
        'languages' => [],
        'job_search_preferences' => [],
        'demographics' => [],
      ];

      foreach ($required_sections as $section => $default_value) {
        if (!isset($consolidated[$section]) || !is_array($consolidated[$section])) {
          $consolidated[$section] = $default_value;
        }
      }
      
      // Merge latest parsed data - smart deduplicate, returns count of additions
      $additions = $this->mergeResumeDataV1($consolidated, $latest_parsed_data);
      
      // Update consolidated_at timestamp
      $consolidated['extraction_metadata']['consolidated_at'] = date('c');
      
      // Store updated consolidated JSON
      $this->userProfileRepository->saveConsolidatedProfileJson(
        $uid,
        json_encode($consolidated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
      );
      
      if ($additions > 0) {
        $this->logInfo('📊 Updated consolidated JSON for uid @uid: @count new items added', [
          '@uid' => $uid,
          '@count' => $additions,
        ]);
        \Drupal::messenger()->addStatus($this->t('Consolidated @count new items into your profile.', ['@count' => $additions]));
      } else {
        \Drupal::messenger()->addStatus($this->t('No new data identified - all information already in consolidated profile.'));
      }
      
      return $additions;
      
    } catch (\Exception $e) {
      $this->logError('Failed to build consolidated JSON: @error', ['@error' => $e->getMessage()]);
      \Drupal::messenger()->addWarning($this->t('Could not update profile: @error', ['@error' => $e->getMessage()]));
      return 0;
    }
  }

  /**
   * Smart merge resume data into consolidated structure (schema v1.0).
   *
   * Additively merges new resume data without removing existing data.
   * Deduplicates by comparing key identifiers (company+title for experience, etc.)
   *
   * @param array &$consolidated
   *   The consolidated data structure (modified by reference).
   * @param array $new_data
   *   New parsed data from resume (schema v1.0 format).
   *
   * @return int
   *   Count of new items added.
   */
  private function mergeResumeDataV1(array &$consolidated, array $new_data): int {
    $additions = 0;
    
    // Track source file
    if (!empty($new_data['extraction_metadata']['source_filename'])) {
      $source_file = $new_data['extraction_metadata']['source_filename'];
      if (!isset($consolidated['extraction_metadata']['source_files'])) {
        $consolidated['extraction_metadata']['source_files'] = [];
      }
      if (!in_array($source_file, $consolidated['extraction_metadata']['source_files'])) {
        $consolidated['extraction_metadata']['source_files'][] = $source_file;
        $additions++;
      }
    }
    
    // Contact info - merge fields, prefer non-empty values
    if (!empty($new_data['contact_info'])) {
      if (empty($consolidated['contact_info'])) {
        $consolidated['contact_info'] = [];
      }
      foreach ($new_data['contact_info'] as $key => $value) {
        if (!empty($value) && empty($consolidated['contact_info'][$key])) {
          $consolidated['contact_info'][$key] = $value;
          $additions++;
        }
      }
    }
    
    // Executive profile - merge unique summary statements
    if (!empty($new_data['executive_profile'])) {
      $additions += $this->mergeArraySection($consolidated, 'executive_profile', $new_data['executive_profile'], 'summary');
    }
    
    // Organizational philosophy - merge unique items
    if (!empty($new_data['organizational_philosophy'])) {
      $additions += $this->mergeArraySection($consolidated, 'organizational_philosophy', $new_data['organizational_philosophy'], 'principle');
    }
    
    // Strategic differentiators - merge unique items by title
    if (!empty($new_data['strategic_differentiators'])) {
      $additions += $this->mergeArraySection($consolidated, 'strategic_differentiators', $new_data['strategic_differentiators'], 'title');
    }
    
    // Professional experience - dedupe by company+title combination
    if (!empty($new_data['professional_experience'])) {
      $additions += $this->mergeExperienceSection($consolidated, 'professional_experience', $new_data['professional_experience']);
    }
    
    // Consulting practice - merge unique engagements by client+project
    if (!empty($new_data['consulting_practice'])) {
      if (!empty($new_data['consulting_practice']['engagements'])) {
        if (empty($consolidated['consulting_practice'])) {
          $consolidated['consulting_practice'] = ['engagements' => []];
        }
        if (empty($consolidated['consulting_practice']['engagements'])) {
          $consolidated['consulting_practice']['engagements'] = [];
        }
        foreach ($new_data['consulting_practice']['engagements'] as $engagement) {
          $key = ($engagement['client'] ?? '') . '|' . ($engagement['project_name'] ?? '');
          $exists = false;
          foreach ($consolidated['consulting_practice']['engagements'] as $existing) {
            $existingKey = ($existing['client'] ?? '') . '|' . ($existing['project_name'] ?? '');
            if ($key === $existingKey) {
              $exists = true;
              break;
            }
          }
          if (!$exists) {
            $consolidated['consulting_practice']['engagements'][] = $engagement;
            $additions++;
          }
        }
      }
    }
    
    // Early career - merge unique positions
    if (!empty($new_data['early_career'])) {
      $additions += $this->mergeExperienceSection($consolidated, 'early_career', $new_data['early_career']);
    }
    
    // Education - dedupe by institution+degree
    if (!empty($new_data['education'])) {
      if (empty($consolidated['education'])) {
        $consolidated['education'] = [];
      }
      foreach ($new_data['education'] as $edu) {
        $key = ($edu['institution'] ?? '') . '|' . ($edu['degree'] ?? '');
        $exists = false;
        foreach ($consolidated['education'] as $existing) {
          $existingKey = ($existing['institution'] ?? '') . '|' . ($existing['degree'] ?? '');
          if ($key === $existingKey) {
            $exists = true;
            break;
          }
        }
        if (!$exists) {
          $consolidated['education'][] = $edu;
          $additions++;
        }
      }
    }
    
    // Technical expertise - merge categories and dedupe skills within each
    if (!empty($new_data['technical_expertise'])) {
      if (empty($consolidated['technical_expertise'])) {
        $consolidated['technical_expertise'] = [];
      }
      foreach ($new_data['technical_expertise'] as $category => $skills) {
        if (!isset($consolidated['technical_expertise'][$category])) {
          $consolidated['technical_expertise'][$category] = [];
        }
        if (is_array($skills)) {
          foreach ($skills as $skill) {
            if (!in_array($skill, $consolidated['technical_expertise'][$category])) {
              $consolidated['technical_expertise'][$category][] = $skill;
              $additions++;
            }
          }
        }
      }
    }
    
    // Leadership philosophy - merge unique items
    if (!empty($new_data['leadership_philosophy'])) {
      $additions += $this->mergeArraySection($consolidated, 'leadership_philosophy', $new_data['leadership_philosophy'], 'principle');
    }
    
    // Demonstration projects - dedupe by name
    if (!empty($new_data['demonstration_projects'])) {
      if (empty($consolidated['demonstration_projects'])) {
        $consolidated['demonstration_projects'] = [];
      }
      foreach ($new_data['demonstration_projects'] as $project) {
        $name = $project['name'] ?? '';
        $exists = false;
        foreach ($consolidated['demonstration_projects'] as $existing) {
          if (($existing['name'] ?? '') === $name) {
            $exists = true;
            break;
          }
        }
        if (!$exists) {
          $consolidated['demonstration_projects'][] = $project;
          $additions++;
        }
      }
    }
    
    // Publications - dedupe by title and authors
    if (!empty($new_data['publications'])) {
      if (empty($consolidated['publications'])) {
        $consolidated['publications'] = [];
      }
      foreach ($new_data['publications'] as $publication) {
        $title = $publication['title'] ?? '';
        $authors = isset($publication['authors']) && is_array($publication['authors']) 
          ? implode('|', $publication['authors']) 
          : ($publication['authors'] ?? '');
        $key = $title . '|' . $authors;
        $exists = false;
        foreach ($consolidated['publications'] as $existing) {
          $existingTitle = $existing['title'] ?? '';
          $existingAuthors = isset($existing['authors']) && is_array($existing['authors']) 
            ? implode('|', $existing['authors']) 
            : ($existing['authors'] ?? '');
          $existingKey = $existingTitle . '|' . $existingAuthors;
          if ($key === $existingKey) {
            $exists = true;
            break;
          }
        }
        if (!$exists) {
          $consolidated['publications'][] = $publication;
          $additions++;
        }
      }
    }
    
    // Certifications - dedupe by name
    if (!empty($new_data['certifications'])) {
      $additions += $this->mergeArraySection($consolidated, 'certifications', $new_data['certifications'], 'name');
    }
    
    // Patents - dedupe by patent number or title and inventors
    if (!empty($new_data['patents'])) {
      if (empty($consolidated['patents'])) {
        $consolidated['patents'] = [];
      }
      foreach ($new_data['patents'] as $patent) {
        $patentNumber = $patent['patent_number'] ?? '';
        $title = $patent['title'] ?? '';
        $inventors = isset($patent['inventors']) && is_array($patent['inventors']) 
          ? implode('|', $patent['inventors']) 
          : ($patent['inventors'] ?? '');
        
        // Try to dedupe by patent_number first, then fallback to title|inventors
        $key = !empty($patentNumber) 
          ? $patentNumber 
          : ($title . '|' . $inventors);
        
        $exists = false;
        foreach ($consolidated['patents'] as $existing) {
          $existingPatentNumber = $existing['patent_number'] ?? '';
          $existingTitle = $existing['title'] ?? '';
          $existingInventors = isset($existing['inventors']) && is_array($existing['inventors']) 
            ? implode('|', $existing['inventors']) 
            : ($existing['inventors'] ?? '');
          
          $existingKey = !empty($existingPatentNumber) 
            ? $existingPatentNumber 
            : ($existingTitle . '|' . $existingInventors);
          
          if ($key === $existingKey) {
            $exists = true;
            break;
          }
        }
        if (!$exists) {
          $consolidated['patents'][] = $patent;
          $additions++;
        }
      }
    }
    
    // Awards and honors - dedupe by title and organization
    if (!empty($new_data['awards_and_honors'])) {
      if (empty($consolidated['awards_and_honors'])) {
        $consolidated['awards_and_honors'] = [];
      }
      foreach ($new_data['awards_and_honors'] as $award) {
        $key = ($award['title'] ?? '') . '|' . ($award['issuing_organization'] ?? '');
        $exists = false;
        foreach ($consolidated['awards_and_honors'] as $existing) {
          $existingKey = ($existing['title'] ?? '') . '|' . ($existing['issuing_organization'] ?? '');
          if ($key === $existingKey) {
            $exists = true;
            break;
          }
        }
        if (!$exists) {
          $consolidated['awards_and_honors'][] = $award;
          $additions++;
        }
      }
    }
    
    // Languages - dedupe by language name
    if (!empty($new_data['languages'])) {
      $additions += $this->mergeArraySection($consolidated, 'languages', $new_data['languages'], 'language');
    }
    
    return $additions;
  }

  /**
   * Normalizes legacy status values in jobhunter_resume_parsed_data.
   */
  private function normalizeResumeParsedDataStatuses(int $uid): void {
    try {
      $this->userProfileRepository->normalizeParsedDataStatuses($uid);
    }
    catch (\Exception $e) {
      // Non-fatal: UI can still fall back to tolerant checks.
      $this->logWarning('Resume parsed_data status normalization failed: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * Fetches the latest parsed record for a given file.
   *
   * If no record exists for the current file ID, attempts to find a legacy
   * record by matching filename in resume_path and repairs resume_file_id.
   */
  private function getLatestParsedRecordForFile($connection, int $uid, int $file_id, string $file_uri, string $filename): ?array {
    return $this->resumeUploadSubform->getLatestParsedRecordForFile($connection, $uid, $file_id, $file_uri, $filename);
  }

  /**
   * Ensures consolidated_profile_json has extraction_metadata.source_files when
   * all resume parsing is complete.
   */
  private function ensureResumeConsolidationUpToDate(int $uid): void {
    try {
      $pending = $this->userProfileRepository->countPendingParsedRecords($uid);

      if ($pending > 0) {
        return;
      }

      $complete_rows = $this->userProfileRepository->getCompleteParsedFileIds($uid);

      if (empty($complete_rows)) {
        return;
      }

      $profile = $this->userProfileRepository->getConsolidatedProfileJsonRow($uid);

      $consolidated = [];
      if (!empty($profile['consolidated_profile_json'])) {
        $consolidated = json_decode($profile['consolidated_profile_json'], TRUE) ?: [];
      }

      $source_files = $consolidated['extraction_metadata']['source_files'] ?? [];
      if (!is_array($source_files)) {
        $source_files = [];
      }

      $expected_filenames = [];
      foreach ($complete_rows as $fid) {
        $file = \Drupal\file\Entity\File::load((int) $fid);
        if ($file) {
          $expected_filenames[] = $file->getFilename();
        }
      }
      $expected_filenames = array_values(array_unique(array_filter($expected_filenames)));

      // If consolidation already includes every file, do nothing.
      $missing = array_values(array_diff($expected_filenames, $source_files));
      if (empty($missing)) {
        return;
      }

      // Run the exact same consolidation logic as the queue worker.
      $worker = \Drupal\job_hunter\Plugin\QueueWorker\ResumeGenAiParsingWorker::create(
        \Drupal::getContainer(),
        [],
        'job_hunter_genai_parsing',
        []
      );
      $ref = new \ReflectionClass($worker);
      $method = $ref->getMethod('consolidateAllParsedData');
      $method->setAccessible(TRUE);
      $method->invoke($worker, $uid);
    }
    catch (\Exception $e) {
      $this->logWarning('Auto-consolidation check failed for uid @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Helper to merge an array section by a key field.
   */
  private function mergeArraySection(array &$consolidated, string $section, array $newItems, string $keyField): int {
    $additions = 0;
    if (empty($consolidated[$section])) {
      $consolidated[$section] = [];
    }
    
    foreach ($newItems as $item) {
      // Handle both object-style and simple string arrays
      if (is_array($item)) {
        $key = $item[$keyField] ?? json_encode($item);
      } else {
        $key = $item;
      }
      
      $exists = false;
      foreach ($consolidated[$section] as $existing) {
        if (is_array($existing)) {
          $existingKey = $existing[$keyField] ?? json_encode($existing);
        } else {
          $existingKey = $existing;
        }
        if ($key === $existingKey) {
          $exists = true;
          break;
        }
      }
      
      if (!$exists) {
        $consolidated[$section][] = $item;
        $additions++;
      }
    }
    
    return $additions;
  }

  /**
   * Helper to merge experience sections (professional_experience, early_career).
   * Deduplicates by company + title combination.
   */
  private function mergeExperienceSection(array &$consolidated, string $section, array $newExperiences): int {
    $additions = 0;
    if (empty($consolidated[$section])) {
      $consolidated[$section] = [];
    }
    
    foreach ($newExperiences as $exp) {
      $company = $exp['company'] ?? $exp['organization'] ?? '';
      $title = $exp['title'] ?? $exp['role'] ?? '';
      $key = $company . '|' . $title;
      
      $exists = false;
      foreach ($consolidated[$section] as $existing) {
        $existingCompany = $existing['company'] ?? $existing['organization'] ?? '';
        $existingTitle = $existing['title'] ?? $existing['role'] ?? '';
        $existingKey = $existingCompany . '|' . $existingTitle;
        if ($key === $existingKey) {
          $exists = true;
          break;
        }
      }
      
      if (!$exists) {
        $consolidated[$section][] = $exp;
        $additions++;
      }
    }
    
    return $additions;
  }

  /**
   * Smart merge resume data into consolidated structure.
   * @deprecated Use mergeResumeDataV1 for schema v1.0 format.
   *
   * @param array &$consolidated
   *   The consolidated data structure (modified by reference).
   * @param array $new_data
   *   New parsed data from resume.
   */
  private function mergeResumeData(array &$consolidated, array $new_data) {
    // Legacy method - kept for backwards compatibility
    // Professional summaries - array of unique summaries
    if (!empty($new_data['professional_summary'])) {
      if (!isset($consolidated['professional_summary'])) {
        $consolidated['professional_summary'] = [];
      }
      if (!in_array($new_data['professional_summary'], $consolidated['professional_summary'])) {
        $consolidated['professional_summary'][] = $new_data['professional_summary'];
      }
    }
    
    // Skills - array of unique skills
    if (!empty($new_data['skills'])) {
      if (!isset($consolidated['skills'])) {
        $consolidated['skills'] = [];
      }
      $new_skills = array_map('trim', explode(',', $new_data['skills']));
      foreach ($new_skills as $skill) {
        if (!in_array($skill, $consolidated['skills'])) {
          $consolidated['skills'][] = $skill;
        }
      }
    }
    
    // Experience years - take maximum
    if (!empty($new_data['experience_years'])) {
      $consolidated['experience_years'] = max(
        $consolidated['experience_years'] ?? 0,
        (int) $new_data['experience_years']
      );
    }
    
    // Education level - take highest
    if (!empty($new_data['education_level'])) {
      $levels = ['high_school' => 1, 'associates' => 2, 'bachelors' => 3, 'masters' => 4, 'phd' => 5];
      $current_level = $levels[$consolidated['education_level'] ?? ''] ?? 0;
      $new_level = $levels[$new_data['education_level']] ?? 0;
      if ($new_level > $current_level) {
        $consolidated['education_level'] = $new_data['education_level'];
      }
    }
    
    // Certifications - array of unique certs
    if (!empty($new_data['certifications'])) {
      if (!isset($consolidated['certifications'])) {
        $consolidated['certifications'] = [];
      }
      $new_certs = array_map('trim', explode(',', $new_data['certifications']));
      foreach ($new_certs as $cert) {
        if (!in_array($cert, $consolidated['certifications'])) {
          $consolidated['certifications'][] = $cert;
        }
      }
    }
    
    // Job titles - array of unique titles
    if (!empty($new_data['job_titles'])) {
      if (!isset($consolidated['job_titles'])) {
        $consolidated['job_titles'] = [];
      }
      $new_titles = array_map('trim', explode(',', $new_data['job_titles']));
      foreach ($new_titles as $title) {
        if (!in_array($title, $consolidated['job_titles'])) {
          $consolidated['job_titles'][] = $title;
        }
      }
    }
  }

  /**
   * Apply consolidated JSON to profile fields (only if fields are empty).
   *
   * @param int $uid
   *   The user ID.
   * @param array $consolidated
   *   The consolidated data structure.
   */
  private function applyConsolidatedToProfileFields($uid, array $consolidated) {
    try {
      // Get current profile fields
      $profile = $this->userProfileRepository->getProfileFieldsForConsolidation((int) $uid);
      
      if (!$profile) {
        return;
      }
      
      $update_fields = [];
      
      // Professional summary - use first one if field is empty
      if (empty($profile['professional_summary']) && !empty($consolidated['professional_summary'])) {
        $update_fields['professional_summary'] = $consolidated['professional_summary'][0];
      }
      
      // Skills - join all unique skills if field is empty
      if (empty($profile['skills']) && !empty($consolidated['skills'])) {
        $update_fields['skills'] = implode(', ', $consolidated['skills']);
      }
      
      // Experience years - if field is empty
      if (empty($profile['experience_years']) && !empty($consolidated['experience_years'])) {
        $update_fields['experience_years'] = $consolidated['experience_years'];
      }
      
      // Education level - if field is empty
      if (empty($profile['education_level']) && !empty($consolidated['education_level'])) {
        $update_fields['education_level'] = $consolidated['education_level'];
      }
      
      // Certifications - format structured data one per line if field is empty
      if (empty($profile['certifications']) && !empty($consolidated['certifications'])) {
        $formatted = [];
        foreach ($consolidated['certifications'] as $cert) {
          if (is_array($cert)) {
            // Structured certification data
            $line = $cert['name'] ?? 'Unknown Certification';
            if (!empty($cert['issuing_organization'])) {
              $line .= ' - ' . $cert['issuing_organization'];
            }
            if (!empty($cert['issue_date'])) {
              // Extract year from date (format: YYYY-MM or YYYY)
              $year = substr($cert['issue_date'], 0, 4);
              $line .= ' (' . $year . ')';
            }
            $formatted[] = $line;
          } else {
            // Simple string certification
            $formatted[] = $cert;
          }
        }
        $update_fields['certifications'] = implode("\n", $formatted);
      }
      
      // Job titles - join all unique titles if field is empty
      if (empty($profile['job_titles']) && !empty($consolidated['job_titles'])) {
        $update_fields['job_titles'] = implode(', ', $consolidated['job_titles']);
      }
      
      // Apply updates if we have any
      if (!empty($update_fields)) {
        $update_fields['changed'] = time();
        $this->userProfileRepository->updateProfileFields((int) $uid, $update_fields);
        
        $fields_updated = implode(', ', array_keys($update_fields));
        $this->logInfo('✅ Applied consolidated data to profile for uid @uid: @fields', [
          '@uid' => $uid,
          '@fields' => $fields_updated,
        ]);
        
        \Drupal::messenger()->addStatus($this->t('Profile fields updated: @fields', [
          '@fields' => $fields_updated,
        ]));
      } else {
        $this->logInfo('ℹ️ No profile fields updated - all fields already populated');
      }
      
    } catch (\Exception $e) {
      $this->logError('Failed to apply consolidated data: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * STEP 6 Helper: Store parsed results in database.
   */
  private function storeParsedResults($uid, $file_id, $file_uri, $parsed_data, $filename) {
    $timestamp = \Drupal::time()->getRequestTime();
    $this->userProfileRepository->upsertParsedDataRecord(
      (int) $uid,
      (int) $file_id,
      $file_uri,
      json_encode($parsed_data),
      $timestamp
    );

    $this->logInfo('✅ STEP 6: Stored parsed data record for: @filename', [
      '@filename' => $filename,
    ]);
  }

  /**
   * Check if we're running in a development environment.
   *
   * @return bool
   *   TRUE if in development environment, FALSE if in production.
   */
  protected function isDevelopmentEnvironment(): bool {
    // Dev/prod both use Bedrock. Keep the method for backward compatibility
    // with older code paths, but never treat an environment as "mock".
    return FALSE;
  }

  /**
   * Generate mock resume data for development mode.
   *
   * @param string $filename
   *   The resume filename.
   *
   * @return array
   *   Mock parsed resume data.
   */
  protected function generateMockResumeData(string $filename): array {
    // Generate context-aware mock data based on filename
    $is_keith = (strpos(strtolower($filename), 'keith') !== FALSE);
    
    return [
      'schema_version' => '1.0',
      'contact_info' => [
        'full_name' => $is_keith ? 'Keith Aumiller' : 'John Doe',
        'email' => $is_keith ? 'keith@example.com' : 'john.doe@example.com',
        'phone' => '(555) 123-4567',
        'location' => $is_keith ? 'St. Louis, MO' : 'New York, NY',
        'linkedin_url' => 'https://linkedin.com/in/profile',
      ],
      'executive_profile' => [
        'summary' => 'Experienced professional with expertise in software development, project management, and team leadership. Proven track record of delivering high-quality solutions and driving business results.',
        'years_experience' => 10,
        'specializations' => ['Software Development', 'Project Management', 'Team Leadership'],
      ],
      'professional_experience' => [
        [
          'job_title' => 'Senior Software Engineer',
          'company_name' => 'Tech Company Inc',
          'location' => 'St. Louis, MO',
          'dates' => '2020-01 to Present',
          'responsibilities' => 'Lead development of enterprise applications using modern web technologies. Mentor junior developers and drive technical excellence.',
          'achievements' => [
            'Reduced system downtime by 40%',
            'Implemented CI/CD pipeline',
            'Led team of 5 developers',
          ],
        ],
        [
          'job_title' => 'Software Developer',
          'company_name' => 'Previous Corp',
          'location' => 'Chicago, IL',
          'dates' => '2017-06 to 2019-12',
          'responsibilities' => 'Developed and maintained web applications using PHP, JavaScript, and MySQL.',
          'achievements' => [
            'Built customer portal from scratch',
            'Improved page load times by 60%',
          ],
        ],
      ],
      'technical_expertise' => [
        'core_technical_skills' => ['PHP', 'JavaScript', 'Python', 'Java', 'MySQL', 'PostgreSQL', 'MongoDB'],
        'frameworks_and_libraries' => ['Drupal', 'React', 'Laravel', 'Symfony'],
        'tools_and_platforms' => ['Git', 'Docker', 'Jenkins', 'AWS'],
      ],
      'education' => [
        [
          'degree_type' => 'Bachelor of Science',
          'field_of_study' => 'Computer Science',
          'institution_name' => 'State University',
          'location' => 'Springfield, IL',
          'completion_date' => '2017',
          'honors' => 'Cum Laude',
        ],
      ],
      'certifications' => [
        [
          'certification_name' => 'AWS Certified Developer',
          'issuing_organization' => 'Amazon Web Services',
          'date_obtained' => '2022-03',
        ],
      ],
      'extraction_metadata' => [
        'extraction_date' => date('Y-m-d H:i:s'),
        'extraction_method' => 'development_mock',
        'source_files' => [$filename],
        'parser_version' => '1.0.0',
      ],
    ];
  }

  /**
   * AJAX callback for file upload.
   */
  public function fileUploadAjax(array &$form, FormStateInterface $form_state) {
    $response = new \Drupal\Core\Ajax\AjaxResponse();
    
    // Return the status container
    $response->addCommand(new \Drupal\Core\Ajax\ReplaceCommand(
      '#resume-import-status',
      '<div id="resume-import-status"><div class="messages messages--status">File uploaded successfully. Click "Parse Resume with AI" to analyze.</div></div>'
    ));
    
    return $response;
  }

  /**
   * AJAX callback to parse uploaded resume.
   */
  public function parseResumeAjax(array &$form, FormStateInterface $form_state) {
    $response = new \Drupal\Core\Ajax\AjaxResponse();
    
    // Get the uploaded file
    $file_id = $form_state->getValue(['resume_import', 'import_file', 0]);
    
    if (empty($file_id)) {
      $response->addCommand(new \Drupal\Core\Ajax\MessageCommand(
        $this->t('Please upload a resume file first.'),
        '#resume-import-status',
        ['type' => 'error']
      ));
      return $response;
    }

    $file = \Drupal\file\Entity\File::load($file_id);
    if (!$file) {
      $response->addCommand(new \Drupal\Core\Ajax\MessageCommand(
        $this->t('Could not load the uploaded file.'),
        '#resume-import-status',
        ['type' => 'error']
      ));
      return $response;
    }

    // Extract text from file
    $resume_text = $this->extractTextFromFile($file);
    
    if (empty($resume_text)) {
      $response->addCommand(new \Drupal\Core\Ajax\MessageCommand(
        $this->t('Could not extract text from the resume file.'),
        '#resume-import-status',
        ['type' => 'error']
      ));
      return $response;
    }

    // Parse resume with AI
    $parsed_data = $this->parseResumeWithAI($resume_text);
    
    if (empty($parsed_data)) {
      $response->addCommand(new \Drupal\Core\Ajax\MessageCommand(
        $this->t('Could not parse resume. Please try again or fill out the form manually.'),
        '#resume-import-status',
        ['type' => 'error']
      ));
      return $response;
    }

    // Fill form fields with parsed data
    $this->fillFormWithParsedData($form, $form_state, $parsed_data);

    // Rebuild the form with new values
    $response->addCommand(new \Drupal\Core\Ajax\ReplaceCommand(
      '#profile-form-wrapper',
      $form
    ));
    
    $response->addCommand(new \Drupal\Core\Ajax\MessageCommand(
      $this->t('Resume parsed successfully! Please review and adjust the auto-filled fields.'),
      '#resume-import-status',
      ['type' => 'status']
    ));

    return $response;
  }

  /**
   * Extract text from uploaded file.
   */
  protected function extractTextFromFile($file) {
    return $this->resumeUploadSubform->extractTextFromFile($file);
  }

  /**
   * Parse resume text with AI.
   */
  protected function parseResumeWithAI($resume_text) {
    if (!$this->aiApiService) {
      return NULL;
    }

    // Create the parsing prompt with JSON schema
    $prompt = "Please analyze the following resume and extract structured information. Return ONLY a valid JSON object with these exact fields (use null for missing information):\n\n";
    $prompt .= "{\n";
    $prompt .= '  "professional_summary": "string - 2-3 sentence professional summary",\n';
    $prompt .= '  "skills": "string - comma-separated list of technical skills",\n';
    $prompt .= '  "experience_years": number - total years of professional experience,\n';
    $prompt .= '  "education_level": "string - highest degree (high_school/associates/bachelors/masters/phd/other)",\n';
    $prompt .= '  "certifications": "string - comma-separated list of certifications",\n';
    $prompt .= '  "job_titles": "string - comma-separated list of desired job titles based on experience",\n';
    $prompt .= '  "linkedin_url": "string - LinkedIn URL if found",\n';
    $prompt .= '  "github_url": "string - GitHub URL if found",\n';
    $prompt .= '  "portfolio_url": "string - Portfolio URL if found"\n';
    $prompt .= "}\n\n";
    $prompt .= "Resume text:\n\n" . substr($resume_text, 0, 8000); // Limit to ~8000 chars
    $prompt .= "\n\nReturn ONLY the JSON object, no other text.";

    try {
      // Create a temporary conversation node for AI interaction
      $conversation = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'ai_conversation',
        'title' => 'Resume Parse - ' . date('Y-m-d H:i:s'),
        'uid' => $this->currentUser->id(),
        'status' => 0, // Unpublished
      ]);
      $conversation->save();

      // Send message to AI
      $response = $this->aiApiService->sendMessage($conversation, $prompt);
      
      // Clean up: delete temporary conversation
      $conversation->delete();

      if (empty($response['response'])) {
        return NULL;
      }

      // Extract JSON from response
      $json_text = $response['response'];
      
      // Try to find JSON in the response (in case AI added extra text)
      if (preg_match('/\{[\s\S]*\}/', $json_text, $matches)) {
        $json_text = $matches[0];
      }

      $parsed = json_decode($json_text, TRUE);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logError('Failed to parse AI response as JSON: @error. Response: @response', [
          '@error' => json_last_error_msg(),
          '@response' => substr($json_text, 0, 500),
        ]);
        return NULL;
      }

      return $parsed;
      
    } catch (\Exception $e) {
      $this->logError('Error parsing resume with AI: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Fill form fields with parsed resume data.
   */
  protected function fillFormWithParsedData(array &$form, FormStateInterface $form_state, array $data) {
    $field_mapping = [
      'professional_summary' => 'field_professional_summary',
      'skills' => 'field_skills_summary',
      'experience_years' => 'field_experience_years',
      'education_level' => 'field_education_level',
      'certifications' => 'field_certifications_json',
      'job_titles' => 'field_target_job_titles',
      'linkedin_url' => 'field_linkedin_url',
      'github_url' => 'field_github_url',
      'portfolio_url' => 'field_portfolio_url',
    ];

    foreach ($field_mapping as $parsed_key => $form_field) {
      if (!empty($data[$parsed_key])) {
        $value = $data[$parsed_key];
        
        // Set the form value
        $form_state->setValue($form_field, $value);
        
        // Update the form element's default value for display
        if (isset($form['core_info'][$form_field])) {
          $form['core_info'][$form_field]['#default_value'] = $value;
        } elseif (isset($form['employment_prefs'][$form_field])) {
          $form['employment_prefs'][$form_field]['#default_value'] = $value;
        } elseif (isset($form['online_presence'][$form_field])) {
          $form['online_presence'][$form_field]['#default_value'] = $value;
        } elseif (isset($form['additional_info'][$form_field])) {
          $form['additional_info'][$form_field]['#default_value'] = $value;
        }
      }
    }
  }

  /**
   * Build HTML display for education from consolidated JSON.
   *
   * @param object|null $job_seeker_profile
   *   The job seeker profile object.
   *
   * @return string
   *   HTML markup for education display.
   */
  private function buildEducationDisplay($job_seeker_profile): string {
    return $this->educationHistorySubform->buildEducationDisplay($job_seeker_profile);
  }

  /**
   * Build HTML display for contact info from consolidated JSON.
   *
   * @param object|null $job_seeker_profile
   *   The job seeker profile object.
   *
   * @return string
   *   HTML markup for contact info display.
   */
  private function buildContactInfoDisplay($job_seeker_profile): string {
    if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
      return '';
    }

    $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE);
    if (!$consolidated || empty($consolidated['contact_info'])) {
      return '';
    }

    $contact = $consolidated['contact_info'];
    $html = '<div class="contact-info-display jh-profile__contact-display">';
    
    // Name and headline
    if (!empty($contact['full_name'])) {
      $html .= '<h3 class="jh-profile__contact-name">' . htmlspecialchars($contact['full_name']);
      if (!empty($contact['credentials'])) {
        $creds = is_array($contact['credentials']) ? implode(', ', $contact['credentials']) : $contact['credentials'];
        $html .= ' <span class="jh-profile__contact-creds">(' . htmlspecialchars($creds) . ')</span>';
      }
      $html .= '</h3>';
    }
    
    if (!empty($contact['headline'])) {
      $html .= '<div class="jh-profile__contact-headline">' . htmlspecialchars($contact['headline']) . '</div>';
    }

    // Contact details grid
    $html .= '<div class="jh-profile__contact-grid">';
    
    if (!empty($contact['email'])) {
      $html .= '<div>📧 <a href="mailto:' . htmlspecialchars($contact['email']) . '">' . htmlspecialchars($contact['email']) . '</a></div>';
    }
    
    if (!empty($contact['phone'])) {
      $html .= '<div>📞 ' . htmlspecialchars($contact['phone']) . '</div>';
    }
    
    if (!empty($contact['location'])) {
      $loc = [];
      if (!empty($contact['location']['city'])) $loc[] = $contact['location']['city'];
      if (!empty($contact['location']['state'])) $loc[] = $contact['location']['state'];
      if (!empty($loc)) {
        $html .= '<div>📍 ' . htmlspecialchars(implode(', ', $loc)) . '</div>';
      }
    }

    $html .= '</div>';

    // Websites
    if (!empty($contact['websites'])) {
      $html .= '<div class="jh-profile__contact-section-title"><strong>Web Presence:</strong></div>';
      $html .= '<ul class="jh-profile__contact-list">';
      foreach ($contact['websites'] as $site) {
        $type = ucfirst($site['type'] ?? 'Website');
        $url = $site['url'] ?? '';
        if ($url) {
          $html .= '<li><strong>' . htmlspecialchars($type) . ':</strong> <a href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars($url) . '</a></li>';
        }
      }
      $html .= '</ul>';
    }

    // LinkedIn metadata
    if (!empty($contact['linkedin']['followers'])) {
      $html .= '<div class="jh-profile__contact-meta"><strong>LinkedIn Followers:</strong> ' . htmlspecialchars($contact['linkedin']['followers']) . '</div>';
    }

    // LinkedIn groups administered
    if (!empty($contact['linkedin']['groups_administered'])) {
      $html .= '<div class="jh-profile__contact-meta"><strong>Groups Administered:</strong> ';
      $html .= htmlspecialchars(implode(', ', $contact['linkedin']['groups_administered']));
      $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Build HTML display for strategic differentiators from consolidated JSON.
   *
   * @param object|null $job_seeker_profile
   *   The job seeker profile object.
   *
   * @return string
   *   HTML markup for strategic differentiators display.
   */
  private function buildStrategicDifferentiatorsDisplay($job_seeker_profile): string {
    if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
      return '';
    }

    $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE);
    if (!$consolidated || empty($consolidated['strategic_differentiators'])) {
      return '';
    }

    $html = '<div class="strategic-differentiators-display">';
    $html .= '<div class="jh-profile__diff-grid">';
    
    foreach ($consolidated['strategic_differentiators'] as $diff) {
      $title = htmlspecialchars($diff['title'] ?? '');
      $description = htmlspecialchars($diff['description'] ?? '');
      
      if ($title) {
        $html .= '<div class="jh-profile__diff-card">';
        $html .= '<h4 class="jh-profile__diff-title">🎯 ' . $title . '</h4>';
        $html .= '<p class="jh-profile__diff-description">' . $description . '</p>';
        $html .= '</div>';
      }
    }

    $html .= '</div></div>';
    return $html;
  }

  /**
   * Build HTML display for full technical expertise from consolidated JSON.
   *
   * @param object|null $job_seeker_profile
   *   The job seeker profile object.
   *
   * @return string
   *   HTML markup for technical expertise display.
   */
  private function buildTechnicalExpertiseDisplay($job_seeker_profile): string {
    if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
      return '';
    }

    $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE);
    if (!$consolidated || empty($consolidated['technical_expertise']['categories'])) {
      return '';
    }

    $html = '<div class="technical-expertise-display">';
    
    foreach ($consolidated['technical_expertise']['categories'] as $category) {
      $name = htmlspecialchars($category['name'] ?? 'Skills');
      
      $html .= '<div class="jh-profile__tech-category">';
      $html .= '<h4 class="jh-profile__tech-category-title">🛠️ ' . $name . '</h4>';
      
      // Regular skills
      if (!empty($category['skills'])) {
        $html .= '<div class="jh-profile__tech-skills">';
        foreach ($category['skills'] as $skill) {
          $html .= '<span class="jh-profile__tech-skill-chip">' . htmlspecialchars($skill) . '</span>';
        }
        $html .= '</div>';
      }
      
      // Subcategories (industry-specific)
      if (!empty($category['subcategories'])) {
        foreach ($category['subcategories'] as $subcat) {
          $industry = htmlspecialchars($subcat['industry'] ?? 'Specialized');
          $html .= '<div class="jh-profile__tech-subcategory">';
          $html .= '<strong class="jh-profile__tech-subcategory-title">' . $industry . ':</strong> ';
          if (!empty($subcat['skills'])) {
            $html .= '<span class="jh-profile__tech-subcategory-skills">' . htmlspecialchars(implode(', ', $subcat['skills'])) . '</span>';
          }
          $html .= '</div>';
        }
      }
      
      // Frameworks (regulatory)
      if (!empty($category['frameworks'])) {
        $html .= '<div class="jh-profile__tech-frameworks">';
        $html .= '<strong>Frameworks:</strong> ';
        foreach ($category['frameworks'] as $framework) {
          $html .= '<span class="jh-profile__tech-framework-chip">' . htmlspecialchars($framework) . '</span>';
        }
        $html .= '</div>';
      }

      $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Build HTML display for leadership philosophy from consolidated JSON.
   *
   * @param object|null $job_seeker_profile
   *   The job seeker profile object.
   *
   * @return string
   *   HTML markup for leadership philosophy display.
   */
  private function buildLeadershipPhilosophyDisplay($job_seeker_profile): string {
    if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
      return '';
    }

    $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE);
    
    $html = '';
    
    // Leadership philosophy
    if (!empty($consolidated['leadership_philosophy'])) {
      $lp = $consolidated['leadership_philosophy'];
      $html .= '<div class="jh-profile__leadership-card">';
      $html .= '<h4 class="jh-profile__leadership-title">🧭 Leadership Philosophy</h4>';
      
      if (is_array($lp)) {
        foreach ($lp as $item) {
          if (is_string($item)) {
            $html .= '<p class="jh-profile__leadership-text">' . htmlspecialchars($item) . '</p>';
          } elseif (is_array($item)) {
            // Influences or key themes
            $html .= '<div class="jh-profile__leadership-elements"><strong>Key Elements:</strong> ';
            $html .= '<span class="jh-profile__leadership-elements-list">' . htmlspecialchars(implode(', ', $item)) . '</span></div>';
          }
        }
      } else {
        $html .= '<p class="jh-profile__leadership-text">' . htmlspecialchars($lp) . '</p>';
      }
      $html .= '</div>';
    }
    
    // Organizational philosophy
    if (!empty($consolidated['organizational_philosophy'])) {
      $op = $consolidated['organizational_philosophy'];
      $html .= '<div class="jh-profile__org-philosophy-card">';
      $html .= '<h4 class="jh-profile__org-philosophy-title">🏢 Organizational Philosophy</h4>';
      
      if (is_array($op)) {
        foreach ($op as $item) {
          if (is_string($item)) {
            $html .= '<p class="jh-profile__leadership-text">' . htmlspecialchars($item) . '</p>';
          }
        }
      } else {
        $html .= '<p class="jh-profile__leadership-text">' . htmlspecialchars($op) . '</p>';
      }
      $html .= '</div>';
    }

    return $html;
  }

  /**
   * Build HTML display for demonstration projects from consolidated JSON.
   *
   * @param object|null $job_seeker_profile
   *   The job seeker profile object.
   *
   * @return string
   *   HTML markup for demonstration projects display.
   */
  private function buildDemonstrationProjectsDisplay($job_seeker_profile): string {
    if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
      return '';
    }

    $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE);
    if (!$consolidated || empty($consolidated['demonstration_projects'])) {
      return '';
    }

    $html = '<div class="demonstration-projects-display">';
    
    foreach ($consolidated['demonstration_projects'] as $project) {
      $name = htmlspecialchars($project['name'] ?? 'Project');
      $url = $project['url'] ?? '';
      $description = htmlspecialchars($project['description'] ?? '');
      $technologies = $project['technologies'] ?? [];

      $html .= '<div class="jh-profile__demo-card">';
      $html .= '<h4 class="jh-profile__demo-title">🚀 ' . $name . '</h4>';
      
      if ($url) {
        $html .= '<div class="jh-profile__demo-url"><a class="jh-profile__demo-link" href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars($url) . '</a></div>';
      }
      
      if ($description) {
        $html .= '<p class="jh-profile__demo-description">' . $description . '</p>';
      }
      
      if (!empty($technologies)) {
        $html .= '<div class="jh-profile__demo-tech-list">';
        foreach ($technologies as $tech) {
          $html .= '<span class="jh-profile__demo-tech-chip">' . htmlspecialchars($tech) . '</span>';
        }
        $html .= '</div>';
      }

      $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Build HTML display for consulting practice from consolidated JSON.
   *
   * @param object|null $job_seeker_profile
   *   The job seeker profile object.
   *
   * @return string
   *   HTML markup for consulting practice display.
   */
  private function buildConsultingPracticeDisplay($job_seeker_profile): string {
    if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
      return '';
    }

    $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE);
    if (!$consolidated || empty($consolidated['consulting_practice'])) {
      return '';
    }

    $cp = $consolidated['consulting_practice'];
    
    // Handle if it's an empty array
    if (is_array($cp) && empty($cp)) {
      return '';
    }
    
    // Handle if it's an array of practices
    if (is_array($cp) && isset($cp[0])) {
      $practices = $cp;
    } else {
      $practices = [$cp];
    }

    $html = '<div class="consulting-practice-display">';
    
    foreach ($practices as $practice) {
      if (!is_array($practice) || empty($practice)) continue;
      
      $company = htmlspecialchars($practice['company'] ?? '');
      $title = htmlspecialchars($practice['title'] ?? '');
      $location = htmlspecialchars($practice['location'] ?? '');
      $start = $practice['start_date'] ?? '';
      $end = $practice['end_date'] ?? 'Present';
      $website = $practice['website'] ?? '';
      $description = htmlspecialchars($practice['description'] ?? '');
      $engagements = $practice['notable_engagements'] ?? [];

      $html .= '<div class="jh-profile__consult-card">';
      
      if ($title) {
        $html .= '<h4 class="jh-profile__consult-title">' . $title . '</h4>';
      }
      if ($company) {
        $html .= '<div class="jh-profile__consult-company">' . $company . '</div>';
      }
      
      $meta = [];
      if ($location) $meta[] = $location;
      if ($start) $meta[] = $start . ' – ' . $end;
      if (!empty($meta)) {
        $html .= '<div class="jh-profile__consult-meta">' . implode(' | ', $meta) . '</div>';
      }
      
      if ($website) {
        $html .= '<div class="jh-profile__consult-website"><a href="' . htmlspecialchars($website) . '" target="_blank">' . htmlspecialchars($website) . '</a></div>';
      }
      
      if ($description) {
        $html .= '<p class="jh-profile__consult-description">' . $description . '</p>';
      }
      
      if (!empty($engagements)) {
        $html .= '<div class="jh-profile__consult-engagement-title"><strong>Notable Engagements:</strong></div>';
        $html .= '<ul class="jh-profile__consult-engagement-list">';
        foreach ($engagements as $eng) {
          $client = htmlspecialchars($eng['client'] ?? '');
          $role = htmlspecialchars($eng['role'] ?? '');
          $desc = htmlspecialchars($eng['description'] ?? '');
          $html .= '<li><strong>' . $client . '</strong>';
          if ($role) $html .= ' - ' . $role;
          if ($desc) $html .= '<br><span class="jh-profile__consult-engagement-desc">' . $desc . '</span>';
          $html .= '</li>';
        }
        $html .= '</ul>';
      }

      $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Build HTML display for early career from consolidated JSON.
   *
   * @param object|null $job_seeker_profile
   *   The job seeker profile object.
   *
   * @return string
   *   HTML markup for early career display.
   */
  private function buildEarlyCareerDisplay($job_seeker_profile): string {
    if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
      return '';
    }

    $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE);
    if (!$consolidated || empty($consolidated['early_career'])) {
      return '';
    }

    $ec = $consolidated['early_career'];
    
    $html = '<div class="early-career-display jh-profile__early-career">';
    $html .= '<h4 class="jh-profile__early-title">📜 Early Career</h4>';
    
    // Handle different formats
    if (is_array($ec)) {
      // Check if it's a structured object or simple array
      if (isset($ec['period']) || isset($ec['summary']) || isset($ec['positions'])) {
        // Structured format
        if (!empty($ec['period'])) {
          $html .= '<div class="jh-profile__early-period">Period: ' . htmlspecialchars($ec['period']) . '</div>';
        }
        if (!empty($ec['summary'])) {
          $html .= '<p class="jh-profile__early-summary">' . htmlspecialchars($ec['summary']) . '</p>';
        }
        if (!empty($ec['positions'])) {
          $html .= '<div class="jh-profile__early-positions-title"><strong>Positions:</strong></div>';
          $html .= '<ul class="jh-profile__early-list">';
          foreach ($ec['positions'] as $pos) {
            $company = htmlspecialchars($pos['company'] ?? '');
            $duration = htmlspecialchars($pos['duration'] ?? '');
            $focus = htmlspecialchars($pos['focus'] ?? '');
            $html .= '<li><strong>' . $company . '</strong>';
            if ($duration) $html .= ' (' . $duration . ')';
            if ($focus) $html .= '<br><span class="jh-profile__early-focus">' . $focus . '</span>';
            $html .= '</li>';
          }
          $html .= '</ul>';
        }
      } else {
        // Simple array format (e.g., ["2000-2011"])
        foreach ($ec as $item) {
          if (is_string($item)) {
            $html .= '<div class="jh-profile__early-item">' . htmlspecialchars($item) . '</div>';
          }
        }
      }
    } else {
      $html .= '<div class="jh-profile__early-item">' . htmlspecialchars($ec) . '</div>';
    }

    $html .= '</div>';
    return $html;
  }

}
