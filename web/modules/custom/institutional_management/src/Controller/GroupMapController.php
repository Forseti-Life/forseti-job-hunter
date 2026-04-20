<?php

namespace Drupal\institutional_management\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for Group Map views.
 */
class GroupMapController extends ControllerBase {

  /**
   * Display group map with member locations.
   *
   * @param int $group
   *   The group ID.
   *
   * @return array
   *   A render array.
   */
  public function viewGroupMap($group) {
    $current_user = $this->currentUser();
    
    // Load the group
    $group_entity = \Drupal::entityTypeManager()->getStorage('group')->load($group);
    
    if (!$group_entity) {
      throw new NotFoundHttpException();
    }
    
    // Check if current user is a member
    $membership = $group_entity->getMember($current_user);
    if (!$membership) {
      throw new AccessDeniedHttpException('You must be a member of this group to view it.');
    }
    
    // Get group type
    $group_type = $group_entity->getGroupType();
    
    // Get all group members with their latest locations
    $members = $group_entity->getMembers();
    $member_locations = [];
    
    $connection = Database::getConnection();
    
    foreach ($members as $member) {
      $user = $member->getUser();
      $uid = $user->id();
      
      // Get latest location for this member
      $result = $connection->select('user_location_tracking', 'ult')
        ->fields('ult')
        ->condition('uid', $uid)
        ->orderBy('timestamp', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchObject();
      
      if ($result) {
        // Get user's roles in this group
        $roles = [];
        foreach ($member->getRoles() as $role) {
          $roles[] = $role->label();
        }
        
        $member_locations[] = [
          'uid' => $uid,
          'username' => $user->getDisplayName(),
          'latitude' => floatval($result->latitude),
          'longitude' => floatval($result->longitude),
          'h3_index' => $result->h3_index,
          'accuracy' => $result->accuracy ? floatval($result->accuracy) : null,
          'timestamp' => intval($result->timestamp),
          'roles' => $roles,
          'updated' => \Drupal::service('date.formatter')->format($result->timestamp, 'medium'),
        ];
      }
    }
    
    // Calculate center point (average of all locations, or default)
    $center_lat = 39.9526;
    $center_lon = -75.1652;
    
    if (!empty($member_locations)) {
      $center_lat = array_sum(array_column($member_locations, 'latitude')) / count($member_locations);
      $center_lon = array_sum(array_column($member_locations, 'longitude')) / count($member_locations);
    }
    
    $build = [];
    
    // Header
    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-fluid', 'py-3']],
      'content' => [
        '#markup' => '<div class="row"><div class="col-12">' .
          '<div class="d-flex justify-content-between align-items-center flex-wrap mb-3">' .
          '<div>' .
          '<h1 class="text-cyan mb-1">' . $this->t('@name - Member Locations', ['@name' => $group_entity->label()]) . '</h1>' .
          '<p class="text-muted mb-0">' . 
          '<span class="badge bg-secondary me-2">' . $group_type->label() . '</span>' .
          '<span class="text-muted">' . $this->t('@count members', ['@count' => count($members)]) . '</span>' .
          '</p>' .
          '</div>' .
          '<div>' .
          '<a href="' . $group_entity->toUrl()->toString() . '" class="btn btn-outline-secondary btn-sm me-2">' . $this->t('View Group') . '</a>' .
          '<a href="/my-groups" class="btn btn-primary btn-sm">' . $this->t('My Groups') . '</a>' .
          '</div>' .
          '</div></div></div>',
      ],
    ];
    
    // Map container
    $build['map'] = [
      '#theme' => 'group_location_map',
      '#group' => $group_entity,
      '#members' => $member_locations,
      '#map_config' => [
        'zoom' => 13,
        'center' => [$center_lat, $center_lon],
      ],
      '#attached' => [
        'library' => [
          'institutional_management/group-map',
        ],
        'drupalSettings' => [
          'groupMap' => [
            'groupId' => $group,
            'groupName' => $group_entity->label(),
            'members' => $member_locations,
            'mapConfig' => [
              'zoom' => 13,
              'center' => [$center_lat, $center_lon],
            ],
            'apiEndpoints' => [
              'latestLocations' => '/api/location/latest?group_id=' . $group,
              'updateLocation' => '/api/location/update',
            ],
          ],
        ],
      ],
    ];
    
    // Member list
    if (empty($member_locations)) {
      $build['empty'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['container', 'my-4']],
        'message' => [
          '#markup' => '<div class="alert alert-info">' .
            $this->t('No member locations available yet. Members need to share their location via the mobile app.') .
            '</div>',
        ],
      ];
    }
    else {
      $build['member_list'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['container', 'my-4']],
        'title' => [
          '#markup' => '<h3 class="mb-3">' . $this->t('Member Locations') . '</h3>',
        ],
      ];
      
      $list_items = [];
      foreach ($member_locations as $location) {
        $list_items[] = [
          '#markup' => '<div class="card mb-2">' .
            '<div class="card-body p-3">' .
            '<div class="d-flex justify-content-between align-items-start">' .
            '<div>' .
            '<strong>' . $location['username'] . '</strong>' .
            (!empty($location['roles']) ? ' <span class="badge bg-info">' . implode(', ', $location['roles']) . '</span>' : '') .
            '<br><small class="text-muted">Last updated: ' . $location['updated'] . '</small>' .
            '</div>' .
            '<div class="text-end">' .
            '<small class="text-muted">Accuracy: ±' . ($location['accuracy'] ?? 'N/A') . 'm</small>' .
            '</div>' .
            '</div>' .
            '</div></div>',
        ];
      }
      
      $build['member_list']['items'] = $list_items;
    }
    
    return $build;
  }

}
