# Code Review: JobDiscoveryService.php

## Overview
The `JobDiscoveryService` provides utility functions for the job discovery page, including fetching user search defaults from profiles, checking API credentials status, retrieving saved jobs, and company information.

---

## ✅ Strengths

### 1. **User-Centric Design**
- Extracts search preferences from user profile
- Intelligently populates search defaults from saved preferences
- Handles missing/incomplete profile gracefully

### 2. **API Credential Status Checking**
- Comprehensive check of all external API services
- Boolean status for easy conditional UI rendering
- No exceptions thrown (returns false instead)

### 3. **Clean Query Building**
- Organized query conditions with filters
- Proper use of database join operations
- LEFT joins for optional relationships

### 4. **Comprehensive Error Handling**
- Try-catch blocks with logging on all methods
- Returns sensible defaults on failure (empty arrays, 0 count)
- Doesn't crash the page on database errors

---

## ⚠️ Issues & Recommendations

### 1. **No Input Validation for Filters** (MEDIUM)
**Current State:** `getSavedJobs()` accepts filters without validation

**Issue:** Invalid filter values could cause SQL errors.

**Recommendation:**
```php
public function getSavedJobs(array $filters = []): array {
    // Validate and sanitize filters
    $valid_filters = ['company', 'status', 'ai_status', 'tailoring'];
    $filters = array_intersect_key($filters, array_flip($valid_filters));
    
    // Validate status values if provided
    $valid_statuses = ['active', 'archived', 'rejected'];
    if (!empty($filters['status']) && !in_array($filters['status'], $valid_statuses)) {
        throw new \InvalidArgumentException('Invalid status filter: ' . $filters['status']);
    }
    
    // Validate AI status values if provided
    $valid_ai_statuses = ['pending', 'completed', 'failed'];
    if (!empty($filters['ai_status']) && !in_array($filters['ai_status'], $valid_ai_statuses)) {
        throw new \InvalidArgumentException('Invalid ai_status filter: ' . $filters['ai_status']);
    }
    
    // Validate tailoring status values if provided
    $valid_tailoring = ['not_started', 'in_progress', 'completed'];
    if (!empty($filters['tailoring']) && !in_array($filters['tailoring'], $valid_tailoring)) {
        throw new \InvalidArgumentException('Invalid tailoring filter: ' . $filters['tailoring']);
    }
    
    // ... rest of method
}
```

### 2. **JSON Decoding Without Validation** (MEDIUM)
**Current State:** Line 102 decodes JSON without checking result

**Issue:** Invalid JSON silently becomes empty array.

**Recommendation:**
```php
if (!empty($profile->consolidated_profile_json)) {
    $consolidated = json_decode($profile->consolidated_profile_json, TRUE);
    
    if ($consolidated === null && json_last_error() !== JSON_ERROR_NONE) {
        $this->loggerFactory->get('job_hunter')->warning(
            'Invalid profile JSON for user @uid: @error',
            ['@uid' => $this->currentUser->id(), '@error' => json_last_error_msg()]
        );
        $consolidated = [];
    }
} else {
    $consolidated = [];
}
```

### 3. **Numeric Coercion Without Validation** (MEDIUM)
**Current State:** Lines 138-142 coerce to int without checking bounds

**Issue:** Salary could be negative or unreasonably large.

**Recommendation:**
```php
// Get salary expectations with validation
$salary_min = $consolidated['job_search_preferences']['salary_expectation_min'] ?? '';
$salary_max = $consolidated['job_search_preferences']['salary_expectation_max'] ?? '';

if ($salary_min && is_numeric($salary_min)) {
    $salary_min_int = (int) $salary_min;
    if ($salary_min_int >= 15000 && $salary_min_int <= 500000) {
        $defaults['salary_min'] = $salary_min_int;
    } else {
        $this->loggerFactory->get('job_hunter')->warning(
            'Unusual salary_min for user @uid: @salary',
            ['@uid' => $this->currentUser->id(), '@salary' => $salary_min_int]
        );
    }
}

if ($salary_max && is_numeric($salary_max)) {
    $salary_max_int = (int) $salary_max;
    if ($salary_max_int >= 15000 && $salary_max_int <= 500000) {
        $defaults['salary_max'] = $salary_max_int;
    }
}
```

### 4. **No Result Count Limits** (LOW)
**Current State:** `getSavedJobs()` returns all results

**Issue:** Could be thousands of records, causing memory/performance issues.

**Recommendation:**
```php
private const MAX_SAVED_JOBS = 500;

public function getSavedJobs(array $filters = []): array {
    try {
        $query = $this->database->select('jobhunter_job_requirements', 'j')
            ->fields('j');
        
        // ... join and filter logic ...
        
        // Add limit for safety
        $query->range(0, self::MAX_SAVED_JOBS);
        
        $results = $query->execute()->fetchAll();
        
        if (count($results) === self::MAX_SAVED_JOBS) {
            $this->loggerFactory->get('job_hunter')->warning(
                'User @uid has more than @max saved jobs',
                ['@uid' => $this->currentUser->id(), '@max' => self::MAX_SAVED_JOBS]
            );
        }
        
        // ... rest of code
    }
}
```

