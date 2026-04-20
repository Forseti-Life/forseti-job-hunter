# Demographic Questions - Redundancy Review

## Question 211: Age (5 metrics)
- ✅ **age_in_years** - Exact age in years (numeric)
- ⚠️ **age_category** - Age range: 18-24, 25-34, 35-44, 45-54, 55-64, 65-74, 75+ (numeric)
- ⚠️ **generation** - Generation: Gen Z, Millennial, Gen X, Boomer, Silent (select)
- ⚠️ **age_related_life_stage** - Life stage: young adult, midlife, older adult, senior (select)
- ❌ **birth_year** - Year of birth (text)

**REDUNDANCY**: Age category, generation, and life stage can all be calculated from age_in_years. Birth year is also redundant.
**RECOMMENDATION**: Keep only **age_in_years**. Calculate others as needed.

---

## Question 212: Sex Assigned at Birth (5 metrics)
- ✅ **sex_assigned_at_birth** - Male, Female, Intersex (select)
- ⚠️ **legal_sex_marker** - Sex on legal documents: M, F, X (select)
- ⚠️ **chromosomal_sex** - XX, XY, Other variation (select)
- ⚠️ **birth_certificate_sex** - Sex recorded at birth (text)
- ⚠️ **medical_sex_classification** - Sex for medical purposes (text)

**REDUNDANCY**: These are mostly overlapping concepts. Sex assigned at birth and birth certificate sex are essentially the same.
**RECOMMENDATION**: Keep **sex_assigned_at_birth** and **legal_sex_marker** (for legal documents). Remove the rest unless specific medical research needs chromosomal data.

---

## Question 213: Gender Identity (5 metrics)
- ✅ **gender_identity** - Man, Woman, Trans man, Trans woman, Non-binary, Genderqueer, Agender, Gender fluid, Other (select)
- ✅ **pronouns** - Preferred pronouns: he/him, she/her, they/them, other (select)
- ✅ **transgender_status** - Cisgender or Transgender (text)
- ✅ **gender_expression** - Masculine, Feminine, Androgynous, Fluid (select)
- ⚠️ **gender_transition_status** - Not transitioning, Considering, In process, Completed, N/A (select)

**REDUNDANCY**: Moderate. Gender identity and transgender status overlap somewhat but provide different information.
**RECOMMENDATION**: Keep all except possibly transition status (very personal, may have low response rate).

---

## Question 214: Sexual Orientation (5 metrics)
- ✅ **sexual_orientation** - Heterosexual/Straight, Gay, Lesbian, Bisexual, Pansexual, Asexual, Queer, Questioning, Other (select)
- ⚠️ **sexual_attraction_pattern** - Attracted to: men, women, all genders, no one, varies (select)
- ⚠️ **romantic_orientation** - Heteroromantic, Homoromantic, Biromantic, Panromantic, Aromantic, Other (select)
- ✅ **lgbtq__identification** - Identify as LGBTQ+: yes/no (boolean)
- ⚠️ **coming_out_status** - Out to: no one, few people, most people, everyone (select)

**REDUNDANCY**: Sexual orientation and attraction pattern are very similar. Romantic orientation is a nuanced distinction.
**RECOMMENDATION**: Keep **sexual_orientation** and **lgbtq__identification**. Remove attraction pattern and romantic orientation unless research specifically needs them.

---

## Question 215: Race (5 metrics)
- ✅ **race___single_or_multiple** - White, Black/African American, Asian, Native American, Pacific Islander, Multiracial (select)
- ⚠️ **racial_categories_endorsed** - Number of racial categories selected (numeric)
- ⚠️ **racial_self_identification** - How person describes own race (text)
- ⚠️ **asian_subgroup** - Chinese, Filipino, Indian, Vietnamese, Korean, Japanese, Other Asian (select)
- ⚠️ **native_tribe_affiliation** - Enrolled tribal member: yes/no, Tribe name (boolean)

**REDUNDANCY**: Racial categories endorsed can be calculated. Self-identification is open-ended version of single/multiple.
**RECOMMENDATION**: Keep **race___single_or_multiple**, **asian_subgroup** (for specificity when Asian selected), and **native_tribe_affiliation** (legal/political significance). Remove count and self-identification.

