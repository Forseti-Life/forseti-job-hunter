<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\Entity\User;

/**
 * Service for user profile validation and completeness calculation.
 */
class UserProfileService {
  
  use StringTranslationTrait;

  /**
   * Field weights for completeness calculation.
   *
   * @var array
   */
  const FIELD_WEIGHTS = [
    'field_resume_file' => 20,
    'field_work_authorization' => 15,
    'field_professional_summary' => 10,
    'field_skills_summary' => 10,
    'field_experience_years' => 8,
    'field_education_level' => 8,
    'field_remote_preference' => 5,
    'field_linkedin_url' => 5,
    'field_salary_expectation_min' => 5,
    'field_available_start_date' => 5,
    'field_portfolio_url' => 4,
    'field_github_url' => 3,
    'field_certifications' => 2,
  ];

  /**
   * Calculates user profile completeness percentage.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   *
   * @return int
   *   Profile completeness percentage (0-100).
   */
  public function calculateProfileCompleteness(User $user) {
    // Get data from jobhunter_job_seeker table
    $jobSeekerService = \Drupal::service('job_hunter.job_seeker_service');
    $jobSeekerData = $jobSeekerService->loadByUserId($user->id());
    
    if (!$jobSeekerData) {
      return 0;
    }
    
    $completed_weight = 0;
    $total_weight = array_sum(self::FIELD_WEIGHTS);

    foreach (self::FIELD_WEIGHTS as $field_name => $weight) {
      if ($this->isJobSeekerFieldCompleted($jobSeekerData, $field_name)) {
        $completed_weight += $weight;
      }
    }

    return round(($completed_weight / $total_weight) * 100);
  }

  /**
   * Checks if a specific field is completed for a user.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   * @param string $field_name
   *   The field name to check.
   *
   * @return bool
   *   TRUE if the field is completed, FALSE otherwise.
   */
  public function isFieldCompleted(User $user, $field_name) {
    if (!$user->hasField($field_name)) {
      return FALSE;
    }

    $field_value = $user->get($field_name);
    
    if ($field_value->isEmpty()) {
      return FALSE;
    }

    // Special handling for different field types
    if ($field_name === 'field_resume_file') {
      // File field - check if file exists
      return !$field_value->isEmpty();
    } elseif (in_array($field_name, ['field_portfolio_url', 'field_linkedin_url', 'field_github_url'])) {
      // URL fields - check if URI is valid
      $uri = $field_value->uri;
      return !empty($uri) && filter_var($uri, FILTER_VALIDATE_URL);
    } else {
      // Regular fields - check if value exists and is not empty
      $value = $field_value->value;
      return !empty($value);
    }
  }

  /**
   * Gets missing required fields for profile recommendations.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   * @param int $limit
   *   Maximum number of recommendations to return.
   *
   * @return array
   *   Array of missing field recommendations.
   */
  public function getMissingFieldRecommendations(User $user, $limit = 5) {
    // Use the new profile-based method to align with field entity type migration
    return $this->getMissingFieldRecommendationsFromProfile($user, $limit);
  }

  /**
   * Updates profile completeness field for a user.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   * @param bool $save
   *   Whether to save the user entity after updating.
   *
   * @return int
   *   The calculated completeness percentage.
   */
  public function updateProfileCompleteness(User $user, $save = TRUE) {
    // Use the new profile-based method to align with field entity type migration
    return $this->updateProfileCompletenessFromProfile($user, $save);
  }

  /**
   * Gets profile completeness status information.
   *
   * @param int $completeness
   *   The completeness percentage.
   *
   * @return array
   *   Array with status information including class, message, and level.
   */
  public function getCompletenessStatus($completeness) {
    if ($completeness >= 70) {
      return [
        'class' => 'complete',
        'level' => 'high',
        'message' => $this->t('Profile Complete'),
        'description' => $this->t('Your profile is ready for job applications.'),
      ];
    } elseif ($completeness >= 40) {
      return [
        'class' => 'partial',
        'level' => 'medium',
        'message' => $this->t('Almost There'),
        'description' => $this->t('Complete a few more fields to reach 70%.'),
      ];
    } else {
      return [
        'class' => 'incomplete',
        'level' => 'low',
        'message' => $this->t('Getting Started'),
        'description' => $this->t('Add more information to improve your profile.'),
      ];
    }
  }

