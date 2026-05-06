<?php

namespace Drupal\Tests\job_hunter\Unit\Repository;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\StatementInterface;
use Drupal\job_hunter\Repository\JobApplicationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JobApplicationRepository.
 *
 * Uses a hand-rolled stub for the DB Connection to avoid needing a full
 * Drupal bootstrap. Methods that rely on schema()->tableExists() or complex
 * query builders are tested through the public interface, not by inspecting
 * the query objects directly.
 *
 * @group job_hunter
 * @coversDefaultClass \Drupal\job_hunter\Repository\JobApplicationRepository
 */
class JobApplicationRepositoryTest extends TestCase {

  /**
   * Create a repository backed by a mocked Connection.
   */
  private function makeRepo(Connection $db): JobApplicationRepository {
    return new JobApplicationRepository($db);
  }

  /**
   * Helper: build a statement mock that returns a fixed fetchField() value.
   */
  private function mockStatementField(mixed $value): StatementInterface {
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchField')->willReturn($value);
    return $stmt;
  }

  /**
   * Helper: build a statement mock that returns a fixed fetchAssoc() value.
   */
  private function mockStatementAssoc(?array $value): StatementInterface {
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchAssoc')->willReturn($value);
    return $stmt;
  }

  /**
   * Helper: build a statement mock that returns a fixed fetchAll() value.
   */
  private function mockStatementAll(array $rows): StatementInterface {
    $stmt = $this->createMock(StatementInterface::class);
    $stmt->method('fetchAll')->willReturn($rows);
    return $stmt;
  }

  // ── countJobRequirements ───────────────────────────────────────────────

  /**
   * @covers ::countJobRequirements
   */
  public function testCountJobRequirementsReturnsInt(): void {
    $stmt = $this->mockStatementField('42');

    $countQuery = $this->createMock(SelectInterface::class);
    $countQuery->method('execute')->willReturn($stmt);

    $select = $this->createMock(SelectInterface::class);
    $select->method('countQuery')->willReturn($countQuery);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    $repo = $this->makeRepo($db);
    $this->assertSame(42, $repo->countJobRequirements());
  }

  /**
   * @covers ::countJobRequirements
   */
  public function testCountJobRequirementsReturnsZeroOnException(): void {
    $db = $this->createMock(Connection::class);
    $db->method('select')->willThrowException(new \Exception('DB error'));

    $repo = $this->makeRepo($db);
    $this->assertSame(0, $repo->countJobRequirements());
  }

  // ── findJobIdByExternalId ──────────────────────────────────────────────

  /**
   * @covers ::findJobIdByExternalId
   */
  public function testFindJobIdByExternalIdReturnsNullForEmptyString(): void {
    $db = $this->createMock(Connection::class);
    $db->expects($this->never())->method('select');

    $repo = $this->makeRepo($db);
    $this->assertNull($repo->findJobIdByExternalId(''));
  }

  /**
   * @covers ::findJobIdByExternalId
   */
  public function testFindJobIdByExternalIdReturnsNullWhenNotFound(): void {
    $stmt = $this->mockStatementField('0');

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    $repo = $this->makeRepo($db);
    $this->assertNull($repo->findJobIdByExternalId('ext-abc'));
  }

  /**
   * @covers ::findJobIdByExternalId
   */
  public function testFindJobIdByExternalIdReturnsIdWhenFound(): void {
    $stmt = $this->mockStatementField('77');

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    $repo = $this->makeRepo($db);
    $this->assertSame(77, $repo->findJobIdByExternalId('ext-abc'));
  }

  // ── hasCompletedProfile ────────────────────────────────────────────────

  /**
   * @covers ::hasCompletedProfile
   */
  public function testHasCompletedProfileReturnsTrueWhenProfileExists(): void {
    $stmt = $this->mockStatementField('{"name":"Alice"}');

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    $repo = $this->makeRepo($db);
    $this->assertTrue($repo->hasCompletedProfile(1));
  }

  /**
   * @covers ::hasCompletedProfile
   */
  public function testHasCompletedProfileReturnsFalseWhenNoProfile(): void {
    $stmt = $this->mockStatementField(NULL);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    $repo = $this->makeRepo($db);
    $this->assertFalse($repo->hasCompletedProfile(1));
  }

  /**
   * @covers ::hasCompletedProfile
   */
  public function testHasCompletedProfileReturnsFalseOnException(): void {
    $db = $this->createMock(Connection::class);
    $db->method('select')->willThrowException(new \Exception('DB error'));

    $repo = $this->makeRepo($db);
    $this->assertFalse($repo->hasCompletedProfile(1));
  }

  // ── findSavedJobMappingId ──────────────────────────────────────────────

  /**
   * @covers ::findSavedJobMappingId
   */
  public function testFindSavedJobMappingIdReturnsIdWhenFound(): void {
    $stmt = $this->mockStatementField('55');

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    $repo = $this->makeRepo($db);
    $this->assertSame(55, $repo->findSavedJobMappingId(1, 10));
  }

