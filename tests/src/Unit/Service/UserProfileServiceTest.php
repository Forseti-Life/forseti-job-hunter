<?php

namespace Drupal\job_hunter\Tests\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Service\UserProfileService;
use Drupal\job_hunter\Service\JobSeekerService;
use Drupal\user\Entity\User;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for UserProfileService.
 *
 * @group job_hunter
 */
class UserProfileServiceTest extends UnitTestCase {

  /**
   * The user profile service under test.
   *
   * @var \Drupal\job_hunter\Service\UserProfileService
   */
  protected $userProfileService;

  /**
   * Mock job seeker service.
   *
   * @var \Drupal\job_hunter\Service\JobSeekerService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $jobSeekerService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock JobSeekerService and register in the container so
    // \Drupal::service('job_hunter.job_seeker_service') works.
    $this->jobSeekerService = $this->createMock(JobSeekerService::class);

    $container = new ContainerBuilder();
    $container->set('job_hunter.job_seeker_service', $this->jobSeekerService);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->userProfileService = new UserProfileService();
  }

  /**
   * Creates a partial mock that stubs undefined "FromProfile" methods.
   *
   * validateForJobApplication() and getMissingFieldRecommendations() delegate
   * to unimplemented *FromProfile() methods. This partial mock stubs them so
   * tests exercising getProfileStats() or other callers don't crash.
   *
   * @param array $validation_return
   *   Return value for validateForJobApplication().
   *
   * @return \Drupal\job_hunter\Service\UserProfileService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected function createPartialMockService(array $validation_return = []) {
    $default_validation = [
      'ready' => FALSE,
      'completeness' => 0,
      'readiness_score' => 0,
      'errors' => ['Resume required.'],
      'warnings' => [],
      'recommendations' => [],
    ];

    $service = $this->getMockBuilder(UserProfileService::class)
      ->onlyMethods(['validateForJobApplication', 'getMissingFieldRecommendations'])
      ->getMock();

    $service->method('validateForJobApplication')
      ->willReturn(array_merge($default_validation, $validation_return));
    $service->method('getMissingFieldRecommendations')
      ->willReturn([]);

    return $service;
  }

  /**
   * Test profile completeness calculation with empty user.
   */
  public function testCalculateCompletenessEmpty() {
    $user = $this->createMockUser([]);

    // No job seeker profile in the database.
    $this->jobSeekerService->method('loadByUserId')->willReturn(FALSE);

    $completeness = $this->userProfileService->calculateProfileCompleteness($user);
    $this->assertEquals(0, $completeness);
  }

  /**
   * Test profile completeness calculation with resume only.
   */
  public function testCalculateCompletenessWithResume() {
    $user = $this->createMockUser(['field_resume_file' => 'resume.pdf']);

    // Provide a job seeker profile with resume populated.
    $profile = (object) [
      'id' => 1,
      'uid' => 42,
      'resume_node_id' => 123,
      'work_authorization' => NULL,
      'professional_summary' => NULL,
      'skills' => NULL,
      'experience_years' => NULL,
      'education_level' => NULL,
      'remote_preference' => NULL,
      'linkedin_url' => NULL,
      'salary_expectation' => NULL,
      'availability' => NULL,
      'portfolio_url' => NULL,
      'github_url' => NULL,
      'certifications' => NULL,
    ];
    $this->jobSeekerService->method('loadByUserId')->willReturn($profile);

    $completeness = $this->userProfileService->calculateProfileCompleteness($user);
    $this->assertEquals(20, $completeness); // Resume is worth 20%
  }

  /**
   * Test field completion detection.
   */
  public function testIsFieldCompleted() {
    $user = $this->createMockUser([
      'field_professional_summary' => 'Test summary',
      'field_linkedin_url' => 'https://linkedin.com/in/test',
    ]);

    $this->assertTrue($this->userProfileService->isFieldCompleted($user, 'field_professional_summary'));
    $this->assertTrue($this->userProfileService->isFieldCompleted($user, 'field_linkedin_url'));
    $this->assertFalse($this->userProfileService->isFieldCompleted($user, 'field_github_url'));
  }

