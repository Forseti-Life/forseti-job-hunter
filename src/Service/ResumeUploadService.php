<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;

/**
 * Uploads a tailored resume to a Workday ATS via stealth browser automation.
 *
 * Runs playwright/resume-upload-workday.js as a subprocess. The script:
 *   1. Logs in with stored credentials.
 *   2. Navigates to the apply URL.
 *   3. Clicks "Autofill with resume".
 *   4. Verifies authentication (email in utility bar).
 *   5. Uploads the tailored resume PDF.
 *   6. Confirms upload success.
 *   7. Clicks "Continue".
 *
 * Credentials are passed via a temp file (mode 0600, deleted by the script
 * immediately after reading).
 */
class ResumeUploadService {

  protected Connection $database;
  protected FileSystemInterface $fileSystem;

  public function __construct(Connection $database, FileSystemInterface $file_system) {
    $this->database = $database;
    $this->fileSystem = $file_system;
  }

  // ── Public API ──────────────────────────────────────────────────────────────

  /**
   * Upload a tailored resume to the ATS for a given job application.
   *
   * @param int   $job_id
   *   The jobhunter_job_requirements.id.
   * @param int   $uid
   *   The Drupal user ID.
   * @param array $options
   *   - timeout (int) — total seconds for the browser run; default 90.
   *
   * @return array{
   *   ok: bool,
   *   auth_verified: bool,
   *   verified_email: string,
   *   resume_uploaded: bool,
   *   upload_filename: string,
   *   continue_clicked: bool,
   *   post_continue_url: string,
   *   page_title: string,
   *   evidence: string,
   *   screenshots: array,
   *   error: string,
   * }
   */
  public function uploadResume(int $job_id, int $uid, array $options = []): array {
    $timeout = (int) ($options['timeout'] ?? 90);

    $blank = [
      'ok'                => FALSE,
      'auth_verified'     => FALSE,
      'verified_email'    => '',
      'resume_uploaded'   => FALSE,
      'upload_filename'   => '',
      'continue_clicked'  => FALSE,
      'post_continue_url' => '',
      'page_title'        => '',
      'evidence'          => '',
      'screenshots'       => [],
      'error'             => '',
    ];

    // ── Load application record ───────────────────────────────────────────
    $application = $this->database->select('jobhunter_applications', 'a')
      ->fields('a', ['id', 'apply_url', 'ats_platform', 'metadata'])
      ->condition('a.uid', $uid)
      ->condition('a.job_id', $job_id)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!$application) {
      return array_merge($blank, ['error' => 'No application record found for job ' . $job_id . '.']);
    }

    $metadata = [];
    if (!empty($application['metadata'])) {
      $decoded = json_decode((string) $application['metadata'], TRUE);
      if (is_array($decoded)) {
        $metadata = $decoded;
      }
    }

    $apply_url     = (string) ($metadata['auth_url'] ?? $application['apply_url'] ?? '');
    $ats_platform  = (string) ($application['ats_platform'] ?? 'custom');

    if ($apply_url === '') {
      return array_merge($blank, ['error' => 'No apply URL found. Complete Steps 2 & 3 first.']);
    }

    // ── Load stored credentials ───────────────────────────────────────────
    $company_id = $this->getCompanyIdForJob($job_id);
    /** @var \Drupal\job_hunter\Service\CredentialManagementService $cred_service */
    $cred_service = \Drupal::service('job_hunter.credential_management_service');
    $credential = $cred_service->retrieveCredential($uid, max(0, $company_id), 'basic');

    if (!$credential || empty($credential['username']) || empty($credential['password'])) {
      return array_merge($blank, ['error' => 'No stored default automation credentials found. Update your profile credentials first.']);
    }

    // ── Resolve resume PDF path ───────────────────────────────────────────
    $resume_pdf_path = $this->getResumePdfPath($uid, $job_id);
    if (!$resume_pdf_path) {
      return array_merge($blank, ['error' => 'No tailored resume PDF found for this job. Generate the resume first.']);
    }

    // ── Build screenshot directory ────────────────────────────────────────
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

    // ── Build payload file ────────────────────────────────────────────────
    $payload = [
      'username'        => (string) $credential['username'],
      'password'        => (string) $credential['password'],
      'apply_url'       => $apply_url,
      'ats_platform'    => $ats_platform,
      'expected_email'  => (string) ($credential['default_email'] ?? $credential['username']),
      'resume_pdf_path' => $resume_pdf_path,
      'screenshot_dir'  => $screenshot_dir,
      'application_id'  => (int) $application['id'],
    ];

    $payload_file = tempnam(sys_get_temp_dir(), 'jh_ru_');
    file_put_contents($payload_file, json_encode($payload), LOCK_EX);
    chmod($payload_file, 0600);

