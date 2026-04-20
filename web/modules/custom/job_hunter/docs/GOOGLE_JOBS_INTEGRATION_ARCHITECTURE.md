# Google Jobs Integration - Implementation Architecture

**Created**: February 7, 2026  
**Status**: ✅ Complete - Ready for Testing  
**Location**: `/jobhunter/googlejobsintegration`

---

## Overview

The Google Jobs Integration feature provides a complete UI and API for managing Schema.org JobPosting structured data to enable job postings to appear in Google for Jobs search results. This is a client-side focused implementation with full database tracking and validation capabilities.

---

## Database Architecture

### Table: `jobhunter_google_jobs_sync`

Tracks the synchronization status and performance of job postings with Google for Jobs.

**Key Fields**:
- `id` - Primary key
- `job_id` - Foreign key to `jobhunter_job_requirements.id` (unique)
- `structured_data_json` - Generated Schema.org JSON-LD (TEXT)
- `is_enabled` - Whether Google Jobs is active for this job (BOOLEAN)
- `validation_status` - Current status: pending, valid, invalid, error
- `validation_errors` - JSON array of validation errors
- `google_indexing_status` - Status from Search Console API: indexed, pending, error, not_found
- `google_last_crawled` - Timestamp of last Google crawl
- `impressions_count` - Number of impressions in Google Search
- `clicks_count` - Number of clicks from Google Search
- `created`, `updated` - Timestamps

**Indexes**: job_id (unique), validation_status, is_enabled, google_indexing_status

### Table: `jobhunter_google_jobs_validation_log`

Historical log of all validation attempts with detailed error tracking.

**Key Fields**:
- `id` - Primary key
- `sync_id` - Foreign key to `jobhunter_google_jobs_sync.id`
- `job_id` - Foreign key to `jobhunter_job_requirements.id` (denormalized)
- `validation_type` - Type: schema, rich_results, search_console
- `status` - Result: valid, invalid, error, warning
- `errors` - JSON array of error messages
- `warnings` - JSON array of warning messages
- `response_data` - Full response from validation service (TEXT)
- `created` - Timestamp

**Indexes**: sync_id, job_id, validation_type, status, created

---

## Component Architecture

### Controller: `GoogleJobsIntegrationController`

**File**: `src/Controller/GoogleJobsIntegrationController.php`

**Methods**:
- `home()` - Main integration dashboard with statistics
- `jobDetail($job_id)` - Detailed view of single job's integration status
- `toggleJobSync(Request)` - AJAX: Enable/disable integration for a job
- `generateStructuredData(Request)` - AJAX: Generate Schema.org JSON-LD
- `validateStructuredData(Request)` - AJAX: Validate structured data
- `getJobsList()` - AJAX: Get all jobs with sync status

**Helper Methods**:
- `getIntegrationStatistics()` - Calculate dashboard statistics
- `getRecentJobsWithSyncStatus($limit)` - Fetch recent jobs with sync data

### Service: `GoogleJobsService`

**File**: `src/Service/GoogleJobsService.php`

**Core Methods**:
- `generateJobPostingJsonLd($job_id)` - Creates Schema.org JobPosting structure
- `validateJobPosting($job_id)` - Validates required/recommended fields

**Helper Methods**:
- `sanitizeDescription($description)` - Clean job description text
- `mapEmploymentType($type)` - Map to Schema.org standard values
- `buildJobLocation($location_data, $extracted_data)` - Build location structure
- `buildSalaryData($salary_data)` - Build MonetaryAmount structure

**Dependencies**: Database connection, Logger, File URL Generator

### Templates

#### `google-jobs-integration-home.html.twig`

Main dashboard showing:
- Header with title and documentation link
- Statistics cards (total jobs, enabled, valid, invalid)
- Performance metrics (indexed count, impressions, clicks, CTR)
- Recent jobs table with inline actions
- Quick actions (Add Job, Validate All, View Guide)
- Status messages container

**Variables**:
- `stats` - Array of statistics
- `recent_jobs` - Array of job data with sync status
- `documentation_url` - Link to integration guide

#### `google-jobs-job-detail.html.twig`

