<?php

declare(strict_types=1);

namespace Drupal\nfr\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Informed Consent form for NFR enrollment.
 */
class NFRConsentForm extends FormBase {

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
   * Constructs a new NFRConsentForm.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
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
    return 'nfr_consent_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Check if uid parameter is provided (for admin viewing other users)
    $request = \Drupal::request();
    $requested_uid = $request->query->get('uid');
    
    // If no uid parameter or user is not admin, use current user
    if ($requested_uid && $this->currentUser->hasPermission('administer nfr')) {
      $uid = (int) $requested_uid;
    } else {
      $uid = $this->currentUser->id();
    }

    // Check if consent already exists.
    $existing_consent = $this->database->select('nfr_consent', 'nc')
      ->fields('nc')
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    // Store uid in form state for submit handler
    $form_state->set('consent_uid', $uid);

    $form['#attached']['library'][] = 'nfr/enrollment';

    $form['intro'] = [
      '#markup' => '<div class="consent-intro"><p>Before you can participate in the National Firefighter Registry, we need your informed consent. Please read the following information carefully.</p></div>',
    ];

    // Informed Consent Document
    $form['consent_document'] = [
      '#type' => 'details',
      '#title' => $this->t('Informed Consent Document'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['consent-document']],
    ];

    $form['consent_document']['content'] = [
      '#markup' => $this->getConsentText(),
    ];

    // Assurance of Confidentiality
    $form['confidentiality'] = [
      '#type' => 'details',
      '#title' => $this->t('Assurance of Confidentiality'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['confidentiality-assurance']],
    ];

    $form['confidentiality']['content'] = [
      '#markup' => $this->getConfidentialityText(),
    ];

