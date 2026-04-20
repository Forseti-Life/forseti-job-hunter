# Code Review: UserProfileController.php

**File Size:** 2,190 lines  
**Date:** 2024  
**Severity Levels:** Critical 🔴 | High 🟠 | Medium 🟡 | Low 🔵

---

## Executive Summary

This is a **monolithic controller** with 2,190 lines containing **19 public/protected methods** and **5 private helper methods**. The file has severe architectural issues and should be split into **3-4 specialized services**. The controller is mixing business logic, database queries, queue management, and form handling, violating the Single Responsibility Principle.

### Key Issues
- **Monolithic design:** Should be split into multiple classes
- **Direct database access:** Raw queries scattered throughout instead of using services
- **Repeated code patterns:** Similar database queries duplicated 15+ times
- **Security vulnerabilities:** Potential SQL injection risks with LIKE queries
- **Missing abstraction:** Queue and job seeker operations need dedicated services
- **Testability crisis:** 2190 lines make unit testing extremely difficult

---

## 🔴 CRITICAL ISSUES

### 1. SQL Injection Vulnerability in Queue Status Check (Line 2127-2142)

**Issue:** Using LIKE pattern matching with user input without proper escaping

```php
// Line 2127-2142 - VULNERABLE CODE
$in_queue = $database->select('queue', 'q')
  ->fields('q', ['item_id'])
  ->condition('name', $queue_name)
  ->condition('data', '%"job_id":' . $job_id . '%', 'LIKE')  // ⚠️ VULNERABLE
  ->condition('data', '%"uid":' . $user_id . '%', 'LIKE')    // ⚠️ VULNERABLE
  ->execute()
  ->fetchField();
```

**Problem:** Concatenating user input directly into LIKE patterns is dangerous. An attacker could craft job_id/user_id values to break the pattern or access unintended data.

**Recommendation:** 
- Use parameterized queries
- Validate and cast inputs to integers
- Consider using JSON operators if database supports it

**Fix:**
```php
$in_queue = $database->select('queue', 'q')
  ->fields('q', ['item_id'])
  ->condition('name', $queue_name)
  ->condition('data', '%' . $database->escapeLike(json_encode(['job_id' => (int)$job_id])) . '%', 'LIKE')
  ->condition('data', '%' . $database->escapeLike(json_encode(['uid' => (int)$user_id])) . '%', 'LIKE')
  ->execute()
  ->fetchField();
```

---

### 2. Inadequate Input Validation in jobDiscoverySearch() (Lines 747-869)

**Issue:** Insufficient validation of user-supplied IDs before database queries

```php
// Line 759-769 - WEAK VALIDATION
if (!$user_id || !is_numeric($user_id)) {
  // ... error handling
}
if (!$company_id || !is_numeric($company_id)) {
  // ... error handling
}
```

**Problems:**
- `is_numeric()` accepts strings, floats, hex values - not strict integer validation
- No permission check that user owns this profile
- No rate limiting for external API calls
- Service injection is missing - using `\Drupal::service()` instead

**Recommendation:**
```php
$user_id = (int) $request->request->get('user_id');
$company_id = (int) $request->request->get('company_id');

if ($user_id <= 0 || $company_id <= 0) {
  return new JsonResponse(['error' => 'Invalid IDs'], 400);
}

// Verify user owns this profile
if ($this->currentUser->id() !== $user_id && !$this->currentUser->hasPermission('administer users')) {
  throw new AccessDeniedHttpException();
}
```

---

### 3. Unreliable Queue Status Tracking (Lines 2116-2160)

**Issue:** Complex, fragile status tracking logic using string pattern matching

```php
// Lines 2128-2134 - Fragile queue detection
$in_queue = $database->select('queue', 'q')
  ->condition('data', '%"job_id":' . $job_id . '%', 'LIKE')
  ->execute()
  ->fetchField();
```

