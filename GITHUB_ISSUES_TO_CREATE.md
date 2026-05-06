# GitHub Issues to Create for Job Hunter Module Code Review

This document lists all critical and high-priority issues identified in the code review that should be created as GitHub issues.

---

## 🔴 CRITICAL SECURITY ISSUES

### Issue 1: CSRF Protection Disabled on Multiple AJAX Routes

**Labels:** `security`, `bug`, `priority:critical`

**Description:**
Multiple AJAX endpoints in `job_hunter.routing.yml` have CSRF protection disabled (`_csrf_token: FALSE`), creating a security vulnerability.

**Affected Routes:**
- Line 242: `job_hunter.job_discovery_search_ajax`
- Line 252: `job_hunter.save_job`
- Line 442: `job_hunter.tailor_resume_ajax`
- Line 462: `job_hunter.add_skill_to_profile_ajax`
- Line 472: `job_hunter.refresh_skills_gap_ajax`

**Impact:**
- **Severity**: Critical
- **Risk**: CSRF attacks could allow unauthorized actions on behalf of authenticated users
- Cross-site request forgery could manipulate job applications, profiles, and saved jobs

**Recommendation:**
Change from:
```yaml
options:
  _csrf_token: FALSE
```

To:
```yaml
options:
  _csrf_request_header_mode: 'true'
```

**Reference:** `CODE_REVIEW_job_hunter.routing.yml.md`

---

### Issue 2: SQL Injection Vulnerability in UserProfileController

**Labels:** `security`, `bug`, `priority:critical`

**Description:**
SQL injection vulnerability in `UserProfileController::queueStatus()` method where user input is used in LIKE queries without proper sanitization.

**Location:** `src/Controller/UserProfileController.php` lines 2127-2142

**Impact:**
- **Severity**: Critical
- **Risk**: Could allow attackers to execute arbitrary SQL queries
- Database compromise, data exfiltration, or data manipulation

**Recommendation:**
- Use parameterized queries with proper escaping
- Use Drupal's database API with placeholders
- Validate and sanitize all user input before using in queries

**Reference:** `src/Controller/CODE_REVIEW_UserProfileController.md`

---

### Issue 3: Path Traversal Vulnerability in DocumentationController

**Labels:** `security`, `bug`, `priority:critical`

**Description:**
Path traversal vulnerability in `DocumentationController::viewDocument()` where user-supplied `$file` parameter is used directly in file path without validation.

**Location:** `src/Controller/DocumentationController.php` line 85

**Impact:**
- **Severity**: Critical
- **Risk**: Could allow reading arbitrary files on the server
- Information disclosure (e.g., `../../etc/passwd`, configuration files)

**Recommendation:**
- Validate filename against a whitelist of allowed documentation files
- Use `basename()` to strip directory traversal sequences
- Implement proper path validation before file access

**Reference:** `src/Controller/CODE_REVIEW_DocumentationController.php.md`

---

### Issue 4: Missing File Upload Validation in ResumeController

**Labels:** `security`, `bug`, `priority:critical`

**Description:**
Resume upload functionality lacks comprehensive security validation including file type verification, size limits, and malicious content scanning.

**Location:** `src/Controller/ResumeController.php`

**Impact:**
- **Severity**: Critical
- **Risk**: Malicious file uploads, potential code execution, denial of service
- Path traversal risks in file handling

**Recommendation:**
- Implement strict MIME type validation
- Add file size limits
- Validate file extensions against whitelist
- Sanitize filenames to prevent path traversal
- Scan uploads for malicious content

**Reference:** `src/Controller/CODE_REVIEW_ResumeController.php.md`

---

### Issue 5: XSS Vulnerabilities in Multiple Controllers

**Labels:** `security`, `bug`, `priority:high`

**Description:**
Multiple controllers use `#markup` with dynamic content without proper XSS protection. Unescaped user input could lead to cross-site scripting attacks.

**Affected Files:**
- `CompanyController.php` - 26 instances of `#markup` (Line references in review)
- `WorkflowController.php` - Unescaped user input in HTML
- `CompanyResearchController.php` - Missing XSS protection

**Impact:**
- **Severity**: High
- **Risk**: Cross-site scripting attacks
- Session hijacking, credential theft, malicious redirects

**Recommendation:**
- Use `#plain_text` for user-generated content
- Properly escape all dynamic content in `#markup`
- Use Drupal's sanitization APIs (e.g., `Html::escape()`, `Xss::filter()`)
- Audit all uses of `#markup` with variables

**Reference:** Multiple `CODE_REVIEW_*Controller.php.md` files

---

## 🔴 CRITICAL ARCHITECTURE ISSUES

