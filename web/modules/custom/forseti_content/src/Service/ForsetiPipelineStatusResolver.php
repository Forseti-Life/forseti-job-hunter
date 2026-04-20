<?php

namespace Drupal\forseti_content\Service;

/**
 * Resolves feature pipeline counts per project from copilot-hq feature files.
 *
 * Mirrors the DungeonCrawler RoadmapPipelineStatusResolver pattern but reads
 * feature files directly rather than a requirements DB table, since forseti
 * projects track work at the feature level (not requirement level).
 */
class ForsetiPipelineStatusResolver {

  /**
   * Maps feature pipeline status → roadmap display status.
   */
  private const PIPELINE_TO_ROADMAP = [
    'shipped'     => 'implemented',
    'done'        => 'in_progress',
    'in_progress' => 'in_progress',
    'ready'       => 'queued',
    'backlog'     => 'pending',
    'pending'     => 'pending',
    'planned'     => 'pending',
    'pre-triage'  => 'pending',
    'deferred'    => 'pending',
  ];

  /**
   * Maps feature ID prefixes to project IDs when no explicit Project field exists.
   *
   * Checked in order; first match wins.
   */
  private const PREFIX_TO_PROJECT = [
    'forseti-langgraph-'            => 'PROJ-001',
    'forseti-copilot-agent-tracker' => 'PROJ-001',
    'forseti-agent-tracker-'        => 'PROJ-001',
    'forseti-qa-'                   => 'PROJ-002',
    'forseti-jobhunter-'            => 'PROJ-004',
    'forseti-ai-'                   => 'PROJ-005',
    'dc-'                           => 'PROJ-003',
  ];

  protected string $features_path;

  /**
   * Cache for getAllProjectCounts() within a single request.
   *
   * @var array<string, array>|null
   */
  private ?array $cache = NULL;

  public function __construct(string $features_path = '') {
    $this->features_path = $features_path !== ''
      ? $features_path
      : rtrim(getenv('COPILOT_HQ_ROOT') ?: '/home/ubuntu/forseti.life/copilot-hq', '/') . '/features';
  }

  /**
   * Returns pipeline counts for a single project.
   *
   * @param string $project_id  e.g. "PROJ-001"
   * @return array{implemented: int, in_progress: int, queued: int, pending: int, total: int, impl_pct: int, progress_pct: int}
   */
  public function getProjectCounts(string $project_id): array {
    $all = $this->getAllProjectCounts();
    return $all[strtoupper(trim($project_id))] ?? $this->emptyCounts();
  }

  /**
   * Returns pipeline counts for every project, keyed by PROJ-XXX.
   *
   * @return array<string, array{implemented: int, in_progress: int, queued: int, pending: int, total: int, impl_pct: int, progress_pct: int}>
   */
  public function getAllProjectCounts(): array {
    if ($this->cache !== NULL) {
      return $this->cache;
    }

    $this->cache = [];

    if (!is_dir($this->features_path)) {
      return $this->cache;
    }

    $feature_files = glob($this->features_path . '/*/feature.md');
    if (empty($feature_files)) {
      return $this->cache;
    }

    foreach ($feature_files as $feature_file) {
      $feature_id = basename(dirname($feature_file));
      $content = @file_get_contents($feature_file);
      if ($content === FALSE || trim($content) === '') {
        continue;
      }

      $project = $this->resolveProjectId($feature_id, $content);
      if ($project === '') {
        continue;
      }

      $pipeline_status = $this->extractStatus($content);
      if ($pipeline_status === '') {
        continue;
      }

      $roadmap_status = self::PIPELINE_TO_ROADMAP[$pipeline_status] ?? 'pending';

      if (!isset($this->cache[$project])) {
        $this->cache[$project] = $this->emptyCounts();
      }

      $this->cache[$project][$roadmap_status]++;
      $this->cache[$project]['total']++;
    }

    // Compute percentages.
    foreach ($this->cache as &$c) {
      $total = $c['total'];
      $c['impl_pct']     = $total > 0 ? (int) round($c['implemented'] * 100 / $total) : 0;
      $c['progress_pct'] = $total > 0 ? (int) round(($c['implemented'] + $c['in_progress']) * 100 / $total) : 0;
    }
    unset($c);

    return $this->cache;
  }

