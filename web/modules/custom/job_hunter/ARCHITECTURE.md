# Job Application Automation Module - Architecture Design

**Last Updated:** February 6, 2026

> **📁 Documentation Note:** This is the comprehensive architecture reference document. For a condensed architecture overview, see [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). For the complete documentation index, see [docs/README.md](docs/README.md).

## Overview
This document outlines the architecture for the Job Application Automation module, designed to provide AI-powered job application automation for users of the forseti.life website. The system leverages Generative AI to analyze resumes, tailor applications to specific job descriptions, and automate the submission process across multiple employer platforms.

**⚠️ IMPORTANT: This document must be read and understood before beginning any development work on this module.**

## 🏗️ **CORE DEVELOPMENT PRINCIPLES** 

### **Drupal-Native Architecture - MANDATORY**
This module MUST follow Drupal best practices and utilize built-in functionality:

#### ✅ **REQUIRED: Use Native Drupal Systems**
- **Primary Content Storage**: Use **NODES** with custom fields for canonical content (Company, Job Posting, Application, Issue)
- **User Interface**: Use **DEFAULT DRUPAL FORMS** (`/node/add`, `/node/edit`) for content creation/editing  
- **Data Display**: Use **VIEWS MODULE** for all listing and administrative interfaces
- **User Profiles**: Extend **USER ENTITY** with custom fields, use default user edit forms
- **Permissions**: Use **DRUPAL'S PERMISSION SYSTEM** for access control
- **File Management**: Use **DRUPAL'S FILE SYSTEM** with managed files and field attachments
- **Configuration**: Use **CONFIGURATION MANAGEMENT** for exportable settings

#### 🚫 **AVOID: Custom Development Unless Absolutely Necessary**
- **No Custom Controllers** unless explicitly required for automation/API integrations or operational dashboards
- **No Custom Forms** - use Drupal's node forms with form_alter hooks if customization needed
- **No Custom Pages** - use Views for listing pages, node pages for detail views
- **No Custom Services** - use existing Drupal services and extend via dependency injection only when required
- **Custom Database Tables** are allowed only for operational/automation data that is not suitable for nodes (see Hybrid Storage Strategy)

#### 🎯 **Implementation Strategy**
1. **Content-First**: Create content types with appropriate fields
2. **Views-Second**: Create Views for administrative and user interfaces  
3. **Forms-Third**: Customize existing forms only if default behavior insufficient
4. **Operational Tables (When Required)**: Use custom tables for high-volume automation, AI artifacts, and sync/validation state
5. **Custom Code-Last**: Add custom code only for automation, API integration, or complex business logic

#### 📋 **Validation Checklist**
Before implementing any custom code, verify:
- [ ] Can this be accomplished with content types and fields?
- [ ] Can this be displayed using Views?
- [ ] Can this use default Drupal forms?
- [ ] Is custom code absolutely necessary for core functionality?

### **Hybrid Storage Strategy (Nodes + Custom Tables)**
This module now follows a **hybrid storage strategy**:

**Nodes remain the canonical source of truth** for business content, while **custom tables** store operational and automation data that is high‑volume, transient, or AI‑generated.

**Custom tables are used for:**
- Automation pipeline state (queue/processing status, generated artifacts)
- AI‑generated intermediate data (parsed resume JSON, consolidated profiles)
- Integration/sync metadata (Google Jobs validation, indexing, metrics)
- Performance‑sensitive or high‑write telemetry

**Rules:**
1. **Canonical content stays in nodes.** Tables must reference node/user IDs, not replace content entities.
2. **No duplicate business truth.** Tables store derived/operational data only.
3. **Schema is versioned.** All table changes require install/update hooks.
4. **Retention is explicit.** Document cleanup or archival policies per table.
5. **Views first.** Use Views for listings unless an operational dashboard requires bespoke UI.

**Current operational tables (non‑exhaustive):**
- `jobhunter_companies`
- `jobhunter_job_requirements`
- `jobhunter_job_seeker`
- `jobhunter_job_seeker_resumes`
- `jobhunter_resume_parsed_data`
- `jobhunter_tailored_resumes`
- `jobhunter_pdf_history`
- `jobhunter_google_jobs_sync`
- `jobhunter_google_jobs_validation_log`

### **User Experience Standards**
- Users create/manage content via **standard Drupal node forms** (`/node/add/company`, `/node/edit/123`)
- Administrators manage content via **Views-based interfaces** (`/admin/content`)
- All data accessible via **standard Drupal APIs** (REST, JSON:API when needed)
- Configuration managed via **Drupal's admin interface** and exported configurations

## Development Status Legend
- **[TODO]** - Feature needs to be implemented
- **[TODO - MVP PRIORITY]** - Critical MVP feature requiring immediate implementation
- **[TODO - BASIC ONLY]** - Simplified version for MVP, enhanced version later
- **[COMPLETED]** - Feature fully implemented and tested
- **[SHELVED]** - Feature noted but not included in MVP scope
- **[NOTED]** - Feature acknowledged but deferred to future phases

## MVP Implementation Status Summary

### Critical MVP Components (Must Implement First):
- **Module Installation & Setup** - **[TODO]** - All content types, fields, and installation hooks
- **User Profile Management** - **[TODO - MVP PRIORITY]** - Extended user entity with job application fields
- **Company Management** - **[TODO - MVP PRIORITY]** - Company nodes with basic scraping configuration
- **Error Queue System** - **[TODO - MVP PRIORITY]** - Simple list with checkboxes for admin management
- **Contact Form Support** - **[TODO - MVP PRIORITY]** - Basic contact form for user issues
- **Single Employer Automation** - **[TODO - MVP PRIORITY]** - Perfect one employer's complete workflow

### Advanced Features (Phase 2+):
- **AI Model Training** - **[SHELVED]** - Continuous improvement of AI models
- **Multi-Employer Scaling** - **[SHELVED]** - Scale beyond single employer automation  
- **Advanced Analytics** - **[SHELVED]** - Success tracking and reporting
- **GDPR Compliance** - **[SHELVED]** - Advanced security and privacy features
- **Market Intelligence** - **[SHELVED]** - Competitive analysis and insights

### Development Priority Order:
1. **Module Installation & Content Types** - **[TODO]** - Foundation for all other features
2. **User Profile Extension** - **[TODO - MVP PRIORITY]** - Required for job applications
3. **Company Node Creation** - **[TODO - MVP PRIORITY]** - Required for employer management
4. **Error Queue Dashboard** - **[TODO - MVP PRIORITY]** - Required for admin oversight
5. **Single Employer Scraping** - **[TODO - MVP PRIORITY]** - Core automation functionality
6. **Application Submission** - **[TODO - MVP PRIORITY]** - Complete the automation workflow

## Current Implementation Status - AI Resume Tailoring **[COMPLETED]**

### **Working AI Resume Tailoring System** **[COMPLETED]**
The core AI resume tailoring functionality is fully implemented and operational:

**✅ AJAX-Powered Resume Tailoring:** **[COMPLETED]**
- **Route:** `/user/{user}/tailor-resume/{job}` - Fully functional tailor resume page
- **AJAX Endpoint:** `/tailor-resume/ajax` - Working POST endpoint for AI processing
- **Controller:** `UserProfileController::tailorResumeAjax()` - Complete implementation
- **Development Environment:** Mock response system with "This is where the tailored resume would be." 
- **Production Environment:** Full AI integration with AWS Bedrock Claude support
- **Node Creation:** Creates `tailored_resume` nodes with proper body content and references

**✅ Frontend Integration:** **[COMPLETED]**
- **JavaScript:** `tailor-resume.js` with Drupal 11 compatible messaging API
- **Template:** `tailor-resume.html.twig` with job details and resume display
- **CSS:** `tailor-resume.css` with professional styling
- **User Experience:** "Start AI Tailoring" button with loading states and success feedback
- **Content Display:** Dynamic population of `#resume-content` div with tailored resume

**✅ Content Types & Data Structure:** **[COMPLETED]**
- **tailored_resume:** Title, body field, job_posting reference, resume reference
- **job_posting:** Working with existing job posting nodes (IDs 65, 66, 67)
- **Data Validation:** AJAX endpoint validates job posting exists and loads resume from node 10
- **Error Handling:** Comprehensive error responses and JavaScript error display

**✅ Environment Setup:** **[COMPLETED]**
- **Development Environment Detection:** Automatic detection of development vs production
- **Dependencies:** PHP 8.3.25, MySQL 8.0.43, Apache 2.4.58, all required extensions
- **Text Processing:** pdftotext, docx2txt, antiword for resume text extraction
- **Cache Management:** Drupal cache clearing and JavaScript library loading

## Complete Development Roadmap

### Phase 1: Foundation & Data Structure (Week 1-2) **[COMPLETED]**
- [x] **Module Install/Enable:** Complete module installation system **[COMPLETED]**
- [x] **Content Types:** All required content types created with fields **[COMPLETED]**
- [x] **User Profile Extension:** Custom fields added to user entity **[COMPLETED]**
- [x] **Basic Views:** Administrative interfaces for all content types **[COMPLETED]**
- [x] **AI Resume Tailoring:** Complete AJAX-powered tailoring system **[COMPLETED]**
- [ ] **Permissions:** Proper role-based access control **[TODO]**
- [ ] **Testing:** Installation/uninstall testing on clean Drupal **[TODO]**

### Phase 2: Core User Experience (Week 3-4) **[PARTIALLY COMPLETED]**
- [ ] **User Registration:** Extended registration with profile fields **[TODO - MVP PRIORITY]**
- [ ] **Profile Management:** Complete profile editing interface **[TODO - MVP PRIORITY]**
- [ ] **Company Selection:** User can browse and select target companies **[TODO - MVP PRIORITY]**
- [ ] **Support System:** Contact form for user assistance **[TODO - MVP PRIORITY]**
- [x] **Resume Tailoring Dashboard:** Working dashboard at `/resume-tailoring/dashboard` **[COMPLETED]**
- [x] **AI Tailoring Interface:** Complete AJAX-powered tailoring system **[COMPLETED]**
- [x] **Content Management:** Standard Drupal node creation/editing for all content types **[COMPLETED]**
- [ ] **Testing:** Complete user workflow testing **[TODO]**

### Phase 3: Admin Management Tools (Week 5-6) **[TODO - MVP PRIORITY]**
- [ ] **Error Queue:** Admin can view and manage system errors
- [ ] **Company Management:** Add/edit companies via admin interface
- [ ] **User Management:** Admin oversight of user profiles and activity
- [ ] **Support Management:** Admin can view and respond to user issues
- [ ] **System Configuration:** Basic module settings and configuration
- [ ] **Testing:** Complete admin workflow testing

### Phase 4: Job Discovery & Automation (Week 7-10) **[TODO - MVP PRIORITY]**
- [ ] **Scraping Framework:** Web scraping infrastructure development
- [ ] **Single Employer Focus:** Perfect automation for one employer
- [ ] **Job Storage:** Scraped jobs saved as proper Drupal nodes
- [ ] **Job Matching:** Basic matching of jobs to user profiles
- [ ] **Application Tracking:** Users can see available jobs and apply
- [ ] **Testing:** End-to-end automation testing and refinement

### Phase 5: Integration & Polish (Week 11-12) **[TODO]**
- [ ] **System Integration:** All components working together seamlessly
- [ ] **Error Handling:** Comprehensive error capture and admin notification
- [ ] **User Experience:** Polish interfaces and improve usability
- [ ] **Performance:** Optimize for expected user load
- [ ] **Documentation:** User guides and admin documentation
- [ ] **Testing:** Full system testing and user acceptance testing

## Implementation Gap Analysis **[CURRENT STATUS]**

### ✅ **COMPLETED COMPONENTS** (Ready for Production)
- **AI Resume Tailoring System** - Complete AJAX-powered system with development/production environments
- **Content Type Infrastructure** - All required content types with proper field configuration
- **JavaScript Integration** - Drupal 11 compatible messaging and AJAX handling
- **Template System** - Professional UI with Bootstrap styling and responsive design
- **Database Integration** - Proper node creation, entity references, and data persistence
- **Environment Setup** - Complete development environment with all dependencies
- **Error Handling** - Comprehensive error management and user feedback

### 🔄 **CRITICAL MVP GAPS** (Immediate Development Needed)
- **User Profile Extensions** - 24+ custom fields for user entity not fully implemented **[HIGH PRIORITY]**
- **Permission System** - Role-based access control not configured **[HIGH PRIORITY]**
- **Admin Dashboard** - Management interfaces for companies, users, applications **[HIGH PRIORITY]**
- **Error Queue System** - Admin interface for system error management **[HIGH PRIORITY]**
- **Job Discovery System** - Web scraping and job posting automation **[MEDIUM PRIORITY]**
- **Application Submission** - Automated application submission to employer sites **[MEDIUM PRIORITY]**

### 📋 **MISSING MVP COMPONENTS** (Architecture vs Implementation)
1. **Extended User Registration** - Profile fields during user registration process
2. **Company Management Interface** - Admin and user interfaces for managing employer companies
3. **Credential Management** - Secure storage and management of employer login credentials
4. **Job Matching Algorithm** - AI-powered job recommendation system
5. **Application Tracking** - Complete application lifecycle management
6. **Admin Error Queue** - System for managing failed automation workflows
7. **Background Processing** - Queue system for automated tasks
8. **Comprehensive Testing** - Installation, user workflow, and system testing

