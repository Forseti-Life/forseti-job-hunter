<?php

namespace Drupal\job_hunter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides bulk actions form for job applications.
 */
class BulkActionsForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_hunter_bulk_actions';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    
    $form['header'] = [
      '#type' => 'markup',
      '#markup' => '<h2>Bulk Actions for Job Applications</h2>
                    <p>Select applications and perform actions on multiple items at once.</p>',
    ];

    // Application selection table (placeholder)
    $form['applications'] = [
      '#type' => 'tableselect',
      '#header' => [
        'id' => $this->t('ID'),
        'title' => $this->t('Position'),
        'company' => $this->t('Company'),
        'status' => $this->t('Status'),
        'date' => $this->t('Date Applied'),
      ],
      '#options' => [
        '1' => [
          'id' => '1',
          'title' => 'Senior Developer',
          'company' => 'Tech Corp',
          'status' => 'Active',
          'date' => date('Y-m-d'),
        ],
        '2' => [
          'id' => '2',
          'title' => 'Project Manager',
          'company' => 'Business Solutions Inc',
          'status' => 'Under Review',
          'date' => date('Y-m-d', strtotime('-2 days')),
        ],
        '3' => [
          'id' => '3',
          'title' => 'Integration Specialist',
          'company' => 'St. Louis Integration',
          'status' => 'Interview Scheduled',
          'date' => date('Y-m-d', strtotime('-5 days')),
        ],
      ],
      '#empty' => $this->t('No job applications found.'),
    ];

    // Bulk action selection
    $form['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Bulk Action'),
      '#required' => TRUE,
      '#options' => [
        'update_status' => $this->t('Update Status'),
        'send_followup' => $this->t('Send Follow-up Email'),
        'schedule_interview' => $this->t('Schedule Interview'),
        'archive' => $this->t('Archive Applications'),
        'delete' => $this->t('Delete Applications'),
      ],
      '#description' => $this->t('Choose the action to perform on selected applications.'),
    ];

    // Additional options based on action
    $form['status_value'] = [
      '#type' => 'select',
      '#title' => $this->t('New Status'),
      '#options' => [
        'active' => $this->t('Active'),
        'under_review' => $this->t('Under Review'),
        'interview_scheduled' => $this->t('Interview Scheduled'),
        'offered' => $this->t('Offer Made'),
        'rejected' => $this->t('Rejected'),
        'withdrawn' => $this->t('Withdrawn'),
      ],
      '#states' => [
        'visible' => [
          ':input[name="action"]' => ['value' => 'update_status'],
        ],
        'required' => [
          ':input[name="action"]' => ['value' => 'update_status'],
        ],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Execute Bulk Action'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $selected_applications = array_filter($form_state->getValue('applications'));
    
    if (empty($selected_applications)) {
      $form_state->setErrorByName('applications', $this->t('Please select at least one application.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected_applications = array_filter($form_state->getValue('applications'));
    $action = $form_state->getValue('action');
    $status_value = $form_state->getValue('status_value');
    
    $count = count($selected_applications);
    
    // Perform the bulk action (placeholder implementation)
    switch ($action) {
      case 'update_status':
        $this->messenger()->addStatus($this->t('Updated status to "@status" for @count applications.', [
          '@status' => $status_value,
          '@count' => $count,
        ]));
        break;
        
      case 'send_followup':
        $this->messenger()->addStatus($this->t('Sent follow-up emails for @count applications.', [
          '@count' => $count,
        ]));
        break;
        
      case 'schedule_interview':
        $this->messenger()->addStatus($this->t('Scheduled interviews for @count applications.', [
          '@count' => $count,
        ]));
        break;
        
      case 'archive':
        $this->messenger()->addStatus($this->t('Archived @count applications.', [
          '@count' => $count,
        ]));
        break;
        
      case 'delete':
        $this->messenger()->addStatus($this->t('Deleted @count applications.', [
          '@count' => $count,
        ]));
        break;
    }
    
    // Log the action
    \Drupal::logger('job_hunter')->info('Bulk action "@action" performed on @count applications by user @user.', [
      '@action' => $action,
      '@count' => $count,
      '@user' => $this->currentUser()->getAccountName(),
    ]);
  }

}