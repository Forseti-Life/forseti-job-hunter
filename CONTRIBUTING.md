# Contributing to Job Application Automation Module

Thanks for your interest in improving this Drupal module!

## Getting Started

1. Read the [README.md](README.md) for an overview
2. Review [ARCHITECTURE.md](ARCHITECTURE.md) to understand the design
3. Check [INSTALL.md](INSTALL.md) for setup instructions
4. See [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for common issues

## Development Setup

### Prerequisites
- Drupal 10 or 11 (development environment)
- PHP 8.1+ with development tools
- Composer for dependency management
- Git for version control

### Local Installation
```bash
# Clone or download the module
cd your-drupal-site/web/modules/custom
git clone https://github.com/Forseti-Life/drupal-job-hunter.git job_hunter

# Install dependencies
cd /path/to/drupal-root
composer install

# Enable module
drush pm:enable job_hunter

# Run migrations
drush updatedb -y
```

## Development Workflow

1. **Create an issue** describing what you want to fix or improve
2. **Create a feature branch** with a clear name:
   ```bash
   git checkout -b fix/issue-123-job-search-timeout
   git checkout -b feature/pdf-resume-support
   ```
3. **Make changes** with clear, focused commits
4. **Test locally** before submitting
5. **Open a pull request** with:
   - Reference to the issue number
   - Summary of changes
   - Validation/testing steps performed
   - Any breaking changes or migration requirements

## Code Standards

### PHP
- Follow PSR-12 coding standard
- Use type hints on all functions/methods
- Document complex logic with comments
- Use Drupal logging for operational events

### Commit Messages
- Use descriptive, present-tense messages
- Reference issue numbers: `fix: update search timeout for #123`
- Keep commits atomic and focused

### Testing
- Test module enable/disable cycle
- Verify data preservation on uninstall
- Check database schema integrity
- Test with both Drupal 10 and 11

## Key Architecture Principles

**Before implementing changes, understand:**

1. **Content-First Design** — Business data lives in nodes (Company, Job Posting, Application)
2. **Hybrid Storage** — Operational/automation data uses custom tables
3. **Drupal-Native** — Use Drupal's forms, views, permissions, etc.
4. **No Platform Coupling** — Module must work on any Drupal site
5. **Data Preservation** — Uninstall should NOT delete user content

See [ARCHITECTURE.md](ARCHITECTURE.md) for full architectural guidance.

## Common Contribution Areas

### Bug Fixes
- Check existing issues first
- Add failing test case if possible
- Fix and submit PR with regression test

### Feature Additions
- Discuss in an issue first
- Keep scope focused and minimal
- Document new configuration/hooks
- Update README if needed

### Documentation Improvements
- Fix typos, clarify ambiguous sections
- Add examples for complex concepts
- Update TROUBLESHOOTING.md with new solutions
- Ensure Drupal.org compliance

### Performance Improvements
- Benchmark before/after
- Check for n+1 query problems
- Optimize database queries
- Add caching where appropriate

## Pull Request Checklist

Before submitting a PR, verify:

- [ ] Module enables/disables without errors
- [ ] No PHP warnings or errors in logs
- [ ] Database tables created/updated correctly
- [ ] No hardcoded paths or site-specific values
- [ ] No secrets or credentials added
- [ ] Follows PSR-12 code standards
- [ ] Tested on both Drupal 10 and 11
- [ ] Documentation updated (README, ARCHITECTURE, TROUBLESHOOTING as needed)
- [ ] Commit messages are clear and descriptive
- [ ] No breaking changes without migration path

## Reporting Security Issues

**Please do NOT open public issues for security vulnerabilities.**

See [SECURITY.md](SECURITY.md) for responsible disclosure process.

## Code of Conduct

See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) — Be respectful, kind, and professional in all interactions.

## Getting Help

- Check [TROUBLESHOOTING.md](TROUBLESHOOTING.md) for common issues
- Review existing GitHub issues and discussions
- Ask questions in pull request comments
- See ARCHITECTURE.md for design guidance

## Recognition

All contributors will be recognized in the module's CHANGELOG. Thank you for helping improve this module!

---

**Questions?** Open a discussion or issue on the GitHub repository.
