<?php

namespace Drupal\job_hunter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for pasting a job posting to extract and analyze.
 *
 * This is the entry point for the job tailoring workflow:
 * 1. User pastes job description text
 * 2. System extracts company and job details (future: AI parsing)
 * 3. System matches against user profile (future: match analysis)
 * 4. User can generate tailored resume (future: AI tailoring)
 */
class AddJobPostingForm extends FormBase {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new AddJobPostingForm.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
    $this->database = \Drupal::database();
    $this->currentUser = \Drupal::currentUser();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_hunter_add_job_posting_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'job_hunter/add-job-posting';

    // Instructions
    $form['instructions'] = [
      '#type' => 'markup',
      '#markup' => '<div class="job-posting-instructions">
        <h3>Add a Job Posting</h3>
        <p>Paste the complete job description below. The system will extract key information and help you tailor your resume to match.</p>
        <p><strong>Tip:</strong> Copy the entire job posting from LinkedIn, Indeed, or the company careers page for best results.</p>
      </div>',
    ];

    // Job URL (optional)
    $form['job_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Job URL (optional)'),
      '#description' => $this->t('Direct link to the job posting for reference'),
      '#maxlength' => 512,
      '#attributes' => [
        'placeholder' => 'https://linkedin.com/jobs/view/123456789',
      ],
    ];

    // Source platform
    $form['source_platform'] = [
      '#type' => 'select',
      '#title' => $this->t('Source Platform'),
      '#options' => [
        'linkedin' => $this->t('LinkedIn'),
        'indeed' => $this->t('Indeed'),
        'glassdoor' => $this->t('Glassdoor'),
        'company_site' => $this->t('Company Website'),
        'recruiter' => $this->t('Recruiter/Email'),
        'other' => $this->t('Other'),
      ],
      '#empty_option' => $this->t('- Select Source -'),
    ];

    // Main job posting textarea
    $form['raw_posting_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Job Posting Text'),
      '#description' => $this->t('Paste the complete job description here'),
      '#required' => TRUE,
      '#rows' => 20,
      '#attributes' => [
        'placeholder' => "VP of Engineering
Acme Healthcare - St. Louis, MO (Hybrid)

About the Role:
We are seeking a VP of Engineering to lead our technology team...

Requirements:
- 10+ years of engineering leadership experience
- Healthcare industry experience preferred
- Strong background in cloud architecture (AWS/Azure)
...

Benefits:
- Competitive salary and bonus
- Health insurance
- 401k matching
...",
      ],
    ];

    // Quick entry fields (optional - for manual entry if not pasting)
    $form['quick_entry'] = [
      '#type' => 'details',
      '#title' => $this->t('Quick Entry (Optional)'),
      '#description' => $this->t('If you know the basics, you can enter them here directly'),
      '#open' => FALSE,
    ];

    $form['quick_entry']['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company Name'),
      '#maxlength' => 255,
    ];

    $form['quick_entry']['job_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Job Title'),
      '#maxlength' => 255,
    ];

    $form['quick_entry']['location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Location'),
      '#maxlength' => 255,
      '#attributes' => [
        'placeholder' => 'St. Louis, MO',
      ],
    ];

    $form['quick_entry']['remote_option'] = [
      '#type' => 'select',
      '#title' => $this->t('Remote Option'),
      '#options' => [
        'remote' => $this->t('Remote'),
        'hybrid' => $this->t('Hybrid'),
        'onsite' => $this->t('On-site'),
      ],
      '#empty_option' => $this->t('- Not specified -'),
    ];

    // Actions
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Job Posting'),
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
    $timestamp = \Drupal::time()->getRequestTime();
    
    // Get or create company
    $company_name = trim($form_state->getValue('company_name') ?? '');
    $company_id = NULL;
    
    if (!empty($company_name)) {
      // Check if company exists
      $existing = $this->database->select('jobhunter_companies', 'c')
        ->fields('c', ['id'])
        ->condition('name', $company_name)
        ->execute()
        ->fetchField();
      
      if ($existing) {
        $company_id = $existing;
      }
      else {
        // Create new company
        $company_id = $this->database->insert('jobhunter_companies')
          ->fields([
            'name' => $company_name,
            'active' => 1,
            'created' => $timestamp,
            'updated' => $timestamp,
          ])
          ->execute();
        
        $this->messenger->addMessage($this->t('Company "@name" has been created.', [
          '@name' => $company_name,
        ]));
      }
    }

    // Prepare job data
    $job_title = trim($form_state->getValue('job_title') ?? '');
    if (empty($job_title)) {
      // Try to extract from first line of posting
      $raw_text = $form_state->getValue('raw_posting_text');
      $lines = explode("\n", $raw_text);
      $job_title = trim($lines[0] ?? 'Untitled Position');
    }

    $raw_posting_text = $form_state->getValue('raw_posting_text');
    
    $fields = [
      'company_id' => $company_id,
      'job_title' => $job_title,
      'job_description' => $raw_posting_text,
      'raw_posting_text' => $raw_posting_text,
      'location' => $form_state->getValue('location') ?? '',
      'remote_option' => $form_state->getValue('remote_option') ?? '',
      'job_url' => $form_state->getValue('job_url') ?? '',
      'source_platform' => $form_state->getValue('source_platform') ?? '',
      'ai_extraction_status' => 'pending',
      'status' => 'active',
      'created' => $timestamp,
      'updated' => $timestamp,
    ];

    // Insert job posting
    $job_id = $this->database->insert('jobhunter_job_requirements')
      ->fields($fields)
      ->execute();

    // Save to the user's saved jobs list.
    $uid = (int) $this->currentUser->id();
    if ($uid) {
      $this->database->insert('jobhunter_saved_jobs')
        ->fields([
          'uid' => $uid,
          'job_id' => $job_id,
          'created' => $timestamp,
          'updated' => $timestamp,
        ])
        ->execute();
    }

    // Queue for AI parsing if raw text provided
    if (!empty($raw_posting_text)) {
      $queue = \Drupal::queue('job_hunter_job_posting_parsing');
      $queue->createItem([
        'job_id' => $job_id,
        'raw_posting_text' => $raw_posting_text,
      ]);
      
      \Drupal::logger('job_hunter')->info('📋 Queued job posting @id for AI parsing', ['@id' => $job_id]);
      
      $this->messenger->addMessage($this->t('Job posting "@title" has been saved and queued for AI parsing. Job ID: @id', [
        '@title' => $job_title,
        '@id' => $job_id,
      ]));
    }
    else {
      $this->messenger->addMessage($this->t('Job posting "@title" has been saved. Job ID: @id', [
        '@title' => $job_title,
        '@id' => $job_id,
      ]));
    }

    // TODO: In the future, redirect to the extraction review page
    // $form_state->setRedirect('job_hunter.job_review', ['job_id' => $job_id]);
    
    // For now, redirect to jobs list
    $form_state->setRedirect('job_hunter.my_jobs');
  }

}
