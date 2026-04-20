<?php

namespace Drupal\agent_evaluation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure AI Conversation settings.
 */
class AIConversationSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'agent_evaluation.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'agent_evaluation_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('agent_evaluation.settings');

    // Show current credential status
    $aws_access_key_config = $config->get('aws_access_key_id');
    $aws_access_key_env = getenv('AWS_ACCESS_KEY_ID');
    $aws_region_config = $config->get('aws_region');
    $aws_region_env = getenv('AWS_DEFAULT_REGION');

    $credential_status = [];
    if (!empty($aws_access_key_config)) {
      $credential_status[] = 'Using configured AWS credentials';
    } elseif (!empty($aws_access_key_env)) {
      $credential_status[] = 'Using AWS_ACCESS_KEY_ID environment variable';
    } else {
      $credential_status[] = 'No AWS credentials found';
    }

    if (!empty($aws_region_config)) {
      $credential_status[] = 'Region from configuration: ' . $aws_region_config;
    } elseif (!empty($aws_region_env)) {
      $credential_status[] = 'Region from AWS_DEFAULT_REGION: ' . $aws_region_env;
    } else {
      $credential_status[] = 'Using default region: us-west-2';
    }

    $form['credential_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Current Status'),
      '#markup' => '<div class="messages messages--status"><ul><li>' . implode('</li><li>', $credential_status) . '</li></ul></div>',
    ];

    $form['aws_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('AWS Bedrock Settings'),
      '#description' => $this->t('Configure your AWS credentials to connect to Bedrock AI services. Leave fields empty to use environment variables (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_DEFAULT_REGION).'),
    ];

    $form['aws_settings']['aws_access_key_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('AWS Access Key ID'),
      '#default_value' => $config->get('aws_access_key_id'),
      '#description' => $this->t('Your AWS Access Key ID with permissions to use Bedrock. Leave empty to use AWS_ACCESS_KEY_ID environment variable.'),
      '#required' => FALSE,
    ];

    $form['aws_settings']['aws_secret_access_key'] = [
      '#type' => 'password',
      '#title' => $this->t('AWS Secret Access Key'),
      '#default_value' => $config->get('aws_secret_access_key'),
      '#description' => $this->t('Your AWS Secret Access Key. Leave blank to keep current value or use AWS_SECRET_ACCESS_KEY environment variable.'),
      '#attributes' => ['autocomplete' => 'off'],
      '#required' => FALSE,
    ];

    $form['aws_settings']['aws_region'] = [
      '#type' => 'select',
      '#title' => $this->t('AWS Region'),
      '#default_value' => $config->get('aws_region') ?: 'us-west-2',
      '#options' => [
        'us-east-1' => 'US East (N. Virginia)',
        'us-west-2' => 'US West (Oregon)',
        'eu-west-1' => 'Europe (Ireland)',
        'ap-southeast-2' => 'Asia Pacific (Sydney)',
      ],
      '#description' => $this->t('The AWS region where Bedrock is available.'),
      '#required' => TRUE,
    ];

    $form['aws_settings']['aws_model'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Model'),
      '#default_value' => $config->get('aws_model') ?: 'anthropic.claude-3-5-sonnet-20240620-v1:0',
      '#options' => [
        'anthropic.claude-3-5-sonnet-20240620-v1:0' => 'Claude 3.5 Sonnet',
        'anthropic.claude-3-haiku-20240307-v1:0' => 'Claude 3 Haiku',
        'anthropic.claude-v2:1' => 'Claude 2.1',
        'anthropic.claude-v2' => 'Claude 2.0',
      ],
      '#description' => $this->t('The AI model to use for conversations.'),
      '#required' => TRUE,
    ];

    $form['aws_settings']['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System Prompt'),
      '#default_value' => $config->get('system_prompt'),
      '#description' => $this->t('The system prompt that defines the AI assistant\'s role, personality, and knowledge context.'),
      '#rows' => 15,
      '#required' => FALSE,
    ];

    $form['conversation_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Conversation Settings'),
    ];

    $form['conversation_settings']['max_tokens'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Tokens'),
      '#default_value' => $config->get('max_tokens') ?: 4000,
      '#description' => $this->t('Maximum number of tokens for AI responses.'),
      '#min' => 100,
      '#max' => 8000,
      '#required' => TRUE,
    ];

    $form['conversation_settings']['max_recent_messages'] = [
      '#type' => 'number',
      '#title' => $this->t('Recent Messages'),
      '#default_value' => $config->get('max_recent_messages') ?: 20,
      '#description' => $this->t('Number of recent messages to keep in memory.'),
      '#min' => 5,
      '#max' => 50,
      '#required' => TRUE,
    ];

    $form['conversation_settings']['summary_frequency'] = [
      '#type' => 'number',
      '#title' => $this->t('Summary Frequency'),
      '#default_value' => $config->get('summary_frequency') ?: 10,
      '#description' => $this->t('Create a summary every N messages.'),
      '#min' => 5,
      '#max' => 50,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('agent_evaluation.settings');
    
    $config->set('aws_access_key_id', $form_state->getValue('aws_access_key_id'));
    
    // Only update secret if a new value was provided
    $secret = $form_state->getValue('aws_secret_access_key');
    if (!empty($secret)) {
      $config->set('aws_secret_access_key', $secret);
    }
    
    $config->set('aws_region', $form_state->getValue('aws_region'))
      ->set('aws_model', $form_state->getValue('aws_model'))
      ->set('system_prompt', $form_state->getValue('system_prompt'))
      ->set('max_tokens', $form_state->getValue('max_tokens'))
      ->set('max_recent_messages', $form_state->getValue('max_recent_messages'))
      ->set('summary_frequency', $form_state->getValue('summary_frequency'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}