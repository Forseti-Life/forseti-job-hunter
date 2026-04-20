<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * NFR Questionnaire Section 8: Health Information.
 */
class NFRQuestionnaireSection8Form extends FormBase {

  use QuestionnaireFormTrait;

  /**
   * Constructs a new NFRQuestionnaireSection8Form.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'nfr_questionnaire_section8_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'nfr/enrollment';

    $uid = $this->getCurrentUserId();
    
    // Load health data from database columns
    $database = $this->getDatabase();
    $questionnaire = $database->select('nfr_questionnaire', 'q')
      ->fields('q', [
        'cancer_diagnosis',
        'health_heart_disease',
        'health_copd',
        'health_asthma',
        'health_diabetes',
      ])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    
    $health = [];
    if ($questionnaire) {
      $health['cancer_diagnosed'] = $questionnaire['cancer_diagnosis'] ? 'yes' : 'no';
      
      // Load other conditions from boolean columns
      $health['other_conditions'] = [];
      if ($questionnaire['health_heart_disease']) $health['other_conditions'][] = 'heart_disease';
      if ($questionnaire['health_copd']) $health['other_conditions'][] = 'copd';
      if ($questionnaire['health_asthma']) $health['other_conditions'][] = 'asthma';
      if ($questionnaire['health_diabetes']) $health['other_conditions'][] = 'diabetes';
    }
    
    // Load cancer diagnoses from nfr_cancer_diagnoses table
    $cancers = $database->select('nfr_cancer_diagnoses', 'cd')
      ->fields('cd')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAll();
    
    $health['cancers'] = [];
    foreach ($cancers as $cancer) {
      $health['cancers'][] = [
        'type' => $cancer->cancer_type,
        'year_diagnosed' => $cancer->year_diagnosed,
      ];
    }

    // Load family cancer history
    $family_cancers = $database->select('nfr_family_cancer_history', 'fch')
      ->fields('fch')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAll();
    
    $health['family_cancers'] = [];
    foreach ($family_cancers as $fc) {
      $health['family_cancers'][] = [
        'relationship' => $fc->relationship,
        'cancer_type' => $fc->cancer_type,
        'age_at_diagnosis' => $fc->age_at_diagnosis,
      ];
    }

    // Add navigation menu
    $form['navigation'] = $this->buildNavigationMenu(8);

    $form['section_title'] = [
      '#type' => 'markup',
      '#markup' => '<h2>Section 8: Health Information</h2><p>These questions help us understand health outcomes. All information is confidential.</p>',
    ];

    $form['health'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Health Information'),
      '#tree' => TRUE,
      '#prefix' => '<div id="health">',
      '#suffix' => '</div>',
    ];

    $form['health']['cancer_diagnosed'] = [
      '#type' => 'radios',
      '#title' => $this->t('Have you ever been diagnosed with cancer?'),
      '#required' => TRUE,
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
      ],
      '#default_value' => $health['cancer_diagnosed'] ?? NULL,
      '#ajax' => [
        'callback' => '::updateCancerFields',
        'wrapper' => 'cancers-wrapper',
      ],
    ];

    // Cancer diagnoses repeating fields
    $num_cancers = $form_state->get('num_cancers') ?? count($health['cancers'] ?? []);
    
    // If user selected "yes" to cancer but has no cancers yet, default to 1
    $cancer_diagnosed = $form_state->getValue(['health', 'cancer_diagnosed']) ?? $health['cancer_diagnosed'] ?? NULL;
    if ($cancer_diagnosed === 'yes' && $num_cancers === 0) {
      $num_cancers = 1;
    }
    
    // Store the current count in form_state so addCancer can increment from the right number
    $form_state->set('num_cancers', $num_cancers);

    $form['health']['cancers'] = [
      '#type' => 'container',
      '#prefix' => '<div id="cancers-wrapper">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="health[cancer_diagnosed]"]' => ['value' => 'yes'],
        ],
      ],
    ];

    $form['health']['add_cancer'] = [
      '#type' => 'submit',
      '#value' => $this->t('+ Add Another Cancer Diagnosis'),
      '#submit' => ['::addCancer'],
      '#ajax' => [
        'callback' => '::updateCancerFields',
        'wrapper' => 'cancers-wrapper',
      ],
      '#limit_validation_errors' => [],
      '#states' => [
        'visible' => [
          ':input[name="health[cancer_diagnosed]"]' => ['value' => 'yes'],
        ],
      ],
    ];

    for ($i = 0; $i < $num_cancers; $i++) {
      $cancer_data = $health['cancers'][$i] ?? [];
      
      $form['health']['cancers'][$i] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Cancer Diagnosis @num', ['@num' => $i + 1]),
      ];

      $form['health']['cancers'][$i]['type'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Type of Cancer'),
        '#default_value' => $cancer_data['type'] ?? '',
        '#description' => $this->t('e.g., Bladder, Lung, Melanoma, Prostate, etc.'),
      ];

      $form['health']['cancers'][$i]['year_diagnosed'] = [
        '#type' => 'number',
        '#title' => $this->t('Year of Diagnosis'),
        '#min' => 1950,
        '#max' => (int) date('Y'),
        '#default_value' => $cancer_data['year_diagnosed'] ?? '',
      ];
    }

    $form['health']['other_conditions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Have you been diagnosed with any of these conditions?'),
      '#options' => [
        'heart_disease' => $this->t('Heart disease'),
        'copd' => $this->t('COPD/Chronic bronchitis'),
        'asthma' => $this->t('Asthma'),
        'diabetes' => $this->t('Diabetes'),
        'none' => $this->t('None of the above'),
      ],
      '#default_value' => $health['other_conditions'] ?? [],
    ];

    // Family cancer history
    $form['health']['has_family_cancer_history'] = [
      '#type' => 'radios',
      '#title' => $this->t('Has anyone in your immediate family (parents, siblings, children) been diagnosed with cancer?'),
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
      ],
      '#default_value' => !empty($health['family_cancers']) ? 'yes' : ($form_state->getValue(['health', 'has_family_cancer_history']) ?? NULL),
    ];

    $num_family_cancers = $form_state->get('num_family_cancers') ?? count($health['family_cancers'] ?? []);
    if ($form_state->getValue(['health', 'has_family_cancer_history']) === 'yes' && $num_family_cancers === 0) {
      $num_family_cancers = 1;
    }
    $form_state->set('num_family_cancers', $num_family_cancers);

    $form['health']['family_cancers_container'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="health[has_family_cancer_history]"]' => ['value' => 'yes'],
        ],
      ],
    ];

    for ($i = 0; $i < $num_family_cancers; $i++) {
      $fc_data = $health['family_cancers'][$i] ?? [];
      
      $form['health']['family_cancers_container'][$i] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Family Member @num', ['@num' => $i + 1]),
      ];

      $form['health']['family_cancers_container'][$i]['relationship'] = [
        '#type' => 'select',
        '#title' => $this->t('Relationship'),
        '#required' => TRUE,
        '#options' => [
          '' => $this->t('- Select -'),
          'mother' => $this->t('Mother'),
          'father' => $this->t('Father'),
          'brother' => $this->t('Brother'),
          'sister' => $this->t('Sister'),
          'son' => $this->t('Son'),
          'daughter' => $this->t('Daughter'),
        ],
        '#default_value' => $fc_data['relationship'] ?? '',
      ];

      $form['health']['family_cancers_container'][$i]['cancer_type'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Type of Cancer'),
        '#required' => TRUE,
        '#default_value' => $fc_data['cancer_type'] ?? '',
      ];

      $form['health']['family_cancers_container'][$i]['age_at_diagnosis'] = [
        '#type' => 'number',
        '#title' => $this->t('Age at Diagnosis (if known)'),
        '#min' => 0,
        '#max' => 120,
        '#default_value' => $fc_data['age_at_diagnosis'] ?? '',
      ];
    }

    $form['health']['add_family_cancer'] = [
      '#type' => 'submit',
      '#value' => $this->t('+ Add Another Family Member'),
      '#submit' => ['::addFamilyCancer'],
      '#ajax' => [
        'callback' => '::updateFamilyCancerFields',
        'wrapper' => 'health',
      ],
      '#limit_validation_errors' => [],
      '#states' => [
        'visible' => [
          ':input[name="health[has_family_cancer_history]"]' => ['value' => 'yes'],
        ],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['previous'] = [
      '#type' => 'submit',
      '#value' => $this->t('← Previous'),
      '#submit' => ['::previousSection'],
      '#limit_validation_errors' => [],
    ];

    $form['actions']['save_exit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save & Exit'),
      '#submit' => ['::saveAndExit'],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue →'),
    ];

    return $form;
  }

  /**
   * AJAX callback to update cancer fields.
   */
  public function updateCancerFields(array &$form, FormStateInterface $form_state): array {
    return $form['health']['cancers'];
  }

