<?php

namespace Drupal\nfr\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure NFR settings.
 */
class NFRSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'nfr_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['nfr.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('nfr.settings');

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Settings'),
      '#open' => TRUE,
    ];

    $form['general']['enable_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Notifications'),
      '#default_value' => $config->get('enable_notifications') ?? TRUE,
      '#description' => $this->t('Enable email notifications for registry updates.'),
    ];

    $form['general']['default_certification_period'] = [
      '#type' => 'number',
      '#title' => $this->t('Default Certification Period (months)'),
      '#default_value' => $config->get('default_certification_period') ?? 24,
      '#min' => 1,
      '#max' => 120,
      '#description' => $this->t('Default period for firefighter certifications.'),
    ];

    $form['general']['require_badge_number'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require Badge Number'),
      '#default_value' => $config->get('require_badge_number') ?? FALSE,
      '#description' => $this->t('Make badge number a required field.'),
    ];

    // State Registry Integration
    $form['state_registry'] = [
      '#type' => 'details',
      '#title' => $this->t('State Cancer Registry Integration'),
      '#open' => TRUE,
    ];

    $form['state_registry']['enable_auto_linkage'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Automatic Linkage'),
      '#default_value' => $config->get('enable_auto_linkage') ?? FALSE,
      '#description' => $this->t('Automatically attempt to link cancer cases with state registries.'),
    ];

    $form['state_registry']['linkage_consent_required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require Consent for Linkage'),
      '#default_value' => $config->get('linkage_consent_required') ?? TRUE,
      '#description' => $this->t('Require explicit participant consent before linking to state registries.'),
    ];

    // USFA NERIS Integration
    $form['neris'] = [
      '#type' => 'details',
      '#title' => $this->t('USFA NERIS Integration'),
      '#open' => FALSE,
    ];

    $form['neris']['enable_neris_sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable NERIS Synchronization'),
      '#default_value' => $config->get('enable_neris_sync') ?? FALSE,
      '#description' => $this->t('Enable data synchronization with USFA NERIS.'),
    ];

    $form['neris']['neris_api_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('NERIS API Endpoint'),
      '#default_value' => $config->get('neris_api_endpoint') ?? '',
      '#description' => $this->t('URL for USFA NERIS API endpoint.'),
    ];

    $form['neris']['neris_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('NERIS API Key'),
      '#default_value' => $config->get('neris_api_key') ?? '',
      '#description' => $this->t('API key for NERIS access.'),
    ];

    // Data Export Settings
    $form['export'] = [
      '#type' => 'details',
      '#title' => $this->t('Data Export Settings'),
      '#open' => FALSE,
    ];

    $form['export']['enable_public_dashboard'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Public Dashboard'),
      '#default_value' => $config->get('enable_public_dashboard') ?? TRUE,
      '#description' => $this->t('Make summary statistics available on public dashboard.'),
    ];

    $form['export']['anonymize_exports'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Anonymize Data Exports'),
      '#default_value' => $config->get('anonymize_exports') ?? TRUE,
      '#description' => $this->t('Remove personally identifiable information from data exports.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('nfr.settings')
      ->set('enable_notifications', $form_state->getValue('enable_notifications'))
      ->set('default_certification_period', $form_state->getValue('default_certification_period'))
      ->set('require_badge_number', $form_state->getValue('require_badge_number'))
      ->set('enable_auto_linkage', $form_state->getValue('enable_auto_linkage'))
      ->set('linkage_consent_required', $form_state->getValue('linkage_consent_required'))
      ->set('enable_neris_sync', $form_state->getValue('enable_neris_sync'))
      ->set('neris_api_endpoint', $form_state->getValue('neris_api_endpoint'))
      ->set('neris_api_key', $form_state->getValue('neris_api_key'))
      ->set('enable_public_dashboard', $form_state->getValue('enable_public_dashboard'))
      ->set('anonymize_exports', $form_state->getValue('anonymize_exports'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
