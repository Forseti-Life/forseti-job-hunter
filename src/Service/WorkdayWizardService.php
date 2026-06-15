<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;

/**
 * Advances through Workday application wizard steps (2-7) via Playwright.
 *
 * Wraps playwright/workday-wizard-advance.js which handles:
 *   - My Information (verify/fill personal info)
 *   - My Experience (verify resume-parsed data)
 *   - Application Questions (screenshot + common Q&A)
 *   - Voluntary Disclosures (EEO)
 *   - Self-Identify (disability)
 *   - Review & Submit (click Submit)
 *
 * Follows the same subprocess pattern as ResumeUploadService:
 *   temp payload file (0600) → proc_open → output file → hard timeout cap.
 */
class WorkdayWizardService {

  protected Connection $database;
  protected FileSystemInterface $fileSystem;
  protected WorkdayProfileDataMapper $profileDataMapper;
  protected WorkdayPlaywrightRunner $playwrightRunner;

  public function __construct(Connection $database, FileSystemInterface $file_system, WorkdayProfileDataMapper $profile_data_mapper, WorkdayPlaywrightRunner $playwright_runner) {
    $this->database = $database;
    $this->fileSystem = $file_system;
    $this->profileDataMapper = $profile_data_mapper;
    $this->playwrightRunner = $playwright_runner;
  }

  // ── Valid step keys ─────────────────────────────────────────────────────────

  private const VALID_STEPS = [
    'my_information',
    'my_experience',
    'application_questions',
    'voluntary_disclosures',
    'self_identify',
    'review_submit',
  ];

  // ── Public API ──────────────────────────────────────────────────────────────

