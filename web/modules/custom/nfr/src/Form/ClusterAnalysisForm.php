<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for running K-means cluster analysis on NFR data.
 */
class ClusterAnalysisForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a ClusterAnalysisForm object.
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
    return 'nfr_cluster_analysis_form';
  }

  /**
   * Get available variables for clustering.
   */
  private function getAvailableVariables(): array {
    return [
      'Demographics' => [
        'age_at_enrollment' => 'Age at Enrollment',
        'education_level' => 'Education Level',
      ],
      'Health Metrics' => [
        'height_inches' => 'Height (inches)',
        'weight_pounds' => 'Weight (pounds)',
        'bmi' => 'Body Mass Index (BMI)',
        'smoking_status' => 'Smoking Status',
        'alcohol_consumption' => 'Alcohol Consumption',
        'exercise_frequency' => 'Exercise Frequency',
      ],
      'Work History' => [
        'total_fire_departments' => 'Total Fire Departments',
        'total_years_service' => 'Total Years of Service',
        'years_career_firefighter' => 'Years Career Firefighter',
        'years_volunteer' => 'Years Volunteer',
        'highest_rank' => 'Highest Rank',
        'num_states_worked' => 'States Worked',
      ],
      'Incident Exposure' => [
        'total_structure_fires' => 'Total Structure Fires',
        'total_structure_residential_fires' => 'Residential Fires',
        'total_structure_commercial_fires' => 'Commercial Fires',
        'total_vehicle_fires' => 'Vehicle Fires',
        'total_wildland_fires' => 'Wildland Fires',
        'total_hazmat_incidents' => 'Hazmat Incidents',
        'total_medical_ems_calls' => 'Medical/EMS Calls',
        'avg_structure_fires_per_year' => 'Avg Structure Fires/Year',
        'incident_diversity_score' => 'Incident Diversity',
      ],
      'Health Outcomes' => [
        'has_cancer_diagnosis' => 'Cancer Diagnosis Status',
        'num_cancer_diagnoses' => 'Number Cancer Diagnoses',
        'age_first_cancer_diagnosis' => 'Age First Diagnosis',
        'family_cancer_count' => 'Family Cancer History',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $variables = $this->getAvailableVariables();
    
    $options = [];
    foreach ($variables as $group => $vars) {
      foreach ($vars as $key => $label) {
        $options[$group][$key] = $label;
      }
    }

    $form['description'] = [
      '#markup' => '<div class="cluster-intro"><p>Identify natural groupings in firefighter populations using K-means clustering. Select variables to analyze and the algorithm will segment participants into distinct groups based on similarity.</p></div>',
    ];

    $form['clustering_variables'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Variables for Clustering'),
      '#options' => array_merge(...array_values($options)),
      '#required' => TRUE,
      '#description' => $this->t('Select 2 or more variables to use for clustering analysis.'),
    ];

    $form['num_clusters'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of Clusters (k)'),
      '#min' => 2,
      '#max' => 10,
      '#default_value' => 3,
      '#required' => TRUE,
      '#description' => $this->t('How many distinct groups to identify (typically 2-5 for interpretability).'),
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
      '#description' => $this->t('Only include records with quality score above threshold.'),
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

    $form['max_iterations'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Iterations'),
      '#min' => 10,
      '#max' => 500,
      '#default_value' => 100,
      '#description' => $this->t('Maximum iterations for K-means convergence.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run Cluster Analysis'),
      '#button_type' => 'primary',
    ];

    $form['actions']['export'] = [
      '#type' => 'submit',
      '#value' => $this->t('Export Clusters (CSV)'),
      '#submit' => ['::exportClusters'],
    ];

    if ($form_state->get('results')) {
      $form['results'] = $this->buildResults($form_state);
    }

    $form['#attached']['library'][] = 'nfr/cluster-analysis';

    return $form;
  }

  /**
   * Build results display.
   */
  private function buildResults(FormStateInterface $form_state): array {
    $results = $form_state->get('results');
    $variables = $form_state->get('variables');
    $k = $form_state->get('num_clusters');

    $markup = '<div class="cluster-results">';
    $markup .= '<h2>Cluster Analysis Results</h2>';
    
    $markup .= '<div class="result-summary">';
    $markup .= '<p><strong>Variables:</strong> ' . implode(', ', $variables) . '</p>';
    $markup .= '<p><strong>Number of Clusters:</strong> ' . $k . '</p>';
    $markup .= '<p><strong>Total Records:</strong> ' . number_format($results['total_records']) . '</p>';
    $markup .= '<p><strong>Iterations:</strong> ' . $results['iterations'] . '</p>';
    $markup .= '<p><strong>Converged:</strong> ' . ($results['converged'] ? 'Yes' : 'No') . '</p>';
    $markup .= '</div>';

    // Cluster summaries
    $markup .= '<div class="cluster-summaries">';
    $markup .= '<h3>Cluster Characteristics</h3>';
    
    foreach ($results['clusters'] as $cluster_id => $cluster_data) {
      $markup .= '<div class="cluster-card">';
      $markup .= '<h4>Cluster ' . ($cluster_id + 1) . '</h4>';
      $markup .= '<p class="cluster-size"><strong>Size:</strong> ' . number_format($cluster_data['size']) . ' members (' . number_format($cluster_data['percentage'], 1) . '%)</p>';
      
      $markup .= '<div class="cluster-centroids">';
      $markup .= '<table>';
      $markup .= '<tr><th>Variable</th><th>Mean Value</th></tr>';
      foreach ($cluster_data['centroid'] as $var => $value) {
        $markup .= '<tr><td>' . htmlspecialchars($var) . '</td><td>' . number_format($value, 2) . '</td></tr>';
      }
      $markup .= '</table>';
      $markup .= '</div>';
      $markup .= '</div>';
    }
    $markup .= '</div>';

    // Within-cluster sum of squares (quality metric)
    $markup .= '<div class="cluster-quality">';
    $markup .= '<h3>Clustering Quality</h3>';
    $markup .= '<p><strong>Within-Cluster Sum of Squares:</strong> ' . number_format($results['wcss'], 2) . '</p>';
    $markup .= '<p class="quality-note">Lower values indicate tighter, more cohesive clusters. Compare WCSS across different k values to find optimal number of clusters.</p>';
    $markup .= '</div>';

    $markup .= '</div>';

    return [
      '#markup' => $markup,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $variables = array_filter($form_state->getValue('clustering_variables'));
    
    if (count($variables) < 2) {
      $form_state->setErrorByName('clustering_variables', $this->t('Please select at least 2 variables for clustering.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $variables = array_filter($form_state->getValue('clustering_variables'));
    $k = (int) $form_state->getValue('num_clusters');
    $max_iterations = (int) $form_state->getValue('max_iterations');
    $min_quality = $form_state->getValue('min_quality_score');
    $cancer_filter = $form_state->getValue('cancer_filter');

    // Build query to get data
    $query = $this->database->select('nfr_correlation_analysis', 'c')
      ->fields('c', array_merge(['uid'], $variables));

    if ($min_quality) {
      $query->condition('data_quality_score', $min_quality, '>=');
    }
    if ($cancer_filter !== '') {
      $query->condition('has_cancer_diagnosis', (int) $cancer_filter);
    }

    // Exclude NULL values for selected variables
    foreach ($variables as $var) {
      $query->isNotNull($var);
    }

    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      $this->messenger()->addError($this->t('No data found matching your criteria.'));
      return;
    }

    // Extract data matrix
    $data_matrix = [];
    $uids = [];
    foreach ($results as $row) {
      $point = [];
      foreach ($variables as $var) {
        $point[] = (float) $row->{$var};
      }
      $data_matrix[] = $point;
      $uids[] = $row->uid;
    }

    // Normalize data (z-score normalization)
    $normalized_data = $this->normalizeData($data_matrix);

    // Run K-means clustering
    $clustering_result = $this->kMeansClustering($normalized_data, $k, $max_iterations);

    // Calculate cluster characteristics in original scale
    $cluster_characteristics = $this->calculateClusterCharacteristics(
      $data_matrix,
      $clustering_result['assignments'],
      $variables,
      $k
    );

    $results_array = [
      'clusters' => $cluster_characteristics,
      'assignments' => $clustering_result['assignments'],
      'uids' => $uids,
      'total_records' => count($data_matrix),
      'iterations' => $clustering_result['iterations'],
      'converged' => $clustering_result['converged'],
      'wcss' => $clustering_result['wcss'],
    ];

    $form_state->set('results', $results_array);
    $form_state->set('variables', array_values($variables));
    $form_state->set('num_clusters', $k);
    $form_state->setRebuild(TRUE);

    $this->messenger()->addStatus($this->t('Cluster analysis complete. Identified @k clusters from @n records.', [
      '@k' => $k,
      '@n' => count($data_matrix),
    ]));
  }

  /**
   * Export clusters to CSV.
   */
  public function exportClusters(array &$form, FormStateInterface $form_state): void {
    $results = $form_state->get('results');
    
    if (!$results) {
      $this->messenger()->addError($this->t('No clustering results to export. Run analysis first.'));
      return;
    }

    $filename = 'nfr_clusters_k' . $form_state->get('num_clusters') . '_' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['UID', 'Cluster']);
    
    foreach ($results['uids'] as $idx => $uid) {
      $cluster = $results['assignments'][$idx] + 1;
      fputcsv($output, [$uid, $cluster]);
    }
    
    fclose($output);
    exit;
  }

  /**
   * Normalize data using z-score normalization.
   */
  private function normalizeData(array $data): array {
    $n_features = count($data[0]);
    $normalized = [];

    // Calculate mean and std dev for each feature
    $means = [];
    $stddevs = [];
    
    for ($f = 0; $f < $n_features; $f++) {
      $feature_values = array_column($data, $f);
      $means[$f] = array_sum($feature_values) / count($feature_values);
      
      $sq_diff_sum = 0;
      foreach ($feature_values as $val) {
        $sq_diff_sum += pow($val - $means[$f], 2);
      }
      $stddevs[$f] = sqrt($sq_diff_sum / count($feature_values));
      
      // Avoid division by zero
      if ($stddevs[$f] == 0) {
        $stddevs[$f] = 1;
      }
    }

    // Normalize each data point
    foreach ($data as $point) {
      $normalized_point = [];
      for ($f = 0; $f < $n_features; $f++) {
        $normalized_point[] = ($point[$f] - $means[$f]) / $stddevs[$f];
      }
      $normalized[] = $normalized_point;
    }

    return $normalized;
  }

  /**
   * K-means clustering algorithm.
   */
  private function kMeansClustering(array $data, int $k, int $max_iterations): array {
    $n_samples = count($data);
    $n_features = count($data[0]);

    // Initialize centroids randomly from data points
    $centroid_indices = array_rand($data, $k);
    if (!is_array($centroid_indices)) {
      $centroid_indices = [$centroid_indices];
    }
    
    $centroids = [];
    foreach ($centroid_indices as $idx) {
      $centroids[] = $data[$idx];
    }

    $assignments = array_fill(0, $n_samples, 0);
    $converged = FALSE;

    for ($iteration = 0; $iteration < $max_iterations; $iteration++) {
      $old_assignments = $assignments;

      // Assignment step: assign each point to nearest centroid
      for ($i = 0; $i < $n_samples; $i++) {
        $min_distance = PHP_FLOAT_MAX;
        $closest_centroid = 0;

        for ($c = 0; $c < $k; $c++) {
          $distance = $this->euclideanDistance($data[$i], $centroids[$c]);
          if ($distance < $min_distance) {
            $min_distance = $distance;
            $closest_centroid = $c;
          }
        }

        $assignments[$i] = $closest_centroid;
      }

      // Update step: recalculate centroids
      $new_centroids = [];
      for ($c = 0; $c < $k; $c++) {
        $cluster_points = [];
        for ($i = 0; $i < $n_samples; $i++) {
          if ($assignments[$i] === $c) {
            $cluster_points[] = $data[$i];
          }
        }

        if (empty($cluster_points)) {
          // If cluster is empty, reinitialize randomly
          $new_centroids[$c] = $data[array_rand($data)];
        } else {
          // Calculate mean of cluster points
          $centroid = array_fill(0, $n_features, 0);
          foreach ($cluster_points as $point) {
            for ($f = 0; $f < $n_features; $f++) {
              $centroid[$f] += $point[$f];
            }
          }
          for ($f = 0; $f < $n_features; $f++) {
            $centroid[$f] /= count($cluster_points);
          }
          $new_centroids[$c] = $centroid;
        }
      }

      $centroids = $new_centroids;

      // Check for convergence
      if ($assignments === $old_assignments) {
        $converged = TRUE;
        break;
      }
    }

    // Calculate within-cluster sum of squares (WCSS)
    $wcss = 0;
    for ($i = 0; $i < $n_samples; $i++) {
      $cluster = $assignments[$i];
      $distance = $this->euclideanDistance($data[$i], $centroids[$cluster]);
      $wcss += $distance * $distance;
    }

    return [
      'assignments' => $assignments,
      'centroids' => $centroids,
      'iterations' => $iteration + 1,
      'converged' => $converged,
      'wcss' => $wcss,
    ];
  }

  /**
   * Calculate Euclidean distance between two points.
   */
  private function euclideanDistance(array $point1, array $point2): float {
    $sum = 0;
    for ($i = 0; $i < count($point1); $i++) {
      $sum += pow($point1[$i] - $point2[$i], 2);
    }
    return sqrt($sum);
  }

  /**
   * Calculate cluster characteristics in original scale.
   */
  private function calculateClusterCharacteristics(array $original_data, array $assignments, array $variables, int $k): array {
    $characteristics = [];
    $total_samples = count($original_data);

    for ($c = 0; $c < $k; $c++) {
      $cluster_points = [];
      for ($i = 0; $i < $total_samples; $i++) {
        if ($assignments[$i] === $c) {
          $cluster_points[] = $original_data[$i];
        }
      }

      $size = count($cluster_points);
      $percentage = ($size / $total_samples) * 100;

      // Calculate mean for each variable
      $centroid = [];
      $var_list = array_values($variables);
      for ($f = 0; $f < count($var_list); $f++) {
        $feature_values = array_column($cluster_points, $f);
        $centroid[$var_list[$f]] = array_sum($feature_values) / $size;
      }

      $characteristics[$c] = [
        'size' => $size,
        'percentage' => $percentage,
        'centroid' => $centroid,
      ];
    }

    return $characteristics;
  }

}
