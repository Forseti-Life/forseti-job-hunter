# Job Tailoring System - Design Document

## Overview

This document outlines the architecture for the Job Tailoring feature that allows users to:
1. Paste a job description/requirement
2. Extract company and job details automatically
3. Generate a tailored resume matched to the job requirements

---

## Process Flow

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                           JOB TAILORING WORKFLOW                                 │
└─────────────────────────────────────────────────────────────────────────────────┘

┌──────────────┐     ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│   Step 1     │     │   Step 2     │     │   Step 3     │     │   Step 4     │
│  Paste Job   │────▶│   Extract    │────▶│   Review &   │────▶│  Generate    │
│ Description  │     │   Details    │     │    Match     │     │  Tailored    │
│              │     │  (AI Parse)  │     │   Keywords   │     │   Resume     │
└──────────────┘     └──────────────┘     └──────────────┘     └──────────────┘
       │                    │                    │                    │
       ▼                    ▼                    ▼                    ▼
  ┌─────────┐         ┌─────────┐         ┌─────────┐         ┌─────────┐
  │Raw Text │         │Extracted│         │  Match  │         │Tailored │
  │ Input   │         │  JSON   │         │ Report  │         │ Resume  │
  └─────────┘         └─────────┘         └─────────┘         └─────────┘
```

### Step 1: Paste Job Description
- User navigates to `/jobhunter/jobs/add`
- User pastes the full job posting (text from LinkedIn, Indeed, company site, etc.)
- Optional: Provide job URL for reference
- Submit initiates AI extraction

### Step 2: AI Extraction
- AI parses raw text to extract structured data:
  - Company name, industry, size
  - Job title, department
  - Location, remote options
  - Required skills (must-have)
  - Preferred skills (nice-to-have)
  - Experience requirements
  - Education requirements
  - Salary range (if mentioned)
  - Key responsibilities
  - Keywords/phrases used repeatedly
- Company is created/matched in database
- Job posting is saved with structured data

### Step 3: Review & Match Analysis
- System compares job requirements against user's `consolidated_profile_json`
- Generates match report:
  - **Strong Matches**: Skills/experience that directly align
  - **Partial Matches**: Related but not exact
  - **Gaps**: Requirements user may not meet
  - **Keywords to Emphasize**: Terms from job posting to weave in
- User can review and adjust matching before tailoring

### Step 4: Generate Tailored Resume
- AI generates a tailored resume that:
  - Reorders experience to highlight most relevant
  - Incorporates keywords from job description
  - Adjusts professional summary for this role
  - Emphasizes matching skills prominently
  - De-emphasizes irrelevant experience
- Tailored resume saved with link to job posting

---

## Storage Architecture

### Existing Tables (Already Implemented)

| Table | Purpose | Relevant Fields |
|-------|---------|-----------------|
| `jobhunter_companies` | Company records | id, name, website, industry, location |
| `jobhunter_job_requirements` | Job postings | id, company_id, job_title, job_description, requirements |
| `jobhunter_job_seeker` | User profiles | id, uid, consolidated_profile_json |

### New Table: `jobhunter_tailored_resumes`

```sql
CREATE TABLE jobhunter_tailored_resumes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_seeker_id INT UNSIGNED NOT NULL,          -- FK to jobhunter_job_seeker.id
  job_requirement_id INT UNSIGNED NOT NULL,      -- FK to jobhunter_job_requirements.id
  
  -- Tailored Resume Content
  tailored_resume_json LONGTEXT,                 -- Full tailored resume in JSON format
  tailored_resume_text LONGTEXT,                 -- Plain text version for copy/paste
  tailored_resume_html LONGTEXT,                 -- HTML formatted version
  
  -- Match Analysis
  match_analysis_json LONGTEXT,                  -- Skills match report
  match_score INT,                               -- Overall match percentage (0-100)
  
  -- Keywords extracted and used
  keywords_json LONGTEXT,                        -- {"emphasized": [...], "added": [...], "original_frequency": {...}}
  
  -- Status tracking
  status VARCHAR(32) DEFAULT 'draft',            -- draft, finalized, applied, rejected, interview, offer
  applied_date INT,                              -- Timestamp when applied
  
  -- Metadata
  version INT DEFAULT 1,                         -- For resume versioning
  notes TEXT,                                    -- User notes about this application
  created INT NOT NULL,
  changed INT NOT NULL,
  
  INDEX idx_job_seeker (job_seeker_id),
  INDEX idx_job_requirement (job_requirement_id),
  INDEX idx_status (status),
  UNIQUE KEY unique_job_resume (job_seeker_id, job_requirement_id, version)
);
```

### Enhanced: `jobhunter_job_requirements`

Add columns to existing table:

```sql
ALTER TABLE jobhunter_job_requirements ADD COLUMN (
  raw_posting_text LONGTEXT,                     -- Original pasted text
  extracted_json LONGTEXT,                       -- AI-extracted structured data
  skills_required_json LONGTEXT,                 -- {"must_have": [...], "nice_to_have": [...]}
  experience_requirements_json LONGTEXT,         -- {"years_min": 5, "years_preferred": 10, "types": [...]}
  education_requirements_json LONGTEXT,          -- {"degree": "Bachelor's", "fields": [...], "certifications": [...]}
  keywords_json LONGTEXT,                        -- Important keywords/phrases from posting
  posted_date INT,                               -- When job was posted (if known)
  deadline_date INT,                             -- Application deadline (if known)
  source_platform VARCHAR(100),                  -- linkedin, indeed, company_site, etc.
  ai_extraction_status VARCHAR(32) DEFAULT 'pending' -- pending, completed, failed
);
```

### Enhanced: `jobhunter_companies`

Add columns for richer company data:

```sql
ALTER TABLE jobhunter_companies ADD COLUMN (
  linkedin_url VARCHAR(512),
  glassdoor_url VARCHAR(512),
  company_size VARCHAR(50),                      -- startup, small, medium, large, enterprise
  employee_count VARCHAR(50),                    -- "50-200", "1000+", etc.
  founded_year INT,
  headquarters_location VARCHAR(255),
  company_description TEXT,
  culture_notes TEXT,                            -- Notes about company culture
  tech_stack_json LONGTEXT,                      -- Known technologies used
  hiring_contacts_json LONGTEXT                  -- Contact info for recruiters/hiring managers
);
```

---

## Entity Relationship Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        ENTITY RELATIONSHIPS                                  │
└─────────────────────────────────────────────────────────────────────────────┘

    ┌───────────────────┐
    │      users        │
    │ (Drupal core)     │
    └─────────┬─────────┘
              │ 1
              │
              ▼ 1
    ┌───────────────────┐         ┌───────────────────┐
    │ jobhunter_        │         │ job_hunter_       │
    │ job_seeker        │         │ companies         │
    │                   │         │                   │
    │ - id              │         │ - id              │
    │ - uid ────────────┼─────────│ - name            │
    │ - consolidated_   │         │ - website         │
    │   profile_json    │         │ - industry        │
    └─────────┬─────────┘         │ - location        │
              │ 1                 │ - company_size    │
              │                   │ - tech_stack_json │
              │                   └─────────┬─────────┘
              │                             │ 1
              │                             │
              │                             ▼ *
              │                   ┌───────────────────┐
              │                   │ job_hunter_       │
              │                   │ job_requirements  │
              │                   │                   │
              │                   │ - id              │
              │                   │ - company_id ─────┼──┘
              │                   │ - job_title       │
              │                   │ - raw_posting_text│
              │                   │ - extracted_json  │
              │                   │ - skills_required │
              │                   │ - keywords_json   │
              │                   └─────────┬─────────┘
              │                             │ 1
              │                             │
              ▼ *                           ▼ *
    ┌─────────────────────────────────────────────────┐
    │           jobhunter_tailored_resumes            │
    │                                                  │
    │ - id                                            │
    │ - job_seeker_id ────────────────────────────────┼── FK to jobhunter_job_seeker
    │ - job_requirement_id ───────────────────────────┼── FK to jobhunter_job_requirements
    │ - tailored_resume_json                          │
    │ - match_analysis_json                           │
    │ - match_score                                   │
    │ - status (draft/applied/interview/offer)        │
    │ - version                                       │
    └─────────────────────────────────────────────────┘
```

