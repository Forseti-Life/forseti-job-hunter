# Job Application Automation - Documentation Index

Welcome to the comprehensive documentation for the Job Application Automation module.

## 📚 Documentation Structure

This documentation is organized into several focused documents to help you understand, configure, and extend the module.

### Quick Start
Start here if you're new to the module:
1. Read the [Module README](../README.md) for a high-level overview
2. Follow the [Installation Guide](../INSTALL.md) to set up the module
3. Review the [FAQ](FAQ.md) for common questions

### Core Documentation

#### [Architecture Documentation](ARCHITECTURE.md) 🏗️
**Audience:** Developers, System Architects, Technical Leads

Comprehensive technical architecture covering:
- System overview and design principles
- Technology stack and dependencies
- Module structure and organization
- Data model and entity relationships
- Hybrid storage strategy (nodes + operational tables)
- Service layer architecture
- Security architecture
- Integration points (AWS Bedrock, Drupal Core)
- Deployment architecture
- Development guidelines

**When to read:** Before starting development, when planning extensions, or troubleshooting complex issues.

#### [Process Flow Documentation](PROCESS_FLOW.md) 🔄
**Audience:** Developers, Business Analysts, QA Engineers, Product Managers

Detailed process flows and workflows including:
- System architecture flows (installation, configuration)
- User workflows (job posting creation, resume tailoring)
- Administrative workflows (error queue management, user reviews)
- Technical process flows (AI service integration, job scraping)
- Error handling patterns
- Integration points and performance considerations

**When to read:** Understanding how the system works, designing new features, debugging workflows, or writing documentation.

#### [Submission Process Documentation](SUBMISSION_PROCESS.md) 📤
**Audience:** Developers, Product Managers, Business Analysts, Integration Engineers

Comprehensive guide to the job application submission process including:
- Step-by-step process flows for all 6 stages (Upload Resume → Analytics)
- Dependencies (PHP libraries, external services, infrastructure)
- Data sources (database tables, external APIs, file storage)
- Integration points (AWS Bedrock, company portals, email systems)
- Current implementation status (implemented, partial, planned)
- Security and performance considerations

**When to read:** Planning integrations, understanding submission workflows, or implementing new submission features.

#### [Frequently Asked Questions](FAQ.md) ❓
**Audience:** End Users, Administrators, Developers, Support Staff

Comprehensive Q&A covering:
- General questions about the module
- Getting started and installation
- Configuration and settings
- Resume tailoring functionality
- Job discovery features
- Troubleshooting common issues
- Security and privacy considerations
- Technical implementation questions

**When to read:** First stop for any questions, before contacting support, or when helping other users.

### Specialized Documentation

#### [API Integration Guide](API_INTEGRATION_GUIDE.md) 🔌
**Audience:** Administrators, Developers, DevOps

Comprehensive setup guide for all external API integrations:
- AWS Bedrock configuration for AI features
- SerpAPI setup for Google Jobs search
- Google Cloud Talent Solution integration
- Adzuna API configuration
- USAJobs API setup
- Testing and troubleshooting procedures
- Cost management and best practices

**When to read:** When setting up the module for the first time, configuring API integrations, or troubleshooting API issues.

#### [GenAI Fallback Service Standard](GENAI_FALLBACK_SERVICE.md) 🧠
**Audience:** Developers, Architects, QA Engineers

Canonical standard for reusable GenAI fallback decisions in process flows:
- Unified service and response contract (`used/available/success/confirmed/confidence/evidence`)
- Deterministic-first gating pattern before fallback invocation
- Prompt/context conventions and metadata (`use_case`, `stage`, `decision_key`)
- Reuse examples and anti-patterns to avoid per-flow reinvention

**When to read:** Before adding GenAI fallback logic to any new workflow step.

#### [Resume JSON Schema](RESUME_JSON_SCHEMA.md) 📄
**Audience:** Developers, Data Engineers

Complete specification for parsed resume data storage:
- JSON schema definition for `jobhunter_resume_parsed_data.parsed_data`
- Field-by-field documentation with examples
- Contact info, work history, education, skills structures
- Achievement extraction with metrics and keywords
- Consolidation logic for multiple resumes
- GenAI prompt requirements