### Success Metrics & Acceptance Criteria **[MIXED STATUS]**
- [x] **AI Tailoring Success:** Resume tailoring creates proper content and nodes **[COMPLETED]**
- [x] **Technical Integration:** JavaScript, AJAX, templates working properly **[COMPLETED]**
- [x] **Environment Support:** Development and production environment detection **[COMPLETED]**
- [ ] **Installation Success:** Module installs cleanly on any Drupal 11 site **[TODO]**
- [ ] **User Onboarding:** New user can complete profile in <2 hours **[TODO]**
- [ ] **Company Management:** Admin can add companies in <5 minutes **[TODO]**
- [ ] **Job Discovery:** System finds 10+ relevant jobs per day per user **[TODO]**
- [ ] **Application Success:** 80%+ successful application submissions **[TODO]**
- [ ] **Error Management:** <5% error rate with proper admin notification **[TODO]**
- [ ] **Performance:** System handles 50+ concurrent users **[TODO]**
- [ ] **User Satisfaction:** Users report positive experience and results **[TODO]**

## Module Installation & Setup

### Module Enablement Process **[TODO]**
When the Job Application Automation module is enabled, it must automatically create all required content types, fields, and configurations if they don't already exist.

#### Content Types Created on Module Enable: **[TODO]**

**1. Company Content Type (`company`)** **[TODO]**
- **Machine Name:** `company`
- **Description:** Employer companies for job application automation
- **Fields Created:** **[TODO]**
  - `field_website` (Link field) - Company website URL **[TODO]**
  - `field_careers_url` (Link field) - Career page URL for scraping **[TODO]**
  - `field_description` (Text (long)) - Company description **[TODO]**
  - `field_industry` (List (text)) - Industry category **[TODO]**
  - `field_size` (List (text)) - Company size (Small, Medium, Large, Enterprise) **[TODO]**
  - `field_active` (Boolean) - Active job scraping status **[TODO]**
  - `field_scraping_notes` (Text (long)) - Admin notes for scraping configuration **[TODO]**
  - `field_location` (Text) - Company headquarters location **[TODO]**
  - `field_company_logo` (Image) - Company logo for display **[TODO]**

**2. Job Posting Content Type (`job_posting`)** **[TODO]**
- **Machine Name:** `job_posting`
- **Description:** Individual job opportunities discovered through scraping
- **Fields Created:** **[TODO]**
  - `field_company_ref` (Entity reference) - Reference to Company node **[TODO]**
  - `field_job_title` (Text) - Position title **[TODO]**
  - `field_job_description` (Text (long)) - Full job description **[TODO]**
  - `field_requirements` (Text (long)) - Job requirements and qualifications **[TODO]**
  - `field_salary_range` (Text) - Salary information if available **[TODO]**
  - `field_location` (Text) - Job location **[TODO]**
  - `field_remote_option` (List (text)) - Remote work options (Remote, Hybrid, On-site) **[TODO]**
  - `field_employment_type` (List (text)) - Full-time, Part-time, Contract, etc. **[TODO]**
  - `field_job_url` (Link field) - Direct link to job posting **[TODO]**
  - `field_posting_date` (Date) - When job was posted **[TODO]**
  - `field_application_deadline` (Date) - Application deadline if specified **[TODO]**
  - `field_skills_required` (Text (long)) - Extracted skills and technologies **[TODO]**
  - `field_experience_level` (List (text)) - Entry, Mid, Senior, Executive **[TODO]**
  - `field_job_status` (List (text)) - Active, Applied, Archived, Expired **[TODO]**

**3. Application Content Type (`application`)** **[TODO]**
- **Machine Name:** `application`
- **Description:** User job applications and their status
- **Fields Created:** **[TODO]**
  - `field_user_ref` (Entity reference) - Reference to User **[TODO]**
  - `field_company_ref` (Entity reference) - Reference to Company node **[TODO]**
  - `field_job_posting_ref` (Entity reference) - Reference to Job Posting node **[TODO]**
  - `field_application_date` (Date) - When application was submitted **[TODO]**
  - `field_application_status` (List (text)) - Submitted, In Review, Interview, Rejected, Offer, Archived **[TODO]**
  - `field_resume_used` (File) - Copy of resume used for this application **[TODO]**
  - `field_cover_letter_used` (File) - Cover letter used for this application **[TODO]**
  - `field_notes` (Text (long)) - User notes about the application **[TODO]**
  - `field_automated` (Boolean) - Whether application was automated or manual **[TODO]**
  - `field_tailored_content` (Text (long)) - AI-generated tailored resume content **[TODO]**
  - `field_application_url` (Link field) - URL to employer's application if available **[TODO]**

**4. Error Queue Content Type (`error_queue`)** **[TODO]**
- **Machine Name:** `error_queue`
- **Description:** System errors requiring admin attention
- **Fields Created:** **[TODO]**
  - `field_company_ref` (Entity reference) - Reference to Company node **[TODO]**
  - `field_user_ref` (Entity reference) - Reference to affected User **[TODO]**
  - `field_job_posting_ref` (Entity reference) - Reference to Job Posting if applicable **[TODO]**
  - `field_error_type` (List (text)) - Authentication, Scraping, Submission, Technical **[TODO]**
  - `field_error_message` (Text (long)) - Detailed error description **[TODO]**
  - `field_error_data` (Text (long)) - Technical error data and stack traces **[TODO]**
  - `field_priority` (List (text)) - Low, Medium, High, Critical **[TODO]**
  - `field_status` (List (text)) - New, In Progress, Resolved, Deferred **[TODO]**
  - `field_assigned_admin` (Entity reference) - Reference to admin User **[TODO]**
  - `field_resolution_notes` (Text (long)) - Admin resolution documentation **[TODO]**
  - `field_fixed` (Boolean) - Simple checkbox for MVP queue management **[TODO]**
  - `field_screenshot` (Image) - Screenshot of error if available **[TODO]**

#### User Profile Fields Created on Module Enable: **[TODO]**

**Extended User Entity Fields:** **[TODO]**
- `field_resume_file` (File) - Primary resume upload **[TODO]**
- `field_professional_summary` (Text (long)) - Professional summary/objective **[TODO]**
- `field_skills_summary` (Text (long)) - Skills and technologies overview **[TODO]**
- `field_work_authorization` (List (text)) - US Citizen, Green Card, Visa Required, etc. **[TODO]**
- `field_salary_expectation_min` (Number, integer) - Minimum salary expectation **[TODO]**
- `field_salary_expectation_max` (Number, integer) - Maximum salary expectation **[TODO]**
- `field_available_start_date` (Date) - Earliest start date **[TODO]**
- `field_remote_preference` (List (text)) - Remote, Hybrid, On-site, No Preference **[TODO]**
- `field_relocation_willing` (Boolean) - Willing to relocate **[TODO]**
- `field_keywords_interested` (Text (long)) - Job search keywords and interests **[TODO]**
- `field_target_companies` (Entity reference, multiple) - Companies of interest **[TODO]**
- `field_target_job_titles` (Text (long)) - Desired job titles and roles **[TODO]**
- `field_experience_years` (Number, integer) - Years of professional experience **[TODO]**
- `field_education_level` (List (text)) - High School, Associates, Bachelors, Masters, PhD **[TODO]**
- `field_certifications` (Text (long)) - Professional certifications and licenses **[TODO]**
- `field_portfolio_url` (Link field) - Portfolio or personal website **[TODO]**
- `field_linkedin_url` (Link field) - LinkedIn profile URL **[TODO]**
- `field_github_url` (Link field) - GitHub profile URL **[TODO]**
- `field_references_available` (Boolean) - References available upon request **[TODO]**
- `field_cover_letter_template` (Text (long)) - Default cover letter template **[TODO]**
- `field_ai_analysis_data` (Text (long)) - JSON storage for AI-analyzed profile data **[TODO]**
- `field_profile_completeness` (Number, integer) - Profile completion percentage **[TODO]**
- `field_last_profile_update` (Date) - Last time profile was updated **[TODO]**
- `field_notification_preferences` (Text (long)) - JSON storage for notification settings **[TODO]**

### Installation Validation & Setup **[TODO]**
**Module Enable Hook (`hook_install`) Must:** **[TODO]**
1. **Check for Existing Content Types:** Verify if content types already exist before creating **[TODO]**
2. **Create Missing Content Types:** Create Company, Job Posting, Application, Error Queue types **[TODO]**
3. **Add All Required Fields:** Create and attach all fields to appropriate content types and user entity **[TODO]**
4. **Configure Field Settings:** Set appropriate field widgets, validation, and display settings **[TODO]**
5. **Set Permissions:** Configure appropriate permissions for different user roles **[TODO]**
6. **Create Default Vocabularies:** Create taxonomy vocabularies for list fields if needed **[TODO]**
7. **Initialize Configuration:** Set up default module configuration and settings **[TODO]**
8. **Create Initial Views:** Set up basic administrative views for content management **[TODO]**
9. **Validate Installation:** Run post-installation checks to ensure everything was created correctly **[TODO]**
10. **Log Installation Status:** Provide detailed logging of what was created vs. what already existed **[TODO]**

### Configuration Management **[TODO]**
**Field Configuration Details:** **[TODO]**

**List Field Options:** **[TODO]**
- `field_industry` options: Technology, Healthcare, Finance, Manufacturing, Retail, Education, Government, Non-profit, Other **[TODO]**
- `field_size` options: Startup (1-50), Small (51-200), Medium (201-1000), Large (1001-5000), Enterprise (5000+) **[TODO]**
- `field_remote_option` options: Remote, Hybrid, On-site **[TODO]**
- `field_employment_type` options: Full-time, Part-time, Contract, Temporary, Internship **[TODO]**
- `field_experience_level` options: Entry Level, Mid Level, Senior Level, Executive, Lead/Principal **[TODO]**
- `field_application_status` options: Submitted, Under Review, Interview Scheduled, Interview Completed, Offer Received, Rejected, Withdrawn, Archived **[TODO]**
- `field_error_type` options: Authentication Error, Scraping Error, Submission Error, Technical Error, Configuration Error **[TODO]**
- `field_priority` options: Low, Medium, High, Critical **[TODO]**
- `field_work_authorization` options: US Citizen, Permanent Resident, Work Visa (H1B), Student Visa (F1), Visa Sponsorship Required, Other **[TODO]**
- `field_education_level` options: High School, Associates Degree, Bachelors Degree, Masters Degree, Doctoral Degree, Professional Degree **[TODO]**

**Field Validation Rules:** **[TODO]**
- All URL fields must validate proper URL format **[TODO]**
- Email fields must validate email format **[TODO]**
- Date fields must not allow past dates for future-oriented fields **[TODO]**
- Number fields must have appropriate min/max ranges **[TODO]**
- File fields must restrict to appropriate file types (PDF for resumes, images for screenshots) **[TODO]**
- Required fields must be marked appropriately for each content type **[TODO]**

### Post-Installation Requirements **[TODO]**
**After Module Enable:** **[TODO]**
1. **Admin Setup Required:** Admin must configure at least one test company **[TODO]**
2. **User Profile Completion:** Users must complete minimum profile fields before automation **[TODO]**
3. **Credential Setup:** Users must provide employer credentials for automation **[TODO]**
4. **Testing Validation:** System must validate scraping and submission for configured employers **[TODO]**
5. **Permission Configuration:** Admin must assign appropriate roles and permissions **[TODO]**

This installation process ensures that all required data structures are in place for the job application automation system to function properly from the moment the module is enabled.

## Module Foundation Development Milestones

### Module Install/Enable Milestones **[COMPLETED]**
- [x] **Module Structure:** Create module directory structure and .info.yml file **[COMPLETED]**
- [x] **Hook Install:** Implement hook_install() to create content types **[COMPLETED]**
- [x] **Content Types:** Create Company, Job Posting, Application, Error Queue, Tailored Resume content types **[COMPLETED]**
- [x] **Custom Fields:** Create and attach all required custom fields to content types and user entity **[COMPLETED]**
- [x] **Field Configuration:** Set up field validation rules, widgets, and display settings **[COMPLETED]**
- [x] **Tailored Resume Body Field:** Fixed missing body field with update hook 8006 **[COMPLETED]**
- [ ] **Permissions Setup:** Configure appropriate permissions and user roles **[TODO]**
- [x] **Administrative Views:** Create basic administrative views for content management **[COMPLETED]**
- [ ] **Installation Testing:** Test installation on clean Drupal site **[TODO]**
- [x] **Verification:** Verify all content types and fields created correctly **[COMPLETED]**
- [x] **Post-Install Config:** Set up default module configuration and settings **[COMPLETED]**

### Module Disable/Uninstall Milestones **[COMPLETED]**
- [x] **Hook Uninstall:** Implement hook_uninstall() for proper cleanup **[COMPLETED]**
- [x] **Data Handling:** Handle data preservation vs. deletion options **[COMPLETED]**
- [x] **Field Cleanup:** Remove custom fields without breaking existing data **[COMPLETED]**
- [x] **Content Type Removal:** Clean removal of custom content types **[COMPLETED]**
- [x] **Configuration Cleanup:** Remove module configuration and permissions **[COMPLETED]**
- [ ] **Uninstall Testing:** Test uninstall doesn't break site functionality **[TODO]**

## User Onboarding & Profile Completion Workflow **[TODO]**

