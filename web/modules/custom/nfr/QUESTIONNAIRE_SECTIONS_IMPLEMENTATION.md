# NFR Questionnaire Sections - Implementation Summary

This document tracks the implementation of all 9 questionnaire sections based on CDC requirements.

## Section Status

### Section 1: Demographics ✅ COMPLETE  
- Race/ethnicity (checkboxes with "other" field)
- Education level (select)
- Marital status (select)

### Section 2: Work History ✅ COMPLETE (Main Form)
Need to copy to Section2Form.php:
- Number of departments (AJAX dynamic)
- For each department:
  - Department name, state, city, FDID
  - Start/end dates, currently employed checkbox
  - Number of jobs (AJAX dynamic)
  - For each job:
    - Job title, employment type
    - Responded to incidents (yes/no radio)
    - If yes: Incident frequency table (12 incident types)

### Section 3: Exposure Information ✅ COMPLETE (Main Form)
Need to copy to Section3Form.php:
- AFFF usage (yes/no/don't know radio)
  - If yes: number of times, year first used
- Diesel exhaust exposure (frequency select)
- Chemical exposures checkboxes (fire investigation, overhaul, etc.)
- Major incidents (yes/no radio)
  - If yes: Repeating incident descriptions with AJAX

### Section 4: Military Service ✅ COMPLETE (Main Form)
Need to copy to Section4Form.php:
- Military service (yes/no radio)
- If yes:
  - Branch (select)
  - Service dates
  - Firefighting duties (yes/no radio)
  - Years in firefighting role

### Section 5: Other Employment ✅ COMPLETE (Main Form)
Need to copy to Section5Form.php:
- Other jobs > 1 year (yes/no radio)
- If yes: Number of jobs (AJAX dynamic)
  - For each job:
    - Job title, industry
    - Start/end dates
    - Hazardous exposures (checkboxes: chemicals, radiation, asbestos, etc.)

### Section 6: PPE Practices ✅ COMPLETE (Main Form)
Need to copy to Section6Form.php:
- Equipment table for 8 PPE types:
  - Used (yes/no checkboxes)
  - Year started using (text field)
- SCBA usage frequency during:
  - Fire suppression (select: always, usually, sometimes, rarely, never)
  - Overhaul operations (select)

### Section 7: Decontamination Practices ✅ COMPLETE (Main Form)
Need to copy to Section7Form.php:
- 5 decontamination practices with frequency (select: always, usually, sometimes, rarely, never):
  - Washed hands/face at scene
  - Changed gear at scene
  - Showered at station
  - Laundered gear regularly
  - Kept gear out of living quarters

### Section 8: Health Information ✅ COMPLETE (Main Form)
Need to copy to Section8Form.php:
- Cancer diagnosis (yes/no radio)
- If yes: Number of diagnoses (AJAX dynamic)
  - For each diagnosis:
    - Cancer type (text)
    - Year diagnosed
    - Treatment received (checkboxes)
- Family cancer history (yes/no checkboxes for immediate family)
- Other health conditions (checkboxes)

### Section 9: Lifestyle Factors ✅ COMPLETE (Main Form)
Need to copy to Section9Form.php:
- Smoking status (never/former/current radio)
  - If former/current:
    - Type (cigarettes, cigars, pipe, chewing, e-cig checkboxes)
    - Start year
    - If former: quit year
    - Packs per day
- Alcohol use (select: none, light, moderate, heavy)
  - If not none: drinks per week
- Exercise (select: none, light, moderate, vigorous)
  - If not none: minutes per week

## Implementation Approach

Since the main NFRQuestionnaireForm.php already has complete implementations of all sections, we need to:

1. Extract each section's build method code
2. Add AJAX callbacks for dynamic fields
3. Add state management for form rebuilds
4. Add proper submit handlers for saving and navigation
5. Ensure data saves to the correct database columns/JSON fields

## Database Schema

All sections save to `nfr_questionnaire` table with these fields:
- demographics (JSON)
- work_history (JSON) - may also write to nfr_work_history table
- exposure_data (JSON) - TODO: add this column
- military_service (JSON)
- other_employment_data (JSON)
- ppe_practices (JSON)
- decon_practices (JSON)
- cancer_diagnosis, cancer_details (JSON), family_cancer_history (JSON)
- smoking_history (JSON), alcohol_use, exercise_frequency

## Next Steps

Run the build script to copy implementations from main form to individual section forms.
