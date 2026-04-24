<!-- AUTOMATION VALIDATION: 2026-04-23 - automation of development confirmed for this repo -->
# Job Hunter — Drupal Module for AI-Powered Job Application Automation

Add intelligent job search automation to your Drupal site. Helps users automate resume tailoring, discover jobs across multiple platforms, and submit applications with AI assistance.

**Perfect for:** Job boards • HR systems • Career development platforms • Staffing agencies • Educational job placement services

---

## Why This Module?

Job application is tedious. Users spend hours tailoring resumes and filling identical forms. With Job Hunter, they can focus on interviews instead.

✅ **Production-Ready** — Actively maintained, well-tested  
✅ **Drupal-Native** — Uses content types, fields, views, permissions  
✅ **Extensible** — Easy to customize for your specific needs  
✅ **AI-Powered** — AWS Bedrock integration for smart resume tailoring  
✅ **Data-Safe** — Respects Drupal data preservation policies  

---

## Quick Start

### 1. Install

```bash
composer require drupal/job_hunter
drush pm:enable job_hunter
drush updatedb
```

### 2. Configure

Navigate to **Admin → Configuration → Job Hunter Settings** (`/admin/config/job_hunter`)

- Set your master resume node
- Configure AWS Bedrock credentials (for AI features)
- (Optional) Add job API keys (Google Jobs, Adzuna, USAJobs)

### 3. Done!

Users can now access:
- `/jobhunter` — Job discovery & saved jobs
- `/jobhunter/profile` — Profile & resume management
- `/jobhunter/my-jobs` — Application dashboard

[Full installation guide →](docs/INSTALL.md)

---

## What This Module Provides

### 📋 Core Features

| Feature | What It Does |
|---------|------------|
| **Job Management** | Create/manage jobs, companies, and applications with custom content types |
| **Resume Automation** | Users upload once; system tailors for each application |
| **Multi-Source Discovery** | Search Google Jobs, Adzuna, USAJobs, plus custom sources |
| **Smart Submissions** | Queue-based application filing with retry logic |
| **Application Tracking** | Dashboard showing status, dates, notes, interview prep |
| **Admin Dashboards** | Pre-built Views for content management |
| **AI Integration** | AWS Bedrock (Claude 3.5 Sonnet) for resume analysis |

### 🔧 Developer Features

- **Drupal Content Types** — Company, Job Posting, Application, Issue tracking
- **User Fields** — Resume files, profiles, preferences (standard Drupal fields)
- **Permission System** — Granular role-based access control
- **Queue Workers** — Background processing for reliable automation
- **Event Hooks** — React to job saves, applications, submissions
- **Views Integration** — All major interfaces built with Views
- **Hybrid Storage** — Nodes for canonical content + tables for operational data
- **Drush Commands** — Automation and admin tasks
- **REST APIs** — JSON:API and custom endpoints

### 📊 Admin Interfaces

- Job & application management
- User profile completeness tracking
- Error queue with retry capabilities
- Resume parsing status
- Company research & outreach tracking
- Interview preparation materials

---

## Use Cases

### Job Board
```
User uploads resume once.
Browse jobs from multiple sources.
Job Hunter automatically tailors resume.
Click "Apply" — submission happens in background.
Track application status and next steps.
```

### HR/Recruiting
```
HR staff post job openings.
Job Hunter finds matching candidates.
Automates candidate resume screening.
Tracks candidate progress through pipeline.
```

### Career Services (Educational)
```
Career office manages job board.
Students use Job Hunter to automate applications.
Counselors track placement success.
System learns which jobs/companies match student profiles.
```

### Staffing Agency
```
Automate candidate matching to job openings.
Bulk submission to multiple employers.
Tracking and reporting on placements.
Candidate management and outreach automation.
```

---

## How It Works

### User Workflow

1. **Setup Profile** — Upload resume, set preferences, configure job search criteria
2. **Discover Jobs** — Search across multiple job sources or browse saved jobs
3. **Tailor Resume** — AI automatically adapts resume for each job
4. **Submit Application** — One-click submission (or batch submit via CLI)
5. **Track Progress** — Dashboard shows all applications, statuses, and next steps

### Behind the Scenes

- **Resume Parsing** — AWS Bedrock extracts structure from uploaded resume
- **Job Discovery** — Aggregates results from Google Jobs, Adzuna, USAJobs, custom sources
- **Resume Tailoring** — AI analyzes job requirements and customizes resume
- **Application Submission** — Queue workers submit via Playwright bridge (Greenhouse, LinkedIn, etc.)
- **Error Handling** — Graceful retry, admin queue management

---

## Architecture

