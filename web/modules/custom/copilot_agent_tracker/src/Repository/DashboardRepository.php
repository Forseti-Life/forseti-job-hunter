<?php

namespace Drupal\copilot_agent_tracker\Repository;

use Drupal\Core\Database\Connection;

/**
 * Provides database access methods for the agent tracker dashboard.
 */
class DashboardRepository {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Checks whether the agents table exists.
   */
  public function agentsTableExists(): bool {
    return (bool) $this->database->schema()->tableExists('copilot_agent_tracker_agents');
  }

  /**
   * Returns all agents ordered by website/module/role/last_seen DESC.
   *
   * Used by: dashboard(), buildWaitingOnKeithView().
   * The $keyedByAgentId flag switches between fetchAllAssoc and fetchAll.
   */
  public function getAllAgentsOrdered(bool $keyedByAgentId = TRUE): array {
    $query = $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', ['agent_id', 'role', 'website', 'module', 'status', 'current_action', 'last_seen', 'metadata'])
      ->orderBy('website', 'ASC')
      ->orderBy('module', 'ASC')
      ->orderBy('role', 'ASC')
      ->orderBy('last_seen', 'DESC');

    return $keyedByAgentId
      ? ($query->execute()->fetchAllAssoc('agent_id') ?: [])
      : ($query->execute()->fetchAll() ?: []);
  }

  /**
   * Returns all agents ordered by website/module/role/agent_id ASC.
   *
   * Used by: architecture(), releaseControl().
   */
  public function getAllAgentsAlpha(): array {
    return $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', ['agent_id', 'role', 'website', 'module', 'status', 'current_action', 'last_seen'])
      ->orderBy('website', 'ASC')
      ->orderBy('module', 'ASC')
      ->orderBy('role', 'ASC')
      ->orderBy('agent_id', 'ASC')
      ->execute()
      ->fetchAllAssoc('agent_id') ?: [];
  }

  /**
   * Returns all agents with metadata ordered by website/module/role/agent_id ASC.
   *
   * Used by: releaseControl() (needs metadata field).
   */
  public function getAllAgentsAlphaWithMetadata(): array {
    return $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', ['agent_id', 'role', 'website', 'module', 'status', 'current_action', 'last_seen', 'metadata'])
      ->orderBy('website', 'ASC')
      ->orderBy('module', 'ASC')
      ->orderBy('role', 'ASC')
      ->orderBy('agent_id', 'ASC')
      ->execute()
      ->fetchAllAssoc('agent_id') ?: [];
  }

  /**
   * Returns all agents ordered by last_seen DESC (keyed by agent_id).
   *
   * Used by: loadReleaseQaAuditRows().
   */
  public function getAllAgentsByLastSeen(): array {
    return $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', ['agent_id', 'role', 'website', 'module', 'status', 'current_action', 'last_seen', 'metadata'])
      ->orderBy('last_seen', 'DESC')
      ->execute()
      ->fetchAllAssoc('agent_id') ?: [];
  }

  /**
   * Returns the most recent CEO agent row.
   *
   * @param string[] $fields
   *   Fields to select (default: metadata and last_seen).
   */
  public function getCeoAgentRow(array $fields = ['metadata', 'last_seen']): ?array {
    $result = $this->database->select('copilot_agent_tracker_agents', 'a')
      ->fields('a', $fields)
      ->condition('agent_id', 'ceo-copilot%', 'LIKE')
      ->orderBy('last_seen', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Returns a single agent row by agent_id.
   *
   * @param string $agent_id
   *   The agent identifier.
   * @param string[] $fields
   *   Fields to select (empty = all fields).
   */
  public function getAgentById(string $agent_id, array $fields = []): ?array {
    $query = $this->database->select('copilot_agent_tracker_agents', 'a');

    if (empty($fields)) {
      $query->fields('a');
    }
    else {
      $query->fields('a', $fields);
    }

    $result = $query->condition('agent_id', $agent_id)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Returns recent events for a given agent.
   */
  public function getRecentAgentEvents(string $agent_id, int $limit = 50): array {
    return $this->database->select('copilot_agent_tracker_events', 'e')
      ->fields('e', ['created', 'action', 'status', 'summary', 'session_id', 'work_item_id'])
      ->condition('agent_id', $agent_id)
      ->orderBy('created', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll() ?: [];
  }

  /**
   * Returns the IDs of resolved inbox items.
   */
  public function getResolvedInboxItemIds(): array {
    return $this->database->select('copilot_agent_tracker_inbox_resolutions', 'r')
      ->fields('r', ['item_id'])
      ->condition('resolved', 1)
      ->execute()
      ->fetchCol() ?: [];
  }

  /**
   * Returns recent sent (non-dismissed) replies.
   */
  public function getSentReplies(int $limit = 50): array {
    return $this->database->select('copilot_agent_tracker_replies', 'r')
      ->fields('r', ['id', 'to_agent_id', 'in_reply_to', 'message', 'created', 'consumed', 'consumed_at', 'hq_item_id'])
      ->condition('dismissed', 0)
      ->orderBy('created', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll() ?: [];
  }

  /**
   * Inserts a new reply record.
   */
  public function insertReply(array $fields): void {
    $this->database->insert('copilot_agent_tracker_replies')
      ->fields($fields)
      ->execute();
  }

  /**
   * Marks an inbox item as resolved.
   */
  public function resolveInboxItem(string $item_id, int $uid, int $now): void {
    $this->database->merge('copilot_agent_tracker_inbox_resolutions')
      ->key('item_id', $item_id)
      ->fields([
        'resolved' => 1,
        'resolved_at' => $now,
        'resolved_by_uid' => $uid,
      ])
      ->execute();
  }

  /**
   * Marks a sent reply as dismissed.
   */
  public function dismissReply(int $reply_id, int $uid, int $now): void {
    $this->database->update('copilot_agent_tracker_replies')
      ->fields([
        'dismissed' => 1,
        'dismissed_at' => $now,
        'dismissed_by_uid' => $uid,
      ])
      ->condition('id', $reply_id)
      ->execute();
  }

}
