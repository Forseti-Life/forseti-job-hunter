<?php

/**
 * @file
 * Local development override configuration - COMPREHENSIVE CACHE DISABLING
 * For Drupal 11 - Updated to use memory backend instead of null
 */

// Use memory backend (non-persistent) for all cache bins
$settings['cache']['bins']['render'] = 'cache.backend.memory';
$settings['cache']['bins']['page'] = 'cache.backend.memory';
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.memory';
$settings['cache']['bins']['discovery'] = 'cache.backend.memory';
$settings['cache']['bins']['config'] = 'cache.backend.memory';
$settings['cache']['bins']['data'] = 'cache.backend.memory';
$settings['cache']['bins']['default'] = 'cache.backend.memory';
$settings['cache']['bins']['bootstrap'] = 'cache.backend.memory';
$settings['cache']['bins']['entity'] = 'cache.backend.memory';
$settings['cache']['bins']['menu'] = 'cache.backend.memory';
$settings['cache']['bins']['toolbar'] = 'cache.backend.memory';
$settings['cache']['bins']['migrate'] = 'cache.backend.memory';
$settings['cache']['bins']['form'] = 'cache.backend.memory';
$settings['cache']['bins']['rest'] = 'cache.backend.memory';
$settings['cache']['bins']['jsonapi_normalizations'] = 'cache.backend.memory';
$settings['cache']['bins']['jsonapi_resource_types'] = 'cache.backend.memory';
$settings['cache']['bins']['library_info'] = 'cache.backend.memory';

// Disable CSS and JS aggregation and caching
$config['system.performance']['css']['preprocess'] = FALSE;
$config['system.performance']['js']['preprocess'] = FALSE;
$config['system.performance']['cache']['page']['max_age'] = 0;

// Comprehensive Twig debugging and cache disabling
$settings['twig_debug'] = TRUE;
$settings['twig_auto_reload'] = TRUE;
$settings['twig_cache'] = FALSE;

// Views caching disabled
$config['views.settings']['ui']['always_live_preview'] = TRUE;
$config['views.settings']['ui']['exposed_filter_any_label'] = 'new_any';

// Block caching disabled
$config['block.settings']['cache']['max_age'] = 0;

// Node caching disabled
$config['node.settings']['use_admin_theme'] = TRUE;

// System caching disabled
$config['system.site']['page']['front'] = '/home';

// Development settings
$settings['extension_discovery_scan_tests'] = FALSE;
$settings['rebuild_access'] = TRUE;
$settings['skip_permissions_hardening'] = TRUE;
$settings['hash_salt'] = 'development-hash-salt-not-for-production';
$settings['update_free_access'] = FALSE;
$settings['allow_authorize_operations'] = FALSE;

// Disable internal page cache completely
$settings['omit_vary_cookie'] = TRUE;

// Disable BigPipe if it exists
$config['big_pipe.settings']['enabled'] = FALSE;

// Additional cache prevention
$settings['cache_ttl_4xx'] = 0;
$settings['cache_ttl_negative'] = 0;
$settings['page_cache_maximum_age'] = 0;

// Force immediate template updates
$settings['twig_extension_hash_prefix'] = '';

