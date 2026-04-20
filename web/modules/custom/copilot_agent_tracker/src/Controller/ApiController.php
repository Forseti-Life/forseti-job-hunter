<?php

namespace Drupal\copilot_agent_tracker\Controller;

use Drupal\copilot_agent_tracker\Service\AgentTrackerStorage;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Internal API endpoint for posting agent telemetry.
 */
final class ApiController extends ControllerBase {

  public function __construct(
    private readonly AgentTrackerStorage $storage,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('copilot_agent_tracker.storage'),
    );
  }

  /**
   * POST /api/copilot-agent-tracker/event
   */
  public function event(Request $request): JsonResponse {
    $token_header = (string) $request->headers->get('X-Copilot-Agent-Tracker-Token', '');
    $token_state = (string) $this->state()->get('copilot_agent_tracker.telemetry_token', '');
    if ($token_state === '' || $token_header === '' || !hash_equals($token_state, $token_header)) {
      throw new AccessDeniedHttpException('Invalid telemetry token.');
    }

    $content = (string) $request->getContent();
    if ($content === '') {
      throw new BadRequestHttpException('Missing JSON payload.');
    }

    if (strlen($content) > 65536) {
      return new JsonResponse(['error' => 'Payload too large'], 413);
    }

    $payload = json_decode($content, TRUE);
    if (!is_array($payload)) {
      throw new BadRequestHttpException('Invalid JSON payload.');
    }

    // Minimal required fields; do not accept raw chat logs.
    $summary = (string) ($payload['summary'] ?? '');
    if ($summary === '') {
      throw new BadRequestHttpException('summary is required.');
    }

    // Validate agent_id: required, max 64 chars, alphanumeric/dash/underscore.
    $agent_id = (string) ($payload['agent_id'] ?? '');
    if ($agent_id === '') {
      throw new BadRequestHttpException('agent_id is required.');
    }
    if (!preg_match('/^[a-zA-Z0-9\-_]{1,64}$/', $agent_id)) {
      throw new BadRequestHttpException('agent_id must be 1-64 characters: letters, digits, dashes, or underscores only.');
    }

    // Validate status enum if provided.
    $allowed_statuses = ['active', 'idle', 'paused', 'done', 'in_progress', 'blocked', 'needs-info', 'error'];
    if (isset($payload['status']) && !in_array((string) $payload['status'], $allowed_statuses, TRUE)) {
      throw new BadRequestHttpException('Invalid status value.');
    }

    // Validate action (maps to current_action) max 512 chars.
    if (isset($payload['action']) && strlen((string) $payload['action']) > 512) {
      throw new BadRequestHttpException('action exceeds maximum length of 512 characters.');
    }

    // Validate metadata must be a JSON object, not an array or scalar.
    if (isset($payload['metadata'])) {
      if (!is_array($payload['metadata']) || array_is_list($payload['metadata'])) {
        throw new BadRequestHttpException('metadata must be a JSON object.');
      }
    }

    $sanitized = [
      'agent_id' => $agent_id,
      'session_id' => $payload['session_id'] ?? NULL,
      'work_item_id' => $payload['work_item_id'] ?? NULL,
      'role' => $payload['role'] ?? NULL,
      'website' => $payload['website'] ?? NULL,
      'module' => $payload['module'] ?? NULL,
      'action' => isset($payload['action']) ? (string) $payload['action'] : NULL,
      'status' => isset($payload['status']) ? (string) $payload['status'] : NULL,
      'summary' => $summary,
      'details' => $payload['details'] ?? NULL,
      'metadata' => isset($payload['metadata']) ? json_encode($payload['metadata']) : NULL,
    ];

    $event_id = $this->storage->recordEvent($sanitized);
    return new JsonResponse([
      'ok' => TRUE,
      'event_id' => $event_id,
    ]);
  }

}
