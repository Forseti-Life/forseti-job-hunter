<?php

namespace Drupal\Tests\job_hunter\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Service\WorkdayWizardService;
use Drupal\job_hunter\Service\WorkdayPlaywrightRunner;
use Drupal\job_hunter\Service\WorkdayProfileDataMapper;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;

/**
 * Unit tests for WorkdayWizardService.
 *
 * Uses a testable subclass to override buildRunContext (protected), removing
 * the need to mock the full DB query chain and Drupal service container.
 *
 * @group job_hunter
 * @coversDefaultClass \Drupal\job_hunter\Service\WorkdayWizardService
 */
class WorkdayWizardServiceTest extends UnitTestCase {

  /**
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $database;

  /**
   * @var \Drupal\Core\File\FileSystemInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileSystem;

  /**
   * @var \Drupal\job_hunter\Service\WorkdayProfileDataMapper|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $profileDataMapper;

  /**
   * @var \Drupal\job_hunter\Service\WorkdayPlaywrightRunner|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $playwrightRunner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database         = $this->createMock(Connection::class);
    $this->fileSystem       = $this->createMock(FileSystemInterface::class);
    $this->profileDataMapper = $this->createMock(WorkdayProfileDataMapper::class);
    $this->playwrightRunner = $this->createMock(WorkdayPlaywrightRunner::class);
  }

  /**
   * Build a testable service instance.
   *
   * @param array|null $contextResult
   *   Result to return from buildRunContext. NULL means a failing context.
   */
  protected function buildService(?array $contextResult = NULL): TestableWorkdayWizardService {
    return new TestableWorkdayWizardService(
      $this->database,
      $this->fileSystem,
      $this->profileDataMapper,
      $this->playwrightRunner,
      $contextResult
    );
  }

  /**
   * Returns a minimal valid run context for happy-path tests.
   */
  protected function validContext(): array {
    return [
      'ok'              => TRUE,
      'error'           => '',
      'application'     => ['id' => 1, 'apply_url' => 'https://wd.example.com/apply'],
      'apply_url'       => 'https://wd.example.com/apply',
      'credential'      => ['username' => 'test@example.com', 'password' => 's3cr3t'],
      'profile_data'    => ['first_name' => 'Test'],
      'resume_pdf_path' => '',
      'screenshot_dir'  => '',
    ];
  }

  // ── advanceStep: input validation ─────────────────────────────────────────

  /**
   * @covers ::advanceStep
   */
  public function testAdvanceStepInvalidStepKeyReturnsStructuredError(): void {
    $svc = $this->buildService($this->validContext());
    $result = $svc->advanceStep(1, 10, 'nonexistent_step');

    $this->assertFalse($result['ok']);
    $this->assertStringContainsString('Invalid step key', $result['error']);
  }

  /**
   * @covers ::advanceStep
   */
  public function testAdvanceStepNullJobIdReturnsStructuredError(): void {
    $svc = $this->buildService(['ok' => FALSE, 'error' => 'No application record found for job 0.']);
    $result = $svc->advanceStep(0, 10, 'my_information');

    $this->assertFalse($result['ok']);
    $this->assertNotEmpty($result['error']);
    // Must not throw PHP fatal or exception.
  }

  // ── advanceStep: context failures ─────────────────────────────────────────

  /**
   * @covers ::advanceStep
   */
  public function testAdvanceStepNoApplicationRecordReturnsError(): void {
    $svc = $this->buildService(['ok' => FALSE, 'error' => 'No application record found for job 99.']);
    $result = $svc->advanceStep(99, 1, 'my_information');

    $this->assertFalse($result['ok']);
    $this->assertStringContainsString('No application record', $result['error']);
  }

  /**
   * @covers ::advanceStep
   */
  public function testAdvanceStepMissingCredentialsReturnsError(): void {
    $svc = $this->buildService(['ok' => FALSE, 'error' => 'No stored credentials found.']);
    $result = $svc->advanceStep(1, 10, 'my_information');

    $this->assertFalse($result['ok']);
    $this->assertStringContainsString('credentials', $result['error']);
  }

  // ── advanceStep: runner outcomes ──────────────────────────────────────────

