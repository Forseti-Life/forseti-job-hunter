<?php

/**
 * @file
 * Contains views and display configurations for Job Application Automation module.
 */

use Drupal\views\Entity\View;

/**
 * Creates basic administrative views for content management.
 */
function _job_hunter_create_admin_views() {
  $logger = \Drupal::logger('job_hunter');
  
  // Create administrative views for each content type
  $views_configs = [
    'admin_companies' => _job_hunter_get_companies_view_config(),
    'admin_job_postings' => _job_hunter_get_job_postings_view_config(),
    'admin_applications' => _job_hunter_get_applications_view_config(),
    'admin_issues' => _job_hunter_get_issues_view_config(),
  ];
  
  foreach ($views_configs as $view_id => $config) {
    // Check if view already exists
    $view = View::load($view_id);
    if (!$view) {
      try {
        $view = View::create($config);
        $view->save();
        $logger->info('Created administrative view: @view_id', ['@view_id' => $view_id]);
      } catch (\Exception $e) {
        $logger->error('Failed to create administrative view @view_id: @error', [
          '@view_id' => $view_id,
          '@error' => $e->getMessage(),
        ]);
      }
    } else {
      $logger->info('Administrative view @view_id already exists, skipping creation.', ['@view_id' => $view_id]);
    }
  }
}

/**
 * Get configuration for Companies administrative view.
 */
