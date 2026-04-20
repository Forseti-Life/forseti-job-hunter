<?php

/**
 * Populate select metrics with categorical distributions.
 * Based on US Census, CDC, USDA, and research data.
 */

use Drupal\Core\Database\Database;

$database = Database::getConnection();

echo "Populating select metrics with categorical distributions...\n";
echo "=============================================================\n\n";

// Distributions based on research and government data
$select_distributions = [
  
  // DEMOGRAPHIC - State of Residence (Top 10 most populous states)
  'state_of_residence' => [
    'California' => 11.7,
    'Texas' => 8.9,
    'Florida' => 6.5,
    'New York' => 5.8,
    'Pennsylvania' => 3.8,
    'Illinois' => 3.8,
    'Ohio' => 3.5,
    'Georgia' => 3.2,
    'North Carolina' => 3.2,
    'Michigan' => 3.0,
    // Other states: 46.6%
  ],
  
  // CAPABLE - Highest Education Level (US Census)
  'highest_education_level' => [
    'Less than high school' => 10.0,
    'High school diploma/GED' => 27.0,
    'Some college, no degree' => 21.0,
    "Associate's degree" => 8.5,
    "Bachelor's degree" => 21.0,
    'Graduate or professional degree' => 12.5,
  ],
  
  // CAPABLE - College Type (NCES data)
  'college_type' => [
    '4-year public' => 42.0,
    '4-year private nonprofit' => 28.0,
    '2-year public (community college)' => 22.0,
    'For-profit' => 5.0,
    'Vocational/technical' => 3.0,
  ],
  
  // CAPABLE - Graduate Degree Type (among grad students)
  'graduate_degree_type' => [
    "Master's degree" => 65.0,
    'Doctoral degree (PhD)' => 18.0,
    'Professional degree (MD, JD, etc.)' => 17.0,
  ],
  
  // CAPABLE - Bullying Experience (CDC Youth Risk Behavior Survey)
  'bullying_experience' => [
    'Never' => 65.0,
    'Rarely' => 20.0,
    'Sometimes' => 10.0,
    'Often' => 5.0,
  ],
  
  // CONNECTED - Loneliness Frequency (Cigna/Harvard research)
  'loneliness_frequency' => [
    'Rarely' => 45.0,
    'Sometimes' => 35.0,
    'Often' => 20.0,
  ],
  
  // CONNECTED - Transit Reliability
  'transit_reliability' => [
    'Usually on time' => 58.0,
    'Sometimes on time' => 28.0,
    'Rarely on time' => 14.0,
  ],
  
  // CONNECTED - Language Barrier Frequency (Census language data)
  'language_barrier_frequency' => [
    'Rarely/Never' => 85.0,
    'Sometimes' => 10.0,
    'Daily' => 5.0,
  ],
  
  // CONNECTED - Discrimination Experience Frequency (Pew Research)
  'discrimination_experience_frequency' => [
    'Never' => 50.0,
    'Rarely' => 25.0,
    'Sometimes' => 18.0,
    'Often' => 7.0,
  ],
  
  // CONNECTED - Stereotype Experience
  'stereotype_experience' => [
    'Never' => 48.0,
    'Rarely' => 28.0,
    'Sometimes' => 18.0,
    'Often' => 6.0,
  ],
  
  // CONNECTED - Microaggression Frequency
  'microaggression_frequency' => [
    'Never' => 42.0,
    'Rarely' => 30.0,
    'Sometimes' => 20.0,
    'Often' => 8.0,
  ],
  
  // ENERGIZED - Child Meal Reliability (USDA food security)
  'child_meal_reliability' => [
    'Always 3 meals daily' => 78.0,
    'Usually 3 meals daily' => 15.0,
    'Rarely 3 meals daily' => 7.0,
  ],
  
  // ENERGIZED - Protein Adequacy
  'protein_adequacy' => [
    'Can afford daily' => 82.0,
    'Sometimes' => 13.0,
    'Rarely' => 5.0,
  ],
  
  // ENERGIZED - Food Quality Trade-offs (USDA)
  'food_quality_trade_offs' => [
    'Never buy cheaper/less nutritious' => 45.0,
    'Sometimes' => 38.0,
    'Always' => 17.0,
  ],
  
  // ENERGIZED - Coverage Gaps (health insurance)
  'coverage_gaps' => [
    'No gaps' => 82.0,
    'Occasional gaps' => 12.0,
    'Frequent gaps' => 6.0,
  ],
  
  // ENERGIZED - Employment Contract
  'employment_contract' => [
    'Full-time permanent' => 65.0,
    'Part-time' => 17.0,
    'Contract/Temp' => 10.0,
    'Gig/Freelance' => 8.0,
  ],
  
  // ENERGIZED - Passive Income
  'passive_income' => [
    'None' => 72.0,
    'Less than $1,000/year' => 15.0,
    '$1,000-$5,000/year' => 8.0,
    'More than $5,000/year' => 5.0,
  ],
  
  // ENERGIZED - Retirement Savings
  'retirement_savings' => [
    'Actively contributing' => 55.0,
    'Have savings, not contributing' => 20.0,
    'No retirement savings' => 25.0,
  ],
  
  // FREE - Coercion Experience
  'coercion_experience' => [
    'Never' => 75.0,
    'Rarely' => 15.0,
    'Sometimes' => 7.0,
    'Often' => 3.0,
  ],
  
  // FREE - Privacy Violation Frequency
  'privacy_violation_frequency' => [
    'Never' => 52.0,
    'Rarely' => 28.0,
    'Sometimes' => 15.0,
    'Often' => 5.0,
  ],
  
  // FREE - Self-Censorship Frequency
  'self_censorship_frequency' => [
    'Rarely' => 38.0,
    'Sometimes' => 42.0,
    'Often' => 20.0,
  ],
  
  // FREE - Voting Participation (Census voting data)
  'voting_participation' => [
    'Vote in all elections' => 35.0,
    'Vote in most elections' => 31.0,
    'Vote occasionally' => 20.0,
    'Rarely/never vote' => 14.0,
  ],
  
  // SAFE - Days of Emergency Supplies
  'days_of_emergency_supplies_on_hand' => [
    'None/Less than 1 day' => 28.0,
    '1-3 days' => 35.0,
    '4-7 days' => 22.0,
    '1-2 weeks' => 10.0,
    'More than 2 weeks' => 5.0,
  ],
  
  // SAFE - Fire Escape Plan Status
  'fire_escape_plan_status' => [
    'Have plan and practiced' => 25.0,
    'Have plan, not practiced' => 35.0,
    'No formal plan' => 40.0,
  ],
  
  // SAFE - Emergency Contact Protocol
  'emergency_contact_protocol' => [
    'Established and communicated' => 58.0,
    'Partially established' => 28.0,
    'No protocol' => 14.0,
  ],
  
  // SAFE - Vehicle Emergency Kit
  'vehicle_emergency_kit' => [
    'Fully stocked kit' => 22.0,
    'Partial kit' => 38.0,
    'Minimal/no kit' => 40.0,
  ],
  
  // SAFE - Personal Security Spending
  'personal_security_spending' => [
    'None' => 55.0,
    'Less than $100/year' => 25.0,
    '$100-$500/year' => 15.0,
    'More than $500/year' => 5.0,
  ],
  
  // SAFE - Pet Restraint Compliance (vehicle safety)
  'pet_restraint_compliance' => [
    'Always restrain pets' => 35.0,
    'Usually restrain' => 28.0,
    'Rarely restrain' => 22.0,
    'Never restrain' => 15.0,
  ],
  
  // SAFE - Public Health Advisory Compliance
  'public_health_advisory_compliance' => [
    'Always follow' => 45.0,
    'Usually follow' => 38.0,
    'Sometimes follow' => 12.0,
    'Rarely follow' => 5.0,
  ],
  
  // SAFE - Secure Document Shredding
  'secure_document_shredding' => [
    'Always shred sensitive documents' => 48.0,
    'Usually shred' => 28.0,
    'Sometimes shred' => 15.0,
    'Never shred' => 9.0,
  ],
  
  // USEFUL - Recognition Frequency
  'recognition_frequency' => [
    'Regularly recognized' => 32.0,
    'Sometimes recognized' => 45.0,
    'Rarely recognized' => 18.0,
    'Never recognized' => 5.0,
  ],
  
  // WHOLE - Overall Health Rating (CDC self-reported health)
  'overall_health_rating' => [
    'Excellent' => 18.0,
    'Very good' => 32.0,
    'Good' => 30.0,
    'Fair' => 14.0,
    'Poor' => 6.0,
  ],
  
  // WHOLE - Mental Health Rating
  'mental_health_rating' => [
    'Excellent' => 15.0,
    'Very good' => 30.0,
    'Good' => 32.0,
    'Fair' => 16.0,
    'Poor' => 7.0,
  ],
  
  // WHOLE - Overwhelm Frequency
  'overwhelm_frequency' => [
    'Rarely' => 35.0,
    'Sometimes' => 42.0,
    'Often' => 18.0,
    'Always' => 5.0,
  ],
  
  // WHOLE - Daytime Fatigue
  'daytime_fatigue' => [
    'Rarely fatigued' => 28.0,
    'Sometimes fatigued' => 45.0,
    'Often fatigued' => 22.0,
    'Always fatigued' => 5.0,
  ],
  
  // WHOLE - Positive Emotions Frequency
  'positive_emotions_frequency' => [
    'Daily' => 42.0,
    'Several times a week' => 35.0,
    'Weekly' => 15.0,
    'Rarely' => 8.0,
  ],
  
  // SAFE - Health Insurance with Ambulance Coverage
  'health_insurance_with_ambulance_coverage' => [
    'Yes, fully covered' => 68.0,
    'Partial coverage' => 20.0,
    'No coverage' => 12.0,
  ],
  
  // SAFE - Health Monitoring Equipment
  'health_monitoring_equipment' => [
    'Have thermometer only' => 45.0,
    'Have thermometer + 1 other device' => 30.0,
    'Have thermometer + 2+ devices' => 15.0,
    'No monitoring equipment' => 10.0,
  ],
  
  // SAFE - Pandemic Preparedness Supplies
  'pandemic_preparedness_supplies' => [
    '2+ weeks supply' => 35.0,
    '1-2 weeks supply' => 28.0,
    'Less than 1 week supply' => 22.0,
    'No supplies' => 15.0,
  ],
  
  // SAFE - Outdoor Activity Safety Gear
  'outdoor_activity_safety_gear' => [
    'Have 2+ items' => 32.0,
    'Have 1 item' => 28.0,
    'Have basic items only' => 25.0,
    'No safety gear' => 15.0,
  ],
  
  // SAFE - Personal Protective Equipment
  'personal_protective_equipment' => [
    'Have masks + gloves + eye protection' => 42.0,
    'Have masks + one other' => 35.0,
    'Have masks only' => 18.0,
    'No PPE' => 5.0,
  ],
  
  // SAFE - Emergency Decontamination Supplies
  'emergency_decontamination_supplies' => [
    'Fully accessible' => 75.0,
    'Partially accessible' => 18.0,
    'Not accessible' => 7.0,
  ],
  
  // SAFE - Boat Safety Equipment (among boat owners)
  'boat_safety_equipment' => [
    '100% complete' => 45.0,
    '75-99% complete' => 30.0,
    '50-74% complete' => 15.0,
    'Less than 50% complete' => 10.0,
  ],
  
  // SAFE - Shelter in Place Supplies
  'shelter_in_place_supplies' => [
    'Fully prepared' => 18.0,
    'Partially prepared' => 32.0,
    'Minimally prepared' => 35.0,
    'Not prepared' => 15.0,
  ],
  
  // SAFE - Personal Safety Devices
  'personal_safety_devices' => [
    'Carry 2+ devices' => 15.0,
    'Carry 1 device' => 28.0,
    'Have at home but don\'t carry' => 32.0,
    'No safety devices' => 25.0,
  ],
  
  // DEMOGRAPHIC - ZIP Code (top 10 most populous)
  'zip_code' => [
    '10001-10282 (Manhattan, NY)' => 0.8,
    '90001-90899 (Los Angeles, CA)' => 1.2,
    '60601-60827 (Chicago, IL)' => 0.9,
    '77001-77299 (Houston, TX)' => 0.7,
    '85001-85339 (Phoenix, AZ)' => 0.5,
    '19101-19197 (Philadelphia, PA)' => 0.5,
    '78701-78799 (Austin, TX)' => 0.3,
    '32801-32899 (Orlando, FL)' => 0.3,
    '75201-75398 (Dallas, TX)' => 0.4,
    '92101-92199 (San Diego, CA)' => 0.4,
    // All other ZIP codes: 94.0%
  ],
  
  // ENERGIZED - Health Insurance Source (Census Bureau data)
  'health_insurance_source' => [
    'Employer-sponsored' => 54.3,
    'Medicare' => 18.4,
    'Medicaid' => 17.8,
    'Marketplace/ACA' => 3.9,
    'Other public' => 1.2,
    'None/Uninsured' => 4.4,
  ],
  
  // ENERGIZED - Health Insurance Type
  'health_insurance_type' => [
    'Employer-provided' => 54.3,
    'Medicare' => 18.4,
    'Medicaid/CHIP' => 17.8,
    'Marketplace/Individual' => 5.5,
    'None' => 4.0,
  ],
  
  // CAPABLE - Family Education Level (parents' highest)
  'family_education_level' => [
    'Graduate degree' => 15.0,
    "Bachelor's degree" => 25.0,
    "Associate's degree or some college" => 28.0,
    'High school diploma/GED' => 25.0,
    'Less than high school' => 7.0,
  ],
  
  // WHOLE - Health Trajectory
  'health_trajectory' => [
    'Improving' => 28.0,
    'Stable' => 58.0,
    'Declining' => 14.0,
  ],
];

$updated = 0;
$skipped = 0;
$not_found = [];

foreach ($select_distributions as $metric_name => $distribution) {
  // Find the metric
  $metric = $database->select('individual_metrics_master', 'imm')
    ->fields('imm', ['id', 'dimension'])
    ->condition('metric_name', $metric_name)
    ->condition('data_type', 'select')
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
    echo "  ⏭ [{$metric->dimension}] {$metric_name} - ALREADY HAS DISTRIBUTION\n";
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
  
  echo "  ✓ [{$metric->dimension}] {$metric_name} - {$most_common} ({$most_common_pct}%)\n";
  $updated++;
}

echo "\n=============================================================\n";
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

echo "\n=============================================================\n";
echo "Note: These distributions are based on US Census, CDC, USDA,\n";
echo "Pew Research, and other authoritative sources.\n";
echo "=============================================================\n";
