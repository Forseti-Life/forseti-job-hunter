<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Controller for Job Hunter home page.
 */
class JobHunterHomeController extends ControllerBase {
  use JobHunterControllerTrait;

  /**
   * Builds a CSRF-protected URL for a fixed path.
   *
   * @param string $path
   *   Internal path beginning with '/'.
   *
   * @return string
   *   URL including a valid token query argument.
   */
  protected function buildCsrfPathUrl(string $path): string {
    $normalized_path = ltrim($path, '/');
    $token = \Drupal::service('csrf_token')->get($normalized_path);

    return Url::fromUserInput($path, [
      'query' => [
        'token' => $token,
      ],
    ])->toString();
  }

  /**
   * Maximum number of queue items to process per run.
   */
  private const MAX_QUEUE_ITEMS_PER_RUN = 10;

  /**
   * Maximum retry attempts before suspending a queue item.
   */
  private const MAX_RETRY_ATTEMPTS = 3;

  /**
   * Maximum processing time in seconds for queue processing.
   */
  private const QUEUE_PROCESSING_TIMEOUT = 30;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The queue factory service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The lock backend service.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lockBackend;

  /**
   * Constructs a JobHunterHomeController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock_backend
   *   The lock backend service.
   */
  public function __construct(Connection $database, QueueFactory $queue_factory, StateInterface $state, LoggerInterface $logger, LockBackendInterface $lock_backend) {
    $this->database = $database;
    $this->queueFactory = $queue_factory;
    $this->state = $state;
    $this->logger = $logger;
    $this->lockBackend = $lock_backend;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('queue'),
      $container->get('state'),
      $container->get('logger.factory')->get('job_hunter'),
      $container->get('lock')
    );
  }

  /**
   * Queue definitions with display names and descriptions.
   */
  protected const QUEUE_DEFINITIONS = [
    'job_hunter_genai_parsing' => [
      'name' => 'Resume AI Parsing',
      'description' => 'Extracts structured data from uploaded resumes using Claude AI',
      'icon' => '📄',
    ],
    'job_hunter_job_posting_parsing' => [
      'name' => 'Job Posting AI Parsing',
      'description' => 'Extracts job requirements, skills, and company info from job postings',
      'icon' => '📋',
    ],
    'job_hunter_resume_tailoring' => [
      'name' => 'Resume Tailoring',
      'description' => 'Generates tailored resumes matching job requirements',
      'icon' => '✨',
    ],
    'job_hunter_cover_letter_tailoring' => [
      'name' => 'Cover Letter Tailoring',
      'description' => 'Generates personalized cover letters for job applications',
      'icon' => '✉️',
    ],
    'job_hunter_application_submission' => [
      'name' => 'Application Submission',
      'description' => 'Processes queued automated application submissions',
      'icon' => '🚀',
    ],
    'job_hunter_text_extraction' => [
      'name' => 'Resume Text Extraction',
      'description' => 'Extracts raw text from PDF/DOCX resume files',
      'icon' => '📝',
    ],
    'job_hunter_profile_text_extraction' => [
      'name' => 'Profile Text Extraction',
      'description' => 'Extracts text from profile attachments',
      'icon' => '👤',
    ],
  ];

  /**
   * Display the Job Hunter home page.
   *
   * @return array
   *   Render array for the home page.
   */
  public function home() {
    $current_user = $this->currentUser();
    $user_id = $current_user->id();

    // Render the navigation block
    $block_manager = \Drupal::service('plugin.manager.block');
    $plugin_block = $block_manager->createInstance('job_hunter_navigation', []);
    $navigation_block = $plugin_block->build();

    $libraries = [
      'job_hunter/job-hunter-home',
    ];
    $drupal_settings = [];

    // Add queue controls library for admin users
    if ($current_user->hasPermission('administer job application automation')) {
      $libraries[] = 'job_hunter/queue-controls';
      $drupal_settings['jobHunterQueueControls'] = [
        'runUrl' => $this->buildCsrfPathUrl('/jobhunter/queue/run'),
        'runAllUrl' => $this->buildCsrfPathUrl('/jobhunter/queue/run-all'),
        'pauseUrl' => $this->buildCsrfPathUrl('/jobhunter/queue/pause'),
        'resumeUrl' => $this->buildCsrfPathUrl('/jobhunter/queue/resume'),
        'retrySuspendedUrl' => $this->buildCsrfPathUrl('/jobhunter/queue/retry-suspended'),
      ];
    }

    $build = [
      '#theme' => 'job_hunter_home',
      '#attached' => [
        'library' => $libraries,
        'drupalSettings' => $drupal_settings,
      ],
      '#navigation' => $navigation_block,
    ];

    // User profile section
    $build['#user_profile'] = [
      'view_url' => Url::fromRoute('job_hunter.user_job_seeker_view')->toString(),
      'edit_url' => Url::fromRoute('job_hunter.user_profile_edit')->toString(),
    ];

    // Job discovery section
    $build['#job_discovery'] = [
      'start_url' => Url::fromRoute('job_hunter.job_discovery')->toString(),
    ];

    // Dashboard section
    $build['#dashboard'] = [
      'main_url' => Url::fromRoute('job_hunter.dashboard')->toString(),
    ];

    // Statistics (if available)
    $stats = $this->getUserStatistics($user_id);
    $build['#statistics'] = $stats;

    // Queue status - visible to all authenticated users (read-only)
    $build['#queue_status'] = $this->getQueueStatus();

    return $build;
  }

  /**
   * Get status of all Job Hunter queues.
   *
   * @return array
   *   Array of queue status information.
   */
  protected function getQueueStatus(): array {
    $status = [];

    foreach (self::QUEUE_DEFINITIONS as $queue_id => $info) {
      $queue = $this->queueFactory->get($queue_id);
      $status[$queue_id] = [
        'id' => $queue_id,
        'name' => $info['name'],
        'description' => $info['description'],
        'icon' => $info['icon'],
        'items' => $queue->numberOfItems(),
      ];
    }

    return $status;
  }

  /**
   * Validates and returns a queue ID.
   *
   * @param mixed $queue_id
   *   The queue ID to validate.
   *
   * @return string
   *   The validated queue ID.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   If the queue ID is invalid.
   */
  private function validateQueueId($queue_id): string {
    if (!is_string($queue_id) || empty($queue_id)) {
      throw new BadRequestHttpException('Invalid queue ID: must be a non-empty string');
    }

    if (!isset(self::QUEUE_DEFINITIONS[$queue_id])) {
      throw new BadRequestHttpException('Unknown queue ID');
    }

    return $queue_id;
  }

  /**
   * Validates and returns an item ID.
   *
   * @param mixed $item_id
   *   The item ID to validate.
   *
   * @return int
   *   The validated item ID.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   If the item ID is invalid.
   */
  private function validateItemId($item_id): int {
    $id = filter_var($item_id, FILTER_VALIDATE_INT);
    if ($id === FALSE) {
      throw new BadRequestHttpException('Invalid item ID: must be an integer');
    }
    if ($id < 1) {
      throw new BadRequestHttpException('Invalid item ID: must be greater than zero');
    }
    return $id;
  }

  /**
   * AJAX endpoint to run a specific queue.
   *
   * This endpoint is intended for admin use only. Normally, queue processing
   * should happen via Drupal cron. This method is provided for manual testing
   * and diagnostics.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request containing 'queue_id' parameter.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with format:
   *   - success: bool - Whether processing succeeded
   *   - message: string - Human-readable result message
   *   - processed: int - Number of items processed
   *   - queue_id: string - The queue that was processed
   *   - remaining: int - Number of items still in queue
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If user lacks 'administer job application automation' permission.
   */
  public function runQueueAjax(Request $request): JsonResponse {
    // Check permission
    if (!$this->currentUser()->hasPermission('administer job application automation')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied',
      ], 403);
    }

    try {
      $queue_id = $this->validateQueueId($request->request->get('queue_id'));
      $processed = $this->processQueue($queue_id);
      
      return new JsonResponse([
        'success' => TRUE,
        'message' => "Processed {$processed} items from " . self::QUEUE_DEFINITIONS[$queue_id]['name'],
        'processed' => $processed,
        'queue_id' => $queue_id,
        'remaining' => $this->queueFactory->get($queue_id)->numberOfItems(),
      ]);
    }
    catch (BadRequestHttpException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $e->getMessage(),
      ], 400);
    }
    catch (\RuntimeException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $e->getMessage(),
      ], 409);
    }
    catch (\Exception $e) {
      $this->logger->error('Queue processing error: @error', ['@error' => $e->getMessage()]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Error: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * AJAX endpoint to run all queues in logical order.
   *
   * Processes all Job Hunter queues sequentially in dependency order.
   * This is an admin-only endpoint for manual queue management.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with format:
   *   - success: bool - Whether processing succeeded
   *   - message: string - Summary of results
   *   - total_processed: int - Total items processed across all queues
   *   - results: array - Per-queue results with processed counts or errors
   */
  public function runAllQueuesAjax(Request $request): JsonResponse {
    // Check permission
    if (!$this->currentUser()->hasPermission('administer job application automation')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied',
      ], 403);
    }

    $results = [];
    $total_processed = 0;

    // Process queues in logical order
    $queue_order = [
      'job_hunter_text_extraction',
      'job_hunter_profile_text_extraction',
      'job_hunter_genai_parsing',
      'job_hunter_job_posting_parsing',
      'job_hunter_resume_tailoring',
      'job_hunter_cover_letter_tailoring',
    ];

    foreach ($queue_order as $queue_id) {
      try {
        $processed = $this->processQueue($queue_id);
        $total_processed += $processed;
        $results[$queue_id] = [
          'processed' => $processed,
          'remaining' => $this->queueFactory->get($queue_id)->numberOfItems(),
        ];
      }
      catch (\Exception $e) {
        $results[$queue_id] = [
          'error' => $e->getMessage(),
        ];
      }
    }

    return new JsonResponse([
      'success' => TRUE,
      'message' => "Processed {$total_processed} total items across all queues",
      'total_processed' => $total_processed,
      'results' => $results,
    ]);
  }

  /**
   * AJAX endpoint to get current queue status.
   *
   * Returns status information for all Job Hunter queues including item counts.
   * Available to all authenticated users (read-only).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with format:
   *   - success: bool - Always TRUE for authenticated users
   *   - queues: array - Status for each queue including name, description, and item count
   */
  public function getQueueStatusAjax(): JsonResponse {
    // Allow any authenticated user to view queue status
    if (!$this->currentUser()->isAuthenticated()) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied',
      ], 403);
    }

    return new JsonResponse([
      'success' => TRUE,
      'queues' => $this->getQueueStatus(),
    ]);
  }

  /**
   * Get recent queue activity logs (AJAX endpoint).
   *
   * Returns the last 20 queue-related log entries from the watchdog table.
   * Admin-only for security (may contain sensitive debugging information).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with format:
   *   - success: bool - Whether request succeeded
   *   - logs: array - Log entries with timestamp, message, and type (error/warning/info)
   *   - message: string - Error message if access denied
   */
  public function getQueueLogsAjax(): JsonResponse {
    // Admin only for detailed logs
    if (!$this->currentUser()->hasPermission('administer job application automation')) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Access denied'], 403);
    }

    // Get last 20 queue-related log entries
    $query = $this->database->select('watchdog', 'w')
      ->fields('w', ['wid', 'timestamp', 'type', 'severity', 'message', 'variables'])
      ->condition('type', 'job_hunter')
      ->orderBy('timestamp', 'DESC')
      ->range(0, 20);
    
    $results = $query->execute()->fetchAll();
    
    $logs = [];
    foreach ($results as $row) {
      $variables = unserialize($row->variables);
      $message = strtr($row->message, $variables);
      
      // Map severity to type
      $type_map = [
        0 => 'error',    // EMERGENCY
        1 => 'error',    // ALERT
        2 => 'error',    // CRITICAL
        3 => 'error',    // ERROR
        4 => 'warning',  // WARNING
        5 => 'warning',  // NOTICE
        6 => 'info',     // INFO
        7 => 'info',     // DEBUG
      ];
      
      $logs[] = [
        'timestamp' => $row->timestamp,
        'message' => $message,
        'type' => $type_map[$row->severity] ?? 'info',
      ];
    }
    
    return new JsonResponse([
      'success' => TRUE,
      'logs' => $logs,
    ]);
  }

  /**
   * Process items from a queue.
   *
   * @param string $queue_id
   *   The queue ID.
   * @param int $max_items
   *   Maximum items to process.
   * @param int $timeout
   *   Maximum execution time in seconds.
   *
   * @return int
   *   Number of items processed.
   */
  protected function processQueue(string $queue_id, int $max_items = self::MAX_QUEUE_ITEMS_PER_RUN, int $timeout = self::QUEUE_PROCESSING_TIMEOUT): int {
    // Check if queue processing is paused
    if ($this->state->get('job_hunter.queue_paused', FALSE)) {
      $this->logger->notice('Queue processing is paused. Skipping @queue', ['@queue' => $queue_id]);
      return 0;
    }

    $lock_name = "job_hunter.queue_run.{$queue_id}";
    if (!$this->lockBackend->acquire($lock_name, $timeout)) {
      throw new \RuntimeException("Queue {$queue_id} is already running. Please wait for it to finish.");
    }

    $start_time = microtime(TRUE);
    $queue_worker_manager = \Drupal::service('plugin.manager.queue_worker');
    
    $queue = $this->queueFactory->get($queue_id);
    $worker = $queue_worker_manager->createInstance($queue_id);
    
    $processed = 0;
    try {
      while ($processed < $max_items && ($item = $queue->claimItem())) {
        // Check timeout
        $elapsed = microtime(TRUE) - $start_time;
        if ($elapsed > $timeout) {
          $this->logger->notice(
            'Queue @queue processing timeout after @elapsed seconds. Processed @count items.',
            [
              '@queue' => $queue_id,
              '@elapsed' => round($elapsed, 2),
              '@count' => $processed,
            ]
          );
          break;
        }

        $item_key = md5(serialize($item->data));
        
        // Get retry count for this item
        $retry_count = $this->state->get("job_hunter.queue_retry.{$queue_id}.{$item_key}", 0);
        
        // Check if item has exceeded retry limit
        if ($retry_count >= self::MAX_RETRY_ATTEMPTS) {
          // Suspend this item
          $this->suspendQueueItemInternal($queue_id, $item, $retry_count);
          $queue->deleteItem($item);
          $this->state->delete("job_hunter.queue_retry.{$queue_id}.{$item_key}");
          $this->logger->warning('Queue item suspended after @max failed attempts in @queue', [
            '@max' => self::MAX_RETRY_ATTEMPTS,
            '@queue' => $queue_id,
          ]);
          continue;
        }
        
        try {
          $worker->processItem($item->data);
          $queue->deleteItem($item);
          // Clear retry count on success
          $this->state->delete("job_hunter.queue_retry.{$queue_id}.{$item_key}");
          $processed++;
        }
        catch (\Exception $e) {
          // Increment retry count
          $retry_count++;
          $this->state->set("job_hunter.queue_retry.{$queue_id}.{$item_key}", $retry_count);
          
          // Release item back to queue on failure
          $queue->releaseItem($item);
          $this->logger->error('Queue @queue item failed (attempt @attempt/@max): @error', [
            '@queue' => $queue_id,
            '@attempt' => $retry_count,
            '@max' => self::MAX_RETRY_ATTEMPTS,
            '@error' => $e->getMessage(),
          ]);
          // Continue to next item
        }
      }
      
      return $processed;
    }
    finally {
      $this->lockBackend->release($lock_name);
    }
  }

  /**
   * Suspend a queue item after max retries (internal helper).
   *
   * Stores failed queue item data in the suspended items table for later
   * manual review or retry. Called automatically when a queue item exceeds
   * MAX_RETRY_ATTEMPTS.
   *
   * @param string $queue_id
   *   The queue ID where the item failed.
   * @param object $item
   *   The queue item object containing data to suspend.
   * @param int $retry_count
   *   Number of retry attempts that were made.
   */
  private function suspendQueueItemInternal(string $queue_id, $item, int $retry_count) {
    $this->database->insert('jobhunter_queue_suspended')
      ->fields([
        'queue_name' => $queue_id,
        'item_data' => serialize($item->data),
        'retry_count' => $retry_count,
        'suspended_at' => time(),
        'last_error' => 'Max retries exceeded',
      ])
      ->execute();
  }

  /**
   * Get user statistics for display on home page.
   *
   * @param int $user_id
   *   The user ID.
   *
   * @return array
   *   Array of statistics.
   */
  protected function getUserStatistics($user_id) {
    $stats = [
      'total_applications' => 0,
      'active_applications' => 0,
      'companies_tracked' => 0,
      'jobs_saved' => 0,
    ];

    try {
      // Count job postings
      $job_query = $this->entityTypeManager()
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'job_posting')
        ->condition('uid', $user_id);
      $stats['jobs_saved'] = $job_query->count()->execute();

      // Count companies
      $company_query = $this->entityTypeManager()
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'company')
        ->condition('uid', $user_id);
      $stats['companies_tracked'] = $company_query->count()->execute();

      // Count applications (if application content type exists)
      $application_query = $this->entityTypeManager()
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'application')
        ->condition('uid', $user_id);
      $stats['total_applications'] = $application_query->count()->execute();

      // Count active applications (status = in_progress, applied, etc.)
      $active_query = $this->entityTypeManager()
        ->getStorage('node')
        ->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'application')
        ->condition('uid', $user_id)
        ->condition('status', 1);
      $stats['active_applications'] = $active_query->count()->execute();
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('Error fetching user statistics: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $stats;
  }

  /**
   * Display queue management page for admins.
   *
   * @return array
   *   Render array for the queue management page.
   */
  public function queueManagement() {
    $database = \Drupal::database();
    
    // Get all queue items with related data
    $queue_items = [];
    foreach (array_keys(self::QUEUE_DEFINITIONS) as $queue_name) {
      $query = $database->select('queue', 'q')
        ->fields('q', ['item_id', 'data', 'expire', 'created'])
        ->condition('name', $queue_name);
      $results = $query->execute()->fetchAll();
      
      foreach ($results as $row) {
        $data = unserialize($row->data);
        $queue_items[] = [
          'item_id' => $row->item_id,
          'queue_name' => $queue_name,
          'queue_label' => self::QUEUE_DEFINITIONS[$queue_name]['name'],
          'created' => $row->created,
          'expire' => $row->expire,
          'data' => $data,
          'data_preview' => $this->getQueueItemPreview($data, $queue_name),
        ];
      }
    }
    
    // Sort by created date (newest first)
    usort($queue_items, function($a, $b) {
      return $b['created'] - $a['created'];
    });
    
    // Check database table health
    $table_health = $this->checkTableHealth();
    
    // Build content
    $content = [
      '#theme' => 'job_hunter_queue_management',
      '#queue_items' => $queue_items,
      '#queue_status' => $this->getQueueStatus(),
      '#table_health' => $table_health,
      '#attached' => [
        'library' => [
          'job_hunter/job-hunter-home',
          'job_hunter/queue-management',
          'job_hunter/queue-controls',
        ],
        'drupalSettings' => [
          'csrf_token' => \Drupal::csrfToken()->get('rest'),
          'jobHunterQueueControls' => [
            'runUrl' => $this->buildCsrfPathUrl('/jobhunter/queue/run'),
            'runAllUrl' => $this->buildCsrfPathUrl('/jobhunter/queue/run-all'),
            'pauseUrl' => $this->buildCsrfPathUrl('/jobhunter/queue/pause'),
            'resumeUrl' => $this->buildCsrfPathUrl('/jobhunter/queue/resume'),
            'retrySuspendedUrl' => $this->buildCsrfPathUrl('/jobhunter/queue/retry-suspended'),
          ],
        ],
      ],
    ];
    
    return $this->wrapWithNavigation($content, [
      'job_hunter/queue-management',
      'job_hunter/queue-controls',
    ]);
  }

  /**
   * Check health of all job_hunter database tables.
   *
   * @return array
   *   Health check results with overall status and table details.
   */
  private function checkTableHealth() {
    $schema = \Drupal::database()->schema();
    
    // Define expected tables and their critical columns
    $expected_tables = [
      'jobhunter_job_seeker' => ['id', 'uid', 'created', 'changed'],
      'jobhunter_job_history' => ['id', 'job_seeker_id', 'company', 'title'],
      'jobhunter_education_history' => ['id', 'job_seeker_id', 'institution', 'degree'],
      'jobhunter_resume_parsed_data' => ['id', 'uid', 'resume_file_id', 'parsed_data', 'status', 'raw_genai_response_core', 'raw_genai_response_experience'],
      'jobhunter_job_seeker_resumes' => ['id', 'job_seeker_id', 'file_id', 'extracted_text'],
      'jobhunter_tailored_resumes' => ['id', 'job_seeker_id', 'company', 'job_title'],
    ];
    
    $results = [];
    $all_healthy = TRUE;
    
    foreach ($expected_tables as $table_name => $required_columns) {
      $table_exists = $schema->tableExists($table_name);
      $columns_ok = TRUE;
      $missing_columns = [];
      
      if ($table_exists) {
        foreach ($required_columns as $column) {
          if (!$schema->fieldExists($table_name, $column)) {
            $columns_ok = FALSE;
            $missing_columns[] = $column;
          }
        }
      } else {
        $columns_ok = FALSE;
        $all_healthy = FALSE;
      }
      
      $is_healthy = $table_exists && $columns_ok;
      if (!$is_healthy) {
        $all_healthy = FALSE;
      }
      
      $results[$table_name] = [
        'exists' => $table_exists,
        'columns_ok' => $columns_ok,
        'missing_columns' => $missing_columns,
        'healthy' => $is_healthy,
      ];
    }
    
    return [
      'overall_healthy' => $all_healthy,
      'tables' => $results,
      'checked_at' => time(),
    ];
  }

  /**
   * Get a preview of queue item data.
   *
   * Extracts relevant information from queue item data for display in the
   * queue management interface. Handles different queue types with varying
   * data structures.
   *
   * @param object $data
   *   The queue item data object.
   * @param string $queue_name
   *   The name of the queue (currently unused but available for future logic).
   *
   * @return array
   *   Preview data array with keys like 'user', 'file', 'company', etc.
   */
  private function getQueueItemPreview($data, $queue_name) {
    $preview = [];
    
    if (isset($data->uid)) {
      $user = \Drupal\user\Entity\User::load($data->uid);
      $preview['user'] = $user ? $user->getAccountName() : "User #{$data->uid}";
    }
    
    if (isset($data->fid)) {
      $file = \Drupal\file\Entity\File::load($data->fid);
      $preview['file'] = $file ? $file->getFilename() : "File #{$data->fid}";
      $preview['file_id'] = $data->fid;
    }
    
    if (isset($data->resume_file_id)) {
      $file = \Drupal\file\Entity\File::load($data->resume_file_id);
      $preview['resume_file'] = $file ? $file->getFilename() : "File #{$data->resume_file_id}";
      $preview['resume_file_id'] = $data->resume_file_id;
    }
    
    if (isset($data->company_name)) {
      $preview['company'] = $data->company_name;
    }
    
    if (isset($data->job_title)) {
      $preview['job_title'] = $data->job_title;
    }
    
    if (isset($data->extracted_text)) {
      $preview['text_length'] = strlen($data->extracted_text);
    }
    
    return $preview;
  }

  /**
   * Delete a queue item (AJAX endpoint).
   *
   * Permanently removes an item from the active queue. This is an admin-only
   * operation for manual queue management. Use with caution as deleted items
   * cannot be recovered.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request containing 'item_id' and 'queue_name' parameters.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with format:
   *   - success: bool - Whether deletion succeeded
   *   - message: string - Result message
   */
  public function deleteQueueItem(Request $request) {
    // Check admin permission
    if (!$this->currentUser()->hasPermission('administer job application automation')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied',
      ], 403);
    }

    try {
      // Handle JSON request body
      $content = $request->getContent();
      if ($content) {
        $data = json_decode($content, TRUE);
        $item_id = $data['item_id'] ?? NULL;
        $queue_name = $data['queue_name'] ?? NULL;
      } else {
        $item_id = $request->request->get('item_id');
        $queue_name = $request->request->get('queue_name');
      }
      
      // Validate inputs
      $validated_item_id = $this->validateItemId($item_id);
      $validated_queue_name = $this->validateQueueId($queue_name);
      
      $this->logger->info('🔧 Queue Management: Attempting to delete queue item @item_id from queue @queue', [
        '@item_id' => $validated_item_id,
        '@queue' => $validated_queue_name,
      ]);
      
      $deleted = $this->database->delete('queue')
        ->condition('item_id', $validated_item_id)
        ->condition('name', $validated_queue_name)
        ->execute();
      
      if ($deleted) {
        $this->logger->info('✅ Queue Management: Successfully deleted queue item @item_id from queue @queue', [
          '@item_id' => $validated_item_id,
          '@queue' => $validated_queue_name,
        ]);
        
        return new JsonResponse([
          'success' => TRUE,
          'message' => 'Queue item deleted successfully',
        ]);
      } else {
        $this->logger->warning('⚠️ Queue Management: Queue item @item_id not found in queue @queue', [
          '@item_id' => $validated_item_id,
          '@queue' => $validated_queue_name,
        ]);
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'Queue item not found',
        ], 404);
      }
    }
    catch (BadRequestHttpException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $e->getMessage(),
      ], 400);
    }
    catch (\Exception $e) {
      $this->logger->error('Error deleting queue item: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Error deleting queue item: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Suspend a queue item (AJAX endpoint).
   * 
   * Moves an item from the active queue to the suspended queue table.
   * Suspended items won't be automatically processed until manually retried.
   */
  public function suspendQueueItem(Request $request) {
    // Check admin permission
    if (!$this->currentUser()->hasPermission('administer job application automation')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied',
      ], 403);
    }

    // Handle JSON request body
    $content = $request->getContent();
    if ($content) {
      $data = json_decode($content, TRUE);
      $item_id = $data['item_id'] ?? NULL;
      $queue_name = $data['queue_name'] ?? NULL;
      $item_data = $data['item_data'] ?? NULL;
    } else {
      $item_id = $request->request->get('item_id');
      $queue_name = $request->request->get('queue_name');
      $item_data = $request->request->get('item_data');
    }
    
    if (!$item_id || !$queue_name || !$item_data) {
      return new JsonResponse(['success' => false, 'message' => 'Missing parameters'], 400);
    }
    
    \Drupal::logger('job_hunter')->info('⏸️ Queue Management: Attempting to suspend queue item @item_id from queue @queue', [
      '@item_id' => $item_id,
      '@queue' => $queue_name,
    ]);
    
    try {
      $database = \Drupal::database();
      
      // First, verify the item exists in the queue
      $queue_item = $database->select('queue', 'q')
        ->fields('q')
        ->condition('item_id', $item_id)
        ->condition('name', $queue_name)
        ->execute()
        ->fetchObject();
      
      if (!$queue_item) {
        \Drupal::logger('job_hunter')->warning('⚠️ Queue Management: Queue item @item_id not found in queue @queue', [
          '@item_id' => $item_id,
          '@queue' => $queue_name,
        ]);
        return new JsonResponse([
          'success' => false,
          'message' => 'Queue item not found',
        ], 404);
      }
      
      // Get the retry count from state
      $item_key = md5(serialize($item_data));
      $state = \Drupal::state();
      $retry_count = $state->get("job_hunter.queue_retry.{$queue_name}.{$item_key}", 0);
      
      // Insert into suspended table
      $database->insert('jobhunter_queue_suspended')
        ->fields([
          'queue_name' => $queue_name,
          'item_data' => serialize($item_data),
          'suspended_time' => time(),
          'retry_count' => $retry_count,
          'error_message' => 'Manually suspended by user',
        ])
        ->execute();
      
      // Delete from active queue
      $database->delete('queue')
        ->condition('item_id', $item_id)
        ->condition('name', $queue_name)
        ->execute();
      
      \Drupal::logger('job_hunter')->info('✅ Queue Management: Successfully suspended queue item @item_id from queue @queue (retry count: @count)', [
        '@item_id' => $item_id,
        '@queue' => $queue_name,
        '@count' => $retry_count,
      ]);
      
      return new JsonResponse([
        'success' => true,
        'message' => 'Queue item suspended successfully',
      ]);
      
    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('❌ Error suspending queue item @item_id: @error', [
        '@item_id' => $item_id,
        '@error' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => false,
        'message' => 'Error suspending queue item: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Delete a file (AJAX endpoint).
   */
  public function deleteFile(Request $request) {
    // Check admin permission
    if (!$this->currentUser()->hasPermission('administer job application automation')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied',
      ], 403);
    }

    // Handle JSON request body
    $content = $request->getContent();
    if ($content) {
      $data = json_decode($content, TRUE);
      $file_id = $data['file_id'] ?? NULL;
    } else {
      $file_id = $request->request->get('file_id');
    }
    
    if (!$file_id) {
      return new JsonResponse(['success' => false, 'message' => 'Missing file ID'], 400);
    }
    
    \Drupal::logger('job_hunter')->info('🔧 Queue Management: Attempting to delete file ID @fid', [
      '@fid' => $file_id,
    ]);
    
    try {
      $file = \Drupal\file\Entity\File::load($file_id);
      if (!$file) {
        \Drupal::logger('job_hunter')->warning('⚠️ Queue Management: File ID @fid not found', [
          '@fid' => $file_id,
        ]);
        return new JsonResponse(['success' => false, 'message' => 'File not found'], 404);
      }
      
      $filename = $file->getFilename();
      $file_uri = $file->getFileUri();
      
      \Drupal::logger('job_hunter')->info('🗑️ Queue Management: Deleting file ID @fid (@filename) at @uri', [
        '@fid' => $file_id,
        '@filename' => $filename,
        '@uri' => $file_uri,
      ]);
      
      $file->delete();
      
      \Drupal::logger('job_hunter')->info('✅ Queue Management: Successfully deleted file ID @fid (@filename)', [
        '@fid' => $file_id,
        '@filename' => $filename,
      ]);
      
      return new JsonResponse([
        'success' => true,
        'message' => "File '{$filename}' deleted successfully",
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Error deleting file @fid: @error', [
        '@fid' => $file_id,
        '@error' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => false,
        'message' => 'Error deleting file: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Pause all queue processing.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success status.
   */
  public function pauseQueueProcessing() {
    $state = \Drupal::state();
    $state->set('job_hunter.queue_paused', TRUE);
    
    \Drupal::logger('job_hunter')->info('⏸️ Queue Management: Queue processing paused by admin');
    
    return new JsonResponse([
      'success' => true,
      'message' => 'Queue processing has been paused',
      'paused' => TRUE,
    ]);
  }

  /**
   * Resume all queue processing.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success status.
   */
  public function resumeQueueProcessing() {
    $state = \Drupal::state();
    $state->set('job_hunter.queue_paused', FALSE);
    
    \Drupal::logger('job_hunter')->info('▶️ Queue Management: Queue processing resumed by admin');
    
    return new JsonResponse([
      'success' => true,
      'message' => 'Queue processing has been resumed',
      'paused' => FALSE,
    ]);
  }

  /**
   * Get all suspended queue items.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with suspended items.
   */
  public function getSuspendedItems() {
    try {
      $connection = \Drupal::database();
      $query = $connection->select('jobhunter_queue_suspended', 'qs')
        ->fields('qs')
        ->orderBy('suspended_at', 'DESC');
      
      $results = $query->execute()->fetchAll();
      
      $items = [];
      foreach ($results as $row) {
        $items[] = [
          'id' => $row->id,
          'queue_name' => $row->queue_name,
          'queue_display_name' => self::QUEUE_DEFINITIONS[$row->queue_name]['name'] ?? $row->queue_name,
          'retry_count' => $row->retry_count,
          'suspended_at' => date('Y-m-d H:i:s', $row->suspended_at),
          'last_error' => $row->last_error,
        ];
      }
      
      return new JsonResponse([
        'success' => true,
        'items' => $items,
        'count' => count($items),
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Error fetching suspended items: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => false,
        'message' => 'Error fetching suspended items: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Retry a suspended queue item.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success status.
   */
  public function retrySuspendedItem(Request $request) {
    $content = $request->getContent();
    if ($content) {
      $data = json_decode($content, TRUE);
      $suspended_id = $data['id'] ?? NULL;
    }
    else {
      $suspended_id = $request->request->get('id');
    }
    
    if (!$suspended_id) {
      return new JsonResponse(['success' => false, 'message' => 'Missing suspended item ID'], 400);
    }
    
    try {
      $connection = \Drupal::database();
      
      // Get the suspended item
      $item = $connection->select('jobhunter_queue_suspended', 'qs')
        ->fields('qs')
        ->condition('id', $suspended_id)
        ->execute()
        ->fetchObject();
      
      if (!$item) {
        return new JsonResponse(['success' => false, 'message' => 'Suspended item not found'], 404);
      }
      
      // Re-add to queue
      $queue_name = $item->queue_name;
      $queue = \Drupal::queue($queue_name);
      $item_data = unserialize($item->item_data);
      $queue->createItem($item_data);
      
      // Clear retry counter in state
      $item_key = md5(serialize($item_data));
      $state = \Drupal::state();
      $state->delete("job_hunter.queue_retry.{$queue_name}.{$item_key}");
      
      // Delete from suspended table
      $connection->delete('jobhunter_queue_suspended')
        ->condition('id', $suspended_id)
        ->execute();
      
      \Drupal::logger('job_hunter')->info('🔄 Queue Management: Retry suspended item @id in queue @queue', [
        '@id' => $suspended_id,
        '@queue' => $queue_name,
      ]);
      
      return new JsonResponse([
        'success' => true,
        'message' => 'Item has been re-queued for processing',
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Error retrying suspended item @id: @error', [
        '@id' => $suspended_id,
        '@error' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => false,
        'message' => 'Error retrying suspended item: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Clear cached GenAI responses for a queue item.
   * 
   * Use this when retrying suspended items to force a fresh API call.
   */
  public function clearGenAiCache(Request $request) {
    $content = $request->getContent();
    if ($content) {
      $data = json_decode($content, TRUE);
      $queue_name = $data['queue_name'] ?? NULL;
      $item_data = $data['item_data'] ?? NULL;
    }
    else {
      return new JsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
    }
    
    if (!$queue_name || !$item_data) {
      return new JsonResponse(['success' => false, 'message' => 'Missing queue_name or item_data'], 400);
    }
    
    try {
      $ai_service = \Drupal::service('ai_conversation.ai_api_service');
      
      // Map queue names to operations and extract context
      $cleared = 0;
      
      switch ($queue_name) {
        case 'job_hunter_resume_tailoring':
          $cleared = $ai_service->clearCachedResponse(
            'job_hunter',
            'resume_tailoring',
            [
              'uid' => $item_data['uid'] ?? 0,
              'job_id' => $item_data['job_id'] ?? 0,
            ]
          );
          break;
          
        case 'job_hunter_cover_letter_tailoring':
          $cleared = $ai_service->clearCachedResponse(
            'job_hunter',
            'cover_letter_generation',
            [
              'uid' => $item_data['uid'] ?? 0,
              'job_id' => $item_data['job_id'] ?? 0,
            ]
          );
          break;
          
        case 'job_hunter_genai_parsing':
          $cleared = $ai_service->clearCachedResponse(
            'job_hunter',
            'resume_parsing',
            [
              'uid' => $item_data['uid'] ?? 0,
              'filename' => $item_data['filename'] ?? '',
            ]
          );
          break;
          
        case 'job_hunter_job_posting_parsing':
          $cleared = $ai_service->clearCachedResponse(
            'job_hunter',
            'job_posting_parsing',
            [
              'job_id' => $item_data['job_id'] ?? 0,
            ]
          );
          break;
          
        default:
          return new JsonResponse([
            'success' => false,
            'message' => 'Unknown queue type: ' . $queue_name,
          ], 400);
      }
      
      \Drupal::logger('job_hunter')->info('🗑️ Queue Management: Cleared @count cached GenAI response(s) for @queue', [
        '@count' => $cleared,
        '@queue' => $queue_name,
      ]);
      
      return new JsonResponse([
        'success' => true,
        'cleared' => $cleared,
        'message' => $cleared > 0 
          ? "Cleared {$cleared} cached GenAI response(s). Next run will call AI again." 
          : 'No cached responses found to clear.',
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Error clearing GenAI cache: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => false,
        'message' => 'Error clearing cache: ' . $e->getMessage(),
      ], 500);
    }
  }

}
