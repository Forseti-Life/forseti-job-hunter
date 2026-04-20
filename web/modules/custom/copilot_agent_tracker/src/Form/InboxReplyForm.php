<?php

namespace Drupal\copilot_agent_tracker\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to submit a reply to an agent inbox item.
 */
final class InboxReplyForm extends FormBase {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'copilot_agent_tracker_inbox_reply_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $item_id = '', string $to_agent_id = ''): array {
    $form['item_id'] = [
      '#type' => 'hidden',
      '#value' => $item_id,
    ];
    $form['to_agent_id'] = [
      '#type' => 'hidden',
      '#value' => $to_agent_id,
    ];

    $form['reply'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reply'),
      '#required' => FALSE,
      '#default_value' => $item_id !== '' ? 'approved' : '',
      '#rows' => 8,
      '#description' => $this->t('This will be queued back to HQ for delivery to the agent.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['send'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send reply'),
      '#button_type' => 'primary',
      '#name' => 'send_reply',
    ];
    $form['actions']['resolve'] = [
      '#type' => 'submit',
      '#value' => $this->t('Resolve'),
      '#name' => 'resolve_only',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $item_id = (string) $form_state->getValue('item_id');
    $to_agent_id = (string) $form_state->getValue('to_agent_id');
    $reply = (string) $form_state->getValue('reply');

    $trigger = (string) ($form_state->getTriggeringElement()['#name'] ?? '');
    $now = (int) $this->time->getRequestTime();

    // Defensive guard: validation should have caught this.
    if ($item_id === '' || strlen($item_id) > 255) {
      $this->messenger()->addError($this->t('Unable to process: invalid inbox item id.'));
      $form_state->setRedirect('copilot_agent_tracker.waiting_on_keith');
      return;
    }

    if ($trigger === 'send_reply') {
      // At this point validateForm() guarantees reply/to_agent_id are sane.

      $this->database->insert('copilot_agent_tracker_replies')
        ->fields([
          'to_agent_id' => $to_agent_id,
          'in_reply_to' => $item_id,
          'message' => $reply,
          'created' => $now,
          'consumed' => 0,
          'consumed_at' => 0,
        ])
        ->execute();
    }

    // Immediately dismiss from the UI inbox list.
    $this->database->merge('copilot_agent_tracker_inbox_resolutions')
      ->key('item_id', $item_id)
      ->fields([
        'resolved' => 1,
        'resolved_at' => $now,
        'resolved_by_uid' => (int) $this->currentUser()->id(),
      ])
      ->execute();

    if ($trigger === 'send_reply') {
      $this->messenger()->addStatus($this->t('Reply queued for @agent; removed from inbox.', ['@agent' => $to_agent_id]));
    }
    else {
      $this->messenger()->addStatus($this->t('Marked resolved; removed from inbox.'));
    }

    $form_state->setRedirect('copilot_agent_tracker.waiting_on_keith');
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $item_id = (string) $form_state->getValue('item_id');
    $to_agent_id = (string) $form_state->getValue('to_agent_id');
    $reply = (string) $form_state->getValue('reply');
    $trigger = (string) ($form_state->getTriggeringElement()['#name'] ?? '');

    if ($item_id === '') {
      $form_state->setErrorByName('item_id', $this->t('Missing inbox item id.'));
      return;
    }
    if (strlen($item_id) > 255) {
      $form_state->setErrorByName('item_id', $this->t('Invalid inbox item id.'));
      return;
    }

    if ($trigger === 'send_reply') {
      if (trim($reply) === '') {
        $form_state->setErrorByName('reply', $this->t('Reply cannot be empty when sending.'));
        return;
      }
      if ($to_agent_id === '') {
        $form_state->setErrorByName('to_agent_id', $this->t('Missing destination agent.'));
        return;
      }
      if (strlen($to_agent_id) > 128) {
        $form_state->setErrorByName('to_agent_id', $this->t('Invalid destination agent.'));
        return;
      }
    }
  }

}
