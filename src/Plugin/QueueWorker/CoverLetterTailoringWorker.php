<?php

namespace Drupal\job_hunter\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\job_hunter\Traits\JobHunterLoggerTrait;
use Drupal\job_hunter\Traits\QueueWorkerBaseTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cover Letter Tailoring GenAI queue worker.
 *
 * Processes cover letter generation via AWS Bedrock in the background.
 *
 * @QueueWorker(
 *   id = "job_hunter_cover_letter_tailoring",
 *   title = @Translation("Cover Letter Tailoring GenAI"),
 *   cron = {"time" = 120}
 * )
 */
class CoverLetterTailoringWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use JobHunterLoggerTrait;
  use QueueWorkerBaseTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The AI API service.
   *
   * @var \Drupal\ai_conversation\Service\AIApiService
   */
  protected $aiApiService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    $instance->aiApiService = $container->get('ai_conversation.ai_api_service');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $uid = $data['uid'];
    $job_id = $data['job_id'];
    $profile_json = $data['profile_json'];
    $job_data = $data['job_data'];
    $cover_letter_template = $data['cover_letter_template'] ?? '';

    // Get logging context (username, company, job_title)
    $context = $this->getLoggingContext($uid, $job_data);
    
    $this->logInfo('✉️ Queue: Starting cover letter generation for @username → "@title" at @company (job @job_id)', [
      '@username' => $context['username'],
      '@title' => $context['job_title'],
      '@company' => $context['company'],
      '@job_id' => $job_id,
    ]);
    
    // Log what sources we have available
    $has_template = !empty($cover_letter_template);
    $this->logInfo('📋 Cover letter sources: template=@template', [
      '@template' => $has_template ? 'YES (' . strlen($cover_letter_template) . ' chars)' : 'NO (will generate from scratch)',
    ]);
    
    // Parse job extracted data for payload
    $extracted = !empty($job_data['extracted_json']) ? json_decode($job_data['extracted_json'], TRUE) : [];

    $connection = \Drupal::database();

    try {
      // Update status to processing
      $this->updateDatabaseStatus($connection, 'jobhunter_cover_letters', $uid, $job_id, 'processing');

      // Parse job data
      $skills = !empty($job_data['skills_required_json']) ? json_decode($job_data['skills_required_json'], TRUE) : [];
      $keywords = !empty($job_data['keywords_json']) ? json_decode($job_data['keywords_json'], TRUE) : [];

      // Check if tailored resume is available (for better cover letter alignment)
      $tailored_resume = NULL;
      $tailored_record = $connection->select('jobhunter_tailored_resumes', 'tr')
        ->fields('tr', ['tailored_resume_json', 'tailoring_status'])
        ->condition('uid', $uid)
        ->condition('job_id', $job_id)
        ->execute()
        ->fetchObject();
        
      if ($tailored_record && $tailored_record->tailoring_status === 'completed' && !empty($tailored_record->tailored_resume_json)) {
        $tailored_resume = json_decode($tailored_record->tailored_resume_json, TRUE);
        $has_tailored = !empty($tailored_resume);
        $source_status = 'Sources: tailored_resume=' . ($has_tailored ? 'YES' : 'NO') . 
                        ', template=' . (!empty($cover_letter_template) ? 'YES' : 'NO') . 
                        ', profile=YES (fallback)';
        $this->logInfo('✅ Found completed tailored resume - ' . $source_status);
      }
      else {
        $source_status = 'Sources: tailored_resume=NO, template=' . 
                        (!empty($cover_letter_template) ? 'YES' : 'NO') . 
                        ', profile=YES (main source)';
        $this->logInfo('📝 No tailored resume available yet - ' . $source_status);
      }

      // Build the GenAI request payload
      $genai_payload = [
        'action' => 'generate_cover_letter',
        'job_requisition' => [
          'id' => (int) $job_id,
          'extracted_json' => $extracted,
          'skills_required_json' => $skills,
          'keywords_json' => $keywords,
          'raw_posting_text' => $job_data['raw_posting_text'] ?? '',
        ],
        'user_profile' => [
          'consolidated_profile_json' => $profile_json,
        ],
        'tailored_resume' => $tailored_resume,
        'cover_letter_template' => $cover_letter_template,
      ];

      // Call AWS Bedrock
      $cover_letter_result = $this->callGenAiCoverLetterService($genai_payload, $uid, $job_id);

      if (!$cover_letter_result || !isset($cover_letter_result['cover_letter_text'])) {
        // Suspend queue - GenAI call may have succeeded but JSON parsing failed
        throw new SuspendQueueException('Failed to generate cover letter from AI service. Check logs for JSON parsing errors. Clear cache if prompt needs adjustment.');
      }

      // Save the cover letter
      $fields = [
        'cover_letter_text' => $cover_letter_result['cover_letter_text'],
        'cover_letter_html' => $cover_letter_result['cover_letter_html'] ?? '',
        'cover_letter_json' => isset($cover_letter_result['cover_letter_json']) ? json_encode($cover_letter_result['cover_letter_json']) : NULL,
      ];
      
      $this->updateDatabaseStatus(
        $connection,
        'jobhunter_cover_letters',
        $uid,
        $job_id,
        'completed',
        $fields
      );

      $this->logInfo('✅ Queue: Cover letter generation complete for @username → "@title" at @company (job @job_id)', [
        '@username' => $context['username'],
        '@title' => $context['job_title'],
        '@company' => $context['company'],
        '@job_id' => $job_id,
      ]);

    }
    catch (\Exception $e) {
      // Use centralized exception handling
      $this->handleQueueException(
        $e,
        $connection,
        'jobhunter_cover_letters',
        $uid,
        $job_id,
        $context,
        'Cover letter generation'
      );
    }
  }

  /**
   * Call AWS Bedrock for cover letter generation via AIApiService.
   */
  private function callGenAiCoverLetterService(array $payload) {
    try {
      $uid = $payload['uid'] ?? 0;
      $job_id = $payload['job_requisition']['id'] ?? 0;
      
      $prompt = $this->buildCoverLetterPrompt($payload);
      
      // Log prompt size for debugging
      $tailored_resume_size = 0;
      if (!empty($payload['tailored_resume'])) {
        $tailored_resume_size = strlen(json_encode($payload['tailored_resume']));
      }
      
      $this->logInfo('🔍 Building cover letter prompt: job description size: @jd_size chars, profile size: @profile_size chars, tailored resume: @resume_size chars, template size: @template_size chars, total prompt: @total chars', [
        '@jd_size' => strlen($payload['job_requisition']['raw_posting_text'] ?? ''),
        '@profile_size' => strlen(json_encode($payload['user_profile']['consolidated_profile_json'] ?? [])),
        '@resume_size' => $tailored_resume_size,
        '@template_size' => strlen($payload['cover_letter_template'] ?? ''),
        '@total' => strlen($prompt),
      ]);

      $this->logInfo('🚀 Calling AWS Bedrock for cover letter generation via AIApiService');

      // Get max_tokens from centralized ai_conversation config
      $config = $this->configFactory->get('ai_conversation.settings');
      $max_tokens = $config->get('max_tokens_cover_letter') ?? 4000;

      // Use centralized AIApiService
      $result = $this->aiApiService->invokeModelDirect(
        $prompt,
        'job_hunter',
        'cover_letter_generation',
        [
          'uid' => $uid,
          'job_id' => $job_id,
          'queue' => 'job_hunter_cover_letter_tailoring',
          'item_key' => "cover_letter_{$uid}_{$job_id}",
        ],
        [
          'max_tokens' => $max_tokens,
        ]
      );

      if (!$result['success']) {
        $this->logError('AIApiService call failed: @error', ['@error' => $result['error'] ?? 'Unknown error']);
        return NULL;
      }
      
      $ai_response = $result['response'];
      $stop_reason = $result['stop_reason'];
      
      if ($stop_reason === 'max_tokens') {
        $this->logError('❌ Cover letter generation hit max_tokens limit! Response truncated. Consider reducing prompt size.');
        return NULL;
      }

      $this->logInfo('📝 AI response received for cover letter: @len chars, stop_reason: @reason', [
        '@len' => strlen($ai_response),
        '@reason' => $stop_reason,
      ]);

      // Parse the cover letter from response
      $cover_letter_data = $this->parseCoverLetterResponse($ai_response);
      
      if ($cover_letter_data) {
          return $cover_letter_data;
        }

      $this->logError('❌ No valid cover letter content in AI response');
      return NULL;

    }
    catch (\Exception $e) {
      $this->logError('AWS Bedrock cover letter generation error: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Build the prompt for cover letter generation.
   */
  private function buildCoverLetterPrompt(array $payload) {
    $job_data = $payload['job_requisition'];
    $profile = $payload['user_profile']['consolidated_profile_json'];
    $tailored_resume = $payload['tailored_resume'] ?? NULL;
    $template = $payload['cover_letter_template'] ?? '';
    
    // Extract profile highlights
    $profile_summary = is_array($profile) ? json_encode($profile, JSON_PRETTY_PRINT) : $profile;
    $job_description = $job_data['raw_posting_text'] ?? 'No job description provided';
    $job_title = $job_data['extracted_json']['job_title'] ?? 'the position';
    $company = $job_data['extracted_json']['company_name'] ?? 'your company';
    
    // If tailored resume is available, include it for alignment
    $tailored_resume_section = '';
    if ($tailored_resume && is_array($tailored_resume)) {
      $resume_json = json_encode($tailored_resume, JSON_PRETTY_PRINT);
      $tailored_resume_section = <<<RESUME

**TAILORED RESUME FOR THIS JOB** (PRIMARY SOURCE - use this as your main reference):
```
$resume_json
```

RESUME;
    }
    
    // Handle template section
    $template_section = '';
    $template_note = 'No template provided - create a professional cover letter from scratch using the tailored resume and job posting as primary sources.';
    if (!empty($template)) {
      $template_note = 'Use this as a style/structure guide while customizing content for this specific job.';
      $template_section = <<<TEMPLATE

**USER'S COVER LETTER TEMPLATE** (optional style guide):
```
$template
```

TEMPLATE;
    }

    return <<<PROMPT
You are an expert cover letter writer specializing in creating compelling, personalized cover letters for job applications.

**TASK**: Generate a professional cover letter tailored to this specific job opportunity.

**JOB POSTING**:
```
$job_description
```
{$tailored_resume_section}

**CANDIDATE PROFILE** (use only if tailored resume is not available):
```
$profile_summary
```
{$template_section}

**TEMPLATE NOTE**: {$template_note}

**INSTRUCTIONS**:
1. Write a compelling cover letter for {$job_title} at {$company}
2. Length: 3-4 paragraphs (300-400 words)
3. **PRIMARY SOURCES**: Use the tailored resume (if provided) as your main reference point, supplemented by the job posting requirements
4. Opening: Hook that connects candidate's passion to company/role - reference a key achievement from the resume
5. Body: Highlight 2-3 most relevant experiences/achievements that match job requirements - pull these directly from the tailored resume when available
6. Closing: Strong call to action expressing enthusiasm and next steps
7. Tone: Professional yet personable, confident without arrogance
8. Include specific examples and quantifiable achievements where possible (from resume)
9. Reference company values or mission if mentioned in job posting
10. Avoid generic statements - be specific to THIS job and THIS candidate
11. The cover letter should complement (not duplicate) the resume - expand on key points with context and enthusiasm
12. If no template is provided, create a professional letter from scratch - do NOT skip this step

**OUTPUT FORMAT**:
Return the cover letter as plain text, ready to use. Include:
- Opening salutation (use "Dear Hiring Manager" if no name available)
- 3-4 well-structured paragraphs
- Professional closing (Sincerely, [Name])

Do NOT include any JSON, markdown formatting, or code blocks. Just the plain text cover letter.

PROMPT;
  }

  /**
   * Parse cover letter from AI response.
   */
  private function parseCoverLetterResponse($response) {
    $cover_letter_text = trim($response);
    
    if (empty($cover_letter_text)) {
      $this->logError('❌ Empty cover letter response from AI');
      return NULL;
    }

    // Generate HTML version (simple paragraph wrapping)
    $html_version = '';
    $paragraphs = preg_split('/\n\n+/', $cover_letter_text);
    foreach ($paragraphs as $paragraph) {
      $paragraph = trim($paragraph);
      if (!empty($paragraph)) {
        $html_version .= '<p>' . nl2br(htmlspecialchars($paragraph)) . '</p>' . "\n";
      }
    }

    return [
      'cover_letter_text' => $cover_letter_text,
      'cover_letter_html' => $html_version,
      'cover_letter_json' => [
        'paragraphs' => $paragraphs,
        'word_count' => str_word_count($cover_letter_text),
        'char_count' => strlen($cover_letter_text),
      ],
    ];
  }

}
