<?php

declare(strict_types=1);

namespace Drupal\users_metrics\Plugin\views\field;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Allow to group by user registration date.
 *
 * @ViewsField("user_created_groupable")
 */
final class UserCreatedGroupable extends GroupableDateFieldBase {

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
    return 'created';
  }

  /**
   * {@inheritdoc}
   */
  protected function getGroupedFieldSuffix(): string {
    return 'created_grouped';
  }

}
