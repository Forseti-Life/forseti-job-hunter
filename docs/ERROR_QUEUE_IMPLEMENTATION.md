# Error Queue Dashboard Implementation Plan (Flow 4)

**Priority:** 🔴 CRITICAL MVP BLOCKER  
**Estimated Effort:** 4-5 days (1 developer)  
**Complexity:** Medium  
**Status:** Ready for Implementation  
**Dependencies:** Flow 7 (User Profiles must be done first)

---

## Overview

The error queue provides admin visibility into system failures. Without this, **admins cannot diagnose or fix automation problems**, making the system unreliable for production.

### What Gets Done Today:
1. Simple error queue list view for admins
2. Mark-as-fixed checkbox functionality
3. Company management add interface
4. Basic error filtering

### What We're Delaying (Phase 2+):
- Complex error prioritization
- Automated error resolution
- User notification workflows
- Advanced analytics

---

## Database Structure

The `error_queue` content type already exists with these fields:
- `field_company_ref` - Reference to company node
- `field_user_ref` - Reference to affected user
- `field_job_posting_ref` - Reference to job if applicable
- `field_error_type` - Category: Authentication, Scraping, Submission, Technical
- `field_error_message` - Detailed error description
- `field_error_data` - Technical stack traces
- `field_priority` - Low, Medium, High, Critical
- `field_status` - New, In Progress, Resolved, Deferred
- `field_fixed` - Boolean checkbox for MVP

---

## Implementation Plan

### Task 1: Create View for Error Queue List (Estimated: 1-2 days)

**What We're Building:**
- Admin-only list view at `/admin/job-hunter/errors`
- Shows all errors in table format
- Able to filter by date and company
- Able to mark as fixed with one click

**Implementation Steps:**

```yaml
# Add to job_hunter.module or create Views configuration

function job_hunter_views_data_alter(&$data) {
  // Add custom fields to views if needed
  $data['node__field_error_type']['field_error_type'] = [
    'title' => t('Error Type'),
    'field' => [
      'id' => 'field',
      'field_name' => 'field_error_type',
    ],
  ];
}
```

**Views Configuration (via UI or config/install):**
```
View: Error Queue List
Machine name: error_queue_list
Path: /admin/job-hunter/errors

Fields to display:
- Error ID (nid)
- Error Message (field_error_message)
- Company (field_company_ref) - with entity link
- User (field_user_ref) - with entity link  
- Error Type (field_error_type)
- Status (field_status)
- Created Date (created) - formatted date
- Fixed Checkbox (field_fixed) - inline edit

Filters (visible):
- Status: default "New" or blank
- Company: optional dropdown
- Created date: date range picker
- Error Type: optional dropdown

Sorting (default):
- Created date (newest first)
- Then by Status descending (New first)

Access:
- Requires 'administer job_hunter' permission
- Admin users only
```

### Task 2: Create Admin Menu & Routes (Estimated: 1 day)

**File:** `/sites/forseti/web/modules/custom/job_hunter/job_hunter.routing.yml`

```yaml
job_hunter.error_queue_list:
  path: '/admin/job-hunter/errors'
  defaults:
    _controller: '\Drupal\job_hunter\Controller\ErrorQueueController::listErrors'
    _title: 'Error Queue'
  requirements:
    _permission: 'administer job_hunter'

job_hunter.error_queue_view:
  path: '/admin/job-hunter/errors/{error_id}'
  defaults:
    _controller: '\Drupal\job_hunter\Controller\ErrorQueueController::viewError'
    _title: 'View Error Details'
  requirements:
    _permission: 'administer job_hunter'
    error_id: '\d+'

job_hunter.error_queue_mark_fixed:
  path: '/admin/job-hunter/errors/{error_id}/fix'
  defaults:
    _controller: '\Drupal\job_hunter\Controller\ErrorQueueController::markFixed'
  requirements:
    _permission: 'administer job_hunter'
    _method: 'POST'
    error_id: '\d+'
```

**File:** `job_hunter.links.menu.yml`

```yaml
job_hunter.error_queue_admin:
  title: 'Error Queue'
  parent: 'system.admin_content'
  route_name: 'job_hunter.error_queue_list'
  weight: 1
```

### Task 3: Create ErrorQueue Controller (Estimated: 1-2 days)

