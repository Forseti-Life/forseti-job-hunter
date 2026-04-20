<?php

declare(strict_types=1);

namespace Drupal\Tests\users_metrics\Unit;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\users_metrics\Access\DblogEnabledAccessCheck;

/**
 * Tests the DblogEnabledAccessCheck class.
 *
 * @group users_metrics
 * @coversDefaultClass \Drupal\users_metrics\Access\DblogEnabledAccessCheck
 */
class DblogEnabledAccessCheckTest extends UnitTestCase {

  /**
   * The module handler mock.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The account mock.
   *
   * @var \Drupal\Core\Session\AccountInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected AccountInterface $account;

  /**
   * The access checker under test.
   *
   * @var \Drupal\users_metrics\Access\DblogEnabledAccessCheck
   */
  protected DblogEnabledAccessCheck $accessCheck;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $this->account = $this->createMock(AccountInterface::class);
    $this->accessCheck = new DblogEnabledAccessCheck($this->moduleHandler);
  }

  /**
   * Tests access when dblog is enabled and user has permission.
   *
   * @covers ::access
   */
  public function testAccessAllowedWhenDblogEnabledAndHasPermission(): void {
    $this->moduleHandler->expects($this->once())
      ->method('moduleExists')
      ->with('dblog')
      ->willReturn(TRUE);

    $this->account->expects($this->once())
      ->method('hasPermission')
      ->with('access users metrics')
      ->willReturn(TRUE);

    $result = $this->accessCheck->access($this->account);

    $this->assertTrue($result->isAllowed());
  }

  /**
   * Tests access when dblog is disabled.
   *
   * @covers ::access
   */
  public function testAccessDeniedWhenDblogDisabled(): void {
    $this->moduleHandler->expects($this->once())
      ->method('moduleExists')
      ->with('dblog')
      ->willReturn(FALSE);

    $this->account->expects($this->once())
      ->method('hasPermission')
      ->with('access users metrics')
      ->willReturn(TRUE);

    $result = $this->accessCheck->access($this->account);

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Tests access when user lacks permission.
   *
   * @covers ::access
   */
  public function testAccessDeniedWhenNoPermission(): void {
    $this->moduleHandler->expects($this->once())
      ->method('moduleExists')
      ->with('dblog')
      ->willReturn(TRUE);

    $this->account->expects($this->once())
      ->method('hasPermission')
      ->with('access users metrics')
      ->willReturn(FALSE);

    $result = $this->accessCheck->access($this->account);

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Tests access when both conditions fail.
   *
   * @covers ::access
   */
  public function testAccessDeniedWhenBothConditionsFail(): void {
    $this->moduleHandler->expects($this->once())
      ->method('moduleExists')
      ->with('dblog')
      ->willReturn(FALSE);

    $this->account->expects($this->once())
      ->method('hasPermission')
      ->with('access users metrics')
      ->willReturn(FALSE);

    $result = $this->accessCheck->access($this->account);

    $this->assertFalse($result->isAllowed());
  }

  /**
   * Tests that the result has proper cache tags.
   *
   * @covers ::access
   */
  public function testAccessResultHasCacheTags(): void {
    $this->moduleHandler->expects($this->once())
      ->method('moduleExists')
      ->with('dblog')
      ->willReturn(TRUE);

    $this->account->expects($this->once())
      ->method('hasPermission')
      ->with('access users metrics')
      ->willReturn(TRUE);

    $result = $this->accessCheck->access($this->account);

    $this->assertContains('config:core.extension', $result->getCacheTags());
  }

}
