<?php

namespace Drupal\safety_calculator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\safety_calculator\SafetyCalculatorService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Safety Calculator API endpoints.
 */
class SafetyCalculatorController extends ControllerBase {

  /**
   * The safety calculator service.
   *
   * @var \Drupal\safety_calculator\SafetyCalculatorService
   */
  protected $safetyCalculator;

  /**
   * Constructs a SafetyCalculatorController object.
   *
   * @param \Drupal\safety_calculator\SafetyCalculatorService $safety_calculator
   *   The safety calculator service.
   */
  public function __construct(SafetyCalculatorService $safety_calculator) {
    $this->safetyCalculator = $safety_calculator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('safety_calculator.calculator')
    );
  }

  /**
   * Calculate safety score for a location.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with safety score data.
   */
  public function calculate(Request $request) {
    // Get parameters
    $latitude = $request->query->get('lat') ?? $request->request->get('lat');
    $longitude = $request->query->get('lon') ?? $request->request->get('lon');
    $resolution = $request->query->get('resolution', 13);
    $radius = $request->query->get('radius', 1);
    $time_filter = $request->query->get('time_filter');

    // Validate required parameters
    if (empty($latitude) || empty($longitude)) {
      return new JsonResponse([
        'error' => 'Missing required parameters: lat and lon',
        'status' => 'error',
      ], 400);
    }

    // Validate coordinates
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
      return new JsonResponse([
        'error' => 'Invalid coordinates',
        'status' => 'error',
      ], 400);
    }

    if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
      return new JsonResponse([
        'error' => 'Coordinates out of valid range',
        'status' => 'error',
      ], 400);
    }

    // Build options
    $options = [
      'radius' => (int) $radius,
    ];

    if ($time_filter) {
      $options['time_filter'] = $time_filter;
    }

    // Calculate safety score
    try {
      $result = $this->safetyCalculator->calculateSafetyScore(
        (float) $latitude,
        (float) $longitude,
        (int) $resolution,
        $options
      );

      return new JsonResponse([
        'status' => 'success',
        'data' => $result,
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('safety_calculator')->error('Error calculating safety score: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Error calculating safety score',
        'status' => 'error',
      ], 500);
    }
  }

}
