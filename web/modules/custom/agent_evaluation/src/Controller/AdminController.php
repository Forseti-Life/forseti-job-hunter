<?php

namespace Drupal\agent_evaluation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\agent_evaluation\Service\PromptManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for AI conversation administration tasks.
 */
class AdminController extends ControllerBase {

  /**
   * The prompt manager service.
   *
   * @var \Drupal\agent_evaluation\Service\PromptManager
   */
  protected $promptManager;

  /**
   * Constructs an AdminController object.
   *
   * @param \Drupal\agent_evaluation\Service\PromptManager $prompt_manager
   *   The prompt manager service.
   */
  public function __construct(PromptManager $prompt_manager) {
    $this->promptManager = $prompt_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('agent_evaluation.prompt_manager')
    );
  }

  /**
   * Force update the system prompt configuration.
   */
  public function updateSystemPrompt() {
    // Get the default Forseti system prompt from PromptManager
    $system_prompt = $this->promptManager->getBaseSystemPrompt();
    
    // Save using PromptManager
    $success = $this->promptManager->saveSystemPrompt($system_prompt);
    
    return new JsonResponse([
      'success' => $success,
      'message' => $success ? 'Forseti system prompt updated successfully' : 'Failed to update system prompt',
      'prompt_length' => strlen($system_prompt),
    ]);
  }

}