<?php

namespace Drupal\agent_evaluation\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\agent_evaluation\Service\AgentEvaluationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for starting an agent evaluation.
 */
class EvaluateEntityForm extends FormBase {

  /**
   * The agent evaluation service.
   *
   * @var \Drupal\agent_evaluation\Service\AgentEvaluationService
   */
  protected $evaluationService;

  /**
   * Constructs a new EvaluateEntityForm.
   *
   * @param \Drupal\agent_evaluation\Service\AgentEvaluationService $evaluation_service
   *   The agent evaluation service.
   */
  public function __construct(AgentEvaluationService $evaluation_service) {
    $this->evaluationService = $evaluation_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('agent_evaluation.service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'agent_evaluation_evaluate_entity_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attributes']['class'][] = 'evaluate-entity-form';

    $form['intro'] = [
      '#markup' => '<div class="form-intro"><h2>' . $this->t('Evaluate an Agent\'s Power') . '</h2><p>' . 
        $this->t('Enter the name of an AI system, organization, platform, or individual to evaluate using the Agent Power Framework.') . 
        '</p></div>',
    ];

    $form['entity_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity Name'),
      '#description' => $this->t('Examples: ChatGPT, NSA, Amazon Web Services, Elon Musk'),
      '#required' => TRUE,
      '#size' => 60,
      '#maxlength' => 255,
      '#placeholder' => $this->t('Enter entity name...'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start Evaluation'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entity_name = $form_state->getValue('entity_name');

    // Create the evaluation (creates both ai_conversation and evaluated_entity nodes)
    $result = $this->evaluationService->createEvaluation($entity_name);

    if ($result['success']) {
      if ($result['existing']) {
        // Entity already exists - redirect to existing evaluation
        $this->messenger()->addStatus($this->t('An evaluation for "@entity" already exists. Showing existing evaluation.', [
          '@entity' => $entity_name,
        ]));

        $form_state->setRedirect('entity.node.canonical', [
          'node' => $result['entity_nid'],
        ]);
      }
      else {
        // New evaluation created - redirect to conversation to watch AI work
        $this->messenger()->addStatus($this->t('Started evaluation for "@entity". Watch as the AI evaluates across all dimensions...', [
          '@entity' => $entity_name,
        ]));

        // Redirect to the conversation chat interface
        $form_state->setRedirect('agent_evaluation.chat_interface', [
          'node' => $result['conversation_nid'],
        ]);
      }
    }
    else {
      $this->messenger()->addError($this->t('Failed to start evaluation: @error', [
        '@error' => $result['error'] ?? 'Unknown error',
      ]));
    }
  }

}
