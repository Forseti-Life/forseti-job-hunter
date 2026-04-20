<?php

namespace Drupal\job_hunter\Tests\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\job_hunter\Service\AdzunaApiService;
use Drupal\job_hunter\Service\CloudTalentSolutionService;
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

    $this->assertSame(['forseti'], $normalized['sources']);
    $this->assertSame('', $normalized['remote_preference']);
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
      $this->createMock(ConfigFactoryInterface::class),
      $current_user,
      $logger_factory,
      $this->createMock(CloudTalentSolutionService::class),
      $this->createMock(AdzunaApiService::class),
      $this->createMock(UsaJobsApiService::class),
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
      $this->createMock(ConfigFactoryInterface::class),
      $current_user,
      $logger_factory,
      $this->createMock(CloudTalentSolutionService::class),
      $this->createMock(AdzunaApiService::class),
      $this->createMock(UsaJobsApiService::class),
      $this->createMock(SerpApiService::class)
    );
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