**Problems:**
- Relying on JSON string patterns is brittle (JSON formatting changes break detection)
- Multiple sources of truth (queue table, suspended table, database) create sync issues
- Status synchronization happens mid-request (lines 1077-1087), not asynchronously
- No atomic operations - race conditions possible between checks and updates

**Recommendation:** Create a dedicated `QueueStatusService` with proper abstractions and atomic operations using transactions.

---

## 🟠 HIGH SEVERITY ISSUES

### 4. Monolithic Architecture - File Should Be Split (Lines 1-2190)

**Issue:** 2,190 lines in a single controller file violates Single Responsibility Principle

**Methods breakdown:**
- **Profile Management** (4 methods): `dashboard()`, `myProfile()`, `myProfileEdit()`, `viewJobSeekerProfile()`
- **Job Discovery** (5 methods): `startJobDiscovery()`, `companyJobDiscovery()`, `jobDiscoverySearch()`, `saveJob()`, `downloadResume()`
- **Resume Tailoring** (6 methods): `tailorResume()`, `tailorResumeAjax()`, `tailorResumeStatusAjax()`, `addSkillToProfileAjax()`, `refreshSkillsGapAjax()`, `deleteResume()`
- **Helper Methods** (5 private): `callGenAiTailoringService()`, `buildTailoredResumePrompt()`, `extractJsonFromResponse()`, `calculateSkillsGap()`, etc.

**Recommendation:** Create 3-4 separate classes:

1. **UserProfileController** (300 lines)
   - Dashboard, profile view/edit, redirect logic

2. **JobDiscoveryController** (400 lines)
   - Job discovery search, company discovery, job saving

3. **ResumeTailoringController** (500 lines)
   - Resume/cover letter tailoring AJAX endpoints

4. **ProfileService** (new)
   - Business logic: profile completeness, skills gap calculation
   - Profile data loading and transformation

---

### 5. Massive Duplicate Database Queries (Lines 1033-1100, 1150-1162, 1462+)

**Issue:** Same database queries repeated 6+ times with identical SELECT patterns

```php
// PATTERN REPEATED 6+ TIMES - Example occurrences:

// Line 1043-1045 (in tailorResume)
$job_data = $database->select('jobhunter_job_requirements', 'j')
  ->fields('j')
  ->condition('id', $job)
  ->execute()
  ->fetchObject();

// Line 1169-1171 (in tailorResumeAjax)
$job_data = $database->select('jobhunter_job_requirements', 'j')
  ->fields('j')
  ->condition('id', $job_id)
  ->execute()
  ->fetchObject();

// Line 1462 (in buildTailoredResumePrompt)
// Same pattern repeated
```

**Problems:**
- No caching strategy
- Violates DRY principle
- Makes refactoring difficult
- Inconsistent error handling

**Recommendation:** Create a dedicated `JobRequirementRepository` or `JobService`:

```php
class JobService {
  public function loadJobRequirements($job_id) {
    // Centralized, cached, with proper error handling
  }
  
  public function loadUserJobSeekerProfile($user_id) {
    // Centralized with caching
  }
}
```

---

### 6. Unsafe JSON Decoding Without Error Checking (Lines 1051-1053, 1115, 1193-1194)

**Issue:** JSON decoding without validation or error checking

```php
// Line 1051-1053 - NO ERROR CHECKING
$extracted = $job_data->extracted_json ? json_decode($job_data->extracted_json, TRUE) : [];
$skills = $job_data->skills_required_json ? json_decode($job_data->skills_required_json, TRUE) : [];
$keywords = $job_data->keywords_json ? json_decode($job_data->keywords_json, TRUE) : [];
```

**Problems:**
- `json_decode()` returns NULL on error, but code treats it as empty array
- Corrupted JSON silently becomes empty array, obscuring bugs
- No logging of JSON parse failures
- Downstream code assumes valid structure

