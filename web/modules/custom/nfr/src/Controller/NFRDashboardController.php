<?php

declare(strict_types=1);

namespace Drupal\nfr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller for NFR participant dashboard pages.
 */
class NFRDashboardController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
    );
  }

  /**
   * Participant dashboard.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function myDashboard(): array|RedirectResponse {
    $uid = (int) $this->currentUser()->id();
    
    // Load participant data
    $profile = $this->loadProfileData($uid);
    $consent = $this->loadConsentData($uid);
    $questionnaire = $this->loadQuestionnaireData($uid);
    
    // Check enrollment status
    $is_enrolled = $profile && $consent && !empty($profile['profile_completed']);
    
    if (!$is_enrolled) {
      // Redirect to enrollment if not complete
      $this->messenger()->addWarning($this->t('Please complete your enrollment to access the dashboard.'));
      return new RedirectResponse(
        Url::fromRoute('nfr.consent')->toString()
      );
    }

    $dashboard_content = $this->buildDashboardContent($profile, $consent, $questionnaire);

    return [
      '#theme' => 'nfr_dashboard_page',
      '#page_id' => 'my-dashboard',
      '#content' => [
        '#markup' => $dashboard_content,
      ],
      '#attached' => [
        'library' => ['nfr/participant-dashboard'],
      ],
    ];
  }

  /**
   * View/Edit profile page.
   *
   * @return array
   *   Render array.
   */
  public function myProfile(): array {
    return [
      '#theme' => 'nfr_dashboard_page',
      '#page_id' => 'my-profile',
      '#content' => [
        '#markup' => '<h2>My Profile</h2><p>Placeholder for viewing and editing profile.</p>',
      ],
    ];
  }

  /**
   * Follow-up survey page.
   *
   * @return array
   *   Render array.
   */
  public function followUp(): array {
    return [
      '#theme' => 'nfr_dashboard_page',
      '#page_id' => 'follow-up',
      '#content' => [
        '#markup' => '<h2>Follow-Up Survey</h2><p>Placeholder for longitudinal data collection.</p>',
      ],
    ];
  }

  /**
   * Account settings page.
   *
   * @return array
   *   Render array.
   */
  public function accountSettings(): array {
    return [
      '#theme' => 'nfr_dashboard_page',
      '#page_id' => 'account-settings',
      '#content' => [
        '#markup' => '<h2>Account Settings</h2><p>Placeholder for account preferences.</p>',
      ],
    ];
  }

  /**
   * Load profile data.
   */
  private function loadProfileData(int $uid): ?array {
    return $this->database->select('nfr_user_profile', 'p')
      ->fields('p')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc() ?: NULL;
  }

  /**
   * Load consent data.
   */
  private function loadConsentData(int $uid): ?array {
    return $this->database->select('nfr_consent', 'c')
      ->fields('c')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc() ?: NULL;
  }

  /**
   * Load questionnaire data.
   */
  private function loadQuestionnaireData(int $uid): ?array {
    return $this->database->select('nfr_questionnaire', 'q')
      ->fields('q')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc() ?: NULL;
  }

  /**
   * Build dashboard content.
   */
  private function buildDashboardContent(array $profile, array $consent, ?array $questionnaire): string {
    $first_name = htmlspecialchars($profile['first_name']);
    $participant_id = htmlspecialchars($profile['participant_id'] ?? 'Pending');
    $profile_updated = date('F j, Y', (int) ($profile['updated'] ?? $profile['created']));
    $questionnaire_completed = $questionnaire && !empty($questionnaire['questionnaire_completed']);
    $questionnaire_date = $questionnaire_completed && !empty($questionnaire['questionnaire_completed_date']) 
      ? date('F j, Y', (int) $questionnaire['questionnaire_completed_date']) 
      : 'Not completed';
    $linkage_consent = !empty($consent['consented_to_registry_linkage']) ? 'Yes' : 'No';
    
    // Get total participants count
    $total_participants = (int) $this->database->select('nfr_user_profile', 'p')
      ->condition('profile_completed', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    $html = '<div class="row">';
    
    // Main column
    $html .= '<div class="col-lg-8">';
    
    // Welcome section
    $html .= '<div class="card card-forseti mb-4">';
    $html .= '<div class="card-body">';
    $html .= '<h2 class="h3 text-white">' . $this->t('Welcome back, @name!', ['@name' => $first_name]) . '</h2>';
    $html .= '<p class="lead text-white">' . $this->t('Thank you for being part of the National Firefighter Registry.') . '</p>';
    $html .= '<p class="badge bg-primary fs-6">' . $this->t('Participant ID: @id', ['@id' => $participant_id]) . '</p>';
    $html .= '</div></div>';

    // Status cards grid
    $html .= '<div class="row g-3 mb-4">';
    
    // Profile status card
    $html .= '<div class="col-md-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body">';
    $html .= '<div class="d-flex align-items-center mb-3">';
    $html .= '<span class="fs-2 me-3">👤</span>';
    $html .= '<h5 class="mb-0 text-white">' . $this->t('Profile Status') . '</h5>';
    $html .= '</div>';
    $html .= '<p class="text-success fw-bold">' . $this->t('Profile Complete') . '</p>';
    $html .= '<p class="small text-muted-light">' . $this->t('Last updated: @date', ['@date' => $profile_updated]) . '</p>';
    $html .= '<a href="/nfr/profile" class="btn btn-sm btn-outline-primary">' . $this->t('Update Profile') . '</a>';
    $html .= '</div></div></div>';
    
    // Questionnaire status card
    $html .= '<div class="col-md-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body">';
    $html .= '<div class="d-flex align-items-center mb-3">';
    $html .= '<span class="fs-2 me-3">📋</span>';
    $html .= '<h5 class="mb-0 text-white">' . $this->t('Enrollment Questionnaire') . '</h5>';
    $html .= '</div>';
    if ($questionnaire_completed) {
      $html .= '<p class="text-success fw-bold">' . $this->t('Questionnaire Complete') . '</p>';
      $html .= '<p class="small text-muted-light">' . $this->t('Submitted: @date', ['@date' => $questionnaire_date]) . '</p>';
      $html .= '<a href="/nfr/review" class="btn btn-sm btn-outline-primary">' . $this->t('View Responses') . '</a>';
    }
    else {
      $html .= '<p class="text-warning fw-bold">' . $this->t('Not Completed') . '</p>';
      $html .= '<a href="/nfr/questionnaire" class="btn btn-sm btn-cyan">' . $this->t('Complete Now') . '</a>';
    }
    $html .= '</div></div></div>';
    
    $html .= '</div>'; // End row g-3
    
    // Quick Actions
    $html .= '<div class="card card-forseti mb-4">';
    $html .= '<div class="card-body">';
    $html .= '<h5 class="card-title text-white">' . $this->t('Quick Actions') . '</h5>';
    $html .= '<div class="d-grid gap-2 d-md-flex">';
    $html .= '<a href="/nfr/profile" class="btn btn-cyan">' . $this->t('Update My Profile') . '</a>';
    $html .= '<a href="/nfr/contact" class="btn btn-outline-primary">' . $this->t('Report a Cancer Diagnosis') . '</a>';
    $html .= '<a href="/nfr/contact" class="btn btn-outline-primary">' . $this->t('Contact NFR Team') . '</a>';
    $html .= '</div>';
    $html .= '</div></div>';
    
    // Recent activity
    $html .= '<div class="card card-forseti">';
    $html .= '<div class="card-body">';
    $html .= '<h5 class="card-title text-white">' . $this->t('Recent Activity') . '</h5>';
    $html .= '<div class="list-group list-group-flush">';
    
    if ($questionnaire_completed) {
      $html .= '<div class="list-group-item bg-transparent">';
      $html .= '<div class="d-flex align-items-start">';
      $html .= '<span class="text-success me-3 fs-5">✓</span>';
      $html .= '<div>';
      $html .= '<h6 class="mb-1 text-white">' . $this->t('Enrollment Questionnaire Completed') . '</h6>';
      $html .= '<small class="text-muted-light">' . $questionnaire_date . '</small>';
      $html .= '</div></div></div>';
    }
    
    $html .= '<div class="list-group-item bg-transparent">';
    $html .= '<div class="d-flex align-items-start">';
    $html .= '<span class="text-success me-3 fs-5">✓</span>';
    $html .= '<div>';
    $html .= '<h6 class="mb-1">' . $this->t('Profile Created') . '</h6>';
    $html .= '<small class="text-muted-light">' . date('F j, Y', (int) $profile['created']) . '</small>';
    $html .= '</div></div></div>';
    
    $html .= '<div class="list-group-item bg-transparent">';
    $html .= '<div class="d-flex align-items-start">';
    $html .= '<span class="text-success me-3 fs-5">✓</span>';
    $html .= '<div>';
    $html .= '<h6 class="mb-1">' . $this->t('Informed Consent Signed') . '</h6>';
    $html .= '<small class="text-muted-light">' . date('F j, Y', (int) $consent['consent_timestamp']) . '</small>';
    $html .= '</div></div></div>';
    
    $html .= '</div></div></div>'; // End list-group, card-body, card
    
    $html .= '</div>'; // End col-lg-8
    
    // Sidebar column
    $html .= '<div class="col-lg-4">';
    
    // Participation impact
    $html .= '<div class="card card-forseti mb-4">';
    $html .= '<div class="card-body text-center">';
    $html .= '<h5 class="card-title">' . $this->t('Your Impact') . '</h5>';
    $html .= '<p class="display-4 fw-bold text-cyan mb-2">' . number_format($total_participants) . '</p>';
    $html .= '<p class="text-muted-light">' . $this->t('Firefighters in the NFR') . '</p>';
    $html .= '<p class="small">' . $this->t('You are 1 of @count firefighters helping to improve firefighter health and safety.', ['@count' => number_format($total_participants)]) . '</p>';
    $html .= '</div></div>';
    
    // Resources
    $html .= '<div class="card card-forseti mb-4">';
    $html .= '<div class="card-body">';
    $html .= '<h5 class="card-title">' . $this->t('Resources') . '</h5>';
    $html .= '<div class="list-group list-group-flush">';
    $html .= '<a href="/nfr/faq" class="list-group-item list-group-item-action bg-transparent">' . $this->t('Frequently Asked Questions') . '</a>';
    $html .= '<a href="/nfr" class="list-group-item list-group-item-action bg-transparent">' . $this->t('Privacy Policy') . '</a>';
    $html .= '<a href="/nfr" class="list-group-item list-group-item-action bg-transparent">' . $this->t('How Data is Used') . '</a>';
    $html .= '<a href="/nfr/contact" class="list-group-item list-group-item-action bg-transparent">' . $this->t('Contact Us') . '</a>';
    $html .= '</div></div></div>';
    
    // Communication preferences
    $html .= '<div class="card card-forseti">';
    $html .= '<div class="card-body">';
    $html .= '<h5 class="card-title">' . $this->t('Communication Preferences') . '</h5>';
    $html .= '<p class="mb-2"><strong>' . $this->t('Email Notifications:') . '</strong> <span class="badge bg-success">On</span></p>';
    $html .= '<p class="mb-3"><strong>' . $this->t('SMS Notifications:') . '</strong> <span class="badge ' . ($profile['sms_opt_in'] ? 'bg-success' : 'bg-secondary') . '">' . ($profile['sms_opt_in'] ? $this->t('On') : $this->t('Off')) . '</span></p>';
    $html .= '<a href="/user/' . $this->currentUser()->id() . '/edit" class="btn btn-sm btn-outline-primary">' . $this->t('Change Preferences') . '</a>';
    $html .= '</div></div>';
    
    $html .= '</div>'; // End col-lg-4
    $html .= '</div>'; // End row

    return $html;
  }

}
