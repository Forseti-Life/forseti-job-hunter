<?php

namespace Drupal\amisafe\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactory;

/**
 * Service for accessing crime data from the amisafe database.
 */
class CrimeDataService {

  /**
   * Cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructor.
   */
  public function __construct(CacheBackendInterface $cache, LoggerChannelFactory $logger_factory) {
    $this->cache = $cache;
    $this->logger = $logger_factory->get('amisafe');
  }

  /**
   * Get the database connection (use AmISafe dedicated database).
   */
  protected function getDatabase() {
    try {
      return \Drupal\Core\Database\Database::getConnection('default', 'amisafe');
    } catch (\Exception $e) {
      $this->logger->error('Failed to connect to AmISafe database: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new \Exception('AmISafe database connection failed: ' . $e->getMessage());
    }
  }

  /**
   * Get pre-aggregated H3 hexagon data from Gold layer (final aggregations).
   * Resolution 4-13: From metro-wide coverage to ultra-precision analytics.
   */
  public function getH3Aggregations($resolution = 9, $filters = [], $page = 0, $limit = 1000) {
    // Validate resolution parameter (now supports Resolution 4-13)
    if (empty($resolution) || !is_numeric($resolution) || $resolution < 4 || $resolution > 13) {
      $resolution = 9; // Default fallback
    }
    
    $cache_key = 'amisafe:h3_aggregations:' . md5($resolution . serialize($filters) . $page . $limit);
    
    // Check cache first for performance
    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    try {
      // Use Gold layer (amisafe_h3_aggregated) with ultra-precision analytics
      $database = $this->getDatabase();
      $query = $database->select('amisafe_h3_aggregated', 'h3a')
        ->fields('h3a', [
          'id', 'h3_index', 'h3_resolution', 'incident_count', 'unique_incident_types',
          'earliest_incident', 'latest_incident', 'incidents_last_30_days', 'incidents_last_year',
          'center_latitude', 'center_longitude',
          // Optimized: Use analytics columns instead of JSON decoding
          'top_crime_type', 'top_crime_type_12mo', 'top_crime_type_6mo',
          'violent_crime_count', 'nonviolent_crime_count',
          'risk_category', 'risk_category_12mo', 'risk_category_6mo',
          'crime_diversity_index', 'violent_crime_percentile',
          // CRITICAL: Z-scores for proper heat map visualization
          'incident_z_score', 'violent_crime_z_score', 'nonviolent_crime_z_score',
          'incident_z_score_12mo', 'violent_crime_z_score_12mo', 'nonviolent_crime_z_score_12mo',
          'incident_z_score_6mo', 'violent_crime_z_score_6mo', 'nonviolent_crime_z_score_6mo',
          // Risk scores and hotspot status
          'risk_score', 'risk_score_12mo', 'risk_score_6mo',
          'hotspot_status', 'hotspot_status_12mo', 'hotspot_status_6mo',
          // Statistical context
          'incident_mean', 'incident_std_dev', 'incident_percentile',
          'violent_crime_mean', 'violent_crime_std_dev',
          'violent_crime_percentile_12mo', 'violent_crime_percentile_6mo',
          // Windowed counts
          'incident_count_12mo', 'incident_count_6mo',
          'violent_crime_count_12mo', 'violent_crime_count_6mo',
          'nonviolent_crime_count_12mo', 'nonviolent_crime_count_6mo',
          'crime_diversity_index_12mo', 'crime_diversity_index_6mo',
          // Keep JSON for detailed breakdowns (only when needed)
          'incident_type_counts', 'district_counts',
          'data_quality_avg', 'total_valid_records', 'last_aggregation'
        ]);

      // Apply H3 filters first
      $this->applyH3Filters($query, $filters);
      
      // Only apply resolution filter if no specific h3_index is requested
      if (empty($filters['h3_index'])) {
        $query->condition('h3_resolution', $resolution);
      }
      
      $query->range($page * $limit, $limit)
        ->orderBy('incident_count', 'DESC');

      // Debug: Log the actual SQL query being executed
      $this->logger->info('H3 Query SQL: @sql', ['@sql' => (string) $query]);
      
      $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
      
      // Debug: Log query results
      $this->logger->info('H3 Query Results: @count rows returned', ['@count' => count($results)]);
      
      // Process results for frontend consumption with precision metadata
      $processed_results = array_map(function($row) use ($resolution, $filters) {
        return $this->processH3Aggregation($row, $resolution, $filters);
      }, $results);
      
      // Cache for 30 minutes (longer cache for aggregated data)
      $this->cache->set($cache_key, $processed_results, time() + 1800);
      
      return $processed_results;
    } catch (\Exception $e) {
      $this->logger->error('Error fetching Gold layer H3 aggregations: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get filtered incident data from Silver layer (transform data).
   * Resolution 13 Ultra-Precision: Access to 3.4M+ H3-indexed records.
   */
  public function getIncidents($filters = [], $page = 0, $limit = 1000) {
    $cache_key = 'amisafe:incidents:' . md5(serialize($filters) . $page . $limit);
    
    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    try {
      // Use Silver layer (amisafe_clean_incidents) with full H3 indexing
      $database = $this->getDatabase();
      $query = $database->select('amisafe_clean_incidents', 'ci')
        ->fields('ci', [
          'id', 'incident_datetime', 'dc_dist', 'ucr_general', 'lat', 'lng',
          'h3_res_6', 'h3_res_7', 'h3_res_8', 'h3_res_9', 'h3_res_10',
          'h3_res_11', 'h3_res_12', 'h3_res_13', 'data_quality_score'
        ])
        ->range($page * $limit, $limit)
        ->orderBy('incident_datetime', 'DESC');

      $this->applyFilters($query, $filters);

      $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
      
      // Process results for frontend consumption with H3 data
      $processed_results = array_map([$this, 'processTransformIncident'], $results);
      
      // Cache for 10 minutes (longer cache for processed data)
      $this->cache->set($cache_key, $processed_results, time() + 600);
      
      return $processed_results;
    } catch (\Exception $e) {
      $this->logger->error('Error fetching Silver layer incidents: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get count of incidents matching filters.
   * For citywide stats, uses Resolution 5 single hexagon (1.48M incidents, 251 km²).
   * For filtered queries, uses Silver layer for precision.
   */
  public function getIncidentCount($filters = []) {
    $cache_key = 'amisafe:incident_count:' . md5(serialize($filters));
    
    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    try {
      $database = $this->getDatabase();
      
      // If no filters, use Resolution 5 citywide hexagon (most efficient)
      if (empty($filters)) {
        $query = $database->select('amisafe_h3_aggregated', 'h3a');
        $query->addField('h3a', 'incident_count');
        $query->condition('h3_resolution', 5);
        $query->condition('h3_index', '852a134bfffffff'); // Philadelphia citywide hexagon
        
        $result = $query->execute()->fetchField();
        
        if ($result) {
          $this->cache->set($cache_key, $result, time() + 3600); // Cache for 1 hour
          return $result;
        }
      }
      
      // For filtered queries, use Silver layer for accuracy
      $query = $database->select('amisafe_clean_incidents', 'ci');
      $query->addExpression('COUNT(*)', 'total_incidents');
      
      // Apply all filters using the same method as getIncidents()
      $this->applyFilters($query, $filters);

      $result = $query->execute()->fetchField();
      
      // Cache for 10 minutes
      $this->cache->set($cache_key, $result, time() + 600);
      
      return $result;
    } catch (\Exception $e) {
      $this->logger->error('Error counting incidents: @message', [
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }



  /**
   * Get police districts.
   */
  public function getDistricts() {
    $cache_key = 'amisafe:districts';
    
    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    try {
      $database = $this->getDatabase();
      $query = $database->select('amisafe_raw_incidents', 'ri')
        ->fields('ri', ['dc_dist'])
        ->groupBy('dc_dist')
        ->orderBy('dc_dist');

      $results = $query->execute()->fetchCol();
      
      $districts = array_filter($results, function($district) {
        return !empty($district) && is_numeric($district);
      });
      
      sort($districts, SORT_NUMERIC);
      
      // Cache for 1 hour
      $this->cache->set($cache_key, array_values($districts), time() + 3600);
      
      return array_values($districts);
    } catch (\Exception $e) {
      $this->logger->error('Error fetching districts: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      // Return fallback data
      return ['1', '2', '3', '5', '6', '7', '8', '9', '12', '14', '15', '16', '17', '18', '19', '22', '24', '25', '26', '35', '39'];
    }
  }

  /**
   * Get date range of available data.
   */
  public function getDateRange() {
    try {
      $database = $this->getDatabase();
      $query = $database->select('amisafe_raw_incidents', 'ri');
      $query->addExpression('MIN(incident_date)', 'min_date');
      $query->addExpression('MAX(incident_date)', 'max_date');
      $result = $query->execute()->fetchAssoc();
      
      return [
        'min' => $result['min_date'],
        'max' => $result['max_date'],
      ];
    } catch (\Exception $e) {
      $this->logger->error('Error fetching date range: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [
        'min' => '2025-01-01',
        'max' => '2025-12-31',
      ];
    }
  }

  /**
   * Get available crime types for filtering.
   */
  public function getCrimeTypes() {
    try {
      $database = $this->getDatabase();
      $result = $database->query('SELECT DISTINCT ucr_code, ucr_description FROM amisafe_raw_incidents WHERE ucr_code IS NOT NULL AND ucr_description IS NOT NULL ORDER BY ucr_code');
      
      $crime_types = [];
      foreach ($result as $row) {
        $crime_types[$row->ucr_code] = $row->ucr_description;
      }
      
      return $crime_types;
    } catch (\Exception $e) {
      $this->logger->error('Error fetching crime types: @message', [
        '@message' => $e->getMessage(),
      ]);
      
      // Return common Philadelphia crime types as fallback
      return [
        '100' => 'Murder',
        '200' => 'Rape',
        '300' => 'Robbery - Total',
        '400' => 'Aggravated Assault - Total',
        '500' => 'Burglary - Total',
        '600' => 'Theft from Vehicle',
        '700' => 'All Other Larceny',
        '800' => 'Vandalism',
        '900' => 'Fraud',
        '1000' => 'Embezzlement',
        '1100' => 'Narcotic Drug Law Violations',
        '1200' => 'Weapons Violations',
        '1300' => 'Prostitution',
        '1400' => 'Other Assaults',
        '1500' => 'Arson',
        '1600' => 'Stolen Property',
        '1700' => 'DUI',
        '1800' => 'Liquor Laws',
        '2000' => 'Public Drunkenness',
        '2100' => 'Disorderly Conduct',
        '2600' => 'Theft from Person',
      ];
    }
  }

  /**
   * Get district boundaries (placeholder for future implementation).
   */
  public function getDistrictBoundaries() {
    // For now, return empty array - this would normally query a districts table
    return [];
  }

  /**
   * Apply filters to a query.
   */
  private function applyFilters($query, $filters) {
    // Date range filters
    if (!empty($filters['start_date'])) {
      $query->condition('incident_date', $filters['start_date'], '>=');
    }
    
    if (!empty($filters['end_date'])) {
      $query->condition('incident_date', $filters['end_date'], '<=');
    }
    
    // Crime type filters
    if (!empty($filters['crime_types'])) {
      $query->condition('ucr_general', $filters['crime_types'], 'IN');
    }
    
    // District filters
    if (!empty($filters['districts'])) {
      $query->condition('dc_dist', $filters['districts'], 'IN');
    }
    
    // Severity filters (new selector-based)
    if (!empty($filters['severities'])) {
      // Apply severity filtering based on UCR codes
      $severity_conditions = $query->orConditionGroup();
      foreach ($filters['severities'] as $severity_level) {
        $ucr_codes = $this->getUcrCodesBySeverity($severity_level);
        if (!empty($ucr_codes)) {
          $severity_conditions->condition('ucr_general', $ucr_codes, 'IN');
        }
      }
      if ($severity_conditions->count() > 0) {
        $query->condition($severity_conditions);
      }
    }
    
    // Time period filters (new selector-based)
    if (!empty($filters['time_periods'])) {
      $time_conditions = $query->orConditionGroup();
      foreach ($filters['time_periods'] as $time_period) {
        switch ($time_period) {
          case 'early-morning':
            $period_condition = $query->andConditionGroup();
            $period_condition->condition('incident_hour', 0, '>=');
            $period_condition->condition('incident_hour', 5, '<=');
            $time_conditions->condition($period_condition);
            break;
          case 'morning':
            $period_condition = $query->andConditionGroup();
            $period_condition->condition('incident_hour', 6, '>=');
            $period_condition->condition('incident_hour', 11, '<=');
            $time_conditions->condition($period_condition);
            break;
          case 'afternoon':
            $period_condition = $query->andConditionGroup();
            $period_condition->condition('incident_hour', 12, '>=');
            $period_condition->condition('incident_hour', 17, '<=');
            $time_conditions->condition($period_condition);
            break;
          case 'evening':
            $period_condition = $query->andConditionGroup();
            $period_condition->condition('incident_hour', 18, '>=');
            $period_condition->condition('incident_hour', 23, '<=');
            $time_conditions->condition($period_condition);
            break;
        }
      }
      if ($time_conditions->count() > 0) {
        $query->condition($time_conditions);
      }
    }
    
    // Legacy time filters (backwards compatibility)
    if (isset($filters['hour_start']) && isset($filters['hour_end'])) {
      $query->condition('incident_hour', $filters['hour_start'], '>=');
      $query->condition('incident_hour', $filters['hour_end'], '<=');
    }
    
    // Legacy severity filters (backwards compatibility)
    if (isset($filters['severity_min']) || isset($filters['severity_max'])) {
      if (isset($filters['severity_min'])) {
        $min_ucr_codes = $this->getUcrCodesBySeverity($filters['severity_min'], 'min');
        if (!empty($min_ucr_codes)) {
          $query->condition('ucr_general', $min_ucr_codes, 'IN');
        }
      }
      if (isset($filters['severity_max'])) {
        $max_ucr_codes = $this->getUcrCodesBySeverity($filters['severity_max'], 'max');
        if (!empty($max_ucr_codes)) {
          $query->condition('ucr_general', $max_ucr_codes, 'IN');
        }
      }
    }
    
    // Geographic bounds filter for incident queries
    if (!empty($filters['bounds'])) {
      $bounds = $filters['bounds'];
      // Latitude: south <= lat <= north
      $query->condition('lat', $bounds['south'], '>=');
      $query->condition('lat', $bounds['north'], '<=');
      // Longitude: west <= lng <= east
      $query->condition('lng', $bounds['west'], '>=');
      $query->condition('lng', $bounds['east'], '<=');
      
      $this->logger->info('Applied bounds filter to incidents: N:@north E:@east S:@south W:@west', [
        '@north' => $bounds['north'],
        '@east' => $bounds['east'], 
        '@south' => $bounds['south'],
        '@west' => $bounds['west']
      ]);
    }
  }

  /**
   * Process incident data for frontend consumption (legacy raw data).
   */
  private function processIncident($incident) {
    return [
      'id' => $incident['id'],
      'h3_index' => $incident['h3_index'],
      'lat' => floatval($incident['lat']),
      'lng' => floatval($incident['lng']),
      'crime_type' => $incident['ucr_general'],
      'description' => $incident['text_general_code'],
      'datetime' => $incident['dispatch_date_time'],
      'district' => $incident['dc_dist'],
      'block' => $incident['location_block'],
      'hour' => $incident['hour'],
      'severity' => $this->getCrimeSeverity($incident['ucr_general']),
    ];
  }

  /**
   * Process Silver layer (transform) incident data with Resolution 13 H3 support.
   * Provides all H3 indices from resolutions 6-13 for multi-scale analysis.
   */
  private function processTransformIncident($incident) {
    return [
      'id' => $incident['id'],
      'lat' => floatval($incident['lat']),
      'lng' => floatval($incident['lng']),
      'crime_type' => $incident['ucr_general'],
      'datetime' => $incident['incident_datetime'],
      'district' => $incident['dc_dist'],
      'data_quality_score' => floatval($incident['data_quality_score']),
      'severity' => $this->getCrimeSeverity($incident['ucr_general']),
      // Multi-resolution H3 indices (Resolutions 6-13)
      'h3_indices' => [
        'res_6' => $incident['h3_res_6'],    // City-wide (36.1 km²)
        'res_7' => $incident['h3_res_7'],    // District (5.2 km²)
        'res_8' => $incident['h3_res_8'],    // Block (737 m²)
        'res_9' => $incident['h3_res_9'],    // Street (105 m²)
        'res_10' => $incident['h3_res_10'],  // Building cluster (15K m²)
        'res_11' => $incident['h3_res_11'],  // Building (2.1K m²)
        'res_12' => $incident['h3_res_12'],  // Floor (307 m²)
        'res_13' => $incident['h3_res_13'],  // Ultra-fine room (44 m²)
      ],
    ];
  }

  /**
   * Process Gold layer (final) H3 aggregation data with ultra-precision metadata.
   * Provides comprehensive analytics for Resolution 13 hexagon data.
   * Adapts data based on date_range filter (12months, 6months, alltime).
   */
  private function processH3Aggregation($aggregation, $resolution, $filters = []) {
    // Lazy-load JSON fields only if needed (optimize performance)
    $incident_types = isset($aggregation['incident_type_counts']) ? json_decode($aggregation['incident_type_counts'], true) : [];
    $districts = isset($aggregation['district_counts']) ? json_decode($aggregation['district_counts'], true) : [];
    
    // Determine which data columns to use based on date_range filter
    $date_range = $filters['date_range'] ?? 'alltime';
    
    // Select appropriate columns based on date range
    switch ($date_range) {
      case '12months':
        $incident_count = intval($aggregation['incident_count_12mo'] ?? $aggregation['incident_count']);
        $violent_count = intval($aggregation['violent_crime_count_12mo'] ?? $aggregation['violent_crime_count']);
        $nonviolent_count = intval($aggregation['nonviolent_crime_count_12mo'] ?? $aggregation['nonviolent_crime_count']);
        $risk_level = $aggregation['risk_category_12mo'] ?? $aggregation['risk_category'] ?? 'UNKNOWN';
        $risk_score = floatval($aggregation['risk_score_12mo'] ?? $aggregation['risk_score'] ?? 0);
        $hotspot = $aggregation['hotspot_status_12mo'] ?? $aggregation['hotspot_status'];
        $z_incident = floatval($aggregation['incident_z_score_12mo'] ?? $aggregation['incident_z_score'] ?? 0);
        $z_violent = floatval($aggregation['violent_crime_z_score_12mo'] ?? $aggregation['violent_crime_z_score'] ?? 0);
        $z_nonviolent = floatval($aggregation['nonviolent_crime_z_score_12mo'] ?? $aggregation['nonviolent_crime_z_score'] ?? 0);
        $top_crime = $aggregation['top_crime_type_12mo'] ?? $aggregation['top_crime_type'];
        $diversity = floatval($aggregation['crime_diversity_index_12mo'] ?? $aggregation['crime_diversity_index'] ?? 0);
        $violent_percentile = intval($aggregation['violent_crime_percentile_12mo'] ?? $aggregation['violent_crime_percentile'] ?? 0);
        break;
        
      case '6months':
        $incident_count = intval($aggregation['incident_count_6mo'] ?? $aggregation['incident_count']);
        $violent_count = intval($aggregation['violent_crime_count_6mo'] ?? $aggregation['violent_crime_count']);
        $nonviolent_count = intval($aggregation['nonviolent_crime_count_6mo'] ?? $aggregation['nonviolent_crime_count']);
        $risk_level = $aggregation['risk_category_6mo'] ?? $aggregation['risk_category'] ?? 'UNKNOWN';
        $risk_score = floatval($aggregation['risk_score_6mo'] ?? $aggregation['risk_score'] ?? 0);
        $hotspot = $aggregation['hotspot_status_6mo'] ?? $aggregation['hotspot_status'];
        $z_incident = floatval($aggregation['incident_z_score_6mo'] ?? $aggregation['incident_z_score'] ?? 0);
        $z_violent = floatval($aggregation['violent_crime_z_score_6mo'] ?? $aggregation['violent_crime_z_score'] ?? 0);
        $z_nonviolent = floatval($aggregation['nonviolent_crime_z_score_6mo'] ?? $aggregation['nonviolent_crime_z_score'] ?? 0);
        $top_crime = $aggregation['top_crime_type_6mo'] ?? $aggregation['top_crime_type'];
        $diversity = floatval($aggregation['crime_diversity_index_6mo'] ?? $aggregation['crime_diversity_index'] ?? 0);
        $violent_percentile = intval($aggregation['violent_crime_percentile_6mo'] ?? $aggregation['violent_crime_percentile'] ?? 0);
        break;
        
      case 'alltime':
      default:
        $incident_count = intval($aggregation['incident_count']);
        $violent_count = intval($aggregation['violent_crime_count'] ?? 0);
        $nonviolent_count = intval($aggregation['nonviolent_crime_count'] ?? 0);
        $risk_level = $aggregation['risk_category'] ?? 'UNKNOWN';
        $risk_score = floatval($aggregation['risk_score'] ?? 0);
        $hotspot = $aggregation['hotspot_status'];
        $z_incident = floatval($aggregation['incident_z_score'] ?? 0);
        $z_violent = floatval($aggregation['violent_crime_z_score'] ?? 0);
        $z_nonviolent = floatval($aggregation['nonviolent_crime_z_score'] ?? 0);
        $top_crime = $aggregation['top_crime_type'];
        $diversity = floatval($aggregation['crime_diversity_index'] ?? 0);
        $violent_percentile = intval($aggregation['violent_crime_percentile'] ?? 0);
        break;
    }
    
    return [
      'id' => $aggregation['id'],
      'h3_index' => $aggregation['h3_index'],
      'resolution' => $aggregation['h3_resolution'],
      'incident_count' => $incident_count,
      'unique_types' => intval($aggregation['unique_incident_types']),
      'center' => [
        'lat' => floatval($aggregation['center_latitude']),
        'lng' => floatval($aggregation['center_longitude']),
      ],
      'temporal' => [
        'earliest' => $aggregation['earliest_incident'],
        'latest' => $aggregation['latest_incident'],
        'last_30_days' => intval($aggregation['incidents_last_30_days']),
        'last_year' => intval($aggregation['incidents_last_year']),
      ],
      'quality' => [
        'avg_score' => floatval($aggregation['data_quality_avg']),
        'valid_records' => intval($aggregation['total_valid_records']),
      ],
      'geography' => [
        'coverage_km2' => 0,
        'precision_level' => $this->getPrecisionLevel($resolution),
        'hex_size_m2' => $this->getHexagonSizeM2($resolution),
      ],
      'analytics' => [
        'crime_types' => $incident_types ?: [],
        'districts' => $districts ?: [],
        'density' => $this->calculateDensity($incident_count, $resolution),
        // Risk categories and scores (date-range specific)
        'risk_level' => $risk_level,
        'risk_score' => $risk_score,
        'hotspot_status' => $hotspot,
        // Z-scores (CRITICAL for heat map coloring - date-range specific)
        'z_scores' => [
          'incident' => $z_incident,
          'violent' => $z_violent,
          'nonviolent' => $z_nonviolent,
        ],
        // All time window data still available for comparison
        'risk_level_12mo' => $aggregation['risk_category_12mo'] ?? null,
        'risk_level_6mo' => $aggregation['risk_category_6mo'] ?? null,
        'risk_score_12mo' => floatval($aggregation['risk_score_12mo'] ?? 0),
        'risk_score_6mo' => floatval($aggregation['risk_score_6mo'] ?? 0),
        'hotspot_status_12mo' => $aggregation['hotspot_status_12mo'] ?? null,
        'hotspot_status_6mo' => $aggregation['hotspot_status_6mo'] ?? null,
        'z_scores_12mo' => [
          'incident' => floatval($aggregation['incident_z_score_12mo'] ?? 0),
          'violent' => floatval($aggregation['violent_crime_z_score_12mo'] ?? 0),
          'nonviolent' => floatval($aggregation['nonviolent_crime_z_score_12mo'] ?? 0),
        ],
        'z_scores_6mo' => [
          'incident' => floatval($aggregation['incident_z_score_6mo'] ?? 0),
          'violent' => floatval($aggregation['violent_crime_z_score_6mo'] ?? 0),
          'nonviolent' => floatval($aggregation['nonviolent_crime_z_score_6mo'] ?? 0),
        ],
        // Statistical context
        'statistics' => [
          'mean' => floatval($aggregation['incident_mean'] ?? 0),
          'std_dev' => floatval($aggregation['incident_std_dev'] ?? 0),
          'percentile' => intval($aggregation['incident_percentile'] ?? 0),
          'violent_mean' => floatval($aggregation['violent_crime_mean'] ?? 0),
          'violent_std_dev' => floatval($aggregation['violent_crime_std_dev'] ?? 0),
        ],
        // Crime type breakdown (date-range specific)
        'top_crime_type' => $top_crime,
        'top_crime_type_12mo' => $aggregation['top_crime_type_12mo'] ?? null,
        'top_crime_type_6mo' => $aggregation['top_crime_type_6mo'] ?? null,
        // Counts by category (date-range specific)
        'violent_count' => $violent_count,
        'violent_count_12mo' => intval($aggregation['violent_crime_count_12mo'] ?? 0),
        'violent_count_6mo' => intval($aggregation['violent_crime_count_6mo'] ?? 0),
        'nonviolent_count' => $nonviolent_count,
        'nonviolent_count_12mo' => intval($aggregation['nonviolent_crime_count_12mo'] ?? 0),
        'nonviolent_count_6mo' => intval($aggregation['nonviolent_crime_count_6mo'] ?? 0),
        // Windowed incident counts
        'incident_count_12mo' => intval($aggregation['incident_count_12mo'] ?? 0),
        'incident_count_6mo' => intval($aggregation['incident_count_6mo'] ?? 0),
        // Crime diversity (date-range specific)
        'crime_diversity' => $diversity,
        'crime_diversity_12mo' => floatval($aggregation['crime_diversity_index_12mo'] ?? 0),
        'crime_diversity_6mo' => floatval($aggregation['crime_diversity_index_6mo'] ?? 0),
        // Percentile rankings (date-range specific)
        'violent_percentile' => $violent_percentile,
        'violent_percentile_12mo' => intval($aggregation['violent_crime_percentile_12mo'] ?? 0),
        'violent_percentile_6mo' => intval($aggregation['violent_crime_percentile_6mo'] ?? 0),
      ],
      'metadata' => [
        'last_updated' => $aggregation['last_aggregation'],
        'source_records' => intval($aggregation['total_valid_records']),
        'aggregation_type' => 'gold_layer_ultra_precision',
        'date_range' => $date_range, // Include filter info in response
      ],
    ];
  }

  /**
   * Get crime severity score.
   */
  private function getCrimeSeverity($ucr_code) {
    $severity_map = [
      '100' => 5, // Murder
      '200' => 4, // Rape
      '300' => 4, // Robbery
      '400' => 4, // Aggravated Assault
      '500' => 3, // Burglary
      '600' => 2, // Theft from Vehicle
      '700' => 2, // All Other Larceny
      '800' => 2, // Vandalism
      '900' => 2, // Fraud
      '1000' => 2, // Embezzlement
      '1100' => 3, // Narcotic Drug Law Violations
      '1200' => 3, // Weapons Violations
      '1300' => 1, // Prostitution
      '1400' => 2, // Other Assaults
      '1500' => 4, // Arson
      '1600' => 2, // Stolen Property
      '1700' => 2, // DUI
      '1800' => 1, // Liquor Laws
      '2000' => 1, // Public Drunkenness
      '2100' => 1, // Disorderly Conduct
      '2600' => 3, // Theft from Person
    ];
    
    return $severity_map[$ucr_code] ?? 2;
  }

  /**
   * Get UCR codes by severity level.
   */
  private function getUcrCodesBySeverity($severity_level, $mode = 'exact') {
    $severity_to_codes = [
      1 => ['1300', '1800', '2000', '2100'], // Low severity
      2 => ['600', '700', '800', '900', '1000', '1400', '1600', '1700'], // Moderate severity
      3 => ['500', '1100', '1200', '2600'], // High severity
      4 => ['200', '300', '400', '1500'], // Critical severity
      5 => ['100'], // Extreme severity
    ];
    
    switch ($mode) {
      case 'min':
        // Get codes with severity >= specified level
        $codes = [];
        for ($level = $severity_level; $level <= 5; $level++) {
          if (isset($severity_to_codes[$level])) {
            $codes = array_merge($codes, $severity_to_codes[$level]);
          }
        }
        return $codes;
        
      case 'max':
        // Get codes with severity <= specified level
        $codes = [];
        for ($level = 1; $level <= $severity_level; $level++) {
          if (isset($severity_to_codes[$level])) {
            $codes = array_merge($codes, $severity_to_codes[$level]);
          }
        }
        return $codes;
        
      default:
        // Get codes with exact severity level
        return $severity_to_codes[$severity_level] ?? [];
    }
  }

  

  /**
   * Get crime color for visualization.
   */
  private function getCrimeColor($ucr_code) {
    $color_map = [
      '100' => '#ff0000', // Red - Homicide
      '200' => '#ff8800', // Orange - Robbery
      '300' => '#ffff00', // Yellow - Assault
      '400' => '#00ff00', // Green - Burglary
      '500' => '#00ffff', // Cyan - Theft
      '600' => '#0088ff', // Blue - Auto Theft
      '700' => '#888888', // Gray - Other
    ];
    
    $code_prefix = substr($ucr_code, 0, 1) . '00';
    return $color_map[$code_prefix] ?? '#888888';
  }

  /**
   * Get detailed information for a specific hexagon.
   */
  public function getHexagonDetails($h3_index, array $filters = []) {
    
    try {
      // Get database connection like other methods
      $database = $this->getDatabase();
      
      $query = $database->select('amisafe_raw_incidents', 'ri');
      $query->fields('ri');
      $query->condition('ri.h3_index', $h3_index);

      // Apply filters
      if (!empty($filters['crime_types'])) {
        $query->condition('ri.ucr_general', $filters['crime_types'], 'IN');
      }

      if (!empty($filters['start_date'])) {
        $query->condition('ri.dispatch_date', $filters['start_date'], '>=');
      }

      if (!empty($filters['end_date'])) {
        $query->condition('ri.dispatch_date', $filters['end_date'], '<=');
      }

      if (!empty($filters['districts'])) {
        $query->condition('ri.dc_dist', $filters['districts'], 'IN');
      }

      $results = $query->execute()->fetchAll();

      if (empty($results)) {
        return [
          'h3_index' => $h3_index,
          'incidents' => [],
          'summary' => [
            'total_incidents' => 0,
            'crime_types' => [],
            'severity_avg' => 0,
            'threat_level' => 'MINIMAL',
            'last_incident' => null,
          ]
        ];
      }

      // Process incidents
      $incidents = [];
      $crime_types = [];
      $severities = [];
      $latest_date = null;

      foreach ($results as $row) {
        $ucr_code = $row->ucr_general;
        $severity = $this->calculateSeverityScore($ucr_code);
        $severities[] = $severity;
        
        if (!isset($crime_types[$ucr_code])) {
          $crime_types[$ucr_code] = 0;
        }
        $crime_types[$ucr_code]++;

        if (!$latest_date || $row->dispatch_date > $latest_date) {
          $latest_date = $row->dispatch_date;
        }

        $incidents[] = [
          'incident_id' => $row->id,
          'date' => $row->dispatch_date,
          'time' => $row->dispatch_time ?? '00:00:00',
          'ucr_code' => $ucr_code,
          'crime_type' => $row->text_general_code ?? 'Unknown',
          'address' => $row->location_block ?? 'Address unavailable',
          'district' => $row->dc_dist ?? 'Unknown',
          'severity' => $severity,
          'lat' => $row->lat,
          'lng' => $row->lng,
        ];
      }

      $avg_severity = !empty($severities) ? round(array_sum($severities) / count($severities), 1) : 0;
      
      // Determine threat level
      $threat_level = 'MINIMAL';
      if ($avg_severity >= 4.5) {
        $threat_level = 'EXTREME';
      } elseif ($avg_severity >= 3.5) {
        $threat_level = 'HIGH';
      } elseif ($avg_severity >= 2.5) {
        $threat_level = 'MODERATE';
      } elseif ($avg_severity >= 1.5) {
        $threat_level = 'LOW';
      }

      return [
        'h3_index' => $h3_index,
        'incidents' => $incidents,
        'summary' => [
          'total_incidents' => count($incidents),
          'crime_types' => $crime_types,
          'severity_avg' => $avg_severity,
          'threat_level' => $threat_level,
          'last_incident' => $latest_date,
        ]
      ];

    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('Error getting hexagon details: @message', ['@message' => $e->getMessage()]);
      return [
        'error' => 'Failed to fetch hexagon details',
        'h3_index' => $h3_index,
        'incidents' => [],
        'summary' => [
          'total_incidents' => 0,
          'crime_types' => [],
          'severity_avg' => 0,
          'threat_level' => 'UNKNOWN',
          'last_incident' => null,
        ]
      ];
    }
  }

  /**
   * Calculate severity score for a UCR code.
   */
  private function calculateSeverityScore($ucr_code) {
    $severity_map = [
      '100' => 5, // Murder
      '200' => 4, // Rape  
      '300' => 4, // Robbery
      '400' => 3, // Aggravated Assault
      '500' => 2, // Burglary
      '600' => 3, // Theft from Vehicle
      '700' => 1, // All Other Larceny
      '800' => 2, // Vandalism
      '900' => 2, // Fraud
      '1000' => 2, // Embezzlement
      '1100' => 3, // Drug Violations
      '1200' => 4, // Weapons
      '1300' => 2, // Prostitution
      '1400' => 3, // Other Assaults
      '1500' => 4, // Arson
      '1600' => 2, // Stolen Property
      '1700' => 2, // DUI
      '1800' => 1, // Liquor Laws
      '2000' => 1, // Public Drunkenness
      '2100' => 1, // Disorderly Conduct
      '2600' => 3, // Theft from Person
    ];
    
    return $severity_map[$ucr_code] ?? 2;
  }

  /**
   * Apply filters to H3 aggregation queries.
   * Now supports h3_index filtering for Resolution 5 citywide hexagon lookup.
   * Supports date_range presets (12months, 6months, alltime) using pre-calculated columns.
   */
  private function applyH3Filters($query, $filters) {
    // Filter by specific H3 index (for Resolution 5 citywide hexagon)
    if (!empty($filters['h3_index'])) {
      $query->condition('h3_index', $filters['h3_index']);
    }

    // Log filters for debugging
    \Drupal::logger('amisafe')->info('H3 Filters received: @filters', ['@filters' => print_r($filters, TRUE)]);

    // Date range preset filtering - uses pre-calculated database columns
    // Note: The columns are used to filter which hexagons to show based on their activity
    // The actual data selection happens in processH3Aggregation based on date_range
    if (!empty($filters['date_range'])) {
      switch ($filters['date_range']) {
        case '12months':
          // Show hexagons with activity in last 12 months
          $query->condition('incident_count_12mo', 0, '>');
          break;
        case '6months':
          // Show hexagons with activity in last 6 months
          $query->condition('incident_count_6mo', 0, '>');
          break;
        case 'alltime':
          // Show all hexagons (no additional filter needed)
          break;
      }
      \Drupal::logger('amisafe')->info('Applied date_range filter: @range', ['@range' => $filters['date_range']]);
    }

    // Apply incident count filters
    if (!empty($filters['min_incidents'])) {
      $query->condition('incident_count', $filters['min_incidents'], '>=');
    }

    if (!empty($filters['max_incidents'])) {
      $query->condition('incident_count', $filters['max_incidents'], '<=');
    }

    // Handle districts filter - using simple LIKE approach for JSON fields
    if (!empty($filters['districts'])) {
      $districts = is_array($filters['districts']) ? $filters['districts'] : [$filters['districts']];
      $district_conditions = $query->orConditionGroup();
      foreach ($districts as $district) {
        // Use LIKE to search for district in JSON - simpler than JSON_SEARCH
        $district_conditions->condition('district_counts', '%"' . $district . '"%', 'LIKE');
      }
      $query->condition($district_conditions);
      \Drupal::logger('amisafe')->info('Applied districts filter: @districts', ['@districts' => implode(',', $districts)]);
    }

    // Handle crime_types filter - using simple LIKE approach for JSON fields
    if (!empty($filters['crime_types'])) {
      $crime_types = is_array($filters['crime_types']) ? $filters['crime_types'] : [$filters['crime_types']];
      $crime_conditions = $query->orConditionGroup();
      foreach ($crime_types as $crime_type) {
        // Use LIKE to search for crime type in JSON - simpler than JSON_SEARCH
        $crime_conditions->condition('incident_type_counts', '%"' . $crime_type . '"%', 'LIKE');
      }
      $query->condition($crime_conditions);
      \Drupal::logger('amisafe')->info('Applied crime_types filter: @types', ['@types' => implode(',', $crime_types)]);
    }

    // Handle bounds filter - filter hexagons by geographic bounds
    if (!empty($filters['bounds'])) {
      $bounds = $filters['bounds'];
      // Latitude: south <= center_latitude <= north
      $query->condition('center_latitude', $bounds['south'], '>=');
      $query->condition('center_latitude', $bounds['north'], '<=');
      // Longitude: west <= center_longitude <= east
      $query->condition('center_longitude', $bounds['west'], '>=');
      $query->condition('center_longitude', $bounds['east'], '<=');
      \Drupal::logger('amisafe')->info('Applied bounds filter: N:@north E:@east S:@south W:@west', [
        '@north' => $bounds['north'],
        '@east' => $bounds['east'], 
        '@south' => $bounds['south'],
        '@west' => $bounds['west']
      ]);
    } else {
      \Drupal::logger('amisafe')->info('NO bounds filter provided - returning all hexagons for resolution');
    }
  }

  /**
   * Get precision level description for resolution.
   */
  private function getPrecisionLevel($resolution) {
    $levels = [
      6 => 'Metropolitan',
      7 => 'District-wide', 
      8 => 'Block-level',
      9 => 'Street-level',
      10 => 'Property clusters',
      11 => 'Building-level',
      12 => 'Floor-level',
      13 => 'Ultra-fine room-level'
    ];
    return $levels[$resolution] ?? 'Unknown';
  }

  /**
   * Get hexagon size in square meters for resolution.
   */
  private function getHexagonSizeM2($resolution) {
    $sizes = [
      6 => 36129000,  // 36.1 km²
      7 => 5161000,   // 5.2 km²
      8 => 737000,    // 737 m²
      9 => 105000,    // 105 m²
      10 => 15048,    // 15K m²
      11 => 2150,     // 2.1K m²
      12 => 307,      // 307 m²
      13 => 44        // 44 m² (Ultra-fine)
    ];
    return $sizes[$resolution] ?? 0;
  }

  /**
   * Calculate incident density per square meter.
   */
  private function calculateDensity($incident_count, $resolution) {
    $hex_size = $this->getHexagonSizeM2($resolution);
    return $hex_size > 0 ? round($incident_count / $hex_size * 10000, 6) : 0; // Per 10K m²
  }

  /**
   * Calculate risk level based on incident count and resolution.
   */
  private function calculateRiskLevel($incident_count, $resolution) {
    // Adjusted thresholds based on hexagon size
    $thresholds = [
      6 => [1000, 5000, 10000],   // City-wide
      7 => [500, 2000, 5000],     // District
      8 => [100, 500, 1000],      // Block
      9 => [50, 200, 500],        // Street
      10 => [20, 100, 300],       // Property
      11 => [10, 50, 150],        // Building
      12 => [5, 25, 75],          // Floor
      13 => [2, 10, 30]           // Ultra-fine
    ];

    $levels = $thresholds[$resolution] ?? [10, 50, 150];
    
    if ($incident_count >= $levels[2]) return 'EXTREME';
    if ($incident_count >= $levels[1]) return 'HIGH';
    if ($incident_count >= $levels[0]) return 'MODERATE';
    if ($incident_count > 0) return 'LOW';
    return 'MINIMAL';
  }

}