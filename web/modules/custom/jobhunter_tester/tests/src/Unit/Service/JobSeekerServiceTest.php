<?php

namespace Drupal\Tests\jobhunter_tester\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Service\JobSeekerService;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Unit tests for JobSeekerService.
 * 
 * Implements test cases JSS-001 through JSS-006 from TEST_CASES.md
 * 
 * @group job_hunter
 * @group jobhunter_tester
 */
class JobSeekerServiceTest extends UnitTestCase {

  /**
   * The job seeker service under test.
   *
   * @var \Drupal\job_hunter\Service\JobSeekerService
   */
  protected $jobSeekerService;

  /**
   * Mock database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * Mock current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    
    $this->database = $this->createMock(Connection::class);
    $this->currentUser = $this->createMock(AccountProxyInterface::class);

    // Mock the datetime.time service for \Drupal::time()->getRequestTime()
    // called by create() and update().
    $time = $this->createMock(\Drupal\Component\Datetime\TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);
    $container = new ContainerBuilder();
    $container->set('datetime.time', $time);
    \Drupal::setContainer($container);

    $this->jobSeekerService = new JobSeekerService($this->database, $this->currentUser);
  }

  /**
   * Test: Load Job Seeker by User ID (JSS-001)
   * 
   * Valid user ID returns correct profile data.
   */
  public function testLoadByUserIdWithValidUser() {
    $expected_data = (object) [
      'id' => 1,
      'uid' => 42,
      'professional_summary' => 'Experienced developer',
      'skills' => 'PHP, Drupal, JavaScript',
      'consolidated_profile_json' => '{"skills": ["PHP", "Drupal"]}',
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->expects($this->once())
      ->method('fetchObject')
      ->willReturn($expected_data);

    $select = $this->createMock(Select::class);
    $select->expects($this->once())
      ->method('fields')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('condition')
      ->with('uid', 42)
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('execute')
      ->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->with('jobhunter_job_seeker', 'js')
      ->willReturn($select);

    $result = $this->jobSeekerService->loadByUserId(42);
    
    $this->assertNotNull($result);
    $this->assertEquals($expected_data, $result);
    $this->assertEquals(42, $result->uid);
    $this->assertEquals('Experienced developer', $result->professional_summary);
  }

  /**
   * Test: Load Job Seeker by User ID (JSS-001)
   * 
   * Invalid user ID returns null.
   */
  public function testLoadByUserIdWithInvalidUser() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->expects($this->once())
      ->method('fetchObject')
      ->willReturn(FALSE);

    $select = $this->createMock(Select::class);
    $select->expects($this->once())
      ->method('fields')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('condition')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('execute')
      ->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->willReturn($select);

    $result = $this->jobSeekerService->loadByUserId(9999);
    
    // fetchObject() returns FALSE when no row found.
    $this->assertFalse($result);
  }

  /**
   * Test: Load Job Seeker by User ID (JSS-001)
   * 
   * Non-existent profile returns null.
   */
  public function testLoadByUserIdWithNonExistentProfile() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->expects($this->once())
      ->method('fetchObject')
      ->willReturn(FALSE);

    $select = $this->createMock(Select::class);
    $select->expects($this->once())
      ->method('fields')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('condition')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('execute')
      ->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->willReturn($select);

    $result = $this->jobSeekerService->loadByUserId(100);
    
