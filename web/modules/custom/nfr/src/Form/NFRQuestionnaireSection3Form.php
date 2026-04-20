<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Section 3: Exposure Information.
 */
class NFRQuestionnaireSection3Form extends FormBase {

  use QuestionnaireFormTrait;

  public function __construct(
    private readonly Connection $database,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'nfr_questionnaire_section_3';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $uid = $this->getCurrentUserId();
    
    // Load exposure data from direct columns
    $database = $this->getDatabase();
    $questionnaire = $database->select('nfr_questionnaire', 'q')
      ->fields('q', ['afff_used', 'afff_times', 'afff_first_year', 'diesel_exhaust', 'chemical_activities', 'major_incidents'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    
    $exposure = [];
    if ($questionnaire) {
      $exposure['afff_used'] = $questionnaire['afff_used'] ?? '';
      $exposure['afff_times'] = $questionnaire['afff_times'] ?? '';
      $exposure['afff_first_year'] = $questionnaire['afff_first_year'] ?? '';
      $exposure['diesel_exhaust'] = $questionnaire['diesel_exhaust'] ?? '';
      $exposure['chemical_activities'] = $questionnaire['chemical_activities'] ? json_decode($questionnaire['chemical_activities'], TRUE) : [];
      $exposure['major_incidents'] = $questionnaire['major_incidents'] ? 'yes' : 'no';
    }
    
    // Load major incidents from table
    $incidents = $database->select('nfr_major_incidents', 'mi')
      ->fields('mi', ['description', 'incident_date', 'duration'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    
    $exposure['incidents'] = [];
    foreach ($incidents as $incident) {
      $exposure['incidents'][] = [
        'description' => $incident['description'] ?? '',
        'date' => $incident['incident_date'] ?? '',
        'duration' => $incident['duration'] ?? '',
      ];
    }
    
    $form['#tree'] = TRUE;
    
    // Add navigation menu
    $form['navigation'] = $this->buildNavigationMenu(3);

    $form['section_title'] = [
      '#type' => 'markup',
      '#markup' => '<h2>Section 3: Exposure Information</h2><p>These questions help us understand your exposures to substances that may affect firefighter health.</p>',
    ];

    $form['exposure'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Exposure Information'),
      '#tree' => TRUE,
    ];

    $form['exposure']['afff_used'] = [
      '#type' => 'radios',
      '#title' => $this->t('Have you ever used Aqueous Film-Forming Foam (AFFF), also known as firefighting foam?'),
      '#required' => TRUE,
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
        'unknown' => $this->t("Don't Know"),
      ],
      '#default_value' => $exposure['afff_used'] ?? '',
    ];

    $form['exposure']['afff_times'] = [
      '#type' => 'number',
      '#title' => $this->t('Approximately how many times did you use AFFF?'),
      '#min' => 1,
      '#default_value' => $exposure['afff_times'] ?? '',
      '#states' => [
        'visible' => [
          ':input[name="exposure[afff_used]"]' => ['value' => 'yes'],
        ],
      ],
    ];

    $form['exposure']['afff_first_year'] = [
      '#type' => 'number',
      '#title' => $this->t('In what year did you first use AFFF?'),
      '#min' => 1950,
      '#max' => (int) date('Y'),
      '#default_value' => $exposure['afff_first_year'] ?? '',
      '#states' => [
        'visible' => [
          ':input[name="exposure[afff_used]"]' => ['value' => 'yes'],
        ],
      ],
    ];

    $form['exposure']['diesel_exhaust'] = [
      '#type' => 'radios',
      '#title' => $this->t('Were you regularly exposed to diesel exhaust from fire apparatus?'),
      '#required' => TRUE,
      '#options' => [
        'regularly' => $this->t('Yes, regularly'),
        'sometimes' => $this->t('Sometimes'),
        'rarely' => $this->t('Rarely'),
        'never' => $this->t('Never'),
      ],
      '#default_value' => $exposure['diesel_exhaust'] ?? '',
    ];

    $form['exposure']['chemical_activities'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Were you involved in any of these activities that may involve chemical exposure?'),
      '#options' => [
        'fire_investigation' => $this->t('Fire investigation'),
        'overhaul' => $this->t('Overhaul operations'),
        'salvage' => $this->t('Salvage operations'),
        'vehicle_maintenance' => $this->t('Vehicle maintenance/apparatus cleaning'),
        'station_maintenance' => $this->t('Station maintenance'),
        'none' => $this->t('None of the above'),
      ],
      '#default_value' => $exposure['chemical_activities'] ?? [],
    ];

    $form['exposure']['major_incidents'] = [
      '#type' => 'radios',
      '#title' => $this->t('Were you involved in any major incidents or events with prolonged or intense exposure?'),
      '#required' => TRUE,
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
      ],
      '#default_value' => $exposure['major_incidents'] ?? '',
    ];

    // Major incidents repeating fields
    $num_incidents = $form_state->get('num_major_incidents') ?? count($exposure['incidents'] ?? []) ?: 0;
    
    $form['exposure']['incidents_wrapper'] = [
      '#type' => 'container',
      '#prefix' => '<div id="incidents-wrapper">',
      '#suffix' => '</div>',
      '#states' => [
        'visible' => [
          ':input[name="exposure[major_incidents]"]' => ['value' => 'yes'],
        ],
      ],
    ];

    for ($i = 0; $i < $num_incidents; $i++) {
      $incident_data = $exposure['incidents'][$i] ?? [];
      
      $form['exposure']['incidents_wrapper']['incidents'][$i] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Incident @num', ['@num' => $i + 1]),
        '#tree' => TRUE,
      ];

      $form['exposure']['incidents_wrapper']['incidents'][$i]['description'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Event Description'),
        '#rows' => 3,
        '#default_value' => $incident_data['description'] ?? '',
      ];

      $form['exposure']['incidents_wrapper']['incidents'][$i]['date'] = [
        '#type' => 'date',
        '#title' => $this->t('Date (approximate)'),
        '#default_value' => $incident_data['date'] ?? '',
      ];

      $form['exposure']['incidents_wrapper']['incidents'][$i]['duration'] = [
        '#type' => 'select',
        '#title' => $this->t('Duration of Involvement'),
        '#options' => [
          '' => $this->t('- Select -'),
          'hours' => $this->t('Hours'),
          'days' => $this->t('Days'),
          'weeks' => $this->t('Weeks'),
          'months' => $this->t('Months'),
        ],
        '#default_value' => $incident_data['duration'] ?? '',
      ];
    }

    // Add incident button at the bottom, after all incidents
    $form['exposure']['incidents_wrapper']['add_incident'] = [
      '#type' => 'submit',
      '#value' => $this->t('+ Add Incident'),
      '#submit' => ['::addIncident'],
      '#ajax' => [
        'callback' => '::updateIncidentFields',
        'wrapper' => 'incidents-wrapper',
      ],
      '#limit_validation_errors' => [],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['previous'] = [
      '#type' => 'submit',
      '#value' => $this->t('← Previous'),
      '#submit' => ['::previousSection'],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['button', 'button--secondary']],
    ];
    $form['actions']['save_exit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save & Exit'),
      '#submit' => ['::saveAndExit'],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['button', 'button--secondary']],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save & Continue to Section 4 →'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    return $form;
  }

