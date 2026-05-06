# Resume PDF Style Schema

This document defines the style mapping from Resume JSON Schema sections to PDF formatting, enabling consistent PDF generation from `consolidated_profile_json` or `tailored_resume_json`.

**Related:** See `RESUME_JSON_SCHEMA.md` for the content structure.

---

## Overview

**Goal:** Map each section defined in `RESUME_JSON_SCHEMA.md` to a set of PDF style properties, then generate formatted PDFs that match the source resume format.

```
┌─────────────────────┐    ┌─────────────────────┐    ┌─────────────────────┐
│   Resume JSON       │    │   Style Schema      │    │   Output PDF        │
│   (content)         │ +  │   (formatting)      │ =  │   (formatted doc)   │
└─────────────────────┘    └─────────────────────┘    └─────────────────────┘
```

---

## JSON Section → Style Mapping

### Section Index

| JSON Section | PDF Element | Style Key |
|--------------|-------------|-----------|
| `contact_info.full_name` | Name header | `name` |
| `contact_info.credentials` | After name | `credentials` |
| `contact_info.headline` | Subtitle | `headline` |
| `contact_info.location` | Contact line | `contact_line` |
| `contact_info.phone` | Contact line | `contact_line` |
| `contact_info.email` | Contact line | `contact_link` |
| `contact_info.websites[]` | Contact line | `contact_link` |
| `contact_info.linkedin` | Contact line | `contact_line` |
| `executive_profile.summary` | Section body | `body_text` |
| `executive_profile.key_metrics[]` | Bullet list | `bullet_item` |
| `strategic_differentiators[]` | Bullet list | `differentiator_item` |
| `professional_experience[]` | Section | `experience_entry` |
| `professional_experience[].title` | Job title | `job_title` |
| `professional_experience[].company` | Company name | `company_name` |
| `professional_experience[].start_date` | Date range | `date_range` |
| `professional_experience[].company_context` | Italic context | `company_context` |
| `professional_experience[].responsibility_categories[]` | Category header | `category_header` |
| `professional_experience[].responsibility_categories[].achievements[]` | Bullets | `achievement_bullet` |
| `consulting_practice` | Section | `experience_entry` |
| `consulting_practice.notable_engagements[]` | Sub-entries | `engagement_entry` |
| `early_career.positions[]` | Condensed list | `early_career_item` |
| `education[]` | Section entries | `education_entry` |
| `education[].institution` | School name | `institution_name` |
| `education[].degree` | Degree | `degree_name` |
| `technical_expertise.categories[]` | Category blocks | `skill_category` |
| `technical_expertise.categories[].skills[]` | Skill list | `skill_list` |
| `leadership_philosophy.statement` | Body text | `body_text` |
| `demonstration_projects[]` | Project entries | `project_entry` |

---

## Style Schema Definition

### Page Layout

```json
{
  "page": {
    "size": "custom",
    "width_inches": 9.5,
    "height_inches": 11,
    "margins": {
      "top_inches": 0.5,
      "bottom_inches": 0.5,
      "left_inches": 0.75,
      "right_inches": 0.75
    }
  }
}
```

### Font Definitions

```json
{
  "fonts": {
    "primary": {
      "family": "Tahoma",
      "fallback": ["Arial", "Helvetica", "sans-serif"]
    },
    "primary_bold": {
      "family": "Tahoma",
      "weight": "bold",
      "fallback": ["Arial Bold", "Helvetica Bold", "sans-serif"]
    }
  }
}
```

### Section Header Style (Applies to all section labels)

```json
{
  "section_header": {
    "font": "primary_bold",
    "size_pt": 12,
    "color": "#000000",
    "text_transform": "uppercase",
    "letter_spacing": 0.5,
    "margin_top_pt": 14,
    "margin_bottom_pt": 4,
    "border_bottom": {
      "width_pt": 0.5,
      "color": "#000000"
    }
  }
}
```

---

## Complete Style Mapping by JSON Section

### `contact_info`

| Element | Style Properties |
|---------|------------------|
| `full_name` | `font: primary_bold, size: 18pt, color: #000000, margin_bottom: 2pt` |
| `credentials` | `font: primary, size: 11pt, color: #000000, inline after name, comma-separated` |
| `headline` | `font: primary_bold, size: 11pt, color: #333333, margin_bottom: 4pt` |
| `location + phone + email` | `font: primary, size: 10pt, color: #000000, separator: " | "` |
| `websites[].url` | `font: primary, size: 10pt, color: #0066cc, underline: true` |
| `linkedin.followers` | `font: primary, size: 10pt, color: #000000` |