### Critical Success Factors for Job Applications: **[TODO]**
The system's effectiveness depends entirely on comprehensive user profile completion. Incomplete profiles result in:
- Poor AI resume analysis and job matching **[TODO]**
- Failed automated form submissions due to missing required fields **[TODO]**
- Reduced application success rates **[TODO]**
- Manual intervention requirements **[TODO]**

### Phase 1: Essential Profile Setup (Required for Basic Function) **[TODO]**
**User must complete these fields before system can function:** **[TODO]**

1. **Resume Upload & Analysis** **[TODO]**
   - Upload comprehensive, detailed resume (PDF/Word format) **[TODO]**
   - Wait for AI analysis completion (skills, experience, completeness scoring) **[TODO]**
   - Review AI-generated skills assessment and approve/correct **[TODO]**
   - Achieve minimum 70% completeness score before proceeding **[TODO]**

2. **Core Personal Information** **[TODO]**
   - Legal name (first, middle, last) and preferred professional name **[TODO]**
   - Primary contact information (phone, email, address) **[TODO]**
   - Professional online presence (LinkedIn, portfolio website) **[TODO]**

3. **Work Authorization Status** **[TODO]**
   - US work authorization status and documentation **[TODO]**
   - Visa information and expiration dates (if applicable) **[TODO]**
   - Security clearance level (if applicable) **[TODO]**
   - Professional licenses and certifications **[TODO]**

### Phase 2: Employment Preferences & Requirements **[TODO]**
**Configure job search parameters and requirements:** **[TODO]**

4. **Job Search Preferences** **[TODO]**
   - Keywords and job types of interest **[TODO]**
   - Employment type preferences (full-time, part-time, contract) **[TODO]**
   - Remote work preferences and location flexibility **[TODO]**
   - Salary expectations and compensation requirements **[TODO]**

5. **Availability & Schedule** **[TODO]**
   - Earliest available start date **[TODO]**
   - Schedule preferences and restrictions **[TODO]**
   - Travel willingness and overtime availability **[TODO]**
   - Relocation willingness and assistance needs **[TODO]**

6. **References & Professional Network** **[TODO]**
   - Minimum 3 professional references with contact information **[TODO]**
   - Permission settings for reference and current employer contact **[TODO]**
   - Professional network connections and recommendations **[TODO]**

### Phase 3: EEO & Compliance Information (Optional but Recommended) **[TODO]**
**Complete diversity and compliance data for broader job opportunities:** **[TODO]**

7. **EEO Information (Optional)** **[TODO]**
   - Disability status disclosure (for ADA preference) **[TODO]**
   - Veteran status (for veteran preference programs) **[TODO]**
   - Diversity demographics (gender, race, sexual orientation) **[TODO]**
   - Consent for EEO data usage in reporting

8. **Background & Screening Consents**
   - Background check authorization
   - Drug testing consent
   - Credit check permission (for financial roles)
   - Social media screening authorization

### Phase 4: Industry-Specific & Advanced Requirements
**Complete specialized information for specific industries:**

9. **Industry-Specific Data**
   - Driving record and license information (if applicable)
   - Physical requirements capabilities
   - Language proficiencies and certifications
   - Specialized training and continuing education

10. **Employment History Details**
    - Detailed employment history with supervisor contacts
    - Salary history (where legally collectible)
    - Reasons for leaving previous positions
    - Employment gap explanations

### Profile Completeness Scoring System:
The AI system calculates profile completeness based on:

- **Resume Quality (40%):** Detailed experience, skills, quantified achievements
- **Core Information (30%):** Contact info, work authorization, preferences  
- **Reference Verification (15%):** Professional references with confirmed contact info
- **Industry Requirements (10%):** Field-specific certifications and requirements
- **EEO/Compliance Data (5%):** Optional but increases application opportunities

### User Guidance & Notifications:
**Progressive Disclosure Approach:**

1. **Initial Setup (Days 1-3):** Focus on resume upload and core information
2. **Profile Enhancement (Week 1):** Add preferences, references, and availability
3. **Ongoing Optimization (Monthly):** Update information, add new skills/experience
4. **Compliance Completion (As Needed):** Industry-specific requirements per job type

### Data Validation & Verification:
**Automated Validation Checks:**

- **Resume Analysis:** AI validates resume completeness and suggests improvements
- **Contact Verification:** Email/phone validation with confirmation codes
- **Reference Verification:** Optional reference contact confirmation
- **Employment Verification:** Cross-reference employment dates and companies
- **License Verification:** API checks for professional license validity (where available)

### Profile Maintenance & Updates:
**Ongoing Profile Management:**

- **Quarterly Profile Reviews:** System prompts for information updates
- **Skills Assessment Updates:** Regular AI re-analysis of resume and experience
- **Market Alignment Checks:** Compare profile to current job market requirements
- **Privacy Settings Management:** Control what information is shared with employers
- **Data Export/Deletion:** GDPR compliance for user data management

## Detailed Process Flows

### Flow 1: New User Profile Creation Process

#### Step 1: Initial Registration & Welcome
**User Actions:**
1. User creates Drupal account with basic information (email, password)
2. System displays welcome screen with process overview
3. User acknowledges understanding of comprehensive data requirements
4. System creates initial User Profile Entity record

**System Actions:**
- Create user profile with default incomplete status
- Initialize completeness score at 0%
- Send welcome email with getting started guide
- Log user registration event

**Validation Requirements:**
- Valid email address with confirmation
- Password meeting security requirements
- Terms of service and privacy policy acceptance

#### Step 2: Critical Resume Upload & AI Analysis
**User Actions:**
1. Upload comprehensive resume (PDF/Word/TXT formats accepted)
2. Wait for AI processing confirmation (typically 2-5 minutes)
3. Review AI-generated skills analysis and experience summary **[TODO]**
4. Approve or correct AI interpretations of skills and experience levels **[TODO]**
5. Receive completeness score and improvement recommendations **[TODO]**

**System Actions:** **[TODO]**
- Parse resume using OCR/text extraction libraries **[TODO]**
- Send resume content to GenAI service for analysis **[TODO]**
- Extract skills, experience levels, and career progression **[TODO]**
- Generate competency matrix and experience timeline **[TODO]**
- Calculate initial completeness score (typically 20-40% at this stage) **[TODO]**
- Store AI analysis results in skills_analysis JSON field **[TODO]**
- Create first version of tailored resume template **[TODO]**

**AI Integration Points:** **[TODO]**
- Skills extraction and proficiency assessment **[TODO]**
- Experience level determination (entry, mid, senior, executive) **[TODO]**
- Industry classification and job category suggestions
- Gap analysis against common job requirements
- Resume optimization recommendations

**Validation Requirements:**
- File format validation and virus scanning
- Minimum resume length (typically 1+ pages)
- Basic structure validation (contact info, experience sections)
- AI confidence score above threshold (70%+)

**Error Handling:**
- Unsupported file formats → user guidance for format conversion
- Poor quality scans → OCR improvement suggestions
- AI analysis failures → manual review queue notification
- Insufficient content → specific improvement guidance

#### Step 3: Essential Personal Information Collection
**User Actions:**
1. Complete contact information form (name, phone, email, address)
2. Add professional online presence (LinkedIn, portfolio URLs)
3. Verify contact information through email/SMS confirmation
4. Set communication preferences and privacy settings

**System Actions:**
- Update user profile with personal information
- Send verification codes to email and phone
- Validate LinkedIn profile accessibility
- Update completeness score (now typically 35-50%)

**Validation Requirements:**
- Email confirmation within 24 hours
- Phone number verification (optional but recommended)
- LinkedIn profile public accessibility check
- Address validation through postal service APIs

#### Step 4: Work Authorization & Legal Status
**User Actions:**
1. Select work authorization status (US citizen, permanent resident, visa holder)
2. If on visa: provide visa type, expiration date, and restrictions
3. Enter security clearance information (if applicable)
4. Add professional licenses and certifications with expiration dates
5. Consent to background checks and screening procedures

**System Actions:**
- Store work authorization data with appropriate encryption
- Set up expiration date monitoring for visas and licenses
- Flag any work authorization limitations for job matching
- Update completeness score (now typically 50-65%)

**Critical Business Logic:**
- Visa expiration warnings 90 days in advance
- Job matching filters based on sponsorship requirements
- Automatic exclusion from jobs requiring specific clearances
- License renewal reminders and verification

#### Step 5: Job Search Preferences & Availability
**User Actions:**
1. Define job search keywords and categories of interest
2. Set employment type preferences (full-time, part-time, contract)
3. Configure location preferences and remote work options
4. Set salary expectations and compensation requirements
5. Define availability (start date, schedule, travel willingness)

**System Actions:**
- Store preferences for job matching algorithms
- Initialize employer search based on keywords
- Set up job alert parameters
- Update completeness score (now typically 70-85%)

**AI Integration Points:**
- Keyword optimization based on resume analysis
- Salary range suggestions based on experience and market data
- Location recommendations based on industry concentrations
- Job category suggestions aligned with skills and experience

#### Step 6: References & Professional Network
**User Actions:**
1. Add minimum 3 professional references with full contact information
2. Define relationship and permission levels for each reference
3. Set current employer contact preferences
4. Add professional network connections and recommendations

**System Actions:**
- Store reference data with encryption
- Send optional reference verification emails
- Flag references for validation before job applications
- Update completeness score (now typically 80-95%)

**Validation & Verification:**
- Email format validation for all reference contacts
- Optional reference confirmation (references receive email explaining system)
- Professional relationship validation (no family members, etc.)
- Contact information completeness checks

#### Step 7: Optional EEO & Advanced Information
**User Actions:**
1. Complete optional EEO information (disability, veteran status, demographics)
2. Add industry-specific requirements (driving record, physical capabilities)
3. Complete advanced employment history details
4. Set privacy preferences for sensitive information

**System Actions:**
- Store EEO data with strict privacy controls
- Enable EEO-based job matching where beneficial
- Complete profile with 95-100% completeness score
- Activate full job discovery and matching capabilities

### Profile Creation Success Metrics:
- **Completion Time:** Target 45-60 minutes for full profile
- **Completeness Threshold:** Minimum 70% required for job applications
- **AI Accuracy:** Skills analysis accuracy above 85%
- **User Satisfaction:** Post-setup survey rating above 4.0/5.0

### Profile Creation User Experience Guidelines:
- **Progressive Disclosure:** Show only relevant fields based on previous answers
- **Save-and-Continue:** Allow users to complete profile over multiple sessions
- **Real-time Validation:** Immediate feedback on field completion and errors
- **Visual Progress:** Clear completion percentage and next steps guidance
- **Help & Support:** Contextual help and live chat support during setup

### Flow 2: Company Selection & Employer Management Process

#### Step 1: Employer Discovery & Research
**User Actions:**
1. Browse recommended employers based on profile analysis and keywords
2. Search for specific companies by name, industry, or location
3. Review company profiles including size, industry, culture, and benefits
4. Read system-provided information about application processes and requirements
5. Add companies to "interested employers" list for further evaluation

**System Actions:**
- Generate employer recommendations based on user skills and preferences
- Display employer profiles with scraped company information
- Show job posting frequency and types of roles typically available
- Provide application success rate statistics for similar profiles
- Track user interest patterns for recommendation improvement

**AI Integration Points:**
- Company-to-profile matching based on skills and experience
- Culture fit analysis based on company values and user preferences
- Salary range alignment between user expectations and company standards
- Career growth potential assessment based on company structure

**Information Displayed for Each Employer:**
- Company overview (size, industry, locations, culture)
- Typical job categories and requirements
- Application process complexity (simple form vs. multi-step)
- ATS system type and known compatibility issues
- Average application processing time and response rates
- Benefits and compensation patterns
- Recent job posting activity and frequency

#### Step 2: Employer Selection & Prioritization
**User Actions:**
1. Select primary target employers (recommended 5-15 companies)
2. Prioritize employers by preference level (high, medium, low)
3. Set specific keywords and job types for each employer
4. Configure notification preferences for each employer
5. Review and confirm employer application requirements

**System Actions:**
- Create User-Employer relationship records
- Initialize job scraping schedules based on employer posting patterns
- Set up keyword monitoring for each employer
- Configure notification triggers based on user preferences
- Begin preliminary job discovery for selected employers

**Employer Configuration Options:**
- **Job Keywords:** Specific terms to monitor (e.g., "software engineer," "remote," "senior")
- **Employment Types:** Full-time, part-time, contract, internship preferences
- **Location Preferences:** Specific offices, remote-eligible, relocation considerations
- **Notification Settings:** Immediate, daily digest, or weekly summary
- **Application Automation:** Fully automated, review-before-submit, or manual only

#### Step 3: Credential Setup & Authentication
**User Actions:**
1. For each selected employer, provide login credentials for their career portal
2. Test credentials by attempting login through the system
3. Set up multi-factor authentication handling (where required)
4. Configure additional authentication methods (security questions, etc.)
5. Set credential update and validation schedules

**System Actions:**
- Encrypt and store credentials using industry-standard encryption
- Perform initial credential validation test
- Set up automated credential validation schedule (weekly/monthly)
- Create credential monitoring alerts for expiration or failures
- Log all credential access for security auditing

**Credential Management Features:**
- **Secure Storage:** End-to-end encryption with key management
- **Validation Testing:** Regular automated login tests
- **Expiration Monitoring:** Alerts for password changes or account issues
- **MFA Support:** Integration with authenticator apps and SMS verification
- **Access Logging:** Complete audit trail of credential usage