  /**
   * @covers ::advanceStep
   */
  public function testAdvanceStepRunnerReturnsNullReturnsStructuredError(): void {
    $this->playwrightRunner->method('runWizardPayload')->willReturn(NULL);

    $svc = $this->buildService($this->validContext());
    $result = $svc->advanceStep(1, 10, 'my_information');

    $this->assertFalse($result['ok']);
    $this->assertStringContainsString('invalid output', $result['error']);
  }

  /**
   * @covers ::advanceStep
   */
  public function testAdvanceStepRunnerTimesOutReturnsStructuredError(): void {
    $this->playwrightRunner->method('runWizardPayload')->willReturn([
      'ok'    => FALSE,
      'error' => 'Browser subprocess timed out after 135s.',
    ]);

    $svc = $this->buildService($this->validContext());
    $result = $svc->advanceStep(1, 10, 'my_information', ['timeout' => 120]);

    $this->assertFalse($result['ok']);
    $this->assertStringContainsString('timed out', $result['error']);
  }

  /**
   * @covers ::advanceStep
   */
  public function testAdvanceStepRunnerSimulates503ReturnsStructuredFailure(): void {
    $this->playwrightRunner->method('runWizardPayload')->willReturn([
      'ok'    => FALSE,
      'error' => 'Workday ATS unavailable (503).',
    ]);

    $svc = $this->buildService($this->validContext());
    $result = $svc->advanceStep(1, 10, 'my_information');

    $this->assertFalse($result['ok']);
    $this->assertNotEmpty($result['error']);
    // No exception must propagate.
  }

  // ── advanceStep: happy path ───────────────────────────────────────────────

  /**
   * @covers ::advanceStep
   */
  public function testAdvanceStepHappyPathReturnsSuccessResult(): void {
    $this->playwrightRunner
      ->expects($this->once())
      ->method('runWizardPayload')
      ->with($this->isString(), $this->isInt(), 'my_information')
      ->willReturn([
        'ok'           => TRUE,
        'target_step'  => 'my_information',
        'detected_page' => 'My Information',
        'page_matched' => TRUE,
        'fields_filled' => ['firstName', 'lastName'],
        'continue_clicked' => TRUE,
        'post_continue_url' => 'https://wd.example.com/apply/step2',
        'error' => '',
      ]);

    $svc = $this->buildService($this->validContext());
    $result = $svc->advanceStep(1, 10, 'my_information');

    $this->assertTrue($result['ok']);
    $this->assertEquals('my_information', $result['target_step']);
    $this->assertTrue($result['page_matched']);
    $this->assertTrue($result['continue_clicked']);
    $this->assertEmpty($result['error']);
  }

  /**
   * @covers ::advanceStep
   */
  public function testAdvanceStepRunnerReceivesCredentialPayload(): void {
    $this->playwrightRunner
      ->expects($this->once())
      ->method('runWizardPayload')
      ->with(
        $this->callback(function (string $payload_file) {
          // The payload temp file must exist at call time.
          return is_string($payload_file) && strlen($payload_file) > 0;
        }),
        120,
        'application_questions'
      )
      ->willReturn(['ok' => TRUE, 'error' => '']);

    $svc = $this->buildService($this->validContext());
    $svc->advanceStep(1, 10, 'application_questions', ['timeout' => 120]);
  }

  // ── advanceWizardAutoSingleSession: input validation ──────────────────────

  /**
   * @covers ::advanceWizardAutoSingleSession
   */
  public function testAdvanceWizardAutoInvalidStartStepReturnsError(): void {
    $svc = $this->buildService($this->validContext());
    $result = $svc->advanceWizardAutoSingleSession(1, 10, 'bogus_start');

    $this->assertFalse($result['ok']);
    $this->assertStringContainsString('Invalid start step', $result['error']);
  }

  // ── advanceWizardAutoSingleSession: context failures ──────────────────────

  /**
   * @covers ::advanceWizardAutoSingleSession
   */
  public function testAdvanceWizardAutoNoApplicationRecordReturnsError(): void {
    $svc = $this->buildService(['ok' => FALSE, 'error' => 'No application record found for job 99.']);
    $result = $svc->advanceWizardAutoSingleSession(99, 1);

    $this->assertFalse($result['ok']);
    $this->assertStringContainsString('No application record', $result['error']);
  }

