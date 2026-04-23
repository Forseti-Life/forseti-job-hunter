<?php

namespace Drupal\copilot_agent_tracker\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\copilot_agent_tracker\Service\AuditLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Admin settings form for LangGraph Console observe settings.
 */
final class AdminSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    private readonly AuditLogger $auditLogger,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('config.factory'),
      $container->get('copilot_agent_tracker.audit_logger'),
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['copilot_agent_tracker.observe_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'copilot_agent_tracker_admin_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('copilot_agent_tracker.observe_settings');

    $form['#prefix'] = '<div class="langgraph-console-admin-settings">';
    $form['#suffix'] = '</div>';

    $form['info'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure LangGraph Console observe settings. These settings control tick history, metrics trending, drift detection, and alert management.') . '</p>',
    ];

    $form['max_tick_history'] = [
      '#type' => 'number',
      '#title' => $this->t('Max tick history'),
      '#description' => $this->t('Maximum number of recent ticks to retain in memory (1-10000).'),
      '#default_value' => $config->get('max_tick_history') ?: 1000,
      '#min' => 1,
      '#max' => 10000,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['metrics_trend_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Metrics trend window (minutes)'),
      '#description' => $this->t('Time window for calculating metrics trends in minutes (1-1440 = 1 min to 24 hours).'),
      '#default_value' => $config->get('metrics_trend_window') ?: 60,
      '#min' => 1,
      '#max' => 1440,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['drift_threshold_pct'] = [
      '#type' => 'number',
      '#title' => $this->t('Drift threshold (%)'),
      '#description' => $this->t('Performance drift alert threshold in percent (0.1-100).'),
      '#default_value' => $config->get('drift_threshold_pct') ?: 5.0,
      '#min' => 0.1,
      '#max' => 100,
      '#step' => 0.1,
      '#required' => TRUE,
    ];

    $form['alert_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Alert retention (days)'),
      '#description' => $this->t('How many days to retain alert records (1-365).'),
      '#default_value' => $config->get('alert_retention_days') ?: 30,
      '#min' => 1,
      '#max' => 365,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['canary_duration_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Canary duration (hours)'),
      '#description' => $this->t('Duration for canary deployment testing in hours (0.1-168 = 6 min to 7 days).'),
      '#default_value' => $config->get('canary_duration_hours') ?: 1.0,
      '#min' => 0.1,
      '#max' => 168,
      '#step' => 0.1,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate max_tick_history.
    $max_tick = (int) $form_state->getValue('max_tick_history');
    if ($max_tick < 1 || $max_tick > 10000) {
      $form_state->setErrorByName('max_tick_history',
        $this->t('Max tick history must be between 1 and 10000.'));
    }

    // Validate metrics_trend_window.
    $trend_window = (int) $form_state->getValue('metrics_trend_window');
    if ($trend_window < 1 || $trend_window > 1440) {
      $form_state->setErrorByName('metrics_trend_window',
        $this->t('Metrics trend window must be between 1 and 1440 minutes.'));
    }

    // Validate drift_threshold_pct.
    $drift_threshold = (float) $form_state->getValue('drift_threshold_pct');
    if ($drift_threshold < 0.1 || $drift_threshold > 100) {
      $form_state->setErrorByName('drift_threshold_pct',
        $this->t('Drift threshold must be between 0.1 and 100 percent.'));
    }

    // Validate alert_retention_days.
    $alert_retention = (int) $form_state->getValue('alert_retention_days');
    if ($alert_retention < 1 || $alert_retention > 365) {
      $form_state->setErrorByName('alert_retention_days',
        $this->t('Alert retention must be between 1 and 365 days.'));
    }

    // Validate canary_duration_hours.
    $canary_duration = (float) $form_state->getValue('canary_duration_hours');
    if ($canary_duration < 0.1 || $canary_duration > 168) {
      $form_state->setErrorByName('canary_duration_hours',
        $this->t('Canary duration must be between 0.1 and 168 hours.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $config = $this->config('copilot_agent_tracker.observe_settings');

      // Collect previous values for audit logging.
      $before_values = [
        'max_tick_history' => $config->get('max_tick_history'),
        'metrics_trend_window' => $config->get('metrics_trend_window'),
        'drift_threshold_pct' => $config->get('drift_threshold_pct'),
        'alert_retention_days' => $config->get('alert_retention_days'),
        'canary_duration_hours' => $config->get('canary_duration_hours'),
      ];

      // Collect new values.
      $after_values = [
        'max_tick_history' => (int) $form_state->getValue('max_tick_history'),
        'metrics_trend_window' => (int) $form_state->getValue('metrics_trend_window'),
        'drift_threshold_pct' => (float) $form_state->getValue('drift_threshold_pct'),
        'alert_retention_days' => (int) $form_state->getValue('alert_retention_days'),
        'canary_duration_hours' => (float) $form_state->getValue('canary_duration_hours'),
      ];

      // Save to Drupal config.
      $config->setData($after_values)->save();

      // Save to JSON fallback at $COPILOT_HQ_ROOT/admin/settings.json.
      $this->saveJsonFallback($after_values);

      // Log audit entry.
      $this->auditLogger->log(
        'observe_settings_changed',
        'observe_settings',
        $before_values,
        $after_values,
        TRUE
      );

      $this->messenger()->addStatus($this->t('LangGraph Console settings have been saved.'));
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('copilot_agent_tracker')
        ->error('Error saving admin settings: @error', ['@error' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Error saving settings. Check logs for details.'));
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Save settings to JSON fallback file.
   *
   * @param array<string, mixed> $values
   *   The settings values to save.
   */
  private function saveJsonFallback(array $values): void {
    $hq_root = rtrim((string) (getenv('COPILOT_HQ_ROOT') ?: '/home/ubuntu/forseti.life'), '/');
    $admin_dir = $hq_root . '/admin';
    $settings_file = $admin_dir . '/settings.json';

    if (!is_dir($admin_dir)) {
      if (!@mkdir($admin_dir, 0755, TRUE)) {
        throw new \RuntimeException("Cannot create directory: {$admin_dir}");
      }
    }

    $json_content = json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \RuntimeException('JSON encoding error: ' . json_last_error_msg());
    }

    if (!@file_put_contents($settings_file, $json_content, LOCK_EX)) {
      throw new \RuntimeException("Cannot write to file: {$settings_file}");
    }
  }

}
