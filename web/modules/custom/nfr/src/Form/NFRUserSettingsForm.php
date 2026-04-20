<?php

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;

/**
 * User account settings form for NFR participants.
 */
class NFRUserSettingsForm extends FormBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public function __construct(AccountInterface $current_user, Connection $database) {
    $this->currentUser = $current_user;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'nfr_user_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();
    
    // Load current settings from user profile
    $profile = $this->database->select('nfr_user_profile', 'p')
      ->fields('p')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    $form['#attached']['library'][] = 'nfr/nfr-forms';

    $form['intro'] = [
      '#markup' => '<div class="alert alert-info">' . 
        $this->t('Manage your account preferences and communication settings.') . 
        '</div>',
    ];

    // Communication Preferences
    $form['communication'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Communication Preferences'),
    ];

    $form['communication']['alternate_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Alternate Email'),
      '#default_value' => $profile['alternate_email'] ?? '',
      '#description' => $this->t('An additional email address where we can reach you. To change your primary email, visit your <a href="/user/edit">account page</a>.'),
    ];

    $form['communication']['mobile_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Mobile Phone'),
      '#default_value' => $profile['mobile_phone'] ?? '',
      '#description' => $this->t('Your mobile phone number for SMS notifications.'),
    ];

    $form['communication']['sms_opt_in'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable SMS Notifications'),
      '#default_value' => $profile['sms_opt_in'] ?? 0,
      '#description' => $this->t('Receive text message reminders for follow-up surveys (requires mobile phone number).'),
      '#states' => [
        'visible' => [
          ':input[name="mobile_phone"]' => ['filled' => TRUE],
        ],
      ],
    ];

    // Password Change
    $form['password'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Password'),
    ];

    $form['password']['password_info'] = [
      '#markup' => '<p>' . 
        $this->t('To change your password, visit your <a href="/user/edit">account page</a>.') . 
        '</p>',
    ];

    // Privacy & Data
    $form['privacy'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Privacy & Data'),
    ];

    $form['privacy']['privacy_info'] = [
      '#markup' => '<p>' . 
        $this->t('Your privacy settings were established during enrollment. To modify cancer registry linkage consent, please <a href="/nfr/contact">contact us</a>.') . 
        '</p>',
    ];

    // Account Actions
    $form['account'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Account Actions'),
    ];

    $form['account']['account_links'] = [
      '#markup' => '<ul>' .
        '<li><a href="/user/edit">' . $this->t('View Full Drupal Account Settings') . '</a></li>' .
        '<li><a href="/nfr/my-profile">' . $this->t('Edit My Profile') . '</a></li>' .
        '<li><a href="/nfr/contact">' . $this->t('Contact Support') . '</a></li>' .
        '</ul>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Settings'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('nfr.dashboard'),
      '#attributes' => ['class' => ['btn', 'btn-secondary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate alternate email if provided
    $alternate_email = $form_state->getValue('alternate_email');
    if (!empty($alternate_email)) {
      if (!\Drupal::service('email.validator')->isValid($alternate_email)) {
        $form_state->setErrorByName('alternate_email', $this->t('Please enter a valid email address.'));
      }
      
      // Check that it's different from primary email
      $user = \Drupal\user\Entity\User::load($this->currentUser->id());
      if ($user && $alternate_email === $user->getEmail()) {
        $form_state->setErrorByName('alternate_email', $this->t('Alternate email must be different from your primary email.'));
      }
    }

    // Validate mobile phone format if provided
    $mobile_phone = $form_state->getValue('mobile_phone');
    if (!empty($mobile_phone)) {
      // Basic US phone number validation
      $phone_cleaned = preg_replace('/\D/', '', $mobile_phone);
      if (strlen($phone_cleaned) != 10) {
        $form_state->setErrorByName('mobile_phone', $this->t('Please enter a valid 10-digit US phone number.'));
      }
    }

    // If SMS opted in, require mobile phone
    if ($form_state->getValue('sms_opt_in') && empty($mobile_phone)) {
      $form_state->setErrorByName('sms_opt_in', $this->t('Please provide a mobile phone number to enable SMS notifications.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = $this->currentUser->id();

    try {
      // Update user profile with new settings
      $this->database->update('nfr_user_profile')
        ->fields([
          'alternate_email' => $form_state->getValue('alternate_email') ?: NULL,
          'mobile_phone' => $form_state->getValue('mobile_phone') ?: NULL,
          'sms_opt_in' => $form_state->getValue('sms_opt_in') ? 1 : 0,
          'updated' => time(),
        ])
        ->condition('uid', $uid)
        ->execute();

      $this->messenger()->addStatus($this->t('Your settings have been saved.'));
      
      // Redirect to dashboard
      $form_state->setRedirect('nfr.dashboard');
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('An error occurred while saving your settings. Please try again.'));
      \Drupal::logger('nfr')->error('Error saving user settings for UID @uid: @message', [
        '@uid' => $uid,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