  /**
   * Advance a specific Workday wizard step for a job application.
   *
   * @param int    $job_id
   *   The jobhunter_job_requirements.id.
   * @param int    $uid
   *   The Drupal user ID.
   * @param string $step_key
   *   One of: my_information, my_experience, application_questions,
   *           voluntary_disclosures, self_identify, review_submit.
   * @param array  $options
   *   - timeout (int) — total seconds; default 120.
   *
   * @return array{
   *   ok: bool,
   *   target_step: string,
   *   detected_page: string,
   *   page_matched: bool,
   *   fields_filled: array,
   *   fields_skipped: array,
   *   continue_clicked: bool,
   *   post_continue_url: string,
   *   page_title: string,
   *   needs_manual_review: bool,
   *   evidence: string,
   *   screenshots: array,
   *   error: string,
   * }
   */
  public function advanceStep(int $job_id, int $uid, string $step_key, array $options = []): array {
    $timeout = (int) ($options['timeout'] ?? 120);
    $apply_url_override = trim((string) ($options['apply_url'] ?? ''));
      $prevent_submit = !empty($options['prevent_submit']);
      $review_submit_mode = trim((string) ($options['review_submit_mode'] ?? ''));
      if ($review_submit_mode === '' && !empty($options['save_and_continue_later'])) {
        $review_submit_mode = 'save_and_continue_later';
      }

    $blank = [
      'ok'                  => FALSE,
      'target_step'         => $step_key,
      'detected_page'       => '',
      'page_matched'        => FALSE,
      'fields_filled'       => [],
      'fields_skipped'      => [],
      'continue_clicked'    => FALSE,
      'post_continue_url'   => '',
      'page_title'          => '',
      'needs_manual_review' => FALSE,
      'evidence'            => '',
      'screenshots'         => [],
      'visual_confirmation' => [],
      'submit_blocked'      => FALSE,
      'review_submit_mode'  => '',
      'error'               => '',
    ];

    if (!in_array($step_key, self::VALID_STEPS, TRUE)) {
      return array_merge($blank, ['error' => "Invalid step key: $step_key"]);
    }

    $context = $this->buildRunContext($job_id, $uid, $apply_url_override, ['id', 'apply_url', 'ats_platform', 'metadata']);
    if (empty($context['ok'])) {
      return array_merge($blank, ['error' => (string) ($context['error'] ?? 'Failed to prepare execution context.')]);
    }

    $application = (array) $context['application'];
    $credential = (array) $context['credential'];
    $payload = [
      'username'       => (string) $credential['username'],
      'password'       => (string) $credential['password'],
      'apply_url'      => (string) $context['apply_url'],
      'target_step'    => $step_key,
      'profile_data'   => (array) $context['profile_data'],
      'resume_pdf_path'=> (string) $context['resume_pdf_path'],
      'screenshot_dir' => (string) $context['screenshot_dir'],
      'application_id' => (int) $application['id'],
      'prevent_submit' => $prevent_submit,
      'review_submit_mode' => $review_submit_mode,
    ];

    $payload_file = $this->createPayloadFile($payload);
    if ($payload_file === NULL) {
      return array_merge($blank, ['error' => 'Failed to create payload file for wizard script.']);
    }

    // ── Run the Node script ───────────────────────────────────────────────
    $result = $this->playwrightRunner->runWizardPayload($payload_file, $timeout, $step_key);

    // Clean up payload file if script didn't delete it.
    if (file_exists($payload_file)) {
      @unlink($payload_file);
    }

    if ($result === NULL) {
      return array_merge($blank, ['error' => 'Node script could not be launched or returned invalid output.']);
    }

    return array_merge($blank, [
      'ok'                  => !empty($result['ok']),
      'target_step'         => (string) ($result['target_step'] ?? $step_key),
      'detected_page'       => (string) ($result['detected_page'] ?? ''),
      'page_matched'        => !empty($result['page_matched']),
      'fields_filled'       => (array) ($result['fields_filled'] ?? []),
      'fields_skipped'      => (array) ($result['fields_skipped'] ?? []),
      'continue_clicked'    => !empty($result['continue_clicked']),
      'post_continue_url'   => (string) ($result['post_continue_url'] ?? ''),
      'page_title'          => (string) ($result['page_title'] ?? ''),
      'needs_manual_review' => !empty($result['needs_manual_review']),
      'evidence'            => (string) ($result['evidence'] ?? ''),
      'screenshots'         => (array) ($result['screenshots'] ?? []),
      'visual_confirmation' => (array) ($result['visual_confirmation'] ?? []),
      'submit_blocked'      => !empty($result['submit_blocked']),
      'review_submit_mode'  => (string) ($result['review_submit_mode'] ?? ''),
      'error'               => (string) ($result['error'] ?? ''),
    ]);
  }

