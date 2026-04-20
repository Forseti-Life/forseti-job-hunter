<?php

declare(strict_types=1);

namespace Drupal\Tests\users_metrics\Unit\Service;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\users_metrics\Service\FieldLabelResolver;

/**
 * Tests the FieldLabelResolver service.
 *
 * @group users_metrics
 * @coversDefaultClass \Drupal\users_metrics\Service\FieldLabelResolver
 */
class FieldLabelResolverTest extends UnitTestCase {

  /**
   * The language manager mock.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager mock.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The field label resolver under test.
   *
   * @var \Drupal\users_metrics\Service\FieldLabelResolver
   */
  protected FieldLabelResolver $fieldLabelResolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->languageManager = $this->createMock(LanguageManagerInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);

    // Set up string translation.
    $string_translation = $this->createMock(TranslationInterface::class);
    $string_translation->method('translateString')
      ->willReturnCallback(function ($string) {
        return $string->getUntranslatedString();
      });

    $container = new ContainerBuilder();
    $container->set('string_translation', $string_translation);
    \Drupal::setContainer($container);

    $this->fieldLabelResolver = new FieldLabelResolver(
      $this->languageManager,
      $this->entityTypeManager,
      $this->entityFieldManager
    );
  }

  /**
   * Tests resolving label with empty value.
   *
   * @covers ::resolveLabel
   */
  public function testResolveLabelWithEmptyValue(): void {
    $result = $this->fieldLabelResolver->resolveLabel('any_field', '');

    $this->assertEquals('(empty)', $result);
  }

  /**
   * Tests resolving label with language code.
   *
   * @covers ::resolveLabel
   */
  public function testResolveLabelWithLangcode(): void {
    $english_language = $this->createMock(LanguageInterface::class);
    $english_language->method('getName')
      ->willReturn('English');

    $spanish_language = $this->createMock(LanguageInterface::class);
    $spanish_language->method('getName')
      ->willReturn('Spanish');

    $languages = [
      'en' => $english_language,
      'es' => $spanish_language,
    ];

    $this->languageManager->expects($this->once())
      ->method('getLanguages')
      ->willReturn($languages);

    $result = $this->fieldLabelResolver->resolveLabel('langcode', 'en');

    $this->assertEquals('English', $result);
  }

  /**
   * Tests resolving label with unknown language code.
   *
   * @covers ::resolveLabel
   */
  public function testResolveLabelWithLangcodeUnknown(): void {
    $english_language = $this->createMock(LanguageInterface::class);
    $english_language->method('getName')
      ->willReturn('English');

    $languages = [
      'en' => $english_language,
    ];

    $this->languageManager->expects($this->once())
      ->method('getLanguages')
      ->willReturn($languages);

    $result = $this->fieldLabelResolver->resolveLabel('langcode', 'unknown');

    $this->assertEquals('unknown', $result);
  }

  /**
   * Tests resolving label with list field.
   *
   * @covers ::resolveLabel
   */
  public function testResolveLabelWithListField(): void {
    $field_storage = $this->createMock(FieldStorageConfigInterface::class);
    $field_storage->method('getSettings')
      ->willReturn([
        'allowed_values' => [
          'active' => 'Active User',
          'blocked' => 'Blocked User',
          'pending' => 'Pending Approval',
        ],
      ]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with('user.field_user_status')
      ->willReturn($field_storage);

    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('field_storage_config')
      ->willReturn($storage);

    $result = $this->fieldLabelResolver->resolveLabel('field_user_status', 'active');

    $this->assertEquals('Active User', $result);
  }

  /**
   * Tests resolving label with list field but value not in allowed values.
   *
   * @covers ::resolveLabel
   */
  public function testResolveLabelWithListFieldValueNotInAllowedValues(): void {
    $field_storage = $this->createMock(FieldStorageConfigInterface::class);
    $field_storage->method('getSettings')
      ->willReturn([
        'allowed_values' => [
          'active' => 'Active User',
          'blocked' => 'Blocked User',
        ],
      ]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with('user.field_user_status')
      ->willReturn($field_storage);

    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('field_storage_config')
      ->willReturn($storage);

    $result = $this->fieldLabelResolver->resolveLabel('field_user_status', 'unknown_status');

    $this->assertEquals('unknown_status', $result);
  }

  /**
   * Tests resolving label with list field that has no storage.
   *
   * @covers ::resolveLabel
   */
  public function testResolveLabelWithListFieldNoStorage(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with('user.field_custom_field')
      ->willReturn(NULL);

    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('field_storage_config')
      ->willReturn($storage);

    $result = $this->fieldLabelResolver->resolveLabel('field_custom_field', 'some_value');

    $this->assertEquals('some_value', $result);
  }

  /**
   * Tests resolving label with list field that throws exception.
   *
   * @covers ::resolveLabel
   */
  public function testResolveLabelWithListFieldException(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with('user.field_problematic')
      ->willThrowException(new \Exception('Storage error'));

    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('field_storage_config')
      ->willReturn($storage);

    $result = $this->fieldLabelResolver->resolveLabel('field_problematic', 'fallback_value');

    $this->assertEquals('fallback_value', $result);
  }

  /**
   * Tests resolving label with regular value.
   *
   * @covers ::resolveLabel
   */
  public function testResolveLabelWithRegularValue(): void {
    // No mocks should be called for regular fields.
    $result = $this->fieldLabelResolver->resolveLabel('uid', '123');

    $this->assertEquals('123', $result);
  }

  /**
   * Tests resolving label with regular string value.
   *
   * @covers ::resolveLabel
   */
  public function testResolveLabelWithRegularStringValue(): void {
    $result = $this->fieldLabelResolver->resolveLabel('name', 'john_doe');

    $this->assertEquals('john_doe', $result);
  }

  /**
   * Tests resolving label with status field.
   *
   * @covers ::resolveLabel
   */
  public function testResolveLabelWithStatusField(): void {
    // Status is not a custom field (doesn't start with field_).
    // It should return the original value.
    $result = $this->fieldLabelResolver->resolveLabel('status', '1');

    $this->assertEquals('1', $result);
  }

  /**
   * Tests isFieldGroupable with langcode field.
   *
   * @covers ::isFieldGroupable
   */
  public function testIsFieldGroupableWithLangcode(): void {
    $result = $this->fieldLabelResolver->isFieldGroupable('langcode');

    $this->assertTrue($result);
  }

  /**
   * Tests isFieldGroupable with groupable field types.
   *
   * @covers ::isFieldGroupable
   */
  public function testIsFieldGroupableWithGroupableTypes(): void {
    $groupable_types = [
      'entity_reference',
      'list_string',
      'list_integer',
      'list_float',
      'boolean',
      'language',
    ];

    foreach ($groupable_types as $field_type) {
      $field_definition = $this->createMock(FieldDefinitionInterface::class);
      $field_definition->method('getType')
        ->willReturn($field_type);

      $this->entityFieldManager->expects($this->once())
        ->method('getFieldDefinitions')
        ->with('user', 'user')
        ->willReturn([
          'field_test' => $field_definition,
        ]);

      $result = $this->fieldLabelResolver->isFieldGroupable('field_test');

      $this->assertTrue($result, "Field type '{$field_type}' should be groupable");

      // Reset the mock for next iteration.
      $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
      $this->fieldLabelResolver = new FieldLabelResolver(
        $this->languageManager,
        $this->entityTypeManager,
        $this->entityFieldManager
      );
    }
  }

  /**
   * Tests isFieldGroupable with non-groupable field type.
   *
   * @covers ::isFieldGroupable
   */
  public function testIsFieldGroupableWithNonGroupableType(): void {
    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getType')
      ->willReturn('string');

    $this->entityFieldManager->expects($this->once())
      ->method('getFieldDefinitions')
      ->with('user', 'user')
      ->willReturn([
        'field_text' => $field_definition,
      ]);

    $result = $this->fieldLabelResolver->isFieldGroupable('field_text');

    $this->assertFalse($result);
  }

  /**
   * Tests isFieldGroupable with non-existent field.
   *
   * @covers ::isFieldGroupable
   */
  public function testIsFieldGroupableWithNonExistentField(): void {
    $this->entityFieldManager->expects($this->once())
      ->method('getFieldDefinitions')
      ->with('user', 'user')
      ->willReturn([]);

    $result = $this->fieldLabelResolver->isFieldGroupable('field_nonexistent');

    $this->assertFalse($result);
  }

}
