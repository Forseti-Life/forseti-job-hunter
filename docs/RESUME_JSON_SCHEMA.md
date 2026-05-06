# Resume Parsed Data JSON Schema

## Overview

This document defines the JSON schema used for storing parsed resume data in the `jobhunter_resume_parsed_data.parsed_data` field. The schema is designed to preserve all data from resume files with full fidelity while enabling structured querying and AI-powered job matching.

**Schema Version:** 1.0  
**Last Updated:** 2026-02-02

## Storage Location

- **Table:** `jobhunter_resume_parsed_data`
- **Field:** `parsed_data` (LONGTEXT)
- **Format:** JSON string

## Schema Structure

### Root Level Properties

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `schema_version` | string | Yes | Schema version for backward compatibility |
| `extraction_metadata` | object | Yes | Source file information and extraction details |
| `contact_info` | object | Yes | Personal and contact information |
| `executive_profile` | object | No | Executive summary and key metrics |
| `strategic_differentiators` | array | No | Key value propositions |
| `professional_experience` | array | Yes | Detailed work history |
| `consulting_practice` | object | No | Consulting business details (if applicable) |
| `early_career` | object | No | Consolidated early career positions |
| `education` | array | Yes | Educational background |
| `technical_expertise` | object | No | Skills organized by category |
| `leadership_philosophy` | object | No | Leadership style and influences |
| `demonstration_projects` | array | No | Portfolio projects |
| `publications` | array | No | Published works and research papers |
| `certifications` | array | No | Professional certifications and licenses |
| `patents` | array | No | Patent filings and grants |
| `awards_and_honors` | array | No | Awards, recognitions, and honors |
| `languages` | array | No | Language proficiencies |
| `job_search_preferences` | object | No | User-provided job search criteria and eligibility |

---

## Detailed Schema Definitions

### `extraction_metadata`

Tracks source file information for traceability.

