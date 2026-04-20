<?php

declare(strict_types=1);

namespace Drupal\Tests\users_metrics\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\RoleInterface;
use Drupal\users_metrics\Form\SettingsForm;

/**
 * Tests the SettingsForm class.
 *
 * @group users_metrics
 * @coversDefaultClass \Drupal\users_metrics\Form\SettingsForm
 */
class SettingsFormTest extends UnitTestCase {

  /**
   * The config factory mock.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The typed config manager mock.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected TypedConfigManagerInterface $typedConfigManager;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The settings form under test.
   *
   * @var \Drupal\users_metrics\Form\SettingsForm
   */
  protected SettingsForm $form;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->typedConfigManager = $this->createMock(TypedConfigManagerInterface::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    // Set up string translation.
    $string_translation = $this->createMock(TranslationInterface::class);
    $string_translation->method('translateString')
      ->willReturnCallback(function ($string) {
        return $string->getUntranslatedString();
      });

    $container = new ContainerBuilder();
    $container->set('string_translation', $string_translation);
    \Drupal::setContainer($container);

    $this->form = new SettingsForm(
      $this->configFactory,
      $this->typedConfigManager,
      $this->entityTypeManager
    );
  }

  /**
   * Tests the form ID.
   *
   * @covers ::getFormId
   */
  public function testGetFormId(): void {
    $this->assertEquals('users_metrics_settings', $this->form->getFormId());
  }

  /**
   * Tests the editable config names.
   *
   * @covers ::getEditableConfigNames
   */
  public function testGetEditableConfigNames(): void {
    $method = new \ReflectionMethod(SettingsForm::class, 'getEditableConfigNames');
    $method->setAccessible(TRUE);

    $config_names = $method->invoke($this->form);

    $this->assertEquals(['users_metrics.settings'], $config_names);
  }

  /**
   * Tests form validation with valid UIDs.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithValidUids(): void {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $form_state->expects($this->once())
      ->method('getValue')
      ->with('excluded_uids')
      ->willReturn('1, 2, 3');

    $form_state->expects($this->never())
      ->method('setErrorByName');

    $this->form->validateForm($form, $form_state);
  }

  /**
   * Tests form validation with invalid UIDs.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithInvalidUids(): void {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $form_state->expects($this->once())
      ->method('getValue')
      ->with('excluded_uids')
      ->willReturn('1, abc, 3');

    $form_state->expects($this->once())
      ->method('setErrorByName')
      ->with('excluded_uids', $this->anything());

    $this->form->validateForm($form, $form_state);
  }

  /**
   * Tests form validation with negative UIDs.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithNegativeUids(): void {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $form_state->expects($this->once())
      ->method('getValue')
      ->with('excluded_uids')
      ->willReturn('1, -5, 3');

    $form_state->expects($this->once())
      ->method('setErrorByName')
      ->with('excluded_uids', $this->anything());

    $this->form->validateForm($form, $form_state);
  }

  /**
   * Tests form validation with empty UIDs.
   *
   * @covers ::validateForm
   */
  public function testValidateFormWithEmptyUids(): void {
    $form = [];
    $form_state = $this->createMock(FormStateInterface::class);

    $form_state->expects($this->once())
      ->method('getValue')
      ->with('excluded_uids')
      ->willReturn('');

    $form_state->expects($this->never())
      ->method('setErrorByName');

    $this->form->validateForm($form, $form_state);
  }

  /**
   * Tests the build form method.
   *
   * @covers ::buildForm
   */
  public function testBuildForm(): void {
    $config = $this->createMock(Config::class);
    $config->method('get')
      ->willReturnMap([
        ['excluded_roles', []],
        ['excluded_uids', [1, 2]],
      ]);

    $this->configFactory->method('get')
      ->with('users_metrics.settings')
      ->willReturn($config);
    $this->configFactory->method('getEditable')
      ->with('users_metrics.settings')
      ->willReturn($config);

    $role1 = $this->createMock(RoleInterface::class);
    $role1->method('label')->willReturn('Administrator');

    $role2 = $this->createMock(RoleInterface::class);
    $role2->method('label')->willReturn('Authenticated user');

    $role_storage = $this->createMock(EntityStorageInterface::class);
    $role_storage->method('loadMultiple')
      ->willReturn([
        'anonymous' => $this->createMock(RoleInterface::class),
        'authenticated' => $role2,
        'administrator' => $role1,
      ]);

    $this->entityTypeManager->method('getStorage')
      ->with('user_role')
      ->willReturn($role_storage);

    $form_state = $this->createMock(FormStateInterface::class);

    $form = $this->form->buildForm([], $form_state);

    $this->assertArrayHasKey('excluded_roles', $form);
    $this->assertArrayHasKey('excluded_uids', $form);
    $this->assertEquals('checkboxes', $form['excluded_roles']['#type']);
    $this->assertEquals('textarea', $form['excluded_uids']['#type']);
    // Anonymous should be excluded from options.
    $this->assertArrayNotHasKey('anonymous', $form['excluded_roles']['#options']);
    $this->assertArrayHasKey('authenticated', $form['excluded_roles']['#options']);
    $this->assertArrayHasKey('administrator', $form['excluded_roles']['#options']);
  }

}
