<?php

namespace Drupal\job_hunter\Tests\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Service\TailoringRunService;

/**
 * Unit tests for TailoringRunService.
 *
 * Covers the idempotency decision (reuse vs. create-new) and the
 * transactional outbox + run_id queue dispatch, which are the
 * production-safety-critical behaviors of the first event-driven resume
 * tailoring slice.
 *
 * @group job_hunter
 * @coversDefaultClass \Drupal\job_hunter\Service\TailoringRunService
 */
class TailoringRunServiceTest extends UnitTestCase {

  /**
   * Mock database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * Mock schema.
   *
   * @var \Drupal\Core\Database\Schema|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $schema;

  /**
   * Mock queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $queueFactory;

  /**
   * Mock queue.
   *
   * @var \Drupal\Core\Queue\QueueInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $queue;

  /**
   * Mock UUID generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $uuidGenerator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->createMock(Connection::class);
    $this->schema = $this->createMock(Schema::class);
    $this->database->method('schema')->willReturn($this->schema);
    $this->schema->method('tableExists')->willReturn(TRUE);

    $this->queue = $this->createMock(QueueInterface::class);
    $this->queueFactory = $this->createMock(QueueFactory::class);
    $this->queueFactory->method('get')->willReturn($this->queue);

    $this->uuidGenerator = $this->createMock(UuidInterface::class);

    $logger_channel = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($logger_channel);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($config);

    $container = new ContainerBuilder();
    $container->set('logger.factory', $logger_factory);
    $container->set('config.factory', $config_factory);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Builds the service under test.
   */
  protected function buildService(): TailoringRunService {
    return new TailoringRunService($this->database, $this->queueFactory, $this->uuidGenerator, $this->createMock(LoggerChannelFactoryInterface::class));
  }

  /**
   * An active (queued/processing) run is reused, not duplicated.
   *
   * @covers ::createOrReuseRun
   */
  public function testCreateOrReuseRunReusesActiveRun() {
    $existing_run = (object) [
      'id' => 42,
      'run_uuid' => 'existing-uuid-1234',
      'uid' => 7,
      'job_id' => 99,
      'status' => 'queued',
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn($existing_run);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);

    $this->database->method('select')->willReturn($select);

    // No insert/queue activity should happen when reusing.
    $this->database->expects($this->never())->method('insert');
    $this->queueFactory->expects($this->never())->method('get');

    $service = $this->buildService();
    $result = $service->createOrReuseRun(7, 99, FALSE);

    $this->assertTrue($result['reused']);
    $this->assertSame('existing-uuid-1234', $result['run_id']);
    $this->assertSame('queued', $result['status']);
  }

  /**
   * With no active run, a new run + outbox event + queue item are created,
   * and the outbox event is marked dispatched once the queue item succeeds.
   *
   * @covers ::createOrReuseRun
   */
  public function testCreateOrReuseRunCreatesNewRunAndDispatchesOutbox() {
    // No active run found, and version lookup returns no rows (MAX() -> NULL).
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn(FALSE);
    $statement->method('fetchField')->willReturn(NULL);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $this->database->method('select')->willReturn($select);

    $this->uuidGenerator->method('generate')->willReturn('new-uuid-5678');

    $run_insert = $this->createMock(Insert::class);
    $run_insert->method('fields')->willReturnSelf();
    $run_insert->method('execute')->willReturn(101);

    $outbox_insert = $this->createMock(Insert::class);
    $outbox_insert->method('fields')->willReturnSelf();
    $outbox_insert->method('execute')->willReturn(202);

    $this->database->method('insert')->willReturnOnConsecutiveCalls($run_insert, $outbox_insert);

    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(1);
    $this->database->method('update')->willReturn($update);

    // Queue dispatch must be attempted with only the run_id — no profile or
    // job data should ever appear in the queue payload.
    $this->queue->expects($this->once())
      ->method('createItem')
      ->with($this->callback(function ($payload) {
        return is_array($payload) && array_keys($payload) === ['run_id'] && $payload['run_id'] === 'new-uuid-5678';
      }));

    $service = $this->buildService();
    $result = $service->createOrReuseRun(7, 99, FALSE);

    $this->assertFalse($result['reused']);
    $this->assertSame('new-uuid-5678', $result['run_id']);
    $this->assertSame('queued', $result['status']);
  }

