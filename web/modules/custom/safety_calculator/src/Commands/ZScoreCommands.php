<?php

declare(strict_types=1);

namespace Drupal\safety_calculator\Commands;

use Drupal\safety_calculator\Service\ZScoreCalculationService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for z-score calculation.
 */
class ZScoreCommands extends DrushCommands {

  /**
   * The z-score calculation service.
   *
   * @var \Drupal\safety_calculator\Service\ZScoreCalculationService
   */
  protected ZScoreCalculationService $zScoreService;

  /**
   * Constructs a ZScoreCommands object.
   *
   * @param \Drupal\safety_calculator\Service\ZScoreCalculationService $zScoreService
   *   The z-score calculation service.
   */
  public function __construct(ZScoreCalculationService $zScoreService) {
    parent::__construct();
    $this->zScoreService = $zScoreService;
  }

  /**
   * Calculate z-scores for responses without z-scores.
   *
   * @param array $options
   *   Command options.
   *
   * @command safety:calculate-zscores
   * @option limit Limit the number of responses to process
   * @usage safety:calculate-zscores
   *   Calculate z-scores for all pending responses.
   * @usage safety:calculate-zscores --limit=100
   *   Calculate z-scores for up to 100 responses.
   * @aliases calc-zscores
   */
  public function calculateZScores(array $options = ['limit' => NULL]) {
    $limit = $options['limit'] ? (int) $options['limit'] : NULL;

    $this->output()->writeln('🔢 Calculating z-scores...');

    $result = $this->zScoreService->calculateAllZScores($limit);

    $this->output()->writeln('');
    $this->output()->writeln("✅ Processed: {$result['processed']}");
    $this->output()->writeln("❌ Errors: {$result['errors']}");

    if ($result['processed'] > 0) {
      $this->logger()->success("Successfully calculated {$result['processed']} z-scores.");
    }

    if ($result['errors'] > 0) {
      $this->logger()->warning("Failed to calculate {$result['errors']} z-scores.");
    }
  }

  /**
   * Recalculate all z-scores (useful when population changes).
   *
   * @param array $options
   *   Command options.
   *
   * @command safety:recalculate-zscores
   * @option limit Limit the number of responses to process
   * @usage safety:recalculate-zscores
   *   Recalculate all z-scores.
   * @usage safety:recalculate-zscores --limit=500
   *   Recalculate z-scores for up to 500 responses.
   * @aliases recalc-zscores
   */
  public function recalculateZScores(array $options = ['limit' => NULL]) {
    $limit = $options['limit'] ? (int) $options['limit'] : NULL;

    $this->output()->writeln('♻️  Recalculating all z-scores...');
    $this->output()->writeln('⚠️  This will update population statistics and recalculate based on current data.');

    $result = $this->zScoreService->recalculateAllZScores($limit);

    $this->output()->writeln('');
    $this->output()->writeln("✅ Processed: {$result['processed']}");
    $this->output()->writeln("❌ Errors: {$result['errors']}");

    if ($result['processed'] > 0) {
      $this->logger()->success("Successfully recalculated {$result['processed']} z-scores.");
    }

    if ($result['errors'] > 0) {
      $this->logger()->warning("Failed to recalculate {$result['errors']} z-scores.");
    }
  }

  /**
   * Show z-score statistics.
   *
   * @command safety:zscore-stats
   * @usage safety:zscore-stats
   *   Display z-score statistics.
   * @aliases zscore-stats
   */
  public function zscoreStats() {
    $stats = $this->zScoreService->getZScoreStatistics();

    $this->output()->writeln('');
    $this->output()->writeln('📊 Z-Score Statistics');
    $this->output()->writeln('═══════════════════════════════════════');
    $this->output()->writeln("Total Scored:     {$stats['total_scored']}");
    $this->output()->writeln("Total Unscored:   {$stats['total_unscored']}");
    $this->output()->writeln("Average Z-Score:  {$stats['avg_z_score']}");
    $this->output()->writeln("Min Z-Score:      {$stats['min_z_score']}");
    $this->output()->writeln("Max Z-Score:      {$stats['max_z_score']}");
    $this->output()->writeln("Std Dev Z-Score:  {$stats['std_dev_z_score']}");
    $this->output()->writeln('');

    // Show metrics needing calculation.
    $pending = $this->zScoreService->getMetricsNeedingCalculation();

    if (!empty($pending)) {
      $this->output()->writeln('⏳ Metrics Needing Z-Score Calculation:');
      $this->output()->writeln('───────────────────────────────────────');

      foreach (array_slice($pending, 0, 10) as $metric) {
        $this->output()->writeln("  • {$metric['metric_name']} ({$metric['data_type']}): {$metric['pending_count']} pending");
      }

      if (count($pending) > 10) {
        $remaining = count($pending) - 10;
        $this->output()->writeln("  ... and {$remaining} more metrics");
      }

      $this->output()->writeln('');
      $this->output()->writeln("Run 'drush safety:calculate-zscores' to process pending calculations.");
    }
    else {
      $this->output()->writeln('✅ All z-scores are up to date!');
    }

    $this->output()->writeln('');
  }

  /**
   * Show population statistics for a specific metric.
   *
   * @param int $metricId
   *   The metric ID.
   *
   * @command safety:metric-stats
   * @usage safety:metric-stats 1
   *   Show population statistics for metric ID 1.
   * @aliases metric-stats
   */
  public function metricStats(int $metricId) {
    $stats = $this->zScoreService->getPopulationStatistics($metricId);

    if (!$stats) {
      $this->logger()->warning("No statistics available for metric ID {$metricId}. Need at least 2 numeric responses.");
      return;
    }

    $this->output()->writeln('');
    $this->output()->writeln("📈 Population Statistics for Metric ID {$metricId}");
    $this->output()->writeln('═══════════════════════════════════════');
    $this->output()->writeln("Count:           {$stats['count']}");
    $this->output()->writeln("Mean:            {$stats['mean']}");
    $this->output()->writeln("Std Deviation:   {$stats['std_dev']}");
    $this->output()->writeln("Min:             {$stats['min']}");
    $this->output()->writeln("Max:             {$stats['max']}");
    $this->output()->writeln('');
  }

}
