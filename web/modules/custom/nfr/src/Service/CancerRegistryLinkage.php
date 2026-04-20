<?php

namespace Drupal\nfr\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * State Cancer Registry Linkage Service.
 */
class CancerRegistryLinkage {

  /**
   * Constructs a CancerRegistryLinkage object.
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
   * Link firefighter cancer data to state cancer registry.
   *
   * @param int $cancer_data_id
   *   The cancer data record ID.
   * @param string $state_registry_id
   *   The state registry identifier.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function linkToStateRegistry(int $cancer_data_id, string $state_registry_id): bool {
    try {
      $this->database->update('nfr_cancer_data')
        ->fields([
          'state_registry_linked' => 1,
          'state_registry_id' => $state_registry_id,
          'updated' => \Drupal::time()->getRequestTime(),
        ])
        ->condition('id', $cancer_data_id)
        ->execute();

      $this->loggerFactory->get('nfr')->info('Linked cancer record @id to state registry', ['@id' => $cancer_data_id]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('nfr')->error('Error linking to state registry: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Get linkage statistics by state.
   *
   * @return array<string, array<string, int|float>>
   *   Array of linkage statistics.
   */
  public function getLinkageStatistics(): array {
    $query = $this->database->select('nfr_cancer_data', 'c');
    $query->leftJoin('nfr_firefighters', 'f', 'c.firefighter_id = f.id');
    $query->fields('f', ['state']);
    $query->addExpression('COUNT(*)', 'total_cases');
    $query->addExpression('SUM(CASE WHEN c.state_registry_linked = 1 THEN 1 ELSE 0 END)', 'linked_cases');
    $query->groupBy('f.state');
    $query->orderBy('total_cases', 'DESC');

    $results = $query->execute();

    $statistics = [];
    foreach ($results as $row) {
      if (!empty($row->state)) {
        $statistics[$row->state] = [
          'total' => $row->total_cases,
          'linked' => $row->linked_cases,
          'linkage_rate' => $row->total_cases > 0 ? round(($row->linked_cases / $row->total_cases) * 100, 2) : 0,
        ];
      }
    }

    return $statistics;
  }

  /**
   * Process batch linkage for a specific state.
   *
   * @param string $state
   *   The state abbreviation.
   *
   * @return array<string, int|string>
   *   Results of the batch process.
   */
  public function processBatchLinkage(string $state): array {
    try {
      // Get unlinked cancer records for the specified state
      $query = $this->database->select('nfr_cancer_data', 'c');
      $query->leftJoin('nfr_firefighters', 'f', 'c.firefighter_id = f.id');
      $query->fields('c', ['id']);
      $query->condition('f.state', $state);
      $query->condition('c.state_registry_linked', 0);

      $results = $query->execute();

      $processed = 0;
      $failed = 0;

      foreach ($results as $row) {
        // Actual linkage logic would go here
        // This is a placeholder for state registry API integration
        $processed++;
      }

      $this->loggerFactory->get('nfr')->info('Batch linkage for @state: @processed processed, @failed failed', [
        '@state' => $state,
        '@processed' => $processed,
        '@failed' => $failed,
      ]);

      return [
        'processed' => $processed,
        'failed' => $failed,
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('nfr')->error('Error in batch linkage: @message', ['@message' => $e->getMessage()]);
      return [
        'processed' => 0,
        'failed' => 0,
        'error' => $e->getMessage(),
      ];
    }
  }

}
