<?php

namespace Drupal\copilot_agent_tracker\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Process\Process;

final class ReleaseManagementCycleForm extends FormBase {

  public function getFormId(): string {
    return 'copilot_agent_tracker_release_management_cycle';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $script = getenv('COPILOT_HQ_RELEASE_CYCLE_CONTROL_SCRIPT')
      ?: '/home/keithaumiller/copilot-sessions-hq/scripts/release-management-cycle.sh';

    $state = [
      'enabled' => TRUE,
      'updated_at' => '',
      'updated_by' => '',
      'reason' => '',
    ];

    try {
      $process = new Process([
        $script,
        'status',
        '--json',
      ]);
      $process->setTimeout(3);
      $process->run();
      if ($process->isSuccessful()) {
        $decoded = Json::decode(trim((string) $process->getOutput())) ?? [];
        if (is_array($decoded)) {
          $state = $decoded + $state;
        }
      }
    }
    catch (\Throwable) {
      // Keep defaults on failure.
    }

    $enabled = (bool) ($state['enabled'] ?? TRUE);

    $meta = [];
    if (!empty($state['updated_at'])) {
      $meta[] = $this->t('Updated: @t', ['@t' => (string) $state['updated_at']]);
    }
    if (!empty($state['updated_by'])) {
      $meta[] = $this->t('By: @u', ['@u' => (string) $state['updated_by']]);
    }
    if (!empty($state['reason'])) {
      $meta[] = $this->t('Reason: @r', ['@r' => (string) $state['reason']]);
    }

    $form['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Current release-cycle automation state'),
      '#markup' => $enabled
        ? $this->t('<div class="messages messages--status"><strong>ENABLED</strong> — release-cycle automation is active.</div>')
        : $this->t('<div class="messages messages--warning"><strong>DISABLED</strong> — release-cycle and coordinated push automation are paused.</div>'),
    ];

    $form['meta'] = [
      '#type' => 'item',
      '#title' => $this->t('Last change'),
      '#markup' => $meta ? implode('<br>', $meta) : '<em>Unknown</em>',
    ];

    $form['enabled_current'] = [
      '#type' => 'hidden',
      '#value' => $enabled ? '1' : '0',
    ];

    $form['reason'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reason (optional)'),
      '#maxlength' => 255,
      '#default_value' => '',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['toggle'] = [
      '#type' => 'submit',
      '#value' => $enabled
        ? $this->t('Stop release management cycle automation')
        : $this->t('Start release management cycle automation'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $enabled_current = ((string) $form_state->getValue('enabled_current')) === '1';
    $target_cmd = $enabled_current ? 'stop' : 'start';

    $script = getenv('COPILOT_HQ_RELEASE_CYCLE_CONTROL_SCRIPT')
      ?: '/home/keithaumiller/copilot-sessions-hq/scripts/release-management-cycle.sh';

    $reason = trim((string) $form_state->getValue('reason'));
    $username = $this->currentUser()->getAccountName();

    $cmd = [
      $script,
      $target_cmd,
      '--by',
      $username,
    ];
    if ($reason !== '') {
      $cmd[] = '--reason';
      $cmd[] = $reason;
    }

    try {
      $process = new Process($cmd);
      $process->setTimeout(10);
      $process->run();

      if (!$process->isSuccessful()) {
        $msg = trim((string) $process->getErrorOutput()) ?: trim((string) $process->getOutput());
        $this->messenger()->addError($this->t('Failed to update release-cycle automation: @m', ['@m' => mb_substr($msg, 0, 400)]));
        return;
      }

      $this->messenger()->addStatus($this->t('Release-cycle automation updated.'));
      $form_state->setRedirect('<current>');
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Failed to update release-cycle automation: @m', ['@m' => $e->getMessage()]));
    }
  }

}
