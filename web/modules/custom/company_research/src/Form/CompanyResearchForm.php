<?php

namespace Drupal\company_research\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\company_research\Service\CompanyResearchOrchestrator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a form for researching companies.
 */
class CompanyResearchForm extends FormBase {

  /**
   * The company research orchestrator service.
   *
   * @var \Drupal\company_research\Service\CompanyResearchOrchestrator
   */
  protected $orchestrator;

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a CompanyResearchForm object.
   *
   * @param \Drupal\company_research\Service\CompanyResearchOrchestrator $orchestrator
   *   The orchestrator service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   */
  public function __construct(CompanyResearchOrchestrator $orchestrator, RequestStack $request_stack) {
    $this->orchestrator = $orchestrator;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('company_research.orchestrator'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'company_research_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#prefix'] = '<div class="company-research-form-wrapper">';
    $form['#suffix'] = '</div>';

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Enter a company name to research their career pages, ATS platform, and authentication requirements.') . '</p>',
    ];

    // Pre-fill company name from query parameter if provided.
    $request = $this->requestStack->getCurrentRequest();
    $default_company_name = $request->query->get('company_name', '');

    $form['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company Name'),
      '#description' => $this->t('Enter the name of the company you want to research (e.g., "Acme Corporation").'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $default_company_name,
    ];

    $form['refresh'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force refresh'),
      '#description' => $this->t('Check this to bypass cached results and perform a fresh research.'),
      '#default_value' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Research Company'),
      '#button_type' => 'primary',
    ];

    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to Company Research'),
      '#url' => Url::fromRoute('job_hunter.company_research'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $company_name = $form_state->getValue('company_name');
    $refresh = (bool) $form_state->getValue('refresh');

    try {
      // Execute research.
      $results = $this->orchestrator->executeResearch($company_name, [
        'refresh' => $refresh,
      ]);

      $this->messenger()->addStatus(
        $this->t('Research completed for @company.', [
          '@company' => $company_name,
        ])
      );

      // Redirect to results page.
      $form_state->setRedirect('company_research.results', [
        'research_id' => $results['id'],
      ]);
    }
    catch (\Exception $e) {
      $this->messenger()->addError(
        $this->t('Research failed: @error', [
          '@error' => $e->getMessage(),
        ])
      );
    }
  }

}