### Content Structure (Drupal Nodes)

```
Company (content type)
├── Company name, description, research notes
└── Contact info (websites, hiring managers)

Job Posting (content type)
├── Job title, description, requirements
├── Company reference
└── Application deadline, salary, location

Application (content type)
├── User (job seeker)
├── Job reference
├── Status (applied, pending, rejected, interviewed, offered)
└── Interview notes, offer details

Tailored Resume (content type)
├── Base resume reference
├── Job reference
└── AI-tailored content
```

### User Fields (Drupal User Profile)

```
User → field_resume_file — Uploaded resume document
     → field_profile_completeness — 0-100% profile score
     → field_job_preferences — Job search criteria
     → field_saved_jobs — Saved job references
```

### Database Tables (Operational Data)

```
jobhunter_companies — Aggregate company data
jobhunter_job_requirements — Job catalog
jobhunter_saved_jobs — User-specific saved jobs
jobhunter_job_seeker — User job search profiles
jobhunter_applications — Application tracking
jobhunter_tailored_resumes — Generated resume versions
jobhunter_google_jobs_sync — Integration sync metadata
```

**Why two storage layers?**  
Nodes provide user-facing content, versioning, and permissions. Tables handle high-volume, ephemeral operational data (job search results, sync metadata).

### Extensibility Points

```php
// Add custom job sources
hook_job_discovery_sources()

// React to events
hook_job_saved($job)
hook_application_submitted($application)
hook_resume_tailored($resume)

// Customize AI behavior
hook_resume_tailoring_context_alter(&$context)

// Extend user profile
hook_job_seeker_profile_alter(&$profile)
```

[Full architecture guide →](docs/ARCHITECTURE.md)

---

## Extending the Module

### Add a Custom Job Source

```php
// my_module.job_hunter_sources.inc
namespace Drupal\my_module\Plugin\JobDiscovery;

use Drupal\job_hunter\Plugin\JobDiscoveryBase;

/**
 * @JobDiscoverySource(
 *   id = "my_custom_source",
 *   label = @Translation("My Custom Job Source"),
 * )
 */
class MyCustomSource extends JobDiscoveryBase {
  public function search($query) {
    // Call your API, parse results
    $jobs = $this->queryMyAPI($query);
    
    // Return standardized format
    return $this->normalizeResults($jobs);
  }
}
```

### React to Job Application Events

```php
function my_module_job_hunter_application_submitted($application, $job) {
  // Send custom notification
  $user = $application->getOwner();
  \Drupal::messenger()->addMessage(
    t('Application submitted for @title at @company',
      ['@title' => $job->getTitle(), '@company' => $job->getCompany()]
    )
  );
  
  // Log to external system, trigger workflow, etc.
}
```

### Customize Resume Parsing

```php
function my_module_resume_tailoring_context_alter(&$context) {
  // Add custom data to AI tailoring context
  $context['company_culture'] = getCurrentCompanyCulture();
  $context['role_level'] = getCurrentRoleLevel();
}
```

[Full extension guide →](docs/EXTENDING.md)

---

## Installation Requirements

### Drupal
- **Drupal 10.3+** or **Drupal 11+**
- Standard Drupal core (no additional core modules required)

### PHP
- **PHP 8.3+**
- Standard extensions (curl, json, xml)

