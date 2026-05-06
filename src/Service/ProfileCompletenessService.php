<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;

/**
 * Computes profile completeness from the job seeker DB tables.
 *
 * Fields evaluated (each worth 20 points, 5 fields × 20 = 100):
 *   - display_name  : full_name in jobhunter_job_seeker
 *   - resume_text   : resume_node_id in jobhunter_job_seeker
 *   - skills        : skills in jobhunter_job_seeker
 *   - education_history: ≥1 row in jobhunter_education_history
 *   - work_experience  : ≥1 row in jobhunter_job_history
 */
class ProfileCompletenessService {

  /**
   * Point weight per field.
   */
  const FIELD_WEIGHT = 20;

  /**
   * Field definitions: field ID → human label for missing-field checklist.
   */
  const FIELDS = [
    'display_name' => 'Display name',
    'resume_text' => 'Resume',
    'skills' => 'Skills',
    'education_history' => 'Education history',
    'work_experience' => 'Work experience',
  ];

  /**
   * Profile edit page path (single form — sections reached by fragment).
   */
  const EDIT_PATH = '/jobhunter/profile/edit';

  /**
   * HTML fragment anchors for each field on the edit form.
   */
  const FIELD_ANCHORS = [
    'display_name' => '#edit-core-info',
    'resume_text' => '#edit-resume-workflow',
    'skills' => '#edit-core-info',
    'education_history' => '#edit-experience-education',
    'work_experience' => '#edit-experience-education',
  ];

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a ProfileCompletenessService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Computes the completeness integer (0–100) for a user.
   *
   * Calling this method twice with identical data returns the same integer.
   *
   * @param int $uid
   *   The Drupal user ID.
   *
   * @return int
   *   Completeness percentage 0–100.
   */
  public function calculate(int $uid): int {
    $present = $this->evaluateFields($uid);
    $completed = count(array_filter($present));
    return (int) round(($completed / count(self::FIELDS)) * 100);
  }

  /**
   * Returns missing field definitions with human labels and direct edit links.
   *
   * @param int $uid
   *   The Drupal user ID.
   *
   * @return array
   *   Array of items, each with keys:
   *   - 'id'    : field machine name
   *   - 'label' : human-readable field label
   *   - 'link'  : absolute path to the profile edit section
   */
  public function getMissingFields(int $uid): array {
    $present = $this->evaluateFields($uid);
    $missing = [];
    foreach (self::FIELDS as $field_id => $label) {
      if (empty($present[$field_id])) {
        $missing[] = [
          'id' => $field_id,
          'label' => $label,
          'link' => self::EDIT_PATH . self::FIELD_ANCHORS[$field_id],
        ];
      }
    }
    return $missing;
  }

  /**
   * Returns boolean presence for each tracked field.
   *
   * @param int $uid
   *   The Drupal user ID.
   *
   * @return array
   *   Keyed by field ID, boolean value.
   */
  public function evaluateFields(int $uid): array {
    $job_seeker = $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js', ['id', 'full_name', 'resume_node_id', 'skills'])
      ->condition('js.uid', $uid)
      ->execute()
      ->fetchObject();

    $education_count = 0;
    $work_count = 0;

    if ($job_seeker) {
      $education_count = (int) $this->database->select('jobhunter_education_history', 'eh')
        ->condition('eh.job_seeker_id', $job_seeker->id)
        ->countQuery()
        ->execute()
        ->fetchField();

      $work_count = (int) $this->database->select('jobhunter_job_history', 'jh')
        ->condition('jh.job_seeker_id', $job_seeker->id)
        ->countQuery()
        ->execute()
        ->fetchField();
    }

    return [
      'display_name' => $job_seeker && !empty($job_seeker->full_name),
      'resume_text' => $job_seeker && !empty($job_seeker->resume_node_id),
      'skills' => $job_seeker && !empty($job_seeker->skills),
      'education_history' => $education_count >= 1,
      'work_experience' => $work_count >= 1,
    ];
  }

}
