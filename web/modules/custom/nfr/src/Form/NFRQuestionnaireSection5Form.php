<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * NFR Questionnaire Section 5: Other Employment.
 */
class NFRQuestionnaireSection5Form extends FormBase {

  use QuestionnaireFormTrait;

  /**
   * Constructs a new NFRQuestionnaireSection5Form.
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
    return 'nfr_questionnaire_section5_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'nfr/enrollment';

    $uid = $this->getCurrentUserId();
    
    // Load other employment from database
    $database = $this->getDatabase();
    $questionnaire = $database->select('nfr_questionnaire', 'q')
      ->fields('q', ['had_other_jobs'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    
    $other_employment = [];
    $other_employment['had_other_jobs'] = $questionnaire['had_other_jobs'] ?? NULL;
    
    // Load jobs from nfr_other_employment table
    $jobs = $database->select('nfr_other_employment', 'oe')
      ->fields('oe')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAll();
    
    $other_employment['jobs'] = [];
    foreach ($jobs as $job) {
      $other_employment['jobs'][] = [
        'occupation' => $job->occupation,
        'industry' => $job->industry,
        'start_year' => $job->start_year,
        'end_year' => $job->end_year,
        'exposures' => $job->exposures ? json_decode($job->exposures, TRUE) : [],
        'exposures_other' => $job->exposures_other,
      ];
    }

    // Add navigation menu
    $form['navigation'] = $this->buildNavigationMenu(5);

    $form['section_title'] = [
      '#type' => 'markup',
      '#markup' => '<h2>Section 5: Other Employment</h2><p>Please tell us about any other jobs you held for more than one year (outside of firefighting).</p>',
    ];

    $form['other_employment'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Other Employment'),
      '#tree' => TRUE,
    ];

    $form['other_employment']['had_other_jobs'] = [
      '#type' => 'radios',
      '#title' => $this->t('Have you had any other jobs (non-firefighting) for more than one year?'),
      '#required' => TRUE,
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
      ],
      '#default_value' => $other_employment['had_other_jobs'] ?? NULL,
    ];

    // Other jobs repeating fields
    $num_other_jobs = $form_state->get('num_other_jobs') ?? count($other_employment['jobs'] ?? []) ?: 0;

    $form['other_employment']['jobs'] = [
      '#type' => 'container',
      '#prefix' => '<div id="other-jobs-wrapper">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="other_employment[had_other_jobs]"]' => ['value' => 'yes'],
        ],
      ],
    ];

    $form['other_employment']['add_job'] = [
      '#type' => 'submit',
      '#value' => $this->t('+ Add Another Job'),
      '#submit' => ['::addOtherJob'],
      '#ajax' => [
        'callback' => '::updateOtherJobFields',
        'wrapper' => 'other-jobs-wrapper',
      ],
      '#limit_validation_errors' => [],
      '#states' => [
        'visible' => [
          ':input[name="other_employment[had_other_jobs]"]' => ['value' => 'yes'],
        ],
      ],
    ];

    for ($i = 0; $i < $num_other_jobs; $i++) {
      $job_data = $other_employment['jobs'][$i] ?? [];
      
      $form['other_employment']['jobs'][$i] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Job @num', ['@num' => $i + 1]),
      ];

      $form['other_employment']['jobs'][$i]['occupation'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Job/Occupation'),
        '#default_value' => $job_data['occupation'] ?? '',
      ];

      $form['other_employment']['jobs'][$i]['industry'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Industry'),
        '#default_value' => $job_data['industry'] ?? '',
      ];

      $form['other_employment']['jobs'][$i]['start_year'] = [
        '#type' => 'number',
        '#title' => $this->t('Start Year'),
        '#min' => 1950,
        '#max' => (int) date('Y'),
        '#default_value' => $job_data['start_year'] ?? '',
      ];

      $form['other_employment']['jobs'][$i]['end_year'] = [
        '#type' => 'number',
        '#title' => $this->t('End Year'),
        '#min' => 1950,
        '#max' => (int) date('Y'),
        '#default_value' => $job_data['end_year'] ?? '',
      ];

      $form['other_employment']['jobs'][$i]['exposures'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Potential Hazardous Exposures'),
        '#options' => [
          'chemicals' => $this->t('Chemicals'),
          'radiation' => $this->t('Radiation'),
          'asbestos' => $this->t('Asbestos'),
          'heavy_metals' => $this->t('Heavy metals'),
          'other' => $this->t('Other'),
        ],
        '#default_value' => $job_data['exposures'] ?? [],
      ];

      $form['other_employment']['jobs'][$i]['exposures_other'] = [
        '#type' => 'textfield',
        '#title' => $this->t('If other, please specify'),
        '#default_value' => $job_data['exposures_other'] ?? '',
        '#states' => [
          'visible' => [
            ':input[name="other_employment[jobs][' . $i . '][exposures][other]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

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
   * AJAX callback to update other job fields.
   */
  public function updateOtherJobFields(array &$form, FormStateInterface $form_state): array {
    return $form['other_employment']['jobs'];
  }

