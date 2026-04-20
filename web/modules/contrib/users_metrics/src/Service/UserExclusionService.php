<?php

declare(strict_types=1);

namespace Drupal\users_metrics\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Service for handling user exclusion logic in metrics.
 */
class UserExclusionService {

  /**
   * Static cache for role-based user IDs.
   *
   * @var array
   */
  protected array $roleUidsCache = [];

  /**
   * Constructs a UserExclusionService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected Connection $database,
  ) {}

  /**
   * Get excluded user IDs from configuration.
   *
   * @return array
   *   Array of user IDs to exclude.
   */
  public function getExcludedUids(): array {
    $config = $this->configFactory->get('users_metrics.settings');
    return $config->get('excluded_uids') ?? [];
  }

  /**
   * Get excluded roles from configuration.
   *
   * @return array
   *   Array of role IDs to exclude.
   */
  public function getExcludedRoles(): array {
    $config = $this->configFactory->get('users_metrics.settings');
    return $config->get('excluded_roles') ?? [];
  }

  /**
   * Get user IDs that have any of the specified roles.
   *
   * @param array $roles
   *   Array of role IDs to search for.
   *
   * @return array
   *   Array of user IDs that have any of the specified roles.
   */
  public function getUidsByRoles(array $roles): array {
    if (empty($roles)) {
      return [];
    }

    $cache_key = implode(':', $roles);

    if (isset($this->roleUidsCache[$cache_key])) {
      return $this->roleUidsCache[$cache_key];
    }

    $query = $this->database->select('user__roles', 'ur')
      ->fields('ur', ['entity_id'])
      ->condition('roles_target_id', $roles, 'IN')
      ->distinct();

    $uids = $query->execute()->fetchCol();

    $this->roleUidsCache[$cache_key] = $uids;

    return $uids;
  }

  /**
   * Get all user IDs to exclude based on configuration.
   *
   * @return array
   *   Array of all user IDs to exclude.
   */
  public function getAllExcludedUids(): array {
    $excluded_uids = $this->getExcludedUids();
    $excluded_roles = $this->getExcludedRoles();

    if (!empty($excluded_roles)) {
      $role_uids = $this->getUidsByRoles($excluded_roles);
      $excluded_uids = array_unique(array_merge($excluded_uids, $role_uids));
    }

    return $excluded_uids;
  }

}
