<?php

namespace Drupal\copilot_agent_tracker\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Process\Process;

final class OrgAutomationToggleForm extends FormBase {

  public function getFormId(): string {
    return 'copilot_agent_tracker_org_automation_toggle';
  }

  /**
   * @param array $org_control
   *   Array with keys: enabled, updated_at, updated_by, reason.
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $org_control = []): array {
    $script = getenv('COPILOT_HQ_ORG_CONTROL_SCRIPT')
      ?: '/home/keithaumiller/copilot-sessions-hq/scripts/org-control.sh';

    // Prefer authoritative status from HQ (read local org-control state), since
    // published telemetry can lag by minutes.
    try {
      $process = new Process([
        $script,
        'status',
        '--json',
      ]);
      $process->setTimeout(3);
      $process->run();

      if ($process->isSuccessful()) {
        $status = Json::decode(trim((string) $process->getOutput())) ?? [];
        if (is_array($status) && $status !== []) {
          $org_control = $status + $org_control;
        }
      }
    }
    catch (\Throwable) {
      // Fall back to the passed-in status when HQ is unreachable.
    }

    $enabled = (bool) ($org_control['enabled'] ?? TRUE);
    $updated_at = (string) ($org_control['updated_at'] ?? '');
    $updated_by = (string) ($org_control['updated_by'] ?? '');
    $reason = (string) ($org_control['reason'] ?? '');

    $form['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Current state'),
      '#markup' => $enabled
        ? $this->t('<strong>ENABLED</strong>')
        : $this->t('<strong>DISABLED</strong>'),
    ];

    $meta = [];
    if ($updated_at !== '') {
      $meta[] = $this->t('Updated: @t', ['@t' => $updated_at]);
    }
    if ($updated_by !== '') {
      $meta[] = $this->t('By: @u', ['@u' => $updated_by]);
    }
    if ($reason !== '') {
      $meta[] = $this->t('Reason: @r', ['@r' => $reason]);
    }

    $form['meta'] = [
      '#type' => 'item',
      '#title' => $this->t('Last change'),
      '#markup' => $meta ? implode('<br>', $meta) : '<em>Unknown</em>',
    ];

    $form['enabled_current'] = [
      '#type' => 'hidden',
      '#value' => $enabled ? '1' : '0',
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $enabled
        ? $this->t('Disable org automation')
        : $this->t('Enable org automation'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $enabled_current = ((string) $form_state->getValue('enabled_current')) === '1';
    $target_cmd = $enabled_current ? 'disable' : 'enable';

    $script = getenv('COPILOT_HQ_ORG_CONTROL_SCRIPT')
      ?: '/home/keithaumiller/copilot-sessions-hq/scripts/org-control.sh';

    $username = $this->currentUser()->getAccountName();

    try {
      $process = new Process([
        $script,
        $target_cmd,
        '--by',
        $username,
        '--one-line',
      ]);
      $process->setTimeout(10);
      $process->run();

      $out = trim((string) $process->getOutput());
      $err = trim((string) $process->getErrorOutput());

      if (!$process->isSuccessful()) {
        $msg = $err !== '' ? $err : ($out !== '' ? $out : 'Unknown error');
        $this->messenger()->addError($this->t('Failed to update org automation: @m', ['@m' => mb_substr($msg, 0, 400)]));
        $this->getLogger('copilot_agent_tracker')->error('Org automation toggle failed: cmd=@cmd out=@out err=@err', [
          '@cmd' => $target_cmd,
          '@out' => mb_substr($out, 0, 2000),
          '@err' => mb_substr($err, 0, 2000),
        ]);
        return;
      }

      $this->messenger()->addStatus($this->t('Org automation updated: @s', ['@s' => ($out !== '' ? $out : $target_cmd)]));

      // Post/Redirect/Get so the page re-renders from updated HQ status.
      $form_state->setRedirect('<current>');
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Failed to update org automation: @m', ['@m' => $e->getMessage()]));
      $this->getLogger('copilot_agent_tracker')->error('Org automation toggle exception: @m', ['@m' => $e->getMessage()]);
    }
  }

}
