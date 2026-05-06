<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Runs Playwright wizard scripts and returns decoded JSON results.
 */
class WorkdayPlaywrightRunner {

  protected LoggerChannelInterface $logger;

  public function __construct(LoggerChannelInterface $logger) {
    $this->logger = $logger;
  }

  /**
   * Spawn the Node subprocess for the wizard advance script.
   */
  public function runWizardPayload(string $payload_file, int $timeout, string $step_key): ?array {
    $playwright_dir = DRUPAL_ROOT . '/../web/modules/custom/job_hunter/playwright';
    if (!is_dir($playwright_dir)) {
      $playwright_dir = DRUPAL_ROOT . '/modules/custom/job_hunter/playwright';
    }
    $script = $playwright_dir . '/workday-wizard-advance.js';
    if (!file_exists($script)) {
      return NULL;
    }

    $browser_timeout = max(120, $timeout + 20);
    $output_file = tempnam(sys_get_temp_dir(), 'jh_wz_out_');
    @unlink($output_file);

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
    stream_set_blocking($pipes[1], FALSE);
    stream_set_blocking($pipes[2], FALSE);

    while (TRUE) {
      // Drain stdout to prevent subprocess blocking on a full pipe buffer.
      fread($pipes[1], 8192);

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

    if ($stderr !== '') {
      $this->logger->notice('WD wizard @step stderr: @stderr', [
        '@step'   => $step_key,
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
