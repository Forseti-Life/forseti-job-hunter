<?php

namespace Drupal\safety_calculator\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Multi-step questionnaire form for safety assessment.
 */
class QuestionnaireStepForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'safety_calculator_questionnaire_step';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $step = 'safe') {
    // Get dimension info
    $dimension = $this->getDimensionInfo($step);
    
    if (!$dimension) {
      $this->messenger()->addError($this->t('Invalid assessment step.'));
      return new RedirectResponse(Url::fromRoute('safety_calculator.questionnaire')->toString());
    }

    // Store current step
    $form_state->set('current_step', $step);

    // Load existing responses from session
    $tempstore = \Drupal::service('tempstore.private')->get('safety_calculator');
    $responses = $tempstore->get('questionnaire_responses') ?? [];

    // Wrapper with progress bar
    $form['#prefix'] = $this->buildProgressBar($step);
    $form['#prefix'] .= '<div class="container my-5"><div class="card card-forseti p-4">';
    $form['#suffix'] = '</div></div>';

    // Dimension header
    // $safety_map_url = Url::fromRoute('forseti.safety_map')->toString();
    $form['header'] = [
      '#markup' => sprintf(
        '<div class="text-center mb-4">
          <img src="%s" alt="%s" class="questionnaire-dimension-icon mb-3">
          <h2>%s</h2>
          <p class="lead">%s</p>
          <div class="alert alert-info">
            <strong>Note:</strong> These scores are based on Philadelphia emergency services data. 
            Review the scores and they will be used in your overall safety assessment.
          </div>
        </div>',
        $dimension['icon'],
        $dimension['name'],
        $dimension['name'],
        $dimension['subtitle']
      ),
      '#weight' => -100,
    ];
    
    // Load Philadelphia baseline scores
    $baseline_scores = $this->loadBaselineScores();

    // Get questions for this dimension
    $questions = $this->getQuestionsForDimension($step);
    
    // Top navigation buttons (above questions)
    $next_step = $this->getNextStep($step);
    $prev_step = $this->getPreviousStep($step);
    
    $form['top_actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['d-flex', 'justify-content-between', 'mb-4']],
      '#weight' => -50,
    ];

    if ($prev_step) {
      $form['top_actions']['previous'] = [
        '#type' => 'submit',
        '#value' => $this->t('← Previous'),
        '#submit' => ['::submitPrevious'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['btn', 'btn-secondary']],
      ];
    }
    else {
      $form['top_actions']['previous'] = [
        '#markup' => '<div></div>',
      ];
    }

