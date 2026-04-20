<?php

namespace Drupal\job_hunter\Service;

use Psr\Container\ContainerInterface;

/**
 * Standardized GenAI fallback decision service.
 *
 * This service centralizes:
 * - Availability checks for ai_conversation.ai_api_service
 * - Prompt invocation metadata conventions
 * - JSON extraction/decoding from model responses
 * - Standard response shape for process-flow fallback gates
 */
class GenAiFallbackService {

  protected ContainerInterface $container;

  public function __construct(ContainerInterface $container) {
    $this->container = $container;
  }

  /**
   * Evaluate a boolean fallback decision using GenAI.
   *
   * @param string $use_case
   *   Short use-case key (example: application_location_validation).
   * @param array $context
   *   Context passed to GenAI (will be JSON-encoded in prompt).
   * @param string $instruction
   *   Domain-specific instruction for model behavior and decision criteria.
   * @param array $options
   *   Supported options:
   *   - module: module key for invokeModelDirect (default: job_hunter)
   *   - stage: stage metadata for invokeModelDirect
   *   - max_tokens: response max tokens (default: 600)
   *   - decision_key: key expected for boolean decision in JSON response
   *     (default: is_confirmed)
   *
   * @return array
   *   Standardized fallback result:
   *   [
   *     'used' => bool,
   *     'available' => bool,
   *     'success' => bool,
   *     'confirmed' => bool,
   *     'confidence' => 'none|low|medium|high',
   *     'response' => string,
   *     'evidence' => string,
   *     'parsed' => array,
   *   ]
   */
  public function evaluateBooleanDecision(string $use_case, array $context, string $instruction, array $options = []): array {
    $result = [
      'used' => TRUE,
      'available' => FALSE,
      'success' => FALSE,
      'confirmed' => FALSE,
      'confidence' => 'none',
      'response' => '',
      'evidence' => '',
      'parsed' => [],
    ];

    if (!$this->container->has('ai_conversation.ai_api_service')) {
      $result['evidence'] = 'Service ai_conversation.ai_api_service is not available.';
      return $result;
    }

    $result['available'] = TRUE;

    $module = (string) ($options['module'] ?? 'job_hunter');
    $stage = (string) ($options['stage'] ?? 'generic_fallback');
    $max_tokens = max(128, (int) ($options['max_tokens'] ?? 600));
    $decision_key = (string) ($options['decision_key'] ?? 'is_confirmed');

    $prompt = $instruction . "\n\n"
      . "Return strict JSON only with keys: {$decision_key} (boolean), confidence (low|medium|high), reason (string)."
      . "\n\nContext JSON:\n"
      . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    try {
      $ai = $this->container->get('ai_conversation.ai_api_service');
      $ai_result = $ai->invokeModelDirect(
        $prompt,
        $module,
        $use_case,
        [
          'use_case' => $use_case,
          'stage' => $stage,
          'job_id' => (int) ($context['job_id'] ?? 0),
        ],
        [
          'max_tokens' => $max_tokens,
        ]
      );

      if (empty($ai_result['success'])) {
        $result['evidence'] = (string) ($ai_result['error'] ?? 'GenAI call failed.');
        return $result;
      }

      $result['success'] = TRUE;
      $response = (string) ($ai_result['response'] ?? '');
      $result['response'] = $response;

      $decoded = $this->decodeJsonFromText($response);
      if (!is_array($decoded)) {
        $result['evidence'] = 'GenAI returned non-JSON response.';
        return $result;
      }

      $result['parsed'] = $decoded;
      $result['confirmed'] = !empty($decoded[$decision_key]);
      $confidence = strtolower((string) ($decoded['confidence'] ?? 'none'));
      $result['confidence'] = in_array($confidence, ['low', 'medium', 'high'], TRUE) ? $confidence : 'none';
      $result['evidence'] = (string) ($decoded['reason'] ?? 'No reason returned.');
      return $result;
    }
    catch (\Throwable $e) {
      $result['evidence'] = $e->getMessage();
      return $result;
    }
  }

  /**
   * Parse JSON object from plain text or fenced text.
   */
  private function decodeJsonFromText(string $text): ?array {
    $direct = json_decode($text, TRUE);
    if (is_array($direct)) {
      return $direct;
    }

    if (preg_match('/\{.*\}/s', $text, $m)) {
      $parsed = json_decode((string) $m[0], TRUE);
      if (is_array($parsed)) {
        return $parsed;
      }
    }

    return NULL;
  }

}
