# Company Research Path - Design Summary

**Document Version:** 1.0  
**Created:** February 13, 2026  
**Status:** Design Complete - Ready for Implementation

---

## Overview

This design package provides a comprehensive blueprint for implementing a company research system in the jobhunter module. The system accepts a company name as input and automatically discovers:

1. **Job Application Careers Pages** - All recruitment portals and career sites
2. **Application System Type (ATS)** - Which platform (Workday, Greenhouse, Taleo, etc.)
3. **Authentication Methodologies** - Required account creation and verification methods

---

## Document Structure

This design consists of three complementary documents:

### 1. Main Design Document
**File:** [COMPANY_RESEARCH_PATH_DESIGN.md](./COMPANY_RESEARCH_PATH_DESIGN.md)

**Purpose:** Complete architectural design and specifications

**Contents:**
- Detailed 8-step process flow
- Controller architecture with methods and routes
- Service layer architecture (6 services)
- Data models and database schemas
- API integrations (Google Search, LinkedIn, Headless Browser)
- Authentication detection strategies
- 12-week implementation roadmap

**Key Sections:**
- Process Flow (8 steps from input to output)
- Controller Architecture (CompanyResearchServiceController)
- Service Layer (6 specialized services)
- Data Models (2 database tables with schemas)
- Authentication Strategies (4 methods: Email/Password, SSO, SAML, 2FA)
- Security Considerations
- Implementation Roadmap (Phases 1-6)

---

### 2. Visual Diagrams Document
**File:** [COMPANY_RESEARCH_DIAGRAMS.md](./COMPANY_RESEARCH_DIAGRAMS.md)

**Purpose:** Visual representations of flows and architectures

**Contents:**
- System architecture diagram (5 layers)
- Service interaction diagram with complete flow
- Data flow diagram showing transformations
- Authentication detection flow chart
- ATS detection decision tree
- State machine diagrams
- Component dependency graph
- Caching strategy diagram
- Batch processing flow
- Error handling flow

**Key Diagrams:**
- System Architecture (UI → Controller → Orchestrator → Services → Data)
- Service Interactions (Step-by-step with dependencies)
- Data Transformations (Input → Normalized → Enriched → Output)
- State Machines (Research states and automation readiness)
- Error Handling (Graceful degradation strategy)

---

### 3. Implementation Examples Document
**File:** [COMPANY_RESEARCH_IMPLEMENTATION_EXAMPLES.md](./COMPANY_RESEARCH_IMPLEMENTATION_EXAMPLES.md)

**Purpose:** Concrete code examples for implementation

**Contents:**
- Service implementations (6 services with full code)
- Controller implementations
- Database schema creation hooks
- Configuration schema examples
- Testing examples (unit tests)
- Template examples (Twig)
- Service registration (services.yml)
- Route definitions (routing.yml)
- Permission definitions (permissions.yml)

**Key Examples:**
- CompanyResearchService (main orchestrator)
- CompanyDiscoveryService (website discovery)
- ATSDetectionService (platform identification)
- Unit test examples with data providers
- Twig templates for results display

---

## Quick Start Guide

### For Developers Planning Implementation

1. **Start Here:** Read [COMPANY_RESEARCH_PATH_DESIGN.md](./COMPANY_RESEARCH_PATH_DESIGN.md)
   - Understand the complete architecture
   - Review the 8-step process flow
   - Study the service layer design

2. **Visualize:** Review [COMPANY_RESEARCH_DIAGRAMS.md](./COMPANY_RESEARCH_DIAGRAMS.md)
   - See how components interact
   - Understand data flow
   - Learn state transitions

3. **Implement:** Reference [COMPANY_RESEARCH_IMPLEMENTATION_EXAMPLES.md](./COMPANY_RESEARCH_IMPLEMENTATION_EXAMPLES.md)
   - Copy and adapt code examples
   - Follow the service patterns
   - Use the database schemas

4. **Execute:** Follow the Implementation Roadmap
   - Phase 1: Foundation (Weeks 1-2)
   - Phase 2: ATS Detection (Weeks 3-4)
   - Phase 3: API Discovery (Weeks 5-6)
   - Phase 4: Authentication Analysis (Weeks 7-8)
   - Phase 5: Integration & Polish (Weeks 9-10)
   - Phase 6: Testing & Deployment (Weeks 11-12)

---

## Key Design Decisions

### Architecture Pattern: Service-Oriented

**Why:** Separation of concerns, testability, reusability

**Structure:**
```
Controller (HTTP layer)
    ↓
Orchestrator Service (workflow coordination)
    ↓
Specialized Services (focused responsibilities)
    ↓
External APIs / Data Layer
```

