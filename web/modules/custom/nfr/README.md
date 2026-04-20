# National Firefighter Registry (NFR) Module

**Last Updated:** February 6, 2026

## ⚠️ CRITICAL: Data Preservation Warning

**DO NOT UNINSTALL THIS MODULE** without backing up the database first. Drupal automatically drops all tables defined in hook_schema() when a module is uninstalled, which will **permanently delete all collected data**, including:
- Participant consent records
- Questionnaire responses
- Cancer diagnoses
- Work history
- All user data

### Safe Update Procedures

**For module updates:**
```bash
# Pull latest code
git pull origin main

# Run database updates (NOT uninstall/reinstall)
drush updb -y
drush cr
```

**If you must reinstall the module:**
```bash
# 1. Back up database FIRST
drush sql:dump > nfr-backup-$(date +%Y%m%d).sql

# 2. Then uninstall if needed
drush pmu nfr -y

# 3. Reinstall
drush en nfr -y

# 4. If tables were dropped, restore from backup
drush sqlc < nfr-backup-YYYYMMDD.sql
```

## Overview

The National Firefighter Registry (NFR) module is a CDC cancer surveillance and health tracking system for firefighters. This module supports the collection, management, and analysis of cancer incidence data among firefighters nationwide.

## Key Features

### 1. Participant Registration
- Comprehensive firefighter profile collection
- Demographics and career information tracking
- Consent management for research participation
- User-friendly registration workflow

### 2. Cancer Data Collection
- Cancer incidence tracking and reporting
- Cancer type and stage documentation
- Diagnosis date recording
- De-identified data storage for research

### 3. State Cancer Registry Linkages
- Integration with state cancer registries
- Consent-based linkage system
- Batch processing capabilities
- Linkage statistics and monitoring
- Multi-state coordination support

### 4. USFA NERIS Integration
- Data ingestion from USFA National Emergency Response Information System
- Bi-directional synchronization
- NERIS ID tracking and validation
- API-based data exchange

### 5. Longitudinal Data Collection
- Follow-up survey management
- Time-series health data tracking
- Survey versioning and updates
- JSON-based flexible data storage

### 6. Data Analysis & Dashboards
- Summary statistics generation
- Cancer incidence by type and location
- Participation metrics by state
- State registry linkage rates
- Public-facing data dashboard
- De-identified data export capabilities

## Database Schema

The module creates seven primary tables for enrollment and longitudinal tracking:

### nfr_consent
Informed consent tracking:
- User ID reference
- Consent status and timestamp
- Withdrawal tracking
- Research authorization
- Data sharing preferences
- HIPAA authorization
- JSON metadata storage

### nfr_user_profile
Firefighter demographic and contact information:
- Personal details (name, DOB, SSN)
- Contact information
- Department affiliation
- Emergency contacts
- JSON metadata storage

### nfr_questionnaire
Comprehensive enrollment questionnaire (9 sections):
- Demographics
- Career history
- Work practices
- Health history
- Cancer history
- Lifestyle factors
- Family history
- Environmental exposures
- Additional information
- JSON storage for flexible sections

### nfr_work_history
Detailed employment records:
- Department and location
- Position/rank
- Dates of service
- Employment type (career/volunteer)
- JSON metadata

### nfr_job_titles
Job title and role tracking:
- Title and rank
- Department assignment
- Date ranges
- Duties and responsibilities

### nfr_incident_frequency
Fire and hazmat exposure tracking:
- Incident counts by type
- Time period
- Exposure levels
- JSON metadata

### nfr_follow_up_surveys
Longitudinal data collection:
- Survey type and date
- JSON-encoded responses
- Version tracking
- Follow-up intervals

### Legacy Tables (Deprecated)
The following tables are maintained for backward compatibility but will be removed in future versions:
- `nfr_firefighters` - Replaced by nfr_user_profile and nfr_questionnaire
- `nfr_cancer_data` - Replaced by questionnaire cancer history section
- `nfr_longitudinal_data` - Replaced by nfr_follow_up_surveys

## Services

### NERISIntegration Service
- `importFromNERIS($neris_id)`: Import firefighter data from NERIS
- `syncWithNERIS($firefighter_id)`: Sync existing records with NERIS

### CancerRegistryLinkage Service
- `linkToStateRegistry($cancer_data_id, $state_registry_id)`: Link cancer record to state registry
- `getLinkageStatistics()`: Get linkage statistics by state
- `processBatchLinkage($state)`: Process batch linkages for a state

