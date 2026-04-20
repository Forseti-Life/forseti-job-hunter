<?php

namespace Drupal\job_hunter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a form for creating job applications.
 */
class JobApplicationForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_hunter_job_application_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#prefix'] = '<div class="job-application-form">';
    $form['#suffix'] = '</div>';

    $form['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company Name'),
      '#description' => $this->t('Enter the name of the company you are applying to.'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['position_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Position Title'),
      '#description' => $this->t('Enter the job title or position you are applying for.'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['job_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Job Description'),
      '#description' => $this->t('Paste or enter the job description (optional).'),
      '#rows' => 5,
    ];

    $form['application_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Application Date'),
      '#description' => $this->t('When did you submit this application?'),
      '#required' => TRUE,
      '#default_value' => date('Y-m-d'),
    ];

    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Application Status'),
      '#description' => $this->t('Current status of this job application.'),
      '#options' => [
        'draft' => $this->t('Draft'),
        'submitted' => $this->t('Submitted'),
        'under_review' => $this->t('Under Review'),
        'interview_scheduled' => $this->t('Interview Scheduled'),
        'interview_completed' => $this->t('Interview Completed'),
        'offer_received' => $this->t('Offer Received'),
        'accepted' => $this->t('Accepted'),
        'rejected' => $this->t('Rejected'),
        'withdrawn' => $this->t('Withdrawn'),
      ],
      '#default_value' => 'draft',
      '#required' => TRUE,
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#description' => $this->t('Any additional notes about this application.'),
      '#rows' => 3,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Job Application'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('job_hunter.home'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // For now, just show a success message
    // In a full implementation, this would save to a custom entity or database table
    $values = $form_state->getValues();
    
    $this->messenger()->addMessage(
      $this->t('Job application for @company (@position) has been saved successfully.', [
        '@company' => $values['company_name'],
        '@position' => $values['position_title'],
      ])
    );

    // Log the submission for development purposes
    \Drupal::logger('job_hunter')->info(
      'Job application submitted: Company: @company, Position: @position, Status: @status',
      [
        '@company' => $values['company_name'],
        '@position' => $values['position_title'],
        '@status' => $values['status'],
      ]
    );

    // Redirect back to the home page
    $form_state->setRedirect('job_hunter.home');
  }

}