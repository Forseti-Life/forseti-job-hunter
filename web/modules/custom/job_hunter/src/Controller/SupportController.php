<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for user support functionality.
 */
class SupportController extends ControllerBase {

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a SupportController object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user account.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder service.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(
    AccountInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    FormBuilderInterface $form_builder,
    MailManagerInterface $mail_manager,
    DateFormatterInterface $date_formatter
  ) {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->formBuilder = $form_builder;
    $this->mailManager = $mail_manager;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
      $container->get('plugin.manager.mail'),
      $container->get('date.formatter')
    );
  }

  /**
   * Displays the support contact form.
   *
   * @return array
   *   Render array containing the support form and information.
   */
  public function contactForm() {
    $build = [];

    // Add page header
    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['support-header']],
    ];

    $build['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Contact Support'),
      '#attributes' => ['class' => ['support-title']],
    ];

    $build['header']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Having trouble with the job application system? We\'re here to help! Please provide as much detail as possible about your issue.'),
      '#attributes' => ['class' => ['support-description']],
    ];

    // Add the support form
    $build['form'] = $this->formBuilder->getForm('Drupal\job_hunter\Form\SupportForm');

    // Add helpful information section
    $build['help'] = [
      '#type' => 'details',
      '#title' => $this->t('Common Issues & Quick Help'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['support-help']],
    ];

    $build['help']['content'] = [
      '#type' => 'markup',
      '#markup' => '
        <div class="help-section">
          <h3>' . $this->t('Before submitting a support request:') . '</h3>
          <ul>
            <li>' . $this->t('Check that your profile is at least 70% complete for job applications') . '</li>
            <li>' . $this->t('Verify your email address is correct in your profile') . '</li>
            <li>' . $this->t('Try refreshing the page or clearing your browser cache') . '</li>
            <li>' . $this->t('Check our <a href="@docs">documentation</a> for common solutions', ['@docs' => '#']) . '</li>
          </ul>
          <h3>' . $this->t('For technical issues:') . '</h3>
          <ul>
            <li>' . $this->t('Include your browser type and version') . '</li>
            <li>' . $this->t('Describe the exact steps that led to the problem') . '</li>
            <li>' . $this->t('Include any error messages you received') . '</li>
          </ul>
        </div>
      ',
    ];

    // Add CSS for styling
    $build['#attached']['library'][] = 'job_hunter/support';

    return $build;
  }

  /**
   * Displays the admin support dashboard.
   *
   * @return array
   *   Render array containing the support management interface.
   */
  public function adminDashboard() {
    $build = [];

    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['admin-support-header']],
    ];

    $build['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Support Request Management'),
      '#attributes' => ['class' => ['admin-support-title']],
    ];

    // Get support requests from our content type (we'll need to create this)
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'support_request')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->accessCheck(TRUE);

    $nids = $query->execute();
    
    if (empty($nids)) {
      $build['no_requests'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No support requests found.') . '</p>',
      ];
      return $build;
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    // Create a table of support requests
    $build['requests_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Date'),
        $this->t('User'),
        $this->t('Issue Type'),
        $this->t('Subject'),
        $this->t('Status'),
        $this->t('Actions'),
      ],
      '#attributes' => ['class' => ['support-requests-table']],
      '#empty' => $this->t('No support requests found.'),
    ];

    foreach ($nodes as $node) {
      $user_field = $node->get('field_support_user_ref')->entity;
      $user_name = $user_field ? $user_field->getDisplayName() : $this->t('Anonymous');
      
      $build['requests_table'][$node->id()] = [
        'date' => [
          '#markup' => $this->dateFormatter->format($node->getCreatedTime(), 'short'),
        ],
        'user' => [
          '#markup' => $user_name,
        ],
        'issue_type' => [
          '#markup' => $node->get('field_support_issue_type')->value ?: $this->t('N/A'),
        ],
        'subject' => [
          '#markup' => $node->get('field_support_subject')->value ?: $this->t('No subject'),
        ],
        'status' => [
          '#markup' => $node->get('field_support_status')->value ?: $this->t('Open'),
        ],
        'actions' => [
          '#type' => 'dropbutton',
          '#links' => [
            'view' => [
              'title' => $this->t('View'),
              'url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()]),
            ],
            'edit' => [
              'title' => $this->t('Edit'),
              'url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()]),
            ],
          ],
        ],
      ];
    }

    // Add summary statistics
    $build['stats'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['support-stats']],
      '#weight' => -10,
    ];

    $build['stats']['total'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('Total Requests: @count', ['@count' => count($nodes)]),
      '#attributes' => ['class' => ['stat-item']],
    ];

    return $build;
  }

}