<?php

namespace Drupal\job_hunter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding ATS credentials.
 *
 * Handles POST to /jobhunter/settings/credentials (add form on the page).
 */
class CredentialForm extends FormBase {

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * ATS platforms that require login.
   */
  const LOGIN_PLATFORMS = [
    'workday'        => 'Workday',
    'icims'          => 'iCIMS',
    'taleo'          => 'Oracle Taleo',
    'successfactors' => 'SAP SuccessFactors',
    'ultipro'        => 'UKG Pro (UltiPro)',
    'paylocity'      => 'Paylocity',
    'usajobs'        => 'USAJobs.gov',
    'bamboohr'       => 'BambooHR',
  ];

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('database'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_hunter_credential_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Load companies the user has saved jobs for via the saved_jobs mapping table.
    // jobhunter_job_requirements is a global catalog (no uid column); uid lives in jobhunter_saved_jobs.
    $uid = \Drupal::currentUser()->id();
    $query = $this->database->select('jobhunter_saved_jobs', 's');
    $query->join('jobhunter_job_requirements', 'j', 's.job_id = j.id');
    $company_ids = $query
      ->fields('j', ['company_id'])
      ->condition('s.uid', $uid)
      ->isNotNull('j.company_id')
      ->distinct()
      ->execute()
      ->fetchCol();

    $companies = [];
    if (!empty($company_ids)) {
      $companies = $this->database->select('jobhunter_companies', 'c')
        ->fields('c', ['id', 'name'])
        ->condition('id', $company_ids, 'IN')
        ->orderBy('name')
        ->execute()
        ->fetchAllKeyed();
    }

    $form['#attributes']['class'][] = 'credential-add-form';

    $form['company_id'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Employer'),
      '#options'       => ['' => $this->t('-- Select Employer --')] + $companies,
      '#required'      => TRUE,
      '#description'   => $this->t('Select the employer whose portal you want to automate.'),
    ];

    $form['credential_type'] = [
      '#type'     => 'select',
      '#title'    => $this->t('ATS Platform'),
      '#options'  => ['' => $this->t('-- Select Platform --')] + self::LOGIN_PLATFORMS,
      '#required' => TRUE,
    ];

    $form['submission_url'] = [
      '#type'        => 'url',
      '#title'       => $this->t('Employer Portal URL'),
      '#description' => $this->t('The URL of the employer\'s application portal or job listing (e.g. https://company.wd5.myworkdayjobs.com).'),
      '#required'    => FALSE,
      '#placeholder' => 'https://',
    ];

    $form['credentials_fieldset'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Credentials'),
    ];

    $form['credentials_fieldset']['username'] = [
      '#type'        => 'email',
      '#title'       => $this->t('Username / Email'),
      '#required'    => TRUE,
      '#description' => $this->t('Your login email or username for this employer\'s portal.'),
      '#autocomplete_route_name' => FALSE,
    ];

    $form['credentials_fieldset']['password'] = [
      '#type'        => 'password',
      '#title'       => $this->t('Password'),
      '#required'    => TRUE,
      '#description' => $this->t('Your password. Stored encrypted using AES-256-CBC.'),
    ];

    $form['security_notice'] = [
      '#markup' => '<div class="messages messages--warning credential-security-notice">'
        . '<strong>🔐 Security:</strong> Credentials are encrypted at rest (AES-256-CBC) and only decrypted when automation runs. '
        . 'They are never logged or displayed in plaintext. '
        . '<a href="/jobhunter/docs/CREDENTIAL_STORAGE_SECURITY.md">Read security policy →</a>'
        . '</div>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type'        => 'submit',
      '#value'       => $this->t('Store Credentials'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $company_id = $form_state->getValue('company_id');
    $type       = $form_state->getValue('credential_type');

    if (empty($company_id)) {
      $form_state->setErrorByName('company_id', $this->t('Please select an employer.'));
    }
    if (empty($type) || !isset(self::LOGIN_PLATFORMS[$type])) {
      $form_state->setErrorByName('credential_type', $this->t('Please select a valid ATS platform.'));
    }
    if (strlen($form_state->getValue('password')) < 4) {
      $form_state->setErrorByName('password', $this->t('Password is too short.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid        = \Drupal::currentUser()->id();
    $company_id = (int) $form_state->getValue('company_id');
    $type       = $form_state->getValue('credential_type');
    $url        = $form_state->getValue('submission_url') ?: '';

    $credential_data = [
      'username' => trim($form_state->getValue('username')),
      'password' => $form_state->getValue('password'),
    ];

    /** @var \Drupal\job_hunter\Service\CredentialManagementService $cred_service */
    $cred_service = \Drupal::service('job_hunter.credential_management_service');
    $result = $cred_service->storeCredential($uid, $company_id, $type, $credential_data, $url);

    if ($result['success']) {
      $this->messenger()->addStatus($this->t('Credentials stored for @platform. You can now use automated submission for this employer.', [
        '@platform' => self::LOGIN_PLATFORMS[$type] ?? $type,
      ]));
    }
    else {
      $this->messenger()->addError($this->t('Failed to store credentials: @error', [
        '@error' => $result['error'] ?? $result['message'],
      ]));
    }

    $form_state->setRedirectUrl(Url::fromRoute('job_hunter.credentials'));
  }

}
