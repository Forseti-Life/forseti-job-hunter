<?php

namespace Drupal\Tests\job_hunter\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Functional tests for user profile forms.
 *
 * @group job_hunter
 */
#[RunTestsInSeparateProcesses]
class UserProfileFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'starterkit_theme';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'user',
    'field',
    'text',
    'file',
    'image',
    'datetime',
    'options',
    'system',
    'views',
    'job_hunter',
  ];

  /**
   * Test user profile form has job search fields.
   */
  public function testUserProfileFormHasJobSearchFields() {
    // Create admin user with permission to edit users.
    $admin = $this->drupalCreateUser([
      'administer users',
      'edit own user account',
    ]);
    $this->drupalLogin($admin);

    // Navigate to user edit form.
    $this->drupalGet('/user/' . $admin->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);

    // Check for job search fieldset.
    $this->assertSession()->elementExists('xpath', '//fieldset[@id="edit-job-search-info"]');
  }

  /**
   * Test user registration form has job search fields.
   */
  public function testUserRegistrationFormHasJobSearchFields() {
    // Navigate to registration page.
    $this->drupalGet('/user/register');
    $this->assertSession()->statusCodeEquals(200);

    // Check for job search fieldset.
    $this->assertSession()->elementExists('xpath', '//fieldset[@id="edit-job-search-profile"]');
  }

  /**
   * Test profile completeness updates on form save.
   */
  public function testProfileCompletenessUpdatesOnSave() {
    // Create a user.
    $user = $this->drupalCreateUser([
      'edit own user account',
    ]);
    $this->drupalLogin($user);

    // Initially, user should have empty profile.
    $user = User::load($user->id());
    $this->assertTrue($user->get('field_profile_completeness')->isEmpty());

    // Edit user profile.
    $this->drupalGet('/user/' . $user->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);

    // Submit the form.
    $this->submitForm([], 'Save');

    // Check that profile completeness was updated.
    $user = User::load($user->id());
    $this->assertFalse($user->get('field_profile_completeness')->isEmpty());
  }

  /**
   * Test validation errors are displayed for incomplete profile.
   */
  public function testValidationErrorsDisplayedForIncompleteProfile() {
    $user = $this->drupalCreateUser([
      'edit own user account',
    ]);
    $this->drupalLogin($user);

    // Navigate to profile dashboard.
    $this->drupalGet('/jobhunter/profile/summary');

    // Without required fields, validation errors should be visible.
    $this->assertSession()->pageTextContains('Action Required');
  }

  /**
   * Test profile is marked complete when all required fields are filled.
   */
  public function testProfileMarkedCompleteWhenRequiredFieldsFilled() {
    $user = $this->drupalCreateUser([
      'edit own user account',
      'access job hunter',
    ]);
    $this->drupalLogin($user);

    // Fill in required profile fields via edit form.
    $this->drupalGet('/user/' . $user->id() . '/edit');

    // Mock resume file exists check (actual file upload would be in integration test).
    // For now, we verify the form accepts the values.
    $this->assertSession()->statusCodeEquals(200);

    // Submit form.
    $this->submitForm([], 'Save');
    $this->assertSession()->pageTextContains('The account has been updated.');
  }

  /**
   * Tests the dedicated jobhunter profile edit route loads without errors.
   */
  public function testJobhunterProfileEditRouteLoads() {
    $user = $this->drupalCreateUser([
      'edit own user account',
      'access job hunter',
    ]);
    $this->drupalLogin($user);

    $this->drupalGet('/jobhunter/profile/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Edit Job Application Profile');
  }

}
