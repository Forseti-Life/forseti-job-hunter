<?php

namespace Drupal\Tests\job_hunter\Unit\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\job_hunter\Form\UserProfileForm;
use Drupal\job_hunter\Repository\UserProfileRepository;
use Drupal\job_hunter\Service\JobSeekerService;
use Drupal\job_hunter\Service\UserProfileService;
use Drupal\Tests\UnitTestCase;

/**
 * Covers professional experience cleanup heuristics.
 *
 * @group job_hunter
 */
class UserProfileFormExperienceCleanupTest extends UnitTestCase {

  private function buildForm(): UserProfileForm {
    return new UserProfileForm(
      $this->createMock(AccountInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(MessengerInterface::class),
      $this->createMock(UserProfileService::class),
      $this->createMock(JobSeekerService::class),
      NULL,
      $this->createMock(Connection::class),
      $this->createMock(UserProfileRepository::class)
    );
  }

  public function testCleanExperienceRolesDropsImpliedAndContextRows(): void {
    $form = $this->buildForm();
    $method = new \ReflectionMethod(UserProfileForm::class, 'cleanExperienceRoles');
    $method->setAccessible(TRUE);

    $roles = [
      [
        'company' => 'MasterCard',
        'title' => 'Data Analytics Contributor',
        'start_date' => '2004-01',
        'end_date' => '2006-01',
      ],
      [
        'company' => 'MasterCard',
        'title' => 'Data & Analytics Professional (implied)',
        'start_date' => '2000-01',
        'end_date' => '2011-12',
      ],
      [
        'company' => 'Pfizer (implied from context)',
        'title' => 'Data & AI Leadership Role (implied)',
        'start_date' => '',
        'end_date' => '',
      ],
    ];

    $cleaned = $method->invoke($form, $roles);

    $this->assertCount(1, $cleaned);
    $this->assertSame('MasterCard', $cleaned[0]['company']);
    $this->assertSame('Data Analytics Contributor', $cleaned[0]['title']);
  }

  public function testCleanExperienceRolesMergesAliasCompanyRowsWithSameDates(): void {
    $form = $this->buildForm();
    $method = new \ReflectionMethod(UserProfileForm::class, 'cleanExperienceRoles');
    $method->setAccessible(TRUE);

    $roles = [
      [
        'company' => 'St. Louis Integration LLC',
        'title' => 'Data Science & AI Consulting Practice',
        'start_date' => '2007-06',
        'end_date' => NULL,
        'highlights' => 'Built the consulting practice.',
      ],
      [
        'company' => 'St. Louis Integration LLC – Data & AI Consulting Practice',
        'title' => 'Founder & Principal Consultant',
        'start_date' => '2007-06',
        'end_date' => NULL,
        'company_context' => 'Independent consulting firm.',
      ],
    ];

    $cleaned = $method->invoke($form, $roles);

    $this->assertCount(1, $cleaned);
    $this->assertSame('St. Louis Integration LLC', $cleaned[0]['company']);
    $this->assertSame('Founder & Principal Consultant', $cleaned[0]['title']);
    $this->assertSame('Independent consulting firm.', $cleaned[0]['company_context']);
  }

  public function testCleanExperienceRolesMergesSameCompanyWhenOneRowLacksExplicitDates(): void {
    $form = $this->buildForm();
    $method = new \ReflectionMethod(UserProfileForm::class, 'cleanExperienceRoles');
    $method->setAccessible(TRUE);

    $roles = [
      [
        'company' => 'Example Corp',
        'title' => 'Platform Lead',
        'start_date' => '2020-01',
        'end_date' => '2021-12',
        'highlights' => 'Led the platform team.',
      ],
      [
        'company' => 'Example Corp',
        'title' => 'Platform Engineering Lead',
        'start_date' => '',
        'end_date' => '',
        'company_context' => 'Broader resume summary row.',
      ],
    ];

    $cleaned = $method->invoke($form, $roles);

    $this->assertCount(1, $cleaned);
    $this->assertSame('Example Corp', $cleaned[0]['company']);
    $this->assertSame('Platform Engineering Lead', $cleaned[0]['title']);
    $this->assertSame('2020-01', $cleaned[0]['start_date']);
    $this->assertSame('2021-12', $cleaned[0]['end_date']);
  }

}
