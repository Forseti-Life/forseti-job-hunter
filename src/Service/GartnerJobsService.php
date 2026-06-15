<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for Gartner jobs feed integration.
 */
class GartnerJobsService {

  /**
   * Gartner jobs RSS feed URL.
   */
  private const FEED_URL = 'https://jobs.gartner.com/jobs/jobs-xml/?rss=true';

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a GartnerJobsService object.
   */
  public function __construct(ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('job_hunter');
  }

  /**
   * Search Gartner jobs using the public RSS feed plus per-job page metadata.
   *
   * @param array $params
   *   Search parameters.
   *
   * @return array
   *   Array containing jobs and pagination metadata.
   */
  public function searchJobs(array $params): array {
    $query = trim((string) ($params['query'] ?? ''));
    $location = trim((string) ($params['location'] ?? ''));
    $page = max(1, (int) ($params['page'] ?? 1));
    $results_per_page = max(1, min(50, (int) ($params['results_per_page'] ?? 10)));

    try {
      $feed = $this->fetchFeed();
      $items = $feed->channel->item ?? [];
      $jobs = [];

      foreach ($items as $item) {
        $job = $this->normalizeFeedItem($item, $query, $location);
        if ($job !== NULL) {
          $jobs[] = $job;
        }
      }

      $total = count($jobs);
      $offset = ($page - 1) * $results_per_page;
      $paged_jobs = array_slice($jobs, $offset, $results_per_page);

      $this->logger->info('📚 Gartner feed returned @count matching job(s)', [
        '@count' => $total,
      ]);

      return [
        'jobs' => $paged_jobs,
        'total' => $total,
        'page' => $page,
        'has_more' => $total > ($offset + $results_per_page),
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('❌ Gartner jobs search failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [
        'jobs' => [],
        'total' => 0,
        'page' => $page,
        'has_more' => FALSE,
      ];
    }
  }

  /**
   * Fetch and parse the Gartner RSS feed.
   */
  protected function fetchFeed(): \SimpleXMLElement {
    $response = $this->httpClient->get(self::FEED_URL, [
      'headers' => [
        'Accept' => 'application/rss+xml,application/xml,text/xml;q=0.9,*/*;q=0.8',
        'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
      ],
      'timeout' => 20,
    ]);

    $xml = (string) $response->getBody();
    $feed = @simplexml_load_string($xml);

    if (!$feed instanceof \SimpleXMLElement) {
      throw new \RuntimeException('Unable to parse Gartner RSS feed.');
    }

    return $feed;
  }

  /**
   * Normalize one RSS item into the shared job result shape.
   *
   * @param \SimpleXMLElement $item
   *   RSS item.
   * @param string $query
   *   Requested query string.
   * @param string $requested_location
   *   Requested location string.
   *
   * @return array|null
   *   Normalized result or NULL when the item does not match.
   */
  protected function normalizeFeedItem(\SimpleXMLElement $item, string $query, string $requested_location): ?array {
    $title = trim((string) $item->title);
    $url = trim((string) $item->link);
    $description = $this->stripHtml((string) $item->description);
    $pub_date = trim((string) $item->pubDate);

    if ($title === '' || $url === '') {
      return NULL;
    }

    // Reject obvious non-matches before fetching the job page.
    if (!$this->matchesQuery($query, $title . ' ' . $description)) {
      return NULL;
    }

    $job_page = $this->fetchJobPageData($url);
    $location = trim((string) ($job_page['location'] ?? ''));
    $page_text = trim(($job_page['title'] ?? '') . ' ' . ($job_page['location'] ?? '') . ' ' . ($job_page['description'] ?? ''));

    if (!$this->matchesQuery($query, $title . ' ' . $description . ' ' . $page_text)) {
      return NULL;
    }

    if ($requested_location !== '' && !$this->matchesLocation($requested_location, $location . ' ' . $page_text)) {
      return NULL;
    }

    $job_id = $this->extractJobId($url) ?: md5($url);
    $combined_text = trim($description . ' ' . ($job_page['description'] ?? ''));

    return [
      'id' => 'gartner_' . $job_id,
      'title' => $title,
      'company' => 'Gartner',
      'location' => $location !== '' ? $location : 'Not specified',
      'description' => $this->truncateText($combined_text, 200),
      'source' => 'Gartner Careers',
      'posted_date' => $this->formatPostedDate($pub_date),
      'url' => $url,
      'job_hash' => md5('gartner|' . mb_strtolower($title) . '|' . mb_strtolower($location)),
      'raw_data' => [
        'feed_title' => $title,
        'feed_description' => $description,
        'job_page' => $job_page,
        'feed_url' => $url,
      ],
    ];
  }

  /**
   * Fetch extra metadata from the Gartner job page.
   *
   * @param string $url
   *   Job page URL.
   *
   * @return array
   *   Parsed job page metadata.
   */
  protected function fetchJobPageData(string $url): array {
    if ($url === '') {
      return [];
    }

    try {
      $response = $this->httpClient->get($url, [
        'headers' => [
          'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
          'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        ],
        'timeout' => 20,
      ]);

      $html = (string) $response->getBody();
      return $this->extractJobPageData($html);
    }
    catch (RequestException $e) {
      $this->logger->warning('⚠️ Gartner job page fetch failed for @url: @error', [
        '@url' => $url,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Extract title/location/summary data from a Gartner job page.
   */
  protected function extractJobPageData(string $html): array {
    if ($html === '') {
      return [];
    }

    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new \DOMXPath($dom);
    $title = $this->extractNodeText($xpath, '//h1[contains(@class, "display-2")]');
    $location = $this->extractNodeText($xpath, '//ul[contains(@class, "job-meta")]/li[1]');
    $category = $this->extractNodeText($xpath, '//ul[contains(@class, "job-meta")]/li[2]');
    $description = $this->extractNodeText($xpath, '//article[contains(@class, "cms-content")]');

    $location = preg_replace('/\s+/', ' ', $this->stripIconText($location)) ?? $location;
    $location = trim($location);

    return [
      'title' => $title,
      'location' => $location,
      'department' => $category !== '' ? $category : '',
      'description' => $description,
    ];
  }

  /**
   * Extract text content from the first XPath node match.
   */
  protected function extractNodeText(\DOMXPath $xpath, string $expression): string {
    $node = $xpath->query($expression)->item(0);
    if (!$node) {
      return '';
    }

    return $this->normalizeWhitespace($node->textContent ?? '');
  }

  /**
   * Determine whether the query text matches the job text.
   */
  protected function matchesQuery(string $query, string $text): bool {
    $query = trim(mb_strtolower($query));
    if ($query === '') {
      return TRUE;
    }

    $text = mb_strtolower($this->normalizeWhitespace($text));
    foreach ($this->splitSearchTerms($query) as $term) {
      if (!str_contains($text, $term)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Determine whether the location matches the requested location.
   */
  protected function matchesLocation(string $requested_location, string $text): bool {
    $requested_location = trim(mb_strtolower($requested_location));
    if ($requested_location === '') {
      return TRUE;
    }

    $text = mb_strtolower($this->normalizeLocationText($text));
    foreach ($this->splitSearchTerms($requested_location) as $term) {
      if (!str_contains($text, $term)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Split a search string into meaningful tokens.
   *
   * @return string[]
   *   Search tokens.
   */
  protected function splitSearchTerms(string $text): array {
    $terms = preg_split('/[\s,\/]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $terms = array_map(static fn(string $term): string => trim($term), $terms);
    $terms = array_filter($terms, static fn(string $term): bool => $term !== '' && strlen($term) > 1);
    return array_values(array_unique($terms));
  }

  /**
   * Strip HTML and normalize whitespace.
   */
  protected function stripHtml(string $html): string {
    return $this->normalizeWhitespace(html_entity_decode(trim(strip_tags($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  }

  /**
   * Strip icon-related glyph text from location strings.
   */
  protected function stripIconText(string $text): string {
    return preg_replace('/^[\p{So}\p{Sk}\p{Cf}\s]+/u', '', $text) ?? $text;
  }

  /**
   * Normalize whitespace.
   */
  protected function normalizeWhitespace(string $text): string {
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;
    return trim($text);
  }

  /**
   * Normalize a location string for token matching.
   */
  protected function normalizeLocationText(string $location): string {
    $location = $this->normalizeWhitespace($location);
    $location = preg_replace('/[^\p{L}\p{N}\s\/,-]+/u', ' ', $location) ?? $location;
    return $this->normalizeWhitespace($location);
  }

  /**
   * Extract Gartner job ID from a job URL.
   */
  protected function extractJobId(string $url): string {
    if (preg_match('~/jobs/job/(\d+)-~', $url, $matches)) {
      return $matches[1];
    }

    if (preg_match('~/jobs/job/(\d+)/?~', $url, $matches)) {
      return $matches[1];
    }

    return '';
  }

  /**
   * Truncate text to the requested length.
   */
  protected function truncateText(string $text, int $length): string {
    if (strlen($text) <= $length) {
      return $text;
    }

    return substr($text, 0, $length) . '...';
  }

  /**
   * Format an RSS pubDate safely.
   */
  protected function formatPostedDate(string $pub_date): string {
    if ($pub_date === '') {
      return 'Unknown';
    }

    $timestamp = strtotime($pub_date);
    if ($timestamp === FALSE) {
      return 'Unknown';
    }

    return date('M j, Y', $timestamp);
  }

}
