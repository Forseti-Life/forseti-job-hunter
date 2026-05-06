# Google Job Search API Integration Guide

**Last Updated**: February 9, 2026  
**Status**: 🟡 In Progress - Migrating to Cloud Talent Solution API  
**Module**: job_hunter

---

## Table of Contents

1. [Overview](#overview)
2. [Cloud Talent Solution API Setup](#cloud-talent-solution-api-setup)
3. [What is Google for Jobs](#what-is-google-for-jobs)
4. [Prerequisites](#prerequisites)
5. [Integration Methods](#integration-methods)
6. [Structured Data Implementation](#structured-data-implementation)
7. [Schema.org JobPosting Specification](#schemaorg-jobposting-specification)
8. [Implementation Examples](#implementation-examples)
9. [Testing and Validation](#testing-and-validation)
10. [Best Practices](#best-practices)
11. [Common Pitfalls](#common-pitfalls)
12. [SEO Optimization](#seo-optimization)
13. [Monitoring and Maintenance](#monitoring-and-maintenance)
14. [Additional Resources](#additional-resources)

---

## Overview

This guide provides comprehensive documentation for integrating the `job_hunter` module with Google's Job Search feature (also known as "Google for Jobs"). Google for Jobs is not a traditional API but rather a search feature that displays job postings directly in Google Search results when properly structured data is present on web pages.

### Key Benefits

- **Increased Visibility**: Job postings appear prominently in Google Search results
- **Enhanced User Experience**: Rich job cards with details, salary, location, and apply buttons
- **Better Qualified Candidates**: Users can filter by location, job type, date posted, etc.
- **No Cost**: Free to implement and use (unlike paid job boards)
- **Mobile Optimization**: Jobs appear in Google Search mobile app with excellent UX

---

## Cloud Talent Solution API Setup

**Google Cloud Project**: forseti-483518  
**API**: Cloud Talent Solution (Google Enterprise API)  
**Status**: 🔴 Requires Configuration

The Cloud Talent Solution API provides enterprise-level capabilities to create, read, update, and delete job postings programmatically, going beyond the basic structured data approach.

### Service Account Configuration

**Console URL**: [https://console.cloud.google.com/talent-solution/connect-service-accounts?project=forseti-483518](https://console.cloud.google.com/talent-solution/connect-service-accounts?project=forseti-483518)

#### Connected Service Accounts

| Name | Service Account ID | Key ID | Status |
|------|-------------------|--------|--------|
| forseti.life | `forseti-life@forseti-483518.iam.gserviceaccount.com` | No keys | ⚠️ Needs API Key |

### Setup Requirements

1. **Enable Cloud Talent Solution API**
   ```bash
   gcloud services enable jobs.googleapis.com --project=forseti-483518
   ```

2. **Create Service Account Key**
   - Navigate to: [Service Accounts Console](https://console.cloud.google.com/talent-solution/connect-service-accounts?project=forseti-483518)
   - Select the `forseti-life@forseti-483518.iam.gserviceaccount.com` service account
   - Create a new JSON key
   - Download and securely store the key file

3. **Configure Permissions**
   Required IAM roles for the service account:
   - `roles/cloudtalentsolution.jobsEditor` - Create, update, delete jobs
   - `roles/cloudtalentsolution.jobsViewer` - Read jobs
   - `roles/cloudtalentsolution.profilesEditor` - Manage candidate profiles (optional)

4. **Store API Credentials**
   - Add the JSON key to Drupal configuration
   - Use Drupal Key module for secure storage (recommended)
   - Or store in `job_hunter.settings` configuration
   - **Settings Page**: `/jobhunter/settings` (Admin > Job Hunter > Settings)

### API Capabilities

Unlike basic structured data, the Cloud Talent Solution API provides:

- **Job Management**: Full CRUD operations for job postings
- **Company Management**: Create and manage company profiles
- **Advanced Search**: AI-powered job matching and search
- **Commute Search**: Find jobs by commute time
- **Autocomplete**: Job title and location suggestions
- **Analytics**: Track job performance metrics
- **Batch Operations**: Bulk import/update jobs
- **Real-time Updates**: Immediate job posting updates

### Integration Approach

This module uses a **hybrid approach**:

1. **Schema.org Markup** (Current)
   - For public job posting pages
   - Free, simple implementation
   - Google crawls and indexes automatically

2. **Cloud Talent Solution API** (In Progress)
   - For programmatic job management
   - Advanced search and matching
   - Direct job posting to Google's index
   - Enterprise features and analytics

### Next Steps

- [ ] Generate and download service account key
- [ ] Configure API credentials in job_hunter module (`/jobhunter/settings`)
- [ ] Implement CloudTalentSolutionService class
- [ ] Create API endpoints for job CRUD operations
- [ ] Add job search interface using API
- [ ] Migrate from third-party scraping to direct API access

### Reference Links

- [Cloud Talent Solution Documentation](https://cloud.google.com/talent-solution/job-search/docs)
- [API Reference](https://cloud.google.com/talent-solution/job-search/docs/reference/rest)
- [Client Libraries](https://cloud.google.com/talent-solution/job-search/docs/libraries)
- [Quotas and Limits](https://cloud.google.com/talent-solution/quotas)

---

## What is Google for Jobs

Google for Jobs is **not a traditional REST API**. Instead, it's a search feature that:

1. **Crawls Your Website**: Google's web crawler (Googlebot) indexes your job posting pages
2. **Reads Structured Data**: Extracts job information from Schema.org JobPosting markup
3. **Displays in Search**: Shows enriched job cards when users search for relevant jobs
4. **Aggregates from Multiple Sources**: Combines job postings from various websites

### How It Works

```
Your Website → JobPosting Structured Data → Googlebot Crawls → Google Index → Search Results Display
```

**Important**: You do NOT send job postings to Google via API calls. Google finds and indexes them automatically when they're properly marked up on your website.

---

## Prerequisites

### Required Components

1. **Public Job Posting Pages**
   - Each job must have a unique, publicly accessible URL
   - Pages must be crawlable (not behind login walls)
   - No robots.txt blocking

2. **Schema.org Markup**
   - Valid JSON-LD or Microdata format
   - JobPosting schema with required properties
   - Embedded in the HTML of job posting pages

3. **Valid HTML**
   - Well-formed HTML structure
   - Fast page load times (< 3 seconds)
   - Mobile-responsive design

4. **Google Search Console**
   - Verified ownership of your domain
   - Access to monitor indexing status
   - Rich Results testing capability

### Technical Requirements

- **Drupal 11**: Current installation
- **Public Web Server**: HTTPS enabled
- **Content Type**: job_posting (already exists in job_hunter)
- **URL Aliases**: Clean URLs for job postings (e.g., `/jobs/senior-engineer-123`)
- **Sitemap**: XML sitemap including job posting URLs

---

## Integration Methods

### Method 1: JSON-LD (Recommended)

**JSON-LD** (JavaScript Object Notation for Linked Data) is the recommended format by Google.

**Advantages**:
- Easy to implement and maintain
- Doesn't interfere with page HTML
- Can be dynamically generated
- Easy to debug and validate

**Implementation Location**:
```html
<head>
  <script type="application/ld+json">
  {
    "@context": "https://schema.org/",
    "@type": "JobPosting",
    "title": "Software Engineer",
    ...
  }
  </script>
</head>
```

### Method 2: Microdata (Alternative)

**Microdata** embeds structured data directly in HTML elements.

**Advantages**:
- Integrated with visible content
- Ensures accuracy (markup matches display)

**Disadvantages**:
- More verbose
- Harder to maintain
- Can clutter HTML

**Implementation Example**:
```html
<div itemscope itemtype="https://schema.org/JobPosting">
  <h1 itemprop="title">Software Engineer</h1>
  <p itemprop="description">We are seeking...</p>
  ...
</div>
```

### Recommended Approach for job_hunter Module

**Use JSON-LD** for the following reasons:
1. Drupal can generate it programmatically
2. Easier to update without changing templates
3. Doesn't affect page styling or layout
4. Better for maintenance and debugging
5. Can be added via Twig template or custom module code

---

## Structured Data Implementation

### Step 1: Create a Twig Template

Create or modify the job posting template to include JSON-LD structured data.

**File**: `/sites/forseti/web/modules/custom/job_hunter/templates/node--job-posting--full.html.twig`

```twig
{#
/**
 * @file
 * Theme override for job posting nodes in full display mode.
 */
#}

{# Include default node display #}
{{ attach_library('job_hunter/job_posting') }}

<article{{ attributes.addClass('node', 'node--type-' ~ node.bundle|clean_class, 'node--view-mode-' ~ view_mode|clean_class) }}>
  
  {# JSON-LD Structured Data for Google for Jobs #}
  <script type="application/ld+json">
  {
    "@context": "https://schema.org/",
    "@type": "JobPosting",
    "title": "{{ node.title.value|escape('js') }}",
    "description": "{{ node.field_job_description.value|striptags|escape('js') }}",
    "identifier": {
      "@type": "PropertyValue",
      "name": "{{ site_name }}",
      "value": "{{ node.id }}"
    },
    "datePosted": "{{ node.created.value|date('Y-m-d') }}",
    {% if node.field_valid_through.value %}
    "validThrough": "{{ node.field_valid_through.value|date('Y-m-d') }}",
    {% endif %}
    "employmentType": [
      {% if node.field_employment_type.value %}
      "{{ node.field_employment_type.value }}"
      {% else %}
      "FULL_TIME"
      {% endif %}
    ],
    "hiringOrganization": {
      "@type": "Organization",
      "name": "{{ node.field_company.entity.title.value|escape('js') }}",
      "sameAs": "{{ node.field_company.entity.field_website.uri }}",
      {% if node.field_company.entity.field_logo.entity.uri.value %}
      "logo": "{{ file_url(node.field_company.entity.field_logo.entity.uri.value) }}"
      {% endif %}
    },
    "jobLocation": {
      "@type": "Place",
      "address": {
        "@type": "PostalAddress",
        {% if node.field_location_city.value %}
        "addressLocality": "{{ node.field_location_city.value|escape('js') }}",
        {% endif %}
        {% if node.field_location_state.value %}
        "addressRegion": "{{ node.field_location_state.value|escape('js') }}",
        {% endif %}
        {% if node.field_location_country.value %}
        "addressCountry": "{{ node.field_location_country.value|escape('js') }}"
        {% endif %}
      }
    },
    {% if node.field_salary_min.value or node.field_salary_max.value %}
    "baseSalary": {
      "@type": "MonetaryAmount",
      "currency": "USD",
      "value": {
        "@type": "QuantitativeValue",
        {% if node.field_salary_min.value and node.field_salary_max.value %}
        "minValue": {{ node.field_salary_min.value }},
        "maxValue": {{ node.field_salary_max.value }},
        {% elif node.field_salary_min.value %}
        "value": {{ node.field_salary_min.value }},
        {% elif node.field_salary_max.value %}
        "value": {{ node.field_salary_max.value }},
        {% endif %}
        "unitText": "YEAR"
      }
    },
    {% endif %}
    "url": "{{ url('<current>')|render }}"
  }
  </script>

  {# Standard node content #}
  {{ content }}

</article>
```

### Step 2: Add Required Fields to job_posting Content Type

The following fields should exist in your `job_posting` content type:

| Field Name | Machine Name | Type | Required | Example |
|------------|--------------|------|----------|---------|
| Job Title | `title` | Text | ✅ | "Senior Software Engineer" |
| Description | `field_job_description` | Text (long) | ✅ | Full job description |
| Company | `field_company` | Entity Reference | ✅ | Reference to company node |
| Employment Type | `field_employment_type` | List (text) | ✅ | FULL_TIME, PART_TIME, etc. |
| Location City | `field_location_city` | Text | ✅ | "San Francisco" |
| Location State | `field_location_state` | Text | ✅ | "CA" |
| Location Country | `field_location_country` | Text | ✅ | "US" |
| Salary Min | `field_salary_min` | Number | ❌ | 80000 |
| Salary Max | `field_salary_max` | Number | ❌ | 120000 |
| Valid Through | `field_valid_through` | Date | ❌ | "2026-03-31" |
| Posted Date | `created` | Timestamp | ✅ | Auto-generated |

**Note**: Some fields may already exist in job_hunter. Verify and add missing fields.

### Step 3: Implement in a Custom Module (Alternative)

If you prefer programmatic generation, implement in `job_hunter.module`:

```php
<?php

/**
 * Implements hook_preprocess_node().
 */
function job_hunter_preprocess_node(&$variables) {
  if ($variables['node']->bundle() === 'job_posting' && $variables['view_mode'] === 'full') {
    $node = $variables['node'];
    
    // Build JSON-LD structured data.
    $structured_data = _job_hunter_build_job_posting_json_ld($node);
    
    // Add to page head.
    $variables['#attached']['html_head'][] = [
      [
        '#type' => 'html_tag',
        '#tag' => 'script',
        '#attributes' => ['type' => 'application/ld+json'],
        '#value' => json_encode($structured_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      ],
      'job_posting_json_ld',
    ];
  }
}

/**
 * Build JSON-LD structured data for a job posting.
 */
function _job_hunter_build_job_posting_json_ld($node) {
  $data = [
    '@context' => 'https://schema.org/',
    '@type' => 'JobPosting',
    'title' => $node->getTitle(),
    'description' => strip_tags($node->get('field_job_description')->value),
    'identifier' => [
      '@type' => 'PropertyValue',
      'name' => \Drupal::config('system.site')->get('name'),
      'value' => $node->id(),
    ],
    'datePosted' => date('Y-m-d', $node->getCreatedTime()),
  ];

  // Add optional fields if they exist.
  if ($node->hasField('field_valid_through') && !$node->get('field_valid_through')->isEmpty()) {
    $data['validThrough'] = $node->get('field_valid_through')->value;
  }

  // Employment type.
  if ($node->hasField('field_employment_type') && !$node->get('field_employment_type')->isEmpty()) {
    $data['employmentType'] = [$node->get('field_employment_type')->value];
  }

  // Company information.
  if ($node->hasField('field_company') && !$node->get('field_company')->isEmpty()) {
    $company = $node->get('field_company')->entity;
    $data['hiringOrganization'] = [
      '@type' => 'Organization',
      'name' => $company->getTitle(),
    ];
    
    if ($company->hasField('field_website') && !$company->get('field_website')->isEmpty()) {
      $data['hiringOrganization']['sameAs'] = $company->get('field_website')->uri;
    }
    
    if ($company->hasField('field_logo') && !$company->get('field_logo')->isEmpty()) {
      $logo_uri = $company->get('field_logo')->entity->getFileUri();
      $data['hiringOrganization']['logo'] = \Drupal::service('file_url_generator')->generateAbsoluteString($logo_uri);
    }
  }

  // Job location.
  $address = ['@type' => 'PostalAddress'];
  if ($node->hasField('field_location_city') && !$node->get('field_location_city')->isEmpty()) {
    $address['addressLocality'] = $node->get('field_location_city')->value;
  }
  if ($node->hasField('field_location_state') && !$node->get('field_location_state')->isEmpty()) {
    $address['addressRegion'] = $node->get('field_location_state')->value;
  }
  if ($node->hasField('field_location_country') && !$node->get('field_location_country')->isEmpty()) {
    $address['addressCountry'] = $node->get('field_location_country')->value;
  }
  
  if (count($address) > 1) {
    $data['jobLocation'] = [
      '@type' => 'Place',
      'address' => $address,
    ];
  }

  // Salary information.
  if (($node->hasField('field_salary_min') && !$node->get('field_salary_min')->isEmpty()) ||
      ($node->hasField('field_salary_max') && !$node->get('field_salary_max')->isEmpty())) {
    
    $salary = [
      '@type' => 'MonetaryAmount',
      'currency' => 'USD',
      'value' => ['@type' => 'QuantitativeValue'],
    ];
    
    if ($node->hasField('field_salary_min') && !$node->get('field_salary_min')->isEmpty()) {
      $salary['value']['minValue'] = $node->get('field_salary_min')->value;
    }
    
    if ($node->hasField('field_salary_max') && !$node->get('field_salary_max')->isEmpty()) {
      $salary['value']['maxValue'] = $node->get('field_salary_max')->value;
    }
    
    $salary['value']['unitText'] = 'YEAR';
    $data['baseSalary'] = $salary;
  }

  // Current page URL.
  $data['url'] = $node->toUrl('canonical', ['absolute' => TRUE])->toString();

  return $data;
}
```

---

## Schema.org JobPosting Specification

### Required Properties

Google requires the following properties for job postings to be eligible for rich results:

| Property | Type | Description | Example |
|----------|------|-------------|---------|
| `title` | Text | Job title | "Software Engineer" |
| `description` | Text | Full job description (HTML allowed) | "We are seeking a talented..." |
| `datePosted` | Date | ISO 8601 format | "2026-02-06" |
| `hiringOrganization` | Organization | Company hiring | `{"@type": "Organization", "name": "Acme Corp"}` |
| `jobLocation` | Place | Job location | See location section below |

### Recommended Properties

These are not required but highly recommended for better visibility:

| Property | Type | Description | Example |
|----------|------|-------------|---------|
| `validThrough` | Date | Job expiration date | "2026-03-31" |
| `employmentType` | Text[] | Type of employment | ["FULL_TIME", "CONTRACTOR"] |
| `baseSalary` | MonetaryAmount | Salary information | See salary section below |
| `identifier` | PropertyValue | Unique identifier | `{"name": "MySite", "value": "123"}` |
| `url` | URL | Direct link to job posting | "https://example.com/jobs/123" |

### Employment Type Values

Use one or more of these standardized values:

- `FULL_TIME` - Full-time position
- `PART_TIME` - Part-time position
- `CONTRACTOR` - Independent contractor
- `TEMPORARY` - Temporary position
- `INTERN` - Internship
- `VOLUNTEER` - Volunteer position
- `PER_DIEM` - Per diem work
- `OTHER` - Other types

### Location Specification

#### Physical Location

```json
"jobLocation": {
  "@type": "Place",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "555 Clancy St",
    "addressLocality": "Detroit",
    "addressRegion": "MI",
    "postalCode": "48201",
    "addressCountry": "US"
  }
}
```

#### Remote Work

For fully remote positions:

```json
"jobLocation": {
  "@type": "Place",
  "address": {
    "@type": "PostalAddress",
    "addressCountry": "US"
  }
},
"jobLocationType": "TELECOMMUTE"
```

#### Multiple Locations

```json
"jobLocation": [
  {
    "@type": "Place",
    "address": {
      "@type": "PostalAddress",
      "addressLocality": "Detroit",
      "addressRegion": "MI",
      "addressCountry": "US"
    }
  },
  {
    "@type": "Place",
    "address": {
      "@type": "PostalAddress",
      "addressLocality": "Austin",
      "addressRegion": "TX",
      "addressCountry": "US"
    }
  }
]
```

### Salary Specification

#### Annual Salary Range

```json
"baseSalary": {
  "@type": "MonetaryAmount",
  "currency": "USD",
  "value": {
    "@type": "QuantitativeValue",
    "minValue": 80000,
    "maxValue": 120000,
    "unitText": "YEAR"
  }
}
```

#### Hourly Rate

```json
"baseSalary": {
  "@type": "MonetaryAmount",
  "currency": "USD",
  "value": {
    "@type": "QuantitativeValue",
    "value": 25.00,
    "unitText": "HOUR"
  }
}
```

#### Monthly Salary

```json
"baseSalary": {
  "@type": "MonetaryAmount",
  "currency": "USD",
  "value": {
    "@type": "QuantitativeValue",
    "value": 8000,
    "unitText": "MONTH"
  }
}
```

### Additional Optional Properties

| Property | Description | Example |
|----------|-------------|---------|
| `skills` | Required or desired skills | "Python, JavaScript, SQL" |
| `educationRequirements` | Education requirements | "Bachelor's degree in Computer Science" |
| `experienceRequirements` | Experience requirements | "3+ years experience" |
| `qualifications` | Required qualifications | "Must have valid driver's license" |
| `responsibilities` | Job responsibilities | "Design and implement features" |
| `benefits` | Benefits offered | "Health insurance, 401k matching" |
| `industry` | Industry sector | "Technology" |
| `occupationalCategory` | O*NET-SOC code | "15-1252.00" |
| `workHours` | Working hours | "40 hours per week" |
| `salaryCurrency` | ISO 4217 currency code | "USD" |

---

## Implementation Examples

### Example 1: Complete Job Posting with All Fields

```json
{
  "@context": "https://schema.org/",
  "@type": "JobPosting",
  "title": "Senior Software Engineer",
  "description": "<p>We are seeking a talented Senior Software Engineer to join our team. You will be responsible for designing, developing, and maintaining high-quality software applications.</p><h3>Responsibilities</h3><ul><li>Design and implement software features</li><li>Code review and mentoring</li><li>Collaborate with cross-functional teams</li></ul>",
  "identifier": {
    "@type": "PropertyValue",
    "name": "Acme Corp Careers",
    "value": "JOB-2026-001"
  },
  "datePosted": "2026-02-06",
  "validThrough": "2026-03-31T23:59:59Z",
  "employmentType": ["FULL_TIME", "CONTRACTOR"],
  "hiringOrganization": {
    "@type": "Organization",
    "name": "Acme Corporation",
    "sameAs": "https://www.acmecorp.com",
    "logo": "https://www.acmecorp.com/logo.png"
  },
  "jobLocation": {
    "@type": "Place",
    "address": {
      "@type": "PostalAddress",
      "streetAddress": "123 Tech Blvd",
      "addressLocality": "San Francisco",
      "addressRegion": "CA",
      "postalCode": "94105",
      "addressCountry": "US"
    }
  },
  "baseSalary": {
    "@type": "MonetaryAmount",
    "currency": "USD",
    "value": {
      "@type": "QuantitativeValue",
      "minValue": 120000,
      "maxValue": 180000,
      "unitText": "YEAR"
    }
  },
  "skills": "Python, JavaScript, React, Node.js, Docker, Kubernetes",
  "educationRequirements": {
    "@type": "EducationalOccupationalCredential",
    "credentialCategory": "bachelor degree"
  },
  "experienceRequirements": {
    "@type": "OccupationalExperienceRequirements",
    "monthsOfExperience": 60
  },
  "qualifications": "Strong problem-solving skills, excellent communication, team player",
  "responsibilities": "Design scalable systems, mentor junior engineers, participate in architecture decisions",
  "benefits": "Health insurance, dental, vision, 401k matching, unlimited PTO, stock options",
  "url": "https://www.acmecorp.com/careers/senior-software-engineer-2026-001"
}
```

### Example 2: Remote Position

```json
{
  "@context": "https://schema.org/",
  "@type": "JobPosting",
  "title": "Remote Customer Support Specialist",
  "description": "Join our fully remote customer support team. Help customers solve problems and have a great experience with our product.",
  "datePosted": "2026-02-06",
  "validThrough": "2026-04-06",
  "employmentType": ["FULL_TIME"],
  "hiringOrganization": {
    "@type": "Organization",
    "name": "CloudTech Solutions",
    "sameAs": "https://www.cloudtech.example",
    "logo": "https://www.cloudtech.example/assets/logo.png"
  },
  "jobLocation": {
    "@type": "Place",
    "address": {
      "@type": "PostalAddress",
      "addressCountry": "US"
    }
  },
  "jobLocationType": "TELECOMMUTE",
  "baseSalary": {
    "@type": "MonetaryAmount",
    "currency": "USD",
    "value": {
      "@type": "QuantitativeValue",
      "value": 50000,
      "unitText": "YEAR"
    }
  },
  "url": "https://www.cloudtech.example/jobs/customer-support-2026"
}
```

### Example 3: Internship with Stipend

```json
{
  "@context": "https://schema.org/",
  "@type": "JobPosting",
  "title": "Summer Software Engineering Intern",
  "description": "Paid internship for college students interested in software development. Work on real projects with mentorship from senior engineers.",
  "datePosted": "2026-02-06",
  "validThrough": "2026-05-31",
  "employmentType": ["INTERN"],
  "hiringOrganization": {
    "@type": "Organization",
    "name": "Tech Startup Inc",
    "sameAs": "https://www.techstartup.example"
  },
  "jobLocation": {
    "@type": "Place",
    "address": {
      "@type": "PostalAddress",
      "addressLocality": "Austin",
      "addressRegion": "TX",
      "addressCountry": "US"
    }
  },
  "baseSalary": {
    "@type": "MonetaryAmount",
    "currency": "USD",
    "value": {
      "@type": "QuantitativeValue",
      "value": 25,
      "unitText": "HOUR"
    }
  },
  "educationRequirements": "Currently enrolled in Computer Science or related degree program",
  "url": "https://www.techstartup.example/internships/summer-2026"
}
```

---

## Testing and Validation

### Step 1: Rich Results Test

Google provides a free tool to validate your structured data:

**Rich Results Test**: https://search.google.com/test/rich-results

**How to Use**:
1. Navigate to the Rich Results Test URL
2. Enter your job posting URL or paste the HTML code
3. Click "Test URL" or "Test Code"
4. Review results for errors and warnings
5. Fix any issues found

**What to Look For**:
- ✅ "Page is eligible for rich results" message
- ✅ JobPosting type detected
- ✅ All required properties present
- ⚠️ Warnings about recommended properties (fix if possible)
- ❌ Errors that prevent indexing (must fix)

### Step 2: Schema Markup Validator

Alternative validation tool:

**Schema.org Validator**: https://validator.schema.org/

**How to Use**:
1. Paste your JSON-LD code
2. Review detected types and properties
3. Verify no errors or warnings

### Step 3: Google Search Console

Monitor how Google indexes your job postings:

**URL**: https://search.google.com/search-console

**Steps**:
1. Add and verify your property (website)
2. Navigate to **Enhancements** → **Job Postings**
3. Review indexing status:
   - Valid job postings
   - Job postings with warnings
   - Invalid job postings (with errors)
4. Click on individual issues to see affected URLs
5. Fix issues and request re-indexing

### Step 4: URL Inspection Tool

Test individual job posting pages:

1. In Google Search Console, use **URL Inspection** tool
2. Enter the full URL of a job posting page
3. Click "Test Live URL"
4. Review:
   - Crawlability
   - Indexability
   - Mobile usability
   - Structured data detected
5. Request indexing if the page is new

### Step 5: Manual Search Test

After indexing (can take 1-7 days):

1. Search Google for your job title + location
2. Example: `"Software Engineer San Francisco"`
3. Look for your job in the "Jobs" section
4. Click "More jobs" to see if your posting appears
5. Verify all details are correct (title, company, salary, etc.)

### Step 6: Structured Data Testing Script

Create an automated test script in job_hunter:

```php
<?php

/**
 * @file
 * Drush command to validate job posting structured data.
 */

use Drupal\node\Entity\Node;

/**
 * Drush command to test job posting JSON-LD.
 */
function job_hunter_drush_command() {
  return [
    'job-hunter-validate-structured-data' => [
      'description' => 'Validate job posting structured data.',
      'aliases' => ['jhvsd'],
      'arguments' => [
        'nid' => 'Node ID of job posting to validate (optional, validates all if not provided)',
      ],
    ],
  ];
}

/**
 * Validate structured data for job postings.
 */
function drush_job_hunter_validate_structured_data($nid = NULL) {
  if ($nid) {
    $nodes = [Node::load($nid)];
  }
  else {
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'job_posting')
      ->condition('status', 1)
      ->execute();
    $nodes = Node::loadMultiple($nids);
  }

  foreach ($nodes as $node) {
    $json_ld = _job_hunter_build_job_posting_json_ld($node);
    
    // Validate required fields.
    $required = ['title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation'];
    $missing = [];
    
    foreach ($required as $field) {
      if (empty($json_ld[$field])) {
        $missing[] = $field;
      }
    }
    
    if (empty($missing)) {
      drush_print("✅ Node {$node->id()}: Valid structured data");
    }
    else {
      drush_print("❌ Node {$node->id()}: Missing required fields: " . implode(', ', $missing));
    }
  }
}
```

**Usage**:
```bash
drush job-hunter-validate-structured-data
drush job-hunter-validate-structured-data 123
```

---

## Best Practices

### 1. Content Quality

- **Detailed Descriptions**: Provide comprehensive job descriptions (minimum 200 words)
- **Accurate Information**: Ensure all data is current and accurate
- **Professional Writing**: Use proper grammar and formatting
- **No Misleading Content**: Don't use clickbait or false information

### 2. Unique URLs

- **One Job Per URL**: Each job posting must have its own unique URL
- **Persistent URLs**: URLs should remain stable (don't change after publishing)
- **Clean URLs**: Use human-readable URLs (e.g., `/jobs/software-engineer-123`)
- **Canonical Tags**: Use canonical tags if the same job appears on multiple pages

### 3. Regular Updates

- **Remove Expired Jobs**: Delete or unpublish jobs when positions are filled
- **Update validThrough**: Set realistic expiration dates
- **Mark as Expired**: Use 410 status code for permanently removed jobs
- **Reindex Promptly**: Request reindexing when jobs are updated

### 4. Complete Data

- **Include Salary When Possible**: Jobs with salary info get more visibility
- **Specify Location Precisely**: Include city and state at minimum
- **Add Employment Type**: Helps users filter results
- **Provide Company Info**: Logo and website improve trust

### 5. Mobile Optimization

- **Responsive Design**: Ensure job pages work well on mobile devices
- **Fast Loading**: Optimize page speed (target < 2 seconds)
- **Easy Application**: Make the application process mobile-friendly
- **Clear CTAs**: Prominent "Apply" buttons

### 6. Structured Data Maintenance

- **Regular Validation**: Test structured data monthly
- **Monitor Search Console**: Check for errors weekly
- **Update Schema**: Keep up with Schema.org changes
- **Version Control**: Track changes to JSON-LD templates

### 7. SEO Best Practices

- **Title Tags**: Include job title and location
- **Meta Descriptions**: Write compelling descriptions
- **Headers**: Use proper H1, H2, H3 structure
- **Internal Links**: Link to related jobs and company pages
- **XML Sitemap**: Include job postings in sitemap
- **robots.txt**: Ensure job pages are crawlable

### 8. User Experience

- **Clear Application Process**: Make it easy to apply
- **Contact Information**: Provide ways to ask questions
- **Company Culture**: Showcase your workplace
- **Benefits**: Highlight perks and benefits
- **Response Time**: Set expectations for hearing back

---

## Common Pitfalls

### 1. Missing Required Fields

**Problem**: Job postings without required fields won't appear in Google for Jobs.

**Solution**: Always include:
- title
- description
- datePosted
- hiringOrganization
- jobLocation

**Example Error**:
```
Missing field "datePosted" (required)
```

### 2. Duplicate Content

**Problem**: Same job posted at multiple URLs confuses Google.

**Solution**:
- Use canonical tags to indicate the primary URL
- Remove duplicate listings
- Use 301 redirects for moved jobs

### 3. Jobs Behind Login Walls

**Problem**: Google can't crawl pages that require authentication.

**Solution**:
- Make job listing pages publicly accessible
- Only require login for the application process
- Use proper access control in Drupal

### 4. Invalid JSON-LD Syntax

**Problem**: Syntax errors prevent Google from parsing structured data.

**Common Issues**:
- Trailing commas in JSON
- Unescaped quotes in text
- Missing closing braces
- Invalid date formats

**Solution**:
- Use a JSON validator before deployment
- Test in Rich Results Test tool
- Implement automated validation

**Example Fix**:
```json
// ❌ Wrong (trailing comma)
{
  "title": "Engineer",
  "description": "Great job",
}

// ✅ Correct
{
  "title": "Engineer",
  "description": "Great job"
}
```

### 5. Incorrect Date Formats

**Problem**: Google requires ISO 8601 format dates.

**Wrong**:
- `02/06/2026` (US format)
- `6th February 2026` (written format)
- `2026-2-6` (missing leading zeros)

**Correct**:
- `2026-02-06` (date only)
- `2026-02-06T10:00:00-05:00` (with time and timezone)
- `2026-02-06T15:00:00Z` (UTC)

### 6. Salary Information Errors

**Problem**: Incorrect salary format or currency.

**Common Mistakes**:
- Using commas in numbers: `100,000` (wrong)
- Wrong currency code: `$` instead of `USD`
- Missing unitText: Not specifying YEAR, MONTH, or HOUR

**Correct Format**:
```json
"baseSalary": {
  "@type": "MonetaryAmount",
  "currency": "USD",
  "value": {
    "@type": "QuantitativeValue",
    "value": 100000,
    "unitText": "YEAR"
  }
}
```

### 7. Invalid Organization Data

**Problem**: Incomplete or incorrect hiring organization info.

**Must Include**:
- Organization name
- Organization type
- Valid URL for sameAs property

**Example**:
```json
"hiringOrganization": {
  "@type": "Organization",
  "name": "Acme Corp",
  "sameAs": "https://www.acmecorp.com"
}
```

### 8. Location Issues

**Problem**: Vague or incorrect location information.

**Don't Do**:
- "USA" only (too vague)
- "Remote" in addressLocality (use jobLocationType instead)
- Multiple locations in one address object

**Do**:
- Provide specific city and state
- Use jobLocationType for remote jobs
- Use array of locations for multiple locations

### 9. Expired Jobs Still Indexed

**Problem**: Old job postings remain in Google search results.

**Solutions**:
- Set validThrough date
- Return 410 (Gone) status for removed jobs
- Use noindex meta tag for filled positions
- Request URL removal in Search Console

### 10. Character Encoding Issues

**Problem**: Special characters display incorrectly.

**Solution**:
- Use UTF-8 encoding
- Escape special characters in JSON
- Use `|escape('js')` in Twig templates
- Test with international characters

---

## SEO Optimization

### Technical SEO

1. **Page Speed**
   - Target: < 2 seconds load time
   - Optimize images
   - Enable caching
   - Minify CSS/JS

2. **Mobile Responsiveness**
   - Test with Google Mobile-Friendly Test
   - Use responsive design
   - Optimize for touch interfaces

3. **HTTPS**
   - Required for rich results
   - Use valid SSL certificate
   - Redirect HTTP to HTTPS

4. **Sitemap**
   - Include job posting URLs
   - Update after changes
   - Submit to Search Console

5. **Robots.txt**
   - Allow Googlebot access to job pages
   - Don't block CSS/JS needed for rendering

### Content SEO

1. **Keywords**
   - Include relevant keywords in title
   - Natural language in description
   - Don't keyword stuff

2. **Title Tags**
   - Format: "Job Title - Company - Location"
   - Keep under 60 characters
   - Include primary keyword

3. **Meta Descriptions**
   - 150-160 characters
   - Compelling call-to-action
   - Include job title and location

4. **Headers**
   - Use H1 for job title
   - H2 for main sections
   - H3 for subsections

5. **Internal Linking**
   - Link to company profile
   - Link to related jobs
   - Link to application instructions

### User Engagement

1. **Clear Application Process**
   - Easy-to-find "Apply" button
   - Mobile-friendly forms
   - Multiple application methods

2. **Rich Media**
   - Company photos
   - Office videos
   - Team member bios

3. **Social Proof**
   - Employee testimonials
   - Company reviews
   - Awards and recognition

4. **Trust Signals**
   - Contact information
   - Privacy policy
   - Equal opportunity statement

---

## Monitoring and Maintenance

### Regular Tasks

#### Daily
- Monitor Search Console for critical errors
- Check that new jobs are being indexed
- Review application submissions

#### Weekly
- Review "Job Postings" report in Search Console
- Check for warnings or errors
- Verify structured data is being detected

#### Monthly
- Validate structured data on sample of job pages
- Review click-through rates from Google for Jobs
- Update documentation with any schema changes
- Archive filled positions

#### Quarterly
- Full audit of all active job postings
- Review and update field configurations
- Check for Schema.org specification updates
- Analyze performance metrics

### Key Metrics to Track

1. **Indexing Status**
   - Number of valid job postings
   - Number of errors
   - Number of warnings
   - Coverage reports

2. **Search Performance**
   - Impressions in Google Search
   - Click-through rate (CTR)
   - Average position
   - Queries driving traffic

3. **User Behavior**
   - Time on page
   - Bounce rate
   - Application completion rate
   - Source/medium breakdown

4. **Technical Metrics**
   - Page load time
   - Mobile usability score
   - Core Web Vitals (LCP, FID, CLS)
   - Error rates

### Monitoring Tools

1. **Google Search Console**
   - Job Postings enhancement report
   - URL inspection
   - Performance reports
   - Coverage reports

2. **Google Analytics**
   - Traffic from organic search
   - User behavior flows
   - Conversion tracking
   - Custom events

3. **Third-Party Tools**
   - SEMrush
   - Ahrefs
   - Moz
   - Screaming Frog

### Alert Configuration

Set up alerts for:
- New errors in Search Console
- Significant drop in indexed job postings
- Drop in organic traffic to job pages
- Increase in page load time
- Mobile usability issues

### Maintenance Checklist

**Monthly Review**:
```
□ Check Search Console for errors
□ Validate 5 random job postings with Rich Results Test
□ Review filled positions and archive them
□ Update expired jobs with new validThrough dates
□ Check for Schema.org updates
□ Review application analytics
□ Update documentation as needed
```

**Quarterly Audit**:
```
□ Full validation of all active job postings
□ Review field configurations in Drupal
□ Test application process end-to-end
□ Analyze performance metrics
□ Review competitor job postings
□ Update best practices based on learnings
□ Training for content editors
```

---

## Additional Resources

### Official Documentation

- **Google Job Posting Guidelines**: https://developers.google.com/search/docs/appearance/structured-data/job-posting
- **Schema.org JobPosting**: https://schema.org/JobPosting
- **Google Search Central**: https://developers.google.com/search
- **Rich Results Test**: https://search.google.com/test/rich-results
- **Google Search Console**: https://search.google.com/search-console

### Testing Tools

- **Rich Results Test**: https://search.google.com/test/rich-results
- **Schema Markup Validator**: https://validator.schema.org/
- **Google Mobile-Friendly Test**: https://search.google.com/test/mobile-friendly
- **PageSpeed Insights**: https://pagespeed.web.dev/
- **Structured Data Linter**: http://linter.structured-data.org/

### Community Resources

- **Stack Overflow - structured-data tag**: https://stackoverflow.com/questions/tagged/structured-data
- **Schema.org Community Group**: https://www.w3.org/community/schemaorg/
- **Google Search Central Help Community**: https://support.google.com/webmasters/community
- **Drupal SEO Group**: https://www.drupal.org/node/add/project-issue/seo

### Related Drupal Modules

- **Metatag**: https://www.drupal.org/project/metatag
- **Schema.org Metatag**: https://www.drupal.org/project/schema_metatag
- **Simple XML Sitemap**: https://www.drupal.org/project/simple_sitemap
- **Job Posting**: https://www.drupal.org/project/job

### Further Reading

- **Structured Data Handbook**: https://structured-data.org/
- **JSON-LD Playground**: https://json-ld.org/playground/
- **SEO for Job Boards**: Multiple articles available via search
- **Employment Type Codes**: https://schema.org/EmploymentType

### Training Resources

- **Google Search Central YouTube**: Video tutorials on structured data
- **Schema.org Documentation**: Comprehensive property reference
- **Drupal Theming Guide**: For implementing templates
- **JSON-LD Tutorial**: For understanding the format

---

## Support and Troubleshooting

### Common Questions

**Q: How long does it take for jobs to appear in Google?**  
A: Typically 1-7 days after Google crawls and indexes your pages. Use URL Inspection tool to request indexing.

**Q: My jobs aren't showing in Google for Jobs. What should I check?**  
A:
1. Validate structured data with Rich Results Test
2. Check Search Console for errors
3. Ensure pages are publicly accessible
4. Verify no robots.txt blocking
5. Check that jobs aren't expired

**Q: Can I use Microdata instead of JSON-LD?**  
A: Yes, but JSON-LD is recommended by Google and easier to maintain.

**Q: Do I need to submit my sitemap to Google?**  
A: It's recommended but not required. Google will find and crawl your pages naturally, but a sitemap helps.

**Q: How often should I update my job postings?**  
A: Update whenever information changes. Remove filled positions within 2-3 days.

**Q: Can I post the same job on multiple pages?**  
A: Avoid this. Use canonical tags if necessary and ensure each URL has unique content.

### Getting Help

**For job_hunter module issues**:
- Check module documentation
- Review logs: `/admin/reports/dblog`
- Contact module maintainer

**For Google for Jobs issues**:
- Google Search Central Help Community
- Stack Overflow with appropriate tags
- Google Search Console support

**For Drupal issues**:
- Drupal.org issue queue
- Drupal Slack channels
- Drupal Stack Exchange

---

## Conclusion

Integrating the `job_hunter` module with Google for Jobs through proper Schema.org markup provides significant benefits for job visibility and candidate quality. By following this guide, you can:

1. ✅ Implement proper structured data using JSON-LD
2. ✅ Validate your implementation with Google's tools
3. ✅ Monitor performance through Search Console
4. ✅ Maintain high-quality job postings
5. ✅ Optimize for search visibility

**Next Steps**:
1. Review existing job_posting content type fields
2. Add any missing required fields
3. Implement JSON-LD template or module code
4. Test with Rich Results Test
5. Submit sitemap to Search Console
6. Monitor indexing status
7. Train content editors on best practices

**Remember**: Google for Jobs is not a paid service. Success depends on:
- Quality of job content
- Proper technical implementation
- Regular maintenance and updates
- User-friendly application process

Good luck with your integration! 🚀

---

**Document Version**: 1.0  
**Last Updated**: February 6, 2026  
**Author**: Job Hunter Development Team  
**Module Version**: 1.x
