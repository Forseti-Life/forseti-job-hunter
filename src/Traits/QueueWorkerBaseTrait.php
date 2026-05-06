<?php

namespace Drupal\job_hunter\Traits;

use Drupal\Core\Queue\SuspendQueueException;

/**
 * Provides common functionality for Job Hunter queue workers.
 * 
 * Centralizes:
 * - Database status updates
 * - GenAI API calls
 * - JSON extraction and parsing
 * - Error handling patterns
 */
trait QueueWorkerBaseTrait {

  /**
   * Update or create a database record with status.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param string $table
   *   The table name (e.g., 'jobhunter_tailored_resumes').
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job ID.
   * @param string $status
   *   The status value (e.g., 'processing', 'completed', 'failed').
   * @param array $extra_fields
   *   Additional fields to update/insert.
   * @param string $id_column
   *   The column name to check for existing record (default: 'id').
   *
   * @return int|null
   *   The record ID.
   */
  protected function updateDatabaseStatus($connection, string $table, int $uid, int $job_id, string $status, array $extra_fields = [], string $id_column = 'id') {
    $now = time();
    
    // Check for existing record
    $existing = $connection->select($table, 't')
      ->fields('t', [$id_column])
      ->condition('uid', $uid)
      ->condition('job_id', $job_id)
      ->execute()
      ->fetchField();

    // Build fields array
    $fields = array_merge([
      'tailoring_status' => $status,
      'updated' => $now,
    ], $extra_fields);

    if ($existing) {
      // Update existing record
      $connection->update($table)
        ->fields($fields)
        ->condition($id_column, $existing)
        ->execute();
      return $existing;
    }
    else {
      // Insert new record
      $fields['uid'] = $uid;
      $fields['job_id'] = $job_id;
      $fields['created'] = $now;
      
      return $connection->insert($table)
        ->fields($fields)
        ->execute();
    }
  }

  /**
   * Call GenAI API service with standardized error handling.
   *
   * @param string $prompt
   *   The prompt to send to GenAI.
   * @param string $module
   *   The module name for context tracking (e.g., 'job_hunter').
   * @param string $operation
   *   The operation name (e.g., 'resume_tailoring', 'cover_letter').
   * @param array $context_data
   *   Context data for tracking (uid, job_id, etc.).
   * @param int $max_tokens
   *   Maximum tokens for response (default: 8000).
   *
   * @return array|null
   *   The API result array with 'success', 'response', 'stop_reason' keys, or NULL on failure.
   *
   * @throws \Exception
   *   If the API call fails.
   */
  protected function callGenAiService(string $prompt, string $module, string $operation, array $context_data, int $max_tokens = 8000) {
    if (method_exists($this, 'logInfo')) {
      $this->logInfo('Queue: Calling GenAI API for @operation (max_tokens: @max)', [
        '@operation' => $operation,
        '@max' => $max_tokens,
      ]);
    }

    $result = $this->aiApiService->invokeModelDirect(
      $prompt,
      $module,
      $operation,
      $context_data,
      [
        'max_tokens' => $max_tokens,
      ]
    );

    if (!$result['success']) {
      $error = $result['error'] ?? 'Unknown error';
      if (method_exists($this, 'logError')) {
        $this->logError('AIApiService call failed: @error', ['@error' => $error]);
      }
      throw new \Exception("GenAI API call failed: {$error}");
    }

    return $result;
  }

  /**
   * Extract and parse JSON from GenAI response with comprehensive error handling.
   *
   * @param string $ai_response
   *   The raw AI response text.
   * @param string $stop_reason
   *   The stop reason from the API (e.g., 'end_turn', 'max_tokens').
   * @param string $operation
   *   The operation name for logging context.
   *
   * @return array|null
   *   The parsed JSON array, or NULL if parsing fails.
   *
   * @throws \Drupal\Core\Queue\SuspendQueueException
   *   If response was truncated due to max_tokens.
   */
  protected function parseGenAiJsonResponse(string $ai_response, string $stop_reason, string $operation) {
    $response_length = strlen($ai_response);
    
    // Verbose logging if logInfo is available
    if (method_exists($this, 'logInfo')) {
      $this->logInfo('🔍 Parsing GenAI response: length=@len, stop_reason=@reason', [
        '@len' => $response_length,
        '@reason' => $stop_reason,
      ]);
    }
    
    // Check if response was truncated due to max_tokens
    if ($stop_reason === 'max_tokens') {
      $error_msg = "GenAI response hit max_tokens limit! Response truncated at {$response_length} chars. The response is incomplete and cannot be parsed.";
      
      if (method_exists($this, 'logError')) {
        $this->logError('❌ @operation: @error', [
          '@operation' => $operation,
          '@error' => $error_msg,
        ]);
      }
      
      // Suspend queue - this requires manual intervention or cache clearing
      throw new SuspendQueueException($error_msg . ' Clear cache if prompt needs adjustment.');
    }

    // Extract JSON from response (handles markdown code blocks, explanatory text, etc.)
    $json_str = $this->extractJsonFromResponse($ai_response);

    if (!$json_str) {
      if (method_exists($this, 'logError')) {
        $this->logError('❌ No valid JSON found in response. Original response length: @len', [
          '@len' => $response_length,
        ]);
      }
      return NULL;
    }

    // Parse JSON
    $parsed = json_decode($json_str, TRUE);
    $json_error = json_last_error();
    $json_error_msg = json_last_error_msg();
    
    if ($json_error !== JSON_ERROR_NONE || !$parsed) {
      if (method_exists($this, 'logError')) {
        $this->logError('❌ JSON parse error: @error (code: @code). Extracted JSON length: @len', [
          '@error' => $json_error_msg,
          '@code' => $json_error,
          '@len' => strlen($json_str),
        ]);
        
        // Log a preview of what failed to parse
        $preview_len = min(500, strlen($json_str));
        $this->logError('JSON preview (first @len chars): @preview', [
          '@len' => $preview_len,
          '@preview' => substr($json_str, 0, $preview_len),
        ]);
      }
      return NULL;
    }

    if (method_exists($this, 'logInfo')) {
      $this->logInfo('✅ Successfully parsed JSON response');
    }

    return $parsed;
  }