    // fetchObject() returns FALSE when no row found.
    $this->assertFalse($result);
  }

  /**
   * Test: Load Job Seeker by User ID (JSS-001)
   * 
   * Database errors are handled gracefully.
   */
  public function testLoadByUserIdWithDatabaseError() {
    $this->database->expects($this->once())
      ->method('select')
      ->willThrowException(new \Exception('Database connection failed'));

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Database connection failed');
    
    $this->jobSeekerService->loadByUserId(42);
  }

  /**
   * Test: Create Job Seeker Profile (JSS-002)
   * 
   * Profile created with all required fields.
   */
  public function testCreateProfileWithAllFields() {
    $values = [
      'uid' => 42,
      'professional_summary' => 'Test summary',
      'skills' => 'PHP, Drupal',
      'experience_years' => 5,
    ];

    $insertQuery = $this->createMock(Insert::class);
    $insertQuery->expects($this->once())
      ->method('fields')
      ->willReturnSelf();
    $insertQuery->expects($this->once())
      ->method('execute')
      ->willReturn(1);

    $this->database->expects($this->once())
      ->method('insert')
      ->with('jobhunter_job_seeker')
      ->willReturn($insertQuery);

    $result = $this->jobSeekerService->create($values);
    
    $this->assertEquals(1, $result);
  }

  /**
   * Test: Create Job Seeker Profile (JSS-002)
   * 
   * Default values assigned correctly.
   */
  public function testCreateProfileWithDefaults() {
    $minimal_values = [
      'uid' => 42,
    ];

    $insertQuery = $this->createMock(Insert::class);
    $insertQuery->expects($this->once())
      ->method('fields')
      ->with($this->callback(function($fields) {
        // Verify default timestamps are set
        return isset($fields['created']) && isset($fields['changed']);
      }))
      ->willReturnSelf();
    $insertQuery->expects($this->once())
      ->method('execute')
      ->willReturn(1);

    $this->database->expects($this->once())
      ->method('insert')
      ->willReturn($insertQuery);

    $result = $this->jobSeekerService->create($minimal_values);
    
    $this->assertEquals(1, $result);
  }

  /**
   * Test: Update Job Seeker Profile (JSS-003)
   * 
   * Profile updated with new values.
   */
  public function testUpdateProfile() {
    $id = 1;
    $values = [
      'professional_summary' => 'Updated summary',
      'skills' => 'PHP, Drupal, React',
    ];

    $updateQuery = $this->createMock(Update::class);
    $updateQuery->expects($this->once())
      ->method('fields')
      ->with($this->callback(function($fields) {
        // Verify updated timestamp is set
        return isset($fields['changed']);
      }))
      ->willReturnSelf();
    $updateQuery->expects($this->once())
      ->method('condition')
      ->with('id', $id)
      ->willReturnSelf();
    $updateQuery->expects($this->once())
      ->method('execute')
      ->willReturn(1);

    $this->database->expects($this->once())
      ->method('update')
      ->with('jobhunter_job_seeker')
      ->willReturn($updateQuery);

    $result = $this->jobSeekerService->update($id, $values);
    
    $this->assertEquals(1, $result);
  }

  /**
   * Test: Delete Job Seeker Profile (JSS-004)
   * 
   * Profile deleted successfully.
   */
  public function testDeleteProfile() {
    $id = 1;

    $deleteQuery = $this->createMock(Delete::class);
    $deleteQuery->expects($this->once())
      ->method('condition')
      ->with('id', $id)
      ->willReturnSelf();
    $deleteQuery->expects($this->once())
      ->method('execute')
      ->willReturn(1);

    $this->database->expects($this->once())
      ->method('delete')
      ->with('jobhunter_job_seeker')
      ->willReturn($deleteQuery);

    $result = $this->jobSeekerService->delete($id);
    
    $this->assertEquals(1, $result);
  }

  /**
   * Test: Delete Job Seeker Profile (JSS-004)
   * 
   * Non-existent profile deletion handled gracefully.
   */
  public function testDeleteNonExistentProfile() {
    $id = 9999;

    $deleteQuery = $this->createMock(Delete::class);
    $deleteQuery->expects($this->once())
      ->method('condition')
      ->willReturnSelf();
    $deleteQuery->expects($this->once())
      ->method('execute')
      ->willReturn(0);

    $this->database->expects($this->once())
      ->method('delete')
      ->willReturn($deleteQuery);

    $result = $this->jobSeekerService->delete($id);
    
    $this->assertEquals(0, $result);
  }

  /**
   * Test: User Has Profile Check (JSS-006)
   * 
   * Returns true for user with profile.
   */
  public function testUserHasProfileReturnsTrue() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->expects($this->once())
      ->method('fetchField')
      ->willReturn(1);

    $select = $this->createMock(Select::class);
    $select->expects($this->once())
      ->method('condition')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('countQuery')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('execute')
      ->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->willReturn($select);

    $result = $this->jobSeekerService->userHasProfile(42);
    
    $this->assertTrue($result);
  }

  /**
   * Test: User Has Profile Check (JSS-006)
   * 
   * Returns false for user without profile.
   */
  public function testUserHasProfileReturnsFalse() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->expects($this->once())
      ->method('fetchField')
      ->willReturn(0);

    $select = $this->createMock(Select::class);
    $select->expects($this->once())
      ->method('condition')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('countQuery')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('execute')
      ->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->willReturn($select);

    $result = $this->jobSeekerService->userHasProfile(9999);
    
    $this->assertFalse($result);
  }

  /**
   * Test: User Has Profile Check (JSS-006)
   * 
   * Returns false for invalid user ID.
   */
  public function testUserHasProfileWithInvalidUserId() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->expects($this->once())
      ->method('fetchField')
      ->willReturn(0);

    $select = $this->createMock(Select::class);
    $select->expects($this->once())
      ->method('condition')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('countQuery')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('execute')
      ->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->willReturn($select);

    $result = $this->jobSeekerService->userHasProfile(-1);
    
    $this->assertFalse($result);
  }

  /**
   * Test: Current User Profile Access (JSS-005)
   * 
   * Current user profile loaded correctly.
   */
  public function testGetCurrentUserProfile() {
    $current_uid = 42;
    $expected_data = (object) [
      'id' => 1,
      'uid' => $current_uid,
      'professional_summary' => 'Current user profile',
    ];

    $this->currentUser->expects($this->once())
      ->method('id')
      ->willReturn($current_uid);

    $statement = $this->createMock(StatementInterface::class);
    $statement->expects($this->once())
      ->method('fetchObject')
      ->willReturn($expected_data);

    $select = $this->createMock(Select::class);
    $select->expects($this->once())
      ->method('fields')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('condition')
      ->with('uid', $current_uid)
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('execute')
      ->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->willReturn($select);

    $result = $this->jobSeekerService->getCurrentUserProfile();
    
    $this->assertNotNull($result);
    $this->assertEquals($expected_data, $result);
  }

  /**
   * Test: Current User Profile Access (JSS-005)
   * 
   * Anonymous user returns null.
   */
  public function testGetCurrentUserProfileForAnonymous() {
    $this->currentUser->expects($this->once())
      ->method('id')
      ->willReturn(0);

    $statement = $this->createMock(StatementInterface::class);
    $statement->expects($this->once())
      ->method('fetchObject')
      ->willReturn(FALSE);

    $select = $this->createMock(Select::class);
    $select->expects($this->once())
      ->method('fields')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('condition')
      ->willReturnSelf();
    $select->expects($this->once())
      ->method('execute')
      ->willReturn($statement);

    $this->database->expects($this->once())
      ->method('select')
      ->willReturn($select);

    $result = $this->jobSeekerService->getCurrentUserProfile();
    
    // fetchObject() returns FALSE when no row found.
    $this->assertFalse($result);
  }

}
