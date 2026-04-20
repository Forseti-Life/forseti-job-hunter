<?php

namespace Drupal\users_metrics\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Users Metrics settings.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'users_metrics_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['users_metrics.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('users_metrics.settings');

    // Get all roles except anonymous.
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    unset($roles['anonymous']);
    $role_options = [];
    foreach ($roles as $role_id => $role) {
      $role_options[$role_id] = $role->label();
    }

    $form['excluded_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Excluded roles'),
      '#description' => $this->t('Users with these roles will be excluded from statistics. This affects both registration and login metrics.'),
      '#options' => $role_options,
      '#default_value' => $config->get('excluded_roles') ?? [],
    ];

    $excluded_uids = $config->get('excluded_uids') ?? [];
    $form['excluded_uids'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded user IDs'),
      '#description' => $this->t('Enter user IDs to exclude from statistics, one per line or comma-separated. These users will not appear in registration or login metrics.'),
      '#default_value' => implode(', ', $excluded_uids),
      '#placeholder' => $this->t('Example: 1, 2, 3'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $uids_input = $form_state->getValue('excluded_uids');
    if (!empty($uids_input)) {
      // Parse UIDs from input (comma or newline separated).
      $uids = preg_split('/[\s,]+/', $uids_input, -1, PREG_SPLIT_NO_EMPTY);

      foreach ($uids as $uid) {
        if (!is_numeric($uid) || (int) $uid < 0) {
          $form_state->setErrorByName('excluded_uids', $this->t('User IDs must be positive integers. Invalid value: @uid', ['@uid' => $uid]));
          return;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Process roles - filter out unchecked values.
    $roles = array_filter($form_state->getValue('excluded_roles'));
    $roles = array_values($roles);

    // Process UIDs.
    $uids_input = $form_state->getValue('excluded_uids');
    $uids = [];
    if (!empty($uids_input)) {
      $uids = preg_split('/[\s,]+/', $uids_input, -1, PREG_SPLIT_NO_EMPTY);
      $uids = array_map('intval', $uids);
      $uids = array_unique($uids);
      $uids = array_values($uids);
    }

    $this->config('users_metrics.settings')
      ->set('excluded_roles', $roles)
      ->set('excluded_uids', $uids)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
