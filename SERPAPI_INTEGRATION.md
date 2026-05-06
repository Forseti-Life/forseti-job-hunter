# SerpAPI Google Jobs Integration

**Last Updated:** February 12, 2026

## Overview

The Job Hunter module integrates with **SerpAPI** to scrape Google Jobs search results, providing access to millions of job listings aggregated by Google from across the web.

SerpAPI provides a simple REST API that returns structured JSON data from Google Jobs searches without needing to deal with browser automation, proxies, or CAPTCHA challenges.

**Official Documentation:** https://serpapi.com/google-jobs-api

## Features

✅ **Google Jobs Scraping** - Access Google's job aggregation engine via SerpAPI  
✅ **Multiple Filters** - Location, keywords, employment type, date posted  
✅ **Structured Data** - Clean JSON responses with job details  
✅ **Free Tier** - 100 searches per month included  
✅ **No Browser Required** - Pure API integration, no Selenium/Puppeteer needed  
✅ **Rate Limiting** - Built-in request throttling and error handling

## API Information

### Endpoint
```
GET https://serpapi.com/search
```

### Required Parameters
- `engine=google_jobs` - Use Google Jobs search engine
- `api_key` - Your SerpAPI authentication key
- `q` - Search query (keywords)

### Optional Parameters
- `location` - Geographic location (e.g., "Austin, Texas, United States")
- `uule` - Google encoded location (alternative to location parameter)
- `hl` - Language (e.g., "en" for English)
- `gl` - Country code (e.g., "us" for United States)
- `num` - Number of results (max 100, default 10)
- `start` - Results offset for pagination
- `chips` - Additional filters (employment type, date posted, etc.)
- `lrad` - Search radius in kilometers
- `ltype` - Work from home filter (1 = remote only)
- `uds` - Advanced filter string from Google Jobs

### Response Structure
```json
{
  "search_metadata": {
    "id": "search_id",
    "status": "Success",
    "total_time_taken": 0.84
  },
  "search_parameters": {
    "q": "Software Engineer",
    "engine": "google_jobs",
    "location": "Austin, Texas"
  },
  "jobs_results": [
    {
      "title": "Senior Software Engineer",
      "company_name": "Tech Corp",
      "location": "Austin, TX",
      "via": "LinkedIn",
      "description": "Job description text...",
      "thumbnail": "https://...",
      "extensions": ["Full-time", "$120K-$180K", "Health insurance"],
      "detected_extensions": {
        "posted_at": "2 days ago",
        "schedule_type": "Full-time",
        "salary": "$120K-$180K",
        "work_from_home": false
      },
      "apply_options": [
        {
          "title": "LinkedIn",
          "link": "https://linkedin.com/jobs/..."
        }
      ],
      "job_id": "eyJqb2JfdGl0bGUi..."
    }
  ],
  "filters": [...],
  "serpapi_pagination": {
    "next_page_token": "...",
    "next": "https://serpapi.com/search.json?..."
  }
}
```

## Setup Instructions

### 1. Get Your SerpAPI Key

1. Visit: https://serpapi.com/users/sign_up
2. Create a free account
3. Copy your API key from the dashboard
4. **Free tier includes 100 searches/month**

### 2. Configure in Drupal

1. Navigate to: **Admin > Job Hunter > Settings** (`/jobhunter/settings`)
2. Scroll to **"External Job Search APIs"** section
3. Find **"SerpAPI (Google Jobs Scraper)"**
4. Paste your API key in the **"SerpAPI API Key"** field
5. Click **"Save configuration"**

### 3. Test the Integration

1. Navigate to: **Job Discovery** (`/jobhunter/job-discovery`)
2. Enter search criteria:
   - Keywords: e.g., "Software Engineer"
   - Location: e.g., "Austin, Texas"
3. Check **"SerpAPI (Google Jobs)"** checkbox
4. Click **"Search Jobs"**
5. View results from Google Jobs via SerpAPI

## File Structure

```
job_hunter/
├── src/
│   ├── Service/
│   │   └── SerpApiService.php          # Core API integration service
│   ├── Controller/
│   │   └── JobApplicationController.php # jobDiscoverySearchResults() method
│   └── Form/
│       └── SettingsForm.php            # API key configuration
├── job_hunter.services.yml             # Service registration
└── SERPAPI_INTEGRATION.md              # This documentation
```

## Code Implementation

### Service Registration (`job_hunter.services.yml`)

```yaml
job_hunter.serpapi:
  class: Drupal\job_hunter\Service\SerpApiService
  arguments: ['@http_client', '@logger.factory', '@config.factory']
```

### Service Usage Example

