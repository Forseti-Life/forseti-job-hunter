<?php

namespace Drupal\job_hunter\Tests\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\job_hunter\Service\AdzunaApiService;
use Drupal\job_hunter\Service\CloudTalentSolutionService;
use Drupal\job_hunter\Service\GartnerJobsService;
use Drupal\job_hunter\Service\SearchAggregatorService;
use Drupal\job_hunter\Service\SerpApiService;
use Drupal\job_hunter\Service\UsaJobsApiService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SearchAggregatorService preference normalization.
 *
 * @group job_hunter
 */
class SearchAggregatorServiceTest extends UnitTestCase {

  /**
   * Tests saved preferences are applied when the request has no overrides.
   */
  public function testNormalizeSearchParametersUsesSavedPreferences(): void {
    $service = $this->createServiceWithPreferenceRow((object) [
      'sources_enabled' => json_encode(['serpapi', 'usajobs']),
      'min_salary' => 120000,
      'remote_preference' => 'remote_only',
    ]);

    $normalized = $service->normalizeSearchParameters([
      'query' => 'staff engineer',
      'sources' => [],
      'salary_min' => '',
      'remote_preference' => '',
      '_explicit_sources' => FALSE,
      '_explicit_salary_min' => FALSE,
      '_explicit_remote_preference' => FALSE,
    ]);

    $this->assertSame(['serpapi', 'usajobs'], $normalized['sources']);
    $this->assertSame(120000, $normalized['salary_min']);
    $this->assertSame('remote', $normalized['remote_preference']);
    $this->assertArrayNotHasKey('_explicit_sources', $normalized);
  }

  /**
   * Tests explicit request values are not overwritten by saved preferences.
   */
  public function testNormalizeSearchParametersPreservesExplicitOverrides(): void {
    $service = $this->createServiceWithPreferenceRow((object) [
      'sources_enabled' => json_encode(['serpapi', 'adzuna']),
      'min_salary' => 150000,
      'remote_preference' => 'remote',
    ]);

    $normalized = $service->normalizeSearchParameters([
      'sources' => ['forseti'],
      'salary_min' => '90000',
      'remote_preference' => '',
      '_explicit_sources' => TRUE,
      '_explicit_salary_min' => TRUE,
      '_explicit_remote_preference' => TRUE,
    ]);

    $this->assertSame(['forseti'], $normalized['sources']);
    $this->assertSame('90000', $normalized['salary_min']);
    $this->assertSame('', $normalized['remote_preference']);
  }

  /**
   * Tests legacy or invalid saved source keys fall back safely.
   */
  public function testNormalizeSearchParametersFallsBackWhenSavedSourcesAreInvalid(): void {
    $service = $this->createServiceWithPreferenceRow((object) [
      'sources_enabled' => json_encode(['linkedin', 'indeed']),
      'min_salary' => NULL,
      'remote_preference' => 'any',
    ]);

    $normalized = $service->normalizeSearchParameters([
      'sources' => [],
      'salary_min' => '',
      'remote_preference' => '',
      '_explicit_sources' => FALSE,
      '_explicit_salary_min' => FALSE,
      '_explicit_remote_preference' => FALSE,
    ]);

    $this->assertSame(['forseti', 'gartner'], $normalized['sources']);
    $this->assertSame('', $normalized['remote_preference']);
  }

  /**
   * Tests default sources include Gartner.
   */
  public function testNormalizeSearchParametersIncludesGartnerByDefault(): void {
    $service = $this->createServiceWithPreferenceRow(NULL);

    $normalized = $service->normalizeSearchParameters([
      'sources' => [],
      'salary_min' => '',
      'remote_preference' => '',
      '_explicit_sources' => FALSE,
      '_explicit_salary_min' => FALSE,
      '_explicit_remote_preference' => FALSE,
    ]);

    $this->assertSame(['forseti', 'gartner'], $normalized['sources']);
  }

  /**
   * Tests import logic falls back to the legacy source field when needed.
   */
  public function testImportedJobSourceFieldFallsBackToLegacySource(): void {
    $service = $this->createServiceWithSourceFieldAvailability(FALSE);
    $this->assertSame('source', $service->exposeImportedJobSourceField());
  }