  /**
   * Validates profile data for job application readiness.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   *
   * @return array
   *   Validation result with status and messages.
   */
  public function validateForJobApplication(User $user) {
    // Use profile-based validation to align with field entity type migration
    return $this->validateForJobApplicationFromProfile($user);
  }

  /**
   * Validates user profile for job application using profile entity data.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   *
   * @return array
   *   Validation results with ready, errors, warnings, and recommendations.
   */
  public function validateForJobApplicationFromProfile(User $user) {
    $completeness = $this->calculateProfileCompleteness($user);
    $errors = [];
    $warnings = [];
    $recommendations = [];

    // Critical Requirements (Blocking - cannot apply without these)
    if (!$this->isFieldCompleted($user, 'field_resume_file')) {
      $errors[] = $this->t('Resume upload is required - employers need to see your qualifications.');
    }

    if (!$this->isFieldCompleted($user, 'field_work_authorization')) {
      $errors[] = $this->t('Work authorization status is required - employers must verify eligibility.');
    }

    // Contact Information Validation (Critical for employer contact)
    $email = $user->getEmail();
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = $this->t('Valid email address is required for application responses.');
    }

    // Enhanced Data Quality Checks
    if ($this->isFieldCompleted($user, 'field_salary_expectation_min') && 
        $user->hasField('field_salary_expectation_max') && 
        !$user->get('field_salary_expectation_max')->isEmpty()) {
      $min_salary = (int)$user->get('field_salary_expectation_min')->value;
      $max_salary = (int)$user->get('field_salary_expectation_max')->value;
      
      if ($min_salary >= $max_salary) {
        $errors[] = $this->t('Minimum salary must be less than maximum salary.');
      }
      
      if ($min_salary < 20000 || $max_salary > 500000) {
        $warnings[] = $this->t('Salary expectations seem unusual - verify amounts are accurate.');
      }
    }

    // Professional Presence Validation
    if ($this->isFieldCompleted($user, 'field_linkedin_url')) {
      $linkedin_url = $user->get('field_linkedin_url')->uri ?? '';
      if (!preg_match('/linkedin\.com\/in\//i', $linkedin_url)) {
        $warnings[] = $this->t('LinkedIn URL should be a profile link (linkedin.com/in/yourname).');
      }
    }

    if ($this->isFieldCompleted($user, 'field_github_url')) {
      $github_url = $user->get('field_github_url')->uri ?? '';
      if (!preg_match('/github\.com\//i', $github_url)) {
        $warnings[] = $this->t('GitHub URL should be a valid GitHub profile or repository link.');
      }
    }

    // Application Success Factors (High-impact recommendations)
    if (!$this->isFieldCompleted($user, 'field_professional_summary')) {
      $recommendations[] = $this->t('Professional summary significantly improves application success rates.');
    }

    if (!$this->isFieldCompleted($user, 'field_skills_summary')) {
      $recommendations[] = $this->t('Skills summary helps employers match you to relevant positions.');
    }

    if (!$this->isFieldCompleted($user, 'field_linkedin_url')) {
      $recommendations[] = $this->t('LinkedIn profile adds credibility and helps employers learn about you.');
    }

    if (!$this->isFieldCompleted($user, 'field_experience_years')) {
      $warnings[] = $this->t('Years of experience helps employers assess your career level.');
    }

    if (!$this->isFieldCompleted($user, 'field_available_start_date')) {
      $warnings[] = $this->t('Start date availability is commonly requested in applications.');
    }

