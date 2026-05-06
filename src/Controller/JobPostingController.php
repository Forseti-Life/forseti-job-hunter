<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Controller for job posting operations.
 */
class JobPostingController extends ControllerBase {

  /**
   * Queue name constant for job posting parsing.
   */
  public const QUEUE_NAME = 'job_hunter_job_posting_parsing';

  /**
   * Status constant for pending AI extraction.
   */
  public const STATUS_PENDING = 'pending';

  /**
   * Table name constant for job requirements.
   */
  public const TABLE_NAME = 'jobhunter_job_requirements';

  /**
   * Retry AI parsing for a failed job posting.
   *
   * @param int $job_id
   *   The job ID to retry parsing for.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect back to referrer or jobs list.
   */
  public function retryParsing($job_id) {
    // Validate job_id parameter.
    $job_id = (int) $job_id;
    if ($job_id <= 0) {
      throw new BadRequestHttpException('Invalid job ID provided.');
    }

    $database = \Drupal::database();

    try {
      // Get job details.
      $job = $database->select(self::TABLE_NAME, 'j')
        ->fields('j', ['id', 'raw_posting_text', 'job_description', 'ai_extraction_status'])
        ->condition('id', $job_id)
        ->execute()
        ->fetchObject();

      if (!$job) {
        $this->messenger()->addError($this->t('Job posting not found.'));
        return new RedirectResponse(Url::fromRoute('job_hunter.jobs_list')->toString());
      }

      // Fall back to job_description for jobs scraped without raw_posting_text.
      $posting_text = $job->raw_posting_text ?: $job->job_description;

      if (empty($posting_text)) {
        $this->messenger()->addError($this->t('Cannot retry parsing: No job text available to parse.'));
        return $this->redirect('job_hunter.job_view', ['job_id' => $job_id]);
      }

      // Use transaction to ensure atomic operations.
      $transaction = $database->startTransaction();

      try {
        // Reset AI extraction status.
        $database->update(self::TABLE_NAME)
          ->fields([
            'ai_extraction_status' => self::STATUS_PENDING,
          ])
          ->condition('id', $job_id)
          ->execute();

        // Re-queue for AI parsing using best available text.
        $queue = \Drupal::queue(self::QUEUE_NAME);
        $queue->createItem([
          'job_id' => $job_id,
          'raw_posting_text' => $posting_text,
        ]);

        // Log the action.
        \Drupal::logger('job_hunter')->info('Job posting #@id re-queued for AI parsing by user @user', [
          '@id' => $job_id,
          '@user' => $this->currentUser()->getDisplayName(),
        ]);

        $this->messenger()->addMessage($this->t('Job posting #@id has been re-queued for AI parsing.', [
          '@id' => $job_id,
        ]));
      }
      catch (\Exception $e) {
        $transaction->rollBack();
        \Drupal::logger('job_hunter')->error('Failed to retry parsing for job @id: @error', [
          '@id' => $job_id,
          '@error' => $e->getMessage(),
        ]);
        $this->messenger()->addError($this->t('Failed to re-queue job posting. Please try again.'));
        return new RedirectResponse(Url::fromRoute('job_hunter.jobs_list')->toString());
      }
    }
    catch (DatabaseExceptionWrapper $e) {
      \Drupal::logger('job_hunter')->error('Database error in retry parsing: @error', [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An error occurred. Please try again.'));
      return new RedirectResponse(Url::fromRoute('job_hunter.jobs_list')->toString());
    }

    // Redirect back to safe referrer or jobs list.
    return $this->getSafeRedirect();
  }

  /**
   * Get a safe redirect URL, validating referrer against current host.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A safe redirect response.
   */
  protected function getSafeRedirect() {
    $referer = \Drupal::request()->headers->get('referer');

    if ($referer) {
      // Validate referrer is from the same domain.
      $request_host = \Drupal::request()->getHost();
      $request_scheme = \Drupal::request()->getScheme();
      $referer_parsed = parse_url($referer);

      // Check host and scheme match.
      if (isset($referer_parsed['host'], $referer_parsed['scheme']) &&
          $referer_parsed['host'] === $request_host &&
          $referer_parsed['scheme'] === $request_scheme) {
        return new RedirectResponse($referer);
      }
    }

    // Default to jobs list if no valid referrer.
    return new RedirectResponse(Url::fromRoute('job_hunter.jobs_list')->toString());
  }

}
