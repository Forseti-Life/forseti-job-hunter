# Job Application Automation - Process Flows

## Table of Contents
- [Overview](#overview)
- [System Architecture Flows](#system-architecture-flows)
- [User Workflows](#user-workflows)
- [Administrative Workflows](#administrative-workflows)
- [Technical Process Flows](#technical-process-flows)
- [Error Handling Flows](#error-handling-flows)

---

## Overview

This document describes the complete process flows for the Job Application Automation module. Each flow is documented with sequence diagrams, decision points, and technical implementation details.

**Flow Status Legend:**
- ✅ **IMPLEMENTED** - Flow is fully functional
- 🔄 **PARTIAL** - Flow has some components implemented
- 📋 **PLANNED** - Flow designed but not yet implemented

---

## System Architecture Flows

### Module Initialization Flow ✅ IMPLEMENTED

```
┌─────────────────────────────────────────────────────────────┐
│ Module Installation (drush pm:enable)                       │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ job_hunter_install()                         │
│ - Create custom job_seeker database table                   │
│ - Create content types (company, job_posting, etc.)         │
│ - Create vocabularies (application_status, job_type, etc.)  │
│ - Import default configuration                              │
│ - Set default permissions                                   │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Configuration Import                                         │
│ - Load config/install/*.yml files                           │
│ - Create field definitions for content types                │
│ - Set default values (AI settings, etc.)                    │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Service Registration                                         │
│ - Register JobSeekerService (database, current_user)        │
│ - Register ResumeTailoringService (logger)                  │
│ - Register UserProfileService                               │
│ - Register AbbVieJobScrapingService (http, logger, config)  │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Cache Rebuild (drush cr)                                    │
│ - Clear all caches                                          │
│ - Rebuild routes, services, schema                          │
│ - Module ready for use                                      │
└─────────────────────────────────────────────────────────────┘
```

### Configuration Flow ✅ IMPLEMENTED

```
┌─────────────────────────────────────────────────────────────┐
│ Admin: Navigate to /admin/config/job-application/settings   │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ SettingsForm::buildForm()                                   │
│ - Load current configuration                                │
│ - Display Original Resume autocomplete                      │
│ - Show AI settings fields                                   │
│ - Display automatic tailoring toggle                        │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Admin: Select Original Resume Node                          │
│ - Use entity autocomplete to search resumes                 │
│ - Select master resume node                                 │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Admin: Configure AI Settings (Optional)                     │
│ - Set AWS region (default: us-west-2)                       │
│ - Set model ID (default: claude-3-5-sonnet)                 │
│ - Set max tokens (default: 4000)                            │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ SettingsForm::submitForm()                                  │
│ - Validate resume node exists                               │
│ - Save to job_hunter.settings config        │
│ - Store original_resume_node_id                             │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Configuration Saved                                          │
│ - Display success message                                   │
│ - Config exported to config/sync (if enabled)               │
│ - System ready for automatic tailoring                      │
└─────────────────────────────────────────────────────────────┘
```

---

## User Workflows

### Job Posting Creation & Automatic Tailoring ✅ IMPLEMENTED

```
┌─────────────────────────────────────────────────────────────┐
│ User: Navigate to /node/add/job_posting                     │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Fill Job Posting Form                                        │
│ - Enter job title (field_job_title)                         │
│ - Select/create company (field_company_ref)                 │
│ - Paste job description (field_job_description)             │
│ - Add application link (field_application_link)             │
│ - Set job type, location, etc.                              │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ User: Click "Save"                                           │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Drupal: Node Save Triggered                                 │
│ - Validate required fields                                  │
│ - Create job_posting node                                   │
│ - Trigger hook_entity_insert()                              │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ hook_entity_insert()                                         │
│ - Check: Is this a job_posting node? → YES                  │
│ - Load config: job_hunter.settings          │
│ - Check: Is automatic tailoring enabled? → YES              │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Load Original Resume                                         │
│ 1. Try: Load from config (original_resume_node_id)          │
│ 2. Fallback: Search for title "Original Resume"             │
│ 3. Check: Resume found? → Decision Point                    │
└─────────────────────────────────────────────────────────────┘
                           │
                ┌──────────┴──────────┐
                │                     │
            YES │                     │ NO
                ↓                     ↓
┌─────────────────────────┐  ┌──────────────────────────┐
│ Resume Found            │  │ Log Warning              │
│ - Extract job fields    │  │ "Original Resume not     │
│ - Get resume text       │  │  found. Configure at     │
│ - Prepare for AI        │  │  /admin/config..."       │
└─────────────────────────┘  │ - Skip tailoring         │
                │            │ - Job saved without      │
                │            │   tailored resume        │
                │            └──────────────────────────┘
                ↓
┌─────────────────────────────────────────────────────────────┐
│ Call ResumeTailoringService::generateTailoredResume()       │
│ - Pass: resume_text, job_title, company, job_description   │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ AI Processing (see AI Flow below)                           │
│ - Build prompt with context                                 │
│ - Call AWS Bedrock Claude API                               │
│ - Receive tailored resume text                              │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Save Tailored Resume                                         │
│ - Set field_tailored_resume with AI response                │
│ - Re-save job_posting node                                  │
│ - Log success with resume node ID                           │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ User: View Job Posting                                       │
│ - See job details                                           │
│ - See tailored resume in field_tailored_resume              │
│ - Ready to apply                                            │
└─────────────────────────────────────────────────────────────┘
```

### Manual Resume Tailoring 🔄 PARTIAL

```
┌─────────────────────────────────────────────────────────────┐
│ User: Navigate to /user/{uid}/tailor-resume/{job_nid}      │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ UserProfileController::tailorResume()                       │
│ - Load job posting node                                     │
│ - Load original resume                                      │
│ - Render tailor-resume.html.twig template                   │
│ - Display job details and current resume                    │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Page Loads                                                   │
│ - Show job title, company, description                      │
│ - Show current resume content                               │
│ - Display "Start AI Tailoring" button                       │
│ - Load tailor-resume.js JavaScript                          │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ User: Click "Start AI Tailoring"                            │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ JavaScript: AJAX POST to /tailor-resume/ajax                │
│ - Send: job_nid, user_uid                                   │
│ - Show loading spinner                                      │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ UserProfileController::tailorResumeAjax()                   │
│ - Validate request                                          │
│ - Load job posting and resume                               │
│ - Call ResumeTailoringService                               │
│ - Return JSON response                                      │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ JavaScript: Handle Response                                  │
│ - Hide loading spinner                                      │
│ - Display tailored resume in #resume-content                │
│ - Show success message with link to view                    │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ User: Review Tailored Resume                                 │
│ - Option: Click link to view full tailored_resume node      │
│ - Option: Edit if needed                                    │
│ - Option: Proceed with application                          │
└─────────────────────────────────────────────────────────────┘
```

### Company Management Workflow 📋 PLANNED

```
┌─────────────────────────────────────────────────────────────┐
│ User: Navigate to /node/add/company                         │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Fill Company Form                                            │
│ - Company name (title)                                      │
│ - Company website (field_company_website)                   │
│ - Careers page URL (field_careers_url)                      │
│ - Scraping enabled (field_scraping_enabled)                 │
│ - Scraping configuration (field_scraping_config)            │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Save Company Node                                            │
│ - Node created in database                                  │
│ - Available for job posting references                      │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Admin: View Company List                                     │
│ - Navigate to company management view                       │
│ - See all companies with scraping status                    │
│ - Bulk operations available                                 │
└─────────────────────────────────────────────────────────────┘
```

---

## Administrative Workflows

### Error Queue Management 📋 PLANNED

```
┌─────────────────────────────────────────────────────────────┐
│ Automation Process Failure                                   │
│ - Scraping error, submission failure, etc.                  │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Create Issue Node                                            │
│ - Type: issue                                               │
│ - Severity: (error, warning, info)                          │
│ - Error message and stack trace                             │
│ - Related entities (job, company, user)                     │
│ - Status: open                                              │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Admin: View Issue Queue                                      │
│ - Navigate to issues view                                   │
│ - Filter by severity, status, date                          │
│ - Sort by priority                                          │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Admin: Review Issue                                          │
│ - Click issue to view details                               │
│ - See error context and related entities                    │
│ - Determine resolution strategy                             │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Admin: Take Action                                           │
│ Option A: Fix code/config and mark resolved                 │
│ Option B: Notify user for manual intervention               │
│ Option C: Retry automation process                          │
│ Option D: Mark as known issue / won't fix                   │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Update Issue Status                                          │
│ - Status: resolved, in_progress, or closed                  │
│ - Add resolution notes                                      │
│ - Link to related changes/commits if applicable             │
└─────────────────────────────────────────────────────────────┘
```

### User Profile Review 📋 PLANNED

```
┌─────────────────────────────────────────────────────────────┐
│ Admin: Navigate to /admin/people                            │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ View: Users with job_seeker Profile                         │
│ - Filter by profile completeness                            │
│ - View resume upload status                                 │
│ - See last activity dates                                   │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Admin: Select User to Review                                 │
│ - Click user to view full profile                           │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Review User Data                                             │
│ - Resume file and extracted text                            │
│ - Skills, experience, education                             │
│ - Application history                                       │
│ - Success rate metrics                                      │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Admin Actions                                                │
│ Option A: Contact user for profile completion               │
│ Option B: Review automated applications                     │
│ Option C: Assist with failed applications                   │
│ Option D: Provide support / training                        │
└─────────────────────────────────────────────────────────────┘
```

---

## Technical Process Flows

### AI Resume Tailoring Service Flow ✅ IMPLEMENTED

```
┌─────────────────────────────────────────────────────────────┐
│ ResumeTailoringService::generateTailoredResume()            │
│ Input: resume_text, job_title, company, job_description    │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Build AI Prompt                                              │
│ - Call buildResumePrompt() with parameters                  │
│ - Construct detailed instructions for Claude                │
│ - Include context about job requirements                    │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Prompt Structure:                                            │
│                                                              │
│ "You are an expert resume writer. Tailor this resume        │
│  for the following job:                                     │
│                                                              │
│  Job Title: {job_title}                                     │
│  Company: {company}                                         │
│  Job Description: {job_description}                         │
│                                                              │
│  Original Resume:                                           │
│  {resume_text}                                              │
│                                                              │
│  Provide an optimized resume that highlights relevant        │
│  skills and experience. Maintain professional formatting."   │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Environment Check                                            │
│ Is production environment? (AWS_EXECUTION_ENV set)          │
└─────────────────────────────────────────────────────────────┘
                           │
                ┌──────────┴──────────┐
                │                     │
             YES│                     │NO (Development)
                ↓                     ↓
┌─────────────────────────┐  ┌──────────────────────────┐
│ AWS Bedrock Call        │  │ Return Mock Response     │
│                         │  │                          │
│ 1. Load AWS credentials │  │ "This is where the       │
│ 2. Create Bedrock       │  │  tailored resume would   │
│    Runtime client       │  │  be generated using AI." │
│ 3. Prepare InvokeModel  │  │                          │
│    request              │  │ (For testing without     │
│ 4. Set parameters:      │  │  AWS costs)              │
│    - modelId: claude    │  └──────────────────────────┘
│    - body: prompt JSON  │
│    - contentType        │
│ 5. Execute API call     │
└─────────────────────────┘
                │
                ↓
┌─────────────────────────────────────────────────────────────┐
│ AWS Bedrock Response                                         │
│ - Receive response body (JSON)                              │
│ - Extract content from Claude response                      │
│ - Parse text from response structure                        │
└─────────────────────────────────────────────────────────────┘
                │
                ↓
┌─────────────────────────────────────────────────────────────┐
│ Process AI Response                                          │
│ - Clean up response text                                    │
│ - Validate response is not empty                            │
│ - Log success with character count                          │
└─────────────────────────────────────────────────────────────┘
                │
                ↓
┌─────────────────────────────────────────────────────────────┐
│ Error Handling                                               │
│ Try/Catch wrapper around entire process:                    │
│ - Catch AWS exceptions                                      │
│ - Catch network errors                                      │
│ - Log detailed error messages                               │
│ - Return error message or empty string                      │
└─────────────────────────────────────────────────────────────┘
                │
                ↓
┌─────────────────────────────────────────────────────────────┐
│ Return Tailored Resume Text                                  │
│ - Success: Return AI-generated resume                       │
│ - Failure: Return error message or empty string             │
└─────────────────────────────────────────────────────────────┘
```

### Job Discovery & Scraping Flow 📋 PLANNED

```
┌─────────────────────────────────────────────────────────────┐
│ Cron Job Trigger                                             │
│ - Scheduled job discovery execution                         │
│ - Or manual trigger from admin UI                           │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Load Companies with Scraping Enabled                         │
│ - Query: company nodes where field_scraping_enabled = TRUE  │
│ - Load scraping configuration for each                      │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ For Each Company: Start Scraping                            │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Initialize Scraper                                           │
│ - Load company-specific scraper plugin                      │
│ - Get careers page URL                                      │
│ - Set up HTTP client with headers                           │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Fetch Careers Page                                           │
│ - HTTP GET request to careers URL                           │
│ - Parse HTML response                                       │
│ - Extract job listing links                                 │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ For Each Job Listing Link                                    │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Fetch Job Details Page                                       │
│ - Follow link to individual job posting                     │
│ - Parse job details HTML                                    │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Extract Job Information                                      │
│ - Job title (from H1, title tag, etc.)                      │
│ - Job description (main content area)                       │
│ - Application link/button URL                               │
│ - Location, job type, salary (if available)                │
│ - Posted date                                               │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Check for Duplicate                                          │
│ Query: job_posting nodes matching:                          │
│ - Same company reference                                    │
│ - Same job title                                            │
│ - Same application link                                     │
└─────────────────────────────────────────────────────────────┘
                           │
                ┌──────────┴──────────┐
                │                     │
         Exists │                     │ New Job
                ↓                     ↓
┌─────────────────────────┐  ┌──────────────────────────┐
│ Skip Creation           │  │ Create job_posting Node  │
│ - Job already exists    │  │ - Populate all fields    │
│ - Update last_seen date │  │ - Trigger automatic      │
│   if tracking           │  │   tailoring              │
└─────────────────────────┘  │ - Save to database       │
                             └──────────────────────────┘
                                        │
                                        ↓
                             ┌──────────────────────────┐
                             │ Automatic Resume         │
                             │ Tailoring Triggered      │
                             │ (See earlier flow)       │
                             └──────────────────────────┘
                                        │
                                        ↓
┌─────────────────────────────────────────────────────────────┐
│ Continue to Next Job Listing                                 │
│ - Process all jobs from this company                        │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Scraping Complete for Company                                │
│ - Log summary: X jobs found, Y new, Z duplicates            │
│ - Update company last_scraped timestamp                     │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Error Handling (If Scraping Fails)                          │
│ - Create issue node with error details                      │
│ - Log error to watchdog                                     │
│ - Optionally: Send admin notification                       │
│ - Continue with next company                                │
└─────────────────────────────────────────────────────────────┘
```

---

## Error Handling Flows

### General Error Handling Pattern ✅ IMPLEMENTED

```
┌─────────────────────────────────────────────────────────────┐
│ Any Module Operation                                         │
│ (Resume tailoring, scraping, submission, etc.)              │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Try Block                                                    │
│ - Execute primary operation                                 │
│ - Validate inputs                                           │
│ - Process business logic                                    │
└─────────────────────────────────────────────────────────────┘
                           │
                ┌──────────┴──────────┐
                │                     │
          Success│                     │Exception
                ↓                     ↓
┌─────────────────────────┐  ┌──────────────────────────┐
│ Return Success Result   │  │ Catch Block              │
│ - Log success message   │  │ - Capture exception      │
│ - Return data/status    │  │ - Extract error details  │
└─────────────────────────┘  └──────────────────────────┘
                                        │
                                        ↓
                             ┌──────────────────────────┐
                             │ Log Error                │
                             │ - Watchdog entry         │
                             │ - Channel: module name   │
                             │ - Severity: ERROR        │
                             │ - Include stack trace    │
                             └──────────────────────────┘
                                        │
                                        ↓
                             ┌──────────────────────────┐
                             │ User-Facing Error        │
                             │ - Display generic message│
                             │ - Don't expose internals │
                             │ - Suggest next steps     │
                             └──────────────────────────┘
                                        │
                                        ↓
                             ┌──────────────────────────┐
                             │ Create Issue Node?       │
                             │ (For critical errors)    │
                             │ - Automated process fail │
                             │ - Requires admin review  │
                             └──────────────────────────┘
```

### Resume Tailoring Error Flow ✅ IMPLEMENTED

```
┌─────────────────────────────────────────────────────────────┐
│ Tailoring Process Encounters Error                          │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Identify Error Type                                          │
└─────────────────────────────────────────────────────────────┘
                           │
          ┌────────────────┼────────────────┬────────────────┐
          │                │                │                │
          ↓                ↓                ↓                ↓
┌──────────────────┐ ┌─────────────┐ ┌──────────────┐ ┌────────────┐
│ Original Resume  │ │ AWS Bedrock │ │ Network      │ │ Data       │
│ Not Found        │ │ API Error   │ │ Timeout      │ │ Validation │
└──────────────────┘ └─────────────┘ └──────────────┘ └────────────┘
          │                │                │                │
          ↓                ↓                ↓                ↓
┌──────────────────┐ ┌─────────────┐ ┌──────────────┐ ┌────────────┐
│ Log Warning:     │ │ Log Error:  │ │ Log Error:   │ │ Log Error: │
│ "Configure       │ │ AWS API     │ │ Connection   │ │ Invalid    │
│ Original Resume  │ │ failed with │ │ timeout to   │ │ resume or  │
│ at /admin/..."   │ │ exception   │ │ AWS Bedrock  │ │ job data   │
│                  │ │ details     │ │ after Xs     │ │            │
│ Skip tailoring   │ │             │ │              │ │            │
└──────────────────┘ └─────────────┘ └──────────────┘ └────────────┘
          │                │                │                │
          └────────────────┼────────────────┼────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Save Job Posting Without Tailored Resume                     │
│ - field_tailored_resume remains empty                       │
│ - User can manually trigger tailoring later                 │
│ - Job posting still created/saved                           │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Admin Review (if critical)                                   │
│ - Check Recent Log Messages (/admin/reports/dblog)         │
│ - Filter by job_hunter channel             │
│ - Review error context and take corrective action          │
└─────────────────────────────────────────────────────────────┘
```

---

## Integration Points

### Drupal Core Integration

```
┌─────────────────────────────────────────────────────────────┐
│ job_hunter Module                            │
└─────────────────────────────────────────────────────────────┘
                           │
        ┌──────────────────┼──────────────────┬───────────────┐
        │                  │                  │               │
        ↓                  ↓                  ↓               ↓
┌──────────────┐  ┌─────────────┐  ┌──────────────┐  ┌──────────────┐
│ Node System  │  │ User/Profile│  │ Config Mgmt  │  │ Views Module │
│              │  │ System      │  │              │  │              │
│ - company    │  │ - job_seeker│  │ - Settings   │  │ - Admin      │
│ - job_posting│  │   profile   │  │   export     │  │   interfaces │
│ - application│  │ - Extended  │  │ - Field      │  │ - Lists      │
│ - issue      │  │   fields    │  │   config     │  │ - Filters    │
└──────────────┘  └─────────────┘  └──────────────┘  └──────────────┘
```

### External Service Integration

```
┌─────────────────────────────────────────────────────────────┐
│ ResumeTailoringService                                       │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ AWS SDK PHP                                                  │
│ - Bedrock Runtime Client                                    │
│ - Credential Provider Chain                                 │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ AWS Bedrock Service                                          │
│ - Region: us-west-2 (configurable)                          │
│ - Model: Claude 3.5 Sonnet                                  │
│ - API: InvokeModel                                          │
└─────────────────────────────────────────────────────────────┘
```

---

## Performance Considerations

### Caching Strategy

```
Content Caching:
- Node content: Standard Drupal cache
- Views displays: Views query cache + render cache
- Configuration: Config cache (persistent)

AI Responses:
- Not cached (each tailoring is unique)
- Consider caching for repeated job/resume combinations
```

### Async Processing (Future)

```
┌─────────────────────────────────────────────────────────────┐
│ Job Posting Created                                          │
│ Instead of: Immediate tailoring (blocks save)               │
│ Future: Queue job for background processing                 │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Queue API / Cron                                             │
│ - Add task to queue                                         │
│ - Process during cron runs                                  │
│ - Update node when complete                                 │
└─────────────────────────────────────────────────────────────┘
```

---

**Last Updated:** January 2026
**Module Version:** 1.0-dev
