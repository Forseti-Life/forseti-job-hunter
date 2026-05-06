# User Profile Forms Implementation Plan (Flow 7)

**Priority:** 🔴 CRITICAL MVP BLOCKER  
**Estimated Effort:** 3-4 days (1 developer)  
**Complexity:** Medium  
**Status:** Ready for Implementation

---

## Overview

Users must complete their job search profile before the system can:
- Match them to jobs
- Generate tailored resumes
- Automate applications

Without this flow, **the entire Job Hunter system is unusable**.

---

## Current State

✅ **Already Complete:**
- User entity extended with 24 custom fields (database level)
- All field definitions created
- Field storage in database

❌ **NOT COMPLETE:**
- User registration form customization
- Profile edit form integration
- Resume file upload handling
- Form validation and required fields
- User-facing profile dashboard

---

## Implementation Plan

### Step 1: Create User Profile Service (Estimated: 2-3 hours)

**File:** `/sites/forseti/web/modules/custom/job_hunter/src/Service/UserProfileService.php`

```php
<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\user\UserInterface;
use Drupal\file\FileInterface;

/**
 * Service for managing user profiles and job search data.
 */
class UserProfileService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $fieldManager;

  /**
   * Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $field_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fieldManager = $field_manager;
  }

  /**
   * Get user profile completeness percentage.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return int
   *   Percentage 0-100 of profile completion.
   */
  public function getProfileCompleteness(UserInterface $user): int {
    $required_fields = [
      'field_resume_file',
      'field_professional_summary',
      'field_work_authorization',
      'field_available_start_date',
      'field_remote_preference',
    ];

    $completed_fields = 0;
    foreach ($required_fields as $field_name) {
      if ($user->hasField($field_name) && !$user->get($field_name)->isEmpty()) {
        $completed_fields++;
      }
    }

    return (int) ($completed_fields / count($required_fields)) * 100;
  }

  /**
   * Validate user profile for job applications.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return array
   *   Array of validation errors, empty if valid.
   */
  public function validateProfile(UserInterface $user): array {
    $errors = [];

    // Check required fields
    if ($user->get('field_resume_file')->isEmpty()) {
      $errors[] = 'Resume file is required';
    }

    if ($user->get('field_work_authorization')->isEmpty()) {
      $errors[] = 'Work authorization status is required';
    }

    if ($user->get('field_available_start_date')->isEmpty()) {
      $errors[] = 'Available start date is required';
    }

    return $errors;
  }

  /**
   * Mark profile as complete.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   */
  public function markProfileComplete(UserInterface $user): void {
    $completeness = $this->getProfileCompleteness($user);
    if ($completeness >= 70) {
      $user->set('field_profile_completeness', $completeness);
      $user->set('field_last_profile_update', \Drupal::time()->getRequestTime());
      $user->save();
    }
  }

}
```

### Step 2: Extend User Registration Form (Estimated: 4-5 hours)

**File:** `/sites/forseti/web/modules/custom/job_hunter/job_hunter.module`

Add to module file (or create `src/EventSubscriber/UserFormSubscriber.php`):

```php
/**
 * Implements hook_form_user_register_form_alter().
 */
function job_hunter_form_user_register_form_alter(&$form, \Drupal\Core\Form\FormStateInterface $form_state) {
  // Add section for job search profile
  $form['job_search_profile'] = [
    '#type' => 'fieldset',
    '#title' => t('Job Search Profile'),
    '#description' => t('Help us customize your job search experience.'),
    '#weight' => 0,
    '#open' => TRUE,
  ];

  // Add key fields to registration form
  $form['job_search_profile']['field_resume_file'] = $form['field_resume_file'] ?? [];
  $form['job_search_profile']['field_professional_summary'] = $form['field_professional_summary'] ?? [];
  $form['job_search_profile']['field_work_authorization'] = $form['field_work_authorization'] ?? [];
  $form['job_search_profile']['field_available_start_date'] = $form['field_available_start_date'] ?? [];
  $form['job_search_profile']['field_remote_preference'] = $form['field_remote_preference'] ?? [];

  // Set required
  if (isset($form['job_search_profile']['field_resume_file'])) {
    $form['job_search_profile']['field_resume_file']['#required'] = TRUE;
  }

  // Move form elements
  unset($form['field_resume_file']);
  unset($form['field_professional_summary']);
  unset($form['field_work_authorization']);
  unset($form['field_available_start_date']);
  unset($form['field_remote_preference']);
}

/**
 * Implements hook_form_user_form_alter().
 */
function job_hunter_form_user_form_alter(&$form, \Drupal\Core\Form\FormStateInterface $form_state) {
  // Organize profile edit form
  $form['job_search'] = [
    '#type' => 'fieldset',
    '#title' => t('Job Search Information'),
    '#collapsible' => FALSE,
    '#weight' => -5,
  ];

  // Move profile fields into organized section
  $profile_fields = [
    'field_resume_file',
    'field_professional_summary',
    'field_skills_summary',
    'field_work_authorization',
    'field_salary_expectation_min',
    'field_salary_expectation_max',
    'field_available_start_date',
    'field_remote_preference',
    'field_relocation_willing',
    'field_keywords_interested',
    'field_target_job_titles',
  ];

  foreach ($profile_fields as $field_name) {
    if (isset($form[$field_name])) {
      $form['job_search'][$field_name] = $form[$field_name];
      unset($form[$field_name]);
    }
  }

  // Add save callback to update completeness
  $form['#submit'][] = 'job_hunter_user_form_submit';
}

/**
 * Custom submit handler for user form.
 */
function job_hunter_user_form_submit(&$form, \Drupal\Core\Form\FormStateInterface $form_state) {
  /** @var \Drupal\user\UserInterface $user */
  $user = $form_state->getFormObject()->getEntity();

  // Update profile completeness
  $profile_service = \Drupal::service('job_hunter.user_profile_service');
  $profile_service->markProfileComplete($user);
}
```

