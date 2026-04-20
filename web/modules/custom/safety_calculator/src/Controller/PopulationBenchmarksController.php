<?php

namespace Drupal\safety_calculator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Population Benchmarks page.
 */
class PopulationBenchmarksController extends ControllerBase {

  /**
   * The individual metrics repository.
   *
   * @var \Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface
   */
  protected IndividualMetricsRepositoryInterface $repository;

  /**
   * Constructs a PopulationBenchmarksController object.
   *
   * @param \Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface $repository
   *   The individual metrics repository.
   */
  public function __construct(IndividualMetricsRepositoryInterface $repository) {
    $this->repository = $repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('safety_calculator.individual_metrics_repository')
    );
  }

  /**
   * Display the population benchmarks page.
   *
   * @return array
   *   A render array.
   */
  public function page(): array {
    // Define hierarchical order for dimensions
    $dimensionOrder = [
      'DEMOGRAPHIC',
      'SAFE',
      'ENERGIZED',
      'CONNECTED',
      'FREE',
      'CAPABLE',
      'USEFUL',
      'WHOLE',
    ];
    
    // Get all dimensions
    $dimensions = $this->repository->getDimensions();
    
    // Get all metrics grouped by dimension and category
    $metricsData = [];
    foreach ($dimensions as $dimension) {
      $metrics = $this->repository->getMetricsByDimension($dimension);
      
      // Group by category
      $categories = [];
      foreach ($metrics as $metric) {
        $category = $metric['category'];
        if (!isset($categories[$category])) {
          $categories[$category] = [
            'name' => $category,
            'metrics' => [],
          ];
        }
        $categories[$category]['metrics'][] = $metric;
      }
      
      $metricsData[$dimension] = [
        'name' => $dimension,
        'categories' => array_values($categories),
        'metric_count' => count($metrics),
      ];
    }
    
    // Sort dimensions according to hierarchical order
    $orderedMetricsData = [];
    foreach ($dimensionOrder as $dimension) {
      if (isset($metricsData[$dimension])) {
        $orderedMetricsData[$dimension] = $metricsData[$dimension];
      }
    }
    // Add any dimensions not in the predefined order (safety net)
    foreach ($metricsData as $dimension => $data) {
      if (!isset($orderedMetricsData[$dimension])) {
        $orderedMetricsData[$dimension] = $data;
      }
    }
    
    // Calculate dimension-level averages (Philadelphia scores)
    $dimensionScores = $this->calculateDimensionScores($orderedMetricsData);
    
    // Prepare metrics data for JavaScript recalculation
    $metricsForJS = [];
    foreach ($orderedMetricsData as $dimension_key => $dimension) {
      foreach ($dimension['categories'] as $category) {
        foreach ($category['metrics'] as $metric) {
          if (in_array($metric['data_type'], ['numeric', 'scale']) && isset($metric['normalized_mean'])) {
            $metricsForJS[] = [
              'dimension' => $dimension_key,
              'metric_name' => $metric['metric_name'],
              'normalized_mean' => $metric['normalized_mean'],
              'population_mean' => $metric['population_mean'] ?? null,
              'population_stddev' => $metric['population_stddev'] ?? null,
            ];
          }
        }
      }
    }
    
    return [
      '#theme' => 'population_benchmarks',
      '#dimensions' => $orderedMetricsData,
      '#dimension_scores' => $dimensionScores,
      '#attached' => [
        'library' => [
          'safety_calculator/population-benchmarks',
        ],
        'drupalSettings' => [
          'populationBenchmarks' => [
            'metrics' => $metricsForJS,
          ],
        ],
      ],
    ];
  }

  /**
   * Calculate overall scores for each dimension using z-score normalization.
   * 
   * Only includes numeric and scale metrics in scoring.
   * Boolean and select metrics are excluded pending additional modeling research.
   * Excludes DEMOGRAPHIC dimension from scoring.
   */
  protected function calculateDimensionScores(array $metricsData): array {
    $scores = [];
    
    foreach ($metricsData as $dimension => $data) {
      // Skip demographic dimension
      if ($dimension === 'DEMOGRAPHIC') {
        $scores[$dimension] = NULL;
        continue;
      }
      
      $totalMetrics = 0;
      $weightedSum = 0;
      
      foreach ($data['categories'] as $category) {
        foreach ($category['metrics'] as $metric) {
          // Only include numeric and scale metrics in scoring
          if (!in_array($metric['data_type'], ['numeric', 'scale'])) {
            continue;
          }
          
          // Use normalized mean if available, otherwise calculate z-score
          if (isset($metric['normalized_mean']) && $metric['normalized_mean'] !== null) {
            $totalMetrics++;
            $weightedSum += $metric['normalized_mean'];
          }
          elseif (isset($metric['population_mean'], $metric['population_stddev']) 
                  && $metric['population_stddev'] > 0) {
            $totalMetrics++;
            // Calculate z-score: z = (value - mean) / stddev
            // Since we're at the mean, z = 0, so normalized = 50
            $zScore = 0; // At population mean
            $normalizedValue = $this->zScoreToNormalized($zScore);
            $weightedSum += $normalizedValue;
          }
        }
      }
      
      $scores[$dimension] = $totalMetrics > 0 ? round($weightedSum / $totalMetrics, 2) : NULL;
    }
    
    return $scores;
  }

  /**
   * Convert z-score to 0-100 normalized scale.
   * 
   * Maps z=-3 to 0, z=0 to 50, z=+3 to 100
   * 
   * @param float $zScore
   *   The z-score value.
   * 
   * @return float
   *   Normalized value between 0-100.
   */
  protected function zScoreToNormalized(float $zScore): float {
    // Formula: 50 + (z * 16.67)
    // 16.67 = 50/3 (maps ±3 std dev to 0-100 range)
    $normalized = 50 + ($zScore * 16.67);
    
    // Clamp to 0-100 range
    return min(100, max(0, $normalized));
  }

}
