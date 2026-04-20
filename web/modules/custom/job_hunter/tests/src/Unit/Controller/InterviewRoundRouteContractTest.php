<?php

namespace Drupal\Tests\job_hunter\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Static contract test for the interview round save route.
 *
 * @group job_hunter
 */
class InterviewRoundRouteContractTest extends TestCase {

  private const ROUTING_FILE = __DIR__ . '/../../../../job_hunter.routing.yml';

  /**
   * Extract a named route block from routing.yml.
   */
  private function getRouteBlock(string $route_name): string {
    $content = file_get_contents(self::ROUTING_FILE);
    $pattern = '/^' . preg_quote($route_name, '/') . ":\n(?:(?:  |    ).*\n)*/m";
    preg_match($pattern, $content, $matches);
    return $matches[0] ?? '';
  }

  public function testInterviewRoundSaveRouteRequiresExpectedProtections(): void {
    $block = $this->getRouteBlock('job_hunter.interview_round_save');

    $this->assertNotSame('', $block, 'Interview round save route must exist.');
    $this->assertStringContainsString("path: '/jobhunter/interview-rounds/{job_id}/save'", $block);
    $this->assertStringContainsString("methods: [POST]", $block);
    $this->assertStringContainsString("_controller: '\\Drupal\\job_hunter\\Controller\\CompanyController::interviewRoundSave'", $block);
    $this->assertStringContainsString("_permission: 'access job hunter'", $block);
    $this->assertStringContainsString("_user_is_logged_in: 'TRUE'", $block);
    $this->assertStringContainsString("_csrf_token: 'TRUE'", $block);
  }

}