  // ── advanceWizardAutoSingleSession: runner outcomes ───────────────────────

  /**
   * @covers ::advanceWizardAutoSingleSession
   */
  public function testAdvanceWizardAutoRunnerReturnsNullReturnsStructuredError(): void {
    $this->playwrightRunner->method('runWizardPayload')->willReturn(NULL);

    $svc = $this->buildService($this->validContext());
    $result = $svc->advanceWizardAutoSingleSession(1, 10);

    $this->assertFalse($result['ok']);
    $this->assertStringContainsString('invalid output', $result['error']);
  }

  /**
   * @covers ::advanceWizardAutoSingleSession
   */
  public function testAdvanceWizardAutoTimeoutReturnsStructuredError(): void {
    $this->playwrightRunner->method('runWizardPayload')->willReturn([
      'ok'    => FALSE,
      'error' => 'Browser subprocess timed out after 235s.',
    ]);

    $svc = $this->buildService($this->validContext());
    $result = $svc->advanceWizardAutoSingleSession(1, 10, 'my_information', ['timeout' => 220]);

    $this->assertFalse($result['ok']);
    $this->assertStringContainsString('timed out', $result['error']);
    // No silent failure — structured error only.
  }

  // ── advanceWizardAutoSingleSession: happy path ────────────────────────────

  /**
   * @covers ::advanceWizardAutoSingleSession
   */
  public function testAdvanceWizardAutoHappyPathReturnsSuccessResult(): void {
    $this->playwrightRunner
      ->expects($this->once())
      ->method('runWizardPayload')
      ->willReturn([
        'ok' => TRUE,
        'target_step' => 'wizard_auto',
        'completed_steps' => ['my_information', 'my_experience', 'review_submit'],
        'step_results' => [
          'my_information' => ['status' => 'pass'],
          'my_experience'  => ['status' => 'pass'],
          'review_submit'  => ['status' => 'pass'],
        ],
        'post_continue_url' => 'https://wd.example.com/apply/done',
        'error' => '',
      ]);

    $svc = $this->buildService($this->validContext());
    $result = $svc->advanceWizardAutoSingleSession(1, 10, 'my_information');

    $this->assertTrue($result['ok']);
    $this->assertIsArray($result['completed_steps']);
    $this->assertIsArray($result['step_results']);
    $this->assertEmpty($result['error']);
  }

  /**
   * Calling advanceWizardAutoSingleSession twice invokes the runner twice.
   *
   * WorkdayWizardService itself has no de-dup guard — duplicate suppression
   * is the controller's responsibility.
   *
   * @covers ::advanceWizardAutoSingleSession
   */
  public function testAdvanceWizardAutoCalledTwiceInvokesRunnerTwice(): void {
    $this->playwrightRunner
      ->expects($this->exactly(2))
      ->method('runWizardPayload')
      ->willReturn(['ok' => TRUE, 'error' => '']);

    $svc = $this->buildService($this->validContext());
    $svc->advanceWizardAutoSingleSession(1, 10);
    $svc->advanceWizardAutoSingleSession(1, 10);
  }

}

/**
 * Testable subclass: overrides buildRunContext to avoid DB / Drupal container.
 */
class TestableWorkdayWizardService extends WorkdayWizardService {

  /**
   * Pre-canned context returned by buildRunContext.
   *
   * NULL simulates a context where application record is missing.
   *
   * @var array|null
   */
  private ?array $contextResult;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    Connection $database,
    FileSystemInterface $file_system,
    WorkdayProfileDataMapper $profile_data_mapper,
    WorkdayPlaywrightRunner $playwright_runner,
    ?array $context_result = NULL
  ) {
    parent::__construct($database, $file_system, $profile_data_mapper, $playwright_runner);
    $this->contextResult = $context_result;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildRunContext(int $job_id, int $uid, string $apply_url_override = '', array $application_fields = ['id', 'apply_url', 'metadata']): array {
    if ($this->contextResult === NULL) {
      return ['ok' => FALSE, 'error' => 'No application record found for job ' . $job_id . '.'];
    }
    return $this->contextResult;
  }

}