---

## Question 216: Ethnicity/Hispanic Origin (5 metrics)
- ✅ **ethnicity_hispanic_latino** - Hispanic or Latino: yes/no (boolean)
- ✅ **hispanic_latino_origin** - Mexican, Puerto Rican, Cuban, Central American, South American, Other (select)
- ⚠️ **country_of_family_origin** - Primary country of ethnic heritage (text)
- ⚠️ **ethnic_cultural_identity** - Identify with ethnic culture: strongly/somewhat/not at all (select)
- ⚠️ **generations_in_u_s_** - Number of generations family has been in U.S. (numeric)

**REDUNDANCY**: Hispanic yes/no and origin are complementary. Cultural identity and generations are separate dimensions.
**RECOMMENDATION**: Keep **ethnicity_hispanic_latino** and **hispanic_latino_origin**. Consider keeping **generations_in_u_s_** if immigrant experience is important. Remove country of origin (too broad) and cultural identity.

---

## Question 217: Household Income (5 metrics)
- ⚠️ **annual_household_income** - Total household income before taxes (text)
- ✅ **household_income_bracket** - <$25K, $25-50K, $50-75K, $75-100K, $100-150K, $150-200K, >$200K (select)
- ⚠️ **income_to_poverty_ratio** - Household income / federal poverty level (%) (text)
- ⚠️ **poverty_status** - Below poverty line: yes/no (boolean)
- ⚠️ **household_income_sources** - Number of income sources in household (numeric)

**REDUNDANCY**: Annual income and bracket are same thing (different precision). Poverty ratio and status are related.
**RECOMMENDATION**: Keep **household_income_bracket** (less sensitive than exact amount), **poverty_status**. Remove the others or calculate from bracket.

---

## Question 218: Personal Income (5 metrics)
- ⚠️ **personal_annual_income** - Individual income before taxes (text)
- ✅ **personal_income_bracket** - <$15K, $15-25K, $25-40K, $40-60K, $60-80K, $80-100K, >$100K (select)
- ⚠️ **earned_vs_unearned_income** - % from wages vs investment/benefits (text)
- ⚠️ **income_change_trajectory** - Income increasing/stable/decreasing past 3 years (numeric)
- ⚠️ **income_consistency** - Stable or variable income month-to-month (text)

**REDUNDANCY**: Personal income and bracket are duplicate. Trajectory and consistency measure different aspects.
**RECOMMENDATION**: Keep **personal_income_bracket** and possibly **income_change_trajectory**. Remove the others.

---

## Question 219: Education (5 metrics)
- ✅ **highest_degree_earned** - Less than HS, HS/GED, Some college, Associate, Bachelor's, Master's, Doctorate, Professional (select)
- ⚠️ **years_of_education_completed** - Total years of formal schooling (numeric)
- ⚠️ **field_of_study** - Primary field/major of highest degree (text)
- ⚠️ **currently_enrolled_in_school** - Enrolled in any educational program: yes/no (boolean)
- ⚠️ **educational_mobility** - First in family to achieve education level: yes/no (boolean)

**REDUNDANCY**: Degree earned and years completed overlap significantly. Field is only relevant if degree > HS.
**RECOMMENDATION**: Keep **highest_degree_earned** and **currently_enrolled_in_school**. Field could be conditional on degree level.

---

## Question 220: Employment Status (5 metrics)
- ✅ **current_employment_status** - Employed FT, Employed PT, Unemployed, Retired, Disabled, Student, Homemaker, Other (select)
- ⚠️ **work_hours_per_week** - Average hours worked per week (numeric)
- ⚠️ **employment_sector** - Private, Public/government, Nonprofit, Self-employed (select)
- ⚠️ **multiple_jobs_status** - Working multiple jobs: yes/no, If yes: how many (boolean)
- ⚠️ **union_membership** - Member of labor union: yes/no (boolean)

**REDUNDANCY**: Hours per week duplicates FT/PT distinction somewhat. Others are complementary.
**RECOMMENDATION**: Keep **current_employment_status**, **employment_sector**, **multiple_jobs_status**. Remove work hours (can infer from FT/PT).

---

