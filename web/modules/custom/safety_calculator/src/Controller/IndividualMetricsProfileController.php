<?php

declare(strict_types=1);

namespace Drupal\safety_calculator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Individual Metrics Profile page.
 */
class IndividualMetricsProfileController extends ControllerBase {

  /**
   * The individual metrics repository.
   *
   * @var \Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface
   */
  protected IndividualMetricsRepositoryInterface $repository;

  /**
   * Constructs an IndividualMetricsProfileController object.
   *
   * @param \Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface $repository
   *   The individual metrics repository.
   */
  public function __construct(IndividualMetricsRepositoryInterface $repository) {
    $this->repository = $repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('safety_calculator.individual_metrics_repository')
    );
  }

  /**
   * Access check for the profile page.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   * @param \Drupal\user\UserInterface $user
   *   The user whose profile is being viewed.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, UserInterface $user) {
    // Users can only view their own metrics, or admins can view any.
    if ($account->hasPermission('administer users')) {
      return AccessResult::allowed();
    }
    
    return AccessResult::allowedIf($account->id() == $user->id())
      ->cachePerUser()
      ->addCacheableDependency($user);
  }

  /**
   * Display the individual metrics profile page.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user whose profile is being viewed.
   *
   * @return array
   *   A render array.
   */
  public function view(UserInterface $user): array {
    $userId = (int) $user->id();
    
    // Get the user's latest assessment.
    $assessment = $this->repository->getLatestAssessment($userId);
    
    if (!$assessment) {
      // No assessment yet - show empty state with Bootstrap styling.
      return $this->buildEmptyState($user);
    }
    
    $assessmentId = (int) $assessment['id'];
    
    // Get all responses grouped by dimension.
    $responsesByDimension = $this->repository->getResponsesByDimension($userId, $assessmentId);
    
    // Get all dimensions for display order.
    $allDimensions = $this->repository->getDimensions();
    
    // Build dimension data structure.
    $dimensionsData = [];
    foreach ($allDimensions as $dimension) {
      $responses = $responsesByDimension[$dimension] ?? [];
      
      // Group responses by category within dimension.
      $categories = [];
      foreach ($responses as $response) {
        $category = $response['category'];
        if (!isset($categories[$category])) {
          $categories[$category] = [
            'name' => $category,
            'metrics' => [],
          ];
        }
        
        $categories[$category]['metrics'][] = [
          'id' => $response['id'],
          'metric_id' => $response['metric_id'],
          'question_id' => $response['question_id'],
          'metric_name' => $response['metric_name'],
          'metric_description' => $response['metric_description'],
          'response_value' => $response['response_value'],
          'z_score' => $response['z_score'],
          'z_score_calculated_at' => $response['z_score_calculated_at'],
          'reference_url' => $response['reference_url'] ?? NULL,
          'population_definition' => $response['population_definition'] ?? NULL,
          'population_mean' => $response['population_mean'] ?? NULL,
          'population_stddev' => $response['population_stddev'] ?? NULL,
        ];
      }
      
      $dimensionsData[$dimension] = [
        'name' => $dimension,
        'response_count' => count($responses),
        'categories' => array_values($categories),
      ];
    }
    
    return [
      '#theme' => 'individual_metrics_profile',
      '#user' => $user,
      '#assessment' => $assessment,
      '#dimensions' => $dimensionsData,
      '#has_responses' => !empty($responsesByDimension),
      '#attached' => [
        'library' => [
          'safety_calculator/individual-metrics-profile',
        ],
      ],
    ];
  }

  /**
   * Build empty state when user has no assessment.
   */
  protected function buildEmptyState(UserInterface $user): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['container', 'my-5']],
      'hero' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['bg-light', 'text-center', 'py-5', 'rounded', 'mb-4']],
        'icon' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => '📊',
          '#attributes' => ['class' => ['fs-1', 'mb-3']],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h1',
          '#value' => $this->t('Individual Metrics Profile'),
          '#attributes' => ['class' => ['display-5', 'fw-bold', 'mb-3']],
        ],
        'subtitle' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Track your personal safety metrics and compare to population averages'),
          '#attributes' => ['class' => ['lead', 'text-muted']],
        ],
      ],
      'empty' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['text-center', 'py-5']],
        'message' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('You have not completed any individual metrics assessment yet.'),
          '#attributes' => ['class' => ['lead', 'mb-4']],
        ],
        'cta' => [
          '#type' => 'link',
          '#title' => $this->t('Start Your Assessment'),
          '#url' => \Drupal\Core\Url::fromRoute('safety_calculator.questionnaire'),
          '#attributes' => [
            'class' => ['btn', 'btn-primary', 'btn-lg'],
          ],
        ],
      ],
    ];
  }

}
