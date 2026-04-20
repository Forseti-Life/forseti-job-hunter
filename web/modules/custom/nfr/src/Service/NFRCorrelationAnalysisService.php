<?php

declare(strict_types=1);

namespace Drupal\nfr\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for populating and managing the correlation analysis table.
 */
class NFRCorrelationAnalysisService {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Frequency midpoint mapping for incident calculations.
   */
  protected const FREQUENCY_MIDPOINTS = [
    'never' => 0,
    'less_than_1' => 0.5,
    '1_5' => 3,
    '6_20' => 13,
    '21_50' => 35.5,
    'more_than_50' => 60,
  ];

  /**
   * Constructs a NFRCorrelationAnalysisService object.
   */
  public function __construct(Connection $database, LoggerChannelFactoryInterface $logger_factory) {
    $this->database = $database;
    $this->logger = $logger_factory->get('nfr');
  }

  /**
   * Rebuild correlation data for a specific user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function rebuildUserData(int $uid): bool {
    try {
      $data = $this->aggregateUserData($uid);
      
      if (empty($data)) {
        $this->logger->warning('No data found for user @uid', ['@uid' => $uid]);
        return FALSE;
      }
      
      // Insert or update
      $exists = $this->database->select('nfr_correlation_analysis', 'c')
        ->fields('c', ['id'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchField();
      
      if ($exists) {
        $this->database->update('nfr_correlation_analysis')
          ->fields($data)
          ->condition('uid', $uid)
          ->execute();
      }
      else {
        $this->database->insert('nfr_correlation_analysis')
          ->fields($data)
          ->execute();
      }
      
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Error rebuilding correlation data for user @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Rebuild correlation data for all users.
   *
   * @return array
   *   Statistics about the rebuild (success count, fail count, duration).
   */
  public function rebuildAllData(): array {
    $start_time = microtime(TRUE);
    $success = 0;
    $failed = 0;
    
    // Get all users with NFR data
    $uids = $this->database->select('nfr_user_profile', 'p')
      ->fields('p', ['uid'])
      ->execute()
      ->fetchCol();
    
    foreach ($uids as $uid) {
      if ($this->rebuildUserData((int) $uid)) {
        $success++;
      }
      else {
        $failed++;
      }
    }
    
    $duration = round(microtime(TRUE) - $start_time, 2);
    
    $this->logger->info('Correlation analysis rebuild complete: @success success, @failed failed, @duration seconds', [
      '@success' => $success,
      '@failed' => $failed,
      '@duration' => $duration,
    ]);
    
    return [
      'success' => $success,
      'failed' => $failed,
      'duration' => $duration,
    ];
  }

  /**
   * Aggregate all data for a specific user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   Aggregated data array.
   */
  protected function aggregateUserData(int $uid): array {
    $data = [
      'uid' => $uid,
      'data_snapshot_date' => \Drupal::time()->getRequestTime(),
    ];
    
    // Get demographics from user profile
    $profile_data = $this->getProfileData($uid);
    $data = array_merge($data, $profile_data);
    
    // Get questionnaire data (health, lifestyle, demographics)
    $questionnaire_data = $this->getQuestionnaireData($uid);
    $data = array_merge($data, $questionnaire_data);
    
    // Get work history aggregates
    $work_data = $this->getWorkHistoryData($uid);
    $data = array_merge($data, $work_data);
    
    // Get incident exposure metrics
    $incident_data = $this->getIncidentData($uid);
    $data = array_merge($data, $incident_data);
    
    // Get cancer outcomes
    $cancer_data = $this->getCancerData($uid);
    $data = array_merge($data, $cancer_data);
    
    // Get family cancer history
    $family_data = $this->getFamilyCancerData($uid);
    $data = array_merge($data, $family_data);
    
    // Calculate derived metrics
    $data = $this->calculateDerivedMetrics($data);
    
    // Calculate data quality score
    $data['data_quality_score'] = $this->calculateDataQualityScore($data);
    
    return $data;
  }

