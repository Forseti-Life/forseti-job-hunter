# Job Application Automation - Frequently Asked Questions

## Table of Contents
- [General Questions](#general-questions)
- [Getting Started](#getting-started)
- [Configuration](#configuration)
- [Resume Tailoring](#resume-tailoring)
- [Job Discovery](#job-discovery)
- [Troubleshooting](#troubleshooting)
- [Security & Privacy](#security--privacy)
- [Technical Questions](#technical-questions)

---

## General Questions

### What is the Job Application Automation module?
The Job Application Automation module is a comprehensive Drupal system that uses AI to automate the job application process. It scrapes job postings, tailors your resume using AWS Bedrock Claude AI, and helps streamline your job search workflow.

### What AI service does this module use?
The module uses **AWS Bedrock Runtime** with **Claude 3.5 Sonnet** (model: `anthropic.claude-3-5-sonnet-20240620-v1:0`). This provides state-of-the-art natural language processing for resume tailoring and analysis.

### Do I need AWS credentials?
Yes, for production use. The module requires AWS credentials with access to Bedrock Runtime. For development environments, the module includes mock responses for testing without AWS costs.

### Is this module free?
The Drupal module itself is open source. However, using AWS Bedrock incurs costs based on your usage (tokens processed by Claude AI). Check AWS Bedrock pricing for current rates.

---

## Getting Started

### How do I install the module?
1. Enable the module: `drush pm:enable job_hunter -y`
2. Clear cache: `drush cr`
3. Configure settings at `/admin/config/job-application/settings`
4. Set your Original Resume node
5. Start creating companies and job postings

See [INSTALL.md](../INSTALL.md) for detailed installation instructions.

### What content types does the module create?
The module creates custom content types for different data:
- **Company** - Employer profiles with scraping configuration
- **Job Posting** - Individual job opportunities
- **Application** - Track application submissions and status
- **Issue** - Error queue for failed automation workflows
- **Tailored Resume** - AI-generated resume versions

### What profile data is stored?
Job seeker profiles are stored in a custom `job_seeker` database table with:
- Resume node reference
- Skills (JSON array)
- Experience years
- Target companies (JSON array)
- Preferred locations (JSON array)
- Target job titles (JSON array)
- Salary expectations
- Availability status
- LinkedIn and portfolio URLs
- Created and modified timestamps

**Important:** This data persists even if the module is uninstalled to prevent data loss.

### Can I use this on a multi-site Drupal installation?
Yes! The module is designed to work with Drupal multi-site installations. Each site maintains its own configuration and content.

---

## Configuration

### Where do I configure the module?
Navigate to `/admin/config/job-application/settings` for main configuration options.

### How do I set my Original Resume?
1. Create a resume node (Content Type: Resume)
2. Go to `/admin/config/job-application/settings`
3. Use the autocomplete field to select your resume node
4. Save configuration

The module will use this resume as the base for all AI tailoring.

### What if I don't configure an Original Resume?
The system will fall back to searching for a resume node titled "Original Resume". However, this is fragile and not recommended. Always use the configuration setting.

### Can I disable automatic tailoring?
Yes! In `/admin/config/job-application/settings`, uncheck "Enable automatic tailoring". This stops the module from automatically generating tailored resumes when job postings are created.

### How do I change AI settings?
In the settings form at `/admin/config/job-application/settings`, you can configure:
- **AWS Region** (default: us-west-2)
- **Model ID** (default: anthropic.claude-3-5-sonnet-20240620-v1:0)
- **Max Tokens** (default: 4000)

---

## Resume Tailoring

### How does automatic tailoring work?
When you create a new **Job Posting** node:
1. Module detects the new node via `hook_entity_insert()`
2. Loads your configured Original Resume
3. Extracts job title, company, and description from the job posting
4. Sends prompt to AWS Bedrock Claude AI
5. AI generates tailored resume optimized for that specific job
6. Saves tailored content to `field_tailored_resume` on the job posting

All of this happens automatically in the background!

### Can I manually trigger tailoring?
Yes, the module includes endpoints for manual tailoring via the UI. Access `/user/{user_id}/tailor-resume/{job_id}` to manually review and trigger tailoring.

### How long does AI tailoring take?
Typically 3-10 seconds depending on:
- Resume length
- Job description complexity
- AWS Bedrock API response time
- Network latency

### What if tailoring fails?
The module includes comprehensive error handling:
- Errors are logged to the Drupal watchdog (`job_hunter` channel)
- Check Recent Log Messages at `/admin/reports/dblog`
- Common causes: Missing AWS credentials, network issues, or invalid resume format

### Can I edit the tailored resume?
Yes! Tailored resumes are stored in the `field_tailored_resume` text field on the Job Posting node. You can edit them like any Drupal field through the node edit form.

### Does tailoring modify my original resume?
**No!** The Original Resume node is never modified. Tailoring creates new content in the job posting's field while preserving your master resume intact.

---

## Job Discovery

### What is Job Discovery?
Job Discovery is a planned feature for automatically scraping job postings from employer websites. The framework is in place but requires per-employer implementation.

See [JOB_DISCOVERY_README.md](../JOB_DISCOVERY_README.md) for technical details.

### Which employers are supported?
Currently, job discovery requires custom implementation per employer. The MVP focuses on perfecting automation for **one employer** before scaling.

### How do I add a new company?
1. Navigate to `/node/add/company`
2. Fill in company details (name, website, careers URL)
3. Add scraping configuration (requires technical knowledge)
4. Save the company node

### Can I disable job scraping?
Yes. Simply don't configure scraping settings for a company, or set appropriate flags in the company node fields.

---

## Troubleshooting

### "Original Resume node not found" warning
**Solution:** Configure your Original Resume at `/admin/config/job-application/settings`. Use the entity autocomplete to select your master resume node.

### Tailored resume is empty or contains errors
**Check:**
1. Is your Original Resume node populated with content?
2. Does the resume body field contain actual text?
3. Are AWS credentials configured correctly?
4. Check Recent Log Messages for specific errors

### "Cache rebuild complete" but settings not working
After changing configuration:
1. Clear Drupal cache: `drush cr`
2. Check configuration export: `drush cex -y`
3. Verify config at `/admin/config/development/configuration/single/export`

### Module won't uninstall
The module is designed to **preserve all user content** during uninstall. Only configuration is removed. If you want to remove content types and fields, you must do so manually after uninstalling.

### Permission denied errors
**Check permissions:**
1. Navigate to `/admin/people/permissions`
2. Search for "job application automation"
3. Grant appropriate permissions to user roles
4. Key permission: "Administer Job Application Automation"

### AI responses are taking too long
**Potential causes:**
- Network latency to AWS
- Complex/long resume or job description
- AWS Bedrock throttling (check quotas)
- Region misconfiguration (try us-west-2 if using other region)

---

## Security & Privacy

### Where are my AWS credentials stored?
AWS credentials should be configured via standard AWS SDK credential methods:
- Environment variables (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`)
- AWS credentials file (`~/.aws/credentials`)
- IAM roles (recommended for EC2/ECS deployments)

**Never** store credentials in the Drupal database or configuration exports.

### Is my resume data secure?
- Resumes are stored in Drupal's database with standard Drupal security
- Resume content sent to AWS Bedrock is processed but not stored by AWS (per AWS Bedrock terms)
- Use HTTPS for all production deployments
- Follow Drupal security best practices

### Can other users see my resume?
By default, resume nodes follow Drupal's node access system. Configure permissions appropriately to restrict resume visibility.

### What data is logged?
The module logs:
- AI tailoring events (success/failure)
- Configuration changes
- Resume node access (which resume was used)
- Errors and warnings

Logs are stored in Drupal's watchdog system and can be viewed at `/admin/reports/dblog`.

### GDPR compliance?
The module itself doesn't implement specific GDPR features. You must:
- Add appropriate privacy policies for AI processing
- Implement data export/deletion workflows per GDPR requirements
- Document data processing in your privacy policy
- Obtain user consent for AI resume processing

---

## Technical Questions

### What Drupal version is required?
**Drupal 11.2.3+** is required. The module uses Drupal 11 APIs and is not backward compatible with Drupal 10 or earlier.

### What PHP version is required?
**PHP 8.3.6+** is required for AWS SDK compatibility and Drupal 11 requirements.

### Which contributed modules are dependencies?
- **Views** (core, for administrative interfaces)
- **Block** (core, for navigation system)
- **AWS SDK PHP** (via Composer, for AI integration)

Note: The Profile contrib module is **NOT** required. Job seeker profiles use a custom database table.

### What happens to my data if I uninstall the module?
The module is designed to **preserve all data** during uninstall:
- **Content types** are preserved (Company, Job Posting, Application, Issue, Tailored Resume)
- **Custom table** `job_seeker` is preserved with all profile data
- **User fields** are preserved
- Only module configuration settings are removed

To manually remove items after uninstall:
- Content types: Structure > Content types
- Custom table: Use database administration tools
- User fields: Configuration > Account settings

### How do I access job seeker profiles?
- **View profile:** `/jobhunter/profile`
- **Edit profile:** `/jobhunter/profile/edit`
- **Dashboard:** `/jobhunter`

All routes require appropriate permissions (typically `access job hunter` for members, and admin permissions for administrative pages).

### Can I use a different AI service?
The module is architected with a service layer (`ResumeTailoringService`). You can create an alternative service implementation for different AI providers. However, the default implementation is tightly coupled to AWS Bedrock.

### How do I extend the module?
The module follows Drupal best practices:
- Use `hook_form_alter()` to customize forms
- Use Views to create custom displays
- Extend services via dependency injection
- Add custom fields to content types via UI
- Use event subscribers for advanced customization

### Where is the code repository?
Check your git remote: `git remote -v`. The module is typically part of your Drupal site repository.

### How do I report bugs?
1. Check existing issues in your repository
2. Review Recent Log Messages (`/admin/reports/dblog`)
3. Enable debug logging if needed
4. Create detailed issue reports with logs and steps to reproduce

### Can I contribute to development?
Yes! Follow standard Drupal contribution guidelines:
- Write clean, documented code
- Follow Drupal coding standards
- Add tests for new functionality
- Update documentation
- Submit pull requests with clear descriptions

---

## Additional Resources

- [Architecture Documentation](ARCHITECTURE.md) - Complete technical architecture
- [Process Flow Documentation](PROCESS_FLOW.md) - Workflow diagrams and sequences
- [Installation Guide](../INSTALL.md) - Detailed installation steps
- [Profile Management](../PROFILE_MANAGEMENT.md) - User profile field documentation
- [Job Discovery Technical Guide](../JOB_DISCOVERY_README.md) - Scraping implementation details
- [Main README](../README.md) - Module overview and quick start

---

## Still Have Questions?

If your question isn't answered here:
1. Check the other documentation files listed above
2. Review the codebase comments and docblocks
3. Check Drupal watchdog logs for error details
4. Search Drupal.org forums for similar issues
5. Contact your site administrator or development team

**Last Updated:** January 2026
