<?php

namespace Drupal\job_hunter\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Plugin\Field\FieldWidget\NumberWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Temporary debugging widget to identify which field is causing NumberWidget issues.
 *
 * @FieldWidget(
 *   id = "debug_number",
 *   label = @Translation("Debug Number"),
 *   field_types = {
 *     "integer",
 *     "decimal",
 *     "float"
 *   }
 * )
 */
class DebugNumberWidget extends NumberWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $items->getFieldDefinition()->getName();
    $entity_type = $items->getEntity()->getEntityTypeId();
    $bundle = $items->getEntity()->bundle();
    
    // Log the field information for debugging
    \Drupal::logger('job_hunter')->warning('Processing number field: @field for @entity_type:@bundle. Settings: @settings', [
      '@field' => $field_name,
      '@entity_type' => $entity_type,
      '@bundle' => $bundle,
      '@settings' => json_encode($this->getSettings()),
    ]);
    
    // Check if prefix/suffix keys exist in settings
    $settings = $this->getSettings();
    if (!array_key_exists('prefix', $settings)) {
      \Drupal::logger('job_hunter')->error('MISSING PREFIX KEY in field @field settings. Available keys: @keys', [
        '@field' => $field_name,
        '@keys' => implode(', ', array_keys($settings)),
      ]);
    }
    
    if (!array_key_exists('suffix', $settings)) {
      \Drupal::logger('job_hunter')->error('MISSING SUFFIX KEY in field @field settings. Available keys: @keys', [
        '@field' => $field_name,
        '@keys' => implode(', ', array_keys($settings)),
      ]);
    }
    
    return parent::formElement($items, $delta, $element, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'placeholder' => '',
      'prefix' => '',
      'suffix' => '',
    ] + parent::defaultSettings();
  }

}