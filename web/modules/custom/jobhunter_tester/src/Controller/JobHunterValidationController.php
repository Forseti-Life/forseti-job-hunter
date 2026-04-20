<?php

declare(strict_types=1);

namespace Drupal\jobhunter_tester\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\job_hunter\Controller\JobHunterControllerTrait;
use Drupal\Core\Url;
use Drupal\Component\Render\FormattableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Validation dashboard for Job Hunter routes.
 *
 * Tests route access across user roles using internal sub-requests
 * (same approach as NFR validation). No external HTTP calls.
 */
class JobHunterValidationController extends ControllerBase {

  use JobHunterControllerTrait;

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly RouteProviderInterface $routeProvider,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly HttpKernelInterface $httpKernel,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('router.route_provider'),
      $container->get('account_switcher'),
      $container->get('http_kernel'),
      $container->get('logger.factory')->get('job_hunter'),
    );
  }

  /**
   * Build sub-navigation for tester pages.
   */
  private function buildSubNavigation(string $active_page = 'validation'): array {
    $items = [
      'validation' => [
        'title' => '✓ Validation Dashboard',
        'url' => Url::fromRoute('jobhunter_tester.validation'),
      ],
      'unit_tests' => [
        'title' => '⚡ Unit Tests',
        'url' => Url::fromRoute('jobhunter_tester.unit_tests'),
      ],
    ];

    $links = [];
    foreach ($items as $key => $item) {
      $is_active = ($key === $active_page);
      $active_class = $is_active ? 'tester-nav-active' : '';
      $links[] = '<a href="' . $item['url']->toString() . '" class="tester-nav-link ' . $active_class . '">' . $item['title'] . '</a>';
    }

    return [
      '#markup' => '<div class="tester-sub-nav"><div class="tester-nav-container">' . implode('', $links) . '</div></div>',
    ];
  }

  /**
   * Validation dashboard page.
   */
  public function validationDashboard(): array {
    $routes = $this->getJobHunterRoutes();
    $test_users = $this->getTestUsers();

    // Get base URL from query parameter or default to current
    $request = \Drupal::request();
    $environment = $request->query->get('env', 'current');
    
    $environments = [
      'current' => $request->getSchemeAndHttpHost(),
      'production' => 'https://forseti.life',
      'localhost' => 'http://localhost',
    ];
    
    $base_url = $environments[$environment] ?? $environments['current'];

    $content = [
      'sub_nav' => $this->buildSubNavigation('validation'),
      'environment_selector' => [
        '#type' => 'inline_template',
        '#template' => '<div class="env-selector-card">' .
          '<form method="get" action="{{ action_url }}">' .
          '<label for="env">🌐 Test Environment:</label>' .
          '<select name="env" id="env">' .
          '<option value="current"{{ current_selected }}>Current ({{ current_host }})</option>' .
          '<option value="production"{{ production_selected }}>Production (https://forseti.life)</option>' .
          '<option value="localhost"{{ localhost_selected }}>Localhost (http://localhost)</option>' .
          '</select>' .
          '<button type="submit">▶️ Run Tests</button>' .
          '</form>' .
          '</div>',
        '#context' => [
          'action_url' => Url::fromRoute('jobhunter_tester.validation')->toString(),
          'current_host' => $environments['current'],
          'current_selected' => $environment === 'current' ? ' selected' : '',
          'production_selected' => $environment === 'production' ? ' selected' : '',
          'localhost_selected' => $environment === 'localhost' ? ' selected' : '',
        ],
      ],
      'dashboard' => [
        '#markup' => new FormattableMarkup($this->buildDashboardHtml($routes, $test_users, $base_url), []),
      ],
      '#attached' => [
        'library' => ['jobhunter_tester/validation'],
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Test a single route for a specific user via sub-request.
   */
  public function testRoute(Request $request): JsonResponse {
    $route_name = $request->query->get('route');
    $path = $request->query->get('path');
    $uid = (int) $request->query->get('uid');
    $expected = $request->query->get('expected');

    if (!$route_name || !$path) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Missing route or path parameter',
      ], 400);
    }

    return new JsonResponse($this->testRouteAccess($route_name, $path, $uid, $expected));
  }

  /**
   * Get all job_hunter GET routes, excluding tester/validation routes.
   */
  private function getJobHunterRoutes(): array {
    $routes = [];
    $all_routes = $this->routeProvider->getAllRoutes();

    // Exclude the validation/tester routes themselves.
    $exclude_prefixes = [
      'jobhunter_tester.',
    ];

    foreach ($all_routes as $route_name => $route) {
      // Only job_hunter routes.
      if (!str_starts_with($route_name, 'job_hunter.')) {
        continue;
      }

      // Skip excluded prefixes.
      $skip = FALSE;
      foreach ($exclude_prefixes as $prefix) {
        if (str_starts_with($route_name, $prefix)) {
          $skip = TRUE;
          break;
        }
      }
      if ($skip) {
        continue;
      }

      $path = $route->getPath();
      $methods = $route->getMethods();
      $requirements = $route->getRequirements();

      // Only GET routes (or routes with no explicit method restriction).
      if (!empty($methods) && !in_array('GET', $methods)) {
        continue;
      }

      $routes[$route_name] = [
        'name' => $route_name,
        'path' => $path,
        'title' => $route->getDefault('_title') ?? 'No title',
        'permission' => $requirements['_permission'] ?? NULL,
        'requires_login' => isset($requirements['_user_is_logged_in']),
        'has_parameters' => str_contains($path, '{'),
      ];
    }

    // Sort by path.
    usort($routes, fn($a, $b) => strcmp($a['path'], $b['path']));

    return $routes;
  }

  /**
   * Get test users.
   *
   * Permission matrix for job_hunter:
   * - Anonymous: no access (all routes need permission)
   * - Authenticated: 'access job hunter' routes only
   * - Admin (UID 1): all routes
   */
  private function getTestUsers(): array {
    $test_users = [
      'anonymous' => [
        'uid' => 0,
        'name' => 'Anonymous',
        'label' => 'Anonymous',
      ],
      'admin' => [
        'uid' => 1,
        'name' => 'admin',
        'label' => 'Admin (UID 1)',
      ],
    ];

    // Find an authenticated user with no special admin roles.
    $connection = \Drupal::database();
    $query = $connection->select('users_field_data', 'u')
      ->fields('u', ['uid', 'name'])
      ->condition('u.status', 1)
      ->condition('u.uid', 1, '>')
      ->range(0, 1);
    $result = $query->execute()->fetchAssoc();

    if ($result) {
      $test_users['authenticated'] = [
        'uid' => (int) $result['uid'],
        'name' => $result['name'],
        'label' => 'Authenticated',
      ];
    }

    return $test_users;
  }

  /**
   * Determine expected access for a route/user combination.
   */
  private function shouldHaveAccess(?string $permission, bool $requires_login, string $user_key): bool {
    if ($user_key === 'anonymous') {
      return !$requires_login && ($permission === NULL || $permission === 'access content');
    }

    if ($user_key === 'admin') {
      return TRUE;
    }

    // Authenticated user.
    if ($permission === NULL || $permission === 'access content' || $permission === 'access job hunter') {
      return TRUE;
    }

    // 'administer job application automation' — regular user has no admin.
    return FALSE;
  }

  /**
   * Test route access for a specific user using sub-request.
   */
  private function testRouteAccess(string $route_name, string $path, int $uid, ?string $expected = NULL): array {
    $result = [
      'route' => $route_name,
      'path' => $path,
      'uid' => $uid,
      'status_code' => NULL,
      'access' => NULL,
      'error' => NULL,
      'expected' => $expected,
    ];

    try {
      // Load or create anonymous user.
      if ($uid > 0) {
        $user = \Drupal\user\Entity\User::load($uid);
        if (!$user) {
          $result['error'] = 'User not found';
          return $result;
        }
      }
      else {
        $user = \Drupal\user\Entity\User::getAnonymousUser();
      }

      $this->accountSwitcher->switchTo($user);

      try {
        $url = Url::fromRoute($route_name);
        $access = $url->access($user);
        $result['access'] = $access;

        if ($access) {
          // Access granted — try to render via sub-request.
          try {
            $test_path = $path;
            // Replace any parameter placeholders with test values.
            $test_path = preg_replace('/\{[^}]+\}/', '1', $test_path);

            $sub_request = Request::create($test_path, 'GET');
            $response = $this->httpKernel->handle($sub_request, HttpKernelInterface::SUB_REQUEST, FALSE);
            $status_code = $response->getStatusCode();

            if ($status_code === 200) {
              $result['status_code'] = 200;
              $result['status_text'] = 'OK';
              $result['class'] = 'success';
            }
            elseif ($status_code >= 300 && $status_code < 400) {
              $result['status_code'] = 200;
              $result['status_text'] = 'OK (Redirect)';
              $result['class'] = 'success';
            }
            elseif ($status_code === 500) {
              $result['status_code'] = 500;
              $result['status_text'] = 'Server Error';
              $result['class'] = 'error';
              $result['error'] = 'HTTP 500';
            }
            else {
              $result['status_code'] = $status_code;
              $result['status_text'] = 'HTTP ' . $status_code;
              $result['class'] = 'error';
            }
          }
          catch (\Exception $e) {
            $result['status_code'] = 500;
            $result['status_text'] = 'Error';
            $result['class'] = 'error';
            $result['error'] = substr($e->getMessage(), 0, 150);
          }
        }
        else {
          $result['status_code'] = 403;
          $result['status_text'] = 'Forbidden';
          $result['class'] = 'forbidden';
        }
      }
      catch (\Exception $e) {
        $result['status_code'] = 500;
        $result['status_text'] = 'Error';
        $result['class'] = 'error';
        $result['error'] = $e->getMessage();
      }

      $this->accountSwitcher->switchBack();
    }
    catch (\Exception $e) {
      $result['error'] = $e->getMessage();
      $result['status_code'] = 500;
      $result['class'] = 'error';
    }

    // Check expected vs actual.
    if ($expected !== NULL) {
      $actual_access = ($result['status_code'] === 200);
      $expected_access = ($expected === 'allow');
      $result['matches_expected'] = ($actual_access === $expected_access)
        || ($result['status_code'] === 403 && $expected === 'deny');
    }

    return $result;
  }

  /**
   * Check database tables via AJAX.
   */
  public function checkTables(): JsonResponse {
    $db = \Drupal::database();
    $expected_tables = $this->getExpectedTables();
    $results = [];
    $all_exist = TRUE;

    foreach ($expected_tables as $table => $description) {
      $exists = $db->schema()->tableExists($table);
      $count = NULL;
      if ($exists) {
        try {
          $count = (int) $db->select($table)->countQuery()->execute()->fetchField();
        }
        catch (\Exception $e) {
          $count = -1;
        }
      }
      else {
        $all_exist = FALSE;
      }
      $results[] = [
        'table' => $table,
        'description' => $description,
        'exists' => $exists,
        'row_count' => $count,
      ];
    }

    // Check for orphaned old-convention tables.
    $old_tables = [];
    try {
      $old_tables = $db->query("SHOW TABLES LIKE 'job\\_hunter\\_%'")->fetchCol();
    }
    catch (\Exception $e) {
      // Ignore.
    }

    return new JsonResponse([
      'tables' => $results,
      'all_exist' => $all_exist,
      'total' => count($expected_tables),
      'existing' => count(array_filter($results, fn($r) => $r['exists'])),
      'old_tables' => $old_tables,
    ]);
  }

  /**
   * Get the expected jobhunter tables and their descriptions.
   */
  private function getExpectedTables(): array {
    return [
      'jobhunter_companies' => 'Company records for job postings',
      'jobhunter_education_history' => 'User education history',
      'jobhunter_google_jobs_sync' => 'Google Jobs API sync tracking',
      'jobhunter_google_jobs_validation_log' => 'Google Jobs validation logs',
      'jobhunter_job_history' => 'User job/employment history',
      'jobhunter_job_requirements' => 'Job posting requirements',
      'jobhunter_job_seeker' => 'Job seeker profile data',
      'jobhunter_job_seeker_resumes' => 'Uploaded resume files',
      'jobhunter_pdf_history' => 'Generated PDF resume history',
      'jobhunter_resume_parsed_data' => 'Parsed resume content (GenAI)',
      'jobhunter_tailored_resumes' => 'Tailored resume output per job',
    ];
  }

  /**
   * Build the dashboard HTML.
   *
   * @param array $routes
   *   Job Hunter routes.
   * @param array $users
   *   Test users.
   * @param string $base_url
   *   Base URL for testing context.
   *
   * @return string
   *   HTML string.
   */
  private function buildDashboardHtml(array $routes, array $users, string $base_url = ''): string {
    $html = '<div class="jh-validation-dashboard">';

    // Show current test environment
    if ($base_url) {
      $html .= '<div class="page-header"><p><strong>Testing URL:</strong> ' . htmlspecialchars($base_url) . '</p></div>';
    }

    // Stats row.
    $testable = count(array_filter($routes, fn($r) => !$r['has_parameters']));
    $parameterized = count($routes) - $testable;

    $html .= '<div class="stats-grid">';
    $html .= $this->statBox((string) count($routes), 'Total Routes');
    $html .= $this->statBox((string) $testable, 'Testable');
    $html .= $this->statBox((string) $parameterized, 'Need Parameters');
    $html .= $this->statBox((string) count($users), 'User Roles');
    $html .= $this->statBox((string) ($testable * count($users)), 'Total Tests');
    $html .= '</div>';

    // Actions.
    $html .= '<div class="action-buttons">';
    $html .= '<button id="test-all-routes" class="btn-primary">▶️ Run All Tests</button>';
    $html .= '<button id="check-tables" class="btn-primary">📊 Check Database Tables</button>';
    $html .= '<button id="clear-results" class="btn-secondary">🗑️ Clear Results</button>';
    $html .= '</div>';

    // Database health section.
    $html .= '<div id="db-health-section" class="db-health-card">';
    $html .= '<h3>📊 Database Health</h3>';
    $html .= '<div id="db-health-summary" style="margin-bottom:15px;"></div>';
    $html .= '<table class="validation-table">';
    $html .= '<thead><tr>';
    $html .= '<th>Status</th>';
    $html .= '<th>Table</th>';
    $html .= '<th>Description</th>';
    $html .= '<th style="text-align:right;">Rows</th>';
    $html .= '</tr></thead>';
    $html .= '<tbody id="db-health-body"></tbody>';
    $html .= '</table>';
    $html .= '<div id="db-old-tables" style="display:none; margin-top:15px;"></div>';
    $html .= '</div>';

    // Summary placeholder.
    $html .= '<div id="test-summary" style="display:none; margin-bottom:25px;"></div>';

    // Routes table.
    $html .= '<table class="validation-table">';
    $html .= '<thead><tr>';
    $html .= '<th>Path</th>';
    $html .= '<th>Permission</th>';

    foreach ($users as $user) {
      $html .= '<th style="text-align:center;">' . htmlspecialchars($user['label']) . '</th>';
    }

    $html .= '</tr></thead><tbody>';

    foreach ($routes as $route) {
      $route_id = str_replace('.', '_', $route['name']);
      $html .= '<tr data-route="' . htmlspecialchars($route['name']) . '">';

      // Path column.
      if ($route['has_parameters']) {
        $html .= '<td><code>' . htmlspecialchars($route['path']) . '</code> <small style="color:#9ca3af;">(params)</small></td>';
      }
      else {
        $html .= '<td><a href="' . htmlspecialchars($route['path']) . '" target="_blank" style="color:#667eea; text-decoration:none; font-weight:500;"><code>' . htmlspecialchars($route['path']) . '</code></a></td>';
      }

      // Permission column.
      $perm = $route['permission'] ? '<code>' . htmlspecialchars($route['permission']) . '</code>' : '—';
      $html .= '<td>' . $perm . '</td>';

      // User test cells.
      foreach ($users as $user_key => $user) {
        $cell_id = $route_id . '_' . $user_key;
        $should_access = $this->shouldHaveAccess($route['permission'], $route['requires_login'], $user_key);
        $expected_icon = $should_access ? '✓' : '✗';
        $expected_color = $should_access ? '#10b981' : '#ef4444';
        $expected_val = $should_access ? 'allow' : 'deny';

        $html .= '<td style="text-align:center;" id="cell-' . $cell_id . '">';
        $html .= '<span style="color:' . $expected_color . '; margin-right:4px; font-weight:600;">' . $expected_icon . '</span>';

        if (!$route['has_parameters']) {
          $html .= '<button class="jh-test-btn" style="padding:4px 12px; background:#f3f4f6; color:#667eea; border:1px solid #e5e7eb; border-radius:4px; cursor:pointer; font-size:12px; font-weight:500; transition:all 0.2s;" '
            . 'data-route="' . htmlspecialchars($route['name']) . '" '
            . 'data-path="' . htmlspecialchars($route['path']) . '" '
            . 'data-uid="' . $user['uid'] . '" '
            . 'data-user="' . htmlspecialchars($user['name']) . '" '
            . 'data-expected="' . $expected_val . '">'
            . 'Test</button>';
        }
        else {
          $html .= '<span style="color:#9ca3af;">—</span>';
        }

        $html .= '<div class="jh-test-result" id="result-' . $cell_id . '"></div>';
        $html .= '</td>';
      }

      $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    $html .= '</div>';

    return $html;
  }

  /**
   * Render a stat box.
   */
  private function statBox(string $value, string $label): string {
    return '<div class="stat-card">'
      . '<div class="stat-value">' . $value . '</div>'
      . '<div class="stat-label">' . $label . '</div>'
      . '</div>';
  }

}
