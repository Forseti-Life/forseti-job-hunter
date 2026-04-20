<?php

declare(strict_types=1);

namespace Drupal\users_metrics\Plugin\views\filter;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter to select a user field for grouping.
 *
 * @ViewsFilter("user_field_grouping")
 */
class UserFieldGrouping extends FilterPluginBase {

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a UserFieldGrouping object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityFieldManagerInterface $entity_field_manager, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary() {
    return '';
  }

  /**
   * {@inheritdoc}
   */
  protected function operatorForm(&$form, FormStateInterface $form_state) {
    $form['operator'] = [];
  }

  /**
   * {@inheritdoc}
   */
  public function canExpose() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultExposeOptions() {
    parent::defaultExposeOptions();
    $this->options['expose']['required'] = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {
    $form['value'] = [
      '#type' => 'select',
      '#title' => $this->t('Group by field'),
      '#options' => $this->getGroupableFieldOptions(),
      '#default_value' => $this->value ?? '',
      '#empty_option' => $this->t('- Select a field -'),
    ];

    if (!empty($this->options['expose']['identifier'])) {
      $user_input = $form_state->getUserInput();
      if (isset($user_input[$this->options['expose']['identifier']])) {
        $form['value']['#default_value'] = $user_input[$this->options['expose']['identifier']];
      }
    }
  }

  /**
   * Get options for groupable user fields.
   *
   * @return array
   *   Array of field labels keyed by field name.
   */
  protected function getGroupableFieldOptions(): array {
    $options = [];

    // Add core language field.
    $options['langcode'] = $this->t('Language');

    // Get all user fields.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('user', 'user');

    foreach ($field_definitions as $field_name => $field_definition) {
      // Skip base fields except langcode (already added).
      if ($field_definition->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }

      // Get field type.
      $field_type = $field_definition->getType();

      // Include only fields that make sense for grouping.
      $groupable_types = [
        'entity_reference',
        'list_string',
        'list_integer',
        'list_float',
        'boolean',
        'language',
      ];

      if (in_array($field_type, $groupable_types)) {
        $options[$field_name] = $field_definition->getLabel();
      }
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // This filter doesn't modify the query directly.
    // The grouping functionality is handled via hook_views_pre_build().
  }

}
