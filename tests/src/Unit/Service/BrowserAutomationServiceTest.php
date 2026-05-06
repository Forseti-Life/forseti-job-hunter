<?php

namespace Drupal\Tests\job_hunter\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Service\BrowserAutomationService;
use Drupal\job_hunter\Service\ApplyUrlResolverService;
use Drupal\job_hunter\Service\ApplicationFormMapper;
use Drupal\job_hunter\Service\JobSeekerService;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Unit tests for BrowserAutomationService routing logic and attempt logging.
 *
 * Uses a testable subclass to expose routeByPlatform (protected) and stub
 * external dependencies (Playwright bridge, DB lookups) without a live site.
 *
 * @group job_hunter
 * @coversDefaultClass \Drupal\job_hunter\Service\BrowserAutomationService
 */
class BrowserAutomationServiceTest extends UnitTestCase {

  /**
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * @var \Drupal\job_hunter\Service\ApplyUrlResolverService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $urlResolver;

  /**
   * @var \Drupal\job_hunter\Service\ApplicationFormMapper|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $formMapper;

  /**
   * @var \Drupal\job_hunter\Service\JobSeekerService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $jobSeekerService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->logger = $this->createMock(LoggerChannelInterface::class);

    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->method('get')->willReturn($this->logger);

    $this->database     = $this->createMock(Connection::class);
    $this->urlResolver  = $this->createMock(ApplyUrlResolverService::class);
    $this->formMapper   = $this->createMock(ApplicationFormMapper::class);
    $this->jobSeekerService = $this->createMock(JobSeekerService::class);
  }

  /**
   * Build a testable service with optional credential stub result.
   */
  protected function buildService(bool $credentials = FALSE): TestableBrowserAutomationService {
    $svc = new TestableBrowserAutomationService(
      $this->database,
      $this->loggerFactory,
      $this->urlResolver,
      $this->formMapper,
      $this->jobSeekerService,
      $credentials
    );
    return $svc;
  }

  // -------------------------------------------------------------------------
  // routeByPlatform: aggregator / unknown → manual_required
  // -------------------------------------------------------------------------

  /**
   * @covers ::routeByPlatform
   */
  public function testAggregatorPlatformReturnsManualRequired(): void {
    $svc = $this->buildService();
    $result = $svc->testRouteByPlatform(1, 10, 100, [], 'https://jobs.example.com/123', 'aggregator', 'Job Aggregator');

    $this->assertEquals('manual_required', $result['outcome']);
    $this->assertEquals('no_direct_ats', $result['reason']);
    $this->assertFalse($result['success']);
  }

  /**
   * @covers ::routeByPlatform
   */
  public function testUnknownPlatformReturnsManualRequired(): void {
    $svc = $this->buildService();
    $result = $svc->testRouteByPlatform(1, 10, 100, [], 'https://jobs.example.com/123', 'unknown', 'Unknown');

    $this->assertEquals('manual_required', $result['outcome']);
    $this->assertEquals('no_direct_ats', $result['reason']);
  }

  /**
   * @covers ::routeByPlatform
   */
  public function testEmptyPlatformReturnsManualRequired(): void {
    $svc = $this->buildService();
    $result = $svc->testRouteByPlatform(1, 10, 100, [], 'https://jobs.example.com/123', '', 'Unknown');

    $this->assertEquals('manual_required', $result['outcome']);
  }

  // -------------------------------------------------------------------------
  // routeByPlatform: automatable platform without bridge → manual_required
  // -------------------------------------------------------------------------

  /**
   * When platform is automatable but Playwright bridge is unavailable (returns NULL),
   * outcome should be manual_required with reason bridge_unavailable.
   *
   * @covers ::routeByPlatform
   */
  public function testAutomatablePlatformBridgeUnavailableReturnsManualRequired(): void {
    // TestableBrowserAutomationService stubs runPlaywrightBridge to return NULL.
    $svc = $this->buildService();
    $result = $svc->testRouteByPlatform(1, 10, 100, [], 'https://boards.greenhouse.io/acme/jobs/1', 'greenhouse', 'Greenhouse');

    $this->assertEquals('manual_required', $result['outcome']);
    $this->assertEquals('bridge_unavailable', $result['reason']);
    $this->assertFalse($result['requires_credentials']);
  }

