<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

/**
 * Common functionality for questionnaire section forms.
 */
trait QuestionnaireFormTrait {

  /**
   * Get database connection safely.
   */
  protected function getDatabase() {
    try {
      return $this->database;
    }
    catch (\Throwable $e) {
      // Fallback for AJAX contexts where property might not be initialized
      return \Drupal::database();
    }
  }

  /**
   * Load questionnaire data for a user.
   * Note: This now returns empty array as data is stored in specific columns, not a generic JSON field.
   */
  private function loadData(int $uid): array {
    // Data is now stored in specific database columns and normalized tables
    // Section forms should load data directly from those columns
    // This method is kept for backwards compatibility but returns empty
    return [];
  }

  /**
   * Save questionnaire data for a user.
   * Note: This is deprecated. Data should be saved to specific columns in nfr_questionnaire table.
   */
  private function saveData(int $uid, array $data): void {
    // Data is now stored in specific database columns
    // This method is kept for backwards compatibility but does nothing
    // Section forms should save directly to specific columns
    return;
  }

  /**
   * Build navigation menu for sections.
   */
  private function buildNavigationMenu(int $current_section): array {
    $uid = $this->getCurrentUserId();
    
    // Get completed sections from database
    $database = $this->getDatabase();
    $completed_sections = $this->getCompletedSections($uid, $database);
    
    // Calculate progress percentage based on completed sections
    $progress_percent = (count($completed_sections) / 9) * 100;
    
    $sections = [
      1 => 'Demographics',
      2 => 'Work History',
      3 => 'Exposure Info',
      4 => 'Military Service',
      5 => 'Other Employment',
      6 => 'PPE Practices',
      7 => 'Decontamination',
      8 => 'Health Info',
      9 => 'Lifestyle',
    ];

    // Build process flow stepper
    $stepper_html = '<div class="nfr-process-stepper">';
    $stepper_html .= '<div class="stepper-header">';
    $stepper_html .= '<div class="stepper-title">Enrollment Questionnaire</div>';
    $stepper_html .= '<div class="stepper-progress">Section ' . $current_section . ' of 9 &middot; ' . round($progress_percent) . '% Complete</div>';
    $stepper_html .= '</div>';
    $stepper_html .= '<div class="stepper-steps">';
    
    foreach ($sections as $section_num => $section_name) {
      $step_class = 'stepper-step';
      $is_completed = in_array($section_num, $completed_sections);
      
      if ($is_completed) {
        $step_class .= ' completed';
      }
      elseif ($section_num == $current_section) {
        $step_class .= ' active';
      }
      else {
        $step_class .= ' upcoming';
      }
      
      // Make completed sections and current section clickable
      $is_clickable = ($is_completed || $section_num == $current_section);
      if ($is_clickable) {
        $step_class .= ' clickable';
      }
      
      $stepper_html .= '<div class="' . $step_class . '" data-section="' . $section_num . '">';
      
      if ($is_clickable) {
        $stepper_html .= '<a href="/nfr/questionnaire/section/' . $section_num . '" class="step-link">';
      }
      
      $stepper_html .= '<div class="step-number">';
      if ($is_completed) {
        $stepper_html .= '<svg class="step-check" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
      }
      else {
        $stepper_html .= $section_num;
      }
      $stepper_html .= '</div>';
      $stepper_html .= '<div class="step-label">' . $section_name . '</div>';
      
      if ($is_clickable) {
        $stepper_html .= '</a>';
      }
      
      if ($section_num < 9) {
        $stepper_html .= '<div class="step-connector"></div>';
      }
      $stepper_html .= '</div>';
    }
    
    $stepper_html .= '</div></div>';

    return [
      '#type' => 'markup',
      '#markup' => $stepper_html,
      '#weight' => -100,  // Ensure it renders first
      '#attached' => [
        'library' => [
          'nfr/enrollment',
        ],
      ],
    ];
  }

  /**
   * Get current user ID safely.
   */
  protected function getCurrentUserId(): int {
    try {
      return (int) $this->currentUser->id();
    }
    catch (\Throwable $e) {
      // Fallback for AJAX contexts where property might not be initialized
      return (int) \Drupal::currentUser()->id();
    }
  }

  /**
   * Ensure a questionnaire record exists for the user.
   * Creates initial record with INSERT if it doesn't exist.
   * This prevents UPDATE operations from silently failing.
   *
   * @param int $uid
   *   The user ID.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  protected function ensureQuestionnaireRecordExists(int $uid, $database): void {
    // Check if record exists
    $exists = $database->select('nfr_questionnaire', 'q')
      ->fields('q', ['uid'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();
    
    // Create initial record if it doesn't exist
    if (!$exists) {
      $database->insert('nfr_questionnaire')
        ->fields([
          'uid' => $uid,
          'created' => time(),
        ])
        ->execute();
    }
  }

  /**
   * Mark a specific section as completed.
   *
   * @param int $uid
   *   The user ID.
   * @param int $section_number
   *   The section number (1-9).
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  protected function markSectionComplete(int $uid, int $section_number, $database): void {
    $database->merge('nfr_section_completion')
      ->keys([
        'uid' => $uid,
        'section_number' => $section_number,
      ])
      ->fields([
        'completed' => 1,
        'completed_at' => time(),
        'updated' => time(),
      ])
      ->execute();
    
    // Also update last_section_completed to highest completed section for backward compatibility
    $this->updateLastSectionCompleted($uid, $database);
  }

  /**
   * Update last_section_completed to the highest completed section.
   *
   * @param int $uid
   *   The user ID.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  protected function updateLastSectionCompleted(int $uid, $database): void {
    $highest = $database->select('nfr_section_completion', 'sc')
      ->fields('sc', ['section_number'])
      ->condition('uid', $uid)
      ->condition('completed', 1)
      ->orderBy('section_number', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    
    if ($highest) {
      $database->update('nfr_questionnaire')
        ->fields(['last_section_completed' => $highest])
        ->condition('uid', $uid)
        ->execute();
    }
  }

  /**
   * Get completed section numbers for a user.
   *
   * @param int $uid
   *   The user ID.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   *
   * @return array
   *   Array of completed section numbers.
   */
  protected function getCompletedSections(int $uid, $database): array {
    $completed = $database->select('nfr_section_completion', 'sc')
      ->fields('sc', ['section_number'])
      ->condition('uid', $uid)
      ->condition('completed', 1)
      ->execute()
      ->fetchCol();
    
    return $completed ? array_map('intval', $completed) : [];
  }

}