```php
<?php

// Get the SerpAPI service
$serpapiService = \Drupal::service('job_hunter.serpapi');

// Search parameters
$params = [
  'query' => 'Software Engineer',
  'location' => 'Austin, Texas',
  'employment_type' => 'FULL_TIME',
  'page' => 1,
  'results_per_page' => 10,
];

// Execute search
$results = $serpapiService->searchJobs($params);

// Process results
foreach ($results['jobs'] as $job) {
  echo $job['title'] . ' at ' . $job['company_name'] . "\n";
  echo 'Location: ' . $job['location'] . "\n";
  echo 'Posted: ' . $job['detected_extensions']['posted_at'] . "\n";
  echo 'Salary: ' . $job['detected_extensions']['salary'] . "\n";
  echo 'URL: ' . $job['apply_options'][0]['link'] . "\n\n";
}

// Total results
echo 'Found ' . $results['total'] . ' jobs on page ' . $results['page'];
```

### Employment Type Mapping

The service maps Drupal employment types to Google Jobs format:

```php
$type_mapping = [
  'FULL_TIME' => 'FULLTIME',
  'PART_TIME' => 'PARTTIME',
  'CONTRACT' => 'CONTRACTOR',
  'TEMPORARY' => 'TEMPORARY',
  'INTERN' => 'INTERN',
];
```

## API Rate Limits

| Plan       | Searches/Month | Price/Month |
|------------|----------------|-------------|
| Free       | 100            | $0          |
| Developer  | 5,000          | $75         |
| Production | 15,000         | $200        |
| Enterprise | Custom         | Contact     |

**Note:** Each search consumes 1 credit regardless of number of results returned.

## Error Handling

The service includes comprehensive error handling:

```php
try {
  $results = $serpapiService->searchJobs($params);
  
  if (empty($results['jobs'])) {
    // No results found
    \Drupal::logger('job_hunter')->warning('SerpAPI returned no jobs');
  }
  
} catch (\Exception $e) {
  \Drupal::logger('job_hunter')->error('SerpAPI search failed: @error', [
    '@error' => $e->getMessage(),
  ]);
}
```

### Common Errors

1. **Invalid API Key**
   - Error: `"Invalid API key"`
   - Solution: Check your API key in settings

2. **Rate Limit Exceeded**
   - Error: `"You have reached your rate limit"`
   - Solution: Upgrade plan or wait for monthly reset

3. **Network Timeout**
   - Error: `"cURL error 28: Operation timed out"`
   - Solution: Retry request, check network connectivity

4. **No Results**
   - Response: `jobs_results = []`
   - Solution: Try broader search terms or different location

## Integration Points

### Job Discovery Interface