  /**
   * Get profile data from nfr_user_profile.
   */
  protected function getProfileData(int $uid): array {
    $profile = $this->database->select('nfr_user_profile', 'p')
      ->fields('p')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    
    if (!$profile) {
      return [];
    }
    
    $data = [
      'participant_id' => $profile['participant_id'] ?? NULL,
      'date_of_birth' => $profile['date_of_birth'] ?? NULL,
      'sex' => $profile['sex'] ?? NULL,
      'country_of_birth' => $profile['country_of_birth'] ?? NULL,
      'state_of_birth' => $profile['state_of_birth'] ?? NULL,
      'currently_active' => ($profile['current_work_status'] ?? '') === 'active' ? 1 : 0,
      'enrollment_date' => $profile['created'] ? date('Y-m-d', (int) $profile['created']) : NULL,
    ];
    
    // Calculate age at enrollment
    if (!empty($profile['date_of_birth']) && !empty($profile['created'])) {
      $dob = new \DateTime($profile['date_of_birth']);
      $enrollment = new \DateTime('@' . $profile['created']);
      $data['age_at_enrollment'] = $dob->diff($enrollment)->y;
      
      // Calculate years since enrollment
      $now = new \DateTime();
      $data['years_since_enrollment'] = round($enrollment->diff($now)->days / 365.25, 2);
    }
    
    return $data;
  }

  /**
   * Get questionnaire data (demographics, health, lifestyle).
   */
  protected function getQuestionnaireData(int $uid): array {
    $q = $this->database->select('nfr_questionnaire', 'q')
      ->fields('q')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    
    if (!$q) {
      return ['questionnaire_complete' => 0];
    }
    
    $data = [
      'questionnaire_complete' => (int) ($q['questionnaire_completed'] ?? 0),
      'education_level' => $q['education_level'] ?? NULL,
      'marital_status' => $q['marital_status'] ?? NULL,
      'height_inches' => $q['height_inches'] ?? NULL,
      'weight_pounds' => $q['weight_pounds'] ?? NULL,
      'smoking_status' => $q['smoking_status'] ?? NULL,
      'smoking_years' => $q['smoking_years'] ?? NULL,
      'alcohol_frequency' => $q['alcohol_frequency'] ?? NULL,
      'exercise_frequency' => $q['exercise_frequency'] ?? NULL,
      'diet_quality' => $q['diet_quality'] ?? NULL,
      'sleep_hours_avg' => $q['sleep_hours_per_night'] ?? NULL,
    ];
    
    // Parse race/ethnicity JSON array
    if (!empty($q['race_ethnicity'])) {
      $races = json_decode($q['race_ethnicity'], TRUE);
      if (is_array($races)) {
        $data['race_american_indian'] = in_array('american_indian', $races) ? 1 : 0;
        $data['race_asian'] = in_array('asian', $races) ? 1 : 0;
        $data['race_black'] = in_array('black', $races) ? 1 : 0;
        $data['race_hispanic'] = in_array('hispanic', $races) ? 1 : 0;
        $data['race_middle_eastern'] = in_array('middle_eastern', $races) ? 1 : 0;
        $data['race_pacific_islander'] = in_array('pacific_islander', $races) ? 1 : 0;
        $data['race_white'] = in_array('white', $races) ? 1 : 0;
        $data['race_other'] = in_array('other', $races) ? 1 : 0;
      }
    }
    
    return $data;
  }

