<?php

namespace Drupal\copilot_agent_tracker\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\copilot_agent_tracker\Service\AuditLogger;
use Drupal\copilot_agent_tracker\Service\HealthAggregator;
use Drupal\copilot_agent_tracker\Form\AdminSettingsForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for LangGraph Console admin operations.
 */
final class LangGraphConsoleAdminController extends ControllerBase {

  public function __construct(
    private readonly AuditLogger $auditLogger,
    private readonly HealthAggregator $healthAggregator,
    private readonly FormBuilderInterface $formBuilder,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly MessengerInterface $messenger,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('copilot_agent_tracker.audit_logger'),
      $container->get('copilot_agent_tracker.health_aggregator'),
      $container->get('form_builder'),
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('messenger'),
      $container->get('renderer'),
    );
  }

  /**
   * Settings form page.
   *
   * @return array<string, mixed>
   *   Render array with settings form.
   */
  public function settings(): array {
    return [
      '#type' => 'page',
      '#title' => $this->t('LangGraph Console Settings'),
      'form' => $this->formBuilder->getForm(AdminSettingsForm::class),
    ];
  }

  /**
   * Permissions matrix and team assignment page.
   *
   * @return array<string, mixed>
   *   Render array with permissions table.
   */
  public function permissions(): array {
    try {
      $permissions = $this->getPermissionsData();

      return [
        '#type' => 'page',
        '#title' => $this->t('Console Permissions & Team Assignment'),
        'permissions_table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Permission'),
            $this->t('Description'),
            $this->t('Roles'),
            $this->t('Teams'),
          ],
          '#rows' => $this->formatPermissionsRows($permissions),
          '#attributes' => ['class' => ['permissions-matrix']],
          '#empty' => $this->t('No permissions defined.'),
        ],
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('copilot_agent_tracker')
        ->error('Error loading permissions: @error', ['@error' => $e->getMessage()]);
      return [
        '#type' => 'page',
        '#title' => $this->t('Console Permissions & Team Assignment'),
        'error' => [
          '#type' => 'markup',
          '#markup' => '<div class="messages messages--error">' .
            $this->t('Error loading permissions data. Check logs for details.') .
            '</div>',
        ],
      ];
    }
  }

  /**
   * Audit log table page.
   *
   * @return array<string, mixed>
   *   Render array with audit log table.
   */
  public function auditLog(Request $request): array {
    try {
      // Get filter values from query parameters.
      $operator_id = $request->query->get('operator_id', NULL);
      $action = $request->query->get('action', NULL);
      $resource_id = $request->query->get('resource_id', NULL);

      $filters = [];
      if (!empty($operator_id)) {
        $filters['operator_id'] = $operator_id;
      }
      if (!empty($action)) {
        $filters['action'] = $action;
      }
      if (!empty($resource_id)) {
        $filters['resource_id'] = $resource_id;
      }

      // Query audit entries (limit 100 per page).
      $limit = 100;
      $offset = max(0, (int) $request->query->get('page', 0)) * $limit;

      $entries = $this->auditLogger->query($filters, $limit, $offset);
      $count = $this->auditLogger->count($filters);

      $rows = [];
      foreach ($entries as $entry) {
        $rows[] = [
          $entry['timestamp'],
          $entry['operator_id'],
          $entry['action'],
          $entry['resource_id'] ?? '--',
          strlen($entry['before_value'] ?? '') > 0 ? $this->t('Yes') : $this->t('No'),
          strlen($entry['after_value'] ?? '') > 0 ? $this->t('Yes') : $this->t('No'),
          $entry['csrf_verified'] ? $this->t('Yes') : $this->t('No'),
        ];
      }

      $build = [
        '#type' => 'page',
        '#title' => $this->t('Audit Log'),
        'filters' => [
          '#type' => 'fieldset',
          '#title' => $this->t('Filter audit log'),
          '#collapsed' => FALSE,
          'operator_id' => [
            '#type' => 'textfield',
            '#title' => $this->t('Operator ID'),
            '#default_value' => $operator_id,
            '#size' => 20,
          ],
          'action' => [
            '#type' => 'textfield',
            '#title' => $this->t('Action'),
            '#default_value' => $action,
            '#size' => 30,
          ],
          'resource_id' => [
            '#type' => 'textfield',
            '#title' => $this->t('Resource ID'),
            '#default_value' => $resource_id,
            '#size' => 20,
          ],
          'submit' => [
            '#type' => 'submit',
            '#value' => $this->t('Filter'),
          ],
        ],
        'table' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Timestamp'),
            $this->t('Operator'),
            $this->t('Action'),
            $this->t('Resource'),
            $this->t('Before'),
            $this->t('After'),
            $this->t('CSRF OK'),
          ],
          '#rows' => $rows,
          '#attributes' => ['class' => ['audit-log-table']],
          '#empty' => $this->t('No audit entries found.'),
        ],
        'pager' => [
          '#type' => 'pager',
        ],
      ];

      if ($count > $limit) {
        $build['count'] = [
          '#type' => 'markup',
          '#markup' => '<p>' . $this->t('Showing @from to @to of @total entries.',
            [
              '@from' => $offset + 1,
              '@to' => min($offset + $limit, $count),
              '@total' => $count,
            ]) . '</p>',
        ];
      }

      return $build;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('copilot_agent_tracker')
        ->error('Error loading audit log: @error', ['@error' => $e->getMessage()]);
      return [
        '#type' => 'page',
        '#title' => $this->t('Audit Log'),
        'error' => [
          '#type' => 'markup',
          '#markup' => '<div class="messages messages--error">' .
            $this->t('Error loading audit log. Check logs for details.') .
            '</div>',
        ],
      ];
    }
  }

  /**
   * Health dashboard page.
   *
   * @return array<string, mixed>
   *   Render array with health dashboard.
   */
  public function health(): array {
    try {
      $health_data = $this->healthAggregator->collect();

      $orchestrator = $health_data['orchestrator_status'] ?? [];
      $agents = $health_data['agents'] ?? [];
      $freshness = $health_data['data_freshness'] ?? [];

      // Check if COPILOT_HQ_ROOT is accessible.
      $hq_root = getenv('COPILOT_HQ_ROOT');
      if (empty($hq_root) || !is_dir($hq_root)) {
        $this->messenger()->addWarning($this->t(
          'COPILOT_HQ_ROOT not configured or missing. Health data may be incomplete.'
        ));
      }

      $agent_rows = [];
      foreach ($agents as $agent) {
        $agent_rows[] = [
          $agent['seat_id'],
          $agent['status'],
          $agent['inbox_size'],
          $agent['last_modified'] ? date('Y-m-d H:i:s', $agent['last_modified']) : '--',
        ];
      }

      return [
        '#type' => 'page',
        '#title' => $this->t('System Health Dashboard'),
        'orchestrator' => [
          '#type' => 'fieldset',
          '#title' => $this->t('Orchestrator Status'),
          'markup' => [
            '#type' => 'markup',
            '#markup' => $this->renderer->render([
              '#type' => 'item_list',
              '#items' => [
                $this->t('Status: <strong>@status</strong>',
                  ['@status' => $orchestrator['status'] ?? 'unknown']),
                $this->t('Last tick: @ts',
                  ['@ts' => isset($orchestrator['last_tick_timestamp'])
                    ? date('Y-m-d H:i:s', $orchestrator['last_tick_timestamp'])
                    : 'unknown']),
                $this->t('Tick variance: @var%',
                  ['@var' => $orchestrator['tick_frequency_variance'] ?? 'N/A']),
                $this->t('Parity OK: @ok',
                  ['@ok' => $orchestrator['parity_ok'] ? 'Yes' : 'No']),
              ],
              '#attributes' => ['class' => ['orchestrator-status']],
            ]),
          ],
        ],
        'agents' => [
          '#type' => 'fieldset',
          '#title' => $this->t('Agent Status'),
          'table' => [
            '#type' => 'table',
            '#header' => [
              $this->t('Seat ID'),
              $this->t('Status'),
              $this->t('Inbox Size'),
              $this->t('Last Modified'),
            ],
            '#rows' => $agent_rows,
            '#attributes' => ['class' => ['agents-table']],
            '#empty' => $this->t('No agents found.'),
          ],
        ],
        'freshness' => [
          '#type' => 'fieldset',
          '#title' => $this->t('Data Freshness'),
          'markup' => [
            '#type' => 'markup',
            '#markup' => $this->renderer->render([
              '#type' => 'item_list',
              '#items' => [
                $this->t('Ticks fresh: @fresh (age: @age seconds)',
                  [
                    '@fresh' => $freshness['ticks_fresh'] ? 'Yes' : 'No',
                    '@age' => $freshness['ticks_age_seconds'] ?? 'N/A',
                  ]),
                $this->t('Feature progress fresh: @fresh (age: @age seconds)',
                  [
                    '@fresh' => $freshness['feature_progress_fresh'] ? 'Yes' : 'No',
                    '@age' => $freshness['feature_progress_age_seconds'] ?? 'N/A',
                  ]),
                $this->t('Executor failures: @count',
                  ['@count' => $freshness['executor_failures_count'] ?? 0]),
              ],
              '#attributes' => ['class' => ['data-freshness']],
            ]),
          ],
        ],
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('copilot_agent_tracker')
        ->error('Error loading health dashboard: @error', ['@error' => $e->getMessage()]);
      return [
        '#type' => 'page',
        '#title' => $this->t('System Health Dashboard'),
        'error' => [
          '#type' => 'markup',
          '#markup' => '<div class="messages messages--error">' .
            $this->t('Error loading health data. Check logs for details.') .
            '</div>',
        ],
      ];
    }
  }

  /**
   * Health data as JSON (REST endpoint).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with health data.
   */
  public function healthJson(): JsonResponse {
    try {
      $health_data = $this->healthAggregator->collect();

      return new JsonResponse([
        'status' => 'success',
        'data' => $health_data,
        'timestamp' => time(),
      ]);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('copilot_agent_tracker')
        ->error('Error collecting health data: @error', ['@error' => $e->getMessage()]);

      return new JsonResponse([
        'status' => 'error',
        'error' => $e->getMessage(),
        'timestamp' => time(),
      ], 500);
    }
  }

  /**
   * Navigation settings form page.
   *
   * @return array<string, mixed>
   *   Render array with navigation form.
   */
  public function navigation(): array {
    $form = [
      '#type' => 'fieldset',
      '#title' => $this->t('Console Navigation Settings'),
      'markup' => [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('Navigation settings are managed through the main admin interface.') . '</p>',
      ],
    ];

    return [
      '#type' => 'page',
      '#title' => $this->t('Navigation Settings'),
      'form' => $form,
    ];
  }

  /**
   * Get permissions data.
   *
   * @return array<string, mixed>
   *   Permissions data structure.
   */
  private function getPermissionsData(): array {
    // Return hardcoded permissions structure for now.
    return [
      [
        'permission' => 'administer console settings',
        'description' => 'Configure LangGraph console settings, permissions, audit log, and view system health.',
        'roles' => 'administrator',
        'teams' => 'ceo-copilot-2',
      ],
      [
        'permission' => 'administer copilot agent tracker',
        'description' => 'View agent status dashboards and manage agent tracking configuration.',
        'roles' => 'administrator',
        'teams' => 'All teams',
      ],
      [
        'permission' => 'post copilot agent telemetry',
        'description' => 'Allow posting agent status/events to the internal telemetry endpoint.',
        'roles' => 'authenticated',
        'teams' => 'All agents',
      ],
    ];
  }

  /**
   * Format permissions data for table rendering.
   *
   * @param array<int, array<string, mixed>> $permissions
   *   Permissions data.
   *
   * @return array<int, array<string, string>>
   *   Formatted table rows.
   */
  private function formatPermissionsRows(array $permissions): array {
    $rows = [];
    foreach ($permissions as $perm) {
      $rows[] = [
        $perm['permission'],
        $perm['description'],
        $perm['roles'],
        $perm['teams'],
      ];
    }
    return $rows;
  }

}
