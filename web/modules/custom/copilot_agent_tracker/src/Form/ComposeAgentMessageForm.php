<?php

namespace Drupal\copilot_agent_tracker\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Compose a new inbox message to any agent (via HQ reply queue).
 */
final class ComposeAgentMessageForm extends FormBase {

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
    return 'copilot_agent_tracker_compose_agent_message_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $agents
   *   Agent options keyed by agent_id.
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $agents = []): array {
    $default_to = '';
    if (isset($agents['ceo-copilot'])) {
      $default_to = 'ceo-copilot';
    }
    elseif (count($agents) === 1) {
      $default_to = (string) array_key_first($agents);
    }

    $form['to_agent_id'] = [
      '#type' => 'select',
      '#title' => $this->t('To agent'),
      '#options' => ['' => $this->t('- Select -')] + $agents,
      '#required' => TRUE,
      '#default_value' => $default_to,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#rows' => 6,
      '#description' => $this->t('This will be queued to HQ and delivered as an inbox item to the selected agent.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $to_agent_id = trim((string) $form_state->getValue('to_agent_id'));
    $message = (string) $form_state->getValue('message');
    $message = trim($message);

    if ($to_agent_id === '') {
      $form_state->setErrorByName('to_agent_id', $this->t('Please select an agent.'));
      return;
    }
    if ($message === '') {
      $form_state->setErrorByName('message', $this->t('Message cannot be empty.'));
      return;
    }

    $this->database->insert('copilot_agent_tracker_replies')
      ->fields([
        'to_agent_id' => $to_agent_id,
        'in_reply_to' => '',
        'message' => $message,
        'created' => (int) $this->time->getRequestTime(),
        'consumed' => 0,
        'consumed_at' => 0,
      ])
      ->execute();

    $this->messenger()->addStatus($this->t('Message queued for @agent.', ['@agent' => $to_agent_id]));
    $form_state->setRedirect('copilot_agent_tracker.waiting_on_keith');
  }

}

