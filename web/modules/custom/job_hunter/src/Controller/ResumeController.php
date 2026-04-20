<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\job_hunter\Service\ResumePdfService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for resume PDF generation and download.
 */
class ResumeController extends ControllerBase {

  /**
   * Maximum file size in bytes (10MB).
   */
  private const MAX_PDF_SIZE = 10 * 1024 * 1024;

  /**
   * Maximum filename length.
   */
  private const MAX_FILENAME_LENGTH = 50;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The resume PDF service.
   *
   * @var \Drupal\job_hunter\Service\ResumePdfService
   */
  protected ResumePdfService $pdfService;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The time service.
   *
   * @var \Drupal\Core\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a ResumeController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\job_hunter\Service\ResumePdfService $pdf_service
   *   The resume PDF service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    Connection $database,
    ResumePdfService $pdf_service,
    FileSystemInterface $file_system,
    TimeInterface $time,
    LoggerInterface $logger
  ) {
    $this->database = $database;
    $this->pdfService = $pdf_service;
    $this->fileSystem = $file_system;
    $this->time = $time;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('job_hunter.resume_pdf_service'),
      $container->get('file_system'),
      $container->get('datetime.time'),
      $container->get('logger.factory')->get('job_hunter')
    );
  }

  /**
   * Generate and save a tailored resume PDF for a specific job.
   *
   * @param int $job_id
   *   The job ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with PDF info.
   */
  public function generateTailoredPdf(int $job_id): \Symfony\Component\HttpFoundation\JsonResponse {
    $userId = $this->currentUser()->id();

    // Get tailored resume for this job.
    $tailoredRecord = $this->database->select('jobhunter_tailored_resumes', 'tr')
      ->fields('tr', ['id', 'tailored_resume_json'])
      ->condition('job_id', $job_id)
      ->condition('uid', $userId)
      ->execute()
      ->fetchAssoc();

    if (!$tailoredRecord || empty($tailoredRecord['tailored_resume_json'])) {
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => FALSE,
        'message' => 'No tailored resume found. Please generate a tailored resume first.',
      ], 400);
    }

    $content = json_decode($tailoredRecord['tailored_resume_json'], TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => FALSE,
        'message' => 'Invalid tailored resume data.',
      ], 400);
    }

    // Get job info for filename.
    $job = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j', ['job_title', 'extracted_json'])
      ->condition('id', $job_id)
      ->execute()
      ->fetchAssoc();

    $jobTitle = '';
    $companyName = '';
    if ($job) {
      $extractedData = json_decode($job['extracted_json'] ?? '', TRUE);
      $jobTitle = $extractedData['position']['title'] ?? $job['job_title'] ?? 'Position';
      $companyName = $extractedData['company']['name'] ?? '';
    }

    // Generate filename with timestamp.
    $filename = $this->generateFilename($content, $companyName, $jobTitle, TRUE);

    // Generate and save PDF.
    $pdfContent = $this->pdfService->generatePdf($content);
    if ($pdfContent === NULL) {
      $this->logger->error('Failed to generate PDF for user @uid, job @job_id', [
        '@uid' => $userId,
        '@job_id' => $job_id,
      ]);
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to generate PDF.',
      ], 500);
    }

    // Save to private files directory (tailored resumes).
    $directory = 'private://job_hunter/resumes/' . $userId . '/tailoredresumes';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $filepath = $directory . '/' . $filename;
    $saved = $this->fileSystem->saveData($pdfContent, $filepath, FileSystemInterface::EXISTS_REPLACE);

    if (!$saved) {
      $this->logger->error('Failed to save PDF file for user @uid, job @job_id', [
        '@uid' => $userId,
        '@job_id' => $job_id,
      ]);
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to save PDF file.',
      ], 500);
    }

    // Update the database record.
    $requestTime = $this->time->getRequestTime();
    $this->database->update('jobhunter_tailored_resumes')
      ->fields([
        'pdf_path' => $filepath,
        'pdf_generated' => $requestTime,
      ])
      ->condition('id', $tailoredRecord['id'])
      ->execute();

    // Insert into PDF history table.
    $this->database->insert('jobhunter_pdf_history')
      ->fields([
        'uid' => $userId,
        'job_id' => $job_id,
        'filename' => $filename,
        'filepath' => $filepath,
        'filesize' => strlen($pdfContent),
        'created' => $requestTime,
      ])
      ->execute();

    $this->logger->info('PDF generated successfully for user @uid, job @job_id, filename @filename', [
      '@uid' => $userId,
      '@job_id' => $job_id,
      '@filename' => $filename,
    ]);

    return new \Symfony\Component\HttpFoundation\JsonResponse([
      'success' => TRUE,
      'message' => 'PDF generated successfully.',
      'filename' => $filename,
      'generated' => date('Y-m-d H:i:s', $requestTime),
    ]);
  }

  /**
   * Generate tailored PDF and redirect back to caller.
   *
   * Used for non-AJAX links (e.g., My Jobs action column) so users can click
   * once, get PDF generation confirmation, and land back on the originating
   * page with status updated.
   *
   * @param int $job_id
   *   The job ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response back to my-jobs (or provided return_to path).
   */
  public function generateTailoredPdfAndReturn(int $job_id): RedirectResponse {
    $request = \Drupal::request();
    $return_to = (string) $request->query->get('return_to', '/jobhunter/my-jobs');
    if (!preg_match('/^\/(?!\/)/', $return_to)) {
      $return_to = '/jobhunter/my-jobs';
    }

    $response = $this->generateTailoredPdf($job_id);
    $payload = [];
    $raw = $response->getContent();
    if (is_string($raw) && $raw !== '') {
      $decoded = json_decode($raw, TRUE);
      if (is_array($decoded)) {
        $payload = $decoded;
      }
    }

    if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && !empty($payload['success'])) {
      $this->messenger()->addStatus($this->t('Resume PDF generated successfully.'));
    }
    else {
      $message = !empty($payload['message']) ? (string) $payload['message'] : 'Failed to generate resume PDF.';
      $this->messenger()->addError($this->t($message));
    }

    return new RedirectResponse($return_to);
  }

  /**
   * Download a specific PDF by its history ID.
   *
   * @param int $pdf_id
   *   The PDF history ID.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The PDF response.
   */
  public function downloadPdfById(int $pdf_id): Response {
    $userId = (int) $this->currentUser()->id();

    // Get PDF record.
    $pdfRecord = $this->database->select('jobhunter_pdf_history', 'ph')
      ->fields('ph', ['filepath', 'filename', 'uid'])
      ->condition('id', $pdf_id)
      ->execute()
      ->fetchAssoc();

    if (!$pdfRecord) {
      throw new NotFoundHttpException('PDF not found.');
    }

    // Security check - make sure user owns this PDF.
    if ((int) $pdfRecord['uid'] !== $userId) {
      $this->logger->warning('User @uid attempted to access PDF @pdf_id owned by @owner', [
        '@uid' => $userId,
        '@pdf_id' => $pdf_id,
        '@owner' => $pdfRecord['uid'],
      ]);
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Access denied.');
    }

    // Read the file.
    $realPath = $this->fileSystem->realpath($pdfRecord['filepath']);

    if (!$realPath || !file_exists($realPath)) {
      $this->logger->error('PDF file not found on disk: @path', ['@path' => $pdfRecord['filepath']]);
      throw new NotFoundHttpException('PDF file not found on disk.');
    }

    // Check file size before reading.
    $fileSize = filesize($realPath);
    if ($fileSize > self::MAX_PDF_SIZE) {
      $this->logger->error('PDF file too large: @size bytes', ['@size' => $fileSize]);
      throw new \RuntimeException('PDF file is too large to download.');
    }

    $pdfContent = file_get_contents($realPath);

    $response = new Response($pdfContent);
    $response->headers->set('Content-Type', 'application/pdf');
    
    // Use RFC 5987 encoding for filename.
    $encodedFilename = rawurlencode($pdfRecord['filename']);
    $response->headers->set('Content-Disposition', 
      'attachment; filename="' . addslashes($pdfRecord['filename']) . '"; ' .
      "filename*=UTF-8''" . $encodedFilename
    );
    
    $response->headers->set('Content-Length', strlen($pdfContent));
    $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');
    $response->headers->set('Pragma', 'public');

    $this->logger->info('PDF downloaded by user @uid: @filename', [
      '@uid' => $userId,
      '@filename' => $pdfRecord['filename'],
    ]);

    return $response;
  }

  /**
   * Delete a specific PDF by its history ID.
   *
   * @param int $pdf_id
   *   The PDF history ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function deletePdf(int $pdf_id): \Symfony\Component\HttpFoundation\JsonResponse {
    $userId = (int) $this->currentUser()->id();

    // Get PDF record.
    $pdfRecord = $this->database->select('jobhunter_pdf_history', 'ph')
      ->fields('ph', ['id', 'filepath', 'filename', 'uid', 'job_id'])
      ->condition('id', $pdf_id)
      ->execute()
      ->fetchAssoc();

    if (!$pdfRecord) {
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => FALSE,
        'message' => 'PDF not found.',
      ], 404);
    }

    // Security check - make sure user owns this PDF.
    if ((int) $pdfRecord['uid'] !== $userId) {
      $this->logger->warning('User @uid attempted to delete PDF @pdf_id owned by @owner', [
        '@uid' => $userId,
        '@pdf_id' => $pdf_id,
        '@owner' => $pdfRecord['uid'],
      ]);
      return new \Symfony\Component\HttpFoundation\JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied.',
      ], 403);
    }

    // Delete the file from disk.
    $realPath = $this->fileSystem->realpath($pdfRecord['filepath']);

    if ($realPath && file_exists($realPath)) {
      if (!unlink($realPath)) {
        $this->logger->warning('Failed to delete PDF file: @path', ['@path' => $realPath]);
      }
    }

    // Delete from database.
    $this->database->delete('jobhunter_pdf_history')
      ->condition('id', $pdf_id)
      ->execute();

    $this->logger->info('PDF deleted by user @uid: @filename', [
      '@uid' => $userId,
      '@filename' => $pdfRecord['filename'],
    ]);

    // Check if this was the latest PDF and update tailored_resumes table.
    $latestPdf = $this->database->select('jobhunter_pdf_history', 'ph')
      ->fields('ph', ['filepath', 'created'])
      ->condition('uid', $userId)
      ->condition('job_id', $pdfRecord['job_id'])
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if ($latestPdf) {
      $this->database->update('jobhunter_tailored_resumes')
        ->fields([
          'pdf_path' => $latestPdf['filepath'],
          'pdf_generated' => $latestPdf['created'],
        ])
        ->condition('uid', $userId)
        ->condition('job_id', $pdfRecord['job_id'])
        ->execute();
    } else {
      // No more PDFs, clear the path.
      $this->database->update('jobhunter_tailored_resumes')
        ->fields([
          'pdf_path' => NULL,
          'pdf_generated' => 0,
        ])
        ->condition('uid', $userId)
        ->condition('job_id', $pdfRecord['job_id'])
        ->execute();
    }

    return new \Symfony\Component\HttpFoundation\JsonResponse([
      'success' => TRUE,
      'message' => 'PDF deleted successfully.',
      'filename' => $pdfRecord['filename'],
    ]);
  }

  /**
   * Download a tailored resume as PDF for a specific job.
   *
   * @param int $job_id
   *   The job ID.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The PDF response.
   */
  public function downloadTailoredPdf(int $job_id): Response {
    $userId = $this->currentUser()->id();

    // First try to get tailored resume for this job.
    $tailoredResume = $this->database->select('jobhunter_tailored_resumes', 'tr')
      ->fields('tr', ['tailored_resume_json'])
      ->condition('job_id', $job_id)
      ->condition('uid', $userId)
      ->execute()
      ->fetchField();

    if ($tailoredResume) {
      $content = json_decode($tailoredResume, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        throw new NotFoundHttpException('Invalid tailored resume data.');
      }
    }
    else {
      // Fall back to base resume.
      $content = $this->getBaseResumeContent($userId);
      if (!$content) {
        throw new NotFoundHttpException('No resume found. Please create your job seeker profile first.');
      }
    }

    // Get job info for filename.
    $job = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j', ['job_title', 'extracted_json'])
      ->condition('id', $job_id)
      ->execute()
      ->fetchAssoc();

    $jobTitle = '';
    $companyName = '';
    if ($job) {
      $extractedData = json_decode($job['extracted_json'] ?? '', TRUE);
      $jobTitle = $extractedData['position']['title'] ?? $job['job_title'] ?? 'Position';
      $companyName = $extractedData['company']['name'] ?? '';
    }

    // Generate filename.
    $filename = $this->generateFilename($content, $companyName, $jobTitle);

    return $this->generatePdfResponse($content, $filename);
  }

  /**
   * Download the base resume as PDF.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The PDF response.
   */
  public function downloadBasePdf(): Response {
    $userId = $this->currentUser()->id();
    $content = $this->getBaseResumeContent($userId);

    if (!$content) {
      throw new NotFoundHttpException('No resume found. Please create your job seeker profile first.');
    }

    // Generate filename.
    $filename = $this->generateFilename($content, NULL, NULL, FALSE, TRUE);

    return $this->generatePdfResponse($content, $filename);
  }

  /**
   * Get base resume content for user.
   *
   * @param int $user_id
   *   The user ID.
   *
   * @return array|null
   *   The resume content or NULL.
   */
  protected function getBaseResumeContent(int $user_id): ?array {
    $result = $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js', ['consolidated_profile_json'])
      ->condition('uid', $user_id)
      ->execute()
      ->fetchField();

    if (!$result) {
      return NULL;
    }

    $content = json_decode($result, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return NULL;
    }

    return $content;
  }

  /**
   * Generate PDF response.
   *
   * @param array $content
   *   The resume content.
   * @param string $filename
   *   The filename.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The PDF response.
   */
  protected function generatePdfResponse(array $content, string $filename): Response {
    $pdfContent = $this->pdfService->generatePdf($content);

    if ($pdfContent === NULL) {
      $this->logger->error('Failed to generate PDF for user @uid', [
        '@uid' => $this->currentUser()->id(),
      ]);
      throw new NotFoundHttpException('Failed to generate PDF.');
    }

    $response = new Response($pdfContent);
    $response->headers->set('Content-Type', 'application/pdf');
    
    // Use RFC 5987 encoding for filename.
    $encodedFilename = rawurlencode($filename);
    $response->headers->set('Content-Disposition',
      'attachment; filename="' . addslashes($filename) . '"; ' .
      "filename*=UTF-8''" . $encodedFilename
    );
    
    $response->headers->set('Content-Length', strlen($pdfContent));
    $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');
    $response->headers->set('Pragma', 'public');

    return $response;
  }

  /**
   * Generate a filename for a resume PDF.
   *
   * @param array $content
   *   The resume content.
   * @param string|null $companyName
   *   Optional company name to include.
   * @param string|null $jobTitle
   *   Optional job title to include.
   * @param bool $includeTimestamp
   *   Whether to include a timestamp.
   * @param bool $includeResumeLabel
   *   Whether to include "_Resume" suffix (for base resumes).
   *
   * @return string
   *   The generated filename with .pdf extension.
   */
  protected function generateFilename(
    array $content,
    ?string $companyName = NULL,
    ?string $jobTitle = NULL,
    bool $includeTimestamp = FALSE,
    bool $includeResumeLabel = FALSE
  ): string {
    $name = $content['contact_info']['full_name'] ?? 'Resume';
    $filename = $this->sanitizeFilename($name);

    if ($companyName) {
      $filename .= '_' . $this->sanitizeFilename($companyName);
    }
    if ($jobTitle) {
      $filename .= '_' . $this->sanitizeFilename($jobTitle);
    }
    if ($includeResumeLabel) {
      $filename .= '_Resume';
    }
    if ($includeTimestamp) {
      $timestamp = $this->time->getRequestTime();
      $filename .= '_' . date('Ymd_His', $timestamp);
    }

    return $filename . '.pdf';
  }

  /**
   * Sanitize a string for use in a filename.
   *
   * @param string $string
   *   The string to sanitize.
   *
   * @return string
   *   The sanitized string.
   */
  protected function sanitizeFilename(string $string): string {
    // Remove special characters.
    $string = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $string);
    // Replace spaces with underscores.
    $string = preg_replace('/\s+/', '_', $string);
    // Limit length.
    $string = substr($string, 0, self::MAX_FILENAME_LENGTH);
    // Remove trailing underscores.
    $string = rtrim($string, '_');

    return $string;
  }

}
