<?php

namespace Drupal\safety_calculator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Controller for Safety Calculator landing page.
 */
class LandingPageController extends ControllerBase {

  /**
   * Returns the landing page content.
   *
   * @return array
   *   A render array.
   */
  public function page() {
    $build = [];

    // Hero section - using Bootstrap bg-primary
    $build['hero'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bg-primary', 'text-white', 'py-5', 'mb-5']],
      'inner' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['container', 'text-center']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h1',
          '#value' => $this->t('Safety Calculator'),
          '#attributes' => ['class' => ['display-4', 'fw-bold', 'mb-3']],
        ],
        'subtitle' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Real-time risk assessment for informed safety decisions'),
          '#attributes' => ['class' => ['lead', 'mb-4']],
        ],
      ],
    ];

    // User type cards - Bootstrap grid
    $build['user_types'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container', 'my-5']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Choose Your Account Type'),
        '#attributes' => ['class' => ['text-center', 'mb-5']],
      ],
      'cards' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['row', 'g-4']],
      ],
    ];

    // Individual card
    $build['user_types']['cards']['individual'] = $this->buildUserTypeCard(
      'individual',
      '👤',
      $this->t('Individual'),
      $this->t('Quick safety checks for personal decisions'),
      [
        $this->t('Check safety before walking or jogging'),
        $this->t('Evaluate unfamiliar areas instantly'),
        $this->t('Free basic access'),
      ],
      Url::fromRoute('safety_calculator.questionnaire')
    );

    // Family card
    $build['user_types']['cards']['family'] = $this->buildUserTypeCard(
      'family',
      '👨‍👩‍👧‍👦',
      $this->t('Family'),
      $this->t('Protect loved ones with comprehensive monitoring'),
      [
        $this->t('Monitor multiple family members'),
        $this->t('Save important locations (home, school, work)'),
        $this->t('Receive safety alerts'),
        $this->t('Track history and patterns'),
      ],
      Url::fromRoute('user.register', [], ['query' => ['account_type' => 'family']]),
      'Coming Soon'
    );

    // Institution card
    $build['user_types']['cards']['institution'] = $this->buildUserTypeCard(
      'institution',
      '🏢',
      $this->t('Institution'),
      $this->t('Enterprise safety management and compliance'),
      [
        $this->t('Manage multiple facilities'),
        $this->t('Employee safety monitoring'),
        $this->t('Bulk location analysis'),
        $this->t('Compliance reports and analytics'),
        $this->t('API access for integration'),
      ],
      Url::fromRoute('user.register', [], ['query' => ['account_type' => 'institution']]),
      'Coming Soon'
    );

    // Features section - Bootstrap styling
    $build['features'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container', 'my-5', 'py-5', 'bg-light', 'rounded']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Powered by Real Data'),
        '#attributes' => ['class' => ['text-center', 'mb-4']],
      ],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Our safety calculations use comprehensive crime data and advanced geospatial analysis.'),
        '#attributes' => ['class' => ['text-center', 'text-muted', 'mb-4']],
      ],
      'stats' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('<strong>3.4+ Million</strong> crime incidents analyzed'),
          $this->t('<strong>413,000+</strong> location hexagons mapped'),
          $this->t('<strong>Resolution 13</strong> precision (building-level accuracy)'),
          $this->t('<strong>Real-time</strong> safety score calculations'),
        ],
        '#attributes' => ['class' => ['list-unstyled', 'row', 'text-center']],
      ],
    ];

    // CTA section - Bootstrap primary button
    $build['cta'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container', 'text-center', 'my-5', 'py-5']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Ready to Check Your Safety Score?'),
        '#attributes' => ['class' => ['mb-4']],
      ],
      'button' => [
        '#type' => 'link',
        '#title' => $this->t('Try Individual Calculator'),
        '#url' => Url::fromRoute('safety_calculator.questionnaire'),
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'btn-lg'],
        ],
      ],
    ];

    $build['#attached']['library'][] = 'safety_calculator/landing_page';

    return $build;
  }

  /**
   * Build a user type card using Bootstrap classes and card-forseti theme.
   */
  protected function buildUserTypeCard($id, $icon, $title, $description, array $features, $url, $badge = NULL) {
    $card = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-lg-4', 'col-md-6', 'mb-4']],
    ];

    $card_inner = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'card-forseti', 'h-100', 'text-center', 'p-4']],
    ];

    if ($badge) {
      $card_inner['badge'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $badge,
        '#attributes' => ['class' => ['badge', 'bg-warning', 'mb-3']],
      ];
    }

    $card_inner['icon'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $icon,
      '#attributes' => ['class' => ['fs-1', 'mb-3']],
    ];

    $card_inner['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h4',
      '#value' => $title,
      '#attributes' => ['class' => ['mb-3']],
    ];

    $card_inner['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $description,
      '#attributes' => ['class' => ['mb-4']],
    ];

    $card_inner['features'] = [
      '#theme' => 'item_list',
      '#items' => $features,
      '#attributes' => ['class' => ['list-unstyled', 'text-start', 'mb-4']],
    ];

    $card_inner['action'] = [
      '#type' => 'link',
      '#title' => $badge ? $this->t('Learn More') : $this->t('Get Started'),
      '#url' => $url,
      '#attributes' => [
        'class' => ['btn', $badge ? 'btn-secondary' : 'btn-primary', 'mt-auto'],
      ],
    ];

    if ($badge) {
      $card_inner['action']['#attributes']['class'][] = 'disabled';
    }

    $card['card_inner'] = $card_inner;

    return $card;
  }

}
