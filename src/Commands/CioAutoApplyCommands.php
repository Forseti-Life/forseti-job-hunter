<?php

namespace Drupal\job_hunter\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\job_hunter\Service\ApplyUrlResolverService;
use Drupal\job_hunter\Service\ApplicationSubmissionService;
use Drupal\job_hunter\Service\BrowserAutomationService;
use Drush\Commands\DrushCommands;

/**
 * CIO-focused job auto-apply Drush commands.
 */
class CioAutoApplyCommands extends DrushCommands {

  /**
   * Default CIO/executive title keywords.
   */
  protected const DEFAULT_TITLE_KEYWORDS = [
    'chief information officer',
    'cio',
    'chief technology officer',
    'cto',
    'chief digital officer',
    'vp information technology',
    'vice president information technology',
    'head of it',
    'it director',
    'director of information technology',
  ];

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Application submission service.
   *
   * @var \Drupal\job_hunter\Service\ApplicationSubmissionService
   */
  protected $applicationSubmissionService;

  /**
   * Logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Apply URL resolver.
   *
   * @var \Drupal\job_hunter\Service\ApplyUrlResolverService
   */
  protected $urlResolver;

  /**
   * Construct CIO auto-apply commands.
   */
  public function __construct(
    Connection $database,
    ApplicationSubmissionService $application_submission_service,
    LoggerChannelFactoryInterface $logger_factory,
    ApplyUrlResolverService $url_resolver
  ) {
    parent::__construct();
    $this->database = $database;
    $this->applicationSubmissionService = $application_submission_service;
    $this->loggerFactory = $logger_factory;
    $this->urlResolver = $url_resolver;
  }

  /**
   * Show CIO auto-apply candidate jobs for a user.
   *
   * @command job-hunter:cio-candidates
   * @aliases jh-cio-candidates
   * @option uid Required user ID.
   * @option limit Max rows to show. Default: 25.
   * @option title-keywords Comma-separated CIO title keywords. Defaults to built-in CIO keywords.
   * @usage job-hunter:cio-candidates --uid=2 --limit=20
   * @usage job-hunter:cio-candidates --uid=2 --title-keywords="chief information officer,cio,cto"
   */
  public function listCandidates($options = ['uid' => NULL, 'limit' => 25, 'title-keywords' => '']) {
    $uid = (int) ($options['uid'] ?? 0);
    $limit = max(1, (int) ($options['limit'] ?? 25));
    $title_keywords = $this->parseTitleKeywords((string) ($options['title-keywords'] ?? ''));

    if ($uid <= 0) {
      $this->output()->writeln('<error>Missing required option: --uid</error>');
      return;
    }

    $candidates = $this->loadCandidateJobs($uid, $limit, TRUE, $title_keywords);
    if (empty($candidates)) {
      $this->output()->writeln('<comment>No CIO auto-apply candidates found.</comment>');
      return;
    }

    $this->output()->writeln('<info>Title filters: ' . implode(', ', $title_keywords) . '</info>');

    $rows = [];
    foreach ($candidates as $candidate) {
      $rows[] = [
        'job_id' => $candidate['job_id'],
        'title' => $candidate['job_title'],
        'company' => $candidate['company_name'],
        'latest_status' => $candidate['latest_status'] ?: 'none',
      ];
    }

    $this->io()->table(['Job ID', 'Title', 'Company', 'Latest Status'], $rows);
  }