**File:** `/sites/forseti/web/modules/custom/job_hunter/src/Controller/ErrorQueueController.php`

```php
<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for error queue admin interface.
 */
class ErrorQueueController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $controller = new static();
    $controller->entityTypeManager = $container->get('entity_type.manager');
    return $controller;
  }

  /**
   * List all errors in queue.
   */
  public function listErrors() {
    // Query for all error_queue nodes
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'error_queue')
      ->sort('created', 'DESC')
      ->pager(25);

    $error_ids = $query->execute();
    $errors = $this->entityTypeManager->getStorage('node')
      ->loadMultiple($error_ids);

    $rows = [];
    foreach ($errors as $error) {
      $rows[] = [
        'id' => $error->id(),
        'message' => $error->get('field_error_message')->value,
        'company' => $error->get('field_company_ref')->entity 
          ? $error->get('field_company_ref')->entity->label() 
          : 'N/A',
        'type' => $error->get('field_error_type')->value ?? 'Unknown',
        'status' => $error->get('field_status')->value ?? 'New',
        'created' => \Drupal::service('date.formatter')
          ->format($error->getCreatedTime(), 'short'),
        'actions' => [
          'data' => [
            '#type' => 'link',
            '#title' => 'View',
            '#url' => \Drupal\Core\Url::fromRoute(
              'job_hunter.error_queue_view',
              ['error_id' => $error->id()]
            ),
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        'message' => t('Error Message'),
        'company' => t('Company'),
        'type' => t('Type'),
        'status' => t('Status'),
        'created' => t('Created'),
        'actions' => t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => t('No errors in queue.'),
    ];

    $build['pager'] = ['#type' => 'pager'];

    return $build;
  }

  /**
   * View error details.
   */
  public function viewError($error_id) {
    $error = $this->entityTypeManager->getStorage('node')
      ->load($error_id);

    if (!$error || $error->bundle() !== 'error_queue') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => 'error-details'],
    ];

    // Error message
    $build['message'] = [
      '#type' => 'fieldset',
      '#title' => t('Error Message'),
      'content' => [
        '#markup' => nl2br($error->get('field_error_message')->value),
      ],
    ];

    // Technical details
    if (!$error->get('field_error_data')->isEmpty()) {
      $build['technical'] = [
        '#type' => 'fieldset',
        '#title' => t('Technical Details'),
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
        'content' => [
          '#markup' => '<pre>' . htmlspecialchars($error->get('field_error_data')->value) . '</pre>',
        ],
      ];
    }

    // Mark as fixed button
    $build['actions'] = [
      '#type' => 'form_actions',
      '#attributes' => ['class' => 'button-group'],
    ];

    $build['actions']['fixed'] = [
      '#type' => 'submit',
      '#value' => t('Mark as Fixed'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    return $build;
  }

  /**
   * Mark error as fixed.
   */
  public function markFixed($error_id) {
    $error = $this->entityTypeManager->getStorage('node')
      ->load($error_id);

    if (!$error || $error->bundle() !== 'error_queue') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $error->set('field_fixed', TRUE);
    $error->set('field_status', 'Resolved');
    $error->save();

    \Drupal::messenger()->addStatus(t('Error marked as fixed.'));

    return $this->redirect('job_hunter.error_queue_list');
  }

}
```

### Task 4: Add Company Management (Estimated: 1 day)

**Add to ErrorQueueController:**

```php
/**
 * Form for adding new company.
 */
public function addCompanyForm($form, \Drupal\Core\Form\FormStateInterface $form_state) {
  $form['company_name'] = [
    '#type' => 'textfield',
    '#title' => t('Company Name'),
    '#required' => TRUE,
  ];

  $form['field_website'] = [
    '#type' => 'url',
    '#title' => t('Company Website'),
  ];

  $form['field_careers_url'] = [
    '#type' => 'url',
    '#title' => t('Careers Page URL'),
    '#description' => t('The URL where we will scrape job postings from.'),
  ];

  $form['field_active'] = [
    '#type' => 'checkbox',
    '#title' => t('Active (scrape jobs from this company)'),
    '#default_value' => TRUE,
  ];

  $form['actions'] = ['#type' => 'actions'];
  $form['actions']['submit'] = [
    '#type' => 'submit',
    '#value' => t('Add Company'),
  ];

  return $form;
}

/**
 * Submit handler for add company form.
 */
public function addCompanySubmit($form, \Drupal\Core\Form\FormStateInterface $form_state) {
  $company = $this->entityTypeManager->getStorage('node')->create([
    'type' => 'company',
    'title' => $form_state->getValue('company_name'),
    'field_website' => $form_state->getValue('field_website'),
    'field_careers_url' => $form_state->getValue('field_careers_url'),
    'field_active' => $form_state->getValue('field_active'),
  ]);

  $company->save();

  \Drupal::messenger()->addStatus(
    t('Company "@name" has been added.', 
      ['@name' => $form_state->getValue('company_name')])
  );

  return $this->redirect('job_hunter.error_queue_list');
}
```

