<?php

namespace Drupal\copilot_agent_tracker\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Service for logging console mutations to the audit table.
 */
final class AuditLogger {

  public function __construct(
    private Connection $database,
    private AccountProxyInterface $currentUser,
  ) {}

  /**
   * Log a mutation to the audit table.
   *
   * @param string $action
   *   Action type (e.g., 'settings_changed', 'permission_updated').
   * @param string|null $resourceId
   *   Resource identifier (e.g., setting name, agent id).
   * @param mixed $beforeValue
   *   Previous value (will be JSON serialized).
   * @param mixed $afterValue
   *   New value (will be JSON serialized).
   * @param bool $csrfVerified
   *   Whether CSRF token was valid (default: TRUE).
   *
   * @return int
   *   The inserted row ID.
   */
  public function log(
    string $action,
    ?string $resourceId = NULL,
    $beforeValue = NULL,
    $afterValue = NULL,
    bool $csrfVerified = TRUE,
  ): int {
    $beforeJson = $this->truncateValue($beforeValue);
    $afterJson = $this->truncateValue($afterValue);

    $id = $this->database->insert('copilot_agent_tracker_audit')
      ->fields([
        'timestamp' => time(),
        'operator_id' => $this->currentUser->id(),
        'action' => $action,
        'resource_id' => $resourceId,
        'before_value' => $beforeJson,
        'after_value' => $afterJson,
        'csrf_verified' => (int) $csrfVerified,
      ])
      ->execute();

    return (int) $id;
  }

  /**
   * JSON-serialize and truncate a value to 1KB.
   */
  private function truncateValue($value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    $json = is_string($value) ? $value : json_encode($value);
    if (strlen($json) > 1024) {
      $json = substr($json, 0, 1020) . '...';
    }
    return $json;
  }

  /**
   * Query audit entries with optional filtering.
   *
   * @param array<string, mixed> $filters
   *   Filters: operator_id, action, resource_id, from_timestamp, to_timestamp.
   * @param int $limit
   *   Limit results (default: 100).
   * @param int $offset
   *   Pagination offset (default: 0).
   *
   * @return array<int, array<string, mixed>>
   *   Array of audit entries.
   */
  public function query(array $filters = [], int $limit = 100, int $offset = 0): array {
    $query = $this->database->select('copilot_agent_tracker_audit', 'a');
    $query->fields('a');

    if (isset($filters['operator_id'])) {
      $query->condition('a.operator_id', $filters['operator_id']);
    }
    if (isset($filters['action'])) {
      $query->condition('a.action', $filters['action']);
    }
    if (isset($filters['resource_id'])) {
      $query->condition('a.resource_id', '%' . $filters['resource_id'] . '%', 'LIKE');
    }
    if (isset($filters['from_timestamp'])) {
      $query->condition('a.timestamp', $filters['from_timestamp'], '>=');
    }
    if (isset($filters['to_timestamp'])) {
      $query->condition('a.timestamp', $filters['to_timestamp'], '<=');
    }

    $query->orderBy('a.timestamp', 'DESC');
    $query->range($offset, $limit);

    return $query->execute()->fetchAllAssoc('id', \PDO::FETCH_ASSOC) ?: [];
  }

  /**
   * Count audit entries matching filters.
   */
  public function count(array $filters = []): int {
    $query = $this->database->select('copilot_agent_tracker_audit', 'a');
    $query->addExpression('COUNT(a.id)', 'count');

    if (isset($filters['operator_id'])) {
      $query->condition('a.operator_id', $filters['operator_id']);
    }
    if (isset($filters['action'])) {
      $query->condition('a.action', $filters['action']);
    }
    if (isset($filters['resource_id'])) {
      $query->condition('a.resource_id', '%' . $filters['resource_id'] . '%', 'LIKE');
    }
    if (isset($filters['from_timestamp'])) {
      $query->condition('a.timestamp', $filters['from_timestamp'], '>=');
    }
    if (isset($filters['to_timestamp'])) {
      $query->condition('a.timestamp', $filters['to_timestamp'], '<=');
    }

    $result = $query->execute()->fetchField();
    return (int) ($result ?? 0);
  }

  /**
   * Delete audit entries older than a given timestamp (cron cleanup).
   *
   * @param int $beforeTimestamp
   *   Delete entries with timestamp < this value.
   *
   * @return int
   *   Number of deleted rows.
   */
  public function purgeOlderThan(int $beforeTimestamp): int {
    return (int) $this->database->delete('copilot_agent_tracker_audit')
      ->condition('timestamp', $beforeTimestamp, '<')
      ->execute();
  }

}
