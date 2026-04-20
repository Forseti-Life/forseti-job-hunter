<?php

namespace Drupal\forseti_safety_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\agent_evaluation\Service\AgentEvaluationService;

/**
 * Controller for batch processing entity evaluations.
 */
class BatchEvaluationController extends ControllerBase {

  /**
   * The agent evaluation service.
   *
   * @var \Drupal\agent_evaluation\Service\AgentEvaluationService
   */
  protected $evaluationService;

  /**
   * Constructs a new BatchEvaluationController.
   *
   * @param \Drupal\agent_evaluation\Service\AgentEvaluationService $evaluation_service
   *   The evaluation service.
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
   * Process a single entity evaluation automatically.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with evaluation result.
   */
  public function processEntity(Request $request) {
    $entity_name = $request->query->get('entity');
    
    if (empty($entity_name)) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Entity name is required',
      ], 400);
    }

    // Create the evaluation
    $result = $this->evaluationService->createEvaluation($entity_name);

    if (!$result['success']) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $result['error'] ?? 'Unknown error occurred',
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'entity_name' => $entity_name,
      'entity_nid' => $result['entity_nid'],
      'conversation_nid' => $result['conversation_nid'],
      'existing' => $result['existing'] ?? FALSE,
      'message' => $result['existing'] 
        ? "Entity '{$entity_name}' already exists" 
        : "Successfully created evaluation for '{$entity_name}'",
    ]);
  }

  /**
   * Calculate and save category average for a specific category.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with calculation result.
   */
  public function calculateCategoryAverage(Request $request) {
    $category_label = $request->query->get('category');
    
    if (empty($category_label)) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Category label is required',
      ], 400);
    }

    // Map category labels to category values
    $category_map = [
      'US Government Executive Branch' => 'us_government_executive',
      'US Military - General/Flag Officers' => 'us_military_general',
      'US Military - Field Grade Officers' => 'us_military_field',
      'US Military - Company Grade Officers' => 'us_military_company',
      'US Military - Warrant Officers' => 'us_military_warrant',
      'US Military - Senior Enlisted' => 'us_military_senior_enlisted',
      'US Military - Junior Enlisted' => 'us_military_junior_enlisted',
      'Government Agencies' => 'government_agencies',
      'Intelligence Alliances' => 'intelligence_alliances',
      'State & Local Government' => 'state_local_government',
      'Tech Companies' => 'tech_companies',
      'Cybersecurity' => 'cybersecurity',
      'Education & Learning' => 'education_learning',
      'Legal Services' => 'legal_services',
      'Professional Services' => 'professional_services',
      'Financial Institutions' => 'financial_institutions',
      'Healthcare Organizations' => 'healthcare_organizations',
      'Universities' => 'universities',
      'Research Institutions' => 'research_institutions',
      'Law Enforcement' => 'law_enforcement',
      'Transportation' => 'transportation',
      'Retail & Consumer' => 'retail_consumer',
      'Food & Hospitality' => 'food_hospitality',
      'Manufacturing & Industry' => 'manufacturing_industry',
      'Defense & Aerospace' => 'defense_aerospace',
      'Energy & Resources' => 'energy_resources',
      'Pharmaceutical' => 'pharmaceutical',
      'International Organizations' => 'international_organizations',
      'Non-Profits & Research' => 'nonprofits_research',
      'Media & Publishing' => 'media_publishing',
      'Social Platforms' => 'social_platforms',
      'Entertainment & Sports' => 'entertainment_sports',
      'Real Estate & Construction' => 'real_estate_construction',
      'Agriculture & Food Production' => 'agriculture_food',
      'Telecommunications' => 'telecommunications',
      'Logistics & Supply Chain' => 'logistics_supply_chain',
      'Insurance' => 'insurance',
      'Consumer Electronics & Appliances' => 'consumer_electronics',
      'Fitness & Wellness' => 'fitness_wellness',
      'Notable Individuals' => 'notable_individuals',
      'Standards & Certification' => 'standards_certification',
      'AI Systems & Automation' => 'ai_systems_automation',
      'Basic Services & Infrastructure' => 'basic_services_infrastructure',
    ];

    $category_value = $category_map[$category_label] ?? null;
    if (!$category_value) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => "Unknown category: {$category_label}",
      ], 400);
    }

    // Query all entities in this category
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'evaluated_entity')
      ->condition('status', 1)
      ->condition('field_entity_category', $category_value)
      ->accessCheck(FALSE);
    
    $nids = $query->execute();
    $entities = $storage->loadMultiple($nids);

    if (empty($entities)) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => "No entities found for category: {$category_label}",
      ], 404);
    }

    // Calculate averages for primary dimensions
    $dimension_fields = [
      'field_information_access',
      'field_resource_control',
      'field_authority_permission',
      'field_network_position',
      'field_synthesis_application',
    ];

    $averages = [];
    $count = count($entities);

    foreach ($dimension_fields as $field) {
      $sum = 0;
      foreach ($entities as $entity) {
        if ($entity->hasField($field)) {
          $sum += (float) ($entity->get($field)->value ?? 0);
        }
      }
      $averages[$field] = round($sum / $count, 2);
    }

    // Calculate total power average
    $total_power_sum = 0;
    foreach ($entities as $entity) {
      if ($entity->hasField('field_total_power')) {
        $total_power_sum += (float) ($entity->get('field_total_power')->value ?? 0);
      }
    }
    $averages['field_total_power'] = round($total_power_sum / $count, 2);

    // Calculate averages for sub-dimensions
    $subdimension_fields = [
      // Information Access sub-dimensions
      'field_sub_sources', 'field_sub_granularity', 'field_sub_temporal',
      'field_sub_classification', 'field_sub_data_storage', 'field_sub_memory',
      // Resource Control sub-dimensions
      'field_sub_financial', 'field_sub_computational', 'field_sub_human',
      'field_sub_network_bandwidth', 'field_sub_api_access', 'field_sub_info_flow',
      // Authority & Permission sub-dimensions
      'field_sub_legal', 'field_sub_policy', 'field_sub_budget_auth',
      'field_sub_scope', 'field_sub_override', 'field_sub_restriction',
      // Network Position sub-dimensions
      'field_sub_connectivity', 'field_sub_centrality', 'field_sub_coalition',
      'field_sub_trust_reputation', 'field_sub_institutional', 'field_sub_network_effects',
      // Synthesis & Application sub-dimensions
      'field_sub_reasoning', 'field_sub_planning', 'field_sub_creativity',
      'field_sub_learning', 'field_sub_execution', 'field_sub_audit',
    ];

    foreach ($subdimension_fields as $field) {
      $sum = 0;
      foreach ($entities as $entity) {
        if ($entity->hasField($field)) {
          $sum += (float) ($entity->get($field)->value ?? 0);
        }
      }
      $averages[$field] = round($sum / $count, 2);
    }

    // Create or update the category average entity
    $entity_title = "Average: {$category_label}";
    
    // Check if category average entity already exists
    $existing_query = $storage->getQuery()
      ->condition('type', 'evaluated_entity')
      ->condition('title', $entity_title)
      ->accessCheck(FALSE);
    
    $existing_nids = $existing_query->execute();
    
    if (!empty($existing_nids)) {
      // Update existing entity
      $entity = $storage->load(reset($existing_nids));
      $entity_nid = $entity->id();
      $is_new = FALSE;
    } else {
      // Create new entity
      $entity = $storage->create([
        'type' => 'evaluated_entity',
        'title' => $entity_title,
        'status' => 1,
      ]);
      $is_new = TRUE;
    }

    // Set all field values
    foreach ($averages as $field => $value) {
      if ($entity->hasField($field)) {
        $entity->set($field, $value);
      }
    }

    // Set category to the same category it's averaging
    if ($entity->hasField('field_entity_category')) {
      $entity->set('field_entity_category', $category_value);
    }
    
    $entity->save();
    $entity_nid = $entity->id();

    return new JsonResponse([
      'success' => TRUE,
      'category' => $category_label,
      'entity_nid' => $entity_nid,
      'entities_count' => $count,
      'is_new' => $is_new,
      'averages' => $averages,
      'message' => $is_new 
        ? "Created category average for '{$category_label}'" 
        : "Updated category average for '{$category_label}'",
    ]);
  }

}
