<?php

/**
 * @file
 * Provides Views integration for Users Metrics module.
 */

/**
 * Implements hook_views_data_alter().
 */
function users_metrics_views_data_alter(array &$data) {
  // Add groupable created field to users_field_data table.
  $data['users_field_data']['created_groupable'] = [
    'title' => t('Registration Date (Groupable)'),
    'help' => t('Allow grouping by user registration date.'),
    'field' => [
      'id' => 'user_created_groupable',
    ],
  ];

  // Add filter for selecting grouping field.
  $data['users_field_data']['group_field'] = [
    'title' => t('Group by field'),
    'help' => t('Select a user field to group registrations by.'),
    'filter' => [
      'id' => 'user_field_grouping',
    ],
  ];

  // Only add watchdog fields if dblog is enabled.
  if (\Drupal::moduleHandler()->moduleExists('dblog')) {
    $data['watchdog']['timestamp_groupable'] = [
      'title' => t('Timestamp (Groupable)'),
      'help' => t('Allow grouping by log timestamp for statistics.'),
      'field' => [
        'id' => 'watchdog_timestamp_groupable',
      ],
    ];
  }
}
