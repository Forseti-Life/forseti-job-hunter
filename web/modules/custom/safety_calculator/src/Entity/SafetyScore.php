<?php

namespace Drupal\safety_calculator\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Safety Score entity.
 *
 * @ContentEntityType(
 *   id = "safety_score",
 *   label = @Translation("Safety Score"),
 *   label_collection = @Translation("Safety Scores"),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *   },
 *   base_table = "safety_score",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "user_id",
 *   },
 *   admin_permission = "administer safety calculator",
 * )
 */
class SafetyScore extends ContentEntityBase implements EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Add owner field from trait.
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['hexagon_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Hexagon ID'))
      ->setDescription(t('H3 hexagon identifier'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => 10,
      ]);

    $fields['latitude'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Latitude'))
      ->setDescription(t('Latitude coordinate'))
      ->setRequired(TRUE)
      ->setSetting('precision', 10)
      ->setSetting('scale', 7)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 20,
      ]);

    $fields['longitude'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Longitude'))
      ->setDescription(t('Longitude coordinate'))
      ->setRequired(TRUE)
      ->setSetting('precision', 10)
      ->setSetting('scale', 7)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 21,
      ]);

    $fields['resolution'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Resolution'))
      ->setDescription(t('H3 resolution level'))
      ->setRequired(TRUE)
      ->setDefaultValue(13)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 30,
      ]);

    $fields['score'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Safety Score'))
      ->setDescription(t('Safety score (0-100)'))
      ->setRequired(TRUE)
      ->setSetting('precision', 5)
      ->setSetting('scale', 2)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_decimal',
        'weight' => 1,
      ]);

    $fields['risk_level'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Risk Level'))
      ->setDescription(t('Risk category'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        'low' => 'Low',
        'moderate' => 'Moderate',
        'high' => 'High',
        'critical' => 'Critical',
      ])
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 2,
      ]);

    $fields['crime_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Crime Count'))
      ->setDescription(t('Total crimes in analysis area'))
      ->setRequired(TRUE)
      ->setDefaultValue(0)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 40,
      ]);

    $fields['hexagons_analyzed'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Hexagons Analyzed'))
      ->setDescription(t('Number of hexagons included in calculation'))
      ->setRequired(TRUE)
      ->setDefaultValue(1)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 41,
      ]);

    $fields['crime_details'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Crime Details'))
      ->setDescription(t('JSON crime breakdown by type'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 50,
      ]);

    $fields['calculation_options'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Calculation Options'))
      ->setDescription(t('JSON calculation parameters'))
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 60,
      ]);

    $fields['account_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Account Type'))
      ->setDescription(t('Type of account that requested this calculation'))
      ->setSetting('allowed_values', [
        'individual' => 'Individual',
        'family' => 'Family',
        'institution' => 'Institution',
        'anonymous' => 'Anonymous',
      ])
      ->setDefaultValue('anonymous')
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'list_default',
        'weight' => 70,
      ]);

    $fields['account_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Account ID'))
      ->setDescription(t('Related family or institution ID'))
      ->setDefaultValue(0)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'number_integer',
        'weight' => 71,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the score was calculated.'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 80,
      ]);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    $fields['expires'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Expires'))
      ->setDescription(t('Cache expiration time'))
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'timestamp',
        'weight' => 81,
      ]);

    return $fields;
  }

  /**
   * Gets the hexagon ID.
   *
   * @return string
   *   The hexagon identifier.
   */
  public function getHexagonId() {
    return $this->get('hexagon_id')->value;
  }

  /**
   * Gets the safety score.
   *
   * @return float
   *   The safety score.
   */
  public function getScore() {
    return (float) $this->get('score')->value;
  }

  /**
   * Gets the risk level.
   *
   * @return string
   *   The risk level.
   */
  public function getRiskLevel() {
    return $this->get('risk_level')->value;
  }

  /**
   * Gets crime details as array.
   *
   * @return array
   *   Crime details decoded from JSON.
   */
  public function getCrimeDetails() {
    $json = $this->get('crime_details')->value;
    return $json ? json_decode($json, TRUE) : [];
  }

  /**
   * Check if this score has expired.
   *
   * @return bool
   *   TRUE if expired, FALSE otherwise.
   */
  public function isExpired() {
    $expires = $this->get('expires')->value;
    return $expires && $expires < time();
  }

}
