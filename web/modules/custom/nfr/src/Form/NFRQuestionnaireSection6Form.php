<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * NFR Questionnaire Section 6: PPE Practices.
 */
class NFRQuestionnaireSection6Form extends FormBase {

  use QuestionnaireFormTrait;

  /**
   * Constructs a new NFRQuestionnaireSection6Form.
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
    return 'nfr_questionnaire_section6_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'nfr/enrollment';

    $uid = $this->getCurrentUserId();
    
    // Load PPE data from direct columns
    $database = $this->getDatabase();
    $questionnaire = $database->select('nfr_questionnaire', 'q')
      ->fields('q', [
        'ppe_scba_ever_used',
        'ppe_scba_year_started',
        'ppe_scba_always_used',
        'ppe_turnout_coat_ever_used',
        'ppe_turnout_coat_year_started',
        'ppe_turnout_coat_always_used',
        'ppe_turnout_pants_ever_used',
        'ppe_turnout_pants_year_started',
        'ppe_turnout_pants_always_used',
        'ppe_gloves_ever_used',
        'ppe_gloves_year_started',
        'ppe_gloves_always_used',
        'ppe_helmet_ever_used',
        'ppe_helmet_year_started',
        'ppe_helmet_always_used',
        'ppe_boots_ever_used',
        'ppe_boots_year_started',
        'ppe_boots_always_used',
        'ppe_nomex_hood_ever_used',
        'ppe_nomex_hood_year_started',
        'ppe_nomex_hood_always_used',
        'ppe_wildland_clothing_ever_used',
        'ppe_wildland_clothing_year_started',
        'ppe_wildland_clothing_always_used',
        'ppe_scba_during_suppression',
        'ppe_scba_during_overhaul',
        'ppe_scba_interior_attack',
        'ppe_scba_exterior_attack',
        'ppe_respirator_vehicle_fires',
        'ppe_respirator_brush_fires',
        'ppe_respirator_wildland',
        'ppe_respirator_investigations',
        'ppe_respirator_wui',
      ])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    
    $ppe = [];
    if ($questionnaire) {
      $ppe_types = ['scba', 'turnout_coat', 'turnout_pants', 'gloves', 'helmet', 'boots', 'nomex_hood', 'wildland_clothing'];
      foreach ($ppe_types as $type) {
        $ppe[$type] = [
          'ever_used' => $questionnaire['ppe_' . $type . '_ever_used'] ?? NULL,
          'year_started' => $questionnaire['ppe_' . $type . '_year_started'] ?? '',
          'always_used' => $questionnaire['ppe_' . $type . '_always_used'] ?? NULL,
        ];
      }
      $ppe['scba_during_suppression'] = $questionnaire['ppe_scba_during_suppression'] ?? '';
      $ppe['scba_during_overhaul'] = $questionnaire['ppe_scba_during_overhaul'] ?? '';
      $ppe['scba_interior_attack'] = $questionnaire['ppe_scba_interior_attack'] ?? '';
      $ppe['scba_exterior_attack'] = $questionnaire['ppe_scba_exterior_attack'] ?? '';
      $ppe['respirator_vehicle_fires'] = $questionnaire['ppe_respirator_vehicle_fires'] ?? '';
      $ppe['respirator_brush_fires'] = $questionnaire['ppe_respirator_brush_fires'] ?? '';
      $ppe['respirator_wildland'] = $questionnaire['ppe_respirator_wildland'] ?? '';
      $ppe['respirator_investigations'] = $questionnaire['ppe_respirator_investigations'] ?? '';
      $ppe['respirator_wui'] = $questionnaire['ppe_respirator_wui'] ?? '';
    }

    // Add navigation menu
    $form['navigation'] = $this->buildNavigationMenu(6);

    $form['section_title'] = [
      '#type' => 'markup',
      '#markup' => '<h2>Section 6: Personal Protective Equipment (PPE)</h2><p>These questions help us understand the equipment you used and when you started using it.</p>',
    ];

    $form['ppe'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('PPE Practices'),
      '#tree' => TRUE,
    ];

    $ppe_types = [
      'scba' => 'Self-Contained Breathing Apparatus (SCBA)',
      'turnout_coat' => 'Structural firefighting coat (turnout coat)',
      'turnout_pants' => 'Structural firefighting pants (turnout pants)',
      'gloves' => 'Firefighting gloves',
      'helmet' => 'Firefighting helmet',
      'boots' => 'Firefighting boots',
      'nomex_hood' => 'Nomex hood (particulate-blocking)',
      'wildland_clothing' => 'Wildland firefighting clothing',
    ];

    $form['ppe']['equipment_table'] = [
      '#type' => 'markup',
      '#markup' => '<div class="ppe-intro">' . $this->t('For each type of equipment, indicate if you used it and when you started.') . '</div>',
    ];

    foreach ($ppe_types as $type_key => $type_label) {
      $form['ppe'][$type_key] = [
        '#type' => 'fieldset',
        '#title' => $this->t($type_label),
      ];

      $form['ppe'][$type_key]['ever_used'] = [
        '#type' => 'radios',
        '#title' => $this->t('Ever used?'),
        '#options' => [
          'yes' => $this->t('Yes'),
          'no' => $this->t('No'),
        ],
        '#default_value' => $ppe[$type_key]['ever_used'] ?? NULL,
      ];

      $form['ppe'][$type_key]['always_used'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('I have always used this (since start of career)'),
        '#default_value' => $ppe[$type_key]['always_used'] ?? FALSE,
        '#states' => [
          'visible' => [
            ':input[name="ppe[' . $type_key . '][ever_used]"]' => ['value' => 'yes'],
          ],
        ],
      ];

      $form['ppe'][$type_key]['year_started'] = [
        '#type' => 'number',
        '#title' => $this->t('Year Started Using'),
        '#min' => 1950,
        '#max' => (int) date('Y'),
        '#default_value' => $ppe[$type_key]['year_started'] ?? '',
        '#states' => [
          'visible' => [
            ':input[name="ppe[' . $type_key . '][ever_used]"]' => ['value' => 'yes'],
            ':input[name="ppe[' . $type_key . '][always_used]"]' => ['checked' => FALSE],
          ],
        ],
      ];
    }

    // SCBA usage follow-up
    $form['ppe']['scba_during_suppression'] = [
      '#type' => 'select',
      '#title' => $this->t('During fire suppression activities, how often did you wear SCBA?'),
      '#options' => [
        '' => $this->t('- Select -'),
        'always' => $this->t('Always (100%)'),
        'usually' => $this->t('Usually (75-99%)'),
        'sometimes' => $this->t('Sometimes (25-74%)'),
        'rarely' => $this->t('Rarely (<25%)'),
        'never' => $this->t('Never'),
      ],
      '#default_value' => $ppe['scba_during_suppression'] ?? '',
    ];

    $form['ppe']['scba_during_overhaul'] = [
      '#type' => 'select',
      '#title' => $this->t('During overhaul operations, how often did you wear SCBA?'),
      '#options' => [
        '' => $this->t('- Select -'),
        'always' => $this->t('Always (100%)'),
        'usually' => $this->t('Usually (75-99%)'),
        'sometimes' => $this->t('Sometimes (25-74%)'),
        'rarely' => $this->t('Rarely (<25%)'),
        'never' => $this->t('Never'),
      ],
      '#default_value' => $ppe['scba_during_overhaul'] ?? '',
    ];

    $form['ppe']['scba_interior_attack'] = [
      '#type' => 'select',
      '#title' => $this->t('During interior structural attack, how often did you wear SCBA?'),
      '#options' => [
        '' => $this->t('- Select -'),
        'always' => $this->t('Always (100%)'),
        'usually' => $this->t('Usually (75-99%)'),
        'sometimes' => $this->t('Sometimes (25-74%)'),
        'rarely' => $this->t('Rarely (<25%)'),
        'never' => $this->t('Never'),
      ],
      '#default_value' => $ppe['scba_interior_attack'] ?? '',
    ];

    $form['ppe']['scba_exterior_attack'] = [
      '#type' => 'select',
      '#title' => $this->t('During exterior structural attack, how often did you wear SCBA?'),
      '#options' => [
        '' => $this->t('- Select -'),
        'always' => $this->t('Always (100%)'),
        'usually' => $this->t('Usually (75-99%)'),
        'sometimes' => $this->t('Sometimes (25-74%)'),
        'rarely' => $this->t('Rarely (<25%)'),
        'never' => $this->t('Never'),
      ],
      '#default_value' => $ppe['scba_exterior_attack'] ?? '',
    ];

    $form['ppe']['respirator_vehicle_fires'] = [
      '#type' => 'select',
      '#title' => $this->t('During vehicle fires, how often did you wear SCBA or respirator?'),
      '#options' => [
        '' => $this->t('- Select -'),
        'always' => $this->t('Always (100%)'),
        'usually' => $this->t('Usually (75-99%)'),
        'sometimes' => $this->t('Sometimes (25-74%)'),
        'rarely' => $this->t('Rarely (<25%)'),
        'never' => $this->t('Never'),
      ],
      '#default_value' => $ppe['respirator_vehicle_fires'] ?? '',
    ];

    $form['ppe']['respirator_brush_fires'] = [
      '#type' => 'select',
      '#title' => $this->t('During brush/vegetation fires, how often did you wear a respirator?'),
      '#options' => [
        '' => $this->t('- Select -'),
        'always' => $this->t('Always (100%)'),
        'usually' => $this->t('Usually (75-99%)'),
        'sometimes' => $this->t('Sometimes (25-74%)'),
        'rarely' => $this->t('Rarely (<25%)'),
        'never' => $this->t('Never'),
      ],
      '#default_value' => $ppe['respirator_brush_fires'] ?? '',
    ];

    $form['ppe']['respirator_wildland'] = [
      '#type' => 'select',
      '#title' => $this->t('During wildland fire suppression, how often did you wear a respirator?'),
      '#options' => [
        '' => $this->t('- Select -'),
        'always' => $this->t('Always (100%)'),
        'usually' => $this->t('Usually (75-99%)'),
        'sometimes' => $this->t('Sometimes (25-74%)'),
        'rarely' => $this->t('Rarely (<25%)'),
        'never' => $this->t('Never'),
      ],
      '#default_value' => $ppe['respirator_wildland'] ?? '',
    ];

    $form['ppe']['respirator_investigations'] = [
      '#type' => 'select',
      '#title' => $this->t('During fire investigations, how often did you wear a respirator?'),
      '#options' => [
        '' => $this->t('- Select -'),
        'always' => $this->t('Always (100%)'),
        'usually' => $this->t('Usually (75-99%)'),
        'sometimes' => $this->t('Sometimes (25-74%)'),
        'rarely' => $this->t('Rarely (<25%)'),
        'never' => $this->t('Never'),
      ],
      '#default_value' => $ppe['respirator_investigations'] ?? '',
    ];

    $form['ppe']['respirator_wui'] = [
      '#type' => 'select',
      '#title' => $this->t('During Wildland-Urban Interface (WUI) fires, how often did you wear a respirator?'),
      '#options' => [
        '' => $this->t('- Select -'),
        'always' => $this->t('Always (100%)'),
        'usually' => $this->t('Usually (75-99%)'),
        'sometimes' => $this->t('Sometimes (25-74%)'),
        'rarely' => $this->t('Rarely (<25%)'),
        'never' => $this->t('Never'),
      ],
      '#default_value' => $ppe['respirator_wui'] ?? '',
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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $ppe = $form_state->getValue('ppe');

    // Save PPE data to direct columns
    $database = $this->getDatabase();
    
    $fields = [];
    
    $ppe_types = ['scba', 'turnout_coat', 'turnout_pants', 'gloves', 'helmet', 'boots', 'nomex_hood', 'wildland_clothing'];
    foreach ($ppe_types as $type) {
      $fields['ppe_' . $type . '_ever_used'] = $ppe[$type]['ever_used'] ?? NULL;
      $fields['ppe_' . $type . '_year_started'] = !empty($ppe[$type]['year_started']) ? (int) $ppe[$type]['year_started'] : NULL;
      $fields['ppe_' . $type . '_always_used'] = !empty($ppe[$type]['always_used']) ? 1 : 0;
    }
    
    $fields['ppe_scba_during_suppression'] = $ppe['scba_during_suppression'] ?? NULL;
    $fields['ppe_scba_during_overhaul'] = $ppe['scba_during_overhaul'] ?? NULL;
    $fields['ppe_scba_interior_attack'] = $ppe['scba_interior_attack'] ?? NULL;
    $fields['ppe_scba_exterior_attack'] = $ppe['scba_exterior_attack'] ?? NULL;
    $fields['ppe_respirator_vehicle_fires'] = $ppe['respirator_vehicle_fires'] ?? NULL;
    $fields['ppe_respirator_brush_fires'] = $ppe['respirator_brush_fires'] ?? NULL;
    $fields['ppe_respirator_wildland'] = $ppe['respirator_wildland'] ?? NULL;
    $fields['ppe_respirator_investigations'] = $ppe['respirator_investigations'] ?? NULL;
    $fields['ppe_respirator_wui'] = $ppe['respirator_wui'] ?? NULL;
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    $database->update('nfr_questionnaire')
      ->fields($fields)
      ->condition('uid', $uid)
      ->execute();

    // Mark section 6 as complete
    $this->markSectionComplete($uid, 6, $database);

    $this->messenger()->addStatus($this->t('Section 6 saved.'));
    $form_state->setRedirect('nfr.questionnaire.section7');
  }

  /**
   * Submit handler for previous button.
   */
  public function previousSection(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $ppe = $form_state->getValue('ppe');

    // Save PPE data to direct columns
    $database = $this->getDatabase();
    
    $fields = [];
    
    $ppe_types = ['scba', 'turnout_coat', 'turnout_pants', 'gloves', 'helmet', 'boots', 'nomex_hood', 'wildland_clothing'];
    foreach ($ppe_types as $type) {
      $fields['ppe_' . $type . '_ever_used'] = $ppe[$type]['ever_used'] ?? NULL;
      $fields['ppe_' . $type . '_year_started'] = !empty($ppe[$type]['year_started']) ? (int) $ppe[$type]['year_started'] : NULL;
      $fields['ppe_' . $type . '_always_used'] = !empty($ppe[$type]['always_used']) ? 1 : 0;
    }
    
    $fields['ppe_scba_during_suppression'] = $ppe['scba_during_suppression'] ?? NULL;
    $fields['ppe_scba_during_overhaul'] = $ppe['scba_during_overhaul'] ?? NULL;
    $fields['ppe_scba_interior_attack'] = $ppe['scba_interior_attack'] ?? NULL;
    $fields['ppe_scba_exterior_attack'] = $ppe['scba_exterior_attack'] ?? NULL;
    $fields['ppe_respirator_vehicle_fires'] = $ppe['respirator_vehicle_fires'] ?? NULL;
    $fields['ppe_respirator_brush_fires'] = $ppe['respirator_brush_fires'] ?? NULL;
    $fields['ppe_respirator_wildland'] = $ppe['respirator_wildland'] ?? NULL;
    $fields['ppe_respirator_investigations'] = $ppe['respirator_investigations'] ?? NULL;
    $fields['ppe_respirator_wui'] = $ppe['respirator_wui'] ?? NULL;
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    $database->update('nfr_questionnaire')
      ->fields($fields)
      ->condition('uid', $uid)
      ->execute();
    
    $form_state->setRedirect('nfr.questionnaire.section5');
  }

