<?php

/**
 * Reclassify text metrics to their correct data types.
 * Most text fields are actually numeric or select fields.
 */

use Drupal\Core\Database\Database;

$database = Database::getConnection();

echo "Reclassifying text metrics to correct data types...\n";
echo "====================================================\n\n";

// Convert to SELECT (categorical)
$text_to_select = [
  'health_insurance_source',
  'health_insurance_type',
  'family_education_level',
  'health_trajectory',
];

// Convert to NUMERIC (all the rest - measurements, amounts, ratios, frequencies, scores)
$text_to_numeric = [
  // CAPABLE
  'class_rank',
  'college_gpa',
  'counselor_ratio',
  'financial_aid_received',
  'gpa_in_high_school',
  'graduate_debt',
  'graduation_rate',
  'job_placement_rate',
  'loan_payment_to_income_ratio',
  'standardized_test_scores',
  'student_loan_debt',
  'workshop_seminar_attendance',
  
  // CONNECTED
  'community_center_usage',
  'community_meeting_attendance',
  'cultural_event_attendance',
  'library_materials_borrowed',
  'library_visits',
  'meeting_attendance_frequency',
  'museum_theater_visits',
  'neighborhood_events_attendance',
  'park_usage_frequency',
  'playdates_frequency',
  'social_activity_participation',
  'transit_frequency',
  
  // ENERGIZED
  'annual_income',
  'assets_to_liabilities_ratio',
  'benefits_value',
  'budget_variance',
  'checking_account_balance',
  'checking_account_fees',
  'check_cashing_fees',
  'commute_cost',
  'commute_time',
  'credit_card_debt',
  'credit_score',
  'credit_utilization',
  'crime_rate',
  'debt_to_income_ratio',
  'deductible_amount',
  'dental_visit_frequency',
  'electric_bill_amount',
  'food_pantry_utilization',
  'gas_bill_amount',
  'gig_economy_earnings',
  'home_equity',
  'home_value',
  'housing_affordability_ratio',
  'hsa_fsa_balance',
  'hsa_fsa_contributions',
  'income_growth',
  'insurance_premium',
  'internet_cost',
  'internet_speed',
  'interview_conversion_rate',
  'investment_account_balance',
  'investment_costs',
  'job_applications_submitted',
  'life_insurance_coverage',
  'liquidity_ratio',
  'living_wage_comparison',
  'meal_skipping_frequency',
  'medical_debt',
  'medical_debt_amount',
  'mental_health_visit_frequency',
  'mental_health_wait_times',
  'monthly_medication_cost',
  'months_of_liquid_savings',
  'mortgage_payment_to_income_ratio',
  'neighborhood_median_income',
  'net_worth',
  'net_worth_growth_rate',
  'number_of_specialists',
  'out_of_pocket_maximum',
  'overdraft_frequency',
  'pcp_visit_frequency',
  'phone_bill_amount',
  'power_outage_frequency',
  'recent_credit_inquiries',
  'rent_payment_to_income_ratio',
  'retirement_income_projection',
  'retirement_savings_rate',
  'savings_account_balance',
  'shelter_utilization',
  'snap_benefit_amount',
  'specialist_out_of_pocket',
  'total_utility_costs',
  'transportation_proximity',
  'trash_collection_frequency',
  'underinsurance_risk',
  'utility_shutoff_history',
  'waste_disposal_cost',
  'water_bill_amount',
  'years_to_next_promotion',
  
  // FREE
  'neighborhood_school_quality',
  'voter_turnout_rate',
  
  // SAFE
  '911_call_response_time',
  'emergency_preparedness_spending',
  'fire_risk_mitigation_spending',
  'home_security_spending',
  'social_isolation_prevention',
  'vehicle_safety_rating',
  'water_storage',
  
  // USEFUL
  'charitable_giving_amount',
  'creative_income',
  'giving_as___of_income',
  'number_of_causes_supported',
  
  // WHOLE
  'dental_checkups_frequency',
  'exercise_intensity',
  'primary_care_visits',
  'therapy_frequency',
];

$converted_to_select = 0;
$converted_to_numeric = 0;
$not_found = [];

// Convert to SELECT
echo "Converting to SELECT (categorical)...\n";
foreach ($text_to_select as $metric_name) {
  $count = $database->update('individual_metrics_master')
    ->fields(['data_type' => 'select'])
    ->condition('metric_name', $metric_name)
    ->condition('data_type', 'text')
    ->execute();
  
  if ($count > 0) {
    echo "  ✓ {$metric_name} → select\n";
    $converted_to_select++;
  } else {
    $not_found[] = $metric_name;
  }
}

// Convert to NUMERIC
echo "\nConverting to NUMERIC...\n";
foreach ($text_to_numeric as $metric_name) {
  $count = $database->update('individual_metrics_master')
    ->fields(['data_type' => 'numeric'])
    ->condition('metric_name', $metric_name)
    ->condition('data_type', 'text')
    ->execute();
  
  if ($count > 0) {
    echo "  ✓ {$metric_name}\n";
    $converted_to_numeric++;
  } else {
    $not_found[] = $metric_name;
  }
}

echo "\n====================================================\n";
echo "Summary:\n";
echo "  Converted to SELECT: {$converted_to_select}\n";
echo "  Converted to NUMERIC: {$converted_to_numeric}\n";
echo "  Not found: " . count($not_found) . "\n";

if (!empty($not_found)) {
  echo "\nMetrics not found:\n";
  foreach ($not_found as $name) {
    echo "  - {$name}\n";
  }
}

// Check remaining text metrics
$remaining = $database->select('individual_metrics_master', 'imm')
  ->condition('data_type', 'text')
  ->countQuery()
  ->execute()
  ->fetchField();

echo "\nRemaining text metrics: {$remaining}\n";

echo "\n====================================================\n";
echo "Next step: Run populate scripts to generate distributions\n";
echo "for the newly converted numeric metrics.\n";
echo "====================================================\n";
