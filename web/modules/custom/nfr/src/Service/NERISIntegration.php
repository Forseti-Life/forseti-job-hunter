<?php

namespace Drupal\nfr\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * USFA NERIS Integration Service.
 */
class NERISIntegration {

  /**
   * Constructs a NERISIntegration object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   */
  public function __construct(
    protected Connection $database,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ClientInterface $httpClient,
  ) {}

  /**
   * Import data from USFA NERIS.
   *
   * @param string $neris_id
   *   The NERIS ID to import.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function importFromNERIS(string $neris_id): bool {
    try {
      // This would make an API call to USFA NERIS
      // Example implementation - would need actual NERIS API endpoint
      
      $this->loggerFactory->get('nfr')->info('Importing NERIS data for ID: @id', ['@id' => $neris_id]);
      
      // Check if firefighter with this NERIS ID already exists
      $existing = $this->database->select('nfr_firefighters', 'n')
        ->fields('n', ['id'])
        ->condition('neris_id', $neris_id)
        ->execute()
        ->fetchField();

      if ($existing) {
        $this->loggerFactory->get('nfr')->warning('Firefighter with NERIS ID @id already exists', ['@id' => $neris_id]);
        return FALSE;
      }

      // Import logic would go here
      // This is a placeholder for the actual NERIS API integration
      
      return TRUE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('nfr')->error('Error importing NERIS data: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Sync firefighter data with NERIS.
   *
   * @param int $firefighter_id
   *   The firefighter ID to sync.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function syncWithNERIS(int $firefighter_id): bool {
    try {
      $firefighter = $this->database->select('nfr_firefighters', 'n')
        ->fields('n')
        ->condition('id', $firefighter_id)
        ->execute()
        ->fetchObject();

      if (!$firefighter) {
        return FALSE;
      }

      // Sync logic would go here
      $this->loggerFactory->get('nfr')->info('Syncing firefighter @id with NERIS', ['@id' => $firefighter_id]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('nfr')->error('Error syncing with NERIS: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

}