```json
{
  "extraction_metadata": {
    "source_file_id": 56,
    "source_filename": "KeithAumillerA.docx",
    "extracted_at": "2026-02-02T10:23:39Z",
    "character_count": 14060
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `source_file_id` | integer | Drupal file entity ID |
| `source_filename` | string | Original filename |
| `extracted_at` | string (ISO 8601) | Extraction timestamp |
| `character_count` | integer | Characters in extracted text |

---

### `contact_info`

Personal and contact information with structured web presence.

```json
{
  "contact_info": {
    "full_name": "Jane Doe",
    "credentials": ["MBA"],
    "headline": "Data Engineering Leader",
    "location": {
      "city": "Sample City",
      "state": "ST"
    },
    "phone": "(555) 555-5555",
    "email": "support@forseti.life",
    "websites": [
      {"type": "personal", "url": "https://forseti.life"},
      {"type": "github", "url": "https://github.com/your-handle"},
      {"type": "linkedin", "url": "https://linkedin.com/in/your-handle"},
      {"type": "demo", "url": "https://forseti.life/demo"}
    ],
    "linkedin": {
      "followers": "0",
      "groups_administered": ["Sample Community Group"]
    }
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `full_name` | string | Full legal name |
| `credentials` | array[string] | Degrees and certifications after name |
| `headline` | string | Professional title/tagline |
| `location.city` | string | City |
| `location.state` | string | State abbreviation |
| `phone` | string | Phone number |
| `email` | string | Email address |
| `websites` | array[object] | Web presence with type classification |
| `websites[].type` | string | One of: personal, github, linkedin, demo, portfolio |
| `websites[].url` | string | Full URL |
| `linkedin` | object | LinkedIn-specific metadata |

---

### `executive_profile`

High-level professional summary with quantified metrics.

```json
{
  "executive_profile": {
    "summary": "Transformational data and AI leader with 20+ years...",
    "industry_focus": ["financial services", "energy", "healthcare", "technology"],
    "key_metrics": [
      {
        "metric": "revenue_generated",
        "value": "$20M+",
        "context": "transformed single-analyst to revenue-generating practice"
      },
      {
        "metric": "platform_transactions",
        "value": "$1B+",
        "context": "daily transaction platforms"
      }
    ]
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `summary` | string | Executive summary paragraph |
| `industry_focus` | array[string] | Target industries |
| `key_metrics` | array[object] | Quantified achievements |
| `key_metrics[].metric` | string | Metric identifier |
| `key_metrics[].value` | string | Metric value with units |
| `key_metrics[].context` | string | Context for the metric |

---

### `strategic_differentiators`

Key value propositions and competitive advantages.

```json
{
  "strategic_differentiators": [
    {
      "title": "Enterprise Data & AI Leadership",
      "description": "Led comprehensive digital transformations across multiple industries..."
    }
  ]
}
```

---

### `professional_experience`

Detailed work history with categorized achievements.

```json
{
  "professional_experience": [
    {
      "company": "AmeriGas UGI",
      "title": "Director of Advanced Analytics",
      "employment_type": "direct",
      "via_company": null,
      "start_date": "2022-06",
      "end_date": "2025-10",
      "location": "Philadelphia, PA",
      "company_context": "one of the largest propane distributors in the United States...",
      "responsibility_categories": [
        {
          "category": "Enterprise AI Vision & Strategic Transformation",
          "achievements": [
            {
              "text": "Partnered with C-suite leadership to establish comprehensive AI vision...",
              "metrics": ["multi-million-dollar data infrastructure investments"],
              "technologies": ["Dataiku", "Databricks", "Snowflake"],
              "keywords": ["C-suite", "AI vision", "board approval"]
            }
          ]
        }
      ]
    }
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `company` | string | Employer name |
| `title` | string | Job title |
| `employment_type` | string | "direct" or "consulting" |
| `via_company` | string\|null | Consulting company name if applicable |
| `start_date` | string | YYYY-MM format |
| `end_date` | string\|null | YYYY-MM format, null if current |
| `location` | string | City, State |
| `company_context` | string | Brief company description |
| `responsibility_categories` | array | Grouped achievements |
| `responsibility_categories[].category` | string | Category heading |
| `responsibility_categories[].achievements` | array | Achievement list |
| `achievements[].text` | string | Achievement description |
| `achievements[].metrics` | array[string] | Quantified results |
| `achievements[].technologies` | array[string] | Technologies mentioned |
| `achievements[].keywords` | array[string] | Searchable keywords |

---

### `consulting_practice`

For candidates with consulting/freelance business.

```json
{
  "consulting_practice": {
    "company": "St. Louis Integration LLC",
    "title": "Founder & Principal Consultant",
    "start_date": "2007-06",
    "end_date": null,
    "is_current": true,
    "location": "Philadelphia, PA",
    "website": "https://stlouisintegration.com/",
    "description": "Provides specialized executive consulting...",
    "notable_engagements": [
      {
        "client": "AbbVie",
        "role": "Clinical Data Management Vendor Management Consultant",
        "description": "Engaged to establish an oncology early development data management pipeline."
      }
    ]
  }
}
```

---

### `early_career`

Consolidated early career positions without full detail.

```json
{
  "early_career": {
    "period": "2000-2011",
    "summary": "Built comprehensive expertise across enterprise data systems...",
    "positions": [
      {
        "company": "Edward Jones Investments",
        "duration": "5 years",
        "focus": "Led enterprise data systems transformation..."
      },
      {
        "company": "MasterCard",
        "duration": null,
        "focus": "Contributed to global payment processing infrastructure..."
      }
    ]
  }
}
```

---

### `education`

Educational background with structured date fields.

```json
{
  "education": [
    {
      "institution": "Washington University in St. Louis, Olin School of Business",
      "degree": "Master of Business Administration",
      "abbreviation": "MBA",
      "field": null,
      "start_date": "2009-08",
      "end_date": "2011-05"
    },
    {
      "institution": "Truman State University",
      "location": "Kirksville, MO",
      "degree": "Bachelor of Science",
      "abbreviation": "BS",
      "field": "Psychology",
      "start_date": "1996-08",
      "end_date": "2000-05"
    }
  ]
}
```

---

### `technical_expertise`

Skills organized by category with optional subcategories for industry-specific skills.

```json
{
  "technical_expertise": {
    "categories": [
      {
        "name": "Data Engineering & Architecture",
        "skills": [
          "Enterprise Data Architecture",
          "Cloud-Native Platforms (AWS, Azure, GCP)",
          "Data Lake and Warehouse Design"
        ]
      },
      {
        "name": "Industry-Specific Technologies",
        "subcategories": [
          {
            "industry": "Financial Services",
            "skills": ["Payment Processing Systems", "Fraud Detection"]
          },
          {
            "industry": "Healthcare & Pharmaceutical",
            "skills": ["Clinical Data Management", "Biostatistics"]
          }
        ]
      },
      {
        "name": "Regulatory Compliance",
        "frameworks": ["FDA", "FERC", "NERC", "SOX", "Basel III", "GDPR"]
      }
    ]
  }
}
```

---

### `leadership_philosophy`

Leadership style, influences, and key themes.

```json
{
  "leadership_philosophy": {
    "statement": "I excel at designing and implementing data services organizations...",
    "influences": ["GE methodology", "Ray Dalio leadership styles"],
    "key_themes": ["scalable infrastructure", "high-performing teams", "consensus building"]
  }
}
```

---

### `demonstration_projects`

Portfolio and demo projects.

```json
{
  "demonstration_projects": [
    {
      "name": "GenAI Demo Site",
      "url": "https://thetruthperspective.org",
      "technologies": ["AWS", "open source CMS", "generative AI"],
      "description": "AWS-hosted GenAI solution utilizing..."
    }
  ]
}
```

---

### `job_search_preferences`

User-provided job search criteria and employment eligibility. These fields are typically entered via the profile form rather than extracted from resumes.

```json
{
  "job_search_preferences": {
    "us_work_authorized": "yes",
    "requires_sponsorship": "no",
    "work_authorization": "us_citizen",
    "experience_years": 20,
    "education_level": "masters",
    "certifications": "PMP, AWS Solutions Architect",
    "target_titles": "VP Data Engineering\nDirector of AI",
    "keywords": "data engineering, machine learning, cloud architecture",
    "salary_min": 200000,
    "salary_max": 300000,
    "salary_change_minimum": 15,
    "remote_preference": "hybrid",
    "relocation_willing": true,
    "available_start_date": "2026-03-01",
    "references_available": true,
    "cover_letter_template": "Dear Hiring Manager..."
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `us_work_authorized` | string | "yes" or "no" - Authorized to work in US |
| `requires_sponsorship` | string | "yes" or "no" - Requires visa sponsorship |
| `work_authorization` | string | us_citizen, permanent_resident, h1b, f1, visa_required, other |
| `experience_years` | integer | Total years of professional experience |
| `education_level` | string | high_school, associates, bachelors, masters, doctoral |
| `certifications` | string | Comma-separated certifications |
| `target_titles` | string | Newline-separated target job titles |
| `keywords` | string | Job search keywords |
| `salary_min` | integer | Minimum salary expectation (USD) |
| `salary_max` | integer | Maximum salary expectation (USD) |
| `salary_change_minimum` | integer | Minimum percentage salary increase required |
| `remote_preference` | string | remote, hybrid, onsite |
| `relocation_willing` | boolean | Willing to relocate |
| `available_start_date` | string | Earliest start date (YYYY-MM-DD) |
| `references_available` | boolean | Has references available |
| `cover_letter_template` | string | Default cover letter template |

---

## Usage in Job Matching

The structured schema enables efficient job matching by:

1. **Keyword Extraction**: `achievements[].keywords` and `technical_expertise.categories[].skills` provide searchable terms
2. **Metric-Based Matching**: `executive_profile.key_metrics` and `achievements[].metrics` enable quantified comparisons
3. **Industry Targeting**: `executive_profile.industry_focus` and `technical_expertise.categories[].subcategories[].industry` support industry-specific matching
4. **Technology Matching**: `achievements[].technologies` and `technical_expertise.categories[].skills` enable technology stack matching
5. **Experience Level**: Date ranges enable experience year calculations

---

## GenAI Prompt Requirements

When calling AWS Bedrock Claude for resume parsing, the prompt must instruct the model to return JSON conforming to this schema. See `UserProfileForm::parseResumeProdMode()` for the production implementation.

---

## Consolidation Logic

When merging multiple resumes into `jobhunter_job_seeker.consolidated_profile_json`:

1. **Skills**: Union of all skills, deduplicated
2. **Experience Years**: Maximum value across resumes
3. **Education Level**: Highest level across resumes
4. **Professional Experience**: Merge with source resume tracking
5. **Key Metrics**: Combine with deduplication by metric name

See `UserProfileForm::buildConsolidatedJsonAndApplyToProfile()` for implementation.

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-02-02 | Initial schema definition |
