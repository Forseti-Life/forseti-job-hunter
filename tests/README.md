# Job Hunter Module - Testing Documentation

## Overview
This directory contains test cases and testing documentation for the Job Hunter module.

## Documentation Files

### TEST_CASES.md
**Comprehensive test case documentation** covering all aspects of the Job Hunter module.

📊 **Statistics**:
- **109 documented test cases** across 10 categories
- **1,400+ lines** of detailed testing documentation
- **Coverage areas**: Unit, Integration, Functional, API, UI, Security, Performance, Migration, Queue Workers, AI Services

📋 **Test Categories**:
1. **Unit Tests** (15+ cases) - Service layer testing
2. **Integration Tests** (20+ cases) - Workflow and feature integration
3. **Functional Tests** (15+ cases) - User-facing functionality
4. **API Tests** (10+ cases) - AJAX endpoints and REST APIs
5. **Browser/UI Tests** (10+ cases) - Interface and user experience
6. **Security Tests** (15+ cases) - Authentication, validation, file security
7. **Performance Tests** (10+ cases) - Load time, database, API performance
8. **Data Migration Tests** (5+ cases) - Data migration and format conversion
9. **Queue Worker Tests** (10+ cases) - Background job processing
10. **AI Service Tests** (10+ cases) - AWS Bedrock integration and AI quality

🎯 **Each test case includes**:
- Unique Test Case ID (e.g., UPS-001, JSS-002)
- Clear description
- Specific test scenarios
- Implementation status (✅ IMPLEMENTED / 🔄 TODO)
- Test execution guidelines

## Current Test Implementation Status

### ✅ Implemented Tests (5 cases)
Located in: `src/Unit/Service/UserProfileServiceTest.php`

1. **UPS-001**: Profile Completeness Calculation
2. **UPS-002**: Field Completion Detection
3. **UPS-003**: Missing Field Recommendations
4. **UPS-004**: Completeness Status Detection
5. **UPS-005**: Job Application Validation

### 🔄 Pending Implementation (104+ cases)
See [TEST_CASES.md](TEST_CASES.md) for complete list and implementation roadmap.

## Quick Start

### Running Existing Tests

```bash
# Run all Job Hunter tests
vendor/bin/phpunit modules/custom/job_hunter/tests/

# Run unit tests only
vendor/bin/phpunit modules/custom/job_hunter/tests/src/Unit/

# Run specific test class
vendor/bin/phpunit modules/custom/job_hunter/tests/src/Unit/Service/UserProfileServiceTest.php

# Run with code coverage
vendor/bin/phpunit --coverage-html coverage modules/custom/job_hunter/tests/
```

### Test Development Workflow

1. **Review TEST_CASES.md** - Find the test case you want to implement
2. **Create test class** - Follow Drupal testing standards
3. **Implement test methods** - Use documented test scenarios
4. **Run tests** - Verify implementation
5. **Update status** - Mark test as ✅ IMPLEMENTED in documentation

## Test Directory Structure

```
tests/
├── README.md                          # This file
├── TEST_CASES.md                      # Comprehensive test documentation
└── src/
    ├── Unit/                          # Unit tests (service layer)
    │   └── Service/
    │       └── UserProfileServiceTest.php
    ├── Kernel/                        # Integration tests (module integration)
    ├── Functional/                    # Functional tests (user workflows)
    └── FunctionalJavascript/          # Browser tests (UI interaction)
```

## Test Implementation Priority

### Phase 1: Foundation (High Priority)
1. Security tests (Authentication, Input Validation)
2. Core service tests (JobSeekerService, ResumePdfService)
3. Queue worker tests (Resume processing, Tailoring)

### Phase 2: User Experience (Medium Priority)
1. Functional tests (Profile management, Job discovery)
2. UI tests (Forms, Navigation, Responsive design)
3. Integration tests (Complete workflows)

### Phase 3: Enhancement (Low Priority)
1. Performance tests
2. Accessibility tests
3. Advanced AI tests

## Testing Standards

### Test Naming Conventions
- **Test class**: `{ClassName}Test.php`
- **Test method**: `test{FeatureDescription}`
- **Test case ID**: `{CATEGORY}-{NUMBER}` (e.g., UPS-001)