Job detail page showing:
- Job header (title, company, posted date)
- Sync status card (enabled, validation, indexing, last validated)
- Action buttons (Validate, Regenerate, Enable/Disable)
- Performance metrics (impressions, clicks, CTR)
- Structured data preview (JSON-LD display)
- Validation history table
- Resources links

**Variables**:
- `job` - Job requirement object
- `company` - Company object
- `sync` - Google Jobs sync record
- `validation_log` - Array of validation log entries

---

## Client-Side Implementation

### JavaScript: `google-jobs-integration.js`

**Event Handlers**:
- Refresh statistics button
- Toggle job sync (enable/disable per job)
- Generate structured data button
- Validate structured data button
- Validate all jobs batch processing
- Show status messages (alerts)

**AJAX Endpoints Used**:
- `POST /jobhunter/googlejobsintegration/toggle-sync`
- `POST /jobhunter/googlejobsintegration/generate`
- `POST /jobhunter/googlejobsintegration/validate`
- `GET /jobhunter/googlejobsintegration/jobs-list`

### CSS: `google-jobs-integration.css`

**Styling Features**:
- Gradient header with call-to-action buttons
- Card-based layout for statistics
- Color-coded badges (valid=green, invalid=orange, pending=blue, error=red)
- Hover effects on cards
- Responsive design (mobile-friendly)
- Status message animations (slide-in from right)
- Loading spinners
- Structured data code preview (dark theme)

---

## Routes

All routes require `administer job application automation` permission.

| Route | Path | Purpose |
|-------|------|---------|
| `job_hunter.google_jobs_home` | `/jobhunter/googlejobsintegration` | Main dashboard |
| `job_hunter.google_jobs_job_detail` | `/jobhunter/googlejobsintegration/job/{job_id}` | Job detail page |
| `job_hunter.google_jobs_toggle_sync` | `/jobhunter/googlejobsintegration/toggle-sync` | AJAX toggle |
| `job_hunter.google_jobs_generate_structured_data` | `/jobhunter/googlejobsintegration/generate` | AJAX generate |
| `job_hunter.google_jobs_validate` | `/jobhunter/googlejobsintegration/validate` | AJAX validate |
| `job_hunter.google_jobs_list` | `/jobhunter/googlejobsintegration/jobs-list` | AJAX job list |
| `job_hunter.documentation.google_jobs` | `/jobhunter/documentation/google-jobs-integration` | Documentation |

---

## Service Registration

**File**: `job_hunter.services.yml`

```yaml
job_hunter.google_jobs_service:
  class: Drupal\job_hunter\Service\GoogleJobsService
  arguments: ['@database', '@logger.factory', '@file_url_generator']
```

---

## Library Registration

**File**: `job_hunter.libraries.yml`

```yaml
google_jobs_integration:
  css:
    theme:
      css/google-jobs-integration.css: {}
  js:
    js/google-jobs-integration.js: {}
  dependencies:
    - core/drupal
    - core/jquery
    - core/once
```

---

## Theme Hook Registration

**File**: `job_hunter.module`

```php
function job_hunter_theme() {
  return [
    // ...
    'google_jobs_integration_home' => [
      'variables' => [
        'stats' => [],
        'recent_jobs' => [],
        'documentation_url' => NULL,
      ],
      'template' => 'google-jobs-integration-home',
    ],
    'google_jobs_job_detail' => [
      'variables' => [
        'job' => NULL,
        'company' => NULL,
        'sync' => NULL,
        'validation_log' => [],
      ],
      'template' => 'google-jobs-job-detail',
    ],
  ];
}
```

---

## Navigation Integration

**File**: `job_hunter.links.menu.yml`

```yaml
job_hunter.google_jobs_integration:
  title: 'Google Jobs Integration'
  route_name: job_hunter.google_jobs_home
  description: 'Manage Google for Jobs integration with Schema.org structured data'
  parent: job_hunter.admin
  weight: 4
```

Appears in: **Administration → Job Hunter → Google Jobs Integration**

---

## Data Flow

### 1. Enable Integration for a Job

```
User clicks "Enable" → AJAX POST to toggle-sync
  → Controller updates jobhunter_google_jobs_sync.is_enabled = 1
  → Response returns success
  → UI updates badge to "Pending"
```

