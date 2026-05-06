<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Service for managing job seeker profiles.
 */
class JobSeekerService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a JobSeekerService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(Connection $database, AccountProxyInterface $current_user) {
    $this->database = $database;
    $this->currentUser = $current_user;
  }

  /**
   * Load a job seeker profile by user ID.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return object|null
   *   The job seeker profile object or NULL if not found.
   */
  public function loadByUserId($uid) {
    $query = $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js')
      ->condition('uid', $uid)
      ->execute();
    
    $profile = $query->fetchObject();
    
    return $profile;
  }

  /**
   * Load a job seeker profile by ID.
   *
   * @param int $id
   *   The job seeker profile ID.
   *
   * @return object|null
   *   The job seeker profile object or NULL if not found.
   */
  public function load($id) {
    $query = $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js')
      ->condition('id', $id)
      ->execute();
    
    $profile = $query->fetchObject();
    
    if ($profile) {
      // Decode JSON fields (only target_companies and preferred_locations are JSON)
      // skills, job_titles, etc. are plain text
      $profile->target_companies = $profile->target_companies ? json_decode($profile->target_companies, TRUE) : [];
      $profile->preferred_locations = $profile->preferred_locations ? json_decode($profile->preferred_locations, TRUE) : [];
    }
    
    return $profile;
  }

  /**
   * Create a new job seeker profile.
   *
   * @param array $values
   *   An associative array of values for the profile.
   *
   * @return int
   *   The ID of the newly created profile.
   */
  public function create(array $values) {
    $timestamp = \Drupal::time()->getRequestTime();
    
    // Encode JSON fields (only target_companies and preferred_locations are JSON)
    // skills, job_titles, etc. are plain text
    if (isset($values['target_companies']) && is_array($values['target_companies'])) {
      $values['target_companies'] = json_encode($values['target_companies']);
    }
    if (isset($values['preferred_locations']) && is_array($values['preferred_locations'])) {
      $values['preferred_locations'] = json_encode($values['preferred_locations']);
    }
    
    $values['created'] = $timestamp;
    $values['changed'] = $timestamp;

    $new_id = $this->database->insert('jobhunter_job_seeker')
      ->fields($values)
      ->execute();

    // Keep projection columns in sync when consolidated_profile_json is provided.
    if (!empty($values['uid']) && array_key_exists('consolidated_profile_json', $values)) {
      $decoded = [];
      if (!empty($values['consolidated_profile_json'])) {
        $decoded = json_decode((string) $values['consolidated_profile_json'], TRUE);
        $decoded = is_array($decoded) ? $decoded : [];
      }
      $this->updateProfileProjections((int) $values['uid'], $decoded);
    }

    return $new_id;
  }

  /**
   * Update an existing job seeker profile.
   *
   * @param int $id
   *   The job seeker profile ID.
   * @param array $values
   *   An associative array of values to update.
   *
   * @return int
   *   The number of rows affected.
   */
  public function update($id, array $values) {
    $timestamp = \Drupal::time()->getRequestTime();
    
    // Encode JSON fields (only target_companies and preferred_locations are JSON)
    // skills, job_titles, etc. are plain text
    if (isset($values['target_companies']) && is_array($values['target_companies'])) {
      $values['target_companies'] = json_encode($values['target_companies']);
    }
    if (isset($values['preferred_locations']) && is_array($values['preferred_locations'])) {
      $values['preferred_locations'] = json_encode($values['preferred_locations']);
    }
    
    $values['changed'] = $timestamp;

    $rows = $this->database->update('jobhunter_job_seeker')
      ->fields($values)
      ->condition('id', $id)
      ->execute();

    // Keep projection columns in sync when consolidated_profile_json changes.
    if (array_key_exists('consolidated_profile_json', $values)) {
      $uid = (int) $this->database->select('jobhunter_job_seeker', 'js')
        ->fields('js', ['uid'])
        ->condition('id', $id)
        ->execute()
        ->fetchField();

      if ($uid > 0) {
        $decoded = [];
        if (!empty($values['consolidated_profile_json'])) {
          $decoded = json_decode((string) $values['consolidated_profile_json'], TRUE);
          $decoded = is_array($decoded) ? $decoded : [];
        }
        $this->updateProfileProjections($uid, $decoded);
      }
    }

    return $rows;
  }

  /**
   * Delete a job seeker profile.
   *
   * @param int $id
   *   The job seeker profile ID.
   *
   * @return int
   *   The number of rows affected.
   */
  public function delete($id) {
    return $this->database->delete('jobhunter_job_seeker')
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Check if a user has a job seeker profile.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return bool
   *   TRUE if the user has a profile, FALSE otherwise.
   */
  public function userHasProfile($uid) {
    $count = $this->database->select('jobhunter_job_seeker', 'js')
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField();
    
    return $count > 0;
  }

  /**
   * Get the current user's job seeker profile.
   *
   * @return object|null
   *   The job seeker profile object or NULL if not found.
   */
  public function getCurrentUserProfile() {
    return $this->loadByUserId($this->currentUser->id());
  }

  /**
   * Get consolidated profile JSON for a user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   Consolidated profile array (empty array if missing/invalid).
   */
  public function getConsolidatedProfile(int $uid): array {
    $json = $this->database->select('jobhunter_job_seeker', 'js')
      ->fields('js', ['consolidated_profile_json'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    if (!$json) {
      return [];
    }

    $decoded = json_decode($json, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Update projection columns from consolidated_profile_json.
   *
   * Intended to be called after resume consolidation or any direct edits to
   * consolidated_profile_json.
   *
   * @param int $uid
   *   The user ID.
   * @param array|null $consolidated
   *   Optional consolidated profile array to avoid reloading from DB.
   */
  public function updateProfileProjections(int $uid, ?array $consolidated = NULL): void {
    $schema = $this->database->schema();
    $projection_columns = [
      'full_name',
      'contact_email',
      'contact_phone',
      'location_city',
      'location_state',
      'remote_preference',
      'relocation_willing',
      'salary_min',
      'salary_max',
      'available_start_date',
      'linkedin_url',
      'github_url',
      'portfolio_url',
      'projection_updated',
    ];
    $available_columns = [];
    foreach ($projection_columns as $column) {
      if ($schema->fieldExists('jobhunter_job_seeker', $column)) {
        $available_columns[$column] = TRUE;
      }
    }

    if (empty($available_columns)) {
      return;
    }

    $consolidated = $consolidated ?? $this->getConsolidatedProfile($uid);
    if (empty($consolidated)) {
      // Still record projection update time so callers can detect the attempt.
      if (isset($available_columns['projection_updated'])) {
        $this->database->update('jobhunter_job_seeker')
          ->fields(['projection_updated' => \Drupal::time()->getRequestTime()])
          ->condition('uid', $uid)
          ->execute();
      }
      return;
    }

    $contact = $consolidated['contact_info'] ?? [];
    $location = is_array($contact['location'] ?? NULL) ? $contact['location'] : [];
    $websites = is_array($contact['websites'] ?? NULL) ? $contact['websites'] : [];
    $prefs = is_array($consolidated['job_search_preferences'] ?? NULL) ? $consolidated['job_search_preferences'] : [];

    $urls = [
      'linkedin' => '',
      'github' => '',
      'personal' => '',
      'portfolio' => '',
    ];
    foreach ($websites as $site) {
      if (!is_array($site)) {
        continue;
      }
      $type = strtolower(trim((string) ($site['type'] ?? '')));
      $url = trim((string) ($site['url'] ?? ''));
      if ($url === '') {
        continue;
      }
      if ($type !== '' && isset($urls[$type]) && $urls[$type] === '') {
        $urls[$type] = $url;
      }
      // Fallback: infer from domain if type is missing.
      if ($type === '') {
        if ($urls['linkedin'] === '' && stripos($url, 'linkedin.com') !== FALSE) {
          $urls['linkedin'] = $url;
        }
        if ($urls['github'] === '' && stripos($url, 'github.com') !== FALSE) {
          $urls['github'] = $url;
        }
      }
    }

    $remote = strtolower(trim((string) ($prefs['remote_preference'] ?? '')));
    if (!in_array($remote, ['remote', 'hybrid', 'onsite', 'any'], TRUE)) {
      $remote = '';
    }

    $relocation_raw = $prefs['relocation_willing'] ?? NULL;
    $relocation = 0;
    if (is_bool($relocation_raw)) {
      $relocation = (int) $relocation_raw;
    }
    elseif (is_numeric($relocation_raw)) {
      $relocation = (int) ((int) $relocation_raw > 0);
    }
    elseif (is_string($relocation_raw)) {
      $relocation = (int) in_array(strtolower($relocation_raw), ['1', 'yes', 'true', 'y'], TRUE);
    }

    $salary_min = $prefs['salary_expectation_min'] ?? NULL;
    $salary_max = $prefs['salary_expectation_max'] ?? NULL;
    $salary_min = is_numeric($salary_min) ? (int) $salary_min : NULL;
    $salary_max = is_numeric($salary_max) ? (int) $salary_max : NULL;

    $available = trim((string) ($prefs['available_start_date'] ?? $prefs['available_start'] ?? ''));
    if ($available !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $available)) {
      $available = '';
    }

    $update = [
      'full_name' => trim((string) ($contact['full_name'] ?? '')),
      'contact_email' => trim((string) ($contact['email'] ?? '')),
      'contact_phone' => trim((string) ($contact['phone'] ?? '')),
      'location_city' => trim((string) ($location['city'] ?? '')),
      'location_state' => trim((string) ($location['state'] ?? '')),
      'remote_preference' => $remote,
      'relocation_willing' => $relocation,
      'salary_min' => $salary_min,
      'salary_max' => $salary_max,
      'available_start_date' => $available,
      'linkedin_url' => $urls['linkedin'],
      'github_url' => $urls['github'],
      'portfolio_url' => $urls['portfolio'] ?: $urls['personal'],
      'projection_updated' => \Drupal::time()->getRequestTime(),
    ];

    $filtered_update = array_intersect_key($update, $available_columns);
    if (!empty($filtered_update)) {
      $this->database->update('jobhunter_job_seeker')
        ->fields($filtered_update)
        ->condition('uid', $uid)
        ->execute();
    }
  }

}