  /**
   * A forced request always creates a new run, even if one is active.
   *
   * @covers ::createOrReuseRun
   */
  public function testForceAlwaysCreatesNewRun() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(3);
    $statement->expects($this->never())->method('fetchObject');

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $this->database->method('select')->willReturn($select);

    $this->uuidGenerator->method('generate')->willReturn('forced-uuid-999');

    $insert = $this->createMock(Insert::class);
    $insert->method('fields')->willReturnSelf();
    $insert->method('execute')->willReturn(555);
    $this->database->method('insert')->willReturn($insert);

    $update = $this->createMock(Update::class);
    $update->method('fields')->willReturnSelf();
    $update->method('condition')->willReturnSelf();
    $update->method('execute')->willReturn(1);
    $this->database->method('update')->willReturn($update);

    $this->queue->expects($this->once())->method('createItem');

    $service = $this->buildService();
    $result = $service->createOrReuseRun(7, 99, TRUE);

    $this->assertFalse($result['reused']);
    $this->assertSame('forced-uuid-999', $result['run_id']);
  }

  /**
   * resolveRunInputs() returns NULL when the run cannot be found.
   *
   * @covers ::resolveRunInputs
   */
  public function testResolveRunInputsReturnsNullForMissingRun() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn(FALSE);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $this->database->method('select')->willReturn($select);

    $service = $this->buildService();
    $this->assertNull($service->resolveRunInputs('missing-run-uuid'));
  }

  /**
   * getCanonicalStatus() returns the run's status when a run exists — this
   * is the canonical status source going forward; the legacy queue-scan
   * heuristic is only used by callers when this returns NULL.
   *
   * @covers ::getCanonicalStatus
   * @covers ::getLatestRun
   */
  public function testGetCanonicalStatusReturnsRunStatusWhenRunExists() {
    $run = (object) [
      'id' => 42,
      'run_uuid' => 'run-uuid-abc',
      'uid' => 7,
      'job_id' => 99,
      'status' => 'processing',
      'error_message' => NULL,
    ];

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn($run);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $this->database->method('select')->willReturn($select);

    $service = $this->buildService();
    $status = $service->getCanonicalStatus(7, 99);

    $this->assertSame('run-uuid-abc', $status['run_id']);
    $this->assertSame('processing', $status['status']);
  }

  /**
   * getCanonicalStatus() returns NULL when no run exists at all, signalling
   * callers to fall back to the narrow legacy migration path.
   *
   * @covers ::getCanonicalStatus
   */
  public function testGetCanonicalStatusReturnsNullWhenNoRunExists() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchObject')->willReturn(FALSE);

    $select = $this->createMock(Select::class);
    $select->method('fields')->willReturnSelf();
    $select->method('condition')->willReturnSelf();
    $select->method('orderBy')->willReturnSelf();
    $select->method('range')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $this->database->method('select')->willReturn($select);

    $service = $this->buildService();
    $this->assertNull($service->getCanonicalStatus(7, 99));
  }

  /**
   * adoptLegacyQueueItem() creates a run already marked 'processing' and
   * does not touch the outbox table or dispatch a queue item (the item
   * being adopted is already in-flight in the queue).
   *
   * @covers ::adoptLegacyQueueItem
   */
  public function testAdoptLegacyQueueItemCreatesProcessingRunWithoutDispatch() {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(NULL);

    $select = $this->createMock(Select::class);
    $select->method('condition')->willReturnSelf();
    $select->method('addExpression')->willReturnSelf();
    $select->method('execute')->willReturn($statement);
    $this->database->method('select')->willReturn($select);

    $this->uuidGenerator->method('generate')->willReturn('adopted-uuid-321');

    $insert = $this->createMock(Insert::class);
    $insert->expects($this->once())
      ->method('fields')
      ->with($this->callback(function ($fields) {
        return $fields['status'] === 'processing'
          && $fields['run_uuid'] === 'adopted-uuid-321'
          && $fields['uid'] === 7
          && $fields['job_id'] === 99;
      }))
      ->willReturnSelf();
    $insert->method('execute')->willReturn(1);
    $this->database->expects($this->once())->method('insert')->willReturn($insert);

    // No outbox write and no queue dispatch for adopted legacy items.
    $this->queueFactory->expects($this->never())->method('get');

    $service = $this->buildService();
    $run_uuid = $service->adoptLegacyQueueItem(7, 99);

    $this->assertSame('adopted-uuid-321', $run_uuid);
  }

}
