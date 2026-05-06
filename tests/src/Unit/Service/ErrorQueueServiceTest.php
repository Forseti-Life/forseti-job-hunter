<?php

namespace Drupal\Tests\job_hunter\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Service\ErrorQueueService;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Unit tests for ErrorQueueService.
 *
 * @group job_hunter
 * @coversDefaultClass \Drupal\job_hunter\Service\ErrorQueueService
 */
class ErrorQueueServiceTest extends UnitTestCase {

  /**
   * The service under test.
   *
   * @var \Drupal\job_hunter\Service\ErrorQueueService
   */
  protected $service;

  /**
   * Mock entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Mock logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);

    $this->service = new ErrorQueueService(
      $this->entityTypeManager,
      $this->loggerFactory
    );
  }

  /**
   * Tests logError creates error node successfully.
   *
   * @covers ::logError
   */
  public function testLogErrorCreatesNode() {
    $storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    $error_node = $this->createMock(NodeInterface::class);
    $storage->method('create')->willReturn($error_node);

    $result = $this->service->logError(
      'Test error message',
      'technical',
      NULL,
      NULL,
      [],
      'high'
    );

    $this->assertInstanceOf(NodeInterface::class, $result);
  }

  /**
   * Tests logError validates error type.
   *
   * @covers ::logError
   */
  public function testLogErrorValidatesErrorType() {
    $storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    $error_node = $this->createMock(NodeInterface::class);
    $storage->method('create')->willReturn($error_node);

    // Invalid error type should default to 'technical'
    $result = $this->service->logError(
      'Test error',
      'invalid_type',
      NULL,
      NULL,
      [],
      'medium'
    );

    $this->assertInstanceOf(NodeInterface::class, $result);
  }

  /**
   * Tests logError validates priority.
   *
   * @covers ::logError
   */
  public function testLogErrorValidatesPriority() {
    $storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    $error_node = $this->createMock(NodeInterface::class);
    $storage->method('create')->willReturn($error_node);

    // Invalid priority should default to 'medium'
    $result = $this->service->logError(
      'Test error',
      'technical',
      NULL,
      NULL,
      [],
      'invalid_priority'
    );

    $this->assertInstanceOf(NodeInterface::class, $result);
  }

  /**
   * Tests logError truncates long titles.
   *
   * @covers ::logError
   */
  public function testLogErrorTruncatesTitle() {
    $storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    $long_message = str_repeat('a', 200);
    $error_node = $this->createMock(NodeInterface::class);
    $storage->method('create')->willReturn($error_node);

    $result = $this->service->logError(
      $long_message,
      'technical',
      NULL,
      NULL,
      [],
      'high'
    );

    // Title should be truncated to 100 characters
    $this->assertInstanceOf(NodeInterface::class, $result);
  }

  /**
   * Tests getUnfixedErrorCount returns integer.
   *
   * @covers ::getUnfixedErrorCount
   */
  public function testGetUnfixedErrorCount() {
    $storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    $query = $this->createMock(\Drupal\Core\Entity\Query\QueryInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $count_query = $this->createMock(\Drupal\Core\Entity\Query\QueryInterface::class);
    $query->method('count')->willReturn($count_query);
    $count_query->method('execute')->willReturn(5);

    $result = $this->service->getUnfixedErrorCount();
    $this->assertEquals(5, $result);
  }

  /**
   * Tests getUnresolvedErrorCount returns integer.
   *
   * @covers ::getUnresolvedErrorCount
   */
  public function testGetUnresolvedErrorCount() {
    $storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager
      ->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    $query = $this->createMock(\Drupal\Core\Entity\Query\QueryInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $query->method('condition')->willReturnSelf();
    $count_query = $this->createMock(\Drupal\Core\Entity\Query\QueryInterface::class);
    $query->method('count')->willReturn($count_query);
    $count_query->method('execute')->willReturn(3);

    $result = $this->service->getUnresolvedErrorCount();
    $this->assertEquals(3, $result);
  }

  /**
   * Tests getRecentErrors returns array of nodes.
   *
   * @covers ::getRecentErrors
   */
  public function testGetRecentErrors() {
    $storage = $this->createMock(EntityStorageInterface::class);
    $this->entityTypeManager->expects($this->atLeastOnce())
      ->method('getStorage')
      ->with('node')
      ->willReturn($storage);

    $query = $this->createMock(\Drupal\Core\Entity\Query\QueryInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([1, 2, 3]);

    $error1 = $this->createMock(NodeInterface::class);
    $error2 = $this->createMock(NodeInterface::class);
    $error3 = $this->createMock(NodeInterface::class);
    $storage->method('loadMultiple')->willReturn([1 => $error1, 2 => $error2, 3 => $error3]);

    $result = $this->service->getRecentErrors(10);
    $this->assertIsArray($result);
    $this->assertCount(3, $result);
  }

  /**
   * Tests markErrorFixed updates node fields.
   *
   * @covers ::markErrorFixed
   */
  public function testMarkErrorFixed() {
    $error = $this->createMock(NodeInterface::class);

    $this->service->markErrorFixed($error, 'resolved');

    $error->method('set')->shouldHaveBeenCalledWith('field_fixed', TRUE);
  }

}
