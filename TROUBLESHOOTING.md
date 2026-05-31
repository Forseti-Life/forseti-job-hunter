# Job Application Automation Module - Troubleshooting Guide

Last Updated: April 21, 2026

## Quick Diagnostics

If Job Hunter isn't working as expected, use this checklist to identify the problem:

### Pre-Installation Checks

- [ ] Drupal 10 or 11 installed and running
- [ ] PHP 8.1+ available (`php -v`)
- [ ] MySQL 8.0+ or PostgreSQL 13+ running
- [ ] Module uploaded to `web/modules/custom/job_hunter/`
- [ ] ai_conversation module enabled (dependency)
- [ ] AWS Bedrock credentials configured (if using AI features)

### Installation Issues

**Module doesn't enable in Extend**
- Clear cache: `drush cache:rebuild`
- Check PHP syntax: `php -l src/`
- Verify dependencies: `drush pm:list | grep job_hunter`
- Check logs: `tail -100 /var/log/drupal/drupal.log`

**Database tables not created**
- Run install hooks: `drush updatedb -y`
- Check table creation: `drush sqlq "SHOW TABLES LIKE 'jobhunter%';"`
- Re-install module: Uninstall, then enable again

**"ai_conversation module not found" error**
- Verify ai_conversation is enabled: `drush pm:list | grep ai_conversation`
- If not enabled: `drush pm:enable ai_conversation`
- Check dependencies in job_hunter.info.yml

---

## Configuration Issues

### Google Cloud Talent Solution API

**"Google Cloud credentials not configured" error**
1. Navigate to `/admin/config/job_hunter/settings`
2. Paste full Google Cloud service account JSON (includes project_id)
3. Set Tenant Name: `projects/YOUR_PROJECT_ID/tenants/YOUR_TENANT_ID`
4. Save form

**How to find your Tenant ID:**
```
GET https://jobs.googleapis.com/v4/projects/YOUR_PROJECT_ID/tenants

# Response includes "name" field with full tenant path
"name": "projects/YOUR_PROJECT_ID/tenants/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
```

**"Invalid JSON in credentials field" error**
- Paste raw JSON from Google Cloud Console
- Verify it includes: `type`, `project_id`, `private_key`, `client_email`
- Check for extra whitespace or formatting

### External Job APIs

**SerpAPI Configuration**
- Get free API key: https://serpapi.com/users/sign_up
- 100 free searches/month included
- Add key at `/admin/config/job_hunter/settings`

**Google Cloud Credentials (for Google Jobs)**
- Requires Google Cloud project with Cloud Talent Solution API enabled
- Service account with proper IAM roles
- JSON key file from Google Cloud Console

**Adzuna API**
- Requires App ID + Key from https://developer.adzuna.com/
- Add both at `/admin/config/job_hunter/settings`

**USAJobs API**
- Get free API key at https://developer.usajobs.gov/
- Requires valid email address
- Configure at `/admin/config/job_hunter/settings`

---

## Job Search Issues

### No results when searching

**Check external API configuration:**
1. Navigate to `/admin/config/job_hunter/settings`
2. Verify SerpAPI key is set
3. Test with simple keyword: "engineer" or "developer"
4. Check system logs for API errors

**Check database connectivity:**
```
drush sqlq "SELECT COUNT(*) FROM jobhunter_job_requirements;"
```

**Verify permissions:**
- User must have "Access Job Discovery Search" permission
- Check user role at `/admin/people`

### Rate limiting / API quota exceeded

**Symptoms:** Searches return empty results after many attempts

**Solutions:**
1. Check API quota at respective provider
2. Wait for quota reset (typically 24 hours)
3. Consider upgrading to paid API plan
4. Reduce search frequency via cron settings

---

## Resume & Profile Issues

### "Resume file not found" error

**Check file storage:**
```
drush sqlq "SELECT * FROM jobhunter_job_seeker_resumes WHERE uid = 1 LIMIT 5;"
```

**Verify file path:**
1. Check private file system configured: `/admin/config/media/file-system`
2. Ensure `private://` directory exists and is writable
3. Check file permissions on server

### Profile completeness stuck at 0%

**Cause:** Required fields not filled out

**Solution:**
1. Navigate to `/user/profile`
2. Fill required fields (marked with red asterisk)
3. Save form
4. Completeness should recalculate

**Manual recalculation:**
```
drush eval "
\$uid = 1; // Change to target user
\$service = \Drupal::service('job_hunter.profile_completeness_service');
\$service->recalculateUserProfileCompleteness(\$uid);
drupal_set_message('Profile completeness updated for user ' . \$uid);
"
```