### Issue 6: Empty hook_schema() Bypasses Drupal Schema Management

**Labels:** `architecture`, `bug`, `priority:high`

**Description:**
`job_hunter.install` returns an empty array in `hook_schema()` which defeats Drupal's schema management system. Tables are created manually in `hook_install()` and won't be tracked by Drupal.

**Location:** `job_hunter.install` lines 15-18

**Impact:**
- **Severity**: High
- Makes updates and schema changes difficult
- No automatic schema validation
- Cannot use `hook_update_N()` effectively for schema changes
- Orphaned tables during uninstall

**Recommendation:**
- Either: Implement proper `hook_schema()` and let Drupal manage tables
- Or: Document why this anti-pattern is necessary and provide alternative management strategy
- Add configuration option for data preservation behavior during uninstall

**Reference:** `CODE_REVIEW_job_hunter.install.md`

---

### Issue 7: Monolithic CSS File (job_hunter.css) is Unmaintainable

**Labels:** `css`, `refactoring`, `priority:high`

**Description:**
The main `job_hunter.css` file is 1,322 lines long, combining multiple concerns and making it unmaintainable.

**Location:** `css/job_hunter.css`

**Impact:**
- **Severity**: High (blocks maintainability)
- **DO NOT MERGE** in current state
- Difficult to debug and modify
- Poor performance due to lack of selective loading
- Code duplication and conflicts

**Recommendation:**
Split into 5-6 modular files:
- `job-hunter-base.css` - Core variables and utilities
- `job-hunter-layout.css` - Layout and grid systems
- `job-hunter-components.css` - Reusable components
- `job-hunter-forms.css` - Form-specific styles
- `job-hunter-tables.css` - Table layouts
- `job-hunter-responsive.css` - Media queries

**Reference:** `css/CODE_REVIEW_job_hunter.css.md`, `css/CODE_REVIEW_SUMMARY.md`

---

### Issue 8: CSS Syntax Error in job-search-results.css

**Labels:** `bug`, `css`, `priority:high`

**Description:**
Syntax error on line 107 with extra space in selector `. diagnostic-info` should be `.diagnostic-info`

**Location:** `css/job-search-results.css` line 107

**Impact:**
- **Severity**: High
- Selector will not match any elements
- Diagnostic info styling will not be applied

**Fix:**
```css
/* Change from: */
. diagnostic-info {

/* To: */
.diagnostic-info {
```

**Reference:** `css/CODE_REVIEW_job-search-results.css.md`, `css/CODE_REVIEW_SUMMARY.md`

---

## 🟠 HIGH PRIORITY ISSUES

### Issue 9: N+1 Query Problem in CompanyResearchController

**Labels:** `performance`, `bug`, `priority:high`

**Description:**
Database performance issue executing 201 queries instead of 1 for 100 companies. The code fetches companies in one query, then loops through executing 2 queries per company.

**Location:** `src/Controller/CompanyResearchController.php` lines 22-42

**Impact:**
- **Severity**: High
- Severe performance degradation with many companies
- Database load increases linearly with number of companies
- Slow page load times

**Recommendation:**
- Use a single aggregated query with JOINs and GROUP BY
- Or load all counts in two bulk queries before the loop
- Implement caching for company statistics

**Reference:** `src/Controller/CODE_REVIEW_CompanyResearchController.php.md`

---

### Issue 10: Race Condition in JobPostingController Queue Processing

**Labels:** `bug`, `priority:high`

**Description:**
Race condition between status reset and queue item creation in `retryParsing()` method. Another process could read stale data between operations.

**Location:** `src/Controller/JobPostingController.php` lines 44-56

**Impact:**
- **Severity**: High
- Data inconsistency in queue processing
- Job might be processed with wrong status
- Potential duplicate processing

**Recommendation:**
- Wrap both operations in a database transaction
- Use proper locking mechanisms
- Ensure atomic updates

**Reference:** `src/Controller/CODE_REVIEW_JobPostingController.php.md`

---

### Issue 11: Variable Shadowing Bug in WorkflowController

**Labels:** `bug`, `priority:high`

**Description:**
Variable name inconsistency/shadowing bug where `$content` is declared but then `$build` is used inconsistently throughout the method.

**Location:** `src/Controller/WorkflowController.php` lines 24-60

**Impact:**
- **Severity**: High
- Code may not work as intended
- Array structure confusion
- Potential undefined index errors

**Recommendation:**
- Use consistent variable naming throughout method
- Choose either `$content` or `$build` and stick with it
- Review and test functionality after fix

**Reference:** `src/Controller/CODE_REVIEW_WorkflowController.php.md`

---

### Issue 12: Excessive !important Usage in company-profile.css

**Labels:** `css`, `refactoring`, `priority:high`

