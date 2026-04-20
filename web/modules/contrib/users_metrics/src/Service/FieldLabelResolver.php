<?php

declare(strict_types=1);

namespace Drupal\users_metrics\Service;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for resolving field labels to human-readable values.
 */
class FieldLabelResolver {

  use StringTranslationTrait;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * Internal cache for allowed values lookup.
   *
   * @var array
   */
  protected array $allowedValuesCache = [];

  /**
   * Constructs a FieldLabelResolver object.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(LanguageManagerInterface $language_manager, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $this->languageManager = $language_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * Checks if a field is groupable.
   *
   * @param string $field_id
   *   The field ID.
   *
   * @return bool
   *   TRUE if the field is groupable, FALSE otherwise.
   */
  public function isFieldGroupable(string $field_id): bool {
    // Langcode is always groupable.
    if ($field_id === 'langcode') {
      return TRUE;
    }

    // Check if field exists in user field definitions.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('user', 'user');

    if (!isset($field_definitions[$field_id])) {
      return FALSE;
    }

    // Check if field type is groupable.
    $field_type = $field_definitions[$field_id]->getType();
    $groupable_types = [
      'entity_reference',
      'list_string',
      'list_integer',
      'list_float',
      'boolean',
      'language',
    ];

    return in_array($field_type, $groupable_types, TRUE);
  }

  /**
   * Resolves a field value to its human-readable label.
   *
   * @param string $field_id
   *   The field ID.
   * @param string $value
   *   The raw field value.
   *
   * @return string
   *   The human-readable label.
   */
  public function resolveLabel(string $field_id, string $value): string {
    // Handle empty values.
    if (empty($value)) {
      return (string) $this->t('(empty)');
    }

    // Handle language field.
    if ($field_id === 'langcode') {
      $languages = $this->languageManager->getLanguages();
      if (isset($languages[$value])) {
        return $languages[$value]->getName();
      }
      return $value;
    }

    // Handle custom list fields (field_*).
    if (str_starts_with($field_id, 'field_')) {
      // Use cached allowed values.
      if (!isset($this->allowedValuesCache[$field_id])) {
        $this->allowedValuesCache[$field_id] = $this->loadAllowedValues($field_id);
      }

      $allowed = $this->allowedValuesCache[$field_id];
      if (isset($allowed[$value])) {
        return $allowed[$value];
      }
    }

    // Fallback: return the original value.
    return $value;
  }

  /**
   * Loads allowed values for a field.
   *
   * @param string $field_id
   *   The field ID.
   *
   * @return array
   *   The allowed values array, or empty array on error.
   */
  private function loadAllowedValues(string $field_id): array {
    try {
      $field_storage = $this->entityTypeManager
        ->getStorage('field_storage_config')
        ->load('user.' . $field_id);

      if ($field_storage) {
        $settings = $field_storage->getSettings();
        if (isset($settings['allowed_values'])) {
          return $settings['allowed_values'];
        }
      }
    }
    catch (\Exception $e) {
      // Ignore errors, return empty array as fallback.
    }

    return [];
  }

}