  /**
   * Get work history aggregates.
   */
  protected function getWorkHistoryData(int $uid): array {
    $data = [
      'total_fire_departments' => 0,
      'total_years_service' => 0,
      'num_states_worked' => 0,
      'years_career' => 0,
      'years_volunteer' => 0,
      'years_paid_on_call' => 0,
      'years_seasonal' => 0,
      'years_wildland' => 0,
      'years_military' => 0,
      'held_leadership_role' => 0,
      'responded_to_incidents' => 0,
      'total_incident_years' => 0,
    ];
    
    // Get all work history records
    $work_history = $this->database->select('nfr_work_history', 'w')
      ->fields('w')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAllAssoc('id');
    
    $data['total_fire_departments'] = count($work_history);
    
    if (empty($work_history)) {
      return $data;
    }
    
    $work_history_ids = array_keys($work_history);
    $states = [];
    $earliest_year = NULL;
    
    // Get all job titles for this user's work history
    $job_titles = $this->database->select('nfr_job_titles', 'j')
      ->fields('j')
      ->condition('work_history_id', $work_history_ids, 'IN')
      ->execute()
      ->fetchAll();
    
    foreach ($work_history as $dept) {
      // Get states
      if (!empty($dept->department_state) && !in_array($dept->department_state, $states)) {
        $states[] = $dept->department_state;
      }
      
      // Track earliest start date from department level
      if (!empty($dept->start_date)) {
        $start_year = (int) date('Y', strtotime($dept->start_date));
        if ($earliest_year === NULL || $start_year < $earliest_year) {
          $earliest_year = $start_year;
        }
      }
    }
    
    foreach ($job_titles as $job) {
      // Get department dates for this job
      $dept = $work_history[$job->work_history_id] ?? NULL;
      if (!$dept || empty($dept->start_date)) {
        continue;
      }
      
      // Calculate years for this job (use department dates)
      $start = strtotime($dept->start_date);
      $is_current = $dept->is_current ?? 0;
      $end = $is_current ? time() : ($dept->end_date ? strtotime($dept->end_date) : time());
      $years = ($end - $start) / (365.25 * 24 * 60 * 60);
      
      // Skip if calculation resulted in negative years
      if ($years < 0) {
        continue;
      }
      
      // Aggregate by employment type
      switch ($job->employment_type) {
        case 'career':
          $data['years_career'] += $years;
          break;
        case 'volunteer':
          $data['years_volunteer'] += $years;
          break;
        case 'paid_on_call':
          $data['years_paid_on_call'] += $years;
          break;
        case 'seasonal':
          $data['years_seasonal'] += $years;
          break;
        case 'wildland':
          $data['years_wildland'] += $years;
          break;
        case 'military':
          $data['years_military'] += $years;
          break;
      }
      
      // Check if responded to incidents
      if ($job->responded_to_incidents == 1 || $job->responded_to_incidents === 'yes') {
        $data['responded_to_incidents'] = 1;
        $data['total_incident_years'] += $years;
      }
      
      // Check for leadership roles
      $leadership_titles = ['chief', 'battalion chief', 'assistant chief', 'deputy chief', 'captain', 'lieutenant'];
      foreach ($leadership_titles as $title) {
        if (stripos($job->job_title, $title) !== FALSE) {
          $data['held_leadership_role'] = 1;
          if (empty($data['highest_rank']) || $this->getRankWeight($job->job_title) > $this->getRankWeight($data['highest_rank'])) {
            $data['highest_rank'] = $job->job_title;
          }
        }
      }
    }
    
    $data['num_states_worked'] = count($states);
    
    // Calculate total years of service and primary state
    $data['total_years_service'] = round(
      $data['years_career'] + 
      $data['years_volunteer'] + 
      $data['years_paid_on_call'] + 
      $data['years_seasonal'] + 
      $data['years_wildland'] + 
      $data['years_military'],
      2
    );
    
    $data['year_first_firefighter'] = $earliest_year;
    
    // Round all year fields
    foreach (['years_career', 'years_volunteer', 'years_paid_on_call', 'years_seasonal', 'years_wildland', 'years_military', 'total_incident_years'] as $field) {
      $data[$field] = round($data[$field], 2);
    }
    
    return $data;
  }