  /**
   * Tests import logic uses external_source when the current schema has it.
   */
  public function testImportedJobSourceFieldUsesExternalSourceWhenAvailable(): void {
    $service = $this->createServiceWithSourceFieldAvailability(TRUE);
    $this->assertSame('external_source', $service->exposeImportedJobSourceField());
  }

  /**
   * Tests location filtering removes broad off-target results.
   */
  public function testSearchJobsFiltersResultsOutsideRequestedLocation(): void {
    $service = $this->createSearchServiceWithStaticResults([
      'forseti' => [
        [
          'id' => 'forseti_1',
          'title' => 'Chief Information Officer',
          'company' => 'Acme Health',
          'location' => 'Philadelphia, PA',
          'employment_type' => 'Not specified',
          'salary_range' => 'Not specified',
          'description' => 'Local role',
          'source' => 'Forseti Jobs',
          'posted_date' => 'Jan 1, 2026',
          'url' => '/jobhunter/job/1',
        ],
        [
          'id' => 'forseti_2',
          'title' => 'Chief Information Officer',
          'company' => 'Acme Health',
          'location' => 'Dallas, TX',
          'employment_type' => 'Not specified',
          'salary_range' => 'Not specified',
          'description' => 'Off-target role',
          'source' => 'Forseti Jobs',
          'posted_date' => 'Jan 1, 2026',
          'url' => '/jobhunter/job/2',
        ],
      ],
      'serpapi' => [
        'results' => [
          [
            'id' => 'serpapi_1',
            'title' => 'CIO',
            'company' => 'Regional Bank',
            'location' => 'Greater Philadelphia Area',
            'employment_type' => 'Not specified',
            'salary_range' => 'Not specified',
            'description' => 'Local external role',
            'source' => 'Google Jobs (SerpAPI)',
            'posted_date' => 'Jan 2, 2026',
            'url' => 'https://example.com/phl',
          ],
          [
            'id' => 'serpapi_2',
            'title' => 'CIO',
            'company' => 'Regional Bank',
            'location' => 'Austin, Texas, United States',
            'employment_type' => 'Not specified',
            'salary_range' => 'Not specified',
            'description' => 'Broad external role',
            'source' => 'Google Jobs (SerpAPI)',
            'posted_date' => 'Jan 2, 2026',
            'url' => 'https://example.com/tx',
          ],
        ],
        'pagination' => [
          'current_page' => 3,
          'next_page_token' => 'token-123',
          'has_more' => TRUE,
        ],
      ],
    ]);

    $results = $service->searchJobs([
      'query' => 'CIO',
      'location' => 'Philadelphia, PA',
      'sources' => ['forseti', 'serpapi'],
      '_explicit_sources' => TRUE,
      '_explicit_salary_min' => FALSE,
      '_explicit_remote_preference' => FALSE,
    ]);

    $this->assertCount(2, $results['results']);
    $this->assertSame('Philadelphia, PA', $results['results'][0]['location']);
    $this->assertSame('Greater Philadelphia Area', $results['results'][1]['location']);
    $this->assertSame(2, $results['total']);
    $this->assertSame('token-123', $results['pagination']['serpapi']['next_page_token']);
  }

  /**
   * Builds the service under test with a mocked preferences row.
   *
   * @param object|null $row
   *   Database row returned by fetchObject().
   *
   * @return \Drupal\job_hunter\Service\SearchAggregatorService
   *   Service under test.
   */
  protected function createServiceWithPreferenceRow(?object $row): SearchAggregatorService {
    $statement = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['fetchObject'])
      ->getMock();
    $statement->method('fetchObject')->willReturn($row);

