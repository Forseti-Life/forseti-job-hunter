# Permissions Matrix

**Last Updated:** February 13, 2026

## Overview

This document provides a comprehensive overview of the Job Hunter module's permission system, organized by role and functionality.

## Quick Reference: Recommended Role Configuration

### Job Seeker (Authenticated User)
Basic job hunting capabilities for end users.

**Required Permissions:**
- ✅ `access job hunter` - Basic module access
- ✅ `access job discovery` - Search for jobs
- ✅ `access user job applications` - Manage own applications
- ✅ `view own job application profile` - View own profile
- ✅ `edit own job application profile` - Edit own profile
- ✅ `tailor resume` - Use AI resume tailoring
- ✅ `create application content` - Create job applications
- ✅ `edit own application content` - Edit own applications
- ✅ `view application content` - View applications
- ✅ `view job_posting content` - Browse job postings
- ✅ `view company content` - Browse companies
- ✅ `create tailored_resume content` - Generate tailored resumes
- ✅ `edit own tailored_resume content` - Edit own resumes
- ✅ `view tailored_resume content` - View tailored resumes

**Typical User Journey:**
1. Browse job postings → `view job_posting content`
2. Search for jobs → `access job discovery`
3. View company profiles → `view company content`
4. Tailor resume to job → `tailor resume`
5. Create application → `create application content`
6. Track applications → `view own job application profile`

---

### Job Hunter Manager
Manage job postings and companies without full admin access.

**Includes Job Seeker permissions, plus:**
- ✅ `create company content` - Add new companies
- ✅ `edit any company content` - Manage all companies
- ✅ `create job_posting content` - Add job postings
- ✅ `edit any job_posting content` - Manage all postings
- ✅ `manage job scraping` - Configure job scrapers
- ✅ `access companies overview` - Company management dashboard
- ✅ `view job application reports` - Analytics access

**Typical Manager Tasks:**
1. Add target companies → `create company content`
2. Import job postings → `create job_posting content`
3. Configure scraping → `manage job scraping`
4. View analytics → `view job application reports`

---

### Administrator
Full system access and configuration.

**Includes Manager permissions, plus:**
- ✅ `administer job application automation` - Full admin access
- ✅ `manage job application workflow` - Workflow management
- ✅ `manage error queue` - Error resolution
- ✅ `view any job application profile` - View all profiles
- ✅ `edit any job application profile` - Edit any profile
- ✅ `manage user profiles` - Profile system administration
- ✅ `edit any application content` - Manage all applications
- ✅ `delete any application content` - Delete applications
- ✅ `delete any company content` - Remove companies
- ✅ `delete any job_posting content` - Remove postings
- ✅ `edit any tailored_resume content` - Manage all resumes

**Typical Admin Tasks:**
1. Configure module settings → `administer job application automation`
2. Resolve queue issues → `manage error queue`
3. Review user profiles → `view any job application profile`
4. System maintenance → All delete permissions

---

## Detailed Permission Reference

### Core Module Permissions

| Permission | Description | Roles | Routes/Features |
|------------|-------------|-------|-----------------|
| `access job hunter` | Basic module access | Job Seeker, Manager, Admin | `/jobhunter/*` routes |
| `administer job application automation` | Full admin access | Admin only | `/jobhunter/settings`, configuration |

### Job Discovery & Search

| Permission | Description | Roles | Routes/Features |
|------------|-------------|-------|-----------------|
| `access job discovery` | Search for jobs | Job Seeker, Manager, Admin | `/jobhunter/job-discovery` |
| `access companies overview` | Company dashboard | Manager, Admin | `/jobhunter/companies` |
| `manage job scraping` | Configure scrapers | Manager, Admin | Company scraping configuration |

### User Profile Management

| Permission | Description | Roles | Routes/Features |
|------------|-------------|-------|-----------------|
| `view own job application profile` | View own profile | Job Seeker, Manager, Admin | `/jobhunter/my-profile` |
| `edit own job application profile` | Edit own profile | Job Seeker, Manager, Admin | `/jobhunter/my-profile/edit` |
| `view any job application profile` | View all profiles | Admin only | `/jobhunter/profiles/{uid}` |
| `edit any job application profile` | Edit any profile | Admin only | Administrative profile management |
| `manage user profiles` | Profile admin | Admin only | Profile system configuration |

