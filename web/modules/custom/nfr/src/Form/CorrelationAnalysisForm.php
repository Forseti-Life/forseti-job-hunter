<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for running correlation analysis between variables.
 */
class CorrelationAnalysisForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a CorrelationAnalysisForm object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database = NULL) {
    if ($database === NULL) {
      $database = \Drupal::database();
    }
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'nfr_correlation_analysis_form';
  }

  /**
   * Get all available variables for correlation analysis.
   */
  private function getAvailableVariables(): array {
    return [
      'Demographics' => [
        'age_at_enrollment' => 'Age at Enrollment',
        'sex' => 'Sex/Gender',
        'education_level' => 'Education Level',
        'marital_status' => 'Marital Status',
      ],
      'Health Metrics' => [
        'height_inches' => 'Height (inches)',
        'weight_pounds' => 'Weight (pounds)',
        'bmi' => 'Body Mass Index (BMI)',
        'bmi_category' => 'BMI Category',
        'smoking_status' => 'Smoking Status',
        'alcohol_consumption' => 'Alcohol Consumption',
        'exercise_frequency' => 'Exercise Frequency',
      ],
      'Work History' => [
        'total_fire_departments' => 'Total Fire Departments Worked',
        'total_years_service' => 'Total Years of Service',
        'years_career_firefighter' => 'Years as Career Firefighter',
        'years_volunteer' => 'Years as Volunteer',
        'years_paid_on_call' => 'Years as Paid-on-Call',
        'highest_rank' => 'Highest Rank Achieved',
        'had_leadership_role' => 'Had Leadership Role',
        'responded_to_incidents' => 'Responded to Incidents',
        'num_states_worked' => 'Number of States Worked',
      ],
      'Incident Exposure' => [
        'total_structure_fires' => 'Total Structure Fires (All)',
        'total_structure_residential_fires' => 'Total Residential Fires',
        'total_structure_commercial_fires' => 'Total Commercial Fires',
        'total_vehicle_fires' => 'Total Vehicle Fires',
        'total_wildland_fires' => 'Total Wildland Fires',
        'total_hazmat_incidents' => 'Total Hazmat Incidents',
        'total_training_fires' => 'Total Training Fires',
        'total_medical_ems_calls' => 'Total Medical/EMS Calls',
        'total_technical_rescue' => 'Total Technical Rescue',
        'avg_structure_fires_per_year' => 'Avg Structure Fires per Year',
        'avg_incidents_per_year' => 'Avg All Incidents per Year',
        'incident_diversity_score' => 'Incident Type Diversity',
        'years_high_structure_exposure' => 'Years High Structure Exposure',
      ],
      'Health Outcomes' => [
        'has_cancer_diagnosis' => 'Has Cancer Diagnosis',
        'num_cancer_diagnoses' => 'Number of Cancer Diagnoses',
        'age_first_cancer_diagnosis' => 'Age at First Cancer Diagnosis',
        'years_service_before_first_cancer' => 'Years Service Before Cancer',
        'has_lung_cancer' => 'Has Lung Cancer',
        'has_colorectal_cancer' => 'Has Colorectal Cancer',
        'has_prostate_cancer' => 'Has Prostate Cancer',
        'has_breast_cancer' => 'Has Breast Cancer',
        'has_bladder_cancer' => 'Has Bladder Cancer',
        'has_kidney_cancer' => 'Has Kidney Cancer',
        'has_skin_cancer' => 'Has Skin Cancer',
        'family_cancer_count' => 'Family Cancer History Count',
      ],
      'Data Quality' => [
        'data_quality_score' => 'Data Quality Score',
        'years_since_enrollment' => 'Years Since Enrollment',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $variables = $this->getAvailableVariables();

    // Flatten for select options
    $options = [];
    foreach ($variables as $group => $vars) {
      foreach ($vars as $key => $label) {
        $options[$group][$key] = $label;
      }
    }

    $form['description'] = [
      '#markup' => '<div class="correlation-intro"><p>Analyze relationships between any two variables in the NFR dataset. Select your variables below to calculate correlation coefficients and view scatter plots.</p></div>',
    ];

    $form['variable_x'] = [
      '#type' => 'select',
      '#title' => $this->t('X-Axis Variable (Independent)'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => 'total_years_service',
      '#description' => $this->t('Typically the exposure or predictor variable.'),
    ];

    $form['variable_y'] = [
      '#type' => 'select',
      '#title' => $this->t('Y-Axis Variable (Dependent)'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => 'has_cancer_diagnosis',
      '#description' => $this->t('Typically the outcome variable.'),
    ];

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filters (Optional)'),
      '#open' => FALSE,
    ];

    $form['filters']['min_quality_score'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum Data Quality Score'),
      '#min' => 0,
      '#max' => 100,
      '#default_value' => 70,
      '#description' => $this->t('Only include records with quality score above this threshold.'),
    ];

    $form['filters']['sex_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Sex/Gender Filter'),
      '#options' => [
        '' => '- All -',
        'M' => 'Male',
        'F' => 'Female',
      ],
      '#description' => $this->t('Filter to specific sex/gender.'),
    ];

    $form['filters']['cancer_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Cancer Diagnosis Filter'),
      '#options' => [
        '' => '- All -',
        '1' => 'Cancer Diagnosis Only',
        '0' => 'No Cancer Diagnosis',
      ],
      '#description' => $this->t('Filter by cancer diagnosis status.'),
    ];

    $form['analysis_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Correlation Method'),
      '#options' => [
        'pearson' => $this->t('Pearson (linear correlation for continuous variables)'),
        'spearman' => $this->t('Spearman (rank correlation for ordinal or non-linear)'),
      ],
      '#default_value' => 'pearson',
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run Analysis'),
      '#button_type' => 'primary',
    ];

    $form['actions']['export'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export Data (CSV)'),
      '#submit' => ['::exportData'],
    ];

    // Display results if analysis has been run
    if ($form_state->get('results')) {
      $form['results'] = $this->buildResults($form_state);
    }

    $form['#attached']['library'][] = 'nfr/correlation-analysis';

    return $form;
  }

  /**
   * Build results display.
   */
  private function buildResults(FormStateInterface $form_state): array {
    $results = $form_state->get('results');
    $var_x = $form_state->get('variable_x');
    $var_y = $form_state->get('variable_y');
    $method = $form_state->get('analysis_type');

    $variables = $this->getAvailableVariables();
    $label_x = $this->getVariableLabel($var_x, $variables);
    $label_y = $this->getVariableLabel($var_y, $variables);

    $markup = '<div class="correlation-results">';
    $markup .= '<h2>Analysis Results</h2>';
    $markup .= '<div class="result-summary">';
    $markup .= '<p><strong>Variables:</strong> ' . htmlspecialchars($label_x) . ' (X) vs ' . htmlspecialchars($label_y) . ' (Y)</p>';
    $markup .= '<p><strong>Method:</strong> ' . ucfirst($method) . ' Correlation</p>';
    $markup .= '<p><strong>Sample Size:</strong> ' . number_format($results['n']) . ' records</p>';
    $markup .= '</div>';

    $markup .= '<div class="correlation-coefficient">';
    $markup .= '<h3>Correlation Coefficient</h3>';
    $markup .= '<div class="coef-value">';
    $markup .= '<span class="coef-number">' . number_format($results['correlation'], 4) . '</span>';
    $markup .= '<span class="coef-interpretation">' . $this->interpretCorrelation($results['correlation']) . '</span>';
    $markup .= '</div>';
    
    if (isset($results['p_value'])) {
      $significance = $results['p_value'] < 0.001 ? 'p < 0.001' : 'p = ' . number_format($results['p_value'], 4);
      $is_significant = $results['p_value'] < 0.05;
      $markup .= '<p class="significance ' . ($is_significant ? 'sig-yes' : 'sig-no') . '">';
      $markup .= '<strong>Statistical Significance:</strong> ' . $significance;
      $markup .= ' (' . ($is_significant ? 'Statistically significant' : 'Not statistically significant') . ')';
      $markup .= '</p>';
    }
    $markup .= '</div>';

    // Summary statistics
    $markup .= '<div class="summary-stats">';
    $markup .= '<h3>Summary Statistics</h3>';
    $markup .= '<table class="stats-table">';
    $markup .= '<tr><th>Variable</th><th>Mean</th><th>Median</th><th>Std Dev</th><th>Min</th><th>Max</th></tr>';
    $markup .= '<tr>';
    $markup .= '<td>' . htmlspecialchars($label_x) . '</td>';
    $markup .= '<td>' . number_format($results['x_mean'], 2) . '</td>';
    $markup .= '<td>' . number_format($results['x_median'], 2) . '</td>';
    $markup .= '<td>' . number_format($results['x_stddev'], 2) . '</td>';
    $markup .= '<td>' . number_format($results['x_min'], 2) . '</td>';
    $markup .= '<td>' . number_format($results['x_max'], 2) . '</td>';
    $markup .= '</tr>';
    $markup .= '<tr>';
    $markup .= '<td>' . htmlspecialchars($label_y) . '</td>';
    $markup .= '<td>' . number_format($results['y_mean'], 2) . '</td>';
    $markup .= '<td>' . number_format($results['y_median'], 2) . '</td>';
    $markup .= '<td>' . number_format($results['y_stddev'], 2) . '</td>';
    $markup .= '<td>' . number_format($results['y_min'], 2) . '</td>';
    $markup .= '<td>' . number_format($results['y_max'], 2) . '</td>';
    $markup .= '</tr>';
    $markup .= '</table>';
    $markup .= '</div>';

    // Data preview
    $markup .= '<div class="data-preview">';
    $markup .= '<h3>Data Preview (First 10 Records)</h3>';
    $markup .= '<table class="preview-table">';
    $markup .= '<tr><th>' . htmlspecialchars($label_x) . '</th><th>' . htmlspecialchars($label_y) . '</th></tr>';
    foreach (array_slice($results['data_points'], 0, 10) as $point) {
      $markup .= '<tr><td>' . number_format($point['x'], 2) . '</td><td>' . number_format($point['y'], 2) . '</td></tr>';
    }
    $markup .= '</table>';
    $markup .= '</div>';

    $markup .= '</div>';

    return [
      '#markup' => $markup,
    ];
  }

  /**
   * Get variable label from key.
   */
  private function getVariableLabel(string $key, array $variables): string {
    foreach ($variables as $group => $vars) {
      if (isset($vars[$key])) {
        return $vars[$key];
      }
    }
    return $key;
  }

  /**
   * Interpret correlation coefficient.
   */
  private function interpretCorrelation(float $r): string {
    $abs_r = abs($r);
    $direction = $r >= 0 ? 'Positive' : 'Negative';
    
    if ($abs_r >= 0.9) {
      $strength = 'Very Strong';
    } elseif ($abs_r >= 0.7) {
      $strength = 'Strong';
    } elseif ($abs_r >= 0.5) {
      $strength = 'Moderate';
    } elseif ($abs_r >= 0.3) {
      $strength = 'Weak';
    } else {
      $strength = 'Very Weak/None';
    }
    
    return $direction . ' ' . $strength . ' Correlation';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $var_x = $form_state->getValue('variable_x');
    $var_y = $form_state->getValue('variable_y');
    $method = $form_state->getValue('analysis_type');
    $min_quality = $form_state->getValue('min_quality_score');
    $sex_filter = $form_state->getValue('sex_filter');
    $cancer_filter = $form_state->getValue('cancer_filter');

    // Build query
    $query = $this->database->select('nfr_correlation_analysis', 'c')
      ->fields('c', [$var_x, $var_y]);

    // Apply filters
    if ($min_quality) {
      $query->condition('data_quality_score', $min_quality, '>=');
    }
    if ($sex_filter) {
      $query->condition('sex', $sex_filter);
    }
    if ($cancer_filter !== '') {
      $query->condition('has_cancer_diagnosis', (int) $cancer_filter);
    }

    // Exclude NULL values
    $query->isNotNull($var_x);
    $query->isNotNull($var_y);

    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      $this->messenger()->addError($this->t('No data found matching your criteria.'));
      return;
    }

    // Extract data points
    $x_values = [];
    $y_values = [];
    $data_points = [];
    
    foreach ($results as $row) {
      $x = (float) $row->{$var_x};
      $y = (float) $row->{$var_y};
      $x_values[] = $x;
      $y_values[] = $y;
      $data_points[] = ['x' => $x, 'y' => $y];
    }

    // Calculate correlation
    if ($method === 'pearson') {
      $correlation = $this->calculatePearsonCorrelation($x_values, $y_values);
    } else {
      $correlation = $this->calculateSpearmanCorrelation($x_values, $y_values);
    }

    // Calculate summary statistics
    $results_array = [
      'correlation' => $correlation,
      'n' => count($x_values),
      'x_mean' => $this->mean($x_values),
      'x_median' => $this->median($x_values),
      'x_stddev' => $this->standardDeviation($x_values),
      'x_min' => min($x_values),
      'x_max' => max($x_values),
      'y_mean' => $this->mean($y_values),
      'y_median' => $this->median($y_values),
      'y_stddev' => $this->standardDeviation($y_values),
      'y_min' => min($y_values),
      'y_max' => max($y_values),
      'p_value' => $this->calculatePValue($correlation, count($x_values)),
      'data_points' => $data_points,
    ];

    $form_state->set('results', $results_array);
    $form_state->set('variable_x', $var_x);
    $form_state->set('variable_y', $var_y);
    $form_state->set('analysis_type', $method);
    $form_state->setRebuild(TRUE);

    $this->messenger()->addStatus($this->t('Analysis complete. Correlation coefficient: @r', ['@r' => number_format($correlation, 4)]));
  }

  /**
   * Export data to CSV.
   */
  public function exportData(array &$form, FormStateInterface $form_state): void {
    $var_x = $form_state->getValue('variable_x');
    $var_y = $form_state->getValue('variable_y');
    $min_quality = $form_state->getValue('min_quality_score');
    $sex_filter = $form_state->getValue('sex_filter');
    $cancer_filter = $form_state->getValue('cancer_filter');

    // Build query - get all relevant fields
    $query = $this->database->select('nfr_correlation_analysis', 'c')
      ->fields('c', ['uid', $var_x, $var_y, 'data_quality_score']);

    // Apply same filters
    if ($min_quality) {
      $query->condition('data_quality_score', $min_quality, '>=');
    }
    if ($sex_filter) {
      $query->condition('sex', $sex_filter);
    }
    if ($cancer_filter !== '') {
      $query->condition('has_cancer_diagnosis', (int) $cancer_filter);
    }

    $query->isNotNull($var_x);
    $query->isNotNull($var_y);

    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      $this->messenger()->addError($this->t('No data to export.'));
      return;
    }

    // Generate CSV
    $variables = $this->getAvailableVariables();
    $label_x = $this->getVariableLabel($var_x, $variables);
    $label_y = $this->getVariableLabel($var_y, $variables);

    $filename = 'nfr_correlation_' . $var_x . '_vs_' . $var_y . '_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['UID', $label_x, $label_y, 'Data Quality Score']);
    
    foreach ($results as $row) {
      fputcsv($output, [
        $row->uid,
        $row->{$var_x},
        $row->{$var_y},
        $row->data_quality_score,
      ]);
    }
    
    fclose($output);
    exit;
  }

  /**
   * Calculate Pearson correlation coefficient.
   */
  private function calculatePearsonCorrelation(array $x, array $y): float {
    $n = count($x);
    if ($n < 2) {
      return 0.0;
    }

    $mean_x = $this->mean($x);
    $mean_y = $this->mean($y);

    $numerator = 0;
    $sum_sq_x = 0;
    $sum_sq_y = 0;

    for ($i = 0; $i < $n; $i++) {
      $diff_x = $x[$i] - $mean_x;
      $diff_y = $y[$i] - $mean_y;
      $numerator += $diff_x * $diff_y;
      $sum_sq_x += $diff_x * $diff_x;
      $sum_sq_y += $diff_y * $diff_y;
    }

    $denominator = sqrt($sum_sq_x * $sum_sq_y);
    
    return $denominator == 0 ? 0.0 : $numerator / $denominator;
  }

  /**
   * Calculate Spearman rank correlation coefficient.
   */
  private function calculateSpearmanCorrelation(array $x, array $y): float {
    $n = count($x);
    if ($n < 2) {
      return 0.0;
    }

    // Convert to ranks
    $rank_x = $this->getRanks($x);
    $rank_y = $this->getRanks($y);

    // Use Pearson on ranks
    return $this->calculatePearsonCorrelation($rank_x, $rank_y);
  }

  /**
   * Convert values to ranks.
   */
  private function getRanks(array $values): array {
    $sorted = $values;
    asort($sorted);
    $ranks = [];
    $rank = 1;
    foreach (array_keys($sorted) as $key) {
      $ranks[$key] = $rank++;
    }
    
    // Reorder to match original
    $result = [];
    foreach (array_keys($values) as $key) {
      $result[] = $ranks[$key];
    }
    return $result;
  }

  /**
   * Calculate mean.
   */
  private function mean(array $values): float {
    return count($values) > 0 ? array_sum($values) / count($values) : 0.0;
  }

  /**
   * Calculate median.
   */
  private function median(array $values): float {
    $count = count($values);
    if ($count === 0) {
      return 0.0;
    }
    
    sort($values);
    $middle = floor($count / 2);
    
    if ($count % 2 === 0) {
      return ($values[$middle - 1] + $values[$middle]) / 2;
    }
    
    return $values[$middle];
  }

  /**
   * Calculate standard deviation.
   */
  private function standardDeviation(array $values): float {
    $count = count($values);
    if ($count < 2) {
      return 0.0;
    }
    
    $mean = $this->mean($values);
    $sum_sq_diff = 0;
    
    foreach ($values as $value) {
      $diff = $value - $mean;
      $sum_sq_diff += $diff * $diff;
    }
    
    return sqrt($sum_sq_diff / ($count - 1));
  }

  /**
   * Calculate approximate p-value for correlation.
   */
  private function calculatePValue(float $r, int $n): float {
    if ($n < 3) {
      return 1.0;
    }
    
    // t-statistic for correlation
    $t = $r * sqrt(($n - 2) / (1 - $r * $r + 0.0001));
    $df = $n - 2;
    
    // Approximate p-value using t-distribution
    // For simplicity, using rough approximation
    $abs_t = abs($t);
    
    if ($abs_t > 3.291) return 0.001;  // p < 0.001
    if ($abs_t > 2.576) return 0.01;   // p < 0.01
    if ($abs_t > 1.960) return 0.05;   // p < 0.05
    if ($abs_t > 1.645) return 0.10;   // p < 0.10
    
    return 0.20; // p > 0.10 (not significant)
  }

}