## Question 221: Occupation Details (5 metrics)
- ✅ **occupation_category** - Management, Professional, Service, Sales, Office, Farming, Construction, Production, Other (select)
- ⚠️ **occupation_title** - Specific job title (text)
- ⚠️ **industry_sector** - Healthcare, Education, Retail, Manufacturing, Technology, Government, Finance, Other (select)
- ⚠️ **skill_level** - Unskilled, Semi-skilled, Skilled, Professional (select)
- ⚠️ **years_in_current_occupation** - Time in current occupation/field (text)

**REDUNDANCY**: Occupation category and skill level overlap significantly. Title is very specific.
**RECOMMENDATION**: Keep **occupation_category** and **industry_sector**. Remove skill level (can infer from category) and years in occupation.

---

## Question 222: Marital/Relationship Status (5 metrics)
- ✅ **marital_status** - Never married, Married, Divorced, Separated, Widowed, Domestic partnership/Civil union (select)
- ⚠️ **cohabitation_status** - Living with partner: yes/no (boolean)
- ⚠️ **years_in_current_relationship** - Years in current marriage/partnership (numeric)
- ⚠️ **marriage_count** - Number of times married (numeric)
- ⚠️ **relationship_satisfaction** - Satisfied with relationship status: yes/no (boolean)

**REDUNDANCY**: Marital status covers most information. Cohabitation provides additional detail.
**RECOMMENDATION**: Keep **marital_status**. Remove others except possibly **cohabitation_status** if domestic partnerships are important.

---

## Question 223: Household Composition (5 metrics)
- ✅ **household_size** - Total number of people in household (numeric)
- ✅ **household_type** - Single person, Couple, Family with children, Roommates, Multigenerational, Other (select)
- ⚠️ **number_of_adults_in_household** - Adults age 18+ in household (numeric)
- ⚠️ **number_of_children_in_household** - Children under 18 in household (text)
- ⚠️ **multigenerational_household** - 3+ generations living together: yes/no (boolean)

**REDUNDANCY**: Number of adults + children = household size. Multigenerational is captured in household type.
**RECOMMENDATION**: Keep **household_size** and **household_type**. Remove the detailed breakdowns.

---

## Question 224: Children Details (5 metrics)
- ✅ **number_of_biological_children** - Number of biological children (numeric)
- ⚠️ **number_of_adopted_children** - Number of adopted children (numeric)
- ⚠️ **number_of_stepchildren** - Number of stepchildren (numeric)
- ⚠️ **age_of_youngest_child** - Age of youngest child in years (numeric)
- ⚠️ **age_of_oldest_child** - Age of oldest child in years (numeric)

**REDUNDANCY**: All the number fields could be combined into "total number of children."
**RECOMMENDATION**: Keep **total number of children** (sum of bio/adopted/step) and **age_of_youngest_child** (most relevant for childcare needs). Remove the detailed breakdowns.

---

## Question 225: Caregiving (5 metrics)
- ✅ **primary_caregiver_status** - Primary caregiver for children: yes/no (boolean)
- ✅ **elder_care_responsibilities** - Caring for elderly family: yes/no (boolean)
- ✅ **special_needs_caregiving** - Caring for person with disability: yes/no (boolean)
- ⚠️ **childcare_hours_per_week** - Hours per week providing childcare (numeric)
- ⚠️ **dependent_care_hours_total** - Total hours per week caring for dependents (numeric)

**REDUNDANCY**: Total hours is redundant with childcare hours. Others are complementary.
**RECOMMENDATION**: Keep the three yes/no fields. Keep **dependent_care_hours_total** (covers all types). Remove childcare hours.

---

## Question 226: Geographic Type (5 metrics)
- ✅ **geographic_location_type** - Urban, Suburban, Rural, Remote rural (select)
- ⚠️ **metropolitan_area_size** - Major metro, Mid-size metro, Small metro, Micropolitan, Non-metro (select)
- ⚠️ **population_density** - People per square mile in area (numeric)
- ⚠️ **distance_to_major_city** - Miles to city of 50,000+ population (numeric)
- ⚠️ **coastal_vs_inland** - Coastal, Inland, or Mountain region (select)

**REDUNDANCY**: Geographic type and metro area size are highly correlated. Population density supports both.
**RECOMMENDATION**: Keep **geographic_location_type** and **population_density**. Remove the others.

