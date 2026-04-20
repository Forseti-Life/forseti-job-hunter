<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;
use GuzzleHttp\ClientInterface;

/**
 * Verifies that a resolved URL is the true application location for a requisition.
 */
class ApplicationLocationVerificationService {

  protected Connection $database;
  protected ApplyUrlResolverService $resolver;
  protected ClientInterface $httpClient;
  protected GenAiFallbackService $genAiFallbackService;

  public function __construct(
    Connection $database,
    ApplyUrlResolverService $resolver,
    ClientInterface $http_client,
    GenAiFallbackService $genai_fallback_service
  ) {
    $this->database = $database;
    $this->resolver = $resolver;
    $this->httpClient = $http_client;
    $this->genAiFallbackService = $genai_fallback_service;
  }

  /**
   * Execute verification checks and optional GenAI fallback.
   */
  public function verify(int $job_id, array $options = []): array {
    $defaults = [
      'genai_fallback' => TRUE,
      'min_description_overlap' => 0.15,
      'timeout' => 15,
    ];
    $options = array_merge($defaults, $options);

    $job = $this->loadJobData($job_id);
    if (empty($job)) {
      return [
        'job_id' => $job_id,
        'final_pass' => FALSE,
        'decision_mode' => 'fail',
        'error' => 'Job not found in jobhunter_job_requirements.',
        'checks' => [],
        'genai' => [
          'used' => FALSE,
          'available' => FALSE,
          'success' => FALSE,
          'confirmed' => FALSE,
          'confidence' => 'none',
          'response' => '',
          'evidence' => '',
        ],
      ];
    }

    $resolved = $this->resolver->resolve([
      'apply_options' => (string) ($job['apply_options'] ?? ''),
      'job_url' => (string) ($job['job_url'] ?? ''),
    ]);

    $resolved_url = (string) ($resolved['url'] ?? '');
    $candidate_url = $resolved_url !== '' ? $resolved_url : (string) ($job['job_url'] ?? '');

    $timeout = max(3, (int) ($options['timeout'] ?? 15));
    $fetched = $candidate_url !== '' ? $this->fetchPage($candidate_url, $timeout) : [
      'status_code' => 0,
      'effective_url' => '',
      'html' => '',
      'error' => 'No candidate URL available from resolver or original job URL.',
    ];

    $expected_req_id = $this->extractRequisitionId((string) ($job['job_url'] ?? ''));
    $job_title = $this->normalizeWhitespace((string) ($job['job_title'] ?? ''));
    $company_name = $this->normalizeWhitespace((string) ($job['company_name'] ?? ''));
    $job_description = $this->normalizeWhitespace((string) ($job['job_description'] ?? ''));

    $effective_url = (string) ($fetched['effective_url'] ?? $candidate_url);
    $html = (string) ($fetched['html'] ?? '');
    $page_text = $this->normalizeWhitespace($this->extractVisibleText($html));
    $page_title = $this->extractHtmlTitle($html);

    $req_match = $this->matchRequisition($expected_req_id, $effective_url, $page_text);
    $title_match = $job_title !== '' ? $this->containsLoose($page_text . ' ' . $page_title, $job_title) : FALSE;
    $company_match = $company_name !== '' ? $this->containsLoose($page_text . ' ' . $page_title, $company_name) : TRUE;
    $title_company_met = $title_match && $company_match;
    $apply_control = $this->hasApplyControl($html, $page_text);

    $min_overlap = (float) ($options['min_description_overlap'] ?? 0.15);
    if ($min_overlap < 0.01) {
      $min_overlap = 0.01;
    }
    if ($min_overlap > 0.8) {
      $min_overlap = 0.8;
    }

    $description_overlap = $this->descriptionOverlap($job_description, $page_text);
    $description_match = $job_description !== '' ? ($description_overlap >= $min_overlap) : FALSE;

    $is_non_aggregator = $effective_url !== '' ? !$this->resolver->isAggregator($effective_url) : FALSE;
    $status_code = (int) ($fetched['status_code'] ?? 0);
    $browser_used = !empty($fetched['browser_used']);
    $is_retrieved = $status_code >= 200 && $status_code < 400 && $html !== '';
    $is_resolved = $resolved_url !== '';

    $checks = [
      [
        'key' => 'resolved_destination_captured',
        'label' => 'Resolved destination URL captured',
        'required' => TRUE,
        'met' => $is_resolved,
        'evidence' => $resolved_url !== '' ? $resolved_url : 'Resolver did not return URL.',
      ],
      [
        'key' => 'page_retrieved_successfully',
        'label' => 'Destination page retrieved successfully',
        'required' => TRUE,
        'met' => $is_retrieved,
        'evidence' => 'HTTP ' . $status_code
          . ' | ' . ($browser_used ? 'stealth browser' : 'Guzzle HTTP')
          . ' | Effective URL: ' . $effective_url,
      ],
      [
        'key' => 'requisition_identity_match',
        'label' => 'Requisition identity matches target',
        'required' => TRUE,
        'met' => $req_match,
        'evidence' => $expected_req_id !== ''
          ? ('Expected req: ' . $expected_req_id)
          : 'No requisition ID parsed from original URL; cannot prove strict req-id match.',
      ],
      [
        'key' => 'title_company_match',
        'label' => 'Title and company context match',
        'required' => TRUE,
        'met' => $title_company_met,
        'evidence' => 'Title match: ' . ($title_match ? 'yes' : 'no') . ' | Company match: ' . ($company_name !== '' ? ($company_match ? 'yes' : 'no') : 'not-evaluated (company missing in source)'),
      ],
      [
        'key' => 'apply_control_present',
        'label' => 'Apply/submit control is present',
        'required' => TRUE,
        'met' => $apply_control,
        'evidence' => $apply_control ? 'Apply control phrase found in page content.' : 'No apply control phrase detected.',
      ],
      [
        'key' => 'destination_is_employer_or_ats',
        'label' => 'Final destination is employer/ATS (not aggregator)',
        'required' => TRUE,
        'met' => $is_non_aggregator,
        'evidence' => $is_non_aggregator ? 'Non-aggregator domain.' : 'Final URL appears to be an aggregator domain.',
      ],
      [
        'key' => 'description_similarity',
        'label' => 'Description overlaps original requisition text',
        'required' => FALSE,
        'met' => $description_match,
        'evidence' => 'Overlap ratio: ' . number_format($description_overlap, 3) . ' (threshold: ' . number_format($min_overlap, 3) . ')',
      ],
    ];

    $required_checks = array_values(array_filter($checks, static fn(array $c): bool => !empty($c['required'])));
    $required_met = count(array_filter($required_checks, static fn(array $c): bool => !empty($c['met'])));
    $required_total = count($required_checks);
    $hard_pass = $required_total > 0 && $required_met === $required_total;

    $all_met = count(array_filter($checks, static fn(array $c): bool => !empty($c['met'])));
    $all_total = count($checks);
    $heuristic_confidence = $this->scoreToConfidence($all_total > 0 ? ($all_met / $all_total) : 0.0);

    $genai = [
      'used' => FALSE,
      'available' => FALSE,
      'success' => FALSE,
      'confirmed' => FALSE,
      'confidence' => 'none',
      'response' => '',
      'evidence' => '',
    ];

    if (!$hard_pass && !empty($options['genai_fallback'])) {
      $genai = $this->runGenAiFallback([
        'job_id' => $job_id,
        'original_url' => (string) ($job['job_url'] ?? ''),
        'resolved_url' => $resolved_url,
        'effective_url' => $effective_url,
        'job_title' => $job_title,
        'company_name' => $company_name,
        'expected_req_id' => $expected_req_id,
        'checks' => $checks,
        'page_title' => $page_title,
        'page_text_excerpt' => mb_substr($page_text, 0, 6000),
      ]);
    }

    $final_pass = $hard_pass || (!empty($genai['success']) && !empty($genai['confirmed']));
    $final_mode = $hard_pass ? 'heuristic_pass' : ($final_pass ? 'genai_fallback_pass' : 'fail');

    return [
      'job_id' => $job_id,
      'job_title' => $job_title,
      'company_name' => $company_name,
      'expected_requisition_id' => $expected_req_id,
      'original_url' => (string) ($job['job_url'] ?? ''),
      'selected_apply_option' => (string) ($resolved['selected_option'] ?? ''),
      'resolved_url' => $resolved_url,
      'effective_url' => $effective_url,
      'ats_platform' => (string) ($resolved['ats_platform'] ?? ''),
      'resolver_confidence' => (string) ($resolved['confidence'] ?? ''),
      'checks' => $checks,
      'hard_pass' => $hard_pass,
      'heuristic_confidence' => $heuristic_confidence,
      'genai' => $genai,
      'final_pass' => $final_pass,
      'decision_mode' => $final_mode,
      'http_status' => $status_code,
      'browser_used' => $browser_used,
      'fetch_error' => (string) ($fetched['error'] ?? ''),
      'generated_at' => date('c'),
    ];
  }

