# SerpAPI Google Jobs API Reference

**Last Updated:** February 12, 2026

## Overview

The SerpAPI Google Jobs API provides programmatic access to Google Jobs search results. The API scrapes SERP results from Google Jobs searches and returns structured JSON data.

- **Endpoint:** `https://serpapi.com/search?engine=google_jobs`
- **Method:** GET
- **Uptime:** 99.977%
- **Results Per Page:** 10 jobs (use pagination for more)

## API Parameters

### Required Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `engine` | string | Must be set to `google_jobs` to use the Google Jobs API engine |
| `api_key` | string | Your SerpApi private key |
| `q` | string | The search query (e.g., "Barista", "Java Developer") |

### Geographic Location

| Parameter | Type | Description |
|-----------|------|-------------|
| `location` | string | Geographic location to originate search from (e.g., "Austin, Texas, United States"). Specify at city level for best results. Cannot be used with `uule`. |
| `uule` | string | Google encoded location. Cannot be used with `location`. |

### Localization

| Parameter | Type | Description |
|-----------|------|-------------|
| `google_domain` | string | Google domain to use (default: `google.com`) |
| `gl` | string | Two-letter country code (e.g., `us`, `uk`, `fr`) |
| `hl` | string | Two-letter language code (e.g., `en`, `es`, `fr`) |

### Filtering & Search Refinement

| Parameter | Type | Description |
|-----------|------|-------------|
| `uds` | string | Filter parameter that enables advanced filtering. Extracted from `filters` in API response. Multiple filters can be combined in a single `uds` value. |
| `chips` | string | **DEPRECATED** Additional query conditions (e.g., `city:Owg_06VPwoli_nfhBo8LyA==`) |
| `ltype` | string | **DEPRECATED** Set to `1` to filter for work from home jobs |
| `lrad` | number | Search radius in kilometers (not strictly enforced) |

### Pagination

| Parameter | Type | Description |
|-----------|------|-------------|
| `next_page_token` | string | Token for retrieving next page of results. Found in response at `serpapi_pagination.next_page_token`. Up to 10 results per page. |

**Note:** The `start` parameter (results offset) has been discontinued by Google.

### SerpApi Control Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `no_cache` | boolean | Force fresh results (`true`) or allow cached results (`false`, default). Cache expires after 1 hour. Cannot be used with `async`. |
| `async` | boolean | Submit search asynchronously (`true`) or wait for results (`false`, default). Use Searches Archive API to retrieve async results. Cannot be used with `no_cache`. |
| `output` | string | Response format: `json` (default) or `html` |

## Response Structure

### Search Metadata

```json
{
  "search_metadata": {
    "id": "695928888c24bd247f1be809",
    "status": "Success",
    "json_endpoint": "https://serpapi.com/searches/.../695928888c24bd247f1be809.json",
    "created_at": "2026-01-03 14:32:40 UTC",
    "processed_at": "2026-01-03 14:32:40 UTC",
    "google_jobs_url": "https://www.google.com/search?q=Barista&...",
    "raw_html_file": "https://serpapi.com/searches/.../695928888c24bd247f1be809.html",
    "total_time_taken": 0.84
  }
}
```

**Status Flow:** `Processing` → `Success` || `Error`

### Search Parameters (Echo)

```json
{
  "search_parameters": {
    "q": "Barista",
    "engine": "google_jobs",
    "location_requested": "Austin, Texas, United States",
    "location_used": "Austin,Texas,United States",
    "google_domain": "google.com"
  }
}
```

### Filters Array

The `filters` array contains available search refinement options extracted from the Google Jobs interface. Each filter includes:

- `name` - Filter display name
- `link` - Google search URL with filter applied
- `serpapi_link` - SerpApi URL with filter applied
- `uds` - Filter parameter value for API use
- `q` - Modified search query with filter

**Filter Categories:**
- **Date Posted:** Yesterday, Last 3 days, Last week, Last month
- **Job Type:** Full-time, Part-time, Contract, Internship
- **Experience Level:** Entry level, Mid level, Senior level
- **Education:** No degree, Associate, Bachelor's, Master's
- **Remote Work:** Remote jobs only

```json
{
  "filters": [
    {
      "name": "No degree",
      "uds": "AOm0WdE2fekQnsyfYEw8JPYozOKzrwA9MuiW8MXKZuh...",
      "q": "Barista no degree",
      "link": "https://www.google.com/search?...",
      "serpapi_link": "https://serpapi.com/search.json?..."
    },
    {
      "name": "Date posted",
      "options": [
        {
          "name": "Yesterday",
          "uds": "ADvngMg3E3nZHBbR_ywpl3w6An90vX97JE-gu4BCrGwD...",
          "q": "Barista since yesterday",
          "link": "https://www.google.com/search?...",
          "serpapi_link": "https://serpapi.com/search.json?..."
        }
      ]
    }
  ]
}
```

## Job Results Structure

### Complete Job Object

Each job in the `jobs_results` array contains:

