<?php

namespace Drupal\job_hunter\Commands;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactory;
use Drupal\ai_conversation\Service\AIApiService;
use Drupal\file\Entity\File;
use Drupal\user\Entity\User;
use Drush\Commands\DrushCommands;
use Drupal\job_hunter\Plugin\QueueWorker\ResumeGenAiParsingWorker;
use ReflectionClass;
use ReflectionMethod;

/**
 * Direct resume parsing Drush command for testing.
 *
 * Bypasses the queue system to directly invoke resume parsing logic.
 * Useful for testing and debugging without waiting for queue processing.
 */
class ResumeParseDrushCommand extends DrushCommands {

  /**
   * The entity type manager.
   */
  protected EntityTypeManager $entityTypeManager;

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The config factory.
   */
  protected ConfigFactory $configFactory;

  /**
   * The AI API service.
   */
  protected AIApiService $aiApiService;

  /**
   * Constructor.
   */
  public function __construct(
    EntityTypeManager $entityTypeManager,
    Connection $database,
    ConfigFactory $configFactory,
    AIApiService $aiApiService
  ) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->configFactory = $configFactory;
    $this->aiApiService = $aiApiService;
  }

  /**
   * Parse a resume immediately (bypass queue).
   *
   * @command job-hunter:parse-resume
   * @aliases jh-parse,jh-parse-resume,job-parse
   * @argument file_id The file ID to parse
   * @option user-id Override the user ID (if different from file owner)
   * @option force Force re-parsing even if already parsed
   * @usage job-hunter:parse-resume 1
   * @usage job-hunter:parse-resume 5 --force
   * @usage job-hunter:parse-resume 3 --user-id=10
   */
  public function parseResume($file_id, $options = ['user-id' => NULL, 'force' => FALSE]) {
    $this->output()->writeln('📄 <info>Job Hunter Resume Parser - Direct Mode (Queue Bypass)</info>');
    $this->output()->writeln('🔄 <info>Loading file...</info>');

    // Load file
    $file = File::load($file_id);
    if (!$file) {
      $this->output()->writeln('<error>❌ File not found: ' . $file_id . '</error>');
      return DrushCommands::EXIT_FAILURE;
    }

    // Determine user ID
    $uid = $options['user-id'] ?? $file->getOwnerId();
    $user = User::load($uid);
    if (!$user) {
      $this->output()->writeln('<error>❌ User not found: ' . $uid . '</error>');
      return DrushCommands::EXIT_FAILURE;
    }

    $this->output()->writeln('📁 File: <info>' . $file->getFilename() . '</info> (ID: ' . $file_id . ')');
    $this->output()->writeln('👤 User: <info>' . $user->getAccountName() . '</info> (UID: ' . $uid . ')');

    try {
      // Step 1: Extract text
      $this->output()->writeln("\n🔄 <info>Step 1: Extracting text from file...</info>");
      $extracted_text = $this->extractTextFromFile($file);

      if (empty($extracted_text)) {
        $this->output()->writeln('<error>❌ No text extracted from file</error>');
        return DrushCommands::EXIT_FAILURE;
      }

      $char_count = strlen($extracted_text);
      $this->output()->writeln('✅ Text extracted: <info>' . number_format($char_count) . ' characters</info>');

      // Step 2: Get or create parsing record
      $this->output()->writeln("\n🔄 <info>Step 2: Checking parsing record...</info>");
      $result = $this->database->select('jobhunter_resume_parsed_data', 'rpd')
        ->fields('rpd', ['id', 'status'])
        ->condition('resume_file_id', $file_id)
        ->condition('uid', $uid)
        ->execute()
        ->fetchAssoc();

      $resume_record_id = $result['id'] ?? NULL;
      if ($resume_record_id && !$options['force']) {
        $current_status = $result['status'] ?? 'unknown';
        if ($current_status !== 'error') {
          $this->output()->writeln('⚠️  <comment>Record already exists with status: ' . $current_status . '</comment>');
          $this->output()->writeln('💡 Use <options=bold>--force</> to re-parse');
          return DrushCommands::EXIT_SUCCESS;
        }
      }

      if (!$resume_record_id) {
        // Create new record (in queued state initially)
        $this->database->insert('jobhunter_resume_parsed_data')
          ->fields([
            'uid' => $uid,
            'resume_file_id' => $file_id,
            'status' => 'processing',
            'created' => \Drupal::time()->getRequestTime(),
            'changed' => \Drupal::time()->getRequestTime(),
          ])
          ->execute();

        // Retrieve the ID
        $result = $this->database->select('jobhunter_resume_parsed_data', 'rpd')
          ->fields('rpd', ['id'])
          ->condition('resume_file_id', $file_id)
          ->condition('uid', $uid)
          ->execute()
          ->fetchAssoc();
        $resume_record_id = $result['id'];
      }

      $this->output()->writeln('✅ Record ID: <info>' . $resume_record_id . '</info>');

      // Step 3: Call the parsing functions directly
      $this->output()->writeln("\n🔄 <info>Step 3: Parsing with GenAI (2-pass approach)...</info>");
      $parsing_result = $this->parseResumeProdMode(
        $extracted_text,
        $file->getFilename(),
        $uid,
        $user->getAccountName()
      );

      $parsed_data = $parsing_result['parsed_data'];
      $raw_responses = $parsing_result['raw_responses'];

      $this->output()->writeln('✅ Parsing complete');
      $this->output()->writeln('<info>' . count($parsed_data['professional_experience'] ?? [])
        . ' job experiences found</info>');

      // Step 4: Store results
      $this->output()->writeln("\n🔄 <info>Step 4: Storing results...</info>");

      $core_raw_text = $this->formatRawResponses($raw_responses['core'] ?? []);

      $this->database->update('jobhunter_resume_parsed_data')
        ->fields([
          'parsed_data' => json_encode($parsed_data),
          'raw_genai_response_core' => $core_raw_text,
          'raw_genai_response_experience' => json_encode($raw_responses['experience'] ?? []),
          'status' => 'complete',
          'error_message' => NULL,
          'changed' => \Drupal::time()->getRequestTime(),
        ])
        ->condition('id', $resume_record_id)
        ->execute();

      $this->output()->writeln('✅ Results stored in database');

      // Step 5: Check for consolidation
      $this->output()->writeln("\n🔄 <info>Step 5: Running consolidation (if needed)...</info>");
      $pending_count = $this->database->select('jobhunter_resume_parsed_data', 'rpd')
        ->condition('uid', $uid)
        ->condition('status', ['queued', 'processing'], 'IN')
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($pending_count == 0) {
        $this->output()->writeln('✅ All files complete. Running consolidation...');
        $this->consolidateAllParsedData($uid);
        $this->output()->writeln('✅ Consolidation complete');
      } else {
        $this->output()->writeln('⏳ <comment>' . $pending_count . ' files still pending, deferring consolidation</comment>');
      }

      $this->output()->writeln("\n✅ <fg=green>✓ Resume parsing complete!</fg=green>");
      return DrushCommands::EXIT_SUCCESS;

    } catch (\Exception $e) {
      $this->output()->writeln('<error>❌ Error: ' . $e->getMessage() . '</error>');
      $this->logger()->error('Resume parsing error: ' . $e->getMessage());
      return DrushCommands::EXIT_FAILURE;
    }
  }

  /**
   * Extract text from resume file.
   */
  protected function extractTextFromFile(File $file): string {
    $file_path = \Drupal::service('file_system')->realpath($file->getFileUri());
    $extension = pathinfo($file->getFilename(), PATHINFO_EXTENSION);

    switch (strtolower($extension)) {
      case 'txt':
        return file_get_contents($file_path) ?: '';

      case 'pdf':
        if (shell_exec('which pdftotext')) {
          $output = shell_exec("timeout 30s pdftotext " . escapeshellarg($file_path) . " -");
          return $output ?: '';
        }
        break;

      case 'docx':
        if (shell_exec('which docx2txt')) {
          $output = shell_exec("timeout 20s docx2txt " . escapeshellarg($file_path) . " -");
          return $output ?: '';
        }
        break;

      case 'doc':
        if (shell_exec('which antiword')) {
          $output = shell_exec("timeout 20s antiword " . escapeshellarg($file_path));
          return $output ?: '';
        }
        break;
    }

    return '';
  }

  /**
   * Parse resume (2-pass approach: core + experience).
   *
   * This method is extracted from ResumeGenAiParsingWorker via reflection
   * to call it directly without queue processing.
   */
  protected function parseResumeProdMode($extracted_text, $filename, $uid, $username): array {
    $worker = $this->createWorkerInstance();

    // Use reflection to call the private method
    $reflection = new ReflectionClass($worker);
    $method = $reflection->getMethod('parseResumeProdMode');
    $method->setAccessible(TRUE);

    return $method->invoke($worker, $extracted_text, $filename, $uid);
  }

  /**
   * Format raw responses.
   */
  protected function formatRawResponses($raw_responses): string {
    if (empty($raw_responses)) {
      return '';
    }

    $formatted = [];
    foreach ($raw_responses as $chunk_name => $response) {
      $formatted[] = "=== $chunk_name ===\n" . $response;
    }

    return implode("\n\n", $formatted);
  }

  /**
   * Consolidate parsed data.
   */
  protected function consolidateAllParsedData($uid): void {
    $worker = $this->createWorkerInstance();

    // Use reflection to call the trait method
    $reflection = new ReflectionClass($worker);
    $method = $reflection->getMethod('consolidateAllParsedData');
    $method->setAccessible(TRUE);

    $method->invoke($worker, $uid);
  }

  /**
   * Create a queue worker instance with injected dependencies.
   */
  protected function createWorkerInstance(): ResumeGenAiParsingWorker {
    $worker = new ResumeGenAiParsingWorker([], 'job_hunter_genai_parsing', []);
    $worker->configFactory = $this->configFactory;
    $worker->aiApiService = $this->aiApiService;

    return $worker;
  }

}
