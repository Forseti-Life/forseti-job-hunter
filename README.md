# Job Application Automation Module

**Last Updated:** February 18, 2026

## 📚 Documentation

This module has comprehensive documentation organized for different audiences:

- **[ARCHITECTURE.md](ARCHITECTURE.md)** - Complete architecture design and development guidelines (developers, architects)
- **[INSTALL.md](INSTALL.md)** - Installation and setup guide (administrators, developers)
- **[docs/](docs/)** - Organized documentation library:
  - [docs/README.md](docs/README.md) - Documentation index and navigation
  - [docs/FAQ.md](docs/FAQ.md) - Frequently asked questions
  - [docs/PROCESS_FLOW.md](docs/PROCESS_FLOW.md) - Detailed workflow diagrams
  - [docs/SUBMISSION_PROCESS.md](docs/SUBMISSION_PROCESS.md) - Application submission guide
  - And more specialized documentation files

**Quick Start:** New developers should read ARCHITECTURE.md first, then INSTALL.md, then explore the docs/ directory.

## Overview
A comprehensive AI-powered Drupal module that automates the entire job application process using Generative AI. This system analyzes user resumes, scrapes job postings from employer websites, tailors applications using AI, and automatically submits applications across multiple employer platforms.

## Maintenance Notes
- 2026-04-13: Realigned the contact tracker with the current feature brief by adding `name`, `title`, and `company_id` compatibility to `jobhunter_contacts`, switching the UI to company-backed contacts, and matching saved-job contact surfacing on `company_id` before legacy-name fallback.
- 2026-03-02: Standardized GenAI fallback decisions into `GenAiFallbackService` for reusable process-flow gating (deterministic checks first, shared JSON contract, shared parsing). Initial production use is in Step 2 application-location verification.
- 2026-02-19: Auto-generate cover letter templates on profile load when missing, removing the manual generate button.
- 2026-02-19: Unified profile form resume parsing with ResumeGenAiParsingWorker chunked parsing to ensure a single shared GenAI flow and avoid token limits.
- 2026-02-19: Aligned error queue admin routes with the standard admin permission and added paging plus safe message previews to the error list.
- 2026-02-19: Split resume parsing into core and professional experience passes with adaptive chunk splitting to avoid token limits.
- 2026-02-17: Refactored `JobDiscoveryService` end-to-end for schema safety and consistency: centralized logger access, added shared company-field resolver (`name` vs `company_name`), and applied that resolver to saved-job list filtering/sorting and company dropdown queries.
- 2026-02-17: Unified search auto-imports external API results into `jobhunter_job_requirements`, but now skips duplicates using identifier checks (`job_hash`, then `external_source + external_job_id`, then `external_source + job_url`).
- 2026-02-17: Prevented duplicate "Forseti Jobs (Pending)" cards by deduplicating staging rows in `SearchAggregatorService::searchForsetiDatabase()` and by only caching true external API sources (`Google Jobs`, `Adzuna`, `USAJobs`, `Google Jobs (SerpAPI)`) in `storeSearchResults()`.
- 2026-02-17: Fixed `job-search-results.html.twig` to avoid direct Twig `csrf_token()` calls; token is now generated in `JobApplicationController::jobDiscoverySearchResults()` and passed as `save_job_csrf_token` to prevent Twig syntax errors while preserving AJAX save security.
- 2026-02-13: Controllers must not redeclare typed properties that exist on ControllerBase (e.g., entityTypeManager) to avoid PHP fatal errors in Drupal 11.
- 2026-02-17: Added per-job "Have applied" tracking on `/jobhunter/my-jobs` with toggle + applied date persistence (`jobhunter_job_requirements.applied_on_date`).
- 2026-02-17: Added schema-safe truncation for imported external metadata (`external_job_id`, URLs, `via`, and related varchar fields) in both immediate and cron import paths to prevent DB length errors.
- 2026-02-17: Job Search Results "Save Job" now uses AJAX and returns JSON on XHR requests so saves do not navigate away from `/jobhunter/job-discovery/search`.
- 2026-02-17: "Save Job" now supports fallback lookup by `external_job_id` in `jobhunter_job_search_results.job_data_json` when legacy/base64 payload decoding fails, preserving searched job data recovery.
- 2026-02-17: `My Jobs` now uses `jobhunter_saved_jobs` (uid↔job_id mapping) so the Forseti jobs catalog (`jobhunter_job_requirements`) stays global while saved jobs remain user-specific.

## ⚠️ CRITICAL: Read Architecture First
**Before any development work begins, all developers MUST read and understand the complete [ARCHITECTURE.md](ARCHITECTURE.md) document.** This system involves complex AI integration, automated web scraping, credential management, and multi-platform submission automation that requires thorough understanding of the architecture before implementation.

## 🛡️ IMPORTANT: Data Preservation Policy

**This module is designed to NEVER delete user content or fields during uninstallation.**

All job applications, company data, user profiles, resume files, and custom content are preserved to prevent accidental data loss.

### Protected During Uninstall
- **Content Types**: company, job_posting, application, issue, tailored_resume (and all their content)
- **User Fields**: field_resume_file, field_primary_resume_text, field_profile_completeness, etc.
- **Profile Fields**: field_profile_completeness, field_resume_file (job_seeker profile)
- **Views**: Job Applications Dashboard, Company Management, Job Discovery
- **All User Data**: Resume uploads, extracted text, profile information, application history

### What Gets Removed
Only module configuration settings (`job_hunter.settings`) are removed during uninstall.

