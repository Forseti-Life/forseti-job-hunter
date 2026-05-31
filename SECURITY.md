# Security Policy

## Reporting a Vulnerability

**Please do NOT open public issues for suspected security vulnerabilities.**

Vulnerabilities should be reported privately via one of these methods:

1. **GitHub Private Security Advisory** (preferred)
   - Create a draft security advisory in the repository
   - Include affected component/version, severity, and reproduction details

2. **Email** (if advisories unavailable)
   - Report to: maintainers through secure channel
   - Include: affected component, steps to reproduce, impact assessment

## Required Information

When reporting a vulnerability, please include:

- **Component/Module/File** affected
- **Affected Version(s)**
- **Steps to reproduce** the vulnerability
- **Impact Assessment** (e.g., data exposure, unauthorized access)
- **Suggested Remediation** (if you have one)
- **Your contact information**

## Response Timeline

- **Initial Acknowledgement:** Within 3 business days
- **Severity Assessment:** As soon as practical
- **Patch Development:** Depends on complexity
- **Public Disclosure:** After patch is released

## What Qualifies as a Security Issue

**Include:**
- Authentication/authorization bypass
- Data exposure or leakage
- Injection vulnerabilities (SQL, PHP code, etc.)
- Privilege escalation
- Session hijacking
- Credential storage issues
- API token/key exposure

**Exclude (use standard issue reporting):**
- Documentation typos
- Usability issues
- Feature requests
- Non-exploitable configuration edge cases

## Security Practices

### Credential Handling

- **API Keys/Secrets:** Store only in Drupal configuration, never in code
- **Database Credentials:** Use Drupal's database connection settings
- **Environment Variables:** Never hardcode; use `.env` files (not in git)
- **Logs:** Mask credentials in debug output

### Code Security

- **Input Validation:** All user input validated before use
- **SQL Injection Prevention:** Use prepared statements and Drupal query API
- **XSS Prevention:** Escape all output, use Drupal's security functions
- **CSRF Protection:** Use Drupal's form token API
- **Permission Checks:** Verify user permissions before operations

### Dependencies

- Keep Drupal core and dependencies up-to-date
- Review security advisories for all packages
- Report vulnerable dependencies to maintainers
- Use `composer audit` to check for known vulnerabilities

## Uninstall Safety

- **Data Preservation:** Uninstall NEVER deletes user content
- **Credential Cleanup:** Remove stored credentials on uninstall
- **Tables:** Custom tables preserved for data safety
- **Rollback:** Safe to disable and re-enable module

## Configuration Security

### Safe Defaults
- Debug mode disabled by default
- API access restricted to authenticated users
- External API calls require explicit credentials
- Credentials stored in configuration (not visible to non-admins)

### Best Practices
- Rotate external API keys periodically
- Use separate credentials for development/production
- Restrict Google Cloud service account permissions to minimum needed
- Enable API rate limiting if available

## Data Protection

### Personal Data
- Resume files: Stored in private file system (not web-accessible)
- User profiles: Subject to Drupal user field permissions
- Application history: User-owned, protected by entity access control
- AI-generated data: Not shared with third parties without consent

### Retention
- Default: Data preserved on module uninstall
- Deletion: Manual cleanup available through Drupal UI
- Archival: No built-in archival system

### GDPR/Privacy
- Compliance: Module respects Drupal's privacy settings
- Data Export: Use Drupal's user data export feature
- Deletion: Users can delete their own profiles and content
- Configuration: No automatic data collection

## External Services

### Third-Party APIs
- **Google Cloud Talent Solution:** Requires valid credentials, terms apply
- **SerpAPI:** Free tier available, terms: https://serpapi.com
- **Adzuna:** Terms: https://developer.adzuna.com
- **USAJobs:** US Government API, terms: https://developer.usajobs.gov
- **AWS Bedrock:** Requires AWS account, terms: https://aws.amazon.com

### Data Sharing
- Job search queries sent to configured APIs
- Resume data sent to AI service for parsing/tailoring (if enabled)
- No data shared with unauthorized parties
- Review API terms before configuration

## Security Checklist for Administrators

Before deploying Job Hunter in production:

- [ ] Module enabled only on updated Drupal 10/11 site
- [ ] PHP security updates applied (8.1+)
- [ ] Database credentials protected (not in code)
- [ ] External API credentials configured securely
- [ ] File permissions correct (private:// directory)
- [ ] Drupal permissions assigned (not all users need Job Hunter)
- [ ] HTTPS enabled on site
- [ ] Regular backups configured
- [ ] Log monitoring configured
- [ ] Security advisories monitored

## Version Support

| Version | Status | Support |
|---------|--------|---------|
| 1.0.x | Current | Security patches for 1 year |
| 0.x | Retired | No longer supported |

## License

This module is licensed under Apache License 2.0. See LICENSE file for full terms.

---

## Questions?

- For security issues: See reporting instructions above
- For general questions: See CONTRIBUTING.md or open a discussion
- For bugs: Use GitHub issues
