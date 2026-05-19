<?php

namespace Drupal\job_hunter\Repository;

use Drupal\Core\Database\Connection;

/**
 * Data-access layer for all job_hunter DB tables.
 *
 * Extracts every raw $database-> call from JobApplicationController so the
 * controller deals only with HTTP concerns and business logic.
 */
class JobApplicationRepository {

  public function __construct(protected Connection $database) {}

  // ── Job Requirements ───────────────────────────────────────────────────

  /**
   * Count all rows in jobhunter_job_requirements.
   */
  public function countJobRequirements(): int {
    try {
      $count = $this->database->select('jobhunter_job_requirements', 'j')
        ->countQuery()
        ->execute()
        ->fetchField();
      return (int) $count;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Find a Forseti job ID by its normalised external identifier.
   */
  public function findJobIdByExternalId(string $external_id): ?int {
    if ($external_id === '') {
      return NULL;
    }
    $job_id = (int) $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j', ['id'])
      ->condition('j.external_job_id', $external_id)
      ->orderBy('j.id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $job_id > 0 ? $job_id : NULL;
  }

  /**
   * Insert a new job requirement row and return the new ID.
   *
   * @param array $fields
   *   Column => value map.
   *
   * @return int
   *   New row ID, or 0 on failure.
   */
  public function insertJobRequirement(array $fields): int {
    return (int) $this->database->insert('jobhunter_job_requirements')
      ->fields($fields)
      ->execute();
  }

  /**
   * Update a job requirement row by ID.
   *
   * @param int $job_id
   *   Row primary key.
   * @param array $fields
   *   Column => value map.
   */
  public function updateJobRequirement(int $job_id, array $fields): void {
    $this->database->update('jobhunter_job_requirements')
      ->fields($fields)
      ->condition('id', $job_id)
      ->execute();
  }

  /**
   * Fetch a job requirement row by ID.
   *
   * @param int $job_id
   *   Row primary key.
   * @param array $fields
   *   Columns to select. Empty = all columns.
   *
   * @return object|null
   *   Row as stdClass, or NULL if not found.
   */
  public function getJobById(int $job_id, array $fields = []): ?object {
    $query = $this->database->select('jobhunter_job_requirements', 'j')
      ->condition('j.id', $job_id)
      ->range(0, 1);
    if ($fields) {
      $query->fields('j', $fields);
    }
    else {
      $query->fields('j');
    }
    $row = $query->execute()->fetchObject();
    return $row ?: NULL;
  }

  // ── Saved Jobs ─────────────────────────────────────────────────────────

  /**
   * Return the mapping-row ID for (uid, job_id), or 0 if absent.
   */
  public function findSavedJobMappingId(int $uid, int $job_id): int {
    return (int) $this->database->select('jobhunter_saved_jobs', 'sj')
      ->fields('sj', ['id'])
      ->condition('sj.uid', $uid)
      ->condition('sj.job_id', $job_id)
      ->execute()
      ->fetchField();
  }

  /**
   * Insert a new saved-job mapping row.
   */
  public function insertSavedJob(int $uid, int $job_id): void {
    $now = time();
    $this->database->insert('jobhunter_saved_jobs')
      ->fields([
        'uid' => $uid,
        'job_id' => $job_id,
        'archived' => 0,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();
  }

  /**
   * Set the per-user archive flag on a saved-job mapping row.
   *
   * This replaces the old pattern of writing to jobhunter_job_requirements
   * .status so that archiving is scoped to the calling user only.
   *
   * @param int $uid
   *   The user performing the archive/unarchive.
   * @param int $job_id
   *   The jobhunter_job_requirements.id to archive/unarchive.
   * @param bool $archived
   *   TRUE to archive; FALSE to restore.
   */
  public function setJobArchivedForUser(int $uid, int $job_id, bool $archived): void {
    $this->database->update('jobhunter_saved_jobs')
      ->fields([
        'archived' => (int) $archived,
        'updated' => time(),
      ])
      ->condition('uid', $uid)
      ->condition('job_id', $job_id)
      ->execute();
  }

  // ── Job Search Results (staging) ───────────────────────────────────────

  /**
   * Return the imported job ID for a staging result row, or 0 if not found.
   */
  public function getImportedJobIdFromStaging(int $staging_id): int {
    return (int) $this->database->select('jobhunter_job_search_results', 's')
      ->fields('s', ['imported_to_job_id'])
      ->condition('s.id', $staging_id)
      ->execute()
      ->fetchField();
  }

  /**
   * Fetch a staged job-search result row by ID.
   *
   * @param int $staging_id
   *   Primary key from jobhunter_job_search_results.
   *
   * @return object|null
   *   Row as stdClass, or NULL if not found.
   */
  public function getStagingResultById(int $staging_id): ?object {
    $row = $this->database->select('jobhunter_job_search_results', 's')
      ->fields('s')
      ->condition('s.id', $staging_id)
      ->range(0, 1)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Mark a staged search result as imported into the main jobs table.
   *
   * @param int $staging_id
   *   Primary key from jobhunter_job_search_results.
   * @param int $job_id
   *   Imported jobhunter_job_requirements ID.
   * @param int $uid
   *   User who triggered the import.
   */
  public function markStagingResultImported(int $staging_id, int $job_id, int $uid): void {
    $this->database->update('jobhunter_job_search_results')
      ->fields([
        'imported_to_job_id' => $job_id,
        'imported_at' => time(),
        'imported_by_uid' => $uid,
      ])
      ->condition('id', $staging_id)
      ->execute();
  }

  // ── Job Seeker ─────────────────────────────────────────────────────────

  /**
   * Return TRUE if the user has a non-empty consolidated profile.
   */
  public function hasCompletedProfile(int $uid): bool {
    try {
      $profile = $this->database->select('jobhunter_job_seeker', 'js')
        ->fields('js', ['consolidated_profile_json'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchField();
      return !empty($profile);
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Fetch job seeker profile fields for a user.
   *
   * @param int $uid
   *   User ID.
   * @param array $fields
   *   Columns to select.
   *
   * @return array|null
   *   Associative row or NULL if not found.
   */
  public function getJobSeekerProfile(int $uid, array $fields): ?array {
    $row = $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js', $fields)
      ->condition('js.uid', $uid)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  // ── Tailored Resumes ───────────────────────────────────────────────────

  /**
   * Update a tailored-resume row matched by (uid, job_id).
   */
  public function updateTailoredResume(int $uid, int $job_id, array $fields): void {
    $this->database->update('jobhunter_tailored_resumes')
      ->fields($fields)
      ->condition('uid', $uid)
      ->condition('job_id', $job_id)
      ->execute();
  }

  /**
   * Return the latest PDF path for a tailored resume, or NULL.
   */
  public function getResumeUri(int $uid, int $job_id): ?string {
    $uri = $this->database->select('jobhunter_tailored_resumes', 't')
      ->fields('t', ['pdf_path'])
      ->condition('uid', $uid)
      ->condition('job_id', $job_id)
      ->isNotNull('pdf_path')
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $uri ?: NULL;
  }

  /**
   * Fetch a tailored-resume row as an associative array (empty if not found).
   */
  public function getTailoredResumeRow(int $uid, int $job_id): array {
    return $this->database->select('jobhunter_tailored_resumes', 't')
      ->fields('t', ['tailoring_status', 'pdf_generated', 'pdf_path'])
      ->condition('t.uid', $uid)
      ->condition('t.job_id', $job_id)
      ->orderBy('created', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc() ?: [];
  }

  // ── Queue ──────────────────────────────────────────────────────────────

  /**
   * Return all data blobs for a named Drupal queue.
   *
   * @return array<object>
   *   Rows with a ->data property.
   */
  public function getQueueDataItems(string $queue_name): array {
    return $this->database->select('queue', 'q')
      ->fields('q', ['data'])
      ->condition('name', $queue_name)
      ->execute()
      ->fetchAll();
  }

  // ── Applications ───────────────────────────────────────────────────────

  /**
   * Return the most-recently-created application row for (uid, job_id).
   *
   * @param int $uid
   *   User ID.
   * @param int $job_id
   *   Job requirement ID.
   * @param array $fields
   *   Columns to select. Empty = all columns.
   *
   * @return array|null
   *   Associative row, or NULL if not found.
   */
  public function findLatestApplicationByJobAndUser(int $uid, int $job_id, array $fields = []): ?array {
    $schema = $this->database->schema();
    $query = $this->database->select('jobhunter_applications', 'a')
      ->condition('a.uid', $uid)
      ->condition('a.job_id', $job_id)
      ->orderBy('created', 'DESC')
      ->range(0, 1);
    if ($fields) {
      foreach ($fields as $field) {
        if ($schema->fieldExists('jobhunter_applications', $field)) {
          $query->addField('a', $field);
        }
        else {
          $query->addExpression('NULL', $field);
        }
      }
    }
    else {
      $query->fields('a');
    }
    $row = $query->execute()->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Update an application row by primary key.
   *
   * @param int $id
   *   Row primary key.
   * @param array $fields
   *   Column => value map.
   */
  public function updateApplication(int $id, array $fields): void {
    $this->database->update('jobhunter_applications')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Insert a new application row and return the new ID.
   *
   * @param array $fields
   *   Column => value map.
   *
   * @return int
   *   New row ID.
   */
  public function insertApplication(array $fields): int {
    return (int) $this->database->insert('jobhunter_applications')
      ->fields($fields)
      ->execute();
  }

  /**
   * Return TRUE if any application row exists for (uid, job_id).
   */
  public function hasApplicationForJob(int $uid, int $job_id): bool {
    return (bool) $this->database->select('jobhunter_applications', 'a')
      ->fields('a', ['id'])
      ->condition('a.uid', $uid)
      ->condition('a.job_id', $job_id)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * Return the submission_status for the most-recent application row, or ''.
   */
  public function getApplicationStatusByJobAndUser(int $uid, int $job_id): string {
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
   * Aggregate application counts by status for a user (and optional job).
   *
   * @param int $uid
   *   User ID.
   * @param int|null $job_id
   *   Optional job filter.
   *
   * @return array{total:int,submitted:int,processing:int,manual_required:int,failed:int}
   */
  public function getApplicationSubmissionSummary(int $uid, ?int $job_id = NULL): array {
    if (!$this->database->schema()->tableExists('jobhunter_applications')) {
      return ['total' => 0, 'submitted' => 0, 'processing' => 0, 'manual_required' => 0, 'failed' => 0];
    }

    $base = $this->database->select('jobhunter_applications', 'a')
      ->condition('a.uid', $uid);
    if ($job_id !== NULL) {
      $base->condition('a.job_id', $job_id);
    }

    $total = (int) (clone $base)->countQuery()->execute()->fetchField();
    $submitted = (int) (clone $base)->condition('a.submission_status', 'submitted')->countQuery()->execute()->fetchField();
    $processing = (int) (clone $base)->condition('a.submission_status', ['pending', 'processing', 'queued'], 'IN')->countQuery()->execute()->fetchField();
    $manual_required = (int) (clone $base)->condition('a.submission_status', 'manual_required')->countQuery()->execute()->fetchField();
    $failed = (int) (clone $base)->condition('a.submission_status', 'failed')->countQuery()->execute()->fetchField();

    return [
      'total' => $total,
      'submitted' => $submitted,
      'processing' => $processing,
      'manual_required' => $manual_required,
      'failed' => $failed,
    ];
  }

  /**
   * Return recent application rows for a user, optionally filtered by job.
   *
   * Optional columns are included only when they exist in the live schema.
   *
   * @return array<array<string,mixed>>
   *   Rows as associative arrays.
   */
  public function getRecentApplicationSubmissions(int $uid, int $limit = 25, ?int $job_id = NULL): array {
    $schema = $this->database->schema();
    if (!$schema->tableExists('jobhunter_applications')) {
      return [];
    }
    $query = $this->database->select('jobhunter_applications', 'a')
      ->condition('a.uid', $uid)
      ->orderBy('a.created', 'DESC')
      ->range(0, $limit);
    if ($job_id !== NULL) {
      $query->condition('a.job_id', $job_id);
    }

    $fields = ['id', 'job_id', 'submission_status'];
    foreach (['attempt_count', 'ats_platform', 'apply_url', 'selected_apply_option', 'metadata', 'confirmation_reference', 'confirmation_ref'] as $optional_field) {
      if ($schema->fieldExists('jobhunter_applications', $optional_field)) {
        $fields[] = $optional_field;
      }
    }
    $query->fields('a', $fields);

    if ($schema->tableExists('jobhunter_job_requirements') && $schema->fieldExists('jobhunter_job_requirements', 'job_title')) {
      $query->leftJoin('jobhunter_job_requirements', 'j', 'a.job_id = j.id');
      $query->addField('j', 'job_title');
    }

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  // ── Application Attempts ───────────────────────────────────────────────

  /**
   * Return the most-recent attempt row for an application, or NULL.
   */
  public function getLastAttempt(int $application_id): ?array {
    $row = $this->database->select('jobhunter_application_attempts', 'att')
      ->fields('att', ['outcome', 'error_message', 'attempted_at'])
      ->condition('att.application_id', $application_id)
      ->orderBy('attempted_at', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Return the latest attempt row for each application in the given set.
   *
   * @param int[] $application_ids
   *   Application primary keys.
   *
   * @return array<int,array>
   *   Keyed by application_id.
   */
  public function getLatestAttemptsByApplicationIds(array $application_ids): array {
    $application_ids = array_values(array_filter(array_map('intval', $application_ids)));
    if (empty($application_ids) || !$this->database->schema()->tableExists('jobhunter_application_attempts')) {
      return [];
    }

    $rows = $this->database->select('jobhunter_application_attempts', 'at')
      ->fields('at', ['id', 'application_id', 'attempted_at', 'outcome', 'error_message'])
      ->condition('at.application_id', $application_ids, 'IN')
      ->orderBy('at.application_id', 'ASC')
      ->orderBy('at.id', 'DESC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $latest = [];
    foreach ($rows as $row) {
      $application_id = (int) ($row['application_id'] ?? 0);
      if ($application_id > 0 && !isset($latest[$application_id])) {
        $latest[$application_id] = $row;
      }
    }

    return $latest;
  }

  // ── Companies ──────────────────────────────────────────────────────────

  /**
   * Return the company name for a given company ID, or NULL.
   */
  public function getCompanyName(int $company_id): ?string {
    $name = $this->database->select('jobhunter_companies', 'c')
      ->fields('c', ['name'])
      ->condition('c.id', $company_id)
      ->execute()
      ->fetchField();
    return $name ?: NULL;
  }

  /**
   * Return all company rows ordered by name.
   *
   * @return array<object>
   */
  public function getAllCompanies(): array {
    return $this->database->select('jobhunter_companies', 'c')
      ->fields('c')
      ->orderBy('name', 'ASC')
      ->execute()
      ->fetchAll();
  }

  /**
   * Return job count per company_id for active jobs (keyed by company_id).
   *
   * @return array<int,int>
   */
  public function getActiveJobCountsByCompany(): array {
    $query = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j', ['company_id'])
      ->condition('status', 'active')
      ->groupBy('company_id');
    $query->addExpression('COUNT(*)', 'job_count');
    return $query->execute()->fetchAllKeyed(0, 1);
  }

  /**
   * Return all active job requirement rows with extracted_json and company_id.
   *
   * @return array<object>
   */
  public function getActiveJobsForCompanyExtraction(): array {
    return $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j', ['id', 'extracted_json', 'company_id'])
      ->condition('status', 'active')
      ->execute()
      ->fetchAll();
  }

  // ── Composite / Context ────────────────────────────────────────────────

  /**
   * Load a fully-enriched job context object for the given user and job.
   *
   * Returns NULL when the user has no saved mapping AND no application for
   * the job, or when the job row itself is missing.
   *
   * The returned object carries extra properties beyond the job row:
   *   ->tailoring_status, ->pdf_generated, ->pdf_path, ->application_status,
   *   ->extracted_data (decoded from extracted_json), ->job_title, ->company_name
   *
   * @return object|null
   *   Enriched job stdClass, or NULL.
   */
  public function loadJobContext(int $uid, int $job_id): ?object {
    $has_saved = (bool) $this->database->select('jobhunter_saved_jobs', 'sj')
      ->fields('sj', ['id'])
      ->condition('sj.uid', $uid)
      ->condition('sj.job_id', $job_id)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $has_application = (bool) $this->database->select('jobhunter_applications', 'a')
      ->fields('a', ['id'])
      ->condition('a.uid', $uid)
      ->condition('a.job_id', $job_id)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if (!$has_saved && !$has_application) {
      return NULL;
    }

    $job = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j')
      ->condition('j.id', $job_id)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if (!$job) {
      return NULL;
    }

    $tailored = $this->getTailoredResumeRow($uid, $job_id);

    $application_status = $this->getApplicationStatusByJobAndUser($uid, $job_id);

    $job->tailoring_status = (string) ($tailored['tailoring_status'] ?? '');
    $job->pdf_generated = (int) ($tailored['pdf_generated'] ?? 0);
    $job->pdf_path = (string) ($tailored['pdf_path'] ?? '');
    $job->application_status = $application_status;

    if (!empty($job->extracted_json) && is_string($job->extracted_json)) {
      $decoded = json_decode($job->extracted_json, TRUE);
      if (is_array($decoded)) {
        $job->extracted_data = $decoded;
      }
    }

    if (empty($job->job_title) && !empty($job->extracted_data['position']['title'])) {
      $job->job_title = (string) $job->extracted_data['position']['title'];
    }
    if (empty($job->company_name) && !empty($job->extracted_data['company']['name'])) {
      $job->company_name = (string) $job->extracted_data['company']['name'];
    }

    if (empty($job->company_name) && !empty($job->company_id)) {
      $company_name = $this->getCompanyName((int) $job->company_id);
      if ($company_name) {
        $job->company_name = $company_name;
      }
    }

    return $job;
  }

  // ── User Skills and Profile ────────────────────────────────────────────────

  /**
   * Get user's skills string from jobhunter_job_seeker table.
   *
   * @param int $uid
   *   User ID.
   *
   * @return string
   *   The user's skills string, or empty string if not found.
   */
  public function getUserSkills(int $uid): string {
    try {
      $seeker_row = $this->database->select('jobhunter_job_seeker', 'js')
        ->fields('js', ['skills'])
        ->condition('js.uid', $uid)
        ->execute()
        ->fetchObject();
      return $seeker_row ? (string) ($seeker_row->skills ?? '') : '';
    }
    catch (\Exception $e) {
      return '';
    }
  }

  // ── Applications Dashboard ────────────────────────────────────────────────

  /**
   * Get user's recent applications (paginated).
   *
   * @param int $uid
   *   User ID.
   * @param int $limit
   *   Maximum number of results.
   *
   * @return array
   *   Array of application rows (associative arrays).
   */
  public function getUserApplications(int $uid, int $limit = 100): array {
    try {
      return $this->database->select('jobhunter_applications', 'a')
        ->fields('a', ['id', 'job_id', 'submission_status', 'submission_date', 'created'])
        ->condition('a.uid', $uid)
        ->orderBy('a.created', 'DESC')
        ->range(0, $limit)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Get job titles and companies for given job IDs.
   *
   * @param array $job_ids
   *   Array of job IDs.
   *
   * @return array
   *   Keyed array: job_id => ['title' => ..., 'company' => ...]
   */
  public function getJobTitlesByIds(array $job_ids): array {
    if (empty($job_ids)) {
      return [];
    }
    try {
      $rows = $this->database->select('jobhunter_saved_jobs', 'j')
        ->fields('j', ['id', 'title', 'company'])
        ->condition('j.id', $job_ids, 'IN')
        ->execute()
        ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
      return (array) $rows;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Bulk update application statuses for a user.
   *
   * @param array $ids
   *   Application IDs to update.
   * @param string $new_status
   *   New status value.
   * @param int $uid
   *   User ID (for security: only update own applications).
   *
   * @return int
   *   Number of rows updated.
   */
  public function updateApplicationStatuses(array $ids, string $new_status, int $uid): int {
    if (empty($ids)) {
      return 0;
    }
    try {
      $updated = $this->database->update('jobhunter_applications')
        ->fields(['submission_status' => $new_status])
        ->condition('id', $ids, 'IN')
        ->condition('uid', $uid)
        ->execute();
      return (int) ($updated ?? 0);
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  // ── Analytics ──────────────────────────────────────────────────────────────

  /**
   * Count user's saved jobs (not archived).
   *
   * @param int $uid
   *   User ID.
   *
   * @return int
   *   Count of saved jobs.
   */
  public function countUserSavedJobs(int $uid): int {
    try {
      $count = (int) $this->database->query(
        "SELECT COUNT(*) FROM {jobhunter_saved_jobs} sj WHERE sj.uid = :uid AND sj.archived = 0",
        [':uid' => $uid]
      )->fetchField();
      return $count;
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * Get application funnel data (stage counts) for user.
   *
   * @param int $uid
   *   User ID.
   *
   * @return array
   *   Keyed array: stage => count
   */
  public function getApplicationFunnel(int $uid): array {
    try {
      $funnel_raw = $this->database->query(
        "SELECT jr.application_status AS stage, COUNT(*) AS cnt
         FROM {jobhunter_saved_jobs} sj
         JOIN {jobhunter_job_requirements} jr ON sj.job_id = jr.id
         WHERE sj.uid = :uid AND sj.archived = 0
         GROUP BY jr.application_status",
        [':uid' => $uid]
      )->fetchAllKeyed();
      return (array) $funnel_raw;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  // ── Offers ────────────────────────────────────────────────────────────────

  /**
   * Check if offers table exists.
   *
   * @return bool
   *   TRUE if table exists.
   */
  public function offersTableExists(): bool {
    try {
      return $this->database->schema()->tableExists('jobhunter_offers');
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Get all offers for a user.
   *
   * @param int $uid
   *   User ID.
   *
   * @return array
   *   Array of offer rows.
   */
  public function getOffersForUser(int $uid): array {
    try {
      return $this->database->select('jobhunter_offers', 'o')
        ->fields('o')
        ->condition('o.uid', $uid)
        ->orderBy('o.response_deadline', 'ASC')
        ->orderBy('o.created', 'ASC')
        ->execute()
        ->fetchAll();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Check if company table has 'name' field (vs legacy 'company_name').
   *
   * @return bool
   *   TRUE if 'name' field exists, FALSE otherwise.
   */
  public function companyTableHasNameField(): bool {
    try {
      return $this->database->schema()->fieldExists('jobhunter_companies', 'name');
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Get saved job row for a user.
   *
   * @param int $uid
   *   User ID.
   * @param int $saved_job_id
   *   Saved job ID.
   * @param array $fields
   *   Fields to fetch.
   *
   * @return object|null
   *   The saved job row or NULL if not found.
   */
  public function getSavedJobForUser(int $uid, int $saved_job_id, array $fields = []): ?object {
    if (empty($fields)) {
      $fields = ['job_id'];
    }
    try {
      return $this->database->select('jobhunter_saved_jobs', 'sj')
        ->fields('sj', $fields)
        ->condition('sj.uid', $uid)
        ->condition('sj.id', $saved_job_id)
        ->execute()
        ->fetchObject();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Get job requirement row.
   *
   * @param int $job_id
   *   Job ID.
   *
   * @return object|null
   *   The job requirement row or NULL if not found.
   */
  public function getJobRequirement(int $job_id): ?object {
    try {
      return $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j', ['id', 'job_title', 'company_id'])
        ->condition('j.id', $job_id)
        ->execute()
        ->fetchObject();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Get company row by ID, using dynamic field name.
   *
   * @param int $company_id
   *   Company ID.
   * @param string $name_field
   *   Field name to fetch ('name' or 'company_name').
   *
   * @return object|null
   *   The company row or NULL if not found.
   */
  public function getCompanyById(int $company_id, string $name_field = 'name'): ?object {
    try {
      return $this->database->select('jobhunter_companies', 'c')
        ->fields('c', [$name_field])
        ->condition('c.id', $company_id)
        ->execute()
        ->fetchObject();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
