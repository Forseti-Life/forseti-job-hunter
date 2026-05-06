# Job Requisition JSON Schema

This document defines the standard JSON structures for job requisitions stored in `jobhunter_job_requirements`. These structures are passed to the GenAI backend along with the user's resume (`consolidated_profile_json`) to generate tailored resumes.

**Related Schema:** See `RESUME_JSON_SCHEMA.md` for the resume/profile JSON structure.

---

## Database Fields

| Field | Type | Purpose |
|-------|------|---------|
| `raw_posting_text` | LONGTEXT | Original pasted job posting text |
| `extracted_json` | LONGTEXT | AI-extracted structured job data |
| `skills_required_json` | LONGTEXT | Must-have and nice-to-have skills |
| `keywords_json` | LONGTEXT | Important keywords from posting |
| `source_platform` | VARCHAR(100) | linkedin, indeed, company_site, etc. |
| `ai_extraction_status` | VARCHAR(32) | pending, completed, failed |

---

## extracted_json

Core structured data extracted from the job posting.

```json
{
  "meta": {
    "extraction_date": "2026-02-02T15:07:16Z",
    "extraction_version": "1.0",
    "confidence_score": 0.92
  },
  
  "company": {
    "name": "DrFirst",
    "industry": "Healthcare Technology",
    "sub_industry": "Medication Management / Health IT",
    "company_size": "enterprise",
    "employee_count": null,
    "founded_year": 2000,
    "headquarters": null,
    "description": "Healthcare IT company providing intelligent medication management solutions",
    "key_stats": [
      "100 million patients annually",
      "420,000+ prescribers",
      "71,000 pharmacies",
      "270 EHRs and health information systems",
      "2,000+ hospitals in the U.S.",
      "300 million prescriptions processed annually",
      "25% of US prescriptions"
    ],
    "culture_indicators": [
      "Remote-First",
      "Technology entrepreneurs",
      "Pragmatic approach to AI",
      "Resource-constrained creativity"
    ],
    "tech_stack": [
      "Java", "Python", "Kafka", "Postgres", "AWS", "GCP"
    ]
  },
  
  "position": {
    "title": "VP of Engineering, AI",
    "level": "VP / Executive",
    "department": "Engineering",
    "reports_to": null,
    "team_size": "expanding team of engineers, data scientists",
    "role_type": "player-coach",
    "hands_on_percentage": "20-30%"
  },
  
  "location": {
    "city": null,
    "state": null,
    "country": "USA",
    "remote_options": "Remote-First",
    "travel_required": "healthcare conferences, pharma partner meetings"
  },
  
  "compensation": {
    "salary_min": 200000,
    "salary_max": 280000,
    "currency": "USD",
    "bonus": null,
    "equity": null,
    "benefits": [
      "401K with 50% company match up to 5%",
      "HSA with company contribution up to $500",
      "18 days PTO (increasing with tenure)",
      "Remote-First flexibility"
    ]
  },
  
  "requirements": {
    "experience_years_min": null,
    "experience_years_preferred": null,
    "education": {
      "degree_required": null,
      "fields": [],
      "advanced_preferred": null
    },
    "certifications": []
  }
}
```

---

## skills_required_json

Skills categorized for matching against user profile.

```json
{
  "must_have": [
    {
      "skill": "AI/ML Production Systems",
      "category": "technical",
      "context": "Proven track record taking AI/ML products from concept to production with measurable business impact"
    },
    {
      "skill": "LLM/ML at Scale",
      "category": "technical",
      "context": "Shipped ML systems at scale. Understand prompt engineering, RAG architectures, embedding models, speech to text and text to speech, model evaluation"
    },
    {
      "skill": "Event-driven Architecture",
      "category": "technical",
      "context": "Real-time processing and making decisions in milliseconds"
    },
    {
      "skill": "AWS",
      "category": "technical",
      "context": "Deep AWS experience required"
    },
    {
      "skill": "High-scale Transaction Processing",
      "category": "technical",
      "context": "Built systems that process transactions at scale everyday"
    },
    {
      "skill": "Hands-on Coding",
      "category": "technical",
      "context": "Player-coach role, 20-30% time prototyping"
    },
    {
      "skill": "Team Leadership",
      "category": "leadership",
      "context": "Lead teams, full hiring authority, grow engineering teams"
    },
    {
      "skill": "Executive Presence",
      "category": "leadership",
      "context": "Comfortable presenting at healthcare conferences, meeting with pharma partners"
    },
    {
      "skill": "Strategic Thinking",
      "category": "leadership",
      "context": "Spot opportunities others miss, understand where AI creates genuine value"
    },
    {
      "skill": "Storytelling & Communication",
      "category": "leadership",
      "context": "Translate complex technical concepts into narratives"
    },
    {
      "skill": "AI-Assisted Development",
      "category": "technical",
      "context": "Actively use AI-assisted development tools, understand how they change workflows"
    }
  ],
  "nice_to_have": [
    {
      "skill": "Healthcare Domain",
      "category": "domain",
      "context": "Nice to have but not required. We'll teach you healthcare if you bring the AI expertise"
    },
    {
      "skill": "GCP",
      "category": "technical",
      "context": "GCP a plus"
    },
    {
      "skill": "Network of Talent",
      "category": "leadership",
      "context": "Critical for multiplying impact when headcount is limited"
    }
  ],
  "tech_stack": {
    "languages": ["Java", "Python"],
    "infrastructure": ["AWS", "GCP"],
    "data": ["Kafka", "Postgres"],
    "ai_ml": ["LLM", "RAG", "Embedding Models", "Speech-to-Text", "Text-to-Speech"],
    "methodologies": ["Event-driven Architecture", "AI-assisted Development"]
  }
}
```

