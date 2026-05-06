<?php

namespace Drupal\job_hunter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for bulk importing companies from a text list.
 */
class BulkCompanyImportForm extends FormBase {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new BulkCompanyImportForm.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'job_hunter_bulk_company_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    
    $form['header'] = [
      '#type' => 'markup',
      '#markup' => '<h2>Add Target Companies</h2>
                    <p class="description"><strong>These are your primary target companies</strong> that the Job Hunter will focus on when searching for opportunities. Add the companies you want to work for, and the AI will help you discover and track job openings from these organizations.</p>
                    <p><strong>Enter one company name per line.</strong> You can add a single company or import multiple companies at once.</p>',
    ];

    $form['company_list'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Target Company Names (one per line)'),
      '#description' => $this->t('Enter one company name per line. These will be your primary job search targets. For example:<br/>
                                 Google<br/>
                                 Microsoft<br/>
                                 Apple<br/>
                                 Amazon'),
      '#rows' => 15,
      '#required' => TRUE,
      '#placeholder' => "Google\nMicrosoft\nApple\nAmazon\nFacebook\nNetflix\nTesla\nSpaceX",
    ];

    $form['options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Import Options'),
      '#collapsible' => FALSE,
    ];

    $form['options']['skip_duplicates'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Skip duplicates'),
      '#description' => $this->t('Skip companies that already exist (based on exact title match)'),
      '#default_value' => TRUE,
    ];

    $form['options']['publish_immediately'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publish immediately'),
      '#description' => $this->t('Publish all imported companies immediately (recommended)'),
      '#default_value' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Companies'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('job_hunter.dashboard'),
      '#attributes' => ['class' => ['button', 'button--secondary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $company_list = trim($form_state->getValue('company_list'));
    
    if (empty($company_list)) {
      $form_state->setErrorByName('company_list', $this->t('Please enter at least one company name.'));
      return;
    }

    // Split companies and validate
    $companies = array_filter(array_map('trim', explode("\n", $company_list)));
    
    if (empty($companies)) {
      $form_state->setErrorByName('company_list', $this->t('Please enter valid company names, one per line.'));
      return;
    }

    // Check for extremely long company names
    foreach ($companies as $company) {
      if (strlen($company) > 255) {
        $form_state->setErrorByName('company_list', $this->t('Company name is too long: @company (max 255 characters)', ['@company' => substr($company, 0, 50) . '...']));
        return;
      }
    }

    // Store cleaned companies for submission
    $form_state->setValue('cleaned_companies', $companies);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $companies = $form_state->getValue('cleaned_companies');
    $skip_duplicates = $form_state->getValue('skip_duplicates');
    $publish_immediately = $form_state->getValue('publish_immediately');

    $created_count = 0;
    $skipped_count = 0;
    $error_count = 0;

    foreach ($companies as $company_name) {
      try {
        // Check for duplicates if option is enabled
        if ($skip_duplicates) {
          $existing_query = \Drupal::entityQuery('node')
            ->condition('type', 'company')
            ->condition('title', $company_name)
            ->accessCheck(TRUE)
            ->range(0, 1);
          
          if ($existing_query->execute()) {
            $skipped_count++;
            continue;
          }
        }

        // Create the company node
        $company_node = Node::create([
          'type' => 'company',
          'title' => $company_name,
          'status' => $publish_immediately ? 1 : 0,
          'uid' => \Drupal::currentUser()->id(),
        ]);

        $company_node->save();
        $created_count++;

      } catch (\Exception $e) {
        $error_count++;
        \Drupal::logger('job_hunter')->error('Failed to create company "@company": @error', [
          '@company' => $company_name,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    // Display results
    if ($created_count > 0) {
      $this->messenger->addMessage($this->t('Successfully created @count companies.', ['@count' => $created_count]));
    }

    if ($skipped_count > 0) {
      $this->messenger->addMessage($this->t('Skipped @count duplicate companies.', ['@count' => $skipped_count]));
    }

    if ($error_count > 0) {
      $this->messenger->addError($this->t('Failed to create @count companies. Check the logs for details.', ['@count' => $error_count]));
    }

    if ($created_count === 0 && $skipped_count === 0 && $error_count === 0) {
      $this->messenger->addWarning($this->t('No companies were processed.'));
    }

    // Redirect back to dashboard
    $form_state->setRedirect('job_hunter.dashboard');
  }

}