  /**
   * Advance remaining Workday wizard steps in a single browser session.
   *
   * @param int $job_id
   *   The jobhunter_job_requirements.id.
   * @param int $uid
   *   The Drupal user ID.
   * @param string $start_step
   *   First step key to run in sequence.
   * @param array $options
   *   - timeout (int): total seconds, default 220.
   *   - apply_url (string): optional URL override.
   *
   * @return array
   *   Script result including step_results and completed_steps.
   */
  public function advanceWizardAutoSingleSession(int $job_id, int $uid, string $start_step = 'my_information', array $options = []): array {
    $timeout = (int) ($options['timeout'] ?? 220);
    $apply_url_override = trim((string) ($options['apply_url'] ?? ''));
      $prevent_submit = !empty($options['prevent_submit']);
      $review_submit_mode = trim((string) ($options['review_submit_mode'] ?? ''));
      if ($review_submit_mode === '' && !empty($options['save_and_continue_later'])) {
        $review_submit_mode = 'save_and_continue_later';
      }

    $blank = [
      'ok' => FALSE,
      'target_step' => 'wizard_auto',
      'completed_steps' => [],
      'step_results' => [],
      'post_continue_url' => '',
      'visual_confirmation' => [],
      'submit_blocked' => FALSE,
      'review_submit_mode' => '',
      'error' => '',
    ];

    if (!in_array($start_step, self::VALID_STEPS, TRUE)) {
      return array_merge($blank, ['error' => "Invalid start step: $start_step"]);
    }

    $context = $this->buildRunContext($job_id, $uid, $apply_url_override, ['id', 'apply_url', 'metadata']);
    if (empty($context['ok'])) {
      return array_merge($blank, ['error' => (string) ($context['error'] ?? 'Failed to prepare execution context.')]);
    }

    $application = (array) $context['application'];
    $credential = (array) $context['credential'];
    $payload = [
      'username'       => (string) $credential['username'],
      'password'       => (string) $credential['password'],
      'apply_url'      => (string) $context['apply_url'],
      'target_step'    => 'wizard_validate',
      'start_step'     => $start_step,
      'profile_data'   => (array) $context['profile_data'],
      'resume_pdf_path'=> (string) $context['resume_pdf_path'],
      'screenshot_dir' => (string) $context['screenshot_dir'],
      'application_id' => (int) $application['id'],
      'prevent_submit' => $prevent_submit,
      'review_submit_mode' => $review_submit_mode,
    ];

    $payload_file = $this->createPayloadFile($payload);
    if ($payload_file === NULL) {
      return array_merge($blank, ['error' => 'Failed to create payload file for wizard script.']);
    }

    $result = $this->playwrightRunner->runWizardPayload($payload_file, $timeout, 'wizard_auto');

    if (file_exists($payload_file)) {
      @unlink($payload_file);
    }

    if ($result === NULL) {
      return array_merge($blank, ['error' => 'Node script could not be launched or returned invalid output.']);
    }

    return array_merge($blank, [
      'ok' => !empty($result['ok']),
      'target_step' => (string) ($result['target_step'] ?? 'wizard_auto'),
      'completed_steps' => (array) ($result['completed_steps'] ?? []),
      'step_results' => (array) ($result['step_results'] ?? []),
      'post_continue_url' => (string) ($result['post_continue_url'] ?? ''),
      'error' => (string) ($result['error'] ?? ''),
      'evidence' => (string) ($result['evidence'] ?? ''),
      'screenshots' => (array) ($result['screenshots'] ?? []),
      'visual_confirmation' => (array) ($result['visual_confirmation'] ?? []),
      'submit_blocked' => !empty($result['submit_blocked']),
      'review_submit_mode' => (string) ($result['review_submit_mode'] ?? ''),
    ]);
  }

  // ── Private helpers ─────────────────────────────────────────────────────────

  /**
   * Build shared execution context used by both single-step and auto-session runs.
   *
   * @param int $job_id
   * @param int $uid
   * @param string $apply_url_override
   * @param array $application_fields
   *
   * @return array{
   *   ok: bool,
   *   error: string,
   *   application: array,
   *   apply_url: string,
   *   credential: array,
   *   profile_data: array,
   *   resume_pdf_path: string,
   *   screenshot_dir: string,
   * }
   */
  protected function buildRunContext(int $job_id, int $uid, string $apply_url_override = '', array $application_fields = ['id', 'apply_url', 'metadata']): array {
    $application = $this->loadLatestApplicationRecord($job_id, $uid, $application_fields);
    if (!$application) {
      return ['ok' => FALSE, 'error' => 'No application record found for job ' . $job_id . '.'];
    }

    $metadata = $this->decodeMetadata((string) ($application['metadata'] ?? ''));
    $apply_url = $this->resolveApplyUrl($application, $metadata, $apply_url_override);
    if ($apply_url === '') {
      return ['ok' => FALSE, 'error' => 'No apply URL found.'];
    }

    $credential_context = $this->resolveBasicCredential($uid, $job_id);
    if (empty($credential_context['ok'])) {
      return ['ok' => FALSE, 'error' => (string) ($credential_context['error'] ?? 'No stored default automation credentials found.')];
    }

    return [
      'ok' => TRUE,
      'error' => '',
      'application' => $application,
      'apply_url' => $apply_url,
      'credential' => (array) $credential_context['credential'],
      'profile_data' => $this->buildProfileData($uid),
      'resume_pdf_path' => $this->getResumePdfPath($uid, $job_id) ?? '',
      'screenshot_dir' => $this->resolveScreenshotDirectory(),
    ];
  }

