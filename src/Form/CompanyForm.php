<?php

namespace Drupal\job_hunter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding/editing companies.
 */
class CompanyForm extends FormBase {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new CompanyForm.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
    $this->database = \Drupal::database();
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
    return 'job_hunter_company_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $company_id = NULL) {
    $company = NULL;
    
    // Load existing company if editing
    if ($company_id) {
      $company = $this->database->select('jobhunter_companies', 'c')
        ->fields('c')
        ->condition('id', $company_id)
        ->execute()
        ->fetchObject();
      
      if (!$company) {
        $this->messenger->addError($this->t('Company not found.'));
        return $form;
      }
      
      $form_state->set('company_id', $company_id);
    }

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company Name'),
      '#required' => TRUE,
      '#default_value' => $company ? $company->name : '',
      '#maxlength' => 255,
      '#description' => $this->t('Enter the company name (other details can be added later)'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $company_id ? $this->t('Update Company') : $this->t('Add Company'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('job_hunter.companies_list'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $company_id = $form_state->get('company_id');
    $timestamp = \Drupal::time()->getRequestTime();

    $fields = [
      'name' => $form_state->getValue('name'),
      'active' => 1,
      'updated' => $timestamp,
    ];

    if ($company_id) {
      // Update existing company
      $this->database->update('jobhunter_companies')
        ->fields($fields)
        ->condition('id', $company_id)
        ->execute();
      
      $this->messenger->addMessage($this->t('Company "@name" has been updated.', [
        '@name' => $fields['name'],
      ]));
    }
    else {
      // Insert new company
      $fields['created'] = $timestamp;
      
      $this->database->insert('jobhunter_companies')
        ->fields($fields)
        ->execute();
      
      $this->messenger->addMessage($this->t('Company "@name" has been added.', [
        '@name' => $fields['name'],
      ]));
    }

    $form_state->setRedirect('job_hunter.companies_list');
  }

}
