<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for company and job requirement management.
 */
class CompanyController extends ControllerBase {
  use JobHunterControllerTrait;

  /**
   * Valid job status values.
   */
  const VALID_JOB_STATUSES = ['active', 'archived', 'applied', 'interviewing', 'rejected', 'offered'];

  /**
   * Valid AI extraction status values.
   */
  const VALID_AI_STATUSES = ['pending', 'queued', 'processing', 'completed', 'failed'];

  /**
   * Valid tailoring status values.
   */
  const VALID_TAILORING_STATUSES = ['pending', 'queued', 'processing', 'completed', 'failed'];

  /**
   * Valid interview round types.
   */
  const INTERVIEW_ROUND_TYPES = ['phone-screen', 'technical', 'behavioral', 'final', 'other'];

  /**
   * Valid interview round outcomes.
   */
  const INTERVIEW_ROUND_OUTCOMES = ['pending', 'passed', 'failed', 'withdrawn'];

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
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Constructs a CompanyController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   */
  public function __construct(Connection $database, AccountProxyInterface $current_user, RequestStack $request_stack, FormBuilderInterface $form_builder) {
    $this->database = $database;
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('form_builder')
    );
  }

  /**
   * Safely decode JSON with error handling and logging.
   *
   * @param string|null $json
   *   The JSON string to decode.
   * @param string $context
   *   Context for logging (e.g., 'job requirements', 'tailored resume').
   * @param int|null $id
   *   Optional ID for logging context.
   *
   * @return array|null
   *   The decoded array or NULL on failure.
   */
  protected function safeJsonDecode($json, $context = 'data', $id = NULL) {
    if (empty($json)) {
      return NULL;
    }

    $decoded = json_decode($json, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $log_params = ['@context' => $context, '@error' => json_last_error_msg()];
      if ($id !== NULL) {
        $log_params['@id'] = $id;
        $this->getLogger('job_hunter')->warning(
          'Invalid JSON in @context @id: @error',
          $log_params
        );
      }
      else {
        $this->getLogger('job_hunter')->warning(
          'Invalid JSON in @context: @error',
          $log_params
        );
      }
      return NULL;
    }

    return $decoded;
  }

  /**
   * Load the saved-job row owned by the current user for a job requirement.
   */
  private function loadOwnedSavedJob(int $uid, int $job_id): ?object {
    $saved_job = $this->database->select('jobhunter_saved_jobs', 'sj')
      ->fields('sj', ['id', 'deadline_date', 'follow_up_date'])
      ->condition('sj.uid', $uid)
      ->condition('sj.job_id', $job_id)
      ->execute()
      ->fetchObject();

    return $saved_job ?: NULL;
  }

  /**
   * Load interview rounds for the current user and saved job in chronological order.
   *
   * @return object[]
   *   Interview round rows.
   */
  private function loadInterviewRounds(int $uid, int $saved_job_id): array {
    if (!$this->database->schema()->tableExists('jobhunter_interview_rounds')) {
      return [];
    }

    return $this->database->select('jobhunter_interview_rounds', 'ir')
      ->fields('ir', ['id', 'round_type', 'outcome', 'conducted_date', 'notes'])
      ->condition('ir.uid', $uid)
      ->condition('ir.saved_job_id', $saved_job_id)
      ->orderBy('ir.conducted_date', 'ASC')
      ->orderBy('ir.id', 'ASC')
      ->execute()
      ->fetchAll();
  }

  /**
   * Return a human-friendly label for an interview round type.
   */
  private function getInterviewRoundTypeLabel(string $round_type): string {
    $labels = [
      'phone-screen' => 'Phone Screen',
      'technical' => 'Technical',
      'behavioral' => 'Behavioral',
      'final' => 'Final',
      'other' => 'Other',
    ];

    return $labels[$round_type] ?? ucwords(str_replace('-', ' ', $round_type));
  }

  /**
   * Return label and CSS class metadata for an interview outcome.
   *
   * @return array{label: string, class: string}
   *   Outcome display metadata.
   */
  private function getInterviewOutcomeMeta(string $outcome): array {
    $meta = [
      'pending' => ['label' => 'Pending', 'class' => 'outcome-pending'],
      'passed' => ['label' => 'Passed', 'class' => 'outcome-passed'],
      'failed' => ['label' => 'Failed', 'class' => 'outcome-failed'],
      'withdrawn' => ['label' => 'Withdrawn', 'class' => 'outcome-withdrawn'],
    ];

    return $meta[$outcome] ?? ['label' => ucwords(str_replace('-', ' ', $outcome)), 'class' => 'outcome-neutral'];
  }

  /**
   * Render the interview round log body markup.
   */
  private function buildInterviewRoundsLogHtml(array $rounds): string {
    if (empty($rounds)) {
      return '<p class="interview-rounds-empty">No interview rounds logged yet.</p>';
    }

    $rows_html = '';
    foreach ($rounds as $round) {
      $outcome_meta = $this->getInterviewOutcomeMeta((string) $round->outcome);
      $notes = trim((string) ($round->notes ?? ''));
      $notes_display = $notes !== '' ? htmlspecialchars($notes) : '&mdash;';
      if ($notes !== '' && mb_strlen($notes) > 180) {
        $notes_display = htmlspecialchars(mb_substr($notes, 0, 177) . '...');
      }

      $rows_html .= '<tr data-round-id="' . (int) $round->id . '">'
        . '<td>' . htmlspecialchars($this->getInterviewRoundTypeLabel((string) $round->round_type)) . '</td>'
        . '<td><span class="interview-outcome-badge ' . htmlspecialchars($outcome_meta['class']) . '">' . htmlspecialchars($outcome_meta['label']) . '</span></td>'
        . '<td>' . htmlspecialchars((string) $round->conducted_date) . '</td>'
        . '<td>' . $notes_display . '</td>'
        . '<td><button type="button" class="button button--small button--secondary btn-interview-round-edit"'
        . ' data-round-id="' . (int) $round->id . '"'
        . ' data-round-type="' . htmlspecialchars((string) $round->round_type, ENT_QUOTES) . '"'
        . ' data-outcome="' . htmlspecialchars((string) $round->outcome, ENT_QUOTES) . '"'
        . ' data-conducted-date="' . htmlspecialchars((string) $round->conducted_date, ENT_QUOTES) . '"'
        . ' data-notes="' . htmlspecialchars($notes, ENT_QUOTES) . '">'
        . 'Edit</button></td>'
        . '</tr>';
    }

    return '<table class="interview-rounds-table">'
      . '<thead><tr><th>Round</th><th>Outcome</th><th>Date</th><th>Notes</th><th>Actions</th></tr></thead>'
      . '<tbody>' . $rows_html . '</tbody>'
      . '</table>';
  }

  /**
   * List all companies.
   * 
   * Note: The fields() call and groupBy() calls must be kept in sync.
   * If you add or remove fields, update both locations.
   */
  public function listCompanies() {
    try {
      // Get all companies with job count in a single query (fixed N+1 issue)
      $query = $this->database->select('jobhunter_companies', 'c');
      // Note: Fields listed here must match the GROUP BY clauses below
      $query->fields('c', ['id', 'name', 'industry', 'location', 'active']);
      $query->leftJoin('jobhunter_job_requirements', 'j', 'c.id = j.company_id');
      $query->addExpression('COUNT(j.id)', 'job_count');
      // GROUP BY all non-aggregated fields from the SELECT clause
      $query->groupBy('c.id');
      $query->groupBy('c.name');
      $query->groupBy('c.industry');
      $query->groupBy('c.location');
      $query->groupBy('c.active');
      $query->orderBy('c.name', 'ASC');
      $companies = $query->execute()->fetchAll();
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Unable to load companies. Please try again.'));
      $this->getLogger('job_hunter')->error('Failed to load companies: @error', ['@error' => $e->getMessage()]);
      $companies = [];
    }
    
    // Build table
    $header = [
      $this->t('Company'),
      $this->t('Industry'),
      $this->t('Location'),
      $this->t('Active'),
      $this->t('Jobs'),
      $this->t('Actions'),
    ];
    
    $rows = [];
    foreach ($companies as $company) {
      $job_count = $company->job_count ?? 0;
      
      $rows[] = [
        $company->name,
        $company->industry ?? $this->t('N/A'),
        $company->location ?? $this->t('N/A'),
        $company->active ? $this->t('Yes') : $this->t('No'),
        $job_count,
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => Url::fromRoute('job_hunter.company_edit', ['company_id' => $company->id]),
              ],
              'add_job' => [
                'title' => $this->t('Add Job'),
                'url' => Url::fromRoute('job_hunter.job_paste'),
              ],
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => Url::fromRoute('job_hunter.company_delete', ['company_id' => $company->id]),
              ],
            ],
          ],
        ],
      ];
    }
    
    $content = [
      'header' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Companies'),
      ],
      'add_button' => [
        '#type' => 'link',
        '#title' => $this->t('Add Company'),
        '#url' => Url::fromRoute('job_hunter.bulk_import_companies'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No companies found. Click "Add Company" to add your first target company.'),
        '#attributes' => ['class' => ['companies-table']],
      ],
    ];
    
    return $this->wrapWithNavigation($content);
  }

  /**
   * Delete a company.
   */
  public function deleteCompany($company_id) {
    try {
      // Delete all jobs for this company first
      $this->database->delete('jobhunter_job_requirements')
        ->condition('company_id', $company_id)
        ->execute();
      
      // Delete the company
      $this->database->delete('jobhunter_companies')
        ->condition('id', $company_id)
        ->execute();
      
      $this->messenger()->addMessage($this->t('Company and all associated jobs have been deleted.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to delete company. Please try again.'));
      $this->getLogger('job_hunter')->error('Failed to delete company @id: @error', [
        '@id' => $company_id,
        '@error' => $e->getMessage(),
      ]);
    }
    
    return new RedirectResponse(Url::fromRoute('job_hunter.companies_list')->toString());
  }

  /**
   * List all job requirements.
   */
  public function listJobs() {
    $current_user_id = $this->currentUser->id();
    $request = $this->requestStack->getCurrentRequest();
    
    // Get filter parameters with validation
    $filter_company = $request->query->get('company', '');
    $filter_status = $request->query->get('status', '');
    $filter_ai_status = $request->query->get('ai_status', '');
    $filter_tailoring = $request->query->get('tailoring', '');
    
    // Validate filter values using class constants
    if ($filter_status && !in_array($filter_status, self::VALID_JOB_STATUSES)) {
      $filter_status = '';
    }
    if ($filter_ai_status && !in_array($filter_ai_status, self::VALID_AI_STATUSES)) {
      $filter_ai_status = '';
    }
    if ($filter_tailoring && !in_array($filter_tailoring, self::VALID_TAILORING_STATUSES)) {
      $filter_tailoring = '';
    }
    
    try {
      // Get all jobs with company names and tailoring status
      $query = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j');
      $query->leftJoin('jobhunter_companies', 'c', 'j.company_id = c.id');
      $query->addField('c', 'name', 'company_name');
      // Join tailored resumes for current user
      $query->leftJoin('jobhunter_tailored_resumes', 'tr', 'j.id = tr.job_id AND tr.uid = :uid', [':uid' => $current_user_id]);
      $query->addField('tr', 'tailoring_status');
      $query->addField('tr', 'tailored_resume_json');
      $query->addField('tr', 'pdf_path');
      // Join application records for current user.
      $query->leftJoin('jobhunter_applications', 'app', 'j.id = app.job_id AND app.uid = :app_uid', [':app_uid' => $current_user_id]);
      $query->addField('app', 'submission_status', 'application_status');
      if ($this->database->schema()->fieldExists('jobhunter_applications', 'ats_platform')) {
        $query->addField('app', 'ats_platform', 'application_ats');
      }
      else {
        $query->addExpression("''", 'application_ats');
      }
      $query->addField('app', 'automation_success', 'application_automation_success');
      
      // Apply filters
      if (!empty($filter_company)) {
        $query->condition('c.name', '%' . $this->database->escapeLike($filter_company) . '%', 'LIKE');
      }
      if (!empty($filter_status)) {
        $query->condition('j.status', $filter_status);
      }
      if (!empty($filter_ai_status)) {
        $query->condition('j.ai_extraction_status', $filter_ai_status);
      }
      if (!empty($filter_tailoring)) {
        $query->condition('tr.tailoring_status', $filter_tailoring);
      }
      
      $query->orderBy('c.name', 'ASC');
      $query->orderBy('j.job_title', 'ASC');
      $jobs = $query->execute()->fetchAll();
      
      // Get distinct companies for filter dropdown
      $companies_query = $this->database->select('jobhunter_companies', 'c')
        ->fields('c', ['name'])
        ->distinct()
        ->orderBy('name', 'ASC');
      $companies = $companies_query->execute()->fetchCol();
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Unable to load jobs. Please try again.'));
      $this->getLogger('job_hunter')->error('Failed to load jobs: @error', ['@error' => $e->getMessage()]);
      $jobs = [];
      $companies = [];
    }
    
    // Build table
    $header = [
      $this->t('Job Title'),
      $this->t('Company'),
      $this->t('Status'),
      $this->t('AI Parsed'),
      $this->t('Tailored'),
      $this->t('Actions'),
    ];
    
    $rows = [];
    foreach ($jobs as $job) {
      // Parse extracted JSON for better title display using helper method
      $extracted = $this->safeJsonDecode($job->extracted_json, 'job requirements', $job->id);
      // Use extracted title if available, fall back to job_title, then to a default
      $job_title = $extracted['job_title'] ?? ($job->job_title ?? 'Job #' . $job->id);
      $company_name = $extracted['company_name'] ?? ($job->company_name ?? 'Unknown');
      
      // Determine AI parsing status
      $has_raw_text = !empty($job->raw_posting_text);
      $has_extracted = !empty($job->extracted_json);
      $ai_status = $job->ai_extraction_status ?? 'pending';
      
      if ($has_extracted) {
        $ai_badge = '<span class="badge badge--success" title="AI parsing complete">✅ Parsed</span>';
      } elseif ($ai_status === 'processing' || $ai_status === 'queued') {
        $ai_badge = '<span class="badge badge--warning" title="AI parsing in progress">⏳ Processing</span>';
      } elseif ($ai_status === 'failed') {
        $ai_badge = '<span class="badge badge--error" title="AI parsing failed">❌ Failed</span>';
      } elseif ($has_raw_text) {
        $ai_badge = '<span class="badge badge--info" title="Has raw text, needs AI parsing">📝 Needs Parsing</span>';
      } else {
        $ai_badge = '<span class="badge badge--neutral" title="No content yet">⚪ No Content</span>';
      }
      
      // Determine tailoring status
      $tailoring_status = $job->tailoring_status ?? NULL;
      $has_tailored_json = !empty($job->tailored_resume_json);
      $has_pdf = !empty($job->pdf_path);
      
      if ($tailoring_status === 'completed' && $has_tailored_json) {
        if ($has_pdf) {
          $tailor_badge = '<span class="badge badge--success" title="Tailored with PDF ready">✅ PDF Ready</span>';
        } else {
          $tailor_badge = '<span class="badge badge--success" title="Resume tailored, generate PDF">✅ Tailored</span>';
        }
      } elseif ($tailoring_status === 'processing' || $tailoring_status === 'queued') {
        $tailor_badge = '<span class="badge badge--warning" title="Tailoring in progress">⏳ Processing</span>';
      } elseif ($tailoring_status === 'failed') {
        $tailor_badge = '<span class="badge badge--error" title="Tailoring failed">❌ Failed</span>';
      } else {
        $tailor_badge = '<span class="badge badge--neutral" title="Not yet tailored">⚪ Not Tailored</span>';
      }
      
      // Build action links
      $tailor_link = [
        '#type' => 'link',
        '#title' => $tailoring_status === 'completed' ? $this->t('View/Edit') : $this->t('Tailor'),
        '#url' => Url::fromRoute('job_hunter.tailor_resume', ['job' => $job->id]),
        '#attributes' => ['class' => ['button', 'button--small', $tailoring_status === 'completed' ? 'button--secondary' : 'button--primary']],
      ];
      
      $rows[] = [
        [
          'data' => [
            '#type' => 'link',
            '#title' => $job_title,
            '#url' => Url::fromRoute('job_hunter.job_view', ['job_id' => $job->id]),
          ],
        ],
        ['data' => ['#markup' => $company_name]],
        ['data' => ['#markup' => ucfirst($job->status ?: 'active')]],
        ['data' => ['#markup' => $ai_badge]],
        ['data' => ['#markup' => $tailor_badge]],
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'tailor' => [
                'title' => $tailoring_status === 'completed' ? $this->t('View Tailored') : $this->t('Tailor Resume'),
                'url' => Url::fromRoute('job_hunter.tailor_resume', ['job' => $job->id]),
              ],
              'view' => [
                'title' => $this->t('View Job'),
                'url' => Url::fromRoute('job_hunter.job_view', ['job_id' => $job->id]),
              ],
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => Url::fromRoute('job_hunter.job_edit', ['job_id' => $job->id]),
              ],
            ] + ($ai_status === 'failed' && $has_raw_text ? [
              'retry_parsing' => [
                'title' => $this->t('Retry Parsing'),
                'url' => Url::fromRoute('job_hunter.job_retry_parsing', ['job_id' => $job->id]),
                'attributes' => [
                  'class' => ['button--warning'],
                ],
              ],
            ] : []) + [
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => Url::fromRoute('job_hunter.job_delete', ['job_id' => $job->id]),
              ],
            ],
          ],
        ],
      ];
    }
    
    $content = [
      'header' => [
        '#markup' => '<h2>' . $this->t('Job Requirements') . '</h2>',
      ],
      'add_button' => [
        '#type' => 'link',
        '#title' => $this->t('Add Job Posting'),
        '#url' => Url::fromRoute('job_hunter.job_paste'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'filters' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['jobs-filters']],
        'form' => [
          '#type' => 'inline_template',
          '#template' => '
            <div class="filter-form">
              <form method="get" action="{{ action_url }}">
                <div class="filter-row">
                  <div class="filter-field">
                    <label for="company">{{ "Company"|t }}</label>
                    <select name="company" id="company">
                      <option value="">{{ "All Companies"|t }}</option>
                      {% for company in companies %}
                        <option value="{{ company }}"{{ company == filter_company ? " selected" : "" }}>{{ company }}</option>
                      {% endfor %}
                    </select>
                  </div>
                  <div class="filter-field">
                    <label for="status">{{ "Status"|t }}</label>
                    <select name="status" id="status">
                      <option value="">{{ "All Statuses"|t }}</option>
                      <option value="active"{{ filter_status == "active" ? " selected" : "" }}>{{ "Active"|t }}</option>
                      <option value="archived"{{ filter_status == "archived" ? " selected" : "" }}>{{ "Archived"|t }}</option>
                      <option value="applied"{{ filter_status == "applied" ? " selected" : "" }}>{{ "Applied"|t }}</option>
                    </select>
                  </div>
                  <div class="filter-field">
                    <label for="ai_status">{{ "AI Status"|t }}</label>
                    <select name="ai_status" id="ai_status">
                      <option value="">{{ "All AI Statuses"|t }}</option>
                      <option value="completed"{{ filter_ai_status == "completed" ? " selected" : "" }}>{{ "Parsed"|t }}</option>
                      <option value="pending"{{ filter_ai_status == "pending" ? " selected" : "" }}>{{ "Needs Parsing"|t }}</option>
                      <option value="processing"{{ filter_ai_status == "processing" ? " selected" : "" }}>{{ "Processing"|t }}</option>
                      <option value="failed"{{ filter_ai_status == "failed" ? " selected" : "" }}>{{ "Failed"|t }}</option>
                    </select>
                  </div>
                  <div class="filter-field">
                    <label for="tailoring">{{ "Tailoring"|t }}</label>
                    <select name="tailoring" id="tailoring">
                      <option value="">{{ "All Tailoring Statuses"|t }}</option>
                      <option value="completed"{{ filter_tailoring == "completed" ? " selected" : "" }}>{{ "Tailored"|t }}</option>
                      <option value="pending"{{ filter_tailoring == "pending" ? " selected" : "" }}>{{ "Not Tailored"|t }}</option>
                      <option value="processing"{{ filter_tailoring == "processing" ? " selected" : "" }}>{{ "Processing"|t }}</option>
                      <option value="failed"{{ filter_tailoring == "failed" ? " selected" : "" }}>{{ "Failed"|t }}</option>
                    </select>
                  </div>
                  <div class="filter-actions">
                    <button type="submit" class="button button--primary">{{ "Filter"|t }}</button>
                    <a href="{{ action_url }}" class="button button--secondary">{{ "Clear"|t }}</a>
                  </div>
                </div>
              </form>
            </div>',
          '#context' => [
            'action_url' => Url::fromRoute('job_hunter.my_jobs')->toString(),
            'companies' => $companies,
            'filter_company' => $filter_company,
            'filter_status' => $filter_status,
            'filter_ai_status' => $filter_ai_status,
            'filter_tailoring' => $filter_tailoring,
          ],
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No job requirements found. Click "Add Job Requirement" to add your first job.'),
        '#attributes' => ['class' => ['jobs-table']],
      ],
    ];
    
    // Add CSS for filters
    $content['#attached']['html_head'][] = [
      [
        '#tag' => 'style',
        '#value' => '
          .jobs-filters { margin: 20px 0; }
          .filter-form { background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #e5e7eb; }
          .filter-row { display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
          .filter-field { display: flex; flex-direction: column; flex: 1; min-width: 150px; }
          .filter-field label { font-weight: 600; margin-bottom: 5px; color: #374151; font-size: 14px; }
          .filter-field select { padding: 8px 12px; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 14px; background: white; }
          .filter-field select:focus { outline: none; border-color: #667eea; }
          .filter-actions { display: flex; gap: 10px; align-items: center; }
          .filter-actions .button { margin: 0; }
        ',
      ],
      'jobs_filters_styles',
    ];
    
    return $this->wrapWithNavigation($content);
  }

  /**
   * Delete a job requirement.
   */
  public function deleteJob($job_id) {
    $request = $this->requestStack->getCurrentRequest();
    $return_to = (string) $request->query->get('return_to', '/jobhunter/my-jobs');
    if (!preg_match('/^\/(?!\/)/', $return_to)) {
      $return_to = '/jobhunter/my-jobs';
    }

    try {
      // Remove only this user's saved-job mapping.
      $this->database->delete('jobhunter_saved_jobs')
        ->condition('uid', (int) $this->currentUser->id())
        ->condition('job_id', (int) $job_id)
        ->execute();

      $this->messenger()->addMessage($this->t('Job removed from My Jobs.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to remove job. Please try again.'));
      $this->getLogger('job_hunter')->error('Failed to remove saved-job mapping for job @id: @error', [
        '@id' => $job_id,
        '@error' => $e->getMessage(),
      ]);
    }
    
    return new RedirectResponse($return_to);
  }

  /**
   * View a job requirement with all extracted data.
   */
  public function viewJob($job_id) {
    try {
      // Load the job
      $job = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j')
        ->condition('id', $job_id)
        ->execute()
        ->fetchObject();
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Unable to load job. Please try again.'));
      $this->getLogger('job_hunter')->error('Failed to load job @id: @error', [
        '@id' => $job_id,
        '@error' => $e->getMessage(),
      ]);
      return new RedirectResponse(Url::fromRoute('job_hunter.my_jobs')->toString());
    }
    
    if (!$job) {
      $this->messenger()->addError($this->t('Job not found.'));
      return new RedirectResponse(Url::fromRoute('job_hunter.my_jobs')->toString());
    }

    $jobValue = static function (object $row, string $field) {
      return property_exists($row, $field) ? $row->{$field} : NULL;
    };
    
    // Parse JSON data using helper method
    $extracted = $this->safeJsonDecode($jobValue($job, 'extracted_json'), 'job extracted data', $job_id);
    $skills = $this->safeJsonDecode($jobValue($job, 'skills_required_json'), 'job skills', $job_id);
    $duplicates = $this->safeJsonDecode($jobValue($job, 'potential_duplicates_json'), 'potential duplicates', $job_id) ?? [];
    
    // Build the content
    $content = [];
    
    // Show duplicate warning if found
    if (!empty($duplicates)) {
      $exact_match = array_filter($duplicates, fn($d) => $d['is_exact_match'] ?? FALSE);
      
      if (!empty($exact_match)) {
        $match = reset($exact_match);
        $content['duplicate_exact'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['messages', 'messages--error', 'duplicate-warning']],
          '#markup' => '<strong>⚠️ Exact Duplicate Found!</strong><br>' .
            'This job appears to be identical to: <a href="' . 
            Url::fromRoute('job_hunter.job_view', ['job_id' => $match['job_id']])->toString() . 
            '"><strong>' . htmlspecialchars($match['job_title']) . '</strong> at ' . 
            htmlspecialchars($match['company']) . ' (Job #' . $match['job_id'] . ')</a>',
        ];
      }
      else {
        $links = [];
        foreach ($duplicates as $dup) {
          $links[] = '<a href="' . 
            Url::fromRoute('job_hunter.job_view', ['job_id' => $dup['job_id']])->toString() . 
            '">' . htmlspecialchars($dup['job_title']) . ' at ' . 
            htmlspecialchars($dup['company']) . ' (' . $dup['similarity_score'] . '% match)</a>';
        }
        $content['duplicate_warning'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['messages', 'messages--warning', 'duplicate-warning']],
          '#markup' => '<strong>📋 Potential Duplicates Found</strong><br>' .
            'This job may be similar to:<br><ul><li>' . implode('</li><li>', $links) . '</li></ul>',
        ];
      }
    }
    
    // Check if user has a tailored resume for this job
    $current_user = $this->currentUser;
    $tailored_resume = $this->database->select('jobhunter_tailored_resumes', 'tr')
      ->fields('tr', ['id', 'tailoring_status', 'pdf_path'])
      ->condition('uid', $current_user->id())
      ->condition('job_id', $job_id)
      ->execute()
      ->fetchObject();

    // Check existing application record (schema-safe across environments).
    $existing_application_query = $this->database->select('jobhunter_applications', 'a')
      ->fields('a', ['id', 'submission_status', 'apply_url', 'selected_apply_option', 'attempt_count', 'confirmation_reference', 'submission_date', 'automation_success', 'admin_review_required'])
      ->condition('uid', $current_user->id())
      ->condition('job_id', $job_id)
      ->orderBy('created', 'DESC')
      ->range(0, 1);
    if ($this->database->schema()->fieldExists('jobhunter_applications', 'ats_platform')) {
      $existing_application_query->addField('a', 'ats_platform');
    }
    else {
      $existing_application_query->addExpression("''", 'ats_platform');
    }
    $existing_application = $existing_application_query
      ->execute()
      ->fetchAssoc();

    // Header with edit link
    $raw_title = $jobValue($job, 'job_title');
    $display_title = $extracted['job_title']
      ?? ($raw_title ?: 'Job Requisition #' . $job_id);

    $raw_company = '';
    $company_id = $jobValue($job, 'company_id');
    if ($company_id) {
      $company_node = \Drupal::entityTypeManager()->getStorage('node')->load($company_id);
      if ($company_node) {
        $raw_company = $company_node->getTitle();
      }
    }
    $display_company = $extracted
      ? (($extracted['company_name'] ?? '') . (!empty($extracted['industry']) ? ' — ' . $extracted['industry'] : ''))
      : $raw_company;

    $saved_job = $this->database->select('jobhunter_saved_jobs', 'sj')
      ->fields('sj', ['id', 'archived'])
      ->condition('sj.uid', $current_user->id())
      ->condition('sj.job_id', $job_id)
      ->execute()
      ->fetchObject();
    $saved_job_is_archived = $saved_job ? (int) ($saved_job->archived ?? 0) === 1 : FALSE;
    $job_detail_return_to = Url::fromRoute('job_hunter.job_view', ['job_id' => $job_id])->toString();

    // Build Apply button HTML — AJAX-powered, no page refresh.
    $apply_url_route = Url::fromRoute('job_hunter.job_apply', ['job_id' => $job_id])->toString();
    $status_url_route = Url::fromRoute('job_hunter.application_status', ['job_id' => $job_id])->toString();
    $csrf_token = \Drupal::csrfToken()->get('jobhunter/jobs/' . (int) $job_id . '/apply');

    if ($existing_application) {
      $app_status = $existing_application['submission_status'];
      $status_labels = [
        'pending'         => ['label' => '⏳ Application Pending', 'class' => 'btn-warning'],
        'processing'      => ['label' => '⚙️ Submitting...', 'class' => 'btn-warning'],
        'submitted'       => ['label' => '✅ Applied', 'class' => 'btn-success'],
        'failed'          => ['label' => '❌ Failed — Retry', 'class' => 'btn-danger'],
        'manual_required' => ['label' => '📋 Apply Manually', 'class' => 'btn-secondary'],
      ];
      $btn_info = $status_labels[$app_status] ?? ['label' => '📤 Apply', 'class' => 'button--primary'];
      $apply_button_html = '<button class="button ' . $btn_info['class'] . ' btn-apply-job" data-job-id="' . $job_id . '" data-apply-url="' . $apply_url_route . '" data-status-url="' . $status_url_route . '" data-token="' . $csrf_token . '">' . $btn_info['label'] . '</button>';
    } else {
      $apply_button_html = '<button class="button button--primary btn-apply-job" data-job-id="' . $job_id . '" data-apply-url="' . $apply_url_route . '" data-status-url="' . $status_url_route . '" data-token="' . $csrf_token . '">📤 Apply</button>';
    }

    $content['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['job-view-header']],
      'title' => [
        '#markup' => '<h2>' . htmlspecialchars($display_title) . '</h2>',
      ],
      'company' => [
        '#markup' => $display_company ? '<p class="job-company"><strong>' . htmlspecialchars($display_company) . '</strong></p>' : '',
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['job-actions']],
        'edit' => [
          '#type' => 'link',
          '#title' => $this->t('Edit'),
          '#url' => Url::fromRoute('job_hunter.job_edit', ['job_id' => $job_id]),
          '#attributes' => ['class' => ['button']],
        ],
        'tailor' => [
          '#type' => 'link',
          '#title' => $this->t('Tailor My Resume'),
          '#url' => Url::fromRoute('job_hunter.tailor_resume', ['job' => $job_id]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
        'apply' => [
          '#markup' => $apply_button_html,
        ],
      ],
    ];

    if ($saved_job) {
      $content['header']['actions']['archive'] = [
        '#type' => 'link',
        '#title' => $saved_job_is_archived ? $this->t('Restore') : $this->t('Archive'),
        '#url' => Url::fromRoute($saved_job_is_archived ? 'job_hunter.job_unarchive' : 'job_hunter.job_archive', ['job_id' => $job_id], [
          'query' => ['return_to' => $job_detail_return_to],
        ]),
        '#attributes' => [
          'class' => $saved_job_is_archived ? ['button', 'button--secondary'] : ['button', 'button--danger'],
        ],
      ];
    }

    // Application status panel (shown when application exists).
    if ($existing_application) {
      $app = $existing_application;
      $status_classes = [
        'pending'         => 'status-pending',
        'processing'      => 'status-processing',
        'submitted'       => 'status-completed',
        'failed'          => 'status-failed',
        'manual_required' => 'status-neutral',
      ];
      $status_class = $status_classes[$app['submission_status']] ?? 'status-neutral';
      $status_display = ucwords(str_replace('_', ' ', $app['submission_status']));

      $app_info_parts = [
        '<strong>Status:</strong> <span class="' . $status_class . '">' . htmlspecialchars($status_display) . '</span>',
      ];
      if (!empty($app['ats_platform'])) {
        $app_info_parts[] = '<strong>ATS Platform:</strong> ' . htmlspecialchars(ucfirst($app['ats_platform']));
      }
      if (!empty($app['selected_apply_option'])) {
        $app_info_parts[] = '<strong>Apply Via:</strong> ' . htmlspecialchars($app['selected_apply_option']);
      }
      if (!empty($app['apply_url'])) {
        $app_info_parts[] = '<strong>Apply URL:</strong> <a href="' . htmlspecialchars($app['apply_url']) . '" target="_blank" rel="noopener">' . htmlspecialchars($app['apply_url']) . ' ↗</a>';
      }
      if (!empty($app['confirmation_reference'])) {
        $app_info_parts[] = '<strong>Confirmation:</strong> ' . htmlspecialchars($app['confirmation_reference']);
      }
      if (!empty($app['submission_date'])) {
        $app_info_parts[] = '<strong>Submitted:</strong> ' . htmlspecialchars($app['submission_date']);
      }
      if (!empty($app['attempt_count'])) {
        $app_info_parts[] = '<strong>Attempts:</strong> ' . (int) $app['attempt_count'];
      }

      $content['application_status'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['job-application-status', 'job-info-box']],
        '#markup' => '<h4>📋 Application Status</h4><div class="app-status-details">' . implode('<br>', $app_info_parts) . '</div>',
        '#cache' => ['contexts' => ['user']],
      ];
    }

    // Job source information and links
    $source_info = [];
    $job_url = $jobValue($job, 'job_url');
    $application_url = $jobValue($job, 'application_url');
    $original_url = !empty($job_url) ? $job_url : ($application_url ?? '');
    if (!empty($original_url)) {
      $source_info[] = '<strong>Job URL:</strong> <a href="' . htmlspecialchars($original_url) . '" target="_blank" rel="noopener">' . htmlspecialchars($original_url) . ' ↗</a>';
    }
    if (!empty($jobValue($job, 'external_source'))) {
      $source_info[] = '<strong>Source:</strong> ' . htmlspecialchars((string) $jobValue($job, 'external_source'));
    }
    if (!empty($jobValue($job, 'external_job_id'))) {
      $source_info[] = '<strong>External Job ID:</strong> ' . htmlspecialchars((string) $jobValue($job, 'external_job_id'));
    }
    if (!empty($jobValue($job, 'source_platform'))) {
      $source_info[] = '<strong>Platform:</strong> ' . htmlspecialchars((string) $jobValue($job, 'source_platform'));
    }
    if ($tailored_resume) {
      $status_text = ucfirst($tailored_resume->tailoring_status);
      $status_class = match($tailored_resume->tailoring_status) {
        'completed' => 'status-completed',
        'pending' => 'status-pending',
        'queued' => 'status-queued',
        'processing' => 'status-processing',
        'failed' => 'status-failed',
        default => 'status-unknown',
      };
      $source_info[] = '<strong>Tailored Resume:</strong> <a href="' . Url::fromRoute('job_hunter.tailor_resume', ['job' => $job_id])->toString() . '">View/Edit Tailored Resume</a> <span class="' . $status_class . '">(' . $status_text . ')</span>';
      if ($tailored_resume->tailoring_status === 'completed' && !empty($tailored_resume->pdf_path)) {
        $source_info[] = '<strong>Resume PDF:</strong> <a href="' . Url::fromRoute('job_hunter.download_tailored_resume_pdf', ['job_id' => $job_id])->toString() . '" target="_blank">Download PDF ↗</a>';
      }
    }
    
    if (!empty($source_info)) {
      $content['source_info'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['job-source-info']],
        '#markup' => '<div class="job-info-box"><ul class="job-meta-list"><li>' . implode('</li><li>', $source_info) . '</li></ul></div>',
      ];
    }

    // AI extraction status notice when parsing is not yet complete.
    $ai_status = $jobValue($job, 'ai_extraction_status') ?? 'pending';
    if (!$extracted) {
      $status_labels = [
        'pending'    => ['label' => '⏳ AI parsing pending',    'class' => 'messages--warning'],
        'queued'     => ['label' => '⏳ AI parsing queued',     'class' => 'messages--warning'],
        'processing' => ['label' => '⚙️ AI parsing in progress', 'class' => 'messages--warning'],
        'failed'     => ['label' => '⚠️ AI parsing failed', 'class' => 'messages--error'],
      ];
      $badge = $status_labels[$ai_status] ?? ['label' => '⏳ AI parsing not yet run', 'class' => 'messages--warning'];
      $content['ai_status_notice'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['messages', $badge['class']]],
        '#markup' => $badge['label'],
      ];
    }

    // Extracted Job Data section
    if ($extracted) {
      $content['extracted'] = [
        '#type' => 'details',
        '#title' => $this->t('Job Details'),
        '#open' => TRUE,
        '#attributes' => ['class' => ['job-section']],
      ];
      
      // Position info
      if (!empty($extracted['job_title']) || !empty($extracted['employment_type']) || !empty($extracted['experience_years'])) {
        $loc = $extracted['location'] ?? [];
        $location_str = is_array($loc) ? ($loc['full_location'] ?? implode(', ', array_filter([$loc['city'] ?? '', $loc['state'] ?? '']))) : (string) $loc;
        $content['extracted']['position'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['job-subsection']],
          '#markup' => '<h3>Position</h3>' .
            '<dl class="job-details">' .
            '<dt>Title</dt><dd>' . htmlspecialchars($extracted['job_title'] ?? 'N/A') . '</dd>' .
            '<dt>Employment Type</dt><dd>' . htmlspecialchars($extracted['employment_type'] ?? 'N/A') . '</dd>' .
            '<dt>Experience Required</dt><dd>' . htmlspecialchars($extracted['experience_years'] ? $extracted['experience_years'] . ' years' : 'N/A') . '</dd>' .
            '<dt>Remote</dt><dd>' . htmlspecialchars($extracted['remote_option'] ?? 'N/A') . '</dd>' .
            '<dt>Location</dt><dd>' . htmlspecialchars($location_str ?: 'N/A') . '</dd>' .
            '</dl>',
        ];
      }

      // Compensation
      if (!empty($extracted['salary_range']) || !empty($extracted['benefits'])) {
        $benefits = $extracted['benefits'] ?? [];
        $benefits_str = is_array($benefits) ? implode(', ', $benefits) : (string) $benefits;
        $content['extracted']['compensation'] = [
          '#type' => 'container',
          '#markup' => '<h3>Compensation</h3>' .
            '<dl class="job-details">' .
            '<dt>Salary Range</dt><dd>' . htmlspecialchars($extracted['salary_range'] ?? 'N/A') . '</dd>' .
            '<dt>Application Deadline</dt><dd>' . htmlspecialchars($extracted['application_deadline'] ?? 'N/A') . '</dd>' .
            '<dt>Visa Sponsorship</dt><dd>' . ($extracted['visa_sponsorship'] ? 'Yes' : 'No') . '</dd>' .
            '</dl>',
        ];
        if (!empty($benefits_str)) {
          $content['extracted']['benefits'] = [
            '#markup' => '<p><strong>Benefits:</strong> ' . htmlspecialchars($benefits_str) . '</p>',
          ];
        }
      }

      // Requirements
      if (!empty($extracted['requirements'])) {
        $req = $extracted['requirements'];
        $req_items = is_array($req) ? '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $req)) . '</li></ul>' : '<p>' . htmlspecialchars((string) $req) . '</p>';
        $content['extracted']['requirements'] = [
          '#type' => 'container',
          '#markup' => '<h3>Requirements</h3>' . $req_items,
        ];
      }

      // Qualifications (required + preferred)
      if (!empty($extracted['qualifications'])) {
        $qual = $extracted['qualifications'];
        $qual_html = '<h3>Qualifications</h3>';
        if (!empty($qual['required'])) {
          $qual_html .= '<p><strong>Required:</strong></p><ul><li>' . implode('</li><li>', array_map('htmlspecialchars', (array) $qual['required'])) . '</li></ul>';
        }
        if (!empty($qual['preferred'])) {
          $qual_html .= '<p><strong>Preferred:</strong></p><ul><li>' . implode('</li><li>', array_map('htmlspecialchars', (array) $qual['preferred'])) . '</li></ul>';
        }
        $content['extracted']['qualifications'] = [
          '#type' => 'container',
          '#markup' => $qual_html,
        ];
      }

      // Key responsibilities
      if (!empty($extracted['responsibilities'])) {
        $resp = $extracted['responsibilities'];
        $resp_items = is_array($resp) ? '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $resp)) . '</li></ul>' : '<p>' . htmlspecialchars((string) $resp) . '</p>';
        $content['extracted']['responsibilities'] = [
          '#type' => 'container',
          '#markup' => '<h3>Key Responsibilities</h3>' . $resp_items,
        ];
      }

      // Company info
      if (!empty($extracted['company_name']) || !empty($extracted['industry']) || !empty($extracted['company_description'])) {
        $content['extracted']['company_info'] = [
          '#type' => 'container',
          '#markup' => '<h3>Company</h3>' .
            '<dl class="job-details">' .
            '<dt>Name</dt><dd>' . htmlspecialchars($extracted['company_name'] ?? 'N/A') . '</dd>' .
            '<dt>Industry</dt><dd>' . htmlspecialchars($extracted['industry'] ?? 'N/A') . '</dd>' .
            '</dl>' .
            (!empty($extracted['company_description']) ? '<p>' . htmlspecialchars($extracted['company_description']) . '</p>' : ''),
        ];
      }

      // AI-extracted job description narrative
      if (!empty($extracted['job_description'])) {
        $content['extracted']['job_description'] = [
          '#type' => 'container',
          '#markup' => '<h3>Job Description</h3><div class="job-description-text">' . nl2br(htmlspecialchars($extracted['job_description'])) . '</div>',
        ];
      }
    }

    // Original job posting — always show when available (pre-parsed source data)
    $db_description = $jobValue($job, 'job_description');
    $db_requirements = $jobValue($job, 'requirements');
    if (!empty($db_description) || !empty($db_requirements)) {
      $content['original_posting'] = [
        '#type' => 'details',
        '#title' => $this->t('Original Job Posting'),
        '#open' => !$extracted,
        '#attributes' => ['class' => ['job-section']],
      ];
      if (!empty($db_description)) {
        $content['original_posting']['description'] = [
          '#markup' => '<h3>Description</h3><div class="raw-text">' . nl2br(htmlspecialchars($db_description)) . '</div>',
        ];
      }
      if (!empty($db_requirements)) {
        $content['original_posting']['requirements'] = [
          '#markup' => '<h3>Requirements</h3><div class="raw-text">' . nl2br(htmlspecialchars($db_requirements)) . '</div>',
        ];
      }
    }

    // Skills section
    if ($skills) {
      $content['skills'] = [
        '#type' => 'details',
        '#title' => $this->t('Required Skills'),
        '#open' => FALSE,
        '#attributes' => ['class' => ['job-section']],
      ];
      
      // Must-have skills
      if (!empty($skills['must_have'])) {
        $must_items = [];
        foreach ($skills['must_have'] as $skill) {
          $must_items[] = '<strong>' . $skill['skill'] . '</strong>' . 
            (!empty($skill['years']) ? ' (' . $skill['years'] . '+ years)' : '') .
            (!empty($skill['context']) ? ' — ' . $skill['context'] : '');
        }
        $content['skills']['must_have'] = [
          '#markup' => '<h3>Must Have</h3><ul><li>' . implode('</li><li>', $must_items) . '</li></ul>',
        ];
      }
      
      // Nice-to-have skills
      if (!empty($skills['nice_to_have'])) {
        $nice_items = [];
        foreach ($skills['nice_to_have'] as $skill) {
          $nice_items[] = '<strong>' . $skill['skill'] . '</strong>' .
            (!empty($skill['context']) ? ' — ' . $skill['context'] : '');
        }
        $content['skills']['nice_to_have'] = [
          '#markup' => '<h3>Nice to Have</h3><ul><li>' . implode('</li><li>', $nice_items) . '</li></ul>',
        ];
      }
      
      // Tech stack
      if (!empty($skills['tech_stack'])) {
        $stack = $skills['tech_stack'];
        $stack_html = '<h3>Tech Stack</h3><dl class="job-details">';
        if (!empty($stack['languages'])) {
          $stack_html .= '<dt>Languages</dt><dd>' . implode(', ', $stack['languages']) . '</dd>';
        }
        if (!empty($stack['frameworks'])) {
          $stack_html .= '<dt>Frameworks</dt><dd>' . implode(', ', $stack['frameworks']) . '</dd>';
        }
        if (!empty($stack['databases'])) {
          $stack_html .= '<dt>Databases</dt><dd>' . implode(', ', $stack['databases']) . '</dd>';
        }
        if (!empty($stack['cloud'])) {
          $stack_html .= '<dt>Cloud</dt><dd>' . implode(', ', $stack['cloud']) . '</dd>';
        }
        if (!empty($stack['tools'])) {
          $stack_html .= '<dt>Tools</dt><dd>' . implode(', ', $stack['tools']) . '</dd>';
        }
        $stack_html .= '</dl>';
        $content['skills']['tech_stack'] = [
          '#markup' => $stack_html,
        ];
      }
    }
    
    // Add some basic styling
    $content['#attached']['html_head'][] = [
      [
        '#tag' => 'style',
        '#value' => '
          .job-details { display: grid; grid-template-columns: 150px 1fr; gap: 8px; margin: 10px 0; }
          .job-details dt { font-weight: 600; color: #555; }
          .job-details dd { margin: 0; }
          .job-section { margin-bottom: 15px; }
          .job-subsection { margin-bottom: 20px; }
          .job-view-header { margin-bottom: 20px; }
          .job-company { color: #666; font-size: 1.1em; margin-top: -10px; }
          .job-source-info { margin-bottom: 20px; }
          .job-info-box { background: #f8f9fa; border-left: 4px solid #667eea; padding: 15px; border-radius: 4px; }
          .job-info-box strong { color: #333; }
          .job-info-box a { color: #667eea; text-decoration: none; }
          .job-info-box a:hover { text-decoration: underline; }
          .job-meta-list { margin: 0; padding-left: 18px; }
          .job-meta-list li { margin: 0 0 6px 0; }
          .job-meta-list li:last-child { margin-bottom: 0; }
          .status-completed, .status-neutral { color: #10b981; font-weight: 600; }
          .status-pending { color: #f59e0b; font-weight: 600; }
          .status-queued { color: #3b82f6; font-weight: 600; }
          .status-processing { color: #8b5cf6; font-weight: 600; }
          .status-failed { color: #ef4444; font-weight: 600; }
          .raw-text { white-space: pre-wrap; font-size: 0.95em; line-height: 1.6; margin: 10px 0; }
          .job-application-status { margin-bottom: 20px; }
          .job-application-status h4 { margin: 0 0 10px 0; color: #333; }
          .app-status-details { line-height: 1.9; }
          .btn-apply-job { background: #667eea; color: #fff; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; font-size: 0.95em; }
          .btn-apply-job:hover { background: #5563d0; }
          .btn-apply-job.btn-success { background: #10b981; }
          .btn-apply-job.btn-warning { background: #f59e0b; }
          .btn-apply-job.btn-danger { background: #ef4444; }
          .btn-apply-job.btn-secondary { background: #6b7280; }
          .btn-apply-job:disabled { opacity: 0.6; cursor: not-allowed; }
          #apply-status-msg { margin-top: 8px; font-size: 0.9em; padding: 8px 12px; border-radius: 4px; display: none; }
          #apply-status-msg.success { background: #d1fae5; color: #065f46; display: block; }
          #apply-status-msg.error { background: #fee2e2; color: #991b1b; display: block; }
          #apply-status-msg.info { background: #dbeafe; color: #1e40af; display: block; }
        ',
      ],
      'job_view_styles',
    ];

    // Apply button AJAX handler.
    $content['#attached']['html_head'][] = [
      [
        '#tag' => 'script',
        '#value' => '
(function() {
  document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".btn-apply-job").forEach(function(btn) {
      btn.addEventListener("click", function() {
        var jobId     = btn.dataset.jobId;
        var applyUrl  = btn.dataset.applyUrl;
        var token     = btn.dataset.token;
        var statusEl  = document.getElementById("apply-status-msg");

        btn.disabled = true;
        btn.textContent = "⏳ Processing...";

        fetch(applyUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": token
          },
          credentials: "same-origin"
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.status === "manual_required" || data.ats_platform === "aggregator" || data.ats_platform === "unknown") {
            btn.textContent = "📋 Apply Manually";
            btn.classList.remove("btn-apply-job");
            btn.classList.add("btn-secondary");
            if (statusEl) {
              statusEl.className = "info";
              var link = data.apply_url ? " <a href=\"" + data.apply_url + "\" target=\"_blank\">Open application ↗</a>" : "";
              statusEl.innerHTML = "✅ Tracked! This job requires manual submission." + link;
            }
          } else if (data.success) {
            btn.textContent = "✅ Applied";
            btn.classList.add("btn-success");
            if (statusEl) {
              statusEl.className = "success";
              statusEl.textContent = data.message || "Application submitted!";
            }
          } else {
            btn.textContent = "❌ Failed — Retry";
            btn.classList.add("btn-danger");
            btn.disabled = false;
            if (statusEl) {
              statusEl.className = "error";
              statusEl.textContent = data.error || "Submission failed. Please try again.";
            }
          }
        })
        .catch(function(err) {
          btn.textContent = "❌ Error — Retry";
          btn.disabled = false;
          if (statusEl) {
            statusEl.className = "error";
            statusEl.textContent = "Network error. Please try again.";
          }
        });
      });
    });
  });
})();
        ',
      ],
      'job_apply_js',
    ];

    // Status message container (populated by AJAX).
    $content['apply_status_msg'] = [
      '#markup' => '<div id="apply-status-msg"></div>',
    ];

    // Application Notes block — visible only for saved jobs.
    $uid = (int) $this->currentUser->id();
    $saved_job = $this->loadOwnedSavedJob($uid, (int) $job_id);

    if ($saved_job) {
      $saved_job_id = (int) $saved_job->id;
      $existing_notes = $this->database->select('jobhunter_application_notes', 'an')
        ->fields('an', ['manager_name', 'contact_email', 'last_contact_date', 'notes'])
        ->condition('an.uid', $uid)
        ->condition('an.saved_job_id', $saved_job_id)
        ->execute()
        ->fetchObject();

      $notes_save_url = Url::fromRoute('job_hunter.application_notes_save', ['job_id' => (int) $job_id])->toString();
      $notes_csrf_token = \Drupal::csrfToken()->get('jobhunter/jobs/' . (int) $job_id . '/notes/save');

      $f_manager = htmlspecialchars((string) ($existing_notes->manager_name ?? ''));
      $f_email    = htmlspecialchars((string) ($existing_notes->contact_email ?? ''));
      $f_date     = htmlspecialchars((string) ($existing_notes->last_contact_date ?? ''));
      $f_notes    = htmlspecialchars((string) ($existing_notes->notes ?? ''));

      $content['application_notes'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['application-notes-section']],
        '#markup' => '
<h3>Application Notes</h3>
<div class="application-notes-form">
  <div class="notes-field-row">
    <label for="notes-manager-name">Hiring Manager Name</label>
    <input type="text" id="notes-manager-name" name="manager_name" value="' . $f_manager . '" maxlength="255" placeholder="Optional" />
  </div>
  <div class="notes-field-row">
    <label for="notes-contact-email">Contact Email</label>
    <input type="email" id="notes-contact-email" name="contact_email" value="' . $f_email . '" maxlength="255" placeholder="Optional" />
  </div>
  <div class="notes-field-row">
    <label for="notes-last-contact-date">Last Contact Date</label>
    <input type="date" id="notes-last-contact-date" name="last_contact_date" value="' . $f_date . '" />
  </div>
  <div class="notes-field-row">
    <label for="notes-text">Notes <span class="notes-char-count"></span></label>
    <textarea id="notes-text" name="notes" maxlength="2000" rows="5" placeholder="Optional">' . $f_notes . '</textarea>
  </div>
  <button type="button" class="btn-notes-save" data-save-url="' . $notes_save_url . '" data-token="' . $notes_csrf_token . '">Save Notes</button>
  <div id="notes-status-msg"></div>
</div>',
      ];

      $content['#attached']['html_head'][] = [
        [
          '#tag' => 'style',
          '#value' => '
            .application-notes-section { margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #667eea; }
            .application-notes-section h3 { margin: 0 0 15px 0; color: #333; }
            .notes-field-row { margin-bottom: 14px; }
            .notes-field-row label { display: block; font-weight: 600; color: #555; margin-bottom: 4px; font-size: 0.9em; }
            .notes-field-row input[type="text"],
            .notes-field-row input[type="email"],
            .notes-field-row input[type="date"] { width: 100%; max-width: 400px; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.95em; }
            .notes-field-row textarea { width: 100%; max-width: 700px; padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.95em; resize: vertical; }
            .notes-char-count { font-size: 0.8em; color: #9ca3af; font-weight: normal; }
            .btn-notes-save { margin-top: 8px; background: #667eea; color: #fff; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; font-size: 0.95em; }
            .btn-notes-save:hover { background: #5563d0; }
            .btn-notes-save:disabled { opacity: 0.6; cursor: not-allowed; }
            #notes-status-msg { margin-top: 8px; font-size: 0.9em; padding: 8px 12px; border-radius: 4px; display: none; }
            #notes-status-msg.success { background: #d1fae5; color: #065f46; display: block; }
            #notes-status-msg.error { background: #fee2e2; color: #991b1b; display: block; }
          ',
        ],
        'application_notes_styles',
      ];

      $content['#attached']['html_head'][] = [
        [
          '#tag' => 'script',
          '#value' => '
(function() {
  var textarea = document.getElementById("notes-text");
  var charCount = document.querySelector(".notes-char-count");
  if (textarea && charCount) {
    function updateCount() { charCount.textContent = "(" + textarea.value.length + "/2000)"; }
    textarea.addEventListener("input", updateCount);
    updateCount();
  }
  var saveBtn = document.querySelector(".btn-notes-save");
  if (!saveBtn) { return; }
  saveBtn.addEventListener("click", function() {
    var saveUrl = saveBtn.dataset.saveUrl + "?token=" + encodeURIComponent(saveBtn.dataset.token);
    var statusEl = document.getElementById("notes-status-msg");
    var payload = {
      manager_name: document.getElementById("notes-manager-name").value,
      contact_email: document.getElementById("notes-contact-email").value,
      last_contact_date: document.getElementById("notes-last-contact-date").value,
      notes: document.getElementById("notes-text").value
    };
    saveBtn.disabled = true;
    saveBtn.textContent = "Saving\u2026";
    fetch(saveUrl, {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      credentials: "same-origin",
      body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json().then(function(d) { return {status: r.status, data: d}; }); })
    .then(function(res) {
      saveBtn.disabled = false;
      saveBtn.textContent = "Save Notes";
      if (statusEl) {
        statusEl.className = res.status === 200 ? "success" : "error";
        statusEl.textContent = res.status === 200 ? (res.data.message || "Notes saved.") : (res.data.error || "Save failed.");
        setTimeout(function() { statusEl.className = ""; statusEl.textContent = ""; }, 4000);
      }
    })
    .catch(function() {
      saveBtn.disabled = false;
      saveBtn.textContent = "Save Notes";
      if (statusEl) { statusEl.className = "error"; statusEl.textContent = "Network error. Please try again."; }
    });
  });
})();
          ',
        ],
        'application_notes_js',
      ];
    }

    // Deadline tracker form — visible only for saved jobs where schema columns exist.
    if ($saved_job && $this->database->schema()->fieldExists('jobhunter_saved_jobs', 'deadline_date')) {
      $dl_date  = htmlspecialchars((string) ($saved_job->deadline_date ?? ''));
      $fu_date  = htmlspecialchars((string) ($saved_job->follow_up_date ?? ''));
      $dl_save_url   = Url::fromRoute('job_hunter.deadline_save', ['job_id' => (int) $job_id])->toString();
      $dl_csrf_token = \Drupal::csrfToken()->get('jobhunter/jobs/' . (int) $job_id . '/deadline/save');

      $content['deadline_tracker'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['deadline-tracker-section']],
        '#markup' => '
<h3>&#128197; Application Dates</h3>
<div class="deadline-form">
  <div class="deadline-field-row">
    <label for="deadline-date">Application Deadline</label>
    <input type="date" id="deadline-date" name="deadline_date" value="' . $dl_date . '" />
  </div>
  <div class="deadline-field-row">
    <label for="followup-date">Follow-up Reminder</label>
    <input type="date" id="followup-date" name="follow_up_date" value="' . $fu_date . '" />
  </div>
  <button type="button" class="btn-deadline-save" data-save-url="' . $dl_save_url . '" data-token="' . $dl_csrf_token . '">Save Dates</button>
  <div id="deadline-status-msg"></div>
</div>',
      ];

      $content['#attached']['html_head'][] = [
        [
          '#tag' => 'style',
          '#value' => '
            .deadline-tracker-section { margin-top: 24px; padding: 20px; background: #f0fdf4; border-radius: 8px; border-left: 4px solid #10b981; }
            .deadline-tracker-section h3 { margin: 0 0 14px 0; color: #333; }
            .deadline-field-row { margin-bottom: 12px; }
            .deadline-field-row label { display: block; font-weight: 600; color: #555; margin-bottom: 4px; font-size: 0.9em; }
            .deadline-field-row input[type="date"] { padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.95em; width: 200px; }
            .btn-deadline-save { margin-top: 4px; background: #10b981; color: #fff; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; font-size: 0.95em; }
            .btn-deadline-save:hover { background: #059669; }
            .btn-deadline-save:disabled { opacity: 0.6; cursor: not-allowed; }
            #deadline-status-msg { margin-top: 8px; font-size: 0.9em; padding: 8px 12px; border-radius: 4px; display: none; }
            #deadline-status-msg.success { background: #d1fae5; color: #065f46; display: block; }
            #deadline-status-msg.error { background: #fee2e2; color: #991b1b; display: block; }
          ',
        ],
        'deadline_tracker_styles',
      ];

      $content['#attached']['html_head'][] = [
        [
          '#tag' => 'script',
          '#value' => '
(function() {
  var saveBtn = document.querySelector(".btn-deadline-save");
  if (!saveBtn) { return; }
  saveBtn.addEventListener("click", function() {
    var saveUrl = saveBtn.dataset.saveUrl + "?token=" + encodeURIComponent(saveBtn.dataset.token);
    var statusEl = document.getElementById("deadline-status-msg");
    var payload = {
      deadline_date: document.getElementById("deadline-date").value,
      follow_up_date: document.getElementById("followup-date").value
    };
    saveBtn.disabled = true;
    saveBtn.textContent = "Saving\u2026";
    fetch(saveUrl, {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      credentials: "same-origin",
      body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json().then(function(d) { return {status: r.status, data: d}; }); })
    .then(function(res) {
      saveBtn.disabled = false;
      saveBtn.textContent = "Save Dates";
      if (statusEl) {
        statusEl.className = res.status === 200 ? "success" : "error";
        statusEl.textContent = res.status === 200 ? (res.data.message || "Dates saved.") : (res.data.error || "Save failed.");
        setTimeout(function() { statusEl.className = ""; statusEl.textContent = ""; }, 4000);
      }
    })
    .catch(function() {
      saveBtn.disabled = false;
      saveBtn.textContent = "Save Dates";
      if (statusEl) { statusEl.className = "error"; statusEl.textContent = "Network error. Please try again."; }
    });
  });
})();
          ',
        ],
        'deadline_tracker_js',
      ];
    }

    // Interview outcome tracker — visible on saved-job detail pages once the
    // interview rounds table exists.
    if ($saved_job && $this->database->schema()->tableExists('jobhunter_interview_rounds')) {
      $saved_job_id = (int) $saved_job->id;
      $interview_rounds = $this->loadInterviewRounds($uid, $saved_job_id);
      $save_url = Url::fromRoute('job_hunter.interview_round_save', ['job_id' => (int) $job_id])->toString();
      $save_token = \Drupal::csrfToken()->get('jobhunter/interview-rounds/' . (int) $job_id . '/save');
      $prep_url = Url::fromRoute('job_hunter.interview_prep', ['job_id' => (int) $job_id])->toString();

      $round_type_options = '';
      foreach (self::INTERVIEW_ROUND_TYPES as $round_type) {
        $round_type_options .= '<option value="' . htmlspecialchars($round_type, ENT_QUOTES) . '">'
          . htmlspecialchars($this->getInterviewRoundTypeLabel($round_type))
          . '</option>';
      }

      $outcome_options = '';
      foreach (self::INTERVIEW_ROUND_OUTCOMES as $outcome) {
        $outcome_meta = $this->getInterviewOutcomeMeta($outcome);
        $outcome_options .= '<option value="' . htmlspecialchars($outcome, ENT_QUOTES) . '">'
          . htmlspecialchars($outcome_meta['label'])
          . '</option>';
      }

      $summary_markup = '';
      if (!empty($interview_rounds)) {
        $latest_round = $interview_rounds[count($interview_rounds) - 1];
        $latest_meta = $this->getInterviewOutcomeMeta((string) $latest_round->outcome);
        $summary_markup = '<p class="interview-rounds-summary">'
          . '<strong>' . count($interview_rounds) . '</strong> rounds logged. Latest: '
          . htmlspecialchars($this->getInterviewRoundTypeLabel((string) $latest_round->round_type))
          . ' — <span class="interview-outcome-badge ' . htmlspecialchars($latest_meta['class']) . '">'
          . htmlspecialchars($latest_meta['label']) . '</span>'
          . ' on ' . htmlspecialchars((string) $latest_round->conducted_date)
          . '.</p>';
      }

      $content['interview_rounds'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['interview-rounds-section']],
        '#markup' => '<h3>Interview Outcome Tracker</h3>'
          . '<p class="interview-rounds-intro">Log each interview round here and keep prep notes separate at '
          . '<a href="' . htmlspecialchars($prep_url) . '">Interview Prep</a>.</p>'
          . $summary_markup
          . '<div class="interview-round-form">'
          . '<h4 class="interview-round-form-heading">Add Interview Round</h4>'
          . '<input type="hidden" id="interview-round-id" value="">'
          . '<div class="interview-round-form-grid">'
          . '<div class="interview-round-field"><label for="interview-round-type">Round Type</label><select id="interview-round-type">' . $round_type_options . '</select></div>'
          . '<div class="interview-round-field"><label for="interview-round-outcome">Outcome</label><select id="interview-round-outcome">' . $outcome_options . '</select></div>'
          . '<div class="interview-round-field"><label for="interview-round-date">Date Conducted</label><input type="date" id="interview-round-date"></div>'
          . '</div>'
          . '<div class="interview-round-field"><label for="interview-round-notes">Notes</label><textarea id="interview-round-notes" rows="4" maxlength="4000" placeholder="Optional"></textarea></div>'
          . '<div class="interview-round-actions">'
          . '<button type="button" class="button button--primary btn-interview-round-save" data-save-url="' . $save_url . '" data-token="' . htmlspecialchars($save_token, ENT_QUOTES) . '">Save Interview Round</button>'
          . '<button type="button" class="button button--secondary btn-interview-round-reset">Clear</button>'
          . '<div id="interview-round-status-msg"></div>'
          . '</div>'
          . '</div>'
          . '<div id="interview-round-log" class="interview-round-log">'
          . $this->buildInterviewRoundsLogHtml($interview_rounds)
          . '</div>',
      ];

      $content['#attached']['html_head'][] = [
        [
          '#tag' => 'style',
          '#value' => '
            .interview-rounds-section { margin-top: 24px; padding: 20px; background: #fefce8; border-radius: 8px; border-left: 4px solid #f59e0b; }
            .interview-rounds-section h3 { margin: 0 0 10px 0; color: #333; }
            .interview-rounds-intro { margin: 0 0 12px 0; }
            .interview-rounds-summary { margin: 0 0 14px 0; color: #444; }
            .interview-round-form { background: #fff; border: 1px solid #fde68a; border-radius: 8px; padding: 16px; margin-bottom: 18px; }
            .interview-round-form-heading { margin: 0 0 12px 0; }
            .interview-round-form-grid { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
            .interview-round-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
            .interview-round-field label { font-weight: 600; color: #555; font-size: 0.9em; }
            .interview-round-field select,
            .interview-round-field input[type="date"],
            .interview-round-field textarea { width: 100%; max-width: 420px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.95em; }
            .interview-round-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
            .interview-rounds-table { width: 100%; border-collapse: collapse; background: #fff; }
            .interview-rounds-table th, .interview-rounds-table td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
            .interview-rounds-table th { background: #f9fafb; font-weight: 600; }
            .interview-rounds-empty { margin: 0; padding: 14px 0 4px; color: #666; }
            .interview-outcome-badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 0.85em; font-weight: 600; }
            .interview-outcome-badge.outcome-pending { background: #e5e7eb; color: #374151; }
            .interview-outcome-badge.outcome-passed { background: #d1fae5; color: #065f46; }
            .interview-outcome-badge.outcome-failed { background: #fee2e2; color: #991b1b; }
            .interview-outcome-badge.outcome-withdrawn { background: #fef3c7; color: #92400e; }
            .interview-outcome-badge.outcome-neutral { background: #e0e7ff; color: #3730a3; }
            #interview-round-status-msg { font-size: 0.9em; padding: 8px 12px; border-radius: 4px; display: none; }
            #interview-round-status-msg.success { display: block; background: #d1fae5; color: #065f46; }
            #interview-round-status-msg.error { display: block; background: #fee2e2; color: #991b1b; }
          ',
        ],
        'interview_rounds_styles',
      ];

      $content['#attached']['html_head'][] = [
        [
          '#tag' => 'script',
          '#value' => '
(function() {
  var saveBtn = document.querySelector(".btn-interview-round-save");
  var resetBtn = document.querySelector(".btn-interview-round-reset");
  if (!saveBtn || !resetBtn) { return; }

  var statusEl = document.getElementById("interview-round-status-msg");
  var roundIdEl = document.getElementById("interview-round-id");
  var roundTypeEl = document.getElementById("interview-round-type");
  var outcomeEl = document.getElementById("interview-round-outcome");
  var dateEl = document.getElementById("interview-round-date");
  var notesEl = document.getElementById("interview-round-notes");
  var headingEl = document.querySelector(".interview-round-form-heading");
  var logEl = document.getElementById("interview-round-log");

  function setStatus(kind, message) {
    if (!statusEl) { return; }
    statusEl.className = kind ? kind : "";
    statusEl.textContent = message || "";
  }

  function resetForm() {
    roundIdEl.value = "";
    roundTypeEl.value = "phone-screen";
    outcomeEl.value = "pending";
    dateEl.value = "";
    notesEl.value = "";
    headingEl.textContent = "Add Interview Round";
    saveBtn.textContent = "Save Interview Round";
  }

  resetBtn.addEventListener("click", function() {
    resetForm();
    setStatus("", "");
  });

  document.addEventListener("click", function(event) {
    var editBtn = event.target.closest(".btn-interview-round-edit");
    if (!editBtn) { return; }
    roundIdEl.value = editBtn.dataset.roundId || "";
    roundTypeEl.value = editBtn.dataset.roundType || "phone-screen";
    outcomeEl.value = editBtn.dataset.outcome || "pending";
    dateEl.value = editBtn.dataset.conductedDate || "";
    notesEl.value = editBtn.dataset.notes || "";
    headingEl.textContent = "Edit Interview Round";
    saveBtn.textContent = "Update Interview Round";
    setStatus("", "");
    notesEl.focus();
  });

  saveBtn.addEventListener("click", function() {
    var saveUrl = saveBtn.dataset.saveUrl + "?token=" + encodeURIComponent(saveBtn.dataset.token);
    var payload = {
      round_id: roundIdEl.value,
      round_type: roundTypeEl.value,
      outcome: outcomeEl.value,
      conducted_date: dateEl.value,
      notes: notesEl.value
    };

    saveBtn.disabled = true;
    resetBtn.disabled = true;
    saveBtn.textContent = roundIdEl.value ? "Updating..." : "Saving...";

    fetch(saveUrl, {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      credentials: "same-origin",
      body: JSON.stringify(payload)
    })
      .then(function(response) {
        return response.json().then(function(data) {
          return {status: response.status, data: data};
        });
      })
      .then(function(result) {
        saveBtn.disabled = false;
        resetBtn.disabled = false;
        if (result.status === 200) {
          if (logEl && result.data.log_html) {
            logEl.innerHTML = result.data.log_html;
          }
          resetForm();
          setStatus("success", result.data.message || "Interview round saved.");
        }
        else {
          saveBtn.textContent = roundIdEl.value ? "Update Interview Round" : "Save Interview Round";
          setStatus("error", result.data.error || "Unable to save interview round.");
        }
      })
      .catch(function() {
        saveBtn.disabled = false;
        resetBtn.disabled = false;
        saveBtn.textContent = roundIdEl.value ? "Update Interview Round" : "Save Interview Round";
        setStatus("error", "Network error. Please try again.");
      });
  });

  resetForm();
})();
          ',
        ],
        'interview_rounds_js',
      ];
    }

    // Offer Details section — visible when job status is 'offered' and job is saved.
    if ($saved_job && (string) ($job->status ?? '') === 'offered' && $this->database->schema()->tableExists('jobhunter_offers')) {
      $saved_job_id = (int) $saved_job->id;
      $existing_offer = $this->database->select('jobhunter_offers', 'o')
        ->fields('o')
        ->condition('o.uid', $uid)
        ->condition('o.saved_job_id', $saved_job_id)
        ->execute()
        ->fetchObject();

      $offer_save_url   = Url::fromRoute('job_hunter.offer_save', ['job_id' => (int) $job_id])->toString();
      $offer_save_token = \Drupal::csrfToken()->get('jobhunter/jobs/' . (int) $job_id . '/offer/save');

      $f_salary   = htmlspecialchars((string) ($existing_offer->base_salary ?? ''));
      $f_equity   = htmlspecialchars((string) ($existing_offer->equity_summary ?? ''));
      $f_benefits = htmlspecialchars((string) ($existing_offer->benefits_summary ?? ''));
      $f_deadline = htmlspecialchars((string) ($existing_offer->response_deadline ?? ''));
      $f_notes    = htmlspecialchars((string) ($existing_offer->notes ?? ''));
      $offers_url = Url::fromRoute('job_hunter.offers')->toString();

      $content['offer_details'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['offer-details-section']],
        '#markup' => '<h3>Offer Details</h3>'
          . '<p class="offer-details-intro">Record this offer below. Compare all active offers at <a href="' . htmlspecialchars($offers_url) . '">My Offers</a>.</p>'
          . '<div class="offer-details-form">'
          . '<div class="offer-field-row"><label for="offer-base-salary">Base Salary ($)</label>'
          . '<input type="number" id="offer-base-salary" min="0" max="9999999" value="' . $f_salary . '" placeholder="Optional"></div>'
          . '<div class="offer-field-row"><label for="offer-equity">Equity / Bonus Summary</label>'
          . '<input type="text" id="offer-equity" maxlength="2000" value="' . $f_equity . '" placeholder="Optional"></div>'
          . '<div class="offer-field-row"><label for="offer-benefits">Benefits Summary</label>'
          . '<input type="text" id="offer-benefits" maxlength="2000" value="' . $f_benefits . '" placeholder="Optional"></div>'
          . '<div class="offer-field-row"><label for="offer-deadline">Response Deadline</label>'
          . '<input type="date" id="offer-deadline" value="' . $f_deadline . '"></div>'
          . '<div class="offer-field-row"><label for="offer-notes">Notes</label>'
          . '<textarea id="offer-notes" rows="4" maxlength="2000" placeholder="Optional">' . $f_notes . '</textarea></div>'
          . '<div class="offer-actions">'
          . '<button type="button" class="button button--primary btn-offer-save" data-save-url="' . $offer_save_url . '" data-token="' . htmlspecialchars($offer_save_token, ENT_QUOTES) . '">Save Offer Details</button>'
          . '<div id="offer-status-msg"></div>'
          . '</div>'
          . '</div>',
      ];

      $content['#attached']['html_head'][] = [
        [
          '#tag' => 'style',
          '#value' => '
            .offer-details-section { margin-top: 24px; padding: 20px; background: #f0fdf4; border-radius: 8px; border-left: 4px solid #22c55e; }
            .offer-details-section h3 { margin: 0 0 10px 0; color: #333; }
            .offer-details-intro { margin: 0 0 14px 0; }
            .offer-details-form { background: #fff; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; }
            .offer-field-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; }
            .offer-field-row label { font-weight: 600; color: #555; font-size: 0.9em; }
            .offer-field-row input[type="text"],
            .offer-field-row input[type="number"],
            .offer-field-row input[type="date"],
            .offer-field-row textarea { width: 100%; max-width: 480px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.95em; }
            .offer-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 4px; }
            #offer-status-msg { font-size: 0.9em; padding: 8px 12px; border-radius: 4px; display: none; }
            #offer-status-msg.success { display: block; background: #d1fae5; color: #065f46; }
            #offer-status-msg.error { display: block; background: #fee2e2; color: #991b1b; }
          ',
        ],
        'offer_details_styles',
      ];

      $content['#attached']['html_head'][] = [
        [
          '#tag' => 'script',
          '#value' => '
(function() {
  var saveBtn = document.querySelector(".btn-offer-save");
  if (!saveBtn) { return; }
  var statusEl = document.getElementById("offer-status-msg");

  function setStatus(cls, msg) {
    if (!statusEl) { return; }
    statusEl.className = cls;
    statusEl.textContent = msg;
    if (cls === "success") { setTimeout(function() { statusEl.className = ""; statusEl.textContent = ""; }, 4000); }
  }

  saveBtn.addEventListener("click", function() {
    var salary = document.getElementById("offer-base-salary").value.trim();
    var equity = document.getElementById("offer-equity").value.trim();
    var benefits = document.getElementById("offer-benefits").value.trim();
    var deadline = document.getElementById("offer-deadline").value.trim();
    var notes = document.getElementById("offer-notes").value.trim();

    saveBtn.disabled = true;
    saveBtn.textContent = "Saving\u2026";

    var payload = {
      base_salary: salary !== "" ? parseInt(salary, 10) : null,
      equity_summary: equity,
      benefits_summary: benefits,
      response_deadline: deadline,
      notes: notes
    };

    var url = saveBtn.getAttribute("data-save-url") + "?token=" + encodeURIComponent(saveBtn.getAttribute("data-token"));
    fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      saveBtn.disabled = false;
      saveBtn.textContent = "Save Offer Details";
      if (data.error) { setStatus("error", data.error); }
      else { setStatus("success", "Offer details saved."); }
    })
    .catch(function() {
      saveBtn.disabled = false;
      saveBtn.textContent = "Save Offer Details";
      setStatus("error", "Network error. Please try again.");
    });
  });
})();
          ',
        ],
        'offer_details_js',
      ];
    }

    try {
      $uid = (int) $current_user->id();

      // Find the user's saved_job row for this job requirement.
      $saved_job_row_id = $this->database->select('jobhunter_saved_jobs', 'sj')
        ->fields('sj', ['id'])
        ->condition('sj.uid', $uid)
        ->condition('sj.job_id', (int) $job_id)
        ->execute()
        ->fetchField();

      $linked_contacts = [];
      if ($saved_job_row_id) {
        $linked_contacts = $this->database->select('jobhunter_contact_job_links', 'l')
          ->fields('l', ['contact_id'])
          ->condition('l.uid', $uid)
          ->condition('l.saved_job_id', (int) $saved_job_row_id)
          ->execute()
          ->fetchCol();
      }

      $contact_name_field = $this->getContactNameField();
      $contact_title_field = $this->getContactTitleField();
      $contact_has_company_id = $this->database->schema()->fieldExists('jobhunter_contacts', 'company_id');
      $contact_has_company_name = $this->database->schema()->fieldExists('jobhunter_contacts', 'company_name');

      $referral_rows = [];
      $other_rows = [];
      if (!empty($linked_contacts)) {
        $contact_query = $this->database->select('jobhunter_contacts', 'ct');
        $contact_query->fields('ct', ['id', $contact_name_field, $contact_title_field, 'relationship_type', 'referral_status']);
        $contact_rows = $contact_query
          ->condition('ct.id', $linked_contacts, 'IN')
          ->condition('ct.uid', $uid)
          ->orderBy('ct.' . $contact_name_field)
          ->execute()
          ->fetchAll();
        foreach ($contact_rows as $c) {
          $edit_url = Url::fromRoute('job_hunter.contacts_edit', ['contact_id' => $c->id])->toString();
          $ref_badge = '';
          if ($c->referral_status === 'provided') {
            $ref_badge = ' <span style="display:inline-block;background:#d1fae5;color:#065f46;padding:2px 7px;border-radius:999px;font-size:0.8em;font-weight:600;">Provided</span>';
          }
          elseif ($c->referral_status === 'pending') {
            $ref_badge = ' <span style="display:inline-block;background:#fef3c7;color:#92400e;padding:2px 7px;border-radius:999px;font-size:0.8em;font-weight:600;">Pending Referral</span>';
          }
          $row_html = '<tr>'
            . '<td>' . htmlspecialchars((string) ($c->{$contact_name_field} ?? '')) . $ref_badge . '</td>'
            . '<td>' . htmlspecialchars((string) ($c->{$contact_title_field} ?? '')) . '</td>'
            . '<td>' . htmlspecialchars((string) $c->relationship_type) . '</td>'
            . '<td><a href="' . htmlspecialchars($edit_url) . '">Edit</a></td>'
            . '</tr>';
          if ($c->referral_status === 'provided') {
            $referral_rows[] = $row_html;
          }
          else {
            $other_rows[] = $row_html;
          }
        }
      }

      $job_company_id = $this->database->select('jobhunter_job_requirements', 'jr')
        ->fields('jr', ['company_id'])
        ->condition('jr.id', (int) $job_id)
        ->range(0, 1)
        ->execute()
        ->fetchField();

      // Also show same-company contacts not already linked.
      $job_company_name = $extracted['company_name'] ?? NULL;
      $exclude_ids = !empty($linked_contacts) ? array_map('intval', $linked_contacts) : [0];
      $company_match = [];

      if ($job_company_id && $contact_has_company_id) {
        $company_match_query = $this->database->select('jobhunter_contacts', 'ct');
        $company_match_query->fields('ct', ['id', $contact_name_field, $contact_title_field, 'relationship_type', 'referral_status']);
        if ($contact_has_company_name) {
          $company_match_query->addField('ct', 'company_name', 'legacy_company_name');
        }
        $company_match = $company_match_query
          ->condition('ct.uid', $uid)
          ->condition('ct.company_id', (int) $job_company_id)
          ->condition('ct.id', $exclude_ids, 'NOT IN')
          ->orderBy('ct.' . $contact_name_field)
          ->execute()
          ->fetchAll();
      }

      if (empty($company_match) && !empty($job_company_name) && $contact_has_company_name) {
        $company_match_query = $this->database->select('jobhunter_contacts', 'ct');
        $company_match_query->fields('ct', ['id', $contact_name_field, $contact_title_field, 'relationship_type', 'referral_status', 'company_name']);
        $company_match = $company_match_query
          ->condition('ct.uid', $uid)
          ->condition('ct.company_name', $job_company_name)
          ->condition('ct.id', $exclude_ids, 'NOT IN')
          ->orderBy('ct.' . $contact_name_field)
          ->execute()
          ->fetchAll();
      }

      foreach ($company_match as $c) {
        $edit_url = Url::fromRoute('job_hunter.contacts_edit', ['contact_id' => $c->id])->toString();
        $other_rows[] = '<tr>'
          . '<td>' . htmlspecialchars((string) ($c->{$contact_name_field} ?? ''))
          . ' <span style="display:inline-block;background:#e0e7ff;color:#3730a3;padding:2px 7px;border-radius:999px;font-size:0.8em;">Company match</span></td>'
          . '<td>' . htmlspecialchars((string) ($c->{$contact_title_field} ?? '')) . '</td>'
          . '<td>' . htmlspecialchars((string) $c->relationship_type) . '</td>'
          . '<td><a href="' . htmlspecialchars($edit_url) . '">Edit</a></td>'
          . '</tr>';
      }

      if (!empty($referral_rows) || !empty($other_rows)) {
        $all_rows = implode('', array_merge($referral_rows, $other_rows));
        $content['contacts_at_company'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['contacts-at-company-section']],
          '#markup' => '<h3>Contacts for This Job</h3>'
            . '<table class="contacts-at-company-table">'
            . '<thead><tr><th>Name</th><th>Title</th><th>Relationship</th><th></th></tr></thead>'
            . '<tbody>' . $all_rows . '</tbody>'
            . '</table>',
        ];
        $content['#attached']['html_head'][] = [
          [
            '#type' => 'html_tag',
            '#tag' => 'style',
            '#value' => '
              .contacts-at-company-section { margin-top: 30px; padding: 20px; background: #f0f4ff; border-radius: 8px; border-left: 4px solid #667eea; }
              .contacts-at-company-section h3 { margin: 0 0 12px 0; color: #333; }
              .contacts-at-company-table { width: 100%; border-collapse: collapse; font-size: 0.95em; }
              .contacts-at-company-table th, .contacts-at-company-table td { padding: 8px 12px; border-bottom: 1px solid #d1d5db; text-align: left; }
              .contacts-at-company-table th { background: #e8ecf8; font-weight: 600; }
            ',
          ],
          'contacts_at_company_styles',
        ];
      }
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('contacts_for_job query failed: uid=@uid error=@error', [
        '@uid' => (int) $current_user->id(),
        '@error' => $e->getMessage(),
      ]);
    }

    // AC-1 + AC-2 + AC-5: Resume submitted on application — show and allow updating.
    try {
      $uid_for_resume = (int) $current_user->id();
      $app_resume_row = $this->database->select('jobhunter_applications', 'a')
        ->fields('a', ['id', 'source_resume_id', 'submitted_resume_id', 'submitted_resume_type'])
        ->condition('a.uid', $uid_for_resume)
        ->condition('a.job_id', (int) $job_id)
        ->orderBy('a.created', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      // Resolve current submitted resume — prefer new columns, fall back to source_resume_id (migration compat).
      $current_submitted_id   = $app_resume_row ? (int) ($app_resume_row['submitted_resume_id'] ?? $app_resume_row['source_resume_id'] ?? 0) : 0;
      $current_submitted_type = $app_resume_row ? ($app_resume_row['submitted_resume_type'] ?: ($app_resume_row['source_resume_id'] ? 'base' : '')) : '';

      // Load user's job seeker record for ownership checks.
      $job_seeker_for_resume = $this->database->select('jobhunter_job_seeker', 'js')
        ->fields('js', ['id'])
        ->condition('uid', $uid_for_resume)
        ->execute()
        ->fetchField();

      // Load base resumes.
      $base_resumes = [];
      if ($job_seeker_for_resume) {
        $base_resumes = $this->database->select('jobhunter_job_seeker_resumes', 'jsr')
          ->fields('jsr', ['id', 'resume_name', 'version_label'])
          ->condition('job_seeker_id', (int) $job_seeker_for_resume)
          ->orderBy('changed', 'DESC')
          ->execute()
          ->fetchAll();
      }

      // Load tailored resumes for this user.
      $tailored_resumes = $this->database->select('jobhunter_tailored_resumes', 'tr')
        ->fields('tr', ['id', 'job_id'])
        ->condition('uid', $uid_for_resume)
        ->orderBy('updated', 'DESC')
        ->execute()
        ->fetchAll();

      if ($app_resume_row || !empty($base_resumes) || !empty($tailored_resumes)) {
        // Build "Resume submitted" display (AC-1).
        $resume_used_html = '';
        if ($current_submitted_id && $current_submitted_type) {
          $type_badge = '<span style="background:#e2e8f0;padding:2px 7px;border-radius:10px;font-size:0.85em;margin-left:6px;">' . htmlspecialchars($current_submitted_type) . '</span>';
          if ($current_submitted_type === 'base') {
            $linked = $this->database->select('jobhunter_job_seeker_resumes', 'jsr')
              ->fields('jsr', ['resume_name', 'version_label'])
              ->condition('id', $current_submitted_id)
              ->execute()
              ->fetchAssoc();
            if ($linked) {
              $label_display = !empty($linked['version_label']) ? htmlspecialchars($linked['version_label']) : htmlspecialchars($linked['resume_name']);
              $edit_url = Url::fromRoute('job_hunter.resume_version_edit', ['resume_id' => $current_submitted_id])->toString();
              $resume_used_html = '<p><strong>Resume submitted:</strong> ' . $label_display . $type_badge . ' <a href="' . htmlspecialchars($edit_url) . '">(view)</a></p>';
            }
          }
          elseif ($current_submitted_type === 'tailored') {
            $resume_used_html = '<p><strong>Resume submitted:</strong> Tailored resume #' . $current_submitted_id . $type_badge . '</p>';
          }
        }

        // Build grouped dropdown (AC-2, AC-5).
        $options_html = '<option value="">-- Select a resume --</option>';
        if (!empty($base_resumes)) {
          $options_html .= '<optgroup label="Base Resumes">';
          foreach ($base_resumes as $r) {
            $label = !empty($r->version_label) ? htmlspecialchars($r->version_label) : htmlspecialchars($r->resume_name);
            $selected = ($current_submitted_type === 'base' && $current_submitted_id === (int) $r->id) ? ' selected' : '';
            $options_html .= '<option value="base:' . (int) $r->id . '"' . $selected . '>' . $label . '</option>';
          }
          $options_html .= '</optgroup>';
        }
        if (!empty($tailored_resumes)) {
          $options_html .= '<optgroup label="Tailored Resumes">';
          foreach ($tailored_resumes as $tr) {
            $tr_label = 'Tailored #' . (int) $tr->id . ' (job ' . (int) $tr->job_id . ')';
            $selected = ($current_submitted_type === 'tailored' && $current_submitted_id === (int) $tr->id) ? ' selected' : '';
            $options_html .= '<option value="tailored:' . (int) $tr->id . '"' . $selected . '>' . htmlspecialchars($tr_label) . '</option>';
          }
          $options_html .= '</optgroup>';
        }

        $csrf_token = \Drupal::csrfToken()->get('jobhunter/jobs/' . (int) $job_id . '/resume-source/save');
        $save_url = Url::fromRoute('job_hunter.resume_source_save', ['job_id' => (int) $job_id])->toString()
          . '?token=' . rawurlencode($csrf_token);

        $content['resume_source_section'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['resume-source-section']],
          '#markup' => '
<div class="resume-source-section-inner">
  <h3>📄 Resume Submitted</h3>
  ' . $resume_used_html . '
  <div class="resume-source-field-row">
    <label for="rs-resume-select">Link a resume to this application:</label>
    <select id="rs-resume-select" style="margin-left:8px;">' . $options_html . '</select>
    <button id="rs-save-btn" type="button" class="button button--primary" style="margin-left:8px;">Save</button>
  </div>
  <div id="rs-status" style="margin-top:8px;"></div>
</div>
<script>
(function() {
  document.getElementById("rs-save-btn").addEventListener("click", function() {
    var val = document.getElementById("rs-resume-select").value;
    if (!val) { document.getElementById("rs-status").textContent = "Please select a resume."; return; }
    var parts = val.split(":");
    var rtype = parts[0];
    var rid = parseInt(parts[1], 10);
    fetch(' . json_encode($save_url) . ', {
      method: "POST",
      headers: {"Content-Type": "application/json"},
      body: JSON.stringify({submitted_resume_id: rid, submitted_resume_type: rtype})
    }).then(function(r) { return r.json(); }).then(function(d) {
      var el = document.getElementById("rs-status");
      if (d.message) { el.textContent = "✅ " + d.message; el.style.color = "green"; }
      else { el.textContent = "❌ " + (d.error || "Error saving."); el.style.color = "red"; }
    }).catch(function() {
      document.getElementById("rs-status").textContent = "❌ Request failed.";
    });
  });
})();
</script>',
        ];

        $content['#attached']['html_head'][] = [
          [
            '#type' => 'html_tag',
            '#tag' => 'style',
            '#value' => '
              .resume-source-section { margin-top: 30px; padding: 20px; background: #f0fff4; border-radius: 8px; border-left: 4px solid #38a169; }
              .resume-source-section-inner h3 { margin: 0 0 12px 0; color: #333; }
              .resume-source-field-row { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
            ',
          ],
          'resume_source_styles',
        ];
      }
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('resume_source_section failed: uid=@uid job_id=@jid error=@error', [
        '@uid' => (int) $current_user->id(),
        '@jid' => $job_id,
        '@error' => $e->getMessage(),
      ]);
    }

    return $this->wrapWithNavigation($content);
  }

  /**
   * Return existing application notes as JSON (GET, no CSRF needed).
   *
   * @param int $job_id
   *   The job_requirements ID.
   */
  public function applicationNotesLoad($job_id): JsonResponse {
    $uid = (int) $this->currentUser->id();
    $job_id = (int) $job_id;

    $saved_job = $this->database->select('jobhunter_saved_jobs', 'sj')
      ->fields('sj', ['id'])
      ->condition('sj.uid', $uid)
      ->condition('sj.job_id', $job_id)
      ->execute()
      ->fetchObject();

    if (!$saved_job) {
      return new JsonResponse(['manager_name' => '', 'contact_email' => '', 'last_contact_date' => '', 'notes' => '']);
    }

    $saved_job_id = (int) $saved_job->id;
    $row = $this->database->select('jobhunter_application_notes', 'an')
      ->fields('an', ['manager_name', 'contact_email', 'last_contact_date', 'notes'])
      ->condition('an.uid', $uid)
      ->condition('an.saved_job_id', $saved_job_id)
      ->execute()
      ->fetchObject();

    return new JsonResponse([
      'manager_name'      => (string) ($row->manager_name ?? ''),
      'contact_email'     => (string) ($row->contact_email ?? ''),
      'last_contact_date' => (string) ($row->last_contact_date ?? ''),
      'notes'             => (string) ($row->notes ?? ''),
    ]);
  }

  /**
   * Save (create or update) application notes (POST, CSRF-protected).
   *
   * @param int $job_id
   *   The job_requirements ID.
   */
  public function applicationNotesSave($job_id): JsonResponse {
    $uid = (int) $this->currentUser->id();
    $job_id = (int) $job_id;
    $request = $this->requestStack->getCurrentRequest();

    // Ownership check: saved_job must belong to this user.
    $saved_job = $this->database->select('jobhunter_saved_jobs', 'sj')
      ->fields('sj', ['id'])
      ->condition('sj.uid', $uid)
      ->condition('sj.job_id', $job_id)
      ->execute()
      ->fetchObject();

    if (!$saved_job) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }
    $saved_job_id = (int) $saved_job->id;

    // Parse JSON body.
    $body = json_decode((string) $request->getContent(), TRUE) ?? [];

    $manager_name      = strip_tags((string) ($body['manager_name'] ?? ''));
    $contact_email_raw = (string) ($body['contact_email'] ?? '');
    $last_contact_date = preg_replace('/[^0-9\-]/', '', (string) ($body['last_contact_date'] ?? ''));
    $notes_raw         = (string) ($body['notes'] ?? '');

    // Validate email (AC-6).
    if ($contact_email_raw !== '' && !filter_var($contact_email_raw, FILTER_VALIDATE_EMAIL)) {
      return new JsonResponse(['error' => 'Invalid email address.'], 422);
    }
    $contact_email = $contact_email_raw;

    // Enforce notes length limit (AC-5).
    if (mb_strlen($notes_raw) > 2000) {
      return new JsonResponse(['error' => 'Notes may not exceed 2000 characters.'], 400);
    }
    $notes = strip_tags($notes_raw);

    $now = time();

    $existing_id = $this->database->select('jobhunter_application_notes', 'an')
      ->fields('an', ['id'])
      ->condition('an.uid', $uid)
      ->condition('an.saved_job_id', $saved_job_id)
      ->execute()
      ->fetchField();

    if ($existing_id) {
      $this->database->update('jobhunter_application_notes')
        ->fields([
          'manager_name'      => $manager_name ?: NULL,
          'contact_email'     => $contact_email ?: NULL,
          'last_contact_date' => $last_contact_date ?: NULL,
          'notes'             => $notes ?: NULL,
          'changed'           => $now,
        ])
        ->condition('uid', $uid)
        ->condition('saved_job_id', $saved_job_id)
        ->execute();
    }
    else {
      $this->database->insert('jobhunter_application_notes')
        ->fields([
          'uid'               => $uid,
          'saved_job_id'      => $saved_job_id,
          'manager_name'      => $manager_name ?: NULL,
          'contact_email'     => $contact_email ?: NULL,
          'last_contact_date' => $last_contact_date ?: NULL,
          'notes'             => $notes ?: NULL,
          'created'           => $now,
          'changed'           => $now,
        ])
        ->execute();
    }

    // SEC-5: log only uid and saved_job_id, never PII fields.
    $this->getLogger('job_hunter')->info('Application notes saved: uid=@uid saved_job_id=@sjid', [
      '@uid'  => $uid,
      '@sjid' => $saved_job_id,
    ]);

    return new JsonResponse(['status' => 'ok', 'message' => 'Notes saved.']);
  }

  /**
   * Display the edit job form wrapped in navigation.
   */
  public function editJobForm($job_id) {
    // Build the form
    $form = $this->formBuilder->getForm('Drupal\job_hunter\Form\JobRequirementForm', $job_id);

    return $this->wrapWithNavigation($form);
  }

  /**
   * Combined job view and resume tailoring page.
   */
  public function jobTailoring($job_id) {
    try {
      // Get current user
      $user = $this->entityTypeManager()->getStorage('user')->load($this->currentUser->id());
      $saved_job = $this->loadOwnedSavedJob((int) $this->currentUser->id(), (int) $job_id);
      
      // Load the job
      $job = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j')
        ->condition('id', $job_id)
        ->execute()
        ->fetchObject();
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Unable to load job. Please try again.'));
      $this->getLogger('job_hunter')->error('Failed to load job @id: @error', [
        '@id' => $job_id,
        '@error' => $e->getMessage(),
      ]);
      return new RedirectResponse(Url::fromRoute('job_hunter.my_jobs')->toString());
    }
    
    if (!$job) {
      $this->messenger()->addError($this->t('Job not found.'));
      return new RedirectResponse(Url::fromRoute('job_hunter.my_jobs')->toString());
    }

    // AC-6: Cross-user access check — users may only view their own job tailoring.
    if (!$saved_job) {
      throw new AccessDeniedHttpException();
    }

    // Parse JSON data using helper method
    $extracted = $this->safeJsonDecode($job->extracted_json, 'job extracted data', $job_id) ?? [];
    $skills = $this->safeJsonDecode($job->skills_required_json, 'job skills', $job_id) ?? [];
    $keywords = $this->safeJsonDecode($job->keywords_json, 'job keywords', $job_id) ?? [];
    
    // Load user's tailored resume for this job (if exists)
    $tailored_record = $this->database->select('jobhunter_tailored_resumes', 'tr')
      ->fields('tr')
      ->condition('uid', $user->id())
      ->condition('job_id', $job_id)
      ->execute()
      ->fetchObject();
    
    $tailored = $tailored_record ? $this->safeJsonDecode($tailored_record->tailored_resume_json, 'tailored resume', $job_id) : NULL;
    $tailoring_status = $tailored_record ? $tailored_record->tailoring_status : 'pending';
    
    // Fix stuck queued/processing status
    if ($tailored_record && in_array($tailoring_status, ['queued', 'processing'])) {
      $queue_item = $this->database->select('queue', 'q')
        ->fields('q', ['item_id'])
        ->condition('name', 'job_hunter_resume_tailoring')
        ->condition('data', '%"job_id":' . $job_id . '%', 'LIKE')
        ->execute()
        ->fetchField();
      
      if (!$queue_item) {
        $new_status = $tailored ? 'completed' : 'pending';
        $this->database->update('jobhunter_tailored_resumes')
          ->fields(['tailoring_status' => $new_status])
          ->condition('uid', $user->id())
          ->condition('job_id', $job_id)
          ->execute();
        $tailoring_status = $new_status;
      }
    }
    
    // Get PDF info
    $pdf_path = $tailored_record && !empty($tailored_record->pdf_path) ? $tailored_record->pdf_path : NULL;
    $pdf_generated = $tailored_record && !empty($tailored_record->pdf_generated) ? $tailored_record->pdf_generated : NULL;

    // Get PDF history for this job
    $pdf_history = $this->database->select('jobhunter_pdf_history', 'ph')
      ->fields('ph')
      ->condition('uid', $user->id())
      ->condition('job_id', $job_id)
      ->orderBy('created', 'DESC')
      ->execute()
      ->fetchAll();

    // Load user's job seeker profile
    $job_seeker_profile = $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js')
      ->condition('uid', $user->id())
      ->execute()
      ->fetchObject();
    
    $profile_json = [];
    if ($job_seeker_profile && !empty($job_seeker_profile->consolidated_profile_json)) {
      $profile_json = $this->safeJsonDecode($job_seeker_profile->consolidated_profile_json, 'job seeker profile', $user->id()) ?? [];
    }

    // Calculate skills gap
    $skills_gap = [];
    if (!empty($skills['must_have']) && !empty($profile_json['skills'])) {
      $user_skills_lower = array_map('strtolower', array_column($profile_json['skills'], 'name'));
      foreach ($skills['must_have'] as $required_skill) {
        $skill_name = $required_skill['skill'] ?? '';
        if ($skill_name && !in_array(strtolower($skill_name), $user_skills_lower)) {
          $skills_gap[] = $required_skill;
        }
      }
    }
    
    // Build combined content
    $save_resume_url = Url::fromRoute('job_hunter.job_tailoring_save_resume', ['job_id' => $job_id])->toString();
    $content = [
      '#theme' => 'job_tailoring_combined',
      '#job' => $job,
      '#job_id' => $job_id,
      '#job_extracted' => $extracted,
      '#job_skills' => $skills,
      '#job_keywords' => $keywords,
      '#user' => $user,
      '#profile' => $job_seeker_profile,
      '#profile_json' => $profile_json,
      '#skills_gap' => $skills_gap,
      '#tailored_resume' => $tailored,
      '#tailoring_status' => $tailoring_status,
      '#pdf_path' => $pdf_path,
      '#pdf_generated' => $pdf_generated,
      '#pdf_history' => $pdf_history,
      '#confidence_score' => $tailored_record ? (int) ($tailored_record->confidence_score ?? 0) : 0,
      '#save_resume_url' => $save_resume_url,
      '#attached' => [
        'library' => [
          'job_hunter/job-hunter-navigation',
          'job_hunter/job-hunter-home',
          'job_hunter/tailor_resume',
        ],
      ],
    ];
    
    return $this->wrapWithNavigation($content);
  }

  /**
   * POST handler: save a completed tailored resume as the user's active resume.
   *
   * Route: job_hunter.job_tailoring_save_resume (POST, CSRF-protected).
   *
   * @param int $job_id
   *   The job whose tailored resume is being saved.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to the tailoring page with a status message.
   */
  public function saveResume($job_id) {
    $uid = (int) $this->currentUser->id();

    // Load the tailored resume record (scoped to current user + job).
    $tailored_record = $this->database->select('jobhunter_tailored_resumes', 'tr')
      ->fields('tr', ['id', 'tailoring_status', 'uid'])
      ->condition('tr.uid', $uid)
      ->condition('tr.job_id', (int) $job_id)
      ->execute()
      ->fetchObject();

    if (!$tailored_record) {
      $this->messenger()->addError($this->t('Tailored resume not found.'));
      return new RedirectResponse(Url::fromRoute('job_hunter.job_tailoring', ['job_id' => $job_id])->toString());
    }

    // Ownership double-check (belt-and-suspenders; route already guards).
    if ((int) $tailored_record->uid !== $uid) {
      throw new AccessDeniedHttpException();
    }

    if ($tailored_record->tailoring_status !== 'completed') {
      $this->messenger()->addWarning($this->t('Tailoring must be completed before saving to your profile.'));
      return new RedirectResponse(Url::fromRoute('job_hunter.job_tailoring', ['job_id' => $job_id])->toString());
    }

    // Update jobhunter_job_seeker to point to this tailored resume as active.
    $updated = $this->database->update('jobhunter_job_seeker')
      ->fields(['active_tailored_resume_id' => (int) $tailored_record->id])
      ->condition('uid', $uid)
      ->execute();

    if ($updated > 0) {
      $this->messenger()->addStatus($this->t('Tailored resume saved as your active resume.'));
    }
    else {
      $this->messenger()->addWarning($this->t('Profile record not found; could not save resume. Please complete your profile first.'));
    }

    return new RedirectResponse(Url::fromRoute('job_hunter.job_tailoring', ['job_id' => $job_id])->toString());
  }

  /**
   * Cover letter display page — GET /jobhunter/coverletter/{job_id}.
   */
  public function coverLetter($job_id) {
    $uid = (int) $this->currentUser->id();
    $job_id = (int) $job_id;
    $saved_job = $this->loadOwnedSavedJob($uid, $job_id);

    if (!$saved_job) {
      throw new AccessDeniedHttpException();
    }

    $job = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j', ['id', 'job_title'])
      ->condition('j.id', $job_id)
      ->execute()
      ->fetchObject();

    if (!$job) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    // Load existing cover letter record (may be NULL).
    $cover_letter = $this->database->select('jobhunter_cover_letters', 'cl')
      ->fields('cl')
      ->condition('cl.uid', $uid)
      ->condition('cl.job_id', $job_id)
      ->execute()
      ->fetchObject();

    $tailoring_status = $cover_letter ? (string) $cover_letter->tailoring_status : NULL;
    $cover_letter_html = ($tailoring_status === 'completed' && $cover_letter)
      ? (string) ($cover_letter->cover_letter_html ?: '')
      : '';

    $generate_url = Url::fromRoute('job_hunter.cover_letter_generate', ['job_id' => $job_id])->toString();
    $save_url = Url::fromRoute('job_hunter.cover_letter_save', ['job_id' => $job_id])->toString();

    $build = [
      '#theme' => 'cover_letter_display',
      '#job' => $job,
      '#job_id' => $job_id,
      '#cover_letter' => $cover_letter,
      '#tailoring_status' => $tailoring_status,
      '#cover_letter_html' => $cover_letter_html,
      '#pdf_path' => $cover_letter ? $cover_letter->pdf_path : NULL,
      '#generate_url' => $generate_url,
      '#save_url' => $save_url,
      '#attached' => [
        'drupalSettings' => [
          'jobHunterCoverLetter' => [
            'jobId' => $job_id,
            'status' => $tailoring_status,
          ],
        ],
      ],
    ];

    return $this->wrapWithNavigation($build);
  }

  /**
   * Cover letter generate — POST /jobhunter/coverletter/{job_id}/generate.
   *
   * Creates a jobhunter_cover_letters row (status=queued) if one does not exist
   * (or re-enqueues on retry), then enqueues a queue item.
   */
  public function coverLetterGenerate($job_id) {
    $uid = (int) $this->currentUser->id();
    $job_id = (int) $job_id;

    // Verify job ownership.
    $saved_job = $this->loadOwnedSavedJob($uid, $job_id);
    if (!$saved_job) {
      throw new AccessDeniedHttpException();
    }

    try {
      $existing = $this->database->select('jobhunter_cover_letters', 'cl')
        ->fields('cl', ['id', 'tailoring_status'])
        ->condition('cl.uid', $uid)
        ->condition('cl.job_id', $job_id)
        ->execute()
        ->fetchObject();

      $now = time();

      if (!$existing) {
        $this->database->insert('jobhunter_cover_letters')
          ->fields([
            'uid' => $uid,
            'job_id' => $job_id,
            'tailoring_status' => 'queued',
            'created' => $now,
            'updated' => $now,
          ])
          ->execute();
      }
      else {
        $this->database->update('jobhunter_cover_letters')
          ->fields(['tailoring_status' => 'queued', 'updated' => $now])
          ->condition('uid', $uid)
          ->condition('job_id', $job_id)
          ->execute();
      }

      // Enqueue the cover letter generation item.
      $queue = \Drupal::queue('job_hunter_cover_letter_tailoring');
      $queue->createItem([
        'uid' => $uid,
        'job_id' => $job_id,
      ]);

      $this->messenger()->addStatus($this->t('Cover letter generation queued. Check back shortly.'));
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('Cover letter enqueue failed for job @id: @error', [
        '@id' => $job_id,
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Failed to queue cover letter generation. Please try again.'));
    }

    return new RedirectResponse(Url::fromRoute('job_hunter.cover_letter', ['job_id' => $job_id])->toString());
  }

  /**
   * Cover letter save — POST /jobhunter/coverletter/{job_id}/save.
   *
   * Links the completed cover letter to the job application record.
   */
  public function coverLetterSave($job_id) {
    $uid = (int) $this->currentUser->id();
    $job_id = (int) $job_id;

    $cover_letter = $this->database->select('jobhunter_cover_letters', 'cl')
      ->fields('cl', ['id', 'uid', 'tailoring_status'])
      ->condition('cl.uid', $uid)
      ->condition('cl.job_id', $job_id)
      ->execute()
      ->fetchObject();

    if (!$cover_letter || (int) $cover_letter->uid !== $uid) {
      throw new AccessDeniedHttpException();
    }

    if ($cover_letter->tailoring_status !== 'completed') {
      $this->messenger()->addWarning($this->t('Cover letter must be completed before saving.'));
      return new RedirectResponse(Url::fromRoute('job_hunter.cover_letter', ['job_id' => $job_id])->toString());
    }

    // Update application record if one exists, else no-op (graceful).
    $updated = $this->database->update('jobhunter_applications')
      ->fields(['cover_letter_id' => (int) $cover_letter->id, 'updated' => time()])
      ->condition('uid', $uid)
      ->condition('job_id', $job_id)
      ->execute();

    if ($updated > 0) {
      $this->messenger()->addStatus($this->t('Cover letter saved to your application.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Cover letter saved. It will be linked when you apply.'));
    }

    return new RedirectResponse(Url::fromRoute('job_hunter.cover_letter', ['job_id' => $job_id])->toString());
  }

  /**
   * Interview prep page — checklist, notes, and AI tips for a saved job.
   *
   * @param int $job_id
   *   The saved job ID (integer enforced by routing pattern \d+).
   *
   * @return array
   *   Render array wrapped in navigation.
   */
  public function interviewPrep($job_id) {
    $uid = (int) $this->currentUser->id();
    $job_id = (int) $job_id;
    $saved_job = $this->loadOwnedSavedJob($uid, $job_id);

    if (!$saved_job) {
      throw new AccessDeniedHttpException();
    }

    $job = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j', ['id', 'job_title'])
      ->condition('j.id', $job_id)
      ->execute()
      ->fetchObject();

    if (!$job) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $notes_text = $this->database->select('jobhunter_interview_notes', 'n')
      ->fields('n', ['notes_text'])
      ->condition('n.uid', $uid)
      ->condition('n.job_id', $job_id)
      ->execute()
      ->fetchField();

    $build = [
      '#theme' => 'interview_prep_page',
      '#job' => $job,
      '#job_id' => $job_id,
      '#notes_text' => $notes_text ?: '',
      '#save_url' => Url::fromRoute('job_hunter.interview_prep_save', ['job_id' => $job_id])->toString(),
      '#ai_tips_url' => Url::fromRoute('job_hunter.interview_prep_ai_tips', ['job_id' => $job_id])->toString(),
    ];

    return $this->wrapWithNavigation($build);
  }

  /**
   * Save interview prep notes (POST, CSRF-guarded).
   *
   * @param int $job_id
   *   The saved job ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function interviewPrepSave($job_id) {
    $uid = (int) $this->currentUser->id();
    $job_id = (int) $job_id;
    $request = $this->requestStack->getCurrentRequest();
    $saved_job = $this->loadOwnedSavedJob($uid, $job_id);

    if (!$saved_job) {
      throw new AccessDeniedHttpException();
    }

    $notes_raw = (string) $request->request->get('notes_text', '');
    if (mb_strlen($notes_raw) > 10000) {
      $this->messenger()->addError($this->t('Notes may not exceed 10,000 characters.'));
      return new RedirectResponse(Url::fromRoute('job_hunter.interview_prep', ['job_id' => $job_id])->toString());
    }
    $notes = strip_tags($notes_raw);

    $existing_id = $this->database->select('jobhunter_interview_notes', 'n')
      ->fields('n', ['id'])
      ->condition('n.uid', $uid)
      ->condition('n.job_id', $job_id)
      ->execute()
      ->fetchField();

    if ($existing_id) {
      $this->database->update('jobhunter_interview_notes')
        ->fields(['notes_text' => $notes, 'updated' => time()])
        ->condition('uid', $uid)
        ->condition('job_id', $job_id)
        ->execute();
    }
    else {
      $this->database->insert('jobhunter_interview_notes')
        ->fields([
          'uid' => $uid,
          'job_id' => $job_id,
          'notes_text' => $notes,
          'updated' => time(),
        ])
        ->execute();
    }

    $this->messenger()->addStatus($this->t('Interview notes saved.'));
    return new RedirectResponse(Url::fromRoute('job_hunter.interview_prep', ['job_id' => $job_id])->toString());
  }

  /**
   * Save or update an interview round (POST, CSRF-guarded, AJAX).
   *
   * @param int $job_id
   *   The saved job's job requirement ID.
   */
  public function interviewRoundSave($job_id): JsonResponse {
    if (!$this->database->schema()->tableExists('jobhunter_interview_rounds')) {
      return new JsonResponse(['error' => 'Interview tracker is not yet available. Run database updates first.'], 503);
    }

    $uid = (int) $this->currentUser->id();
    $job_id = (int) $job_id;
    $saved_job = $this->loadOwnedSavedJob($uid, $job_id);
    if (!$saved_job) {
      return new JsonResponse(['error' => 'Not found.'], 403);
    }

    $request = $this->requestStack->getCurrentRequest();
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body)) {
      $body = $request->request->all();
    }

    $round_id = isset($body['round_id']) && $body['round_id'] !== '' ? (int) $body['round_id'] : NULL;
    $round_type = trim((string) ($body['round_type'] ?? ''));
    $outcome = trim((string) ($body['outcome'] ?? ''));
    $conducted_date = trim((string) ($body['conducted_date'] ?? ''));
    $notes = strip_tags(trim((string) ($body['notes'] ?? '')));

    if (!in_array($round_type, self::INTERVIEW_ROUND_TYPES, TRUE)) {
      return new JsonResponse(['error' => 'Invalid interview round type.'], 400);
    }
    if (!in_array($outcome, self::INTERVIEW_ROUND_OUTCOMES, TRUE)) {
      return new JsonResponse(['error' => 'Invalid interview outcome.'], 400);
    }
    if ($conducted_date === '') {
      return new JsonResponse(['error' => 'Interview date is required.'], 400);
    }

    $parsed_date = \DateTime::createFromFormat('Y-m-d', $conducted_date);
    if (!$parsed_date || $parsed_date->format('Y-m-d') !== $conducted_date) {
      return new JsonResponse(['error' => 'Invalid interview date format. Use YYYY-MM-DD.'], 400);
    }
    if (mb_strlen($notes) > 4000) {
      return new JsonResponse(['error' => 'Notes may not exceed 4000 characters.'], 400);
    }

    $saved_job_id = (int) $saved_job->id;
    $now = time();

    if ($round_id !== NULL) {
      $existing_round = $this->database->select('jobhunter_interview_rounds', 'ir')
        ->fields('ir', ['id'])
        ->condition('ir.id', $round_id)
        ->condition('ir.uid', $uid)
        ->condition('ir.saved_job_id', $saved_job_id)
        ->execute()
        ->fetchField();

      if (!$existing_round) {
        return new JsonResponse(['error' => 'Interview round not found.'], 404);
      }

      $this->database->update('jobhunter_interview_rounds')
        ->fields([
          'round_type' => $round_type,
          'outcome' => $outcome,
          'conducted_date' => $conducted_date,
          'notes' => $notes !== '' ? $notes : NULL,
          'changed' => $now,
        ])
        ->condition('id', $round_id)
        ->condition('uid', $uid)
        ->condition('saved_job_id', $saved_job_id)
        ->execute();

      $message = 'Interview round updated.';
    }
    else {
      $this->database->insert('jobhunter_interview_rounds')
        ->fields([
          'uid' => $uid,
          'saved_job_id' => $saved_job_id,
          'round_type' => $round_type,
          'outcome' => $outcome,
          'conducted_date' => $conducted_date,
          'notes' => $notes !== '' ? $notes : NULL,
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();

      $message = 'Interview round saved.';
    }

    return new JsonResponse([
      'message' => $message,
      'log_html' => $this->buildInterviewRoundsLogHtml($this->loadInterviewRounds($uid, $saved_job_id)),
    ]);
  }

  /**
   * Return AI-generated interview tips as JSON (POST, CSRF-guarded, AJAX).
   *
   * @param int $job_id
   *   The saved job ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function interviewPrepAiTips($job_id) {
    $uid = (int) $this->currentUser->id();
    $job_id = (int) $job_id;
    $saved_job = $this->loadOwnedSavedJob($uid, $job_id);

    if (!$saved_job) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    $job = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j', ['id', 'job_title', 'job_description'])
      ->condition('j.id', $job_id)
      ->execute()
      ->fetchObject();

    if (!$job) {
      return new JsonResponse(['error' => 'Not found.'], 404);
    }

    $job_title = (string) ($job->job_title ?: 'this role');
    $job_desc_snippet = substr((string) ($job->job_description ?: ''), 0, 500);

    $profile_summary = '';
    try {
      $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
      if ($user && $user->hasField('field_professional_summary')) {
        $profile_summary = (string) $user->get('field_professional_summary')->getString();
      }
    }
    catch (\Exception $e) {
      // Non-fatal — proceed without profile summary.
    }

    $prompt = "You are a career coach. Give 3-5 concise bullet-point interview preparation tips for a candidate applying for the following position.\n\nJob Title: {$job_title}\n";
    if ($job_desc_snippet) {
      $prompt .= "Job Description (excerpt): {$job_desc_snippet}\n";
    }
    if ($profile_summary) {
      $prompt .= "Candidate Summary: {$profile_summary}\n";
    }
    $prompt .= "\nRespond with ONLY a JSON object: {\"tips\": [\"tip1\", \"tip2\", ...]}";

    try {
      $ai_service = \Drupal::service('ai_conversation.ai_api_service');
      $result = $ai_service->invokeModelDirect(
        $prompt,
        'job_hunter',
        'interview_prep_tips',
        ['job_id' => $job_id],
        ['skip_cache' => FALSE]
      );

      if (!empty($result['success']) && !empty($result['response'])) {
        $raw = trim($result['response']);
        $decoded = json_decode($raw, TRUE);
        if (is_array($decoded) && !empty($decoded['tips'])) {
          return new JsonResponse(['tips' => $decoded['tips']]);
        }
        // Fallback: parse plain-text bullets if JSON parsing fails.
        $lines = array_filter(explode("\n", $raw));
        $tips = array_values(array_filter(
          array_map(fn($l) => trim(ltrim($l, '-•* ')), $lines)
        ));
        return new JsonResponse(['tips' => array_slice($tips, 0, 5)]);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('interview_prep_ai_tips error for job @id: @code', [
        '@id' => $job_id,
        '@code' => get_class($e),
      ]);
    }

    return new JsonResponse(['error' => 'Could not generate tips. Please try again later.'], 500);
  }

  /**
   * Display the add company form wrapped in navigation.
   */
  public function addForm($company_id = NULL) {
    // Build the form
    $form = $this->formBuilder->getForm('Drupal\job_hunter\Form\CompanyForm', $company_id);

    return $this->wrapWithNavigation($form);
  }

  /**
   * Display the bulk import form wrapped in navigation.
   */
  public function bulkImportForm() {
    // Build the form
    $form = $this->formBuilder->getForm('Drupal\job_hunter\Form\BulkCompanyImportForm');

    return $this->wrapWithNavigation($form);
  }

  /**
   * POST handler: initiate automated application for a job.
   *
   * Route: POST /jobhunter/jobs/{job_id}/apply
   */
  public function applyToJob($job_id) {
    $uid = $this->currentUser->id();
    $request = $this->requestStack->getCurrentRequest();
    $return_to = (string) $request->query->get('return_to', '');
    $redirect_mode = $return_to !== '';
    if ($redirect_mode && !preg_match('/^\/(?!\/)/', $return_to)) {
      $return_to = '/jobhunter/my-jobs';
    }

    if (!$uid) {
      if ($redirect_mode) {
        $this->messenger()->addError($this->t('Not authenticated.'));
        return new RedirectResponse('/user/login');
      }
      return new JsonResponse(['success' => FALSE, 'error' => 'Not authenticated.'], 403);
    }

    // Validate CSRF token from header (AJAX) or form field (My Jobs form).
    $token = $request->headers->get('X-CSRF-Token')
      ?: $request->request->get('csrf_token')
      ?: $request->query->get('csrf_token');
    if (!\Drupal::csrfToken()->validate($token, 'jobhunter/jobs/' . (int) $job_id . '/apply')) {
      if ($redirect_mode) {
        $this->messenger()->addError($this->t('Invalid security token. Please refresh the page and try again.'));
        return new RedirectResponse($return_to ?: '/jobhunter/my-jobs');
      }
      return new JsonResponse(['success' => FALSE, 'error' => 'Invalid security token.'], 403);
    }

    /** @var \Drupal\job_hunter\Service\ApplicationSubmissionService $submission_service */
    $submission_service = \Drupal::service('job_hunter.application_submission_service');
    /** @var \Drupal\job_hunter\Service\ApplyUrlResolverService $resolver */
    $resolver = \Drupal::service('job_hunter.apply_url_resolver');

    // Load job (only the columns that actually exist on this table).
    $job = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j', ['id', 'apply_options', 'job_url', 'job_title'])
      ->condition('id', (int) $job_id)
      ->execute()
      ->fetchAssoc();

    if (!$job) {
      if ($redirect_mode) {
        $this->messenger()->addError($this->t('Job not found.'));
        return new RedirectResponse($return_to ?: '/jobhunter/my-jobs');
      }
      return new JsonResponse(['success' => FALSE, 'error' => 'Job not found.'], 404);
    }

    // Resolve the best apply URL before queuing (result stored on the application record).
    $resolved = $resolver->resolve($job);

    // Submit application — this validates prerequisites internally and queues it.
    $result = $submission_service->submitApplication($uid, (int) $job_id, TRUE);

    if (!$result['success'] && ($result['status'] ?? '') !== 'queued') {
      if ($redirect_mode) {
        $this->messenger()->addError($this->t($result['message'] ?? 'Submission failed.'));
        return new RedirectResponse($return_to ?: '/jobhunter/my-jobs');
      }
      return new JsonResponse([
        'success' => FALSE,
        'error'   => $result['message'] ?? 'Submission failed.',
        'details' => $result['error'] ?? [],
      ], 422);
    }

    // Update the application record with resolved URL and ATS metadata.
    if (!empty($result['application_id'])) {
      $update_fields = [
        'apply_url'             => $resolved['url'],
        'selected_apply_option' => $resolved['selected_option'],
        'metadata'              => json_encode([
          'resolution_steps' => $resolved['resolution_steps'],
          'confidence'       => $resolved['confidence'],
        ]),
        'changed' => date('Y-m-d H:i:s'),
      ];
      if ($this->database->schema()->fieldExists('jobhunter_applications', 'ats_platform')) {
        $update_fields['ats_platform'] = $resolved['ats_platform'];
      }

      $this->database->update('jobhunter_applications')
        ->fields($update_fields)
        ->condition('id', $result['application_id'])
        ->execute();
    }

    // Determine UI response based on ATS platform.
    $platform  = $resolved['ats_platform'];
    $apply_url = $resolved['url'] ?: ($job['job_url'] ?? '');

    if (in_array($platform, ['aggregator', 'unknown', ''])) {
      if ($redirect_mode) {
        $this->messenger()->addWarning($this->t('Application tracked. This job requires manual submission.'));
        return new RedirectResponse($return_to ?: '/jobhunter/my-jobs');
      }
      return new JsonResponse([
        'success'        => TRUE,
        'status'         => 'manual_required',
        'message'        => 'Application tracked. This job requires manual submission.',
        'apply_url'      => $apply_url,
        'ats_platform'   => $platform,
        'application_id' => $result['application_id'] ?? NULL,
      ]);
    }

    if ($redirect_mode) {
      $this->messenger()->addStatus($this->t($result['message'] ?? 'Application queued for submission.'));
      return new RedirectResponse($return_to ?: '/jobhunter/my-jobs');
    }

    return new JsonResponse([
      'success'        => $result['success'],
      'status'         => $result['status'] ?? 'queued',
      'message'        => $result['message'] ?? 'Application queued for submission.',
      'ats_platform'   => $platform,
      'apply_url'      => $apply_url,
      'application_id' => $result['application_id'] ?? NULL,
    ]);
  }

  /**
   * GET handler: return current application status for a job.
   *
   * Route: GET /jobhunter/jobs/{job_id}/application-status
   */
  public function applicationStatus($job_id) {
    $uid = $this->currentUser->id();
    if (!$uid) {
      return new JsonResponse(['error' => 'Not authenticated.'], 403);
    }

    $app_query = $this->database->select('jobhunter_applications', 'a')
      ->fields('a', ['id', 'submission_status', 'apply_url', 'selected_apply_option', 'attempt_count', 'confirmation_reference', 'submission_date', 'automation_success', 'admin_review_required', 'created'])
      ->condition('a.uid', $uid)
      ->condition('a.job_id', (int) $job_id)
      ->orderBy('a.created', 'DESC')
      ->range(0, 1);
    if ($this->database->schema()->fieldExists('jobhunter_applications', 'ats_platform')) {
      $app_query->addField('a', 'ats_platform');
    }
    else {
      $app_query->addExpression("''", 'ats_platform');
    }

    $app = $app_query
      ->execute()
      ->fetchAssoc();

    if (!$app) {
      return new JsonResponse(['applied' => FALSE]);
    }

    // Get attempt history.
    $attempts = $this->database->select('jobhunter_application_attempts', 'at')
      ->fields('at', ['attempted_at', 'ats_detected', 'outcome', 'error_message'])
      ->condition('application_id', $app['id'])
      ->orderBy('attempted_at', 'DESC')
      ->range(0, 5)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    return new JsonResponse([
      'applied'                => TRUE,
      'application_id'         => $app['id'],
      'status'                 => $app['submission_status'],
      'ats_platform'           => $app['ats_platform'],
      'apply_url'              => $app['apply_url'],
      'selected_apply_option'  => $app['selected_apply_option'],
      'attempt_count'          => (int) $app['attempt_count'],
      'confirmation_reference' => $app['confirmation_reference'],
      'submission_date'        => $app['submission_date'],
      'automation_success'     => (bool) $app['automation_success'],
      'admin_review_required'  => (bool) $app['admin_review_required'],
      'created'                => $app['created'],
      'attempts'               => $attempts ?: [],
    ]);
  }

  /**
   * Save deadline_date and follow_up_date for a saved job (POST, CSRF-protected).
   *
   * @param int $job_id
   *   The job_requirements ID.
   */
  public function deadlineSave($job_id): JsonResponse {
    $uid    = (int) $this->currentUser->id();
    $job_id = (int) $job_id;

    $ownership = $this->database->select('jobhunter_saved_jobs', 'sj')
      ->fields('sj', ['id'])
      ->condition('sj.uid', $uid)
      ->condition('sj.job_id', $job_id)
      ->execute()
      ->fetchField();

    if (!$ownership) {
      return new JsonResponse(['error' => 'Not found.'], 403);
    }

    $request = $this->requestStack->getCurrentRequest();
    $body    = json_decode($request->getContent(), TRUE) ?? [];

    $deadline_date  = isset($body['deadline_date'])  && $body['deadline_date']  !== '' ? $body['deadline_date']  : NULL;
    $follow_up_date = isset($body['follow_up_date']) && $body['follow_up_date'] !== '' ? $body['follow_up_date'] : NULL;

    if ($deadline_date !== NULL) {
      $parsed = \DateTime::createFromFormat('Y-m-d', $deadline_date);
      if (!$parsed || $parsed->format('Y-m-d') !== $deadline_date) {
        return new JsonResponse(['error' => 'Invalid deadline date format. Use YYYY-MM-DD.'], 400);
      }
    }
    if ($follow_up_date !== NULL) {
      $parsed = \DateTime::createFromFormat('Y-m-d', $follow_up_date);
      if (!$parsed || $parsed->format('Y-m-d') !== $follow_up_date) {
        return new JsonResponse(['error' => 'Invalid follow-up date format. Use YYYY-MM-DD.'], 400);
      }
    }

    $this->database->update('jobhunter_saved_jobs')
      ->fields([
        'deadline_date'  => $deadline_date,
        'follow_up_date' => $follow_up_date,
        'updated'        => time(),
      ])
      ->condition('uid', $uid)
      ->condition('job_id', $job_id)
      ->execute();

    return new JsonResponse(['message' => 'Dates saved.']);
  }

  /**
   * Show all saved jobs with deadline urgency indicators at /jobhunter/status.
   */
  public function statusDashboard(): array {
    $uid  = (int) $this->currentUser->id();
    $today = new \DateTime('today');

    $rows = $this->database->select('jobhunter_saved_jobs', 'sj')
      ->fields('sj', ['job_id', 'deadline_date', 'follow_up_date', 'archived'])
      ->condition('sj.uid', $uid)
      ->condition('sj.archived', 0)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $job_ids = array_column($rows, 'job_id');
    $jobs_by_id = [];
    if ($job_ids) {
      $job_results = $this->database->select('jobhunter_job_requirements', 'jr')
        ->fields('jr', ['id', 'job_title'])
        ->condition('jr.id', $job_ids, 'IN')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);
      foreach ($job_results as $j) {
        $jobs_by_id[(int) $j['id']] = $j['job_title'];
      }
      $company_results = $this->database->query(
        'SELECT jr.id as job_id, c.name as company_name
         FROM {jobhunter_job_requirements} jr
         LEFT JOIN {jobhunter_companies} c ON jr.company_id = c.id
         WHERE jr.id IN (:ids[])',
        [':ids[]' => $job_ids]
      )->fetchAll(\PDO::FETCH_ASSOC);
      $companies_by_job = [];
      foreach ($company_results as $cr) {
        $companies_by_job[(int) $cr['job_id']] = $cr['company_name'] ?? '';
      }
    }

    $table_rows = [];
    foreach ($rows as $row) {
      $job_id    = (int) $row['job_id'];
      $job_title = $jobs_by_id[$job_id] ?? 'Unknown Job';
      $company   = $companies_by_job[$job_id] ?? '';
      $dl        = $row['deadline_date'];
      $fu        = $row['follow_up_date'];

      $urgency_class = '';
      $urgency_label = '';
      if ($dl) {
        $dl_dt = new \DateTime($dl);
        $diff  = (int) $today->diff($dl_dt)->days;
        $past  = $dl_dt < $today;
        if ($past) {
          $urgency_class = 'deadline-overdue';
          $urgency_label = 'Overdue';
        }
        elseif ($diff <= 3) {
          $urgency_class = 'deadline-soon';
          $urgency_label = $diff === 0 ? 'Due today' : 'Due in ' . $diff . 'd';
        }
        else {
          $urgency_label = $dl;
        }
      }

      $job_url = Url::fromRoute('job_hunter.job_view', ['job_id' => $job_id])->toString();
      $table_rows[] = [
        ['data' => '<a href="' . $job_url . '">' . htmlspecialchars($job_title) . '</a>', 'allow_html' => TRUE],
        htmlspecialchars($company),
        ['data' => '<span class="' . $urgency_class . '">' . htmlspecialchars($urgency_label ?: '—') . '</span>', 'allow_html' => TRUE],
        htmlspecialchars($fu ?: '—'),
      ];
    }

    $content = [
      '#type' => 'container',
      'heading' => ['#markup' => '<h2>Application Status</h2>'],
    ];

    if ($table_rows) {
      $content['table'] = [
        '#type'   => 'table',
        '#header' => ['Job', 'Company', 'Deadline', 'Follow-up'],
        '#rows'   => $table_rows,
        '#attributes' => ['class' => ['status-dashboard-table']],
      ];
    }
    else {
      $content['empty'] = ['#markup' => '<p>No active saved jobs found.</p>'];
    }

    $content['#attached']['html_head'][] = [
      [
        '#tag'   => 'style',
        '#value' => '
          .status-dashboard-table { width: 100%; border-collapse: collapse; }
          .status-dashboard-table th, .status-dashboard-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #e5e7eb; }
          .status-dashboard-table th { background: #f9fafb; font-weight: 600; color: #374151; }
          .deadline-overdue { color: #dc2626; font-weight: 700; }
          .deadline-soon { color: #d97706; font-weight: 600; }
        ',
      ],
      'status_dashboard_styles',
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Show jobs with upcoming deadlines at /jobhunter/deadlines.
   */
  public function deadlinesList(): array {
    $uid   = (int) $this->currentUser->id();
    $today = new \DateTime('today');

    $rows = $this->database->query(
      'SELECT sj.job_id, sj.deadline_date, sj.follow_up_date,
              jr.job_title, c.name AS company_name
       FROM {jobhunter_saved_jobs} sj
       JOIN {jobhunter_job_requirements} jr ON sj.job_id = jr.id
       LEFT JOIN {jobhunter_companies} c ON jr.company_id = c.id
       WHERE sj.uid = :uid AND sj.archived = 0 AND sj.deadline_date IS NOT NULL
       ORDER BY sj.deadline_date ASC',
      [':uid' => $uid]
    )->fetchAll(\PDO::FETCH_ASSOC);

    $table_rows = [];
    foreach ($rows as $row) {
      $job_id = (int) $row['job_id'];
      $dl     = $row['deadline_date'];
      $dl_dt  = new \DateTime($dl);
      $diff   = (int) $today->diff($dl_dt)->days;
      $past   = $dl_dt < $today;

      $urgency_class = '';
      $urgency_label = '';
      if ($past) {
        $urgency_class = 'deadline-overdue';
        $urgency_label = 'Overdue';
      }
      elseif ($diff <= 3) {
        $urgency_class = 'deadline-soon';
        $urgency_label = $diff === 0 ? 'Due today' : 'Due in ' . $diff . 'd';
      }

      $job_url = Url::fromRoute('job_hunter.job_view', ['job_id' => $job_id])->toString();
      $table_rows[] = [
        ['data' => '<a href="' . $job_url . '">' . htmlspecialchars($row['job_title']) . '</a>', 'allow_html' => TRUE],
        htmlspecialchars($row['company_name'] ?? ''),
        $dl,
        ['data' => '<span class="' . $urgency_class . '">' . htmlspecialchars($urgency_label ?: 'OK') . '</span>', 'allow_html' => TRUE],
        htmlspecialchars($row['follow_up_date'] ?? '—'),
      ];
    }

    $content = [
      '#type' => 'container',
      'heading' => ['#markup' => '<h2>Upcoming Deadlines</h2>'],
    ];

    if ($table_rows) {
      $content['table'] = [
        '#type'   => 'table',
        '#header' => ['Job', 'Company', 'Deadline Date', 'Status', 'Follow-up'],
        '#rows'   => $table_rows,
        '#attributes' => ['class' => ['deadlines-table']],
      ];
    }
    else {
      $content['empty'] = ['#markup' => '<p>No jobs with deadlines set. <a href="/jobhunter/my-jobs">View your saved jobs</a> to add deadlines.</p>'];
    }

    $content['#attached']['html_head'][] = [
      [
        '#tag'   => 'style',
        '#value' => '
          .deadlines-table { width: 100%; border-collapse: collapse; }
          .deadlines-table th, .deadlines-table td { padding: 10px 14px; text-align: left; border-bottom: 1px solid #e5e7eb; }
          .deadlines-table th { background: #f9fafb; font-weight: 600; color: #374151; }
          .deadline-overdue { color: #dc2626; font-weight: 700; }
          .deadline-soon { color: #d97706; font-weight: 600; }
        ',
      ],
      'deadlines_list_styles',
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Valid company interest status values.
   */
  const COMPANY_INTEREST_STATUSES = ['researching', 'interviewing', 'rejected', 'accepted'];

  /**
   * Renders the current user's company watchlist at /jobhunter/companies/my-list.
   */
  public function companyWatchlist(): array {
    $uid = (int) $this->currentUser()->id();

    try {
      $query = $this->database->select('jobhunter_company_interest', 'ci');
      $query->fields('ci', ['id', 'company_id', 'interest_level', 'culture_fit_score', 'status', 'changed']);
      $query->join('jobhunter_companies', 'c', 'ci.company_id = c.id');
      $query->addField('c', 'name', 'company_name');
      $query->condition('ci.uid', $uid);
      $query->orderBy('ci.interest_level', 'DESC');
      $query->orderBy('c.name', 'ASC');
      $rows_data = $query->execute()->fetchAll();
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('companyWatchlist failed: @error', ['@error' => $e->getMessage()]);
      $rows_data = [];
    }

    $status_badges = [
      'researching' => '#2196F3',
      'interviewing' => '#FF9800',
      'rejected' => '#F44336',
      'accepted' => '#4CAF50',
    ];

    $rows = [];
    foreach ($rows_data as $row) {
      $stars = str_repeat('★', (int) $row->interest_level) . str_repeat('☆', 5 - (int) $row->interest_level);
      $fit = $row->culture_fit_score !== NULL ? str_repeat('★', (int) $row->culture_fit_score) . str_repeat('☆', 5 - (int) $row->culture_fit_score) : $this->t('—');
      $color = $status_badges[$row->status] ?? '#999';
      $status_html = '<span style="background:' . $color . ';color:#fff;padding:2px 8px;border-radius:3px;font-size:0.85em;">'
        . htmlspecialchars(ucfirst((string) $row->status)) . '</span>';
      $rows[] = [
        'data' => [
          ['data' => Link::fromTextAndUrl($row->company_name, Url::fromRoute('job_hunter.company_interest_form', ['company_id' => $row->company_id]))->toRenderable()],
          ['data' => ['#markup' => $stars]],
          ['data' => is_string($fit) ? ['#markup' => $fit] : $fit],
          ['data' => ['#markup' => $status_html]],
          ['data' => Link::fromTextAndUrl($this->t('Edit'), Url::fromRoute('job_hunter.company_interest_form', ['company_id' => $row->company_id]))->toRenderable()],
        ],
      ];
    }

    $content = [
      'header' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('My Company Watchlist'),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Company'),
          $this->t('Interest'),
          $this->t('Culture Fit'),
          $this->t('Status'),
          $this->t('Actions'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No companies tracked yet. Visit a company page to start tracking.'),
        '#attributes' => ['class' => ['company-watchlist']],
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Renders the company interest tracking form (GET).
   */
  public function companyInterestForm($company_id): array {
    $uid = (int) $this->currentUser()->id();
    $company_id = (int) $company_id;

    // Load company name.
    $company = $this->database->select('jobhunter_companies', 'c')
      ->fields('c', ['id', 'name'])
      ->condition('c.id', $company_id)
      ->execute()
      ->fetchObject();

    if (!$company) {
      return $this->wrapWithNavigation([
        '#markup' => $this->t('Company not found.'),
      ]);
    }

    // Load existing interest row for pre-population.
    $existing = $this->database->select('jobhunter_company_interest', 'ci')
      ->fields('ci', ['interest_level', 'culture_fit_score', 'status', 'research_links', 'notes'])
      ->condition('ci.uid', $uid)
      ->condition('ci.company_id', $company_id)
      ->execute()
      ->fetchAssoc();

    // Generate CSRF token for the POST save route.
    $csrf_token = \Drupal::csrfToken()->get('jobhunter/companies/' . $company_id . '/interest/save');
    $form_action = Url::fromRoute('job_hunter.company_interest_save', ['company_id' => $company_id])->toString()
      . '?token=' . rawurlencode($csrf_token);

    $interest_val  = (int) ($existing['interest_level'] ?? 3);
    $culture_val   = $existing['culture_fit_score'] !== NULL ? (int) $existing['culture_fit_score'] : '';
    $status_val    = htmlspecialchars((string) ($existing['status'] ?? 'researching'));
    $links_val     = htmlspecialchars((string) ($existing['research_links'] ?? ''));
    $notes_val     = htmlspecialchars((string) ($existing['notes'] ?? ''));
    $company_name  = htmlspecialchars((string) $company->name);

    $status_options = '';
    foreach (self::COMPANY_INTEREST_STATUSES as $s) {
      $sel = $status_val === $s ? ' selected' : '';
      $status_options .= '<option value="' . $s . '"' . $sel . '>' . ucfirst($s) . '</option>';
    }

    $interest_options = '';
    for ($i = 1; $i <= 5; $i++) {
      $sel = $interest_val === $i ? ' selected' : '';
      $interest_options .= '<option value="' . $i . '"' . $sel . '>' . $i . ' ' . str_repeat('★', $i) . '</option>';
    }

    $culture_options = '<option value="">— not rated —</option>';
    for ($i = 1; $i <= 5; $i++) {
      $sel = ((string) $culture_val === (string) $i) ? ' selected' : '';
      $culture_options .= '<option value="' . $i . '"' . $sel . '>' . $i . ' ' . str_repeat('★', $i) . '</option>';
    }

    $html = <<<HTML
<div class="company-interest-form">
  <h2>Track: {$company_name}</h2>
  <form method="post" action="{$form_action}">
    <div style="margin-bottom:1em;">
      <label for="ci-interest"><strong>Interest Level</strong></label><br>
      <select name="interest_level" id="ci-interest">{$interest_options}</select>
    </div>
    <div style="margin-bottom:1em;">
      <label for="ci-culture"><strong>Culture Fit Score</strong> (optional)</label><br>
      <select name="culture_fit_score" id="ci-culture">{$culture_options}</select>
    </div>
    <div style="margin-bottom:1em;">
      <label for="ci-status"><strong>Status</strong></label><br>
      <select name="status" id="ci-status">{$status_options}</select>
    </div>
    <div style="margin-bottom:1em;">
      <label for="ci-links"><strong>Research Links</strong> (optional, comma-separated)</label><br>
      <input type="text" name="research_links" id="ci-links" value="{$links_val}" style="width:100%;max-width:500px;">
    </div>
    <div style="margin-bottom:1em;">
      <label for="ci-notes"><strong>Notes</strong> (optional)</label><br>
      <textarea name="notes" id="ci-notes" rows="5" style="width:100%;max-width:500px;">{$notes_val}</textarea>
    </div>
    <button type="submit" class="button button--primary">Save</button>
    &nbsp;
    <a href="/jobhunter/companies/my-list" class="button">Back to Watchlist</a>
  </form>
</div>
HTML;

    $content = ['#markup' => $html];
    return $this->wrapWithNavigation($content);
  }

  /**
   * Saves company interest data (POST, CSRF-protected via routing.yml split-route).
   */
  public function companyInterestSave($company_id): \Symfony\Component\HttpFoundation\Response {
    $uid = (int) $this->currentUser()->id();
    $company_id = (int) $company_id;

    $request = $this->requestStack->getCurrentRequest();

    // Validate company exists.
    $company_exists = $this->database->select('jobhunter_companies', 'c')
      ->fields('c', ['id'])
      ->condition('c.id', $company_id)
      ->execute()
      ->fetchField();

    if (!$company_exists) {
      return new \Symfony\Component\HttpFoundation\Response('Company not found.', 404);
    }

    // Sanitize inputs — uid always from session (SEC-3).
    $interest_level   = (int) $request->request->get('interest_level', 3);
    $culture_fit_raw  = $request->request->get('culture_fit_score', '');
    $status           = (string) $request->request->get('status', 'researching');
    $research_links   = strip_tags((string) $request->request->get('research_links', ''));
    $notes            = strip_tags((string) $request->request->get('notes', ''));

    // Validate ranges.
    if ($interest_level < 1 || $interest_level > 5) {
      $interest_level = 3;
    }
    $culture_fit = ($culture_fit_raw !== '' && $culture_fit_raw !== NULL)
      ? max(1, min(5, (int) $culture_fit_raw))
      : NULL;
    if (!in_array($status, self::COMPANY_INTEREST_STATUSES, TRUE)) {
      $status = 'researching';
    }

    $now = \Drupal::time()->getRequestTime();

    try {
      // UPSERT: check existing row then insert or update.
      $existing_id = $this->database->select('jobhunter_company_interest', 'ci')
        ->fields('ci', ['id'])
        ->condition('ci.uid', $uid)
        ->condition('ci.company_id', $company_id)
        ->execute()
        ->fetchField();

      if ($existing_id) {
        $this->database->update('jobhunter_company_interest')
          ->fields([
            'interest_level'    => $interest_level,
            'culture_fit_score' => $culture_fit,
            'status'            => $status,
            'research_links'    => $research_links,
            'notes'             => $notes,
            'changed'           => $now,
          ])
          ->condition('id', $existing_id)
          ->execute();
      }
      else {
        $this->database->insert('jobhunter_company_interest')
          ->fields([
            'uid'               => $uid,
            'company_id'        => $company_id,
            'interest_level'    => $interest_level,
            'culture_fit_score' => $culture_fit,
            'status'            => $status,
            'research_links'    => $research_links,
            'notes'             => $notes,
            'created'           => $now,
            'changed'           => $now,
          ])
          ->execute();
      }

      // Log uid + company_id only — never notes content (SEC-5).
      $this->getLogger('job_hunter')->info(
        'Company interest saved: uid=@uid company_id=@cid',
        ['@uid' => $uid, '@cid' => $company_id]
      );
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error(
        'companyInterestSave failed: uid=@uid company_id=@cid error=@error',
        ['@uid' => $uid, '@cid' => $company_id, '@error' => $e->getMessage()]
      );
      $this->messenger()->addError($this->t('Failed to save. Please try again.'));
      return new \Symfony\Component\HttpFoundation\RedirectResponse(
        Url::fromRoute('job_hunter.company_interest_form', ['company_id' => $company_id])->toString()
      );
    }

    $this->messenger()->addStatus($this->t('Company interest saved.'));
    return new \Symfony\Component\HttpFoundation\RedirectResponse(
      Url::fromRoute('job_hunter.company_watchlist')->toString()
    );
  }

  /**
   * Lists all companies the current user has researched.
   */
  public function companyResearchList(): array {
    $uid = (int) $this->currentUser()->id();

    $query = $this->database->select('jobhunter_company_research', 'cr');
    $query->join('jobhunter_companies', 'c', 'cr.company_id = c.id');
    $query->fields('cr', ['id', 'company_id', 'culture_fit_score', 'changed']);
    $query->fields('c', ['name', 'industry']);
    $query->condition('cr.uid', $uid);
    $query->orderBy('cr.changed', 'DESC');
    $rows = $query->execute()->fetchAll();

    if (empty($rows)) {
      $content = ['#markup' => '<p>No companies tracked yet.</p>'];
      return $this->wrapWithNavigation($content);
    }

    $header = ['Company', 'Industry', 'Culture Fit Score', 'Last Updated', 'Actions'];
    $table_rows = [];
    foreach ($rows as $row) {
      $company_name = htmlspecialchars($row->name ?? '', ENT_QUOTES, 'UTF-8');
      $industry     = htmlspecialchars($row->industry ?? '', ENT_QUOTES, 'UTF-8');
      $score        = ($row->culture_fit_score !== NULL) ? (int) $row->culture_fit_score : '—';
      $date         = $row->changed ? date('Y-m-d', (int) $row->changed) : '—';
      $cid          = (int) $row->company_id;
      $table_rows[] = [
        '<a href="/jobhunter/companies/' . $cid . '/research" rel="noopener noreferrer">' . $company_name . '</a>',
        $industry,
        $score,
        $date,
        '<a href="/jobhunter/companies/' . $cid . '/research" rel="noopener noreferrer">Edit</a>',
      ];
    }

    $header_html = '<tr><th>' . implode('</th><th>', $header) . '</th></tr>';
    $body_html = '';
    foreach ($table_rows as $tr) {
      $body_html .= '<tr><td>' . implode('</td><td>', $tr) . '</td></tr>';
    }

    $html = '<table class="company-research"><thead>' . $header_html . '</thead><tbody>' . $body_html . '</tbody></table>';
    $content = ['#markup' => $html];
    return $this->wrapWithNavigation($content);
  }

  /**
   * Renders the company research form (GET).
   */
  public function companyResearchForm($company_id): array|\Symfony\Component\HttpFoundation\Response {
    $uid        = (int) $this->currentUser()->id();
    $company_id = (int) $company_id;

    $company = $this->database->select('jobhunter_companies', 'c')
      ->fields('c', ['id', 'name', 'industry'])
      ->condition('c.id', $company_id)
      ->execute()
      ->fetchObject();

    if (!$company) {
      return new \Symfony\Component\HttpFoundation\Response('Company not found.', 404);
    }

    $existing = $this->database->select('jobhunter_company_research', 'cr')
      ->fields('cr', ['culture_fit_score', 'notes', 'research_links_json'])
      ->condition('cr.uid', $uid)
      ->condition('cr.company_id', $company_id)
      ->execute()
      ->fetchObject();

    $score_val = ($existing && $existing->culture_fit_score !== NULL) ? (int) $existing->culture_fit_score : '';
    $notes_val = $existing ? htmlspecialchars($existing->notes ?? '', ENT_QUOTES, 'UTF-8') : '';
    $links_val = '';
    if ($existing && !empty($existing->research_links_json)) {
      $links_arr = json_decode($existing->research_links_json, TRUE);
      if (is_array($links_arr)) {
        $links_val = htmlspecialchars(implode("\n", $links_arr), ENT_QUOTES, 'UTF-8');
      }
    }

    $company_name = htmlspecialchars($company->name, ENT_QUOTES, 'UTF-8');
    $token        = \Drupal::csrfToken()->get('jobhunter/companies/' . $company_id . '/research/save');
    $form_action  = Url::fromRoute('job_hunter.company_research_save', ['company_id' => $company_id])->toString()
      . '?token=' . rawurlencode($token);

    $html = <<<HTML
<div class="company-research-form">
  <h2>Research: {$company_name}</h2>
  <form method="post" action="{$form_action}">
    <div style="margin-bottom:1em;">
      <label for="cr-score"><strong>Culture Fit Score</strong> (0–10)</label><br>
      <input type="number" name="culture_fit_score" id="cr-score" min="0" max="10" value="{$score_val}" style="width:80px;">
    </div>
    <div style="margin-bottom:1em;">
      <label for="cr-links"><strong>Research links (one URL per line)</strong></label><br>
      <textarea name="research_links" id="cr-links" rows="4" style="width:100%;max-width:600px;">{$links_val}</textarea>
    </div>
    <div style="margin-bottom:1em;">
      <label for="cr-notes"><strong>Notes</strong> (optional)</label><br>
      <textarea name="notes" id="cr-notes" rows="6" style="width:100%;max-width:600px;">{$notes_val}</textarea>
    </div>
    <button type="submit" class="button button--primary">Save</button>
    &nbsp;
    <a href="/jobhunter/companies" class="button">Back to My Research</a>
  </form>
</div>
HTML;

    $content = ['#markup' => $html];
    return $this->wrapWithNavigation($content);
  }

  /**
   * Saves company research data (POST, CSRF-protected via routing.yml split-route).
   */
  public function companyResearchSave($company_id): \Symfony\Component\HttpFoundation\Response {
    $uid        = (int) $this->currentUser()->id();
    $company_id = (int) $company_id;

    $request = $this->requestStack->getCurrentRequest();

    $company_exists = $this->database->select('jobhunter_companies', 'c')
      ->fields('c', ['id'])
      ->condition('c.id', $company_id)
      ->execute()
      ->fetchField();

    if (!$company_exists) {
      return new \Symfony\Component\HttpFoundation\Response('Company not found.', 404);
    }

    // Validate culture_fit_score (SEC-4 range check).
    $score_raw = $request->request->get('culture_fit_score', '');
    if ($score_raw !== '' && $score_raw !== NULL) {
      $score = (int) $score_raw;
      if ($score < 0 || $score > 10) {
        return new \Symfony\Component\HttpFoundation\JsonResponse(
          ['error' => 'culture_fit_score must be 0–10'],
          422
        );
      }
      $culture_fit = $score;
    }
    else {
      $culture_fit = NULL;
    }

    // Validate research_links: http/https only (SEC-4).
    $links_raw  = (string) $request->request->get('research_links', '');
    $link_lines = array_filter(array_map('trim', explode("\n", $links_raw)));
    $clean_links = [];
    foreach ($link_lines as $url) {
      $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
      if (!in_array($scheme, ['http', 'https'], TRUE)) {
        return new \Symfony\Component\HttpFoundation\JsonResponse(
          ['error' => 'research_links must be http or https URLs'],
          422
        );
      }
      $clean_links[] = $url;
    }
    $links_json = !empty($clean_links) ? json_encode(array_values($clean_links)) : NULL;

    // Strip HTML from notes (SEC-5).
    $notes = strip_tags((string) $request->request->get('notes', ''));
    if ($notes === '') {
      $notes = NULL;
    }

    $now = \Drupal::time()->getRequestTime();

    try {
      $existing_id = $this->database->select('jobhunter_company_research', 'cr')
        ->fields('cr', ['id'])
        ->condition('cr.uid', $uid)
        ->condition('cr.company_id', $company_id)
        ->execute()
        ->fetchField();

      if ($existing_id) {
        $this->database->update('jobhunter_company_research')
          ->fields([
            'culture_fit_score'   => $culture_fit,
            'notes'               => $notes,
            'research_links_json' => $links_json,
            'changed'             => $now,
          ])
          ->condition('id', $existing_id)
          ->execute();
      }
      else {
        $this->database->insert('jobhunter_company_research')
          ->fields([
            'uid'                 => $uid,
            'company_id'          => $company_id,
            'culture_fit_score'   => $culture_fit,
            'notes'               => $notes,
            'research_links_json' => $links_json,
            'created'             => $now,
            'changed'             => $now,
          ])
          ->execute();
      }

      // Log uid + company_id only — never notes content (SEC-6).
      $this->getLogger('job_hunter')->info(
        'Company research saved: uid=@uid company_id=@cid',
        ['@uid' => $uid, '@cid' => $company_id]
      );
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error(
        'companyResearchSave failed: uid=@uid company_id=@cid error=@error',
        ['@uid' => $uid, '@cid' => $company_id, '@error' => $e->getMessage()]
      );
      $this->messenger()->addError($this->t('Failed to save. Please try again.'));
      return new \Symfony\Component\HttpFoundation\RedirectResponse(
        Url::fromRoute('job_hunter.company_research_form', ['company_id' => $company_id])->toString()
      );
    }

    $this->messenger()->addStatus($this->t('Company research saved.'));
    return new \Symfony\Component\HttpFoundation\RedirectResponse(
      Url::fromRoute('job_hunter.company_research_list')->toString()
    );
  }

  /**
   * Valid contact relationship types.
   *
   * Supports the original brief plus PM-approved extensions.
   */
  const CONTACT_RELATIONSHIP_TYPES = ['warm', 'cold', 'referral', 'recruiter', 'hiring_manager', 'connection'];

  /**
   * Valid contact referral status values.
   */
  const CONTACT_REFERRAL_STATUSES = ['none', 'requested', 'pending', 'provided'];

  /**
   * Resolve the preferred contact name column for the current schema.
   */
  protected function getContactNameField(): string {
    return $this->database->schema()->fieldExists('jobhunter_contacts', 'name') ? 'name' : 'full_name';
  }

  /**
   * Resolve the preferred contact title column for the current schema.
   */
  protected function getContactTitleField(): string {
    return $this->database->schema()->fieldExists('jobhunter_contacts', 'title') ? 'title' : 'job_title';
  }

  /**
   * Load company options for contact forms.
   *
   * @return array<int, string>
   *   Company options keyed by company id.
   */
  protected function getContactCompanyOptions(): array {
    $companies = $this->database->select('jobhunter_companies', 'c')
      ->fields('c', ['id', 'name'])
      ->orderBy('c.name', 'ASC')
      ->execute()
      ->fetchAllKeyed();

    return array_map('strval', $companies);
  }

  /**
   * Normalize contact relationship values across schema generations.
   */
  protected function normalizeContactRelationshipType(string $relationship_type): string {
    $normalized = [
      'colleague' => 'connection',
      'friend' => 'connection',
      'alumni' => 'connection',
      'other' => 'cold',
    ][trim($relationship_type)] ?? trim($relationship_type);

    if (!in_array($normalized, self::CONTACT_RELATIONSHIP_TYPES, TRUE)) {
      return 'connection';
    }

    return $normalized;
  }

  /**
   * Normalize legacy referral statuses to the current contract.
   */
  protected function normalizeContactReferralStatus(string $referral_status): string {
    $normalized = [
      'referred' => 'provided',
      'pending-referral' => 'pending',
    ][trim($referral_status)] ?? trim($referral_status);

    if (!in_array($normalized, self::CONTACT_REFERRAL_STATUSES, TRUE)) {
      return 'none';
    }

    return $normalized;
  }

  /**
   * Try to resolve a company id from a legacy company name snapshot.
   */
  protected function resolveCompanyIdFromLegacyName(?string $company_name): ?int {
    $company_name = trim((string) $company_name);
    if ($company_name === '') {
      return NULL;
    }

    $company_id = $this->database->select('jobhunter_companies', 'c')
      ->fields('c', ['id'])
      ->condition('c.name', $company_name)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $company_id !== FALSE ? (int) $company_id : NULL;
  }

  /**
   * Renders the contacts list at /jobhunter/contacts.
   */
  public function contactsList(): array {
    $uid = (int) $this->currentUser()->id();
    $contact_name_field = $this->getContactNameField();
    $contact_title_field = $this->getContactTitleField();
    $contact_has_company_id = $this->database->schema()->fieldExists('jobhunter_contacts', 'company_id');
    $contact_has_company_name = $this->database->schema()->fieldExists('jobhunter_contacts', 'company_name');

    try {
      $query = $this->database->select('jobhunter_contacts', 'ct');
      $query->fields('ct', ['id', $contact_name_field, $contact_title_field, 'relationship_type', 'last_contact_date', 'referral_status', 'changed']);
      if ($contact_has_company_id) {
        $query->addField('ct', 'company_id');
        $query->leftJoin('jobhunter_companies', 'c', 'ct.company_id = c.id');
        $query->addField('c', 'name', 'company_display_name');
      }
      if ($contact_has_company_name) {
        $query->addField('ct', 'company_name', 'legacy_company_name');
      }
      $contacts = $query
        ->condition('ct.uid', $uid)
        ->orderBy('ct.' . $contact_name_field, 'ASC')
        ->execute()
        ->fetchAll();
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('contactsList failed: @error', ['@error' => $e->getMessage()]);
      $contacts = [];
    }

    $rows = [];
    foreach ($contacts as $ct) {
      $edit_url = Url::fromRoute('job_hunter.contacts_edit', ['contact_id' => $ct->id])->toString();
      $del_csrf  = \Drupal::csrfToken()->get('jobhunter/contacts/' . $ct->id . '/delete');
      $del_url   = Url::fromRoute('job_hunter.contacts_delete', ['contact_id' => $ct->id])->toString() . '?token=' . rawurlencode($del_csrf);
      $company_display = (string) ($ct->company_display_name ?? $ct->legacy_company_name ?? '—');
      $relationship_label = ucwords(str_replace('_', ' ', str_replace('-', ' ', (string) $ct->relationship_type)));
      $ref_badge = '';
      if ($ct->referral_status === 'provided') {
        $ref_badge = ' <span style="background:#28a745;color:#fff;padding:2px 6px;border-radius:4px;font-size:0.8em;">Provided</span>';
      }
      elseif ($ct->referral_status === 'pending') {
        $ref_badge = ' <span style="background:#fd7e14;color:#fff;padding:2px 6px;border-radius:4px;font-size:0.8em;">Pending</span>';
      }
      elseif ($ct->referral_status === 'requested') {
        $ref_badge = ' <span style="background:#0d6efd;color:#fff;padding:2px 6px;border-radius:4px;font-size:0.8em;">Requested</span>';
      }
      $delete_form = '<form method="post" action="' . htmlspecialchars($del_url) . '" style="display:inline;margin:0;">'
        . '<button type="submit" class="link-button" onclick="return confirm(\'Delete this contact?\')">Delete</button>'
        . '</form>';
      $rows[] = [
        'data' => [
          ['data' => ['#markup' => htmlspecialchars((string) ($ct->{$contact_name_field} ?? ''))]],
          ['data' => ['#markup' => htmlspecialchars($company_display)]],
          ['data' => ['#markup' => htmlspecialchars((string) ($ct->{$contact_title_field} ?? '—'))]],
          ['data' => ['#markup' => htmlspecialchars($relationship_label)]],
          ['data' => ['#markup' => htmlspecialchars((string) ($ct->last_contact_date ?? '—'))]],
          ['data' => ['#markup' => htmlspecialchars(ucfirst(str_replace('-', ' ', (string) $ct->referral_status))) . $ref_badge]],
          ['data' => ['#markup' => '<a href="' . htmlspecialchars($edit_url) . '">Edit</a> | ' . $delete_form]],
        ],
      ];
    }

    $add_url = Url::fromRoute('job_hunter.contacts_add')->toString();

    $content = [
      'add_link' => ['#markup' => '<p><a href="' . htmlspecialchars($add_url) . '" class="button">+ Add Contact</a></p><style>.link-button{background:none;border:none;color:#0d6efd;padding:0;font:inherit;cursor:pointer;text-decoration:underline;}</style>'],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Name'), $this->t('Company'), $this->t('Title'), $this->t('Relationship'), $this->t('Last Contact'), $this->t('Referral Status'), $this->t('Actions')],
        '#rows' => $rows,
        '#empty' => $this->t('No contacts yet. Click "Add Contact" to get started.'),
        '#attributes' => ['class' => ['contacts-list']],
      ],
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Renders add/edit contact form (GET). Used for both add (/contacts/add) and edit (/contacts/{id}/edit).
   */
  public function contactForm($contact_id = NULL): array {
    $uid = (int) $this->currentUser()->id();
    $contact_id = $contact_id !== NULL ? (int) $contact_id : NULL;
    $contact_name_field = $this->getContactNameField();
    $contact_title_field = $this->getContactTitleField();

    $existing = NULL;
    if ($contact_id !== NULL) {
      $existing = $this->database->select('jobhunter_contacts', 'ct')
        ->fields('ct')
        ->condition('ct.id', $contact_id)
        ->condition('ct.uid', $uid)
        ->execute()
        ->fetchAssoc();
      if (!$existing) {
        return $this->wrapWithNavigation(['#markup' => $this->t('Contact not found.')]);
      }
    }

    // Load saved jobs for the job-link section (edit mode only).
    $saved_jobs = [];
    $linked_job_ids = [];
    if ($contact_id !== NULL) {
      try {
        $saved_jobs = $this->database->select('jobhunter_saved_jobs', 'j')
          ->fields('j', ['id', 'title'])
          ->condition('j.uid', $uid)
          ->orderBy('j.title')
          ->execute()
          ->fetchAllKeyed(0, 1);
      }
      catch (\Exception $e) {
        $saved_jobs = [];
      }
      try {
        $linked_job_ids = $this->database->select('jobhunter_contact_job_links', 'l')
          ->fields('l', ['saved_job_id'])
          ->condition('l.uid', $uid)
          ->condition('l.contact_id', $contact_id)
          ->execute()
          ->fetchCol();
      }
      catch (\Exception $e) {
        $linked_job_ids = [];
      }
    }

    $company_options = $this->getContactCompanyOptions();
    if (empty($company_options)) {
      return $this->wrapWithNavigation([
        '#markup' => $this->t('No companies are available yet. Add or import a company before creating contacts.'),
      ]);
    }

    $save_csrf = \Drupal::csrfToken()->get('jobhunter/contacts/save');
    $save_url  = Url::fromRoute('job_hunter.contacts_save')->toString() . '?token=' . rawurlencode($save_csrf);

    $resolved_company_id = isset($existing['company_id']) && $existing['company_id'] !== NULL
      ? (int) $existing['company_id']
      : $this->resolveCompanyIdFromLegacyName($existing['company_name'] ?? NULL);
    $full_name_val  = htmlspecialchars((string) ($existing[$contact_name_field] ?? $existing['full_name'] ?? ''));
    $job_title_val  = htmlspecialchars((string) ($existing[$contact_title_field] ?? $existing['job_title'] ?? ''));
    $notes_val      = htmlspecialchars((string) ($existing['notes'] ?? ''));
    $rel_val        = $this->normalizeContactRelationshipType((string) ($existing['relationship_type'] ?? 'connection'));
    $id_field       = $contact_id !== NULL ? '<input type="hidden" name="contact_id" value="' . $contact_id . '">' : '';
    $lcd_val        = htmlspecialchars((string) ($existing['last_contact_date'] ?? ''));
    $ref_val        = $this->normalizeContactReferralStatus((string) ($existing['referral_status'] ?? 'none'));
    $email_val      = htmlspecialchars((string) ($existing['email'] ?? ''));
    $linkedin_val   = htmlspecialchars((string) ($existing['linkedin_url'] ?? ''));

    $rel_options = '';
    foreach (self::CONTACT_RELATIONSHIP_TYPES as $r) {
      $sel = ($rel_val === $r) ? ' selected' : '';
      $rel_options .= '<option value="' . $r . '"' . $sel . '>' . htmlspecialchars(ucwords(str_replace('_', ' ', str_replace('-', ' ', $r)))) . '</option>';
    }

    $ref_options = '';
    foreach (self::CONTACT_REFERRAL_STATUSES as $s) {
      $sel = ($ref_val === $s) ? ' selected' : '';
      $ref_options .= '<option value="' . $s . '"' . $sel . '>' . htmlspecialchars(ucfirst(str_replace('-', ' ', $s))) . '</option>';
    }

    $company_select_options = '<option value="">— Select a company —</option>';
    foreach ($company_options as $company_option_id => $company_label) {
      $sel = ($resolved_company_id === (int) $company_option_id) ? ' selected' : '';
      $company_select_options .= '<option value="' . (int) $company_option_id . '"' . $sel . '>' . htmlspecialchars($company_label) . '</option>';
    }

    $heading = $contact_id !== NULL ? 'Edit Contact' : 'Add Contact';

    // Build job-link section (edit mode only).
    $job_link_html = '';
    if ($contact_id !== NULL) {
      $link_csrf = \Drupal::csrfToken()->get('jobhunter/contacts/' . $contact_id . '/link-job');
      $link_url  = Url::fromRoute('job_hunter.contact_job_link_save', ['contact_id' => $contact_id])->toString() . '?token=' . rawurlencode($link_csrf);
      $job_options = '<option value="">— Select a job —</option>';
      foreach ($saved_jobs as $jid => $jtitle) {
        $job_options .= '<option value="' . (int) $jid . '">' . htmlspecialchars((string) $jtitle) . '</option>';
      }
      $linked_list = '';
      if (!empty($linked_job_ids)) {
        foreach ($linked_job_ids as $ljid) {
          $jlabel = htmlspecialchars((string) ($saved_jobs[(int) $ljid] ?? 'Job #' . $ljid));
          $linked_list .= '<li>' . $jlabel . '</li>';
        }
        $linked_list = '<ul style="margin:4px 0 8px 0;">' . $linked_list . '</ul>';
      }
      else {
        $linked_list = '<p style="font-style:italic;color:#666;">No linked jobs yet.</p>';
      }
      $job_link_html = <<<JLHTML
<div style="margin-top:2em;padding:16px;background:#f8f9fa;border-radius:6px;">
  <h3 style="margin:0 0 8px 0;">Linked Jobs</h3>
  {$linked_list}
  <form method="post" action="{$link_url}" style="margin-top:8px;">
    <label><strong>Link to a saved job:</strong></label><br>
    <select name="saved_job_id" style="width:100%;max-width:400px;">{$job_options}</select>
    <br><br>
    <button type="submit" class="button">Add Link</button>
  </form>
</div>
JLHTML;
    }

    $html = <<<HTML
<div class="contact-form">
  <h2>{$heading}</h2>
  <form method="post" action="{$save_url}">
    {$id_field}
    <div style="margin-bottom:1em;">
      <label><strong>Name *</strong></label><br>
      <input type="text" name="name" value="{$full_name_val}" required style="width:100%;max-width:400px;">
    </div>
    <div style="margin-bottom:1em;">
      <label><strong>Company *</strong></label><br>
      <select name="company_id" required style="width:100%;max-width:400px;">{$company_select_options}</select>
    </div>
    <div style="margin-bottom:1em;">
      <label><strong>Title</strong> (optional)</label><br>
      <input type="text" name="title" value="{$job_title_val}" style="width:100%;max-width:400px;">
    </div>
    <div style="margin-bottom:1em;">
      <label><strong>Relationship Type</strong></label><br>
      <select name="relationship_type">{$rel_options}</select>
    </div>
    <div style="margin-bottom:1em;">
      <label><strong>Last Contact Date</strong> (optional)</label><br>
      <input type="date" name="last_contact_date" value="{$lcd_val}" style="width:100%;max-width:400px;">
    </div>
    <div style="margin-bottom:1em;">
      <label><strong>Referral Status</strong></label><br>
      <select name="referral_status" style="width:100%;max-width:400px;">{$ref_options}</select>
    </div>
    <div style="margin-bottom:1em;">
      <label><strong>Notes</strong> (optional)</label><br>
      <textarea name="notes" rows="4" style="width:100%;max-width:400px;">{$notes_val}</textarea>
    </div>
    <div style="margin-bottom:1em;">
      <label><strong>Email</strong> (optional)</label><br>
      <input type="email" name="email" value="{$email_val}" style="width:100%;max-width:400px;" placeholder="jane@example.com">
    </div>
    <div style="margin-bottom:1em;">
      <label><strong>LinkedIn URL</strong> (optional)</label><br>
      <input type="url" name="linkedin_url" value="{$linkedin_val}" style="width:100%;max-width:400px;" placeholder="https://linkedin.com/in/username">
    </div>
    <button type="submit" class="button button--primary">Save</button>
    &nbsp;<a href="/jobhunter/contacts" class="button">Cancel</a>
  </form>
  {$job_link_html}
</div>
HTML;

    return $this->wrapWithNavigation(['#markup' => $html]);
  }

  /**
   * Saves (create or update) a contact (POST, CSRF-protected).
   */
  public function contactSave(): \Symfony\Component\HttpFoundation\Response {
    $uid     = (int) $this->currentUser()->id();
    $request = $this->requestStack->getCurrentRequest();
    $contact_name_field = $this->getContactNameField();
    $contact_title_field = $this->getContactTitleField();
    $contact_has_full_name = $this->database->schema()->fieldExists('jobhunter_contacts', 'full_name');
    $contact_has_job_title = $this->database->schema()->fieldExists('jobhunter_contacts', 'job_title');
    $contact_has_company_id = $this->database->schema()->fieldExists('jobhunter_contacts', 'company_id');
    $contact_has_company_name = $this->database->schema()->fieldExists('jobhunter_contacts', 'company_name');

    $contact_id        = $request->request->get('contact_id', NULL);
    $contact_id        = ($contact_id !== NULL && $contact_id !== '') ? (int) $contact_id : NULL;
    $full_name         = strip_tags((string) $request->request->get('name', $request->request->get('full_name', '')));
    $job_title         = strip_tags((string) $request->request->get('title', $request->request->get('job_title', '')));
    $company_id        = (int) $request->request->get('company_id', 0);
    $rel_type          = $this->normalizeContactRelationshipType((string) $request->request->get('relationship_type', 'connection'));
    $notes             = strip_tags((string) $request->request->get('notes', ''));
    $last_contact_date = preg_replace('/[^0-9\-]/', '', (string) $request->request->get('last_contact_date', ''));
    $referral_status   = $this->normalizeContactReferralStatus((string) $request->request->get('referral_status', 'none'));
    $email_raw         = trim((string) $request->request->get('email', ''));
    $linkedin_raw      = trim((string) $request->request->get('linkedin_url', ''));
    $redirect_route = $contact_id !== NULL
      ? Url::fromRoute('job_hunter.contacts_edit', ['contact_id' => $contact_id])->toString()
      : Url::fromRoute('job_hunter.contacts_add')->toString();

    if ($full_name === '') {
      $this->messenger()->addError($this->t('Contact name is required.'));
      return new \Symfony\Component\HttpFoundation\RedirectResponse(
        $redirect_route
      );
    }

    if ($company_id <= 0) {
      $this->messenger()->addError($this->t('Company is required.'));
      return new \Symfony\Component\HttpFoundation\RedirectResponse(
        $redirect_route
      );
    }

    if ($last_contact_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $last_contact_date)) {
      $this->messenger()->addError($this->t('Last contact date must use YYYY-MM-DD format.'));
      return new \Symfony\Component\HttpFoundation\RedirectResponse(
        $redirect_route
      );
    }

    // SEC-4: validate email format if provided.
    $email = '';
    if ($email_raw !== '') {
      if (!filter_var($email_raw, FILTER_VALIDATE_EMAIL)) {
        $this->messenger()->addError($this->t('Invalid email address.'));
        return new \Symfony\Component\HttpFoundation\RedirectResponse($redirect_route);
      }
      $email = $email_raw;
    }

    // SEC-4: validate LinkedIn URL — scheme must be http/https; reject javascript:/data:/etc with 422.
    $linkedin_url = '';
    if ($linkedin_raw !== '') {
      $li_scheme = strtolower(parse_url($linkedin_raw, PHP_URL_SCHEME) ?? '');
      if (!in_array($li_scheme, ['http', 'https'], TRUE)) {
        return new \Symfony\Component\HttpFoundation\JsonResponse(
          ['error' => 'linkedin_url scheme must be http or https'],
          422
        );
      }
      if (strpos($linkedin_raw, 'linkedin.com') === FALSE) {
        $this->messenger()->addError($this->t('LinkedIn URL must be a linkedin.com URL.'));
        return new \Symfony\Component\HttpFoundation\RedirectResponse($redirect_route);
      }
      $linkedin_url = $linkedin_raw;
    }

    $company = $this->database->select('jobhunter_companies', 'c')
      ->fields('c', ['id', 'name'])
      ->condition('c.id', $company_id)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if (!$company) {
      $this->messenger()->addError($this->t('Selected company was not found.'));
      return new \Symfony\Component\HttpFoundation\RedirectResponse(
        $redirect_route
      );
    }

    $now = \Drupal::time()->getRequestTime();
    $fields = [
      $contact_name_field => $full_name,
      $contact_title_field => $job_title ?: NULL,
      'relationship_type' => $rel_type,
      'notes' => $notes ?: NULL,
      'last_contact_date' => $last_contact_date ?: NULL,
      'referral_status' => $referral_status,
      'changed' => $now,
    ];

    if ($contact_has_full_name) {
      $fields['full_name'] = $full_name;
    }
    if ($contact_has_job_title) {
      $fields['job_title'] = $job_title ?: NULL;
    }
    if ($contact_has_company_id) {
      $fields['company_id'] = (int) $company->id;
    }
    if ($contact_has_company_name) {
      $fields['company_name'] = (string) $company->name;
    }
    if ($this->database->schema()->fieldExists('jobhunter_contacts', 'email')) {
      $fields['email'] = $email ?: NULL;
    }
    if ($this->database->schema()->fieldExists('jobhunter_contacts', 'linkedin_url')) {
      $fields['linkedin_url'] = $linkedin_url ?: NULL;
    }

    try {
      if ($contact_id !== NULL) {
        // Update — verify ownership first (SEC-3).
        $owned = $this->database->select('jobhunter_contacts', 'ct')
          ->fields('ct', ['id'])
          ->condition('ct.id', $contact_id)
          ->condition('ct.uid', $uid)
          ->execute()
          ->fetchField();
        if (!$owned) {
          $this->messenger()->addError($this->t('Contact not found.'));
          return new \Symfony\Component\HttpFoundation\RedirectResponse(
            Url::fromRoute('job_hunter.contacts_list')->toString()
          );
        }
        $this->database->update('jobhunter_contacts')
          ->fields($fields)
          ->condition('id', $contact_id)
          ->condition('uid', $uid)
          ->execute();
        // SEC-5: log uid + id only.
        $this->getLogger('job_hunter')->info('Contact updated: uid=@uid id=@id', ['@uid' => $uid, '@id' => $contact_id]);
        $redirect_id = $contact_id;
      }
      else {
        $fields['uid'] = $uid;
        $fields['created'] = $now;
        $redirect_id = $this->database->insert('jobhunter_contacts')
          ->fields($fields)
          ->execute();
        // SEC-5: log uid + id only.
        $this->getLogger('job_hunter')->info('Contact created: uid=@uid id=@id', ['@uid' => $uid, '@id' => $redirect_id]);
      }
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('contactSave failed: uid=@uid error=@error', ['@uid' => $uid, '@error' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Failed to save contact. Please try again.'));
      return new \Symfony\Component\HttpFoundation\RedirectResponse(
        Url::fromRoute('job_hunter.contacts_list')->toString()
      );
    }

    $this->messenger()->addStatus($this->t('Contact saved.'));
    return new \Symfony\Component\HttpFoundation\RedirectResponse(
      Url::fromRoute('job_hunter.contacts_edit', ['contact_id' => $redirect_id])->toString()
    );
  }

  /**
   * Deletes a contact (POST, CSRF-protected). Verifies uid ownership.
   */
  public function contactDelete($contact_id): \Symfony\Component\HttpFoundation\Response {
    $uid        = (int) $this->currentUser()->id();
    $contact_id = (int) $contact_id;

    try {
      // SEC-3: verify ownership.
      $owned = $this->database->select('jobhunter_contacts', 'ct')
        ->fields('ct', ['id'])
        ->condition('ct.id', $contact_id)
        ->condition('ct.uid', $uid)
        ->execute()
        ->fetchField();

      if (!$owned) {
        $this->messenger()->addError($this->t('Contact not found.'));
        return new \Symfony\Component\HttpFoundation\RedirectResponse(
          Url::fromRoute('job_hunter.contacts_list')->toString()
        );
      }

      $this->database->delete('jobhunter_contacts')
        ->condition('id', $contact_id)
        ->condition('uid', $uid)
        ->execute();

      // SEC-5: log uid + id only.
      $this->getLogger('job_hunter')->info('Contact deleted: uid=@uid id=@id', ['@uid' => $uid, '@id' => $contact_id]);
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('contactDelete failed: uid=@uid id=@id error=@error', ['@uid' => $uid, '@id' => $contact_id, '@error' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Failed to delete contact.'));
    }

    $this->messenger()->addStatus($this->t('Contact deleted.'));
    return new \Symfony\Component\HttpFoundation\RedirectResponse(
      Url::fromRoute('job_hunter.contacts_list')->toString()
    );
  }

  /**
   * Links a contact to a saved job (POST, CSRF-protected).
   * Route: /jobhunter/contacts/{contact_id}/link-job
   */
  public function contactJobLinkSave($contact_id): \Symfony\Component\HttpFoundation\Response {
    $uid        = (int) $this->currentUser()->id();
    $contact_id = (int) $contact_id;
    $request    = $this->requestStack->getCurrentRequest();
    $saved_job_id = (int) $request->request->get('saved_job_id', 0);

    if ($saved_job_id === 0) {
      $this->messenger()->addError($this->t('Please select a job to link.'));
      return new \Symfony\Component\HttpFoundation\RedirectResponse(
        Url::fromRoute('job_hunter.contacts_edit', ['contact_id' => $contact_id])->toString()
      );
    }

    try {
      // SEC-3: verify contact ownership.
      $contact_owned = $this->database->select('jobhunter_contacts', 'ct')
        ->fields('ct', ['id'])
        ->condition('ct.id', $contact_id)
        ->condition('ct.uid', $uid)
        ->execute()
        ->fetchField();
      if (!$contact_owned) {
        $this->messenger()->addError($this->t('Contact not found.'));
        return new \Symfony\Component\HttpFoundation\RedirectResponse(
          Url::fromRoute('job_hunter.contacts_list')->toString()
        );
      }

      // SEC-3: verify saved job ownership.
      $job_owned = $this->database->select('jobhunter_saved_jobs', 'j')
        ->fields('j', ['id'])
        ->condition('j.id', $saved_job_id)
        ->condition('j.uid', $uid)
        ->execute()
        ->fetchField();
      if (!$job_owned) {
        $this->messenger()->addError($this->t('Saved job not found.'));
        return new \Symfony\Component\HttpFoundation\RedirectResponse(
          Url::fromRoute('job_hunter.contacts_edit', ['contact_id' => $contact_id])->toString()
        );
      }

      // Upsert: insert only if link does not already exist.
      $exists = $this->database->select('jobhunter_contact_job_links', 'l')
        ->fields('l', ['id'])
        ->condition('l.contact_id', $contact_id)
        ->condition('l.saved_job_id', $saved_job_id)
        ->condition('l.uid', $uid)
        ->execute()
        ->fetchField();
      if (!$exists) {
        $this->database->insert('jobhunter_contact_job_links')
          ->fields([
            'uid'          => $uid,
            'contact_id'   => $contact_id,
            'saved_job_id' => $saved_job_id,
            'created'      => \Drupal::time()->getRequestTime(),
          ])
          ->execute();
        $this->getLogger('job_hunter')->info('Contact job link created: uid=@uid contact_id=@cid job_id=@jid', [
          '@uid' => $uid, '@cid' => $contact_id, '@jid' => $saved_job_id,
        ]);
        $this->messenger()->addStatus($this->t('Job link added.'));
      }
      else {
        $this->messenger()->addStatus($this->t('This job is already linked to this contact.'));
      }
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('contactJobLinkSave failed: uid=@uid error=@error', ['@uid' => $uid, '@error' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Failed to link job. Please try again.'));
    }

    return new \Symfony\Component\HttpFoundation\RedirectResponse(
      Url::fromRoute('job_hunter.contacts_edit', ['contact_id' => $contact_id])->toString()
    );
  }

  /**
   * Valid job-board source keys for SEC-4 allowlist.
   */
  const VALID_SOURCE_KEYS = ['forseti', 'serpapi', 'adzuna', 'usajobs'];

  /**
   * Labels for supported job-board preference source keys.
   */
  const SOURCE_KEY_LABELS = [
    'forseti' => 'Forseti Jobs',
    'serpapi' => 'Google Jobs (SerpAPI)',
    'adzuna' => 'Adzuna',
    'usajobs' => 'USAJobs',
  ];

  /**
   * Valid remote preference values.
   */
  const VALID_REMOTE_PREFS = ['any', 'remote', 'hybrid', 'onsite'];

  /**
   * Renders the job-board source preferences form at /jobhunter/preferences.
   */
  public function sourcePreferencesForm(): array {
    $uid = (int) $this->currentUser->id();
    try {
      $prefs = $this->database->select('jobhunter_source_preferences', 'sp')
        ->fields('sp', ['sources_enabled', 'min_salary', 'remote_preference', 'location_radius_km'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchObject();
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('sourcePreferencesForm load failed: uid=@uid error=@error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      $prefs = NULL;
    }

    $enabled_sources = ['forseti'];
    if ($prefs && !empty($prefs->sources_enabled)) {
      $decoded = json_decode($prefs->sources_enabled, TRUE);
      if (is_array($decoded)) {
        $enabled_sources = array_values(array_filter(array_map(
          static fn($source) => strtolower(trim((string) $source)),
          $decoded
        ), static fn($source) => in_array($source, self::VALID_SOURCE_KEYS, TRUE)));
        if (!empty($decoded) && empty($enabled_sources)) {
          $enabled_sources = ['forseti'];
        }
      }
    }
    $f_min_salary = $prefs ? (int) ($prefs->min_salary ?? 0) : 0;
    $f_remote_raw = $prefs ? (string) ($prefs->remote_preference ?? 'any') : 'any';
    $f_remote = $f_remote_raw === 'remote_only' ? 'remote' : htmlspecialchars($f_remote_raw);
    $f_radius     = $prefs ? (int) ($prefs->location_radius_km ?? 0) : 0;

    $save_url  = Url::fromRoute('job_hunter.preferences_save')->toString();
    $csrf_token = \Drupal::csrfToken()->get('jobhunter/preferences/save');

    $source_checkboxes = '';
    foreach (self::VALID_SOURCE_KEYS as $key) {
      $checked = in_array($key, $enabled_sources, TRUE) ? ' checked' : '';
      $label = self::SOURCE_KEY_LABELS[$key] ?? ucfirst($key);
      $source_checkboxes .= '<label class="source-checkbox-label">'
        . '<input type="checkbox" name="sources_enabled[]" value="' . htmlspecialchars($key) . '"' . $checked . '> '
        . $label . '</label> ';
    }

    $remote_options = '';
    $remote_labels = ['any' => 'Any', 'remote' => 'Remote Only', 'hybrid' => 'Hybrid', 'onsite' => 'On-site'];
    foreach ($remote_labels as $val => $lbl) {
      $sel = ($f_remote === $val) ? ' selected' : '';
      $remote_options .= '<option value="' . htmlspecialchars($val) . '"' . $sel . '>' . $lbl . '</option>';
    }

    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['source-preferences-form']],
      '#markup' => '
<h2>Job Board Preferences</h2>
<form id="source-prefs-form" method="post">
  <input type="hidden" name="token" value="' . htmlspecialchars($csrf_token) . '">
  <div class="prefs-field-row">
    <label><strong>Job Boards (sources enabled)</strong></label>
    <div class="source-checkboxes">' . $source_checkboxes . '</div>
    <p class="prefs-hint">Uncheck boards to exclude them from job discovery.</p>
  </div>
  <div class="prefs-field-row">
    <label for="pref-min-salary">Minimum Salary ($)</label>
    <input type="number" id="pref-min-salary" name="min_salary" value="' . $f_min_salary . '" min="0" max="999999999" step="1000" />
  </div>
  <div class="prefs-field-row">
    <label for="pref-remote">Remote Preference</label>
    <select id="pref-remote" name="remote_preference">' . $remote_options . '</select>
  </div>
  <div class="prefs-field-row">
    <label for="pref-radius">Location Radius (km)</label>
    <input type="number" id="pref-radius" name="location_radius_km" value="' . $f_radius . '" min="1" max="500" />
    <p class="prefs-hint">Leave 0 to use no radius constraint.</p>
  </div>
  <button type="button" id="pref-save-btn" data-save-url="' . htmlspecialchars($save_url) . '" data-token="' . htmlspecialchars($csrf_token) . '">Save Preferences</button>
  <div id="pref-status-msg"></div>
</form>
<style>
  .source-preferences-form { max-width: 640px; margin: 20px auto; padding: 24px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #667eea; }
  .source-preferences-form h2 { margin: 0 0 20px 0; }
  .prefs-field-row { margin-bottom: 18px; }
  .prefs-field-row label { display: block; font-weight: 600; color: #444; margin-bottom: 6px; }
  .prefs-field-row input[type="number"], .prefs-field-row select { padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.95em; width: 200px; }
  .source-checkboxes { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 6px; }
  .source-checkbox-label { font-weight: normal; cursor: pointer; }
  .prefs-hint { font-size: 0.82em; color: #9ca3af; margin: 4px 0 0 0; }
  #pref-save-btn { background: #667eea; color: #fff; border: none; padding: 9px 22px; border-radius: 4px; cursor: pointer; font-size: 0.95em; margin-top: 8px; }
  #pref-save-btn:hover { background: #5563d0; }
  #pref-save-btn:disabled { opacity: 0.6; cursor: not-allowed; }
  #pref-status-msg { margin-top: 10px; font-size: 0.9em; padding: 8px 12px; border-radius: 4px; display: none; }
  #pref-status-msg.success { background: #d1fae5; color: #065f46; display: block; }
  #pref-status-msg.error { background: #fee2e2; color: #991b1b; display: block; }
</style>
<script>
(function() {
  var btn = document.getElementById("pref-save-btn");
  if (!btn) return;
  btn.addEventListener("click", function() {
    var form = document.getElementById("source-prefs-form");
    var statusEl = document.getElementById("pref-status-msg");
    var sources = Array.from(form.querySelectorAll("input[name=\'sources_enabled[]\']:checked")).map(function(el) { return el.value; });
    var payload = {
      sources_enabled: sources,
      min_salary: parseInt(document.getElementById("pref-min-salary").value) || 0,
      remote_preference: document.getElementById("pref-remote").value,
      location_radius_km: parseInt(document.getElementById("pref-radius").value) || 0
    };
    btn.disabled = true;
    btn.textContent = "Saving\u2026";
    fetch(btn.dataset.saveUrl + "?token=" + encodeURIComponent(btn.dataset.token), {
      method: "POST",
      headers: {"Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest"},
      body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json().then(function(d) { return {status: r.status, data: d}; }); })
    .then(function(res) {
      btn.disabled = false;
      btn.textContent = "Save Preferences";
      statusEl.className = res.status === 200 ? "success" : "error";
      statusEl.textContent = res.status === 200 ? (res.data.message || "Saved.") : (res.data.error || "Save failed.");
      setTimeout(function() { statusEl.className = ""; statusEl.textContent = ""; }, 4000);
    })
    .catch(function() {
      btn.disabled = false;
      btn.textContent = "Save Preferences";
      if (statusEl) { statusEl.className = "error"; statusEl.textContent = "Network error. Please try again."; }
    });
  });
})();
</script>
',
    ];

    return $this->wrapWithNavigation($content);
  }

  /**
   * Saves job-board source preferences (POST, CSRF protected).
   */
  public function sourcePreferencesSave(): \Symfony\Component\HttpFoundation\Response {
    $uid = (int) $this->currentUser->id();
    $body = json_decode($this->requestStack->getCurrentRequest()->getContent(), TRUE) ?? [];

    // SEC-4: validate sources_enabled against allowlist.
    $raw_sources = is_array($body['sources_enabled'] ?? NULL) ? $body['sources_enabled'] : [];
    $sources_enabled = [];
    foreach ($raw_sources as $s) {
      if (in_array($s, self::VALID_SOURCE_KEYS, TRUE)) {
        $sources_enabled[] = $s;
      }
      else {
        return new \Symfony\Component\HttpFoundation\JsonResponse(
          ['error' => 'Invalid source key: ' . htmlspecialchars((string) $s)],
          400
        );
      }
    }

    // SEC-5: validate min_salary.
    $min_salary = isset($body['min_salary']) ? (int) $body['min_salary'] : 0;
    if ($min_salary < 0 || $min_salary > 999999999) {
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'min_salary out of range.'], 400);
    }

    // Validate remote_preference.
    $remote_pref = (string) ($body['remote_preference'] ?? 'any');
    if (!in_array($remote_pref, self::VALID_REMOTE_PREFS, TRUE)) {
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Invalid remote_preference.'], 400);
    }

    // SEC-5: validate location_radius_km.
    $radius = isset($body['location_radius_km']) ? (int) $body['location_radius_km'] : 0;
    if ($radius !== 0 && ($radius < 1 || $radius > 500)) {
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'location_radius_km must be 1–500 or 0 (no constraint).'], 400);
    }

    $now = \Drupal::time()->getRequestTime();
    $sources_json = json_encode(array_values($sources_enabled));

    try {
      $existing = $this->database->select('jobhunter_source_preferences', 'sp')
        ->fields('sp', ['id'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchField();

      if ($existing) {
        $this->database->update('jobhunter_source_preferences')
          ->fields([
            'sources_enabled'    => $sources_json,
            'min_salary'         => $min_salary ?: NULL,
            'remote_preference'  => $remote_pref,
            'location_radius_km' => $radius ?: NULL,
            'changed'            => $now,
          ])
          ->condition('uid', $uid)
          ->execute();
      }
      else {
        $this->database->insert('jobhunter_source_preferences')
          ->fields([
            'uid'                => $uid,
            'sources_enabled'    => $sources_json,
            'min_salary'         => $min_salary ?: NULL,
            'remote_preference'  => $remote_pref,
            'location_radius_km' => $radius ?: NULL,
            'created'            => $now,
            'changed'            => $now,
          ])
          ->execute();
      }

      $this->getLogger('job_hunter')->info('sourcePreferencesSave: uid=@uid', ['@uid' => $uid]);
      return new \Symfony\Component\HttpFoundation\JsonResponse(['message' => 'Preferences saved.'], 200);
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('sourcePreferencesSave failed: uid=@uid error=@error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Save failed. Please try again.'], 500);
    }
  }

  /**
   * Resume Version Labeling — edit form (GET, AC-2).
   *
   * @param int $resume_id
   *   The resume record ID.
   */
  public function resumeVersionForm($resume_id): array|\Symfony\Component\HttpFoundation\Response {
    $uid = (int) $this->currentUser->id();
    $resume_id = (int) $resume_id;

    // SEC-3: ownership — verify via job_seeker_id.
    $job_seeker = $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js', ['id'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    if (!$job_seeker) {
      $this->messenger()->addError($this->t('Resume not found.'));
      return new \Symfony\Component\HttpFoundation\RedirectResponse(Url::fromRoute('job_hunter.my_profile')->toString());
    }

    $resume = $this->database->select('jobhunter_job_seeker_resumes', 'jsr')
      ->fields('jsr', ['id', 'resume_name', 'version_label', 'version_notes'])
      ->condition('id', $resume_id)
      ->condition('job_seeker_id', (int) $job_seeker)
      ->execute()
      ->fetchAssoc();

    if (!$resume) {
      $this->messenger()->addError($this->t('Resume not found or access denied.'));
      return new \Symfony\Component\HttpFoundation\RedirectResponse(Url::fromRoute('job_hunter.my_profile')->toString());
    }

    $save_url = Url::fromRoute('job_hunter.resume_version_save', ['resume_id' => $resume_id])->toString();
    $csrf_token = \Drupal::csrfToken()->get('jobhunter/resume/' . $resume_id . '/edit/save');
    $back_url = Url::fromRoute('job_hunter.my_profile')->toString();

    $f_label = htmlspecialchars((string) ($resume['version_label'] ?? ''));
    $f_notes = htmlspecialchars((string) ($resume['version_notes'] ?? ''));
    $f_name  = htmlspecialchars((string) ($resume['resume_name'] ?? ''));

    $content['resume_version_form'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['resume-version-form-section']],
      '#markup' => '
<div class="resume-version-form">
  <p><strong>Resume:</strong> ' . $f_name . '</p>
  <div class="form-field-row">
    <label for="rv-version-label">Version Label <span style="color:#888;font-size:0.9em;">(max 128 chars)</span></label>
    <input type="text" id="rv-version-label" name="version_label" value="' . $f_label . '" maxlength="128" placeholder="e.g. Software Engineer v3" style="width:100%;max-width:480px;" />
  </div>
  <div class="form-field-row" style="margin-top:12px;">
    <label for="rv-version-notes">Notes <span style="color:#888;font-size:0.9em;">(optional)</span></label>
    <textarea id="rv-version-notes" name="version_notes" rows="4" placeholder="e.g. Emphasizes Go/Python; minimal frontend" style="width:100%;max-width:480px;">' . $f_notes . '</textarea>
  </div>
  <div style="margin-top:16px;">
    <button id="rv-save-btn" type="button" class="button button--primary">Save</button>
    <a href="' . htmlspecialchars($back_url) . '" class="button" style="margin-left:8px;">Cancel</a>
  </div>
  <div id="rv-status" style="margin-top:10px;"></div>
</div>
<script>
(function() {
  document.getElementById("rv-save-btn").addEventListener("click", function() {
    var label = document.getElementById("rv-version-label").value;
    var notes = document.getElementById("rv-version-notes").value;
    fetch(' . json_encode($save_url) . ', {
      method: "POST",
      headers: {"Content-Type": "application/json", "X-CSRF-Token": ' . json_encode($csrf_token) . '},
      body: JSON.stringify({version_label: label, version_notes: notes})
    }).then(function(r) { return r.json(); }).then(function(d) {
      var el = document.getElementById("rv-status");
      if (d.message) { el.textContent = "✅ " + d.message; el.style.color = "green"; }
      else { el.textContent = "❌ " + (d.error || "Error saving."); el.style.color = "red"; }
    }).catch(function() {
      document.getElementById("rv-status").textContent = "❌ Request failed.";
    });
  });
})();
</script>',
    ];

    // AC-3: "Used in applications" section — show jobs where this base resume was submitted (SEC-4: name + job metadata only).
    try {
      $used_apps = $this->database->select('jobhunter_applications', 'a')
        ->fields('a', ['id', 'submission_status', 'submission_date'])
        ->condition('a.submitted_resume_id', $resume_id)
        ->condition('a.submitted_resume_type', 'base')
        ->condition('a.uid', $uid)
        ->orderBy('a.created', 'DESC')
        ->execute()
        ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);

      if (!empty($used_apps)) {
        $rows_html = '';
        foreach ($used_apps as $app) {
          $job_row2 = $this->database->query(
            "SELECT j.job_title, c.name AS company_name FROM {jobhunter_job_requirements} j LEFT JOIN {jobhunter_companies} c ON j.company_id = c.id WHERE j.id = (SELECT job_id FROM {jobhunter_applications} WHERE id = :appid LIMIT 1)",
            [':appid' => (int) $app['id']]
          )->fetchAssoc();

          $title   = $job_row2 ? htmlspecialchars($job_row2['job_title'] ?? '') : '—';
          $company = $job_row2 ? htmlspecialchars($job_row2['company_name'] ?? '') : '—';
          $status  = htmlspecialchars($app['submission_status'] ?? '');
          $date    = htmlspecialchars($app['submission_date'] ?? '');
          $rows_html .= '<tr><td>' . $title . '</td><td>' . $company . '</td><td>' . $status . '</td><td>' . $date . '</td></tr>';
        }

        $content['resume_where_used'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['resume-where-used-section']],
          '#markup' => '
<div class="resume-where-used" style="margin-top:28px;padding:18px;background:#f7fafc;border-radius:8px;border-left:4px solid #3182ce;">
  <h3 style="margin:0 0 12px 0;color:#333;">📋 Used in applications</h3>
  <table style="width:100%;border-collapse:collapse;">
    <thead><tr>
      <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #e2e8f0;">Job Title</th>
      <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #e2e8f0;">Company</th>
      <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #e2e8f0;">Status</th>
      <th style="text-align:left;padding:6px 8px;border-bottom:1px solid #e2e8f0;">Submitted</th>
    </tr></thead>
    <tbody>' . $rows_html . '</tbody>
  </table>
</div>',
        ];
      }
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('resumeVersionForm where-used failed: uid=@uid rid=@rid error=@error', [
        '@uid' => $uid,
        '@rid' => $resume_id,
        '@error' => $e->getMessage(),
      ]);
    }

    return $this->wrapWithNavigation($content);
  }

  /**
   * Resume Version Labeling — save (POST, CSRF, AC-2).
   *
   * @param int $resume_id
   *   The resume record ID.
   */
  public function resumeVersionSave($resume_id): \Symfony\Component\HttpFoundation\JsonResponse {
    $uid = (int) $this->currentUser->id();
    $resume_id = (int) $resume_id;

    // SEC-3: ownership check via job_seeker_id.
    $job_seeker = $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js', ['id'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    if (!$job_seeker) {
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Access denied.'], 403);
    }

    $owns = (bool) $this->database->select('jobhunter_job_seeker_resumes', 'jsr')
      ->fields('jsr', ['id'])
      ->condition('id', $resume_id)
      ->condition('job_seeker_id', (int) $job_seeker)
      ->execute()
      ->fetchField();

    if (!$owns) {
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Access denied.'], 403);
    }

    $body = json_decode($this->requestStack->getCurrentRequest()->getContent(), TRUE) ?? [];

    // SEC-4: plain text only, max 128 chars for label.
    $version_label = isset($body['version_label']) ? substr(strip_tags((string) $body['version_label']), 0, 128) : NULL;
    $version_notes = isset($body['version_notes']) ? strip_tags((string) $body['version_notes']) : NULL;

    try {
      $this->database->update('jobhunter_job_seeker_resumes')
        ->fields([
          'version_label' => $version_label ?: NULL,
          'version_notes' => $version_notes ?: NULL,
          'changed' => \Drupal::time()->getRequestTime(),
        ])
        ->condition('id', $resume_id)
        ->execute();

      // SEC-5: log only uid and resume_id, never version_notes content.
      $this->getLogger('job_hunter')->info('resumeVersionSave: uid=@uid resume_id=@rid', [
        '@uid' => $uid,
        '@rid' => $resume_id,
      ]);
      return new \Symfony\Component\HttpFoundation\JsonResponse(['message' => 'Version label saved.'], 200);
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('resumeVersionSave failed: uid=@uid resume_id=@rid error=@error', [
        '@uid' => $uid,
        '@rid' => $resume_id,
        '@error' => $e->getMessage(),
      ]);
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Save failed.'], 500);
    }
  }

  /**
   * Resume Source Link — save submitted_resume_id + submitted_resume_type on application (POST, CSRF, AC-2, AC-5).
   *
   * Accepts JSON body: {"submitted_resume_id": <int>, "submitted_resume_type": "base"|"tailored"}
   * Also accepts legacy {"source_resume_id": <int>} (treated as type=base for backward compat).
   *
   * @param int $job_id
   *   The job_requirements ID.
   */
  public function resumeSourceSave($job_id): \Symfony\Component\HttpFoundation\JsonResponse {
    $uid = (int) $this->currentUser->id();
    $job_id = (int) $job_id;

    $body = json_decode($this->requestStack->getCurrentRequest()->getContent(), TRUE) ?? [];

    // Support both new and legacy field names.
    if (isset($body['submitted_resume_id'])) {
      $resume_id   = (int) $body['submitted_resume_id'];
      $resume_type = in_array($body['submitted_resume_type'] ?? '', ['base', 'tailored'], TRUE) ? $body['submitted_resume_type'] : 'base';
    }
    elseif (isset($body['source_resume_id'])) {
      $resume_id   = (int) $body['source_resume_id'];
      $resume_type = 'base';
    }
    else {
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Invalid resume selection.'], 400);
    }

    if ($resume_id <= 0) {
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Invalid resume id.'], 400);
    }

    // SEC-3: ownership check — verify resume belongs to current user.
    if ($resume_type === 'base') {
      $job_seeker = $this->database->select('jobhunter_job_seeker', 'js')
        ->fields('js', ['id'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchField();

      if (!$job_seeker) {
        return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Access denied.'], 403);
      }

      $owns = (bool) $this->database->select('jobhunter_job_seeker_resumes', 'jsr')
        ->fields('jsr', ['id'])
        ->condition('id', $resume_id)
        ->condition('job_seeker_id', (int) $job_seeker)
        ->execute()
        ->fetchField();

      if (!$owns) {
        return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Access denied.'], 403);
      }
    }
    else {
      // Tailored resume ownership check via uid column.
      $owns = (bool) $this->database->select('jobhunter_tailored_resumes', 'tr')
        ->fields('tr', ['id'])
        ->condition('id', $resume_id)
        ->condition('uid', $uid)
        ->execute()
        ->fetchField();

      if (!$owns) {
        return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Access denied.'], 403);
      }
    }

    // SEC-3: verify the application row belongs to this user.
    $owns_app = (bool) $this->database->select('jobhunter_applications', 'a')
      ->fields('a', ['id'])
      ->condition('uid', $uid)
      ->condition('job_id', $job_id)
      ->execute()
      ->fetchField();

    if (!$owns_app) {
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'No application found for this job.'], 404);
    }

    try {
      $updated = $this->database->update('jobhunter_applications')
        ->fields([
          'submitted_resume_id'   => $resume_id,
          'submitted_resume_type' => $resume_type,
          'source_resume_id'      => $resume_type === 'base' ? $resume_id : NULL,
          'changed' => date('Y-m-d H:i:s'),
        ])
        ->condition('uid', $uid)
        ->condition('job_id', $job_id)
        ->execute();

      if (!$updated) {
        return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'No application found for this job.'], 404);
      }

      // SEC-5: log only ids, no content.
      $this->getLogger('job_hunter')->info('resumeSourceSave: uid=@uid job_id=@jid resume_id=@rid type=@type', [
        '@uid' => $uid,
        '@jid' => $job_id,
        '@rid' => $resume_id,
        '@type' => $resume_type,
      ]);
      return new \Symfony\Component\HttpFoundation\JsonResponse(['message' => 'Resume source saved.'], 200);
    }
    catch (\Exception $e) {
      $this->getLogger('job_hunter')->error('resumeSourceSave failed: uid=@uid job_id=@jid error=@error', [
        '@uid' => $uid,
        '@jid' => $job_id,
        '@error' => $e->getMessage(),
      ]);
      return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Save failed.'], 500);
    }
  }

  /**
   * Create or update offer details for a saved job (POST, CSRF-protected).
   *
   * @param int $job_id
   *   The jobhunter_job_requirements ID.
   */
  public function offerSave($job_id): JsonResponse {
    if (!$this->database->schema()->tableExists('jobhunter_offers')) {
      return new JsonResponse(['error' => 'Offer tracking not available.'], 503);
    }

    $uid     = (int) $this->currentUser->id();
    $job_id  = (int) $job_id;
    $request = $this->requestStack->getCurrentRequest();

    // SEC-3: ownership check.
    $saved_job = $this->loadOwnedSavedJob($uid, $job_id);
    if (!$saved_job) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }
    $saved_job_id = (int) $saved_job->id;

    $body = json_decode((string) $request->getContent(), TRUE) ?? [];

    // Validate and sanitize inputs.
    $base_salary_raw = $body['base_salary'] ?? NULL;
    $base_salary = ($base_salary_raw !== NULL && $base_salary_raw !== '') ? (int) $base_salary_raw : NULL;
    if ($base_salary !== NULL && ($base_salary < 0 || $base_salary > 9999999)) {
      return new JsonResponse(['error' => 'Base salary must be between 0 and 9,999,999.'], 422);
    }

    $equity_summary   = strip_tags((string) ($body['equity_summary'] ?? ''));
    $benefits_summary = strip_tags((string) ($body['benefits_summary'] ?? ''));
    $response_deadline = preg_replace('/[^0-9\-]/', '', (string) ($body['response_deadline'] ?? ''));
    $notes_raw        = (string) ($body['notes'] ?? '');

    foreach (['equity_summary' => $equity_summary, 'benefits_summary' => $benefits_summary, 'notes' => $notes_raw] as $field => $val) {
      if (mb_strlen($val) > 2000) {
        return new JsonResponse(['error' => ucfirst(str_replace('_', ' ', $field)) . ' may not exceed 2000 characters.'], 400);
      }
    }
    $notes = strip_tags($notes_raw);

    $now = time();

    $existing_id = $this->database->select('jobhunter_offers', 'o')
      ->fields('o', ['id'])
      ->condition('o.uid', $uid)
      ->condition('o.saved_job_id', $saved_job_id)
      ->execute()
      ->fetchField();

    if ($existing_id) {
      $this->database->update('jobhunter_offers')
        ->fields([
          'base_salary'       => $base_salary,
          'equity_summary'    => $equity_summary ?: NULL,
          'benefits_summary'  => $benefits_summary ?: NULL,
          'response_deadline' => $response_deadline ?: NULL,
          'notes'             => $notes ?: NULL,
          'changed'           => $now,
        ])
        ->condition('uid', $uid)
        ->condition('saved_job_id', $saved_job_id)
        ->execute();
    }
    else {
      $this->database->insert('jobhunter_offers')
        ->fields([
          'uid'               => $uid,
          'saved_job_id'      => $saved_job_id,
          'base_salary'       => $base_salary,
          'equity_summary'    => $equity_summary ?: NULL,
          'benefits_summary'  => $benefits_summary ?: NULL,
          'response_deadline' => $response_deadline ?: NULL,
          'notes'             => $notes ?: NULL,
          'created'           => $now,
          'changed'           => $now,
        ])
        ->execute();
    }

    // SEC-5: log only uid and saved_job_id, never salary/notes values.
    $this->getLogger('job_hunter')->info('Offer details saved: uid=@uid saved_job_id=@sjid', [
      '@uid'  => $uid,
      '@sjid' => $saved_job_id,
    ]);

    return new JsonResponse(['message' => 'Offer details saved.'], 200);
  }

}