### Resume Management

| Permission | Description | Roles | Routes/Features |
|------------|-------------|-------|-----------------|
| `tailor resume` | AI resume tailoring | Job Seeker, Manager, Admin | `/jobhunter/tailor-resume/{job}` |
| `create tailored_resume content` | Create resumes | Job Seeker, Manager, Admin | Resume generation |
| `edit own tailored_resume content` | Edit own resumes | Job Seeker, Manager, Admin | Resume editing |
| `edit any tailored_resume content` | Edit all resumes | Admin only | Administrative resume management |
| `view tailored_resume content` | View resumes | Job Seeker, Manager, Admin | Resume viewing |

### Company Content Type

| Permission | Description | Roles | Routes/Features |
|------------|-------------|-------|-----------------|
| `create company content` | Create companies | Manager, Admin | `/node/add/company` |
| `edit own company content` | Edit own companies | Manager, Admin | `/node/{nid}/edit` |
| `edit any company content` | Edit all companies | Manager, Admin | Administrative company management |
| `delete own company content` | Delete own | Manager, Admin | `/node/{nid}/delete` |
| `delete any company content` | Delete any | Admin only | Bulk company deletion |
| `view company content` | View companies | All authenticated | `/node/{nid}`, company listings |

### Job Posting Content Type

| Permission | Description | Roles | Routes/Features |
|------------|-------------|-------|-----------------|
| `create job_posting content` | Create job postings | Manager, Admin | `/node/add/job_posting` |
| `edit own job_posting content` | Edit own postings | Manager, Admin | `/node/{nid}/edit` |
| `edit any job_posting content` | Edit all postings | Manager, Admin | Administrative job management |
| `delete own job_posting content` | Delete own | Manager, Admin | `/node/{nid}/delete` |
| `delete any job_posting content` | Delete any | Admin only | Bulk job deletion |
| `view job_posting content` | View postings | All authenticated | `/node/{nid}`, job listings |

### Application Content Type

| Permission | Description | Roles | Routes/Features |
|------------|-------------|-------|-----------------|
| `create application content` | Create applications | Job Seeker, Manager, Admin | `/node/add/application` |
| `edit own application content` | Edit own | Job Seeker, Manager, Admin | `/node/{nid}/edit` |
| `edit any application content` | Edit all | Admin only | Administrative application management |
| `delete own application content` | Delete own | Job Seeker, Manager, Admin | `/node/{nid}/delete` |
| `delete any application content` | Delete any | Admin only | Bulk application deletion |
| `view application content` | View applications | All authenticated | `/node/{nid}`, application tracking |
| `access user job applications` | Manage own applications | Job Seeker, Manager, Admin | `/jobhunter/my-applications` |

### Issue/Error Queue Content Type

| Permission | Description | Roles | Routes/Features |
|------------|-------------|-------|-----------------|
| `create issue content` | Create issues | Manager, Admin | `/node/add/issue` |
| `edit own issue content` | Edit own issues | Manager, Admin | `/node/{nid}/edit` |
| `edit any issue content` | Edit all issues | Admin only | Issue management |
| `delete own issue content` | Delete own | Manager, Admin | `/node/{nid}/delete` |
| `delete any issue content` | Delete any | Admin only | Issue cleanup |
| `view issue content` | View issues | Manager, Admin | `/node/{nid}`, issue tracking |
| `manage error queue` | Error resolution | Admin only | `/jobhunter/queue-management` |

### Workflow & Reports

| Permission | Description | Roles | Routes/Features |
|------------|-------------|-------|-----------------|
| `manage job application workflow` | Workflow states | Admin only | Application state transitions |
| `view job application reports` | Analytics | Manager, Admin | `/jobhunter/reports`, `/jobhunter/analytics` |

---

## Permission Dependencies

Some features require multiple permissions to function properly:

### Resume Tailoring Workflow
**Required for full functionality:**
1. `access job hunter` - Basic access
2. `tailor resume` - Trigger tailoring
3. `create tailored_resume content` - Generate resume
4. `view tailored_resume content` - View result
5. `view job_posting content` - See job details

