<?php

declare(strict_types=1);

namespace Drupal\safety_calculator\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for editing individual metrics by dimension.
 */
class IndividualMetricsEditForm extends FormBase {

  /**
   * The individual metrics repository.
   *
   * @var \Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface
   */
  protected IndividualMetricsRepositoryInterface $repository;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * Constructs an IndividualMetricsEditForm object.
   *
   * @param \Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface $repository
   *   The individual metrics repository.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(IndividualMetricsRepositoryInterface $repository, AccountInterface $currentUser) {
    $this->repository = $repository;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('safety_calculator.individual_metrics_repository'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'individual_metrics_edit_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, UserInterface $user = NULL, string $dimension = NULL) {
    if (!$user || !$dimension) {
      $this->messenger()->addError($this->t('Invalid parameters.'));
      return $form;
    }

    // Store user and dimension for submit handler.
    $form_state->set('user', $user);
    $form_state->set('dimension', $dimension);

    $userId = (int) $user->id();

    // Get or create assessment.
    $assessment = $this->repository->getLatestAssessment($userId);
    if (!$assessment || $assessment['status'] === 'completed') {
      // Create new assessment.
      $assessmentId = $this->repository->createAssessment($userId);
      $assessment = $this->repository->getAssessment($assessmentId);
    }

    $form_state->set('assessment_id', (int) $assessment['id']);

    // Get metrics for this dimension.
    $metrics = $this->repository->getMetricsByDimension($dimension);

    if (empty($metrics)) {
      $form['message'] = [
        '#markup' => $this->t('No metrics found for dimension: @dimension', ['@dimension' => $dimension]),
      ];
      return $form;
    }

    // Get existing responses.
    $existingResponses = $this->repository->getResponsesByDimension($userId, (int) $assessment['id']);
    $responseValues = [];
    if (isset($existingResponses[$dimension])) {
      foreach ($existingResponses[$dimension] as $response) {
        $responseValues[$response['metric_id']] = $response['response_value'];
      }
    }

    // Group metrics by category.
    $categorizedMetrics = [];
    foreach ($metrics as $metric) {
      $category = $metric['category'];
      if (!isset($categorizedMetrics[$category])) {
        $categorizedMetrics[$category] = [];
      }
      $categorizedMetrics[$category][] = $metric;
    }

    // Build form.
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'safety_calculator/individual-metrics-edit';

    $form['dimension_title'] = [
      '#markup' => '<h2>' . $this->t('Edit @dimension Metrics', ['@dimension' => $dimension]) . '</h2>',
    ];

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Update your responses for the @dimension dimension. All fields are optional.', ['@dimension' => $dimension]) . '</p>',
    ];

    $form['metrics'] = [
      '#type' => 'container',
    ];

    // Build form fields for each category and metric.
    foreach ($categorizedMetrics as $category => $categoryMetrics) {
      $form['metrics'][$category] = [
        '#type' => 'details',
        '#title' => $category,
        '#open' => TRUE,
      ];

      foreach ($categoryMetrics as $metric) {
        $metricId = (int) $metric['id'];
        $fieldName = 'metric_' . $metricId;
        $defaultValue = $responseValues[$metricId] ?? NULL;

        // Parse validation rules.
        $validationRules = !empty($metric['validation_rules']) ? json_decode($metric['validation_rules'], TRUE) : [];

        // Build field based on data type.
        $field = $this->buildMetricField($metric, $defaultValue, $validationRules);

        $form['metrics'][$category][$fieldName] = $field;
      }
    }

    // Actions.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Changes'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('safety_calculator.individual_metrics_profile', ['user' => $userId]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * Build a form field for a metric based on its data type.
   *
   * @param array $metric
   *   The metric definition.
   * @param mixed $defaultValue
   *   The default value.
   * @param array $validationRules
   *   The validation rules.
   *
   * @return array
   *   The form field.
   */
  protected function buildMetricField(array $metric, $defaultValue, array $validationRules): array {
    $dataType = $metric['data_type'];
    $description = $metric['metric_description'];

    $field = [
      '#title' => $metric['metric_name'],
      '#description' => $description,
      '#default_value' => $defaultValue,
      '#required' => FALSE,
    ];

    switch ($dataType) {
      case 'numeric':
        $field['#type'] = 'number';
        $field['#step'] = 'any';
        if (isset($validationRules['min'])) {
          $field['#min'] = $validationRules['min'];
        }
        if (isset($validationRules['max'])) {
          $field['#max'] = $validationRules['max'];
        }
        break;

      case 'boolean':
        $field['#type'] = 'checkbox';
        $field['#default_value'] = $defaultValue ? 1 : 0;
        break;

      case 'scale':
        $field['#type'] = 'range';
        $field['#min'] = $validationRules['min'] ?? 0;
        $field['#max'] = $validationRules['max'] ?? 10;
        $field['#step'] = $validationRules['step'] ?? 1;
        $field['#attributes']['class'][] = 'metric-scale-slider';
        // Add a display element to show the current value.
        $field['#suffix'] = '<output class="scale-value">' . ($defaultValue ?? $field['#min']) . '</output>';
        break;

      case 'select':
        $field['#type'] = 'select';
        $field['#empty_option'] = $this->t('- Select -');
        if (isset($validationRules['options'])) {
          $field['#options'] = array_combine($validationRules['options'], $validationRules['options']);
        }
        break;

      case 'text':
      default:
        $field['#type'] = 'textfield';
        if (isset($validationRules['max_length'])) {
          $field['#maxlength'] = $validationRules['max_length'];
        }
        break;
    }

    return $field;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $user = $form_state->get('user');
    $dimension = $form_state->get('dimension');
    $assessmentId = $form_state->get('assessment_id');

    $metrics = $this->repository->getMetricsByDimension($dimension);
    $metricsById = [];
    foreach ($metrics as $metric) {
      $metricsById[$metric['id']] = $metric;
    }

    // Validate each metric value.
    $values = $form_state->getValue('metrics');
    if ($values) {
      foreach ($values as $category => $categoryValues) {
        foreach ($categoryValues as $fieldName => $value) {
          if (strpos($fieldName, 'metric_') !== 0) {
            continue;
          }

          $metricId = (int) str_replace('metric_', '', $fieldName);
          
          // Skip empty values (all fields are optional).
          if ($value === '' || $value === NULL) {
            continue;
          }

          if (isset($metricsById[$metricId])) {
            try {
              $this->repository->validateResponse($metricId, $value);
            }
            catch (\InvalidArgumentException $e) {
              $form_state->setErrorByName(
                "metrics][$category][$fieldName",
                $this->t('Validation error for @metric: @error', [
                  '@metric' => $metricsById[$metricId]['metric_name'],
                  '@error' => $e->getMessage(),
                ])
              );
            }
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user = $form_state->get('user');
    $dimension = $form_state->get('dimension');
    $assessmentId = $form_state->get('assessment_id');
    $userId = (int) $user->id();

    $metrics = $this->repository->getMetricsByDimension($dimension);
    $metricsById = [];
    foreach ($metrics as $metric) {
      $metricsById[$metric['id']] = $metric;
    }

    $savedCount = 0;
    $errorCount = 0;

    // Process submitted values.
    $values = $form_state->getValue('metrics');
    if ($values) {
      foreach ($values as $category => $categoryValues) {
        foreach ($categoryValues as $fieldName => $value) {
          if (strpos($fieldName, 'metric_') !== 0) {
            continue;
          }

          $metricId = (int) str_replace('metric_', '', $fieldName);

          // Skip empty values.
          if ($value === '' || $value === NULL) {
            continue;
          }

          if (isset($metricsById[$metricId])) {
            try {
              $this->repository->saveResponse($userId, $assessmentId, $metricId, $value);
              $savedCount++;
            }
            catch (\Exception $e) {
              $this->messenger()->addError(
                $this->t('Error saving @metric: @error', [
                  '@metric' => $metricsById[$metricId]['metric_name'],
                  '@error' => $e->getMessage(),
                ])
              );
              $errorCount++;
            }
          }
        }
      }
    }

    // Show appropriate message.
    if ($savedCount > 0) {
      $this->messenger()->addStatus(
        $this->t('Successfully saved @count metric response(s) for @dimension.', [
          '@count' => $savedCount,
          '@dimension' => $dimension,
        ])
      );
    }

    if ($errorCount > 0) {
      $this->messenger()->addWarning(
        $this->t('Failed to save @count metric response(s). Please review the errors above.', [
          '@count' => $errorCount,
        ])
      );
    }

    if ($savedCount === 0 && $errorCount === 0) {
      $this->messenger()->addWarning($this->t('No changes were made.'));
    }

    // Redirect back to profile.
    $form_state->setRedirect('safety_calculator.individual_metrics_profile', ['user' => $userId]);
  }

}