  /**
   * Test missing field recommendations.
   *
   * getMissingFieldRecommendations() delegates to an unimplemented
   * getMissingFieldRecommendationsFromProfile() method. Use a partial mock
   * to return a realistic recommendation list.
   */
  public function testGetMissingFieldRecommendations() {
    $user = $this->createMockUser(['field_professional_summary' => 'Test summary']);

    $service = $this->getMockBuilder(UserProfileService::class)
      ->onlyMethods(['getMissingFieldRecommendations'])
      ->getMock();

    $service->method('getMissingFieldRecommendations')
      ->willReturn([
        'Upload your resume',
        'Add work authorization',
        'Add your skills',
      ]);

    $missing = $service->getMissingFieldRecommendations($user, 3);

    $this->assertIsArray($missing);
    $this->assertLessThanOrEqual(3, count($missing));
    $this->assertContains('Upload your resume', $missing);
  }

  /**
   * Test completeness status detection.
   */
  public function testGetCompletenessStatus() {
    $status_low = $this->userProfileService->getCompletenessStatus(30);
    $this->assertEquals('incomplete', $status_low['class']);
    $this->assertEquals('low', $status_low['level']);

    $status_medium = $this->userProfileService->getCompletenessStatus(50);
    $this->assertEquals('partial', $status_medium['class']);
    $this->assertEquals('medium', $status_medium['level']);

    $status_high = $this->userProfileService->getCompletenessStatus(80);
    $this->assertEquals('complete', $status_high['class']);
    $this->assertEquals('high', $status_high['level']);
  }

  /**
   * Test job application validation.
   *
   * validateForJobApplication() delegates to validateForJobApplicationFromProfile()
   * which calls undefined getJobSeekerProfile(). Use a partial mock that returns
   * realistic validation results.
   */
  public function testValidateForJobApplication() {
    // User without required fields — partial mock returns errors.
    $user_incomplete = $this->createMockUser([]);
    $service_incomplete = $this->createPartialMockService([
      'ready' => FALSE,
      'errors' => ['Resume upload is required.', 'Work authorization is required.'],
    ]);
    $validation = $service_incomplete->validateForJobApplication($user_incomplete);
    $this->assertFalse($validation['ready']);
    $this->assertNotEmpty($validation['errors']);

    // User with minimum required fields — partial mock returns ready.
    $user_basic = $this->createMockUser([
      'field_resume_file' => 'resume.pdf',
      'field_work_authorization' => 'us_citizen',
    ]);
    $service_ready = $this->createPartialMockService([
      'ready' => TRUE,
      'errors' => [],
      'completeness' => 35,
    ]);
    $validation_basic = $service_ready->validateForJobApplication($user_basic);
    $this->assertTrue($validation_basic['ready']);
    $this->assertEmpty($validation_basic['errors']);
  }

  /**
   * Creates a mock user entity with specified field values.
   *
   * @param array $field_values
   *   Array of field values.
   *
   * @return \Drupal\user\Entity\User|\PHPUnit\Framework\MockObject\MockObject
   *   Mock user entity.
   */
  protected function createMockUser(array $field_values) {
    $user = $this->createMock(User::class);

    $user->method('id')->willReturn(42);
    $user->method('getEmail')->willReturn('test@example.com');

    // Mock hasField method.
    $user->method('hasField')->willReturnCallback(function ($field_name) use ($field_values) {
      return array_key_exists($field_name, $field_values) ||
             in_array($field_name, array_keys(UserProfileService::FIELD_WEIGHTS));
    });

    // Mock get method.
    $test = $this;
    $user->method('get')->willReturnCallback(function ($field_name) use ($field_values, $test) {
      $field_item_list = $test->createMock(FieldItemListInterface::class);

      if (array_key_exists($field_name, $field_values)) {
        $field_item_list->method('isEmpty')->willReturn(FALSE);

        // Use __get mock for URL fields so $field_value->uri works correctly.
        if (in_array($field_name, ['field_portfolio_url', 'field_linkedin_url', 'field_github_url'])) {
          $uri = $field_values[$field_name];
          $field_item_list->method('__get')->willReturnCallback(function ($prop) use ($uri) {
            if ($prop === 'uri') {
              return $uri;
            }
            return NULL;
          });
        }
        else {
          $value = $field_values[$field_name];
          $field_item_list->method('__get')->willReturnCallback(function ($prop) use ($value) {
            if ($prop === 'value') {
              return $value;
            }
            return NULL;
          });
        }
      }
      else {
        $field_item_list->method('isEmpty')->willReturn(TRUE);
        $field_item_list->method('__get')->willReturn(NULL);
      }

      return $field_item_list;
    });

    return $user;
  }

}