---

## JSON Schema Definitions

### extracted_json (Job Posting)

```json
{
  "company": {
    "name": "Acme Healthcare",
    "industry": "Healthcare Technology",
    "size": "enterprise",
    "location": "St. Louis, MO"
  },
  "position": {
    "title": "VP of Engineering",
    "department": "Technology",
    "reports_to": "CTO",
    "level": "Executive"
  },
  "location": {
    "city": "St. Louis",
    "state": "MO",
    "remote_options": "hybrid",
    "travel_required": "10-20%"
  },
  "compensation": {
    "salary_min": 180000,
    "salary_max": 250000,
    "bonus": "15-25%",
    "equity": true
  },
  "requirements": {
    "experience_years_min": 10,
    "experience_years_preferred": 15,
    "education": {
      "degree": "Bachelor's",
      "fields": ["Computer Science", "Engineering"],
      "advanced_preferred": true
    }
  }
}
```

### skills_required_json

```json
{
  "must_have": [
    {"skill": "Team Leadership", "years": 10},
    {"skill": "Healthcare Industry", "years": 5},
    {"skill": "Cloud Architecture", "years": 5},
    {"skill": "Agile/Scrum", "years": null}
  ],
  "nice_to_have": [
    {"skill": "AWS", "years": 3},
    {"skill": "HIPAA Compliance", "years": null},
    {"skill": "M&A Experience", "years": null}
  ],
  "certifications": [
    {"name": "PMP", "required": false},
    {"name": "AWS Solutions Architect", "required": false}
  ]
}
```

