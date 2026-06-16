<?php

namespace Drupal\job_hunter\Form\Subform;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Database\Connection;
use Drupal\job_hunter\Service\JobSeekerService;
use Drupal\job_hunter\Traits\JobHunterLoggerTrait;

/**
 * Manages the Resume Upload/Management sub-section of UserProfileForm.
 *
 * Owns:
 *   - buildFormElements(): populates $form['resume_workflow']
 *   - processUploadedFilesSubmit(): handles file upload processing
 *   - addAnotherResumeSubmit(): clears upload field for another upload
 *   - addResumeSubmit(): legacy single-file add handler
 *   - registerResumeSubmit(): registers a scanned file into the DB
 *   - deleteResumeFileSubmit(): deletes a single resume and its data
 *   - deleteAllResumeDataSubmit(): deletes all resumes and profile data
 *   - extractTextFromFile(): extracts plain text from a file entity
 */
class ResumeUploadSubform {

  use DependencySerializationTrait;
  use StringTranslationTrait;
  use JobHunterLoggerTrait;

  /**
   * The job seeker service.
   *
   * @var \Drupal\job_hunter\Service\JobSeekerService
   */
  protected JobSeekerService $jobSeekerService;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Constructs a new ResumeUploadSubform.
   *
   * @param \Drupal\job_hunter\Service\JobSeekerService $job_seeker_service
   *   The job seeker service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(JobSeekerService $job_seeker_service, Connection $database) {
    $this->jobSeekerService = $job_seeker_service;
    $this->database = $database;
  }

