# Company Research Path - Process Flow & Architecture Design

**Document Version:** 1.0  
**Created:** February 13, 2026  
**Status:** Design Document (Not Implemented)

## Table of Contents
1. [Overview](#overview)
2. [Process Flow](#process-flow)
3. [Controller Architecture](#controller-architecture)
4. [Service Layer Architecture](#service-layer-architecture)
5. [Data Models & Schemas](#data-models--schemas)
6. [API Integrations](#api-integrations)
7. [Authentication Strategies](#authentication-strategies)
8. [Implementation Roadmap](#implementation-roadmap)

---

## Overview

### Purpose
This document outlines the design for a company research process that accepts a company name as input and identifies:
1. Job application careers pages
2. Application system type (ATS platform)
3. Authentication methodologies required for account creation and confirmation

### Goals
- **Automated Discovery**: Identify company careers pages automatically
- **ATS Detection**: Recognize which application tracking system is being used
- **Auth Analysis**: Determine account creation and verification requirements
- **Scalability**: Design for multiple companies and ATS platforms
- **Extensibility**: Easy to add new ATS platform support

### Input/Output
- **Input**: Company name (string)
- **Output**: Company research data structure containing:
  - Careers page URLs
  - ATS platform identification
  - Authentication requirements
  - API endpoints (if available)
  - Required credentials/tokens

---

## Process Flow

### High-Level Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. COMPANY NAME INPUT                                           │
│    - User provides company name                                 │
│    - Optional: Domain/website hint                              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. COMPANY WEBSITE DISCOVERY                                    │
│    - Search company website via multiple sources:               │
│      • Google Custom Search API                                 │
│      • LinkedIn Company API                                     │
│      • Manual domain lookup                                     │
│    - Validate website accessibility                             │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. CAREERS PAGE DISCOVERY                                       │
│    - Scan company website for careers links:                    │
│      • Common patterns: /careers, /jobs, /opportunities         │
│      • Sub-domains: careers.company.com, jobs.company.com       │
│      • Third-party: company.workday.com, company.taleo.net      │
│    - Follow redirects to external ATS platforms                 │
│    - Extract careers page metadata                              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4. ATS PLATFORM IDENTIFICATION                                  │
│    - Analyze HTML/JavaScript patterns                           │
│    - Detect platform-specific identifiers:                      │
│      • Workday: "workday.com" domain, specific CSS classes      │
│      • Taleo: "taleo.net" domain, Oracle branding               │
│      • Greenhouse: "greenhouse.io", specific API endpoints      │
│      • Lever: "lever.co", specific page structure               │
│      • SmartRecruiters: "smartrecruiters.com"                   │
│      • iCIMS: "icims.com"                                       │
│      • BambooHR: "bamboohr.com"                                 │
│      • Custom: In-house built systems                           │
│    - Extract ATS version and capabilities                       │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5. API ENDPOINT DISCOVERY                                       │
│    - Search for API endpoints:                                  │
│      • REST API endpoints                                       │
│      • GraphQL endpoints                                        │
│      • SOAP endpoints (legacy systems)                          │
│    - Document API base URLs                                     │
│    - Identify required headers/tokens                           │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6. AUTHENTICATION ANALYSIS                                      │
│    - Identify account creation process:                         │
│      • Email/password registration                              │
│      • SSO (Single Sign-On) providers                           │
│      • OAuth 2.0 providers (Google, LinkedIn, etc.)             │
│      • SAML integration                                         │
│    - Analyze verification requirements:                         │
│      • Email verification links                                 │
│      • SMS/phone verification                                   │
│      • CAPTCHA/reCAPTCHA                                        │
│      • Two-factor authentication                                │
│    - Document login endpoints and flows                         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 7. DATA PERSISTENCE                                             │
│    - Store research results in database                         │
│    - Cache for future lookups                                   │
│    - Version results (companies may change ATS)                 │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 8. OUTPUT GENERATION                                            │
│    - Return structured data to caller                           │
│    - Display in UI with actionable insights                     │
│    - Flag automation readiness level                            │
└─────────────────────────────────────────────────────────────────┘
```

### Detailed Step-by-Step Flow

#### Step 1: Company Name Input
**Triggers:**
- User enters company name via UI form
- Bulk import process provides company list
- API call with company name parameter

**Processing:**
- Sanitize and normalize company name
- Check if company already exists in database
- If exists, return cached results (unless refresh requested)

**Decision Points:**
- Company exists in DB → Check cache freshness
- Company not in DB → Proceed to discovery
- Cache expired → Proceed to refresh

---

#### Step 2: Company Website Discovery

**Methods:**

**A. Google Custom Search API**
```
Query: "{company_name} official website"
Process: 
  1. Execute search via API
  2. Parse top 3 results
  3. Look for official domain markers
  4. Validate domain ownership (via WHOIS if needed)
```

**B. LinkedIn Company Search**
```
Query: Search LinkedIn for company profile
Process:
  1. Use LinkedIn Company API (if available)
  2. Extract official website link
  3. Validate domain
```

**C. Manual Domain Patterns**
```
Common patterns:
  - company-name.com
  - companyname.com
  - company.com
  - thecompanyname.com
```

**Output:**
- Primary company domain
- Alternative domains (if any)
- Domain registration info
- SSL certificate validation

---

#### Step 3: Careers Page Discovery

**Detection Strategies:**

**A. Common URL Patterns**
```
Priority Order:
1. /careers
2. /jobs
3. /opportunities
4. /work-with-us
5. /join-us
6. /about/careers
7. /company/careers
```

**B. Sub-domain Patterns**
```
Priority Order:
1. careers.{domain}
2. jobs.{domain}
3. hiring.{domain}
4. recruiting.{domain}
```

**C. HTML Link Analysis**
```
Search for links with keywords:
- Text contains: "careers", "jobs", "opportunities", "join"
- Aria-label or title attributes
- Common CSS classes: .careers-link, .jobs-link
```

**D. External ATS Redirects**
```
Common redirect patterns:
- {company}.workday.com
- {company}.taleo.net
- jobs.{company}.com → greenhouse.io
- {company}.lever.co
```

**Output:**
- List of careers page URLs
- Redirect chains (if any)
- Page accessibility status
- Response time metrics

---

#### Step 4: ATS Platform Identification

**Detection Methods:**

**A. Domain-Based Detection**
```javascript
Detection Rules:
{
  "workday.com": {
    "platform": "Workday",
    "confidence": "high",
    "detection_method": "domain"
  },
  "taleo.net": {
    "platform": "Oracle Taleo",
    "confidence": "high",
    "detection_method": "domain"
  },
  "greenhouse.io": {
    "platform": "Greenhouse",
    "confidence": "high",
    "detection_method": "domain"
  },
  "lever.co": {
    "platform": "Lever",
    "confidence": "high",
    "detection_method": "domain"
  }
}
```

**B. HTML/CSS Pattern Detection**
```javascript
Pattern Signatures:
{
  "Workday": {
    "selectors": [
      "[data-automation-id*='workday']",
      ".WORKDAY-theme",
      "#wd-*"
    ],
    "scripts": ["workday.js", "wd-*.js"]
  },
  "Greenhouse": {
    "selectors": [
      "#grnhse_app",
      ".greenhouse-application"
    ],
    "scripts": ["greenhouse.io"]
  },
  "Taleo": {
    "selectors": [
      "#taleo-*",
      ".taleoContent"
    ],
    "scripts": ["taleo.net"]
  }
}
```

**C. JavaScript Library Detection**
```javascript
Library Fingerprints:
- Check for platform-specific JS frameworks
- Analyze API endpoint patterns in network requests
- Detect platform-specific cookies
```

**D. Meta Tag Analysis**
```html
Look for:
<meta name="application-name" content="Workday">
<meta name="generator" content="Greenhouse ATS">
<meta property="og:site_name" content="Lever">
```

**Output:**
- ATS platform name
- Platform version (if detectable)
- Confidence level (high/medium/low)
- Detection method used
- Alternative platforms detected (for multi-ATS setups)

---

#### Step 5: API Endpoint Discovery

**Discovery Strategies:**

**A. Network Traffic Analysis**
```
Monitor browser network requests:
1. Load careers page
2. Capture XHR/Fetch requests
3. Identify API endpoints
4. Document request/response formats
```

**B. Common API Patterns**
```
Workday:
  - /api/v1/jobs
  - /api/v1/applications
  
Greenhouse:
  - /embed/job_board/json
  - /boards/{company}/jobs
  
Lever:
  - /v1/postings/{company}
  
Taleo:
  - /careersection/rest/jobboard
```

**C. Documentation Discovery**
```
Look for:
- /api/docs
- /developers
- /api-documentation
- robots.txt hints
- sitemap.xml entries
```

**Output:**
- Base API URL
- Available endpoints
- Authentication requirements
- Rate limits (if documented)
- Request/response examples

---

#### Step 6: Authentication Analysis

**Authentication Methods Detection:**

**A. Registration Flow Analysis**
```
Flow Steps:
1. Locate "Sign Up" / "Create Account" links
2. Analyze form fields:
   - Required fields (email, password, name, etc.)
   - Optional fields (phone, location, etc.)
   - Password requirements
3. Identify CAPTCHA implementation
4. Check for SSO options
5. Document registration endpoint
```

**B. SSO Provider Detection**
```
Common Providers:
- Google Sign-In
- LinkedIn OAuth
- Microsoft Azure AD
- Okta
- Auth0
- OneLogin

Detection:
- Look for provider-specific buttons
- Analyze OAuth redirect URLs
- Check for SAML metadata
```

**C. Verification Requirements**
```
Email Verification:
- Link-based verification
- Code-based verification
- Timing (immediate vs delayed)

Phone Verification:
- SMS code
- Voice call
- WhatsApp/other

Two-Factor:
- Authenticator apps (TOTP)
- SMS-based
- Email-based
- Hardware tokens
```

**D. Login Process Analysis**
```
Steps:
1. Locate login form
2. Identify authentication endpoint
3. Document required headers
4. Analyze session management:
   - Cookies
   - JWT tokens
   - OAuth tokens
5. Test login persistence
6. Document logout process
```

**E. CAPTCHA/Bot Prevention**
```
Types:
- Google reCAPTCHA v2
- Google reCAPTCHA v3
- hCaptcha
- Custom CAPTCHA
- No CAPTCHA (risk assessment)

Detection:
- Analyze form submission requirements
- Check for CAPTCHA scripts
- Test form submission flow
```

**Output:**
- Authentication method(s) used
- Registration endpoint
- Login endpoint
- Required credentials
- Verification steps
- Session management approach
- Bot prevention mechanisms

---

#### Step 7: Data Persistence

**Database Schema:**
```sql
Table: jobhunter_company_research

Columns:
- id (PRIMARY KEY)
- company_id (FOREIGN KEY to jobhunter_companies)
- research_date (TIMESTAMP)
- company_domain (VARCHAR)
- careers_page_urls (JSON) -- Array of URLs
- ats_platform (VARCHAR)
- ats_version (VARCHAR)
- ats_confidence_level (ENUM: high, medium, low)
- api_base_url (VARCHAR)
- api_endpoints (JSON) -- Array of endpoint objects
- auth_methods (JSON) -- Array of auth method objects
- registration_endpoint (VARCHAR)
- login_endpoint (VARCHAR)
- verification_requirements (JSON)
- sso_providers (JSON) -- Array of provider names
- captcha_type (VARCHAR)
- automation_readiness (ENUM: ready, partial, manual)
- notes (TEXT)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)
```

**Caching Strategy:**
- Cache results for 30 days
- Refresh on-demand
- Background refresh for active companies
- Version history tracking

---

#### Step 8: Output Generation

**Output Format:**

```json
{
  "company_name": "Example Corporation",
  "research_date": "2026-02-13T16:53:00Z",
  "company_domain": "example.com",
  "careers_pages": [
    {
      "url": "https://careers.example.com",
      "type": "primary",
      "accessible": true,
      "response_time_ms": 234
    },
    {
      "url": "https://example.workday.com/careers",
      "type": "ats_hosted",
      "accessible": true,
      "response_time_ms": 456
    }
  ],
  "ats_detection": {
    "platform": "Workday",
    "version": "2024.1",
    "confidence": "high",
    "detection_method": "domain",
    "alternative_platforms": []
  },
  "api_endpoints": {
    "base_url": "https://example.workday.com/api/v1",
    "endpoints": [
      {
        "path": "/jobs",
        "method": "GET",
        "description": "List all job postings"
      },
      {
        "path": "/applications",
        "method": "POST",
        "description": "Submit job application"
      }
    ]
  },
  "authentication": {
    "methods": [
      "email_password",
      "google_oauth",
      "linkedin_oauth"
    ],
    "registration": {
      "endpoint": "https://example.workday.com/api/v1/users",
      "required_fields": [
        "email",
        "password",
        "first_name",
        "last_name"
      ],
      "optional_fields": [
        "phone",
        "location"
      ],
      "password_requirements": {
        "min_length": 8,
        "requires_uppercase": true,
        "requires_lowercase": true,
        "requires_number": true,
        "requires_special": true
      }
    },
    "verification": {
      "email_verification": {
        "required": true,
        "method": "link",
        "timing": "immediate"
      },
      "phone_verification": {
        "required": false
      },
      "two_factor": {
        "required": false,
        "optional": true,
        "methods": ["authenticator", "sms"]
      }
    },
    "login": {
      "endpoint": "https://example.workday.com/api/v1/auth/login",
      "method": "POST",
      "session_type": "jwt_token",
      "token_location": "header",
      "token_expiry": "24h"
    },
    "bot_prevention": {
      "captcha_type": "recaptcha_v3",
      "site_key": "6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI"
    }
  },
  "automation_readiness": {
    "level": "partial",
    "ready_features": [
      "job_listing_retrieval",
      "api_access"
    ],
    "blocked_features": [
      "auto_registration",
      "auto_application"
    ],
    "blockers": [
      "recaptcha_required",
      "email_verification_required"
    ],
    "recommendations": [
      "Manual account creation required",
      "Use API key authentication if available",
      "Consider email integration for verification"
    ]
  },
  "metadata": {
    "research_duration_ms": 5678,
    "urls_scanned": 12,
    "confidence_score": 0.92
  }
}
```

---

## Controller Architecture

### CompanyResearchService Controller

**File:** `src/Controller/CompanyResearchServiceController.php`

**Purpose:** Orchestrates the company research process

**Methods:**

```php
/**
 * Initiate company research process.
 *
 * @param string $company_name
 *   The company name to research.
 * @param array $options
 *   Optional parameters:
 *   - refresh: Force refresh cached data
 *   - deep_scan: Perform comprehensive analysis
 *
 * @return array
 *   Research results as structured array.
 */
public function researchCompany(string $company_name, array $options = []);

/**
 * Display company research results page.
 *
 * @param int $company_id
 *   The company ID to display research for.
 *
 * @return array
 *   Render array for research results page.
 */
public function displayResearchResults(int $company_id);

/**
 * Refresh research data for a company.
 *
 * @param int $company_id
 *   The company ID to refresh.
 *
 * @return \Symfony\Component\HttpFoundation\RedirectResponse
 *   Redirect back to research results page.
 */
public function refreshResearch(int $company_id);

/**
 * Batch research multiple companies.
 *
 * @param array $company_names
 *   Array of company names to research.
 *
 * @return array
 *   Batch processing results.
 */
public function batchResearch(array $company_names);
```

**Routes:**

```yaml
# job_hunter.routing.yml additions

job_hunter.company_research.start:
  path: '/job-hunter/company-research/start'
  defaults:
    _controller: '\Drupal\job_hunter\Controller\CompanyResearchServiceController::startResearch'
    _title: 'Start Company Research'
  requirements:
    _permission: 'access company research'

job_hunter.company_research.results:
  path: '/job-hunter/company-research/results/{company_id}'
  defaults:
    _controller: '\Drupal\job_hunter\Controller\CompanyResearchServiceController::displayResearchResults'
    _title: 'Company Research Results'
  requirements:
    _permission: 'access company research'
    company_id: \d+

job_hunter.company_research.refresh:
  path: '/job-hunter/company-research/refresh/{company_id}'
  defaults:
    _controller: '\Drupal\job_hunter\Controller\CompanyResearchServiceController::refreshResearch'
  requirements:
    _permission: 'administer company research'
    company_id: \d+

job_hunter.company_research.batch:
  path: '/job-hunter/company-research/batch'
  defaults:
    _controller: '\Drupal\job_hunter\Controller\CompanyResearchServiceController::batchResearch'
    _title: 'Batch Company Research'
  requirements:
    _permission: 'administer company research'
```

---

## Service Layer Architecture

### 1. CompanyResearchService

**File:** `src/Service/CompanyResearchService.php`

**Purpose:** Main orchestration service that coordinates all research steps

**Dependencies:**
- CompanyDiscoveryService
- CareersPageDiscoveryService
- ATSDetectionService
- AuthenticationAnalysisService
- Database connection
- Logger

**Key Methods:**

```php
/**
 * Execute complete company research workflow.
 *
 * @param string $company_name
 *   Company name to research.
 * @param array $options
 *   Research options.
 *
 * @return array
 *   Complete research results.
 */
public function executeResearch(string $company_name, array $options = []);

/**
 * Get cached research results.
 *
 * @param int $company_id
 *   Company ID.
 *
 * @return array|null
 *   Cached results or NULL if not cached.
 */
public function getCachedResearch(int $company_id);

/**
 * Store research results in database.
 *
 * @param int $company_id
 *   Company ID.
 * @param array $results
 *   Research results.
 */
public function storeResearchResults(int $company_id, array $results);
```

---

### 2. CompanyDiscoveryService

**File:** `src/Service/CompanyDiscoveryService.php`

**Purpose:** Discovers company website from company name

**Dependencies:**
- Google Custom Search API client
- LinkedIn API client (optional)
- HTTP client
- Logger

**Key Methods:**

```php
/**
 * Discover company website from name.
 *
 * @param string $company_name
 *   Company name.
 *
 * @return array
 *   Domain and website information.
 */
public function discoverCompanyWebsite(string $company_name);

/**
 * Validate domain ownership.
 *
 * @param string $domain
 *   Domain to validate.
 *
 * @return bool
 *   TRUE if domain is valid and accessible.
 */
public function validateDomain(string $domain);

/**
 * Search via Google Custom Search API.
 *
 * @param string $query
 *   Search query.
 *
 * @return array
 *   Search results.
 */
protected function searchViaGoogle(string $query);

/**
 * Search via LinkedIn Company API.
 *
 * @param string $company_name
 *   Company name.
 *
 * @return array
 *   LinkedIn company data.
 */
protected function searchViaLinkedIn(string $company_name);
```

---

### 3. CareersPageDiscoveryService

**File:** `src/Service/CareersPageDiscoveryService.php`

**Purpose:** Discovers careers pages from company website

**Dependencies:**
- HTTP client
- HTML parser (e.g., Symfony DomCrawler)
- Logger

**Key Methods:**

```php
/**
 * Discover careers pages for a company domain.
 *
 * @param string $domain
 *   Company domain.
 *
 * @return array
 *   Array of careers page URLs.
 */
public function discoverCareersPages(string $domain);

/**
 * Check common URL patterns.
 *
 * @param string $domain
 *   Company domain.
 *
 * @return array
 *   Found careers URLs.
 */
protected function checkCommonPatterns(string $domain);

/**
 * Check subdomain patterns.
 *
 * @param string $domain
 *   Company domain.
 *
 * @return array
 *   Found careers URLs on subdomains.
 */
protected function checkSubdomains(string $domain);

/**
 * Analyze HTML for careers links.
 *
 * @param string $html
 *   HTML content.
 * @param string $base_url
 *   Base URL for relative links.
 *
 * @return array
 *   Found careers links.
 */
protected function analyzeCareersLinks(string $html, string $base_url);

/**
 * Follow redirects to find final careers page.
 *
 * @param string $url
 *   Initial URL.
 *
 * @return array
 *   Redirect chain information.
 */
protected function followRedirects(string $url);
```

---

### 4. ATSDetectionService

**File:** `src/Service/ATSDetectionService.php`

**Purpose:** Identifies ATS platform from careers page

**Dependencies:**
- HTTP client
- HTML parser
- JavaScript analyzer
- Logger

**Key Methods:**

```php
/**
 * Detect ATS platform from careers page.
 *
 * @param string $careers_url
 *   Careers page URL.
 *
 * @return array
 *   ATS detection results.
 */
public function detectATSPlatform(string $careers_url);

/**
 * Detect via domain analysis.
 *
 * @param string $url
 *   Page URL.
 *
 * @return array|null
 *   Platform info or NULL.
 */
protected function detectViaDomain(string $url);

/**
 * Detect via HTML/CSS patterns.
 *
 * @param string $html
 *   HTML content.
 *
 * @return array|null
 *   Platform info or NULL.
 */
protected function detectViaHTMLPatterns(string $html);

/**
 * Detect via JavaScript libraries.
 *
 * @param string $html
 *   HTML content.
 *
 * @return array|null
 *   Platform info or NULL.
 */
protected function detectViaJavaScript(string $html);

/**
 * Detect via meta tags.
 *
 * @param string $html
 *   HTML content.
 *
 * @return array|null
 *   Platform info or NULL.
 */
protected function detectViaMetaTags(string $html);

/**
 * Get ATS platform signatures.
 *
 * @return array
 *   Array of platform detection patterns.
 */
protected function getPlatformSignatures();
```

---

### 5. APIDiscoveryService

**File:** `src/Service/APIDiscoveryService.php`

**Purpose:** Discovers API endpoints for the ATS platform

**Dependencies:**
- HTTP client
- Network traffic analyzer
- Logger

**Key Methods:**

```php
/**
 * Discover API endpoints for ATS platform.
 *
 * @param string $careers_url
 *   Careers page URL.
 * @param string $ats_platform
 *   Detected ATS platform name.
 *
 * @return array
 *   API endpoint information.
 */
public function discoverAPIEndpoints(string $careers_url, string $ats_platform);

/**
 * Analyze network traffic for API calls.
 *
 * @param string $url
 *   Page URL to analyze.
 *
 * @return array
 *   Discovered API endpoints.
 */
protected function analyzeNetworkTraffic(string $url);

/**
 * Check common API patterns for platform.
 *
 * @param string $base_url
 *   Base URL.
 * @param string $platform
 *   ATS platform name.
 *
 * @return array
 *   Found API endpoints.
 */
protected function checkCommonAPIPatterns(string $base_url, string $platform);

/**
 * Look for API documentation.
 *
 * @param string $domain
 *   Company domain.
 *
 * @return string|null
 *   Documentation URL or NULL.
 */
protected function findAPIDocumentation(string $domain);

/**
 * Get platform-specific API patterns.
 *
 * @param string $platform
 *   ATS platform name.
 *
 * @return array
 *   API patterns for platform.
 */
protected function getPlatformAPIPatterns(string $platform);
```

---

### 6. AuthenticationAnalysisService

**File:** `src/Service/AuthenticationAnalysisService.php`

**Purpose:** Analyzes authentication and account creation requirements

**Dependencies:**
- HTTP client
- HTML parser
- Form analyzer
- Logger

**Key Methods:**

```php
/**
 * Analyze authentication requirements.
 *
 * @param string $careers_url
 *   Careers page URL.
 * @param string $ats_platform
 *   ATS platform name.
 *
 * @return array
 *   Authentication analysis results.
 */
public function analyzeAuthentication(string $careers_url, string $ats_platform);

/**
 * Analyze registration flow.
 *
 * @param string $url
 *   Registration page URL.
 *
 * @return array
 *   Registration flow details.
 */
protected function analyzeRegistrationFlow(string $url);

/**
 * Detect SSO providers.
 *
 * @param string $html
 *   HTML content.
 *
 * @return array
 *   Array of SSO providers.
 */
protected function detectSSOProviders(string $html);

/**
 * Analyze verification requirements.
 *
 * @param string $html
 *   HTML content.
 *
 * @return array
 *   Verification requirements.
 */
protected function analyzeVerificationRequirements(string $html);

/**
 * Analyze login process.
 *
 * @param string $url
 *   Login page URL.
 *
 * @return array
 *   Login process details.
 */
protected function analyzeLoginProcess(string $url);

/**
 * Detect CAPTCHA implementation.
 *
 * @param string $html
 *   HTML content.
 *
 * @return array|null
 *   CAPTCHA details or NULL.
 */
protected function detectCaptcha(string $html);

/**
 * Get SSO provider patterns.
 *
 * @return array
 *   SSO detection patterns.
 */
protected function getSSOPatterns();
```

---

## Data Models & Schemas

### Database Tables

#### jobhunter_company_research

**Purpose:** Stores company research results

```sql
CREATE TABLE jobhunter_company_research (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  research_date INT NOT NULL,
  company_domain VARCHAR(255),
  careers_page_urls LONGTEXT,  -- JSON array
  ats_platform VARCHAR(100),
  ats_version VARCHAR(50),
  ats_confidence_level VARCHAR(20),
  detection_method VARCHAR(50),
  api_base_url VARCHAR(255),
  api_endpoints LONGTEXT,  -- JSON array
  auth_methods LONGTEXT,  -- JSON array
  registration_endpoint VARCHAR(255),
  login_endpoint VARCHAR(255),
  verification_requirements LONGTEXT,  -- JSON object
  sso_providers LONGTEXT,  -- JSON array
  captcha_type VARCHAR(50),
  captcha_site_key VARCHAR(255),
  automation_readiness VARCHAR(20),
  automation_blockers LONGTEXT,  -- JSON array
  notes TEXT,
  metadata LONGTEXT,  -- JSON object
  created_at INT NOT NULL,
  updated_at INT NOT NULL,
  PRIMARY KEY (id),
  KEY company_id (company_id),
  KEY ats_platform (ats_platform),
  KEY automation_readiness (automation_readiness),
  CONSTRAINT fk_company_research_company 
    FOREIGN KEY (company_id) 
    REFERENCES jobhunter_companies (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### jobhunter_ats_platforms

**Purpose:** Reference table for known ATS platforms

```sql
CREATE TABLE jobhunter_ats_platforms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  platform_name VARCHAR(100) NOT NULL,
  platform_slug VARCHAR(100) NOT NULL,
  vendor VARCHAR(100),
  description TEXT,
  official_website VARCHAR(255),
  documentation_url VARCHAR(255),
  logo_url VARCHAR(255),
  detection_patterns LONGTEXT,  -- JSON object
  api_patterns LONGTEXT,  -- JSON object
  auth_patterns LONGTEXT,  -- JSON object
  automation_capability VARCHAR(20),  -- full, partial, none
  notes TEXT,
  created_at INT NOT NULL,
  updated_at INT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY platform_slug (platform_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Seed Data Examples:**

```sql
-- Workday
INSERT INTO jobhunter_ats_platforms VALUES (
  NULL,
  'Workday',
  'workday',
  'Workday, Inc.',
  'Enterprise-level ATS and HCM platform',
  'https://www.workday.com',
  'https://developer.workday.com',
  NULL,
  '{"domains": ["workday.com"], "css_classes": ["WORKDAY-theme"], "meta_tags": ["Workday"]}',
  '{"base_patterns": ["/api/v1"], "endpoints": ["/jobs", "/applications"]}',
  '{"methods": ["email_password", "sso"], "captcha": "recaptcha_v3"}',
  'partial',
  'Supports API access but requires account approval',
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
);

-- Greenhouse
INSERT INTO jobhunter_ats_platforms VALUES (
  NULL,
  'Greenhouse',
  'greenhouse',
  'Greenhouse Software, Inc.',
  'Modern ATS focused on recruiting and hiring',
  'https://www.greenhouse.io',
  'https://developers.greenhouse.io',
  NULL,
  '{"domains": ["greenhouse.io", "boards.greenhouse.io"], "ids": ["grnhse_app"], "css_classes": ["greenhouse-application"]}',
  '{"base_patterns": ["/embed/job_board/json", "/boards/*/jobs"], "public_api": true}',
  '{"methods": ["email_password"], "captcha": "optional"}',
  'full',
  'Public API available, good automation support',
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
);

-- Taleo
INSERT INTO jobhunter_ats_platforms VALUES (
  NULL,
  'Oracle Taleo',
  'taleo',
  'Oracle Corporation',
  'Legacy enterprise ATS platform',
  'https://www.oracle.com/taleo',
  NULL,
  NULL,
  '{"domains": ["taleo.net"], "ids": ["taleo-"], "css_classes": ["taleoContent"]}',
  '{"base_patterns": ["/careersection/rest/jobboard"], "public_api": false}',
  '{"methods": ["email_password"], "captcha": "recaptcha_v2"}',
  'partial',
  'Limited API access, complex authentication',
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP()
);
```

---

### Configuration Objects

#### Research Configuration

**File:** `config/install/job_hunter.company_research.settings.yml`

```yaml
# Company Research Settings

# Cache TTL (in seconds)
cache_ttl: 2592000  # 30 days

# Research timeout (in seconds)
research_timeout: 300  # 5 minutes

# Max concurrent research jobs
max_concurrent_jobs: 5

# Enable deep scanning
deep_scan_enabled: true

# Google Custom Search API
google_search_api:
  enabled: true
  api_key: ''
  search_engine_id: ''
  
# LinkedIn API
linkedin_api:
  enabled: false
  client_id: ''
  client_secret: ''

# Network traffic analysis
network_analysis:
  enabled: true
  headless_browser: 'chrome'  # chrome, firefox, or phantomjs
  timeout: 60

# ATS Detection
ats_detection:
  confidence_threshold: 0.7  # 0.0 to 1.0
  fallback_to_manual: true

# Authentication Analysis
auth_analysis:
  test_registration: false  # Don't actually register
  capture_forms: true
  analyze_verification: true

# Logging
logging:
  level: 'info'  # debug, info, warning, error
  log_http_requests: true
  log_detection_details: true
```

---

## API Integrations

### Required Third-Party APIs

#### 1. Google Custom Search API

**Purpose:** Company website discovery

**Setup:**
1. Create Google Cloud Project
2. Enable Custom Search API
3. Create API credentials
4. Set up Custom Search Engine
5. Configure in module settings

**API Endpoint:**
```
GET https://www.googleapis.com/customsearch/v1
Parameters:
  - key: API key
  - cx: Search engine ID
  - q: Query string
```

**Rate Limits:**
- Free tier: 100 queries/day
- Paid tier: 10,000 queries/day

---

#### 2. LinkedIn Company API (Optional)

**Purpose:** Company information verification

**Setup:**
1. Create LinkedIn Developer App
2. Request API access
3. Obtain OAuth credentials
4. Configure in module settings

**API Endpoint:**
```
GET https://api.linkedin.com/v2/organizations
Headers:
  - Authorization: Bearer {access_token}
```

**Rate Limits:**
- Varies by access level

---

#### 3. Headless Browser (Puppeteer/Playwright)

**Purpose:** Network traffic analysis and JavaScript rendering

**Options:**

**A. Puppeteer (Chrome/Chromium)**
```javascript
// Example usage pattern
const browser = await puppeteer.launch({
  headless: true,
  args: ['--no-sandbox']
});

const page = await browser.newPage();
await page.setRequestInterception(true);

// Capture API calls
page.on('request', request => {
  // Log API requests
});

await page.goto(careersUrl);
await browser.close();
```

**B. Playwright (Multi-browser)**
```javascript
// Example usage pattern
const browser = await playwright.chromium.launch();
const page = await browser.newPage();

// Monitor network
page.on('request', request => {
  // Analyze request
});

await page.goto(careersUrl);
await browser.close();
```

**PHP Integration:**
- Use Node.js as separate service
- PHP calls Node service via HTTP
- Or use existing PHP Puppeteer libraries

---

## Authentication Strategies

### Supported Authentication Methods

#### 1. Email/Password Registration

**Flow:**
```
1. User fills registration form
2. System validates inputs
3. Password hashed and stored
4. Verification email sent
5. User clicks email link
6. Account activated
7. User can log in
```

**Detection Points:**
- Form field analysis
- Password requirements
- Email verification flow
- Activation endpoints

---

#### 2. SSO (Single Sign-On)

**Common Providers:**

**A. Google OAuth 2.0**
```
Flow:
1. User clicks "Sign in with Google"
2. Redirect to Google auth
3. User grants permissions
4. Redirect back with auth code
5. Exchange code for tokens
6. Create/link account
```

**B. LinkedIn OAuth 2.0**
```
Flow:
1. User clicks "Sign in with LinkedIn"
2. Redirect to LinkedIn auth
3. User grants permissions
4. Redirect back with auth code
5. Exchange code for tokens
6. Create/link account
```

**C. Microsoft Azure AD / Office 365**
```
Flow:
1. User clicks "Sign in with Microsoft"
2. Redirect to Microsoft auth
3. User authenticates
4. SAML assertion returned
5. Account created/linked
```

**Detection:**
- Look for OAuth redirect URLs
- Detect provider-specific buttons
- Analyze SAML metadata

---

#### 3. SAML Integration

**Flow:**
```
1. User initiates login
2. Redirect to IdP
3. IdP authenticates user
4. SAML assertion sent to SP
5. SP validates assertion
6. User session created
```

**Detection:**
- Look for SAML metadata endpoint
- Check for IdP configuration
- Analyze assertion consumer service

---

#### 4. Two-Factor Authentication

**Methods:**

**A. TOTP (Time-based One-Time Password)**
- Authenticator apps (Google Authenticator, Authy)
- QR code setup
- 6-digit codes

**B. SMS-based**
- Phone number registration
- SMS code delivery
- Code validation

**C. Email-based**
- Email code delivery
- Limited time validity

**Detection:**
- Check 2FA setup flow
- Analyze login requirements
- Document enforcement level (optional/required)

---

### Bot Prevention & CAPTCHA

#### 1. Google reCAPTCHA v2

**Detection:**
```html
<script src="https://www.google.com/recaptcha/api.js"></script>
<div class="g-recaptcha" data-sitekey="..."></div>
```

**Characteristics:**
- Visible checkbox
- Image challenges
- Site key required

---

#### 2. Google reCAPTCHA v3

**Detection:**
```html
<script src="https://www.google.com/recaptcha/api.js?render=SITE_KEY"></script>
```

**Characteristics:**
- Invisible to user
- Risk score based
- No user interaction

---

#### 3. hCaptcha

**Detection:**
```html
<script src="https://hcaptcha.com/1/api.js"></script>
<div class="h-captcha" data-sitekey="..."></div>
```

**Characteristics:**
- Privacy-focused alternative
- Image challenges
- Similar to reCAPTCHA v2

---

#### 4. Custom CAPTCHA

**Detection:**
- Custom image generation
- Math problems
- Custom verification logic

---

### Automation Readiness Assessment

**Levels:**

#### Full Automation Ready
- Public API available
- Simple email/password auth
- No CAPTCHA or bypassable
- No manual verification required
- Clear documentation

**Examples:**
- Greenhouse (with public API)
- Lever (with API access)

---

#### Partial Automation Ready
- Limited API access
- CAPTCHA present but workable
- Email verification required
- Manual steps for account setup

**Examples:**
- Workday (API but restricted)
- SmartRecruiters

---

#### Manual Only
- No public API
- Strong CAPTCHA
- Phone verification required
- Manual approval required
- Complex auth flow

**Examples:**
- Custom ATS systems
- High-security employers

---

## Implementation Roadmap

### Phase 1: Foundation (Week 1-2)

**Tasks:**
1. Create database tables
2. Set up configuration schemas
3. Implement CompanyDiscoveryService
4. Implement CareersPageDiscoveryService
5. Create basic UI for manual testing
6. Add logging infrastructure

**Deliverables:**
- Database schema installed
- Basic company website discovery working
- Careers page discovery working
- Simple test interface

---

### Phase 2: ATS Detection (Week 3-4)

**Tasks:**
1. Implement ATSDetectionService
2. Create ATS platform reference data
3. Add domain-based detection
4. Add HTML/CSS pattern detection
5. Add JavaScript library detection
6. Build confidence scoring

**Deliverables:**
- Working ATS detection
- Support for top 5 ATS platforms
- Detection confidence metrics
- Test coverage

---

### Phase 3: API Discovery (Week 5-6)

**Tasks:**
1. Implement APIDiscoveryService
2. Add network traffic analysis
3. Create platform-specific API patterns
4. Document API endpoints
5. Test API accessibility

**Deliverables:**
- API endpoint discovery working
- Platform-specific patterns for top ATS
- API documentation capture
- Test coverage

---

### Phase 4: Authentication Analysis (Week 7-8)

**Tasks:**
1. Implement AuthenticationAnalysisService
2. Add registration flow analysis
3. Add SSO provider detection
4. Add verification requirement analysis
5. Add login process analysis
6. Add CAPTCHA detection

**Deliverables:**
- Complete authentication analysis
- SSO provider detection
- CAPTCHA identification
- Automation readiness scoring

---

### Phase 5: Integration & Polish (Week 9-10)

**Tasks:**
1. Integrate all services
2. Build CompanyResearchService orchestrator
3. Create user-facing UI
4. Add caching layer
5. Implement batch processing
6. Add error handling
7. Create comprehensive documentation

**Deliverables:**
- Complete end-to-end flow
- Production-ready UI
- Batch processing capability
- Full documentation

---

### Phase 6: Testing & Deployment (Week 11-12)

**Tasks:**
1. Unit test all services
2. Integration testing
3. Performance testing
4. Security audit
5. User acceptance testing
6. Production deployment

**Deliverables:**
- Full test coverage
- Performance benchmarks
- Security clearance
- Production deployment

---

## Security Considerations

### Data Privacy
- Store only necessary data
- Encrypt sensitive credentials
- GDPR compliance for EU companies
- Regular data cleanup

### Rate Limiting
- Respect robots.txt
- Implement delays between requests
- Honor API rate limits
- Graceful degradation on limits

### Authentication Security
- Never store plain passwords in tests
- Secure credential storage
- Encrypted communication only (HTTPS)
- No actual account creation in testing

### Bot Detection Avoidance
- Use realistic user agents
- Reasonable request timing
- Don't overwhelm servers
- Respect CAPTCHA challenges

---

## Future Enhancements

### Advanced Features
- Machine learning for ATS detection
- Historical tracking of ATS changes
- Company culture/review integration
- Salary data integration
- Application difficulty scoring

### Platform Expansion
- Support for niche ATS platforms
- International ATS systems
- Government job portals
- University career systems

### Automation
- Automatic account creation (with consent)
- CAPTCHA solving services integration
- Email verification automation
- Auto-apply pipeline integration

---

## Appendix

### A. Common ATS Platforms Reference

| Platform | Market Share | Automation Level | API Available |
|----------|--------------|------------------|---------------|
| Workday | 15-20% | Partial | Yes (restricted) |
| Greenhouse | 10-15% | Full | Yes (public) |
| Taleo | 8-12% | Partial | Limited |
| Lever | 5-8% | Full | Yes (public) |
| SmartRecruiters | 5-8% | Partial | Yes (restricted) |
| iCIMS | 5-8% | Partial | Yes (restricted) |
| BambooHR | 3-5% | Partial | Yes (limited) |
| JobVite | 3-5% | Partial | Yes (limited) |
| Custom/Other | 30-40% | Varies | Varies |

### B. Common Careers Page URLs

```
/careers
/jobs
/opportunities
/work-with-us
/join-us
/employment
/hiring
/open-positions
/job-openings
/about/careers
/company/careers
/company/jobs
```

### C. Common Careers Subdomains

```
careers.{domain}
jobs.{domain}
hiring.{domain}
recruiting.{domain}
apply.{domain}
talent.{domain}
```

### D. SSO Provider Detection Patterns

```javascript
{
  "google": {
    "urls": ["accounts.google.com", "google.com/oauth"],
    "buttons": ["Sign in with Google", "Continue with Google"],
    "icons": ["google-icon", "fab-google"]
  },
  "linkedin": {
    "urls": ["linkedin.com/oauth", "www.linkedin.com/uas"],
    "buttons": ["Sign in with LinkedIn", "Continue with LinkedIn"],
    "icons": ["linkedin-icon", "fab-linkedin"]
  },
  "microsoft": {
    "urls": ["login.microsoftonline.com", "login.live.com"],
    "buttons": ["Sign in with Microsoft", "Sign in with Office 365"],
    "icons": ["microsoft-icon", "fab-microsoft"]
  }
}
```

---

## Document History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-02-13 | GitHub Copilot Agent | Initial design document |

---

**End of Design Document**