  /**
   * Queue CIO auto-apply submissions for eligible saved jobs.
   *
   * @command job-hunter:cio-auto-apply
   * @aliases jh-cio-auto-apply,jh-cio-run
   * @option uid Required user ID.
   * @option limit Max jobs to queue in this run. Default: 10.
   * @option retry-manual Include jobs whose latest status is manual_required.
   * @option dry-run Preview candidates without queuing submissions.
   * @option run-queue Immediately process submission queue after queuing.
   * @option queue-time-limit Queue processing time limit (seconds). Default: 180.
   * @option rounds Number of queue+candidate rounds to execute in this run. Default: 2.
   * @option title-keywords Comma-separated CIO title keywords. Defaults to built-in CIO keywords.
   * @usage job-hunter:cio-auto-apply --uid=2 --limit=10
   * @usage job-hunter:cio-auto-apply --uid=2 --title-keywords="chief information officer,cio"
   * @usage job-hunter:cio-auto-apply --uid=2 --limit=5 --retry-manual --run-queue
   */
  public function autoApply($options = [
    'uid' => NULL,
    'limit' => 10,
    'retry-manual' => FALSE,
    'dry-run' => FALSE,
    'run-queue' => TRUE,
    'queue-time-limit' => 180,
    'rounds' => 2,
    'title-keywords' => '',
  ]) {
    $uid = (int) ($options['uid'] ?? 0);
    $limit = max(1, (int) ($options['limit'] ?? 10));
    $retry_manual = (bool) ($options['retry-manual'] ?? TRUE);
    $dry_run = (bool) ($options['dry-run'] ?? FALSE);
    $run_queue = (bool) ($options['run-queue'] ?? TRUE);
    $queue_time_limit = max(10, (int) ($options['queue-time-limit'] ?? 180));
    $rounds = max(1, min(10, (int) ($options['rounds'] ?? 2)));
    $title_keywords = $this->parseTitleKeywords((string) ($options['title-keywords'] ?? ''));

    if ($uid <= 0) {
      $this->output()->writeln('<error>Missing required option: --uid</error>');
      return;
    }

    $logger = $this->loggerFactory->get('job_hunter');

    $total_queued = 0;
    $total_failed = 0;
    $results = [];

    $this->output()->writeln('<info>Title filters: ' . implode(', ', $title_keywords) . '</info>');

    for ($round = 1; $round <= $rounds; $round++) {
      $candidates = $this->loadCandidateJobs($uid, $limit, $retry_manual, $title_keywords);

      if (empty($candidates)) {
        if ($round === 1) {
          $this->output()->writeln('<comment>No eligible CIO auto-apply jobs found.</comment>');
        }
        break;
      }

      $this->output()->writeln(sprintf('<info>Round %d/%d: found %d candidate job(s) for uid=%d.</info>', $round, $rounds, count($candidates), $uid));

      if ($dry_run) {
        foreach ($candidates as $candidate) {
          $this->output()->writeln(sprintf('DRY-RUN: job_id=%d | %s @ %s | latest=%s',
            $candidate['job_id'],
            $candidate['job_title'],
            $candidate['company_name'],
            $candidate['latest_status'] ?: 'none'
          ));
        }
        return;
      }

      foreach ($candidates as $candidate) {
        $job_id = (int) $candidate['job_id'];
        $result = $this->applicationSubmissionService->submitApplication($uid, $job_id, TRUE);

        $ok = !empty($result['success']);
        $status = (string) ($result['status'] ?? 'unknown');
        $message = (string) ($result['message'] ?? '');

        if ($ok && in_array($status, ['queued', 'pending_review', 'processing', 'submitted'], TRUE)) {
          $total_queued++;
        }
        else {
          $total_failed++;
        }

        $results[] = [
          'job_id' => $job_id,
          'status' => $status,
          'success' => $ok ? 'yes' : 'no',
          'message' => $message,
        ];

        $logger->info('CIO auto-apply run: uid=@uid job_id=@job status=@status success=@success round=@round', [
          '@uid' => $uid,
          '@job' => $job_id,
          '@status' => $status,
          '@success' => $ok ? '1' : '0',
          '@round' => $round,
        ]);
      }

      if ($run_queue) {
        $queue_result = $this->processSubmissionQueue($queue_time_limit);
        $this->output()->writeln(sprintf(
          '<info>Round %d queue processed=%d failed=%d remaining=%d suspended=%s</info>',
          $round,
          $queue_result['processed'],
          $queue_result['failed'],
          $queue_result['remaining'],
          $queue_result['suspended'] ? 'yes' : 'no'
        ));

        if ((int) $queue_result['processed'] === 0 && (int) $queue_result['remaining'] === 0) {
          break;
        }
      }
    }

    if (!empty($results)) {
      $this->io()->table(['Job ID', 'Status', 'Success', 'Message'], $results);
    }
    $this->output()->writeln(sprintf('<info>CIO auto-apply queued=%d failed=%d rounds=%d</info>', $total_queued, $total_failed, $rounds));
  }

