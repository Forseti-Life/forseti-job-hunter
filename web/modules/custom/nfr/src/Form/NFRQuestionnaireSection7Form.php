<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * NFR Questionnaire Section 7: Decontamination Practices.
 */
class NFRQuestionnaireSection7Form extends FormBase {

  use QuestionnaireFormTrait;

  /**
   * Constructs a new NFRQuestionnaireSection7Form.
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
    return 'nfr_questionnaire_section7_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'nfr/enrollment';

    $uid = $this->getCurrentUserId();
    
    // Load decon data from direct columns
    $database = $this->getDatabase();
    $questionnaire = $database->select('nfr_questionnaire', 'q')
      ->fields('q', [
        'decon_washed_hands_face',
        'decon_changed_gear_at_scene',
        'decon_showered_at_station',
        'decon_laundered_gear',
        'decon_used_wet_wipes',
        'decon_department_had_sops',
        'decon_sops_year_implemented',
      ])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    
    $decon = [];
    if ($questionnaire) {
      $decon['washed_hands_face'] = $questionnaire['decon_washed_hands_face'] ?? '';
      $decon['changed_gear_at_scene'] = $questionnaire['decon_changed_gear_at_scene'] ?? '';
      $decon['showered_at_station'] = $questionnaire['decon_showered_at_station'] ?? '';
      $decon['laundered_gear'] = $questionnaire['decon_laundered_gear'] ?? '';
      $decon['used_wet_wipes'] = $questionnaire['decon_used_wet_wipes'] ?? '';
      $decon['department_had_sops'] = $questionnaire['decon_department_had_sops'] ?? NULL;
      $decon['sops_year_implemented'] = $questionnaire['decon_sops_year_implemented'] ?? '';
    }

    // Add navigation menu
    $form['navigation'] = $this->buildNavigationMenu(7);

    $form['section_title'] = [
      '#type' => 'markup',
      '#markup' => '<h2>Section 7: Decontamination Practices</h2><p>These questions are about cleaning practices after fires or other exposures.</p>',
    ];

    $form['decontamination'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Decontamination Practices'),
      '#tree' => TRUE,
    ];

    $form['decontamination']['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('After fire suppression or other emergency operations, how often did you do the following?') . '</p>',
    ];

    $practices = [
      'washed_hands_face' => 'Washed hands and face at scene',
      'changed_gear_at_scene' => 'Changed out of contaminated gear at scene',
      'showered_at_station' => 'Showered soon after returning to station',
      'laundered_gear' => 'Laundered turnout gear regularly',
      'used_wet_wipes' => 'Used wet wipes to clean skin after fire',
    ];

    $frequency_options = [
      '' => $this->t('- Select -'),
      'always' => $this->t('Always'),
      'usually' => $this->t('Usually'),
      'sometimes' => $this->t('Sometimes'),
      'rarely' => $this->t('Rarely'),
      'never' => $this->t('Never'),
    ];

    foreach ($practices as $practice_key => $practice_label) {
      $form['decontamination'][$practice_key] = [
        '#type' => 'select',
        '#title' => $this->t($practice_label),
        '#options' => $frequency_options,
        '#default_value' => $decon[$practice_key] ?? '',
      ];
    }

    $form['decontamination']['department_had_sops'] = [
      '#type' => 'radios',
      '#title' => $this->t('Did your department have decontamination SOPs/SOGs?'),
      '#required' => TRUE,
      '#options' => [
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
        'unknown' => $this->t("Don't Know"),
      ],
      '#default_value' => $decon['department_had_sops'] ?? NULL,
    ];

    $form['decontamination']['sops_year_implemented'] = [
      '#type' => 'number',
      '#title' => $this->t('In what year were they implemented?'),
      '#min' => 1950,
      '#max' => (int) date('Y'),
      '#default_value' => $decon['sops_year_implemented'] ?? '',
      '#states' => [
        'visible' => [
          ':input[name="decontamination[department_had_sops]"]' => ['value' => 'yes'],
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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $decontamination = $form_state->getValue('decontamination');

    // Save decon data to direct columns
    $database = $this->getDatabase();
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    $database->update('nfr_questionnaire')
      ->fields([
        'decon_washed_hands_face' => $decontamination['washed_hands_face'] ?? NULL,
        'decon_changed_gear_at_scene' => $decontamination['changed_gear_at_scene'] ?? NULL,
        'decon_showered_at_station' => $decontamination['showered_at_station'] ?? NULL,
        'decon_laundered_gear' => $decontamination['laundered_gear'] ?? NULL,
        'decon_used_wet_wipes' => $decontamination['used_wet_wipes'] ?? NULL,
        'decon_department_had_sops' => $decontamination['department_had_sops'] ?? NULL,
        'decon_sops_year_implemented' => !empty($decontamination['sops_year_implemented']) ? (int) $decontamination['sops_year_implemented'] : NULL,
      ])
      ->condition('uid', $uid)
      ->execute();

    // Mark section 7 as complete
    $this->markSectionComplete($uid, 7, $database);

    $this->messenger()->addStatus($this->t('Section 7 saved.'));
    $form_state->setRedirect('nfr.questionnaire.section8');
  }

  /**
   * Submit handler for previous button.
   */
  public function previousSection(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $decontamination = $form_state->getValue('decontamination');

    // Save decon data to direct columns
    $database = $this->getDatabase();
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    $database->update('nfr_questionnaire')
      ->fields([
        'decon_washed_hands_face' => $decontamination['washed_hands_face'] ?? NULL,
        'decon_changed_gear_at_scene' => $decontamination['changed_gear_at_scene'] ?? NULL,
        'decon_showered_at_station' => $decontamination['showered_at_station'] ?? NULL,
        'decon_laundered_gear' => $decontamination['laundered_gear'] ?? NULL,
        'decon_used_wet_wipes' => $decontamination['used_wet_wipes'] ?? NULL,
        'decon_department_had_sops' => $decontamination['department_had_sops'] ?? NULL,
        'decon_sops_year_implemented' => !empty($decontamination['sops_year_implemented']) ? (int) $decontamination['sops_year_implemented'] : NULL,
      ])
      ->condition('uid', $uid)
      ->execute();
    
    $form_state->setRedirect('nfr.questionnaire.section6');
  }

  /**
   * Submit handler for save and exit button.
   */
  public function saveAndExit(array &$form, FormStateInterface $form_state): void {
    $uid = $this->getCurrentUserId();
    $decontamination = $form_state->getValue('decontamination');

    // Save decon data to direct columns
    $database = $this->getDatabase();
    
    // Ensure record exists before updating
    $this->ensureQuestionnaireRecordExists($uid, $database);
    
    $database->update('nfr_questionnaire')
      ->fields([
        'decon_washed_hands_face' => $decontamination['washed_hands_face'] ?? NULL,
        'decon_changed_gear_at_scene' => $decontamination['changed_gear_at_scene'] ?? NULL,
        'decon_showered_at_station' => $decontamination['showered_at_station'] ?? NULL,
        'decon_laundered_gear' => $decontamination['laundered_gear'] ?? NULL,
        'decon_used_wet_wipes' => $decontamination['used_wet_wipes'] ?? NULL,
        'decon_department_had_sops' => $decontamination['department_had_sops'] ?? NULL,
        'decon_sops_year_implemented' => !empty($decontamination['sops_year_implemented']) ? (int) $decontamination['sops_year_implemented'] : NULL,
      ])
      ->condition('uid', $uid)
      ->execute();

    $this->messenger()->addStatus($this->t('Your progress has been saved.'));
    $form_state->setRedirect('nfr.dashboard');
  }

}
