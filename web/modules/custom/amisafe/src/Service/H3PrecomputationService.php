<?php

namespace Drupal\amisafe\Service;

use Drupal\Core\Database\Database;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * H3 Pre-computation Service for AmISafe
 * 
 * This service handles the pre-calculation of H3 hexagon data
 * for all resolution levels, enabling fast 1-meter precision mapping.
 */
class H3PrecomputationService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Philadelphia boundary coordinates
   */
  const PHILADELPHIA_BOUNDS = [
    'north' => 40.2,
    'south' => 39.8,
    'east' => -74.9,
    'west' => -75.5
  ];

  /**
   * Constructor.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Get the AmISafe database connection lazily.
   *
   * This prevents unrelated Drush commands from failing during bootstrap just
   * because the optional AmISafe secondary database is unavailable.
   */
  protected function getDatabase() {
    if (!$this->database) {
      $this->database = Database::getConnection('default', 'amisafe');
    }
    return $this->database;
  }

  /**
   * Generate H3 indexes for all raw incidents
   */
  public function generateH3IndexesForIncidents() {
    $logger = $this->loggerFactory->get('amisafe_precomputation');
    $logger->info('Starting H3 index generation for incidents...');
    $database = $this->getDatabase();

    // Get all incidents without H3 indexes (NULL or empty)
    $query = $database->select('raw_incidents', 'ri')
      ->fields('ri', ['id', 'lat', 'lng'])
      ->condition('lat', 0, '!=')
      ->condition('lng', 0, '!=');
    
    $or = $query->orConditionGroup()
      ->condition('h3_index', '', '=')
      ->isNull('h3_index');
    $query->condition($or);
    
    $incidents = $query->execute()->fetchAll();
    $total = count($incidents);
    $processed = 0;

    $logger->info("Found {$total} incidents to process");

    foreach ($incidents as $incident) {
      // Calculate H3 index at resolution 9 (street level - good base resolution)
      $h3_index = $this->latLngToH3($incident->lat, $incident->lng, 9);
      
      if ($h3_index) {
        // Update the incident with H3 index
        $database->update('raw_incidents')
          ->fields(['h3_index' => $h3_index])
          ->condition('id', $incident->id)
          ->execute();
        
        $processed++;
        
        if ($processed % 100 == 0) {
          $logger->info("Processed {$processed}/{$total} incidents");
        }
      }
    }

    $logger->info("Completed H3 index generation. Processed: {$processed}/{$total}");
    return $processed;
  }

  /**
   * Pre-compute H3 aggregations for a specific resolution
   */
  public function precomputeResolution($resolution = 9) {
    $logger = $this->loggerFactory->get('amisafe_precomputation');
    $logger->info("Starting pre-computation for H3 resolution {$resolution}");
    $database = $this->getDatabase();

    // Update processing status
    $this->updateProcessingStatus($resolution, 'AGGREGATION', 'RUNNING');

    // Get all incidents with coordinates
    $query = $database->select('raw_incidents', 'ri')
      ->fields('ri')
      ->condition('lat', 0, '!=')
      ->condition('lng', 0, '!=');
    
    $incidents = $query->execute()->fetchAll();
    $total_incidents = count($incidents);
    
    $logger->info("Processing {$total_incidents} incidents for resolution {$resolution}");

    // Group incidents by H3 index at the target resolution
    $h3_aggregation = [];
    $processed = 0;

    foreach ($incidents as $incident) {
      $h3_index = $this->latLngToH3($incident->lat, $incident->lng, $resolution);
      
      if ($h3_index) {
        if (!isset($h3_aggregation[$h3_index])) {
          $h3_aggregation[$h3_index] = [
            'crime_count' => 0,
            'crime_types' => [],
            'incidents' => []
          ];
        }
        
        $h3_aggregation[$h3_index]['crime_count']++;
        $h3_aggregation[$h3_index]['crime_types'][$incident->crime_type] = 
          ($h3_aggregation[$h3_index]['crime_types'][$incident->crime_type] ?? 0) + 1;
        $h3_aggregation[$h3_index]['incidents'][] = $incident;
      }
      
      $processed++;
      if ($processed % 1000 == 0) {
        $progress = ($processed / $total_incidents) * 100;
        $this->updateProcessingProgress($resolution, 'AGGREGATION', $progress, $processed, $total_incidents);
        $logger->info("Aggregation progress: {$progress}% ({$processed}/{$total_incidents})");
      }
    }

    // Store aggregated data
    $hexagons_stored = 0;
    foreach ($h3_aggregation as $h3_index => $data) {
      $center = $this->h3ToLatLng($h3_index);
      $boundary = $this->h3ToBoundary($h3_index);
      
        if ($center && $boundary) {
          // Store in database
          $database->merge('amisafe_h3_aggregated')
          ->keys(['h3_index' => $h3_index, 'h3_resolution' => $resolution])
          ->fields([
            'center_lat' => $center['lat'],
            'center_lng' => $center['lng'],
            'boundary_json' => json_encode($boundary),
            'crime_count' => $data['crime_count'],
            'crime_types_json' => json_encode($data['crime_types']),
            'is_empty' => 0,
            'last_updated' => date('Y-m-d H:i:s')
          ])
          ->execute();
        
        $hexagons_stored++;
      }
    }

    // Generate empty hexagons for complete grid
    $empty_hexagons = $this->generateEmptyHexagons($resolution, array_keys($h3_aggregation));
    
    $logger->info("Stored {$hexagons_stored} hexagons with data and {$empty_hexagons} empty hexagons for resolution {$resolution}");
    
    // Update completion status
    $this->updateProcessingStatus($resolution, 'AGGREGATION', 'COMPLETED');
    
    return [
      'hexagons_with_data' => $hexagons_stored,
      'empty_hexagons' => $empty_hexagons,
      'total_hexagons' => $hexagons_stored + $empty_hexagons
    ];
  }

  /**
   * Generate empty hexagons for complete grid visualization
   */
  private function generateEmptyHexagons($resolution, $existing_indexes) {
    $logger = $this->loggerFactory->get('amisafe_precomputation');
    $logger->info("Generating empty hexagon grid for resolution {$resolution}");
    $database = $this->getDatabase();

    $bounds = self::PHILADELPHIA_BOUNDS;
    
    // Create a polygon for Philadelphia bounds
    $philadelphia_polygon = [
      [$bounds['west'], $bounds['south']],
      [$bounds['east'], $bounds['south']],
      [$bounds['east'], $bounds['north']],
      [$bounds['west'], $bounds['north']],
      [$bounds['west'], $bounds['south']]
    ];

    // Get all H3 indexes in Philadelphia bounds
    $all_indexes = $this->polygonToH3($philadelphia_polygon, $resolution);
    $existing_set = array_flip($existing_indexes);
    $empty_count = 0;

    foreach ($all_indexes as $h3_index) {
      if (!isset($existing_set[$h3_index])) {
        $center = $this->h3ToLatLng($h3_index);
        $boundary = $this->h3ToBoundary($h3_index);
        
        if ($center && $boundary) {
          // Store empty hexagon
          $database->merge('amisafe_h3_aggregated')
            ->keys(['h3_index' => $h3_index, 'h3_resolution' => $resolution])
            ->fields([
              'center_lat' => $center['lat'],
              'center_lng' => $center['lng'],
              'boundary_json' => json_encode($boundary),
              'crime_count' => 0,
              'crime_types_json' => json_encode([]),
              'is_empty' => 1,
              'last_updated' => date('Y-m-d H:i:s')
            ])
            ->execute();
          
          $empty_count++;
        }
      }
    }

    $logger->info("Generated {$empty_count} empty hexagons for resolution {$resolution}");
    return $empty_count;
  }

  /**
   * Pre-compute all resolutions (6-15)
   */
  public function precomputeAllResolutions() {
    $resolutions = [6, 7, 8, 9, 10, 11, 12, 13, 14, 15];
    $results = [];

    foreach ($resolutions as $resolution) {
      $results[$resolution] = $this->precomputeResolution($resolution);
    }

    return $results;
  }

  /**
   * Update processing status
   */
  private function updateProcessingStatus($resolution, $process_type, $status, $error_message = null) {
    $database = $this->getDatabase();
    $fields = [
      'status' => $status,
      'error_message' => $error_message
    ];

    if ($status === 'RUNNING') {
      $fields['started_at'] = date('Y-m-d H:i:s');
    } elseif ($status === 'COMPLETED') {
      $fields['completed_at'] = date('Y-m-d H:i:s');
      $fields['progress_percent'] = 100.00;
    }

    $database->merge('amisafe_h3_processing_status')
      ->keys(['resolution' => $resolution, 'process_type' => $process_type])
      ->fields($fields)
      ->execute();
  }

  /**
   * Update processing progress
   */
  private function updateProcessingProgress($resolution, $process_type, $progress_percent, $processed, $total) {
    $this->getDatabase()->update('amisafe_h3_processing_status')
      ->fields([
        'progress_percent' => $progress_percent,
        'records_processed' => $processed,
        'total_records' => $total
      ])
      ->condition('resolution', $resolution)
      ->condition('process_type', $process_type)
      ->execute();
  }

  /**
   * Placeholder H3 functions (will be implemented with external H3 library or API)
   */
  private function latLngToH3($lat, $lng, $resolution) {
    // This would use the H3 library to convert lat/lng to H3 index
    // For now, return a mock H3 index based on coordinates and resolution
    $lat_int = intval($lat * 10000);
    $lng_int = intval(abs($lng) * 10000);
    return sprintf('%02d%06d%07d', $resolution, $lat_int, $lng_int);
  }

  private function h3ToLatLng($h3_index) {
    // Extract coordinates from mock H3 index
    $resolution = intval(substr($h3_index, 0, 2));
    $lat_part = intval(substr($h3_index, 2, 6));
    $lng_part = intval(substr($h3_index, 8, 7));
    
    return [
      'lat' => $lat_part / 10000.0,
      'lng' => -($lng_part / 10000.0)
    ];
  }

  private function h3ToBoundary($h3_index) {
    // Generate mock hexagon boundary
    $center = $this->h3ToLatLng($h3_index);
    $resolution = intval(substr($h3_index, 0, 2));
    
    // Hexagon size based on resolution
    $size_map = [
      6 => 0.05, 7 => 0.02, 8 => 0.008, 9 => 0.003, 10 => 0.001,
      11 => 0.0004, 12 => 0.00015, 13 => 0.00006, 14 => 0.00002, 15 => 0.000008
    ];
    
    $size = $size_map[$resolution] ?? 0.001;
    
    // Generate hexagon vertices
    $vertices = [];
    for ($i = 0; $i < 6; $i++) {
      $angle = $i * M_PI / 3;
      $vertices[] = [
        $center['lng'] + $size * cos($angle),
        $center['lat'] + $size * sin($angle)
      ];
    }
    
    return $vertices;
  }

  private function polygonToH3($polygon, $resolution) {
    // Mock implementation - generate H3 indexes within polygon bounds
    $indexes = [];
    $bounds = $this->getPolygonBounds($polygon);
    
    // Generate grid of H3 indexes within bounds
    $lat_step = 0.001 * (16 - $resolution); // Smaller steps for higher resolution
    $lng_step = 0.001 * (16 - $resolution);
    
    for ($lat = $bounds['south']; $lat <= $bounds['north']; $lat += $lat_step) {
      for ($lng = $bounds['west']; $lng <= $bounds['east']; $lng += $lng_step) {
        if ($this->pointInPolygon($lat, $lng, $polygon)) {
          $indexes[] = $this->latLngToH3($lat, $lng, $resolution);
        }
      }
    }
    
    return array_unique($indexes);
  }

  private function getPolygonBounds($polygon) {
    $lats = array_column($polygon, 1);
    $lngs = array_column($polygon, 0);
    
    return [
      'north' => max($lats),
      'south' => min($lats),
      'east' => max($lngs),
      'west' => min($lngs)
    ];
  }

  private function pointInPolygon($lat, $lng, $polygon) {
    // Simple bounding box check for mock implementation
    $bounds = $this->getPolygonBounds($polygon);
    return $lat >= $bounds['south'] && $lat <= $bounds['north'] && 
           $lng >= $bounds['west'] && $lng <= $bounds['east'];
  }

}
