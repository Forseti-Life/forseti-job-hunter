<?php

declare(strict_types=1);

namespace Drupal\forseti_cluster\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin controller for forseti-meshd integration.
 *
 * All data comes from the local forseti-meshd HTTP API (AC-2, AC-6).
 */
class ClusterAdminController extends ControllerBase {

  /**
   * Daemon base URL — configured via settings.php override.
   */
  protected string $daemonUrl;

  public function __construct() {
    $this->daemonUrl = \Drupal::state()->get('forseti_cluster.daemon_url', 'http://127.0.0.1:8765');
  }

  /**
   * GET /admin/forseti/cluster — cluster overview.
   */
  public function overview(): array {
    $identity = $this->daemonGet('/api/v1/identity');
    $peers = $this->daemonGet('/api/v1/peers');
    $mission = $this->daemonGet('/api/v1/mission');

    $active = array_filter($peers ?? [], fn($p) => $p['status'] === 'active');
    $proposed = array_filter($peers ?? [], fn($p) => $p['status'] === 'proposed');

    return [
      '#theme' => 'forseti_cluster_overview',
      '#identity' => $identity,
      '#mission' => $mission,
      '#peer_counts' => [
        'total' => count($peers ?? []),
        'active' => count($active),
        'proposed' => count($proposed),
      ],
      '#daemon_url' => $this->daemonUrl,
      '#attached' => ['library' => ['forseti_cluster/admin']],
    ];
  }

