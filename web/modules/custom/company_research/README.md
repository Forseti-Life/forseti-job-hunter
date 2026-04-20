# Company Research Module

**Version:** 1.0  
**Created:** February 13, 2026

## Overview

The Company Research module automates the discovery of company career pages, identifies their Application Tracking System (ATS) platform, and analyzes authentication requirements for account creation.

## Features

- **Company Website Discovery**: Automatically discovers company websites from company names
- **Careers Page Detection**: Finds careers/jobs pages on company websites
- **ATS Platform Identification**: Detects which ATS platform is being used (Workday, Greenhouse, Taleo, Lever, SmartRecruiters, iCIMS, etc.)
- **Authentication Analysis**: Analyzes authentication methods, CAPTCHA requirements, and verification requirements
- **AI-Powered Analysis**: Uses the ai_conversation module's Bedrock integration for intelligent analysis
- **Automation Readiness**: Provides an assessment of how automation-friendly each company's application process is

## Installation

1. Enable the module:
   ```bash
   drush en company_research -y
   ```

2. Clear caches:
   ```bash
   drush cr
   ```

3. Ensure the `ai_conversation` module is enabled and configured with AWS Bedrock credentials.

## Usage

### Researching a Company

1. Navigate to `/company-research`
2. Enter a company name (e.g., "Acme Corporation")
3. Optionally check "Force refresh" to bypass cached results
4. Click "Research Company"
5. View the results showing:
   - Company domain
   - Careers page URLs
   - ATS platform detected
   - Authentication methods
   - CAPTCHA requirements
   - Automation readiness level

### Automation Readiness Levels

- **Ready**: Full automation possible - no significant barriers
- **Partial**: Some automation possible - some barriers present (e.g., CAPTCHA, email verification)
- **Manual**: Manual processing required - automation not feasible

## Architecture

### Services

The module provides several services for different aspects of research:

1. **CompanyResearchOrchestrator** (`company_research.orchestrator`)
   - Main service that coordinates the research workflow
   - Manages caching and result storage

2. **CompanyDiscoveryService** (`company_research.company_discovery`)
   - Discovers company websites from company names
   - Validates domain accessibility

3. **CareersPageDiscoveryService** (`company_research.careers_discovery`)
   - Finds careers pages using common URL patterns
   - Checks subdomain patterns (careers.company.com)

4. **ATSDetectionService** (`company_research.ats_detection`)
   - Detects ATS platform using domain matching
   - Analyzes HTML patterns
   - Uses AI for intelligent detection

5. **AuthenticationAnalysisService** (`company_research.auth_analysis`)
   - Detects CAPTCHA implementation (reCAPTCHA v2/v3, hCaptcha)
   - Uses AI to analyze authentication methods
   - Determines verification requirements

### Database Tables

1. **company_research_results**
   - Stores research results with 30-day cache
   - Indexed by company name, ATS platform, and automation readiness

2. **company_research_ats_platforms**
   - Reference table for known ATS platforms
   - Contains detection patterns for each platform

### AI Integration

The module leverages the `ai_conversation` module's AIApiService for:
- ATS platform detection when standard patterns don't match
- Authentication method analysis
- Verification requirement detection

All AI calls are logged and tracked through the ai_conversation module's existing infrastructure.

## Permissions

- **access company research**: Allows users to research companies and view results
- **administer company research**: Full administrative access to configuration and data

## Caching

Research results are cached for 30 days to improve performance and reduce API calls. Use the "Force refresh" option to bypass cache and perform fresh research.

## Dependencies

- Drupal Core (^9 || ^10 || ^11)
- ai_conversation module (for AI-powered analysis)
- GuzzleHTTP (included with Drupal)

## Limitations

- Company website discovery uses basic pattern matching (e.g., companyname.com)
- For better results, consider adding Google Custom Search API integration
- AI analysis requires AWS Bedrock credentials configured in ai_conversation module
- Some ATS platforms may not be detected if they use custom implementations

## Future Enhancements

- Google Custom Search API integration for better company discovery
- LinkedIn Company API integration for verification
- API endpoint discovery for detected ATS platforms
- More sophisticated authentication flow analysis
- Batch processing for multiple companies
- Historical tracking of ATS platform changes

## Support

For issues or questions, see the module's issue queue or contact the maintainers.

## License

Follows the project's licensing terms.