    // Completeness-based Validation
    if ($completeness < 50) {
      $errors[] = $this->t('Profile must be at least 50% complete for reliable application submissions.');
    } elseif ($completeness < 70) {
      $warnings[] = $this->t('Profile completeness below 70% significantly reduces application success rate.');
    }

    // Application Readiness Score
    $readiness_score = $this->calculateApplicationReadinessScore($user, $completeness, $errors, $warnings);

    return [
      'ready' => empty($errors),
      'completeness' => $completeness,
      'readiness_score' => $readiness_score,
      'errors' => $errors,
      'warnings' => $warnings,
      'recommendations' => $recommendations,
    ];
  }

  /**
   * Gets profile statistics for a user.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   *
   * @return array
   *   Array of profile statistics.
   */
  public function getProfileStats(User $user) {
    $stats = [];
    
    // Basic completeness
    $stats['completeness'] = $this->calculateProfileCompleteness($user);
    
    // Field counts
    $total_fields = count(self::FIELD_WEIGHTS);
    $completed_fields = 0;
    
    foreach (array_keys(self::FIELD_WEIGHTS) as $field_name) {
      if ($this->isFieldCompleted($user, $field_name)) {
        $completed_fields++;
      }
    }
    
    $stats['completed_fields'] = $completed_fields;
    $stats['total_fields'] = $total_fields;
    
    // Last update
    $stats['last_updated'] = NULL;
    if ($user->hasField('field_last_profile_update') && !$user->get('field_last_profile_update')->isEmpty()) {
      $stats['last_updated'] = $user->get('field_last_profile_update')->value;
    }
    
    // Application readiness
    $validation = $this->validateForJobApplication($user);
    $stats['ready_for_applications'] = $validation['ready'];
    $stats['validation'] = $validation;
    
    return $stats;
  }

  /**
   * Checks if a job seeker field is completed.
   *
   * @param object $jobSeekerData
   *   The job seeker data from database.
   * @param string $field_name
   *   The field name to check.
   *
   * @return bool
   *   TRUE if the field is completed, FALSE otherwise.
   */
  protected function isJobSeekerFieldCompleted($jobSeekerData, $field_name) {
    $consolidated = [];
    if (!empty($jobSeekerData->consolidated_profile_json)) {
      $decoded = json_decode((string) $jobSeekerData->consolidated_profile_json, TRUE);
      $consolidated = is_array($decoded) ? $decoded : [];
    }

    $contact = is_array($consolidated['contact_info'] ?? NULL) ? $consolidated['contact_info'] : [];
    $websites = is_array($contact['websites'] ?? NULL) ? $contact['websites'] : [];
    $preferences = is_array($consolidated['job_search_preferences'] ?? NULL) ? $consolidated['job_search_preferences'] : [];
    $education = is_array($consolidated['education'] ?? NULL) ? $consolidated['education'] : [];
    $technical_expertise = $consolidated['technical_expertise'] ?? [];
    $executive_profile_text = $this->valueToSearchableText($consolidated['executive_profile'] ?? '');

    $website_urls = [
      'linkedin' => '',
      'github' => '',
      'portfolio' => '',
      'personal' => '',
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
      if ($type !== '' && isset($website_urls[$type]) && $website_urls[$type] === '') {
        $website_urls[$type] = $url;
      }
      if ($website_urls['linkedin'] === '' && stripos($url, 'linkedin.com') !== FALSE) {
        $website_urls['linkedin'] = $url;
      }
      if ($website_urls['github'] === '' && stripos($url, 'github.com') !== FALSE) {
        $website_urls['github'] = $url;
      }
    }

    $technical_skills_present = FALSE;
    if (is_array($technical_expertise)) {
      foreach ($technical_expertise as $entry) {
        if (is_array($entry) && (!empty($entry['skills']) || !empty($entry['name']) || !empty($entry['category']))) {
          $technical_skills_present = TRUE;
          break;
        }
      }
    }

    switch ($field_name) {
      case 'field_resume_file':
        if (!empty($jobSeekerData->resume_node_id)) {
          return TRUE;
        }
        if (!empty($jobSeekerData->id)) {
          $resume_count = (int) \Drupal::database()->select('jobhunter_job_seeker_resumes', 'r')
            ->condition('r.job_seeker_id', (int) $jobSeekerData->id)
            ->countQuery()
            ->execute()
            ->fetchField();
          return $resume_count > 0;
        }
        return FALSE;

      case 'field_work_authorization':
        return !empty($jobSeekerData->age_18_or_older);

      case 'field_professional_summary':
        return !empty($jobSeekerData->professional_summary)
          || !empty($consolidated['executive_profile'])
          || !empty($contact['headline']);

      case 'field_skills_summary':
        return !empty($jobSeekerData->skills) || $technical_skills_present;

      case 'field_experience_years':
        return !empty($jobSeekerData->experience_years)
          || preg_match('/\b\d+\+?\s*years\b/i', $executive_profile_text) === 1
          || !empty($consolidated['professional_experience'])
          || !empty($consolidated['early_career']['positions']);

      case 'field_education_level':
        return !empty($jobSeekerData->education_level)
          || !empty($education)
          || !empty($contact['credentials']);

      case 'field_remote_preference':
        return !empty($jobSeekerData->remote_preference) || !empty($preferences['remote_preference']);

      case 'field_linkedin_url':
        return !empty($jobSeekerData->linkedin_url)
          || !empty($contact['linkedin'])
          || !empty($website_urls['linkedin']);

      case 'field_salary_expectation_min':
        return !empty($jobSeekerData->salary_min)
          || !empty($preferences['salary_expectation_min'])
          || !empty($preferences['salary_expectation']);

      case 'field_available_start_date':
        return !empty($jobSeekerData->available_start_date)
          || !empty($preferences['available_start_date'])
          || !empty($preferences['available_start']);

      case 'field_portfolio_url':
        return !empty($jobSeekerData->portfolio_url)
          || !empty($contact['portfolio'])
          || !empty($website_urls['portfolio'])
          || !empty($website_urls['personal']);

      case 'field_github_url':
        return !empty($jobSeekerData->github_url)
          || !empty($contact['github'])
          || !empty($website_urls['github']);

      case 'field_certifications':
        return !empty($jobSeekerData->certifications) || !empty($contact['credentials']);
    }

    return FALSE;
  }

  /**
   * Convert a mixed profile value into searchable plain text.
   */
  protected function valueToSearchableText($value): string {
    if (is_string($value)) {
      return trim($value);
    }

    if (is_numeric($value) || is_bool($value)) {
      return (string) $value;
    }

    if (is_object($value)) {
      if (method_exists($value, '__toString')) {
        return trim((string) $value);
      }
      $value = get_object_vars($value);
    }

    if (!is_array($value)) {
      return '';
    }

    $parts = [];
    array_walk_recursive($value, static function ($item) use (&$parts): void {
      if (is_string($item)) {
        $item = trim($item);
        if ($item !== '') {
          $parts[] = $item;
        }
        return;
      }

      if (is_numeric($item) || is_bool($item)) {
        $parts[] = (string) $item;
      }
    });

    return implode(' ', $parts);
  }

  /**
   * Updates profile completeness for job application readiness.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   * @param bool $save
   *   Whether to save the user entity.
   *
   * @return int
   *   Updated completeness percentage.
   */
  protected function updateProfileCompletenessFromProfile(User $user, $save = TRUE) {
    $completeness = $this->calculateProfileCompleteness($user);
    
    // Store in field if available
    if ($user->hasField('field_profile_completeness')) {
      $user->set('field_profile_completeness', $completeness);
    }
    
    if ($save) {
      $user->save();
    }
    
    return $completeness;
  }

  /**
   * Gets missing field recommendations for profile.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   * @param int $limit
   *   Maximum recommendations to return.
   *
   * @return array
   *   Array of missing field names with weights.
   */
  protected function getMissingFieldRecommendationsFromProfile(User $user, $limit = 5) {
    $recommendations = [];
    
    foreach (self::FIELD_WEIGHTS as $field_name => $weight) {
      if (!$this->isFieldCompleted($user, $field_name)) {
        $recommendations[] = [
          'field' => $field_name,
          'weight' => $weight,
          'impact' => $this->getFieldImpactDescription($field_name),
        ];
      }
    }
    
    // Sort by weight descending
    usort($recommendations, function($a, $b) {
      return $b['weight'] <=> $a['weight'];
    });
    
    return array_slice($recommendations, 0, $limit);
  }

  /**
   * Gets description of field impact on application success.
   *
   * @param string $field_name
   *   The field name.
   *
   * @return string
   *   Impact description.
   */
  protected function getFieldImpactDescription($field_name) {
    $descriptions = [
      'field_resume_file' => 'Resume is required by all employers',
      'field_work_authorization' => 'Work authorization status is critical for hiring',
      'field_professional_summary' => 'Summary helps employers quickly understand your value',
      'field_skills_summary' => 'Skills improve keyword matching in applications',
      'field_experience_years' => 'Experience helps employers assess seniority level',
      'field_education_level' => 'Education verification is often required',
      'field_linkedin_url' => 'LinkedIn profile adds credibility and background verification',
      'field_salary_expectation_min' => 'Salary expectations help filter suitable roles',
      'field_available_start_date' => 'Start date is commonly requested',
      'field_portfolio_url' => 'Portfolio demonstrates your work quality',
      'field_github_url' => 'GitHub shows technical contributions and code quality',
      'field_certifications' => 'Certifications validate specialized skills',
      'field_remote_preference' => 'Remote preference reduces irrelevant applications',
    ];
    
    return $descriptions[$field_name] ?? 'Improves profile completeness';
  }

  /**
   * Calculates application readiness score.
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   * @param int $completeness
   *   Profile completeness percentage.
   * @param array $errors
   *   Validation errors.
   * @param array $warnings
   *   Validation warnings.
   *
   * @return int
   *   Readiness score 0-100.
   */
  protected function calculateApplicationReadinessScore(User $user, $completeness, array $errors, array $warnings) {
    // Base score on completeness
    $score = $completeness;
    
    // Penalize for critical errors
    $score -= (count($errors) * 10);
    
    // Minor penalty for warnings
    $score -= (count($warnings) * 3);
    
    // Bonus for critical fields
    if ($this->isFieldCompleted($user, 'field_resume_file')) {
      if ($completeness >= 50) {
        $score += 5;
      }
    }
    
    if ($this->isFieldCompleted($user, 'field_professional_summary') && 
        $this->isFieldCompleted($user, 'field_skills_summary')) {
      $score += 5;
    }
    
    // Ensure score is in valid range
    return max(0, min(100, $score));
  }

  /**
   * Gets job seeker profile (placeholder for potential profile entity).
   *
   * @param \Drupal\user\Entity\User $user
   *   The user entity.
   *
   * @return object|null
   *   Profile object or NULL if not applicable.
   */
  protected function getJobSeekerProfile(User $user) {
    // Currently profiles are stored as user entity fields
    // This method is here for future expansion to profile entities if needed
    return TRUE; // User entity itself is the profile
  }

  /**
   * Checks if a profile field is completed.
   *
   * @param mixed $profile
   *   The profile entity.
   * @param string $field_name
   *   The field name.
   *
   * @return bool
   *   TRUE if field is completed.
   */
  protected function isProfileFieldCompleted($profile, $field_name) {
    // If profile is the user entity
    if ($profile === TRUE) {
      return FALSE; // This shouldn't be called this way
    }
    
    if ($profile instanceof User) {
      return $this->isFieldCompleted($profile, $field_name);
    }
    
    return FALSE;
  }

}