  private function loadJobData(int $job_id): array {
    $schema = $this->database->schema();
    if (!$schema->tableExists('jobhunter_job_requirements')) {
      return [];
    }

    $fields = ['id'];
    foreach (['job_url', 'job_title', 'company_name', 'job_description', 'apply_options', 'extracted_json'] as $optional) {
      if ($schema->fieldExists('jobhunter_job_requirements', $optional)) {
        $fields[] = $optional;
      }
    }

    $row = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j', $fields)
      ->condition('j.id', $job_id)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc() ?: [];

    if (empty($row)) {
      return [];
    }

    if (!empty($row['extracted_json']) && is_string($row['extracted_json'])) {
      $decoded = json_decode($row['extracted_json'], TRUE);
      if (is_array($decoded)) {
        if (empty($row['job_title']) && !empty($decoded['position']['title'])) {
          $row['job_title'] = (string) $decoded['position']['title'];
        }
        if (empty($row['company_name']) && !empty($decoded['company']['name'])) {
          $row['company_name'] = (string) $decoded['company']['name'];
        }
        if (empty($row['job_description']) && !empty($decoded['description'])) {
          $row['job_description'] = (string) $decoded['description'];
        }
      }
    }

    return $row;
  }

  /**
   * Blocked HTTP status codes that trigger a browser fallback.
   */
  private const BROWSER_FALLBACK_CODES = [403, 429, 503];

