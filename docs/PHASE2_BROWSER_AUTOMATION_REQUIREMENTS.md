# Phase 2: Browser Automation — Requirements

**Module:** job_hunter  
**Status:** 🟠 Requirements Defined — Not Yet Implemented  
**Depends On:** Phase 1 (✅), Phase 1.5 Credential Storage (✅)  
**Related Docs:**
- [`STEP3_APPLICATION_SUBMISSION_DESIGN.md`](STEP3_APPLICATION_SUBMISSION_DESIGN.md)
- [`STEP3_APPLICATION_SUBMISSION_PROGRESS.md`](STEP3_APPLICATION_SUBMISSION_PROGRESS.md)
- [`PLAYWRIGHT_BRIDGE_SPEC.md`](PLAYWRIGHT_BRIDGE_SPEC.md)
- [`CREDENTIAL_STORAGE_SECURITY.md`](CREDENTIAL_STORAGE_SECURITY.md)

---

## 1. Objective

Phase 2 activates automated form submission for job applications on supported ATS platforms. When the system detects a known ATS and user profile data is available, it should:

1. Navigate to the employer's apply page via a headless browser
2. Fill all available form fields from the user's consolidated profile
3. Upload the job-tailored resume PDF
4. Answer required screening questions where possible
5. Submit the form
6. Capture the confirmation number / confirmation page screenshot
7. Update `jobhunter_applications.application_status = 'submitted'`
8. Log the full attempt to `jobhunter_application_attempts`

If automation fails at any point, the system must fall back gracefully to `manual_required` with a pre-filled field map and a direct apply link, without losing any user data.

---

## 2. Scope

### 2.1 In Scope (Phase 2)

**Track A — No-Login ATS (public apply forms):**

| Platform | Notes |
|---|---|
| Greenhouse | Standard `boards.greenhouse.io` form — well-structured, predictable fields |
| Lever | Standard `jobs.lever.co` form — minimal fields, file upload |
| Ashby | `jobs.ashbyhq.com` — Greenhouse-like structure |
| SmartRecruiters | `jobs.smartrecruiters.com` — multi-step form |
| Workable | `apply.workable.com` — single-page form |

**Track B — Login-Required ATS (stored credentials required):**

| Platform | Notes |
|---|---|
| Workday | Per-company subdomain `*.myworkdayjobs.com`. Requires account login + multi-step form. Highest priority given market penetration. |
| iCIMS | `*.icims.com` — account-based, resume upload + form fill |
| USAJobs | `usajobs.gov` — federal jobs, complex multi-step. Handle as high-value separate implementation. |
| Taleo | `*.taleo.net` — legacy Oracle system, login + lengthy form |
| SuccessFactors | `*.sap-successfactors.com` — SAP-based, login required |

### 2.2 Out of Scope (Phase 2)

- Wellfound / AngelList (requires OAuth flow)
- LinkedIn Easy Apply (ToS concerns, anti-scraping)
- Indeed Apply (ToS concerns)
- Any platform using CAPTCHA on the main apply form (use manual fallback)
- Multi-round multi-interview scheduling
- Reference submission automation

---

## 3. Architecture Decision: Browser Bridge Approach

### 3.1 Recommendation: Node.js Playwright Subprocess

**Rationale:**
- Playwright (Microsoft) is the most capable headless browser library available — superior to Puppeteer for modern SPAs, handles Shadow DOM, network interception, file upload natively
- Runs as an isolated subprocess — PHP shell-execs `node playwright-apply.js --payload='...'`
- No extra HTTP infrastructure needed for a single-instance deployment
- Playwright includes built-in anti-detection (stealth mode via `playwright-extra`)
- JSON over stdout/stderr for clean PHP ↔ Node IPC

**Alternative Considered: PHP Panther (Symfony)**
- Pure PHP, no Node.js dependency
- Uses ChromeDriver or Selenium Grid
- Substantially less capable with modern React/Angular ATS forms
- Anti-detection not built-in
- ❌ Rejected: modern ATS forms (especially Workday) are SPAs that Panther struggles with

**Alternative Considered: Dedicated Microservice**
- REST API server (Express + Playwright) running as a separate process/container
- Better for horizontal scaling
- More infrastructure overhead for a single-user system
- ❌ Deferred to Phase 3 if volume demands scaling

