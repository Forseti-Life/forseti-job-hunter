<?php

/**
 * Populate boolean metrics with estimated Yes/No distributions.
 * Based on typical population statistics for common safety behaviors.
 * 
 * Note: These are estimates based on available research and typical population
 * behaviors. Actual percentages may vary by region and demographic factors.
 */

use Drupal\Core\Database\Database;

$database = Database::getConnection();

echo "Populating boolean metrics with estimated distributions...\n";
echo "==============================================================\n\n";

// Common safety behavior estimates based on various research sources
$boolean_distributions = [
  // SAFE Dimension - Home Safety
  'smoke_detector_functionality' => ['Yes' => 96.0, 'No' => 4.0],  // CDC/NFPA data
  'fire_extinguisher_access' => ['Yes' => 62.0, 'No' => 38.0],
  'carbon_monoxide_detectors' => ['Yes' => 42.0, 'No' => 58.0],
  'evacuation_plan_status' => ['Yes' => 35.0, 'No' => 65.0],  // ready.gov surveys
  'property_insurance_coverage' => ['Yes' => 85.0, 'No' => 15.0],  // III data
  'backup_power_supply' => ['Yes' => 18.0, 'No' => 82.0],
  
  // SAFE - Emergency Preparedness
  'first_aid_certification' => ['Yes' => 15.0, 'No' => 85.0],  // Red Cross estimates
  'medical_alert_system' => ['Yes' => 8.0, 'No' => 92.0],
  'poison_control_number_saved' => ['Yes' => 25.0, 'No' => 75.0],
  'hazardous_materials_storage' => ['Yes' => 45.0, 'No' => 55.0],
  'medication_safety_practices' => ['Yes' => 68.0, 'No' => 32.0],
  'emergency_contact_in_vehicle' => ['Yes' => 55.0, 'No' => 45.0],
  
  // SAFE - Mental Health
  'mental_health_crisis_plan' => ['Yes' => 12.0, 'No' => 88.0],
  'crisis_hotline_access' => ['Yes' => 42.0, 'No' => 58.0],
  'mental_health_first_aid_training' => ['Yes' => 8.0, 'No' => 92.0],
  
  // SAFE - Transportation
  'roadside_assistance_membership' => ['Yes' => 52.0, 'No' => 48.0],  // AAA data
  'vehicle_emergency_kit' => ['Yes' => 38.0, 'No' => 62.0],
  
  // SAFE - Child Safety
  'child_safety_measures' => ['Yes' => 72.0, 'No' => 28.0],
  'childproofing_completeness' => ['Yes' => 65.0, 'No' => 35.0],
  
  // SAFE - Security
  'neighborhood_watch_participation' => ['Yes' => 22.0, 'No' => 78.0],
  'home_security_system' => ['Yes' => 35.0, 'No' => 65.0],
  
  // SAFE - Health Access
  'telemedicine_access' => ['Yes' => 76.0, 'No' => 24.0],  // CDC telehealth data
  'health_insurance_coverage' => ['Yes' => 92.0, 'No' => 8.0],
  
  // ENERGIZED - Employment  
  'full_time_employment' => ['Yes' => 49.8, 'No' => 50.2],  // BLS data
  'part_time_employment' => ['Yes' => 13.2, 'No' => 86.8],
  'remote_work_available' => ['Yes' => 35.0, 'No' => 65.0],
  'paid_leave_available' => ['Yes' => 76.0, 'No' => 24.0],
  'employer_retirement_contribution' => ['Yes' => 68.0, 'No' => 32.0],
  'workplace_safety_training' => ['Yes' => 82.0, 'No' => 18.0],
  
  // ENERGIZED - Housing
  'homeownership' => ['Yes' => 65.2, 'No' => 34.8],  // Census data
  'rent_control_coverage' => ['Yes' => 8.0, 'No' => 92.0],
  'housing_subsidy' => ['Yes' => 4.5, 'No' => 95.5],
  'recent_homelessness' => ['Yes' => 2.0, 'No' => 98.0],
  
  // ENERGIZED - Food Security
  'food_insecurity' => ['Yes' => 10.5, 'No' => 89.5],  // USDA data
  'snap_benefits' => ['Yes' => 12.5, 'No' => 87.5],
  'food_pantry_use' => ['Yes' => 6.0, 'No' => 94.0],
  'home_garden' => ['Yes' => 35.0, 'No' => 65.0],
  
  // ENERGIZED - Financial
  'emergency_fund_available' => ['Yes' => 41.0, 'No' => 59.0],  // Fed Reserve data
  'retirement_savings' => ['Yes' => 55.0, 'No' => 45.0],
  'checking_account' => ['Yes' => 95.0, 'No' => 5.0],
  'savings_account' => ['Yes' => 71.0, 'No' => 29.0],
  'credit_card_access' => ['Yes' => 73.0, 'No' => 27.0],
  'debt_in_collections' => ['Yes' => 14.0, 'No' => 86.0],
  'student_loan_debt' => ['Yes' => 43.0, 'No' => 57.0],
  'medical_debt' => ['Yes' => 23.0, 'No' => 77.0],
  
  // CAPABLE - Education
  'college_degree' => ['Yes' => 38.0, 'No' => 62.0],  // Census education data
  'graduate_degree' => ['Yes' => 13.0, 'No' => 87.0],
  'vocational_certification' => ['Yes' => 22.0, 'No' => 78.0],
  'currently_enrolled_in_school' => ['Yes' => 24.0, 'No' => 76.0],
  'continuing_education_participation' => ['Yes' => 18.0, 'No' => 82.0],
  
  // CAPABLE - Skills
  'second_language_proficiency' => ['Yes' => 20.0, 'No' => 80.0],
  'digital_literacy' => ['Yes' => 72.0, 'No' => 28.0],
  'financial_literacy' => ['Yes' => 57.0, 'No' => 43.0],
  'first_aid_skills' => ['Yes' => 25.0, 'No' => 75.0],
  
  // CONNECTED - Social
  'religious_community_membership' => ['Yes' => 47.0, 'No' => 53.0],  // Pew Research
  'volunteer_regularly' => ['Yes' => 25.0, 'No' => 75.0],
  'club_membership' => ['Yes' => 32.0, 'No' => 68.0],
  'neighborhood_organization_member' => ['Yes' => 15.0, 'No' => 85.0],
  'close_friend_network' => ['Yes' => 68.0, 'No' => 32.0],
  'family_support_available' => ['Yes' => 75.0, 'No' => 25.0],
  
  // CONNECTED - Relationships
  'married_or_partnered' => ['Yes' => 62.0, 'No' => 38.0],
  'children_in_household' => ['Yes' => 28.0, 'No' => 72.0],
  'live_alone' => ['Yes' => 28.0, 'No' => 72.0],
  'pet_ownership' => ['Yes' => 67.0, 'No' => 33.0],
  
  // FREE - Civic Engagement
  'registered_voter' => ['Yes' => 71.0, 'No' => 29.0],  // Census voting data
  'voted_recent_election' => ['Yes' => 66.0, 'No' => 34.0],
  'contact_elected_officials' => ['Yes' => 18.0, 'No' => 82.0],
  'attend_public_meetings' => ['Yes' => 12.0, 'No' => 88.0],
  'petition_signer' => ['Yes' => 35.0, 'No' => 65.0],
  
  // FREE - Rights Awareness
  'know_legal_rights' => ['Yes' => 42.0, 'No' => 58.0],
  'discrimination_experience' => ['Yes' => 25.0, 'No' => 75.0],
  'access_to_legal_counsel' => ['Yes' => 35.0, 'No' => 65.0],
  
  // USEFUL - Work Meaning
  'work_feels_meaningful' => ['Yes' => 72.0, 'No' => 28.0],
  'skills_match_job' => ['Yes' => 65.0, 'No' => 35.0],
  'career_advancement_opportunity' => ['Yes' => 58.0, 'No' => 42.0],
  
  // USEFUL - Contribution
  'volunteer_work' => ['Yes' => 25.0, 'No' => 75.0],
  'mentor_others' => ['Yes' => 18.0, 'No' => 82.0],
  'community_leadership_role' => ['Yes' => 12.0, 'No' => 88.0],
  'charitable_giving' => ['Yes' => 64.0, 'No' => 36.0],
  
  // USEFUL - Purpose
  'sense_of_purpose' => ['Yes' => 75.0, 'No' => 25.0],
  'legacy_planning' => ['Yes' => 32.0, 'No' => 68.0],
  
  // WHOLE - Physical Health
  'regular_exercise' => ['Yes' => 23.0, 'No' => 77.0],  // CDC physical activity
  'healthy_diet' => ['Yes' => 12.0, 'No' => 88.0],
  'adequate_sleep' => ['Yes' => 35.0, 'No' => 65.0],
  'chronic_condition' => ['Yes' => 60.0, 'No' => 40.0],
  'regular_checkups' => ['Yes' => 68.0, 'No' => 32.0],
  
  // WHOLE - Mental Health
  'mental_health_treatment' => ['Yes' => 22.0, 'No' => 78.0],
  'stress_management_practice' => ['Yes' => 42.0, 'No' => 58.0],
  'meditation_practice' => ['Yes' => 14.0, 'No' => 86.0],
  
  // WHOLE - Substance Use
  'tobacco_use' => ['Yes' => 11.5, 'No' => 88.5],  // CDC data
  'alcohol_consumption' => ['Yes' => 54.0, 'No' => 46.0],
  'substance_abuse_history' => ['Yes' => 8.5, 'No' => 91.5],
];

