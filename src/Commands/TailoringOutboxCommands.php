<?php

namespace Drupal\job_hunter\Commands;

use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\DelayableQueueInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\job_hunter\Service\TailoringRunService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for supervising the resume tailoring transactional outbox.
 *
 * These commands provide a Drush-compatible dispatcher/consumer for the
 * event-driven resume tailoring outbox:
 * - dispatch(): the "dispatcher" — reconcilePendingOutbox() re-derives and
 *   re-enqueues any outbox event that was not confirmed dispatched (e.g.
 *   because the process crashed between the outbox write and the queue
 *   write).
 * - consume(): the "consumer" — drains the job_hunter_resume_tailoring
 *   queue directly (same claim/process/delete loop Drupal core uses for
 *   cron queue processing), for use under a process supervisor (systemd
 *   timer, supervisord, etc.) as the primary async processing path. This
 *   is equivalent to Drush core's `queue:run job_hunter_resume_tailoring`
 *   and is provided here alongside dispatch() so a single supervised
 *   command can both reconcile the outbox and drain the queue.
 *
 * Existing cron-driven queue processing (QueueWorker cron annotation)
 * remains as a secondary fallback/reconciliation mechanism and is not
 * modified here.
 */
class TailoringOutboxCommands extends DrushCommands {

  protected TailoringRunService $tailoringRunService;

  protected QueueFactory $queueFactory;

  protected QueueWorkerManagerInterface $queueWorkerManager;

  public function __construct(TailoringRunService $tailoring_run_service, QueueFactory $queue_factory, QueueWorkerManagerInterface $queue_worker_manager) {
    parent::__construct();
    $this->tailoringRunService = $tailoring_run_service;
    $this->queueFactory = $queue_factory;
    $this->queueWorkerManager = $queue_worker_manager;
  }

  /**
   * Reconcile and (re-)dispatch pending resume tailoring outbox events.
   *
   * Intended to be run supervised (e.g. via systemd timer / cron) as the
   * consumer for the transactional outbox. Safe to run frequently and
   * concurrently is not guaranteed; a single supervised instance is
   * recommended.
   *
   * @command job-hunter:tailoring-outbox-dispatch
   * @aliases jh-outbox-dispatch
   * @option limit Maximum number of pending outbox rows to process.
   * @usage drush job-hunter:tailoring-outbox-dispatch
   *   Dispatch up to 50 pending outbox events.
   * @usage drush job-hunter:tailoring-outbox-dispatch --limit=200
   *   Dispatch up to 200 pending outbox events.
   */
  public function dispatch(array $options = ['limit' => 50]): int {
    $limit = (int) ($options['limit'] ?? 50);
    $summary = $this->tailoringRunService->reconcilePendingOutbox($limit);

    $this->output()->writeln(sprintf(
      'Tailoring outbox reconciliation: %d dispatched, %d skipped (already terminal), %d failed.',
      $summary['dispatched'],
      $summary['skipped'],
      $summary['failed']
    ));

    return $summary['failed'] > 0 ? DrushCommands::EXIT_FAILURE_WITH_CLARITY : DrushCommands::EXIT_SUCCESS;
  }

  /**
   * Consume (drain) the resume tailoring queue for a bounded time window.
   *
   * This is the supervised "consumer" side of the dispatcher/consumer pair:
   * run it under a process supervisor (systemd timer, supervisord, etc.) on
   * a short interval so queued runs are processed promptly rather than
   * waiting for the next cron run. Cron's own queue processing (the
   * QueueWorker's `cron` annotation) remains as a fallback so items are
   * never stranded if the supervised consumer is not running.
   *
   * @command job-hunter:tailoring-consume
   * @aliases jh-tailoring-consume
   * @option time-limit Maximum seconds to keep draining the queue.
   * @option queue Queue name to consume.
   * @usage drush job-hunter:tailoring-consume
   *   Drain the resume tailoring queue for up to 60 seconds.
   * @usage drush job-hunter:tailoring-consume --time-limit=300
   *   Drain the resume tailoring queue for up to 5 minutes.
   */
  public function consume(array $options = ['time-limit' => 60, 'queue' => 'job_hunter_resume_tailoring']): int {
    $queue_name = (string) ($options['queue'] ?? 'job_hunter_resume_tailoring');
    $time_limit = (int) ($options['time-limit'] ?? 60);

    $queue = $this->queueFactory->get($queue_name);
    $worker = $this->queueWorkerManager->createInstance($queue_name);

    $processed = 0;
    $requeued = 0;
    $failed = 0;

    $end = time() + $time_limit;
    $lease_time = max($time_limit, 30);

    try {
      while (time() < $end && ($item = $queue->claimItem($lease_time))) {
        try {
          $worker->processItem($item->data);
          $queue->deleteItem($item);
          $processed++;
        }
        catch (DelayedRequeueException $e) {
          if ($queue instanceof DelayableQueueInterface) {
            $queue->delayItem($item, $e->getDelay());
          }
          $requeued++;
        }
        catch (RequeueException) {
          $queue->releaseItem($item);
          $requeued++;
        }
        catch (SuspendQueueException $e) {
          $queue->releaseItem($item);
          $this->logger()->warning('Tailoring consumer suspended: @message', ['@message' => $e->getMessage()]);
          break;
        }
        catch (\Exception $e) {
          // The worker's own retry/backoff logic (handleQueueExceptionWithRetry)
          // already handles transient/permanent classification and does not
          // re-throw; reaching here means an unexpected error escaped that
          // handling. Log it and leave the item to be retried on next claim.
          $failed++;
          $this->logger()->error('Tailoring consumer error processing queue item: @message', ['@message' => $e->getMessage()]);
        }
      }
    }
    finally {
      // No persistent lock is held beyond individual claimItem() leases, so
      // nothing further to release here; claimed-but-unprocessed items will
      // simply become reclaimable again once their lease expires.
    }

    $this->output()->writeln(sprintf(
      'Tailoring consumer (%s): %d processed, %d requeued, %d errored.',
      $queue_name,
      $processed,
      $requeued,
      $failed
    ));

    return $failed > 0 ? DrushCommands::EXIT_FAILURE_WITH_CLARITY : DrushCommands::EXIT_SUCCESS;
  }

}
