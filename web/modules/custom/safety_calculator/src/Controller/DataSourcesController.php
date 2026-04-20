<?php

namespace Drupal\safety_calculator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Data Sources page.
 */
class DataSourcesController extends ControllerBase {

  /**
   * The individual metrics repository.
   *
   * @var \Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface
   */
  protected IndividualMetricsRepositoryInterface $repository;

  /**
   * Constructs a DataSourcesController object.
   *
   * @param \Drupal\safety_calculator\Repository\IndividualMetricsRepositoryInterface $repository
   *   The individual metrics repository.
   */
  public function __construct(IndividualMetricsRepositoryInterface $repository) {
    $this->repository = $repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('safety_calculator.individual_metrics_repository')
    );
  }

  /**
   * Display the data sources page.
   *
   * @return array
   *   A render array.
   */
  public function page(): array {
    // Get all metrics with references
    $connection = \Drupal::database();
    
    // Check if reference_citation field exists
    $schema = $connection->schema();
    $hasCitationField = $schema->fieldExists('individual_metrics_master', 'reference_citation');
    
    $fields = [
      'id',
      'metric_name',
      'metric_description',
      'category',
      'dimension',
      'reference_url',
    ];
    
    if ($hasCitationField) {
      $fields[] = 'reference_citation';
    }
    
    $query = $connection->select('individual_metrics_master', 'm')
      ->fields('m', $fields)
      ->condition('reference_url', NULL, 'IS NOT NULL')
      ->orderBy('dimension')
      ->orderBy('category')
      ->orderBy('metric_name');
    
    $results = $query->execute()->fetchAll();
    
    // Group by dimension and category
    $dimensionOrder = [
      'SAFE',
      'ENERGIZED',
      'CONNECTED',
      'FREE',
      'CAPABLE',
      'USEFUL',
      'WHOLE',
      'DEMOGRAPHIC',
    ];
    
    $groupedData = [];
    $uniqueReferences = [];
    $totalMetrics = 0;
    $metricsByDimension = [];
    
    foreach ($results as $row) {
      $dimension = $row->dimension;
      $category = $row->category;
      
      if (!isset($groupedData[$dimension])) {
        $groupedData[$dimension] = [];
        $metricsByDimension[$dimension] = 0;
      }
      
      if (!isset($groupedData[$dimension][$category])) {
        $groupedData[$dimension][$category] = [];
      }
      
      $groupedData[$dimension][$category][] = [
        'id' => $row->id,
        'name' => $row->metric_name,
        'description' => $row->metric_description,
        'url' => $row->reference_url,
        'citation' => $hasCitationField && isset($row->reference_citation) ? $row->reference_citation : NULL,
      ];
      
      // Track unique references
      if ($row->reference_url && !isset($uniqueReferences[$row->reference_url])) {
        $uniqueReferences[$row->reference_url] = [
          'url' => $row->reference_url,
          'citation' => $hasCitationField && isset($row->reference_citation) ? $row->reference_citation : NULL,
          'count' => 0,
        ];
      }
      if ($row->reference_url) {
        $uniqueReferences[$row->reference_url]['count']++;
      }
      
      $totalMetrics++;
      $metricsByDimension[$dimension]++;
    }
    
    // Sort grouped data by dimension order
    $sortedData = [];
    foreach ($dimensionOrder as $dim) {
      if (isset($groupedData[$dim])) {
        $sortedData[$dim] = $groupedData[$dim];
      }
    }
    
    // Get dimension names
    $dimensionNames = [
      'SAFE' => 'Physiological Safety',
      'ENERGIZED' => 'Safety Needs',
      'CONNECTED' => 'Love & Belonging',
      'FREE' => 'Esteem Needs',
      'CAPABLE' => 'Cognitive Needs',
      'USEFUL' => 'Aesthetic Needs',
      'WHOLE' => 'Self-Actualization',
      'DEMOGRAPHIC' => 'Demographic Information',
    ];
    
    return [
      '#theme' => 'data_sources',
      '#grouped_data' => $sortedData,
      '#dimension_names' => $dimensionNames,
      '#unique_references' => $uniqueReferences,
      '#total_metrics' => $totalMetrics,
      '#metrics_by_dimension' => $metricsByDimension,
      '#attached' => [
        'library' => [
          'safety_calculator/data-sources',
        ],
      ],
    ];
  }

}
