<?php

namespace Drupal\job_hunter\Form\Subform;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Database\Connection;

/**
 * Manages the Education History sub-section of UserProfileForm.
 *
 * Owns:
 *   - buildFormElements(): populates $form['experience_education']['education_entries']
 *   - buildEducationDisplay(): renders HTML from consolidated profile JSON
 *   - calculateYearsOfExperience(): computes experience years from DB
 */
class EducationHistorySubform {

  use StringTranslationTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Constructs a new EducationHistorySubform.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Builds the education_entries form sub-section.
   *
   * Adds $form['experience_education']['education_entries'] elements.
   * The caller is responsible for ensuring $form['experience_education'] exists
   * and for pre-computing $education_text via formatSectionForTextarea().
   *
   * @param array $form
   *   The parent form array, passed by reference.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param object|null $job_seeker_profile
   *   The job seeker profile object, or NULL if not yet created.
   * @param string $education_text
   *   Pre-formatted education text for the textarea default value.
   */
  public function buildFormElements(array &$form, FormStateInterface $form_state, $job_seeker_profile, string $education_text): void {
    $form['experience_education']['education_entries'] = [
      '#type' => 'details',
      '#title' => $this->t('📚 Education History'),
      '#open' => TRUE,
      '#attributes' => [
        'class' => ['education-entries-display', 'jh-profile__education-entries'],
      ],
    ];

    $education_display = $this->buildEducationDisplay($job_seeker_profile);
    if ($education_display) {
      $form['experience_education']['education_entries']['education_display'] = [
        '#type' => 'markup',
        '#markup' => $education_display,
        '#weight' => -20,
      ];
    }
    $form['experience_education']['education_entries']['info'] = [
      '#markup' => '<p class="description"><em>Edit one entry per line. Save to apply changes.</em></p>',
    ];
    $form['experience_education']['education_entries']['field_education_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Education History'),
      '#description' => $this->t('One item per line. Optional format: Institution | Degree | Year'),
      '#default_value' => $education_text,
      '#rows' => 10,
    ];
  }

  /**
   * Builds an HTML display block for education entries from consolidated JSON.
   *
   * @param object|null $job_seeker_profile
   *   The job seeker profile object, or NULL.
   *
   * @return string
   *   Rendered HTML, or empty string when no data is available.
   */
  public function buildEducationDisplay($job_seeker_profile): string {
    if (!$job_seeker_profile || empty($job_seeker_profile->consolidated_profile_json)) {
      return '';
    }

    $consolidated = json_decode($job_seeker_profile->consolidated_profile_json, TRUE);
    if (!$consolidated || empty($consolidated['education'])) {
      return '';
    }

    $html = '<div class="education-display">';

    foreach ($consolidated['education'] as $edu) {
      $institution = htmlspecialchars($edu['institution'] ?? 'Unknown Institution');
      $degree = htmlspecialchars($edu['degree'] ?? '');
      $abbreviation = $edu['abbreviation'] ?? '';
      $field = htmlspecialchars($edu['field'] ?? '');
      $location = htmlspecialchars($edu['location'] ?? '');
      $start = $edu['start_date'] ?? '';
      $end = $edu['end_date'] ?? '';

      $html .= '<div class="education-entry jh-profile__education-entry">';

      $degree_line = $degree;
      if ($abbreviation) {
        $degree_line .= ' (' . htmlspecialchars($abbreviation) . ')';
      }
      if ($field) {
        $degree_line .= ' in ' . $field;
      }

      $html .= '<h4 class="jh-profile__education-degree">' . $degree_line . '</h4>';
      $html .= '<div class="jh-profile__education-institution">' . $institution . '</div>';

      $meta = [];
      if ($location) {
        $meta[] = $location;
      }
      if ($start && $end) {
        $meta[] = $start . ' – ' . $end;
      }
      elseif ($end) {
        $meta[] = 'Graduated ' . $end;
      }

      if (!empty($meta)) {
        $html .= '<div class="jh-profile__education-meta">' . implode(' | ', $meta) . '</div>';
      }

      $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
  }

  /**
   * Calculates estimated years of experience from the earliest graduation date.
   *
   * @param int|null $job_seeker_id
   *   The job seeker profile ID.
   *
   * @return int
   *   Years of experience, or 0 if not determinable.
   */
  public function calculateYearsOfExperience($job_seeker_id): int {
    if (!$job_seeker_id) {
      return 0;
    }

    $query = $this->database->select('jobhunter_education_history', 'e')
      ->fields('e', ['end_date'])
      ->condition('e.job_seeker_id', $job_seeker_id)
      ->orderBy('e.end_date', 'ASC')
      ->range(0, 1);

    $earliest_graduation = $query->execute()->fetchField();

    if (!$earliest_graduation) {
      return 0;
    }

    $graduation_year = null;
    if (preg_match('/\b(19|20)\d{2}\b/', $earliest_graduation, $matches)) {
      $graduation_year = (int) $matches[0];
    }

    if (!$graduation_year) {
      return 0;
    }

    $current_year = (int) date('Y');
    return max(0, $current_year - $graduation_year);
  }

}
