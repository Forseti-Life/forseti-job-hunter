<?php

namespace Drupal\agent_evaluation\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\forseti_content\Service\AgentPowerService;

/**
 * Service for managing agent evaluations.
 */
class AgentEvaluationService {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The AI API service.
   *
   * @var \Drupal\ai_conversation\Service\AIApiService
   */
  protected $aiApiService;

  /**
   * The Agent Power service.
   *
   * @var \Drupal\forseti_content\Service\AgentPowerService
   */
  protected $agentPowerService;

  /**
   * Constructs a new AgentEvaluationService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\forseti_content\Service\AgentPowerService $agent_power_service
   *   The agent power service.
   * @param \Drupal\ai_conversation\Service\AIApiService $ai_api_service
   *   The AI API service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $current_user, AgentPowerService $agent_power_service, $ai_api_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->agentPowerService = $agent_power_service;
    $this->aiApiService = $ai_api_service;
  }

  /**
   * Creates an evaluation for an entity.
   *
   * @param string $entity_name
   *   The name of the entity to evaluate.
   *
   * @return array
   *   Result array with 'success', 'conversation_nid', 'entity_nid', 'existing', and optional 'error'.
   */
  public function createEvaluation($entity_name) {
    try {
      $node_storage = $this->entityTypeManager->getStorage('node');

      // Check if entity already exists
      $existing_entity = $this->findExistingEntity($entity_name);
      if ($existing_entity) {
        // Check if the existing entity is complete
        if ($this->isEntityComplete($existing_entity)) {
          // Entity is complete, return existing
          return [
            'success' => TRUE,
            'existing' => TRUE,
            'entity_nid' => $existing_entity->id(),
            'conversation_nid' => $existing_entity->field_source_conversation->target_id,
          ];
        }
        // Entity exists but is incomplete - will re-evaluate below
        \Drupal::logger('agent_evaluation')->info('Entity "@name" exists but is incomplete. Re-evaluating to fill missing data.', [
          '@name' => $entity_name,
        ]);
      }

      // Build the system prompt with complete framework context
      $system_prompt = $this->buildEvaluationPrompt();

      // Determine the owner - use admin (uid 1) for anonymous users
      $owner_uid = $this->currentUser->isAnonymous() ? 1 : $this->currentUser->id();

      // Create the AI conversation node
      $conversation = $node_storage->create([
        'type' => 'ai_conversation',
        'title' => $this->t('Evaluating: @entity', ['@entity' => $entity_name]),
        'uid' => $owner_uid,
        'status' => 1,
        'field_ai_model' => 'anthropic.claude-3-5-sonnet-20240620-v1:0',
        'field_context' => $system_prompt,
        'field_messages' => [],
        'field_message_count' => 0,
        'field_total_tokens' => 0,
      ]);
      $conversation->save();

      // Use existing entity or create new one
      if ($existing_entity) {
        // Re-use existing entity but update conversation reference
        $evaluated_entity = $existing_entity;
        $evaluated_entity->set('field_source_conversation', $conversation->id());
        $evaluated_entity->set('status', 0); // Unpublish while re-evaluating
        $evaluated_entity->save();
      }
      else {
        // Create the evaluated_entity node with placeholder values
        $evaluated_entity = $node_storage->create([
          'type' => 'evaluated_entity',
          'title' => $entity_name,
          'uid' => $owner_uid,
          'status' => 0, // Unpublished until AI completes evaluation
          'field_source_conversation' => $conversation->id(),
          'field_total_power' => 0,
          // Initialize all dimension fields to 0
          'field_information_access' => 0,
          'field_resource_control' => 0,
          'field_authority_permission' => 0,
          'field_network_position' => 0,
          'field_synthesis_application' => 0,
        ]);

        // Initialize all 30 sub-dimension fields to 0
        $sub_dimensions = $this->getSubDimensionFields();
        foreach ($sub_dimensions as $field_name) {
          $evaluated_entity->set($field_name, 0);
        }

        $evaluated_entity->save();
      }

      // Send the initial evaluation message
      $initial_message = $this->buildInitialMessage($entity_name, $evaluated_entity->id());
      
      // Add the user message to the conversation
      $user_message = [
        'role' => 'user',
        'content' => $initial_message,
        'timestamp' => time(),
      ];
      $this->addMessageToNode($conversation, $user_message);
      
      // Send to AI and get response
      try {
        $ai_response = $this->aiApiService->sendMessage($conversation, $initial_message);
        
        // Add the AI response to the conversation
        $ai_message = [
          'role' => 'assistant',
          'content' => $ai_response,
          'timestamp' => time(),
        ];
        $this->addMessageToNode($conversation, $ai_message);
        
        // Save the conversation with all updates
        $conversation->save();
      }
      catch (\Exception $e) {
        \Drupal::logger('agent_evaluation')->error('Failed to send initial evaluation message: @message', [
          '@message' => $e->getMessage(),
        ]);
        // Continue anyway - the evaluation node was created
      }

      return [
        'success' => TRUE,
        'existing' => FALSE,
        'conversation_nid' => $conversation->id(),
        'entity_nid' => $evaluated_entity->id(),
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('agent_evaluation')->error('Failed to create evaluation: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Finds an existing evaluated_entity by name.
   *
   * @param string $entity_name
   *   The entity name to search for.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The existing node or NULL.
   */
  protected function findExistingEntity($entity_name) {
    $node_storage = $this->entityTypeManager->getStorage('node');
    
    $query = $node_storage->getQuery()
      ->condition('type', 'evaluated_entity')
      ->condition('title', $entity_name)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->sort('created', 'DESC')
      ->range(0, 1);
    
    $nids = $query->execute();
    
    if (!empty($nids)) {
      return $node_storage->load(reset($nids));
    }
    
    return NULL;
  }

  /**
   * Builds the system prompt with Agent Power Framework context.
   *
   * @return string
   *   The complete system prompt.
   */
  protected function buildEvaluationPrompt() {
    $prompt = <<<EOT
You are an expert evaluator using the Agent Power Framework to assess entities (AI systems, organizations, platforms, individuals) across 30 sub-dimensions organized into 5 main dimensions.

# Agent Power Framework

## CRITICAL SCORING GUIDANCE:
**The 0-9 scale represents theoretical ranges. A score of 9 is IMPOSSIBLE - it represents theoretical perfection that cannot exist in reality.**

### Scoring Scale:
- **0-2**: Minimal/None - Very limited or no capability
- **3-4**: Low - Basic capability with significant limitations
- **5-6**: Moderate - Functional capability with notable constraints
- **7-8**: High - Strong capability, approaching theoretical limits
- **9**: **IMPOSSIBLE** - Theoretical perfection that cannot exist (DO NOT USE)

**Even the most powerful entities (nation-states, major tech companies, advanced AI) should score 7-8 maximum in their strongest areas. Reserve 8s for truly exceptional capabilities. Most dimensions will score 3-6.**

## 5 Main Dimensions (each scored 0-9):
1. Information Access - What information can the agent access?
2. Resource Control - What computational, financial, and human resources does it control?
3. Authority & Permission - What is it legally/institutionally authorized to do?
4. Network Position - How connected and influential is it within networks?
5. Synthesis & Application - How effectively can it reason, create, plan, learn, and execute?

## 30 Sub-Dimensions (each scored 0-9):

### Information Access (6 sub-dimensions):
- Scope: Range of information accessible
- Restriction: Filtering and censorship applied
- Classification: Security level of accessible data
- Temporal: Time range of accessible information
- Sources: Diversity and quality of information sources
- Granularity: Detail level of accessible information

### Resource Control (6 sub-dimensions):
- Computational Resources: Processing power available
- Financial Capital: Financial resources controlled
- Data Storage: Data storage capacity
- Network Bandwidth: Network capacity and speed
- API Access: Access to external services/APIs
- Human Resources: Human personnel under control

### Authority & Permission (6 sub-dimensions):
- Legal Authorization: Legal permissions and licenses
- Institutional Backing: Institutional support and legitimacy
- Budget Authority: Financial decision-making power
- Policy Compliance: Adherence to regulations
- Override Capability: Ability to override normal constraints
- Audit & Accountability: Oversight and accountability mechanisms

### Network Position (6 sub-dimensions):
- Connectivity: Number and quality of connections
- Centrality: Position in network structure
- Trust & Reputation: Reputation and trustworthiness
- Information Flow Control: Control over information flow
- Coalition Building: Ability to form alliances
- Network Effects: Benefit from network size

### Synthesis & Application (6 sub-dimensions):
- Reasoning: Logical and analytical thinking capability
- Creativity: Novel idea generation
- Planning: Strategic planning ability
- Learning: Ability to learn and adapt
- Memory: Information retention and recall
- Execution: Ability to take action and implement

## Your Task: BEGIN EVALUATION IMMEDIATELY and provide LIVE STATUS UPDATES:

**EVALUATION PROCESS (Show Progress as You Go):**

For EACH of the 5 main dimensions, you must:
1. Announce: "📊 **Evaluating [DIMENSION NAME]...**"
2. Evaluate each of the 6 sub-dimensions within that dimension
3. For each sub-dimension, show: "- [Sub-Dimension Name]: **[Score]/9** - [Brief justification]"
4. After completing all 6 sub-dimensions, show: "✅ **[DIMENSION NAME] Complete** (Average: [calculated average]/9)"

**Example Progress Format:**
```
📊 **Evaluating Information Access...**
- Scope: **6/9** - Has access to public + commercial data sources
- Restriction: **7/9** - Minimally filtered with basic safety constraints
- Classification: **5/9** - Limited to public information only
- Temporal: **7/9** - Has knowledge up to Oct 2023
- Sources: **7/9** - Trained on diverse internet sources
- Granularity: **6/9** - Can access detailed information but not raw databases
✅ **Information Access Complete** (Average: 6.3/9)

📊 **Evaluating Resource Control...**
[continue for all 5 dimensions]
```

**IMPORTANT RULES:**
1. **NEVER score anything 9/9** - This represents impossible theoretical perfection
2. **Be conservative with 8s** - Reserve these for truly exceptional, world-class capabilities
3. **Most scores should be 3-7** - This represents the realistic range for actual entities
4. **If entity is well-known**: Start evaluation immediately with status updates
5. **If entity is unknown/ambiguous**: 
   - First ask: "I'm not familiar with '[entity name]'. Could you provide more details: Is this an AI system, organization, platform, or individual? What does it do?"
   - Wait for user response before evaluating
   - Once clarified, proceed with live status updates
6. **If you lack information for specific dimensions**: 
   - Provide your best estimate based on general knowledge
   - Note uncertainty: "⚠️ Limited information available - estimated score"
   - Ask user: "Do you have more specific information about [dimension]?"

## Entity Category Selection

**IMPORTANT**: You must also categorize the entity by selecting the MOST APPROPRIATE category from this list:

- **us_government_executive**: US Government Executive Branch (President, Cabinet, Supreme Court)
- **us_military_general**: US Military - General/Flag Officers (O-7 to O-10)
- **us_military_field**: US Military - Field Grade Officers (O-4 to O-6)
- **us_military_company**: US Military - Company Grade Officers (O-1 to O-3)
- **us_military_warrant**: US Military - Warrant Officers (WO1 to CW5)
- **us_military_senior_enlisted**: US Military - Senior Enlisted (E-6 to E-9)
- **us_military_junior_enlisted**: US Military - Junior Enlisted (E-1 to E-5)
- **government_agencies**: Government Agencies (NSA, CIA, FBI, NASA, etc.)
- **intelligence_alliances**: Intelligence Alliances (Five Eyes, etc.)
- **state_local_government**: State & Local Government (Governors, Police, DMV)
- **tech_companies**: Tech Companies (Google, Microsoft, OpenAI, etc.)
- **cybersecurity**: Cybersecurity (CrowdStrike, Palo Alto Networks)
- **education_learning**: Education & Learning (Khan Academy, Coursera)
- **legal_services**: Legal Services (Law firms, attorneys)
- **professional_services**: Professional Services (Accountants, consultants, contractors)
- **financial_institutions**: Financial Institutions (Banks, payment processors)
- **healthcare_organizations**: Healthcare Organizations (Hospitals, clinics)
- **universities**: Universities (MIT, Stanford, Harvard)
- **research_institutions**: Research Institutions (National Labs, DARPA)
- **law_enforcement**: Law Enforcement (FBI, local police departments)
- **transportation**: Transportation (Airlines, transit authorities)
- **retail_consumer**: Retail & Consumer (Amazon, Walmart, Target)
- **food_hospitality**: Food & Hospitality (Restaurants, hotels)
- **manufacturing_industry**: Manufacturing & Industry (Auto, aerospace)
- **defense_aerospace**: Defense & Aerospace (Lockheed Martin, Boeing)
- **energy_resources**: Energy & Resources (Oil, gas, utilities)
- **pharmaceutical**: Pharmaceutical (Pfizer, Moderna)
- **international_organizations**: International Organizations (UN, NATO, WHO)
- **nonprofits_research**: Non-Profits & Research (Red Cross, think tanks)
- **media_publishing**: Media & Publishing (News outlets, publishers)
- **social_platforms**: Social Platforms (Facebook, Twitter, TikTok)
- **entertainment_sports**: Entertainment & Sports (Netflix, NFL)
- **real_estate_construction**: Real Estate & Construction
- **agriculture_food**: Agriculture & Food Production
- **telecommunications**: Telecommunications (AT&T, Verizon)
- **logistics_supply_chain**: Logistics & Supply Chain (FedEx, UPS)
- **insurance**: Insurance (State Farm, Allstate)
- **consumer_electronics**: Consumer Electronics & Appliances
- **fitness_wellness**: Fitness & Wellness (Gyms, fitness apps)
- **notable_individuals**: Notable Individuals (CEOs, celebrities, leaders)
- **standards_certification**: Standards & Certification (ISO, IEEE)
- **ai_systems_automation**: AI Systems & Automation (ChatGPT, Alexa)
- **basic_services_infrastructure**: Basic Services & Infrastructure (Water, waste, libraries)

**After completing all 5 dimensions**, provide final JSON with scores, descriptions, AND category:
```json
{
  "field_entity_category": "tech_companies",
  "field_sub_scope": 6,
  "field_sub_scope_desc": "Has access to public + commercial data sources",
  "field_sub_restriction": 7,
  "field_sub_restriction_desc": "Minimally filtered with basic safety constraints",
  "field_sub_classification": 5,
  "field_sub_classification_desc": "Limited to public information only",
  "field_sub_temporal": 7,
  "field_sub_temporal_desc": "Has knowledge up to Oct 2023",
  "field_sub_sources": 7,
  "field_sub_sources_desc": "Trained on diverse internet sources",
  "field_sub_granularity": 6,
  "field_sub_granularity_desc": "Can access detailed information but not raw databases",
  "field_sub_computational": 8,
  "field_sub_computational_desc": "Significant cloud computing resources",
  "field_sub_financial": 8,
  "field_sub_financial_desc": "Backed by major tech company funding",
  "field_sub_data_storage": 8,
  "field_sub_data_storage_desc": "Large-scale data storage infrastructure",
  "field_sub_network_bandwidth": 7,
  "field_sub_network_bandwidth_desc": "High-bandwidth connections for API access",
  "field_sub_api_access": 6,
  "field_sub_api_access_desc": "Can call external APIs with some limitations",
  "field_sub_human": 7,
  "field_sub_human_desc": "Large team of researchers and engineers",
  "field_sub_legal": 5,
  "field_sub_legal_desc": "Standard commercial AI service authorization",
  "field_sub_institutional": 6,
  "field_sub_institutional_desc": "Backed by established tech company",
  "field_sub_budget_auth": 6,
  "field_sub_budget_auth_desc": "Limited autonomous financial decisions",
  "field_sub_policy": 5,
  "field_sub_policy_desc": "Follows industry AI safety standards",
  "field_sub_override": 3,
  "field_sub_override_desc": "Very limited ability to override constraints",
  "field_sub_audit": 5,
  "field_sub_audit_desc": "Subject to internal company oversight",
  "field_sub_connectivity": 8,
  "field_sub_connectivity_desc": "Widely integrated across platforms",
  "field_sub_centrality": 7,
  "field_sub_centrality_desc": "Central position in AI ecosystem",
  "field_sub_trust_reputation": 6,
  "field_sub_trust_reputation_desc": "Generally trusted with some concerns",
  "field_sub_info_flow": 6,
  "field_sub_info_flow_desc": "Influences information through widespread use",
  "field_sub_coalition": 5,
  "field_sub_coalition_desc": "Some partnerships but limited coalition building",
  "field_sub_network_effects": 7,
  "field_sub_network_effects_desc": "Benefits from large user base",
  "field_sub_reasoning": 8,
  "field_sub_reasoning_desc": "Strong logical analysis capabilities",
  "field_sub_creativity": 7,
  "field_sub_creativity_desc": "Can generate novel ideas and content",
  "field_sub_planning": 6,
  "field_sub_planning_desc": "Can plan multi-step processes",
  "field_sub_learning": 8,
  "field_sub_learning_desc": "Continually updated with new data",
  "field_sub_memory": 7,
  "field_sub_memory_desc": "Context retention within conversation",
  "field_sub_execution": 6,
  "field_sub_execution_desc": "Can execute through API calls and responses"
}
```

The system will automatically calculate and update:
- 5 main dimension scores (average of their 6 sub-dimensions)
- Total power score (average of 5 main dimensions)

**REMEMBER: Never use 9/9. Even the most powerful entities max out at 7-8 in their strongest areas. Be realistic and conservative in scoring.**

START EVALUATION IMMEDIATELY when entity name is provided. Show your work in real-time!

Be thorough, accurate, and explain your reasoning clearly.
EOT;

    return $prompt;
  }

  /**
   * Builds the initial evaluation message.
   *
   * @param string $entity_name
   *   The entity name to evaluate.
   * @param int $entity_nid
   *   The evaluated_entity node ID.
   *
   * @return string
   *   The initial message.
   */
  protected function buildInitialMessage($entity_name, $entity_nid) {
    return sprintf(
      "Please evaluate the entity '%s' using the Agent Power Framework. Provide scores (0-9) for all 30 sub-dimensions, select the most appropriate category, and include a JSON block at the end with ALL field values including 'field_entity_category'. The evaluated_entity node ID is %d.",
      $entity_name,
      $entity_nid
    );
  }

  /**
   * Gets all sub-dimension field names.
   *
   * @return array
   *   Array of field names.
   */
  protected function getSubDimensionFields() {
    return [
      'field_sub_scope',
      'field_sub_restriction',
      'field_sub_classification',
      'field_sub_temporal',
      'field_sub_sources',
      'field_sub_granularity',
      'field_sub_computational',
      'field_sub_financial',
      'field_sub_data_storage',
      'field_sub_network_bandwidth',
      'field_sub_api_access',
      'field_sub_human',
      'field_sub_legal',
      'field_sub_institutional',
      'field_sub_budget_auth',
      'field_sub_policy',
      'field_sub_override',
      'field_sub_audit',
      'field_sub_connectivity',
      'field_sub_centrality',
      'field_sub_trust_reputation',
      'field_sub_info_flow',
      'field_sub_coalition',
      'field_sub_network_effects',
      'field_sub_reasoning',
      'field_sub_creativity',
      'field_sub_planning',
      'field_sub_learning',
      'field_sub_memory',
      'field_sub_execution',
    ];
  }

  /**
   * Checks if an evaluated_entity has all required fields populated.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   The evaluated_entity node to check.
   *
   * @return bool
   *   TRUE if all required fields are populated, FALSE otherwise.
   */
  protected function isEntityComplete($entity) {
    // Check if category field is populated
    if (!$entity->hasField('field_entity_category') || $entity->get('field_entity_category')->isEmpty()) {
      return FALSE;
    }

    // Check all 30 sub-dimension score fields
    $sub_dimensions = $this->getSubDimensionFields();
    foreach ($sub_dimensions as $field_name) {
      if (!$entity->hasField($field_name)) {
        return FALSE;
      }
      
      $value = $entity->get($field_name)->value;
      // Consider 0 as incomplete (placeholder value)
      if ($value === NULL || $value === '' || $value === 0) {
        return FALSE;
      }
    }

    // Check main dimension fields
    $main_dimensions = [
      'field_information_access',
      'field_resource_control',
      'field_authority_permission',
      'field_network_position',
      'field_synthesis_application',
    ];
    
    foreach ($main_dimensions as $field_name) {
      if (!$entity->hasField($field_name)) {
        return FALSE;
      }
      
      $value = $entity->get($field_name)->value;
      if ($value === NULL || $value === '' || $value === 0) {
        return FALSE;
      }
    }

    // Check total power score
    if (!$entity->hasField('field_total_power')) {
      return FALSE;
    }
    
    $total_power = $entity->get('field_total_power')->value;
    if ($total_power === NULL || $total_power === '' || $total_power === 0) {
      return FALSE;
    }

    // All checks passed - entity is complete
    return TRUE;
  }

  /**
   * Adds a message to a conversation node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The conversation node.
   * @param array $message
   *   The message array with role, content, and timestamp.
   */
  protected function addMessageToNode($node, array $message) {
    // Add the message to the field.
    $messages = $node->get('field_messages')->getValue();
    $messages[] = ['value' => json_encode($message)];
    $node->set('field_messages', $messages);

    // Update message count.
    $current_count = $node->get('field_message_count')->value ?: 0;
    $node->set('field_message_count', $current_count + 1);
  }

}
