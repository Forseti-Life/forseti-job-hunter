<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * NFR Questionnaire Section 9: Lifestyle Factors.
 */
class NFRQuestionnaireSection9Form extends FormBase {

  use QuestionnaireFormTrait;

  /**
   * Constructs a new NFRQuestionnaireSection9Form.
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
    return 'nfr_questionnaire_section9_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'nfr/enrollment';

    $uid = $this->getCurrentUserId();
    
    // Load lifestyle data from database columns
    $database = $this->getDatabase();
    $questionnaire = $database->select('nfr_questionnaire', 'q')
      ->fields('q', ['smoking_history', 'alcohol_use', 'physical_activity_days', 'sleep_hours_per_night', 'sleep_quality', 'sleep_disorders'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    
    $lifestyle = [];
    if ($questionnaire) {
      if ($questionnaire['smoking_history']) {
        $smoking = json_decode($questionnaire['smoking_history'], TRUE) ?? [];
        $lifestyle = array_merge($lifestyle, $smoking);
      }
      $lifestyle['alcohol_frequency'] = $questionnaire['alcohol_use'] ?? '';
      $lifestyle['physical_activity_days'] = $questionnaire['physical_activity_days'] ?? '';
      $lifestyle['sleep_hours_per_night'] = $questionnaire['sleep_hours_per_night'] ?? '';
      $lifestyle['sleep_quality'] = $questionnaire['sleep_quality'] ?? '';
      $lifestyle['sleep_disorders'] = $questionnaire['sleep_disorders'] ? json_decode($questionnaire['sleep_disorders'], TRUE) : [];
    }

    // Add navigation menu
    $form['navigation'] = $this->buildNavigationMenu(9);

    $form['section_title'] = [
      '#type' => 'markup',
      '#markup' => '<h2>Section 9: Lifestyle Factors</h2><p>These questions help us account for other factors that may affect health.</p>',
    ];

    $form['lifestyle'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Lifestyle Factors'),
      '#tree' => TRUE,
    ];

    $form['lifestyle']['smoking_status'] = [
      '#type' => 'radios',
      '#title' => $this->t('Have you ever smoked cigarettes?'),
      '#required' => TRUE,
      '#options' => [
        'never' => $this->t('Never'),
        'former' => $this->t('Former smoker'),
        'current' => $this->t('Current smoker'),
      ],
      '#default_value' => $lifestyle['smoking_status'] ?? NULL,
    ];

    $form['lifestyle']['smoking_age_started'] = [
      '#type' => 'number',
      '#title' => $this->t('Age started smoking'),
      '#min' => 1,
      '#max' => 100,
      '#default_value' => $lifestyle['smoking_age_started'] ?? '',
      '#states' => [
        'visible' => [
          [':input[name="lifestyle[smoking_status]"]' => ['value' => 'former']],
          'or',
          [':input[name="lifestyle[smoking_status]"]' => ['value' => 'current']],
        ],
      ],
    ];

    $form['lifestyle']['smoking_age_stopped'] = [
      '#type' => 'number',
      '#title' => $this->t('Age stopped smoking'),
      '#min' => 1,
      '#max' => 100,
      '#default_value' => $lifestyle['smoking_age_stopped'] ?? '',
      '#states' => [
        'visible' => [
          ':input[name="lifestyle[smoking_status]"]' => ['value' => 'former'],
        ],
      ],
    ];

    $form['lifestyle']['cigarettes_per_day'] = [
      '#type' => 'select',
      '#title' => $this->t('Cigarettes per day'),
      '#options' => [
        '' => $this->t('- Select -'),
        'less_half_pack' => $this->t('Less than 1/2 pack (< 10)'),
        'half_to_one_pack' => $this->t('1/2 to 1 pack (10-20)'),
        'one_to_two_packs' => $this->t('1 to 2 packs (20-40)'),
        'more_than_two_packs' => $this->t('More than 2 packs (> 40)'),
      ],
      '#default_value' => $lifestyle['cigarettes_per_day'] ?? '',
      '#states' => [
        'visible' => [
          [':input[name="lifestyle[smoking_status]"]' => ['value' => 'former']],
          'or',
          [':input[name="lifestyle[smoking_status]"]' => ['value' => 'current']],
        ],
      ],
    ];

    // Other tobacco types
    $tobacco_types = [
      'cigars' => 'Cigars',
      'pipes' => 'Pipes',
      'ecigs' => 'E-cigarettes/vaping',
      'smokeless' => 'Smokeless tobacco (chew, snuff)',
    ];

    foreach ($tobacco_types as $type_key => $type_label) {
      $form['lifestyle'][$type_key . '_ever_used'] = [
        '#type' => 'radios',
        '#title' => $this->t('Have you ever used @type?', ['@type' => strtolower($type_label)]),
        '#options' => [
          'never' => $this->t('Never'),
          'former' => $this->t('Former user'),
          'current' => $this->t('Current user'),
        ],
        '#default_value' => $lifestyle[$type_key . '_ever_used'] ?? 'never',
      ];

      $form['lifestyle'][$type_key . '_age_started'] = [
        '#type' => 'number',
        '#title' => $this->t('Age started using @type', ['@type' => strtolower($type_label)]),
        '#min' => 1,
        '#max' => 100,
        '#default_value' => $lifestyle[$type_key . '_age_started'] ?? '',
        '#states' => [
          'visible' => [
            [':input[name="lifestyle[' . $type_key . '_ever_used]"]' => ['value' => 'former']],
            'or',
            [':input[name="lifestyle[' . $type_key . '_ever_used]"]' => ['value' => 'current']],
          ],
        ],
      ];

      $form['lifestyle'][$type_key . '_age_stopped'] = [
        '#type' => 'number',
        '#title' => $this->t('Age stopped using @type', ['@type' => strtolower($type_label)]),
        '#min' => 1,
        '#max' => 100,
        '#default_value' => $lifestyle[$type_key . '_age_stopped'] ?? '',
        '#states' => [
          'visible' => [
            ':input[name="lifestyle[' . $type_key . '_ever_used]"]' => ['value' => 'former'],
          ],
        ],
      ];
    }

    $form['lifestyle']['alcohol_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('How often do you drink alcoholic beverages?'),
      '#required' => TRUE,
      '#options' => [
        '' => $this->t('- Select -'),
        'never' => $this->t('Never'),
        'less_than_monthly' => $this->t('Less than once a month'),
        '1_3_per_month' => $this->t('1-3 times per month'),
        '1_2_per_week' => $this->t('1-2 times per week'),
        '3_4_per_week' => $this->t('3-4 times per week'),
        '5_plus_per_week' => $this->t('5+ times per week'),
      ],
      '#default_value' => $lifestyle['alcohol_frequency'] ?? '',
    ];

    $form['lifestyle']['physical_activity_days'] = [
      '#type' => 'number',
      '#title' => $this->t('On average, how many days per week do you engage in moderate or vigorous physical activity for at least 30 minutes?'),
      '#required' => TRUE,
      '#min' => 0,
      '#max' => 7,
      '#default_value' => $lifestyle['physical_activity_days'] ?? '',
      '#description' => $this->t('0-7 days'),
    ];

    $form['lifestyle']['sleep_hours_per_night'] = [
      '#type' => 'number',
      '#title' => $this->t('On average, how many hours of sleep do you get per night?'),
      '#required' => TRUE,
      '#min' => 0,
      '#max' => 24,
      '#default_value' => $lifestyle['sleep_hours_per_night'] ?? '',
      '#description' => $this->t('Include naps if applicable'),
    ];

    $form['lifestyle']['sleep_quality'] = [
      '#type' => 'select',
      '#title' => $this->t('How would you rate your overall sleep quality?'),
      '#required' => TRUE,
      '#options' => [
        '' => $this->t('- Select -'),
        'excellent' => $this->t('Excellent'),
        'good' => $this->t('Good'),
        'fair' => $this->t('Fair'),
        'poor' => $this->t('Poor'),
        'very_poor' => $this->t('Very Poor'),
      ],
      '#default_value' => $lifestyle['sleep_quality'] ?? '',
    ];

    $form['lifestyle']['sleep_disorders'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Have you been diagnosed with any of these sleep disorders?'),
      '#options' => [
        'sleep_apnea' => $this->t('Sleep apnea'),
        'insomnia' => $this->t('Insomnia'),
        'restless_leg' => $this->t('Restless leg syndrome'),
        'narcolepsy' => $this->t('Narcolepsy'),
        'shift_work_disorder' => $this->t('Shift work sleep disorder'),
        'other' => $this->t('Other sleep disorder'),
        'none' => $this->t('None'),
      ],
      '#default_value' => $lifestyle['sleep_disorders'] ?? [],
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
      '#value' => $this->t('Complete Questionnaire →'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $lifestyle = $form_state->getValue('lifestyle');

    // Separate smoking data from lifestyle data
    $smoking_data = [
      'smoking_status' => $lifestyle['smoking_status'] ?? '',
      'smoking_age_started' => $lifestyle['smoking_age_started'] ?? '',
      'smoking_age_stopped' => $lifestyle['smoking_age_stopped'] ?? '',
      'cigarettes_per_day' => $lifestyle['cigarettes_per_day'] ?? '',
      // Other tobacco types
      'cigars_ever_used' => $lifestyle['cigars_ever_used'] ?? 'never',
      'cigars_age_started' => $lifestyle['cigars_age_started'] ?? '',
      'cigars_age_stopped' => $lifestyle['cigars_age_stopped'] ?? '',
      'pipes_ever_used' => $lifestyle['pipes_ever_used'] ?? 'never',
      'pipes_age_started' => $lifestyle['pipes_age_started'] ?? '',
      'pipes_age_stopped' => $lifestyle['pipes_age_stopped'] ?? '',
      'ecigs_ever_used' => $lifestyle['ecigs_ever_used'] ?? 'never',
      'ecigs_age_started' => $lifestyle['ecigs_age_started'] ?? '',
      'ecigs_age_stopped' => $lifestyle['ecigs_age_stopped'] ?? '',
      'smokeless_ever_used' => $lifestyle['smokeless_ever_used'] ?? 'never',
      'smokeless_age_started' => $lifestyle['smokeless_age_started'] ?? '',
      'smokeless_age_stopped' => $lifestyle['smokeless_age_stopped'] ?? '',
    ];

    // Save lifestyle data to database columns
    $database = $this->getDatabase();
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    $database->update('nfr_questionnaire')
      ->fields([
        'smoking_history' => json_encode($smoking_data),
        'alcohol_use' => $lifestyle['alcohol_frequency'] ?? NULL,
        'physical_activity_days' => !empty($lifestyle['physical_activity_days']) ? (int)$lifestyle['physical_activity_days'] : NULL,
        'sleep_hours_per_night' => !empty($lifestyle['sleep_hours_per_night']) ? (float)$lifestyle['sleep_hours_per_night'] : NULL,
        'sleep_quality' => $lifestyle['sleep_quality'] ?? NULL,
        'sleep_disorders' => !empty($lifestyle['sleep_disorders']) ? json_encode(array_filter($lifestyle['sleep_disorders'])) : NULL,
        'questionnaire_completed' => 1,
      ])
      ->condition('uid', $uid)
      ->execute();

    // Mark section 9 as complete
    $this->markSectionComplete($uid, 9, $database);

    $this->messenger()->addStatus($this->t('Congratulations! You have completed the questionnaire.'));
    $form_state->setRedirect('nfr.review_submit');
  }

  /**
   * Submit handler for previous button.
   */
  public function previousSection(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('nfr.questionnaire.section8');
  }

