<?php

namespace Drupal\amisafe\Commands;

use Drupal\amisafe\Service\H3PrecomputationService;
use Drush\Commands\DrushCommands;

/**
 * AmISafe H3 Pre-computation Drush Commands
 */
class H3PrecomputationCommands extends DrushCommands {

  /**
   * The H3 pre-computation service.
   *
   * @var \Drupal\amisafe\Service\H3PrecomputationService
   */
  protected $h3PrecomputationService;

  /**
   * Constructor.
   */
  public function __construct(H3PrecomputationService $h3_precomputation_service) {
    $this->h3PrecomputationService = $h3_precomputation_service;
  }

  /**
   * Generate H3 indexes for all crime incidents
   *
   * @command amisafe:generate-h3-indexes
   * @aliases amisafe-h3-indexes
   * @usage amisafe:generate-h3-indexes
   *   Generate H3 indexes for all raw crime incidents
   */
  public function generateH3Indexes() {
    $this->output()->writeln('🚀 Starting H3 index generation for crime incidents...');
    
    $processed = $this->h3PrecomputationService->generateH3IndexesForIncidents();
    
    $this->output()->writeln("✅ Completed! Generated H3 indexes for {$processed} incidents.");
  }

  /**
   * Pre-compute H3 aggregations for a specific resolution
   *
   * @command amisafe:precompute-resolution
   * @aliases amisafe-precompute
   * @param int $resolution The H3 resolution level (6-15)
   * @usage amisafe:precompute-resolution 9
   *   Pre-compute H3 aggregations for resolution 9 (street level)
   * @usage amisafe:precompute-resolution 14
   *   Pre-compute H3 aggregations for resolution 14 (1-meter precision)
   */
  public function precomputeResolution($resolution = 9) {
    if ($resolution < 6 || $resolution > 15) {
      $this->output()->writeln("❌ Error: Resolution must be between 6 and 15");
      return;
    }

    $this->output()->writeln("🚀 Starting H3 pre-computation for resolution {$resolution}...");
    
    $result = $this->h3PrecomputationService->precomputeResolution($resolution);
    
    $this->output()->writeln("✅ Completed H3 pre-computation for resolution {$resolution}:");
    $this->output()->writeln("   - Hexagons with data: {$result['hexagons_with_data']}");
    $this->output()->writeln("   - Empty hexagons: {$result['empty_hexagons']}");
    $this->output()->writeln("   - Total hexagons: {$result['total_hexagons']}");
  }

  /**
   * Pre-compute H3 aggregations for all resolutions (6-15)
   *
   * @command amisafe:precompute-all
   * @aliases amisafe-precompute-all
   * @usage amisafe:precompute-all
   *   Pre-compute H3 aggregations for all resolution levels (6-15)
   */
  public function precomputeAll() {
    $this->output()->writeln('🚀 Starting H3 pre-computation for ALL resolutions (6-15)...');
    $this->output()->writeln('⚠️  This may take several minutes to complete.');
    
    $results = $this->h3PrecomputationService->precomputeAllResolutions();
    
    $this->output()->writeln('✅ Completed H3 pre-computation for all resolutions:');
    
    foreach ($results as $resolution => $result) {
      $this->output()->writeln("   Resolution {$resolution}: {$result['total_hexagons']} hexagons");
    }
    
    $total_hexagons = array_sum(array_column($results, 'total_hexagons'));
    $this->output()->writeln("🎯 Grand total: {$total_hexagons} hexagons across all resolutions");
  }

  /**
   * Show H3 pre-computation status
   *
   * @command amisafe:h3-status
   * @aliases amisafe-status
   * @usage amisafe:h3-status
   *   Show current H3 pre-computation status
   */
  public function showStatus() {
    $database = \Drupal\Core\Database\Database::getConnection('default', 'amisafe');
    
    $this->output()->writeln('📊 H3 Pre-computation Status:');
    $this->output()->writeln('');
    
    // Show incident counts
    $incident_count = $database->select('amisafe_raw_incidents', 'ri')
      ->countQuery()
      ->execute()
      ->fetchField();
    
    $incidents_with_h3 = $database->select('amisafe_raw_incidents', 'ri')
      ->condition('h3_index', '', '!=')
      ->countQuery()
      ->execute()
      ->fetchField();
    
    $this->output()->writeln("📍 Raw Incidents:");
    $this->output()->writeln("   - Total incidents: {$incident_count}");
    $this->output()->writeln("   - With H3 indexes: {$incidents_with_h3}");
    $this->output()->writeln('');
    
    // Show aggregation status by resolution
    $query = $database->select('amisafe_h3_aggregated', 'h3');
    $query->fields('h3', ['h3_resolution']);
    $query->addExpression('COUNT(*)', 'total_hexagons');
    $query->addExpression('SUM(CASE WHEN is_empty = 0 THEN 1 ELSE 0 END)', 'hexagons_with_data');
    $query->addExpression('SUM(CASE WHEN is_empty = 1 THEN 1 ELSE 0 END)', 'empty_hexagons');
    $query->groupBy('h3_resolution');
    $query->orderBy('h3_resolution');
    
    $aggregations = $query->execute()->fetchAll();
    
    if ($aggregations) {
      $this->output()->writeln('🔷 H3 Aggregations by Resolution:');
      foreach ($aggregations as $agg) {
        $desc = $this->getResolutionDescription($agg->h3_resolution);
        $this->output()->writeln("   Resolution {$agg->h3_resolution} ({$desc}): {$agg->total_hexagons} hexagons ({$agg->hexagons_with_data} with data, {$agg->empty_hexagons} empty)");
      }
    } else {
      $this->output()->writeln('❌ No H3 aggregations found. Run precompute commands first.');
    }
  }

  /**
   * Get human-readable resolution description
   */
  private function getResolutionDescription($resolution) {
    $descriptions = [
      6 => '~3.1km',
      7 => '~1.2km', 
      8 => '~460m',
      9 => '~174m',
      10 => '~65m',
      11 => '~25m',
      12 => '~9m',
      13 => '~3.4m',
      14 => '~1.3m',
      15 => '~0.5m'
    ];
    
    return $descriptions[$resolution] ?? 'unknown';
  }

}