<?php

namespace Drupal\job_hunter\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\file\Entity\File;
use Drupal\user\Entity\User;
use Drupal\profile\Entity\Profile;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Profile resume text extraction queue worker.
 *
 * @QueueWorker(
 *   id = "job_hunter_profile_text_extraction",
 *   title = @Translation("Profile Resume Text Extraction"),
 *   cron = {"time" = 60}
 * )
 */
class ProfileTextExtractionWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
    $profile_id = $data['profile_id'];
    $user_id = $data['user_id'];
    $file_id = $data['file_id'];

    $profile = Profile::load($profile_id);
    $user = User::load($user_id);
    $file = File::load($file_id);

    if (!$profile || !$user || !$file) {
      \Drupal::logger('job_hunter')->error('Failed to load profile @pid, user @uid or file @fid for text extraction', [
        '@pid' => $profile_id,
        '@uid' => $user_id,
        '@fid' => $file_id
      ]);
      return;
    }

    $this->extractResumeTextToProfile($profile, $file);
  }

  /**
   * Extract text from resume file and populate profile field.
   */
  private function extractResumeTextToProfile(Profile $profile, File $file) {
    if (!$profile->hasField('field_primary_resume_text')) {
      \Drupal::logger('job_hunter')->error('Profile @pid does not have field_primary_resume_text field', [
        '@pid' => $profile->id()
      ]);
      return;
    }

    $file_uri = $file->getFileUri();
    $file_path = \Drupal::service('file_system')->realpath($file_uri);
    $mime_type = $file->getMimeType();

    // Set execution time limit to prevent timeouts
    set_time_limit(30);

    $extracted_text = '';

    try {
      \Drupal::logger('job_hunter')->info('Starting profile text extraction for file: @filename (Type: @mime_type)', [
        '@filename' => $file->getFilename(),
        '@mime_type' => $mime_type
      ]);

      switch ($mime_type) {
        case 'application/pdf':
          $extracted_text = $this->extractFromPdf($file_path);
          break;

        case 'application/msword':
        case 'application/vnd.ms-word':
          $extracted_text = $this->extractFromDoc($file_path);
          break;

        case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
          $extracted_text = $this->extractFromDocx($file_path);
          break;

        case 'text/plain':
          $extracted_text = file_get_contents($file_path);
          break;

        default:
          \Drupal::logger('job_hunter')->warning('Unsupported file type for text extraction: @mime_type', [
            '@mime_type' => $mime_type
          ]);
          return;
      }

      if (!empty($extracted_text)) {
        // Clean and truncate the text
        $extracted_text = trim($extracted_text);
        $extracted_text = preg_replace('/\s+/', ' ', $extracted_text); // Normalize whitespace
        
        // Truncate to reasonable length (10,000 characters)
        if (strlen($extracted_text) > 10000) {
          $extracted_text = substr($extracted_text, 0, 10000) . "\n\n[Text truncated...]";
        }

        // Save to profile field
        $profile->set('field_primary_resume_text', $extracted_text);
        $profile->save();

        \Drupal::logger('job_hunter')->info('Successfully extracted @chars characters of text to profile @pid', [
          '@chars' => strlen($extracted_text),
          '@pid' => $profile->id()
        ]);
      } else {
        \Drupal::logger('job_hunter')->warning('No text extracted from file: @filename', [
          '@filename' => $file->getFilename()
        ]);
      }
    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Error extracting text from resume file: @message', [
        '@message' => $e->getMessage()
      ]);
    }
  }

  /**
   * Extract text from PDF file using pdftotext.
   */
  private function extractFromPdf($file_path) {
    $command = sprintf('timeout 20 pdftotext %s -', escapeshellarg($file_path));
    $output = shell_exec($command);
    return $output ?: '';
  }

  /**
   * Extract text from DOC file using antiword.
   */
  private function extractFromDoc($file_path) {
    $command = sprintf('timeout 20 antiword %s', escapeshellarg($file_path));
    $output = shell_exec($command);
    return $output ?: '';
  }

  /**
   * Extract text from DOCX file using docx2txt.
   */
  private function extractFromDocx($file_path) {
    $command = sprintf('timeout 20 docx2txt %s -', escapeshellarg($file_path));
    $output = shell_exec($command);
    return $output ?: '';
  }
}