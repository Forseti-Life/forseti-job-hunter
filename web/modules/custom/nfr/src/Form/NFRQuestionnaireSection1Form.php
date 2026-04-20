<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Section 1: Demographics.
 */
class NFRQuestionnaireSection1Form extends FormBase {

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
    return 'nfr_questionnaire_section_1';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $uid = (int) $this->currentUser->id();
    
    // Load demographics from database columns
    $database = $this->getDatabase();
    $questionnaire = $database->select('nfr_questionnaire', 'q')
      ->fields('q', ['race_ethnicity', 'race_other', 'education_level', 'marital_status', 'height_inches', 'weight_pounds'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    
    $demographics = [];
    if ($questionnaire) {
      $demographics['race_ethnicity'] = $questionnaire['race_ethnicity'] ? json_decode($questionnaire['race_ethnicity'], TRUE) : [];
      $demographics['race_other'] = $questionnaire['race_other'] ?? '';
      $demographics['education_level'] = $questionnaire['education_level'] ?? '';
      $demographics['marital_status'] = $questionnaire['marital_status'] ?? '';
      $demographics['height_inches'] = $questionnaire['height_inches'] ?? '';
      $demographics['weight_pounds'] = $questionnaire['weight_pounds'] ?? '';
    }

    // Add navigation menu
    $form['navigation'] = $this->buildNavigationMenu(1);

    $form['section_title'] = [
      '#type' => 'markup',
      '#markup' => '<h2>Section 1: Demographics</h2>',
    ];

    $form['demographics'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Demographics'),
      '#tree' => TRUE,
    ];

    $form['demographics']['race_ethnicity'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Race/Ethnicity (select all that apply)'),
      '#options' => [
        'american_indian' => $this->t('American Indian or Alaska Native'),
        'asian' => $this->t('Asian'),
        'black' => $this->t('Black or African American'),
        'hispanic' => $this->t('Hispanic or Latino'),
        'middle_eastern' => $this->t('Middle Eastern or North African'),
        'pacific_islander' => $this->t('Native Hawaiian or Other Pacific Islander'),
        'white' => $this->t('White'),
        'other' => $this->t('Other'),
      ],
      '#default_value' => $demographics['race_ethnicity'] ?? [],
    ];

    $form['demographics']['race_other'] = [
      '#type' => 'textfield',
      '#title' => $this->t('If other, please specify'),
      '#default_value' => $demographics['race_other'] ?? '',
      '#states' => [
        'visible' => [
          ':input[name="demographics[race_ethnicity][other]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['demographics']['education_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Highest Education Level'),
      '#required' => TRUE,
      '#options' => [
        '' => $this->t('- Select -'),
        'never_attended' => $this->t('Never attended school or only attended kindergarten'),
        'elementary' => $this->t('Grades 1 through 8 (Elementary)'),
        'some_hs' => $this->t('Grades 9 through 11 (Some high school)'),
        'hs_ged' => $this->t('Grade 12 or GED (High school graduate)'),
        'some_college' => $this->t('College for 1 year to 3 years (Some college or technical school)'),
        'college_graduate' => $this->t('College for 4 years or more (College graduate or advanced graduate education)'),
        'prefer_not_answer' => $this->t('Prefer not to answer'),
      ],
      '#default_value' => $demographics['education_level'] ?? '',
    ];

    $form['demographics']['marital_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Marital Status'),
      '#required' => TRUE,
      '#options' => [
        '' => $this->t('- Select -'),
        'married' => $this->t('Married'),
        'living_with_partner' => $this->t('Living with a partner as an unmarried couple'),
        'never_married' => $this->t('Never married'),
        'divorced' => $this->t('Divorced'),
        'separated' => $this->t('Separated'),
        'widowed' => $this->t('Widowed'),
        'prefer_not_answer' => $this->t('Prefer not to answer'),
      ],
      '#default_value' => $demographics['marital_status'] ?? '',
    ];

    $form['demographics']['height_inches'] = [
      '#type' => 'number',
      '#title' => $this->t('Height (inches)'),
      '#required' => TRUE,
      '#min' => 48,
      '#max' => 96,
      '#step' => 1,
      '#field_suffix' => $this->t('inches'),
      '#default_value' => $demographics['height_inches'] ?? '',
      '#description' => $this->t('Enter your height in inches (e.g., 5\'10" = 70 inches)'),
    ];

    $form['demographics']['weight_pounds'] = [
      '#type' => 'number',
      '#title' => $this->t('Weight (pounds)'),
      '#required' => TRUE,
      '#min' => 80,
      '#max' => 500,
      '#step' => 1,
      '#field_suffix' => $this->t('lbs'),
      '#default_value' => $demographics['weight_pounds'] ?? '',
      '#description' => $this->t('Enter your weight in pounds'),
    ];

    // Calculate and display BMI if both values are present
    if (!empty($demographics['height_inches']) && !empty($demographics['weight_pounds'])) {
      $height = (float) $demographics['height_inches'];
      $weight = (float) $demographics['weight_pounds'];
      $bmi = round(($weight / ($height * $height)) * 703, 1);
      
      $form['demographics']['bmi_display'] = [
        '#type' => 'markup',
        '#markup' => '<div class="bmi-display"><strong>Current BMI:</strong> ' . $bmi . '</div>',
        '#weight' => 100,
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save & Continue to Section 2'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $demographics = $form_state->getValue('demographics');

    // Prepare race_ethnicity as JSON array (only checked values)
    $race_values = array_filter($demographics['race_ethnicity']);
    $race_json = !empty($race_values) ? json_encode(array_values($race_values)) : NULL;

    // Save demographics to specific columns
    $database = $this->getDatabase();
    
    // Ensure the questionnaire record exists
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    // Update the database
    $database->update('nfr_questionnaire')
      ->fields([
        'race_ethnicity' => $race_json,
        'race_other' => $demographics['race_other'] ?: NULL,
        'education_level' => $demographics['education_level'] ?: NULL,
        'marital_status' => $demographics['marital_status'] ?: NULL,
        'height_inches' => $demographics['height_inches'] ?: NULL,
        'weight_pounds' => $demographics['weight_pounds'] ?: NULL,
        'updated' => time(),
      ])
      ->condition('uid', $uid)
      ->execute();
    
    // Mark section 1 as complete
    $this->markSectionComplete($uid, 1, $database);

    $this->messenger()->addStatus($this->t('Section 1 saved successfully.'));
    $form_state->setRedirect('nfr.questionnaire.section2');
  }

}
