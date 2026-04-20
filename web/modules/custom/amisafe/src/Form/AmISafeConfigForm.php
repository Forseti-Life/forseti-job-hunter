<?php

namespace Drupal\amisafe\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure AmISafe module settings.
 */
class AmISafeConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['amisafe.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'amisafe_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('amisafe.settings');

    $form['data_layer'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Data Layer Configuration'),
      '#description' => $this->t('Configure which data layer to use for crime analytics.'),
    ];

    $form['data_layer']['use_gold_layer'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use Gold Layer (Recommended)'),
      '#description' => $this->t('Use pre-computed aggregated data from Gold layer for optimal performance with Resolution 13 support.'),
      '#default_value' => $config->get('use_gold_layer') ?? TRUE,
    ];

    $form['resolution'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('H3 Resolution Settings'),
      '#description' => $this->t('Configure H3 hexagon resolution levels for spatial analysis.'),
    ];

    $form['resolution']['default_resolution'] = [
      '#type' => 'select',
      '#title' => $this->t('Default H3 Resolution'),
      '#description' => $this->t('Choose the default resolution level for crime map display.'),
      '#options' => [
        6 => $this->t('Resolution 6 - City-wide (36.1 km²)'),
        7 => $this->t('Resolution 7 - District (5.2 km²)'),
        8 => $this->t('Resolution 8 - Neighborhood (0.7 km²)'),
        9 => $this->t('Resolution 9 - Block Group (0.1 km²)'),
        10 => $this->t('Resolution 10 - Block (15,047 m²)'),
        11 => $this->t('Resolution 11 - Building (2,150 m²)'),
        12 => $this->t('Resolution 12 - Room-level (307 m²)'),
        13 => $this->t('Resolution 13 - Ultra-precision (44 m²)'),
      ],
      '#default_value' => $config->get('default_resolution') ?? 9,
    ];

    $form['resolution']['max_resolution'] = [
      '#type' => 'select',
      '#title' => $this->t('Maximum H3 Resolution'),
      '#description' => $this->t('Set the maximum resolution level users can access (higher resolutions require more processing power).'),
      '#options' => [
        10 => $this->t('Resolution 10 - Block level'),
        11 => $this->t('Resolution 11 - Building level'),
        12 => $this->t('Resolution 12 - Room level'),
        13 => $this->t('Resolution 13 - Ultra-precision'),
      ],
      '#default_value' => $config->get('max_resolution') ?? 13,
    ];

    $form['performance'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Performance Settings'),
      '#description' => $this->t('Configure performance limits for high-resolution data.'),
    ];

    $form['performance']['ultra_precision_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Ultra-precision Result Limit'),
      '#description' => $this->t('Maximum number of hexagons to display for Resolution 13 (ultra-precision) queries.'),
      '#default_value' => $config->get('ultra_precision_limit') ?? 2000,
      '#min' => 100,
      '#max' => 10000,
    ];

    $form['performance']['fine_precision_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Fine Precision Result Limit'),
      '#description' => $this->t('Maximum number of hexagons to display for Resolution 12 queries.'),
      '#default_value' => $config->get('fine_precision_limit') ?? 3000,
      '#min' => 100,
      '#max' => 15000,
    ];

    $form['display'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Display Settings'),
      '#description' => $this->t('Configure how crime data is displayed in the dashboard.'),
    ];

    $form['display']['show_empty_hexagons'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Empty Hexagons'),
      '#description' => $this->t('Display hexagons with no crime incidents (may impact performance at high resolutions).'),
      '#default_value' => $config->get('show_empty_hexagons') ?? FALSE,
    ];



    $form['analytics'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Analytics & Monitoring'),
      '#description' => $this->t('Configure data analytics and system monitoring.'),
    ];

    $form['analytics']['enable_ultra_precision_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Ultra-precision Logging'),
      '#description' => $this->t('Log all Resolution 13 queries for performance monitoring.'),
      '#default_value' => $config->get('enable_ultra_precision_logging') ?? TRUE,
    ];

    $form['analytics']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (minutes)'),
      '#description' => $this->t('How long to cache ultra-precision queries (affects real-time accuracy vs performance).'),
      '#default_value' => $config->get('cache_ttl') ?? 30,
      '#min' => 5,
      '#max' => 1440,
    ];

    $form['gold_layer_stats'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Gold Layer Statistics'),
      '#description' => $this->t('Current gold layer data warehouse statistics.'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    ];

    // Get current statistics
    $database = \Drupal::database();
    $total_hexagons = $database->select('amisafe_h3_aggregated')->countQuery()->execute()->fetchField();
    $resolution_13_count = $database->select('amisafe_h3_aggregated', 'h')
      ->condition('resolution', 13)
      ->countQuery()
      ->execute()
      ->fetchField();

    $form['gold_layer_stats']['stats_display'] = [
      '#type' => 'item',
      '#markup' => $this->t('<strong>Total Hexagons:</strong> @total<br><strong>Resolution 13 (Ultra-precision):</strong> @ultra<br><strong>Data Coverage:</strong> 8 resolution levels (6-13)<br><strong>Precision Range:</strong> 36.1 km² → 44 m²', [
        '@total' => number_format($total_hexagons),
        '@ultra' => number_format($resolution_13_count),
      ]),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('amisafe.settings')
      ->set('use_gold_layer', $form_state->getValue('use_gold_layer'))
      ->set('default_resolution', $form_state->getValue('default_resolution'))
      ->set('max_resolution', $form_state->getValue('max_resolution'))
      ->set('ultra_precision_limit', $form_state->getValue('ultra_precision_limit'))
      ->set('fine_precision_limit', $form_state->getValue('fine_precision_limit'))
      ->set('show_empty_hexagons', $form_state->getValue('show_empty_hexagons'))
      ->set('enable_ultra_precision_logging', $form_state->getValue('enable_ultra_precision_logging'))
      ->set('cache_ttl', $form_state->getValue('cache_ttl'))
      ->save();

    // Clear caches when settings change
    \Drupal::cache('default')->deleteAll();
    drupal_set_message($this->t('AmISafe configuration has been saved. All caches have been cleared to apply changes.'));

    parent::submitForm($form, $form_state);
  }

}