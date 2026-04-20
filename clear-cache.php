<?php

/**
 * Simple cache clear script for production deployment
 * Run via: curl https://forseti.life/clear-cache.php
 */

// Bootstrap Drupal
use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once 'autoload.php';
$kernel = new DrupalKernel('prod', $autoloader);

$request = Request::createFromGlobals();
$kernel->boot();
$kernel->prepareLegacyRequest($request);

// Clear all caches
drupal_flush_all_caches();

echo "Cache cleared successfully!\n";
echo "Routes rebuilt.\n";
echo "New API endpoints available.\n";
