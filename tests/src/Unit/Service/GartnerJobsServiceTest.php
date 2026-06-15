<?php

namespace Drupal\job_hunter\Tests\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\job_hunter\Service\GartnerJobsService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Gartner jobs scraper.
 *
 * @group job_hunter
 */
class GartnerJobsServiceTest extends UnitTestCase {

  /**
   * Tests non-matching queries do not trigger job page fetches.
   */
  public function testSearchJobsSkipsPageFetchForObviousNonMatches(): void {
    $client = $this->createMock(Client::class);
    $client->expects($this->once())
      ->method('get')
      ->with('https://jobs.gartner.com/jobs/jobs-xml/?rss=true')
      ->willReturn(new Response(200, [], $this->buildFeedXml([
        [
          'title' => 'Director, Data Platforms',
          'link' => 'https://jobs.gartner.com/jobs/job/123-director-data-platforms',
          'description' => 'Build data platforms for internal teams.',
          'pubDate' => 'Mon, 15 Jun 2026 12:00:00 GMT',
        ],
      ])));

    $logger_factory = $this->createLoggerFactoryMock();
    $service = new GartnerJobsService($client, $logger_factory);

    $results = $service->searchJobs([
      'query' => 'unmatched keyword',
      'location' => 'Philadelphia, PA',
      'page' => 1,
      'results_per_page' => 10,
    ]);

    $this->assertSame([], $results['jobs']);
    $this->assertSame(0, $results['total']);
  }

  /**
   * Tests invalid RSS pubDate values fall back to Unknown.
   */
  public function testSearchJobsFallsBackWhenPubDateIsInvalid(): void {
    $client = $this->createMock(Client::class);
    $feed_xml = $this->buildFeedXml([
      [
        'title' => 'Director, Data Platforms',
        'link' => 'https://jobs.gartner.com/jobs/job/123-director-data-platforms',
        'description' => 'Build data platforms for internal teams.',
        'pubDate' => 'not-a-date',
      ],
    ]);

    $client->method('get')
      ->willReturnCallback(function (string $url, array $options = []) use ($feed_xml) {
        if ($url === 'https://jobs.gartner.com/jobs/jobs-xml/?rss=true') {
          return new Response(200, [], $feed_xml);
        }

        return new Response(200, [], $this->buildJobHtml());
      });

    $logger_factory = $this->createLoggerFactoryMock();
    $service = new GartnerJobsService($client, $logger_factory);

    $results = $service->searchJobs([
      'query' => 'Director',
      'location' => 'Philadelphia, PA',
      'page' => 1,
      'results_per_page' => 10,
    ]);

    $this->assertCount(1, $results['jobs']);
    $this->assertSame('Unknown', $results['jobs'][0]['posted_date']);
  }

  /**
   * Builds a minimal Gartner RSS feed XML string.
   *
   * @param array<int, array<string, string>> $items
   *   Feed items to include.
   */
  protected function buildFeedXml(array $items): string {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Gartner Jobs</title>';
    foreach ($items as $item) {
      $xml .= '<item>';
      $xml .= '<title>' . htmlspecialchars($item['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</title>';
      $xml .= '<link>' . htmlspecialchars($item['link'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</link>';
      $xml .= '<description><![CDATA[' . $item['description'] . ']]></description>';
      $xml .= '<pubDate>' . htmlspecialchars($item['pubDate'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</pubDate>';
      $xml .= '</item>';
    }
    $xml .= '</channel></rss>';

    return $xml;
  }

  /**
   * Builds a minimal Gartner job page HTML string.
   */
  protected function buildJobHtml(): string {
    return <<<HTML
<!doctype html>
<html>
  <body>
    <h1 class="display-2">Director, Data Platforms</h1>
    <ul class="job-meta">
      <li>📍 Philadelphia, PA</li>
      <li>Data &amp; Analytics</li>
    </ul>
    <article class="cms-content">Build data platforms for internal teams.</article>
  </body>
</html>
HTML;
  }

  /**
   * Builds a mocked logger factory.
   */
  protected function createLoggerFactoryMock(): LoggerChannelFactoryInterface {
    $logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('job_hunter')->willReturn($logger);

    return $logger_factory;
  }

}