### Test Documentation Requirements
- Clear description of what is being tested
- Expected behavior documented
- Edge cases identified
- Dependencies noted
- Mock strategy documented

### Code Coverage Goals
- **Unit Tests**: 80% code coverage minimum
- **Integration Tests**: All critical workflows covered
- **Functional Tests**: All user-facing features tested
- **Security Tests**: All input points validated

## Resources

### Internal Documentation
- [TEST_CASES.md](TEST_CASES.md) - Comprehensive test case documentation
- [../README.md](../README.md) - Module overview and features
- [../ARCHITECTURE.md](../ARCHITECTURE.md) - System architecture

### External Resources
- [Drupal Testing Documentation](https://www.drupal.org/docs/testing)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Drupal Test Traits](https://www.drupal.org/docs/testing/phpunit-in-drupal/phpunit-test-tutorial)

## Contributing Tests

When adding new tests:

1. ✅ **Follow Drupal testing standards**
2. ✅ **Use appropriate test base class**:
   - `UnitTestCase` for unit tests
   - `KernelTestBase` for integration tests
   - `BrowserTestBase` for functional tests
   - `WebDriverTestBase` for JavaScript tests
3. ✅ **Mock external dependencies** (AI services, HTTP clients)
4. ✅ **Use test-specific database** (don't affect production data)
5. ✅ **Clean up after tests** (delete test data, close connections)
6. ✅ **Update TEST_CASES.md** when test is implemented

## Test Data and Fixtures

### Test Resume Files
Store test resume files in: `tests/fixtures/resumes/`
- `sample_resume.docx` - Valid resume for testing
- `corrupted_resume.docx` - Invalid file for error testing
- `large_resume.docx` - Large file for performance testing

### Test User Data
Mock user data for testing:
- Use `$this->createMockUser()` helper methods
- Don't use real user data in tests
- Create minimal test data needed

### Database Fixtures
- Tests should create their own data
- Use `setUp()` to prepare test environment
- Use `tearDown()` to clean up

## Continuous Integration

Tests are automatically run:
- On every pull request
- Before merging to main branch
- On scheduled nightly builds

### CI Requirements
- ✅ All tests must pass
- ✅ Code coverage must meet minimum (80%)
- ✅ No security vulnerabilities detected
- ✅ Performance benchmarks met

## Getting Help

### Questions about Testing?
- Review [TEST_CASES.md](TEST_CASES.md) for test scenarios
- Check existing tests in `src/Unit/` for examples
- Consult [Drupal testing documentation](https://www.drupal.org/docs/testing)

### Found a Bug?
- Add a test case that reproduces the bug
- Fix the bug
- Verify test passes
- Submit pull request with test + fix

### Need to Add a New Test?
1. Document it in TEST_CASES.md first
2. Get review on test approach
3. Implement the test
4. Mark as ✅ IMPLEMENTED

## Test Execution Tips

### Speed Up Test Execution
```bash
# Run tests in parallel (if available)
vendor/bin/paratest modules/custom/job_hunter/tests/

# Run only failing tests
vendor/bin/phpunit --failed

# Stop on first failure
vendor/bin/phpunit --stop-on-failure
```

### Debug Test Failures
```bash
# Run with verbose output
vendor/bin/phpunit --verbose

# Run specific test method
vendor/bin/phpunit --filter testCalculateCompletenessEmpty

# Print debug output
vendor/bin/phpunit --debug
```

### Generate Coverage Reports
```bash
# HTML coverage report
vendor/bin/phpunit --coverage-html coverage/

# XML coverage report (for CI)
vendor/bin/phpunit --coverage-clover coverage.xml

# Text coverage summary
vendor/bin/phpunit --coverage-text
```

## Maintenance

This testing documentation is a living document and should be updated:
- When new features are added
- When test cases are implemented
- When testing approaches change
- When new test categories are needed

**Last Updated**: 2026-02-06
**Maintained By**: Job Hunter Development Team
**Status**: Active Development

---

For complete test case details, see [TEST_CASES.md](TEST_CASES.md)