function _job_hunter_get_companies_view_config() {
  return [
    'langcode' => 'en',
    'status' => TRUE,
    'id' => 'admin_companies',
    'label' => 'Admin: Companies',
    'module' => 'views',
    'description' => 'Administrative view for managing company records',
    'tag' => 'job_hunter',
    'base_table' => 'node_field_data',
    'base_field' => 'nid',
    'display' => [
      'default' => [
        'id' => 'default',
        'display_title' => 'Default',
        'display_plugin' => 'default',
        'position' => 0,
        'display_options' => [
          'title' => 'Companies Administration',
          'fields' => [
            'title' => [
              'id' => 'title',
              'table' => 'node_field_data',
              'field' => 'title',
              'relationship' => 'none',
              'group_type' => 'group',
              'admin_label' => '',
              'entity_type' => 'node',
              'entity_field' => 'title',
              'plugin_id' => 'field',
              'label' => 'Company Name',
              'exclude' => FALSE,
              'alter' => [
                'alter_text' => FALSE,
                'text' => '',
                'make_link' => FALSE,
                'path' => '',
                'absolute' => FALSE,
                'external' => FALSE,
                'replace_spaces' => FALSE,
                'path_case' => 'none',
                'trim_whitespace' => FALSE,
                'alt' => '',
                'rel' => '',
                'link_class' => '',
                'prefix' => '',
                'suffix' => '',
                'target' => '',
                'nl2br' => FALSE,
                'max_length' => 0,
                'word_boundary' => TRUE,
                'ellipsis' => TRUE,
                'more_link' => FALSE,
                'more_link_text' => '',
                'more_link_path' => '',
                'strip_tags' => FALSE,
                'trim' => FALSE,
                'preserve_tags' => '',
                'html' => FALSE,
              ],
              'element_type' => '',
              'element_class' => '',
              'element_label_type' => '',
              'element_label_class' => '',
              'element_label_colon' => TRUE,
              'element_wrapper_type' => '',
              'element_wrapper_class' => '',
              'element_default_classes' => TRUE,
              'empty' => '',
              'hide_empty' => FALSE,
              'empty_zero' => FALSE,
              'hide_alter_empty' => TRUE,
              'click_sort_column' => 'value',
              'type' => 'string',
              'settings' => [
                'link_to_entity' => TRUE,
              ],
              'group_column' => 'value',
              'group_columns' => [],
              'group_rows' => TRUE,
              'delta_limit' => 0,
              'delta_offset' => 0,
              'delta_reversed' => FALSE,
              'delta_first_last' => FALSE,
              'multi_type' => 'separator',
              'separator' => ', ',
              'field_api_classes' => FALSE,
            ],
          ],
          'filters' => [
            'status' => [
              'id' => 'status',
              'table' => 'node_field_data',
              'field' => 'status',
              'relationship' => 'none',
              'group_type' => 'group',
              'admin_label' => '',
              'entity_type' => 'node',
              'entity_field' => 'status',
              'plugin_id' => 'boolean',
              'operator' => '=',
              'value' => '1',
              'group' => 1,
              'exposed' => FALSE,
              'expose' => [
                'operator_id' => '',
                'label' => '',
                'description' => '',
                'use_operator' => FALSE,
                'operator' => '',
                'operator_limit_selection' => FALSE,
                'operator_list' => [],
                'identifier' => '',
                'required' => FALSE,
                'remember' => FALSE,
                'multiple' => FALSE,
                'remember_roles' => [
                  'authenticated' => 'authenticated',
                  'anonymous' => '0',
                  'administrator' => '0',
                ],
              ],
              'is_grouped' => FALSE,
              'group_info' => [
                'label' => '',
                'description' => '',
                'identifier' => '',
                'optional' => TRUE,
                'widget' => 'select',
                'multiple' => FALSE,
                'remember' => FALSE,
                'default_group' => 'All',
                'default_group_multiple' => [],
                'group_items' => [],
              ],
            ],
            'type' => [
              'id' => 'type',
              'table' => 'node_field_data',
              'field' => 'type',
              'relationship' => 'none',
              'group_type' => 'group',
              'admin_label' => '',
              'entity_type' => 'node',
              'entity_field' => 'type',
              'plugin_id' => 'bundle',
              'operator' => 'in',
              'value' => [
                'company' => 'company',
              ],
              'group' => 1,
              'exposed' => FALSE,
              'expose' => [
                'operator_id' => '',
                'label' => '',
                'description' => '',
                'use_operator' => FALSE,
                'operator' => '',
                'operator_limit_selection' => FALSE,
                'operator_list' => [],
                'identifier' => '',
                'required' => FALSE,
                'remember' => FALSE,
                'multiple' => FALSE,
                'remember_roles' => [
                  'authenticated' => 'authenticated',
                  'anonymous' => '0',
                  'administrator' => '0',
                ],
              ],
              'is_grouped' => FALSE,
              'group_info' => [
                'label' => '',
                'description' => '',
                'identifier' => '',
                'optional' => TRUE,
                'widget' => 'select',
                'multiple' => FALSE,
                'remember' => FALSE,
                'default_group' => 'All',
                'default_group_multiple' => [],
                'group_items' => [],
              ],
            ],
          ],
          'sorts' => [
            'created' => [
              'id' => 'created',
              'table' => 'node_field_data',
              'field' => 'created',
              'relationship' => 'none',
              'group_type' => 'group',
              'admin_label' => '',
              'entity_type' => 'node',
              'entity_field' => 'created',
              'plugin_id' => 'date',
              'order' => 'DESC',
              'expose' => [
                'label' => '',
                'field_identifier' => '',
              ],
              'exposed' => FALSE,
              'granularity' => 'second',
            ],
          ],
          'pager' => [
            'type' => 'full',
            'options' => [
              'offset' => 0,
              'items_per_page' => 50,
              'total_pages' => NULL,
              'id' => 0,
              'tags' => [
                'next' => 'Next ›',
                'previous' => '‹ Previous',
                'first' => '« First',
                'last' => 'Last »',
              ],
              'expose' => [
                'items_per_page' => FALSE,
                'items_per_page_label' => 'Items per page',
                'items_per_page_options' => '5, 10, 25, 50',
                'items_per_page_options_all' => FALSE,
                'items_per_page_options_all_label' => '- All -',
                'offset' => FALSE,
                'offset_label' => 'Offset',
              ],
              'quantity' => 9,
            ],
          ],
          'exposed_form' => [
            'type' => 'basic',
            'options' => [
              'submit_button' => 'Apply',
              'reset_button' => FALSE,
              'reset_button_label' => 'Reset',
              'exposed_sorts_label' => 'Sort by',
              'expose_sort_order' => TRUE,
              'sort_asc_label' => 'Asc',
              'sort_desc_label' => 'Desc',
            ],
          ],
          'access' => [
            'type' => 'perm',
            'options' => [
              'perm' => 'edit any company content',
            ],
          ],
          'cache' => [
            'type' => 'tag',
            'options' => [],
          ],
          'empty' => [],
          'relationships' => [],
          'arguments' => [],
          'display_extenders' => [],
          'style' => [
            'type' => 'table',
            'options' => [
              'grouping' => [],
              'row_class' => '',
              'default_row_class' => TRUE,
              'columns' => [
                'title' => 'title',
              ],
              'default' => 'title',
              'info' => [
                'title' => [
                  'sortable' => TRUE,
                  'default_sort_order' => 'asc',
                  'align' => '',
                  'separator' => '',
                  'empty_column' => FALSE,
                  'responsive' => '',
                ],
              ],
              'override' => TRUE,
              'sticky' => FALSE,
              'summary' => '',
              'empty_table' => FALSE,
              'caption' => '',
              'description' => '',
            ],
          ],
          'row' => [
            'type' => 'fields',
            'options' => [
              'default_field_elements' => TRUE,
              'inline' => [],
              'separator' => '',
              'hide_empty' => FALSE,
            ],
          ],
          'query' => [
            'type' => 'views_query',
            'options' => [
              'query_comment' => '',
              'disable_sql_rewrite' => FALSE,
              'distinct' => FALSE,
              'replica' => FALSE,
              'query_tags' => [],
            ],
          ],
          'header' => [],
          'footer' => [],
          'display_comment' => '',
        ],
      ],
      'page_1' => [
        'id' => 'page_1',
        'display_title' => 'Page',
        'display_plugin' => 'page',
        'position' => 1,
        'display_options' => [
          'display_extenders' => [],
          'path' => 'admin/content/companies',
          'menu' => [
            'type' => 'normal',
            'title' => 'Companies',
            'description' => 'Manage company records for job application automation',
            'expanded' => FALSE,
            'parent' => 'system.admin_content',
            'weight' => 0,
            'context' => '0',
            'menu_name' => 'admin',
          ],
        ],
      ],
    ],
  ];
}

