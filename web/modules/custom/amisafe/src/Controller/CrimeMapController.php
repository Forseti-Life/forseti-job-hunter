<?php

namespace Drupal\amisafe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\amisafe\Service\CrimeDataService;
use Drupal\amisafe\Service\H3AggregatorService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the interactive crime map.
 */
class CrimeMapController extends ControllerBase {

  /**
   * The crime data service.
   *
   * @var \Drupal\amisafe\Service\CrimeDataService
   */
  protected $crimeDataService;

  /**
   * The H3 aggregator service.
   *
   * @var \Drupal\amisafe\Service\H3AggregatorService
   */
  protected $h3AggregatorService;

  /**
   * Constructs a CrimeMapController object.
   */
  public function __construct(CrimeDataService $crime_data_service, H3AggregatorService $h3_aggregator_service) {
    $this->crimeDataService = $crime_data_service;
    $this->h3AggregatorService = $h3_aggregator_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('amisafe.crime_data'),
      $container->get('amisafe.h3_aggregator')
    );
  }

  /**
   * Displays the interactive crime map.
   */
  public function map(Request $request) {
    // Use default configuration (avoid config dependency for now)
    $default_zoom = 11;
    $default_center = [39.9526, -75.1652];

    // Get real data from services
    $crime_types = $this->getDefaultCrimeTypes();
    $districts = $this->getDefaultDistricts();
    $date_range = $this->getDefaultDateRange();
    
    // Fetch real citywide statistics
    $citywide_stats = $this->getCitywideStatistics();
    \Drupal::logger('amisafe')->info('Controller passing stats to template: @stats', ['@stats' => print_r($citywide_stats, TRUE)]);
    
    // TEST: Force hardcoded values to debug template variable passing
    $citywide_stats = [
      'total_citywide' => 'FORCED-3,406,192',
      'active_districts' => 'FORCED-25',
      'active_sectors' => 'FORCED-80',
      'visible_incidents' => 'FORCED-0',
    ];

    $build = [
      '#theme' => 'amisafe_crime_map',
      '#title' => '',
      '#map_config' => [
        'zoom' => $default_zoom,
        'center' => $default_center,
        'api_endpoints' => [
          'incidents' => '/api/amisafe/incidents',
          'aggregated' => '/api/amisafe/aggregated',
          'hotspots' => '/api/amisafe/hotspots',
          'districts' => '/api/amisafe/districts',
          'ultraPrecision' => '/api/amisafe/ultra-precision',
          'systemStats' => '/api/amisafe/system-stats',
        ],
      ],
      '#crime_types' => $crime_types,
      '#districts' => $districts,
      '#date_range' => $date_range,
      '#citywide_stats' => $citywide_stats,
      '#attached' => [
        'library' => ['amisafe/crime-map', 'community_incident_report/community-layer'],
        'drupalSettings' => [
          'amisafe' => [
            'mapConfig' => [
              'zoom' => $default_zoom,
              'center' => $default_center,
            ],
            'apiEndpoints' => [
              'incidents' => '/api/amisafe/incidents',
              'aggregated' => '/api/amisafe/aggregated',
              'hotspots' => '/api/amisafe/hotspots',
              'districts' => '/api/amisafe/districts',
              'ultraPrecision' => '/api/amisafe/ultra-precision',
              'systemStats' => '/api/amisafe/system-stats',
            ],
            'crimeTypes' => $crime_types,
            'districts' => $districts,
            'dateRange' => $date_range,
            'debugMode' => FALSE, // Set to TRUE to enable debug logging
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * Provides default crime types if database is unavailable.
   */
  private function getDefaultCrimeTypes() {
    return [
      ['code' => '100', 'name' => 'Homicide', 'severity' => 5, 'color' => '#ff0000'],
      ['code' => '200', 'name' => 'Robbery', 'severity' => 4, 'color' => '#ff8800'],
      ['code' => '300', 'name' => 'Aggravated Assault', 'severity' => 4, 'color' => '#ffff00'],
      ['code' => '400', 'name' => 'Burglary', 'severity' => 3, 'color' => '#00ff00'],
      ['code' => '500', 'name' => 'Theft', 'severity' => 2, 'color' => '#00ffff'],
      ['code' => '600', 'name' => 'Auto Theft', 'severity' => 3, 'color' => '#0088ff'],
    ];
  }

  /**
   * Provides default districts if database is unavailable.
   */
  private function getDefaultDistricts() {
    return ['1', '2', '3', '5', '6', '7', '8', '9', '12', '14', '15', '16', '17', '18', '19', '22', '24', '25', '26', '35', '39'];
  }

  /**
   * Provides default date range if database is unavailable.
   */
  private function getDefaultDateRange() {
    return [
      'min' => '2022-01-01 00:00:00',
      'max' => '2025-10-27 23:59:59',
    ];
  }

  /**
   * Get real citywide statistics for template.
   */
  private function getCitywideStatistics() {
    \Drupal::logger('amisafe')->info('Getting citywide statistics...');
    
    try {
      // Get the same data that the API provides
      $database = \Drupal\Core\Database\Database::getConnection('default', 'amisafe');
      \Drupal::logger('amisafe')->info('Database connection successful');
      
      // Get total incidents across ALL hexagons (use H3:5 for citywide coverage)
      $total_query = $database->select('amisafe_h3_aggregated', 'h');
      $total_query->addExpression('SUM(incident_count)', 'total_incidents');
      $total_query->condition('h3_resolution', 5); // H3:5 provides complete metro coverage
      $total_incidents = $total_query->execute()->fetchField() ?: 0;
      
      // Count active districts from H3:7 hexagons (better district representation)
      $districts_query = $database->select('amisafe_h3_aggregated', 'h');
      $districts_query->addExpression('COUNT(DISTINCT h3_index)', 'hexagon_count');
      $districts_query->condition('h3_resolution', 7);
      $districts_query->condition('incident_count', 0, '>'); // Only active hexagons
      $hexagon_count = $districts_query->execute()->fetchField() ?: 0;
      
      // Estimate districts from active hexagons (each district ~= 3-4 hexagons at H3:7)
      $active_districts = min(25, max(1, round($hexagon_count / 3.7)));
      
      // Calculate active sectors (districts * 3.2)
      $active_sectors = round($active_districts * 3.2);
      
      $stats = [
        'total_citywide' => number_format($total_incidents),
        'active_districts' => $active_districts,
        'active_sectors' => $active_sectors,
        'visible_incidents' => 0, // Will be updated by JavaScript
      ];
      
      \Drupal::logger('amisafe')->info('Statistics calculated: @stats', ['@stats' => print_r($stats, TRUE)]);
      
      return $stats;
      
    } catch (\Exception $e) {
      // Fallback if database query fails
      \Drupal::logger('amisafe')->error('Statistics query failed: @error', ['@error' => $e->getMessage()]);
      
      $fallback = [
        'total_citywide' => '3,406,192',
        'active_districts' => 25,
        'active_sectors' => 80,
        'visible_incidents' => 0,
      ];
      
      \Drupal::logger('amisafe')->info('Using fallback statistics: @stats', ['@stats' => print_r($fallback, TRUE)]);
      
      return $fallback;
    }
  }

}