<?php

namespace Drupal\jobhunter_tester\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\job_hunter\Controller\JobHunterControllerTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Controller for testing Job Hunter routes.
 */
class JobHunterTesterController extends ControllerBase {

  use JobHunterControllerTrait;

  /**
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The account switcher.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleExtensionList;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a JobHunterTesterController object.
   */
  public function __construct(RouteProviderInterface $route_provider, ClientInterface $http_client, RequestStack $request_stack, AccountSwitcherInterface $account_switcher, ModuleExtensionList $module_extension_list, $database) {
    $this->routeProvider = $route_provider;
    $this->httpClient = $http_client;
    $this->requestStack = $request_stack;
    $this->accountSwitcher = $account_switcher;
    $this->moduleExtensionList = $module_extension_list;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('router.route_provider'),
      $container->get('http_client'),
      $container->get('request_stack'),
      $container->get('account_switcher'),
      $container->get('extension.list.module'),
      $container->get('database')
    );
  }

  /**
   * Build sub-navigation for tester pages.
   */
  private function buildSubNavigation(string $active_page = 'route_testing'): array {
    $items = [
      'route_testing' => [
        'title' => '🧪 Route Testing',
        'url' => Url::fromRoute('jobhunter_tester.test_page'),
      ],
      'unit_tests' => [
        'title' => '⚡ Unit Tests',
        'url' => Url::fromRoute('jobhunter_tester.unit_tests'),
      ],
      'validation' => [
        'title' => '✓ Validation Dashboard',
        'url' => Url::fromRoute('jobhunter_tester.validation'),
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
   * Test all Job Hunter routes.
   */
  public function testPage() {
    $build = [];
    
    // Attach styling library
    $build['#attached']['library'][] = 'jobhunter_tester/tester_styles';
    
    // Add sub-navigation
    $build['sub_nav'] = $this->buildSubNavigation('route_testing');
    
    // Get base URL from query parameter or default to current
    $request = $this->requestStack->getCurrentRequest();
    $environment = $request->query->get('env', 'current');
    
    $environments = [
      'current' => $request->getSchemeAndHttpHost(),
      'production' => 'https://forseti.life',
      'localhost' => 'http://localhost',
    ];
    
    $base_url = $environments[$environment] ?? $environments['current'];
    
    // Get all routes
    $all_routes = $this->routeProvider->getAllRoutes();
    $job_hunter_routes = [];
    
    // Filter for job_hunter routes
    foreach ($all_routes as $route_name => $route) {
      if (strpos($route_name, 'job_hunter.') === 0) {
        $path = $route->getPath();
        $methods = $route->getMethods();
        $permission_info = $this->getRoutePermissionInfo($route);
        
        // Only test GET routes (skip POST, PUT, DELETE)
        if (empty($methods) || in_array('GET', $methods)) {
          $job_hunter_routes[$route_name] = [
            'name' => $route_name,
            'path' => $path,
            'title' => $route->getDefault('_title') ?? 'No Title',
            'permission' => $permission_info['permission'],
            'requires_login' => $permission_info['requires_login'],
          ];
        }
      }
    }
    
    // Sort routes by name
    ksort($job_hunter_routes);
    
    // Environment selector — use inline_template so form/select/button
    // elements are not stripped by Drupal's Xss::filterAdmin().
    $current_url = Url::fromRoute('jobhunter_tester.test_page')->toString();
    $build['environment_selector'] = [
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
        'action_url' => $current_url,
        'current_host' => $environments['current'],
        'current_selected' => $environment === 'current' ? ' selected' : '',
        'production_selected' => $environment === 'production' ? ' selected' : '',
        'localhost_selected' => $environment === 'localhost' ? ' selected' : '',
      ],
    ];
    
    $build['summary'] = [
      '#markup' => '<div class="page-header">' .
        '<h2>🧪 Job Hunter Route Testing</h2>' .
        '<p>Found <span class="badge">' . count($job_hunter_routes) . ' routes</span> GET-accessible Job Hunter routes.</p>' .
        '<p><strong>Testing URL:</strong> ' . $base_url . '</p>' .
        '<p><strong>Testing Mode:</strong> HTTP Response + Permission Analysis</p>' .
        '</div>',
    ];
    
    // Get test users
    $test_users = $this->getTestUsers();

    // Test each route
    $results = [];
    foreach ($job_hunter_routes as $route_info) {
      $url = $base_url . $route_info['path'];
      $status = 'pending';
      $status_code = null;
      $error_message = '';
      
      // Skip routes with parameters (they need specific values)
      if (strpos($route_info['path'], '{') !== FALSE) {
        $status = 'skipped';
        $error_message = 'Route requires parameters';
      }
      else {
        try {
          $response = $this->httpClient->request('GET', $url, [
            'http_errors' => FALSE,
            'timeout' => 10,
          ]);
          
          $status_code = $response->getStatusCode();
          
          if ($status_code === 200) {
            $status = 'success';
          }
          elseif ($status_code >= 300 && $status_code < 400) {
            $status = 'redirect';
            $error_message = 'Redirected (code: ' . $status_code . ')';
          }
          elseif ($status_code === 403) {
            $status = 'forbidden';
            $error_message = 'Access Denied (403)';
          }
          elseif ($status_code === 404) {
            $status = 'not-found';
            $error_message = 'Not Found (404)';
          }
          else {
            $status = 'error';
            $error_message = 'HTTP ' . $status_code;
          }
        }
        catch (\Exception $e) {
          $status = 'error';
          $error_message = $e->getMessage();
        }
      }
      
      $results[] = [
        'name' => $route_info['name'],
        'path' => $route_info['path'],
        'url' => $url,
        'title' => $route_info['title'],
        'status' => $status,
        'status_code' => $status_code,
        'error_message' => $error_message,
        'permission' => $route_info['permission'] ?? 'None',
        'requires_login' => $route_info['requires_login'] ? 'Yes' : 'No',
        'expected_access' => $this->getExpectedAccessSummary($route_info, $test_users),
      ];
    }
    
    // Count results
    $success_count = count(array_filter($results, fn($r) => $r['status'] === 'success'));
    $error_count = count(array_filter($results, fn($r) => $r['status'] === 'error'));
    $forbidden_count = count(array_filter($results, fn($r) => $r['status'] === 'forbidden'));
    $not_found_count = count(array_filter($results, fn($r) => $r['status'] === 'not-found'));
    $redirect_count = count(array_filter($results, fn($r) => $r['status'] === 'redirect'));
    $skipped_count = count(array_filter($results, fn($r) => $r['status'] === 'skipped'));
    
    $build['stats'] = [
      '#markup' => '<div class="stats-grid">' .
        '<div class="stat-card stat-success"><div class="stat-icon">✓</div><div class="stat-value">' . $success_count . '</div><div class="stat-label">Success (200)</div></div>' .
        '<div class="stat-card stat-redirect"><div class="stat-icon">⚠</div><div class="stat-value">' . $redirect_count . '</div><div class="stat-label">Redirects</div></div>' .
        '<div class="stat-card stat-error"><div class="stat-icon">✗</div><div class="stat-value">' . $error_count . '</div><div class="stat-label">Errors</div></div>' .
        '<div class="stat-card stat-forbidden"><div class="stat-icon">⊗</div><div class="stat-value">' . $forbidden_count . '</div><div class="stat-label">Forbidden (403)</div></div>' .
        '<div class="stat-card stat-notfound"><div class="stat-icon">⊗</div><div class="stat-value">' . $not_found_count . '</div><div class="stat-label">Not Found (404)</div></div>' .
        '<div class="stat-card stat-skipped"><div class="stat-icon">−</div><div class="stat-value">' . $skipped_count . '</div><div class="stat-label">Skipped</div></div>' .
        '</div>',
    ];
    
    // Build results table
    $rows = [];
    foreach ($results as $result) {
      $status_color = match($result['status']) {
        'success' => 'green',
        'redirect' => 'orange',
        'forbidden' => 'orange',
        'not-found' => 'red',
        'error' => 'red',
        'skipped' => 'gray',
        default => 'black',
      };
      
      $status_icon = match($result['status']) {
        'success' => '✓',
        'redirect' => '↗',
        'forbidden' => '⊗',
        'not-found' => '⊗',
        'error' => '✗',
        'skipped' => '−',
        default => '?',
      };
      
      $url_link = $result['status'] !== 'skipped' 
        ? '<a href="' . $result['url'] . '" target="_blank">' . $result['path'] . '</a>'
        : $result['path'];
      
      $rows[] = [
        'data' => [
          ['data' => $status_icon . ' ' . strtoupper($result['status']), 'style' => 'color: ' . $status_color . '; font-weight: bold;'],
          ['data' => $result['status_code'] ?? '−'],
          ['data' => $result['name']],
          ['data' => ['#markup' => $url_link]],
          ['data' => $result['title']],
          ['data' => $result['permission']],
          ['data' => $result['requires_login']],
          ['data' => ['#markup' => $result['expected_access']]],
          ['data' => $result['error_message']],
        ],
      ];
    }
    
    $build['results'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['results-table']],
      '#header' => [
        'Status',
        'Code',
        'Route Name',
        'Path',
        'Title',
        'Permission',
        'Login Required',
        'Expected Access',
        'Error',
      ],
      '#rows' => $rows,
      '#attributes' => [
        'class' => ['results-table'],
      ],
      '#attached' => [
        'library' => [
          'system/admin',
        ],
      ],
    ];
    
    return $this->wrapWithNavigation($build);
  }

  /**
   * Get test users with expected access patterns.
   *
   * @return array
   *   Array of test user configurations.
   */
  private function getTestUsers(): array {
    $test_users = [
      'anonymous' => [
        'uid' => 0,
        'name' => 'Anonymous',
        'label' => 'Anonymous User',
        'expected_permissions' => [],
      ],
      'admin' => [
        'uid' => 1,
        'name' => 'admin',
        'label' => 'Administrator (UID 1)',
        'expected_permissions' => ['*'], // All permissions
      ],
    ];

    // Find an authenticated user without special roles
    $query = $this->database->select('users_field_data', 'u')
      ->fields('u', ['uid', 'name'])
      ->condition('u.status', 1)
      ->condition('u.uid', 1, '>')
      ->range(0, 1);
    $result = $query->execute()->fetchAssoc();
    
    if ($result) {
      $test_users['authenticated'] = [
        'uid' => (int)$result['uid'],
        'name' => $result['name'],
        'label' => 'Authenticated User',
        'expected_permissions' => ['access job hunter', 'access content'],
      ];
    }

    return $test_users;
  }

  /**
   * Determine if user should have access based on route requirements.
   *
   * @param string|null $permission
   *   Required permission.
   * @param bool $requires_login
   *   Whether login is required.
   * @param string $user_key
   *   User key (anonymous, authenticated, admin).
   *
   * @return bool
   *   TRUE if access expected, FALSE otherwise.
   */
  private function shouldHaveAccess(?string $permission, bool $requires_login, string $user_key): bool {
    // Anonymous user
    if ($user_key === 'anonymous') {
      return !$requires_login && ($permission === null || $permission === 'access content');
    }

    // Admin has all access
    if ($user_key === 'admin') {
      return TRUE;
    }

    // Authenticated user
    if ($user_key === 'authenticated') {
      // Basic authenticated permissions
      if ($permission === null || $permission === 'access content' || $permission === 'access job hunter') {
        return TRUE;
      }
      // No admin permissions
      if ($permission === 'administer job application automation') {
        return FALSE;
      }
      return FALSE;
    }

    return FALSE;
  }

  /**
   * Get route permission information.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route object.
   *
   * @return array
   *   Array with permission and requires_login.
   */
  private function getRoutePermissionInfo($route): array {
    $requirements = $route->getRequirements();
    $permission = $requirements['_permission'] ?? null;
    $requires_login = isset($requirements['_user_is_logged_in']);
    
    return [
      'permission' => $permission,
      'requires_login' => $requires_login,
    ];
  }

  /**
   * Get expected access summary for a route.
   *
   * @param array $route_info
   *   Route information.
   * @param array $test_users
   *   Test users array.
   *
   * @return string
   *   HTML string showing expected access.
   */
  private function getExpectedAccessSummary(array $route_info, array $test_users): string {
    $access_list = [];
    
    foreach ($test_users as $user_key => $user_info) {
      $has_access = $this->shouldHaveAccess(
        $route_info['permission'] ?? null,
        $route_info['requires_login'] ?? false,
        $user_key
      );
      
      $icon = $has_access ? '✓' : '✗';
      $color = $has_access ? 'green' : 'red';
      $access_list[] = '<span style="color: ' . $color . ';">' . $icon . ' ' . $user_info['label'] . '</span>';
    }
    
    return implode('<br>', $access_list);
  }

  /**
   * Display unit tests dashboard.
   */
  public function unitTestsPage() {
    $build = [];
    
    // Attach styling library
    $build['#attached']['library'][] = 'jobhunter_tester/tester_styles';
    
    // Add sub-navigation
    $build['sub_nav'] = $this->buildSubNavigation('unit_tests');
    
    $build['header'] = [
      '#markup' => '<div class="page-header">' .
        '<h2>⚡ Job Hunter Unit Tests Dashboard</h2>' .
        '<p>View and run PHPUnit tests for the Job Hunter module.</p>' .
        '</div>',
    ];
    
    // Test categories
    $test_categories = $this->getTestCategories();
    
    // Build category overview
    $category_rows = [];
    foreach ($test_categories as $category_key => $category_info) {
      $status_color = $category_info['status'] === 'implemented' ? 'green' : 'orange';
      $status_icon = $category_info['status'] === 'implemented' ? '✓' : '⚠';
      
      $category_rows[] = [
        'data' => [
          ['data' => $status_icon, 'style' => 'color: ' . $status_color . '; font-weight: bold; font-size: 20px;'],
          $category_info['name'],
          $category_info['test_file'],
          $category_info['test_count'],
          $category_info['description'],
          ['data' => [
            '#type' => 'inline_template',
            '#template' => '<button class="button button--primary test-run-btn" data-test-file="{{ test_file }}">Run Tests</button>',
            '#context' => ['test_file' => $category_info['test_file']],
          ]],
        ],
      ];
    }
    
    $build['categories'] = [
      '#type' => 'table',
      '#header' => ['Status', 'Category', 'Test File', 'Test Count', 'Description', 'Actions'],
      '#rows' => $category_rows,
      '#attributes' => [
        'style' => 'width: 100%; margin-top: 20px;',
      ],
    ];
    
    // Test execution section
    $build['execution'] = [
      '#markup' => '<div id="test-results" style="margin-top: 30px; padding: 20px; background: #f9f9f9; border-radius: 5px; display: none;">' .
        '<h3>Test Results</h3>' .
        '<pre id="test-output" style="background: #fff; padding: 15px; border: 1px solid #ddd; max-height: 600px; overflow: auto;"></pre>' .
        '</div>',
    ];
    
    // Add JavaScript for test execution
    $build['#attached']['library'][] = 'jobhunter_tester/test-runner';
    
    return $this->wrapWithNavigation($build);
  }

  /**
   * Run PHPUnit tests via AJAX.
   */
  public function runTests() {
    $request = $this->requestStack->getCurrentRequest();
    $test_file = $request->request->get('test_file');
    
    if (empty($test_file)) {
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => false,
        'error' => 'No test file specified',
      ], 400);
    }
    
    $module_path = $this->moduleExtensionList->getPath('jobhunter_tester');
    $test_path = DRUPAL_ROOT . '/' . $module_path . '/tests/src/Unit/Service/' . basename($test_file);
    
    if (!file_exists($test_path)) {
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => false,
        'error' => 'Test file not found: ' . basename($test_file),
      ], 404);
    }
    
    // Execute PHPUnit
    $output = [];
    $return_code = 0;
    
    $command = 'cd ' . escapeshellarg(DRUPAL_ROOT) . ' && phpunit --colors=never ' . escapeshellarg($test_path) . ' 2>&1';
    exec($command, $output, $return_code);
    
    $output_text = implode("\n", $output);
    
    return new \Symfony\Component\HttpFoundation\JsonResponse([
      'success' => $return_code === 0,
      'output' => $output_text,
      'return_code' => $return_code,
    ]);
  }

  /**
   * Get test categories and their information.
   */
  private function getTestCategories(): array {
    return [
      'job_seeker_service' => [
        'name' => 'JobSeekerService Tests',
        'test_file' => 'JobSeekerServiceTest.php',
        'test_count' => '14 tests',
        'description' => 'Tests for job seeker profile CRUD operations (JSS-001 to JSS-006)',
        'status' => 'implemented',
      ],
      'user_profile_service_extended' => [
        'name' => 'UserProfileService Extended Tests',
        'test_file' => 'UserProfileServiceExtendedTest.php',
        'test_count' => '8 tests',
        'description' => 'Extended tests for profile statistics and completeness (UPS-006)',
        'status' => 'implemented',
      ],
      'resume_pdf_service' => [
        'name' => 'ResumePdfService Tests',
        'test_file' => 'ResumePdfServiceTest.php',
        'test_count' => '0 tests',
        'description' => 'Tests for PDF generation and styling (RPS-001 to RPS-005)',
        'status' => 'todo',
      ],
      'abbvie_scraping_service' => [
        'name' => 'AbbVieJobScrapingService Tests',
        'test_file' => 'AbbVieJobScrapingServiceTest.php',
        'test_count' => '0 tests',
        'description' => 'Tests for job scraping functionality (AJSS-001 to AJSS-004)',
        'status' => 'todo',
      ],
    ];
  }

}
