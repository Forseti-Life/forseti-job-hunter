<?php

namespace Drupal\amisafe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for managing user console logs from mobile devices.
 */
class LogManagementController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a LogManagementController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Displays the log management page with file selection.
   *
   * @return array
   *   Render array for the log management page.
   */
  public function logManagementPage() {
    // Get all available log files
    $logs = $this->database->select('amisafe_user_logs', 'aul')
      ->fields('aul')
      ->orderBy('uploaded_at', 'DESC')
      ->execute()
      ->fetchAll();

    // Build the page
    $build = [
      '#theme' => 'amisafe_log_management',
      '#logs' => $logs,
      '#attached' => [
        'library' => [
          'amisafe/log-viewer',
        ],
      ],
    ];

    return $build;
  }

  /**
   * API endpoint to upload log files from mobile devices.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with upload status.
   */
  public function uploadLog(Request $request) {
    // Verify request method
    if ($request->getMethod() !== 'POST') {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Only POST requests are allowed',
      ], 405);
    }

    // Get JSON payload
    $content = $request->getContent();
    $data = json_decode($content, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Invalid JSON payload',
      ], 400);
    }

    // Validate required fields
    if (empty($data['user_id']) || empty($data['log_content'])) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Missing required fields: user_id and log_content',
      ], 400);
    }

    $user_id = $data['user_id'];
    $log_content = $data['log_content'];
    $device_info = $data['device_info'] ?? NULL;
    $app_version = $data['app_version'] ?? NULL;

    try {
      // Delete existing log for this user (keep only most recent)
      $this->database->delete('amisafe_user_logs')
        ->condition('user_id', $user_id)
        ->execute();

      // Insert new log
      $this->database->insert('amisafe_user_logs')
        ->fields([
          'user_id' => $user_id,
          'log_content' => $log_content,
          'device_info' => $device_info,
          'app_version' => $app_version,
          'uploaded_at' => time(),
        ])
        ->execute();

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Log uploaded successfully',
        'timestamp' => time(),
      ]);

    }
    catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('Log upload failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Failed to save log file',
      ], 500);
    }
  }

  /**
   * API endpoint to retrieve a specific log file.
   *
   * @param string $log_id
   *   The log ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with log content.
   */
  public function getLog($log_id) {
    $log = $this->database->select('amisafe_user_logs', 'aul')
      ->fields('aul')
      ->condition('id', $log_id)
      ->execute()
      ->fetch();

    if (!$log) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Log not found',
      ], 404);
    }

    return new JsonResponse([
      'success' => TRUE,
      'log' => [
        'id' => $log->id,
        'user_id' => $log->user_id,
        'log_content' => $log->log_content,
        'device_info' => $log->device_info,
        'app_version' => $log->app_version,
        'uploaded_at' => date('Y-m-d H:i:s', $log->uploaded_at),
      ],
    ]);
  }

  /**
   * API endpoint to delete a log file.
   *
   * @param string $log_id
   *   The log ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with deletion status.
   */
  public function deleteLog($log_id) {
    try {
      $deleted = $this->database->delete('amisafe_user_logs')
        ->condition('id', $log_id)
        ->execute();

      if ($deleted) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => 'Log deleted successfully',
        ]);
      }
      else {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Log not found',
        ], 404);
      }
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Failed to delete log',
      ], 500);
    }
  }

}
