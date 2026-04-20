<?php

namespace Drupal\safety_calculator\Commands;

use Drupal\Core\Database\Connection;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for importing individual metrics.
 */
class MetricsImportCommands extends DrushCommands {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a MetricsImportCommands object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    parent::__construct();
    $this->database = $database;
  }

  /**
   * Import individual metrics from table markdown file.
   *
   * @command safety:import-metrics
   * @aliases sim
   * @usage safety:import-metrics
   *   Import all 1,200 metrics from individual_metrics_table.md
   */
  public function importMetrics() {
    $this->output()->writeln('Starting metric import...');
    
    // Path to the metrics table file
    $module_path = \Drupal::service('extension.list.module')->getPath('safety_calculator');
    $file_path = DRUPAL_ROOT . '/' . $module_path . '/data/individual_metrics_table.md';
    
    if (!file_exists($file_path)) {
      $this->logger()->error('Metrics table file not found: ' . $file_path);
      return DrushCommands::EXIT_FAILURE;
    }

    $content = file_get_contents($file_path);
    $lines = explode("\n", $content);
    
    $metrics = [];
    $imported = 0;
    $skipped = 0;
    
    // Parse the markdown table
    foreach ($lines as $line) {
      // Skip empty lines, headers, and separators
      if (empty(trim($line)) || strpos($line, '---') === 0 || strpos($line, 'Metric Name') !== FALSE) {
        continue;
      }
      
      // Only process lines that start with pipe
      if (strpos($line, '|') === 0) {
        $parts = array_map('trim', explode('|', trim($line, '|')));
        
        // Should have 7 or 11 parts (original 7, or 7 + 4 new reference columns)
        // Original: Metric Name, Description, Category, Question #, Question Title, Parent Node, Dimension
        // New: + Reference URL, Population, Pop. Mean, Pop. Std Dev
        if (count($parts) >= 7) {
          $metric_name = $parts[0];
          $metric_description = $parts[1];
          $category = $parts[2];
          $question_id = (int) $parts[3];
          $question_title = $parts[4];
          $parent_node = $parts[5] === 'None (universal)' ? NULL : $parts[5];
          $dimension = $parts[6];
          
          // Extract new reference fields if present (columns 8-11)
          $reference_url = (count($parts) >= 8 && !empty($parts[7])) ? $parts[7] : NULL;
          $population_definition = (count($parts) >= 9 && !empty($parts[8])) ? $parts[8] : NULL;
          $population_mean = (count($parts) >= 10 && !empty($parts[9]) && is_numeric($parts[9])) ? (float) $parts[9] : NULL;
          $population_stddev = (count($parts) >= 11 && !empty($parts[10]) && is_numeric($parts[10])) ? (float) $parts[10] : NULL;
          
          // Skip if no question ID (likely header or summary row)
          if ($question_id === 0) {
            continue;
          }
          
          // Determine data type based on description
          $data_type = $this->guessDataType($metric_description);
          
          // Build validation rules
          $validation_rules = $this->buildValidationRules($metric_description, $data_type);
          
          $metrics[] = [
            'question_id' => $question_id,
            'metric_name' => $this->sanitizeMetricName($metric_name),
            'metric_description' => $metric_description,
            'category' => $category,
            'dimension' => $dimension,
            'parent_node' => $parent_node,
            'data_type' => $data_type,
            'validation_rules' => $validation_rules ? json_encode($validation_rules) : NULL,
            'reference_url' => $reference_url,
            'population_definition' => $population_definition,
            'population_mean' => $population_mean,
            'population_stddev' => $population_stddev,
            'created' => time(),
          ];
        }
      }
    }
    
    $this->output()->writeln(sprintf('Parsed %d metrics from file.', count($metrics)));
    
    // Clear existing metrics
    $this->database->truncate('individual_metrics_master')->execute();
    $this->output()->writeln('Cleared existing metrics.');
    
    // Insert metrics in batches
    $batch_size = 100;
    $batches = array_chunk($metrics, $batch_size);
    
    foreach ($batches as $batch) {
      $query = $this->database->insert('individual_metrics_master')
        ->fields([
          'question_id',
          'metric_name',
          'metric_description',
          'category',
          'dimension',
          'parent_node',
          'data_type',
          'validation_rules',
          'reference_url',
          'population_definition',
          'population_mean',
          'population_stddev',
          'created',
        ]);
      
      foreach ($batch as $metric) {
        $query->values($metric);
      }
      
      try {
        $query->execute();
        $imported += count($batch);
      }
      catch (\Exception $e) {
        $this->logger()->error('Error importing batch: ' . $e->getMessage());
        $skipped += count($batch);
      }
    }
    
    $this->output()->writeln('');
    $this->output()->writeln(sprintf('✅ Import complete: %d metrics imported, %d skipped.', $imported, $skipped));
    
    // Verify counts by dimension
    $this->output()->writeln('');
    $this->output()->writeln('Metrics by dimension:');
    $result = $this->database->query("
      SELECT dimension, COUNT(*) as count 
      FROM {individual_metrics_master} 
      GROUP BY dimension 
      ORDER BY dimension
    ");
    
    foreach ($result as $row) {
      $this->output()->writeln(sprintf('  %s: %d metrics', $row->dimension, $row->count));
    }
    
    // Verify counts by question
    $total_questions = $this->database->query("
      SELECT COUNT(DISTINCT question_id) as count 
      FROM {individual_metrics_master}
    ")->fetchField();
    
    $this->output()->writeln('');
    $this->output()->writeln(sprintf('Total questions: %d', $total_questions));
    
    return DrushCommands::EXIT_SUCCESS;
  }

  /**
   * Sanitize metric name to machine name format.
   */
  protected function sanitizeMetricName($name) {
    return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $name));
  }

  /**
   * Guess data type from description.
   */
  protected function guessDataType($description) {
    $lower = strtolower($description);
    
    // Check for yes/no
    if (strpos($lower, 'yes/no') !== FALSE) {
      return 'boolean';
    }
    
    // Check for scale
    if (preg_match('/(scale|rating)\s+(\d+)-(\d+)/i', $lower)) {
      return 'scale';
    }
    if (preg_match('/\(0-10\)|\(0-100\)|\(1-5\)/i', $lower)) {
      return 'scale';
    }
    
    // Check for numeric
    if (preg_match('/\b(number|count|age|years|months|days|hours|dollars|percentage|miles|feet|square|times|frequency)\b/i', $lower)) {
      return 'numeric';
    }
    
    // Check for select/multiple choice
    if (preg_match('/(never|rarely|sometimes|often|always)/i', $lower)) {
      return 'select';
    }
    if (preg_match('/:|;|,.*,/i', $description)) {
      return 'select';
    }
    
    // Default to text
    return 'text';
  }

  /**
   * Build validation rules based on description.
   */
  protected function buildValidationRules($description, $data_type) {
    $rules = [];
    
    if ($data_type === 'numeric') {
      // Try to extract min/max from description
      if (preg_match('/(\d+)-(\d+)/', $description, $matches)) {
        $rules['min'] = (int) $matches[1];
        $rules['max'] = (int) $matches[2];
      }
    }
    
    if ($data_type === 'scale') {
      // Extract scale range
      if (preg_match('/\((\d+)-(\d+)\)/', $description, $matches)) {
        $rules['min'] = (int) $matches[1];
        $rules['max'] = (int) $matches[2];
        $rules['step'] = 1;
      }
    }
    
    if ($data_type === 'boolean') {
      $rules['type'] = 'boolean';
    }
    
    if ($data_type === 'select') {
      // Try to extract options
      if (preg_match('/never.*rarely.*sometimes.*often/i', $description)) {
        $rules['options'] = ['never', 'rarely', 'sometimes', 'often'];
      }
      else if (preg_match('/never.*rarely.*sometimes.*often.*always/i', $description)) {
        $rules['options'] = ['never', 'rarely', 'sometimes', 'often', 'always'];
      }
    }
    
    return !empty($rules) ? $rules : NULL;
  }

}