### `executive_profile`

| Element | Style Properties |
|---------|------------------|
| Section Header | `"EXECUTIVE PROFILE"` using `section_header` style |
| `summary` | `font: primary, size: 11pt, line_height: 1.3, text_align: justify` |
| `key_metrics[]` | Inline within summary or as bullet list, `font: primary, size: 11pt` |

### `strategic_differentiators`

| Element | Style Properties |
|---------|------------------|
| Section Header | `"STRATEGIC DIFFERENTIATORS"` using `section_header` style |
| `[].title` | `font: primary_bold, size: 11pt, bullet: "→"` |
| `[].description` | `font: primary, size: 11pt, inline after title` |

### `professional_experience`

| Element | Style Properties |
|---------|------------------|
| Section Header | `"PROFESSIONAL EXPERIENCE"` using `section_header` style |
| Entry Layout | Two-column: left=title+company, right=dates |
| `title` | `font: primary_bold, size: 11pt` |
| `company` | `font: primary, size: 11pt, inline after title with " – "` |
| `start_date - end_date` | `font: primary, size: 10pt, color: #666666, align: right` |
| `location` | `font: primary, size: 10pt, color: #666666` |
| `company_context` | `font: primary_italic, size: 10pt, color: #555555, margin_bottom: 4pt` |
| `responsibility_categories[].category` | `font: primary_bold, size: 10pt, margin_top: 6pt` |
| `achievements[].text` | `font: primary, size: 10pt, bullet: "•", indent: 12pt, margin_bottom: 2pt` |

### `consulting_practice`

Same styling as `professional_experience`, with:
| Element | Style Properties |
|---------|------------------|
| `notable_engagements[].client` | `font: primary_bold, size: 10pt` |
| `notable_engagements[].role` | `font: primary_italic, size: 10pt` |

### `early_career`

| Element | Style Properties |
|---------|------------------|
| Section Header | `"EARLY CAREER"` using `section_header` style |
| `summary` | `font: primary, size: 10pt, margin_bottom: 6pt` |
| `positions[].company` | `font: primary_bold, size: 10pt, inline` |
| `positions[].duration` | `font: primary, size: 10pt, in parentheses` |
| `positions[].focus` | `font: primary, size: 10pt, follows company` |

### `education`

| Element | Style Properties |
|---------|------------------|
| Section Header | `"EDUCATION"` using `section_header` style |
| `institution` | `font: primary_bold, size: 11pt` |
| `degree + field` | `font: primary, size: 11pt` |
| `start_date - end_date` | `font: primary, size: 10pt, color: #666666` |

### `technical_expertise`

| Element | Style Properties |
|---------|------------------|
| Section Header | `"TECHNICAL EXPERTISE"` using `section_header` style |
| `categories[].name` | `font: primary_bold, size: 10pt` |
| `categories[].skills[]` | `font: primary, size: 10pt, comma-separated inline` |
| `subcategories[].industry` | `font: primary_bold, size: 10pt, inline label` |
| `subcategories[].skills[]` | `font: primary, size: 10pt, comma-separated` |

### `leadership_philosophy`

| Element | Style Properties |
|---------|------------------|
| Section Header | `"LEADERSHIP PHILOSOPHY"` using `section_header` style |
| `statement` | `font: primary, size: 10pt, line_height: 1.3` |
| `influences[]` | `font: primary, size: 10pt, bullet list` |

### `demonstration_projects`

| Element | Style Properties |
|---------|------------------|
| Section Header | `"DEMONSTRATION PROJECTS"` using `section_header` style |
| `[].name` | `font: primary_bold, size: 10pt` |
| `[].url` | `font: primary, size: 10pt, color: #0066cc` |
| `[].description` | `font: primary, size: 10pt` |
| `[].technologies[]` | `font: primary, size: 9pt, comma-separated, label: "Technologies:"` |

---

## Complete Schema JSON Template