---

## Question 227: Neighborhood/Local (5 metrics)
- ✅ **zip_code** - Five-digit ZIP code (text)
- ⚠️ **zip_4** - Nine-digit ZIP code with +4 extension (text)
- ⚠️ **census_tract** - Census tract number (numeric)
- ⚠️ **block_group** - Census block group (text)
- ⚠️ **neighborhood_name** - Common neighborhood name (text)

**REDUNDANCY**: ZIP+4 contains ZIP. Census tract and block group are very specific geographic identifiers.
**RECOMMENDATION**: Keep **zip_code** only (most commonly used, sufficient for most analyses). Remove others unless doing hyper-local research.

---

## Question 228: State/Region (5 metrics)
- ✅ **state_of_residence** - State abbreviation (text)
- ⚠️ **county_of_residence** - County name (text)
- ⚠️ **region** - Northeast, South, Midwest, West (select)
- ⚠️ **congressional_district** - U.S. Congressional district number (numeric)
- ⚠️ **time_zone** - Eastern, Central, Mountain, Pacific, Alaska, Hawaii (select)

**REDUNDANCY**: Region can be derived from state. Time zone can be derived from state. Congressional district is hyper-specific.
**RECOMMENDATION**: Keep **state_of_residence** only. Calculate region and timezone as needed.

---

## Question 229: Residential Mobility (5 metrics)
- ✅ **years_at_current_address** - Years living at current residence (numeric)
- ⚠️ **number_of_moves_past_5_years** - Times moved in past 5 years (numeric)
- ⚠️ **lifetime_mobility** - Number of states/countries lived in (numeric)
- ⚠️ **plan_to_move** - Plan to move in next year: yes/no/unsure (boolean)
- ⚠️ **reason_for_last_move** - Work, Family, Housing, School, Other (select)

**REDUNDANCY**: Years at current address and number of moves are related but distinct.
**RECOMMENDATION**: Keep **years_at_current_address** and **number_of_moves_past_5_years**. Remove lifetime mobility and reason for move.

---

## Question 230: Housing Tenure (5 metrics)
- ✅ **housing_tenure** - Own, Rent, Live with family/friends, Homeless, Group quarters, Other (select)
- ⚠️ **mortgage_status** - Own outright, Own with mortgage, Rent, Other (select)
- ⚠️ **subsidized_housing** - Receive housing subsidy: yes/no (boolean)
- ⚠️ **landlord_type** - Individual, Corporation, Housing authority, N/A (select)
- ⚠️ **housing_stability** - Stable housing past year: yes/no (boolean)

**REDUNDANCY**: Housing tenure and mortgage status overlap significantly.
**RECOMMENDATION**: Keep **housing_tenure** and **subsidized_housing**. Remove mortgage status and landlord type.

---

## Question 231: Immigration Status (5 metrics)
- ✅ **citizenship_status** - U.S. born citizen, Naturalized citizen, Permanent resident, Visa holder, Undocumented, Other (select)
- ⚠️ **country_of_birth** - Country where born (text)
- ⚠️ **immigration_generation** - 1st gen (foreign-born), 2nd gen (parent foreign-born), 3rd+ gen (select)
- ⚠️ **years_in_united_states** - Total years living in U.S. (numeric)
- ⚠️ **age_at_immigration** - Age when immigrated to U.S. (if applicable) (numeric)

**REDUNDANCY**: Citizenship status is primary. Country of birth and generation provide context.
**RECOMMENDATION**: Keep **citizenship_status**, **immigration_generation**, and **years_in_united_states**. Remove country of birth and age at immigration.

---

## Question 232: Language (5 metrics)
- ✅ **primary_language** - Language most comfortable speaking (text)
- ⚠️ **english_proficiency_level** - Native, Fluent, Good, Fair, Poor, None (select)
- ⚠️ **languages_spoken_at_home** - Languages spoken in household (text)
- ⚠️ **number_of_languages_fluent** - Total languages spoken fluently (text)
- ⚠️ **interpreter_need** - Need interpreter for services: yes/no (boolean)

**REDUNDANCY**: Number of languages and languages spoken at home overlap. Interpreter need relates to English proficiency.
**RECOMMENDATION**: Keep **primary_language** and **english_proficiency_level**. Remove the others.