#### [Profile Management Guide](../PROFILE_MANAGEMENT.md) 👤
**Audience:** Users, Administrators

Details about user profile extensions for job seekers:
- Profile fields and their purposes
- Profile completion requirements
- Resume upload and management
- Skills and experience tracking

#### [Job Discovery Technical Guide](../JOB_DISCOVERY_README.md) 🔍
**Audience:** Developers

Technical implementation details for job scraping:
- Job discovery framework
- Per-employer scraper implementation
- HTML parsing strategies
- Duplicate detection
- Error handling for scraping

#### [Google Job Search API Integration Guide](GOOGLE_JOB_SEARCH_API_INTEGRATION.md) 🔎
**Audience:** Developers, SEO Specialists, Content Managers

Comprehensive guide for integrating with Google for Jobs:
- Understanding Google for Jobs (not a traditional API)
- Schema.org JobPosting structured data implementation
- JSON-LD vs Microdata approaches
- Required and recommended properties
- Step-by-step implementation in Drupal
- Testing and validation procedures
- Best practices and common pitfalls
- SEO optimization for job postings
- Monitoring and maintenance guidelines

#### [Google Jobs Integration Architecture](GOOGLE_JOBS_INTEGRATION_ARCHITECTURE.md) 🏗️
**Audience:** Developers, System Architects

Technical implementation architecture for the Google Jobs Integration feature:
- Database schema (sync tracking, validation logs)
- Component architecture (Controller, Service, Templates)
- Client-side implementation (JavaScript, CSS)
- Data flow and AJAX endpoints
- Schema.org JobPosting structure generation
- Validation rules and error handling
- Integration with existing job_hunter data
- Testing checklist and maintenance notes

#### [Company Research Path Design](COMPANY_RESEARCH_DESIGN_SUMMARY.md) 🔬
**Audience:** Developers, System Architects, Product Managers

**NEW - Complete design package for company research automation (NOT YET IMPLEMENTED)**

Comprehensive design for automated company research including:
- **[Design Summary](COMPANY_RESEARCH_DESIGN_SUMMARY.md)** - Overview and quick start (14KB)
- **[Main Design Document](COMPANY_RESEARCH_PATH_DESIGN.md)** - Complete specifications (45KB)
  - 8-step process flow (company name → careers pages → ATS detection → auth analysis)
  - Controller and service layer architecture (6 services)
  - Database schemas (2 tables with full specifications)
  - API integrations (Google Search, LinkedIn, Headless Browser)
  - Authentication strategies (Email/Password, SSO, SAML, 2FA)
  - 12-week implementation roadmap
- **[Visual Diagrams](COMPANY_RESEARCH_DIAGRAMS.md)** - Process flows and architecture diagrams (50KB)
  - System architecture (5 layers)
  - Service interactions and data flows
  - State machines and decision trees
  - Error handling and caching strategies
- **[Implementation Examples](COMPANY_RESEARCH_IMPLEMENTATION_EXAMPLES.md)** - Code examples (49KB)
  - Complete service implementations
  - Controller examples with routes
  - Database installation hooks
  - Configuration schemas
  - Unit test examples
  - Twig templates

**Purpose:** Accept company name as input, automatically identify:
1. Job application careers pages
2. Application system type (Workday, Greenhouse, Taleo, etc.)
3. Authentication methodologies required for account creation

**Status:** Design complete, ready for implementation. No code has been written yet.

**When to read:** Before implementing company research features, when planning ATS integrations, or understanding automation requirements.

#### [Installation Guide](../INSTALL.md) 🚀
**Audience:** System Administrators, DevOps Engineers

Step-by-step installation instructions:
- System requirements
- Module installation via Drush
- Initial configuration
- Post-installation verification
- Troubleshooting installation issues

#### [Queue Worker Troubleshooting](QUEUE_TROUBLESHOOTING.md) 🔧
**Audience:** System Administrators, DevOps Engineers, Developers

Comprehensive troubleshooting guide for background queue processing:
- Queue worker overview and status checking
- Common issues and solutions (stuck items, suspensions, timeouts)
- AWS Bedrock integration troubleshooting
- Memory and performance optimization
- Concurrent processing management
- Monitoring and debugging procedures
- Emergency procedures and recovery

