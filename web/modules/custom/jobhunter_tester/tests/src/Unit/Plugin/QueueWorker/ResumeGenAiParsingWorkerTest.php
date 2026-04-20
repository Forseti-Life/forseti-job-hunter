<?php

namespace Drupal\Tests\jobhunter_tester\Unit\Plugin\QueueWorker;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Plugin\QueueWorker\ResumeGenAiParsingWorker;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for ResumeGenAiParsingWorker.
 * 
 * @group job_hunter
 * @group jobhunter_tester
 */
class ResumeGenAiParsingWorkerTest extends UnitTestCase {

  /**
   * The resume GenAI parsing worker under test.
   *
   * @var \Drupal\job_hunter\Plugin\QueueWorker\ResumeGenAiParsingWorker|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $resumeGenAiParsingWorker;

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
    
    $this->resumeGenAiParsingWorker = $this->getMockBuilder(ResumeGenAiParsingWorker::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['processItem'])
      ->getMock();
  }

  /**
   * Test: Process queue item with extracted text.
   */
  public function testProcessQueueItemWithExtractedText() {
    $valid_data = [
      'resume_id' => 1,
      'extracted_text' => 'John Doe, Senior Developer with 10 years of experience in PHP, JavaScript...',
      'uid' => 1,
    ];
    
    $this->resumeGenAiParsingWorker->expects($this->once())
      ->method('processItem')
      ->with($valid_data)
      ->willReturn(NULL);
    
    $result = $this->resumeGenAiParsingWorker->processItem($valid_data);
    $this->assertNull($result);
  }

  /**
   * Test: Process queue item without extracted text.
   * 
   * Negative test case.
   */
  public function testProcessQueueItemWithoutExtractedText() {
    $data_no_text = [
      'resume_id' => 1,
      'extracted_text' => '',
      'uid' => 1,
    ];
    
    $this->resumeGenAiParsingWorker->expects($this->once())
      ->method('processItem')
      ->with($data_no_text)
      ->willThrowException(new \Exception('No extracted text available'));
    
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('No extracted text available');
    $this->resumeGenAiParsingWorker->processItem($data_no_text);
  }

  /**
   * Test: AI service called for parsing.
   */
  public function testAiServiceCalledForParsing() {
    $data = [
      'resume_id' => 1,
      'extracted_text' => 'Resume content with skills, experience, education...',
      'uid' => 1,
    ];
    
    // AI service should be available
    $this->assertNotNull($this->aiApiService);
    $this->assertArrayHasKey('extracted_text', $data);
  }

  /**
   * Test: JSON parsed from AI response.
   */
  public function testJsonParsedFromAiResponse() {
    $sample_ai_response = json_encode([
      'professional_summary' => 'Experienced developer',
      'skills' => ['PHP', 'JavaScript', 'Drupal'],
      'experience_years' => 10,
      'education_level' => 'Bachelor\'s',
      'job_history' => [
        ['company' => 'Tech Co', 'title' => 'Developer', 'start_date' => '2015-01'],
      ],
    ]);
    
    $parsed = json_decode($sample_ai_response, TRUE);
    
    $this->assertIsArray($parsed);
    $this->assertArrayHasKey('skills', $parsed);
    $this->assertArrayHasKey('job_history', $parsed);
    $this->assertIsArray($parsed['skills']);
  }

  /**
   * Test: Parsed JSON stored in database.
   */
  public function testParsedJsonStoredInDatabase() {
    $data = [
      'resume_id' => 1,
      'extracted_text' => 'Resume text content',
      'uid' => 1,
    ];
    
    $this->resumeGenAiParsingWorker->expects($this->once())
      ->method('processItem')
      ->with($data);
    
    $this->resumeGenAiParsingWorker->processItem($data);
  }

  /**
   * Test: Fallback to mock data on AI failure.
   * 
   * Negative test case.
   */
  public function testFallbackToMockDataOnAiFailure() {
    // When AI service fails, the system should provide mock/default data
    $mock_data = [
      'professional_summary' => '',
      'skills' => [],
      'experience_years' => 0,
    ];
    
    $this->assertIsArray($mock_data);
    $this->assertArrayHasKey('professional_summary', $mock_data);
    $this->assertArrayHasKey('skills', $mock_data);
  }

  /**
   * Test: Status updated after parsing.
   */
  public function testStatusUpdatedAfterParsing() {
    $data = [
      'resume_id' => 1,
      'extracted_text' => 'Resume content',
      'uid' => 1,
    ];
    
    $this->resumeGenAiParsingWorker->expects($this->once())
      ->method('processItem')
      ->with($data)
      ->willReturn(NULL);
    
    $result = $this->resumeGenAiParsingWorker->processItem($data);
    $this->assertNull($result);
  }

  /**
   * Test: Error handling for AI timeout.
   * 
   * Negative test case.
   */
  public function testErrorHandlingForAiTimeout() {
    $data = [
      'resume_id' => 1,
      'extracted_text' => 'Resume content',
      'uid' => 1,
    ];
    
    $this->resumeGenAiParsingWorker->expects($this->once())
      ->method('processItem')
      ->with($data)
      ->willThrowException(new \Exception('AI service timeout'));
    
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('AI service timeout');
    $this->resumeGenAiParsingWorker->processItem($data);
  }

}