  /**
   * Load eligible candidate jobs from unarchived saved jobs.
   */
  protected function loadCandidateJobs(int $uid, int $limit, bool $retry_manual, array $title_keywords): array {
    $saved_jobs_has_archived = $this->database->schema()->fieldExists('jobhunter_saved_jobs', 'archived');

    $query = $this->database->select('jobhunter_saved_jobs', 'sj')
      ->fields('sj', ['job_id'])
      ->condition('sj.uid', $uid)
      ->range(0, $limit)
      ->orderBy('sj.updated', 'DESC');

    if ($saved_jobs_has_archived) {
      $query->condition('sj.archived', 0);
    }

    $query->leftJoin('jobhunter_job_requirements', 'j', 'j.id = sj.job_id');
    $query->addField('j', 'job_title');
    $query->addField('j', 'status', 'job_status');
    $query->addField('j', 'job_url');
    $query->addField('j', 'apply_options');
    $query->addField('j', 'company_id');

    $query->leftJoin('jobhunter_companies', 'c', 'c.id = j.company_id');
    $query->addField('c', 'name', 'company_name');

    $rows = $query->execute()->fetchAllAssoc('job_id');

    $candidates = [];
    foreach ($rows as $job_id => $row) {
      if (empty($row->job_id) || (string) ($row->job_status ?? '') !== 'active') {
        continue;
      }
      if (!$this->matchesTitleKeywords((string) ($row->job_title ?? ''), $title_keywords)) {
        continue;
      }

      $latest_status = $this->getLatestApplicationStatus($uid, (int) $job_id);
      if (in_array($latest_status, ['submitted', 'pending', 'processing', 'queued'], TRUE)) {
        continue;
      }
      if (!$retry_manual && $latest_status === 'manual_required') {
        continue;
      }

      $resolved = $this->urlResolver->resolve([
        'apply_options' => (string) ($row->apply_options ?? ''),
        'job_url' => (string) ($row->job_url ?? ''),
      ]);
      $ats_platform = (string) ($resolved['ats_platform'] ?? '');

      if (!$this->isAutoApplicablePlatform($uid, $ats_platform, (int) ($row->company_id ?? 0))) {
        continue;
      }

      $candidates[] = [
        'job_id' => (int) $job_id,
        'job_title' => (string) ($row->job_title ?? 'Unknown title'),
        'company_name' => (string) ($row->company_name ?? 'Unknown company'),
        'latest_status' => $latest_status,
        'ats_platform' => $ats_platform,
      ];
    }

    return $candidates;
  }

  /**
   * Determine whether the job can be auto-submitted in the current run.
   */
  protected function isAutoApplicablePlatform(int $uid, string $ats_platform, int $company_id): bool {
    if (in_array($ats_platform, BrowserAutomationService::AUTOMATABLE_PLATFORMS, TRUE)) {
      return TRUE;
    }

    if (in_array($ats_platform, BrowserAutomationService::LOGIN_REQUIRED_PLATFORMS, TRUE)) {
      return $company_id > 0 && $this->userHasCompanyCredentials($uid, $company_id);
    }

    return FALSE;
  }

  /**
   * Return TRUE when user has stored credentials for the company.
   */
  protected function userHasCompanyCredentials(int $uid, int $company_id): bool {
    return (bool) $this->database->select('jobhunter_employer_credentials', 'c')
      ->condition('c.uid', $uid)
      ->condition('c.company_id', $company_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Return latest application status for a user/job pair.
   */
  protected function getLatestApplicationStatus(int $uid, int $job_id): string {
    $status = $this->database->select('jobhunter_applications', 'a')
      ->fields('a', ['submission_status'])
      ->condition('a.uid', $uid)
      ->condition('a.job_id', $job_id)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return (string) ($status ?: '');
  }

  /**
   * Process the application submission queue.
   */
  protected function processSubmissionQueue(int $time_limit): array {
    $queue = \Drupal::queue('job_hunter_application_submission');
    $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('job_hunter_application_submission');
    $deadline = time() + $time_limit;

    $processed = 0;
    $failed = 0;
    $suspended = FALSE;

    while (time() < $deadline && ($item = $queue->claimItem())) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (SuspendQueueException $e) {
        $queue->releaseItem($item);
        $suspended = TRUE;
        break;
      }
      catch (\Throwable $e) {
        $queue->releaseItem($item);
        $failed++;
      }
    }

    return [
      'processed' => $processed,
      'failed' => $failed,
      'remaining' => $queue->numberOfItems(),
      'suspended' => $suspended,
    ];
  }

  /**
   * Parse and normalize title keywords.
   */
  protected function parseTitleKeywords(string $raw_keywords): array {
    if (trim($raw_keywords) === '') {
      return self::DEFAULT_TITLE_KEYWORDS;
    }

    $parts = array_filter(array_map('trim', explode(',', $raw_keywords)));
    $normalized = array_values(array_unique(array_map('mb_strtolower', $parts)));
    return !empty($normalized) ? $normalized : self::DEFAULT_TITLE_KEYWORDS;
  }

  /**
   * Return TRUE when title matches one or more keyword filters.
   */
  protected function matchesTitleKeywords(string $job_title, array $title_keywords): bool {
    $title = mb_strtolower(trim($job_title));
    if ($title === '') {
      return FALSE;
    }
    foreach ($title_keywords as $keyword) {
      if ($keyword !== '' && str_contains($title, (string) $keyword)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
