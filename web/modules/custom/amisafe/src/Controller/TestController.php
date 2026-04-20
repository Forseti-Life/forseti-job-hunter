<?php

namespace Drupal\amisafe\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Simple test controller for debugging database connection.
 */
class TestController extends ControllerBase {

  /**
   * Test database connection.
   */
  public function test() {
    try {
      $database = \Drupal\Core\Database\Database::getConnection('default', 'amisafe');
      
      // Test simple query
      $count = $database->query('SELECT COUNT(*) FROM amisafe_raw_incidents')->fetchField();
      
      return new JsonResponse([
        'status' => 'success',
        'message' => 'Database connection working',
        'connection_class' => get_class($database),
        'record_count' => $count
      ]);
      
    } catch (\Exception $e) {
      return new JsonResponse([
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
      ]);
    }
  }

}