  /**
   * Submit handler to add another other job.
   */
  public function addOtherJob(array &$form, FormStateInterface $form_state): void {
    $num_jobs = $form_state->get('num_other_jobs') ?? 0;
    $form_state->set('num_other_jobs', $num_jobs + 1);
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $other_employment = $form_state->getValue('other_employment');

    // Save to database
    $database = $this->getDatabase();
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    // Update had_other_jobs
    $database->update('nfr_questionnaire')
      ->fields([
        'had_other_jobs' => $other_employment['had_other_jobs'] ?? 'no',
      ])
      ->condition('uid', $uid)
      ->execute();
    
    // Mark section 5 as complete
    $this->markSectionComplete($uid, 5, $database);
    
    // Delete existing jobs
    $database->delete('nfr_other_employment')
      ->condition('uid', $uid)
      ->execute();
    
    // Insert new jobs if they had other jobs
    if (($other_employment['had_other_jobs'] ?? 'no') === 'yes' && !empty($other_employment['jobs'])) {
      $time = \Drupal::time()->getRequestTime();
      
      foreach ($other_employment['jobs'] as $job) {
        if (empty($job['occupation']) && empty($job['industry'])) {
          continue; // Skip empty jobs
        }
        
        $database->insert('nfr_other_employment')
          ->fields([
            'uid' => $uid,
            'occupation' => $job['occupation'] ?? '',
            'industry' => $job['industry'] ?? '',
            'start_year' => !empty($job['start_year']) ? (int) $job['start_year'] : NULL,
            'end_year' => !empty($job['end_year']) ? (int) $job['end_year'] : NULL,
            'exposures' => !empty($job['exposures']) ? json_encode(array_filter($job['exposures'])) : NULL,
            'exposures_other' => $job['exposures_other'] ?? '',
            'created' => $time,
            'updated' => $time,
          ])
          ->execute();
      }
    }

    $this->messenger()->addStatus($this->t('Section 5 saved.'));
    $form_state->setRedirect('nfr.questionnaire.section6');
  }

  /**
   * Submit handler for previous button.
   */
  public function previousSection(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $other_employment = $form_state->getValue('other_employment');

    // Save to database
    $database = $this->getDatabase();
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    // Update had_other_jobs
    $database->update('nfr_questionnaire')
      ->fields(['had_other_jobs' => $other_employment['had_other_jobs'] ?? 'no'])
      ->condition('uid', $uid)
      ->execute();
    
    // Delete existing jobs
    $database->delete('nfr_other_employment')
      ->condition('uid', $uid)
      ->execute();
    
    // Insert new jobs if they had other jobs
    if (($other_employment['had_other_jobs'] ?? 'no') === 'yes' && !empty($other_employment['jobs'])) {
      $time = \Drupal::time()->getRequestTime();
      
      foreach ($other_employment['jobs'] as $job) {
        if (empty($job['occupation']) && empty($job['industry'])) {
          continue; // Skip empty jobs
        }
        
        $database->insert('nfr_other_employment')
          ->fields([
            'uid' => $uid,
            'occupation' => $job['occupation'] ?? '',
            'industry' => $job['industry'] ?? '',
            'start_year' => !empty($job['start_year']) ? (int) $job['start_year'] : NULL,
            'end_year' => !empty($job['end_year']) ? (int) $job['end_year'] : NULL,
            'exposures' => !empty($job['exposures']) ? json_encode(array_filter($job['exposures'])) : NULL,
            'exposures_other' => $job['exposures_other'] ?? '',
            'created' => $time,
            'updated' => $time,
          ])
          ->execute();
      }
    }
    
    $form_state->setRedirect('nfr.questionnaire.section4');
  }

  /**
   * Submit handler for save and exit button.
   */
  public function saveAndExit(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $other_employment = $form_state->getValue('other_employment');

    // Save to database
    $database = $this->getDatabase();
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    // Update had_other_jobs
    $database->update('nfr_questionnaire')
      ->fields(['had_other_jobs' => $other_employment['had_other_jobs'] ?? 'no'])
      ->condition('uid', $uid)
      ->execute();
    
    // Delete existing jobs
    $database->delete('nfr_other_employment')
      ->condition('uid', $uid)
      ->execute();
    
    // Insert new jobs if they had other jobs
    if (($other_employment['had_other_jobs'] ?? 'no') === 'yes' && !empty($other_employment['jobs'])) {
      $time = \Drupal::time()->getRequestTime();
      
      foreach ($other_employment['jobs'] as $job) {
        if (empty($job['occupation']) && empty($job['industry'])) {
          continue; // Skip empty jobs
        }
        
        $database->insert('nfr_other_employment')
          ->fields([
            'uid' => $uid,
            'occupation' => $job['occupation'] ?? '',
            'industry' => $job['industry'] ?? '',
            'start_year' => !empty($job['start_year']) ? (int) $job['start_year'] : NULL,
            'end_year' => !empty($job['end_year']) ? (int) $job['end_year'] : NULL,
            'exposures' => !empty($job['exposures']) ? json_encode(array_filter($job['exposures'])) : NULL,
            'exposures_other' => $job['exposures_other'] ?? '',
            'created' => $time,
            'updated' => $time,
          ])
          ->execute();
      }
    }

    $this->messenger()->addStatus($this->t('Your progress has been saved.'));
    $form_state->setRedirect('nfr.dashboard');
  }

}