### AI Features (Optional)
- **AWS Account** with Bedrock access
- **Claude 3.5 Sonnet** model enabled
- **Credentials** in environment: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`

### Job Discovery (Optional)
- **SerpAPI** key (Google Jobs) — [Sign up free](https://serpapi.com)
- **Adzuna** key (job board aggregator)
- **USAJobs** API key (US government jobs)

[Detailed setup guide →](docs/INSTALL.md)

---

## Configuration

### Basic Settings
Navigate to **Admin → Configuration → Job Hunter Settings**

| Setting | Purpose |
|---------|---------|
| Original Resume Node | Master resume for tailoring |
| Enable Auto-Tailoring | Auto-create tailored versions |
| Profile Completeness Threshold | Min% for auto-submission |
| Job Discovery Sources | APIs to search |

### API Credentials
Configure via environment variables or settings form:

```bash
# .env (recommended for security)
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
SERPAPI_KEY=...
ADZUNA_KEY=...
```

[Full configuration guide →](docs/INSTALL.md)

---

## Contributing

We welcome contributions from Drupal developers! Here's where you can help:

### 🟢 Good First Contributions

**Documentation**
- [ ] Write a user guide for site builders
- [ ] Create video tutorial for module setup
- [ ] Expand FAQ with common questions
- [ ] Improve error messages

**Testing**
- [ ] Test with different resume formats
- [ ] Verify on Drupal 11 latest
- [ ] Accessibility testing (WCAG 2.1)
- [ ] Performance testing with large datasets

### 🟡 Intermediate Contributions

**New Features**
- [ ] LinkedIn job source integration
- [ ] Indeed.com job source integration
- [ ] Additional resume export formats (ATS-friendly text)
- [ ] Interview scheduling integration
- [ ] Salary history tracking

**Improvements**
- [ ] Optimize resume parsing performance
- [ ] Improve resume tailoring AI prompts
- [ ] Better error recovery and messages
- [ ] Admin dashboard performance

### 🔴 Advanced Contributions

**Architecture**
- [ ] Performance optimization for large job datasets
- [ ] Caching strategy for job discovery results
- [ ] Advanced scheduling for bulk submissions
- [ ] Machine learning for job recommendations

**Integration**
- [ ] HRIS system integration (ADP, Workday, etc.)
- [ ] Email notification system
- [ ] Slack/Teams notifications
- [ ] Calendar integration for interviews

### Getting Started

1. **Fork the repository**
2. **Clone locally** (add to Drupal installation)
3. **Create a branch** for your feature
4. **Run tests** (see below)
5. **Submit a pull request** with clear description

### Running Tests

```bash
# Unit tests
./vendor/bin/phpunit modules/contrib/job_hunter/tests

# Code standards (Drupal coding standards)
./vendor/bin/phpcs modules/contrib/job_hunter

# PHP static analysis
./vendor/bin/phpstan analyse modules/contrib/job_hunter
```

### Code Standards

- PSR-12 style
- Drupal coding standards
- Comprehensive PHPDoc comments
- Test coverage for new code

[Full contribution guide →](CONTRIBUTING.md)

---

## Issues & Roadmap

### Current Work
- Phase 2: AI conversation improvements
- Phase 3: Additional job source integrations
- Phase 4: Mobile app support

### Help Wanted
- 🔴 **Critical** — Resume parsing performance (Playwright optimization)
- 🟠 **High** — LinkedIn job source integration
- 🟡 **Medium** — Admin dashboard UI/UX refresh
- 🟢 **Nice to have** — Video tutorials

[View all issues →](https://github.com/Forseti-Life/forseti-job-hunter/issues)  
[View roadmap →](docs/ROADMAP.md)

---

## Status & Maintenance

| Status | Value |
|--------|-------|
| **Latest Release** | v1.1.0 |
| **Release Date** | April 2026 |
| **Maintenance** | ✅ Actively maintained |
| **Production Ready** | ✅ Yes |
| **Drupal Versions** | 10.3+, 11+ |
| **Security** | ✅ Actively audited |

Maintained by [Keith Miller](https://github.com/keithaumiller) & [Forseti-Life Team](https://github.com/Forseti-Life)

**Seeking:** Additional maintainers to help grow this module

---

## Documentation

- 📖 [Installation & Setup](docs/INSTALL.md) — Get started in 5 minutes
- 🏗️ [Architecture & Design](docs/ARCHITECTURE.md) — How it works internally
- 🔧 [Extending the Module](docs/EXTENDING.md) — Custom job sources, hooks, events
- ❓ [FAQ](docs/FAQ.md) — Common questions answered
- 📚 [Process Flows](docs/PROCESS_FLOW.md) — Visual workflow diagrams
- 🔌 [API Reference](docs/API.md) — REST APIs and hooks

---

## Community

- **GitHub Issues** — Report bugs, request features
- **Drupal.org** — Drupal-specific discussion
- **GitHub Discussions** — General Q&A and ideas

We're building this together. Your feedback makes it better.

---

## License

GPL-2.0+

This module is licensed under the GNU General Public License v2.0 or later, consistent with Drupal community standards.

---

## Support & Credits

**Maintainer:** [Keith Miller](https://github.com/keithaumiller)  
**Organization:** [Forseti-Life Community](https://github.com/Forseti-Life)  
**Contributors:** [See all →](CONTRIBUTORS.md)

**Built with:**
- Drupal 10/11
- AWS Bedrock (Claude 3.5 Sonnet)
- Playwright (browser automation)

**Special thanks to** the Drupal community for best practices, contrib modules, and guidance.

---

## Get Started Now

```bash
# Add to your Drupal project
composer require drupal/job_hunter

# Install
drush pm:enable job_hunter

# Configure
Visit /admin/config/job_hunter

# Done!
Users can now access /jobhunter
```

Questions? [Open an issue](https://github.com/Forseti-Life/forseti-job-hunter/issues) or [check the FAQ](docs/FAQ.md).

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>
