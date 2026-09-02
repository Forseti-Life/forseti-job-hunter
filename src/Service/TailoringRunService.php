<?php

namespace Drupal\job_hunter\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\job_hunter\Traits\JobHunterLoggerTrait;

/**
 * Manages versioned resume tailoring runs and their transactional outbox.
 *
 * This is the first production-safe slice of the event-driven resume
 * tailoring plan: a run record makes tailoring requests idempotent per
 * uid/job_id, and a transactional outbox guarantees that the durable
 * record of "this run was requested" is written atomically with the
 * (small) queue payload used to trigger asynchronous processing.
 *
 * Design notes:
 * - The default Drupal queue backend ('queue.database') writes to the
 *   {queue} table using the same default database connection as this
 *   service, so wrapping the run insert, outbox insert, and queue
 *   createItem() call in a single transaction commits or rolls back all
 *   three together.
 * - Queue payloads only ever carry a run_id (a UUID). Consumers resolve
 *   the actual uid/job_id/profile/job inputs from the database via
 *   resolveRunInputs(), keeping queue rows small and avoiding stale
 *   duplicated data.
 * - If the outbox row is ever left 'pending' (e.g. a crash between the
 *   outbox insert and the queue insert outside of the guaranteed path),
 *   reconcilePendingOutbox() re-derives and re-enqueues it. This is
 *   intended to be run from a supervised drush command and/or cron.
 */
class TailoringRunService {

  use JobHunterLoggerTrait;

  const STATUS_QUEUED = 'queued';
  const STATUS_PROCESSING = 'processing';
  const STATUS_COMPLETED = 'completed';
  const STATUS_FAILED = 'failed';

  const RUN_TABLE = 'jobhunter_tailoring_runs';
  const OUTBOX_TABLE = 'jobhunter_tailoring_outbox';

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The queue factory.
   */
  protected QueueFactory $queueFactory;

  /**
   * The UUID generator.
   */
  protected UuidInterface $uuidGenerator;