---

### Data Storage: Hybrid Approach

**Strategy:** Nodes for canonical data + Custom tables for operational data

**Company Research Results:** Custom table
- Reason: High-volume operational data
- Cache-friendly with TTL
- Not user-generated content
- Performance-optimized queries

**ATS Platform Reference:** Custom table
- Reason: Reference data with detection patterns
- Frequently queried
- Version-controlled
- Seed data included

---

### Detection Strategy: Multi-Method with Confidence Scoring

**Methods (in order):**
1. Domain-based (Highest confidence)
2. HTML/CSS patterns (Medium confidence)
3. JavaScript libraries (Low confidence)
4. Meta tags (Low confidence)

**Result:** Confidence level + detection method returned

---

### Error Handling: Graceful Degradation

**Philosophy:** Partial results are better than complete failure

**Strategy:**
- Each step tries multiple methods
- Failures logged but don't stop process
- Return partial results with warnings
- Mark incomplete data clearly

---

### Caching: Time-based with Manual Refresh

**Default TTL:** 30 days

**Rationale:**
- Companies rarely change ATS platforms
- Reduce API calls and processing time
- Manual refresh available for updates
- Background refresh for active companies

---

## Implementation Estimates

### Development Time: 12 Weeks (Full Implementation)

**Phase 1: Foundation** (2 weeks)
- Database setup: 2 days
- CompanyDiscoveryService: 3 days
- CareersPageDiscoveryService: 3 days
- Basic UI: 2 days

**Phase 2: ATS Detection** (2 weeks)
- ATSDetectionService: 5 days
- Platform signatures: 2 days
- Testing: 3 days

**Phase 3: API Discovery** (2 weeks)
- APIDiscoveryService: 5 days
- Network analysis integration: 3 days
- Testing: 2 days

**Phase 4: Authentication Analysis** (2 weeks)
- AuthenticationAnalysisService: 6 days
- Form analysis: 2 days
- Testing: 2 days

**Phase 5: Integration** (2 weeks)
- Service orchestration: 3 days
- UI completion: 3 days
- Batch processing: 2 days
- Documentation: 2 days

**Phase 6: Testing & Deployment** (2 weeks)
- Unit tests: 3 days
- Integration tests: 3 days
- Performance testing: 2 days
- Deployment: 2 days

---

## Dependencies & Requirements

### External Services

**Required:**
- Google Custom Search API (for company discovery)
  - Free tier: 100 queries/day
  - Cost: $5 per 1000 queries after free tier

**Optional:**
- LinkedIn Company API (for company verification)
  - Requires LinkedIn Developer Account
  - Manual approval process

**Recommended:**
- Headless Browser (Puppeteer or Playwright)
  - For network traffic analysis
  - Can run as separate Node.js service
  - PHP wrapper available

---

### PHP Extensions

**Required:**
- cURL (for HTTP requests)
- JSON (for data encoding/decoding)
- PDO (for database access)

**Recommended:**
- DOM (for HTML parsing)
- Symfony DomCrawler (included with Drupal)

---

### Drupal Modules

**Core:**
- Database API (included)
- Configuration API (included)
- Logger API (included)

**Contrib:**
- None required (all functionality in custom code)

---

## Testing Strategy

### Unit Tests

**Coverage:** All service methods

**Example Services to Test:**
- CompanyDiscoveryService::discoverCompanyWebsite()
- ATSDetectionService::detectATSPlatform()
- AuthenticationAnalysisService::analyzeAuthentication()

**Mock Data:** Use fixtures for external API responses

---

### Integration Tests

**Scenarios:**
1. Complete research flow (company name → results)
2. Cached result retrieval
3. Refresh workflow
4. Batch processing

**Real Data:** Test with known companies (with permission)

---

### Performance Tests

**Metrics:**
- Research completion time (target: < 2 minutes per company)
- Cache hit rate (target: > 80%)
- API call count (minimize to stay within limits)
- Database query performance (< 100ms per query)

---

## Monitoring & Logging

### Logging Levels

**INFO:** Normal operations
- Research started
- Research completed
- Cache hits

**WARNING:** Recoverable issues
- API failures with fallback
- Partial detection
- Cache misses

**ERROR:** Unrecoverable issues
- Company not found
- All methods failed
- Database errors

---

### Metrics to Track

**Research Metrics:**
- Total companies researched
- Success rate (complete vs partial vs failed)
- Average research time
- Cache hit rate

**Detection Metrics:**
- ATS platforms detected (breakdown by platform)
- Confidence levels (high/medium/low counts)
- API availability rate

