# Code Review: UserProfileService.php

## Overview
The `UserProfileService` handles user profile validation and completeness calculation for job seekers. It measures profile readiness for job applications and provides recommendations for improving profile quality.

---

## ✅ Strengths

### 1. **Weighted Field Analysis**
- FIELD_WEIGHTS constant defines importance of each field
- Profile completeness calculated as weighted percentage (0-100)
- Granular field-specific checking

### 2. **Comprehensive Validation**
- Critical requirements checking (resume, work authorization)
- Data quality checks (salary ranges, URLs)
- Professional presence validation (LinkedIn, GitHub)
- Multi-level validation (errors, warnings, recommendations)

### 3. **User-Friendly Output**
- Completeness status with human-readable messages
- Separate errors (blocking), warnings (cautions), recommendations (nice-to-have)
- Application readiness score

### 4. **Proper String Translation**
- Uses `StringTranslationTrait` for i18n
- All user messages translatable

---

## ⚠️ Issues & Recommendations

### 1. **Critical: Hard-coded Database Column Mapping** (MEDIUM)
**Current State:** Lines 339-353 map field names to database columns manually

**Issue:** If database schema changes, mappings must be updated manually with no validation.

**Recommendation:**
```php
/**
 * Maps Drupal user fields to job seeker profile database columns.
 * 
 * This mapping is used to check profile completeness from the
 * jobhunter_job_seeker table instead of the user entity.
 */
private function getFieldMapping(): array {
    return [
        'field_resume_file' => 'resume_node_id',
        'field_work_authorization' => 'work_authorization',
        'field_professional_summary' => 'professional_summary',
        'field_skills_summary' => 'skills',
        'field_experience_years' => 'experience_years',
        'field_education_level' => 'education_level',
        'field_remote_preference' => 'remote_preference',
        'field_linkedin_url' => 'linkedin_url',
        'field_salary_expectation_min' => 'salary_expectation',
        'field_available_start_date' => 'availability',
        'field_portfolio_url' => 'portfolio_url',
        'field_github_url' => 'github_url',
        'field_certifications' => 'certifications',
    ];
}

protected function isJobSeekerFieldCompleted($jobSeekerData, $field_name) {
    $mapping = $this->getFieldMapping();
    
    if (!isset($mapping[$field_name])) {
        return false;
    }
    
    $db_field = $mapping[$field_name];
    $value = $jobSeekerData->$db_field ?? null;
    
    return !empty($value);
}
```

### 2. **Missing Error Handling** (MEDIUM)
**Current State:** No try-catch for service access

**Issue:** If `job_hunter.job_seeker_service` fails to load, exception not caught.

**Recommendation:**
```php
public function calculateProfileCompleteness(User $user) {
    try {
        // Get data from jobhunter_job_seeker table
        $jobSeekerService = \Drupal::service('job_hunter.job_seeker_service');
        $jobSeekerData = $jobSeekerService->loadByUserId($user->id());
    } catch (\Exception $e) {
        \Drupal::logger('job_hunter')->error(
            'Failed to load job seeker profile for user @uid: @error',
            ['@uid' => $user->id(), '@error' => $e->getMessage()]
        );
        return 0;
    }
    
    if (!$jobSeekerData) {
        return 0;
    }
    
    // ... rest of method
}
```

### 3. **Misleading Method Names** (MEDIUM)
**Current State:** Methods have confusing names and behaviors:
- `isFieldCompleted()` works on User entity
- `isJobSeekerFieldCompleted()` works on database object
- `calculateProfileCompleteness()` mixes both

**Issue:** Hard to know which method to use when.

**Recommendation:**
```php
/**
 * Calculates profile completeness from the job seeker profile table.
 * 
 * This is the primary method for getting profile completeness.
 * It uses the jobhunter_job_seeker table, not the user entity.
 */
public function calculateProfileCompleteness(User $user): int {
    // Use job seeker profile table
    // ... existing implementation
}

/**
 * Checks if a field is completed on the user entity (legacy).
 * 
 * @deprecated Use profile-based methods instead
 */
public function isFieldCompleted(User $user, $field_name): bool {
    // ... existing implementation
}
```

### 4. **Type Hints Missing** (MEDIUM)
**Current State:**
```php
public function calculateProfileCompleteness(User $user)
public function isFieldCompleted(User $user, $field_name)
```

**Issue:** No return type hints.

**Recommendation:**
```php
public function calculateProfileCompleteness(User $user): int
public function isFieldCompleted(User $user, string $field_name): bool
public function getCompletenessStatus(int $completeness): array
public function validateForJobApplication(User $user): array
public function getProfileStats(User $user): array
```