### keywords_json (Job Posting)

```json
{
  "high_frequency": [
    {"term": "digital transformation", "count": 5},
    {"term": "team leadership", "count": 4},
    {"term": "stakeholder management", "count": 3}
  ],
  "action_verbs": ["lead", "drive", "transform", "scale", "optimize"],
  "industry_terms": ["HIPAA", "EHR", "interoperability", "value-based care"],
  "culture_indicators": ["collaborative", "innovative", "fast-paced"]
}
```

### match_analysis_json (Tailored Resume)

```json
{
  "overall_score": 85,
  "breakdown": {
    "skills_match": 90,
    "experience_match": 85,
    "education_match": 80,
    "industry_match": 95
  },
  "strong_matches": [
    {
      "requirement": "Team Leadership 10+ years",
      "profile_match": "15 years leading engineering teams up to 50 people",
      "evidence": ["VP at Company A", "Director at Company B"]
    },
    {
      "requirement": "Healthcare Industry",
      "profile_match": "8 years in healthcare technology",
      "evidence": ["Centene Corporation", "Express Scripts"]
    }
  ],
  "partial_matches": [
    {
      "requirement": "AWS Solutions Architect",
      "profile_match": "Extensive AWS experience, no certification",
      "recommendation": "Consider highlighting AWS projects prominently"
    }
  ],
  "gaps": [
    {
      "requirement": "M&A Experience",
      "profile_match": null,
      "recommendation": "If you have any M&A-adjacent experience (integrations, acquisitions), highlight it"
    }
  ],
  "keywords_to_add": [
    {"term": "digital transformation", "suggested_placement": "executive summary"},
    {"term": "value-based care", "suggested_placement": "healthcare experience section"}
  ]
}
```

### tailored_resume_json

