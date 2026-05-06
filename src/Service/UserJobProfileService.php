<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\user\UserInterface;
use Drupal\file\FileInterface;

/**
 * Service for managing user job search profiles.
 */
class UserJobProfileService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $fieldManager;

  /**
   * Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $field_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fieldManager = $field_manager;
  }

  /**
   * Calculate user profile completeness percentage.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return int
   *   Percentage 0-100 of profile completion.
   */
  public function getProfileCompleteness(UserInterface $user): int {
    // Required fields for MVP
    $required_fields = [
      'field_resume_file',
      'field_work_authorization',
      'field_available_start_date',
      'field_remote_preference',
    ];

    // Optional but influential fields
    $optional_fields = [
      'field_professional_summary',
      'field_skills_summary',
      'field_keywords_interested',
      'field_salary_expectation_min',
      'field_target_companies',
    ];

    $required_count = 0;
    $optional_count = 0;

    // Check required fields
    foreach ($required_fields as $field_name) {
      if ($user->hasField($field_name) && !$user->get($field_name)->isEmpty()) {
        $required_count++;
      }
    }

    // Check optional fields
    foreach ($optional_fields as $field_name) {
      if ($user->hasField($field_name) && !$user->get($field_name)->isEmpty()) {
        $optional_count++;
      }
    }

    // Calculate: 70% from required + 30% from optional
    $required_percentage = (int) ($required_count / count($required_fields)) * 70;
    $optional_percentage = (int) ($optional_count / count($optional_fields)) * 30;

    return min(100, $required_percentage + $optional_percentage);
  }

  /**
   * Validate user profile for job applications.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return array
   *   Array of validation errors, empty if valid.
   */
  public function validateProfile(UserInterface $user): array {
    $errors = [];

    // Check required fields (guard with hasField to avoid unknown field exceptions)
    if (!$user->hasField('field_resume_file') || $user->get('field_resume_file')->isEmpty()) {
      $errors['resume'] = t('Resume file is required to use the job application system.');
    }

    if (!$user->hasField('field_work_authorization') || $user->get('field_work_authorization')->isEmpty()) {
      $errors['authorization'] = t('Work authorization status is required.');
    }

    if (!$user->hasField('field_available_start_date') || $user->get('field_available_start_date')->isEmpty()) {
      $errors['availability'] = t('Available start date is required.');
    }

    if (!$user->hasField('field_remote_preference') || $user->get('field_remote_preference')->isEmpty()) {
      $errors['remote'] = t('Remote work preference is required.');
    }

    return $errors;
  }

  /**
   * Check if profile meets minimum requirements.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return bool
   *   TRUE if profile is valid and ready for job matching.
   */
  public function isProfileComplete(UserInterface $user): bool {
    $errors = $this->validateProfile($user);
    return empty($errors);
  }

  /**
   * Update profile completeness tracking.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   */
  public function updateProfileCompleteness(UserInterface $user): void {
    $completeness = $this->getProfileCompleteness($user);

    if ($user->hasField('field_profile_completeness')) {
      $user->set('field_profile_completeness', $completeness);
    }

    if ($user->hasField('field_last_profile_update')) {
      $user->set('field_last_profile_update', \Drupal::time()->getRequestTime());
    }

    $user->save();
  }

  /**
   * Get profile summary for display.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return array
   *   Array of profile field summaries.
   */
  public function getProfileSummary(UserInterface $user): array {
    $summary = [];

    $fields = [
      'field_resume_file' => t('Resume'),
      'field_professional_summary' => t('Professional Summary'),
      'field_skills_summary' => t('Skills'),
      'field_work_authorization' => t('Work Authorization'),
      'field_salary_expectation_min' => t('Salary Range'),
      'field_available_start_date' => t('Start Date'),
      'field_remote_preference' => t('Remote Work'),
      'field_keywords_interested' => t('Job Interests'),
      'field_target_companies' => t('Target Companies'),
    ];

    foreach ($fields as $field_name => $label) {
      if ($user->hasField($field_name)) {
        $field_value = $user->get($field_name);
        $is_empty = $field_value->isEmpty();

        $summary[$field_name] = [
          'label' => $label,
          'complete' => !$is_empty,
          'value' => !$is_empty ? $field_value->getValue() : NULL,
        ];
      }
    }

    return $summary;
  }

  /**
   * Get field descriptions for form display.
   *
   * @return array
   *   Array of field descriptions.
   */
  public function getFieldDescriptions(): array {
    return [
      'field_resume_file' => t('Upload your professional resume (PDF or Word document). This will be analyzed and tailored for each job posting.'),
      'field_professional_summary' => t('A brief summary of your professional background and career goals. This helps with job matching.'),
      'field_skills_summary' => t('List your key technical skills and competencies. This is used to match you with relevant positions.'),
      'field_work_authorization' => t('Your right to work, which affects which positions you\'re eligible for.'),
      'field_salary_expectation_min' => t('Your minimum acceptable salary. Leave blank if you\'re flexible.'),
      'field_salary_expectation_max' => t('Your target or maximum expected salary.'),
      'field_available_start_date' => t('When you\'re able to start a new position.'),
      'field_remote_preference' => t('Your preference regarding remote work. This helps filter relevant positions.'),
      'field_relocation_willing' => t('Are you willing to relocate for a position?'),
      'field_keywords_interested' => t('Job titles, technologies, or industries you\'re interested in (comma-separated).'),
      'field_target_companies' => t('Specific companies you\'d like to work for. We\'ll prioritize opportunities there.'),
    ];
  }

}