### Step 3: Create User Profile Management Page (Estimated: 3-4 hours)

**File:** `/sites/forseti/web/modules/custom/job_hunter/src/Controller/UserProfileController.php`

Add methods:

```php
/**
 * Display user profile dashboard.
 */
public function profileDashboard(UserInterface $user) {
  $profile_service = \Drupal::service('job_hunter.user_profile_service');
  
  $completeness = $profile_service->getProfileCompleteness($user);
  $validation_errors = $profile_service->validateProfile($user);

  $build['#theme'] = 'user_profile_dashboard';
  $build['#completeness'] = $completeness;
  $build['#validation_errors'] = $validation_errors;
  $build['#user'] = $user;
  $build['#edit_link'] = $user->toLink(t('Edit Profile'), 'edit-form')->toRenderable();

  return $build;
}

/**
 * Build profile fields summary.
 */
public function getProfileSummary(UserInterface $user): array {
  $summary = [];

  $fields = [
    'field_resume_file' => t('Resume'),
    'field_professional_summary' => t('Professional Summary'),
    'field_work_authorization' => t('Work Authorization'),
    'field_available_start_date' => t('Available Start Date'),
    'field_remote_preference' => t('Remote Preference'),
    'field_salary_expectation_min' => t('Salary Expectation'),
    'field_keywords_interested' => t('Job Keywords'),
    'field_target_companies' => t('Target Companies'),
  ];

  foreach ($fields as $field_name => $label) {
    if ($user->hasField($field_name)) {
      $value = $user->get($field_name)->getValue();
      $summary[$field_name] = [
        'label' => $label,
        'value' => $value,
        'complete' => !empty($value),
      ];
    }
  }

  return $summary;
}
```

### Step 4: Add Routes and Permissions (Estimated: 1-2 hours)

**File:** `/sites/forseti/web/modules/custom/job_hunter/job_hunter.routing.yml`

```yaml
job_hunter.user_profile_dashboard:
  path: '/user/{user}/profile-dashboard'
  defaults:
    _controller: '\Drupal\job_hunter\Controller\UserProfileController::profileDashboard'
    _title: 'My Job Search Profile'
  requirements:
    _permission: 'access own user profile'
    user: '\d+'

job_hunter.profile_edit:
  path: '/user/{user}/profile-edit'
  defaults:
    _controller: '\Drupal\user\Controller\UserController::reset'
    _title: 'Edit Profile'
  requirements:
    _permission: 'access own user profile'
    user: '\d+'
```

**File:** `/sites/forseti/web/modules/custom/job_hunter/job_hunter.permissions.yml`

```yaml
'access own job search profile':
  title: 'Access own job search profile'
  description: 'Allow users to access and manage their job search profile'
  roles:
    - authenticated

'manage own resume':
  title: 'Manage own resume'
  description: 'Allow users to upload and manage their resume'
  roles:
    - authenticated

'view profile completeness':
  title: 'View profile completeness'
  description: 'Allow users to see their profile completion percentage'
  roles:
    - authenticated
```

### Step 5: Create Profile Dashboard Template (Estimated: 2-3 hours)

**File:** `/sites/forseti/web/modules/custom/job_hunter/templates/user-profile-dashboard.html.twig`

