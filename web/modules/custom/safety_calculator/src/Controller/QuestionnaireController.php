<?php

namespace Drupal\safety_calculator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Controller for Safety Assessment Questionnaire.
 */
class QuestionnaireController extends ControllerBase {

  /**
   * Landing page for questionnaire.
   */
  public function landing() {
    $build = [];

    // Hero Section
    $build['hero'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bg-primary', 'text-white', 'py-5', 'mb-5']],
      'inner' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['container', 'text-center']],
        'title' => [
          '#markup' => '<h1 class="display-4 fw-bold mb-3">' . $this->t('Personal Safety Assessment') . '</h1>',
        ],
        'subtitle' => [
          '#markup' => '<p class="lead mb-4">' . $this->t('Get a comprehensive safety score based on the seven dimensions of community well-being') . '</p>',
        ],
        'cta' => [
          '#type' => 'link',
          '#title' => $this->t('Start Assessment'),
          '#url' => Url::fromRoute('safety_calculator.questionnaire_step', ['step' => 'safe']),
          '#attributes' => ['class' => ['btn', 'btn-light', 'btn-lg']],
        ],
      ],
    ];

    // 7 Dimensions Overview
    $build['dimensions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container', 'my-5']],
      'title' => [
        '#markup' => '<h2 class="text-center mb-5">' . $this->t('The Seven Dimensions of Safety') . '</h2>',
      ],
      'grid' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['row', 'row-cols-1', 'row-cols-md-2', 'row-cols-lg-3', 'g-4']],
      ],
    ];

    $dimensions = $this->getDimensions();
    foreach ($dimensions as $dimension) {
      $build['dimensions']['grid'][$dimension['id']] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['col']],
        'card' => [
          '#markup' => sprintf(
            '<div class="card card-forseti dimension-card h-100">
              <div class="d-flex align-items-start mb-2">
                <img src="%s" alt="%s" class="me-2">
                <div class="flex-grow-1">
                  <h6 class="text-cyan mb-0">%s</h6>
                  <small class="text-muted-light d-block">%s</small>
                </div>
              </div>
              <ul class="small text-muted mb-0">
                <li>%d questions</li>
                <li>~%d min</li>
              </ul>
            </div>',
            $dimension['icon'],
            $dimension['name'],
            $dimension['name'],
            $dimension['subtitle'],
            $dimension['question_count'],
            $dimension['estimated_minutes']
          ),
        ],
      ];
    }

    return $build;
  }

  /**
   * Get the 7 safety dimensions.
   */
  protected function getDimensions() {
    return [
      [
        'id' => 'safe',
        'name' => $this->t('Safe'),
        'subtitle' => $this->t('Security & Protection'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_safe.png',
        'question_count' => 30,
        'estimated_minutes' => 8,
      ],
      [
        'id' => 'energized',
        'name' => $this->t('Energized'),
        'subtitle' => $this->t('Vitality & Basic Needs'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_energized.png',
        'question_count' => 30,
        'estimated_minutes' => 8,
      ],
      [
        'id' => 'connected',
        'name' => $this->t('Connected'),
        'subtitle' => $this->t('Community & Belonging'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_connected.png',
        'question_count' => 30,
        'estimated_minutes' => 8,
      ],
      [
        'id' => 'free',
        'name' => $this->t('Free'),
        'subtitle' => $this->t('Autonomy & Rights'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_free.png',
        'question_count' => 30,
        'estimated_minutes' => 8,
      ],
      [
        'id' => 'capable',
        'name' => $this->t('Capable'),
        'subtitle' => $this->t('Mastery & Development'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_capable.png',
        'question_count' => 30,
        'estimated_minutes' => 8,
      ],
      [
        'id' => 'useful',
        'name' => $this->t('Useful'),
        'subtitle' => $this->t('Purpose & Contribution'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_useful.png',
        'question_count' => 30,
        'estimated_minutes' => 8,
      ],
      [
        'id' => 'whole',
        'name' => $this->t('Whole'),
        'subtitle' => $this->t('Holistic Health & Identity'),
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_whole.png',
        'question_count' => 30,
        'estimated_minutes' => 8,
      ],
    ];
  }

}