  /**
   * Submit handler to add another cancer diagnosis.
   */
  public function addCancer(array &$form, FormStateInterface $form_state): void {
    $num_cancers = $form_state->get('num_cancers') ?? 0;
    $form_state->set('num_cancers', $num_cancers + 1);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $health = $form_state->getValue('health');

    $database = $this->getDatabase();
    $has_cancer = ($health['cancer_diagnosed'] === 'yes');
    
    // Prepare condition fields
    $other_conditions = array_filter($health['other_conditions'] ?? []);
    $condition_fields = [
      'health_heart_disease' => in_array('heart_disease', $other_conditions) ? 1 : 0,
      'health_copd' => in_array('copd', $other_conditions) ? 1 : 0,
      'health_asthma' => in_array('asthma', $other_conditions) ? 1 : 0,
      'health_diabetes' => in_array('diabetes', $other_conditions) ? 1 : 0,
    ];
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    // Save health data to database columns
    $database->update('nfr_questionnaire')
      ->fields(array_merge([
        'cancer_diagnosis' => $has_cancer ? 1 : 0,
      ], $condition_fields))
      ->condition('uid', $uid)
      ->execute();
    
    // Mark section 8 as complete
    $this->markSectionComplete($uid, 8, $database);
    
    // Delete existing cancer diagnoses
    $database->delete('nfr_cancer_diagnoses')
      ->condition('uid', $uid)
      ->execute();
    
    // Insert cancer diagnoses if they have cancer
    if ($has_cancer && !empty($health['cancers'])) {
      $time = \Drupal::time()->getRequestTime();
      
      foreach ($health['cancers'] as $cancer) {
        if (!empty($cancer['type'])) {
          $database->insert('nfr_cancer_diagnoses')
            ->fields([
              'uid' => $uid,
              'cancer_type' => $cancer['type'],
              'year_diagnosed' => !empty($cancer['year_diagnosed']) ? (int) $cancer['year_diagnosed'] : NULL,
              'created' => $time,
              'updated' => $time,
            ])
            ->execute();
        }
      }
    }

    // Save family cancer history
    $this->saveFamilyCancerHistory($uid, $health, $database);

    $this->messenger()->addStatus($this->t('Section 8 saved.'));
    $form_state->setRedirect('nfr.questionnaire.section9');
  }