---

## Question 233: Disability Status (5 metrics)
- ✅ **disability_status_overall** - Have any disability: yes/no (boolean)
- ✅ **disability_type___physical** - Mobility, dexterity, physical disability: yes/no (boolean)
- ✅ **disability_type___sensory** - Vision, hearing disability: yes/no (boolean)
- ✅ **disability_type___cognitive** - Learning, memory, cognitive disability: yes/no (boolean)
- ✅ **disability_type___mental_health** - Mental health disability: yes/no (boolean)

**REDUNDANCY**: Minimal. The overall yes/no and four types provide good detail without redundancy.
**RECOMMENDATION**: Keep all 5. This is good categorization.

---

## Question 234: Health Insurance (5 metrics)
- ✅ **health_insurance_coverage** - Insured: yes/no (boolean)
- ✅ **insurance_type___primary** - Employer, Marketplace/ACA, Medicaid, Medicare, Military/VA, Uninsured (select)
- ⚠️ **insurance_type___secondary** - Have secondary insurance: yes/no (boolean)
- ⚠️ **insurance_adequacy** - Insurance meets needs: yes/no (boolean)
- ⚠️ **months_uninsured_past_year** - Months without insurance in past year (numeric)

**REDUNDANCY**: Coverage yes/no and primary type overlap. Months uninsured and coverage overlap.
**RECOMMENDATION**: Keep **insurance_type___primary** (includes uninsured) and **insurance_adequacy**. Remove coverage yes/no and months uninsured.

---

## Question 235: Military/Veteran Status (5 metrics)
- ✅ **veteran_status** - Veteran of U.S. Armed Forces: yes/no (boolean)
- ⚠️ **military_branch** - Army, Navy, Air Force, Marines, Coast Guard, National Guard (select)
- ⚠️ **service_era** - Vietnam, Gulf War, Iraq/Afghanistan, Other (select)
- ⚠️ **years_of_service** - Total years of military service (numeric)
- ⚠️ **disability_rating** - VA disability rating percentage (numeric)

**REDUNDANCY**: Veteran yes/no is primary. Other details are for veterans only.
**RECOMMENDATION**: Keep **veteran_status** and **disability_rating** (has benefits implications). Make others conditional on veteran status.

---

## Question 236: Religion (5 metrics)
- ✅ **religious_affiliation** - Christian, Jewish, Muslim, Hindu, Buddhist, Atheist, Agnostic, Spiritual not religious, No religion, Other (select)
- ⚠️ **religious_denomination** - Specific denomination/tradition (text)
- ⚠️ **religious_service_attendance** - Never, Few times/year, Monthly, Weekly, Multiple times/week (numeric)
- ⚠️ **importance_of_religion** - Very, Somewhat, Not very, Not at all important (select)
- ⚠️ **raised_in_religion** - Religion raised in as child (text)

**REDUNDANCY**: Affiliation is primary. Importance and attendance measure different aspects.
**RECOMMENDATION**: Keep **religious_affiliation** and **importance_of_religion**. Remove denomination and childhood religion.

---

## Question 237: Political Views (5 metrics)
- ✅ **political_party_affiliation** - Democrat, Republican, Independent, Libertarian, Green, Other, None (select)
- ⚠️ **political_ideology** - Very liberal, Liberal, Moderate, Conservative, Very conservative (select)
- ⚠️ **registered_voter** - Registered to vote: yes/no (boolean)
- ⚠️ **voting_frequency** - Vote: every election, most, some, rarely, never (select)
- ✅ **political_engagement_level** - Politically engaged (scale 0-10) (scale)

**REDUNDANCY**: Party and ideology overlap significantly. Voting frequency and engagement overlap.
**RECOMMENDATION**: Keep **political_party_affiliation** and **political_engagement_level** (normalized scale). Remove the others.

---

## Question 238: Physical Characteristics (5 metrics)
- ⚠️ **height** - Height in inches or cm (text)
- ⚠️ **weight** - Weight in pounds or kg (text)
- ⚠️ **body_mass_index__bmi_** - Weight (kg) / height (m)² (text)
- ⚠️ **blood_type** - A+, A-, B+, B-, AB+, AB-, O+, O- (select)
- ⚠️ **dominant_hand** - Right-handed, Left-handed, Ambidextrous (select)

