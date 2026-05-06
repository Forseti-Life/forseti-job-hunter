# Resume JSON → Style Mapping Report

**Generated:** February 3, 2026  
**Source:** `jobhunter_job_seeker.consolidated_profile_json` (id=1)  
**Style Schema:** `config/resume_styles/keith_aumiller.json` v1.0

---

## Page Layout

| Property | Value |
|----------|-------|
| Page Size | 684 × 792 pts (9.5" × 11" custom) |
| Top Margin | 36 pts (0.5") |
| Bottom Margin | 36 pts (0.5") |
| Left Margin | 54 pts (0.75") |
| Right Margin | 54 pts (0.75") |

## Font Definitions

| Font Key | Family | Weight/Style | Fallbacks |
|----------|--------|--------------|-----------|
| `primary` | Tahoma | normal | Arial, Helvetica, sans-serif |
| `primary_bold` | Tahoma | bold | Arial Bold, Helvetica Bold |
| `primary_italic` | Tahoma | italic | Arial Italic, Helvetica Oblique |

---

## Section 1: CONTACT_INFO

**Layout:** Header block (no section label)

| JSON Path | Sample Value | Style Key | Font | Size | Color | Notes |
|-----------|--------------|-----------|------|------|-------|-------|
| `contact_info.full_name` | "Keith Aumiller" | `name` | **Tahoma-Bold** | 18pt | #000000 | margin-bottom: 2pt |
| `contact_info.credentials[]` | ["MBA", "BS Psychology"] | `credentials` | Tahoma | 11pt | #000000 | inline, separator: ", " |
| `contact_info.headline` | "Business Data Engineering & AI Leader..." | `headline` | **Tahoma-Bold** | 11pt | #333333 | margin-bottom: 4pt |
| `contact_info.location.city` | "Philadelphia" | `contact_line` | Tahoma | 10pt | #000000 | separator: " \| " |
| `contact_info.location.state` | "PA" | `contact_line` | Tahoma | 10pt | #000000 | combined with city |
| `contact_info.phone` | "(314) 369-0811" | `contact_line` | Tahoma | 10pt | #000000 | separator: " \| " |
| `contact_info.email` | "keith.aumiller@stlouisintegration.com" | `contact_line` | Tahoma | 10pt | #000000 | |
| `contact_info.websites[0].url` | "https://stlouisintegration.com" | `contact_link` | Tahoma | 10pt | #0066cc | underline |
| `contact_info.websites[1].url` | "https://github.com/keithaumiller" | `contact_link` | Tahoma | 10pt | #0066cc | underline |
| `contact_info.websites[2].url` | "https://thetruthperspective.org" | `contact_link` | Tahoma | 10pt | #0066cc | underline |
| `contact_info.linkedin.followers` | "14k" | `linkedin_followers` | Tahoma | 10pt | #000000 | prefix: "LinkedIn: ", suffix: " followers" |
| `contact_info.linkedin.groups_administered[]` | ["Artificial Intelligence..."] | `linkedin_groups` | Tahoma | 9pt | #666666 | prefix: "Admin: " |

---

## Section 2: EXECUTIVE_PROFILE

**Section Header:** "EXECUTIVE PROFILE" → `section_header` (Tahoma-Bold 12pt, UPPERCASE, border-bottom: 0.5pt)

| JSON Path | Sample Value (truncated) | Style Key | Font | Size | Color | Notes |
|-----------|--------------------------|-----------|------|------|-------|-------|
| `executive_profile` | "Transformational data and AI leader with 20+ years..." | `body_text` | Tahoma | 11pt | #000000 | line-height: 1.3 |

**Full Content:**
> Transformational data and AI leader with 20+ years architecting enterprise-wide solutions across financial services, energy, healthcare, and technology sectors. Proven expertise in building scalable data organizations, having transformed single-analyst functions into $20M+ revenue-generating practices and managed $1B+ daily transaction platforms. Expert in modernizing traditional enterprises into data-driven, AI-enabled organizations through comprehensive infrastructure development and regulatory-compliant architectures...

---

## Section 3: ORGANIZATIONAL_PHILOSOPHY

**Section Header:** "ORGANIZATIONAL PHILOSOPHY" → `section_header`

| JSON Path | Sample Value (truncated) | Style Key | Font | Size | Color | Notes |
|-----------|--------------------------|-----------|------|------|-------|-------|
| `organizational_philosophy[0]` | "I excel at designing and implementing data services organizations..." | `organizational_philosophy` | Tahoma | 11pt | #000000 | line-height: 1.3, margin-bottom: 6pt |

---

## Section 4: STRATEGIC_DIFFERENTIATORS

**Section Header:** "STRATEGIC DIFFERENTIATORS" → `section_header`

| # | Title (Style: `differentiator_title`) | Description (Style: `differentiator_description`) |
|---|---------------------------------------|--------------------------------------------------|
| 1 | **→ Enterprise Data & AI Leadership** | Led comprehensive digital transformations across multiple industries, managing platforms processing $1B+ daily transactions with 99.999% uptime |
| 2 | **→ Scalable Organization Building** | Transformed fragmented data operations into unified, high-performing teams of 100+ professionals, generating $20M+ in new revenue streams |
| 3 | **→ Cross-Industry Innovation** | Successfully applied methodologies across financial services, energy, healthcare, and government sectors |
| 4 | **→ Regulatory Compliance & Governance** | Established comprehensive data governance frameworks ensuring compliance across FERC, NERC, FDA, SOX, Basel III, and GDPR |
| 5 | **→ AI Strategy & Implementation** | Developed and executed enterprise AI strategies securing multi-million-dollar infrastructure investments |
| 6 | **→ Exit Strategy** | Involved in several successful private equity exits; Bracket Global became Signant Health |
| 7 | **→ Energy & Utilities Leadership** | Led AI-powered transformation of multi-billion-dollar energy operations |
| 8 | **→ Energy Data Engineering** | Designed scalable energy infrastructure across utility operations and energy trading systems |
| 9 | **→ FP&A Automation** | Designed Financial Planning and Accounting automation for forecasting and quarterly reporting |
| 10 | **→ Marketing and Customer Analysis** | Designed customer churn, retention, and campaign effectiveness methodologies |
| 11 | **→ Energy Regulatory & Compliance** | Established data governance frameworks for federal and state utility commission requirements |
| 12 | **→ AI-Powered Energy Innovation** | Collaborated with existing teams to build new AI grid capabilities |
| 13 | **→ Healthcare & Pharmaceutical Leadership** | Led AI-powered transformation of clinical operations across 700+ trials |
| 14 | **→ Enterprise Data Engineering** | Designed scalable data infrastructure across clinical, commercial, and research operations |
| 15 | **→ Data Quality & Governance** | Established data quality frameworks ensuring FDA compliance |
| 16 | **→ AI-Powered Healthcare Innovation** | Collaborated with existing teams to build new AI capabilities |

**Styling:**
- Title: **Tahoma-Bold 11pt** #000000, bullet: "→"
- Description: Tahoma 11pt #000000, inline after title

---

## Section 5: PROFESSIONAL_EXPERIENCE

**Section Header:** "PROFESSIONAL EXPERIENCE" → `section_header`

### Position 1: AmeriGas UGI

| JSON Path | Value | Style Key | Font | Size | Color |
|-----------|-------|-----------|------|------|-------|
| `.title` | "Director of Advanced Analytics" | `job_title` | **Tahoma-Bold** | 11pt | #000000 |
| `.company` | "AmeriGas UGI" | `company_name` | Tahoma | 11pt | #000000 |
| `.start_date` – `.end_date` | "2022-06 – 2025-10" | `date_range` | Tahoma | 10pt | #666666 |
| `.location` | "Philadelphia, PA" | `location` | Tahoma | 10pt | #666666 |
| `.company_context` | "one of the largest propane distributors..." | `company_context` | *Tahoma-Italic* | 10pt | #555555 |

**Responsibility Categories:**

| Category (Style: `category_header`) | Achievements (Style: `achievement_bullet`) |
|-------------------------------------|-------------------------------------------|
| **Enterprise AI Vision & Strategic Transformation** | • Partnered with C-suite leadership to establish comprehensive AI vision... |
| | • Led organizational change management initiatives transforming traditional energy company... |
| | • Developed AI strategy framework aligning corporate strategic objectives... |
| **Data Infrastructure & Platform Architecture** | • Architected enterprise data infrastructure strategy (Dataiku, Databricks, Snowflake, Matillion)... |
| | • Established comprehensive data governance and AI ethics frameworks... |
| | • Built unified data platform strategy consolidating disparate operational systems... |
| | • Designed self-service data and AI capabilities... |
| **Revenue Generation & Business Impact** | • Developed productized AI solutions generating **$3.2M** revenue, **30%** efficiency improvement |
| | • Implemented advanced forecasting and automation for FP&A... |
| | • Created customer churn models, retention analytics, and campaign monitoring... |

### Position 2: Signant Health

| JSON Path | Value | Style Key | Font | Size | Color |
|-----------|-------|-----------|------|------|-------|
| `.title` | "Director Data Science Services Products" | `job_title` | **Tahoma-Bold** | 11pt | #000000 |
| `.company` | "Signant Health" | `company_name` | Tahoma | 11pt | #000000 |
| `.start_date` – `.end_date` | "2018-02 – 2021-11" | `date_range` | Tahoma | 10pt | #666666 |
| `.location` | "Philadelphia, PA" | `location` | Tahoma | 10pt | #666666 |
| `.company_context` | "Led 100+ person data science practice delivering AI/ML solutions for 700+ large-scale clinical trials..." | `company_context` | *Tahoma-Italic* | 10pt | #555555 |

**Categories:** Pharmaceutical Data Engineering & Clinical Infrastructure, Clinical Research Data Quality & Compliance, Revenue Growth & Strategic Development

**Key Achievements:**
- Transformed single-analyst data function into **$20M+** AI and data services organization
- Built scalable data infrastructure supporting **700+ concurrent clinical trials** with **99.9% uptime**
- Responsible for **Pfizer COVID vaccine trials** during pandemic
- Secured multiple **eight-figure contracts** with pharmaceutical companies

### Position 3: NRG

| JSON Path | Value | Style Key | Font | Size | Color |
|-----------|-------|-----------|------|------|-------|
| `.title` | "Data Program and Product Manager" | `job_title` | **Tahoma-Bold** | 11pt | #000000 |
| `.company` | "NRG" | `company_name` | Tahoma | 11pt | #000000 |
| `.start_date` – `.end_date` | "2014-08 – 2016-12" | `date_range` | Tahoma | 10pt | #666666 |
| `.location` | "Philadelphia, PA" | `location` | Tahoma | 10pt | #666666 |
| `.company_context` | "one of the largest competitive power generators in the United States..." | `company_context` | *Tahoma-Italic* | 10pt | #555555 |

**Categories:** Enterprise Data Strategy & Infrastructure Transformation, Product Development & Market Innovation

**Key Achievements:**
- Secured executive approval for **$15M+** data platform investments
- Managed analytics for **10+ acquisitions** M&A due diligence
- Improved forecasting accuracy by **25%**

### Position 4: Citigroup

| JSON Path | Value | Style Key | Font | Size | Color |
|-----------|-------|-----------|------|------|-------|
| `.title` | "Vice President Treasury and Trade Solutions" | `job_title` | **Tahoma-Bold** | 11pt | #000000 |
| `.company` | "Citigroup" | `company_name` | Tahoma | 11pt | #000000 |
| `.start_date` – `.end_date` | "2011-11 – 2014-07" | `date_range` | Tahoma | 10pt | #666666 |
| `.location` | "Philadelphia, PA" | `location` | Tahoma | 10pt | #666666 |
| `.company_context` | "Led comprehensive transformation of traditional treasury operations into AI-powered platform managing $1B+ daily transactions" | `company_context` | *Tahoma-Italic* | 10pt | #555555 |

**Categories:** Enterprise Financial Platform & AI Integration, Financial Risk & Data Products

**Key Achievements:**
- **99.999% uptime** and **sub-second processing** capabilities
- Improved fraud detection accuracy by **85%**, reduced false positives by **60%**
- Prevented **$50M+** in potential losses through AI-powered fraud monitoring

---

## Section 6: CONSULTING_PRACTICE

**Section Header:** "CONSULTING PRACTICE" → `section_header`

| JSON Path | Value | Style Key | Notes |
|-----------|-------|-----------|-------|
| `consulting_practice` | `[]` (empty) | — | **Skip section if empty** |

---

## Section 7: EARLY_CAREER

**Section Header:** "EARLY CAREER" → `section_header`

| JSON Path | Value | Style Key | Font | Size | Color |
|-----------|-------|-----------|------|------|-------|
| `early_career[0]` | "2000-2011" | `early_career_summary` | Tahoma | 10pt | #000000 |

**Note:** Current data only contains date range. For full structure, would need positions with company, duration, and focus fields.

---

## Section 8: EDUCATION

**Section Header:** "EDUCATION" → `section_header`

### Entry 1: MBA

| JSON Path | Value | Style Key | Font | Size | Color |
|-----------|-------|-----------|------|------|-------|
| `.institution` | "Washington University in St. Louis, Olin School of Business" | `institution_name` | **Tahoma-Bold** | 11pt | #000000 |
| `.degree` | "Master of Business Administration" | `degree_name` | Tahoma | 11pt | #000000 |
| `.abbreviation` | "MBA" | `education_abbreviation` | **Tahoma-Bold** | 10pt | #000000 |
| `.start_date` – `.end_date` | "2009-08 – 2011-05" | `education_date` | Tahoma | 10pt | #666666 |

### Entry 2: BS Psychology

| JSON Path | Value | Style Key | Font | Size | Color |
|-----------|-------|-----------|------|------|-------|
| `.institution` | "Truman State University" | `institution_name` | **Tahoma-Bold** | 11pt | #000000 |
| `.location` | "Kirksville, MO" | `education_location` | Tahoma | 10pt | #666666 |
| `.degree` | "Bachelor of Science" | `degree_name` | Tahoma | 11pt | #000000 |
| `.field` | "Psychology" | `degree_name` | Tahoma | 11pt | #000000 |
| `.abbreviation` | "BS" | `education_abbreviation` | **Tahoma-Bold** | 10pt | #000000 |
| `.start_date` – `.end_date` | "1996-08 – 2000-05" | `education_date` | Tahoma | 10pt | #666666 |

---

## Section 9: TECHNICAL_EXPERTISE

**Section Header:** "TECHNICAL EXPERTISE" → `section_header`

| Category (Style: `skill_category`) | Skills (Style: `skill_list`) |
|------------------------------------|------------------------------|
| **Data Engineering & Architecture** | Enterprise Data Architecture, Cloud-Native Platforms (AWS, Azure, GCP), Data Lake and Warehouse Design, MLOps and DataOps, API Strategy, Medallion Architecture, ETL/ELT Systems, Real-time Processing |
| **Advanced Analytics & AI** | Machine Learning Strategy, Generative AI Implementation, Statistical Modeling, Predictive Analytics, Deep Learning Frameworks (TensorFlow, PyTorch), MLOps, Algorithmic Development, Behavioral Analytics |
| **Industry-Specific Technologies** | *(has subcategories)* |
| ↳ *Financial Services* | Payment Processing Systems, Real-time Transaction Processing, Risk Management Platforms, Regulatory Reporting, Core Banking Integration, Fraud Detection |
| ↳ *Energy & Utilities* | Smart Grid Data Architecture, Energy Trading Systems, SCADA Integration, Renewable Energy Analytics, Demand Forecasting, Grid Optimization |
| ↳ *Healthcare & Pharmaceutical* | Clinical Data Management, Regulatory Compliance Frameworks, Clinical Trial Analytics, Statistical Modeling for Life Sciences, Biostatistics |
| **Data Quality & Governance** | Data Governance Frameworks, Data Stewardship, Master Data Management, Data Quality Monitoring, Audit and Validation Processes |
| **Regulatory Compliance** | FDA, FERC, NERC, SOX, Basel III, GDPR *(Style: `regulatory_frameworks`)* |
| **Technology Platforms** | SQL, Python, R, Databricks, Snowflake, Dataiku, Matillion, Tableau, QLIK, Financial Risk Management Software, Energy Management Systems (EMS), Clinical Research Platforms |
| **Energy & Utilities Data Engineering** | Smart Grid Data Architecture, Energy Trading Systems, SCADA Integration, Renewable Energy Analytics, Demand Forecasting Platforms, Grid Optimization Systems, Energy Market Data Integration, Commodity Trading Infrastructure |
| **Energy Regulatory & Compliance** | FERC Compliance Frameworks, NERC CIP Standards, DOT Hazmat Regulations, Energy Data Governance, Utility Rate Case Analytics, Environmental Compliance Reporting, Safety Management Systems, Grid Reliability Standards |
| **Advanced Energy Analytics & AI** | Energy Demand Forecasting, Predictive Maintenance for Energy Assets, Smart Meter Analytics, Renewable Energy Optimization, Energy Trading Algorithms, Load Balancing Models, Grid Stability Prediction, Weather-Energy Correlation Analysis |
| **Energy Infrastructure & Operations** | Energy Distribution Management, Asset Performance Management, Energy Storage Optimization, Renewable Energy Integration, Energy Efficiency Analytics, Customer Energy Usage Analysis, Supply Chain Optimization for Energy Commodities |
| **Clinical Data Management & Regulatory** | Clinical Trial Data Management, FDA 21 CFR Part 11 Compliance, ICH E6 GCP Guidelines, CDISC Standards (SDTM, ADaM), Electronic Data Capture (EDC), Clinical Data Interchange, Regulatory Submission Support, Audit Trail Management |
| **Healthcare Data Governance** | HIPAA Compliance Frameworks, PHI/PII Data Protection, Clinical Data Quality Assurance, Pharmaceutical Data Governance, Patient Privacy Protection, Research Ethics Compliance, Data Integrity Standards, Electronic Records Management |
| **Pharmaceutical Analytics & AI** | Clinical Trial Analytics, Patient Recruitment Optimization, Site Performance Monitoring, Risk-Based Monitoring, Biostatistics Integration, Pharmacovigilance Analytics, Drug Safety Signal Detection, Real-World Evidence Analytics |
| **Healthcare Infrastructure & Operations** | Clinical Data Warehousing, Healthcare Interoperability (HL7, FHIR), Laboratory Information Systems (LIMS), Electronic Health Records Integration, Clinical Trial Management Systems (CTMS), Randomization and Trial Supply Management, Patient Reported Outcomes (ePRO/eCOA) |

**Total:** 16 skill categories

---

## Section 10: LEADERSHIP_PHILOSOPHY

**Section Header:** "LEADERSHIP PHILOSOPHY" → `section_header`

| JSON Path | Value | Style Key | Font | Size | Color | Notes |
|-----------|-------|-----------|------|------|-------|-------|
| `leadership_philosophy[0]` | "I excel at designing and implementing data services organizations..." | `body_text` | Tahoma | 11pt | #000000 | line-height: 1.3 |
| `leadership_philosophy[1]` | ["GE methodology", "Ray Dalio leadership styles"] | `leadership_influences` | Tahoma | 10pt | #000000 | prefix: "Influences: ", bullets |
| `leadership_philosophy[2]` | ["scalable infrastructure", "high-performing teams", "consensus building", "regulatory compliance"] | `leadership_keywords` | Tahoma | 9pt | #666666 | prefix: "Focus Areas: ", inline |

---

## Section 11: DEMONSTRATION_PROJECTS

**Section Header:** "DEMONSTRATION PROJECTS" → `section_header`

| JSON Path | Value | Style Key | Font | Size | Color |
|-----------|-------|-----------|------|------|-------|
| `.name` | "GenAI Demo Site" | `project_name` | **Tahoma-Bold** | 10pt | #000000 |
| `.url` | "https://thetruthperspective.org" | `project_url` | Tahoma | 10pt | #0066cc |
| `.description` | "AWS-hosted GenAI solution utilizing open source content management system, generative AI content generation, social media integration, sentiment analysis, and comprehensive data pipeline automation." | `project_description` | Tahoma | 10pt | #000000 |
| `.technologies[]` | "AWS, open source CMS, generative AI, social media integration, sentiment analysis, data pipeline automation" | `project_technologies` | Tahoma | 9pt | #000000 |

---

## Sections NOT Rendered to PDF

| JSON Path | Purpose |
|-----------|---------|
| `extraction_metadata` | Internal tracking |
| `job_search_preferences` | Application form data |
| `demographics` | EEO data (confidential) |

---

## Style Coverage Summary

| Section | Styles Used | Status |
|---------|-------------|--------|
| contact_info | `name`, `credentials`, `headline`, `contact_line`, `contact_link`, `linkedin_followers`, `linkedin_groups` | ✅ Complete |
| executive_profile | `section_header`, `body_text` | ✅ Complete |
| organizational_philosophy | `section_header`, `organizational_philosophy` | ✅ Complete |
| strategic_differentiators | `section_header`, `differentiator_title`, `differentiator_description` | ✅ Complete |
| professional_experience | `section_header`, `job_title`, `company_name`, `date_range`, `location`, `company_context`, `category_header`, `achievement_bullet` | ✅ Complete |
| consulting_practice | `section_header`, `engagement_client`, `engagement_role` | ✅ Complete (empty data) |
| early_career | `section_header`, `early_career_summary` | ✅ Complete |
| education | `section_header`, `institution_name`, `education_location`, `degree_name`, `education_abbreviation`, `education_date` | ✅ Complete |
| technical_expertise | `section_header`, `skill_category`, `skill_list`, `skill_subcategory`, `regulatory_frameworks` | ✅ Complete |
| leadership_philosophy | `section_header`, `body_text`, `leadership_influences`, `leadership_keywords` | ✅ Complete |
| demonstration_projects | `section_header`, `project_name`, `project_url`, `project_description`, `project_technologies` | ✅ Complete |

**Total Styles Defined:** 35  
**All JSON Paths Mapped:** ✅ Yes

---

## PDF Rendering Estimate

| Section | Items | Est. Lines |
|---------|-------|------------|
| Contact Info | 1 header block | 6 lines |
| Executive Profile | 1 paragraph | 10 lines |
| Organizational Philosophy | 1 paragraph | 5 lines |
| Strategic Differentiators | 16 items | 32 lines |
| Professional Experience | 4 positions | 100 lines |
| Early Career | 1 summary | 2 lines |
| Education | 2 entries | 6 lines |
| Technical Expertise | 16 categories | 50 lines |
| Leadership Philosophy | 1 paragraph + lists | 6 lines |
| Demonstration Projects | 1 project | 5 lines |

**Estimated Total:** ~220 lines → **6 pages** (matches source PDF)

---

*Report Version: 2.0*  
*Generated: February 3, 2026*
