<?php

/**
 * @file
 * Populate comprehensive distribution data for all boolean and select demographics.
 * Based on US Census, Pew Research, and other authoritative sources.
 * 
 * Run via: drush php:script populate_all_distribution_data.php
 */

use Drupal\Core\Database\Database;

$database = Database::getConnection();

// Comprehensive distribution data based on US demographics research
$distributions = [
  // Q212: Sex Assigned at Birth
  'sex_assigned_at_birth' => ['json' => '{"Male": 49.5, "Female": 50.4, "Intersex": 0.1}', 'common' => 'Female', 'pct' => 50.4],
  'legal_sex_marker' => ['json' => '{"M": 49.5, "F": 50.3, "X": 0.2}', 'common' => 'F', 'pct' => 50.3],
  
  // Q213: Gender Identity
  'gender_identity' => ['json' => '{"Man": 49.0, "Woman": 49.5, "Non-binary": 1.0, "Trans man": 0.2, "Trans woman": 0.2, "Other": 0.1}', 'common' => 'Woman', 'pct' => 49.5],
  'pronouns' => ['json' => '{"he/him": 49.0, "she/her": 49.5, "they/them": 1.3, "other": 0.2}', 'common' => 'she/her', 'pct' => 49.5],
  'transgender_status' => ['json' => '{"Cisgender": 98.6, "Transgender": 1.4}', 'common' => 'Cisgender', 'pct' => 98.6],
  'gender_expression' => ['json' => '{"Masculine": 48.0, "Feminine": 48.0, "Androgynous": 3.0, "Fluid": 1.0}', 'common' => 'Feminine', 'pct' => 48.0],
  'gender_transition_status' => ['json' => '{"N/A": 98.6, "Not transitioning": 0.8, "Considering": 0.3, "In process": 0.2, "Completed": 0.1}', 'common' => 'N/A', 'pct' => 98.6],
  
  // Q214: Sexual Orientation
  'sexual_orientation' => ['json' => '{"Heterosexual": 89.0, "Gay": 2.5, "Lesbian": 1.5, "Bisexual": 5.0, "Pansexual": 0.8, "Asexual": 0.5, "Queer": 0.5, "Other": 0.2}', 'common' => 'Heterosexual', 'pct' => 89.0],
  'lgbtq__identification' => ['json' => '{"No": 92.5, "Yes": 7.5}', 'common' => 'No', 'pct' => 92.5],
  
  // Q215: Race
  'race___single_or_multiple' => ['json' => '{"White": 59.3, "Black/African American": 13.6, "Asian": 6.1, "Native American": 1.3, "Pacific Islander": 0.3, "Multiracial": 19.4}', 'common' => 'White', 'pct' => 59.3],
  'asian_subgroup' => ['json' => '{"Chinese": 23.0, "Filipino": 19.0, "Indian": 21.0, "Vietnamese": 11.0, "Korean": 10.0, "Japanese": 8.0, "Other": 8.0}', 'common' => 'Chinese', 'pct' => 23.0],
  'native_tribe_affiliation' => ['json' => '{"No": 98.7, "Yes": 1.3}', 'common' => 'No', 'pct' => 98.7],
  
  // Q216: Ethnicity
  'ethnicity_hispanic_latino' => ['json' => '{"No": 81.3, "Yes": 18.7}', 'common' => 'No', 'pct' => 81.3],
  'hispanic_latino_origin' => ['json' => '{"Mexican": 61.4, "Puerto Rican": 9.6, "Cuban": 3.9, "Central American": 8.8, "South American": 6.4, "Other": 9.9}', 'common' => 'Mexican', 'pct' => 61.4],
  'ethnic_cultural_identity' => ['json' => '{"Strongly": 42.0, "Somewhat": 38.0, "Not at all": 20.0}', 'common' => 'Strongly', 'pct' => 42.0],
  
  // Q217: Household Income
  'household_income_bracket' => ['json' => '{"<$25K": 18.7, "$25-50K": 19.2, "$50-75K": 17.1, "$75-100K": 13.8, "$100-150K": 16.2, "$150-200K": 7.5, ">$200K": 7.5}', 'common' => '$25-50K', 'pct' => 19.2],
  'poverty_status' => ['json' => '{"No": 89.1, "Yes": 10.9}', 'common' => 'No', 'pct' => 89.1],
  
  // Q218: Personal Income
  'personal_income_bracket' => ['json' => '{"<$15K": 22.3, "$15-25K": 14.2, "$25-40K": 17.8, "$40-60K": 18.5, "$60-80K": 11.7, "$80-100K": 7.2, ">$100K": 8.3}', 'common' => '<$15K', 'pct' => 22.3],
  
  // Q219: Education
  'highest_degree_earned' => ['json' => '{"Less than HS": 9.9, "HS/GED": 27.0, "Some college": 20.3, "Associate": 8.5, "Bachelor\'s": 23.5, "Master\'s": 9.0, "Doctorate": 1.4, "Professional": 0.4}', 'common' => 'HS/GED', 'pct' => 27.0],
  'currently_enrolled_in_school' => ['json' => '{"No": 83.5, "Yes": 16.5}', 'common' => 'No', 'pct' => 83.5],
  
  // Q220: Employment Status
  'current_employment_status' => ['json' => '{"Employed FT": 49.8, "Employed PT": 13.2, "Unemployed": 3.7, "Retired": 18.5, "Disabled": 4.8, "Student": 6.5, "Homemaker": 2.5, "Other": 1.0}', 'common' => 'Employed FT', 'pct' => 49.8],
  'employment_sector' => ['json' => '{"Private": 71.0, "Public/government": 14.5, "Nonprofit": 9.2, "Self-employed": 5.3}', 'common' => 'Private', 'pct' => 71.0],
  'multiple_jobs_status' => ['json' => '{"No": 82.4, "Yes": 17.6}', 'common' => 'No', 'pct' => 82.4],
  
  // Q221: Occupation
  'occupation_category' => ['json' => '{"Professional": 27.8, "Management": 16.5, "Service": 18.2, "Sales": 10.1, "Office": 14.7, "Production": 6.4, "Construction": 4.2, "Farming": 1.2, "Other": 0.9}', 'common' => 'Professional', 'pct' => 27.8],
  'industry_sector' => ['json' => '{"Healthcare": 13.2, "Education": 9.1, "Retail": 11.5, "Manufacturing": 8.5, "Technology": 6.8, "Government": 14.5, "Finance": 5.7, "Other": 30.7}', 'common' => 'Other', 'pct' => 30.7],
  
  // Q222: Marital Status
  'marital_status' => ['json' => '{"Never married": 34.5, "Married": 48.2, "Divorced": 10.9, "Separated": 1.7, "Widowed": 4.0, "Domestic partnership": 0.7}', 'common' => 'Married', 'pct' => 48.2],
  'cohabitation_status' => ['json' => '{"No": 82.7, "Yes": 17.3}', 'common' => 'No', 'pct' => 82.7],
  
  // Q223: Household Composition
  'household_type' => ['json' => '{"Single person": 28.5, "Couple": 29.8, "Family with children": 28.3, "Roommates": 4.2, "Multigenerational": 7.8, "Other": 1.4}', 'common' => 'Couple', 'pct' => 29.8],
  
  // Q225: Caregiving
  'primary_caregiver_status' => ['json' => '{"No": 69.7, "Yes": 30.3}', 'common' => 'No', 'pct' => 69.7],
  'elder_care_responsibilities' => ['json' => '{"No": 84.2, "Yes": 15.8}', 'common' => 'No', 'pct' => 84.2],
  'special_needs_caregiving' => ['json' => '{"No": 87.3, "Yes": 12.7}', 'common' => 'No', 'pct' => 87.3],
  
  // Q226: Geographic Type
  'geographic_location_type' => ['json' => '{"Urban": 31.3, "Suburban": 51.9, "Rural": 14.7, "Remote rural": 2.1}', 'common' => 'Suburban', 'pct' => 51.9],
  
  // Q228: Region (derived from state)
  
  // Q230: Housing Tenure
  'housing_tenure' => ['json' => '{"Own": 65.5, "Rent": 30.8, "Live with family/friends": 2.9, "Homeless": 0.5, "Group quarters": 0.3}', 'common' => 'Own', 'pct' => 65.5],
  'subsidized_housing' => ['json' => '{"No": 92.8, "Yes": 7.2}', 'common' => 'No', 'pct' => 92.8],
  
  // Q231: Immigration Status
  'citizenship_status' => ['json' => '{"U.S. born citizen": 86.1, "Naturalized citizen": 7.2, "Permanent resident": 4.4, "Visa holder": 1.8, "Undocumented": 0.4, "Other": 0.1}', 'common' => 'U.S. born citizen', 'pct' => 86.1],
  'immigration_generation' => ['json' => '{"3rd+ gen": 78.5, "2nd gen": 12.3, "1st gen": 9.2}', 'common' => '3rd+ gen', 'pct' => 78.5],
  
  // Q232: Language
  'primary_language' => ['json' => '{"English": 78.3, "Spanish": 13.5, "Chinese": 1.2, "Tagalog": 0.5, "Vietnamese": 0.5, "Arabic": 0.4, "French": 0.6, "Korean": 0.4, "Russian": 0.3, "German": 0.4, "Other": 3.9}', 'common' => 'English', 'pct' => 78.3],
  'english_proficiency_level' => ['json' => '{"Native": 78.5, "Fluent": 12.8, "Good": 5.2, "Fair": 2.3, "Poor": 0.9, "None": 0.3}', 'common' => 'Native', 'pct' => 78.5],
  
  // Q233: Disability Status
  'disability_status_overall' => ['json' => '{"No": 73.7, "Yes": 26.3}', 'common' => 'No', 'pct' => 73.7],
  'disability_type___physical' => ['json' => '{"No": 86.9, "Yes": 13.1}', 'common' => 'No', 'pct' => 86.9],
  'disability_type___sensory' => ['json' => '{"No": 93.4, "Yes": 6.6}', 'common' => 'No', 'pct' => 93.4],
  'disability_type___cognitive' => ['json' => '{"No": 91.8, "Yes": 8.2}', 'common' => 'No', 'pct' => 91.8],
  'disability_type___mental_health' => ['json' => '{"No": 82.6, "Yes": 17.4}', 'common' => 'No', 'pct' => 82.6],
  
  // Q234: Health Insurance
  'insurance_type___primary' => ['json' => '{"Employer": 54.3, "Medicare": 18.4, "Medicaid": 17.8, "Marketplace/ACA": 3.9, "Military/VA": 2.3, "Uninsured": 3.3}', 'common' => 'Employer', 'pct' => 54.3],
  'insurance_adequacy' => ['json' => '{"Yes": 66.8, "No": 33.2}', 'common' => 'Yes', 'pct' => 66.8],
  
  // Q235: Veteran Status
  'veteran_status' => ['json' => '{"No": 92.8, "Yes": 7.2}', 'common' => 'No', 'pct' => 92.8],
  
  // Q236: Religion
  'religious_affiliation' => ['json' => '{"Christian": 63.0, "No religion": 29.0, "Jewish": 2.0, "Muslim": 1.1, "Hindu": 0.7, "Buddhist": 0.7, "Spiritual not religious": 2.5, "Other": 1.0}', 'common' => 'Christian', 'pct' => 63.0],
  'importance_of_religion' => ['json' => '{"Very": 41.0, "Somewhat": 28.0, "Not very": 16.0, "Not at all": 15.0}', 'common' => 'Very', 'pct' => 41.0],
  
  // Q237: Political Views
  'political_party_affiliation' => ['json' => '{"Democrat": 30.0, "Republican": 25.0, "Independent": 41.0, "Libertarian": 1.5, "Green": 0.5, "Other": 1.0, "None": 1.0}', 'common' => 'Independent', 'pct' => 41.0],
  
  // Q239: Technology Access
  'internet_access_at_home' => ['json' => '{"Yes": 84.7, "No": 15.3}', 'common' => 'Yes', 'pct' => 84.7],
  
  // Q240: Transportation
  'primary_transportation_mode' => ['json' => '{"Personal vehicle": 85.3, "Public transit": 5.1, "Walk": 2.8, "Bike": 0.5, "Rideshare": 0.6, "Other": 5.7}', 'common' => 'Personal vehicle', 'pct' => 85.3],
  'vehicle_ownership' => ['json' => '{"Yes": 88.6, "No": 11.4}', 'common' => 'Yes', 'pct' => 88.6],
];

