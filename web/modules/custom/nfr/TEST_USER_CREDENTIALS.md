# NFR Test User Credentials

**Created:** January 25, 2026  
**Environment:** Development/Testing  
**Password (all users):** Test123!

---

## Test Users by Role

### 1. Firefighter (Active) - Participant User
**Username:** `firefighter_active`  
**Email:** firefighter.active@test.com  
**Password:** Test123!  
**User ID:** 2  
**Roles:** Authenticated, Firefighter  

**Permissions:**
- Access NFR dashboard
- Complete enrollment (consent, profile, questionnaire)
- View own data
- Update profile
- Report cancer diagnoses

**Test Scenarios:**
- Complete full enrollment process
- Access participant dashboard
- Update profile information
- Navigate welcome page and enrollment steps

---

### 2. Firefighter (Retired) - Participant User
**Username:** `firefighter_retired`  
**Email:** firefighter.retired@test.com  
**Password:** Test123!  
**User ID:** 3  
**Roles:** Authenticated, Firefighter  

**Permissions:**
- Access NFR dashboard
- Complete enrollment (consent, profile, questionnaire)
- View own data
- Update profile
- Report cancer diagnoses

**Test Scenarios:**
- Complete enrollment as retired firefighter
- Test historical work history entry
- Verify dashboard access for retired participants

---

### 3. NFR Administrator - NIOSH Staff
**Username:** `nfr_admin`  
**Email:** nfr.admin@test.com  
**Password:** Test123!  
**User ID:** 4  
**Roles:** Authenticated, NFR Administrator  

**Permissions:**
- ✅ Administer NFR
- ✅ Access NFR dashboard
- ✅ Manage firefighters
- ✅ View firefighter records
- ✅ View cancer data
- ✅ Manage cancer data
- ✅ Export NFR data
- ✅ Manage state registry linkages

**Test Scenarios:**
- Access admin dashboard at `/admin/nfr`
- View participant list
- Access participant detail pages
- Test linkage management workflow
- Verify all admin permissions

---

### 4. NFR Researcher - Data Analyst
**Username:** `nfr_researcher`  
**Email:** nfr.researcher@test.com  
**Password:** Test123!  
**User ID:** 5  
**Roles:** Authenticated, NFR Researcher  

**Permissions:**
- ✅ Access NFR dashboard
- ✅ View firefighter records (de-identified)
- ✅ View cancer data
- ✅ Export NFR data

**Test Scenarios:**
- Access dashboard
- View aggregated statistics
- Test data export functionality
- Verify no administrative access

---

### 5. Fire Department Administrator
**Username:** `dept_admin`  
**Email:** dept.admin@test.com  
**Password:** Test123!  
**User ID:** 6  
**Roles:** Authenticated, Fire Department Administrator  

**Permissions:**
- ✅ Access NFR dashboard
- ✅ View firefighter records (department-level)

**Test Scenarios:**
- View department participants
- Access aggregated department statistics
- Verify limited access (no data export or admin functions)

---

## Quick Login URLs

### Development Environment
- **Login:** http://localhost/user/login
- **NFR Welcome:** http://localhost/nfr/welcome
- **Admin Dashboard:** http://localhost/admin/nfr
- **Participant Dashboard:** http://localhost/nfr/my-dashboard

### Testing Workflow

#### For Firefighter Users (firefighter_active, firefighter_retired)
1. Login at `/user/login`
2. Redirected to `/nfr/welcome`
3. Complete enrollment steps:
   - Consent form: `/nfr/consent`
   - User profile: `/nfr/profile`
   - Questionnaire: `/nfr/questionnaire`
   - Review: `/nfr/review`
4. View confirmation: `/nfr/confirmation`
5. Access dashboard: `/nfr/my-dashboard`

#### For NFR Administrator (nfr_admin)
1. Login at `/user/login`
2. Navigate to `/admin/nfr`
3. Test admin features:
   - View metrics and statistics
   - Access participant list at `/admin/nfr/participants`
   - View participant details at `/admin/nfr/participant/{id}`
   - Manage linkage at `/admin/nfr/linkage`

#### For NFR Researcher (nfr_researcher)
1. Login at `/user/login`
2. Navigate to dashboard
3. Access data views (read-only)
4. Test export functionality

#### For Fire Department Admin (dept_admin)
1. Login at `/user/login`
2. Access department-level dashboard
3. View department participants
4. Access aggregated statistics

---

## Role Permission Matrix

| Permission | Firefighter | NFR Admin | Researcher | Dept Admin |
|-----------|------------|-----------|------------|------------|
| Access NFR Dashboard | ✅ | ✅ | ✅ | ✅ |
| Administer NFR | ❌ | ✅ | ❌ | ❌ |
| Manage Firefighters | ❌ | ✅ | ❌ | ❌ |
| View Firefighter Records | Own only | ✅ All | ✅ De-ID | ✅ Dept |
| View Cancer Data | Own only | ✅ | ✅ | ❌ |
| Manage Cancer Data | ❌ | ✅ | ❌ | ❌ |
| Export NFR Data | ❌ | ✅ | ✅ | ❌ |
| Manage State Registry Linkages | ❌ | ✅ | ❌ | ❌ |

---

## Password Reset

If you need to reset passwords for test users:

```bash
# Reset specific user password
drush user:password firefighter_active "NewPassword123!"

# Or reset all test users to same password
drush user:password firefighter_active "Test123!"
drush user:password firefighter_retired "Test123!"
drush user:password nfr_admin "Test123!"
drush user:password nfr_researcher "Test123!"
drush user:password dept_admin "Test123!"
```

---

## Delete Test Users

To remove test users (for cleanup):

```bash
drush user:cancel firefighter_active
drush user:cancel firefighter_retired
drush user:cancel nfr_admin
drush user:cancel nfr_researcher
drush user:cancel dept_admin
```

---

## Security Notes

⚠️ **Important:**
- These are **DEVELOPMENT/TESTING credentials only**
- **NEVER** use these credentials in production
- **NEVER** commit real user data with these accounts
- Change all passwords before any production deployment
- Disable or delete these accounts before going live

---

## Additional Test Scenarios

### Complete Enrollment Test
1. Login as `firefighter_active`
2. Complete all enrollment steps
3. Verify data appears in admin dashboard
4. Login as `nfr_admin` and verify participant appears

### Multi-User Test
1. Create enrollments for both firefighter accounts
2. Login as `nfr_admin`
3. Verify both participants appear in list
4. Test filtering and search

### Permission Boundary Test
1. Login as `nfr_researcher`
2. Verify admin routes return 403 Forbidden
3. Verify export functionality works
4. Confirm no edit access

### Department Admin Test
1. Login as `dept_admin`
2. Verify limited view access
3. Confirm no export or admin access
4. Test department-level filters

---

## Support

For issues with test accounts:
- Check role assignments: `drush user:information [username]`
- Verify permissions: `drush role:list`
- Clear cache: `drush cr`
- Check logs: `drush watchdog:show`

---

**Last Updated:** January 25, 2026  
**Maintainer:** NFR Development Team
