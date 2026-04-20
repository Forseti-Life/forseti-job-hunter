<?php

namespace Drupal\Tests\jobhunter_tester\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Service\UserProfileService;
use Drupal\job_hunter\Service\JobSeekerService;
use Drupal\user\Entity\User;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * Extended unit tests for UserProfileService.
 *
 * Implements test case UPS-006 from TEST_CASES.md
 * (UPS-001 through UPS-005 already implemented in job_hunter module)
 *
 * @group job_hunter
 * @group jobhunter_tester
 */
class UserProfileServiceExtendedTest extends UnitTestCase {

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

    // Mock the JobSeekerService and register it in the container so
    // \Drupal::service('job_hunter.job_seeker_service') resolves correctly
    // during calculateProfileCompleteness().
    $this->jobSeekerService = $this->createMock(JobSeekerService::class);

    $container = new ContainerBuilder();
    $container->set('job_hunter.job_seeker_service', $this->jobSeekerService);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $this->userProfileService = new UserProfileService();
  }

  /**
   * Creates a partial mock of UserProfileService.
   *
   * Stubs out validateForJobApplication() and
   * getMissingFieldRecommendations() which call undefined "FromProfile"
   * methods that are not yet implemented in the main service. This lets us
   * test getProfileStats() without hitting those fatal errors.
   *
   * @param array $validation_return
   *   Return value for validateForJobApplication().
   * @param array $recommendations_return
   *   Return value for getMissingFieldRecommendations().
   *
   * @return \Drupal\job_hunter\Service\UserProfileService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected function createPartialMockService(array $validation_return = [], array $recommendations_return = []) {
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
      ->willReturn($recommendations_return);

    return $service;
  }

  /**
   * Test: Profile Statistics Generation (UPS-006)
   *
   * Returns correct field counts (total, completed, missing).
   */
  public function testGetProfileStatsFieldCounts() {
    $user = $this->createMockUser([
      'field_resume_file' => 'resume.pdf',
      'field_professional_summary' => 'Test summary',
      'field_linkedin_url' => 'https://linkedin.com/in/test',
    ]);

    // Provide a job seeker profile object with matching DB columns.
    $profile = $this->createJobSeekerProfile([
      'resume_node_id' => 123,
      'professional_summary' => 'Test summary',
      'linkedin_url' => 'https://linkedin.com/in/test',
    ]);
    $this->jobSeekerService->method('loadByUserId')->willReturn($profile);

    // Use partial mock to avoid undefined validateForJobApplicationFromProfile().
    $service = $this->createPartialMockService();
    $stats = $service->getProfileStats($user);

    $this->assertIsArray($stats);
    $this->assertArrayHasKey('total_fields', $stats);
    $this->assertArrayHasKey('completed_fields', $stats);
    $this->assertArrayHasKey('completeness', $stats);

    $this->assertEquals(count(UserProfileService::FIELD_WEIGHTS), $stats['total_fields']);
    // completed_fields counts via isFieldCompleted on user entity fields.
    $this->assertEquals(3, $stats['completed_fields']);
  }

  /**
   * Test: Profile Statistics Generation (UPS-006)
   *
   * Returns correct completeness percentage.
   */
  public function testGetProfileStatsCompletenessPercentage() {
    $user = $this->createMockUser([
      'field_resume_file' => 'resume.pdf',
      'field_professional_summary' => 'Test summary',
    ]);

    $profile = $this->createJobSeekerProfile([
      'resume_node_id' => 123,
      'professional_summary' => 'Test summary',
    ]);
    $this->jobSeekerService->method('loadByUserId')->willReturn($profile);

    // Use partial mock to avoid undefined validateForJobApplicationFromProfile().
    $service = $this->createPartialMockService();
    $stats = $service->getProfileStats($user);

    $this->assertArrayHasKey('completeness', $stats);
    $this->assertIsNumeric($stats['completeness']);
    $this->assertGreaterThanOrEqual(0, $stats['completeness']);
    $this->assertLessThanOrEqual(100, $stats['completeness']);

    // resume (20) + professional_summary (10) = 30 weight out of 100 total.
    $this->assertGreaterThan(0, $stats['completeness']);
  }

  /**
   * Test: Profile Statistics Generation (UPS-006)
   *
   * Returns correct completeness status via getCompletenessStatus().
   */
  public function testGetCompletenessStatus() {
    // Test low completeness.
    $status_low = $this->userProfileService->getCompletenessStatus(20);
    $this->assertIsArray($status_low);
    $this->assertArrayHasKey('class', $status_low);
    $this->assertArrayHasKey('level', $status_low);
    $this->assertEquals('incomplete', $status_low['class']);
    $this->assertEquals('low', $status_low['level']);

    // Test medium completeness.
    $status_medium = $this->userProfileService->getCompletenessStatus(50);
    $this->assertEquals('partial', $status_medium['class']);
    $this->assertEquals('medium', $status_medium['level']);

    // Test high completeness.
    $status_high = $this->userProfileService->getCompletenessStatus(80);
    $this->assertEquals('complete', $status_high['class']);
    $this->assertEquals('high', $status_high['level']);
  }

  /**
   * Test: Profile Statistics Generation (UPS-006)
   *
   * Validation results include readiness information.
   */
  public function testGetProfileStatsIncludesValidation() {
    $user = $this->createMockUser([
      'field_professional_summary' => 'Test summary',
    ]);

    $profile = $this->createJobSeekerProfile([
      'professional_summary' => 'Test summary',
    ]);
    $this->jobSeekerService->method('loadByUserId')->willReturn($profile);

    // Use partial mock to avoid undefined validateForJobApplicationFromProfile().
    $service = $this->createPartialMockService([
      'ready' => FALSE,
      'errors' => ['Resume required.'],
    ]);
    $stats = $service->getProfileStats($user);

    $this->assertArrayHasKey('ready_for_applications', $stats);
    $this->assertArrayHasKey('validation', $stats);
    $this->assertIsArray($stats['validation']);
    $this->assertArrayHasKey('ready', $stats['validation']);
    $this->assertArrayHasKey('errors', $stats['validation']);
  }

  /**
   * Test: Profile Statistics Generation (UPS-006)
   *
   * Empty profile returns correct statistics.
   */
  public function testGetProfileStatsEmptyProfile() {
    $user = $this->createMockUser([]);

    // No job seeker profile in the database.
    $this->jobSeekerService->method('loadByUserId')->willReturn(FALSE);

    // Use partial mock to avoid undefined validateForJobApplicationFromProfile().
    $service = $this->createPartialMockService();
    $stats = $service->getProfileStats($user);

    $this->assertEquals(0, $stats['completeness']);
    $this->assertEquals(0, $stats['completed_fields']);
    $this->assertEquals(count(UserProfileService::FIELD_WEIGHTS), $stats['total_fields']);
  }

  /**
   * Test: Profile Statistics Generation (UPS-006)
   *
   * Fully completed profile returns correct statistics.
   */
  public function testGetProfileStatsFullProfile() {
    $user = $this->createMockUser([
      'field_resume_file' => 'resume.pdf',
      'field_work_authorization' => 'us_citizen',
      'field_professional_summary' => 'Comprehensive professional summary',
      'field_skills_summary' => 'PHP, Drupal, JavaScript, React',
      'field_experience_years' => '10',
      'field_education_level' => 'masters',
      'field_remote_preference' => 'hybrid',
      'field_linkedin_url' => 'https://linkedin.com/in/test',
      'field_salary_expectation_min' => '80000',
      'field_available_start_date' => '2025-01-01',
      'field_portfolio_url' => 'https://portfolio.test',
      'field_github_url' => 'https://github.com/test',
      'field_certifications' => 'Certified Developer',
    ]);

    $profile = $this->createJobSeekerProfile([
      'resume_node_id' => 123,
      'work_authorization' => 'us_citizen',
      'professional_summary' => 'Comprehensive professional summary',
      'skills' => 'PHP, Drupal, JavaScript, React',
      'experience_years' => 10,
      'education_level' => 'masters',
      'remote_preference' => 'hybrid',
      'linkedin_url' => 'https://linkedin.com/in/test',
      'salary_expectation' => 80000,
      'availability' => '2025-01-01',
      'portfolio_url' => 'https://portfolio.test',
      'github_url' => 'https://github.com/test',
      'certifications' => 'Certified Developer',
    ]);
    $this->jobSeekerService->method('loadByUserId')->willReturn($profile);

    // Use partial mock to avoid undefined validateForJobApplicationFromProfile().
    $service = $this->createPartialMockService([
      'ready' => TRUE,
      'errors' => [],
    ]);
    $stats = $service->getProfileStats($user);

    $this->assertEquals(100, $stats['completeness']);
    $this->assertEquals($stats['total_fields'], $stats['completed_fields']);
  }

  /**
   * Test: Profile completeness calculation edge cases.
   */
  public function testProfileCompletenessEdgeCases() {
    // Test with only low-weight fields filled.
    $user_optional = $this->createMockUser([
      'field_github_url' => 'https://github.com/test',
      'field_portfolio_url' => 'https://portfolio.test',
    ]);

    $profile_optional = $this->createJobSeekerProfile([
      'github_url' => 'https://github.com/test',
      'portfolio_url' => 'https://portfolio.test',
    ]);
    $this->jobSeekerService->expects($this->atLeastOnce())
      ->method('loadByUserId')
      ->willReturn($profile_optional);

    $completeness_optional = $this->userProfileService->calculateProfileCompleteness($user_optional);

    // github (3) + portfolio (4) = 7 out of 100 total weight.
    $this->assertGreaterThan(0, $completeness_optional);
    $this->assertLessThan(50, $completeness_optional);
  }

  /**
   * Test: Profile completeness with high-weight fields.
   */
  public function testProfileCompletenessHighWeightFields() {
    $user_required = $this->createMockUser([
      'field_resume_file' => 'resume.pdf',
      'field_work_authorization' => 'us_citizen',
    ]);

    $profile_required = $this->createJobSeekerProfile([
      'resume_node_id' => 123,
      'work_authorization' => 'us_citizen',
    ]);
    $this->jobSeekerService->expects($this->atLeastOnce())
      ->method('loadByUserId')
      ->willReturn($profile_required);

    $completeness_required = $this->userProfileService->calculateProfileCompleteness($user_required);

    // resume (20) + work_auth (15) = 35 out of 100 total weight.
    $this->assertGreaterThan(30, $completeness_required);
    $this->assertLessThan(40, $completeness_required);
  }

  /**
   * Test: Field priority in recommendations via getMissingFieldRecommendations.
   *
   * Note: The actual getMissingFieldRecommendations() delegates to an
   * unimplemented getMissingFieldRecommendationsFromProfile() method.
   * This test validates the partial mock returns expected structure and
   * that FIELD_WEIGHTS defines the expected priority order.
   */
  public function testRecommendationsPriority() {
    // Verify FIELD_WEIGHTS is ordered by descending weight (resume first).
    $weights = UserProfileService::FIELD_WEIGHTS;
    $this->assertNotEmpty($weights);

    // Resume should have the highest weight.
    $first_field = array_key_first($weights);
    $this->assertEquals('field_resume_file', $first_field);
    $this->assertEquals(20, $weights['field_resume_file']);

    // Work authorization should be second highest.
    $this->assertEquals(15, $weights['field_work_authorization']);
  }

  /**
   * Test: getCompletenessStatus boundary values.
   */
  public function testCompletenessStatusBoundaries() {
    // At exactly 70 — should be "complete".
    $status_70 = $this->userProfileService->getCompletenessStatus(70);
    $this->assertEquals('complete', $status_70['class']);
    $this->assertEquals('high', $status_70['level']);

    // At exactly 40 — should be "partial".
    $status_40 = $this->userProfileService->getCompletenessStatus(40);
    $this->assertEquals('partial', $status_40['class']);
    $this->assertEquals('medium', $status_40['level']);

    // At exactly 39 — should be "incomplete".
    $status_39 = $this->userProfileService->getCompletenessStatus(39);
    $this->assertEquals('incomplete', $status_39['class']);
    $this->assertEquals('low', $status_39['level']);

    // At 0.
    $status_0 = $this->userProfileService->getCompletenessStatus(0);
    $this->assertEquals('incomplete', $status_0['class']);
    $this->assertEquals('low', $status_0['level']);

    // At 100.
    $status_100 = $this->userProfileService->getCompletenessStatus(100);
    $this->assertEquals('complete', $status_100['class']);
    $this->assertEquals('high', $status_100['level']);
  }

  /**
   * Creates a mock job seeker profile object with specified field values.
   *
   * Simulates the object returned by JobSeekerService::loadByUserId(),
   * which is a stdClass from fetchObject().
   *
   * @param array $field_values
   *   DB column values for the profile.
   *
   * @return object
   *   Simulated job seeker profile object.
   */
  protected function createJobSeekerProfile(array $field_values) {
    $defaults = [
      'id' => 1,
      'uid' => 42,
      'resume_node_id' => NULL,
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

    return (object) array_merge($defaults, $field_values);
  }

  /**
   * Creates a mock user entity with specified field values.
   *
   * @param array $field_values
   *   Array of field_name => value pairs.
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

        // Use __get mock so $field_value->uri and $field_value->value work
        // correctly when the service accesses them.
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
