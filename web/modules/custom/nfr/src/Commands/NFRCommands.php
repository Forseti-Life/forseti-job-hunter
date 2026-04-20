<?php

namespace Drupal\nfr\Commands;

use Drupal\Core\Database\Connection;
use Drupal\nfr\Service\NERISIntegration;
use Drupal\nfr\Service\CancerRegistryLinkage;
use Drupal\nfr\Service\DataExport;
use Drush\Commands\DrushCommands;

/**
 * NFR Drush commands.
 */
class NFRCommands extends DrushCommands {

  /**
   * Constructs a NFRCommands object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\nfr\Service\NERISIntegration $nerisIntegration
   *   The NERIS integration service.
   * @param \Drupal\nfr\Service\CancerRegistryLinkage $cancerRegistryLinkage
   *   The cancer registry linkage service.
   * @param \Drupal\nfr\Service\DataExport $dataExport
   *   The data export service.
   */
  public function __construct(
    protected Connection $database,
    protected NERISIntegration $nerisIntegration,
    protected CancerRegistryLinkage $cancerRegistryLinkage,
    protected DataExport $dataExport,
  ) {
    parent::__construct();
  }

  /**
   * Display NFR statistics.
   *
   * @command nfr:stats
   * @aliases nfr-stats
   * @usage nfr:stats
   *   Display NFR registry statistics
   */
  public function stats(): void {
    $stats = $this->dataExport->exportSummaryStatistics();

    $this->output()->writeln('=== National Firefighter Registry Statistics ===');
    $this->output()->writeln('');
    $this->output()->writeln('Total Participants: ' . $stats['total_participants']);
    $this->output()->writeln('Active Participants: ' . $stats['active_participants']);
    $this->output()->writeln('Total Cancer Cases: ' . $stats['total_cancer_cases']);
    $this->output()->writeln('State Registry Linkages: ' . $stats['state_registry_linkages']);
    $this->output()->writeln('');

    if (!empty($stats['career_type'])) {
      $this->output()->writeln('=== By Career Type ===');
      foreach ($stats['career_type'] as $type => $count) {
        $this->output()->writeln(ucfirst($type) . ': ' . $count);
      }
    }
  }

  /**
   * Import firefighter data from USFA NERIS.
   *
   * @param string $neris_id
   *   The NERIS ID to import.
   *
   * @command nfr:import-neris
   * @aliases nfr-import
   * @usage nfr:import-neris 12345
   *   Import firefighter with NERIS ID 12345
   */
  public function importNeris(string $neris_id): void {
    $this->output()->writeln("Importing NERIS ID: $neris_id");
    
    $result = $this->nerisIntegration->importFromNERIS($neris_id);
    
    if ($result) {
      $this->output()->writeln("<info>Successfully imported NERIS ID: $neris_id</info>");
    }
    else {
      $this->output()->writeln("<error>Failed to import NERIS ID: $neris_id</error>");
    }
  }

  /**
   * Process batch linkage to state cancer registries.
   *
   * @param string $state
   *   The state abbreviation (e.g., CA, NY).
   *
   * @command nfr:link-state
   * @aliases nfr-link
   * @usage nfr:link-state CA
   *   Process linkages for California cancer registry
   */
  public function linkState(string $state): void {
    $state = strtoupper($state);
    $this->output()->writeln("Processing state registry linkage for: $state");
    
    $result = $this->cancerRegistryLinkage->processBatchLinkage($state);
    
    $this->output()->writeln("<info>Processed: {$result['processed']}</info>");
    $this->output()->writeln("<info>Failed: {$result['failed']}</info>");
    
    if (isset($result['error'])) {
      $this->output()->writeln("<error>Error: {$result['error']}</error>");
    }
  }

  /**
   * Display linkage statistics by state.
   *
   * @command nfr:linkage-stats
   * @aliases nfr-link-stats
   * @usage nfr:linkage-stats
   *   Display state registry linkage statistics
   */
  public function linkageStats(): void {
    $stats = $this->cancerRegistryLinkage->getLinkageStatistics();

    $this->output()->writeln('=== State Cancer Registry Linkage Statistics ===');
    $this->output()->writeln('');

    if (empty($stats)) {
      $this->output()->writeln('No linkage data available.');
      return;
    }

    foreach ($stats as $state => $data) {
      $this->output()->writeln(sprintf(
        '%s: %d total, %d linked (%.1f%%)',
        $state,
        $data['total'],
        $data['linked'],
        $data['linkage_rate']
      ));
    }
  }

  /**
   * Export NFR data to CSV.
   *
   * @param string $type
   *   Type of data to export (firefighters or cancer_data).
   * @param array<string, string|null> $options
   *   Command options.
   *
   * @option state Filter by state abbreviation.
   * @option status Filter by status (for firefighters).
   *
   * @command nfr:export
   * @aliases nfr-export
   * @usage nfr:export firefighters --state=CA
   *   Export California firefighters to CSV
   * @usage nfr:export cancer_data
   *   Export all cancer data to CSV
   */
  public function export(string $type, array $options = ['state' => NULL, 'status' => NULL]): void {
    $filters = array_filter($options);
    
    $this->output()->writeln("Exporting $type data...");
    
    $csv = $this->dataExport->exportToCSV($type, $filters);
    
    $filename = "nfr_export_{$type}_" . date('Y-m-d_H-i-s') . '.csv';
    file_put_contents("/tmp/$filename", $csv);
    
    $this->output()->writeln("<info>Export complete: /tmp/$filename</info>");
  }

}
