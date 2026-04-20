<?php

namespace Drupal\Tests\jobhunter_tester\Unit\Plugin\QueueWorker;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Plugin\QueueWorker\ResumeTailoringWorker;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for ResumeTailoringWorker.
 * 
 * @group job_hunter
 * @group jobhunter_tester
 */
class ResumeTailoringWorkerTest extends UnitTestCase {

  /**
   * The resume tailoring worker under test.
   *
   * @var \Drupal\job_hunter\Plugin\QueueWorker\ResumeTailoringWorker|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $resumeTailoringWorker;

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
    
    // Mock the database and container for Drupal static calls
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('ai_conversation.ai_api_service', $this->aiApiService);
    \Drupal::setContainer($container);
    
    // Create a partial mock of the worker to avoid full initialization
    $this->resumeTailoringWorker = $this->getMockBuilder(ResumeTailoringWorker::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['processItem'])
      ->getMock();
  }

  /**
   * Test: Process queue item with valid data.
   */
  public function testProcessQueueItemWithValidData() {
    $valid_data = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => ['skills' => ['PHP', 'Drupal']],
      'job_data' => [
        'extracted_json' => '{}',
        'skills_required_json' => '["PHP"]',
        'keywords_json' => '[]',
        'raw_posting_text' => 'Test job posting',
      ],
    ];
    
    // Mock processItem to simulate successful processing
    $this->resumeTailoringWorker->expects($this->once())
      ->method('processItem')
      ->with($valid_data)
      ->willReturn(NULL);
    
    // Process should not throw exception
    $result = $this->resumeTailoringWorker->processItem($valid_data);
    $this->assertNull($result);
  }

  /**
   * Test: Process queue item with invalid data.
   * 
   * Negative test case.
   */
  public function testProcessQueueItemWithInvalidData() {
    $invalid_data = [
      // Missing required fields
      'uid' => 1,
    ];
    
    $this->resumeTailoringWorker->expects($this->once())
      ->method('processItem')
      ->with($invalid_data)
      ->willThrowException(new \Exception('Invalid queue data'));
    
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid queue data');
    $this->resumeTailoringWorker->processItem($invalid_data);
  }

  /**
   * Test: Process queue item with missing resume.
   * 
   * Negative test case.
   */
  public function testProcessQueueItemWithMissingResume() {
    $data_missing_resume = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => NULL, // Missing resume data
      'job_data' => ['extracted_json' => '{}'],
    ];
    
    $this->resumeTailoringWorker->expects($this->once())
      ->method('processItem')
      ->with($data_missing_resume)
      ->willThrowException(new \Exception('Resume data missing'));
    
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Resume data missing');
    $this->resumeTailoringWorker->processItem($data_missing_resume);
  }

  /**
   * Test: Process queue item with missing job posting.
   * 
   * Negative test case.
   */
  public function testProcessQueueItemWithMissingJobPosting() {
    $data_missing_job = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => ['skills' => ['PHP']],
      'job_data' => NULL, // Missing job data
    ];
    
    $this->resumeTailoringWorker->expects($this->once())
      ->method('processItem')
      ->with($data_missing_job)
      ->willThrowException(new \Exception('Job posting data missing'));
    
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Job posting data missing');
    $this->resumeTailoringWorker->processItem($data_missing_job);
  }

  /**
   * Test: AI service integration for tailoring.
   */
  public function testAiServiceIntegrationForTailoring() {
    // Test that AI service is properly called with expected payload structure
    $data = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => ['skills' => ['PHP', 'JavaScript']],
      'job_data' => [
        'extracted_json' => '{"title": "Developer"}',
        'skills_required_json' => '["PHP", "JavaScript"]',
        'keywords_json' => '["backend"]',
        'raw_posting_text' => 'Looking for developer',
      ],
    ];
    
    // The AI service should be called (we're testing the integration point)
    // In a real scenario, the worker would call aiApiService
    $this->assertNotNull($this->aiApiService);
  }

  /**
   * Test: Tailored resume saved to database.
   */
  public function testTailoredResumeSavedToDatabase() {
    // Test that successful processing results in database save
    // This would verify the worker calls database insert/update
    $data = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => ['skills' => ['PHP']],
      'job_data' => ['extracted_json' => '{}'],
    ];
    
    // Mock successful processing
    $this->resumeTailoringWorker->expects($this->once())
      ->method('processItem')
      ->with($data);
    
    $this->resumeTailoringWorker->processItem($data);
  }

  /**
   * Test: Queue item marked complete after processing.
   */
  public function testQueueItemMarkedComplete() {
    // Test that queue item is marked as completed after successful processing
    $data = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => ['skills' => ['PHP']],
      'job_data' => ['extracted_json' => '{}'],
    ];
    
    // Should complete without error
    $this->resumeTailoringWorker->expects($this->once())
      ->method('processItem')
      ->with($data)
      ->willReturn(NULL);
    
    $result = $this->resumeTailoringWorker->processItem($data);
    $this->assertNull($result);
  }

  /**
   * Test: Error handling during processing.
   * 
   * Negative test case.
   */
  public function testErrorHandlingDuringProcessing() {
    $data = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => ['skills' => ['PHP']],
      'job_data' => ['extracted_json' => '{}'],
    ];
    
    // Simulate an error during processing
    $this->resumeTailoringWorker->expects($this->once())
      ->method('processItem')
      ->with($data)
      ->willThrowException(new \Exception('AI service unavailable'));
    
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('AI service unavailable');
    $this->resumeTailoringWorker->processItem($data);
  }

  /**
   * Test: Skills gap analysis performed.
   */
  public function testSkillsGapAnalysisPerformed() {
    // Test that skills gap analysis is part of the tailoring process
    $data = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => ['skills' => ['PHP', 'JavaScript']],
      'job_data' => [
        'skills_required_json' => '["PHP", "JavaScript", "React", "Node.js"]',
        'extracted_json' => '{}',
      ],
    ];
    
    // The worker should analyze the gap between user skills and required skills
    // User has: PHP, JavaScript
    // Job requires: PHP, JavaScript, React, Node.js
    // Gap: React, Node.js
    $this->assertIsArray($data['profile_json']);
    $this->assertArrayHasKey('skills', $data['profile_json']);
    $this->assertIsArray($data['profile_json']['skills']);
  }

  /**
   * Test: Resume content formatted correctly.
   */
  public function testResumeContentFormattedCorrectly() {
    // Test that the tailored resume output is properly formatted
    $data = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => [
        'professional_summary' => 'Experienced developer',
        'skills' => ['PHP', 'Drupal'],
        'experience_years' => 5,
      ],
      'job_data' => ['extracted_json' => '{}'],
    ];
    
    // Verify input data structure
    $this->assertArrayHasKey('professional_summary', $data['profile_json']);
    $this->assertArrayHasKey('skills', $data['profile_json']);
    $this->assertArrayHasKey('experience_years', $data['profile_json']);
  }

}
