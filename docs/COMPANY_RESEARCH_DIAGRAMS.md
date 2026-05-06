# Company Research Path - Visual Process Diagrams

**Document Version:** 1.0  
**Created:** February 13, 2026  
**Related:** COMPANY_RESEARCH_PATH_DESIGN.md

This document provides visual diagrams to complement the main design document.

---

## Table of Contents
1. [System Architecture Diagram](#system-architecture-diagram)
2. [Service Interaction Diagram](#service-interaction-diagram)
3. [Data Flow Diagram](#data-flow-diagram)
4. [Authentication Detection Flow](#authentication-detection-flow)
5. [ATS Detection Decision Tree](#ats-detection-decision-tree)
6. [State Machine Diagrams](#state-machine-diagrams)

---

## System Architecture Diagram

### Component Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          USER INTERFACE LAYER                                │
│  ┌─────────────────────┐  ┌──────────────────────┐  ┌─────────────────────┐ │
│  │ Company Research    │  │ Batch Processing     │  │ Results Display     │ │
│  │ Form                │  │ Dashboard            │  │ Page                │ │
│  └─────────────────────┘  └──────────────────────┘  └─────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ↓
┌─────────────────────────────────────────────────────────────────────────────┐
│                          CONTROLLER LAYER                                    │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │         CompanyResearchServiceController                                ││
│  │  • researchCompany()                                                    ││
│  │  • displayResearchResults()                                             ││
│  │  • refreshResearch()                                                    ││
│  │  • batchResearch()                                                      ││
│  └─────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ↓
┌─────────────────────────────────────────────────────────────────────────────┐
│                       ORCHESTRATION LAYER                                    │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │         CompanyResearchService (Main Orchestrator)                      ││
│  │  • executeResearch()                                                    ││
│  │  • getCachedResearch()                                                  ││
│  │  • storeResearchResults()                                               ││
│  └─────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                 ┌──────────────────────┼──────────────────────┐
                 │                      │                      │
                 ↓                      ↓                      ↓
┌──────────────────────────────────────────────────────────────────────────────┐
│                          SERVICE LAYER                                        │
│  ┌─────────────────┐  ┌──────────────────┐  ┌─────────────────────────────┐ │
│  │ Company         │  │ CareersPage      │  │ ATS                         │ │
│  │ Discovery       │  │ Discovery        │  │ Detection                   │ │
│  │ Service         │  │ Service          │  │ Service                     │ │
│  └─────────────────┘  └──────────────────┘  └─────────────────────────────┘ │
│                                                                               │
│  ┌─────────────────┐  ┌──────────────────────────────────────────────────┐  │
│  │ API             │  │ Authentication                                   │  │
│  │ Discovery       │  │ Analysis                                         │  │
│  │ Service         │  │ Service                                          │  │
│  └─────────────────┘  └──────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────────┘
                                        │
                 ┌──────────────────────┼──────────────────────┐
                 │                      │                      │
                 ↓                      ↓                      ↓
┌──────────────────────────────────────────────────────────────────────────────┐
│                          INTEGRATION LAYER                                    │
│  ┌─────────────────┐  ┌──────────────────┐  ┌─────────────────────────────┐ │
│  │ HTTP Client     │  │ HTML Parser      │  │ Headless Browser            │ │
│  │ (Guzzle)        │  │ (DomCrawler)     │  │ (Puppeteer/Playwright)      │ │
│  └─────────────────┘  └──────────────────┘  └─────────────────────────────┘ │
│                                                                               │
│  ┌─────────────────┐  ┌──────────────────┐                                  │
│  │ Google Search   │  │ LinkedIn API     │                                  │
│  │ API             │  │ (Optional)       │                                  │
│  └─────────────────┘  └──────────────────┘                                  │
└──────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ↓
┌──────────────────────────────────────────────────────────────────────────────┐
│                          DATA LAYER                                           │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │ jobhunter_company_research                                              │ │
│  │ • Research results storage                                              │ │
│  │ • Cache management                                                      │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
│                                                                               │
│  ┌─────────────────────────────────────────────────────────────────────────┐ │
│  │ jobhunter_ats_platforms                                                 │ │
│  │ • ATS platform reference data                                           │ │
│  │ • Detection patterns                                                    │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────────────┘
```

---

## Service Interaction Diagram

### Complete Research Flow with Service Interactions

```
User Request: "Research Acme Corporation"
│
├─► CompanyResearchServiceController::researchCompany("Acme Corporation")
    │
    └─► CompanyResearchService::executeResearch("Acme Corporation")
        │
        ├─► Step 1: Check Cache
        │   └─► Database Query: SELECT FROM jobhunter_company_research
        │       └─► Result: [Cache Miss] → Continue
        │
        ├─► Step 2: CompanyDiscoveryService::discoverCompanyWebsite("Acme Corporation")
        │   │
        │   ├─► searchViaGoogle("Acme Corporation official website")
        │   │   └─► Google Custom Search API
        │   │       └─► Result: "acme.com"
        │   │
        │   ├─► validateDomain("acme.com")
        │   │   └─► HTTP Client: HEAD request
        │   │       └─► Result: ✓ Valid (200 OK)
        │   │
        │   └─► Result: { domain: "acme.com", valid: true }
        │
        ├─► Step 3: CareersPageDiscoveryService::discoverCareersPages("acme.com")
        │   │
        │   ├─► checkCommonPatterns("acme.com")
        │   │   ├─► HTTP Client: GET acme.com/careers
        │   │   │   └─► Result: ✓ Found (200 OK)
        │   │   ├─► HTTP Client: GET acme.com/jobs
        │   │   │   └─► Result: ✗ Not Found (404)
        │   │   └─► Result: ["https://acme.com/careers"]
        │   │
        │   ├─► checkSubdomains("acme.com")
        │   │   ├─► HTTP Client: GET careers.acme.com
        │   │   │   └─► Result: ✓ Found (redirect to acme.workday.com)
        │   │   └─► Result: ["https://acme.workday.com/careers"]
        │   │
        │   ├─► followRedirects("https://acme.com/careers")
        │   │   └─► Result: Redirects to "https://acme.workday.com/careers"
        │   │
        │   └─► Result: [
        │           { url: "https://acme.workday.com/careers", type: "ats_hosted" }
        │       ]
        │
        ├─► Step 4: ATSDetectionService::detectATSPlatform("https://acme.workday.com/careers")
        │   │
        │   ├─► detectViaDomain("https://acme.workday.com/careers")
        │   │   └─► Pattern Match: "workday.com"
        │   │       └─► Result: { platform: "Workday", confidence: "high" }
        │   │
        │   ├─► detectViaHTMLPatterns(html_content)
        │   │   └─► HTML Parser: Look for Workday CSS classes
        │   │       └─► Result: { platform: "Workday", confidence: "high" }
        │   │
        │   ├─► detectViaJavaScript(html_content)
        │   │   └─► Script Analysis: Found "workday.js"
        │   │       └─► Result: { platform: "Workday", confidence: "high" }
        │   │
        │   └─► Result: {
        │           platform: "Workday",
        │           version: "2024.1",
        │           confidence: "high",
        │           detection_method: "multiple"
        │       }
        │
        ├─► Step 5: APIDiscoveryService::discoverAPIEndpoints("https://acme.workday.com", "Workday")
        │   │
        │   ├─► analyzeNetworkTraffic("https://acme.workday.com/careers")
        │   │   └─► Headless Browser: Load page and capture XHR requests
        │   │       └─► Result: [
        │               "https://acme.workday.com/api/v1/jobs",
        │               "https://acme.workday.com/api/v1/locations"
        │           ]
        │   │
        │   ├─► checkCommonAPIPatterns("https://acme.workday.com", "Workday")
        │   │   └─► HTTP Client: Test common Workday API patterns
        │   │       └─► Result: [
        │               "https://acme.workday.com/api/v1/jobs",
        │               "https://acme.workday.com/api/v1/applications"
        │           ]
        │   │
        │   └─► Result: {
        │           base_url: "https://acme.workday.com/api/v1",
        │           endpoints: [
        │               { path: "/jobs", method: "GET" },
        │               { path: "/applications", method: "POST" }
        │           ]
        │       }
        │
        ├─► Step 6: AuthenticationAnalysisService::analyzeAuthentication("https://acme.workday.com", "Workday")
        │   │
        │   ├─► analyzeRegistrationFlow("https://acme.workday.com/register")
        │   │   └─► HTML Parser: Analyze registration form
        │   │       └─► Result: {
        │               endpoint: "/api/v1/users",
        │               required_fields: ["email", "password", "first_name", "last_name"],
        │               password_requirements: { min_length: 8, ... }
        │           }
        │   │
        │   ├─► detectSSOProviders(html_content)
        │   │   └─► HTML Parser: Look for SSO buttons
        │   │       └─► Result: ["google", "linkedin"]
        │   │
        │   ├─► analyzeVerificationRequirements(html_content)
        │   │   └─► Form Analysis: Check verification steps
        │   │       └─► Result: {
        │               email_verification: { required: true, method: "link" },
        │               phone_verification: { required: false }
        │           }
        │   │
        │   ├─► analyzeLoginProcess("https://acme.workday.com/login")
        │   │   └─► Form Analysis: Analyze login form
        │   │       └─► Result: {
        │               endpoint: "/api/v1/auth/login",
        │               session_type: "jwt_token"
        │           }
        │   │
        │   ├─► detectCaptcha(html_content)
        │   │   └─► Script Analysis: Look for CAPTCHA
        │   │       └─► Result: {
        │               type: "recaptcha_v3",
        │               site_key: "6LeIxAcT..."
        │           }
        │   │
        │   └─► Result: { <complete auth analysis> }
        │
        ├─► Step 7: Calculate Automation Readiness
        │   └─► analyzeBlockers()
        │       └─► Result: {
        │               level: "partial",
        │               blockers: ["recaptcha_required", "email_verification_required"],
        │               ready_features: ["job_listing_retrieval", "api_access"]
        │           }
        │
        ├─► Step 8: CompanyResearchService::storeResearchResults(company_id, results)
        │   └─► Database Insert/Update: jobhunter_company_research
        │       └─► Result: ✓ Stored
        │
        └─► Return: Complete Research Results Object
            └─► CompanyResearchServiceController::displayResearchResults()
                └─► Render: Research Results Page
```

---

## Data Flow Diagram

### Data Transformation Through the System

```
INPUT: Company Name String
   "Acme Corporation"
         │
         ↓
┌────────────────────────────┐
│ Normalization              │
│ • Trim whitespace          │
│ • Lowercase                │
│ • Remove special chars     │
└────────────────────────────┘
         │
         ↓
   "acme corporation"
         │
         ↓
┌────────────────────────────┐
│ Company Discovery          │
│ Input: "acme corporation"  │
│ Output: Domain info        │
└────────────────────────────┘
         │
         ↓
{
  company_name: "Acme Corporation",
  domain: "acme.com",
  valid: true,
  ssl_valid: true
}
         │
         ↓
┌────────────────────────────┐
│ Careers Page Discovery     │
│ Input: Domain info         │
│ Output: Careers URLs       │
└────────────────────────────┘
         │
         ↓
{
  company_name: "Acme Corporation",
  domain: "acme.com",
  careers_pages: [
    {
      url: "https://acme.workday.com/careers",
      type: "ats_hosted",
      accessible: true
    }
  ]
}
         │
         ↓
┌────────────────────────────┐
│ ATS Detection              │
│ Input: Careers URLs        │
│ Output: ATS info           │
└────────────────────────────┘
         │
         ↓
{
  company_name: "Acme Corporation",
  domain: "acme.com",
  careers_pages: [...],
  ats_detection: {
    platform: "Workday",
    version: "2024.1",
    confidence: "high"
  }
}
         │
         ↓
┌────────────────────────────┐
│ API Discovery              │
│ Input: ATS info + URLs     │
│ Output: API endpoints      │
└────────────────────────────┘
         │
         ↓
{
  company_name: "Acme Corporation",
  domain: "acme.com",
  careers_pages: [...],
  ats_detection: {...},
  api_endpoints: {
    base_url: "https://acme.workday.com/api/v1",
    endpoints: [...]
  }
}
         │
         ↓
┌────────────────────────────┐
│ Authentication Analysis    │
│ Input: URLs + ATS info     │
│ Output: Auth requirements  │
└────────────────────────────┘
         │
         ↓
{
  company_name: "Acme Corporation",
  domain: "acme.com",
  careers_pages: [...],
  ats_detection: {...},
  api_endpoints: {...},
  authentication: {
    methods: [...],
    registration: {...},
    verification: {...},
    login: {...},
    bot_prevention: {...}
  }
}
         │
         ↓
┌────────────────────────────┐
│ Readiness Calculation      │
│ Input: Complete data       │
│ Output: Readiness score    │
└────────────────────────────┘
         │
         ↓
{
  company_name: "Acme Corporation",
  domain: "acme.com",
  careers_pages: [...],
  ats_detection: {...},
  api_endpoints: {...},
  authentication: {...},
  automation_readiness: {
    level: "partial",
    ready_features: [...],
    blocked_features: [...],
    blockers: [...],
    recommendations: [...]
  }
}
         │
         ↓
┌────────────────────────────┐
│ Database Storage           │
│ Table: company_research    │
│ Cache: 30 days             │
└────────────────────────────┘
         │
         ↓
OUTPUT: Complete Research Results
```

---

## Authentication Detection Flow

### Authentication Method Detection Process

```
Start: Load Login/Registration Page
│
├─► Analyze Page HTML
│   │
│   ├─► Search for Form Elements
│   │   │
│   │   ├─► Found <form> with email + password?
│   │   │   ├─► YES → Detect: EMAIL_PASSWORD
│   │   │   └─► NO → Continue
│   │   │
│   │   ├─► Found "Sign in with Google" button?
│   │   │   ├─► YES → Detect: GOOGLE_OAUTH
│   │   │   └─► NO → Continue
│   │   │
│   │   ├─► Found "Sign in with LinkedIn" button?
│   │   │   ├─► YES → Detect: LINKEDIN_OAUTH
│   │   │   └─► NO → Continue
│   │   │
│   │   ├─► Found "Sign in with Microsoft" button?
│   │   │   ├─► YES → Detect: MICROSOFT_OAUTH
│   │   │   └─► NO → Continue
│   │   │
│   │   └─► Found SAML metadata?
│   │       ├─► YES → Detect: SAML_SSO
│   │       └─► NO → Continue
│   │
│   ├─► Analyze Scripts
│   │   │
│   │   ├─► Found recaptcha/api.js?
│   │   │   ├─► YES → Check for data-sitekey
│   │   │   │   ├─► render= parameter?
│   │   │   │   │   ├─► YES → Detect: RECAPTCHA_V3
│   │   │   │   │   └─► NO → Detect: RECAPTCHA_V2
│   │   │   └─► NO → Continue
│   │   │
│   │   ├─► Found hcaptcha.com/api.js?
│   │   │   ├─► YES → Detect: HCAPTCHA
│   │   │   └─► NO → Continue
│   │   │
│   │   └─► Found custom captcha?
│   │       ├─► YES → Detect: CUSTOM_CAPTCHA
│   │       └─► NO → No CAPTCHA detected
│   │
│   └─► Analyze Registration Flow
│       │
│       ├─► Simulate Form Submission
│       │   └─► Capture Response
│       │       │
│       │       ├─► Response contains "verify email"?
│       │       │   ├─► YES → Detect: EMAIL_VERIFICATION_REQUIRED
│       │       │   └─► NO → Continue
│       │       │
│       │       ├─► Response contains "verify phone"?
│       │       │   ├─► YES → Detect: PHONE_VERIFICATION_REQUIRED
│       │       │   └─► NO → Continue
│       │       │
│       │       └─► Response contains "2FA" or "authenticator"?
│       │           ├─► YES → Detect: TWO_FACTOR_AVAILABLE
│       │           └─► NO → Continue
│       │
│       └─► Analyze Password Requirements
│           ├─► Read validation rules from form
│           └─► Document requirements
│
└─► Compile Authentication Profile
    │
    └─► Return: {
            methods: [detected_methods],
            captcha: captcha_type,
            verification: verification_requirements,
            password_rules: password_requirements,
            two_factor: two_factor_info
        }
```

---

## ATS Detection Decision Tree

### Platform Identification Logic

```
Start: Analyze Careers Page URL
│
├─► Check Domain
│   │
│   ├─► Domain contains "workday.com"?
│   │   ├─► YES → WORKDAY (confidence: HIGH)
│   │   │        └─► Confirm with HTML patterns
│   │   │            ├─► Found Workday CSS?
│   │   │            │   └─► YES → CONFIRMED WORKDAY (confidence: HIGH)
│   │   │            └─► NO → LIKELY WORKDAY (confidence: MEDIUM)
│   │   └─► NO → Continue
│   │
│   ├─► Domain contains "greenhouse.io" or "boards.greenhouse.io"?
│   │   ├─► YES → GREENHOUSE (confidence: HIGH)
│   │   │        └─► Confirm with HTML patterns
│   │   │            ├─► Found #grnhse_app?
│   │   │            │   └─► YES → CONFIRMED GREENHOUSE (confidence: HIGH)
│   │   │            └─► NO → LIKELY GREENHOUSE (confidence: MEDIUM)
│   │   └─► NO → Continue
│   │
│   ├─► Domain contains "taleo.net"?
│   │   ├─► YES → TALEO (confidence: HIGH)
│   │   │        └─► Confirm with HTML patterns
│   │   │            ├─► Found .taleoContent?
│   │   │            │   └─► YES → CONFIRMED TALEO (confidence: HIGH)
│   │   │            └─► NO → LIKELY TALEO (confidence: MEDIUM)
│   │   └─► NO → Continue
│   │
│   ├─► Domain contains "lever.co"?
│   │   ├─► YES → LEVER (confidence: HIGH)
│   │   │        └─► Confirm with HTML patterns
│   │   └─► NO → Continue
│   │
│   ├─► Domain contains "smartrecruiters.com"?
│   │   ├─► YES → SMARTRECRUITERS (confidence: HIGH)
│   │   │        └─► Confirm with HTML patterns
│   │   └─► NO → Continue
│   │
│   ├─► Domain contains "icims.com"?
│   │   ├─► YES → ICIMS (confidence: HIGH)
│   │   │        └─► Confirm with HTML patterns
│   │   └─► NO → Continue
│   │
│   ├─► Domain contains "bamboohr.com"?
│   │   ├─► YES → BAMBOOHR (confidence: HIGH)
│   │   │        └─► Confirm with HTML patterns
│   │   └─► NO → Continue to HTML analysis
│   │
│   └─► NO DOMAIN MATCH → Analyze HTML Content
│
├─► HTML/CSS Pattern Analysis (if domain didn't match)
│   │
│   ├─► Search for platform-specific selectors
│   │   │
│   │   ├─► Found [data-automation-id*="workday"]?
│   │   │   └─► YES → WORKDAY (confidence: MEDIUM)
│   │   │
│   │   ├─► Found #grnhse_app?
│   │   │   └─► YES → GREENHOUSE (confidence: MEDIUM)
│   │   │
│   │   ├─► Found .taleoContent?
│   │   │   └─► YES → TALEO (confidence: MEDIUM)
│   │   │
│   │   └─► [Check other platform patterns...]
│   │
│   └─► NO PATTERN MATCH → Analyze JavaScript
│
├─► JavaScript Analysis (if HTML didn't match)
│   │
│   ├─► Scan for platform-specific JS libraries
│   │   │
│   │   ├─► Found "workday.js" or "wd-*.js"?
│   │   │   └─► YES → WORKDAY (confidence: LOW)
│   │   │
│   │   ├─► Found script src="greenhouse.io"?
│   │   │   └─► YES → GREENHOUSE (confidence: LOW)
│   │   │
│   │   └─► [Check other platform scripts...]
│   │
│   └─► NO SCRIPT MATCH → Analyze Meta Tags
│
├─► Meta Tag Analysis (if JS didn't match)
│   │
│   ├─► Check meta tags
│   │   │
│   │   ├─► <meta name="application-name" content="Workday">?
│   │   │   └─► YES → WORKDAY (confidence: LOW)
│   │   │
│   │   ├─► <meta name="generator" content="Greenhouse ATS">?
│   │   │   └─► YES → GREENHOUSE (confidence: LOW)
│   │   │
│   │   └─► [Check other platform meta tags...]
│   │
│   └─► NO META MATCH → Custom or Unknown
│
└─► Final Result
    │
    ├─► Platform Identified?
    │   ├─► YES → Return: {
    │   │           platform: detected_platform,
    │   │           confidence: confidence_level,
    │   │           detection_method: method_used
    │   │       }
    │   │
    │   └─► NO → Return: {
    │               platform: "CUSTOM_OR_UNKNOWN",
    │               confidence: "low",
    │               detection_method: "none",
    │               note: "Manual analysis required"
    │           }
    │
    └─► End
```

---

## State Machine Diagrams

### Company Research State Machine

```
┌─────────────┐
│   CREATED   │  Initial state when company added to system
└──────┬──────┘
       │
       │ Trigger: Start Research
       ↓
┌─────────────┐
│  PENDING    │  Research job queued
└──────┬──────┘
       │
       │ Trigger: Worker picks up job
       ↓
┌─────────────────────────────────────────────────────────┐
│              DISCOVERING WEBSITE                        │  Step 1
│  • Query Google Search API                              │
│  • Query LinkedIn API (optional)                        │
│  • Validate domain                                      │
└───────┬────────────────────────────────┬────────────────┘
        │ Success                        │ Failure
        ↓                                ↓
┌─────────────────────────┐     ┌──────────────┐
│ DISCOVERING_CAREERS     │     │   ERROR      │
│  • Check URL patterns   │     │  (Website    │
│  • Check subdomains     │     │   Not Found) │
│  • Analyze HTML         │     └──────────────┘
└───────┬─────────────────┘
        │ Success
        ↓
┌─────────────────────────┐
│  DETECTING_ATS          │  Step 4
│  • Domain analysis      │
│  • HTML patterns        │
│  • JavaScript analysis  │
└───────┬─────────────────┘
        │ Success
        ↓
┌─────────────────────────┐
│  DISCOVERING_API        │  Step 5
│  • Network analysis     │
│  • Common patterns      │
│  • Documentation lookup │
└───────┬─────────────────┘
        │ Success
        ↓
┌─────────────────────────┐
│  ANALYZING_AUTH         │  Step 6
│  • Registration flow    │
│  • SSO detection        │
│  • Verification check   │
│  • Login analysis       │
│  • CAPTCHA detection    │
└───────┬─────────────────┘
        │ Success
        ↓
┌─────────────────────────┐
│  CALCULATING_READINESS  │  Step 7
│  • Analyze blockers     │
│  • Score features       │
│  • Generate report      │
└───────┬─────────────────┘
        │ Success
        ↓
┌─────────────────────────┐
│  STORING_RESULTS        │  Step 8
│  • Save to database     │
│  • Update cache         │
│  • Log completion       │
└───────┬─────────────────┘
        │ Success
        ↓
┌─────────────┐
│  COMPLETED  │  Research finished successfully
└─────────────┘

States can transition to ERROR from any point:
• Network timeouts
• API failures
• Invalid data
• Unexpected responses

From ERROR state:
├─► Can be RETRIED (goes back to PENDING)
└─► Or marked as FAILED (terminal state)
```

### Automation Readiness State Machine

```
┌─────────────────┐
│  UNCATEGORIZED  │  Initial state, no analysis yet
└────────┬────────┘
         │
         │ After authentication analysis
         ↓
    ┌────────┐
    │ Decide │
    └───┬────┘
        │
        ├─► Has Public API + No CAPTCHA + Simple Auth
        │   ↓
        │   ┌──────────────────┐
        │   │  FULLY_READY     │  Can automate everything
        │   │                  │
        │   │  Features:       │
        │   │  ✓ Job scraping  │
        │   │  ✓ Auto-apply    │
        │   │  ✓ API access    │
        │   └──────────────────┘
        │
        ├─► Has API but CAPTCHA or Email Verification
        │   ↓
        │   ┌──────────────────┐
        │   │ PARTIALLY_READY  │  Can automate some features
        │   │                  │
        │   │  Features:       │
        │   │  ✓ Job scraping  │
        │   │  ✗ Auto-apply    │
        │   │  ~ API access    │
        │   │                  │
        │   │  Blockers:       │
        │   │  • CAPTCHA       │
        │   │  • Email verify  │
        │   └──────────────────┘
        │
        └─► No API + Strong CAPTCHA + Complex Auth
            ↓
            ┌──────────────────┐
            │  MANUAL_ONLY     │  Cannot automate
            │                  │
            │  Features:       │
            │  ✗ Job scraping  │
            │  ✗ Auto-apply    │
            │  ✗ API access    │
            │                  │
            │  Blockers:       │
            │  • No API        │
            │  • Strong CAPTCHA│
            │  • Phone verify  │
            │  • Manual review │
            └──────────────────┘

Note: States can be upgraded if:
• Company adds public API
• CAPTCHA removed
• Authentication simplified

States can be downgraded if:
• API access revoked
• New blockers added
• Security increased
```

---

## Sequence Diagrams

### User-Initiated Research Flow

```
User            Controller         Orchestrator      Services           External APIs      Database
 │                  │                  │                │                    │               │
 │─────Submit────►│                  │                │                    │               │
 │ "Acme Corp"     │                  │                │                    │               │
 │                 │──executeResearch─►│                │                    │               │
 │                 │                  │─getCached?────►│                    │               │
 │                 │                  │                │                    │   SELECT...   │
 │                 │                  │                │                    │◄──────────────┤
 │                 │                  │◄─Cache Miss────┤                    │               │
 │                 │                  │                │                    │               │
 │                 │                  │─discoverWebsite►                   │               │
 │                 │                  │                │─Google Search──────►               │
 │                 │                  │                │◄─────Results──────┤               │
 │                 │                  │◄─"acme.com"────┤                    │               │
 │                 │                  │                │                    │               │
 │                 │                  │─discoverCareers►                   │               │
 │                 │                  │                │─HTTP GET careers───►               │
 │                 │                  │                │◄─HTML Response────┤               │
 │                 │                  │◄─Careers URLs──┤                    │               │
 │                 │                  │                │                    │               │
 │                 │                  │─detectATS──────►                   │               │
 │                 │                  │                │─HTTP GET + Parse───►               │
 │                 │                  │                │◄─HTML Response────┤               │
 │                 │                  │◄─"Workday"─────┤                    │               │
 │                 │                  │                │                    │               │
 │                 │                  │─discoverAPI────►                   │               │
 │                 │                  │                │─Network Analysis───►               │
 │                 │                  │                │◄─API Endpoints────┤               │
 │                 │                  │◄─API Info──────┤                    │               │
 │                 │                  │                │                    │               │
 │                 │                  │─analyzeAuth────►                   │               │
 │                 │                  │                │─Analyze Forms──────►               │
 │                 │                  │                │◄─Auth Details─────┤               │
 │                 │                  │◄─Auth Info─────┤                    │               │
 │                 │                  │                │                    │               │
 │                 │                  │─storeResults───►                   │               │
 │                 │                  │                │                    │   INSERT...   │
 │                 │                  │                │                    │──────────────►│
 │                 │                  │◄─Success───────┤                    │               │
 │                 │◄─Complete Results┤                │                    │               │
 │◄──Display Page──┤                  │                │                    │               │
 │                 │                  │                │                    │               │
```

---

## Error Handling Flow

### Graceful Degradation Strategy

```
Research Process Starts
│
├─► Step 1: Company Discovery
│   ├─► Try: Google Search API
│   │   ├─► Success → Continue
│   │   └─► Fail → Try: LinkedIn API
│   │       ├─► Success → Continue
│   │       └─► Fail → Try: Manual patterns
│   │           ├─► Success → Continue (degraded mode)
│   │           └─► Fail → ERROR: Cannot find company
│   │
├─► Step 2: Careers Discovery
│   ├─► Try: Common URL patterns
│   │   ├─► Success → Continue
│   │   └─► Fail → Try: Subdomain patterns
│   │       ├─► Success → Continue
│   │       └─► Fail → Try: HTML link analysis
│   │           ├─► Success → Continue (degraded mode)
│   │           └─► Fail → WARNING: No careers page found
│   │                      (Store partial results and continue)
│   │
├─► Step 3: ATS Detection
│   ├─► Try: Domain detection
│   │   ├─► Success → Continue (high confidence)
│   │   └─► Fail → Try: HTML pattern detection
│   │       ├─► Success → Continue (medium confidence)
│   │       └─► Fail → Try: JavaScript detection
│   │           ├─► Success → Continue (low confidence)
│   │           └─► Fail → Mark as "CUSTOM_OR_UNKNOWN"
│   │                      (Store and continue)
│   │
├─► Step 4: API Discovery
│   ├─► Try: Network traffic analysis
│   │   ├─► Success → Continue
│   │   └─► Fail → Try: Common API patterns
│   │       ├─► Success → Continue (needs validation)
│   │       └─► Fail → Mark as "No API detected"
│   │                  (Store and continue)
│   │
├─► Step 5: Auth Analysis
│   ├─► Try: Full analysis
│   │   ├─► Success → Continue
│   │   └─► Fail → Try: Basic form analysis
│   │       ├─► Success → Continue (limited data)
│   │       └─► Fail → Mark as "Analysis incomplete"
│   │                  (Store and continue)
│   │
└─► Final: Store Results
    ├─► Has any data? → Success (with warnings)
    └─► No data at all → Failure

Result Levels:
├─► COMPLETE: All steps successful
├─► PARTIAL: Some steps failed, useful data collected
└─► FAILED: Critical steps failed, insufficient data
```

---

## Component Dependency Graph

```
CompanyResearchServiceController
        │
        └─► depends on
            │
            ├─► CompanyResearchService (orchestrator)
            │   │
            │   └─► depends on
            │       │
            │       ├─► CompanyDiscoveryService
            │       │   ├─► HttpClient
            │       │   ├─► GoogleSearchClient
            │       │   └─► LinkedInClient (optional)
            │       │
            │       ├─► CareersPageDiscoveryService
            │       │   ├─► HttpClient
            │       │   └─► HtmlParser (DomCrawler)
            │       │
            │       ├─► ATSDetectionService
            │       │   ├─► HttpClient
            │       │   ├─► HtmlParser
            │       │   └─► DatabaseConnection (ats_platforms table)
            │       │
            │       ├─► APIDiscoveryService
            │       │   ├─► HttpClient
            │       │   ├─► HeadlessBrowser (Puppeteer/Playwright)
            │       │   └─► NetworkAnalyzer
            │       │
            │       └─► AuthenticationAnalysisService
            │           ├─► HttpClient
            │           ├─► HtmlParser
            │           ├─► FormAnalyzer
            │           └─► ScriptAnalyzer
            │
            ├─► DatabaseConnection
            ├─► CacheManager
            └─► Logger

All services can use:
├─► Configuration (job_hunter.company_research.settings)
├─► Logger
└─► Event Dispatcher (for progress updates)
```

---

## Caching Strategy Diagram

```
Request: Research Company
         │
         ↓
    ┌─────────┐
    │ Check   │
    │ Cache   │
    └────┬────┘
         │
    ┌────┴────┐
    │         │
    │ Cached? │
    │         │
    └────┬────┘
         │
    ┌────┴───────┬─────────┐
    │            │         │
    YES          NO      EXPIRED
    │            │         │
    ↓            ↓         ↓
┌────────┐  ┌───────┐  ┌─────────┐
│ Return │  │Execute│  │ Refresh?│
│ Cached │  │ Fresh │  └────┬────┘
│ Data   │  │Research│       │
└────────┘  └───────┘   ┌────┴───┬───────┐
                        │        │       │
                      YES       NO   USER_CHOICE
                        │        │       │
                        ↓        ↓       ↓
                   ┌────────┐┌─────┐┌──────┐
                   │Execute ││Return││Ask  │
                   │Research││Cached││User │
                   │& Update││ Data │└──────┘
                   │ Cache  │└─────┘
                   └────────┘

Cache Storage Structure:
jobhunter_company_research table
├─► Key: company_id
├─► Data: JSON serialized results
├─► Created: timestamp
├─► Updated: timestamp
└─► Expires: created + 30 days

Cache Invalidation Events:
├─► Manual refresh requested
├─► Company website changes (detected)
├─► ATS platform upgrade detected
└─► Background refresh job (weekly)
```

---

## Batch Processing Flow

```
Batch Request: ["Company A", "Company B", "Company C", ...]
│
├─► Queue Manager
│   │
│   ├─► Create job for each company
│   │   ├─► Job A: Research "Company A"
│   │   ├─► Job B: Research "Company B"
│   │   ├─► Job C: Research "Company C"
│   │   └─► ...
│   │
│   └─► Add jobs to queue with priority
│
├─► Worker Pool (max_concurrent_jobs: 5)
│   │
│   ├─► Worker 1 → Processing Job A
│   │   └─► Progress: DISCOVERING_WEBSITE
│   │
│   ├─► Worker 2 → Processing Job B
│   │   └─► Progress: DETECTING_ATS
│   │
│   ├─► Worker 3 → Processing Job C
│   │   └─► Progress: ANALYZING_AUTH
│   │
│   ├─► Worker 4 → Idle
│   └─► Worker 5 → Idle
│
├─► Progress Tracker
│   │
│   ├─► Total: 10 companies
│   ├─► Completed: 3
│   ├─► In Progress: 3
│   ├─► Pending: 4
│   ├─► Failed: 0
│   └─► Success Rate: 100%
│
└─► Results Aggregator
    │
    ├─► Collect completed results
    ├─► Generate summary report
    └─► Return to user
        │
        ├─► Company A: ✓ Complete (Workday, Partial Automation)
        ├─► Company B: ✓ Complete (Greenhouse, Full Automation)
        ├─► Company C: ⚠ Partial (Custom ATS, Manual Only)
        └─► ...

Status Updates (real-time):
├─► WebSocket: Push updates to UI
├─► Progress bar: X of Y complete
└─► Log: Detailed progress for each company
```

---

**End of Visual Diagrams Document**

---

## Notes for Developers

When implementing the company research path:

1. **Start with the simplest flow**: Implement domain discovery → careers page discovery → basic ATS detection
2. **Use these diagrams as reference**: Each diagram shows decision points and error handling
3. **Implement services independently**: Each service should be testable in isolation
4. **Add integration tests**: Test the complete flow with real examples
5. **Consider rate limits**: Don't overwhelm external APIs or company servers
6. **Log extensively**: Use the flow diagrams to add logging at each decision point
7. **Handle errors gracefully**: Follow the degradation strategy to provide partial results when possible

---

## Related Documents

- [COMPANY_RESEARCH_PATH_DESIGN.md](./COMPANY_RESEARCH_PATH_DESIGN.md) - Main design document
- [PROCESS_FLOW.md](./PROCESS_FLOW.md) - General module process flows
- [ARCHITECTURE.md](./ARCHITECTURE.md) - Module architecture overview