```json
{
  "meta": {
    "generated_for_job_id": 123,
    "generated_at": "2026-02-02T10:30:00Z",
    "version": 1
  },
  "contact_info": {
    "full_name": "Keith Aumiller",
    "headline": "VP of Engineering | Healthcare Technology Leader | Digital Transformation Expert",
    "email": "...",
    "phone": "...",
    "location": {"city": "St. Louis", "state": "MO"}
  },
  "executive_profile": "Technology executive with 15+ years leading digital transformation initiatives in healthcare...",
  "strategic_differentiators": [
    {
      "title": "Healthcare Technology Leadership",
      "description": "8 years driving innovation in HIPAA-compliant environments..."
    }
  ],
  "professional_experience": [
    {
      "company": "Centene Corporation",
      "title": "VP of Engineering",
      "tailoring_notes": "Emphasized healthcare, team size, digital transformation keywords",
      "original_bullets": 8,
      "tailored_bullets": 5,
      "emphasized_achievements": [...]
    }
  ],
  "skills_emphasized": ["Digital Transformation", "Team Leadership", "Healthcare", "AWS"],
  "skills_deemphasized": ["Legacy PHP", "Desktop Applications"]
}
```

---

## Route Structure

```
/jobhunter/jobs                    - List all saved job postings (Views)
/jobhunter/jobs/add                - Add new job posting (paste form)
/jobhunter/jobs/{job_id}           - View job posting details
/jobhunter/jobs/{job_id}/edit      - Edit job posting
/jobhunter/jobs/{job_id}/tailor    - Generate/view tailored resume for this job
/jobhunter/jobs/{job_id}/match     - View match analysis
/jobhunter/jobs/{job_id}/apply     - Mark as applied, add notes

/jobhunter/companies               - List all companies
/jobhunter/companies/{id}          - View company details with all related jobs

/jobhunter/tailored                - List all tailored resumes
/jobhunter/tailored/{id}           - View specific tailored resume
/jobhunter/tailored/{id}/export    - Export to PDF/Word/plain text

/jobhunter/applications            - Application tracker dashboard
```

---

## Form Design: Add Job Posting

### `/jobhunter/jobs/add`

```
┌─────────────────────────────────────────────────────────────────┐
│                    Add Job Posting                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Job URL (optional):                                            │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ https://linkedin.com/jobs/view/123456789                   │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  Paste Job Description: *                                        │
│  ┌────────────────────────────────────────────────────────────┐ │
│  │ VP of Engineering                                          │ │
│  │ Acme Healthcare - St. Louis, MO (Hybrid)                   │ │
│  │                                                            │ │
│  │ About the Role:                                            │ │
│  │ We are seeking a VP of Engineering to lead our...          │ │
│  │                                                            │ │
│  │ Requirements:                                              │ │
│  │ - 10+ years of engineering leadership...                   │ │
│  │ - Healthcare industry experience preferred...              │ │
│  │                                                            │ │
│  │ [Large textarea - 20+ rows]                                │ │
│  └────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  Source Platform:                                                │
│  ○ LinkedIn  ○ Indeed  ○ Company Website  ○ Other              │
│                                                                  │
│  ┌──────────────────────────────┐                               │
│  │  Extract & Analyze           │                               │
│  └──────────────────────────────┘                               │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Post-Extraction Review Page

```
┌─────────────────────────────────────────────────────────────────┐
│              Review Extracted Job Details                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────────────┐  ┌────────────────────────────────────┐│
│  │ COMPANY             │  │ POSITION                           ││
│  │ ─────────────────── │  │ ────────────────────────────────── ││
│  │ Name: Acme Health   │  │ Title: VP of Engineering           ││
│  │ Industry: Healthcare│  │ Department: Technology             ││
│  │ Size: Enterprise    │  │ Reports To: CTO                    ││
│  │ Location: St. Louis │  │ Remote: Hybrid                     ││
│  │ [Edit]              │  │ [Edit]                             ││
│  └─────────────────────┘  └────────────────────────────────────┘│
│                                                                  │
│  ┌──────────────────────────────────────────────────────────────┐
│  │ REQUIRED SKILLS                           YOUR MATCH         │
│  │ ──────────────────────────────────────────────────────────── │
│  │ ✓ Team Leadership (10+ years)             15 years - STRONG  │
│  │ ✓ Healthcare Industry (5+ years)          8 years - STRONG   │
│  │ ○ AWS Solutions Architect                 Experience, no cert│
│  │ ✗ M&A Experience                          Not found          │
│  └──────────────────────────────────────────────────────────────┘
│                                                                  │
│  ┌──────────────────────────────────────────────────────────────┐
│  │ KEYWORDS TO INCORPORATE                                      │
│  │ ──────────────────────────────────────────────────────────── │
│  │ [digital transformation] [stakeholder management]            │
│  │ [value-based care] [HIPAA] [interoperability]               │
│  └──────────────────────────────────────────────────────────────┘
│                                                                  │
│  Match Score: ████████████████████░░░ 85%                       │
│                                                                  │
│  ┌─────────────────────┐  ┌─────────────────────┐               │
│  │  Save Job Posting   │  │  Generate Tailored  │               │
│  │                     │  │  Resume             │               │
│  └─────────────────────┘  └─────────────────────┘               │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Implementation Phases