  /**
   * Load the most recent application record for a user/job pair.
   */
  private function loadLatestApplicationRecord(int $job_id, int $uid, array $fields): ?array {
    $record = $this->database->select('jobhunter_applications', 'a')
      ->fields('a', $fields)
      ->condition('a.uid', $uid)
      ->condition('a.job_id', $job_id)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $record ?: NULL;
  }

  /**
   * Decode application metadata safely.
   */
  private function decodeMetadata(string $metadata_json): array {
    if ($metadata_json === '') {
      return [];
    }
    $decoded = json_decode($metadata_json, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Resolve best-available apply URL from override + metadata + application row.
   */
  private function resolveApplyUrl(array $application, array $metadata, string $apply_url_override = ''): string {
    $override = trim($apply_url_override);
    if ($override !== '') {
      return $override;
    }

    $resume_post_continue_url = (string) ($metadata['step5_cache']['resume_upload_result']['post_continue_url'] ?? '');
    $wd_last_url = (string) ($metadata['step5_cache']['wd_last_url'] ?? '');

    if ($wd_last_url !== '') {
      return $wd_last_url;
    }
    if ($resume_post_continue_url !== '') {
      return $resume_post_continue_url;
    }

    return (string) ($metadata['auth_url'] ?? $application['apply_url'] ?? '');
  }

  /**
   * Resolve basic credential context for this job/user pair.
   */
  private function resolveBasicCredential(int $uid, int $job_id): array {
    $company_id = $this->getCompanyIdForJob($job_id);

    /** @var \Drupal\job_hunter\Service\CredentialManagementService $cred_service */
    $cred_service = \Drupal::service('job_hunter.credential_management_service');
    $credential = $cred_service->retrieveCredential($uid, max(0, $company_id), 'basic');

    if (!$credential || empty($credential['username']) || empty($credential['password'])) {
      return ['ok' => FALSE, 'error' => 'No stored default automation credentials found.', 'credential' => []];
    }

    return ['ok' => TRUE, 'error' => '', 'credential' => $credential];
  }

  /**
   * Resolve writable screenshot directory for Playwright artifacts.
   */
  private function resolveScreenshotDirectory(): string {
    $screenshot_dir = '';
    $private_path = $this->fileSystem->realpath('private://job_hunter/screenshots');
    if ($private_path) {
      if (!is_dir($private_path)) {
        @mkdir($private_path, 0755, TRUE);
      }
      if (is_dir($private_path) && is_writable($private_path)) {
        $screenshot_dir = $private_path;
      }
    }
    return $screenshot_dir;
  }

  /**
   * Create secure temp payload file for Node runner.
   */
  private function createPayloadFile(array $payload): ?string {
    $payload_file = tempnam(sys_get_temp_dir(), 'jh_wz_');
    if ($payload_file === FALSE || $payload_file === '') {
      return NULL;
    }

    $written = file_put_contents($payload_file, json_encode($payload), LOCK_EX);
    if ($written === FALSE) {
      @unlink($payload_file);
      return NULL;
    }

    @chmod($payload_file, 0600);
    return $payload_file;
  }

  /**
   * Assemble profile data from the job_seeker table for form filling.
   */
  private function buildProfileData(int $uid): array {
    return $this->profileDataMapper->buildProfileData($uid);
  }

  /**
   * Get the company_id for a given job requirement.
   */
  private function getCompanyIdForJob(int $job_id): int {
    try {
      $cid = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j', ['company_id'])
        ->condition('j.id', $job_id)
        ->execute()
        ->fetchField();
      return (int) ($cid ?: 0);
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Resolve tailored resume PDF absolute filesystem path.
   */
  private function getResumePdfPath(int $uid, int $job_id): ?string {
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

}
