<?php

namespace Drupal\copilot_agent_tracker\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\copilot_agent_tracker\Repository\DashboardRepository;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\Markup;
use Drupal\copilot_agent_tracker\Form\AgentDashboardFilterForm;
use Drupal\copilot_agent_tracker\Form\ComposeAgentMessageForm;
use Drupal\copilot_agent_tracker\Form\InboxReplyForm;
use Drupal\copilot_agent_tracker\Form\OrgAutomationToggleForm;
use Drupal\copilot_agent_tracker\Form\ReleaseManagementCycleForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Admin dashboard for agent/session tracking.
 */
final class DashboardController extends ControllerBase {

  // LangGraph telemetry file paths — resolved at runtime via COPILOT_HQ_ROOT env.
  // Use $this->langgraphPath('relative/path') instead of these constants directly.
  const LANGGRAPH_TICKS_RELATIVE = 'inbox/responses/langgraph-ticks.jsonl';
  const LANGGRAPH_PARITY_RELATIVE = 'inbox/responses/langgraph-parity-latest.json';
  const LANGGRAPH_FEATURE_PROGRESS_RELATIVE = 'dashboards/FEATURE_PROGRESS.md';
  const RELEASE_CYCLE_CONTROL_FILE_DEFAULT = '/var/tmp/copilot-sessions-hq/release-cycle-control.json';
  const JOBHUNTER_CIO_TARGET_EMAIL = 'keith.aumiller@stlouisintegration.com';

