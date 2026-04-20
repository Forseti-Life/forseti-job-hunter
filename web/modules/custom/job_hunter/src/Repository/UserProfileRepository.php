<?php

namespace Drupal\job_hunter\Repository;

use Drupal\Core\Database\Connection;

/**
 * Provides database access methods for user profile operations.
 */
class UserProfileRepository {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Updates a parsed resume data row.
   *
   * Used by: UserProfileForm::submitForm() — parsed_data_* field handling.
   */
  public function updateParsedResumeData(int $parsedId, string $parsedData): void {
    $this->database->update('jobhunter_resume_parsed_data')
      ->fields(['parsed_data' => $parsedData, 'changed' => time()])
      ->condition('id', $parsedId)
      ->execute();
  }

  /**
   * Returns the consolidated_profile_json row for a user, or FALSE if absent.
   *
   * Used by: UserProfileForm::syncFormFieldsToConsolidatedJson().
   *
   * @return array|false
   *   Associative array with 'consolidated_profile_json' key, or FALSE.
   */
  public function getConsolidatedProfileJsonRow(int $uid): array|false {
    return $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js', ['consolidated_profile_json'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
  }

  /**
   * Checks whether a resume file is already registered for a job seeker.
   */
  public function resumeFileRegistered(int $jobSeekerId, int $fileId): bool {
    return (bool) $this->database->select('jobhunter_job_seeker_resumes', 'jsr')
      ->condition('job_seeker_id', $jobSeekerId)
      ->condition('file_id', $fileId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts the number of resumes registered for a job seeker.
   */
  public function countResumesForJobSeeker(int $jobSeekerId): int {
    return (int) $this->database->select('jobhunter_job_seeker_resumes', 'jsr')
      ->condition('job_seeker_id', $jobSeekerId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Creates a new resume record and returns the new record ID.
   *
   * @return int
   *   The new resume record ID.
   */
  public function createResumeRecord(int $jobSeekerId, int $fileId, string $resumeName, int $isPrimary): int {
    return (int) $this->database->insert('jobhunter_job_seeker_resumes')
      ->fields([
        'job_seeker_id' => $jobSeekerId,
        'file_id' => $fileId,
        'resume_name' => $resumeName,
        'is_primary' => $isPrimary,
        'created' => time(),
        'changed' => time(),
      ])
      ->execute();
  }

  /**
   * Updates the extracted_text field for a resume record.
   */
  public function updateResumeExtractedText(int $resumeId, string $extractedText): void {
    $this->database->update('jobhunter_job_seeker_resumes')
      ->fields(['extracted_text' => $extractedText])
      ->condition('id', $resumeId)
      ->execute();
  }

  /**
   * Inserts a new parsed data record (e.g., with 'queued' or 'complete' status).
   */
  public function insertParsedDataRecord(int $uid, int $fileId, string $filePath, string $parsedData, string $status, ?string $errorMessage, int $timestamp): void {
    $this->database->insert('jobhunter_resume_parsed_data')
      ->fields([
        'uid' => $uid,
        'resume_file_id' => $fileId,
        'resume_path' => $filePath,
        'parsed_data' => $parsedData,
        'status' => $status,
        'error_message' => $errorMessage,
        'created' => $timestamp,
        'changed' => $timestamp,
      ])
      ->execute();
  }

  /**
   * Loads a resume record by ID, restricted to the given job seeker IDs.
   *
   * @return array
   *   Associative array with id, file_id, resume_name, extracted_text.
   *
   * @throws \Exception
   *   If no matching record is found.
   */
  public function loadResumeRecord(int $resumeId, array $jobSeekerIds): array {
    $record = $this->database->select('jobhunter_job_seeker_resumes', 'jsr')
      ->fields('jsr', ['id', 'file_id', 'resume_name', 'extracted_text'])
      ->condition('id', $resumeId)
      ->condition('job_seeker_id', $jobSeekerIds, 'IN')
      ->execute()
      ->fetchAssoc();

    if (empty($record)) {
      throw new \Exception("Resume record not found (ID: {$resumeId})");
    }

    return $record;
  }

  /**
   * Returns the latest parsed data record for a file, or NULL if absent.
   *
   * @return array|null
   *   Associative array with 'parsed_data' key, or NULL.
   */
  public function getLatestParsedDataByFileId(int $uid, int $fileId): ?array {
    $record = $this->database->select('jobhunter_resume_parsed_data', 'rpd')
      ->fields('rpd', ['parsed_data'])
      ->condition('uid', $uid)
      ->condition('resume_file_id', $fileId)
      ->orderBy('changed', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $record ?: NULL;
  }

  /**
   * Returns resume rows (id, file_id, extracted_text) for a set of job seeker IDs.
   *
   * @return \stdClass[]
   */
  public function getResumeRowsForJobSeeker(array $jobSeekerIds): array {
    return $this->database->select('jobhunter_job_seeker_resumes', 'r')
      ->fields('r', ['id', 'file_id', 'extracted_text'])
      ->condition('job_seeker_id', $jobSeekerIds, 'IN')
      ->execute()
      ->fetchAll();
  }

  /**
   * Upserts a parsed data record with 'complete' status.
   *
   * Checks for an existing record by uid + resume_file_id; updates if found,
   * inserts otherwise.
   */
  public function upsertParsedDataRecord(int $uid, int $fileId, string $filePath, string $parsedData, int $timestamp): void {
    $existing = $this->database->select('jobhunter_resume_parsed_data', 'rpd')
      ->fields('rpd', ['id'])
      ->condition('uid', $uid)
      ->condition('resume_file_id', $fileId)
      ->execute()
      ->fetchField();

    if ($existing) {
      $this->database->update('jobhunter_resume_parsed_data')
        ->fields([
          'resume_path' => $filePath,
          'parsed_data' => $parsedData,
          'status' => 'complete',
          'error_message' => NULL,
          'changed' => $timestamp,
        ])
        ->condition('id', $existing)
        ->execute();
    }
    else {
      $this->database->insert('jobhunter_resume_parsed_data')
        ->fields([
          'uid' => $uid,
          'resume_file_id' => $fileId,
          'resume_path' => $filePath,
          'parsed_data' => $parsedData,
          'status' => 'complete',
          'error_message' => NULL,
          'created' => $timestamp,
          'changed' => $timestamp,
        ])
        ->execute();
    }
  }

  /**
   * Saves the consolidated profile JSON for a user.
   */
  public function saveConsolidatedProfileJson(int $uid, string $json): void {
    $this->database->update('jobhunter_job_seeker')
      ->fields([
        'consolidated_profile_json' => $json,
        'changed' => time(),
      ])
      ->condition('uid', $uid)
      ->execute();
  }

  /**
   * Normalizes legacy 'completed' status values to 'complete' in parsed data.
   */
  public function normalizeParsedDataStatuses(int $uid): void {
    $this->database->update('jobhunter_resume_parsed_data')
      ->fields(['status' => 'complete', 'changed' => time()])
      ->condition('uid', $uid)
      ->condition('status', 'completed')
      ->execute();
  }

  /**
   * Returns the count of pending (queued or processing) parsed data records.
   */
  public function countPendingParsedRecords(int $uid): int {
    return (int) $this->database->select('jobhunter_resume_parsed_data', 'rpd')
      ->condition('uid', $uid)
      ->condition('status', ['queued', 'processing'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Returns an array of resume_file_id values for 'complete' parsed data records.
   *
   * @return int[]
   */
  public function getCompleteParsedFileIds(int $uid): array {
    return $this->database->select('jobhunter_resume_parsed_data', 'rpd')
      ->fields('rpd', ['resume_file_id'])
      ->condition('uid', $uid)
      ->condition('status', 'complete')
      ->execute()
      ->fetchCol();
  }

  /**
   * Returns profile fields used by applyConsolidatedToProfileFields(), or NULL.
   *
   * @return array|null
   *   Associative array of profile field values, or NULL if no profile found.
   */
  public function getProfileFieldsForConsolidation(int $uid): ?array {
    $result = $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js', [
        'professional_summary',
        'skills',
        'experience_years',
        'education_level',
        'certifications',
        'job_titles',
      ])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    return $result ?: NULL;
  }

  /**
   * Updates job seeker profile fields for a user.
   *
   * @param array $fields
   *   Associative array of column => value pairs to update (include 'changed').
   */
  public function updateProfileFields(int $uid, array $fields): void {
    $this->database->update('jobhunter_job_seeker')
      ->fields($fields)
      ->condition('uid', $uid)
      ->execute();
  }

}
