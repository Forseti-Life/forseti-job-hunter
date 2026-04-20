<?php

namespace Drupal\safety_calculator;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for calculating safety scores based on crime data.
 */
class SafetyCalculatorService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a SafetyCalculatorService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(Connection $database, LoggerChannelFactoryInterface $logger_factory) {
    $this->database = $database;
    $this->logger = $logger_factory->get('safety_calculator');
  }

  /**
   * Calculate safety score for a specific location.
   *
   * @param float $latitude
   *   The latitude.
   * @param float $longitude
   *   The longitude.
   * @param int $resolution
   *   The H3 resolution (default 13).
   * @param array $options
   *   Additional options:
   *   - time_filter: Filter by time period
   *   - crime_types: Array of crime types to include
   *   - radius: Number of rings to include in calculation
   *
   * @return array
   *   Array containing:
   *   - score: Safety score (0-100, higher is safer)
   *   - risk_level: String risk level (low, moderate, high, critical)
   *   - crime_count: Number of crimes in the area
   *   - hexagon: The H3 hexagon ID
   *   - details: Detailed breakdown by crime type
   */
  public function calculateSafetyScore($latitude, $longitude, $resolution = 13, array $options = []) {
    // Default options
    $options += [
      'time_filter' => NULL,
      'crime_types' => [],
      'radius' => 1,
    ];

    // Get H3 hexagon for the location
    $hexagon = $this->getH3Hexagon($latitude, $longitude, $resolution);
    
    if (!$hexagon) {
      return [
        'error' => 'Unable to determine H3 hexagon',
        'score' => NULL,
      ];
    }

    // Get nearby hexagons if radius > 0
    $hexagons = [$hexagon];
    if ($options['radius'] > 0) {
      $hexagons = array_merge($hexagons, $this->getNeighborHexagons($hexagon, $options['radius']));
    }

    // Query crime data
    $crime_data = $this->queryCrimeData($hexagons, $options);
    
    // Calculate the score
    $score = $this->computeScore($crime_data, $options);
    
    // Determine risk level
    $risk_level = $this->determineRiskLevel($score);

    return [
      'score' => $score,
      'risk_level' => $risk_level,
      'crime_count' => $crime_data['total_count'],
      'hexagon' => $hexagon,
      'hexagons_analyzed' => count($hexagons),
      'details' => $crime_data['by_type'],
      'timestamp' => time(),
    ];
  }

  /**
   * Get H3 hexagon for coordinates.
   */
  protected function getH3Hexagon($latitude, $longitude, $resolution) {
    // Use the H3 Python service or call the function directly
    // For now, we'll query the database to find the closest hexagon
    $query = $this->database->select('amisafe_crime_data', 'acd')
      ->fields('acd', ['hexagon_id'])
      ->orderBy('ABS(acd.latitude - :lat) + ABS(acd.longitude - :lon)', 'ASC')
      ->range(0, 1);
    $query->addExpression('ABS(acd.latitude - :lat) + ABS(acd.longitude - :lon)', 'distance');
    
    $result = $query->execute()->fetchField();
    
    return $result ?: NULL;
  }

  /**
   * Get neighboring hexagons.
   */
  protected function getNeighborHexagons($hexagon, $radius = 1) {
    // This would typically call H3 library functions
    // For now, return empty array - implement based on H3 integration
    return [];
  }

  /**
   * Query crime data for hexagons.
   */
  protected function queryCrimeData(array $hexagons, array $options) {
    $query = $this->database->select('amisafe_crime_data', 'acd');
    $query->fields('acd', ['crime_type']);
    $query->addExpression('COUNT(*)', 'count');
    $query->condition('acd.hexagon_id', $hexagons, 'IN');

    // Apply time filter
    if (!empty($options['time_filter'])) {
      $this->applyTimeFilter($query, $options['time_filter']);
    }

    // Apply crime type filter
    if (!empty($options['crime_types'])) {
      $query->condition('acd.crime_type', $options['crime_types'], 'IN');
    }

    $query->groupBy('acd.crime_type');
    
    $results = $query->execute()->fetchAllKeyed();
    
    $total_count = array_sum($results);
    
    return [
      'total_count' => $total_count,
      'by_type' => $results,
    ];
  }

  /**
   * Apply time filter to query.
   */
  protected function applyTimeFilter($query, $time_filter) {
    // Implement time filtering logic
    // Examples: last_30_days, last_year, custom_range
    switch ($time_filter) {
      case 'last_30_days':
        $query->condition('acd.incident_date', strtotime('-30 days'), '>=');
        break;
      
      case 'last_year':
        $query->condition('acd.incident_date', strtotime('-1 year'), '>=');
        break;
    }
  }

  /**
   * Compute safety score from crime data.
   */
  protected function computeScore(array $crime_data, array $options) {
    $total_crimes = $crime_data['total_count'];
    
    // Base score starts at 100 (perfectly safe)
    $score = 100;
    
    // Deduct points based on crime count
    // Scale: 0-5 crimes = excellent, 5-15 = good, 15-30 = moderate, 30+ = concerning
    if ($total_crimes > 0) {
      // Logarithmic scale so first few crimes have more impact
      $crime_penalty = min(80, $total_crimes * 2 + log($total_crimes + 1) * 5);
      $score -= $crime_penalty;
    }
    
    // Weight by crime type severity
    if (!empty($crime_data['by_type'])) {
      $severity_weights = $this->getCrimeSeverityWeights();
      $weighted_penalty = 0;
      
      foreach ($crime_data['by_type'] as $type => $count) {
        $weight = $severity_weights[$type] ?? 1.0;
        $weighted_penalty += $count * $weight;
      }
      
      $score -= min(20, $weighted_penalty / 10);
    }
    
    // Ensure score is within 0-100 range
    $score = max(0, min(100, $score));
    
    return round($score, 2);
  }

  /**
   * Get crime severity weights.
   */
  protected function getCrimeSeverityWeights() {
    return [
      'violent_crime' => 3.0,
      'assault' => 2.5,
      'robbery' => 2.0,
      'theft' => 1.0,
      'burglary' => 1.5,
      'vandalism' => 0.5,
      'other' => 0.3,
    ];
  }

  /**
   * Determine risk level from score.
   */
  protected function determineRiskLevel($score) {
    if ($score >= 80) {
      return 'low';
    }
    elseif ($score >= 60) {
      return 'moderate';
    }
    elseif ($score >= 40) {
      return 'high';
    }
    else {
      return 'critical';
    }
  }

  /**
   * Calculate time-based safety score.
   *
   * @param float $latitude
   *   The latitude.
   * @param float $longitude
   *   The longitude.
   * @param string $time_of_day
   *   Time category: 'morning', 'afternoon', 'evening', 'night'.
   *
   * @return array
   *   Safety score data for the specified time.
   */
  public function calculateTimeBasedSafety($latitude, $longitude, $time_of_day) {
    $hour_ranges = [
      'morning' => [6, 12],
      'afternoon' => [12, 18],
      'evening' => [18, 22],
      'night' => [22, 6],
    ];
    
    $range = $hour_ranges[$time_of_day] ?? [0, 24];
    
    $options = [
      'time_filter' => 'custom',
      'hour_range' => $range,
    ];
    
    return $this->calculateSafetyScore($latitude, $longitude, 13, $options);
  }

}
