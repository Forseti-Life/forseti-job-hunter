<?php

namespace Drupal\agent_evaluation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\agent_evaluation\Service\AgentEvaluationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for agent evaluation operations.
 */
class EvaluationController extends ControllerBase {

  /**
   * The agent evaluation service.
   *
   * @var \Drupal\agent_evaluation\Service\AgentEvaluationService
   */
  protected $evaluationService;

  /**
   * Constructs a new EvaluationController object.
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
   * Creates a new evaluation via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with evaluation creation result.
   */
  public function createEvaluation(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    
    if (empty($data['entity_name'])) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Entity name is required.',
      ], 400);
    }

    $entity_name = trim($data['entity_name']);
    
    // Call the service to create the evaluation
    $result = $this->evaluationService->createEvaluation($entity_name);
    
    if ($result['success']) {
      // Build the entity node URL
      $entity_url = '/node/' . $result['entity_nid'];
      
      return new JsonResponse([
        'success' => TRUE,
        'entity_url' => $entity_url,
        'conversation_nid' => $result['conversation_nid'],
        'entity_nid' => $result['entity_nid'],
        'existing' => $result['existing'],
      ]);
    }
    else {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $result['error'] ?? 'Failed to create evaluation.',
      ], 500);
    }
  }

}