    $query = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['fields', 'condition', 'execute'])
      ->getMock();
    $query->method('fields')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn($statement);

    $database = $this->createMock(Connection::class);
    $database->method('select')
      ->with('jobhunter_source_preferences', 'sp')
      ->willReturn($query);

    $current_user = $this->createMock(AccountProxyInterface::class);
    $current_user->method('isAuthenticated')->willReturn(TRUE);
    $current_user->method('id')->willReturn(42);

    $logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('job_hunter')->willReturn($logger);

    return new SearchAggregatorService(
      $database,
      $this->createSettingsConfigFactory(),
      $current_user,
      $logger_factory,
      $this->createMock(CloudTalentSolutionService::class),
      $this->createMock(AdzunaApiService::class),
      $this->createMock(UsaJobsApiService::class),
      $this->createMock(GartnerJobsService::class),
      $this->createMock(SerpApiService::class)
    );
  }

  /**
   * Builds the service under test with a mocked job source field presence.
   */
  protected function createServiceWithSourceFieldAvailability(bool $has_external_source): TestableSearchAggregatorService {
    $schema = $this->createMock(Schema::class);
    $schema->method('fieldExists')
      ->willReturnCallback(static function (string $table, string $field) use ($has_external_source): bool {
        return $table === 'jobhunter_job_requirements' && $field === 'external_source' ? $has_external_source : FALSE;
      });

    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);

    $current_user = $this->createMock(AccountProxyInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('job_hunter')->willReturn($logger);

    return new TestableSearchAggregatorService(
      $database,
      $this->createSettingsConfigFactory(),
      $current_user,
      $logger_factory,
      $this->createMock(CloudTalentSolutionService::class),
      $this->createMock(AdzunaApiService::class),
      $this->createMock(UsaJobsApiService::class),
      $this->createMock(GartnerJobsService::class),
      $this->createMock(SerpApiService::class)
    );
  }

  /**
   * Builds the service under test with static source results.
   *
   * @param array $source_results
   *   Source-specific results keyed by source name.
   */
  protected function createSearchServiceWithStaticResults(array $source_results): TestableSearchAggregatorServiceWithStaticResults {
    $current_user = $this->createMock(AccountProxyInterface::class);
    $current_user->method('isAuthenticated')->willReturn(FALSE);

    $logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('job_hunter')->willReturn($logger);

    $service = new TestableSearchAggregatorServiceWithStaticResults(
      $this->createMock(Connection::class),
      $this->createSettingsConfigFactory(),
      $current_user,
      $logger_factory,
      $this->createMock(CloudTalentSolutionService::class),
      $this->createMock(AdzunaApiService::class),
      $this->createMock(UsaJobsApiService::class),
      $this->createMock(GartnerJobsService::class),
      $this->createMock(SerpApiService::class)
    );
    $service->setSourceResults($source_results);

    return $service;
  }

  /**
   * Builds a config factory mock for job_hunter.settings lookups.
   *
   * @param array<string, mixed> $settings
   *   Config values returned by the job_hunter.settings mock.
   */
  protected function createSettingsConfigFactory(array $settings = []): ConfigFactoryInterface {
    $config = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['get'])
      ->getMock();
    $config->method('get')->willReturnCallback(static function (string $key) use ($settings) {
      return $settings[$key] ?? NULL;
    });

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('job_hunter.settings')
      ->willReturn($config);

    return $config_factory;
  }

}

/**
 * Testable subclass exposing protected SearchAggregatorService helpers.
 */
class TestableSearchAggregatorService extends SearchAggregatorService {

  /**
   * Exposes imported source field resolution for unit tests.
   */
  public function exposeImportedJobSourceField(): string {
    return $this->getImportedJobSourceField();
  }

}

/**
 * Testable subclass with controllable source outputs.
 */
class TestableSearchAggregatorServiceWithStaticResults extends TestableSearchAggregatorService {

  /**
   * @var array<string, array>
   */
  protected array $sourceResults = [];

  /**
   * Sets the source results returned by the overridden source methods.
   *
   * @param array<string, array> $source_results
   *   Source outputs keyed by source name.
   */
  public function setSourceResults(array $source_results): void {
    $this->sourceResults = $source_results;
  }

  /**
   * {@inheritdoc}
   */
  protected function searchForsetiDatabase(array $params): array {
    return $this->sourceResults['forseti'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  protected function searchSerpApi(array $params): array {
    return $this->sourceResults['serpapi'] ?? [
      'results' => [],
      'pagination' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function storeSearchResults(array $params, array $results): void {}

  /**
   * {@inheritdoc}
   */
  protected function importRecentResults(): void {}

}
