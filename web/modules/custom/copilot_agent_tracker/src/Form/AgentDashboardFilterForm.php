<?php

namespace Drupal\copilot_agent_tracker\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * GET filter form for the agent tracker dashboard.
 */
final class AgentDashboardFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'copilot_agent_tracker_agent_dashboard_filter_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $options
   *   Keys: products, roles.
   * @param array $selected
   *   Keys: product, role.
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $options = [], array $selected = []): array {
    $form['#method'] = 'get';

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filters'),
      '#open' => FALSE,
    ];

    $form['filters']['product'] = [
      '#type' => 'select',
      '#title' => $this->t('Product'),
      '#options' => ['' => $this->t('- All -')] + ($options['products'] ?? []),
      '#default_value' => (string) ($selected['product'] ?? ''),
    ];
    $form['filters']['role'] = [
      '#type' => 'select',
      '#title' => $this->t('Role'),
      '#options' => ['' => $this->t('- All -')] + ($options['roles'] ?? []),
      '#default_value' => (string) ($selected['role'] ?? ''),
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
    ];
    $form['filters']['actions']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#button_type' => 'primary',
    ];
    $form['filters']['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => \Drupal\Core\Url::fromRoute('copilot_agent_tracker.dashboard'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();

    $query = [];
    foreach (['product', 'role'] as $k) {
      $v = isset($values[$k]) ? trim((string) $values[$k]) : '';
      if ($v !== '') {
        $query[$k] = $v;
      }
    }

    $form_state->setRedirect('copilot_agent_tracker.dashboard', [], ['query' => $query]);
  }

}

