#!/usr/bin/env php
<?php

declare(strict_types=1);

use Drupal\Core\DrupalKernel;
use Drupal\Core\Queue\SuspendQueueException;
use Symfony\Component\HttpFoundation\Request;

$defaultTitleKeywords = [
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
 * Parse and normalize comma-separated keywords.
 */
function parseTitleKeywords(string $raw, array $defaults): array {
  if (trim($raw) === '') {
    return $defaults;
  }
  $parts = array_filter(array_map('trim', explode(',', $raw)));
  $keywords = array_values(array_unique(array_map('mb_strtolower', $parts)));
  return !empty($keywords) ? $keywords : $defaults;
}

/**
 * Return TRUE when title matches one keyword.
 */
function titleMatchesKeywords(string $title, array $keywords): bool {
  $normalizedTitle = mb_strtolower(trim($title));
  if ($normalizedTitle === '') {
    return false;
  }
  foreach ($keywords as $keyword) {
    if ($keyword !== '' && str_contains($normalizedTitle, (string) $keyword)) {
      return true;
    }
  }
  return false;
}

$options = [
  'uid' => 1,
  'limit' => 10,
  'rounds' => 2,
  'queue-time-limit' => 180,
  'retry-manual' => false,
  'title-keywords' => '',
];

foreach (array_slice($argv, 1) as $arg) {
  if (str_starts_with($arg, '--uid=')) {
    $options['uid'] = (int) substr($arg, 6);
  }
  elseif (str_starts_with($arg, '--limit=')) {
    $options['limit'] = (int) substr($arg, 8);
  }
  elseif (str_starts_with($arg, '--rounds=')) {
    $options['rounds'] = (int) substr($arg, 9);
  }
  elseif (str_starts_with($arg, '--queue-time-limit=')) {
    $options['queue-time-limit'] = (int) substr($arg, 19);
  }
  elseif (str_starts_with($arg, '--title-keywords=')) {
    $options['title-keywords'] = (string) substr($arg, 17);
  }
  elseif ($arg === '--retry-manual') {
    $options['retry-manual'] = true;
  }
  elseif ($arg === '--no-retry-manual') {
    $options['retry-manual'] = false;
  }
}

$options['uid'] = max(1, (int) $options['uid']);
$options['limit'] = max(1, (int) $options['limit']);
$options['rounds'] = max(1, min(10, (int) $options['rounds']));
$options['queue-time-limit'] = max(10, (int) $options['queue-time-limit']);

chdir(__DIR__ . '/../web');
$autoloader = require_once 'autoload.php';
$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
$kernel->preHandle($request);

$db = \Drupal::database();
$submissionService = \Drupal::service('job_hunter.application_submission_service');
$urlResolver = \Drupal::service('job_hunter.apply_url_resolver');
$logger = \Drupal::logger('job_hunter');

$uid = $options['uid'];
$limit = $options['limit'];
$rounds = $options['rounds'];
$retryManual = (bool) $options['retry-manual'];
$queueTimeLimit = $options['queue-time-limit'];
$titleKeywords = parseTitleKeywords((string) $options['title-keywords'], $defaultTitleKeywords);

$summary = [
  'uid' => $uid,
  'limit' => $limit,
  'rounds' => $rounds,
  'retry_manual' => $retryManual,
  'title_keywords' => $titleKeywords,
  'queue_time_limit' => $queueTimeLimit,
  'queued_total' => 0,
  'failed_total' => 0,
  'queue_processed_total' => 0,
  'queue_failed_total' => 0,
  'round_results' => [],
];

for ($round = 1; $round <= $rounds; $round++) {
  $savedJobsHasArchived = $db->schema()->fieldExists('jobhunter_saved_jobs', 'archived');

  $query = $db->select('jobhunter_saved_jobs', 'sj')
    ->fields('sj', ['job_id'])
    ->condition('sj.uid', $uid)
    ->range(0, $limit)
    ->orderBy('sj.updated', 'DESC');

  if ($savedJobsHasArchived) {
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
  foreach ($rows as $jobId => $row) {
    if (empty($row->job_id) || (string) ($row->job_status ?? '') !== 'active') {
      continue;
    }
    if (!titleMatchesKeywords((string) ($row->job_title ?? ''), $titleKeywords)) {
      continue;
    }

    $latestStatus = (string) ($db->select('jobhunter_applications', 'a')
      ->fields('a', ['submission_status'])
      ->condition('a.uid', $uid)
      ->condition('a.job_id', (int) $jobId)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField() ?: '');

    if (in_array($latestStatus, ['submitted', 'pending', 'processing', 'queued'], true)) {
      continue;
    }
    if (!$retryManual && $latestStatus === 'manual_required') {
      continue;
    }

    $resolved = $urlResolver->resolve([
      'apply_options' => (string) ($row->apply_options ?? ''),
      'job_url' => (string) ($row->job_url ?? ''),
    ]);
    $atsPlatform = (string) ($resolved['ats_platform'] ?? '');

    $isAutomatable = in_array($atsPlatform, \Drupal\job_hunter\Service\BrowserAutomationService::AUTOMATABLE_PLATFORMS, true);
    $isLoginRequired = in_array($atsPlatform, \Drupal\job_hunter\Service\BrowserAutomationService::LOGIN_REQUIRED_PLATFORMS, true);

    $hasCredentials = false;
    if ($isLoginRequired && !empty($row->company_id)) {
      $hasCredentials = (bool) ($db->select('jobhunter_employer_credentials', 'cred')
        ->condition('cred.uid', $uid)
        ->condition('cred.company_id', (int) $row->company_id)
        ->countQuery()
        ->execute()
        ->fetchField());
    }

    if (!$isAutomatable && !($isLoginRequired && $hasCredentials)) {
      continue;
    }

    $candidates[] = [
      'job_id' => (int) $jobId,
      'title' => (string) ($row->job_title ?? 'Unknown'),
      'company' => (string) ($row->company_name ?? 'Unknown'),
      'latest_status' => $latestStatus,
      'ats_platform' => $atsPlatform,
    ];
  }

  $roundResult = [
    'round' => $round,
    'candidates' => count($candidates),
    'queued' => 0,
    'failed' => 0,
    'queue_processed' => 0,
    'queue_failed' => 0,
    'queue_remaining' => 0,
  ];

  foreach ($candidates as $candidate) {
    $result = $submissionService->submitApplication($uid, (int) $candidate['job_id'], true);
    $ok = !empty($result['success']);
    $status = (string) ($result['status'] ?? 'unknown');

    if ($ok && in_array($status, ['queued', 'pending_review', 'processing', 'submitted'], true)) {
      $roundResult['queued']++;
      $summary['queued_total']++;
    }
    else {
      $roundResult['failed']++;
      $summary['failed_total']++;
    }
  }

  $queue = \Drupal::queue('job_hunter_application_submission');
  $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('job_hunter_application_submission');
  $deadline = time() + $queueTimeLimit;

  while (time() < $deadline && ($item = $queue->claimItem())) {
    try {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
      $roundResult['queue_processed']++;
      $summary['queue_processed_total']++;
    }
    catch (SuspendQueueException $e) {
      $queue->releaseItem($item);
      break;
    }
    catch (\Throwable $e) {
      $queue->releaseItem($item);
      $roundResult['queue_failed']++;
      $summary['queue_failed_total']++;
      break;
    }
  }

  $roundResult['queue_remaining'] = $queue->numberOfItems();
  $summary['round_results'][] = $roundResult;

  if ($roundResult['candidates'] === 0 && $roundResult['queue_processed'] === 0) {
    break;
  }
}

$summary['submitted_total_for_user'] = (int) $db->select('jobhunter_applications', 'a')
  ->condition('a.uid', $uid)
  ->condition('a.submission_status', 'submitted')
  ->countQuery()
  ->execute()
  ->fetchField();

$logger->info('CIO auto-apply scheduler summary: @summary', [
  '@summary' => json_encode($summary),
]);

echo json_encode($summary, JSON_PRETTY_PRINT) . PHP_EOL;

$kernel->terminate($request, new Symfony\Component\HttpFoundation\Response());
