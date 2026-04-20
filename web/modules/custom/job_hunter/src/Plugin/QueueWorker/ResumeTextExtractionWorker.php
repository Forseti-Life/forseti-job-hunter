<?php

namespace Drupal\job_hunter\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\file\Entity\File;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resume text extraction queue worker.
 *
 * @QueueWorker(
 *   id = "job_hunter_text_extraction",
 *   title = @Translation("Resume Text Extraction"),
 *   cron = {"time" = 60}
 * )
 */
class ResumeTextExtractionWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $user_id = $data['user_id'];
    $file_id = $data['file_id'];

    $user = User::load($user_id);
    $file = File::load($file_id);

    if (!$user || !$file) {
      \Drupal::logger('job_hunter')->error('Failed to load user @uid or file @fid for text extraction', [
        '@uid' => $user_id,
        '@fid' => $file_id
      ]);
      return;
    }

    $this->extractResumeText($user, $file);
  }

  /**
   * Extract text from resume file.
   */
  protected function extractResumeText(User $user, File $file) {
    if (!$user->hasField('field_primary_resume_text')) {
      return;
    }

    $file_uri = $file->getFileUri();
    $file_path = \Drupal::service('file_system')->realpath($file_uri);
    $mime_type = $file->getMimeType();

    $extracted_text = '';

    try {
      \Drupal::logger('job_hunter')->info('Processing text extraction for file: @filename (Type: @mime_type)', [
        '@filename' => $file->getFilename(),
        '@mime_type' => $mime_type
      ]);

      switch ($mime_type) {
        case 'application/pdf':
          $extracted_text = $this->extractPdfText($file_path);
          break;

        case 'application/msword':
        case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
          $extracted_text = $this->extractDocText($file_path, $mime_type);
          break;

        default:
          \Drupal::logger('job_hunter')->warning('Unsupported file type for text extraction: @mime_type', ['@mime_type' => $mime_type]);
          return;
      }

      if (!empty($extracted_text)) {
        // Truncate very long text to prevent database issues
        if (strlen($extracted_text) > 50000) {
          $extracted_text = substr($extracted_text, 0, 50000) . "\n\n[Content truncated due to length]";
        }

        $user->set('field_primary_resume_text', $extracted_text);
        $user->save();

        \Drupal::logger('job_hunter')->info('Resume text extracted and saved for user @uid (Length: @length chars)', [
          '@uid' => $user->id(),
          '@length' => strlen($extracted_text)
        ]);
      } else {
        \Drupal::logger('job_hunter')->warning('No text extracted from file: @filename', [
          '@filename' => $file->getFilename()
        ]);
      }

    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Error extracting resume text: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * Extract text from PDF file.
   */
  protected function extractPdfText($file_path) {
    $text = '';

    if (shell_exec('which pdftotext')) {
      $command = sprintf('timeout 30s pdftotext %s -', escapeshellarg($file_path));
      $text = shell_exec($command);

      if ($text === null) {
        \Drupal::logger('job_hunter')->warning('PDF text extraction timed out for file: @file', ['@file' => $file_path]);
        return '';
      }
    }

    return trim($text);
  }

  /**
   * Extract text from Word document.
   */
  protected function extractDocText($file_path, $mime_type) {
    $text = '';

    if ($mime_type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
      // DOCX file
      if (shell_exec('which docx2txt')) {
        $command = sprintf('timeout 20s docx2txt %s -', escapeshellarg($file_path));
        $text = shell_exec($command);

        if ($text === null) {
          \Drupal::logger('job_hunter')->warning('DOCX text extraction timed out for file: @file', ['@file' => $file_path]);
          return '';
        }
      }
    } else {
      // DOC file (older format)
      if (shell_exec('which antiword')) {
        $command = sprintf('timeout 20s antiword %s', escapeshellarg($file_path));
        $text = shell_exec($command);

        if ($text === null) {
          \Drupal::logger('job_hunter')->warning('DOC text extraction timed out for file: @file', ['@file' => $file_path]);
          return '';
        }
      }
    }

    return trim($text);
  }

}