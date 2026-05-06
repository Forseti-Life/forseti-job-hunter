<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\job_hunter\Service\ErrorQueueService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for error queue admin interface.
 */
class ErrorQueueController extends ControllerBase {

  /**
   * The error queue service.
   *
   * @var \Drupal\job_hunter\Service\ErrorQueueService
   */
  protected $errorQueueService;

  /**
   * Constructs an ErrorQueueController object.
   *
   * @param \Drupal\job_hunter\Service\ErrorQueueService $error_queue_service
   *   The error queue service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    ErrorQueueService $error_queue_service,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->errorQueueService = $error_queue_service;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('job_hunter.error_queue_service'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * List all errors in the queue.
   */
  public function listErrors() {
    // Get all errors from database
    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'error_queue')
      ->sort('created', 'DESC')
      ->pager(25)
      ->accessCheck(TRUE);

    $error_ids = $query->execute();
    $errors = $this->entityTypeManager->getStorage('node')->loadMultiple($error_ids);

    // Build table rows
    $rows = [];
    foreach ($errors as $error) {
      $message = trim($error->get('field_error_message')->value ?? '');
      if ($message === '') {
        $message = $this->t('No message');
      }
      $message_preview = (strlen($message) > 50)
        ? substr($message, 0, 50) . '...'
        : $message;

      $rows[] = [
        'message' => [
          'data' => [
            '#type' => 'link',
            '#title' => $message_preview,
            '#url' => \Drupal\Core\Url::fromRoute('job_hunter.error_queue_view', ['error_id' => $error->id()]),
          ],
        ],
        'type' => $error->get('field_error_type')->value ?? '-',
        'priority' => [
          'data' => $this->_priorityBadge($error->get('field_priority')->value ?? 'medium'),
        ],
        'status' => $error->get('field_status')->value ?? 'new',
        'fixed' => $error->get('field_fixed')->value ? '✓' : '○',
        'created' => \Drupal::service('date.formatter')->format(
          $error->getCreatedTime(),
          'short'
        ),
        'actions' => [
          'data' => [
            '#type' => 'dropbutton',
            '#links' => [
              'view' => [
                'title' => $this->t('View'),
                'url' => \Drupal\Core\Url::fromRoute('job_hunter.error_queue_view', ['error_id' => $error->id()]),
              ],
              'mark_fixed' => [
                'title' => $this->t('Mark Fixed'),
                'url' => \Drupal\Core\Url::fromRoute('job_hunter.error_queue_mark_fixed', ['error_id' => $error->id()]),
              ],
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => \Drupal\Core\Url::fromRoute('entity.node.delete_form', ['node' => $error->id()]),
              ],
            ],
          ],
        ],
      ];
    }

    // Build the table
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        'message' => $this->t('Error Message'),
        'type' => $this->t('Type'),
        'priority' => $this->t('Priority'),
        'status' => $this->t('Status'),
        'fixed' => $this->t('Fixed'),
        'created' => $this->t('Created'),
        'actions' => $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No errors in queue.'),
      '#attributes' => ['class' => ['error-queue-table']],
    ];

    // Add some stats
    $unfixed_count = $this->errorQueueService->getUnfixedErrorCount();
    $unresolved_count = $this->errorQueueService->getUnresolvedErrorCount();

    $build['stats'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['error-queue-stats']],
      [
        '#markup' => $this->t(
          '<p><strong>Unfixed Errors:</strong> @unfixed | <strong>Unresolved:</strong> @unresolved</p>',
          ['@unfixed' => $unfixed_count, '@unresolved' => $unresolved_count]
        ),
      ],
    ];

    // Add pager
    $build['pager'] = ['#type' => 'pager'];

    $build['#attached']['library'][] = 'job_hunter/error-queue-styling';

    return $build;
  }

  /**
   * View error details.
   */
  public function viewError($error_id) {
    $error = $this->entityTypeManager->getStorage('node')->load($error_id);

    if (!$error || $error->bundle() !== 'error_queue') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['error-details-container']],
    ];

    // Error title and basics
    $build['header'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Error Information'),
      '#attributes' => ['class' => ['error-header']],
    ];

    $build['header']['message'] = [
      '#type' => 'item',
      '#title' => $this->t('Error Message'),
      '#markup' => $error->get('field_error_message')->value,
    ];

    $build['header']['type'] = [
      '#type' => 'item',
      '#title' => $this->t('Error Type'),
      '#markup' => $error->get('field_error_type')->value ?? '-',
    ];

    $build['header']['priority'] = [
      '#type' => 'item',
      '#title' => $this->t('Priority'),
      '#markup' => $this->_priorityBadge($error->get('field_priority')->value ?? 'medium'),
    ];

    $build['header']['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Status'),
      '#markup' => $error->get('field_status')->value ?? 'new',
    ];

    $build['header']['created'] = [
      '#type' => 'item',
      '#title' => $this->t('Created'),
      '#markup' => \Drupal::service('date.formatter')->format(
        $error->getCreatedTime(),
        'medium'
      ),
    ];

    // Related entities
    if (!$error->get('field_user_ref')->isEmpty()) {
      $user = $error->get('field_user_ref')->entity;
      $build['header']['user'] = [
        '#type' => 'item',
        '#title' => $this->t('Affected User'),
        '#markup' => $user ? $user->getDisplayName() : '-',
      ];
    }

    if (!$error->get('field_company_ref')->isEmpty()) {
      $company = $error->get('field_company_ref')->entity;
      $build['header']['company'] = [
        '#type' => 'item',
        '#title' => $this->t('Related Company'),
        '#markup' => $company ? $company->getTitle() : '-',
      ];
    }

    // Technical details
    if (!$error->get('field_error_data')->isEmpty()) {
      $error_data = $error->get('field_error_data')->value;
      $build['technical'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Technical Details'),
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
        '#attributes' => ['class' => ['error-technical']],
      ];

      $build['technical']['data'] = [
        '#type' => 'item',
        '#markup' => '<pre>' . htmlspecialchars($error_data) . '</pre>',
      ];
    }

    // Actions
    $build['actions'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Actions'),
      '#attributes' => ['class' => ['error-actions']],
    ];

    $build['actions']['mark_fixed'] = [
      '#type' => 'link',
      '#title' => $this->t('Mark as Fixed'),
      '#url' => \Drupal\Core\Url::fromRoute('job_hunter.error_queue_mark_fixed', ['error_id' => $error->id()]),
      '#attributes' => ['class' => ['button', 'button-primary']],
    ];

    $build['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to Queue'),
      '#url' => \Drupal\Core\Url::fromRoute('job_hunter.error_queue_list'),
      '#attributes' => ['class' => ['button']],
    ];

    $build['#attached']['library'][] = 'job_hunter/error-queue-styling';

    return $build;
  }

  /**
   * Mark error as fixed.
   */
  public function markFixed($error_id) {
    $error = $this->entityTypeManager->getStorage('node')->load($error_id);

    if (!$error || $error->bundle() !== 'error_queue') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    try {
      $this->errorQueueService->markErrorFixed($error, 'resolved');
      $this->messenger()->addStatus($this->t('Error marked as fixed.'));
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to mark error as fixed: @error', ['@error' => $e->getMessage()]));
    }

    return $this->redirect('job_hunter.error_queue_list');
  }

  /**
   * Get AJAX error count.
   *
   * Returns JSON with current error counts.
   */
  public function getErrorCount() {
    $unfixed = $this->errorQueueService->getUnfixedErrorCount();
    $unresolved = $this->errorQueueService->getUnresolvedErrorCount();

    return new JsonResponse([
      'unfixed' => $unfixed,
      'unresolved' => $unresolved,
    ]);
  }

  /**
   * Generate priority badge HTML.
   *
   * @param string $priority
   *   Priority level: low, medium, high, critical.
   *
   * @return array
   *   Render array for badge.
   */
  private function _priorityBadge($priority) {
    $classes = ['priority-badge', 'priority-' . $priority];
    $labels = [
      'low' => $this->t('Low'),
      'medium' => $this->t('Medium'),
      'high' => $this->t('High'),
      'critical' => $this->t('Critical'),
    ];

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $labels[$priority] ?? $priority,
      '#attributes' => ['class' => $classes],
    ];
  }

}
