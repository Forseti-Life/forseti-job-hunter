# Job Application Submission Process Documentation

## Overview

This document provides comprehensive documentation for the job application submission process within the jobhunter module. It covers process flows, dependencies, data sources, and integration points required for successful submission to companies.

**Status Legend:**
- ✅ **IMPLEMENTED** - Feature fully operational
- 🔄 **PARTIAL** - Partially implemented
- 📋 **PLANNED** - Designed but not yet implemented

---

## Table of Contents

- [Process Flows](#process-flows)
  - [Step 1: Upload Resume & Clean Up Profile](#step-1-upload-resume--clean-up-profile)
  - [Step 2: Target Companies](#step-2-target-companies)
  - [Step 3: AI Job Discovery (Coming Soon)](#step-3-ai-job-discovery-coming-soon)
  - [Step 4: Application Submission (Coming Soon)](#step-4-application-submission-coming-soon)
  - [Step 5: Interview & Follow-up (Coming Soon)](#step-5-interview--follow-up-coming-soon)
  - [Step 6: Analytics (Coming Soon)](#step-6-analytics-coming-soon)
- [Dependencies](#dependencies)
- [Data Sources](#data-sources)
- [Integration Points](#integration-points)

---

## Process Flows

### Step 1: Upload Resume & Clean Up Profile

**Status:** ✅ IMPLEMENTED

#### Overview
Step 1 enables users to upload their resume files, parse them with AI, and refine their consolidated profile. This creates a comprehensive, structured profile that serves as the foundation for job matching and application tailoring.

#### Process Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    STEP 1: RESUME UPLOAD & PROFILE              │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ User navigates to /job-application/profile                      │
│ - Profile Management Interface Loads                            │
│ - Displays current profile completeness: 0%                     │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 1.1: FILE UPLOAD                                                │
│ User uploads .docx resume file(s)                               │
│                                                                 │
│ System Actions:                                                 │
│ - Save file to private://job_hunter/resumes/                   │
│ - Auto-register in jobhunter_job_seeker_resumes table          │
│ - Create Drupal file entity                                     │
│ - Initialize status: ⬜ Text | ⬜ JSON | ⬜ Consolidated         │
│                                                                 │
│ Status: ✅ File uploaded                                        │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 1.2: TEXT EXTRACTION                                            │
│ User clicks "Extract Text" button                               │
│                                                                 │
│ System Actions:                                                 │
│ - Use PhpOffice\PhpWord to parse .docx structure               │
│ - Extract plain text content                                    │
│ - Store in jobhunter_job_seeker_resumes.extracted_text         │
│ - Update status: ✅ Text Extracted (X chars) | ⬜ JSON | ⬜ Cons│
│ - Display character count for verification                      │
│                                                                 │
│ Status: ✅ Text extracted                                       │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 1.3: AI JSON PARSING                                            │
│ User clicks "Parse JSON" button                                 │
│                                                                 │
│ System Actions:                                                 │
│ - Call AWS Bedrock (Claude 3.5 Sonnet)                         │
│ - Send extracted text with structured prompt                    │
│ - Parse response into JSON schema                               │
│ - Store in jobhunter_resume_parsed_data.parsed_data            │
│ - Fallback to mock data if AWS unavailable                      │
│ - Update status: ✅ Text | ✅ JSON Stored | ⬜ Consolidated     │
│                                                                 │
│ Status: ✅ JSON parsed and stored                               │
│                                                                 │
│ JSON Structure:                                                 │
│ {                                                               │
│   "professional_summary": "string",                             │
│   "skills": ["skill1", "skill2"],                              │
│   "experience_years": 15,                                       │
│   "education_level": "Bachelor's",                              │
│   "certifications": ["cert1", "cert2"],                         │
│   "job_history": [{ company, title, dates, description }],     │
│   "education_history": [{ institution, degree, field }],       │
│   "contact_info": { email, phone, location }                    │
│ }                                                               │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 1.4: CONSOLIDATION                                              │
│ User clicks "Consolidate" button                                │
│                                                                 │
│ System Actions:                                                 │
│ - Load all resume JSON data for user                            │
│ - Deduplicate and merge data:                                   │
│   • Skills: Unique values only                                  │
│   • Professional Summary: Array of summaries                    │
│   • Experience Years: Maximum value                             │
│   • Education Level: Highest level                              │
│   • Job History: Dedupe by company+title+dates                  │
│   • Education History: Dedupe by institution+degree             │
│ - Store in jobhunter_job_seeker.consolidated_profile_json      │
│ - Update status: ✅ Text | ✅ JSON | ✅ Merged to Consolidated  │
│ - Update profile completeness percentage                        │
│                                                                 │
│ Status: ✅ Profile consolidated                                 │
│                                                                 │
│ Consolidated JSON Structure:                                    │
│ {                                                               │
│   "professional_summary": ["summary1", "summary2"],             │
│   "skills": ["skill1", "skill2", "skill3"],                    │
│   "experience_years": 15,                                       │
│   "education_level": "Master's",                                │
│   "job_history": [{                                             │
│     company, title, dates, description,                         │
│     source_resumes: [20, 21]                                    │
│   }],                                                           │
│   "education_history": [{                                       │
│     institution, degree, field,                                 │
│     source_resumes: [20]                                        │
│   }]                                                            │
│ }                                                               │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ PROFILE READY                                                   │
│ Profile completeness: 100%                                      │
│ User can proceed to Step 2: Target Companies                    │
└─────────────────────────────────────────────────────────────────┘
```

#### Data Tables Used

| Table Name | Purpose | Key Fields |
|------------|---------|------------|
| `jobhunter_job_seeker_resumes` | Resume file tracking | file_id, uid, extracted_text, status |
| `jobhunter_resume_parsed_data` | Individual resume JSON | resume_id, parsed_data (JSON) |
| `jobhunter_job_seeker` | Consolidated profile | uid, consolidated_profile_json |

#### Dependencies (Step 1)

- **PHP Libraries:**
  - `phpoffice/phpword` - DOCX parsing
  - AWS SDK for PHP - Bedrock integration
  
- **External Services:**
  - AWS Bedrock with Claude 3.5 Sonnet
  
- **Drupal Services:**
  - File system (private files)
  - Entity management
  - User session

#### Success Metrics

- Resume file uploaded successfully
- Text extraction completes with character count > 100
- JSON parsing returns valid structured data
- Consolidated profile shows 100% completeness

---

### Step 2: Target Companies

**Status:** 🔄 PARTIAL

#### Overview
Step 2 allows users to build and manage a list of companies they want to work for. This creates the foundation for job discovery and application submission.

#### Process Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    STEP 2: TARGET COMPANIES                      │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ User navigates to /job-application/companies                    │
│ - Company Management Interface Loads                            │
│ - Displays current target company count: 0                      │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2.1: ADD COMPANY                                                │
│ User clicks "Add Company" button                                │
│                                                                 │
│ Company Information Form:                                       │
│ - Company Name (required)                                       │
│ - Website URL (required)                                        │
│ - Career Page URL (optional)                                    │
│ - Application Portal URL (optional)                             │
│ - Company Description                                           │
│ - Industry/Sector                                               │
│ - Company Size                                                  │
│ - Priority Level (High/Medium/Low)                              │
│                                                                 │
│ System Actions:                                                 │
│ - Validate company doesn't already exist                        │
│ - Create company node (content type: company)                   │
│ - Link company to user's profile                                │
│ - Set initial status: "Active"                                  │
│                                                                 │
│ Status: ✅ Company added to target list                         │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2.2: CONFIGURE CREDENTIALS (OPTIONAL)                           │
│ User adds login credentials for company portal                  │
│                                                                 │
│ Credential Information:                                         │
│ - Portal URL (required)                                         │
│ - Username/Email (required)                                     │
│ - Password (encrypted storage)                                  │
│ - Security Questions/Answers                                    │
│ - Multi-Factor Auth method                                      │
│ - Notes                                                         │
│                                                                 │
│ System Actions:                                                 │
│ - Encrypt password with industry-standard algorithm             │
│ - Store in secure credential table                              │
│ - Link credentials to company and user                          │
│ - Validate credentials (optional test)                          │
│                                                                 │
│ Status: ✅ Credentials stored securely                          │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2.3: SET JOB PREFERENCES                                        │
│ User configures job search preferences for company             │
│                                                                 │
│ Preference Settings:                                            │
│ - Keywords to match (skills, technologies)                      │
│ - Job titles of interest                                        │
│ - Department/Function preferences                               │
│ - Location preferences                                          │
│ - Remote/Hybrid/Onsite preference                               │
│ - Salary range expectations                                     │
│ - Employment type (Full-time/Contract/etc.)                     │
│                                                                 │
│ System Actions:                                                 │
│ - Store preferences in job_seeker_company_preferences table     │
│ - Create matching rules for job discovery                       │
│ - Set up automated scraping schedule                            │
│                                                                 │
│ Status: ✅ Preferences configured                               │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2.4: REVIEW & MANAGE COMPANIES                                  │
│ User views list of target companies                             │
│                                                                 │
│ Company Management Actions:                                     │
│ - Edit company information                                      │
│ - Update credentials                                            │
│ - Modify preferences                                            │
│ - Set priority level                                            │
│ - Pause/Resume monitoring                                       │
│ - Remove from target list                                       │
│                                                                 │
│ Dashboard Display:                                              │
│ - Total target companies: X                                     │
│ - Active monitoring: X                                          │
│ - Jobs discovered: X                                            │
│ - Applications submitted: X                                     │
│                                                                 │
│ Status: ✅ Companies managed                                    │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ READY FOR JOB DISCOVERY                                         │
│ Target companies configured: X companies                        │
│ User can proceed to Step 3: AI Job Discovery                    │
└─────────────────────────────────────────────────────────────────┘
```

#### Data Tables Used

| Table Name | Purpose | Key Fields |
|------------|---------|------------|
| `company` (node) | Company information | name, website_url, career_page_url |
| `job_seeker_company_credentials` | Login credentials | company_id, uid, username, encrypted_password |
| `job_seeker_company_preferences` | Job preferences | company_id, uid, keywords, job_titles |
| `job_seeker_companies` | User-company relationships | uid, company_id, priority, status |

#### Dependencies (Step 2)

- **Drupal Content Types:**
  - Company node type
  
- **Security:**
  - Encryption service for credentials
  - Secure credential storage
  
- **Drupal Services:**
  - Entity management
  - User session
  - Views for company listing

#### Success Metrics

- At least 1 company added to target list
- Company information complete (name, URLs)
- Credentials stored (if applicable)
- Job preferences configured

---

### Step 3: AI Job Discovery (Coming Soon)

**Status:** 📋 PLANNED

#### Overview
Step 3 will automatically find matching jobs at target companies using AI-powered web scraping and intelligent job matching algorithms.

#### Planned Process Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                  STEP 3: AI JOB DISCOVERY                       │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3.1: AUTOMATED SCRAPING                                         │
│ System continuously monitors target company career pages        │
│                                                                 │
│ Planned Features:                                               │
│ - Scheduled cron job for job discovery                          │
│ - Web scraping of company career pages                          │
│ - HTML parsing and job extraction                               │
│ - Job posting metadata extraction                               │
│ - Change detection (new/updated/removed jobs)                   │
│                                                                 │
│ System Actions:                                                 │
│ - Queue scraping jobs for each company                          │
│ - Execute company-specific scrapers                             │
│ - Parse HTML and extract job data                               │
│ - Store as job_posting nodes                                    │
│ - Track scraping history and errors                             │
│                                                                 │
│ Status: 📋 Not yet implemented                                  │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3.2: AI-POWERED JOB MATCHING                                    │
│ System matches discovered jobs to user profile                  │
│                                                                 │
│ Planned Features:                                               │
│ - Compare job requirements to user skills                       │
│ - Calculate match score (0-100%)                                │
│ - Identify missing required skills                              │
│ - Prioritize by match quality and user preferences              │
│ - Consider location, salary, and other criteria                 │
│                                                                 │
│ Matching Algorithm:                                             │
│ - Skills match (40% weight)                                     │
│ - Experience level match (20% weight)                           │
│ - Education match (15% weight)                                  │
│ - Location preference (10% weight)                              │
│ - Job title preference (15% weight)                             │
│                                                                 │
│ Status: 📋 Algorithm design phase                               │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3.3: JOB RECOMMENDATION                                         │
│ User views dashboard with matched jobs                          │
│                                                                 │
│ Planned Features:                                               │
│ - Dashboard showing matched jobs                                │
│ - Match score visualization                                     │
│ - Job details preview                                           │
│ - Skills gap analysis                                           │
│ - One-click application initiation                              │
│ - Save jobs for later                                           │
│ - Mark jobs as not interested                                   │
│                                                                 │
│ Status: 📋 UI mockups in progress                               │
└─────────────────────────────────────────────────────────────────┘
```

#### Planned Dependencies (Step 3)

- **Web Scraping:**
  - Guzzle HTTP client
  - HTML parsing library (Symfony DomCrawler)
  - Proxy service (optional)
  
- **AI Services:**
  - AWS Bedrock for job description analysis
  - Natural language processing for keyword extraction
  
- **Queue Management:**
  - Drupal Queue API
  - Cron for scheduled scraping
  
- **Company-Specific Scrapers:**
  - Custom scraper per target company
  - Adaptable to website changes

#### Planned Data Sources (Step 3)

- Company career page HTML
- Job posting detail pages
- Company APIs (if available)
- Third-party job boards (future)

#### Planned Integration Points (Step 3)

- Company career websites (HTTP/HTTPS)
- AWS Bedrock API for job analysis
- Internal job_posting content type
- User notification system

---

### Step 4: Application Submission (Coming Soon)

**Status:** 📋 PLANNED

#### Overview
Step 4 will enable auto-application with tailored resumes, using AI to customize applications for each job and automating form submission.

#### Planned Process Flow

```
┌─────────────────────────────────────────────────────────────────┐
│               STEP 4: APPLICATION SUBMISSION                     │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4.1: RESUME TAILORING                                           │
│ System tailors resume for specific job                          │
│                                                                 │
│ Current Implementation: ✅ WORKING                              │
│ - User clicks "Generate Tailored Resume"                        │
│ - AWS Bedrock analyzes job description                          │
│ - AI generates customized resume content                        │
│ - Tailored resume saved to database                             │
│ - User can review and edit                                      │
│                                                                 │
│ Planned Enhancement:                                            │
│ - Automatic tailoring on job match                              │
│ - Multiple resume style templates                               │
│ - Skills gap highlighting                                       │
│ - Cover letter generation                                       │
│                                                                 │
│ Status: 🔄 Core implemented, enhancements planned               │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4.2: APPLICATION FORM AUTOMATION                                │
│ System automatically fills out application forms                │
│                                                                 │
│ Planned Features:                                               │
│ - Browser automation (Selenium/Puppeteer)                       │
│ - Intelligent form field recognition                            │
│ - Auto-fill from consolidated profile                           │
│ - File upload automation (resume, cover letter)                 │
│ - Multi-step form navigation                                    │
│ - CAPTCHA detection and human intervention                      │
│                                                                 │
│ Form Mapping:                                                   │
│ - Map profile fields to application form fields                 │
│ - Handle various field types (text, select, file, etc.)        │
│ - Support custom questions                                      │
│ - Validate required fields before submission                    │
│                                                                 │
│ Status: 📋 Architecture design phase                            │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4.3: SUBMISSION EXECUTION                                       │
│ System submits application to company portal                    │
│                                                                 │
│ Planned Features:                                               │
│ - Login with stored credentials                                 │
│ - Navigate to application page                                  │
│ - Fill all form fields                                          │
│ - Upload tailored resume and documents                          │
│ - Handle multi-factor authentication                            │
│ - Submit application                                            │
│ - Capture confirmation details                                  │
│                                                                 │
│ Error Handling:                                                 │
│ - Detect submission failures                                    │
│ - Capture error messages                                        │
│ - Save partial progress                                         │
│ - Queue for manual completion                                   │
│ - Notify user of issues                                         │
│                                                                 │
│ Status: 📋 Requirements gathering phase                         │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4.4: APPLICATION TRACKING                                       │
│ System records application details and status                   │
│                                                                 │
│ Planned Features:                                               │
│ - Create application node                                       │
│ - Store submission timestamp                                    │
│ - Link to job posting and company                               │
│ - Save confirmation number/URL                                  │
│ - Set initial status: "Submitted"                               │
│ - Track tailored resume used                                    │
│ - Record submission method (auto/manual)                        │
│                                                                 │
│ Status: 📋 Data model defined                                   │
└─────────────────────────────────────────────────────────────────┘
```

#### Planned Dependencies (Step 4)

- **Browser Automation:**
  - Selenium WebDriver or Puppeteer
  - Chrome/Firefox browser drivers
  
- **AI Services:**
  - AWS Bedrock for resume tailoring (✅ implemented)
  
- **Document Generation:**
  - PDF generation library
  - DOCX export capability
  
- **Authentication:**
  - Session management
  - OAuth support (future)

#### Planned Data Sources (Step 4)

- User consolidated profile JSON
- Tailored resume content
- Company credential store
- Job posting details
- Application form metadata

#### Planned Integration Points (Step 4)

- Company application portals (web automation)
- Email confirmation systems
- Document upload endpoints
- Authentication systems (login, MFA)

---

### Step 5: Interview & Follow-up (Coming Soon)

**Status:** 📋 PLANNED

#### Overview
Step 5 will track application status, manage interview scheduling, and automate follow-up communications.

#### Planned Process Flow

```
┌─────────────────────────────────────────────────────────────────┐
│            STEP 5: INTERVIEW & FOLLOW-UP                        │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5.1: APPLICATION STATUS MONITORING                              │
│ System tracks application progress                              │
│                                                                 │
│ Planned Features:                                               │
│ - Email monitoring for responses                                │
│ - Portal checking for status updates                            │
│ - Status parsing and categorization                             │
│ - Timeline tracking                                             │
│                                                                 │
│ Status Categories:                                              │
│ - Submitted                                                     │
│ - Under Review                                                  │
│ - Interview Requested                                           │
│ - Interview Scheduled                                           │
│ - Rejected                                                      │
│ - Offer Extended                                                │
│ - Accepted/Declined                                             │
│                                                                 │
│ Status: 📋 Planned feature                                      │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5.2: INTERVIEW MANAGEMENT                                       │
│ User manages interview scheduling and preparation               │
│                                                                 │
│ Planned Features:                                               │
│ - Calendar integration                                          │
│ - Interview details storage (date, time, format)               │
│ - Interview preparation resources                               │
│ - Company research summaries                                    │
│ - Question bank and answers                                     │
│ - Thank-you note templates                                      │
│                                                                 │
│ Status: 📋 Feature requirements defined                         │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5.3: AUTOMATED FOLLOW-UP                                        │
│ System sends follow-up communications                           │
│                                                                 │
│ Planned Features:                                               │
│ - Thank-you email generation (AI-powered)                       │
│ - Status inquiry emails                                         │
│ - Interview confirmation messages                               │
│ - Customizable follow-up templates                              │
│ - Scheduled follow-up reminders                                 │
│                                                                 │
│ AI-Generated Content:                                           │
│ - Personalized thank-you notes                                  │
│ - Reference to interview specifics                              │
│ - Professional tone and formatting                              │
│ - Editable before sending                                       │
│                                                                 │
│ Status: 📋 Design phase                                         │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5.4: OUTCOME TRACKING                                           │
│ User records interview outcomes and feedback                    │
│                                                                 │
│ Planned Features:                                               │
│ - Interview notes and feedback                                  │
│ - Outcome recording (offer, rejection, next round)             │
│ - Offer details storage                                         │
│ - Negotiation tracking                                          │
│ - Final decision recording                                      │
│                                                                 │
│ Status: 📋 Data model in planning                               │
└─────────────────────────────────────────────────────────────────┘
```

#### Planned Dependencies (Step 5)

- **Email Integration:**
  - IMAP/POP3 for email monitoring
  - SMTP for outbound emails
  
- **Calendar Integration:**
  - Google Calendar API
  - Outlook/Exchange integration
  
- **AI Services:**
  - Natural language processing for email parsing
  - AI-generated follow-up content
  
- **Notification System:**
  - Drupal notification service
  - SMS integration (optional)

#### Planned Data Sources (Step 5)

- Email inbox (application responses)
- Company portal status pages
- Calendar entries
- User-entered interview data
- Company communication templates

#### Planned Integration Points (Step 5)

- Email servers (IMAP, SMTP)
- Calendar services (Google, Outlook)
- Company portals (status checking)
- SMS gateway (optional)
- AI content generation API

---

### Step 6: Analytics (Coming Soon)

**Status:** 📋 PLANNED

#### Overview
Step 6 will measure success rates, provide insights, and help users optimize their job search strategy.

#### Planned Process Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                   STEP 6: ANALYTICS                             │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6.1: APPLICATION METRICS                                        │
│ System tracks and analyzes application performance              │
│                                                                 │
│ Planned Metrics:                                                │
│ - Total applications submitted                                  │
│ - Response rate (applications with responses)                   │
│ - Interview rate (applications leading to interviews)          │
│ - Offer rate (applications resulting in offers)                │
│ - Time to response (average days)                               │
│ - Time to interview (average days)                              │
│ - Time to offer (average days)                                  │
│                                                                 │
│ Segmentation:                                                   │
│ - By company                                                    │
│ - By industry                                                   │
│ - By job title/role                                             │
│ - By application method (auto/manual)                           │
│ - By time period                                                │
│                                                                 │
│ Status: 📋 Metrics framework designed                           │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6.2: SUCCESS RATE ANALYSIS                                      │
│ System provides insights into what works                        │
│                                                                 │
│ Planned Analysis:                                               │
│ - Resume effectiveness by version/style                         │
│ - Best performing companies                                     │
│ - Optimal application timing                                    │
│ - Skills that drive interviews                                  │
│ - Keywords that improve match rates                             │
│ - Application volume vs. success correlation                    │
│                                                                 │
│ AI-Powered Insights:                                            │
│ - Pattern recognition in successful applications                │
│ - Recommendations for profile improvements                      │
│ - Suggested companies based on success patterns                 │
│ - Optimal job match thresholds                                  │
│                                                                 │
│ Status: 📋 Algorithm design in progress                         │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6.3: VISUALIZATION DASHBOARD                                    │
│ User views interactive analytics dashboard                      │
│                                                                 │
│ Planned Visualizations:                                         │
│ - Application funnel chart                                      │
│ - Timeline of application activity                              │
│ - Success rate trends over time                                 │
│ - Company comparison charts                                     │
│ - Skills gap heat map                                           │
│ - Response time distributions                                   │
│                                                                 │
│ Interactive Features:                                           │
│ - Date range filtering                                          │
│ - Company/industry drill-down                                   │
│ - Export to PDF/Excel                                           │
│ - Customizable dashboard widgets                                │
│                                                                 │
│ Status: 📋 UI/UX design phase                                   │
└─────────────────────────────────────────────────────────────────┘
                               │
                               ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6.4: OPTIMIZATION RECOMMENDATIONS                               │
│ System provides actionable recommendations                      │
│                                                                 │
│ Planned Recommendations:                                        │
│ - Profile improvements to increase match rate                   │
│ - Skills to add based on market demand                          │
│ - Companies with higher success rates                           │
│ - Optimal application frequency                                 │
│ - Resume tailoring improvements                                 │
│ - Follow-up timing optimization                                 │
│                                                                 │
│ Machine Learning:                                               │
│ - Learn from user's historical data                             │
│ - Compare against aggregated patterns                           │
│ - Personalized recommendations                                  │
│ - Continuous improvement over time                              │
│                                                                 │
│ Status: 📋 ML model architecture defined                        │
└─────────────────────────────────────────────────────────────────┘
```

#### Planned Dependencies (Step 6)

- **Data Analytics:**
  - Statistical analysis libraries
  - Data visualization libraries (D3.js, Chart.js)
  
- **Machine Learning:**
  - Scikit-learn or TensorFlow
  - Pattern recognition algorithms
  
- **Reporting:**
  - PDF generation for reports
  - Excel export capability
  
- **Database:**
  - Efficient query optimization
  - Aggregation and reporting tables

#### Planned Data Sources (Step 6)

- Application history data
- Job posting metadata
- Company performance data
- User profile information
- Timeline and outcome data
- Industry benchmarks (future)

#### Planned Integration Points (Step 6)

- Internal database (all tables)
- External analytics platforms (optional)
- Reporting services
- Email for automated reports
- Dashboard UI components

---

## Dependencies

### System Dependencies

#### Core Platform
- **Drupal 11** (PHP 8.3+)
  - Content management framework
  - Entity and field system
  - User management and permissions
  - Views for data display
  - Configuration management

- **MySQL 8.0+**
  - Primary data storage
  - Custom tables for job hunter data
  - Full-text search capabilities

- **Apache/Nginx**
  - Web server
  - HTTPS support required

- **Redis** (recommended)
  - Caching layer
  - Queue management
  - Session storage

#### PHP Libraries

**Currently Required:**
```json
{
  "phpoffice/phpword": "^1.0",
  "aws/aws-sdk-php": "^3.0",
  "guzzlehttp/guzzle": "^7.0"
}
```

**Planned Additions:**
```json
{
  "symfony/dom-crawler": "^6.0",
  "fabpot/goutte": "^4.0",
  "phpunit/phpunit": "^10.0",
  "behat/mink": "^1.10",
  "behat/mink-selenium2-driver": "^1.6"
}
```

#### External Services

**Currently Integrated:**
- **AWS Bedrock**
  - Claude 3.5 Sonnet model
  - Resume parsing and tailoring
  - Job description analysis
  - Region: us-west-2 (configurable)
  - Authentication: AWS credentials

**Planned Integrations:**
- **Email Services**
  - SMTP server for outbound emails
  - IMAP/POP3 for monitoring responses

- **Calendar Services**
  - Google Calendar API
  - Microsoft Outlook/Exchange

- **Browser Automation**
  - Selenium Grid
  - Headless Chrome/Firefox

- **Proxy Services** (optional)
  - Rotating proxies for web scraping
  - Rate limiting compliance

### Drupal Module Dependencies

**Required Core Modules:**
- Node
- User
- Field
- File
- Views
- Text
- Options
- Link
- Datetime

**Required Contrib Modules:**
- None currently, but consider for future:
  - Queue UI (admin interface for queues)
  - Admin Toolbar (improved admin UX)
  - Pathauto (URL management)

### Development Dependencies

**For Development Environment:**
- **Docker** (optional)
  - Containerized development environment
  
- **Drush**
  - Drupal command-line tool
  - Version 12+ for Drupal 11

- **Composer**
  - PHP dependency management
  - Version 2.0+

**For Testing:**
- PHPUnit (unit testing)
- Behat (behavioral testing)
- Selenium (browser automation testing)

### Infrastructure Dependencies

**Production Environment:**
- Cron for scheduled tasks
- SSL certificate (HTTPS required)
- Adequate disk space for file storage
- Sufficient memory for AI processing (4GB+ recommended)

**Queue Processing:**
- Dedicated queue workers (recommended)
- Cron jobs for background processing
- Monitoring and alerting

---

## Data Sources

### Internal Data Sources

#### Database Tables

**Job Seeker Profile Data:**
```sql
-- User consolidated profile
jobhunter_job_seeker
  - id (primary key)
  - uid (user ID)
  - consolidated_profile_json (JSON)
  - profile_completeness (percentage)
  - created_at
  - updated_at

-- Resume file tracking
jobhunter_job_seeker_resumes
  - id (primary key)
  - uid (user ID)
  - file_id (Drupal file entity ID)
  - extracted_text (LONGTEXT)
  - status (extracted/parsed/consolidated)
  - created_at
  - updated_at

-- Parsed resume data
jobhunter_resume_parsed_data
  - id (primary key)
  - resume_id (foreign key)
  - parsed_data (JSON)
  - parsing_method (ai/manual)
  - created_at
```

**Company and Job Data:**
```sql
-- Target companies (stored as nodes)
company (content type)
  - nid (node ID)
  - title (company name)
  - field_website_url
  - field_career_page_url
  - field_application_portal_url
  - field_company_description
  - field_industry
  - field_company_size

-- Job postings (stored as nodes)
job_posting (content type)
  - nid (node ID)
  - title (job title)
  - field_company (entity reference)
  - field_job_description
  - field_requirements
  - field_location
  - field_salary_range
  - field_application_url
  - field_posting_date
  - field_scraped_date

-- Tailored resumes
jobhunter_tailored_resumes
  - id (primary key)
  - uid (user ID)
  - job_id (job posting node ID)
  - tailored_resume_json (JSON)
  - tailoring_status (pending/processing/completed/failed)
  - created_at
  - updated_at
```

**Application Tracking:**
```sql
-- Applications (planned, may use nodes)
application
  - id (primary key)
  - uid (user ID)
  - job_id (job posting node ID)
  - company_id (company node ID)
  - tailored_resume_id
  - submission_date
  - status (submitted/under_review/interview/rejected/offer)
  - confirmation_number
  - application_url

-- Application timeline
application_timeline (planned)
  - id (primary key)
  - application_id
  - status_change
  - notes
  - timestamp
```

**Credentials and Preferences:**
```sql
-- Company credentials (planned)
job_seeker_company_credentials
  - id (primary key)
  - uid (user ID)
  - company_id (company node ID)
  - portal_url
  - username
  - encrypted_password
  - mfa_method
  - security_questions (JSON)
  - created_at
  - updated_at

-- Job preferences (planned)
job_seeker_company_preferences
  - id (primary key)
  - uid (user ID)
  - company_id (company node ID)
  - keywords (JSON array)
  - preferred_titles (JSON array)
  - locations (JSON array)
  - employment_types (JSON array)
  - salary_min
  - salary_max
```

#### Drupal File System

**Resume Storage:**
- Location: `private://job_hunter/resumes/`
- File types: .docx, .pdf
- Managed files tracked in Drupal file entity table

**Generated Documents:**
- Location: `private://job_hunter/tailored/`
- File types: .pdf, .docx
- Tailored resumes and cover letters

### External Data Sources

#### Company Career Websites

**Web Scraping Sources:**
- Company career page HTML
- Job posting detail pages
- Application portal forms
- Job listing APIs (if available)

**Data Extracted:**
- Job title and ID
- Job description and requirements
- Location and remote options
- Salary information (if available)
- Posting/closing dates
- Application instructions

#### AWS Bedrock (AI Service)

**Input Data:**
- Resume text (extracted from .docx)
- Job description text
- Prompts and instructions

**Output Data:**
- Parsed resume JSON
- Tailored resume content
- Job requirement analysis
- Skills matching scores

#### Email Systems (Planned)

**Monitored Data:**
- Application confirmation emails
- Interview invitations
- Status update notifications
- Rejection/offer letters

**Extracted Information:**
- Confirmation numbers
- Interview dates/times
- Status changes
- Company contacts

---

## Integration Points

### Company Application Portals

#### Integration Overview
The system must integrate with various company application portals to submit applications automatically.

#### Common Portal Types

**Type 1: Career Page with Direct Apply**
- **Example:** Company website with job listings and apply buttons
- **Integration Method:** Web scraping + browser automation
- **Required Actions:**
  - Navigate to job posting
  - Click "Apply" button
  - Fill out application form
  - Upload resume and documents
  - Submit application

**Type 2: Third-Party ATS (Applicant Tracking Systems)**
- **Common Systems:** Workday, Taleo, Greenhouse, Lever, iCIMS
- **Integration Method:** Company-specific automation scripts
- **Required Actions:**
  - Create or login to candidate profile
  - Search for specific job
  - Complete multi-step application
  - Answer screening questions
  - Upload documents
  - Submit application

**Type 3: Email-Based Applications**
- **Example:** Apply by sending resume to jobs@company.com
- **Integration Method:** Email automation
- **Required Actions:**
  - Compose email with required information
  - Attach tailored resume
  - Send to specified address
  - Track sent status

**Type 4: API-Based Submissions**
- **Example:** Companies with public APIs for job applications
- **Integration Method:** REST API calls
- **Required Actions:**
  - Authenticate with API
  - POST application data
  - Upload documents via API
  - Receive confirmation

#### Integration Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                  APPLICATION SUBMISSION LAYER                    │
└─────────────────────────────────────────────────────────────────┘
                               │
                ┌──────────────┼──────────────┐
                ↓              ↓              ↓
        ┌──────────────┐ ┌───────────┐ ┌──────────────┐
        │   Browser    │ │   Email   │ │     API      │
        │  Automation  │ │  Sender   │ │   Client     │
        └──────────────┘ └───────────┘ └──────────────┘
                │              │              │
        ┌──────────────┐ ┌───────────┐ ┌──────────────┐
        │  Selenium/   │ │   SMTP    │ │   REST API   │
        │  Puppeteer   │ │  Service  │ │   Wrapper    │
        └──────────────┘ └───────────┘ └──────────────┘
                │              │              │
        ┌──────────────┐ ┌───────────┐ ┌──────────────┐
        │   Company    │ │  Company  │ │   Company    │
        │   Portal     │ │   Email   │ │     API      │
        └──────────────┘ └───────────┘ └──────────────┘
```

### AWS Bedrock Integration

**Current Status:** ✅ IMPLEMENTED

#### Connection Details
- **Service:** AWS Bedrock Runtime
- **Model:** Claude 3.5 Sonnet (anthropic.claude-3-5-sonnet-20240620-v1:0)
- **Region:** us-west-2 (configurable)
- **Authentication:** AWS credentials from environment

#### API Endpoints Used
- `POST /model/invoke` - Synchronous invocation
- Request format: JSON with prompt and parameters
- Response format: JSON with generated content

#### Integration Flow

```
Drupal Module → AWS SDK for PHP → AWS Bedrock → Claude Model
                                          ↓
                                   JSON Response
                                          ↓
                                   Parse & Store
```

#### Data Flow

**Input to AI:**
```json
{
  "modelId": "anthropic.claude-3-5-sonnet-20240620-v1:0",
  "contentType": "application/json",
  "body": {
    "anthropic_version": "bedrock-2023-05-31",
    "max_tokens": 4000,
    "messages": [
      {
        "role": "user",
        "content": "Extract resume data: [resume text]"
      }
    ]
  }
}
```

**Output from AI:**
```json
{
  "professional_summary": "...",
  "skills": ["skill1", "skill2"],
  "job_history": [...],
  "education_history": [...]
}
```

#### Error Handling
- Connection timeout: Fallback to mock data
- Authentication failure: Log error, notify admin
- Rate limiting: Queue and retry
- Invalid response: Validation and re-request

### Drupal Core Integration

**Integration Points:**

**Entity System:**
- Create and manage nodes (company, job_posting, application)
- User entity extensions
- File entity management

**Form System:**
- Standard node forms for content creation
- Custom forms for specialized workflows
- Form validation and submission hooks

**Views System:**
- Company listings
- Job discovery dashboard
- Application history
- Admin management interfaces

**Permission System:**
- Role-based access control
- Custom permissions for job hunter features

**Queue API:**
- Resume parsing queue
- Job tailoring queue
- Application submission queue

**File System:**
- Private file storage
- Managed file tracking
- File field handling

**Cache System:**
- Configuration caching
- Entity caching
- Custom cache bins

### Future Integration Points (Planned)

#### Email Integration
- **SMTP Server:** Outbound emails (confirmations, follow-ups)
- **IMAP/POP3:** Monitoring application responses
- **Email Parsing:** Extract status updates from emails

#### Calendar Integration
- **Google Calendar API:** Interview scheduling
- **Microsoft Graph API:** Outlook calendar
- **iCal:** Calendar export for users

#### Browser Automation
- **Selenium Grid:** Distributed automation
- **Headless Chrome:** Form filling and submission
- **Puppeteer:** JavaScript-heavy portals

#### Analytics and Monitoring
- **Application Metrics:** Track success rates
- **Error Monitoring:** Sentry or similar
- **Performance Monitoring:** New Relic or similar

---

## Security Considerations

### Credential Storage
- **Encryption:** AES-256 encryption for passwords
- **Key Management:** Secure key storage (environment variables)
- **Access Control:** Strict permissions on credential tables

### API Security
- **AWS Credentials:** Never commit to version control
- **Token Management:** Secure token storage and rotation
- **Rate Limiting:** Prevent abuse of external APIs

### Data Privacy
- **User Data:** Encrypted storage of sensitive information
- **File Security:** Private file system for resumes
- **Audit Logging:** Track all credential access

### Web Scraping Ethics
- **Robots.txt:** Respect robots.txt directives
- **Rate Limiting:** Throttle requests to avoid overload
- **User Agent:** Identify ourselves properly
- **Terms of Service:** Comply with website ToS

---

## Performance Considerations

### Caching Strategy
- Cache company data (career page URLs, etc.)
- Cache job posting data (reduce scraping frequency)
- Cache user profiles (reduce database queries)

### Queue Management
- Dedicated queue workers for AI processing
- Priority queuing for user-initiated actions
- Background processing for bulk operations

### Database Optimization
- Indexes on frequently queried fields
- JSON field indexing for searchable data
- Query optimization for large datasets

### AI Service Optimization
- Batch processing when possible
- Request deduplication
- Response caching for identical prompts

---

## Monitoring and Maintenance

### Health Checks
- AWS Bedrock connection status
- Queue processing status
- Database connectivity
- File system accessibility

### Error Monitoring
- Failed application submissions
- AI service failures
- Web scraping errors
- Authentication issues

### Success Metrics
- Resume parsing success rate
- Application submission success rate
- Job discovery accuracy
- User satisfaction scores

### Maintenance Tasks
- Update company scraper scripts when websites change
- Review and improve AI prompts
- Monitor API usage and costs
- Database maintenance and optimization

---

## Appendix

### Related Documentation
- [README.md](../README.md) - Module overview
- [ARCHITECTURE.md](ARCHITECTURE.md) - Technical architecture
- [PROCESS_FLOW.md](PROCESS_FLOW.md) - Detailed process flows
- [FAQ.md](FAQ.md) - Frequently asked questions

### Contact and Support
For questions about this documentation or the job hunter module:
- Review existing documentation
- Check module issue queue
- Contact development team

---

**Last Updated:** February 2026  
**Document Version:** 1.0  
**Module Version:** 1.0-dev

**Status Summary:**
- ✅ Step 1 (Resume Upload): Fully implemented
- 🔄 Step 2 (Target Companies): Partially implemented
- 📋 Steps 3-6: Planned for future development