### 5. **URL Validation Fragile** (MEDIUM)
**Current State:** Lines 231-233 use regex for LinkedIn/GitHub URLs

**Issue:** Regex could miss valid URLs or accept invalid ones.

**Recommendation:**
```php
private function validateLinkedInUrl(string $url): bool {
    $url = trim($url);
    
    // Valid LinkedIn profile URL formats:
    // - https://www.linkedin.com/in/username
    // - https://linkedin.com/in/username
    // - linkedin.com/in/username
    
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    return (bool) preg_match(
        '/^https?:\/\/(www\.)?linkedin\.com\/in\/[a-zA-Z0-9-]+\/?$/',
        $url
    );
}

private function validateGitHubUrl(string $url): bool {
    $url = trim($url);
    
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    // Valid GitHub URLs:
    // - https://github.com/username
    // - https://github.com/username/repo
    
    return (bool) preg_match(
        '/^https?:\/\/(www\.)?github\.com\/[a-zA-Z0-9-]+\/?[a-zA-Z0-9\/-]*\/?$/',
        $url
    );
}

if ($this->isFieldCompleted($user, 'field_linkedin_url')) {
    $linkedin_url = $user->get('field_linkedin_url')->uri;
    if (!$this->validateLinkedInUrl($linkedin_url)) {
        $warnings[] = $this->t(
            'LinkedIn URL should be a valid profile link (linkedin.com/in/yourname).'
        );
    }
}
```

### 6. **Salary Bounds Too Loose** (LOW)
**Current State:** Lines 223-225 check if $20k-$500k range

**Issue:** Doesn't account for different job levels or countries.

**Recommendation:**
```php
private function validateSalaryRange(int $min, int $max, array &$warnings): bool {
    // Check min < max (already done elsewhere)
    if ($min >= $max) {
        return false;
    }
    
    // Entry level: typically $25k-$70k
    // Mid-level: typically $60k-$150k  
    // Senior: typically $120k-$250k+
    
    // Warn on extremes but don't fail
    $min_reasonable = 20000;
    $max_reasonable = 1000000; // $1M+ for executive roles
    
    if ($min < $min_reasonable) {
        $warnings[] = $this->t(
            'Minimum salary of @min seems very low. Is this correct?',
            ['@min' => number_format($min)]
        );
    }
    
    if ($max > $max_reasonable) {
        $warnings[] = $this->t(
            'Maximum salary of @max seems very high. Is this correct?',
            ['@max' => number_format($max)]
        );
    }
    
    // Warn if range too small
    $range = $max - $min;
    if ($range < 5000) {
        $warnings[] = $this->t(
            'Salary range is very narrow (@range). Consider wider range for flexibility.',
            ['@range' => number_format($range)]
        );
    }
    
    return true;
}
```

### 7. **Readiness Score Calculation Unclear** (MEDIUM)
**Current State:** `calculateApplicationReadinessScore()` method referenced but not defined

**Issue:** Method called but not implemented.

**Recommendation:**
```php
/**
 * Calculates application readiness score (0-100).
 * 
 * Based on:
 * - Profile completeness (40% weight)
 * - Number of errors (blocking issues)
 * - Number of warnings
 * 
 * @return int Score from 0-100
 */
private function calculateApplicationReadinessScore(
    User $user,
    int $completeness,
    array $errors,
    array $warnings
): int {
    $score = 0;
    
    // Completeness portion (40%)
    $score += ($completeness / 100) * 40;
    
    // Error penalty (20 points per error, max -50)
    $error_penalty = min(50, count($errors) * 20);
    $score -= $error_penalty;
    
    // Warning penalty (5 points per warning, max -30)
    $warning_penalty = min(30, count($warnings) * 5);
    $score -= $warning_penalty;
    
    // Critical fields bonus (20%)
    $critical_fields = [
        'field_resume_file',
        'field_work_authorization',
        'field_professional_summary',
    ];
    
    $critical_score = 0;
    foreach ($critical_fields as $field) {
        if ($this->isJobSeekerFieldCompleted(
            $this->getJobSeekerProfile($user),
            $field
        )) {
            $critical_score += (20 / 3);
        }
    }
    
    $score += $critical_score;
    
    // Clamp to 0-100
    return max(0, min(100, (int) round($score)));
}
```

### 8. **No Logging of Profile Changes** (LOW)
**Current State:** No audit trail of profile updates

**Issue:** Can't track when/how profile completeness changed.

