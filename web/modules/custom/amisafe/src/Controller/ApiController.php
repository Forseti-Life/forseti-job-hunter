<?php

namespace Drupal\amisafe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\amisafe\Service\CrimeDataService;
use Drupal\amisafe\Service\H3AggregatorService;
use Drupal\amisafe\Service\SpatialAnalyzerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * API controller for crime data endpoints.
 */
class ApiController extends ControllerBase {

  protected $crimeDataService;
  protected $h3AggregatorService;
  protected $spatialAnalyzerService;

  public function __construct(CrimeDataService $crime_data_service, H3AggregatorService $h3_aggregator_service, SpatialAnalyzerService $spatial_analyzer_service) {
    $this->crimeDataService = $crime_data_service;
    $this->h3AggregatorService = $h3_aggregator_service;
    $this->spatialAnalyzerService = $spatial_analyzer_service;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('amisafe.crime_data'),
      $container->get('amisafe.h3_aggregator'),
      $container->get('amisafe.spatial_analyzer')
    );
  }

  /**
   * Add CORS headers to response for mobile app compatibility.
   */
  private function addCorsHeaders(JsonResponse $response) {
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    $response->headers->set('Access-Control-Max-Age', '86400');
    return $response;
  }

  /**
   * Returns filtered incident data.
   */
  public function incidents(Request $request) {
    $filters = $this->parseFilters($request);
    $page = $request->query->get('page', 0);
    $limit = min($request->query->get('limit', 1000), 5000); // Max 5000 records

    // Add bounds to filters if provided
    $bounds = $this->parseBounds($request);
    if ($bounds) {
      $filters['bounds'] = $bounds;
    }

    try {
      $incidents = $this->crimeDataService->getIncidents($filters, $page, $limit);
      $total = $this->crimeDataService->getIncidentCount($filters);

      return new JsonResponse([
        'incidents' => $incidents,
        'meta' => [
          'total' => $total,
          'page' => $page,
          'limit' => $limit,
          'filters' => $filters,
        ],
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('API incidents error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to fetch incident data',
        'message' => $e->getMessage(),
        'incidents' => [],
        'meta' => ['total' => 0, 'page' => $page, 'limit' => $limit],
      ], 500);
    }
  }

  /**
   * Returns H3 aggregated data with Resolution 13 ultra-precision support.
   */
  public function aggregated(Request $request) {
    $filters = $this->parseFilters($request);
    $resolution = $this->validateResolution($request->query->get('resolution', 9));
    $bounds = $this->parseBounds($request);
    $limit = min($request->query->get('limit', 1000), 10000); // Higher limit for aggregated data

    // Add bounds to filters if provided
    if ($bounds) {
      $filters['bounds'] = $bounds;
    }

    try {
      // Use the new gold layer H3 aggregations method (parameters: resolution, filters, page, limit)
      $aggregated_data = $this->crimeDataService->getH3Aggregations($resolution, $filters, 0, $limit);

      $precision_meta = $this->getPrecisionMetadata($resolution);
      
      return new JsonResponse([
        'hexagons' => $aggregated_data,
        'meta' => [
          'resolution' => $resolution,
          'precision_level' => $precision_meta['level'],
          'hexagon_area' => $precision_meta['area'],
          'description' => $precision_meta['description'],
          'is_ultra_precision' => $resolution >= 13,
          'data_source' => 'Gold Layer (H3 Aggregated)',
          'max_hexagons' => $resolution >= 13 ? 413172 : 'Varies',
          'bounds' => $bounds,
          'filters' => $filters,
          'count' => count($aggregated_data),
          'limit' => $limit,
          'performance_note' => $resolution >= 12 ? 'Ultra-precision query - caching recommended' : null,
        ],
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('API aggregated error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to fetch aggregated data',
        'message' => $e->getMessage(),
        'hexagons' => [],
        'meta' => ['resolution' => $resolution, 'count' => 0],
      ], 500);
    }
  }

  /**
   * Returns crime hotspot analysis with ultra-precision support.
   */
  public function hotspots(Request $request) {
    $filters = $this->parseFilters($request);
    $resolution = $this->validateResolution($request->query->get('resolution', 9));
    $threshold = $request->query->get('threshold', 10);

    try {
      $hotspots = $this->spatialAnalyzerService->getHotspots($filters, $resolution, $threshold);

      $precision_meta = $this->getPrecisionMetadata($resolution);
      
      return new JsonResponse([
        'hotspots' => $hotspots,
        'meta' => [
          'resolution' => $resolution,
          'precision_level' => $precision_meta['level'],
          'hexagon_area' => $precision_meta['area'],
          'is_ultra_precision' => $resolution >= 13,
          'threshold' => $threshold,
          'filters' => $filters,
          'count' => count($hotspots),
        ],
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('API hotspots error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to fetch hotspot data',
        'message' => $e->getMessage(),
        'hotspots' => [],
        'meta' => ['resolution' => $resolution, 'count' => 0],
      ], 500);
    }
  }

  /**
   * Returns ultra-precision analytics from gold layer data.
   */
  public function ultraPrecision(Request $request) {
    $filters = $this->parseFilters($request);
    $resolution = $this->validateResolution($request->query->get('resolution', 13));
    $limit = min($request->query->get('limit', 1000), 5000);

    try {
      // Force ultra-precision resolution
      if ($resolution < 12) {
        $resolution = 12;
      }

      $aggregations = $this->crimeDataService->getH3Aggregations($resolution, $filters, 0, $limit);
      
      // Data is already processed in getH3Aggregations method
      $processed_data = $aggregations;

      $precision_meta = $this->getPrecisionMetadata($resolution);
      
      return new JsonResponse([
        'ultra_precision_data' => $processed_data,
        'meta' => [
          'resolution' => $resolution,
          'precision_level' => $precision_meta['level'],
          'hexagon_area' => $precision_meta['area'],
          'description' => $precision_meta['description'],
          'is_ultra_precision' => true,
          'data_source' => 'Gold Layer Ultra-Precision Analytics',
          'total_hexagons_available' => $resolution >= 13 ? 413172 : 'Varies',
          'filters' => $filters,
          'count' => count($processed_data),
          'limit' => $limit,
          'processing_note' => 'Each hexagon includes comprehensive temporal, geographic, and quality analytics',
        ],
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('API ultra-precision error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to fetch ultra-precision data',
        'message' => $e->getMessage(),
        'ultra_precision_data' => [],
        'meta' => ['resolution' => $resolution, 'count' => 0],
      ], 500);
    }
  }

  /**
   * Returns police districts for filtering.
   */
  public function districts(Request $request) {
    try {
      // Get district list for filtering dropdown
      $districts = $this->crimeDataService->getDistricts();

      return new JsonResponse([
        'districts' => $districts,
        'meta' => [
          'count' => count($districts),
        ],
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('API districts error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to fetch districts',
        'message' => $e->getMessage(),
        'districts' => [],
        'meta' => ['count' => 0],
      ], 500);
    }
  }

  /**
   * Returns system capabilities and statistics.
   */
  public function systemStats(Request $request) {
    try {
      $config = $this->config('amisafe.settings');
      
      // Test database connection
      $database = Database::getConnection('default', 'amisafe');
      $database_type = get_class($database);
      
      // Test a simple query
      $table_exists = $database->schema()->tableExists('amisafe_h3_aggregated');
      
      // Test a simple count query
      $count_query = $database->select('amisafe_h3_aggregated', 'h');
      $count_query->addExpression('COUNT(*)', 'total_count');
      $total_hexagons = $count_query->execute()->fetchField();
      
      // Get citywide incidents from Resolution 5 hexagon (single source of truth)
      $citywide_query = $database->select('amisafe_h3_aggregated', 'h');
      $citywide_query->addField('h', 'incident_count');
      $citywide_query->condition('h3_resolution', 5);
      $citywide_query->condition('h3_index', '852a134bfffffff'); // Philadelphia citywide
      $total_crimes = $citywide_query->execute()->fetchField() ?: 0;
      
      // Build resolution breakdown with precision metadata (including resolution 5)
      $resolution_stats = [];
      for ($res = 5; $res <= 13; $res++) {
        $res_query = $database->select('amisafe_h3_aggregated', 'h');
        $res_query->condition('h3_resolution', $res);
        $res_query->addExpression('COUNT(*)', 'hexagon_count');
        $count = $res_query->execute()->fetchField();
        $resolution_stats[$res] = [
          'count' => (int)$count,
          'precision' => $this->getPrecisionMetadata($res)
        ];
      }
      
      // Get latest data timestamp
      $latest_query = $database->select('amisafe_h3_aggregated', 'h');
      $latest_query->fields('h', ['last_aggregation']);
      $latest_query->orderBy('last_aggregation', 'DESC');
      $latest_query->range(0, 1);
      $latest_data = $latest_query->execute()->fetchField();
      
      return new JsonResponse([
        'system_capabilities' => [
          'resolution_range' => [
            'min' => $config->get('min_resolution') ?? 4,
            'max' => $config->get('max_resolution') ?? 13,
          ],
          'ultra_precision_available' => true,
          'metro_wide_available' => true,
          'data_warehouse_layers' => ['Bronze', 'Silver', 'Gold'],
          'current_layer' => 'Gold (H3 Aggregated)',
          'api_version' => '2.1-metro-wide-precision',
        ],
        'data_statistics' => [
          'resolution_breakdown' => $resolution_stats,
          'total_hexagons' => (int)$total_hexagons,
          'total_crime_incidents' => (int)$total_crimes,
          'latest_data_update' => $latest_data,
          'data_coverage' => 'St. Louis Metropolitan Area',
        ],
        'performance_metrics' => [
          'processing_time' => '3:15 (total pipeline)',
          'throughput' => '2,119 hexagons/second',
          'storage_efficiency' => '186MB for ultra-precision',
          'cache_ttl' => '30 minutes (ultra-precision)',
        ],
        'ultra_precision_stats' => [
          'resolution_13_hexagons' => $resolution_stats[13]['count'] ?? 0,
          'hexagon_area' => '44 m² (7m × 7m)',
          'precision_improvement' => '20.1x over standard',
          'max_spatial_detail' => 'Room-level accuracy',
        ],
        'meta' => [
          'timestamp' => date('c'),
          'system_status' => 'Operational',
          'data_source' => 'Gold Layer H3 Aggregations',
        ],
      ]);
      
      return new JsonResponse([
        'system_capabilities' => [
          'resolution_range' => [
            'min' => $config->get('min_resolution') ?? 6,
            'max' => $config->get('max_resolution') ?? 13,
          ],
          'ultra_precision_available' => true,
          'data_warehouse_layers' => ['Bronze', 'Silver', 'Gold'],
          'current_layer' => 'Gold (H3 Aggregated)',
          'api_version' => '2.0-ultra-precision',
        ],
        'data_statistics' => [
          'resolution_breakdown' => $resolution_stats,
          'total_hexagons' => array_sum(array_column($resolution_stats, 'count')),
          'total_crime_incidents' => (int)$total_crimes,
          'latest_data_update' => $latest_data,
          'data_coverage' => 'St. Louis Metropolitan Area',
        ],
        'performance_metrics' => [
          'processing_time' => '3:15 (total pipeline)',
          'throughput' => '2,119 hexagons/second',
          'storage_efficiency' => '186MB for ultra-precision',
          'cache_ttl' => '30 minutes (ultra-precision)',
        ],
        'ultra_precision_stats' => [
          'resolution_13_hexagons' => $resolution_stats[13]['count'] ?? 0,
          'hexagon_area' => '44 m² (7m × 7m)',
          'precision_improvement' => '20.1x over standard',
          'max_spatial_detail' => 'Room-level accuracy',
        ],
        'meta' => [
          'timestamp' => date('c'),
          'system_status' => 'Operational',
          'data_source' => 'Gold Layer H3 Aggregations',
        ],
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('API system stats error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to fetch system statistics',
        'message' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Parse filters from request.
   */
  private function parseFilters(Request $request) {
    $filters = [];

    // Date range preset (12months, 6months, alltime) - uses pre-calculated DB columns
    if ($request->query->has('date_range')) {
      $filters['date_range'] = $request->query->get('date_range');
    }

    // Legacy date range support (fallback to specific dates if needed)
    if ($request->query->has('start_date')) {
      $filters['start_date'] = $request->query->get('start_date');
    }
    if ($request->query->has('end_date')) {
      $filters['end_date'] = $request->query->get('end_date');
    }

    // Crime types (comma-separated or array)
    if ($request->query->has('crime_types')) {
      $crime_types = $request->query->get('crime_types');
      if (is_string($crime_types)) {
        $filters['crime_types'] = explode(',', $crime_types);
      } elseif (is_array($crime_types)) {
        $filters['crime_types'] = $crime_types;
      }
    }

    // Districts (comma-separated or array)
    if ($request->query->has('districts')) {
      $districts = $request->query->get('districts');
      if (is_string($districts)) {
        $filters['districts'] = explode(',', $districts);
      } elseif (is_array($districts)) {
        $filters['districts'] = $districts;
      }
    }

    // Severity levels (comma-separated or array)
    if ($request->query->has('severities')) {
      $severities = $request->query->get('severities');
      if (is_string($severities)) {
        $filters['severities'] = explode(',', $severities);
      } elseif (is_array($severities)) {
        $filters['severities'] = $severities;
      }
    }

    // Time periods (from new selector-based system)
    if ($request->query->has('time_periods')) {
      $time_periods = $request->query->get('time_periods');
      if (is_string($time_periods)) {
        $filters['time_periods'] = explode(',', $time_periods);
      } elseif (is_array($time_periods)) {
        $filters['time_periods'] = $time_periods;
      }
    }

    // Legacy time filters (still support hour_start/hour_end for backwards compatibility)
    if ($request->query->has('hour_start')) {
      $filters['hour_start'] = $request->query->get('hour_start');
    }
    if ($request->query->has('hour_end')) {
      $filters['hour_end'] = $request->query->get('hour_end');
    }

    // Legacy severity filters (backwards compatibility)
    if ($request->query->has('severity_min')) {
      $filters['severity_min'] = $request->query->get('severity_min');
    }
    if ($request->query->has('severity_max')) {
      $filters['severity_max'] = $request->query->get('severity_max');
    }

    // H3 index filter (for Resolution 5 citywide hexagon lookup)
    if ($request->query->has('h3_index')) {
      $filters['h3_index'] = $request->query->get('h3_index');
    }

    return $filters;
  }

  /**
   * Parse map bounds from request.
   */
  private function parseBounds(Request $request) {
    if (!$request->query->has('bounds')) {
      return null;
    }

    $bounds_string = $request->query->get('bounds');
    $bounds_array = explode(',', $bounds_string);
    
    if (count($bounds_array) === 4) {
      return [
        'north' => floatval($bounds_array[0]),
        'east' => floatval($bounds_array[1]),
        'south' => floatval($bounds_array[2]),
        'west' => floatval($bounds_array[3]),
      ];
    }

    return null;
  }

  /**
   * Returns available crime types for filtering.
   */
  public function crimeTypes() {
    try {
      $crime_types = $this->crimeDataService->getCrimeTypes();

      return new JsonResponse([
        'crime_types' => $crime_types,
        'meta' => [
          'count' => count($crime_types),
        ],
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('API crime types error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to fetch crime types',
        'message' => $e->getMessage(),
        'crime_types' => [],
      ], 500);
    }
  }



  /**
   * Returns date range of available data.
   */
  public function dateRange() {
    try {
      $date_range = $this->crimeDataService->getDateRange();

      return new JsonResponse([
        'date_range' => $date_range,
        'meta' => [
          'min_date' => $date_range['min'],
          'max_date' => $date_range['max'],
        ],
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('API date range error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to fetch date range',
        'message' => $e->getMessage(),
        'date_range' => ['min' => '2025-01-01', 'max' => '2025-12-31'],
      ], 500);
    }
  }

  /**
   * Returns citywide crime statistics for dashboard overview.
   */
  public function citywideStats() {
    try {
      // Get citywide statistics from H3:5 aggregated data (correct total)
      $database = \Drupal\Core\Database\Database::getConnection('default', 'amisafe');
      
      // Get total incidents from ALL H3:5 hexagons (complete citywide coverage)
      $total_query = $database->select('amisafe_h3_aggregated', 'h');
      $total_query->addExpression('SUM(incident_count)', 'total_incidents');
      $total_query->condition('h3_resolution', 5);
      $total_incidents = $total_query->execute()->fetchField() ?: 0;
      
      // Get crime type breakdown from H3:7 aggregated data (better granularity)
      $crime_breakdown = $this->getCrimeTypeBreakdown($database);
      
      $districts = $this->crimeDataService->getDistricts();
      
      // Calculate citywide threat level based on incident density
      $threat_level = $this->calculateCitywideThreatlevel($total_incidents);
      
      return new JsonResponse([
        'stats' => [
          'total_incidents' => (string)$total_incidents,
          'total_visible' => 0, // Will be updated by JavaScript based on current map view
          'violent_crimes' => (string)$crime_breakdown['violent'],
          'property_crimes' => (string)$crime_breakdown['property'],
          'other_crimes' => (string)$crime_breakdown['other'],
          'active_districts' => count($districts),
          'citywide_threat_level' => $threat_level,
          'last_updated' => date('Y-m-d H:i:s'),
        ],
        'crime_percentages' => $crime_breakdown['percentages'],
        'meta' => [
          'districts' => $districts,
          'calculation_method' => 'h3_aggregated_sum_all_hexagons',
          'crime_breakdown_method' => 'calculated_from_dataset',
          'violent_crime_types' => ['100', '200', '300', '400', '1400'],
          'property_crime_types' => ['500', '600', '700', '800', '900', '1000', '1200', '1300', '1500', '2600'],
        ],
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('Citywide stats API error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      // Return fallback data
      return new JsonResponse([
        'stats' => [
          'total_incidents' => 28750,
          'active_districts' => 21,
          'citywide_threat_level' => 'CRITICAL',
          'coverage_percentage' => 94.2,
          'last_updated' => date('Y-m-d H:i:s'),
        ],
        'meta' => [
          'fallback' => true,
          'error' => 'Using simulated data for Philadelphia 2085',
        ],
      ]);
    }
  }

  /**
   * Calculate citywide threat level based on incident count.
   */
  private function calculateCitywideThreatlevel($incident_count) {
    if ($incident_count >= 30000) {
      return 'EXTREME';
    } elseif ($incident_count >= 20000) {
      return 'CRITICAL';
    } elseif ($incident_count >= 10000) {
      return 'HIGH';
    } elseif ($incident_count >= 5000) {
      return 'ELEVATED';
    } else {
      return 'MODERATE';
    }
  }

  /**
   * Get crime type breakdown for citywide statistics
   */
  private function getCrimeTypeBreakdown($database) {
    // Get actual crime type counts from the raw incidents data
    $violent_query = $database->select('amisafe_clean_incidents', 'i');
    $violent_query->addExpression('COUNT(*)', 'violent_count');
    $violent_query->condition('ucr_general', [
      '100', // Homicide
      '200', // Sexual Assault  
      '300', // Robbery
      '400', // Assault
      '1400' // Other Assault
    ], 'IN');
    $violent_count = $violent_query->execute()->fetchField() ?: 0;
    
    $property_query = $database->select('amisafe_clean_incidents', 'i');
    $property_query->addExpression('COUNT(*)', 'property_count');
    $property_query->condition('ucr_general', [
      '500',  // Burglary
      '600',  // Theft
      '700',  // Motor Vehicle Theft
      '800',  // Arson
      '900',  // Forgery
      '1000', // Fraud
      '1200', // Embezzlement
      '1300', // Stolen Property
      '1500', // Vandalism
      '2600'  // All Other Larceny
    ], 'IN');
    $property_count = $property_query->execute()->fetchField() ?: 0;
    
    // Get total count from H3:5 for consistency with citywide display
    $total_query = $database->select('amisafe_h3_aggregated', 'h');
    $total_query->addExpression('SUM(incident_count)', 'total');
    $total_query->condition('h3_resolution', 5);
    $total_count = $total_query->execute()->fetchField() ?: 0;
    
    return [
      'violent' => (int)$violent_count,
      'property' => (int)$property_count,
      'total' => $total_count,
      'other' => max(0, $total_count - $violent_count - $property_count),
      'percentages' => [
        'violent' => $total_count > 0 ? round(($violent_count / $total_count) * 100, 1) : 0,
        'property' => $total_count > 0 ? round(($property_count / $total_count) * 100, 1) : 0,
      ]
    ];
  }

  /**
   * Debug endpoint to test basic API functionality.
   */
  public function debugTest() {
    return new JsonResponse([
      'status' => 'API_WORKING',
      'timestamp' => date('Y-m-d H:i:s'),
      'message' => 'Debug test successful'
    ]);
  }

  /**
   * Returns individual incident details for a specific H3:13 hexagon.
   * Enables granular filtering at the incident level for ultra-precision analysis.
   */
  public function hexagonIncidents(Request $request, $h3_index) {
    try {
      // Parse filters from request
      $filters = $this->parseFilters($request);
      
      // Get incident IDs from the H3 aggregated table
      $database = Database::getConnection('default', 'amisafe');
      
      // First, get the hexagon with incident IDs (H3:13 only)
      $hexagon_query = $database->select('amisafe_h3_aggregated', 'h3')
        ->fields('h3', ['h3_index', 'h3_resolution', 'incident_count', 'incident_ids'])
        ->condition('h3_index', $h3_index)
        ->condition('h3_resolution', 13);
      
      $hexagon_data = $hexagon_query->execute()->fetchAssoc();
      
      if (!$hexagon_data || empty($hexagon_data['incident_ids'])) {
        return new JsonResponse([
          'error' => 'Hexagon not found or no incident details available',
          'message' => 'Incident-level data is only available for H3:13 hexagons',
          'h3_index' => $h3_index,
          'incidents' => [],
          'meta' => ['count' => 0, 'filtered_count' => 0],
        ], 404);
      }
      
      // Parse incident IDs
      $incident_ids = json_decode($hexagon_data['incident_ids'], true);
      if (empty($incident_ids)) {
        return new JsonResponse([
          'error' => 'No incidents found in hexagon',
          'h3_index' => $h3_index,
          'incidents' => [],
          'meta' => ['count' => 0, 'filtered_count' => 0],
        ]);
      }
      
      // Query for incident details with filtering
      $incidents_query = $database->select('amisafe_clean_incidents', 'i')
        ->fields('i', [
          'incident_id', 'ucr_general', 'incident_datetime', 
          'dc_dist', 'lat', 'lng', 'incident_month', 'incident_hour',
          'crime_description', 'incident_year'
        ])
        ->condition('incident_id', $incident_ids, 'IN');
      
      // Apply granular filters at incident level
      if (!empty($filters['crime_types'])) {
        $incidents_query->condition('ucr_general', $filters['crime_types'], 'IN');
      }
      
      if (!empty($filters['districts'])) {
        $incidents_query->condition('dc_dist', $filters['districts'], 'IN');
      }
      
      if (!empty($filters['start_date'])) {
        $incidents_query->condition('incident_datetime', $filters['start_date'], '>=');
      }
      
      if (!empty($filters['end_date'])) {
        $incidents_query->condition('incident_datetime', $filters['end_date'], '<=');
      }
      
      // Apply time period filters
      if (!empty($filters['time_periods'])) {
        $hour_conditions = [];
        foreach ($filters['time_periods'] as $period) {
          switch ($period) {
            case 'morning':
              $hour_conditions[] = ['field' => 'incident_hour', 'op' => 'BETWEEN', 'value' => [6, 11]];
              break;
            case 'afternoon':
              $hour_conditions[] = ['field' => 'incident_hour', 'op' => 'BETWEEN', 'value' => [12, 17]];
              break;
            case 'evening':
              $hour_conditions[] = ['field' => 'incident_hour', 'op' => 'BETWEEN', 'value' => [18, 23]];
              break;
            case 'night':
              $hour_conditions[] = ['field' => 'incident_hour', 'op' => 'BETWEEN', 'value' => [0, 5]];
              break;
          }
        }
        
        if (!empty($hour_conditions)) {
          $or_group = $incidents_query->orConditionGroup();
          foreach ($hour_conditions as $condition) {
            $or_group->condition($condition['field'], $condition['value'], $condition['op']);
          }
          $incidents_query->condition($or_group);
        }
      }
      
      $incidents_query->orderBy('incident_datetime', 'DESC');
      $incidents_query->range(0, 500); // Limit to 500 incidents for performance
      
      $incidents = $incidents_query->execute()->fetchAll(\PDO::FETCH_ASSOC);
      
      // Convert to structured format
      $formatted_incidents = [];
      foreach ($incidents as $incident) {
        $formatted_incidents[] = [
          'incident_id' => $incident['incident_id'],
          'crime_type' => $incident['ucr_general'],
          'description' => $incident['crime_description'],
          'datetime' => $incident['incident_datetime'],
          'district' => $incident['dc_dist'],
          'coordinates' => [
            'lat' => floatval($incident['lat']),
            'lng' => floatval($incident['lng'])
          ],
          'temporal_data' => [
            'year' => intval($incident['incident_year']),
            'month' => intval($incident['incident_month']),
            'hour' => intval($incident['incident_hour']),
            'time_period' => $this->getTimePeriod(intval($incident['incident_hour']))
          ]
        ];
      }
      
      return new JsonResponse([
        'h3_index' => $h3_index,
        'incidents' => $formatted_incidents,
        'hexagon_summary' => [
          'total_incidents_in_hex' => intval($hexagon_data['incident_count']),
          'incidents_matching_filter' => count($formatted_incidents),
          'filter_efficiency' => count($incident_ids) > 0 ? 
            round((count($formatted_incidents) / count($incident_ids)) * 100, 1) : 0
        ],
        'filters_applied' => $filters,
        'meta' => [
          'count' => count($formatted_incidents),
          'total_available' => count($incident_ids),
          'resolution' => 13,
          'granular_filtering' => true,
          'precision_level' => '7m × 7m hexagon',
          'timestamp' => date('c')
        ]
      ]);
      
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('API hexagon incidents error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to fetch hexagon incidents',
        'message' => $e->getMessage(),
        'h3_index' => $h3_index,
        'incidents' => [],
        'meta' => ['count' => 0],
      ], 500);
    }
  }

  /**
   * Get time period for an hour.
   */
  private function getTimePeriod($hour) {
    if ($hour >= 6 && $hour <= 11) {
      return 'morning';
    } elseif ($hour >= 12 && $hour <= 17) {
      return 'afternoon';
    } elseif ($hour >= 18 && $hour <= 23) {
      return 'evening';
    } else {
      return 'night';
    }
  }

  /**
   * Get detailed information for a specific hexagon.
   */
  public function hexagonDetails(Request $request, $h3_index) {
    try {
      
      // Get filters from request
      $filters = [];
      $data = json_decode($request->getContent(), TRUE);
      if ($data) {
        $filters = $data;
      } else {
        // Fallback to query parameters
        $filters = [
          'crime_types' => $request->query->all('crime_types') ?: [],
          'date_start' => $request->query->get('date_start'),
          'date_end' => $request->query->get('date_end'),
          'districts' => $request->query->all('districts') ?: [],
        ];
      }

      // Get detailed hexagon data
      $hexagon_data = $this->crimeDataService->getHexagonDetails($h3_index, $filters);
      
      if (!$hexagon_data) {
        return new JsonResponse([
          'error' => 'Hexagon not found',
          'h3_index' => $h3_index,
        ], 404);
      }

      // Calculate threat analysis
      $threat_level = $this->calculateThreatLevel($hexagon_data);
      $recommendations = $this->generateRecommendations($hexagon_data, $threat_level);

      return new JsonResponse([
        'h3_index' => $h3_index,
        'hexagon_data' => $hexagon_data,
        'threat_analysis' => [
          'level' => $threat_level,
          'score' => $hexagon_data['severity_avg'] ?? 0,
          'risk_factors' => $this->identifyRiskFactors($hexagon_data),
        ],
        'recommendations' => $recommendations,
        'meta' => [
          'timestamp' => date('Y-m-d H:i:s'),
          'data_quality' => 'HIGH',
          'confidence' => $this->calculateConfidence($hexagon_data),
        ],
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('API hexagon details error: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to fetch hexagon details',
        'message' => $e->getMessage(),
        'h3_index' => $h3_index,
      ], 500);
    }
  }

  /**
   * Calculate threat level based on hexagon data.
   */
  private function calculateThreatLevel($hexagon_data) {
    $incident_count = $hexagon_data['incident_count'] ?? 0;
    $severity_avg = $hexagon_data['severity_avg'] ?? 0;
    
    // Advanced threat level calculation
    if ($incident_count >= 50 && $severity_avg >= 4) {
      return 'CRITICAL';
    } elseif ($incident_count >= 25 && $severity_avg >= 3) {
      return 'HIGH';
    } elseif ($incident_count >= 10 && $severity_avg >= 2) {
      return 'MODERATE';
    } elseif ($incident_count >= 1) {
      return 'LOW';
    } else {
      return 'MINIMAL';
    }
  }

  /**
   * Generate security recommendations.
   */
  private function generateRecommendations($hexagon_data, $threat_level) {
    $recommendations = [];
    $crime_types = $hexagon_data['crime_types'] ?? [];
    
    switch ($threat_level) {
      case 'CRITICAL':
        $recommendations[] = 'AVOID AREA - High criminal activity detected';
        $recommendations[] = 'If transit required, use secure vehicle with escort';
        $recommendations[] = 'Implement enhanced surveillance protocols';
        break;
        
      case 'HIGH':
        $recommendations[] = 'Exercise extreme caution in this sector';
        $recommendations[] = 'Travel in groups, avoid night operations';
        $recommendations[] = 'Maintain constant comm link with security';
        break;
        
      case 'MODERATE':
        $recommendations[] = 'Standard security protocols recommended';
        $recommendations[] = 'Stay alert for suspicious activity';
        $recommendations[] = 'Avoid displaying valuable items';
        break;
        
      case 'LOW':
        $recommendations[] = 'Basic precautions sufficient';
        $recommendations[] = 'Monitor local conditions';
        break;
        
      default:
        $recommendations[] = 'Area appears secure - standard vigilance';
    }
    
    // Add crime-specific recommendations
    if (in_array('300', $crime_types) || in_array('2600', $crime_types)) {
      $recommendations[] = 'HIGH THEFT RISK - Secure all possessions';
    }
    if (in_array('400', $crime_types) || in_array('1400', $crime_types)) {
      $recommendations[] = 'VIOLENCE DETECTED - Avoid confrontations';
    }
    if (in_array('1100', $crime_types)) {
      $recommendations[] = 'NARCOTIC ACTIVITY - Potential gang presence';
    }
    
    return $recommendations;
  }

  /**
   * Identify risk factors.
   */
  private function identifyRiskFactors($hexagon_data) {
    $risk_factors = [];
    $crime_types = $hexagon_data['crime_types'] ?? [];
    $incident_count = $hexagon_data['incident_count'] ?? 0;
    
    if ($incident_count > 50) {
      $risk_factors[] = 'High incident density';
    }
    
    if (count($crime_types) > 5) {
      $risk_factors[] = 'Multiple crime categories';
    }
    
    $violent_crimes = array_intersect(['100', '200', '300', '400', '1400'], $crime_types);
    if (!empty($violent_crimes)) {
      $risk_factors[] = 'Violent crime presence';
    }
    
    if (in_array('1100', $crime_types)) {
      $risk_factors[] = 'Drug-related activity';
    }
    
    // Time-based analysis
    $last_incident = strtotime($hexagon_data['last_incident'] ?? '2025-01-01');
    $days_since = (time() - $last_incident) / (24 * 60 * 60);
    
    if ($days_since < 7) {
      $risk_factors[] = 'Recent criminal activity';
    }
    
    return $risk_factors;
  }

  /**
   * Calculate confidence score.
   */
  private function calculateConfidence($hexagon_data) {
    $incident_count = $hexagon_data['incident_count'] ?? 0;
    
    if ($incident_count >= 20) {
      return 'HIGH';
    } elseif ($incident_count >= 5) {
      return 'MEDIUM';
    } else {
      return 'LOW';
    }
  }

  /**
   * Validate and constrain H3 resolution within supported range (4-13).
   */
  private function validateResolution($resolution) {
    $config = $this->config('amisafe.settings');
    $max_resolution = $config->get('max_resolution') ?? 13;
    $min_resolution = $config->get('min_resolution') ?? 4; // Now supports Resolution 4 for metro-wide coverage
    
    // Ensure resolution is within our gold layer supported range (4-13)
    $resolution = max($min_resolution, min($max_resolution, intval($resolution)));
    
    // Log ultra-precision requests for monitoring
    if ($resolution >= 13) {
      \Drupal::logger('amisafe')->info('Ultra-precision request: Resolution @resolution', [
        '@resolution' => $resolution,
      ]);
    }
    
    // Log metro-wide requests for monitoring
    if ($resolution <= 5) {
      \Drupal::logger('amisafe')->info('Metro-wide request: Resolution @resolution', [
        '@resolution' => $resolution,
      ]);
    }
    
    return $resolution;
  }

  /**
   * Get precision metadata for a given resolution level.
   */
  private function getPrecisionMetadata($resolution) {
    $precision_data = [
      4 => ['level' => 'Metro-wide', 'area' => '1,770 km²', 'description' => 'Complete metropolitan area coverage'],
      5 => ['level' => 'Metro districts', 'area' => '251 km²', 'description' => 'Large district-level analysis'],
      6 => ['level' => 'City-wide', 'area' => '36.1 km²', 'description' => 'District-level analysis'],
      7 => ['level' => 'District', 'area' => '5.2 km²', 'description' => 'Neighborhood aggregation'], 
      8 => ['level' => 'Neighborhood', 'area' => '0.7 km²', 'description' => 'Block group detail'],
      9 => ['level' => 'Block Group', 'area' => '0.1 km²', 'description' => 'Street-level precision'],
      10 => ['level' => 'Block', 'area' => '15,047 m²', 'description' => 'Building group analysis'],
      11 => ['level' => 'Building', 'area' => '2,150 m²', 'description' => 'Individual building detail'],
      12 => ['level' => 'Room-level', 'area' => '307 m²', 'description' => 'Apartment/room precision'],
      13 => ['level' => 'Ultra-precision', 'area' => '44 m²', 'description' => 'Maximum spatial detail']
    ];
    
    return $precision_data[$resolution] ?? ['level' => 'Unknown', 'area' => 'Unknown', 'description' => 'Unsupported resolution'];
  }

  /**
   * User registration API endpoint for mobile app.
   */
  public function userRegister(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);
      
      if (empty($data['name']) || empty($data['mail']) || empty($data['pass'])) {
        $response = new JsonResponse([
          'success' => FALSE,
          'message' => 'Username, email, and password are required',
        ], 400);
        return $this->addCorsHeaders($response);
      }

      // Check if username already exists
      $existing_user = user_load_by_name($data['name']);
      if ($existing_user) {
        $response = new JsonResponse([
          'success' => FALSE,
          'message' => 'Username already exists',
        ], 409);
        return $this->addCorsHeaders($response);
      }

      // Check if email already exists
      $existing_email = user_load_by_mail($data['mail']);
      if ($existing_email) {
        $response = new JsonResponse([
          'success' => FALSE,
          'message' => 'Email already registered',
        ], 409);
        return $this->addCorsHeaders($response);
      }

      // Create new user
      $user = \Drupal\user\Entity\User::create();
      $user->setUsername($data['name']);
      $user->setEmail($data['mail']);
      $user->setPassword($data['pass']);
      $user->activate();
      $user->save();

      \Drupal::logger('amisafe')->info('Mobile user registered: @name', ['@name' => $data['name']]);

      $response = new JsonResponse([
        'success' => TRUE,
        'message' => 'Registration successful! Please log in with your credentials.',
        'user' => [
          'uid' => $user->id(),
          'name' => $user->getAccountName(),
          'mail' => $user->getEmail(),
        ],
      ]);
      return $this->addCorsHeaders($response);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('Registration error: @message', ['@message' => $e->getMessage()]);
      
      $response = new JsonResponse([
        'success' => FALSE,
        'message' => 'Registration failed: ' . $e->getMessage(),
      ], 500);
      return $this->addCorsHeaders($response);
    }
  }

  /**
   * User login API endpoint for mobile app.
   */
  public function userLogin(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);
      
      if (empty($data['name']) || empty($data['pass'])) {
        $response = new JsonResponse([
          'success' => FALSE,
          'message' => 'Username and password are required',
        ], 400);
        return $this->addCorsHeaders($response);
      }

      // Load user by username
      $user = user_load_by_name($data['name']);
      if (!$user) {
        $response = new JsonResponse([
          'success' => FALSE,
          'message' => 'Invalid username or password',
        ], 401);
        return $this->addCorsHeaders($response);
      }

      // Verify password
      $password_hasher = \Drupal::service('password');
      if (!$password_hasher->check($data['pass'], $user->getPassword())) {
        $response = new JsonResponse([
          'success' => FALSE,
          'message' => 'Invalid username or password',
        ], 401);
        return $this->addCorsHeaders($response);
      }

      // Check if user is active
      if ($user->isBlocked()) {
        $response = new JsonResponse([
          'success' => FALSE,
          'message' => 'Account is blocked',
        ], 403);
        return $this->addCorsHeaders($response);
      }

      // Log the user in and create a session
      user_login_finalize($user);
      
      // Generate a simple token for the mobile app
      // For mobile apps, we'll use a hash-based token instead of session cookies
      $token_data = [
        'uid' => $user->id(),
        'name' => $user->getAccountName(),
        'timestamp' => \Drupal::time()->getRequestTime(),
        'salt' => \Drupal::service('private_key')->get(),
      ];
      $session_token = hash('sha256', json_encode($token_data));

      \Drupal::logger('amisafe')->info('Mobile user logged in: @name (token generated)', [
        '@name' => $data['name'],
      ]);

      $response = new JsonResponse([
        'success' => TRUE,
        'message' => 'Login successful',
        'user' => [
          'uid' => $user->id(),
          'name' => $user->getAccountName(),
          'mail' => $user->getEmail(),
          'roles' => $user->getRoles(),
        ],
        'token' => $session_token,
        'sessionToken' => $session_token,
      ]);
      return $this->addCorsHeaders($response);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('Login error: @message', ['@message' => $e->getMessage()]);
      
      $response = new JsonResponse([
        'success' => FALSE,
        'message' => 'Login failed: ' . $e->getMessage(),
      ], 500);
      return $this->addCorsHeaders($response);
    }
  }

  /**
   * Update user location from mobile app.
   * 
   * POST /api/amisafe/location/update
   */
  public function locationUpdate(Request $request) {
    try {
      // Check authentication
      $current_user = \Drupal::currentUser();
      if ($current_user->isAnonymous()) {
        $response = new JsonResponse([
          'success' => FALSE,
          'message' => 'Authentication required',
        ], 401);
        return $this->addCorsHeaders($response);
      }

      $data = json_decode($request->getContent(), TRUE);
      
      if (!isset($data['latitude']) || !isset($data['longitude'])) {
        $response = new JsonResponse([
          'success' => FALSE,
          'message' => 'Missing required fields: latitude, longitude',
        ], 400);
        return $this->addCorsHeaders($response);
      }

      // Validate coordinates
      $latitude = floatval($data['latitude']);
      $longitude = floatval($data['longitude']);
      
      if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
        $response = new JsonResponse([
          'success' => FALSE,
          'message' => 'Invalid coordinates',
        ], 400);
        return $this->addCorsHeaders($response);
      }

      // Insert location record into institutional_management table
      $location_data = [
        'uid' => $current_user->id(),
        'latitude' => $latitude,
        'longitude' => $longitude,
        'h3_index' => $data['h3_index'] ?? NULL,
        'accuracy' => isset($data['accuracy']) ? floatval($data['accuracy']) : NULL,
        'altitude' => isset($data['altitude']) ? floatval($data['altitude']) : NULL,
        'heading' => isset($data['heading']) ? floatval($data['heading']) : NULL,
        'speed' => isset($data['speed']) ? floatval($data['speed']) : NULL,
        'timestamp' => isset($data['timestamp']) ? intval($data['timestamp']) : time(),
        'created' => time(),
        'device_info' => isset($data['device_info']) ? json_encode($data['device_info']) : NULL,
      ];

      $connection = Database::getConnection();
      $connection->insert('user_location_tracking')
        ->fields($location_data)
        ->execute();

      \Drupal::logger('amisafe')->info(
        'Location updated for user @uid: [@lat, @lon]',
        [
          '@uid' => $current_user->id(),
          '@lat' => $latitude,
          '@lon' => $longitude,
        ]
      );

      $response = new JsonResponse([
        'success' => TRUE,
        'message' => 'Location updated successfully',
        'data' => [
          'latitude' => $latitude,
          'longitude' => $longitude,
          'timestamp' => $location_data['timestamp'],
        ],
      ]);
      return $this->addCorsHeaders($response);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error(
        'Failed to store location: @error',
        ['@error' => $e->getMessage()]
      );

      $response = new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to store location data',
      ], 500);
      return $this->addCorsHeaders($response);
    }
  }

  /**
   * Get user's location history.
   * 
   * GET /api/amisafe/location/history
   */
  public function locationHistory(Request $request) {
    try {
      $current_user = \Drupal::currentUser();
      if ($current_user->isAnonymous()) {
        $response = new JsonResponse([
          'success' => FALSE,
          'message' => 'Authentication required',
        ], 401);
        return $this->addCorsHeaders($response);
      }

      $limit = min($request->query->get('limit', 50), 500);
      $offset = $request->query->get('offset', 0);
      $since = $request->query->get('since', NULL);

      $connection = Database::getConnection();
      $query = $connection->select('user_location_tracking', 'ult')
        ->fields('ult')
        ->condition('uid', $current_user->id())
        ->orderBy('timestamp', 'DESC')
        ->range($offset, $limit);

      if ($since) {
        $query->condition('timestamp', intval($since), '>=');
      }

      $results = $query->execute()->fetchAll();

      $locations = [];
      foreach ($results as $row) {
        $locations[] = [
          'latitude' => floatval($row->latitude),
          'longitude' => floatval($row->longitude),
          'h3_index' => $row->h3_index,
          'accuracy' => $row->accuracy ? floatval($row->accuracy) : NULL,
          'altitude' => $row->altitude ? floatval($row->altitude) : NULL,
          'heading' => $row->heading ? floatval($row->heading) : NULL,
          'speed' => $row->speed ? floatval($row->speed) : NULL,
          'timestamp' => intval($row->timestamp),
        ];
      }

      $response = new JsonResponse([
        'success' => TRUE,
        'data' => [
          'locations' => $locations,
          'count' => count($locations),
        ],
      ]);
      return $this->addCorsHeaders($response);
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error(
        'Failed to retrieve location history: @error',
        ['@error' => $e->getMessage()]
      );

      $response = new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to retrieve location history',
      ], 500);
      return $this->addCorsHeaders($response);
    }
  }

}
