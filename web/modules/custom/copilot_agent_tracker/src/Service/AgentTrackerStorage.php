<?php

namespace Drupal\copilot_agent_tracker\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Storage helper for agent status and event stream.
 */
final class AgentTrackerStorage {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Record an agent event and update the agent's current status.
   *
   * @param array $payload
   *   Sanitized payload.
   *
   * @return int
   *   Inserted event ID.
   */
  public function recordEvent(array $payload): int {
    $now = (int) $this->time->getRequestTime();

    $agent_id = (string) ($payload['agent_id'] ?? '');
    if ($agent_id === '') {
      throw new \InvalidArgumentException('agent_id is required');
    }

    // Upsert agent status.
    $this->database->merge('copilot_agent_tracker_agents')
      ->key('agent_id', $agent_id)
      ->fields([
        'role' => $payload['role'] ?? NULL,
        'website' => $payload['website'] ?? NULL,
        'module' => $payload['module'] ?? NULL,
        'status' => $payload['status'] ?? 'active',
        'current_action' => $payload['action'] ?? NULL,
        'last_seen' => $now,
        'metadata' => $payload['metadata'] ?? NULL,
      ])
      ->execute();

    // Insert event.
    return (int) $this->database->insert('copilot_agent_tracker_events')
      ->fields([
        'agent_id' => $agent_id,
        'session_id' => $payload['session_id'] ?? NULL,
        'work_item_id' => $payload['work_item_id'] ?? NULL,
        'website' => $payload['website'] ?? NULL,
        'module' => $payload['module'] ?? NULL,
        'action' => $payload['action'] ?? NULL,
        'status' => $payload['status'] ?? NULL,
        'summary' => (string) ($payload['summary'] ?? ''),
        'details' => $payload['details'] ?? NULL,
        'created' => $now,
      ])
      ->execute();
  }

}