### Phase 1: Database & Basic Forms (MVP)
1. Add new columns to `jobhunter_job_requirements`
2. Add new columns to `jobhunter_companies`  
3. Create `jobhunter_tailored_resumes` table
4. Create Add Job Posting form with basic text input
5. Create job listing page (Views)

### Phase 2: AI Extraction
1. Implement AI extraction endpoint (similar to resume parsing)
2. Parse raw job posting text to extract structured data
3. Auto-match/create company records
4. Extract keywords and requirements

### Phase 3: Match Analysis
1. Build comparison engine (profile JSON vs job requirements JSON)
2. Calculate match scores
3. Generate gap analysis
4. Identify keywords to emphasize

### Phase 4: Resume Tailoring
1. AI prompt engineering for resume tailoring
2. Generate tailored versions (JSON, text, HTML)
3. Diff view showing original vs tailored
4. User editing capabilities

### Phase 5: Application Tracking
1. Status workflow (saved → applied → interviewing → offer/rejected)
2. Timeline/activity log
3. Reminder system for follow-ups
4. Export capabilities (PDF/Word)

---

## AI Prompts (Outline)

### Job Parsing Prompt
```
Analyze this job posting and extract structured information...
- Company details
- Position requirements
- Skills (must-have vs nice-to-have)
- Keywords and phrases used multiple times
- Experience and education requirements
Return as JSON matching our schema.
```

### Match Analysis Prompt
```
Compare this job seeker profile against this job posting...
- Identify strong matches with evidence
- Identify partial matches with recommendations
- Identify gaps with suggestions
- Extract keywords the candidate should incorporate
Return match analysis JSON with scores.
```

### Resume Tailoring Prompt
```
Create a tailored resume for this candidate targeting this specific job...
- Prioritize relevant experience
- Incorporate keywords naturally
- Adjust professional summary for this role
- Maintain truthfulness - only reframe, don't fabricate
- De-emphasize irrelevant experience (don't remove)
Return tailored resume JSON.
```

---

## File Structure

```
job_hunter/
├── src/
│   ├── Controller/
│   │   ├── JobPostingController.php       # Job CRUD operations
│   │   └── TailoredResumeController.php   # Tailoring operations
│   ├── Form/
│   │   ├── AddJobPostingForm.php          # Paste job description
│   │   └── ReviewExtractionForm.php       # Review/edit extracted data
│   ├── Service/
│   │   ├── JobExtractionService.php       # AI job parsing
│   │   ├── MatchAnalysisService.php       # Profile vs job matching
│   │   ├── ResumeTailoringService.php     # Generate tailored resumes
│   │   └── CompanyMatchService.php        # Find/create company records
│   └── Entity/
│       └── TailoredResume.php             # (optional) Entity class
├── templates/
│   ├── job-posting-view.html.twig
│   ├── match-analysis.html.twig
│   └── tailored-resume-view.html.twig
└── config/
    └── views/ (Views exports for listings)
```

---

## Next Steps

1. **Approve this design** - Review and confirm the architecture
2. **Run database migrations** - Add new columns and table
3. **Create AddJobPostingForm** - Basic form to paste job descriptions
4. **Implement JobExtractionService** - AI parsing for job postings
5. **Build match analysis** - Compare profile to job requirements
6. **Generate tailored resumes** - AI-powered resume customization

---

*Document created: February 2, 2026*
*Module: job_hunter*
*Version: 1.0*
