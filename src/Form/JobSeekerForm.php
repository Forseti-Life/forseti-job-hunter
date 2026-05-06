<?php

namespace Drupal\job_hunter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\job_hunter\Service\JobSeekerService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Form for creating/editing job seeker profiles.
 */
class JobSeekerForm extends FormBase {

  /**
   * The job seeker service.
   *
   * @var \Drupal\job_hunter\Service\JobSeekerService
   */
  protected $jobSeekerService;

  /**
   * The job seeker ID being edited (null for new profiles).
   *
   * @var int|null
   */
  protected $jobSeekerId;

  /**
   * Constructs a JobSeekerForm object.
   *
   * @param \Drupal\job_hunter\Service\JobSeekerService $job_seeker_service
   *   The job seeker service.
   */
  public function __construct(JobSeekerService $job_seeker_service) {
    $this->jobSeekerService = $job_seeker_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('job_hunter.job_seeker_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_seeker_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $job_seeker_id = NULL) {
    $this->jobSeekerId = $job_seeker_id;
    $profile = NULL;
    
    if ($job_seeker_id) {
      $profile = $this->jobSeekerService->load($job_seeker_id);
    }

    // Load user entity for default value
    $default_user = NULL;
    if ($profile && $profile->uid) {
      $default_user = \Drupal\user\Entity\User::load($profile->uid);
    } else {
      $current_user_id = \Drupal::currentUser()->id();
      if ($current_user_id) {
        $default_user = \Drupal\user\Entity\User::load($current_user_id);
      }
    }

    $form['uid'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('User'),
      '#target_type' => 'user',
      '#default_value' => $default_user,
      '#required' => TRUE,
      '#description' => $this->t('The user this profile belongs to.'),
    ];

    // Load resume node for default value
    $default_resume_node = NULL;
    if ($profile && $profile->resume_node_id) {
      $resume_node = \Drupal\node\Entity\Node::load($profile->resume_node_id);
      if ($resume_node && $resume_node->access('view')) {
        $default_resume_node = $resume_node;
      }
    }

    $form['resume_node_id'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Original Resume'),
      '#target_type' => 'node',
      '#selection_settings' => [
        'target_bundles' => ['resume'],
      ],
      '#default_value' => $default_resume_node,
      '#description' => $this->t('Select the original resume document.'),
    ];

    $form['skills'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Skills'),
      '#default_value' => $profile && !empty($profile->skills) ? implode(', ', $profile->skills) : '',
      '#description' => $this->t('Enter skills separated by commas.'),
    ];

    $form['experience_years'] = [
      '#type' => 'number',
      '#title' => $this->t('Years of Experience'),
      '#default_value' => $profile ? $profile->experience_years : NULL,
      '#min' => 0,
      '#max' => 100,
    ];

    $form['job_titles'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Target Job Titles'),
      '#default_value' => $profile && !empty($profile->job_titles) ? implode(', ', $profile->job_titles) : '',
      '#description' => $this->t('Enter target job titles separated by commas.'),
    ];

    $form['preferred_locations'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Preferred Locations'),
      '#default_value' => $profile && !empty($profile->preferred_locations) ? implode(', ', $profile->preferred_locations) : '',
      '#description' => $this->t('Enter preferred job locations separated by commas.'),
    ];

    $form['salary_expectation'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Salary Expectation'),
      '#default_value' => $profile ? $profile->salary_expectation : '',
      '#description' => $this->t('e.g., $100,000 - $150,000'),
    ];

    $form['availability'] = [
      '#type' => 'select',
      '#title' => $this->t('Availability'),
      '#options' => [
        'immediate' => $this->t('Immediate'),
        '2_weeks' => $this->t('2 Weeks'),
        '1_month' => $this->t('1 Month'),
        '2_months' => $this->t('2 Months'),
        '3_months_plus' => $this->t('3+ Months'),
      ],
      '#default_value' => $profile ? $profile->availability : 'immediate',
      '#empty_option' => $this->t('- Select -'),
    ];

    $form['linkedin_url'] = [
      '#type' => 'url',
      '#title' => $this->t('LinkedIn Profile'),
      '#default_value' => $profile ? $profile->linkedin_url : '',
      '#description' => $this->t('Your LinkedIn profile URL.'),
    ];

    $form['portfolio_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Portfolio/Website'),
      '#default_value' => $profile ? $profile->portfolio_url : '',
      '#description' => $this->t('Your portfolio or personal website URL.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $job_seeker_id ? $this->t('Update Profile') : $this->t('Create Profile'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('job_hunter.dashboard'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = [
      'uid' => $form_state->getValue('uid'),
      'resume_node_id' => $form_state->getValue('resume_node_id'),
      'skills' => $form_state->getValue('skills') ? array_map('trim', explode(',', $form_state->getValue('skills'))) : [],
      'experience_years' => $form_state->getValue('experience_years'),
      'job_titles' => $form_state->getValue('job_titles') ? array_map('trim', explode(',', $form_state->getValue('job_titles'))) : [],
      'preferred_locations' => $form_state->getValue('preferred_locations') ? array_map('trim', explode(',', $form_state->getValue('preferred_locations'))) : [],
      'salary_expectation' => $form_state->getValue('salary_expectation'),
      'availability' => $form_state->getValue('availability'),
      'linkedin_url' => $form_state->getValue('linkedin_url'),
      'portfolio_url' => $form_state->getValue('portfolio_url'),
    ];

    if ($this->jobSeekerId) {
      // Update existing profile
      $this->jobSeekerService->update($this->jobSeekerId, $values);
      $this->messenger()->addStatus($this->t('Job seeker profile has been updated.'));
    }
    else {
      // Create new profile
      $this->jobSeekerService->create($values);
      $this->messenger()->addStatus($this->t('Job seeker profile has been created.'));
    }

    $form_state->setRedirect('job_hunter.dashboard');
  }

}
