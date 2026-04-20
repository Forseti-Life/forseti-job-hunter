<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Review and submit enrollment form - REWRITTEN for new DB structure.
 */
class NFRReviewSubmitForm extends FormBase {

  /**
   * Constructs the form.
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
    return 'nfr_review_submit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Check if uid parameter is provided (for admin viewing other users)
    $request = \Drupal::request();
    $requested_uid = $request->query->get('uid');
    
    // If no uid parameter or user is not admin, use current user
    if ($requested_uid && $this->currentUser->hasPermission('administer nfr')) {
      $uid = (int) $requested_uid;
    } else {
      $uid = (int) $this->currentUser->id();
    }
    
    $form['#attached']['library'][] = 'nfr/enrollment';

    // Add process flow diagram
    $form['process_flow'] = [
      '#type' => 'markup',
      '#markup' => $this->buildProcessFlowDiagram($uid),
      '#weight' => -10,
    ];

    // Header
    $form['header'] = [
      '#type' => 'markup',
      '#markup' => '<div class="review-header"><h1>' . $this->t('Review Your Responses') . '</h1><p>' . $this->t('Please review all your responses before final submission. You can click "Edit" to update any section.') . '</p></div>',
      '#weight' => 0,
    ];

    // Section 1: Demographics
    $form['section1'] = $this->buildSection(
      'Section 1: Demographics',
      $this->renderSection1($uid),
      'nfr.questionnaire.section1',
      10
    );

    // Section 2: Work History
    $form['section2'] = $this->buildSection(
      'Section 2: Work History',
      $this->renderSection2($uid),
      'nfr.questionnaire.section2',
      20
    );

    // Section 3: Exposure Information
    $form['section3'] = $this->buildSection(
      'Section 3: Exposure Information',
      $this->renderSection3($uid),
      'nfr.questionnaire.section3',
      30
    );

    // Section 4: Military Service
    $form['section4'] = $this->buildSection(
      'Section 4: Military Service',
      $this->renderSection4($uid),
      'nfr.questionnaire.section4',
      40
    );

    // Section 5: Other Employment
    $form['section5'] = $this->buildSection(
      'Section 5: Other Employment',
      $this->renderSection5($uid),
      'nfr.questionnaire.section5',
      50
    );

    // Section 6: PPE Practices
    $form['section6'] = $this->buildSection(
      'Section 6: PPE Practices',
      $this->renderSection6($uid),
      'nfr.questionnaire.section6',
      60
    );

    // Section 7: Decontamination Practices
    $form['section7'] = $this->buildSection(
      'Section 7: Decontamination Practices',
      $this->renderSection7($uid),
      'nfr.questionnaire.section7',
      70
    );

    // Section 8: Health Information
    $form['section8'] = $this->buildSection(
      'Section 8: Health Information',
      $this->renderSection8($uid),
      'nfr.questionnaire.section8',
      80
    );

    // Section 9: Lifestyle Factors
    $form['section9'] = $this->buildSection(
      'Section 9: Lifestyle Factors',
      $this->renderSection9($uid),
      'nfr.questionnaire.section9',
      90
    );

    // Final confirmation
    $form['confirmation'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Final Confirmation'),
      '#weight' => 100,
    ];

    $form['confirmation']['accuracy_confirmed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I confirm that my responses are accurate to the best of my knowledge'),
      '#required' => TRUE,
    ];

    // Actions
    $form['actions'] = ['#type' => 'actions', '#weight' => 110];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit Questionnaire'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    return $form;
  }

  /**
   * Build a collapsible section with edit link.
   */
  private function buildSection(string $title, string $content, string $route, int $weight): array {
    return [
      '#type' => 'details',
      '#title' => $this->t($title),
      '#open' => FALSE,
      '#weight' => $weight,
      'content' => [
        '#markup' => $content,
      ],
      'edit' => [
        '#type' => 'link',
        '#title' => $this->t('Edit'),
        '#url' => \Drupal\Core\Url::fromRoute($route),
        '#attributes' => ['class' => ['button', 'button--secondary']],
      ],
    ];
  }