**When to read:** When queue items aren't processing, investigating suspended items, or optimizing queue performance.

#### [Permissions Matrix](PERMISSIONS.md) 🔐
**Audience:** System Administrators, Site Managers

Complete permissions reference and role configuration guide:
- Recommended permission sets for Job Seeker, Manager, and Admin roles
- Detailed permission descriptions with use cases
- Permission dependencies for workflows
- Security considerations and best practices
- Troubleshooting permission issues
- Granting permissions via UI, Drush, or code

**When to read:** Setting up new user roles, troubleshooting access denied errors, or implementing custom role hierarchies.

---

## 🎯 Documentation by Role

### For End Users
1. [Module README](../README.md) - Overview
2. [FAQ](FAQ.md) - Questions about using the module
3. [Profile Management](../PROFILE_MANAGEMENT.md) - Setting up your profile

### For Administrators
1. [Installation Guide](../INSTALL.md) - Setting up the module
2. [FAQ](FAQ.md) - Configuration and troubleshooting
3. [Process Flow](PROCESS_FLOW.md) - Administrative workflows
4. [Profile Management](../PROFILE_MANAGEMENT.md) - Managing user profiles

### For Developers
1. [Architecture](ARCHITECTURE.md) - System design and structure
2. [Process Flow](PROCESS_FLOW.md) - Technical flows
3. [Submission Process](SUBMISSION_PROCESS.md) - Integration points and dependencies
4. [Job Discovery Guide](../JOB_DISCOVERY_README.md) - Scraping implementation
5. [Google Job Search API Integration](GOOGLE_JOB_SEARCH_API_INTEGRATION.md) - Google for Jobs integration guide
6. [Google Jobs Integration Architecture](GOOGLE_JOBS_INTEGRATION_ARCHITECTURE.md) - Implementation architecture
7. [FAQ](FAQ.md) - Technical questions

### For Business Analysts / Product Managers
1. [Module README](../README.md) - Feature overview
2. [Submission Process](SUBMISSION_PROCESS.md) - End-to-end workflow documentation
3. [Process Flow](PROCESS_FLOW.md) - User and business workflows
4. [FAQ](FAQ.md) - General questions about capabilities

---

## 🔑 Key Concepts

### AI-Powered Resume Tailoring
The module uses AWS Bedrock with Claude 3.5 Sonnet to automatically tailor resumes to specific job postings. When a job posting is created, the system:
1. Loads the configured "Original Resume"
2. Extracts job details (title, company, description)
3. Sends a prompt to Claude AI with context
4. Receives an optimized resume tailored to that job
5. Saves the tailored resume to the job posting node

