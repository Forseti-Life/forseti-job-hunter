<?php

/**
 * Populate newly reclassified numeric metrics with normalized distributions.
 * These were previously text fields but are actually numeric measurements.
 */

use Drupal\Core\Database\Database;

$database = Database::getConnection();

echo "Populating reclassified numeric metrics with distributions...\n";
echo "=============================================================\n\n";

/**
 * Generate normalized distribution data.
 */
function generateNormalizedDistribution($mean, $stddev) {
  $distribution = [];
  $metadata = [
    'mean' => $mean,
    'min_value' => $mean - (3 * $stddev),
    'max_value' => $mean + (3 * $stddev),
  ];
  
  for ($x = 0; $x <= 100; $x += 5) {
    $z = ($x - 50) / 16.67;
    $actual_value = $mean + ($z * $stddev);
    $pdf = (1 / (sqrt(2 * M_PI))) * exp(-0.5 * pow($z, 2));
    $distribution[(string)$x] = round($pdf * 250, 2);
  }
  
  $distribution['_metadata'] = $metadata;
  return $distribution;
}

// Numeric metrics with their population means and standard deviations
// Based on US Census, BLS, CDC, Federal Reserve, and other authoritative sources
$numeric_distributions = [
  // CAPABLE - Education metrics
  'gpa_in_high_school' => ['mean' => 3.0, 'stddev' => 0.5],
  'college_gpa' => ['mean' => 3.1, 'stddev' => 0.45],
  'class_rank' => ['mean' => 50.0, 'stddev' => 28.0], // percentile
  'standardized_test_scores' => ['mean' => 50.0, 'stddev' => 28.0], // percentile
  'counselor_ratio' => ['mean' => 482.0, 'stddev' => 200.0], // students per counselor (NCES)
  'graduation_rate' => ['mean' => 85.0, 'stddev' => 10.0], // %
  'job_placement_rate' => ['mean' => 75.0, 'stddev' => 15.0], // %
  'financial_aid_received' => ['mean' => 14000, 'stddev' => 8000], // dollars/year
  'student_loan_debt' => ['mean' => 37000, 'stddev' => 20000], // total debt
  'graduate_debt' => ['mean' => 45000, 'stddev' => 35000],
  'loan_payment_to_income_ratio' => ['mean' => 8.0, 'stddev' => 6.0], // %
  'workshop_seminar_attendance' => ['mean' => 4.0, 'stddev' => 5.0], // per year
  
  // CONNECTED - Social participation
  'library_visits' => ['mean' => 3.0, 'stddev' => 4.0], // per month
  'library_materials_borrowed' => ['mean' => 4.0, 'stddev' => 6.0], // per month
  'community_center_usage' => ['mean' => 2.0, 'stddev' => 3.0], // per month
  'park_usage_frequency' => ['mean' => 6.0, 'stddev' => 6.0], // per month
  'social_activity_participation' => ['mean' => 4.0, 'stddev' => 4.0], // per month
  'meeting_attendance_frequency' => ['mean' => 2.0, 'stddev' => 3.0], // per month
  'community_meeting_attendance' => ['mean' => 3.0, 'stddev' => 4.0], // per year
  'cultural_event_attendance' => ['mean' => 6.0, 'stddev' => 8.0], // per year
  'museum_theater_visits' => ['mean' => 3.0, 'stddev' => 5.0], // per year
  'neighborhood_events_attendance' => ['mean' => 5.0, 'stddev' => 6.0], // per year
  'playdates_frequency' => ['mean' => 2.0, 'stddev' => 2.0], // per week
  'transit_frequency' => ['mean' => 4.0, 'stddev' => 3.0], // buses/hour
  
  // ENERGIZED - Income & Financial
  'annual_income' => ['mean' => 70000, 'stddev' => 45000],
  'net_worth' => ['mean' => 121000, 'stddev' => 250000],
  'credit_score' => ['mean' => 695, 'stddev' => 85],
  'savings_account_balance' => ['mean' => 12000, 'stddev' => 20000],
  'checking_account_balance' => ['mean' => 4500, 'stddev' => 6000],
  'investment_account_balance' => ['mean' => 85000, 'stddev' => 150000],
  'hsa_fsa_balance' => ['mean' => 2500, 'stddev' => 3000],
  'home_value' => ['mean' => 295000, 'stddev' => 175000],
  'home_equity' => ['mean' => 120000, 'stddev' => 140000],
  'credit_card_debt' => ['mean' => 6200, 'stddev' => 8000],
  'medical_debt' => ['mean' => 2500, 'stddev' => 8000],
  'medical_debt_amount' => ['mean' => 2500, 'stddev' => 8000],
  'life_insurance_coverage' => ['mean' => 180000, 'stddev' => 220000],
  
  // ENERGIZED - Ratios & Percentages
  'debt_to_income_ratio' => ['mean' => 36.0, 'stddev' => 22.0], // %
  'mortgage_payment_to_income_ratio' => ['mean' => 28.0, 'stddev' => 12.0], // %
  'rent_payment_to_income_ratio' => ['mean' => 30.0, 'stddev' => 15.0], // %
  'housing_affordability_ratio' => ['mean' => 30.0, 'stddev' => 15.0], // %
  'credit_utilization' => ['mean' => 30.0, 'stddev' => 25.0], // %
  'budget_variance' => ['mean' => 5.0, 'stddev' => 15.0], // % over/under
  'assets_to_liabilities_ratio' => ['mean' => 2.5, 'stddev' => 3.0],
  'liquidity_ratio' => ['mean' => 3.0, 'stddev' => 4.0], // months of expenses
  'months_of_liquid_savings' => ['mean' => 3.0, 'stddev' => 4.0],
  'retirement_savings_rate' => ['mean' => 8.0, 'stddev' => 6.0], // %
  'income_growth' => ['mean' => 3.0, 'stddev' => 8.0], // % per year
  'net_worth_growth_rate' => ['mean' => 5.0, 'stddev' => 15.0], // %
  'investment_costs' => ['mean' => 0.8, 'stddev' => 0.6], // % of portfolio
  'living_wage_comparison' => ['mean' => 110.0, 'stddev' => 40.0], // % of living wage
  'interview_conversion_rate' => ['mean' => 15.0, 'stddev' => 12.0], // per 100 apps
  
  // ENERGIZED - Monthly expenses
  'commute_cost' => ['mean' => 250, 'stddev' => 200],
  'insurance_premium' => ['mean' => 450, 'stddev' => 300],
  'electric_bill_amount' => ['mean' => 115, 'stddev' => 60],
  'gas_bill_amount' => ['mean' => 75, 'stddev' => 50],
  'water_bill_amount' => ['mean' => 70, 'stddev' => 40],
  'internet_cost' => ['mean' => 65, 'stddev' => 25],
  'phone_bill_amount' => ['mean' => 85, 'stddev' => 40],
  'total_utility_costs' => ['mean' => 320, 'stddev' => 150],
  'waste_disposal_cost' => ['mean' => 35, 'stddev' => 20],
  'monthly_medication_cost' => ['mean' => 150, 'stddev' => 200],
  'checking_account_fees' => ['mean' => 12, 'stddev' => 15],
  'check_cashing_fees' => ['mean' => 8, 'stddev' => 12],
  
  // ENERGIZED - Annual/Other amounts
  'benefits_value' => ['mean' => 15000, 'stddev' => 10000],
  'deductible_amount' => ['mean' => 2500, 'stddev' => 2000],
  'out_of_pocket_maximum' => ['mean' => 6000, 'stddev' => 3000],
  'hsa_fsa_contributions' => ['mean' => 2000, 'stddev' => 1500],
  'gig_economy_earnings' => ['mean' => 800, 'stddev' => 1200], // per month
  'snap_benefit_amount' => ['mean' => 250, 'stddev' => 150], // per month
  'retirement_income_projection' => ['mean' => 3500, 'stddev' => 2000], // monthly
  
  // ENERGIZED - Time & Frequency
  'commute_time' => ['mean' => 27.0, 'stddev' => 18.0], // minutes
  'transportation_proximity' => ['mean' => 25.0, 'stddev' => 20.0], // minutes
  'employment_duration' => ['mean' => 48.0, 'stddev' => 60.0], // months
  'unemployment_duration' => ['mean' => 5.0, 'stddev' => 8.0], // months
  'years_to_next_promotion' => ['mean' => 3.0, 'stddev' => 2.0],
  'job_applications_submitted' => ['mean' => 5.0, 'stddev' => 4.0], // per week
  
  // ENERGIZED - Healthcare
  'pcp_visit_frequency' => ['mean' => 2.5, 'stddev' => 2.0], // per year
  'dental_visit_frequency' => ['mean' => 1.8, 'stddev' => 1.2], // per year
  'mental_health_visit_frequency' => ['mean' => 2.0, 'stddev' => 4.0], // per month
  'mental_health_wait_times' => ['mean' => 3.0, 'stddev' => 4.0], // weeks
  'number_of_specialists' => ['mean' => 1.5, 'stddev' => 2.0],
  'specialist_out_of_pocket' => ['mean' => 75, 'stddev' => 60],
  'meal_skipping_frequency' => ['mean' => 1.0, 'stddev' => 2.0], // per week
  'food_pantry_utilization' => ['mean' => 0.5, 'stddev' => 2.0], // per month
  'shelter_utilization' => ['mean' => 0.0, 'stddev' => 15.0], // nights/year
  
  // ENERGIZED - Other
  'internet_speed' => ['mean' => 120, 'stddev' => 100], // Mbps
  'crime_rate' => ['mean' => 3.5, 'stddev' => 4.0], // per 1,000 residents
  'neighborhood_median_income' => ['mean' => 68000, 'stddev' => 35000],
  'power_outage_frequency' => ['mean' => 2.0, 'stddev' => 3.0], // per year
  'trash_collection_frequency' => ['mean' => 2.0, 'stddev' => 1.0], // per week
  'overdraft_frequency' => ['mean' => 1.5, 'stddev' => 3.0], // per year
  'recent_credit_inquiries' => ['mean' => 1.0, 'stddev' => 2.0],
  'utility_shutoff_history' => ['mean' => 0.2, 'stddev' => 0.8], // per year
  'underinsurance_risk' => ['mean' => 5000, 'stddev' => 15000],
  
  // FREE
  'neighborhood_school_quality' => ['mean' => 6.5, 'stddev' => 2.0], // 1-10 scale
  'voter_turnout_rate' => ['mean' => 65.0, 'stddev' => 25.0], // %
  
  // SAFE
  '911_call_response_time' => ['mean' => 8.0, 'stddev' => 5.0], // minutes
  'emergency_preparedness_spending' => ['mean' => 200, 'stddev' => 300], // annual
  'fire_risk_mitigation_spending' => ['mean' => 150, 'stddev' => 250],
  'home_security_spending' => ['mean' => 400, 'stddev' => 600],
  'social_isolation_prevention' => ['mean' => 12.0, 'stddev' => 10.0], // contacts/week
  'vehicle_safety_rating' => ['mean' => 4.0, 'stddev' => 1.0], // 1-5 stars
  'water_storage' => ['mean' => 3.0, 'stddev' => 8.0], // gallons
  
  // USEFUL
  'charitable_giving_amount' => ['mean' => 2500, 'stddev' => 5000], // annual
  'creative_income' => ['mean' => 1200, 'stddev' => 5000], // annual
  'giving_as___of_income' => ['mean' => 2.5, 'stddev' => 3.0], // %
  'number_of_causes_supported' => ['mean' => 3.0, 'stddev' => 3.0],
  
  // WHOLE
  'dental_checkups_frequency' => ['mean' => 1.8, 'stddev' => 1.0], // per year
  'exercise_intensity' => ['mean' => 3.0, 'stddev' => 2.5], // sessions/week
  'primary_care_visits' => ['mean' => 2.5, 'stddev' => 2.0], // per year
  'therapy_frequency' => ['mean' => 2.0, 'stddev' => 3.0], // per month
];

