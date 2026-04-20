<?php

namespace Drupal\institutional_management\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Institutional Management settings.
 */
class InstitutionalSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['institutional_management.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'institutional_management_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('institutional_management.settings');

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Settings'),
      '#open' => TRUE,
    ];

    $form['general']['enable_api_access'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable API access for institutions'),
      '#default_value' => $config->get('enable_api_access') ?? FALSE,
      '#description' => $this->t('Allow institutions to access data via API endpoints.'),
    ];

    $form['general']['max_members_per_institution'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum members per institution'),
      '#default_value' => $config->get('max_members_per_institution') ?? 100,
      '#min' => 1,
      '#description' => $this->t('Set the maximum number of members allowed per institution.'),
    ];

    $form['compliance'] = [
      '#type' => 'details',
      '#title' => $this->t('Compliance & Reporting'),
      '#open' => FALSE,
    ];

    $form['compliance']['enable_compliance_reports'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable compliance reporting'),
      '#default_value' => $config->get('enable_compliance_reports') ?? TRUE,
      '#description' => $this->t('Generate and provide compliance reports for institutions.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('institutional_management.settings')
      ->set('enable_api_access', $form_state->getValue('enable_api_access'))
      ->set('max_members_per_institution', $form_state->getValue('max_members_per_institution'))
      ->set('enable_compliance_reports', $form_state->getValue('enable_compliance_reports'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
