# National Firefighter Registry - Documentation Index

**Last Updated:** January 25, 2026

Welcome to the National Firefighter Registry (NFR) documentation. This directory contains comprehensive project documentation including business requirements, technical specifications, and official CDC source documents.

---

## Documentation Files

### Development Documentation

#### 1. Business Requirements (`BUSINESS_REQUIREMENTS.md`)
Comprehensive business requirements document extracted from CDC NFR official documents.

**Contents:**
- Executive Summary
- Legislative Mandate (Firefighter Cancer Registry Act of 2018)
- System Objectives and Scope
- Target Populations (minorities, women, volunteers, wildland firefighters)
- Data Collection Requirements
  - User Profile (5-minute form)
  - Enrollment Questionnaire (30-minute survey)
- External System Integrations
  - State Cancer Registries (VPR-CLS)
  - National Death Index (NDI)
  - USFA NERIS
- Security & Compliance (AoC, FISMA)
- Success Criteria
- Risk Mitigation
- Implementation Timeline

**File Size:** ~15 KB  
**Sections:** 14 main sections + appendices

---

#### 2. User Roles & Process Flows (`USER_ROLES_AND_PROCESS_FLOWS.md`)
Detailed user roles, process flows, and user journey maps.

**Contents:**
- User Roles
  - Primary Users (Firefighters: Active, Retired, Academy Students)
  - System Users (Administrators, Researchers, Fire Department Staff)
  - Stakeholders (Fire Service Organizations, State Registries, Congress)
- Process Flows
  - Firefighter Registration & Enrollment (7 steps)
  - Longitudinal Follow-Up Process
  - Profile Update Process
  - State Cancer Registry Linkage Process
  - Administrator Workflows
- Page Requirements by User Type
- User Journey Maps
  - First-Time Participant Journey
  - Returning Participant Journey
  - Administrator Journey (Linkage Processing)
- System States
  - Account States
  - Cancer Status States
  - Questionnaire States
- Integration Points (Internal & External)

**File Size:** ~26 KB  
**Sections:** 6 main sections with detailed subsections

---

#### 3. Page Specifications (`PAGE_SPECIFICATIONS.md`)
Complete page-level specifications with Drupal content type mapping.

**Contents:**
- Page Inventory
  - 9 Public Pages
  - 13 Authenticated Pages
  - 8 Administrative Pages
- Drupal Content Type Mapping
  - NFR Participant
  - NFR User Profile (Paragraph)
  - NFR Work History (Paragraph)
  - Fire Department (Taxonomy/Content Type)
  - NFR Questionnaire Data
  - NFR Cancer Diagnosis
  - NFR Follow-Up Survey
- Public Page Specifications
  - Home/Landing Page
  - About NFR
  - How It Works
  - Why Participate
  - FAQ
  - Public Data Dashboard
  - Contact Us
- Authenticated Page Specifications
  - Login/Registration
  - Informed Consent (with electronic signature)
  - User Profile Form (5 minutes)
  - Enrollment Questionnaire (30 minutes)
    - 9 sections with conditional logic
    - Repeating sections for departments/job titles
    - Incident frequency tracking
  - Review & Submit
  - Participant Dashboard
- Administrative Page Specifications
  - Admin Dashboard
  - Participant Management
  - Linkage Management
  - Data Quality Dashboard
  - Report Builder
- Form Specifications
  - Field-level requirements
  - Validation rules
  - Conditional display logic
- Dashboard Specifications

**File Size:** ~44 KB  
**Sections:** 7 main sections with detailed page mockups

---

### CDC Official Documents

#### 4. NFR Protocol (`NFR-Protocol-Aprl_2025_OMB.pdf`)
Official CDC/NIOSH National Firefighter Registry Protocol approved by OMB.

**Contents:**
- Congressional Mandate (Firefighter Cancer Registry Act of 2018)
- Program Authority and Legal Basis
- Surveillance Objectives
  - Objective 1: Determine incidence of cancer among firefighters
  - Objective 2: Identify risk factors for cancer
  - Objective 3: Monitor cancer trends over time
- Surveillance Activities
  - Activity 1: Enrollment and data collection
  - Activity 2: State cancer registry linkage
  - Activity 3: National Death Index matching
- 5-Year Funding Authorization
- Stakeholder Engagement Requirements
- Privacy Protections (Assurance of Confidentiality)
- Data Use and Sharing Policies

**File Size:** 975 KB  
**Format:** PDF  
**Publication Date:** April 2025 OMB

---

#### 5. User Profile Form (`NFR-User-Profile-April_-2025_OMB.pdf`)
CDC-approved User Profile registration form specification.