### 2. Generate Structured Data

```
User clicks "Generate" → AJAX POST to generate
  → Controller calls GoogleJobsService.generateJobPostingJsonLd(job_id)
  → Service queries job and company data
  → Service builds Schema.org JSON-LD structure
  → Controller saves to jobhunter_google_jobs_sync.structured_data_json
  → Response returns JSON-LD
  → UI logs to console
```

### 3. Validate Structured Data

```
User clicks "Validate" → AJAX POST to validate
  → Controller calls GoogleJobsService.validateJobPosting(job_id)
  → Service generates JSON-LD
  → Service checks required fields (title, description, datePosted, etc.)
  → Service checks field formats (dates, employment types, etc.)
  → Service returns validation result (status, errors, warnings)
  → Controller updates jobhunter_google_jobs_sync (validation_status, errors, last_validated)
  → Controller logs to jobhunter_google_jobs_validation_log
  → Response returns validation result
  → UI updates status badge and shows message
```

### 4. Batch Validate All Jobs

```
User clicks "Validate All" → JS gets all job IDs from table
  → For each job (staggered by 300ms):
    → AJAX POST to validate with job_id
    → Track completed count
  → When all complete:
    → Show summary message
    → Reload page to show updated statuses
```

---

## Schema.org JobPosting Structure

The service generates JSON-LD with these fields:

### Required Fields
- `@context`: "https://schema.org/"
- `@type`: "JobPosting"
- `title`: Job title from jobhunter_job_requirements
- `description`: Sanitized job description
- `datePosted`: ISO 8601 date from created_at
- `hiringOrganization`: Organization object with company name
- `jobLocation`: Place with PostalAddress

### Recommended Fields
- `validThrough`: Expiration date (default +30 days)
- `employmentType`: Array (FULL_TIME, PART_TIME, etc.)
- `baseSalary`: MonetaryAmount with value/range
- `identifier`: PropertyValue with job ID
- `url`: Direct link to job posting

### Optional Fields
- `skills`: Comma-separated list
- `educationRequirements`: EducationalOccupationalCredential
- `experienceRequirements`: OccupationalExperienceRequirements
- `jobLocationType`: TELECOMMUTE for remote jobs

---

## Validation Rules

### Required Field Checks
- Title must exist
- Description must exist and be >50 characters
- datePosted must be valid ISO 8601 format
- hiringOrganization must have name
- jobLocation must have address with at least addressCountry

### Warnings
- Title >80 characters (display truncation)
- Description <200 characters (recommend more detail)
- Missing validThrough (recommend adding)
- Missing employmentType (recommend adding)
- Missing baseSalary (improves visibility)

### Format Validation
- datePosted: YYYY-MM-DD format
- employmentType: Must be one of standard values
- Employment types: FULL_TIME, PART_TIME, CONTRACTOR, TEMPORARY, INTERN, VOLUNTEER, PER_DIEM, OTHER

---

## Integration with Existing Data

### Data Sources
- `jobhunter_job_requirements` - Job details
  - `job_title`, `job_description`, `created_at`
  - `extracted_json` - Parsed job data (description, location, salary, etc.)
  - `skills_required_json` - Skills arrays (must_have, nice_to_have, tech_stack)
- `jobhunter_companies` - Company information
  - `company_name`, `website`, `logo_path`

### Data Mapping
- Job title → `title`
- extracted_json.description → `description`
- created_at → `datePosted`
- Company name → `hiringOrganization.name`
- Company website → `hiringOrganization.sameAs`
- extracted_json.location → `jobLocation.address`
- extracted_json.salary → `baseSalary`
- skills_required_json arrays → `skills`

---

## Future Enhancements

### Phase 2 (Not Yet Implemented)
- Google Search Console API integration for actual indexing status
- Automatic validation on job posting creation/update
- Scheduled validation checks (daily/weekly)
- Rich results preview from Google's API
- Bulk enable/disable for multiple jobs
- Export structured data for manual submission

### Phase 3 (Future)
- A/B testing of different structured data variations
- Performance analytics dashboard with trends
- Automated optimization suggestions
- Integration with Google Analytics for click tracking
- Webhook notifications when Google crawls jobs