  /**
   * @covers ::routeByPlatform
   */
  public function testLeverPlatformBridgeUnavailableReturnsManualRequired(): void {
    $svc = $this->buildService();
    $result = $svc->testRouteByPlatform(1, 10, 100, [], 'https://jobs.lever.co/acme/abc', 'lever', 'Lever');

    $this->assertEquals('manual_required', $result['outcome']);
    $this->assertEquals('bridge_unavailable', $result['reason']);
  }

  // -------------------------------------------------------------------------
  // routeByPlatform: login-required platform without credentials → manual_required
  // -------------------------------------------------------------------------

  /**
   * Login-required ATS without credentials → manual_required with reason no_credentials.
   *
   * @covers ::routeByPlatform
   */
  public function testLoginRequiredPlatformNoCredentialsReturnsManualRequired(): void {
    $svc = $this->buildService(FALSE); // credentials = false
    $result = $svc->testRouteByPlatform(1, 10, 100, [], 'https://acme.wd1.myworkdayjobs.com/jobs/1', 'workday', 'Workday');

    $this->assertEquals('manual_required', $result['outcome']);
    $this->assertEquals('no_credentials', $result['reason']);
    $this->assertTrue($result['requires_credentials']);
    $this->assertFalse($result['has_credentials']);
    $this->assertArrayHasKey('login_url', $result);
  }

  /**
   * @covers ::routeByPlatform
   */
  public function testTaleoPlatformNoCredentialsReturnsManualRequired(): void {
    $svc = $this->buildService(FALSE);
    $result = $svc->testRouteByPlatform(1, 10, 100, [], 'https://taleo.net/careersection/1/jobdetail.ftl?job=123', 'taleo', 'Oracle Taleo');

    $this->assertEquals('manual_required', $result['outcome']);
    $this->assertEquals('no_credentials', $result['reason']);
    $this->assertTrue($result['requires_credentials']);
  }

  // -------------------------------------------------------------------------
  // routeByPlatform: login-required platform WITH credentials but no bridge
  // -------------------------------------------------------------------------

  /**
   * Login-required ATS with credentials but bridge unavailable → manual_required (bridge_unavailable).
   *
   * @covers ::routeByPlatform
   */
  public function testLoginRequiredWithCredentialsBridgeUnavailableReturnsManualRequired(): void {
    $svc = $this->buildService(TRUE); // credentials = true
    $result = $svc->testRouteByPlatform(1, 10, 100, [], 'https://acme.wd1.myworkdayjobs.com/jobs/1', 'workday', 'Workday');

    $this->assertEquals('manual_required', $result['outcome']);
    $this->assertEquals('bridge_unavailable', $result['reason']);
    $this->assertTrue($result['requires_credentials']);
    $this->assertTrue($result['has_credentials']);
  }

  // -------------------------------------------------------------------------
  // routeByPlatform: custom platform → manual_required (custom_page)
  // -------------------------------------------------------------------------

  /**
   * Non-ATS company career page (not aggregator/automatable/login-required)
   * should fall through to custom_page manual result.
   *
   * @covers ::routeByPlatform
   */
  public function testCustomPlatformReturnsManualRequired(): void {
    $svc = $this->buildService();
    $result = $svc->testRouteByPlatform(1, 10, 100, [], 'https://careers.acme.com/apply/123', 'custom', 'Company Career Page');

    $this->assertEquals('manual_required', $result['outcome']);
    $this->assertEquals('custom_page', $result['reason']);
  }

  // -------------------------------------------------------------------------
  // logAttempt: inserts into jobhunter_application_attempts
  // -------------------------------------------------------------------------

  /**
   * logAttempt should execute a database INSERT on jobhunter_application_attempts.
   *
   * @covers ::logAttempt
   */
  public function testLogAttemptCallsDatabaseInsert(): void {
    $insertQuery = $this->createMock(Insert::class);
    $insertQuery->method('fields')->willReturnSelf();
    $insertQuery->expects($this->once())->method('execute');

    $this->database
      ->expects($this->once())
      ->method('insert')
      ->with('jobhunter_application_attempts')
      ->willReturn($insertQuery);

    $svc = $this->buildService();
    $svc->testLogAttempt(100, 1, 'https://boards.greenhouse.io/acme/1', 'greenhouse', 'manual_required', 250, NULL, []);
  }