### Task 5: Create Error Service (Estimated: 1-2 days)

**File:** `/sites/forseti/web/modules/custom/job_hunter/src/Service/ErrorQueueService.php`

```php
<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\user\UserInterface;
use Drupal\node\NodeInterface;

/**
 * Service for managing error queue.
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
   * Constructor.
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
   * @param string $error_message
   *   Human-readable error message.
   * @param string $error_type
   *   Error type: Authentication, Scraping, Submission, Technical.
   * @param \Drupal\node\NodeInterface|null $company
   *   Company node if applicable.
   * @param \Drupal\user\UserInterface|null $user
   *   User entity if applicable.
   * @param array $error_data
   *   Technical error details (array will be JSON-encoded).
   * @param string $priority
   *   Priority level: Low, Medium, High, Critical.
   */
  public function logError(
    $error_message,
    $error_type,
    NodeInterface $company = NULL,
    UserInterface $user = NULL,
    array $error_data = [],
    $priority = 'Medium'
  ): NodeInterface {
    
    // Create error node
    $error_node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'error_queue',
      'title' => substr($error_message, 0, 100),
      'field_error_message' => $error_message,
      'field_error_type' => $error_type,
      'field_priority' => $priority,
      'field_status' => 'New',
      'field_fixed' => FALSE,
    ]);

    // Add optional references
    if ($company) {
      $error_node->set('field_company_ref', $company->id());
    }

    if ($user) {
      $error_node->set('field_user_ref', $user->id());
    }

    // Add technical data if provided
    if (!empty($error_data)) {
      $error_node->set('field_error_data', json_encode($error_data, JSON_PRETTY_PRINT));
    }

    $error_node->save();

    // Log to Drupal logs
    $this->loggerFactory->get('job_hunter')->error(
      '@type error in Job Hunter: @message',
      [
        '@type' => $error_type,
        '@message' => $error_message,
      ]
    );

    // Alert admin if critical
    if ($priority === 'Critical') {
      \Drupal::messenger()->addError(
        t('CRITICAL: @message', ['@message' => $error_message])
      );
    }

    return $error_node;
  }

  /**
   * Get count of unresolved errors.
   */
  public function getUnresolvedErrorCount(): int {
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'error_queue')
      ->condition('field_fixed', FALSE);

    return $query->count()->execute();
  }

  /**
   * Get recent errors.
   */
  public function getRecentErrors($limit = 10): array {
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'error_queue')
      ->sort('created', 'DESC')
      ->range(0, $limit);

    $error_ids = $query->execute();
    return $this->entityTypeManager->getStorage('node')
      ->loadMultiple($error_ids);
  }

}
```

### Task 6: Register Services (Estimated: 0.5 days)

**File:** `/sites/forseti/web/modules/custom/job_hunter/job_hunter.services.yml`

```yaml
job_hunter.error_queue_service:
  class: Drupal\job_hunter\Service\ErrorQueueService
  arguments:
    - '@entity_type.manager'
    - '@logger.factory'
```

### Task 7: Create Admin Toolbar Widget (Estimated: 1 day)

**Add to job_hunter.module:**

