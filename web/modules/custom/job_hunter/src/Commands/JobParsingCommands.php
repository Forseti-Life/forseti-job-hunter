<?php

namespace Drupal\job_hunter\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for managing job posting AI parsing.
 */
class JobParsingCommands extends DrushCommands {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The queue factory.
   */
  protected QueueFactory $queueFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database, QueueFactory $queue_factory) {
    parent::__construct();
    $this->database = $database;
    $this->queueFactory = $queue_factory;
  }

  /**
   * Queue all pending job postings for AI parsing.
   *
   * @command job-hunter:queue-pending
   * @aliases jh-queue-pending
   * @option dry-run List jobs that would be queued without actually queuing them.
   * @usage drush job-hunter:queue-pending
   *   Queue all pending jobs that have text available to parse.
   * @usage drush job-hunter:queue-pending --dry-run
   *   Preview which jobs would be queued.
   */
  public function queuePendingJobs(array $options = ['dry-run' => FALSE]): void {
    $dry_run = $options['dry-run'];

    // Select all pending jobs that have text to parse (raw or description).
    $query = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j', ['id', 'job_title', 'raw_posting_text', 'job_description'])
      ->condition('ai_extraction_status', 'pending');
    $jobs = $query->execute()->fetchAll();

    if (empty($jobs)) {
      $this->logger()->success('No pending jobs found.');
      return;
    }

    $queued = 0;
    $skipped = 0;

    $queue = $this->queueFactory->get('job_hunter_job_posting_parsing');

    foreach ($jobs as $job) {
      $text = $job->raw_posting_text ?: $job->job_description;
      if (empty($text)) {
        $this->logger()->warning("Job #{$job->id} ({$job->job_title}): no text available — skipping.");
        $skipped++;
        continue;
      }

      if ($dry_run) {
        $preview = substr(preg_replace('/\s+/', ' ', $text), 0, 80);
        $this->logger()->info("Would queue job #{$job->id}: {$job->job_title} — \"{$preview}...\"");
      }
      else {
        $queue->createItem(['job_id' => $job->id, 'raw_posting_text' => $text]);
        $this->logger()->info("Queued job #{$job->id}: {$job->job_title}");
      }
      $queued++;
    }

    $action = $dry_run ? 'Would queue' : 'Queued';
    $this->logger()->success("{$action} {$queued} job(s). Skipped {$skipped} (no text).");

    if (!$dry_run && $queued > 0) {
      $this->logger()->info("Run 'drush queue:run job_hunter_job_posting_parsing' to process them.");
    }
  }

  /**
   * Show parsing status summary for all job postings.
   *
   * @command job-hunter:parsing-status
   * @aliases jh-parse-status
   * @usage drush job-hunter:parsing-status
   *   Show a breakdown of AI parsing status for all job postings.
   */
  public function parsingStatus(): void {
    $results = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j', ['ai_extraction_status'])
      ->execute()
      ->fetchAll();

    $counts = [];
    foreach ($results as $row) {
      $status = $row->ai_extraction_status ?: 'NULL';
      $counts[$status] = ($counts[$status] ?? 0) + 1;
    }

    $total = array_sum($counts);
    $this->logger()->info("Job posting AI parsing status (total: {$total}):");
    foreach ($counts as $status => $count) {
      $this->logger()->info("  {$status}: {$count}");
    }

    // Show queue depth.
    $queue = $this->queueFactory->get('job_hunter_job_posting_parsing');
    $depth = $queue->numberOfItems();
    $this->logger()->info("Queue depth (job_hunter_job_posting_parsing): {$depth} item(s)");
  }

}