**Automation Metrics:**
- Readiness levels (ready/partial/manual counts)
- Common blockers
- Recommendation patterns

---

## Security Considerations

### Data Privacy

**Principle:** Only store necessary data

**Actions:**
- No storage of CAPTCHA responses
- No storage of actual passwords
- Encrypt API keys in configuration
- GDPR compliance for EU companies

---

### Rate Limiting

**Principle:** Be a good citizen

**Actions:**
- Respect robots.txt
- Implement delays between requests (1-2 seconds)
- Honor API rate limits
- Graceful degradation on limits

---

### Bot Detection

**Principle:** Don't trigger security measures

**Actions:**
- Use realistic user agents
- Reasonable request timing
- Don't overwhelm servers
- Never attempt to bypass CAPTCHA automatically

---

## Future Enhancements

### Phase 2 (After Initial Release)

**Machine Learning for ATS Detection:**
- Train model on known platforms
- Improve detection accuracy
- Reduce false positives

**Historical Tracking:**
- Track ATS platform changes over time
- Alert on changes
- Migration patterns

**Expanded Platform Support:**
- Niche ATS platforms
- International systems
- Government portals
- University systems

---

### Phase 3 (Advanced Features)

**Company Culture Integration:**
- Glassdoor integration
- Indeed reviews
- LinkedIn company pages
- Culture fit scoring

**Salary Data:**
- Salary.com integration
- Glassdoor salary data
- Cost of living adjustments

**Application Difficulty Scoring:**
- Process complexity analysis
- Time estimate
- Success rate prediction

---

## Success Criteria

### Minimum Viable Product (MVP)

**Must Have:**
- ✓ Discover company website from name
- ✓ Find careers pages
- ✓ Detect top 5 ATS platforms
- ✓ Basic authentication analysis
- ✓ Automation readiness score

**Nice to Have:**
- API endpoint discovery
- SSO provider detection
- CAPTCHA identification
- Batch processing

---

### Production Ready

**Quality Metrics:**
- 90% success rate for company discovery
- 85% accuracy for ATS detection
- < 3 minutes average research time
- 80% cache hit rate

**Reliability:**
- Graceful degradation on failures
- Comprehensive error logging
- Monitoring dashboard
- Alerting on critical issues

---

## Support & Maintenance

### Known Limitations

1. **ATS Detection:** Custom/unknown platforms return "CUSTOM_OR_UNKNOWN"
2. **API Discovery:** Some platforms have undocumented APIs
3. **Authentication:** Complex flows may need manual analysis
4. **Rate Limits:** Free tier Google Search limited to 100/day

---

### Maintenance Tasks

**Weekly:**
- Review failed research attempts
- Update ATS platform signatures
- Check API quota usage

**Monthly:**
- Refresh popular company data
- Update detection patterns
- Review and improve accuracy

**Quarterly:**
- Security audit
- Performance optimization
- Add new ATS platform support

---

## Documentation Updates

### When to Update

**Design Documents:**
- After major architecture changes
- When adding new services
- After security reviews

**Implementation Examples:**
- When adding new features
- After API changes
- When best practices evolve

**Diagrams:**
- After workflow changes
- When adding new components
- After major refactoring

---

## Contact & Questions

For questions about this design:
1. Review the three main documents
2. Check implementation examples
3. Consult the diagrams
4. Refer to existing similar code in the module

---

## Document Index

### Primary Documents

1. **[COMPANY_RESEARCH_PATH_DESIGN.md](./COMPANY_RESEARCH_PATH_DESIGN.md)**
   - Complete design specification
   - 43KB, ~1,900 lines

2. **[COMPANY_RESEARCH_DIAGRAMS.md](./COMPANY_RESEARCH_DIAGRAMS.md)**
   - Visual process flows
   - 38KB, ~1,000 lines

3. **[COMPANY_RESEARCH_IMPLEMENTATION_EXAMPLES.md](./COMPANY_RESEARCH_IMPLEMENTATION_EXAMPLES.md)**
   - Code examples
   - 49KB, ~1,700 lines

### Related Documents

- [README.md](../README.md) - Module overview
- [ARCHITECTURE.md](../ARCHITECTURE.md) - Overall module architecture
- [PROCESS_FLOW.md](./PROCESS_FLOW.md) - General process flows

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-02-13 | GitHub Copilot Agent | Initial complete design package |

---

## License & Usage

This design is part of the forseti.life project and follows the project's licensing terms. The design is provided as-is for implementation within the jobhunter module.

---

**Design Status: COMPLETE**  
**Implementation Status: NOT STARTED**  
**Ready for: Development Team Review & Implementation**

---

*End of Design Summary*
