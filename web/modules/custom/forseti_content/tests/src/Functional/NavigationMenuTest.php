<?php

namespace Drupal\Tests\forseti_content\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Validates the main navigation menu has exactly the expected items.
 *
 * Regression test for double-menu-item bug caused by forseti_safety_content
 * and forseti_content both defining the same top-level nav links.
 *
 * Expected top-level items (in weight order):
 *   About, How It Works, Talk with Forseti, Family & Institutions, Job Hunter
 *
 * @group forseti_content
 * @group navigation
 */
class NavigationMenuTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'image',
    'link',
    'menu_ui',
    'block',
    'forseti_content',
  ];

  /**
   * Expected top-level menu item titles, in order.
   */
  const EXPECTED_TOP_LEVEL_ITEMS = [
    'About',
    'How It Works',
    'Talk with Forseti',
    'Family & Institutions',
    'Job Hunter',
  ];

  /**
   * Expected items that must NOT appear in the main nav.
   */
  const FORBIDDEN_ITEMS = [
    'Home',
    'Privacy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Place the main navigation block in the content region so stark renders it.
    $this->drupalPlaceBlock('system_menu_block:main', [
      'region' => 'content',
      'id' => 'main-navigation-test',
    ]);
  }

  /**
   * Assert top-level nav items appear exactly once each.
   */
  public function testMainMenuItemsAreNotDuplicated(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);

    foreach (self::EXPECTED_TOP_LEVEL_ITEMS as $title) {
      $links = $this->getSession()->getPage()->findAll(
        'xpath',
        '//nav//a[normalize-space(text())="' . $title . '"]'
      );
      $count = count($links);
      $this->assertEquals(
        1,
        $count,
        "Expected exactly 1 nav link for '$title', found $count. Possible duplicate menu link definitions."
      );
    }
  }

  /**
   * Assert forbidden items are absent from the main nav.
   */
  public function testForbiddenItemsAbsentFromMainNav(): void {
    $this->drupalGet('<front>');

    foreach (self::FORBIDDEN_ITEMS as $title) {
      $this->assertSession()->elementNotExists(
        'xpath',
        '//nav//a[normalize-space(text())="' . $title . '"]'
      );
    }
  }

  /**
   * Assert the expected set of top-level items is complete — no extras.
   */
  public function testMainMenuHasNoUnexpectedTopLevelItems(): void {
    $this->drupalGet('<front>');

    $navLinks = $this->getSession()->getPage()->findAll(
      'xpath',
      '//nav//ul[contains(@class,"navbar-nav") or @id="main-navigation-test"]/li/a'
    );

    $renderedTitles = array_map(
      fn($link) => trim($link->getText()),
      $navLinks
    );

    // Filter out empty strings and forbidden items.
    $renderedTitles = array_values(array_filter(
      $renderedTitles,
      fn($t) => $t !== '' && !in_array($t, self::FORBIDDEN_ITEMS, TRUE)
    ));

    $unexpected = array_diff($renderedTitles, self::EXPECTED_TOP_LEVEL_ITEMS);
    $this->assertEmpty(
      $unexpected,
      'Unexpected top-level nav items found: ' . implode(', ', $unexpected)
    );
  }

}
