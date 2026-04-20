<?php

declare(strict_types=1);

namespace Drupal\users_metrics\Plugin\views\field;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\views\Plugin\views\field\Date;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Abstract base class for groupable date fields.
 */
abstract class GroupableDateFieldBase extends Date implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a GroupableDateFieldBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DateFormatterInterface $date_formatter,
    EntityTypeManagerInterface $entity_type_manager,
    TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $date_formatter, $entity_type_manager->getStorage('date_format'), $time);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Get the source column name for the timestamp.
   *
   * @return string
   *   The column name (e.g., 'created' or 'timestamp').
   */
  abstract protected function getSourceColumn(): string;

  /**
   * Get the field alias suffix for grouping.
   *
   * @return string
   *   The suffix for the grouped field alias.
   */
  abstract protected function getGroupedFieldSuffix(): string;

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $this->ensureMyTable();
    $params = [];
    $format = $this->getFormat();

    // Fix week format.
    if ($format === 'Y-\WW') {
      $format = 'Y-W';
    }

    $column = $this->getSourceColumn();
    $suffix = $this->getGroupedFieldSuffix();

    $format_date = $this->query->getDateFormat("FROM_UNIXTIME({$this->tableAlias}.{$column})", $format);
    $this->field_alias = $this->query->addField('', $format_date, $this->tableAlias . '_' . $suffix, $params);
    $this->addAdditionalFields();
  }

  /**
   * Get the configured date format.
   *
   * @return string
   *   The date format.
   */
  protected function getFormat(): string {
    $format = $this->options['date_format'] ?? '';

    if ($format === 'custom') {
      return $this->options['custom_date_format'] ?? '';
    }

    if ($format) {
      $formatter = $this->entityTypeManager->getStorage('date_format')->load($format);
      return $formatter ? $formatter->getPattern() : '';
    }

    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $value = $this->getValue($values);

    // Convert the date format to a week format for html_week.
    if ($this->options['date_format'] === 'html_week') {
      $value = str_replace('-', '-W', $value);
      return $this->sanitizeValue($value);
    }

    return FieldPluginBase::render($values);
  }

}
