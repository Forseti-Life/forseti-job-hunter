<?php

namespace Drupal\group\Entity\Storage;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\State\State;
use Drupal\group\Entity\GroupTypeInterface;
use Drupal\group\Plugin\Group\Relation\GroupRelationInterface;
use Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the storage handler class for relationship type entities.
 *
 * This extends the base storage class, adding required special handling for
 * loading relationship type entities based on group type and plugin ID.
 */
class GroupRelationshipTypeStorage extends ConfigEntityStorage implements GroupRelationshipTypeStorageInterface {

  /**
   * Statically caches loaded relationship types by target entity type ID.
   *
   * @var \Drupal\group\Entity\GroupRelationshipTypeInterface[][]
   */
  protected $byEntityTypeCache = [];

  /**
   * Statically caches relationship type IDs by group type and plugin ID.
   *
   * @var string[]
   */
  protected $idCache = [];

  public function __construct(
    EntityTypeInterface $entity_type,
    protected GroupRelationTypeManagerInterface $pluginManager,
    ConfigFactoryInterface $config_factory,
    UuidInterface $uuid_service,
    LanguageManagerInterface $language_manager,
    MemoryCacheInterface $memory_cache,
    protected State $state,
  ) {
    parent::__construct($entity_type, $config_factory, $uuid_service, $language_manager, $memory_cache);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('group_relation_type.manager'),
      $container->get('config.factory'),
      $container->get('uuid'),
      $container->get('language_manager'),
      $container->get('entity.memory_cache'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function loadByGroupType(GroupTypeInterface $group_type) {
    return $this->loadByProperties(['group_type' => $group_type->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function loadByPluginId($plugin_id) {
    return $this->loadByProperties(['content_plugin' => $plugin_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function loadByEntityTypeId($entity_type_id) {
    if (isset($this->byEntityTypeCache[$entity_type_id])) {
      return $this->byEntityTypeCache[$entity_type_id];
    }

    // If no responsible group relation types were found, we return nothing.
    $plugin_ids = $this->pluginManager->getPluginIdsByEntityTypeId($entity_type_id);
    if (empty($plugin_ids)) {
      $this->byEntityTypeCache[$entity_type_id] = [];
      return [];
    }

    // Otherwise load all relationship types being handled by gathered plugins.
    return $this->byEntityTypeCache[$entity_type_id] = $this->loadByPluginId($plugin_ids);
  }

  /**
   * {@inheritdoc}
   */
  public function createFromPlugin(GroupTypeInterface $group_type, $plugin_id, array $configuration = []) {
    // Add the group type ID to the configuration.
    $configuration['group_type_id'] = $group_type->id();

    // Instantiate the plugin we are installing.
    $plugin = $this->pluginManager->createInstance($plugin_id, $configuration);
    assert($plugin instanceof GroupRelationInterface);

    // Create the relationship type using plugin generated info.
    $values = [
      'id' => $this->getRelationshipTypeId($group_type->id(), $plugin_id),
      'group_type' => $group_type->id(),
      'content_plugin' => $plugin_id,
      'plugin_config' => $plugin->getConfiguration(),
    ];

    return $this->create($values);
  }

  /**
   * {@inheritdoc}
   */
  public function getRelationshipTypeId($group_type_id, $plugin_id) {
    if (isset($this->idCache[$group_type_id][$plugin_id])) {
      return $this->idCache[$group_type_id][$plugin_id];
    }

    // Legacy versions used to use a different pattern. If we upgraded from one,
    // we need to check the DB for legacy group relationship types and use their
    // ID instead.
    if ($this->state->get('group_update_10300_detected_legacy_version', FALSE)) {
      $ids = $this->getQuery()
        ->condition('group_type', $group_type_id)
        ->condition('content_plugin', $plugin_id)
        ->execute();

      if (!empty($ids)) {
        $this->idCache[$group_type_id][$plugin_id] = reset($ids);
        return $this->idCache[$group_type_id][$plugin_id];
      }
    }

    $preferred_id = $group_type_id . '-' . str_replace(':', '-', $plugin_id);

    // Return a hashed ID if the readable ID would exceed the maximum length.
    if (strlen($preferred_id) > EntityTypeInterface::BUNDLE_MAX_LENGTH) {
      // Try to preserve the group type ID if there is room left for a hash.
      if (EntityTypeInterface::BUNDLE_MAX_LENGTH - strlen($group_type_id) > 8) {
        $hashed_id = $group_type_id . '-' . md5($plugin_id);
      }
      else {
        $hashed_id = 'grt_' . md5($preferred_id);
      }
      $preferred_id = substr($hashed_id, 0, EntityTypeInterface::BUNDLE_MAX_LENGTH);
    }

    return $this->idCache[$group_type_id][$plugin_id] = $preferred_id;
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(?array $ids = NULL) {
    parent::resetCache($ids);
    $this->byEntityTypeCache = [];
    $this->idCache = [];
  }

}
