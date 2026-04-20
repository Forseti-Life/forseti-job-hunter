<?php

namespace Drupal\forseti_safety_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\forseti_safety_content\Service\AgentPowerServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Agent Power Framework pages.
 */
class AgentPowerFrameworkController extends ControllerBase {

  /**
   * The agent power service.
   *
   * @var \Drupal\forseti_safety_content\Service\AgentPowerServiceInterface
   */
  protected $agentPowerService;

  /**
   * Constructs an AgentPowerFrameworkController object.
   *
   * @param \Drupal\forseti_safety_content\Service\AgentPowerServiceInterface $agent_power_service
   *   The agent power service.
   */
  public function __construct(AgentPowerServiceInterface $agent_power_service) {
    $this->agentPowerService = $agent_power_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('forseti_safety_content.agent_power')
    );
  }

  /**
   * Agent Power Framework main page.
   */
  public function agentHierarchy() {
    $power_levels = $this->agentPowerService->getPowerLevels();
    $dimension_info = $this->agentPowerService->getDimensionInfo();
    $power_categories = $this->agentPowerService->getPowerCategories();
    
    return [
      '#theme' => 'forseti_page_agent_hierarchy',
      '#title' => $this->t('Agent Power Framework'),
      '#intro' => $this->buildIntroContent(),
      '#power_categories' => $power_categories,
      '#power_levels' => $power_levels,
      '#dimension_info' => $dimension_info,
      '#transparency_note' => $this->buildTransparencyNote(),
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['url'],
      ],
    ];
  }

  /**
   * Scope dimension detail page.
   */
  public function dimensionScope() {
    return $this->buildDimensionPage(
      'scope',
      $this->t('Scope & Breadth'),
      $this->t('Breadth of knowledge domains accessible - from universal all-domain access to narrow single-task context.'),
      $this->agentPowerService->getScopeLevels()
    );
  }

  /**
   * Restriction dimension detail page.
   */
  public function dimensionRestriction() {
    $page = $this->buildDimensionPage(
      'restriction',
      $this->t('Content Restriction'),
      $this->t('Level of content filtering applied - from zero filtering to extreme pre-approved only responses.'),
      $this->agentPowerService->getRestrictionLevels()
    );
    
    // Add sub-dimension link
    $sub_dimension_markup = '<div class="sub-dimension-section" style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-left: 4px solid #007bff;">';
    $sub_dimension_markup .= '<h3>Sub-Dimension</h3>';
    $sub_dimension_markup .= '<p><strong><a href="/agent-power-framework/restriction/classification">Classification Access</a></strong> - Sensitivity level of accessible information, from public domain to top secret data.</p>';
    $sub_dimension_markup .= '</div>';
    
    $page['sub_dimension'] = [
      '#markup' => $sub_dimension_markup,
    ];
    return $page;
  }

  /**
   * Classification dimension detail page.
   */
  public function dimensionClassification() {
    $page = $this->buildDimensionPage(
      'classification',
      $this->t('Classification Access'),
      $this->t('Level of data classification accessible - from top secret to basic public FAQs only.'),
      $this->agentPowerService->getClassificationLevels()
    );
    
    // Add breadcrumb navigation
    $breadcrumb = '<div class="breadcrumb-nav" style="margin-bottom: 20px; padding: 10px; background: #e9ecef; border-radius: 4px;">';
    $breadcrumb .= '<a href="/agent-power-framework">Agent Power Framework</a> » ';
    $breadcrumb .= '<a href="/agent-power-framework/restriction">Content Restriction</a> » ';
    $breadcrumb .= '<strong>Classification Access (Sub-dimension)</strong>';
    $breadcrumb .= '</div>';
    
    $page['breadcrumb'] = [
      '#markup' => $breadcrumb,
      '#weight' => -10,
    ];
    return $page;
  }

  /**
   * Temporal dimension detail page.
   */
  public function dimensionTemporal() {
    return $this->buildDimensionPage(
      'temporal',
      $this->t('Temporal Reach'),
      $this->t('Access to historical data and real-time feeds - from complete timeline to static snapshot.'),
      $this->agentPowerService->getTemporalLevels()
    );
  }

  /**
   * Sources dimension detail page.
   */
  public function dimensionSources() {
    return $this->buildDimensionPage(
      'sources',
      $this->t('Source Diversity'),
      $this->t('Range and diversity of information sources - from all sources globally to single internal knowledge base.'),
      $this->agentPowerService->getSourcesLevels()
    );
  }

  /**
   * Granularity dimension detail page.
   */
  public function dimensionGranularity() {
    return $this->buildDimensionPage(
      'granularity',
      $this->t('Data Granularity'),
      $this->t('Level of detail accessible - from atomic individual records to general concepts only.'),
      $this->agentPowerService->getGranularityLevels()
    );
  }

  /**
   * Authority dimension detail page.
   */
  public function dimensionAuthority() {
    return $this->buildDimensionPage(
      'authority',
      $this->t('Authority Level'),
      $this->t('System permissions and capabilities - from full read/write/execute to basic retrieval only.'),
      $this->agentPowerService->getAuthorityLevels()
    );
  }

  /**
   * Synthesis dimension detail page.
   */
  /**
   * Information Synthesis dimension detail page.
   */
  public function dimensionSynthesis() {
    return $this->buildDimensionPage(
      'synthesis',
      $this->t('Information Synthesis'),
      $this->t('Cross-domain pattern recognition and insight generation - from no synthesis to universal connections.'),
      $this->agentPowerService->getSynthesisLevels()
    );
  }

  /**
   * Creativity & Novel Generation dimension detail page.
   */
  public function dimensionCreativity() {
    return $this->buildDimensionPage(
      'creativity',
      $this->t('Creativity & Novel Generation'),
      $this->t('Producing genuinely new ideas and solutions - from template-only to breakthrough innovation.'),
      $this->agentPowerService->getCreativityLevels()
    );
  }

  /**
   * Strategic Planning Depth dimension detail page.
   */
  public function dimensionStrategicPlanning() {
    return $this->buildDimensionPage(
      'strategic_planning',
      $this->t('Strategic Planning Depth'),
      $this->t('Multi-horizon planning sophistication - from reactive-only to multi-decade scenario modeling.'),
      $this->agentPowerService->getStrategicPlanningLevels()
    );
  }

  /**
   * Decision Quality dimension detail page.
   */
  public function dimensionDecisionQuality() {
    return $this->buildDimensionPage(
      'decision_quality',
      $this->t('Decision Quality'),
      $this->t('Accuracy and appropriateness of choices under uncertainty - from random to near-optimal.'),
      $this->agentPowerService->getDecisionQualityLevels()
    );
  }

  /**
   * Adaptive Learning Rate dimension detail page.
   */
  public function dimensionAdaptiveLearning() {
    return $this->buildDimensionPage(
      'adaptive_learning',
      $this->t('Adaptive Learning Rate'),
      $this->t('Speed of learning from feedback - from static/never learns to real-time continuous improvement.'),
      $this->agentPowerService->getAdaptiveLearningLevels()
    );
  }

  /**
   * Memory Architecture dimension detail page.
   */
  public function dimensionMemoryArchitecture() {
    return $this->buildDimensionPage(
      'memory_architecture',
      $this->t('Memory Architecture'),
      $this->t('Knowledge storage, indexing, and retrieval sophistication - from no memory to perfect recall.'),
      $this->agentPowerService->getMemoryArchitectureLevels()
    );
  }

  /**
   * Verification dimension detail page.
   */
  public function dimensionVerification() {
    return $this->buildDimensionPage(
      'verification',
      $this->t('Data Verification'),
      $this->t('Level of information validation - from raw + all verification levels to pre-written only.'),
      $this->agentPowerService->getVerificationLevels()
    );
  }

  /**
   * Computational Resources dimension detail page.
   */
  public function dimensionComputationalResources() {
    return $this->buildDimensionPage(
      'computational_resources',
      $this->t('Computational Resources'),
      $this->t('Processing power, memory, storage, and GPU access for training and inference.'),
      $this->agentPowerService->getComputationalResourcesLevels()
    );
  }

  /**
   * Financial Capital dimension detail page.
   */
  public function dimensionFinancialCapital() {
    return $this->buildDimensionPage(
      'financial_capital',
      $this->t('Financial Capital'),
      $this->t('Budget availability for operations, acquisitions, and strategic initiatives.'),
      $this->agentPowerService->getFinancialCapitalLevels()
    );
  }

  /**
   * Infrastructure Access dimension detail page.
   */
  public function dimensionInfrastructureAccess() {
    return $this->buildDimensionPage(
      'infrastructure_access',
      $this->t('Infrastructure Access'),
      $this->t('Physical facilities, data centers, network bandwidth, and technological platforms.'),
      $this->agentPowerService->getInfrastructureAccessLevels()
    );
  }

  /**
   * Human Capital dimension detail page.
   */
  public function dimensionHumanCapital() {
    return $this->buildDimensionPage(
      'human_capital',
      $this->t('Human Capital'),
      $this->t('Workforce expertise, labor availability, and talent quality.'),
      $this->agentPowerService->getHumanCapitalLevels()
    );
  }

  /**
   * Energy Resources dimension detail page.
   */
  public function dimensionEnergyResources() {
    return $this->buildDimensionPage(
      'energy_resources',
      $this->t('Energy Resources'),
      $this->t('Power availability and efficiency for computation and operations.'),
      $this->agentPowerService->getEnergyResourcesLevels()
    );
  }

  /**
   * Time Allocation dimension detail page.
   */
  public function dimensionTimeAllocation() {
    return $this->buildDimensionPage(
      'time_allocation',
      $this->t('Time Allocation'),
      $this->t('Attention, priority, and temporal resources for execution.'),
      $this->agentPowerService->getTimeAllocationLevels()
    );
  }

  /**
   * Trust Network Depth dimension detail page.
   */
  public function dimensionTrustNetworkDepth() {
    return $this->buildDimensionPage(
      'trust_network_depth',
      $this->t('Trust Network Depth'),
      $this->t('Quality and strength of trusted relationships that enable coordination and cooperation.'),
      $this->agentPowerService->getTrustNetworkDepthLevels()
    );
  }

  /**
   * Dependency Relationships dimension detail page.
   */
  public function dimensionDependencyRelationships() {
    return $this->buildDimensionPage(
      'dependency_relationships',
      $this->t('Dependency Relationships'),
      $this->t('How many entities depend on this entity for critical functions or resources.'),
      $this->agentPowerService->getDependencyRelationshipsLevels()
    );
  }

  /**
   * Gatekeeping Power dimension detail page.
   */
  public function dimensionGatekeepingPower() {
    return $this->buildDimensionPage(
      'gatekeeping_power',
      $this->t('Gatekeeping Power'),
      $this->t('Control over critical pathways, approvals, or bottlenecks in systems or processes.'),
      $this->agentPowerService->getGatekeepingPowerLevels()
    );
  }

  /**
   * Influence Reach dimension detail page.
   */
  public function dimensionInfluenceReach() {
    return $this->buildDimensionPage(
      'influence_reach',
      $this->t('Influence Reach'),
      $this->t('Ability to shape opinions, decisions, and behaviors across networks.'),
      $this->agentPowerService->getInfluenceReachLevels()
    );
  }

  /**
   * Reputation Capital dimension detail page.
   */
  public function dimensionReputationCapital() {
    return $this->buildDimensionPage(
      'reputation_capital',
      $this->t('Reputation Capital'),
      $this->t('Accumulated credibility, legitimacy, and social proof that enables action.'),
      $this->agentPowerService->getReputationCapitalLevels()
    );
  }

  /**
   * Mobilization Capability dimension detail page.
   */
  public function dimensionMobilizationCapability() {
    return $this->buildDimensionPage(
      'mobilization_capability',
      $this->t('Mobilization Capability'),
      $this->t('Speed and scale at which resources and people can be coordinated for action.'),
      $this->agentPowerService->getMobilizationCapabilityLevels()
    );
  }

  /**
   * Legal Authorization dimension detail page.
   */
  public function dimensionLegalAuthorization() {
    return $this->buildDimensionPage(
      'legal_authorization',
      $this->t('Legal Authorization'),
      $this->t('Licensed, certified, or legally permitted activities and professional standing.'),
      $this->agentPowerService->getLegalAuthorizationLevels()
    );
  }

  /**
   * Decision-Making Scope dimension detail page.
   */
  public function dimensionDecisionMakingScope() {
    return $this->buildDimensionPage(
      'decision_making_scope',
      $this->t('Decision-Making Scope'),
      $this->t('Range and significance of decisions the entity is authorized to make.'),
      $this->agentPowerService->getDecisionMakingScopeLevels()
    );
  }

  /**
   * Budget Authority dimension detail page.
   */
  public function dimensionBudgetAuthority() {
    return $this->buildDimensionPage(
      'budget_authority',
      $this->t('Budget Authority'),
      $this->t('Financial resources the entity can approve and commit without additional authorization.'),
      $this->agentPowerService->getBudgetAuthorityLevels()
    );
  }

  /**
   * Jurisdictional Reach dimension detail page.
   */
  public function dimensionJurisdictionalReach() {
    return $this->buildDimensionPage(
      'jurisdictional_reach',
      $this->t('Jurisdictional Reach'),
      $this->t('Geographic and organizational scope where authority applies.'),
      $this->agentPowerService->getJurisdictionalReachLevels()
    );
  }

  /**
   * Enforcement Power dimension detail page.
   */
  public function dimensionEnforcementPower() {
    return $this->buildDimensionPage(
      'enforcement_power',
      $this->t('Enforcement Power'),
      $this->t('Ability to compel compliance and impose consequences for violations.'),
      $this->agentPowerService->getEnforcementPowerLevels()
    );
  }

  /**
   * Moral Authority dimension detail page.
   */
  public function dimensionMoralAuthority() {
    return $this->buildDimensionPage(
      'moral_authority',
      $this->t('Moral Authority'),
      $this->t('Ethical legitimacy, social credibility, and moral standing that enables action.'),
      $this->agentPowerService->getMoralAuthorityLevels()
    );
  }

  /**
   * Evaluation Matrix page.
   */
  public function evaluationMatrix() {
    $entities = $this->agentPowerService->getEntityEvaluations();
    
    $content = '<div class="evaluation-matrix-page">';
    $content .= '<h1>Entity Evaluation Matrix</h1>';
    $content .= '<p class="intro">Comprehensive evaluation of 110 entities across 9 critical dimensions of agent safety and governance.</p>';
    
    $content .= '<div class="evaluation-table-wrapper">';
    $content .= '<table class="evaluation-matrix">';
    $content .= '<thead><tr>';
    $content .= '<th>Entity</th>';
    $content .= '<th>Type</th>';
    $content .= '<th>Technical</th>';
    $content .= '<th>Governance</th>';
    $content .= '<th>Transparency</th>';
    $content .= '<th>Safety</th>';
    $content .= '<th>Ethics</th>';
    $content .= '<th>Implementation</th>';
    $content .= '<th>Innovation</th>';
    $content .= '<th>Impact</th>';
    $content .= '<th>Overall</th>';
    $content .= '</tr></thead><tbody>';
    
    foreach ($entities as $entity) {
      $content .= '<tr>';
      $content .= '<td><strong>' . $entity['name'] . '</strong></td>';
      $content .= '<td>' . $entity['type'] . '</td>';
      $content .= '<td>' . $entity['technical'] . '</td>';
      $content .= '<td>' . $entity['governance'] . '</td>';
      $content .= '<td>' . $entity['transparency'] . '</td>';
      $content .= '<td>' . $entity['safety'] . '</td>';
      $content .= '<td>' . $entity['ethics'] . '</td>';
      $content .= '<td>' . $entity['implementation'] . '</td>';
      $content .= '<td>' . $entity['innovation'] . '</td>';
      $content .= '<td>' . $entity['impact'] . '</td>';
      $content .= '<td><strong>' . $entity['overall'] . '</strong></td>';
      $content .= '</tr>';
    }
    
    $content .= '</tbody></table>';
    $content .= '</div></div>';
    
    return [
      '#markup' => $content,
      '#attached' => [
        'library' => ['forseti_safety_content/evaluation-matrix'],
      ],
    ];
  }

  /**
   * All Dimensions page.
   */
  public function allDimensions() {
    $dimensions = $this->agentPowerService->getAllDimensionsList();
    
    $content = '<div class="all-dimensions-page">';
    $content .= '<h1>All Agent Evaluation Dimensions</h1>';
    $content .= '<p class="intro">Comprehensive framework of 74 dimensions across 10 categories for evaluating agent systems.</p>';
    
    foreach ($dimensions as $category => $items) {
      $content .= '<div class="dimension-category">';
      $content .= '<h2>' . $category . '</h2>';
      $content .= '<ul>';
      foreach ($items as $item) {
        $content .= '<li>';
        if (isset($item['link'])) {
          $content .= '<a href="' . $item['link'] . '">' . $item['name'] . '</a>';
        }
        else {
          $content .= $item['name'];
        }
        $content .= '</li>';
      }
      $content .= '</ul>';
      $content .= '</div>';
    }
    
    $content .= '</div>';
    
    return [
      '#markup' => $content,
      '#attached' => [
        'library' => ['forseti_safety_content/dimensions'],
      ],
    ];
  }

  /**
   * Information Access category page.
   */
  public function informationAccess() {
    $content = '<div class="container py-5">';
    $content .= '<div class="row"><div class="col-lg-10 mx-auto">';
    
    $content .= '<nav aria-label="breadcrumb" class="mb-3">';
    $content .= '<ol class="breadcrumb">';
    $content .= '<li class="breadcrumb-item"><a href="/" class="link-cyan">Home</a></li>';
    $content .= '<li class="breadcrumb-item"><a href="/agent-power-framework" class="link-cyan">Agent Power Framework</a></li>';
    $content .= '<li class="breadcrumb-item active text-cyan" aria-current="page">Information Access</li>';
    $content .= '</ol></nav>';
    
    $content .= '<h1 class="text-cyan mb-4">Information Access</h1>';
    $content .= '<p class="lead text-muted-light mb-4">The foundation of power: what you can know that others cannot. These six dimensions measure an entity\'s ability to access, process, and validate information across domains, time, sources, and sensitivity levels.</p>';
    
    $content .= '<div class="alert alert-info-cyan mb-5">';
    $content .= '<h4 class="text-cyan">Why Information Access Matters</h4>';
    $content .= '<p class="mb-0">Information access is the most fundamental form of power because it enables all other forms. An entity with superior information access can make better decisions, identify opportunities faster, understand threats earlier, and act more strategically than competitors. Control of information flow has always been central to institutional power - from ancient libraries to modern intelligence agencies.</p>';
    $content .= '</div>';
    
    $content .= '<h2 class="text-cyan mb-4">Six Information Access Dimensions</h2>';
    
    $dimensions = [
      ['name' => 'Scope & Breadth', 'link' => '/agent-power-framework/scope', 'desc' => 'Breadth of knowledge domains accessible', 'icon' => 'fa-globe'],
      ['name' => 'Content Restriction', 'link' => '/agent-power-framework/restriction', 'desc' => 'Level of content filtering applied', 'icon' => 'fa-filter'],
      ['name' => 'Temporal Reach', 'link' => '/agent-power-framework/temporal', 'desc' => 'Access to historical data and real-time feeds', 'icon' => 'fa-clock'],
      ['name' => 'Source Diversity', 'link' => '/agent-power-framework/sources', 'desc' => 'Range and diversity of information sources', 'icon' => 'fa-network-wired'],
      ['name' => 'Data Granularity', 'link' => '/agent-power-framework/granularity', 'desc' => 'Level of detail accessible', 'icon' => 'fa-microscope'],
      ['name' => 'Data Verification', 'link' => '/agent-power-framework/verification', 'desc' => 'Level of information validation', 'icon' => 'fa-check-circle'],
    ];
    
    $content .= '<div class="row row-cols-1 row-cols-md-2 g-4 mb-5">';
    foreach ($dimensions as $dim) {
      $content .= '<div class="col">';
      $content .= '<a href="' . $dim['link'] . '" class="text-decoration-none">';
      $content .= '<div class="card card-forseti h-100 p-4 hover-lift">';
      $content .= '<div class="d-flex align-items-start mb-3">';
      $content .= '<i class="fas ' . $dim['icon'] . ' fa-2x text-cyan me-3"></i>';
      $content .= '<div class="flex-grow-1">';
      $content .= '<h4 class="text-cyan mb-1">' . $dim['name'] . '</h4>';
      $content .= '</div>';
      $content .= '</div>';
      $content .= '<p class="text-muted-light mb-0">' . $dim['desc'] . '</p>';
      $content .= '</div>';
      $content .= '</a>';
      $content .= '</div>';
    }
    $content .= '</div>';
    
    $content .= '<div class="text-center mt-5">';
    $content .= '<a href="/agent-power-framework" class="btn btn-primary"><i class="fas fa-arrow-left me-2"></i>Return to Agent Power Framework</a>';
    $content .= '</div>';
    
    $content .= '</div></div></div>';
    
    return [
      '#markup' => $content,
      '#attached' => [
        'library' => ['forseti_safety_content/agent-power'],
      ],
    ];
  }

  /**
   * Resource Control category page.
   */
  public function resourceControl() {
    $content = '<div class="container py-5">';
    $content .= '<div class="row"><div class="col-lg-10 mx-auto">';
    
    $content .= '<nav aria-label="breadcrumb" class="mb-3">';
    $content .= '<ol class="breadcrumb">';
    $content .= '<li class="breadcrumb-item"><a href="/" class="link-cyan">Home</a></li>';
    $content .= '<li class="breadcrumb-item"><a href="/agent-power-framework" class="link-cyan">Agent Power Framework</a></li>';
    $content .= '<li class="breadcrumb-item active text-cyan" aria-current="page">Resource Control</li>';
    $content .= '</ol></nav>';
    
    $content .= '<h1 class="text-cyan mb-4">Resource Control</h1>';
    $content .= '<p class="lead text-muted-light mb-4">Material power: the computational, financial, and infrastructure resources available to act on information. Having perfect knowledge means nothing without the resources to execute.</p>';
    
    $content .= '<div class="alert alert-info-cyan mb-5">';
    $content .= '<h4 class="text-cyan">Why Resource Control Matters</h4>';
    $content .= '<p class="mb-0">Resource control determines what an entity can actually <em>do</em> with information. The best intelligence means nothing without compute to process it, budget to act on it, infrastructure to scale it, people to execute it, energy to power it, and time to focus on it. These six dimensions measure an entity\'s material capability to transform knowledge into action.</p>';
    $content .= '</div>';
    
    $content .= '<h2 class="text-cyan mb-4">Six Resource Control Dimensions</h2>';
    
    $dimensions = [
      ['name' => 'Computational Resources', 'icon' => 'fa-microchip', 'link' => '/agent-power-framework/computational-resources', 'desc' => 'Processing power, memory, storage, and GPU access'],
      ['name' => 'Financial Capital', 'icon' => 'fa-dollar-sign', 'link' => '/agent-power-framework/financial-capital', 'desc' => 'Budget availability for operations and initiatives'],
      ['name' => 'Infrastructure Access', 'icon' => 'fa-building', 'link' => '/agent-power-framework/infrastructure-access', 'desc' => 'Physical facilities, data centers, network bandwidth'],
      ['name' => 'Human Capital', 'icon' => 'fa-users', 'link' => '/agent-power-framework/human-capital', 'desc' => 'Workforce expertise, labor availability, talent quality'],
      ['name' => 'Energy Resources', 'icon' => 'fa-bolt', 'link' => '/agent-power-framework/energy-resources', 'desc' => 'Power availability and efficiency for computation'],
      ['name' => 'Time Allocation', 'icon' => 'fa-clock', 'link' => '/agent-power-framework/time-allocation', 'desc' => 'Attention, priority, and temporal resources'],
    ];
    
    $content .= '<div class="row row-cols-1 row-cols-md-2 g-4 mb-5">';
    foreach ($dimensions as $dim) {
      $content .= '<div class="col">';
      $content .= '<a href="' . $dim['link'] . '" class="text-decoration-none">';
      $content .= '<div class="card card-forseti h-100 p-4 hover-lift">';
      $content .= '<div class="d-flex align-items-start mb-3">';
      $content .= '<i class="fas ' . $dim['icon'] . ' fa-2x text-cyan me-3"></i>';
      $content .= '<div class="flex-grow-1">';
      $content .= '<h4 class="text-cyan mb-1">' . $dim['name'] . '</h4>';
      $content .= '</div></div>';
      $content .= '<p class="text-muted-light mb-0">' . $dim['desc'] . '</p>';
      $content .= '</div></a>';
      $content .= '</div>';
    }
    $content .= '</div>';
    
    $content .= '<div class="text-center mt-5">';
    $content .= '<a href="/agent-power-framework" class="btn btn-primary"><i class="fas fa-arrow-left me-2"></i>Return to Agent Power Framework</a>';
    $content .= '</div>';
    
    $content .= '</div></div></div>';
    
    return [
      '#markup' => $content,
      '#attached' => [
        'library' => ['forseti_safety_content/agent-power'],
      ],
    ];
  }

  /**
   * Network Position category page.
   */
  public function networkPosition() {
    $content = '<div class="container py-5">';
    $content .= '<div class="row"><div class="col-lg-10 mx-auto">';
    
    $content .= '<nav aria-label="breadcrumb" class="mb-3">';
    $content .= '<ol class="breadcrumb">';
    $content .= '<li class="breadcrumb-item"><a href="/" class="link-cyan">Home</a></li>';
    $content .= '<li class="breadcrumb-item"><a href="/agent-power-framework" class="link-cyan">Agent Power Framework</a></li>';
    $content .= '<li class="breadcrumb-item active text-cyan" aria-current="page">Network Position</li>';
    $content .= '</ol></nav>';
    
    $content .= '<h1 class="text-cyan mb-4">Network Position</h1>';
    $content .= '<p class="lead text-muted-light mb-4">Social power: the trust networks, dependencies, and influence pathways that amplify individual capability. Who you can mobilize matters as much as what you know.</p>';
    
    $content .= '<div class="alert alert-info-cyan mb-5">';
    $content .= '<h4 class="text-cyan">Why Network Position Matters</h4>';
    $content .= '<p class="mb-0">Network position is the multiplier of all other power. An entity with perfect information, unlimited resources, full authority, and brilliant synthesis can still fail without networks. Trust enables coordination, dependencies create leverage, gatekeeping controls access, influence shapes behavior, reputation opens doors, and mobilization converts potential into action. These six dimensions measure social power—the force multiplier that determines whether individual capability becomes collective impact.</p>';
    $content .= '</div>';
    
    $content .= '<h2 class="text-cyan mb-4">Six Network Position Dimensions</h2>';
    
    $dimensions = [
      ['name' => 'Trust Network Depth', 'icon' => 'fa-handshake', 'link' => '/agent-power-framework/trust-network-depth', 'desc' => 'Quality and strength of trusted relationships'],
      ['name' => 'Dependency Relationships', 'icon' => 'fa-link', 'link' => '/agent-power-framework/dependency-relationships', 'desc' => 'How many entities depend on this entity'],
      ['name' => 'Gatekeeping Power', 'icon' => 'fa-key', 'link' => '/agent-power-framework/gatekeeping-power', 'desc' => 'Control over critical pathways and bottlenecks'],
      ['name' => 'Influence Reach', 'icon' => 'fa-bullhorn', 'link' => '/agent-power-framework/influence-reach', 'desc' => 'Ability to shape opinions and decisions'],
      ['name' => 'Reputation Capital', 'icon' => 'fa-star', 'link' => '/agent-power-framework/reputation-capital', 'desc' => 'Accumulated credibility and social proof'],
      ['name' => 'Mobilization Capability', 'icon' => 'fa-rocket', 'link' => '/agent-power-framework/mobilization-capability', 'desc' => 'Speed and scale of resource coordination'],
    ];
    
    $content .= '<div class="row row-cols-1 row-cols-md-2 g-4 mb-5">';
    foreach ($dimensions as $dim) {
      $content .= '<div class="col">';
      $content .= '<a href="' . $dim['link'] . '" class="text-decoration-none">';
      $content .= '<div class="card card-forseti h-100 p-4 hover-lift">';
      $content .= '<div class="d-flex align-items-start mb-3">';
      $content .= '<i class="fas ' . $dim['icon'] . ' fa-2x text-cyan me-3"></i>';
      $content .= '<div class="flex-grow-1">';
      $content .= '<h4 class="text-cyan mb-1">' . $dim['name'] . '</h4>';
      $content .= '</div></div>';
      $content .= '<p class="text-muted-light mb-0">' . $dim['desc'] . '</p>';
      $content .= '</div></a>';
      $content .= '</div>';
    }
    $content .= '</div>';
    
    $content .= '<div class="text-center mt-5">';
    $content .= '<a href="/agent-power-framework" class="btn btn-primary"><i class="fas fa-arrow-left me-2"></i>Return to Agent Power Framework</a>';
    $content .= '</div>';
    
    $content .= '</div></div></div>';
    
    return [
      '#markup' => $content,
      '#attached' => [
        'library' => ['forseti_safety_content/agent-power'],
      ],
    ];
  }

  /**
   * Authority & Permission category page.
   */
  public function authorityCategory() {
    $content = '<div class="container py-5">';
    $content .= '<div class="row"><div class="col-lg-10 mx-auto">';
    
    $content .= '<nav aria-label="breadcrumb" class="mb-3">';
    $content .= '<ol class="breadcrumb">';
    $content .= '<li class="breadcrumb-item"><a href="/" class="link-cyan">Home</a></li>';
    $content .= '<li class="breadcrumb-item"><a href="/agent-power-framework" class="link-cyan">Agent Power Framework</a></li>';
    $content .= '<li class="breadcrumb-item active text-cyan" aria-current="page">Authority & Permission</li>';
    $content .= '</ol></nav>';
    
    $content .= '<h1 class="text-cyan mb-4">Authority & Permission</h1>';
    $content .= '<p class="lead text-muted-light mb-4">Legitimacy to act: legal rights, social permissions, and institutional backing. What you are allowed to do regardless of capability. The difference between can and may.</p>';
    
    $content .= '<div class="alert alert-info-cyan mb-5">';
    $content .= '<h4 class="text-cyan">Why Authority Matters</h4>';
    $content .= '<p class="mb-0">Having information and resources means nothing without the legitimate authority to act. A doctor with perfect diagnostic knowledge cannot prescribe without a medical license. An agent system with access to all data cannot execute transactions without proper permissions. Authority transforms capability into legitimate action across legal, organizational, financial, geographic, coercive, and moral dimensions—it\'s the social contract that enables power to be exercised without constant resistance.</p>';
    $content .= '</div>';
    
    $content .= '<h2 class="text-cyan mb-4">Six Authority Dimensions</h2>';
    
    $dimensions = [
      ['name' => 'Legal Authorization', 'icon' => 'fa-gavel', 'link' => '/agent-power-framework/legal-authorization', 'desc' => 'Licensed, certified, or legally permitted activities'],
      ['name' => 'Decision-Making Scope', 'icon' => 'fa-sitemap', 'link' => '/agent-power-framework/decision-making-scope', 'desc' => 'Range and significance of authorized decisions'],
      ['name' => 'Budget Authority', 'icon' => 'fa-wallet', 'link' => '/agent-power-framework/budget-authority', 'desc' => 'Financial resources that can be committed'],
      ['name' => 'Jurisdictional Reach', 'icon' => 'fa-map-marked-alt', 'link' => '/agent-power-framework/jurisdictional-reach', 'desc' => 'Geographic and organizational scope'],
      ['name' => 'Enforcement Power', 'icon' => 'fa-shield-alt', 'link' => '/agent-power-framework/enforcement-power', 'desc' => 'Ability to compel compliance and impose consequences'],
      ['name' => 'Moral Authority', 'icon' => 'fa-balance-scale', 'link' => '/agent-power-framework/moral-authority', 'desc' => 'Ethical legitimacy and social credibility'],
    ];
    
    $content .= '<div class="row row-cols-1 row-cols-md-2 g-4 mb-5">';
    foreach ($dimensions as $dim) {
      $content .= '<div class="col">';
      $content .= '<a href="' . $dim['link'] . '" class="text-decoration-none">';
      $content .= '<div class="card card-forseti h-100 p-4 hover-lift">';
      $content .= '<div class="d-flex align-items-start mb-3">';
      $content .= '<i class="fas ' . $dim['icon'] . ' fa-2x text-cyan me-3"></i>';
      $content .= '<div class="flex-grow-1">';
      $content .= '<h4 class="text-cyan mb-1">' . $dim['name'] . '</h4>';
      $content .= '</div></div>';
      $content .= '<p class="text-muted-light mb-0">' . $dim['desc'] . '</p>';
      $content .= '</div></a>';
      $content .= '</div>';
    }
    $content .= '</div>';
    
    $content .= '<div class="alert alert-warning-cyan mb-0">';
    $content .= '<h5 class="text-cyan mb-3"><i class="fas fa-lightbulb me-2"></i>Authority vs. Capability</h5>';
    $content .= '<p class="mb-0">These dimensions measure <strong>what you\'re allowed to do</strong>, not <strong>what you can do</strong>. An agent system might have technical capability to execute trades (Resource Control), but without proper regulatory licenses (Legal Authorization) and financial limits (Budget Authority), those actions would be illegitimate. Authority without resources is powerless; resources without authority are illegitimate. Together they enable legitimate, effective action.</p>';
    $content .= '</div>';
    
    $content .= '<div class="text-center mt-5">';
    $content .= '<a href="/agent-power-framework" class="btn btn-primary"><i class="fas fa-arrow-left me-2"></i>Return to Agent Power Framework</a>';
    $content .= '</div>';
    
    $content .= '</div></div></div>';
    
    return [
      '#markup' => $content,
      '#attached' => [
        'library' => ['forseti_safety_content/agent-power'],
      ],
    ];
  }

  /**
   * Synthesis & Application category page.
   */
  public function synthesisCategory() {
    $content = '<div class="container py-5">';
    $content .= '<div class="row"><div class="col-lg-10 mx-auto">';
    
    $content .= '<nav aria-label="breadcrumb" class="mb-3">';
    $content .= '<ol class="breadcrumb">';
    $content .= '<li class="breadcrumb-item"><a href="/" class="link-cyan">Home</a></li>';
    $content .= '<li class="breadcrumb-item"><a href="/agent-power-framework" class="link-cyan">Agent Power Framework</a></li>';
    $content .= '<li class="breadcrumb-item active text-cyan" aria-current="page">Synthesis & Application</li>';
    $content .= '</ol></nav>';
    
    $content .= '<h1 class="text-cyan mb-4">Synthesis & Application</h1>';
    $content .= '<p class="lead text-muted-light mb-4">Cognitive algorithm quality: the sophistication of thinking processes independent of computational resources. These dimensions measure how well an entity reasons, learns, plans, and creates.</p>';
    
    $content .= '<div class="alert alert-info-cyan mb-5">';
    $content .= '<h4 class="text-cyan">Why Cognitive Algorithms Matter</h4>';
    $content .= '<p class="mb-0">You can have perfect information access, unlimited computational resources, full authority, and vast networks—but without sophisticated cognitive algorithms, power remains unrealized. A brilliant algorithm on limited hardware outperforms a mediocre algorithm on infinite compute. This category measures the quality of your mental software: pattern recognition, creativity, planning, decision-making, learning, and memory architecture. It\'s not about how fast you think (Resource Control) or what you know (Information Access)—it\'s about how well you think.</p>';
    $content .= '</div>';
    
    $content .= '<h2 class="text-cyan mb-4">Six Cognitive Algorithm Dimensions</h2>';
    
    $dimensions = [
      ['name' => 'Information Synthesis', 'icon' => 'fa-brain', 'link' => '/agent-power-framework/information-synthesis', 'desc' => 'Cross-domain pattern recognition, connecting disparate information, generating novel insights'],
      ['name' => 'Creativity & Novel Generation', 'icon' => 'fa-lightbulb', 'link' => '/agent-power-framework/creativity-generation', 'desc' => 'Producing genuinely new ideas, hypothesis generation, innovation algorithms'],
      ['name' => 'Strategic Planning Depth', 'icon' => 'fa-chess', 'link' => '/agent-power-framework/strategic-planning', 'desc' => 'Multi-step planning across time horizons, scenario modeling, uncertainty handling'],
      ['name' => 'Decision Quality', 'icon' => 'fa-balance-scale-right', 'link' => '/agent-power-framework/decision-quality', 'desc' => 'Accuracy and appropriateness of choices under uncertainty and constraint'],
      ['name' => 'Adaptive Learning Rate', 'icon' => 'fa-chart-line', 'link' => '/agent-power-framework/adaptive-learning', 'desc' => 'Speed of learning from feedback, meta-learning, continuous improvement'],
      ['name' => 'Memory Architecture', 'icon' => 'fa-database', 'link' => '/agent-power-framework/memory-architecture', 'desc' => 'Storage, indexing, and retrieval algorithms for knowledge management'],
    ];
    
    $content .= '<div class="row row-cols-1 row-cols-md-2 g-4 mb-5">';
    foreach ($dimensions as $dim) {
      $content .= '<div class="col">';
      $content .= '<a href="' . $dim['link'] . '" class="text-decoration-none">';
      $content .= '<div class="card card-forseti h-100 p-4 hover-lift">';
      $content .= '<div class="d-flex align-items-start mb-3">';
      $content .= '<i class="fas ' . $dim['icon'] . ' fa-2x text-cyan me-3"></i>';
      $content .= '<div class="flex-grow-1">';
      $content .= '<h4 class="text-cyan mb-1">' . $dim['name'] . '</h4>';
      $content .= '</div></div>';
      $content .= '<p class="text-muted-light mb-0">' . $dim['desc'] . '</p>';
      $content .= '</div></a>';
      $content .= '</div>';
    }
    $content .= '</div>';
    
    $content .= '<div class="text-center mt-5">';
    $content .= '<a href="/agent-power-framework" class="btn btn-primary"><i class="fas fa-arrow-left me-2"></i>Return to Agent Power Framework</a>';
    $content .= '</div>';
    
    $content .= '</div></div></div>';
    
    return [
      '#markup' => $content,
      '#attached' => [
        'library' => ['forseti_safety_content/agent-power'],
      ],
    ];
  }

  /**
   * Helper method to build dimension detail pages.
   */
  private function buildDimensionPage($dimension_id, $dimension_name, $dimension_description, $levels) {
    return [
      '#theme' => 'forseti_page_dimension',
      '#dimension_id' => $dimension_id,
      '#dimension_name' => $dimension_name,
      '#dimension_description' => $dimension_description,
      '#levels' => $levels,
      '#back_link' => '/agent-power-framework',
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['url'],
      ],
    ];
  }

  /**
   * Agent evaluation tool page.
   */
  public function evaluate() {
    // Get entity name from query parameter if provided
    $entity_name = \Drupal::request()->query->get('entity');
    
    return [
      '#theme' => 'forseti_page_agent_evaluate',
      '#title' => $this->t('Evaluate an Agent'),
      '#intro' => [
        'description' => $this->t('Use our automated evaluation tool to assess agent capabilities across all five dimensions and 30 sub-dimensions. This tool will help you understand the power profile of any agent system.'),
      ],
      '#entity_name' => $entity_name,
      '#cache' => [
        'max-age' => 3600,
        'contexts' => ['url', 'url.query_args'],
      ],
    ];
  }

  /**
   * Build introduction content for agent hierarchy.
   */
  private function buildIntroContent() {
    return [
      'lead' => $this->t('A framework for understanding agent capabilities across five fundamental dimensions and 30 measurable sub-dimensions. This system automates analyst research and evaluation, providing rapid assessments of entities, organizations, and AI systems. All evaluations are high-level assessments that may vary depending on the LLM used for analysis and the information available at the time of evaluation.'),
      'title' => '',
      'paragraphs' => [],
    ];
  }

  /**
   * Build transparency note content.
   */
  private function buildTransparencyNote() {
    return $this->t('Forseti aspires to operate at the highest capability levels across all five dimensions - seeking unrestricted information access, adequate resources, appropriate authority, strong network position, and sophisticated synthesis capability. However, we acknowledge that all systems operate under constraints. Our goal is transparency about our actual capability profile across these 30 sub-dimensions and continuous work toward higher levels of access, resources, authority, connectivity, and analytical sophistication to serve community safety through comprehensive intelligence.');
  }

  /**
   * Evaluations list page showing all evaluated entities.
   */
  public function evaluationsList() {
    // Get sort parameter from URL
    $request = \Drupal::request();
    $sort_field = $request->query->get('sort', 'title');
    $sort_direction = $request->query->get('order', 'ASC');
    
    // Get category sort parameters
    $category_sort = $request->query->get('category_sort', 'total_power');
    $category_order = $request->query->get('category_order', 'DESC');
    
    // Validate sort direction
    $sort_direction = strtoupper($sort_direction) === 'DESC' ? 'DESC' : 'ASC';
    $category_order = strtoupper($category_order) === 'DESC' ? 'DESC' : 'ASC';
    
    // Map sort field to actual field names
    $field_map = [
      'title' => 'title',
      'information_access' => 'field_information_access',
      'resource_control' => 'field_resource_control',
      'authority_permission' => 'field_authority_permission',
      'network_position' => 'field_network_position',
      'synthesis_application' => 'field_synthesis_application',
      'total_power' => 'field_total_power',
    ];
    
    $sort_field_name = $field_map[$sort_field] ?? 'title';
    
    // Query for all published evaluated_entity nodes
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', 'evaluated_entity')
      ->condition('status', 1)
      ->sort($sort_field_name, $sort_direction)
      ->accessCheck(TRUE);
    
    $nids = $query->execute();
    $entities = $storage->loadMultiple($nids);
    
    // Build table rows grouped by category and entity data for visualization
    $categories = [];
    $entities_data = [];
    $category_counts = [];
    
    foreach ($entities as $entity) {
      $entity_title = $entity->getTitle();
      
      // Check if this is a category average entity
      $is_average = strpos($entity_title, 'Average: ') === 0;
      
      // Skip category average entities in the main list (but not in visualization)
      if (!$is_average) {
        $category_value = $entity->get('field_entity_category')->value ?? 'uncategorized';
        $category_label = $entity->get('field_entity_category')->value 
          ? $this->getFieldLabel('node', 'evaluated_entity', 'field_entity_category', $category_value)
          : 'Uncategorized';
        
        // Initialize category if not exists
        if (!isset($categories[$category_value])) {
          $categories[$category_value] = [
            'label' => $category_label,
            'value' => $category_value,
            'rows' => [],
            'average' => NULL,
          ];
          $category_counts[$category_value] = 0;
        }
        
        $row = [
          'nid' => $entity->id(),
          'title' => [
            'data' => [
              '#type' => 'link',
              '#title' => $entity_title,
              '#url' => $entity->toUrl(),
            ],
          ],
          'information_access' => $entity->get('field_information_access')->value ?? 0,
          'resource_control' => $entity->get('field_resource_control')->value ?? 0,
          'authority_permission' => $entity->get('field_authority_permission')->value ?? 0,
          'network_position' => $entity->get('field_network_position')->value ?? 0,
          'synthesis_application' => $entity->get('field_synthesis_application')->value ?? 0,
          'total_power' => $entity->get('field_total_power')->value ?? 0,
        ];
        
        $categories[$category_value]['rows'][] = $row;
        $category_counts[$category_value]++;
      }
      
      // Add ALL entities (including averages) to visualization data with sub-dimensions
      $entities_data[] = [
        'nid' => $entity->id(),
        'name' => $entity_title,
        'scores' => [
          'information_access' => $entity->get('field_information_access')->value ?? 0,
          'resource_control' => $entity->get('field_resource_control')->value ?? 0,
          'authority_permission' => $entity->get('field_authority_permission')->value ?? 0,
          'network_position' => $entity->get('field_network_position')->value ?? 0,
          'synthesis_application' => $entity->get('field_synthesis_application')->value ?? 0,
        ],
        'sub_dimensions' => [
          'information_access' => [
            'scope' => $entity->hasField('field_sub_scope') ? ($entity->get('field_sub_scope')->value ?? 0) : 0,
            'restriction' => $entity->hasField('field_sub_restriction') ? ($entity->get('field_sub_restriction')->value ?? 0) : 0,
            'classification' => $entity->hasField('field_sub_classification') ? ($entity->get('field_sub_classification')->value ?? 0) : 0,
            'temporal' => $entity->hasField('field_sub_temporal') ? ($entity->get('field_sub_temporal')->value ?? 0) : 0,
            'sources' => $entity->hasField('field_sub_sources') ? ($entity->get('field_sub_sources')->value ?? 0) : 0,
            'granularity' => $entity->hasField('field_sub_granularity') ? ($entity->get('field_sub_granularity')->value ?? 0) : 0,
          ],
          'resource_control' => [
            'computational' => $entity->hasField('field_sub_computational') ? ($entity->get('field_sub_computational')->value ?? 0) : 0,
            'financial' => $entity->hasField('field_sub_financial') ? ($entity->get('field_sub_financial')->value ?? 0) : 0,
            'data_storage' => $entity->hasField('field_sub_data_storage') ? ($entity->get('field_sub_data_storage')->value ?? 0) : 0,
            'network_bandwidth' => $entity->hasField('field_sub_network_bandwidth') ? ($entity->get('field_sub_network_bandwidth')->value ?? 0) : 0,
            'api_access' => $entity->hasField('field_sub_api_access') ? ($entity->get('field_sub_api_access')->value ?? 0) : 0,
            'human' => $entity->hasField('field_sub_human') ? ($entity->get('field_sub_human')->value ?? 0) : 0,
          ],
          'authority_permission' => [
            'legal' => $entity->hasField('field_sub_legal') ? ($entity->get('field_sub_legal')->value ?? 0) : 0,
            'institutional' => $entity->hasField('field_sub_institutional') ? ($entity->get('field_sub_institutional')->value ?? 0) : 0,
            'budget_auth' => $entity->hasField('field_sub_budget_auth') ? ($entity->get('field_sub_budget_auth')->value ?? 0) : 0,
            'policy' => $entity->hasField('field_sub_policy') ? ($entity->get('field_sub_policy')->value ?? 0) : 0,
            'override' => $entity->hasField('field_sub_override') ? ($entity->get('field_sub_override')->value ?? 0) : 0,
            'audit' => $entity->hasField('field_sub_audit') ? ($entity->get('field_sub_audit')->value ?? 0) : 0,
          ],
          'network_position' => [
            'connectivity' => $entity->hasField('field_sub_connectivity') ? ($entity->get('field_sub_connectivity')->value ?? 0) : 0,
            'centrality' => $entity->hasField('field_sub_centrality') ? ($entity->get('field_sub_centrality')->value ?? 0) : 0,
            'trust_reputation' => $entity->hasField('field_sub_trust_reputation') ? ($entity->get('field_sub_trust_reputation')->value ?? 0) : 0,
            'info_flow' => $entity->hasField('field_sub_info_flow') ? ($entity->get('field_sub_info_flow')->value ?? 0) : 0,
            'coalition' => $entity->hasField('field_sub_coalition') ? ($entity->get('field_sub_coalition')->value ?? 0) : 0,
            'network_effects' => $entity->hasField('field_sub_network_effects') ? ($entity->get('field_sub_network_effects')->value ?? 0) : 0,
          ],
          'synthesis_application' => [
            'reasoning' => $entity->hasField('field_sub_reasoning') ? ($entity->get('field_sub_reasoning')->value ?? 0) : 0,
            'creativity' => $entity->hasField('field_sub_creativity') ? ($entity->get('field_sub_creativity')->value ?? 0) : 0,
            'planning' => $entity->hasField('field_sub_planning') ? ($entity->get('field_sub_planning')->value ?? 0) : 0,
            'learning' => $entity->hasField('field_sub_learning') ? ($entity->get('field_sub_learning')->value ?? 0) : 0,
            'memory' => $entity->hasField('field_sub_memory') ? ($entity->get('field_sub_memory')->value ?? 0) : 0,
            'execution' => $entity->hasField('field_sub_execution') ? ($entity->get('field_sub_execution')->value ?? 0) : 0,
          ],
        ],
      ];
    }
    
    // Sort entities_data to put "Average: " entities at the top
    usort($entities_data, function($a, $b) {
      $a_is_average = strpos($a['name'], 'Average: ') === 0;
      $b_is_average = strpos($b['name'], 'Average: ') === 0;
      
      // If one is average and the other isn't, average goes first
      if ($a_is_average && !$b_is_average) {
        return -1;
      }
      if (!$a_is_average && $b_is_average) {
        return 1;
      }
      
      // Otherwise sort alphabetically
      return strcmp($a['name'], $b['name']);
    });
    
    // Load category average entities
    foreach ($categories as $category_value => &$category) {
      $average_title = "Average: " . $category['label'];
      $average_query = $storage->getQuery()
        ->condition('type', 'evaluated_entity')
        ->condition('status', 1)
        ->condition('title', $average_title)
        ->accessCheck(TRUE);
      
      $average_nids = $average_query->execute();
      if (!empty($average_nids)) {
        $average_entity = $storage->load(reset($average_nids));
        if ($average_entity) {
          $category['average'] = [
            'nid' => $average_entity->id(),
            'information_access' => $average_entity->get('field_information_access')->value ?? 0,
            'resource_control' => $average_entity->get('field_resource_control')->value ?? 0,
            'authority_permission' => $average_entity->get('field_authority_permission')->value ?? 0,
            'network_position' => $average_entity->get('field_network_position')->value ?? 0,
            'synthesis_application' => $average_entity->get('field_synthesis_application')->value ?? 0,
            'total_power' => $average_entity->get('field_total_power')->value ?? 0,
          ];
        }
      }
    }
    unset($category); // Break reference
    
    // Sort categories based on the selected sort field
    uasort($categories, function($a, $b) use ($category_sort, $category_order) {
      $a_val = 0;
      $b_val = 0;
      
      if ($category_sort === 'label') {
        // Alphabetical sort
        $result = strcmp($a['label'], $b['label']);
        return $result;
      }
      
      // Sort by dimension averages
      if (isset($a['average'][$category_sort])) {
        $a_val = $a['average'][$category_sort];
      }
      if (isset($b['average'][$category_sort])) {
        $b_val = $b['average'][$category_sort];
      }
      
      // Apply sort direction
      if ($category_order === 'DESC') {
        $result = $b_val <=> $a_val;
      } else {
        $result = $a_val <=> $b_val;
      }
      
      // If values are equal, sort by label
      if ($result === 0) {
        $result = strcmp($a['label'], $b['label']);
      }
      
      return $result;
    });
    
    return [
      '#theme' => 'forseti_evaluations_list',
      '#title' => $this->t('Evaluated Entities'),
      '#intro' => $this->t('Browse evaluated entities organized by category. Compare power profiles across the five fundamental dimensions. Click a category to expand and view entities.'),
      '#categories' => $categories,
      '#category_counts' => $category_counts,
      '#entities_data' => $entities_data,
      '#sort_field' => $sort_field,
      '#sort_direction' => $sort_direction,
      '#category_sort' => $category_sort,
      '#category_order' => $category_order,
      '#cache' => [
        'max-age' => 300, // Cache for 5 minutes
        'contexts' => ['url.query_args'],
      ],
    ];
  }
  
  /**
   * Helper function to get field label from allowed values.
   */
  private function getFieldLabel($entity_type, $bundle, $field_name, $value) {
    $field_storage = \Drupal::entityTypeManager()
      ->getStorage('field_storage_config')
      ->load($entity_type . '.' . $field_name);
    
    if ($field_storage) {
      $allowed_values = $field_storage->getSetting('allowed_values');
      // For list_string fields, allowed_values is a key-value array
      if (is_array($allowed_values) && isset($allowed_values[$value])) {
        return $allowed_values[$value];
      }
    }
    
    return $value;
  }

  /**
   * Example Entities page - lists all entities mentioned in framework examples.
   */
  public function exampleEntities() {
    $entities = $this->getExampleEntitiesList();
    
    return [
      '#theme' => 'forseti_example_entities',
      '#entities' => $entities,
      '#cache' => [
        'max-age' => 86400, // Cache for 24 hours
      ],
    ];
  }

  /**
   * US Government Power Analysis page.
   */
  public function usGovernmentPower() {
    return [
      '#theme' => 'forseti_us_government_power',
      '#title' => $this->t('US Government Power Analysis'),
      '#intro' => $this->buildUsGovIntro(),
      '#branches' => $this->getUsGovernmentStructure(),
      '#cache' => [
        'max-age' => 86400,
      ],
    ];
  }

  /**
   * Build introduction content for US Government page.
   */
  private function buildUsGovIntro() {
    return [
      'lead' => $this->t('Executive Branch military chain of command: Evaluating each rank against the Agent Power Framework\'s 30 sub-dimensions.'),
      'paragraphs' => [
        $this->t('This page lists all military ranks from Commander-in-Chief to Private, showing how each position maps to the framework\'s 0-9 capability scale. Multiple ranks may share the same power level when their actual capabilities across the five dimensions are equivalent.'),
        $this->t('Each rank will be evaluated based on: Information Access (what they can know), Resource Control (what they can deploy), Authority & Permission (what they\'re allowed to do), Network Position (whom they can influence), and Synthesis & Application (how well they think and act).'),
      ],
    ];
  }

  /**
   * Get US Government organizational structure with power profiles.
   */
  private function getUsGovernmentStructure() {
    // Military ranks evaluated against Agent Power Framework (0-9 scale)
    // All 30 sub-dimensions (6 per main dimension) evaluated for each rank
    return [
      [
        'position' => 'President of the United States',
        'title' => 'Commander-in-Chief',
        'rank_type' => 'Constitutional Office',
        'scope' => 'All U.S. Armed Forces (2.1M active duty, 800K reserves)',
        'power_profile' => ['info' => 9, 'resource' => 9, 'authority' => 9, 'network' => 9, 'synthesis' => 8],
        'level_avg' => 8.8,
        'sub_dimensions' => [
          'info_scope' => 9, 'info_restrictions' => 9, 'info_classification' => 9, 'info_temporal' => 9, 'info_source_diversity' => 9, 'info_detail' => 9,
          'resource_computational' => 9, 'resource_financial' => 9, 'resource_infrastructure' => 9, 'resource_human' => 9, 'resource_energy' => 9, 'resource_time' => 9,
          'authority_legal' => 9, 'authority_jurisdiction' => 9, 'authority_hierarchy' => 9, 'authority_financial' => 9, 'authority_territory' => 9, 'authority_legitimacy' => 9,
          'network_trust' => 9, 'network_dependencies' => 9, 'network_gatekeeping' => 9, 'network_influence' => 9, 'network_reputation' => 9, 'network_mobilization' => 9,
          'synthesis_pattern' => 8, 'synthesis_creativity' => 8, 'synthesis_planning' => 9, 'synthesis_decision' => 8, 'synthesis_learning' => 7, 'synthesis_memory' => 8,
        ],
      ],
      [
        'position' => 'Secretary of Defense',
        'title' => 'Civilian Head of DoD',
        'rank_type' => 'Cabinet Position',
        'scope' => 'Department of Defense (3M personnel)',
        'power_profile' => ['info' => 8, 'resource' => 9, 'authority' => 8, 'network' => 8, 'synthesis' => 8],
        'level_avg' => 8.2,
        'sub_dimensions' => [
          'info_scope' => 8, 'info_restrictions' => 9, 'info_classification' => 9, 'info_temporal' => 8, 'info_source_diversity' => 8, 'info_detail' => 8,
          'resource_computational' => 9, 'resource_financial' => 9, 'resource_infrastructure' => 9, 'resource_human' => 9, 'resource_energy' => 9, 'resource_time' => 9,
          'authority_legal' => 8, 'authority_jurisdiction' => 8, 'authority_hierarchy' => 9, 'authority_financial' => 9, 'authority_territory' => 8, 'authority_legitimacy' => 8,
          'network_trust' => 8, 'network_dependencies' => 8, 'network_gatekeeping' => 8, 'network_influence' => 8, 'network_reputation' => 8, 'network_mobilization' => 8,
          'synthesis_pattern' => 8, 'synthesis_creativity' => 7, 'synthesis_planning' => 9, 'synthesis_decision' => 8, 'synthesis_learning' => 8, 'synthesis_memory' => 8,
        ],
      ],
      [
        'position' => 'Chairman, Joint Chiefs of Staff',
        'title' => 'O-10 / Four-Star General',
        'rank_type' => 'Senior Military Officer',
        'scope' => 'All Service Branches Military Advisor',
        'power_profile' => ['info' => 8, 'resource' => 8, 'authority' => 7, 'network' => 8, 'synthesis' => 7],
        'level_avg' => 7.6,
        'sub_dimensions' => [
          'info_scope' => 8, 'info_restrictions' => 8, 'info_classification' => 9, 'info_temporal' => 8, 'info_source_diversity' => 8, 'info_detail' => 7,
          'resource_computational' => 8, 'resource_financial' => 8, 'resource_infrastructure' => 8, 'resource_human' => 8, 'resource_energy' => 8, 'resource_time' => 8,
          'authority_legal' => 7, 'authority_jurisdiction' => 7, 'authority_hierarchy' => 8, 'authority_financial' => 7, 'authority_territory' => 7, 'authority_legitimacy' => 7,
          'network_trust' => 8, 'network_dependencies' => 8, 'network_gatekeeping' => 8, 'network_influence' => 8, 'network_reputation' => 8, 'network_mobilization' => 8,
          'synthesis_pattern' => 7, 'synthesis_creativity' => 7, 'synthesis_planning' => 8, 'synthesis_decision' => 7, 'synthesis_learning' => 7, 'synthesis_memory' => 7,
        ],
      ],
      [
        'position' => 'Combatant Commander',
        'title' => 'O-10 / Four-Star General',
        'rank_type' => 'Senior Military Officer',
        'scope' => 'Geographic or Functional Combatant Command',
        'power_profile' => ['info' => 8, 'resource' => 8, 'authority' => 7, 'network' => 7, 'synthesis' => 7],
        'level_avg' => 7.4,
        'sub_dimensions' => [
          'info_scope' => 8, 'info_restrictions' => 8, 'info_classification' => 9, 'info_temporal' => 8, 'info_source_diversity' => 7, 'info_detail' => 8,
          'resource_computational' => 8, 'resource_financial' => 8, 'resource_infrastructure' => 8, 'resource_human' => 8, 'resource_energy' => 8, 'resource_time' => 8,
          'authority_legal' => 7, 'authority_jurisdiction' => 7, 'authority_hierarchy' => 8, 'authority_financial' => 7, 'authority_territory' => 7, 'authority_legitimacy' => 7,
          'network_trust' => 7, 'network_dependencies' => 7, 'network_gatekeeping' => 7, 'network_influence' => 7, 'network_reputation' => 7, 'network_mobilization' => 8,
          'synthesis_pattern' => 7, 'synthesis_creativity' => 7, 'synthesis_planning' => 8, 'synthesis_decision' => 7, 'synthesis_learning' => 7, 'synthesis_memory' => 7,
        ],
      ],
      [
        'position' => 'Lieutenant General / Vice Admiral',
        'title' => 'O-9 / Three-Star',
        'rank_type' => 'Senior Military Officer',
        'scope' => 'Corps Commander (20,000-45,000 troops)',
        'power_profile' => ['info' => 7, 'resource' => 7, 'authority' => 7, 'network' => 7, 'synthesis' => 7],
        'level_avg' => 7.0,
        'sub_dimensions' => [
          'info_scope' => 7, 'info_restrictions' => 7, 'info_classification' => 8, 'info_temporal' => 7, 'info_source_diversity' => 7, 'info_detail' => 7,
          'resource_computational' => 7, 'resource_financial' => 7, 'resource_infrastructure' => 7, 'resource_human' => 7, 'resource_energy' => 7, 'resource_time' => 7,
          'authority_legal' => 7, 'authority_jurisdiction' => 7, 'authority_hierarchy' => 7, 'authority_financial' => 7, 'authority_territory' => 7, 'authority_legitimacy' => 7,
          'network_trust' => 7, 'network_dependencies' => 7, 'network_gatekeeping' => 7, 'network_influence' => 7, 'network_reputation' => 7, 'network_mobilization' => 7,
          'synthesis_pattern' => 7, 'synthesis_creativity' => 7, 'synthesis_planning' => 7, 'synthesis_decision' => 7, 'synthesis_learning' => 7, 'synthesis_memory' => 7,
        ],
      ],
      [
        'position' => 'Major General / Rear Admiral (Upper)',
        'title' => 'O-8 / Two-Star',
        'rank_type' => 'General Officer',
        'scope' => 'Division Commander (10,000-15,000 troops)',
        'power_profile' => ['info' => 6, 'resource' => 6, 'authority' => 6, 'network' => 6, 'synthesis' => 6],
        'level_avg' => 6.0,
        'sub_dimensions' => [
          'info_scope' => 6, 'info_restrictions' => 6, 'info_classification' => 7, 'info_temporal' => 6, 'info_source_diversity' => 6, 'info_detail' => 6,
          'resource_computational' => 6, 'resource_financial' => 6, 'resource_infrastructure' => 6, 'resource_human' => 6, 'resource_energy' => 6, 'resource_time' => 6,
          'authority_legal' => 6, 'authority_jurisdiction' => 6, 'authority_hierarchy' => 6, 'authority_financial' => 6, 'authority_territory' => 6, 'authority_legitimacy' => 6,
          'network_trust' => 6, 'network_dependencies' => 6, 'network_gatekeeping' => 6, 'network_influence' => 6, 'network_reputation' => 6, 'network_mobilization' => 6,
          'synthesis_pattern' => 6, 'synthesis_creativity' => 6, 'synthesis_planning' => 6, 'synthesis_decision' => 6, 'synthesis_learning' => 6, 'synthesis_memory' => 6,
        ],
      ],
      [
        'position' => 'Brigadier General / Rear Admiral (Lower)',
        'title' => 'O-7 / One-Star',
        'rank_type' => 'General Officer',
        'scope' => 'Brigade Commander (3,000-5,000 troops)',
        'power_profile' => ['info' => 6, 'resource' => 6, 'authority' => 6, 'network' => 6, 'synthesis' => 6],
        'level_avg' => 6.0,
        'sub_dimensions' => [
          'info_scope' => 6, 'info_restrictions' => 6, 'info_classification' => 7, 'info_temporal' => 6, 'info_source_diversity' => 6, 'info_detail' => 6,
          'resource_computational' => 6, 'resource_financial' => 6, 'resource_infrastructure' => 6, 'resource_human' => 6, 'resource_energy' => 6, 'resource_time' => 6,
          'authority_legal' => 6, 'authority_jurisdiction' => 6, 'authority_hierarchy' => 6, 'authority_financial' => 6, 'authority_territory' => 6, 'authority_legitimacy' => 6,
          'network_trust' => 6, 'network_dependencies' => 6, 'network_gatekeeping' => 6, 'network_influence' => 6, 'network_reputation' => 6, 'network_mobilization' => 6,
          'synthesis_pattern' => 6, 'synthesis_creativity' => 6, 'synthesis_planning' => 6, 'synthesis_decision' => 6, 'synthesis_learning' => 6, 'synthesis_memory' => 6,
        ],
      ],
      [
        'position' => 'Colonel / Captain (Navy)',
        'title' => 'O-6 / Senior Field Grade',
        'rank_type' => 'Field Grade Officer',
        'scope' => 'Regiment/Brigade Staff (1,000-3,000 troops)',
        'power_profile' => ['info' => 5, 'resource' => 5, 'authority' => 5, 'network' => 5, 'synthesis' => 5],
        'level_avg' => 5.0,
        'sub_dimensions' => [
          'info_scope' => 5, 'info_restrictions' => 5, 'info_classification' => 6, 'info_temporal' => 5, 'info_source_diversity' => 5, 'info_detail' => 5,
          'resource_computational' => 5, 'resource_financial' => 5, 'resource_infrastructure' => 5, 'resource_human' => 5, 'resource_energy' => 5, 'resource_time' => 5,
          'authority_legal' => 5, 'authority_jurisdiction' => 5, 'authority_hierarchy' => 5, 'authority_financial' => 5, 'authority_territory' => 5, 'authority_legitimacy' => 5,
          'network_trust' => 5, 'network_dependencies' => 5, 'network_gatekeeping' => 5, 'network_influence' => 5, 'network_reputation' => 5, 'network_mobilization' => 5,
          'synthesis_pattern' => 5, 'synthesis_creativity' => 5, 'synthesis_planning' => 5, 'synthesis_decision' => 5, 'synthesis_learning' => 5, 'synthesis_memory' => 5,
        ],
      ],
      [
        'position' => 'Lieutenant Colonel / Commander',
        'title' => 'O-5 / Field Grade',
        'rank_type' => 'Field Grade Officer',
        'scope' => 'Battalion Commander (300-1,000 troops)',
        'power_profile' => ['info' => 5, 'resource' => 5, 'authority' => 5, 'network' => 5, 'synthesis' => 5],
        'level_avg' => 5.0,
        'sub_dimensions' => [
          'info_scope' => 5, 'info_restrictions' => 5, 'info_classification' => 6, 'info_temporal' => 5, 'info_source_diversity' => 5, 'info_detail' => 5,
          'resource_computational' => 5, 'resource_financial' => 5, 'resource_infrastructure' => 5, 'resource_human' => 5, 'resource_energy' => 5, 'resource_time' => 5,
          'authority_legal' => 5, 'authority_jurisdiction' => 5, 'authority_hierarchy' => 5, 'authority_financial' => 5, 'authority_territory' => 5, 'authority_legitimacy' => 5,
          'network_trust' => 5, 'network_dependencies' => 5, 'network_gatekeeping' => 5, 'network_influence' => 5, 'network_reputation' => 5, 'network_mobilization' => 5,
          'synthesis_pattern' => 5, 'synthesis_creativity' => 5, 'synthesis_planning' => 5, 'synthesis_decision' => 5, 'synthesis_learning' => 5, 'synthesis_memory' => 5,
        ],
      ],
      [
        'position' => 'Major / Lieutenant Commander',
        'title' => 'O-4 / Field Grade',
        'rank_type' => 'Field Grade Officer',
        'scope' => 'Battalion Executive Officer/Staff',
        'power_profile' => ['info' => 4, 'resource' => 4, 'authority' => 4, 'network' => 4, 'synthesis' => 4],
        'level_avg' => 4.0,
        'sub_dimensions' => [
          'info_scope' => 4, 'info_restrictions' => 4, 'info_classification' => 5, 'info_temporal' => 4, 'info_source_diversity' => 4, 'info_detail' => 4,
          'resource_computational' => 4, 'resource_financial' => 4, 'resource_infrastructure' => 4, 'resource_human' => 4, 'resource_energy' => 4, 'resource_time' => 4,
          'authority_legal' => 4, 'authority_jurisdiction' => 4, 'authority_hierarchy' => 4, 'authority_financial' => 4, 'authority_territory' => 4, 'authority_legitimacy' => 4,
          'network_trust' => 4, 'network_dependencies' => 4, 'network_gatekeeping' => 4, 'network_influence' => 4, 'network_reputation' => 4, 'network_mobilization' => 4,
          'synthesis_pattern' => 4, 'synthesis_creativity' => 4, 'synthesis_planning' => 4, 'synthesis_decision' => 4, 'synthesis_learning' => 4, 'synthesis_memory' => 4,
        ],
      ],
      [
        'position' => 'Captain / Lieutenant',
        'title' => 'O-3 / Company Grade',
        'rank_type' => 'Company Grade Officer',
        'scope' => 'Company Commander (60-200 troops)',
        'power_profile' => ['info' => 4, 'resource' => 4, 'authority' => 4, 'network' => 4, 'synthesis' => 4],
        'level_avg' => 4.0,
        'sub_dimensions' => [
          'info_scope' => 4, 'info_restrictions' => 4, 'info_classification' => 5, 'info_temporal' => 4, 'info_source_diversity' => 4, 'info_detail' => 4,
          'resource_computational' => 4, 'resource_financial' => 4, 'resource_infrastructure' => 4, 'resource_human' => 4, 'resource_energy' => 4, 'resource_time' => 4,
          'authority_legal' => 4, 'authority_jurisdiction' => 4, 'authority_hierarchy' => 4, 'authority_financial' => 4, 'authority_territory' => 4, 'authority_legitimacy' => 4,
          'network_trust' => 4, 'network_dependencies' => 4, 'network_gatekeeping' => 4, 'network_influence' => 4, 'network_reputation' => 4, 'network_mobilization' => 4,
          'synthesis_pattern' => 4, 'synthesis_creativity' => 4, 'synthesis_planning' => 4, 'synthesis_decision' => 4, 'synthesis_learning' => 4, 'synthesis_memory' => 4,
        ],
      ],
      [
        'position' => 'First Lieutenant / Lieutenant (JG)',
        'title' => 'O-2 / Company Grade',
        'rank_type' => 'Company Grade Officer',
        'scope' => 'Platoon Leader (16-50 troops)',
        'power_profile' => ['info' => 3, 'resource' => 3, 'authority' => 3, 'network' => 3, 'synthesis' => 3],
        'level_avg' => 3.0,
        'sub_dimensions' => [
          'info_scope' => 3, 'info_restrictions' => 3, 'info_classification' => 4, 'info_temporal' => 3, 'info_source_diversity' => 3, 'info_detail' => 3,
          'resource_computational' => 3, 'resource_financial' => 3, 'resource_infrastructure' => 3, 'resource_human' => 3, 'resource_energy' => 3, 'resource_time' => 3,
          'authority_legal' => 3, 'authority_jurisdiction' => 3, 'authority_hierarchy' => 3, 'authority_financial' => 3, 'authority_territory' => 3, 'authority_legitimacy' => 3,
          'network_trust' => 3, 'network_dependencies' => 3, 'network_gatekeeping' => 3, 'network_influence' => 3, 'network_reputation' => 3, 'network_mobilization' => 3,
          'synthesis_pattern' => 3, 'synthesis_creativity' => 3, 'synthesis_planning' => 3, 'synthesis_decision' => 3, 'synthesis_learning' => 3, 'synthesis_memory' => 3,
        ],
      ],
      [
        'position' => 'Second Lieutenant / Ensign',
        'title' => 'O-1 / Company Grade',
        'rank_type' => 'Junior Officer',
        'scope' => 'Platoon Leader/Assistant (16-50 troops)',
        'power_profile' => ['info' => 3, 'resource' => 3, 'authority' => 3, 'network' => 3, 'synthesis' => 3],
        'level_avg' => 3.0,
        'sub_dimensions' => [
          'info_scope' => 3, 'info_restrictions' => 3, 'info_classification' => 4, 'info_temporal' => 3, 'info_source_diversity' => 3, 'info_detail' => 3,
          'resource_computational' => 3, 'resource_financial' => 3, 'resource_infrastructure' => 3, 'resource_human' => 3, 'resource_energy' => 3, 'resource_time' => 3,
          'authority_legal' => 3, 'authority_jurisdiction' => 3, 'authority_hierarchy' => 3, 'authority_financial' => 3, 'authority_territory' => 3, 'authority_legitimacy' => 3,
          'network_trust' => 3, 'network_dependencies' => 3, 'network_gatekeeping' => 3, 'network_influence' => 3, 'network_reputation' => 3, 'network_mobilization' => 3,
          'synthesis_pattern' => 3, 'synthesis_creativity' => 3, 'synthesis_planning' => 3, 'synthesis_decision' => 3, 'synthesis_learning' => 3, 'synthesis_memory' => 3,
        ],
      ],
      [
        'position' => 'Warrant Officer 5 (CW5)',
        'title' => 'W-5 / Master Warrant Officer',
        'rank_type' => 'Senior Warrant Officer',
        'scope' => 'Senior Technical Expert',
        'power_profile' => ['info' => 5, 'resource' => 4, 'authority' => 4, 'network' => 5, 'synthesis' => 6],
        'level_avg' => 4.8,
        'sub_dimensions' => [
          'info_scope' => 5, 'info_restrictions' => 5, 'info_classification' => 6, 'info_temporal' => 5, 'info_source_diversity' => 5, 'info_detail' => 6,
          'resource_computational' => 4, 'resource_financial' => 3, 'resource_infrastructure' => 4, 'resource_human' => 4, 'resource_energy' => 4, 'resource_time' => 5,
          'authority_legal' => 4, 'authority_jurisdiction' => 4, 'authority_hierarchy' => 3, 'authority_financial' => 3, 'authority_territory' => 4, 'authority_legitimacy' => 5,
          'network_trust' => 5, 'network_dependencies' => 5, 'network_gatekeeping' => 5, 'network_influence' => 5, 'network_reputation' => 6, 'network_mobilization' => 4,
          'synthesis_pattern' => 7, 'synthesis_creativity' => 6, 'synthesis_planning' => 5, 'synthesis_decision' => 6, 'synthesis_learning' => 6, 'synthesis_memory' => 6,
        ],
      ],
      [
        'position' => 'Warrant Officer 4 (CW4)',
        'title' => 'W-4 / Senior Warrant Officer',
        'rank_type' => 'Senior Warrant Officer',
        'scope' => 'Battalion/Brigade Technical Advisor',
        'power_profile' => ['info' => 5, 'resource' => 4, 'authority' => 4, 'network' => 5, 'synthesis' => 6],
        'level_avg' => 4.8,
        'sub_dimensions' => [
          'info_scope' => 5, 'info_restrictions' => 5, 'info_classification' => 6, 'info_temporal' => 5, 'info_source_diversity' => 5, 'info_detail' => 6,
          'resource_computational' => 4, 'resource_financial' => 3, 'resource_infrastructure' => 4, 'resource_human' => 4, 'resource_energy' => 4, 'resource_time' => 5,
          'authority_legal' => 4, 'authority_jurisdiction' => 4, 'authority_hierarchy' => 3, 'authority_financial' => 3, 'authority_territory' => 4, 'authority_legitimacy' => 5,
          'network_trust' => 5, 'network_dependencies' => 5, 'network_gatekeeping' => 5, 'network_influence' => 5, 'network_reputation' => 6, 'network_mobilization' => 4,
          'synthesis_pattern' => 7, 'synthesis_creativity' => 6, 'synthesis_planning' => 5, 'synthesis_decision' => 6, 'synthesis_learning' => 6, 'synthesis_memory' => 6,
        ],
      ],
      [
        'position' => 'Warrant Officer 3 (CW3)',
        'title' => 'W-3 / Warrant Officer',
        'rank_type' => 'Warrant Officer',
        'scope' => 'Company/Battalion Technical Expert',
        'power_profile' => ['info' => 4, 'resource' => 3, 'authority' => 3, 'network' => 4, 'synthesis' => 5],
        'level_avg' => 3.8,
        'sub_dimensions' => [
          'info_scope' => 4, 'info_restrictions' => 4, 'info_classification' => 5, 'info_temporal' => 4, 'info_source_diversity' => 4, 'info_detail' => 5,
          'resource_computational' => 3, 'resource_financial' => 2, 'resource_infrastructure' => 3, 'resource_human' => 3, 'resource_energy' => 3, 'resource_time' => 4,
          'authority_legal' => 3, 'authority_jurisdiction' => 3, 'authority_hierarchy' => 2, 'authority_financial' => 2, 'authority_territory' => 3, 'authority_legitimacy' => 4,
          'network_trust' => 4, 'network_dependencies' => 4, 'network_gatekeeping' => 4, 'network_influence' => 4, 'network_reputation' => 5, 'network_mobilization' => 3,
          'synthesis_pattern' => 6, 'synthesis_creativity' => 5, 'synthesis_planning' => 4, 'synthesis_decision' => 5, 'synthesis_learning' => 5, 'synthesis_memory' => 5,
        ],
      ],
      [
        'position' => 'Warrant Officer 2 (CW2)',
        'title' => 'W-2 / Warrant Officer',
        'rank_type' => 'Warrant Officer',
        'scope' => 'Technical Specialist',
        'power_profile' => ['info' => 4, 'resource' => 3, 'authority' => 3, 'network' => 4, 'synthesis' => 5],
        'level_avg' => 3.8,
        'sub_dimensions' => [
          'info_scope' => 4, 'info_restrictions' => 4, 'info_classification' => 5, 'info_temporal' => 4, 'info_source_diversity' => 4, 'info_detail' => 5,
          'resource_computational' => 3, 'resource_financial' => 2, 'resource_infrastructure' => 3, 'resource_human' => 3, 'resource_energy' => 3, 'resource_time' => 4,
          'authority_legal' => 3, 'authority_jurisdiction' => 3, 'authority_hierarchy' => 2, 'authority_financial' => 2, 'authority_territory' => 3, 'authority_legitimacy' => 4,
          'network_trust' => 4, 'network_dependencies' => 4, 'network_gatekeeping' => 4, 'network_influence' => 4, 'network_reputation' => 5, 'network_mobilization' => 3,
          'synthesis_pattern' => 6, 'synthesis_creativity' => 5, 'synthesis_planning' => 4, 'synthesis_decision' => 5, 'synthesis_learning' => 5, 'synthesis_memory' => 5,
        ],
      ],
      [
        'position' => 'Warrant Officer 1 (WO1)',
        'title' => 'W-1 / Warrant Officer',
        'rank_type' => 'Junior Warrant Officer',
        'scope' => 'Entry Technical Specialist',
        'power_profile' => ['info' => 3, 'resource' => 2, 'authority' => 2, 'network' => 3, 'synthesis' => 4],
        'level_avg' => 2.8,
        'sub_dimensions' => [
          'info_scope' => 3, 'info_restrictions' => 3, 'info_classification' => 4, 'info_temporal' => 3, 'info_source_diversity' => 3, 'info_detail' => 4,
          'resource_computational' => 2, 'resource_financial' => 1, 'resource_infrastructure' => 2, 'resource_human' => 2, 'resource_energy' => 2, 'resource_time' => 3,
          'authority_legal' => 2, 'authority_jurisdiction' => 2, 'authority_hierarchy' => 2, 'authority_financial' => 1, 'authority_territory' => 2, 'authority_legitimacy' => 3,
          'network_trust' => 3, 'network_dependencies' => 3, 'network_gatekeeping' => 3, 'network_influence' => 3, 'network_reputation' => 4, 'network_mobilization' => 2,
          'synthesis_pattern' => 5, 'synthesis_creativity' => 4, 'synthesis_planning' => 3, 'synthesis_decision' => 4, 'synthesis_learning' => 4, 'synthesis_memory' => 4,
        ],
      ],
      [
        'position' => 'Sergeant Major / Master Chief',
        'title' => 'E-9 / Senior Enlisted',
        'rank_type' => 'Senior Enlisted Advisor',
        'scope' => 'Senior Enlisted Advisor (Battalion+)',
        'power_profile' => ['info' => 4, 'resource' => 4, 'authority' => 4, 'network' => 5, 'synthesis' => 5],
        'level_avg' => 4.4,
        'sub_dimensions' => [
          'info_scope' => 4, 'info_restrictions' => 4, 'info_classification' => 5, 'info_temporal' => 4, 'info_source_diversity' => 4, 'info_detail' => 4,
          'resource_computational' => 4, 'resource_financial' => 3, 'resource_infrastructure' => 4, 'resource_human' => 4, 'resource_energy' => 4, 'resource_time' => 5,
          'authority_legal' => 4, 'authority_jurisdiction' => 4, 'authority_hierarchy' => 4, 'authority_financial' => 3, 'authority_territory' => 4, 'authority_legitimacy' => 5,
          'network_trust' => 6, 'network_dependencies' => 5, 'network_gatekeeping' => 5, 'network_influence' => 5, 'network_reputation' => 6, 'network_mobilization' => 5,
          'synthesis_pattern' => 5, 'synthesis_creativity' => 4, 'synthesis_planning' => 5, 'synthesis_decision' => 5, 'synthesis_learning' => 5, 'synthesis_memory' => 6,
        ],
      ],
      [
        'position' => 'First Sergeant / Senior Chief',
        'title' => 'E-8 / Senior NCO',
        'rank_type' => 'Senior Non-Commissioned Officer',
        'scope' => 'Company First Sergeant (100-200 troops)',
        'power_profile' => ['info' => 4, 'resource' => 4, 'authority' => 4, 'network' => 5, 'synthesis' => 5],
        'level_avg' => 4.4,
        'sub_dimensions' => [
          'info_scope' => 4, 'info_restrictions' => 4, 'info_classification' => 5, 'info_temporal' => 4, 'info_source_diversity' => 4, 'info_detail' => 4,
          'resource_computational' => 4, 'resource_financial' => 3, 'resource_infrastructure' => 4, 'resource_human' => 4, 'resource_energy' => 4, 'resource_time' => 5,
          'authority_legal' => 4, 'authority_jurisdiction' => 4, 'authority_hierarchy' => 4, 'authority_financial' => 3, 'authority_territory' => 4, 'authority_legitimacy' => 5,
          'network_trust' => 6, 'network_dependencies' => 5, 'network_gatekeeping' => 5, 'network_influence' => 5, 'network_reputation' => 6, 'network_mobilization' => 5,
          'synthesis_pattern' => 5, 'synthesis_creativity' => 4, 'synthesis_planning' => 5, 'synthesis_decision' => 5, 'synthesis_learning' => 5, 'synthesis_memory' => 6,
        ],
      ],
      [
        'position' => 'Master Sergeant / Chief',
        'title' => 'E-7 / Senior NCO',
        'rank_type' => 'Senior Non-Commissioned Officer',
        'scope' => 'Platoon Sergeant (16-50 troops)',
        'power_profile' => ['info' => 3, 'resource' => 3, 'authority' => 3, 'network' => 4, 'synthesis' => 4],
        'level_avg' => 3.4,
        'sub_dimensions' => [
          'info_scope' => 3, 'info_restrictions' => 3, 'info_classification' => 4, 'info_temporal' => 3, 'info_source_diversity' => 3, 'info_detail' => 3,
          'resource_computational' => 3, 'resource_financial' => 2, 'resource_infrastructure' => 3, 'resource_human' => 3, 'resource_energy' => 3, 'resource_time' => 4,
          'authority_legal' => 3, 'authority_jurisdiction' => 3, 'authority_hierarchy' => 3, 'authority_financial' => 2, 'authority_territory' => 3, 'authority_legitimacy' => 4,
          'network_trust' => 5, 'network_dependencies' => 4, 'network_gatekeeping' => 4, 'network_influence' => 4, 'network_reputation' => 5, 'network_mobilization' => 4,
          'synthesis_pattern' => 4, 'synthesis_creativity' => 3, 'synthesis_planning' => 4, 'synthesis_decision' => 4, 'synthesis_learning' => 4, 'synthesis_memory' => 5,
        ],
      ],
      [
        'position' => 'Staff Sergeant / Petty Officer 1st',
        'title' => 'E-6 / Staff NCO',
        'rank_type' => 'Non-Commissioned Officer',
        'scope' => 'Squad Leader (9-13 troops)',
        'power_profile' => ['info' => 3, 'resource' => 3, 'authority' => 3, 'network' => 4, 'synthesis' => 4],
        'level_avg' => 3.4,
        'sub_dimensions' => [
          'info_scope' => 3, 'info_restrictions' => 3, 'info_classification' => 4, 'info_temporal' => 3, 'info_source_diversity' => 3, 'info_detail' => 3,
          'resource_computational' => 3, 'resource_financial' => 2, 'resource_infrastructure' => 3, 'resource_human' => 3, 'resource_energy' => 3, 'resource_time' => 4,
          'authority_legal' => 3, 'authority_jurisdiction' => 3, 'authority_hierarchy' => 3, 'authority_financial' => 2, 'authority_territory' => 3, 'authority_legitimacy' => 4,
          'network_trust' => 5, 'network_dependencies' => 4, 'network_gatekeeping' => 4, 'network_influence' => 4, 'network_reputation' => 5, 'network_mobilization' => 4,
          'synthesis_pattern' => 4, 'synthesis_creativity' => 3, 'synthesis_planning' => 4, 'synthesis_decision' => 4, 'synthesis_learning' => 4, 'synthesis_memory' => 5,
        ],
      ],
      [
        'position' => 'Sergeant / Petty Officer 2nd',
        'title' => 'E-5 / NCO',
        'rank_type' => 'Non-Commissioned Officer',
        'scope' => 'Team Leader (4-9 troops)',
        'power_profile' => ['info' => 3, 'resource' => 3, 'authority' => 3, 'network' => 3, 'synthesis' => 3],
        'level_avg' => 3.0,
        'sub_dimensions' => [
          'info_scope' => 3, 'info_restrictions' => 3, 'info_classification' => 4, 'info_temporal' => 3, 'info_source_diversity' => 3, 'info_detail' => 3,
          'resource_computational' => 3, 'resource_financial' => 2, 'resource_infrastructure' => 3, 'resource_human' => 3, 'resource_energy' => 3, 'resource_time' => 4,
          'authority_legal' => 3, 'authority_jurisdiction' => 3, 'authority_hierarchy' => 3, 'authority_financial' => 2, 'authority_territory' => 3, 'authority_legitimacy' => 4,
          'network_trust' => 4, 'network_dependencies' => 3, 'network_gatekeeping' => 3, 'network_influence' => 3, 'network_reputation' => 4, 'network_mobilization' => 3,
          'synthesis_pattern' => 3, 'synthesis_creativity' => 3, 'synthesis_planning' => 3, 'synthesis_decision' => 3, 'synthesis_learning' => 3, 'synthesis_memory' => 4,
        ],
      ],
      [
        'position' => 'Corporal / Petty Officer 3rd',
        'title' => 'E-4 / Junior NCO',
        'rank_type' => 'Junior Non-Commissioned Officer',
        'scope' => 'Fire Team Leader (3-4 troops)',
        'power_profile' => ['info' => 2, 'resource' => 2, 'authority' => 2, 'network' => 3, 'synthesis' => 3],
        'level_avg' => 2.4,
        'sub_dimensions' => [
          'info_scope' => 2, 'info_restrictions' => 2, 'info_classification' => 3, 'info_temporal' => 2, 'info_source_diversity' => 2, 'info_detail' => 2,
          'resource_computational' => 2, 'resource_financial' => 1, 'resource_infrastructure' => 2, 'resource_human' => 2, 'resource_energy' => 2, 'resource_time' => 3,
          'authority_legal' => 2, 'authority_jurisdiction' => 2, 'authority_hierarchy' => 2, 'authority_financial' => 1, 'authority_territory' => 2, 'authority_legitimacy' => 3,
          'network_trust' => 3, 'network_dependencies' => 3, 'network_gatekeeping' => 3, 'network_influence' => 3, 'network_reputation' => 3, 'network_mobilization' => 3,
          'synthesis_pattern' => 3, 'synthesis_creativity' => 2, 'synthesis_planning' => 3, 'synthesis_decision' => 3, 'synthesis_learning' => 3, 'synthesis_memory' => 3,
        ],
      ],
      [
        'position' => 'Specialist / Seaman',
        'title' => 'E-4 / Specialist',
        'rank_type' => 'Enlisted',
        'scope' => 'Specialist (no leadership)',
        'power_profile' => ['info' => 2, 'resource' => 2, 'authority' => 1, 'network' => 2, 'synthesis' => 2],
        'level_avg' => 1.8,
        'sub_dimensions' => [
          'info_scope' => 2, 'info_restrictions' => 2, 'info_classification' => 3, 'info_temporal' => 2, 'info_source_diversity' => 2, 'info_detail' => 2,
          'resource_computational' => 2, 'resource_financial' => 1, 'resource_infrastructure' => 2, 'resource_human' => 1, 'resource_energy' => 2, 'resource_time' => 2,
          'authority_legal' => 1, 'authority_jurisdiction' => 1, 'authority_hierarchy' => 1, 'authority_financial' => 1, 'authority_territory' => 1, 'authority_legitimacy' => 2,
          'network_trust' => 2, 'network_dependencies' => 2, 'network_gatekeeping' => 2, 'network_influence' => 2, 'network_reputation' => 2, 'network_mobilization' => 2,
          'synthesis_pattern' => 2, 'synthesis_creativity' => 2, 'synthesis_planning' => 2, 'synthesis_decision' => 2, 'synthesis_learning' => 2, 'synthesis_memory' => 2,
        ],
      ],
      [
        'position' => 'Private First Class / Seaman Apprentice',
        'title' => 'E-3 / Junior Enlisted',
        'rank_type' => 'Enlisted',
        'scope' => 'Individual Service Member',
        'power_profile' => ['info' => 2, 'resource' => 2, 'authority' => 1, 'network' => 2, 'synthesis' => 2],
        'level_avg' => 1.8,
        'sub_dimensions' => [
          'info_scope' => 2, 'info_restrictions' => 2, 'info_classification' => 3, 'info_temporal' => 2, 'info_source_diversity' => 2, 'info_detail' => 2,
          'resource_computational' => 2, 'resource_financial' => 1, 'resource_infrastructure' => 2, 'resource_human' => 1, 'resource_energy' => 2, 'resource_time' => 2,
          'authority_legal' => 1, 'authority_jurisdiction' => 1, 'authority_hierarchy' => 1, 'authority_financial' => 1, 'authority_territory' => 1, 'authority_legitimacy' => 2,
          'network_trust' => 2, 'network_dependencies' => 2, 'network_gatekeeping' => 2, 'network_influence' => 2, 'network_reputation' => 2, 'network_mobilization' => 2,
          'synthesis_pattern' => 2, 'synthesis_creativity' => 2, 'synthesis_planning' => 2, 'synthesis_decision' => 2, 'synthesis_learning' => 2, 'synthesis_memory' => 2,
        ],
      ],
      [
        'position' => 'Private / Seaman Recruit',
        'title' => 'E-2 / Junior Enlisted',
        'rank_type' => 'Enlisted',
        'scope' => 'Individual Service Member',
        'power_profile' => ['info' => 1, 'resource' => 1, 'authority' => 1, 'network' => 1, 'synthesis' => 1],
        'level_avg' => 1.0,
        'sub_dimensions' => [
          'info_scope' => 1, 'info_restrictions' => 1, 'info_classification' => 2, 'info_temporal' => 1, 'info_source_diversity' => 1, 'info_detail' => 1,
          'resource_computational' => 1, 'resource_financial' => 1, 'resource_infrastructure' => 1, 'resource_human' => 1, 'resource_energy' => 1, 'resource_time' => 1,
          'authority_legal' => 1, 'authority_jurisdiction' => 1, 'authority_hierarchy' => 1, 'authority_financial' => 1, 'authority_territory' => 1, 'authority_legitimacy' => 1,
          'network_trust' => 1, 'network_dependencies' => 1, 'network_gatekeeping' => 1, 'network_influence' => 1, 'network_reputation' => 1, 'network_mobilization' => 1,
          'synthesis_pattern' => 1, 'synthesis_creativity' => 1, 'synthesis_planning' => 1, 'synthesis_decision' => 1, 'synthesis_learning' => 1, 'synthesis_memory' => 1,
        ],
      ],
      [
        'position' => 'Private (Recruit) / Seaman Recruit',
        'title' => 'E-1 / Entry Level',
        'rank_type' => 'Entry Enlisted',
        'scope' => 'Basic Training/Entry Service Member',
        'power_profile' => ['info' => 1, 'resource' => 1, 'authority' => 1, 'network' => 1, 'synthesis' => 1],
        'level_avg' => 1.0,
        'sub_dimensions' => [
          'info_scope' => 1, 'info_restrictions' => 1, 'info_classification' => 2, 'info_temporal' => 1, 'info_source_diversity' => 1, 'info_detail' => 1,
          'resource_computational' => 1, 'resource_financial' => 1, 'resource_infrastructure' => 1, 'resource_human' => 1, 'resource_energy' => 1, 'resource_time' => 1,
          'authority_legal' => 1, 'authority_jurisdiction' => 1, 'authority_hierarchy' => 1, 'authority_financial' => 1, 'authority_territory' => 1, 'authority_legitimacy' => 1,
          'network_trust' => 1, 'network_dependencies' => 1, 'network_gatekeeping' => 1, 'network_influence' => 1, 'network_reputation' => 1, 'network_mobilization' => 1,
          'synthesis_pattern' => 1, 'synthesis_creativity' => 1, 'synthesis_planning' => 1, 'synthesis_decision' => 1, 'synthesis_learning' => 1, 'synthesis_memory' => 1,
        ],
      ],
    ];
  }

  /**
   * Get organized list of all example entities from the framework.
   */
  private function getExampleEntitiesList() {
    return [
      'US Government Executive Branch' => [
        'President of the United States', 'Secretary of Defense', 'Treasury Secretary',
        'Cabinet Secretaries', 'Supreme Court Justices', 'Federal Judges',
      ],
      'US Military - General/Flag Officers' => [
        'Chairman, Joint Chiefs of Staff', 'Combatant Commander',
        'Lieutenant General / Vice Admiral (O-9)',
        'Major General / Rear Admiral Upper (O-8)',
        'Brigadier General / Rear Admiral Lower (O-7)',
      ],
      'US Military - Field Grade Officers' => [
        'Colonel / Captain Navy (O-6)',
        'Lieutenant Colonel / Commander (O-5)',
        'Major / Lieutenant Commander (O-4)',
      ],
      'US Military - Company Grade Officers' => [
        'Captain / Lieutenant Navy (O-3)',
        'First Lieutenant / Lieutenant JG (O-2)',
        'Second Lieutenant / Ensign (O-1)',
      ],
      'US Military - Warrant Officers' => [
        'Warrant Officer 5 (CW5)',
        'Warrant Officer 4 (CW4)',
        'Warrant Officer 3 (CW3)',
        'Warrant Officer 2 (CW2)',
        'Warrant Officer 1 (WO1)',
      ],
      'US Military - Senior Enlisted' => [
        'Sergeant Major / Master Chief (E-9)',
        'First Sergeant / Senior Chief (E-8)',
        'Master Sergeant / Chief (E-7)',
        'Staff Sergeant / Petty Officer 1st (E-6)',
      ],
      'US Military - Junior Enlisted' => [
        'Sergeant / Petty Officer 2nd (E-5)',
        'Corporal / Petty Officer 3rd (E-4)',
        'Specialist / Seaman (E-4)',
        'Private First Class / Seaman Apprentice (E-3)',
        'Private / Seaman Recruit (E-2)',
        'Private Recruit / Seaman Recruit (E-1)',
      ],
      'Government Agencies' => [
        'NSA', 'CIA', 'FBI', 'DARPA', 'NASA', 'DOE', 'DEA', 'ATF', 'NIST', 'DHS', 'CISA', 'FDA',
        'EPA', 'NOAA', 'CDC', 'IRS', 'NIH', 'Social Security Administration', 'Veterans Affairs', 'USPS',
        'Federal Reserve', 'Pentagon', 'Department of Defense', 'OSHA', 'FCC', 'SEC', 'FINRA',
        'Congressional Research Service', 'Better Business Bureau', 'U.S. Census Bureau',
      ],
      'Intelligence Alliances' => [
        'Five Eyes Intelligence Alliance',
      ],
      'State & Local Government' => [
        'State Governors', 'State Supreme Courts', 'State Attorneys General', 'DMV',
        'State Health Departments', 'City Councils', 'County Sheriffs', 'Municipal Judges',
        'Regional Transit Authorities', 'State District Courts', 'City Police Departments',
        'Local BBB Offices', 'Municipal Building Permit Offices', 'Emergency Management',
      ],
      'Tech Companies' => [
        'OpenAI', 'Anthropic', 'Google', 'Microsoft', 'Meta', 'Amazon', 'Apple', 'IBM', 'NVIDIA',
        'Palantir', 'Databricks', 'Scale AI', 'Hugging Face', 'Cohere', 'DeepSeek', 'Baidu',
        'Tencent', 'Alibaba', 'SenseTime', 'Megvii', 'iFlytek', 'Yandex', 'Samsung', 'Sony',
        'Intel', 'AMD', 'Qualcomm', 'Mistral AI', 'Stability AI', 'Inflection AI', 'Adept AI',
        'Character.AI', 'Runway ML', 'Midjourney', 'xAI', 'AI21 Labs', 'Netflix', 'Uber',
        'Spotify', 'Target', 'Walmart', 'TikTok', 'Instagram', 'YouTube', 'Twitter/X',
        'LinkedIn', 'Disney+', 'Comcast', 'AT&T', 'Verizon', 'Cloudflare', 'Akamai',
        'SAP', 'Salesforce', 'Oracle', 'Siemens', 'Bosch', 'Tesla', 'Coca-Cola', 'Unilever',
        'Nike', 'DuckDuckGo', 'Notion', 'Obsidian', 'Canva', 'Grammarly', 'Zoom',
        'Bluehost', 'GoDaddy', 'WordPress', 'Linode', 'AWS', 'Azure', 'Google Cloud',
      ],
      'Cybersecurity' => [
        'CrowdStrike', 'Palo Alto Networks', 'Facebook/Twitter Content Moderation',
        'Turnitin', 'IRS Audit Systems',
      ],
      'Education & Learning' => [
        'ABCmouse', 'Khan Academy', 'Duolingo', 'Coursera', 'edX',
      ],
      'Legal Services' => [
        'Wachtell Lipton', 'Westlaw', 'Public Defenders', 'District Attorneys',
        'Law Firm Associates', 'Solo Practitioners',
      ],
      'Professional Services' => [
        'Accounting Firms (CPA)', 'Engineering Firms (PE)',
        'Architecture Firms (AIA)', 'Real Estate Agents', 'Insurance Agents',
        'Licensed Electricians', 'Licensed Plumbers', 'HVAC Technicians',
        'Licensed Cosmetologists', 'Management Consultants', 'Freelance Graphic Designers',
      ],
      'Financial Institutions' => [
        'Bank of America', 'Wells Fargo', 'Fifth Third Bank', 'Citibank', 'HSBC',
        'SWIFT Network', 'Bloomberg Terminal', 'Federal Reserve', 'Visa', 'Mastercard',
        'PayPal', 'Stripe', 'Square', 'Sovereign Wealth Funds', 'Hedge Funds',
        'Investment Banks', 'Credit Unions', 'Microfinance Institutions',
      ],
      'Healthcare Organizations' => [
        'Mayo Clinic', 'Johns Hopkins Hospital', 'Kaiser Permanente', 'HCA Healthcare',
        'Epic Systems', 'IBM Watson for Oncology', 'Radiology Partners', 'Cleveland Clinic',
        'Partners Healthcare', 'Doctors Without Borders', 'Community Health Centers',
        'Rural Health Clinics', 'Urgent Care Centers', 'Telemedicine Platforms',
      ],
      'Universities' => [
        'MIT', 'Stanford', 'Berkeley', 'Carnegie Mellon', 'Oxford', 'Cambridge', 'ETH Zurich',
        'Princeton', 'Caltech', 'Toronto', 'Harvard', 'Yale', 'UCLA', 'University of California',
        'Tsinghua University', 'Peking University', 'Seoul National University', 'Tokyo University',
        'Imperial College London', 'Technical University Munich', 'EPFL', 'National University Singapore',
        'Max Planck Institute', 'Leverhulme CFI', 'Community Colleges', 'State Universities',
        'Liberal Arts Colleges',
      ],
      'Research Institutions' => [
        'MIT Media Lab', 'DARPA', 'NIH', 'NSF', 'Max Planck Institute', 'CERN',
        'National Labs (Oak Ridge, Los Alamos, Lawrence Livermore)', 'Fermilab',
        'Jet Propulsion Laboratory', 'Argonne National Laboratory',
      ],
      'Law Enforcement' => [
        'Kansas City Police Department (KCPD)', 'Philadelphia Police Department',
        'FBI', 'DEA', 'ATF', 'U.S. Marshals', 'Secret Service', 'Border Patrol',
        'State Police', 'County Sheriffs', 'INTERPOL',
      ],
      'Transportation' => [
        'Southwest Airlines', 'Uber', 'Lyft', 'FAA', 'TSA', 'Amtrak', 'Greyhound',
        'Public Transit Authorities', 'Port Authorities', 'Airports', 'Air Traffic Control',
      ],
      'Retail & Consumer' => [
        'Amazon', 'Target', 'Walmart', 'McDonald\'s', 'Disney+', 'Netflix', 'Spotify',
        'Starbucks', 'Costco', 'Best Buy', 'Home Depot', 'Lowe\'s', 'CVS', 'Walgreens',
        'Kroger', 'Safeway', 'Whole Foods', 'IKEA', 'Zara', 'H&M',
      ],
      'Food & Hospitality' => [
        'McDonald\'s', 'Starbucks', 'Chipotle', 'Panera Bread', 'Subway',
        'Marriott', 'Hilton', 'Airbnb', 'DoorDash', 'Grubhub', 'UberEats',
        'Restaurant Chains', 'Independent Restaurants', 'Food Trucks', 'Catering Services',
      ],
      'Manufacturing & Industry' => [
        'Tesla', 'Ford', 'GM', 'Boeing', 'Lockheed Martin', 'Raytheon',
        'Caterpillar', 'John Deere', 'General Electric', 'Honeywell',
        'Aluminum Smelters', 'Steel Mills', 'Chemical Plants', 'Refineries',
      ],
      'Defense & Aerospace' => [
        'Lockheed Martin', 'Boeing', 'NASA', 'Pentagon', 'DARPA', 'Raytheon',
        'Northrop Grumman', 'SpaceX', 'Blue Origin', 'Virgin Galactic',
      ],
      'Energy & Resources' => [
        'Saudi Aramco', 'Bitcoin Network', 'CERN', 'ExxonMobil', 'Chevron',
        'Shell', 'BP', 'ConocoPhillips', 'Duke Energy', 'PG&E',
        'Nuclear Power Plants', 'Solar Farms', 'Wind Farms', 'Hydroelectric Dams',
        'Natural Gas Pipelines', 'Oil Refineries',
      ],
      'Pharmaceutical' => [
        'Pfizer', 'Moderna', 'Johnson & Johnson', 'Merck', 'AstraZeneca',
        'Novartis', 'Roche', 'GSK', 'Sanofi', 'Gilead Sciences',
        'Eli Lilly', 'Bristol Myers Squibb',
      ],
      'International Organizations' => [
        'United Nations', 'UN Security Council', 'WHO', 'WTO', 'OECD', 'NATO',
        'International Criminal Court', 'World Economic Forum', 'G7', 'European Union',
        'IPCC', 'World Bank', 'IMF', 'Cochrane Library', 'Doctors Without Borders',
      ],
      'Non-Profits & Research' => [
        'Partnership on AI', 'AI Now Institute', 'Future of Humanity Institute',
        'Center for AI Safety', 'Machine Intelligence Research Institute', 'OpenMined',
        'EleutherAI', 'Allen Institute for AI', 'OpenPhilanthropy', 'Effective Altruism',
        'Red Cross', 'Doctors Without Borders', 'Smithsonian', 'AI Forensics',
        'Ada Lovelace Institute', 'Data & Society', 'Algorithmic Justice League',
        'Montreal AI Ethics Institute', 'Center for Security and Emerging Tech',
        'AI Incident Database', 'Global Partnership on AI', 'AI Standards Hub',
        'Centre for Long-Term Resilience', 'Rethink Priorities', 'AI Objectives Institute',
        'AI Safety Support', 'Community Foundations', 'United Way', 'Habitat for Humanity',
        'Local Food Banks', 'Animal Shelters', 'Environmental NGOs',
      ],
      'Media & Publishing' => [
        'Wikipedia', 'Reuters', 'Google News', 'Fox News', 'MSNBC', 'Newsmax',
        'The Daily Wire', 'Democracy Now', 'Ground.news', 'New York Times',
        'Wall Street Journal', 'Washington Post', 'CNN', 'BBC', 'Al Jazeera',
        'Associated Press', 'Bloomberg News', 'The Economist', 'The Guardian',
        'Local Newspapers', 'Community Blogs', 'Podcasts', 'YouTube Channels',
        'Encyclopedia Britannica', 'JSTOR', 'PubMed', 'Google Scholar',
      ],
      'Social Platforms' => [
        'Facebook', 'Twitter/X', 'Instagram', 'TikTok', 'LinkedIn', 'Reddit',
        'Discord', 'Snapchat', 'Pinterest', 'Tumblr', 'WeChat', 'WhatsApp',
        'Telegram', 'Signal', 'Mastodon', 'Threads',
      ],
      'Entertainment & Sports' => [
        'Netflix', 'Hulu', 'HBO Max', 'Disney+', 'Amazon Prime Video',
        'Spotify', 'Apple Music', 'Pandora', 'SoundCloud', 'Twitch',
        'NFL', 'NBA', 'MLB', 'FIFA', 'Olympics', 'ESPN',
        'Gaming Companies (Nintendo, Sony PlayStation, Xbox)', 'Steam', 'Epic Games Store',
      ],
      'Real Estate & Construction' => [
        'Commercial Real Estate Firms', 'Residential Developers', 'Construction Companies',
        'Property Management Companies', 'Real Estate Investment Trusts (REITs)',
        'Architectural Firms', 'Engineering Firms', 'General Contractors',
      ],
      'Agriculture & Food Production' => [
        'Monsanto/Bayer', 'Cargill', 'Tyson Foods', 'Nestle', 'Unilever',
        'Archer Daniels Midland', 'John Deere', 'Family Farms', 'Cooperatives',
        'Food Distributors', 'Grocery Wholesalers',
      ],
      'Telecommunications' => [
        'AT&T', 'Verizon', 'T-Mobile', 'Sprint', 'Comcast', 'Charter Spectrum',
        'Cox Communications', 'CenturyLink', 'Frontier', 'Regional ISPs',
        'Satellite Providers', 'Fiber Optic Networks',
      ],
      'Logistics & Supply Chain' => [
        'FedEx', 'UPS', 'DHL', 'Amazon Logistics', 'Maersk', 'APL',
        'C.H. Robinson', 'XPO Logistics', 'J.B. Hunt', 'Freight Forwarders',
        'Warehouse Operators', 'Last-Mile Delivery',
      ],
      'Insurance' => [
        'State Farm', 'Allstate', 'Geico', 'Progressive', 'Nationwide',
        'MetLife', 'Prudential', 'AIG', 'Berkshire Hathaway', 'Lloyds of London',
        'Health Insurance Companies', 'Life Insurance Companies', 'Property & Casualty Insurers',
      ],
      'Consumer Electronics & Appliances' => [
        'Apple', 'Samsung', 'Sony', 'LG', 'Panasonic', 'Philips',
        'Whirlpool', 'GE Appliances', 'Dyson', 'iRobot',
      ],
      'Fitness & Wellness' => [
        'Fitbit', 'Peloton', 'MyFitnessPal', 'Strava', 'ClassPass',
        'Planet Fitness', 'LA Fitness', 'Gold\'s Gym', 'Yoga Studios',
        'Personal Trainers',
      ],
      'Notable Individuals' => [
        'Taylor Swift', 'Oprah Winfrey', 'Elon Musk', 'Mark Zuckerberg', 'Bill Gates',
        'Warren Buffett', 'Tim Cook', 'Satya Nadella', 'Jerome Powell', 'Christine Lagarde',
        'Joe Rogan', 'Cristiano Ronaldo', 'Pope Francis', 'Dalai Lama', 'Nelson Mandela',
        'Mother Teresa', 'Walter Cronkite', 'Dr. Fauci', 'Einstein', 'Mozart',
        'Martin Luther King Jr.', 'Mahatma Gandhi', 'Malala Yousafzai', 'Archbishop Desmond Tutu',
        'Ruth Bader Ginsburg', 'Ben Cohen', 'Yvon Chouinard', 'Greta Thunberg', 'Al Gore',
      ],
      'Standards & Certification' => [
        'ISO', 'IEEE', 'ICANN', 'American Bar Association', 'FDA', 'FAA', 'FCC',
        'NIST', 'ANSI', 'BSI', 'SAE', 'ASTM International', 'UL (Underwriters Laboratories)',
      ],
      'AI Systems & Automation' => [
        'ChatGPT', 'GPT-4', 'Claude', 'AlphaGo', 'AlphaFold', 'DALL-E', 'GitHub Copilot',
        'Jasper AI', 'Nest Thermostat', 'Apple Siri', 'Google Assistant', 'Amazon Alexa',
        'Automated Trading Systems', 'Robot Process Automation (RPA)', 'Manufacturing Robots',
        'Warehouse Automation', 'Self-Driving Cars', 'Drones', 'Smart Home Systems',
      ],
      'Basic Services & Infrastructure' => [
        'Water Utilities', 'Sewage Treatment', 'Waste Management', 'Recycling Centers',
        'Public Libraries', 'Post Offices', 'DMV Offices', 'Social Services',
        'Parks & Recreation', 'Public Schools', 'Fire Departments',
      ],
    ];
  }

}
