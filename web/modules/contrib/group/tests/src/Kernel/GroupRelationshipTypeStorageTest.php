<?php

namespace Drupal\Tests\group\Kernel;

use Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface;

/**
 * Tests the behavior of group relationship type storage handler.
 *
 * @coversDefaultClass \Drupal\group\Entity\Storage\GroupRelationshipTypeStorage
 * @group group
 */
class GroupRelationshipTypeStorageTest extends GroupKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group_test_plugin', 'node'];

  /**
   * Tests the generation of a relationship type ID.
   *
   * @covers ::getRelationshipTypeId
   */
  public function testGetRelationshipTypeId() {
    $storage = $this->entityTypeManager->getStorage('group_relationship_type');
    assert($storage instanceof GroupRelationshipTypeStorageInterface);

    $group_type_id = 'some_short_id';
    $plugin_id = 'some_plugin_id';
    $this->assertSame('some_short_id-some_plugin_id', $storage->getRelationshipTypeId($group_type_id, $plugin_id), 'Easily readable name returned when room allows for it.');

    $plugin_id = 'some_really_enormously_long_plugin_id';
    $this->assertSame('some_short_id-f79a8f768a7d37b2aa', $storage->getRelationshipTypeId($group_type_id, $plugin_id), 'Slightly readable name returned when room allows for it.');

    $group_type_id = 'some_really_enormously_long_group_type_id';
    $this->assertSame('grt_6928581958e764d3a5b3f964261b', $storage->getRelationshipTypeId($group_type_id, $plugin_id), 'Garbled name returned when there was no room for a nicer one.');
  }

  /**
   * Tests the retrieval of legacy relationship type IDs.
   *
   * @covers ::getRelationshipTypeId
   * @depends testGetRelationshipTypeId
   */
  public function testGetRelationshipTypeIdLegacy() {
    $storage = $this->entityTypeManager->getStorage('group_relationship_type');
    assert($storage instanceof GroupRelationshipTypeStorageInterface);

    $this->createGroupType(['id' => 'some_short_id']);
    $this->createGroupType(['id' => 'some_other_id']);

    $storage->save($storage->create([
      'id' => 'old_id_pattern',
      'group_type' => 'some_short_id',
      'content_plugin' => 'group_relation',
      'plugin_config' => [],
    ]));
    $this->assertSame('some_short_id-group_relation', $storage->getRelationshipTypeId('some_short_id', 'group_relation'), 'New pattern was returned even if an entity existed with old pattern, because legacy version was not detected.');

    \Drupal::state()->set('group_update_10300_detected_legacy_version', TRUE);
    $storage->resetCache();

    $this->assertSame('old_id_pattern', $storage->getRelationshipTypeId('some_short_id', 'group_relation'), 'Old ID pattern was returned if a legacy entity existed.');
    $this->assertSame('some_other_id-group_relation', $storage->getRelationshipTypeId('some_other_id', 'group_relation'), 'New ID pattern returned if no legacy entity was detected.');
  }

}