**Contents:**
- Form Overview (5-minute burden estimate)
- Eligibility Requirements (Age 18+)
- Field Specifications:
  - Personal Information
    - Name fields (first, middle, last, other names)
    - Birth information (country, state, city, date)
    - Sex
    - SSN last 4 digits (optional)
  - Contact Information
    - Residential address
    - Email addresses (primary, alternate)
    - Phone numbers (mobile with SMS opt-in)
  - Employment Information
    - Current work status
    - Current/most recent department
- SSN Rationale
  - Why last 4 digits requested
  - How it improves cancer registry matching
  - Optional but recommended
  - Privacy protections
- Department Search Functionality
- Validation Rules
- Help Text and User Guidance

**File Size:** 300 KB  
**Format:** PDF  
**Publication Date:** April 2025 OMB

---

#### 6. Enrollment Questionnaire (`NFR-Enrollment-Questionnaire-April_2025_OMB.pdf`)
Comprehensive 30-minute enrollment survey specification.

**Contents:**
- Form Overview (30-minute burden estimate)
- Section 1: Demographics
  - Race/ethnicity (multiple selections allowed)
  - Education level
  - Marital status
- Section 2: Work History
  - Number of departments
  - For each department:
    - Department identification
    - Employment dates
    - Job titles held
    - Employment type (career, volunteer, paid-on-call, seasonal, wildland, military)
    - Incident response (yes/no)
    - If yes: Incident types and frequencies
      - Structure fires (residential/commercial)
      - Vehicle fires
      - Wildland fires
      - Medical/EMS
      - Hazmat
      - Technical rescue
      - Aircraft rescue (ARFF)
      - Marine firefighting
      - Prescribed burns
      - Training fires
      - Other
- Section 3: Exposure Information
  - AFFF (aqueous film-forming foam) usage
  - Diesel exhaust exposure
  - Chemical exposures (investigation, overhaul, maintenance)
  - Major incidents with prolonged exposure
- Section 4: Military Service
  - Branch, dates, military firefighting duties
- Section 5: Other Employment
  - Jobs outside firefighting >1 year
  - Potential hazardous exposures
- Section 6: Personal Protective Equipment (PPE)
  - Equipment types and year started using
    - SCBA
    - Turnout coat/pants
    - Gloves, helmet, boots
    - Nomex hood (particulate-blocking)
    - Wildland clothing
  - SCBA usage frequency (fire suppression vs overhaul)
- Section 7: Decontamination Practices
  - Post-incident cleaning practices
  - Department SOPs/SOGs
- Section 8: Health Information
  - Cancer diagnoses (type, year)
  - Other health conditions
- Section 9: Lifestyle Factors
  - Tobacco use (cigarettes)
  - Alcohol consumption
  - Physical activity

**File Size:** 485 KB  
**Format:** PDF  
**Publication Date:** April 2025 OMB

---

## How to Use This Documentation

### For Developers
1. Start with **BUSINESS_REQUIREMENTS.md** to understand project scope and objectives
2. Review **USER_ROLES_AND_PROCESS_FLOWS.md** for user workflows
3. Reference **PAGE_SPECIFICATIONS.md** for implementation details
4. Consult CDC PDFs for authoritative field-level requirements

### For Stakeholders
1. Review **Business Requirements** for project overview
2. Examine **User Roles & Process Flows** for participant experience
3. Review CDC Protocol for official program documentation

### For Project Managers
1. **Business Requirements** → Project scope and timeline
2. **User Roles** → Resource planning and user testing
3. **Page Specifications** → Development estimates and sprints

---

## Accessing Documentation

### Via Web Interface
Visit `/nfr/documentation` to browse all documentation with formatted display.

**Available Pages:**
- `/nfr/documentation` - Documentation index
- `/nfr/documentation/business-requirements` - Business Requirements
- `/nfr/documentation/user-roles` - User Roles & Process Flows  
- `/nfr/documentation/page-specifications` - Page Specifications
- `/nfr/documentation/protocol` - CDC Protocol (PDF)
- `/nfr/documentation/user-profile` - User Profile Form (PDF)
- `/nfr/documentation/questionnaire` - Enrollment Questionnaire (PDF)

### Via File System
All documentation files are located in:
```
sites/forseti/web/modules/custom/nfr/documents/
```

---

## Document Control

**Project:** National Firefighter Registry (NFR)  
**Module Version:** 1.0  
**Documentation Version:** 1.0  
**Last Updated:** January 25, 2026  
**Maintained By:** NFR Development Team

---

## Additional Resources

For additional technical documentation, see the module root directory:
- `README.md` - Module overview and installation
- `ARCHITECTURE.md` - System architecture and design patterns
- `INSTALLATION.md` - Installation and deployment guide
- `DRUPAL11_COMPLIANCE.md` - Drupal 11 standards compliance

---

**Note:** This documentation is based on CDC NFR official documents dated April 2025 (OMB approved) and reflects the most current requirements as of January 2026.