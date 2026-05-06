<?php

namespace Drupal\job_hunter\Commands;

use Drupal\job_hunter\Service\ApplicationLocationVerificationService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for requisition application-location verification.
 */
class ApplicationLocationVerificationCommands extends DrushCommands {

  protected ApplicationLocationVerificationService $verificationService;

  public function __construct(ApplicationLocationVerificationService $verification_service) {
    parent::__construct();
    $this->verificationService = $verification_service;
  }

  /**
   * Verify whether resolved destination is the true application location.
   *
   * @command job-hunter:verify-application-location
   * @aliases jh-verify-apply
   * @argument job_id Job requisition record ID from jobhunter_job_requirements.
   * @option genai-fallback 1 to enable GenAI fallback when required checks fail (default: 1).
   * @option min-description-overlap Minimum overlap ratio for description similarity (default: 0.15).
   * @option timeout HTTP timeout seconds for destination retrieval (default: 15).
   * @option emit-json Optional absolute path to write full result JSON.
   */
  public function verifyApplicationLocation($job_id, array $options = [
    'genai-fallback' => '1',
    'min-description-overlap' => '0.15',
    'timeout' => '15',
    'emit-json' => '',
  ]): int {
    $job_id = (int) $job_id;
    if ($job_id <= 0) {
      $this->output()->writeln('<error>Invalid job_id</error>');
      return DrushCommands::EXIT_FAILURE;
    }

    $result = $this->verificationService->verify($job_id, [
      'genai_fallback' => ((string) ($options['genai-fallback'] ?? '1')) !== '0',
      'min_description_overlap' => (float) ($options['min-description-overlap'] ?? 0.15),
      'timeout' => (int) ($options['timeout'] ?? 15),
    ]);

    $this->printResult($result);

    $emit_path = trim((string) ($options['emit-json'] ?? ''));
    if ($emit_path !== '') {
      file_put_contents($emit_path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      $this->output()->writeln('<info>Wrote JSON: ' . $emit_path . '</info>');
    }

    return !empty($result['final_pass']) ? DrushCommands::EXIT_SUCCESS : DrushCommands::EXIT_FAILURE;
  }

  private function printResult(array $result): void {
    if (!empty($result['error'])) {
      $this->output()->writeln('<error>' . (string) $result['error'] . '</error>');
      return;
    }

    $this->output()->writeln('');
    $this->output()->writeln('<info>Application Location Verification</info>');
    $this->output()->writeln('Job #' . (string) ($result['job_id'] ?? '0') . ' | ' . (string) ($result['job_title'] ?? ''));
    $this->output()->writeln('Company: ' . (string) ($result['company_name'] ?? ''));
    $this->output()->writeln('Original URL: ' . (string) ($result['original_url'] ?? ''));
    $this->output()->writeln('Resolved URL: ' . (string) ($result['resolved_url'] ?? ''));
    $this->output()->writeln('Effective URL: ' . (string) ($result['effective_url'] ?? ''));
    $this->output()->writeln('ATS: ' . (string) ($result['ats_platform'] ?? '') . ' | Resolver confidence: ' . (string) ($result['resolver_confidence'] ?? ''));
    $this->output()->writeln('');

    foreach (($result['checks'] ?? []) as $check) {
      $mark = !empty($check['met']) ? '[x]' : '[ ]';
      $required = !empty($check['required']) ? 'required' : 'supporting';
      $this->output()->writeln($mark . ' ' . (string) ($check['label'] ?? '') . ' (' . $required . ')');
      $this->output()->writeln('    ' . (string) ($check['evidence'] ?? ''));
    }

    $this->output()->writeln('');
    $this->output()->writeln('Heuristic pass: ' . (!empty($result['hard_pass']) ? 'yes' : 'no') . ' | Heuristic confidence: ' . (string) ($result['heuristic_confidence'] ?? 'low'));

    $genai = $result['genai'] ?? [];
    if (!empty($genai['used'])) {
      $this->output()->writeln('GenAI fallback used: yes | available: ' . (!empty($genai['available']) ? 'yes' : 'no') . ' | success: ' . (!empty($genai['success']) ? 'yes' : 'no'));
      if (!empty($genai['success'])) {
        $this->output()->writeln('GenAI confirmed: ' . (!empty($genai['confirmed']) ? 'yes' : 'no') . ' | confidence: ' . (string) ($genai['confidence'] ?? 'none'));
      }
      if (!empty($genai['evidence'])) {
        $this->output()->writeln('GenAI evidence: ' . (string) $genai['evidence']);
      }
    }

    $this->output()->writeln('');
    $this->output()->writeln('<comment>Final decision: ' . (!empty($result['final_pass']) ? 'PASS' : 'FAIL') . ' (' . (string) ($result['decision_mode'] ?? 'unknown') . ')</comment>');
    $this->output()->writeln('');
  }

}