    // Consent Checkboxes
    $form['consent_participate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I have read and understood the informed consent document, and I voluntarily agree to participate in the National Firefighter Registry.'),
      '#required' => TRUE,
      '#default_value' => $existing_consent['consented_to_participate'] ?? 0,
    ];

    $form['consent_linkage'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I consent to having my information linked with state cancer registries for research purposes.'),
      '#description' => $this->t('This is optional but helps improve the quality of cancer surveillance data.'),
      '#default_value' => $existing_consent['consented_to_registry_linkage'] ?? 0,
    ];

    // Electronic Signature
    $form['signature_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Electronic Signature'),
      '#attributes' => ['class' => ['signature-section']],
    ];

    $form['signature_section']['signature'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full Name (Electronic Signature)'),
      '#description' => $this->t('By typing your full name, you are providing your electronic signature.'),
      '#required' => TRUE,
      '#default_value' => $existing_consent['electronic_signature'] ?? '',
    ];

    $form['signature_section']['signature_date'] = [
      '#markup' => '<div class="signature-date"><strong>Date:</strong> ' . date('F j, Y') . '</div>',
    ];

    $form['signature_section']['signature_ip'] = [
      '#markup' => '<div class="signature-ip"><small>IP Address: ' . $this->getRequest()->getClientIp() . '</small></div>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit Consent'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('nfr.home'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$form_state->getValue('consent_participate')) {
      $form_state->setErrorByName('consent_participate', $this->t('You must consent to participate in order to continue.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Use the stored uid from buildForm (may be different from current user if admin is viewing)
    $uid = $form_state->get('consent_uid') ?? $this->currentUser->id();
    $values = $form_state->getValues();

    $fields = [
      'uid' => $uid,
      'consented_to_participate' => $values['consent_participate'] ? 1 : 0,
      'consented_to_registry_linkage' => $values['consent_linkage'] ? 1 : 0,
      'electronic_signature' => $values['signature'],
      'consent_ip_address' => $this->getRequest()->getClientIp(),
      'consent_timestamp' => time(),
      'created' => time(),
    ];

    // Check if consent already exists.
    $existing = $this->database->select('nfr_consent', 'nc')
      ->fields('nc', ['id'])
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();

    if ($existing) {
      $this->database->update('nfr_consent')
        ->fields($fields)
        ->condition('uid', $uid)
        ->execute();
      
      $this->messenger()->addStatus($this->t('Your consent has been updated.'));
    }
    else {
      $this->database->insert('nfr_consent')
        ->fields($fields)
        ->execute();
      
      $this->messenger()->addStatus($this->t('Thank you for consenting to participate in the National Firefighter Registry.'));
    }

    // Redirect to profile form.
    $form_state->setRedirect('nfr.user_profile');
  }

  /**
   * Get informed consent text.
   *
   * @return string
   *   HTML content for consent document.
   */
  protected function getConsentText(): string {
    return '
      <div class="consent-content">
        <h3>Purpose of the Study</h3>
        <p>The National Firefighter Registry (NFR) is a voluntary research program conducted by the National Institute for Occupational Safety and Health (NIOSH), part of the Centers for Disease Control and Prevention (CDC). The purpose is to better understand and prevent cancer among firefighters.</p>

        <h3>What You Will Be Asked to Do</h3>
        <ul>
          <li>Complete an initial registration (approximately 5 minutes)</li>
          <li>Provide detailed information about your firefighting career and health history (approximately 30 minutes)</li>
          <li>Allow us to link your information with state cancer registries (optional)</li>
          <li>Participate in periodic follow-up surveys to update your information</li>
        </ul>

        <h3>Risks and Benefits</h3>
        <p><strong>Risks:</strong> There are minimal risks to participating. The primary risk is the potential for a breach of confidentiality, though we take extensive measures to protect your information.</p>
        <p><strong>Benefits:</strong> You will not receive direct personal benefits. However, your participation will contribute to research that may help prevent cancer in current and future firefighters.</p>

        <h3>Confidentiality</h3>
        <p>Your information will be kept confidential to the extent allowed by law. We will:</p>
        <ul>
          <li>Store all data in secure, encrypted databases</li>
          <li>Limit access to authorized research personnel only</li>
          <li>Use participant ID numbers instead of names in research datasets</li>
          <li>Never publish or share information that could identify you personally</li>
          <li>Comply with all federal privacy regulations including the Federal Information Security Management Act (FISMA)</li>
        </ul>

        <h3>Voluntary Participation</h3>
        <p>Your participation is completely voluntary. You may:</p>
        <ul>
          <li>Choose not to participate</li>
          <li>Refuse to answer any questions</li>
          <li>Withdraw from the study at any time without penalty</li>
        </ul>

        <h3>Contact Information</h3>
        <p>If you have questions about the National Firefighter Registry, please contact:</p>
        <p>
          <strong>NIOSH National Firefighter Registry</strong><br>
          Email: nfr@cdc.gov<br>
          Phone: 1-800-CDC-INFO (1-800-232-4636)
        </p>

        <h3>Your Rights</h3>
        <p>By consenting below, you acknowledge that:</p>
        <ul>
          <li>You have read and understood this consent form</li>
          <li>You have had the opportunity to ask questions</li>
          <li>Your participation is voluntary</li>
          <li>You may withdraw at any time</li>
        </ul>
      </div>
    ';
  }

  /**
   * Get assurance of confidentiality text.
   *
   * @return string
   *   HTML content for confidentiality assurance.
   */
  protected function getConfidentialityText(): string {
    return '
      <div class="confidentiality-content">
        <p>The information you provide to the National Firefighter Registry is protected under the Assurance of Confidentiality provided by Section 308(d) of the Public Health Service Act (42 U.S.C. 242m(d)).</p>
        
        <p>Under this authority, NIOSH provides the following assurance:</p>
        
        <blockquote>
          <p>No information, if an establishment or person supplying the information or described in it is identifiable, obtained in the course of activities undertaken or supported under Section 308(d) may be used for any purpose other than the purpose for which it was supplied unless such establishment or person has consented to its use for such other purpose. Such information may not be published or released in other form if the person who supplied the information or who is described in it is identifiable unless such person has consented to its publication or release in other form.</p>
        </blockquote>

        <h4>What This Means for You</h4>
        <ul>
          <li>Your personal information cannot be shared with anyone outside the authorized research team</li>
          <li>We cannot share your information with employers, insurance companies, or other third parties</li>
          <li>Research results will only be published in aggregate form that cannot identify individual participants</li>
          <li>Your information is protected by federal law</li>
        </ul>

        <h4>Data Security Measures</h4>
        <ul>
          <li>All data is encrypted both in transit and at rest</li>
          <li>Access is limited to authorized NIOSH personnel with a need to know</li>
          <li>All personnel undergo security clearance and confidentiality training</li>
          <li>Systems comply with FISMA security requirements</li>
          <li>Regular security audits are conducted</li>
        </ul>
      </div>
    ';
  }

}