### Resume parsing fails

**Check AI service credentials:**
1. Verify AWS Bedrock access key configured
2. Confirm Claude 3.5 Sonnet model available in region
3. Check CloudWatch logs for Bedrock errors

**Check resume file format:**
- Only .docx (Word 2007+) supported
- PDF conversion not available in current version
- File must be valid, not corrupted

**Increase token limits:**
If parsing large resumes:
1. Go to `/admin/config/job_hunter/settings`
2. Increase "Max Tokens (Resume Tailoring)" value
3. Save form

---

## Application Submission Issues

### "Application submission failed" errors

**Check ATS platform credentials:**
1. Verify username/password correct
2. Check if account locked (too many login attempts)
3. Confirm account still has access (may have been revoked)

**Check job posting:**
- Verify job still active on employer website
- Check if URL is correct and accessible
- Confirm application form structure unchanged

**Enable debug logging:**
```
\$config = \Drupal::configFactory()->getEditable('job_hunter.settings');
\$config->set('enable_debug_logging', TRUE)->save();
```

Then check logs at `/admin/reports/dblog`

### Workday automation issues

**"Workday login failed" error**
- Verify username/email exactly matches Workday account
- Confirm password correct (check caps lock)
- Try manual login to workday.com first
- Check if 2FA enabled (auto-submission won't work)

**"Could not find application form" error**
- Workday site layout may have changed
- Check Application -> Workday Wizard debug output
- Report issue with job posting URL for investigation

---

## Performance & Timeout Issues

### Jobs load slowly on `/jobhunter/my-jobs`

**Check database indexes:**
```
drush sqlq "SHOW INDEXES FROM jobhunter_job_requirements;"
```

**Optimize queries:**
1. Remove unused API configurations
2. Reduce stored job history (archive old entries)
3. Increase PHP memory limit if available

**Monitor slow queries:**
Enable MySQL slow query log, then analyze with `pt-query-digest`

### Search takes too long

**Common causes:**
- Multiple API calls happening sequentially
- Database query returning too many rows
- AI processing (resume tailoring) happening during search

**Solutions:**
1. Use AJAX to make search non-blocking
2. Enable caching: `drush cache:rebuild`
3. Run tailoring as background job (disable immediate processing)

### "Maximum execution time exceeded" error

**Increase PHP timeout:**
In `php.ini` or `.htaccess`:
```ini
max_execution_time = 300  ; 5 minutes for long operations
```

**For long-running operations:**
- Use Drupal queue system
- Run via drush in background
- Check queue with: `drush queue:list`

---

## Getting Help

### Enabling Debug Mode

**Increase logging verbosity:**
```
drush eval "
\$config = \Drupal::configFactory()->getEditable('job_hunter.settings');
\$config->set('debug_mode', TRUE)->save();
"
```

**View recent logs:**
- `/admin/reports/dblog` (Drupal)
- `drush watchdog:show --tail=50` (CLI)
- `/var/log/drupal/drupal.log` (server)

### Creating a Bug Report

Include:
1. **Module version:** From job_hunter.info.yml
2. **Drupal version:** `drush core:status`
3. **PHP version:** `php -v`
4. **Steps to reproduce**
5. **Expected vs actual behavior**
6. **Relevant log entries** (from `/admin/reports/dblog`)
7. **Configuration** (mask API keys)

### Common Questions (FAQ)

**Q: Can I use Job Hunter on multiple Drupal sites?**
A: Yes, but credentials are per-site. Configure separately on each site.

**Q: Can I export my job application history?**
A: Use Views to export (CSV, JSON) from `/admin/content/applications`.

**Q: How do I backup application data?**
A: Use standard Drupal database backup:
```bash
drush sql:dump > backup.sql
```

**Q: Can I import jobs from a CSV file?**
A: Not yet. Use the web UI or Drupal's migration system.

**Q: How often does job search run via cron?**
A: Configured at `/admin/config/job_hunter/settings` (default: hourly).

---

## System Requirements Reference

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| Drupal | 10.0 | 11.x |
| PHP | 8.1 | 8.3+ |
| MySQL | 8.0 | 8.0.23+ |
| PostgreSQL | 13 | 15+ |
| Memory | 2 GB | 4 GB+ |
| Storage | 1 GB (code) | 10+ GB (jobs/resumes) |

---

## Support

- **Documentation:** See ARCHITECTURE.md for technical details
- **Issues:** Report bugs on GitHub repository
- **Security:** See SECURITY.md for vulnerability reporting
- **Contributing:** See CONTRIBUTING.md for code contributions