**Recommendation:**
```php
private function safeJsonDecode($json_string, $context = 'unknown') {
  if (empty($json_string)) {
    return [];
  }
  
  $decoded = json_decode($json_string, TRUE);
  
  if (json_last_error() !== JSON_ERROR_NONE) {
    \Drupal::logger('job_hunter')->error(
      'JSON decode failed in @context: @error',
      ['@context' => $context, '@error' => json_last_error_msg()]
    );
    return [];
  }
  
  return $decoded ?: [];
}
```

---

### 7. Deprecated Code Not Removed (Lines 1445-1450)

**Issue:** Dead code pathway exists and is confusing

```php
// Line 1445-1450
private function callGenAiTailoringService(array $payload) {
  // DEPRECATED - This code path is never called.
  \Drupal::logger('job_hunter')->warning('DEPRECATED: ...');
  throw new \Exception('Direct GenAI tailoring is deprecated...');
}
```

**Problems:**
- Creates confusion for maintainers
- Takes up space and adds to complexity
- Should be removed or properly refactored

**Recommendation:** Delete this method entirely and document the architectural decision in comments or documentation.

---

## 🟡 MEDIUM SEVERITY ISSUES

### 8. Complex JSON Extraction Logic (Lines 1570-1630)

**Issue:** 60 lines of complex JSON extraction with brace counting is fragile

```php
// Line 1570-1630 - COMPLEX, FRAGILE LOGIC
for ($i = $start_pos; $i < $len; $i++) {
  $char = $response_text[$i];
  if ($escape_next) { ... }
  if ($char === '\\') { ... }
  if ($char === '"') { ... }
  // ... 40 more lines of state machine
}
```

**Problems:**
- Hard to test and maintain
- State machine logic is error-prone
- Handles edge cases inconsistently
- Should use established libraries

**Recommendation:** Use an existing JSON parser library or extract logic to a dedicated `JSONExtractionService`:

```php
class JSONExtractionService {
  public function extractJSON($text) {
    // Centralized, well-tested, documented
  }
}
```

---

### 9. Missing Direct Dependency Injection (Lines 65, 760-761, 1033, 1150, 1462)

**Issue:** Using `\Drupal::` static calls instead of constructor injection

```php
// Line 1033 - ANTI-PATTERN
$database = \Drupal::database();

// Line 760 - ANTI-PATTERN
$scraping_service = \Drupal::service('job_hunter.abbvie_job_scraping_service');

// Line 1462 - ANTI-PATTERN
$now = \Drupal::time()->getRequestTime();
```

