<?php

namespace Drupal\safety_calculator\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Safety Calculator settings.
 */
class SafetyCalculatorSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['safety_calculator.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'safety_calculator_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('safety_calculator.settings');

    $form['calculation'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Calculation Settings'),
    ];

    $form['calculation']['default_resolution'] = [
      '#type' => 'select',
      '#title' => $this->t('Default H3 Resolution'),
      '#options' => [
        '11' => $this->t('11 - Larger areas'),
        '12' => $this->t('12 - Medium areas'),
        '13' => $this->t('13 - Precise (default)'),
        '14' => $this->t('14 - Ultra-precise'),
      ],
      '#default_value' => $config->get('default_resolution') ?? 13,
      '#description' => $this->t('The H3 hexagon resolution to use for safety calculations.'),
    ];

    $form['calculation']['default_radius'] = [
      '#type' => 'number',
      '#title' => $this->t('Default Search Radius'),
      '#min' => 0,
      '#max' => 5,
      '#default_value' => $config->get('default_radius') ?? 1,
      '#description' => $this->t('Number of hexagon rings to include around the target location.'),
    ];

    $form['calculation']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#min' => 0,
      '#default_value' => $config->get('cache_ttl') ?? 3600,
      '#description' => $this->t('How long to cache safety score calculations. Set to 0 to disable caching.'),
    ];

    $form['scoring'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Scoring Settings'),
    ];

    $form['scoring']['base_score'] = [
      '#type' => 'number',
      '#title' => $this->t('Base Safety Score'),
      '#min' => 0,
      '#max' => 100,
      '#default_value' => $config->get('base_score') ?? 100,
      '#description' => $this->t('Starting score before crime penalties (0-100).'),
    ];

    $form['scoring']['enable_time_weighting'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable time-based weighting'),
      '#default_value' => $config->get('enable_time_weighting') ?? TRUE,
      '#description' => $this->t('Weight recent crimes more heavily than older ones.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('safety_calculator.settings')
      ->set('default_resolution', $form_state->getValue('default_resolution'))
      ->set('default_radius', $form_state->getValue('default_radius'))
      ->set('cache_ttl', $form_state->getValue('cache_ttl'))
      ->set('base_score', $form_state->getValue('base_score'))
      ->set('enable_time_weighting', $form_state->getValue('enable_time_weighting'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