  /**
   * Submit handler for save and exit button.
   */
  public function saveAndExit(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $lifestyle = $form_state->getValue('lifestyle');

    // Separate smoking data from lifestyle data
    $smoking_data = [
      'smoking_status' => $lifestyle['smoking_status'] ?? '',
      'smoking_age_started' => $lifestyle['smoking_age_started'] ?? '',
      'smoking_age_stopped' => $lifestyle['smoking_age_stopped'] ?? '',
      'cigarettes_per_day' => $lifestyle['cigarettes_per_day'] ?? '',
      // Other tobacco types
      'cigars_ever_used' => $lifestyle['cigars_ever_used'] ?? 'never',
      'cigars_age_started' => $lifestyle['cigars_age_started'] ?? '',
      'cigars_age_stopped' => $lifestyle['cigars_age_stopped'] ?? '',
      'pipes_ever_used' => $lifestyle['pipes_ever_used'] ?? 'never',
      'pipes_age_started' => $lifestyle['pipes_age_started'] ?? '',
      'pipes_age_stopped' => $lifestyle['pipes_age_stopped'] ?? '',
      'ecigs_ever_used' => $lifestyle['ecigs_ever_used'] ?? 'never',
      'ecigs_age_started' => $lifestyle['ecigs_age_started'] ?? '',
      'ecigs_age_stopped' => $lifestyle['ecigs_age_stopped'] ?? '',
      'smokeless_ever_used' => $lifestyle['smokeless_ever_used'] ?? 'never',
      'smokeless_age_started' => $lifestyle['smokeless_age_started'] ?? '',
      'smokeless_age_stopped' => $lifestyle['smokeless_age_stopped'] ?? '',
    ];

    // Save lifestyle data to database columns
    $database = $this->getDatabase();
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    $database->update('nfr_questionnaire')
      ->fields([
        'smoking_history' => json_encode($smoking_data),
        'alcohol_use' => $lifestyle['alcohol_frequency'] ?? NULL,
        'physical_activity_days' => !empty($lifestyle['physical_activity_days']) ? (int)$lifestyle['physical_activity_days'] : NULL,
        'sleep_hours_per_night' => !empty($lifestyle['sleep_hours_per_night']) ? (float)$lifestyle['sleep_hours_per_night'] : NULL,
        'sleep_quality' => $lifestyle['sleep_quality'] ?? NULL,
        'sleep_disorders' => !empty($lifestyle['sleep_disorders']) ? json_encode(array_filter($lifestyle['sleep_disorders'])) : NULL,
      ])
      ->condition('uid', $uid)
      ->execute();

    $this->messenger()->addStatus($this->t('Your progress has been saved.'));
    $form_state->setRedirect('nfr.dashboard');
  }

}