$updated = 0;
$skipped = 0;

foreach ($numeric_distributions as $metric_name => $params) {
  // Check if metric exists and is numeric
  $metric = $database->select('individual_metrics_master', 'imm')
    ->fields('imm', ['id', 'dimension', 'data_type', 'distribution_data'])
    ->condition('metric_name', $metric_name)
    ->execute()
    ->fetchObject();
  
  if (!$metric || $metric->data_type !== 'numeric') {
    continue;
  }
  
  if (!empty($metric->distribution_data)) {
    echo "  ⏭ [{$metric->dimension}] {$metric_name} - ALREADY HAS DISTRIBUTION\n";
    $skipped++;
    continue;
  }
  
  $distribution = generateNormalizedDistribution($params['mean'], $params['stddev']);
  
  // Update the metric
  $database->update('individual_metrics_master')
    ->fields([
      'distribution_data' => json_encode($distribution),
      'population_mean' => $params['mean'],
      'population_stddev' => $params['stddev'],
    ])
    ->condition('id', $metric->id)
    ->execute();
  
  echo "  ✓ [{$metric->dimension}] {$metric_name} - Mean: {$params['mean']}, SD: {$params['stddev']}\n";
  $updated++;
}

echo "\n=============================================================\n";
echo "Summary:\n";
echo "  Updated: {$updated} metrics\n";
echo "  Skipped: {$skipped} metrics (already had distribution)\n";
echo "=============================================================\n";
