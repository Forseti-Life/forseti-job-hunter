<?php

namespace Drupal\Tests\job_hunter\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Service\UserJobProfileService;
use Drupal\user\UserInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Unit tests for UserJobProfileService.
 *
 * @group job_hunter
 * @coversDefaultClass \Drupal\job_hunter\Service\UserJobProfileService
 */
class UserJobProfileServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\job_hunter\Service\UserJobProfileService
   */
  protected $service;

  /**
   * Mock entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Mock entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);

    $this->service = new UserJobProfileService(
      $this->entityTypeManager,
      $this->entityFieldManager
    );
  }

  /**
   * Tests getProfileCompleteness with all fields populated.
   *
   * @covers ::getProfileCompleteness
   */
  public function testGetProfileCompletenessAllFieldsPopulated() {
    $user = $this->createMockUser([
      'field_resume' => 'resume.pdf',
      'field_work_authorization' => 'US Citizen',
      'field_available_start_date' => '2025-06-01',
      'field_remote_preference' => 'Remote',
      'field_professional_summary' => 'Experienced developer',
      'field_key_skills' => ['PHP', 'Drupal'],
      'field_professional_keywords' => 'web development',
      'field_salary_expectation' => ['min' => 100000, 'max' => 150000],
      'field_target_companies' => [1, 2],
    ]);

    $completeness = $this->service->getProfileCompleteness($user);
    $this->assertEquals(100, $completeness);
  }

  /**
   * Tests getProfileCompleteness with only required fields.
   *
   * @covers ::getProfileCompleteness
   */
  public function testGetProfileCompletenessRequiredOnly() {
    $user = $this->createMockUser([
      'field_resume' => 'resume.pdf',
      'field_work_authorization' => 'US Citizen',
      'field_available_start_date' => '2025-06-01',
      'field_remote_preference' => 'Remote',
    ]);

    $completeness = $this->service->getProfileCompleteness($user);
    $this->assertEquals(70, $completeness);
  }

  /**
   * Tests getProfileCompleteness with no fields populated.
   *
   * @covers ::getProfileCompleteness
   */
  public function testGetProfileCompletenessNoFields() {
    $user = $this->createMockUser([]);

    $completeness = $this->service->getProfileCompleteness($user);
    $this->assertEquals(0, $completeness);
  }

  /**
   * Tests getProfileCompleteness with partial required fields.
   *
   * @covers ::getProfileCompleteness
   */
  public function testGetProfileCompletenessPartialRequired() {
    $user = $this->createMockUser([
      'field_resume' => 'resume.pdf',
      'field_work_authorization' => 'US Citizen',
      // Missing start_date and remote_preference
    ]);

    $completeness = $this->service->getProfileCompleteness($user);
    // 50% of required fields (2/4) = 35%, 0% of optional = 0%, total = 35%
    $this->assertEquals(35, $completeness);
  }

  /**
   * Tests validateProfile with all required fields.
   *
   * @covers ::validateProfile
   */
  public function testValidateProfileValid() {
    $user = $this->createMockUser([
      'field_resume' => 'resume.pdf',
      'field_work_authorization' => 'US Citizen',
      'field_available_start_date' => '2025-06-01',
      'field_remote_preference' => 'Remote',
    ]);

    $errors = $this->service->validateProfile($user);
    $this->assertEmpty($errors);
  }

  /**
   * Tests validateProfile with missing required fields.
   *
   * @covers ::validateProfile
   */
  public function testValidateProfileMissingFields() {
    $user = $this->createMockUser([]);

    $errors = $this->service->validateProfile($user);
    $this->assertNotEmpty($errors);
    $this->assertCount(4, $errors);
  }

  /**
   * Tests isProfileComplete with complete profile.
   *
   * @covers ::isProfileComplete
   */
  public function testIsProfileCompleteTrue() {
    $user = $this->createMockUser([
      'field_resume' => 'resume.pdf',
      'field_work_authorization' => 'US Citizen',
      'field_available_start_date' => '2025-06-01',
      'field_remote_preference' => 'Remote',
    ]);

    $this->assertTrue($this->service->isProfileComplete($user));
  }

  /**
   * Tests isProfileComplete with incomplete profile.
   *
   * @covers ::isProfileComplete
   */
  public function testIsProfileCompleteFalse() {
    $user = $this->createMockUser([
      'field_resume' => 'resume.pdf',
    ]);

    $this->assertFalse($this->service->isProfileComplete($user));
  }

  /**
   * Tests getProfileSummary returns array with all fields.
   *
   * @covers ::getProfileSummary
   */
  public function testGetProfileSummary() {
    $user = $this->createMockUser([
      'field_resume' => 'resume.pdf',
      'field_work_authorization' => 'US Citizen',
      'field_available_start_date' => '2025-06-01',
      'field_remote_preference' => 'Remote',
      'field_professional_summary' => 'Experienced developer',
    ]);

    $summary = $this->service->getProfileSummary($user);
    $this->assertIsArray($summary);
    $this->assertNotEmpty($summary);
  }

  /**
   * Tests getFieldDescriptions returns array with descriptions.
   *
   * @covers ::getFieldDescriptions
   */
  public function testGetFieldDescriptions() {
    $descriptions = $this->service->getFieldDescriptions();
    $this->assertIsArray($descriptions);
    $this->assertNotEmpty($descriptions);
    $this->assertArrayHasKey('field_resume', $descriptions);
    $this->assertArrayHasKey('field_work_authorization', $descriptions);
  }

  /**
   * Creates a mock user with specified field values.
   *
   * @param array $fields
   *   Field values keyed by field name.
   *
   * @return \Drupal\user\UserInterface
   *   Mock user entity.
   */
  protected function createMockUser(array $fields = []): UserInterface {
    $user = $this->createMock(UserInterface::class);

    $field_mapping = [
      'field_resume' => 'has_field_resume',
      'field_work_authorization' => 'has_field_authorization',
      'field_available_start_date' => 'has_field_start_date',
      'field_remote_preference' => 'has_field_remote',
      'field_professional_summary' => 'has_field_summary',
      'field_key_skills' => 'has_field_skills',
      'field_professional_keywords' => 'has_field_keywords',
      'field_salary_expectation' => 'has_field_salary',
      'field_target_companies' => 'has_field_companies',
    ];

    // Set up hasField() method.
    $user->method('hasField')->willReturnCallback(function ($field_name) use ($fields) {
      return isset($fields[$field_name]);
    });

    // Set up get() method.
    $user->method('get')->willReturnCallback(function ($field_name) use ($fields) {
      if (isset($fields[$field_name])) {
        $field = $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class);
        $field->method('isEmpty')->willReturn(FALSE);
        $field->method('first')->willReturnCallback(function () use ($fields, $field_name) {
          $item = $this->createMock(\Drupal\Core\Field\FieldItemInterface::class);
          $item->method('getValue')->willReturn(['value' => $fields[$field_name]]);
          return $item;
        });
        return $field;
      }
      $field = $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn(TRUE);
      return $field;
    });

    return $user;
  }

}