**Problems:**
- Makes testing difficult (can't mock dependencies)
- Hides actual dependencies
- Couples controller to Drupal bootstrap

**Recommendation:** Inject all dependencies in constructor:

```php
protected DatabaseConnection $database;
protected ScrapeJobsService $scrapeService;
protected TimeInterface $timeService;

public function __construct(
  DatabaseConnection $database,
  ScrapeJobsService $scrape_service,
  TimeInterface $time_service,
  // ... other deps
) {
  $this->database = $database;
  $this->scrapeService = $scrape_service;
  $this->timeService = $time_service;
}
```

---

### 10. Inconsistent Error Handling & Logging (Lines 747-869)

**Issue:** Mix of different error handling patterns

```php
// Line 759 - JSON response with error
return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Invalid user ID'], 400);

// Line 1104 - Silent fallback
$profile_json = json_decode($job_seeker_profile->consolidated_profile_json, TRUE) ?: [];

// Line 109-115 - Exception throwing
throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
```

**Problems:**
- Three different error handling patterns in same file
- Some errors logged, some not
- Some return JSON, some throw exceptions
- Inconsistent HTTP status codes

**Recommendation:** Standardize on one approach (prefer exceptions for consistency with Drupal framework).

---

### 11. Overly Large Helper Methods (Lines 292-431, 1462-1569)

**Issue:** Private methods are too large and do too much

```php
// Lines 292-431 - buildProfileStats() is 140 lines
// Lines 1462-1569 - buildTailoredResumePrompt() generates 100+ line prompt
```

**Recommendation:** Break these into smaller, focused methods:

```php
// Instead of one 140-line method
private function buildProfileStats($user_entity) { ... }

// Create several small methods
private function buildEducationStats($user_entity) { }
private function buildExperienceStats($user_entity) { }
private function buildSkillsStats($user_entity) { }
```

---

### 12. Complex Nested Conditionals (Lines 1700-1787)

**Issue:** Deep nesting and complex logic in `calculateSkillsGap()`

```php
// Line 1700-1787 - Complex nested conditionals
private function calculateSkillsGap(array $job_skills, array $profile_json): array {
  $gap = [];
  foreach ($job_skills as $skill) {
    // ... 30+ lines of nested logic
    if (isset($skill['name'])) {
      $normalized_name = strtolower($skill['name']);
      if (!$this->skillExistsInProfile($normalized_name, $user_skills)) {
        // ... 20+ more lines
      }
    }
  }
  return $gap;
}
```

**Recommendation:** Extract to smaller methods, use early returns:

```php
private function calculateSkillsGap(array $job_skills, array $profile_json): array {
  $gap = [];
  foreach ($job_skills as $skill) {
    if ($this->isSkillMissing($skill, $profile_json)) {
      $gap[] = $this->formatSkillGap($skill);
    }
  }
  return $gap;
}

private function isSkillMissing($skill, $profile_json): bool { }
private function formatSkillGap($skill): array { }
```

---

### 13. No Caching of User/Profile Data (Throughout)

**Issue:** User profile loaded multiple times per request

```php
// Line 109 - Load in dashboard()
$user_entity = User::load($uid);

// Line 1033 - Load again in tailorResume()
$user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

// Line 1035+ - Load job seeker profile
$job_seeker_profile = $database->select('jobhunter_job_seeker', 'js')->...
```

**Problem:** Multiple loads cause N+1 query performance issues

**Recommendation:** Implement request-level caching or use Drupal's entity cache.

---

## 🔵 LOW SEVERITY ISSUES

### 14. Magic Numbers and Strings (Lines 1038, 1445, 1700)

**Issue:** Hard-coded values scattered throughout

```php
// Line 1038 - Magic number
$max_items = 10;  // Where does 10 come from?

// Line 1445 - Hard-coded retry limit
if ($retry_count >= 3) {  // Why 3?

// Line 1700 - Magic delay
sleep(1);  // Why 1 second?
```

**Recommendation:** Use constants:

```php
class UserProfileController {
  private const MAX_QUEUE_ITEMS = 10;
  private const MAX_RETRY_ATTEMPTS = 3;
  private const QUEUE_PROCESSING_DELAY = 1;
}
```

---

### 15. Incomplete Documentation (Lines 102-108, 578-589)

**Issue:** Some methods lack proper PHPDoc blocks

```php
// Line 102-108 - CREATE method missing param docs
public static function create(ContainerInterface $container) {
  // No @param, @return docs
}

// Line 605-637 - viewJobSeekerProfile() missing detailed docs
public function viewJobSeekerProfile() {
  // Single line description, no @return array
}
```

**Recommendation:** Add comprehensive PHPDoc to all public methods.

---

### 16. Potential Race Conditions in Queue Updates (Lines 1077-1087)

**Issue:** Non-atomic status updates

```php
// Lines 1077-1087 - Not atomic
if ($queue_status['should_update_db']) {
  $database->update('jobhunter_tailored_resumes')
    ->fields(['tailoring_status' => $queue_status['status'], 'updated' => time()])
    ->condition('uid', $user->id())
    ->condition('job_id', $job)
    ->execute();
  // Two requests could race here
}
```

**Recommendation:** Use database transactions:

```php
$transaction = $database->startTransaction();
try {
  if ($queue_status['should_update_db']) {
    $database->update('jobhunter_tailored_resumes')
      ->fields(['tailoring_status' => $queue_status['status'], 'updated' => time()])
      ->condition('uid', $user->id())
      ->condition('job_id', $job)
      ->execute();
  }
  $transaction->commit();
} catch (\Exception $e) {
  $transaction->rollBack();
  throw $e;
}
```

---

## Testing Challenges

### Current Issues

1. **No interfaces** - Can't create mock implementations
2. **Static service calls** - Can't inject mocks
3. **No separation of concerns** - Business logic mixed with HTTP handling
4. **Large methods** - Hard to test individual paths
5. **Complex dependencies** - 5+ dependencies in constructor

### Required Changes for Testability

1. Create interfaces for each service
2. Use constructor injection exclusively
3. Extract business logic to dedicated service classes
4. Keep controllers thin (HTTP handling only)
5. Use small, focused methods with single responsibility

### Example Test Structure

```php
class UserProfileControllerTest extends UnitTestCase {
  private UserProfileController $controller;
  private MockProfileService $profileService;
  private MockJobService $jobService;
  
  public function testDashboardReturnsUserData() {
    // Setup would be simple with proper injection
    $this->profileService->expects('calculateCompleteness')
      ->willReturn(75);
    
    $result = $this->controller->dashboard($mockUser);
    
    $this->assertNotEmpty($result);
  }
}
```

---

## Performance Issues

### Current Bottlenecks

1. **N+1 Queries** - User data loaded multiple times per request
2. **No query result caching** - Same job requirements queried 3-4 times
3. **Heavy JSON processing** - Large JSON decoded and encoded repeatedly
4. **No database indexes** - LIKE queries on JSON data are slow
5. **Synchronous processing** - Large operations happen in request lifecycle

### Recommendations

1. Add request-level caching for user/job data
2. Create database indexes on frequently queried columns
3. Cache JSON parsing results
4. Move heavy operations to queue workers
5. Implement query result batching

---

## Security Summary

### Vulnerabilities Found

| Issue | Line | Severity | Status |
|-------|------|----------|--------|
| SQL Injection in LIKE patterns | 2128-2134 | 🔴 Critical | Not Fixed |
| Weak input validation | 759-769 | 🔴 Critical | Not Fixed |
| Missing permission checks | 747-869 | 🟠 High | Not Fixed |
| Unsafe JSON decoding | 1051-1053 | 🟠 High | Not Fixed |
| Static service injection | Throughout | 🟠 High | Not Fixed |

---

## Refactoring Roadmap

### Phase 1: Extract Services (Priority 1)
- [ ] Create `JobService` - consolidate job queries
- [ ] Create `ProfileService` - profile business logic
- [ ] Create `SkillsGapService` - skills gap calculation
- [ ] Move all `\Drupal::service()` calls to constructor

### Phase 2: Security Fixes (Priority 1)
- [ ] Fix SQL injection in queue status check
- [ ] Add proper input validation
- [ ] Add permission checks
- [ ] Implement safe JSON decoding

### Phase 3: Split Controllers (Priority 2)
- [ ] Create `JobDiscoveryController`
- [ ] Create `ResumeTailoringController`
- [ ] Keep `UserProfileController` for profile-specific routes

### Phase 4: Improve Quality (Priority 3)
- [ ] Add caching layer
- [ ] Break down large methods
- [ ] Add comprehensive tests
- [ ] Performance optimization

---

## Summary Table

| Category | Issues | Severity |
|----------|--------|----------|
| Architecture | 2 | 🔴 Critical |
| Security | 3 | 🔴 Critical |
| Code Quality | 6 | 🟡 Medium |
| Performance | 2 | 🟡 Medium |
| Testing | 5 | 🟠 High |
| Documentation | 2 | 🔵 Low |
| **TOTAL** | **20** | **Mixed** |

---

## Recommended Next Steps

1. **Immediately:** Address SQL injection vulnerability (line 2128-2134)
2. **This Sprint:** Extract ProfileService and JobService
3. **This Sprint:** Add input validation and permission checks
4. **Next Sprint:** Split controller into 3 focused controllers
5. **Ongoing:** Add unit tests for extracted services

---

**Code Review Completed:** 2024  
**Reviewer Recommendation:** REFACTOR - This file is at critical complexity level and requires immediate architectural improvements.
