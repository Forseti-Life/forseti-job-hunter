<?php

declare(strict_types=1);

namespace Drupal\safety_calculator\Repository;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Repository for Individual Metrics data access.
 */
class IndividualMetricsRepository implements IndividualMetricsRepositoryInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs an IndividualMetricsRepository object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(Connection $database, LoggerChannelFactoryInterface $loggerFactory) {
    $this->database = $database;
    $this->logger = $loggerFactory->get('safety_calculator');
  }

  /**
   * {@inheritdoc}
   */
  public function saveResponse(int $userId, int $assessmentId, int $metricId, $value): int {
    // Validate the response.
    $this->validateResponse($metricId, $value);

    // Check if response exists.
    $existing = $this->getResponse($userId, $assessmentId, $metricId);

    $fields = [
      'user_id' => $userId,
      'assessment_id' => $assessmentId,
      'metric_id' => $metricId,
      'response_value' => is_array($value) ? json_encode($value) : (string) $value,
      'updated' => time(),
    ];

    try {
      if ($existing) {
        // Update existing response.
        $this->database->update('individual_metric_responses')
          ->fields($fields)
          ->condition('id', $existing['id'])
          ->execute();

        $this->logger->info('Updated metric response @id for user @uid', [
          '@id' => $existing['id'],
          '@uid' => $userId,
        ]);

        return (int) $existing['id'];
      }
      else {
        // Insert new response.
        $fields['created'] = time();
        $id = $this->database->insert('individual_metric_responses')
          ->fields($fields)
          ->execute();

        $this->logger->info('Created metric response @id for user @uid', [
          '@id' => $id,
          '@uid' => $userId,
        ]);

        return (int) $id;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to save metric response: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getResponse(int $userId, int $assessmentId, int $metricId): ?array {
    $result = $this->database->select('individual_metric_responses', 'r')
      ->fields('r')
      ->condition('user_id', $userId)
      ->condition('assessment_id', $assessmentId)
      ->condition('metric_id', $metricId)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getUserResponses(int $userId, int $assessmentId): array {
    return $this->database->select('individual_metric_responses', 'r')
      ->fields('r')
      ->condition('user_id', $userId)
      ->condition('assessment_id', $assessmentId)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * {@inheritdoc}
   */
  public function getResponsesByDimension(int $userId, int $assessmentId): array {
    $query = $this->database->select('individual_metric_responses', 'r');
    $query->join('individual_metrics_master', 'm', 'r.metric_id = m.id');
    $query->fields('r');
    $query->addField('m', 'dimension');
    $query->addField('m', 'category');
    $query->addField('m', 'question_id');
    $query->addField('m', 'metric_name');
    $query->addField('m', 'metric_description');
    $query->condition('r.user_id', $userId);
    $query->condition('r.assessment_id', $assessmentId);
    $query->orderBy('m.dimension');
    $query->orderBy('m.question_id');

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    // Group by dimension.
    $grouped = [];
    foreach ($results as $row) {
      $dimension = $row['dimension'];
      if (!isset($grouped[$dimension])) {
        $grouped[$dimension] = [];
      }
      $grouped[$dimension][] = $row;
    }

    return $grouped;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetricsByDimension(string $dimension): array {
    return $this->database->select('individual_metrics_master', 'm')
      ->fields('m')
      ->condition('dimension', $dimension)
      ->orderBy('question_id')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * {@inheritdoc}
   */
  public function getMetricsByCategory(string $category): array {
    return $this->database->select('individual_metrics_master', 'm')
      ->fields('m')
      ->condition('category', $category)
      ->orderBy('question_id')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * {@inheritdoc}
   */
  public function getMetric(int $metricId): ?array {
    $result = $this->database->select('individual_metrics_master', 'm')
      ->fields('m')
      ->condition('id', $metricId)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetricByName(int $questionId, string $metricName): ?array {
    $result = $this->database->select('individual_metrics_master', 'm')
      ->fields('m')
      ->condition('question_id', $questionId)
      ->condition('metric_name', $metricName)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function updateZScore(int $responseId, float $zScore): bool {
    try {
      $this->database->update('individual_metric_responses')
        ->fields([
          'z_score' => $zScore,
          'z_score_calculated_at' => time(),
        ])
        ->condition('id', $responseId)
        ->execute();

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update z-score for response @id: @error', [
        '@id' => $responseId,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getResponsesWithoutZScores(?int $limit = NULL): array {
    $query = $this->database->select('individual_metric_responses', 'r')
      ->fields('r')
      ->isNull('z_score');

    if ($limit !== NULL) {
      $query->range(0, $limit);
    }

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * {@inheritdoc}
   */
  public function validateResponse(int $metricId, $value): bool {
    $metric = $this->getMetric($metricId);

    if (!$metric) {
      throw new \InvalidArgumentException("Metric ID {$metricId} not found");
    }

    $dataType = $metric['data_type'];
    $validationRules = !empty($metric['validation_rules']) ? json_decode($metric['validation_rules'], TRUE) : [];

    // Type validation.
    switch ($dataType) {
      case 'numeric':
        if (!is_numeric($value)) {
          throw new \InvalidArgumentException("Value must be numeric for metric {$metric['metric_name']}");
        }
        // Check min/max.
        if (isset($validationRules['min']) && $value < $validationRules['min']) {
          throw new \InvalidArgumentException("Value must be at least {$validationRules['min']}");
        }
        if (isset($validationRules['max']) && $value > $validationRules['max']) {
          throw new \InvalidArgumentException("Value must be at most {$validationRules['max']}");
        }
        break;

      case 'boolean':
        if (!is_bool($value) && !in_array($value, ['0', '1', 0, 1, TRUE, FALSE], TRUE)) {
          throw new \InvalidArgumentException("Value must be boolean for metric {$metric['metric_name']}");
        }
        break;

      case 'scale':
        if (!is_numeric($value)) {
          throw new \InvalidArgumentException("Scale value must be numeric for metric {$metric['metric_name']}");
        }
        if (isset($validationRules['min']) && $value < $validationRules['min']) {
          throw new \InvalidArgumentException("Scale value must be at least {$validationRules['min']}");
        }
        if (isset($validationRules['max']) && $value > $validationRules['max']) {
          throw new \InvalidArgumentException("Scale value must be at most {$validationRules['max']}");
        }
        break;

      case 'select':
        if (isset($validationRules['options']) && !in_array($value, $validationRules['options'], TRUE)) {
          $options = implode(', ', $validationRules['options']);
          throw new \InvalidArgumentException("Value must be one of: {$options}");
        }
        break;

      case 'text':
        // Text is flexible, but check max length if specified.
        if (isset($validationRules['max_length']) && strlen((string) $value) > $validationRules['max_length']) {
          throw new \InvalidArgumentException("Text must be at most {$validationRules['max_length']} characters");
        }
        break;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function bulkSaveResponses(array $responses): int {
    $transaction = $this->database->startTransaction();
    $count = 0;

    try {
      foreach ($responses as $response) {
        $this->saveResponse(
          $response['user_id'],
          $response['assessment_id'],
          $response['metric_id'],
          $response['value']
        );
        $count++;
      }

      $this->logger->info('Bulk saved @count metric responses', ['@count' => $count]);
      return $count;
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('Bulk save failed: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createAssessment(int $userId): int {
    try {
      $id = $this->database->insert('individual_assessments')
        ->fields([
          'user_id' => $userId,
          'started_at' => time(),
          'status' => 'in_progress',
          'created' => time(),
          'updated' => time(),
        ])
        ->execute();

      $this->logger->info('Created assessment @id for user @uid', [
        '@id' => $id,
        '@uid' => $userId,
      ]);

      return (int) $id;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create assessment: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAssessment(int $assessmentId): ?array {
    $result = $this->database->select('individual_assessments', 'a')
      ->fields('a')
      ->condition('id', $assessmentId)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function completeAssessment(int $assessmentId, array $dimensionScores, float $overallScore): bool {
    try {
      $this->database->update('individual_assessments')
        ->fields([
          'completed_at' => time(),
          'status' => 'completed',
          'dimension_scores' => json_encode($dimensionScores),
          'overall_score' => $overallScore,
          'updated' => time(),
        ])
        ->condition('id', $assessmentId)
        ->execute();

      $this->logger->info('Completed assessment @id with overall score @score', [
        '@id' => $assessmentId,
        '@score' => $overallScore,
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to complete assessment @id: @error', [
        '@id' => $assessmentId,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getLatestAssessment(int $userId): ?array {
    $result = $this->database->select('individual_assessments', 'a')
      ->fields('a')
      ->condition('user_id', $userId)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getDimensions(): array {
    return $this->database->select('individual_metrics_master', 'm')
      ->fields('m', ['dimension'])
      ->distinct()
      ->orderBy('dimension')
      ->execute()
      ->fetchCol();
  }

  /**
   * {@inheritdoc}
   */
  public function getCategoriesForDimension(string $dimension): array {
    return $this->database->select('individual_metrics_master', 'm')
      ->fields('m', ['category'])
      ->condition('dimension', $dimension)
      ->distinct()
      ->orderBy('category')
      ->execute()
      ->fetchCol();
  }

}
