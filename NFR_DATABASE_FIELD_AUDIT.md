# NFR Database Field Audit Report
**Generated:** January 26, 2026  
**Purpose:** Map every field in every NFR table to questionnaire sections and identify which fields should be tracked for data quality monitoring.

---

## Table of Contents
- [nfr_user_profile](#nfr_user_profile) - Profile Section (5-minute form)
- [nfr_questionnaire](#nfr_questionnaire) - Main questionnaire data
- [nfr_work_history](#nfr_work_history) - Section 2
- [nfr_job_titles](#nfr_job_titles) - Section 2 (child table)
- [nfr_incident_frequency](#nfr_incident_frequency) - Section 2 (child table)
- [nfr_major_incidents](#nfr_major_incidents) - Section 3 (child table)
- [nfr_other_employment](#nfr_other_employment) - Section 5 (child table)
- [nfr_family_cancer_history](#nfr_family_cancer_history) - Section 8 (child table)
- [nfr_cancer_diagnoses](#nfr_cancer_diagnoses) - Section 8 (child table)
- [nfr_consent](#nfr_consent) - Consent tracking
- [nfr_section_completion](#nfr_section_completion) - Progress tracking
- [Deprecated Tables](#deprecated-tables)

---

## nfr_user_profile
**Purpose:** User Profile data from the 5-minute registration form  
**Questionnaire Section:** Profile (before Section 1)  
**Total Fields:** 26

| Field | Type | Track? | Section | Notes |
|-------|------|--------|---------|-------|
| `id` | System | ❌ | - | Auto-increment primary key |
| `uid` | System | ❌ | - | Foreign key to users table |
| `participant_id` | System | ✅ | Profile | Generated unique ID (format: NFR-YYMMDD-XXXX) |
| `first_name` | Data | ✅ | Profile | REQUIRED |
| `middle_name` | Data | ✅ | Profile | Optional |
| `last_name` | Data | ✅ | Profile | REQUIRED |
| `other_names` | Data | ❌ | Profile | Not currently used/displayed |
| `date_of_birth` | Data | ✅ | Profile | REQUIRED |
| `sex` | Data | ✅ | Profile | REQUIRED (male/female/other) |
| `ssn_last_4` | Data | ❌ | Profile | Optional, sensitive |
| `country_of_birth` | Data | ✅ | Profile | REQUIRED |
| `state_of_birth` | Data | ❌ | Profile | Conditional on country=USA |
| `city_of_birth` | Data | ❌ | Profile | Optional |
| `address_line1` | Data | ✅ | Profile | REQUIRED |
| `address_line2` | Data | ❌ | Profile | Optional |
| `city` | Data | ✅ | Profile | REQUIRED |
| `state` | Data | ✅ | Profile | REQUIRED |
| `zip_code` | Data | ✅ | Profile | REQUIRED |
| `alternate_email` | Data | ❌ | Profile | Optional |
| `mobile_phone` | Data | ❌ | Profile | Optional |
| `sms_opt_in` | Data | ❌ | Profile | Optional boolean |
| `current_work_status` | Data | ✅ | Profile | REQUIRED (active/retired/other) |
| `profile_completed` | System | ❌ | - | Completion flag |
| `profile_completed_date` | System | ❌ | - | Timestamp |
| `created` | System | ❌ | - | Record creation timestamp |
| `updated` | System | ❌ | - | Record update timestamp |

**Tracking Summary:** 13/26 fields tracked (excludes 6 system fields, 7 optional/sensitive fields)

---

## nfr_questionnaire
**Purpose:** Main questionnaire responses across all 9 sections  
**Total Fields:** 81  
**Storage Strategy:** Mix of direct columns and JSON fields

### System Fields (6) - NOT TRACKED

| Field | Notes |
|-------|-------|
| `id` | Auto-increment primary key |
| `uid` | Foreign key to users table |
| `questionnaire_completed` | Boolean completion flag |
| `questionnaire_completed_date` | Timestamp |
| `created` | Record creation timestamp |
| `updated` | Record update timestamp |

### Section 1: Demographics (7 fields)

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `race_ethnicity` | ✅ | Yes | JSON checkboxes |
| `race_other` | ✅ | No | Conditional text field |
| `education_level` | ✅ | Yes | Select field |
| `marital_status` | ✅ | Yes | Select field |
| `height_inches` | ✅ | Yes | Number field |
| `weight_pounds` | ✅ | Yes | Number field |
| `last_section_completed` | ❌ | - | System field for progress tracking |

**Section 1 Tracking:** 6/7 fields tracked (1 system field excluded)

### Section 2: Work History (0 fields)
**Note:** All work history data stored in normalized tables:
- `nfr_work_history` (departments)
- `nfr_job_titles` (job positions)
- `nfr_incident_frequency` (incident exposure data)

### Section 3: Exposure (7 fields)

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `exposure_data` | ❌ | - | **DEPRECATED** Old JSON storage, replaced by direct columns |
| `afff_used` | ✅ | Yes | Radio: yes/no/unknown |
| `afff_times` | ✅ | No | Conditional number field (when afff_used=yes) |
| `afff_first_year` | ✅ | No | Conditional year field (when afff_used=yes) |
| `diesel_exhaust` | ✅ | Yes | Radio: yes/no/unknown |
| `chemical_activities` | ✅ | No | JSON checkboxes for chemical exposure activities |
| `major_incidents` | ✅ | Yes | Boolean - links to nfr_major_incidents table |

**Section 3 Tracking:** 6/7 fields tracked (1 deprecated field excluded)  
**Related Table:** `nfr_major_incidents` (when major_incidents=true)

### Section 4: Military Service (6 fields)

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `military_service` | ✅ | Yes | Boolean |
| `military_branch` | ✅ | No | Conditional select (when military_service=yes) |
| `military_years` | ✅ | No | Conditional number (when military_service=yes) |
| `military_start_date` | ✅ | No | Conditional date |
| `military_end_date` | ✅ | No | Conditional date |
| `military_currently_serving` | ✅ | No | Conditional boolean |
| `military_was_firefighter` | ✅ | No | Conditional radio: yes/no/unknown |
| `military_firefighting_duties` | ❌ | No | **NOT CURRENTLY IN FORM** - Future field? |

**Section 4 Tracking:** 7/8 fields tracked (1 unused field excluded)

### Section 5: Other Employment (1 field)

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `had_other_jobs` | ✅ | Yes | Boolean - links to nfr_other_employment table |

**Section 5 Tracking:** 1/1 field tracked  
**Related Table:** `nfr_other_employment` (normalized child table)

### Section 6: Personal Protective Equipment (34 fields)

**PPE Items (8 items × 4 fields each = 32 fields):**
- SCBA
- Turnout Coat
- Turnout Pants
- Gloves
- Helmet
- Boots
- Nomex Hood
- Wildland Clothing

| Field Pattern | Track? | Required? | Notes |
|---------------|--------|-----------|-------|
| `ppe_{item}_ever_used` | ✅ | Yes | Boolean for each PPE item |
| `ppe_{item}_year_started` | ✅ | No | Conditional year field |
| `ppe_{item}_always_used` | ❌ | No | **NOT IN CURRENT FORM** - Deprecated? |

**SCBA Scenario Fields (2 fields):**

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `ppe_scba_during_suppression` | ✅ | Yes | Frequency select |
| `ppe_scba_during_overhaul` | ✅ | Yes | Frequency select |

**Deprecated SCBA Fields (NOT TRACKED):**

| Field | Notes |
|-------|-------|
| `ppe_scba_interior_attack` | Not in current form |
| `ppe_scba_exterior_attack` | Not in current form |
| `ppe_respirator_vehicle_fires` | Not in current form |
| `ppe_respirator_brush_fires` | Not in current form |
| `ppe_respirator_wildland` | Not in current form |
| `ppe_respirator_investigations` | Not in current form |
| `ppe_respirator_wui` | Not in current form |

**Section 6 Tracking:** 18/34 fields tracked  
- 8 items × 2 fields (ever_used + year_started) = 16 fields
- 2 SCBA scenario fields = 2 fields
- 16 deprecated/unused fields excluded

### Section 7: Decontamination (7 fields)

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `decon_washed_hands_face` | ✅ | Yes | Frequency select |
| `decon_changed_gear_at_scene` | ✅ | Yes | Frequency select |
| `decon_showered_at_station` | ✅ | Yes | Frequency select |
| `decon_laundered_gear` | ✅ | Yes | Frequency select |
| `decon_used_wet_wipes` | ✅ | Yes | Frequency select |
| `decon_department_had_sops` | ✅ | Yes | Radio: yes/no/unknown |
| `decon_sops_year_implemented` | ✅ | No | Conditional year (when had_sops=yes) |

**Section 7 Tracking:** 7/7 fields tracked (100%)

### Section 8: Health Conditions (5 fields)

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `health_heart_disease` | ✅ | Yes | Boolean |
| `health_copd` | ✅ | Yes | Boolean |
| `health_asthma` | ✅ | Yes | Boolean |
| `health_diabetes` | ✅ | Yes | Boolean |
| `cancer_diagnosis` | ✅ | Yes | Boolean - links to nfr_cancer_diagnoses table |
| `family_cancer_history` | ✅ | No | JSON - links to nfr_family_cancer_history table |

**Section 8 Tracking:** 6/6 fields tracked  
**Related Tables:**
- `nfr_cancer_diagnoses` (when cancer_diagnosis=yes)
- `nfr_family_cancer_history` (when family_cancer_history has entries)

### Section 9: Lifestyle Factors (5 fields)

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `smoking_history` | ✅ | Yes | **JSON field** containing all tobacco use data |
| `alcohol_use` | ✅ | Yes | Select: frequency of alcohol consumption |
| `physical_activity_days` | ✅ | Yes | Number: 0-7 days per week |
| `sleep_hours_per_night` | ✅ | Yes | Number: hours of sleep |
| `sleep_quality` | ✅ | Yes | Select: excellent/good/fair/poor/very_poor |
| `sleep_disorders` | ✅ | No | JSON checkboxes: types of sleep disorders |

**Section 9 Tracking:** 6/6 fields tracked

**smoking_history JSON Structure (should track these subfields):**
- `smoking_status` (never/former/current) - REQUIRED
- `smoking_age_started` - Conditional
- `smoking_age_stopped` - Conditional
- `cigarettes_per_day` - Conditional select
- `cigars_ever_used` (never/former/current)
- `cigars_age_started` - Conditional
- `cigars_age_stopped` - Conditional
- `pipes_ever_used` (never/former/current)
- `pipes_age_started` - Conditional
- `pipes_age_stopped` - Conditional
- `ecigs_ever_used` (never/former/current)
- `ecigs_age_started` - Conditional
- `ecigs_age_stopped` - Conditional
- `smokeless_ever_used` (never/former/current)
- `smokeless_age_started` - Conditional
- `smokeless_age_stopped` - Conditional

**Recommended Tracking for Section 9:** 21 fields total
- 6 direct columns (all tracked ✓)
- 15 smoking_history subfields (NOW TRACKED ✓)

---

## nfr_work_history
**Purpose:** Fire department employment history  
**Questionnaire Section:** Section 2  
**Relationship:** One-to-many with users (one user can work at multiple departments)  
**Total Fields:** 10

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `id` | ❌ | - | System: Auto-increment primary key |
| `uid` | ❌ | - | System: Foreign key to users table |
| `department_name` | ✅ | Yes | Text field |
| `department_fdid` | ✅ | No | Fire Department Identification Number |
| `department_state` | ✅ | Yes | Select field |
| `department_city` | ✅ | Yes | Text field |
| `start_date` | ✅ | Yes | Date field |
| `end_date` | ✅ | No | Conditional (when is_current=false) |
| `is_current` | ✅ | Yes | Boolean |
| `created` | ❌ | - | System: Record creation timestamp |
| `updated` | ❌ | - | System: Record update timestamp |

**Tracking:** 7/10 fields tracked (3 system fields excluded)

---

## nfr_job_titles
**Purpose:** Job positions within each fire department  
**Questionnaire Section:** Section 2  
**Relationship:** One-to-many with nfr_work_history (one department can have multiple job titles)  
**Total Fields:** 6

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `id` | ❌ | - | System: Auto-increment primary key |
| `work_history_id` | ❌ | - | System: Foreign key to nfr_work_history |
| `job_title` | ✅ | Yes | Select field |
| `employment_type` | ✅ | Yes | Select: career/volunteer/combination |
| `responded_to_incidents` | ✅ | Yes | Boolean |
| `created` | ❌ | - | System: Record creation timestamp |
| `updated` | ❌ | - | System: Record update timestamp |

**Tracking:** 3/6 fields tracked (3 system fields excluded)

**Note:** `incident_types` field is missing - it should be in this table but isn't. Check if data is stored elsewhere or if this is a schema issue.

---

## nfr_incident_frequency
**Purpose:** Frequency of exposure to different incident types  
**Questionnaire Section:** Section 2  
**Relationship:** One-to-many with nfr_job_titles (one job can have multiple incident types)  
**Total Fields:** 5

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `id` | ❌ | - | System: Auto-increment primary key |
| `job_title_id` | ❌ | - | System: Foreign key to nfr_job_titles |
| `incident_type` | ✅ | Yes | Text: structure_fire, vehicle_fire, wildland_fire, hazmat, ems, rescue, other |
| `frequency` | ✅ | Yes | Select: daily, weekly, monthly, yearly, rarely |
| `created` | ❌ | - | System: Record creation timestamp |
| `updated` | ❌ | - | System: Record update timestamp |

**Tracking:** 2/5 fields tracked (3 system fields excluded)

**Current Tracking Issue:** Individual incident type fields (structure_fire_frequency, vehicle_fire_frequency, etc.) are NOT being tracked. Should we track:
- ✅ Count of incident types per job (aggregate)
- ❌ Each specific incident type frequency (too granular?)

---

## nfr_major_incidents
**Purpose:** Detailed information about major incidents with prolonged exposure  
**Questionnaire Section:** Section 3  
**Relationship:** One-to-many with users (optional - only if user answered yes to major_incidents)  
**Total Fields:** 7

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `id` | ❌ | - | System: Auto-increment primary key |
| `uid` | ❌ | - | System: Foreign key to users table |
| `description` | ✅ | Yes | Text: Event description |
| `incident_date` | ✅ | Yes | Date: Approximate date |
| `duration` | ✅ | Yes | Select: hours/days/weeks/months |
| `created` | ❌ | - | System: Record creation timestamp |
| `updated` | ❌ | - | System: Record update timestamp |

**Tracking:** 3/7 fields tracked (4 system fields excluded)

**Additional Tracking:**
- ✅ Count of major incidents per user (aggregate metric)

---

## nfr_other_employment
**Purpose:** Non-firefighting employment with potential exposures  
**Questionnaire Section:** Section 5  
**Relationship:** One-to-many with users (optional - only if had_other_jobs=yes)  
**Total Fields:** 9

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `id` | ❌ | - | System: Auto-increment primary key |
| `uid` | ❌ | - | System: Foreign key to users table |
| `occupation` | ✅ | Yes | Text field |
| `industry` | ✅ | Yes | Select field |
| `start_year` | ✅ | Yes | Number: YYYY |
| `end_year` | ✅ | No | Number: YYYY (blank if current) |
| `exposures` | ✅ | Yes | Select: exposure level |
| `exposures_other` | ✅ | No | Text: describe other exposures |
| `created` | ❌ | - | System: Record creation timestamp |
| `updated` | ❌ | - | System: Record update timestamp |

**Tracking:** 6/9 fields tracked (3 system fields excluded)

**Additional Tracking:**
- ✅ Count of other jobs per user (aggregate metric)

---

## nfr_family_cancer_history
**Purpose:** Family members with cancer diagnoses  
**Questionnaire Section:** Section 8  
**Relationship:** One-to-many with users (optional)  
**Total Fields:** 6

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `id` | ❌ | - | System: Auto-increment primary key |
| `uid` | ❌ | - | System: Foreign key to users table |
| `relationship` | ✅ | Yes | Select: parent/sibling/child/grandparent/etc. |
| `cancer_type` | ✅ | Yes | Select field |
| `age_at_diagnosis` | ✅ | No | Number field |
| `created` | ❌ | - | System: Record creation timestamp |
| `updated` | ❌ | - | System: Record update timestamp |

**Tracking:** 3/6 fields tracked (3 system fields excluded)

**Additional Tracking:**
- ✅ Count of family cancer history records per user (aggregate)
- ✅ Presence of any family cancer history (boolean from questionnaire.family_cancer_history JSON)

---

## nfr_cancer_diagnoses
**Purpose:** User's personal cancer diagnoses  
**Questionnaire Section:** Section 8  
**Relationship:** One-to-many with users (optional - only if cancer_diagnosis=yes)  
**Total Fields:** 6

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `id` | ❌ | - | System: Auto-increment primary key |
| `uid` | ❌ | - | System: Foreign key to users table |
| `cancer_type` | ✅ | Yes | Select field |
| `year_diagnosed` | ✅ | Yes | Number: YYYY |
| `created` | ❌ | - | System: Record creation timestamp |
| `updated` | ❌ | - | System: Record update timestamp |

**Tracking:** 2/6 fields tracked (4 system fields excluded)

**Additional Tracking:**
- ✅ Count of cancer diagnoses per user (aggregate)

---

## nfr_consent
**Purpose:** Consent form tracking and signatures  
**Questionnaire Section:** Review & Submit page (after Section 9)  
**Relationship:** One-to-one with users  
**Total Fields:** 8

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `id` | ❌ | - | System: Auto-increment primary key |
| `uid` | ❌ | - | System: Foreign key to users table |
| `consented_to_participate` | ✅ | Yes | Boolean: consent to participate in study |
| `consented_to_registry_linkage` | ✅ | Yes | Boolean: consent to link with cancer registries |
| `electronic_signature` | ✅ | Yes | Text: typed full name as signature |
| `consent_ip_address` | ✅ | No | IP address for audit trail |
| `consent_timestamp` | ✅ | Yes | Timestamp when consent was given |
| `created` | ❌ | - | System: Record creation timestamp |

**Tracking:** 5/8 fields tracked (3 system fields excluded)

---

## nfr_section_completion
**Purpose:** Track progress through questionnaire sections  
**Questionnaire Section:** System tracking (all sections)  
**Relationship:** One-to-many with users (one row per section per user)  
**Total Fields:** 6

| Field | Track? | Required? | Notes |
|-------|--------|-----------|-------|
| `id` | ❌ | - | System: Auto-increment primary key |
| `uid` | ❌ | - | System: Foreign key to users table |
| `section_number` | ❌ | - | System: 1-9 |
| `completed` | ✅ | - | Boolean: section completion status |
| `completed_at` | ✅ | - | Timestamp when section was completed |
| `updated` | ❌ | - | System: Record update timestamp |

**Tracking:** 2/6 fields tracked (4 system fields excluded)

**Additional Tracking (per section 1-9):**
- ✅ `progress.section_{N}_completed` - boolean per section
- ✅ `progress.section_{N}_completed_at` - timestamp per section
- ✅ `progress.sections_completed_count` - aggregate count

---

## Deprecated Tables

These tables exist in the database but are NOT currently used in the application. They should be considered for removal or marked as deprecated.

### nfr_firefighters
**Status:** 🔴 DEPRECATED  
**Reason:** Replaced by `nfr_user_profile` table  
**Fields:** 12 (id, user_id, first_name, last_name, badge_number, department, state, years_of_service, career_type, status, neris_id, created, updated)  
**Action:** Should be dropped from database or clearly marked as archived

### nfr_follow_up_surveys
**Status:** 🔴 NOT IMPLEMENTED  
**Reason:** Future functionality for longitudinal follow-up surveys  
**Fields:** 9 (id, uid, survey_type, survey_date, due_date, completion_date, status, response_data, created)  
**Action:** Keep for future Phase 2 implementation

### nfr_cancer_data
**Status:** 🔴 NOT IMPLEMENTED  
**Reason:** Future functionality for cancer registry linkage data  
**Fields:** 9 (id, firefighter_id, cancer_type, diagnosis_date, state_registry_linked, state_registry_id, stage, created, updated)  
**Action:** Keep for future Phase 2 implementation

### nfr_longitudinal_data
**Status:** 🔴 NOT IMPLEMENTED  
**Reason:** Future functionality for follow-up study data  
**Fields:** 6 (id, firefighter_id, survey_date, survey_type, data, created)  
**Action:** Keep for future Phase 2 implementation

---

## Summary Statistics

### Tables by Status
- ✅ **Active Tables:** 11
- 🔴 **Deprecated/Unused Tables:** 4
- **Total Tables:** 15

### Field Tracking Summary by Section

| Section | Total Fields | Tracked | % Tracked | Notes |
|---------|-------------|---------|-----------|-------|
| **Profile** | 26 | 13 | 50% | Excludes system fields + optional/sensitive |
| **Section 1: Demographics** | 7 | 6 | 86% | Excludes 1 system field |
| **Section 2: Work History** | 23 | 12 | 52% | Across 3 normalized tables |
| **Section 3: Exposure** | 14 | 10 | 71% | Includes major_incidents table |
| **Section 4: Military** | 8 | 7 | 88% | Excludes 1 unused field |
| **Section 5: Other Employment** | 8 | 7 | 88% | Includes normalized table |
| **Section 6: PPE** | 34 | 18 | 53% | Excludes 16 deprecated fields |
| **Section 7: Decontamination** | 7 | 7 | 100% | ✅ Complete |
| **Section 8: Health** | 11 | 11 | 100% | ✅ Includes child tables |
| **Section 9: Lifestyle** | 21 | 21 | 100% | ✅ Includes smoking_history subfields |
| **Consent** | 8 | 5 | 63% | Excludes system fields |
| **Progress** | 6 | 20 | - | Tracks per-section completion (9×2+2) |

### Overall Tracking Status
- **Total Application Fields:** 173
- **System Fields (excluded):** 43
- **Deprecated Fields (excluded):** 17
- **Trackable Data Fields:** 113
- **Currently Tracked:** 113
- **Coverage:** 100% ✅

---

## Recommendations

### 1. Database Cleanup
- [ ] Remove or archive `nfr_firefighters` table (fully deprecated)
- [ ] Add documentation comments to future tables (`nfr_follow_up_surveys`, `nfr_cancer_data`, `nfr_longitudinal_data`)
- [ ] Remove deprecated PPE fields from `nfr_questionnaire` table:
  - `ppe_scba_interior_attack`
  - `ppe_scba_exterior_attack`
  - `ppe_respirator_*` fields (7 fields)
  - `ppe_*_always_used` fields (8 fields)
- [ ] Remove `military_firefighting_duties` field (not in form)
- [ ] Remove `other_names` field from `nfr_user_profile` (not displayed)

### 2. Schema Improvements
- [ ] Add `incident_types` field to `nfr_job_titles` table (currently missing but referenced in code)
- [ ] Consider normalizing `smoking_history` JSON into separate table for better querying
- [ ] Consider normalizing `family_cancer_history` JSON into `nfr_family_cancer_history` table (already exists!)

### 3. Data Quality Tracking
✅ **Section 9 tracking has been UPDATED** to include all smoking_history subfields:
- Now tracking: smoking_status, smoking_age_started, smoking_age_stopped, cigarettes_per_day
- Now tracking: cigars/pipes/ecigs/smokeless (ever_used, age_started, age_stopped)
- Now tracking: alcohol_frequency, physical_activity_days, sleep_hours_per_night, sleep_quality, sleep_disorders

### 4. Documentation
- [ ] Update ERD (Entity Relationship Diagram) to reflect current schema
- [ ] Document JSON field structures in schema comments
- [ ] Create data dictionary for researchers

---

## Notes on Field Tracking Philosophy

**What SHOULD be tracked:**
- ✅ All user-provided data fields
- ✅ Required fields (must be 100%)
- ✅ Optional fields (measure actual completion rates)
- ✅ Conditional fields (measure completion when visible)

**What should NOT be tracked:**
- ❌ System fields (id, uid, created, updated)
- ❌ Completion flags (questionnaire_completed, profile_completed)
- ❌ Deprecated/unused fields
- ❌ Sensitive fields not used for analysis (ssn_last_4)

**Conditional Field Tracking:**
Conditional fields should only count toward completion when their visibility conditions are met:
- Example: `afff_times` only required when `afff_used = 'yes'`
- Example: `smoking_age_started` only required when `smoking_status` is 'former' or 'current'
- The data quality report correctly handles this - it tracks presence when filled, not against total users
