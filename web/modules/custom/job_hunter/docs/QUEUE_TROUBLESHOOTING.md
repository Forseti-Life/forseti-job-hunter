# Queue Worker Troubleshooting Guide

**Last Updated:** February 13, 2026

## Overview

The Job Hunter module uses Drupal's Queue API for background processing of resource-intensive tasks. This guide helps diagnose and resolve common queue worker issues.

## Queue Workers

The module uses the following queue workers:

| Queue Name | Purpose | Processing Time | Dependencies |
|------------|---------|-----------------|--------------|
| `job_hunter_resume_text_extraction` | Extract text from uploaded PDF/DOC resumes | 5-30 seconds | PhpOffice/PhpWord |
| `job_hunter_genai_parsing` | AI parsing of resume content | 15-45 seconds | AWS Bedrock |
| `job_hunter_resume_tailoring` | Generate tailored resumes for jobs | 30-60 seconds | AWS Bedrock |
| `job_hunter_job_posting_parsing` | Extract job requirements via AI | 20-45 seconds | AWS Bedrock |
| `job_hunter_cover_letter_tailoring` | Generate tailored cover letters | 25-50 seconds | AWS Bedrock |
| `job_hunter_profile_text_extraction` | Extract profile data | 5-20 seconds | Various |

## Common Issues and Solutions

### 1. Queue Items Stuck in "Queued" Status

**Symptoms:**
- Items remain in queue for extended periods
- Status shows "queued" but never "processing"
- No error messages in logs

**Causes:**
- Cron not running
- Queue worker not being processed
- Time limit too short

**Solutions:**

```bash
# Check if cron is configured
drush core:cron

# Check queue status
drush queue:list

# Manually process specific queue
drush queue:run job_hunter_resume_tailoring --time-limit=240

# Process all queues
drush queue:run job_hunter_genai_parsing --time-limit=240
drush queue:run job_hunter_job_posting_parsing --time-limit=240
drush queue:run job_hunter_resume_tailoring --time-limit=240
```

**Configure Cron:**
Add to your crontab:
```bash
# Process queues every 5 minutes
*/5 * * * * cd /var/www/html/forseti && vendor/bin/drush queue:run job_hunter_job_posting_parsing --time-limit=240 2>&1 | logger -t job_hunter_queue
*/5 * * * * cd /var/www/html/forseti && flock -n /tmp/jh_tailoring.lock vendor/bin/drush queue:run job_hunter_resume_tailoring --time-limit=240 >> /var/log/drupal/tailoring_queue.log 2>&1
```

---

### 2. Items Moving to Suspended Queue

**Symptoms:**
- Items disappear from active queue
- Found in `jobhunter_queue_suspended` table
- Error messages in watchdog

**Causes:**
- API failures (AWS Bedrock timeouts)
- Invalid data
- Configuration issues

**Solutions:**

1. **Check Suspended Items:**
   ```bash
   # Via Drush
   drush sql:query "SELECT * FROM jobhunter_queue_suspended ORDER BY created DESC LIMIT 10;"
   ```

2. **View in Admin UI:**
   - Navigate to `/jobhunter/queue-management`
   - Click "Suspended Items" tab
   - Review error messages

3. **Retry Suspended Items:**
   - In UI: Click "Retry" button for individual items
   - In UI: Use "Retry All" for bulk retry
   - Via code: Items are automatically retried up to 3 times

4. **Delete Invalid Items:**
   ```bash
   # Delete items older than 7 days
   drush sql:query "DELETE FROM jobhunter_queue_suspended WHERE created < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY));"
   ```

---

### 3. AWS Bedrock Timeout Errors

**Symptoms:**
- Error: "Bedrock API timeout"
- Items suspended after 30-60 seconds
- Inconsistent success rate

**Causes:**
- Network latency
- AWS throttling
- Large content payloads
- Region issues

**Solutions:**

1. **Check AWS Credentials:**
   ```bash
   # Test AWS connection
   aws bedrock list-foundation-models --region us-east-1
   ```

2. **Verify Region Configuration:**
   - Navigate to `/admin/config/job_hunter/settings`
   - Ensure AWS region matches your credentials
   - Try different regions if persistent issues

3. **Increase Timeout:**
   Edit `ResumeTailoringService.php` (or relevant service):
   ```php
   // Increase timeout to 120 seconds
   $client = new BedrockRuntimeClient([
     'region' => $region,
     'version' => 'latest',
     'http' => [
       'timeout' => 120,
       'connect_timeout' => 30,
     ],
   ]);
   ```

4. **Request Quota Increase:**
   - Visit AWS Service Quotas console
   - Request higher rate limits for Bedrock
   - Typical limits: 10-20 requests per second

---

### 4. Memory Exhaustion

**Symptoms:**
- Error: "Allowed memory size exhausted"
- Queue processing stops mid-execution
- Server becomes unresponsive

**Causes:**
- Large PDF files
- Insufficient PHP memory limit
- Memory leaks in processing

**Solutions:**

1. **Increase PHP Memory Limit:**
   ```bash
   # In php.ini
   memory_limit = 512M
   
   # Or in Drush command
   php -d memory_limit=512M vendor/bin/drush queue:run job_hunter_resume_tailoring
   ```

2. **Process Smaller Batches:**
   ```bash
   # Limit number of items processed
   drush queue:run job_hunter_resume_tailoring --time-limit=60
   ```