/**
 * Get configuration for Job Postings administrative view.
 */
function _job_hunter_get_job_postings_view_config() {
  return [
    'langcode' => 'en',
    'status' => TRUE,
    'id' => 'admin_job_postings',
    'label' => 'Admin: Job Postings',
    'module' => 'views',
    'description' => 'Administrative view for managing job posting records',
    'tag' => 'job_hunter',
    'base_table' => 'node_field_data',
    'base_field' => 'nid',
    'display' => [
      'default' => [
        'id' => 'default',
        'display_title' => 'Default',
        'display_plugin' => 'default',
        'position' => 0,
        'display_options' => [
          'title' => 'Job Postings Administration',
          'filters' => [
            'status' => [
              'id' => 'status',
              'table' => 'node_field_data',
              'field' => 'status',
              'relationship' => 'none',
              'group_type' => 'group',
              'admin_label' => '',
              'entity_type' => 'node',
              'entity_field' => 'status',
              'plugin_id' => 'boolean',
              'operator' => '=',
              'value' => '1',
              'group' => 1,
              'exposed' => FALSE,
            ],
            'type' => [
              'id' => 'type',
              'table' => 'node_field_data',
              'field' => 'type',
              'relationship' => 'none',
              'group_type' => 'group',
              'admin_label' => '',
              'entity_type' => 'node',
              'entity_field' => 'type',
              'plugin_id' => 'bundle',
              'operator' => 'in',
              'value' => [
                'job_posting' => 'job_posting',
              ],
              'group' => 1,
              'exposed' => FALSE,
            ],
          ],
          'access' => [
            'type' => 'perm',
            'options' => [
              'perm' => 'edit any job_posting content',
            ],
          ],
        ],
      ],
      'page_1' => [
        'id' => 'page_1',
        'display_title' => 'Page',
        'display_plugin' => 'page',
        'position' => 1,
        'display_options' => [
          'display_extenders' => [],
          'path' => 'admin/content/job-postings',
          'menu' => [
            'type' => 'normal',
            'title' => 'Job Postings',
            'description' => 'Manage job posting records',
            'expanded' => FALSE,
            'parent' => 'system.admin_content',
            'weight' => 1,
            'context' => '0',
            'menu_name' => 'admin',
          ],
        ],
      ],
    ],
  ];
}

/**
 * Get configuration for Applications administrative view.
 */