```php
/**
 * Implements hook_toolbar().
 */
function job_hunter_toolbar() {
  $items = [];

  $error_service = \Drupal::service('job_hunter.error_queue_service');
  $error_count = $error_service->getUnresolvedErrorCount();

  $items['job_hunter'] = [
    '#type' => 'toolbar_item',
    'tab' => [
      '#type' => 'link',
      '#title' => [
        'text' => t('Job Hunter'),
        '#suffix' => $error_count > 0 
          ? ' <span class="badge badge-danger">' . $error_count . '</span>' 
          : '',
      ],
      '#url' => \Drupal\Core\Url::fromRoute('job_hunter.error_queue_list'),
      '#attributes' => [
        'class' => ['toolbar-item', 'tab', 'job-hunter-link'],
      ],
    ],
  ];

  return $items;
}
```

### Task 8: Testing (Estimated: 2 days)

**File:** `/sites/forseti/web/modules/custom/job_hunter/tests/src/Functional/ErrorQueueTest.php`

```php
<?php

namespace Drupal\Tests\job_hunter\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\Node;

/**
 * Tests for error queue functionality.
 *
 * @group job_hunter
 */
class ErrorQueueTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['job_hunter', 'node'];

  /**
   * Test logging error to queue.
   */
  public function testLogErrorToQueue() {
    $error_service = \Drupal::service('job_hunter.error_queue_service');

    $error = $error_service->logError(
      'Test error message',
      'Technical',
      NULL,
      NULL,
      ['stack_trace' => 'Some trace'],
      'High'
    );

    $this->assertNotEmpty($error->id());
    $this->assertEquals('error_queue', $error->bundle());
    $this->assertEquals('Test error message', $error->get('field_error_message')->value);
  }

  /**
   * Test admin can view error queue.
   */
  public function testAdminViewErrorQueue() {
    // Create admin user
    $admin = $this->drupalCreateUser(['administer job_hunter']);
    $this->drupalLogin($admin);

    // View error queue page
    $this->drupalGet('/admin/job-hunter/errors');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Test marking error as fixed.
   */
  public function testMarkErrorAsFixed() {
    $error_service = \Drupal::service('job_hunter.error_queue_service');

    $error = $error_service->logError('Test', 'Technical');
    $error_id = $error->id();

    // Create admin and login
    $admin = $this->drupalCreateUser(['administer job_hunter']);
    $this->drupalLogin($admin);

    // Mark as fixed
    $this->drupalGetForm('job_hunter.error_queue_mark_fixed', ['error_id' => $error_id]);
    $this->submitForm([], 'Mark as Fixed');

    // Verify marked fixed
    $error = \Drupal::entityTypeManager()->getStorage('node')->load($error_id);
    $this->assertTrue($error->get('field_fixed')->value);
  }

}
```

---

## Implementation Checklist

### Phase 1: Database & Structure (Day 1)
- [ ] Verify `error_queue` content type exists with all fields
- [ ] Create ErrorQueue service with logging capabilities
- [ ] Register service in services.yml
- [ ] Add Permissions (administer job_hunter)

### Phase 2: Admin Views (Day 2)
- [ ] Create error queue list view/route
- [ ] Create error detail view/route
- [ ] Implement error filtering (date, company, type)
- [ ] Implement mark-as-fixed functionality
- [ ] Test list view displays all errors

### Phase 3: Company Management (Day 2-3)
- [ ] Add company form to admin interface
- [ ] Implement company creation from admin
- [ ] Verify company nodes created correctly
- [ ] Add company selection to add form

### Phase 4: UI Polish & Testing (Day 3-4)
- [ ] Create admin toolbar widget showing error count
- [ ] Add CSS styling for error queue
- [ ] Write functional tests
- [ ] Test end-to-end workflow
- [ ] Admin acceptance testing

---

## Success Criteria

After completion, validate:

- ✅ Admin sees error queue list at `/admin/job-hunter/errors`
- ✅ Admins can filter errors by date and company
- ✅ Admins can mark errors as fixed with one click
- ✅ Admins can add companies from admin interface
- ✅ Error count badge shows in toolbar
- ✅ Error details page displays technical information
- ✅ All errors properly logged and queryable
- ✅ Only admins can access error queue

---

## Integration Points

- Called by: Job Discovery Service, Application Submission Service (when errors occur)
- Depends on: User Profiles (to reference affected users)
- Feeds into: Admin Dashboard (error metrics)

---

## Next Steps After Completion

1. User Support Contact Form (Flow 5) - Users can report issues
2. Company Management Interface (Flow 8) - Full company CRUD
3. Application Tracking (Flow 9) - Track what gets submitted