### DataExport Service
- `exportSummaryStatistics()`: Generate summary statistics for dashboards
- `exportToCSV($type, $filters)`: Export de-identified data to CSV

## Permissions

### User Roles
- **NFR Administrator**: Full system access, all permissions
- **NFR Researcher**: View and export data, view reports
- **Firefighter**: Access to personal dashboard and enrollment
- **Fire Department Administrator**: Department-level data access

### Permission Details
- **Access NFR Dashboard**: View the NFR dashboard
- **Administer NFR**: Configure module settings and access admin tools
- **Manage Firefighters**: Create, edit, and delete firefighter records
- **View Firefighter Records**: View firefighter registry records
- **View Cancer Data**: Access cancer incidence and statistics
- **Manage Cancer Data**: Create, edit, and delete cancer records
- **Export NFR Data**: Export registry data for analysis (restricted)
- **Manage State Registry Linkages**: Configure state registry integrations (restricted)
- **View NFR Reports**: Access research reports and analytics

## Routes & Pages

### Public & Participant Routes
- `/nfr`: Welcome page with enrollment process overview
- `/nfr/consent`: Informed consent form
- `/nfr/profile`: User profile collection
- `/nfr/questionnaire`: Enrollment questionnaire (9 sections)
- `/nfr/review`: Review and submit enrollment
- `/nfr/confirmation`: Enrollment confirmation
- `/nfr/my-dashboard`: Personal participant dashboard
- `/nfr/dashboard`: Main dashboard with summary statistics
- `/nfr/firefighters`: Firefighter list view
- `/nfr/data-dashboard`: Public data dashboard
- `/nfr/cancer-data`: Cancer data summary
- `/nfr/data`: Public-facing data and statistics
- `/nfr/documentation`: Interactive documentation hub

### Administrative Routes
- `/admin/config/nfr/settings`: Module configuration
- `/admin/nfr`: Admin dashboard with metrics
- `/admin/nfr/participants`: Participant management
- `/admin/nfr/participant/{id}`: Participant detail view
- `/admin/nfr/linkage`: State registry linkage workflow
- `/admin/nfr/data-quality`: Data quality monitoring
- `/admin/nfr/reports`: Research reports
- `/admin/nfr/issues`: Issue tracking and management

### Testing & Validation
- `/nfr/validation`: **Validation Dashboard** - Automated route testing interface
  - Tests all NFR routes with different user permission levels
  - Validates access control for each user role
  - Provides visual feedback (✅ 200 OK, 🚫 403 Forbidden, ❌ Error)
  - Supports individual route testing and batch "Run All Tests" functionality
  - **As requirements are validated, additional test cases will be added to this page**

## Configuration

Access module settings at `/admin/config/nfr/settings`

### General Settings
- Email notifications
- Default certification period
- Badge number requirements

### State Cancer Registry Integration
- Automatic linkage enablement
- Consent requirements
- Batch processing options

### USFA NERIS Integration
- NERIS synchronization toggle
- API endpoint configuration
- API key management

### Data Export Settings
- Public dashboard visibility
- Data anonymization options
- Export format preferences

## Development

### Testing & Quality Assurance

The NFR module includes a comprehensive validation dashboard at `/nfr/validation` for automated testing:

**Validation Dashboard Features:**
- Automated route access testing for all NFR routes
- Permission verification across all user roles
- Visual test results with status indicators
- Individual route testing and batch "Run All Tests" mode
- Real-time feedback and statistics

**Test Coverage:**
- 36+ NFR routes tested
- 6 user contexts (Anonymous + 5 test roles)
- 216+ individual access control tests
- Continuous validation as new routes are added

**Using the Validation Dashboard:**
1. Log in as an NFR Administrator
2. Visit `/nfr/validation`
3. Click individual "Test" buttons or use "Run All Tests"
4. Review results: ✅ (200 OK), 🚫 (403 Forbidden), ❌ (Error)

**Test Users:** See `TEST_USER_CREDENTIALS.md` for test account details.

**Continuous Testing:**
As business requirements are validated and new features are developed, additional automated test cases will be added to the validation dashboard to ensure quality and compliance.

### Directory Structure