---

## Installation & Setup

### 1. Run Database Updates

```bash
drush updatedb
```

This creates the two new tables:
- `jobhunter_google_jobs_sync`
- `jobhunter_google_jobs_validation_log`

### 2. Clear Cache

```bash
drush cr
```

### 3. Access the Interface

Navigate to: **Administration → Job Hunter → Google Jobs Integration**

Or directly: `/jobhunter/googlejobsintegration`

### 4. Enable Integration for Jobs

1. Click "Enable" toggle for each job you want to include
2. Click "Generate" to create structured data
3. Click "Validate" to check for errors
4. View job detail page for full information

---

## Testing Checklist

- [ ] Access `/jobhunter/googlejobsintegration` - Home page loads
- [ ] Statistics show correct counts
- [ ] Recent jobs table displays
- [ ] Click "Enable" toggle - Updates status
- [ ] Click "Generate" button - Creates structured data
- [ ] Click "Validate" button - Shows validation result
- [ ] Click "Validate All" - Processes all jobs
- [ ] Access job detail page - Shows full information
- [ ] View structured data preview - JSON displays correctly
- [ ] Copy JSON to clipboard - Works
- [ ] Validation history displays past attempts
- [ ] Menu link appears in Job Hunter admin section
- [ ] Documentation link opens integration guide

---

## Performance Considerations

### Database Queries
- Home page: 2 queries (statistics + recent jobs)
- Job detail: 4 queries (job + company + sync + log)
- AJAX endpoints: 1-3 queries per request

### Caching Opportunities
- Statistics can be cached for 5-15 minutes
- Structured data JSON can be cached until job is updated
- Validation results can be cached for 24 hours

### Optimization Tips
- Use pagination for large job lists
- Implement lazy loading for validation history
- Cache generated JSON-LD at CDN level
- Index database queries (already done)

---

## Security Considerations

- All routes require `administer job application automation` permission
- CSRF protection disabled on AJAX endpoints (consider adding tokens)
- Input sanitization on job descriptions
- JSON encoding prevents XSS in structured data
- No sensitive data exposed in client-side code
- Database queries use parameterized statements

---

## Documentation References

- [Google Jobs Integration Guide](docs/GOOGLE_JOB_SEARCH_API_INTEGRATION.md) - Complete 1,400+ line implementation guide
- [Google Search Central - Job Posting](https://developers.google.com/search/docs/appearance/structured-data/job-posting)
- [Schema.org - JobPosting](https://schema.org/JobPosting)
- [Rich Results Test](https://search.google.com/test/rich-results)

---

## File Inventory

**Controllers** (1):
- `src/Controller/GoogleJobsIntegrationController.php` (12,275 bytes)

**Services** (1):
- `src/Service/GoogleJobsService.php` (11,534 bytes)

**Templates** (2):
- `templates/google-jobs-integration-home.html.twig` (7,779 bytes)
- `templates/google-jobs-job-detail.html.twig` (10,498 bytes)

**Assets** (2):
- `css/google-jobs-integration.css` (6,835 bytes)
- `js/google-jobs-integration.js` (10,036 bytes)

**Configuration** (5):
- `job_hunter.install` (updated with 2 new tables + update hook)
- `job_hunter.services.yml` (updated)
- `job_hunter.routing.yml` (added 7 routes)
- `job_hunter.libraries.yml` (added 1 library)
- `job_hunter.module` (added 2 theme hooks)
- `job_hunter.links.menu.yml` (added 1 menu link)

**Total**: 58,957 bytes of new code + database schema + configuration

---

## Maintenance Notes

### Regular Tasks
- Monitor validation status weekly
- Review failed validations monthly
- Update structured data when Schema.org changes
- Test with Google Rich Results Test quarterly

### Troubleshooting
- If jobs show "pending" indefinitely: Click "Generate" then "Validate"
- If validation fails: Check job has required fields (title, description, company)
- If structured data missing: Ensure job.extracted_json is populated
- If performance metrics show 0: Google Search Console API not yet integrated

---

**Document Version**: 1.0  
**Last Updated**: February 7, 2026  
**Implementation Status**: ✅ Complete - Ready for Testing