3. **Optimize File Sizes:**
   - Compress PDF files before upload
   - Limit resume uploads to 5MB
   - Use efficient parsing libraries

---

### 5. Concurrent Processing Issues

**Symptoms:**
- Duplicate processing of same item
- Race conditions
- Lock timeouts

**Causes:**
- Multiple cron jobs running simultaneously
- No locking mechanism
- Slow processing causing overlap

**Solutions:**

1. **Use File Locking:**
   ```bash
   # Prevent concurrent processing
   */5 * * * * cd /var/www/html/forseti && flock -n /tmp/jh_tailoring.lock vendor/bin/drush queue:run job_hunter_resume_tailoring --time-limit=240
   ```

2. **Adjust Cron Frequency:**
   - If processing takes 4 minutes, run cron every 5+ minutes
   - Monitor execution time: `time drush queue:run job_hunter_resume_tailoring`

3. **Check for Multiple Cron Sources:**
   ```bash
   # Check system crontab
   crontab -l
   
   # Check Drupal automated cron
   drush config:get automated_cron.settings interval
   ```

---

### 6. Zero Items Processed

**Symptoms:**
- Queue shows items but none are processed
- No errors in logs
- Cron runs but skips queue

**Causes:**
- Queue worker plugin not found
- Module not enabled properly
- Cache issues

**Solutions:**

1. **Clear Caches:**
   ```bash
   drush cache:rebuild
   ```

2. **Verify Module Status:**
   ```bash
   drush pm:list | grep job_hunter
   ```

3. **Check Queue Worker Plugins:**
   ```bash
   drush plugin:list queue_worker
   ```

4. **Reinstall Module (last resort):**
   ```bash
   # Export configuration first
   drush config:export
   
   # Reinstall
   drush pm:uninstall job_hunter
   drush pm:install job_hunter
   
   # Import configuration
   drush config:import
   ```

---

## Monitoring Queue Health

### Check Queue Status

```bash
# View all queues
drush queue:list

# View specific queue
drush queue:list | grep job_hunter
```

**Expected Output:**
```
job_hunter_resume_tailoring: 0 items
job_hunter_job_posting_parsing: 0 items
job_hunter_genai_parsing: 0 items
```

### Monitor Suspended Items

```bash
# Count suspended items
drush sql:query "SELECT COUNT(*) as count, queue_name FROM jobhunter_queue_suspended GROUP BY queue_name;"
```

### Check Processing Logs

```bash
# View recent watchdog entries
drush watchdog:show --severity=Error --filter=job_hunter

# View queue processing logs (if configured)
tail -f /var/log/drupal/tailoring_queue.log
```

### Performance Metrics

Navigate to `/jobhunter/queue-management` to view:
- Items in queue by worker
- Suspended items count
- Processing success rate
- Average processing time

---

## Debug Mode

Enable verbose logging for troubleshooting:

1. Navigate to `/admin/config/job_hunter/settings`
2. Check "Enable Debug Mode"
3. Save configuration
4. Process queue items
5. Check `/admin/reports/dblog` for detailed logs

**Remember to disable debug mode in production to avoid log bloat.**

---

## Performance Optimization

### Best Practices

1. **Batch Processing:**
   - Process 10-20 items per cron run
   - Adjust `--time-limit` based on item complexity

2. **Stagger Queue Processing:**
   ```bash
   # Different queues at different times
   */5 * * * * drush queue:run job_hunter_job_posting_parsing --time-limit=180
   */7 * * * * drush queue:run job_hunter_resume_tailoring --time-limit=240
   ```

3. **Monitor API Costs:**
   - Track AWS Bedrock usage
   - Set up billing alerts
   - Implement rate limiting if needed

4. **Cache Results:**
   - Cache parsed resume data
   - Avoid re-processing unchanged content
   - Use Drupal's cache API

### Scaling Strategies

**For High Volume:**
1. Use separate queue processing server
2. Implement Redis for queue storage
3. Use AWS Lambda for AI processing
4. Add queue worker monitoring (e.g., Nagios, Datadog)

---

## Emergency Procedures

### Clear All Queues (Use with Caution)

```bash
# Delete all items from specific queue
drush queue:delete job_hunter_resume_tailoring

# Or via SQL
drush sql:query "DELETE FROM queue WHERE name='job_hunter_resume_tailoring';"
```

### Pause All Processing

```bash
# Remove cron entries temporarily
crontab -e
# Comment out job_hunter cron lines
```

### Reset Suspended Items

```bash
# Move suspended items back to active queue
drush sql:query "UPDATE jobhunter_queue_suspended SET status='retry_pending' WHERE retry_count < 3;"
```

---

## Getting Help

If issues persist after following this guide:

1. **Check Logs:**
   - Drupal watchdog: `/admin/reports/dblog`
   - PHP error log: `/var/log/php/error.log`
   - Apache/Nginx logs: `/var/log/apache2/error.log`

2. **Report Issue:**
   - GitHub: [https://github.com/keithaumiller/forseti.life/issues](https://github.com/keithaumiller/forseti.life/issues)
   - Include: Queue name, error message, watchdog logs, PHP version, Drupal version

3. **Community Support:**
   - Drupal Slack: #queue-api channel
   - Drupal forums: Queue API topics

---

**Last Updated:** February 13, 2026