### Manual Cleanup (Optional)
To remove content types/fields after uninstall: Structure > Content types > Delete or Configuration > Account settings > Manage fields > Delete

## Configuration

### Initial Setup

1. **Set Original Resume Node** - Navigate to `/jobhunter/settings` (Admin > Job Hunter > Settings)
2. **Select Resume Node** - Use autocomplete to select your master resume node
3. **Enable Automatic Tailoring** - Check the box to enable automatic resume generation when job postings are created
4. **Configure AI Settings** (optional) - Shared AI provider defaults are configured centrally, with DeepSeek now used for jobhunter parsing and tailoring
5. **Configure External Job APIs** - Add API credentials for job search aggregators:
   - **SerpAPI** - Google Jobs scraper (100 free searches/month) - [Get API key](https://serpapi.com/users/sign_up)
   - **Google Cloud Talent Solution** - Add service account JSON key
   - **Adzuna API** - Job search API (App ID + Key required)
   - **USAJobs API** - US Government jobs (API Key + Email required)

The module now uses the shared `ai_conversation` DeepSeek backend by default for parsing and tailoring flows.

### Original Resume Selection

The module requires a designated "Original Resume" node to generate tailored versions. Configure this at `/jobhunter/settings`:

- **Configuration-based (Recommended)**: Admin selects the resume node via entity autocomplete in settings form
- **Fallback (Legacy)**: System searches for a resume node titled "Original Resume" if not configured
- **Warning Logging**: If no resume is found, warnings are logged to help with troubleshooting

## Current Working System **[✅ IMPLEMENTED]**

### **Resume Management & JSON Storage Workflow** **[✅ IMPLEMENTED]**

The module provides a streamlined 4-step workflow focused on JSON storage of resume data:

#### **Step 1: File Upload** **[✅ WORKING]**
- User uploads .docx resume files to `private://job_hunter/resumes/`
- System automatically registers files in `jobhunter_job_seeker_resumes` table
- File entities created automatically for each uploaded resume
- Status checklist initialized: ⬜ Text Extracted | ⬜ Individual JSON Stored | ⬜ Merged to Consolidated

#### **Step 2: Extract Text** **[✅ WORKING]**
- **Button Location**: Inline next to "Text Extracted" status line
- **Action**: Click "Extract Text" button to extract content from .docx file
- **Processing**: Uses `PhpOffice\PhpWord` to parse document structure
- **Storage**: Stores extracted text in `jobhunter_job_seeker_resumes.extracted_text` LONGTEXT field
- **Status Update**: ✅ Text Extracted (X chars) | ⬜ Individual JSON Stored | ⬜ Merged to Consolidated
- **Character Tracking**: Displays character count for verification (e.g., "22,987 chars")

#### **Step 3: Parse JSON** **[✅ WORKING]**
- **Button Location**: Inline next to "Individual JSON Stored" status line
- **Prerequisites**: Text must be extracted first
- **AI Processing**: Calls the shared DeepSeek backend to analyze resume structure
- **Fallback**: Mock data if AWS credentials not configured or timeout occurs
- **JSON Schema**: Structured data with arrays for job_history and education_history
- **Storage**: Stores parsed JSON in `jobhunter_resume_parsed_data.parsed_data` JSON field
- **Status Update**: ✅ Text Extracted | ✅ Individual JSON Stored | ⬜ Merged to Consolidated

**Response Normalization**: The parser now normalizes AI responses that return JSON as a
string-escaped payload (e.g., literal `\n` sequences between lines). This ensures valid
JSON is decoded even when the model returns escaped formatting.

**Individual Resume JSON Structure**:
```json
{
  "professional_summary": "string",
  "skills": ["skill1", "skill2", "skill3"],
  "experience_years": 15,
  "education_level": "Bachelor's",
  "certifications": ["cert1", "cert2"],
  "job_titles": ["title1", "title2"],
  "job_history": [
    {
      "company": "Company Name",
      "title": "Job Title",
      "start_date": "2020-01",
      "end_date": "2023-12",
      "description": "Roles and responsibilities..."
    }
  ],
  "education_history": [
    {
      "institution": "University Name",
      "degree": "Bachelor of Science",
      "field": "Computer Science",
      "graduation_date": "2010-05"
    }
  ],
  "contact_info": {
    "email": "user@example.com",
    "phone": "555-1234",
    "location": "City, State"
  }
}
```

#### **Step 4: Consolidate** **[✅ WORKING]**
- **Button Location**: Inline next to "Merged to Consolidated" status line
- **Prerequisites**: Individual JSON must exist for the resume
- **Deduplication Logic**: Smart merging of data from multiple resumes
  - Skills: Unique values only, comma-separated parsing
  - Professional Summary: Array of unique summaries from all resumes
  - Experience Years: Maximum value across all resumes
  - Education Level: Highest level across all resumes
  - Job History: Deduplicated by company + title + dates, tracks source resumes
  - Education History: Deduplicated by institution + degree, tracks source resumes
- **Storage**: Updates `jobhunter_job_seeker.consolidated_profile_json` JSON field
- **Status Update**: ✅ Text Extracted | ✅ Individual JSON Stored | ✅ Merged to Consolidated

**Profile Pre-Fill**: `/jobhunter/profile/edit` reads `consolidated_profile_json` to
pre-populate contact and summary fields when available.

**Search Assist Section**: Job search preferences and demographic fields are shown
at the top of the profile form under “Fill this out to assist in your search.” These
fields are user-entered and are not derived from the resume JSON.

**Salary Fields**: Salary inputs no longer render a `$` suffix in the UI. The section
now includes a single note indicating all salary values are in USD.

**Consolidated Profile JSON Structure**:
```json
{
  "professional_summary": ["summary from resume 1", "summary from resume 2"],
  "skills": ["skill1", "skill2", "skill3", "skill4"],
  "experience_years": 15,
  "education_level": "Master's",
  "certifications": ["cert1", "cert2", "cert3"],
  "job_titles": ["title1", "title2", "title3"],
  "job_history": [
    {
      "company": "Company A",
      "title": "Senior Engineer",
      "start_date": "2020-01",
      "end_date": "2023-12",
      "description": "Combined roles...",
      "source_resumes": [20, 21]
    }
  ],
  "education_history": [
    {
      "institution": "University",
      "degree": "Master's",
      "field": "CS",
      "graduation_date": "2015-05",
      "source_resumes": [20]
    }
  ]
}
```

#### **Current Scope: JSON + Projections** **[🎯 FOCUSED]**

The workflow uses a hybrid approach:
1. **AI Artifacts (JSON)**
   - **Individual Resume Data**: `jobhunter_resume_parsed_data.parsed_data` (per resume file)
   - **Consolidated Profile Data**: `jobhunter_job_seeker.consolidated_profile_json` (merged from all resumes)
2. **Query-Friendly Projections (Columns)**
   - A small set of commonly-filtered fields are written into dedicated columns on
     `jobhunter_job_seeker` (e.g., location, remote preference, salary range, key URLs).
   - These projection columns are **derived** from `consolidated_profile_json` and can be
     rebuilt at any time; they exist to support fast filtering/sorting and workflow checks
     without requiring JSON parsing.

**Features Deferred (Commented Out)**:
- ❌ Profile text field population (professional_summary, skills, certifications columns)
- ❌ Job history relational table inserts (`jobhunter_job_history` table unused)
- ❌ Education history relational table inserts (`jobhunter_education_history` table unused)

These features exist in the codebase but are commented out per development priorities. Future implementation may populate relational tables and/or Drupal fields from the consolidated JSON data.

### **Automatic Resume Tailoring** (Consolidated from resume_tailoring module):
1. **Create Job Posting** - Add new job_posting node with company, title, and description **[✅ WORKING]**
2. **Automatic AI Tailoring** - System automatically generates tailored resume on save **[✅ WORKING]**
3. **Configuration Check** - Uses configured Original Resume node ID (or title fallback) **[✅ WORKING]**
4. **AI Processing** - Shared DeepSeek chat completion analyzes the job and tailors the resume **[✅ WORKING]**
5. **Resume Saved** - Tailored content saved to field_tailored_resume on job posting **[✅ WORKING]**
6. **Logging & Monitoring** - Full logging for debugging and monitoring **[✅ WORKING]**

### **Development Environment Features** **[✅ IMPLEMENTED]**:
- **Environment Detection** - Automatic dev/prod environment detection **[✅ WORKING]**
- **Configuration Management** - Config entity for Original Resume selection **[✅ WORKING]**
- **Error Handling** - Comprehensive error handling with detailed logging **[✅ WORKING]**
- **Cache Management** - Drupal cache integration with library loading **[✅ WORKING]**
- **Database Integration** - Proper node creation and entity references **[✅ WORKING]**
- **AI Service Integration** - Shared DeepSeek-backed `ai_conversation` runtime with configurable provider settings **[✅ WORKING]**

## Planned System Workflow (Future Development)

### For Users (Full System):
1. **Upload Comprehensive Resume** - AI analyzes skills, experience, and expertise levels **[🔄 TODO]**
2. **Add Employers & Credentials** - Manage login credentials for target employer websites **[🔄 TODO]**
3. **Set Job Preferences** - Define keywords and types of positions of interest **[🔄 TODO]**
4. **Automated Discovery** - System continuously scrapes employer sites for matching jobs **[🔄 TODO]**
5. **AI-Powered Application** - Click "Apply" to have AI tailor resume and submit automatically **[🔄 TODO]**
6. **Manual Completion** - When automation fails, receive tailored resume to finish manually **[🔄 TODO]**

### For Administrators:
1. **Error Queue Management** - Review and resolve failed automation workflows
2. **Automation Improvement** - Update scripts based on employer website changes
3. **User Support** - Help users with credential issues and manual completions
4. **System Monitoring** - Track success rates and identify improvement opportunities

## Key Features

### 🤖 **AI-Powered Resume Analysis & Tailoring** **[✅ IMPLEMENTED - CONSOLIDATED]**
- **AJAX-Based Manual Tailoring** - Complete frontend/backend integration at `/user/{user}/tailor-resume/{job}`
- **Automatic Tailoring on Job Creation** - Generates tailored resume when job_posting node is created
- **Unified AI Service** - Single ResumeTailoringService handles all tailoring operations
- **Environment-Aware Processing** - Mock responses in development, shared DeepSeek provider in production
- **Dynamic Content Generation** - Creates tailored_resume nodes with personalized content
- **Real-Time User Feedback** - JavaScript messaging system with success/error handling
- **Professional UI/UX** - Bootstrap-styled interface with loading states and content preview
- **NOTE**: Consolidated from separate resume_tailoring module into core functionality

### 🏢 **Employer Management & Job Discovery**
- Add and manage target employers with website scraping configuration
- Automated job posting discovery from employer career pages
- Keyword matching against user preferences
- Real-time job posting monitoring and change detection

### 🔍 **Company Research Integration** **[✅ INTEGRATED - Feb 2026]**
- **Automated Company Research** - AI-powered analysis of company career pages, ATS platforms, and authentication methods
- **Research Status Badges** - Visual indicators showing research completion and data freshness
- **ATS Platform Detection** - Automatically identifies which Application Tracking System companies use (Workday, Greenhouse, Taleo, etc.)
- **Automation Readiness Assessment** - Provides readiness levels (Ready/Partial/Manual) for automated application submission
- **Integrated Workflow** - One-click research from company cards at `/jobhunter/companyresearch`
- **Smart Caching** - 30-day cache with staleness indicators and manual refresh options
- **Detailed Research Results** - View complete research data including careers pages, authentication requirements, and CAPTCHA detection
- **Seamless Module Integration** - Leverages the `company_research` module's AI-powered services
- **Back Navigation** - Easy return to company research page from research form

#### Using Company Research
1. Navigate to `/jobhunter/companyresearch` to view all companies
2. Companies with existing research show badges (✓ Researched or 🔄 Stale Research)
3. Click "Research Company" button on any company card to initiate research
4. Review detailed results including ATS platform and automation readiness
5. Click "View Research" to see complete research details anytime
6. Research data is automatically cached for 30 days to optimize performance

For technical details on the research process, see [docs/COMPANY_RESEARCH_DESIGN_SUMMARY.md](docs/COMPANY_RESEARCH_DESIGN_SUMMARY.md).

### 🔐 **Secure Credential Management**
- Encrypted storage of user login credentials for each employer
- Credential validation and testing capabilities
- Multi-factor authentication support
- Secure credential sharing and session management

### 🚀 **Automated Application Submission**
- Browser automation for complex multi-step application processes
- Intelligent form field recognition and completion
- File upload automation (resumes, cover letters, portfolios)
- CAPTCHA detection and error recovery mechanisms

### 📊 **User Dashboard & Management**
- Real-time application status tracking
- Job match recommendations with AI-powered relevance scoring
- One-click application submission with preview capabilities
- Profile completeness indicators and improvement suggestions

### 🛠️ **Advanced Error Handling**
- Comprehensive error detection and categorization
- Admin queue for failed automation workflows
- User notifications for manual completion requirements
- Automation improvement tracking and implementation

## Critical User Requirements

### Resume Completeness
**The system requires comprehensive, detailed resumes to function properly.** Users will be notified that:
- Incomplete resumes will result in poor AI analysis and job matching
- The system cannot effectively tailor applications without sufficient resume detail
- Regular resume updates improve AI accuracy and job match quality
- Resume completeness scoring helps users understand improvement areas

### Employer Credentials
**Users must manage their own login credentials for each target employer.** This includes:
- Secure storage and regular validation of login credentials
- Management of multi-factor authentication requirements
- Credential updates when passwords change or expire
- Understanding of security implications and best practices

## Installation & Setup

### Prerequisites
- Drupal 11 with PHP 8.3+
- MySQL 8.0+ database
- Redis for caching and queue management
- OpenAI API access for GenAI services
- Selenium/Puppeteer for browser automation

### Installation Steps **[✅ WORKING]**
1. **Module Installation** - Place module in `drupal/web/modules/custom/job_hunter/` **[✅ COMPLETED]**
2. **Dependencies** - Install required dependencies: `composer install` **[✅ COMPLETED]**
3. **Module Enable** - Enable the module: `drush en job_hunter` **[✅ COMPLETED]**
4. **Environment Setup** - Run `./scripts/setup-environment.sh` for complete environment **[✅ COMPLETED]**
5. **Content Types** - All required content types created automatically **[✅ COMPLETED]**
6. **AI Tailoring Ready** - Access `/user/{user}/tailor-resume/{job}` for resume tailoring **[✅ COMPLETED]**
7. **Permissions Configuration** - Configure permissions: `/admin/people/permissions` **[🔄 TODO]**

### Configuration Requirements
- **AI Service Configuration** - OpenAI API keys and model settings
- **Scraping Configuration** - Rate limits and respectful scraping policies
- **Security Settings** - Credential encryption and storage policies
- **Queue Management** - Background processing and error handling
- **Admin Notifications** - Error queue alerts and assignment rules

## Usage & Access Points

### External Job API Integrations **[IMPLEMENTED]**

The module integrates with multiple external job search APIs to provide comprehensive job discovery:

#### **SerpAPI (Google Jobs Scraper)** **[IMPLEMENTED]**
- **Purpose**: Access Google Jobs aggregated listings from across the web
- **Free Tier**: 100 searches per month
- **Setup**: Get API key from https://serpapi.com/users/sign_up
- **Documentation**: [SERPAPI_INTEGRATION.md](SERPAPI_INTEGRATION.md)
- **Coverage**: Millions of jobs from Indeed, LinkedIn, Monster, Glassdoor, and more
- **Data**: Title, company, location, salary, description, posting date, apply links
- **Service**: `job_hunter.serpapi` (`SerpApiService.php`)
- **Configuration**: Add SerpAPI API Key in `/jobhunter/settings`

#### **Google Cloud Talent Solution API** **[IMPLEMENTED]**
- **Purpose**: Search your own job postings stored in Google Cloud
- **Setup**: Create service account and download JSON credentials
- **Cost**: Pay-per-use, requires Google Cloud account
- **Coverage**: Your uploaded job data only (not public jobs)
- **Service**: `job_hunter.cloud_talent_solution` (`CloudTalentSolutionService.php`)

#### **Adzuna API** **[IMPLEMENTED]**
- **Purpose**: Search Adzuna's job aggregation network
- **Setup**: Get App ID and App Key from https://developer.adzuna.com/
- **Cost**: Free tier available
- **Coverage**: UK, US, and international job listings
- **Service**: `job_hunter.adzuna_api` (`AdzunaApiService.php`)

#### **USAJobs API** **[IMPLEMENTED]**
- **Purpose**: Search US Government job openings
- **Setup**: Get API key from https://developer.usajobs.gov/
- **Cost**: Free (unlimited searches)
- **Coverage**: Federal government jobs only
- **Service**: `job_hunter.usajobs_api` (`UsaJobsApiService.php`)
- **Configuration**: Add API Key + Email in `/jobhunter/settings`

#### **Unified Job Discovery Interface**
All external APIs are accessible through a single search interface at `/jobhunter/job-discovery`:
- Select one or more data sources (Forseti, SerpAPI, Google Cloud, Adzuna, USAJobs)
- Enter search criteria (keywords, location, employment type, salary range)
- Results displayed with source attribution
- Filter by date posted, remote work, relocation preferences

### User Interface **[✅ PARTIALLY IMPLEMENTED]**
- **Resume Management:** `/job-application/profile` - **[✅ WORKING]** - Upload, extract, parse, and consolidate resume data
  - Step 1: Upload .docx files to private directory
  - Step 2: Extract text from uploaded files
  - Step 3: Parse JSON with the shared DeepSeek AI backend (or mock fallback)
  - Step 4: Consolidate multiple resumes into unified JSON profile
  - Auto-registration of files upon upload
  - Inline action buttons with real-time status indicators
  - Delete functionality for individual resume files
- **AI Resume Tailoring:** `/jobhunter/tailor-resume/{job_id}` - **[✅ WORKING]** - Complete AJAX-powered resume tailoring interface (see detailed flow below)
- **Resume Dashboard:** `/resume-tailoring/dashboard` - **[✅ WORKING]** - Resume tailoring dashboard with job postings

### Resume Tailoring Process Flow **[✅ IMPLEMENTED]**

The resume tailoring page (`/jobhunter/tailor-resume/{job_id}`) provides a comprehensive workflow for tailoring resumes to specific job postings.

#### Status Lifecycle

| Status | Database Value | UI Label | Description |
|--------|----------------|----------|-------------|
| **Pending** | `pending` (or no record) | "Ready to Tailor" | Initial state - user hasn't requested tailoring |
| **Queued** | `queued` | "In Queue" | Request submitted, waiting for queue worker |
| **Processing** | `processing` | "Tailoring in Progress" | AI is generating tailored resume (30-60 sec) |
| **Completed** | `completed` | "Tailored & Ready" | Resume ready for review and PDF generation |
| **Failed** | `failed` | "Tailoring Failed" | Error occurred, retry available |

#### Status Determination Logic

```
Page Load → Query jobhunter_tailored_resumes for (uid, job_id)
         ↓
    Record exists?
         ↓
    NO → status = 'pending'
    YES → status = record.tailoring_status
```

#### Skills Gap Analysis

The page automatically calculates which job-required skills are missing from the user's profile:

**Data Sources:**
- Job skills from `jobhunter_job_requirements.skills_required_json` (must_have, nice_to_have, tech_stack)
- User skills from `jobhunter_job_seeker.consolidated_profile_json` (technical_expertise, skills, certifications)

**Matching Logic:**
- Case-insensitive comparison
- Fuzzy matching (substring containment both directions)
- Results split into "Must Have (Missing)" and "Nice to Have (Missing)"

#### Add Skill to Profile Feature

Users can add missing skills directly from the tailoring page:
- **Individual:** Click "+ Add to Profile" on any missing skill
- **Bulk:** "Add All Must-Have Skills" or "Add All Missing Skills" buttons
- **Storage:** Skills added to `consolidated_profile_json.technical_expertise`
- **Default Proficiency:** 'intermediate'
- **Refresh:** Click "🔄 Refresh Skills Gap" after adding to re-calculate

#### Process Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         USER VISITS PAGE                                 │
└─────────────────────────────────────────────────────────────────────────┘
                                 │
                ┌────────────────┼────────────────┐
                ▼                ▼                ▼
         ┌──────────┐    ┌──────────────┐   ┌────────────┐
         │ SKILLS   │    │ TAILORING    │   │ COMPLETED  │
         │ GAP      │    │ STATUS       │   │ PREVIEW    │
         │          │    │              │   │            │
         │ Missing  │    │ Progress bar │   │ Tailored   │
         │ skills + │    │ showing step │   │ resume +   │
         │ Add btns │    │ 1→2→3→4      │   │ PDF opts   │
         └──────────┘    └──────────────┘   └────────────┘
```

#### Queue Processing

**Queue Worker:** `job_hunter_resume_tailoring`
**Location:** `ResumeTailoringWorker.php`

```
User clicks "Generate" → tailorResumeAjax()
                              │
                              ▼
                    Insert into Drupal queue
                    Set status = 'queued'
                              │
                              ▼
                    Queue worker picks up job
                    Set status = 'processing'
                              │
                              ▼
                    Call shared DeepSeek AI
                              │
              ┌───────────────┼───────────────┐
              ▼                               ▼
         Success                           Failure
    status = 'completed'              status = 'failed'
    Store tailored_resume_json        Log error
```

#### Database Tables

**Storage Strategy:** This module uses a hybrid model — **nodes for canonical content** and **custom tables for operational/automation data** (AI artifacts, pipeline state, sync metadata). See [ARCHITECTURE.md](ARCHITECTURE.md) for policy and rules.

| Table | Purpose |
|-------|---------|
| `jobhunter_job_requirements` | Job posting data (extracted_json, skills_required_json) |
| `jobhunter_companies` | Company profile data and scraping configuration |
| `jobhunter_job_seeker` | User profile with `consolidated_profile_json` |
| `jobhunter_job_seeker_resumes` | Uploaded resumes and extracted text state |
| `jobhunter_resume_parsed_data` | Parsed resume JSON data |
| `jobhunter_job_history` | Normalized work history (future use) |
| `jobhunter_education_history` | Normalized education history (future use) |
| `jobhunter_tailored_resumes` | Tailored results and `tailoring_status` |
| `jobhunter_pdf_history` | Tracks generated PDF files per job |
| `jobhunter_google_jobs_sync` | Google Jobs sync state and metrics |
| `jobhunter_google_jobs_validation_log` | Validation history and results |

#### Available Actions by Status

| Status | Generate | Skills Gap | Add Skills | View Resume | PDF | Regenerate |
|--------|----------|------------|------------|-------------|-----|------------|
| pending | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| queued | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ |
| processing | ❌ | ✅ | ✅ | ❌ | ❌ | ❌ |
| completed | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ |
| failed | ✅ (Retry) | ✅ | ✅ | ❌ | ❌ | ❌ |

### User Profile Forms **[✅ IMPLEMENTED]**

The User Profile Forms feature provides a comprehensive profile management system that allows users to maintain job search information required for job matching and application automation.

#### Profile Completeness System

Users are assigned a profile completeness percentage (0-100%) calculated from two categories:

**Required Fields (70% weight):**
- 📄 Resume file (`field_resume`)
- 🛂 Work authorization status (`field_work_authorization`)
- 📅 Available start date (`field_available_start_date`)
- 🏠 Remote work preference (`field_remote_preference`)

**Optional Fields (30% weight):**
- Professional summary
- Key skills
- Professional keywords
- Salary expectations
- Target companies

**Calculation Logic:**
```
Required Completeness = (Filled Required Fields / 4) × 70%
Optional Completeness = (Filled Optional Fields / 5) × 30%
Total Profile % = Required Completeness + Optional Completeness
```

#### Access Points

| Path | Purpose | Access |
|------|---------|--------|
| `/jobhunter/profile/summary` | Profile dashboard with completeness indicator | Authenticated users |
| `/user/{uid}/edit` | Edit profile with organized fieldsets | User or admin |
| `/user/register` | Registration form with job search section | Anonymous (registration enabled) |

#### Profile Dashboard (`/jobhunter/profile/summary`)

The profile dashboard displays:

1. **Completeness Progress Bar**
   - Visual progress indicator showing 0-100%
   - Color-coded status (red: incomplete, yellow: nearly complete, green: complete)
   - Animated transitions on profile updates

2. **Validation Alerts** (if profile incomplete)
   - Lists all missing required fields with emoji icons
   - Provides specific guidance for each field
   - Includes links to edit form for quick completion

3. **Profile Summary Table**
   - All 9 tracked profile fields displayed
   - Current value or "Missing" badge
   - Completion indicator (✅ or 🔲)
   - Smart value display per field type:
     - Resume: Filename with file icon
     - Dates: Formatted (Month Day, Year)
     - Salary: Currency format with range
     - Remote/Authorization: Text display
     - Companies: Count with term naming

#### Form Organization

**User Registration Form** (`/user/register`)
- New section: "Job Search Profile" (fieldset)
- Displays 4 required fields with descriptions
- Minimizes overwhelm for new users
- Descriptions provide inline help text

**User Edit Form** (`/user/{uid}/edit`)
- New section: "Job Search Information" (collapsible)
- All 11 profile fields organized by category:
  - Basic Information (resume, authorization, dates)
  - Work Preferences (remote, relocation, travel requirements)
  - Professional Details (summary, skills, keywords)
  - Compensation (salary expectations)
  - Target Companies (company selection)
- Field descriptions from service provide guidance
- Organized tabs/fieldsets prevent cognitive overload

#### Service Architecture

**Service:** `UserJobProfileService` (`src/Service/UserJobProfileService.php`)

```php
// Get profile as percentage (0-100)
$completeness = $service->getProfileCompleteness($user);

// Check if profile meets minimum requirements
$is_complete = $service->isProfileComplete($user);

// Get validation errors for missing fields
$errors = $service->validateProfile($user);

// Get summary of all profile fields for display
$summary = $service->getProfileSummary($user);

// Get field descriptions for form labels
$descriptions = $service->getFieldDescriptions();

// Update completeness tracking fields
$service->updateProfileCompleteness($user);
```

#### CSS Styling

Profile display uses centralized CSS styling via the `job_hunter/user-profile-styling` library:

**Files:**
- `css/profile.css` - All profile-related styling

**Key Classes:**
- `.profile-completeness-container` - Dashboard container
- `.progress-bar` - Animated completeness progress bar
- `.validation-errors` - Missing field alerts with styling
- `.profile-summary-container` - Field summary table wrapper
- `.profile-fields-table` - Profile fields data table with responsive design
- `.badge-success` / `.badge-incomplete` - Field status badges

**Responsive Design:**
- Desktop: Full table layout with hover effects
- Tablet: Readable with adjusted padding
- Mobile: Card-based layout with data attributes

#### Testing

**Unit Tests:** `tests/src/Unit/Service/UserJobProfileServiceTest.php`
- Profile completeness calculation with various field combinations
- Validation error detection for required fields
- Completion status checks
- Profile summary generation
- Field descriptions retrieval

**Functional Tests:** `tests/src/Functional/UserProfileFormTest.php`
- Registration form includes job search fields
- Edit form requires job search section
- Profile completeness updates on save
- Validation errors display correctly
- Profile marked complete when required fields filled

**Running Tests:**
```bash
# Run all job_hunter tests
./vendor/bin/phpunit modules/custom/job_hunter/tests

# Run only profile service tests
./vendor/bin/phpunit modules/custom/job_hunter/tests/src/Unit/Service/UserJobProfileServiceTest.php

# Run only profile form tests
./vendor/bin/phpunit modules/custom/job_hunter/tests/src/Functional/UserProfileFormTest.php
```

#### Hooks Implementation

**Form Alteration Hooks** (job_hunter.module):

1. `hook_form_user_register_form_alter()`
   - Adds job_search_profile fieldset with required fields
   - Sets field descriptions from service
   - Makes registration form collect essential profile info

2. `hook_form_user_form_alter()`
   - Reorganizes 11 profile fields into collapsible sections
   - Adds descriptions from service for guidance
   - Attaches submit handler for tracking

3. `job_hunter_user_form_submit()`
   - After form save, updates profile completeness tracking
   - Updates `field_profile_completeness` and `field_last_profile_update`
   - Displays success message with profile status

#### Controller Methods

**ProfileDashboard** (UserProfileController::profileDashboard())
- Loads current user profile
- Calls service methods to fetch metrics
- Renders profile-completeness and profile-summary templates
- Sets no-cache headers for real-time display
- Provides profile summary + validation errors + action buttons

#### Database Fields

**Core Profile Fields (User Entity):**
- `field_resume` - File field for resume upload
- `field_work_authorization` - Select list (US Citizen, Green Card, etc.)
- `field_available_start_date` - Date field
- `field_remote_preference` - Select (Remote Only, On-Site Only, Hybrid, Any)
- `field_professional_summary` - Long text field
- `field_key_skills` - Multi-value text field (skills list)
- `field_professional_keywords` - Text field
- `field_salary_expectation` - Entity reference range (min/max salary nodes)
- `field_target_companies` - Multi-select entity reference to company nodes

**Tracking Fields:**
- `field_profile_completeness` - Integer (0-100%)
- `field_last_profile_update` - Timestamp field

#### Next Actions

The User Profile Forms provide the foundation for:
1. **Job Matching** - Uses profile data to find relevant jobs
2. **Application Automation** - Requires complete profile before submission
3. **AI Tailoring** - Uses profile fields to contextualize resume generation
4. **User Dashboard** - Displays recommendations based on profile completeness

- **Content Management:** `/node/add/{type}` - **[✅ WORKING]** - Standard Drupal content creation forms
- **Employer Management:** `/job-application/employers` - **[🔄 TODO]** - Add employers and manage credentials  
- **Application History:** `/job-application/history` - **[🔄 TODO]** - View all applications and their status

### Administrative Interface
- **Admin Dashboard:** `/admin/job-applications` - System overview and analytics
- **Error Queue:** `/admin/job-applications/queue` - Failed workflow management
- **User Management:** `/admin/job-applications/users` - User profile and credential oversight
- **System Settings:** `/jobhunter/settings` (Admin > Job Hunter > Settings) - Module configuration
- **Scraping Management:** `/admin/job-applications/scraping` - Job discovery configuration

## Permissions & Security

### User Permissions
- `access job application dashboard` - View personal dashboard and job matches
- `manage job application profile` - Upload resume and manage personal profile
- `manage employer credentials` - Add employers and store login credentials
- `view job application history` - Access personal application history and status
- `use automated job application` - Use AI-powered application submission

### Administrative Permissions
- `administer job application automation` - Full system administration access
- `manage job application queue` - Process failed automation workflows
- `view all job applications` - Access all user applications and data
- `configure job scraping` - Manage employer scraping and discovery settings
- `manage system credentials` - Oversee user credential security and validation

### Security Considerations
- **Credential Encryption:** All user credentials encrypted with industry-standard algorithms
- **Access Logging:** Complete audit trail for all credential access and system usage
- **Rate Limiting:** Protection against abuse and excessive API usage
- **Data Privacy:** GDPR compliance with user data deletion and export capabilities
- **AI Security:** Input validation and prompt injection prevention for GenAI services

## Development Guidelines

### Required Reading
1. **[ARCHITECTURE.md](ARCHITECTURE.md)** - Complete system architecture and design patterns
2. **Entity Relationships** - Understanding of complex entity relationships and dependencies
3. **AI Integration Patterns** - GenAI service integration and error handling
4. **Security Protocols** - Credential management and encryption requirements
5. **Automation Frameworks** - Browser automation and form submission handling

### CSS and Styling Standards
**CENTRALIZED STYLING POLICY**: This module follows strict centralized CSS architecture:
- **NO INLINE STYLES**: Never use inline `style=""` attributes in templates
- **NO STYLE TAGS**: Never embed `<style>` tags in template files
- **CSS LIBRARIES ONLY**: All styling must be defined in separate CSS files in the `css/` directory
- **CLASS-BASED STYLING**: Use semantic CSS classes and apply styles through registered Drupal libraries
- **THEME CONSISTENCY**: Follow established design patterns and the project style guide
- **RESPONSIVE DESIGN**: Use CSS media queries in stylesheets, not inline styles
- **LIBRARY REGISTRATION**: All CSS files must be registered in `job_hunter.libraries.yml`

**Module CSS Files**:
- `css/job-hunter-home.css` - Dashboard and home page styling
- `css/job-hunter-navigation.css` - Navigation block styling
- `css/job-discovery.css` - Job discovery workflow styling
- `css/tailor-resume.css` - Resume tailoring interface styling
- `css/company-profile.css` - Company management styling
- `css/user-profile-custom.css` - User profile styling
- `css/documentation.css` - Documentation page styling
- `css/job_hunter.css` - Global module styling

### Development Phases
- **Phase 1:** Core infrastructure and entity definitions (Current)
- **Phase 2:** AI integration and job discovery systems
- **Phase 3:** Automated submission and error handling
- **Phase 4:** Production optimization and advanced features

### Critical Development Notes
- **No Direct Coding Without Architecture Review** - All development must follow documented architecture
- **Security-First Approach** - Credential handling and user data protection is paramount
- **AI Service Integration** - Proper error handling and fallback mechanisms required
- **Respectful Automation** - Rate limiting and ethical scraping practices mandatory
- **Comprehensive Testing** - Automated testing for all AI and automation workflows

## System Requirements

### Minimum Requirements
- **Drupal 11** with PHP 8.3+
- **MySQL 8.0+** for primary data storage
- **Redis** for caching and queue management
- **OpenAI API Access** for GenAI resume analysis and tailoring
- **Sufficient Server Resources** for browser automation and background processing

### Recommended Infrastructure
- **Load Balancing** for high-volume job scraping and application submission
- **Dedicated Queue Workers** for background AI processing
- **CDN Integration** for fast resume and document delivery
- **Monitoring Systems** for error tracking and performance optimization
- **Backup Systems** for critical user data and credential protection

### Crontab Configuration (Production)

The following cron jobs are required for background queue processing on the production server:

```bash
# Run Drupal cron frequently so queue workers process pending items automatically.
* * * * * cd /var/www/html/forseti && vendor/bin/drush cron >/dev/null 2>&1

# Optional: explicit queue runners for higher throughput (all protected with non-blocking locks).
*/5 * * * * cd /var/www/html/forseti && flock -n /tmp/jh_genai_parsing.lock vendor/bin/drush queue:run job_hunter_genai_parsing --time-limit=240 2>&1 | logger -t job_hunter_queue
*/5 * * * * cd /var/www/html/forseti && flock -n /tmp/jh_job_posting_parsing.lock vendor/bin/drush queue:run job_hunter_job_posting_parsing --time-limit=240 2>&1 | logger -t job_hunter_queue
*/5 * * * * cd /var/www/html/forseti && flock -n /tmp/jh_resume_tailoring.lock vendor/bin/drush queue:run job_hunter_resume_tailoring --time-limit=240 >> /var/log/drupal/tailoring_queue.log 2>&1
```

**Queue Workers:**
- `job_hunter_genai_parsing` - Resume text extraction and JSON parsing
- `job_hunter_job_posting_parsing` - Job posting AI analysis (skills, keywords, company extraction)
- `job_hunter_resume_tailoring` - AI-powered resume tailoring for specific job postings

## Monitoring & Analytics

### Success Metrics
- **Application Success Rate** - Percentage of successful automated submissions
- **Resume Analysis Accuracy** - AI-powered skills and experience assessment quality
- **Job Match Relevance** - User satisfaction with AI-recommended job matches
- **Error Resolution Time** - Average time to resolve failed automation workflows
- **User Engagement** - Dashboard usage and feature adoption rates

### Alert Systems
- **Failed Submission Alerts** - Immediate notification of automation failures
- **Credential Validation Errors** - Alerts for expired or invalid user credentials
- **AI Service Outages** - Monitoring and fallback for GenAI service disruptions
- **Scraping Failures** - Detection of employer website changes affecting job discovery
- **Security Incidents** - Monitoring for unauthorized access or credential breaches

## Support & Documentation

### User Support Resources
- **Getting Started Guide** - Step-by-step setup for new users
- **Resume Optimization Tips** - Best practices for AI-friendly resume formatting
- **Credential Management** - Security guidelines for employer login information
- **Troubleshooting Guide** - Common issues and resolution steps
- **Privacy & Security FAQ** - User data protection and privacy policies

### Developer Resources
- **Architecture Documentation** - [ARCHITECTURE.md](ARCHITECTURE.md) (Required Reading)
- **Google Job Search API Integration** - [docs/GOOGLE_JOB_SEARCH_API_INTEGRATION.md](docs/GOOGLE_JOB_SEARCH_API_INTEGRATION.md) - Complete guide for integrating with Google for Jobs
- **API Documentation** - Integration points and service interfaces
- **Testing Guidelines** - Automated testing requirements and best practices
- **Deployment Procedures** - Production deployment and configuration
- **Security Protocols** - Credential handling and encryption standards

### Getting Help
For technical issues, feature requests, and development questions:
- **Project Repository:** https://github.com/keithaumiller/forseti.life
- **Issue Tracking:** GitHub Issues for bug reports and feature requests
- **Development Discussions:** GitHub Discussions for architecture and implementation questions

## License & Compliance
- **License:** GPL v2 or later
- **Data Privacy:** GDPR and CCPA compliant
- **Security Standards:** SOC 2 Type II framework alignment
- **Ethical AI:** Responsible AI usage guidelines and bias mitigation
- **Web Scraping:** Respectful scraping practices and robots.txt compliance