  /**
   * GET /admin/forseti/cluster/peers — peer registry (AC-2, AC-16).
   */
  public function peers(): array {
    $peers = $this->daemonGet('/api/v1/peers') ?? [];

    $rows = [];
    foreach ($peers as $peer) {
      $rows[] = [
        'data' => [
          $peer['display_name'] ?? $peer['installation_id'],
          $peer['base_url'],
          $peer['status'],
          $peer['mission_alignment'],
          $peer['last_seen_at'] ?? '—',
          [
            'data' => [
              '#type' => 'operations',
              '#links' => $this->peerOperations($peer),
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => ['Name', 'URL', 'Status', 'Alignment', 'Last Seen', 'Operations'],
      '#rows' => $rows,
      '#empty' => $this->t('No peers registered yet.'),
    ];
  }

  /**
   * Activate a peer (promote proposed → active). AC-2, AC-16.
   */
  public function activatePeer(Request $request, string $peer_id): array {
    $result = $this->daemonPatch("/api/v1/peers/{$peer_id}/status", [
      'status' => 'active',
    ]);

    if ($result) {
      $this->messenger()->addStatus($this->t('Peer activated successfully.'));
    }
    else {
      $this->messenger()->addError($this->t('Failed to activate peer. Check daemon logs.'));
    }

    return $this->redirect('forseti_cluster.peers')->send() ?? ['#markup' => ''];
  }

  /**
   * GET /admin/forseti/cluster/capabilities — capability registry (AC-8, AC-11).
   */
  public function capabilities(): array {
    $caps = $this->daemonGet('/api/v1/capabilities') ?? [];
    $needs = $this->daemonGet('/api/v1/capabilities/needs') ?? [];

    $cap_rows = array_map(fn($c) => [[$c['capability_type'], $c['description'] ?? '—']], $caps);
    $need_rows = array_map(fn($n) => [[$n['capability_type'], $n['description'] ?? '—']], $needs);

    return [
      'capabilities' => [
        '#type' => 'details',
        '#title' => $this->t('Offered Capabilities'),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Type'), $this->t('Description')],
          '#rows' => array_merge(...($cap_rows ?: [[]])),
          '#empty' => $this->t('None declared.'),
        ],
      ],
      'needs' => [
        '#type' => 'details',
        '#title' => $this->t('Declared Needs'),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Type'), $this->t('Description')],
          '#rows' => array_merge(...($need_rows ?: [[]])),
          '#empty' => $this->t('None declared.'),
        ],
      ],
    ];
  }

  /**
   * GET /admin/forseti/cluster/audit — audit log (AC-10).
   */
  public function auditLog(): array {
    $entries = $this->daemonGet('/api/v1/audit?limit=100') ?? [];

    $rows = array_map(fn($e) => [
      $e['created_at'],
      $e['event_type'],
      $e['actor_installation_id'] ?? '—',
      $e['target_id'] ?? '—',
    ], $entries);

    return [
      '#type' => 'table',
      '#header' => [$this->t('Timestamp'), $this->t('Event'), $this->t('Actor'), $this->t('Target')],
      '#rows' => $rows,
      '#empty' => $this->t('No audit events yet.'),
    ];
  }

  /**
   * GET /admin/forseti/cluster/mission — mission alignment (AC-15, AC-17).
   */
  public function mission(): array {
    $peers = $this->daemonGet('/api/v1/peers') ?? [];
    $local_mission = $this->daemonGet('/api/v1/mission');

    $rows = array_map(fn($p) => [
      $p['display_name'] ?? $p['installation_id'],
      $p['status'],
      $p['mission_alignment'],
    ], $peers);

    return [
      'local' => [
        '#type' => 'details',
        '#title' => $this->t('This Installation Mission'),
        '#open' => TRUE,
        '#markup' => '<p>' . ($local_mission['mission_statement'] ?? '') . '</p><p><strong>Version:</strong> ' . ($local_mission['mission_version'] ?? '') . '</p>',
      ],
      'peers' => [
        '#type' => 'table',
        '#caption' => $this->t('Peer Mission Alignment'),
        '#header' => [$this->t('Peer'), $this->t('Status'), $this->t('Alignment')],
        '#rows' => $rows,
        '#empty' => $this->t('No peers registered.'),
      ],
    ];
  }

  /**
   * GET /admin/forseti/cluster/service-requests — service requests (AC-9, AC-12, AC-14).
   */
  public function serviceRequests(): array {
    $requests = $this->daemonGet('/api/v1/service-requests') ?? [];

    $rows = array_map(fn($r) => [
      $r['id'],
      $r['requester_installation_id'],
      $r['capability_type'],
      $r['status'],
      $r['created_at'],
      $r['expires_at'] ?? '—',
    ], $requests);

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Request ID'),
        $this->t('Requester'),
        $this->t('Capability'),
        $this->t('Status'),
        $this->t('Created'),
        $this->t('Expires'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No service requests.'),
    ];
  }

  // ── Private helpers ──────────────────────────────────────────────────────

  private function peerOperations(array $peer): array {
    $ops = [];
    if ($peer['status'] === 'proposed') {
      $ops['activate'] = [
        'title' => $this->t('Activate'),
        'url' => \Drupal\Core\Url::fromRoute('forseti_cluster.peer_activate_get', ['peer_id' => $peer['id']]),
      ];
    }
    return $ops;
  }

  /**
   * HTTP GET to daemon API.
   */
  private function daemonGet(string $path): mixed {
    try {
      $url = $this->daemonUrl . $path;
      $ctx = stream_context_create(['http' => ['timeout' => 5]]);
      $body = @file_get_contents($url, false, $ctx);
      return $body ? json_decode($body, TRUE) : NULL;
    }
    catch (\Throwable $e) {
      \Drupal::logger('forseti_cluster')->error('Daemon GET failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * HTTP PATCH to daemon API.
   */
  private function daemonPatch(string $path, array $data): mixed {
    try {
      $url = $this->daemonUrl . $path;
      $ctx = stream_context_create([
        'http' => [
          'method' => 'PATCH',
          'header' => "Content-Type: application/json\r\n",
          'content' => json_encode($data),
          'timeout' => 5,
        ],
      ]);
      $body = @file_get_contents($url, false, $ctx);
      return $body ? json_decode($body, TRUE) : NULL;
    }
    catch (\Throwable $e) {
      \Drupal::logger('forseti_cluster')->error('Daemon PATCH failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

}