**Security Considerations:**
- Users receive clear warnings about credential storage and security
- Option to use "test mode" with dummy credentials initially
- Regular security assessments and credential rotation recommendations
- Immediate alerts for failed login attempts or security issues
- User control over credential sharing and usage permissions

#### Step 4: Job Discovery Configuration
**User Actions:**
1. Configure scraping frequency for each employer (daily, weekly, specific days)
2. Set job discovery preferences (new postings only, all active postings)
3. Define job matching criteria and relevance thresholds
4. Set up job alert preferences and delivery methods
5. Configure automatic application triggers (if desired)

**System Actions:**
- Initialize web scraping schedules for each employer
- Set up job posting monitoring and change detection
- Configure keyword matching algorithms for job discovery
- Create job alert queues and notification schedules
- Begin active job discovery and matching processes

**Advanced Configuration Options:**
- **Scraping Schedule:** Optimal times to check for new postings
- **Relevance Scoring:** Minimum match threshold for job alerts
- **Application Triggers:** Automatic vs. manual application decisions  
- **Alert Filtering:** Avoid duplicate or similar job notifications
- **Backup Monitoring:** Secondary job boards and aggregators

#### Step 5: Application Process Customization
**User Actions:**
1. For each employer, review typical application process flow
2. Configure application preferences (fully automated vs. review-first)
3. Set up employer-specific resume and cover letter customizations
4. Define application timing preferences (immediate vs. scheduled)
5. Configure error handling and manual fallback preferences

**System Actions:**
- Map employer application processes and form requirements
- Create employer-specific resume templates and customizations
- Set up application workflow triggers and automation rules
- Configure error handling and manual fallback procedures
- Initialize application success tracking and optimization

**Per-Employer Application Configuration:**
- **Automation Level:** Full automation, review-before-submit, or manual only
- **Resume Customization:** Employer-specific resume tailoring preferences
- **Cover Letter:** Auto-generate, use template, or skip
- **Application Timing:** Submit immediately, batch processing, or scheduled times
- **Error Handling:** Automatic retry, immediate manual fallback, or queue for review

### Company Selection Success Metrics:
- **Employer Setup Time:** Target 10-15 minutes per employer
- **Credential Success Rate:** 95%+ successful initial credential validation
- **Job Discovery Accuracy:** 80%+ relevant job matches based on keywords
- **User Satisfaction:** Post-setup rating above 4.2/5.0 for process clarity

### Company Selection Best Practices:
- **Start Small:** Recommend 3-5 employers initially, expand based on success
- **Quality Over Quantity:** Better to have well-configured employers than many poorly set up
- **Regular Reviews:** Monthly review of employer performance and job match quality
- **Credential Security:** Strong emphasis on security practices and regular updates
- **Success Tracking:** Monitor application success rates per employer for optimization

### Flow 3: Job Discovery & Application Process

#### Phase 1: Automated Job Discovery
**System Actions (Continuous Background Process):**
1. **Web Scraping Execution:**
   - Run scheduled scraping jobs for each configured employer
   - Extract job postings from career pages using configured scraping rules
   - Detect new postings, changes to existing posts, and removed positions
   - Parse job descriptions, requirements, and application URLs
   - Store raw job data in Job Posting Entity

2. **Job Analysis & Processing:**
   - Send job descriptions to GenAI service for requirement analysis
   - Extract key skills, experience levels, and qualification requirements
   - Identify compensation information, location details, and employment type
   - Analyze job complexity and application process requirements
   - Generate job posting summary and key highlights

3. **User Matching & Relevance Scoring:**
   - Compare job requirements against user profile and skills
   - Calculate relevance score based on keyword matching and skill alignment
   - Assess qualification match percentage and experience level fit
   - Factor in user preferences (location, salary, remote work, etc.)
   - Generate personalized job recommendations with match reasoning

4. **Notification & Alert Generation:**
   - Create job alerts for high-relevance matches above user threshold
   - Generate daily/weekly digest emails with new opportunities
   - Send immediate notifications for high-priority or urgent postings
   - Update user dashboard with new job matches and application opportunities
   - Track notification delivery and user engagement patterns

**Job Discovery Success Criteria:**
- Discover 95%+ of relevant new job postings within 24 hours of posting
- Maintain <5% false positive rate for job match relevance
- Achieve 80%+ accuracy in skills requirement extraction
- Process job descriptions within 2 minutes of discovery

#### Phase 2: User Job Review & Selection
**User Actions:**
1. **Dashboard Review:**
   - Log into personal dashboard to review new job matches
   - View job cards with relevance scores, key requirements, and match reasoning
   - Filter and sort jobs by relevance, posting date, salary, location, etc.
   - Read AI-generated job summaries and requirement highlights
   - Mark jobs as "interested," "not interested," or "save for later"

2. **Detailed Job Analysis:**
   - Click on job cards to view comprehensive job details
   - Review full job description, requirements, and company information
   - See AI analysis of qualification match and potential application success
   - View tailored resume preview showing how profile aligns with requirements
   - Check application complexity assessment and estimated submission success rate

3. **Application Decision Making:**
   - Select jobs for application submission
   - Choose automation level: "Apply Now" (fully automated) or "Review First" (approval required)
   - Set application timing preferences (immediate, scheduled, batch processing)
   - Add personal notes or customizations for specific applications
   - Confirm understanding of application process and requirements

**User Interface Features:**
- **Job Cards:** Compact view with key information and quick actions
- **Relevance Scoring:** Visual indicators (stars, percentages) showing job match quality
- **Filter & Search:** Advanced filtering by multiple criteria simultaneously
- **Save & Organize:** Ability to save jobs to custom lists and categories
- **Application History:** Track previously applied positions to avoid duplicates

#### Phase 3: AI-Powered Resume Tailoring
**System Actions (Triggered by User Application Decision):**
1. **Job-Specific Analysis:**
   - Deep analysis of job requirements and preferred qualifications
   - Identification of key skills, technologies, and experience emphasized
   - Assessment of company culture and values from job description
   - Analysis of ATS system requirements and keyword optimization needs
   - Comparison with user's experience and skill set for optimal positioning

2. **Resume Customization:**
   - Restructure resume sections to emphasize most relevant experience
   - Optimize keyword density for ATS systems while maintaining readability
   - Highlight achievements and projects most relevant to job requirements
   - Adjust professional summary to align with job-specific priorities
   - Ensure resume length and format meet employer preferences

3. **Cover Letter Generation (If Required):**
   - Generate personalized cover letter based on job requirements and user experience
   - Incorporate company research and specific job details
   - Highlight most relevant qualifications and achievements
   - Maintain authentic voice while optimizing for job requirements
   - Format according to company preferences and application system requirements

4. **Application Package Assembly:**
   - Compile tailored resume in required format (PDF, Word, etc.)
   - Generate cover letter if required by application process
   - Prepare any additional documents (portfolio samples, certifications, etc.)
   - Validate file formats, sizes, and naming conventions
   - Create backup copies and version tracking

**AI Integration Quality Metrics:**
- Resume relevance score improvement: 25%+ increase over generic resume
- ATS compatibility score: 90%+ for resume parsing success
- Cover letter personalization score: 85%+ unique content vs. template
- Processing time: Complete tailoring within 3-5 minutes

#### Phase 4: Automated Application Submission
**System Actions (For Fully Automated Applications):**
1. **Pre-Submission Validation:**
   - Validate user credentials for employer application system
   - Confirm job posting is still active and accepting applications
   - Verify all required documents and information are available
   - Check for any application deadline constraints
   - Perform final compatibility check for application system

2. **Browser Automation & Form Completion:**
   - Launch automated browser session with employer application portal
   - Navigate to specific job application page
   - Authenticate using stored user credentials (handling MFA if required)
   - Intelligently map user profile data to application form fields
   - Upload tailored resume, cover letter, and supporting documents
   - Complete multi-page application flows with error detection

3. **Form Field Population:**
   - **Personal Information:** Auto-populate name, contact details, address
   - **Employment History:** Enter previous positions, dates, responsibilities
   - **Education Background:** Complete education sections with dates and details
   - **Work Authorization:** Select appropriate authorization status and details
   - **EEO Information:** Populate optional diversity questions based on user consent
   - **References:** Enter reference contact information and relationships
   - **Custom Questions:** Use AI to answer employer-specific questions when possible

4. **Submission & Confirmation:**
   - Review completed application for accuracy and completeness
   - Submit application through employer's system
   - Capture confirmation screens and reference numbers
   - Save application confirmation details and tracking information
   - Update job application status to "Submitted Successfully"
   - Generate user notification with application summary and confirmation

**Automation Success Metrics:**
- Successful submission rate: 85%+ for standard application forms
- Form field accuracy: 98%+ correct data population
- Submission time: Complete applications within 10-15 minutes
- Error detection rate: 95%+ of issues caught before final submission

#### Phase 5: Error Handling & Manual Fallback
**When Automation Fails:**
1. **Error Detection & Classification:**
   - **Authentication Errors:** Expired passwords, MFA challenges, account lockouts
   - **Form Structure Changes:** New fields, modified layouts, updated requirements
   - **Technical Issues:** Website downtime, CAPTCHA challenges, file upload failures
   - **Content Restrictions:** Blocked keywords, file format rejections, size limitations
   - **Process Changes:** Multi-step workflows, new verification requirements

2. **Immediate User Notification:**
   - Send real-time notification about automation failure
   - Provide specific error details and recommended next steps
   - Offer tailored resume and cover letter for manual completion
   - Include direct link to job application page
   - Estimate time required for manual completion (typically 15-30 minutes)

3. **Manual Completion Support:**
   - **Tailored Documents:** Provide job-specific resume and cover letter ready for upload
   - **Pre-filled Information:** Generate text snippets for common form fields
   - **Step-by-Step Guidance:** Provide instructions for completing application manually
   - **Field Mapping:** Show how profile information maps to application form fields
   - **Completion Tracking:** Allow user to mark application as manually completed

4. **Admin Queue Management:**
   - Log error details in Workflow Error Queue Entity
   - Assign priority level based on error type and user impact
   - Notify admin team of automation failures requiring system updates
   - Track resolution time and automation improvement opportunities
   - Update automation scripts based on resolved errors

**Error Resolution Workflow:**
- **Immediate Response:** User notification and manual fallback within 5 minutes
- **Admin Review:** Error queue review within 24 hours for high-priority issues
- **System Updates:** Automation improvements deployed within 48-72 hours
- **User Follow-up:** Confirmation of issue resolution and prevention measures

#### Phase 6: Application Tracking & Follow-up
**Ongoing Monitoring:**
1. **Status Tracking:**
   - Monitor application status through employer portals where possible
   - Track email confirmations and communication from employers
   - Update application status based on employer feedback
   - Log interview requests, rejections, and offers received
   - Maintain comprehensive application history and timeline

2. **Follow-up Management:**
   - Generate follow-up reminders based on application timelines
   - Suggest appropriate follow-up actions and timing
   - Draft follow-up emails and messages when appropriate
   - Track response rates and employer engagement patterns
   - Optimize follow-up strategies based on success data

3. **Performance Analytics:**
   - Calculate application success rates per employer and job type
   - Track time-to-response and interview conversion rates
   - Analyze rejection reasons and improvement opportunities
   - Monitor resume effectiveness and tailoring success
   - Generate insights for profile and strategy optimization

**Application Success Metrics:**
- Application completion rate: 90%+ of attempted applications successfully submitted
- Response rate: Track employer responses and engagement levels
- Interview conversion: Monitor applications that lead to interview requests
- User satisfaction: Post-application surveys and experience ratings
- Time efficiency: Average 5-10 minutes per automated application vs. 30-45 minutes manual

### End-to-End Process Success Criteria: **[TODO]**
- **Profile to First Application:** Complete workflow within 2-3 hours for new users **[TODO]**
- **Application Volume:** Support 5-20 applications per week per active user **[TODO]**
- **Success Rate:** 85%+ successful automated applications with <15% manual fallback **[TODO]**
- **User Engagement:** 80%+ of users apply to 3+ jobs within first week of setup **[TODO]**
- **Quality Maintenance:** Maintain high application quality while increasing volume and efficiency **[TODO]**

### Flow 4: Admin Error Queue Management Process (MVP Implementation) **[TODO - MVP PRIORITY]**

#### MVP Implementation: Simple Error Queue List **[TODO]**
**Admin Interface (Simplified):** **[TODO]**
1. **Error Queue Dashboard:** **[TODO]**
   - Display simple list of failed automation attempts **[TODO]**
   - Show error details: user, employer, job posting, error type, timestamp **[TODO]**
   - Provide checkbox for each error to mark as "Fixed" **[TODO]**
   - No complex categorization or assignment - just a basic list view **[TODO]**
   - Basic filtering by date and employer **[TODO]**

2. **Add Company Function:** **[TODO]**
   - Simple "Add Company" button that creates new Drupal company node **[TODO]**
   - Standard Drupal node creation form for company information **[TODO]**
   - Store basic company details: name, website, careers URL **[TODO]**
   - No complex scraping configuration in MVP - just basic company data **[TODO]**
   - Companies automatically populate admin error queue when issues arise **[TODO]**

**Company Node Fields (MVP):** **[TODO]**
- `title` - Company name **[TODO]**
- `field_website` - Company website URL **[TODO]**
- `field_careers_url` - Career page URL for scraping **[TODO]**
- `field_active` - Boolean for active/inactive status **[TODO]**
- `field_scraping_notes` - Text field for admin notes
- `created` - Standard Drupal creation timestamp

