# Job Application Automation Module - Installation Guide### Administrative Views (Node-Based Content Management)

Following **Drupal 11 administrative patterns**, all content is managed through Views-based interfaces:

#### Node Administration Paths:
- **Companies Management**: `/admin/content/companies` - Full CRUD operations on Company nodes
- **Job Postings Management**: `/admin/content/job-postings` - Comprehensive job posting administration  
- **Applications Management**: `/admin/content/applications` - Application tracking and status management
- **Issues Management**: `/admin/content/issues` - Support request and system issue handling

#### Content Architecture Benefits:
- **Standard Drupal Operations** - Add, Edit, Delete, Bulk Operations via familiar Drupal interfaces
- **Permission Integration** - Content access controlled by Drupal's robust permission system  
- **Workflow Ready** - All content types support Drupal's content moderation workflows
- **Export/Import Compatible** - Full support for content migration and configuration export

### User Profile Extensions (Field API Integration)

**Enhanced User Entity** with 25+ additional fields using **Drupal's Field API**:
- Professional profile data integrated directly into User entities
- Maintains user entity integrity while extending functionality
- Full integration with user registration and profile management forms
- Compatible with profile management contributed modules

## System Requirements & Installation

### Prerequisites
- **Drupal 10 or 11** (Latest stable version recommended - module supports both Drupal 10 and 11)
- **PHP 8.1+** (PHP 8.3 recommended for optimal performance) 
- **MySQL 8.0+** or **PostgreSQL 13+**
- **Composer** for dependency management

### Production Server Information

**Current Production Environment:**
- **Server**: AWS EC2 instance
- **Operating System**: Ubuntu 24.04.1 LTS (Linux 6.14.0-1013-aws)
- **Architecture**: x86_64
- **Web Root**: `/var/www/html/forseti/`
- **Drupal Root**: `/var/www/html/forseti/web/`
- **Web Server User**: `www-data`
- **SSH User**: `ubuntu`

**Production Drush Commands:**
```bash
# Navigate to Drupal root
cd /var/www/html/forseti

# Run database updates (requires www-data user)
sudo -u www-data ./vendor/bin/drush updatedb -y

# Run entity updates
sudo -u www-data ./vendor/bin/drush entity:updates -y

# Clear caches
sudo -u www-data ./vendor/bin/drush cache:rebuild

# Check module status
sudo -u www-data ./vendor/bin/drush pm:list | grep job_hunter
```

**Database Management:**
```bash
# Verify table creation
sudo -u www-data ./vendor/bin/drush sqlq "SHOW TABLES LIKE 'profile__field_profile_completeness';"

# Check entity field storage
sudo -u www-data ./vendor/bin/drush entity:updates --show
```

### Installation Process

#### Method 1: Via Drupal Admin UI
1. Upload module to `/web/modules/custom/job_hunter/`
2. Navigate to **Extend** (`/admin/modules`)  
3. Enable "Job Application Automation"
4. **Installation hook automatically executes** - Creates all content types, fields, and configurations

#### Method 2: Via Drush (Recommended for Development)
```bash
# Enable module with automatic dependency resolution
drush en job_hunter -y

# Verify installation
drush config-export
```

#### Method 3: Via Composer (Production Workflow)
```bash
# Add to composer.json repositories section for custom modules
composer require drupal/job_hunter
drush en job_hunter -y
```

### Post-Installation Verification

#### Configuration Verification:
```bash
# Verify content types were created
drush config:get node.type.company
drush config:get node.type.job_posting  
drush config:get node.type.application
drush config:get node.type.issue

# Verify field configurations
drush field:list --entity-type=node
drush field:list --entity-type=user
```

#### Functional Testing:
1. **Content Creation Test**: Navigate to `/node/add` - Verify all 4 content types available
2. **Administrative Views Test**: Check all admin paths respond with HTTP 200
3. **Permission Test**: Verify appropriate user roles can access content creation
4. **Field Validation Test**: Create test content to verify field constraintsOverview

This module follows **Drupal 11 best practices** and utilizes **nodes as the primary data storage mechanism** for all business entities. The installation system creates a comprehensive content architecture using Drupal's native node system for optimal performance, searchability, and administrative management.

## Drupal 11 Development Standards Compliance

### Code Quality & Standards
- ✅ **Drupal Coding Standards** - All code follows Drupal 11 coding standards (PHP_CodeSniffer validated)
- ✅ **Entity API Integration** - Full compliance with Drupal's Entity API patterns
- ✅ **Hook Implementation** - Proper use of Drupal's hook system for extensibility
- ✅ **Configuration Management** - All settings exportable via Drupal's Configuration API
- ✅ **Security Best Practices** - Input sanitization, output filtering, permission checks