```twig
<div class="user-profile-dashboard">
  <h1>{{ 'My Job Search Profile'|t }}</h1>

  <!-- Profile Completeness Progress -->
  <div class="profile-completeness">
    <h2>{{ 'Profile Status'|t }}</h2>
    <div class="progress-bar">
      <div class="progress-fill" style="width: {{ completeness }}%">
        <span class="completeness-text">{{ completeness }}% {{ 'Complete'|t }}</span>
      </div>
    </div>
    
    {% if completeness < 70 %}
      <p class="warning">
        {{ 'Complete your profile to unlock job matching and automation.'|t }}
      </p>
    {% else %}
      <p class="success">
        {{ 'Your profile is ready! You can now start your job search.'|t }}
      </p>
    {% endif %}
  </div>

  <!-- Validation Errors -->
  {% if validation_errors %}
    <div class="validation-errors alert alert-danger">
      <h3>{{ 'Action Required'|t }}</h3>
      <ul>
        {% for error in validation_errors %}
          <li>{{ error }}</li>
        {% endfor %}
      </ul>
    </div>
  {% endif %}

  <!-- Profile Summary -->
  <div class="profile-summary">
    <h2>{{ 'Your Information'|t }}</h2>
    <table class="profile-fields">
      <tbody>
        {% for field_name, field_info in profile_summary %}
          <tr class="{% if field_info.complete %}complete{% else %}incomplete{% endif %}">
            <td class="field-label">{{ field_info.label }}</td>
            <td class="field-status">
              {% if field_info.complete %}
                <span class="badge badge-success">{{ 'Complete'|t }}</span>
              {% else %}
                <span class="badge badge-warning">{{ 'Missing'|t }}</span>
              {% endif %}
            </td>
          </tr>
        {% endfor %}
      </tbody>
    </table>
  </div>

  <!-- Edit Profile Button -->
  <div class="profile-actions">
    {{ edit_link }}
  </div>

  <!-- Resume Information -->
  <div class="resume-section">
    <h2>{{ 'Your Resume'|t }}</h2>
    {% if user.field_resume_file.0 %}
      <p>{{ 'Resume uploaded: '|t }}
        <a href="{{ file_url(user.field_resume_file.0) }}">
          {{ user.field_resume_file.0.filename }}
        </a>
      </p>
      <p class="help-text">{{ 'This resume will be tailored for each job application.'|t }}</p>
    {% else %}
      <p class="alert alert-warning">
        {{ 'Please upload your resume to get started.'|t }}
      </p>
    {% endif %}
  </div>

  <!-- Quick Links -->
  <div class="quick-links">
    <a href="/job-browser" class="btn btn-primary">{{ 'View Available Jobs'|t }}</a>
    <a href="/user/{{ user.id }}/profile-edit" class="btn btn-secondary">{{ 'Edit Profile'|t }}</a>
  </div>
</div>
```

### Step 6: Add CSS Styling (Estimated: 1-2 hours)

**File:** `/sites/forseti/web/modules/custom/job_hunter/css/user-profile.css`

```css
.user-profile-dashboard {
  max-width: 800px;
  margin: 20px auto;
  padding: 20px;
  background: #f5f5f5;
  border-radius: 8px;
}

.profile-completeness {
  margin-bottom: 30px;
  padding: 20px;
  background: white;
  border-radius: 6px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.progress-bar {
  height: 30px;
  background: #e0e0e0;
  border-radius: 15px;
  overflow: hidden;
  margin: 10px 0;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #4CAF50, #45a049);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  transition: width 0.3s ease;
}

.profile-summary table {
  width: 100%;
  margin-top: 15px;
  border-collapse: collapse;
}

.profile-summary tr {
  border-bottom: 1px solid #ddd;
  padding: 10px 0;
}

.profile-summary tr:hover {
  background: #f9f9f9;
}

.profile-summary td {
  padding: 12px;
}

.field-label {
  font-weight: 500;
}

.profile-summary tr.complete .field-status {
  color: #4CAF50;
}

.profile-summary tr.incomplete .field-status {
  color: #ff9800;
}

.profile-actions {
  margin-top: 30px;
  padding-top: 20px;
  border-top: 1px solid #ddd;
}

.btn {
  display: inline-block;
  padding: 10px 20px;
  margin-right: 10px;
  border-radius: 4px;
  text-decoration: none;
  font-weight: 500;
}

.btn-primary {
  background: #2196F3;
  color: white;
}

.btn-primary:hover {
  background: #0b7dda;
}

.btn-secondary {
  background: #757575;
  color: white;
}

.btn-secondary:hover {
  background: #616161;
}

.alert {
  padding: 15px;
  margin: 15px 0;
  border-radius: 4px;
}

.alert-warning {
  background: #fff3cd;
  color: #856404;
  border: 1px solid #ffeaa7;
}

.alert-danger {
  background: #f8d7da;
  color: #721c24;
  border: 1px solid #f5c6cb;
}

.alert-success {
  background: #d4edda;
  color: #155724;
  border: 1px solid #c3e6cb;
}

.badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: bold;
}

.badge-success {
  background: #4CAF50;
  color: white;
}

.badge-warning {
  background: #ff9800;
  color: white;
}
```