  /**
   * Builds the resume_workflow form sub-section.
   *
   * Adds $form['resume_workflow'] with upload field, status display, action
   * buttons, and per-file JSON editors.
   *
   * @param array $form
   *   The parent form array, passed by reference.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param object|null $job_seeker_profile
   *   The job seeker profile object, or NULL if not yet created.
   * @param int $uid
   *   The current user ID.
   */
  public function buildFormElements(array &$form, FormStateInterface $form_state, $job_seeker_profile, int $uid): void {
    $form['resume_workflow'] = [
      '#type' => 'container',
      '#weight' => -100,
      '#prefix' => '<div id="resume-workflow-wrapper" class="jh-profile__resume">',
      '#suffix' => '</div>',
    ];

    $form['resume_workflow']['header'] = [
      '#markup' => '<h3 class="jh-profile__resume-header">📁 ' . $this->t('Resume Management') . '</h3><p class="jh-profile__resume-desc">' . $this->t('Upload your resume files. Files are automatically processed with AI to extract your profile information.') . '</p>',
    ];

    // Upload field - always show empty for new uploads.
    $user_resume_dir = 'private://job_hunter/resumes/' . $uid . '/originalresumes';
    \Drupal::service('file_system')->prepareDirectory($user_resume_dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);

    $form['resume_workflow']['field_resume_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload New Resume'),
      '#description' => $this->t('Upload resume files (PDF or Word format, max 10MB). Click "Upload" after selecting.'),
      '#required' => FALSE,
      '#multiple' => TRUE,
      '#upload_location' => $user_resume_dir,
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'pdf doc docx'],
        'FileSizeLimit' => ['fileLimit' => 10 * 1024 * 1024],
      ],
      '#default_value' => [],
      '#progress_indicator' => 'bar',
    ];

    $form['resume_workflow']['process_upload'] = [
      '#type' => 'submit',
      '#value' => $this->t('📤 Process Uploaded Files'),
      '#submit' => ['::processUploadedFilesSubmit'],
      '#limit_validation_errors' => [['field_resume_file']],
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    // Build uploaded files status display.
    $database = \Drupal::database();
    $files_list = [];
    $resume_table = '';

    $private_path = \Drupal::service('file_system')->realpath('private://job_hunter/resumes/' . $uid . '/originalresumes');

    if ($private_path && is_dir($private_path)) {
      $files = scandir($private_path);
      $files = array_diff($files, ['.', '..']);
      $files = array_filter($files, function ($filename) use ($private_path) {
        return is_file($private_path . '/' . $filename);
      });

      if (!empty($files)) {
        $resume_table = '<div class="jh-profile__resume-list">';

        $index = 0;
        foreach ($files as $filename) {
          $file_path = $private_path . '/' . $filename;
          $file_size = filesize($file_path);

          $file_uri = 'private://job_hunter/resumes/' . $uid . '/originalresumes/' . $filename;
          $file_entities = \Drupal::entityTypeManager()
            ->getStorage('file')
            ->loadByProperties(['uri' => $file_uri]);

          $is_registered = false;
          $resume_record_id = null;
          $file_id = null;
          $parsed_data = null;
          $extracted_text = null;

          if (empty($file_entities)) {
            $file = \Drupal\file\Entity\File::create([
              'uri' => $file_uri,
              'filename' => $filename,
              'status' => 1,
            ]);
            $file->save();
            $file_id = $file->id();
          }
          else {
            $file = reset($file_entities);
            $file_id = $file->id();
          }

          // Ensure job_seeker profile exists before auto-registration.
          if (!$job_seeker_profile) {
            $job_seeker_data = ['uid' => $uid];
            $job_seeker_id = $this->jobSeekerService->create($job_seeker_data);
            $job_seeker_profile = $this->jobSeekerService->load($job_seeker_id);
          }
          $job_seeker_id = (int) $job_seeker_profile->id;

          $resume_record = $database->select('jobhunter_job_seeker_resumes', 'jsr')
            ->fields('jsr', ['id', 'extracted_text', 'version_label', 'version_notes'])
            ->condition('job_seeker_id', $job_seeker_id)
            ->condition('file_id', $file_id)
            ->execute()
            ->fetchAssoc();

          $version_label = NULL;
          $version_notes = NULL;
          if ($resume_record) {
            $is_registered = true;
            $resume_record_id = $resume_record['id'];
            $extracted_text = $resume_record['extracted_text'];
            $version_label = $resume_record['version_label'] ?? NULL;
            $version_notes = $resume_record['version_notes'] ?? NULL;
          }
          else {
            // Auto-register.
            $resume_record_id = $database->insert('jobhunter_job_seeker_resumes')
              ->fields([
                'job_seeker_id' => $job_seeker_id,
                'file_id' => $file_id,
                'resume_name' => pathinfo($filename, PATHINFO_FILENAME),
                'created' => time(),
                'changed' => time(),
              ])
              ->execute();
            $is_registered = true;
          }

          $parsing_status = NULL;
          $parsing_error = NULL;
          if ($is_registered) {
            $parsed_record = $this->getLatestParsedRecordForFile($database, $uid, $file_id, $file_uri, $filename);

            if ($parsed_record) {
              $parsing_status = $parsed_record['status'];
              $parsing_error = $parsed_record['error_message'];
              $parsed_data = json_decode($parsed_record['parsed_data'], TRUE);
            }
            elseif (!empty($extracted_text)) {
              // Self-heal: ensure extracted resumes are always represented in the
              // parsing queue/status pipeline.
              if (!$file->isPermanent()) {
                $file->setPermanent();
                $file->save();
              }

              $timestamp = \Drupal::time()->getRequestTime();
              $database->insert('jobhunter_resume_parsed_data')
                ->fields([
                  'uid' => $uid,
                  'resume_file_id' => $file_id,
                  'resume_path' => $file_uri,
                  'parsed_data' => json_encode(['status' => 'queued']),
                  'status' => 'queued',
                  'error_message' => NULL,
                  'created' => $timestamp,
                  'changed' => $timestamp,
                ])
                ->execute();

              $already_queued = (bool) $database->select('queue', 'q')
                ->fields('q', ['item_id'])
                ->condition('name', 'job_hunter_genai_parsing')
                ->condition('data', '%"file_id":' . $file_id . '%', 'LIKE')
                ->range(0, 1)
                ->execute()
                ->fetchField();

              if (!$already_queued) {
                \Drupal::queue('job_hunter_genai_parsing')->createItem([
                  'uid' => $uid,
                  'resume_id' => $resume_record_id,
                  'file_id' => $file_id,
                  'extracted_text' => $extracted_text,
                  'filename' => $filename,
                ]);
              }

              $parsing_status = 'queued';
              $parsing_error = NULL;
              $parsed_data = ['status' => 'queued'];
            }
          }

          $size_kb = round($file_size / 1024, 2);
          $size_display = $size_kb < 1024 ? $size_kb . ' KB' : round($size_kb / 1024, 2) . ' MB';

          $resume_table .= '<div class="jh-profile__resume-card">';
          $resume_table .= '<div class="jh-profile__resume-card-header">';
          $resume_table .= '<div>';
          $resume_table .= '<strong class="jh-profile__resume-card-name">' . htmlspecialchars($filename) . '</strong>';
          $resume_table .= '<span class="jh-profile__resume-card-size">(' . $size_display . ')</span>';
          if (!empty($version_label)) {
            $resume_table .= ' <span class="jh-profile__resume-version-label">🏷 ' . htmlspecialchars($version_label) . '</span>';
          }
          if ($resume_record_id) {
            $edit_url = \Drupal\Core\Url::fromRoute('job_hunter.resume_version_edit', ['resume_id' => $resume_record_id])->toString();
            $resume_table .= ' <a class="jh-profile__resume-edit-link" href="' . htmlspecialchars($edit_url) . '">Edit label</a>';
          }
          $resume_table .= '</div>';
          $resume_table .= '<div id="delete-btn-' . $index . '"></div>';
          $resume_table .= '</div>';

          $resume_table .= '<div class="jh-profile__status">';
          $resume_table .= '<strong class="jh-profile__status-title">📋 ' . $this->t('Processing Status:') . '</strong>';
          $resume_table .= '<ul class="jh-profile__status-list">';

          $has_extracted_text = !empty($extracted_text);
          $text_icon = $has_extracted_text ? '✅' : '⬜';
          $text_status_class = $has_extracted_text ? 'jh-profile__status-item--done' : 'jh-profile__status-item--pending';
          $resume_table .= '<li class="jh-profile__status-item ' . $text_status_class . '">';
          $resume_table .= $text_icon . ' <strong>' . $this->t('Text Extracted:') . '</strong> ' . ($has_extracted_text ? $this->t('Yes (@chars chars)', ['@chars' => number_format(strlen($extracted_text))]) : $this->t('Pending...'));
          $resume_table .= '</li>';

          $has_json = false;
          $is_queued = ($parsing_status === 'queued');
          $is_processing = ($parsing_status === 'processing');
          $is_error = ($parsing_status === 'error');
          $is_complete = in_array($parsing_status, ['complete', 'completed'], TRUE);

          if ($parsed_data && is_array($parsed_data) && $is_complete) {
            $has_json = !empty($parsed_data['schema_version']) ||
                       !empty($parsed_data['contact_info']) ||
                       !empty($parsed_data['executive_profile']) ||
                       !empty($parsed_data['professional_experience']) ||
                       !empty($parsed_data['technical_expertise']) ||
                       !empty($parsed_data['education']);
          }

          if ($is_queued) {
            $json_icon = '⏳';
            $json_status_class = 'jh-profile__status-item--queued';
            $json_status = $this->t('Queued for AI parsing...');
          }
          elseif ($is_processing) {
            $json_icon = '🔄';
            $json_status_class = 'jh-profile__status-item--processing';
            $json_status = $this->t('AI parsing in progress...');
          }
          elseif ($is_error) {
            $json_icon = '❌';
            $json_status_class = 'jh-profile__status-item--error';
            $json_status = $this->t('Error: @msg', ['@msg' => substr($parsing_error ?? 'Unknown error', 0, 50)]);
          }
          elseif ($has_json) {
            $json_icon = '✅';
            $json_status_class = 'jh-profile__status-item--done';
            $json_status = $this->t('Yes');
          }
          else {
            $json_icon = '⬜';
            $json_status_class = 'jh-profile__status-item--pending';
            $json_status = $this->t('No');
          }

          $resume_table .= '<li class="jh-profile__status-item ' . $json_status_class . '">';
          $resume_table .= $json_icon . ' <strong>' . $this->t('Individual JSON Stored:') . '</strong> ' . $json_status;
          if ($is_queued || $is_processing) {
            $resume_table .= ' <span class="jh-profile__section-info">(' . $this->t('Refresh page to check status') . ')</span>';
          }
          $resume_table .= '</li>';

          $in_consolidated = false;
          if ($job_seeker_profile && !empty($job_seeker_profile->consolidated_profile_json)) {
            $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE);
            if ($consolidated && is_array($consolidated) &&
                !empty($consolidated['extraction_metadata']['source_files'])) {
              $in_consolidated = in_array($filename, $consolidated['extraction_metadata']['source_files']);
            }
          }

          $consol_status_class = $in_consolidated ? 'jh-profile__status-item--done' : 'jh-profile__status-item--pending';
          $consol_icon = $in_consolidated ? '✅' : '⬜';
          $resume_table .= '<li class="jh-profile__status-item ' . $consol_status_class . '">';
          $resume_table .= $consol_icon . ' <strong>' . $this->t('Merged to Consolidated:') . '</strong> ' . ($in_consolidated ? $this->t('Yes') : $this->t('Pending...'));
          $resume_table .= '</li>';

          $resume_table .= '</ul>';

          if ($is_queued || $is_processing) {
            $resume_table .= '<div class="jh-profile__status-banner jh-profile__status-banner--info">';
            $resume_table .= '🔄 <em>' . $this->t('Processing automatically in the queue/cron pipeline - this usually takes 5-10 minutes.') . '</em>';
            $resume_table .= '</div>';
          }
          elseif ($is_error) {
            $resume_table .= '<div class="jh-profile__status-banner jh-profile__status-banner--error">';
            $resume_table .= '❌ <em>' . $this->t('Parsing failed - please try re-uploading the file') . '</em>';
            $resume_table .= '</div>';
          }

          $resume_table .= '</div>';
          $resume_table .= '</div>';

          $files_list[] = [
            'filename' => $filename,
            'resume_id' => $resume_record_id,
            'has_text' => $has_extracted_text,
            'has_json' => $has_json,
            'in_consolidated' => $in_consolidated,
            'file_id' => $file_id,
            'file' => $file,
          ];
          $index++;
        }

        $resume_table .= '</div>';
      }
    }

    if (!empty($resume_table)) {
      $form['resume_workflow']['resume_files_display'] = [
        '#markup' => '<div class="jh-profile__uploaded-files"><h4 class="jh-profile__uploaded-files-title">' . $this->t('📋 Uploaded Files') . '</h4>' . $resume_table . '</div>',
      ];

      $index = 0;
      foreach ($files_list as $file_info) {
        $form['resume_workflow']['delete_btn_' . $index] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['delete-btn-container'],
            'data-target' => 'delete-btn-' . $index,
          ],
        ];

        $form['resume_workflow']['delete_btn_' . $index]['delete_resume_' . $index] = [
          '#type' => 'submit',
          '#value' => $this->t('Delete'),
          '#name' => 'delete_resume_' . $index,
          '#submit' => ['::deleteResumeFileSubmit'],
          '#limit_validation_errors' => [],
          '#validate' => [],
          '#attributes' => [
            'data-filename' => $file_info['filename'],
            'data-file-id' => (string) ($file_info['file_id'] ?? ''),
            'class' => ['button', 'button--danger', 'jh-profile__delete-btn'],
            'data-confirm-message' => $this->t('Are you sure you want to delete this resume file?'),
          ],
        ];

        $index++;
      }

      $form['resume_workflow']['individual_json_editors'] = [
        '#type' => 'details',
        '#title' => $this->t('📝 Individual Resume JSON Data'),
        '#description' => $this->t('View and edit the parsed JSON data for each resume file.'),
        '#open' => FALSE,
        '#weight' => 10,
      ];

      $json_index = 0;
      foreach ($files_list as $file_info) {
        if (isset($file_info['has_json']) && $file_info['has_json'] && isset($file_info['file_id'])) {
          $parsed_record = $this->database->select('jobhunter_resume_parsed_data', 'jrpd')
            ->fields('jrpd', ['id', 'parsed_data'])
            ->condition('uid', $uid)
            ->condition('resume_file_id', $file_info['file_id'])
            ->execute()
            ->fetchObject();

          if ($parsed_record && isset($file_info['file'])) {
            $form['resume_workflow']['individual_json_editors']['json_' . $json_index] = [
              '#type' => 'details',
              '#title' => $this->t('📄 @name', ['@name' => $file_info['file']->getFilename()]),
              '#open' => FALSE,
              '#attributes' => ['class' => ['jh-profile__json-editor']],
            ];

            $form['resume_workflow']['individual_json_editors']['json_' . $json_index]['parsed_data_' . $parsed_record->id] = [
              '#type' => 'textarea',
              '#title' => $this->t('Parsed JSON Data'),
              '#default_value' => $parsed_record->parsed_data,
              '#rows' => 20,
              '#attributes' => [
                'class' => ['jh-profile__json-textarea'],
                'placeholder' => $this->t('JSON data will appear here after parsing...'),
              ],
              '#description' => $this->t('Edit the parsed JSON data for this resume. Must be valid JSON format. Changes save when you submit the form.'),
            ];

            $json_index++;
          }
        }
      }

      if ($json_index == 0) {
        $form['resume_workflow']['individual_json_editors']['no_json'] = [
          '#markup' => '<p><em>' . $this->t('No parsed JSON data is available yet. Your resume is processed automatically by the queue/cron pipeline after upload, and this usually takes 5-10 minutes. Refresh this page shortly to see results.') . '</em></p>',
        ];
      }

      $form['resume_workflow']['#attached']['library'][] = 'core/drupal';
      $form['resume_workflow']['#attached']['library'][] = 'job_hunter/user_profile';
    }
    else {
      $form['resume_workflow']['no_files'] = [
        '#markup' => '<p class="jh-profile__no-files">' . $this->t('No resume files uploaded yet. Use the upload field above to add your resume.') . '</p>',
      ];
    }
  }

  /**
   * Submit handler: processes uploaded managed_file entries.
   *
   * Registers new files, re-queues already-registered files for AI parsing,
   * and skips files that are already queued/processing.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function processUploadedFilesSubmit(array &$form, FormStateInterface $form_state): void {
    $fids = $form_state->getValue('field_resume_file');

    if (empty($fids)) {
      \Drupal::messenger()->addWarning($this->t('No files selected. Please choose files to upload first.'));
      return;
    }

    $uid = \Drupal::currentUser()->id();
    $connection = \Drupal::database();
    $processed_count = 0;

    $job_seeker_profile = $this->jobSeekerService->loadByUserId($uid);
    if (!$job_seeker_profile) {
      $job_seeker_id = $this->jobSeekerService->create(['uid' => $uid]);
      $job_seeker_profile = $this->jobSeekerService->load($job_seeker_id);
    }
    $job_seeker_id = (int) $job_seeker_profile->id;

    foreach ($fids as $fid) {
      $file = \Drupal\file\Entity\File::load($fid);
      if (!$file) {
        continue;
      }

      $file->setPermanent();
      $file->save();

      $this->logInfo('📁 File made permanent: @filename (fid: @fid)', [
        '@filename' => $file->getFilename(),
        '@fid' => $file->id(),
      ]);

      $existing_record = $connection->select('jobhunter_job_seeker_resumes', 'jsr')
        ->fields('jsr', ['id', 'extracted_text'])
        ->condition('job_seeker_id', $job_seeker_id)
        ->condition('file_id', $file->id())
        ->execute()
        ->fetchAssoc();

      if ($existing_record) {
        $latest_parsed = $connection->select('jobhunter_resume_parsed_data', 'rpd')
          ->fields('rpd', ['id', 'status'])
          ->condition('uid', $uid)
          ->condition('resume_file_id', $file->id())
          ->orderBy('changed', 'DESC')
          ->range(0, 1)
          ->execute()
          ->fetchAssoc();

        if (!empty($latest_parsed['status']) && in_array($latest_parsed['status'], ['queued', 'processing'], TRUE)) {
          $this->logInfo('📁 File already queued/processing, skipping duplicate queue: @filename', [
            '@filename' => $file->getFilename(),
          ]);
          continue;
        }

        $resume_id = $existing_record['id'];
        $this->logInfo('📁 File already registered; queueing reprocess: @filename', [
          '@filename' => $file->getFilename(),
        ]);
      }
      else {
        $existing_count = $connection->select('jobhunter_job_seeker_resumes', 'jsr')
          ->condition('job_seeker_id', $job_seeker_id)
          ->countQuery()
          ->execute()
          ->fetchField();

        $is_primary = ($existing_count == 0) ? 1 : 0;

        $resume_id = $connection->insert('jobhunter_job_seeker_resumes')
          ->fields([
            'job_seeker_id' => $job_seeker_id,
            'file_id' => $file->id(),
            'resume_name' => pathinfo($file->getFilename(), PATHINFO_FILENAME),
            'is_primary' => $is_primary,
            'created' => time(),
            'changed' => time(),
          ])
          ->execute();

        $this->logInfo('📁 Registered resume: @filename (resume_id: @id)', [
          '@filename' => $file->getFilename(),
          '@id' => $resume_id,
        ]);
      }

      try {
        $extracted_text = $this->extractTextFromFile($file);

        if (!empty($extracted_text)) {
          $connection->update('jobhunter_job_seeker_resumes')
            ->fields(['extracted_text' => $extracted_text])
            ->condition('id', $resume_id)
            ->execute();

          $this->logInfo('📁 Extracted @chars characters from: @filename', [
            '@chars' => strlen($extracted_text),
            '@filename' => $file->getFilename(),
          ]);

          $timestamp = \Drupal::time()->getRequestTime();
          $existing_parsed_id = $connection->select('jobhunter_resume_parsed_data', 'rpd')
            ->fields('rpd', ['id'])
            ->condition('uid', $uid)
            ->condition('resume_file_id', $file->id())
            ->orderBy('changed', 'DESC')
            ->range(0, 1)
            ->execute()
            ->fetchField();

          if ($existing_parsed_id) {
            $connection->update('jobhunter_resume_parsed_data')
              ->fields([
                'resume_path' => $file->getFileUri(),
                'parsed_data' => json_encode(['status' => 'queued']),
                'status' => 'queued',
                'error_message' => NULL,
                'changed' => $timestamp,
              ])
              ->condition('id', $existing_parsed_id)
              ->execute();
          }
          else {
            $connection->insert('jobhunter_resume_parsed_data')
              ->fields([
                'uid' => $uid,
                'resume_file_id' => $file->id(),
                'resume_path' => $file->getFileUri(),
                'parsed_data' => json_encode(['status' => 'queued']),
                'status' => 'queued',
                'error_message' => NULL,
                'created' => $timestamp,
                'changed' => $timestamp,
              ])
              ->execute();
          }

          $queue = \Drupal::queue('job_hunter_genai_parsing');
          $queue->createItem([
            'uid' => $uid,
            'resume_id' => $resume_id,
            'file_id' => $file->id(),
            'extracted_text' => $extracted_text,
            'filename' => $file->getFilename(),
          ]);

          $this->logInfo('📁 Queued for GenAI parsing: @filename', [
            '@filename' => $file->getFilename(),
          ]);
        }
      }
      catch (\Exception $e) {
        $this->logError('📁 Processing failed for @filename: @error', [
          '@filename' => $file->getFilename(),
          '@error' => $e->getMessage(),
        ]);
      }

      $processed_count++;
    }

    if ($processed_count > 0) {
      \Drupal::messenger()->addStatus($this->t('@count resume(s) queued for AI processing. Existing processed files were re-queued when selected. Processing runs automatically and usually completes in 5-10 minutes.', ['@count' => $processed_count]));
    }

    $form_state->setRedirect('job_hunter.user_profile_edit');
  }

  /**
   * Submit handler: clears the upload field to allow uploading another file.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function addAnotherResumeSubmit(array &$form, FormStateInterface $form_state): void {
    $this->logInfo('📁 Add Another Resume clicked');
    $form_state->setValue('field_resume_file', []);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler: legacy single-file add handler.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function addResumeSubmit(array &$form, FormStateInterface $form_state): void {
    \Drupal::logger('job_hunter_debug')->info('=== ADD RESUME SUBMIT CALLED ===');

    $uid = (int) $form_state->get('target_uid');
    \Drupal::logger('job_hunter_debug')->info('Submit handler: User ID @uid', ['@uid' => $uid]);

    $resume_file = $form_state->getValue('field_resume_file');
    $resume_name = $form_state->getValue('resume_name');

    \Drupal::logger('job_hunter_debug')->info('Submit handler: Resume file value - @file, Resume name - @name', [
      '@file' => print_r($resume_file, TRUE),
      '@name' => $resume_name ?: 'NOT PROVIDED',
    ]);

    if (!empty($resume_file[0])) {
      \Drupal::logger('job_hunter_debug')->info('Submit handler: File ID present - @fid', ['@fid' => $resume_file[0]]);

      $file = \Drupal\file\Entity\File::load($resume_file[0]);
      if ($file) {
        \Drupal::logger('job_hunter_debug')->info('Submit handler: File entity loaded - @filename (@fid)', [
          '@filename' => $file->getFilename(),
          '@fid' => $file->id(),
        ]);

        $file->setPermanent();
        $file->save();
        \Drupal::logger('job_hunter_debug')->info('Submit handler: File set to permanent and saved');

        $job_seeker_profile = $this->jobSeekerService->loadByUserId($uid);
        if (!$job_seeker_profile) {
          \Drupal::logger('job_hunter_debug')->info('Submit handler: Creating new job seeker profile for uid @uid', ['@uid' => $uid]);
          $job_seeker_data = ['uid' => $uid];
          $job_seeker_id = $this->jobSeekerService->create($job_seeker_data);
          $job_seeker_profile = $this->jobSeekerService->load($job_seeker_id);
        }

        \Drupal::logger('job_hunter_debug')->info('Submit handler: Job seeker profile ID - @id', ['@id' => $job_seeker_profile->id]);

        $database = \Drupal::database();
        $existing_count = $database->select('jobhunter_job_seeker_resumes', 'jsr')
          ->condition('job_seeker_id', $job_seeker_profile->id)
          ->countQuery()
          ->execute()
          ->fetchField();

        \Drupal::logger('job_hunter_debug')->info('Submit handler: Existing resume count - @count', ['@count' => $existing_count]);

        $is_primary = ($existing_count == 0) ? 1 : 0;

        \Drupal::logger('job_hunter_debug')->info('Submit handler: Inserting into jobhunter_job_seeker_resumes - job_seeker_id: @jsid, file_id: @fid, is_primary: @primary', [
          '@jsid' => $job_seeker_profile->id,
          '@fid' => $file->id(),
          '@primary' => $is_primary,
        ]);

        $insert_result = $database->insert('jobhunter_job_seeker_resumes')
          ->fields([
            'job_seeker_id' => $job_seeker_profile->id,
            'file_id' => $file->id(),
            'resume_name' => $resume_name ?: NULL,
            'is_primary' => $is_primary,
            'created' => time(),
            'changed' => time(),
          ])
          ->execute();

        \Drupal::logger('job_hunter_debug')->info('Submit handler: Database insert result - @result', ['@result' => $insert_result]);

        if ($is_primary) {
          $this->jobSeekerService->update($job_seeker_profile->id, [
            'resume_node_id' => $file->id(),
          ]);
        }

        $form_state->setValue('field_resume_file', []);
        $form_state->setValue('resume_name', '');
        $form_state->setRebuild(TRUE);

        \Drupal::logger('job_hunter_debug')->info('Submit handler: Form fields cleared, rebuild set to TRUE');
        \Drupal::messenger()->addMessage($this->t('Resume uploaded successfully!'));
        \Drupal::logger('job_hunter_debug')->info('=== ADD RESUME SUBMIT COMPLETED SUCCESSFULLY ===');
      }
      else {
        \Drupal::logger('job_hunter_debug')->error('Submit handler: FAILED to load file entity for FID @fid', ['@fid' => $resume_file[0]]);
      }
    }
    else {
      \Drupal::logger('job_hunter_debug')->error('Submit handler: NO FILE ID in form state value');
    }
  }

  /**
   * Submit handler: registers a scanned file into the database.
   *
   * Also automatically extracts text and queues GenAI parsing.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function registerResumeSubmit(array &$form, FormStateInterface $form_state): void {
    $this->logInfo('📝 REGISTER BUTTON CLICKED - registerResumeSubmit called');

    $triggering_element = $form_state->getTriggeringElement();
    $filename = $triggering_element['#attributes']['data-filename'] ?? NULL;

    $this->logInfo('📝 Register filename: @filename', ['@filename' => $filename ?? 'NULL']);

    if (!$filename) {
      \Drupal::messenger()->addError($this->t('Could not identify file to register.'));
      return;
    }

    $uid = (int) $form_state->get('target_uid');
    $connection = \Drupal::database();

    $job_seeker_profile = $this->jobSeekerService->loadByUserId($uid);
    if (!$job_seeker_profile) {
      $job_seeker_data = ['uid' => $uid];
      $job_seeker_id = $this->jobSeekerService->create($job_seeker_data);
      $job_seeker_profile = $this->jobSeekerService->load($job_seeker_id);
    }

    $file_uri = 'private://job_hunter/resumes/' . $uid . '/originalresumes/' . $filename;

    $file_entities = \Drupal::entityTypeManager()
      ->getStorage('file')
      ->loadByProperties(['uri' => $file_uri]);

    if (!empty($file_entities)) {
      $file = reset($file_entities);
    }
    else {
      $file = \Drupal\file\Entity\File::create([
        'uri' => $file_uri,
        'filename' => $filename,
        'status' => 1,
      ]);
      $file->save();
    }

    $existing = $connection->select('jobhunter_job_seeker_resumes', 'jsr')
      ->condition('job_seeker_id', (int) $job_seeker_profile->id)
      ->condition('file_id', $file->id())
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($existing > 0) {
      \Drupal::messenger()->addWarning($this->t('This resume is already registered.'));
      return;
    }

    $existing_count = $connection->select('jobhunter_job_seeker_resumes', 'jsr')
      ->condition('job_seeker_id', (int) $job_seeker_profile->id)
      ->countQuery()
      ->execute()
      ->fetchField();

    $is_primary = ($existing_count == 0) ? 1 : 0;

    $resume_id = $connection->insert('jobhunter_job_seeker_resumes')
      ->fields([
        'job_seeker_id' => (int) $job_seeker_profile->id,
        'file_id' => $file->id(),
        'resume_name' => pathinfo($filename, PATHINFO_FILENAME),
        'is_primary' => $is_primary,
        'created' => time(),
        'changed' => time(),
      ])
      ->execute();

    if ($is_primary) {
      $this->jobSeekerService->update($job_seeker_profile->id, [
        'resume_node_id' => $file->id(),
      ]);
    }

    $this->logInfo('📝 Resume registered with ID: @id', ['@id' => $resume_id]);

    try {
      $extracted_text = $this->extractTextFromFile($file);

      if (!empty($extracted_text)) {
        $connection->update('jobhunter_job_seeker_resumes')
          ->fields(['extracted_text' => $extracted_text])
          ->condition('id', $resume_id)
          ->execute();

        $this->logInfo('📝 Auto-extracted @chars characters from: @filename', [
          '@chars' => strlen($extracted_text),
          '@filename' => $filename,
        ]);

        $timestamp = \Drupal::time()->getRequestTime();
        $connection->insert('jobhunter_resume_parsed_data')
          ->fields([
            'uid' => $uid,
            'resume_file_id' => $file->id(),
            'resume_path' => $file->getFileUri(),
            'parsed_data' => json_encode(['status' => 'queued']),
            'status' => 'queued',
            'error_message' => NULL,
            'created' => $timestamp,
            'changed' => $timestamp,
          ])
          ->execute();

        $queue = \Drupal::queue('job_hunter_genai_parsing');
        $queue->createItem([
          'uid' => $uid,
          'resume_id' => $resume_id,
          'file_id' => $file->id(),
          'extracted_text' => $extracted_text,
          'filename' => $filename,
        ]);

        $this->logInfo('📝 Queued resume for GenAI parsing: @filename', ['@filename' => $filename]);

        \Drupal::messenger()->addStatus($this->t('Resume "@filename" has been registered. AI parsing has been queued.', ['@filename' => $filename]));
      }
      else {
        \Drupal::messenger()->addStatus($this->t('Resume "@filename" has been registered. (Text extraction returned empty - check file format)', ['@filename' => $filename]));
      }
    }
    catch (\Exception $e) {
      $this->logError('📝 Auto-parse failed: @error', ['@error' => $e->getMessage()]);
      \Drupal::messenger()->addWarning($this->t('Resume registered but parsing failed: @error', ['@error' => $e->getMessage()]));
    }

    $form_state->setRedirect('job_hunter.user_profile_edit');
  }

  /**
   * Submit handler: deletes a single resume file and all associated data.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function deleteResumeFileSubmit(array &$form, FormStateInterface $form_state): void {
    $triggering_element = $form_state->getTriggeringElement();
    $filename = $triggering_element['#attributes']['data-filename'] ?? NULL;
    $file_id_attr = $triggering_element['#attributes']['data-file-id'] ?? NULL;
    $target_file_id = is_numeric($file_id_attr) ? (int) $file_id_attr : 0;

    if (!$filename) {
      \Drupal::messenger()->addError($this->t('Could not identify file to delete.'));
      return;
    }

    $uid = \Drupal::currentUser()->id();
    $connection = \Drupal::database();
    $file_uri = 'private://job_hunter/resumes/' . $uid . '/originalresumes/' . $filename;
    $logger = \Drupal::logger('job_hunter');

    $logger->info('🗑️ Deleting resume file: @filename for user @uid', [
      '@filename' => $filename,
      '@uid' => $uid,
    ]);

    $file_entities = [];
    if ($target_file_id > 0) {
      $file_by_id = \Drupal\file\Entity\File::load($target_file_id);
      if ($file_by_id) {
        $file_entities[$target_file_id] = $file_by_id;
      }
    }
    if (empty($file_entities)) {
      $file_entities = \Drupal::entityTypeManager()
        ->getStorage('file')
        ->loadByProperties(['uri' => $file_uri]);
    }

    if (!empty($file_entities)) {
      foreach ($file_entities as $file) {
        $file_id = $file->id();

        $deleted_parsed = $connection->delete('jobhunter_resume_parsed_data')
          ->condition('uid', $uid)
          ->condition('resume_file_id', $file_id)
          ->execute();
        $logger->info('🗑️ Deleted @count parsed data records for file_id @fid', [
          '@count' => $deleted_parsed,
          '@fid' => $file_id,
        ]);

        $job_seeker_profile = $this->jobSeekerService->loadByUserId($uid);
        $job_seeker_id = $job_seeker_profile ? (int) $job_seeker_profile->id : 0;
        $deleted_resume = $connection->delete('jobhunter_job_seeker_resumes')
          ->condition('job_seeker_id', $job_seeker_id)
          ->condition('file_id', $file_id)
          ->execute();
        $logger->info('🗑️ Deleted @count resume records for file_id @fid', [
          '@count' => $deleted_resume,
          '@fid' => $file_id,
        ]);

        $connection->delete('queue')
          ->condition('name', 'job_hunter_genai_parsing')
          ->condition('data', '%"file_id":' . $file_id . '%', 'LIKE')
          ->execute();

        $file->delete();
      }
    }

    $file_system = \Drupal::service('file_system');
    $file_path = $file_system->realpath($file_uri);

    if ($file_path && file_exists($file_path) && is_file($file_path)) {
      unlink($file_path);
    }

    \Drupal::messenger()->addStatus($this->t('Resume file "@filename" and all associated data have been deleted.', ['@filename' => $filename]));
    $form_state->setRedirect('job_hunter.user_profile_edit');
  }

  /**
   * Submit handler: deletes all resume files, parsed data, and job seeker profile.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function deleteAllResumeDataSubmit(array &$form, FormStateInterface $form_state): void {
    $uid = \Drupal::currentUser()->id();
    $connection = \Drupal::database();
    $file_system = \Drupal::service('file_system');
    $logger = \Drupal::logger('job_hunter');

    $logger->info('🔍 DEBUG: deleteAllResumeDataSubmit called for user @uid', ['@uid' => $uid]);

    try {
      $deleted_count = 0;
      $errors = [];

      $before_delete = $connection->select('jobhunter_job_seeker', 'js')
        ->fields('js')
        ->condition('uid', $uid)
        ->execute()
        ->fetchAssoc();

      $logger->info('🔍 DEBUG: job_seeker record BEFORE delete: @exists', [
        '@exists' => $before_delete ? 'EXISTS' : 'NOT FOUND',
      ]);

      $job_seeker_profile = $this->jobSeekerService->loadByUserId($uid);
      $job_seeker_ids = array_values(array_unique(array_filter([
        (int) $uid,
        (int) ($job_seeker_profile->id ?? 0),
      ])));
      $resumes = $connection->select('jobhunter_job_seeker_resumes', 'jsr')
        ->fields('jsr', ['id', 'file_id', 'resume_name'])
        ->condition('job_seeker_id', $job_seeker_ids, 'IN')
        ->execute()
        ->fetchAll();

      $logger->info('🔍 DEBUG: Found @count resume(s) to delete', ['@count' => count($resumes)]);

      $orphaned_files = \Drupal::database()->select('file_managed', 'fm')
        ->fields('fm', ['fid', 'uri', 'filename'])
        ->condition('uri', 'private://job_hunter/resumes/' . $uid . '/%', 'LIKE')
        ->execute()
        ->fetchAll();

      $logger->info('🔍 DEBUG: Found @count file(s) in file_managed table', ['@count' => count($orphaned_files)]);

      foreach ($resumes as $resume) {
        $connection->delete('jobhunter_resume_parsed_data')
          ->condition('uid', $uid)
          ->condition('resume_file_id', $resume->file_id)
          ->execute();

        $connection->delete('queue')
          ->condition('name', 'job_hunter_genai_parsing')
          ->condition('data', '%"file_id":' . $resume->file_id . '%', 'LIKE')
          ->execute();

        try {
          $file = \Drupal\file\Entity\File::load($resume->file_id);
          if ($file) {
            $file_uri = $file->getFileUri();
            $file->delete();

            $file_path = $file_system->realpath($file_uri);
            if ($file_path && file_exists($file_path)) {
              unlink($file_path);
            }
          }
        }
        catch (\Exception $e) {
          $errors[] = "Error deleting file {$resume->resume_name}: " . $e->getMessage();
          $this->logError('Error deleting file entity: @error', [
            '@error' => $e->getMessage(),
            'file_id' => $resume->file_id,
          ]);
        }

        $connection->delete('jobhunter_job_seeker_resumes')
          ->condition('id', $resume->id)
          ->execute();

        $deleted_count++;
      }

      $orphaned_parsed = $connection->delete('jobhunter_resume_parsed_data')
        ->condition('uid', $uid)
        ->execute();
      if ($orphaned_parsed > 0) {
        $logger->info('🗑️ Deleted @count orphaned parsed data records for user @uid', [
          '@count' => $orphaned_parsed,
          '@uid' => $uid,
        ]);
      }

      $queues_to_check = [
        'job_hunter_genai_parsing',
        'job_hunter_text_extraction',
        'job_hunter_profile_text_extraction',
        'job_hunter_resume_tailoring',
        'job_hunter_job_posting_parsing',
      ];

      $total_deleted = 0;
      foreach ($queues_to_check as $queue_name) {
        $queue_items = $connection->select('queue', 'q')
          ->fields('q', ['item_id', 'data'])
          ->condition('q.name', $queue_name)
          ->execute()
          ->fetchAll();

        foreach ($queue_items as $item) {
          $item_data = unserialize($item->data);
          if (isset($item_data['uid']) && $item_data['uid'] == $uid) {
            $connection->delete('queue')
              ->condition('item_id', $item->item_id)
              ->execute();
            $total_deleted++;
          }
        }
      }

      if ($total_deleted > 0) {
        $logger->info('🗑️ Deleted @count queued items for user @uid', [
          '@count' => $total_deleted,
          '@uid' => $uid,
        ]);
      }

      foreach ($orphaned_files as $orphaned) {
        try {
          $file = \Drupal\file\Entity\File::load($orphaned->fid);
          if ($file) {
            $file_uri = $file->getFileUri();
            $logger->info('🔍 DEBUG: Deleting orphaned file entity fid=@fid, uri=@uri', [
              '@fid' => $orphaned->fid,
              '@uri' => $file_uri,
            ]);
            $file->delete();

            $file_path = $file_system->realpath($file_uri);
            if ($file_path && file_exists($file_path)) {
              unlink($file_path);
            }
            $deleted_count++;
          }
        }
        catch (\Exception $e) {
          $errors[] = "Error deleting orphaned file {$orphaned->filename}: " . $e->getMessage();
          $logger->error('Error deleting orphaned file entity: @error', [
            '@error' => $e->getMessage(),
            'fid' => $orphaned->fid,
          ]);
        }
      }

      $user_base_path = $file_system->realpath('private://job_hunter/resumes/' . $uid);
      if ($user_base_path && is_dir($user_base_path)) {
        $this->deleteDirectoryRecursive($user_base_path);
      }

      $logger->info('🔍 DEBUG: Attempting to delete jobhunter_job_seeker record for uid @uid', ['@uid' => $uid]);

      $profile_deleted = $connection->delete('jobhunter_job_seeker')
        ->condition('uid', $uid)
        ->execute();

      $logger->info('🔍 DEBUG: job_seeker delete result: @count rows affected', [
        '@count' => $profile_deleted,
      ]);

      $after_delete = $connection->select('jobhunter_job_seeker', 'js')
        ->fields('js')
        ->condition('uid', $uid)
        ->execute()
        ->fetchAssoc();

      $logger->info('🔍 DEBUG: job_seeker record AFTER delete: @exists', [
        '@exists' => $after_delete ? 'STILL EXISTS' : 'DELETED SUCCESSFULLY',
      ]);

      if ($profile_deleted) {
        $logger->info('Deleted job_seeker profile for user @uid', ['@uid' => $uid]);
      }

      $message_parts = [];
      if ($deleted_count > 0) {
        $message_parts[] = $this->t('@count resume file(s)', ['@count' => $deleted_count]);
      }
      if ($profile_deleted) {
        $message_parts[] = $this->t('all profile data');
      }

      if (!empty($message_parts)) {
        \Drupal::messenger()->addStatus($this->t('Successfully deleted: @items. Your profile has been reset to 0%.', [
          '@items' => implode(', ', $message_parts),
        ]));

        $this->logInfo('User @uid deleted all profile and resume data: @count files, profile: @profile', [
          '@uid' => $uid,
          '@count' => $deleted_count,
          '@profile' => $profile_deleted ? 'yes' : 'no',
        ]);
      }
      else {
        \Drupal::messenger()->addWarning($this->t('No profile or resume data found to delete.'));
      }

      if (!empty($errors)) {
        foreach ($errors as $error) {
          \Drupal::messenger()->addWarning($this->t('Warning: @error', ['@error' => $error]));
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::messenger()->addError($this->t('Error deleting profile and resume data: @error', [
        '@error' => $e->getMessage(),
      ]));
      $this->logError('Error in deleteAllResumeDataSubmit: @error', [
        '@error' => $e->getMessage(),
        'uid' => $uid,
      ]);
    }

    $input = $form_state->getUserInput();
    $preserved_keys = ['form_build_id', 'form_token', 'form_id', 'op'];
    foreach (array_keys($input) as $key) {
      if (!in_array($key, $preserved_keys)) {
        unset($input[$key]);
      }
    }
    $form_state->setUserInput($input);
    $form_state->setValues([]);
    $form_state->setRedirect('job_hunter.user_profile_edit');
  }

  /**
   * Extracts plain text from a file entity.
   *
   * Supports PDF (via pdftotext), DOCX (via docx2txt), DOC (via antiword),
   * and plain TXT files.
   *
   * @param \Drupal\file\Entity\File $file
   *   The file entity.
   *
   * @return string
   *   Extracted text, or empty string if extraction fails.
   */
  public function extractTextFromFile($file): string {
    $file_path = \Drupal::service('file_system')->realpath($file->getFileUri());
    $extension = pathinfo($file->getFilename(), PATHINFO_EXTENSION);

    switch (strtolower($extension)) {
      case 'txt':
        return file_get_contents($file_path);

      case 'pdf':
        $pdftotext = NULL;
        foreach (['/usr/bin/pdftotext', '/bin/pdftotext', 'pdftotext'] as $candidate) {
          if ($candidate === 'pdftotext' || file_exists($candidate)) {
            $pdftotext = $candidate;
            break;
          }
        }
        if ($pdftotext) {
          $cmd = escapeshellcmd($pdftotext) . ' ' . escapeshellarg($file_path) . ' -';
          $output = shell_exec($cmd);
          return is_string($output) ? $output : '';
        }
        break;

      case 'doc':
      case 'docx':
        if (strtolower($extension) === 'docx') {
          $docx2txt = NULL;
          foreach (['/usr/bin/docx2txt', '/bin/docx2txt', 'docx2txt'] as $candidate) {
            if ($candidate === 'docx2txt' || file_exists($candidate)) {
              $docx2txt = $candidate;
              break;
            }
          }
          if ($docx2txt) {
            $cmd = escapeshellcmd($docx2txt) . ' ' . escapeshellarg($file_path) . ' -';
            $output = shell_exec($cmd);
            return is_string($output) ? $output : '';
          }
        }
        if (strtolower($extension) === 'doc') {
          $antiword = NULL;
          foreach (['/usr/bin/antiword', '/bin/antiword', 'antiword'] as $candidate) {
            if ($candidate === 'antiword' || file_exists($candidate)) {
              $antiword = $candidate;
              break;
            }
          }
          if ($antiword) {
            $cmd = escapeshellcmd($antiword) . ' ' . escapeshellarg($file_path);
            $output = shell_exec($cmd);
            return is_string($output) ? $output : '';
          }
        }
        break;
    }

    return '';
  }

  /**
   * Retrieves the latest parsed data record for a given file, with legacy fallback.
   *
   * If no record matches by file_id, falls back to a URI/filename LIKE query
   * and repairs the linkage in-place when safe.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param int $uid
   *   The user ID.
   * @param int $file_id
   *   The managed file ID.
   * @param string $file_uri
   *   The file URI (used for linkage repair).
   * @param string $filename
   *   The file basename (used for legacy LIKE fallback).
   *
   * @return array|null
   *   Associative record array, or NULL if nothing found.
   */
  public function getLatestParsedRecordForFile($connection, int $uid, int $file_id, string $file_uri, string $filename): ?array {
    $parsed_record = $connection->select('jobhunter_resume_parsed_data', 'rpd')
      ->fields('rpd', ['id', 'parsed_data', 'status', 'error_message', 'resume_file_id'])
      ->condition('uid', $uid)
      ->condition('resume_file_id', $file_id)
      ->orderBy('changed', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if ($parsed_record) {
      return $parsed_record;
    }

    $like = '%' . $connection->escapeLike('/' . $filename);
    $legacy = $connection->select('jobhunter_resume_parsed_data', 'rpd')
      ->fields('rpd', ['id', 'parsed_data', 'status', 'error_message', 'resume_file_id', 'resume_path'])
      ->condition('uid', $uid)
      ->condition('resume_path', $like, 'LIKE')
      ->orderBy('changed', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (empty($legacy)) {
      return NULL;
    }

    if (!empty($legacy['resume_file_id']) && (int) $legacy['resume_file_id'] !== $file_id) {
      try {
        $connection->update('jobhunter_resume_parsed_data')
          ->fields([
            'resume_file_id' => $file_id,
            'resume_path' => $file_uri,
            'changed' => \Drupal::time()->getRequestTime(),
          ])
          ->condition('id', (int) $legacy['id'])
          ->execute();
        $legacy['resume_file_id'] = $file_id;
        $legacy['resume_path'] = $file_uri;
      }
      catch (\Exception $e) {
        $this->logWarning('Failed to repair resume_file_id on parsed_data row @id: @error', [
          '@id' => $legacy['id'],
          '@error' => $e->getMessage(),
        ]);
      }
    }

    if (($legacy['status'] ?? NULL) === 'completed') {
      $legacy['status'] = 'complete';
    }

    return $legacy;
  }

  /**
   * Recursively deletes a directory and all its contents.
   *
   * @param string $dir
   *   Absolute filesystem path to the directory.
   */
  public function deleteDirectoryRecursive(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
      $path = $dir . '/' . $file;
      if (is_dir($path)) {
        $this->deleteDirectoryRecursive($path);
      }
      else {
        @unlink($path);
      }
    }
    @rmdir($dir);
  }

}
