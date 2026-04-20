<?php

declare(strict_types=1);

namespace Drupal\nfr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller for NFR enrollment pages.
 * 
 * Note: User registration is handled by Drupal's standard user registration
 * at /user/register with NFR-specific profile fields added.
 * Consent form is handled by NFRConsentForm.
 * User profile form is handled by NFRUserProfileForm.
 * Enrollment questionnaire is handled by NFRQuestionnaireForm.
 * Review & submit is handled by NFRReviewSubmitForm.
 */
class NFREnrollmentController extends ControllerBase {

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
   * Welcome page for authenticated users.
   *
   * @return array
   *   Render array.
   */
  public function welcome(): array {
    $uid = (int) $this->currentUser()->id();
    
    // Get first name from NFR profile if available
    $profile = $this->database->select('nfr_user_profile', 'p')
      ->fields('p', ['first_name'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    
    $first_name = $profile['first_name'] ?? $this->currentUser()->getDisplayName();
    
    // Check enrollment status
    $enrollment_status = $this->getEnrollmentStatus($uid);
    
    return [
      '#theme' => 'nfr_enrollment_page',
      '#page_id' => 'welcome',
      '#content' => [
        '#markup' => $this->buildWelcomeContent($first_name, $enrollment_status),
      ],
      '#attached' => [
        'library' => ['nfr/enrollment'],
      ],
    ];
  }

  /**
   * Get enrollment status for user.
   */
  private function getEnrollmentStatus(int $uid): array {
    $status = [
      'consent' => false,
      'profile' => false,
      'questionnaire' => false,
      'complete' => false,
    ];

    // Check consent
    $consent = $this->database->select('nfr_consent', 'c')
      ->fields('c', ['consent_timestamp'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();
    $status['consent'] = (bool) $consent;

    // Check profile
    $profile = $this->database->select('nfr_user_profile', 'p')
      ->fields('p', ['profile_completed'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    $status['profile'] = !empty($profile['profile_completed']);

    // Check questionnaire
    $questionnaire = $this->database->select('nfr_questionnaire', 'q')
      ->fields('q', ['questionnaire_completed'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();
    $status['questionnaire'] = !empty($questionnaire['questionnaire_completed']);

    $status['complete'] = $status['consent'] && $status['profile'] && $status['questionnaire'];

    return $status;
  }

  /**
   * Build welcome page content.
   */
  private function buildWelcomeContent(string $first_name, array $status): string {
    $html = '<div class="container my-5">';
    
    // Header
    $html .= '<div class="card card-forseti mb-4">';
    $html .= '<div class="card-body text-center py-5">';
    $html .= '<h1 class="display-5 mb-3">' . $this->t('Welcome to the National Firefighter Registry, @name!', ['@name' => htmlspecialchars($first_name)]) . '</h1>';
    $html .= '<p class="lead text-muted-light">' . $this->t('Thank you for your commitment to advancing cancer research for firefighters.') . '</p>';
    $html .= '</div></div>';

    // Check if already enrolled
    if ($status['complete']) {
      $html .= '<div class="card card-forseti">';
      $html .= '<div class="card-body text-center py-5">';
      $html .= '<div class="display-1 text-success mb-3">✓</div>';
      $html .= '<h2 class="mb-3">' . $this->t('You\'re All Set!') . '</h2>';
      $html .= '<p class="lead mb-4">' . $this->t('You have already completed your enrollment in the National Firefighter Registry.') . '</p>';
      $html .= '<a href="/nfr/my-dashboard" class="btn btn-cyan btn-lg">' . $this->t('Go to My Dashboard') . '</a>';
      $html .= '</div></div>';
    }
    else {
      // Show enrollment process
      $html .= '<div class="card card-forseti mb-4">';
      $html .= '<div class="card-body">';
      $html .= '<h2 class="h4 mb-3">' . $this->t('Before Proceeding') . '</h2>';
      $html .= '<p class="text-muted-light">' . $this->t('To enroll in the NFR, you will need to complete the (1) consent form, (2) user profile, and (3) questionnaire. If needed, you can log out at any point in this process.') . '</p>';
      $html .= '</div></div>';

      $html .= '<div class="row g-4">';
      
      // Step 1: Consent
      $consent_status = $status['consent'] ? 'complete' : 'pending';
      $html .= '<div class="col-md-4">';
      $html .= '<div class="card card-forseti h-100">';
      $html .= '<div class="card-body text-center">';
      $html .= '<div class="display-4 mb-3 ' . ($status['consent'] ? 'text-success' : 'text-primary') . '">' . ($status['consent'] ? '✓' : '1') . '</div>';
      $html .= '<h3 class="h5 mb-3">' . $this->t('Informed Consent') . '</h3>';
      $html .= '<p class="text-muted-light mb-4">' . $this->t('Review and sign the informed consent form to participate in the NFR research study.') . '</p>';
      if (!$status['consent']) {
        $html .= '<a href="/nfr/consent" class="btn btn-cyan">' . $this->t('Start Consent Form') . '</a>';
      } else {
        $html .= '<div class="badge bg-success p-2">✓ Complete</div>';
      }
      $html .= '</div></div></div>';
      
      // Step 2: Profile
      $profile_status = $status['profile'] ? 'complete' : ($status['consent'] ? 'pending' : 'locked');
      $html .= '<div class="col-md-4">';
      $html .= '<div class="card card-forseti h-100 ' . (!$status['consent'] ? 'opacity-50' : '') . '">';
      $html .= '<div class="card-body text-center">';
      $html .= '<div class="display-4 mb-3 ' . ($status['profile'] ? 'text-success' : 'text-primary') . '">' . ($status['profile'] ? '✓' : '2') . '</div>';
      $html .= '<h3 class="h5 mb-3">' . $this->t('User Profile') . '</h3>';
      $html .= '<p class="text-muted-light mb-4">' . $this->t('Provide your demographic information, contact details, and current fire department information.') . '</p>';
      if ($status['consent'] && !$status['profile']) {
        $html .= '<a href="/nfr/profile" class="btn btn-cyan">' . $this->t('Complete Profile') . '</a>';
      } elseif ($status['profile']) {
        $html .= '<div class="badge bg-success p-2">✓ Complete</div>';
      } else {
        $html .= '<div class="text-muted">Complete step 1 first</div>';
      }
      $html .= '</div></div></div>';
      
      // Step 3: Questionnaire
      $questionnaire_status = $status['questionnaire'] ? 'complete' : ($status['profile'] ? 'pending' : 'locked');
      $html .= '<div class="col-md-4">';
      $html .= '<div class="card card-forseti h-100 ' . (!$status['profile'] ? 'opacity-50' : '') . '">';
      $html .= '<div class="card-body text-center">';
      $html .= '<div class="display-4 mb-3 ' . ($status['questionnaire'] ? 'text-success' : 'text-primary') . '">' . ($status['questionnaire'] ? '✓' : '3') . '</div>';
      $html .= '<h3 class="h5 mb-3">' . $this->t('Enrollment Questionnaire') . '</h3>';
      $html .= '<p class="text-muted-light mb-4">' . $this->t('Complete a comprehensive health and occupational history questionnaire (approximately 30 minutes).') . '</p>';
      if ($status['profile'] && !$status['questionnaire']) {
        $html .= '<a href="/nfr/questionnaire" class="btn btn-cyan">' . $this->t('Start Questionnaire') . '</a>';
      } elseif ($status['questionnaire']) {
        $html .= '<div class="badge bg-success p-2">✓ Complete</div>';
      } else {
        $html .= '<div class="text-muted">Complete steps 1 & 2 first</div>';
      }
      $html .= '</div></div></div>';

      $html .= '</div>'; // .row

      // Progress summary
      $completed_steps = ($status['consent'] ? 1 : 0) + ($status['profile'] ? 1 : 0) + ($status['questionnaire'] ? 1 : 0);
      $html .= '<div class="card card-forseti mt-4">';
      $html .= '<div class="card-body">';
      $html .= '<h3 class="h5 mb-3">' . $this->t('Enrollment Progress') . '</h3>';
      $html .= '<div class="progress" style="height: 30px;">';
      $html .= '<div class="progress-bar bg-success" role="progressbar" style="width: ' . (($completed_steps / 3) * 100) . '%" aria-valuenow="' . $completed_steps . '" aria-valuemin="0" aria-valuemax="3">';
      $html .= $completed_steps . ' of 3 steps complete';
      $html .= '</div></div>';
      $html .= '</div></div>';
    }

    // Additional information
    $html .= '<div class="row g-4 mt-4">';
    $html .= '<div class="col-md-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body">';
    $html .= '<h3 class="h5 mb-3">' . $this->t('Need Help?') . '</h3>';
    $html .= '<div class="list-group list-group-flush">';
    $html .= '<a href="/nfr/faq" class="list-group-item list-group-item-action bg-transparent">' . $this->t('Frequently Asked Questions') . '</a>';
    $html .= '<a href="/nfr/contact" class="list-group-item list-group-item-action bg-transparent">' . $this->t('Contact the NFR Team') . '</a>';
    $html .= '<a href="/user/logout" class="list-group-item list-group-item-action bg-transparent">' . $this->t('Log Out') . '</a>';
    $html .= '</div></div></div></div>';
    
    $html .= '<div class="col-md-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body">';
    $html .= '<h3 class="h5 mb-3">' . $this->t('Privacy & Security') . '</h3>';
    $html .= '<p class="text-muted-light">' . $this->t('Your information is protected under federal law and will only be used for research purposes. You can withdraw from the study at any time.') . '</p>';
    $html .= '</div></div></div>';
    $html .= '</div>';

    $html .= '</div>'; // .container

    return $html;
  }

  /**
   * Enrollment confirmation page.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Render array or redirect.
   */
  public function confirmation(): array|RedirectResponse {
    $uid = $this->currentUser()->id();
    
    // Load participant data
    $profile = $this->database->select('nfr_user_profile', 'p')
      ->fields('p', ['participant_id', 'first_name', 'created'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    if (!$profile) {
      $this->messenger()->addError($this->t('Profile not found. Please complete your enrollment.'));
      return new RedirectResponse(Url::fromRoute('nfr.consent')->toString());
    }

    $participant_id = $profile['participant_id'] ?? 'Pending';
    $first_name = $profile['first_name'] ?? '';
    $email = $this->currentUser()->getEmail();
    $enrollment_date = date('F j, Y', (int) ($profile['created'] ?? time()));

    return [
      '#theme' => 'nfr_enrollment_page',
      '#page_id' => 'confirmation',
      '#content' => [
        '#markup' => $this->buildConfirmationContent($participant_id, $first_name, $email, $enrollment_date),
      ],
      '#attached' => [
        'library' => ['nfr/enrollment'],
      ],
    ];
  }

  /**
   * Build confirmation page content.
   */
  private function buildConfirmationContent(string $participant_id, string $first_name, string $email, string $date): string {
    $dashboard_url = \Drupal\Core\Url::fromRoute('nfr.my_dashboard')->toString();
    
    $html = '<div class="confirmation-page">';
    $html .= '<div class="card card-forseti mb-4">';
    $html .= '<div class="card-body text-center py-5">';
    $html .= '<div class="display-1 text-success mb-3">✓</div>';
    $html .= '<h1 class="display-5 text-white">' . $this->t('Thank You for Joining the National Firefighter Registry!') . '</h1>';
    $html .= '</div></div>';

    $html .= '<div class="row g-4">';
    $html .= '<div class="col-lg-6">';
    
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body text-center">';
    $html .= '<h2 class="h4 mb-3">' . $this->t('Your Participant ID') . '</h2>';
    $html .= '<div class="display-4 text-cyan mb-3">' . htmlspecialchars($participant_id) . '</div>';
    $html .= '<p class="text-muted-light">' . $this->t('Please save this ID for your records') . '</p>';
    $html .= '</div></div>';
    
    $html .= '</div><div class="col-lg-6">';

    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body">';
    $html .= '<h2 class="h4 mb-3">' . $this->t('What Happens Next?') . '</h2>';
    $html .= '<ul class="list-unstyled">';
    $html .= '<li class="mb-2">✓ ' . $this->t('We\'ve sent a confirmation email to <strong>@email</strong>', ['@email' => htmlspecialchars($email)]) . '</li>';
    $html .= '<li class="mb-2">✓ ' . $this->t('You can access your dashboard anytime to view or update your information') . '</li>';
    $html .= '<li class="mb-2">✓ ' . $this->t('We\'ll contact you annually for follow-up surveys') . '</li>';
    $html .= '<li class="mb-2">✓ ' . $this->t('If you\'re diagnosed with cancer, you can update your profile at any time') . '</li>';
    $html .= '<li class="mb-2">✓ ' . $this->t('Your participation helps protect firefighter health nationwide') . '</li>';
    $html .= '</ul>';
    $html .= '</div></div>';
    
    $html .= '</div></div>';

    $html .= '<div class="text-center my-4">';
    $html .= '<a href="' . $dashboard_url . '" class="btn btn-cyan btn-lg">' . 
      $this->t('Go to My Dashboard') . '</a>';
    $html .= '</div>';

    $html .= '<div class="card card-forseti">';
    $html .= '<div class="card-body">';
    $html .= '<h3 class="h5 mb-3">' . $this->t('Additional Resources') . '</h3>';
    $html .= '<div class="list-group list-group-flush">';
    $html .= '<a href="/nfr/faq" class="list-group-item list-group-item-action bg-transparent">' . $this->t('Frequently Asked Questions') . '</a>';
    $html .= '<a href="/nfr/contact" class="list-group-item list-group-item-action bg-transparent">' . $this->t('Contact the NFR Team') . '</a>';
    $html .= '<a href="/nfr" class="list-group-item list-group-item-action bg-transparent">' . $this->t('Learn More About the NFR') . '</a>';
    $html .= '</div></div></div>';

    $html .= '</div>'; // .container

    return $html;
  }

  /**
   * Redirect /nfr/questionnaire to section 1.
   */
  public function questionnaireRedirect(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('nfr.questionnaire.section1')->toString());
  }

}

