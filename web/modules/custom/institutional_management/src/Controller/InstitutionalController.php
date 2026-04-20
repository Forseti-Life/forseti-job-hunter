<?php

namespace Drupal\institutional_management\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for Institutional Management pages.
 */
class InstitutionalController extends ControllerBase {

  /**
   * Landing page for institutional management.
   *
   * @return array
   *   A render array.
   */
  public function landing() {
    $build = [];

    // Hero Section
    $build['hero'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bg-primary', 'text-white', 'py-5', 'mb-5', 'rounded']],
      'inner' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['container', 'text-center']],
        'title' => [
          '#markup' => '<h1 class="display-4 fw-bold mb-3">' . $this->t('Family Safety Groups') . '</h1>',
        ],
        'subtitle' => [
          '#markup' => '<p class="lead mb-4">' . $this->t('Keep your family safe together with coordinated safety monitoring and real-time alerts') . '</p>',
        ],
        'cta' => [
          '#markup' => '<div class="mt-4">
            <a href="/group/add/family" class="btn btn-light btn-lg">
              <i class="fas fa-users me-2"></i>' . $this->t('Create Your Family Group') . '
            </a>
          </div>',
        ],
      ],
    ];

    // Features Overview
    $build['features'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container', 'my-5']],
      'title' => [
        '#markup' => '<h2 class="text-center mb-5">' . $this->t('Family Safety Features') . '</h2>',
      ],
      'grid' => [
        '#markup' => '
          <div class="row g-4">
            <div class="col-md-6 col-lg-3">
              <div class="card h-100 text-center p-4">
                <i class="fas fa-bell fa-3x text-primary mb-3"></i>
                <h5>' . $this->t('Real-Time Alerts') . '</h5>
                <p class="text-muted">' . $this->t('Get notified when family members enter dangerous areas') . '</p>
              </div>
            </div>
            <div class="col-md-6 col-lg-3">
              <div class="card h-100 text-center p-4">
                <i class="fas fa-map-marker-alt fa-3x text-primary mb-3"></i>
                <h5>' . $this->t('Location Tracking') . '</h5>
                <p class="text-muted">' . $this->t('See where your family members are and get safety scores for their locations') . '</p>
              </div>
            </div>
            <div class="col-md-6 col-lg-3">
              <div class="card h-100 text-center p-4">
                <i class="fas fa-shield-alt fa-3x text-primary mb-3"></i>
                <h5>' . $this->t('Coordinated Safety') . '</h5>
                <p class="text-muted">' . $this->t('Share safety information and coordinate as a family unit') . '</p>
              </div>
            </div>
            <div class="col-md-6 col-lg-3">
              <div class="card h-100 text-center p-4">
                <i class="fas fa-lock fa-3x text-primary mb-3"></i>
                <h5>' . $this->t('Privacy Protected') . '</h5>
                <p class="text-muted">' . $this->t('Your family data stays private and secure within your group') . '</p>
              </div>
            </div>
          </div>',
      ],
    ];

    return $build;
  }

  /**
   * Dashboard page for institutional management.
   *
   * @return array
   *   A render array.
   */
  public function dashboard() {
    // Check if user has institutional group membership
    // This will be implemented after Group integration
    
    $build = [];
    
    $build['content'] = [
      '#markup' => '<div class="container my-5"><h2>' . $this->t('Institution Dashboard') . '</h2><p>' . $this->t('Dashboard content coming soon...') . '</p></div>',
    ];

    return $build;
  }

  /**
   * Financial health page backed by the accountant dashboard in copilot-hq.
   *
   * @return array
   *   A render array.
   */
  public function financialHealth() {
    $dashboard_relative_path = 'dashboards/finance/current-dashboard-2026-04.md';
    $dashboard_path = $this->resolveHqPath($dashboard_relative_path);
    $dashboard_contents = '';

    if (is_readable($dashboard_path)) {
      $dashboard_contents = file_get_contents($dashboard_path) ?: '';
    }

    $status = $this->extractMetadataValue($dashboard_contents, 'Status');
    $last_updated = $this->extractMetadataValue($dashboard_contents, 'Last updated');
    $owner = $this->extractMetadataValue($dashboard_contents, 'Owner');
    $primary_developer = $this->extractMetadataValue($dashboard_contents, 'Primary developer');
    $reporting_month = $this->extractReportingMonth($dashboard_contents) ?: '2026-04';

    $financial_rows = $this->parseMarkdownTable($this->extractSection($dashboard_contents, 'Current financial view'));
    $coverage_rows = $this->parseMarkdownTable($this->extractSection($dashboard_contents, 'Source coverage'));
    $blockers = $this->parseMarkdownList($this->extractSection($dashboard_contents, 'Active blockers'));
    $artifact_paths = $this->parseArtifactPaths($this->extractSection($dashboard_contents, 'Working artifacts'));
    $financial_index = $this->indexRowsByColumn($financial_rows, 'Area');
    $overall_confidence = $this->deriveOverallConfidence($financial_rows, $coverage_rows);
    $executive_cards = [
      [
        'label' => 'Income MTD',
        'value' => $financial_index['Income MTD']['Current value'] ?? 'unknown',
        'status' => $financial_index['Income MTD']['Confidence'] ?? 'unknown',
        'note' => $financial_index['Income MTD']['Source / note'] ?? '',
      ],
      [
        'label' => 'Expense MTD',
        'value' => $financial_index['Expense MTD']['Current value'] ?? 'unknown',
        'status' => $financial_index['Expense MTD']['Confidence'] ?? 'unknown',
        'note' => $financial_index['Expense MTD']['Source / note'] ?? '',
      ],
      [
        'label' => 'Net MTD',
        'value' => $financial_index['Net MTD']['Current value'] ?? 'unknown',
        'status' => $financial_index['Net MTD']['Confidence'] ?? 'unknown',
        'note' => $financial_index['Net MTD']['Source / note'] ?? '',
      ],
      [
        'label' => 'Cash status',
        'value' => $financial_index['Cash position']['Current value'] ?? 'unknown',
        'status' => $financial_index['Cash position']['Confidence'] ?? 'unknown',
        'note' => $financial_index['Cash position']['Source / note'] ?? '',
      ],
      [
        'label' => 'Overall confidence status',
        'value' => strtoupper($overall_confidence),
        'status' => $overall_confidence,
        'note' => 'Derived from the weakest material finance dependency currently present in the accountant dashboard.',
      ],
    ];
    $source_backed_expense_total = 0.0;
    $has_source_backed_expense = FALSE;
    $blocked_expense_labels = [];

    foreach ($financial_rows as $row) {
      $area = $row['Area'] ?? '';
      $confidence = strtolower(trim((string) ($row['Confidence'] ?? '')));
      if ($area === 'Expense MTD' || $area === 'Net MTD' || !str_contains(strtolower($area), 'expense')) {
        continue;
      }

      if ($confidence === 'source-backed') {
        $amount = $this->parseAmount($row['Current value'] ?? '');
        if ($amount !== NULL) {
          $source_backed_expense_total += $amount;
          $has_source_backed_expense = TRUE;
        }
      }

      if ($confidence === 'blocked') {
        $blocked_expense_labels[] = $area;
      }
    }

    $rollup_rows = [
      [
        'Metric' => 'Income subtotal',
        'Current value' => $financial_index['Income MTD']['Current value'] ?? 'unknown',
        'Status' => $financial_index['Income MTD']['Confidence'] ?? 'unknown',
        'Source / note' => $financial_index['Income MTD']['Source / note'] ?? '',
      ],
      [
        'Metric' => 'Expense subtotal',
        'Current value' => $financial_index['Expense MTD']['Current value'] ?? 'unknown',
        'Status' => $financial_index['Expense MTD']['Confidence'] ?? 'unknown',
        'Source / note' => $financial_index['Expense MTD']['Source / note'] ?? '',
      ],
      [
        'Metric' => 'Net subtotal',
        'Current value' => $financial_index['Net MTD']['Current value'] ?? 'unknown',
        'Status' => $financial_index['Net MTD']['Confidence'] ?? 'unknown',
        'Source / note' => $financial_index['Net MTD']['Source / note'] ?? '',
      ],
      [
        'Metric' => 'Source-backed expense subtotal',
        'Current value' => $has_source_backed_expense ? number_format($source_backed_expense_total, 2, '.', '') : '0.00',
        'Status' => 'source-backed',
        'Source / note' => 'Calculated from source-backed expense lines currently present in the accountant dashboard.',
      ],
      [
        'Metric' => 'Blocked/missing expense subtotal',
        'Current value' => $blocked_expense_labels !== [] ? 'unknown' : '0.00',
        'Status' => $blocked_expense_labels !== [] ? 'blocked' : 'source-backed',
        'Source / note' => $blocked_expense_labels !== [] ? 'Blocked expense inputs remain unresolved for: ' . implode(', ', $blocked_expense_labels) . '.' : 'No blocked expense rows are currently recorded.',
      ],
      [
        'Metric' => 'Blocked/missing income subtotal',
        'Current value' => (($financial_index['Income MTD']['Confidence'] ?? '') === 'blocked') ? 'unknown' : ($financial_index['Income MTD']['Current value'] ?? '0.00'),
        'Status' => (($financial_index['Income MTD']['Confidence'] ?? '') === 'blocked') ? 'blocked' : ($financial_index['Income MTD']['Confidence'] ?? 'unknown'),
        'Source / note' => $financial_index['Income MTD']['Source / note'] ?? '',
      ],
    ];
    $coverage_rows_with_refresh = [];
    foreach ($coverage_rows as $row) {
      $coverage_rows_with_refresh[] = [
        'Signal' => $row['Signal'] ?? '',
        'Source status' => $row['Source status'] ?? '',
        'Last refresh' => $last_updated ?: 'unknown',
        'Blocker / detail' => $row['Detail'] ?? '',
      ];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container', 'my-5']],
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    $status_badge_class = $this->mapStatusBadgeClass($status);

    $build['header'] = [
      '#markup' => '<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">'
        . '<div>'
        . '<h1 class="mb-2">' . $this->t('Financial Health') . '</h1>'
        . '<p class="text-muted mb-0">' . $this->t('Institutional accounting snapshot rendered from the accountant book of record in copilot-hq.') . '</p>'
        . '</div>'
        . '<div class="text-lg-end">'
        . '<div><span class="badge bg-' . $status_badge_class . ' text-uppercase">' . Html::escape($status ?: 'unknown') . '</span></div>'
        . '<div class="small text-muted mt-2">' . $this->t('Reporting month: @month', ['@month' => $reporting_month]) . '</div>'
        . '<div class="small text-muted">' . $this->t('Last updated: @updated', ['@updated' => $last_updated ?: 'unknown']) . '</div>'
        . '</div>'
        . '</div>',
    ];

    $build['meta'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'g-3', 'mb-4']],
      'owner' => $this->buildSimpleMetaCard((string) $this->t('Owner'), $owner ?: 'unknown'),
      'developer' => $this->buildSimpleMetaCard((string) $this->t('Primary developer'), $primary_developer ?: 'unknown'),
      'source' => $this->buildSimpleMetaCard((string) $this->t('Source file'), $dashboard_relative_path),
    ];

    if ($dashboard_contents === '') {
      $build['warning'] = [
        '#markup' => '<div class="alert alert-warning">'
          . $this->t('The financial dashboard source file could not be read from @path. Confirm COPILOT_HQ_ROOT for the web runtime.', ['@path' => $dashboard_path])
          . '</div>',
      ];
      return $build;
    }

    $build['blockers_title'] = [
      '#markup' => '<h2 class="h3 mb-3">' . $this->t('Active blockers') . '</h2>',
    ];
    $build['blockers'] = [
      '#theme' => 'item_list',
      '#items' => $blockers ?: [$this->t('No blockers recorded.')],
      '#attributes' => ['class' => ['mb-5']],
    ];

    $build['cards'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'g-4', 'mb-5']],
    ];

    foreach ($executive_cards as $delta => $card) {
      $label = $card['label'];
      $value = $card['value'];
      $confidence = $card['status'];
      $note = $card['note'];

      $build['cards']['card_' . $delta] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['col-md-6', 'col-xl-4']],
        'content' => [
          '#markup' => '<div class="card h-100 shadow-sm">'
            . '<div class="card-body">'
            . '<div class="d-flex justify-content-between align-items-start gap-3 mb-3">'
            . '<h2 class="h5 mb-0">' . Html::escape($label) . '</h2>'
            . '<span class="badge bg-' . $this->mapStatusBadgeClass($confidence) . ' text-uppercase">' . Html::escape($confidence) . '</span>'
            . '</div>'
            . '<div class="display-6 fw-semibold mb-2">' . Html::escape($value) . '</div>'
            . '<p class="text-muted mb-0">' . Html::escape($note) . '</p>'
            . '</div>'
            . '</div>',
        ],
      ];
    }

    $build['financial_table_title'] = [
      '#markup' => '<h2 class="h3 mb-3">' . $this->t('Current financial view') . '</h2>',
    ];
    $build['financial_table'] = $this->buildTable([
      'Area',
      'Current value',
      'Confidence',
      'Source / note',
    ], $financial_rows);

    $build['rollup_title'] = [
      '#markup' => '<h2 class="h3 mt-5 mb-3">' . $this->t('Current-month financial roll-up') . '</h2>',
    ];
    $build['rollup_table'] = $this->buildTable([
      'Metric',
      'Current value',
      'Status',
      'Source / note',
    ], $rollup_rows);

    $build['coverage_title'] = [
      '#markup' => '<h2 class="h3 mt-5 mb-3">' . $this->t('Source coverage') . '</h2>',
    ];
    $build['coverage_table'] = $this->buildTable([
      'Signal',
      'Source status',
      'Last refresh',
      'Blocker / detail',
    ], $coverage_rows_with_refresh);

    $artifact_items = [];
    foreach ($artifact_paths as $artifact) {
      $artifact_items[] = [
        '#markup' => '<strong>' . Html::escape($artifact['label']) . ':</strong> <code>' . Html::escape($artifact['path']) . '</code>',
      ];
    }

    $build['artifacts_title'] = [
      '#markup' => '<h2 class="h3 mt-5 mb-3">' . $this->t('Book-of-record artifacts') . '</h2>',
    ];
    $build['artifacts_help'] = [
      '#markup' => '<p class="text-muted">' . $this->t('These paths identify the accountant-owned source files that feed this page.') . '</p>',
    ];
    $build['artifacts'] = [
      '#theme' => 'item_list',
      '#items' => $artifact_items,
    ];

    return $build;
  }

  /**
   * Display user's groups page.
   *
   * @return array
   *   A render array.
   */
  public function myGroups() {
    $build = [];
    $current_user = \Drupal::currentUser();
    
    // Get the group membership loader service.
    $group_membership_loader = \Drupal::service('group.membership_loader');
    
    // Load all groups for the current user.
    $user_groups = $group_membership_loader->loadByUser($current_user);
    
    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container', 'my-5']],
      'title' => [
        '#markup' => '<h1 class="mb-4">' . $this->t('My Groups') . '</h1>',
      ],
    ];
    
    if (empty($user_groups)) {
      $build['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['container', 'mb-5']],
        'message' => [
          '#markup' => '<div class="alert alert-info">' . 
            $this->t('You are not a member of any groups yet.') . 
            '</div>',
        ],
        'actions' => [
          '#markup' => '<p><a href="/group/add/family" class="btn btn-primary">' . $this->t('Create a Family Group') . '</a> ' .
            '<a href="/group/add/institution" class="btn btn-secondary">' . $this->t('Create an Institution') . '</a></p>',
        ],
      ];
    }
    else {
      // Build a list of groups.
      $groups_list = [];
      
      foreach ($user_groups as $group_membership) {
        $group = $group_membership->getGroup();
        $group_type = $group->getGroupType();
        
        // Get user's roles in this group.
        $roles = [];
        foreach ($group_membership->getRoles() as $role) {
          $roles[] = $role->label();
        }
        
        $created_date = \Drupal::service('date.formatter')->format($group->getCreatedTime(), 'medium');
        
        $groups_list[] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['card', 'mb-3']],
          'card_body' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['card-body']],
            'content' => [
              '#markup' => '<h5 class="card-title"><a href="' . $group->toUrl()->toString() . '">' . 
                $group->label() . '</a></h5>' .
                '<p class="card-text text-muted mb-2">' . 
                '<span class="badge bg-secondary me-2">' . $group_type->label() . '</span>' .
                (!empty($roles) ? '<span class="badge bg-info">' . implode(', ', $roles) . '</span>' : '') .
                '</p>' .
                '<p class="card-text text-muted small">Created: ' . $created_date . '</p>' .
                '<a href="' . $group->toUrl()->toString() . '" class="btn btn-sm btn-primary">' . 
                $this->t('View Group') . '</a> ' .
                '<a href="/group/' . $group->id() . '/map" class="btn btn-sm btn-success">' . 
                $this->t('View Map') . '</a> ' .
                '<a href="' . $group->toUrl('edit-form')->toString() . '" class="btn btn-sm btn-outline-secondary">' . 
                $this->t('Edit') . '</a>',
            ],
          ],
        ];
      }
      
      $build['groups'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['container', 'mb-5']],
        'list' => $groups_list,
      ];
      
      $build['create_new'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['container', 'mt-4']],
        'actions' => [
          '#markup' => '<h3 class="mb-3">' . $this->t('Create New Group') . '</h3>' .
            '<p><a href="/group/add/family" class="btn btn-primary">' . $this->t('Create a Family Group') . '</a> ' .
            '<a href="/group/add/institution" class="btn btn-secondary">' . $this->t('Create an Institution') . '</a></p>',
        ],
      ];
    }

    return $build;
  }

  /**
   * Build a small metadata card.
   *
   * @param string $label
   *   Card label.
   * @param string $value
   *   Card value.
   *
   * @return array
   *   A render array.
   */
  protected function buildSimpleMetaCard(string $label, string $value): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-4']],
      'content' => [
        '#markup' => '<div class="card h-100 border-0 bg-light">'
          . '<div class="card-body">'
          . '<div class="text-muted small text-uppercase mb-2">' . Html::escape($label) . '</div>'
          . '<div class="fw-semibold">' . Html::escape($value) . '</div>'
          . '</div>'
          . '</div>',
      ],
    ];
  }

  /**
   * Build a table from parsed markdown rows.
   *
   * @param array $headers
   *   Column headers in display order.
   * @param array $rows
   *   Parsed rows.
   *
   * @return array
   *   A render array.
   */
  protected function buildTable(array $headers, array $rows): array {
    $table_rows = [];

    foreach ($rows as $row) {
      $table_row = [];
      foreach ($headers as $header) {
        $table_row[] = $row[$header] ?? '';
      }
      $table_rows[] = $table_row;
    }

    return [
      '#type' => 'table',
      '#header' => $headers,
      '#rows' => $table_rows,
      '#empty' => $this->t('No data available.'),
      '#attributes' => ['class' => ['table', 'table-striped', 'table-bordered']],
    ];
  }

  /**
   * Index parsed markdown rows by a specific column.
   *
   * @param array $rows
   *   Parsed rows.
   * @param string $column
   *   Column name to key by.
   *
   * @return array
   *   Row index.
   */
  protected function indexRowsByColumn(array $rows, string $column): array {
    $index = [];

    foreach ($rows as $row) {
      if (!empty($row[$column])) {
        $index[$row[$column]] = $row;
      }
    }

    return $index;
  }

  /**
   * Parse an amount string into a float.
   *
   * @param string $value
   *   Amount text.
   *
   * @return float|null
   *   Parsed amount or NULL if unavailable.
   */
  protected function parseAmount(string $value): ?float {
    $normalized = preg_replace('/[^0-9.\-]/', '', $value);
    if ($normalized === '' || !is_numeric($normalized)) {
      return NULL;
    }

    return (float) $normalized;
  }

  /**
   * Derive an overall confidence status from financial/source rows.
   *
   * @param array $financial_rows
   *   Financial table rows.
   * @param array $coverage_rows
   *   Source coverage rows.
   *
   * @return string
   *   One of source-backed, provisional, or blocked.
   */
  protected function deriveOverallConfidence(array $financial_rows, array $coverage_rows): string {
    $statuses = [];

    foreach ($financial_rows as $row) {
      if (!empty($row['Confidence'])) {
        $statuses[] = strtolower(trim((string) $row['Confidence']));
      }
    }

    foreach ($coverage_rows as $row) {
      if (!empty($row['Source status'])) {
        $statuses[] = strtolower(trim((string) $row['Source status']));
      }
    }

    if (in_array('blocked', $statuses, TRUE)) {
      return 'blocked';
    }
    if (array_intersect($statuses, ['partial', 'provisional'])) {
      return 'provisional';
    }

    return 'source-backed';
  }

  /**
   * Resolve a path under the copilot-hq root.
   *
   * @param string $relative_path
   *   Path relative to COPILOT_HQ_ROOT.
   *
   * @return string
   *   Absolute path.
   */
  protected function resolveHqPath(string $relative_path): string {
    $root = rtrim((string) (getenv('COPILOT_HQ_ROOT') ?: '/home/ubuntu/forseti.life/copilot-hq'), '/');
    return $root . '/' . ltrim($relative_path, '/');
  }

  /**
   * Extract a metadata value from the markdown front section.
   *
   * @param string $contents
   *   Markdown contents.
   * @param string $label
   *   Metadata label.
   *
   * @return string
   *   Metadata value if found.
   */
  protected function extractMetadataValue(string $contents, string $label): string {
    if ($contents === '') {
      return '';
    }

    $pattern = '/^- ' . preg_quote($label, '/') . ':\s+`?([^`\n]+)`?$/m';
    if (preg_match($pattern, $contents, $matches)) {
      return trim($matches[1]);
    }

    return '';
  }

  /**
   * Extract the reporting month from the title line.
   *
   * @param string $contents
   *   Markdown contents.
   *
   * @return string
   *   Reporting month if found.
   */
  protected function extractReportingMonth(string $contents): string {
    if ($contents !== '' && preg_match('/^#\s+Forseti Finance Dashboard.*([0-9]{4}-[0-9]{2})$/m', $contents, $matches)) {
      return $matches[1];
    }

    return '';
  }

  /**
   * Extract a markdown section by heading.
   *
   * @param string $contents
   *   Markdown contents.
   * @param string $heading
   *   Heading text without the markdown prefix.
   *
   * @return string
   *   Section contents.
   */
  protected function extractSection(string $contents, string $heading): string {
    if ($contents === '') {
      return '';
    }

    $pattern = '/^## ' . preg_quote($heading, '/') . '\R(.*?)(?=^## |\z)/ms';
    if (preg_match($pattern, $contents, $matches)) {
      return trim($matches[1]);
    }

    return '';
  }

  /**
   * Parse a markdown table into keyed row arrays.
   *
   * @param string $section
   *   Markdown table section.
   *
   * @return array
   *   Parsed rows keyed by header.
   */
  protected function parseMarkdownTable(string $section): array {
    $headers = [];
    $rows = [];

    foreach (preg_split('/\R/', $section) as $line) {
      $trimmed = trim($line);
      if ($trimmed === '' || !str_starts_with($trimmed, '|')) {
        continue;
      }

      $cells = array_map('trim', explode('|', trim($trimmed, '| ')));
      if ($headers === []) {
        $headers = $cells;
        continue;
      }

      $is_separator = TRUE;
      foreach ($cells as $cell) {
        if (!preg_match('/^:?-{3,}:?$/', str_replace(' ', '', $cell))) {
          $is_separator = FALSE;
          break;
        }
      }
      if ($is_separator) {
        continue;
      }

      if (count($cells) !== count($headers)) {
        continue;
      }

      $rows[] = array_combine($headers, $cells);
    }

    return $rows;
  }

  /**
   * Parse markdown lists from a section.
   *
   * @param string $section
   *   Markdown list section.
   *
   * @return array
   *   List item strings.
   */
  protected function parseMarkdownList(string $section): array {
    $items = [];

    foreach (preg_split('/\R/', $section) as $line) {
      $trimmed = trim($line);
      if (preg_match('/^(?:-|\d+\.)\s+(.*)$/', $trimmed, $matches)) {
        $items[] = trim($matches[1]);
      }
    }

    return $items;
  }

  /**
   * Parse label/path entries from the working artifacts section.
   *
   * @param string $section
   *   Markdown section.
   *
   * @return array
   *   Artifact records.
   */
  protected function parseArtifactPaths(string $section): array {
    $artifacts = [];

    foreach (preg_split('/\R/', $section) as $line) {
      $trimmed = trim($line);
      if (preg_match('/^- ([^:]+):\s+`([^`]+)`$/', $trimmed, $matches)) {
        $artifacts[] = [
          'label' => trim($matches[1]),
          'path' => trim($matches[2]),
        ];
      }
    }

    return $artifacts;
  }

  /**
   * Map finance status values to Bootstrap badge classes.
   *
   * @param string $status
   *   Status string.
   *
   * @return string
   *   Bootstrap contextual color name.
   */
  protected function mapStatusBadgeClass(string $status): string {
    return match (strtolower(trim($status))) {
      'source-backed', 'live' => 'success',
      'partial', 'provisional' => 'warning',
      'blocked' => 'danger',
      default => 'secondary',
    };
  }

}
