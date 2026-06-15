<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;

/**
 * Verifies ATS account authentication by launching a stealth browser.
 *
 * Runs playwright/verify-account.js as a subprocess, logs in with stored
 * credentials, navigates to the user home page, and confirms the identity
 * by checking for the logged-in user's email in the account menu.
 *
 * Credential data is passed via a temp file (mode 0600, deleted immediately
 * by the Node script after reading) — never exposed via argv or env.
 */
class AccountVerificationService {

  protected Connection $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  // ── Public API ──────────────────────────────────────────────────────────────

  /**
   * Verify authentication for a job application's ATS account.
   *
   * @param int   $job_id
   *   The jobhunter_job_requirements.id to verify against.
   * @param int   $uid
   *   The user ID whose credentials to use.
   * @param array $options
   *   - timeout (int)  – total seconds for the browser run; default 60.
   *
   * @return array{
   *   ok: bool,
   *   verified: bool,
   *   verified_email: string,
   *   user_home_url: string,
   *   page_title: string,
   *   evidence: string,
   *   screenshots: array,
   *   error: string,
   * }
   */
  public function verify(int $job_id, int $uid, array $options = []): array {
    $timeout = (int) ($options['timeout'] ?? 60);

    $blank = [
      'ok'             => FALSE,
      'verified'       => FALSE,
      'verified_email' => '',
      'user_home_url'  => '',
      'page_title'     => '',
      'evidence'       => '',
      'screenshots'    => [],
      'error'          => '',
    ];

    // ── Load application metadata (auth_url, ats_platform) ────────────────
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

    $auth_url     = (string) ($metadata['auth_url'] ?? $application['apply_url'] ?? '');
    $ats_platform = (string) ($application['ats_platform'] ?? 'custom');

    if ($auth_url === '') {
      return array_merge($blank, ['error' => 'No auth URL found. Run Steps 2 & 3 first.']);
    }

    // ── Load stored credentials ───────────────────────────────────────────
    $company_id = $this->getCompanyIdForJob($job_id);
    /** @var \Drupal\job_hunter\Service\CredentialManagementService $cred_service */
    $cred_service = \Drupal::service('job_hunter.credential_management_service');
    $credential = $cred_service->retrieveCredential($uid, max(0, $company_id), 'basic');

    if (!$credential || empty($credential['username']) || empty($credential['password'])) {
      return array_merge($blank, ['error' => 'No stored default automation credentials found. Update your profile credentials first.']);
    }

    // ── Build payload file ────────────────────────────────────────────────
    $payload = [
      'username'       => (string) $credential['username'],
      'password'       => (string) $credential['password'],
      'auth_url'       => $auth_url,
      'ats_platform'   => $ats_platform,
      'expected_email' => (string) ($credential['default_email'] ?? $credential['username']),
    ];

    $payload_file = tempnam(sys_get_temp_dir(), 'jh_vacct_');
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
      'ok'             => !empty($result['ok']),
      'verified'       => !empty($result['verified']),
      'verified_email' => (string) ($result['verified_email'] ?? ''),
      'user_home_url'  => (string) ($result['user_home_url'] ?? ''),
      'page_title'     => (string) ($result['page_title'] ?? ''),
      'evidence'       => (string) ($result['evidence'] ?? ''),
      'screenshots'    => (array)  ($result['screenshots'] ?? []),
      'error'          => (string) ($result['error'] ?? ''),
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
   * Spawn the Node subprocess for account verification.
   *
   * @param string $payload_file
   *   Path to the JSON payload file with credentials.
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
    $script = $playwright_dir . '/verify-account.js';
    if (!file_exists($script)) {
      return NULL;
    }

    $browser_timeout = max(60, $timeout + 20);
    $output_file = tempnam(sys_get_temp_dir(), 'jh_vacct_out_');
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
          'ok'       => FALSE,
          'verified' => FALSE,
          'error'    => 'Browser subprocess timed out after ' . $hard_cap . 's.',
        ];
      }

      usleep(500000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    // Log stderr for diagnostics.
    if ($stderr !== '') {
      \Drupal::logger('job_hunter')->notice('Account verification stderr (@platform): @stderr', [
        '@platform' => $ats_platform,
        '@stderr' => substr($stderr, 0, 2000),
      ]);
    }

    $raw = file_exists($output_file) ? file_get_contents($output_file) : '';
    @unlink($output_file);

    if ($raw === '' || $raw === FALSE) {
      return [
        'ok'       => FALSE,
        'verified' => FALSE,
        'error'    => 'Output file empty. stderr: ' . substr($stderr, 0, 400),
      ];
    }

    $decoded = json_decode(trim($raw), TRUE);
    if (!is_array($decoded)) {
      return [
        'ok'       => FALSE,
        'verified' => FALSE,
        'error'    => 'Invalid JSON from Node. stderr: ' . substr($stderr, 0, 400),
      ];
    }

    return $decoded;
  }

}
