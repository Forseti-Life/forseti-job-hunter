<?php

namespace Drupal\job_hunter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for submitting support requests.
 */
class SupportForm extends FormBase {

  /**
   * Minimum length for support request subject.
   */
  const MIN_SUBJECT_LENGTH = 5;

  /**
   * Maximum length for support request subject.
   */
  const MAX_SUBJECT_LENGTH = 255;

  /**
   * Minimum length for support request description.
   */
  const MIN_DESCRIPTION_LENGTH = 10;

  /**
   * Maximum length for support request description.
   */
  const MAX_DESCRIPTION_LENGTH = 10000;

  /**
   * Rate limit window in seconds (1 hour).
   */
  const RATE_LIMIT_WINDOW = 3600;

  /**
   * Maximum number of support requests allowed per rate limit window.
   */
  const RATE_LIMIT_MAX_REQUESTS = 3;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs a SupportForm object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user account.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    AccountInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    MailManagerInterface $mail_manager,
    TimeInterface $time
  ) {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->mailManager = $mail_manager;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.mail'),
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_hunter_support_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#prefix'] = '<div class="support-form">';
    $form['#suffix'] = '</div>';

    $form['issue_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Issue Type'),
      '#description' => $this->t('Please select the type of issue you are experiencing.'),
      '#options' => [
        'application_submission' => $this->t('Application Submission Problem'),
        'profile_issue' => $this->t('Profile or Account Issue'),
        'technical_error' => $this->t('Technical Error or Bug'),
        'feature_request' => $this->t('Feature Request'),
        'other' => $this->t('Other'),
      ],
      '#required' => TRUE,
    ];

    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#description' => $this->t('Brief description of your issue (@min-@max characters).', [
        '@min' => self::MIN_SUBJECT_LENGTH,
        '@max' => self::MAX_SUBJECT_LENGTH,
      ]),
      '#required' => TRUE,
      '#maxlength' => self::MAX_SUBJECT_LENGTH,
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Detailed Description'),
      '#description' => $this->t('Please provide as much detail as possible about your issue (@min-@max characters).', [
        '@min' => self::MIN_DESCRIPTION_LENGTH,
        '@max' => self::MAX_DESCRIPTION_LENGTH,
      ]),
      '#required' => TRUE,
      '#rows' => 8,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit Support Request'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $subject = trim($form_state->getValue('subject'));
    $description = trim($form_state->getValue('description'));
    $uid = $this->currentUser->id();

    // Validate subject length
    if (strlen($subject) < self::MIN_SUBJECT_LENGTH) {
      $form_state->setErrorByName('subject', $this->t('Subject must be at least @min characters long.', [
        '@min' => self::MIN_SUBJECT_LENGTH,
      ]));
    }

    // Validate description length
    if (strlen($description) < self::MIN_DESCRIPTION_LENGTH) {
      $form_state->setErrorByName('description', $this->t('Description must be at least @min characters long.', [
        '@min' => self::MIN_DESCRIPTION_LENGTH,
      ]));
    }
    if (strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
      $form_state->setErrorByName('description', $this->t('Description is too long (maximum @max characters).', [
        '@max' => self::MAX_DESCRIPTION_LENGTH,
      ]));
    }

    // Check for rate limiting - prevent spam
    if ($this->isRateLimited($uid)) {
      $form_state->setErrorByName('', $this->t('You have submitted too many support requests recently. Please wait before submitting another request.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $uid = $this->currentUser->id();

    // Trim user inputs before storage; XSS protection occurs during rendering via Form API/Entity API automatic escaping
    $subject = trim($values['subject']);
    $description = trim($values['description']);
    $issue_type = $values['issue_type'];

    try {
      // Create a support request node
      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'support_request',
        'title' => $subject,
        'field_support_subject' => $subject,
        'field_support_description' => $description,
        'field_support_issue_type' => $issue_type,
        'field_support_status' => 'open',
        'field_support_user_ref' => $uid,
        'uid' => $uid,
        'status' => 1,
      ]);

      $node->save();

      // Send confirmation message to user
      $this->messenger()->addStatus($this->t('Your support request has been submitted successfully. We will review your request and respond as soon as possible.'));

      // Log the submission
      $this->logger('job_hunter')->info(
        'Support request created by user @uid: @subject',
        [
          '@uid' => $uid,
          '@subject' => $subject,
        ]
      );

      // Send notification email to admins (queued in a real implementation)
      $this->sendAdminNotification($node->id(), $subject, $issue_type);

      // Redirect to support page
      $form_state->setRedirect('job_hunter.support');

    }
    catch (EntityStorageException $e) {
      $this->logger('job_hunter')->error('Failed to create support request: @error', ['@error' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Failed to submit support request. Please try again later.'));
    }
  }

  /**
   * Check if user is rate limited for support submissions.
   *
   * @param int $uid
   *   The user ID to check.
   *
   * @return bool
   *   TRUE if rate limited, FALSE otherwise.
   */
  protected function isRateLimited($uid) {
    // Calculate the start of the rate limit window
    $window_start_time = $this->time->getRequestTime() - self::RATE_LIMIT_WINDOW;
    
    // Use accessCheck(TRUE) to enforce view access control on query results
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'support_request')
      ->condition('uid', $uid)
      ->condition('created', $window_start_time, '>')
      ->accessCheck(TRUE);

    $count = $query->count()->execute();

    // Limit to configured max requests per window
    return $count >= self::RATE_LIMIT_MAX_REQUESTS;
  }

  /**
   * Send notification email to administrators about new support request.
   *
   * @param int $node_id
   *   The support request node ID.
   * @param string $subject
   *   The support request subject.
   * @param string $issue_type
   *   The issue type.
   */
  protected function sendAdminNotification($node_id, $subject, $issue_type) {
    // In a production implementation, this would queue the email
    // For now, we'll just log that a notification should be sent
    $this->logger('job_hunter')->info(
      'Support request notification queued for node @nid: @subject (@type)',
      [
        '@nid' => $node_id,
        '@subject' => $subject,
        '@type' => $issue_type,
      ]
    );
  }

}
