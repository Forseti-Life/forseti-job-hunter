# NFR Module - Installation & Deployment Guide

## Prerequisites

- Drupal 9, 10, or 11
- PHP 8.1 or higher
- MySQL 5.7+ or MariaDB 10.3+
- Drush 11 or higher
- Composer

## Installation Steps

### 1. Module Installation

The module is already located at:
```
sites/forseti/web/modules/custom/nfr/
```

### 2. Enable the Module

```bash
cd sites/forseti/web
drush en nfr -y
drush cr
```

### 3. Verify Installation

Check that the database tables were created:

```bash
drush sqlq "SHOW TABLES LIKE 'nfr_%';"
```

You should see:
- nfr_firefighters
- nfr_cancer_data
- nfr_longitudinal_data

### 4. Configure Permissions

Navigate to: `/admin/people/permissions`

Set appropriate permissions for roles:

**Administrators:**
- All NFR permissions

**NFR Staff:**
- Access NFR Dashboard
- Manage Firefighters
- View Firefighter Records
- View Cancer Data
- Manage Cancer Data

**Public Users:**
- (No permissions - can only access registration form)

### 5. Configure Module Settings

Navigate to: `/admin/config/nfr/settings`

Configure:
1. **General Settings**
   - Enable notifications: Yes
   - Default certification period: 24 months
   - Require badge number: No

2. **State Cancer Registry Integration**
   - Enable automatic linkage: No (initially)
   - Require consent for linkage: Yes

3. **USFA NERIS Integration**
   - Enable NERIS sync: No (initially)
   - Configure API endpoint and key when ready

4. **Data Export Settings**
   - Enable public dashboard: Yes
   - Anonymize exports: Yes

### 6. Test Registration

Visit: `/nfr/register`

Complete a test registration to verify functionality.

## Configuration

### Email Notifications Setup

Configure email settings in Drupal:
```bash
drush config-set system.mail interface.default php_mail
```

### Cron Setup

Ensure Drupal cron is configured for automated tasks:
```bash
# Add to crontab
*/15 * * * * cd /path/to/drupal && drush cron
```

## Drush Commands

### View Statistics
```bash
drush nfr:stats
```

### Import from NERIS
```bash
drush nfr:import-neris [NERIS_ID]
```

### Process State Linkages
```bash
drush nfr:link-state CA
```

### View Linkage Statistics
```bash
drush nfr:linkage-stats
```

### Export Data
```bash
# Export all firefighters
drush nfr:export firefighters

# Export firefighters from specific state
drush nfr:export firefighters --state=CA

# Export cancer data
drush nfr:export cancer_data
```

## Integration Setup

### USFA NERIS Integration

1. Obtain API credentials from USFA
2. Configure in module settings:
   - API Endpoint: `https://apps.usfa.fema.gov/neris/api/v1/`
   - API Key: [Your API Key]
3. Enable NERIS synchronization
4. Test with sample import:
   ```bash
   drush nfr:import-neris [TEST_ID]
   ```

### State Cancer Registry Integration

Each state requires individual setup:

1. **Establish Data Use Agreement** with state registry
2. **Configure API Access** or SFTP credentials
3. **Test Linkage** with small batch
4. **Enable Batch Processing** for production

Example for California:
```bash
# Test linkage
drush nfr:link-state CA

# Check results
drush nfr:linkage-stats
```

## Data Migration

### Importing Existing Data

If you have existing firefighter data, create a migration script:

```php
// Example migration script
$data = [
  [
    'first_name' => 'John',
    'last_name' => 'Doe',
    'department' => 'FDNY',
    'state' => 'NY',
    'career_type' => 'career',
    'years_of_service' => 15,
  ],
  // ... more records
];

foreach ($data as $record) {
  $connection->insert('nfr_firefighters')
    ->fields($record + [
      'status' => 'active',
      'created' => time(),
      'updated' => time(),
    ])
    ->execute();
}
```

## Monitoring & Maintenance

### Health Checks

Regular checks to ensure system health:

```bash
# Check database tables
drush sqlq "SELECT COUNT(*) as total FROM nfr_firefighters;"
drush sqlq "SELECT COUNT(*) as total FROM nfr_cancer_data;"

# Check recent registrations
drush sqlq "SELECT COUNT(*) as recent FROM nfr_firefighters WHERE created > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY));"
```

### Performance Monitoring

Monitor query performance:
```bash
# Enable slow query log in MySQL
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;
```

### Backup Procedures

Regular backups are critical:

```bash
# Backup database
drush sql-dump --gzip --result-file=/backup/nfr-$(date +%Y%m%d).sql

# Backup specific tables
mysqldump -u user -p database nfr_firefighters nfr_cancer_data nfr_longitudinal_data | gzip > /backup/nfr_tables.sql.gz
```

## Troubleshooting

### Issue: Registration form not submitting

**Solution:**
1. Check Drupal logs: `/admin/reports/dblog`
2. Verify form validation settings
3. Check database permissions

### Issue: State registry linkage failing

**Solution:**
1. Verify API credentials
2. Check consent status for participants
3. Review error logs:
   ```bash
   drush watchdog-show --type=nfr
   ```

### Issue: Dashboard not showing statistics

**Solution:**
1. Clear cache: `drush cr`
2. Verify database tables exist
3. Check permissions for accessing dashboard

### Issue: NERIS import errors

**Solution:**
1. Verify API endpoint URL
2. Check API key validity
3. Test network connectivity to USFA servers
4. Review NERIS integration logs

## Security Considerations

### HIPAA Compliance

1. **Enable HTTPS** for all traffic
2. **Configure encryption** for sensitive fields
3. **Implement audit logging** for PHI access
4. **Regular security updates** for Drupal core and modules
5. **Access controls** - least privilege principle

### Data Privacy

1. **Anonymization** enabled for all exports
2. **Consent tracking** for all data sharing
3. **Retention policies** - document and implement
4. **User access reviews** - quarterly audit

## Production Deployment

### Pre-Deployment Checklist

- [ ] Module tested in staging environment
- [ ] Database backups completed
- [ ] Permissions configured correctly
- [ ] Email notifications tested
- [ ] HTTPS enabled and verified
- [ ] Monitoring and alerting configured
- [ ] Documentation updated
- [ ] Staff training completed

### Deployment Steps

1. **Backup production database**
   ```bash
   drush sql-dump --gzip --result-file=/backup/pre-nfr-deploy.sql
   ```

2. **Enable module in production**
   ```bash
   drush en nfr -y
   drush cr
   drush updb -y
   ```

3. **Verify installation**
   ```bash
   drush nfr:stats
   ```

4. **Configure settings**
   - Navigate to `/admin/config/nfr/settings`
   - Set production values

5. **Test core functionality**
   - Test registration
   - Verify dashboard
   - Check data export

## Support & Documentation

### Internal Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md) - System architecture
- [README.md](README.md) - Module overview and features

### Logs & Debugging

View module-specific logs:
```bash
drush watchdog-show --type=nfr
```

### Getting Help

For issues or questions:
1. Check the documentation
2. Review Drupal logs
3. Contact module maintainer
4. File issue in project tracker

## Version Information

**Current Version:** 1.0.0
**Drupal Compatibility:** 9.x, 10.x, 11.x
**Last Updated:** January 25, 2026

## Change Log

### Version 1.0.0 (2026-01-25)
- Initial release
- Core participant registration
- Cancer data tracking
- State registry linkage framework
- USFA NERIS integration framework
- Longitudinal data collection
- Data export and dashboard features
- Drush command support