**Learn more:** [Process Flow - AI Resume Tailoring](PROCESS_FLOW.md#ai-resume-tailoring-service-flow)

### Content Types and Data Storage
The module uses a hybrid approach:
- **Content Types** (nodes) for primary data: Company, Job Posting, Application, Issue, Tailored Resume
- **Custom Database Table** (`job_seeker`) for job seeker profiles - persists through module uninstall
- **Configuration** for module settings (Original Resume node ID, AI parameters)

**Learn more:** [Architecture - Data Model](ARCHITECTURE.md#data-model)

### Service Layer
Business logic is encapsulated in services:
- `ResumeTailoringService` - Handles AI integration for resume generation
- `JobSeekerService` - Manages CRUD operations for job seeker profiles
- `UserProfileService` - User profile management and statistics
- `AbbVieJobScrapingService` - Company-specific job scraping
- Future services for generic scraping and submission automation

**Learn more:** [Architecture - Service Layer](ARCHITECTURE.md#service-layer)

### Queue Workers & Background Processing
The module uses Drupal's Queue API for asynchronous AI operations:
- **Queue Workers:** Process items in background via cron
- **4 Active Workers:** Resume Tailoring, Cover Letter, Resume Parsing, Job Posting Parsing
- **Shared Trait:** `QueueWorkerBaseTrait` centralizes common functionality (7 methods)
- **3-Retry Logic:** Automatic retry with exponential backoff
- **Auto-Suspension:** Items exceeding 3 retries moved to suspended queue
- **Manual Intervention:** Suspended items can be reviewed and retried

**Learn more:** [Architecture - Queue Architecture](ARCHITECTURE.md#queue-architecture)

### Queue Management Interface
**Route:** `/jobhunter/queue`

Administrative interface for monitoring and managing queues:
- **Active Queue Inspector:** View and delete items currently in processing queues
- **Suspended Items:** Review items that failed after 3 retries
- **Manual Actions:** Suspend, retry, or delete queue items
- **GenAI Cache:** Clear cached AI responses to force fresh API calls
- **Pause/Resume:** Temporarily stop queue processing system-wide

**Features:**
- Real-time queue statistics (pending/processing/failed counts)
- Item-level inspection (view full item data)
- Manual suspension for problematic items
- Batch operations support
- Error context and retry history

**Learn more:** [Architecture - Queue Management Infrastructure](ARCHITECTURE.md#queue-management-infrastructure)

### GenAI Debug Inspector
**Route:** `/admin/reports/genai-debug`

Debugging interface for AI API requests and responses:
- **Filter by:** Module, operation, success/failure, date range
- **View Details:** Full prompts, responses, token usage, costs
- **Debug Issues:** Inspect JSON parsing errors, truncation, timeouts
- **Performance:** Track API latency and success rates
- **Cost Tracking:** Monitor token usage and estimated costs

**Database:** Queries `ai_conversation_api_usage` table (logs all GenAI calls)

**Access:** Requires `administer job application automation` permission

**Learn more:** [Architecture - GenAiDebugController](ARCHITECTURE.md#genaidebugcontroller)

### Configuration Management
The module uses Drupal's configuration system for:
- Selecting the "Original Resume" node
- Configuring AI settings (region, model, tokens)
- Toggling automatic tailoring on/off

**Learn more:** [FAQ - Configuration](FAQ.md#configuration)

---

## 📖 Documentation Standards

### Keeping Documentation Updated
When making changes to the module:
1. Update relevant documentation files
2. Update the "Last Updated" date at the bottom of each file
3. Add entries to FAQ for new features or common questions
4. Update process flows if workflows change
5. Commit documentation changes with code changes

### Documentation Format
- All documentation is in Markdown format
- Use clear headings and table of contents
- Include code examples where appropriate
- Use diagrams (ASCII art) for complex flows
- Link between related documentation

### Writing Style
- **Be Clear:** Write for your audience's technical level
- **Be Concise:** Get to the point quickly
- **Be Complete:** Provide all necessary context
- **Be Current:** Keep documentation in sync with code

---

## 🔗 External Resources

### Drupal Resources
- [Drupal 11 Documentation](https://www.drupal.org/docs/drupal-apis)
- [Drupal Coding Standards](https://www.drupal.org/docs/develop/standards)
- [Views Module Documentation](https://www.drupal.org/docs/8/core/modules/views)
- [Profile Module](https://www.drupal.org/project/profile)

### AWS Resources
- [AWS Bedrock Documentation](https://docs.aws.amazon.com/bedrock/)
- [Claude AI Model Details](https://docs.anthropic.com/claude/reference/)
- [AWS SDK for PHP](https://docs.aws.amazon.com/sdk-for-php/)

### Development Tools
- [Drush Documentation](https://www.drush.org/)
- [Composer for Drupal](https://www.drupal.org/docs/develop/using-composer)
- [PHPUnit Testing](https://phpunit.de/documentation.html)

---

## 📝 Contributing to Documentation

We welcome documentation improvements! To contribute:

1. **Identify what needs updating:** Outdated info, missing details, unclear explanations
2. **Make your changes:** Edit the appropriate Markdown file
3. **Follow the style guide:** Match existing formatting and tone
4. **Test your changes:** Ensure links work and formatting renders correctly
5. **Submit changes:** Commit to version control with clear message

---

## 🆘 Getting Help

If you can't find what you need in the documentation:

1. **Search the FAQ:** [FAQ.md](FAQ.md) covers many common questions
2. **Check the logs:** Navigate to `/admin/reports/dblog` and filter by `job_hunter`
3. **Review the code:** The codebase is well-commented
4. **Ask your team:** Consult with other developers or administrators
5. **Open an issue:** Create a detailed issue report in your repository

---

## 📅 Document History

### Version 1.0 (January 2026)
- Initial comprehensive documentation structure
- Created Architecture, Process Flow, and FAQ documents
- Established documentation standards
- Added this README index

### Version 1.1 (February 2026)
- Added comprehensive Submission Process documentation
- Documented all 6 stages of job application workflow
- Detailed dependencies, data sources, and integration points
- Included implementation status for each feature

### Version 1.2 (February 11, 2026)
- Added Queue Architecture documentation (QueueWorkerBaseTrait, 4 active workers)
- Documented Queue Management Interface (/jobhunter/queue)
- Added GenAI Debug Inspector documentation (/admin/reports/genai-debug)
- Documented new operational tables (ai_conversation_api_usage, jobhunter_queue_suspended, etc.)
- Updated Controller Architecture section (JobHunterControllerTrait, GenAiDebugController)
- Documented 3-retry logic and auto-suspension for failed queue items
- Added suspend button functionality to queue management
- Updated ARCHITECTURE.md with comprehensive queue and controller documentation

### Version 1.3 (February 13, 2026)
- **NEW: Company Research Path Design Package** - Complete design for company research automation (NOT YET IMPLEMENTED)
  - Added COMPANY_RESEARCH_DESIGN_SUMMARY.md - Quick reference and overview
  - Added COMPANY_RESEARCH_PATH_DESIGN.md - Complete technical design (8-step process, 6 services, 2 database tables)
  - Added COMPANY_RESEARCH_DIAGRAMS.md - Visual process flows and architecture diagrams
  - Added COMPANY_RESEARCH_IMPLEMENTATION_EXAMPLES.md - Code examples and implementation patterns
- Updated README.md documentation index with company research section

---

## 📂 Complete File List

### Main Documentation (This Directory)
- `README.md` (this file) - Documentation index
- `ARCHITECTURE.md` - Technical architecture documentation
- `PROCESS_FLOW.md` - Process flows and workflows
- `SUBMISSION_PROCESS.md` - Job application submission process, dependencies, data sources, and integration points
- `FAQ.md` - Frequently asked questions
- `API_INTEGRATION_GUIDE.md` - External API setup guide (AWS Bedrock, SerpAPI, Google Cloud, Adzuna, USAJobs)
- `QUEUE_TROUBLESHOOTING.md` - Queue worker debugging and optimization
- `PERMISSIONS.md` - Complete permissions matrix and role configuration
- `GOOGLE_JOB_SEARCH_API_INTEGRATION.md` - Google for Jobs integration guide
- `GOOGLE_JOBS_INTEGRATION_ARCHITECTURE.md` - Google Jobs feature implementation architecture
- `RESUME_JSON_SCHEMA.md` - Resume data JSON schema specification
- `JOB_REQUISITION_JSON_SCHEMA.md` - Job requisition JSON schema
- `JOB_TAILORING_DESIGN.md` - Job tailoring design documentation
- `RESUME_PDF_STYLE_SCHEMA.md` - Resume PDF styling schema
- `RESUME_STYLE_MAPPING_REPORT.md` - Resume style mapping report
- **`COMPANY_RESEARCH_DESIGN_SUMMARY.md`** - Company research design overview (NEW)
- **`COMPANY_RESEARCH_PATH_DESIGN.md`** - Complete company research design specification (NEW)
- **`COMPANY_RESEARCH_DIAGRAMS.md`** - Visual process flows and diagrams (NEW)
- **`COMPANY_RESEARCH_IMPLEMENTATION_EXAMPLES.md`** - Implementation code examples (NEW)

### Module Root Documentation
- `../README.md` - Module overview and quick start
- `../INSTALL.md` - Installation instructions
- `../ARCHITECTURE.md` - Comprehensive architecture design
- `../PROFILE_MANAGEMENT.md` - User profile field documentation
- `../JOB_DISCOVERY_README.md` - Job scraping technical guide
- `../BRANDING_AUDIT.md` - Branding analysis
- `../FUNCTION_MAPPING.md` - Function mapping documentation
- `../IMPLEMENTATION_PROGRESS.md` - Development progress tracking

---

**Last Updated:** February 13, 2026  
**Module Version:** 1.0.1

**Happy coding! 🚀**