  /**
   * Render Section 1: Demographics.
   */
  private function renderSection1(int $uid): string {
    $data = $this->database->select('nfr_questionnaire', 'q')
      ->fields('q', ['race_ethnicity', 'race_other', 'education_level', 'marital_status'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    if (!$data) {
      return '<p class="incomplete">' . $this->t('Not completed') . '</p>';
    }

    $html = '<div class="summary-content">';

    // Race/Ethnicity
    if (!empty($data['race_ethnicity'])) {
      $races = json_decode($data['race_ethnicity'], TRUE);
      if (!empty($races) && is_array($races)) {
        $race_labels = [
          'american_indian' => 'American Indian or Alaska Native',
          'asian' => 'Asian',
          'black' => 'Black or African American',
          'hispanic' => 'Hispanic or Latino',
          'pacific_islander' => 'Native Hawaiian or Other Pacific Islander',
          'white' => 'White',
          'other' => 'Other',
        ];
        $selected_races = array_map(fn($key) => $race_labels[$key] ?? ucwords(str_replace('_', ' ', (string) $key)), array_keys(array_filter($races)));
        $html .= '<p><strong>' . $this->t('Race/Ethnicity:') . '</strong><br>' . implode('<br>', $selected_races) . '</p>';
        
        if (!empty($data['race_other'])) {
          $html .= '<p><strong>' . $this->t('Other (specified):') . '</strong> ' . htmlspecialchars($data['race_other']) . '</p>';
        }
      }
    }

    // Education Level
    if (!empty($data['education_level'])) {
      $edu_labels = [
        'less_than_hs' => 'Less than high school',
        'hs_or_ged' => 'High school or GED',
        'some_college' => 'Some college',
        'associate' => 'Associate degree',
        'bachelor' => 'Bachelor\'s degree',
        'graduate' => 'Graduate degree',
      ];
      $html .= '<p><strong>' . $this->t('Education Level:') . '</strong> ' . 
        ($edu_labels[$data['education_level']] ?? ucwords(str_replace('_', ' ', $data['education_level']))) . '</p>';
    }

    // Marital Status
    if (!empty($data['marital_status'])) {
      $marital_labels = [
        'single' => 'Single, never married',
        'married' => 'Married',
        'divorced' => 'Divorced',
        'widowed' => 'Widowed',
        'separated' => 'Separated',
      ];
      $html .= '<p><strong>' . $this->t('Marital Status:') . '</strong> ' . 
        ($marital_labels[$data['marital_status']] ?? ucfirst($data['marital_status'])) . '</p>';
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Render Section 2: Work History.
   */
  private function renderSection2(int $uid): string {
    // Load departments
    $departments = $this->database->select('nfr_work_history', 'wh')
      ->fields('wh')
      ->condition('uid', $uid)
      ->orderBy('start_date', 'DESC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($departments)) {
      return '<p class="incomplete">' . $this->t('Not completed') . '</p>';
    }

    $html = '<div class="summary-content">';
    $html .= '<p><strong>' . $this->t('Total Departments:') . '</strong> ' . count($departments) . '</p>';
    $html .= '<hr>';

    foreach ($departments as $i => $dept) {
      $html .= '<h4 style="margin-top: 1rem;">' . $this->t('Department @num', ['@num' => $i + 1]) . '</h4>';
      $html .= '<p><strong>' . htmlspecialchars($dept['department_name']) . '</strong><br>';
      $html .= htmlspecialchars($dept['department_city']) . ', ' . htmlspecialchars($dept['department_state']);
      if (!empty($dept['department_fdid'])) {
        $html .= ' (FDID: ' . htmlspecialchars($dept['department_fdid']) . ')';
      }
      $html .= '</p>';
      
      $html .= '<p><strong>' . $this->t('Employment Period:') . '</strong> ';
      $html .= htmlspecialchars($dept['start_date']);
      $html .= ' - ';
      $html .= $dept['is_current'] ? '<em>Present</em>' : htmlspecialchars($dept['end_date'] ?? 'Unknown');
      $html .= '</p>';

      // Load job titles for this department
      $jobs = $this->database->select('nfr_job_titles', 'jt')
        ->fields('jt')
        ->condition('work_history_id', $dept['id'])
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      if (!empty($jobs)) {
        $html .= '<p><strong>' . $this->t('Positions:') . '</strong></p><ul>';
        foreach ($jobs as $job) {
          $html .= '<li>';
          $html .= '<strong>' . htmlspecialchars($job['job_title']) . '</strong>';
          $html .= ' (' . ucwords(str_replace('_', ' ', $job['employment_type'])) . ')';
          
          if ($job['responded_to_incidents']) {
            $html .= '<br><em>Responded to incidents</em>';
            
            // Load incident frequencies
            $frequencies = $this->database->select('nfr_incident_frequency', 'if')
              ->fields('if', ['incident_type', 'frequency'])
              ->condition('job_title_id', $job['id'])
              ->execute()
              ->fetchAll(\PDO::FETCH_ASSOC);

            if (!empty($frequencies)) {
              $html .= '<br><small>';
              $freq_list = [];
              foreach ($frequencies as $freq) {
                if ($freq['frequency'] !== 'never') {
                  $freq_list[] = ucwords(str_replace('_', ' ', $freq['incident_type'])) . ': ' . str_replace('_', ' ', $freq['frequency']);
                }
              }
              $html .= implode('; ', $freq_list);
              $html .= '</small>';
            }
          }
          $html .= '</li>';
        }
        $html .= '</ul>';
      }
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Render Section 3: Exposure Information.
   */
  private function renderSection3(int $uid): string {
    $data = $this->database->select('nfr_questionnaire', 'q')
      ->fields('q', [
        'afff_used', 'afff_times', 'afff_first_year',
        'diesel_exhaust', 'chemical_activities', 'major_incidents'
      ])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    if (!$data) {
      return '<p class="incomplete">' . $this->t('Not completed') . '</p>';
    }

    $html = '<div class="summary-content">';

    // AFFF Usage
    $html .= '<h4>' . $this->t('AFFF (Firefighting Foam) Usage') . '</h4>';
    $html .= '<p><strong>' . $this->t('Ever used AFFF:') . '</strong> ' . 
      ucfirst($data['afff_used'] ?? 'Not answered') . '</p>';
    
    if ($data['afff_used'] === 'yes') {
      if (!empty($data['afff_times'])) {
        $html .= '<p><strong>' . $this->t('Approximate times used:') . '</strong> ' . 
          htmlspecialchars($data['afff_times']) . '</p>';
      }
      if (!empty($data['afff_first_year'])) {
        $html .= '<p><strong>' . $this->t('First year of use:') . '</strong> ' . 
          htmlspecialchars($data['afff_first_year']) . '</p>';
      }
    }

    // Diesel Exhaust
    $html .= '<h4>' . $this->t('Diesel Exhaust Exposure') . '</h4>';
    $diesel_labels = [
      'regularly' => 'Yes, regularly',
      'sometimes' => 'Sometimes',
      'rarely' => 'Rarely',
      'never' => 'Never',
    ];
    $html .= '<p>' . ($diesel_labels[$data['diesel_exhaust']] ?? ucfirst($data['diesel_exhaust'] ?? 'Not answered')) . '</p>';

    // Chemical Activities
    if (!empty($data['chemical_activities'])) {
      $activities = json_decode($data['chemical_activities'], TRUE);
      if (!empty($activities) && is_array($activities)) {
        $html .= '<h4>' . $this->t('Chemical Exposure Activities') . '</h4>';
        $activity_labels = [
          'fire_investigation' => 'Fire investigation',
          'overhaul' => 'Overhaul operations',
          'salvage' => 'Salvage operations',
          'vehicle_maintenance' => 'Vehicle maintenance/apparatus cleaning',
          'station_maintenance' => 'Station maintenance',
        ];
        $html .= '<ul>';
        foreach ($activities as $key => $val) {
          if ($val) {
            $html .= '<li>' . ($activity_labels[$key] ?? ucwords(str_replace('_', ' ', (string) $key))) . '</li>';
          }
        }
        $html .= '</ul>';
      }
    }

    // Major Incidents
    $html .= '<h4>' . $this->t('Major Incidents') . '</h4>';
    $html .= '<p><strong>' . $this->t('Involved in major incidents:') . '</strong> ' . 
      ($data['major_incidents'] ? 'Yes' : 'No') . '</p>';

    if ($data['major_incidents']) {
      $incidents = $this->database->select('nfr_major_incidents', 'mi')
        ->fields('mi', ['description', 'incident_date', 'duration'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      if (!empty($incidents)) {
        $html .= '<p><strong>' . $this->t('Incidents:') . '</strong></p><ul>';
        foreach ($incidents as $incident) {
          $html .= '<li>';
          $html .= htmlspecialchars($incident['description']);
          if (!empty($incident['incident_date'])) {
            $html .= '<br><small>' . htmlspecialchars($incident['incident_date']);
            if (!empty($incident['duration'])) {
              $html .= ' (' . htmlspecialchars($incident['duration']) . ')';
            }
            $html .= '</small>';
          }
          $html .= '</li>';
        }
        $html .= '</ul>';
      }
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Render Section 4: Military Service.
   */
  private function renderSection4(int $uid): string {
    $data = $this->database->select('nfr_questionnaire', 'q')
      ->fields('q', [
        'military_service', 'military_branch', 'military_start_date', 'military_end_date',
        'military_currently_serving', 'military_was_firefighter', 'military_firefighting_duties'
      ])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    if (!$data) {
      return '<p class="incomplete">' . $this->t('Not completed') . '</p>';
    }

    $html = '<div class="summary-content">';

    $html .= '<p><strong>' . $this->t('Military Service:') . '</strong> ' . 
      ($data['military_service'] ? 'Yes' : 'No') . '</p>';

    if ($data['military_service']) {
      if (!empty($data['military_branch'])) {
        $branch_labels = [
          'army' => 'Army',
          'navy' => 'Navy',
          'air_force' => 'Air Force',
          'marines' => 'Marines',
          'coast_guard' => 'Coast Guard',
          'national_guard' => 'National Guard',
          'reserves' => 'Reserves',
        ];
        $html .= '<p><strong>' . $this->t('Branch:') . '</strong> ' . 
          ($branch_labels[$data['military_branch']] ?? ucwords(str_replace('_', ' ', $data['military_branch']))) . '</p>';
      }

      if (!empty($data['military_start_date'])) {
        $html .= '<p><strong>' . $this->t('Service Period:') . '</strong> ';
        $html .= htmlspecialchars($data['military_start_date']);
        $html .= ' - ';
        $html .= $data['military_currently_serving'] ? '<em>Currently Serving</em>' : htmlspecialchars($data['military_end_date'] ?? 'Unknown');
        $html .= '</p>';
      }

      if (!empty($data['military_was_firefighter'])) {
        $html .= '<p><strong>' . $this->t('Military Firefighter:') . '</strong> ' . 
          ucfirst($data['military_was_firefighter']) . '</p>';
        
        if ($data['military_was_firefighter'] === 'yes' && !empty($data['military_firefighting_duties'])) {
          $html .= '<p><strong>' . $this->t('Firefighting Duties:') . '</strong><br>' . 
            nl2br(htmlspecialchars($data['military_firefighting_duties'])) . '</p>';
        }
      }
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Render Section 5: Other Employment.
   */
  private function renderSection5(int $uid): string {
    $questionnaire = $this->database->select('nfr_questionnaire', 'q')
      ->fields('q', ['had_other_jobs'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    // Handle case where no questionnaire data exists
    if ($questionnaire === false) {
      $questionnaire = [];
    }

    $html = '<div class="summary-content">';

    $html .= '<p><strong>' . $this->t('Other jobs outside firefighting (>1 year):') . '</strong> ' . 
      ucfirst($questionnaire['had_other_jobs'] ?? 'Not answered') . '</p>';

    if (($questionnaire['had_other_jobs'] ?? '') === 'yes') {
      $jobs = $this->database->select('nfr_other_employment', 'oe')
        ->fields('oe')
        ->condition('uid', $uid)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      if (!empty($jobs)) {
        $html .= '<p><strong>' . $this->t('Jobs:') . '</strong></p><ul>';
        foreach ($jobs as $job) {
          $html .= '<li>';
          $html .= '<strong>' . htmlspecialchars($job['occupation'] ?? '') . '</strong>';
          if (!empty($job['industry'])) {
            $html .= ' (' . htmlspecialchars($job['industry']) . ')';
          }
          if (!empty($job['start_year']) && !empty($job['end_year'])) {
            $html .= '<br>' . htmlspecialchars((string) $job['start_year']) . ' - ' . htmlspecialchars((string) $job['end_year']);
          }
          if (!empty($job['exposures'])) {
            $exposures = json_decode($job['exposures'], TRUE);
            if (!empty($exposures) && is_array($exposures)) {
              $html .= '<br><small>Exposures: ' . implode(', ', array_map('ucwords', $exposures)) . '</small>';
            }
          }
          $html .= '</li>';
        }
        $html .= '</ul>';
      }
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Render Section 6: PPE Practices.
   */
  private function renderSection6(int $uid): string {
    $data = $this->database->select('nfr_questionnaire', 'q')
      ->fields('q')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    if (!$data) {
      return '<p class="incomplete">' . $this->t('Not completed') . '</p>';
    }

    $html = '<div class="summary-content">';

    $ppe_types = [
      'scba' => 'Self-Contained Breathing Apparatus (SCBA)',
      'turnout_coat' => 'Turnout Coat',
      'turnout_pants' => 'Turnout Pants',
      'gloves' => 'Firefighting Gloves',
      'helmet' => 'Firefighting Helmet',
      'boots' => 'Firefighting Boots',
      'nomex_hood' => 'Nomex Hood (particulate-blocking)',
      'wildland_clothing' => 'Wildland Firefighting Clothing',
    ];

    $html .= '<h4>' . $this->t('Equipment Used') . '</h4>';
    $html .= '<table class="review-table"><thead><tr><th>Equipment</th><th>Ever Used</th><th>Year Started</th></tr></thead><tbody>';

    foreach ($ppe_types as $key => $label) {
      $ever_used = $data['ppe_' . $key . '_ever_used'] ?? '';
      $year_started = $data['ppe_' . $key . '_year_started'] ?? '';
      
      if ($ever_used) {
        $html .= '<tr>';
        $html .= '<td>' . $label . '</td>';
        $html .= '<td>' . ucfirst($ever_used) . '</td>';
        $html .= '<td>' . ($ever_used === 'yes' && $year_started ? htmlspecialchars($year_started) : '-') . '</td>';
        $html .= '</tr>';
      }
    }

    $html .= '</tbody></table>';

    // SCBA Usage Patterns
    $html .= '<h4>' . $this->t('SCBA Usage Frequency') . '</h4>';
    
    if (!empty($data['ppe_scba_during_suppression'])) {
      $freq_labels = [
        'always' => 'Always (100%)',
        'usually' => 'Usually (75-99%)',
        'sometimes' => 'Sometimes (25-74%)',
        'rarely' => 'Rarely (<25%)',
        'never' => 'Never',
      ];
      $html .= '<p><strong>' . $this->t('During fire suppression:') . '</strong> ' . 
        ($freq_labels[$data['ppe_scba_during_suppression']] ?? ucfirst($data['ppe_scba_during_suppression'])) . '</p>';
    }

    if (!empty($data['ppe_scba_during_overhaul'])) {
      $freq_labels = [
        'always' => 'Always (100%)',
        'usually' => 'Usually (75-99%)',
        'sometimes' => 'Sometimes (25-74%)',
        'rarely' => 'Rarely (<25%)',
        'never' => 'Never',
      ];
      $html .= '<p><strong>' . $this->t('During overhaul operations:') . '</strong> ' . 
        ($freq_labels[$data['ppe_scba_during_overhaul']] ?? ucfirst($data['ppe_scba_during_overhaul'])) . '</p>';
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Render Section 7: Decontamination Practices.
   */
  private function renderSection7(int $uid): string {
    $data = $this->database->select('nfr_questionnaire', 'q')
      ->fields('q', [
        'decon_washed_hands_face', 'decon_changed_gear_at_scene',
        'decon_showered_at_station', 'decon_laundered_gear', 'decon_used_wet_wipes',
        'decon_department_had_sops', 'decon_sops_year_implemented'
      ])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    if (!$data) {
      return '<p class="incomplete">' . $this->t('Not completed') . '</p>';
    }

    $html = '<div class="summary-content">';

    $html .= '<h4>' . $this->t('Decontamination Practices Frequency') . '</h4>';
    $html .= '<table class="review-table"><thead><tr><th>Practice</th><th>Frequency</th></tr></thead><tbody>';

    $practices = [
      'washed_hands_face' => 'Washed hands and face at scene',
      'changed_gear_at_scene' => 'Changed out of contaminated gear at scene',
      'showered_at_station' => 'Showered soon after returning to station',
      'laundered_gear' => 'Laundered turnout gear regularly',
      'used_wet_wipes' => 'Used wet wipes to clean skin after fire',
    ];

    foreach ($practices as $key => $label) {
      $value = $data['decon_' . $key] ?? '';
      if ($value) {
        $html .= '<tr><td>' . $label . '</td><td>' . ucfirst($value) . '</td></tr>';
      }
    }

    $html .= '</tbody></table>';

    $html .= '<h4>' . $this->t('Department SOPs/SOGs') . '</h4>';
    $html .= '<p><strong>' . $this->t('Department had decontamination SOPs:') . '</strong> ' . 
      ucfirst($data['decon_department_had_sops'] ?? 'Not answered') . '</p>';

    if ($data['decon_department_had_sops'] === 'yes' && !empty($data['decon_sops_year_implemented'])) {
      $html .= '<p><strong>' . $this->t('Year implemented:') . '</strong> ' . 
        htmlspecialchars($data['decon_sops_year_implemented']) . '</p>';
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Render Section 8: Health Information.
   */
  private function renderSection8(int $uid): string {
    $data = $this->database->select('nfr_questionnaire', 'q')
      ->fields('q', [
        'cancer_diagnosis',
        'health_heart_disease', 'health_copd', 'health_asthma', 'health_diabetes'
      ])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    if (!$data) {
      return '<p class="incomplete">' . $this->t('Not completed') . '</p>';
    }

    $html = '<div class="summary-content">';

    $html .= '<h4>' . $this->t('Cancer Diagnoses') . '</h4>';
    $html .= '<p><strong>' . $this->t('Ever diagnosed with cancer:') . '</strong> ' . 
      ($data['cancer_diagnosis'] ? 'Yes' : 'No') . '</p>';

    if ($data['cancer_diagnosis']) {
      $cancers = $this->database->select('nfr_cancer_diagnoses', 'cd')
        ->fields('cd', ['cancer_type', 'year_diagnosed'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      if (!empty($cancers)) {
        $html .= '<ul>';
        foreach ($cancers as $cancer) {
          $html .= '<li>';
          $html .= '<strong>' . htmlspecialchars($cancer['cancer_type']) . '</strong>';
          if (!empty($cancer['year_diagnosed'])) {
            $html .= ' - ' . htmlspecialchars($cancer['year_diagnosed']);
          }
          $html .= '</li>';
        }
        $html .= '</ul>';
      }
    }

    $html .= '<h4>' . $this->t('Other Health Conditions') . '</h4>';
    $conditions = [];
    if ($data['health_heart_disease']) $conditions[] = 'Heart disease';
    if ($data['health_copd']) $conditions[] = 'COPD/Chronic bronchitis';
    if ($data['health_asthma']) $conditions[] = 'Asthma';
    if ($data['health_diabetes']) $conditions[] = 'Diabetes';

    if (!empty($conditions)) {
      $html .= '<ul>';
      foreach ($conditions as $condition) {
        $html .= '<li>' . $condition . '</li>';
      }
      $html .= '</ul>';
    } else {
      $html .= '<p>' . $this->t('None reported') . '</p>';
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Render Section 9: Lifestyle Factors.
   */
  private function renderSection9(int $uid): string {
    $data = $this->database->select('nfr_questionnaire', 'q')
      ->fields('q', ['smoking_history', 'alcohol_use', 'physical_activity_days'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    if (!$data) {
      return '<p class="incomplete">' . $this->t('Not completed') . '</p>';
    }

    $html = '<div class="summary-content">';

    // Smoking
    $html .= '<h4>' . $this->t('Tobacco Use') . '</h4>';
    if (!empty($data['smoking_history'])) {
      $smoking = json_decode($data['smoking_history'], TRUE);
      if (!empty($smoking)) {
        $status_labels = [
          'never' => 'Never smoked',
          'former' => 'Former smoker',
          'current' => 'Current smoker',
        ];
        $html .= '<p><strong>' . $this->t('Smoking Status:') . '</strong> ' . 
          ($status_labels[$smoking['smoking_status']] ?? ucfirst($smoking['smoking_status'])) . '</p>';

        if (in_array($smoking['smoking_status'], ['former', 'current'])) {
          if (!empty($smoking['smoking_age_started'])) {
            $html .= '<p><strong>' . $this->t('Age started:') . '</strong> ' . 
              htmlspecialchars($smoking['smoking_age_started']) . '</p>';
          }
          if ($smoking['smoking_status'] === 'former' && !empty($smoking['smoking_age_stopped'])) {
            $html .= '<p><strong>' . $this->t('Age stopped:') . '</strong> ' . 
              htmlspecialchars($smoking['smoking_age_stopped']) . '</p>';
          }
          if (!empty($smoking['cigarettes_per_day'])) {
            $cig_labels = [
              'less_than_half_pack' => 'Less than 1/2 pack (<10)',
              'half_to_one_pack' => '1/2 to 1 pack (10-20)',
              'one_to_two_packs' => '1 to 2 packs (20-40)',
              'more_than_two_packs' => 'More than 2 packs (>40)',
            ];
            $html .= '<p><strong>' . $this->t('Cigarettes per day:') . '</strong> ' . 
              ($cig_labels[$smoking['cigarettes_per_day']] ?? htmlspecialchars($smoking['cigarettes_per_day'])) . '</p>';
          }
        }
      }
    } else {
      $html .= '<p>' . $this->t('Not answered') . '</p>';
    }

    // Alcohol
    $html .= '<h4>' . $this->t('Alcohol Use') . '</h4>';
    if (!empty($data['alcohol_use'])) {
      $alcohol_labels = [
        'never' => 'Never',
        'less_than_monthly' => 'Less than once a month',
        '1_3_per_month' => '1-3 times per month',
        '1_2_per_week' => '1-2 times per week',
        '3_4_per_week' => '3-4 times per week',
        '5_plus_per_week' => '5+ times per week',
      ];
      $html .= '<p>' . ($alcohol_labels[$data['alcohol_use']] ?? ucwords(str_replace('_', ' ', $data['alcohol_use']))) . '</p>';
    } else {
      $html .= '<p>' . $this->t('Not answered') . '</p>';
    }

    // Physical Activity
    $html .= '<h4>' . $this->t('Physical Activity') . '</h4>';
    if (isset($data['physical_activity_days'])) {
      $html .= '<p>' . $this->t('@days days per week of moderate or vigorous physical activity', 
        ['@days' => $data['physical_activity_days']]) . '</p>';
    } else {
      $html .= '<p>' . $this->t('Not answered') . '</p>';
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Build process flow diagram.
   */
  private function buildProcessFlowDiagram(int $uid): string {
    $completed_sections = $this->database->select('nfr_section_completion', 'sc')
      ->fields('sc', ['section_number'])
      ->condition('uid', $uid)
      ->condition('completed', 1)
      ->execute()
      ->fetchCol();

    $progress_percent = (count($completed_sections) / 9) * 100;
    
    $sections = [
      1 => 'Demographics',
      2 => 'Work History',
      3 => 'Exposure Info',
      4 => 'Military Service',
      5 => 'Other Employment',
      6 => 'PPE Practices',
      7 => 'Decontamination',
      8 => 'Health Info',
      9 => 'Lifestyle',
    ];

    $html = '<div class="nfr-process-stepper">';
    $html .= '<div class="stepper-header">';
    $html .= '<div class="stepper-title">Review & Submit</div>';
    $html .= '<div class="stepper-progress">' . round($progress_percent) . '% Complete</div>';
    $html .= '</div>';
    $html .= '<div class="stepper-steps">';
    
    foreach ($sections as $section_num => $section_name) {
      $is_completed = in_array($section_num, $completed_sections);
      $step_class = 'stepper-step' . ($is_completed ? ' completed clickable' : ' upcoming clickable');
      $section_url = \Drupal\Core\Url::fromRoute('nfr.questionnaire.section' . $section_num)->toString();
      
      $html .= '<div class="' . $step_class . '" data-section="' . $section_num . '">';
      $html .= '<a href="' . $section_url . '" class="step-link">';
      $html .= '<div class="step-number">';
      
      if ($is_completed) {
        $html .= '<svg class="step-check" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
      } else {
        $html .= $section_num;
      }
      
      $html .= '</div>';
      $html .= '<div class="step-label">' . $section_name . '</div>';
      $html .= '</a>';
      $html .= '</div>';
    }
    
    $html .= '</div></div>';
    return $html;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Validation handled by required checkbox
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser->id();
    
    // Mark questionnaire as fully completed
    $this->database->update('nfr_questionnaire')
      ->fields([
        'questionnaire_completed' => 1,
        'questionnaire_completed_date' => time(),
        'updated' => time(),
      ])
      ->condition('uid', $uid)
      ->execute();

    $this->database->update('nfr_user_profile')
      ->fields([
        'profile_completed' => 1,
        'updated' => time(),
      ])
      ->condition('uid', $uid)
      ->execute();

    $this->messenger()->addStatus($this->t('Thank you for completing your enrollment in the National Firefighter Registry!'));
    $form_state->setRedirect('nfr.confirmation');
  }

}
