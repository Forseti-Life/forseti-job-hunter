<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;

/**
 * Identifies the authentication path required to submit a job application.
 *
 * Launches `playwright/identify-auth-path.js` as a subprocess, navigates to
 * the resolved apply URL, clicks the Apply button (if present), and classifies
 * the authentication mechanism that appears.
 *
 * Result is persisted to jobhunter_applications.metadata as:
 *   auth_type, auth_url, sso_providers, form_fields, auth_identification_at
 *
 * AUTH TYPES returned by the Node script
 * ──────────────────────────────────────
 * direct             - no login required; form appears immediately
 * email_password     - standard email + password login form
 * email_only         - passwordless / magic-link
 * sso_google         - Google OAuth primary path
 * sso_linkedin       - LinkedIn OAuth primary path
 * sso_microsoft      - Microsoft / Azure AAD
 * sso_apple          - Apple Sign-In
 * company_sso        - company's own IdP (SAML/OIDC, Okta, OneLogin, etc.)
 * registration_first - must register/create account before applying
 * captcha_blocked    - bot-detection prevented classification
 * unknown            - could not determine
 */
class AuthPathIdentificationService {

  protected Connection $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  // ── Public API ──────────────────────────────────────────────────────────────

  /**
   * Identify the authentication path for a job application.
   *
   * @param int   $job_id
   *   The jobhunter_job_requirements.id to analyse.
   * @param array $options
   *   timeout  (int)    – seconds; default 45
   *
   * @return array{
   *   job_id: int,
   *   ok: bool,
   *   auth_type: string,
   *   sso_providers: string[],
   *   form_fields: string[],
   *   auth_url: string,
   *   page_title: string,
   *   evidence: string,
   *   html_excerpt: string,
   *   error: string,
   * }
   */
  public function identify(int $job_id, array $options = []): array {
    $timeout = (int) ($options['timeout'] ?? 45);

    $blank = [
      'job_id'        => $job_id,
      'ok'            => FALSE,
      'auth_type'     => 'unknown',
      'sso_providers' => [],
      'form_fields'   => [],
      'auth_url'      => '',
      'page_title'    => '',
      'evidence'      => '',
      'html_excerpt'  => '',
      'error'         => '',
    ];

    // Load the resolved apply URL — prefer the persisted value from Step 2.
    $apply_url = $this->loadApplyUrl($job_id);
    if ($apply_url === '') {
      return array_merge($blank, ['error' => 'No resolved apply URL found for job ' . $job_id . '. Run Step 2 first.']);
    }

    $raw = $this->runNode($apply_url, $timeout);

    if (!is_array($raw)) {
      return array_merge($blank, ['error' => 'Node script returned invalid JSON or could not be launched.']);
    }

    return array_merge($blank, [
      'ok'           => !empty($raw['ok']),
      'auth_type'    => (string) ($raw['auth_type'] ?? 'unknown'),
      'sso_providers'=> (array)  ($raw['sso_providers'] ?? []),
      'form_fields'  => (array)  ($raw['form_fields'] ?? []),
      'auth_url'     => (string) ($raw['apply_url'] ?? $apply_url),
      'page_title'   => (string) ($raw['page_title'] ?? ''),
      'evidence'     => (string) ($raw['evidence'] ?? ''),
      'html_excerpt' => (string) ($raw['html_excerpt'] ?? ''),
      'error'        => (string) ($raw['error'] ?? ''),
    ]);
  }

  // ── Private helpers ─────────────────────────────────────────────────────────

  /**
   * Load the best available apply URL for a job.
   *
   * Priority: jobhunter_applications.apply_url (persisted by Step 2)
   *         → jobhunter_job_requirements.apply_url
   */
  private function loadApplyUrl(int $job_id): string {
    // Check the applications table first (set when Step 2 passes).
    try {
      $row = $this->database->select('jobhunter_applications', 'a')
        ->fields('a', ['apply_url'])
        ->condition('a.job_id', $job_id)
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();
      if (!empty($row['apply_url'])) {
        return (string) $row['apply_url'];
      }
    }
    catch (\Exception $e) {
      // Fall through to job-requirements lookup.
    }

    // Fall back to the raw requirements record.
    try {
      $row = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j', ['apply_url'])
        ->condition('j.id', $job_id)
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();
      return !empty($row['apply_url']) ? (string) $row['apply_url'] : '';
    }
    catch (\Exception $e) {
      return '';
    }
  }

  /**
   * Spawn the Node subprocess.
   *
   * @return array|null  Decoded JSON result, or NULL on hard failure.
   */
  private function runNode(string $apply_url, int $timeout): ?array {
    // Locate identify-auth-path.js.
    $playwright_dir = DRUPAL_ROOT . '/../web/modules/custom/job_hunter/playwright';
    if (!is_dir($playwright_dir)) {
      $playwright_dir = DRUPAL_ROOT . '/modules/custom/job_hunter/playwright';
    }
    $script = $playwright_dir . '/identify-auth-path.js';
    if (!file_exists($script)) {
      return NULL;
    }

    // Give the browser more time than the PHP-level timeout:
    // stealth launch + click + wait can easily take 30-50 s.
    $browser_timeout = max(50, $timeout + 20);

    // Write result to a temp file to avoid pipe-buffer overflow on large HTML.
    $output_file = tempnam(sys_get_temp_dir(), 'jh_ap_');
    @unlink($output_file);

    // Prefer system-installed Chrome/Chromium accessible to www-data.
    $system_chrome = '';
    foreach (['/usr/bin/google-chrome', '/usr/bin/chromium-browser', '/usr/bin/chromium'] as $candidate) {
      if (is_executable($candidate)) {
        $system_chrome = $candidate;
        break;
      }
    }

    $node_bin = is_executable('/usr/bin/node') ? '/usr/bin/node' : 'node';

    $cmd = $node_bin . ' ' . escapeshellarg($script)
      . ' --url=' . escapeshellarg($apply_url)
      . ' --timeout=' . (int) $browser_timeout
      . ' --output-file=' . escapeshellarg($output_file)
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
        return ['ok' => FALSE, 'auth_type' => 'unknown', 'error' => 'Node subprocess timed out after ' . $hard_cap . 's.', 'evidence' => '', 'apply_url' => $apply_url];
      }

      usleep(500000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $raw = file_exists($output_file) ? file_get_contents($output_file) : '';
    @unlink($output_file);

    if ($raw === '' || $raw === FALSE) {
      return ['ok' => FALSE, 'auth_type' => 'unknown', 'error' => 'Output file empty. stderr: ' . substr($stderr, 0, 400), 'evidence' => '', 'apply_url' => $apply_url];
    }

    $decoded = json_decode(trim($raw), TRUE);
    if (!is_array($decoded)) {
      return ['ok' => FALSE, 'auth_type' => 'unknown', 'error' => 'Invalid JSON from Node. stderr: ' . substr($stderr, 0, 400), 'evidence' => '', 'apply_url' => $apply_url];
    }

    return $decoded;
  }

}