### Job Discovery Workflow
**Required for full functionality:**
1. `access job hunter` - Basic access
2. `access job discovery` - Search interface
3. `view job_posting content` - View results
4. `view company content` - View company details
5. `create application content` - Apply to jobs

### Company Management Workflow
**Required for full functionality:**
1. `access job hunter` - Basic access
2. `access companies overview` - Dashboard access
3. `create company content` - Add companies
4. `edit any company content` - Manage companies
5. `manage job scraping` - Configure scrapers

---

## Security Considerations

### User Data Protection
- ✅ Users can only view/edit their own applications by default
- ✅ Profile data restricted to owner unless admin
- ✅ Resume data accessible only to owner/admin
- ✅ Application status visible only to owner/admin

### Administrative Oversight
- ✅ Admins can view all user data for support purposes
- ✅ Error queue access restricted to admins
- ✅ System configuration requires admin permission
- ✅ Bulk operations require admin permission

### API Access
- ✅ API credentials visible only to admins
- ✅ AI features require authenticated user
- ✅ Job discovery requires module access permission
- ✅ External API calls logged for audit

---

## Granting Permissions

### Via Drupal UI
1. Navigate to `/admin/people/permissions`
2. Scroll to "Job Application Automation" section
3. Check boxes for desired role/permission combinations
4. Save permissions

### Via Drush
```bash
# Grant permission to role
drush role:perm:add "authenticated" "access job hunter"
drush role:perm:add "manager" "manage job scraping"

# List all job_hunter permissions
drush role:perm:list | grep "job hunter\|job application\|tailor"

# Export configuration
drush config:export
```

### Via Code (for custom modules)
```php
<?php
use Drupal\user\Entity\Role;

// Grant permission programmatically
$role = Role::load('authenticated');
$role->grantPermission('access job hunter');
$role->save();
```

---

## Troubleshooting Permission Issues

### User Can't Access Feature

**Check:**
1. User has `access job hunter` permission
2. User has specific feature permission (e.g., `access job discovery`)
3. User is authenticated
4. Cache has been cleared
5. Route requirements are met

**Debug:**
```bash
# Check user's permissions
drush user:role:list username@example.com

# Check specific permission
drush sql:query "SELECT * FROM role_permission WHERE permission LIKE '%job hunter%';"

# Clear cache
drush cache:rebuild
```

### Permission Not Appearing

**Solutions:**
1. Clear cache: `drush cache:rebuild`
2. Rebuild permissions: `drush php:eval "node_access_rebuild();"`
3. Verify module is enabled: `drush pm:list | grep job_hunter`
4. Check permissions.yml file exists and is valid

### "Access Denied" Errors

**Common Causes:**
1. Missing base permission: `access job hunter`
2. Missing content type permission: `view job_posting content`
3. Route requires admin: Check routing.yml `_permission` requirements
4. Custom access controller denying access

**Fix:**
1. Review route requirements in `job_hunter.routing.yml`
2. Check custom access controllers in `src/Controller/`
3. Verify user has all required permissions
4. Check for custom access checks in code

---

## Best Practices

### 1. Principle of Least Privilege
- Grant only necessary permissions
- Use specific permissions rather than admin for managers
- Regularly audit permission assignments

### 2. Role Hierarchy
- Base role: Authenticated user (basic access)
- Mid-level: Manager (content management)
- Top-level: Administrator (system configuration)

### 3. Testing Permissions
- Test with actual user accounts, not admin
- Use "Masquerade" module to test as different roles
- Document required permissions for each feature

### 4. Custom Roles
Consider creating custom roles for specific use cases:
- **Job Coordinator**: Company and job management, no admin
- **Career Counselor**: View profiles and reports, no editing
- **Job Seeker (Free)**: Basic access with limitations
- **Job Seeker (Premium)**: Enhanced features, higher limits

---

## Related Documentation

- [Installation Guide](../INSTALL.md) - Initial permission configuration
- [Architecture Documentation](ARCHITECTURE.md) - Security architecture
- [FAQ](FAQ.md) - Permission-related questions

---

**Last Updated:** February 13, 2026
