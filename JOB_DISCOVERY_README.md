# AI Job Discovery Feature - Testing Guide

## Overview
The AI Job Discovery feature has been successfully implemented for Phase 3 of the job application automation system. This feature analyzes a user's job seeker profile and searches AbbVie's careers page for matching opportunities.

## Files Created/Modified

### Routes (`job_hunter.routing.yml`)
- `job_hunter.start_job_discovery`: Main discovery page
- `job_hunter.job_discovery_search`: AJAX search endpoint

### Controller (`UserProfileController.php`)
- `startJobDiscovery()`: Displays the job discovery page
- `jobDiscoverySearch()`: Handles AJAX search requests
- `extractKeywordsFromProfile()`: Extracts keywords from user profile

### Service (`AbbVieJobScrapingService.php`)
- Complete service for scraping AbbVie careers page
- HTML parsing and job extraction
- Keyword relevance scoring
- Fallback to simulated data

### Templates
- `job-discovery-start.html.twig`: Main discovery page template
- Responsive design with professional styling
- Real-time search functionality

### Assets
- `css/job-discovery.css`: Complete styling for discovery page
- `js/job-discovery.js`: JavaScript for search functionality and UI interactions

### Configuration
- `job_hunter.libraries.yml`: Added job_discovery library
- `job_hunter.module`: Registered theme hook
- `job_hunter.services.yml`: Registered scraping service
- `job_hunter.local_tasks.yml`: Added navigation tab

## How to Test

1. **Navigate to User Profile**
   - Go to `/user/1` (or any user with a job seeker profile)
   - You should see an "AI Job Discovery" tab in the navigation

2. **Access Job Discovery Page**
   - Click the "AI Job Discovery" tab
   - URL: `/user/{user_id}/job-discovery/start`

3. **Test the Discovery Process**
   - The page displays extracted keywords from the user's profile
   - Shows AbbVie company information
   - Click "Start Discovery" to search for jobs
   - Results are displayed with job details from AbbVie careers

## Features Implemented

### Keyword Extraction
- Analyzes job seeker profile fields:
  - `field_skills`
  - `field_job_title`
  - `field_career_objectives`
  - `field_experience_summary`
  - `field_industry_preferences`

### AbbVie Integration
- Scrapes `https://careers.abbvie.com/en/`
- Parses job listings using the HTML structure provided
- Extracts:
  - Job title and URL
  - Location
  - Description
  - Job ID
  - Function/Department
  - Therapy Area
  - Experience Level
  - Job Type

### Relevance Scoring
- Title matches: 10 points
- Function/Therapy area matches: 5 points
- Description matches: 2 points
- Results sorted by relevance

### User Interface
- Professional, responsive design
- Real-time search with loading states
- Job cards with detailed information
- Save job functionality (placeholder)
- Direct links to AbbVie job pages

## Fallback Behavior
If the actual scraping fails (due to network issues, rate limiting, etc.), the system falls back to simulated job data based on the real AbbVie jobs from the HTML you provided.

## Security & Performance
- User access control (users can only access their own discovery)
- CSRF protection on AJAX endpoints
- Error handling and logging
- Timeout protection for HTTP requests
- Caching consideration for future optimization

## Next Steps
1. Test with real user profiles containing keywords
2. Verify the scraping works with live AbbVie data
3. Consider adding more target companies
4. Implement job saving functionality
5. Add email notifications for new matches

## URLs to Test
- Main discovery page: `/user/1/job-discovery/start`
- AJAX search endpoint: `/job-discovery/search` (POST)

Remember to clear Drupal cache after deployment:
```bash
cd /var/www/html/forseti
./vendor/bin/drush cache:rebuild
```