**Recommendation:**
```php
public function updateProfileCompleteness(User $user, $save = TRUE): int {
    $old_completeness = null;
    if ($user->hasField('field_profile_completeness')) {
        $old_completeness = (int) $user->get('field_profile_completeness')->value;
    }
    
    // ... calculate new completeness ...
    
    if ($old_completeness !== null && $old_completeness !== $new_completeness) {
        \Drupal::logger('job_hunter')->info(
            'Profile completeness updated for user @uid: @old% → @new%',
            [
                '@uid' => $user->id(),
                '@old' => $old_completeness,
                '@new' => $new_completeness,
            ]
        );
    }
    
    return $new_completeness;
}
```

### 9. **No Caching of Completeness** (LOW)
**Current State:** Calculates completeness every request

**Issue:** Database queries repeated unnecessarily.

**Recommendation:**
```php
private const CACHE_DURATION = 3600; // 1 hour

public function calculateProfileCompleteness(User $user): int {
    $cache_key = 'profile_completeness:' . $user->id();
    
    if ($cache = \Drupal::cache('job_hunter_results')->get($cache_key)) {
        return (int) $cache->data;
    }
    
    // ... calculate completeness ...
    
    \Drupal::cache('job_hunter_results')->set(
        $cache_key,
        $completeness,
        time() + self::CACHE_DURATION
    );
    
    return $completeness;
}
```

### 10. **Validation Logic Deeply Nested** (LOW)
**Current State:** `validateForJobApplication()` has many nested if-else chains

**Issue:** Hard to maintain and extend.

**Recommendation:**
```php
public function validateForJobApplicationFromProfile(User $user): array {
    $profile = $this->getJobSeekerProfile($user);
    $completeness = $this->calculateProfileCompletenessFromProfile($user);
    
    $errors = [];
    $warnings = [];
    $recommendations = [];

    // Use dedicated validation methods
    $this->validateCriticalRequirements($profile, $user, $errors);
    $this->validateContactInformation($user, $errors);
    $this->validateDataQuality($user, $warnings);
    $this->validateProfessionalPresence($user, $warnings);
    $this->validateRecommendedFields($user, $recommendations);
    $this->validateCompleteness($completeness, $errors, $warnings);

    $readiness_score = $this->calculateApplicationReadinessScore(
        $user,
        $completeness,
        $errors,
        $warnings
    );

    return [
        'ready' => empty($errors),
        'completeness' => $completeness,
        'readiness_score' => $readiness_score,
        'errors' => $errors,
        'warnings' => $warnings,
        'recommendations' => $recommendations,
    ];
}

private function validateCriticalRequirements(
    $profile,
    User $user,
    array &$errors
): void {
    if (!$profile || !$this->isProfileFieldCompleted($profile, 'field_resume_file')) {
        $errors[] = $this->t('Resume upload is required - employers need to see your qualifications.');
    }

    if (!$profile || !$this->isProfileFieldCompleted($profile, 'field_work_authorization')) {
        $errors[] = $this->t('Work authorization status is required - employers must verify eligibility.');
    }
}

// ... more validation methods ...
```

---

## 🔍 Testing Considerations

1. **Unit Tests:**
   - Test profile completeness calculation
   - Test field completeness checks
   - Test validation with various scenarios
   - Test readiness score calculation
   - Test URL validation

2. **Integration Tests:**
   - Test with real user entity
   - Test with job seeker profile data
   - Test profile updates

3. **Edge Cases:**
   - User with no profile
   - User with partial profile
   - Invalid URLs
   - Boundary salary values
   - Missing optional fields

---

## 📋 Summary

| Category | Status | Priority |
|----------|--------|----------|
| Validation Logic | ✅ Comprehensive | - |
| User Messages | ✅ Good | - |
| Weights System | ✅ Flexible | - |
| Error Handling | ⚠️ Missing | MEDIUM |
| Type Safety | ⚠️ Partial | MEDIUM |
| URL Validation | ⚠️ Fragile | MEDIUM |
| Method Organization | ⚠️ Confusing | MEDIUM |
| Caching | ❌ Missing | LOW |
| Audit Trail | ❌ Missing | LOW |

---

## Action Items

- [ ] Add error handling for service dependencies
- [ ] Add return type hints to all methods
- [ ] Improve URL validation with dedicated methods
- [ ] Implement readiness score calculation method
- [ ] Refactor validation logic into separate methods
- [ ] Implement caching for profile completeness
- [ ] Add audit logging for profile changes
- [ ] Clarify field mapping documentation
- [ ] Make readiness score calculation configurable
- [ ] Create comprehensive unit tests
