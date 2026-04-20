<?php

namespace Drupal\safety_calculator\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Safety Assessment entity.
 *
 * Stores completed safety questionnaire assessments.
 *
 * @ContentEntityType(
 *   id = "safety_assessment",
 *   label = @Translation("Safety Assessment"),
 *   label_collection = @Translation("Safety Assessments"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "safety_assessment",
 *   data_table = "safety_assessment_field_data",
 *   translatable = FALSE,
 *   admin_permission = "administer safety calculator",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "user_id",
 *   },
 *   links = {
 *     "canonical" = "/safety-calculator/assessment/{safety_assessment}",
 *     "delete-form" = "/safety-calculator/assessment/{safety_assessment}/delete",
 *     "collection" = "/admin/content/safety-assessments",
 *   },
 * )
 */
class SafetyAssessment extends ContentEntityBase implements EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Add owner field from trait.
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    // Location information
    $fields['location'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Location'))
      ->setDescription(t('Address or location description'))
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['latitude'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Latitude'))
      ->setDescription(t('Location latitude'))
      ->setSettings([
        'precision' => 10,
        'scale' => 7,
      ]);

    $fields['longitude'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Longitude'))
      ->setDescription(t('Location longitude'))
      ->setSettings([
        'precision' => 10,
        'scale' => 7,
      ]);

    // Dimension: Safe (Security & Protection)
    // Store as JSON for flexibility with all responses
    $fields['safe_responses'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Safe Dimension Responses'))
      ->setDescription(t('JSON-encoded responses for Safe dimension'));

    $fields['safe_score'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Safe Score'))
      ->setDescription(t('Calculated score for Safe dimension'))
      ->setSettings([
        'precision' => 5,
        'scale' => 2,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 0,
      ]);

    // Dimension: Energized (Vitality & Basic Needs)
    $fields['energized_responses'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Energized Dimension Responses'))
      ->setDescription(t('JSON-encoded responses for Energized dimension'));

    $fields['energized_score'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Energized Score'))
      ->setSettings([
        'precision' => 5,
        'scale' => 2,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 1,
      ]);

    // Dimension: Connected (Community & Belonging)
    $fields['connected_responses'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Connected Dimension Responses'))
      ->setDescription(t('JSON-encoded responses for Connected dimension'));

    $fields['connected_score'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Connected Score'))
      ->setSettings([
        'precision' => 5,
        'scale' => 2,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 2,
      ]);

    // Dimension: Free (Autonomy & Rights)
    $fields['free_responses'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Free Dimension Responses'))
      ->setDescription(t('JSON-encoded responses for Free dimension'));

    $fields['free_score'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Free Score'))
      ->setSettings([
        'precision' => 5,
        'scale' => 2,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 3,
      ]);

    // Dimension: Capable (Mastery & Development)
    $fields['capable_responses'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Capable Dimension Responses'))
      ->setDescription(t('JSON-encoded responses for Capable dimension'));

    $fields['capable_score'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Capable Score'))
      ->setSettings([
        'precision' => 5,
        'scale' => 2,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 4,
      ]);

    // Dimension: Useful (Purpose & Contribution)
    $fields['useful_responses'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Useful Dimension Responses'))
      ->setDescription(t('JSON-encoded responses for Useful dimension'));

    $fields['useful_score'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Useful Score'))
      ->setSettings([
        'precision' => 5,
        'scale' => 2,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 5,
      ]);

    // Dimension: Whole (Holistic Health & Identity)
    $fields['whole_responses'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Whole Dimension Responses'))
      ->setDescription(t('JSON-encoded responses for Whole dimension'));

    $fields['whole_score'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Whole Score'))
      ->setSettings([
        'precision' => 5,
        'scale' => 2,
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 6,
      ]);

    // Overall score
    $fields['overall_score'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Overall Safety Score'))
      ->setDescription(t('Calculated overall safety score'))
      ->setSettings([
        'precision' => 5,
        'scale' => 2,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_decimal',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('view', TRUE);

    // Assessment metadata
    $fields['completed'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Completed'))
      ->setDescription(t('Whether the assessment is fully completed'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'boolean',
        'weight' => 10,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the assessment was created.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 11,
      ]);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the assessment was last edited.'));

    return $fields;
  }

}