  /**
   * Get incident exposure metrics from nfr_incident_frequency.
   */
  protected function getIncidentData(int $uid): array {
    $data = [
      'total_structure_residential_fires' => 0,
      'total_structure_commercial_fires' => 0,
      'total_vehicle_fires' => 0,
      'total_wildland_fires' => 0,
      'total_hazmat_incidents' => 0,
      'total_training_fires' => 0,
      'total_rubbish_fires' => 0,
      'total_medical_ems_calls' => 0,
      'total_technical_rescue' => 0,
      'total_arff_incidents' => 0,
      'total_marine_incidents' => 0,
      'total_prescribed_burns' => 0,
      'total_other_incidents' => 0,
      'years_high_structure_exposure' => 0,
    ];
    
    // Get work history records for this user (need dates)
    $work_history = $this->database->select('nfr_work_history', 'w')
      ->fields('w', ['id', 'start_date', 'end_date', 'is_current'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAllAssoc('id');
    
    if (empty($work_history)) {
      return $data;
    }
    
    // Get all job_titles for this user's work history (need work_history_id link)
    $job_titles = $this->database->select('nfr_job_titles', 'j')
      ->fields('j', ['id', 'work_history_id'])
      ->condition('work_history_id', array_keys($work_history), 'IN')
      ->execute()
      ->fetchAllAssoc('id');
    
    if (empty($job_titles)) {
      return $data;
    }
    
    $job_ids = array_keys($job_titles);
    
    // Get all incident frequency records for these jobs
    $incidents = $this->database->select('nfr_incident_frequency', 'i')
      ->fields('i')
      ->condition('job_title_id', $job_ids, 'IN')
      ->execute()
      ->fetchAll();
    
    if (empty($incidents)) {
      return $data;
    }
    
    // Get job duration for each incident record
    $job_durations = [];
    foreach ($incidents as $inc) {
      if (!isset($job_durations[$inc->job_title_id])) {
        // Get the job_title record to find its work_history_id
        $job_title = $job_titles[$inc->job_title_id] ?? NULL;
        if (!$job_title) {
          continue;
        }
        
        // Get the work_history record for dates
        $work_hist = $work_history[$job_title->work_history_id] ?? NULL;
        if ($work_hist && !empty($work_hist->start_date)) {
          $start = strtotime($work_hist->start_date);
          $is_current = $work_hist->is_current ?? 0;
          $end = $is_current ? time() : ($work_hist->end_date ? strtotime($work_hist->end_date) : time());
          $job_durations[$inc->job_title_id] = ($end - $start) / (365.25 * 24 * 60 * 60);
        }
      }
    }
    
    // Map incident types to data fields
    $incident_type_map = [
      'structure_residential' => 'total_structure_residential_fires',
      'structure_commercial' => 'total_structure_commercial_fires',
      'vehicle' => 'total_vehicle_fires',
      'wildland' => 'total_wildland_fires',
      'hazmat' => 'total_hazmat_incidents',
      'training_fires' => 'total_training_fires',
      'rubbish_dumpster' => 'total_rubbish_fires',
      'medical_ems' => 'total_medical_ems_calls',
      'technical_rescue' => 'total_technical_rescue',
      'arff' => 'total_arff_incidents',
      'marine' => 'total_marine_incidents',
      'prescribed_burns' => 'total_prescribed_burns',
      'other' => 'total_other_incidents',
    ];
    
    foreach ($incidents as $inc) {
      $years = $job_durations[$inc->job_title_id] ?? 1;
      $frequency_midpoint = self::FREQUENCY_MIDPOINTS[$inc->frequency] ?? 0;
      $estimated_total = $frequency_midpoint * $years;
      
      if (isset($incident_type_map[$inc->incident_type])) {
        $data[$incident_type_map[$inc->incident_type]] += (int) round($estimated_total);
      }
      
      // Count years with high structure fire exposure
      if (($inc->incident_type === 'structure_residential' || $inc->incident_type === 'structure_commercial') 
          && in_array($inc->frequency, ['21_50', 'more_than_50'])) {
        $data['years_high_structure_exposure'] += (int) ceil($years);
      }
    }
    
    // Calculate aggregate totals
    $data['total_structure_fires'] = $data['total_structure_residential_fires'] + $data['total_structure_commercial_fires'];
    $data['total_all_incidents'] = array_sum(array_filter($data, 'is_numeric'));
    
    // Calculate diversity score (number of incident types with exposure)
    $data['incident_diversity_score'] = 0;
    foreach ($incident_type_map as $field) {
      if ($data[$field] > 0) {
        $data['incident_diversity_score']++;
      }
    }
    
    // Determine primary incident type
    $primary_type = NULL;
    $max_count = 0;
    foreach ($incident_type_map as $type => $field) {
      if ($data[$field] > $max_count) {
        $max_count = $data[$field];
        $primary_type = $type;
      }
    }
    $data['primary_incident_type'] = $primary_type;
    
    return $data;
  }

  /**
   * Get cancer diagnosis data.
   */
  protected function getCancerData(int $uid): array {
    $data = [
      'has_cancer_diagnosis' => 0,
      'num_cancer_diagnoses' => 0,
      'has_lung_cancer' => 0,
      'has_colorectal_cancer' => 0,
      'has_prostate_cancer' => 0,
      'has_breast_cancer' => 0,
      'has_bladder_cancer' => 0,
      'has_kidney_cancer' => 0,
      'has_skin_cancer' => 0,
      'has_leukemia' => 0,
      'has_lymphoma' => 0,
      'has_mesothelioma' => 0,
    ];
    
    $cancers = $this->database->select('nfr_cancer_diagnoses', 'c')
      ->fields('c')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAll();
    
    if (empty($cancers)) {
      return $data;
    }
    
    $data['has_cancer_diagnosis'] = 1;
    $data['num_cancer_diagnoses'] = count($cancers);
    
    // Map cancer types to flags
    $cancer_type_map = [
      'lung' => 'has_lung_cancer',
      'colorectal' => 'has_colorectal_cancer',
      'prostate' => 'has_prostate_cancer',
      'breast' => 'has_breast_cancer',
      'bladder' => 'has_bladder_cancer',
      'kidney' => 'has_kidney_cancer',
      'skin' => 'has_skin_cancer',
      'melanoma' => 'has_skin_cancer',
      'leukemia' => 'has_leukemia',
      'lymphoma' => 'has_lymphoma',
      'mesothelioma' => 'has_mesothelioma',
    ];
    
    $earliest_year = NULL;
    foreach ($cancers as $cancer) {
      // Set type flags
      $cancer_type_lower = strtolower($cancer->cancer_type);
      foreach ($cancer_type_map as $keyword => $field) {
        if (stripos($cancer_type_lower, $keyword) !== FALSE) {
          $data[$field] = 1;
        }
      }
      
      // Track earliest diagnosis
      if (!empty($cancer->diagnosis_year)) {
        if ($earliest_year === NULL || $cancer->diagnosis_year < $earliest_year) {
          $earliest_year = $cancer->diagnosis_year;
          $data['earliest_cancer_type'] = $cancer->cancer_type;
        }
      }
    }
    
    // Calculate age at first diagnosis if we have DOB
    if ($earliest_year) {
      $profile = $this->database->select('nfr_user_profile', 'p')
        ->fields('p', ['date_of_birth'])
        ->condition('uid', $uid)
        ->execute()
        ->fetchField();
      
      if ($profile) {
        $birth_year = (int) date('Y', strtotime($profile));
        $data['age_first_cancer_diagnosis'] = $earliest_year - $birth_year;
      }
    }
    
    return $data;
  }

  /**
   * Get family cancer history data.
   */
  protected function getFamilyCancerData(int $uid): array {
    $family = $this->database->select('nfr_family_cancer_history', 'f')
      ->fields('f')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAll();
    
    $data = [
      'family_cancer_count' => count($family),
      'family_cancer_parents' => 0,
      'family_cancer_siblings' => 0,
    ];
    
    foreach ($family as $member) {
      $rel = strtolower($member->relationship ?? '');
      if (in_array($rel, ['mother', 'father', 'parent'])) {
        $data['family_cancer_parents']++;
      }
      elseif (in_array($rel, ['sister', 'brother', 'sibling'])) {
        $data['family_cancer_siblings']++;
      }
    }
    
    return $data;
  }

  /**
   * Calculate derived metrics (BMI, averages, etc).
   */
  protected function calculateDerivedMetrics(array $data): array {
    // Calculate BMI
    if (!empty($data['height_inches']) && !empty($data['weight_pounds'])) {
      $data['bmi'] = round(($data['weight_pounds'] / ($data['height_inches'] * $data['height_inches'])) * 703, 2);
      
      // Categorize BMI
      if ($data['bmi'] < 18.5) {
        $data['bmi_category'] = 'Underweight';
      }
      elseif ($data['bmi'] < 25) {
        $data['bmi_category'] = 'Normal';
      }
      elseif ($data['bmi'] < 30) {
        $data['bmi_category'] = 'Overweight';
      }
      else {
        $data['bmi_category'] = 'Obese';
      }
    }
    
    // Calculate incident averages per year
    if (!empty($data['total_incident_years']) && $data['total_incident_years'] > 0) {
      $data['avg_structure_fires_per_year'] = round($data['total_structure_fires'] / $data['total_incident_years'], 2);
      $data['avg_vehicle_fires_per_year'] = round($data['total_vehicle_fires'] / $data['total_incident_years'], 2);
      $data['avg_wildland_fires_per_year'] = round($data['total_wildland_fires'] / $data['total_incident_years'], 2);
      $data['avg_hazmat_per_year'] = round($data['total_hazmat_incidents'] / $data['total_incident_years'], 2);
      $data['avg_training_fires_per_year'] = round($data['total_training_fires'] / $data['total_incident_years'], 2);
      $data['avg_all_incidents_per_year'] = round($data['total_all_incidents'] / $data['total_incident_years'], 2);
    }
    
    // Calculate years since retirement if applicable
    if (!$data['currently_active'] && !empty($data['year_first_firefighter']) && !empty($data['total_years_service'])) {
      $estimated_retirement_year = $data['year_first_firefighter'] + (int) $data['total_years_service'];
      $data['years_since_retirement'] = date('Y') - $estimated_retirement_year;
    }
    
    // Calculate years of service before first cancer
    if (!empty($data['age_first_cancer_diagnosis']) && !empty($data['age_at_enrollment']) && !empty($data['year_first_firefighter'])) {
      $cancer_year = (int) date('Y') - ($data['age_at_enrollment'] - $data['age_first_cancer_diagnosis']);
      $data['years_service_before_first_cancer'] = round($cancer_year - $data['year_first_firefighter'], 2);
    }
    
    return $data;
  }

  /**
   * Calculate data quality score (0-100).
   */
  protected function calculateDataQualityScore(array $data): int {
    $score = 0;
    $max_score = 0;
    
    // Critical fields (10 points each)
    $critical_fields = ['participant_id', 'date_of_birth', 'sex', 'education_level', 'total_fire_departments'];
    foreach ($critical_fields as $field) {
      $max_score += 10;
      if (!empty($data[$field])) {
        $score += 10;
      }
    }
    
    // Important fields (5 points each)
    $important_fields = ['height_inches', 'weight_pounds', 'smoking_status', 'total_years_service', 'responded_to_incidents'];
    foreach ($important_fields as $field) {
      $max_score += 5;
      if (!empty($data[$field]) || $data[$field] === 0) {
        $score += 5;
      }
    }
    
    // Exposure data (15 points)
    $max_score += 15;
    if (!empty($data['total_all_incidents'])) {
      $score += 15;
      $data['has_complete_incident_data'] = 1;
    }
    
    // Work history (10 points)
    $max_score += 10;
    if (!empty($data['total_fire_departments']) && !empty($data['total_years_service'])) {
      $score += 10;
      $data['has_complete_work_history'] = 1;
    }
    
    // Health data (10 points)
    $max_score += 10;
    if (isset($data['has_cancer_diagnosis'])) {
      $score += 10;
      $data['has_complete_health_data'] = 1;
    }
    
    // Calculate percentage
    $percentage = $max_score > 0 ? round(($score / $max_score) * 100) : 0;
    $data['data_completeness_percentage'] = $percentage;
    
    return (int) min(100, $percentage);
  }

  /**
   * Get rank weight for determining highest rank.
   */
  protected function getRankWeight(string $title): int {
    $title_lower = strtolower($title);
    
    if (stripos($title_lower, 'chief') !== FALSE) {
      return 5;
    }
    if (stripos($title_lower, 'captain') !== FALSE) {
      return 4;
    }
    if (stripos($title_lower, 'lieutenant') !== FALSE) {
      return 3;
    }
    if (stripos($title_lower, 'engineer') !== FALSE) {
      return 2;
    }
    
    return 1; // Firefighter or other
  }

}
