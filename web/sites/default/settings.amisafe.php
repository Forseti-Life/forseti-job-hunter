<?php

/**
 * @file
 * AmISafe database connection configuration.
 * 
 * This file configures the secondary database connection for the AmISafe
 * crime mapping module. The AmISafe database stores H3-indexed crime data
 * and geospatial analytics.
 * 
 * Include this file in settings.php:
 *   if (file_exists($app_root . '/' . $site_path . '/settings.amisafe.php')) {
 *     include $app_root . '/' . $site_path . '/settings.amisafe.php';
 *   }
 */

/**
 * AmISafe database connection.
 * 
 * Database: amisafe_database
 * Tables:
 *   - amisafe_raw_incidents (Bronze layer - immutable source data)
 *   - amisafe_clean_incidents (Silver layer - cleaned, H3-indexed)
 *   - amisafe_h3_aggregated (Gold layer - pre-computed analytics)
 *   - amisafe_ucr_codes (Reference data)
 * 
 * Stored Procedures: 21 analytics procedures for crime pattern analysis
 */
$databases['amisafe']['default'] = [
  'database' => 'amisafe_database',
  'username' => 'drupal_user',
  'password' => $databases['default']['default']['password'] ?? (getenv('DB_PASSWORD') ?: ''),
  'prefix' => '',
  'host' => '127.0.0.1',
  'port' => '3306',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
  'driver' => 'mysql',
  'collation' => 'utf8mb4_unicode_ci',
];