  /**
   * AJAX callback for incident fields.
   */
  public function updateIncidentFields(array &$form, FormStateInterface $form_state): array {
    return $form['exposure']['incidents_wrapper'];
  }

  /**
   * Add incident submit handler.
   */
  public function addIncident(array &$form, FormStateInterface $form_state): void {
    $num_incidents = $form_state->get('num_major_incidents') ?? 0;
    $form_state->set('num_major_incidents', $num_incidents + 1);
    $form_state->setRebuild();
  }

  /**
   * Previous section handler.
   */
  public function previousSection(array &$form, FormStateInterface $form_state): void {
    $this->saveSection($form_state);
    $form_state->setRedirect('nfr.questionnaire.section2');
  }

  /**
   * Save and exit handler.
   */
  public function saveAndExit(array &$form, FormStateInterface $form_state): void {
    $this->saveSection($form_state);
    $this->messenger()->addStatus($this->t('Exposure information saved. You can continue later from your dashboard.'));
    $form_state->setRedirect('nfr.my_dashboard');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $exposure = $form_state->getValue('exposure');
    
    // Prepare chemical activities as JSON (checkboxes)
    $chemical_activities = array_filter($exposure['chemical_activities'] ?? []);
    $chemical_activities_json = !empty($chemical_activities) ? json_encode(array_values($chemical_activities)) : NULL;
    
    // Save exposure data to direct columns
    $database = $this->getDatabase();
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    $database->update('nfr_questionnaire')
      ->fields([
        'afff_used' => $exposure['afff_used'] ?: NULL,
        'afff_times' => !empty($exposure['afff_times']) ? (int)$exposure['afff_times'] : NULL,
        'afff_first_year' => !empty($exposure['afff_first_year']) ? (int)$exposure['afff_first_year'] : NULL,
        'diesel_exhaust' => $exposure['diesel_exhaust'] ?: NULL,
        'chemical_activities' => $chemical_activities_json,
        'major_incidents' => ($exposure['major_incidents'] === 'yes') ? 1 : 0,
      ])
      ->condition('uid', $uid)
      ->execute();
    
    // Mark section 3 as complete
    $this->markSectionComplete($uid, 3, $database);
    
    // Delete existing major incidents
    $database->delete('nfr_major_incidents')
      ->condition('uid', $uid)
      ->execute();
    
    // Insert new major incidents if any
    if (!empty($exposure['incidents_wrapper']['incidents']) && $exposure['major_incidents'] === 'yes') {
      $timestamp = time();
      foreach ($exposure['incidents_wrapper']['incidents'] as $key => $incident) {
        // Skip if no description
        if (empty($incident['description'])) {
          continue;
        }
        
        $database->insert('nfr_major_incidents')
          ->fields([
            'uid' => $uid,
            'description' => $incident['description'] ?? '',
            'incident_date' => $incident['date'] ?? NULL,
            'duration' => $incident['duration'] ?? NULL,
            'created' => $timestamp,
            'updated' => $timestamp,
          ])
          ->execute();
      }
    }
    
    $this->messenger()->addStatus($this->t('Section 3 saved.'));
    $form_state->setRedirect('nfr.questionnaire.section4');
  }

  /**
   * Save section data.
   */
  private function saveSection(FormStateInterface $form_state): void {
    // Deprecated - now saves directly in submitForm and saveAndExit
  }

}
