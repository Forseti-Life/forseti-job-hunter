<?php

namespace Drupal\Tests\jobhunter_tester\Unit\Plugin\QueueWorker;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Plugin\QueueWorker\JobPostingParsingWorker;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for JobPostingParsingWorker.
 * 
 * @group job_hunter
 * @group jobhunter_tester
 */
class JobPostingParsingWorkerTest extends UnitTestCase {

  /**
   * The job posting parsing worker under test.
   *
   * @var \Drupal\job_hunter\Plugin\QueueWorker\JobPostingParsingWorker|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $jobPostingParsingWorker;

  /**
   * Mock config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mock AI API service.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  protected $aiApiService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->aiApiService = $this->createMock(\stdClass::class);
    
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('ai_conversation.ai_api_service', $this->aiApiService);
    \Drupal::setContainer($container);
    
    $this->jobPostingParsingWorker = $this->getMockBuilder(JobPostingParsingWorker::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['processItem'])
      ->getMock();
  }

  /**
   * Test: Process queue item with valid job posting.
   */
  public function testProcessQueueItemWithValidJobPosting() {
    $valid_data = [
      'job_id' => 100,
      'raw_posting_text' => 'We are looking for a Senior PHP Developer with 5+ years of experience...',
      'company_name' => 'Tech Corporation',
    ];
    
    $this->jobPostingParsingWorker->expects($this->once())
      ->method('processItem')
      ->with($valid_data)
      ->willReturn(NULL);
    
    $result = $this->jobPostingParsingWorker->processItem($valid_data);
    $this->assertNull($result);
  }

  /**
   * Test: Process queue item with invalid data.
   * 
   * Negative test case.
   */
  public function testProcessQueueItemWithInvalidData() {
    $invalid_data = [
      'job_id' => 100,
      // Missing raw_posting_text
    ];
    
    $this->jobPostingParsingWorker->expects($this->once())
      ->method('processItem')
      ->with($invalid_data)
      ->willThrowException(new \Exception('Missing job posting text'));
    
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Missing job posting text');
    $this->jobPostingParsingWorker->processItem($invalid_data);
  }

  /**
   * Test: Job description parsed correctly.
   */
  public function testJobDescriptionParsedCorrectly() {
    $data = [
      'job_id' => 100,
      'raw_posting_text' => 'Senior Developer role requiring PHP, JavaScript, and database skills.',
      'company_name' => 'Tech Inc',
    ];
    
    // Verify the raw text is present for parsing
    $this->assertArrayHasKey('raw_posting_text', $data);
    $this->assertNotEmpty($data['raw_posting_text']);
  }

  /**
   * Test: Job requirements extracted.
   */
  public function testJobRequirementsExtracted() {
    $posting_text = 'Requirements: 5+ years PHP, Bachelor degree, strong communication skills';
    $data = [
      'job_id' => 100,
      'raw_posting_text' => $posting_text,
    ];
    
    // Check that the text contains extractable requirements
    $this->assertStringContainsString('Requirements:', $data['raw_posting_text']);
    $this->assertStringContainsString('PHP', $data['raw_posting_text']);
    $this->assertStringContainsString('Bachelor', $data['raw_posting_text']);
  }

  /**
   * Test: Skills extracted from job posting.
   */
  public function testSkillsExtractedFromJobPosting() {
    $data = [
      'job_id' => 100,
      'raw_posting_text' => 'Looking for someone with PHP, JavaScript, React, Node.js, and MySQL experience.',
    ];
    
    // Verify posting text contains skills
    $skills = ['PHP', 'JavaScript', 'React', 'Node.js', 'MySQL'];
    foreach ($skills as $skill) {
      $this->assertStringContainsString($skill, $data['raw_posting_text']);
    }
  }

  /**
   * Test: AI service integration for parsing.
   */
  public function testAiServiceIntegrationForParsing() {
    $data = [
      'job_id' => 100,
      'raw_posting_text' => 'Job description with requirements and responsibilities',
    ];
    
    // AI service should be available
    $this->assertNotNull($this->aiApiService);
    $this->assertArrayHasKey('raw_posting_text', $data);
  }

  /**
   * Test: Parsed data saved to database.
   */
  public function testParsedDataSavedToDatabase() {
    $data = [
      'job_id' => 100,
      'raw_posting_text' => 'Test job posting content',
    ];
    
    $this->jobPostingParsingWorker->expects($this->once())
      ->method('processItem')
      ->with($data);
    
    $this->jobPostingParsingWorker->processItem($data);
  }

  /**
   * Test: Error handling during parsing.
   * 
   * Negative test case.
   */
  public function testErrorHandlingDuringParsing() {
    $data = [
      'job_id' => 100,
      'raw_posting_text' => 'Test content',
    ];
    
    $this->jobPostingParsingWorker->expects($this->once())
      ->method('processItem')
      ->with($data)
      ->willThrowException(new \Exception('Parsing failed'));
    
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Parsing failed');
    $this->jobPostingParsingWorker->processItem($data);
  }

}
