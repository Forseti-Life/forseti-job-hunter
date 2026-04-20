<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Resolves the canonical apply URL from a job's apply_options and job_url.
 *
 * Aggregator sites (Teal, Jobright, etc.) don't host the actual application
 * form — they redirect to the employer's ATS. This service follows those
 * redirects to find the real URL and classify the ATS platform.
 */
class ApplyUrlResolverService {

  /**
   * Known aggregator hostnames that don't host the actual application.
   */
  const AGGREGATORS = [
    'tealhq.com',
    'jobright.ai',
    'sidehustles.com',
    'trabajo.org',
    'us.trabajo.org',
    'simplyhired.com',
    'salary.com',
    'ziprecruiter.com',
    'careerbuilder.com',
    'monster.com',
    'glassdoor.com',
    'indeed.com',
    'linkedin.com',
    'dice.com',
    'jobot.com',
    'jooble.org',
    'getwork.com',
    'lensa.com',
    'talent.com',
    'thejobnetwork.com',
    'jobilize.com',
    'learn4good.com',
    'jobs.google.com',
    'jobcase.com',
    'snagajob.com',
    'builtinnyc.com',
    'builtinboston.com',
    'builtinsf.com',
    'builtin.com',
    'ladders.com',
    'theladders.com',
    'flexjobs.com',
    'remoteok.com',
    'weworkremotely.com',
    'remote.co',
    'jobgether.com',
    'wayup.com',
    'idealist.org',
    'clearancejobs.com',
    'federalgovernmentjobs.us',
    'usajobsmarket.com',
  ];

  /**
   * ATS platform patterns — hostname → platform key.
   */
  const ATS_PLATFORMS = [
    // Direct ATS domains.
    'wd1.myworkdayjobs.com'   => 'workday',
    'wd3.myworkdayjobs.com'   => 'workday',
    'wd5.myworkdayjobs.com'   => 'workday',
    'myworkdayjobs.com'       => 'workday',
    'workday.com'             => 'workday',
    'greenhouse.io'           => 'greenhouse',
    'boards.greenhouse.io'    => 'greenhouse',
    'lever.co'                => 'lever',
    'jobs.lever.co'           => 'lever',
    'taleo.net'               => 'taleo',
    'tbe.taleo.net'           => 'taleo',
    'icims.com'               => 'icims',
    'recruiting.ultipro.com'  => 'ultipro',
    'paylocity.com'           => 'paylocity',
    'bamboohr.com'            => 'bamboohr',
    'jobvite.com'             => 'jobvite',
    'jobs.jobvite.com'        => 'jobvite',
    'successfactors.com'      => 'successfactors',
    'successfactors.eu'       => 'successfactors',
    'smartrecruiters.com'     => 'smartrecruiters',
    'jobs.smartrecruiters.com' => 'smartrecruiters',
    'apply.workable.com'      => 'workable',
    'workable.com'            => 'workable',
    'breezy.hr'               => 'breezy',
    'app.breezy.hr'           => 'breezy',
    'recruiting.paylocity.com' => 'paylocity',
    'careers.peoplesoft.com'  => 'peoplesoft',
    'usajobs.gov'             => 'usajobs',
    'apply.usajobs.gov'       => 'usajobs',
    'careers-page.com'        => 'careers_page',
    'ashbyhq.com'             => 'ashby',
    'jobs.ashbyhq.com'        => 'ashby',
    'rippling.com'            => 'rippling',
    'app.rippling.com'        => 'rippling',
    'apply.ycombinator.com'   => 'y_combinator',
    'wellfound.com'           => 'wellfound',
    'angel.co'                => 'wellfound',
  ];

  /**
   * Preferred apply_option titles (higher index = lower preference).
   * We prefer direct ATS links over aggregators.
   */
  const PREFERRED_OPTION_TITLES = [
    'usajobs'        => 0,
    'company website' => 1,
    'official site'  => 1,
    'apply direct'   => 1,
    'apply here'     => 1,
    'workday'        => 2,
    'greenhouse'     => 2,
    'lever'          => 2,
    'taleo'          => 2,
    'icims'          => 2,
    'bamboohr'       => 2,
    'jobvite'        => 2,
    'smartrecruiters' => 2,
    'linkedin'       => 5,
    'indeed'         => 5,
    'ziprecruiter'   => 5,
    'dice'           => 5,
    'glassdoor'      => 5,
    'teal'           => 7,
    'jobright'       => 7,
    'sidehustles'    => 7,
    'trabajo'        => 7,
  ];

