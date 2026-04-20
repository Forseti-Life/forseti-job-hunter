<?php

namespace Drupal\agent_evaluation\Service;

/**
 * Service for calculating aggregated power scores.
 */
class EvaluationCalculator {

  /**
   * Calculates all dimension scores and total power from sub-dimensions.
   *
   * @param array $sub_dimension_values
   *   Array of field_sub_* => value pairs.
   *
   * @return array
   *   Array with calculated main dimension and total scores.
   */
  public function calculateScores(array $sub_dimension_values) {
    $scores = [];

    // Information Access (average of 6 sub-dimensions)
    $scores['field_information_access'] = $this->calculateAverage([
      $sub_dimension_values['field_sub_scope'] ?? 0,
      $sub_dimension_values['field_sub_restriction'] ?? 0,
      $sub_dimension_values['field_sub_classification'] ?? 0,
      $sub_dimension_values['field_sub_temporal'] ?? 0,
      $sub_dimension_values['field_sub_sources'] ?? 0,
      $sub_dimension_values['field_sub_granularity'] ?? 0,
    ]);

    // Resource Control (average of 6 sub-dimensions)
    $scores['field_resource_control'] = $this->calculateAverage([
      $sub_dimension_values['field_sub_computational'] ?? 0,
      $sub_dimension_values['field_sub_financial'] ?? 0,
      $sub_dimension_values['field_sub_data_storage'] ?? 0,
      $sub_dimension_values['field_sub_network_bandwidth'] ?? 0,
      $sub_dimension_values['field_sub_api_access'] ?? 0,
      $sub_dimension_values['field_sub_human'] ?? 0,
    ]);

    // Authority & Permission (average of 6 sub-dimensions)
    $scores['field_authority_permission'] = $this->calculateAverage([
      $sub_dimension_values['field_sub_legal'] ?? 0,
      $sub_dimension_values['field_sub_institutional'] ?? 0,
      $sub_dimension_values['field_sub_budget_auth'] ?? 0,
      $sub_dimension_values['field_sub_policy'] ?? 0,
      $sub_dimension_values['field_sub_override'] ?? 0,
      $sub_dimension_values['field_sub_audit'] ?? 0,
    ]);

    // Network Position (average of 6 sub-dimensions)
    $scores['field_network_position'] = $this->calculateAverage([
      $sub_dimension_values['field_sub_connectivity'] ?? 0,
      $sub_dimension_values['field_sub_centrality'] ?? 0,
      $sub_dimension_values['field_sub_trust_reputation'] ?? 0,
      $sub_dimension_values['field_sub_info_flow'] ?? 0,
      $sub_dimension_values['field_sub_coalition'] ?? 0,
      $sub_dimension_values['field_sub_network_effects'] ?? 0,
    ]);

    // Synthesis & Application (average of 6 sub-dimensions)
    $scores['field_synthesis_application'] = $this->calculateAverage([
      $sub_dimension_values['field_sub_reasoning'] ?? 0,
      $sub_dimension_values['field_sub_creativity'] ?? 0,
      $sub_dimension_values['field_sub_planning'] ?? 0,
      $sub_dimension_values['field_sub_learning'] ?? 0,
      $sub_dimension_values['field_sub_memory'] ?? 0,
      $sub_dimension_values['field_sub_execution'] ?? 0,
    ]);

    // Total Power (average of 5 main dimensions)
    $scores['field_total_power'] = $this->calculateAverage([
      $scores['field_information_access'],
      $scores['field_resource_control'],
      $scores['field_authority_permission'],
      $scores['field_network_position'],
      $scores['field_synthesis_application'],
    ]);

    return $scores;
  }

  /**
   * Calculates average of values, rounded to nearest integer.
   *
   * @param array $values
   *   Array of numeric values.
   *
   * @return int
   *   Rounded average.
   */
  protected function calculateAverage(array $values) {
    $values = array_filter($values, 'is_numeric');
    if (empty($values)) {
      return 0;
    }
    return (int) round(array_sum($values) / count($values));
  }

}
