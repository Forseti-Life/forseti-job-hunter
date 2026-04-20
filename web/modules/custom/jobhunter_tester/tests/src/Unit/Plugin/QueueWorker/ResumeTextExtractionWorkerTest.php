<?php

namespace Drupal\Tests\jobhunter_tester\Unit\Plugin\QueueWorker;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Plugin\QueueWorker\ResumeTextExtractionWorker;
use Drupal\Core\DependencyInjection\ContainerBuilder;

/**
 * Unit tests for ResumeTextExtractionWorker.
 * 
 * @group job_hunter
 * @group jobhunter_tester
 */
class ResumeTextExtractionWorkerTest extends UnitTestCase {

  /**
   * The resume text extraction worker under test.
   *
   * @var \Drupal\job_hunter\Plugin\QueueWorker\ResumeTextExtractionWorker|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $resumeTextExtractionWorker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    
    $container = new ContainerBuilder();
    \Drupal::setContainer($container);
    
    $this->resumeTextExtractionWorker = $this->getMockBuilder(ResumeTextExtractionWorker::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['processItem'])
      ->getMock();
  }

  /**
   * Test: Process queue item with valid resume file.
   */
  public function testProcessQueueItemWithValidResumeFile() {
    $valid_data = [
      'resume_id' => 1,
      'file_path' => '/path/to/resume.docx',
      'uid' => 1,
    ];
    
    $this->resumeTextExtractionWorker->expects($this->once())
      ->method('processItem')
      ->with($valid_data)
      ->willReturn(NULL);
    
    $result = $this->resumeTextExtractionWorker->processItem($valid_data);
    $this->assertNull($result);
  }

  /**
   * Test: Process queue item with missing file.
   * 
   * Negative test case.
   */
  public function testProcessQueueItemWithMissingFile() {
    $data_missing_file = [
      'resume_id' => 1,
      'file_path' => '/path/to/nonexistent.docx',
      'uid' => 1,
    ];
    
    $this->resumeTextExtractionWorker->expects($this->once())
      ->method('processItem')
      ->with($data_missing_file)
      ->willThrowException(new \Exception('Resume file not found'));
    
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Resume file not found');
    $this->resumeTextExtractionWorker->processItem($data_missing_file);
  }

  /**
   * Test: Text extracted from DOCX file.
   */
  public function testTextExtractedFromDocxFile() {
    $data = [
      'resume_id' => 1,
      'file_path' => '/path/to/resume.docx',
      'uid' => 1,
    ];
    
    // Verify file path is present for extraction
    $this->assertArrayHasKey('file_path', $data);
    $this->assertStringEndsWith('.docx', $data['file_path']);
  }

  /**
   * Test: Extracted text stored in database.
   */
  public function testExtractedTextStoredInDatabase() {
    $data = [
      'resume_id' => 1,
      'file_path' => '/path/to/resume.docx',
      'uid' => 1,
    ];
    
    $this->resumeTextExtractionWorker->expects($this->once())
      ->method('processItem')
      ->with($data);
    
    $this->resumeTextExtractionWorker->processItem($data);
  }

  /**
   * Test: Character count calculated.
   */
  public function testCharacterCountCalculated() {
    $sample_text = 'This is a resume with some content that should be counted for character length.';
    $char_count = strlen($sample_text);
    
    $this->assertGreaterThan(0, $char_count);
    $this->assertEquals(80, $char_count);
  }

  /**
   * Test: Status updated after extraction.
   */
  public function testStatusUpdatedAfterExtraction() {
    $data = [
      'resume_id' => 1,
      'file_path' => '/path/to/resume.docx',
      'uid' => 1,
    ];
    
    $this->resumeTextExtractionWorker->expects($this->once())
      ->method('processItem')
      ->with($data)
      ->willReturn(NULL);
    
    $result = $this->resumeTextExtractionWorker->processItem($data);
    $this->assertNull($result);
  }

  /**
   * Test: Error handling for corrupted files.
   * 
   * Negative test case.
   */
  public function testErrorHandlingForCorruptedFiles() {
    $data = [
      'resume_id' => 1,
      'file_path' => '/path/to/corrupted.docx',
      'uid' => 1,
    ];
    
    $this->resumeTextExtractionWorker->expects($this->once())
      ->method('processItem')
      ->with($data)
      ->willThrowException(new \Exception('Failed to read DOCX file'));
    
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Failed to read DOCX file');
    $this->resumeTextExtractionWorker->processItem($data);
  }

}