**MVP Error Queue Entity (Simplified):**
- `id` - Unique identifier
- `company_node_id` - Reference to company node
- `user_id` - Reference to affected user
- `error_message` - Basic error description
- `fixed` - Boolean checkbox field
- `created` - Error timestamp

#### Phase 2+ (Future Enhancement): Complex Error Management **[SHELVED]**
*Shelved for future implementation:* **[SHELVED]**
- Complex error categorization and prioritization **[SHELVED]**
- Admin assignment and workflow management **[SHELVED]**
- User communication automation **[SHELVED]**
- Resolution tracking and analytics **[SHELVED]**
- Automated error pattern detection **[SHELVED]**

### Flow 4 Development Milestones **[TODO - MVP PRIORITY]**

#### Core Development Tasks **[TODO]**
- [ ] **Error Queue Content Type:** Create error_queue content type with all required fields **[TODO]**
- [ ] **Node CRUD Operations:** Test error record creation, reading, updating, deletion **[TODO]**
- [ ] **Admin List Interface:** Simple admin view showing all errors with basic filtering **[TODO]**
- [ ] **Checkbox Functionality:** "Fixed" checkbox field with form integration **[TODO]**
- [ ] **Company Integration:** "Add Company" button creating new company nodes **[TODO]**
- [ ] **Company Form:** Standard Drupal node creation form for company information **[TODO]**
- [ ] **Error Capture System:** Automated error detection and error queue population **[TODO]**
- [ ] **Basic Filtering:** Date range and employer filtering functionality **[TODO]**
- [ ] **Admin Workflow:** Complete mark-as-fixed workflow with status updates **[TODO]**

#### Testing & Integration **[TODO]**
- [ ] **Error Generation Testing:** Simulate various error conditions **[TODO]**
- [ ] **Admin Interface Testing:** Verify all admin functions work correctly **[TODO]**
- [ ] **Company Creation Testing:** Test company node creation from admin interface **[TODO]**
- [ ] **Data Integrity:** Ensure error data is properly stored and retrieved **[TODO]**
- [ ] **User Permission Testing:** Verify only admins can access error queue **[TODO]**

#### Success Criteria **[TODO]**
- [ ] **End-to-End Success:** Admin can view errors, add companies, and mark issues as fixed **[TODO]**
- [ ] **Data Flow:** Errors automatically populate queue when system issues occur **[TODO]**
- [ ] **Admin Efficiency:** Simple, intuitive interface requiring minimal training **[TODO]**

### Flow 5: User Support & Troubleshooting Process (MVP Implementation) **[TODO - MVP PRIORITY]**

#### MVP Implementation: Simple Contact Form **[TODO]**
**User Support Interface:** **[TODO]**
1. **Basic Contact Form:** **[TODO]**
   - Standard Drupal contact form or webform implementation **[TODO]**
   - Fields: Name, Email, Subject, Message, Issue Type (dropdown) **[TODO]**
   - Issues manually handled by admin via email notifications **[TODO]**
   - No automated triage or response system in MVP **[TODO]**

**Contact Form Fields:** **[TODO]**
- `name` - User's name **[TODO]**
- `email` - Contact email address **[TODO]**
- `issue_type` - Dropdown: Technical Issue, Account Problem, General Question **[TODO]**
- `subject` - Brief description of issue **[TODO]**
- `message` - Detailed issue description **[TODO]**
- `user_id` - Auto-populated if user logged in **[TODO]**

#### Phase 2+ (Future Enhancement): Advanced Support System **[SHELVED]**
*Shelved for future implementation:* **[SHELVED]**
- Automated issue triage and categorization **[SHELVED]**
- Support ticket tracking system **[SHELVED]**
- Response time monitoring and SLA management **[SHELVED]**
- Knowledge base and self-service options **[SHELVED]**
- Integration with error queue for technical issues **[SHELVED]**

### Flow 5 Development Milestones **[TODO - MVP PRIORITY]**

#### Core Development Tasks **[TODO]**
- [ ] **Contact Form Setup:** Implement Drupal contact form or webform module **[TODO]**
- [ ] **Form Fields:** Create all required form fields with proper validation **[TODO]**
- [ ] **Field Configuration:** Set up dropdown options, required fields, and field widgets **[TODO]**
- [ ] **Email Integration:** Configure admin email notifications on form submission **[TODO]**
- [ ] **User Access:** Make contact form accessible from user dashboard **[TODO]**
- [ ] **Form Processing:** Handle form submission and data storage **[TODO]**
- [ ] **User Experience:** Create intuitive form layout and help text **[TODO]**

#### Testing & Integration **[TODO]**
- [ ] **Form Submission Testing:** Test all form fields and validation rules **[TODO]**
- [ ] **Email Delivery Testing:** Verify admin receives notifications **[TODO]**
- [ ] **User Interface Testing:** Ensure form is accessible and user-friendly **[TODO]**
- [ ] **Data Storage Testing:** Verify form submissions are properly stored **[TODO]**
- [ ] **Integration Testing:** Test form access from various user states **[TODO]**

#### Success Criteria **[TODO]**
- [ ] **End-to-End Success:** User can submit issue, admin receives and can respond **[TODO]**
- [ ] **User Experience:** Form is intuitive and provides clear feedback **[TODO]**
- [ ] **Admin Workflow:** Admins can efficiently manage and respond to support requests **[TODO]**

### Flow 6: System Monitoring & Performance Management (Noted for Future) **[NOTED - NOT MVP]**

#### MVP Status: Not Implemented **[NOTED]**
**Note:** System monitoring and performance management will be addressed in Phase 2+ once core functionality is stable. **[NOTED]**

**Future Implementation Considerations:** **[NOTED]**
- Basic uptime monitoring **[NOTED]**
- Application success rate tracking **[NOTED]**
- Simple performance metrics **[NOTED]**
- Error rate monitoring **[NOTED]**
- User activity analytics **[NOTED]**

*This flow is noted but ignored for MVP development.* **[NOTED]**

### Flow 7: Profile Updates & Maintenance Process (MVP Implementation) **[TODO - MVP PRIORITY]**

#### MVP Implementation: Standard Drupal Profile Management **[TODO]**
**User Profile Management:** **[TODO]**
1. **Drupal User Profile Extension:** **[TODO]**
   - Extend standard Drupal user entity with custom fields **[TODO]**
   - Use Drupal's built-in user registration and login system **[TODO]**
   - Standard profile edit forms with custom fields for job application data **[TODO]**
   - No automated review prompts or AI-driven suggestions in MVP **[TODO]**

**Custom User Profile Fields (MVP):**
- `field_resume_file` - File field for resume upload
- `field_skills_summary` - Long text field for skills description
- `field_work_authorization` - Text field for work eligibility status
- `field_salary_expectation` - Number field for salary range
- `field_available_start_date` - Date field for availability
- `field_remote_preference` - List field: Remote, Hybrid, On-site
- `field_keywords_interested` - Text field for job search keywords
- `field_professional_summary` - Long text field for profile summary

**User Profile Management Features:**
- Standard Drupal user account registration and login
- Basic profile edit form with custom fields **[TODO]**
- Resume file upload and storage using Drupal file system **[TODO]**
- Simple form validation for required fields **[TODO]**
- No automated profile completeness scoring in MVP **[TODO]**

#### Phase 2+ (Future Enhancement): Intelligent Profile Management **[SHELVED]**
*Shelved for future implementation:* **[SHELVED]**
- AI-powered profile completeness scoring **[SHELVED]**
- Automated review reminders and prompts **[SHELVED]**
- Skills analysis and improvement suggestions **[SHELVED]**
- Performance-based profile optimization **[SHELVED]**
- Market alignment recommendations **[SHELVED]**

### Flow 7 Development Milestones **[TODO - MVP PRIORITY]**

#### Core Development Tasks **[TODO]**
- [ ] **User Entity Extension:** Add all 24 custom fields to user entity **[TODO]**
- [ ] **Profile Edit Forms:** Create comprehensive profile editing forms **[TODO]**
- [ ] **Field Validation:** Implement required fields and format validation **[TODO]**
- [ ] **File Upload System:** Resume upload with PDF/Word validation **[TODO]**
- [ ] **Data Storage:** Ensure profile data is properly saved and retrieved **[TODO]**
- [ ] **User Registration Integration:** Extend Drupal registration with custom fields **[TODO]**
- [ ] **Profile Dashboard:** Create user profile management interface **[TODO]**
- [ ] **Completeness Tracking:** Calculate and display profile completion percentage **[TODO]**

#### AI Integration (Future Phase) **[SHELVED]**
- [ ] **Resume Analysis:** AI-powered resume parsing and skills extraction **[SHELVED]**
- [ ] **Skills Assessment:** AI-generated competency assessments **[SHELVED]**
- [ ] **Profile Optimization:** AI-driven profile improvement suggestions **[SHELVED]**

#### Testing & Integration **[TODO]**
- [ ] **Form Testing:** Test all profile forms and field validation **[TODO]**
- [ ] **File Upload Testing:** Verify resume upload and file handling **[TODO]**
- [ ] **Data Integrity Testing:** Ensure profile data persistence **[TODO]**
- [ ] **User Experience Testing:** Verify intuitive profile completion flow **[TODO]**
- [ ] **Integration Testing:** Test profile data usage in other system components **[TODO]**

#### Success Criteria **[TODO]**
- [ ] **End-to-End Success:** User can complete full profile and achieve 70%+ completeness **[TODO]**
- [ ] **Data Completeness:** All required fields properly collected and validated **[TODO]**
- [ ] **User Experience:** Intuitive, progressive profile completion process **[TODO]**

### Flow 8: Employer Relationship Management Process (MVP Implementation) **[TODO - MVP PRIORITY]**

#### MVP Implementation: Drupal Node-Based Employer Management
**Employer Management:**
1. **Company Drupal Nodes:**
   - Each employer represented as a Drupal content node
   - Standard node creation/editing forms for employer information
   - Simple relationship between user and employer nodes
   - Basic employer list view for users to select target companies

**Company Node Structure (MVP):**
- Standard Drupal content type: "Company"
- `title` - Company name
- `field_website` - Company website URL
- `field_careers_url` - Career page URL
- `field_description` - Company description
- `field_industry` - Industry category
- `field_size` - Company size (Small, Medium, Large, Enterprise)
- `field_active` - Boolean for active job scraping

**User-Employer Relationship (MVP):**
- Simple taxonomy or reference field linking users to companies of interest
- `field_target_companies` - Entity reference field (multiple) on user profile
- `field_company_monitoring_status` - Boolean field per company (active/paused)

**Basic Employer Functions:**
- **Pause/Resume Monitoring:** Simple checkbox to enable/disable job scraping per employer
- **Add/Remove Employers:** Standard Drupal node references on user profile
- No credential management in MVP - manual application completion initially **[TODO]**

#### Phase 2+ (Future Enhancement): Advanced Employer Management **[SHELVED]**
*Shelved for future implementation:* **[SHELVED]**
- Credential storage and management **[SHELVED]**
- Application automation preferences per employer **[SHELVED]**
- Performance tracking per employer relationship **[SHELVED]**
- Advanced configuration and customization options **[SHELVED]**
- Employer relationship cleanup and archival **[SHELVED]**

### Flow 8 Development Milestones **[TODO - MVP PRIORITY]**

#### Core Development Tasks **[TODO]**
- [ ] **Company Content Type:** Create company content type with all required fields **[TODO]**
- [ ] **Node CRUD Operations:** Test company node creation, reading, updating, deletion **[TODO]**
- [ ] **User-Company Relations:** Entity reference field linking users to target companies **[TODO]**
- [ ] **Company Selection Interface:** User interface to browse and select companies **[TODO]**
- [ ] **Monitoring Controls:** Pause/resume job monitoring functionality per company **[TODO]**
- [ ] **Company Data Management:** Admin interface to add/edit company information **[TODO]**
- [ ] **Company List Views:** User and admin views of companies **[TODO]**
- [ ] **Relationship Management:** User can add/remove target companies **[TODO]**

#### Testing & Integration **[TODO]**
- [ ] **Company CRUD Testing:** Test all company node operations **[TODO]**
- [ ] **User Interface Testing:** Verify company selection and management interfaces **[TODO]**
- [ ] **Relationship Testing:** Test user-company relationship creation and updates **[TODO]**
- [ ] **Monitoring Control Testing:** Verify pause/resume functionality works **[TODO]**
- [ ] **Integration Testing:** Test company data usage in other system components **[TODO]**

#### Success Criteria **[TODO]**
- [ ] **End-to-End Success:** User can select companies and control monitoring status **[TODO]**
- [ ] **Data Management:** Companies properly stored and manageable by admins **[TODO]**
- [ ] **User Experience:** Intuitive company selection and management process **[TODO]**

### Flow 9: Application Results & Follow-up Management Process (MVP Implementation) **[TODO - MVP PRIORITY]**

