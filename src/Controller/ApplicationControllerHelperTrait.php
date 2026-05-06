<?php

namespace Drupal\job_hunter\Controller;

/**
 * Shared helper methods used by ApplicationSubmissionController
 * and ApplicationActionController.
 */
trait ApplicationControllerHelperTrait {

  private function loadSelectedJobContext(int $uid, int $job_id): ?object {
    return $this->repository->loadJobContext($uid, $job_id);
  }

  /**
   * Step 5: Interview & Follow-up page.
   *
   * @return array
   *   A renderable array for the interview and follow-up page.
   */

}