```
nfr/
├── css/
│   ├── nfr-enrollment.css          # Enrollment forms styling
│   ├── nfr-participant-dashboard.css  # Participant dashboard
│   ├── nfr-admin.css               # Admin dashboard
│   ├── nfr-validation.css          # Validation dashboard
│   ├── nfr-dashboard.css           # Legacy dashboard
│   └── nfr-documentation.css       # Documentation pages
├── js/
│   ├── nfr-validation.js           # Validation testing
│   └── nfr-dashboard.js            # Dashboard interactions
├── src/
│   ├── Commands/
│   │   └── NFRCommands.php         # Drush commands
│   ├── Controller/
│   │   ├── NFREnrollmentController.php  # Welcome & confirmation
│   │   ├── NFRDashboardController.php   # Participant dashboard
│   │   ├── NFRAdminController.php       # Admin interface
│   │   ├── NFRValidationController.php  # Route testing
│   │   ├── NFRController.php            # Legacy controller
│   │   └── NFRDocumentationController.php  # Documentation
│   ├── Form/
│   │   ├── NFRConsentForm.php           # Informed consent
│   │   ├── NFRUserProfileForm.php       # User profile
│   │   ├── NFRQuestionnaireForm.php     # 9-section questionnaire
│   │   ├── NFRReviewSubmitForm.php      # Review before submit
│   │   ├── NFRRegistrationForm.php      # Legacy registration
│   │   └── NFRSettingsForm.php          # Admin settings
│   └── Service/
│       ├── NERISIntegration.php         # USFA integration
│       ├── CancerRegistryLinkage.php    # State registry linkage
│       └── DataExport.php               # Data export utilities
├── css/
│   ├── nfr-dashboard.css
│   └── nfr-documentation.css
├── js/
│   └── nfr-dashboard.js
├── templates/
│   ├── nfr-dashboard.html.twig
│   ├── nfr-documentation.html.twig
│   └── nfr-documentation-page.html.twig
├── documents/                      # Project documentation
│   ├── README.md
│   ├── BUSINESS_REQUIREMENTS.md
│   ├── USER_ROLES_AND_PROCESS_FLOWS.md
│   ├── PAGE_SPECIFICATIONS.md
│   └── *.pdf                       # CDC official documents
├── nfr.info.yml
├── nfr.module
├── nfr.routing.yml
├── nfr.links.menu.yml
├── nfr.permissions.yml
├── nfr.libraries.yml
├── nfr.services.yml
├── drush.services.yml
├── nfr.install
├── README.md
├── ARCHITECTURE.md
├── INSTALLATION.md
└── DRUPAL11_COMPLIANCE.md
```

## Frontend Architecture

The NFR module follows Forseti's centralized theming patterns:

- **Templates**: Twig templates in `templates/` directory for all rendered output
- **Styling**: Module-specific CSS in `css/` directory
- **Theme Integration**: All libraries depend on `forseti/style` for consistent theming
- **No Inline Markup**: Controllers return structured render arrays, templates handle presentation
- **Assets**: Defined in `nfr.libraries.yml` with proper dependencies

## Documentation

### Web-Based Documentation
Visit `/nfr/documentation` for interactive access to all project documentation including:
- Development Documentation (Business Requirements, User Roles, Page Specifications)
- CDC Official Documents (Protocol, User Profile Form, Enrollment Questionnaire)
- Additional Technical Documentation

### Core Documentation
- **[README.md](README.md)** - This file, module overview and features
- **[ARCHITECTURE.md](ARCHITECTURE.md)** - System architecture and design patterns
- **[INSTALLATION.md](INSTALLATION.md)** - Installation, deployment, and configuration guide
- **[DRUPAL11_COMPLIANCE.md](DRUPAL11_COMPLIANCE.md)** - Drupal 11 standards compliance documentation

### Project Documentation
- **[documents/BUSINESS_REQUIREMENTS.md](documents/BUSINESS_REQUIREMENTS.md)** - Complete business requirements
- **[documents/USER_ROLES_AND_PROCESS_FLOWS.md](documents/USER_ROLES_AND_PROCESS_FLOWS.md)** - User workflows
- **[documents/PAGE_SPECIFICATIONS.md](documents/PAGE_SPECIFICATIONS.md)** - Page-level specifications

### Quick Links
- **Getting Started**: See [INSTALLATION.md](INSTALLATION.md) for setup instructions
- **Architecture Overview**: See [ARCHITECTURE.md](ARCHITECTURE.md) for system design
- **Code Standards**: See [DRUPAL11_COMPLIANCE.md](DRUPAL11_COMPLIANCE.md) for compliance details
- **Full Documentation**: Visit `/nfr/documentation` on your site

## Version

1.0.0

## License

Proprietary