  private function fetchPage(string $url, int $timeout): array {
    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => $timeout,
        'http_errors' => FALSE,
        'allow_redirects' => [
          'max' => 8,
          'strict' => TRUE,
          'referer' => TRUE,
          'track_redirects' => TRUE,
        ],
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
          'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
          'Accept-Language' => 'en-US,en;q=0.9',
        ],
      ]);

      $history = $response->getHeader('X-Guzzle-Redirect-History');
      $effective_url = !empty($history) ? (string) end($history) : $url;
      $status_code = $response->getStatusCode();
      $html = (string) $response->getBody();

      // If the plain HTTP GET was blocked, fall through to stealth browser.
      if (in_array($status_code, self::BROWSER_FALLBACK_CODES, TRUE) || ($status_code === 0 && $html === '')) {
        $browser = $this->fetchPageViaBrowser($url, $timeout);
        if ($browser['ok']) {
          return $browser;
        }
        // Return the browser result even on failure (preserves browser status)
        return $browser;
      }

      return [
        'status_code' => $status_code,
        'effective_url' => $effective_url,
        'html' => $html,
        'browser_used' => FALSE,
      ];
    }
    catch (\Throwable $e) {
      // Network-level failure — try the browser before giving up.
      $browser = $this->fetchPageViaBrowser($url, $timeout);
      if ($browser['ok']) {
        return $browser;
      }
      return [
        'status_code' => 0,
        'effective_url' => $url,
        'html' => '',
        'error' => $e->getMessage(),
        'browser_used' => FALSE,
      ];
    }
  }

  /**
   * Fetch a URL using a stealth Chromium browser (bypasses bot-detection WAFs).
   *
   * Spawns playwright/fetch-page.js as a subprocess — the same bridge pattern
   * used by BrowserAutomationService::runPlaywrightBridge(). The result is
   * written to a temp file to avoid pipe-buffer overflow on large HTML pages.
   *
   * Returns the same array shape as fetchPage() with an extra 'browser_used=true'.
   */
  private function fetchPageViaBrowser(string $url, int $timeout): array {
    $error_result = [
      'ok' => FALSE,
      'status_code' => 0,
      'effective_url' => $url,
      'html' => '',
      'browser_used' => TRUE,
      'error' => 'Browser fetch unavailable.',
    ];

    // Locate the playwright directory relative to the Drupal module.
    $playwright_dir = DRUPAL_ROOT . '/../web/modules/custom/job_hunter/playwright';
    if (!is_dir($playwright_dir)) {
      $playwright_dir = DRUPAL_ROOT . '/modules/custom/job_hunter/playwright';
    }
    $fetch_js = $playwright_dir . '/fetch-page.js';

    if (!file_exists($fetch_js)) {
      $error_result['error'] = 'fetch-page.js not found at: ' . $fetch_js;
      return $error_result;
    }

    // Browser timeout: stealth browser needs significantly more time than plain
    // HTTP (JS execution, Cloudflare challenge resolution, etc.). Use at least
    // 40 seconds regardless of the caller's timeout setting.
    $browser_timeout = max(40, $timeout + 20);

    // Write result to a temp file to prevent pipe-buffer overflow on large HTML.
    $output_file = tempnam(sys_get_temp_dir(), 'jh_fp_');
    @unlink($output_file); // Remove so Node creates it fresh.

    // Detect a system-installed Chrome/Chromium that www-data can access.
    // Playwright's own browser lives in the developer's home directory and is
    // not reachable by the web server process (www-data).
    $system_chrome = '';
    foreach (['/usr/bin/google-chrome', '/usr/bin/chromium-browser', '/usr/bin/chromium'] as $candidate) {
      if (is_executable($candidate)) {
        $system_chrome = $candidate;
        break;
      }
    }

    // Use the absolute path to node (not just "node") — www-data's PATH is
    // minimal and typically does not include /usr/bin unless added explicitly.
    $node_bin = is_executable('/usr/bin/node') ? '/usr/bin/node' : 'node';

    $cmd = $node_bin . ' ' . escapeshellarg($fetch_js)
      . ' --url=' . escapeshellarg($url)
      . ' --timeout=' . (int) $browser_timeout
      . ' --output-file=' . escapeshellarg($output_file)
      . ($system_chrome !== '' ? ' --executable-path=' . escapeshellarg($system_chrome) : '');

    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes, $playwright_dir);

    if (!is_resource($process)) {
      @unlink($output_file);
      $error_result['error'] = 'proc_open failed for browser fetch.';
      return $error_result;
    }

    fclose($pipes[0]);

    // Hard cap: browser_timeout + 15s grace for startup overhead.
    $hard_cap = $browser_timeout + 15;
    $start    = time();
    $stderr   = '';

    stream_set_blocking($pipes[2], FALSE);

    while (TRUE) {
      // Drain stderr for diagnostics only; result goes to output file.
      $chunk = fread($pipes[2], 8192);
      if ($chunk !== FALSE && $chunk !== '') {
        $stderr .= $chunk;
      }

      $status = proc_get_status($process);
      if (!$status['running']) {
        break;
      }

      if ((time() - $start) >= $hard_cap) {
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($output_file);
        $error_result['error'] = 'Browser fetch timed out after ' . $hard_cap . 's.';
        return $error_result;
      }

      usleep(500000); // 500ms poll — browser is slow, tight polling wastes CPU.
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    // Read result from temp file.
    $raw = file_exists($output_file) ? file_get_contents($output_file) : '';
    @unlink($output_file);

    if ($raw === '' || $raw === FALSE) {
      $error_result['error'] = 'Browser fetch: output file empty or missing. stderr: ' . substr($stderr, 0, 300);
      return $error_result;
    }

    $decoded = json_decode(trim($raw), TRUE);

    if (!is_array($decoded)) {
      $error_result['error'] = 'Browser fetch returned invalid JSON. stderr: ' . substr($stderr, 0, 300);
      return $error_result;
    }

    return [
      'ok'           => !empty($decoded['ok']),
      'status_code'  => (int) ($decoded['status_code'] ?? 0),
      'effective_url'=> (string) ($decoded['effective_url'] ?? $url),
      'html'         => (string) ($decoded['html'] ?? ''),
      'title'        => (string) ($decoded['title'] ?? ''),
      'browser_used' => TRUE,
      'error'        => (string) ($decoded['error'] ?? ''),
    ];
  }

  private function extractVisibleText(string $html): string {
    if ($html === '') {
      return '';
    }

    $without_scripts = preg_replace('/<script\\b[^>]*>(.*?)<\\/script>/is', ' ', $html) ?? $html;
    $without_styles = preg_replace('/<style\\b[^>]*>(.*?)<\\/style>/is', ' ', $without_scripts) ?? $without_scripts;
    $text = strip_tags($without_styles);
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

  private function extractHtmlTitle(string $html): string {
    if (preg_match('/<title[^>]*>(.*?)<\\/title>/is', $html, $m)) {
      return $this->normalizeWhitespace(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
  }

  private function extractRequisitionId(string $url): string {
    if ($url === '') {
      return '';
    }
    if (preg_match('/\\/jobs\\/(r-[0-9a-z\\-]+)/i', $url, $m)) {
      return strtoupper((string) $m[1]);
    }
    if (preg_match('/\\b(r-[0-9a-z\\-]{4,})\\b/i', $url, $m)) {
      return strtoupper((string) $m[1]);
    }
    return '';
  }

  private function matchRequisition(string $expected_req_id, string $effective_url, string $page_text): bool {
    if ($expected_req_id === '') {
      return FALSE;
    }

    $needle = strtolower($expected_req_id);
    if (str_contains(strtolower($effective_url), $needle)) {
      return TRUE;
    }
    return str_contains(strtolower($page_text), $needle);
  }

  private function containsLoose(string $haystack, string $needle): bool {
    $haystack_norm = $this->normalizeForMatch($haystack);
    $needle_norm = $this->normalizeForMatch($needle);

    if ($haystack_norm === '' || $needle_norm === '') {
      return FALSE;
    }

    if (str_contains($haystack_norm, $needle_norm)) {
      return TRUE;
    }

    $needle_tokens = array_values(array_filter(explode(' ', $needle_norm), static fn(string $token): bool => strlen($token) > 2));
    if (empty($needle_tokens)) {
      return FALSE;
    }

    $hits = 0;
    foreach ($needle_tokens as $token) {
      if (str_contains($haystack_norm, $token)) {
        $hits++;
      }
    }

    return ($hits / count($needle_tokens)) >= 0.75;
  }

  private function hasApplyControl(string $html, string $text): bool {
    $combined = strtolower($html . ' ' . $text);
    return (bool) preg_match('/\\b(apply( now)?|submit application|continue application|start application|easy apply)\\b/i', $combined);
  }

  private function descriptionOverlap(string $source, string $target): float {
    $source_tokens = $this->keywordSet($source);
    $target_tokens = $this->keywordSet($target);
    if (empty($source_tokens) || empty($target_tokens)) {
      return 0.0;
    }
    $intersection = array_intersect_key($source_tokens, $target_tokens);
    return count($intersection) / count($source_tokens);
  }

  private function keywordSet(string $text): array {
    $norm = strtolower($this->normalizeForMatch($text));
    if ($norm === '') {
      return [];
    }

    $parts = preg_split('/\\s+/', $norm) ?: [];
    $stop = [
      'the' => TRUE, 'and' => TRUE, 'for' => TRUE, 'with' => TRUE, 'this' => TRUE,
      'that' => TRUE, 'from' => TRUE, 'your' => TRUE, 'will' => TRUE, 'are' => TRUE,
      'our' => TRUE, 'you' => TRUE, 'job' => TRUE, 'role' => TRUE, 'position' => TRUE,
      'have' => TRUE, 'has' => TRUE, 'their' => TRUE, 'into' => TRUE, 'about' => TRUE,
      'can' => TRUE, 'not' => TRUE, 'all' => TRUE, 'any' => TRUE, 'use' => TRUE,
    ];

    $set = [];
    foreach ($parts as $token) {
      if (strlen($token) < 4 || isset($stop[$token])) {
        continue;
      }
      $set[$token] = TRUE;
      if (count($set) >= 250) {
        break;
      }
    }

    return $set;
  }

  private function normalizeForMatch(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[^\\p{L}\\p{N}\\s\\-]/u', ' ', $text) ?? $text;
    $text = str_replace('-', ' ', $text);
    return $this->normalizeWhitespace(mb_strtolower($text));
  }

  private function normalizeWhitespace(string $value): string {
    $value = preg_replace('/\\s+/u', ' ', $value) ?? $value;
    return trim($value);
  }

  private function scoreToConfidence(float $ratio): string {
    if ($ratio >= 0.85) {
      return 'high';
    }
    if ($ratio >= 0.60) {
      return 'medium';
    }
    return 'low';
  }

  private function runGenAiFallback(array $context): array {
    $checks_summary = [];
    foreach (($context['checks'] ?? []) as $check) {
      $checks_summary[] = [
        'key' => $check['key'] ?? '',
        'required' => !empty($check['required']),
        'met' => !empty($check['met']),
        'evidence' => $check['evidence'] ?? '',
      ];
    }

    return $this->genAiFallbackService->evaluateBooleanDecision(
      'application_location_validation',
      [
        'job_id' => $context['job_id'] ?? 0,
        'expected_req_id' => $context['expected_req_id'] ?? '',
        'job_title' => $context['job_title'] ?? '',
        'company_name' => $context['company_name'] ?? '',
        'original_url' => $context['original_url'] ?? '',
        'resolved_url' => $context['resolved_url'] ?? '',
        'effective_url' => $context['effective_url'] ?? '',
        'page_title' => $context['page_title'] ?? '',
        'checks' => $checks_summary,
        'page_text_excerpt' => $context['page_text_excerpt'] ?? '',
      ],
      'You are validating if a web page is the TRUE application location for a specific job requisition.',
      [
        'module' => 'job_hunter',
        'stage' => 'resolve_redirect_chain',
        'max_tokens' => 600,
        'decision_key' => 'is_true_application_location',
      ]
    );
  }

}