**Description:**
18+ instances of `!important` in `company-profile.css` making styles unmaintainable. Also uses invalid `:contains()` pseudo-class that doesn't work in CSS.

**Location:** `css/company-profile.css` (lines 196, 199, 200, 201, 203-207, 212-218, 250, 253-256, 262-265, 334-344, 346, 348-350, 352-356, 369-378)

**Impact:**
- **Severity**: High
- Unmaintainable cascade
- Selector specificity issues
- `:contains()` selectors won't work (CSS doesn't support this)

**Recommendation:**
- Remove all `!important` declarations
- Refactor selectors to use appropriate specificity
- Fix or remove `:contains()` selectors
- Use proper CSS architecture patterns

**Reference:** `css/CODE_REVIEW_company-profile.css.md`

---

### Issue 13: Hardcoded Company ID in Cron Job

**Labels:** `bug`, `priority:high`

**Description:**
Cron job in `job_hunter.module` assumes a default company with ID 1 exists, which may not be true in all installations.

**Location:** `job_hunter.module` line 382

**Impact:**
- **Severity**: High
- Cron job failures on fresh installations
- Data association errors
- Job import issues

**Recommendation:**
- Check if default company exists, create if needed
- Use configuration to specify default company ID
- Add validation in hook_install() to ensure default company exists

**Reference:** `CODE_REVIEW_job_hunter.module.md`

---

### Issue 14: Login Redirect Override Affects All Users

**Labels:** `ux`, `bug`, `priority:medium`

**Description:**
All user logins are automatically redirected to job hunter dashboard regardless of user role or intent.

**Location:** `job_hunter.module` lines 492-503

**Impact:**
- **Severity**: Medium
- Poor user experience for non-job-seekers
- Unexpected behavior
- May interfere with other modules

**Recommendation:**
- Make redirect conditional based on user role
- Add configuration option to enable/disable redirect
- Only redirect users with job seeker role or preference
- Check destination parameter before redirecting

**Reference:** `CODE_REVIEW_job_hunter.module.md`

---

## 🟡 MEDIUM PRIORITY ISSUES

### Issue 15: Missing Accessibility Features in JavaScript

**Labels:** `accessibility`, `a11y`, `enhancement`, `priority:medium`

**Description:**
Multiple JavaScript files lack proper accessibility features including keyboard navigation, ARIA attributes, and screen reader support.

**Affected Files:**
- `js/queue-controls.js` - No keyboard support
- `js/target-companies.js` - Missing ARIA labels
- `js/job-discovery.js` - Poor focus management
- `js/google-jobs-integration.js` - No screen reader support

**Impact:**
- **Severity**: Medium
- WCAG compliance failures
- Inaccessible to keyboard users
- Poor screen reader experience

**Recommendation:**
- Add keyboard event handlers for all interactive elements
- Implement proper ARIA attributes (aria-label, aria-describedby, role)
- Manage focus states properly
- Test with screen readers
- Add skip links for complex interactions

**Reference:** Multiple `js/CODE_REVIEW_*.md` files, `js/CODE_REVIEW_INDEX.md`

---

### Issue 16: No Caching on External API Services

**Labels:** `performance`, `enhancement`, `priority:medium`

**Description:**
External API services (Google Jobs, SerpAPI, Adzuna, USAJobs) have no caching implementation, leading to repeated API calls and quota exhaustion.

**Affected Files:**
- `src/Service/GoogleJobsService.php`
- `src/Service/SerpApiService.php`
- `src/Service/AdzunaApiService.php`
- `src/Service/UsaJobsApiService.php`

**Impact:**
- **Severity**: Medium
- API quota exhaustion
- Poor performance
- Unnecessary API costs
- Rate limiting issues

**Recommendation:**
- Implement response caching with appropriate TTL
- Cache search results for common queries
- Add cache invalidation strategy
- Document cache configuration options

**Reference:** Multiple `src/Service/CODE_REVIEW_*.md` files

---

### Issue 17: Missing Rate Limiting on API Services

**Labels:** `security`, `performance`, `priority:medium`

**Description:**
No rate limiting implementation on external API services, risking quota exhaustion and potential service blocking.

**Affected Files:**
- All services in `src/Service/` that call external APIs

**Impact:**
- **Severity**: Medium
- API quota exhaustion
- Service blocking by providers
- Cost overruns

**Recommendation:**
- Implement rate limiting with configurable thresholds
- Add queue-based throttling for API calls
- Track API usage metrics
- Add alerts for quota approaching limits

**Reference:** `src/Service/CODE_REVIEW_INDEX.md`

---

### Issue 18: Hardcoded Colors Throughout CSS Files

