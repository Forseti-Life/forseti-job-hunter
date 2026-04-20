<?php

namespace Drupal\nfr\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Provides an NFR Navigation block.
 *
 * @Block(
 *   id = "nfr_navigation",
 *   admin_label = @Translation("NFR Navigation Menu"),
 *   category = @Translation("NFR")
 * )
 */
class NFRNavigationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * Constructs a new NFRNavigationBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AccountInterface $current_user
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $is_logged_in = $this->currentUser->isAuthenticated();
    $is_admin = $this->currentUser->hasPermission('administer nfr');
    $can_view_reports = $this->currentUser->hasPermission('view nfr reports');
    $is_participant = $this->currentUser->hasPermission('access nfr participant');
    $is_researcher = $this->currentUser->hasPermission('view nfr research data');
    
    // Check if user has any role that should see My Dashboard
    $has_dashboard_access = $is_participant || $is_admin || $is_researcher || 
      $this->currentUser->hasRole('firefighter') ||
      $this->currentUser->hasRole('fire_dept_admin') || 
      $this->currentUser->hasRole('nfr_administrator') || 
      $this->currentUser->hasRole('nfr_researcher');

    $menu_items = $this->buildMenuStructure($is_logged_in, $is_admin, $can_view_reports, $has_dashboard_access, $is_researcher);

    return [
      '#theme' => 'nfr_navigation_menu',
      '#menu_items' => $menu_items,
      '#attached' => [
        'library' => ['nfr/navigation'],
      ],
      '#cache' => [
        'contexts' => ['user.permissions', 'user.roles'],
      ],
      '#configuration' => $this->getConfiguration(),
      '#plugin_id' => $this->getPluginId(),
      '#base_plugin_id' => $this->getBaseId(),
      '#derivative_plugin_id' => $this->getDerivativeId(),
    ];
  }

  /**
   * Build the menu structure based on user permissions.
   *
   * @param bool $is_logged_in
   *   Whether user is authenticated.
   * @param bool $is_admin
   *   Whether user has admin permission.
   * @param bool $can_view_reports
   *   Whether user can view reports.
   * @param bool $has_dashboard_access
   *   Whether user should have access to My Dashboard.
   * @param bool $is_researcher
   *   Whether user has researcher access.
   *
   * @return array
   *   Menu structure array.
   */
  private function buildMenuStructure(bool $is_logged_in, bool $is_admin, bool $can_view_reports, bool $has_dashboard_access, bool $is_researcher): array {
    $menu = [];

    // Public Pages (always visible)
    $menu['public'] = [
      'title' => $this->t('About NFR'),
      'url' => Url::fromRoute('nfr.home'),
      'weight' => 0,
      'children' => [
        [
          'title' => $this->t('Home'),
          'url' => Url::fromRoute('nfr.home'),
          'weight' => 0,
        ],
        [
          'title' => $this->t('Public Statistics'),
          'url' => Url::fromRoute('nfr.public_data'),
          'weight' => 1,
        ],
      ],
    ];

    // Enrollment Pages (authenticated users only)
    if ($is_logged_in && ($has_dashboard_access || $is_admin)) {
      $menu['enrollment'] = [
        'title' => $this->t('Enrollment'),
        'url' => Url::fromRoute('nfr.welcome'),
        'weight' => 2,
        'children' => [
          [
            'title' => $this->t('Welcome'),
            'url' => Url::fromRoute('nfr.welcome'),
            'weight' => 0,
          ],
          [
            'title' => $this->t('Informed Consent'),
            'url' => Url::fromRoute('nfr.consent'),
            'weight' => 1,
          ],
          [
            'title' => $this->t('User Profile'),
            'url' => Url::fromRoute('nfr.user_profile'),
            'weight' => 2,
          ],
          [
            'title' => $this->t('Questionnaire'),
            'url' => Url::fromRoute('nfr.enrollment_questionnaire'),
            'weight' => 3,
          ],
          [
            'title' => $this->t('Review & Submit'),
            'url' => Url::fromRoute('nfr.review_submit'),
            'weight' => 4,
          ],
          [
            'title' => $this->t('Confirmation'),
            'url' => Url::fromRoute('nfr.confirmation'),
            'weight' => 5,
          ],
        ],
      ];

      // Participant Dashboard
      $menu['participant'] = [
        'title' => $this->t('My Dashboard'),
        'url' => Url::fromRoute('nfr.my_dashboard'),
        'weight' => 1,
        'children' => [
          [
            'title' => $this->t('Dashboard Home'),
            'url' => Url::fromRoute('nfr.my_dashboard'),
            'weight' => 0,
          ],
          [
            'title' => $this->t('Follow-Up Survey'),
            'url' => Url::fromRoute('nfr.follow_up'),
            'weight' => 1,
          ],
        ],
      ];
    }

    // Documentation (visible to logged in users and public)
    $doc_children = [
      [
        'title' => $this->t('Documentation Home'),
        'url' => Url::fromRoute('nfr.documentation'),
        'weight' => 0,
      ],
    ];

    $menu['documentation'] = [
      'title' => $this->t('Documentation'),
      'url' => Url::fromRoute('nfr.documentation'),
      'weight' => 4,
      'children' => $doc_children,
    ];

    // Admin Pages (admin permission required)
    if ($is_admin) {
      $admin_children = [
        [
          'title' => $this->t('Admin Dashboard'),
          'url' => Url::fromRoute('nfr.admin_dashboard'),
          'weight' => 0,
        ],
        [
          'title' => $this->t('Participant Management'),
          'url' => Url::fromRoute('nfr.admin_participants'),
          'weight' => 1,
        ],
        [
          'title' => $this->t('Cancer Registry Linkage'),
          'url' => Url::fromRoute('nfr.admin_linkage'),
          'weight' => 2,
        ],
        [
          'title' => $this->t('Data Quality Monitor'),
          'url' => Url::fromRoute('nfr.admin_data_quality'),
          'weight' => 3,
        ],
        [
          'title' => $this->t('User Support Issues'),
          'url' => Url::fromRoute('nfr.admin_issues'),
          'weight' => 5,
        ],
        [
          'title' => $this->t('System Settings'),
          'url' => Url::fromRoute('nfr.admin_settings'),
          'weight' => 6,
        ],
        [
          'title' => $this->t('Validation Dashboard'),
          'url' => Url::fromRoute('nfr.validation'),
          'weight' => 7,
        ],
      ];

      // Add reports if user has permission
      if ($can_view_reports || $is_admin) {
        $admin_children[] = [
          'title' => $this->t('Report Builder'),
          'url' => Url::fromRoute('nfr.admin_reports'),
          'weight' => 4,
        ];
      }

      $menu['admin'] = [
        'title' => $this->t('Administration'),
        'url' => Url::fromRoute('nfr.admin_dashboard'),
        'weight' => 3,
        'children' => $admin_children,
      ];
    }

    // Sort children by weight
    foreach ($menu as &$item) {
      if (!empty($item['children'])) {
        usort($item['children'], fn($a, $b) => $a['weight'] <=> $b['weight']);
      }
    }

    // Sort top level by weight
    uasort($menu, fn($a, $b) => $a['weight'] <=> $b['weight']);

    return $menu;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0; // Don't cache to ensure permission checks are current
  }

}
