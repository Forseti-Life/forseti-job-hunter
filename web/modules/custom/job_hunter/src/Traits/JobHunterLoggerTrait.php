<?php

namespace Drupal\job_hunter\Traits;

/**
 * Provides logging methods that respect the job_hunter log level setting.
 */
trait JobHunterLoggerTrait {

  /**
   * Log level priorities for filtering.
   */
  protected static $logLevelPriorities = [
    'debug' => 100,
    'info' => 200,
    'notice' => 250,
    'warning' => 300,
    'error' => 400,
  ];

  /**
   * Check if a log level should be logged based on settings.
   *
   * @param string $level
   *   The log level to check (debug, info, notice, warning, error).
   *
   * @return bool
   *   TRUE if the level should be logged.
   */
  protected function shouldLog($level) {
    $config = \Drupal::config('job_hunter.settings');
    $configured_level = $config->get('log_level') ?? 'notice';
    
    $level_priority = self::$logLevelPriorities[$level] ?? 250;
    $configured_priority = self::$logLevelPriorities[$configured_level] ?? 250;
    
    return $level_priority >= $configured_priority;
  }

  /**
   * Log a debug message if log level permits.
   *
   * @param string $message
   *   The message string.
   * @param array $context
   *   The context array.
   */
  protected function logDebug($message, array $context = []) {
    if ($this->shouldLog('debug')) {
      \Drupal::logger('job_hunter')->debug($message, $context);
    }
  }

  /**
   * Log an info message if log level permits.
   *
   * @param string $message
   *   The message string.
   * @param array $context
   *   The context array.
   */
  protected function logInfo($message, array $context = []) {
    if ($this->shouldLog('info')) {
      \Drupal::logger('job_hunter')->info($message, $context);
    }
  }

  /**
   * Log a notice message if log level permits.
   *
   * @param string $message
   *   The message string.
   * @param array $context
   *   The context array.
   */
  protected function logNotice($message, array $context = []) {
    if ($this->shouldLog('notice')) {
      \Drupal::logger('job_hunter')->notice($message, $context);
    }
  }

  /**
   * Log a warning message if log level permits.
   *
   * @param string $message
   *   The message string.
   * @param array $context
   *   The context array.
   */
  protected function logWarning($message, array $context = []) {
    if ($this->shouldLog('warning')) {
      \Drupal::logger('job_hunter')->warning($message, $context);
    }
  }

  /**
   * Log an error message (always logged regardless of level).
   *
   * @param string $message
   *   The message string.
   * @param array $context
   *   The context array.
   */
  protected function logError($message, array $context = []) {
    // Errors are always logged
    \Drupal::logger('job_hunter')->error($message, $context);
  }

}
