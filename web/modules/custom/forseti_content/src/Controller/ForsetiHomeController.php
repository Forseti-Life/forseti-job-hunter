<?php

namespace Drupal\forseti_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Drupal\amisafe\Service\CrimeDataService;
use Drupal\amisafe\Service\H3AggregatorService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Forseti home page.
 */
class ForsetiHomeController extends ControllerBase {

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
   * Constructs a ForsetiHomeController object.
   */
  public function __construct(CrimeDataService $crime_data_service = NULL, H3AggregatorService $h3_aggregator_service = NULL) {
    $this->crimeDataService = $crime_data_service;
    $this->h3AggregatorService = $h3_aggregator_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->has('amisafe.crime_data') ? $container->get('amisafe.crime_data') : NULL,
      $container->has('amisafe.h3_aggregator') ? $container->get('amisafe.h3_aggregator') : NULL
    );
  }

  /**
   * Returns the home page content.
   */
  public function content() {
    $build = [];

    $build['#attached']['library'][] = 'forseti_content/style';

    $build['hero'] = [
      '#type' => 'markup',
      '#markup' => '
        <div class="container py-5">
          <div class="row">
            <div class="col-lg-8 mx-auto text-center">
              <h1 class="display-4 mb-4 text-cyan">Forseti: AI-Powered Community Services</h1>
              <p class="lead mb-4">Building automated services that save time and improve outcomes for our community members.</p>
            </div>
          </div>
        </div>
      ',
    ];

    $build['services'] = [
      '#type' => 'markup',
      '#markup' => '
        <div class="container py-4">
          <div class="row">
            <div class="col-lg-10 mx-auto">
              <h2 class="text-center mb-4 text-cyan">Current Services</h2>
              
              <div class="card card-forseti mb-4 p-4">
                <h3 class="text-cyan mb-3">
                  <a href="/jobhunter" class="text-cyan">Job Hunter</a> - Stop AI Systems from Wasting Your Time
                </h3>
                <p class="lead mb-3">Avoid the AI resume screening chaos for both employers and job hunters!</p>
                
                <div class="row mt-3">
                  <div class="col-md-6 mb-3">
                    <h4 class="h5 text-cyan">For Job Hunters</h4>
                    <p>Tailor your resume to the job description based on <strong>all of your skills</strong>... we tailor it for you!</p>
                  </div>
                  <div class="col-md-6 mb-3">
                    <h4 class="h5 text-cyan">For Employers</h4>
                    <p>Don\'t waste time looking at resumes! Find skilled workers we trust. We filter and rank candidates for you.</p>
                    <p><strong>Saving everyone time.</strong></p>
                  </div>
                </div>
                
                <div class="text-center mt-3">
                  <a href="/jobhunter" class="btn btn-primary">Access Job Hunter</a>
                </div>
              </div>
              
              <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
                <a href="/how-it-works" class="btn btn-outline-primary">Learn How It Works</a>
                <a href="/contact" class="btn btn-outline-primary">Contact Us</a>
              </div>
            </div>
          </div>
        </div>
      ',
    ];

    return $build;
  }

  /**
   * Returns the safety map page.
   */
  public function safetyMap() {
    $build = [];

    $build['#attached']['library'][] = 'forseti_content/style';

    // Add page introduction
    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => '
        <div class="container py-4">
          <div class="row">
            <div class="col-lg-10 mx-auto text-center">
              <h1 class="mb-3">Philadelphia Safety Map</h1>
              <p class="lead">
                Real-time crime monitoring using H3 geospatial indexing. 
                View crime incidents across Philadelphia with interactive heat maps and detailed statistics.
              </p>
              <div class="alert alert-info">
                <strong>AI-Powered Safety:</strong> This map uses advanced H3 geospatial technology to aggregate 
                and visualize crime data, helping you make informed decisions about safety in your area.
              </div>
            </div>
          </div>
        </div>
      ',
    ];

    // Add the interactive safety map
    $build['safety_map'] = $this->getSafetyMapContent();

    return $build;
  }

  /**
   * Get safety map content.
   */
  private function getSafetyMapContent() {
    // Use default configuration
    $default_zoom = 11;
    $default_center = [39.9526, -75.1652];

    // Get data
    $crime_types = $this->getDefaultCrimeTypes();
    $districts = $this->getDefaultDistricts();
    $date_range = $this->getDefaultDateRange();
    $citywide_stats = $this->getCitywideStatistics();

    return [
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
          'library' => ['amisafe/crime-map'],
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
              'debugMode' => FALSE,
            ],
          ],
        ],
    ];
  }

  /**
   * Provides default crime types.
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
   * Provides default districts.
   */
  private function getDefaultDistricts() {
    return ['1', '2', '3', '5', '6', '7', '8', '9', '12', '14', '15', '16', '17', '18', '19', '22', '24', '25', '26', '35', '39'];
  }

  /**
   * Provides default date range.
   */
  private function getDefaultDateRange() {
    return [
      'min' => '2022-01-01 00:00:00',
      'max' => '2025-10-27 23:59:59',
    ];
  }

  /**
   * Get citywide statistics.
   */
  private function getCitywideStatistics() {
    try {
      if (!$this->crimeDataService) {
        throw new \Exception('Crime data service not available');
      }
      
      $database = \Drupal\Core\Database\Database::getConnection('default', 'amisafe');
      
      $total_query = $database->select('amisafe_h3_aggregated', 'h');
      $total_query->addExpression('SUM(incident_count)', 'total_incidents');
      $total_query->condition('h3_resolution', 5);
      $total_incidents = $total_query->execute()->fetchField() ?: 0;
      
      $districts_query = $database->select('amisafe_h3_aggregated', 'h');
      $districts_query->addExpression('COUNT(DISTINCT h3_index)', 'hexagon_count');
      $districts_query->condition('h3_resolution', 7);
      $districts_query->condition('incident_count', 0, '>');
      $hexagon_count = $districts_query->execute()->fetchField() ?: 0;
      
      $active_districts = min(25, max(1, round($hexagon_count / 3.7)));
      $active_sectors = round($active_districts * 3.2);
      
      return [
        'total_citywide' => number_format($total_incidents),
        'active_districts' => $active_districts,
        'active_sectors' => $active_sectors,
        'visible_incidents' => 0,
      ];
      
    } catch (\Exception $e) {
      return [
        'total_citywide' => '3,406,192',
        'active_districts' => 25,
        'active_sectors' => 80,
        'visible_incidents' => 0,
      ];
    }
  }

}