File: [JobApplicationController.php](src/Controller/JobApplicationController.php#L1720-L2120)

The `jobDiscoverySearchResults()` method integrates SerpAPI results:

```php
// Check if SerpAPI is selected and execute search
if (in_array('serpapi', $sources)) {
  $serpapiService = \Drupal::service('job_hunter.serpapi');
  
  $serpapi_params = [
    'query' => $query,
    'location' => $location,
    'employment_type' => $employment_type,
    'page' => 1,
    'results_per_page' => 10,
  ];
  
  $serpapi_results = $serpapiService->searchJobs($serpapi_params);
  
  // Format results for display
  foreach ($serpapi_results['jobs'] as $job_data) {
    $all_results[] = [
      'title' => $job_data['title'],
      'company' => $job_data['company_name'],
      'location' => $job_data['location'],
      'salary_range' => $job_data['detected_extensions']['salary'] ?? 'Not specified',
      'description' => $this->truncateText($job_data['description'], 200),
      'source' => 'Google Jobs (SerpAPI)',
      'posted_date' => $job_data['detected_extensions']['posted_at'] ?? 'Unknown',
      'url' => $job_data['apply_options'][0]['link'] ?? '',
    ];
  }
}
```

### Settings Form

File: [SettingsForm.php](src/Form/SettingsForm.php#L256-L270)

API key configuration field:

```php
$form['external_job_apis']['serpapi']['serpapi_api_key'] = [
  '#type' => 'textfield',
  '#title' => $this->t('SerpAPI API Key'),
  '#description' => $this->t('Your SerpAPI authentication key.'),
  '#default_value' => $config->get('serpapi_api_key') ?? '',
  '#required' => FALSE,
];
```

## Comparison with Other APIs

| Feature | SerpAPI | Google Cloud Talent | Adzuna | USAJobs |
|---------|---------|---------------------|--------|---------|
| **Data Source** | Google Jobs | Your own data | Adzuna network | US Government |
| **Setup Complexity** | Easy | Complex | Easy | Easy |
| **Cost (Free Tier)** | 100/month | None | None | Unlimited |
| **Job Coverage** | Millions (aggregated) | Your uploads only | Moderate | Government only |
| **Update Frequency** | Real-time | Manual upload | Daily | Daily |
| **Authentication** | API Key | OAuth2 + Service Account | App ID + Key | API Key + Email |

### When to Use SerpAPI

✅ **Use SerpAPI when:**
- You need access to broad job market data
- You want jobs from multiple aggregators (Indeed, LinkedIn, etc.)
- You need real-time job postings
- You want minimal setup complexity
- You need salary information and posting dates
- Your budget supports 100+ searches/month

❌ **Don't use SerpAPI when:**
- You need to search your own proprietary job data (use Google Cloud Talent)
- You want unlimited searches on free tier (use USAJobs for government jobs)
- You need direct employer contact information
- You need application tracking integration

## Advanced Features

### Pagination

SerpAPI supports pagination via `start` parameter:

```php
// Page 1 (results 0-9)
$params1 = ['query' => 'Engineer', 'results_per_page' => 10];

// Page 2 (results 10-19)
$params2 = ['query' => 'Engineer', 'page' => 2, 'results_per_page' => 10];
```

### Location Encoding

Use `location` parameter for city-level precision:

```php
$params = [
  'query' => 'Developer',
  'location' => 'Austin, Texas, United States',
];
```

Or use `uule` for precise Google-encoded locations (advanced).

### Remote Jobs Filter

To filter for remote-only positions:

```php
$params = [
  'query' => 'Software Engineer',
  'location' => 'Remote', // Or any location
  'ltype' => '1', // 1 = Work from home only
];
```

### Date Posted Filter

Filter by posting date (not directly supported via simple parameter, but available via `uds` advanced filters).

## Logging

The service logs all API activities:

```php
// Search initiated
\Drupal::logger('job_hunter')->info('🔍 SerpAPI Google Jobs search: @params');

// Results received
\Drupal::logger('job_hunter')->info('✅ SerpAPI returned @count jobs');

// Errors
\Drupal::logger('job_hunter')->error('SerpAPI request failed: @error');
```

View logs at: **Reports > Recent log messages** (`/admin/reports/dblog`)

## Testing

### Manual Test

1. Navigate to: `/jobhunter/job-discovery`
2. Enter test query: "Software Engineer"
3. Enter location: "Austin, Texas"
4. Select **"SerpAPI (Google Jobs)"** checkbox
5. Click **"Search Jobs"**
6. Verify results appear with source badge "Google Jobs (SerpAPI)"

### Drush Test

```bash
# Check if API key is configured
cd /var/www/html/forseti
./vendor/bin/drush cget job_hunter.settings serpapi_api_key

# View recent SerpAPI logs
./vendor/bin/drush watchdog:show --filter="SerpAPI" --count=20
```

### curl Test (Direct API)

```bash
# Replace YOUR_API_KEY with your actual key
curl "https://serpapi.com/search?engine=google_jobs&q=Software+Engineer&location=Austin,+Texas&api_key=YOUR_API_KEY"
```

## Troubleshooting

### Issue: "SerpAPI (Google Jobs)" checkbox is disabled

**Cause:** API key not configured

**Solution:**
1. Go to `/jobhunter/settings`
2. Enter your SerpAPI API key
3. Save configuration
4. Refresh job discovery page

### Issue: No results returned

**Possible Causes:**
1. API key invalid
2. Rate limit exceeded
3. Network connectivity issues
4. Query too specific

**Debug Steps:**
```bash
# Check logs for errors
cd /var/www/html/forseti
./vendor/bin/drush watchdog:show --filter="SerpAPI" --count=10

# Test API key directly
curl "https://serpapi.com/search?engine=google_jobs&q=Engineer&api_key=YOUR_KEY"
```

### Issue: Rate limit exceeded

**Solution:**
1. Check your usage at https://serpapi.com/dashboard
2. Wait for monthly reset (1st of each month)
3. Or upgrade to higher tier plan

## Best Practices

1. **Cache Results** - Store SerpAPI results in database to reduce API calls
2. **Batch Queries** - Combine multiple searches efficiently
3. **Monitor Usage** - Track API calls to stay within rate limits
4. **Error Handling** - Always wrap API calls in try-catch blocks
5. **Fallback Logic** - Have alternative data sources if SerpAPI is unavailable
6. **Log Everything** - Track all API interactions for debugging

## Security Considerations

1. **API Key Protection**
   - Never commit API keys to Git
   - Store keys in `job_hunter.settings` config (excluded from export)
   - Use environment variables for production

2. **Rate Limiting**
   - Implement client-side rate limiting to prevent abuse
   - Track API usage per user if needed

3. **Data Validation**
   - Sanitize all job data before display
   - Validate URLs before creating links
   - Escape HTML in job descriptions

## Support & Resources

- **SerpAPI Documentation:** https://serpapi.com/google-jobs-api
- **Playground (Live Testing):** https://serpapi.com/playground?engine=google_jobs
- **API Status:** https://serpapi.com/status
- **Support:** support@serpapi.com
- **GitHub:** https://github.com/serpapi

## Changelog

### 2026-02-12
- ✅ Complete SerpAPI integration implemented
- ✅ Service registered and injected via DI
- ✅ Settings form configuration added
- ✅ Job discovery controller integration complete
- ✅ Error handling and logging implemented
- ✅ Documentation created

---

**Module:** Job Hunter  
**Version:** 1.0  
**Maintainer:** Keith Aumiller  
**License:** Proprietary
