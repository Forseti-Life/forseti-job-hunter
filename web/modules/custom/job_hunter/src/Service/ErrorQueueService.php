<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Service for managing the error queue.
 *
 * Provides centralized error logging and queue management for all automation
 * workflows. Errors are stored as error_queue nodes for admin review.
 */
class ErrorQueueService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs an ErrorQueueService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Log an error to the queue.
   *
   * Creates an error_queue node with the provided error details for admin review.
   *
   * @param string $error_message
   *   Human-readable error message.
   * @param string $error_type
   *   Error type: 'authentication', 'scraping', 'submission', 'technical', 'validation'.
   * @param \Drupal\node\NodeInterface|null $company
   *   Company node if applicable.
   * @param \Drupal\user\UserInterface|null $user
   *   User entity if applicable.
   * @param array $error_data
   *   Technical error details (array will be JSON-encoded).
   * @param string $priority
   *   Priority level: 'low', 'medium', 'high', 'critical'.
   *
   * @return \Drupal\node\NodeInterface
   *   The created error node.
   *
   * @throws \Exception
   *   If error node creation fails.
   */
  public function logError(
    $error_message,
    $error_type,
    NodeInterface $company = NULL,
    UserInterface $user = NULL,
    array $error_data = [],
    $priority = 'medium'
  ): NodeInterface {

    // Ensure error type is valid
    $valid_types = ['authentication', 'scraping', 'submission', 'technical', 'validation'];
    if (!in_array($error_type, $valid_types)) {
      $error_type = 'technical';
    }

    // Ensure priority is valid
    $valid_priorities = ['low', 'medium', 'high', 'critical'];
    if (!in_array($priority, $valid_priorities)) {
      $priority = 'medium';
    }

    // Create error node
    $error_node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'error_queue',
      'title' => substr($error_message, 0, 100),
      'field_error_message' => [
        'value' => $error_message,
        'format' => 'plain_text',
      ],
      'field_error_type' => $error_type,
      'field_priority' => $priority,
      'field_status' => 'new',
      'field_fixed' => FALSE,
    ]);

    // Add JSON error data if provided
    if (!empty($error_data)) {
      $error_node->set('field_error_data', json_encode($error_data, JSON_PRETTY_PRINT));
    }

    // Add optional references
    if ($company) {
      $error_node->set('field_company_ref', $company->id());
    }

    if ($user) {
      $error_node->set('field_user_ref', $user->id());
    }

    try {
      $error_node->save();
      $this->loggerFactory->get('job_hunter')->warning(
        'Error logged (Type: @type, Priority: @priority): @message',
        [
          '@type' => $error_type,
          '@priority' => $priority,
          '@message' => substr($error_message, 0, 100),
        ]
      );
      return $error_node;
    } catch (\Exception $e) {
      $this->loggerFactory->get('job_hunter')->critical(
        'Failed to create error node: @error',
        ['@error' => $e->getMessage()]
      );
      throw $e;
    }
  }

  /**
   * Get count of unresolved errors.
   *
   * Returns count of error_queue nodes with status 'new' or 'in_progress'.
   *
   * @return int
   *   Count of unresolved errors.
   */
  public function getUnresolvedErrorCount(): int {
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'error_queue')
      ->condition('field_status', ['new', 'in_progress'], 'IN');

    return (int) $query->count()->execute();
  }

  /**
   * Get count of unfixed errors (field_fixed = FALSE).
   *
   * @return int
   *   Count of unfixed errors.
   */
  public function getUnfixedErrorCount(): int {
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'error_queue')
      ->condition('field_fixed', FALSE);

    return (int) $query->count()->execute();
  }

  /**
   * Get recent errors.
   *
   * @param int $limit
   *   Maximum number of errors to return.
   * @param string|null $error_type
   *   Optional filter by error type.
   * @param string|null $priority
   *   Optional filter by priority.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Array of error nodes.
   */
  public function getRecentErrors(
    $limit = 10,
    $error_type = NULL,
    $priority = NULL
  ): array {
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'error_queue')
      ->sort('created', 'DESC')
      ->range(0, $limit);

    // Add optional filters
    if ($error_type) {
      $query->condition('field_error_type', $error_type);
    }

    if ($priority) {
      $query->condition('field_priority', $priority);
    }

    $error_ids = $query->execute();
    return $this->entityTypeManager->getStorage('node')->loadMultiple($error_ids);
  }

  /**
   * Get errors by status.
   *
   * @param string $status
   *   Filter by status: 'new', 'in_progress', 'resolved'.
   * @param int $limit
   *   Maximum number of errors to return.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Array of error nodes with specified status.
   */
  public function getErrorsByStatus($status, $limit = 50): array {
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'error_queue')
      ->condition('field_status', $status)
      ->sort('created', 'DESC')
      ->range(0, $limit);

    $error_ids = $query->execute();
    return $this->entityTypeManager->getStorage('node')->loadMultiple($error_ids);
  }

  /**
   * Get errors for a specific user.
   *
   * @param \Drupal\user\UserInterface $user
   *   User to filter errors for.
   * @param int $limit
   *   Maximum number of errors to return.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Array of error nodes for the user.
   */
  public function getUserErrors(UserInterface $user, $limit = 20): array {
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'error_queue')
      ->condition('field_user_ref', $user->id())
      ->sort('created', 'DESC')
      ->range(0, $limit);

    $error_ids = $query->execute();
    return $this->entityTypeManager->getStorage('node')->loadMultiple($error_ids);
  }

  /**
   * Get errors for a specific company.
   *
   * @param \Drupal\node\NodeInterface $company
   *   Company node to filter errors for.
   * @param int $limit
   *   Maximum number of errors to return.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Array of error nodes for the company.
   */
  public function getCompanyErrors(NodeInterface $company, $limit = 20): array {
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'error_queue')
      ->condition('field_company_ref', $company->id())
      ->sort('created', 'DESC')
      ->range(0, $limit);

    $error_ids = $query->execute();
    return $this->entityTypeManager->getStorage('node')->loadMultiple($error_ids);
  }

  /**
   * Mark an error as fixed.
   *
   * @param \Drupal\node\NodeInterface $error
   *   Error node to mark as fixed.
   * @param string $status
   *   Optional status to set: 'new', 'in_progress', 'resolved'.
   *
   * @throws \Exception
   *   If update fails.
   */
  public function markErrorFixed(NodeInterface $error, $status = 'resolved'): void {
    try {
      $error->set('field_fixed', TRUE);
      $error->set('field_status', $status);
      $error->save();
      $this->loggerFactory->get('job_hunter')->info(
        'Error @id marked as fixed.',
        ['@id' => $error->id()]
      );
    } catch (\Exception $e) {
      $this->loggerFactory->get('job_hunter')->error(
        'Failed to mark error as fixed: @error',
        ['@error' => $e->getMessage()]
      );
      throw $e;
    }
  }

  /**
   * Delete old errors.
   *
   * Removes resolved errors older than specified days.
   *
   * @param int $days
   *   Delete resolved errors older than this many days.
   *
   * @return int
   *   Number of errors deleted.
   */
  public function deleteOldResolvedErrors($days = 30): int {
    $cutoff = \Drupal::time()->getRequestTime() - ($days * 86400);

    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'error_queue')
      ->condition('field_status', 'resolved')
      ->condition('created', $cutoff, '<');

    $error_ids = $query->execute();

    if (empty($error_ids)) {
      return 0;
    }

    $errors = $this->entityTypeManager->getStorage('node')->loadMultiple($error_ids);
    $this->entityTypeManager->getStorage('node')->delete($errors);

    $this->loggerFactory->get('job_hunter')->info(
      'Deleted @count resolved errors older than @days days.',
      ['@count' => count($error_ids), '@days' => $days]
    );

    return count($error_ids);
  }

}