### 5. **Missing Type Hints** (MEDIUM)
**Current State:**
```php
public function getUserSearchDefaults(): array
public function getApiCredentialsStatus(): array
```

**Issue:** Could be more specific about returned structure.

**Recommendation:**
```php
/**
 * @return array{
 *   keywords: string,
 *   location: string,
 *   remote_pref: string,
 *   salary_min: int|string,
 *   salary_max: int|string,
 *   employment_type: string,
 *   relocation: string
 * }
 */
public function getUserSearchDefaults(): array

/**
 * @return array{
 *   google_cloud: bool,
 *   adzuna: bool,
 *   usajobs: bool,
 *   serpapi: bool
 * }
 */
public function getApiCredentialsStatus(): array
```

### 6. **No Caching of Expensive Operations** (LOW)
**Current State:** Database queries execute every time

**Issue:** Same data queried repeatedly within short time spans.

**Recommendation:**
```php
private const CACHE_DURATION = 300; // 5 minutes

public function getSavedJobsCount(): int {
    try {
        $cache_key = 'saved_jobs_count:' . $this->currentUser->id();
        
        if ($cache = \Drupal::cache('job_hunter_results')->get($cache_key)) {
            return (int) $cache->data;
        }
        
        $count = (int) $this->database->select('jobhunter_job_requirements', 'j')
            ->condition('uid', $this->currentUser->id())
            ->countQuery()
            ->execute()
            ->fetchField();
        
        \Drupal::cache('job_hunter_results')->set(
            $cache_key,
            $count,
            time() + self::CACHE_DURATION
        );
        
        return $count;
    }
    catch (\Exception $e) {
        $this->loggerFactory->get('job_hunter')->error(
            'Error counting saved jobs: @error',
            ['@error' => $e->getMessage()]
        );
        return 0;
    }
}
```

### 7. **Inconsistent JSON Decoding** (LOW)
**Current State:** Lines 307-312 decode JSON fields

**Issue:** Same pattern repeated; should be centralized.

**Recommendation:**
```php
private function decodeJobJson(&$job): void {
    if (!empty($job->extracted_json)) {
        $decoded = json_decode($job->extracted_json, TRUE);
        if ($decoded !== null) {
            $job->extracted_data = $decoded;
        } else {
            $this->loggerFactory->get('job_hunter')->warning(
                'Invalid extracted_json for job @id',
                ['@id' => $job->id]
            );
            $job->extracted_data = [];
        }
    }
    
    if (!empty($job->tailored_resume_json)) {
        $decoded = json_decode($job->tailored_resume_json, TRUE);
        if ($decoded !== null) {
            $job->tailored_data = $decoded;
        } else {
            $job->tailored_data = [];
        }
    }
}

public function getSavedJobs(array $filters = []): array {
    // ... query logic ...
    
    $results = $query->execute()->fetchAll();
    
    // Decode JSON fields
    foreach ($results as $job) {
        $this->decodeJobJson($job);
    }
    
    return $results;
}
```

### 8. **Missing Access Control Check** (HIGH)
**Current State:** No verification that user owns the jobs/companies

**Issue:** Could access other users' data if direct ID passed.

**Recommendation:**
```php
public function getSavedJobs(array $filters = []): array {
    try {
        $uid = $this->currentUser->id();
        
        $query = $this->database->select('jobhunter_job_requirements', 'j')
            ->fields('j');
        
        // CRITICAL: Filter by current user
        $query->condition('j.uid', $uid);
        
        // ... rest of query ...
    }
}

public function getTargetCompaniesCount(): int {
    try {
        return (int) $this->database->select('jobhunter_companies', 'c')
            ->condition('uid', $this->currentUser->id()) // Already filtered
            ->countQuery()
            ->execute()
            ->fetchField();
    }
}
```

---

## 🔍 Testing Considerations

1. **Unit Tests:**
   - Test with complete profile data
   - Test with partial profile data
   - Test with no profile data
   - Test with invalid JSON
   - Test API credential combinations

2. **Integration Tests:**
   - Test with real database
   - Test user data isolation
   - Test with various saved job/company counts

3. **Edge Cases:**
   - User with no profile
   - Invalid JSON in profile
   - Missing optional fields
   - Boundary salary values
   - No saved jobs
   - No target companies
   - No API credentials

---

## 📋 Summary

| Category | Status | Priority |
|----------|--------|----------|
| Error Handling | ✅ Good | - |
| User Experience | ✅ Good | - |
| Access Control | ❌ Missing | HIGH |
| Input Validation | ⚠️ Partial | MEDIUM |
| JSON Handling | ⚠️ Weak | MEDIUM |
| Type Safety | ⚠️ Partial | MEDIUM |
| Performance | ⚠️ No Limits | LOW |
| Caching | ❌ Missing | LOW |

---

## Action Items

- [ ] Add access control checks for all user-specific queries
- [ ] Add input validation for filter parameters
- [ ] Improve JSON decoding with error checking
- [ ] Add salary value range validation
- [ ] Add result count limits
- [ ] Implement caching for expensive queries
- [ ] Add return type hints with array structures
- [ ] Centralize JSON decoding logic
- [ ] Create comprehensive unit tests
- [ ] Document expected profile JSON structure