### 3.2 Infrastructure Requirements

```
Server Requirements:
- Node.js >= 18.x
- Playwright: npm install playwright @playwright/test playwright-extra puppeteer-extra-plugin-stealth
- Chromium: npx playwright install chromium
- Write access to /tmp for screenshots and resume upload staging
```

**File locations:**
```
web/modules/custom/job_hunter/
├── playwright/
│   ├── apply.js              # Main entry point (dispatches by ATS)
│   ├── platforms/
│   │   ├── greenhouse.js
│   │   ├── lever.js
│   │   ├── ashby.js
│   │   ├── smartrecruiters.js
│   │   ├── workable.js
│   │   ├── workday.js
│   │   ├── icims.js
│   │   └── generic.js        # Fallback heuristic fill
│   ├── utils/
│   │   ├── stealth.js        # Anti-detection setup
│   │   ├── resume-upload.js  # File upload helpers
│   │   └── screenshot.js     # Capture confirmation
│   └── package.json
```

### 3.3 PHP–Node Interface

See [`PLAYWRIGHT_BRIDGE_SPEC.md`](PLAYWRIGHT_BRIDGE_SPEC.md) for the full interface contract.

**Summary:**
- PHP calls: `node apply.js --payload='<JSON>'`
- Node writes result JSON to stdout, errors to stderr
- PHP reads stdout, parses JSON, updates DB
- 90-second timeout per attempt; exit code 0 = success, non-zero = failure
- Screenshots written to `private://job_hunter/screenshots/{application_id}.png`

---

## 4. Per-Platform Form Fill Requirements

### 4.1 Greenhouse (`boards.greenhouse.io`)

**Form structure:** Single-page, predictable CSS IDs.

| Field | CSS Selector | Source |
|---|---|---|
| First name | `#first_name` | `personal_info.first_name` |
| Last name | `#last_name` | `personal_info.last_name` |
| Email | `#email` | `personal_info.email` |
| Phone | `#phone` | `personal_info.phone` |
| LinkedIn | `#job_application_linkedin_profile_url` | `profile.linkedin_url` |
| Resume | `#resume` file input | tailored_resume_pdf path |
| Cover letter | `#cover_letter` | tailored_resume.cover_letter (if present) |
| Location | `#location` | `personal_info.city + state` |
| Demographic questions | Various | Skip / answer "Prefer not to say" |

**Submit selector:** `input[type=submit]`, `button[data-submit]`

**Confirmation detection:** URL changes to `/confirmation` OR page contains "Thank you" / "application received"

**Known failure modes:**
- Custom questions beyond standard fields → screenshot + partial-fill result
- Employer-added CAPTCHA → abort, return `manual_required`

### 4.2 Lever (`jobs.lever.co`)

**Form structure:** Clean, minimal — name, email, phone, resume, cover letter.

| Field | CSS Selector | Source |
|---|---|---|
| Name | `input[name=name]` | `personal_info.full_name` |
| Email | `input[name=email]` | `personal_info.email` |
| Phone | `input[name=phone]` | `personal_info.phone` |
| Current company | `input[name=org]` | `experience[0].company` |
| LinkedIn | `input[name=urls[LinkedIn]]` | `profile.linkedin_url` |
| Resume | `input[type=file]` | tailored_resume_pdf path |

**Submit selector:** `button[data-qa=btn-submit-application]`

**Confirmation:** URL contains `/thanks` OR page contains "Application submitted"

### 4.3 Ashby (`jobs.ashbyhq.com`)

Similar structure to Greenhouse. Uses React-rendered forms.

**Note:** Must wait for React hydration before interacting — use `page.waitForSelector()` rather than `page.fill()` immediately.

| Field | Approach |
|---|---|
| Standard fields | Label-based matching: `page.getByLabel('First Name')` |
| Resume upload | `input[type=file]` within `.resume-upload-section` |
| Custom questions | Answer required, skip optional |

**Confirmation:** Toast notification OR URL change to `/applied`

### 4.4 SmartRecruiters (`jobs.smartrecruiters.com`)

**Multi-step form.** Steps: Personal Info → Experience → Questions → Review → Submit.