### Performance Optimization
- **Entity Caching** - All node content automatically cached via Drupal's entity cache
- **Field Optimization** - Efficient field storage using appropriate field types
- **Database Queries** - Leverages Drupal's Entity Query API for optimized database access
- **Views Caching** - Administrative views configured with appropriate cache contexts

### Extensibility & Integration
- **API-First Design** - All content accessible via REST API, JSON:API, and GraphQL (with contrib modules)
- **Module Dependencies** - Clean dependency management with core modules only
- **Configuration Export** - Full configuration export/import compatibility for deployment workflows
- **Testing Framework** - Ready for unit and functional testing with Drupal's testing framework

## Troubleshooting

### Common Installation Issues

#### Content Types Not Created
```bash
# Check module installation status
drush pm:list | grep job_hunter

# Manually trigger installation hooks if needed
drush php:eval "job_hunter_install();"
```

#### Permission Issues
```bash  
# Clear cache after installation
drush cr

# Rebuild permissions
drush php:eval "node_access_rebuild();"
```

#### Field Configuration Problems
```bash
# Verify field configuration export
drush config:export

# Check for field storage issues
drush field:list --field-type=entity_reference
```

### Performance Monitoring
```bash
# Monitor content creation performance
drush sqlq "SELECT COUNT(*) FROM node WHERE type IN ('company', 'job_posting', 'application', 'issue');"

# Check database optimization
drush sqlq "SHOW TABLE STATUS LIKE 'node%';"
```

## Development Workflow Integration

### Local Development Setup
1. **Enable Development Modules**: `devel`, `webprofiler` for debugging
2. **Configure Local Settings**: Use local.settings.php for development configurations
3. **Content Generation**: Use Devel Generate for test content creation

### Production Deployment
1. **Configuration Export**: Export all configurations before deployment
2. **Database Updates**: Run `drush updatedb` after code deployment  
3. **Cache Management**: Clear all caches post-deployment
4. **Content Verification**: Verify all administrative interfaces are accessible

---

**Module Version**: 1.0.0  
**Drupal Compatibility**: 11.2+  
**Last Updated**: January 2025  
**Architecture**: Node-based content management with Field API integration

## Installation Summary

### Content Types Created (4 Node Types)
1. **Company** (`company`) - Employer companies for job application automation
2. **Job Posting** (`job_posting`) - Individual job opportunities discovered through scraping  
3. **Application** (`application`) - User job applications and their status
4. **Issue** (`issue`) - Support requests and system issues requiring attention

### Field Architecture (71+ Custom Fields)

**Company Node Fields (9):**
- Entity Reference, Link, Text, List, Boolean, Image fields following Drupal field API standards

**Job Posting Node Fields (14):**  
- Entity References to Company nodes, structured text fields, date fields, taxonomy references

**Application Node Fields (11):**
- Entity References linking Users, Companies, and Job Postings in normalized relationships

**Issue Node Fields (12):**
- Comprehensive issue tracking with priority, status, assignment, and resolution tracking

**User Entity Extension (25+ Fields):**
- Extended user profiles using field API, maintaining user entity integrity

#### Administrative Views (4)
- **Companies Management** - `/admin/content/job-application/companies`
- **Job Postings Management** - `/admin/content/job-application/job-postings`  
- **Applications Tracking** - `/admin/content/job-application/applications`
- **Error Queue Management** - `/admin/content/job-application/errors`

### Installation Features

✅ **Safe Installation** - Checks for existing content before creating  
✅ **Comprehensive Logging** - Detailed installation logs for debugging  
✅ **Error Handling** - Graceful failure handling with user notifications  
✅ **Data Preservation** - Uninstall preserves data to prevent accidental loss  
✅ **Dependency Management** - All required field modules automatically included  
✅ **Administrative Interface** - Complete admin views for all content types  

### Requirements

- Drupal 11.x
- PHP 8.1+
- MySQL/MariaDB database

### Installation

1. Enable the module via Drupal admin interface or drush:
   ```bash
   drush en job_hunter
   ```

2. Visit the administrative views to begin configuration:
   - Add companies: `/admin/content/job-application/companies`
   - Configure user profiles: `/admin/people`
   - Monitor system: `/admin/content/job-application/errors`

### Architecture Compliance

This installation system fully implements the specifications defined in `ARCHITECTURE.md`:

- ✅ All 4 required content types created
- ✅ 71 custom fields (exceeds 50+ requirement)  
- ✅ User entity extended with 25 fields (exceeds 24+ requirement)
- ✅ Administrative views for all content management
- ✅ Proper field validation and configuration
- ✅ Complete error handling and logging
- ✅ Safe uninstall process

### Development Status

The foundation installation system is **COMPLETE** and ready for production use. All major installation milestones have been achieved, providing a solid foundation for the job application automation features to be built upon.