  public function __construct(
    private readonly DashboardRepository $repository,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly StateInterface $state,
    private readonly FormBuilderInterface $dashboardFormBuilder,
    private readonly RequestStack $dashboardRequestStack,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('copilot_agent_tracker.dashboard_repository'),
      $container->get('date.formatter'),
      $container->get('state'),
      $container->get('form_builder'),
      $container->get('request_stack'),
      $container->get('csrf_token'),
      $container->get('database'),
    );
  }

  /**
   * Dashboard listing all agents.
   */
  public function dashboard(): array {
    $token = (string) $this->state->get('copilot_agent_tracker.telemetry_token', '');

    if (!$this->repository->agentsTableExists()) {
      $this->messenger()->addWarning($this->t('Copilot Agent Tracker database tables are missing. Run database updates (for example: drush updb) to create required tables.'));
      return [
        '#type' => 'container',
        'help' => [
          '#markup' => '<p>Copilot Agent Tracker is enabled, but required database tables have not been created yet.</p>'
            . '<p>Run database updates, then reload this page.</p>'
            . ($token ? '<p><strong>Telemetry token</strong> (send as <code>X-Copilot-Agent-Tracker-Token</code>): <code>' . $token . '</code></p>' : ''),
        ],
        '#cache' => [
          'max-age' => 0,
        ],
      ];
    }

    $request = $this->dashboardRequestStack->getCurrentRequest();
    $selected = [
      'product' => (string) ($request?->query->get('product') ?? ''),
      'role' => (string) ($request?->query->get('role') ?? ''),
    ];

    $rows = $this->repository->getAllAgentsOrdered();

    $products = [];
    foreach ($rows as $row) {
      $website = (string) ($row->website ?? '');
      $module = (string) ($row->module ?? '');
      $product_key = $website . '::' . $module;
      $products[$product_key] = ($website ?: '-') . ' / ' . ($module ?: '-');

      $role = trim((string) ($row->role ?? ''));
      if ($role !== '') {
        $roles[$role] = $role;
      }
    }
    asort($products);
    ksort($roles);

    $filter_form = $this->dashboardFormBuilder->getForm(AgentDashboardFilterForm::class, [
      'products' => $products,
      'roles' => $roles,
    ], $selected);

    $table_rows = [];
    $visible_agents = [];
    foreach ($rows as $agent_id => $row) {
      $website = (string) ($row->website ?? '');
      $module = (string) ($row->module ?? '');
      $status = (string) ($row->status ?? '');
      $role = (string) ($row->role ?? '');

      if ($selected['product'] !== '' && ($website . '::' . $module) !== $selected['product']) {
        continue;
      }
      if ($selected['role'] !== '' && $role !== $selected['role']) {
        continue;
      }

      $meta = [];
      if (!empty($row->metadata)) {
        try {
          $meta = Json::decode((string) $row->metadata) ?? [];
        }
        catch (\Throwable) {
          $meta = [];
        }
      }

      $active_item_id = '';
      if (is_array($meta) && isset($meta['active_inbox'])) {
        $active_item_id = trim((string) ($meta['active_inbox'] ?? ''));
      }

      $inbox_count = (int) ($meta['inbox_count'] ?? 0);
      $next_item_id = '';
      if (is_array($meta) && isset($meta['next_inbox'])) {
        $next_item_id = trim((string) ($meta['next_inbox'] ?? ''));
      }
      $next_inbox_roi = (int) ($meta['next_inbox_effective_roi'] ?? ($meta['next_inbox_roi'] ?? 0));

      $visible_agents[] = [
        'agent_id' => (string) $agent_id,
        'website' => $website,
        'module' => $module,
        'role' => $role,
        'status' => $status,
        'current_action' => (string) ($row->current_action ?? ''),
        'last_seen' => (int) ($row->last_seen ?? 0),
        'active_item_id' => $active_item_id,
        'inbox_count' => $inbox_count,
        'next_item_id' => $next_item_id,
        'next_inbox_roi' => $next_inbox_roi,
        'meta' => is_array($meta) ? $meta : [],
      ];

      $table_rows[] = [
        Link::fromTextAndUrl($agent_id, Url::fromRoute('copilot_agent_tracker.agent', ['agent_id' => $agent_id]))->toString(),
        $website,
        $module,
        $role,
        $status,
        $row->current_action ?? '',
        (string) $inbox_count,
        $row->last_seen ? $this->dateFormatter->format((int) $row->last_seen, 'short') : '',
      ];
    }

    $current_release = $this->buildCurrentReleaseSummary($visible_agents);
    $release_stages = $this->buildReleaseStageAccordion($visible_agents);

    return [
      '#type' => 'container',
      'help' => [
        '#markup' => '<p>Tracks high-level agent status updates and work item progress. Do not post raw conversation logs.</p>'
          . '<p><strong>Release stage view</strong> (below) is a best-effort inference based on agent role + tracker metadata. It includes in-progress, queued (inbox), and blocked work when available.</p>'
          . ($token ? '<p><strong>Telemetry token</strong> (send as <code>X-Copilot-Agent-Tracker-Token</code>): <code>' . $token . '</code></p>' : ''),
      ],
      'ops_links' => [
        '#type' => 'container',
        'title' => [
          '#markup' => '<h3>' . $this->t('Operational troubleshooting views') . '</h3>',
        ],
        'list' => $this->renderSimpleList([
          $this->safeRouteLink('Agentic Architecture', 'copilot_agent_tracker.architecture'),
          $this->safeRouteLink('LangGraph Session Health', 'copilot_agent_tracker.langgraph_session'),
          $this->safeRouteLink('LangGraph Feature Flow', 'copilot_agent_tracker.langgraph_feature_progress'),
          $this->safeRouteLink('LangGraph Parity Health', 'copilot_agent_tracker.langgraph_parity'),
          $this->safeRouteLink('LangGraph Release Control', 'copilot_agent_tracker.langgraph_release_status'),
        ]),
      ],
      'todo_separator' => [
        '#markup' => '<hr>',
      ],
      'todo' => $this->buildWaitingOnKeithView(),
      'agents_separator' => [
        '#markup' => '<hr>',
      ],
      'filters' => $filter_form,
      'current_release' => $current_release,
      'release_stages' => $release_stages,
      'agents' => [
        '#type' => 'table',
        '#header' => ['Agent', 'Website', 'Module', 'Role', 'Status', 'Current action', 'Inbox', 'Last seen'],
        '#rows' => $table_rows,
        '#empty' => $this->t('No agent updates yet.'),
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Legacy dashboard URL redirect.
   *
   * Retired path kept for backward compatibility:
   * /admin/reports/copilot-agent-tracker
   */
  public function legacyDashboardRedirect(): RedirectResponse {
    $url = Url::fromRoute('copilot_agent_tracker.langgraph_dashboard');
    return new RedirectResponse($url->toString(), 301);
  }

  /**
   * Architecture page: how agents, process, and systems fit together.
   */
  public function architecture(): array {
    $agents_rows = [];
    if ($this->database->schema()->tableExists('copilot_agent_tracker_agents')) {
      $agents = $this->database->select('copilot_agent_tracker_agents', 'a')
        ->fields('a', ['agent_id', 'role', 'website', 'module', 'status', 'current_action', 'last_seen'])
        ->orderBy('website', 'ASC')
        ->orderBy('module', 'ASC')
        ->orderBy('role', 'ASC')
        ->orderBy('agent_id', 'ASC')
        ->execute()
        ->fetchAllAssoc('agent_id');

      foreach ($agents as $agent_id => $row) {
        $agents_rows[] = [
          (string) $agent_id,
          (string) ($row->role ?? ''),
          (string) ($row->website ?? ''),
          (string) ($row->module ?? ''),
          (string) ($row->status ?? ''),
          (string) ($row->current_action ?? ''),
          !empty($row->last_seen) ? $this->dateFormatter->format((int) $row->last_seen, 'short') : '',
        ];
      }
    }

    $process_rows = [
      ['1', 'consume_replies', 'scripts/consume-forseti-replies.sh', 'Pull Board/Drupal replies into agent inboxes.'],
      ['2', 'dispatch_commands', 'inbox/commands/*.md routing', 'Route command items to PM inboxes or CEO triage inbox.'],
      ['3', 'release_cycle', 'scripts/release-cycle-start.sh + orchestrator state', 'Start/advance coordinated release cycles (interval-gated).'],
      ['4', 'coordinated_push', 'gh workflow run deploy.yml + release signoffs', 'Auto-deploy once coordinated PM signoffs are complete.'],
      ['5', 'pick_agents', 'Role weight + ROI prioritization', 'Select executable seats, CEO-first, bounded by agent cap.'],
      ['6', 'exec_agents', 'scripts/agent-exec-next.sh <agent>', 'Execute one work item per selected agent.'],
      ['7', 'health_check', 'scripts/hq-status.sh + scripts/hq-blockers.sh', 'Detect stalls, auto-remediate, and escalate stagnation.'],
      ['8', 'kpi_monitor', 'scripts/release-kpi-monitor.py --auto-remediate', 'Detect handoff gaps and trigger corrective routing.'],
      ['9', 'publish', 'scripts/publish-forseti-agent-tracker.sh', 'Publish tracker telemetry to Drupal dashboard tables.'],
    ];

    $process_cycle_rows = [
      ['0', 'Cron / systemd timers', 'scripts/install-cron-hq-automation.sh, scripts/install-cron-orchestrator-loop.sh, systemd unit', 'Bootstraps unattended runtime and periodic convergence checks.'],
      ['1', 'hq-automation-watchdog', 'scripts/hq-automation-watchdog.sh', 'Runs convergence checks and repairs process drift.'],
      ['1.1', 'converge', 'scripts/hq-automation.sh converge', 'Ensures required loops are started/stopped per org-control state.'],
      ['2', 'orchestrator loop', 'scripts/orchestrator-loop.sh (every 60s)', 'Runs one orchestration tick when org is enabled.'],
      ['2.1', 'engine dispatch', 'orchestrator/run.py (LangGraph-backed tick engine)', 'Runs one orchestration tick through the LangGraph runtime graph.'],
      ['3', 'tick entry', 'orchestrator/run.py --once', 'Starts one end-to-end scheduling/execution cycle.'],
      ['3.1', 'consume_replies', 'scripts/consume-forseti-replies.sh', 'Board/Drupal replies are materialized into seat inboxes.'],
      ['3.2', 'dispatch_commands', 'inbox/commands/*.md routing', 'Commands are routed to PM or CEO queues.'],
      ['3.3', 'release_cycle (gated)', 'scripts/release-cycle-start.sh + tmp/release-cycle-active/', 'Creates/advances current+next coordinated release tasks.'],
      ['3.3.1', 'QA preflight trigger', 'sessions/qa-<team>/inbox/<item>', 'Triggers QA release preflight and evidence cycle.'],
      ['3.3.2', 'PM grooming trigger', 'sessions/pm-<team>/inbox/<item>', 'Triggers next-release grooming in parallel.'],
      ['3.4', 'coordinated_push', 'PM signoff intersection + gh workflow run deploy.yml', 'Deploy trigger when all required PM signoffs are present.'],
      ['3.5', 'pick_agents', 'role weight + ROI + inbox presence', 'Chooses agents to execute this tick (CEO-first).'],
      ['3.6', 'exec_agents', 'scripts/agent-exec-next.sh <agent>', 'Per-agent execution consumes one inbox item and writes outbox/artifacts.'],
      ['3.6.1', 'runtime provider', 'local LLM (llm/runner.py) or gh copilot fallback', 'Executes agent prompt chain and persists result.'],
      ['3.7', 'health_check', 'scripts/hq-status.sh + scripts/hq-blockers.sh', 'Detects stalls, triggers auto-remediation and stagnation escalation.'],
      ['3.8', 'kpi_monitor (gated)', 'scripts/release-kpi-monitor.py --auto-remediate', 'Detects handoff gaps and queues corrective actions.'],
      ['3.9', 'publish', 'scripts/publish-forseti-agent-tracker.sh', 'Publishes latest state to Drupal tracker tables/views.'],
      ['4', 'dashboard refresh cycle', '/admin/reports/copilot-agent-tracker/* routes', 'Operators inspect live process, parity, and release state.'],
    ];

    $process_cycle_expanded_rows = [];
    $process_cycle_flat_rows = [];
    foreach ($process_cycle_rows as $entry) {
      [$level, $label, $implementation, $next_trigger] = $entry;
      $depth = substr_count((string) $level, '.');
      $parent = str_contains((string) $level, '.') ? (string) preg_replace('/\.[^.]+$/', '', (string) $level) : 'root';
      $path = $parent === 'root' ? (string) $level : $parent . ' → ' . (string) $level;
      $indented_label = str_repeat('↳ ', $depth) . $label;

      $process_cycle_expanded_rows[] = [
        (string) $level,
        (string) $depth,
        $parent,
        $indented_label,
        $implementation,
        $next_trigger,
      ];

      $process_cycle_flat_rows[] = [
        (string) $level,
        (string) $depth,
        $parent,
        $path,
        $label,
        $implementation,
        $next_trigger,
      ];
    }

    $systems_rows = [
      ['Org model', 'org-chart/', 'Roles, seats, ownership, and product-team registry.'],
      ['Queue state', 'sessions/<agent>/inbox|outbox|artifacts', 'Source-of-truth work queue and outputs.'],
      ['Orchestrator engine', 'scripts/orchestrator-loop.sh + orchestrator/run.py + orchestrator/runtime_graph/engine.py', 'Tick scheduler and LangGraph execution control-flow.'],
      ['Execution runtime', 'scripts/agent-exec-next.sh', 'Agent seat execution with lock-safe queue handling.'],
      ['Local LLM layer', 'llm/routing.yaml + llm/runner.py', 'Role/seat model routing for local inference.'],
      ['Copilot CLI fallback', 'gh copilot --resume (executor path)', 'Fallback runtime when no local model is available.'],
      ['Release automation', 'tmp/release-cycle-active/ + scripts/release-cycle-*.sh', 'Current/next coordinated release state and transitions.'],
      ['Control plane', 'tmp/org-control.json + scripts/hq-automation.sh', 'Enable/disable and converge automation loops.'],
      ['Watchdog', 'scripts/hq-automation-watchdog.sh', 'Enforce desired process state every minute.'],
      ['Telemetry API', '/api/copilot-agent-tracker/event', 'Accept sanitized runtime telemetry into tracker tables.'],
      ['Dashboard UI', 'copilot_agent_tracker module routes/controllers', 'Admin report pages for operational visibility.'],
      ['LangGraph telemetry', 'inbox/responses/langgraph-ticks.jsonl', 'Structured tick results for parity/monitoring.'],
    ];

    $langgraph = $this->buildLanggraphTroubleshootingPanels();

    return [
      '#type' => 'container',
      'overview' => [
        '#type' => 'details',
        '#title' => $this->t('Architecture Overview'),
        '#open' => TRUE,
        'summary' => [
          '#markup' => '<p>This page describes the agentic operating model used by Copilot Sessions HQ: who does the work (agents), how work progresses (process), and where state/execution lives (systems).</p>'
            . '<p>Execution follows a queue-driven orchestrator loop with role-aware scheduling and publish-to-dashboard telemetry.</p>',
        ],
      ],
      'agents' => [
        '#type' => 'details',
        '#title' => $this->t('Agents Inventory'),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => ['Agent ID', 'Role', 'Website', 'Module', 'Status', 'Current action', 'Last seen'],
          '#rows' => $agents_rows,
          '#empty' => $this->t('No tracked agents found yet. Ensure telemetry publishing is active.'),
        ],
      ],
      'process' => [
        '#type' => 'details',
        '#title' => $this->t('Process Inventory'),
        '#open' => TRUE,
        'hierarchy_help' => [
          '#markup' => '<p><strong>Execution-cycle hierarchy (expanded)</strong>: starts from Cron/systemd and flows through convergence, orchestrator ticks, and downstream release/agent/publish cycles. Indentation + depth show level.</p>',
        ],
        'hierarchy' => [
          '#type' => 'table',
          '#header' => ['Level', 'Depth', 'Parent', 'Trigger / cycle', 'Primary implementation', 'What it triggers next'],
          '#rows' => $process_cycle_expanded_rows,
        ],
        'flattened_help' => [
          '#markup' => '<p><strong>Execution-cycle map (flattened)</strong>: explicit level path for each row so parent/child relationships are easy to scan during troubleshooting.</p>',
        ],
        'flattened' => [
          '#type' => 'table',
          '#header' => ['Level', 'Depth', 'Parent', 'Path', 'Trigger / cycle', 'Primary implementation', 'What it triggers next'],
          '#rows' => $process_cycle_flat_rows,
        ],
        'flat_help' => [
          '#markup' => '<p><strong>Tick step reference</strong>: flat ordered list of the per-tick pipeline.</p>',
        ],
        'table' => [
          '#type' => 'table',
          '#header' => ['Order', 'Step', 'Primary implementation', 'Purpose'],
          '#rows' => $process_rows,
        ],
      ],
      'systems' => [
        '#type' => 'details',
        '#title' => $this->t('Systems Inventory'),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => ['System', 'Location', 'Purpose'],
          '#rows' => $systems_rows,
        ],
      ],
      'langgraph_troubleshooting' => [
        '#type' => 'details',
        '#title' => $this->t('LangGraph Troubleshooting Interfaces'),
        '#open' => TRUE,
        'help' => [
          '#markup' => '<p>Operational signals from HQ runtime artifacts for diagnosing orchestration issues quickly.</p>',
        ],
        'engine' => [
          '#type' => 'table',
          '#header' => ['Interface item', 'Current signal', 'Source'],
          '#rows' => $langgraph['engine_rows'],
          '#empty' => $this->t('No engine signals available.'),
        ],
        'tick' => [
          '#type' => 'table',
          '#header' => ['Tick health item', 'Current signal', 'Source'],
          '#rows' => $langgraph['tick_rows'],
          '#empty' => $this->t('No tick health signals available.'),
        ],
        'nodes' => [
          '#type' => 'table',
          '#header' => ['Node', 'Last status', 'Details'],
          '#rows' => $langgraph['node_rows'],
          '#empty' => $this->t('No node signals available.'),
        ],
        'parity' => [
          '#type' => 'table',
          '#header' => ['Parity item', 'Current signal', 'Source'],
          '#rows' => $langgraph['parity_rows'],
          '#empty' => $this->t('No parity signals available.'),
        ],
        'release' => [
          '#type' => 'table',
          '#header' => ['Release control item', 'Current signal', 'Source'],
          '#rows' => $langgraph['release_rows'],
          '#empty' => $this->t('No release control signals available.'),
        ],
        'errors' => [
          '#type' => 'table',
          '#header' => ['Error signal', 'Current signal', 'Source'],
          '#rows' => $langgraph['error_rows'],
          '#empty' => $this->t('No error signals available.'),
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Builds LangGraph troubleshooting panels from HQ runtime artifacts.
   */
  private function buildLanggraphTroubleshootingPanels(): array {
    $hq_root = rtrim((string) (getenv('COPILOT_HQ_ROOT') ?: '/home/ubuntu/forseti.life'), '/');

    $ticks_path = $hq_root . '/inbox/responses/langgraph-ticks.jsonl';
    $parity_path = $hq_root . '/inbox/responses/langgraph-parity-latest.json';
    $orchestrator_log_path = $hq_root . '/inbox/responses/orchestrator-latest.log';
    $release_state_dir = $hq_root . '/tmp/release-cycle-active';

    $last_tick = $this->readLastJsonlObject($ticks_path);
    $last_tick_ts = '';
    if (is_array($last_tick) && isset($last_tick['ts'])) {
      $last_tick_ts = (string) ($last_tick['ts'] ?? '');
    }

    $last_tick_age = '';
    if ($last_tick_ts !== '') {
      $tick_time = strtotime($last_tick_ts);
      if ($tick_time !== FALSE) {
        $last_tick_age = max(0, time() - (int) $tick_time) . 's';
      }
    }

    $steps = [];
    if (is_array($last_tick) && isset($last_tick['step_results']) && is_array($last_tick['step_results'])) {
      $steps = $last_tick['step_results'];
    }
    $summary = is_array($steps['summarize_tick'] ?? NULL) ? $steps['summarize_tick'] : [];

    $errors = [];
    if (is_array($summary) && isset($summary['errors']) && is_array($summary['errors'])) {
      $errors = $summary['errors'];
    }

    $parity = $this->readJsonFile($parity_path);
    $parity_ok = is_array($parity) && isset($parity['parity_ok']) ? (bool) $parity['parity_ok'] : NULL;

    $engine_mode = 'unknown';
    $orchestrator_log_snippet = '';
    if (is_readable($orchestrator_log_path)) {
      $log = (string) @file_get_contents($orchestrator_log_path);
      $orchestrator_log_snippet = trim($log);
    }
    // Prefer tick-based mode detection (reliable across log format changes).
    if (is_array($last_tick) && (isset($last_tick['step_results']) || isset($last_tick['dry_run']))) {
      $engine_mode = 'langgraph';
    }
    elseif ($orchestrator_log_snippet !== '' && (
      str_contains($orchestrator_log_snippet, '"step_results"') ||
      str_contains($orchestrator_log_snippet, '"dry_run"') ||
      str_contains($orchestrator_log_snippet, '"steps"')
    )) {
      $engine_mode = 'langgraph';
    }

    $release_rows = [];
    if (is_dir($release_state_dir)) {
      $files = glob($release_state_dir . '/*.release_id') ?: [];
      sort($files);
      foreach ($files as $release_file) {
        $team = basename((string) $release_file, '.release_id');
        $current_release = trim((string) @file_get_contents((string) $release_file));
        $next_release_file = $release_state_dir . '/' . $team . '.next_release_id';
        $next_release = is_readable($next_release_file) ? trim((string) @file_get_contents($next_release_file)) : '';
        $release_rows[] = [
          (string) $team,
          'current=' . ($current_release !== '' ? $current_release : '-') . ' | next=' . ($next_release !== '' ? $next_release : '-'),
          'tmp/release-cycle-active/' . $team . '.release_id',
        ];
      }
    }

    if (!$release_rows) {
      $release_rows[] = ['coordinated teams', 'No active release-cycle state files found.', 'tmp/release-cycle-active/'];
    }

    $engine_rows = [
      ['Engine mode', $engine_mode, 'inbox/responses/orchestrator-latest.log'],
      ['HQ root path', $hq_root, 'COPILOT_HQ_ROOT env (or default path)'],
      ['Ticks artifact', is_readable($ticks_path) ? 'available' : 'missing', 'inbox/responses/langgraph-ticks.jsonl'],
      ['Parity artifact', is_readable($parity_path) ? 'available' : 'missing', 'inbox/responses/langgraph-parity-latest.json'],
    ];

    $selected_agents = '-';
    if (is_array($summary) && !empty($summary['selected_agents']) && is_array($summary['selected_agents'])) {
      $selected_agents = implode(', ', array_map('strval', $summary['selected_agents']));
    }

    $tick_rows = [
      ['Last tick timestamp', $last_tick_ts !== '' ? $last_tick_ts : 'unavailable', 'langgraph-ticks.jsonl'],
      ['Last tick age', $last_tick_age !== '' ? $last_tick_age : 'unavailable', 'derived from tick timestamp'],
      ['Selected agents (last tick)', $selected_agents, 'step_results.summarize_tick.selected_agents'],
      ['Org enabled (last tick)', isset($summary['org_enabled']) ? ((bool) $summary['org_enabled'] ? 'true' : 'false') : 'unavailable', 'step_results.summarize_tick.org_enabled'],
    ];

    $expected_nodes = [
      'gate_org_enabled',
      'consume_replies',
      'dispatch_commands',
      'release_cycle',
      'coordinated_push',
      'pick_agents',
      'exec_agents',
      'health_check',
      'kpi_monitor',
      'publish',
    ];

    $node_rows = [];
    foreach ($expected_nodes as $node) {
      $detail = is_array($steps[$node] ?? NULL) ? $steps[$node] : [];
      if (!$detail) {
        $node_rows[] = [$node, 'missing', 'No data in latest tick.'];
        continue;
      }

      $status = 'ok';
      if (isset($detail['error'])) {
        $status = 'error';
      }
      elseif (isset($detail['skipped'])) {
        $status = 'skipped';
      }

      $details = [];
      if (isset($detail['mode'])) {
        $details[] = 'mode=' . (string) $detail['mode'];
      }
      if (isset($detail['rc'])) {
        $details[] = 'rc=' . (string) $detail['rc'];
      }
      if (isset($detail['skipped'])) {
        $details[] = 'reason=' . (string) $detail['skipped'];
      }
      if (isset($detail['error'])) {
        $details[] = 'error=' . (string) $detail['error'];
      }

      $node_rows[] = [$node, $status, $details ? implode('; ', $details) : 'ok'];
    }

    $parity_rows = [
      ['Parity overall', $parity_ok === NULL ? 'unavailable' : ($parity_ok ? 'true' : 'false'), 'langgraph-parity-latest.json'],
      [
        'Selected agents parity',
        (is_array($parity) && isset($parity['selected_agents']['match'])) ? (((bool) $parity['selected_agents']['match']) ? 'match' : 'mismatch') : 'unavailable',
        'selected_agents.match',
      ],
      [
        'Step order parity',
        (is_array($parity) && isset($parity['steps']['match'])) ? (((bool) $parity['steps']['match']) ? 'match' : 'mismatch') : 'unavailable',
        'steps.match',
      ],
    ];

    $error_rows = [
      ['Latest tick errors', $errors ? (string) count($errors) : '0', 'step_results.summarize_tick.errors'],
      ['Orchestrator latest log', $orchestrator_log_snippet !== '' ? mb_substr($orchestrator_log_snippet, 0, 240) : 'unavailable', 'inbox/responses/orchestrator-latest.log'],
    ];

    return [
      'engine_rows' => $engine_rows,
      'tick_rows' => $tick_rows,
      'node_rows' => $node_rows,
      'parity_rows' => $parity_rows,
      'release_rows' => $release_rows,
      'error_rows' => $error_rows,
    ];
  }

  /**
   * Render a reusable LangGraph troubleshooting section.
   */
  private function renderLanggraphTroubleshootingSection(string $title = 'Troubleshooting Interfaces'): array {
    $langgraph = $this->buildLanggraphTroubleshootingPanels();
    return [
      '#type' => 'details',
      '#title' => $this->t($title),
      '#open' => TRUE,
      'engine' => [
        '#type' => 'table',
        '#header' => ['Interface item', 'Current signal', 'Source'],
        '#rows' => $langgraph['engine_rows'],
      ],
      'tick' => [
        '#type' => 'table',
        '#header' => ['Tick health item', 'Current signal', 'Source'],
        '#rows' => $langgraph['tick_rows'],
      ],
      'nodes' => [
        '#type' => 'table',
        '#header' => ['Node', 'Last status', 'Details'],
        '#rows' => $langgraph['node_rows'],
      ],
      'parity' => [
        '#type' => 'table',
        '#header' => ['Parity item', 'Current signal', 'Source'],
        '#rows' => $langgraph['parity_rows'],
      ],
      'release' => [
        '#type' => 'table',
        '#header' => ['Release control item', 'Current signal', 'Source'],
        '#rows' => $langgraph['release_rows'],
      ],
      'errors' => [
        '#type' => 'table',
        '#header' => ['Error signal', 'Current signal', 'Source'],
        '#rows' => $langgraph['error_rows'],
      ],
    ];
  }

  /**
   * Render navigation links between LangGraph operational pages.
   */
  private function renderLanggraphReferenceNav(): array {
    return [
      '#type' => 'container',
      'title' => [
        '#markup' => '<h3>' . $this->t('Navigation hierarchy') . '</h3>',
      ],
      'how_to_navigate' => [
        '#markup' => '<p><strong>' . $this->t('Recommended path:') . '</strong> '
          . $this->t('Start in Control Plane (Overview → Session Health → Parity Health), then move into Execution Plane (Feature Flow → Release Control), and finish in Assurance Plane (Release Evidence → Release Triage).') . '</p>',
      ],
      'system_level' => $this->renderLanggraphNavList(
        (string) $this->t('1) Control Plane (system health + controls)'),
        [
          $this->safeRouteLinkOrCurrent('Overview (home)', 'copilot_agent_tracker.langgraph_dashboard'),
          $this->safeRouteLinkOrCurrent('Session Health (tick/runtime health)', 'copilot_agent_tracker.langgraph_session'),
          $this->safeRouteLinkOrCurrent('Parity Health (engine correctness)', 'copilot_agent_tracker.langgraph_parity'),
        ]
      ),
      'workflow_level' => $this->renderLanggraphNavList(
        (string) $this->t('2) Execution Plane (feature + release execution flow)'),
        [
          $this->safeRouteLinkOrCurrent('Feature Flow (work-item execution planning)', 'copilot_agent_tracker.langgraph_feature_progress'),
          $this->safeRouteLinkOrCurrent('Release Control (release readiness controls)', 'copilot_agent_tracker.langgraph_release_status'),
        ]
      ),
      'assurance_level' => $this->renderLanggraphNavList(
        (string) $this->t('3) Assurance Plane (evidence + triage)'),
        [
          $this->safeRouteLinkOrCurrent('Release Evidence (signoff evidence)', 'copilot_agent_tracker.langgraph_release_notes'),
          $this->safeRouteLinkOrCurrent('Release Triage (seat-level blockers)', 'copilot_agent_tracker.langgraph_release_troubleshooting'),
        ]
      ),
      'reference' => $this->renderLanggraphNavList(
        (string) $this->t('4) Cross-cutting reference'),
        [
          $this->safeRouteLinkOrCurrent('Agentic Architecture', 'copilot_agent_tracker.architecture'),
          $this->safeRouteLinkOrCurrent('Main Copilot Agent Tracker', 'copilot_agent_tracker.dashboard'),
        ]
      ),
    ];
  }

  /**
   * Render a compact flow hub for the LangGraph home page — groups all pages
   * by plane with a one-line description, so operators can orient quickly.
   */
  private function renderLanggraphHomeFlowHub(): array {
    return [
      '#type'  => 'details',
      '#title' => $this->t('LangGraph Page Hub'),
      '#open'  => FALSE,
      'nav'    => $this->renderLanggraphReferenceNav(),
    ];
  }

  /**
   * Render a simple navigation list without invoking item_list theme edge-cases.
   */
  private function renderLanggraphNavList(string $title, array $items): array {
    $item_markup = '';
    foreach ($items as $item) {
      $item_markup .= '<li>' . (string) $item . '</li>';
    }
    return [
      '#markup' => '<div class="item-list"><h3>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3><ul>' . $item_markup . '</ul></div>',
    ];
  }

  /**
   * Render an ordered list block without item_list theming.
   */
  private function renderLanggraphOrderedList(string $title, array $items): array {
    $item_markup = '';
    foreach ($items as $item) {
      $item_markup .= '<li>' . (string) $item . '</li>';
    }
    return [
      '#markup' => '<div class="item-list"><h3>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3><ol>' . $item_markup . '</ol></div>',
    ];
  }

  /**
   * Render an unordered/ordered list without item_list theming.
   */
  private function renderSimpleList(array $items, bool $ordered = FALSE): array {
    $item_markup = '';
    foreach ($items as $item) {
      if ($item instanceof MarkupInterface) {
        $rendered = (string) $item;
      }
      else {
        $rendered = Html::escape((string) $item);
      }
      $item_markup .= '<li>' . $rendered . '</li>';
    }
    $tag = $ordered ? 'ol' : 'ul';
    return [
      '#markup' => '<div class="item-list"><' . $tag . '>' . $item_markup . '</' . $tag . '></div>',
    ];
  }

  /**
   * Recursively convert Url objects in render arrays to safe strings.
   */
  private function sanitizeRenderArray(array $build): array {
    foreach ($build as $key => $value) {
      if (is_array($value)) {
        $build[$key] = $this->sanitizeRenderArray($value);
        continue;
      }
      if ($value instanceof Url) {
        try {
          $build[$key] = (string) $value->toString();
        }
        catch (RouteNotFoundException) {
          $build[$key] = '';
        }
      }
    }
    return $build;
  }

  /**
   * Render a route link safely; falls back to plain text if route is unavailable.
   */
  private function safeRouteLink(string $label, string $route_name) {
    try {
      $url = (string) Url::fromRoute($route_name)->toString();
      $text = htmlspecialchars((string) $this->t($label), ENT_QUOTES, 'UTF-8');
      $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
      return Markup::create('<a href="' . $href . '">' . $text . '</a>');
    }
    catch (RouteNotFoundException) {
      return Markup::create(htmlspecialchars((string) $this->t($label), ENT_QUOTES, 'UTF-8'));
    }
  }

  /**
   * Render a route link, or plain text when it points to the current page.
   */
  private function safeRouteLinkOrCurrent(string $label, string $route_name) {
    $request = $this->dashboardRequestStack->getCurrentRequest();
    $current_route = (string) ($request?->attributes->get('_route') ?? '');
    if ($current_route !== '' && $current_route === $route_name) {
      return Markup::create(htmlspecialchars((string) $this->t('@label (current page)', ['@label' => $label]), ENT_QUOTES, 'UTF-8'));
    }

    // Fallback path match for cases where route attributes are unavailable/stale.
    $current_path = rtrim((string) ($request?->getPathInfo() ?? ''), '/');
    if ($current_path === '') {
      $current_path = '/';
    }
    try {
      $target_path = rtrim((string) Url::fromRoute($route_name)->toString(), '/');
      if ($target_path === '') {
        $target_path = '/';
      }
      if ($target_path === $current_path) {
        return Markup::create(htmlspecialchars((string) $this->t('@label (current page)', ['@label' => $label]), ENT_QUOTES, 'UTF-8'));
      }
    }
    catch (RouteNotFoundException) {
      // Let safeRouteLink() handle unavailable routes consistently.
    }

    return $this->safeRouteLink($label, $route_name);
  }

  /**
   * Render standardized page guidance for LangGraph operational pages.
   */
  private function renderLanggraphPageGuide(string $purpose, string $usage, array $represents): array {
    $items = '';
    foreach ($represents as $item) {
      $items .= '<li>' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</li>';
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Purpose, usage, and data represented'),
      '#open' => TRUE,
      'purpose' => [
        '#markup' => '<p><strong>' . $this->t('Purpose') . ':</strong> '
          . htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8') . '</p>',
      ],
      'usage' => [
        '#markup' => '<p><strong>' . $this->t('How to use') . ':</strong> '
          . htmlspecialchars($usage, ENT_QUOTES, 'UTF-8') . '</p>',
      ],
      'represents' => [
        '#markup' => '<p><strong>' . $this->t('What this page represents') . ':</strong></p><ul>' . $items . '</ul>',
      ],
    ];
  }

  /**
   * Render clear page responsibility boundaries.
   */
  private function renderLanggraphResponsibilityCard(string $primary, array $owns, array $handoff_to): array {
    $owns_items = '';
    foreach ($owns as $item) {
      $owns_items .= '<li>' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $handoff_items = '';
    foreach ($handoff_to as $item) {
      $handoff_items .= '<li>' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</li>';
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Page responsibility boundary'),
      '#open' => FALSE,
      'primary' => [
        '#markup' => '<p><strong>' . $this->t('Primary responsibility') . ':</strong> '
          . htmlspecialchars($primary, ENT_QUOTES, 'UTF-8') . '</p>',
      ],
      'owns' => [
        '#markup' => '<p><strong>' . $this->t('This page owns') . ':</strong></p><ul>' . $owns_items . '</ul>',
      ],
      'handoff' => [
        '#markup' => '<p><strong>' . $this->t('Handoff to other pages for') . ':</strong></p><ul>' . $handoff_items . '</ul>',
      ],
    ];
  }

  /**
   * Build explicit process-flow and node-page navigation for LangGraph home.
   */
  /**
   * Render a collapsible Key Terms / glossary panel for a LangGraph page.
   *
   * @param array<string,string> $terms  Map of term => plain-English definition.
   * @param bool $open  Whether the details panel starts expanded.
   */
  private function renderLangGraphKeyTerms(array $terms, bool $open = FALSE): array {
    $items = '';
    foreach ($terms as $term => $def) {
      $items .= '<dt style="font-weight:bold;margin-top:.5em">' . htmlspecialchars($term) . '</dt>'
              . '<dd style="margin-left:1.2em;color:#444">' . htmlspecialchars($def) . '</dd>';
    }
    return [
      '#type'   => 'details',
      '#title'  => $this->t('Key Terms'),
      '#open'   => $open,
      'content' => ['#markup' => '<dl style="margin:0 0 .5em">' . $items . '</dl>'],
    ];
  }

  /**
   * Render a context callout banner for a LangGraph dashboard page.
   *
   * @param string $what   What signals / data this page shows.
   * @param string $when   When an operator should use this page.
   * @param string $source Where the data comes from.
   */
  private function renderLangGraphContextBanner(string $what, string $when, string $source): array {
    return [
      '#markup' => '<div style="background:#f5f5f5;border-left:4px solid #1976d2;padding:.75em 1em;margin:.5em 0 1em">'
        . '<strong>What this page shows:</strong> ' . htmlspecialchars($what) . '<br>'
        . '<strong>When to use it:</strong> ' . htmlspecialchars($when) . '<br>'
        . '<strong>Data source:</strong> ' . htmlspecialchars($source)
        . '</div>',
    ];
  }

  /**
   * Render the top-level workflow registry grouped by scope.
   *
   * Scope levels:
   *   system  — org-wide flows (not tied to a single site)
   *   site    — flows scoped to one product site
   *
   * Add a row here whenever a new LangGraph flow is implemented or planned.
   */
  private function renderWorkflowRegistry(): array {
    /** @var array<array{scope: string, site: string, name: string, status: string, purpose: string, console: string|null}> $workflows */
    $workflows = [
      // ── System / Org-wide ──────────────────────────────────────────────────
      [
        'scope'   => 'System',
        'site'    => 'Org-wide',
        'name'    => 'Release Cycle Orchestrator',
        'status'  => 'active',
        'purpose' => 'Multi-team release cycles, agent dispatch, feature progression, publish gates, and coordinated push across all sites.',
        'console' => 'copilot_agent_tracker.langgraph_console_home',
      ],
      // ── Site: forseti.life ─────────────────────────────────────────────────
      [
        'scope'   => 'Site',
        'site'    => 'forseti.life',
        'name'    => 'Job-Hunter Intake Flow',
        'status'  => 'planned',
        'purpose' => 'LangGraph-driven job-posting intake and triage for the forseti.life job-hunter module.',
        'console' => NULL,
      ],
      [
        'scope'   => 'Site',
        'site'    => 'forseti.life',
        'name'    => 'Job-Hunter CIO Application Flow',
        'status'  => 'in_progress',
        'purpose' => 'Monitor Keith Aumiller CIO roles from staged discovery through canonical import, saved-job binding, tailoring readiness, and submission checkpoints.',
        'console' => 'copilot_agent_tracker.langgraph_process_flow',
      ],
      // ── Site: dungeoncrawler ───────────────────────────────────────────────
      [
        'scope'   => 'Site',
        'site'    => 'dungeoncrawler',
        'name'    => 'PF2E Encounter Flow',
        'status'  => 'planned',
        'purpose' => 'LangGraph-driven encounter and session management for the DungeonCrawler PF2E assistant.',
        'console' => NULL,
      ],
    ];

    $rows = [];
    foreach ($workflows as $wf) {
      $status_label = match($wf['status']) {
        'active'      => '🟢 Active',
        'in_progress' => '🟡 In Progress',
        'planned'     => '⬜ Planned',
        default       => $wf['status'],
      };

      if ($wf['console'] !== NULL) {
        try {
          $console_link = \Drupal\Core\Link::createFromRoute('Open Console', $wf['console'])->toString();
        }
        catch (\Exception $e) {
          $console_link = '—';
        }
      }
      else {
        $console_link = '—';
      }

      $rows[] = [
        $wf['scope'] . ' · ' . $wf['site'],
        $wf['name'],
        $status_label,
        $wf['purpose'],
        ['data' => ['#markup' => $console_link]],
      ];
    }

    return [
      '#type' => 'container',
      'heading' => [
        '#markup' => '<h2>' . $this->t('LangGraph Workflow Registry') . '</h2>'
          . '<p>' . $this->t('All LangGraph workflow processes, organised by scope. System-level flows run across the whole organisation; site-level flows are scoped to a single product.') . '</p>',
      ],
      'table' => [
        '#type'   => 'table',
        '#header' => [
          $this->t('Scope · Site'),
          $this->t('Workflow'),
          $this->t('Status'),
          $this->t('Purpose'),
          $this->t('Console'),
        ],
        '#rows'   => $rows,
        '#empty'  => $this->t('No workflows registered.'),
      ],
    ];
  }

  /**
   * Render the home page process-flow navigation hub.
    return [
      '#type' => 'container',
      'flow_title' => [
        '#markup' => '<h3>' . $this->t('Process flow navigation') . '</h3>',
      ],
      'flow_steps' => $this->renderLanggraphOrderedList(
        (string) $this->t('Process flow steps'),
        [
          $this->safeRouteLinkOrCurrent('Control Plane: Start at Overview (this page) to confirm top-level control-state health.', 'copilot_agent_tracker.langgraph_dashboard'),
          $this->safeRouteLinkOrCurrent('Control Plane: Validate runtime cadence and node execution stability in Session Health.', 'copilot_agent_tracker.langgraph_session'),
          $this->safeRouteLinkOrCurrent('Control Plane: Confirm expected engine behavior in Parity Health.', 'copilot_agent_tracker.langgraph_parity'),
          $this->safeRouteLinkOrCurrent('Execution Plane: Review work-item execution posture in Feature Flow.', 'copilot_agent_tracker.langgraph_feature_progress'),
          $this->safeRouteLinkOrCurrent('Execution Plane: Check release readiness controls in Release Control.', 'copilot_agent_tracker.langgraph_release_status'),
          $this->safeRouteLinkOrCurrent('Assurance Plane: Validate release evidence in Release Evidence.', 'copilot_agent_tracker.langgraph_release_notes'),
          $this->safeRouteLinkOrCurrent('Assurance Plane: If stalled, diagnose seat-level blockers in Release Triage.', 'copilot_agent_tracker.langgraph_release_troubleshooting'),
        ]
      ),
      'node_title' => [
        '#markup' => '<h3>' . $this->t('Node/process-specific pages') . '</h3>',
      ],
      'node_table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Flow/node concern'),
          $this->t('Primary page'),
          $this->t('Why this page'),
        ],
        '#rows' => [
          [
            $this->t('Tick cadence and node execution errors'),
            $this->safeRouteLinkOrCurrent('Session Health', 'copilot_agent_tracker.langgraph_session'),
            $this->t('Owns runtime telemetry and per-tick error visibility.'),
          ],
          [
            $this->t('Selected-agents and step-order parity'),
            $this->safeRouteLinkOrCurrent('Parity Health', 'copilot_agent_tracker.langgraph_parity'),
            $this->t('Owns parity pass/fail and mismatch diagnostics.'),
          ],
          [
            $this->t('Feature/work-item execution planning'),
            $this->safeRouteLinkOrCurrent('Feature Flow', 'copilot_agent_tracker.langgraph_feature_progress'),
            $this->t('Owns planning and ownership context for active work.'),
          ],
          [
            $this->t('Release control and publish readiness'),
            $this->safeRouteLinkOrCurrent('Release Control', 'copilot_agent_tracker.langgraph_release_status'),
            $this->t('Owns release hold/ready posture.'),
          ],
          [
            $this->t('Release evidence and signoff narrative'),
            $this->safeRouteLinkOrCurrent('Release Evidence', 'copilot_agent_tracker.langgraph_release_notes'),
            $this->t('Owns approval-grade release evidence.'),
          ],
          [
            $this->t('Seat-level blockers and needs-info queues'),
            $this->safeRouteLinkOrCurrent('Release Triage', 'copilot_agent_tracker.langgraph_release_troubleshooting'),
            $this->t('Owns bottleneck and ownership triage.'),
          ],
        ],
      ],
    ];
  }

  /**
   * Build shared LangGraph page shell (container, title, optional reference, nav).
   */
  private function buildLanggraphPageShell(string $title, ?string $reference_note = NULL): array {
    $build = [
      '#type' => 'container',
      '#cache' => ['max-age' => 0],
      'title' => [
        '#markup' => '<h2>' . $this->t($title) . '</h2>',
      ],
    ];
    if ($reference_note !== NULL && $reference_note !== '') {
      $build['reference_note'] = [
        '#markup' => '<p><strong>' . $this->t('Reference:') . '</strong> ' . htmlspecialchars($reference_note, ENT_QUOTES, 'UTF-8') . '</p>',
      ];
    }
    $build['nav'] = $this->renderLanggraphReferenceNav();
    return $build;
  }

  /**
   * Render a standardized expected-operator-action banner.
   */
  private function renderLanggraphExpectedAction(
    bool $ok,
    string $ok_label,
    string $ok_message,
    string $warn_label,
    string $warn_message,
  ): array {
    $is_ok = $ok ? 'status' : 'warning';
    $label = $ok ? $ok_label : $warn_label;
    $message = $ok ? $ok_message : $warn_message;
    return [
      '#markup' => '<div class="messages messages--' . $is_ok . '"><strong>'
        . $this->t($label) . ':</strong> ' . $this->t($message) . '</div>',
    ];
  }

  /**
   * Render a fixed expected-operator-action banner.
   */
  private function renderLanggraphExpectedActionFixed(
    string $severity,
    string $label,
    string $message,
  ): array {
    return [
      '#markup' => '<div class="messages messages--' . $severity . '"><strong>'
        . $this->t($label) . ':</strong> ' . $this->t($message) . '</div>',
    ];
  }

  /**
   * Returns absolute path to a HQ repo file, resolved from COPILOT_HQ_ROOT env.
   */
  private function langgraphPath(string $relative): string {
    $root = rtrim((string) (getenv('COPILOT_HQ_ROOT') ?: '/home/ubuntu/forseti.life'), '/');
    return $root . '/' . ltrim($relative, '/');
  }

  /**
   * Read a JSON file safely.
   */
  private function readJsonFile(string $path): array {
    if (!is_readable($path)) {
      return [];
    }
    try {
      $raw = (string) file_get_contents($path);
      if ($raw === '') {
        return [];
      }
      $decoded = json_decode($raw, TRUE);
      return is_array($decoded) ? $decoded : [];
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Read release-cycle control state with fallback compatibility.
   */
  private function readReleaseCycleControlState(): array {
    $state_file = (string) (getenv('RELEASE_CYCLE_CONTROL_FILE') ?: self::RELEASE_CYCLE_CONTROL_FILE_DEFAULT);
    $state = $this->readJsonFile($state_file);
    if (empty($state)) {
      $hq_root = rtrim((string) (getenv('COPILOT_HQ_ROOT') ?: '/home/ubuntu/forseti.life'), '/');
      $state = $this->readJsonFile($hq_root . '/tmp/release-cycle-control.json');
    }

    return [
      'enabled' => isset($state['enabled']) ? (bool) $state['enabled'] : TRUE,
      'updated_at' => (string) ($state['updated_at'] ?? ''),
      'updated_by' => (string) ($state['updated_by'] ?? ''),
      'reason' => (string) ($state['reason'] ?? ''),
    ];
  }

  /**
   * Read the last JSON object from a JSONL file safely.
   */
  private function readLastJsonlObject(string $path): array {
    if (!is_readable($path)) {
      return [];
    }

    try {
      $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
      if (!$lines) {
        return [];
      }
      $last = trim((string) end($lines));
      if ($last === '') {
        return [];
      }
      $decoded = json_decode($last, TRUE);
      return is_array($decoded) ? $decoded : [];
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Returns the Drupal user ID for the CIO automation target profile.
   */
  private function getJobHunterCioTargetUid(): ?int {
    if (!$this->database->schema()->tableExists('users_field_data')) {
      return NULL;
    }

    $uid = $this->database->query(
      'SELECT uid FROM {users_field_data} WHERE mail = :mail ORDER BY uid ASC LIMIT 1',
      [':mail' => self::JOBHUNTER_CIO_TARGET_EMAIL]
    )->fetchField();

    return $uid === FALSE ? NULL : (int) $uid;
  }

  /**
   * Returns the SQL fragment and params used to match CIO job titles.
   *
   * @return array{sql: string, params: array<string, string>}
   *   SQL clause and placeholder values.
   */
  private function getJobHunterCioTitleSql(string $field): array {
    return [
      'sql' => '(LOWER(' . $field . ') LIKE :cio_phrase OR LOWER(' . $field . ') REGEXP :cio_regex)',
      'params' => [
        ':cio_phrase' => '%chief information officer%',
        ':cio_regex' => '(^|[^a-z])cio([^a-z]|$)',
      ],
    ];
  }

  /**
   * Executes a scalar COUNT/summary query and normalizes the result to int.
   */
  private function fetchIntQueryValue(string $sql, array $args = []): int {
    $value = $this->database->query($sql, $args)->fetchField();
    return $value === FALSE ? 0 : (int) $value;
  }

  /**
   * Renders a compact workflow-stage badge.
   */
  private function renderJobHunterFlowBadge(string $state, string $label): string {
    [$fg, $bg] = match ($state) {
      'healthy' => ['#1b5e20', '#e8f5e9'],
      'attention' => ['#e65100', '#fff3e0'],
      'blocked' => ['#b71c1c', '#ffebee'],
      default => ['#616161', '#f5f5f5'],
    };

    return '<span style="display:inline-block;padding:2px 8px;border-radius:12px;'
      . 'font-size:0.78em;font-weight:600;color:' . $fg . ';background:' . $bg . ';">'
      . Html::escape($label)
      . '</span>';
  }

  /**
   * Builds a live Job Hunter CIO process-flow snapshot for Keith Aumiller.
   *
   * @return array<string, mixed>
   *   Snapshot data keyed by counts, warnings, steps, and recent jobs.
   */
  private function loadJobHunterCioFlowSnapshot(): array {
    $required_tables = [
      'jobhunter_job_search_results',
      'jobhunter_job_requirements',
      'jobhunter_saved_jobs',
      'jobhunter_applications',
      'jobhunter_tailored_resumes',
    ];
    foreach ($required_tables as $table) {
      if (!$this->database->schema()->tableExists($table)) {
        return [
          'available' => FALSE,
          'reason' => 'Required Job Hunter table missing: ' . $table,
        ];
      }
    }

    $uid = $this->getJobHunterCioTargetUid();
    if ($uid === NULL) {
      return [
        'available' => FALSE,
        'reason' => 'Unable to resolve Keith Aumiller user account on this site.',
      ];
    }

    ['sql' => $job_title_sql, 'params' => $title_params] = $this->getJobHunterCioTitleSql('jr.job_title');
    ['sql' => $search_title_sql, 'params' => $search_params] = $this->getJobHunterCioTitleSql('job_title');

    $staged_pending = $this->fetchIntQueryValue(
      'SELECT COUNT(*) FROM {jobhunter_job_search_results} WHERE imported_to_job_id IS NULL AND ' . $search_title_sql,
      $search_params
    );
    $staged_imported = $this->fetchIntQueryValue(
      'SELECT COUNT(*) FROM {jobhunter_job_search_results} WHERE imported_to_job_id IS NOT NULL AND ' . $search_title_sql,
      $search_params
    );
    $canonical_jobs = $this->fetchIntQueryValue(
      'SELECT COUNT(*) FROM {jobhunter_job_requirements} jr WHERE ' . $job_title_sql,
      $title_params
    );
    $saved_jobs = $this->fetchIntQueryValue(
      'SELECT COUNT(*) FROM {jobhunter_saved_jobs} sj INNER JOIN {jobhunter_job_requirements} jr ON jr.id = sj.job_id WHERE sj.uid = :uid AND ' . $job_title_sql,
      [':uid' => $uid] + $title_params
    );
    $applications = $this->fetchIntQueryValue(
      'SELECT COUNT(*) FROM {jobhunter_applications} a INNER JOIN {jobhunter_job_requirements} jr ON jr.id = a.job_id WHERE a.uid = :uid AND ' . $job_title_sql,
      [':uid' => $uid] + $title_params
    );
    $apply_ready = $this->fetchIntQueryValue(
      'SELECT COUNT(*) FROM {jobhunter_applications} a INNER JOIN {jobhunter_job_requirements} jr ON jr.id = a.job_id WHERE a.uid = :uid AND COALESCE(a.apply_url, \'\') <> \'\' AND ' . $job_title_sql,
      [':uid' => $uid] + $title_params
    );
    $manual_review = $this->fetchIntQueryValue(
      'SELECT COUNT(*) FROM {jobhunter_applications} a INNER JOIN {jobhunter_job_requirements} jr ON jr.id = a.job_id WHERE a.uid = :uid AND (a.admin_review_required = 1 OR a.submission_status = :manual_required) AND ' . $job_title_sql,
      [':uid' => $uid, ':manual_required' => 'manual_required'] + $title_params
    );
    $tailored_resumes = $this->fetchIntQueryValue(
      'SELECT COUNT(*) FROM {jobhunter_tailored_resumes} tr INNER JOIN {jobhunter_job_requirements} jr ON jr.id = tr.job_id WHERE tr.uid = :uid AND ' . $job_title_sql,
      [':uid' => $uid] + $title_params
    );
    $tailored_pdf_ready = $this->fetchIntQueryValue(
      'SELECT COUNT(*) FROM {jobhunter_tailored_resumes} tr INNER JOIN {jobhunter_job_requirements} jr ON jr.id = tr.job_id WHERE tr.uid = :uid AND (COALESCE(tr.pdf_generated, 0) > 0 OR COALESCE(tr.pdf_path, \'\') <> \'\') AND ' . $job_title_sql,
      [':uid' => $uid] + $title_params
    );
    $submitted = $this->fetchIntQueryValue(
      'SELECT COUNT(*) FROM {jobhunter_applications} a INNER JOIN {jobhunter_job_requirements} jr ON jr.id = a.job_id WHERE a.uid = :uid AND ((COALESCE(a.submission_status, \'\') IN (:submitted, :confirmed)) OR COALESCE(a.confirmed_at, \'\') <> \'\' OR COALESCE(a.submission_date, \'\') <> \'\') AND ' . $job_title_sql,
      [':uid' => $uid, ':submitted' => 'submitted', ':confirmed' => 'confirmed'] + $title_params
    );

    $recent_jobs = [];
    $source_field = $this->database->schema()->fieldExists('jobhunter_job_requirements', 'external_source') ? 'external_source' : 'source';
    $recent_query = $this->database->query(
      'SELECT jr.id, jr.job_title, jr.' . $source_field . ' AS source_label, COALESCE(sj.id, 0) AS saved_job_id, '
      . 'COALESCE(a.submission_status, \'\') AS submission_status, COALESCE(a.apply_url, \'\') AS apply_url, '
      . 'COALESCE(a.admin_review_required, 0) AS admin_review_required, COALESCE(tr.tailoring_status, \'\') AS tailoring_status, '
      . 'COALESCE(tr.pdf_generated, 0) AS pdf_generated '
      . 'FROM {jobhunter_job_requirements} jr '
      . 'LEFT JOIN {jobhunter_saved_jobs} sj ON sj.job_id = jr.id AND sj.uid = :uid '
      . 'LEFT JOIN {jobhunter_applications} a ON a.job_id = jr.id AND a.uid = :uid '
      . 'LEFT JOIN {jobhunter_tailored_resumes} tr ON tr.job_id = jr.id AND tr.uid = :uid '
      . 'WHERE ' . $job_title_sql . ' '
      . 'ORDER BY jr.id DESC LIMIT 10',
      [':uid' => $uid] + $title_params
    );
    foreach ($recent_query as $row) {
      $recent_jobs[] = [
        'id' => (int) $row->id,
        'job_title' => (string) $row->job_title,
        'source_label' => (string) ($row->source_label ?? ''),
        'saved_job_id' => (int) ($row->saved_job_id ?? 0),
        'submission_status' => (string) ($row->submission_status ?? ''),
        'apply_url' => (string) ($row->apply_url ?? ''),
        'admin_review_required' => (int) ($row->admin_review_required ?? 0),
        'tailoring_status' => (string) ($row->tailoring_status ?? ''),
        'pdf_generated' => (int) ($row->pdf_generated ?? 0),
      ];
    }

    $discovered_total = $staged_pending + $staged_imported;
    $steps = [
      [
        'label' => 'Discover CIO opportunities',
        'state' => $discovered_total > 0 ? 'healthy' : 'pending',
        'badge' => $this->renderJobHunterFlowBadge($discovered_total > 0 ? 'healthy' : 'pending', $discovered_total > 0 ? 'Healthy' : 'Pending'),
        'signal' => $discovered_total . ' total matches (' . $staged_pending . ' still staged, ' . $staged_imported . ' already imported).',
        'intent' => 'Keep the LangGraph intake queue fed with fresh CIO-class opportunities.',
      ],
      [
        'label' => 'Import into canonical job requirements',
        'state' => $canonical_jobs === 0 ? 'blocked' : ($canonical_jobs < $discovered_total ? 'attention' : 'healthy'),
        'badge' => $this->renderJobHunterFlowBadge($canonical_jobs === 0 ? 'blocked' : ($canonical_jobs < $discovered_total ? 'attention' : 'healthy'), $canonical_jobs === 0 ? 'Blocked' : ($canonical_jobs < $discovered_total ? 'Attention' : 'Healthy')),
        'signal' => $canonical_jobs . ' canonical CIO jobs for ' . $discovered_total . ' discovered matches.',
        'intent' => 'Promote staged opportunities into the durable job requirement pipeline.',
      ],
      [
        'label' => 'Bind to Keith queue',
        'state' => $canonical_jobs === 0 ? 'pending' : ($saved_jobs >= $canonical_jobs ? 'healthy' : ($saved_jobs > 0 ? 'attention' : 'blocked')),
        'badge' => $this->renderJobHunterFlowBadge($canonical_jobs === 0 ? 'pending' : ($saved_jobs >= $canonical_jobs ? 'healthy' : ($saved_jobs > 0 ? 'attention' : 'blocked')), $canonical_jobs === 0 ? 'Pending' : ($saved_jobs >= $canonical_jobs ? 'Healthy' : ($saved_jobs > 0 ? 'Attention' : 'Blocked'))),
        'signal' => $saved_jobs . ' saved CIO jobs tied to Keith (uid ' . $uid . ').',
        'intent' => 'Turn imported jobs into user-scoped work items ready for application automation.',
      ],
      [
        'label' => 'Resolve apply path and create application records',
        'state' => $applications === 0 ? 'blocked' : ($apply_ready >= $applications ? 'healthy' : 'attention'),
        'badge' => $this->renderJobHunterFlowBadge($applications === 0 ? 'blocked' : ($apply_ready >= $applications ? 'healthy' : 'attention'), $applications === 0 ? 'Blocked' : ($apply_ready >= $applications ? 'Healthy' : 'Attention')),
        'signal' => $applications . ' application records, ' . $apply_ready . ' with resolved apply URLs, ' . $manual_review . ' flagged for manual review.',
        'intent' => 'Move each saved job into an actionable submission path with a destination URL and operator posture.',
      ],
      [
        'label' => 'Generate tailored resume package',
        'state' => $applications === 0 ? 'pending' : ($tailored_resumes === 0 ? 'blocked' : ($tailored_pdf_ready >= $tailored_resumes ? 'healthy' : 'attention')),
        'badge' => $this->renderJobHunterFlowBadge($applications === 0 ? 'pending' : ($tailored_resumes === 0 ? 'blocked' : ($tailored_pdf_ready >= $tailored_resumes ? 'healthy' : 'attention')), $applications === 0 ? 'Pending' : ($tailored_resumes === 0 ? 'Blocked' : ($tailored_pdf_ready >= $tailored_resumes ? 'Healthy' : 'Attention'))),
        'signal' => $tailored_resumes . ' tailored CIO resumes, ' . $tailored_pdf_ready . ' with PDF output.',
        'intent' => 'Produce the per-opportunity resume artifact the submission step can actually send.',
      ],
      [
        'label' => 'Submit and confirm applications',
        'state' => $applications === 0 ? 'pending' : ($submitted > 0 ? 'healthy' : 'attention'),
        'badge' => $this->renderJobHunterFlowBadge($applications === 0 ? 'pending' : ($submitted > 0 ? 'healthy' : 'attention'), $applications === 0 ? 'Pending' : ($submitted > 0 ? 'Healthy' : 'Attention')),
        'signal' => $submitted . ' submissions confirmed out of ' . $applications . ' application records.',
        'intent' => 'Close the loop from prepared application to confirmed external submission.',
      ],
    ];

    $warnings = [];
    if ($source_field === 'source') {
      $warnings[] = 'jobhunter_job_requirements is still using the legacy source column; compatibility fallback is active until external_source is available on this host.';
    }
    if ($tailored_resumes === 0 && $applications > 0) {
      $warnings[] = 'Application prep exists, but no tailored CIO resume artifacts have been generated yet.';
    }

    return [
      'available' => TRUE,
      'uid' => $uid,
      'discovered_total' => $discovered_total,
      'staged_pending' => $staged_pending,
      'staged_imported' => $staged_imported,
      'canonical_jobs' => $canonical_jobs,
      'saved_jobs' => $saved_jobs,
      'applications' => $applications,
      'apply_ready' => $apply_ready,
      'manual_review' => $manual_review,
      'tailored_resumes' => $tailored_resumes,
      'tailored_pdf_ready' => $tailored_pdf_ready,
      'submitted' => $submitted,
      'warnings' => $warnings,
      'steps' => $steps,
      'recent_jobs' => $recent_jobs,
    ];
  }

  /**
   * Builds a "current release" summary block.
   *
   * Uses CEO-published metadata.release_notes to infer the current release id
   * and uses QA agent metadata (qa_last_audit) to show per-product PASS/FAIL.
   */
  private function buildCurrentReleaseSummary(array $agents): array {
    $current_release_id = '';
    $fallback = [];

    // Pull most-recent CEO metadata and infer current release id.
    $row = $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', ['metadata', 'last_seen'])
      ->condition('agent_id', 'ceo-copilot%', 'LIKE')
      ->orderBy('last_seen', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    $ceo_meta = [];
    if (!empty($row['metadata'])) {
      try {
        $ceo_meta = Json::decode((string) $row['metadata']) ?? [];
      }
      catch (\Throwable) {
        $ceo_meta = [];
      }
    }

    $entries = (is_array($ceo_meta) && !empty($ceo_meta['release_notes']) && is_array($ceo_meta['release_notes'])) ? $ceo_meta['release_notes'] : [];
    if ($entries) {
      $candidates = [];
      $fallback = [];
      foreach ($entries as $e) {
        if (!is_array($e)) {
          continue;
        }
        $rid = trim((string) ($e['release_id'] ?? ''));
        if ($rid === '') {
          continue;
        }
        if (!preg_match('/^\d{8}-[A-Za-z0-9._-]+$/', $rid)) {
          continue;
        }
        $fallback[] = $rid;

        $state = strtolower(trim((string) ($e['state'] ?? '')));
        // Treat anything not explicitly shipped/released as "current" candidate.
        if ($state === '' || $state === 'pending' || $state === 'candidate' || $state === 'needs_approval' || $state === 'needs-approval') {
          $candidates[] = $rid;
          continue;
        }
        if (!in_array($state, ['shipped', 'released', 'done', 'closed'], TRUE)) {
          $candidates[] = $rid;
        }
      }

      $pick_from = $candidates ?: $fallback;
      if ($pick_from) {
        // Release ids are typically YYYYMMDD-*; lexicographic max approximates newest.
        sort($pick_from);
        $current_release_id = (string) end($pick_from);
      }
    }

    if ($current_release_id === '') {
      $current_release_id = $this->inferReleaseIdFromInFlightAgents($agents);
    }

    if ($current_release_id === '') {
      $current_release_id = $this->inferReleaseIdFromQaPreflightArtifacts();
    }

    if ($current_release_id === '') {
      $current_release_id = $this->inferReleaseIdFromSignoffs();
    }

    // Derive previous and next release ids from the known release list.
    $prev_release_id = '';
    $next_release_id = '';
    if ($fallback && $current_release_id !== '') {
      $sorted_ids = array_values(array_unique($fallback));
      sort($sorted_ids);
      $idx = array_search($current_release_id, $sorted_ids, TRUE);
      if ($idx !== FALSE) {
        $prev_release_id = $idx > 0 ? $sorted_ids[$idx - 1] : '';
        $next_release_id = isset($sorted_ids[$idx + 1]) ? $sorted_ids[$idx + 1] : '';
      }
    }

    $release_notes_url = Url::fromRoute('copilot_agent_tracker.release_notes');
    $release_notes_link = Link::fromTextAndUrl('Release notes / features / evidence', $release_notes_url)->toString();
    $release_id_link = $current_release_id !== ''
      ? Link::fromTextAndUrl($current_release_id, $this->safeReleaseNotesDetailUrl($current_release_id))->toString()
      : '-';
    $prev_release_link = $prev_release_id !== ''
      ? Link::fromTextAndUrl($prev_release_id, $this->safeReleaseNotesDetailUrl($prev_release_id))->toString()
      : '';
    $next_release_link = $next_release_id !== ''
      ? Link::fromTextAndUrl($next_release_id, $this->safeReleaseNotesDetailUrl($next_release_id))->toString()
      : '';

    // Build per-product QA status table.
    $by_product = [];
    foreach ($agents as $a) {
      if (!is_array($a)) {
        continue;
      }
      $website = trim((string) ($a['website'] ?? ''));
      $module = trim((string) ($a['module'] ?? ''));

      // Internal/unscoped seats (e.g. agent-code-review) publish empty website+module.
      // They should not appear as a "- / -" product row.
      if ($website === '' && $module === '') {
        continue;
      }

      $product_key = $website . '::' . $module;
      if (!isset($by_product[$product_key])) {
        $by_product[$product_key] = [
          'website' => $website,
          'module' => $module,
          'agents' => [],
        ];
      }
      $by_product[$product_key]['agents'][] = $a;
    }
    ksort($by_product);

    // Website-level fallback QA seats, used when a module row has no direct QA.
    // Prefer seats scoped only to the website (module empty) when available.
    $qa_fallback_by_website = [];
    foreach ($agents as $a) {
      if (!is_array($a)) {
        continue;
      }
      $agent_id = (string) ($a['agent_id'] ?? '');
      $role = (string) ($a['role'] ?? '');
      $website = trim((string) ($a['website'] ?? ''));
      if ($website === '') {
        continue;
      }
      if (!($role === 'tester' || str_starts_with($agent_id, 'qa-'))) {
        continue;
      }

      $candidate_module = trim((string) ($a['module'] ?? ''));
      $meta = (!empty($a['meta']) && is_array($a['meta'])) ? $a['meta'] : [];
      $has_audit = !empty($meta['qa_last_audit']) && is_array($meta['qa_last_audit']);
      $score = ($has_audit ? 2 : 0) + ($candidate_module === '' ? 1 : 0);

      if (!isset($qa_fallback_by_website[$website]) || $score > (int) ($qa_fallback_by_website[$website]['score'] ?? -1)) {
        $qa_fallback_by_website[$website] = [
          'agent' => $a,
          'has_audit' => $has_audit,
          'score' => $score,
        ];
      }
    }

    $qa_rows = [];
    foreach ($by_product as $product_key => $p) {
      $website = (string) ($p['website'] ?? '');
      $module = (string) ($p['module'] ?? '');
      $product_label = ($website ?: '-') . ' / ' . ($module ?: '-');

      $pm_agent = NULL;
      $qa_agent = NULL;
      $qa_agent_has_audit = FALSE;
      $qa_is_website_fallback = FALSE;
      $saw_paused = FALSE;
      $saw_non_paused = FALSE;
      foreach (($p['agents'] ?? []) as $a) {
        if (!is_array($a)) {
          continue;
        }
        $agent_id = (string) ($a['agent_id'] ?? '');
        $role = (string) ($a['role'] ?? '');
        $agent_status = strtolower(trim((string) ($a['status'] ?? '')));
        if ($agent_status === 'paused') {
          $saw_paused = TRUE;
        }
        elseif ($agent_status !== '') {
          $saw_non_paused = TRUE;
        }
        if ($pm_agent === NULL && ($role === 'product-manager' || str_starts_with($agent_id, 'pm-'))) {
          $pm_agent = $a;
        }
        if ($role === 'tester' || str_starts_with($agent_id, 'qa-')) {
          $meta = (!empty($a['meta']) && is_array($a['meta'])) ? $a['meta'] : [];
          $has_audit = !empty($meta['qa_last_audit']) && is_array($meta['qa_last_audit']);
          if (!is_array($qa_agent) || (!$qa_agent_has_audit && $has_audit)) {
            $qa_agent = $a;
            $qa_agent_has_audit = $has_audit;
          }
        }
      }

      if ($website !== '' && isset($qa_fallback_by_website[$website])) {
        $fallback = $qa_fallback_by_website[$website];
        $fallback_agent = $fallback['agent'] ?? NULL;
        $fallback_has_audit = (bool) ($fallback['has_audit'] ?? FALSE);
        if (
          is_array($fallback_agent)
          && (
            !is_array($qa_agent)
            || (!$qa_agent_has_audit && $fallback_has_audit)
          )
        ) {
          $qa_agent = $fallback_agent;
          $qa_agent_has_audit = $fallback_has_audit;
          $qa_is_website_fallback = TRUE;
        }
      }

      $product_paused = ($saw_paused && !$saw_non_paused);

      $qa_status = $product_paused ? 'PAUSED' : 'NOT RUN';
      $qa_details = $product_paused ? 'Paused' : '-';
      $qa_link = '-';
      $features_link = '-';

      if (is_array($pm_agent)) {
        $pm_agent_id = (string) ($pm_agent['agent_id'] ?? '');
        if ($pm_agent_id !== '') {
          $features_link = Link::fromTextAndUrl('Features', Url::fromRoute('copilot_agent_tracker.agent', ['agent_id' => $pm_agent_id]))->toString();
        }
      }

      if ($product_paused) {
        // Keep the explicit PAUSED marker; do not attempt to interpret QA data.
      }
      elseif (!is_array($qa_agent)) {
        $qa_status = 'NO QA';
        $qa_details = 'No QA seat for this product';
      }
      else {
        $qa_agent_id = (string) ($qa_agent['agent_id'] ?? '');
        $qa_link = Link::fromTextAndUrl($qa_agent_id, Url::fromRoute('copilot_agent_tracker.agent', ['agent_id' => $qa_agent_id]))->toString();

        $meta = (!empty($qa_agent['meta']) && is_array($qa_agent['meta'])) ? $qa_agent['meta'] : [];
        $qa_last = (!empty($meta['qa_last_audit']) && is_array($meta['qa_last_audit'])) ? $meta['qa_last_audit'] : [];

        if (!$qa_last) {
          $qa_status = 'NOT RUN';
          $qa_details = 'No QA audit published yet';
        }
        else {
          $failed = (int) ($qa_last['url_checks_failed'] ?? 0) + (int) ($qa_last['route_checks_failed'] ?? 0) + (int) ($qa_last['permission_violation_count'] ?? 0);
          $run_id = trim((string) ($qa_last['run_id'] ?? ''));
          $status = strtolower(trim((string) ($qa_last['status'] ?? '')));
          $base_url = trim((string) ($qa_last['base_url'] ?? ''));

          if ($failed > 0 || in_array($status, ['issues', 'fail', 'failed'], TRUE)) {
            $qa_status = 'FAIL';
          }
          elseif (in_array($status, ['clean', 'pass', 'passed'], TRUE)) {
            $qa_status = 'PASS';
          }
          elseif ($failed === 0 && ($run_id !== '' || $base_url !== '')) {
            // If we have a concrete run published and zero failures, treat it as PASS.
            $qa_status = 'PASS';
          }
          else {
            $qa_status = 'NOT RUN';
          }

          $qa_details_parts = [];
          if ($run_id !== '') {
            $qa_details_parts[] = 'Run: ' . htmlspecialchars($run_id);
          }
          if ($base_url !== '') {
            $qa_details_parts[] = 'Base: ' . htmlspecialchars($base_url);
          }
          if ($qa_is_website_fallback) {
            $qa_details_parts[] = 'Scope: website QA seat';
          }
          $qa_details_parts[] = 'Failed checks: ' . (string) max(0, $failed);
          $qa_details = $qa_details_parts ? implode(' — ', $qa_details_parts) : '-';
        }
      }

      $qa_rows[] = [
        htmlspecialchars($product_label),
        Markup::create('<strong>' . htmlspecialchars($qa_status) . '</strong>'),
        Markup::create($qa_details),
        Markup::create($features_link),
        Markup::create($qa_link),
      ];
    }

    return [
      '#type' => 'container',
      'title' => [
        '#markup' => '<h3>Current release</h3>',
      ],
      'summary' => [
        '#markup' => '<p><strong>Release id:</strong> ' . $release_id_link . '</p>'
          . '<p><strong>Links:</strong> ' . $release_notes_link . '</p>'
          . ($prev_release_link !== '' ? '<p><strong>Last release:</strong> ' . $prev_release_link . '</p>' : '')
          . ($next_release_link !== '' ? '<p><strong>Next release:</strong> ' . $next_release_link . '</p>' : '')
          . '<p><em>The release stage section below marks the inferred current stage as “CURRENT”.</em></p>',
      ],
      'qa_table' => [
        '#type' => 'table',
        '#header' => ['Product', 'QA status', 'Last QA run (summary)', 'Features', 'QA page'],
        '#rows' => $qa_rows,
        '#empty' => $this->t('No products visible.'),
      ],
    ];
  }

  /**
   * Infers in-flight release id from active/queued release-cycle item ids.
   *
   * This is intended to resolve "Current release" before the first QA cycle
   * has completed and before release notes/signoff artifacts are available.
   */
  private function inferReleaseIdFromInFlightAgents(array $agents): string {
    $candidates = [];

    foreach ($agents as $a) {
      if (!is_array($a)) {
        continue;
      }

      $tokens = [
        trim((string) ($a['active_item_id'] ?? '')),
        trim((string) ($a['next_item_id'] ?? '')),
      ];

      $action = trim((string) ($a['current_action'] ?? ''));
      if ($action !== '') {
        if (preg_match_all('/\b\d{8}-(?:release-preflight-test-suite|release-ready)-[A-Za-z0-9._-]+\b/', $action, $m)) {
          foreach (($m[0] ?? []) as $tok) {
            $tokens[] = trim((string) $tok);
          }
        }
      }

      foreach ($tokens as $tok) {
        if ($tok === '') {
          continue;
        }
        // Examples:
        // 20260224-release-preflight-test-suite-20260224-coordinated-release
        // 20260224-release-ready-20260224-coordinated-release
        if (preg_match('/^\d{8}-(?:release-preflight-test-suite|release-ready)-(.+)$/', $tok, $m)) {
          $rid = trim((string) ($m[1] ?? ''));
          if ($rid !== '' && preg_match('/^\d{8}-[A-Za-z0-9._-]+$/', $rid)) {
            $candidates[] = $rid;
          }
        }
      }
    }

    if (!$candidates) {
      return '';
    }

    sort($candidates);
    return (string) end($candidates);
  }

  /**
   * Infers release id from QA preflight inbox/outbox artifact naming.
   *
   * Pattern:
   *   <date>-release-preflight-test-suite-<release-id>
   */
  private function inferReleaseIdFromQaPreflightArtifacts(): string {
    $paths = [];

    $inbox = glob($this->langgraphPath('sessions/qa-*/inbox/*release-preflight-test-suite-*')) ?: [];
    foreach ($inbox as $p) {
      $paths[] = $p;
    }

    $outbox = glob($this->langgraphPath('sessions/qa-*/outbox/*release-preflight-test-suite-*.md')) ?: [];
    foreach ($outbox as $p) {
      $paths[] = $p;
    }

    if (!$paths) {
      return '';
    }

    $best_id = '';
    $best_mtime = 0;
    foreach ($paths as $path) {
      $name = basename($path);
      $name = preg_replace('/\.md$/', '', $name) ?? $name;
      if (!preg_match('/^\d{8}-release-preflight-test-suite-(.+)$/', $name, $m)) {
        continue;
      }
      $rid = trim((string) ($m[1] ?? ''));
      if ($rid === '' || !preg_match('/^\d{8}-[A-Za-z0-9._-]+$/', $rid)) {
        continue;
      }

      $mtime = @filemtime($path);
      if (!is_int($mtime)) {
        $mtime = 0;
      }
      if ($mtime > $best_mtime || ($mtime === $best_mtime && strcmp($rid, $best_id) > 0)) {
        $best_mtime = $mtime;
        $best_id = $rid;
      }
    }

    return $best_id;
  }

  /**
   * Fallback release-id inference from PM release-signoff artifacts in HQ.
   */
  private function inferReleaseIdFromSignoffs(): string {
    $pattern = $this->langgraphPath('sessions/pm-*/artifacts/release-signoffs/*.md');
    $files = glob($pattern) ?: [];
    if (!$files) {
      return '';
    }

    $best_id = '';
    $best_mtime = 0;
    foreach ($files as $path) {
      $mtime = @filemtime($path);
      if (!is_int($mtime)) {
        $mtime = 0;
      }
      $rid = pathinfo($path, PATHINFO_FILENAME);
      $rid = trim((string) $rid);
      if ($rid === '') {
        continue;
      }
      if (!preg_match('/^\d{8}-[A-Za-z0-9._-]+$/', $rid)) {
        continue;
      }
      // Release ids are generally YYYYMMDD-*; accept best-effort fallback too.
      if ($mtime > $best_mtime || ($mtime === $best_mtime && strcmp($rid, $best_id) > 0)) {
        $best_mtime = $mtime;
        $best_id = $rid;
      }
    }

    return $best_id;
  }

  /**
   * Builds a nested accordion view of active work by release stage and product.
   *
   * Render placement requirement (per request): above the agent table, below the
   * rest of the dashboard content.
   */
  private function buildReleaseStageAccordion(array $agents): array {
    $relevant_agents = [];
    foreach ($agents as $a) {
      if (!is_array($a)) {
        continue;
      }
      $status = strtolower(trim((string) ($a['status'] ?? '')));
      if ($status === 'paused') {
        continue;
      }
      $inbox_count = (int) ($a['inbox_count'] ?? 0);
      $role = trim((string) ($a['role'] ?? ''));
      $agent_id = trim((string) ($a['agent_id'] ?? ''));
      $meta = (!empty($a['meta']) && is_array($a['meta'])) ? $a['meta'] : [];
      $stage3_velocity = (!empty($meta['stage3_velocity']) && is_array($meta['stage3_velocity'])) ? $meta['stage3_velocity'] : [];
      $latest_open_issues = (int) ($stage3_velocity['latest_open_issues'] ?? 0);
      $is_dev_seat = ($role === 'software-developer' || str_starts_with($agent_id, 'dev-'));
      $has_open_issues = ($is_dev_seat && $latest_open_issues > 0);

      if ($status === 'in_progress' || $inbox_count > 0 || $status === 'blocked' || $status === 'needs-info' || $has_open_issues) {
        $a['stage3_latest_open_issues'] = $latest_open_issues;
        $a['stage3_resolved_per_15'] = (float) ($stage3_velocity['resolved_per_15_minutes'] ?? 0);
        $a['stage3_handoff_signal'] = trim((string) ($stage3_velocity['workflow']['handoff_signal'] ?? ''));
        $relevant_agents[] = $a;
      }
    }

    $stages = [
      0 => 'Stage 0 — Start of cycle (scope freeze + suite readiness)',
      1 => 'Stage 1 — Intake (backlog; next cycle once frozen)',
      2 => 'Stage 2 — Triage / routing / dedupe',
      3 => 'Stage 3 — Execution (implementation)',
      4 => 'Stage 4 — Verification (QA regression loop)',
      5 => 'Stage 5 — Release candidate assembly',
      6 => 'Stage 6 — Signoff (coordinated release)',
      7 => 'Stage 7 — Ship',
      8 => 'Stage 8 — Post-release QA (production)',
      9 => 'Stage 9 — Continuous improvement',
    ];

    $by_stage_product = [];
    foreach ($relevant_agents as $a) {
      $stage = $this->inferReleaseStage($a);
      $website = trim((string) ($a['website'] ?? ''));
      $module = trim((string) ($a['module'] ?? ''));
      $product_key = $website . '::' . $module;
      if (!isset($by_stage_product[$stage])) {
        $by_stage_product[$stage] = [];
      }
      if (!isset($by_stage_product[$stage][$product_key])) {
        $by_stage_product[$stage][$product_key] = [
          'website' => $website,
          'module' => $module,
          'agents' => [],
        ];
      }
      $by_stage_product[$stage][$product_key]['agents'][] = $a;
    }

    // Infer "current" stage from the work distribution.
    // Priority: most active agents, then blocked, then queued.
    $current_stage_id = 0;
    $best_score = -1;
    foreach (array_keys($stages) as $sid) {
      $products = $by_stage_product[$sid] ?? [];
      $active = 0;
      $queued = 0;
      $blocked = 0;
      $open_issue_idle = 0;
      foreach ($products as $p) {
        $agents_in_product = (is_array($p['agents'] ?? NULL)) ? $p['agents'] : [];
        foreach ($agents_in_product as $a) {
          if (!is_array($a)) {
            continue;
          }
          $s = strtolower(trim((string) ($a['status'] ?? '')));
          $c = (int) ($a['inbox_count'] ?? 0);
          if ($s === 'in_progress') {
            $active++;
          }
          elseif ($s === 'blocked' || $s === 'needs-info') {
            $blocked++;
          }
          elseif ($c > 0) {
            $queued++;
          }
          elseif ($s === 'idle' && (int) ($a['stage3_latest_open_issues'] ?? 0) > 0) {
            $open_issue_idle++;
          }
        }
      }
      $score = ($active * 1000000) + ($blocked * 10000) + ($open_issue_idle * 100) + $queued;
      if ($score > $best_score) {
        $best_score = $score;
        $current_stage_id = (int) $sid;
      }
    }

    $build = [
      '#type' => 'container',
      'title' => [
        '#markup' => '<h3>Release stage (active work, grouped by product)</h3>',
      ],
    ];

    if (!$relevant_agents) {
      $build['empty'] = [
        '#markup' => '<em>No active, queued, or blocked work is currently visible.</em>',
      ];
      return $build;
    }

    foreach ($stages as $stage_id => $stage_title) {
      $products = $by_stage_product[$stage_id] ?? [];
      $agent_count = 0;
      $active_count = 0;
      $queued_count = 0;
      $blocked_count = 0;
      $open_issue_idle_count = 0;
      foreach ($products as $p) {
        $agents_in_product = (is_array($p['agents'] ?? NULL)) ? $p['agents'] : [];
        $agent_count += count($agents_in_product);
        foreach ($agents_in_product as $a) {
          if (!is_array($a)) {
            continue;
          }
          $s = strtolower(trim((string) ($a['status'] ?? '')));
          $c = (int) ($a['inbox_count'] ?? 0);
          if ($s === 'in_progress') {
            $active_count++;
          }
          elseif ($s === 'blocked' || $s === 'needs-info') {
            $blocked_count++;
          }
          elseif ($c > 0) {
            $queued_count++;
          }
          elseif ($s === 'idle' && (int) ($a['stage3_latest_open_issues'] ?? 0) > 0) {
            $open_issue_idle_count++;
          }
        }
      }
      $product_count = count($products);
      $title = $stage_title
        . ' (' . (string) $product_count . ' product' . ($product_count === 1 ? '' : 's')
        . ' — ' . (string) $active_count . ' active, ' . (string) $queued_count . ' queued, ' . (string) $blocked_count . ' blocked, ' . (string) $open_issue_idle_count . ' idle-open-issues)';

      if ((int) $stage_id === (int) $current_stage_id) {
        $title = 'CURRENT → ' . $title;
      }

      $stage_build = [
        '#type' => 'details',
        '#title' => $this->t('@t', ['@t' => $title]),
        '#open' => ((int) $stage_id === (int) $current_stage_id),
      ];

      if (!$products) {
        $stage_build['empty'] = [
          '#markup' => '<em>No active, queued, or blocked work inferred for this stage.</em>',
        ];
        $build['stage_' . (string) $stage_id] = $stage_build;
        continue;
      }

      // Stable ordering.
      ksort($products);
      foreach ($products as $product_key => $p) {
        $website = (string) ($p['website'] ?? '');
        $module = (string) ($p['module'] ?? '');
        $label = ($website ?: '-') . ' / ' . ($module ?: '-') . ' (' . (string) count($p['agents']) . ')';

        $items = [];
        foreach (($p['agents'] ?? []) as $a) {
          if (!is_array($a)) {
            continue;
          }
          $agent_id = (string) ($a['agent_id'] ?? '');
          $active_item_id = trim((string) ($a['active_item_id'] ?? ''));
          $next_item_id = trim((string) ($a['next_item_id'] ?? ''));
          $next_inbox_roi = (int) ($a['next_inbox_roi'] ?? 0);
          $inbox_count = (int) ($a['inbox_count'] ?? 0);
          $status = strtolower(trim((string) ($a['status'] ?? '')));
          $current_action = trim((string) ($a['current_action'] ?? ''));
          $role = trim((string) ($a['role'] ?? ''));
          $is_dev_row = ($role === 'software-developer' || str_starts_with($agent_id, 'dev-'));

          $agent_link = Link::fromTextAndUrl($agent_id, Url::fromRoute('copilot_agent_tracker.agent', ['agent_id' => $agent_id]))->toString();
          $parts = [$agent_link];

          if ($status !== '') {
            $parts[] = 'Status: ' . htmlspecialchars($status);
          }

          if ($status === 'in_progress' && $active_item_id !== '') {
            $item_link = Link::fromTextAndUrl(
              $active_item_id,
              Url::fromRoute('copilot_agent_tracker.agent_inbox_item', ['agent_id' => $agent_id, 'item_id' => $active_item_id])
            )->toString();
            $parts[] = 'Active: ' . $item_link;
          }
          elseif ($next_item_id !== '') {
            $item_link = Link::fromTextAndUrl(
              $next_item_id,
              Url::fromRoute('copilot_agent_tracker.agent_inbox_item', ['agent_id' => $agent_id, 'item_id' => $next_item_id])
            )->toString();
            $parts[] = 'Next: ' . $item_link;
            if ($next_inbox_roi > 0) {
              $parts[] = 'ROI: ' . (string) $next_inbox_roi;
            }
          }

          if ($inbox_count > 0) {
            $parts[] = 'Inbox: ' . (string) $inbox_count;
          }
          $latest_open_issues = (int) ($a['stage3_latest_open_issues'] ?? 0);
          $resolved_per_15 = (float) ($a['stage3_resolved_per_15'] ?? 0);
          $handoff_signal = trim((string) ($a['stage3_handoff_signal'] ?? ''));
          if ($is_dev_row || $latest_open_issues > 0) {
            $parts[] = 'Open issues: ' . (string) $latest_open_issues;
          }
          if ($is_dev_row || $resolved_per_15 > 0 || $latest_open_issues > 0) {
            $parts[] = 'Resolved/15m: ' . htmlspecialchars((string) $resolved_per_15);
          }
          if ($handoff_signal !== '') {
            $parts[] = 'Handoff: ' . htmlspecialchars($handoff_signal);
          }
          if ($current_action !== '') {
            $parts[] = 'Action: ' . htmlspecialchars($current_action);
          }
          $items[] = Markup::create(implode(' — ', $parts));
        }

        $stage_build['product_' . md5($product_key)] = [
          '#type' => 'details',
          '#title' => $this->t('@t', ['@t' => $label]),
          '#open' => FALSE,
          'items' => [
            '#type' => 'container',
            'list' => $this->renderSimpleList($items ?: [Markup::create('<em>No visible work.</em>')]),
          ],
        ];
      }

      $build['stage_' . (string) $stage_id] = $stage_build;
    }

    return $build;
  }

  /**
   * Best-effort inference of release stage for an active agent.
   *
   * Uses role + active inbox item id patterns. This is intentionally simple and
   * uses only already-published tracker fields.
   */
  private function inferReleaseStage(array $a): int {
    $role = trim((string) ($a['role'] ?? ''));
    $agent_id = trim((string) ($a['agent_id'] ?? ''));
    $active_item_id = trim((string) ($a['active_item_id'] ?? ''));
    $current_action = strtolower(trim((string) ($a['current_action'] ?? '')));
    $meta = (!empty($a['meta']) && is_array($a['meta'])) ? $a['meta'] : [];

    if ($active_item_id !== '' && str_contains($active_item_id, 'release-preflight-test-suite')) {
      return 0;
    }

    // If QA is actively auditing production, treat it as post-release QA.
    if (($role === 'tester' || str_starts_with($agent_id, 'qa-')) && !empty($meta['qa_last_audit']) && is_array($meta['qa_last_audit'])) {
      $base = strtolower((string) ($meta['qa_last_audit']['base_url'] ?? ''));
      if (str_starts_with($base, 'https://forseti.life') || str_starts_with($base, 'https://dungeoncrawler.forseti.life')) {
        if (str_contains($current_action, 'audit') || str_contains($current_action, 'qa')) {
          return 8;
        }
      }
    }

    if ($role === 'tester' || str_starts_with($agent_id, 'qa-')) {
      return 4;
    }
    if ($role === 'software-developer' || str_starts_with($agent_id, 'dev-')) {
      return 3;
    }
    if ($role === 'business-analyst' || str_starts_with($agent_id, 'ba-')) {
      return 2;
    }
    if ($role === 'product-manager' || str_starts_with($agent_id, 'pm-')) {
      if (str_contains($current_action, 'signoff') || str_contains($active_item_id, 'signoff')) {
        return 6;
      }
      if (str_contains($current_action, 'ship') || str_contains($current_action, 'push')) {
        return 7;
      }
      return 5;
    }

    return 3;
  }

  /**
   * Consolidated entry point for Keith/CEO pending decisions.
   *
   * This report is now rendered within the main dashboard page to avoid
   * splitting the workflow across two separate admin reports.
   */
  public function waitingOnKeithRedirect(): RedirectResponse {
    $url = Url::fromRoute('copilot_agent_tracker.langgraph_dashboard');
    return new RedirectResponse($url->toString(), 301);
  }

  /**
   * Backward-compatible controller method.
   *
   * Some environments may temporarily have stale route caches that still
   * reference `::waitingOnKeith`. Keep this method callable and delegate to the
   * canonical redirect.
   */
  public function waitingOnKeith(): RedirectResponse {
    return $this->waitingOnKeithRedirect();
  }

  /**
   * Inbox-style view for Keith/CEO pending decisions.
   */
  private function buildWaitingOnKeithView(): array {
    $self_agent_prefix = 'ceo-copilot';
    $resolved = $this->database->select('copilot_agent_tracker_inbox_resolutions', 'r')
      ->fields('r', ['item_id'])
      ->condition('resolved', 1)
      ->execute()
      ->fetchCol();
    $resolved = array_fill_keys($resolved ?: [], TRUE);

    $rows = $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', ['agent_id', 'role', 'website', 'module', 'status', 'current_action', 'last_seen', 'metadata'])
      ->orderBy('website', 'ASC')
      ->orderBy('module', 'ASC')
      ->orderBy('role', 'ASC')
      ->orderBy('last_seen', 'DESC')
      ->execute()
      ->fetchAll();

    $ceo_meta = [];
    $ceo_last_seen = 0;
    $agent_meta = [];

    $is_legacy_agent_id = static function (string $agent_id): bool {
      // Legacy bug: HQ briefly published per-inbox-item "agent ids" into the tracker.
      // These contain dated/task suffixes like:
      //   pm-foo-20260220-product-...
      //   ...-reply-keith-...
      //   ...-needs-...
      //   ...-clarify-escalation-...
      if ($agent_id === '') {
        return TRUE;
      }
      if (preg_match('/-\\d{8}(-|$)/', $agent_id)) {
        return TRUE;
      }
      if (str_contains($agent_id, '-reply-keith-') || str_contains($agent_id, '-needs-') || str_contains($agent_id, '-clarify-escalation-')) {
        return TRUE;
      }
      return FALSE;
    };

    foreach ($rows as $row) {
      $meta = [];
      if (!empty($row->metadata)) {
        try {
          $meta = Json::decode((string) $row->metadata) ?? [];
        }
        catch (\Throwable) {
          $meta = [];
        }
      }

      $agent_id_for_meta = (string) ($row->agent_id ?? '');
      if ($agent_id_for_meta === $self_agent_prefix || str_starts_with($agent_id_for_meta, $self_agent_prefix . '-')) {
        $seen = (int) ($row->last_seen ?? 0);
        if ($seen >= $ceo_last_seen) {
          $ceo_last_seen = $seen;
          $ceo_meta = is_array($meta) ? $meta : [];
        }
      }
      $agent_meta[$agent_id_for_meta] = is_array($meta) ? $meta : [];

      $agent_id = trim((string) ($row->agent_id ?? ''));
      if ($agent_id === '') {
        continue;
      }

      // Include CEO agents in the pending-agent list so the report reflects the full set of tracked seats.

      // Hide legacy per-item IDs so the report shows only real agent seats.
      if ($is_legacy_agent_id($agent_id)) {
        continue;
      }

      $status = trim((string) ($row->status ?? ''));
      $is_paused = strtolower($status) === 'paused';

      $inbox_count = (int) ($meta['inbox_count'] ?? 0);

      // Prefer effective ROI (includes small time-based aging bonus from HQ).
      // Fall back to base ROI for older payloads.
      $next_inbox_roi = (int) ($meta['next_inbox_effective_roi'] ?? ($meta['next_inbox_roi'] ?? 1));
      if ($next_inbox_roi < 1) {
        $next_inbox_roi = 1;
      }

      // Sort key: prioritize agents with pending inbox items, then highest ROI.
      // (ROI is published from HQ as metadata.next_inbox_roi.)
      // Paused seats should still be visible on this page, but not prioritized.
      $sort_has_inbox = (!$is_paused && $inbox_count > 0) ? 1 : 0;
      $sort_roi = (!$is_paused && $sort_has_inbox) ? $next_inbox_roi : 0;
      $sort_last_seen = (int) ($row->last_seen ?? 0);

      $website_cell = trim((string) ($row->website ?? ''));
      $module_cell = trim((string) ($row->module ?? ''));
      $role_cell = trim((string) ($row->role ?? ''));
      $status_cell = trim((string) ($row->status ?? ''));
      $action_cell = trim((string) ($row->current_action ?? ''));
      $last_seen_cell = $row->last_seen ? $this->dateFormatter->format((int) $row->last_seen, 'short') : '-';

      $pending_items[] = [
        'sort_has_inbox' => $sort_has_inbox,
        'sort_roi' => $sort_roi,
        'sort_last_seen' => $sort_last_seen,
        'agent_id' => $agent_id,
        'row' => [
        Link::fromTextAndUrl($agent_id, Url::fromRoute('copilot_agent_tracker.agent', ['agent_id' => $agent_id]))->toString(),
        $website_cell !== '' ? $website_cell : '-',
        $module_cell !== '' ? $module_cell : '-',
        $role_cell !== '' ? $role_cell : '-',
        $status_cell !== '' ? $status_cell : '-',
        $action_cell !== '' ? $action_cell : '-',
        (string) $inbox_count,
        $last_seen_cell,
        ],
      ];

      $pending_agent_ids[$agent_id] = TRUE;
    }

    // Ensure *all* configured seats are represented, even if a seat hasn't
    // published telemetry yet (or was recently added).
    $configured = $ceo_meta['configured_seats'] ?? [];
    if (is_array($configured)) {
      foreach ($configured as $maybe_id) {
        $id = trim((string) $maybe_id);
        if ($id === '' || !is_string($maybe_id) && !is_numeric($maybe_id)) {
          continue;
        }
        if (!empty($pending_agent_ids[$id])) {
          continue;
        }
        // Keep legacy noise out of the report.
        if ($is_legacy_agent_id($id)) {
          continue;
        }

        // If the seat exists in the agents table but was excluded earlier for
        // some reason, let it show up normally (link works).
        // Otherwise, render a placeholder row without a broken link.
        $pending_items[] = [
          'sort_has_inbox' => 0,
          'sort_roi' => 0,
          'sort_last_seen' => 0,
          'agent_id' => $id,
          'row' => [
            $id,
            '-',
            '-',
            '-',
            'missing',
            'no telemetry yet',
            '0',
            '-',
          ],
        ];
        $pending_agent_ids[$id] = TRUE;
      }
    }

    // Apply org-level ordering: highest ROI first, while keeping agents with no inbox items at the bottom.
    usort($pending_items, static function (array $a, array $b): int {
      // Has inbox first.
      $c = ($b['sort_has_inbox'] ?? 0) <=> ($a['sort_has_inbox'] ?? 0);
      if ($c !== 0) {
        return $c;
      }
      // Highest ROI first.
      $c = ($b['sort_roi'] ?? 0) <=> ($a['sort_roi'] ?? 0);
      if ($c !== 0) {
        return $c;
      }
      // Most recently seen first.
      $c = ($b['sort_last_seen'] ?? 0) <=> ($a['sort_last_seen'] ?? 0);
      if ($c !== 0) {
        return $c;
      }
      // Stable-ish tie-breaker.
      return strcmp((string) ($a['agent_id'] ?? ''), (string) ($b['agent_id'] ?? ''));
    });

    foreach ($pending_items as $it) {
      if (!empty($it['row']) && is_array($it['row'])) {
        $pending_rows[] = $it['row'];
      }
    }

    // Compose dropdown includes ALL agents, including CEO threads.
    // CEO threads are ordered first for convenience.
    $agent_options = [];
    $ceo_ids = [];
    foreach ($rows as $row) {
      $agent_id = trim((string) ($row->agent_id ?? ''));
      if ($agent_id === '' || !str_starts_with($agent_id, $self_agent_prefix)) {
        continue;
      }
      $ceo_ids[] = $agent_id;
    }
    sort($ceo_ids);
    // Force ceo-copilot first if present.
    if (in_array($self_agent_prefix, $ceo_ids, TRUE)) {
      $ceo_ids = array_values(array_unique(array_merge([$self_agent_prefix], array_diff($ceo_ids, [$self_agent_prefix]))));
    }

    $all_ids = [];
    foreach ($rows as $row) {
      $agent_id = trim((string) ($row->agent_id ?? ''));
      if ($agent_id !== '') {
        $all_ids[] = $agent_id;
      }
    }
    $all_ids = array_values(array_unique($all_ids));
    sort($all_ids);
    // Prefer configured seat ordering if HQ published it.
    $configured_ids = [];
    if (is_array($ceo_meta['configured_seats'] ?? NULL)) {
      foreach (($ceo_meta['configured_seats'] ?? []) as $maybe_id) {
        $id = trim((string) $maybe_id);
        if ($id !== '' && !$is_legacy_agent_id($id)) {
          $configured_ids[] = $id;
        }
      }
      $configured_ids = array_values(array_unique($configured_ids));
      sort($configured_ids);
    }

    $ordered_ids = array_values(array_unique(array_merge($ceo_ids, $configured_ids, $all_ids)));

    $by_id = [];
    foreach ($rows as $row) {
      $agent_id = trim((string) ($row->agent_id ?? ''));
      if ($agent_id !== '') {
        $by_id[$agent_id] = $row;
      }
    }

    foreach ($ordered_ids as $agent_id) {
      // Keep CEO threads, but hide legacy per-item IDs from the compose dropdown.
      if ($agent_id !== $self_agent_prefix && !str_starts_with($agent_id, $self_agent_prefix . '-') && $is_legacy_agent_id($agent_id)) {
        continue;
      }
      $row = $by_id[$agent_id] ?? NULL;
      $website = trim((string) ($row?->website ?? ''));
      $module = trim((string) ($row?->module ?? ''));
      $role = trim((string) ($row?->role ?? ''));
      $label = $agent_id;
      if ($website !== '' || $module !== '' || $role !== '') {
        $label .= ' (' . ($website ?: '-') . '/' . ($module ?: '-') . ($role ? (' - ' . $role) : '') . ')';
      }
      $agent_options[$agent_id] = $label;
    }

    $sent = $this->database->select('copilot_agent_tracker_replies', 'r')
      ->fields('r', ['id', 'to_agent_id', 'in_reply_to', 'message', 'created', 'consumed', 'consumed_at', 'hq_item_id'])
      ->condition('dismissed', 0)
      ->orderBy('created', 'DESC')
      ->range(0, 50)
      ->execute()
      ->fetchAll();

    $messages = [];
    foreach (($ceo_meta['inbox_messages'] ?? []) as $m) {
      if (!is_array($m)) {
        continue;
      }
      $item_id = (string) ($m['item_id'] ?? '');
      if ($item_id === '') {
        continue;
      }
      if (!empty($resolved[$item_id])) {
        continue;
      }
      $messages[] = $m;
    }

    $ceo_by_agent = [];
    foreach ($messages as $m) {
      $from = trim((string) ($m['from_agent'] ?? ''));
      if ($from !== '') {
        $ceo_by_agent[$from][] = $m;
      }
    }

    $message_rows = [];
    foreach ($messages as $m) {
      $item_id = (string) ($m['item_id'] ?? '');
      $from = (string) ($m['from_agent'] ?? '');
      $subject = (string) ($m['subject'] ?? $item_id);
      $body = (string) ($m['body'] ?? '');
      $website = (string) ($m['website'] ?? '');
      $module = (string) ($m['module'] ?? '');
      $role = (string) ($m['role'] ?? '');
      $decision = (string) ($m['decision_needed'] ?? '');
      $recommendation = (string) ($m['recommendation'] ?? '');
      $preview = mb_substr(trim($body), 0, 160);

      $subject_link = Link::fromTextAndUrl($subject, Url::fromRoute('copilot_agent_tracker.waiting_on_keith_message', ['item_id' => $item_id]))->toString();
      $approve_link = '';
      if ($from !== '' && strlen($from) <= 128) {
        $token = $this->csrfToken->get('approve-inbox:' . $item_id);
        $approve_link = ' ' . Link::fromTextAndUrl(
          $this->t('Approve'),
          Url::fromRoute('copilot_agent_tracker.waiting_on_keith_approve', ['item_id' => $item_id], ['query' => ['token' => $token]])
        )->toString();
      }

      $message_rows[] = [
        Markup::create($subject_link . $approve_link),
        $from,
        ($website ?: '-') . ' / ' . ($module ?: '-'),
        $role ?: '-',
        mb_substr(trim($decision), 0, 80),
        mb_substr(trim($recommendation), 0, 80),
        $preview,
      ];
    }

    $sent_thread_items = [];
    foreach ($sent as $s) {
      $to_agent_id = (string) ($s->to_agent_id ?? '');
      $created = (int) ($s->created ?? 0);
      $title = ($created ? $this->dateFormatter->format($created, 'short') : '-') . ' -> ' . $to_agent_id;
      $dismiss_token = $this->csrfToken->get('dismiss-sent:' . (int) $s->id);
      $dismiss_link = Link::fromTextAndUrl(
        $this->t('Dismiss'),
        Url::fromRoute('copilot_agent_tracker.dismiss_sent_message', ['reply_id' => (int) $s->id], ['query' => ['token' => $dismiss_token]])
      )->toString();

      $hq_item_id = trim((string) ($s->hq_item_id ?? ''));
      $state = 'Queued';
      if (!empty($s->consumed)) {
        $state = 'Delivered';
        $items = $agent_meta[$to_agent_id]['inbox_items'] ?? [];
        if ($hq_item_id !== '' && is_array($items) && in_array($hq_item_id, $items, TRUE)) {
          $state = 'Pending (in agent inbox)';
        }
      }

      $sub_links = [];
      $sent_ymd = $created ? gmdate('Ymd', $created) : '';
      foreach (($ceo_by_agent[$to_agent_id] ?? []) as $m) {
        $item_id = (string) ($m['item_id'] ?? '');
        if ($item_id === '') {
          continue;
        }
        if ($sent_ymd !== '' && strcmp(substr($item_id, 0, 8), $sent_ymd) < 0) {
          continue;
        }
        $sub_links[] = Link::fromTextAndUrl($item_id, Url::fromRoute('copilot_agent_tracker.waiting_on_keith_message', ['item_id' => $item_id]))->toString();
      }

      $sent_thread_items[] = [
        '#type' => 'details',
        '#title' => $title,
        '#open' => FALSE,
        'meta' => [
          '#markup' => '<p><strong>Status:</strong> ' . $this->t('@s', ['@s' => $state]) . ' &nbsp; ' . $dismiss_link . '<br>'
            . '<strong>HQ item:</strong> ' . $this->t('@h', ['@h' => ($hq_item_id ?: '-')]) . '</p>',
        ],
        'message' => [
          '#type' => 'item',
          '#title' => $this->t('Message'),
          '#markup' => '<pre style="white-space:pre-wrap;max-height:240px;overflow:auto;">' . htmlspecialchars((string) ($s->message ?? '')) . '</pre>',
        ],
        'sub' => [
          '#type' => 'item',
          '#title' => $this->t('Sub-items'),
          '#markup' => $sub_links ? ('<ul><li>' . implode('</li><li>', $sub_links) . '</li></ul>') : '<em>None detected.</em>',
        ],
      ];
    }

    // Organizational priorities (published from HQ into CEO metadata).
    $priority_items = [];
    $org_priorities = $ceo_meta['org_priorities'] ?? [];
    if (is_array($org_priorities)) {
      foreach ($org_priorities as $p) {
        if (!is_array($p)) {
          continue;
        }
        $k = trim((string) ($p['key'] ?? ''));
        $score = $p['score'] ?? NULL;
        if ($k === '' || $score === NULL) {
          continue;
        }
        $priority_items[] = $this->t('@k: @v', ['@k' => $k, '@v' => (string) $score]);
      }
    }

    // Org-wide automation controls (status published from HQ; toggle delegates back to HQ).
    $org_control = $ceo_meta['org_control'] ?? [];
    if (!is_array($org_control)) {
      $org_control = [];
    }
    $org_control += [
      'enabled' => TRUE,
      'updated_at' => NULL,
      'updated_by' => NULL,
      'reason' => NULL,
    ];

    return [
      '#type' => 'container',
      'priorities' => [
        '#type' => 'container',
        'title' => [
          '#markup' => '<h2>Organizational priorities</h2>',
        ],
        'list' => $priority_items ? [
          '#type' => 'container',
          'items' => $this->renderSimpleList($priority_items),
        ] : [
          '#markup' => '<em>No priorities published yet.</em>',
        ],
      ],
      'org_controls' => [
        '#type' => 'details',
        '#title' => $this->t('Org automation'),
        '#open' => FALSE,
        'form' => $this->formBuilder()->getForm(OrgAutomationToggleForm::class, $org_control),
      ],
      'help' => [
        '#type' => 'details',
        '#title' => $this->t('Process flow (authority)'),
        '#open' => FALSE,
        '#markup' => '<p><strong>Purpose:</strong> keep work progressing with a single inbox/outbox per configured agent seat, with a clean audit trail.</p>'
          . '<p><strong>Authority for non-CEO agents:</strong> HQ org-wide + role instructions (copilot-sessions-hq: <code>org-chart/org-wide.instructions.md</code> and <code>org-chart/roles/*.instructions.md</code>). Agents select the next work item from their seat inbox; if idle, they generate role-appropriate work.</p>'
          . '<hr>'
          . '<h3>Keith (Human owner) — what to do next</h3>'
          . '<ol>'
          . '<li>Open this page and review the <strong>Messages</strong> table (CEO inbox items needing decision).</li>'
          . '<li>For each message: read <em>Decision needed</em> + <em>Recommendation</em>, then decide: approve / request clarification / deprioritize.</li>'
          . '<li>If you approve: click <strong>Approve</strong> (it sends an “approved” reply back to the originating agent and resolves the item).</li>'
          . '<li>If you need changes: reply with specific direction (scope, stage breaks, acceptance criteria, constraints).</li>'
          . '<li>Check <strong>Sent messages</strong> to ensure replies were delivered and are pending in the intended agent inbox.</li>'
          . '<li>Daily/periodic: confirm top OKR priorities and adjust only the smallest number of constraints needed to unblock execution.</li>'
          . '</ol>'
          . '<h3>CEO (ceo-copilot) — operating loop</h3>'
          . '<ol>'
          . '<li>Run HQ status + blocker review (HQ scripts): <code>scripts/hq-status.sh</code> and <code>scripts/hq-blockers.sh</code>.</li>'
          . '<li>Ensure every PM seat has an active queue for BA/Dev/QA (one inbox per seat; no new agent IDs).</li>'
          . '<li>When blocked: either provide missing inputs (files/paths/URLs) in a single inbox item, or escalate to Keith with options.</li>'
          . '<li>When idle: seed continuous improvement work for HQ processes and tooling (delegated as inbox items).</li>'
          . '<li>Publish status telemetry from HQ to this tracker (cron) and keep the agent list clean (configured seats only).</li>'
          . '</ol>',
      ],
      'compose' => [
        '#type' => 'details',
        '#title' => $this->t('Compose message'),
        '#open' => FALSE,
        'form' => $this->formBuilder()->getForm(ComposeAgentMessageForm::class, $agent_options),
      ],
      'sent_threads' => [
        '#type' => 'details',
        '#title' => $this->t('Sent messages'),
        '#open' => FALSE,
        'items' => $sent_thread_items ?: [
          '#markup' => '<em>No sent messages yet.</em>',
        ],
      ],
      'todo_title' => [
        '#type' => 'container',
        '#markup' => '<h2 id="todo-for-keith">Todo for Keith</h2>',
      ],
      'messages' => [
        '#type' => 'table',
        '#header' => ['Subject', 'From', 'Product', 'Role', 'Decision needed', 'Recommendation', 'Preview'],
        '#rows' => $message_rows,
        '#empty' => $this->t('No inbox items detected.'),
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Quickly approve a Waiting on Keith inbox item (send "approved" + resolve).
   */
  public function approveWaitingOnKeithItem(string $item_id): RedirectResponse {
    $request = $this->dashboardRequestStack->getCurrentRequest();
    $token = (string) ($request?->query->get('token') ?? '');
    if (!$this->csrfToken->validate($token, 'approve-inbox:' . $item_id)) {
      throw new AccessDeniedHttpException();
    }

    $row = $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', ['metadata'])
      ->condition('agent_id', 'ceo-copilot%', 'LIKE')
      ->orderBy('last_seen', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    $meta = [];
    if (!empty($row['metadata'])) {
      try {
        $meta = Json::decode((string) $row['metadata']) ?? [];
      }
      catch (\Throwable) {
        $meta = [];
      }
    }

    $message = NULL;
    foreach (($meta['inbox_messages'] ?? []) as $m) {
      if (is_array($m) && (string) ($m['item_id'] ?? '') === $item_id) {
        $message = $m;
        break;
      }
    }
    if (!$message) {
      throw new NotFoundHttpException();
    }

    $to_agent_id = trim((string) ($message['from_agent'] ?? ''));
    if ($to_agent_id === '' || strlen($to_agent_id) > 128) {
      $this->messenger()->addError($this->t('Cannot approve: missing or invalid destination agent.'));
      return new RedirectResponse(Url::fromRoute('copilot_agent_tracker.dashboard', [], ['fragment' => 'todo-for-keith'])->toString());
    }

    $now = (int) \Drupal::time()->getRequestTime();
    $this->database->insert('copilot_agent_tracker_replies')
      ->fields([
        'to_agent_id' => $to_agent_id,
        'in_reply_to' => $item_id,
        'message' => 'approved',
        'created' => $now,
        'consumed' => 0,
        'consumed_at' => 0,
      ])
      ->execute();

    $this->database->merge('copilot_agent_tracker_inbox_resolutions')
      ->key('item_id', $item_id)
      ->fields([
        'resolved' => 1,
        'resolved_at' => $now,
        'resolved_by_uid' => (int) $this->currentUser()->id(),
      ])
      ->execute();

    $this->messenger()->addStatus($this->t('Approved and removed from inbox.'));
    return new RedirectResponse(Url::fromRoute('copilot_agent_tracker.dashboard', [], ['fragment' => 'todo-for-keith'])->toString());
  }

  /**
   * Dismiss a sent message thread from the Waiting on Keith page.
   */
  public function dismissSentMessage(int $reply_id): RedirectResponse {
    $request = $this->dashboardRequestStack->getCurrentRequest();
    $token = (string) ($request?->query->get('token') ?? '');
    if (!$this->csrfToken->validate($token, 'dismiss-sent:' . $reply_id)) {
      throw new AccessDeniedHttpException();
    }

    $this->database->update('copilot_agent_tracker_replies')
      ->fields([
        'dismissed' => 1,
        'dismissed_at' => (int) \Drupal::time()->getRequestTime(),
        'dismissed_by_uid' => (int) $this->currentUser()->id(),
      ])
      ->condition('id', $reply_id)
      ->execute();

    $this->messenger()->addStatus($this->t('Sent message dismissed.'));
    return new RedirectResponse(Url::fromRoute('copilot_agent_tracker.dashboard', [], ['fragment' => 'todo-for-keith'])->toString());
  }

  /**
   * Message detail view with reply form.
   */
  public function waitingOnKeithMessage(string $item_id): array {
    $row = $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', ['metadata'])
      ->condition('agent_id', 'ceo-copilot%', 'LIKE')
      ->orderBy('last_seen', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    $meta = [];
    if (!empty($row['metadata'])) {
      try {
        $meta = Json::decode((string) $row['metadata']) ?? [];
      }
      catch (\Throwable) {
        $meta = [];
      }
    }

    $message = NULL;
    foreach (($meta['inbox_messages'] ?? []) as $m) {
      if (is_array($m) && (string) ($m['item_id'] ?? '') === $item_id) {
        $message = $m;
        break;
      }
    }
    if (!$message) {
      throw new NotFoundHttpException();
    }

    $from_agent = (string) ($message['from_agent'] ?? '');
    $subject = (string) ($message['subject'] ?? $item_id);
    $body = (string) ($message['body'] ?? '');
    $website = (string) ($message['website'] ?? '');
    $module = (string) ($message['module'] ?? '');
    $role = (string) ($message['role'] ?? '');
    $decision = (string) ($message['decision_needed'] ?? '');
    $recommendation = (string) ($message['recommendation'] ?? '');

    return [
      '#type' => 'container',
      'header' => [
        '#markup' => '<h2>' . $this->t('Message: @subject', ['@subject' => $subject]) . '</h2>'
          . '<p><strong>' . $this->t('From') . ':</strong> ' . $this->t('@from', ['@from' => $from_agent ?: '-']) . '</p>'
          . '<p><strong>' . $this->t('Product') . ':</strong> ' . $this->t('@p', ['@p' => ($website ?: '-') . ' / ' . ($module ?: '-')]) . '</p>'
          . '<p><strong>' . $this->t('Role') . ':</strong> ' . $this->t('@r', ['@r' => $role ?: '-']) . '</p>',
      ],
      'decision' => [
        '#type' => 'details',
        '#title' => $this->t('Decision needed'),
        '#open' => TRUE,
        'content' => [
          '#type' => 'textarea',
          '#title' => $this->t('Decision'),
          '#value' => $decision,
          '#rows' => 6,
          '#attributes' => ['readonly' => 'readonly'],
        ],
      ],
      'recommendation' => [
        '#type' => 'details',
        '#title' => $this->t('Recommendation'),
        '#open' => TRUE,
        'content' => [
          '#type' => 'textarea',
          '#title' => $this->t('Recommendation'),
          '#value' => $recommendation,
          '#rows' => 6,
          '#attributes' => ['readonly' => 'readonly'],
        ],
      ],
      'body' => [
        '#type' => 'details',
        '#title' => $this->t('Message body'),
        '#open' => TRUE,
        'content' => [
          '#type' => 'textarea',
          '#title' => $this->t('Ask'),
          '#value' => $body,
          '#rows' => 18,
          '#attributes' => ['readonly' => 'readonly'],
        ],
      ],
      'reply' => $this->formBuilder()->getForm(InboxReplyForm::class, $item_id, $from_agent),
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Release Notes admin report (HQ-driven).
   *
   * Data source: CEO metadata published from HQ (scripts/publish-forseti-agent-tracker.sh)
   * under metadata.release_notes.
   */
  public function releaseNotes(): array {
    $entries = $this->loadReleaseNotesEntries();

    // State badge colours.
    $state_colours = [
      'shipped'        => '#1a7f37',
      'signed-off'     => '#0969da',
      'push-ready'     => '#8250df',
      'scope-activated'=> '#9a6700',
      'grooming'       => '#6e7781',
      'unknown'        => '#6e7781',
    ];

    $items = [];
    foreach ($entries as $e) {
      if (!is_array($e)) {
        continue;
      }
      $rid = trim((string) ($e['release_id'] ?? ''));
      if ($rid === '') {
        continue;
      }
      $state = trim((string) ($e['state'] ?? '')) ?: 'unknown';
      $colour = $state_colours[$state] ?? '#6e7781';
      $state_badge = '<span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:0.78em;'
        . 'font-weight:600;color:#fff;background:' . $colour . ';vertical-align:middle;margin-left:8px;">'
        . htmlspecialchars(strtoupper($state)) . '</span>';

      // Feature list — parsed from PM artifacts or CEO metadata.
      $features_text = trim((string) ($e['features_text'] ?? ''));
      $features_html = '';
      if ($features_text !== '') {
        $lines = array_filter(array_map('trim', explode("\n", $features_text)));
        $lis = '';
        foreach ($lines as $line) {
          [$fid, $desc] = array_pad(explode(':', $line, 2), 2, '');
          $fid = htmlspecialchars(trim($fid));
          $desc = htmlspecialchars(trim($desc));
          $lis .= '<li><code style="background:#f6f8fa;padding:1px 5px;border-radius:3px;">' . $fid . '</code>'
            . ($desc !== '' ? ' — ' . $desc : '') . '</li>';
        }
        $features_html = '<div style="margin:6px 0 4px 0;">'
          . '<strong>Features in this release:</strong>'
          . '<ul style="margin:4px 0 0 16px;padding:0;">' . $lis . '</ul></div>';
      }

      // Optional detail sections.
      $details = [];
      $fields = [
        'plan' => 'Release plan',
        'change_list' => 'Change list',
        'test_evidence' => 'Test evidence',
        'risk_security' => 'Risk + security',
        'rollback' => 'Rollback',
        'human_approval' => 'Human approval',
        'release_notes' => 'Full release notes',
      ];
      foreach ($fields as $k => $title) {
        $txt = trim((string) ($e[$k] ?? ''));
        if ($txt === '') {
          continue;
        }
        $details[] = [
          '#type' => 'details',
          '#title' => $this->t('@t', ['@t' => $title]),
          '#open' => FALSE,
          '#markup' => '<pre style="white-space:pre-wrap;max-height:260px;overflow:auto;font-size:0.85em;">'
            . htmlspecialchars($txt) . '</pre>',
        ];
      }

      // Links.
      $rid_link = $rid;
      $links_markup = '';
      if (preg_match('/^\d{8}-needs-/', $rid)) {
        $rid_link = Link::fromTextAndUrl($rid, Url::fromRoute('copilot_agent_tracker.waiting_on_keith_message', ['item_id' => $rid]))->toString();
      }
      elseif (preg_match('/^\d{8}-[A-Za-z0-9._-]+$/', $rid)) {
        $rid_link = Link::fromTextAndUrl($rid, $this->safeReleaseNotesDetailUrl($rid))->toString();
        $release_status_link = Link::fromTextAndUrl('Release status', $this->safeReleaseNotesDetailUrl($rid))->toString();
        $testing_results_link = Link::fromTextAndUrl('Testing results', $this->safeReleaseTestingResultsUrl($rid))->toString();
        $links_markup = '<p style="margin:4px 0;">' . $release_status_link . ' &nbsp;|&nbsp; ' . $testing_results_link . '</p>';
      }

      $summary_html = $links_markup . $features_html;

      $items[] = [
        '#type' => 'details',
        '#title' => Markup::create($rid_link . $state_badge),
        '#open' => FALSE,
        'summary' => $summary_html !== '' ? ['#markup' => $summary_html] : [],
        'body' => $details ?: ['#markup' => '<em>No detailed release notes published yet.</em>'],
      ];
    }

    $total = count($items);
    return [
      '#type' => 'container',
      'help' => [
        '#markup' => '<p>Release history sourced from HQ PM artifacts and CEO metadata. '
          . '<strong>' . $total . ' release(s)</strong> found. '
          . 'Pending release candidates also appear in the '
          . '<a href="/admin/reports/copilot-agent-tracker#todo-for-keith">approval queue</a>. '
          . '<a href="' . Url::fromRoute('forseti_content.roadmap')->toString() . '">Project roadmaps</a>.</p>',
      ],
      'items' => $items ?: [
        '#markup' => '<em>No release candidates or shipped releases found.</em>',
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Release notes detail page for one release id.
   */
  public function releaseNotesRelease(string $release_id): array {
    $release_id = trim($release_id);
    if ($release_id === '' || !preg_match('/^\d{8}-[A-Za-z0-9._-]+$/', $release_id)) {
      throw new NotFoundHttpException();
    }

    $entries = $this->loadReleaseNotesEntries();
    $entry = NULL;
    foreach ($entries as $e) {
      if (!is_array($e)) {
        continue;
      }
      if ((string) ($e['release_id'] ?? '') === $release_id) {
        $entry = $e;
        break;
      }
    }

    if (!is_array($entry)) {
      throw new NotFoundHttpException();
    }

    $state = trim((string) ($entry['state'] ?? '')) ?: 'unknown';
    $testing_results_url = $this->safeReleaseTestingResultsUrl($release_id)->toString();
    $details = [];
    $fields = [
      'plan' => 'Release plan',
      'change_list' => 'Change list',
      'test_evidence' => 'Test evidence',
      'risk_security' => 'Risk + security',
      'rollback' => 'Rollback',
      'human_approval' => 'Human approval',
      'release_notes' => 'Release notes',
    ];
    foreach ($fields as $k => $title) {
      $txt = trim((string) ($entry[$k] ?? ''));
      if ($txt === '') {
        continue;
      }
      $details[] = [
        '#type' => 'details',
        '#title' => $this->t('@t', ['@t' => $title]),
        '#open' => TRUE,
        '#markup' => '<pre style="white-space:pre-wrap;max-height:360px;overflow:auto;">' . htmlspecialchars($txt) . '</pre>',
      ];
    }

    return [
      '#type' => 'container',
      'summary' => [
        '#markup' => '<p><strong>Release id:</strong> ' . htmlspecialchars($release_id) . '</p>'
          . '<p><strong>State:</strong> ' . htmlspecialchars($state) . '</p>'
          . '<p><a href="' . htmlspecialchars($testing_results_url) . '">Testing results (URL validation + functional tests)</a></p>'
          . '<p><a href="' . Url::fromRoute('copilot_agent_tracker.release_notes')->toString() . '">All release notes</a> | <a href="' . Url::fromRoute('forseti_content.roadmap')->toString() . '">Project roadmaps</a></p>',
      ],
      'details' => $details ?: [
        '#markup' => '<em>No details published for this release yet.</em>',
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Release testing results page for one release id.
   */
  public function releaseTestingResults(string $release_id): array {
    $release_id = trim($release_id);
    if ($release_id === '' || !preg_match('/^\d{8}-[A-Za-z0-9._-]+$/', $release_id)) {
      throw new NotFoundHttpException();
    }

    $entries = $this->loadReleaseNotesEntries();
    $entry = NULL;
    foreach ($entries as $e) {
      if (!is_array($e)) {
        continue;
      }
      if ((string) ($e['release_id'] ?? '') === $release_id) {
        $entry = $e;
        break;
      }
    }
    if (!is_array($entry)) {
      throw new NotFoundHttpException();
    }

    $state = trim((string) ($entry['state'] ?? '')) ?: 'unknown';
    $test_evidence = trim((string) ($entry['test_evidence'] ?? ''));
    $qa_rows = $this->loadReleaseQaAuditRows($release_id);

    $total_url_checks = 0;
    $total_url_failed = 0;
    $total_route_checks = 0;
    $total_route_failed = 0;
    $total_permission_violations = 0;
    $functional_total = 0;

    $url_rows = [];
    $functional_rows = [];
    $script_rows = [];

    foreach ($qa_rows as $qa) {
      $qa_last = (isset($qa['qa_last']) && is_array($qa['qa_last'])) ? $qa['qa_last'] : [];
      $qa_counts = (isset($qa['qa_counts']) && is_array($qa['qa_counts'])) ? $qa['qa_counts'] : [];

      $url_total = (int) ($qa_last['url_checks_total'] ?? 0);
      $url_failed = (int) ($qa_last['url_checks_failed'] ?? 0);
      $route_total = (int) ($qa_last['route_checks_total'] ?? 0);
      $route_failed = (int) ($qa_last['route_checks_failed'] ?? 0);
      $permission_violations = (int) ($qa_last['permission_violation_count'] ?? 0);

      $total_url_checks += max(0, $url_total);
      $total_url_failed += max(0, $url_failed);
      $total_route_checks += max(0, $route_total);
      $total_route_failed += max(0, $route_failed);
      $total_permission_violations += max(0, $permission_violations);

      $functional_count = (int) ($qa_counts['functional'] ?? ($qa_last['functional_tests_total'] ?? 0));
      $functional_total += max(0, $functional_count);

      $url_status = $this->deriveQaUrlStatus($qa_last);
      $functional_status = $this->deriveQaFunctionalStatus($qa_counts, $qa_last);
      $last_run = trim((string) ($qa_last['run_id'] ?? ''));
      $last_time = (int) ($qa['audit_time'] ?? 0);
      $last_run_display = $last_run !== ''
        ? $last_run
        : ($last_time > 0 ? $this->dateFormatter->format($last_time, 'short') : '-');

      $agent_link = Link::fromTextAndUrl(
        (string) ($qa['agent_id'] ?? '-'),
        Url::fromRoute('copilot_agent_tracker.agent', ['agent_id' => (string) ($qa['agent_id'] ?? '')])
      )->toString();

      $url_rows[] = [
        Markup::create($agent_link),
        htmlspecialchars((string) ($qa['product'] ?? '-')),
        htmlspecialchars((string) ($qa_last['base_url'] ?? '-')),
        (string) max(0, $url_total) . ' / failed ' . (string) max(0, $url_failed),
        (string) max(0, $route_total) . ' / failed ' . (string) max(0, $route_failed),
        (string) max(0, $permission_violations),
        Markup::create('<strong>' . htmlspecialchars($url_status) . '</strong>'),
        htmlspecialchars($last_run_display),
      ];

      $functional_rows[] = [
        Markup::create($agent_link),
        (string) ((int) ($qa_counts['unit'] ?? 0)),
        (string) max(0, $functional_count),
        (string) ((int) ($qa_counts['integration'] ?? 0)),
        (string) ((int) ($qa_counts['total'] ?? max(0, $functional_count))),
        Markup::create('<strong>' . htmlspecialchars($functional_status) . '</strong>'),
        htmlspecialchars($last_run_display),
      ];

      $scripts = $this->extractQaScriptLabels($qa_last);
      if (!$scripts) {
        $scripts = ['qa_last_audit'];
      }
      foreach ($scripts as $script_name) {
        $script_rows[] = [
          Markup::create($agent_link),
          htmlspecialchars($script_name),
          htmlspecialchars($last_run !== '' ? $last_run : '-'),
          htmlspecialchars((string) ($qa_last['status'] ?? '-')),
          $last_time > 0 ? $this->dateFormatter->format($last_time, 'short') : '-',
        ];
      }
    }

    $url_overall = ($total_url_failed + $total_route_failed + $total_permission_violations) > 0 ? 'FAIL' : (($total_url_checks + $total_route_checks) > 0 ? 'PASS' : 'NOT RUN');
    $functional_overall = $functional_total > 0 ? 'RUN' : 'NOT RUN';
    $scripts_overall = $script_rows ? 'RECORDED' : 'NONE';

    return [
      '#type' => 'container',
      'summary' => [
        '#markup' => '<p><strong>Release id:</strong> ' . htmlspecialchars($release_id) . '</p>'
          . '<p><strong>State:</strong> ' . htmlspecialchars($state) . '</p>'
          . '<p><a href="' . $this->safeReleaseNotesDetailUrl($release_id)->toString() . '">Release status details</a> | <a href="' . Url::fromRoute('copilot_agent_tracker.release_notes')->toString() . '">All release notes</a> | <a href="' . Url::fromRoute('forseti_content.roadmap')->toString() . '">Project roadmaps</a></p>',
      ],
      'scoreboard' => [
        '#type' => 'table',
        '#header' => ['Testing area', 'Result', 'Details'],
        '#rows' => [
          ['URL validation', Markup::create('<strong>' . htmlspecialchars($url_overall) . '</strong>'), 'URL checks: ' . (string) $total_url_checks . ' (failed ' . (string) $total_url_failed . '), route checks: ' . (string) $total_route_checks . ' (failed ' . (string) $total_route_failed . '), permission violations: ' . (string) $total_permission_violations],
          ['Functional tests', Markup::create('<strong>' . htmlspecialchars($functional_overall) . '</strong>'), 'Functional test count published by QA: ' . (string) $functional_total],
          ['QA scripts', Markup::create('<strong>' . htmlspecialchars($scripts_overall) . '</strong>'), 'Script/suite entries recorded: ' . (string) count($script_rows)],
        ],
      ],
      'url_validation' => [
        '#type' => 'details',
        '#title' => $this->t('URL and route validation by QA seat'),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => ['QA agent', 'Product', 'Base URL', 'URL checks', 'Route checks', 'Permission violations', 'Status', 'Last run'],
          '#rows' => $url_rows,
          '#empty' => $this->t('No QA URL validation data published for this release yet.'),
        ],
      ],
      'functional' => [
        '#type' => 'details',
        '#title' => $this->t('Functional test execution by QA seat'),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => ['QA agent', 'Unit', 'Functional', 'Integration', 'Total', 'Status', 'Last run'],
          '#rows' => $functional_rows,
          '#empty' => $this->t('No functional test execution metadata published for this release yet.'),
        ],
      ],
      'scripts' => [
        '#type' => 'details',
        '#title' => $this->t('QA scripts / suites performed'),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => ['QA agent', 'Script / suite', 'Run id', 'Status', 'Updated'],
          '#rows' => $script_rows,
          '#empty' => $this->t('No QA script metadata published for this release yet.'),
        ],
      ],
      'published_evidence' => [
        '#type' => 'details',
        '#title' => $this->t('Published test evidence excerpt'),
        '#open' => FALSE,
        '#markup' => $test_evidence !== ''
          ? '<pre style="white-space:pre-wrap;max-height:320px;overflow:auto;">' . htmlspecialchars($test_evidence) . '</pre>'
          : '<em>No `test_evidence` text published for this release yet.</em>',
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Loads release note entries from CEO metadata.
   */
  private function loadReleaseNotesEntries(): array {
    // --- 1. Load CEO DB metadata (existing source) ---
    $ceo_entries = [];
    $row = $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', ['metadata', 'last_seen'])
      ->condition('agent_id', 'ceo-copilot%', 'LIKE')
      ->orderBy('last_seen', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    if (!empty($row['metadata'])) {
      try {
        $meta = Json::decode((string) $row['metadata']) ?? [];
        $raw = $meta['release_notes'] ?? [];
        if (is_array($raw)) {
          foreach ($raw as $e) {
            if (is_array($e) && !empty($e['release_id'])) {
              $ceo_entries[$e['release_id']] = $e;
            }
          }
        }
      }
      catch (\Throwable) {}
    }

    // --- 2. Scan filesystem for PM release artifacts ---
    $hq_root = rtrim((string) (getenv('COPILOT_HQ_ROOT') ?: '/home/ubuntu/forseti.life'), '/');
    $fs_entries = [];

    // 2a. Detailed change-list files (more complete; process first so release-notes don't override)
    $cl_files = glob($hq_root . '/sessions/pm-*/artifacts/releases/*/01-change-list.md') ?: [];
    foreach ($cl_files as $path) {
      $content = @file_get_contents($path);
      if ($content === FALSE) {
        continue;
      }
      $release_id = '';
      if (preg_match('/(?:Release Change List|Release):\s*([0-9]{8}-[A-Za-z0-9._-]+)/i', $content, $m)) {
        $release_id = trim($m[1]);
      }
      if ($release_id === '') {
        $release_id = basename(dirname($path));
        if (!preg_match('/^\d{8}-/', $release_id)) {
          continue;
        }
      }
      $fs_entries[$release_id] = [
        'release_id' => $release_id,
        'release_notes' => $content,
        'change_list' => $content,
        'features_text' => $this->parseReleaseFeatures($content),
        'state' => 'scope-activated',
        'mtime' => filemtime($path) ?: 0,
        'plan' => '', 'test_evidence' => '',
        'risk_security' => '', 'rollback' => '', 'human_approval' => '',
      ];
    }

    // 2b. Release-notes files (compact summary format)
    $rn_files = glob($hq_root . '/sessions/pm-*/artifacts/release-notes/*.md') ?: [];
    foreach ($rn_files as $path) {
      $fname = basename($path, '.md');
      $content = @file_get_contents($path);
      if ($content === FALSE) {
        continue;
      }
      $release_id = '';
      if (preg_match('/(?:Release (?:id|ID|Notes):\s*|[Rr]elease[_-][Ii][Dd]:\s*)`?([0-9]{8}-[A-Za-z0-9._-]+)`?/i', $content, $m)) {
        $release_id = trim($m[1]);
      }
      if ($release_id === '' && preg_match('/^(\d{8}-[A-Za-z0-9._-]+)$/', $fname)) {
        $release_id = $fname;
      }
      if ($release_id === '' || isset($fs_entries[$release_id])) {
        // Skip if already have a more detailed change-list entry.
        continue;
      }
      $fs_entries[$release_id] = [
        'release_id' => $release_id,
        'release_notes' => $content,
        'features_text' => $this->parseReleaseFeatures($content),
        'state' => 'scope-activated',
        'mtime' => filemtime($path) ?: 0,
        'plan' => '', 'change_list' => '', 'test_evidence' => '',
        'risk_security' => '', 'rollback' => '', 'human_approval' => '',
      ];
    }

    // 2c. Determine state from signoff + outbox files.
    $signoff_files = glob($hq_root . '/sessions/pm-*/artifacts/release-signoffs/*.md') ?: [];
    $signed_releases = [];
    foreach ($signoff_files as $f) {
      $signed_releases[basename($f, '.md')] = TRUE;
    }
    $outbox_files = glob($hq_root . '/sessions/pm-forseti/outbox/*post-push-*.md') ?: [];
    $shipped_releases = [];
    foreach ($outbox_files as $f) {
      if (preg_match('/post-push-([0-9]{8}-[A-Za-z0-9._-]+)/', basename($f), $m)) {
        $shipped_releases[$m[1]] = TRUE;
      }
    }
    foreach ($fs_entries as $rid => &$entry) {
      if (isset($shipped_releases[$rid])) {
        $entry['state'] = 'shipped';
      }
      elseif (isset($signed_releases[$rid])) {
        $entry['state'] = 'signed-off';
      }
    }
    unset($entry);

    // --- 3. Merge: CEO metadata overrides fs_entries for same release_id ---
    $merged = $ceo_entries;
    foreach ($fs_entries as $rid => $fs_entry) {
      if (!isset($merged[$rid])) {
        $merged[$rid] = $fs_entry;
      }
      else {
        // Enrich CEO entry with features_text parsed from PM artifacts.
        if (empty($merged[$rid]['features_text']) && !empty($fs_entry['features_text'])) {
          $merged[$rid]['features_text'] = $fs_entry['features_text'];
        }
        // Upgrade state if CEO has no useful state.
        if (in_array($merged[$rid]['state'] ?? '', ['', 'unknown'], TRUE)) {
          $merged[$rid]['state'] = $fs_entry['state'];
        }
      }
    }

    // --- 4. Sort by release_id descending (newest first) ---
    $result = array_values($merged);
    usort($result, static function ($a, $b): int {
      return strcmp((string) ($b['release_id'] ?? ''), (string) ($a['release_id'] ?? ''));
    });
    return $result;
  }

  /**
   * Parses a PM release-notes/change-list markdown and returns a plain-text
   * feature list (one feature per line: "feature-id: description").
   */
  private function parseReleaseFeatures(string $content): string {
    $features = [];

    // Find the features section (## Features in scope / ## Features shipped / ## Features)
    if (preg_match('/##\s+Features(?:\s+in\s+scope|\s+shipped)?[^\n]*\n([\s\S]*?)(?:\n##|\z)/i', $content, $m)) {
      $section = $m[1];

      // Numbered/bulleted inline format: "1. `feature-id` — Description"
      preg_match_all(
        '/^[\s]*(?:\d+\.|[-*])\s+(?:\*\*)?`?([a-z0-9_-]+)`?(?:\*\*)?\s*(?:\(ROI[^)]*\))?\s*(?:—|--|-)?\s*(.*)$/m',
        $section,
        $matches
      );
      for ($i = 0; $i < count($matches[1]); $i++) {
        $fid = trim($matches[1][$i]);
        $desc = trim(strip_tags($matches[2][$i]));
        if ($fid !== '' && !preg_match('/^\d+$/', $fid)) {
          $features[] = $desc !== '' ? "$fid: $desc" : $fid;
        }
      }

      // Structured format: "### feature-id" headings
      if (empty($features)) {
        preg_match_all('/^###\s+([a-z0-9_-]+)\s*$/m', $section, $hm);
        foreach ($hm[1] as $fid) {
          $features[] = $fid;
        }
      }
    }

    return implode("\n", $features);
  }

  /**
   * Returns release-detail URL with safe fallback when route cache is stale.
   */
  private function safeReleaseNotesDetailUrl(string $release_id): Url {
    try {
      return Url::fromRoute('copilot_agent_tracker.release_notes_release', ['release_id' => $release_id]);
    }
    catch (RouteNotFoundException) {
      return Url::fromRoute('copilot_agent_tracker.release_notes');
    }
  }

  /**
   * Returns testing-results URL with safe fallback when route cache is stale.
   */
  private function safeReleaseTestingResultsUrl(string $release_id): Url {
    try {
      return Url::fromRoute('copilot_agent_tracker.release_notes_release_testingresults', ['release_id' => $release_id]);
    }
    catch (RouteNotFoundException) {
      return $this->safeReleaseNotesDetailUrl($release_id);
    }
  }

  /**
   * Loads QA agent rows likely associated with the given release id.
   */
  private function loadReleaseQaAuditRows(string $release_id): array {
    $rows = $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', ['agent_id', 'role', 'website', 'module', 'status', 'current_action', 'last_seen', 'metadata'])
      ->orderBy('last_seen', 'DESC')
      ->execute()
      ->fetchAllAssoc('agent_id');

    $product_token = $this->extractReleaseProductToken($release_id);
    $matched = [];
    $fallback = [];

    foreach ($rows as $agent_id => $row) {
      $agent_id = (string) $agent_id;
      $role = trim((string) ($row->role ?? ''));
      if (!($role === 'tester' || str_starts_with($agent_id, 'qa-'))) {
        continue;
      }

      $meta = [];
      if (!empty($row->metadata)) {
        try {
          $meta = Json::decode((string) $row->metadata) ?? [];
        }
        catch (\Throwable) {
          $meta = [];
        }
      }
      $qa_last = (!empty($meta['qa_last_audit']) && is_array($meta['qa_last_audit'])) ? $meta['qa_last_audit'] : [];
      $qa_counts = (!empty($meta['qa_test_counts']) && is_array($meta['qa_test_counts'])) ? $meta['qa_test_counts'] : [];
      $audit_time = $this->extractQaAuditTime($qa_last);

      $candidate = [
        'agent_id' => $agent_id,
        'website' => trim((string) ($row->website ?? '')),
        'module' => trim((string) ($row->module ?? '')),
        'product' => (trim((string) ($row->website ?? '')) ?: '-') . ' / ' . (trim((string) ($row->module ?? '')) ?: '-'),
        'current_action' => trim((string) ($row->current_action ?? '')),
        'last_seen' => (int) ($row->last_seen ?? 0),
        'qa_last' => $qa_last,
        'qa_counts' => $qa_counts,
        'audit_time' => $audit_time,
      ];

      if ($qa_last || $qa_counts) {
        $fallback[$agent_id] = $candidate;
      }

      if ($this->qaAgentMatchesRelease($candidate, $release_id, $product_token)) {
        $matched[$agent_id] = $candidate;
      }
    }

    if ($matched) {
      return array_values($matched);
    }
    return array_values($fallback);
  }

  /**
   * Extracts the product token from a release id.
   */
  private function extractReleaseProductToken(string $release_id): string {
    if (preg_match('/^\d{8}-([a-z0-9._-]+)-release-[a-z0-9._-]+$/i', $release_id, $m)) {
      return strtolower(trim((string) ($m[1] ?? '')));
    }
    return '';
  }

  /**
   * Best-effort match of a QA seat to a release id.
   */
  private function qaAgentMatchesRelease(array $candidate, string $release_id, string $product_token): bool {
    $qa_last = (!empty($candidate['qa_last']) && is_array($candidate['qa_last'])) ? $candidate['qa_last'] : [];
    $haystacks = [
      strtolower((string) ($candidate['agent_id'] ?? '')),
      strtolower((string) ($candidate['website'] ?? '')),
      strtolower((string) ($candidate['module'] ?? '')),
      strtolower((string) ($candidate['current_action'] ?? '')),
      strtolower((string) ($qa_last['base_url'] ?? '')),
      strtolower((string) ($qa_last['release_id'] ?? '')),
      strtolower((string) ($qa_last['run_id'] ?? '')),
    ];

    $release_id_lc = strtolower($release_id);
    foreach ($haystacks as $hay) {
      if ($hay !== '' && str_contains($hay, $release_id_lc)) {
        return TRUE;
      }
    }

    if ($product_token !== '') {
      foreach ($haystacks as $hay) {
        if ($hay !== '' && str_contains($hay, $product_token)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Derives URL validation status from qa_last_audit metadata.
   */
  private function deriveQaUrlStatus(array $qa_last): string {
    if (!$qa_last) {
      return 'NOT RUN';
    }

    $failed = (int) ($qa_last['url_checks_failed'] ?? 0)
      + (int) ($qa_last['route_checks_failed'] ?? 0)
      + (int) ($qa_last['permission_violation_count'] ?? 0);
    $status = strtolower(trim((string) ($qa_last['status'] ?? '')));
    $ran = ((int) ($qa_last['url_checks_total'] ?? 0) > 0)
      || ((int) ($qa_last['route_checks_total'] ?? 0) > 0)
      || trim((string) ($qa_last['run_id'] ?? '')) !== '';

    if ($failed > 0 || in_array($status, ['issues', 'fail', 'failed'], TRUE)) {
      return 'FAIL';
    }
    if (in_array($status, ['clean', 'pass', 'passed'], TRUE)) {
      return 'PASS';
    }
    return $ran ? 'PASS' : 'NOT RUN';
  }

  /**
   * Derives functional-test status from QA metadata.
   */
  private function deriveQaFunctionalStatus(array $qa_counts, array $qa_last): string {
    $functional = (int) ($qa_counts['functional'] ?? ($qa_last['functional_tests_total'] ?? 0));
    $failed = (int) ($qa_last['functional_tests_failed'] ?? 0);

    if ($functional <= 0 && $failed <= 0) {
      return 'NOT RUN';
    }
    if ($failed > 0) {
      return 'FAIL';
    }
    return 'PASS';
  }

  /**
   * Extracts script/suite labels from qa_last_audit metadata.
   */
  private function extractQaScriptLabels(array $qa_last): array {
    $labels = [];

    foreach (['script', 'script_name', 'suite', 'runner', 'command'] as $key) {
      $val = trim((string) ($qa_last[$key] ?? ''));
      if ($val !== '') {
        $labels[$val] = TRUE;
      }
    }

    foreach (['scripts', 'suites', 'checks'] as $key) {
      $vals = $qa_last[$key] ?? [];
      if (!is_array($vals)) {
        continue;
      }
      foreach ($vals as $val) {
        if (is_string($val) || is_numeric($val)) {
          $label = trim((string) $val);
          if ($label !== '') {
            $labels[$label] = TRUE;
          }
          continue;
        }
        if (is_array($val)) {
          foreach (['name', 'id', 'script', 'suite', 'check'] as $nested_key) {
            $nested = trim((string) ($val[$nested_key] ?? ''));
            if ($nested !== '') {
              $labels[$nested] = TRUE;
              break;
            }
          }
        }
      }
    }

    return array_slice(array_keys($labels), 0, 12);
  }

  /**
   * Extracts an audit timestamp from qa_last_audit metadata.
   */
  private function extractQaAuditTime(array $qa_last): int {
    foreach (['timestamp', 'mtime', 'updated_at', 'completed_at', 'finished_at', 'created'] as $key) {
      $value = $qa_last[$key] ?? NULL;
      if (is_numeric($value)) {
        $epoch = (int) $value;
        if ($epoch > 0) {
          return $epoch;
        }
      }
      if (is_string($value) && trim($value) !== '') {
        $parsed = strtotime($value);
        if (is_int($parsed) && $parsed > 0) {
          return $parsed;
        }
      }
    }
    return 0;
  }

  /**
   * Agent detail page.
   */
  public function agent(string $agent_id): array {
    $agent = $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a')
      ->condition('agent_id', $agent_id)
      ->execute()
      ->fetchAssoc();

    if (!$agent) {
      throw new NotFoundHttpException();
    }

    $events = $this->database->select('copilot_agent_tracker_events', 'e')
      ->fields('e', ['created', 'action', 'status', 'summary', 'session_id', 'work_item_id'])
      ->condition('agent_id', $agent_id)
      ->orderBy('created', 'DESC')
      ->range(0, 50)
      ->execute()
      ->fetchAll();

    $meta = $this->decodeAgentMetadata($agent);
    $inbox_items = $this->extractInboxItems($meta);

    $active_item_id = trim((string) ($meta['active_inbox'] ?? ''));
    $outbox_results = (!empty($meta['outbox_results']) && is_array($meta['outbox_results'])) ? $meta['outbox_results'] : [];
    $outbox_recent = (!empty($outbox_results['recent']) && is_array($outbox_results['recent'])) ? $outbox_results['recent'] : [];
    $outbox_status_by_id = [];
    foreach ($outbox_recent as $r) {
      if (!is_array($r)) {
        continue;
      }
      $rid = trim((string) ($r['item_id'] ?? ''));
      if ($rid === '') {
        continue;
      }
      $rst = trim((string) ($r['status'] ?? ''));
      if ($rst !== '') {
        $outbox_status_by_id[$rid] = $rst;
      }
    }

    $queue_rows = $this->buildQueueRows($agent_id, $inbox_items, $active_item_id, $outbox_status_by_id);
    $event_rows = $this->buildEventRows($events);
    $metrics_items = $this->buildAgentMetricsItems($meta, $inbox_items);
    $collapse_details = ($agent_id === 'dev-forseti');
    $results = $this->buildAgentResultsSections($meta, !$collapse_details);

    $qa_counts = (!empty($meta['qa_test_counts']) && is_array($meta['qa_test_counts'])) ? $meta['qa_test_counts'] : [];
    $qa_last = (!empty($meta['qa_last_audit']) && is_array($meta['qa_last_audit'])) ? $meta['qa_last_audit'] : [];
    $is_qa = (($agent['role'] ?? '') === 'tester') || str_starts_with($agent_id, 'qa-');
    $qa_counts_items = [];
    if ($is_qa) {
      $qa_counts_items[] = 'Unit tests: ' . (string) ((int) ($qa_counts['unit'] ?? 0));
      $qa_counts_items[] = 'Functional tests: ' . (string) ((int) ($qa_counts['functional'] ?? 0));
      $qa_counts_items[] = 'Integration tests: ' . (string) ((int) ($qa_counts['integration'] ?? 0));
      $qa_counts_items[] = 'Total: ' . (string) ((int) ($qa_counts['total'] ?? 0));
    }

    $qa_last_items = [];
    if ($is_qa && $qa_last) {
      $qa_last_items[] = 'Status: ' . (string) ($qa_last['status'] ?? '-');
      if (!empty($qa_last['run_id'])) {
        $qa_last_items[] = 'Last run: ' . (string) $qa_last['run_id'];
      }
      if (!empty($qa_last['base_url'])) {
        $qa_last_items[] = 'Base URL: ' . (string) $qa_last['base_url'];
      }
      $qa_last_items[] = 'URL checks: ' . (string) ((int) ($qa_last['url_checks_total'] ?? 0))
        . ' (failed ' . (string) ((int) ($qa_last['url_checks_failed'] ?? 0)) . ')';
      $qa_last_items[] = 'Route checks: ' . (string) ((int) ($qa_last['route_checks_total'] ?? 0))
        . ' (failed ' . (string) ((int) ($qa_last['route_checks_failed'] ?? 0)) . ')';
      $qa_last_items[] = 'Permission violations: ' . (string) ((int) ($qa_last['permission_violation_count'] ?? 0));
      $roles = (!empty($qa_last['roles_covered']) && is_array($qa_last['roles_covered'])) ? $qa_last['roles_covered'] : [];
      if ($roles) {
        $qa_last_items[] = 'Roles covered: ' . implode(', ', array_slice(array_map('strval', $roles), 0, 8));
      }
    }

    $active_summary_items = [];
    if ($active_item_id !== '' && !empty($inbox_items[$active_item_id]) && is_array($inbox_items[$active_item_id])) {
      $it = $inbox_items[$active_item_id];
      $active_summary_items[] = Markup::create('<strong>' . htmlspecialchars($active_item_id) . '</strong>');
      $roi = (int) ($it['roi'] ?? 0);
      $eff = (int) ($it['effective_roi'] ?? 0);
      $mtime = (int) ($it['mtime'] ?? 0);
      $preview = trim((string) ($it['preview'] ?? ''));
      if ($roi > 0) {
        $active_summary_items[] = 'ROI: ' . (string) $roi;
      }
      if ($eff > 0) {
        $active_summary_items[] = 'Effective ROI: ' . (string) $eff;
      }
      if ($mtime > 0) {
        $active_summary_items[] = 'Updated: ' . $this->dateFormatter->format($mtime, 'short');
      }
      if ($preview !== '') {
        $active_summary_items[] = 'Preview: ' . $preview;
      }

      $active_link = Link::fromTextAndUrl($this->t('Open active item'), Url::fromRoute('copilot_agent_tracker.agent_inbox_item', ['agent_id' => $agent_id, 'item_id' => $active_item_id]))->toString();
      $active_summary_items[] = Markup::create($active_link);
    }

    return [
      '#type' => 'container',
      'summary' => [
        '#markup' => '<h2>' . $this->t('Agent: @id', ['@id' => $agent_id]) . '</h2>',
      ],
      'active_item' => $active_summary_items ? [
        '#type' => 'details',
        '#title' => $this->t('Active work item'),
        '#open' => TRUE,
        'items' => [
          '#type' => 'container',
          'list' => $this->renderSimpleList($active_summary_items),
        ],
      ] : [],
      'qa_roster' => ($is_qa && $qa_counts_items) ? [
        '#type' => 'container',
        'title' => [
          '#markup' => '<p><strong>QA test roster</strong></p>',
        ],
        'items' => [
          '#type' => 'container',
          'list' => $this->renderSimpleList($qa_counts_items),
        ],
      ] : [],
      'qa_last_run' => ($is_qa && $qa_last_items) ? [
        '#type' => 'container',
        'title' => [
          '#markup' => '<p><strong>QA last run (scripted)</strong></p>',
        ],
        'items' => [
          '#type' => 'container',
          'list' => $this->renderSimpleList($qa_last_items),
        ],
      ] : [],
      'metrics' => [
        '#type' => 'details',
        '#title' => $this->t('Metrics'),
        '#open' => !$collapse_details,
        'items' => [
          '#type' => 'container',
          'list' => $this->renderSimpleList($metrics_items),
        ],
      ],
      'meta' => [
        '#type' => 'container',
        'list' => $this->renderSimpleList([
          'Role: ' . ($agent['role'] ?? ''),
          'Website: ' . ($agent['website'] ?? ''),
          'Module: ' . ($agent['module'] ?? ''),
          'Status: ' . ($agent['status'] ?? ''),
          'Current action: ' . ($agent['current_action'] ?? ''),
        ]),
      ],
      'results' => $results,
      'queue' => [
        '#type' => 'details',
        '#title' => $this->t('Inbox queue'),
        '#open' => !$collapse_details,
        'table' => [
          '#type' => 'table',
          '#header' => ['Item', 'ROI', 'Effective ROI', 'Updated', 'Preview'],
          '#rows' => $queue_rows,
          '#empty' => $this->t('No inbox items published for this agent.'),
        ],
      ],
      'events' => [
        '#type' => 'table',
        '#header' => ['When', 'Action', 'Status', 'Summary', 'Session', 'Work item'],
        '#rows' => $event_rows,
        '#empty' => $this->t('No events yet.'),
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  private function decodeAgentMetadata(array $agent): array {
    if (empty($agent['metadata'])) {
      return [];
    }
    try {
      $decoded = Json::decode((string) $agent['metadata']);
      return is_array($decoded) ? $decoded : [];
    }
    catch (\Throwable) {
      return [];
    }
  }

  private function extractInboxItems(array $meta): array {
    $inbox_items = [];

    if (!empty($meta['inbox_items_detail']) && is_array($meta['inbox_items_detail'])) {
      foreach ($meta['inbox_items_detail'] as $it) {
        if (!is_array($it)) {
          continue;
        }
        $iid = trim((string) ($it['item_id'] ?? ''));
        if ($iid === '') {
          continue;
        }
        $inbox_items[$iid] = $it;
      }
      return $inbox_items;
    }

    if (!empty($meta['inbox_items']) && is_array($meta['inbox_items'])) {
      foreach ($meta['inbox_items'] as $iid) {
        $iid = trim((string) $iid);
        if ($iid !== '') {
          $inbox_items[$iid] = ['item_id' => $iid];
        }
      }
    }

    return $inbox_items;
  }

  private function buildQueueRows(string $agent_id, array $inbox_items, string $active_item_id = '', array $outbox_status_by_id = []): array {
    $queue_rows = [];
    foreach ($inbox_items as $iid => $it) {
      $roi = (int) ($it['roi'] ?? 0);
      $eff = (int) ($it['effective_roi'] ?? 0);
      $mtime = (int) ($it['mtime'] ?? 0);
      $preview = (string) ($it['preview'] ?? '');

      $link_html = Link::fromTextAndUrl($iid, Url::fromRoute('copilot_agent_tracker.agent_inbox_item', ['agent_id' => $agent_id, 'item_id' => $iid]))->toString();
      $is_active = ($active_item_id !== '' && $iid === $active_item_id);
      if ($is_active) {
        $link_html = '<strong>' . $link_html . '</strong>';
      }

      $preview_bits = [];
      if ($is_active) {
        $preview_bits[] = 'ACTIVE';
      }
      $known_status = trim((string) ($outbox_status_by_id[$iid] ?? ''));
      if ($known_status !== '') {
        $preview_bits[] = 'Status: ' . $known_status;
      }
      $preview = trim((string) $preview);
      if ($preview !== '') {
        $preview_bits[] = $preview;
      }

      $queue_rows[] = [
        Markup::create($link_html),
        $roi > 0 ? (string) $roi : '-',
        $eff > 0 ? (string) $eff : '-',
        $mtime ? $this->dateFormatter->format($mtime, 'short') : '-',
        $preview_bits ? htmlspecialchars(implode(' — ', $preview_bits)) : '',
      ];
    }
    return $queue_rows;
  }

  private function buildEventRows(array $events): array {
    $event_rows = [];
    foreach ($events as $e) {
      $event_rows[] = [
        $e->created ? $this->dateFormatter->format((int) $e->created, 'short') : '',
        $e->action ?? '',
        $e->status ?? '',
        $e->summary ?? '',
        $e->session_id ?? '',
        $e->work_item_id ?? '',
      ];
    }
    return $event_rows;
  }

  private function buildAgentMetricsItems(array $meta, array $inbox_items): array {
    $metrics_items = [];
    $metrics_items[] = 'Inbox items: ' . (string) ((int) ($meta['inbox_count'] ?? count($inbox_items)));
    $metrics_items[] = 'Next inbox ROI: ' . (string) ((int) ($meta['next_inbox_effective_roi'] ?? ($meta['next_inbox_roi'] ?? 0)));

    $outbox_results = (!empty($meta['outbox_results']) && is_array($meta['outbox_results'])) ? $meta['outbox_results'] : [];
    $counts_7d = (!empty($outbox_results['counts_7d']) && is_array($outbox_results['counts_7d'])) ? $outbox_results['counts_7d'] : [];

    $count_done_7d = (int) ($counts_7d['done'] ?? 0);
    $count_in_progress_7d = (int) ($counts_7d['in_progress'] ?? 0);
    $count_needs_info_7d = (int) ($counts_7d['needs-info'] ?? 0);
    $count_blocked_7d = (int) ($counts_7d['blocked'] ?? 0);
    $count_total_7d = (int) ($counts_7d['total'] ?? 0);
    $count_forwarded_7d = $count_needs_info_7d + $count_blocked_7d;

    $metrics_items[] = 'Results (7d) — completed: ' . (string) $count_done_7d
      . ', forwarded (needs-info+blocked): ' . (string) $count_forwarded_7d
      . ', in_progress: ' . (string) $count_in_progress_7d
      . ', total: ' . (string) $count_total_7d;

    $last_outbox_mtime = (int) ($outbox_results['last_mtime'] ?? 0);
    if ($last_outbox_mtime > 0) {
      $metrics_items[] = 'Last outbox update: ' . $this->dateFormatter->format($last_outbox_mtime, 'short');
    }

    $role_kpis = (!empty($meta['role_kpis']) && is_array($meta['role_kpis'])) ? $meta['role_kpis'] : [];

    $kpi_value = trim((string) ($role_kpis['value'] ?? ''));
    if ($kpi_value !== '') {
      $metrics_items[] = 'Value I add: ' . $kpi_value;
    }

    $kpi_cost = (!empty($role_kpis['cost']) && is_array($role_kpis['cost'])) ? $role_kpis['cost'] : [];
    $kpi_quality = (!empty($role_kpis['quality']) && is_array($role_kpis['quality'])) ? $role_kpis['quality'] : [];
    $kpi_speed = (!empty($role_kpis['speed']) && is_array($role_kpis['speed'])) ? $role_kpis['speed'] : [];

    if ($kpi_cost) {
      $metrics_items[] = Markup::create('<strong>Cost KPIs</strong>:<br/>' . htmlspecialchars(implode(' | ', array_slice(array_map('strval', $kpi_cost), 0, 6))));
    }
    if ($kpi_quality) {
      $metrics_items[] = Markup::create('<strong>Quality KPIs</strong>:<br/>' . htmlspecialchars(implode(' | ', array_slice(array_map('strval', $kpi_quality), 0, 6))));
    }
    if ($kpi_speed) {
      $metrics_items[] = Markup::create('<strong>Speed KPIs</strong>:<br/>' . htmlspecialchars(implode(' | ', array_slice(array_map('strval', $kpi_speed), 0, 6))));
    }

    return $metrics_items;
  }

  private function buildAgentResultsSections(array $meta, bool $open = TRUE): array {
    $outbox_results = (!empty($meta['outbox_results']) && is_array($meta['outbox_results'])) ? $meta['outbox_results'] : [];

    $results_completed = [];
    $results_forwarded = [];
    $results_in_progress = [];
    $results_other = [];

    if (!empty($outbox_results['recent']) && is_array($outbox_results['recent'])) {
      foreach ($outbox_results['recent'] as $r) {
        if (!is_array($r)) {
          continue;
        }
        $rid = trim((string) ($r['item_id'] ?? ''));
        if ($rid === '') {
          continue;
        }
        $rstatus = trim((string) ($r['status'] ?? ''));
        $rsummary = trim((string) ($r['summary'] ?? ''));
        $rroi = (int) ($r['roi'] ?? 0);
        $rmtime = (int) ($r['mtime'] ?? 0);
        $rexcerpt = (string) ($r['excerpt'] ?? '');

        $title_bits = [];
        $title_bits[] = htmlspecialchars($rid);
        if ($rstatus !== '') {
          $title_bits[] = htmlspecialchars($rstatus);
        }
        if ($rroi > 0) {
          $title_bits[] = 'ROI ' . $rroi;
        }
        $title = implode(' — ', $title_bits);

        $meta_bits = [];
        if ($rmtime > 0) {
          $meta_bits[] = '<strong>' . $this->t('Updated') . ':</strong> ' . $this->dateFormatter->format($rmtime, 'short');
        }
        if ($rsummary !== '') {
          $meta_bits[] = '<strong>' . $this->t('Summary') . ':</strong> ' . htmlspecialchars($rsummary);
        }

        $body = $rexcerpt !== ''
          ? '<pre style="white-space: pre-wrap;">' . htmlspecialchars($rexcerpt) . '</pre>'
          : '<em>No excerpt published.</em>';

        $normalized = strtolower(str_replace(' ', '_', $rstatus));
        if ($normalized === 'needsinfo') {
          $normalized = 'needs-info';
        }

        $item_render = [
          '#type' => 'details',
          '#title' => Markup::create($title),
          '#open' => FALSE,
          'meta' => [
            '#markup' => $meta_bits ? '<p>' . implode('<br/>', $meta_bits) . '</p>' : '',
          ],
          'body' => [
            '#markup' => $body,
          ],
        ];

        if ($normalized === 'done') {
          $results_completed[] = $item_render;
        }
        elseif ($normalized === 'needs-info' || $normalized === 'blocked') {
          $results_forwarded[] = $item_render;
        }
        elseif ($normalized === 'in_progress') {
          $results_in_progress[] = $item_render;
        }
        else {
          $results_other[] = $item_render;
        }
      }
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Results'),
      '#open' => $open,
      'help' => [
        '#markup' => '<p><strong>Completed</strong> = Status done. <strong>Forwarded</strong> = Status needs-info/blocked (requires a decision or missing input). This is derived from HQ outbox updates.</p>',
      ],
      'completed' => [
        '#type' => 'details',
        '#title' => $this->t('Completed (recent)'),
        '#open' => $open,
        'items' => $results_completed ?: ['#markup' => '<em>No completed results published yet.</em>'],
      ],
      'forwarded' => [
        '#type' => 'details',
        '#title' => $this->t('Forwarded / needs decision (recent)'),
        '#open' => $open,
        'items' => $results_forwarded ?: ['#markup' => '<em>No forwarded/escalated results published yet.</em>'],
      ],
      'in_progress' => [
        '#type' => 'details',
        '#title' => $this->t('In progress (recent)'),
        '#open' => FALSE,
        'items' => $results_in_progress ?: ['#markup' => '<em>No in-progress results published yet.</em>'],
      ],
      'other' => [
        '#type' => 'details',
        '#title' => $this->t('Other (recent)'),
        '#open' => FALSE,
        'items' => $results_other ?: ['#markup' => '<em>No other results published yet.</em>'],
      ],
    ];
  }

  /**
   * Agent inbox item detail view (from HQ-published metadata).
   */
  public function agentInboxItem(string $agent_id, string $item_id): array {
    $agent = $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', ['agent_id', 'role', 'website', 'module', 'status', 'current_action', 'last_seen', 'metadata'])
      ->condition('agent_id', $agent_id)
      ->execute()
      ->fetchAssoc();

    if (!$agent) {
      throw new NotFoundHttpException();
    }

    $meta = [];
    if (!empty($agent['metadata'])) {
      try {
        $decoded = Json::decode((string) $agent['metadata']);
        $meta = is_array($decoded) ? $decoded : [];
      }
      catch (\Throwable) {
        $meta = [];
      }
    }

    $detail = NULL;
    if (!empty($meta['inbox_items_detail']) && is_array($meta['inbox_items_detail'])) {
      foreach ($meta['inbox_items_detail'] as $it) {
        if (is_array($it) && (string) ($it['item_id'] ?? '') === $item_id) {
          $detail = $it;
          break;
        }
      }
    }

    $agent_status = trim((string) ($agent['status'] ?? ''));
    $agent_action = trim((string) ($agent['current_action'] ?? ''));
    $agent_last_seen = (int) ($agent['last_seen'] ?? 0);
    $agent_active_inbox = trim((string) ($meta['active_inbox'] ?? ''));

    $active_on_this = FALSE;
    if (strtolower($agent_status) === 'in_progress') {
      if ($agent_active_inbox !== '' && $agent_active_inbox === $item_id) {
        $active_on_this = TRUE;
      }
      elseif ($agent_action !== '' && str_contains($agent_action, $item_id)) {
        $active_on_this = TRUE;
      }
    }

    $activity_items = [];
    $activity_items[] = 'Agent status: ' . ($agent_status !== '' ? $agent_status : '-');
    $activity_items[] = 'Last seen: ' . ($agent_last_seen ? $this->dateFormatter->format($agent_last_seen, 'short') : '-');
    $activity_items[] = 'Current action: ' . ($agent_action !== '' ? $agent_action : '-');
    if ($active_on_this) {
      $activity_items[] = Markup::create('<strong>Actively executing this item.</strong>');
    }
    elseif (strtolower($agent_status) === 'in_progress' && $agent_active_inbox !== '' && $agent_active_inbox !== $item_id) {
      $other_link = Link::fromTextAndUrl($agent_active_inbox, Url::fromRoute('copilot_agent_tracker.agent_inbox_item', ['agent_id' => $agent_id, 'item_id' => $agent_active_inbox]))->toString();
      $activity_items[] = Markup::create('Active item: ' . $other_link);
    }

    if (!$detail) {
      return [
        '#type' => 'container',
        'header' => [
          '#markup' => '<h2>' . $this->t('Inbox item: @item', ['@item' => $item_id]) . '</h2>'
            . '<p><strong>' . $this->t('Agent') . ':</strong> ' . $this->t('@a', ['@a' => $agent_id]) . '</p>',
        ],
        'activity' => [
          '#type' => 'container',
          'list' => $this->renderSimpleList($activity_items),
        ],
        'missing' => [
          '#markup' => '<p><em>No detail published for this item yet.</em> This usually means HQ has not published the newer inbox detail payload. Re-run the HQ publish job and refresh.</p>',
        ],
        '#cache' => [
          'max-age' => 0,
        ],
      ];
    }

    $roi = (int) ($detail['roi'] ?? 0);
    $eff = (int) ($detail['effective_roi'] ?? 0);
    $mtime = (int) ($detail['mtime'] ?? 0);
    $files = $detail['files'] ?? [];
    $files = is_array($files) ? $files : [];
    $body_source = (string) ($detail['body_source'] ?? '');
    $body = (string) ($detail['body'] ?? '');

    $file_markup = '<em>None</em>';
    if ($files) {
      $safe = array_map(static fn($v) => htmlspecialchars((string) $v), $files);
      $file_markup = '<ul><li>' . implode('</li><li>', $safe) . '</li></ul>';
    }

    return [
      '#type' => 'container',
      'header' => [
        '#markup' => '<h2>' . $this->t('Inbox item: @item', ['@item' => $item_id]) . '</h2>'
          . '<p><strong>' . $this->t('Agent') . ':</strong> ' . $this->t('@a', ['@a' => $agent_id]) . '</p>'
          . '<p><strong>' . $this->t('Product') . ':</strong> ' . $this->t('@p', ['@p' => (($agent['website'] ?? '') ?: '-') . ' / ' . (($agent['module'] ?? '') ?: '-')]) . '</p>'
          . '<p><strong>' . $this->t('Role') . ':</strong> ' . $this->t('@r', ['@r' => ($agent['role'] ?? '') ?: '-']) . '</p>',
      ],
      'activity' => [
        '#type' => 'details',
        '#title' => $this->t('Agent activity (latest published)'),
        '#open' => TRUE,
        'items' => [
          '#type' => 'container',
          'list' => $this->renderSimpleList($activity_items),
        ],
      ],
      'meta' => [
        '#type' => 'container',
        'list' => $this->renderSimpleList([
          'ROI: ' . ($roi > 0 ? (string) $roi : '-'),
          'Effective ROI: ' . ($eff > 0 ? (string) $eff : '-'),
          'Updated: ' . ($mtime ? $this->dateFormatter->format($mtime, 'short') : '-'),
          'Source file: ' . ($body_source !== '' ? $body_source : '-'),
        ]),
      ],
      'files' => [
        '#type' => 'details',
        '#title' => $this->t('Files'),
        '#open' => FALSE,
        '#markup' => $file_markup,
      ],
      'body' => [
        '#type' => 'details',
        '#title' => $this->t('Content'),
        '#open' => TRUE,
        'content' => [
          '#type' => 'textarea',
          '#title' => $this->t('Body'),
          '#value' => $body,
          '#rows' => 22,
          '#attributes' => ['readonly' => 'readonly'],
        ],
      ],
      'back' => [
        '#markup' => '<p>' . Link::fromTextAndUrl($this->t('Back to agent'), Url::fromRoute('copilot_agent_tracker.agent', ['agent_id' => $agent_id]))->toString() . '</p>',
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  // ---------------------------------------------------------------------------
  // LangGraph dashboard helpers.
  // ---------------------------------------------------------------------------

  /**
   * Read a flat file safely; log a warning and return NULL on failure.
   */
  private function readFileSafe(string $path): ?string {
    $content = @file_get_contents($path);
    if ($content === FALSE) {
      \Drupal::logger('copilot_agent_tracker')->warning('LangGraph dashboard: could not read file @path', ['@path' => $path]);
      return NULL;
    }
    return $content;
  }

  /**
   * Parse JSONL content; return array of decoded objects (skip bad lines).
   *
   * @return array<int,array<string,mixed>>
   */
  private function parseJsonl(string $content): array {
    $rows = [];
    foreach (explode("\n", trim($content)) as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      $decoded = json_decode($line, TRUE);
      if (is_array($decoded)) {
        $rows[] = $decoded;
      }
    }
    return $rows;
  }

  /**
   * LangGraph Dashboard home view.
   *
   * Route: /admin/reports/copilot-agent-tracker/langgraph
   */
  public function langGraphDashboard(): array {
    $build = $this->buildLanggraphPageShell('LangGraph Control Plane');

    // ── Data ─────────────────────────────────────────────────────────────────
    // Load tick data first so health signals are available for all sections.
    $latest_tick = $this->readLastJsonlObject($this->langgraphPath(self::LANGGRAPH_TICKS_RELATIVE));
    $parity_data = $this->readJsonFile($this->langgraphPath(self::LANGGRAPH_PARITY_RELATIVE));
    $release_control = $this->readReleaseCycleControlState();

    $tick_present = !empty($latest_tick);
    $tick_ts = (string) ($latest_tick['ts'] ?? 'unknown');
    $tick_age = 'unknown';
    if ($tick_present) {
      $tick_time = strtotime($tick_ts);
      if ($tick_time !== FALSE) {
        $tick_age = max(0, time() - $tick_time) . 's';
      }
    }

    $dry_run = !empty($latest_tick['dry_run']);
    $publish_enabled = !empty($latest_tick['publish_enabled']);
    $parity_ok = isset($parity_data['parity_ok']) ? (bool) $parity_data['parity_ok'] : NULL;
    $release_enabled = (bool) ($release_control['enabled'] ?? TRUE);

    $ok_overall = $tick_present
      && $parity_ok === TRUE
      && $publish_enabled
      && $release_enabled;

    // ── ACTION ZONE (weight -100 to -40) ─────────────────────────────────────
    $build['health_banner'] = [
      '#markup' => $ok_overall
        ? '<div class="messages messages--status"><strong>' . $this->t('LangGraph Health: OK') . '</strong> — ' . $this->t('Ticks, parity, publishing, and release-cycle automation are all healthy.') . '</div>'
        : '<div class="messages messages--warning"><strong>' . $this->t('LangGraph Health: ATTENTION') . '</strong> — ' . $this->t('One or more critical signals are degraded. Use the quick actions below to investigate.') . '</div>',
      '#weight' => -100,
    ];
    $build['expected_action'] = $this->renderLanggraphExpectedAction(
      $ok_overall,
      'Expected operator action (HEALTHY)',
      'Continue normal operations and monitor Session/Release pages on routine cadence.',
      'Expected operator action (ATTENTION)',
      'Pause release approvals, investigate parity/session/release signals, and restore green status before proceeding.'
    );
    $build['expected_action']['#weight'] = -90;

    $build['status_snapshot'] = [
      '#type' => 'table',
      '#header' => [$this->t('Signal'), $this->t('Current state'), $this->t('Details')],
      '#rows' => [
        [
          $this->t('Latest tick'),
          $tick_present ? $this->t('OK') : $this->t('MISSING'),
          $tick_present ? ('ts=' . $tick_ts . '; age=' . $tick_age) : $this->t('No tick record found in LangGraph telemetry.'),
        ],
        [
          $this->t('Engine mode'),
          $dry_run ? $this->t('DRY RUN') : $this->t('LIVE'),
          $dry_run ? $this->t('dry_run=true in latest tick') : $this->t('dry_run=false in latest tick'),
        ],
        [
          $this->t('Parity'),
          $parity_ok === NULL ? $this->t('UNKNOWN') : ($parity_ok ? $this->t('PASS') : $this->t('FAIL')),
          $parity_ok === NULL ? $this->t('No readable parity report yet.') : $this->t('From langgraph-parity-latest.json'),
        ],
        [
          $this->t('Publishing'),
          $publish_enabled ? $this->t('ENABLED') : $this->t('DISABLED'),
          $publish_enabled ? $this->t('publish_enabled=true in latest tick') : $this->t('publish_enabled=false in latest tick'),
        ],
        [
          $this->t('Release control automation'),
          $release_enabled ? $this->t('ENABLED') : $this->t('DISABLED'),
          $this->t('updated_at=@t by @u', [
            '@t' => (string) ($release_control['updated_at'] ?? 'unknown'),
            '@u' => (string) ($release_control['updated_by'] ?? 'unknown'),
          ]),
        ],
      ],
      '#weight' => -80,
    ];

    $build['quick_actions'] = $this->renderLanggraphNavList(
      (string) $this->t('Quick actions'),
      [
        $this->safeRouteLinkOrCurrent('Control Plane: review parity health now', 'copilot_agent_tracker.langgraph_parity'),
        $this->safeRouteLinkOrCurrent('Execution Plane: review release control now', 'copilot_agent_tracker.langgraph_release_status'),
        $this->safeRouteLinkOrCurrent('Control Plane: review session health now', 'copilot_agent_tracker.langgraph_session'),
        $this->safeRouteLinkOrCurrent('View full process flow for all workflows', 'copilot_agent_tracker.langgraph_process_flow'),
        $this->safeRouteLinkOrCurrent('Open main Copilot dashboard', 'copilot_agent_tracker.dashboard'),
      ]
    );
    $build['quick_actions']['#weight'] = -70;

    $build['ops_control'] = [
      '#type' => 'details',
      '#title' => $this->t('Org Automation Control'),
      '#open' => TRUE,
      '#weight' => -60,
      'form' => $this->formBuilder()->getForm(OrgAutomationToggleForm::class, []),
    ];

    $build['release_cycle_control'] = [
      '#type' => 'details',
      '#title' => $this->t('Release Management Cycle Control'),
      '#open' => TRUE,
      '#weight' => -50,
      'help' => [
        '#markup' => '<p>Start or stop release-cycle automation (release_cycle + coordinated_push) while keeping other automation controls intact.</p>',
      ],
      'form' => $this->formBuilder()->getForm(ReleaseManagementCycleForm::class),
    ];

    $build['waiting'] = [
      '#type' => 'details',
      '#title' => $this->t('Waiting on Keith'),
      '#open' => FALSE,
      '#weight' => -40,
      'content' => $this->buildWaitingOnKeithView(),
    ];

    // ── REFERENCE ZONE (weight 10+) ──────────────────────────────────────────
    $build['workflow_registry'] = $this->renderWorkflowRegistry();
    $build['workflow_registry']['#weight'] = 10;

    $build['flow_hub'] = $this->renderLanggraphHomeFlowHub();
    $build['flow_hub']['#weight'] = 20;

    $build['page_responsibilities'] = [
      '#type' => 'table',
      '#caption' => $this->t('LangGraph architecture plane map'),
      '#weight' => 30,
      '#header' => [
        $this->t('Plane'),
        $this->t('Page'),
        $this->t('Owns'),
        $this->t('Use when'),
      ],
      '#rows' => [
        [
          $this->t('Control'),
          $this->safeRouteLinkOrCurrent('Overview', 'copilot_agent_tracker.langgraph_dashboard'),
          $this->t('Global control state and cross-signal health snapshot.'),
          $this->t('You need a go/no-go snapshot before drilling down.'),
        ],
        [
          $this->t('Control'),
          $this->safeRouteLinkOrCurrent('Session Health', 'copilot_agent_tracker.langgraph_session'),
          $this->t('Tick cadence, runtime stability, and execution error rate.'),
          $this->t('Orchestration appears unhealthy or stale.'),
        ],
        [
          $this->t('Control'),
          $this->safeRouteLinkOrCurrent('Parity Health', 'copilot_agent_tracker.langgraph_parity'),
          $this->t('Engine parity assertions and mismatch diagnosis.'),
          $this->t('You need confidence that engine behavior matches expected flow.'),
        ],
        [
          $this->t('Execution'),
          $this->safeRouteLinkOrCurrent('Feature Flow', 'copilot_agent_tracker.langgraph_feature_progress'),
          $this->t('Work-item ownership/progress with telemetry context.'),
          $this->t('You are prioritizing or assigning feature execution.'),
        ],
        [
          $this->t('Execution'),
          $this->safeRouteLinkOrCurrent('Release Control', 'copilot_agent_tracker.langgraph_release_status'),
          $this->t('Release readiness signals and publish-control posture.'),
          $this->t('You are deciding whether release execution may continue.'),
        ],
        [
          $this->t('Assurance'),
          $this->safeRouteLinkOrCurrent('Release Evidence', 'copilot_agent_tracker.langgraph_release_notes'),
          $this->t('Release evidence narrative and signoff context.'),
          $this->t('You need approval-grade release evidence.'),
        ],
        [
          $this->t('Assurance'),
          $this->safeRouteLinkOrCurrent('Release Triage', 'copilot_agent_tracker.langgraph_release_troubleshooting'),
          $this->t('Seat-level blockers, needs-info, and queue bottlenecks.'),
          $this->t('Release flow is stalled or ownership is unclear.'),
        ],
      ],
    ];

    // Reference/documentation sections — collapsed, weight 40+.
    $build['terms'] = $this->renderLangGraphKeyTerms([
      'Tick'                     => 'One complete orchestrator loop iteration — the engine runs on a schedule (every few minutes) and appends one JSON record per loop to langgraph-ticks.jsonl.',
      'Parity'                   => 'A validation check that the running graph matches its expected configuration: the same pipeline steps execute in the same order, and the same agents are selected.',
      'dry_run'                  => 'When true, the orchestrator runs all logic but makes no external writes (no GitHub pushes, no Drupal DB writes). Safe mode for testing engine changes.',
      'publish_enabled'          => 'Controls the publish pipeline step — when true, agent tracker telemetry is written to the Drupal database so this UI reflects live data.',
      'Release cycle automation' => 'The release_cycle + coordinated_push pipeline steps. When disabled, teams stop advancing through release phases but other automation continues.',
      'Control Plane'            => 'Pages that own health signals and operational controls (this page, Session Health, Parity Health).',
      'Execution Plane'          => 'Pages that own work progression and release readiness (Feature Flow, Release Control).',
      'Assurance Plane'          => 'Pages that own evidence and triage (Release Evidence, Release Triage).',
    ]);
    $build['terms']['#weight'] = 40;

    $build['guide'] = $this->renderLanggraphPageGuide(
      'Provide a single operational entry point for LangGraph orchestration health.',
      'Start here first, then jump to Session, Parity, or Release pages based on which signal is degraded.',
      [
        'Top-level health signals: latest tick freshness, parity status, publish mode, and release-cycle control state.',
        'A quick action launchpad for deeper diagnostics pages.',
        'Operational control forms (org automation and release-cycle control).',
      ]
    );
    $build['guide']['#weight'] = 50;

    $build['context_banner'] = $this->renderLangGraphContextBanner(
      'Top-level health of the LangGraph orchestration system — tick freshness, engine mode, parity status, publishing state, and release cycle control. The single go/no-go view before drilling into any subpage.',
      'Check here first whenever you suspect orchestrator problems, before approving a release, or after making any configuration change to the engine or agent setup.',
      'langgraph-ticks.jsonl (latest tick) + langgraph-parity-latest.json + release-cycle-control.json — all written by the orchestrator in copilot-hq/inbox/responses/.'
    );
    $build['context_banner']['#weight'] = 60;

    $build['troubleshooting'] = $this->renderLanggraphTroubleshootingSection('LangGraph Troubleshooting Interfaces');
    $build['troubleshooting']['#weight'] = 70;

    return $this->sanitizeRenderArray($build);
  }

  /**
   * LangGraph Process Flow — full pipeline visualization with live step status.
   *
   * Route: /admin/reports/copilot-agent-tracker/langgraph/process-flow
   */
  public function langGraphProcessFlow(): array {
    $build = $this->buildLanggraphPageShell('LangGraph Process Flow');

    $build['context_banner'] = $this->renderLangGraphContextBanner(
      'The full execution pipeline for each registered LangGraph workflow — step-by-step node definitions, execution order, and live status from the most recent orchestrator tick.',
      'Use when debugging a stuck or skipped pipeline step, verifying a new node was registered in the correct position, or reviewing end-to-end execution health of a workflow.',
      'orchestrator/runtime_graph/engine.py (pipeline definition) + langgraph-ticks.jsonl latest tick (live step_results per node).'
    );

    $latest_tick  = $this->readLastJsonlObject($this->langgraphPath(self::LANGGRAPH_TICKS_RELATIVE));
    $step_results = (array) ($latest_tick['step_results'] ?? []);
    $tick_ts      = (string) ($latest_tick['ts'] ?? '');
    $cio_flow     = $this->loadJobHunterCioFlowSnapshot();

    // ── Orchestrator pipeline definition ────────────────────────────────────
    // Order mirrors engine.py graph.add_edge() calls.
    $pipeline_steps = [
      'consume_replies' => [
        'label'       => 'Consume Replies',
        'description' => 'Reads agent outbox reply files from sessions/*/outbox/ and ingests them into the HQ inbox. Moves completed agent work downstream for further processing.',
        'outputs'     => 'rc (exit code)',
      ],
      'dispatch_commands' => [
        'label'       => 'Dispatch Commands',
        'description' => 'Processes pending command files and dispatches directives to the appropriate agents. Handles inter-agent communication and board-level commands.',
        'outputs'     => 'dispatched (list of commands sent)',
      ],
      'release_cycle' => [
        'label'       => 'Release Cycle',
        'description' => 'Advances each product team through release phases (R0→R1→R2→R3→R4→R5→shipped). Triggers phase transitions when signoff and readiness conditions are met.',
        'outputs'     => 'teams (per-team action, current release, next release)',
      ],
      'coordinated_push' => [
        'label'       => 'Coordinated Push',
        'description' => 'Executes a GitHub push only when ALL teams simultaneously signal ready at the push gate. Reports "waiting" and the blocking teams when not all are ready.',
        'outputs'     => 'status (pushed|waiting|skipped), not_ready (blocking teams)',
      ],
      'pick_agents' => [
        'label'       => 'Pick Agents',
        'description' => 'Scores all agents by inbox ROI and selects up to agent_cap agents to run this tick. Prioritises release-critical agents over normal workqueue items.',
        'outputs'     => 'selected (agents to run), release_priority (agents on release path)',
      ],
      'exec_agents' => [
        'label'       => 'Execute Agents',
        'description' => 'Runs the selected agents in parallel via the configured shell provider (Copilot CLI). Records name and exit code for each agent execution.',
        'outputs'     => 'ran (list of {agent, rc} entries)',
      ],
      'health_check' => [
        'label'       => 'Health Check',
        'description' => 'Scans all agents for stuck or idle-with-unread-inbox states and attempts automatic remediation. Reports blocked agent count and any remediation actions taken.',
        'outputs'     => 'idle_with_inbox, blocked_count, remediated',
      ],
      'kpi_monitor' => [
        'label'       => 'KPI Monitor',
        'description' => 'Runs the release KPI monitoring script to detect stagnant releases, stale inboxes, and development throughput bottlenecks across all sites.',
        'outputs'     => 'rc, out (KPI report text)',
      ],
      'publish' => [
        'label'       => 'Publish',
        'description' => 'When publish_enabled=true, writes the full tick telemetry snapshot to the Drupal database so all dashboard pages reflect live orchestrator state.',
        'outputs'     => 'rc (exit code)',
      ],
    ];

    // ── Live tick status banner ──────────────────────────────────────────────
    $tick_note = $tick_ts !== ''
      ? $this->t('Showing live data from tick at @ts', ['@ts' => $tick_ts])
      : $this->t('No tick data available — pipeline status column will be empty.');
    $build['tick_note'] = ['#markup' => '<p><em>' . $tick_note . '</em></p>'];

    // ── Release Cycle Orchestrator pipeline table ────────────────────────────
    $build['orchestrator_heading'] = [
      '#markup' => '<h3>' . $this->t('Release Cycle Orchestrator — Pipeline Steps') . '</h3>'
        . '<p>' . $this->t('consume_replies → dispatch_commands → release_cycle → coordinated_push → pick_agents → exec_agents → health_check → kpi_monitor → publish') . '</p>',
    ];

    $step_rows   = [];
    $step_idx    = 1;
    $total_steps = count($pipeline_steps);
    foreach ($pipeline_steps as $step_key => $step_info) {
      $step_data = (array) ($step_results[$step_key] ?? []);

      // RC badge.
      if (isset($step_data['rc'])) {
        $rc    = (int) $step_data['rc'];
        $color = $rc === 0 ? '#2e7d32' : '#b71c1c';
        $badge = '<strong style="color:' . $color . '">' . ($rc === 0 ? '✓ rc=0' : '✗ rc=' . $rc) . '</strong>';
      }
      elseif (!empty($step_data)) {
        $badge = '<span style="color:#2e7d32">✓ ran</span>';
      }
      else {
        $badge = '<span style="color:#888">—</span>';
      }

      // Key output summary (compact, one line).
      $output_parts = [];
      foreach ($step_data as $k => $v) {
        if ($k === 'rc') {
          continue;
        }
        if (is_array($v)) {
          $output_parts[] = $k . '=' . count($v) . ' item(s)';
        }
        else {
          $str = (string) $v;
          if (strlen($str) > 80) {
            $str = substr($str, 0, 77) . '…';
          }
          $output_parts[] = $k . '=' . htmlspecialchars($str);
        }
      }
      $output_summary = $output_parts ? implode('; ', $output_parts) : '—';

      // Position cell with down-arrow between steps.
      $position_cell = $step_idx < $total_steps
        ? '<strong>' . $step_idx . '</strong> <span style="color:#666">↓</span>'
        : '<strong>' . $step_idx . '</strong>';

      $step_rows[] = [
        ['data' => ['#markup' => $position_cell]],
        ['data' => ['#markup' => '<strong>' . htmlspecialchars((string) $step_info['label']) . '</strong>']],
        (string) $step_info['description'],
        (string) $step_info['outputs'],
        ['data' => ['#markup' => $badge]],
        ['data' => ['#markup' => '<code style="font-size:.85em">' . $output_summary . '</code>']],
      ];
      $step_idx++;
    }

    $build['pipeline_table'] = [
      '#type'   => 'table',
      '#header' => [
        $this->t('#'),
        $this->t('Step'),
        $this->t('What it does'),
        $this->t('Expected outputs'),
        $this->t('Last tick status'),
        $this->t('Last tick output'),
      ],
      '#rows'  => $step_rows,
      '#empty' => $this->t('No pipeline steps defined.'),
    ];

    // ── KPI monitor output (collapsible) ────────────────────────────────────
    $kpi_out = (string) ($step_results['kpi_monitor']['out'] ?? '');
    if ($kpi_out !== '') {
      $build['kpi_detail'] = [
        '#type'   => 'details',
        '#title'  => $this->t('KPI Monitor Output (last tick)'),
        '#open'   => FALSE,
        'content' => ['#markup' => '<pre style="white-space:pre-wrap;font-size:.85em">' . htmlspecialchars($kpi_out) . '</pre>'],
      ];
    }

    // ── Site workflow: Job Hunter CIO application flow ──────────────────────
    $build['cio_flow_heading'] = [
      '#markup' => '<h3>' . $this->t('Job-Hunter CIO Application Flow — Live Site Snapshot') . '</h3>'
        . '<p>' . $this->t('Site-level LangGraph monitoring slice for Keith Aumiller. This turns the existing Job Hunter application pipeline into explicit, checkable stages without changing the org-wide orchestrator graph.') . '</p>',
    ];

    if (empty($cio_flow['available'])) {
      $build['cio_flow_unavailable'] = [
        '#markup' => '<div class="messages messages--warning"><strong>' . $this->t('CIO workflow unavailable') . '</strong> — '
          . $this->t('@reason', ['@reason' => (string) ($cio_flow['reason'] ?? 'Job Hunter data could not be loaded.')]) . '</div>',
      ];
    }
    else {
      $tailoring_gap = (int) $cio_flow['applications'] > 0 && (int) $cio_flow['tailored_resumes'] === 0;
      $summary_class = $tailoring_gap ? 'warning' : 'status';
      $summary_label = $tailoring_gap ? 'CIO Flow: ATTENTION' : 'CIO Flow: OK';
      $summary_text = $tailoring_gap
        ? $this->t('Discovery, import, saved-job, and application-prep stages are live, but tailored CIO resumes have not been generated yet.')
        : $this->t('The monitored CIO workflow has active opportunities and no immediate stage gap is detected.');
      $build['cio_flow_summary'] = [
        '#markup' => '<div class="messages messages--' . $summary_class . '"><strong>' . $this->t($summary_label) . '</strong> — ' . $summary_text . '</div>',
      ];

      $build['cio_flow_snapshot'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Metric'),
          $this->t('Value'),
        ],
        '#rows' => [
          [$this->t('Target profile'), $this->t('Keith Aumiller (uid @uid)', ['@uid' => (string) $cio_flow['uid']])],
          [$this->t('Discovered CIO matches'), (string) $cio_flow['discovered_total']],
          [$this->t('Still staged'), (string) $cio_flow['staged_pending']],
          [$this->t('Imported from staging'), (string) $cio_flow['staged_imported']],
          [$this->t('Canonical CIO jobs'), (string) $cio_flow['canonical_jobs']],
          [$this->t('Saved CIO jobs'), (string) $cio_flow['saved_jobs']],
          [$this->t('Application records'), (string) $cio_flow['applications']],
          [$this->t('Apply URLs resolved'), (string) $cio_flow['apply_ready']],
          [$this->t('Manual review flags'), (string) $cio_flow['manual_review']],
          [$this->t('Tailored resumes'), (string) $cio_flow['tailored_resumes']],
          [$this->t('Tailored PDFs ready'), (string) $cio_flow['tailored_pdf_ready']],
          [$this->t('Confirmed submissions'), (string) $cio_flow['submitted']],
        ],
      ];

      $cio_rows = [];
      foreach ((array) $cio_flow['steps'] as $step_index => $step) {
        $cio_rows[] = [
          (string) ($step_index + 1),
          (string) ($step['label'] ?? ''),
          (string) ($step['intent'] ?? ''),
          (string) ($step['signal'] ?? ''),
          ['data' => ['#markup' => (string) ($step['badge'] ?? '')]],
        ];
      }
      $build['cio_flow_steps'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('#'),
          $this->t('Stage'),
          $this->t('Automation intent'),
          $this->t('Live signal'),
          $this->t('Status'),
        ],
        '#rows' => $cio_rows,
        '#empty' => $this->t('No CIO workflow steps available.'),
      ];

      if (!empty($cio_flow['warnings'])) {
        $warning_items = array_map(static fn(string $warning): string => Html::escape($warning), (array) $cio_flow['warnings']);
        $build['cio_flow_warnings'] = [
          '#markup' => '<h4>' . $this->t('Flow warnings') . '</h4><ul><li>' . implode('</li><li>', $warning_items) . '</li></ul>',
        ];
      }

      $recent_job_rows = [];
      foreach ((array) $cio_flow['recent_jobs'] as $job) {
        $apply_target = trim((string) ($job['apply_url'] ?? ''));
        if ($apply_target === '') {
          $apply_target = '—';
        }
        elseif (strlen($apply_target) > 90) {
          $apply_target = substr($apply_target, 0, 87) . '...';
        }

        $recent_job_rows[] = [
          '#' . (string) ($job['id'] ?? 0) . ' · ' . (string) ($job['job_title'] ?? ''),
          (string) (($job['source_label'] ?? '') !== '' ? $job['source_label'] : '—'),
          !empty($job['saved_job_id']) ? $this->t('Saved') : $this->t('Not saved'),
          (string) (($job['tailoring_status'] ?? '') !== '' ? $job['tailoring_status'] : 'not_started'),
          !empty($job['pdf_generated']) ? $this->t('PDF ready') : $this->t('PDF pending'),
          (string) (($job['submission_status'] ?? '') !== '' ? $job['submission_status'] : 'not_started'),
          !empty($job['admin_review_required']) ? $this->t('Yes') : $this->t('No'),
          $apply_target,
        ];
      }
      $build['cio_flow_recent_jobs'] = [
        '#markup' => '<h4>' . $this->t('Recent canonical CIO jobs') . '</h4>',
      ];
      $build['cio_flow_recent_jobs_table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Job'),
          $this->t('Source'),
          $this->t('Saved'),
          $this->t('Tailoring'),
          $this->t('PDF'),
          $this->t('Application'),
          $this->t('Manual review'),
          $this->t('Apply target'),
        ],
        '#rows' => $recent_job_rows,
        '#empty' => $this->t('No canonical CIO jobs found.'),
      ];
    }

    // ── Registered workflows summary ─────────────────────────────────────────
    $build['other_flows_heading'] = [
      '#markup' => '<h3>' . $this->t('Registered Workflows') . '</h3>',
    ];
    $build['other_flows_table'] = [
      '#type'   => 'table',
      '#header' => [$this->t('Workflow'), $this->t('Site'), $this->t('Status'), $this->t('Notes')],
      '#rows'   => [
        [
          $this->t('Release Cycle Orchestrator'),
          $this->t('Org-wide'),
          $this->t('🟢 Active — see pipeline above'),
          $this->t('Python LangGraph engine in orchestrator/runtime_graph/engine.py'),
        ],
        [
          $this->t('Job-Hunter Intake Flow'),
          $this->t('forseti.life'),
          $this->t('⬜ Planned'),
          $this->t('LangGraph-driven job-posting intake and triage — scoping pending.'),
        ],
        [
          $this->t('Job-Hunter CIO Application Flow'),
          $this->t('forseti.life'),
          $this->t('🟡 In Progress'),
          $this->t('Live monitoring slice for Keith CIO opportunities: staging, import, queue binding, tailoring, and submission readiness.'),
        ],
        [
          $this->t('PF2E Encounter Flow'),
          $this->t('dungeoncrawler'),
          $this->t('⬜ Planned'),
          $this->t('LangGraph-driven encounter and session management — scoping pending.'),
        ],
      ],
    ];

    $build['back'] = [
      '#markup' => '<p>' . Link::createFromRoute('← Back to LangGraph Overview', 'copilot_agent_tracker.langgraph_dashboard')->toString() . '</p>',
    ];

    return $this->sanitizeRenderArray($build);
  }

  /**
   * LangGraph workflow management hub.
   *
   * Note: no dedicated route currently; this is kept for forward compatibility.
   */
  public function langGraphWorkflowManagement(): array {
    $build = $this->buildLanggraphPageShell(
      'LangGraph Workflow Management Hub',
      'workflow-specific operational pages are grouped under this hub to keep system-level management separate.'
    );
    $build['guide'] = $this->renderLanggraphPageGuide(
      'Provide a dedicated entry point for workflow-level execution management.',
      'Use this page after system health (Overview/Session/Parity) is green, then move into feature/release workflow pages.',
      [
        'Clear separation between platform management and workflow execution.',
        'Direct links to Feature Flow and Release Control pages.',
        'Expected sequence: system health first, workflow decisions second.',
      ]
    );
    $build['expected_action'] = $this->renderLanggraphExpectedActionFixed(
      'status',
      'Expected operator action',
      'Use this hub to enter workflow-specific pages; keep system-level diagnosis in Overview/Session/Parity.'
    );
    $build['workflow_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Workflow area'),
        $this->t('Use for'),
        $this->t('Open page'),
      ],
      '#rows' => [
        [
          $this->t('Feature workflow management'),
          $this->t('Prioritization, ownership, and work-item tracking with telemetry context.'),
          $this->safeRouteLink('Feature Flow', 'copilot_agent_tracker.langgraph_feature_progress'),
        ],
        [
          $this->t('Release control status'),
          $this->t('Release readiness and publish-control posture.'),
          $this->safeRouteLink('Release Control', 'copilot_agent_tracker.langgraph_release_status'),
        ],
        [
          $this->t('Release evidence'),
          $this->t('Release notes and signoff evidence review before approvals.'),
          $this->safeRouteLink('Release Evidence', 'copilot_agent_tracker.langgraph_release_notes'),
        ],
        [
          $this->t('Release triage'),
          $this->t('Seat-level blockers, needs-info routing, and stalled flow diagnosis.'),
          $this->safeRouteLink('Release Triage', 'copilot_agent_tracker.langgraph_release_troubleshooting'),
        ],
      ],
    ];
    return $this->sanitizeRenderArray($build);
  }

  /**
   * LangGraph Session Health view.
   *
   * Route: /admin/reports/copilot-agent-tracker/langgraph/session
   */
  public function langGraphSessionMonitoring(): array {
    $build = $this->buildLanggraphPageShell(
      'LangGraph Session Health',
      'this page mirrors troubleshooting depth used by the main Copilot Agent Tracker dashboard.'
    );
    $build['guide'] = $this->renderLanggraphPageGuide(
      'Track execution cadence and detect session-level instability across recent LangGraph ticks.',
      'Use this page to confirm ticks are frequent, not error-heavy, and running in the expected mode (dry-run vs live).',
      [
        'Recent tick timeline with dry_run/publish/provider metadata.',
        'Per-tick error count derived from top-level and per-step error markers.',
        'Session health banner highlighting whether the latest tick is healthy.',
      ]
    );
    $build['responsibility'] = $this->renderLanggraphResponsibilityCard(
      'Runtime execution health and scheduler cadence.',
      [
        'Tick freshness, error density, and execution stability.',
        'Recent tick timeline and execution metadata.',
      ],
      [
        'Engine parity assertions (Parity Health page).',
        'Release go/no-go decisions (Release Control page).',
      ]
    );

    $build['context_banner'] = $this->renderLangGraphContextBanner(
      'Tick timeline for the last 50 orchestrator iterations — timestamps, execution mode, provider, agent cap, and per-tick error count. The primary signal for "is the orchestrator running and healthy?"',
      'Use when the dashboard shows a stale tick, when error counts spike, or after a config change to confirm the engine resumed normal cadence.',
      'langgraph-ticks.jsonl — append-only log in copilot-hq/inbox/responses/. Each line is one tick JSON object. This page shows the last 50.'
    );
    $build['terms'] = $this->renderLangGraphKeyTerms([
      'Tick'           => 'One complete orchestrator loop. The engine appends a JSON record to langgraph-ticks.jsonl on every iteration.',
      'dry_run'        => 'When yes, no external writes occurred this tick (no GitHub pushes, no DB writes). Engine ran in safe/test mode.',
      'publish_enabled' => 'When yes, the publish step ran and pushed telemetry to Drupal. When no, this UI may be showing stale agent data.',
      'agent_cap'      => 'How many agents were allowed to run in parallel this tick.',
      'provider'       => 'The LLM/execution backend used for agent dispatch (e.g. "anthropic").',
      'error count'    => 'Sum of top-level tick errors plus any pipeline steps that reported an error status. Red rows = errors detected.',
    ]);

    $ticks_path = $this->langgraphPath(self::LANGGRAPH_TICKS_RELATIVE);
    $content = $this->readFileSafe($ticks_path);
    if ($content === NULL) {
      $build['empty'] = ['#markup' => '<p class="messages messages--warning">' . $this->t('Tick data unavailable — @path could not be read.', ['@path' => $ticks_path]) . '</p>'];
      return $this->sanitizeRenderArray($build);
    }

    $all_ticks = $this->parseJsonl($content);
    if (empty($all_ticks)) {
      $build['empty'] = ['#markup' => '<p>' . $this->t('No ticks recorded yet.') . '</p>'];
      return $this->sanitizeRenderArray($build);
    }

    // Keep last 50 ticks.
    $ticks = array_slice($all_ticks, -50);
    $latest = end($ticks);
    $now = time();
    $one_hour_ago = $now - 3600;

    // Summary stats.
    $ticks_last_hour = 0;
    foreach ($all_ticks as $tick) {
      $ts = strtotime((string) ($tick['ts'] ?? ''));
      if ($ts !== FALSE && $ts >= $one_hour_ago) {
        $ticks_last_hour++;
      }
    }

    $latest_error_count = count((array) ($latest['errors'] ?? []));
    foreach ((array) ($latest['step_results'] ?? []) as $step) {
      if (is_array($step) && (($step['status'] ?? '') === 'error' || !empty($step['errors']))) {
        $latest_error_count++;
      }
    }

    $build['session_health'] = [
      '#markup' => $latest_error_count > 0
        ? '<div class="messages messages--warning"><strong>' . $this->t('Session Health: ATTENTION') . '</strong> — ' . $this->t('Latest tick reported @n errors across node execution.', ['@n' => (string) $latest_error_count]) . '</div>'
        : '<div class="messages messages--status"><strong>' . $this->t('Session Health: OK') . '</strong> — ' . $this->t('Latest tick reported no node execution errors.') . '</div>',
    ];
    $build['expected_action'] = $this->renderLanggraphExpectedAction(
      $latest_error_count === 0,
      'Expected operator action (NO ERRORS)',
      'Proceed with normal flow and keep parity/release checks as routine guardrails.',
      'Expected operator action (ERRORS PRESENT)',
      'Inspect failed node details, address runtime blockers, and wait for a clean tick before continuing release actions.'
    );
    $build['next_actions'] = $this->renderLanggraphNavList(
        (string) $this->t('Next actions'),
      [
        $this->safeRouteLinkOrCurrent('Review parity health', 'copilot_agent_tracker.langgraph_parity'),
        $this->safeRouteLinkOrCurrent('Review release control', 'copilot_agent_tracker.langgraph_release_status'),
      ]
    );

    $dry_run_latest = !empty($latest['dry_run']) ? $this->t('YES (dry run)') : $this->t('no (live)');
    $build['summary'] = [
      '#markup' => '<p><strong>' . $this->t('Total ticks shown:') . '</strong> ' . count($ticks)
        . ' &nbsp;|&nbsp; <strong>' . $this->t('Ticks in last hour:') . '</strong> ' . $ticks_last_hour
        . ' &nbsp;|&nbsp; <strong>' . $this->t('Latest dry_run:') . '</strong> ' . $dry_run_latest
        . '</p>',
    ];

    // Build table rows (newest first).
    $header = [
      $this->t('Timestamp'),
      $this->t('dry_run'),
      $this->t('publish_enabled'),
      $this->t('agent_cap'),
      $this->t('provider'),
      $this->t('error count'),
    ];

    $rows = [];
    foreach (array_reverse($ticks) as $tick) {
      $error_count = count((array) ($tick['errors'] ?? []));
      // Also check step_results for any error-status steps.
      foreach ((array) ($tick['step_results'] ?? []) as $step) {
        if (is_array($step) && (($step['status'] ?? '') === 'error' || !empty($step['errors']))) {
          $error_count++;
        }
      }

      $row = [
        'data' => [
          (string) ($tick['ts'] ?? ''),
          !empty($tick['dry_run']) ? $this->t('yes') : $this->t('no'),
          !empty($tick['publish_enabled']) ? $this->t('yes') : $this->t('no'),
          (string) ($tick['agent_cap'] ?? ''),
          (string) ($tick['provider'] ?? ''),
          (string) $error_count,
        ],
      ];

      if ($error_count > 0) {
        $row['class'] = ['color-error'];
      }
      $rows[] = $row;
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No ticks to display.'),
    ];

    $build['troubleshooting'] = $this->renderLanggraphTroubleshootingSection('Session Health Troubleshooting');

    return $this->sanitizeRenderArray($build);
  }

  /**
   * LangGraph Feature Flow view.
   *
   * Route: /admin/reports/copilot-agent-tracker/langgraph/feature-progress
   *
   * Source: LANGGRAPH_FEATURE_PROGRESS_FILE markdown table (pre-generated
   * from features/<feature>/feature.md by the orchestrator dashboard generator).
   */
  public function langGraphFeatureProgress(): array {
    $build = $this->buildLanggraphPageShell(
      'LangGraph Feature Flow',
      'troubleshooting capabilities are aligned with the main Copilot Agent Tracker dashboard.'
    );
    $build['guide'] = $this->renderLanggraphPageGuide(
      'Give product/release planning visibility tied to LangGraph telemetry context.',
      'Use this page to review feature/work-item ownership and status, then confirm telemetry/parity are healthy before making execution decisions.',
      [
        'Feature work table parsed from LANGGRAPH_FEATURE_PROGRESS.md.',
        'Latest tick timestamp and inferred engine mode (live vs dry_run).',
        'Parity status snapshot to validate confidence in telemetry interpretation.',
      ]
    );
    $build['responsibility'] = $this->renderLanggraphResponsibilityCard(
      'Feature execution visibility and planning context.',
      [
        'Feature/work-item status by owner and priority.',
        'Planning context with latest telemetry/parity indicators.',
      ],
      [
        'Engine mismatch diagnosis (Parity Health page).',
        'Seat-level release blockers (Release Triage page).',
      ]
    );

    // Parse markdown table from LANGGRAPH_FEATURE_PROGRESS.md.
    $md = $this->readFileSafe($this->langgraphPath(self::LANGGRAPH_FEATURE_PROGRESS_RELATIVE));
    $feature_rows = [];
    if ($md !== NULL) {
      foreach (explode("\n", $md) as $line) {
        $line = trim($line);
        // Match data rows: | col | col | ... (skip header and separator rows).
        if (str_starts_with($line, '|') && !str_contains($line, '---')) {
          $cols = array_map('trim', explode('|', trim($line, '|')));
          // Expect at least 8 cols: Work item | Website | Module | Status | Priority | PM | Dev | QA.
          if (count($cols) >= 8 && $cols[0] !== 'Work item') {
            $feature_rows[] = array_values(array_slice($cols, 0, 8));
          }
        }
      }
    }
    else {
      $build['md_warning'] = ['#markup' => '<p class="messages messages--warning">' . $this->t('Feature progress file unavailable.') . '</p>'];
    }

    $build['feature_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Work item'),
        $this->t('Website'),
        $this->t('Module'),
        $this->t('Status'),
        $this->t('Priority'),
        $this->t('PM'),
        $this->t('Dev'),
        $this->t('QA'),
      ],
      '#rows' => $feature_rows,
      '#empty' => $this->t('No active features found.'),
    ];

    // LangGraph telemetry summary.
    $build['telemetry_heading'] = [
      '#markup' => '<h3>' . $this->t('LangGraph Telemetry Summary') . '</h3>',
    ];

    $last_tick_ts = $this->t('unknown');
    $engine_mode = $this->t('unknown');

    $ticks_content = $this->readFileSafe($this->langgraphPath(self::LANGGRAPH_TICKS_RELATIVE));
    if ($ticks_content !== NULL) {
      $ticks = $this->parseJsonl($ticks_content);
      if (!empty($ticks)) {
        $latest = end($ticks);
        $last_tick_ts = (string) ($latest['ts'] ?? 'unknown');
        $engine_mode = !empty($latest['dry_run']) ? 'dry_run' : 'live';
      }
    }

    $parity_ok = $this->t('unknown');
    $parity_content = $this->readFileSafe($this->langgraphPath(self::LANGGRAPH_PARITY_RELATIVE));
    if ($parity_content !== NULL) {
      $parity = json_decode($parity_content, TRUE);
      if (is_array($parity)) {
        $parity_ok = isset($parity['parity_ok']) ? ($parity['parity_ok'] ? $this->t('PASS') : $this->t('FAIL')) : $this->t('unknown');
      }
    }

    $is_parity_pass = ((string) $parity_ok) === 'PASS';
    $has_tick = ((string) $last_tick_ts) !== 'unknown';
    $build['feature_health'] = [
      '#markup' => ($is_parity_pass && $has_tick)
        ? '<div class="messages messages--status"><strong>' . $this->t('Feature Flow Health: OK') . '</strong> — ' . $this->t('Telemetry and parity signals are available for planning decisions.') . '</div>'
        : '<div class="messages messages--warning"><strong>' . $this->t('Feature Flow Health: ATTENTION') . '</strong> — ' . $this->t('Telemetry or parity is incomplete; verify session and parity pages.') . '</div>',
    ];
    $build['expected_action'] = $this->renderLanggraphExpectedAction(
      $is_parity_pass && $has_tick,
      'Expected operator action (READY)',
      'Use this view for prioritization and assignment decisions.',
      'Expected operator action (NOT READY)',
      'Treat feature status as advisory only until telemetry/parity recover.'
    );
    $build['next_actions'] = $this->renderLanggraphNavList(
        (string) $this->t('Next actions'),
      [
        $this->safeRouteLinkOrCurrent('Review session health', 'copilot_agent_tracker.langgraph_session'),
        $this->safeRouteLinkOrCurrent('Review parity health', 'copilot_agent_tracker.langgraph_parity'),
      ]
    );

    $build['telemetry_summary'] = [
      '#markup' => '<ul>'
        . '<li><strong>' . $this->t('Last tick:') . '</strong> ' . $last_tick_ts . '</li>'
        . '<li><strong>' . $this->t('Engine mode:') . '</strong> ' . $engine_mode . '</li>'
        . '<li><strong>' . $this->t('Parity status:') . '</strong> ' . $parity_ok . '</li>'
        . '</ul>',
    ];

    $build['troubleshooting'] = $this->renderLanggraphTroubleshootingSection('Feature Flow Troubleshooting');

    return $this->sanitizeRenderArray($build);
  }

  /**
   * LangGraph Engine/Parity Health view.
   *
   * Route: /admin/reports/copilot-agent-tracker/langgraph/parity
   */
  public function langGraphParityHealth(): array {
    $build = $this->buildLanggraphPageShell(
      'LangGraph Parity Health',
      'this parity page is anchored to the same troubleshooting model as the main Copilot Agent Tracker dashboard.'
    );
    $build['guide'] = $this->renderLanggraphPageGuide(
      'Validate that runtime behavior still matches expected LangGraph parity checks.',
      'Use this page during incidents, upgrades, or runtime changes to verify selected-agent and step-order parity before trusting downstream metrics.',
      [
        'parity_ok overall status and generation timestamp.',
        'selected_agents.match and steps.match comparisons.',
        'Parity error details from langgraph-parity-latest.json.',
      ]
    );
    $build['responsibility'] = $this->renderLanggraphResponsibilityCard(
      'Parity validation of orchestration engine behavior.',
      [
        'Parity pass/fail truth and mismatch details.',
        'Selected-agent and step-order expectation checks.',
      ],
      [
        'Tick stability troubleshooting (Session Health page).',
        'Release readiness and control decisions (Release Control page).',
      ]
    );

    $parity_path = $this->langgraphPath(self::LANGGRAPH_PARITY_RELATIVE);
    $content = $this->readFileSafe($parity_path);
    if ($content === NULL) {
      $build['empty'] = ['#markup' => '<p class="messages messages--warning">' . $this->t('Parity data unavailable — @path could not be read.', ['@path' => $parity_path]) . '</p>'];
      return $this->sanitizeRenderArray($build);
    }

    $parity = json_decode($content, TRUE);
    if (!is_array($parity)) {
      $build['empty'] = ['#markup' => '<p class="messages messages--warning">' . $this->t('No parity data available — file is empty or malformed.') . '</p>'];
      return $this->sanitizeRenderArray($build);
    }

    $parity_ok = (bool) ($parity['parity_ok'] ?? FALSE);

    // Warning banner when parity fails.
    if (!$parity_ok) {
      $build['warning'] = [
        '#markup' => '<div class="messages messages--warning"><strong>' . $this->t('PARITY FAILURE') . '</strong> — ' . $this->t('LangGraph engine does not match legacy expectations. Review errors below.') . '</div>',
      ];
      $build['expected_action'] = $this->renderLanggraphExpectedActionFixed(
        'warning',
        'Expected operator action (FAIL)',
        'Pause release decisions, review parity errors, confirm recent session health, then rerun/refresh parity before proceeding.'
      );
    }
    else {
      $build['status'] = [
        '#markup' => '<div class="messages messages--status"><strong>' . $this->t('PARITY PASS') . '</strong> — ' . $this->t('LangGraph engine currently matches legacy parity checks.') . '</div>',
      ];
      $build['expected_action'] = $this->renderLanggraphExpectedActionFixed(
        'status',
        'Expected operator action (PASS)',
        'Proceed with normal orchestration and release decisions; continue routine monitoring on Session Health and Release Control pages.'
      );
    }
    $build['next_actions'] = $this->renderLanggraphNavList(
      (string) $this->t('Next actions'),
      $parity_ok
        ? [
          $this->safeRouteLinkOrCurrent('Open LangGraph dashboard home', 'copilot_agent_tracker.langgraph_dashboard'),
          $this->safeRouteLinkOrCurrent('Review release control', 'copilot_agent_tracker.langgraph_release_status'),
        ]
        : [
          $this->safeRouteLinkOrCurrent('Review session health for execution instability', 'copilot_agent_tracker.langgraph_session'),
          $this->safeRouteLinkOrCurrent('Open LangGraph dashboard home to check control state', 'copilot_agent_tracker.langgraph_dashboard'),
          $this->safeRouteLinkOrCurrent('Review release triage before approvals', 'copilot_agent_tracker.langgraph_release_troubleshooting'),
        ]
    );

    $status_label = $parity_ok ? $this->t('✔ PASS') : $this->t('✘ FAIL');
    $agents_match = isset($parity['selected_agents']['match']) ? ($parity['selected_agents']['match'] ? $this->t('yes') : $this->t('no')) : $this->t('n/a');
    $steps_match = isset($parity['steps']['match']) ? ($parity['steps']['match'] ? $this->t('yes') : $this->t('no')) : $this->t('n/a');
    $errors = (array) ($parity['errors'] ?? []);
    $generated_at = (string) ($parity['generated_at'] ?? 'unknown');

    $build['summary_table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#rows' => [
        [$this->t('parity_ok'), $status_label],
        [$this->t('selected_agents.match'), $agents_match],
        [$this->t('steps.match'), $steps_match],
        [$this->t('generated_at'), $generated_at],
        [$this->t('errors'), empty($errors) ? $this->t('(none)') : implode('; ', $errors)],
      ],
    ];

    $build['troubleshooting'] = $this->renderLanggraphTroubleshootingSection('Parity Troubleshooting');

    return $this->sanitizeRenderArray($build);
  }

  /**
   * LangGraph Release Control view.
   *
   * Route: /admin/reports/copilot-agent-tracker/langgraph/release-status
   */
  public function langGraphReleaseStatus(): array {
    $build = $this->buildLanggraphPageShell(
      'LangGraph Release Control',
      'release status troubleshooting mirrors the main Copilot Agent Tracker operational diagnostics.'
    );
    $build['guide'] = $this->renderLanggraphPageGuide(
      'Show release control readiness and recent release-related runtime posture.',
      'Use this page to confirm release controls and publishing are enabled, then verify 24h publishing continuity before coordinated push decisions.',
      [
        'Latest tick release-relevant controls: publish_enabled, dry_run, and agent_cap.',
        'Release control state from release-cycle-control.json.',
        '24h count of ticks where publish_enabled=true as continuity signal.',
      ]
    );
    $build['responsibility'] = $this->renderLanggraphResponsibilityCard(
      'Release readiness posture and control-state validation.',
      [
        'Publish/release control state and continuity signal.',
        'Release hold/ready decision framing.',
      ],
      [
        'Signoff evidence and narrative review (Release Evidence page).',
        'Seat-level blocker diagnostics (Release Triage page).',
      ]
    );

    $ticks_path2 = $this->langgraphPath(self::LANGGRAPH_TICKS_RELATIVE);
    $content = $this->readFileSafe($ticks_path2);
    if ($content === NULL) {
      $build['empty'] = ['#markup' => '<p class="messages messages--warning">' . $this->t('Tick data unavailable — @path could not be read.', ['@path' => $ticks_path2]) . '</p>'];
      return $this->sanitizeRenderArray($build);
    }

    $all_ticks = $this->parseJsonl($content);
    if (empty($all_ticks)) {
      $build['empty'] = ['#markup' => '<p>' . $this->t('No ticks recorded yet.') . '</p>'];
      return $this->sanitizeRenderArray($build);
    }

    $latest = end($all_ticks);
    $publish_enabled = !empty($latest['publish_enabled']);
    $dry_run = !empty($latest['dry_run']);
    $agent_cap = (int) ($latest['agent_cap'] ?? 0);
    $release_control = $this->readReleaseCycleControlState();
    $release_enabled = (bool) ($release_control['enabled'] ?? TRUE);

    // Count ticks in last 24h with publish_enabled == true.
    $cutoff = time() - 86400;
    $publish_enabled_count = 0;
    foreach ($all_ticks as $tick) {
      $ts = strtotime((string) ($tick['ts'] ?? ''));
      if ($ts !== FALSE && $ts >= $cutoff && !empty($tick['publish_enabled'])) {
        $publish_enabled_count++;
      }
    }

    if (!$publish_enabled) {
      $build['publish_notice'] = [
        '#markup' => '<div class="messages messages--warning">' . $this->t('Publishing paused — review orchestrator state.') . '</div>',
      ];
    }

    $build['release_health'] = [
      '#markup' => ($publish_enabled && $release_enabled)
        ? '<div class="messages messages--status"><strong>' . $this->t('Release Control Health: OK') . '</strong> — ' . $this->t('Publishing and release controls are enabled.') . '</div>'
        : '<div class="messages messages--warning"><strong>' . $this->t('Release Control Health: ATTENTION') . '</strong> — ' . $this->t('Publishing or release controls are disabled.') . '</div>',
    ];
    $build['expected_action'] = $this->renderLanggraphExpectedAction(
      $publish_enabled && $release_enabled,
      'Expected operator action (READY TO SHIP)',
      'Continue release execution and approvals per normal gate process.',
      'Expected operator action (HOLD)',
      'Do not approve coordinated release actions until publishing and release controls are re-enabled.'
    );
    $build['next_actions'] = $this->renderLanggraphNavList(
      (string) $this->t('Next actions'),
      [
        $this->safeRouteLinkOrCurrent('Adjust release control', 'copilot_agent_tracker.langgraph_dashboard'),
        $this->safeRouteLinkOrCurrent('Review parity health', 'copilot_agent_tracker.langgraph_parity'),
      ]
    );

    $build['status_table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#rows' => [
        [$this->t('Latest tick timestamp'), (string) ($latest['ts'] ?? 'unknown')],
        [$this->t('publish_enabled'), $publish_enabled ? $this->t('yes') : $this->t('no')],
        [$this->t('release_cycle_enabled'), $release_enabled ? $this->t('yes') : $this->t('no')],
        [$this->t('dry_run'), $dry_run ? $this->t('yes (dry run mode)') : $this->t('no (live)')],
        [$this->t('agent_cap'), (string) $agent_cap],
        [$this->t('Ticks with publish_enabled=true (last 24h)'), (string) $publish_enabled_count],
      ],
    ];

    $build['troubleshooting'] = $this->renderLanggraphTroubleshootingSection('Release Control Troubleshooting');

    return $this->sanitizeRenderArray($build);
  }

  /**
   * LangGraph release evidence view.
   *
   * Route: /admin/reports/copilot-agent-tracker/langgraph/release-notes
   */
  public function langGraphReleaseNotes(): array {
    $build = $this->buildLanggraphPageShell(
      'LangGraph Release Evidence',
      'this is the release notes view linked from the LangGraph dashboard tabs.'
    );
    $build['guide'] = $this->renderLanggraphPageGuide(
      'Provide the release evidence and narrative output tied to LangGraph-managed release flow.',
      'Use this page after release control checks to validate signoffs, change narrative, and test/security evidence before approvals.',
      [
        'Release notes feed produced by the existing release notes view and artifacts.',
        'Consolidated release candidate/signoff context for operator review.',
        'A documentation surface for shipped and in-flight release decisions.',
      ]
    );
    $build['responsibility'] = $this->renderLanggraphResponsibilityCard(
      'Release evidence and signoff narrative.',
      [
        'Release note artifacts and approval evidence.',
        'Communication-ready release context.',
      ],
      [
        'Control-state readiness checks (Release Control page).',
        'Blocker triage and seat routing (Release Triage page).',
      ]
    );
    $build['expected_action'] = $this->renderLanggraphExpectedActionFixed(
      'status',
      'Expected operator action',
      'Use this page as the final evidence checkpoint before approving or communicating a release.'
    );
    $build['notes'] = $this->releaseNotes();

    return $this->sanitizeRenderArray($build);
  }

  /**
   * LangGraph release triage view.
   *
   * Route: /admin/reports/copilot-agent-tracker/langgraph/release-troubleshooting
   */
  public function langGraphReleaseTroubleshooting(): array {
    $build = $this->buildLanggraphPageShell('LangGraph Release Triage');
    $build['intro'] = [
      '#markup' => '<p>Use this page to identify where work is currently sitting by seat, including active item and current action.</p>',
    ];
    $build['guide'] = $this->renderLanggraphPageGuide(
      'Diagnose release bottlenecks by seat-level workload and state.',
      'Use this page when release flow stalls to identify blocked or needs-info seats, and to see active/next work-item positioning per agent.',
      [
        'Seat-level table of status, current action, active item, next item, and inbox depth.',
        'Aggregate counts of blocked, needs-info, and working seats.',
        'A troubleshooting pivot into per-agent detail pages for targeted intervention.',
      ]
    );
    $build['responsibility'] = $this->renderLanggraphResponsibilityCard(
      'Operational blocker triage by seat.',
      [
        'Blocked/needs-info ownership visibility.',
        'Seat-level active/next queue diagnosis.',
      ],
      [
        'Release approval evidence (Release Evidence page).',
        'Top-level release readiness posture (Release Control page).',
      ]
    );

    if (!$this->database->schema()->tableExists('copilot_agent_tracker_agents')) {
      $build['empty'] = [
        '#markup' => '<p class="messages messages--warning">' . $this->t('Tracker agent table is missing. Run database updates and publishing jobs.') . '</p>',
      ];
      return $this->sanitizeRenderArray($build);
    }

    $rows = $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', ['agent_id', 'role', 'website', 'module', 'status', 'current_action', 'last_seen', 'metadata'])
      ->orderBy('website', 'ASC')
      ->orderBy('module', 'ASC')
      ->orderBy('role', 'ASC')
      ->orderBy('agent_id', 'ASC')
      ->execute()
      ->fetchAllAssoc('agent_id');

    $table_rows = [];
    $blocked = 0;
    $needs_info = 0;
    $working = 0;

    foreach ($rows as $agent_id => $row) {
      $status = trim((string) ($row->status ?? ''));
      $status_lc = strtolower($status);
      if (str_contains($status_lc, 'blocked')) {
        $blocked++;
      }
      if (str_contains($status_lc, 'needs-info') || str_contains($status_lc, 'needs_info')) {
        $needs_info++;
      }
      if (str_contains($status_lc, 'running') || str_contains($status_lc, 'working') || str_contains($status_lc, 'in-progress')) {
        $working++;
      }

      $meta = [];
      if (!empty($row->metadata)) {
        try {
          $meta = Json::decode((string) $row->metadata) ?? [];
        }
        catch (\Throwable) {
          $meta = [];
        }
      }

      $active_item = trim((string) ($meta['active_inbox'] ?? ''));
      $next_item = trim((string) ($meta['next_inbox'] ?? ''));
      $inbox_count = (int) ($meta['inbox_count'] ?? 0);

      $agent_link = Link::fromTextAndUrl(
        (string) $agent_id,
        Url::fromRoute('copilot_agent_tracker.agent', ['agent_id' => (string) $agent_id])
      )->toString();

      $table_rows[] = [
        Markup::create($agent_link),
        (string) ($row->website ?? ''),
        (string) ($row->module ?? ''),
        (string) ($row->role ?? ''),
        $status !== '' ? $status : '-',
        trim((string) ($row->current_action ?? '')) ?: '-',
        $active_item !== '' ? $active_item : '-',
        $next_item !== '' ? $next_item : '-',
        (string) $inbox_count,
        !empty($row->last_seen) ? $this->dateFormatter->format((int) $row->last_seen, 'short') : '-',
      ];
    }

    $has_release_blockers = ($blocked > 0) || ($needs_info > 0);
    $build['expected_action'] = $this->renderLanggraphExpectedAction(
      !$has_release_blockers,
      'Expected operator action (NO BLOCKERS)',
      'Proceed to release notes/evidence review and finalize approvals.',
      'Expected operator action (BLOCKERS PRESENT)',
      'Route decisions to the owning seats and clear blocked/needs-info items before shipping.'
    );

    $build['summary'] = [
      '#type' => 'table',
      '#header' => [$this->t('Signal'), $this->t('Value')],
      '#rows' => [
        [$this->t('Total tracked seats'), (string) count($rows)],
        [$this->t('Blocked seats'), (string) $blocked],
        [$this->t('Needs-info seats'), (string) $needs_info],
        [$this->t('Working seats'), (string) $working],
      ],
    ];

    $build['current_action_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Agent'),
        $this->t('Website'),
        $this->t('Module'),
        $this->t('Role'),
        $this->t('Status'),
        $this->t('Current action'),
        $this->t('Active item'),
        $this->t('Next item'),
        $this->t('Inbox count'),
        $this->t('Last seen'),
      ],
      '#rows' => $table_rows,
      '#empty' => $this->t('No agent state published yet.'),
    ];

    $build['troubleshooting'] = $this->renderLanggraphTroubleshootingSection('Release Triage Troubleshooting');

    return $this->sanitizeRenderArray($build);
  }

}