**Labels:** `css`, `refactoring`, `priority:medium`

**Description:**
100+ lines of hardcoded color values duplicated across 15 of 16 CSS files. No use of CSS custom properties.

**Affected Files:**
- Nearly all CSS files except `documentation.css`

**Impact:**
- **Severity**: Medium
- Inconsistent theming
- Hard to maintain brand colors
- Difficult to implement dark mode or themes

**Recommendation:**
- Create CSS custom properties file with color variables
- Replace all hardcoded colors with variables
- Example:
```css
:root {
  --primary-color: #0073aa;
  --secondary-color: #23282d;
  --success-color: #46b450;
  /* etc */
}
```

**Reference:** `css/CODE_REVIEW_SUMMARY.md`

---

### Issue 19: Missing Focus States in CSS (Accessibility)

**Labels:** `accessibility`, `css`, `a11y`, `priority:medium`

**Description:**
14 of 16 CSS files are missing focus states for interactive elements, violating WCAG accessibility guidelines.

**Affected Files:**
- Most CSS files in `css/` directory

**Impact:**
- **Severity**: Medium
- WCAG 2.1 Level AA compliance failure
- Poor keyboard navigation experience
- Inaccessible to keyboard-only users

**Recommendation:**
- Add visible focus states for all interactive elements:
```css
.button:focus,
.link:focus {
  outline: 2px solid var(--focus-color);
  outline-offset: 2px;
}
```
- Test with keyboard navigation
- Ensure focus indicators have sufficient contrast

**Reference:** `css/CODE_REVIEW_SUMMARY.md`

---

### Issue 20: Missing Animation Motion Preferences

**Labels:** `accessibility`, `css`, `a11y`, `priority:medium`

**Description:**
5 CSS files use animations without supporting `prefers-reduced-motion` media query, which can cause issues for users with motion sensitivity.

**Affected Files:**
- `tailor-resume.css`
- `queue-controls.css`
- `google-jobs-integration.css`
- Others with animations

**Impact:**
- **Severity**: Medium
- Accessibility issue for users with vestibular disorders
- WCAG 2.1 compliance concern
- Poor user experience for motion-sensitive users

**Recommendation:**
```css
@media (prefers-reduced-motion: reduce) {
  * {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
  }
}
```

**Reference:** `css/CODE_REVIEW_SUMMARY.md`

---

## 📋 Summary Statistics

**Total Issues to Create:** 20

### By Severity:
- 🔴 **Critical Security**: 5 issues
- 🔴 **Critical Architecture**: 3 issues
- 🟠 **High Priority**: 6 issues
- 🟡 **Medium Priority**: 6 issues

### By Category:
- **Security**: 6 issues
- **Performance**: 3 issues
- **Architecture**: 2 issues
- **CSS/Frontend**: 5 issues
- **Accessibility**: 4 issues

### By Component:
- **Controllers**: 6 issues
- **CSS**: 5 issues
- **Services**: 3 issues
- **Module Core**: 3 issues
- **JavaScript**: 2 issues
- **Configuration**: 1 issue

---

## 📝 Notes for Issue Creation

1. **Labels to use consistently:**
   - Severity: `priority:critical`, `priority:high`, `priority:medium`, `priority:low`
   - Type: `bug`, `security`, `enhancement`, `refactoring`
   - Area: `css`, `javascript`, `php`, `architecture`, `performance`, `accessibility`

2. **Each issue should include:**
   - Clear description of the problem
   - Specific file locations and line numbers
   - Impact assessment
   - Concrete recommendations
   - Reference to relevant code review document

3. **Security issues should:**
   - Be marked private if they contain exploit details
   - Be prioritized for immediate attention
   - Include CVE references if applicable

4. **Consider creating:**
   - Milestone: "Code Review Remediation"
   - Project board to track progress
   - Epic issue to group related problems

---

## 🔗 References

All issues reference detailed code review documents located in:
- `/job_hunter/CODE_REVIEW_*.md` (module files)
- `/job_hunter/src/Controller/CODE_REVIEW_*.md` (controllers)
- `/job_hunter/src/Service/CODE_REVIEW_*.md` (services)
- `/job_hunter/src/Form/CODE_REVIEW_*.md` (forms)
- `/job_hunter/js/CODE_REVIEW_*.md` (JavaScript)
- `/job_hunter/css/CODE_REVIEW_*.md` (CSS)

Index files provide cross-file analysis:
- `src/Controller/CODE_REVIEW_INDEX.md`
- `src/Service/CODE_REVIEW_INDEX.md`
- `src/Form/CODE_REVIEW_INDEX.md`
- `js/CODE_REVIEW_INDEX.md`
- `css/CODE_REVIEW_SUMMARY.md`
