<?php

declare(strict_types=1);

namespace Drupal\safety_calculator\Repository;

/**
 * Interface for Individual Metrics Repository.
 *
 * Provides data access layer for individual metrics system.
 */
interface IndividualMetricsRepositoryInterface {

  /**
   * Save or update a metric response.
   *
   * @param int $userId
   *   The user ID.
   * @param int $assessmentId
   *   The assessment ID.
   * @param int $metricId
   *   The metric ID.
   * @param mixed $value
   *   The response value.
   *
   * @return int
   *   The response ID.
   *
   * @throws \InvalidArgumentException
   *   If validation fails.
   */
  public function saveResponse(int $userId, int $assessmentId, int $metricId, $value): int;

  /**
   * Get a single metric response.
   *
   * @param int $userId
   *   The user ID.
   * @param int $assessmentId
   *   The assessment ID.
   * @param int $metricId
   *   The metric ID.
   *
   * @return array|null
   *   The response record or NULL if not found.
   */
  public function getResponse(int $userId, int $assessmentId, int $metricId): ?array;

  /**
   * Get all responses for a user's assessment.
   *
   * @param int $userId
   *   The user ID.
   * @param int $assessmentId
   *   The assessment ID.
   *
   * @return array
   *   Array of response records.
   */
  public function getUserResponses(int $userId, int $assessmentId): array;

  /**
   * Get responses grouped by dimension.
   *
   * @param int $userId
   *   The user ID.
   * @param int $assessmentId
   *   The assessment ID.
   *
   * @return array
   *   Array keyed by dimension name with response arrays.
   */
  public function getResponsesByDimension(int $userId, int $assessmentId): array;

  /**
   * Get metric definitions by dimension.
   *
   * @param string $dimension
   *   The dimension name.
   *
   * @return array
   *   Array of metric definition records.
   */
  public function getMetricsByDimension(string $dimension): array;

  /**
   * Get metric definitions by category.
   *
   * @param string $category
   *   The category name.
   *
   * @return array
   *   Array of metric definition records.
   */
  public function getMetricsByCategory(string $category): array;

  /**
   * Get a single metric definition.
   *
   * @param int $metricId
   *   The metric ID.
   *
   * @return array|null
   *   The metric definition or NULL if not found.
   */
  public function getMetric(int $metricId): ?array;

  /**
   * Get metric definition by question ID and metric name.
   *
   * @param int $questionId
   *   The question ID.
   * @param string $metricName
   *   The metric name.
   *
   * @return array|null
   *   The metric definition or NULL if not found.
   */
  public function getMetricByName(int $questionId, string $metricName): ?array;

  /**
   * Update z-score for a response (system only).
   *
   * @param int $responseId
   *   The response ID.
   * @param float $zScore
   *   The calculated z-score.
   *
   * @return bool
   *   TRUE on success.
   */
  public function updateZScore(int $responseId, float $zScore): bool;

  /**
   * Get responses without z-scores.
   *
   * @param int|null $limit
   *   Optional limit for batch processing.
   *
   * @return array
   *   Array of response records needing z-score calculation.
   */
  public function getResponsesWithoutZScores(?int $limit = NULL): array;

  /**
   * Validate a response value against metric rules.
   *
   * @param int $metricId
   *   The metric ID.
   * @param mixed $value
   *   The value to validate.
   *
   * @return bool
   *   TRUE if valid.
   *
   * @throws \InvalidArgumentException
   *   If validation fails with detailed message.
   */
  public function validateResponse(int $metricId, $value): bool;

  /**
   * Bulk save responses in a transaction.
   *
   * @param array $responses
   *   Array of response arrays with keys: user_id, assessment_id, metric_id, value.
   *
   * @return int
   *   Number of responses saved.
   *
   * @throws \Exception
   *   If transaction fails.
   */
  public function bulkSaveResponses(array $responses): int;

  /**
   * Create a new assessment.
   *
   * @param int $userId
   *   The user ID.
   *
   * @return int
   *   The assessment ID.
   */
  public function createAssessment(int $userId): int;

  /**
   * Get an assessment.
   *
   * @param int $assessmentId
   *   The assessment ID.
   *
   * @return array|null
   *   The assessment record or NULL if not found.
   */
  public function getAssessment(int $assessmentId): ?array;

  /**
   * Complete an assessment and calculate scores.
   *
   * @param int $assessmentId
   *   The assessment ID.
   * @param array $dimensionScores
   *   Array of dimension scores.
   * @param float $overallScore
   *   The overall score.
   *
   * @return bool
   *   TRUE on success.
   */
  public function completeAssessment(int $assessmentId, array $dimensionScores, float $overallScore): bool;

  /**
   * Get user's most recent assessment.
   *
   * @param int $userId
   *   The user ID.
   *
   * @return array|null
   *   The assessment record or NULL if not found.
   */
  public function getLatestAssessment(int $userId): ?array;

  /**
   * Get all dimensions.
   *
   * @return array
   *   Array of unique dimension names.
   */
  public function getDimensions(): array;

  /**
   * Get all categories for a dimension.
   *
   * @param string $dimension
   *   The dimension name.
   *
   * @return array
   *   Array of unique category names.
   */
  public function getCategoriesForDimension(string $dimension): array;

}