### Skill Categories

| Category | Description |
|----------|-------------|
| `technical` | Hard technical skills, tools, technologies |
| `leadership` | Management, team building, executive skills |
| `domain` | Industry-specific knowledge |
| `soft` | Communication, collaboration, etc. |

---

## keywords_json

Keywords and phrases to incorporate into the tailored resume.

```json
{
  "high_frequency": [
    {"term": "AI", "count": 15, "importance": "critical"},
    {"term": "healthcare", "count": 8, "importance": "high"},
    {"term": "scale", "count": 6, "importance": "high"},
    {"term": "ML/LLM", "count": 5, "importance": "critical"},
    {"term": "team", "count": 5, "importance": "high"},
    {"term": "production", "count": 4, "importance": "high"},
    {"term": "architecture", "count": 4, "importance": "high"},
    {"term": "prescriptions", "count": 3, "importance": "medium"}
  ],
  "action_verbs": [
    "build", "lead", "architect", "establish", "evangelize", 
    "translate", "prototype", "scale", "create", "define"
  ],
  "key_phrases": [
    "AI-assisted development",
    "player-coach",
    "hundreds of millions of transactions",
    "reusable AI services",
    "pragmatic AI",
    "concept to production",
    "measurable business impact",
    "Remote-First",
    "full hiring authority",
    "resource-constrained creativity"
  ],
  "domain_terms": [
    "medication management",
    "prescription",
    "EHR",
    "health information systems",
    "clinicians",
    "pharma",
    "medication history"
  ],
  "culture_keywords": [
    "technology entrepreneurs",
    "creative solutions",
    "smart architecture",
    "hands-on",
    "strategic"
  ]
}
```

### Importance Levels

| Level | Description |
|-------|-------------|
| `critical` | Must appear in tailored resume |
| `high` | Should appear if relevant experience exists |
| `medium` | Include if space allows |
| `low` | Optional, for ATS optimization |

---

## tailoring_guidance

Generated by comparing job requisition against user's resume. Passed to GenAI for resume generation.

```json
{
  "executive_summary_focus": [
    "AI/ML leadership with production deployment experience",
    "High-scale transaction processing",
    "Player-coach who builds AND leads",
    "Healthcare or regulated industry experience"
  ],
  "experience_emphasis": [
    {
      "priority": 1,
      "area": "AI/ML systems taken from concept to production",
      "evidence_needed": "specific examples with measurable business impact"
    },
    {
      "priority": 2,
      "area": "High-scale transaction processing",
      "evidence_needed": "volume metrics (millions/billions of transactions)"
    },
    {
      "priority": 3,
      "area": "Team leadership with hiring authority",
      "evidence_needed": "team sizes, growth, hiring decisions"
    },
    {
      "priority": 4,
      "area": "Hands-on technical work at executive level",
      "evidence_needed": "recent prototyping, architecture decisions"
    },
    {
      "priority": 5,
      "area": "AWS cloud infrastructure",
      "evidence_needed": "specific AWS services, scale"
    }
  ],
  "skills_to_highlight": [
    "LLM/RAG/Embedding models",
    "Event-driven architecture",
    "AWS (required) / GCP (bonus)",
    "Java and Python",
    "Kafka, Postgres",
    "AI-assisted development tools"
  ],
  "skills_to_deemphasize": [
    "Legacy technologies",
    "On-premise only experience",
    "Individual contributor only roles"
  ],
  "keywords_to_incorporate": [
    "AI-assisted development",
    "scale",
    "production",
    "measurable business impact",
    "architecture",
    "player-coach",
    "healthcare" 
  ],
  "potential_gaps": [
    {
      "gap": "Healthcare domain experience",
      "mitigation": "Explicitly marked as 'nice to have' - emphasize regulated industry experience instead"
    }
  ],
  "cultural_fit_signals": [
    "Thrives with resource constraints",
    "Prefers smart architecture over headcount",
    "Enjoys both strategy and hands-on work",
    "Excited by AI tools and possibilities"
  ]
}
```

---

## GenAI Integration

### Request Payload

When generating a tailored resume, send to GenAI backend:

```json
{
  "action": "generate_tailored_resume",
  "job_requisition": {
    "id": 1,
    "extracted_json": { ... },
    "skills_required_json": { ... },
    "keywords_json": { ... },
    "raw_posting_text": "..."
  },
  "user_resume": {
    "consolidated_profile_json": { ... }
  },
  "options": {
    "output_formats": ["json", "text", "html"],
    "include_match_analysis": true,
    "emphasis_level": "moderate"
  }
}
```

### Response Payload

```json
{
  "success": true,
  "tailored_resume_json": { ... },
  "tailored_resume_text": "...",
  "tailored_resume_html": "...",
  "tailoring_guidance": { ... }
}
```

---

## Source Platforms

Valid values for `source_platform`:

| Value | Description |
|-------|-------------|
| `linkedin` | LinkedIn Jobs |
| `indeed` | Indeed |
| `glassdoor` | Glassdoor |
| `company_site` | Direct company careers page |
| `recruiter` | Received from recruiter/email |
| `ziprecruiter` | ZipRecruiter |
| `dice` | Dice (tech jobs) |
| `angel` | AngelList / Wellfound |
| `other` | Other source |

---

## AI Extraction Status

| Status | Description |
|--------|-------------|
| `pending` | Raw text saved, extraction not started |
| `processing` | AI extraction in progress |
| `completed` | Successfully extracted structured data |
| `failed` | Extraction failed, see error log |
| `manual` | Manually entered, no AI extraction |

---

*Schema Version: 1.0*
*Created: February 2, 2026*
