<?php

namespace Drupal\company_research\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller for company research operations.
 */
class CompanyResearchController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a CompanyResearchController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Display research results.
   *
   * @param int $research_id
   *   The research ID.
   *
   * @return array
   *   Render array.
   */
  public function results(int $research_id) {
    // Fetch research results from database.
    $result = $this->database->select('company_research_results', 'r')
      ->fields('r')
      ->condition('id', $research_id)
      ->execute()
      ->fetchAssoc();

    if (!$result) {
      $this->messenger()->addError($this->t('Research results not found.'));
      return $this->redirect('company_research.research_form');
    }

    // Decode JSON fields.
    $result['careers_page_urls'] = json_decode($result['careers_page_urls'] ?? '[]', TRUE);
    $result['auth_methods'] = json_decode($result['auth_methods'] ?? '[]', TRUE);
    $result['verification_requirements'] = json_decode($result['verification_requirements'] ?? '{}', TRUE);
    $result['metadata'] = json_decode($result['metadata'] ?? '{}', TRUE);

    return [
      '#theme' => 'company_research_results',
      '#company_name' => $result['company_name'],
      '#results' => $result,
      '#cache' => [
        'max-age' => 3600,
        'contexts' => ['url.path'],
      ],
    ];
  }

  /**
   * Refresh research results.
   *
   * @param int $research_id
   *   The research ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response.
   */
  public function refresh(int $research_id) {
    // Fetch existing research.
    $result = $this->database->select('company_research_results', 'r')
      ->fields('r', ['company_name'])
      ->condition('id', $research_id)
      ->execute()
      ->fetchField();

    if (!$result) {
      $this->messenger()->addError($this->t('Research results not found.'));
      return new RedirectResponse(Url::fromRoute('company_research.research_form')->toString());
    }

    // Trigger refresh by redirecting to form with company name.
    $this->messenger()->addStatus($this->t('Please submit the form to refresh research for @company.', [
      '@company' => $result,
    ]));

    return new RedirectResponse(Url::fromRoute('company_research.research_form')->toString());
  }

}