  /**
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructor.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Resolve the best apply URL for a job.
   *
   * Picks the best apply_option, follows up to 3 redirects via HEAD request,
   * and detects the ATS platform.
   *
   * @param array $job
   *   Row from jobhunter_job_requirements (must have apply_options, job_url).
   *
   * @return array{
   *   url: string,
   *   ats_platform: string,
   *   selected_option: string,
   *   resolution_steps: array,
   *   confidence: string,
   * }
   */
  public function resolve(array $job): array {
    $steps = [];

    // Parse apply_options JSON.
    $apply_options = [];
    if (!empty($job['apply_options'])) {
      $decoded = json_decode($job['apply_options'], TRUE);
      if (is_array($decoded)) {
        $apply_options = $decoded;
      }
    }

    // Pick the best option based on known ATS/aggregator preference.
    $best_option = $this->selectBestApplyOption($apply_options, $steps);

    // Candidate URL (best option or job_url fallback).
    $candidate_url = $best_option['link'] ?? $job['job_url'] ?? '';
    $selected_option_title = $best_option['title'] ?? 'job_url_fallback';

    if (empty($candidate_url)) {
      return [
        'url'              => '',
        'ats_platform'     => 'unknown',
        'selected_option'  => $selected_option_title,
        'resolution_steps' => $steps,
        'confidence'       => 'none',
      ];
    }

    // Strip UTM params before resolving.
    $clean_url = $this->stripUtmParams($candidate_url);
    $steps[] = ['action' => 'selected_url', 'url' => $clean_url, 'option' => $selected_option_title];

    // Detect ATS from the candidate URL (before following redirects).
    $platform = $this->detectPlatformFromUrl($clean_url);
    if ($platform !== 'unknown' && $platform !== 'aggregator') {
      // URL is already a direct ATS — no need to follow redirects.
      $steps[] = ['action' => 'direct_ats_detected', 'platform' => $platform];
      return [
        'url'              => $clean_url,
        'ats_platform'     => $platform,
        'selected_option'  => $selected_option_title,
        'resolution_steps' => $steps,
        'confidence'       => 'high',
      ];
    }

    // Follow redirects via HEAD request (max 3 hops, 5s timeout).
    $resolved = $this->followRedirects($clean_url, $steps, 3);

    $final_platform = $this->detectPlatformFromUrl($resolved);
    if ($final_platform === 'unknown') {
      // Try to detect from page content (future: HEAD response body hints).
      $final_platform = 'custom';
    }

    $confidence = ($final_platform !== 'custom' && $final_platform !== 'aggregator') ? 'high' : 'low';

    return [
      'url'              => $resolved,
      'ats_platform'     => $final_platform,
      'selected_option'  => $selected_option_title,
      'resolution_steps' => $steps,
      'confidence'       => $confidence,
    ];
  }

  /**
   * Classify a URL without making HTTP requests.
   */
  public function classifyUrl(string $url): string {
    return $this->detectPlatformFromUrl($url);
  }

