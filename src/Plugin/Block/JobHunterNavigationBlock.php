<?php

namespace Drupal\job_hunter\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Provides a 'Job Hunter Navigation' Block.
 *
 * @Block(
 *   id = "job_hunter_navigation",
 *   admin_label = @Translation("Job Hunter Navigation"),
 *   category = @Translation("Job Application Automation"),
 * )
 */
class JobHunterNavigationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new JobHunterNavigationBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountInterface $current_user) {
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
  public function build() {
    $navigation = [];

    // Add "Report a Problem" link if forseti_content module is enabled
    $moduleHandler = \Drupal::service('module_handler');
    if ($moduleHandler->moduleExists('forseti_content')) {
      $navigation['report_problem'] = [
        'title' => $this->t('Report a Problem'),
        'subtitle' => $this->t('We are in BETA'),
        'url' => Url::fromRoute('forseti_content.talk_with_forseti'),
        'icon' => 'exclamation-circle',
        'weight' => -20,
        'classes' => 'report-problem-beta',
      ];
    }

    $navigation['home'] = [
      'title' => $this->t('Dashboard'),
      'url' => Url::fromRoute('job_hunter.dashboard'),
      'icon' => 'home',
      'weight' => 0,
    ];
    $navigation['profile'] = [
      'title' => $this->t('My Profile'),
      'url' => Url::fromRoute('job_hunter.user_job_seeker_view'),
      'icon' => 'user',
      'weight' => 10,
    ];
    $navigation['my_jobs'] = [
      'title' => $this->t('My Jobs'),
      'url' => Url::fromRoute('job_hunter.my_jobs'),
      'icon' => 'briefcase',
      'weight' => 12,
    ];
    $navigation['application_submission'] = [
      'title' => $this->t('Application Submission'),
      'url' => Url::fromRoute('job_hunter.application_submission'),
      'icon' => 'send',
      'weight' => 13,
    ];

    if ($this->currentUser->hasPermission('administer job application automation')) {
      $navigation['job_discovery'] = [
        'title' => $this->t('Job Discovery'),
        'url' => Url::fromRoute('job_hunter.job_discovery'),
        'icon' => 'search',
        'weight' => 14,
      ];
    }

    $navigation['company_research'] = [
      'title' => $this->t('Company Research'),
      'url' => Url::fromRoute('job_hunter.company_research'),
      'icon' => 'building',
      'weight' => 16,
    ];

    // Add admin links if user has permission
    if ($this->currentUser->hasPermission('administer job application automation')) {
      $navigation['settings'] = [
        'title' => $this->t('Settings'),
        'url' => Url::fromRoute('job_hunter.settings'),
        'icon' => 'cog',
        'weight' => 80,
      ];

      $navigation['credentials'] = [
        'title' => $this->t('ATS Credentials'),
        'url' => Url::fromRoute('job_hunter.credentials'),
        'icon' => 'key',
        'weight' => 82,
      ];

      $navigation['queue_management'] = [
        'title' => $this->t('Queue Management'),
        'url' => Url::fromRoute('job_hunter.queue_management'),
        'icon' => 'wrench',
        'weight' => 85,
      ];

      $navigation['opportunity_management'] = [
        'title' => $this->t('Opportunity Management'),
        'url' => Url::fromRoute('job_hunter.opportunity_management'),
        'icon' => 'database',
        'weight' => 87,
      ];

      $navigation['documentation'] = [
        'title' => $this->t('Documentation'),
        'url' => Url::fromRoute('job_hunter.documentation'),
        'icon' => 'book',
        'weight' => 90,
      ];
    }

    // Sort by weight
    uasort($navigation, function($a, $b) {
      return $a['weight'] <=> $b['weight'];
    });

    return [
      '#theme' => 'job_hunter_navigation',
      '#navigation' => $navigation,
      '#attached' => [
        'library' => [
          'job_hunter/job-hunter-navigation',
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
      ],
    ];
  }

}
