<?php

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * NFR Registration Form.
 */
class NFRRegistrationForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'nfr_registration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    
    $form['description'] = [
      '#markup' => '<div class="nfr-registration-intro">
        <h2>' . $this->t('National Firefighter Registry Registration') . '</h2>
        <p>' . $this->t('Join the NFR to contribute to cancer surveillance research among firefighters.') . '</p>
      </div>',
    ];

    // Personal Information
    $form['personal_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Personal Information'),
      '#open' => TRUE,
    ];

    $form['personal_info']['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#required' => TRUE,
    ];

    $form['personal_info']['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#required' => TRUE,
    ];

    $form['personal_info']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#required' => TRUE,
    ];

    // Firefighting Information
    $form['firefighting_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Firefighting Information'),
      '#open' => TRUE,
    ];

    $form['firefighting_info']['department'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Fire Department'),
      '#required' => TRUE,
    ];

    $form['firefighting_info']['state'] = [
      '#type' => 'select',
      '#title' => $this->t('State'),
      '#options' => $this->getStateOptions(),
      '#required' => TRUE,
    ];

    $form['firefighting_info']['badge_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Badge Number'),
      '#required' => FALSE,
    ];

    $form['firefighting_info']['career_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Career Type'),
      '#options' => [
        '' => $this->t('- Select -'),
        'career' => $this->t('Career'),
        'volunteer' => $this->t('Volunteer'),
        'both' => $this->t('Both'),
      ],
      '#required' => TRUE,
    ];

    $form['firefighting_info']['years_of_service'] = [
      '#type' => 'number',
      '#title' => $this->t('Years of Service'),
      '#min' => 0,
      '#max' => 70,
      '#required' => TRUE,
    ];

    // Health Information
    $form['health_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Health Information (Optional)'),
      '#open' => FALSE,
    ];

    $form['health_info']['cancer_diagnosis'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I have been diagnosed with cancer'),
    ];

    $form['health_info']['cancer_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Type of Cancer'),
      '#states' => [
        'visible' => [
          ':input[name="cancer_diagnosis"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Consent
    $form['consent'] = [
      '#type' => 'details',
      '#title' => $this->t('Consent'),
      '#open' => TRUE,
    ];

    $form['consent']['agree_to_participate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to participate in the National Firefighter Registry'),
      '#required' => TRUE,
    ];

    $form['consent']['agree_to_linkage'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I consent to linkage with state cancer registries'),
      '#required' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Register'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $email = $form_state->getValue('email');
    
    // Check if email already registered
    $connection = \Drupal::database();
    $query = $connection->select('nfr_firefighters', 'n')
      ->fields('n', ['id'])
      ->condition('user_id', NULL, 'IS NOT NULL');
    
    // This is a simplified check - would need proper user email validation
    if (!\Drupal::service('email.validator')->isValid($email)) {
      $form_state->setErrorByName('email', $this->t('Please enter a valid email address.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    
    $connection = \Drupal::database();
    
    // Insert firefighter record
    $firefighter_id = $connection->insert('nfr_firefighters')
      ->fields([
        'first_name' => $values['first_name'],
        'last_name' => $values['last_name'],
        'department' => $values['department'],
        'state' => $values['state'],
        'badge_number' => $values['badge_number'],
        'career_type' => $values['career_type'],
        'years_of_service' => $values['years_of_service'],
        'status' => 'active',
        'created' => \Drupal::time()->getRequestTime(),
        'updated' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    // If cancer diagnosis, add cancer data
    if ($values['cancer_diagnosis'] && !empty($values['cancer_type'])) {
      $connection->insert('nfr_cancer_data')
        ->fields([
          'firefighter_id' => $firefighter_id,
          'cancer_type' => $values['cancer_type'],
          'state_registry_linked' => $values['agree_to_linkage'] ? 1 : 0,
          'created' => \Drupal::time()->getRequestTime(),
          'updated' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();
    }

    $this->messenger()->addStatus($this->t('Thank you for registering with the National Firefighter Registry. Your registration ID is @id.', ['@id' => $firefighter_id]));
    
    $form_state->setRedirect('nfr.dashboard');
  }

  /**
   * Get state options.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup|string>
   *   Array of state options.
   */
  protected function getStateOptions(): array {
    return [
      '' => $this->t('- Select -'),
      'AL' => $this->t('Alabama'),
      'AK' => $this->t('Alaska'),
      'AZ' => $this->t('Arizona'),
      'AR' => $this->t('Arkansas'),
      'CA' => $this->t('California'),
      'CO' => $this->t('Colorado'),
      'CT' => $this->t('Connecticut'),
      'DE' => $this->t('Delaware'),
      'FL' => $this->t('Florida'),
      'GA' => $this->t('Georgia'),
      'HI' => $this->t('Hawaii'),
      'ID' => $this->t('Idaho'),
      'IL' => $this->t('Illinois'),
      'IN' => $this->t('Indiana'),
      'IA' => $this->t('Iowa'),
      'KS' => $this->t('Kansas'),
      'KY' => $this->t('Kentucky'),
      'LA' => $this->t('Louisiana'),
      'ME' => $this->t('Maine'),
      'MD' => $this->t('Maryland'),
      'MA' => $this->t('Massachusetts'),
      'MI' => $this->t('Michigan'),
      'MN' => $this->t('Minnesota'),
      'MS' => $this->t('Mississippi'),
      'MO' => $this->t('Missouri'),
      'MT' => $this->t('Montana'),
      'NE' => $this->t('Nebraska'),
      'NV' => $this->t('Nevada'),
      'NH' => $this->t('New Hampshire'),
      'NJ' => $this->t('New Jersey'),
      'NM' => $this->t('New Mexico'),
      'NY' => $this->t('New York'),
      'NC' => $this->t('North Carolina'),
      'ND' => $this->t('North Dakota'),
      'OH' => $this->t('Ohio'),
      'OK' => $this->t('Oklahoma'),
      'OR' => $this->t('Oregon'),
      'PA' => $this->t('Pennsylvania'),
      'RI' => $this->t('Rhode Island'),
      'SC' => $this->t('South Carolina'),
      'SD' => $this->t('South Dakota'),
      'TN' => $this->t('Tennessee'),
      'TX' => $this->t('Texas'),
      'UT' => $this->t('Utah'),
      'VT' => $this->t('Vermont'),
      'VA' => $this->t('Virginia'),
      'WA' => $this->t('Washington'),
      'WV' => $this->t('West Virginia'),
      'WI' => $this->t('Wisconsin'),
      'WY' => $this->t('Wyoming'),
    ];
  }

}
