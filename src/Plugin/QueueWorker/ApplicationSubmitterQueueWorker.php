<?php

namespace Drupal\job_hunter\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\job_hunter\Traits\QueueWorkerBaseTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Application submission queue worker.
 *
 * Processes job applications asynchronously by automating form submission
 * using browser automation (Playwright/Puppeteer).
 *
 * @QueueWorker(
 *   id = "job_hunter_application_submission",
 *   title = @Translation("Job Application Submission"),
 *   cron = {"time" = 120}
 * )
 */
class ApplicationSubmitterQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use QueueWorkerBaseTrait;

  /**
   * The application submission service.
   *
   * @var \Drupal\job_hunter\Service\ApplicationSubmissionService
   */
  protected $applicationSubmissionService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->applicationSubmissionService = $container->get('job_hunter.application_submission_service');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $uid = $data['uid'];
    $job_id = $data['job_id'];
    $application_id = $data['application_id'];
    $app_data = $data['app_data'];

    $logger = \Drupal::logger('job_hunter');
    $logger->info('📧 Queue: Starting application submission for user @uid, job @job_id, application @app_id', [
      '@uid' => $uid,
      '@job_id' => $job_id,
      '@app_id' => $application_id,
    ]);

    try {
      // Update status to processing
      $this->applicationSubmissionService->updateApplicationStatus(
        $application_id,
        'processing',
        []
      );

      // Attempt submission via browser automation.
      // Inject application_id into app_data so BrowserAutomationService can use it.
      $app_data['application_id'] = $application_id;
      $result = $this->submitApplicationViaBrowser($app_data);

      if ($result['success']) {
        $logger->info('✅ Application submitted successfully for user @uid, job @job_id. Confirmation: @confirmation', [
          '@uid' => $uid,
          '@job_id' => $job_id,
          '@confirmation' => $result['confirmation'] ?? 'N/A',
        ]);

        // Update application status to submitted
        $this->applicationSubmissionService->updateApplicationStatus(
          $application_id,
          'submitted',
          [
            'confirmation' => $result['confirmation'] ?? '',
            'automation_success' => TRUE,
          ]
        );

        // Update job_requirements table
        $this->updateJobSubmissionStatus($job_id, 'submitted');
      } else {
        // Submission requires manual action or failed — log and mark accordingly.
        $reason = $result['reason'] ?? 'unknown';
        $is_manual = in_array($reason, ['no_direct_ats', 'no_credentials', 'phase2_pending', 'custom_page', 'manual_required', 'job_expired', 'apply_form_unavailable']);

        $logger->warning('⚠️ Application requires manual action for user @uid, job @job_id. Reason: @reason', [
          '@uid'    => $uid,
          '@job_id' => $job_id,
          '@reason' => $reason,
        ]);

        $this->applicationSubmissionService->updateApplicationStatus(
          $application_id,
          'manual_required',
          [
            'error' => [
              'message'      => $result['error'] ?? 'Manual submission required',
              'reason'       => $reason,
              'apply_url'    => $result['apply_url'] ?? '',
              'ats_platform' => $result['ats_platform'] ?? '',
              'instructions' => $result['instructions'] ?? '',
            ],
            'admin_review' => !$is_manual, // Only flag for admin review on true failures.
          ]
        );

        // Only queue for error review on unexpected failures, not routine manual cases.
        if (!$is_manual) {
          $this->queueForErrorQueue($uid, $job_id, $application_id, $result['error'] ?? 'Application submission failed');
        }
      }
    } catch (SuspendQueueException $e) {
      // Database unavailable or other critical issue
      $logger->error('🔴 Queue worker suspended during application submission: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    } catch (\Exception $e) {
      $logger->error('🔴 Queue worker exception during application submission: @error', [
        '@error' => $e->getMessage(),
      ]);

      // Mark for manual review and continue
      $this->applicationSubmissionService->updateApplicationStatus(
        $application_id,
        'manual_required',
        [
          'error' => [
            'message' => $e->getMessage(),
            'reason' => 'exception',
          ],
          'admin_review' => TRUE,
        ]
      );

      $this->queueForErrorQueue($uid, $job_id, $application_id, 'Exception: ' . $e->getMessage());
    }
  }

  /**
   * Submits application via browser automation.
   *
   * @param array $app_data
   *   The application data prepared by ApplicationSubmissionService.
   *
   * @return array
   *   Result with structure:
   *   [
   *     'success' => bool,
   *     'confirmation' => string,
   *     'error' => string,
   *     'reason' => string (captcha|authentication|form_error|timeout|unsupported),
   *   ]
   */
  protected function submitApplicationViaBrowser(array $app_data): array {
    $logger = \Drupal::logger('job_hunter');

    try {
      /** @var \Drupal\job_hunter\Service\BrowserAutomationService $browser_service */
      $browser_service = \Drupal::service('job_hunter.browser_automation_service');

      // application_id is passed via app_data by the queue payload.
      $application_id = (int) ($app_data['application_id'] ?? 0);
      if (!$application_id) {
        return [
          'success' => FALSE,
          'error'   => 'No application_id in queue payload',
          'reason'  => 'missing_application_id',
        ];
      }

      $result = $browser_service->processApplication($app_data, $application_id);

      // Translate BrowserAutomationService result to the format processItem() expects.
      return [
        'success'       => $result['success'],
        'confirmation'  => $result['confirmation'] ?? '',
        'error'         => $result['error'] ?? '',
        'reason'        => $result['reason'] ?? $result['outcome'] ?? 'manual_required',
        'apply_url'     => $result['apply_url'] ?? '',
        'ats_platform'  => $result['ats_platform'] ?? '',
        'instructions'  => $result['instructions'] ?? '',
        'field_map'     => $result['field_map'] ?? [],
      ];

    } catch (\Exception $e) {
      $logger->error('Exception in browser automation: @error', [
        '@error' => $e->getMessage(),
      ]);

      return [
        'success' => FALSE,
        'error'   => $e->getMessage(),
        'reason'  => 'exception',
      ];
    }
  }

  /**
   * Updates the job submission status in jobhunter_job_requirements.
   *
   * @param int $job_id
   *   The job ID.
   * @param string $status
   *   The new submission status.
   */
  protected function updateJobSubmissionStatus(int $job_id, string $status): void {
    $connection = \Drupal::database();
    // jobhunter_job_requirements does not track submission_status/submission_date.
    // Use existing workflow fields.
    $fields = [
      // Preserve schema semantics: status is the main lifecycle marker.
      'status' => $status === 'submitted' ? 'applied' : $status,
      // applied_on_date is explicitly designed for applied tracking.
      'applied_on_date' => date('Y-m-d'),
      'updated' => \Drupal::time()->getRequestTime(),
    ];

    $connection->update('jobhunter_job_requirements')
      ->fields($fields)
      ->condition('id', $job_id)
      ->execute();
  }

  /**
   * Queues failed application for admin review.
   *
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job ID.
   * @param int $application_id
   *   The application ID.
   * @param string $error_message
   *   The error message.
   */
  protected function queueForErrorQueue(int $uid, int $job_id, int $application_id, string $error_message): void {
    try {
      $error_queue = \Drupal::queue('job_hunter_error_queue');
      $error_queue->createItem([
        'type' => 'application_submission_failed',
        'uid' => $uid,
        'job_id' => $job_id,
        'application_id' => $application_id,
        'error_message' => $error_message,
        'timestamp' => time(),
        'action_required' => 'Assist user in manual application completion',
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Failed to queue error item: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