  /**
   * Extract JSON from GenAI response text.
   * 
   * Handles various formats:
   * - Plain JSON
   * - Markdown code blocks (```json ... ```)
   * - Text with embedded JSON
   *
   * @param string $response
   *   The raw response text.
   *
   * @return string|null
   *   The extracted JSON string, or NULL if not found.
   */
  protected function extractJsonFromResponse(string $response) {
    // Try to find JSON in markdown code block first
    if (preg_match('/```json\s*(\{.*?\})\s*```/s', $response, $matches)) {
      return trim($matches[1]);
    }
    
    // Try regular code block
    if (preg_match('/```\s*(\{.*?\})\s*```/s', $response, $matches)) {
      return trim($matches[1]);
    }
    
    // Try to find JSON object in text (greedy match from first { to last })
    $first_brace = strpos($response, '{');
    $last_brace = strrpos($response, '}');
    
    if ($first_brace !== FALSE && $last_brace !== FALSE && $last_brace > $first_brace) {
      return substr($response, $first_brace, $last_brace - $first_brace + 1);
    }
    
    // If response looks like it starts with JSON, try it as-is
    $trimmed = trim($response);
    if (substr($trimmed, 0, 1) === '{' && substr($trimmed, -1) === '}') {
      return $trimmed;
    }
    
    return NULL;
  }

  /**
   * Get user and job information for logging.
   *
   * @param int $uid
   *   The user ID.
   * @param array $job_data
   *   The job data array with extracted_json field.
   *
   * @return array
   *   Array with 'username', 'company', 'job_title' keys.
   */
  protected function getLoggingContext(int $uid, array $job_data) {
    $user = \Drupal\user\Entity\User::load($uid);
    $username = $user ? $user->getAccountName() : "uid:$uid";
    
    $extracted = !empty($job_data['extracted_json']) ? json_decode($job_data['extracted_json'], TRUE) : [];
    $company = $extracted['company_name'] ?? $extracted['company']['name'] ?? 'Unknown Company';
    $job_title = $extracted['job_title'] ?? $extracted['position']['title'] ?? 'Unknown Position';
    
    return [
      'username' => $username,
      'company' => $company,
      'job_title' => $job_title,
    ];
  }

  /**
   * Handle queue worker exceptions with consistent logging and status updates.
   *
   * @param \Exception $e
   *   The exception.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param string $table
   *   The database table to update.
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job ID.
   * @param array $logging_context
   *   Context for logging (username, company, job_title).
   * @param string $operation
   *   The operation name (for logging).
   *
   * @throws \Exception
   *   Re-throws the exception after logging and status update.
   */
  protected function handleQueueException(\Exception $e, $connection, string $table, int $uid, int $job_id, array $logging_context, string $operation) {
    if (method_exists($this, 'logError')) {
      $this->logError('❌ Queue: @operation failed for @username → "@title" at @company (job @job_id): @error', [
        '@operation' => $operation,
        '@username' => $logging_context['username'] ?? 'unknown',
        '@title' => $logging_context['job_title'] ?? 'unknown',
        '@company' => $logging_context['company'] ?? 'unknown',
        '@job_id' => $job_id,
        '@error' => $e->getMessage(),
      ]);
    }

    // Update status to failed
    $this->updateDatabaseStatus($connection, $table, $uid, $job_id, 'failed', [
      'error_message' => substr($e->getMessage(), 0, 500), // Truncate long error messages
    ]);

    // Re-throw to trigger retry logic
    throw $e;
  }

  /**
   * Get max_tokens configuration for an operation.
   *
   * @param string $config_key
   *   The specific config key (e.g., 'max_tokens_resume_tailoring').
   * @param int $default
   *   Default value if not configured (default: 8000).
   *
   * @return int
   *   The max_tokens value.
   */
  protected function getMaxTokensConfig(string $config_key, int $default = 8000): int {
    if (!isset($this->configFactory)) {
      return $default;
    }
    
    $ai_config = $this->configFactory->get('ai_conversation.settings');
    return $ai_config->get($config_key) ?? $ai_config->get('max_tokens') ?? $default;
  }

}
