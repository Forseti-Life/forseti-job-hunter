<?php

declare(strict_types=1);

namespace Drupal\safety_calculator\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface;

/**
 * Service for calculating z-scores for individual metrics.
 */
class ZScoreCalculationService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The individual metrics repository.
   *
   * @var \Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface
   */
  protected IndividualMetricsRepositoryInterface $repository;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a ZScoreCalculationService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface $repository
   *   The individual metrics repository.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(Connection $database, IndividualMetricsRepositoryInterface $repository, LoggerChannelFactoryInterface $loggerFactory) {
    $this->database = $database;
    $this->repository = $repository;
    $this->logger = $loggerFactory->get('safety_calculator');
  }

  /**
   * Calculate z-scores for all responses without z-scores.
   *
   * @param int|null $limit
   *   Optional limit for batch processing.
   *
   * @return array
   *   Array with 'processed' and 'errors' counts.
   */
  public function calculateAllZScores(?int $limit = NULL): array {
    $responses = $this->repository->getResponsesWithoutZScores($limit);
    $processed = 0;
    $errors = 0;

    foreach ($responses as $response) {
      try {
        $zScore = $this->calculateZScore((int) $response['metric_id'], $response['response_value']);
        
        if ($zScore !== NULL) {
          $this->repository->updateZScore((int) $response['id'], $zScore);
          $processed++;
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to calculate z-score for response @id: @error', [
          '@id' => $response['id'],
          '@error' => $e->getMessage(),
        ]);
        $errors++;
      }
    }

    return [
      'processed' => $processed,
      'errors' => $errors,
    ];
  }

  /**
   * Calculate z-score for a specific metric response.
   *
   * @param int $metricId
   *   The metric ID.
   * @param mixed $value
   *   The response value.
   *
   * @return float|null
   *   The calculated z-score, or NULL if not calculable.
   */
  public function calculateZScore(int $metricId, $value): ?float {
    $metric = $this->repository->getMetric($metricId);
    
    if (!$metric) {
      throw new \InvalidArgumentException("Metric ID {$metricId} not found");
    }

    // Only calculate z-scores for numeric and scale types.
    if (!in_array($metric['data_type'], ['numeric', 'scale'])) {
      return NULL;
    }

    // Convert value to numeric.
    $numericValue = is_numeric($value) ? (float) $value : NULL;
    
    if ($numericValue === NULL) {
      return NULL;
    }

    // Get population statistics for this metric.
    $stats = $this->getPopulationStatistics($metricId);
    
    if (!$stats || $stats['std_dev'] == 0) {
      // Cannot calculate z-score without variation in data.
      return NULL;
    }

    // Calculate z-score: (value - mean) / std_dev
    $zScore = ($numericValue - $stats['mean']) / $stats['std_dev'];

    return round($zScore, 4);
  }

  /**
   * Get population statistics for a metric.
   *
   * @param int $metricId
   *   The metric ID.
   *
   * @return array|null
   *   Array with 'mean', 'std_dev', 'count', 'min', 'max' or NULL.
   */
  public function getPopulationStatistics(int $metricId): ?array {
    $query = $this->database->select('individual_metric_responses', 'r')
      ->condition('metric_id', $metricId);

    // Only consider numeric values.
    $query->where('response_value REGEXP :pattern', [':pattern' => '^[0-9]+\.?[0-9]*$']);

    // Calculate statistics.
    $query->addExpression('COUNT(*)', 'count');
    $query->addExpression('AVG(CAST(response_value AS DECIMAL(10,4)))', 'mean');
    $query->addExpression('STDDEV_POP(CAST(response_value AS DECIMAL(10,4)))', 'std_dev');
    $query->addExpression('MIN(CAST(response_value AS DECIMAL(10,4)))', 'min');
    $query->addExpression('MAX(CAST(response_value AS DECIMAL(10,4)))', 'max');

    $result = $query->execute()->fetchAssoc();

    if (!$result || $result['count'] < 2) {
      // Need at least 2 data points for meaningful statistics.
      return NULL;
    }

    return [
      'count' => (int) $result['count'],
      'mean' => (float) $result['mean'],
      'std_dev' => (float) $result['std_dev'],
      'min' => (float) $result['min'],
      'max' => (float) $result['max'],
    ];
  }

  /**
   * Recalculate all z-scores (useful when population changes significantly).
   *
   * @param int|null $limit
   *   Optional limit for batch processing.
   *
   * @return array
   *   Array with 'processed' and 'errors' counts.
   */
  public function recalculateAllZScores(?int $limit = NULL): array {
    // Get all numeric/scale responses.
    $query = $this->database->select('individual_metric_responses', 'r');
    $query->join('individual_metrics_master', 'm', 'r.metric_id = m.id');
    $query->fields('r', ['id', 'metric_id', 'response_value']);
    $query->condition('m.data_type', ['numeric', 'scale'], 'IN');

    if ($limit !== NULL) {
      $query->range(0, $limit);
    }

    $responses = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $processed = 0;
    $errors = 0;

    foreach ($responses as $response) {
      try {
        $zScore = $this->calculateZScore((int) $response['metric_id'], $response['response_value']);
        
        if ($zScore !== NULL) {
          $this->repository->updateZScore((int) $response['id'], $zScore);
          $processed++;
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to recalculate z-score for response @id: @error', [
          '@id' => $response['id'],
          '@error' => $e->getMessage(),
        ]);
        $errors++;
      }
    }

    return [
      'processed' => $processed,
      'errors' => $errors,
    ];
  }

  /**
   * Get z-score statistics across all metrics.
   *
   * @return array
   *   Summary statistics.
   */
  public function getZScoreStatistics(): array {
    $query = $this->database->select('individual_metric_responses', 'r');
    $query->condition('z_score', NULL, 'IS NOT NULL');
    $query->addExpression('COUNT(*)', 'total_scored');
    $query->addExpression('AVG(z_score)', 'avg_z_score');
    $query->addExpression('MIN(z_score)', 'min_z_score');
    $query->addExpression('MAX(z_score)', 'max_z_score');
    $query->addExpression('STDDEV(z_score)', 'std_dev_z_score');

    $result = $query->execute()->fetchAssoc();

    // Count responses without z-scores.
    $unscored = $this->database->select('individual_metric_responses', 'r')
      ->condition('z_score', NULL, 'IS NULL')
      ->countQuery()
      ->execute()
      ->fetchField();

    return [
      'total_scored' => (int) $result['total_scored'],
      'total_unscored' => (int) $unscored,
      'avg_z_score' => $result['avg_z_score'] ? round((float) $result['avg_z_score'], 4) : 0,
      'min_z_score' => $result['min_z_score'] ? round((float) $result['min_z_score'], 4) : 0,
      'max_z_score' => $result['max_z_score'] ? round((float) $result['max_z_score'], 4) : 0,
      'std_dev_z_score' => $result['std_dev_z_score'] ? round((float) $result['std_dev_z_score'], 4) : 0,
    ];
  }

  /**
   * Get metrics that need z-score calculation.
   *
   * @return array
   *   Array of metric IDs with pending z-score calculations.
   */
  public function getMetricsNeedingCalculation(): array {
    $query = $this->database->select('individual_metric_responses', 'r');
    $query->join('individual_metrics_master', 'm', 'r.metric_id = m.id');
    $query->fields('m', ['id', 'metric_name', 'data_type']);
    $query->addExpression('COUNT(r.id)', 'pending_count');
    $query->condition('r.z_score', NULL, 'IS NULL');
    $query->condition('m.data_type', ['numeric', 'scale'], 'IN');
    $query->groupBy('m.id');
    $query->groupBy('m.metric_name');
    $query->groupBy('m.data_type');
    $query->orderBy('pending_count', 'DESC');

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

}