**REDUNDANCY**: BMI can be calculated from height and weight. Blood type and handedness are rarely relevant to safety metrics.
**RECOMMENDATION**: Remove all unless specifically needed for health/medical research. These don't relate to community safety.

---

## Question 239: Technology Access (5 metrics)
- ⚠️ **technology_ownership** - Smartphone, Computer, Tablet ownership: yes/no for each (boolean)
- ⚠️ **internet_access_at_home** - Home internet: yes/no (boolean)
- ⚠️ **social_media_usage** - Use social media: yes/no, Platforms used (boolean)
- ⚠️ **online_service_usage** - Use online banking, shopping, telehealth: yes/no (boolean)
- ✅ **digital_device_proficiency** - Comfortable using technology (scale 0-10) (scale)

**REDUNDANCY**: Ownership and access overlap with proficiency and usage.
**RECOMMENDATION**: Keep **digital_device_proficiency** (normalized scale) and **internet_access_at_home** (digital divide indicator). Remove detailed ownership and usage.

---

## Question 240: Transportation (5 metrics)
- ✅ **primary_transportation_mode** - Personal vehicle, Public transit, Bike, Walk, Rideshare, Other (select)
- ⚠️ **vehicle_ownership** - Own/have access to vehicle: yes/no (boolean)
- ⚠️ **number_of_vehicles** - Vehicles in household (text)
- ⚠️ **driver_s_license** - Have valid driver's license: yes/no (boolean)
- ⚠️ **commute_time** - Average commute time in minutes (text)

**REDUNDANCY**: Primary mode and vehicle ownership overlap. Number of vehicles is detail.
**RECOMMENDATION**: Keep **primary_transportation_mode** and **vehicle_ownership**. Remove number of vehicles and driver's license.

---

## SUMMARY RECOMMENDATIONS

### Questions to Significantly Reduce:
- **Q211 (Age)**: 5 → 1 metric (keep age_in_years)
- **Q212 (Sex)**: 5 → 2 metrics (keep sex_assigned_at_birth, legal_sex_marker)
- **Q214 (Sexual Orientation)**: 5 → 2 metrics
- **Q215 (Race)**: 5 → 3 metrics
- **Q216 (Ethnicity)**: 5 → 2 metrics
- **Q217 (Household Income)**: 5 → 2 metrics
- **Q218 (Personal Income)**: 5 → 1 metric
- **Q219 (Education)**: 5 → 2 metrics
- **Q220 (Employment)**: 5 → 3 metrics
- **Q221 (Occupation)**: 5 → 2 metrics
- **Q222 (Marital Status)**: 5 → 1 metric
- **Q223 (Household)**: 5 → 2 metrics
- **Q224 (Children)**: 5 → 2 metrics
- **Q225 (Caregiving)**: 5 → 4 metrics
- **Q226 (Geography)**: 5 → 2 metrics
- **Q227 (Local Address)**: 5 → 1 metric
- **Q228 (State/Region)**: 5 → 1 metric
- **Q229 (Mobility)**: 5 → 2 metrics
- **Q230 (Housing)**: 5 → 2 metrics
- **Q231 (Immigration)**: 5 → 3 metrics
- **Q232 (Language)**: 5 → 2 metrics
- **Q234 (Insurance)**: 5 → 2 metrics
- **Q235 (Veteran)**: 5 → 2 metrics
- **Q236 (Religion)**: 5 → 2 metrics
- **Q237 (Politics)**: 5 → 2 metrics
- **Q238 (Physical)**: 5 → 0 metrics (remove entirely)
- **Q239 (Technology)**: 5 → 2 metrics
- **Q240 (Transportation)**: 5 → 2 metrics

### Questions to Keep As-Is:
- **Q213 (Gender Identity)**: Keep all 5 (distinct concepts)
- **Q233 (Disability)**: Keep all 5 (good categorization)

### Potential Reduction:
**From 150 metrics → ~65-75 metrics** (56% reduction)

This would make the demographic section:
- Less burdensome for users
- Faster to complete
- More focused on essential information
- Less redundant data storage
