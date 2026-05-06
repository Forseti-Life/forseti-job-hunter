<?php

namespace Drupal\job_hunter\Controller;

use Drupal\Core\Url;

/**
 * Trait for Job Hunter controllers to ensure consistent navigation.
 *
 * This trait provides a standardized method to wrap content with the
 * Job Hunter navigation sidebar, ensuring all pages have consistent
 * navigation regardless of how the controller returns content.
 *
 * Usage:
 * @code
 * class MyController extends ControllerBase {
 *   use JobHunterControllerTrait;
 *
 *   public function myPage() {
 *     $content = [
 *       '#markup' => '<p>My content</p>',
 *     ];
 *     return $this->wrapWithNavigation($content);
 *   }
 * }
 * @endcode
 */
trait JobHunterControllerTrait {

  /**
   * Block plugin ID for Job Hunter navigation.
   *
   * @var string
   */
  const NAVIGATION_BLOCK_ID = 'job_hunter_navigation';

  /**
   * Theme hook for Job Hunter dashboard wrapper.
   *
   * @var string
   */
  const WRAPPER_THEME = 'job_application_dashboard_wrapper';

  /**
   * Default libraries for Job Hunter pages.
   *
   * @var array
   */
  const DEFAULT_LIBRARIES = [
    'job_hunter/job-hunter-navigation',
    'job_hunter/job-hunter-home',
  ];

  /**
   * Wraps content with Job Hunter navigation sidebar.
   *
   * This is the SINGLE SOURCE OF TRUTH for how Job Hunter pages
   * should be rendered with navigation. All controller methods that
   * return page content should use this method.
   *
   * @param array $content
   *   The render array for the page content.
   * @param array $additional_libraries
   *   Optional additional libraries to attach (beyond the default navigation libraries).
   *
   * @return array
   *   A render array with navigation wrapper.
   */
  protected function wrapWithNavigation(array $content, array $additional_libraries = []): array {
    // Render navigation block with error handling
    try {
      $block_manager = \Drupal::service('plugin.manager.block');
      $plugin_block = $block_manager->createInstance(self::NAVIGATION_BLOCK_ID, []);
      $navigation_block = $plugin_block->build();
    }
    catch (\Exception $e) {
      \Drupal::logger('job_hunter')->error('Failed to load navigation block: @error', ['@error' => $e->getMessage()]);
      $navigation_block = ['#markup' => ''];
    }

    // Merge with any additional libraries
    $libraries = array_merge(self::DEFAULT_LIBRARIES, $additional_libraries);

    return [
      '#theme' => self::WRAPPER_THEME,
      '#navigation' => $navigation_block,
      '#content' => $content,
      '#attached' => [
        'library' => $libraries,
      ],
    ];
  }

}
