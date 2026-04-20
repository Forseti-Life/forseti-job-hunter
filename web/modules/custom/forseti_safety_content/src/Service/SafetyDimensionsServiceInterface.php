<?php

namespace Drupal\forseti_safety_content\Service;

/**
 * Interface for Safety Dimensions Service.
 */
interface SafetyDimensionsServiceInterface {

  /**
   * Get safety dimensions data.
   *
   * @return array
   *   Array of safety dimension definitions.
   */
  public function getSafetyDimensions();

}
