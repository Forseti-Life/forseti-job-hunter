<?php

namespace Drupal\safety_calculator\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\safety_calculator\SafetyCalculatorService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for individual safety check.
 */
class IndividualCheckForm extends FormBase {

  /**
   * The safety calculator service.
   *
   * @var \Drupal\safety_calculator\SafetyCalculatorService
   */
  protected $safetyCalculator;

  /**
   * Constructs a new IndividualCheckForm.
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
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'safety_calculator_individual_check';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Wrap form in Bootstrap container and card-forseti
    $form['#prefix'] = '<div class="container my-5"><div class="card card-forseti p-4" id="safety-check-wrapper">';
    $form['#suffix'] = '</div></div>';

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<div class="text-center mb-4"><h2>' . $this->t('Check Safety Score') . '</h2><p class="lead">' . $this->t('Enter a location to get an instant safety assessment.') . '</p></div>',
    ];

    $form['location_group'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Location'),
      '#attributes' => ['class' => ['mb-4']],
    ];

    $form['location_group']['address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Address or Place'),
      '#description' => $this->t('Enter a street address, landmark, or place name (e.g., "1234 Market St, Philadelphia" or "City Hall")'),
      '#placeholder' => $this->t('Enter address...'),
      '#size' => 60,
      '#attributes' => ['class' => ['form-control']],
    ];

    $form['location_group']['coordinates'] = [
      '#type' => 'details',
      '#title' => $this->t('Or use coordinates'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['mb-3']],
    ];

    $form['location_group']['coordinates']['latitude'] = [
      '#type' => 'number',
      '#title' => $this->t('Latitude'),
      '#step' => 0.0000001,
      '#min' => -90,
      '#max' => 90,
      '#placeholder' => '39.9526',
      '#attributes' => ['class' => ['form-control']],
    ];

    $form['location_group']['coordinates']['longitude'] = [
      '#type' => 'number',
      '#title' => $this->t('Longitude'),
      '#step' => 0.0000001,
      '#min' => -180,
      '#max' => 180,
      '#placeholder' => '-75.1652',
      '#attributes' => ['class' => ['form-control']],
    ];

    $form['location_group']['use_current'] = [
      '#type' => 'button',
      '#value' => $this->t('📍 Use My Current Location'),
      '#attributes' => [
        'class' => ['btn', 'btn-secondary', 'mt-2', 'use-current-location-btn'],
        'onclick' => 'return false;',
      ],
    ];

    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Options'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['mb-4']],
    ];

    $form['options']['resolution'] = [
      '#type' => 'select',
      '#title' => $this->t('Precision Level'),
      '#options' => [
        11 => $this->t('11 - Neighborhood (~700m)'),
        12 => $this->t('12 - Block (~270m)'),
        13 => $this->t('13 - Building (~100m) - Recommended'),
        14 => $this->t('14 - Room (~40m)'),
      ],
      '#default_value' => 13,
      '#description' => $this->t('Higher precision provides more detailed analysis but may include fewer data points.'),
      '#attributes' => ['class' => ['form-select']],
    ];

    $form['options']['radius'] = [
      '#type' => 'select',
      '#title' => $this->t('Analysis Radius'),
      '#options' => [
        0 => $this->t('0 - Single hexagon only'),
        1 => $this->t('1 - Immediate neighbors (7 hexagons) - Recommended'),
        2 => $this->t('2 - Extended area (19 hexagons)'),
        3 => $this->t('3 - Wide area (37 hexagons)'),
      ],
      '#default_value' => 1,
      '#description' => $this->t('Larger radius provides more context but may dilute specific location risk.'),
      '#attributes' => ['class' => ['form-select']],
    ];

    $form['options']['time_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Time Period'),
      '#options' => [
        '' => $this->t('All available data'),
        'last_30_days' => $this->t('Last 30 days'),
        'last_year' => $this->t('Last year'),
      ],
      '#default_value' => '',
      '#description' => $this->t('Filter crimes by recency (optional).'),
      '#attributes' => ['class' => ['form-select']],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['text-center', 'mt-4']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Calculate Safety Score'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['btn', 'btn-primary', 'btn-lg', 'calculate-btn']],
    ];

    // Display results if available
    if ($form_state->has('results')) {
      $form['results'] = $this->buildResults($form_state->get('results'));
    }

    $form['#attached']['library'][] = 'safety_calculator/individual_check';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $address = $form_state->getValue('address');
    $latitude = $form_state->getValue('latitude');
    $longitude = $form_state->getValue('longitude');

    // Require either address or coordinates
    if (empty($address) && (empty($latitude) || empty($longitude))) {
      $form_state->setErrorByName('address', $this->t('Please provide either an address or coordinates.'));
      return;
    }

    // If coordinates provided, validate them
    if (!empty($latitude) || !empty($longitude)) {
      if (empty($latitude) || empty($longitude)) {
        $form_state->setErrorByName('latitude', $this->t('Both latitude and longitude are required.'));
        return;
      }

      if ($latitude < -90 || $latitude > 90) {
        $form_state->setErrorByName('latitude', $this->t('Latitude must be between -90 and 90.'));
      }

      if ($longitude < -180 || $longitude > 180) {
        $form_state->setErrorByName('longitude', $this->t('Longitude must be between -180 and 180.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $address = $form_state->getValue('address');
    $latitude = $form_state->getValue('latitude');
    $longitude = $form_state->getValue('longitude');

    // If address provided, geocode it (simplified for now)
    // In production, integrate with geocoding service
    if (!empty($address) && (empty($latitude) || empty($longitude))) {
      // For now, show message that geocoding is needed
      $this->messenger()->addWarning($this->t('Address geocoding will be implemented. Please use coordinates for now.'));
      return;
    }

    // Get options
    $resolution = $form_state->getValue('resolution');
    $radius = $form_state->getValue('radius');
    $time_filter = $form_state->getValue('time_filter');

    $options = [
      'radius' => $radius,
    ];

    if (!empty($time_filter)) {
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

      // Store location info in results
      $result['latitude'] = $latitude;
      $result['longitude'] = $longitude;
      $result['resolution'] = $resolution;

      // Store results and rebuild form
      $form_state->set('results', $result);
      $form_state->setRebuild(TRUE);

      $this->messenger()->addStatus($this->t('Safety score calculated successfully!'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error calculating safety score: @message', [
        '@message' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Build results display using Bootstrap and Forseti theme classes.
   */
  protected function buildResults(array $results) {
    $risk_colors = [
      'low' => 'success',
      'moderate' => 'warning',
      'high' => 'orange',
      'critical' => 'danger',
    ];

    $risk_icons = [
      'low' => '✅',
      'moderate' => '⚠️',
      'high' => '🔶',
      'critical' => '🚨',
    ];

    $risk_level = $results['risk_level'] ?? 'unknown';
    $color_class = $risk_colors[$risk_level] ?? 'secondary';
    $icon = $risk_icons[$risk_level] ?? '❓';

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mt-5', 'pt-4', 'border-top']],
    ];