    if ($next_step) {
      $form['top_actions']['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save & Continue →'),
        '#attributes' => ['class' => ['btn', 'btn-primary', 'btn-lg']],
      ];
    }
    else {
      $form['top_actions']['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Review & Calculate Score'),
        '#attributes' => ['class' => ['btn', 'btn-success', 'btn-lg']],
      ];
    }

    foreach ($questions as $key => $question) {
      // Get baseline score for this question
      $baseline_value = $baseline_scores[$key] ?? NULL;
      
      // Build the title with the baseline score if available
      $title = $question['question'];
      if ($baseline_value !== NULL) {
        $title .= ' <span class="badge bg-primary ms-2">' . $baseline_value . '/100</span>';
      }
      
      $form[$key] = [
        '#type' => 'details',
        '#title' => $title,
        '#open' => FALSE,
        '#attributes' => ['class' => ['mb-3', 'card-forseti', 'p-3']],
      ];

      if (!empty($question['description'])) {
        $form[$key]['description'] = [
          '#markup' => '<p class="text-muted small mb-3">' . $question['description'] . '</p>',
        ];
      }

      $form[$key][$key . '_rating'] = [
        '#type' => 'range',
        '#title' => $this->t('Rating (0-100)'),
        '#min' => 0,
        '#max' => 100,
        '#step' => 5,
        '#default_value' => $baseline_value ?? 50,
        '#required' => TRUE,
        '#attributes' => [
          'class' => ['rating-slider', 'form-range'],
          'data-value' => $baseline_value ?? 50,
          'data-display-target' => $key . '-value-display',
        ],
      ];
      
      // Add value display
      $form[$key][$key . '_rating_display'] = [
        '#markup' => '<div class="slider-value-display text-center fw-bold mb-3"><span class="value" id="' . $key . '-value-display">' . ($baseline_value ?? 50) . '</span>/100</div>',
      ];

      // Add note about baseline data and contact information
      $baseline_info = $this->getBaselineInfo($key);
      $contact_info = $this->getServiceContactInfo($key);
      
      if (!empty($baseline_info) || !empty($contact_info)) {
        $info_html = '<div class="alert alert-info mt-3"><small>';
        
        if (!empty($baseline_info)) {
          $info_html .= '<strong>Philadelphia Baseline:</strong> ' . $baseline_info;
        }
        
        if (!empty($contact_info)) {
          if (!empty($baseline_info)) {
            $info_html .= '<br><br>';
          }
          $info_html .= '<strong>Contact Information:</strong><br>' . $contact_info;
        }
        
        $info_html .= '</small></div>';
        
        $form[$key]['baseline_note'] = [
          '#markup' => $info_html,
        ];
      }

      if ($question['allow_comments']) {
        $form[$key][$key . '_comment'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Additional comments (optional)'),
          '#rows' => 2,
          '#default_value' => $responses[$key . '_comment'] ?? '',
          '#attributes' => ['class' => ['form-control']],
        ];
      }
    }

    // Navigation buttons
    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['d-flex', 'justify-content-between', 'mt-4']],
      '#weight' => 100,
    ];
    
    // Add JavaScript to update slider values
    $form['#attached']['library'][] = 'safety_calculator/slider-update';

    // Previous button (if not first step)
    if ($prev_step = $this->getPreviousStep($step)) {
      $form['actions']['previous'] = [
        '#type' => 'submit',
        '#value' => $this->t('← Previous'),
        '#submit' => ['::submitPrevious'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['btn', 'btn-secondary']],
      ];
    }
    else {
      $form['actions']['previous'] = [
        '#markup' => '<div></div>',
      ];
    }

    // Save & Continue button
    if ($next_step = $this->getNextStep($step)) {
      $form['actions']['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save & Continue →'),
        '#attributes' => ['class' => ['btn', 'btn-primary', 'btn-lg']],
      ];
    }
    else {
      // Last step - go to review
      $form['actions']['next'] = [
        '#type' => 'submit',
        '#value' => $this->t('Review & Calculate Score'),
        '#attributes' => ['class' => ['btn', 'btn-success', 'btn-lg']],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $step = $form_state->get('current_step');
    
    // Save responses to session
    $tempstore = \Drupal::service('tempstore.private')->get('safety_calculator');
    $responses = $tempstore->get('questionnaire_responses') ?? [];
    
    // Load baseline scores since disabled fields don't submit
    $baseline_scores = $this->loadBaselineScores();
    $questions = $this->getQuestionsForDimension($step);
    
    // Merge baseline values for this dimension
    foreach ($questions as $key => $question) {
      $rating_key = $key . '_rating';
      if (isset($baseline_scores[$key])) {
        $responses[$rating_key] = $baseline_scores[$key];
      }
      // Comments are disabled, so set empty
      $responses[$key . '_comment'] = '';
    }
    
    $tempstore->set('questionnaire_responses', $responses);

    // Navigate to next step
    $next_step = $this->getNextStep($step);
    if ($next_step) {
      $form_state->setRedirect('safety_calculator.questionnaire_step', ['step' => $next_step]);
    }
    else {
      // Go to review page
      $form_state->setRedirect('safety_calculator.review');
    }

    $this->messenger()->addStatus($this->t('Progress saved! Moving to next section.'));
  }

  /**
   * Submit handler for previous button.
   */
  public function submitPrevious(array &$form, FormStateInterface $form_state) {
    $step = $form_state->get('current_step');
    $prev_step = $this->getPreviousStep($step);
    
    if ($prev_step) {
      $form_state->setRedirect('safety_calculator.questionnaire_step', ['step' => $prev_step]);
    }
  }

  /**
   * Build progress bar.
   */
  protected function buildProgressBar($current_step) {
    $steps = $this->getAllSteps();
    $current_index = array_search($current_step, $steps);
    $progress = round((($current_index + 1) / count($steps)) * 100);

    return sprintf(
      '<div class="container my-4">
        <div class="progress" style="height: 30px;">
          <div class="progress-bar bg-primary" role="progressbar" style="width: %d%%" aria-valuenow="%d" aria-valuemin="0" aria-valuemax="100">
            <span class="fw-bold">Step %d of %d (%d%%)</span>
          </div>
        </div>
      </div>',
      $progress,
      $progress,
      $current_index + 1,
      count($steps),
      $progress
    );
  }

  /**
   * Get all assessment steps.
   */
  protected function getAllSteps() {
    return ['safe', 'energized', 'connected', 'free', 'capable', 'useful', 'whole'];
  }

  /**
   * Get next step.
   */
  protected function getNextStep($current) {
    $steps = $this->getAllSteps();
    $index = array_search($current, $steps);
    return $index !== FALSE && isset($steps[$index + 1]) ? $steps[$index + 1] : NULL;
  }

  /**
   * Get previous step.
   */
  protected function getPreviousStep($current) {
    $steps = $this->getAllSteps();
    $index = array_search($current, $steps);
    return $index !== FALSE && $index > 0 ? $steps[$index - 1] : NULL;
  }

  /**
   * Get dimension info.
   */
  protected function getDimensionInfo($step) {
    $dimensions = [
      'safe' => [
        'name' => $this->t('Safe'),
        'subtitle' => $this->t('Security & Protection'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_safe.png',
      ],
      'energized' => [
        'name' => $this->t('Energized'),
        'subtitle' => $this->t('Vitality & Basic Needs'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_energized.png',
      ],
      'connected' => [
        'name' => $this->t('Connected'),
        'subtitle' => $this->t('Community & Belonging'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_connected.png',
      ],
      'free' => [
        'name' => $this->t('Free'),
        'subtitle' => $this->t('Autonomy & Rights'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_free.png',
      ],
      'capable' => [
        'name' => $this->t('Capable'),
        'subtitle' => $this->t('Mastery & Development'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_capable.png',
      ],
      'useful' => [
        'name' => $this->t('Useful'),
        'subtitle' => $this->t('Purpose & Contribution'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_useful.png',
      ],
      'whole' => [
        'name' => $this->t('Whole'),
        'subtitle' => $this->t('Holistic Health & Identity'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_whole.png',
      ],
    ];

    return $dimensions[$step] ?? NULL;
  }

  /**
   * Get rating options.
   */
  protected function getRatingOptions() {
    $options = [];
    for ($i = 0; $i <= 100; $i += 10) {
      if ($i == 0) {
        $options[(string)$i] = '0 - ' . $this->t('Very Poor');
      }
      elseif ($i == 100) {
        $options[(string)$i] = '100 - ' . $this->t('Excellent');
      }
      else {
        $options[(string)$i] = (string)$i;
      }
    }
    return $options;
  }

  /**
   * Get questions for a dimension.
   */
  protected function getQuestionsForDimension($dimension) {
    $all_questions = [
      'safe' => [
        'police' => [
          'question' => $this->t('Police / Law Enforcement'),
          'description' => $this->t('Availability, response time, and effectiveness of local police services'),
          'allow_comments' => FALSE,
        ],
        'fire' => [
          'question' => $this->t('Fire Department / Fire Rescue'),
          'description' => $this->t('Fire prevention, suppression, and rescue services'),
          'allow_comments' => FALSE,
        ],
        'ems' => [
          'question' => $this->t('Emergency Medical Services (EMS/Ambulance)'),
          'description' => $this->t('Paramedics and emergency medical response'),
          'allow_comments' => FALSE,
        ],
        'dispatch' => [
          'question' => $this->t('Emergency Dispatch / 911 Services'),
          'description' => $this->t('Quality and reliability of emergency call handling'),
          'allow_comments' => FALSE,
        ],
        'emergency_management' => [
          'question' => $this->t('Emergency Management / Disaster Response'),
          'description' => $this->t('Preparedness and response to natural disasters and major emergencies'),
          'allow_comments' => FALSE,
        ],
        'public_health' => [
          'question' => $this->t('Public Health Emergency Response'),
          'description' => $this->t('Disease outbreak response and public health crises'),
          'allow_comments' => FALSE,
        ],
        'poison_control' => [
          'question' => $this->t('Poison Control Centers'),
          'description' => $this->t('Access to poison control information and emergency guidance'),
          'allow_comments' => FALSE,
        ],
        'mental_health_crisis' => [
          'question' => $this->t('Mental Health Crisis Teams'),
          'description' => $this->t('Specialized response for mental health emergencies'),
          'allow_comments' => FALSE,
        ],
        'road_services' => [
          'question' => $this->t('Emergency Road / Highway Services'),
          'description' => $this->t('Road maintenance, accident response, and traffic management'),
          'allow_comments' => FALSE,
        ],
        'public_works' => [
          'question' => $this->t('Public Works Emergency Response'),
          'description' => $this->t('Infrastructure maintenance and emergency repairs'),
          'allow_comments' => FALSE,
        ],
        'utilities' => [
          'question' => $this->t('Utility Emergency Services'),
          'description' => $this->t('Emergency response for water, power, and gas services'),
          'allow_comments' => FALSE,
        ],
        'search_rescue' => [
          'question' => $this->t('Search and Rescue (SAR)'),
          'description' => $this->t('Missing persons, wilderness rescue, and recovery operations'),
          'allow_comments' => FALSE,
        ],
        'hazmat' => [
          'question' => $this->t('Hazardous Materials (HAZMAT) Response'),
          'description' => $this->t('Chemical spills, toxic materials, and environmental hazards'),
          'allow_comments' => FALSE,
        ],
        'bomb_squad' => [
          'question' => $this->t('Bomb Squad / Explosive Ordnance Disposal'),
          'description' => $this->t('Handling suspicious packages and explosive threats'),
          'allow_comments' => FALSE,
        ],
        'coast_guard' => [
          'question' => $this->t('Coast Guard / Maritime Emergency Services'),
          'description' => $this->t('Water rescue and maritime safety (if applicable)'),
          'allow_comments' => FALSE,
        ],
        'emergency_shelters' => [
          'question' => $this->t('Emergency Shelters'),
          'description' => $this->t('Availability of emergency housing during disasters'),
          'allow_comments' => FALSE,
        ],
        'food_water_distribution' => [
          'question' => $this->t('Emergency Food / Water Distribution'),
          'description' => $this->t('Emergency supplies during crises'),
          'allow_comments' => FALSE,
        ],
        'emergency_housing' => [
          'question' => $this->t('Emergency Housing Assistance'),
          'description' => $this->t('Temporary housing support for displaced residents'),
          'allow_comments' => FALSE,
        ],
        'crisis_counseling' => [
          'question' => $this->t('Crisis Counseling Services'),
          'description' => $this->t('Mental health support during emergencies and trauma'),
          'allow_comments' => FALSE,
        ],
        'neighborhood_safety' => [
          'question' => $this->t('Neighborhood Safety Perception'),
          'description' => $this->t('How safe do you feel walking in your neighborhood?'),
          'allow_comments' => TRUE,
        ],
        'domestic_violence' => [
          'question' => $this->t('Domestic Violence Support Services'),
          'description' => $this->t('Resources and protection for victims of domestic abuse'),
          'allow_comments' => FALSE,
        ],
        'child_protection' => [
          'question' => $this->t('Child Protection Services'),
          'description' => $this->t('Systems to protect children from abuse and neglect'),
          'allow_comments' => FALSE,
        ],
        'elder_abuse_prevention' => [
          'question' => $this->t('Elder Abuse Prevention'),
          'description' => $this->t('Protection and support for vulnerable seniors'),
          'allow_comments' => FALSE,
        ],
        'cybersecurity' => [
          'question' => $this->t('Cybersecurity and Digital Safety'),
          'description' => $this->t('Protection from online threats and digital crimes'),
          'allow_comments' => FALSE,
        ],
        'fraud_protection' => [
          'question' => $this->t('Fraud and Scam Protection'),
          'description' => $this->t('Resources to prevent and respond to financial fraud'),
          'allow_comments' => FALSE,
        ],
        'workplace_safety' => [
          'question' => $this->t('Workplace Safety Standards'),
          'description' => $this->t('Enforcement of occupational safety regulations'),
          'allow_comments' => FALSE,
        ],
        'building_safety' => [
          'question' => $this->t('Building and Housing Code Enforcement'),
          'description' => $this->t('Inspections and standards for safe structures'),
          'allow_comments' => FALSE,
        ],
        'traffic_safety' => [
          'question' => $this->t('Traffic Safety Programs'),
          'description' => $this->t('Pedestrian safety, traffic calming, road design'),
          'allow_comments' => FALSE,
        ],
        'animal_control' => [
          'question' => $this->t('Animal Control Services'),
          'description' => $this->t('Protection from dangerous animals and animal welfare'),
          'allow_comments' => FALSE,
        ],
        'legal_aid' => [
          'question' => $this->t('Access to Legal Aid and Protection'),
          'description' => $this->t('Legal representation and rights protection services'),
          'allow_comments' => FALSE,
        ],
      ],
      // Add questions for other dimensions
      'energized' => [
        'housing_quality' => [
          'question' => $this->t('How would you rate the quality and affordability of housing?'),
          'description' => '',
          'allow_comments' => TRUE,
        ],
        'housing_stability' => [
          'question' => $this->t('How stable is your housing situation?'),
          'description' => $this->t('Threat of eviction, homelessness, or displacement'),
          'allow_comments' => FALSE,
        ],
        'housing_maintenance' => [
          'question' => $this->t('How well-maintained is housing in your area?'),
          'description' => $this->t('Structural integrity, repairs, upkeep'),
          'allow_comments' => FALSE,
        ],
        'housing_size' => [
          'question' => $this->t('Is housing adequately sized for resident needs?'),
          'description' => $this->t('Overcrowding, space per person'),
          'allow_comments' => FALSE,
        ],
        'food_access' => [
          'question' => $this->t('How easy is it to access fresh, healthy food?'),
          'description' => $this->t('Grocery stores, farmers markets, etc.'),
          'allow_comments' => TRUE,
        ],
        'food_affordability' => [
          'question' => $this->t('How affordable is nutritious food?'),
          'description' => $this->t('Cost of healthy options vs. income'),
          'allow_comments' => FALSE,
        ],
        'food_variety' => [
          'question' => $this->t('How much variety in food choices is available?'),
          'description' => $this->t('Cultural foods, dietary restrictions, preferences'),
          'allow_comments' => FALSE,
        ],
        'food_assistance' => [
          'question' => $this->t('How accessible are food assistance programs?'),
          'description' => $this->t('SNAP, food banks, meal programs'),
          'allow_comments' => FALSE,
        ],
        'employment' => [
          'question' => $this->t('How available are employment opportunities?'),
          'description' => '',
          'allow_comments' => TRUE,
        ],
        'job_quality' => [
          'question' => $this->t('What is the quality of available jobs?'),
          'description' => $this->t('Wages, benefits, working conditions'),
          'allow_comments' => FALSE,
        ],
        'job_stability' => [
          'question' => $this->t('How stable are jobs in your area?'),
          'description' => $this->t('Layoffs, turnover, seasonal work'),
          'allow_comments' => FALSE,
        ],
        'job_training' => [
          'question' => $this->t('How available is job training and skills development?'),
          'description' => $this->t('On-the-job training, certifications'),
          'allow_comments' => FALSE,
        ],
        'water_quality' => [
          'question' => $this->t('How safe and reliable is drinking water?'),
          'description' => $this->t('Quality, contamination, availability'),
          'allow_comments' => FALSE,
        ],
        'electricity' => [
          'question' => $this->t('How reliable is electricity service?'),
          'description' => $this->t('Outages, affordability, capacity'),
          'allow_comments' => FALSE,
        ],
        'heating_cooling' => [
          'question' => $this->t('How adequate are heating and cooling systems?'),
          'description' => $this->t('Climate control, energy costs'),
          'allow_comments' => FALSE,
        ],
        'internet_access' => [
          'question' => $this->t('How accessible is reliable internet service?'),
          'description' => $this->t('Broadband availability, speed, cost'),
          'allow_comments' => FALSE,
        ],
        'income_stability' => [
          'question' => $this->t('How financially stable is your household?'),
          'description' => '',
          'allow_comments' => TRUE,
        ],
        'savings_ability' => [
          'question' => $this->t('How able are residents to save money?'),
          'description' => $this->t('Emergency funds, long-term savings'),
          'allow_comments' => FALSE,
        ],
        'debt_burden' => [
          'question' => $this->t('How manageable is household debt?'),
          'description' => $this->t('Credit cards, loans, medical debt'),
          'allow_comments' => FALSE,
        ],
        'banking_access' => [
          'question' => $this->t('How accessible are banking services?'),
          'description' => $this->t('Banks, credit unions, check cashing'),
          'allow_comments' => FALSE,
        ],
        'transportation' => [
          'question' => $this->t('How accessible is reliable transportation?'),
          'description' => $this->t('Public transit, road conditions, parking'),
          'allow_comments' => TRUE,
        ],
        'transportation_cost' => [
          'question' => $this->t('How affordable is transportation?'),
          'description' => $this->t('Transit fares, vehicle costs, fuel prices'),
          'allow_comments' => FALSE,
        ],
        'childcare' => [
          'question' => $this->t('How available is affordable childcare?'),
          'description' => $this->t('Daycare, preschool, after-school care'),
          'allow_comments' => FALSE,
        ],
        'eldercare' => [
          'question' => $this->t('How available are elder care services?'),
          'description' => $this->t('Home care, assisted living, nursing homes'),
          'allow_comments' => FALSE,
        ],
        'clothing_access' => [
          'question' => $this->t('How accessible is affordable clothing?'),
          'description' => $this->t('Retail, thrift stores, donation programs'),
          'allow_comments' => FALSE,
        ],
        'household_goods' => [
          'question' => $this->t('How affordable are household necessities?'),
          'description' => $this->t('Furniture, appliances, cleaning supplies'),
          'allow_comments' => FALSE,
        ],
        'sanitation' => [
          'question' => $this->t('How reliable are sanitation services?'),
          'description' => $this->t('Trash collection, recycling, sewage'),
          'allow_comments' => FALSE,
        ],
        'emergency_funds' => [
          'question' => $this->t('How available are emergency financial resources?'),
          'description' => $this->t('Assistance programs, loans, charity'),
          'allow_comments' => FALSE,
        ],
        'insurance_access' => [
          'question' => $this->t('How accessible is essential insurance?'),
          'description' => $this->t('Health, auto, renters/home insurance'),
          'allow_comments' => FALSE,
        ],
        'technology_access' => [
          'question' => $this->t('How accessible are essential technologies?'),
          'description' => $this->t('Phones, computers, devices'),
          'allow_comments' => FALSE,
        ],
      ],
      'connected' => [
        'neighborhood_cohesion' => [
          'question' => $this->t('How connected do you feel to your neighbors?'),
          'description' => $this->t('Trust, familiarity, mutual support'),
          'allow_comments' => TRUE,
        ],
        'neighbor_interaction' => [
          'question' => $this->t('How often do residents interact with neighbors?'),
          'description' => $this->t('Conversations, gatherings, helping each other'),
          'allow_comments' => FALSE,
        ],
        'neighborhood_identity' => [
          'question' => $this->t('How strong is the sense of neighborhood identity?'),
          'description' => $this->t('Pride of place, shared history, belonging'),
          'allow_comments' => FALSE,
        ],
        'community_engagement' => [
          'question' => $this->t('How engaged is the community in local activities?'),
          'description' => $this->t('Events, meetings, volunteer initiatives'),
          'allow_comments' => TRUE,
        ],
        'community_meetings' => [
          'question' => $this->t('How active are community meetings and forums?'),
          'description' => $this->t('Town halls, block meetings, civic associations'),
          'allow_comments' => FALSE,
        ],
        'community_events' => [
          'question' => $this->t('How frequent are community social events?'),
          'description' => $this->t('Festivals, block parties, celebrations'),
          'allow_comments' => FALSE,
        ],
        'social_support' => [
          'question' => $this->t('How available is social support when needed?'),
          'description' => $this->t('Friends, family, community networks'),
          'allow_comments' => TRUE,
        ],
        'family_connections' => [
          'question' => $this->t('How connected are you with family?'),
          'description' => $this->t('Proximity, communication, relationships'),
          'allow_comments' => FALSE,
        ],
        'friendship_networks' => [
          'question' => $this->t('How strong are friendship networks?'),
          'description' => $this->t('Close friends, social circles, companionship'),
          'allow_comments' => FALSE,
        ],
        'professional_networks' => [
          'question' => $this->t('How robust are professional networks?'),
          'description' => $this->t('Colleagues, industry connections, business relationships'),
          'allow_comments' => FALSE,
        ],
        'cultural_diversity' => [
          'question' => $this->t('How well does the community embrace cultural diversity?'),
          'description' => '',
          'allow_comments' => TRUE,
        ],
        'cultural_events' => [
          'question' => $this->t('How available are multicultural events and celebrations?'),
          'description' => $this->t('Cultural festivals, heritage months, diversity programs'),
          'allow_comments' => FALSE,
        ],
        'interfaith_connection' => [
          'question' => $this->t('How connected are different faith communities?'),
          'description' => $this->t('Interfaith dialogue, cooperation, understanding'),
          'allow_comments' => FALSE,
        ],
        'public_spaces' => [
          'question' => $this->t('How accessible are public gathering spaces?'),
          'description' => $this->t('Parks, libraries, community centers'),
          'allow_comments' => TRUE,
        ],
        'third_places' => [
          'question' => $this->t('How available are informal gathering places?'),
          'description' => $this->t('Cafes, plazas, shops where people socialize'),
          'allow_comments' => FALSE,
        ],
        'park_spaces' => [
          'question' => $this->t('How welcoming are parks and recreation spaces?'),
          'description' => $this->t('Safety, maintenance, programming'),
          'allow_comments' => FALSE,
        ],
        'digital_connectivity' => [
          'question' => $this->t('How connected are residents digitally?'),
          'description' => $this->t('Internet access, social media, digital literacy'),
          'allow_comments' => TRUE,
        ],
        'online_community' => [
          'question' => $this->t('How active are online community groups?'),
          'description' => $this->t('Facebook groups, Nextdoor, local forums'),
          'allow_comments' => FALSE,
        ],
        'digital_inclusion' => [
          'question' => $this->t('How inclusive is digital community participation?'),
          'description' => $this->t('Access for all ages, abilities, backgrounds'),
          'allow_comments' => FALSE,
        ],
        'youth_programs' => [
          'question' => $this->t('How available are programs for youth and families?'),
          'description' => $this->t('After-school programs, recreation, mentorship'),
          'allow_comments' => TRUE,
        ],
        'teen_spaces' => [
          'question' => $this->t('How available are spaces and activities for teens?'),
          'description' => $this->t('Teen centers, sports, arts programs'),
          'allow_comments' => FALSE,
        ],
        'senior_programs' => [
          'question' => $this->t('How connected are senior residents?'),
          'description' => $this->t('Senior centers, activities, social opportunities'),
          'allow_comments' => FALSE,
        ],
        'intergenerational' => [
          'question' => $this->t('How much intergenerational interaction occurs?'),
          'description' => $this->t('Programs connecting different age groups'),
          'allow_comments' => FALSE,
        ],
        'faith_community' => [
          'question' => $this->t('How active are faith-based communities?'),
          'description' => $this->t('Churches, mosques, temples, synagogues, fellowship'),
          'allow_comments' => FALSE,
        ],
        'clubs_organizations' => [
          'question' => $this->t('How available are clubs and social organizations?'),
          'description' => $this->t('Hobby groups, sports leagues, interest-based groups'),
          'allow_comments' => FALSE,
        ],
        'support_groups' => [
          'question' => $this->t('How accessible are peer support groups?'),
          'description' => $this->t('Recovery, grief, health conditions, parenting'),
          'allow_comments' => FALSE,
        ],
        'isolation_prevention' => [
          'question' => $this->t('How well does the community address social isolation?'),
          'description' => $this->t('Outreach, check-ins, connection programs'),
          'allow_comments' => FALSE,
        ],
        'newcomer_integration' => [
          'question' => $this->t('How welcoming is the community to newcomers?'),
          'description' => $this->t('Integration programs, orientation, friendliness'),
          'allow_comments' => FALSE,
        ],
        'language_connection' => [
          'question' => $this->t('How connected are non-English speakers?'),
          'description' => $this->t('Language access, ESL programs, multilingual resources'),
          'allow_comments' => FALSE,
        ],
        'disability_inclusion' => [
          'question' => $this->t('How included are people with disabilities?'),
          'description' => $this->t('Accessibility, accommodation, social integration'),
          'allow_comments' => FALSE,
        ],
      ],
      'free' => [
        'personal_autonomy' => [
          'question' => $this->t('How free are you to make personal life choices?'),
          'description' => $this->t('Career, relationships, lifestyle decisions'),
          'allow_comments' => TRUE,
        ],
        'career_choice' => [
          'question' => $this->t('How free are you to choose your career path?'),
          'description' => $this->t('Job opportunities, career changes, entrepreneurship'),
          'allow_comments' => FALSE,
        ],
        'education_choice' => [
          'question' => $this->t('How much choice exists in education options?'),
          'description' => $this->t('School selection, curriculum, learning methods'),
          'allow_comments' => FALSE,
        ],
        'housing_choice' => [
          'question' => $this->t('How free are residents to choose where to live?'),
          'description' => $this->t('Neighborhood choice, housing options, mobility'),
          'allow_comments' => FALSE,
        ],
        'civil_liberties' => [
          'question' => $this->t('How protected are civil rights and liberties?'),
          'description' => $this->t('Speech, assembly, privacy, due process'),
          'allow_comments' => TRUE,
        ],
        'free_speech' => [
          'question' => $this->t('How protected is freedom of speech?'),
          'description' => $this->t('Expression of opinions, criticism, dissent'),
          'allow_comments' => FALSE,
        ],
        'free_press' => [
          'question' => $this->t('How free and independent is local journalism?'),
          'description' => $this->t('Media diversity, investigative reporting, censorship'),
          'allow_comments' => FALSE,
        ],
        'assembly_rights' => [
          'question' => $this->t('How protected is the right to peaceful assembly?'),
          'description' => $this->t('Protests, demonstrations, gatherings'),
          'allow_comments' => FALSE,
        ],
        'privacy_rights' => [
          'question' => $this->t('How protected is personal privacy?'),
          'description' => $this->t('Surveillance, data collection, intrusion'),
          'allow_comments' => FALSE,
        ],
        'discrimination' => [
          'question' => $this->t('How free is the community from discrimination?'),
          'description' => $this->t('Based on race, gender, religion, orientation, etc.'),
          'allow_comments' => TRUE,
        ],
        'equal_treatment' => [
          'question' => $this->t('How equal is treatment across different groups?'),
          'description' => $this->t('Access to services, opportunities, justice'),
          'allow_comments' => FALSE,
        ],
        'hate_crime_response' => [
          'question' => $this->t('How effectively are hate crimes addressed?'),
          'description' => $this->t('Reporting, prosecution, prevention'),
          'allow_comments' => FALSE,
        ],
        'workplace_discrimination' => [
          'question' => $this->t('How free are workplaces from discrimination?'),
          'description' => $this->t('Hiring, promotion, treatment'),
          'allow_comments' => FALSE,
        ],
        'economic_freedom' => [
          'question' => $this->t('How free are residents to pursue economic opportunities?'),
          'description' => $this->t('Start businesses, change careers, economic mobility'),
          'allow_comments' => TRUE,
        ],
        'business_startup' => [
          'question' => $this->t('How easy is it to start a business?'),
          'description' => $this->t('Regulations, licensing, barriers to entry'),
          'allow_comments' => FALSE,
        ],
        'entrepreneurship' => [
          'question' => $this->t('How supported is entrepreneurship?'),
          'description' => $this->t('Resources, mentoring, access to capital'),
          'allow_comments' => FALSE,
        ],
        'property_rights' => [
          'question' => $this->t('How protected are property rights?'),
          'description' => $this->t('Ownership, control, fair compensation'),
          'allow_comments' => FALSE,
        ],
        'movement_freedom' => [
          'question' => $this->t('How freely can residents move throughout the area?'),
          'description' => $this->t('Without barriers, fear, or excessive cost'),
          'allow_comments' => TRUE,
        ],
        'pedestrian_freedom' => [
          'question' => $this->t('How freely can people walk in the community?'),
          'description' => $this->t('Sidewalks, crosswalks, pedestrian infrastructure'),
          'allow_comments' => FALSE,
        ],
        'transit_freedom' => [
          'question' => $this->t('How free is access to different areas via transit?'),
          'description' => $this->t('Route coverage, frequency, affordability'),
          'allow_comments' => FALSE,
        ],
        'information_access' => [
          'question' => $this->t('How accessible is information and transparency?'),
          'description' => $this->t('Government data, public records, news sources'),
          'allow_comments' => TRUE,
        ],
        'public_records' => [
          'question' => $this->t('How accessible are public records?'),
          'description' => $this->t('Freedom of information requests, open data'),
          'allow_comments' => FALSE,
        ],
        'government_transparency' => [
          'question' => $this->t('How transparent is local government?'),
          'description' => $this->t('Open meetings, budget visibility, accountability'),
          'allow_comments' => FALSE,
        ],
        'voting_access' => [
          'question' => $this->t('How free and accessible is voting?'),
          'description' => $this->t('Registration, polling places, barriers'),
          'allow_comments' => FALSE,
        ],
        'political_participation' => [
          'question' => $this->t('How free are residents to participate politically?'),
          'description' => $this->t('Run for office, campaign, advocate'),
          'allow_comments' => FALSE,
        ],
        'legal_representation' => [
          'question' => $this->t('How accessible is legal representation?'),
          'description' => $this->t('Public defenders, legal aid, fair trials'),
          'allow_comments' => FALSE,
        ],
        'due_process' => [
          'question' => $this->t('How protected are due process rights?'),
          'description' => $this->t('Fair hearings, legal protections, justice'),
          'allow_comments' => FALSE,
        ],
        'consumer_rights' => [
          'question' => $this->t('How protected are consumer rights?'),
          'description' => $this->t('Fair transactions, fraud protection, recourse'),
          'allow_comments' => FALSE,
        ],
        'tenant_rights' => [
          'question' => $this->t('How protected are tenant rights?'),
          'description' => $this->t('Fair leases, eviction protection, habitability'),
          'allow_comments' => FALSE,
        ],
        'worker_rights' => [
          'question' => $this->t('How protected are worker rights?'),
          'description' => $this->t('Fair wages, safe conditions, collective bargaining'),
          'allow_comments' => FALSE,
        ],
      ],
      'capable' => [
        'education_quality' => [
          'question' => $this->t('How high-quality is the education system?'),
          'description' => $this->t('K-12 schools, academic performance'),
          'allow_comments' => TRUE,
        ],
        'early_childhood' => [
          'question' => $this->t('How available is early childhood education?'),
          'description' => $this->t('Pre-K, Head Start, developmental programs'),
          'allow_comments' => FALSE,
        ],
        'elementary_quality' => [
          'question' => $this->t('What is the quality of elementary education?'),
          'description' => $this->t('Teaching, resources, student outcomes'),
          'allow_comments' => FALSE,
        ],
        'secondary_quality' => [
          'question' => $this->t('What is the quality of middle and high schools?'),
          'description' => $this->t('College prep, graduation rates, achievement'),
          'allow_comments' => FALSE,
        ],
        'special_education' => [
          'question' => $this->t('How available are special education services?'),
          'description' => $this->t('IEPs, accommodations, support'),
          'allow_comments' => FALSE,
        ],
        'gifted_programs' => [
          'question' => $this->t('How available are gifted and advanced programs?'),
          'description' => $this->t('Honors, AP, enrichment'),
          'allow_comments' => FALSE,
        ],
        'higher_education' => [
          'question' => $this->t('How accessible is higher education?'),
          'description' => $this->t('Colleges, universities, affordability'),
          'allow_comments' => TRUE,
        ],
        'community_college' => [
          'question' => $this->t('How accessible are community colleges?'),
          'description' => $this->t('Proximity, programs, cost'),
          'allow_comments' => FALSE,
        ],
        'university_access' => [
          'question' => $this->t('How accessible are four-year universities?'),
          'description' => $this->t('Location, admission, financial aid'),
          'allow_comments' => FALSE,
        ],
        'financial_aid' => [
          'question' => $this->t('How available is financial aid for education?'),
          'description' => $this->t('Scholarships, grants, loans'),
          'allow_comments' => FALSE,
        ],
        'vocational_training' => [
          'question' => $this->t('How available is job training and skill development?'),
          'description' => $this->t('Trade schools, certifications, apprenticeships'),
          'allow_comments' => TRUE,
        ],
        'apprenticeships' => [
          'question' => $this->t('How available are apprenticeship programs?'),
          'description' => $this->t('Trades, skilled labor, earn while learning'),
          'allow_comments' => FALSE,
        ],
        'certification_programs' => [
          'question' => $this->t('How accessible are professional certifications?'),
          'description' => $this->t('Industry credentials, licenses, qualifications'),
          'allow_comments' => FALSE,
        ],
        'digital_skills' => [
          'question' => $this->t('How accessible are digital literacy programs?'),
          'description' => $this->t('Computer skills, coding, technology training'),
          'allow_comments' => TRUE,
        ],
        'coding_programs' => [
          'question' => $this->t('How available are coding and tech bootcamps?'),
          'description' => $this->t('Programming, web development, IT skills'),
          'allow_comments' => FALSE,
        ],
        'basic_computer' => [
          'question' => $this->t('How available is basic computer training?'),
          'description' => $this->t('Email, internet, word processing'),
          'allow_comments' => FALSE,
        ],
        'libraries_resources' => [
          'question' => $this->t('How available are library and learning resources?'),
          'description' => $this->t('Public libraries, online resources, makerspaces'),
          'allow_comments' => TRUE,
        ],
        'library_programs' => [
          'question' => $this->t('How robust are library educational programs?'),
          'description' => $this->t('Workshops, classes, tutoring'),
          'allow_comments' => FALSE,
        ],
        'online_learning' => [
          'question' => $this->t('How accessible are online learning platforms?'),
          'description' => $this->t('Internet access, devices, courses'),
          'allow_comments' => FALSE,
        ],
        'lifelong_learning' => [
          'question' => $this->t('How supported is lifelong learning?'),
          'description' => $this->t('Adult education, workshops, professional development'),
          'allow_comments' => TRUE,
        ],
        'adult_education' => [
          'question' => $this->t('How available are adult basic education programs?'),
          'description' => $this->t('GED, literacy, numeracy'),
          'allow_comments' => FALSE,
        ],
        'esl_programs' => [
          'question' => $this->t('How accessible are English language learning programs?'),
          'description' => $this->t('ESL, ESOL, citizenship classes'),
          'allow_comments' => FALSE,
        ],
        'professional_development' => [
          'question' => $this->t('How available is professional development?'),
          'description' => $this->t('Continuing education, career advancement'),
          'allow_comments' => FALSE,
        ],
        'financial_literacy' => [
          'question' => $this->t('How available is financial literacy education?'),
          'description' => $this->t('Budgeting, investing, money management'),
          'allow_comments' => FALSE,
        ],
        'health_literacy' => [
          'question' => $this->t('How available is health literacy education?'),
          'description' => $this->t('Understanding health info, medical decisions'),
          'allow_comments' => FALSE,
        ],
        'civic_education' => [
          'question' => $this->t('How available is civic literacy education?'),
          'description' => $this->t('Government, voting, civic engagement'),
          'allow_comments' => FALSE,
        ],
        'career_counseling' => [
          'question' => $this->t('How available is career counseling?'),
          'description' => $this->t('Job search, resume help, career planning'),
          'allow_comments' => FALSE,
        ],
        'tutoring' => [
          'question' => $this->t('How accessible are tutoring services?'),
          'description' => $this->t('Academic support, homework help, test prep'),
          'allow_comments' => FALSE,
        ],
        'arts_education' => [
          'question' => $this->t('How available is arts and music education?'),
          'description' => $this->t('Classes, lessons, programs'),
          'allow_comments' => FALSE,
        ],
        'stem_programs' => [
          'question' => $this->t('How available are STEM education programs?'),
          'description' => $this->t('Science, technology, engineering, math'),
          'allow_comments' => FALSE,
        ],
      ],
      'useful' => [
        'employment_meaning' => [
          'question' => $this->t('How meaningful is available employment?'),
          'description' => $this->t('Purpose-driven work, not just jobs'),
          'allow_comments' => TRUE,
        ],
        'work_purpose' => [
          'question' => $this->t('How much purpose do people find in their work?'),
          'description' => $this->t('Making a difference, contributing value'),
          'allow_comments' => FALSE,
        ],
        'job_satisfaction' => [
          'question' => $this->t('How satisfied are workers with their roles?'),
          'description' => $this->t('Fulfillment, recognition, engagement'),
          'allow_comments' => FALSE,
        ],
        'social_enterprise' => [
          'question' => $this->t('How available are social enterprise opportunities?'),
          'description' => $this->t('Mission-driven businesses, social impact'),
          'allow_comments' => FALSE,
        ],
        'volunteer_opportunities' => [
          'question' => $this->t('How available are volunteer opportunities?'),
          'description' => $this->t('Community service, nonprofits, causes'),
          'allow_comments' => TRUE,
        ],
        'nonprofit_engagement' => [
          'question' => $this->t('How active is the nonprofit sector?'),
          'description' => $this->t('Charities, community organizations, services'),
          'allow_comments' => FALSE,
        ],
        'volunteer_matching' => [
          'question' => $this->t('How easy is it to find volunteer roles?'),
          'description' => $this->t('Matching services, recruitment, opportunities'),
          'allow_comments' => FALSE,
        ],
        'skill_volunteering' => [
          'question' => $this->t('How available are skills-based volunteer opportunities?'),
          'description' => $this->t('Using professional skills for good'),
          'allow_comments' => FALSE,
        ],
        'civic_participation' => [
          'question' => $this->t('How encouraged is civic participation?'),
          'description' => $this->t('Voting, town halls, advisory boards'),
          'allow_comments' => TRUE,
        ],
        'advisory_boards' => [
          'question' => $this->t('How accessible are civic advisory roles?'),
          'description' => $this->t('Boards, commissions, committees'),
          'allow_comments' => FALSE,
        ],
        'public_comment' => [
          'question' => $this->t('How valued is public comment and input?'),
          'description' => $this->t('Hearings, feedback, community voice'),
          'allow_comments' => FALSE,
        ],
        'activism' => [
          'question' => $this->t('How supported is community activism?'),
          'description' => $this->t('Advocacy, organizing, campaigns'),
          'allow_comments' => FALSE,
        ],
        'mentorship' => [
          'question' => $this->t('How available are mentorship opportunities?'),
          'description' => $this->t('Sharing knowledge, coaching, teaching others'),
          'allow_comments' => TRUE,
        ],
        'youth_mentoring' => [
          'question' => $this->t('How available is youth mentorship?'),
          'description' => $this->t('Big Brothers/Sisters, tutoring, coaching'),
          'allow_comments' => FALSE,
        ],
        'professional_mentoring' => [
          'question' => $this->t('How available is professional mentorship?'),
          'description' => $this->t('Career guidance, skill sharing, networking'),
          'allow_comments' => FALSE,
        ],
        'peer_mentoring' => [
          'question' => $this->t('How available are peer support roles?'),
          'description' => $this->t('Lived experience, recovery, counseling'),
          'allow_comments' => FALSE,
        ],
        'community_projects' => [
          'question' => $this->t('How accessible are community improvement projects?'),
          'description' => $this->t('Neighborhood cleanups, gardens, beautification'),
          'allow_comments' => TRUE,
        ],
        'community_gardens' => [
          'question' => $this->t('How available are community gardening opportunities?'),
          'description' => $this->t('Shared gardens, urban farms, green space'),
          'allow_comments' => FALSE,
        ],
        'neighborhood_cleanup' => [
          'question' => $this->t('How active are neighborhood cleanup efforts?'),
          'description' => $this->t('Litter pickup, beautification, maintenance'),
          'allow_comments' => FALSE,
        ],
        'placemaking' => [
          'question' => $this->t('How involved are residents in placemaking?'),
          'description' => $this->t('Public space design, murals, improvements'),
          'allow_comments' => FALSE,
        ],
        'creative_expression' => [
          'question' => $this->t('How supported is creative and artistic contribution?'),
          'description' => $this->t('Arts programs, performances, public art'),
          'allow_comments' => TRUE,
        ],
        'public_art' => [
          'question' => $this->t('How accessible are public art opportunities?'),
          'description' => $this->t('Murals, sculptures, installations'),
          'allow_comments' => FALSE,
        ],
        'performance_spaces' => [
          'question' => $this->t('How available are spaces for creative performance?'),
          'description' => $this->t('Stages, galleries, showcases'),
          'allow_comments' => FALSE,
        ],
        'teaching_opportunities' => [
          'question' => $this->t('How available are opportunities to teach others?'),
          'description' => $this->t('Classes, workshops, skill sharing'),
          'allow_comments' => FALSE,
        ],
        'caregiving_support' => [
          'question' => $this->t('How recognized and supported are caregivers?'),
          'description' => $this->t('Family care, respite, appreciation'),
          'allow_comments' => FALSE,
        ],
        'elder_wisdom' => [
          'question' => $this->t('How valued are elders\' contributions?'),
          'description' => $this->t('Wisdom sharing, storytelling, guidance'),
          'allow_comments' => FALSE,
        ],
        'youth_leadership' => [
          'question' => $this->t('How encouraged is youth leadership?'),
          'description' => $this->t('Youth councils, programs, voice'),
          'allow_comments' => FALSE,
        ],
        'environmental_stewardship' => [
          'question' => $this->t('How available are environmental stewardship roles?'),
          'description' => $this->t('Conservation, sustainability, green initiatives'),
          'allow_comments' => FALSE,
        ],
        'legacy_building' => [
          'question' => $this->t('How supported is legacy and impact building?'),
          'description' => $this->t('Long-term projects, enduring contributions'),
          'allow_comments' => FALSE,
        ],
        'social_innovation' => [
          'question' => $this->t('How encouraged is social innovation?'),
          'description' => $this->t('New solutions, experiments, creative approaches'),
          'allow_comments' => FALSE,
        ],
      ],
      'whole' => [
        'healthcare_access' => [
          'question' => $this->t('How accessible is quality healthcare?'),
          'description' => $this->t('Primary care, specialists, hospitals'),
          'allow_comments' => TRUE,
        ],
        'primary_care' => [
          'question' => $this->t('How accessible are primary care physicians?'),
          'description' => $this->t('Family doctors, general practitioners'),
          'allow_comments' => FALSE,
        ],
        'specialists' => [
          'question' => $this->t('How accessible are medical specialists?'),
          'description' => $this->t('Cardiology, orthopedics, etc.'),
          'allow_comments' => FALSE,
        ],
        'emergency_care' => [
          'question' => $this->t('How accessible is emergency medical care?'),
          'description' => $this->t('Emergency rooms, urgent care'),
          'allow_comments' => FALSE,
        ],
        'mental_health' => [
          'question' => $this->t('How available are mental health services?'),
          'description' => $this->t('Counseling, therapy, crisis support'),
          'allow_comments' => TRUE,
        ],
        'therapy_counseling' => [
          'question' => $this->t('How accessible is therapy and counseling?'),
          'description' => $this->t('Individual, family, group therapy'),
          'allow_comments' => FALSE,
        ],
        'psychiatry' => [
          'question' => $this->t('How accessible are psychiatric services?'),
          'description' => $this->t('Medication management, psychiatric care'),
          'allow_comments' => FALSE,
        ],
        'crisis_mental_health' => [
          'question' => $this->t('How available is mental health crisis intervention?'),
          'description' => $this->t('Crisis lines, mobile teams, emergency support'),
          'allow_comments' => FALSE,
        ],
        'substance_support' => [
          'question' => $this->t('How available is substance abuse support?'),
          'description' => $this->t('Treatment programs, recovery resources'),
          'allow_comments' => TRUE,
        ],
        'addiction_treatment' => [
          'question' => $this->t('How accessible is addiction treatment?'),
          'description' => $this->t('Inpatient, outpatient, detox'),
          'allow_comments' => FALSE,
        ],
        'recovery_support' => [
          'question' => $this->t('How available are recovery support services?'),
          'description' => $this->t('Meetings, peer support, sober living'),
          'allow_comments' => FALSE,
        ],
        'harm_reduction' => [
          'question' => $this->t('How available are harm reduction services?'),
          'description' => $this->t('Naloxone, needle exchange, safe use'),
          'allow_comments' => FALSE,
        ],
        'preventive_care' => [
          'question' => $this->t('How accessible is preventive and wellness care?'),
          'description' => $this->t('Screenings, vaccinations, health education'),
          'allow_comments' => TRUE,
        ],
        'health_screenings' => [
          'question' => $this->t('How accessible are health screenings?'),
          'description' => $this->t('Cancer, diabetes, cholesterol, blood pressure'),
          'allow_comments' => FALSE,
        ],
        'vaccinations' => [
          'question' => $this->t('How accessible are vaccinations?'),
          'description' => $this->t('Routine, flu, travel, immunizations'),
          'allow_comments' => FALSE,
        ],
        'health_education' => [
          'question' => $this->t('How available is health education?'),
          'description' => $this->t('Classes, programs, information'),
          'allow_comments' => FALSE,
        ],
        'dental_care' => [
          'question' => $this->t('How accessible is dental care?'),
          'description' => $this->t('Dentists, cleanings, treatments'),
          'allow_comments' => FALSE,
        ],
        'vision_care' => [
          'question' => $this->t('How accessible is vision care?'),
          'description' => $this->t('Eye exams, glasses, contacts'),
          'allow_comments' => FALSE,
        ],
        'hearing_care' => [
          'question' => $this->t('How accessible is hearing care?'),
          'description' => $this->t('Audiology, hearing aids, testing'),
          'allow_comments' => FALSE,
        ],
        'recreation_fitness' => [
          'question' => $this->t('How available are recreation and fitness options?'),
          'description' => $this->t('Gyms, sports, trails, wellness programs'),
          'allow_comments' => TRUE,
        ],
        'fitness_facilities' => [
          'question' => $this->t('How accessible are fitness facilities?'),
          'description' => $this->t('Gyms, recreation centers, pools'),
          'allow_comments' => FALSE,
        ],
        'outdoor_recreation' => [
          'question' => $this->t('How available are outdoor recreation spaces?'),
          'description' => $this->t('Trails, parks, sports fields'),
          'allow_comments' => FALSE,
        ],
        'healthy_food' => [
          'question' => $this->t('How accessible are healthy food options?'),
          'description' => $this->t('Fresh produce, nutrition programs, farmers markets'),
          'allow_comments' => TRUE,
        ],
        'nutrition_education' => [
          'question' => $this->t('How available is nutrition education?'),
          'description' => $this->t('Classes, counseling, meal planning'),
          'allow_comments' => FALSE,
        ],
        'environmental_health' => [
          'question' => $this->t('How healthy is the physical environment?'),
          'description' => $this->t('Air quality, green spaces, pollution levels'),
          'allow_comments' => TRUE,
        ],
        'air_quality' => [
          'question' => $this->t('How good is the air quality?'),
          'description' => $this->t('Pollution, allergens, industrial emissions'),
          'allow_comments' => FALSE,
        ],
        'water_safety' => [
          'question' => $this->t('How safe is the water supply?'),
          'description' => $this->t('Drinking water, contamination, testing'),
          'allow_comments' => FALSE,
        ],
        'environmental_hazards' => [
          'question' => $this->t('How protected are residents from environmental hazards?'),
          'description' => $this->t('Lead, asbestos, chemicals, toxins'),
          'allow_comments' => FALSE,
        ],
        'identity_acceptance' => [
          'question' => $this->t('How accepted are diverse identities and lifestyles?'),
          'description' => $this->t('LGBTQ+, disability, cultural, religious'),
          'allow_comments' => TRUE,
        ],
        'disability_services' => [
          'question' => $this->t('How accessible are disability services?'),
          'description' => $this->t('Support, accommodations, accessibility'),
          'allow_comments' => FALSE,
        ],
      ],
    ];

    return $all_questions[$dimension] ?? [];
  }

  /**
   * Load Philadelphia baseline scores from JSON file.
   */
  protected function loadBaselineScores() {
    $module_path = \Drupal::service('extension.list.module')->getPath('safety_calculator');
    $json_file = $module_path . '/data/philadelphia_baseline_scores.json';
    
    if (!file_exists($json_file)) {
      return [];
    }
    
    $json_data = file_get_contents($json_file);
    $data = json_decode($json_data, TRUE);
    
    // Map the baseline scores to question keys
    $scores = [];
    
    // Load Safe dimension scores
    if (!empty($data['scores'])) {
      foreach ($data['scores'] as $key => $score_data) {
        $scores[$key] = $score_data['score'];
      }
    }
    
    // Load Energized dimension scores
    if (!empty($data['energized_scores'])) {
      foreach ($data['energized_scores'] as $key => $score_data) {
        $scores[$key] = $score_data['score'];
      }
    }
    
    // Load Connected dimension scores
    if (!empty($data['connected_scores'])) {
      foreach ($data['connected_scores'] as $key => $score_data) {
        $scores[$key] = $score_data['score'];
      }
    }
    
    // Load Free dimension scores
    if (!empty($data['free_scores'])) {
      foreach ($data['free_scores'] as $key => $score_data) {
        $scores[$key] = $score_data['score'];
      }
    }
    
    // Load Capable dimension scores
    if (!empty($data['capable_scores'])) {
      foreach ($data['capable_scores'] as $key => $score_data) {
        $scores[$key] = $score_data['score'];
      }
    }
    
    // Load Useful dimension scores
    if (!empty($data['useful_scores'])) {
      foreach ($data['useful_scores'] as $key => $score_data) {
        $scores[$key] = $score_data['score'];
      }
    }
    
    // Load Whole dimension scores
    if (!empty($data['whole_scores'])) {
      foreach ($data['whole_scores'] as $key => $score_data) {
        $scores[$key] = $score_data['score'];
      }
    }
    
    return $scores;
  }

  /**
   * Get baseline information note for a question.
   */
  protected function getBaselineInfo($question_key) {
    $module_path = \Drupal::service('extension.list.module')->getPath('safety_calculator');
    $json_file = $module_path . '/data/philadelphia_baseline_scores.json';
    
    if (!file_exists($json_file)) {
      return '';
    }
    
    $json_data = file_get_contents($json_file);
    $data = json_decode($json_data, TRUE);
    
    // Check all dimension score sections for notes
    $score_sections = [
      'scores',
      'energized_scores',
      'connected_scores',
      'free_scores',
      'capable_scores',
      'useful_scores',
      'whole_scores',
    ];
    
    foreach ($score_sections as $section) {
      if (!empty($data[$section][$question_key]['notes'])) {
        return $data[$section][$question_key]['notes'];
      }
    }
    
    return NULL;
  }

  /**
   * Get contact information for services.
   */
  protected function getServiceContactInfo($question_key) {
    $contacts = [
      // Safe dimension
      'police' => '📞 Emergency: 911 | Non-Emergency: (215) 686-8477<br>🌐 <a href="https://www.phillypolice.com" target="_blank">Philadelphia Police Department</a>',
      'fire' => '📞 Emergency: 911 | Non-Emergency: (215) 686-1300<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-fire-department" target="_blank">Philadelphia Fire Department</a>',
      'ems' => '📞 Emergency: 911<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-fire-department/programs/emergency-medical-services" target="_blank">Philadelphia Fire Department EMS</a>',
      'hospital_access' => '📞 Health: 311 | Crisis: 988<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'mental_health_crisis' => '📞 Crisis: 988 | (215) 685-6440<br>🌐 <a href="https://dbhids.org" target="_blank">Department of Behavioral Health and Intellectual disAbility Services (DBHIDS)</a>',
      'domestic_violence' => '📞 Hotline: (866) 723-3014<br>🌐 <a href="https://www.womenagainstabuse.org" target="_blank">Women Against Abuse</a>',
      'child_protection' => '📞 Child Abuse Hotline: (215) 683-6100<br>🌐 <a href="https://www.phila.gov/departments/department-of-human-services" target="_blank">Philadelphia Department of Human Services</a>',
      'poison_control' => '📞 Hotline: (800) 222-1222<br>🌐 <a href="https://www.poison.org" target="_blank">Poison Control Centers</a>',
      'crisis_counseling' => '📞 Crisis: 988 | Text: 741741<br>🌐 <a href="https://dbhids.org/mental-health" target="_blank">DBHIDS Mental Health Services</a>',
      'elder_abuse_prevention' => '📞 Adult Protective Services: (215) 765-9040<br>🌐 <a href="https://www.pcacares.org" target="_blank">Philadelphia Corporation for Aging</a>',
      'neighborhood_safety' => '📞 Non-Emergency: (215) 686-8477 | 311<br>🌐 <a href="https://www.phillypolice.com" target="_blank">Philadelphia Police Department</a>',
      'cybersecurity' => '📞 Report: (215) 686-8477<br>🌐 <a href="https://www.ic3.gov" target="_blank">FBI Internet Crime Complaint Center</a> | <a href="https://www.phila.gov/departments/office-of-innovation-and-technology" target="_blank">Office of Innovation & Technology</a>',
      'fraud_protection' => '📞 Hotline: (800) 441-2555<br>🌐 <a href="https://www.attorneygeneral.gov/protect-yourself/consumer-protection" target="_blank">Pennsylvania Office of Attorney General</a>',
      'workplace_safety' => '📞 Hotline: (800) 321-6742<br>🌐 <a href="https://www.osha.gov" target="_blank">Occupational Safety and Health Administration (OSHA)</a>',
      'building_safety' => '📞 L&I: (215) 686-2480 | 311<br>🌐 <a href="https://www.phila.gov/departments/department-of-licenses-and-inspections" target="_blank">Philadelphia Department of Licenses & Inspections</a>',
      'traffic_safety' => '📞 Report: 311<br>🌐 <a href="https://www.phila.gov/departments/streets-department" target="_blank">Philadelphia Streets Department</a> | <a href="https://visionzero.phila.gov" target="_blank">Vision Zero Philly</a>',
      'animal_control' => '📞 ACCT: (267) 385-3800<br>🌐 <a href="https://acctphilly.org" target="_blank">ACCT Philly</a>',
      'legal_aid' => '📞 Hotline: (215) 981-3800<br>🌐 <a href="https://www.philalegal.org" target="_blank">Philadelphia Legal Assistance</a> | <a href="https://clsphila.org" target="_blank">Community Legal Services</a>',
      
      // Safe dimension - Additional emergency services
      'dispatch' => '📞 Emergency: 911<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-fire-department" target="_blank">Philadelphia Fire Department Emergency Communications</a>',
      'emergency_management' => '📞 Office: (215) 686-3605<br>🌐 <a href="https://www.phila.gov/departments/office-of-emergency-management" target="_blank">Philadelphia Office of Emergency Management</a>',
      'public_health' => '📞 Department: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'road_services' => '📞 Streets: 311<br>🌐 <a href="https://www.phila.gov/departments/streets-department" target="_blank">Philadelphia Streets Department</a>',
      'public_works' => '📞 Services: 311<br>🌐 <a href="https://www.phila.gov/departments" target="_blank">City of Philadelphia Departments</a>',
      'utilities' => '📞 Water: (215) 685-6300 | Electric: (800) 494-4000 | Gas: (215) 235-1000<br>🌐 <a href="https://www.phila.gov/water" target="_blank">Philadelphia Water Department</a> | <a href="https://www.peco.com" target="_blank">PECO Energy</a> | <a href="https://www.pgworks.com" target="_blank">Philadelphia Gas Works</a>',
      'search_rescue' => '📞 Emergency: 911<br>🌐 <a href="https://www.phillypolice.com" target="_blank">Philadelphia Police Department</a>',
      'hazmat' => '📞 Emergency: 911 | Fire: (215) 686-1300<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-fire-department" target="_blank">Philadelphia Fire Department HAZMAT</a>',
      'bomb_squad' => '📞 Emergency: 911 | Police: (215) 686-8477<br>🌐 <a href="https://www.phillypolice.com" target="_blank">Philadelphia Police Department Bomb Squad</a>',
      'coast_guard' => '📞 Station: (215) 271-4807<br>🌐 <a href="https://www.uscg.mil" target="_blank">United States Coast Guard</a>',
      'emergency_shelters' => '📞 Hotline: (215) 232-1984<br>🌐 <a href="https://www.phila.gov/services/assistance-programs/get-homeless-services" target="_blank">Philadelphia Office of Homeless Services</a>',
      'food_water_distribution' => '📞 Philabundance: (215) 339-0900<br>🌐 <a href="https://www.philabundance.org" target="_blank">Philabundance</a>',
      'emergency_housing' => '📞 Housing Authority: (215) 684-4000<br>🌐 <a href="https://www.pha.phila.gov" target="_blank">Philadelphia Housing Authority</a>',
      
      // Energized dimension
      'housing_quality' => '📞 L&I: (215) 686-2480 | 311<br>🌐 <a href="https://www.phila.gov/departments/department-of-licenses-and-inspections" target="_blank">Philadelphia Department of Licenses & Inspections</a>',
      'housing_affordability' => '📞 Housing Authority: (215) 684-4000<br>🌐 <a href="https://www.pha.phila.gov" target="_blank">Philadelphia Housing Authority</a>',
      'food_access' => '📞 Philabundance: (215) 339-0900<br>🌐 <a href="https://www.philabundance.org" target="_blank">Philabundance</a> | <a href="https://www.phila.gov/services/mental-physical-health/food-assistance" target="_blank">City Food Assistance</a>',
      'employment_availability' => '📞 Office: (215) 557-2625<br>🌐 <a href="https://www.philaworks.org" target="_blank">Philadelphia Works</a>',
      'water_access' => '📞 Water Department: (215) 685-6300<br>🌐 <a href="https://www.phila.gov/water" target="_blank">Philadelphia Water Department</a>',
      'electricity_access' => '📞 Electric: (800) 494-4000<br>🌐 <a href="https://www.peco.com" target="_blank">PECO Energy</a>',
      'internet_access' => '📞 Program: (215) 686-4999<br>🌐 <a href="https://www.phila.gov/programs/phlconnected" target="_blank">PHLConnectED Digital Equity Program</a>',
      'banking_access' => '📞 Hotline: (855) 411-2372<br>🌐 <a href="https://www.consumerfinance.gov" target="_blank">Consumer Financial Protection Bureau</a>',
      'transportation_affordability' => '📞 Customer Service: (215) 580-7800<br>🌐 <a href="https://www.septa.org" target="_blank">Southeastern Pennsylvania Transportation Authority (SEPTA)</a>',
      'childcare_availability' => '📞 Program: (215) 686-9295<br>🌐 <a href="https://www.phila.gov/programs/phlprek" target="_blank">PHLpreK</a>',
      'eldercare_availability' => '📞 Information: (215) 765-9000<br>🌐 <a href="https://www.pcacares.org" target="_blank">Philadelphia Corporation for Aging</a>',
      'healthcare_affordability' => '📞 Enrollment: (844) 844-8040<br>🌐 <a href="https://www.healthcare.gov" target="_blank">HealthCare.gov</a>',
      'emergency_resources' => '📞 City Services: 311<br>🌐 <a href="https://www.phila.gov/services" target="_blank">City of Philadelphia Services</a>',
      
      // Energized dimension - Additional services
      'housing_stability' => '📞 Eviction Prevention: (215) 686-9741<br>🌐 <a href="https://www.phila.gov/services/assistance-programs/apply-for-housing-services" target="_blank">Philadelphia Housing Services</a>',
      'housing_maintenance' => '📞 L&I: (215) 686-2480 | 311<br>🌐 <a href="https://www.phila.gov/departments/department-of-licenses-and-inspections" target="_blank">Philadelphia Department of Licenses & Inspections</a>',
      'housing_size' => '📞 Fair Housing: (215) 686-4670<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-commission-on-human-relations" target="_blank">Philadelphia Commission on Human Relations</a>',
      'housing_options' => '📞 Housing Authority: (215) 684-4000<br>🌐 <a href="https://www.pha.phila.gov" target="_blank">Philadelphia Housing Authority</a>',
      'food_affordability' => '📞 SNAP: (215) 560-7226<br>🌐 <a href="https://www.phila.gov/services/mental-physical-health/food-assistance" target="_blank">Supplemental Nutrition Assistance Program (SNAP)</a>',
      'food_quality' => '📞 Health Department: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'food_security' => '📞 Philabundance: (215) 339-0900<br>🌐 <a href="https://www.philabundance.org" target="_blank">Philabundance</a>',
      'food_variety' => '📞 Information: (215) 988-8800<br>🌐 <a href="https://www.thefoodtrust.org" target="_blank">The Food Trust</a>',
      'food_assistance' => '📞 SNAP: (215) 560-7226<br>🌐 <a href="https://www.phila.gov/services/mental-physical-health/food-assistance" target="_blank">Food Assistance Programs</a>',
      'employment' => '📞 Office: (215) 557-2625<br>🌐 <a href="https://www.philaworks.org" target="_blank">Philadelphia Works</a>',
      'wage_adequacy' => '📞 Department: (800) 932-0665<br>🌐 <a href="https://www.dli.pa.gov" target="_blank">Pennsylvania Department of Labor & Industry</a>',
      'job_benefits' => '📞 UC Hotline: (888) 313-7284<br>🌐 <a href="https://www.uc.pa.gov" target="_blank">Pennsylvania Unemployment Compensation</a>',
      'job_security' => '📞 Office: (215) 557-2625<br>🌐 <a href="https://www.philaworks.org" target="_blank">Philadelphia Works</a>',
      'job_quality' => '📞 Department: (800) 932-0665<br>🌐 <a href="https://www.dli.pa.gov" target="_blank">Pennsylvania Department of Labor & Industry</a>',
      'job_stability' => '📞 Office: (215) 557-2625<br>🌐 <a href="https://www.philaworks.org" target="_blank">Philadelphia Works</a>',
      'job_training' => '📞 Office: (215) 557-2625<br>🌐 <a href="https://www.philaworks.org" target="_blank">Philadelphia Works</a>',
      'water_quality' => '📞 Water Department: (215) 685-6300<br>🌐 <a href="https://www.phila.gov/water" target="_blank">Philadelphia Water Department</a>',
      'electricity' => '📞 Electric: (800) 494-4000<br>🌐 <a href="https://www.peco.com" target="_blank">PECO Energy</a>',
      'heating_cooling' => '📞 Electric: (800) 494-4000 | Gas: (215) 235-1000<br>🌐 <a href="https://www.peco.com" target="_blank">PECO Energy</a> | <a href="https://www.pgworks.com" target="_blank">Philadelphia Gas Works</a>',
      'savings_capacity' => '📞 Financial Services: (215) 686-6880<br>🌐 <a href="https://www.phila.gov/departments/office-of-the-city-treasurer" target="_blank">Office of the City Treasurer</a>',
      'savings_ability' => '📞 Financial Services: (215) 686-6880<br>🌐 <a href="https://www.phila.gov/departments/office-of-the-city-treasurer" target="_blank">Office of the City Treasurer</a>',
      'debt_management' => '📞 Counseling: (800) 388-2227<br>🌐 <a href="https://www.nfcc.org" target="_blank">National Foundation for Credit Counseling</a>',
      'debt_burden' => '📞 Counseling: (800) 388-2227<br>🌐 <a href="https://www.nfcc.org" target="_blank">National Foundation for Credit Counseling</a>',
      'financial_security' => '📞 Benefits: (215) 560-7226<br>🌐 <a href="https://www.phila.gov/services/assistance-programs" target="_blank">City of Philadelphia Assistance Programs</a>',
      'transportation' => '📞 Customer Service: (215) 580-7800<br>🌐 <a href="https://www.septa.org" target="_blank">Southeastern Pennsylvania Transportation Authority (SEPTA)</a>',
      'transportation_cost' => '📞 Customer Service: (215) 580-7800<br>🌐 <a href="https://www.septa.org" target="_blank">SEPTA</a>',
      'transportation_reliability' => '📞 Customer Service: (215) 580-7800<br>🌐 <a href="https://www.septa.org" target="_blank">SEPTA</a>',
      'transportation_options' => '📞 Customer Service: (215) 580-7800<br>🌐 <a href="https://www.septa.org" target="_blank">SEPTA</a>',
      'transportation_safety' => '📞 SEPTA Police: (215) 580-8111<br>🌐 <a href="https://www.septa.org/safety" target="_blank">SEPTA Transit Safety</a>',
      'childcare' => '📞 Program: (215) 686-9295<br>🌐 <a href="https://www.phila.gov/programs/phlprek" target="_blank">PHLpreK</a>',
      'eldercare' => '📞 Information: (215) 765-9000<br>🌐 <a href="https://www.pcacares.org" target="_blank">Philadelphia Corporation for Aging</a>',
      'energy_affordability' => '📞 LIHEAP: (866) 857-7095<br>🌐 <a href="https://www.dhs.pa.gov/Services/Assistance/Pages/LIHEAP.aspx" target="_blank">Low-Income Home Energy Assistance Program (LIHEAP)</a>',
      'basic_needs' => '📞 City Services: 311<br>🌐 <a href="https://www.phila.gov/services/assistance-programs" target="_blank">City of Philadelphia Assistance Programs</a>',
      'emergency_funds' => '📞 Benefits: (215) 560-7226<br>🌐 <a href="https://www.phila.gov/services/assistance-programs" target="_blank">Emergency Assistance Programs</a>',
      'insurance_access' => '📞 Marketplace: (844) 844-8040<br>🌐 <a href="https://www.healthcare.gov" target="_blank">Health Insurance Marketplace</a>',
      'technology_access' => '📞 Program: (215) 686-4999<br>🌐 <a href="https://www.phila.gov/programs/phlconnected" target="_blank">PHLConnectED</a>',
      'clothing_access' => '📞 Information: 211<br>🌐 <a href="https://www.pa211.org" target="_blank">PA 211 Resource Finder</a>',
      'household_goods' => '📞 Information: 211<br>🌐 <a href="https://www.pa211.org" target="_blank">PA 211 Resource Finder</a>',
      'sanitation' => '📞 Streets: 311<br>🌐 <a href="https://www.phila.gov/departments/streets-department" target="_blank">Philadelphia Streets Department</a>',
      'income_stability' => '📞 Benefits: (215) 560-7226<br>🌐 <a href="https://www.phila.gov/services/assistance-programs" target="_blank">Income Support Programs</a>',
      'utilities' => '📞 PWD: (215) 685-6300 | PECO: (800) 494-4000<br>🌐 <a href="https://www.phila.gov/water" target="_blank">Utilities</a>',
      
      // Free dimension
      'voting_access' => '📞 Elections: (215) 686-1590<br>🌐 <a href="https://vote.phila.gov" target="_blank">Philadelphia City Commissioners</a>',
      'legal_representation' => '📞 Public Defender: (215) 568-3190<br>🌐 <a href="https://philadefender.org" target="_blank">Defender Association of Philadelphia</a>',
      'consumer_rights' => '📞 Hotline: (800) 441-2555<br>🌐 <a href="https://www.attorneygeneral.gov/protect-yourself" target="_blank">Pennsylvania Office of Attorney General</a>',
      'tenant_rights' => '📞 Hotline: (267) 443-2500<br>🌐 <a href="https://clsphila.org" target="_blank">Community Legal Services of Philadelphia</a>',
      'worker_rights' => '📞 Department: (800) 932-0665<br>🌐 <a href="https://www.dli.pa.gov" target="_blank">Pennsylvania Department of Labor & Industry</a>',
      'discrimination' => '📞 Commission: (215) 686-4670<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-commission-on-human-relations" target="_blank">Philadelphia Commission on Human Relations</a>',
      
      // Free dimension - Additional rights & freedoms
      'personal_autonomy' => '📞 Commission: (215) 686-4670<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-commission-on-human-relations" target="_blank">Philadelphia Commission on Human Relations</a>',
      'career_choice' => '📞 Office: (215) 557-2625<br>🌐 <a href="https://www.philaworks.org" target="_blank">Philadelphia Works</a>',
      'education_choice' => '📞 District: (215) 400-4000<br>🌐 <a href="https://www.philasd.org" target="_blank">School District of Philadelphia</a>',
      'housing_choice' => '📞 Commission: (215) 686-4670<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-commission-on-human-relations" target="_blank">Philadelphia Commission on Human Relations</a>',
      'civil_liberties' => '📞 ACLU: (215) 592-1513<br>🌐 <a href="https://www.aclupa.org" target="_blank">ACLU of Pennsylvania</a>',
      'free_speech' => '📞 ACLU: (215) 592-1513<br>🌐 <a href="https://www.aclupa.org" target="_blank">ACLU of Pennsylvania</a>',
      'free_press' => '📞 Reporters Committee: (215) 592-8887<br>🌐 <a href="https://www.rcfp.org" target="_blank">Reporters Committee for Freedom of the Press</a>',
      'assembly_rights' => '📞 ACLU: (215) 592-1513<br>🌐 <a href="https://www.aclupa.org" target="_blank">ACLU of Pennsylvania</a>',
      'privacy_rights' => '📞 ACLU: (215) 592-1513<br>🌐 <a href="https://www.aclupa.org" target="_blank">ACLU of Pennsylvania</a>',
      'equal_treatment' => '📞 Commission: (215) 686-4670<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-commission-on-human-relations" target="_blank">Philadelphia Commission on Human Relations</a>',
      'hate_crime_response' => '📞 Police: (215) 686-8477<br>🌐 <a href="https://www.phillypolice.com" target="_blank">Philadelphia Police Department</a>',
      'workplace_discrimination' => '📞 Commission: (215) 686-4670<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-commission-on-human-relations" target="_blank">Philadelphia Commission on Human Relations</a>',
      'economic_freedom' => '📞 PIDC: (215) 496-8020<br>🌐 <a href="https://www.pidcphila.com" target="_blank">Philadelphia Industrial Development Corporation</a>',
      'business_startup' => '📞 Commerce: (215) 686-2181<br>🌐 <a href="https://www.phila.gov/departments/department-of-commerce" target="_blank">Philadelphia Department of Commerce</a>',
      'entrepreneurship' => '📞 PIDC: (215) 496-8020<br>🌐 <a href="https://www.pidcphila.com" target="_blank">Philadelphia Industrial Development Corporation</a>',
      'property_rights' => '📞 Legal Aid: (215) 981-3800<br>🌐 <a href="https://www.philalegal.org" target="_blank">Community Legal Services of Philadelphia</a>',
      'movement_freedom' => '📞 SEPTA: (215) 580-7800<br>🌐 <a href="https://www.septa.org" target="_blank">Southeastern Pennsylvania Transportation Authority</a>',
      'pedestrian_freedom' => '📞 Phila311: (215) 686-8686<br>🌐 <a href="https://www.phila.gov/departments/streets-department" target="_blank">Philadelphia Streets Department</a>',
      'transit_freedom' => '📞 SEPTA: (215) 580-7800<br>🌐 <a href="https://www.septa.org" target="_blank">Southeastern Pennsylvania Transportation Authority</a>',
      'information_access' => '📞 Library: (215) 686-5322<br>🌐 <a href="https://www.freelibrary.org" target="_blank">Free Library of Philadelphia</a>',
      'public_records' => '📞 Archives: (215) 686-1479<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-city-archives" target="_blank">Philadelphia City Archives</a>',
      'government_transparency' => '📞 Controller: (215) 686-6680<br>🌐 <a href="https://controller.phila.gov" target="_blank">Philadelphia City Controller</a>',
      'political_participation' => '📞 Elections: (215) 686-1590<br>🌐 <a href="https://vote.phila.gov" target="_blank">Philadelphia City Commissioners</a>',
      'due_process' => '📞 Defender: (215) 568-3190<br>🌐 <a href="https://philadefender.org" target="_blank">Defender Association of Philadelphia</a>',
      
      // Connected dimension
      'neighborhood_cohesion' => '📞 Civic Engagement: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/civic-engagement-unit" target="_blank">Mayor\'s Office of Civic Engagement & Volunteer Services</a>',
      'neighbor_interaction' => '📞 Civic Engagement: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/civic-engagement-unit" target="_blank">Mayor\'s Office of Civic Engagement & Volunteer Services</a>',
      'neighborhood_identity' => '📞 Planning: (215) 686-4615<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-city-planning-commission" target="_blank">Philadelphia City Planning Commission</a>',
      'community_meetings' => '📞 Civic Engagement: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/civic-engagement-unit" target="_blank">Mayor\'s Office of Civic Engagement & Volunteer Services</a>',
      'community_events' => '📞 Parks & Rec: (215) 683-0200<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-parks-recreation" target="_blank">Philadelphia Parks & Recreation</a>',
      'community_engagement' => '📞 Civic Engagement: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/civic-engagement-unit" target="_blank">Mayor\'s Office of Civic Engagement & Volunteer Services</a>',
      'family_connections' => '📞 Human Services: (215) 683-6100<br>🌐 <a href="https://www.phila.gov/departments/department-of-human-services" target="_blank">Philadelphia Department of Human Services</a>',
      'friendship_networks' => '📞 Parks & Rec: (215) 683-0200<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-parks-recreation" target="_blank">Philadelphia Parks & Recreation</a>',
      'professional_networks' => '📞 Chamber: (215) 545-1234<br>🌐 <a href="https://www.chamberphl.com" target="_blank">Chamber of Commerce for Greater Philadelphia</a>',
      'social_support' => '📞 PA 211: Dial 211<br>🌐 <a href="https://www.pa211.org" target="_blank">PA 211 - United Way</a>',
      'cultural_diversity' => '📞 Arts & Culture: (215) 686-8449<br>🌐 <a href="https://www.creativephl.org" target="_blank">Office of Arts, Culture and the Creative Economy</a>',
      'cultural_events' => '📞 Arts & Culture: (215) 686-8449<br>🌐 <a href="https://www.creativephl.org" target="_blank">Office of Arts, Culture and the Creative Economy</a>',
      'interfaith_connection' => '📞 Mayor\'s Office: (215) 686-2181<br>🌐 <a href="https://www.phila.gov" target="_blank">City of Philadelphia</a>',
      'public_spaces' => '📞 Parks & Rec: (215) 683-0200<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-parks-recreation" target="_blank">Philadelphia Parks & Recreation</a>',
      
      // Capable dimension
      'education_access' => '📞 District: (215) 400-4000<br>🌐 <a href="https://www.philasd.org" target="_blank">School District of Philadelphia</a>',
      'education_quality' => '📞 District: (215) 400-4000<br>🌐 <a href="https://www.philasd.org" target="_blank">School District of Philadelphia</a>',
      'higher_education' => '📞 CCP: (215) 751-8010<br>🌐 <a href="https://www.ccp.edu" target="_blank">Community College of Philadelphia</a>',
      'vocational_training' => '📞 Philadelphia Works: (215) 557-2625<br>🌐 <a href="https://www.philaworks.org" target="_blank">Philadelphia Works</a>',
      'early_childhood_ed' => '📞 PHLpreK: (215) 686-9706<br>🌐 <a href="https://www.phila.gov/programs/phlprek" target="_blank">PHLpreK Program</a>',
      'literacy_programs' => '📞 Library: (215) 686-5322<br>🌐 <a href="https://www.freelibrary.org" target="_blank">Free Library of Philadelphia</a>',
      'language_access' => '📞 MOIA: (215) 686-9848<br>🌐 <a href="https://www.phila.gov/departments/mayors-office-of-immigrant-affairs" target="_blank">Mayor\'s Office of Immigrant Affairs</a>',
      'digital_literacy' => '📞 Library: (215) 686-5322<br>🌐 <a href="https://www.freelibrary.org" target="_blank">Free Library of Philadelphia</a>',
      'financial_literacy' => '📞 Commerce: (215) 686-2181<br>🌐 <a href="https://www.phila.gov/departments/department-of-commerce" target="_blank">Philadelphia Department of Commerce</a>',
      'health_literacy' => '📞 Public Health: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'civic_education' => '📞 Elections: (215) 686-1590<br>🌐 <a href="https://vote.phila.gov" target="_blank">Philadelphia City Commissioners</a>',
      'lifelong_learning' => '📞 Library: (215) 686-5322<br>🌐 <a href="https://www.freelibrary.org" target="_blank">Free Library of Philadelphia</a>',
      'skills_training' => '📞 Philadelphia Works: (215) 557-2625<br>🌐 <a href="https://www.philaworks.org" target="_blank">Philadelphia Works</a>',
      'apprenticeships' => '📞 Philadelphia Works: (215) 557-2625<br>🌐 <a href="https://www.philaworks.org" target="_blank">Philadelphia Works</a>',
      'certification' => '📞 CCP: (215) 751-8010<br>🌐 <a href="https://www.ccp.edu" target="_blank">Community College of Philadelphia</a>',
      'professional_development' => '📞 Philadelphia Works: (215) 557-2625<br>🌐 <a href="https://www.philaworks.org" target="_blank">Philadelphia Works</a>',
      'technology_skills' => '📞 Library: (215) 686-5322<br>🌐 <a href="https://www.freelibrary.org" target="_blank">Free Library of Philadelphia</a>',
      'creative_skills' => '📞 Arts & Culture: (215) 686-8449<br>🌐 <a href="https://www.creativephl.org" target="_blank">Office of Arts, Culture and the Creative Economy</a>',
      'physical_skills' => '📞 Parks & Rec: (215) 683-0200<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-parks-recreation" target="_blank">Philadelphia Parks & Recreation</a>',
      'leadership_development' => '📞 Civic Engagement: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/civic-engagement-unit" target="_blank">Mayor\'s Office of Civic Engagement & Volunteer Services</a>',
      'mentorship' => '📞 Human Services: (215) 683-6100<br>🌐 <a href="https://www.phila.gov/departments/department-of-human-services" target="_blank">Philadelphia Department of Human Services</a>',
      'tutoring' => '📞 District: (215) 400-4000<br>🌐 <a href="https://www.philasd.org" target="_blank">School District of Philadelphia</a>',
      'counseling_access' => '📞 DBHIDS: (215) 685-6440<br>🌐 <a href="https://dbhids.org" target="_blank">Department of Behavioral Health and Intellectual disAbility Services</a>',
      'career_counseling' => '📞 Philadelphia Works: (215) 557-2625<br>🌐 <a href="https://www.philaworks.org" target="_blank">Philadelphia Works</a>',
      'academic_advising' => '📞 CCP: (215) 751-8010<br>🌐 <a href="https://www.ccp.edu" target="_blank">Community College of Philadelphia</a>',
      'special_education' => '📞 District: (215) 400-4000<br>🌐 <a href="https://www.philasd.org" target="_blank">School District of Philadelphia</a>',
      'disability_services' => '📞 MOPD: (215) 686-2798<br>🌐 <a href="https://www.phila.gov/departments/mayors-office-for-people-with-disabilities" target="_blank">Mayor\'s Office for People with Disabilities</a>',
      'english_learning' => '📞 Library: (215) 686-5322<br>🌐 <a href="https://www.freelibrary.org" target="_blank">Free Library of Philadelphia</a>',
      'credential_recognition' => '📞 MOIA: (215) 686-9848<br>🌐 <a href="https://www.phila.gov/departments/mayors-office-of-immigrant-affairs" target="_blank">Mayor\'s Office of Immigrant Affairs</a>',
      'education_support' => '📞 District: (215) 400-4000<br>🌐 <a href="https://www.philasd.org" target="_blank">School District of Philadelphia</a>',
      
      // Useful dimension
      'civic_participation' => '📞 Elections: (215) 686-1590<br>🌐 <a href="https://vote.phila.gov" target="_blank">Philadelphia City Commissioners</a>',
      'voting_ease' => '📞 Elections: (215) 686-1590<br>🌐 <a href="https://vote.phila.gov" target="_blank">Philadelphia City Commissioners</a>',
      'volunteer_opportunities' => '📞 Serve Philly: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/serve-philadelphia" target="_blank">Serve Philadelphia</a>',
      'community_service' => '📞 Serve Philly: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/serve-philadelphia" target="_blank">Serve Philadelphia</a>',
      'activism' => '📞 Civic Engagement: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/civic-engagement-unit" target="_blank">Mayor\'s Office of Civic Engagement & Volunteer Services</a>',
      'advocacy' => '📞 Civic Engagement: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/civic-engagement-unit" target="_blank">Mayor\'s Office of Civic Engagement & Volunteer Services</a>',
      'neighborhood_improvement' => '📞 CLIP: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/community-life-improvement-program" target="_blank">Community Life Improvement Program</a>',
      'environmental_action' => '📞 Phila311: (215) 686-8686<br>🌐 <a href="https://www.phila.gov/departments/streets-department" target="_blank">Philadelphia Streets Department</a>',
      'youth_development' => '📞 Parks & Rec: (215) 683-0200<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-parks-recreation" target="_blank">Philadelphia Parks & Recreation</a>',
      'elder_contribution' => '📞 PCA: (215) 765-9000<br>🌐 <a href="https://www.pcacares.org" target="_blank">Philadelphia Corporation for Aging</a>',
      'skill_sharing' => '📞 Library: (215) 686-5322<br>🌐 <a href="https://www.freelibrary.org" target="_blank">Free Library of Philadelphia</a>',
      'teaching_others' => '📞 Library: (215) 686-5322<br>🌐 <a href="https://www.freelibrary.org" target="_blank">Free Library of Philadelphia</a>',
      'mentoring_others' => '📞 Human Services: (215) 683-6100<br>🌐 <a href="https://www.phila.gov/departments/department-of-human-services" target="_blank">Philadelphia Department of Human Services</a>',
      'caregiving_support' => '📞 PCA: (215) 765-9000<br>🌐 <a href="https://www.pcacares.org" target="_blank">Philadelphia Corporation for Aging</a>',
      'charitable_giving' => '📞 United Way: (215) 665-2500<br>🌐 <a href="https://www.unitedforimpact.org" target="_blank">United Way of Greater Philadelphia and Southern New Jersey</a>',
      'mutual_aid' => '📞 Civic Engagement: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/civic-engagement-unit" target="_blank">Mayor\'s Office of Civic Engagement & Volunteer Services</a>',
      'creative_contribution' => '📞 Arts & Culture: (215) 686-8449<br>🌐 <a href="https://www.creativephl.org" target="_blank">Office of Arts, Culture and the Creative Economy</a>',
      'cultural_preservation' => '📞 PHC: (215) 686-7660<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-historical-commission" target="_blank">Philadelphia Historical Commission</a>',
      'knowledge_sharing' => '📞 Library: (215) 686-5322<br>🌐 <a href="https://www.freelibrary.org" target="_blank">Free Library of Philadelphia</a>',
      'community_organizing' => '📞 Civic Engagement: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/civic-engagement-unit" target="_blank">Mayor\'s Office of Civic Engagement & Volunteer Services</a>',
      'cooperative_projects' => '📞 Commerce: (215) 686-2181<br>🌐 <a href="https://www.phila.gov/departments/department-of-commerce" target="_blank">Philadelphia Department of Commerce</a>',
      'social_enterprise' => '📞 Commerce: (215) 686-2181<br>🌐 <a href="https://www.phila.gov/departments/department-of-commerce" target="_blank">Philadelphia Department of Commerce</a>',
      'innovation' => '📞 OIT: (215) 686-2181<br>🌐 <a href="https://www.phila.gov/departments/office-of-innovation-and-technology" target="_blank">Office of Innovation and Technology</a>',
      'problem_solving' => '📞 Phila311: (215) 686-8686<br>🌐 <a href="https://www.phila.gov/services/311-city-information" target="_blank">Phila311</a>',
      'public_input' => '📞 Civic Engagement: (215) 686-3616<br>🌐 <a href="https://www.phila.gov/departments/civic-engagement-unit" target="_blank">Mayor\'s Office of Civic Engagement & Volunteer Services</a>',
      'policy_influence' => '📞 City Council: (215) 686-3475<br>🌐 <a href="https://www.phlcouncil.com" target="_blank">Philadelphia City Council</a>',
      'workforce_contribution' => '📞 Philadelphia Works: (215) 557-2625<br>🌐 <a href="https://www.philaworks.org" target="_blank">Philadelphia Works</a>',
      'economic_participation' => '📞 Commerce: (215) 686-2181<br>🌐 <a href="https://www.phila.gov/departments/department-of-commerce" target="_blank">Philadelphia Department of Commerce</a>',
      'purpose' => '📞 PA 211: Dial 211<br>🌐 <a href="https://www.pa211.org" target="_blank">PA 211 - United Way</a>',
      
      // Whole dimension
      'physical_health' => '📞 Public Health: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'emotional_wellbeing' => '📞 DBHIDS: (215) 685-6440<br>🌐 <a href="https://dbhids.org" target="_blank">Department of Behavioral Health and Intellectual disAbility Services</a>',
      'spiritual_health' => '📞 Mayor\'s Office: (215) 686-2181<br>🌐 <a href="https://www.phila.gov" target="_blank">City of Philadelphia</a>',
      'healthcare_access' => '📞 Health Centers: (215) 685-5488<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health/programs/health-centers" target="_blank">Philadelphia Department of Public Health - Health Centers</a>',
      'preventive_care' => '📞 Public Health: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'chronic_disease_management' => '📞 Public Health: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'dental_health' => '📞 Public Health: (215) 685-5488<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'vision_health' => '📞 Public Health: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'hearing_health' => '📞 Public Health: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'nutrition' => '📞 Public Health: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'physical_activity' => '📞 Parks & Rec: (215) 683-0200<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-parks-recreation" target="_blank">Philadelphia Parks & Recreation</a>',
      'sleep_quality' => '📞 Public Health: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'stress_management' => '📞 DBHIDS: (215) 685-6440<br>🌐 <a href="https://dbhids.org" target="_blank">Department of Behavioral Health and Intellectual disAbility Services</a>',
      'substance_use_support' => '📞 DBHIDS: (215) 685-6440<br>🌐 <a href="https://dbhids.org" target="_blank">Department of Behavioral Health and Intellectual disAbility Services</a>',
      'trauma_recovery' => '📞 DBHIDS: (215) 685-6440<br>🌐 <a href="https://dbhids.org" target="_blank">Department of Behavioral Health and Intellectual disAbility Services</a>',
      'personal_safety' => '📞 Police: 911 | Non-Emergency: (215) 686-3001<br>🌐 <a href="https://www.phillypolice.com" target="_blank">Philadelphia Police Department</a>',
      'air_quality' => '📞 Air Management: (215) 685-7580<br>🌐 <a href="https://www.phila.gov/departments/air-management-services" target="_blank">Philadelphia Air Management Services</a>',
      'water_safety' => '📞 PWD: (215) 685-6300<br>🌐 <a href="https://www.phila.gov/water" target="_blank">Philadelphia Water Department</a>',
      'noise_pollution' => '📞 Public Health: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'toxin_exposure' => '📞 Poison Control: (800) 222-1222<br>🌐 <a href="https://www.poison.org" target="_blank">The Poison Control Center</a>',
      'disease_prevention' => '📞 Public Health: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'immunization' => '📞 Public Health: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'maternal_health' => '📞 Maternal & Child Health: (215) 685-5300<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'child_health' => '📞 Public Health: (215) 685-6740<br>🌐 <a href="https://www.phila.gov/departments/philadelphia-department-of-public-health" target="_blank">Philadelphia Department of Public Health</a>',
      'aging_health' => '📞 PCA: (215) 765-9000<br>🌐 <a href="https://www.pcacares.org" target="_blank">Philadelphia Corporation for Aging</a>',
      'disability_health' => '📞 MOPD: (215) 686-2798<br>🌐 <a href="https://www.phila.gov/departments/mayors-office-for-people-with-disabilities" target="_blank">Mayor\'s Office for People with Disabilities</a>',
      'holistic_wellbeing' => '📞 DBHIDS: (215) 685-6440<br>🌐 <a href="https://dbhids.org" target="_blank">Department of Behavioral Health and Intellectual disAbility Services</a>',
    ];
    
    return $contacts[$question_key] ?? '';
  }

}
