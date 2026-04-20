<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * User Profile form for NFR enrollment (5-minute form).
 */
class NFRUserProfileForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs a new NFRUserProfileForm.
   */
  public function __construct(Connection $database, AccountProxyInterface $current_user) {
    $this->database = $database;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'nfr_user_profile_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Check if uid parameter is provided (for admin viewing other users)
    $request = \Drupal::request();
    $uid = $request->query->get('uid');
    
    // If no uid parameter or user is not admin, use current user
    if (!$uid || !$this->currentUser->hasPermission('administer nfr')) {
      $uid = $this->currentUser->id();
    }

    // Load existing profile data.
    $profile = $this->database->select('nfr_user_profile', 'p')
      ->fields('p')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    // Store uid in form state for submit handler
    $form_state->set('profile_uid', $uid);

    $form['#attached']['library'][] = 'nfr/enrollment';

    $form['intro'] = [
      '#markup' => '<div class="profile-intro"><h2>User Profile</h2><p>Please provide your personal information. This should take approximately 5 minutes.</p><p><small><em>Fields marked with * are required.</em></small></p></div>',
    ];

    // Personal Information Section
    $form['personal_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Personal Information'),
      '#collapsible' => FALSE,
    ];

    $form['personal_info']['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#required' => TRUE,
      '#default_value' => $profile['first_name'] ?? '',
    ];

    $form['personal_info']['middle_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Middle Name'),
      '#default_value' => $profile['middle_name'] ?? '',
    ];

    $form['personal_info']['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#required' => TRUE,
      '#default_value' => $profile['last_name'] ?? '',
    ];

    $form['personal_info']['date_of_birth'] = [
      '#type' => 'date',
      '#title' => $this->t('Date of Birth'),
      '#required' => TRUE,
      '#default_value' => $profile['date_of_birth'] ?? '',
      '#description' => $this->t('You must be 18 or older to participate.'),
    ];

    $form['personal_info']['sex'] = [
      '#type' => 'select',
      '#title' => $this->t('Sex'),
      '#required' => TRUE,
      '#options' => [
        '' => $this->t('- Select -'),
        'male' => $this->t('Male'),
        'female' => $this->t('Female'),
        'intersex' => $this->t('Intersex'),
      ],
      '#default_value' => $profile['sex'] ?? '',
    ];

    $form['personal_info']['ssn_last_4'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last 4 digits of SSN'),
      '#description' => $this->t('Optional. Helps with record linkage.'),
      '#maxlength' => 4,
      '#size' => 4,
      '#default_value' => $profile['ssn_last_4'] ?? '',
    ];

    // Birth Information
    $form['birth_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Birth Information'),
    ];

    $form['birth_info']['country_of_birth'] = [
      '#type' => 'select',
      '#title' => $this->t('Country of Birth'),
      '#required' => TRUE,
      '#options' => $this->getCountryOptions(),
      '#default_value' => $profile['country_of_birth'] ?? 'USA',
    ];

    $form['birth_info']['state_of_birth'] = [
      '#type' => 'select',
      '#title' => $this->t('State of Birth'),
      '#options' => $this->getStateOptions(),
      '#default_value' => $profile['state_of_birth'] ?? '',
      '#states' => [
        'visible' => [
          ':input[name="country_of_birth"]' => ['value' => 'USA'],
        ],
        'required' => [
          ':input[name="country_of_birth"]' => ['value' => 'USA'],
        ],
      ],
    ];

    $form['birth_info']['city_of_birth'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City of Birth'),
      '#default_value' => $profile['city_of_birth'] ?? '',
    ];

    // Contact Information
    $form['contact_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Contact Information'),
    ];

    $form['contact_info']['address_line1'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Address Line 1'),
      '#required' => TRUE,
      '#default_value' => $profile['address_line1'] ?? '',
    ];

    $form['contact_info']['address_line2'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Address Line 2'),
      '#description' => $this->t('Apartment, suite, unit, etc.'),
      '#default_value' => $profile['address_line2'] ?? '',
    ];

    $form['contact_info']['city'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City'),
      '#required' => TRUE,
      '#default_value' => $profile['city'] ?? '',
    ];

    $form['contact_info']['state'] = [
      '#type' => 'select',
      '#title' => $this->t('State'),
      '#required' => TRUE,
      '#options' => $this->getStateOptions(),
      '#default_value' => $profile['state'] ?? '',
    ];

    $form['contact_info']['zip_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('ZIP Code'),
      '#required' => TRUE,
      '#maxlength' => 10,
      '#size' => 10,
      '#default_value' => $profile['zip_code'] ?? '',
    ];

    $form['contact_info']['alternate_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Alternate Email'),
      '#description' => $this->t('Optional alternate email address.'),
      '#default_value' => $profile['alternate_email'] ?? '',
    ];

    $form['contact_info']['mobile_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Mobile Phone'),
      '#description' => $this->t('Optional. For SMS notifications.'),
      '#default_value' => $profile['mobile_phone'] ?? '',
    ];

    $form['contact_info']['sms_opt_in'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I want to receive SMS text message notifications about the NFR'),
      '#default_value' => $profile['sms_opt_in'] ?? 0,
      '#states' => [
        'visible' => [
          ':input[name="mobile_phone"]' => ['filled' => TRUE],
        ],
      ],
    ];

    // Current Work Status
    $form['work_status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Current Work Status'),
    ];

    $form['work_status']['current_work_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Current Work Status'),
      '#required' => TRUE,
      '#options' => [
        '' => $this->t('- Select -'),
        'active' => $this->t('Currently Active Firefighter'),
        'retired' => $this->t('Retired Firefighter'),
        'student' => $this->t('Fire Academy Student'),
        'inactive' => $this->t('Inactive/Not Currently Working'),
        'other' => $this->t('Other'),
      ],
      '#default_value' => $profile['current_work_status'] ?? '',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and Continue'),
      '#button_type' => 'primary',
    ];

    $form['actions']['save_exit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and Exit'),
      '#submit' => ['::saveAndExit'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Validate age (must be 18+).
    $dob = $form_state->getValue('date_of_birth');
    if ($dob) {
      $dob_timestamp = strtotime($dob);
      $age = floor((time() - $dob_timestamp) / 31536000);
      
      if ($age < 18) {
        $form_state->setErrorByName('date_of_birth', $this->t('You must be at least 18 years old to participate in the NFR.'));
      }
    }

    // Validate SSN last 4 (must be numeric if provided).
    $ssn = $form_state->getValue('ssn_last_4');
    if ($ssn && !preg_match('/^\d{4}$/', $ssn)) {
      $form_state->setErrorByName('ssn_last_4', $this->t('SSN last 4 digits must be exactly 4 numbers.'));
    }

    // Validate ZIP code format.
    $zip = $form_state->getValue('zip_code');
    if ($zip && !preg_match('/^\d{5}(-\d{4})?$/', $zip)) {
      $form_state->setErrorByName('zip_code', $this->t('Please enter a valid ZIP code (e.g., 12345 or 12345-6789).'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->saveProfile($form_state, TRUE);
    $this->messenger()->addStatus($this->t('Your profile has been saved.'));
    $form_state->setRedirect('nfr.enrollment_questionnaire');
  }

  /**
   * Save and exit submit handler.
   */
  public function saveAndExit(array &$form, FormStateInterface $form_state): void {
    $this->saveProfile($form_state, FALSE);
    $this->messenger()->addStatus($this->t('Your profile has been saved. You can continue later.'));
    $form_state->setRedirect('nfr.my_dashboard');
  }

  /**
   * Save profile data to database.
   */
  protected function saveProfile(FormStateInterface $form_state, bool $completed): void {
    // Use the stored uid from buildForm (may be different from current user if admin is viewing)
    $uid = $form_state->get('profile_uid') ?? $this->currentUser->id();
    $values = $form_state->getValues();

    $fields = [
      'uid' => $uid,
      'first_name' => $values['first_name'],
      'middle_name' => $values['middle_name'],
      'last_name' => $values['last_name'],
      'date_of_birth' => $values['date_of_birth'],
      'sex' => $values['sex'],
      'ssn_last_4' => $values['ssn_last_4'],
      'country_of_birth' => $values['country_of_birth'],
      'state_of_birth' => $values['state_of_birth'] ?? NULL,
      'city_of_birth' => $values['city_of_birth'],
      'address_line1' => $values['address_line1'],
      'address_line2' => $values['address_line2'],
      'city' => $values['city'],
      'state' => $values['state'],
      'zip_code' => $values['zip_code'],
      'alternate_email' => $values['alternate_email'],
      'mobile_phone' => $values['mobile_phone'],
      'sms_opt_in' => $values['sms_opt_in'] ? 1 : 0,
      'current_work_status' => $values['current_work_status'],
      'profile_completed' => $completed ? 1 : 0,
      'profile_completed_date' => $completed ? time() : NULL,
      'updated' => time(),
    ];

    // Check if profile already exists.
    $existing = $this->database->select('nfr_user_profile', 'p')
      ->fields('p', ['id'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    if ($existing) {
      $this->database->update('nfr_user_profile')
        ->fields($fields)
        ->condition('uid', $uid)
        ->execute();
    }
    else {
      $fields['created'] = time();
      $fields['participant_id'] = $this->generateParticipantId();
      
      try {
        $this->database->insert('nfr_user_profile')
          ->fields($fields)
          ->execute();
      }
      catch (\Exception $e) {
        // If insert fails (e.g., duplicate key), try update instead
        // This handles race conditions where profile was created between check and insert
        if (strpos($e->getMessage(), 'Duplicate entry') !== FALSE || strpos($e->getMessage(), 'participant_id') !== FALSE) {
          unset($fields['created']);
          unset($fields['participant_id']);
          $this->database->update('nfr_user_profile')
            ->fields($fields)
            ->condition('uid', $uid)
            ->execute();
        }
        else {
          // Re-throw if it's a different error
          throw $e;
        }
      }
    }
  }

  /**
   * Generate unique participant ID.
   */
  protected function generateParticipantId(): string {
    // Format: NFR-YYYYMMDD-XXXX (e.g., NFR-20260125-0001)
    $date_part = date('Ymd');
    
    // Try up to 100 times to find a unique ID (handles race conditions)
    $max_attempts = 100;
    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
      // Get count of profiles created today
      $count = $this->database->select('nfr_user_profile', 'p')
        ->condition('created', strtotime('today'), '>=')
        ->countQuery()
        ->execute()
        ->fetchField();
      
      $sequence = str_pad((string) ($count + $attempt), 4, '0', STR_PAD_LEFT);
      $participant_id = "NFR-{$date_part}-{$sequence}";
      
      // Check if this ID already exists
      $exists = $this->database->select('nfr_user_profile', 'p')
        ->fields('p', ['id'])
        ->condition('participant_id', $participant_id)
        ->execute()
        ->fetchField();
      
      if (!$exists) {
        return $participant_id;
      }
    }
    
    // Fallback: use microtime for uniqueness
    $sequence = str_pad((string) ($count + $max_attempts + (int)(microtime(true) * 1000) % 1000), 4, '0', STR_PAD_LEFT);
    return "NFR-{$date_part}-{$sequence}";
  }

  /**
   * Get country options.
   */
  protected function getCountryOptions(): array {
    return [
      '' => $this->t('- Select -'),
      'USA' => $this->t('United States'),
      'other' => $this->t('Other'),
    ];
  }

  /**
   * Get US state options.
   */
  protected function getStateOptions(): array {
    return [
      '' => $this->t('- Select -'),
      'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
      'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
      'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
      'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
      'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
      'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
      'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
      'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
      'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
      'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
      'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
      'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
      'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'DC' => 'District of Columbia',
      'PR' => 'Puerto Rico', 'VI' => 'Virgin Islands', 'GU' => 'Guam',
    ];
  }

}