| Step | Fields |
|---|---|
| Step 1 | firstName, lastName, email, phoneNumber, country, city |
| Step 2 | Resume upload (`input[type=file]`), work experience entries |
| Step 3 | Screening questions — answer required, leave optional blank unless profile has answer |
| Step 4 | Review — click Submit |

**Note:** SmartRecruiters blocks headless detection on some job postings. Must use stealth plugin.

### 4.5 Workable (`apply.workable.com`)

Single-page React form. Fields loaded dynamically.

**Approach:** `page.waitForLoadState('networkidle')` then label-based fill.

### 4.6 Workday (`*.myworkdayjobs.com`)

**Highest priority login-required ATS.** Used by Fortune 500.

**Login flow:**
1. Navigate to `apply_url` — page redirects to login if not authenticated
2. Load credentials from `CredentialManagementService::retrieveCredential($uid, $company_id)`
3. Fill `input[data-automation-id=email]` and `input[data-automation-id=password]`
4. Handle MFA if present (→ abort, return `manual_required` with `reason=mfa_required`)
5. After login, return to apply URL

**Application form:**
- Multi-step wizard — up to 7 steps depending on employer
- Each step: fill visible fields, click "Next" or "Save and Continue"
- Fields use `data-automation-id` attributes (more stable than CSS classes)
- Resume upload: `input[data-automation-id=file-upload-input-ref]`

**Common Workday field mappings:**

| Field | data-automation-id |
|---|---|
| First name | `legalNameSection_firstName` |
| Last name | `legalNameSection_lastName` |
| Email | `email` |
| Phone | `phone-number` |
| Address Line 1 | `addressSection_addressLine1` |
| City | `addressSection_city` |
| State | `addressSection_countryRegion` |
| Zip | `addressSection_postalCode` |
| Resume | `file-upload-input-ref` |
| Work authorization | `radioBtn-true` / `radioBtn-false` in sponsorship section |

**Known failure modes:**
- MFA → `manual_required` with `reason=mfa_required`
- Two-factor email verification → `manual_required`
- CAPTCHA on login → `manual_required`
- Custom employer questions → fill required, skip optional

### 4.7 iCIMS

Login-required. Similar multi-step wizard to Workday.

**Note:** iCIMS forms vary significantly by employer configuration. Use heuristic label-matching as primary strategy.

### 4.8 Generic / Unknown Company Career Pages

Heuristic approach:
1. Look for `<form>` with file upload input (resume)
2. Match labels via fuzzy text: "First Name", "Last Name", "Email", "Phone", "Resume"
3. Fill matched fields
4. Do NOT submit — return `manual_required` with `reason=heuristic_fill_only` and field_map showing what was pre-filled

---

## 5. Resume Upload Requirements

- PDF is the preferred format — upload the job-tailored PDF from `jobhunter_tailored_resumes.resume_pdf_fid`
- If no tailored PDF exists, fall back to the user's base resume PDF from `jobhunter_job_seeker.resume_pdf_fid`
- If no PDF at all → abort with `reason=no_resume_pdf`, return `manual_required`
- File must be copied to a temp path accessible to the Node.js process before the browser call
- Temp file must be deleted after the attempt (success or failure)
- File size limit: 5 MB (most ATS enforce this) — validate before attempting upload

---

## 6. Screenshot and Evidence Capture

Every attempt must capture:
1. **Pre-submit screenshot** — form filled, before clicking submit
2. **Post-submit screenshot** — confirmation page or error state

Storage:
- Path: `private://job_hunter/screenshots/{application_id}_{attempt_n}_{pre|post}.png`
- Linked in `jobhunter_application_attempts.metadata` JSON as `screenshot_pre` and `screenshot_post`
- Retention: 90 days, then auto-delete via cron

Screenshots serve as evidence of submission for the job seeker.

---

## 7. Error Handling and Retry Logic

### 7.1 Failure Modes and Outcomes

