<?php

declare(strict_types=1);

namespace Drupal\users_metrics\Plugin\views\field;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Allow to group by watchdog timestamp for login statistics.
 *
 * @ViewsField("watchdog_timestamp_groupable")
 */
final class WatchdogTimestampGroupable extends GroupableDateFieldBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getSourceColumn(): string {
    return 'timestamp';
  }

  /**
   * {@inheritdoc}
   */
  protected function getGroupedFieldSuffix(): string {
    return 'timestamp_grouped';
  }

}