  /**
   * The logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Constructs a new TailoringRunService.
   */
  public function __construct(Connection $database, QueueFactory $queue_factory, UuidInterface $uuid_generator, LoggerChannelFactoryInterface $logger_factory) {
    $this->database = $database;
    $this->queueFactory = $queue_factory;
    $this->uuidGenerator = $uuid_generator;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Create a new tailoring run, or reuse an existing active one.
   *
   * Idempotency: unless $force is TRUE, an existing run for the same
   * uid/job_id/run_type with status 'queued' or 'processing' is reused
   * rather than creating a duplicate run + queue item.
   *
   * @param int $uid
   *   The user ID.
   * @param int $job_id
   *   The job requirement ID.
   * @param bool $force
   *   TRUE to always create a new run (e.g. regenerate request).
   * @param string $run_type
   *   The run type identifier (default: 'resume_tailoring').
   * @param string $queue_name
   *   The queue to dispatch the run_id payload to.
   *
   * @return array
   *   Array with keys: 'run_id' (UUID string), 'status', 'reused' (bool).
   */
  public function createOrReuseRun(int $uid, int $job_id, bool $force = FALSE, string $run_type = 'resume_tailoring', string $queue_name = 'job_hunter_resume_tailoring'): array {
    if (!$force) {
      $existing = $this->findActiveRun($uid, $job_id, $run_type);
      if ($existing) {
        return [
          'run_id' => $existing->run_uuid,
          'status' => $existing->status,
          'reused' => TRUE,
        ];
      }
    }

    $run_uuid = $this->uuidGenerator->generate();
    $now = time();
    $version = $this->getNextVersion($uid, $job_id, $run_type);

    $transaction = $this->database->startTransaction();
    try {
      $run_id = $this->database->insert(self::RUN_TABLE)
        ->fields([
          'run_uuid' => $run_uuid,
          'uid' => $uid,
          'job_id' => $job_id,
          'run_type' => $run_type,
          'status' => self::STATUS_QUEUED,
          'version' => $version,
          'force' => $force ? 1 : 0,
          'created' => $now,
          'updated' => $now,
        ])
        ->execute();

      $payload = ['run_id' => $run_uuid];

      $outbox_id = $this->database->insert(self::OUTBOX_TABLE)
        ->fields([
          'run_id' => $run_id,
          'run_uuid' => $run_uuid,
          'event_type' => $run_type . '.requested',
          'queue_name' => $queue_name,
          'payload_json' => json_encode($payload),
          'status' => 'pending',
          'attempts' => 0,
          'created' => $now,
          'updated' => $now,
        ])
        ->execute();

      // Attempt immediate dispatch within the same transaction. The default
      // database queue backend shares this connection, so this commits or
      // rolls back atomically with the run + outbox rows above.
      $this->queueFactory->get($queue_name)->createItem($payload);

      $this->database->update(self::OUTBOX_TABLE)
        ->fields([
          'status' => 'dispatched',
          'attempts' => 1,
          'dispatched_at' => $now,
          'updated' => $now,
        ])
        ->condition('id', $outbox_id)
        ->execute();

      unset($transaction);
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logError('Tailoring run creation failed for uid @uid job @job_id: @error', [
        '@uid' => $uid,
        '@job_id' => $job_id,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }

    $this->logInfo('Created tailoring run @run_id (v@version) for uid @uid job @job_id', [
      '@run_id' => $run_uuid,
      '@version' => $version,
      '@uid' => $uid,
      '@job_id' => $job_id,
    ]);

    return [
      'run_id' => $run_uuid,
      'status' => self::STATUS_QUEUED,
      'reused' => FALSE,
    ];
  }

  /**
   * Find an active (queued/processing) run for uid+job_id+run_type.
   *
   * @return object|null
   *   The run record, or NULL if none active.
   */
  protected function findActiveRun(int $uid, int $job_id, string $run_type) {
    if (!$this->database->schema()->tableExists(self::RUN_TABLE)) {
      return NULL;
    }

    return $this->database->select(self::RUN_TABLE, 'r')
      ->fields('r')
      ->condition('uid', $uid)
      ->condition('job_id', $job_id)
      ->condition('run_type', $run_type)
      ->condition('status', [self::STATUS_QUEUED, self::STATUS_PROCESSING], 'IN')
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchObject();
  }

  /**
   * Compute the next version number for a uid+job_id+run_type combination.
   */
  protected function getNextVersion(int $uid, int $job_id, string $run_type): int {
    if (!$this->database->schema()->tableExists(self::RUN_TABLE)) {
      return 1;
    }

    $query = $this->database->select(self::RUN_TABLE, 'r')
      ->condition('uid', $uid)
      ->condition('job_id', $job_id)
      ->condition('run_type', $run_type);
    $query->addExpression('MAX(version)', 'max_version');
    $max_version = (int) $query->execute()->fetchField();

    return $max_version + 1;
  }

  /**
   * Load a run record by its public UUID.
   *
   * @return object|null
   *   The run record, or NULL if not found.
   */
  public function getRunByUuid(string $run_uuid) {
    if (!$this->database->schema()->tableExists(self::RUN_TABLE)) {
      return NULL;
    }

    return $this->database->select(self::RUN_TABLE, 'r')
      ->fields('r')
      ->condition('run_uuid', $run_uuid)
      ->execute()
      ->fetchObject();
  }

  /**
   * Load the most recent run for a uid+job_id+run_type, regardless of status.
   *
   * Used by controllers to determine the canonical status to display,
   * independent of whether that run is still active or already terminal.
   *
   * @return object|null
   *   The run record, or NULL if none exists.
   */
  public function getLatestRun(int $uid, int $job_id, string $run_type = 'resume_tailoring') {
    if (!$this->database->schema()->tableExists(self::RUN_TABLE)) {
      return NULL;
    }

    return $this->database->select(self::RUN_TABLE, 'r')
      ->fields('r')
      ->condition('uid', $uid)
      ->condition('job_id', $job_id)
      ->condition('run_type', $run_type)
      ->orderBy('id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchObject();
  }

  /**
   * Determine the canonical status for a uid/job_id, sourced from the run
   * record whenever one exists.
   *
   * This is the single source of truth for tailoring status going forward.
   * Callers should only fall back to legacy queue-table heuristics when this
   * returns NULL, i.e. when no run record exists at all (a record created
   * before the run/outbox model existed and never migrated).
   *
   * @return array|null
   *   Array with keys 'run_id' (UUID), 'status', 'error_message' (nullable),
   *   or NULL if no run exists for this uid/job_id/run_type.
   */
  public function getCanonicalStatus(int $uid, int $job_id, string $run_type = 'resume_tailoring'): ?array {
    $run = $this->getLatestRun($uid, $job_id, $run_type);
    if (!$run) {
      return NULL;
    }

    return [
      'run_id' => $run->run_uuid,
      'status' => $run->status,
      'error_message' => $run->error_message ?? NULL,
    ];
  }

  /**
   * Adopt a pre-existing, full-payload queue item into a run record.
   *
   * Narrow migration path only: used by the queue worker when it dequeues
   * a legacy item (enqueued before the run/outbox model existed) that
   * carries no run_id. Creates a run already marked 'processing' — since
   * the item is already in-flight in the queue — without writing an
   * outbox row or dispatching a new queue item (that would duplicate the
   * item currently being processed). This is not part of the canonical
   * dispatch path; all current producers use createOrReuseRun() instead.
   *
   * @return string
   *   The newly created run's UUID.
   */
  public function adoptLegacyQueueItem(int $uid, int $job_id, string $run_type = 'resume_tailoring'): string {
    $run_uuid = $this->uuidGenerator->generate();
    $now = time();
    $version = $this->getNextVersion($uid, $job_id, $run_type);

    $this->database->insert(self::RUN_TABLE)
      ->fields([
        'run_uuid' => $run_uuid,
        'uid' => $uid,
        'job_id' => $job_id,
        'run_type' => $run_type,
        'status' => self::STATUS_PROCESSING,
        'version' => $version,
        'force' => 0,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();

    return $run_uuid;
  }

  /**
   * Resolve the actual tailoring inputs (uid, job_id, profile, job data) for
   * a queue item that only carries a run_id.
   *
   * Marks the run as 'processing' as a side effect when found.
   *
   * @param string $run_uuid
   *   The run UUID from the queue payload.
   *
   * @return array|null
   *   Array with keys 'uid', 'job_id', 'profile_json', 'job_data', or NULL
   *   if the run or its underlying profile/job data could not be resolved.
   */
  public function resolveRunInputs(string $run_uuid): ?array {
    $run = $this->getRunByUuid($run_uuid);
    if (!$run) {
      $this->logError('Tailoring run @run_id not found while resolving inputs.', ['@run_id' => $run_uuid]);
      return NULL;
    }

    $uid = (int) $run->uid;
    $job_id = (int) $run->job_id;

    $job_data_row = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j')
      ->condition('id', $job_id)
      ->execute()
      ->fetchObject();

    $job_seeker_row = $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js')
      ->condition('uid', $uid)
      ->execute()
      ->fetchObject();

    if (!$job_data_row || !$job_seeker_row || empty($job_seeker_row->consolidated_profile_json)) {
      $this->markRunStatus($run_uuid, self::STATUS_FAILED, [
        'error_message' => 'Missing job or profile data at dispatch time.',
      ]);
      return NULL;
    }

    $this->markRunStatus($run_uuid, self::STATUS_PROCESSING);

    return [
      'uid' => $uid,
      'job_id' => $job_id,
      'profile_json' => json_decode($job_seeker_row->consolidated_profile_json, TRUE) ?: [],
      'job_data' => [
        'extracted_json' => $job_data_row->extracted_json,
        'skills_required_json' => $job_data_row->skills_required_json,
        'keywords_json' => $job_data_row->keywords_json,
        'raw_posting_text' => $job_data_row->raw_posting_text ?? '',
      ],
    ];
  }

  /**
   * Update a run's status.
   *
   * @param string $run_uuid
   *   The run UUID.
   * @param string $status
   *   The new status (queued, processing, completed, failed).
   * @param array $extra_fields
   *   Additional fields to set (e.g. error_message).
   */
  public function markRunStatus(string $run_uuid, string $status, array $extra_fields = []): void {
    if (!$this->database->schema()->tableExists(self::RUN_TABLE)) {
      return;
    }

    $now = time();
    $fields = array_merge([
      'status' => $status,
      'updated' => $now,
    ], $extra_fields);

    if (in_array($status, [self::STATUS_COMPLETED, self::STATUS_FAILED], TRUE)) {
      $fields['completed_at'] = $now;
    }

    $this->database->update(self::RUN_TABLE)
      ->fields($fields)
      ->condition('run_uuid', $run_uuid)
      ->execute();
  }

  /**
   * Reconcile outbox rows left 'pending' by (re-)dispatching their payload.
   *
   * Intended for a supervised drush command and/or cron fallback so that a
   * crash between the outbox write and the initial dispatch attempt cannot
   * silently drop a tailoring request.
   *
   * @param int $limit
   *   Maximum number of outbox rows to process in one call.
   *
   * @return array
   *   Summary counts: ['dispatched' => int, 'failed' => int, 'skipped' => int].
   */
  public function reconcilePendingOutbox(int $limit = 50): array {
    $summary = ['dispatched' => 0, 'failed' => 0, 'skipped' => 0];

    if (!$this->database->schema()->tableExists(self::OUTBOX_TABLE)) {
      return $summary;
    }

    $rows = $this->database->select(self::OUTBOX_TABLE, 'o')
      ->fields('o')
      ->condition('status', 'pending')
      ->orderBy('id', 'ASC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();

    foreach ($rows as $row) {
      $payload = json_decode($row->payload_json, TRUE);
      if (!is_array($payload) || empty($payload['run_id'])) {
        $this->database->update(self::OUTBOX_TABLE)
          ->fields([
            'status' => 'failed',
            'error_message' => 'Malformed outbox payload.',
            'updated' => time(),
          ])
          ->condition('id', $row->id)
          ->execute();
        $summary['failed']++;
        continue;
      }

      // Skip reconciliation for runs that already reached a terminal state;
      // the queue item was consumed and there is nothing to redeliver.
      $run = $this->getRunByUuid($payload['run_id']);
      if (!$run || in_array($run->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], TRUE)) {
        $this->database->update(self::OUTBOX_TABLE)
          ->fields(['status' => 'dispatched', 'dispatched_at' => time(), 'updated' => time()])
          ->condition('id', $row->id)
          ->execute();
        $summary['skipped']++;
        continue;
      }

      try {
        $this->queueFactory->get($row->queue_name ?: 'job_hunter_resume_tailoring')->createItem($payload);
        $this->database->update(self::OUTBOX_TABLE)
          ->fields([
            'status' => 'dispatched',
            'attempts' => ((int) $row->attempts) + 1,
            'dispatched_at' => time(),
            'updated' => time(),
          ])
          ->condition('id', $row->id)
          ->execute();
        $summary['dispatched']++;

        $this->logInfo('Reconciled pending outbox event @id for run @run_id (re-enqueued).', [
          '@id' => $row->id,
          '@run_id' => $payload['run_id'],
        ]);
      }
      catch (\Exception $e) {
        $this->database->update(self::OUTBOX_TABLE)
          ->fields([
            'attempts' => ((int) $row->attempts) + 1,
            'error_message' => substr($e->getMessage(), 0, 500),
            'updated' => time(),
          ])
          ->condition('id', $row->id)
          ->execute();
        $summary['failed']++;

        $this->logError('Failed to reconcile outbox event @id for run @run_id: @error', [
          '@id' => $row->id,
          '@run_id' => $payload['run_id'],
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $summary;
  }

}