  /**
   * Returns TRUE if the URL is a known job aggregator.
   */
  public function isAggregator(string $url): bool {
    $host = $this->extractHost($url);
    foreach (self::AGGREGATORS as $agg) {
      if ($host === $agg || str_ends_with($host, '.' . $agg)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Select the best apply_option from the list.
   *
   * Direct ATS links are preferred over aggregators.
   */
  protected function selectBestApplyOption(array $options, array &$steps): array {
    if (empty($options)) {
      return [];
    }

    $scored = [];
    foreach ($options as $option) {
      $title = strtolower($option['title'] ?? '');
      $link  = $option['link'] ?? '';
      $host  = $this->extractHost($link);

      // Score based on detected platform.
      $platform = $this->detectPlatformFromUrl($link);

      if ($platform !== 'unknown' && $platform !== 'aggregator' && $platform !== 'custom') {
        $score = 0; // Direct ATS — best.
      } elseif ($this->isAggregator($link)) {
        $score = 10; // Known aggregator — worst.
      } else {
        $score = 5; // Unknown — medium.
      }

      // Boost based on title keyword matching.
      foreach (self::PREFERRED_OPTION_TITLES as $keyword => $penalty) {
        if (str_contains($title, $keyword)) {
          $score += $penalty;
          break;
        }
      }

      $scored[] = ['option' => $option, 'score' => $score, 'platform' => $platform];
    }

    usort($scored, fn($a, $b) => $a['score'] <=> $b['score']);
    $steps[] = ['action' => 'ranked_options', 'ranking' => array_map(fn($s) => [
      'title' => $s['option']['title'] ?? '',
      'score' => $s['score'],
      'platform' => $s['platform'],
    ], $scored)];

    return $scored[0]['option'];
  }

  /**
   * Detect ATS platform from a URL string.
   */
  public function detectPlatformFromUrl(string $url): string {
    $host = $this->extractHost($url);

    // Direct match.
    if (isset(self::ATS_PLATFORMS[$host])) {
      return self::ATS_PLATFORMS[$host];
    }

    // Suffix match (subdomain.ats.com → ats.com).
    foreach (self::ATS_PLATFORMS as $pattern => $platform) {
      if (str_ends_with($host, '.' . $pattern) || $host === $pattern) {
        return $platform;
      }
    }

    // Aggregator check.
    if ($this->isAggregator($url)) {
      return 'aggregator';
    }

    // Path-based detection (some ATS use subpaths).
    if (preg_match('/\/jobs\/(view|detail|apply)\//', $url)) {
      return 'custom';
    }
    if (str_contains($url, 'taleo')) {
      return 'taleo';
    }
    if (str_contains($url, 'workday')) {
      return 'workday';
    }
    if (str_contains($url, 'greenhouse')) {
      return 'greenhouse';
    }
    if (str_contains($url, 'lever.co')) {
      return 'lever';
    }
    if (str_contains($url, 'icims')) {
      return 'icims';
    }

    return 'unknown';
  }

  /**
   * Follow HTTP redirects up to $max_hops times.
   *
   * Returns the final resolved URL.
   */
  protected function followRedirects(string $url, array &$steps, int $max_hops): string {
    $current = $url;

    for ($i = 0; $i < $max_hops; $i++) {
      $context = stream_context_create([
        'http' => [
          'method'          => 'HEAD',
          'follow_location' => 0,
          'timeout'         => 5,
          'ignore_errors'   => TRUE,
          'header'          => "User-Agent: Mozilla/5.0 (compatible; ForsetiBot/1.0)\r\n",
        ],
        'ssl' => [
          'verify_peer'      => TRUE,
          'verify_peer_name' => TRUE,
        ],
      ]);

      try {
        $headers = @get_headers($current, TRUE, $context);
      }
      catch (\Throwable $e) {
        $steps[] = ['action' => 'redirect_error', 'url' => $current, 'error' => $e->getMessage()];
        break;
      }

      if (!$headers) {
        $steps[] = ['action' => 'no_headers', 'url' => $current];
        break;
      }

      $status_line = is_array($headers[0]) ? end($headers[0]) : $headers[0];
      preg_match('/\s(\d{3})\s/', $status_line, $match);
      $status_code = (int) ($match[1] ?? 0);

      $steps[] = ['action' => 'head_request', 'url' => $current, 'status' => $status_code];

      // 3xx redirect — follow Location header.
      if ($status_code >= 300 && $status_code < 400 && isset($headers['Location'])) {
        $location = is_array($headers['Location']) ? end($headers['Location']) : $headers['Location'];
        $resolved = $this->resolveRelativeUrl($location, $current);
        $steps[] = ['action' => 'following_redirect', 'from' => $current, 'to' => $resolved];
        $current = $resolved;
        continue;
      }

      // Final destination reached (2xx or non-redirect).
      break;
    }

    return $current;
  }

  /**
   * Strip UTM tracking parameters from a URL.
   */
  protected function stripUtmParams(string $url): string {
    $parsed = parse_url($url);
    if (!isset($parsed['query'])) {
      return $url;
    }

    parse_str($parsed['query'], $params);
    $cleaned = array_filter($params, fn($key) => !str_starts_with($key, 'utm_'), ARRAY_FILTER_USE_KEY);

    $base = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
    if (isset($parsed['port'])) {
      $base .= ':' . $parsed['port'];
    }
    $base .= ($parsed['path'] ?? '');

    if (!empty($cleaned)) {
      $base .= '?' . http_build_query($cleaned);
    }
    if (isset($parsed['fragment'])) {
      $base .= '#' . $parsed['fragment'];
    }

    return $base;
  }

  /**
   * Extract hostname from a URL, lowercased.
   */
  protected function extractHost(string $url): string {
    $parsed = parse_url($url);
    return strtolower($parsed['host'] ?? '');
  }

  /**
   * Resolve a potentially-relative redirect URL against its base.
   */
  protected function resolveRelativeUrl(string $location, string $base): string {
    if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
      return $location;
    }

    $parsed = parse_url($base);
    $scheme = $parsed['scheme'] ?? 'https';
    $host   = $parsed['host'] ?? '';

    if (str_starts_with($location, '/')) {
      return "$scheme://$host$location";
    }

    // Relative path — resolve against current path.
    $path = dirname($parsed['path'] ?? '/');
    return "$scheme://$host$path/$location";
  }

}
