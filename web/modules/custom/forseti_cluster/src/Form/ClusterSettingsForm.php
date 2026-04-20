<?php

declare(strict_types=1);

namespace Drupal\forseti_cluster\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Cluster settings form — daemon URL and admin options (AC-2, AC-6).
 */
class ClusterSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['forseti_cluster.settings'];
  }

  public function getFormId(): string {
    return 'forseti_cluster_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['daemon_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Daemon URL'),
      '#description' => $this->t('Base URL of the local forseti-meshd daemon (e.g. http://127.0.0.1:8765).'),
      '#default_value' => \Drupal::state()->get('forseti_cluster.daemon_url', 'http://127.0.0.1:8765'),
      '#required' => TRUE,
    ];

    $form['health_check'] = [
      '#type' => 'item',
      '#markup' => $this->getDaemonHealth(\Drupal::state()->get('forseti_cluster.daemon_url', 'http://127.0.0.1:8765')),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \Drupal::state()->set('forseti_cluster.daemon_url', $form_state->getValue('daemon_url'));
    $this->messenger()->addStatus($this->t('Cluster settings saved.'));
  }

  private function getDaemonHealth(string $url): string {
    try {
      $ctx = stream_context_create(['http' => ['timeout' => 3]]);
      $body = @file_get_contents($url . '/health', false, $ctx);
      if ($body) {
        $data = json_decode($body, TRUE);
        if (($data['status'] ?? '') === 'ok') {
          return '<span style="color:green">✓ Daemon reachable at ' . htmlspecialchars($url) . '</span>';
        }
      }
    }
    catch (\Throwable $e) {}
    return '<span style="color:red">✗ Daemon not reachable at ' . htmlspecialchars($url) . '</span>';
  }

}
