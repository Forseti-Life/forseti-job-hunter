<?php

namespace Drupal\users_metrics\Plugin\views\style;

use Drupal\Core\Form\FormStateInterface;
use Drupal\users_metrics\Service\FieldLabelResolver;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Style plugin to render a D3.js chart.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "users_metrics_d3_chart",
 *   title = @Translation("D3 Chart (Users Metrics)"),
 *   help = @Translation("Render user metrics as a D3.js chart."),
 *   theme = "users_metrics_d3_chart",
 *   display_types = { "normal" }
 * )
 */
class D3Chart extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $usesFields = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $usesGrouping = TRUE;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The field label resolver service.
   *
   * @var \Drupal\users_metrics\Service\FieldLabelResolver
   */
  protected FieldLabelResolver $fieldLabelResolver;

  /**
   * Constructs a D3Chart object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\users_metrics\Service\FieldLabelResolver $field_label_resolver
   *   The field label resolver service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RequestStack $request_stack, FieldLabelResolver $field_label_resolver) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->requestStack = $request_stack;
    $this->fieldLabelResolver = $field_label_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack'),
      $container->get('users_metrics.field_label_resolver')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['chart_type'] = ['default' => 'spline'];
    $options['label_field'] = ['default' => ''];
    $options['data_field'] = ['default' => ''];
    $options['group_field'] = ['default' => ''];
    $options['x_axis_label_rotation'] = ['default' => 60];
    $options['legend_position'] = ['default' => 'right'];
    $options['show_data_labels'] = ['default' => FALSE];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $field_options = $this->getFieldOptions();

    $form['chart_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Chart type'),
      '#options' => [
        'bar' => $this->t('Bar (horizontal)'),
        'spline' => $this->t('Spline (smooth line)'),
        'line' => $this->t('Line'),
        'area' => $this->t('Area'),
      ],
      '#default_value' => $this->options['chart_type'],
      '#required' => TRUE,
    ];

    $form['label_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Label field'),
      '#description' => $this->t('Field to use for X-axis labels.'),
      '#options' => $field_options,
      '#default_value' => $this->options['label_field'],
      '#required' => TRUE,
    ];

    $form['data_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Data field'),
      '#description' => $this->t('Field containing the numeric values.'),
      '#options' => $field_options,
      '#default_value' => $this->options['data_field'],
      '#required' => TRUE,
    ];

    $form['group_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Group field (series)'),
      '#description' => $this->t('Field to group data into separate series.'),
      '#options' => ['' => $this->t('- None -')] + $field_options,
      '#default_value' => $this->options['group_field'],
    ];

    $form['x_axis_label_rotation'] = [
      '#type' => 'number',
      '#title' => $this->t('X-axis label rotation'),
      '#description' => $this->t('Rotation angle for X-axis labels in degrees.'),
      '#default_value' => $this->options['x_axis_label_rotation'],
      '#min' => 0,
      '#max' => 90,
    ];

    $form['legend_position'] = [
      '#type' => 'select',
      '#title' => $this->t('Legend position'),
      '#options' => [
        'right' => $this->t('Right'),
        'bottom' => $this->t('Bottom'),
        'top' => $this->t('Top'),
        'none' => $this->t('Hidden'),
      ],
      '#default_value' => $this->options['legend_position'],
    ];

    $form['show_data_labels'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show data labels'),
      '#description' => $this->t('Display values on data points.'),
      '#default_value' => $this->options['show_data_labels'],
    ];
  }

  /**
   * Get available field options.
   *
   * @return array
   *   An array of field labels keyed by field ID.
   */
  protected function getFieldOptions(): array {
    $options = [];
    $handlers = $this->displayHandler->getHandlers('field');
    foreach ($handlers as $id => $handler) {
      $options[$id] = $handler->adminLabel();
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    // Render fields first - required for grouping to work.
    if ($this->usesFields()) {
      $this->renderFields($this->view->result);
    }

    $chart_data = $this->buildChartData();
    $chart_id = 'users-metrics-chart-' . $this->view->storage->id() . '-' . $this->view->current_display;

    return [
      '#theme' => 'users_metrics_d3_chart',
      '#chart_id' => $chart_id,
      '#chart_type' => $this->options['chart_type'],
      '#attached' => [
        'library' => ['users_metrics/charts'],
        'drupalSettings' => [
          'usersMetricsCharts' => [
            $chart_id => [
              'type' => $this->options['chart_type'],
              'data' => $chart_data,
              'options' => [
                'xAxisLabelRotation' => (int) $this->options['x_axis_label_rotation'],
                'legendPosition' => $this->options['legend_position'],
                'showDataLabels' => (bool) $this->options['show_data_labels'],
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Build the chart data structure from view results.
   *
   * @return array
   *   The chart data.
   */
  protected function buildChartData(): array {
    $label_field = $this->options['label_field'];
    $data_field = $this->options['data_field'];
    $group_field = $this->options['group_field'];

    // If group_field is empty, try to get it from the request (for dynamic
    // grouping via exposed filters).
    if (empty($group_field)) {
      $request = $this->requestStack->getCurrentRequest();
      $group_field = $request ? $request->query->get('group_field', '') : '';
    }

    $series = [];
    $labels = [];

    // Process grouping if enabled.
    if ($this->usesGrouping() && !empty($this->options['grouping'])) {
      $sets = $this->renderGrouping($this->view->result, $this->options['grouping'], TRUE);
      $this->processGroupingSets($sets, $series, $labels, $label_field, $data_field);
    }
    else {
      // No grouping - single series.
      $series_name = 'default';
      if (!empty($group_field)) {
        $series = [];
        foreach ($this->view->result as $row_index => $row) {
          $group_label = $this->getRenderedFieldValue($row_index, $group_field, $row);
          $label = $this->getRenderedFieldValue($row_index, $label_field, $row);
          $value = $this->getRenderedFieldValue($row_index, $data_field, $row);

          if (!in_array($label, $labels)) {
            $labels[] = $label;
          }

          if (!isset($series[$group_label])) {
            $series[$group_label] = [];
          }
          $series[$group_label][] = [
            'label' => $label,
            'value' => (float) $value,
          ];
        }
      }
      else {
        $series[$series_name] = [];
        foreach ($this->view->result as $row_index => $row) {
          $label = $this->getRenderedFieldValue($row_index, $label_field, $row);
          $value = $this->getRenderedFieldValue($row_index, $data_field, $row);
          $labels[] = $label;
          $series[$series_name][] = [
            'label' => $label,
            'value' => (float) $value,
          ];
        }
      }
    }

    return [
      'labels' => $labels,
      'series' => $series,
    ];
  }

  /**
   * Process grouping sets recursively.
   *
   * @param array $sets
   *   The grouping sets from renderGrouping().
   * @param array $series
   *   The series array to populate (passed by reference).
   * @param array $labels
   *   The labels array to populate (passed by reference).
   * @param string $label_field
   *   The label field ID.
   * @param string $data_field
   *   The data field ID.
   */
  protected function processGroupingSets(array $sets, array &$series, array &$labels, string $label_field, string $data_field): void {
    foreach ($sets as $set) {
      // Get the group label.
      $group_label = isset($set['group']) ? (string) $set['group'] : 'default';

      // Check if rows contains more nested groups or actual row data.
      if (!isset($set['rows']) || !is_array($set['rows'])) {
        continue;
      }

      // Check if this is a nested grouping or final rows.
      $first_row = reset($set['rows']);
      if (is_array($first_row) && isset($first_row['group'])) {
        // Nested grouping - recurse.
        $this->processGroupingSets($set['rows'], $series, $labels, $label_field, $data_field);
      }
      else {
        // Final rows - process them.
        if (!isset($series[$group_label])) {
          $series[$group_label] = [];
        }

        foreach ($set['rows'] as $row_index => $row) {
          $label = $this->getRenderedFieldValue($row_index, $label_field, $row);
          $value = $this->getRenderedFieldValue($row_index, $data_field, $row);

          if (!in_array($label, $labels)) {
            $labels[] = $label;
          }

          $series[$group_label][] = [
            'label' => $label,
            'value' => (float) $value,
          ];
        }
      }
    }
  }

  /**
   * Get a rendered field value for a row.
   *
   * @param int $row_index
   *   The row index.
   * @param string $field_id
   *   The field ID.
   * @param object $row
   *   The result row.
   *
   * @return string
   *   The rendered field value.
   */
  protected function getRenderedFieldValue(int $row_index, string $field_id, $row): string {
    // First try the pre-rendered field value from renderFields().
    if (isset($this->view->field[$field_id]) && isset($this->rendered_fields[$row_index][$field_id])) {
      $value = $this->rendered_fields[$row_index][$field_id];
      // Strip HTML tags and trim.
      $value = strip_tags((string) $value);
      return trim($value);
    }

    // Fallback: try to get value directly from the query result row.
    // This handles dynamically added fields via hook_views_query_alter().
    if (isset($row->{$field_id})) {
      $value = trim((string) $row->{$field_id});
      // Use the field label resolver service to get the label.
      return $this->fieldLabelResolver->resolveLabel($field_id, $value);
    }

    return '';
  }

}