```json
{
  "schema_version": "1.0",
  "schema_name": "keith_aumiller_resume",
  "source_pdf": "KeithAumillerA.pdf",
  "created_date": "2026-02-03",
  
  "page": {
    "size": "custom",
    "width_pt": 684,
    "height_pt": 792,
    "margins_pt": {
      "top": 36,
      "bottom": 36,
      "left": 54,
      "right": 54
    }
  },
  
  "fonts": {
    "primary": {"family": "Tahoma", "weight": "normal"},
    "primary_bold": {"family": "Tahoma", "weight": "bold"},
    "primary_italic": {"family": "Tahoma", "style": "italic"}
  },
  
  "styles": {
    "name": {
      "font": "primary_bold",
      "size_pt": 18,
      "color": "#000000",
      "margin_bottom_pt": 2
    },
    "credentials": {
      "font": "primary",
      "size_pt": 11,
      "color": "#000000",
      "display": "inline",
      "separator": ", "
    },
    "headline": {
      "font": "primary_bold",
      "size_pt": 11,
      "color": "#333333",
      "margin_bottom_pt": 4
    },
    "contact_line": {
      "font": "primary",
      "size_pt": 10,
      "color": "#000000",
      "separator": " | "
    },
    "contact_link": {
      "font": "primary",
      "size_pt": 10,
      "color": "#0066cc",
      "text_decoration": "underline"
    },
    "section_header": {
      "font": "primary_bold",
      "size_pt": 12,
      "color": "#000000",
      "text_transform": "uppercase",
      "margin_top_pt": 14,
      "margin_bottom_pt": 4,
      "border_bottom": {"width_pt": 0.5, "color": "#000000"}
    },
    "body_text": {
      "font": "primary",
      "size_pt": 11,
      "color": "#000000",
      "line_height": 1.3,
      "text_align": "left"
    },
    "job_title": {
      "font": "primary_bold",
      "size_pt": 11,
      "color": "#000000"
    },
    "company_name": {
      "font": "primary",
      "size_pt": 11,
      "color": "#000000",
      "prefix": " – "
    },
    "date_range": {
      "font": "primary",
      "size_pt": 10,
      "color": "#666666",
      "align": "right"
    },
    "company_context": {
      "font": "primary_italic",
      "size_pt": 10,
      "color": "#555555",
      "margin_bottom_pt": 4
    },
    "category_header": {
      "font": "primary_bold",
      "size_pt": 10,
      "color": "#000000",
      "margin_top_pt": 6
    },
    "achievement_bullet": {
      "font": "primary",
      "size_pt": 10,
      "color": "#000000",
      "bullet": "•",
      "indent_pt": 12,
      "margin_bottom_pt": 2
    },
    "skill_category": {
      "font": "primary_bold",
      "size_pt": 10,
      "color": "#000000"
    },
    "skill_list": {
      "font": "primary",
      "size_pt": 10,
      "color": "#000000",
      "display": "inline",
      "separator": ", "
    },
    "institution_name": {
      "font": "primary_bold",
      "size_pt": 11,
      "color": "#000000"
    },
    "degree_name": {
      "font": "primary",
      "size_pt": 11,
      "color": "#000000"
    },
    "project_name": {
      "font": "primary_bold",
      "size_pt": 10,
      "color": "#000000"
    }
  },
  
  "section_order": [
    "contact_info",
    "executive_profile",
    "strategic_differentiators",
    "professional_experience",
    "consulting_practice",
    "early_career",
    "education",
    "technical_expertise",
    "leadership_philosophy",
    "demonstration_projects"
  ],
  
  "section_labels": {
    "executive_profile": "EXECUTIVE PROFILE",
    "strategic_differentiators": "STRATEGIC DIFFERENTIATORS",
    "professional_experience": "PROFESSIONAL EXPERIENCE",
    "consulting_practice": "CONSULTING PRACTICE",
    "early_career": "EARLY CAREER",
    "education": "EDUCATION",
    "technical_expertise": "TECHNICAL EXPERTISE",
    "leadership_philosophy": "LEADERSHIP PHILOSOPHY",
    "demonstration_projects": "DEMONSTRATION PROJECTS"
  }
}
```

---

## PDF Generation Process

```
1. Load resume content from consolidated_profile_json or tailored_resume_json
2. Load style schema (e.g., keith_aumiller_resume.json)
3. For each section in section_order:
   a. If section exists in content:
      - Render section_header with section_labels[section]
      - For each element in section:
        - Look up style key from mapping
        - Apply style properties
        - Render element
4. Handle page breaks (avoid splitting entries)
5. Output PDF with embedded fonts
```

---

## File Locations

| File | Purpose |
|------|---------|
| `docs/RESUME_JSON_SCHEMA.md` | Content structure definition |
| `docs/RESUME_PDF_STYLE_SCHEMA.md` | This style mapping document |
| `config/resume_styles/keith_aumiller.json` | Actual style schema for PDF generation |
| `src/Service/ResumePdfService.php` | PHP service for PDF generation |

---

*Schema Version: 1.0*
*Created: February 3, 2026*