  /**
   * Resolves a feature to a project ID.
   *
   * Explicit "- Project:" field wins; prefix map is the fallback.
   */
  private function resolveProjectId(string $feature_id, string $content): string {
    if (preg_match('/^- Project:\s*(.+)$/m', $content, $m)) {
      return strtoupper(trim($m[1]));
    }

    foreach (self::PREFIX_TO_PROJECT as $prefix => $project) {
      if ($feature_id === $prefix || str_starts_with($feature_id, $prefix)) {
        return $project;
      }
    }

    return '';
  }

  private function extractStatus(string $content): string {
    if (preg_match('/^- Status:\s*(.+)$/m', $content, $m)) {
      return strtolower(trim($m[1]));
    }
    return '';
  }

  /**
   * Returns an ordered, grouped feature tree for one project.
   *
   * Used by the DC-parity roadmap drill-down view on
   * forseti.life/roadmap/PROJ-*.
   *
   * Return shape:
   * @code
   * [
   *   'group-key' => [
   *     'title'    => 'Human Name',
   *     'sort'     => 1,          // from - Group Sort: field
   *     'counts'   => [...],      // same shape as emptyCounts()
   *     'features' => [
   *       [
   *         'feature_id'     => 'forseti-jobhunter-profile',
   *         'title'          => 'Profile page refactor',
   *         'scope'          => 'First paragraph of Goal/Summary section',
   *         'status'         => 'implemented',  // roadmap status
   *         'pipeline_status'=> 'shipped',       // raw pipeline status
   *         'status_label'   => 'Shipped',
   *         'sort'           => 1,
   *       ],
   *     ],
   *   ],
   * ]
   * @endcode
   *
   * @param string $project_id  e.g. "PROJ-004"
   * @return array<string, array>
   */
  public function getProjectFeatureGroups(string $project_id): array {
    $project_id = strtoupper(trim($project_id));
    $groups = [];

    if (!is_dir($this->features_path)) {
      return $groups;
    }

    $feature_files = glob($this->features_path . '/*/feature.md');
    if (empty($feature_files)) {
      return $groups;
    }

    foreach ($feature_files as $feature_file) {
      $feature_id = basename(dirname($feature_file));
      $content = @file_get_contents($feature_file);
      if ($content === FALSE || trim($content) === '') {
        continue;
      }

      // Filter by project.
      $feature_project = $this->resolveProjectId($feature_id, $content);
      if ($feature_project !== $project_id) {
        continue;
      }

      $pipeline_status = $this->extractStatus($content);
      if ($pipeline_status === '') {
        continue;
      }
      $roadmap_status = self::PIPELINE_TO_ROADMAP[$pipeline_status] ?? 'pending';

      // Group fields (fall back to "ungrouped" if missing).
      $group_key   = $this->extractField($content, 'Group') ?: 'ungrouped';
      $group_title = $this->extractField($content, 'Group Title') ?: 'Other';
      // Group Order = position of the group among all groups in this project.
      // Group Sort  = position of this feature within its group (used for feature ordering).
      $group_order = (int) ($this->extractField($content, 'Group Order') ?: '99');
      $feat_sort   = (int) ($this->extractField($content, 'Group Sort') ?: '99');

      // Feature title: first H2/H3 after the metadata block, or feature_id.
      $feature_title = $this->extractFeatureTitle($content, $feature_id);

      // Scope: first non-empty paragraph of the Goal or Summary section.
      $scope = $this->extractScope($content);

      if (!isset($groups[$group_key])) {
        $groups[$group_key] = [
          'title'    => $group_title,
          'sort'     => $group_order,
          'counts'   => $this->emptyCounts(),
          'features' => [],
        ];
      }

      $status_labels = [
        'implemented' => 'Shipped',
        'in_progress' => 'In Progress',
        'queued'      => 'Queued',
        'pending'     => 'Backlog',
      ];

      $groups[$group_key]['counts'][$roadmap_status]++;
      $groups[$group_key]['counts']['total']++;
      $groups[$group_key]['features'][] = [
        'feature_id'      => $feature_id,
        'title'           => $feature_title,
        'scope'           => $scope,
        'status'          => $roadmap_status,
        'pipeline_status' => $pipeline_status,
        'status_label'    => $status_labels[$roadmap_status] ?? ucfirst($roadmap_status),
        'sort'            => $feat_sort,
      ];
    }

    // Compute percentages per group, sort features, sort groups.
    foreach ($groups as $key => &$g) {
      $t = $g['counts']['total'];
      $g['counts']['impl_pct']     = $t > 0 ? (int) round($g['counts']['implemented'] * 100 / $t) : 0;
      $g['counts']['progress_pct'] = $t > 0 ? (int) round(($g['counts']['implemented'] + $g['counts']['in_progress']) * 100 / $t) : 0;

      // Sort features by status (shipped first) then title.
      usort($g['features'], function (array $a, array $b): int {
        $order = ['implemented' => 0, 'in_progress' => 1, 'queued' => 2, 'pending' => 3];
        $cmp = ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9);
        return $cmp !== 0 ? $cmp : strcmp($a['title'], $b['title']);
      });
    }
    unset($g);

