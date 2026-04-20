<?php

declare(strict_types=1);

namespace Drupal\nfr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Component\Render\FormattableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Controller for NFR validation and testing.
 */
class NFRValidationController extends ControllerBase {

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
      $container->get('logger.factory')->get('nfr'),
    );
  }

  /**
   * Validation dashboard page.
   *
   * @return array
   *   Render array.
   */
  public function validationDashboard(): array {
    $nfr_routes = $this->getNFRRoutes();
    $test_users = $this->getTestUsers();

    return [
      '#theme' => 'nfr_admin_page',
      '#page_id' => 'validation-dashboard',
      '#content' => [
        '#markup' => new FormattableMarkup($this->buildValidationDashboard($nfr_routes, $test_users), []),
      ],
      '#attached' => [
        'library' => ['nfr/admin', 'nfr/validation'],
      ],
    ];
  }

  /**
   * Get all NFR routes.
   */
  private function getNFRRoutes(): array {
    $routes = [];
    
    // Get all routes
    $all_routes = $this->routeProvider->getAllRoutes();
    
    // Routes to exclude from testing (validation/testing routes themselves)
    $exclude_routes = [
      'nfr.validation',
      'nfr.validation.test_route',
      'nfr.validation.test_questionnaire',
      'nfr.validation.verify_database',
      'nfr.validation.clear_test_data',
      'nfr.validation.test_full_enrollment',
      'nfr.validation.test_max_values',
      'nfr.validation.test_min_values',
      'nfr.validation.test_yes_minimal',
      'nfr.validation.check_error_logs',
      'nfr.validation.create_test_users',
      'nfr.validation.delete_test_users',
      'nfr.validation.submit_all_firefighters',
      'nfr.validation.fill_rates',
      'nfr.validation.fill_rates_redirect',
    ];
    
    foreach ($all_routes as $route_name => $route) {
      // Filter only NFR routes, excluding validation routes
      if (str_starts_with($route_name, 'nfr.') && !in_array($route_name, $exclude_routes)) {
        $path = $route->getPath();
        $requirements = $route->getRequirements();
        $permission = $requirements['_permission'] ?? null;
        $logged_in = isset($requirements['_user_is_logged_in']);
        
        $routes[$route_name] = [
          'name' => $route_name,
          'path' => $path,
          'permission' => $permission,
          'requires_login' => $logged_in,
          'title' => $route->getDefault('_title') ?? 'No title',
        ];
      }
    }
    
    // Sort by path
    usort($routes, fn($a, $b) => strcmp($a['path'], $b['path']));
    
    return $routes;
  }

  /**
   * Get test users with their expected access levels.
   * 
   * Permission Matrix:
   * - access content: All authenticated users (default Drupal permission)
   * - access nfr dashboard: Firefighters, Dept Admins, NFR Admins, Researchers
   * - administer nfr: NFR Administrators only (full system control)
   * - view nfr reports: NFR Researchers and NFR Administrators
   * 
   * Expected Access by Role:
   * - Anonymous (uid=0): Public pages only (/nfr, /nfr/faq, /nfr/contact, /nfr/documentation)
   * - Firefighter Active: Enrollment pages, My Dashboard, public pages
   * - Firefighter Retired: Enrollment pages, My Dashboard, public pages
   * - NFR Administrator: ALL pages including /admin/nfr/* (complete system access)
   * - NFR Researcher: Public pages, reports, data quality/validation (read-only admin), participant list, linkage status
   * - Fire Dept Admin: SAME as firefighters - enrollment, dashboard, public pages (NO admin access)
   */
  private function getTestUsers(): array {
    $test_users = [
      'anonymous' => [
        'uid' => 0,
        'name' => 'Anonymous',
        'label' => 'Anonymous User',
        'expected_access' => [
          'public_pages' => true,
          'enrollment' => false,
          'dashboard' => false,
          'admin' => false,
        ],
      ],
      'drupal_admin' => [
        'uid' => 1,
        'name' => 'admin',
        'label' => 'Drupal Admin (UID 1)',
        'expected_access' => [
          'public_pages' => true,
          'enrollment' => true,
          'dashboard' => true,
          'admin' => true,
        ],
      ],
    ];

    // Query database for actual test users
    $connection = \Drupal::database();
    
    // Get a firefighter user
    $query = $connection->select('users_field_data', 'u')
      ->fields('u', ['uid', 'name'])
      ->condition('u.status', 1)
      ->condition('u.uid', 1, '>')
      ->condition('u.mail', '%@stlouisintegration.com', 'LIKE');
    $query->join('user__roles', 'ur', 'u.uid = ur.entity_id');
    $query->condition('ur.roles_target_id', 'firefighter');
    $query->range(0, 1);
    $result = $query->execute()->fetchAssoc();
    if ($result) {
      $test_users['firefighter'] = [
        'uid' => (int)$result['uid'],
        'name' => $result['name'],
        'label' => 'Firefighter',
        'expected_access' => [
          'public_pages' => true,
          'enrollment' => true,
          'dashboard' => true,
          'admin' => false,
        ],
      ];
    }

    // Get NFR administrator
    $query = $connection->select('users_field_data', 'u')
      ->fields('u', ['uid', 'name'])
      ->condition('u.status', 1)
      ->condition('u.uid', 1, '>')
      ->condition('u.mail', '%@stlouisintegration.com', 'LIKE');
    $query->join('user__roles', 'ur', 'u.uid = ur.entity_id');
    $query->condition('ur.roles_target_id', 'nfr_administrator');
    $query->range(0, 1);
    $result = $query->execute()->fetchAssoc();
    if ($result) {
      $test_users['nfr_admin'] = [
        'uid' => (int)$result['uid'],
        'name' => $result['name'],
        'label' => 'NFR Administrator',
        'expected_access' => [
          'public_pages' => true,
          'enrollment' => true,
          'dashboard' => true,
          'admin' => true,
        ],
      ];
    }

    // Get NFR researcher
    $query = $connection->select('users_field_data', 'u')
      ->fields('u', ['uid', 'name'])
      ->condition('u.status', 1)
      ->condition('u.uid', 1, '>')
      ->condition('u.mail', '%@stlouisintegration.com', 'LIKE');
    $query->join('user__roles', 'ur', 'u.uid = ur.entity_id');
    $query->condition('ur.roles_target_id', 'nfr_researcher');
    $query->range(0, 1);
    $result = $query->execute()->fetchAssoc();
    if ($result) {
      $test_users['nfr_researcher'] = [
        'uid' => (int)$result['uid'],
        'name' => $result['name'],
        'label' => 'NFR Researcher',
        'expected_access' => [
          'public_pages' => true,
          'enrollment' => false,
          'dashboard' => true,
          'admin' => false,
          'reports' => true,
        ],
      ];
    }

    // Get department admin
    $query = $connection->select('users_field_data', 'u')
      ->fields('u', ['uid', 'name'])
      ->condition('u.status', 1)
      ->condition('u.uid', 1, '>')
      ->condition('u.mail', '%@stlouisintegration.com', 'LIKE');
    $query->join('user__roles', 'ur', 'u.uid = ur.entity_id');
    $query->condition('ur.roles_target_id', 'fire_dept_admin');
    $query->range(0, 1);
    $result = $query->execute()->fetchAssoc();
    if ($result) {
      $test_users['dept_admin'] = [
        'uid' => (int)$result['uid'],
        'name' => $result['name'],
        'label' => 'Fire Dept Admin',
        'expected_access' => [
          'public_pages' => true,
          'enrollment' => false,
          'dashboard' => true,
          'admin' => false,
        ],
      ];
    }

    return $test_users;
  }

  /**
   * Determine if a user should have access to a route based on permission.
   * 
   * @param string|null $permission
   *   The required permission for the route.
   * @param bool $requires_login
   *   Whether the route requires login.
   * @param string $user_key
   *   The user key (anonymous, drupal_admin, firefighter, etc.).
   * @param string $route_name
   *   The route name to check for special cases.
   * 
   * @return bool
   *   TRUE if access should be granted, FALSE otherwise.
   */
  private function shouldHaveAccess(?string $permission, bool $requires_login, string $user_key, string $route_name = ''): bool {
    // Anonymous user
    if ($user_key === 'anonymous') {
      // Can only access routes that don't require login and have 'access content' or no permission
      return !$requires_login && ($permission === 'access content' || $permission === null);
    }

    // If route requires login and user is not anonymous, check permission
    if ($requires_login && $user_key === 'anonymous') {
      return FALSE;
    }

    // All authenticated users have 'access content' by default
    if ($permission === 'access content' || $permission === null) {
      return TRUE;
    }

    // Check specific permissions by role
    switch ($user_key) {
      case 'drupal_admin':
        // Drupal admin (UID 1) has all permissions
        return TRUE;

      case 'nfr_admin':
        // NFR Admin has all NFR permissions - full system access
        return TRUE;

      case 'nfr_researcher':
        // Researcher has: view nfr reports, access nfr dashboard
        // Plus special access to data quality/validation pages for research purposes
        if (in_array($permission, ['view nfr reports', 'access nfr dashboard'])) {
          return TRUE;
        }
        // Grant access to admin pages that are read-only/reporting focused
        if ($permission === 'administer nfr') {
          // Check route name for data quality, validation, and reporting routes
          $researcher_routes = [
            'nfr.admin_data_quality',
            'nfr.admin_reports',
            'nfr.admin_linkage', // View linkage status
            'nfr.admin_participants', // View participant list (read-only)
          ];
          if (in_array($route_name, $researcher_routes)) {
            return TRUE;
          }
        }
        return FALSE;

      case 'firefighter':
        // Firefighters have: access nfr dashboard
        return $permission === 'access nfr dashboard';

      case 'dept_admin':
        // Fire Dept Admin has SAME access as firefighters
        // access nfr dashboard only - no admin pages
        return $permission === 'access nfr dashboard';

      default:
        return FALSE;
    }
  }

  /**
   * Build validation dashboard HTML.
   */
  private function buildValidationDashboard(array $routes, array $users): string {
    $html = '<div class="validation-dashboard">';
    
    $html .= '';

    // Test Users Management Section
    $html .= '<div class="test-users-section card card-forseti mb-4">';
    $html .= '<h2 class="text-white">👥 Test Users Management</h2>';
    $html .= '<p>Create test users for different NFR roles. Select a role and specify the number of users to create (1-200).</p>';
    
    // Display current user counts by role
    $role_counts = $this->getUserCountsByRole();
    $html .= '<div class="current-users-summary">';
    $html .= '<h4 class="text-white mb-3">Current Users by Role:</h4>';
    $html .= '<div class="role-counts-grid">';
    
    foreach ($role_counts as $role_info) {
      $html .= '<div class="role-count-item">';
      $html .= '<span class="role-label">' . htmlspecialchars($role_info['label']) . ':</span> ';
      $html .= '<span class="role-count">' . number_format($role_info['count']) . '</span>';
      $html .= '</div>';
    }
    
    $total_role_users = array_sum(array_column($role_counts, 'count'));
    $html .= '<div class="role-count-item total">';
    $html .= '<span class="role-label"><strong>Total:</strong></span> ';
    $html .= '<span class="role-count"><strong>' . number_format($total_role_users) . '</strong></span>';
    $html .= '</div>';
    
    $html .= '</div></div>';
    
    // Create Users Form
    $html .= '<div class="create-users-form card bg-dark border-success p-3 mb-3">';
    $html .= '<h4 class="text-white mb-3">➕ Create New Test Users</h4>';
    $html .= '<div class="row g-3 align-items-end">';
    
    // Role selector
    $html .= '<div class="col-md-5">';
    $html .= '<label for="user-role-select" class="form-label text-white">Select Role:</label>';
    $html .= '<select id="user-role-select" class="form-select">';
    $html .= '<option value="firefighter">Firefighter</option>';
    $html .= '<option value="nfr_administrator">NFR Administrator</option>';
    $html .= '<option value="nfr_researcher">NFR Researcher</option>';
    $html .= '<option value="fire_dept_admin">Fire Department Admin</option>';
    $html .= '</select>';
    $html .= '</div>';
    
    // Number input
    $html .= '<div class="col-md-4">';
    $html .= '<label for="user-count-input" class="form-label text-white">Number of Users:</label>';
    $html .= '<input type="number" id="user-count-input" class="form-control" min="1" max="200" value="5" placeholder="1-200">';
    $html .= '</div>';
    
    // Create button
    $html .= '<div class="col-md-3">';
    $html .= '<button id="create-test-users" class="btn btn-success w-100">';
    $html .= '➕ Create Users</button>';
    $html .= '</div>';
    
    $html .= '</div></div>';
    
    $html .= '<div class="test-controls mt-3">';
    $html .= '<button id="delete-test-users" class="btn btn-danger">';
    $html .= '🗑️ Delete All Test Users</button>';
    $html .= '</div>';
    $html .= '<div id="test-users-results" class="test-results mt-3"></div>';
    $html .= '</div>';

    // Full Enrollment Flow Test Section
    $html .= '<div class="enrollment-flow-test-section card card-forseti mb-4">';
    $html .= '<h2 class="text-white">🚀 Complete Enrollment Flow Tests</h2>';
    $html .= '<p><strong>Tests entire enrollment process (Profile + Questionnaire).</strong> Full end-to-end validation from profile creation through all 9 questionnaire sections.</p>';
    $html .= '<div class="alert alert-info mb-3" style="background: rgba(23, 162, 184, 0.1); border: 1px solid rgba(23, 162, 184, 0.3); color: #fff;">';
    $html .= '<strong>ℹ️ Smart Test User Selection:</strong> Tests automatically find a firefighter test user with <strong>incomplete data</strong>. ';
    $html .= 'Priority: 1) Users with no profile, 2) Partially complete users, 3) Complete users (will overwrite). ';
    $html .= 'The selected user and their status is shown in the test results.';
    $html .= '</div>';
    $html .= '<ul class="text-muted small mb-3">';
    $html .= '<li>Automatically selects test user with incomplete profile/questionnaire</li>';
    $html .= '<li>Creates/updates user profile data</li>';
    $html .= '<li>Submits all 9 questionnaire sections</li>';
    $html .= '<li>Checks system error logs for issues</li>';
    $html .= '<li>Verifies both profile and questionnaire in database</li>';
    $html .= '<li>Tests with different data patterns (random, max values, min values)</li>';
    $html .= '</ul>';
    $html .= '<div class="test-controls">';
    $html .= '<button id="test-full-enrollment" class="btn btn-primary btn-large">';
    $html .= '🎲 Run Full Enrollment Test (Random Data)</button>';
    $html .= '<button id="test-max-values" class="btn btn-success btn-large">';
    $html .= '⬆️ Max Values Test (Yes to All)</button>';
    $html .= '<button id="test-min-values" class="btn btn-info btn-large">';
    $html .= '⬇️ Min Values Test (No to All)</button>';
    $html .= '<button id="test-yes-minimal" class="btn btn-warning btn-large">';
    $html .= '✔️ Yes + Minimal Values Test</button>';
    $html .= '<button id="check-error-logs" class="btn btn-outline-warning">';
    $html .= '⚠️ Check Error Logs (Last 10 Minutes)</button>';
    $html .= '<a href="/admin/nfr/validation/fill-rates" class="btn btn-outline-info">📊 Data Quality Monitoring Dashboard</a>';
    $html .= '</div>';
    $html .= '<div id="enrollment-flow-results" class="test-results mt-3"></div>';
    $html .= '</div>';

    // Submit Questionnaires Section
    $html .= '<div class="submit-questionnaires-section card card-forseti mb-4">';
    
    // Validation header
    $html .= '<div class="validation-header">';
    $html .= '<h1>' . $this->t('NFR Validation Dashboard') . '</h1>';
    $html .= '</div>';
    
    $html .= '<h2 class="text-white">📋 Submit Questionnaires for Firefighters</h2>';
    $html .= '<p>Batch submit questionnaires for multiple firefighter users. Prioritizes incomplete users.</p>';
    $html .= '<div class="row g-3 align-items-end">';
    
    // Number input
    $html .= '<div class="col-md-6">';
    $html .= '<label for="questionnaire-count-input" class="form-label text-white">Number of Firefighters to Process:</label>';
    $html .= '<small class="d-block mb-2">Will submit questionnaires for incomplete firefighters (or fewer if not enough available)</small>';
    $html .= '<input type="number" id="questionnaire-count-input" class="form-control" min="1" max="500" value="10" placeholder="1-500">';
    $html .= '</div>';
    
    // Submit button
    $html .= '<div class="col-md-3">';
    $html .= '<button id="submit-all-firefighters" class="btn btn-primary w-100">';
    $html .= '📋 Submit Questionnaires</button>';
    $html .= '</div>';
    
    $html .= '</div>';
    $html .= '<div id="questionnaire-submit-results" class="test-results mt-3"></div>';
    $html .= '</div>';

    // Role Permission Access Validation Section
    $html .= '<div class="role-permission-validation-section card card-forseti mb-4">';
    $html .= '<h2 class="text-white">🔐 Role Permission Access Validation</h2>';
    $html .= '<p>Test all NFR routes with different user permission levels to ensure proper access control.</p>';

    // Summary stats
    $html .= '<div class="validation-stats">';
    $html .= '<div class="stat-box">';
    $html .= '<div class="stat-value">' . count($routes) . '</div>';
    $html .= '<div class="stat-label">' . $this->t('Total Routes') . '</div>';
    $html .= '</div>';
    $html .= '<div class="stat-box">';
    $html .= '<div class="stat-value">' . count($users) . '</div>';
    $html .= '<div class="stat-label">' . $this->t('User Roles Tested') . '</div>';
    $html .= '</div>';
    $html .= '<div class="stat-box">';
    $html .= '<div class="stat-value">' . (count($routes) * count($users)) . '</div>';
    $html .= '<div class="stat-label">' . $this->t('Total Tests') . '</div>';
    $html .= '</div>';
    $html .= '</div>';

    // Test all button
    $html .= '<div class="validation-actions">';
    $html .= '<button id="test-all-routes" class="btn btn-primary btn-large">' . 
      $this->t('🧪 Run All Tests') . '</button>';
    $html .= '<button id="clear-results" class="btn btn-secondary">' . 
      $this->t('Clear Results') . '</button>';
    $html .= '</div>';

    // Routes table
    $html .= '<div class="routes-table-wrapper">';
    $html .= '<table class="validation-routes-table">';
    $html .= '<thead><tr>';
    $html .= '<th>' . $this->t('Path') . '</th>';
    $html .= '<th>' . $this->t('Permission') . '</th>';
    
    // User columns
    foreach ($users as $user) {
      $html .= '<th class="user-test-column">' . htmlspecialchars($user['label']) . '</th>';
    }
    
    $html .= '</tr></thead><tbody>';
    
    foreach ($routes as $route) {
      $route_id = str_replace('.', '_', $route['name']);
      
      $html .= '<tr data-route="' . htmlspecialchars($route['name']) . '">';
      $html .= '<td><a href="https://forseti.life' . htmlspecialchars($route['path']) . '" target="_blank" rel="noopener"><code>' . htmlspecialchars($route['path']) . '</code></a></td>';
      $html .= '<td>' . ($route['permission'] ? '<code>' . htmlspecialchars($route['permission']) . '</code>' : '-') . '</td>';
      
      // Test cells for each user
      foreach ($users as $user_key => $user) {
        $cell_id = $route_id . '_' . $user_key;
        $should_have_access = $this->shouldHaveAccess($route['permission'], $route['requires_login'], $user_key, $route['name']);
        $expected_icon = $should_have_access ? '✓' : '✗';
        $expected_label = $should_have_access ? 'Expected: 200 OK' : 'Expected: 403 Forbidden';
        
        $html .= '<td class="test-cell" id="cell-' . $cell_id . '" title="' . $expected_label . '">';
        $html .= '<span class="expected-result">' . $expected_icon . '</span> ';
        $html .= '<button class="test-btn btn-mini" ';
        $html .= 'data-route="' . htmlspecialchars($route['name']) . '" ';
        $html .= 'data-path="' . htmlspecialchars($route['path']) . '" ';
        $html .= 'data-uid="' . $user['uid'] . '" ';
        $html .= 'data-user="' . htmlspecialchars($user['name']) . '" ';
        $html .= 'data-expected="' . ($should_have_access ? 'allow' : 'deny') . '">';
        $html .= $this->t('Test') . '</button>';
        $html .= '<div class="test-result" id="result-' . $cell_id . '"></div>';
        $html .= '</td>';
      }
      
      $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    $html .= '</div>';

    // Results summary
    $html .= '<div id="test-summary" class="test-summary" style="display:none;">';
    $html .= '<h3>' . $this->t('Test Results Summary') . '</h3>';
    $html .= '<div id="summary-content"></div>';
    $html .= '</div>';

    $html .= '</div>'; // .role-permission-validation-section

    $html .= '</div>'; // .validation-dashboard

    return $html;
  }

  /**
   * Test route access for specific user.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with test results.
   */
  public function testRoute(Request $request): JsonResponse {
    $route_name = $request->query->get('route');
    $path = $request->query->get('path');
    $uid = (int) $request->query->get('uid');
    $expected = $request->query->get('expected');
    
    if (!$route_name || !$path) {
      return new JsonResponse([
        'success' => false,
        'error' => 'Missing route or path parameter',
      ], 400);
    }

    $result = $this->testRouteAccess($route_name, $path, $uid, $expected);
    
    return new JsonResponse($result);
  }

  /**
   * Test route access for a specific user.
   */
  private function testRouteAccess(string $route_name, string $path, int $uid, ?string $expected = null): array {
    $result = [
      'route' => $route_name,
      'path' => $path,
      'uid' => $uid,
      'status_code' => null,
      'access' => null,
      'error' => null,
      'expected' => $expected,
    ];

    try {
      // Load user
      if ($uid > 0) {
        $user = \Drupal\user\Entity\User::load($uid);
        if (!$user) {
          $result['error'] = 'User not found';
          return $result;
        }
      } else {
        // Anonymous user
        $user = \Drupal\user\Entity\User::getAnonymousUser();
      }

      // Switch to test user
      $this->accountSwitcher->switchTo($user);

      // Try to access the route
      try {
        $url = Url::fromRoute($route_name);
        
        // Check access
        $access = $url->access($user);
        $result['access'] = $access;
        
        if ($access) {
          // Access granted - now try to actually render the route
          try {
            // Replace dynamic parameters with test values
            $test_path = str_replace('{id}', '1', $path);
            
            // Create a subrequest to actually render the page
            $request = Request::create($test_path, 'GET');
            $response = $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST, FALSE);
            
            $status_code = $response->getStatusCode();
            
            if ($status_code === 200) {
              $result['status_code'] = 200;
              $result['status_text'] = 'OK - Page Rendered Successfully';
              $result['class'] = 'success';
            }
            elseif ($status_code === 302 || $status_code === 303) {
              // Redirects are valid responses (e.g., when enrollment incomplete)
              $result['status_code'] = 200;
              $result['status_text'] = 'OK - Redirected';
              $result['class'] = 'success';
            }
            elseif ($status_code === 500) {
              $result['status_code'] = 500;
              $result['status_text'] = 'Error - Page Failed to Render';
              $result['class'] = 'error';
              $result['error'] = 'HTTP 500 - Internal Server Error';
            }
            else {
              $result['status_code'] = $status_code;
              $result['status_text'] = 'HTTP ' . $status_code;
              $result['class'] = 'error';
            }
          }
          catch (\TypeError $e) {
            $result['status_code'] = 500;
            $result['status_text'] = 'TypeError: ' . substr($e->getMessage(), 0, 100);
            $result['class'] = 'error';
            $result['error'] = 'TypeError: ' . $e->getMessage();
          }
          catch (\Exception $e) {
            $result['status_code'] = 500;
            $result['status_text'] = 'Error: ' . substr($e->getMessage(), 0, 100);
            $result['class'] = 'error';
            $result['error'] = $e->getMessage();
          }
        } else {
          $result['status_code'] = 403;
          $result['status_text'] = 'Forbidden - Access Denied';
          $result['class'] = 'forbidden';
        }
      } catch (\Exception $e) {
        $result['status_code'] = 500;
        $result['status_text'] = 'Error: ' . $e->getMessage();
        $result['class'] = 'error';
        $result['error'] = $e->getMessage();
      }

      // Switch back to original user
      $this->accountSwitcher->switchBack();

    } catch (\Exception $e) {
      $result['error'] = $e->getMessage();
      $result['status_code'] = 500;
      $result['status_text'] = 'Error: ' . $e->getMessage();
      $result['class'] = 'error';
    }

    // Check if result matches expected outcome and log if unexpected
    if ($expected !== null) {
      $actual_result = ($result['status_code'] === 200) ? 'allow' : 'deny';
      
      if ($expected !== $actual_result) {
        // Get user info for logging
        $user_name = 'Unknown';
        if ($uid > 0) {
          $user = \Drupal\user\Entity\User::load($uid);
          if ($user) {
            $user_name = $user->getAccountName();
          }
        } else {
          $user_name = 'Anonymous';
        }
        
        // Log unexpected result
        $this->logger->warning('Unexpected validation result: Route @route for user @user (UID: @uid). Expected @expected but got @actual (Status: @status)', [
          '@route' => $route_name,
          '@user' => $user_name,
          '@uid' => $uid,
          '@expected' => $expected,
          '@actual' => $actual_result,
          '@status' => $result['status_code'] ?? 'N/A',
        ]);
      }
    }

    return $result;
  }

  /**
   * Test questionnaire data flow.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with test results.
   */
  public function testQuestionnaireFlow(): JsonResponse {
    $results = [
      'success' => true,
      'steps' => [],
      'errors' => [],
    ];

    try {
      // Get incomplete test user with priority selection
      $test_user = $this->getIncompleteTestUser('firefighter');
      
      if (!$test_user) {
        $results['errors'][] = "No firefighter test user found. Create test users first.";
        $results['success'] = false;
        return new JsonResponse($results);
      }
      
      $test_uid = $test_user['uid'];
      
      // Add test user info to results
      $results['test_user'] = [
        'uid' => $test_user['uid'],
        'username' => $test_user['username'],
        'status' => $test_user['status'],
      ];

      $results['steps'][] = [
        'step' => 'User Check',
        'status' => 'success',
        'message' => "Test user loaded: {$test_user['username']}",
      ];

      // Step 2: Generate test questionnaire data
      $questionnaire_data = $this->generateTestQuestionnaireData($test_uid);
      
      $results['steps'][] = [
        'step' => 'Data Generation',
        'status' => 'success',
        'message' => 'Generated test data for all 9 sections',
      ];

      // Step 3: Submit questionnaire data through actual forms (one for each section)
      $section_results = $this->submitAllQuestionnaireSections($test_uid, $questionnaire_data);
      
      foreach ($section_results as $section_num => $section_result) {
        $results['steps'][] = [
          'step' => "Section {$section_num} Form Submission",
          'status' => $section_result['success'] ? 'success' : 'error',
          'message' => $section_result['message'],
          'errors' => $section_result['errors'] ?? [],
        ];

        if (!$section_result['success']) {
          $results['success'] = false;
          $results['errors'][] = "Section {$section_num} failed: " . $section_result['message'];
        }
      }

      // Step 4: Verify data was saved to database correctly
      $verification = $this->verifyQuestionnaireData($test_uid);
      
      $results['steps'][] = [
        'step' => 'Data Verification',
        'status' => $verification['success'] ? 'success' : 'error',
        'message' => $verification['message'],
        'verified_fields' => $verification['fields'] ?? [],
      ];

      if (!$verification['success']) {
        $results['success'] = false;
      }

    } catch (\Exception $e) {
      $results['success'] = false;
      $results['errors'][] = $e->getMessage();
    }

    return new JsonResponse($results);
  }

  /**
   * Submit questionnaire data through actual form workflow.
   */
  /**
   * Generate test questionnaire data.
   */
  private function generateTestQuestionnaireData(int $uid): array {
    return [
      'demographics' => [
        'race_ethnicity' => ['white', 'hispanic'],
        'race_other' => '',
        'education_level' => 'bachelor',
        'height_inches' => 72,
        'weight_pounds' => 185,
      ],
      'work_history' => [
        'departments' => [
          [
            'department_name' => 'Test City Fire Department',
            'department_fdid' => '12345',
            'department_state' => 'CA',
            'department_city' => 'Test City',
            'start_date' => '2010-06-01',
            'end_date' => '',
            'is_current' => 1,
            'job_titles' => [
              [
                'job_title' => 'Firefighter',
                'employment_type' => 'career',
                'start_date' => '2010-06-01',
                'end_date' => '2015-05-31',
                'responded_to_incidents' => 1,
              ],
              [
                'job_title' => 'Fire Captain',
                'employment_type' => 'career',
                'start_date' => '2015-06-01',
                'end_date' => '',
                'responded_to_incidents' => 1,
              ],
            ],
          ],
        ],
      ],
      'exposure' => [
        'afff_exposure' => 1,
        'afff_years' => 8,
        'afff_frequency' => 'monthly',
        'diesel_exposure' => 1,
        'diesel_years' => 14,
        'major_incidents' => [
          [
            'incident_type' => 'Hazmat',
            'incident_date' => '2015-03-15',
            'exposure_duration' => '6 hours',
          ],
        ],
      ],
      'military' => [
        'military_service' => 1,
        'military_branch' => 'Army',
        'start_date' => '2006-01-01',
        'end_date' => '2010-01-01',
        'military_specialty' => 'Combat Engineer',
        'deployment_locations' => ['Iraq', 'Afghanistan'],
        'exposures' => ['burn_pits', 'diesel'],
      ],
      'other_employment' => [
        'jobs' => [
          [
            'employer' => 'Construction Company',
            'job_title' => 'Carpenter',
            'start_date' => '2004-01-01',
            'end_date' => '2006-01-01',
            'exposures' => ['asbestos', 'wood_dust'],
          ],
        ],
      ],
      'ppe' => [
        'scba_usage' => 'always',
        'glove_usage' => 'always',
        'hood_usage' => 'usually',
        'turnout_cleaning' => 'after_every_fire',
      ],
      'decontamination' => [
        'field_decon' => 1,
        'station_decon' => 1,
        'shower_after_fire' => 'always',
        'gear_drying' => 'dedicated_area',
      ],
      'health' => [
        'cancer_diagnosis' => 0,
        'cancer_details' => [],
        'family_history' => [
          [
            'relation' => 'father',
            'cancer_type' => 'lung',
            'diagnosis_age' => 65,
          ],
        ],
      ],
      'lifestyle' => [
        'smoking_status' => 'never',
        'alcohol_use' => 'occasional',
        'exercise_frequency' => '3-5_per_week',
      ],
    ];
  }

  /**
   * Save test questionnaire data to database.
   */
  /**
   * Verify questionnaire data in database.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with verification results.
   */
  public function verifyQuestionnaireDatabase(): JsonResponse {
    $test_uid = $this->getTestUserByRole('firefighter', 'active');
    if (!$test_uid) {
      return new JsonResponse([
        'success' => false,
        'message' => 'No active firefighter test user found. Create test users first.',
      ], 404);
    }
    $results = $this->verifyQuestionnaireData($test_uid);
    return new JsonResponse($results);
  }

  /**
   * Verify questionnaire data for a user.
   */
  private function verifyQuestionnaireData(int $uid): array {
    try {
      $database = \Drupal::database();

      $record = $database->select('nfr_questionnaire', 'q')
        ->fields('q')
        ->condition('uid', $uid)
        ->execute()
        ->fetchAssoc();

      if (!$record) {
        return [
          'success' => false,
          'message' => "No questionnaire data found for UID: $uid",
        ];
      }

      $verified_fields = [];
      
      // Verify demographics
      if ($record['race_ethnicity']) {
        $race_data = json_decode($record['race_ethnicity'], TRUE);
        $verified_fields['race_ethnicity'] = [
          'status' => 'success',
          'value' => $race_data,
        ];
      }

      if ($record['height_inches']) {
        $verified_fields['height_inches'] = [
          'status' => 'success',
          'value' => $record['height_inches'] . ' inches',
        ];
      }

      if ($record['weight_pounds']) {
        $verified_fields['weight_pounds'] = [
          'status' => 'success',
          'value' => $record['weight_pounds'] . ' lbs',
        ];
      }

      // Verify military service
      if ($record['military_service']) {
        $verified_fields['military_service'] = [
          'status' => 'success',
          'value' => "Branch: {$record['military_branch']}, Years: {$record['military_years']}",
        ];
      }

      // Verify PPE practices and always_used checkboxes
      if (!empty($record['ppe_practices'])) {
        $ppe_data = json_decode($record['ppe_practices'], TRUE);
        $verified_fields['ppe_practices'] = [
          'status' => 'success',
          'value' => count($ppe_data) . ' practices recorded',
        ];
      }
      // Check for new PPE always_used fields
      $always_used_fields = [
        'scba_interior_structural_attack_always_used',
        'scba_exterior_structural_attack_always_used',
        'scba_vehicle_fires_always_used',
        'respirator_brush_veg_fires_always_used',
        'respirator_wildland_suppression_always_used',
        'respirator_fire_investigations_always_used',
        'respirator_wui_fires_always_used',
        'respirator_prescribed_burns_always_used',
      ];
      $always_used_count = 0;
      foreach ($always_used_fields as $field) {
        if (!empty($record[$field])) {
          $always_used_count++;
        }
      }
      if ($always_used_count > 0) {
        $verified_fields['ppe_always_used'] = [
          'status' => 'success',
          'value' => $always_used_count . ' "always done this" practices checked',
        ];
      }

      // Verify decontamination
      if (!empty($record['decon_practices'])) {
        $decon_data = json_decode($record['decon_practices'], TRUE);
        $verified_fields['decon_practices'] = [
          'status' => 'success',
          'value' => count($decon_data) . ' practices recorded',
        ];
      }

      // Verify health info
      $verified_fields['cancer_diagnosis'] = [
        'status' => 'success',
        'value' => $record['cancer_diagnosis'] ? 'Yes' : 'No',
      ];

      // Verify family cancer history from separate table
      $family_cancer_query = $database->select('nfr_family_cancer_history', 'fch')
        ->fields('fch')
        ->condition('uid', $uid)
        ->execute();
      $family_cancers = $family_cancer_query->fetchAll();
      if (!empty($family_cancers)) {
        $verified_fields['family_cancer_history'] = [
          'status' => 'success',
          'value' => count($family_cancers) . ' family members in nfr_family_cancer_history table',
        ];
      }

      // Verify lifestyle
      if ($record['smoking_history']) {
        $smoking_data = json_decode($record['smoking_history'], TRUE);
        $tobacco_types = [];
        if (!empty($smoking_data['smoking_status']) && $smoking_data['smoking_status'] !== 'never') {
          $tobacco_types[] = 'cigarettes';
        }
        if (!empty($smoking_data['cigars_ever_used']) && $smoking_data['cigars_ever_used'] !== 'never') {
          $tobacco_types[] = 'cigars';
        }
        if (!empty($smoking_data['pipes_ever_used']) && $smoking_data['pipes_ever_used'] !== 'never') {
          $tobacco_types[] = 'pipes';
        }
        if (!empty($smoking_data['ecigs_ever_used']) && $smoking_data['ecigs_ever_used'] !== 'never') {
          $tobacco_types[] = 'e-cigarettes';
        }
        if (!empty($smoking_data['smokeless_ever_used']) && $smoking_data['smokeless_ever_used'] !== 'never') {
          $tobacco_types[] = 'smokeless';
        }
        $verified_fields['tobacco_use'] = [
          'status' => 'success',
          'value' => !empty($tobacco_types) ? implode(', ', $tobacco_types) : 'None',
        ];
      }

      $verified_fields['alcohol_use'] = [
        'status' => 'success',
        'value' => $record['alcohol_use'] ?? 'Not specified',
      ];

      // Verify sleep tracking
      $verified_fields['sleep_hours_per_night'] = [
        'status' => 'success',
        'value' => $record['sleep_hours_per_night'] ?? 'Not specified',
      ];
      $verified_fields['sleep_quality'] = [
        'status' => 'success',
        'value' => $record['sleep_quality'] ?? 'Not specified',
      ];
      if ($record['sleep_disorders']) {
        $sleep_disorders = json_decode($record['sleep_disorders'], TRUE);
        $verified_fields['sleep_disorders'] = [
          'status' => 'success',
          'value' => !empty($sleep_disorders) ? implode(', ', array_filter($sleep_disorders)) : 'None',
        ];
      }

      // Verify completion
      $verified_fields['questionnaire_completed'] = [
        'status' => $record['questionnaire_completed'] ? 'success' : 'warning',
        'value' => $record['questionnaire_completed'] ? 'Completed' : 'Incomplete',
      ];

      return [
        'success' => true,
        'message' => 'Verified ' . count($verified_fields) . ' fields in database',
        'fields' => $verified_fields,
        'record' => $record,
      ];

    }
    catch (\Exception $e) {
      return [
        'success' => false,
        'message' => 'Verification error: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Clear test questionnaire data.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with clear results.
   */
  public function clearTestData(): JsonResponse {
    try {
      $test_uid = $this->getTestUserByRole('firefighter', 'active');
      if (!$test_uid) {
        return new JsonResponse([
          'success' => false,
          'message' => 'No active firefighter test user found.',
        ], 404);
      }
      $database = \Drupal::database();

      $deleted = $database->delete('nfr_questionnaire')
        ->condition('uid', $test_uid)
        ->execute();

      return new JsonResponse([
        'success' => true,
        'message' => "Cleared test data for UID: $test_uid",
        'rows_deleted' => $deleted,
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => false,
        'message' => 'Error clearing data: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Test full enrollment flow with random data using actual form submissions.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with test results.
   */
  public function testFullEnrollmentFlow(): JsonResponse {
    $test_start_time = time();
    $results = [
      'success' => true,
      'steps' => [],
      'errors' => [],
      'warnings' => [],
    ];

    try {
      // Find an incomplete test user (or least complete user)
      $test_user = $this->getIncompleteTestUser('firefighter');
      if (!$test_user) {
        return new JsonResponse([
          'success' => false,
          'message' => 'No firefighter test user found. Create test users first.',
          'steps' => [],
          'errors' => ['No test user available'],
        ]);
      }
      
      $test_uid = $test_user['uid'];
      $results['test_user'] = [
        'uid' => $test_uid,
        'username' => $test_user['username'],
        'status' => $test_user['status'],
      ];
      
      // Step 1: Generate and submit consent
      $consent_data = $this->generateConsentData();
      $results['steps'][] = [
        'step' => 'Consent Data Generation',
        'status' => 'success',
        'message' => 'Generated consent data',
      ];
      
      $consent_result = $this->submitConsentForm($test_uid, $consent_data);
      $results['steps'][] = [
        'step' => 'Consent Form Submission',
        'status' => $consent_result['success'] ? 'success' : 'error',
        'message' => $consent_result['message'],
        'errors' => $consent_result['errors'] ?? [],
      ];
      
      if (!$consent_result['success']) {
        $results['success'] = false;
        $results['errors'][] = 'Consent form submission failed: ' . $consent_result['message'];
      }
      
      // Step 2: Generate random user profile data
      $profile_data = $this->generateRandomProfileData();
      $results['steps'][] = [
        'step' => 'Profile Data Generation',
        'status' => 'success',
        'message' => 'Generated random profile data',
        'data' => $profile_data,
      ];

      // Step 3: Submit profile through actual form
      $profile_result = $this->submitProfileForm($test_uid, $profile_data);
      $results['steps'][] = [
        'step' => 'Profile Form Submission',
        'status' => $profile_result['success'] ? 'success' : 'error',
        'message' => $profile_result['message'],
        'errors' => $profile_result['errors'] ?? [],
      ];

      if (!$profile_result['success']) {
        $results['success'] = false;
        $results['errors'][] = 'Profile form submission failed: ' . $profile_result['message'];
      }

      // Step 4: Generate random questionnaire data
      $questionnaire_data = $this->generateRandomQuestionnaireData($test_uid);
      $results['steps'][] = [
        'step' => 'Questionnaire Data Generation',
        'status' => 'success',
        'message' => 'Generated random questionnaire data for all 9 sections',
      ];

      // Step 5: Submit all 9 questionnaire sections through actual forms
      $section_results = $this->submitAllQuestionnaireSections($test_uid, $questionnaire_data);
      
      foreach ($section_results as $section_num => $section_result) {
        $results['steps'][] = [
          'step' => "Section {$section_num} Form Submission",
          'status' => $section_result['success'] ? 'success' : 'error',
          'message' => $section_result['message'],
          'errors' => $section_result['errors'] ?? [],
        ];

        if (!$section_result['success']) {
          $results['success'] = false;
          $results['errors'][] = "Section {$section_num} failed: " . $section_result['message'];
        }
      }

      // Step 6: Check for errors in dblog since test started
      $log_check = $this->checkErrorLogs($test_start_time);
      $results['steps'][] = [
        'step' => 'Error Log Check',
        'status' => $log_check['has_errors'] ? 'warning' : 'success',
        'message' => $log_check['message'],
        'error_count' => $log_check['error_count'],
        'recent_errors' => $log_check['recent_errors'] ?? [],
      ];

      if ($log_check['has_errors']) {
        $results['warnings'][] = 'Found ' . $log_check['error_count'] . ' recent errors in system logs';
      }

      // Step 6: Verify both profile and questionnaire data
      $profile_verify = $this->verifyProfileData($test_uid);
      $questionnaire_verify = $this->verifyQuestionnaireData($test_uid);
      
      $results['steps'][] = [
        'step' => 'Data Verification',
        'status' => ($profile_verify['success'] && $questionnaire_verify['success']) ? 'success' : 'error',
        'message' => 'Profile: ' . $profile_verify['message'] . ' | Questionnaire: ' . $questionnaire_verify['message'],
      ];

    } catch (\Exception $e) {
      $results['success'] = false;
      $results['errors'][] = $e->getMessage();
    }

    return new JsonResponse($results);
  }

  /**
   * Create test users for NFR roles.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with created users.
   */
  public function createTestUsers(): JsonResponse {
    $results = [
      'success' => true,
      'users_created' => [],
      'errors' => [],
    ];

    try {
      // Get parameters from request
      $request = \Drupal::request();
      $role_id = $request->query->get('role', 'firefighter');
      $count = (int) $request->query->get('count', 5);
      
      // Validate parameters
      $valid_roles = [
        'firefighter' => 'Firefighter',
        'nfr_administrator' => 'NFR Administrator',
        'nfr_researcher' => 'NFR Researcher',
        'fire_dept_admin' => 'Fire Department Admin',
      ];
      
      if (!isset($valid_roles[$role_id])) {
        $results['success'] = false;
        $results['errors'][] = "Invalid role: {$role_id}";
        return new JsonResponse($results);
      }
      
      if ($count < 1 || $count > 200) {
        $results['success'] = false;
        $results['errors'][] = "Invalid count: {$count}. Must be between 1 and 200.";
        return new JsonResponse($results);
      }
      
      $role_label = $valid_roles[$role_id];
      $base_username = strtolower(str_replace(' ', '_', $role_label));
      
      // Create the specified number of users
      for ($i = 1; $i <= $count; $i++) {
        $user = $this->createUser($base_username, $role_id, $role_label);
        $results['users_created'][] = [
          'uid' => $user->id(),
          'username' => $user->getAccountName(),
          'role' => $role_label,
          'email' => $user->getEmail(),
        ];
      }

      $results['total_created'] = count($results['users_created']);
      $results['summary'] = [
        'role' => $role_label,
        'count' => $count,
        'total' => count($results['users_created']),
      ];

    } catch (\Exception $e) {
      $results['success'] = false;
      $results['errors'][] = $e->getMessage();
    }

    return new JsonResponse($results);
  }

  /**
   * Get current user counts by role for AJAX refresh.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with role counts.
   */
  public function getUserCounts(): JsonResponse {
    try {
      $role_counts = $this->getUserCountsByRole();
      
      return new JsonResponse([
        'success' => true,
        'role_counts' => $role_counts,
      ]);
    } catch (\Exception $e) {
      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Create or update a validation test user (Drupal best practice - no forced UIDs).
   */
  private function createValidationUser(int $target_uid, string $username, string $role_id, string $role_label): \Drupal\user\Entity\User {
    // Check if user already exists by username (proper Drupal practice)
    $existing_by_name = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => $username]);

    if (!empty($existing_by_name)) {
      $user = reset($existing_by_name);
      // Update role if needed
      if (!$user->hasRole($role_id)) {
        $user->addRole($role_id);
        $user->save();
      }
      return $user;
    }

    // Generate name parts
    $name_map = [
      'firefighter_active' => ['John', 'Smith'],
      'firefighter_retired' => ['Jane', 'Doe'],
      'nfr_admin' => ['Admin', 'User'],
      'nfr_researcher' => ['Research', 'Analyst'],
      'dept_admin' => ['Fire', 'Chief'],
    ];

    $names = $name_map[$username] ?? ['Test', 'User'];

    // Create user using Drupal entity system (let Drupal assign UID)
    $user = \Drupal\user\Entity\User::create([
      'name' => $username,
      'mail' => $username . '@stlouisintegration.com',
      'pass' => 'TestPassword123!',
      'status' => 1,
      'field_first_name' => $names[0],
      'field_last_name' => $names[1],
    ]);

    $user->addRole($role_id);
    $user->save();

    return $user;
  }

  /**
   * Create a single test user with unique username.
   */
  private function createUser(string $base_username, string $role_id, string $role_label): \Drupal\user\Entity\User {
    // Add "test" to base username to identify as test user
    $base_username = $base_username . '_test';
    
    // Find next available username by checking for existing users
    $username = $base_username;
    $counter = 1;
    
    while (TRUE) {
      $existing = \Drupal::entityTypeManager()
        ->getStorage('user')
        ->loadByProperties(['name' => $username]);
      
      if (empty($existing)) {
        break; // Username is available
      }
      
      // Try next number
      $counter++;
      $username = $base_username . '_' . $counter;
      
      // Safety limit to prevent infinite loop
      if ($counter > 10000) {
        throw new \Exception("Could not find available username for $base_username");
      }
    }

    // Generate realistic name
    $first_names = ['John', 'Jane', 'Michael', 'Sarah', 'David', 'Emily', 'Robert', 'Lisa', 'James', 'Mary', 
                    'William', 'Patricia', 'Thomas', 'Jennifer', 'Charles', 'Linda', 'Daniel', 'Elizabeth'];
    $last_names = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 
                   'Martinez', 'Hernandez', 'Lopez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson'];

    $first_name = $first_names[array_rand($first_names)];
    $last_name = $last_names[array_rand($last_names)];

    // Create user
    $user = \Drupal\user\Entity\User::create([
      'name' => $username,
      'mail' => $username . '@stlouisintegration.com',
      'pass' => 'TestPassword123!',
      'status' => 1,
      'field_first_name' => $first_name,
      'field_last_name' => $last_name,
    ]);

    $user->addRole($role_id);
    $user->save();

    return $user;
  }

  /**
   * Delete all test users.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with deletion results.
   */
  public function deleteTestUsers(): JsonResponse {
    $results = [
      'success' => true,
      'users_deleted' => 0,
      'profiles_deleted' => 0,
      'questionnaires_deleted' => 0,
      'errors' => [],
    ];

    try {
      // SAFETY CHECK: Only delete users with @stlouisintegration.com email domain AND "test" in username
      $database = \Drupal::database();
      $uids = $database->query("
        SELECT uid FROM {users_field_data} 
        WHERE uid > 1
        AND status = 1
        AND mail LIKE '%@stlouisintegration.com'
        AND name LIKE '%test%'
      ")->fetchCol();

      foreach ($uids as $uid) {
        $uid = (int) $uid;
        $user = \Drupal\user\Entity\User::load($uid);
        
        if (!$user) {
          continue;
        }
        
        // Double-check email domain AND username for safety
        $email = $user->getEmail();
        $username = strtolower($user->getAccountName());
        
        if (!str_ends_with($email, '@stlouisintegration.com')) {
          $results['errors'][] = "Skipped user {$user->getAccountName()} - email doesn't match test domain";
          continue;
        }
        
        // Additional safety: must have "test" in username
        if (!str_contains($username, 'test')) {
          $results['errors'][] = "Skipped user {$user->getAccountName()} - username doesn't contain 'test'";
          continue;
        }
        
        // Delete NFR profile data
        $profile_deleted = $database->delete('nfr_user_profile')
          ->condition('uid', $uid)
          ->execute();
        $results['profiles_deleted'] += $profile_deleted;
        
        // Delete questionnaire data
        $questionnaire_deleted = $database->delete('nfr_questionnaire')
          ->condition('uid', $uid)
          ->execute();
        $results['questionnaires_deleted'] += $questionnaire_deleted;
        
        // Delete work history (and cascade to job_titles and incident_frequency via their foreign keys)
        $work_history_ids = $database->query(
          "SELECT id FROM {nfr_work_history} WHERE uid = :uid",
          [':uid' => $uid]
        )->fetchCol();
        
        foreach ($work_history_ids as $work_history_id) {
          // First, get job title IDs BEFORE deleting them
          $job_title_ids = $database->query(
            "SELECT id FROM {nfr_job_titles} WHERE work_history_id = :work_history_id",
            [':work_history_id' => $work_history_id]
          )->fetchCol();
          
          // Delete incident frequencies for job titles under this work history
          foreach ($job_title_ids as $job_title_id) {
            $database->delete('nfr_incident_frequency')
              ->condition('job_title_id', $job_title_id)
              ->execute();
          }
          
          // Now delete job titles for this work history
          $database->delete('nfr_job_titles')
            ->condition('work_history_id', $work_history_id)
            ->execute();
        }
        
        // Now delete work history itself
        $database->delete('nfr_work_history')
          ->condition('uid', $uid)
          ->execute();
        
        // Delete major incidents
        $database->delete('nfr_major_incidents')
          ->condition('uid', $uid)
          ->execute();
        
        // Delete other employment
        $database->delete('nfr_other_employment')
          ->condition('uid', $uid)
          ->execute();
        
        // Delete cancer diagnoses
        $database->delete('nfr_cancer_diagnoses')
          ->condition('uid', $uid)
          ->execute();
        
        // Delete family cancer history
        $database->delete('nfr_family_cancer_history')
          ->condition('uid', $uid)
          ->execute();
        
        // Delete consent records
        $database->delete('nfr_consent')
          ->condition('uid', $uid)
          ->execute();
        
        // Delete section completion
        $database->delete('nfr_section_completion')
          ->condition('uid', $uid)
          ->execute();
        
        // Delete follow-up surveys
        $database->delete('nfr_follow_up_surveys')
          ->condition('uid', $uid)
          ->execute();
        
        // Finally, delete the user
        $user->delete();
        $results['users_deleted']++;
      }
      
      // Clean up any orphaned records (job_titles without work_history, incident_frequency without job_titles)
      // First find orphaned incident_frequency IDs
      $orphaned_freq_ids = $database->query("
        SELECT freq.id FROM {nfr_incident_frequency} freq 
        LEFT JOIN {nfr_job_titles} jt ON freq.job_title_id = jt.id 
        WHERE jt.id IS NULL
      ")->fetchCol();
      
      $orphaned_incident_freq = 0;
      if (!empty($orphaned_freq_ids)) {
        $orphaned_incident_freq = $database->delete('nfr_incident_frequency')
          ->condition('id', $orphaned_freq_ids, 'IN')
          ->execute();
      }
      
      // Find orphaned job_titles IDs
      $orphaned_jt_ids = $database->query("
        SELECT jt.id FROM {nfr_job_titles} jt 
        LEFT JOIN {nfr_work_history} wh ON jt.work_history_id = wh.id 
        WHERE wh.id IS NULL
      ")->fetchCol();
      
      $orphaned_job_titles = 0;
      if (!empty($orphaned_jt_ids)) {
        $orphaned_job_titles = $database->delete('nfr_job_titles')
          ->condition('id', $orphaned_jt_ids, 'IN')
          ->execute();
      }
      
      if ($orphaned_job_titles > 0 || $orphaned_incident_freq > 0) {
        $results['message'] = "Deleted {$results['users_deleted']} test users, {$results['profiles_deleted']} profiles, {$results['questionnaires_deleted']} questionnaires. Cleaned up {$orphaned_job_titles} orphaned job titles and {$orphaned_incident_freq} orphaned incident frequencies.";
      } else {
        $results['message'] = "Deleted {$results['users_deleted']} test users, {$results['profiles_deleted']} profiles, and {$results['questionnaires_deleted']} questionnaires.";
      }

    } catch (\Exception $e) {
      $results['success'] = false;
      $results['errors'][] = $e->getMessage();
    }

    return new JsonResponse($results);
  }

  /**
   * Submit questionnaires for all firefighter users.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with submission results.
   */
  public function submitAllFirefighterQuestionnaires(): JsonResponse {
    $results = [
      'success' => true,
      'total_firefighters' => 0,
      'incomplete_found' => 0,
      'processed' => 0,
      'successful_submissions' => 0,
      'failed_submissions' => 0,
      'user_results' => [],
      'errors' => [],
    ];

    try {
      // Get count parameter from request
      $request = \Drupal::request();
      $requested_count = (int) $request->query->get('count', 10);
      
      // Validate count
      if ($requested_count < 1 || $requested_count > 500) {
        $results['success'] = false;
        $results['errors'][] = "Invalid count: {$requested_count}. Must be between 1 and 500.";
        return new JsonResponse($results);
      }
      
      // Get all firefighter test users
      $database = \Drupal::database();
      $firefighter_uids = $database->query("
        SELECT DISTINCT u.uid 
        FROM {users_field_data} u
        JOIN {user__roles} ur ON u.uid = ur.entity_id
        WHERE ur.roles_target_id = 'firefighter'
        AND u.uid > 1
        AND u.mail LIKE '%@stlouisintegration.com'
        ORDER BY u.uid
      ")->fetchCol();

      $results['total_firefighters'] = count($firefighter_uids);
      
      // Find incomplete firefighters (those with <9 completed sections)
      $incomplete_uids = [];
      foreach ($firefighter_uids as $uid) {
        $uid = (int) $uid;
        
        // Check if user has fewer than 9 completed sections
        $completed_sections = $database->query("
          SELECT COUNT(*) 
          FROM {nfr_section_completion}
          WHERE uid = :uid AND completed = 1
        ", [':uid' => $uid])->fetchField();
        
        if ($completed_sections < 9) {
          $incomplete_uids[] = $uid;
          if (count($incomplete_uids) >= $requested_count) {
            break; // Stop when we have enough
          }
        }
      }
      
      $results['incomplete_found'] = count($incomplete_uids);
      $results['processed'] = count($incomplete_uids);
      
      // Process only the incomplete users (up to requested count)
      foreach ($incomplete_uids as $uid) {
        $user = \Drupal\user\Entity\User::load($uid);
        if (!$user) {
          continue;
        }

        $username = $user->getAccountName();
        
        try {
          // Generate and submit consent
          $consent_data = $this->generateConsentData();
          $consent_result = $this->submitConsentForm($uid, $consent_data);
          
          if (!$consent_result['success']) {
            $results['failed_submissions']++;
            $results['user_results'][] = [
              'uid' => $uid,
              'username' => $username,
              'success' => false,
              'error' => 'Consent submission failed: ' . $consent_result['message'],
            ];
            continue;
          }
          
          // Generate random profile data
          $profile_data = $this->generateRandomProfileData();
          
          // Submit profile
          $profile_result = $this->submitProfileForm($uid, $profile_data);
          
          if (!$profile_result['success']) {
            $results['failed_submissions']++;
            $results['user_results'][] = [
              'uid' => $uid,
              'username' => $username,
              'success' => false,
              'error' => 'Profile submission failed: ' . $profile_result['message'],
            ];
            continue;
          }

          // Generate random questionnaire data
          $questionnaire_data = $this->generateRandomQuestionnaireData($uid);
          
          // Submit all sections
          $section_results = $this->submitAllQuestionnaireSections($uid, $questionnaire_data);
          
          // Check if all sections succeeded
          $all_sections_passed = true;
          $failed_sections = [];
          foreach ($section_results as $section_num => $section_result) {
            if (!$section_result['success']) {
              $all_sections_passed = false;
              $failed_sections[] = "Section $section_num: " . $section_result['message'];
            }
          }

          if ($all_sections_passed) {
            $results['successful_submissions']++;
            $results['user_results'][] = [
              'uid' => $uid,
              'username' => $username,
              'success' => true,
              'sections_completed' => 9,
            ];
          } else {
            $results['failed_submissions']++;
            $results['user_results'][] = [
              'uid' => $uid,
              'username' => $username,
              'success' => false,
              'error' => implode('; ', $failed_sections),
            ];
          }

        } catch (\Exception $e) {
          $results['failed_submissions']++;
          $results['user_results'][] = [
            'uid' => $uid,
            'username' => $username,
            'success' => false,
            'error' => $e->getMessage(),
          ];
        }
      }

      $results['success'] = $results['failed_submissions'] === 0;
      $results['success_rate'] = $results['processed'] > 0 
        ? round(($results['successful_submissions'] / $results['processed']) * 100, 2)
        : 0;

    } catch (\Exception $e) {
      $results['success'] = false;
      $results['errors'][] = $e->getMessage();
    }

    return new JsonResponse($results);
  }

  /**
   * Test enrollment with maximum values.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with test results.
   */
  public function testMaxValuesFlow(): JsonResponse {
    return $this->runEnrollmentFlowTest('max', 'Maximum Values Test (Yes to everything, max values)');
  }

  /**
   * Test enrollment with minimum values.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with test results.
   */
  public function testMinValuesFlow(): JsonResponse {
    return $this->runEnrollmentFlowTest('min', 'Minimum Values Test (No to everything, min values)');
  }

  /**
   * Test enrollment with yes answers but minimal values.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with test results.
   */
  public function testYesMinimalFlow(): JsonResponse {
    return $this->runEnrollmentFlowTest('yes_minimal', 'Yes + Minimal Values Test');
  }

  /**
   * Run enrollment flow test with specific data type.
   */
  private function runEnrollmentFlowTest(string $dataType, string $testName): JsonResponse {
    $results = [
      'success' => true,
      'test_type' => $testName,
      'steps' => [],
      'errors' => [],
      'warnings' => [],
    ];

    try {
      // Find an incomplete test user (or least complete user)
      $test_user = $this->getIncompleteTestUser('firefighter');
      if (!$test_user) {
        return new JsonResponse([
          'success' => false,
          'message' => 'No firefighter test user found. Create test users first.',
          'test_type' => $testName,
          'steps' => [],
          'errors' => ['No test user available'],
        ]);
      }
      
      $test_uid = $test_user['uid'];
      $results['test_user'] = [
        'uid' => $test_uid,
        'username' => $test_user['username'],
        'status' => $test_user['status'],
      ];
      
      // Generate profile data based on type
      $profile_data = $this->generateProfileData($dataType);
      $results['steps'][] = [
        'step' => 'Profile Data Generation',
        'status' => 'success',
        'message' => "Generated {$dataType} profile data",
        'data' => $profile_data,
      ];

      // Submit profile
      $profile_result = $this->submitProfileForm($test_uid, $profile_data);
      $results['steps'][] = [
        'step' => 'Profile Form Submission',
        'status' => $profile_result['success'] ? 'success' : 'error',
        'message' => $profile_result['message'],
        'errors' => $profile_result['errors'] ?? [],
      ];

      if (!$profile_result['success']) {
        $results['success'] = false;
        $results['errors'][] = 'Profile form submission failed: ' . $profile_result['message'];
      }

      // Generate questionnaire data
      $questionnaire_data = $this->generateQuestionnaireData($test_uid, $dataType);
      $results['steps'][] = [
        'step' => 'Questionnaire Data Generation',
        'status' => 'success',
        'message' => "Generated {$dataType} questionnaire data for all 9 sections",
      ];

      // Submit all sections
      $section_results = $this->submitAllQuestionnaireSections($test_uid, $questionnaire_data);
      
      foreach ($section_results as $section_num => $section_result) {
        $results['steps'][] = [
          'step' => "Section {$section_num} Form Submission",
          'status' => $section_result['success'] ? 'success' : 'error',
          'message' => $section_result['message'],
          'errors' => $section_result['errors'] ?? [],
        ];

        if (!$section_result['success']) {
          $results['success'] = false;
          $results['errors'][] = "Section {$section_num} failed: " . $section_result['message'];
        }
      }

      // Data verification
      $profile_verify = $this->verifyProfileData($test_uid);
      $questionnaire_verify = $this->verifyQuestionnaireData($test_uid);
      
      $results['steps'][] = [
        'step' => 'Data Verification',
        'status' => ($profile_verify['success'] && $questionnaire_verify['success']) ? 'success' : 'error',
        'message' => 'Profile: ' . $profile_verify['message'] . ' | Questionnaire: ' . $questionnaire_verify['message'],
      ];

    } catch (\Exception $e) {
      $results['success'] = false;
      $results['errors'][] = $e->getMessage();
    }

    return new JsonResponse($results);
  }

  /**
   * Generate profile data based on type.
   */
  private function generateProfileData(string $type): array {
    $base_data = [
      'first_name' => 'Test',
      'middle_name' => 'T',
      'last_name' => 'User',
      'date_of_birth' => '1980-01-15',
      'sex' => 'male',
      'ssn_last_4' => '1234',
      'country_of_birth' => 'USA',
      'state_of_birth' => 'CA',
      'city_of_birth' => 'TestCity',
      'address_line1' => '123 Test St',
      'city' => 'TestCity',
      'state' => 'CA',
      'zip_code' => '12345',
      'mobile_phone' => '(555) 123-4567',
      'current_work_status' => 'active',
    ];

    if ($type === 'max') {
      $base_data['date_of_birth'] = '1960-01-01'; // Oldest allowed
    }
    elseif ($type === 'min') {
      $base_data['date_of_birth'] = '2005-01-01'; // Youngest allowed
      $base_data['current_work_status'] = 'retired';
    }

    return $base_data;
  }

  /**
   * Generate questionnaire data based on type.
   */
  private function generateQuestionnaireData(int $uid, string $type): array {
    if ($type === 'max') {
      return $this->generateMaxValuesData();
    }
    elseif ($type === 'min') {
      return $this->generateMinValuesData();
    }
    elseif ($type === 'yes_minimal') {
      return $this->generateYesMinimalData();
    }
    else {
      return $this->generateRandomQuestionnaireData($uid);
    }
  }

  /**
   * Generate maximum values data (yes to everything, max values).
   */
  private function generateMaxValuesData(): array {
    return [
      'demographics' => [
        'race_ethnicity' => ['white', 'black', 'asian', 'hispanic', 'american_indian'],
        'education_level' => 'college_graduate',
        'marital_status' => 'married',
        'height_inches' => 78,
        'weight_pounds' => 260,
      ],
      'work_history' => [
        'departments' => [
          [
            'department_name' => 'Maximum Test Fire Department',
            'fdid' => '99999',
            'state' => 'CA',
            'city' => 'Los Angeles',
            'start_date' => '1980-01-01',
            'currently_employed' => TRUE,
            'end_date' => '',
            'num_jobs' => 1,
            'jobs' => [
              [
                'title' => 'Fire Chief',
                'employment_type' => 'career',
                'responded_incidents' => 'yes',
                'incident_types' => [
                  'structure_residential' => 'more_than_50',
                  'structure_commercial' => 'more_than_50',
                  'vehicle' => '21_50',
                  'wildland' => '6_20',
                  'hazmat' => '21_50',
                  'medical_ems' => 'more_than_50',
                  'technical_rescue' => '6_20',
                  'rubbish_dumpster' => '21_50',
                ],
              ],
            ],
          ],
        ],
      ],
      'exposure' => [
        'afff_used' => 'yes',
        'afff_years' => 20,
        'afff_frequency' => 'weekly',
        'diesel_exhaust' => 'regularly',
        'diesel_years' => 25,
        'major_incidents' => 'yes',
      ],
      'military' => [
        'served' => 'yes',
        'branch' => 'marines',
        'start_date' => '1978-01-01',
        'end_date' => '1980-01-01',
        'military_specialty' => 'Infantry',
        'deployment_locations' => [],
        'exposures' => [],
      ],
      'other_employment' => [
        'had_other_jobs' => 'yes',
        'jobs' => [
          [
            'occupation' => 'Construction Worker',
            'industry' => 'construction',
            'start_year' => 1975,
            'end_year' => 1980,
            'exposures' => 'asbestos,silica,diesel',
            'exposures_other' => 'Heavy machinery exhaust',
          ],
          [
            'occupation' => 'Paramedic',
            'industry' => 'healthcare',
            'start_year' => 1981,
            'end_year' => 1985,
            'exposures' => 'chemicals,radiation',
            'exposures_other' => '',
          ],
        ],
      ],
      'ppe' => [
        'scba_usage' => 'always',
        'glove_usage' => 'always',
        'hood_usage' => 'always',
        'turnout_cleaning' => 'after_every_fire',
      ],
      'decontamination' => [
        'field_decon' => 1,
        'station_decon' => 1,
        'shower_after_fire' => 'always',
        'gear_drying' => 'dedicated_area',
        'department_had_sops' => 'yes',
        'sops_year_implemented' => 2010,
      ],
      'health' => [
        'cancer_diagnosis' => 0,
        'cancer_details' => [],
        'family_history' => [
          [
            'relationship' => 'mother',
            'cancer_type' => 'Breast Cancer',
            'age_at_diagnosis' => 55,
          ],
          [
            'relationship' => 'father',
            'cancer_type' => 'Lung Cancer',
            'age_at_diagnosis' => 68,
          ],
        ],
      ],
      'lifestyle' => [
        'smoking_status' => 'current',
        'smoking_age_started' => 18,
        'smoking_age_stopped' => '',
        'cigarettes_per_day' => 'more_than_two_packs',
        'cigars_ever_used' => 'current',
        'cigars_age_started' => 25,
        'cigars_age_stopped' => '',
        'pipes_ever_used' => 'former',
        'pipes_age_started' => 20,
        'pipes_age_stopped' => 30,
        'ecigs_ever_used' => 'current',
        'ecigs_age_started' => 35,
        'ecigs_age_stopped' => '',
        'smokeless_ever_used' => 'former',
        'smokeless_age_started' => 16,
        'smokeless_age_stopped' => 22,
        'alcohol_frequency' => '5_plus_per_week',
        'physical_activity_days' => '7',
        'sleep_hours_per_night' => 8,
        'sleep_quality' => 'excellent',
        'sleep_disorders' => ['none' => 'none'],
      ],
    ];
  }

  /**
   * Generate minimum values data (no to everything, min values).
   */
  private function generateMinValuesData(): array {
    return [
      'demographics' => [
        'race_ethnicity' => ['white'],
        'education_level' => 'hs_ged',
        'marital_status' => 'never_married',
        'height_inches' => 60,
        'weight_pounds' => 140,
      ],
      'work_history' => [
        'departments' => [
          [
            'department_name' => 'Minimal Test Fire Department',
            'fdid' => '10000',
            'state' => 'CA',
            'city' => 'TestCity',
            'start_date' => '2020-01-01',
            'currently_employed' => TRUE,
            'end_date' => '',
            'num_jobs' => 1,
            'jobs' => [
              [
                'title' => 'Firefighter',
                'employment_type' => 'volunteer',
                'responded_incidents' => 'yes',
                'incident_types' => [
                  'structure_residential' => 'less_than_1',
                ],
              ],
            ],
          ],
        ],
      ],
      'exposure' => [
        'afff_used' => 'no',
        'afff_years' => 0,
        'afff_frequency' => 'never',
        'diesel_exhaust' => 'never',
        'diesel_years' => 0,
        'major_incidents' => 'no',
      ],
      'military' => [
        'served' => 'no',
        'branch' => '',
        'start_date' => '',
        'end_date' => '',
        'military_specialty' => '',
        'deployment_locations' => [],
        'exposures' => [],
      ],
      'other_employment' => [
        'had_other_jobs' => 'yes',
        'jobs' => [
          [
            'occupation' => 'Retail Worker',
            'industry' => 'retail',
            'start_year' => 2018,
            'end_year' => 2020,
            'exposures' => '',
            'exposures_other' => '',
          ],
        ],
      ],
      'ppe' => [
        'scba_usage' => 'rarely',
        'glove_usage' => 'sometimes',
        'hood_usage' => 'sometimes',
        'turnout_cleaning' => 'monthly',
      ],
      'decontamination' => [
        'field_decon' => 0,
        'station_decon' => 0,
        'shower_after_fire' => 'sometimes',
        'gear_drying' => 'outside',
        'department_had_sops' => 'no',
      ],
      'health' => [
        'cancer_diagnosis' => 0,
        'cancer_details' => [],
        'family_history' => [],
      ],
      'lifestyle' => [
        'smoking_status' => 'never',
        'smoking_age_started' => '',
        'smoking_age_stopped' => '',
        'cigarettes_per_day' => '',
        'cigars_ever_used' => 'never',
        'cigars_age_started' => '',
        'cigars_age_stopped' => '',
        'pipes_ever_used' => 'never',
        'pipes_age_started' => '',
        'pipes_age_stopped' => '',
        'ecigs_ever_used' => 'never',
        'ecigs_age_started' => '',
        'ecigs_age_stopped' => '',
        'smokeless_ever_used' => 'never',
        'smokeless_age_started' => '',
        'smokeless_age_stopped' => '',
        'alcohol_frequency' => 'never',
        'physical_activity_days' => '0',
        'sleep_hours_per_night' => 4,
        'sleep_quality' => 'poor',
        'sleep_disorders' => ['insomnia' => 'insomnia', 'sleep_apnea' => 'sleep_apnea'],
      ],
    ];
  }

  /**
   * Generate yes + minimal values data.
   */
  private function generateYesMinimalData(): array {
    return [
      'demographics' => [
        'race_ethnicity' => ['white'],
        'education_level' => 'hs_ged',
        'marital_status' => 'never_married',
        'height_inches' => 60,
        'weight_pounds' => 140,
      ],
      'work_history' => [
        'departments' => [
          [
            'department_name' => 'Yes Minimal Fire Department',
            'fdid' => '50000',
            'state' => 'CA',
            'city' => 'TestCity',
            'start_date' => '2020-01-01',
            'currently_employed' => TRUE,
            'end_date' => '',
            'num_jobs' => 1,
            'jobs' => [
              [
                'title' => 'Firefighter',
                'employment_type' => 'career',
                'responded_incidents' => 'yes',
                'incident_types' => [
                  'structure_residential' => 'less_than_1',
                  'medical_ems' => '6_20',
                ],
              ],
            ],
          ],
        ],
      ],
      'exposure' => [
        'afff_used' => 'yes',
        'afff_years' => 1,
        'afff_frequency' => 'rarely',
        'diesel_exhaust' => 'rarely',
        'diesel_years' => 1,
        'major_incidents' => 'yes',
      ],
      'military' => [
        'served' => 'yes',
        'branch' => 'army',
        'start_date' => '2018-01-01',
        'end_date' => '2020-01-01',
        'military_specialty' => 'Infantry',
        'deployment_locations' => [],
        'exposures' => [],
      ],
      'other_employment' => [
        'had_other_jobs' => 'yes',
        'jobs' => [
          [
            'occupation' => 'Military',
            'industry' => 'military',
            'start_year' => 2016,
            'end_year' => 2018,
            'exposures' => 'diesel,chemicals',
            'exposures_other' => 'Jet fuel',
          ],
        ],
      ],
      'ppe' => [
        'scba_usage' => 'sometimes',
        'glove_usage' => 'sometimes',
        'hood_usage' => 'sometimes',
        'turnout_cleaning' => 'weekly',
      ],
      'decontamination' => [
        'field_decon' => 1,
        'station_decon' => 1,
        'shower_after_fire' => 'sometimes',
        'gear_drying' => 'living_area',
        'department_had_sops' => 'unknown',
      ],
      'health' => [
        'cancer_diagnosis' => 0,
        'cancer_details' => [],
        'family_history' => [
          [
            'relationship' => 'brother',
            'cancer_type' => 'Testicular Cancer',
            'age_at_diagnosis' => 42,
          ],
        ],
      ],
      'lifestyle' => [
        'smoking_status' => 'former',
        'smoking_age_started' => 18,
        'smoking_age_stopped' => 25,
        'cigarettes_per_day' => 'less_half_pack',
        'cigars_ever_used' => 'never',
        'cigars_age_started' => '',
        'cigars_age_stopped' => '',
        'pipes_ever_used' => 'never',
        'pipes_age_started' => '',
        'pipes_age_stopped' => '',
        'ecigs_ever_used' => 'former',
        'ecigs_age_started' => 30,
        'ecigs_age_stopped' => 32,
        'smokeless_ever_used' => 'never',
        'smokeless_age_started' => '',
        'smokeless_age_stopped' => '',
        'alcohol_frequency' => 'less_than_monthly',
        'physical_activity_days' => '1',
        'sleep_hours_per_night' => 6,
        'sleep_quality' => 'fair',
        'sleep_disorders' => ['shift_work_disorder' => 'shift_work_disorder'],
      ],
    ];
  }

  /**
   * Get all US states for random selection.
   */
  private function getAllStates(): array {
    return [
      'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
      'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
      'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
      'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
      'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY'
    ];
  }

  /**
   * Generate consent data.
   */
  private function generateConsentData(): array {
    $first_names = ['John', 'Michael', 'David', 'James', 'Robert', 'William', 'Sarah', 'Jennifer', 'Maria', 'Lisa'];
    $last_names = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez'];
    
    return [
      'consented_to_participate' => 1,
      'consented_to_registry_linkage' => rand(0, 1),
      'electronic_signature' => $first_names[array_rand($first_names)] . ' ' . $last_names[array_rand($last_names)],
    ];
  }

  /**
   * Generate random profile data.
   */
  private function generateRandomProfileData(): array {
    $first_names = ['John', 'Michael', 'David', 'James', 'Robert', 'William', 'Richard', 'Joseph', 'Thomas', 'Christopher',
                    'Sarah', 'Jennifer', 'Maria', 'Lisa', 'Nancy', 'Karen', 'Betty', 'Helen', 'Sandra', 'Donna'];
    $last_names = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
                   'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin'];
    $states = $this->getAllStates();
    $cities = ['Springfield', 'Franklin', 'Clinton', 'Madison', 'Georgetown', 'Arlington', 'Salem', 'Fairview', 'Bristol', 'Riverside',
               'Oakland', 'Manchester', 'Newport', 'Greenville', 'Ashland', 'Burlington', 'Dover', 'Jackson', 'Columbia', 'Lakewood'];
    $street_names = ['Main', 'Oak', 'Pine', 'Maple', 'Cedar', 'Elm', 'Washington', 'Lake', 'Hill', 'Park',
                     'First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth', 'Tenth'];
    $street_types = ['Street', 'Avenue', 'Road', 'Drive', 'Lane', 'Court', 'Circle', 'Boulevard', 'Way', 'Place'];
    
    return [
      'first_name' => $first_names[array_rand($first_names)],
      'middle_name' => (rand(1, 100) <= 30) ? chr(65 + rand(0, 25)) : '',  // 30% chance of middle name (optional)
      'last_name' => $last_names[array_rand($last_names)],
      'date_of_birth' => sprintf('%04d-%02d-%02d', rand(1960, 1995), rand(1, 12), rand(1, 28)),
      'sex' => ['male', 'female'][rand(0, 1)],
      'ssn_last_4' => sprintf('%04d', rand(1000, 9999)),
      'country_of_birth' => 'USA',
      'state_of_birth' => $states[array_rand($states)],
      'city_of_birth' => $cities[array_rand($cities)],
      'address_line1' => rand(100, 9999) . ' ' . $street_names[array_rand($street_names)] . ' ' . $street_types[array_rand($street_types)],
      'city' => $cities[array_rand($cities)],
      'state' => $states[array_rand($states)],
      'zip_code' => sprintf('%05d', rand(10000, 99999)),
      'mobile_phone' => sprintf('(%03d) %03d-%04d', rand(200, 999), rand(200, 999), rand(1000, 9999)),
      'alternate_email' => (rand(1, 100) <= 30) ? 'alt' . rand(1000, 9999) . '@example.com' : '',  // 30% chance of alternate email (optional)
      'current_work_status' => ['active', 'retired'][rand(0, 1)],
    ];
  }

  /**
   * Generate random questionnaire data.
   * 
   * VALIDATION DATA GENERATION STRATEGY:
   * - All optional top-level fields: 30% probability
   * - Nested optional fields (dependent on parent): 80% probability
   * - This ensures ~30% overall visibility for nested optionals (30% × 80% ≈ 24-30%)
   * 
   * Examples:
   * - Other employment: 30% have jobs → 80% have second job
   * - Major incidents: 30% have incidents → 80% have second incident
   * - Cancer: 30% have diagnosis → 80% have second diagnosis
   * - Smoking: 30% are former/current → 100% have nested age fields
   * - Military service: 30% served → 100% have branch/dates
   */
  private function generateRandomQuestionnaireData(int $uid): array {
    // Form field options - must match exactly
    $races = ['american_indian', 'asian', 'black', 'hispanic', 'middle_eastern', 'pacific_islander', 'white', 'other'];
    $education = ['never_attended', 'elementary', 'some_hs', 'hs_ged', 'some_college', 'college_graduate', 'prefer_not_answer'];
    $employment_types = ['career', 'volunteer', 'paid_on_call', 'seasonal', 'wildland', 'military', 'other'];
    $marital_statuses = ['married', 'living_with_partner', 'never_married', 'divorced', 'separated', 'widowed', 'prefer_not_answer'];
    
    // Randomly select 1-3 races (checkboxes format: key => key)
    $num_races = rand(1, 3);
    $selected_races = [];
    for ($i = 0; $i < $num_races; $i++) {
      $race = $races[array_rand($races)];
      $selected_races[$race] = $race;
    }
    $selected_races = array_unique($selected_races, SORT_REGULAR);
    
    // 30% chance of having other employment, then 80% chance of second job
    $other_jobs = [];
    if (rand(1, 100) <= 30) {
      $occupations = ['Construction Worker', 'Paramedic', 'Police Officer', 'Military', 'Retail Manager', 'Factory Worker', 'Mechanic', 'Electrician',
                      'Plumber', 'Carpenter', 'Welder', 'Painter', 'HVAC Technician', 'Security Guard', 'Truck Driver', 'EMT',
                      'Warehouse Worker', 'Equipment Operator', 'Auto Body Technician', 'Machinist', 'Refinery Worker', 'Chemical Plant Worker'];
      $industries = ['construction', 'healthcare', 'law_enforcement', 'military', 'retail', 'manufacturing', 'automotive', 'trades',
                     'transportation', 'oil_gas', 'chemical', 'utilities', 'agriculture', 'mining', 'shipyard', 'railroad'];
      // Match form options: chemicals, radiation, asbestos, heavy_metals, other
      $exposures_list = ['chemicals', 'radiation', 'asbestos', 'heavy_metals'];
      
      // First job (100% if we're in this block)
      $start_year = rand(1990, 2010);
      $end_year = rand($start_year + 1, 2020);
      $occupation = $occupations[array_rand($occupations)];
      $industry = $industries[array_rand($industries)];
      
      // 80% chance of having exposures (nested optional)
      $exposures = [];
      $exposures_other = '';
      if (rand(1, 100) <= 80) {
        $num_exposures = rand(1, 3);
        for ($j = 0; $j < $num_exposures; $j++) {
          $exposures[] = $exposures_list[array_rand($exposures_list)];
        }
        $exposures = array_unique($exposures);
        // 80% chance of 'other' exposure
        if (rand(1, 100) <= 80) {
          $exposures[] = 'other';
          $exposures_other = 'Other chemical exposure';
        }
        // Convert to keyed array for checkboxes
        $exposures = array_combine($exposures, $exposures);
      }
      
      $other_jobs[] = [
        'occupation' => $occupation,
        'industry' => $industry,
        'start_year' => $start_year,
        'end_year' => $end_year,
        'exposures' => $exposures,
        'exposures_other' => $exposures_other,
      ];
      
      // 80% chance of second job (nested optional - increases to maintain 30%+ visibility)
      if (rand(1, 100) <= 80) {
        $start_year = rand(1990, 2010);
        $end_year = rand($start_year + 1, 2020);
        $occupation = $occupations[array_rand($occupations)];
        $industry = $industries[array_rand($industries)];
        
        $exposures = [];
        $exposures_other = '';
        if (rand(1, 100) <= 80) {
          $num_exposures = rand(1, 3);
          for ($j = 0; $j < $num_exposures; $j++) {
            $exposures[] = $exposures_list[array_rand($exposures_list)];
          }
          $exposures = array_unique($exposures);
          if (rand(1, 100) <= 80) {
            $exposures[] = 'other';
            $exposures_other = 'Other exposure detail';
          }
          // Convert to keyed array for checkboxes
          $exposures = array_combine($exposures, $exposures);
        }
        
        $other_jobs[] = [
          'occupation' => $occupation,
          'industry' => $industry,
          'start_year' => $start_year,
          'end_year' => $end_year,
          'exposures' => $exposures,
          'exposures_other' => $exposures_other,
        ];
      }
    }
    
    // Work history: First department is always added, 30% chance of second department
    $states = $this->getAllStates();
    $cities = ['Springfield', 'Franklin', 'Clinton', 'Madison', 'Georgetown', 'Arlington', 'Salem', 'Fairview', 'Bristol', 'Riverside',
               'Oakland', 'Manchester', 'Newport', 'Greenville', 'Ashland', 'Burlington', 'Dover', 'Jackson', 'Columbia', 'Lakewood'];
    $job_titles = ['Firefighter', 'Firefighter/Paramedic', 'Firefighter/EMT', 'Engineer', 'Driver/Engineer', 'Apparatus Engineer',
                   'Lieutenant', 'Captain', 'Battalion Chief', 'Division Chief', 'Inspector', 'Fire Marshal',
                   'Training Officer', 'Safety Officer', 'Wildland Firefighter', 'Airport Firefighter'];
    
    // Incident frequency options matching the form
    $frequency_options = ['never', 'less_than_1', '1_5', '6_20', '21_50', 'more_than_50'];
    $incident_type_keys = ['structure_residential', 'structure_commercial', 'vehicle', 'rubbish_dumpster', 'wildland',
                           'medical_ems', 'hazmat', 'technical_rescue', 'arff', 'marine', 'prescribed_burns', 'training_fires', 'other'];
    
    // Generate incident frequencies for a job
    $generate_incident_frequencies = function() use ($frequency_options, $incident_type_keys) {
      $frequencies = [];
      foreach ($incident_type_keys as $type) {
        // Randomly select frequency, with higher weight for common incident types
        $frequencies[$type] = $frequency_options[array_rand($frequency_options)];
      }
      return $frequencies;
    };
    
    $departments = [
      [
        'department_name' => 'Test Fire Department ' . rand(1, 999),
        'fdid' => sprintf('%05d', rand(10000, 99999)),
        'state' => $states[array_rand($states)],
        'city' => $cities[array_rand($cities)],
        'start_date' => sprintf('%04d-%02d-%02d', rand(2005, 2020), rand(1, 12), rand(1, 28)),
        'currently_employed' => TRUE,
        'end_date' => '',
        'num_jobs' => 1,
        'jobs' => [
          [
            'title' => $job_titles[array_rand($job_titles)],
            'employment_type' => $employment_types[array_rand($employment_types)],
            'responded_incidents' => 'yes',
            'incident_types' => $generate_incident_frequencies(),
          ],
        ],
      ],
    ];
    
    if (rand(1, 100) <= 30) {
      $departments[] = [
        'department_name' => 'Previous Fire Department ' . rand(1, 999),
        'fdid' => sprintf('%05d', rand(10000, 99999)),
        'state' => $states[array_rand($states)],
        'city' => $cities[array_rand($cities)],
        'start_date' => sprintf('%04d-%02d-%02d', rand(1990, 2005), rand(1, 12), rand(1, 28)),
        'currently_employed' => FALSE,
        'end_date' => sprintf('%04d-%02d-%02d', rand(2005, 2015), rand(1, 12), rand(1, 28)),
        'num_jobs' => 1,
        'jobs' => [
          [
            'title' => $job_titles[array_rand($job_titles)],
            'employment_type' => $employment_types[array_rand($employment_types)],
            'responded_incidents' => 'yes',
            'incident_types' => $generate_incident_frequencies(),
          ],
        ],
      ];
    }
    
    // Major incidents: 30% chance of having incidents
    $major_incidents_data = [];
    if (rand(1, 100) <= 30) {
      $incident_types = ['Structure Fire', 'Wildland Fire', 'Chemical Spill', 'HAZMAT Response', 'Vehicle Fire',
                         'Industrial Fire', 'High-rise Fire', 'Multi-alarm Fire', 'Oil Refinery Fire', 'Chemical Plant Fire',
                         'Warehouse Fire', 'Airport Crash Fire', 'Marine/Ship Fire', 'Tire Fire', 'Plastics Fire',
                         'Terrorist Attack Response', 'Natural Disaster Response', 'Extended Overhaul Operations'];
      // Match form options: hours, days, weeks, months
      $durations = ['hours', 'days', 'weeks', 'months'];
      
      // First incident
      $major_incidents_data[] = [
        'description' => $incident_types[array_rand($incident_types)] . ' - Major incident',
        'date' => sprintf('%04d-%02d-%02d', rand(2000, 2020), rand(1, 12), rand(1, 28)),
        'duration' => $durations[array_rand($durations)],
      ];
      
      // 80% chance of second incident (nested optional - increases to maintain 30%+ visibility)
      if (rand(1, 100) <= 80) {
        $major_incidents_data[] = [
          'description' => $incident_types[array_rand($incident_types)] . ' - Second major incident',
          'date' => sprintf('%04d-%02d-%02d', rand(2000, 2020), rand(1, 12), rand(1, 28)),
          'duration' => $durations[array_rand($durations)],
        ];
      }
    }
    
    // Health: 30% chance of cancer diagnosis, then 80% chance of second diagnosis (nested optional)
    $cancer_details = [];
    if (rand(1, 100) <= 30) {
      $cancer_types = ['Lung', 'Prostate', 'Colon', 'Melanoma', 'Leukemia', 'Lymphoma', 'Kidney', 'Bladder',
                       'Testicular', 'Brain', 'Thyroid', 'Mesothelioma', 'Esophageal', 'Stomach', 'Liver', 'Pancreatic',
                       'Non-Hodgkin Lymphoma', 'Multiple Myeloma', 'Oral Cavity', 'Laryngeal', 'Skin (Non-Melanoma)',
                       'Rectal', 'Colorectal', 'Renal Cell Carcinoma'];
      
      // First diagnosis
      $cancer_details[] = [
        'type' => $cancer_types[array_rand($cancer_types)],
        'year_diagnosed' => rand(2010, 2023),
      ];
      
      // 80% chance of second diagnosis (nested optional - increases to maintain 30%+ visibility)
      if (rand(1, 100) <= 80) {
        $cancer_details[] = [
          'type' => $cancer_types[array_rand($cancer_types)],
          'year_diagnosed' => rand(2010, 2023),
        ];
      }
    }
    
    // Family cancer history: 40% chance of family history, 30% chance of second relative
    $family_history = [];
    if (rand(1, 100) <= 40) {
      $relations = ['mother', 'father', 'brother', 'sister', 'son', 'daughter'];
      $cancer_types = ['Lung', 'Breast', 'Prostate', 'Colon', 'Melanoma', 'Leukemia', 'Lymphoma', 'Pancreatic', 'Ovarian',
                       'Brain', 'Liver', 'Kidney', 'Bladder', 'Thyroid', 'Stomach', 'Esophageal', 'Multiple Myeloma', 'Other'];
      
      // First relative
      $family_history[] = [
        'relationship' => $relations[array_rand($relations)],
        'cancer_type' => $cancer_types[array_rand($cancer_types)],
      ];
      
      // 80% chance of second relative (nested optional - increases to maintain 30%+ visibility)
      if (rand(1, 100) <= 80) {
        $family_history[] = [
          'relationship' => $relations[array_rand($relations)],
          'cancer_type' => $cancer_types[array_rand($cancer_types)],
        ];
      }
    }
    
    return [
      'demographics' => [
        'race_ethnicity' => $selected_races,
        'race_other' => isset($selected_races['other']) ? 'Mixed race' : '',
        'education_level' => $education[array_rand($education)],
        'marital_status' => $marital_statuses[array_rand($marital_statuses)],
        'height_inches' => rand(48, 96),
        'weight_pounds' => rand(80, 500),
      ],
      'work_history' => [
        'num_departments' => count($departments),
        'departments' => $departments,
      ],
      'exposure' => [
        'afff_used' => (rand(1, 100) <= 30) ? 'yes' : ((rand(1, 100) <= 15) ? 'unknown' : 'no'),  // 30% yes, 10.5% unknown, 59.5% no
        'afff_times' => rand(1, 100),
        'afff_first_year' => rand(1990, 2020),
        'diesel_exhaust' => ['regularly', 'sometimes', 'rarely', 'never'][rand(0, 3)],
        'chemical_activities' => $this->getRandomChemicalActivities(),
        'major_incidents' => count($major_incidents_data) > 0 ? 'yes' : 'no',
        'major_incidents_data' => $major_incidents_data,
      ],
      'military' => [
        'served' => (rand(1, 100) <= 30) ? 'yes' : 'no',
        'branch' => ['army', 'navy', 'air_force', 'marines', 'coast_guard', 'national_guard', 'reserves'][rand(0, 6)],
        'start_date' => sprintf('%04d-%02d-%02d', rand(1990, 2010), rand(1, 12), rand(1, 28)),
        'currently_serving' => rand(0, 1) ? TRUE : FALSE,
        'end_date' => sprintf('%04d-%02d-%02d', rand(2010, 2023), rand(1, 12), rand(1, 28)),
        'was_firefighter' => ['yes', 'no'][rand(0, 1)],
        'firefighting_duties' => 'Structural firefighting, crash rescue, and fire prevention duties',
      ],
      'other_employment' => [
        'had_other_jobs' => count($other_jobs) > 0 ? 'yes' : 'no',
        'jobs' => $other_jobs,
      ],
      'ppe' => [
        // Each PPE type: ever_used, always_used, year_started (1950-current)
        'scba' => [
          'ever_used' => 'yes',  // All firefighters use SCBA
          'always_used' => rand(0, 1) ? TRUE : FALSE,
          'year_started' => !rand(0, 1) ? '' : rand(1950, 2024),
        ],
        'turnout_coat' => [
          'ever_used' => 'yes',  // Standard PPE
          'always_used' => rand(0, 1) ? TRUE : FALSE,
          'year_started' => !rand(0, 1) ? '' : rand(1950, 2024),
        ],
        'turnout_pants' => [
          'ever_used' => 'yes',  // Standard PPE
          'always_used' => rand(0, 1) ? TRUE : FALSE,
          'year_started' => !rand(0, 1) ? '' : rand(1950, 2024),
        ],
        'gloves' => [
          'ever_used' => 'yes',  // Standard PPE
          'always_used' => rand(0, 1) ? TRUE : FALSE,
          'year_started' => !rand(0, 1) ? '' : rand(1950, 2024),
        ],
        'helmet' => [
          'ever_used' => 'yes',  // Standard PPE
          'always_used' => rand(0, 1) ? TRUE : FALSE,
          'year_started' => !rand(0, 1) ? '' : rand(1950, 2024),
        ],
        'boots' => [
          'ever_used' => 'yes',  // Standard PPE
          'always_used' => rand(0, 1) ? TRUE : FALSE,
          'year_started' => !rand(0, 1) ? '' : rand(1950, 2024),
        ],
        'nomex_hood' => [
          'ever_used' => rand(0, 1) ? 'yes' : 'no',  // Not all departments had these early on
          'always_used' => rand(0, 1) ? TRUE : FALSE,
          'year_started' => !rand(0, 1) ? '' : rand(1980, 2024),
        ],
        'wildland_clothing' => [
          'ever_used' => rand(0, 2) ? 'no' : 'yes',  // Fewer firefighters do wildland
          'always_used' => rand(0, 1) ? TRUE : FALSE,
          'year_started' => !rand(0, 1) ? '' : rand(1950, 2024),
        ],
        // SCBA usage patterns - all 5 options: always, usually, sometimes, rarely, never
        'scba_during_suppression' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        'scba_during_overhaul' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        'scba_interior_attack' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        'scba_exterior_attack' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        // Respirator usage patterns
        'respirator_vehicle_fires' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        'respirator_brush_fires' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        'respirator_wildland' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        'respirator_investigations' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        'respirator_wui' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
      ],
      'decontamination' => [
        'washed_hands_face' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        'changed_gear_at_scene' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        'showered_at_station' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        'laundered_gear' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        'used_wet_wipes' => ['always', 'usually', 'sometimes', 'rarely', 'never'][rand(0, 4)],
        'department_had_sops' => ['yes', 'no', 'unknown'][rand(0, 2)],
        'sops_year_implemented' => rand(0, 1) ? rand(1990, 2024) : '',
      ],
      'health' => [
        'cancer_diagnosis' => count($cancer_details) > 0 ? 1 : 0,
        'cancer_details' => $cancer_details,
        'family_history' => $family_history,
        'other_conditions' => $this->getRandomHealthConditions(),
      ],
      'lifestyle' => [
        'smoking_status' => $smoking_status = (rand(1, 100) <= 30) ? (['former', 'current'][rand(0, 1)]) : 'never',  // 30% former/current, 70% never
        'smoking_age_started' => in_array($smoking_status, ['former', 'current']) ? rand(13, 25) : '',
        'smoking_age_stopped' => $smoking_status === 'former' ? rand(25, 65) : '',
        'cigarettes_per_day' => in_array($smoking_status, ['former', 'current']) ? ['less_half_pack', 'half_to_one_pack', 'one_to_two_packs', 'more_than_two_packs'][rand(0, 3)] : '',
        'cigars_ever_used' => $cigars_status = (rand(1, 100) <= 30) ? (['former', 'current'][rand(0, 1)]) : 'never',  // 30% former/current
        'cigars_age_started' => in_array($cigars_status, ['former', 'current']) ? rand(18, 30) : '',
        'cigars_age_stopped' => $cigars_status === 'former' ? rand(30, 60) : '',
        'pipes_ever_used' => $pipes_status = (rand(1, 100) <= 30) ? (['former', 'current'][rand(0, 1)]) : 'never',  // 30% former/current
        'pipes_age_started' => in_array($pipes_status, ['former', 'current']) ? rand(18, 30) : '',
        'pipes_age_stopped' => $pipes_status === 'former' ? rand(30, 60) : '',
        'ecigs_ever_used' => $ecigs_status = (rand(1, 100) <= 30) ? (['former', 'current'][rand(0, 1)]) : 'never',  // 30% former/current
        'ecigs_age_started' => in_array($ecigs_status, ['former', 'current']) ? rand(18, 35) : '',
        'ecigs_age_stopped' => $ecigs_status === 'former' ? rand(25, 50) : '',
        'smokeless_ever_used' => $smokeless_status = (rand(1, 100) <= 30) ? (['former', 'current'][rand(0, 1)]) : 'never',  // 30% former/current
        'smokeless_age_started' => in_array($smokeless_status, ['former', 'current']) ? rand(15, 25) : '',
        'smokeless_age_stopped' => $smokeless_status === 'former' ? rand(25, 50) : '',
        'alcohol_frequency' => ['never', 'less_than_monthly', '1_3_per_month', '1_2_per_week', '3_4_per_week', '5_plus_per_week'][rand(0, 5)],
        'physical_activity_days' => (string) rand(0, 7),
        'sleep_hours_per_night' => rand(4, 10),
        'sleep_quality' => ['excellent', 'good', 'fair', 'poor', 'very_poor'][rand(0, 4)],
        'sleep_disorders' => $this->getRandomSleepDisorders(),
      ],
    ];
  }

  /**
   * Generate random chemical activities selection.
   */
  private function getRandomChemicalActivities(): array {
    $all_activities = ['fire_investigation', 'overhaul', 'salvage', 'vehicle_maintenance', 'station_maintenance'];
    $num_activities = rand(0, 3); // 0-3 activities selected
    
    if ($num_activities === 0) {
      return ['none' => 'none'];
    }
    
    $selected = [];
    shuffle($all_activities);
    for ($i = 0; $i < $num_activities && $i < count($all_activities); $i++) {
      $selected[$all_activities[$i]] = $all_activities[$i];
    }
    
    return $selected;
  }

  /**
   * Generate random health conditions selection.
   */
  private function getRandomHealthConditions(): array {
    $all_conditions = ['heart_disease', 'copd', 'asthma', 'diabetes'];
    $num_conditions = rand(0, 2); // 0-2 conditions selected
    
    if ($num_conditions === 0) {
      return ['none' => 'none'];
    }
    
    $selected = [];
    shuffle($all_conditions);
    for ($i = 0; $i < $num_conditions && $i < count($all_conditions); $i++) {
      $selected[$all_conditions[$i]] = $all_conditions[$i];
    }
    
    return $selected;
  }

  /**
   * Generate random sleep disorders selection.
   */
  private function getRandomSleepDisorders(): array {
    $all_disorders = ['insomnia', 'sleep_apnea', 'restless_leg', 'shift_work_disorder'];
    $num_disorders = rand(0, 2); // 0-2 disorders selected
    
    if ($num_disorders === 0) {
      return ['none' => 'none'];
    }
    
    $selected = [];
    shuffle($all_disorders);
    for ($i = 0; $i < $num_disorders && $i < count($all_disorders); $i++) {
      $selected[$all_disorders[$i]] = $all_disorders[$i];
    }
    
    return $selected;
  }

  /**
   * Save profile data to database.
   */
  private function saveProfileData(int $uid, array $data): array {
    try {
      $database = \Drupal::database();

      // Check if record exists
      $exists = $database->select('nfr_user_profile', 'p')
        ->fields('p', ['id'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchField();

      $fields = [
        'first_name' => $data['first_name'],
        'middle_name' => $data['middle_name'],
        'last_name' => $data['last_name'],
        'date_of_birth' => $data['date_of_birth'],
        'sex' => $data['sex'],
        'ssn_last_4' => $data['ssn_last_4'],
        'country_of_birth' => $data['country_of_birth'],
        'state_of_birth' => $data['state_of_birth'],
        'city_of_birth' => $data['city_of_birth'],
        'address_line1' => $data['address_line1'],
        'city' => $data['city'],
        'state' => $data['state'],
        'zip_code' => $data['zip_code'],
        'mobile_phone' => $data['mobile_phone'],
        'current_work_status' => $data['current_work_status'],
        'profile_completed' => 1,
        'profile_completed_date' => time(),
        'updated' => time(),
      ];

      if ($exists) {
        $database->update('nfr_user_profile')
          ->fields($fields)
          ->condition('uid', $uid)
          ->execute();

        return [
          'success' => true,
          'message' => 'Profile data updated successfully',
          'record_id' => $exists,
        ];
      }
      else {
        $fields['uid'] = $uid;
        $fields['created'] = time();
        $fields['participant_id'] = 'NFR-' . strtoupper(substr(md5(uniqid((string) $uid, true)), 0, 8));
        
        $record_id = $database->insert('nfr_user_profile')
          ->fields($fields)
          ->execute();

        return [
          'success' => true,
          'message' => 'Profile data inserted successfully',
          'record_id' => $record_id,
        ];
      }
    }
    catch (\Exception $e) {
      return [
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Verify profile data in database.
   */
  private function verifyProfileData(int $uid): array {
    try {
      $database = \Drupal::database();

      $record = $database->select('nfr_user_profile', 'p')
        ->fields('p')
        ->condition('uid', $uid)
        ->execute()
        ->fetchAssoc();

      if (!$record) {
        return [
          'success' => false,
          'message' => "No profile data found for UID: $uid",
        ];
      }

      return [
        'success' => true,
        'message' => 'Profile verified in database',
        'record' => $record,
      ];

    }
    catch (\Exception $e) {
      return [
        'success' => false,
        'message' => 'Verification error: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Submit consent form through actual form validation and submission.
   */
  private function submitConsentForm(int $uid, array $data): array {
    try {
      // Load the user
      $user = \Drupal\user\Entity\User::load($uid);
      if (!$user) {
        return [
          'success' => false,
          'message' => "User $uid not found",
          'errors' => [],
        ];
      }

      // Switch to the test user's context
      $accountSwitcher = \Drupal::service('account_switcher');
      $accountSwitcher->switchTo($user);

      // Build form state with the consent data
      $form_state = new \Drupal\Core\Form\FormState();
      $form_state->setValues([
        'consent_participate' => $data['consented_to_participate'],
        'consent_linkage' => $data['consented_to_registry_linkage'],
        'signature' => $data['electronic_signature'],
        'op' => 'Submit Consent',
      ]);

      // Get the form and submit it programmatically
      \Drupal::formBuilder()->submitForm('\Drupal\nfr\Form\NFRConsentForm', $form_state);

      // Switch back to original user
      $accountSwitcher->switchBack();

      // Check for form errors
      $errors = $form_state->getErrors();
      if (!empty($errors)) {
        return [
          'success' => false,
          'message' => 'Consent form validation failed',
          'errors' => array_map('strval', $errors),
        ];
      }

      return [
        'success' => true,
        'message' => 'Consent form submitted successfully',
        'errors' => [],
      ];
    }
    catch (\Exception $e) {
      // Make sure to switch back even on error
      if (isset($accountSwitcher)) {
        $accountSwitcher->switchBack();
      }

      return [
        'success' => false,
        'message' => 'Consent form submission error: ' . $e->getMessage(),
        'errors' => [$e->getMessage()],
      ];
    }
  }

  /**
   * Submit profile data through actual form validation and submission.
   */
  private function submitProfileForm(int $uid, array $data): array {
    try {
      // Load the user
      $user = \Drupal\user\Entity\User::load($uid);
      if (!$user) {
        return [
          'success' => false,
          'message' => "User $uid not found",
          'errors' => [],
        ];
      }

      // Switch to the test user's context
      $accountSwitcher = \Drupal::service('account_switcher');
      $accountSwitcher->switchTo($user);

      // Build form state with the random data
      $form_state = new \Drupal\Core\Form\FormState();
      $form_state->setValues([
        'first_name' => $data['first_name'],
        'middle_name' => $data['middle_name'],
        'last_name' => $data['last_name'],
        'date_of_birth' => $data['date_of_birth'],
        'sex' => $data['sex'],
        'ssn_last_4' => $data['ssn_last_4'],
        'country_of_birth' => $data['country_of_birth'],
        'state_of_birth' => $data['state_of_birth'],
        'city_of_birth' => $data['city_of_birth'],
        'address_line1' => $data['address_line1'],
        'city' => $data['city'],
        'state' => $data['state'],
        'zip_code' => $data['zip_code'],
        'mobile_phone' => $data['mobile_phone'],
        'alternate_email' => $data['alternate_email'] ?? '',
        'current_work_status' => $data['current_work_status'],
        'op' => 'Save and Continue',
      ]);

      // Get the form and submit it programmatically
      $form = \Drupal::formBuilder()->getForm('\Drupal\nfr\Form\NFRUserProfileForm');
      \Drupal::formBuilder()->submitForm('\Drupal\nfr\Form\NFRUserProfileForm', $form_state);

      // Switch back to original user
      $accountSwitcher->switchBack();

      // Check for form errors
      $errors = $form_state->getErrors();
      if (!empty($errors)) {
        return [
          'success' => false,
          'message' => 'Form validation failed',
          'errors' => array_map('strval', $errors),
        ];
      }

      return [
        'success' => true,
        'message' => 'Profile form submitted successfully',
        'errors' => [],
      ];
    }
    catch (\Exception $e) {
      // Make sure to switch back even on error
      if (isset($accountSwitcher)) {
        $accountSwitcher->switchBack();
      }

      return [
        'success' => false,
        'message' => 'Form submission error: ' . $e->getMessage(),
        'errors' => [$e->getMessage()],
      ];
    }
  }

  /**
   * Submit all 9 questionnaire section forms.
   */
  private function submitAllQuestionnaireSections(int $uid, array $data): array {
    $section_forms = [
      1 => '\Drupal\nfr\Form\NFRQuestionnaireSection1Form',
      2 => '\Drupal\nfr\Form\NFRQuestionnaireSection2Form',
      3 => '\Drupal\nfr\Form\NFRQuestionnaireSection3Form',
      4 => '\Drupal\nfr\Form\NFRQuestionnaireSection4Form',
      5 => '\Drupal\nfr\Form\NFRQuestionnaireSection5Form',
      6 => '\Drupal\nfr\Form\NFRQuestionnaireSection6Form',
      7 => '\Drupal\nfr\Form\NFRQuestionnaireSection7Form',
      8 => '\Drupal\nfr\Form\NFRQuestionnaireSection8Form',
      9 => '\Drupal\nfr\Form\NFRQuestionnaireSection9Form',
    ];

    $section_data_map = [
      1 => ['demographics' => $data['demographics'] ?? []],
      2 => [
        'work_history' => array_merge(
          ['num_departments' => count($data['work_history']['departments'] ?? [])],
          $data['work_history'] ?? []
        ),
      ],
      3 => [
        'exposure' => [
          'afff_used' => $data['exposure']['afff_used'] ?? 'no',
          'afff_times' => ($data['exposure']['afff_used'] ?? 'no') === 'yes' ? ($data['exposure']['afff_times'] ?? 1) : '',
          'afff_first_year' => ($data['exposure']['afff_used'] ?? 'no') === 'yes' ? ($data['exposure']['afff_first_year'] ?? '') : '',
          'diesel_exhaust' => $data['exposure']['diesel_exhaust'] ?? 'never',
          'chemical_activities' => $data['exposure']['chemical_activities'] ?? [],
          'major_incidents' => !empty($data['exposure']['major_incidents_data']) ? 'yes' : 'no',
          'incidents' => $data['exposure']['major_incidents_data'] ?? [],  // For form builder to count
          'incidents_wrapper' => [
            'incidents' => $data['exposure']['major_incidents_data'] ?? [],  // For submit handler
          ],
        ],
      ],
      4 => [
        'military' => [
          'served' => $data['military']['served'] ?? 'no',
          'branch' => $data['military']['branch'] ?? '',
          'start_date' => $data['military']['start_date'] ?? '',
          'currently_serving' => $data['military']['currently_serving'] ?? FALSE,
          'end_date' => $data['military']['end_date'] ?? '',
          'was_firefighter' => $data['military']['was_firefighter'] ?? 'no',
          'firefighting_duties' => $data['military']['firefighting_duties'] ?? '',
        ],
      ],
      5 => [
        'other_employment' => [
          'had_other_jobs' => !empty($data['other_employment']['jobs']) ? 'yes' : 'no',
          'jobs' => $data['other_employment']['jobs'] ?? [],
        ],
      ],
      6 => [
        'ppe' => $data['ppe'] ?? [],
      ],
      7 => [
        'decontamination' => array_merge(
          ['department_had_sops' => $data['decontamination']['department_had_sops'] ?? 'no'],
          $data['decontamination'] ?? []
        ),
      ],
      8 => [
        'health' => [
          'cancer_diagnosed' => !empty($data['health']['cancer_details']) ? 'yes' : 'no',
          'cancers' => $data['health']['cancer_details'] ?? [],
          'has_family_cancer_history' => !empty($data['health']['family_history']) ? 'yes' : 'no',
          'family_cancers_container' => $data['health']['family_history'] ?? [],
          'other_conditions' => $data['health']['other_conditions'] ?? [],
        ],
      ],
      9 => [
        'lifestyle' => $data['lifestyle'] ?? [
          'smoking_status' => 'never',
          'alcohol_frequency' => 'never',
          'physical_activity_days' => '0',
          'sleep_hours_per_night' => 7,
          'sleep_quality' => 'good',
          'sleep_disorders' => [],
        ],
      ],
    ];

    $results = [];

    try {
      // Load the user
      $user = \Drupal\user\Entity\User::load($uid);
      if (!$user) {
        foreach (range(1, 9) as $section) {
          $results[$section] = [
            'success' => false,
            'message' => "User $uid not found",
            'errors' => [],
          ];
        }
        return $results;
      }

      // Switch to the test user's context
      $accountSwitcher = \Drupal::service('account_switcher');
      $accountSwitcher->switchTo($user);

      // Submit each section form
      foreach ($section_forms as $section_num => $form_class) {
        try {
          $form_state = new \Drupal\Core\Form\FormState();
          $values_to_set = $section_data_map[$section_num];
          
          // Set form_state storage for dynamic field counts (required for AJAX forms)
          if ($section_num === 2 && isset($data['work_history']['departments'])) {
            $form_state->set('num_departments', count($data['work_history']['departments']));
          }
          if ($section_num === 3 && isset($data['exposure']['major_incidents_data'])) {
            $form_state->set('num_major_incidents', count($data['exposure']['major_incidents_data']));
          }
          if ($section_num === 5 && isset($data['other_employment']['jobs'])) {
            $form_state->set('num_other_jobs', count($data['other_employment']['jobs']));
          }
          if ($section_num === 8) {
            if (isset($data['health']['cancer_details'])) {
              $form_state->set('num_cancers', count($data['health']['cancer_details']));
            }
            if (isset($data['health']['family_history'])) {
              $form_state->set('num_family_cancers', count($data['health']['family_history']));
            }
          }
          
          $form_state->setValues($values_to_set);
          $form_state->setValue('op', 'Save & Continue');

          \Drupal::formBuilder()->submitForm($form_class, $form_state);

          $errors = $form_state->getErrors();
          if (!empty($errors)) {
            $error_details = [];
            foreach ($errors as $field => $error) {
              $error_details[] = "$field: $error";
            }
            $results[$section_num] = [
              'success' => false,
              'message' => "Section {$section_num} validation failed: " . implode('; ', $error_details),
              'errors' => array_map('strval', $errors),
            ];
          }
          else {
            $results[$section_num] = [
              'success' => true,
              'message' => "Section {$section_num} submitted successfully",
              'errors' => [],
            ];
          }
        }
        catch (\Exception $e) {
          $results[$section_num] = [
            'success' => false,
            'message' => "Section {$section_num} error: " . $e->getMessage(),
            'errors' => [$e->getMessage()],
          ];
        }
      }

      // Switch back to original user
      $accountSwitcher->switchBack();

      return $results;
    }
    catch (\Exception $e) {
      // Make sure to switch back even on error
      if (isset($accountSwitcher)) {
        $accountSwitcher->switchBack();
      }

      foreach (range(1, 9) as $section) {
        $results[$section] = [
          'success' => false,
          'message' => 'Overall error: ' . $e->getMessage(),
          'errors' => [$e->getMessage()],
        ];
      }

      return $results;
    }
  }

  /**
   * Submit questionnaire data through actual form validation and submission.
   * @deprecated Use submitAllQuestionnaireSections() instead for new multi-page format.
   */
  private function submitQuestionnaireForm(int $uid, array $data): array {
    try {
      // Load the user
      $user = \Drupal\user\Entity\User::load($uid);
      if (!$user) {
        return [
          'success' => false,
          'message' => "User $uid not found",
          'errors' => [],
        ];
      }

      // Switch to the test user's context
      $accountSwitcher = \Drupal::service('account_switcher');
      $accountSwitcher->switchTo($user);

      // Build form state with the random data
      $form_state = new \Drupal\Core\Form\FormState();
      $form_state->setValues($data);
      $form_state->setValue('op', 'Save and Continue');

      // Get the form and submit it programmatically
      $form = \Drupal::formBuilder()->getForm('\Drupal\nfr\Form\NFRQuestionnaireForm');
      \Drupal::formBuilder()->submitForm('\Drupal\nfr\Form\NFRQuestionnaireForm', $form_state);

      // Switch back to original user
      $accountSwitcher->switchBack();

      // Check for form errors
      $errors = $form_state->getErrors();
      if (!empty($errors)) {
        return [
          'success' => false,
          'message' => 'Form validation failed',
          'errors' => array_map('strval', $errors),
        ];
      }

      return [
        'success' => true,
        'message' => 'Questionnaire form submitted successfully',
        'errors' => [],
      ];
    }
    catch (\Exception $e) {
      // Make sure to switch back even on error
      if (isset($accountSwitcher)) {
        $accountSwitcher->switchBack();
      }
      
      return [
        'success' => false,
        'message' => 'Form submission error: ' . $e->getMessage(),
        'errors' => [$e->getMessage()],
      ];
    }
  }

  /**
   * Check error logs for recent errors.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with log check results.
   */
  public function checkErrorLogsEndpoint(): JsonResponse {
    $results = $this->checkErrorLogs();
    return new JsonResponse($results);
  }

  /**
   * Check dblog for recent errors.
   * 
   * @param int|null $since_timestamp
   *   Optional timestamp to check errors since. Defaults to 10 minutes ago.
   */
  private function checkErrorLogs(?int $since_timestamp = NULL): array {
    try {
      $database = \Drupal::database();

      // Check if dblog table exists
      if (!$database->schema()->tableExists('watchdog')) {
        return [
          'success' => true,
          'has_errors' => false,
          'message' => 'Watchdog table not found - dblog module may not be enabled',
          'error_count' => 0,
        ];
      }

      // Default to last 10 minutes if no timestamp provided
      $check_since = $since_timestamp ?? (time() - 600);
      $time_description = $since_timestamp ? 'since test started' : 'in the last 10 minutes';
      
      $query = $database->select('watchdog', 'w')
        ->fields('w', ['wid', 'type', 'message', 'variables', 'severity', 'timestamp'])
        ->condition('severity', [0, 1, 2, 3], 'IN') // EMERGENCY, ALERT, CRITICAL, ERROR
        ->condition('timestamp', $check_since, '>')
        ->orderBy('timestamp', 'DESC')
        ->range(0, 10);

      $errors = $query->execute()->fetchAll();

      $error_count = count($errors);
      $recent_errors = [];

      foreach ($errors as $error) {
        // Decode the variables from the watchdog entry
        $variables = [];
        if (!empty($error->variables)) {
          $variables = unserialize($error->variables);
          if (!is_array($variables)) {
            $variables = [];
          }
        }
        
        // Replace placeholders in the message with actual values
        $message = $error->message;
        if (!empty($variables)) {
          foreach ($variables as $key => $value) {
            // Handle different placeholder types
            if (is_string($value) || is_numeric($value)) {
              $message = str_replace($key, (string) $value, $message);
            }
            elseif (is_array($value) || is_object($value)) {
              $message = str_replace($key, print_r($value, TRUE), $message);
            }
          }
        }
        
        $recent_errors[] = [
          'type' => $error->type,
          'message' => substr($message, 0, 500), // Show more of the error message
          'severity' => $error->severity,
          'time' => date('Y-m-d H:i:s', (int) $error->timestamp),
        ];
      }

      return [
        'success' => true,
        'has_errors' => $error_count > 0,
        'message' => $error_count > 0 
          ? "Found $error_count error(s) $time_description" 
          : "No errors found $time_description",
        'error_count' => $error_count,
        'recent_errors' => $recent_errors,
      ];

    }
    catch (\Exception $e) {
      return [
        'success' => false,
        'has_errors' => false,
        'message' => 'Error checking logs: ' . $e->getMessage(),
        'error_count' => 0,
      ];
    }
  }

  /**
   * Redirect from old fill-rates path to new admin location.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response.
   */
  public function fillRatesRedirect() {
    return $this->redirect('nfr.validation.fill_rates');
  }

  /**
   * Display fill rates for all questionnaire fields.
   */
  public function getFillRates() {
    $connection = \Drupal::database();
    
    try {
      // Get all profile records
      $profile_query = $connection->select('nfr_user_profile', 'p')
        ->fields('p');
      $profile_results = $profile_query->execute();
      $profiles = [];
      foreach ($profile_results as $row) {
        $profiles[$row->uid] = $row;
      }
      
      // Get all questionnaire records with ALL columns
      $query = $connection->select('nfr_questionnaire', 'q')
        ->fields('q');
      
      $results = $query->execute();
      
      // Get all work history from normalized tables
      $work_history_query = $connection->select('nfr_work_history', 'wh')
        ->fields('wh');
      $work_history_results = $work_history_query->execute();
      
      $user_work_history = [];
      foreach ($work_history_results as $wh) {
        if (!isset($user_work_history[$wh->uid])) {
          $user_work_history[$wh->uid] = [
            'num_departments' => 0,
            'departments' => [],
          ];
        }
        $user_work_history[$wh->uid]['num_departments']++;
        $user_work_history[$wh->uid]['departments'][] = $wh;
      }
      
      // Get all job titles from normalized tables
      $job_titles_query = $connection->select('nfr_job_titles', 'jt')
        ->fields('jt');
      $job_titles_results = $job_titles_query->execute();
      
      $work_history_jobs = [];
      foreach ($job_titles_results as $job) {
        if (!isset($work_history_jobs[$job->work_history_id])) {
          $work_history_jobs[$job->work_history_id] = [];
        }
        $work_history_jobs[$job->work_history_id][] = $job;
      }
      
      // Get major incidents from normalized table
      $major_incidents_query = $connection->select('nfr_major_incidents', 'mi')
        ->fields('mi');
      $major_incidents_results = $major_incidents_query->execute();
      
      $user_major_incidents = [];
      foreach ($major_incidents_results as $incident) {
        if (!isset($user_major_incidents[$incident->uid])) {
          $user_major_incidents[$incident->uid] = [];
        }
        $user_major_incidents[$incident->uid][] = $incident;
      }
      
      // Get other employment from normalized table
      $other_employment_query = $connection->select('nfr_other_employment', 'oe')
        ->fields('oe');
      $other_employment_results = $other_employment_query->execute();
      
      $user_other_employment = [];
      foreach ($other_employment_results as $job) {
        if (!isset($user_other_employment[$job->uid])) {
          $user_other_employment[$job->uid] = [];
        }
        $user_other_employment[$job->uid][] = $job;
      }
      
      // Get cancer diagnoses from normalized table
      $cancer_diagnoses_query = $connection->select('nfr_cancer_diagnoses', 'cd')
        ->fields('cd');
      $cancer_diagnoses_results = $cancer_diagnoses_query->execute();
      
      $user_cancer_diagnoses = [];
      foreach ($cancer_diagnoses_results as $diagnosis) {
        if (!isset($user_cancer_diagnoses[$diagnosis->uid])) {
          $user_cancer_diagnoses[$diagnosis->uid] = [];
        }
        $user_cancer_diagnoses[$diagnosis->uid][] = $diagnosis;
      }
      
      // Get consent records
      $consent_query = $connection->select('nfr_consent', 'c')
        ->fields('c');
      $consent_results = $consent_query->execute();
      
      $user_consents = [];
      foreach ($consent_results as $consent) {
        $user_consents[$consent->uid] = $consent;
      }
      
      // Get section completion records
      $section_completion_query = $connection->select('nfr_section_completion', 'sc')
        ->fields('sc');
      $section_completion_results = $section_completion_query->execute();
      
      $user_section_completion = [];
      foreach ($section_completion_results as $completion) {
        if (!isset($user_section_completion[$completion->uid])) {
          $user_section_completion[$completion->uid] = [];
        }
        $user_section_completion[$completion->uid][$completion->section_number] = $completion;
      }
      
      $total_records = 0;
      $field_counts = [];
      $value_distributions = [];
      
      foreach ($results as $row) {
        $total_records++;
        $uid = $row->uid;
        $profile = $profiles[$uid] ?? NULL;
        
        // PROFILE FIELDS
        if ($profile) {
          if (!empty($profile->first_name)) {
            $field_counts['profile.first_name'] = ($field_counts['profile.first_name'] ?? 0) + 1;
          }
          if (!empty($profile->middle_name)) {
            $field_counts['profile.middle_name'] = ($field_counts['profile.middle_name'] ?? 0) + 1;
          }
          if (!empty($profile->last_name)) {
            $field_counts['profile.last_name'] = ($field_counts['profile.last_name'] ?? 0) + 1;
          }
          if (!empty($profile->date_of_birth)) {
            $field_counts['profile.date_of_birth'] = ($field_counts['profile.date_of_birth'] ?? 0) + 1;
          }
          if (!empty($profile->sex)) {
            $field_counts['profile.sex'] = ($field_counts['profile.sex'] ?? 0) + 1;
            $value_distributions['profile.sex'][$profile->sex] = ($value_distributions['profile.sex'][$profile->sex] ?? 0) + 1;
          }
          if (!empty($profile->ssn_last_4)) {
            $field_counts['profile.ssn_last_4'] = ($field_counts['profile.ssn_last_4'] ?? 0) + 1;
          }
          if (!empty($profile->country_of_birth)) {
            $field_counts['profile.country_of_birth'] = ($field_counts['profile.country_of_birth'] ?? 0) + 1;
            $value_distributions['profile.country_of_birth'][$profile->country_of_birth] = ($value_distributions['profile.country_of_birth'][$profile->country_of_birth] ?? 0) + 1;
          }
          if (!empty($profile->state_of_birth)) {
            $field_counts['profile.state_of_birth'] = ($field_counts['profile.state_of_birth'] ?? 0) + 1;
            $value_distributions['profile.state_of_birth'][$profile->state_of_birth] = ($value_distributions['profile.state_of_birth'][$profile->state_of_birth] ?? 0) + 1;
          }
          if (!empty($profile->city_of_birth)) {
            $field_counts['profile.city_of_birth'] = ($field_counts['profile.city_of_birth'] ?? 0) + 1;
          }
          if (!empty($profile->address_line1)) {
            $field_counts['profile.address_line1'] = ($field_counts['profile.address_line1'] ?? 0) + 1;
          }
          if (!empty($profile->address_line2)) {
            $field_counts['profile.address_line2'] = ($field_counts['profile.address_line2'] ?? 0) + 1;
          }
          if (!empty($profile->city)) {
            $field_counts['profile.city'] = ($field_counts['profile.city'] ?? 0) + 1;
          }
          if (!empty($profile->state)) {
            $field_counts['profile.state'] = ($field_counts['profile.state'] ?? 0) + 1;
            $value_distributions['profile.state'][$profile->state] = ($value_distributions['profile.state'][$profile->state] ?? 0) + 1;
          }
          if (!empty($profile->zip_code)) {
            $field_counts['profile.zip_code'] = ($field_counts['profile.zip_code'] ?? 0) + 1;
          }
          if (!empty($profile->alternate_email)) {
            $field_counts['profile.alternate_email'] = ($field_counts['profile.alternate_email'] ?? 0) + 1;
          }
          if (!empty($profile->mobile_phone)) {
            $field_counts['profile.mobile_phone'] = ($field_counts['profile.mobile_phone'] ?? 0) + 1;
          }
          if (!empty($profile->current_work_status)) {
            $field_counts['profile.current_work_status'] = ($field_counts['profile.current_work_status'] ?? 0) + 1;
            $value_distributions['profile.current_work_status'][$profile->current_work_status] = ($value_distributions['profile.current_work_status'][$profile->current_work_status] ?? 0) + 1;
          }
        }
        
        // QUESTIONNAIRE DIRECT COLUMNS
        if (!empty($row->race_other)) {
          $field_counts['demographics.race_other'] = ($field_counts['demographics.race_other'] ?? 0) + 1;
        }
        if (isset($row->military_service) && $row->military_service !== NULL) {
          $field_counts['military.military_service'] = ($field_counts['military.military_service'] ?? 0) + 1;
          $val = $row->military_service ? 'yes' : 'no';
          $value_distributions['military.military_service'][$val] = ($value_distributions['military.military_service'][$val] ?? 0) + 1;
        }
        if (!empty($row->military_branch)) {
          $field_counts['military.military_branch'] = ($field_counts['military.military_branch'] ?? 0) + 1;
          $value_distributions['military.military_branch'][$row->military_branch] = ($value_distributions['military.military_branch'][$row->military_branch] ?? 0) + 1;
        }
        if (isset($row->military_years) && $row->military_years > 0) {
          $field_counts['military.military_years'] = ($field_counts['military.military_years'] ?? 0) + 1;
          $val = $row->military_years;
          $value_distributions['military.military_years'][$val] = ($value_distributions['military.military_years'][$val] ?? 0) + 1;
        }
        if (isset($row->cancer_diagnosis) && $row->cancer_diagnosis !== NULL) {
          $field_counts['health.cancer_diagnosis'] = ($field_counts['health.cancer_diagnosis'] ?? 0) + 1;
          $val = $row->cancer_diagnosis ? 'yes' : 'no';
          $value_distributions['health.cancer_diagnosis'][$val] = ($value_distributions['health.cancer_diagnosis'][$val] ?? 0) + 1;
        }
        if (!empty($row->alcohol_use)) {
          $field_counts['lifestyle.alcohol_use'] = ($field_counts['lifestyle.alcohol_use'] ?? 0) + 1;
          $value_distributions['lifestyle.alcohol_use'][$row->alcohol_use] = ($value_distributions['lifestyle.alcohol_use'][$row->alcohol_use] ?? 0) + 1;
        }
        if (isset($row->had_other_jobs) && $row->had_other_jobs !== NULL) {
          $field_counts['other_employment.had_other_jobs'] = ($field_counts['other_employment.had_other_jobs'] ?? 0) + 1;
          $val = $row->had_other_jobs ? 'yes' : 'no';
          $value_distributions['other_employment.had_other_jobs'][$val] = ($value_distributions['other_employment.had_other_jobs'][$val] ?? 0) + 1;
        }
        
        // DEMOGRAPHICS (from direct columns)
        if (!empty($row->education_level)) {
          $field_counts['demographics.education_level'] = ($field_counts['demographics.education_level'] ?? 0) + 1;
          $val = $row->education_level;
          $value_distributions['demographics.education_level'][$val] = ($value_distributions['demographics.education_level'][$val] ?? 0) + 1;
        }
        if (!empty($row->marital_status)) {
          $field_counts['demographics.marital_status'] = ($field_counts['demographics.marital_status'] ?? 0) + 1;
          $val = $row->marital_status;
          $value_distributions['demographics.marital_status'][$val] = ($value_distributions['demographics.marital_status'][$val] ?? 0) + 1;
        }
        if (!empty($row->race_ethnicity)) {
          $race_data = json_decode($row->race_ethnicity, TRUE);
          if (is_array($race_data)) {
            $race_selections = [];
            foreach ($race_data as $key => $val) {
              if ($val !== 0 && $val !== '0' && !empty($val)) {
                $race_selections[] = $key;
              }
            }
            if (count($race_selections) > 0) {
              $field_counts['demographics.race_ethnicity'] = ($field_counts['demographics.race_ethnicity'] ?? 0) + 1;
              $race_str = implode(', ', $race_selections);
              $value_distributions['demographics.race_ethnicity'][$race_str] = ($value_distributions['demographics.race_ethnicity'][$race_str] ?? 0) + 1;
            }
          }
        }
        if (isset($row->height_inches) && $row->height_inches > 0) {
          $field_counts['demographics.height_inches'] = ($field_counts['demographics.height_inches'] ?? 0) + 1;
          $value_distributions['demographics.height_inches'][$row->height_inches] = ($value_distributions['demographics.height_inches'][$row->height_inches] ?? 0) + 1;
        }
        if (isset($row->weight_pounds) && $row->weight_pounds > 0) {
          $field_counts['demographics.weight_pounds'] = ($field_counts['demographics.weight_pounds'] ?? 0) + 1;
          $value_distributions['demographics.weight_pounds'][$row->weight_pounds] = ($value_distributions['demographics.weight_pounds'][$row->weight_pounds] ?? 0) + 1;
        }
        
        // WORK HISTORY (from normalized tables)
        $uid_work_history = $user_work_history[$uid] ?? NULL;
        if ($uid_work_history && $uid_work_history['num_departments'] > 0) {
          $field_counts['work_history.num_departments'] = ($field_counts['work_history.num_departments'] ?? 0) + 1;
          $val = $uid_work_history['num_departments'];
          $value_distributions['work_history.num_departments'][$val] = ($value_distributions['work_history.num_departments'][$val] ?? 0) + 1;
          
          // Process first department for field tracking
          $dept = $uid_work_history['departments'][0] ?? NULL;
          if ($dept) {
            if (!empty($dept->department_name)) {
              $field_counts['work_history.department_name'] = ($field_counts['work_history.department_name'] ?? 0) + 1;
            }
            if (!empty($dept->department_state)) {
              $field_counts['work_history.department_state'] = ($field_counts['work_history.department_state'] ?? 0) + 1;
              $value_distributions['work_history.department_state'][$dept->department_state] = ($value_distributions['work_history.department_state'][$dept->department_state] ?? 0) + 1;
            }
            if (!empty($dept->department_city)) {
              $field_counts['work_history.department_city'] = ($field_counts['work_history.department_city'] ?? 0) + 1;
            }
            if (!empty($dept->department_fdid)) {
              $field_counts['work_history.department_fdid'] = ($field_counts['work_history.department_fdid'] ?? 0) + 1;
            }
            if (!empty($dept->start_date)) {
              $field_counts['work_history.start_date'] = ($field_counts['work_history.start_date'] ?? 0) + 1;
            }
            if (!empty($dept->end_date)) {
              $field_counts['work_history.end_date'] = ($field_counts['work_history.end_date'] ?? 0) + 1;
            }
            if (isset($dept->is_current)) {
              $field_counts['work_history.is_current'] = ($field_counts['work_history.is_current'] ?? 0) + 1;
            }
            
            // Get jobs for this department
            $dept_jobs = $work_history_jobs[$dept->id] ?? [];
            if (!empty($dept_jobs)) {
              $job = $dept_jobs[0];
              if (!empty($job->job_title)) {
                $field_counts['work_history.job_title'] = ($field_counts['work_history.job_title'] ?? 0) + 1;
                $value_distributions['work_history.job_title'][$job->job_title] = ($value_distributions['work_history.job_title'][$job->job_title] ?? 0) + 1;
              }
              if (!empty($job->employment_type)) {
                $field_counts['work_history.employment_type'] = ($field_counts['work_history.employment_type'] ?? 0) + 1;
                $value_distributions['work_history.employment_type'][$job->employment_type] = ($value_distributions['work_history.employment_type'][$job->employment_type] ?? 0) + 1;
              }
              if (isset($job->responded_to_incidents)) {
                $field_counts['work_history.responded_incidents'] = ($field_counts['work_history.responded_incidents'] ?? 0) + 1;
                $val = $job->responded_to_incidents ? 'yes' : 'no';
                $value_distributions['work_history.responded_incidents'][$val] = ($value_distributions['work_history.responded_incidents'][$val] ?? 0) + 1;
              }
              if (!empty($job->incident_types)) {
                $field_counts['work_history.incident_types'] = ($field_counts['work_history.incident_types'] ?? 0) + 1;
              }
            }
          }
        }
        
        // EXPOSURE (from direct columns + nfr_major_incidents table)
        if (!empty($row->afff_used)) {
          $field_counts['exposure.afff_used'] = ($field_counts['exposure.afff_used'] ?? 0) + 1;
          $value_distributions['exposure.afff_used'][$row->afff_used] = ($value_distributions['exposure.afff_used'][$row->afff_used] ?? 0) + 1;
        }
        if (isset($row->afff_times) && $row->afff_times > 0) {
          $field_counts['exposure.afff_times'] = ($field_counts['exposure.afff_times'] ?? 0) + 1;
        }
        if (isset($row->afff_first_year) && $row->afff_first_year > 0) {
          $field_counts['exposure.afff_first_year'] = ($field_counts['exposure.afff_first_year'] ?? 0) + 1;
        }
        if (!empty($row->diesel_exhaust)) {
          $field_counts['exposure.diesel_exhaust'] = ($field_counts['exposure.diesel_exhaust'] ?? 0) + 1;
          $value_distributions['exposure.diesel_exhaust'][$row->diesel_exhaust] = ($value_distributions['exposure.diesel_exhaust'][$row->diesel_exhaust] ?? 0) + 1;
        }
        if (isset($row->major_incidents)) {
          $field_counts['exposure.major_incidents'] = ($field_counts['exposure.major_incidents'] ?? 0) + 1;
          $val = $row->major_incidents ? 'yes' : 'no';
          $value_distributions['exposure.major_incidents'][$val] = ($value_distributions['exposure.major_incidents'][$val] ?? 0) + 1;
        }
        if (!empty($row->chemical_activities)) {
          $chem = json_decode($row->chemical_activities, TRUE);
          if (is_array($chem) && count($chem) > 0) {
            $field_counts['exposure.chemical_activities'] = ($field_counts['exposure.chemical_activities'] ?? 0) + 1;
          }
        }
        
        // MAJOR INCIDENTS (from nfr_major_incidents table)
        $user_incidents = $user_major_incidents[$uid] ?? [];
        if (count($user_incidents) > 0) {
          $field_counts['exposure.major_incidents_count'] = ($field_counts['exposure.major_incidents_count'] ?? 0) + 1;
          $value_distributions['exposure.major_incidents_count'][count($user_incidents)] = ($value_distributions['exposure.major_incidents_count'][count($user_incidents)] ?? 0) + 1;
          
          $incident = $user_incidents[0];
          if (!empty($incident->description)) {
            $field_counts['exposure.major_incident_description'] = ($field_counts['exposure.major_incident_description'] ?? 0) + 1;
          }
          if (!empty($incident->incident_date)) {
            $field_counts['exposure.major_incident_date'] = ($field_counts['exposure.major_incident_date'] ?? 0) + 1;
          }
          if (!empty($incident->duration)) {
            $field_counts['exposure.major_incident_duration'] = ($field_counts['exposure.major_incident_duration'] ?? 0) + 1;
            $val = $incident->duration;
            $value_distributions['exposure.major_incident_duration'][$val] = ($value_distributions['exposure.major_incident_duration'][$val] ?? 0) + 1;
          }
        }
        
        // MILITARY (from direct columns)
        if (!empty($row->military_start_date)) {
          $field_counts['military.start_date'] = ($field_counts['military.start_date'] ?? 0) + 1;
        }
        if (!empty($row->military_end_date)) {
          $field_counts['military.end_date'] = ($field_counts['military.end_date'] ?? 0) + 1;
        }
        if (isset($row->military_currently_serving)) {
          $field_counts['military.currently_serving'] = ($field_counts['military.currently_serving'] ?? 0) + 1;
          $val = $row->military_currently_serving ? 'yes' : 'no';
          $value_distributions['military.currently_serving'][$val] = ($value_distributions['military.currently_serving'][$val] ?? 0) + 1;
        }
        if (!empty($row->military_was_firefighter)) {
          $field_counts['military.was_firefighter'] = ($field_counts['military.was_firefighter'] ?? 0) + 1;
          $value_distributions['military.was_firefighter'][$row->military_was_firefighter] = ($value_distributions['military.was_firefighter'][$row->military_was_firefighter] ?? 0) + 1;
        }
        
        // OTHER EMPLOYMENT (from nfr_other_employment table + JSON column)
        $user_other_jobs = $user_other_employment[$uid] ?? [];
        if (count($user_other_jobs) > 0) {
          $field_counts['other_employment.jobs_count_table'] = ($field_counts['other_employment.jobs_count_table'] ?? 0) + 1;
          $value_distributions['other_employment.jobs_count_table'][count($user_other_jobs)] = ($value_distributions['other_employment.jobs_count_table'][count($user_other_jobs)] ?? 0) + 1;
          
          $job = $user_other_jobs[0];
          if (!empty($job->occupation)) {
            $field_counts['other_employment.occupation'] = ($field_counts['other_employment.occupation'] ?? 0) + 1;
            $value_distributions['other_employment.occupation'][$job->occupation] = ($value_distributions['other_employment.occupation'][$job->occupation] ?? 0) + 1;
          }
          if (!empty($job->industry)) {
            $field_counts['other_employment.industry'] = ($field_counts['other_employment.industry'] ?? 0) + 1;
            $value_distributions['other_employment.industry'][$job->industry] = ($value_distributions['other_employment.industry'][$job->industry] ?? 0) + 1;
          }
          if (!empty($job->start_year)) {
            $field_counts['other_employment.start_year'] = ($field_counts['other_employment.start_year'] ?? 0) + 1;
          }
          if (!empty($job->end_year)) {
            $field_counts['other_employment.end_year'] = ($field_counts['other_employment.end_year'] ?? 0) + 1;
          }
          if (!empty($job->exposures)) {
            $field_counts['other_employment.exposures'] = ($field_counts['other_employment.exposures'] ?? 0) + 1;
            $val = $job->exposures;
            $value_distributions['other_employment.exposures'][$val] = ($value_distributions['other_employment.exposures'][$val] ?? 0) + 1;
          }
          if (!empty($job->exposures_other)) {
            $field_counts['other_employment.exposures_other'] = ($field_counts['other_employment.exposures_other'] ?? 0) + 1;
          }
        }
        
        // OTHER EMPLOYMENT (from JSON column - keep existing logic for compatibility)
        if (!empty($row->other_employment_data)) {
          $other_employment = json_decode($row->other_employment_data, TRUE);
          if (is_array($other_employment) && !empty($other_employment['had_other_jobs'])) {
            $field_counts['other_employment.had_other_jobs'] = ($field_counts['other_employment.had_other_jobs'] ?? 0) + 1;
            $val = $other_employment['had_other_jobs'];
            $value_distributions['other_employment.had_other_jobs'][$val] = ($value_distributions['other_employment.had_other_jobs'][$val] ?? 0) + 1;
          }
          if (isset($other_employment['jobs']) && is_array($other_employment['jobs']) && count($other_employment['jobs']) > 0) {
            $field_counts['other_employment.jobs_count'] = ($field_counts['other_employment.jobs_count'] ?? 0) + 1;
            $job = $other_employment['jobs'][0];
            if (!empty($job['job_title'])) {
              $field_counts['other_employment.job_title'] = ($field_counts['other_employment.job_title'] ?? 0) + 1;
            }
            if (!empty($job['had_exposure'])) {
              $field_counts['other_employment.had_exposure'] = ($field_counts['other_employment.had_exposure'] ?? 0) + 1;
              $val = $job['had_exposure'];
              $value_distributions['other_employment.had_exposure'][$val] = ($value_distributions['other_employment.had_exposure'][$val] ?? 0) + 1;
            }
          }
        }
        
        // PPE (from direct columns)
        $ppe_items = [
          'scba' => 'ppe_scba',
          'turnout_coat' => 'ppe_turnout_coat',
          'turnout_pants' => 'ppe_turnout_pants',
          'gloves' => 'ppe_gloves',
          'helmet' => 'ppe_helmet',
          'boots' => 'ppe_boots',
          'nomex_hood' => 'ppe_nomex_hood',
          'wildland_clothing' => 'ppe_wildland_clothing',
        ];
        
        foreach ($ppe_items as $item_key => $column_prefix) {
          $ever_used_col = $column_prefix . '_ever_used';
          $year_col = $column_prefix . '_year_started';
          
          if (isset($row->$ever_used_col) && $row->$ever_used_col !== NULL) {
            $field_counts["ppe.{$item_key}.ever_used"] = ($field_counts["ppe.{$item_key}.ever_used"] ?? 0) + 1;
            $value_distributions["ppe.{$item_key}.ever_used"][$row->$ever_used_col] = ($value_distributions["ppe.{$item_key}.ever_used"][$row->$ever_used_col] ?? 0) + 1;
          }
          if (isset($row->$year_col) && $row->$year_col > 0) {
            $field_counts["ppe.{$item_key}.year_started"] = ($field_counts["ppe.{$item_key}.year_started"] ?? 0) + 1;
          }
        }
        
        // SCBA scenarios
        if (isset($row->ppe_scba_during_suppression) && $row->ppe_scba_during_suppression !== NULL) {
          $field_counts["ppe.scba_during_suppression"] = ($field_counts["ppe.scba_during_suppression"] ?? 0) + 1;
          $value_distributions["ppe.scba_during_suppression"][$row->ppe_scba_during_suppression] = ($value_distributions["ppe.scba_during_suppression"][$row->ppe_scba_during_suppression] ?? 0) + 1;
        }
        if (isset($row->ppe_scba_during_overhaul) && $row->ppe_scba_during_overhaul !== NULL) {
          $field_counts["ppe.scba_during_overhaul"] = ($field_counts["ppe.scba_during_overhaul"] ?? 0) + 1;
          $value_distributions["ppe.scba_during_overhaul"][$row->ppe_scba_during_overhaul] = ($value_distributions["ppe.scba_during_overhaul"][$row->ppe_scba_during_overhaul] ?? 0) + 1;
        }
        
        // DECONTAMINATION (from direct columns)
        $decon_fields = [
          'washed_hands_face' => 'decon_washed_hands_face',
          'changed_gear_at_scene' => 'decon_changed_gear_at_scene',
          'showered_at_station' => 'decon_showered_at_station',
          'laundered_gear' => 'decon_laundered_gear',
          'used_wet_wipes' => 'decon_used_wet_wipes',
        ];
        
        foreach ($decon_fields as $key => $column) {
          if (isset($row->$column) && $row->$column !== NULL) {
            $field_counts["decontamination.{$key}"] = ($field_counts["decontamination.{$key}"] ?? 0) + 1;
            $value_distributions["decontamination.{$key}"][$row->$column] = ($value_distributions["decontamination.{$key}"][$row->$column] ?? 0) + 1;
          }
        }
        
        if (isset($row->decon_department_had_sops) && $row->decon_department_had_sops !== NULL) {
          $field_counts['decontamination.department_had_sops'] = ($field_counts['decontamination.department_had_sops'] ?? 0) + 1;
          $value_distributions['decontamination.department_had_sops'][$row->decon_department_had_sops] = ($value_distributions['decontamination.department_had_sops'][$row->decon_department_had_sops] ?? 0) + 1;
        }
        if (isset($row->decon_sops_year_implemented) && $row->decon_sops_year_implemented > 0) {
          $field_counts['decontamination.sop_year_implemented'] = ($field_counts['decontamination.sop_year_implemented'] ?? 0) + 1;
        }
        
        // HEALTH (from direct columns + nfr_cancer_diagnoses table)
        $health_conditions = ['heart_disease', 'copd', 'asthma', 'diabetes'];
        foreach ($health_conditions as $condition) {
          $column = "health_{$condition}";
          if (isset($row->$column) && $row->$column !== NULL) {
            $field_counts["health.{$condition}"] = ($field_counts["health.{$condition}"] ?? 0) + 1;
            $value_distributions["health.{$condition}"][$row->$column ? 'yes' : 'no'] = ($value_distributions["health.{$condition}"][$row->$column ? 'yes' : 'no'] ?? 0) + 1;
          }
        }
        
        if (!empty($row->family_cancer_history)) {
          $family_history = json_decode($row->family_cancer_history, TRUE);
          if (is_array($family_history) && count($family_history) > 0) {
            $field_counts['health.family_cancer_history'] = ($field_counts['health.family_cancer_history'] ?? 0) + 1;
          }
        }
        
        // CANCER DIAGNOSES (from nfr_cancer_diagnoses table)
        $user_cancers = $user_cancer_diagnoses[$uid] ?? [];
        if (count($user_cancers) > 0) {
          $field_counts['health.cancer_count_table'] = ($field_counts['health.cancer_count_table'] ?? 0) + 1;
          $value_distributions['health.cancer_count_table'][count($user_cancers)] = ($value_distributions['health.cancer_count_table'][count($user_cancers)] ?? 0) + 1;
          
          $diagnosis = $user_cancers[0];
          if (!empty($diagnosis->cancer_type)) {
            $field_counts['health.cancer_type_table'] = ($field_counts['health.cancer_type_table'] ?? 0) + 1;
            $val = $diagnosis->cancer_type;
            $value_distributions['health.cancer_type_table'][$val] = ($value_distributions['health.cancer_type_table'][$val] ?? 0) + 1;
          }
          if (!empty($diagnosis->year_diagnosed)) {
            $field_counts['health.year_diagnosed_table'] = ($field_counts['health.year_diagnosed_table'] ?? 0) + 1;
          }
        }
        
        // CONSENT (from nfr_consent table)
        $consent = $user_consents[$uid] ?? NULL;
        if ($consent) {
          if (isset($consent->consented_to_participate)) {
            $field_counts['consent.participate'] = ($field_counts['consent.participate'] ?? 0) + 1;
            $val = $consent->consented_to_participate ? 'yes' : 'no';
            $value_distributions['consent.participate'][$val] = ($value_distributions['consent.participate'][$val] ?? 0) + 1;
          }
          if (isset($consent->consented_to_registry_linkage)) {
            $field_counts['consent.registry_linkage'] = ($field_counts['consent.registry_linkage'] ?? 0) + 1;
            $val = $consent->consented_to_registry_linkage ? 'yes' : 'no';
            $value_distributions['consent.registry_linkage'][$val] = ($value_distributions['consent.registry_linkage'][$val] ?? 0) + 1;
          }
          if (!empty($consent->electronic_signature)) {
            $field_counts['consent.electronic_signature'] = ($field_counts['consent.electronic_signature'] ?? 0) + 1;
          }
          if (!empty($consent->consent_ip_address)) {
            $field_counts['consent.ip_address'] = ($field_counts['consent.ip_address'] ?? 0) + 1;
          }
          if (!empty($consent->consent_timestamp)) {
            $field_counts['consent.timestamp'] = ($field_counts['consent.timestamp'] ?? 0) + 1;
          }
        }
        
        // SECTION COMPLETION (from nfr_section_completion table)
        $sections_completed = $user_section_completion[$uid] ?? [];
        if (count($sections_completed) > 0) {
          $field_counts['progress.sections_completed_count'] = ($field_counts['progress.sections_completed_count'] ?? 0) + 1;
          $value_distributions['progress.sections_completed_count'][count($sections_completed)] = ($value_distributions['progress.sections_completed_count'][count($sections_completed)] ?? 0) + 1;
          
          for ($i = 1; $i <= 9; $i++) {
            if (isset($sections_completed[$i]) && $sections_completed[$i]->completed) {
              $field_counts["progress.section_{$i}_completed"] = ($field_counts["progress.section_{$i}_completed"] ?? 0) + 1;
              if (!empty($sections_completed[$i]->completed_at)) {
                $field_counts["progress.section_{$i}_completed_at"] = ($field_counts["progress.section_{$i}_completed_at"] ?? 0) + 1;
              }
            }
          }
        }
        
        // LIFESTYLE (from direct columns and smoking_history JSON)
        if (!empty($row->smoking_history)) {
          $smoking = json_decode($row->smoking_history, TRUE);
          if (is_array($smoking)) {
            // Cigarette smoking status - REQUIRED field
            if (!empty($smoking['smoking_status'])) {
              $field_counts['lifestyle.smoking_status'] = ($field_counts['lifestyle.smoking_status'] ?? 0) + 1;
              $val = $smoking['smoking_status'];
              $value_distributions['lifestyle.smoking_status'][$val] = ($value_distributions['lifestyle.smoking_status'][$val] ?? 0) + 1;
            }
            
            // Cigarette smoking details (conditional on smoking_status)
            if (!empty($smoking['smoking_age_started'])) {
              $field_counts['lifestyle.smoking_age_started'] = ($field_counts['lifestyle.smoking_age_started'] ?? 0) + 1;
            }
            if (!empty($smoking['smoking_age_stopped'])) {
              $field_counts['lifestyle.smoking_age_stopped'] = ($field_counts['lifestyle.smoking_age_stopped'] ?? 0) + 1;
            }
            if (!empty($smoking['cigarettes_per_day'])) {
              $field_counts['lifestyle.cigarettes_per_day'] = ($field_counts['lifestyle.cigarettes_per_day'] ?? 0) + 1;
              $val = $smoking['cigarettes_per_day'];
              $value_distributions['lifestyle.cigarettes_per_day'][$val] = ($value_distributions['lifestyle.cigarettes_per_day'][$val] ?? 0) + 1;
            }
            
            // Other tobacco types (cigars, pipes, ecigs, smokeless)
            $tobacco_types = [
              'cigars' => 'cigars',
              'pipes' => 'pipes',
              'ecigs' => 'ecigs',
              'smokeless' => 'smokeless',
            ];
            foreach ($tobacco_types as $type => $key) {
              // Ever used status (never/former/current)
              if (!empty($smoking["{$key}_ever_used"])) {
                $field_counts["lifestyle.{$key}_ever_used"] = ($field_counts["lifestyle.{$key}_ever_used"] ?? 0) + 1;
                $val = $smoking["{$key}_ever_used"];
                $value_distributions["lifestyle.{$key}_ever_used"][$val] = ($value_distributions["lifestyle.{$key}_ever_used"][$val] ?? 0) + 1;
              }
              // Age started (conditional)
              if (!empty($smoking["{$key}_age_started"])) {
                $field_counts["lifestyle.{$key}_age_started"] = ($field_counts["lifestyle.{$key}_age_started"] ?? 0) + 1;
              }
              // Age stopped (conditional)
              if (!empty($smoking["{$key}_age_stopped"])) {
                $field_counts["lifestyle.{$key}_age_stopped"] = ($field_counts["lifestyle.{$key}_age_stopped"] ?? 0) + 1;
              }
            }
          }
        }
        
        // Alcohol use - REQUIRED field (direct column)
        if (!empty($row->alcohol_use)) {
          $field_counts['lifestyle.alcohol_frequency'] = ($field_counts['lifestyle.alcohol_frequency'] ?? 0) + 1;
          $value_distributions['lifestyle.alcohol_frequency'][$row->alcohol_use] = ($value_distributions['lifestyle.alcohol_frequency'][$row->alcohol_use] ?? 0) + 1;
        }
        
        // Physical activity - REQUIRED field (direct column)
        if (isset($row->physical_activity_days)) {
          $field_counts['lifestyle.physical_activity_days'] = ($field_counts['lifestyle.physical_activity_days'] ?? 0) + 1;
          $value_distributions['lifestyle.physical_activity_days'][$row->physical_activity_days] = ($value_distributions['lifestyle.physical_activity_days'][$row->physical_activity_days] ?? 0) + 1;
        }
        
        // Sleep hours - REQUIRED field (direct column)
        if (isset($row->sleep_hours_per_night) && $row->sleep_hours_per_night > 0) {
          $field_counts['lifestyle.sleep_hours_per_night'] = ($field_counts['lifestyle.sleep_hours_per_night'] ?? 0) + 1;
          $value_distributions['lifestyle.sleep_hours_per_night'][$row->sleep_hours_per_night] = ($value_distributions['lifestyle.sleep_hours_per_night'][$row->sleep_hours_per_night] ?? 0) + 1;
        }
        
        // Sleep quality - REQUIRED field (direct column)
        if (!empty($row->sleep_quality)) {
          $field_counts['lifestyle.sleep_quality'] = ($field_counts['lifestyle.sleep_quality'] ?? 0) + 1;
          $value_distributions['lifestyle.sleep_quality'][$row->sleep_quality] = ($value_distributions['lifestyle.sleep_quality'][$row->sleep_quality] ?? 0) + 1;
        }
        
        // Sleep disorders - checkboxes (direct column, JSON)
        if (!empty($row->sleep_disorders)) {
          $disorders = json_decode($row->sleep_disorders, TRUE);
          if (is_array($disorders) && count(array_filter($disorders)) > 0) {
            $field_counts['lifestyle.sleep_disorders'] = ($field_counts['lifestyle.sleep_disorders'] ?? 0) + 1;
            $disorder_list = implode(', ', array_keys(array_filter($disorders)));
            $value_distributions['lifestyle.sleep_disorders'][$disorder_list] = ($value_distributions['lifestyle.sleep_disorders'][$disorder_list] ?? 0) + 1;
          }
        }
      }
      
      // Sort fields
      ksort($field_counts);
      
      // Calculate summary statistics
      $total_fields = count($field_counts);
      $fields_at_100 = 0;
      $fields_below_100 = [];
      
      foreach ($field_counts as $field => $count) {
        $pct = round(($count / $total_records) * 100, 1);
        if ($pct >= 100.0) {
          $fields_at_100++;
        }
        else {
          $fields_below_100[] = ['field' => $field, 'count' => $count, 'pct' => $pct];
        }
      }
      
      // =============================================================================
      // CALCULATE TABLE-LEVEL STATISTICS
      // =============================================================================
      $table_stats = [];
      
      // Helper function to calculate record completeness
      // $non_text_fields: array of field names that are DATE, INT, BOOLEAN (don't check != '')
      $calculate_record_completeness = function($table_name, $tracked_columns, $total_records_count, $non_text_fields = []) use ($connection) {
        if ($total_records_count == 0 || empty($tracked_columns)) {
          return ['complete_records' => 0, 'record_completeness_pct' => 0];
        }
        
        // Build WHERE clause to check all tracked columns are NOT NULL
        // For text fields also check != '' but for DATE/INT/BOOLEAN only check IS NOT NULL
        $where_conditions = [];
        foreach ($tracked_columns as $col) {
          if (in_array($col, $non_text_fields)) {
            // DATE, INT, BOOLEAN fields: only check IS NOT NULL
            $where_conditions[] = "$col IS NOT NULL";
          } else {
            // Text fields: check IS NOT NULL AND != ''
            $where_conditions[] = "$col IS NOT NULL AND $col != ''";
          }
        }
        $where_clause = implode(' AND ', $where_conditions);
        
        $complete_records = (int) $connection->query(
          "SELECT COUNT(*) FROM {{$table_name}} WHERE " . $where_clause
        )->fetchField();
        
        $record_completeness_pct = round(($complete_records / $total_records_count) * 100, 1);
        
        return [
          'complete_records' => $complete_records,
          'record_completeness_pct' => $record_completeness_pct
        ];
      };
      
      // Helper function to calculate % of users who have records in a table
      $calculate_user_coverage = function($table_name, $total_users, $uid_column = 'uid') use ($connection) {
        if ($total_users == 0) {
          return 0;
        }
        $users_with_records = (int) $connection->query(
          "SELECT COUNT(DISTINCT {$uid_column}) FROM {{$table_name}}"
        )->fetchField();
        return round(($users_with_records / $total_users) * 100, 1);
      };
      
      // Profile table
      $profile_field_count = 0;
      $profile_complete_fields = 0;
      foreach ($field_counts as $field => $count) {
        if (strpos($field, 'profile.') === 0) {
          $profile_field_count++;
          if ($count >= $total_records) {
            $profile_complete_fields++;
          }
        }
      }
      $profile_tracked_cols = ['first_name', 'last_name', 'date_of_birth', 'sex', 'address_line1', 'city', 'state', 'zip_code', 'mobile_phone'];
      $profile_record_stats = $calculate_record_completeness('nfr_user_profile', $profile_tracked_cols, count($profiles));
      
      $table_stats['nfr_user_profile'] = [
        'record_count' => count($profiles),
        'field_count' => 26,
        'tracked_fields' => $profile_field_count,
        'complete_fields' => $profile_complete_fields,
        'completeness_pct' => $profile_field_count > 0 ? round(($profile_complete_fields / $profile_field_count) * 100, 1) : 0,
        'complete_records' => $profile_record_stats['complete_records'],
        'record_completeness_pct' => $profile_record_stats['record_completeness_pct'],
      ];
      
      // Questionnaire direct columns
      $quest_direct_count = 0;
      $quest_direct_complete = 0;
      foreach ($field_counts as $field => $count) {
        if (strpos($field, 'questionnaire.') === 0 || strpos($field, 'demographics.') === 0) {
          $quest_direct_count++;
          if ($count >= $total_records) {
            $quest_direct_complete++;
          }
        }
      }
      $quest_tracked_cols = ['race_ethnicity', 'smoking_history', 'alcohol_use', 'education_level', 'marital_status', 'height_inches', 'weight_pounds'];
      $quest_record_stats = $calculate_record_completeness('nfr_questionnaire', $quest_tracked_cols, $total_records);
      
      $table_stats['nfr_questionnaire_direct'] = [
        'record_count' => $total_records,
        'field_count' => 9,
        'tracked_fields' => $quest_direct_count,
        'complete_fields' => $quest_direct_complete,
        'completeness_pct' => $quest_direct_count > 0 ? round(($quest_direct_complete / $quest_direct_count) * 100, 1) : 0,
        'complete_records' => $quest_record_stats['complete_records'],
        'record_completeness_pct' => $quest_record_stats['record_completeness_pct'],
      ];
      
      // Work history tables
      $wh_records = (int) $connection->query("SELECT COUNT(*) FROM {nfr_work_history}")->fetchField();
      $wh_tracked_cols = ['department_name', 'department_state', 'department_city', 'department_fdid', 'start_date', 'is_current'];
      $wh_non_text_fields = ['start_date', 'is_current']; // DATE and BOOLEAN fields
      $wh_record_stats = $calculate_record_completeness('nfr_work_history', $wh_tracked_cols, $wh_records, $wh_non_text_fields);
      
      $table_stats['nfr_work_history'] = [
        'record_count' => $wh_records,
        'field_count' => 11,
        'tracked_fields' => 6,
        'completeness_pct' => $calculate_user_coverage('nfr_work_history', $total_records),
        'record_completeness_pct' => $wh_record_stats['record_completeness_pct'],
      ];
      
      // Other tables
      $major_incidents_count = (int) $connection->query("SELECT COUNT(*) FROM {nfr_major_incidents}")->fetchField();
      $major_incidents_tracked_cols = ['description', 'incident_date', 'duration'];
      $major_incidents_non_text_fields = ['incident_date']; // DATE field
      $major_incidents_record_stats = $calculate_record_completeness('nfr_major_incidents', $major_incidents_tracked_cols, $major_incidents_count, $major_incidents_non_text_fields);
      
      $table_stats['nfr_major_incidents'] = [
        'record_count' => $major_incidents_count,
        'field_count' => 7,
        'tracked_fields' => 3,
        'completeness_pct' => $calculate_user_coverage('nfr_major_incidents', $total_records),
        'record_completeness_pct' => $major_incidents_record_stats['record_completeness_pct'],
      ];
      
      $other_employment_count = (int) $connection->query("SELECT COUNT(*) FROM {nfr_other_employment}")->fetchField();
      $other_employment_tracked_cols = ['occupation', 'industry', 'start_year', 'exposures'];
      $other_employment_record_stats = $calculate_record_completeness('nfr_other_employment', $other_employment_tracked_cols, $other_employment_count);
      
      $table_stats['nfr_other_employment'] = [
        'record_count' => $other_employment_count,
        'field_count' => 9,
        'tracked_fields' => 4,
        'completeness_pct' => $calculate_user_coverage('nfr_other_employment', $total_records),
        'record_completeness_pct' => $other_employment_record_stats['record_completeness_pct'],
      ];
      
      $cancer_diagnoses_count = (int) $connection->query("SELECT COUNT(*) FROM {nfr_cancer_diagnoses}")->fetchField();
      $cancer_diagnoses_tracked_cols = ['cancer_type', 'year_diagnosed'];
      $cancer_diagnoses_non_text_fields = ['year_diagnosed']; // INT field
      $cancer_diagnoses_record_stats = $calculate_record_completeness('nfr_cancer_diagnoses', $cancer_diagnoses_tracked_cols, $cancer_diagnoses_count, $cancer_diagnoses_non_text_fields);
      
      $table_stats['nfr_cancer_diagnoses'] = [
        'record_count' => $cancer_diagnoses_count,
        'field_count' => 6,
        'tracked_fields' => 2,
        'completeness_pct' => $calculate_user_coverage('nfr_cancer_diagnoses', $total_records),
        'record_completeness_pct' => $cancer_diagnoses_record_stats['record_completeness_pct'],
      ];
      
      $family_cancer_count = (int) $connection->query("SELECT COUNT(*) FROM {nfr_family_cancer_history}")->fetchField();
      $family_cancer_tracked_cols = ['relationship', 'cancer_type', 'age_at_diagnosis'];
      $family_cancer_non_text_fields = ['age_at_diagnosis']; // INT field
      $family_cancer_record_stats = $calculate_record_completeness('nfr_family_cancer_history', $family_cancer_tracked_cols, $family_cancer_count, $family_cancer_non_text_fields);
      
      $table_stats['nfr_family_cancer_history'] = [
        'record_count' => $family_cancer_count,
        'field_count' => 7,
        'tracked_fields' => 3,
        'completeness_pct' => $calculate_user_coverage('nfr_family_cancer_history', $total_records),
        'record_completeness_pct' => $family_cancer_record_stats['record_completeness_pct'],
      ];
      
      $consent_count = (int) $connection->query("SELECT COUNT(*) FROM {nfr_consent}")->fetchField();
      $consent_tracked_cols = ['consented_to_participate', 'electronic_signature', 'consent_timestamp'];
      $consent_non_text_fields = ['consented_to_participate', 'consent_timestamp']; // BOOLEAN and TIMESTAMP fields
      $consent_record_stats = $calculate_record_completeness('nfr_consent', $consent_tracked_cols, $consent_count, $consent_non_text_fields);
      
      $table_stats['nfr_consent'] = [
        'record_count' => $consent_count,
        'field_count' => 7,
        'tracked_fields' => 3,
        'completeness_pct' => $calculate_user_coverage('nfr_consent', $total_records),
        'record_completeness_pct' => $consent_record_stats['record_completeness_pct'],
      ];
      
      $section_completion_count = (int) $connection->query("SELECT COUNT(*) FROM {nfr_section_completion}")->fetchField();
      $section_completion_tracked_cols = ['section_number', 'completed'];
      $section_completion_record_stats = $calculate_record_completeness('nfr_section_completion', $section_completion_tracked_cols, $section_completion_count);
      
      $table_stats['nfr_section_completion'] = [
        'record_count' => $section_completion_count,
        'field_count' => 5,
        'tracked_fields' => 2,
        'completeness_pct' => $calculate_user_coverage('nfr_section_completion', $total_records),
        'record_completeness_pct' => $section_completion_record_stats['record_completeness_pct'],
      ];
      
      // Add remaining NFR tables with counts from database
      $questionnaire_count = (int) $connection->query("SELECT COUNT(*) FROM {nfr_questionnaire}")->fetchField();
      $questionnaire_all_tracked_cols = ['race_ethnicity', 'height_inches', 'weight_pounds', 'military_service', 'cancer_diagnosis', 'smoking_history', 'alcohol_use', 'education_level', 'marital_status', 'afff_used', 'diesel_exhaust', 'major_incidents'];
      $questionnaire_non_text_fields = ['height_inches', 'weight_pounds', 'military_service', 'cancer_diagnosis', 'major_incidents']; // INT and BOOLEAN fields
      $questionnaire_all_record_stats = $calculate_record_completeness('nfr_questionnaire', $questionnaire_all_tracked_cols, $questionnaire_count, $questionnaire_non_text_fields);
      
      $table_stats['nfr_questionnaire'] = [
        'record_count' => $questionnaire_count,
        'field_count' => 63,
        'tracked_fields' => 12,
        'completeness_pct' => $total_records > 0 ? round(($questionnaire_count / $total_records) * 100, 1) : 0,
        'record_completeness_pct' => $questionnaire_all_record_stats['record_completeness_pct'],
      ];
      
      $job_titles_count = (int) $connection->query("SELECT COUNT(*) FROM {nfr_job_titles}")->fetchField();
      $job_titles_tracked_cols = ['job_title', 'employment_type', 'responded_to_incidents'];
      $job_titles_record_stats = $calculate_record_completeness('nfr_job_titles', $job_titles_tracked_cols, $job_titles_count);
      
      $table_stats['nfr_job_titles'] = [
        'record_count' => $job_titles_count,
        'field_count' => 7,
        'tracked_fields' => 3,
        'completeness_pct' => $total_records > 0 ? round((int)$connection->query("SELECT COUNT(DISTINCT wh.uid) FROM {nfr_job_titles} jt INNER JOIN {nfr_work_history} wh ON jt.work_history_id = wh.id")->fetchField() / $total_records * 100, 1) : 0,
        'record_completeness_pct' => $job_titles_record_stats['record_completeness_pct'],
      ];
      
      $incident_frequency_count = (int) $connection->query("SELECT COUNT(*) FROM {nfr_incident_frequency}")->fetchField();
      $incident_frequency_tracked_cols = ['incident_type', 'frequency'];
      $incident_frequency_record_stats = $calculate_record_completeness('nfr_incident_frequency', $incident_frequency_tracked_cols, $incident_frequency_count);
      
      $table_stats['nfr_incident_frequency'] = [
        'record_count' => $incident_frequency_count,
        'field_count' => 6,
        'tracked_fields' => 2,
        'completeness_pct' => $total_records > 0 ? round((int)$connection->query("SELECT COUNT(DISTINCT wh.uid) FROM {nfr_incident_frequency} freq INNER JOIN {nfr_job_titles} jt ON freq.job_title_id = jt.id INNER JOIN {nfr_work_history} wh ON jt.work_history_id = wh.id")->fetchField() / $total_records * 100, 1) : 0,
        'record_completeness_pct' => $incident_frequency_record_stats['record_completeness_pct'],
      ];
      
      // =============================================================================
      // CALCULATE PROFILE DATASET SUMMARY
      // =============================================================================
      $profile_summary = [
        'total_profiles' => count($profiles),
        'sex_distribution' => [],
        'state_distribution' => [],
        'country_distribution' => [],
        'work_status_distribution' => [],
        'age_groups' => [],
      ];
      
      foreach ($profiles as $profile) {
        // Sex distribution
        if (!empty($profile->sex)) {
          $profile_summary['sex_distribution'][$profile->sex] = ($profile_summary['sex_distribution'][$profile->sex] ?? 0) + 1;
        }
        
        // State distribution
        if (!empty($profile->state)) {
          $profile_summary['state_distribution'][$profile->state] = ($profile_summary['state_distribution'][$profile->state] ?? 0) + 1;
        }
        
        // Country distribution
        if (!empty($profile->country_of_birth)) {
          $profile_summary['country_distribution'][$profile->country_of_birth] = ($profile_summary['country_distribution'][$profile->country_of_birth] ?? 0) + 1;
        }
        
        // Work status
        if (!empty($profile->current_work_status)) {
          $profile_summary['work_status_distribution'][$profile->current_work_status] = ($profile_summary['work_status_distribution'][$profile->current_work_status] ?? 0) + 1;
        }
        
        // Age groups (calculate from DOB if available)
        if (!empty($profile->date_of_birth)) {
          $dob = strtotime($profile->date_of_birth);
          if ($dob) {
            $age = floor((time() - $dob) / (365.25 * 24 * 60 * 60));
            if ($age < 30) {
              $age_group = 'Under 30';
            } elseif ($age < 40) {
              $age_group = '30-39';
            } elseif ($age < 50) {
              $age_group = '40-49';
            } elseif ($age < 60) {
              $age_group = '50-59';
            } elseif ($age < 70) {
              $age_group = '60-69';
            } else {
              $age_group = '70+';
            }
            $profile_summary['age_groups'][$age_group] = ($profile_summary['age_groups'][$age_group] ?? 0) + 1;
          }
        }
      }
      
      // =============================================================================
      // CALCULATE SECTION DATASET SUMMARIES
      // =============================================================================
      $section_summaries = [
        'demographics' => ['fields' => 0, 'complete' => 0, 'users_started' => 0, 'users_completed' => 0],
        'work_history' => ['fields' => 0, 'complete' => 0, 'users_started' => 0, 'users_completed' => 0],
        'exposure' => ['fields' => 0, 'complete' => 0, 'users_started' => 0, 'users_completed' => 0],
        'military' => ['fields' => 0, 'complete' => 0, 'users_started' => 0, 'users_completed' => 0],
        'other_employment' => ['fields' => 0, 'complete' => 0, 'users_started' => 0, 'users_completed' => 0],
        'ppe' => ['fields' => 0, 'complete' => 0, 'users_started' => 0, 'users_completed' => 0],
        'decontamination' => ['fields' => 0, 'complete' => 0, 'users_started' => 0, 'users_completed' => 0],
        'health' => ['fields' => 0, 'complete' => 0, 'users_started' => 0, 'users_completed' => 0],
        'lifestyle' => ['fields' => 0, 'complete' => 0, 'users_started' => 0, 'users_completed' => 0],
      ];
      
      foreach ($field_counts as $field => $count) {
        foreach ($section_summaries as $section => &$summary) {
          if (strpos($field, $section . '.') === 0) {
            $summary['fields']++;
            if ($count >= $total_records) {
              $summary['complete']++;
            }
            if ($count > 0) {
              $summary['users_started'] = max($summary['users_started'], $count);
            }
          }
        }
      }
      
      // Calculate section started and completed from nfr_section_completion table
      for ($i = 1; $i <= 9; $i++) {
        $started_count = 0;
        $completed_count = 0;
        foreach ($user_section_completion as $uid => $sections) {
          if (isset($sections[$i])) {
            $started_count++;
            if ($sections[$i]->completed) {
              $completed_count++;
            }
          }
        }
        $section_keys = ['demographics', 'work_history', 'exposure', 'military', 'other_employment', 'ppe', 'decontamination', 'health', 'lifestyle'];
        if (isset($section_keys[$i - 1])) {
          // Use section_completion data if available, otherwise use field tracking data
          if ($started_count > 0) {
            $section_summaries[$section_keys[$i - 1]]['users_started'] = $started_count;
            $section_summaries[$section_keys[$i - 1]]['users_completed'] = $completed_count;
          }
        }
      }
      
      // Build HTML output with Chart.js
      $output = '<div class="container-fluid">';
      
      // Page Header
      $output .= '<div class="card card-forseti mb-4">';
      $output .= '<div class="card-body">';
      $output .= '<h1 class="mb-3">Data Quality Monitor</h1>';
      $output .= '<p class="lead"><strong>Total Records Analyzed:</strong> ' . $total_records . '</p>';
      $output .= '<p class="text-muted">This dashboard tracks EVERY field from both the User Profile and Enrollment Questionnaire, showing completion rates and value distributions.</p>';
      $output .= '</div></div>';
      
      // Navigation Menu (Collapsible)
      $output .= '<div class="card card-forseti mb-4" style="border-left: 4px solid #00d4ff;">';
      $output .= '<div class="card-header" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#nav-menu-collapse">';
      $output .= '<h2 class="h5 mb-0 d-flex align-items-center justify-content-between">';
      $output .= '<span><i class="fas fa-bars me-2"></i>Quick Navigation</span>';
      $output .= '<span class="badge bg-info">Click to Expand/Collapse</span>';
      $output .= '</h2>';
      $output .= '</div>';
      $output .= '<div class="collapse show" id="nav-menu-collapse">';
      $output .= '<div class="card-body">';
      $output .= '<div class="row g-3">';
      
      // Column 1: Overview & Statistics
      $output .= '<div class="col-md-4">';
      $output .= '<h6 class="text-white mb-2"><i class="fas fa-chart-bar me-1"></i> Overview & Statistics</h6>';
      $output .= '<ul class="list-unstyled small">';
      $output .= '<li><a href="#table-statistics" class="text-cyan">📊 Database Table Statistics</a></li>';
      $output .= '<li><a href="#profile-summary" class="text-cyan">👤 User Profile Dataset Summary</a></li>';
      $output .= '<li><a href="#section-summaries" class="text-cyan">📋 Questionnaire Section Summaries</a></li>';
      $output .= '<li><a href="#legend" class="text-cyan">📖 Legend</a></li>';
      $output .= '<li><a href="#field-audit" class="text-cyan">🔍 Comprehensive Field Audit</a></li>';
      $output .= '<li><a href="#summary-stats" class="text-cyan">📊 Summary Statistics</a></li>';
      $output .= '</ul>';
      $output .= '</div>';
      
      // Column 2: Questionnaire Sections
      $output .= '<div class="col-md-4">';
      $output .= '<h6 class="text-white mb-2"><i class="fas fa-clipboard-list me-1"></i> Questionnaire Sections</h6>';
      $output .= '<ul class="list-unstyled small">';
      $output .= '<li><a href="#section-profile" class="text-cyan">USER PROFILE (5-Minute Form)</a></li>';
      $output .= '<li><a href="#section-demographics" class="text-cyan">Section 1: Demographics</a></li>';
      $output .= '<li><a href="#section-work_history" class="text-cyan">Section 2: Work History</a></li>';
      $output .= '<li><a href="#section-exposure" class="text-cyan">Section 3: Exposure</a></li>';
      $output .= '<li><a href="#section-military" class="text-cyan">Section 4: Military Service</a></li>';
      $output .= '<li><a href="#section-other_employment" class="text-cyan">Section 5: Other Employment</a></li>';
      $output .= '</ul>';
      $output .= '</div>';
      
      // Column 3: More Sections
      $output .= '<div class="col-md-4">';
      $output .= '<h6 class="text-white mb-2"><i class="fas fa-list-check me-1"></i> Additional Sections</h6>';
      $output .= '<ul class="list-unstyled small">';
      $output .= '<li><a href="#section-ppe" class="text-cyan">Section 6: PPE</a></li>';
      $output .= '<li><a href="#section-decontamination" class="text-cyan">Section 7: Decontamination</a></li>';
      $output .= '<li><a href="#section-health" class="text-cyan">Section 8: Health Conditions</a></li>';
      $output .= '<li><a href="#section-lifestyle" class="text-cyan">Section 9: Lifestyle</a></li>';
      $output .= '<li><a href="#section-consent" class="text-cyan">Consent Tracking</a></li>';
      $output .= '<li><a href="#section-progress" class="text-cyan">Section Completion Tracking</a></li>';
      $output .= '<li><a href="#incomplete-fields" class="text-cyan">⚠️ Fields with Incomplete Data</a></li>';
      $output .= '</ul>';
      $output .= '</div>';
      
      $output .= '</div>'; // row
      $output .= '</div></div></div>'; // card-body, collapse, card
      
      // =============================================================================
      // TABLE STATISTICS SECTION
      // =============================================================================
      $output .= '<div class="card card-forseti mb-4" style="border-left: 4px solid #2196F3;" id="table-statistics">';
      $output .= '<div class="card-body">';
      $output .= '<h2 class="h4 mb-4">📊 Database Table Statistics</h2>';
      $output .= '<p class="mb-3">Summary statistics for all NFR database tables showing record counts and field tracking coverage.</p>';
      
      // Add column definitions
      $output .= '<div class="alert alert-light border mb-4">';
      $output .= '<h6 class="mb-3"><strong>Column Definitions:</strong></h6>';
      $output .= '<dl class="row mb-0 small">';
      $output .= '<dt class="col-sm-3">Records</dt>';
      $output .= '<dd class="col-sm-9">Total number of records stored in the database table</dd>';
      $output .= '<dt class="col-sm-3">Total Fields</dt>';
      $output .= '<dd class="col-sm-9">Total number of database columns in the table schema</dd>';
      $output .= '<dt class="col-sm-3">Tracked Fields</dt>';
      $output .= '<dd class="col-sm-9">Number of fields being monitored for data quality (excludes system fields like ID, timestamps)</dd>';
      $output .= '<dt class="col-sm-3">Completeness</dt>';
      $output .= '<dd class="col-sm-9">For base tables (profile, questionnaire): percentage of tracked fields with 100% fill rate. For child tables (incidents, employment, etc.): percentage of users who have records (records ÷ total users)</dd>';
      $output .= '<dt class="col-sm-3">Record Completeness</dt>';
      $output .= '<dd class="col-sm-9 mb-0">Percentage of records in the table that have all tracked fields filled (record-level completeness)</dd>';
      $output .= '</dl>';
      $output .= '</div>';
      
      $output .= '<div class="table-responsive">';
      $output .= '<table class="table table-striped table-hover validation-routes-table">';
      $output .= '<thead>';
      $output .= '<tr>';
      $output .= '<th>Table Name</th>';
      $output .= '<th class="text-center">Records</th>';
      $output .= '<th class="text-center">Total Fields</th>';
      $output .= '<th class="text-center">Tracked Fields</th>';
      $output .= '<th class="text-center">Completeness</th>';
      $output .= '<th class="text-center">Record Completeness</th>';
      $output .= '</tr></thead><tbody>';
      
      foreach ($table_stats as $table_name => $stats) {
        $completeness_badge = 'secondary';
        if (isset($stats['completeness_pct'])) {
          $completeness_badge = $stats['completeness_pct'] >= 90 ? 'success' : ($stats['completeness_pct'] >= 75 ? 'warning' : 'danger');
        }
        
        $output .= '<tr>';
        $output .= '<td>';
        $output .= '<code>' . htmlspecialchars($table_name) . '</code>';
        // Add note for nfr_user_profile about optional fields
        if ($table_name === 'nfr_user_profile') {
          $output .= '<br><small class="text-muted">⚠️ Includes optional fields (middle_name, alternate_email) - validation generates 30% fill rate for optional fields.</small>';
        }
        // Add note for optional child tables
        if (in_array($table_name, ['nfr_major_incidents', 'nfr_other_employment', 'nfr_cancer_diagnoses', 'nfr_family_cancer_history'])) {
          $output .= '<br><small class="text-muted">ℹ️ Optional table - validation generates ~30% user coverage. Nested optionals use 80% fill rate.</small>';
        }
        // Add note for questionnaire which has mixed required/optional fields
        if ($table_name === 'nfr_questionnaire') {
          $output .= '<br><small class="text-muted">ℹ️ Contains both required and optional fields - military service (30%), AFFF details (conditional on usage).</small>';
        }
        $output .= '</td>';
        $output .= '<td class="text-center">' . number_format($stats['record_count']) . '</td>';
        $output .= '<td class="text-center">' . $stats['field_count'] . '</td>';
        $output .= '<td class="text-center">' . $stats['tracked_fields'] . '</td>';
        $output .= '<td class="text-center">';
        if (isset($stats['completeness_pct'])) {
          $output .= '<span class="badge bg-' . $completeness_badge . '">' . $stats['completeness_pct'] . '%</span>';
        } else {
          $output .= '<span class="text-muted">-</span>';
        }
        $output .= '</td>';
        
        $output .= '<td class="text-center">';
        if (isset($stats['record_completeness_pct'])) {
          $record_badge = $stats['record_completeness_pct'] >= 90 ? 'success' : ($stats['record_completeness_pct'] >= 75 ? 'warning' : 'danger');
          $output .= '<span class="badge bg-' . $record_badge . '">' . $stats['record_completeness_pct'] . '%</span>';
        } else {
          $output .= '<span class="text-muted">-</span>';
        }
        $output .= '</td>';
        $output .= '</tr>';
      }
      
      $output .= '</tbody></table>';
      $output .= '</div>'; // table-responsive
      
      $output .= '</div></div>'; // card-body, card
      
      // =============================================================================
      // PROFILE DATASET SUMMARY
      // =============================================================================
      $output .= '<div class="card card-forseti mb-4" style="border-left: 4px solid #9C27B0;" id="profile-summary">';
      $output .= '<div class="card-body">';
      $output .= '<h2 class="h4 mb-4">👤 User Profile Dataset Summary</h2>';
      $output .= '<p class="mb-4"><strong>Total Profiles:</strong> ' . number_format($profile_summary['total_profiles']) . '</p>';
      
      $output .= '<div class="row g-4">';
      
      // Sex Distribution
      if (!empty($profile_summary['sex_distribution'])) {
        $output .= '<div class="col-md-6">';
        $output .= '<div class="card bg-light h-100">';
        $output .= '<div class="card-body">';
        $output .= '<h3 class="h6 mb-3 text-white">Sex Distribution</h3>';
        $output .= '<h3 class="h6 mb-3">Sex Distribution</h3>';
        $output .= '<table class="table table-sm table-borderless mb-0">';
        arsort($profile_summary['sex_distribution']);
        foreach ($profile_summary['sex_distribution'] as $sex => $count) {
          $pct = round(($count / $profile_summary['total_profiles']) * 100, 1);
          $output .= '<tr>';
          $output .= '<td>' . htmlspecialchars(ucfirst($sex)) . '</td>';
          $output .= '<td class="text-end"><strong>' . number_format($count) . '</strong></td>';
          $output .= '<td class="text-end text-muted">(' . $pct . '%)</td>';
          $output .= '</tr>';
        }
        $output .= '</table>';
        $output .= '</div></div></div>';
      }
      
      // Age Groups
      if (!empty($profile_summary['age_groups'])) {
        $output .= '<div class="col-md-6">';
        $output .= '<div class="card bg-light h-100">';
        $output .= '<div class="card-body">';
        $output .= '<h3 class="h6 mb-3 text-white">Age Distribution</h3>';
        $output .= '<table class="table table-sm table-borderless mb-0">';
        $age_order = ['Under 30', '30-39', '40-49', '50-59', '60-69', '70+'];
        foreach ($age_order as $age_group) {
          if (isset($profile_summary['age_groups'][$age_group])) {
            $count = $profile_summary['age_groups'][$age_group];
            $pct = round(($count / $profile_summary['total_profiles']) * 100, 1);
            $output .= '<tr>';
            $output .= '<td>' . htmlspecialchars($age_group) . '</td>';
            $output .= '<td class="text-end"><strong>' . number_format($count) . '</strong></td>';
            $output .= '<td class="text-end text-muted">(' . $pct . '%)</td>';
            $output .= '</tr>';
          }
        }
        $output .= '</table>';
        $output .= '</div></div></div>';
      }
      
      // State Distribution (Top 10)
      if (!empty($profile_summary['state_distribution'])) {
        $output .= '<div class="col-md-6">';
        $output .= '<div class="card bg-light h-100">';
        $output .= '<div class="card-body">';
        $output .= '<h3 class="h6 mb-3 text-white">State Distribution (Top 10)</h3>';
        $output .= '<table class="table table-sm table-borderless mb-0">';
        arsort($profile_summary['state_distribution']);
        $top_states = array_slice($profile_summary['state_distribution'], 0, 10, TRUE);
        foreach ($top_states as $state => $count) {
          $pct = round(($count / $profile_summary['total_profiles']) * 100, 1);
          $output .= '<tr>';
          $output .= '<td>' . htmlspecialchars($state) . '</td>';
          $output .= '<td class="text-end"><strong>' . number_format($count) . '</strong></td>';
          $output .= '<td class="text-end text-muted">(' . $pct . '%)</td>';
          $output .= '</tr>';
        }
        $output .= '</table>';
        $output .= '</div></div></div>';
      }
      
      // Work Status Distribution
      if (!empty($profile_summary['work_status_distribution'])) {
        $output .= '<div class="col-md-6">';
        $output .= '<div class="card bg-light h-100">';
        $output .= '<div class="card-body">';
        $output .= '<h3 class="h6 mb-3 text-white">Work Status Distribution</h3>';
        $output .= '<table class="table table-sm table-borderless mb-0">';
        arsort($profile_summary['work_status_distribution']);
        foreach ($profile_summary['work_status_distribution'] as $status => $count) {
          $pct = round(($count / $profile_summary['total_profiles']) * 100, 1);
          $output .= '<tr>';
          $output .= '<td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $status))) . '</td>';
          $output .= '<td class="text-end"><strong>' . number_format($count) . '</strong></td>';
          $output .= '<td class="text-end text-muted">(' . $pct . '%)</td>';
          $output .= '</tr>';
        }
        $output .= '</table>';
        $output .= '</div></div></div>';
      }
      
      $output .= '</div>'; // row
      $output .= '</div></div>'; // card-body, card
      
      // =============================================================================
      // SECTION DATASET SUMMARIES
      // =============================================================================
      $output .= '<div class="card card-forseti mb-4" style="border-left: 4px solid #FF5722;" id="section-summaries">';
      $output .= '<div class="card-body">';
      $output .= '<h2 class="h4 mb-4">📋 Questionnaire Section Summaries</h2>';
      $output .= '<p class="mb-3">Completion statistics for each questionnaire section showing field coverage and user progress.</p>';
      
      $output .= '<div class="table-responsive">';
      $output .= '<table class="table table-striped table-hover validation-routes-table">';
      $output .= '<thead>';
      $output .= '<tr>';
      $output .= '<th>Section</th>';
      $output .= '<th class="text-center">Tracked Fields</th>';
      $output .= '<th class="text-center">Complete Fields</th>';
      $output .= '<th class="text-center">Field Completeness</th>';
      $output .= '<th class="text-center">Users Started</th>';
      $output .= '<th class="text-center">Users Completed</th>';
      $output .= '<th class="text-center">Completion Rate</th>';
      $output .= '</tr></thead><tbody>';
      
      $section_labels = [
        'demographics' => 'Section 1: Demographics',
        'work_history' => 'Section 2: Work History',
        'exposure' => 'Section 3: Exposure',
        'military' => 'Section 4: Military Service',
        'other_employment' => 'Section 5: Other Employment',
        'ppe' => 'Section 6: PPE',
        'decontamination' => 'Section 7: Decontamination',
        'health' => 'Section 8: Health',
        'lifestyle' => 'Section 9: Lifestyle',
      ];
      
      foreach ($section_summaries as $section => $summary) {
        $field_completeness = $summary['fields'] > 0 ? round(($summary['complete'] / $summary['fields']) * 100, 1) : 0;
        $user_completion_rate = $summary['users_started'] > 0 ? round(($summary['users_completed'] / $summary['users_started']) * 100, 1) : 0;
        
        $field_badge = $field_completeness >= 90 ? 'success' : ($field_completeness >= 75 ? 'warning' : 'danger');
        $user_badge = $user_completion_rate >= 90 ? 'success' : ($user_completion_rate >= 75 ? 'warning' : 'danger');
        
        $output .= '<tr>';
        $output .= '<td><strong>' . htmlspecialchars($section_labels[$section] ?? ucwords(str_replace('_', ' ', $section))) . '</strong></td>';
        $output .= '<td class="text-center">' . $summary['fields'] . '</td>';
        $output .= '<td class="text-center">' . $summary['complete'] . '</td>';
        $output .= '<td class="text-center"><span class="badge bg-' . $field_badge . '">' . $field_completeness . '%</span></td>';
        $output .= '<td class="text-center">' . number_format($summary['users_started']) . '</td>';
        $output .= '<td class="text-center">' . number_format($summary['users_completed']) . '</td>';
        $output .= '<td class="text-center"><span class="badge bg-' . $user_badge . '">' . $user_completion_rate . '%</span></td>';
        $output .= '</tr>';
      }
      
      $output .= '</tbody></table>';
      $output .= '</div>'; // table-responsive
      $output .= '</div></div>'; // card-body, card
      
      // Legend Card - positioned before Audit Report
      $output .= '<div class="card card-forseti mb-3" style="border-left: 4px solid #17a2b8;" id="legend">';
      $output .= '<div class="card-body">';
      $output .= '<h3 class="h5 mb-3">📖 Legend</h3>';
      $output .= '<div class="row g-3">';
      $output .= '<div class="col-md-6">';
      $output .= '<p class="mb-2"><strong>Field Tracking:</strong></p>';
      $output .= '<p class="mb-0 small">✓ = Field is tracked | ✗ = Field not tracked</p>';
      $output .= '</div>';
      $output .= '<div class="col-md-6">';
      $output .= '<p class="mb-2"><strong>Fill Rate Badges:</strong></p>';
      $output .= '<p class="mb-0 small"><span class="badge bg-success">100%</span> Complete | <span class="badge bg-warning text-dark">75-99%</span> Good | <span class="badge bg-danger">1-74%</span> Incomplete | <span class="badge bg-secondary">0%</span> No data</p>';
      $output .= '</div>';
      $output .= '</div>'; // row
      $output .= '<hr class="my-3">';
      $output .= '<p class="mb-2 small"><strong>Storage Strategy:</strong> The NFR system uses multiple storage strategies for data organization:</p>';
      $output .= '<ul class="small mb-0">';
      $output .= '<li><strong>Profile Data (Registration):</strong> Direct columns in <code>nfr_user_profile</code> table (13 tracked fields)</li>';
      $output .= '<li><strong>Demographics (Section 1):</strong> Direct columns in <code>nfr_questionnaire</code> table (6 tracked fields)</li>';
      $output .= '<li><strong>Work History (Section 2):</strong> Normalized tables (<code>nfr_work_history</code> → <code>nfr_job_titles</code> → <code>nfr_incident_frequency</code>) (12 tracked fields)</li>';
      $output .= '<li><strong>Exposure (Section 3):</strong> Direct columns + <code>nfr_major_incidents</code> table (10 tracked fields)</li>';
      $output .= '<li><strong>Military (Section 4):</strong> Direct columns in <code>nfr_questionnaire</code> table (7 tracked fields)</li>';
      $output .= '<li><strong>Other Employment (Section 5):</strong> Direct column + <code>nfr_other_employment</code> normalized table (7 tracked fields)</li>';
      $output .= '<li><strong>PPE (Section 6):</strong> Direct columns in <code>nfr_questionnaire</code> table (18 tracked fields)</li>';
      $output .= '<li><strong>Decontamination (Section 7):</strong> Direct columns in <code>nfr_questionnaire</code> table (7 tracked fields)</li>';
      $output .= '<li><strong>Health (Section 8):</strong> Direct columns + <code>nfr_cancer_diagnoses</code> + <code>nfr_family_cancer_history</code> tables (11 tracked fields)</li>';
      $output .= '<li><strong>Lifestyle (Section 9):</strong> <code>smoking_history</code> JSON + direct columns in <code>nfr_questionnaire</code> table (21 tracked fields)</li>';
      $output .= '<li><strong>Consent:</strong> <code>nfr_consent</code> table with signature tracking (5 tracked fields)</li>';
      $output .= '<li><strong>Progress:</strong> <code>nfr_section_completion</code> table tracking completion by section (20 tracked metrics)</li>';
      $output .= '</ul>';
      $output .= '</div></div>'; // card-body, card
      
      // Audit Report Section
      $output .= '<div class="card card-forseti mb-4" style="border-left: 4px solid #ffc107;" id="field-audit">';
      $output .= '<div class="card-body">';
      $output .= '<h2 class="h4 mb-3">🔍 Comprehensive Field Audit Report</h2>';
      $output .= '<p class="mb-3">Comprehensive field-by-field comparison of requirements vs implementation vs database vs tracking.</p>';
      
      $output .= '<div class="table-responsive">';
      $output .= '<table class="table table-sm table-bordered validation-routes-table">';
      $output .= '<thead>';
      $output .= '<tr>';
      $output .= '<th>Section</th>';
      $output .= '<th>Field Name</th>';
      $output .= '<th class="text-center">Required</th>';
      $output .= '<th>Database Storage</th>';
      $output .= '<th class="text-center">Tracked</th>';
      $output .= '<th class="text-center">Fill Rate</th>';
      $output .= '</tr></thead><tbody>';
      
      // Helper function to add row
      $add_row = function($section, $field_name, $required, $db_location, $tracking_key) use (&$output, $field_counts, $total_records) {
        $is_tracked = isset($field_counts[$tracking_key]);
        $fill_rate = $is_tracked ? round(($field_counts[$tracking_key] / $total_records) * 100, 1) : 0;
        $badge_class = $fill_rate >= 100 ? 'success' : ($fill_rate >= 75 ? 'warning' : ($fill_rate > 0 ? 'danger' : 'secondary'));
        
        $output .= '<tr>';
        $output .= '<td class="table-light"><strong>' . htmlspecialchars($section) . '</strong></td>';
        $output .= '<td>' . htmlspecialchars($field_name) . '</td>';
        $output .= '<td class="text-center">' . ($required ? '<span class="badge bg-info">Yes</span>' : 'No') . '</td>';
        $output .= '<td style="font-family: monospace; font-size: 0.85em;">' . htmlspecialchars($db_location) . '</td>';
        $output .= '<td class="text-center">' . ($is_tracked ? '<span class="text-success">✓</span>' : '<span class="text-danger">✗</span>') . '</td>';
        $output .= '<td class="text-center"><span class="badge bg-' . $badge_class . '">' . $fill_rate . '%</span></td>';
        $output .= '</tr>';
      };
      
      // USER PROFILE FIELDS
      $add_row('Profile', 'First Name', true, 'nfr_user_profile.first_name', 'profile.first_name');
      $add_row('Profile', 'Middle Name', false, 'nfr_user_profile.middle_name', 'profile.middle_name');
      $add_row('Profile', 'Last Name', true, 'nfr_user_profile.last_name', 'profile.last_name');
      $add_row('Profile', 'Date of Birth', true, 'nfr_user_profile.date_of_birth', 'profile.date_of_birth');
      $add_row('Profile', 'Sex', true, 'nfr_user_profile.sex', 'profile.sex');
      $add_row('Profile', 'SSN Last 4', false, 'nfr_user_profile.ssn_last_4', 'profile.ssn_last_4');
      $add_row('Profile', 'Country of Birth', true, 'nfr_user_profile.country_of_birth', 'profile.country_of_birth');
      $add_row('Profile', 'State of Birth', false, 'nfr_user_profile.state_of_birth', 'profile.state_of_birth');
      $add_row('Profile', 'City of Birth', false, 'nfr_user_profile.city_of_birth', 'profile.city_of_birth');
      $add_row('Profile', 'Address Line 1', true, 'nfr_user_profile.address_line1', 'profile.address_line1');
      $add_row('Profile', 'Address Line 2', false, 'nfr_user_profile.address_line2', 'profile.address_line2');
      $add_row('Profile', 'City', true, 'nfr_user_profile.city', 'profile.city');
      $add_row('Profile', 'State', true, 'nfr_user_profile.state', 'profile.state');
      $add_row('Profile', 'ZIP Code', true, 'nfr_user_profile.zip_code', 'profile.zip_code');
      $add_row('Profile', 'Alternate Email', false, 'nfr_user_profile.alternate_email', 'profile.alternate_email');
      $add_row('Profile', 'Mobile Phone', false, 'nfr_user_profile.mobile_phone', 'profile.mobile_phone');
      $add_row('Profile', 'Current Work Status', true, 'nfr_user_profile.current_work_status', 'profile.current_work_status');
      
      // DEMOGRAPHICS (direct columns)
      $add_row('Demographics', 'Race/Ethnicity', true, 'nfr_questionnaire.race_ethnicity (JSON)', 'demographics.race_ethnicity');
      $add_row('Demographics', 'Race Other Specify', false, 'nfr_questionnaire.race_other', 'demographics.race_other');
      $add_row('Demographics', 'Education Level', true, 'nfr_questionnaire.education_level', 'demographics.education_level');
      $add_row('Demographics', 'Marital Status', true, 'nfr_questionnaire.marital_status', 'demographics.marital_status');
      $add_row('Demographics', 'Height (inches)', true, 'nfr_questionnaire.height_inches', 'demographics.height_inches');
      $add_row('Demographics', 'Weight (pounds)', true, 'nfr_questionnaire.weight_pounds', 'demographics.weight_pounds');
      
      // WORK HISTORY (from normalized tables)
      $add_row('Work History', 'Number of Departments', true, 'COUNT(DISTINCT nfr_work_history.id)', 'work_history.num_departments');
      $add_row('Work History', 'Department Name', true, 'nfr_work_history.department_name', 'work_history.department_name');
      $add_row('Work History', 'Department State', true, 'nfr_work_history.department_state', 'work_history.department_state');
      $add_row('Work History', 'Department City', true, 'nfr_work_history.department_city', 'work_history.department_city');
      $add_row('Work History', 'Department FDID', false, 'nfr_work_history.department_fdid', 'work_history.department_fdid');
      $add_row('Work History', 'Start Date', true, 'nfr_work_history.start_date', 'work_history.start_date');
      $add_row('Work History', 'End Date', false, 'nfr_work_history.end_date', 'work_history.end_date');
      $add_row('Work History', 'Is Current', false, 'nfr_work_history.is_current', 'work_history.is_current');
      $add_row('Work History', 'Job Title', true, 'nfr_job_titles.job_title', 'work_history.job_title');
      $add_row('Work History', 'Employment Type', true, 'nfr_job_titles.employment_type', 'work_history.employment_type');
      $add_row('Work History', 'Responded to Incidents', true, 'nfr_job_titles.responded_to_incidents', 'work_history.responded_incidents');
      $add_row('Work History', 'Incident Types', false, 'nfr_job_titles.incident_types (JSON)', 'work_history.incident_types');
      
      // EXPOSURE (from direct columns + nfr_major_incidents table)
      $add_row('Exposure', 'AFFF Used', true, 'nfr_questionnaire.afff_used', 'exposure.afff_used');
      $add_row('Exposure', 'AFFF Times Used', false, 'nfr_questionnaire.afff_times', 'exposure.afff_times');
      $add_row('Exposure', 'AFFF First Year', false, 'nfr_questionnaire.afff_first_year', 'exposure.afff_first_year');
      $add_row('Exposure', 'Diesel Exhaust', true, 'nfr_questionnaire.diesel_exhaust', 'exposure.diesel_exhaust');
      $add_row('Exposure', 'Major Incidents Yes/No', true, 'nfr_questionnaire.major_incidents', 'exposure.major_incidents');
      $add_row('Exposure', 'Chemical Activities', false, 'nfr_questionnaire.chemical_activities (JSON)', 'exposure.chemical_activities');
      
      // MAJOR INCIDENTS (normalized table)
      $add_row('Exposure', 'Major Incidents Count', false, 'COUNT(nfr_major_incidents)', 'exposure.major_incidents_count');
      $add_row('Exposure', 'Incident Description', false, 'nfr_major_incidents.description', 'exposure.major_incident_description');
      $add_row('Exposure', 'Incident Date', false, 'nfr_major_incidents.incident_date', 'exposure.major_incident_date');
      $add_row('Exposure', 'Incident Duration', false, 'nfr_major_incidents.duration', 'exposure.major_incident_duration');
      
      // MILITARY (direct columns)
      $add_row('Military', 'Served in Military', true, 'nfr_questionnaire.military_service', 'military.military_service');
      $add_row('Military', 'Military Branch', false, 'nfr_questionnaire.military_branch', 'military.military_branch');
      $add_row('Military', 'Military Years', false, 'nfr_questionnaire.military_years', 'military.military_years');
      $add_row('Military', 'Start Date', false, 'nfr_questionnaire.military_start_date', 'military.start_date');
      $add_row('Military', 'End Date', false, 'nfr_questionnaire.military_end_date', 'military.end_date');
      $add_row('Military', 'Currently Serving', false, 'nfr_questionnaire.military_currently_serving', 'military.currently_serving');
      $add_row('Military', 'Was Firefighter', false, 'nfr_questionnaire.military_was_firefighter', 'military.was_firefighter');
      
      // OTHER EMPLOYMENT (direct column + normalized table)
      $add_row('Other Employment', 'Had Other Jobs', true, 'nfr_questionnaire.had_other_jobs', 'other_employment.had_other_jobs');
      
      // OTHER EMPLOYMENT (normalized table)
      $add_row('Other Employment', 'Jobs Count (Table)', false, 'COUNT(nfr_other_employment)', 'other_employment.jobs_count_table');
      $add_row('Other Employment', 'Occupation', false, 'nfr_other_employment.occupation', 'other_employment.occupation');
      $add_row('Other Employment', 'Industry', false, 'nfr_other_employment.industry', 'other_employment.industry');
      $add_row('Other Employment', 'Start Year', false, 'nfr_other_employment.start_year', 'other_employment.start_year');
      $add_row('Other Employment', 'End Year', false, 'nfr_other_employment.end_year', 'other_employment.end_year');
      $add_row('Other Employment', 'Exposures', false, 'nfr_other_employment.exposures', 'other_employment.exposures');
      $add_row('Other Employment', 'Exposures Other', false, 'nfr_other_employment.exposures_other', 'other_employment.exposures_other');
      
      // PPE (from direct columns: ppe_*_ever_used, ppe_*_year_started)
      $ppe_items = [
        'SCBA' => 'scba',
        'Turnout Coat' => 'turnout_coat',
        'Turnout Pants' => 'turnout_pants',
        'Gloves' => 'gloves',
        'Helmet' => 'helmet',
        'Boots' => 'boots',
        'Nomex Hood' => 'nomex_hood',
        'Wildland Clothing' => 'wildland_clothing'
      ];
      foreach ($ppe_items as $label => $key) {
        $add_row('PPE', $label . ' - Ever Used', true, 'nfr_questionnaire.ppe_' . $key . '_ever_used', 'ppe.' . $key . '.ever_used');
        $add_row('PPE', $label . ' - Year Started', false, 'nfr_questionnaire.ppe_' . $key . '_year_started', 'ppe.' . $key . '.year_started');
      }
      
      $scba_scenarios = [
        'During Suppression', 'During Overhaul'
      ];
      foreach ($scba_scenarios as $idx => $scenario) {
        $key = ['during_suppression', 'during_overhaul'][$idx];
        $add_row('PPE', 'SCBA ' . $scenario, true, 'nfr_questionnaire.ppe_scba_' . $key, 'ppe.scba_' . $key);
      }
      
      // DECONTAMINATION (from direct columns: decon_*)
      $decon_practices = [
        'Washed Hands/Face' => 'washed_hands_face',
        'Changed Gear at Scene' => 'changed_gear_at_scene',
        'Showered at Station' => 'showered_at_station',
        'Laundered Gear' => 'laundered_gear',
        'Used Wet Wipes' => 'used_wet_wipes'
      ];
      foreach ($decon_practices as $label => $key) {
        $add_row('Decontamination', $label, true, 'nfr_questionnaire.decon_' . $key, 'decontamination.' . $key);
      }
      $add_row('Decontamination', 'Department Had SOPs', true, 'nfr_questionnaire.decon_department_had_sops', 'decontamination.department_had_sops');
      $add_row('Decontamination', 'SOP Year Implemented', false, 'nfr_questionnaire.decon_sops_year_implemented', 'decontamination.sop_year_implemented');
      
      // HEALTH (direct columns + normalized table)
      $health_conditions = [
        'Heart Disease' => 'heart_disease',
        'COPD' => 'copd',
        'Asthma' => 'asthma',
        'Diabetes' => 'diabetes'
      ];
      foreach ($health_conditions as $label => $key) {
        $add_row('Health', $label, true, 'nfr_questionnaire.health_' . $key, 'health.' . $key);
      }
      $add_row('Health', 'Cancer Diagnosed', true, 'nfr_questionnaire.cancer_diagnosis', 'health.cancer_diagnosis');
      $add_row('Health', 'Family Cancer History', false, 'nfr_questionnaire.family_cancer_history (JSON)', 'health.family_cancer_history');
      
      // CANCER DIAGNOSES (normalized table)
      $add_row('Health', 'Cancer Count (Table)', false, 'COUNT(nfr_cancer_diagnoses)', 'health.cancer_count_table');
      $add_row('Health', 'Cancer Type (Table)', false, 'nfr_cancer_diagnoses.cancer_type', 'health.cancer_type_table');
      $add_row('Health', 'Year Diagnosed (Table)', false, 'nfr_cancer_diagnoses.year_diagnosed', 'health.year_diagnosed_table');
      
      // LIFESTYLE (direct columns + JSON columns)
      // Cigarette smoking (in smoking_history JSON)
      $add_row('Lifestyle', 'Smoking Status', true, 'nfr_questionnaire.smoking_history.smoking_status (JSON)', 'lifestyle.smoking_status');
      $add_row('Lifestyle', 'Smoking Age Started', false, 'nfr_questionnaire.smoking_history.smoking_age_started (JSON)', 'lifestyle.smoking_age_started');
      $add_row('Lifestyle', 'Smoking Age Stopped', false, 'nfr_questionnaire.smoking_history.smoking_age_stopped (JSON)', 'lifestyle.smoking_age_stopped');
      $add_row('Lifestyle', 'Cigarettes Per Day', false, 'nfr_questionnaire.smoking_history.cigarettes_per_day (JSON)', 'lifestyle.cigarettes_per_day');
      
      // Other tobacco types (in smoking_history JSON)
      $tobacco_types = [
        'cigars' => 'Cigars',
        'pipes' => 'Pipes',
        'ecigs' => 'E-cigarettes',
        'smokeless' => 'Smokeless Tobacco',
      ];
      foreach ($tobacco_types as $key => $label) {
        $add_row('Lifestyle', $label . ' - Ever Used', false, 'nfr_questionnaire.smoking_history.' . $key . '_ever_used (JSON)', 'lifestyle.' . $key . '_ever_used');
        $add_row('Lifestyle', $label . ' - Age Started', false, 'nfr_questionnaire.smoking_history.' . $key . '_age_started (JSON)', 'lifestyle.' . $key . '_age_started');
        $add_row('Lifestyle', $label . ' - Age Stopped', false, 'nfr_questionnaire.smoking_history.' . $key . '_age_stopped (JSON)', 'lifestyle.' . $key . '_age_stopped');
      }
      
      // Other lifestyle factors (direct columns)
      $add_row('Lifestyle', 'Alcohol Frequency', true, 'nfr_questionnaire.alcohol_use', 'lifestyle.alcohol_use');
      $add_row('Lifestyle', 'Physical Activity Days', true, 'nfr_questionnaire.physical_activity_days', 'lifestyle.physical_activity_days');
      $add_row('Lifestyle', 'Sleep Hours Per Night', true, 'nfr_questionnaire.sleep_hours_per_night', 'lifestyle.sleep_hours_per_night');
      $add_row('Lifestyle', 'Sleep Quality', true, 'nfr_questionnaire.sleep_quality', 'lifestyle.sleep_quality');
      $add_row('Lifestyle', 'Sleep Disorders', false, 'nfr_questionnaire.sleep_disorders (JSON)', 'lifestyle.sleep_disorders');
      
      // CONSENT (from nfr_consent table)
      $add_row('Consent', 'Consented to Participate', true, 'nfr_consent.consented_to_participate', 'consent.participate');
      $add_row('Consent', 'Consented to Registry Linkage', true, 'nfr_consent.consented_to_registry_linkage', 'consent.registry_linkage');
      $add_row('Consent', 'Electronic Signature', true, 'nfr_consent.electronic_signature', 'consent.electronic_signature');
      $add_row('Consent', 'IP Address', false, 'nfr_consent.consent_ip_address', 'consent.ip_address');
      $add_row('Consent', 'Timestamp', true, 'nfr_consent.consent_timestamp', 'consent.timestamp');
      
      // PROGRESS TRACKING (from nfr_section_completion table)
      $add_row('Progress', 'Sections Completed Count', false, 'COUNT(nfr_section_completion WHERE completed=1)', 'progress.sections_completed_count');
      for ($i = 1; $i <= 9; $i++) {
        $add_row('Progress', "Section {$i} Completed", false, "nfr_section_completion.completed (section={$i})", "progress.section_{$i}_completed");
        $add_row('Progress', "Section {$i} Completed At", false, "nfr_section_completion.completed_at (section={$i})", "progress.section_{$i}_completed_at");
      }
      
      $output .= '</tbody></table>';
      $output .= '</div>'; // table-responsive
      $output .= '</div></div>'; // card-body, card
      
      // Summary Statistics
      $output .= '<div class="card card-forseti mb-4" style="border-left: 4px solid #4CAF50;" id="summary-stats">';
      $output .= '<div class="card-body">';
      $output .= '<h2 class="h4 mb-4">📊 Summary Statistics</h2>';
      $output .= '<div class="row g-3">';
      $output .= '<div class="col-md-4"><div class="card bg-light h-100"><div class="card-body text-center">';
      $output .= '<h3 class="h5 text-muted mb-2">Total Fields Tracked</h3>';
      $output .= '<div class="display-6 text-primary">' . $total_fields . '</div>';
      $output .= '</div></div></div>';
      $output .= '<div class="col-md-4"><div class="card bg-light h-100"><div class="card-body text-center">';
      $output .= '<h3 class="h5 text-muted mb-2">Fields at 100%</h3>';
      $output .= '<div class="display-6 text-success">' . $fields_at_100 . '</div>';
      $output .= '</div></div></div>';
      $output .= '<div class="col-md-4"><div class="card bg-light h-100"><div class="card-body text-center">';
      $output .= '<h3 class="h5 text-muted mb-2">Fields Below 100%</h3>';
      $output .= '<div class="display-6 ' . (count($fields_below_100) > 0 ? 'text-danger' : 'text-success') . '">' . count($fields_below_100) . '</div>';
      $output .= '</div></div></div>';
      $output .= '</div>'; // row
      $output .= '</div></div>'; // card-body, card
      
      $sections = [
        'profile' => 'USER PROFILE (5-Minute Form)',
        'demographics' => 'DEMOGRAPHICS (Section 1)',
        'work_history' => 'WORK HISTORY (Section 2)',
        'exposure' => 'EXPOSURE (Section 3)',
        'military' => 'MILITARY SERVICE (Section 4)',
        'other_employment' => 'OTHER EMPLOYMENT (Section 5)',
        'ppe' => 'PERSONAL PROTECTIVE EQUIPMENT (Section 6)',
        'decontamination' => 'DECONTAMINATION (Section 7)',
        'health' => 'HEALTH CONDITIONS (Section 8)',
        'lifestyle' => 'LIFESTYLE (Section 9)',
        'consent' => 'CONSENT TRACKING',
        'progress' => 'SECTION COMPLETION TRACKING',
      ];
      
      $chart_data_js = [];
      
      foreach ($sections as $section_key => $section_name) {
        $output .= '<div class="card card-forseti mb-4" id="section-' . $section_key . '">';
        $output .= '<div class="card-header">';
        $output .= '<h2 class="h5 mb-0">' . $section_name . '</h2>';
        $output .= '</div>';
        $output .= '<div class="card-body">';
        
        $section_has_fields = FALSE;
        foreach ($field_counts as $field => $count) {
          if (strpos($field, $section_key . '.') === 0) {
            $section_has_fields = TRUE;
            $pct = round(($count / $total_records) * 100, 1);
            $badge_class = $pct >= 100 ? 'success' : ($pct >= 90 ? 'warning' : 'danger');
            
            // Create chart for this field
            $chart_id = 'chart_' . str_replace('.', '_', $field);
            $output .= '<div class="mb-4">';
            $output .= '<h3 class="h6 text-white">' . htmlspecialchars($field) . '</h3>';
            $output .= '<p class="mb-2"><strong>Fill Rate:</strong> <span class="badge bg-' . $badge_class . '">' . $count . ' / ' . $total_records . ' (' . $pct . '%)</span></p>';
            
            if (isset($value_distributions[$field])) {
              $output .= '<div style="position: relative; height: 300px; margin-top: 10px;">';
              $output .= '<canvas id="' . $chart_id . '"></canvas>';
              $output .= '</div>';
              
              // Prepare data for this chart
              $labels = array_keys($value_distributions[$field]);
              $values = array_values($value_distributions[$field]);
              
              // Limit very long labels and ensure strings
              $labels = array_map(function($label) {
                $label = (string)$label;
                return strlen($label) > 30 ? substr($label, 0, 27) . '...' : $label;
              }, $labels);
              
              $chart_data_js[] = [
                'id' => $chart_id,
                'labels' => $labels,
                'data' => $values,
                'field' => $field,
              ];
            }
            else {
              $output .= '<p class="text-muted fst-italic">No value distribution data available</p>';
            }
            
            $output .= '</div>';
          }
        }
        
        if (!$section_has_fields) {
          $output .= '<p class="text-muted fst-italic">No fields analyzed in this section</p>';
        }
        
        $output .= '</div></div>'; // card-body, card
      }
      
      // Fields with Incomplete Data Section
      if (count($fields_below_100) > 0) {
        $output .= '<div class="card card-forseti mb-4" style="border-left: 4px solid #F44336;" id="incomplete-fields">';
        $output .= '<div class="card-body">';
        $output .= '<h2 class="h4 text-danger mb-3">⚠️ Fields with Incomplete Data</h2>';
        $output .= '<p class="mb-3">The following fields have less than 100% completion. Charts show the distribution of actual responses.</p>';
        
        foreach ($fields_below_100 as $item) {
          $field = $item['field'];
          $count = $item['count'];
          $pct = $item['pct'];
          $missing = $total_records - $count;
          $missing_pct = round(($missing / $total_records) * 100, 1);
          $color = $pct >= 90 ? '#FF9800' : '#F44336';
          
          $chart_id = 'chart_' . str_replace('.', '_', $field);
          
          $output .= '<div class="chart-container" style="border: 2px solid ' . $color . ';">';
          $output .= '<h3 style="color: ' . $color . ';">' . htmlspecialchars($field) . '</h3>';
          $output .= '<div style="background: #fff; padding: 10px; margin: 10px 0; border-radius: 4px;">';
          $output .= '<table style="width: 100%; border-collapse: collapse;">';
          $output .= '<tr>';
          $output .= '<td style="padding: 8px;"><strong>Completed:</strong></td>';
          $output .= '<td style="padding: 8px; color: #4CAF50;">' . $count . ' (' . $pct . '%)</td>';
          $output .= '<td style="padding: 8px;"><strong>Missing:</strong></td>';
          $output .= '<td style="padding: 8px; color: #F44336;">' . $missing . ' (' . $missing_pct . '%)</td>';
          $output .= '<td style="padding: 8px;"><strong>Total:</strong></td>';
          $output .= '<td style="padding: 8px;">' . $total_records . '</td>';
          $output .= '</tr>';
          $output .= '</table>';
          $output .= '</div>';
          
          if (isset($value_distributions[$field])) {
            $output .= '<p><strong>Value Distribution (for completed responses only):</strong></p>';
            $output .= '<div class="chart-wrapper">';
            $output .= '<canvas id="' . $chart_id . '_incomplete"></canvas>';
            $output .= '</div>';
            
            // Add chart data with "_incomplete" suffix
            $labels = array_keys($value_distributions[$field]);
            $values = array_values($value_distributions[$field]);
            $labels = array_map(function($label) {
              $label = (string)$label;
              return strlen($label) > 30 ? substr($label, 0, 27) . '...' : $label;
            }, $labels);
            
            $chart_data_js[] = [
              'id' => $chart_id . '_incomplete',
              'labels' => $labels,
              'data' => $values,
              'field' => $field,
            ];
            
            // Also show a table of values
            $output .= '<div class="table-responsive mt-3">';
            $output .= '<table class="table table-sm table-bordered">';
            $output .= '<thead class="table-light">';
            $output .= '<tr>';
            $output .= '<th>Value</th>';
            $output .= '<th class="text-center">Count</th>';
            $output .= '<th class="text-center">% of Completed</th>';
            $output .= '<th class="text-center">% of Total</th>';
            $output .= '</tr></thead><tbody>';
            
            foreach ($value_distributions[$field] as $val => $val_count) {
              $pct_of_completed = round(($val_count / $count) * 100, 1);
              $pct_of_total = round(($val_count / $total_records) * 100, 1);
              $output .= '<tr>';
              $output .= '<td>' . htmlspecialchars((string)$val) . '</td>';
              $output .= '<td class="text-center">' . $val_count . '</td>';
              $output .= '<td class="text-center">' . $pct_of_completed . '%</td>';
              $output .= '<td class="text-center">' . $pct_of_total . '%</td>';
              $output .= '</tr>';
            }
            
            $output .= '</tbody></table>';
            $output .= '</div>';
          }
          else {
            $output .= '<p class="text-muted fst-italic">No value distribution data available</p>';
          }
          
          $output .= '</div>';
        }
        $output .= '</div></div>'; // card-body, card
      }
      
      $output .= '<p class="mt-3"><a href="/admin/nfr/validation" class="btn btn-secondary">← Back to Validation Dashboard</a></p>';
      $output .= '</div>'; // container-fluid
      
      return [
        '#theme' => 'nfr_admin_page',
        '#page_id' => 'fill-rates',
        '#content' => [
          '#type' => 'inline_template',
          '#template' => $output,
        ],
        '#attached' => [
          'library' => [
            'nfr/admin',
            'nfr/fill_rates',
          ],
          'html_head' => [
            [
              [
                '#type' => 'html_tag',
                '#tag' => 'script',
                '#attributes' => ['src' => 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'],
              ],
              'chartjs',
            ],
          ],
          'drupalSettings' => [
            'nfr_fill_rates' => [
              'chart_data' => $chart_data_js,
            ],
          ],
        ],
        '#cache' => [
          'max-age' => 0,
        ],
      ];
    }
    catch (\Exception $e) {
      return [
        '#theme' => 'nfr_admin_page',
        '#page_id' => 'fill-rates-error',
        '#content' => [
          '#markup' => '<div class="error"><h1>Error</h1><p>' . htmlspecialchars($e->getMessage()) . '</p><p><a href="/admin/nfr/validation">← Back to Validation Dashboard</a></p></div>',
        ],
        '#attached' => [
          'library' => ['nfr/admin'],
        ],
      ];
    }
  }

  /**
   * Get total number of users in the system.
   */
  private function getTotalUsers(): int {
    $database = \Drupal::database();
    $query = $database->select('users_field_data', 'u')
      ->condition('u.uid', 0, '>')
      ->condition('u.status', 1);
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Get count of test users (users with @stlouisintegration.com email).
   */
  private function getTestUsersCount(): int {
    $database = \Drupal::database();
    $query = $database->select('users_field_data', 'u')
      ->condition('u.uid', 0, '>')
      ->condition('u.status', 1)
      ->condition('u.mail', '%@stlouisintegration.com', 'LIKE');
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Get user counts grouped by role.
   */
  private function getUserCountsByRole(): array {
    $database = \Drupal::database();
    
    $roles = [
      'nfr_administrator' => 'NFR Administrators',
      'nfr_researcher' => 'NFR Researchers',
      'fire_dept_admin' => 'Fire Department Admins',
      'firefighter' => 'Firefighters',
    ];
    
    $counts = [];
    
    foreach ($roles as $role_id => $role_label) {
      $query = $database->select('user__roles', 'ur')
        ->fields('ur', ['entity_id'])
        ->condition('ur.roles_target_id', $role_id)
        ->condition('ur.deleted', 0);
      
      $count = (int) $query->countQuery()->execute()->fetchField();
      
      $counts[] = [
        'role_id' => $role_id,
        'label' => $role_label,
        'count' => $count,
      ];
    }
    
    return $counts;
  }

  /**
   * Get a test user UID by role.
   * 
   * Finds first active user matching criteria:
   * - Has specified role
   * - Email ends with @stlouisintegration.com
   * - Username contains "test" OR matches role pattern (firefighter_, nfr_administrator_, etc.)
   * 
   * @param string $role
   *   Role machine name (firefighter, nfr_administrator, nfr_researcher, fire_dept_admin)
   * @param string $status
   *   Optional status filter like 'active', 'retired' (for firefighter role)
   * 
   * @return int|null
   *   User ID or NULL if not found
   */
  private function getTestUserByRole(string $role, string $status = ''): ?int {
    $connection = \Drupal::database();
    
    // Build username pattern based on role and status
    $username_patterns = [];
    switch ($role) {
      case 'firefighter':
        if ($status === 'active') {
          $username_patterns[] = 'firefighter_active%';
        }
        elseif ($status === 'retired') {
          $username_patterns[] = 'firefighter_retired%';
        }
        else {
          $username_patterns[] = 'firefighter%';
        }
        break;
      
      case 'nfr_administrator':
        $username_patterns[] = 'nfr_administrator%';
        $username_patterns[] = 'nfr_admin%';
        break;
      
      case 'nfr_researcher':
        $username_patterns[] = 'nfr_researcher%';
        break;
      
      case 'fire_dept_admin':
        $username_patterns[] = 'fire_dept_admin%';
        $username_patterns[] = 'dept_admin%';
        break;
    }
    
    // Query for user with role and test email
    $query = $connection->select('users_field_data', 'u');
    $query->fields('u', ['uid']);
    $query->condition('u.status', 1);
    $query->condition('u.mail', '%@stlouisintegration.com', 'LIKE');
    
    // Join with user roles
    $query->leftJoin('user__roles', 'ur', 'u.uid = ur.entity_id');
    $query->condition('ur.roles_target_id', $role);
    
    // Add username pattern conditions
    if (!empty($username_patterns)) {
      $or_group = $query->orConditionGroup();
      foreach ($username_patterns as $pattern) {
        $or_group->condition('u.name', $pattern, 'LIKE');
      }
      // Also match if "test" is in username
      $or_group->condition('u.name', '%test%', 'LIKE');
      $query->condition($or_group);
    }
    
    $query->range(0, 1);
    $result = $query->execute()->fetchField();
    
    return $result ? (int)$result : NULL;
  }

  /**
   * Get a test user with incomplete profile/questionnaire.
   * 
   * Finds a firefighter test user who has NOT completed all sections.
   * Prioritizes users with no data, then partially complete users.
   * 
   * @param string $role
   *   Role machine name (firefighter, nfr_administrator, etc.)
   * 
   * @return array|null
   *   Array with 'uid' and 'username', or NULL if not found
   */
  private function getIncompleteTestUser(string $role = 'firefighter'): ?array {
    $connection = \Drupal::database();
    
    // First try to find a test user with NO profile at all
    $query = $connection->select('users_field_data', 'u');
    $query->fields('u', ['uid', 'name']);
    $query->condition('u.status', 1);
    $query->condition('u.mail', '%@stlouisintegration.com', 'LIKE');
    
    // Join with user roles
    $query->leftJoin('user__roles', 'ur', 'u.uid = ur.entity_id');
    $query->condition('ur.roles_target_id', $role);
    
    // Exclude users who have a profile
    $query->leftJoin('nfr_user_profile', 'p', 'u.uid = p.uid');
    $query->isNull('p.uid');
    
    // Username pattern
    $or_group = $query->orConditionGroup();
    $or_group->condition('u.name', 'firefighter%', 'LIKE');
    $or_group->condition('u.name', '%test%', 'LIKE');
    $query->condition($or_group);
    
    $query->range(0, 1);
    $result = $query->execute()->fetchAssoc();
    
    if ($result) {
      return [
        'uid' => (int)$result['uid'],
        'username' => $result['name'],
        'status' => 'No profile - fresh user',
      ];
    }
    
    // If all have profiles, find one with incomplete sections (< 9 completed)
    $query = $connection->select('users_field_data', 'u');
    $query->fields('u', ['uid', 'name']);
    $query->condition('u.status', 1);
    $query->condition('u.mail', '%@stlouisintegration.com', 'LIKE');
    
    // Join with user roles
    $query->leftJoin('user__roles', 'ur', 'u.uid = ur.entity_id');
    $query->condition('ur.roles_target_id', $role);
    
    // Join with section completion - count completed sections
    $query->leftJoin('nfr_section_completion', 'sc', 'u.uid = sc.uid AND sc.completed = 1');
    $query->addExpression('COUNT(sc.id)', 'completed_count');
    $query->groupBy('u.uid');
    $query->groupBy('u.name');
    $query->having('COUNT(sc.id) < 9');
    
    // Username pattern
    $or_group = $query->orConditionGroup();
    $or_group->condition('u.name', 'firefighter%', 'LIKE');
    $or_group->condition('u.name', '%test%', 'LIKE');
    $query->condition($or_group);
    
    $query->range(0, 1);
    $result = $query->execute()->fetchAssoc();
    
    if ($result) {
      $completed = (int)($result['completed_count'] ?? 0);
      return [
        'uid' => (int)$result['uid'],
        'username' => $result['name'],
        'status' => "Incomplete - {$completed}/9 sections done",
      ];
    }
    
    // If all test users are complete, just return the first one
    $test_uid = $this->getTestUserByRole($role);
    if ($test_uid) {
      $user = \Drupal\user\Entity\User::load($test_uid);
      return [
        'uid' => $test_uid,
        'username' => $user->getAccountName(),
        'status' => 'Complete - will overwrite',
      ];
    }
    
    return NULL;
  }

}
