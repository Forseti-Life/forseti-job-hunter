<?php

namespace Drupal\job_hunter\Tests\Unit\Service;

use Drupal\job_hunter\Service\DemonstrationProjectPolicy;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the resume demonstration-project inclusion policy.
 *
 * @group job_hunter
 * @coversDefaultClass \Drupal\job_hunter\Service\DemonstrationProjectPolicy
 */
class DemonstrationProjectPolicyTest extends UnitTestCase {

  /**
   * @covers ::filter
   */
  public function testFilterRequiresHttpUrl(): void {
    $result = DemonstrationProjectPolicy::filter([
      ['name' => 'Work initiative', 'description' => 'Internal work only.'],
      ['name' => 'Local file', 'url' => 'file:///tmp/demo'],
      ['name' => 'Public demo', 'url' => 'https://demo.example/app'],
    ]);

    $this->assertCount(1, $result);
    $this->assertSame('Public demo', $result[0]['name']);
  }

  /**
   * @covers ::filter
   */
  public function testFilterRejectsUrlNotPresentInSourceProfile(): void {
    $source = [
      [
        'name' => 'Approved demo',
        'description' => 'Source profile description.',
        'url' => 'https://demo.example/approved/',
      ],
      ['name' => 'Professional project', 'url' => NULL],
    ];
    $result = DemonstrationProjectPolicy::filter([
      [
        'name' => 'Professional initiative disguised as a demo',
        'description' => 'Invented model description.',
        'url' => 'https://demo.example/approved',
      ],
      ['name' => 'Invented demo', 'url' => 'https://demo.example/invented'],
      ['name' => 'Professional project', 'url' => ''],
    ], $source);

    $this->assertCount(1, $result);
    $this->assertSame('Approved demo', $result[0]['name']);
    $this->assertSame('Source profile description.', $result[0]['description']);
    $this->assertSame('https://demo.example/approved', $result[0]['url']);
  }

  /**
   * @covers ::filter
   */
  public function testFilterMergesDuplicateUrlEntries(): void {
    $result = DemonstrationProjectPolicy::filter([
      [
        'name' => 'Demo',
        'description' => '',
        'url' => 'https://demo.example/app',
        'technologies' => ['PHP'],
      ],
      [
        'name' => 'Public Demonstration Application',
        'description' => 'A working public demonstration.',
        'url' => 'https://demo.example/app/',
        'technologies' => ['php', 'Drupal'],
      ],
    ]);

    $this->assertCount(1, $result);
    $this->assertSame('Public Demonstration Application', $result[0]['name']);
    $this->assertSame('A working public demonstration.', $result[0]['description']);
    $this->assertSame(['php', 'Drupal'], $result[0]['technologies']);
  }

}
