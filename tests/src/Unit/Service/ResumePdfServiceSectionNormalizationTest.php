<?php

namespace Drupal\job_hunter\Tests\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\job_hunter\Service\ResumePdfService;

/**
 * Unit tests for ResumePdfService's optional-section normalization.
 *
 * Several resume sections (strategic_differentiators, consulting_practice,
 * early_career, leadership_philosophy, demonstration_projects) have loosely
 * specified GenAI tailoring schemas, so the model can return inconsistent
 * shapes across runs, and source profile data for these sections can
 * contain items that are little more than a label with every descriptive
 * field blank. These tests cover prepareSectionContent()'s normalization
 * and filtering, which is what prevents a section header from ever being
 * printed with nothing meaningful underneath it.
 *
 * @group job_hunter
 * @coversDefaultClass \Drupal\job_hunter\Service\ResumePdfService
 */
class ResumePdfServiceSectionNormalizationTest extends UnitTestCase {

  /**
   * Invoke the protected prepareSectionContent() method under test.
   *
   * @param string $section
   *   The section key.
   * @param mixed $content
   *   The raw section content.
   *
   * @return mixed
   *   Whatever prepareSectionContent() returns.
   */
  protected function prepare(string $section, $content) {
    $reflection = new \ReflectionClass(ResumePdfService::class);
    $service = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('prepareSectionContent');
    $method->setAccessible(TRUE);
    return $method->invoke($service, $section, $content);
  }

  /**
   * @covers ::normalizeStrategicDifferentiators
   */
  public function testStrategicDifferentiatorsSplitsColonFormattedStrings() {
    $result = $this->prepare('strategic_differentiators', [
      'AI Strategy: Designed and deployed agentic workflows across regulated industries.',
    ]);

    $this->assertNotNull($result);
    $this->assertSame('AI Strategy', $result[0]['title']);
    $this->assertSame('Designed and deployed agentic workflows across regulated industries.', $result[0]['description']);
  }

  /**
   * @covers ::normalizeStrategicDifferentiators
   */
  public function testStrategicDifferentiatorsAcceptsTitleDescriptionObjects() {
    $result = $this->prepare('strategic_differentiators', [
      ['title' => 'Enterprise AI Leadership', 'description' => 'Led AI adoption at scale.'],
    ]);

    $this->assertSame('Enterprise AI Leadership', $result[0]['title']);
    $this->assertSame('Led AI adoption at scale.', $result[0]['description']);
  }

  /**
   * @covers ::normalizeStrategicDifferentiators
   */
  public function testStrategicDifferentiatorsDropsCompletelyEmptyItems() {
    $result = $this->prepare('strategic_differentiators', [
      ['title' => '', 'description' => ''],
    ]);

    $this->assertNull($result);
  }

  /**
   * @covers ::normalizeConsultingPractice
   */
  public function testConsultingPracticeAcceptsEngagementsKeyAlias() {
    // The tailoring AI and/or source profile data uses "engagements"
    // instead of the renderer's expected "notable_engagements" key.
    $result = $this->prepare('consulting_practice', [
      'engagements' => [
        ['client' => 'AbbVie', 'project_name' => '', 'role' => '', 'description' => ''],
      ],
    ]);

    $this->assertNotNull($result);
    $this->assertArrayHasKey('notable_engagements', $result);
    $this->assertArrayNotHasKey('engagements', $result);
    $this->assertSame('AbbVie', $result['notable_engagements'][0]['client']);
  }

  /**
   * @covers ::normalizeConsultingPractice
   */
  public function testConsultingPracticeReturnsNullWhenNothingMeaningful() {
    $result = $this->prepare('consulting_practice', [
      'engagements' => [
        ['client' => '', 'project_name' => '', 'role' => '', 'description' => ''],
      ],
    ]);

    $this->assertNull($result);
  }

  /**
   * @covers ::normalizeEarlyCareer
   */
  public function testEarlyCareerPreservesLeadingDateRangeAsPrefix() {
    $result = $this->prepare('early_career', [
      ['company' => '2000-2011', 'title' => '', 'description' => 'Built foundational expertise across enterprise data systems.'],
      ['company' => '', 'title' => '', 'description' => 'Additional early-career context.'],
    ]);

    $this->assertNotNull($result);
    $this->assertStringStartsWith('2000-2011 — ', $result[0]);
    $this->assertSame('Additional early-career context.', $result[1]);
  }

  /**
   * @covers ::normalizeEarlyCareer
   */
  public function testEarlyCareerReturnsNullWhenAllItemsAreBlank() {
    $result = $this->prepare('early_career', [
      ['company' => '', 'title' => '', 'description' => ''],
    ]);

    $this->assertNull($result);
  }

  /**
   * @covers ::normalizeEarlyCareer
   */
  public function testEarlyCareerKeepsBareDateRangeWhenNothingElseAvailable() {
    $result = $this->prepare('early_career', '2000-2011');

    $this->assertSame(['2000-2011'], $result);
  }

  /**
   * @covers ::normalizeLeadershipPhilosophy
   */
  public function testLeadershipPhilosophyDedupesNearIdenticalParagraphsAndCapsCount() {
    $paragraph = 'I excel at designing and implementing data services organizations that drive measurable business impact.';
    $result = $this->prepare('leadership_philosophy', [
      ['principle' => $paragraph . ' Extra trailing detail A.'],
      ['principle' => $paragraph . ' Extra trailing detail B.'],
      ['principle' => 'A genuinely distinct second philosophy statement about collaborative leadership.'],
      ['principle' => 'A third, different philosophy statement that should be capped out.'],
    ]);

    $this->assertNotNull($result);
    $this->assertCount(2, $result);
  }

  /**
   * @covers ::normalizeLeadershipPhilosophy
   */
  public function testLeadershipPhilosophyAcceptsBareString() {
    $result = $this->prepare('leadership_philosophy', 'A single leadership philosophy statement.');
    $this->assertSame(['A single leadership philosophy statement.'], $result);
  }

  /**
   * @covers ::normalizeDemonstrationProjects
   */
  public function testDemonstrationProjectsDedupesSubsumedNames() {
    $result = $this->prepare('demonstration_projects', [
      ['name' => 'GenAI Demo Site - The Truth Perspective', 'description' => '', 'url' => '', 'technologies' => []],
      ['name' => 'The Truth Perspective', 'description' => '', 'url' => '', 'technologies' => []],
      ['name' => 'GenAI Demo Site', 'description' => '', 'url' => '', 'technologies' => []],
    ]);

    $this->assertNotNull($result);
    $this->assertCount(1, $result);
    $this->assertSame('GenAI Demo Site - The Truth Perspective', $result[0]['name']);
  }

  /**
   * @covers ::normalizeDemonstrationProjects
   */
  public function testDemonstrationProjectsDropsCompletelyEmptyItems() {
    $result = $this->prepare('demonstration_projects', [
      ['name' => '', 'description' => '', 'url' => '', 'technologies' => []],
    ]);

    $this->assertNull($result);
  }

  /**
   * @covers ::prepareSectionContent
   */
  public function testUnrelatedSectionsPassThroughUnchanged() {
    $content = ['summary' => 'Executive profile summary text.'];
    $result = $this->prepare('executive_profile', $content);
    $this->assertSame($content, $result);
  }

}