  /**
   * Submit handler for save and exit button.
   */
  public function saveAndExit(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $ppe = $form_state->getValue('ppe');

    // Save PPE data to direct columns
    $database = $this->getDatabase();
    
    $fields = [];
    
    $ppe_types = ['scba', 'turnout_coat', 'turnout_pants', 'gloves', 'helmet', 'boots', 'nomex_hood', 'wildland_clothing'];
    foreach ($ppe_types as $type) {
      $fields['ppe_' . $type . '_ever_used'] = $ppe[$type]['ever_used'] ?? NULL;
      $fields['ppe_' . $type . '_year_started'] = !empty($ppe[$type]['year_started']) ? (int) $ppe[$type]['year_started'] : NULL;
      $fields['ppe_' . $type . '_always_used'] = !empty($ppe[$type]['always_used']) ? 1 : 0;
    }
    
    $fields['ppe_scba_during_suppression'] = $ppe['scba_during_suppression'] ?? NULL;
    $fields['ppe_scba_during_overhaul'] = $ppe['scba_during_overhaul'] ?? NULL;
    $fields['ppe_scba_interior_attack'] = $ppe['scba_interior_attack'] ?? NULL;
    $fields['ppe_scba_exterior_attack'] = $ppe['scba_exterior_attack'] ?? NULL;
    $fields['ppe_respirator_vehicle_fires'] = $ppe['respirator_vehicle_fires'] ?? NULL;
    $fields['ppe_respirator_brush_fires'] = $ppe['respirator_brush_fires'] ?? NULL;
    $fields['ppe_respirator_wildland'] = $ppe['respirator_wildland'] ?? NULL;
    $fields['ppe_respirator_investigations'] = $ppe['respirator_investigations'] ?? NULL;
    $fields['ppe_respirator_wui'] = $ppe['respirator_wui'] ?? NULL;
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    $database->update('nfr_questionnaire')
      ->fields($fields)
      ->condition('uid', $uid)
      ->execute();

    $this->messenger()->addStatus($this->t('Your progress has been saved.'));
    $form_state->setRedirect('nfr.dashboard');
  }

}