| Scenario | `outcome` | `reason` | Retry? |
|---|---|---|---|
| CAPTCHA detected | `manual_required` | `captcha_detected` | No |
| MFA required | `manual_required` | `mfa_required` | No |
| Invalid credentials | `manual_required` | `auth_failed` | No |
| Required field not in profile | `manual_required` | `missing_required_field` | After profile update |
| No resume PDF | `manual_required` | `no_resume_pdf` | After upload |
| Timeout (> 90s) | `manual_required` | `timeout` | Yes (up to 3x) |
| Network error | `manual_required` | `network_error` | Yes (up to 3x) |
| JS error on ATS page | `manual_required` | `ats_page_error` | Once |
| Unexpected page structure | `manual_required` | `selector_not_found` | No |
| Successful confirmation | `submitted` | — | — |
| Partial fill, no confirmation | `manual_required` | `heuristic_fill_only` | No |

### 7.2 Retry Policy

- Max `attempt_count`: 3 for timeout/network errors
- After 3 failures: lock to `manual_required` permanently, notify user
- Retry cooldown: 30 minutes between automated retries
- No auto-retry for auth failures, CAPTCHA, MFA — require user action first

### 7.3 Timeout Budget

| Stage | Timeout |
|---|---|
| Page load / navigation | 30s |
| Login form interaction | 20s |
| Each form step | 15s |
| File upload | 30s |
| Confirmation wait | 20s |
| **Total per attempt** | **90s hard limit** |

---

## 8. Anti-Detection Requirements

ATS platforms increasingly detect and block headless browsers. The following must be applied:

1. **Stealth plugin:** Use `playwright-extra` with `puppeteer-extra-plugin-stealth`
2. **User agent:** Rotate realistic desktop user agents (not headless Chromium default)
3. **Viewport:** Set to realistic size (e.g., 1440×900)
4. **Human-like delays:** Random 300–1200ms between keystrokes; 500–2000ms between form steps
5. **Do not use `page.fill()` for sensitive fields** — use `page.type()` with delay to simulate typing
6. **No parallel submissions** — one browser instance per user at a time
7. **IP considerations:** Submissions originate from server IP. If employer blocks, fall back to `manual_required`. Do not use proxy rotation (legal/ToS risk).

---

## 9. Credential Handling in Phase 2

Credentials are already stored encrypted in `jobhunter_employer_credentials` via `CredentialManagementService` (Phase 1.5).

Phase 2 integration:
- PHP retrieves credential via `CredentialManagementService::retrieveCredential($uid, $company_id)`
- Passes `username` and `password` to Node.js via the payload JSON (over encrypted subprocess IPC — not logged)
- Node.js script MUST NOT log credential values
- Credential is zeroed from memory after use
- If login fails → update credential status to `invalid` via PHP after Node returns

See [`CREDENTIAL_STORAGE_SECURITY.md`](CREDENTIAL_STORAGE_SECURITY.md) for full security model.

---

## 10. New Database Requirements

### 10.1 `jobhunter_application_attempts` additions

Column `screenshot_pre_path` varchar(500) — path to pre-submit screenshot  
Column `screenshot_post_path` varchar(500) — path to post-submit screenshot  
Column `confirmation_text` text — extracted confirmation text from page  
Column `confirmation_number` varchar(100) — parsed confirmation/reference number if found

These will be added in DB update `job_hunter_update_9032`.

### 10.2 `jobhunter_applications` addition

Column `confirmed_at` datetime — timestamp when confirmation was captured  
Column `confirmation_ref` varchar(100) — employer confirmation reference number

---

## 11. UI Requirements

### 11.1 Job Detail Page — Apply Section

Current (Phase 1): Shows "✅ Tracked! This job requires manual submission."

Phase 2 additions:
- When `ats_platform` is automatable: Show "🤖 Automating submission..." spinner while processing
- On success: Show "✅ Applied! Confirmation: {ref}" with link to screenshot
- On `manual_required` with `field_map`: Show pre-filled field values as a "Copy to clipboard" helper so users can paste into the form faster
- On `mfa_required`: Show "🔐 MFA detected. Store your credentials and re-queue." with link to credentials settings

### 11.2 My Jobs Page

Phase 2 status badges:

| Status | Badge |
|---|---|
| `submitted` | ✅ Applied |
| `pending` / `queued` | ⏳ Processing |
| `manual_required` + `phase2_pending` | 🤖 Automation soon |
| `manual_required` + `captcha_detected` | 🚫 CAPTCHA |
| `manual_required` + `mfa_required` | 🔐 Needs MFA |
| `manual_required` + `no_credentials` | 🔑 Add Credentials |
| `failed` | ❌ Failed |