function _job_hunter_get_applications_view_config() {
  return [
    'langcode' => 'en',
    'status' => TRUE,
    'id' => 'admin_applications',
    'label' => 'Admin: Applications',
    'module' => 'views',
    'description' => 'Administrative view for managing job application records',
    'tag' => 'job_hunter',
    'base_table' => 'node_field_data',
    'base_field' => 'nid',
    'display' => [
      'default' => [
        'id' => 'default',
        'display_title' => 'Default',
        'display_plugin' => 'default',
        'position' => 0,
        'display_options' => [
          'title' => 'Applications Administration',
          'filters' => [
            'status' => [
              'id' => 'status',
              'table' => 'node_field_data',
              'field' => 'status',
              'relationship' => 'none',
              'group_type' => 'group',
              'admin_label' => '',
              'entity_type' => 'node',
              'entity_field' => 'status',
              'plugin_id' => 'boolean',
              'operator' => '=',
              'value' => '1',
              'group' => 1,
              'exposed' => FALSE,
            ],
            'type' => [
              'id' => 'type',
              'table' => 'node_field_data',
              'field' => 'type',
              'relationship' => 'none',
              'group_type' => 'group',
              'admin_label' => '',
              'entity_type' => 'node',
              'entity_field' => 'type',
              'plugin_id' => 'bundle',
              'operator' => 'in',
              'value' => [
                'application' => 'application',
              ],
              'group' => 1,
              'exposed' => FALSE,
            ],
          ],
          'access' => [
            'type' => 'perm',
            'options' => [
              'perm' => 'edit any application content',
            ],
          ],
        ],
      ],
      'page_1' => [
        'id' => 'page_1',
        'display_title' => 'Page',
        'display_plugin' => 'page',
        'position' => 1,
        'display_options' => [
          'display_extenders' => [],
          'path' => 'admin/content/applications',
          'menu' => [
            'type' => 'normal',
            'title' => 'Job Applications',
            'description' => 'Manage job application records',
            'expanded' => FALSE,
            'parent' => 'system.admin_content',
            'weight' => 2,
            'context' => '0',
            'menu_name' => 'admin',
          ],
        ],
      ],
    ],
  ];
}

/**
 * Get configuration for Issues administrative view.
 */
function _job_hunter_get_issues_view_config() {
  return [
    'langcode' => 'en',
    'status' => TRUE,
    'id' => 'admin_issues',
    'label' => 'Admin: Error Queue',
    'module' => 'views',
    'description' => 'Administrative view for managing error queue items',
    'tag' => 'job_hunter',
    'base_table' => 'node_field_data',
    'base_field' => 'nid',
    'display' => [
      'default' => [
        'id' => 'default',
        'display_title' => 'Default',
        'display_plugin' => 'default',
        'position' => 0,
        'display_options' => [
          'title' => 'Error Queue Administration',
          'filters' => [
            'status' => [
              'id' => 'status',
              'table' => 'node_field_data',
              'field' => 'status',
              'relationship' => 'none',
              'group_type' => 'group',
              'admin_label' => '',
              'entity_type' => 'node',
              'entity_field' => 'status',
              'plugin_id' => 'boolean',
              'operator' => '=',
              'value' => '1',
              'group' => 1,
              'exposed' => FALSE,
            ],
            'type' => [
              'id' => 'type',
              'table' => 'node_field_data',
              'field' => 'type',
              'relationship' => 'none',
              'group_type' => 'group',
              'admin_label' => '',
              'entity_type' => 'node',
              'entity_field' => 'type',
              'plugin_id' => 'bundle',
              'operator' => 'in',
              'value' => [
                'issue' => 'issue',
              ],
              'group' => 1,
              'exposed' => FALSE,
            ],
          ],
          'access' => [
            'type' => 'perm',
            'options' => [
              'perm' => 'manage error queue',
            ],
          ],
        ],
      ],
      'page_1' => [
        'id' => 'page_1',
        'display_title' => 'Page',
        'display_plugin' => 'page',
        'position' => 1,
        'display_options' => [
          'display_extenders' => [],
          'path' => 'admin/content/error-queue',
          'menu' => [
            'type' => 'normal',
            'title' => 'Error Queue',
            'description' => 'Manage system error queue items',
            'expanded' => FALSE,
            'parent' => 'system.admin_content',
            'weight' => 3,
            'context' => '0',
            'menu_name' => 'admin',
          ],
        ],
      ],
    ],
  ];
}

/**
 * Removes administrative views during uninstall.
 */
function _job_hunter_remove_admin_views() {
  $logger = \Drupal::logger('job_hunter');
  $view_ids = ['admin_companies', 'admin_job_postings', 'admin_applications', 'admin_issues'];
  
  foreach ($view_ids as $view_id) {
    $view = View::load($view_id);
    if ($view) {
      $view->delete();
      $logger->info('Removed administrative view: @view_id', ['@view_id' => $view_id]);
    }
  }
}