```json
{
  "job_id": "eyJqb2JfdGl0bGUi...",
  "title": "barista - Store# 71323, N LAMAR BLVD & AIRPORT BLVD",
  "company_name": "Starbucks",
  "location": "Austin, TX",
  "via": "Indeed",
  "description": "Full job description text...",
  "thumbnail": "https://serpapi.com/.../company-logo.jpeg",
  "share_link": "https://www.google.com/search?ibp=htl;jobs&...",
  "extensions": [
    "4 days ago",
    "16.25–18.44 an hour",
    "Full-time",
    "Dental insurance",
    "Health insurance",
    "Paid time off"
  ],
  "detected_extensions": {
    "posted_at": "4 days ago",
    "salary": "16.25–18.44 an hour",
    "schedule_type": "Full-time",
    "dental_coverage": true,
    "health_insurance": true,
    "paid_time_off": true,
    "work_from_home": false
  },
  "job_highlights": [
    {
      "title": "Qualifications",
      "items": [
        "No previous experience required",
        "Available to work flexible hours including weekends"
      ]
    },
    {
      "title": "Responsibilities", 
      "items": [
        "Prepare food and beverages to standard recipes",
        "Engage with customers through clear communication"
      ]
    },
    {
      "title": "Benefits",
      "items": [
        "Medical, dental, vision insurance",
        "401(k) with employer match"
      ]
    }
  ],
  "apply_options": [
    {
      "title": "Indeed",
      "link": "https://www.indeed.com/viewjob?jk=..."
    },
    {
      "title": "LinkedIn",
      "link": "https://www.linkedin.com/jobs/view/..."
    }
  ]
}
```

## Field Reference

### Core Job Fields

| Field | Type | Description |
|-------|------|-------------|
| `job_id` | string | **Unique identifier from SerpAPI** - Use this instead of generating random IDs |
| `title` | string | Job title |
| `company_name` | string | Hiring company name |
| `location` | string | Job location (city, state/country) |
| `via` | string | Source platform (Indeed, LinkedIn, ZipRecruiter, etc.) |
| `description` | string | **Full job description** - Not truncated |
| `thumbnail` | string | Company logo URL |
| `share_link` | string | Google Jobs share link |

### Extensions & Benefits

**extensions** (array of strings) - Raw text extracted from job listing:
- Posted date (e.g., "4 days ago", "2 weeks ago")
- Salary range (e.g., "16.25–18.44 an hour", "$50,000-$70,000")
- Schedule type (e.g., "Full-time", "Part-time")
- Benefits (e.g., "Health insurance", "401k")

**detected_extensions** (object) - Structured/parsed data:

| Field | Type | Description |
|-------|------|-------------|
| `posted_at` | string | When job was posted (relative time) |
| `salary` | string | Salary information (formatted) |
| `schedule_type` | string | Full-time, Part-time, Contract, Internship |
| `work_from_home` | boolean | Remote work flag |
| `health_insurance` | boolean | Health insurance offered |
| `dental_coverage` | boolean | Dental insurance offered |
| `paid_time_off` | boolean | PTO offered |
| `qualifications` | string | Degree requirements |

### Structured Highlights

**job_highlights** - Array of categorized information:

```json
[
  {
    "title": "Qualifications",
    "items": ["Requirement 1", "Requirement 2", ...]
  },
  {
    "title": "Responsibilities",
    "items": ["Duty 1", "Duty 2", ...]
  },
  {
    "title": "Benefits",
    "items": ["Benefit 1", "Benefit 2", ...]
  }
]
```

### Application Links

**apply_options** - Array of platforms where job can be applied:

```json
[
  {
    "title": "Indeed",
    "link": "https://www.indeed.com/viewjob?jk=..."
  },
  {
    "title": "LinkedIn", 
    "link": "https://www.linkedin.com/jobs/view/..."
  }
]
```

Typically includes 5-8 different platforms per job.

## Pagination

Results are paginated with 10 jobs per page. Access subsequent pages using the `next_page_token`:

```json
{
  "serpapi_pagination": {
    "next_page_token": "eyJmYyI6IkV1SUVDcUlFUVV4cmRG...",
    "next": "https://serpapi.com/search.json?engine=google_jobs&...&next_page_token=eyJmYy..."
  }
}
```

Use the `next_page_token` parameter in your next API call to retrieve the next page.

## Usage Examples

### Basic Search

```bash
GET https://serpapi.com/search.json?engine=google_jobs&q=Barista&location=Austin,Texas,United+States&api_key=YOUR_API_KEY
```

### Search with Date Filter (Last Week)

```bash
GET https://serpapi.com/search.json?engine=google_jobs&q=Barista&uds=ADvngMg3E3nZHBbR_ywpl3w6An90vX97JE...&api_key=YOUR_API_KEY
```

### Remote Work Filter

```bash
GET https://serpapi.com/search.json?engine=google_jobs&q=Java+Developer&ltype=1&api_key=YOUR_API_KEY
```

### Combined Filters via UDS

Multiple filters can be combined in a single `uds` parameter:

