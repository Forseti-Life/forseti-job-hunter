<?php

namespace Drupal\copilot_agent_tracker\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\File\FileSystemInterface;

/**
 * Service for aggregating orchestrator and agent health data.
 */
final class HealthAggregator {

  public function __construct(
    private CacheBackendInterface $cache,
    private FileSystemInterface $fileSystem,
  ) {}

  /**
   * Collect health data (with caching).
   *
   * @return array<string, mixed>
   *   Health data structure.
   */
  public function collect(): array {
    $cached = $this->cache->get('copilot:health-status');
    if ($cached !== FALSE) {
      return (array) $cached->data;
    }

    $data = $this->collectFresh();

    // Cache for 30 seconds.
    $this->cache->set('copilot:health-status', $data, time() + 30, ['copilot:health']);

    return $data;
  }

  /**
   * Collect fresh health data (no caching).
   *
   * @return array<string, mixed>
   */
  private function collectFresh(): array {
    $hqRoot = rtrim((string) (getenv('COPILOT_HQ_ROOT') ?: '/home/ubuntu/forseti.life/copilot-hq'), '/');

    $orchestratorStatus = $this->getOrchestratorStatus($hqRoot);
    $agents = $this->getAgentStatus($hqRoot);
    $dataFreshness = $this->getDataFreshness($hqRoot);

    return [
      'orchestrator_status' => $orchestratorStatus,
      'agents' => $agents,
      'data_freshness' => $dataFreshness,
      'collected_at' => time(),
    ];
  }

  /**
   * Get orchestrator status.
   *
   * @return array<string, mixed>
   */
  private function getOrchestratorStatus(string $hqRoot): array {
    $ticksPath = $hqRoot . '/inbox/responses/langgraph-ticks.jsonl';

    if (!is_readable($ticksPath)) {
      return [
        'status' => 'unknown',
        'last_tick_timestamp' => NULL,
        'tick_frequency_variance' => NULL,
        'parity_ok' => NULL,
        'provider' => NULL,
      ];
    }

    // Read last line (most recent tick).
    $lines = array_filter(
      file($ticksPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
    );
    $lastLine = end($lines);

    if (!$lastLine) {
      return [
        'status' => 'unknown',
        'last_tick_timestamp' => NULL,
        'tick_frequency_variance' => NULL,
        'parity_ok' => NULL,
        'provider' => NULL,
      ];
    }

    $tick = json_decode($lastLine, TRUE);
    if (!is_array($tick)) {
      return [
        'status' => 'unknown',
        'last_tick_timestamp' => NULL,
        'tick_frequency_variance' => NULL,
        'parity_ok' => NULL,
        'provider' => NULL,
      ];
    }

    $ts = (int) ($tick['ts'] ?? time());
    $now = time();
    $elapsed = $now - $ts;

    if ($elapsed < 300) {
      $status = 'ok';
    }
    elseif ($elapsed < 900) {
      $status = 'slow';
    }
    else {
      $status = 'down';
    }

    // Calculate tick frequency variance from last 10 ticks.
    $variance = 0;
    if (count($lines) >= 10) {
      $lastTen = array_slice($lines, -10);
      $times = [];
      foreach ($lastTen as $line) {
        $t = json_decode($line, TRUE);
        if (is_array($t) && isset($t['ts'])) {
          $times[] = (int) $t['ts'];
        }
      }
      if (count($times) >= 2) {
        $deltas = [];
        for ($i = 1; $i < count($times); $i++) {
          $deltas[] = $times[$i] - $times[$i - 1];
        }
        $avg = array_sum($deltas) / count($deltas);
        if ($avg > 0) {
          $variance = round(max(...array_map(
            fn($d) => abs($d - $avg) / $avg * 100,
            $deltas
          )), 1);
        }
      }
    }

    return [
      'status' => $status,
      'last_tick_timestamp' => $ts,
      'tick_frequency_variance' => $variance,
      'parity_ok' => $tick['parity_ok'] ?? NULL,
      'provider' => $tick['provider'] ?? NULL,
    ];
  }

  /**
   * Get per-agent status.
   *
   * @return array<int, array<string, mixed>>
   */
  private function getAgentStatus(string $hqRoot): array {
    $agents = [];
    $sessionsPath = $hqRoot . '/sessions';

    if (!is_dir($sessionsPath)) {
      return [];
    }

    $handles = @glob($sessionsPath . '/*/inbox', GLOB_ONLYDIR) ?: [];

    foreach ($handles as $inboxPath) {
      $pathParts = explode('/', $inboxPath);
      $seatId = $pathParts[count($pathParts) - 2] ?? 'unknown';

      $status = 'idle';
      $lastModified = 0;
      $inboxSize = 0;

      $items = @glob($inboxPath . '/*', GLOB_ONLYDIR) ?: [];
      $inboxSize = count($items);

      // Check for .inwork marker to determine if currently working.
      $inwork = false;
      foreach ($items as $itemPath) {
        if (file_exists($itemPath . '/.inwork')) {
          $inwork = true;
          break;
        }
      }

      if ($inwork) {
        $status = 'working';
      }

      // Get mtime from most recent item.
      foreach ($items as $itemPath) {
        if (is_dir($itemPath)) {
          $mtime = filemtime($itemPath) ?: 0;
          if ($mtime > $lastModified) {
            $lastModified = $mtime;
          }
        }
      }

      $agents[] = [
        'seat_id' => $seatId,
        'status' => $status,
        'inbox_size' => $inboxSize,
        'last_modified' => $lastModified,
      ];
    }

    return $agents;
  }

  /**
   * Get data freshness info.
   *
   * @return array<string, mixed>
   */
  private function getDataFreshness(string $hqRoot): array {
    $now = time();
    $freshness = [
      'ticks_mtime' => 0,
      'ticks_age_seconds' => 0,
      'ticks_fresh' => FALSE,
      'feature_progress_mtime' => 0,
      'feature_progress_age_seconds' => 0,
      'feature_progress_fresh' => FALSE,
      'executor_failures_count' => 0,
    ];

    // Ticks file (< 5 min = fresh).
    $ticksPath = $hqRoot . '/inbox/responses/langgraph-ticks.jsonl';
    if (is_readable($ticksPath)) {
      $mtime = filemtime($ticksPath) ?: 0;
      $age = $now - $mtime;
      $freshness['ticks_mtime'] = $mtime;
      $freshness['ticks_age_seconds'] = $age;
      $freshness['ticks_fresh'] = $age < 300;
    }

    // Feature progress (< 60 min = fresh).
    $featurePath = $hqRoot . '/dashboards/FEATURE_PROGRESS.md';
    if (is_readable($featurePath)) {
      $mtime = filemtime($featurePath) ?: 0;
      $age = $now - $mtime;
      $freshness['feature_progress_mtime'] = $mtime;
      $freshness['feature_progress_age_seconds'] = $age;
      $freshness['feature_progress_fresh'] = $age < 3600;
    }

    // Executor failures directory.
    $failuresDir = $hqRoot . '/tmp/executor-failures';
    if (is_dir($failuresDir)) {
      $files = @glob($failuresDir . '/*.md') ?: [];
      $freshness['executor_failures_count'] = count($files);
    }

    return $freshness;
  }

}
