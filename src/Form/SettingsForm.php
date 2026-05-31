<?php

namespace Drupal\job_hunter\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\EmailValidatorInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Job Application Automation module settings.
 *
 * Provides a comprehensive settings interface for:
 * - Resume tailoring configuration
 * - AI service settings (AWS Bedrock)
 * - Google Cloud Talent Solution API
 * - External job search APIs (Adzuna, USAJobs, SerpAPI)
 * - Developer/debugging options
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Google Cloud project ID - for reference only.
   * The actual project ID must be set during configuration.
   * This is a placeholder; configure via settings form.
   */
  const GOOGLE_CLOUD_PROJECT_ID_PLACEHOLDER = 'YOUR_GOOGLE_CLOUD_PROJECT_ID';

  /**
   * Google Cloud service account email - for reference only.
   * The actual service account must be set during configuration.
   * This is a placeholder; configure via settings form.
   */
  const GOOGLE_CLOUD_SERVICE_ACCOUNT_PLACEHOLDER = 'YOUR_SERVICE_ACCOUNT@YOUR_PROJECT.iam.gserviceaccount.com';

  /**
   * Google Jobs API base URL.
   */
  const GOOGLE_JOBS_API_URL = 'https://jobs.googleapis.com/v4';

  /**
   * SerpAPI base URL.
   */
  const SERPAPI_URL = 'https://serpapi.com/search';

  /**
   * CSS class for success messages.
   */
  const MESSAGE_SUCCESS = 'messages messages--status';

  /**
   * CSS class for error messages.
   */
  const MESSAGE_ERROR = 'messages messages--error';

  /**
   * CSS class for warning messages.
   */
  const MESSAGE_WARNING = 'messages messages--warning';

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The email validator.
   *
   * @var \Drupal\Component\Utility\EmailValidatorInterface
   */
  protected $emailValidator;

  /**
   * Constructs a SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Component\Utility\EmailValidatorInterface $email_validator
   *   The email validator.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, ClientInterface $http_client, EntityTypeManagerInterface $entity_type_manager, EmailValidatorInterface $email_validator) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->emailValidator = $email_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('http_client'),
      $container->get('entity_type.manager'),
      $container->get('email.validator')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['job_hunter.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_hunter_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('job_hunter.settings');

    // Add intro text to match theme standards
    $form['intro'] = [
      '#markup' => '<div class="settings-intro"><p>' . $this->t('Configure Job Hunter module settings including AI service credentials, external API integrations, and system options.') . '</p></div>',
      '#weight' => -100,
    ];

    $this->buildAiSettingsSection($form, $config);
    $this->buildGoogleCloudSection($form, $config);
    $this->buildExternalApisSection($form, $config);
    $this->buildDeveloperSettingsSection($form, $config);

    return parent::buildForm($form, $form_state);
  }

  /**
   * Build the AI Service Configuration section.
   *
   * Creates form elements for AWS Bedrock AI service settings, including
   * region, model ID, and token limits for various operations.
   *
   * @param array &$form
   *   The form array to add elements to.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The configuration object.
   */
  protected function buildAiSettingsSection(array &$form, $config): void {
    $form['ai_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('AI Service Configuration'),
      '#open' => TRUE,
    ];

    $form['ai_settings']['ai_service_region'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS Region'),
      '#description' => $this->t('The AWS region for Bedrock service (e.g., us-west-2).'),
      '#default_value' => $config->get('ai_service_region') ?? 'us-west-2',
      '#required' => TRUE,
    ];

    $form['ai_settings']['ai_model_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AI Model ID'),
      '#description' => $this->t('The AWS Bedrock model ID to use for resume tailoring.'),
      '#default_value' => $config->get('ai_model_id') ?? 'anthropic.claude-3-5-sonnet-20240620-v1:0',
      '#required' => TRUE,
    ];

    $form['ai_settings']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Tokens (Default)'),
      '#description' => $this->t('Maximum number of tokens for AI generation. Used for most operations.'),
      '#default_value' => $config->get('max_tokens') ?? 20000,
      '#required' => TRUE,
      '#min' => 1000,
      '#max' => 50000,
    ];

    $form['ai_settings']['max_tokens_resume_tailoring'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Tokens (Resume Tailoring)'),
      '#description' => $this->t('Maximum tokens for resume tailoring operations. Tailored resumes generate large JSON responses and may need more tokens than other operations. Leave empty to use default.'),
      '#default_value' => $config->get('max_tokens_resume_tailoring') ?? 16000,
      '#required' => FALSE,
      '#min' => 8000,
      '#max' => 50000,
    ];
  }

  /**
   * Build the Google Cloud Talent Solution API section.
   *
   * Creates form elements for Google Cloud configuration, including service
   * account credentials, tenant management, and API testing features.
   *
   * @param array &$form
   *   The form array to add elements to.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The configuration object.
   */
  protected function buildGoogleCloudSection(array &$form, $config): void {
    $form['google_cloud_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Google Cloud Talent Solution API'),
      '#description' => $this->t('<p>Configure Google Cloud Talent Solution API for advanced job search capabilities.</p><p><strong>Project:</strong> @project<br><strong>Service Account:</strong> @account</p><p>See the <a href="@doc_url" target="_blank">documentation</a> for setup instructions.</p>', [
        '@project' => self::GOOGLE_CLOUD_PROJECT_ID,
        '@account' => self::GOOGLE_CLOUD_SERVICE_ACCOUNT,
        '@doc_url' => '/jobhunter/documentation/google-jobs-integration',
      ]),
      '#open' => FALSE,
    ];

    $form['google_cloud_settings']['google_cloud_credentials'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Service Account JSON Key'),
      '#description' => $this->t('Paste the contents of your Google Cloud service account JSON key file here. Get your key from the <a href="@url" target="_blank">Google Cloud Console</a>.<br><br><strong>Note:</strong> You can use the same JSON key for both development and production environments. The key identifies your project and permissions, not the environment.', [
        '@url' => 'https://console.cloud.google.com/talent-solution/connect-service-accounts',
      ]),
      '#default_value' => $config->get('google_cloud_credentials') ?? '',
      '#rows' => 12,
      '#required' => FALSE,
      '#attributes' => [
        'placeholder' => '{"type": "service_account", "project_id": "YOUR_PROJECT_ID", ...}',
        'class' => ['job-hunter-credentials-textarea'],
      ],
    ];

    $form['google_cloud_settings']['tenant_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tenant Name'),
      '#description' => $this->t('The full tenant resource name (e.g., projects/YOUR_PROJECT_ID/tenants/76d39aae-4a00-0000-0000-00527559cb6e). Use the "List Tenants" button below to find your tenant name.'),
      '#default_value' => $config->get('tenant_name') ?? '',
      '#required' => FALSE,
      '#attributes' => [
        'placeholder' => 'projects/YOUR_PROJECT_ID/tenants/76d39aae-4a00-0000-0000-00527559cb6e',
      ],
    ];

    $form['google_cloud_settings']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['job-hunter-button-group']],
    ];

    $form['google_cloud_settings']['actions']['test_credentials'] = [
      '#type' => 'button',
      '#value' => $this->t('Test API Connection'),
      '#ajax' => [
        'callback' => '::testGoogleCloudCredentials',
        'wrapper' => 'google-cloud-test-result',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Testing...')],
      ],
    ];

    $form['google_cloud_settings']['actions']['create_tenant'] = [
      '#type' => 'button',
      '#value' => $this->t('Create Tenant'),
      '#ajax' => [
        'callback' => '::createGoogleCloudTenant',
        'wrapper' => 'google-cloud-test-result',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Creating...')],
      ],
      '#attributes' => ['class' => ['button--primary']],
    ];

    $form['google_cloud_settings']['actions']['list_tenants'] = [
      '#type' => 'button',
      '#value' => $this->t('List Tenants'),
      '#ajax' => [
        'callback' => '::listGoogleCloudTenants',
        'wrapper' => 'google-cloud-test-result',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Loading...')],
      ],
    ];

    $form['google_cloud_settings']['test_result'] = [
      '#type' => 'markup',
      '#markup' => '<div id="google-cloud-test-result"><em>Click a button above to test...</em></div>',
    ];
  }

  /**
   * Build the External Job Search APIs section and subsections.
   *
   * Creates form elements for third-party job board API configurations,
   * including Adzuna, USAJobs, and SerpAPI integrations with test buttons.
   *
   * @param array &$form
   *   The form array to add elements to.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The configuration object.
   */
  protected function buildExternalApisSection(array &$form, $config): void {
    $form['external_job_apis'] = [
      '#type' => 'details',
      '#title' => $this->t('External Job Search APIs'),
      '#description' => $this->t('<p>Configure third-party job board APIs to search public job postings from across the internet.</p>'),
      '#open' => FALSE,
    ];

    // Adzuna API configuration
    $form['external_job_apis']['adzuna'] = [
      '#type' => 'details',
      '#title' => $this->t('Adzuna API'),
      '#description' => $this->t('<p><strong>Adzuna</strong> aggregates job postings from Indeed, Monster, and other major job boards. Free tier: 250 API calls per month.</p><p>Get your free API keys: <a href="@url" target="_blank">@url</a></p>', [
        '@url' => 'https://developer.adzuna.com/signup',
      ]),
      '#open' => TRUE,
    ];

    $form['external_job_apis']['adzuna']['adzuna_app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Application ID'),
      '#description' => $this->t('Your Adzuna application ID.'),
      '#default_value' => $config->get('adzuna_app_id') ?? '',
      '#required' => FALSE,
      '#attributes' => [
        'placeholder' => 'e.g., abc123xyz',
      ],
    ];

    $form['external_job_apis']['adzuna']['adzuna_app_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Application Key'),
      '#description' => $this->t('Your Adzuna application key (API key).'),
      '#default_value' => $config->get('adzuna_app_key') ?? '',
      '#required' => FALSE,
      '#attributes' => [
        'placeholder' => 'e.g., 0123456789abcdef0123456789abcdef',
      ],
    ];

    $form['external_job_apis']['adzuna']['test_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Integration'),
      '#ajax' => [
        'callback' => '::testAdzunaIntegration',
        'wrapper' => 'adzuna-test-result',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Testing...')],
      ],
      '#attributes' => ['class' => ['job-hunter-test-btn']],
    ];

    $form['external_job_apis']['adzuna']['test_result'] = [
      '#type' => 'markup',
      '#markup' => '<div id="adzuna-test-result" class="job-hunter-test-result"></div>',
    ];

    // USAJobs API configuration
    $form['external_job_apis']['usajobs'] = [
      '#type' => 'details',
      '#title' => $this->t('USAJobs API'),
      '#description' => $this->t('<p><strong>USAJobs</strong> is the official job board for U.S. federal government positions. Free API with unlimited requests.</p><p>Get your free API key: <a href="@url" target="_blank">@url</a></p>', [
        '@url' => 'https://developer.usajobs.gov/APIRequest/Index',
      ]),
      '#open' => TRUE,
    ];

    $form['external_job_apis']['usajobs']['usajobs_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Your USAJobs API authentication key.'),
      '#default_value' => $config->get('usajobs_api_key') ?? '',
      '#required' => FALSE,
      '#attributes' => [
        'placeholder' => 'e.g., ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890abcd',
      ],
    ];

    $form['external_job_apis']['usajobs']['usajobs_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#description' => $this->t('Your email address. Required by USAJobs API for the User-Agent header.'),
      '#default_value' => $config->get('usajobs_email') ?? '',
      '#required' => FALSE,
      '#attributes' => [
        'placeholder' => 'your-email@example.com',
      ],
    ];

    $form['external_job_apis']['usajobs']['test_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Integration'),
      '#ajax' => [
        'callback' => '::testUsaJobsIntegration',
        'wrapper' => 'usajobs-test-result',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Testing...')],
      ],
      '#attributes' => ['class' => ['job-hunter-test-btn']],
    ];

    $form['external_job_apis']['usajobs']['test_result'] = [
      '#type' => 'markup',
      '#markup' => '<div id="usajobs-test-result" class="job-hunter-test-result"></div>',
    ];

    // SerpAPI configuration
    $form['external_job_apis']['serpapi'] = [
      '#type' => 'details',
      '#title' => $this->t('SerpAPI (Google Jobs Scraper)'),
      '#description' => $this->t('<p><strong>SerpAPI</strong> provides access to Google Jobs search results (scrapes Google\'s job search). Free tier: 100 searches per month.</p><p>Get your free API key: <a href="@url" target="_blank">@url</a></p>', [
        '@url' => 'https://serpapi.com/users/sign_up',
      ]),
      '#open' => TRUE,
    ];

    $form['external_job_apis']['serpapi']['serpapi_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Your SerpAPI authentication key.'),
      '#default_value' => $config->get('serpapi_api_key') ?? '',
      '#required' => FALSE,
      '#attributes' => [
        'placeholder' => 'e.g., 01234567890abcdef01234567890abcdef01234567890abcdef01234567890abc',
      ],
    ];

    $form['external_job_apis']['serpapi']['test_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Integration'),
      '#ajax' => [
        'callback' => '::testSerpApiIntegration',
        'wrapper' => 'serpapi-test-result',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Testing...')],
      ],
      '#attributes' => ['class' => ['job-hunter-test-btn']],
    ];

    $form['external_job_apis']['serpapi']['test_result'] = [
      '#type' => 'markup',
      '#markup' => '<div id="serpapi-test-result" class="job-hunter-test-result"></div>',
    ];
  }

  /**
   * Build the Developer Settings section.
   *
   * Creates form elements for debugging and logging configuration options
   * to control the verbosity of module logging.
   *
   * @param array &$form
   *   The form array to add elements to.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The configuration object.
   */
  protected function buildDeveloperSettingsSection(array &$form, $config): void {
    $form['developer_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('🔧 Developer Settings'),
      '#description' => $this->t('Configure debugging and logging options.'),
      '#open' => FALSE,
    ];

    $form['developer_settings']['log_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Logging Level'),
      '#description' => $this->t('Control the verbosity of job_hunter module logging. Only messages at or above the selected level will be logged.<br><strong>debug</strong> = All messages (most verbose)<br><strong>info</strong> = Informational messages and above<br><strong>notice</strong> = Notable events and above<br><strong>warning</strong> = Warnings and errors only<br><strong>error</strong> = Only error messages (least verbose)'),
      '#options' => [
        'debug' => $this->t('Debug (most verbose - development only)'),
        'info' => $this->t('Info (recommended for development)'),
        'notice' => $this->t('Notice (default - production)'),
        'warning' => $this->t('Warning (only warnings and errors)'),
        'error' => $this->t('Error (only errors - least verbose)'),
      ],
      '#default_value' => $config->get('log_level') ?? 'notice',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate Google Cloud credentials JSON format
    $credentials_json = $form_state->getValue('google_cloud_credentials');
    if (!empty($credentials_json)) {
      $credentials = json_decode($credentials_json, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $form_state->setErrorByName('google_cloud_credentials',
          $this->t('Invalid JSON format: @error', ['@error' => json_last_error_msg()]));
      }
      elseif (!isset($credentials['project_id']) || !isset($credentials['type'])) {
        $form_state->setErrorByName('google_cloud_credentials',
          $this->t('Invalid service account JSON. Must contain "project_id" and "type" fields.'));
      }
    }

    // Validate email format for USAJobs (if provided)
    $usajobs_email = $form_state->getValue('usajobs_email');
    if (!empty($usajobs_email) && !\Drupal::service('email.validator')->isValid($usajobs_email)) {
      $form_state->setErrorByName('usajobs_email',
        $this->t('Please enter a valid email address.'));
    }

    // Validate API keys are not empty spaces
    $api_keys = ['adzuna_app_id', 'adzuna_app_key', 'usajobs_api_key', 'serpapi_api_key'];
    foreach ($api_keys as $key) {
      $value = $form_state->getValue($key);
      if (!empty($value) && trim($value) === '') {
        $form_state->setErrorByName($key, $this->t('API key cannot be only whitespace.'));
      }
    }
  }

  /**
   * AJAX callback to test Google Cloud credentials.
   *
   * Validates the Google Cloud service account credentials by attempting
   * to authenticate and fetch tenant information.
   *
   * @param array &$form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   Renderable array with test results.
   */
  public function testGoogleCloudCredentials(array &$form, FormStateInterface $form_state) {
    $credentials_json = $form_state->getValue('google_cloud_credentials');
    $tenant_name = $form_state->getValue('tenant_name');
    
    if (empty($credentials_json)) {
      return $this->buildAjaxMessage('google-cloud-test-result', 'Please enter your service account credentials first.', 'error');
    }

    if (empty($tenant_name)) {
      return $this->buildAjaxMessage('google-cloud-test-result', 'Please enter the tenant name first. Use the "List Tenants" or "Create Tenant" button to get a tenant.', 'error');
    }

    // Temporarily save and test the credentials
    $temp_config = \Drupal::configFactory()->getEditable('job_hunter.settings');
    $old_creds = $temp_config->get('google_cloud_credentials');
    $old_tenant = $temp_config->get('tenant_name');
    $temp_config
      ->set('google_cloud_credentials', $credentials_json)
      ->set('tenant_name', $tenant_name)
      ->save();

    try {
      $service = \Drupal::service('job_hunter.cloud_talent_solution');
      $valid = $service->checkApiCredentials();
      
      if ($valid) {
        $form['google_cloud_settings']['test_result']['#markup'] = '<div id="google-cloud-test-result" class="messages messages--status">✓ Successfully connected to Cloud Talent Solution API!</div>';
      }
      else {
        $form['google_cloud_settings']['test_result']['#markup'] = '<div id="google-cloud-test-result" class="messages messages--error">✗ Connection test failed. Please check your credentials and project configuration.</div>';
      }
    }
    catch (\Exception $e) {
      $form['google_cloud_settings']['test_result']['#markup'] = '<div id="google-cloud-test-result" class="messages messages--error">✗ Error: ' . $e->getMessage() . '</div>';
    }

    // Restore old values
    $temp_config
      ->set('google_cloud_credentials', $old_creds)
      ->set('tenant_name', $old_tenant)
      ->save();

    return $form['google_cloud_settings']['test_result'];
  }

  /**
   * AJAX callback to create Google Cloud Talent Solution tenant.
   *
   * Creates a new tenant in the Google Cloud Talent Solution API for
   * organizing job postings and company data.
   *
   * @param array &$form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   Renderable array with creation results.
   */
  public function createGoogleCloudTenant(array &$form, FormStateInterface $form_state) {
    $credentials_json = $form_state->getValue('google_cloud_credentials');
    
    if (empty($credentials_json)) {
      return $this->buildAjaxMessage('google-cloud-test-result', '✗ ERROR: Enter credentials first.', 'error', 'styled');
    }

    try {
      list($credentials, $token, $project_id) = $this->authenticateGoogleCloud($credentials_json);
      
      // Check if tenant already exists
      try {
        $list_response = $this->httpClient->get(self::GOOGLE_JOBS_API_URL . "/projects/{$project_id}/tenants", [
          'headers' => ['Authorization' => 'Bearer ' . $token['access_token']],
        ]);
        $existing_tenants = json_decode($list_response->getBody()->getContents(), true);
        
        if (!empty($existing_tenants['tenants'])) {
          $message = '⚠ ALREADY EXISTS<br>Found ' . count($existing_tenants['tenants']) . ' tenant(s). Use "List Tenants" to view.';
          return $this->buildAjaxMessage('google-cloud-test-result', $message, 'warning', 'styled');
        }
      } catch (\Exception $e) {
        // Continue with creation if listing fails
      }
      
      // Create the tenant
      $response = $this->httpClient->post(self::GOOGLE_JOBS_API_URL . "/projects/{$project_id}/tenants", [
        'headers' => ['Authorization' => 'Bearer ' . $token['access_token']],
        'json' => [
          'externalId' => 'forseti-jobhunter',
          'usageType' => 'GENERAL_PURPOSE',
        ]
      ]);

      $tenant_data = json_decode($response->getBody()->getContents(), true);
      $tenant_name = $tenant_data['name'] ?? 'unknown';
      
      $message = '✓ CREATED!<br><code>' . htmlspecialchars($tenant_name) . '</code>';
      return $this->buildAjaxMessage('google-cloud-test-result', $message, 'success', 'styled');
    }
    catch (\Exception $e) {
      $message = '✗ ERROR:<br>' . htmlspecialchars($e->getMessage());
      return $this->buildAjaxMessage('google-cloud-test-result', $message, 'error', 'styled');
    }
  }

  /**
   * AJAX callback to list Google Cloud Talent Solution tenants.
   *
   * Retrieves and displays all tenants associated with the Google Cloud
   * project, showing their resource names and external IDs.
   *
   * @param array &$form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   Renderable array with tenant list.
   */
  public function listGoogleCloudTenants(array &$form, FormStateInterface $form_state) {
    $credentials_json = $form_state->getValue('google_cloud_credentials');
    
    if (empty($credentials_json)) {
      return $this->buildAjaxMessage('google-cloud-test-result', '✗ ERROR: Enter credentials first.', 'error', 'styled');
    }

    try {
      list($credentials, $token, $project_id) = $this->authenticateGoogleCloud($credentials_json);
      $response = $this->httpClient->get(self::GOOGLE_JOBS_API_URL . "/projects/{$project_id}/tenants", [
        'headers' => ['Authorization' => 'Bearer ' . $token['access_token']],
      ]);

      $tenants_data = json_decode($response->getBody()->getContents(), true);
      $tenants = $tenants_data['tenants'] ?? [];
      
      if (empty($tenants)) {
        $message = '⚠ NO TENANTS<br>Click "Create Tenant" to create one.';
        return $this->buildAjaxMessage('google-cloud-test-result', $message, 'warning', 'styled');
      }
      
      $tenant_list = '<ul style="margin-top: 10px; list-style: none; padding: 0;">';
      foreach ($tenants as $tenant) {
        $tenant_list .= '<li style="margin: 8px 0; padding: 8px; background: white; border-radius: 4px;"><code>' . htmlspecialchars($tenant['name'] ?? 'N/A') . '</code></li>';
      }
      $tenant_list .= '</ul>';
      
      $message = '✓ FOUND ' . count($tenants) . ' TENANT(S)' . $tenant_list;
      return $this->buildAjaxMessage('google-cloud-test-result', $message, 'success', 'styled');
    }
    catch (\Exception $e) {
      $message = '✗ ERROR:<br>' . htmlspecialchars($e->getMessage());
      return $this->buildAjaxMessage('google-cloud-test-result', $message, 'error', 'styled');
    }
  }

  /**
   * AJAX callback to test Adzuna API integration.
   *
   * Validates Adzuna API credentials by performing a test search for
   * Software Engineer jobs in the US.
   *
   * @param array &$form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   Renderable array with test results.
   */
  public function testAdzunaIntegration(array &$form, FormStateInterface $form_state) {
    $app_id = $form_state->getValue('adzuna_app_id');
    $app_key = $form_state->getValue('adzuna_app_key');
    
    if (empty($app_id) || empty($app_key)) {
      return $this->buildAjaxMessage('adzuna-test-result', '⚠ Please enter both Application ID and Application Key first.', 'error');
    }

    return $this->testApiIntegration(
      'job_hunter.adzuna',
      ['adzuna_app_id' => $app_id, 'adzuna_app_key' => $app_key],
      ['query' => 'software engineer', 'location' => 'remote', 'results_per_page' => 1],
      'adzuna-test-result',
      'Adzuna API'
    );
  }

  /**
   * AJAX callback to test USAJobs API integration.
   *
   * Validates USAJobs API credentials by performing a test search for
   * Software Developer positions.
   *
   * @param array &$form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   Renderable array with test results.
   */
  public function testUsaJobsIntegration(array &$form, FormStateInterface $form_state) {
    $api_key = $form_state->getValue('usajobs_api_key');
    $email = $form_state->getValue('usajobs_email');
    
    if (empty($api_key)) {
      return $this->buildAjaxMessage('usajobs-test-result', '⚠ Please enter your API Key first.', 'error');
    }

    return $this->testApiIntegration(
      'job_hunter.usajobs',
      ['usajobs_api_key' => $api_key, 'usajobs_email' => $email],
      ['query' => 'engineer', 'results_per_page' => 1],
      'usajobs-test-result',
      'USAJobs API',
      'federal jobs'
    );
  }

  /**
   * AJAX callback to test SerpAPI integration.
   *
   * Validates SerpAPI credentials and performs a comprehensive diagnostic
   * test of the Google Jobs scraper functionality, including rate limit
   * detection and search result validation.
   *
   * @param array &$form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   Renderable array with detailed test results and diagnostics.
   */
  public function testSerpApiIntegration(array &$form, FormStateInterface $form_state) {
    $api_key = $form_state->getValue('serpapi_api_key');
    
    if (empty($api_key)) {
      return $this->buildAjaxMessage('serpapi-test-result', '⚠ Please enter your API Key first.', 'error');
    }

    // Test API key directly with SerpAPI
    try {
      $response = $this->httpClient->get(self::SERPAPI_URL, [
        'query' => [
          'engine' => 'google_jobs',
          'api_key' => $api_key,
          'q' => 'software developer',
          'location' => 'United States',
          'num' => 5,
        ],
        'timeout' => 15,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      
      // Check for API errors
      if (isset($data['error'])) {
        return $this->buildAjaxMessage('serpapi-test-result', '✗ API Error: ' . htmlspecialchars($data['error']), 'error');
      }
      
      // Check search metadata
      $search_metadata = $data['search_metadata'] ?? [];
      $status = $search_metadata['status'] ?? 'unknown';
      $jobs = $data['jobs_results'] ?? [];
      $count = count($jobs);
      
      // Build success message with details
      $message = '<strong>✓ Successfully connected to SerpAPI!</strong><br>';
      $message .= 'Status: ' . htmlspecialchars($status) . '<br>';
      $message .= 'Jobs returned: ' . $count . '<br>';
      
      if (isset($search_metadata['total_results'])) {
        $message .= 'Total available: ' . number_format($search_metadata['total_results']) . '<br>';
      }
      
      if ($count === 0) {
        $message .= '<br><em style="color: #f57c00;">⚠ No results returned. This could mean:</em><br>';
        $message .= '<ul style="margin: 5px 0; padding-left: 20px;">';
        $message .= '<li>API key has no remaining credits (check <a href="https://serpapi.com/dashboard" target="_blank">dashboard</a>)</li>';
        $message .= '<li>Query didn\'t match any jobs</li>';
        $message .= '<li>Rate limit reached</li>';
        $message .= '</ul>';
      } else {
        $message .= '<br><strong>Sample job:</strong> ' . htmlspecialchars($jobs[0]['title'] ?? 'N/A');
      }
      
      return $this->buildAjaxMessage('serpapi-test-result', $message, 'success');
    }
    catch (\Exception $e) {
      return $this->buildAjaxMessage('serpapi-test-result', '✗ Connection Error: ' . htmlspecialchars($e->getMessage()), 'error');
    }
  }

  /**
   * Helper method to authenticate with Google Cloud.
   *
   * @param string $credentials_json
   *   JSON credentials string.
   *
   * @return array
   *   Array containing [credentials, token, project_id].
   *
   * @throws \Exception
   */
  protected function authenticateGoogleCloud(string $credentials_json): array {
    $credentials = json_decode($credentials_json, TRUE);
    if (!$credentials || !isset($credentials['project_id'])) {
      throw new \Exception('Invalid JSON credentials format');
    }

    $client = new \Google\Auth\Credentials\ServiceAccountCredentials(
      'https://www.googleapis.com/auth/cloud-platform',
      $credentials
    );
    $token = $client->fetchAuthToken();
    $project_id = $credentials['project_id'];

    return [$credentials, $token, $project_id];
  }

  /**
   * Helper method to test external API integrations.
   *
   * @param string $service_id
   *   The service ID to test.
   * @param array $credentials
   *   Array of credential key-value pairs to temporarily save.
   * @param array $test_params
   *   Parameters to pass to searchJobs().
   * @param string $wrapper_id
   *   AJAX wrapper element ID.
   * @param string $api_name
   *   Human-readable API name.
   * @param string $job_type
   *   Optional job type descriptor (e.g., 'federal jobs').
   *
   * @return array
   *   Form element with test result.
   */
  protected function testApiIntegration(string $service_id, array $credentials, array $test_params, string $wrapper_id, string $api_name, string $job_type = 'jobs'): array {
    $temp_config = \Drupal::configFactory()->getEditable('job_hunter.settings');
    $old_values = [];

    // Save old values and set new credentials
    foreach ($credentials as $key => $value) {
      $old_values[$key] = $temp_config->get($key);
      $temp_config->set($key, $value);
    }
    $temp_config->save();

    try {
      $service = \Drupal::service($service_id);
      $results = $service->searchJobs($test_params);
      
      $count = count($results['jobs'] ?? []);
      $total = $results['total'] ?? 0;
      
      $message = "✓ Successfully connected to {$api_name}!<br>Test search returned {$count} result(s) from " . number_format($total) . " total {$job_type} available.";
      $result = $this->buildAjaxMessage($wrapper_id, $message, 'success');
    }
    catch (\Exception $e) {
      $message = '✗ Error: ' . htmlspecialchars($e->getMessage());
      $result = $this->buildAjaxMessage($wrapper_id, $message, 'error');
    }

    // Restore old values
    foreach ($old_values as $key => $value) {
      $temp_config->set($key, $value);
    }
    $temp_config->save();

    return $result;
  }

  /**
   * Helper method to build AJAX message markup.
   *
   * @param string $wrapper_id
   *   The HTML element ID.
   * @param string $message
   *   The message text.
   * @param string $type
   *   Message type: 'success', 'error', 'warning'.
   * @param string $style
   *   Optional style variant: 'styled' for Google Cloud style.
   *
   * @return array
   *   Form element with markup.
   */
  protected function buildAjaxMessage(string $wrapper_id, string $message, string $type = 'success', string $style = 'default'): array {
    $class_map = [
      'success' => self::MESSAGE_SUCCESS,
      'error' => self::MESSAGE_ERROR,
      'warning' => self::MESSAGE_WARNING,
    ];

    $css_class = $class_map[$type] ?? self::MESSAGE_SUCCESS;
    
    if ($style === 'styled') {
      // Google Cloud styled variant
      $color_map = [
        'success' => ['border' => '#388e3c', 'bg' => '#e8f5e9', 'text' => '#388e3c'],
        'error' => ['border' => '#d32f2f', 'bg' => '#ffebee', 'text' => '#d32f2f'],
        'warning' => ['border' => '#f57c00', 'bg' => '#fff3e0', 'text' => '#f57c00'],
      ];
      $colors = $color_map[$type] ?? $color_map['success'];
      $markup = '<div id="' . $wrapper_id . '" style="margin-top: 15px; padding: 15px; border: 2px solid ' . $colors['border'] . '; border-radius: 4px; background: ' . $colors['bg'] . ';"><strong style="color: ' . $colors['text'] . ';">' . $message . '</strong></div>';
    }
    else {
      // Standard Drupal message style
      $markup = '<div id="' . $wrapper_id . '" class="' . $css_class . '" style="margin-top: 10px;">' . $message . '</div>';
    }

    return [
      '#type' => 'markup',
      '#markup' => $markup,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('job_hunter.settings')
      ->set('ai_service_region', $form_state->getValue('ai_service_region'))
      ->set('ai_model_id', $form_state->getValue('ai_model_id'))
      ->set('max_tokens', $form_state->getValue('max_tokens'))
      ->set('max_tokens_resume_tailoring', $form_state->getValue('max_tokens_resume_tailoring'))
      ->set('google_cloud_credentials', $form_state->getValue('google_cloud_credentials'))
      ->set('tenant_name', $form_state->getValue('tenant_name'))
      ->set('adzuna_app_id', $form_state->getValue('adzuna_app_id'))
      ->set('adzuna_app_key', $form_state->getValue('adzuna_app_key'))
      ->set('usajobs_api_key', $form_state->getValue('usajobs_api_key'))
      ->set('usajobs_email', $form_state->getValue('usajobs_email'))
      ->set('serpapi_api_key', $form_state->getValue('serpapi_api_key'))
      ->set('log_level', $form_state->getValue('log_level'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