    // ── Run the Node script ───────────────────────────────────────────────
    $result = $this->runNode($payload_file, $timeout, $ats_platform);

    // Clean up payload file if script didn't delete it.
    if (file_exists($payload_file)) {
      @unlink($payload_file);
    }

    if ($result === NULL) {
      return array_merge($blank, ['error' => 'Node script could not be launched or returned invalid output.']);
    }

    return array_merge($blank, [
      'ok'                => !empty($result['ok']),
      'auth_verified'     => !empty($result['auth_verified']),
      'verified_email'    => (string) ($result['verified_email'] ?? ''),
      'resume_uploaded'   => !empty($result['resume_uploaded']),
      'upload_filename'   => (string) ($result['upload_filename'] ?? ''),
      'continue_clicked'  => !empty($result['continue_clicked']),
      'post_continue_url' => (string) ($result['post_continue_url'] ?? ''),
      'page_title'        => (string) ($result['page_title'] ?? ''),
      'evidence'          => (string) ($result['evidence'] ?? ''),
      'screenshots'       => (array)  ($result['screenshots'] ?? []),
      'error'             => (string) ($result['error'] ?? ''),
    ]);
  }

  // ── Private helpers ─────────────────────────────────────────────────────────

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
   * Resolve the tailored resume PDF absolute filesystem path.
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

  /**
   * Spawn the Node subprocess for resume upload.
   *
   * @param string $payload_file
   *   Path to the JSON payload file with credentials + resume path.
   * @param int    $timeout
   *   Timeout in seconds.
   * @param string $ats_platform
   *   ATS platform identifier for logging.
   *
   * @return array|null
   *   Decoded JSON result, or NULL on hard failure.
   */
  private function runNode(string $payload_file, int $timeout, string $ats_platform): ?array {
    $playwright_dir = DRUPAL_ROOT . '/../web/modules/custom/job_hunter/playwright';
    if (!is_dir($playwright_dir)) {
      $playwright_dir = DRUPAL_ROOT . '/modules/custom/job_hunter/playwright';
    }
    $script = $playwright_dir . '/resume-upload-workday.js';
    if (!file_exists($script)) {
      return NULL;
    }

    $browser_timeout = max(90, $timeout + 20);
    $output_file = tempnam(sys_get_temp_dir(), 'jh_ru_out_');
    @unlink($output_file);

    // Prefer system-installed Chrome/Chromium.
    $system_chrome = '';
    foreach (['/usr/bin/google-chrome', '/usr/bin/chromium-browser', '/usr/bin/chromium'] as $candidate) {
      if (is_executable($candidate)) {
        $system_chrome = $candidate;
        break;
      }
    }

    $node_bin = is_executable('/usr/bin/node') ? '/usr/bin/node' : 'node';

    $cmd = $node_bin . ' ' . escapeshellarg($script)
      . ' --payload-file=' . escapeshellarg($payload_file)
      . ' --output-file=' . escapeshellarg($output_file)
      . ' --timeout=' . (int) $browser_timeout
      . ($system_chrome !== '' ? ' --executable-path=' . escapeshellarg($system_chrome) : '');

    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes, $playwright_dir);
    if (!is_resource($process)) {
      @unlink($output_file);
      return NULL;
    }

    fclose($pipes[0]);

    $hard_cap = $browser_timeout + 15;
    $start    = time();
    $stderr   = '';
    stream_set_blocking($pipes[2], FALSE);

    while (TRUE) {
      $chunk = fread($pipes[2], 8192);
      if ($chunk !== FALSE && $chunk !== '') {
        $stderr .= $chunk;
      }

      $status = proc_get_status($process);
      if (!$status['running']) {
        break;
      }

      if ((time() - $start) >= $hard_cap) {
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($output_file);
        return [
          'ok'    => FALSE,
          'error' => 'Browser subprocess timed out after ' . $hard_cap . 's.',
        ];
      }

      usleep(500000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    // Log stderr for diagnostics.
    if ($stderr !== '') {
      \Drupal::logger('job_hunter')->notice('Resume upload stderr (@platform): @stderr', [
        '@platform' => $ats_platform,
        '@stderr' => substr($stderr, 0, 2000),
      ]);
    }

    $raw = file_exists($output_file) ? file_get_contents($output_file) : '';
    @unlink($output_file);

    if ($raw === '' || $raw === FALSE) {
      return [
        'ok'    => FALSE,
        'error' => 'Output file empty. stderr: ' . substr($stderr, 0, 400),
      ];
    }

    $decoded = json_decode(trim($raw), TRUE);
    if (!is_array($decoded)) {
      return [
        'ok'    => FALSE,
        'error' => 'Invalid JSON from Node. stderr: ' . substr($stderr, 0, 400),
      ];
    }

    return $decoded;
  }

}
