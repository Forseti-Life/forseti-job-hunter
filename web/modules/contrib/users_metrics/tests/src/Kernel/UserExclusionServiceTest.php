<?php

declare(strict_types=1);

namespace Drupal\Tests\users_metrics\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Tests the UserExclusionService.
 *
 * @group users_metrics
 * @coversDefaultClass \Drupal\users_metrics\Service\UserExclusionService
 */
class UserExclusionServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'views',
    'dblog',
    'users_metrics',
  ];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The user exclusion service.
   *
   * @var \Drupal\users_metrics\Service\UserExclusionService
   */
  protected $exclusionService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['users_metrics']);

    $this->exclusionService = $this->container->get('users_metrics.user_exclusion');
  }

  /**
   * Tests getExcludedUids with empty configuration.
   *
   * @covers ::getExcludedUids
   */
  public function testGetExcludedUidsEmpty(): void {
    $result = $this->exclusionService->getExcludedUids();
    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * Tests getExcludedRoles with empty configuration.
   *
   * @covers ::getExcludedRoles
   */
  public function testGetExcludedRolesEmpty(): void {
    $result = $this->exclusionService->getExcludedRoles();
    $this->assertIsArray($result);
    $this->assertEmpty($result);
  }

  /**
   * Tests getExcludedUids with configured UIDs.
   *
   * @covers ::getExcludedUids
   */
  public function testGetExcludedUidsConfigured(): void {
    $config = $this->config('users_metrics.settings');
    $config->set('excluded_uids', [1, 2, 3])->save();

    $result = $this->exclusionService->getExcludedUids();
    $this->assertEquals([1, 2, 3], $result);
  }

  /**
   * Tests getExcludedRoles with configured roles.
   *
   * @covers ::getExcludedRoles
   */
  public function testGetExcludedRolesConfigured(): void {
    $config = $this->config('users_metrics.settings');
    $config->set('excluded_roles', ['administrator', 'editor'])->save();

    $result = $this->exclusionService->getExcludedRoles();
    $this->assertEquals(['administrator', 'editor'], $result);
  }

  /**
   * Tests getUidsByRoles with existing roles.
   *
   * @covers ::getUidsByRoles
   */
  public function testGetUidsByRoles(): void {
    // Create a test role.
    $role = Role::create([
      'id' => 'test_role',
      'label' => 'Test Role',
    ]);
    $role->save();

    // Create users with the test role.
    $user1 = User::create([
      'name' => 'test_user_1',
      'mail' => 'test1@example.com',
      'status' => 1,
    ]);
    $user1->addRole('test_role');
    $user1->save();

    $user2 = User::create([
      'name' => 'test_user_2',
      'mail' => 'test2@example.com',
      'status' => 1,
    ]);
    $user2->addRole('test_role');
    $user2->save();

    // Create a user without the role.
    $user3 = User::create([
      'name' => 'test_user_3',
      'mail' => 'test3@example.com',
      'status' => 1,
    ]);
    $user3->save();

    $result = $this->exclusionService->getUidsByRoles(['test_role']);

    $this->assertCount(2, $result);
    $this->assertContains($user1->id(), $result);
    $this->assertContains($user2->id(), $result);
    $this->assertNotContains($user3->id(), $result);
  }

  /**
   * Tests getUidsByRoles with empty roles array.
   *
   * @covers ::getUidsByRoles
   */
  public function testGetUidsByRolesEmpty(): void {
    $result = $this->exclusionService->getUidsByRoles([]);
    $this->assertEmpty($result);
  }

  /**
   * Tests getAllExcludedUids combines UIDs and role-based UIDs.
   *
   * @covers ::getAllExcludedUids
   */
  public function testGetAllExcludedUids(): void {
    // Create a test role.
    $role = Role::create([
      'id' => 'excluded_role',
      'label' => 'Excluded Role',
    ]);
    $role->save();

    // Create a user with the role.
    $user = User::create([
      'name' => 'role_user',
      'mail' => 'role@example.com',
      'status' => 1,
    ]);
    $user->addRole('excluded_role');
    $user->save();

    // Configure exclusions.
    $config = $this->config('users_metrics.settings');
    $config->set('excluded_uids', [999])->save();
    $config->set('excluded_roles', ['excluded_role'])->save();

    $result = $this->exclusionService->getAllExcludedUids();

    $this->assertContains(999, $result);
    $this->assertContains($user->id(), $result);
  }

}
