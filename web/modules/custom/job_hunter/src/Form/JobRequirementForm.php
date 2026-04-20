<?php

namespace Drupal\job_hunter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding/editing job requirements.
 */
class JobRequirementForm extends FormBase {

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
   * Constructs a new JobRequirementForm.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
    $this->database = \Drupal::database();
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
    return 'job_hunter_job_requirement_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $job_id = NULL, $company_id = NULL) {
    $job = NULL;
    
    // Load existing job if editing
    if ($job_id) {
      $job = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j')
        ->condition('id', $job_id)
        ->execute()
        ->fetchObject();
      
      if (!$job) {
        $this->messenger->addError($this->t('Job requirement not found.'));
        return $form;
      }
      
      $form_state->set('job_id', $job_id);
      
      // Display processing status for existing jobs
      $form['status_display'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['job-status-display']],
      ];
      
      $has_raw_text = !empty($job->raw_posting_text);
      $has_extracted = !empty($job->extracted_json);
      $has_skills = !empty($job->skills_required_json);
      $has_keywords = !empty($job->keywords_json);
      $ai_status = $job->ai_extraction_status ?? 'pending';
      
      $status_html = '<div style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-radius: 5px; border: 1px solid #ddd;">';
      $status_html .= '<strong style="font-size: 14px;">📋 Processing Status:</strong>';
      $status_html .= '<ul style="margin: 10px 0 0 20px; padding: 0; list-style: none;">';
      
      // Raw text status
      $text_icon = $has_raw_text ? '✅' : '⬜';
      $text_color = $has_raw_text ? 'green' : '#999';
      $text_chars = $has_raw_text ? ' (' . number_format(strlen($job->raw_posting_text)) . ' chars)' : '';
      $status_html .= '<li style="color: ' . $text_color . '; padding: 2px 0;">' . $text_icon . ' <strong>Raw Posting Saved:</strong> ' . ($has_raw_text ? 'Yes' . $text_chars : 'No') . '</li>';
      
      // AI Extraction status
      if ($ai_status === 'processing') {
        $ai_icon = '🔄';
        $ai_color = '#3b82f6';
        $ai_text = 'AI extraction in progress...';
      } elseif ($ai_status === 'completed' || $has_extracted) {
        $ai_icon = '✅';
        $ai_color = 'green';
        $ai_text = 'Yes';
      } elseif ($ai_status === 'failed') {
        $ai_icon = '❌';
        $ai_color = '#ef4444';
        $ai_text = 'Failed';
      } else {
        $ai_icon = '⏳';
        $ai_color = '#f59e0b';
        $ai_text = 'Pending...';
      }
      $status_html .= '<li style="color: ' . $ai_color . '; padding: 2px 0;">' . $ai_icon . ' <strong>AI Extracted:</strong> ' . $ai_text . '</li>';
      
      // Skills extracted
      $skills_icon = $has_skills ? '✅' : '⬜';
      $skills_color = $has_skills ? 'green' : '#999';
      $status_html .= '<li style="color: ' . $skills_color . '; padding: 2px 0;">' . $skills_icon . ' <strong>Skills Identified:</strong> ' . ($has_skills ? 'Yes' : 'Pending...') . '</li>';
      
      // Keywords extracted
      $keywords_icon = $has_keywords ? '✅' : '⬜';
      $keywords_color = $has_keywords ? 'green' : '#999';
      $status_html .= '<li style="color: ' . $keywords_color . '; padding: 2px 0;">' . $keywords_icon . ' <strong>Keywords Extracted:</strong> ' . ($has_keywords ? 'Yes' : 'Pending...') . '</li>';
      
      $status_html .= '</ul></div>';
      
      $form['status_display']['status'] = [
        '#markup' => $status_html,
      ];
      