### 11.3 Credentials Management UI

Must exist before Phase 2 go-live:
- Route: `/jobhunter/settings/credentials`
- Add/remove employer credentials per platform
- Test credentials button (triggers a verification attempt without submitting)
- Status indicator: Valid / Invalid / Unverified

---

## 12. Implementation Rollout Order

Phase 2 must be implemented in this order to minimize risk and deliver value fastest:

| Priority | Platform | Track | Reason |
|---|---|---|---|
| 1 | **Greenhouse** | A (no login) | Most predictable form, highest usage among tech companies |
| 2 | **Lever** | A (no login) | Second most common, minimal form |
| 3 | **Workday** | B (login) | Highest market share overall — unlocks Fortune 500 |
| 4 | Ashby | A (no login) | Growing adoption in tech startups |
| 5 | SmartRecruiters | A (no login) | Mid-market companies |
| 6 | iCIMS | B (login) | Enterprise employers |
| 7 | Workable | A (no login) | SMB companies |
| 8 | USAJobs | B (login) | Federal/government track |

---

## 13. Acceptance Criteria

### 13.1 Greenhouse (Priority 1 — Gate to Phase 2 launch)

- [ ] Given a Greenhouse job URL and a complete user profile with a tailored PDF:
  - The system navigates to the form without triggering bot detection
  - All standard fields (name, email, phone, LinkedIn, resume) are filled correctly
  - The form is submitted successfully
  - `jobhunter_applications.application_status` is updated to `submitted`
  - Pre- and post-submit screenshots are saved to private storage
  - `jobhunter_application_attempts.outcome = 'submitted'` with `confirmation_text` populated
  - The My Jobs page shows "✅ Applied" badge for the job

### 13.2 Fallback (All Platforms)

- [ ] If CAPTCHA is encountered, outcome is `manual_required` with `reason=captcha_detected`
- [ ] No user data is lost — field_map is always populated even on failure
- [ ] No unhandled exceptions propagate to the Drupal UI
- [ ] All attempts are logged to `jobhunter_application_attempts`
- [ ] Credentials are never logged in plaintext

### 13.3 Workday (Priority 3 — Login Track Gate)

- [ ] Given valid stored Workday credentials:
  - Login completes successfully
  - Application form navigated step by step
  - Required fields filled
  - Resume uploaded
  - Confirmation captured
- [ ] Given invalid credentials: outcome is `manual_required` with `reason=auth_failed`
- [ ] Given MFA prompt: outcome is `manual_required` with `reason=mfa_required`

---

## 14. Security Considerations

1. **Subprocess isolation:** Node.js process has no access to Drupal database directly — all data passed via PHP
2. **Credential transmission:** Passed as JSON payload in subprocess argv — visible to process table. Mitigate: write payload to temp file with 600 permissions, pass file path to Node, Node reads and deletes file
3. **Screenshot storage:** Must use Drupal private filesystem — never public
4. **Rate limiting:** Max 1 automated submission per user per minute, 20 per day
5. **Audit trail:** Every credential retrieval logged via `CredentialManagementService` audit hooks
6. **ToS compliance:** Automation only on public apply forms or employer ATS portals with user's own credentials. Never scrape competitor listings or bypass employer authentication for unauthorized access.

---

## 15. Dependencies and Blockers

| Dependency | Status | Notes |
|---|---|---|
| Phase 1 infra (DB, queue, services) | ✅ Complete | |
| Phase 1.5 credential storage | ✅ Complete | |
| Node.js on server | ⬜ Not confirmed | Verify with hosting environment |
| Playwright npm package | ⬜ Not installed | `npm install` in `playwright/` dir |
| Chromium binary | ⬜ Not installed | `npx playwright install chromium` |
| Private filesystem configured | ⬜ Verify | Needed for screenshots |
| Tailored resume PDF generation | ✅ Exists (`resume_pdf_fid`) | Verify path is accessible to Node subprocess |
| Credentials UI (`/jobhunter/settings/credentials`) | ⬜ Not built | Required before Track B |

---

*Last updated: 2026-02-27*