    // Score Display Card
    $build['score_card'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['text-center', 'mb-4']],
      'icon' => [
        '#markup' => '<div class=\"fs-1 mb-3\">' . $icon . '</div>',
      ],
      'score' => [
        '#markup' => '<h1 class=\"display-1 text-' . $color_class . ' mb-2\">' . round($results['score'], 1) . '</h1>',
      ],
      'label' => [
        '#markup' => '<p class=\"lead mb-3\">Safety Score</p>',
      ],
      'risk' => [
        '#markup' => '<span class=\"badge bg-' . $color_class . ' fs-5 px-4 py-2\">' . strtoupper($risk_level) . ' RISK</span>',
      ],
    ];

    // Details Section
    $build['details'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'mt-4', 'g-4']],
    ];

    $build['details']['stats'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-6']],
      'content' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Analysis Details'),
        '#items' => [
          $this->t('Crime incidents: <strong>@count</strong>', ['@count' => $results['crime_count']]),
          $this->t('Hexagons analyzed: <strong>@count</strong>', ['@count' => $results['hexagons_analyzed']]),
          $this->t('Location: <strong>@lat, @lon</strong>', [
            '@lat' => round($results['latitude'], 4),
            '@lon' => round($results['longitude'], 4),
          ]),
        ],
        '#attributes' => ['class' => ['list-unstyled']],
      ],
    ];

    if (!empty($results['details'])) {
      $crime_items = [];
      foreach ($results['details'] as $type => $count) {
        $crime_items[] = $this->t('@type: <strong>@count</strong>', [
          '@type' => ucfirst(str_replace('_', ' ', $type)),
          '@count' => $count,
        ]);
      }

      $build['details']['crimes'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['col-md-6']],
        'content' => [
          '#theme' => 'item_list',
          '#title' => $this->t('Crime Breakdown'),
          '#items' => $crime_items,
          '#attributes' => ['class' => ['list-unstyled']],
        ],
      ];
    }

    return $build;
  }

}
