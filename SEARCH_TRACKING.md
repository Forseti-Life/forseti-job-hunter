# Google Cloud Talent Solution Search Tracking

## Overview

The job_hunter module now includes comprehensive search tracking for all Google Cloud Talent Solution API searches. Every search query and its results are logged to the database for analytics, debugging, and user history.

## Configuration

Before using the search tracking features, you must configure your Google Cloud credentials:

1. **Navigate to Settings**: Go to `/jobhunter/settings` (Admin > Job Hunter > Settings)
2. **Add Service Account Key**: Paste your Google Cloud service account JSON key in the "Google Cloud Talent Solution API" section
3. **Test Connection**: Click "Test API Connection" to verify your credentials
4. **Save Configuration**: Save your settings

See the [Google Jobs API Integration documentation](/jobhunter/documentation/google-jobs-integration) for detailed setup instructions.

## Database Schema

### Table: jobhunter_job_search_queries

Tracks every search query sent to the Cloud Talent Solution API.

**Columns:**
- `id` - Primary key
- `uid` - User ID who performed the search
- `query_text` - Search query string
- `location` - Location filter applied
- `search_params_json` - Complete JSON of all search parameters sent to API
- `company_name` - Company name filter
- `employment_types` - Comma-separated employment types (FULL_TIME, PART_TIME, etc.)
- `job_categories` - Comma-separated job categories
- `page_token` - Pagination token used for this query  
- `page_size` - Number of results requested
- `total_results` - Total number of results returned by API
- `next_page_token` - Next page token returned by API
- `api_response_time_ms` - API response time in milliseconds
- `status` - Status: completed, error, timeout
- `error_message` - Error message if status is error
- `created` - Timestamp when search was performed

**Indexes:**
- `uid` - Search by user
- `status` - Filter by status
- `created` - Date range queries
- `query_text` (prefix) - Search history
- `location` (prefix) - Location analysis
- `company_name` (prefix) - Company searches

### Table: jobhunter_job_search_results

Stores individual job results from each search query.

**Columns:**
- `id` - Primary key
- `search_query_id` - Reference to jobhunter_job_search_queries.id
- `external_job_id` - Google job ID (e.g., projects/{project}/companies/{company}/jobs/{job})
- `job_title` - Job title from search result
- `company_name` - Company name from search result
- `location` - Job location
- `job_data_json` - Complete JSON data of the job from API
- `rank_position` - Position in search results (1-based)
- `imported_to_job_id` - Reference to jobhunter_job_requirements.id if imported
- `imported_at` - Timestamp when job was imported (NULL if not imported)
- `imported_by_uid` - User ID who imported this job
- `created` - Timestamp when result was stored

**Indexes:**
- `search_query_id` - Find results for a query
- `external_job_id` (prefix) - Lookup by Google job ID
- `imported_to_job_id` - Find import status
- `imported_at` - Date range for imports
- `job_title` (prefix) - Search by title
- `company_name` (prefix) - Search by company
- `created` - Date range queries

## Implementation

### CloudTalentSolutionService Changes

The `searchJobs()` method has been enhanced to:

1. **Track API response time** - Records start and end time of each API call
2. **Log search queries** - Calls `logSearchQuery()` to store query metadata
3. **Log search results** - Calls `logSearchResults()` to store individual job matches
4. **Handle errors gracefully** - Logs failed searches with error messages

### Import Tracking

The `importJob()` method now:

1. **Updates search results** - When a job is imported, updates the corresponding search result record
2. **Tracks import user** - Records which user imported the job
3. **Timestamps import** - Records when the job was imported
4. **Links to job record** - Creates link between search result and jobhunter_job_requirements

The `updateSearchResultImport()` method:
- Finds all search results matching the external_job_id
- Updates them with import details
- Only updates results that haven't been imported yet (imported_to_job_id IS NULL)
- Gracefully handles errors (logs warning but doesn't fail import)

## Schema Updates Required

Run these Drupal update hooks after deploying:

```bash
drush updb
```

This will run:

- **job_hunter_update_9014** - Creates search tracking tables
- **job_hunter_update_9015** - Adds cloud_talent_company_name to companies table  
- **job_hunter_update_9016** - Adds external source tracking columns to job_requirements table

## Analytics & Reporting Queries

### Search volume by user

```sql
SELECT uid, COUNT(*) as search_count, 
       AVG(api_response_time_ms) as avg_response_time_ms
FROM jobhunter_job_search_queries
WHERE status = 'completed'
GROUP BY uid
ORDER BY search_count DESC;
```

### Most searched queries

```sql
SELECT query_text, COUNT(*) as search_count
FROM jobhunter_job_search_queries
WHERE query_text IS NOT NULL
GROUP BY query_text
ORDER BY search_count DESC
LIMIT 20;
```

### Import conversion rate

```sql
SELECT 
  COUNT(*) as total_results,
  SUM(CASE WHEN imported_to_job_id IS NOT NULL THEN 1 ELSE 0 END) as imported_count,
  ROUND(SUM(CASE WHEN imported_to_job_id IS NOT NULL THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as import_rate_pct
FROM jobhunter_job_search_results;
```

### Top companies in search results

```sql
SELECT company_name, COUNT(*) as result_count,
       SUM(CASE WHEN imported_to_job_id IS NOT NULL THEN 1 ELSE 0 END) as imported_count
FROM jobhunter_job_search_results
GROUP BY company_name
ORDER BY result_count DESC
LIMIT 20;
```

### Failed searches

```sql
SELECT query_text, location, error_message, created
FROM jobhunter_job_search_queries
WHERE status = 'error'
ORDER BY created DESC
LIMIT 50;
```

### Search performance metrics

```sql
SELECT 
  DATE(FROM_UNIXTIME(created)) as search_date,
  COUNT(*) as searches,
  AVG(api_response_time_ms) as avg_response_ms,
  MAX(api_response_time_ms) as max_response_ms,
  AVG(total_results) as avg_results_per_search
FROM jobhunter_job_search_queries
WHERE status = 'completed'
GROUP BY search_date
ORDER BY search_date DESC;
```

## Privacy Considerations

- Search queries are linked to user accounts (uid)
- All search parameters are stored in JSON format
- Job data includes potentially sensitive information
- Consider implementing data retention policies
- Export/delete user data on account deletion (GDPR compliance)

## Future Enhancements

Potential improvements:

1. **Search history UI** - Let users view their past searches
2. **Popular searches** - Show trending searches across all users
3. **Search suggestions** - Autocomplete based on past searches
4. **Export to CSV** - Download search results for offline analysis
5. **API rate limiting** - Track and enforce API usage quotas
6. **Cost tracking** - Estimate API costs based on usage
7. **A/B testing** - Compare different search parameters
8. **Search alerts** - Notify users when new matches appear for saved searches