### Step 7: Integration Testing (Estimated: 3-4 hours)

**File:** `/sites/forseti/web/modules/custom/job_hunter/tests/src/Functional/UserProfileFormTest.php`

```php
<?php

namespace Drupal\Tests\job_hunter\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;

/**
 * Tests for user profile form functionality.
 *
 * @group job_hunter
 */
class UserProfileFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['job_hunter', 'user', 'file'];

  /**
   * Test user registration with profile fields.
   */
  public function testUserRegistrationWithProfileFields() {
    // Create test user with profile data
    $test_user = User::create([
      'name' => 'testuser',
      'mail' => 'test@example.com',
      'pass' => 'password',
    ]);
    $test_user->save();

    // Verify user created
    $this->assertNotEmpty($test_user->id());

    // Test profile completeness calculation
    $profile_service = \Drupal::service('job_hunter.user_profile_service');
    $completeness = $profile_service->getProfileCompleteness($test_user);
    $this->assertEquals(0, $completeness);
  }

  /**
   * Test profile edit form.
   */
  public function testProfileEditForm() {
    $this->drupalLogin($this->drupalCreateUser());
    $this->drupalGet('/user/1/edit');

    $this->assertSession()->fieldExists('field_professional_summary[0][value]');
    $this->assertSession()->fieldExists('field_work_authorization');
  }

  /**
   * Test profile validation.
   */
  public function testProfileValidation() {
    $profile_service = \Drupal::service('job_hunter.user_profile_service');
    $test_user = User::create([
      'name' => 'testuser',
      'mail' => 'test@example.com',
    ]);

    // Check validation errors
    $errors = $profile_service->validateProfile($test_user);
    $this->assertNotEmpty($errors);
    $this->assertContains('Resume file is required', $errors);
  }

}
```

---

## Implementation Tasks Checklist

### Phase 1: Service Layer (Day 1)
- [ ] Create `UserProfileService.php` with:
  - [ ] `getProfileCompleteness()` method
  - [ ] `validateProfile()` method
  - [ ] `markProfileComplete()` method
  - [ ] Unit tests
- [ ] Register service in `job_hunter.services.yml`
- [ ] Add service dependency injection to controllers

### Phase 2: Form Integration (Day 2)
- [ ] Implement `hook_form_user_register_form_alter()` to customize registration
- [ ] Implement `hook_form_user_form_alter()` to organize profile edit form
- [ ] Add form validation callbacks
- [ ] Test registration and edit forms work correctly

### Phase 3: User Interface (Day 2-3)
- [ ] Create profile dashboard route
- [ ] Create profile dashboard template
- [ ] Add CSS styling
- [ ] Add profile dashboard menu link
- [ ] Create profile summary template

### Phase 4: Testing & Polish (Day 3-4)
- [ ] Write functional tests
- [ ] Test complete user workflow
- [ ] Verify resume upload works
- [ ] Test profile completeness calculation
- [ ] User acceptance testing
- [ ] Documentation

---

## Success Criteria

After completion, validate:

- ✅ New user can complete registration with job search profile fields
- ✅ User can edit profile at `/user/{uid}/edit`
- ✅ User can upload resume with file validation
- ✅ Profile completeness percentage calculates correctly
- ✅ Profile dashboard shows at `/user/{uid}/profile-dashboard`
- ✅ Profile validation prevents incomplete submissions
- ✅ All 24 profile fields properly accessible
- ✅ Form is mobile-responsive and user-friendly

---

## Dependencies

- Drupal 11+ with User and File modules enabled
- `job_hunter` module installed
- Bootstrap CSS framework for styling (already used in theme)

---

## Related Documentation

- ARCHITECTURE.md - Flow 7: Profile Updates & Maintenance Process
- PROCESS_FLOW.md - User Onboarding workflow
- job_hunter.module - Hook implementations

---

## Next Steps After Completion

Once user profiles are complete, proceed with:
1. Error Queue Dashboard (Flow 4)
2. Company Management Interface (Flow 8)
3. Job Discovery Service with Diffbot (Flow 11)
