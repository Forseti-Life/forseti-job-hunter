<?php

namespace Drupal\amisafe\Service;

use Drupal\amisafe\Service\CrimeDataService;
use Drupal\amisafe\Service\H3AggregatorService;

/**
 * Service for spatial analysis and hotspot detection.
 */
class SpatialAnalyzerService {

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
   * Constructor.
   */
  public function __construct(CrimeDataService $crime_data_service, H3AggregatorService $h3_aggregator_service) {
    $this->crimeDataService = $crime_data_service;
    $this->h3AggregatorService = $h3_aggregator_service;
  }

  /**
   * Get crime hotspots based on filters and threshold.
   */
  public function getHotspots($filters = [], $resolution = 9, $threshold = 10) {
    try {
      // For now, return sample hotspot data
      // In a full implementation, this would analyze H3 aggregated data
      $hotspots = $this->generateSampleHotspots($resolution, $threshold);
      
      return $hotspots;
    } catch (\Exception $e) {
      \Drupal::logger('amisafe')->error('Error in hotspot analysis: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Generate sample hotspot data for testing.
   */
  private function generateSampleHotspots($resolution = 9, $threshold = 10) {
    $hotspots = [];
    
    // Philadelphia high-crime areas (sample data)
    $hotspot_areas = [
      ['lat' => 39.9612, 'lng' => -75.1605, 'name' => 'North Philadelphia', 'intensity' => 85],
      ['lat' => 39.9440, 'lng' => -75.1636, 'name' => 'Center City', 'intensity' => 65],
      ['lat' => 39.9242, 'lng' => -75.1665, 'name' => 'South Philadelphia', 'intensity' => 72],
      ['lat' => 39.9804, 'lng' => -75.1530, 'name' => 'Kensington', 'intensity' => 90],
      ['lat' => 39.9370, 'lng' => -75.2100, 'name' => 'West Philadelphia', 'intensity' => 78],
    ];
    
    foreach ($hotspot_areas as $area) {
      if ($area['intensity'] >= $threshold) {
        $hotspots[] = [
          'lat' => $area['lat'],
          'lng' => $area['lng'],
          'intensity' => $area['intensity'],
          'name' => $area['name'],
          'incident_count' => rand($threshold, 100),
          'severity_avg' => round($area['intensity'] / 20, 1),
          'resolution' => $resolution,
          'radius' => $this->calculateHotspotRadius($area['intensity']),
          'primary_crime_types' => $this->getPrimaryCrimeTypes(),
        ];
      }
    }
    
    return $hotspots;
  }

  /**
   * Calculate hotspot radius based on intensity.
   */
  private function calculateHotspotRadius($intensity) {
    return max(200, $intensity * 5); // Minimum 200m radius, scales with intensity
  }

  /**
   * Get primary crime types for hotspots.
   */
  private function getPrimaryCrimeTypes() {
    $crime_types = ['100', '200', '300', '400', '500', '600'];
    $count = rand(1, 3);
    return array_slice(array_rand(array_flip($crime_types), $count), 0, $count);
  }

  /**
   * Analyze temporal crime patterns.
   */
  public function analyzeTemporalPatterns($filters = []) {
    // Placeholder for temporal analysis
    return [
      'peak_hours' => [18, 19, 20, 21, 22],
      'peak_days' => ['Friday', 'Saturday', 'Sunday'],
      'seasonal_trends' => 'Higher activity in summer months',
    ];
  }

  /**
   * Calculate crime density trends.
   */
  public function calculateTrends($h3_index, $time_periods = ['1d', '7d', '30d']) {
    // Placeholder for trend calculation
    $trends = [];
    foreach ($time_periods as $period) {
      $trends[$period] = [
        'direction' => rand(0, 1) ? 'increasing' : 'decreasing',
        'percentage' => rand(-50, 50),
        'significance' => rand(0, 1) ? 'significant' : 'not_significant',
      ];
    }
    return $trends;
  }

}