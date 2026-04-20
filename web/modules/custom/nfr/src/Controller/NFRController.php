<?php

namespace Drupal\nfr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for NFR module.
 */
class NFRController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Constructs a NFRController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Dashboard page callback.
   *
   * @return array
   *   Render array.
   */
  public function dashboard(): array {
    $build = [];
    
    $build['welcome'] = [
      '#markup' => '<div class="nfr-dashboard">
        <h2>' . $this->t('National Firefighter Registry Dashboard') . '</h2>
        <p>' . $this->t('Welcome to the National Firefighter Registry (NFR) system.') . '</p>
      </div>',
    ];

    // Get statistics from nfr_user_profile table
    $total_firefighters = $this->database->select('nfr_user_profile', 'n')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Note: nfr_user_profile doesn't have a 'status' column, so count all profiles
    $active_firefighters = $total_firefighters;

    $build['stats'] = [
      '#markup' => '<div class="nfr-stats">
        <div class="stat-item">
          <span class="stat-label">' . $this->t('Total Firefighters') . ':</span>
          <span class="stat-value">' . $total_firefighters . '</span>
        </div>
        <div class="stat-item">
          <span class="stat-label">' . $this->t('Active Firefighters') . ':</span>
          <span class="stat-value">' . $active_firefighters . '</span>
        </div>
      </div>',
    ];

    $build['#attached']['library'][] = 'nfr/nfr-dashboard';

    return $build;
  }

  /**
   * Firefighter list page callback.
   *
   * @return array
   *   Render array.
   */
  public function firefighterList(): array {
    $build = [];

    $header = [
      ['data' => $this->t('ID'), 'field' => 'id'],
      ['data' => $this->t('Name'), 'field' => 'last_name'],
      ['data' => $this->t('Badge Number'), 'field' => 'badge_number'],
      ['data' => $this->t('Department'), 'field' => 'department'],
      ['data' => $this->t('State'), 'field' => 'state'],
      ['data' => $this->t('Status'), 'field' => 'status'],
      ['data' => $this->t('Operations')],
    ];

    $query = $this->database->select('nfr_firefighters', 'n')
      ->extend('\Drupal\Core\Database\Query\PagerSelectExtender')
      ->extend('\Drupal\Core\Database\Query\TableSortExtender');
    
    $query->fields('n');
    $query->limit(50);
    $query->orderByHeader($header);

    $results = $query->execute();

    $rows = [];
    foreach ($results as $row) {
      $rows[] = [
        $row->id,
        $row->first_name . ' ' . $row->last_name,
        $row->badge_number ?? '-',
        $row->department ?? '-',
        $row->state ?? '-',
        $row->status,
        $this->t('View | Edit'),
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No firefighters found.'),
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  /**
   * Data dashboard page callback.
   *
   * @return array
   *   Render array.
   */
  public function dataDashboard(): array {
    $build = [];

    $build['title'] = [
      '#markup' => '<h2>' . $this->t('NFR Data Dashboard') . '</h2>',
    ];

    // Cancer statistics
    $cancer_stats = $this->database->select('nfr_cancer_data', 'c')
      ->fields('c', ['cancer_type'])
      ->groupBy('cancer_type')
      ->execute();

    $cancer_counts = [];
    foreach ($cancer_stats as $stat) {
      $count = $this->database->select('nfr_cancer_data', 'c')
        ->condition('cancer_type', $stat->cancer_type)
        ->countQuery()
        ->execute()
        ->fetchField();
      $cancer_counts[$stat->cancer_type] = $count;
    }

    $build['cancer_stats'] = [
      '#type' => 'details',
      '#title' => $this->t('Cancer Incidence by Type'),
      '#open' => TRUE,
    ];

    $cancer_rows = [];
    foreach ($cancer_counts as $type => $count) {
      $cancer_rows[] = [$type, $count];
    }

    $build['cancer_stats']['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Cancer Type'), $this->t('Count')],
      '#rows' => $cancer_rows,
      '#empty' => $this->t('No cancer data available.'),
    ];

    // State distribution
    $state_stats = $this->database->select('nfr_firefighters', 'n')
      ->fields('n', ['state'])
      ->groupBy('state')
      ->execute();

    $state_counts = [];
    foreach ($state_stats as $stat) {
      if (!empty($stat->state)) {
        $count = $this->database->select('nfr_firefighters', 'n')
          ->condition('state', $stat->state)
          ->countQuery()
          ->execute()
          ->fetchField();
        $state_counts[$stat->state] = $count;
      }
    }

    arsort($state_counts);

    $build['state_stats'] = [
      '#type' => 'details',
      '#title' => $this->t('Participation by State'),
      '#open' => TRUE,
    ];

    $state_rows = [];
    foreach (array_slice($state_counts, 0, 10) as $state => $count) {
      $state_rows[] = [$state, $count];
    }

    $build['state_stats']['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('State'), $this->t('Participants')],
      '#rows' => $state_rows,
      '#empty' => $this->t('No state data available.'),
    ];

    $build['#attached']['library'][] = 'nfr/nfr-dashboard';

    return $build;
  }

  /**
   * Cancer data page callback.
   *
   * @return array
   *   Render array.
   */
  public function cancerData(): array {
    $build = [];

    $build['title'] = [
      '#markup' => '<h2>' . $this->t('Cancer Data Summary') . '</h2>',
    ];
    
    // Get total cancer records
    $total_cancer = $this->database->select('nfr_cancer_data', 'c')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Get state registry linkages
    $linked_registries = $this->database->select('nfr_cancer_data', 'c')
      ->condition('state_registry_linked', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    $build['summary'] = [
      '#markup' => '<div class="nfr-stats">
        <div class="stat-item">
          <span class="stat-label">' . $this->t('Total Cancer Cases') . ':</span>
          <span class="stat-value">' . $total_cancer . '</span>
        </div>
        <div class="stat-item">
          <span class="stat-label">' . $this->t('Linked to State Registries') . ':</span>
          <span class="stat-value">' . $linked_registries . '</span>
        </div>
      </div>',
    ];

    $build['#attached']['library'][] = 'nfr/nfr-dashboard';

    return $build;
  }

}