  /**
   * Submit handler for previous button.
   */
  public function previousSection(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $health = $form_state->getValue('health');

    $database = $this->getDatabase();
    $has_cancer = ($health['cancer_diagnosed'] === 'yes');
    
    // Prepare condition fields
    $other_conditions = array_filter($health['other_conditions'] ?? []);
    $condition_fields = [
      'health_heart_disease' => in_array('heart_disease', $other_conditions) ? 1 : 0,
      'health_copd' => in_array('copd', $other_conditions) ? 1 : 0,
      'health_asthma' => in_array('asthma', $other_conditions) ? 1 : 0,
      'health_diabetes' => in_array('diabetes', $other_conditions) ? 1 : 0,
    ];
    
    // Save health data to database columns
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    $database->update('nfr_questionnaire')
      ->fields(array_merge([
        'cancer_diagnosis' => $has_cancer ? 1 : 0,
      ], $condition_fields))
      ->condition('uid', $uid)
      ->execute();
    
    // Delete existing cancer diagnoses
    $database->delete('nfr_cancer_diagnoses')
      ->condition('uid', $uid)
      ->execute();
    
    // Insert cancer diagnoses if they have cancer
    if ($has_cancer && !empty($health['cancers'])) {
      $time = \Drupal::time()->getRequestTime();
      
      foreach ($health['cancers'] as $cancer) {
        if (!empty($cancer['type'])) {
          $database->insert('nfr_cancer_diagnoses')
            ->fields([
              'uid' => $uid,
              'cancer_type' => $cancer['type'],
              'year_diagnosed' => !empty($cancer['year_diagnosed']) ? (int) $cancer['year_diagnosed'] : NULL,
              'created' => $time,
              'updated' => $time,
            ])
            ->execute();
        }
      }
    }
    
    // Save family cancer history
    $this->saveFamilyCancerHistory($uid, $health, $database);

    $form_state->setRedirect('nfr.questionnaire.section7');
  }