#### MVP Implementation: Basic Application Archival
**Application Management:**
1. **Simple Application Archive:**
   - Basic "Archive Application" button on application records
   - Archive status field to hide old applications from active views
   - No employer response tracking (most don't respond anyway)
   - Focus on application volume rather than detailed tracking

**Application Entity (MVP):**
- `id` - Unique identifier
- `user_id` - Reference to user
- `company_id` - Reference to company node
- `job_title` - Position applied for
- `application_date` - When application was submitted
- `status` - Simple status: Submitted, Archived
- `archived` - Boolean field for archival status
- `notes` - Optional user notes field

**Basic Functions:**
- List view of user's applications **[TODO]**
- Simple archive function to clean up old applications **[TODO]**
- Basic search and filter by company or date **[TODO]**

#### Phase 2+ (Future Enhancement): Advanced Results Management **[SHELVED]**
*Shelved for future implementation:* **[SHELVED]**
- Employer response tracking and management **[SHELVED]**
- Interview scheduling and follow-up automation **[SHELVED]**
- Success analysis and pattern recognition **[SHELVED]**
- Performance metrics and optimization insights **[SHELVED]**
- Comprehensive application lifecycle management **[SHELVED]**

### Flow 9 Development Milestones **[TODO - MVP PRIORITY]**

#### Core Development Tasks **[TODO]**
- [ ] **Application Content Type:** Create application content type with all required fields **[TODO]**
- [ ] **Application CRUD:** Test application record creation, viewing, updating **[TODO]**
- [ ] **Status Management:** Implement application status updates (submitted, archived, etc.) **[TODO]**
- [ ] **User Interface:** Create user application history and management interface **[TODO]**
- [ ] **Archive Functionality:** Implement archive/unarchive application feature **[TODO]**
- [ ] **Application Notes:** User can add and edit notes on applications **[TODO]**
- [ ] **Filtering/Search:** Filter applications by status, company, date range **[TODO]**
- [ ] **Application Tracking:** Link applications to jobs and companies **[TODO]**

#### Testing & Integration **[TODO]**
- [ ] **CRUD Testing:** Test all application record operations **[TODO]**
- [ ] **Status Update Testing:** Verify status changes work correctly **[TODO]**
- [ ] **User Interface Testing:** Test application management interface **[TODO]**
- [ ] **Archive Testing:** Verify archive/unarchive functionality **[TODO]**
- [ ] **Search/Filter Testing:** Test all filtering and search capabilities **[TODO]**
- [ ] **Integration Testing:** Test application data with other system components **[TODO]**

#### Success Criteria **[TODO]**
- [ ] **End-to-End Success:** User can track and manage all applications effectively **[TODO]**
- [ ] **Data Organization:** Applications properly categorized and searchable **[TODO]**
- [ ] **User Experience:** Intuitive application management and status tracking **[TODO]**

### Flow 10: AI Model Training & Improvement Process (Shelved) **[SHELVED]**

#### MVP Status: Not Implemented **[SHELVED]**
**Note:** AI model training and continuous improvement is shelved for Phase 2+ implementation. **[SHELVED]**

*This entire flow is noted but not implemented in MVP.* **[SHELVED]**

### Flow 11: Web Scraping Maintenance & Updates Process (MVP Priority) **[TODO - MVP PRIORITY]**
**WEB SCRAPING SOLUTION: Diffbot API Integration**
**API Configuration**: Environment variable storage for Diffbot API authentication

#### **Diffbot Implementation Strategy:**
- **Primary Solution**: Diffbot API for all career page data extraction
- **API Authentication**: Secure environment variable configuration (never hardcoded)
- **Job Data Extraction**: Utilize Diffbot's Job Board API for career portal scraping
- **Content Analysis**: Leverage Diffbot's Natural Language Processing for job description analysis
- **Structured Data**: Receive JSON-formatted job data directly from Diffbot API

#### MVP Implementation: Single Employer Focus (Johnson & Johnson)
**Diffbot Scraping Development Priority:**
1. **Single Employer Implementation with Diffbot:**
   - Configure Diffbot API for Johnson & Johnson career portal (https://www.careers.jnj.com/en/)
   - Use Diffbot's Article API for job posting content extraction
   - Implement Diffbot webhook integration for real-time job updates
   - Store Diffbot API responses in Job Posting content type nodes

**MVP Diffbot Features:**
- Johnson & Johnson job discovery through Diffbot API calls
- Structured job posting data storage in Drupal nodes
- Real-time job updates via Diffbot webhook notifications
- Automated job categorization using Diffbot's content analysis

**Diffbot Development Approach:**
1. **API Configuration**: Set up Diffbot API credentials in environment variables
2. **J&J Integration**: Configure Diffbot crawling for Johnson & Johnson career portal
3. **Data Processing**: Map Diffbot API responses to Drupal Job Posting fields
4. **Webhook Setup**: Implement Diffbot webhook receivers for job updates
5. **Error Handling**: Implement Diffbot API error logging and retry mechanisms

**Security Requirements:**
- **API Key Management**: Store Diffbot API key in secure environment variables only
- **Access Control**: Restrict API key access to authorized system processes only
- **Monitoring**: Log all Diffbot API usage for billing and performance tracking

#### Phase 2+ (Future Enhancement): Multi-Employer Scaling **[SHELVED]**
*Future implementation will address:* **[SHELVED]**
- Automated scraping rule updates **[SHELVED]**
- Multi-employer scaling and management **[SHELVED]**
- Advanced error detection and recovery **[SHELVED]**
- Performance optimization and monitoring **[SHELVED]**

### Flow 11 Development Milestones **[TODO - MVP PRIORITY]**

#### Core Development Tasks **[TODO]**
- [ ] **Scraping Framework:** Build basic web scraping infrastructure (Selenium/Puppeteer) **[TODO]**
- [ ] **Single Employer Selection:** Choose and configure one target employer for MVP **[TODO]**
- [ ] **Job Discovery:** Successfully scrape and parse job postings from target employer **[TODO]**
- [ ] **Content Extraction:** Extract job title, description, requirements, salary, location **[TODO]**
- [ ] **Job Storage:** Save scraped jobs as Job Posting nodes **[TODO]**
- [ ] **Data Validation:** Ensure scraped data quality and completeness **[TODO]**
- [ ] **Error Handling:** Detect and log scraping failures with detailed error info **[TODO]**
- [ ] **Scheduling System:** Implement automated scraping on configurable schedule **[TODO]**

#### Advanced Scraping Features **[TODO]**
- [ ] **Anti-Bot Measures:** Handle rate limiting, CAPTCHA, and bot detection **[TODO]**
- [ ] **Data Normalization:** Standardize job data formats across different sources **[TODO]**
- [ ] **Duplicate Detection:** Identify and handle duplicate job postings **[TODO]**
- [ ] **Job Categorization:** Auto-categorize jobs by type, level, department **[TODO]**
- [ ] **Skills Extraction:** Parse required skills and technologies from job descriptions **[TODO]**

#### Admin & Monitoring **[TODO]**
- [ ] **Admin Interface:** Dashboard for monitoring scraping success rates **[TODO]**
- [ ] **Scraping Configuration:** Admin tools for updating scraping rules **[TODO]**
- [ ] **Performance Monitoring:** Track scraping speed, success rate, data quality **[TODO]**
- [ ] **Error Reporting:** Integration with error queue for scraping failures **[TODO]**
- [ ] **Manual Scraping:** Admin ability to trigger manual scraping runs **[TODO]**

#### Testing & Integration **[TODO]**
- [ ] **Scraping Testing:** Test job discovery and data extraction thoroughly **[TODO]**
- [ ] **Data Quality Testing:** Verify scraped job data accuracy and completeness **[TODO]**
- [ ] **Error Handling Testing:** Test various failure scenarios and recovery **[TODO]**
- [ ] **Performance Testing:** Test scraping under load and various conditions **[TODO]**
- [ ] **Integration Testing:** Test scraped job integration with user matching **[TODO]**

#### Success Criteria **[TODO]**
- [ ] **End-to-End Success:** Complete job discovery, storage, and matching workflow **[TODO]**
- [ ] **Data Quality:** 95%+ accuracy in job data extraction **[TODO]**
- [ ] **Reliability:** 90%+ scraping success rate with proper error handling **[TODO]**
- [ ] **Performance:** Process 100+ jobs per day from target employer **[TODO]**
- [ ] **Foundation Ready:** Framework ready for scaling to additional employers **[TODO]**

### Flow 12: Security & Compliance Management Process (Placeholder) **[SHELVED]**

#### MVP Status: Basic Security Only **[TODO - BASIC ONLY]**
**Current Implementation:** **[TODO]**
- Basic Drupal security practices and user authentication **[TODO]**
- Standard file upload security and validation **[TODO]**
- Simple user data protection using Drupal's built-in security **[TODO]**

**Future Enhancement Placeholder:** **[SHELVED]**
- Advanced credential encryption and management **[SHELVED]**
- GDPR compliance and data protection **[SHELVED]**
- Security incident response procedures **[SHELVED]**
- Comprehensive audit trails and monitoring **[SHELVED]**

*This flow is placeholder for future implementation when scaling beyond personal use.* **[SHELVED]**

### Flow 13: Success Analytics & Reporting (Placeholder) **[SHELVED]**

#### MVP Status: Not Implemented **[SHELVED]**
**Note:** Success analytics and reporting is placeholder for future implementation. **[SHELVED]**

*This flow will be developed in Phase 2+ once core functionality is stable.* **[SHELVED]**

### Flow 14: Market Intelligence & Competitive Analysis (Placeholder) **[SHELVED]**

#### MVP Status: Not Implemented **[SHELVED]**
**Note:** Market intelligence is not an MVP priority and is placeholder for future phases. **[SHELVED]**

*This flow is noted but not included in MVP scope.* **[SHELVED]**

### Flow 15: Third-Party Integration Management (Placeholder) **[SHELVED]**

#### MVP Status: Not Implemented **[SHELVED]**
**Note:** Third-party integrations are placeholder for future implementation phases. **[SHELVED]**

*This flow is noted but not included in MVP scope.* **[SHELVED]**

### Flow 16: Individual Company Job Search Process **[TODO - MVP PRIORITY]**
**TARGET IMPLEMENTATION: Johnson & Johnson (J&J)**
**Company Career Portal**: https://www.careers.jnj.com/en/

#### Process Overview
This flow enables users to perform targeted job searches specifically for Johnson & Johnson opportunities through the Drupal-native interface, utilizing Company and Job Posting content type nodes with direct integration to J&J's career portal data.

#### **Johnson & Johnson Implementation Specifications:**
- **Company Node**: Create J&J company node with career portal URL field
- **Diffbot Integration**: Configure Diffbot API for https://www.careers.jnj.com/en/ data extraction
- **Job Posting Nodes**: Store J&J opportunities as individual content nodes via Diffbot API responses
- **Real-time Updates**: Implement Diffbot webhooks for automatic J&J job updates
- **Structured Data**: Utilize Diffbot's parsed JSON format for consistent job data structure

#### **Diffbot API Configuration for J&J:**
- **API Endpoint**: Diffbot Article API for J&J job posting extraction
- **Webhook Setup**: Real-time notifications for new J&J job postings
- **Data Mapping**: Diffbot JSON response fields → Drupal Job Posting node fields
- **Update Frequency**: Automated daily crawls with webhook-triggered immediate updates
- **Security**: Environment variable storage for Diffbot API authentication

#### **Drupal-Native Implementation Requirements:**
- **Company Data**: Use Company content type node for Johnson & Johnson profile
- **Job Storage**: Use Job Posting content type nodes for J&J opportunities
- **Search Interface**: Use Views module with J&J-specific exposed filters
- **User Interaction**: Standard Drupal node view pages for J&J job details

#### Step 1: Johnson & Johnson Company Selection & Search Initiation
**User Actions:**
1. Navigate to Johnson & Johnson company node page (`/node/[j&j-company-id]`)
2. Access J&J-specific job search through company node view
3. Configure search parameters using J&J career categories (Healthcare, Pharmaceutical, Medical Devices, Consumer Products)
4. Apply location filters specific to J&J global offices

**System Actions:**
- Display Johnson & Johnson company node with career portal integration
- Generate filtered View of J&J job posting nodes from career portal data
- Apply J&J-specific search filters (business units, experience levels, locations)
- Log J&J job search activity for user preference learning

**Johnson & Johnson Data Structure:**
```
J&J Company Node → J&J Job Posting Nodes → Filtered J&J Jobs View → Individual J&J Job Pages
```

**J&J-Specific Field Configuration:**
- **Company Node Fields**: 
  - `field_careers_url`: https://www.careers.jnj.com/en/
  - `field_company_sectors`: Healthcare, Pharmaceutical, Medical Devices, Consumer
  - `field_global_locations`: New Brunswick NJ, Europe, Asia-Pacific, Americas
- **Job Posting Node Fields**:
  - `field_jnj_business_unit`: Janssen, J&J MedTech, J&J Innovative Medicine, Kenvue
  - `field_jnj_job_category`: R&D, Manufacturing, Sales, Marketing, Operations, IT
  - `field_jnj_career_level`: Entry, Experienced, Manager, Director, Executive

#### Step 2: Johnson & Johnson Job Discovery & Results Processing
**User Actions:**
1. Review J&J job posting nodes returned by filtered View
2. Use J&J-specific search filters (business unit, therapeutic area, location)
3. Access individual J&J job posting detail pages (`/node/[jnj-job-id]`)
4. Compare J&J opportunities across different business divisions
5. Access original J&J career portal links for additional job details

**System Actions:**
- Execute View queries against J&J Job Posting content type nodes
- Apply field-based filtering for J&J-specific job requirements and locations
- Display J&J results using standard Drupal theming with J&J branding elements
- Track user J&J job search behavior through Drupal's analytics hooks
- Maintain synchronization with https://www.careers.jnj.com/en/ job data

**J&J-Specific Data Processing:**
- **Job Categorization**: Filter by J&J business units (Janssen Pharmaceuticals, J&J MedTech, etc.)
- **Therapeutic Areas**: Filter by healthcare specializations (Oncology, Immunology, Neuroscience)
- **Career Levels**: Match user experience to J&J career progression paths
- **Location Matching**: Cross-reference user location preferences with J&J global offices

#### Step 3: J&J Search Results Management & Career Portal Integration
**User Actions:**
1. Review J&J search results using standard Drupal pagination and sorting
2. Access detailed J&J job information through node view pages
3. Use native Drupal bookmarking for J&J job interest tracking
4. Navigate to original J&J career portal for official application submission
5. Save J&J application preferences for future search refinement

**System Actions:**
- Maintain J&J search state through Drupal's session management
- Update user J&J search history using node access logging
- Track J&J job interaction metrics through standard Drupal hooks
- Prepare J&J-specific application data for submission process integration

**Johnson & Johnson Integration Points:**
- **Career Portal Sync**: Regular synchronization with https://www.careers.jnj.com/en/
- **Job Data Accuracy**: Maintain current J&J job posting information
- **Application Referral**: Direct users to official J&J application process
- **Preference Learning**: Track user J&J job preferences for improved matching

**Technical Specifications:**
- **Views Configuration**: J&J-specific exposed filters for business unit, location, career level
- **Content Relationships**: Entity reference fields linking J&J Job Posting → J&J Company node
- **Performance**: Implement Views caching for frequently accessed J&J job searches
- **User Experience**: J&J-branded theming elements within standard Drupal interface
- **Diffbot Integration**: Diffbot API configuration for automated https://www.careers.jnj.com/en/ data extraction
- **Real-time Updates**: Diffbot webhook processing for immediate job posting updates
- **API Security**: Environment variable storage for Diffbot API key (never hardcoded)

### Flow 17: Individual Company Job Application Process **[TODO - MVP PRIORITY]**
**TARGET IMPLEMENTATION: Johnson & Johnson (J&J) Applications**
**Integration Point**: https://www.careers.jnj.com/en/ application system

#### Process Overview  
This flow manages the complete job application submission process for Johnson & Johnson opportunities using Drupal's native content creation and management system, with integration points to J&J's official application portal.

#### **Johnson & Johnson Application Integration:**
- **J&J Application Preparation**: Use Application content type nodes for J&J-specific applications
- **Career Portal Integration**: Maintain references to official J&J application URLs
- **J&J-Specific Data**: Capture J&J business unit, therapeutic area, and position-specific requirements
- **Application Tracking**: Monitor J&J application status through both internal and external systems

#### **Drupal-Native Implementation Requirements:**
- **Application Storage**: Use Application content type nodes (`/node/add/application`)
- **Form Processing**: Use default Drupal node creation forms
- **Status Tracking**: Use field values and node status for application management
- **File Management**: Use Drupal's managed file system for resume/cover letter attachments

#### Step 1: Application Initiation
**User Actions:**
1. From Job Posting node page, click "Apply" link
2. Navigate to Application node creation form (`/node/add/application`)
3. Review pre-populated application data from user profile fields
4. Verify job posting and company information auto-referenced in form

**System Actions:**
- Pre-populate Application node form with user entity field data
- Auto-reference selected Job Posting node and related Company node
- Validate user profile completeness for application readiness
- Initialize application status as "Draft" using field default values

**Drupal Implementation:**
- **Form Pre-population**: Use form_alter hooks to populate fields from user entity
- **Entity References**: Auto-populate Company and Job Posting references
- **Validation**: Use field validation rules for required application data
- **Draft Saving**: Use Drupal's node save functionality for draft applications

#### Step 2: Application Data Completion
**User Actions:**
1. Complete application form fields using standard Drupal form interface
2. Upload tailored resume using managed file field
3. Upload customized cover letter using managed file field
4. Add application-specific notes in text field
5. Review application completeness before submission

**System Actions:**
- Validate all required fields using Drupal's form validation system
- Process file uploads through Drupal's managed file system
- Store application data in Application content type node
- Calculate application completeness score using field validation

**Data Validation (Drupal-Native):**
- **Required Fields**: Use field configuration to enforce required application data
- **File Validation**: Use managed file field validation for resume/cover letter formats
- **Reference Integrity**: Validate entity references to User, Company, and Job Posting nodes
- **Status Management**: Use field options to control application workflow states

#### Step 3: Application Submission & Tracking
**User Actions:**
1. Submit completed application using standard Drupal node save
2. Receive confirmation through Drupal's message system
3. Access application tracking through user's content View
4. Monitor application status updates through node field changes

**System Actions:**
- Save Application node with "Submitted" status
- Generate application tracking reference number using node ID
- Log application submission event through Drupal's logging system
- Trigger notification workflows using standard Drupal hooks

**Application Tracking (Drupal-Native):**
```
User → Application Nodes (View) → Status Field Updates → Email Notifications (Rules/Custom Module)
```

#### Step 4: Post-Submission Management
**User Actions:**
1. View application status through user's Application content View
2. Edit application notes using standard node edit form (`/node/[app-id]/edit`)
3. Upload additional documents using file field updates
4. Track communication history through application node updates

**System Actions:**
- Maintain application status history through node revision system
- Update application timestamps using Drupal's built-in date tracking
- Process status changes through field updates and workflow state management
- Archive completed applications using node publishing status

**Administrative Oversight:**
- **Admin Interface**: Use Views module for application management (`/admin/content/applications`)
- **Status Updates**: Use bulk operations for application status management
- **Reporting**: Use Views and reporting modules for application analytics
- **Data Export**: Use standard Drupal content export functionality

**Technical Implementation Notes:**
- **Form Customization**: Use form_alter hooks for application-specific form modifications
- **Workflow Integration**: Use core workflow modules for application status management
- **Email Integration**: Use mail system hooks for application notifications
- **Performance**: Implement caching strategies for frequently accessed application data

## Core Entities & Data Structure

### 1. User Profile Entity
**Purpose:** Extended user profile with comprehensive job application data

**Core Profile Fields:**
- `id` - Unique identifier
- `user_id` - Reference to Drupal user
- `comprehensive_resume_file` - Master resume file (required for system function)
- `resume_completeness_score` - AI-calculated completeness score (0-100)
- `skills_analysis` - JSON field with AI-analyzed skills and proficiency levels
- `experience_summary` - AI-generated experience summary
- `profile_complete` - Boolean flag indicating profile readiness
- `keywords_interested` - User-defined job keywords/types
- `notification_preferences` - Email/alert preferences

**Personal Information Fields:**
- `first_name` - Legal first name
- `middle_name` - Middle name or initial
- `last_name` - Legal last name
- `preferred_name` - Name used professionally
- `date_of_birth` - Date of birth (for age verification when required)
- `phone_primary` - Primary phone number
- `phone_secondary` - Secondary/mobile phone number
- `email_primary` - Primary email address
- `email_secondary` - Secondary email address
- `address_street` - Current street address
- `address_city` - Current city
- `address_state` - Current state/province
- `address_zip` - Current postal code
- `address_country` - Current country
- `linkedin_profile` - LinkedIn profile URL
- `portfolio_website` - Professional portfolio/website URL

**Work Authorization & Legal Fields:**
- `work_authorization_us` - Can legally work in US (boolean)
- `work_authorization_type` - Citizen, permanent resident, visa holder, etc.
- `visa_type` - Type of visa if applicable (H1B, L1, OPT, etc.)
- `visa_expiration` - Visa expiration date
- `security_clearance_level` - Security clearance level if applicable
- `security_clearance_expiration` - Clearance expiration date
- `professional_licenses` - JSON array of licenses and certifications
- `background_check_consent` - Consent to background checks (boolean)
- `drug_test_consent` - Consent to drug testing (boolean)
- `nda_acceptance` - General NDA acceptance (boolean)

**EEO/Diversity Information Fields (Optional):**
- `gender_identity` - Gender identity (optional, for diversity reporting)
- `sexual_orientation` - Sexual orientation (optional, for diversity reporting)
- `race_ethnicity` - Race/ethnicity (optional, for EEO reporting)
- `disability_status` - Disability status (optional, for ADA compliance)
- `veteran_status` - Military veteran status (optional, for veteran preference)
- `eeo_data_consent` - Consent to use EEO data for reporting (boolean)

**Compensation & Benefits Fields:**
- `current_salary` - Current salary (where legally collectible)
- `salary_expectation_min` - Minimum acceptable salary
- `salary_expectation_max` - Maximum salary expectation
- `compensation_type_preference` - Salary, hourly, commission, contract, etc.
- `benefits_requirements` - JSON array of required benefits
- `relocation_assistance_needed` - Requires relocation assistance (boolean)
- `relocation_willingness` - Willing to relocate (boolean)
- `relocation_preferred_locations` - JSON array of preferred relocation cities

**Availability & Schedule Fields:**
- `start_date_available` - Earliest available start date
- `employment_type_preference` - Full-time, part-time, contract, etc.
- `schedule_preferences` - JSON array of acceptable schedules
- `remote_work_preference` - In-office, hybrid, fully remote
- `travel_willingness_percentage` - Acceptable travel percentage (0-100)
- `overtime_availability` - Willing to work overtime (boolean)
- `weekend_availability` - Available for weekend work (boolean)
- `holiday_availability` - Available for holiday work (boolean)
- `shift_preferences` - JSON array of acceptable shift times

**References & Professional Network Fields:**
- `references_data` - JSON array of professional references
- `reference_check_consent` - Consent to contact references (boolean)
- `current_employer_contact_consent` - Can contact current employer (boolean)
- `professional_network_data` - JSON field with networking connections
- `social_media_profiles` - JSON array of professional social media
- `work_samples_files` - JSON array of portfolio file references

**Education & Training Fields:**
- `education_history` - JSON array of educational background
- `transcript_available` - Official transcripts available (boolean)
- `gpa_overall` - Overall GPA (when required)
- `continuing_education` - JSON array of professional development
- `certifications_data` - JSON array of professional certifications
- `language_proficiencies` - JSON array of languages and fluency levels
- `training_records` - JSON array of completed training programs

**Employment History Details:**
- `employment_history_data` - JSON array with detailed work history
- `employment_gaps_explained` - Boolean flag if gaps are documented
- `salary_history_data` - JSON array of salary history (where legal)
- `reason_for_leaving_data` - JSON array of departure reasons
- `supervisor_references` - JSON array of supervisor contact info
- `non_compete_agreements` - JSON array of current restrictions

**Industry-Specific Fields:**
- `driving_record_clean` - Clean driving record (boolean)
- `driving_license_type` - Type of driver's license
- `credit_check_consent` - Consent to credit check (boolean)
- `social_media_screening_consent` - Consent to social media review (boolean)
- `physical_requirements_met` - Can meet physical job requirements (boolean)
- `criminal_background_disclosed` - Any criminal history disclosed (boolean)
- `professional_memberships` - JSON array of industry associations

**System Fields:**
- `profile_completeness_percentage` - Overall profile completion (0-100)
- `data_verification_status` - Status of information verification
- `privacy_settings` - JSON field with privacy preferences
- `created` - Profile creation timestamp
- `updated` - Last profile update
- `last_verification_check` - Last time data was verified

### 2. Employer Entity
**Purpose:** Manage employer information and job scraping configuration

**Key Fields:**
- `id` - Unique identifier
- `name` - Company/organization name
- `website` - Primary company website
- `careers_url` - Career page URL for scraping
- `scraping_enabled` - Boolean flag for active scraping
- `scraping_frequency` - How often to scrape (daily, weekly, etc.)
- `job_keywords` - Keywords this employer typically uses
- `application_method` - How applications are submitted (form, email, ATS)
- `ats_type` - Type of ATS system used (if applicable)
- `scraping_config` - JSON configuration for scraping rules
- `last_scraped` - Last successful scrape timestamp
- `active` - Employer active status
- `created` - Entity creation timestamp
- `updated` - Last modified timestamp

### 3. User Employer Credentials Entity
**Purpose:** Store user's login credentials for each employer

**Key Fields:**
- `id` - Unique identifier
- `user_id` - Reference to user
- `employer_id` - Reference to employer
- `username` - Login username/email (encrypted)
- `password` - Login password (encrypted)
- `additional_fields` - JSON field for extra form fields (encrypted)
- `credential_status` - Status (active, expired, needs_verification)
- `last_validated` - Last successful login timestamp
- `notes` - User notes about credentials
- `created` - Credential creation timestamp
- `updated` - Last credential update

### 4. Job Posting Entity
**Purpose:** Store scraped job postings from employer websites

**Key Fields:**
- `id` - Unique identifier
- `employer_id` - Reference to employer
- `external_job_id` - Employer's internal job ID
- `title` - Job title
- `description` - Full job description
- `requirements` - Job requirements/qualifications
- `location` - Job location
- `employment_type` - Full-time, part-time, contract, etc.
- `salary_range` - Posted salary information
- `posting_date` - When job was originally posted
- `expiration_date` - Application deadline
- `application_url` - Direct application URL
- `keywords_matched` - User keywords that match this job
- `ai_analysis` - GenAI analysis of job requirements
- `status` - Job status (active, expired, filled, removed)
- `scraped_date` - When we discovered this job
- `updated` - Last update timestamp

### 5. Job Application Entity
**Purpose:** Track individual job applications and their status

**Key Fields:**
- `id` - Unique identifier
- `user_id` - Reference to user
- `job_posting_id` - Reference to job posting
- `tailored_resume_file` - AI-customized resume for this application
- `cover_letter_file` - Generated cover letter (if applicable)
- `application_status` - Current status (pending, submitted, failed, manual_required)
- `submission_method` - How it was submitted (automated, manual)
- `submission_date` - When application was submitted
- `automation_success` - Boolean flag for automation success
- `error_details` - JSON field with error information for failed submissions
- `manual_completion_required` - Flag indicating user needs to finish manually
- `admin_review_required` - Flag for admin queue processing
- `notes` - User or system notes
- `created` - Application creation timestamp
- `updated` - Last status update

### 6. Workflow Error Queue Entity
**Purpose:** Manage failed automation workflows for admin review

**Key Fields:**
- `id` - Unique identifier
- `application_id` - Reference to failed application
- `error_type` - Type of failure (authentication, form_submission, site_change, etc.)
- `error_message` - Detailed error description
- `error_data` - JSON field with debugging information
- `user_notified` - Boolean flag if user has been notified
- `priority` - Error priority level (low, medium, high, critical)
- `assigned_admin` - Admin user assigned to fix
- `status` - Queue status (new, in_progress, resolved, wont_fix)
- `resolution_notes` - Admin notes on how issue was resolved
- `automation_updated` - Boolean flag if automation was improved
- `created` - Error occurrence timestamp
- `resolved` - Resolution timestamp
## AI-Powered Services Architecture

### 1. GenAI Resume Analysis Service
**Responsibilities:**
- Analyze uploaded resumes for skills, experience, and expertise levels
- Generate completeness scores and improvement recommendations
- Extract structured data from unstructured resume content
- Identify skill gaps and experience matching for job requirements
- Provide recommendations for resume enhancement

**AI Integration Points:**
- Natural Language Processing for text analysis
- Skills taxonomy matching and categorization
- Experience level assessment algorithms
- Industry-specific knowledge extraction

### 2. GenAI Resume Tailoring Service
**Responsibilities:**
- Customize resumes based on specific job descriptions
- Optimize keyword density and relevance scoring
- Maintain user's authentic experience while emphasizing relevant skills
- Generate job-specific cover letters when required
- Ensure ATS (Applicant Tracking System) compatibility

**AI Integration Points:**
- Job description analysis and requirement extraction
- Resume content optimization and restructuring
- Cover letter generation with personalization
- ATS keyword optimization algorithms

### 3. Job Scraping & Analysis Service
**PRIMARY SOLUTION: Diffbot API Integration**
**Responsibilities:**
- Automated career page data extraction via Diffbot API
- Job posting monitoring and change detection through Diffbot webhooks
- Keyword matching against user preferences using Diffbot's NLP capabilities
- Job description analysis and categorization via Diffbot's content understanding
- Integration with multiple employer career portals through unified Diffbot interface

**Diffbot Technical Components:**
- **Diffbot API Integration**: RESTful API calls for job data extraction
- **Webhook Processing**: Real-time job updates via Diffbot webhook notifications
- **Content Analysis**: Diffbot's Natural Language Processing for job categorization
- **Rate Limiting**: Built-in Diffbot API rate management and respectful scraping
- **Data Normalization**: Consistent job posting structure across all employers

**Diffbot Implementation Architecture:**
```
Employer Career Portals → Diffbot API → Structured JSON Data → Drupal Job Posting Nodes
```

**Security & Configuration:**
- **Environment Variables**: Diffbot API key stored securely, never in code
- **Error Handling**: Comprehensive Diffbot API error logging and retry mechanisms
- **Monitoring**: Track API usage, billing, and performance metrics

### 4. Automated Application Submission Service
**Responsibilities:**
- Automated form filling and submission
- Multi-step application process handling
- File upload automation (resumes, cover letters)
- Authentication and session management
- Error detection and recovery attempts

**Form Field Auto-Population Mapping:**
- **Personal Information:** Name, contact details, address information
- **Work Authorization:** Citizenship status, visa information, work eligibility
- **Employment History:** Previous positions, dates, responsibilities, supervisors
- **Education Background:** Degrees, institutions, graduation dates, GPA (when required)
- **Compensation:** Salary expectations, current compensation (where legal)
- **Availability:** Start date, employment type preferences, schedule availability
- **EEO Information:** Demographics data (when user consents to sharing)
- **References:** Professional references with contact information
- **Certifications:** Licenses, professional certifications, expiration dates
- **Additional Requirements:** Industry-specific fields, background check consents

**Technical Components:**
- Browser automation (Selenium, Puppeteer)
- Intelligent form field recognition and mapping
- Multi-page application flow navigation
- Dynamic field requirement detection
- CAPTCHA detection and handling
- File upload automation with format conversion
- Session persistence and credential management
- Screenshot capture for debugging failed submissions
- Error detection and partial completion recovery

### 5. Employer Credential Management Service
**Responsibilities:**
- Secure storage and encryption of user credentials
- Credential validation and testing
- Multi-factor authentication handling
- Password change detection and notifications
- Credential sharing security protocols

**Security Features:**
- End-to-end encryption for credential storage
- Regular credential validation testing
- Secure key management and rotation
- Audit logging for credential access
- Compliance with data protection regulations

### 6. Error Handling & Queue Management Service
**Responsibilities:**
- Failed submission detection and categorization
- Error queue management and prioritization
- Admin notification and assignment systems
- User notification of manual completion requirements
- Automation improvement tracking and implementation

**Error Categories:**
- Authentication failures (expired credentials, 2FA)
- Form structure changes (site updates, new fields)
- CAPTCHA or anti-bot challenges
- Network connectivity or timeout issues
- File upload restrictions or format requirements

## User Interface Components

### 1. User Dashboard
**Features:**
- Profile completeness indicator with AI recommendations
- List of interested employers and available positions
- Application status overview with real-time updates
- Submit buttons for AI-recommended job matches
- Recent activity feed and notifications
- Resume analysis results and improvement suggestions

**Dashboard Sections:**
- **Profile Status:** Resume completeness, skills analysis, recommendations
- **Job Matches:** AI-recommended positions with match scores
- **Active Applications:** Current application status and next steps
- **Employer Management:** List of configured employers and credential status
- **Queue Notifications:** Manual completion required alerts

### 2. Resume Management Interface
**Features:**
- Master resume upload and analysis
- AI-generated skills assessment and recommendations
- Resume completeness scoring with improvement tips
- Version history of tailored resumes
- Preview of AI-customized resumes for specific jobs

**Critical User Notifications:**
- "Comprehensive Resume Required" - System cannot function without complete resume
- Resume completeness score with specific improvement areas
- Skills gap analysis for target job types
- Regular prompts to update resume for better AI analysis

### 3. Employer & Credential Management
**Features:**
- Add/edit employer information and scraping configuration
- Secure credential entry and testing
- Credential status monitoring and validation alerts
- Job keyword preferences for each employer
- Application history and success rates per employer

**Security Considerations:**
- Clear warnings about credential storage and security
- Options for credential validation testing
- Notifications for expired or failing credentials
- Secure credential update and change workflows

### 4. Job Matching & Application Interface
**Features:**
- AI-recommended job matches with relevance scores
- Job description analysis and requirement matching
- One-click application submission with AI-tailored resume
- Preview of tailored resume before submission
- Manual application override options

**Application Workflow:**
- Display job match score and reasoning
- Show preview of tailored resume
- One-click "Apply Now" with automation
- Real-time submission status updates
- Error handling with manual completion options

### 5. Admin Error Queue Management
**Features:**
- Failed workflow queue with categorization
- Error details and debugging information
- Admin assignment and priority management
- Resolution tracking and automation improvement notes
- User notification management for manual completion

**Admin Workflow:**
- Error categorization and priority assignment
- Debugging information review and analysis
- Resolution documentation and automation updates
- User communication templates and notifications
- Success metrics and automation improvement tracking

## Workflow States & Automation

### Application Status Workflow:
1. **Job Discovered** - New job found matching user keywords
2. **AI Analysis Complete** - Job requirements analyzed by GenAI
3. **Resume Tailoring** - AI customizes resume for specific job
4. **Ready for Submission** - User can review and submit application
5. **Submission in Progress** - Automated submission attempting
6. **Submitted Successfully** - Application submitted automatically
7. **Submission Failed** - Automation failed, queued for admin review
8. **Manual Completion Required** - User must complete application manually
9. **Under Review** - Application being reviewed by employer
10. **Interview Process** - Various interview stages
11. **Decision Received** - Final hiring decision received

### Automation Trigger Events:
- **New job discovery:** Trigger AI analysis and resume tailoring
- **Credential validation:** Verify user credentials before submission attempts
- **Submission failure:** Queue error for admin review and user notification
- **Site structure changes:** Detect and adapt to employer website updates
- **User preference updates:** Re-analyze job matches based on new criteria

## Security & Privacy Considerations

### Data Protection:
- **Credential Encryption:** All user credentials encrypted at rest and in transit
- **PII Handling:** Personal information secured with industry-standard encryption
- **Access Controls:** Role-based access to sensitive user data
- **Audit Logging:** Complete audit trail for all credential access and usage
- **GDPR Compliance:** Right to deletion, data portability, and access controls

### Application Security:
- **Input Validation:** Comprehensive validation of all user inputs
- **SQL Injection Prevention:** Parameterized queries and input sanitization
- **XSS Protection:** Output encoding and content security policies
- **CSRF Protection:** Token-based request validation
- **Rate Limiting:** Protection against abuse and bot attacks

### AI/GenAI Security:
- **Prompt Injection Prevention:** Input sanitization for AI service calls
- **Data Privacy:** Ensure AI services don't retain sensitive user data
- **Model Bias Mitigation:** Regular testing for biased resume tailoring
- **Service Isolation:** AI services isolated from core application data
- **Fallback Systems:** Manual processes when AI services unavailable

## Performance & Scalability

### Optimization Strategies:
- **Background Processing:** Queue system for time-intensive AI operations
- **Caching:** Cache AI analysis results and tailored resumes
- **Rate Limiting:** Respectful scraping and API usage limits
- **Database Optimization:** Proper indexing for large job posting datasets
- **CDN Integration:** Fast delivery of generated documents

### Monitoring & Alerting:
- **Application Performance:** Response time monitoring and alerting
- **AI Service Health:** Monitor AI service availability and response times
- **Scraping Success Rates:** Track job discovery and scraping effectiveness
- **Submission Success Rates:** Monitor automation effectiveness per employer
- **Error Queue Metrics:** Admin workload monitoring and optimization

## Integration Architecture

### External AI Services:
- **OpenAI GPT Models:** Resume analysis and tailoring
- **Natural Language APIs:** Skills extraction and job matching
- **Document Generation:** Automated cover letter creation
- **Content Analysis:** Job description parsing and requirement extraction

### Third-Party Integrations:
- **Job Board APIs:** Indeed, LinkedIn, Glassdoor integration where available
- **ATS System APIs:** Direct integration with major ATS platforms
- **Email Services:** Notification and communication systems
- **File Storage:** Cloud storage for resumes and generated documents
- **Monitoring Services:** Error tracking and performance monitoring

### Internal Drupal Integration:
- **User System:** Leverages Drupal user accounts and authentication
- **File Management:** Drupal file system for document storage
- **Queue System:** Drupal queue API for background processing
- **Cache System:** Drupal cache API for performance optimization
- **Security:** Drupal permissions, roles, and security frameworks

## Development Phases

### Phase 1: Core Infrastructure (Current)
- ✅ Module framework and basic routing
- 🔄 Entity definitions and database schema
- 📋 User profile and resume management
- 📋 Employer management and credential storage
- 📋 Basic AI integration framework

### Phase 2: AI Integration & Job Discovery
- 📋 GenAI resume analysis implementation
- 📋 Job scraping system development
- 📋 AI resume tailoring service
- 📋 Job matching algorithms
- 📋 Basic automation workflows

### Phase 3: Advanced Automation & Error Handling
- 📋 Automated application submission
- 📋 Error detection and queue management
- 📋 Admin interface for error resolution
- 📋 User dashboard with real-time updates
- 📋 Comprehensive testing and optimization

### Phase 4: Production & Enhancement
- 📋 Performance optimization and scaling
- 📋 Advanced AI features and improvements
- 📋 Integration marketplace and API development
- 📋 Mobile interface development
- 📋 Analytics and success tracking

## Technical Requirements

### Core Dependencies:
- **Drupal 11** - Core framework
- **PHP 8.3+** - Runtime environment
- **MySQL 8.0+** - Primary database
- **Redis** - Caching and queue management
- **Elasticsearch** - Job search and matching (optional)

### AI/GenAI Services:
- **OpenAI API** - GPT models for text analysis and generation
- **Custom ML Models** - Skills extraction and matching algorithms
- **NLP Libraries** - Natural language processing capabilities
- **Document Processing** - PDF/Word resume parsing libraries

### Automation Tools:
- **Selenium/Puppeteer** - Browser automation for form submission
- **Scrapy Framework** - Web scraping and data extraction
- **Queue Systems** - Background processing for time-intensive operations
- **Monitoring Tools** - Error tracking and performance monitoring

### Security Tools:
- **Encryption Libraries** - Credential and PII protection
- **OAuth/JWT** - Secure authentication for integrations
- **Rate Limiting** - API abuse prevention
- **Audit Logging** - Compliance and security monitoring

This architecture provides a comprehensive foundation for building an AI-powered job application automation system that respects user privacy, maintains high security standards, and delivers reliable automated application submission across multiple employer platforms.