  /**
   * logAttempt should not throw even when the insert fails (silent error).
   *
   * @covers ::logAttempt
   */
  public function testLogAttemptSilentlyHandlesInsertFailure(): void {
    $this->database
      ->method('insert')
      ->willThrowException(new \Exception('DB connection lost'));

    $this->logger->expects($this->once())->method('error');

    $svc = $this->buildService();
    // Should not throw.
    $svc->testLogAttempt(100, 1, 'https://boards.greenhouse.io/acme/1', 'greenhouse', 'manual_required', 250, 'some error', []);
  }

  // -------------------------------------------------------------------------
  // TC-09: logAttempt called even when Playwright bridge returns failure status
  // -------------------------------------------------------------------------

  /**
   * TC-09: When routeByPlatform returns a failure-outcome result, logAttempt
   * must still insert a run-history record (logging is not conditional on success).
   *
   * @covers ::logAttempt
   */
  public function testLogAttemptCalledWithFailureOutcome(): void {
    $insertQuery = $this->createMock(Insert::class);
    $insertQuery->method('fields')->willReturnSelf();
    $insertQuery->expects($this->once())->method('execute');

    $this->database
      ->expects($this->once())
      ->method('insert')
      ->with('jobhunter_application_attempts')
      ->willReturn($insertQuery);

    $svc = $this->buildService();
    // Invoke logAttempt directly with a failure/timeout outcome.
    $svc->testLogAttempt(100, 1, 'https://boards.greenhouse.io/acme/1', 'greenhouse', 'failure', 5000, 'Bridge timeout', ['ats_label' => 'Greenhouse']);
  }

  /**
   * TC-09b: logAttempt called with status='timeout' (bridge timeout path).
   *
   * @covers ::logAttempt
   */
  public function testLogAttemptCalledWithTimeoutOutcome(): void {
    $insertQuery = $this->createMock(Insert::class);
    $insertQuery->method('fields')->willReturnSelf();
    $insertQuery->expects($this->once())->method('execute');

    $this->database
      ->expects($this->once())
      ->method('insert')
      ->with('jobhunter_application_attempts')
      ->willReturn($insertQuery);

    $svc = $this->buildService();
    $svc->testLogAttempt(101, 2, 'https://jobs.lever.co/acme/abc', 'lever', 'timeout', 95000, 'Playwright timeout after 95s', []);
  }

  // -------------------------------------------------------------------------
  // TC-11: runPlaywrightBridge() exception caught → structured error returned
  // -------------------------------------------------------------------------

  /**
   * TC-11: When the Playwright bridge throws an exception, routeByPlatform
   * must catch it and return a structured result (manual_required), not
   * propagate the exception.
   *
   * Uses TestableBrowserAutomationServiceWithThrowingBridge which stubs
   * runPlaywrightBridge to throw a RuntimeException.
   *
   * @covers ::routeByPlatform
   */
  public function testPlaywrightBridgeExceptionCaughtAndStructuredErrorReturned(): void {
    $svc = new TestableBrowserAutomationServiceWithThrowingBridge(
      $this->database,
      $this->loggerFactory,
      $this->urlResolver,
      $this->formMapper,
      $this->jobSeekerService
    );

    // Should not throw — must return structured result.
    $result = $svc->testRouteByPlatform(1, 10, 100, [], 'https://boards.greenhouse.io/acme/jobs/1', 'greenhouse', 'Greenhouse');

    $this->assertIsArray($result, 'routeByPlatform must return an array even when bridge throws');
    $this->assertArrayHasKey('outcome', $result, 'Result must contain outcome key');
    $this->assertEquals('manual_required', $result['outcome']);
    $this->assertFalse($result['success']);
  }

  // -------------------------------------------------------------------------
  // TC-12: DB absent — logAttempt fails gracefully with logged error
  // -------------------------------------------------------------------------

