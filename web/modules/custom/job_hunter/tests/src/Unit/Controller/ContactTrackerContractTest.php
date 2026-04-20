<?php

namespace Drupal\Tests\job_hunter\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Static contract coverage for the contact tracker implementation.
 *
 * @group job_hunter
 */
class ContactTrackerContractTest extends TestCase {

  private const ROUTING_FILE = __DIR__ . '/../../../../job_hunter.routing.yml';
  private const CONTROLLER_FILE = __DIR__ . '/../../../../src/Controller/CompanyController.php';
  private const INSTALL_FILE = __DIR__ . '/../../../../job_hunter.install';

  /**
   * Extract a named route block from routing.yml.
   */
  private function getRouteBlock(string $route_name): string {
    $content = file_get_contents(self::ROUTING_FILE);
    $pattern = '/^' . preg_quote($route_name, '/') . ":\n(?:(?:  |    ).*\n)*/m";
    preg_match($pattern, $content, $matches);
    return $matches[0] ?? '';
  }

  public function testContactRoutesKeepExpectedProtections(): void {
    $list_block = $this->getRouteBlock('job_hunter.contacts_list');
    $save_block = $this->getRouteBlock('job_hunter.contacts_save');
    $delete_block = $this->getRouteBlock('job_hunter.contacts_delete');

    $this->assertNotSame('', $list_block, 'Contacts list route must exist.');
    $this->assertStringContainsString("path: '/jobhunter/contacts'", $list_block);
    $this->assertStringContainsString("_user_is_logged_in: 'TRUE'", $list_block);

    $this->assertNotSame('', $save_block, 'Contacts save route must exist.');
    $this->assertStringContainsString("methods: [POST]", $save_block);
    $this->assertStringContainsString("_csrf_token: 'TRUE'", $save_block);

    $this->assertNotSame('', $delete_block, 'Contacts delete route must exist.');
    $this->assertStringContainsString("methods: [POST]", $delete_block);
    $this->assertStringContainsString("_csrf_token: 'TRUE'", $delete_block);
  }

  public function testContactControllerUsesCompanyBackedContacts(): void {
    $content = file_get_contents(self::CONTROLLER_FILE);

    $this->assertStringContainsString("const CONTACT_REFERRAL_STATUSES = ['none', 'requested', 'pending', 'provided'];", $content);
    $this->assertStringContainsString("name=\"company_id\" required", $content);
    $this->assertStringContainsString("condition('ct.company_id', (int) \$job_company_id)", $content);
    $this->assertStringContainsString("\$fields['company_id'] = (int) \$company->id;", $content);
    $this->assertStringContainsString("<form method=\"post\" action=\"", $content);
  }

  public function testContactSchemaRealignmentHookExists(): void {
    $content = file_get_contents(self::INSTALL_FILE);

    $this->assertStringContainsString('function job_hunter_update_9059()', $content);
    $this->assertStringContainsString("'jobhunter_contacts', 'name'", $content);
    $this->assertStringContainsString("'jobhunter_contacts', 'title'", $content);
    $this->assertStringContainsString("'jobhunter_contacts', 'company_id'", $content);
    $this->assertStringContainsString("WHEN 'referred' THEN 'provided'", $content);
    $this->assertStringContainsString("WHEN 'pending-referral' THEN 'pending'", $content);
  }

}
