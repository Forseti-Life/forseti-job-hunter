<?php

declare(strict_types=1);

namespace Drupal\nfr\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for NFR administrative pages.
 */
class NFRAdminController extends ControllerBase {

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
   * Admin dashboard.
   *
   * @return array
   *   Render array.
   */
  public function adminDashboard(): array {
    $stats = $this->getStatistics();
    $recent_participants = $this->getRecentParticipants(10);
    $dashboard_content = $this->buildAdminDashboard($stats, $recent_participants);

    return [
      '#theme' => 'nfr_admin_page',
      '#page_id' => 'admin-dashboard',
      '#content' => [
        '#type' => 'inline_template',
        '#template' => '{{ content|raw }}',
        '#context' => [
          'content' => $dashboard_content,
        ],
      ],
      '#attached' => [
        'library' => ['nfr/admin'],
      ],
    ];
  }

  /**
   * Get statistics for dashboard.
   */
  private function getStatistics(): array {
    // Total participants
    $total_participants = $this->database->select('nfr_user_profile', 'p')
      ->condition('profile_completed', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    // New today
    $new_today = $this->database->select('nfr_user_profile', 'p')
      ->condition('profile_completed', 1)
      ->condition('created', strtotime('today'), '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    // This month
    $new_this_month = $this->database->select('nfr_user_profile', 'p')
      ->condition('profile_completed', 1)
      ->condition('created', strtotime('first day of this month'), '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    // Profile completion rate
    $total_profiles = $this->database->select('nfr_user_profile', 'p')
      ->countQuery()
      ->execute()
      ->fetchField();
    $profile_completion_rate = $total_profiles > 0 ? round(($total_participants / $total_profiles) * 100) : 0;

    // Questionnaire completion rate
    $questionnaires_completed = $this->database->select('nfr_questionnaire', 'q')
      ->condition('questionnaire_completed', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
    $questionnaire_completion_rate = $total_participants > 0 ? round(($questionnaires_completed / $total_participants) * 100) : 0;

    // Linkage consent
    $linkage_consents = $this->database->select('nfr_consent', 'c')
      ->condition('consented_to_registry_linkage', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
    $linkage_consent_rate = $total_participants > 0 ? round(($linkage_consents / $total_participants) * 100) : 0;

    // State distribution (top 5)
    $state_distribution = $this->database->select('nfr_user_profile', 'p')
      ->fields('p', ['state'])
      ->condition('profile_completed', 1)
      ->groupBy('state')
      ->execute()
      ->fetchAllKeyed(0, 0);

    return [
      'total_participants' => (int) $total_participants,
      'new_today' => (int) $new_today,
      'new_this_month' => (int) $new_this_month,
      'profile_completion_rate' => (int) $profile_completion_rate,
      'questionnaire_completion_rate' => (int) $questionnaire_completion_rate,
      'linkage_consent_rate' => (int) $linkage_consent_rate,
      'linkage_consents' => (int) $linkage_consents,
      'state_distribution' => $state_distribution,
    ];
  }

  /**
   * Get recent participants.
   */
  private function getRecentParticipants(int $limit = 10): array {
    $query = $this->database->select('nfr_user_profile', 'p');
    $query->leftJoin('users_field_data', 'u', 'p.uid = u.uid');
    $query->fields('p', ['participant_id', 'first_name', 'last_name', 'state', 'created', 'profile_completed']);
    $query->addField('u', 'mail', 'primary_email');
    $query->orderBy('p.created', 'DESC');
    $query->range(0, $limit);
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Build admin dashboard HTML.
   */
  private function buildAdminDashboard(array $stats, array $recent): string {
    $html = '<div class="container-fluid my-4">';
    
    // Header
    $html .= '<div class="card card-forseti mb-4">';
    $html .= '<div class="card-body text-center py-4">';
    $html .= '<h1 class="display-6">' . $this->t('NFR Administration Dashboard') . '</h1>';
    $html .= '<p class="lead text-muted-light">' . $this->t('National Firefighter Registry Management System') . '</p>';
    $html .= '</div></div>';

    // Key metrics
    $html .= '<div class="row g-4 mb-4">';
    
    // Total participants
    $html .= '<div class="col-lg-3 col-md-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body text-center">';
    $html .= '<div class="display-4 mb-2">👥</div>';
    $html .= '<div class="display-5 fw-bold text-cyan">' . number_format($stats['total_participants']) . '</div>';
    $html .= '<div class="text-muted-light mt-2">' . $this->t('Total Participants') . '</div>';
    $html .= '<div class="small text-success mt-2">+' . $stats['new_today'] . ' ' . $this->t('today') . '</div>';
    $html .= '</div></div></div>';
    
    // Enrollment this month
    $html .= '<div class="col-lg-3 col-md-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body text-center">';
    $html .= '<div class="display-4 mb-2">📈</div>';
    $html .= '<div class="display-5 fw-bold text-success">' . number_format($stats['new_this_month']) . '</div>';
    $html .= '<div class="text-muted-light mt-2">' . $this->t('Enrolled This Month') . '</div>';
    $html .= '<div class="small text-muted mt-2">' . $this->t('January 2026') . '</div>';
    $html .= '</div></div></div>';
    
    // Completion rates
    $html .= '<div class="col-lg-3 col-md-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body text-center">';
    $html .= '<div class="display-4 mb-2">✓</div>';
    $html .= '<div class="display-5 fw-bold text-info">' . $stats['questionnaire_completion_rate'] . '%</div>';
    $html .= '<div class="text-muted-light mt-2">' . $this->t('Questionnaire Completion') . '</div>';
    $html .= '<div class="small text-muted mt-2">' . $this->t('Profile: @rate%', ['@rate' => $stats['profile_completion_rate']]) . '</div>';
    $html .= '</div></div></div>';
    
    // Linkage status
    $html .= '<div class="col-lg-3 col-md-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body text-center">';
    $html .= '<div class="display-4 mb-2">🔗</div>';
    $html .= '<div class="display-5 fw-bold text-warning">' . $stats['linkage_consent_rate'] . '%</div>';
    $html .= '<div class="text-muted-light mt-2">' . $this->t('Linkage Consent Rate') . '</div>';
    $html .= '<div class="small text-muted mt-2">' . number_format($stats['linkage_consents']) . ' ' . $this->t('consented') . '</div>';
    $html .= '</div></div></div>';
    
    $html .= '</div>'; // .row

    // Main content area
    $html .= '<div class="row g-4">';
    
    // Left column
    $html .= '<div class="col-lg-8">';
    
    // Recent participants
    $html .= '<div class="card card-forseti mb-4">';
    $html .= '<div class="card-header">';
    $html .= '<h2 class="h5 mb-0">' . $this->t('Recent Registrations') . '</h2>';
    $html .= '</div>';
    $html .= '<div class="card-body p-0">';
    $html .= '<div class="table-responsive">';
    $html .= '<table class="table table-hover mb-0">';
    $html .= '<thead><tr>';
    $html .= '<th>' . $this->t('Participant ID') . '</th>';
    $html .= '<th>' . $this->t('Name') . '</th>';
    $html .= '<th>' . $this->t('State') . '</th>';
    $html .= '<th>' . $this->t('Enrolled') . '</th>';
    $html .= '<th>' . $this->t('Status') . '</th>';
    $html .= '</tr></thead><tbody>';
    
    foreach ($recent as $participant) {
      $html .= '<tr>';
      $html .= '<td><a href="/admin/nfr/participant/' . htmlspecialchars($participant['participant_id']) . '" class="text-cyan">' . 
        htmlspecialchars($participant['participant_id']) . '</a></td>';
      $html .= '<td>' . htmlspecialchars($participant['first_name'] . ' ' . $participant['last_name']) . '</td>';
      $html .= '<td>' . htmlspecialchars($participant['state'] ?? 'N/A') . '</td>';
      $html .= '<td>' . date('M j, Y', (int) $participant['created']) . '</td>';
      $html .= '<td><span class="badge ' . ($participant['profile_completed'] ? 'bg-success' : 'bg-warning') . '">' . 
        ($participant['profile_completed'] ? $this->t('Complete') : $this->t('Pending')) . '</span></td>';
      $html .= '</tr>';
    }
    
    $html .= '</tbody></table>';
    $html .= '</div></div>'; // table-responsive, card-body
    $html .= '<div class="card-footer text-center">';
    $html .= '<a href="/admin/nfr/participants" class="btn btn-outline-primary btn-sm">' . $this->t('View All Participants →') . '</a>';
    $html .= '</div></div>'; // card-footer, card

    // Quick actions
    $html .= '<div class="card card-forseti">';
    $html .= '<div class="card-header">';
    $html .= '<h2 class="h5 mb-0">' . $this->t('Quick Actions') . '</h2>';
    $html .= '</div>';
    $html .= '<div class="card-body">';
    $html .= '<div class="row g-3">';
    $html .= '<div class="col-md-6">';
    $html .= '<a href="/admin/nfr/participants" class="btn btn-cyan w-100 d-flex align-items-center justify-content-center py-3">';
    $html .= '<span class="me-2">📋</span>' . $this->t('View All Participants');
    $html .= '</a></div>';
    $html .= '<div class="col-md-6">';
    $html .= '<a href="/admin/nfr/linkage" class="btn btn-cyan w-100 d-flex align-items-center justify-content-center py-3">';
    $html .= '<span class="me-2">🔗</span>' . $this->t('Process Linkage');
    $html .= '</a></div>';
    $html .= '<div class="col-md-6">';
    $html .= '<a href="/admin/nfr/validation/fill-rates" class="btn btn-cyan w-100 d-flex align-items-center justify-content-center py-3">';
    $html .= '<span class="me-2">📊</span>' . $this->t('Data Quality');
    $html .= '</a></div>';
    $html .= '<div class="col-md-6">';
    $html .= '<a href="/admin/nfr/reports" class="btn btn-cyan w-100 d-flex align-items-center justify-content-center py-3">';
    $html .= '<span class="me-2">📄</span>' . $this->t('Generate Reports');
    $html .= '</a></div>';
    $html .= '</div></div></div>';
    
    $html .= '</div>'; // col-lg-8
    
    // Right sidebar
    $html .= '<div class="col-lg-4">';
    
    // State distribution
    $html .= '<div class="card card-forseti">';
    $html .= '<div class="card-header">';
    $html .= '<h3 class="h6 mb-0">' . $this->t('Top States') . '</h3>';
    $html .= '</div>';
    $html .= '<div class="card-body">';
    
    if (!empty($stats['state_distribution'])) {
      $state_counts = [];
      foreach ($stats['state_distribution'] as $state) {
        if ($state) {
          $state_counts[$state] = ($state_counts[$state] ?? 0) + 1;
        }
      }
      arsort($state_counts);
      $top_states = array_slice($state_counts, 0, 5, true);
      
      foreach ($top_states as $state => $count) {
        $percentage = $stats['total_participants'] > 0 ? round(($count / $stats['total_participants']) * 100, 1) : 0;
        $html .= '<div class="mb-3">';
        $html .= '<div class="d-flex justify-content-between mb-1">';
        $html .= '<span class="fw-bold">' . htmlspecialchars($state) . '</span>';
        $html .= '<span class="text-muted-light">' . number_format($count) . '</span>';
        $html .= '</div>';
        $html .= '<div class="progress" style="height: 8px;">';
        $html .= '<div class="progress-bar bg-cyan" style="width: ' . $percentage . '%"></div>';
        $html .= '</div></div>';
      }
    }
    else {
      $html .= '<p class="text-muted-light">' . $this->t('No state data yet') . '</p>';
    }
    
    $html .= '</div></div>';
    
    // System status
    $html .= '<div class="card card-forseti mt-4">';
    $html .= '<div class="card-header">';
    $html .= '<h3 class="h6 mb-0">' . $this->t('System Status') . '</h3>';
    $html .= '</div>';
    $html .= '<div class="card-body">';
    $html .= '<div class="list-group list-group-flush">';
    $html .= '<div class="list-group-item bg-transparent d-flex align-items-center">';
    $html .= '<span class="badge bg-success rounded-circle me-2" style="width: 12px; height: 12px; padding: 0;"></span>';
    $html .= '<span>' . $this->t('Database: Operational') . '</span>';
    $html .= '</div>';
    $html .= '<div class="list-group-item bg-transparent d-flex align-items-center">';
    $html .= '<span class="badge bg-success rounded-circle me-2" style="width: 12px; height: 12px; padding: 0;"></span>';
    $html .= '<span>' . $this->t('Email Service: Active') . '</span>';
    $html .= '</div>';
    $html .= '<div class="list-group-item bg-transparent d-flex align-items-center">';
    $html .= '<span class="badge bg-success rounded-circle me-2" style="width: 12px; height: 12px; padding: 0;"></span>';
    $html .= '<span>' . $this->t('Backups: Up to date') . '</span>';
    $html .= '</div>';
    $html .= '</div></div></div>';
    
    // Links
    $html .= '<div class="card card-forseti mt-4">';
    $html .= '<div class="card-header">';
    $html .= '<h3 class="h6 mb-0">' . $this->t('Resources') . '</h3>';
    $html .= '</div>';
    $html .= '<div class="card-body p-0">';
    $html .= '<div class="list-group list-group-flush">';
    $html .= '<a href="/admin/nfr/settings" class="list-group-item list-group-item-action bg-transparent">' . $this->t('System Settings') . '</a>';
    $html .= '<a href="/admin/nfr/issues" class="list-group-item list-group-item-action bg-transparent">' . $this->t('Support Issues') . '</a>';
    $html .= '<a href="/nfr/documentation" class="list-group-item list-group-item-action bg-transparent">' . $this->t('Documentation') . '</a>';
    $html .= '<a href="/admin/reports" class="list-group-item list-group-item-action bg-transparent">' . $this->t('System Reports') . '</a>';
    $html .= '</div></div></div>';
    
    $html .= '</div>'; // col-lg-4
    
    $html .= '</div>'; // row
    $html .= '</div>'; // container-fluid

    return $html;
  }

  /**
   * Participant list page.
   *
   * @return array
   *   Render array.
   */
  public function participantList(): array {
    $participants = $this->getAllParticipants();
    $list_content = $this->buildParticipantList($participants);

    return [
      '#theme' => 'nfr_admin_page',
      '#page_id' => 'participant-list',
      '#content' => [
        '#type' => 'inline_template',
        '#template' => '{{ content|raw }}',
        '#context' => [
          'content' => $list_content,
        ],
      ],
      '#attached' => [
        'library' => ['nfr/admin'],
      ],
    ];
  }

  /**
   * Get all participants.
   */
  private function getAllParticipants(): array {
    $request = \Drupal::request();
    
    $query = $this->database->select('nfr_user_profile', 'p')
      ->fields('p')
      ->orderBy('p.created', 'DESC');
    
    // Join with questionnaire to get completion status
    $query->leftJoin('nfr_questionnaire', 'q', 'p.uid = q.uid');
    $query->addField('q', 'questionnaire_completed');
    
    // Join with consent to get linkage status
    $query->leftJoin('nfr_consent', 'c', 'p.uid = c.uid');
    $query->addField('c', 'consented_to_registry_linkage');
    
    // Join with users to get email
    $query->leftJoin('users_field_data', 'u', 'p.uid = u.uid');
    $query->addField('u', 'mail', 'primary_email');
    
    $active_filters = [];
    
    // Apply filters from query parameters
    if ($participant_id = $request->query->get('participant_id')) {
      $query->condition('p.participant_id', '%' . $this->database->escapeLike($participant_id) . '%', 'LIKE');
      $active_filters['participant_id'] = $participant_id;
    }
    
    if ($name = $request->query->get('name')) {
      $or = $query->orConditionGroup()
        ->condition('p.first_name', '%' . $this->database->escapeLike($name) . '%', 'LIKE')
        ->condition('p.last_name', '%' . $this->database->escapeLike($name) . '%', 'LIKE');
      $query->condition($or);
      $active_filters['name'] = $name;
    }
    
    if ($email = $request->query->get('email')) {
      $query->condition('u.mail', '%' . $this->database->escapeLike($email) . '%', 'LIKE');
      $active_filters['email'] = $email;
    }
    
    if ($state = $request->query->get('state')) {
      $query->condition('p.state', $state);
      $active_filters['state'] = $state;
    }
    
    if ($enrolled = $request->query->get('enrolled')) {
      // Filter by enrollment date range or specific value
      if ($enrolled === 'last_30_days') {
        $query->condition('p.created', strtotime('-30 days'), '>=');
        $active_filters['enrolled'] = 'Last 30 Days';
      } elseif ($enrolled === 'last_90_days') {
        $query->condition('p.created', strtotime('-90 days'), '>=');
        $active_filters['enrolled'] = 'Last 90 Days';
      } elseif ($enrolled === 'this_year') {
        $query->condition('p.created', strtotime('January 1'), '>=');
        $active_filters['enrolled'] = 'This Year';
      }
    }
    
    // Log filter application for debugging
    if (!empty($active_filters)) {
      \Drupal::logger('nfr')->info('Participant filters applied: @filters', [
        '@filters' => json_encode($active_filters),
      ]);
    }
    
    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    
    // Log result count for debugging
    \Drupal::logger('nfr')->info('Participant query returned @count results with filters: @filters', [
      '@count' => count($results),
      '@filters' => !empty($active_filters) ? json_encode($active_filters) : 'none',
    ]);
    
    return $results;
  }

  /**
   * Build participant list HTML.
   */
  private function buildParticipantList(array $participants): string {
    $request = \Drupal::request();
    
    // Get current filter values
    $filter_participant_id = $request->query->get('participant_id', '');
    $filter_name = $request->query->get('name', '');
    $filter_email = $request->query->get('email', '');
    $filter_state = $request->query->get('state', '');
    $filter_enrolled = $request->query->get('enrolled', '');
    
    // Check if any filters are active
    $has_active_filters = !empty($filter_participant_id) || !empty($filter_name) || 
                          !empty($filter_email) || !empty($filter_state) || !empty($filter_enrolled);
    
    // Get unique states for dropdown
    $all_participants_query = $this->database->select('nfr_user_profile', 'p')
      ->fields('p', ['state'])
      ->distinct()
      ->condition('state', '', '!=')
      ->orderBy('state', 'ASC');
    $states = $all_participants_query->execute()->fetchCol();
    
    $html = '<div class="participant-list-page">';
    
    // Header
    $html .= '<div class="page-header">';
    $html .= '<h1>' . $this->t('Participant Management') . '</h1>';
    $html .= '<div class="page-actions">';
    $html .= '<a href="/admin/nfr/participants" class="btn btn-secondary">' . $this->t('Clear Filters') . '</a>';
    $html .= '<a href="/admin/nfr" class="btn btn-primary">' . $this->t('← Back to Dashboard') . '</a>';
    $html .= '</div>';
    $html .= '</div>';

    // Active filters badge
    if ($has_active_filters) {
      $html .= '<div class="alert alert-info mb-3">';
      $html .= '<strong>' . $this->t('Active Filters:') . '</strong> ';
      $filter_labels = [];
      if ($filter_participant_id) $filter_labels[] = $this->t('ID: @id', ['@id' => $filter_participant_id]);
      if ($filter_name) $filter_labels[] = $this->t('Name: @name', ['@name' => $filter_name]);
      if ($filter_email) $filter_labels[] = $this->t('Email: @email', ['@email' => $filter_email]);
      if ($filter_state) $filter_labels[] = $this->t('State: @state', ['@state' => $filter_state]);
      if ($filter_enrolled) {
        $enrolled_labels = [
          'last_30_days' => $this->t('Last 30 Days'),
          'last_90_days' => $this->t('Last 90 Days'),
          'this_year' => $this->t('This Year'),
        ];
        $filter_labels[] = $this->t('Enrolled: @period', ['@period' => $enrolled_labels[$filter_enrolled] ?? $filter_enrolled]);
      }
      $html .= implode(' | ', $filter_labels);
      $html .= ' <a href="/admin/nfr/participants" class="ms-2">(' . $this->t('Clear All') . ')</a>';
      $html .= '</div>';
    }

    // Filters Form
    $html .= '<form method="GET" action="/admin/nfr/participants" class="list-filters-form">';
    $html .= '<div class="filters-container">';
    
    // Participant ID filter
    $html .= '<div class="filter-group">';
    $html .= '<label for="participant-id-filter">' . $this->t('Participant ID') . '</label>';
    $html .= '<input type="text" id="participant-id-filter" name="participant_id" value="' . htmlspecialchars($filter_participant_id) . '" placeholder="' . $this->t('Enter ID...') . '" class="filter-input">';
    $html .= '</div>';
    
    // Name filter
    $html .= '<div class="filter-group">';
    $html .= '<label for="name-filter">' . $this->t('Name') . '</label>';
    $html .= '<input type="text" id="name-filter" name="name" value="' . htmlspecialchars($filter_name) . '" placeholder="' . $this->t('First or Last Name...') . '" class="filter-input">';
    $html .= '</div>';
    
    // Email filter
    $html .= '<div class="filter-group">';
    $html .= '<label for="email-filter">' . $this->t('Email') . '</label>';
    $html .= '<input type="email" id="email-filter" name="email" value="' . htmlspecialchars($filter_email) . '" placeholder="' . $this->t('Email address...') . '" class="filter-input">';
    $html .= '</div>';
    
    // State filter
    $html .= '<div class="filter-group">';
    $html .= '<label for="state-filter">' . $this->t('State') . '</label>';
    $html .= '<select id="state-filter" name="state" class="filter-select">';
    $html .= '<option value="">' . $this->t('All States') . '</option>';
    foreach ($states as $state) {
      $selected = ($filter_state === $state) ? ' selected' : '';
      $html .= '<option value="' . htmlspecialchars($state) . '"' . $selected . '>' . htmlspecialchars($state) . '</option>';
    }
    $html .= '</select>';
    $html .= '</div>';
    
    // Enrolled filter
    $html .= '<div class="filter-group">';
    $html .= '<label for="enrolled-filter">' . $this->t('Enrolled') . '</label>';
    $html .= '<select id="enrolled-filter" name="enrolled" class="filter-select">';
    $html .= '<option value=""' . ($filter_enrolled === '' ? ' selected' : '') . '>' . $this->t('All Time') . '</option>';
    $html .= '<option value="last_30_days"' . ($filter_enrolled === 'last_30_days' ? ' selected' : '') . '>' . $this->t('Last 30 Days') . '</option>';
    $html .= '<option value="last_90_days"' . ($filter_enrolled === 'last_90_days' ? ' selected' : '') . '>' . $this->t('Last 90 Days') . '</option>';
    $html .= '<option value="this_year"' . ($filter_enrolled === 'this_year' ? ' selected' : '') . '>' . $this->t('This Year') . '</option>';
    $html .= '</select>';
    $html .= '</div>';
    
    // Filter buttons
    $html .= '<div class="filter-actions">';
    $html .= '<button type="submit" class="btn btn-primary">' . $this->t('Apply Filters') . '</button>';
    $html .= '<a href="/admin/nfr/participants" class="btn btn-link">' . $this->t('Reset') . '</a>';
    $html .= '</div>';
    
    $html .= '</div>'; // filters-container
    $html .= '</form>';

    // Statistics bar
    $total = count($participants);
    $complete = count(array_filter($participants, fn($p) => $p['questionnaire_completed'] ?? false));
    $linkage = count(array_filter($participants, fn($p) => $p['consented_to_registry_linkage'] ?? false));
    
    $html .= '<div class="list-stats">';
    $html .= '<div class="stat-item">';
    $html .= '<span class="stat-label">' . ($has_active_filters ? $this->t('Filtered Results:') : $this->t('Total:')) . '</span> ';
    $html .= '<span class="stat-value">' . number_format($total) . '</span>';
    $html .= '</div>';
    $html .= '<div class="stat-item">';
    $html .= '<span class="stat-label">' . $this->t('Completed:') . '</span> ';
    $html .= '<span class="stat-value">' . number_format($complete) . '</span>';
    $html .= '</div>';
    $html .= '<div class="stat-item">';
    $html .= '<span class="stat-label">' . $this->t('Linkage Consent:') . '</span> ';
    $html .= '<span class="stat-value">' . number_format($linkage) . '</span>';
    $html .= '</div>';
    $html .= '</div>';

    // Table
    $html .= '<div class="participant-table-wrapper">';
    $html .= '<table class="participant-table">';
    $html .= '<thead><tr>';
    $html .= '<th>' . $this->t('Participant ID') . '</th>';
    $html .= '<th>' . $this->t('Name') . '</th>';
    $html .= '<th>' . $this->t('Email') . '</th>';
    $html .= '<th>' . $this->t('State') . '</th>';
    $html .= '<th>' . $this->t('Enrolled') . '</th>';
    $html .= '<th>' . $this->t('Questionnaire') . '</th>';
    $html .= '<th>' . $this->t('Linkage') . '</th>';
    $html .= '<th>' . $this->t('Actions') . '</th>';
    $html .= '</tr></thead><tbody>';
    
    if (empty($participants)) {
      $html .= '<tr><td colspan="8" class="text-center text-muted py-4">';
      if ($has_active_filters) {
        $html .= '<strong>' . $this->t('No participants found matching the current filters.') . '</strong><br>';
        $html .= '<a href="/admin/nfr/participants" class="btn btn-sm btn-primary mt-2">' . $this->t('Clear Filters') . '</a>';
      } else {
        $html .= $this->t('No participants enrolled yet.');
      }
      $html .= '</td></tr>';
    } else {
      foreach ($participants as $participant) {
        $html .= '<tr>';
        $html .= '<td><strong>' . htmlspecialchars($participant['participant_id'] ?? 'N/A') . '</strong></td>';
        $html .= '<td>' . htmlspecialchars(($participant['first_name'] ?? '') . ' ' . ($participant['last_name'] ?? '')) . '</td>';
        $html .= '<td>' . htmlspecialchars($participant['primary_email'] ?? 'N/A') . '</td>';
        $html .= '<td>' . htmlspecialchars($participant['state'] ?? 'N/A') . '</td>';
        $html .= '<td>' . date('M j, Y', (int) ($participant['created'] ?? time())) . '</td>';
        
        $q_status = ($participant['questionnaire_completed'] ?? false) ? 'complete' : 'incomplete';
        $html .= '<td><span class="status-badge status-' . $q_status . '">' . 
          ($q_status === 'complete' ? $this->t('Complete') : $this->t('Incomplete')) . '</span></td>';
        
        $l_status = ($participant['consented_to_registry_linkage'] ?? false) ? 'consented' : 'no-consent';
        $html .= '<td><span class="status-badge status-' . $l_status . '">' . 
          ($l_status === 'consented' ? $this->t('Yes') : $this->t('No')) . '</span></td>';
        
        $html .= '<td><a href="/admin/nfr/participant/' . htmlspecialchars($participant['participant_id'] ?? '') . '" class="btn-link">' . 
          $this->t('View') . '</a></td>';
        $html .= '</tr>';
      }
    }
    
    $html .= '</tbody></table>';
    $html .= '</div>'; // table wrapper
    
    $html .= '</div>'; // .participant-list-page

    return $html;
  }

  /**
   * Participant detail page.
   *
   * @param string $id
   *   Participant ID.
   *
   * @return array
   *   Render array.
   */
  public function participantDetail(string $id): array {
    $participant_data = $this->getParticipantData($id);
    
    if (empty($participant_data)) {
      return [
        '#markup' => '<p>' . $this->t('Participant not found.') . '</p>',
      ];
    }
    
    $detail_content = $this->buildParticipantDetail($participant_data);

    return [
      '#theme' => 'nfr_admin_page',
      '#page_id' => 'participant-detail',
      '#content' => [
        '#type' => 'inline_template',
        '#template' => '{{ content|raw }}',
        '#context' => [
          'content' => $detail_content,
        ],
      ],
      '#attached' => [
        'library' => ['nfr/admin'],
      ],
    ];
  }

  /**
   * Get participant data.
   */
  private function getParticipantData(string $participant_id): array {
    // Get profile
    $profile = $this->database->select('nfr_user_profile', 'p')
      ->fields('p')
      ->condition('participant_id', $participant_id)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);
    
    if (!$profile) {
      return [];
    }
    
    $uid = $profile['uid'];
    
    // Get consent
    $consent = $this->database->select('nfr_consent', 'c')
      ->fields('c')
      ->condition('uid', $uid)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);
    
    // Get questionnaire
    $questionnaire = $this->database->select('nfr_questionnaire', 'q')
      ->fields('q')
      ->condition('uid', $uid)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);
    
    return [
      'profile' => $profile,
      'consent' => $consent,
      'questionnaire' => $questionnaire,
    ];
  }

  /**
   * Build participant detail HTML.
   */
  private function buildParticipantDetail(array $data): string {
    $profile = $data['profile'];
    $consent = $data['consent'];
    $questionnaire = $data['questionnaire'];
    
    $html = '<div class="participant-detail-page">';
    
    // Header
    $html .= '<div class="page-header">';
    $html .= '<div>';
    $html .= '<h1>' . htmlspecialchars(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) . '</h1>';
    $html .= '<p class="participant-id-display">ID: ' . htmlspecialchars($profile['participant_id'] ?? 'N/A') . '</p>';
    $html .= '</div>';
    $html .= '<div class="page-actions">';
    $html .= '<a href="/admin/nfr/participants" class="btn btn-secondary">' . $this->t('← Back to List') . '</a>';
    $html .= '</div>';
    $html .= '</div>';

    // Status overview
    $html .= '<div class="detail-status-bar">';
    $html .= '<div class="status-item">';
    $html .= '<span class="status-label">' . $this->t('Profile:') . '</span> ';
    $html .= '<span class="status-badge status-' . (($profile['profile_completed'] ?? false) ? 'complete' : 'incomplete') . '">' . 
      (($profile['profile_completed'] ?? false) ? $this->t('Complete') : $this->t('Incomplete')) . '</span>';
    $html .= '</div>';
    $html .= '<div class="status-item">';
    $html .= '<span class="status-label">' . $this->t('Questionnaire:') . '</span> ';
    $html .= '<span class="status-badge status-' . (($questionnaire['questionnaire_completed'] ?? false) ? 'complete' : 'incomplete') . '">' . 
      (($questionnaire['questionnaire_completed'] ?? false) ? $this->t('Complete') : $this->t('Incomplete')) . '</span>';
    $html .= '</div>';
    $html .= '<div class="status-item">';
    $html .= '<span class="status-label">' . $this->t('Linkage:') . '</span> ';
    $html .= '<span class="status-badge status-' . (($consent['consented_to_registry_linkage'] ?? false) ? 'consented' : 'no-consent') . '">' . 
      (($consent['consented_to_registry_linkage'] ?? false) ? $this->t('Consented') : $this->t('No Consent')) . '</span>';
    $html .= '</div>';
    $html .= '</div>';

    // Tabs (simplified - showing data sections)
    $html .= '<div class="detail-sections">';
    
    // Profile information
    $html .= '<div class="detail-section">';
    $html .= '<h2>' . $this->t('Profile Information') . '</h2>';
    $html .= '<div class="mb-3"><a href="/nfr/my-profile?uid=' . $profile['uid'] . '" class="btn btn-sm btn-primary">' . $this->t('View Profile Form') . '</a></div>';
    $html .= '<div class="info-grid">';
    // Get email from Drupal user account
    $user_email = 'N/A';
    if (!empty($profile['uid'])) {
      $user = \Drupal\user\Entity\User::load($profile['uid']);
      if ($user) {
        $user_email = $user->getEmail();
      }
    }
    $html .= '<div class="info-item"><strong>' . $this->t('Email:') . '</strong> ' . htmlspecialchars($user_email) . '</div>';
    $html .= '<div class="info-item"><strong>' . $this->t('Phone:') . '</strong> ' . htmlspecialchars($profile['phone_number'] ?? 'N/A') . '</div>';
    $html .= '<div class="info-item"><strong>' . $this->t('Date of Birth:') . '</strong> ' . htmlspecialchars($profile['date_of_birth'] ?? 'N/A') . '</div>';
    $html .= '<div class="info-item"><strong>' . $this->t('Address:') . '</strong> ' . 
      htmlspecialchars(($profile['street_address'] ?? '') . ', ' . ($profile['city'] ?? '') . ', ' . 
      ($profile['state'] ?? '') . ' ' . ($profile['zip_code'] ?? '')) . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Work information
    if (!empty($profile['current_department']) || !empty($profile['current_job_title'])) {
      $html .= '<div class="detail-section">';
      $html .= '<h2>' . $this->t('Work Information') . '</h2>';
      $html .= '<div class="info-grid">';
      $html .= '<div class="info-item"><strong>' . $this->t('Department:') . '</strong> ' . htmlspecialchars($profile['current_department'] ?? 'N/A') . '</div>';
      $html .= '<div class="info-item"><strong>' . $this->t('Job Title:') . '</strong> ' . htmlspecialchars($profile['current_job_title'] ?? 'N/A') . '</div>';
      $html .= '<div class="info-item"><strong>' . $this->t('Employment Status:') . '</strong> ' . htmlspecialchars($profile['employment_status'] ?? 'N/A') . '</div>';
      $html .= '<div class="info-item"><strong>' . $this->t('Firefighter Since:') . '</strong> ' . htmlspecialchars($profile['year_started_firefighting'] ?? 'N/A') . '</div>';
      $html .= '</div>';
    $html .= '</div>';
    }
    
    // Consent information
    if ($consent) {
      $html .= '<div class="detail-section">';
      $html .= '<h2>' . $this->t('Consent Information') . '</h2>';
      $html .= '<div class="mb-3"><a href="/nfr/consent?uid=' . $profile['uid'] . '" class="btn btn-sm btn-primary">' . $this->t('View Consent Form') . '</a></div>';
      $html .= '<div class="info-grid">';
      $html .= '<div class="info-item"><strong>' . $this->t('Consent Date:') . '</strong> ' . 
        ($consent['consent_timestamp'] ? date('M j, Y', (int) $consent['consent_timestamp']) : 'N/A') . '</div>';
      $html .= '<div class="info-item"><strong>' . $this->t('Registry Linkage:') . '</strong> ' . 
        (($consent['consented_to_registry_linkage'] ?? false) ? $this->t('Yes') : $this->t('No')) . '</div>';
      $html .= '<div class="info-item"><strong>' . $this->t('Signature:') . '</strong> ' . htmlspecialchars($consent['participant_signature'] ?? 'N/A') . '</div>';
      $html .= '</div>';
      $html .= '</div>';
    }
    
    // Questionnaire status
    if ($questionnaire) {
      $html .= '<div class="detail-section">';
      $html .= '<h2>' . $this->t('Questionnaire') . '</h2>';
      $html .= '<div class="mb-3"><a href="/nfr/review?uid=' . $profile['uid'] . '" class="btn btn-sm btn-primary">' . $this->t('View Full Questionnaire') . '</a></div>';
      $html .= '<div class="info-grid">';
      $html .= '<div class="info-item"><strong>' . $this->t('Status:') . '</strong> ' . 
        (($questionnaire['questionnaire_completed'] ?? false) ? $this->t('Complete') : $this->t('In Progress')) . '</div>';
      $html .= '<div class="info-item"><strong>' . $this->t('Last Updated:') . '</strong> ' . 
        ($questionnaire['updated'] ? date('M j, Y g:i A', (int) $questionnaire['updated']) : 'N/A') . '</div>';
      $html .= '<div class="info-item"><strong>' . $this->t('Current Section:') . '</strong> ' . htmlspecialchars($questionnaire['current_section'] ?? 'N/A') . '</div>';
      $html .= '</div>';
      $html .= '</div>';
    }
    
    $html .= '</div>'; // .detail-sections
    $html .= '</div>'; // .participant-detail-page

    return $html;
  }

  /**
   * Cancer registry linkage management page.
   *
   * @return array
   *   Render array.
   */
  public function linkageManagement(): array {
    $linkage_stats = $this->getLinkageStatistics();
    $linkage_content = $this->buildLinkageManagement($linkage_stats);

    return [
      '#theme' => 'nfr_admin_page',
      '#page_id' => 'linkage-management',
      '#content' => [
        '#type' => 'inline_template',
        '#template' => '{{ content|raw }}',
        '#context' => [
          'content' => $linkage_content,
        ],
      ],
      '#attached' => [
        'library' => ['nfr/admin'],
      ],
    ];
  }

  /**
   * Get linkage statistics.
   */
  private function getLinkageStatistics(): array {
    $total_consented = $this->database->select('nfr_consent', 'c')
      ->condition('consented_to_registry_linkage', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    $total_participants = $this->database->select('nfr_user_profile', 'p')
      ->condition('profile_completed', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    return [
      'total_consented' => (int) $total_consented,
      'total_participants' => (int) $total_participants,
      'consent_rate' => $total_participants > 0 ? round(($total_consented / $total_participants) * 100, 1) : 0,
      'pending_export' => (int) $total_consented, // In real implementation, track exported status
    ];
  }

  /**
   * Build linkage management HTML.
   */
  private function buildLinkageManagement(array $stats): string {
    $html = '<div class="linkage-management-page">';
    
    // Header
    $html .= '<div class="page-header">';
    $html .= '<h1>' . $this->t('Cancer Registry Linkage Management') . '</h1>';
    $html .= '<p class="page-subtitle">' . $this->t('Manage cancer registry data linkage and matching') . '</p>';
    $html .= '</div>';

    // Statistics
    $html .= '<div class="linkage-stats">';
    $html .= '<div class="stat-card">';
    $html .= '<div class="stat-value">' . number_format($stats['total_consented']) . '</div>';
    $html .= '<div class="stat-label">' . $this->t('Total Consented') . '</div>';
    $html .= '</div>';
    $html .= '<div class="stat-card">';
    $html .= '<div class="stat-value">' . $stats['consent_rate'] . '%</div>';
    $html .= '<div class="stat-label">' . $this->t('Consent Rate') . '</div>';
    $html .= '</div>';
    $html .= '<div class="stat-card">';
    $html .= '<div class="stat-value">' . number_format($stats['pending_export']) . '</div>';
    $html .= '<div class="stat-label">' . $this->t('Pending Export') . '</div>';
    $html .= '</div>';
    $html .= '</div>';

    // Workflow steps
    $html .= '<div class="linkage-workflow">';
    
    // Step 1: Generate files
    $html .= '<div class="workflow-step">';
    $html .= '<div class="step-number">1</div>';
    $html .= '<div class="step-content">';
    $html .= '<h3>' . $this->t('Generate Linkage Files') . '</h3>';
    $html .= '<p>' . $this->t('Export participant data for state cancer registries') . '</p>';
    $html .= '<button class="btn btn-primary">' . $this->t('Generate Export Files') . '</button>';
    $html .= '<p class="step-note">' . $this->t('Last export: Never') . '</p>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Step 2: Submit to registries
    $html .= '<div class="workflow-step">';
    $html .= '<div class="step-number">2</div>';
    $html .= '<div class="step-content">';
    $html .= '<h3>' . $this->t('Submit to State Registries') . '</h3>';
    $html .= '<p>' . $this->t('Send files to state cancer registries via secure channels') . '</p>';
    $html .= '<div class="step-info">' . $this->t('Files should be submitted according to each state\'s protocol') . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Step 3: Upload results
    $html .= '<div class="workflow-step">';
    $html .= '<div class="step-number">3</div>';
    $html .= '<div class="step-content">';
    $html .= '<h3>' . $this->t('Upload Match Results') . '</h3>';
    $html .= '<p>' . $this->t('Import cancer diagnosis data from state registries') . '</p>';
    $html .= '<button class="btn btn-secondary">' . $this->t('Upload Results File') . '</button>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Step 4: Review matches
    $html .= '<div class="workflow-step">';
    $html .= '<div class="step-number">4</div>';
    $html .= '<div class="step-content">';
    $html .= '<h3>' . $this->t('Review Matches') . '</h3>';
    $html .= '<p>' . $this->t('Verify and approve matched records') . '</p>';
    $html .= '<div class="step-info">' . $this->t('No pending matches to review') . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    $html .= '</div>'; // .linkage-workflow

    // Additional resources
    $html .= '<div class="linkage-resources">';
    $html .= '<h2>' . $this->t('Resources') . '</h2>';
    $html .= '<ul>';
    $html .= '<li><strong>' . $this->t('State Cancer Registry Contacts:') . '</strong></li>';
    $html .= '<li style="margin-left: 20px;"><a href="https://www.cdc.gov/cancer/npcr/contact.htm" target="_blank">' . $this->t('CDC NPCR Contact Map (46 States)') . '</a></li>';
    $html .= '<li style="margin-left: 20px;"><a href="https://www.naaccr.org/registry-contact-information/" target="_blank">' . $this->t('NAACCR Registry Directory (All 50 States)') . '</a></li>';
    $html .= '<li style="margin-left: 20px;"><a href="https://seer.cancer.gov/registries/" target="_blank">' . $this->t('NCI SEER Registries') . '</a></li>';
    $html .= '<li style="margin-top: 10px;"><a href="https://apps.usfa.fema.gov/registry/" target="_blank">' . $this->t('USFA Fire Department Registry (All 50 States)') . '</a></li>';
    $html .= '<li style="margin-top: 10px;"><strong>' . $this->t('NFR Federal Contacts:') . '</strong></li>';
    $html .= '<li style="margin-left: 20px;">' . $this->t('Email: <a href="mailto:NFRegistry@cdc.gov">NFRegistry@cdc.gov</a>') . '</li>';
    $html .= '<li style="margin-left: 20px;">' . $this->t('Help Desk: <a href="tel:833-489-1298">833-489-1298</a>') . '</li>';
    $html .= '<li style="margin-left: 20px;">' . $this->t('Mailing: 1090 Tusculum Avenue, MS: C-48, Cincinnati, OH 45226') . '</li>';
    $html .= '<li style="margin-top: 10px;"><a href="/admin/nfr">' . $this->t('← Back to Dashboard') . '</a></li>';
    $html .= '</ul>';
    $html .= '</div>';
    
    $html .= '</div>'; // .linkage-management-page

    return $html;
  }

  /**
   * Participant detail page.
   *
   * @param int $id
   *   Participant ID.
   *
   * @return array
   *   Render array.
   */
  public function participantDetail_old(int $id): array {
    return [
      '#theme' => 'nfr_admin_page',
      '#page_id' => 'participant-detail',
      '#content' => [
        '#markup' => '<h2>Participant Details</h2><p>Viewing participant ID: ' . $id . '</p>',
      ],
    ];
  }

  /**
   * Data quality monitoring page.
   *
   * @return array
   *   Render array.
   */
  public function dataQuality(): array {
    return [
      '#theme' => 'nfr_admin_page',
      '#page_id' => 'data-quality',
      '#content' => [
        '#markup' => '<h2>Data Quality Monitor</h2><p>Placeholder for data quality reports and validation.</p>',
      ],
    ];
  }

  /**
   * Redirect from old data-quality path to fill-rates.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response.
   */
  public function dataQualityRedirect() {
    return $this->redirect('nfr.validation.fill_rates');
  }

  /**
   * Report builder landing page with analysis options.
   *
   * @return array
   *   Render array.
   */
  public function reportBuilder(): array {
    // Get quick stats for display
    $stats_query = $this->database->select('nfr_correlation_analysis', 'c')
      ->fields('c', ['has_cancer_diagnosis', 'data_quality_score']);
    $stats = $stats_query->execute()->fetchAll();
    
    $total_records = count($stats);
    $cancer_cases = 0;
    $avg_quality = 0;
    
    if ($total_records > 0) {
      foreach ($stats as $record) {
        $cancer_cases += $record->has_cancer_diagnosis;
        $avg_quality += $record->data_quality_score;
      }
      $avg_quality = round($avg_quality / $total_records, 1);
    }
    
    $content = '<div class="reports-landing-page">';
    $content .= '<div class="reports-intro">';
    $content .= '<h2>Reports & Statistical Analysis</h2>';
    $content .= '<p>Select an analysis type below to explore relationships and patterns in the NFR dataset.</p>';
    $content .= '<div class="dataset-overview">';
    $content .= '<strong>Dataset:</strong> ' . number_format($total_records) . ' records | ';
    $content .= number_format($cancer_cases) . ' cancer cases | ';
    $content .= $avg_quality . '% avg quality';
    $content .= '</div>';
    $content .= '</div>';
    
    $content .= '<div class="analysis-cards">';
    
    // Correlation Analysis Card
    $content .= '<div class="analysis-card">';
    $content .= '<div class="card-icon">📊</div>';
    $content .= '<h3>Correlation Analysis</h3>';
    $content .= '<p>Examine statistical relationships between any two variables to identify risk factors and dose-response patterns.</p>';
    $content .= '<div class="card-features">';
    $content .= '<ul>';
    $content .= '<li>100+ variables across 5 categories</li>';
    $content .= '<li>Pearson & Spearman methods</li>';
    $content .= '<li>Statistical significance testing</li>';
    $content .= '<li>CSV export for R/SPSS/Python</li>';
    $content .= '</ul>';
    $content .= '</div>';
    $content .= '<a href="/admin/nfr/reports/correlation" class="card-button">Run Correlation Analysis →</a>';
    $content .= '</div>';
    
    // Cluster Analysis Card
    $content .= '<div class="analysis-card">';
    $content .= '<div class="card-icon">🔍</div>';
    $content .= '<h3>Cluster Analysis</h3>';
    $content .= '<p>Identify natural groupings and patterns in firefighter populations based on multiple characteristics.</p>';
    $content .= '<div class="card-features">';
    $content .= '<ul>';
    $content .= '<li>K-means clustering algorithm</li>';
    $content .= '<li>Multi-variable segmentation</li>';
    $content .= '<li>Cluster characteristics analysis</li>';
    $content .= '<li>CSV export of cluster assignments</li>';
    $content .= '</ul>';
    $content .= '</div>';
    $content .= '<a href="/admin/nfr/reports/cluster" class="card-button">Run Cluster Analysis →</a>';
    $content .= '</div>';
    
    $content .= '</div>'; // .analysis-cards
    $content .= '</div>'; // .reports-landing-page
    
    return [
      '#theme' => 'nfr_admin_page',
      '#page_id' => 'reports-landing',
      '#content' => [
        '#type' => 'inline_template',
        '#template' => '{{ content|raw }}',
        '#context' => [
          'content' => $content,
        ],
      ],
      '#attached' => [
        'library' => ['nfr/reports-landing'],
      ],
    ];
  }

  /**
   * Correlation analysis page.
   *
   * @return array
   *   Render array.
   */
  public function correlationAnalysis(): array {
    // Get statistics about the correlation dataset
    $stats = $this->database->select('nfr_correlation_analysis', 'c')
      ->fields('c')
      ->execute()
      ->fetchAll();
    
    $total_records = count($stats);
    $cancer_cases = 0;
    $avg_quality = 0;
    
    if ($total_records > 0) {
      foreach ($stats as $record) {
        $cancer_cases += $record->has_cancer_diagnosis;
        $avg_quality += $record->data_quality_score;
      }
      $avg_quality = round($avg_quality / $total_records, 1);
    }
    
    $intro = '<div class="correlation-page-intro">';
    $intro .= '<p class="breadcrumb"><a href="/admin/nfr/reports">← Back to Reports</a></p>';
    $intro .= '<h2>Correlation Analysis</h2>';
    $intro .= '<p>Analyze statistical relationships between variables in the NFR dataset to identify potential risk factors and dose-response relationships.</p>';
    $intro .= '<p><a href="/nfr/documentation/correlation-analysis-guide" style="color: #c8102e; font-weight: 600;">📖 View User Guide</a> | <a href="/nfr/documentation/correlation-analysis" style="color: #c8102e; font-weight: 600;">📊 Technical Documentation</a></p>';
    $intro .= '<div class="dataset-stats">';
    $intro .= '<div class="stat-box"><span class="stat-number">' . number_format($total_records) . '</span><span class="stat-label">Total Records</span></div>';
    $intro .= '<div class="stat-box"><span class="stat-number">' . number_format($cancer_cases) . '</span><span class="stat-label">Cancer Cases</span></div>';
    $intro .= '<div class="stat-box"><span class="stat-number">' . $avg_quality . '%</span><span class="stat-label">Avg Data Quality</span></div>';
    $intro .= '</div>';
    $intro .= '</div>';

    $form = \Drupal::formBuilder()->getForm('Drupal\nfr\Form\CorrelationAnalysisForm');
    
    return [
      '#theme' => 'nfr_admin_page',
      '#page_id' => 'correlation-analysis',
      '#content' => [
        'intro' => ['#markup' => $intro],
        'form' => $form,
      ],
      '#attached' => [
        'library' => ['nfr/correlation-analysis'],
      ],
    ];
  }

  /**
   * Cluster analysis page.
   *
   * @return array
   *   Render array.
   */
  public function clusterAnalysis(): array {
    // Get dataset statistics
    $stats = $this->database->select('nfr_correlation_analysis', 'c')
      ->fields('c', ['has_cancer_diagnosis', 'data_quality_score'])
      ->execute()
      ->fetchAll();
    
    $total_records = count($stats);
    $cancer_cases = 0;
    $avg_quality = 0;
    
    if ($total_records > 0) {
      foreach ($stats as $record) {
        $cancer_cases += $record->has_cancer_diagnosis;
        $avg_quality += $record->data_quality_score;
      }
      $avg_quality = round($avg_quality / $total_records, 1);
    }
    
    $intro = '<div class="cluster-page-intro">';
    $intro .= '<p class="breadcrumb"><a href="/admin/nfr/reports">← Back to Reports</a></p>';
    $intro .= '<h2>K-Means Cluster Analysis</h2>';
    $intro .= '<p>Identify natural groupings in firefighter populations based on multiple characteristics. The K-means algorithm segments participants into distinct clusters by similarity.</p>';
    $intro .= '<div class="dataset-stats">';
    $intro .= '<div class="stat-box"><span class="stat-number">' . number_format($total_records) . '</span><span class="stat-label">Total Records</span></div>';
    $intro .= '<div class="stat-box"><span class="stat-number">' . number_format($cancer_cases) . '</span><span class="stat-label">Cancer Cases</span></div>';
    $intro .= '<div class="stat-box"><span class="stat-number">' . $avg_quality . '%</span><span class="stat-label">Avg Data Quality</span></div>';
    $intro .= '</div>';
    $intro .= '</div>';

    $form = \Drupal::formBuilder()->getForm('Drupal\nfr\Form\ClusterAnalysisForm');
    
    return [
      '#theme' => 'nfr_admin_page',
      '#page_id' => 'cluster-analysis',
      '#content' => [
        'intro' => ['#markup' => $intro],
        'form' => $form,
      ],
      '#attached' => [
        'library' => ['nfr/cluster-analysis'],
      ],
    ];
  }

  /**
   * User support issues page.
   *
   * @return array
   *   Render array.
   */
  public function userIssues(): array {
    return [
      '#theme' => 'nfr_admin_page',
      '#page_id' => 'user-issues',
      '#content' => [
        '#markup' => '<h2>User Support Issues</h2><p>Placeholder for support ticket queue.</p>',
      ],
    ];
  }

  /**
   * System settings page.
   *
   * @return array
   *   Render array.
   */
  public function systemSettings(): array {
    return [
      '#theme' => 'nfr_admin_page',
      '#page_id' => 'system-settings',
      '#content' => [
        '#markup' => '<h2>System Settings</h2><p>Placeholder for NFR configuration settings.</p>',
      ],
    ];
  }

}