    // Sort groups by their sort field.
    uasort($groups, fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);

    return $groups;
  }

  /**
   * Extracts a simple field value from feature.md front-matter.
   *
   * Looks for lines of the form "- Field Name: value".
   */
  private function extractField(string $content, string $field_name): string {
    if (preg_match('/^- ' . preg_quote($field_name, '/') . ':\s*(.+)$/m', $content, $m)) {
      return trim($m[1]);
    }
    return '';
  }

  /**
   * Extracts the first section heading after the front-matter block as the
   * feature title, falling back to the feature ID with dashes converted to
   * spaces and title-cased.
   */
  private function extractFeatureTitle(string $content, string $feature_id): string {
    // Look for the first ## or ### heading after the "---" separator.
    if (preg_match('/^---\s*\n[\s\S]*?^#{2,3}\s+(.+)$/m', $content, $m)) {
      return trim($m[1]);
    }
    // Or any ## heading.
    if (preg_match('/^#{2,3}\s+(?!Feature Brief)(.+)$/m', $content, $m)) {
      return trim($m[1]);
    }
    // Fall back: prettify the feature ID.
    return ucwords(str_replace('-', ' ', $feature_id));
  }

  /**
   * Extracts the first meaningful paragraph from Goal or Summary section.
   */
  private function extractScope(string $content): string {
    // Try "## Goal", "## Summary", "## Overview" sections.
    foreach (['Goal', 'Summary', 'Overview', 'Scope'] as $heading) {
      if (preg_match('/^## ' . $heading . '\s*\n+([\s\S]+?)(?=\n## |\n---|\z)/m', $content, $m)) {
        $text = trim($m[1]);
        // Remove list prefixes and grab first sentence-ish chunk.
        $text = preg_replace('/^[-*]\s*/m', '', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (strlen($text) > 10) {
          return mb_strimwidth($text, 0, 180, '…');
        }
      }
    }
    return '';
  }

  /**
   * Returns a zeroed-out counts array.
   *
   * @return array{implemented: int, in_progress: int, queued: int, pending: int, total: int, impl_pct: int, progress_pct: int}
   */
  private function emptyCounts(): array {
    return [
      'implemented'  => 0,
      'in_progress'  => 0,
      'queued'       => 0,
      'pending'      => 0,
      'total'        => 0,
      'impl_pct'     => 0,
      'progress_pct' => 0,
    ];
  }

}
