<?php

/**
 * @file
 * One-time script to update validation users to follow test naming patterns.
 */

// Add this as update hook 9020 to nfr.install
function nfr_update_9020() {
  $old_usernames = [
    'firefighter_active',
    'firefighter_retired', 
    'nfr_admin',
    'nfr_researcher',
    'dept_admin',
    'firefighter',
    'fire_department_admin',
  ];
  
  $deleted = 0;
  
  // Delete old validation users
  foreach ($old_usernames as $username) {
    $users = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => $username]);
    
    if (!empty($users)) {
      foreach ($users as $user) {
        if ($user->id() > 1) { // Never delete admin
          $user->delete();
          $deleted++;
          \Drupal::logger('nfr')->info('Deleted old validation user: @username', ['@username' => $username]);
        }
      }
    }
  }
  
  // Create new validation users with test naming pattern
  _nfr_create_validation_users();
  
  return t('Deleted @count old validation users and created new test-pattern users.', ['@count' => $deleted]);
}