$updated = 0;
$skipped = 0;
$not_found = [];

foreach ($boolean_distributions as $metric_name => $distribution) {
  // Find the metric
  $metric = $database->select('individual_metrics_master', 'imm')
    ->fields('imm', ['id'])
    ->condition('metric_name', $metric_name)
    ->condition('data_type', 'boolean')
    ->execute()
    ->fetchObject();
  
  if (!$metric) {
    $not_found[] = $metric_name;
    continue;
  }
  
  // Check if already has distribution
  $existing = $database->select('individual_metrics_master', 'imm')
    ->fields('imm', ['distribution_data'])
    ->condition('id', $metric->id)
    ->execute()
    ->fetchField();
  
  if (!empty($existing)) {
    echo "  ⏭ {$metric_name} - ALREADY HAS DISTRIBUTION\n";
    $skipped++;
    continue;
  }
  
  // Find most common value
  arsort($distribution);
  $most_common = key($distribution);
  $most_common_pct = current($distribution);
  
  // Update the metric
  $database->update('individual_metrics_master')
    ->fields([
      'distribution_data' => json_encode($distribution),
      'most_common_value' => $most_common,
      'most_common_percentage' => $most_common_pct,
    ])
    ->condition('id', $metric->id)
    ->execute();
  
  echo "  ✓ {$metric_name} - {$most_common} ({$most_common_pct}%)\n";
  $updated++;
}

echo "\n============================================================\n";
echo "Summary:\n";
echo "  Updated: {$updated} metrics\n";
echo "  Skipped: {$skipped} metrics (already had distribution)\n";
echo "  Not Found: " . count($not_found) . " metrics\n";

if (!empty($not_found)) {
  echo "\nMetrics not found in database:\n";
  foreach ($not_found as $name) {
    echo "  - {$name}\n";
  }
}

echo "\n============================================================\n";
echo "Note: These are population estimates based on available research.\n";
echo "Actual percentages may vary by region and demographic factors.\n";
echo "============================================================\n";