  /**
   * Submit handler for save and exit button.
   */
  public function saveAndExit(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $health = $form_state->getValue('health');

    $database = $this->getDatabase();
    $has_cancer = ($health['cancer_diagnosed'] === 'yes');
    
    // Prepare condition fields
    $other_conditions = array_filter($health['other_conditions'] ?? []);
    $condition_fields = [
      'health_heart_disease' => in_array('heart_disease', $other_conditions) ? 1 : 0,
      'health_copd' => in_array('copd', $other_conditions) ? 1 : 0,
      'health_asthma' => in_array('asthma', $other_conditions) ? 1 : 0,
      'health_diabetes' => in_array('diabetes', $other_conditions) ? 1 : 0,
    ];
    
    // Save health data to database columns
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    $database->update('nfr_questionnaire')
      ->fields(array_merge([
        'cancer_diagnosis' => $has_cancer ? 1 : 0,
      ], $condition_fields))
      ->condition('uid', $uid)
      ->execute();
    
    // Delete existing cancer diagnoses
    $database->delete('nfr_cancer_diagnoses')
      ->condition('uid', $uid)
      ->execute();
    
    // Insert cancer diagnoses if they have cancer
    if ($has_cancer && !empty($health['cancers'])) {
      $time = \Drupal::time()->getRequestTime();
      
      foreach ($health['cancers'] as $cancer) {
        if (!empty($cancer['type'])) {
          $database->insert('nfr_cancer_diagnoses')
            ->fields([
              'uid' => $uid,
              'cancer_type' => $cancer['type'],
              'year_diagnosed' => !empty($cancer['year_diagnosed']) ? (int) $cancer['year_diagnosed'] : NULL,
              'created' => $time,
              'updated' => $time,
            ])
            ->execute();
        }
      }
    }

    // Save family cancer history
    $this->saveFamilyCancerHistory($uid, $health, $database);

    $this->messenger()->addStatus($this->t('Your progress has been saved.'));
    $form_state->setRedirect('nfr.dashboard');
  }

  /**
   * AJAX callback to add family cancer field.
   */
  public function addFamilyCancer(array &$form, FormStateInterface $form_state): void {
    $num = $form_state->get('num_family_cancers') ?? 0;
    $form_state->set('num_family_cancers', $num + 1);
    $form_state->setRebuild();
  }

  /**
   * AJAX callback for family cancer fields.
   */
  public function updateFamilyCancerFields(array &$form, FormStateInterface $form_state): array {
    return $form['health'];
  }

  /**
   * Save family cancer history to database.
   */
  private function saveFamilyCancerHistory(int $uid, array $health, Connection $database): void {
    // Delete existing family cancer history
    $database->delete('nfr_family_cancer_history')
      ->condition('uid', $uid)
      ->execute();

    // Insert new family cancer history if applicable
    $has_family_cancer = ($health['has_family_cancer_history'] ?? 'no') === 'yes';
    if ($has_family_cancer && !empty($health['family_cancers_container'])) {
      $time = \Drupal::time()->getRequestTime();
      
      foreach ($health['family_cancers_container'] as $fc) {
        if (!empty($fc['relationship']) && !empty($fc['cancer_type'])) {
          $database->insert('nfr_family_cancer_history')
            ->fields([
              'uid' => $uid,
              'relationship' => $fc['relationship'],
              'cancer_type' => $fc['cancer_type'],
              'age_at_diagnosis' => !empty($fc['age_at_diagnosis']) ? (int) $fc['age_at_diagnosis'] : NULL,
              'created' => $time,
              'updated' => $time,
            ])
            ->execute();
        }
      }
    }
  }

}
