<?php

namespace Drupal\users_metrics\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Checks if the dblog module is enabled and user has permission.
 */
class DblogEnabledAccessCheck implements AccessInterface {

  /**
   * Constructs a DblogEnabledAccessCheck object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Checks access.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    // Check if dblog module is enabled.
    $dblog_enabled = $this->moduleHandler->moduleExists('dblog');

    // Check if user has permission to access users metrics.
    $has_permission = $account->hasPermission('access users metrics');

    return AccessResult::allowedIf($dblog_enabled && $has_permission)
      ->addCacheableDependency($account)
      ->addCacheTags(['config:core.extension']);
  }

}
