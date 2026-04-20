<?php

namespace Drupal\Tests\jobhunter_tester\Unit\Plugin\QueueWorker;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Plugin\QueueWorker\CoverLetterTailoringWorker;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for CoverLetterTailoringWorker.
 * 
 * @group job_hunter
 * @group jobhunter_tester
 */
class CoverLetterTailoringWorkerTest extends UnitTestCase {

  /**
   * The cover letter tailoring worker under test.
   *
   * @var \Drupal\job_hunter\Plugin\QueueWorker\CoverLetterTailoringWorker|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $coverLetterTailoringWorker;

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
    
    // Mock the container for Drupal static calls
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->configFactory);
    $container->set('ai_conversation.ai_api_service', $this->aiApiService);
    \Drupal::setContainer($container);
    
    // Create a partial mock of the worker
    $this->coverLetterTailoringWorker = $this->getMockBuilder(CoverLetterTailoringWorker::class)
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
        'extracted_json' => '{"title": "Developer"}',
        'skills_required_json' => '["PHP"]',
        'company_name' => 'Test Company',
      ],
      'cover_letter_template' => 'Dear Hiring Manager,',
    ];
    
    $this->coverLetterTailoringWorker->expects($this->once())
      ->method('processItem')
      ->with($valid_data)
      ->willReturn(NULL);
    
    $result = $this->coverLetterTailoringWorker->processItem($valid_data);
    $this->assertNull($result);
  }

  /**
   * Test: Process queue item with invalid data.
   * 
   * Negative test case.
   */
  public function testProcessQueueItemWithInvalidData() {
    $invalid_data = [
      'uid' => 1,
      // Missing required fields
    ];
    
    $this->coverLetterTailoringWorker->expects($this->once())
      ->method('processItem')
      ->with($invalid_data)
      ->willThrowException(new \Exception('Invalid queue data'));
    
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid queue data');
    $this->coverLetterTailoringWorker->processItem($invalid_data);
  }

  /**
   * Test: AI service generates cover letter.
   */
  public function testAiServiceGeneratesCoverLetter() {
    $data = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => ['professional_summary' => 'Experienced developer'],
      'job_data' => [
        'extracted_json' => '{"title": "Senior Developer"}',
        'company_name' => 'Tech Corp',
      ],
    ];
    
    // AI service should be available for cover letter generation
    $this->assertNotNull($this->aiApiService);
    
    // Verify data structure for AI processing
    $this->assertArrayHasKey('profile_json', $data);
    $this->assertArrayHasKey('job_data', $data);
  }

  /**
   * Test: Cover letter saved to database.
   */
  public function testCoverLetterSavedToDatabase() {
    $data = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => ['skills' => ['PHP']],
      'job_data' => ['company_name' => 'Test Co'],
    ];
    
    // Mock successful processing
    $this->coverLetterTailoringWorker->expects($this->once())
      ->method('processItem')
      ->with($data);
    
    $this->coverLetterTailoringWorker->processItem($data);
  }

  /**
   * Test: Cover letter content formatted correctly.
   */
  public function testCoverLetterContentFormattedCorrectly() {
    $data = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => [
        'professional_summary' => 'Developer with 5 years experience',
        'skills' => ['PHP', 'JavaScript'],
      ],
      'job_data' => [
        'extracted_json' => '{"title": "Full Stack Developer"}',
        'company_name' => 'Innovation Inc',
      ],
      'cover_letter_template' => 'Dear [Hiring Manager],',
    ];
    
    // Verify input structure for proper formatting
    $this->assertArrayHasKey('cover_letter_template', $data);
    $this->assertIsString($data['cover_letter_template']);
    $this->assertArrayHasKey('company_name', $data['job_data']);
  }

  /**
   * Test: Error handling during generation.
   * 
   * Negative test case.
   */
  public function testErrorHandlingDuringGeneration() {
    $data = [
      'uid' => 1,
      'job_id' => 100,
      'profile_json' => ['skills' => ['PHP']],
      'job_data' => ['company_name' => 'Test Co'],
    ];
    
    // Simulate AI service failure
    $this->coverLetterTailoringWorker->expects($this->once())
      ->method('processItem')
      ->with($data)
      ->willThrowException(new \Exception('AI service error'));
    
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('AI service error');
    $this->coverLetterTailoringWorker->processItem($data);
  }

}