      // Show duplicate warnings if any exist
      $duplicates = !empty($job->potential_duplicates_json) ? json_decode($job->potential_duplicates_json, TRUE) : [];
      if (!empty($duplicates)) {
        $exact_match = array_filter($duplicates, fn($d) => $d['is_exact_match'] ?? FALSE);
        
        if (!empty($exact_match)) {
          $match = reset($exact_match);
          $dup_html = '<div class="messages messages--error" style="margin-bottom: 20px;">';
          $dup_html .= '<strong>⚠️ Exact Duplicate Found!</strong><br>';
          $dup_html .= 'This job appears to be identical to: <a href="/jobhunter/jobs/' . $match['job_id'] . '">';
          $dup_html .= '<strong>' . htmlspecialchars($match['job_title']) . '</strong> at ';
          $dup_html .= htmlspecialchars($match['company']) . ' (Job #' . $match['job_id'] . ')</a>';
          $dup_html .= '</div>';
        }
        else {
          $dup_html = '<div class="messages messages--warning" style="margin-bottom: 20px;">';
          $dup_html .= '<strong>📋 Potential Duplicates Found</strong><br>';
          $dup_html .= 'This job may be similar to:<ul>';
          foreach ($duplicates as $dup) {
            $dup_html .= '<li><a href="/jobhunter/jobs/' . $dup['job_id'] . '">';
            $dup_html .= htmlspecialchars($dup['job_title']) . ' at ';
            $dup_html .= htmlspecialchars($dup['company']) . ' (' . $dup['similarity_score'] . '% match)</a></li>';
          }
          $dup_html .= '</ul></div>';
        }
        
        $form['status_display']['duplicates'] = [
          '#markup' => $dup_html,
          '#weight' => -10,
        ];
      }
    }

    // Raw posting text - the primary input
    $form['raw_posting_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Raw Job Posting'),
      '#default_value' => $job ? $job->raw_posting_text : '',
      '#rows' => 12,
      '#description' => $this->t('Paste the original job posting text here.'),
    ];

    // JSON extraction fields
    $form['json_data'] = [
      '#type' => 'details',
      '#title' => $this->t('Extracted JSON Data'),
      '#open' => TRUE,
    ];

    $form['json_data']['extracted_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Extracted Job Data (JSON)'),
      '#default_value' => $job && $job->extracted_json ? json_encode(json_decode($job->extracted_json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '',
      '#rows' => 15,
      '#description' => $this->t('AI-extracted structured data from the job posting.'),
      '#attributes' => ['style' => 'font-family: monospace; font-size: 12px;'],
    ];

    $form['json_data']['skills_required_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Required Skills (JSON)'),
      '#default_value' => $job && $job->skills_required_json ? json_encode(json_decode($job->skills_required_json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '',
      '#rows' => 12,
      '#description' => $this->t('Must-have skills, nice-to-have skills, and tech stack.'),
      '#attributes' => ['style' => 'font-family: monospace; font-size: 12px;'],
    ];

    $form['json_data']['keywords_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Keywords (JSON)'),
      '#default_value' => $job && $job->keywords_json ? json_encode(json_decode($job->keywords_json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '',
      '#rows' => 8,
      '#description' => $this->t('High-frequency terms, action verbs, key phrases for resume tailoring.'),
      '#attributes' => ['style' => 'font-family: monospace; font-size: 12px;'],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $job_id ? $this->t('Update Job') : $this->t('Add Job'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('job_hunter.my_jobs'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $job_id = $form_state->get('job_id');
    $timestamp = \Drupal::time()->getRequestTime();

    // Minify JSON for storage (remove pretty-print whitespace)
    $extracted_json = $form_state->getValue('extracted_json');
    $skills_json = $form_state->getValue('skills_required_json');
    $keywords_json = $form_state->getValue('keywords_json');

    // Re-encode to ensure valid JSON and minify
    if ($extracted_json) {
      $decoded = json_decode($extracted_json);
      $extracted_json = $decoded ? json_encode($decoded) : $extracted_json;
    }
    if ($skills_json) {
      $decoded = json_decode($skills_json);
      $skills_json = $decoded ? json_encode($decoded) : $skills_json;
    }
    if ($keywords_json) {
      $decoded = json_decode($keywords_json);
      $keywords_json = $decoded ? json_encode($decoded) : $keywords_json;
    }

    // Helper to convert empty strings to NULL for database
    $nullIfEmpty = function($value) {
      return ($value === '' || $value === NULL) ? NULL : $value;
    };

    $fields = [
      'raw_posting_text' => $nullIfEmpty($form_state->getValue('raw_posting_text')),
      'extracted_json' => $nullIfEmpty($extracted_json),
      'skills_required_json' => $nullIfEmpty($skills_json),
      'keywords_json' => $nullIfEmpty($keywords_json),
      'updated' => $timestamp,
    ];

    if ($job_id) {
      // Update existing job
      $this->database->update('jobhunter_job_requirements')
        ->fields($fields)
        ->condition('id', $job_id)
        ->execute();
      
      // Queue for AI parsing if raw text exists and status is pending or no extracted data
      $raw_text = $form_state->getValue('raw_posting_text');
      $current_status = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j', ['ai_extraction_status', 'extracted_json'])
        ->condition('id', $job_id)
        ->execute()
        ->fetchObject();
      
      $needs_parsing = !empty($raw_text) && (
        empty($current_status->extracted_json) || 
        $current_status->ai_extraction_status === 'pending' ||
        $current_status->ai_extraction_status === 'failed'
      );
      
      if ($needs_parsing) {
        $this->queueJobForParsing($job_id, $raw_text);
        $this->messenger->addMessage($this->t('Job has been updated and queued for AI parsing.'));
      }
      else {
        $this->messenger->addMessage($this->t('Job has been updated.'));
      }
    }
    else {
      // Insert new job
      $fields['created'] = $timestamp;
      $fields['status'] = 'active';
      $fields['ai_extraction_status'] = 'pending';
      
      $new_job_id = $this->database->insert('jobhunter_job_requirements')
        ->fields($fields)
        ->execute();
      
      // Queue for AI parsing if raw text provided
      $raw_text = $form_state->getValue('raw_posting_text');
      if (!empty($raw_text)) {
        $this->queueJobForParsing($new_job_id, $raw_text);
        $this->messenger->addMessage($this->t('Job has been added and queued for AI parsing.'));
      }
      else {
        $this->messenger->addMessage($this->t('Job has been added.'));
      }
    }

    $form_state->setRedirect('job_hunter.my_jobs');
  }

  /**
   * Queue job posting for AI parsing.
   */
  private function queueJobForParsing($job_id, $raw_text) {
    $queue = \Drupal::queue('job_hunter_job_posting_parsing');
    $queue->createItem([
      'job_id' => $job_id,
      'raw_posting_text' => $raw_text,
    ]);

    // Update status to pending
    $this->database->update('jobhunter_job_requirements')
      ->fields(['ai_extraction_status' => 'pending'])
      ->condition('id', $job_id)
      ->execute();

    \Drupal::logger('job_hunter')->info('📋 Queued job posting @id for AI parsing', ['@id' => $job_id]);
  }

}
