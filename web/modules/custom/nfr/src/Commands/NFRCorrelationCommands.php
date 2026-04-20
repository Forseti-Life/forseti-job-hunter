<?php

declare(strict_types=1);

namespace Drupal\nfr\Commands;

use Drupal\nfr\Service\NFRCorrelationAnalysisService;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for NFR correlation analysis.
 */
class NFRCorrelationCommands extends DrushCommands {

  /**
   * The correlation analysis service.
   */
  protected NFRCorrelationAnalysisService $correlationService;

  /**
   * Constructs a NFRCorrelationCommands object.
   */
  public function __construct(NFRCorrelationAnalysisService $correlation_service) {
    parent::__construct();
    $this->correlationService = $correlation_service;
  }

  /**
   * Rebuild correlation analysis data for all users.
   *
   * @command nfr:correlation-rebuild
   * @aliases nfr-corr
   * @usage nfr:correlation-rebuild
   *   Rebuild correlation analysis table with data from all NFR users.
   */
  public function rebuildCorrelationData() {
    $this->output()->writeln('Starting correlation analysis data rebuild...');
    
    $stats = $this->correlationService->rebuildAllData();
    
    $this->output()->writeln(sprintf(
      'Rebuild complete: %d successful, %d failed in %s seconds',
      $stats['success'],
      $stats['failed'],
      $stats['duration']
    ));
    
    return $stats['failed'] > 0 ? DrushCommands::EXIT_FAILURE : DrushCommands::EXIT_SUCCESS;
  }

  /**
   * Rebuild correlation data for a specific user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @command nfr:correlation-rebuild-user
   * @aliases nfr-corr-user
   * @usage nfr:correlation-rebuild-user 123
   *   Rebuild correlation data for user ID 123.
   */
  public function rebuildUserCorrelationData(int $uid) {
    $this->output()->writeln(sprintf('Rebuilding correlation data for user %d...', $uid));
    
    if ($this->correlationService->rebuildUserData($uid)) {
      $this->output()->writeln('Successfully rebuilt correlation data.');
      return DrushCommands::EXIT_SUCCESS;
    }
    
    $this->output()->writeln('Failed to rebuild correlation data.');
    return DrushCommands::EXIT_FAILURE;
  }

}
