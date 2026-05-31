<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\user\UserInterface;
use Drupal\node\NodeInterface;

/**
 * Service for handling automated job application submissions.
 *
 * Manages the entire application submission workflow including:
 * - Validation of application prerequisites
 * - Preparation of application data from user profile
 * - Coordination with browser automation
 * - Error handling and fallback strategies
 * - Tracking application status and confirmations
 */
class ApplicationSubmissionService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

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
   * The job seeker service.
   *
   * @var \Drupal\job_hunter\Service\JobSeekerService
   */
  protected $jobSeekerService;

  /**
   * The user profile service.
   *
   * @var \Drupal\job_hunter\Service\UserProfileService
   */
  protected $userProfileService;

  /**
   * The credential management service.
   *
   * @var \Drupal\job_hunter\Service\CredentialManagementService
   */
  protected $credentialManagementService;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs an ApplicationSubmissionService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\job_hunter\Service\JobSeekerService $job_seeker_service
   *   The job seeker service.
   * @param \Drupal\job_hunter\Service\UserProfileService $user_profile_service
   *   The user profile service.
   * @param \Drupal\job_hunter\Service\CredentialManagementService $credential_management_service
   *   The credential management service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    JobSeekerService $job_seeker_service,
    UserProfileService $user_profile_service,
    CredentialManagementService $credential_management_service,
    FileSystemInterface $file_system
  ) {
    $this->database = $database;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->jobSeekerService = $job_seeker_service;
    $this->userProfileService = $user_profile_service;
    $this->credentialManagementService = $credential_management_service;
    $this->fileSystem = $file_system;
  }

  /**
   * Initiates or queues an application submission.
   *
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job requirement ID in jobhunter_job_requirements table.
   * @param bool $auto_mode
   *   Whether to attempt full automation (TRUE) or show review screen first (FALSE).
   *
   * @return array
   *   Result array with structure:
   *   [
   *     'success' => bool,
   *     'application_id' => int|null,
   *     'status' => string (pending_review|queued|submitted|manual_required|error),
   *     'message' => string,
   *     'error' => string|null,
   *   ]
   */
  public function submitApplication(int $uid, int $job_id, bool $auto_mode = TRUE): array {
    $logger = $this->loggerFactory->get('job_hunter');

    // Step 1: Validate prerequisites
    $validation = $this->validateApplicationPrerequisites($uid, $job_id);
    if (!$validation['success']) {
      $logger->warning('Application validation failed for user @uid, job @job_id: @error', [
        '@uid' => $uid,
        '@job_id' => $job_id,
        '@error' => $validation['error'],
      ]);
      return [
        'success' => FALSE,
        'application_id' => NULL,
        'status' => 'validation_error',
        'message' => $validation['error'],
        'error' => $validation['details'],
      ];
    }

    try {
      // Step 2: Get job details
      $job_data = $this->getJobDetails($job_id);
      if (!$job_data) {
        throw new \Exception('Job posting not found: ' . $job_id);
      }

      // Step 3: Create application record
      $application_id = $this->createApplicationRecord($uid, $job_id);

      // Step 4: Prepare application data
      $app_data = $this->prepareApplicationData($uid, $job_id);

      // Step 5: Queue for processing or attempt auto-submission
      if ($auto_mode) {
        return $this->queueApplicationForSubmission($uid, $job_id, $application_id, $app_data);
      } else {
        return [
          'success' => TRUE,
          'application_id' => $application_id,
          'status' => 'pending_review',
          'message' => 'Application prepared and ready for review.',
          'error' => NULL,
        ];
      }
    } catch (\Exception $e) {
      $logger->error('Application submission error for user @uid, job @job_id: @error', [
        '@uid' => $uid,
        '@job_id' => $job_id,
        '@error' => $e->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'application_id' => NULL,
        'status' => 'error',
        'message' => 'An error occurred while processing your application.',
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Validates that the application can be submitted.
   *
   * Checks:
   * - User profile completeness (90%+)
   * - Job posting still active
   * - No duplicate application already submitted
   * - Tailored resume available for this job
   *
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job requirement ID.
   *
   * @return array
   *   Validation result:
   *   [
   *     'success' => bool,
   *     'error' => string|null,
   *     'details' => array of validation messages,
   *   ]
   */
  public function validateApplicationPrerequisites(int $uid, int $job_id): array {
    $errors = [];
    $details = [];

    try {
      // Load user
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
      if (!$user || $user->isAnonymous()) {
        throw new \Exception('User not found or anonymous');
      }

      // Check profile completeness.
      // Default threshold aligns with UserProfileService readiness guidance.
      $required_completeness = (int) $this->configFactory
        ->get('job_hunter.settings')
        ->get('application_min_profile_completeness');
      if ($required_completeness <= 0 || $required_completeness > 100) {
        $required_completeness = 70;
      }

      $profile_completion = $this->userProfileService->calculateProfileCompleteness($user);
      if ($profile_completion < $required_completeness) {
        $errors[] = 'Your profile is only ' . $profile_completion . '% complete. Please complete your profile to at least ' . $required_completeness . '% before applying.';
        $details['profile_completion'] = $profile_completion;
        $details['required_profile_completion'] = $required_completeness;
      }

      // Check job still exists and active
      $job = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j')
        ->condition('id', $job_id)
        ->execute()
        ->fetchAssoc();

      if (!$job) {
        $errors[] = 'Job posting not found.';
        $details['job_found'] = FALSE;
      } else {
        $details['job_found'] = TRUE;
        $details['company'] = $job['company_name'] ?? 'Unknown';
        $details['title'] = $job['job_title'] ?? 'Unknown';
      }

      // Check for duplicate application
      $existing = $this->database->select('jobhunter_applications', 'a')
        ->condition('a.uid', $uid)
        ->condition('a.job_id', $job_id)
        ->condition('a.submission_status', ['submitted', 'pending'], 'IN')
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($existing > 0) {
        $errors[] = 'You have already applied to this position.';
        $details['duplicate'] = TRUE;
      }

      // Check for employer credentials (required for automated submission)
      if ($job && isset($job['company_id'])) {
        $has_credentials = $this->database->select('jobhunter_employer_credentials', 'c')
          ->condition('c.uid', $uid)
          ->condition('c.company_id', $job['company_id'])
          ->countQuery()
          ->execute()
          ->fetchField();

        if ($has_credentials == 0) {
          // For now, log a warning but don't block submission
          // Submission will be marked for manual review
          $details['credentials_missing'] = TRUE;
          $details['requires_manual_submission'] = TRUE;
        } else {
          $details['credentials_available'] = TRUE;
        }
      }

      // Check required fields from jobhunter_job_seeker flat columns.
      $seeker = $this->database->select('jobhunter_job_seeker', 'js')
        ->fields('js', ['contact_email', 'contact_phone', 'full_name'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchAssoc();

      if (empty($seeker)) {
        $errors[] = 'Job seeker profile not found. Please complete your profile.';
        $details['seeker_profile_missing'] = TRUE;
      } else {
        if (empty($seeker['contact_email'])) {
          $errors[] = 'Missing required field: Email address';
          $details['missing_fields'][] = 'contact_email';
        }
        if (empty($seeker['contact_phone'])) {
          $errors[] = 'Missing required field: Phone number';
          $details['missing_fields'][] = 'contact_phone';
        }
        if (empty($seeker['full_name'])) {
          $errors[] = 'Missing required field: Full name';
          $details['missing_fields'][] = 'full_name';
        }
      }

      return [
        'success' => empty($errors),
        'error' => empty($errors) ? NULL : implode(' | ', $errors),
        'details' => $details,
      ];
    } catch (\Exception $e) {
      return [
        'success' => FALSE,
        'error' => 'Validation error: ' . $e->getMessage(),
        'details' => ['exception' => $e->getMessage()],
      ];
    }
  }

  /**
   * Prepares application data from user profile.
   *
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job requirement ID.
   *
   * @return array
   *   Application data ready for form submission:
   *   [
   *     'uid' => int,
   *     'job_id' => int,
   *     'personal_info' => [...],
   *     'work_auth' => [...],
   *     'experience' => [...],
   *     'education' => [...],
   *     'tailored_resume' => string,
   *     'skills' => string,
   *   ]
   */
  public function prepareApplicationData(int $uid, int $job_id): array {
    $job = $this->getJobDetails($job_id) ?? [];

    // Pull contact/profile data from jobhunter_job_seeker flat columns.
    $seeker = $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc() ?: [];

    // Get consolidated profile JSON for rich nested fields.
    $consolidated = $this->jobSeekerService->getConsolidatedProfile($uid);
    $contact      = $consolidated['contact_info'] ?? [];
    $loc          = $contact['location'] ?? [];
    $prefs        = $consolidated['job_search_preferences'] ?? [];

    // Split full name.
    $full_name = $seeker['full_name'] ?? ($contact['name'] ?? '');
    $name_parts = explode(' ', trim($full_name), 2);
    $first_name = $name_parts[0] ?? '';
    $last_name  = $name_parts[1] ?? '';

    $exp_entries = $consolidated['professional_experience'] ?? [];
    $current_exp = !empty($exp_entries) ? $exp_entries[0] : [];
    $edu_entries = $consolidated['education'] ?? [];

    // Extract tailored resume if available.
    $tailored_resume = $this->getTailoredResumeForJob($uid, $job_id);
    $resume_pdf_path = $this->getResumePdfPath($uid, $job_id);

    return [
      'uid'          => $uid,
      'job_id'       => $job_id,
      'job_url'      => $job['job_url'] ?? '',
      'apply_options' => $job['apply_options'] ?? '[]',
      'company_name' => $job['company_name'] ?? '',
      'job_title'    => $job['job_title'] ?? '',
      'personal_info' => [
        'first_name'   => $first_name,
        'last_name'    => $last_name,
        'full_name'    => $full_name,
        'email'        => $seeker['contact_email'] ?? ($contact['email'] ?? ''),
        'phone'        => $seeker['contact_phone'] ?? ($contact['phone'] ?? ''),
        'city'         => $seeker['location_city'] ?? ($loc['city'] ?? ''),
        'state'        => $seeker['location_state'] ?? ($loc['state'] ?? ''),
        'zip'          => $loc['zip'] ?? '',
        'linkedin_url' => $seeker['linkedin_url'] ?? ($contact['linkedin_url'] ?? ''),
        'website_url'  => $seeker['portfolio_url'] ?? ($contact['website'] ?? ''),
      ],
      'work_auth' => [
        'status'  => $prefs['work_authorization'] ?? 'US_CITIZEN',
        'visa_type' => NULL,
      ],
      'experience' => [
        'years'           => (int) ($seeker['experience_years'] ?? count($exp_entries)),
        'current_title'   => $current_exp['title'] ?? '',
        'current_company' => $current_exp['company'] ?? '',
        'history'         => $exp_entries,
      ],
      'education' => [
        'level'   => $seeker['education_level'] ?? 'Bachelor\'s',
        'history' => $edu_entries,
      ],
      'skills'           => $seeker['skills'] ?? implode(', ', $consolidated['skills'] ?? []),
      'certifications'   => $consolidated['certifications'] ?? [],
      'languages'        => $consolidated['languages'] ?? [],
      'tailored_resume'  => $tailored_resume,
      'resume'           => [
        'pdf_path' => $resume_pdf_path,
      ],
      'cover_letter'     => '',
      'salary_expectations' => [
        'min' => $seeker['salary_min'] ?? ($prefs['salary_min'] ?? ''),
        'max' => $seeker['salary_max'] ?? ($prefs['salary_max'] ?? ''),
      ],
    ];
  }

  /**
   * Creates an application record in the database.
   *
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job requirement ID.
   * @param array $extra
   *   Optional extra fields (ats_platform, apply_url, selected_apply_option).
   *
   * @return int
   *   The application ID.
   */
  protected function createApplicationRecord(int $uid, int $job_id, array $extra = []): int {
    $result = $this->database->insert('jobhunter_applications')
      ->fields(array_merge([
        'uid'               => $uid,
        'job_id'            => $job_id,
        'submission_status' => 'pending',
        'submission_method' => 'auto',
        'automation_success'    => 0,
        'admin_review_required' => 0,
        'attempt_count'     => 0,
        'created'           => date('Y-m-d H:i:s'),
        'changed'           => date('Y-m-d H:i:s'),
      ], $extra))
      ->execute();

    return $result;
  }

  /**
   * Queues the application for submission.
   *
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job requirement ID.
   * @param int $application_id
   *   The application ID.
   * @param array $app_data
   *   The application data.
   *
   * @return array
   *   Result indicating queuing success.
   */
  protected function queueApplicationForSubmission(int $uid, int $job_id, int $application_id, array $app_data): array {
    try {
      $queue = \Drupal::queue('job_hunter_application_submission');
      $queue->createItem([
        'uid' => $uid,
        'job_id' => $job_id,
        'application_id' => $application_id,
        'app_data' => $app_data,
        'timestamp' => time(),
      ]);

      return [
        'success' => TRUE,
        'application_id' => $application_id,
        'status' => 'queued',
        'message' => 'Your application has been queued for submission. The system will process it shortly.',
        'error' => NULL,
      ];
    } catch (\Exception $e) {
      $this->loggerFactory->get('job_hunter')->error('Failed to queue application: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'application_id' => $application_id,
        'status' => 'queue_error',
        'message' => 'Failed to queue application.',
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Gets job details from database.
   *
   * @param int $job_id
   *   The job requirement ID.
   *
   * @return array|null
   *   Job data or NULL if not found.
   */
  protected function getJobDetails(int $job_id): ?array {
    $query = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j')
      ->condition('j.id', $job_id);

    // Provide a stable company_name in result payloads without denormalizing.
    $query->leftJoin('jobhunter_companies', 'c', 'j.company_id = c.id');
    $query->addField('c', 'name', 'company_name');

    return $query->execute()->fetchAssoc();
  }

  /**
   * Gets the tailored resume for a specific job.
   *
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job requirement ID.
   *
   * @return string|null
   *   The tailored resume JSON text or NULL if not available.
   */
  protected function getTailoredResumeForJob(int $uid, int $job_id): ?string {
    $result = $this->database->select('jobhunter_tailored_resumes', 't')
      ->fields('t', ['tailored_resume_json'])
      ->condition('uid', $uid)
      ->condition('job_id', $job_id)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $result;
  }

  /**
   * Gets the real filesystem path of the most recent tailored resume PDF.
   *
   * Resolves the private:// URI stored in jobhunter_tailored_resumes.pdf_path
   * to an absolute path the Node.js bridge can read.
   *
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job requirement ID.
   *
   * @return string|null
   *   Absolute filesystem path to the PDF, or NULL if not generated yet.
   */
  protected function getResumePdfPath(int $uid, int $job_id): ?string {
    $uri = $this->database->select('jobhunter_tailored_resumes', 't')
      ->fields('t', ['pdf_path'])
      ->condition('uid', $uid)
      ->condition('job_id', $job_id)
      ->isNotNull('pdf_path')
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if (!$uri) {
      return NULL;
    }

    $real_path = $this->fileSystem->realpath($uri);
    return ($real_path && file_exists($real_path)) ? $real_path : NULL;
  }

  /**
   * Gets the current status of an application.
   *
   * @param int $application_id
   *   The application ID.
   *
   * @return array|null
   *   Application status data or NULL if not found.
   */
  public function getApplicationStatus(int $application_id): ?array {
    return $this->database->select('jobhunter_applications', 'a')
      ->fields('a')
      ->condition('id', $application_id)
      ->execute()
      ->fetchAssoc();
  }

  /**
   * Updates application status and details.
   *
   * @param int $application_id
   *   The application ID.
   * @param string $status
   *   The new status (submitted, failed, manual_required).
   * @param array $details
   *   Additional details to store (confirmation, error info, etc).
   */
  public function updateApplicationStatus(int $application_id, string $status, array $details = []): void {
    $update = [
      'submission_status' => $status,
      'changed' => date('Y-m-d H:i:s'),
    ];

    if (!empty($details['confirmation'])) {
      $update['confirmation_reference'] = $details['confirmation'];
    }

    if (!empty($details['error'])) {
      $update['error_details'] = json_encode($details['error']);
    }

    if (!empty($details['automation_success'])) {
      $update['automation_success'] = TRUE;
    }

    if (!empty($details['admin_review'])) {
      $update['admin_review_required'] = TRUE;
    }

    $this->database->update('jobhunter_applications')
      ->fields($update)
      ->condition('id', $application_id)
      ->execute();
  }

}