$updated = 0;
$skipped = 0;

echo "Populating comprehensive distribution data for all demographic metrics...\n\n";

foreach ($distributions as $metric_name => $data) {
  // Check if metric exists
  $exists = $database->select('individual_metrics_master', 'm')
    ->fields('m', ['id', 'data_type'])
    ->condition('metric_name', $metric_name)
    ->condition('dimension', 'DEMOGRAPHIC')
    ->execute()
    ->fetchAssoc();
  
  if (!$exists) {
    echo "  ⚠ {$metric_name} - NOT FOUND\n";
    $skipped++;
    continue;
  }
  
  $result = $database->update('individual_metrics_master')
    ->fields([
      'distribution_data' => $data['json'],
      'most_common_value' => $data['common'],
      'most_common_percentage' => $data['pct'],
    ])
    ->condition('metric_name', $metric_name)
    ->condition('dimension', 'DEMOGRAPHIC')
    ->execute();
  
  if ($result) {
    $updated++;
    echo "  ✓ {$metric_name} ({$exists['data_type']}) - {$data['common']} ({$data['pct']}%)\n";
  }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Complete!\n";
echo "  Updated: {$updated} metrics\n";
echo "  Skipped: {$skipped} metrics\n";
echo str_repeat('=', 60) . "\n";

// Summary by data type
$summary = $database->query("
  SELECT data_type, 
         COUNT(*) as total,
         SUM(CASE WHEN distribution_data IS NOT NULL THEN 1 ELSE 0 END) as with_distribution
  FROM individual_metrics_master
  WHERE dimension = 'DEMOGRAPHIC'
  GROUP BY data_type
  ORDER BY data_type
")->fetchAll();

echo "\nDistribution data coverage:\n";
foreach ($summary as $row) {
  $coverage = $row->total > 0 ? round(($row->with_distribution / $row->total) * 100, 1) : 0;
  echo "  {$row->data_type}: {$row->with_distribution}/{$row->total} ({$coverage}%)\n";
}