```bash
GET https://serpapi.com/search.json?engine=google_jobs&q=barista&uds=ADvngMjIlLeH6JmF8XYRfQNKteaQnZOOk...&api_key=YOUR_API_KEY
```

This might combine: Full-time + Last 3 days + No degree required

### Pagination

```bash
# First page
GET https://serpapi.com/search.json?engine=google_jobs&q=Barista&api_key=YOUR_API_KEY

# Next page (using token from first response)
GET https://serpapi.com/search.json?engine=google_jobs&q=Barista&next_page_token=eyJmYyI6...&api_key=YOUR_API_KEY
```

## Integration Best Practices

### 1. Use Native `job_id` for Identification

**DO NOT** generate random IDs like `serpapi_` + `uniqid()`. Use the `job_id` field provided by SerpAPI:

```php
// ❌ WRONG
'id' => 'serpapi_' . uniqid()

// ✅ CORRECT
'id' => $job_data['job_id']
```

### 2. Store Full Descriptions

**DO NOT** truncate job descriptions. Store the complete text:

```php
// ❌ WRONG
$this->truncateText($job_data['description'] ?? '', 200)

// ✅ CORRECT
$job_data['description'] ?? ''
```

### 3. Capture All Relevant Fields

Map all available fields from the API response:

**Minimum recommended fields:**
- `job_id` - Native unique identifier
- `title` - Job title
- `company_name` - Company name
- `location` - Job location
- `via` - Source platform (Indeed, LinkedIn, etc.)
- `description` - Full description (NOT truncated)
- `thumbnail` - Company logo URL
- `share_link` - Google Jobs link
- `detected_extensions` - All of it (salary, schedule, benefits, remote flag)
- `job_highlights` - Structured qualifications, responsibilities, benefits
- `apply_options` - All application links

### 4. Implement Content-Based Deduplication

Generate a hash based on job content to identify duplicates across sources:

```php
function generateJobHash($company, $title, $location) {
    $normalized_company = strtolower(trim(preg_replace('/\b(inc|llc|ltd|corp)\b/i', '', $company)));
    $normalized_title = strtolower(trim($title));
    $normalized_location = strtolower(trim($location));
    
    return md5($normalized_company . '|' . $normalized_title . '|' . $normalized_location);
}
```

### 5. Store Complete JSON for Future Use

Save the complete API response in `job_data_json` field for access to any fields not initially mapped:

```php
'job_data_json' => json_encode($job_data)
```

### 6. Handle Pagination Properly

```php
$next_token = $response['serpapi_pagination']['next_page_token'] ?? null;
if ($next_token) {
    // Store for next cron run or process immediately
}
```

### 7. Utilize Filters via UDS

Extract `uds` values from the initial search response's `filters` array and use them in subsequent searches:

```php
// Get filters from first search
$filters = $response['filters'];

// Find "Last week" date filter
foreach ($filters as $filter) {
    if ($filter['name'] === 'Date posted' && isset($filter['options'])) {
        foreach ($filter['options'] as $option) {
            if ($option['name'] === 'Last week') {
                $uds_value = $option['uds'];
                // Use in next search: &uds=$uds_value
            }
        }
    }
}
```

## Common Data Patterns

### Salary Formats

- Hourly: `"16.25–18.44 an hour"`
- Annual: `"$50,000-$70,000 a year"`
- Range: `"$50K-$70K"`
- Exact: `"$65,000 a year"`
- Not specified: Field may be missing or `"Not specified"`

### Schedule Types

- `"Full-time"`
- `"Part-time"`
- `"Contract"`
- `"Temporary"`
- `"Internship"`

### Posted Date Formats

- `"24 hours ago"`
- `"2 days ago"`
- `"1 week ago"`
- `"3 weeks ago"`
- `"1 month ago"`
- `"30+ days ago"`

### Location Formats

- City + State: `"Austin, TX"`
- City + State (full): `"Austin, Texas"`
- Remote: `"Anywhere"` or `"Remote"`
- Multiple locations: `"New York, NY (+2 others)"`

## Rate Limits & Caching

- **Cache Duration:** 1 hour
- **Cached searches:** Free and not counted toward monthly quota
- **Cache Key:** Query + all parameters must match exactly
- **Fresh Results:** Use `no_cache=true` to force fresh data

## Error Handling

Check `search_metadata.status`:
- `"Processing"` - Search in progress (async mode)
- `"Success"` - Results available
- `"Error"` - Search failed (check `error` field for message)

## Related Resources

- **SerpAPI Documentation:** https://serpapi.com/google-jobs-api
- **Playground:** https://serpapi.com/playground
- **Locations API:** https://serpapi.com/locations.json (for precise location control)
- **Searches Archive API:** Retrieve async search results

## Changelog

- **Feb 2026:** `chips` parameter deprecated, use `uds` instead
- **Feb 2026:** `ltype` parameter deprecated, use `uds` filters instead
- **Feb 2026:** `start` offset parameter discontinued by Google
- **2025:** Introduction of `detected_extensions` structured data
- **2024:** Addition of `job_highlights` with categorized information
