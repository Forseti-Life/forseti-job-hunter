<?php

namespace Drupal\nfr\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Data Export Service.
 */
class DataExport {

  /**
   * Constructs a DataExport object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    protected Connection $database,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Export summary statistics for dashboard.
   *
   * @return array<string, mixed>
   *   Summary statistics.
   */
  public function exportSummaryStatistics(): array {
    $stats = [];

    // Total participants
    $stats['total_participants'] = $this->database->select('nfr_firefighters', 'n')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Active participants
    $stats['active_participants'] = $this->database->select('nfr_firefighters', 'n')
      ->condition('status', 'active')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Total cancer cases
    $stats['total_cancer_cases'] = $this->database->select('nfr_cancer_data', 'c')
      ->countQuery()
      ->execute()
      ->fetchField();

    // State registry linkages
    $stats['state_registry_linkages'] = $this->database->select('nfr_cancer_data', 'c')
      ->condition('state_registry_linked', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    // Participation by career type
    $career_types = $this->database->select('nfr_firefighters', 'n')
      ->fields('n', ['career_type'])
      ->groupBy('career_type')
      ->execute();

    foreach ($career_types as $type) {
      if (!empty($type->career_type)) {
        $count = $this->database->select('nfr_firefighters', 'n')
          ->condition('career_type', $type->career_type)
          ->countQuery()
          ->execute()
          ->fetchField();
        $stats['career_type'][$type->career_type] = $count;
      }
    }

    return $stats;
  }

  /**
   * Export data in CSV format.
   *
   * @param string $type
   *   Type of data to export (firefighters, cancer_data, etc.).
   * @param array<string, mixed> $filters
   *   Optional filters to apply.
   *
   * @return string
   *   CSV formatted data.
   */
  public function exportToCSV(string $type, array $filters = []): string {
    $output = '';

    switch ($type) {
      case 'firefighters':
        $query = $this->database->select('nfr_firefighters', 'n')
          ->fields('n');
        
        if (!empty($filters['state'])) {
          $query->condition('state', $filters['state']);
        }
        if (!empty($filters['status'])) {
          $query->condition('status', $filters['status']);
        }

        $results = $query->execute();

        // CSV header
        $output .= "ID,First Name,Last Name,Department,State,Career Type,Years of Service,Status\n";

        foreach ($results as $row) {
          $output .= sprintf("%d,%s,%s,%s,%s,%s,%d,%s\n",
            $row->id,
            $this->escapeCsv($row->first_name),
            $this->escapeCsv($row->last_name),
            $this->escapeCsv($row->department),
            $row->state,
            $row->career_type,
            $row->years_of_service ?? 0,
            $row->status
          );
        }
        break;

      case 'cancer_data':
        // De-identified cancer data export
        $query = $this->database->select('nfr_cancer_data', 'c');
        $query->leftJoin('nfr_firefighters', 'f', 'c.firefighter_id = f.id');
        $query->fields('c', ['cancer_type', 'stage', 'state_registry_linked']);
        $query->fields('f', ['state', 'career_type', 'years_of_service']);

        if (!empty($filters['state'])) {
          $query->condition('f.state', $filters['state']);
        }

        $results = $query->execute();

        // CSV header
        $output .= "Cancer Type,Stage,State,Career Type,Years of Service,State Registry Linked\n";

        foreach ($results as $row) {
          $output .= sprintf("%s,%s,%s,%s,%d,%s\n",
            $this->escapeCsv($row->cancer_type),
            $this->escapeCsv($row->stage ?? 'Unknown'),
            $row->state,
            $row->career_type,
            $row->years_of_service ?? 0,
            $row->state_registry_linked ? 'Yes' : 'No'
          );
        }
        break;
    }

    $this->loggerFactory->get('nfr')->info('Exported @type data', ['@type' => $type]);

    return $output;
  }

  /**
   * Escape CSV field.
   *
   * @param string $value
   *   The value to escape.
   *
   * @return string
   *   Escaped value.
   */
  protected function escapeCsv(string $value): string {
    if (strpos($value, ',') !== FALSE || strpos($value, '"') !== FALSE || strpos($value, "\n") !== FALSE) {
      return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
  }

}
