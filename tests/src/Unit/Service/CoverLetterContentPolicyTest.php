<?php

namespace Drupal\job_hunter\Tests\Unit\Service;

use Drupal\job_hunter\Service\CoverLetterContentPolicy;
use Drupal\Tests\UnitTestCase;

/**
 * Tests cover-letter template variable resolution.
 *
 * @group job_hunter
 * @coversDefaultClass \Drupal\job_hunter\Service\CoverLetterContentPolicy
 */
class CoverLetterContentPolicyTest extends UnitTestCase {

  /**
   * @covers ::resolvePlaceholders
   * @covers ::assertNoPlaceholders
   */
  public function testResolvesKnownVariablesAndRemovesUnavailableHeaderLines(): void {
    $content = <<<'LETTER'
Keith Aumiller
Philadelphia, PA

[Date]

[Hiring Manager Name]
[Company Name]
[Company Address]

Dear Hiring Manager,

I am applying for the [Position Title] role at [Company Name].

Sincerely,
[Name]
LETTER;

    $resolved = CoverLetterContentPolicy::resolvePlaceholders(
      $content,
      'VP of Data',
      'Example Company',
      'Keith Aumiller'
    );
    CoverLetterContentPolicy::assertNoPlaceholders($resolved);

    $this->assertStringNotContainsString('[', $resolved);
    $this->assertStringNotContainsString('Date', $resolved);
    $this->assertStringContainsString("Example Company\n\nDear Hiring Manager,", $resolved);
    $this->assertStringContainsString('VP of Data role at Example Company', $resolved);
    $this->assertStringEndsWith("Sincerely,\nKeith Aumiller", $resolved);
  }

  /**
   * @covers ::resolvePlaceholders
   */
  public function testGenericProfileTemplateHasNoVariables(): void {
    $resolved = CoverLetterContentPolicy::resolvePlaceholders(
      "[Company Name]\n\nI am applying for the [Position Title] role at [Company Name].",
      '',
      '',
      ''
    );

    $this->assertSame(
      'I am applying for this role at the organization.',
      $resolved
    );
  }

  /**
   * @covers ::assertNoPlaceholders
   */
  public function testRejectsUnknownPlaceholder(): void {
    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('[Salary Requirement]');
    CoverLetterContentPolicy::assertNoPlaceholders(
      'Please consider [Salary Requirement].'
    );
  }

}
