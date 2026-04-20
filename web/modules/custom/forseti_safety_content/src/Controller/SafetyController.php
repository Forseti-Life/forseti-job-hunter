<?php

namespace Drupal\forseti_safety_content\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\forseti_safety_content\Service\SafetyDimensionsServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Safety-related pages.
 */
class SafetyController extends ControllerBase {

  /**
   * The safety dimensions service.
   *
   * @var \Drupal\forseti_safety_content\Service\SafetyDimensionsServiceInterface
   */
  protected $safetyDimensionsService;

  /**
   * Constructs a SafetyController object.
   *
   * @param \Drupal\forseti_safety_content\Service\SafetyDimensionsServiceInterface $safety_dimensions_service
   *   The safety dimensions service.
   */
  public function __construct(SafetyDimensionsServiceInterface $safety_dimensions_service) {
    $this->safetyDimensionsService = $safety_dimensions_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('forseti_safety_content.safety_dimensions')
    );
  }

  /**
   * Safety Factors page.
   */
  public function safetyFactors() {
    return [
      '#theme' => 'forseti_page_safety_factors',
      '#title' => $this->t('Safety Factors'),
      '#lead' => $this->t('Forseti evaluates safety using a holistic framework aligned with Maslow\'s Hierarchy of Needs. Each dimension represents essential elements that contribute to community well-being and personal security.'),
      '#assessment_alert' => [
        'title' => $this->t('Comprehensive Assessment'),
        'type' => 'info',
        'content' => $this->t('Each dimension is measured using multiple data sources and indicators to provide a complete picture of community safety and wellness.'),
      ],
      '#dimensions_title' => $this->t('The Seven Dimensions of Safety'),
      '#dimensions_intro' => $this->t('Click on each dimension to explore the factors we monitor:'),
      '#dimensions' => $this->safetyDimensionsService->getSafetyDimensions(),
      '#how_forseti_uses' => [
        'title' => $this->t('How Forseti Uses These Dimensions'),
        'items' => [
          $this->t('We analyze these seven dimensions across every neighborhood in Philadelphia using data from multiple sources.'),
          $this->t('Our AI combines real-time feeds with historical patterns to assess current safety conditions.'),
          $this->t('When you enter an area with concerning indicators across multiple dimensions, we send contextual safety alerts.'),
          $this->t('Understanding the full picture helps you make informed decisions about your safety.'),
        ],
      ],
      '#cta_buttons' => [
        [
          'url' => '/safety-map',
          'label' => $this->t('View Safety Map'),
          'style' => 'primary',
        ],
        [
          'url' => '/mobile-app',
          'label' => $this->t('Get Mobile App'),
          'style' => 'outline-primary',
        ],
      ],
      '#attached' => [
        'library' => [
          'forseti_safety_content/style',
        ],
      ],
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['url'],
      ],
    ];
  }

}