  /**
   * @covers ::findSavedJobMappingId
   */
  public function testFindSavedJobMappingIdReturnsZeroWhenNotFound(): void {
    $stmt = $this->mockStatementField(FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    $repo = $this->makeRepo($db);
    $this->assertSame(0, $repo->findSavedJobMappingId(1, 10));
  }

  // ── findLatestApplicationByJobAndUser ──────────────────────────────────

  /**
   * @covers ::findLatestApplicationByJobAndUser
   */
  public function testFindLatestApplicationReturnsNullWhenNotFound(): void {
    $stmt = $this->mockStatementAssoc(NULL);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    $repo = $this->makeRepo($db);
    $this->assertNull($repo->findLatestApplicationByJobAndUser(1, 10));
  }

  /**
   * @covers ::findLatestApplicationByJobAndUser
   */
  public function testFindLatestApplicationReturnsRowWhenFound(): void {
    $row = ['id' => 5, 'submission_status' => 'not_started'];
    $stmt = $this->mockStatementAssoc($row);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    $repo = $this->makeRepo($db);
    $result = $repo->findLatestApplicationByJobAndUser(1, 10);
    $this->assertIsArray($result);
    $this->assertSame(5, $result['id']);
  }

  // ── getLastAttempt ─────────────────────────────────────────────────────

  /**
   * @covers ::getLastAttempt
   */
  public function testGetLastAttemptReturnsNullWhenNoneExist(): void {
    $stmt = $this->mockStatementAssoc(NULL);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    $repo = $this->makeRepo($db);
    $this->assertNull($repo->getLastAttempt(1));
  }

  // ── getApplicationSubmissionSummary ───────────────────────────────────

  /**
   * @covers ::getApplicationSubmissionSummary
   */
  public function testGetApplicationSubmissionSummaryReturnsZerosWhenTableAbsent(): void {
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(FALSE);

    $db = $this->createMock(Connection::class);
    $db->method('schema')->willReturn($schema);

    $repo = $this->makeRepo($db);
    $result = $repo->getApplicationSubmissionSummary(1);
    $this->assertSame(0, $result['total']);
    $this->assertSame(0, $result['submitted']);
  }

  // ── getRecentApplicationSubmissions ───────────────────────────────────

  /**
   * @covers ::getRecentApplicationSubmissions
   */
  public function testGetRecentApplicationSubmissionsReturnsEmptyArrayWhenTableAbsent(): void {
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(FALSE);

    $db = $this->createMock(Connection::class);
    $db->method('schema')->willReturn($schema);

    $repo = $this->makeRepo($db);
    $result = $repo->getRecentApplicationSubmissions(1);
    $this->assertSame([], $result);
  }

  // ── getLatestAttemptsByApplicationIds ─────────────────────────────────

  /**
   * @covers ::getLatestAttemptsByApplicationIds
   */
  public function testGetLatestAttemptsByApplicationIdsReturnsEmptyForEmptyInput(): void {
    $db = $this->createMock(Connection::class);
    $db->expects($this->never())->method('select');
    $db->expects($this->never())->method('schema');

    $repo = $this->makeRepo($db);
    $this->assertSame([], $repo->getLatestAttemptsByApplicationIds([]));
  }

  /**
   * @covers ::getLatestAttemptsByApplicationIds
   */
  public function testGetLatestAttemptsByApplicationIdsReturnsEmptyWhenTableAbsent(): void {
    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(FALSE);

    $db = $this->createMock(Connection::class);
    $db->method('schema')->willReturn($schema);

    $repo = $this->makeRepo($db);
    $this->assertSame([], $repo->getLatestAttemptsByApplicationIds([1, 2]));
  }

  /**
   * @covers ::getLatestAttemptsByApplicationIds
   */
  public function testGetLatestAttemptsByApplicationIdsKeepsOnlyLatestPerApp(): void {
    $rows = [
      ['id' => 3, 'application_id' => 1, 'attempted_at' => '2026-01-03', 'outcome' => 'success', 'error_message' => ''],
      ['id' => 1, 'application_id' => 1, 'attempted_at' => '2026-01-01', 'outcome' => 'fail', 'error_message' => 'err'],
      ['id' => 2, 'application_id' => 2, 'attempted_at' => '2026-01-02', 'outcome' => 'success', 'error_message' => ''],
    ];
    $stmt = $this->mockStatementAll($rows);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $schema = $this->createMock(Schema::class);
    $schema->method('tableExists')->willReturn(TRUE);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);
    $db->method('schema')->willReturn($schema);

    $repo = $this->makeRepo($db);
    $result = $repo->getLatestAttemptsByApplicationIds([1, 2]);

    $this->assertArrayHasKey(1, $result);
    $this->assertArrayHasKey(2, $result);
    // ID 3 is the first row for app 1 (already sorted DESC by the query mock).
    $this->assertSame(3, (int) $result[1]['id']);
    $this->assertSame(2, (int) $result[2]['id']);
  }

  // ── getCompanyName ─────────────────────────────────────────────────────

  /**
   * @covers ::getCompanyName
   */
  public function testGetCompanyNameReturnsNullWhenNotFound(): void {
    $stmt = $this->mockStatementField(FALSE);

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    $repo = $this->makeRepo($db);
    $this->assertNull($repo->getCompanyName(99));
  }

  /**
   * @covers ::getCompanyName
   */
  public function testGetCompanyNameReturnsNameWhenFound(): void {
    $stmt = $this->mockStatementField('Acme Corp');

    $select = $this->createMock(SelectInterface::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('execute')->willReturn($stmt);

    $db = $this->createMock(Connection::class);
    $db->method('select')->willReturn($select);

    $repo = $this->makeRepo($db);
    $this->assertSame('Acme Corp', $repo->getCompanyName(5));
  }

}