  /**
   * TC-12: When the run-history DB table is absent (simulated via exception),
   * logAttempt must log an error and NOT throw a fatal exception to the caller.
   *
   * @covers ::logAttempt
   */
  public function testLogAttemptGracefulOnMissingTable(): void {
    $this->database
      ->method('insert')
      ->willThrowException(new \Drupal\Core\Database\DatabaseExceptionWrapper('Table not found: jobhunter_application_attempts'));

    // Error should be logged.
    $this->logger->expects($this->once())->method('error');

    $svc = $this->buildService();
    // Must not throw.
    $svc->testLogAttempt(200, 5, 'https://boards.greenhouse.io/acme/1', 'greenhouse', 'manual_required', 100, NULL, []);
  }

}

/**
 * Testable subclass: exposes protected methods and stubs external I/O.
 */
class TestableBrowserAutomationService extends BrowserAutomationService {

  /**
   * Stub credential result for checkCredentials.
   */
  private bool $credentialsResult;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    ApplyUrlResolverService $url_resolver,
    ApplicationFormMapper $form_mapper,
    JobSeekerService $job_seeker_service,
    bool $credentials_result = FALSE
  ) {
    parent::__construct($database, $logger_factory, $url_resolver, $form_mapper, $job_seeker_service);
    $this->credentialsResult = $credentials_result;
  }

  /**
   * Expose routeByPlatform for testing.
   */
  public function testRouteByPlatform(int $uid, int $job_id, int $application_id, array $app_data, string $apply_url, string $ats_platform, string $ats_label): array {
    return $this->routeByPlatform($uid, $job_id, $application_id, $app_data, $apply_url, $ats_platform, $ats_label);
  }

  /**
   * Expose logAttempt for testing.
   */
  public function testLogAttempt(int $application_id, int $uid, string $url_tried, string $ats_detected, string $outcome, int $duration_ms, ?string $error_message, array $metadata = []): void {
    $this->logAttempt($application_id, $uid, $url_tried, $ats_detected, $outcome, $duration_ms, $error_message, $metadata);
  }

  /**
   * Stub: Playwright bridge always unavailable in unit tests.
   */
  protected function runPlaywrightBridge(array $app_data, string $apply_url, string $ats_platform, int $application_id, bool $dry_run = FALSE): ?array {
    return NULL;
  }

  /**
   * Stub: always returns a company ID.
   */
  protected function resolveCompanyId(int $job_id): ?int {
    return 999;
  }

  /**
   * Stub: returns the preset credential result.
   */
  protected function checkCredentials(int $uid, int $company_id): bool {
    return $this->credentialsResult;
  }

  /**
   * Stub: returns empty field map.
   */
  protected function buildFieldMapForJob(int $uid, int $job_id, string $ats_platform): array {
    return [];
  }

  /**
   * Stub: returns the apply_url as the login URL.
   */
  protected function getAtsLoginUrl(string $ats_platform, string $apply_url): string {
    return $apply_url;
  }

}

/**
 * Testable subclass: Playwright bridge throws a RuntimeException (TC-11).
 *
 * Used to verify that routeByPlatform catches bridge exceptions and returns
 * a structured manual_required result rather than propagating the exception.
 */
class TestableBrowserAutomationServiceWithThrowingBridge extends TestableBrowserAutomationService {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    \Drupal\Core\Database\Connection $database,
    \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory,
    \Drupal\job_hunter\Service\ApplyUrlResolverService $url_resolver,
    \Drupal\job_hunter\Service\ApplicationFormMapper $form_mapper,
    \Drupal\job_hunter\Service\JobSeekerService $job_seeker_service
  ) {
    parent::__construct($database, $logger_factory, $url_resolver, $form_mapper, $job_seeker_service, FALSE);
  }

  /**
   * Stub: always throws to simulate bridge failure (TC-11).
   */
  protected function runPlaywrightBridge(array $app_data, string $apply_url, string $ats_platform, int $application_id, bool $dry_run = FALSE): ?array {
    throw new \RuntimeException('Playwright bridge: proc_open failed — Node not available');
  }

}
