<?php

namespace Drupal\forseti_safety_content\Service;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Service for Safety Dimensions data.
 */
class SafetyDimensionsService implements SafetyDimensionsServiceInterface {

  use StringTranslationTrait;

  /**
   * Constructs a SafetyDimensionsService object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function getSafetyDimensions() {
    // @todo: Move to YAML config in future iteration
    return $this->buildSafetyDimensionsData();
  }

  /**
   * Build safety dimensions data.
   */
  private function buildSafetyDimensionsData() {
    return [
      [
        'id' => 'safe',
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_safe.png',
        'name' => $this->t('Safe'),
        'planned_enhancement' => FALSE,
        'subtitle' => $this->t('Security & Protection'),
        'description' => $this->t('The foundation of Maslow\'s hierarchy - physical safety and freedom from harm. This dimension tracks crime, emergency response, and environmental hazards.'),
        'integration_note' => NULL,
        'factors' => [
          $this->t('Violent Crime Rate'),
          $this->t('Property Crime Rate'),
          $this->t('Emergency Response Time'),
          $this->t('Police Presence & Community Relations'),
          $this->t('Street Lighting & Infrastructure'),
          $this->t('Environmental Hazards'),
          $this->t('Traffic Safety'),
          $this->t('Building Code Compliance'),
          $this->t('Fire Safety & Prevention'),
          $this->t('Disaster Preparedness'),
        ],
      ],
      [
        'id' => 'energized',
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_energized.png',
        'name' => $this->t('Energized'),
        'planned_enhancement' => TRUE,
        'subtitle' => $this->t('Vitality & Basic Needs'),
        'description' => $this->t('Building on physical safety - access to essential resources like housing, food, and financial stability that energize daily life.'),
        'integration_note' => [
          'message' => $this->t('Forseti can learn what matters most to you about your neighborhood\'s vitality.'),
          'link' => '/talk-with-forseti',
          'link_text' => $this->t('Talk with Forseti →'),
        ],
        'factors' => [
          $this->t('Housing Quality & Affordability'),
          $this->t('Food Security & Access'),
          $this->t('Employment Opportunities'),
          $this->t('Income Stability'),
          $this->t('Utility Reliability'),
          $this->t('Financial Well-being Indicators'),
        ],
      ],
      [
        'id' => 'connected',
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_connected.png',
        'name' => $this->t('Connected'),
        'planned_enhancement' => TRUE,
        'subtitle' => $this->t('Community & Belonging'),
        'description' => $this->t('Social connections and community cohesion - the networks and relationships that create belonging and mutual support.'),
        'integration_note' => [
          'message' => $this->t('Forseti can help you discover community connections.'),
          'link' => '/talk-with-forseti',
          'link_text' => $this->t('Talk with Forseti →'),
        ],
        'factors' => [
          $this->t('Neighborhood Social Cohesion'),
          $this->t('Community Organization Presence'),
          $this->t('Social Support Networks'),
          $this->t('Civic Participation Rate'),
          $this->t('Cultural & Religious Institutions'),
          $this->t('Community Events & Gathering Spaces'),
          $this->t('Social Capital Indicators'),
        ],
      ],
      [
        'id' => 'free',
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_free.png',
        'name' => $this->t('Free'),
        'planned_enhancement' => TRUE,
        'subtitle' => $this->t('Autonomy & Rights'),
        'description' => $this->t('Freedom of movement, privacy, and justice - the ability to make choices and exercise rights without undue constraint.'),
        'integration_note' => [
          'message' => $this->t('Forseti can explore what freedom means in your community context.'),
          'link' => '/talk-with-forseti',
          'link_text' => $this->t('Talk with Forseti →'),
        ],
        'factors' => [
          $this->t('Freedom of Movement'),
          $this->t('Privacy Protection'),
          $this->t('Access to Justice'),
          $this->t('Police Accountability'),
          $this->t('Fair Housing Practices'),
          $this->t('Anti-discrimination Measures'),
        ],
      ],
      [
        'id' => 'capable',
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_capable.png',
        'name' => $this->t('Capable'),
        'planned_enhancement' => TRUE,
        'subtitle' => $this->t('Mastery & Development'),
        'description' => $this->t('Education, skills, and economic opportunity - the resources and systems that enable personal and collective growth.'),
        'integration_note' => [
          'message' => $this->t('Forseti can identify opportunities for skill development in your area.'),
          'link' => '/talk-with-forseti',
          'link_text' => $this->t('Talk with Forseti →'),
        ],
        'factors' => [
          $this->t('Education Quality & Access'),
          $this->t('Skills Training & Development'),
          $this->t('Economic Mobility'),
          $this->t('Job Market Strength'),
          $this->t('Entrepreneurship Support'),
          $this->t('Safety Training & Preparedness'),
        ],
      ],
      [
        'id' => 'useful',
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_useful.png',
        'name' => $this->t('Useful'),
        'planned_enhancement' => TRUE,
        'subtitle' => $this->t('Purpose & Contribution'),
        'description' => $this->t('Civic engagement and contribution - the ability to make meaningful contributions to community well-being and feel valued.'),
        'integration_note' => [
          'message' => $this->t('Forseti can suggest ways to contribute to community safety.'),
          'link' => '/talk-with-forseti',
          'link_text' => $this->t('Talk with Forseti →'),
        ],
        'factors' => [
          $this->t('Volunteer Opportunities'),
          $this->t('Civic Engagement Programs'),
          $this->t('Community Leadership'),
          $this->t('Neighborhood Improvement Initiatives'),
          $this->t('Public Service Accessibility'),
          $this->t('Community Voice in Decision-Making'),
        ],
      ],
      [
        'id' => 'whole',
        'icon' => '/themes/custom/forseti/images/logos/originals/forseti_whole.png',
        'name' => $this->t('Whole'),
        'planned_enhancement' => TRUE,
        'subtitle' => $this->t('Holistic Health & Identity'),
        'description' => $this->t('Comprehensive well-being - mental health, physical health, and community identity that create a sense of wholeness and fulfillment.'),
        'integration_note' => [
          'message' => $this->t('Forseti can discuss holistic community health with you.'),
          'link' => '/talk-with-forseti',
          'link_text' => $this->t('Talk with Forseti →'),
        ],
        'factors' => [
          $this->t('Mental Health Resources'),
          $this->t('Physical Health Services'),
          $this->t('Substance Abuse Support'),
          $this->t('Recreation & Green Space'),
          $this->t('Air & Water Quality'),
          $this->t('Community Identity & Pride'),
          $this->t('Cultural Vitality'),
          $this->t('Demographic Stability'),
        ],
      ],
    ];
  }

}
