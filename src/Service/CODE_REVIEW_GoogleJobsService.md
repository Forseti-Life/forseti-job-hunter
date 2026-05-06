# Code Review: GoogleJobsService.php

## Overview
The `GoogleJobsService` generates Schema.org JobPosting JSON-LD structured data for Google for Jobs integration. It normalizes job data and provides validation capabilities for search engine inclusion.

---

## ✅ Strengths

### 1. **SEO/Schema.org Implementation**
- Proper Schema.org JobPosting structure (RFC 3986)
- Comprehensive field mapping for Google Jobs requirements
- Valid structured data that Google can parse

### 2. **Comprehensive Validation**
- Checks required fields (lines 328-333)
- Validates date formats (lines 354-359)
- Validates employment types (lines 362-369)
- Provides validation warnings for recommendations

### 3. **Proper Data Transformation**
- Safe JSON decoding with error handling
- Dot-notation path helpers for nested data access
- Multiple employment type format support

### 4. **Rich Data Support**
- Salary information with currency/units
- Education requirements
- Experience requirements (converts to months)
- Skills extraction from multiple sources

---

## ⚠️ Issues & Recommendations

### 1. **Missing Input Validation** (MEDIUM)
**Current State:** No validation of job_id parameter

**Issue:** Invalid IDs silently fail.

**Recommendation:**
```php
public function generateJobPostingJsonLd($job_id) {
    // Validate job_id
    if (!is_numeric($job_id) || $job_id <= 0) {
        throw new \InvalidArgumentException('Invalid job_id: must be positive integer');
    }
    
    $job_id = (int) $job_id;
    
    // Get job data
    $job = $this->database->select('jobhunter_job_requirements', 'j')
        ->fields('j')
        ->condition('id', $job_id)
        ->execute()
        ->fetchObject();
    
    if (!$job) {
        throw new \Exception("Job not found with ID: $job_id");
    }
    
    // ... rest of code
}
```

### 2. **Sanitization Incomplete** (MEDIUM)
**Current State:** `sanitizeDescription()` only normalizes whitespace

**Issue:** No HTML stripping or entity encoding.

**Recommendation:**
```php
protected function sanitizeDescription($description) {
    // Remove HTML tags
    $description = strip_tags($description);
    
    // Normalize whitespace
    $description = preg_replace('/\s+/', ' ', $description);
    
    // Trim excess length for Google (max 5000 chars recommended)
    $description = substr($description, 0, 5000);
    
    // Ensure minimum length
    if (strlen(trim($description)) < 50) {
        $description = trim($description) . ' Full job details available on our careers page.';
    }
    
    return trim($description);
}
```

### 3. **No Caching of Generated JSON-LD** (LOW)
**Current State:** Regenerates JSON-LD on every request

**Issue:** Inefficient for frequently viewed jobs.

**Recommendation:**
```php
private const CACHE_DURATION = 86400; // 24 hours

public function generateJobPostingJsonLd($job_id): array {
    $job_id = (int) $job_id;
    
    // Check cache
    $cache_key = 'job_posting_jsonld:' . $job_id;
    if ($cache = \Drupal::cache('job_hunter_results')->get($cache_key)) {
        return $cache->data;
    }
    
    // ... generate JSON-LD ...
    
    // Cache the result
    \Drupal::cache('job_hunter_results')->set(
        $cache_key,
        $json_ld,
        time() + self::CACHE_DURATION
    );
    
    return $json_ld;
}
```

### 4. **Date Format Inconsistent** (MEDIUM)
**Current State:**
- `datePosted` uses 'Y-m-d' format (line 102)
- `validThrough` uses ISO 8601 with Z suffix (lines 107, 111)

**Issue:** Format inconsistency may cause validation issues.

**Recommendation:**
```php
// Use ISO 8601 format consistently for all dates
$json_ld = [
    // ...
    'datePosted' => date('Y-m-d\TH:i:s\Z', strtotime($job->created_at)),
    'validThrough' => date('Y-m-d\TH:i:s\Z', 
                           strtotime($job->valid_through ?? '+30 days')),
    // ...
];
```

### 5. **Country Default Missing Context** (LOW)
**Current State:** Defaults to 'US' (line 258)

**Issue:** Should be configurable or logged.

**Recommendation:**
```php
protected function buildJobLocation($location_data, $extracted_data) {
    // ...
    
    if (!empty($location_data['country'])) {
        $location['address']['addressCountry'] = $location_data['country'];
    }
    elseif (!empty($extracted_data['country'])) {
        $location['address']['addressCountry'] = $extracted_data['country'];
    }
    else {
        // Use config default or log warning
        $default_country = \Drupal::config('job_hunter.settings')
            ->get('default_country') ?? 'US';
        
        if ($default_country !== 'US') {
            $this->logger->notice(
                'Job @id defaulting country to @country',
                ['@id' => $job->id ?? 'unknown', '@country' => $default_country]
            );
        }
        
        $location['address']['addressCountry'] = $default_country;
    }
    
    // ...
}
```

### 6. **Employment Type Mapping Not Exhaustive** (LOW)
**Current State:** Maps to pre-defined array

**Issue:** Unmapped values silently omitted.

**Recommendation:**
```php
protected function mapEmploymentType($type) {
    $valid_types = [
        'FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY',
        'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER'
    ];
    
    if (is_array($type)) {
        $mapped = array_intersect($type, $valid_types);
        if (count($mapped) < count($type)) {
            $this->logger->warning(
                'Some employment types not recognized: @types',
                ['@types' => implode(', ', array_diff($type, $valid_types))]
            );
        }
        return $mapped;
    }
    
    $type = strtoupper(trim($type ?? ''));
    if (in_array($type, $valid_types)) {
        return [$type];
    }
    
    $this->logger->warning('Unknown employment type: @type', ['@type' => $type]);
    return ['FULL_TIME']; // Fallback with warning
}
```

### 7. **Missing Database Error Handling** (MEDIUM)
**Current State:** No try-catch for database queries

**Issue:** Database errors cause crashes.

**Recommendation:**
```php
public function generateJobPostingJsonLd($job_id) {
    // Validate job_id
    if (!is_numeric($job_id) || $job_id <= 0) {
        throw new \InvalidArgumentException('Invalid job_id: must be positive integer');
    }

    $job_id = (int) $job_id;

    try {
        // Get job data
        $job = $this->database->select('jobhunter_job_requirements', 'j')
            ->fields('j')
            ->condition('id', $job_id)
            ->execute()
            ->fetchObject();

        if (!$job) {
            throw new \Exception("Job not found with ID: $job_id");
        }

        // Get company data
        $company = $this->database->select('jobhunter_companies', 'c')
            ->fields('c')
            ->condition('id', $job->company_id)
            ->execute()
            ->fetchObject();

        if (!$company) {
            throw new \Exception("Company not found for job ID: $job_id");
        }
        
        // ... rest of code
        
    } catch (\Exception $e) {
        $this->logger->error(
            'Failed to generate JSON-LD for job @id: @error',
            ['@id' => $job_id, '@error' => $e->getMessage()]
        );
        throw $e;
    }
}
```

### 8. **Type Hints Missing** (MEDIUM)
**Current State:**
```php
public function generateJobPostingJsonLd($job_id)
public function validateJobPosting($job_id)
```

**Issue:** No type hints on parameters or returns.

**Recommendation:**
```php
/**
 * @return array The JSON-LD structured data
 * @throws \Exception If job not found or generation fails
 */
public function generateJobPostingJsonLd(int $job_id): array

/**
 * @return array{status: string, errors: array, warnings: array, structured_data?: array}
 */
public function validateJobPosting(int $job_id): array
```

### 9. **Application URL Format** (LOW)
**Current State:** Uses route-based URL (line 174)

**Issue:** May not be appropriate for external applicants.

**Recommendation:**
```php
// Check if external URL exists, use that first
if (!empty($extracted_data['application_url'])) {
    $json_ld['url'] = $extracted_data['application_url'];
} else {
    // Fallback to internal job view page
    $json_ld['url'] = Url::fromRoute(
        'job_hunter.job_view',
        ['job_id' => $job_id],
        ['absolute' => TRUE]
    )->toString();
}
```

### 10. **Missing Salary Validation** (LOW)
**Current State:** No validation of salary values

**Recommendation:**
```php
protected function buildSalaryData($salary_data) {
    // Validate values
    if (!empty($salary_data['min']) && !is_numeric($salary_data['min'])) {
        $this->logger->warning('Invalid min salary: @value', 
                              ['@value' => $salary_data['min']]);
        unset($salary_data['min']);
    }
    
    if (!empty($salary_data['max']) && !is_numeric($salary_data['max'])) {
        $this->logger->warning('Invalid max salary: @value',
                              ['@value' => $salary_data['max']]);
        unset($salary_data['max']);
    }
    
    if (isset($salary_data['min']) && isset($salary_data['max'])) {
        if ((int)$salary_data['min'] > (int)$salary_data['max']) {
            $this->logger->warning('Salary min > max, swapping values');
            [$salary_data['min'], $salary_data['max']] = 
                [$salary_data['max'], $salary_data['min']];
        }
    }
    
    // ... rest of code
}
```

---

## 🔍 Testing Considerations

1. **Unit Tests:**
   - Test JSON-LD generation for various job types
   - Test validation with valid/invalid data
   - Test employment type mapping
   - Test location building
   - Test salary formatting

2. **Schema.org Validation:**
   - Use Google's Rich Results Test (https://search.google.com/test/rich-results)
   - Validate against Schema.org spec

3. **Edge Cases:**
   - Missing optional fields
   - Special characters in job title/description
   - Very long descriptions
   - Invalid date formats
   - Missing company
   - Missing location data

---

## 📋 Summary

| Category | Status | Priority |
|----------|--------|----------|
| Schema.org Compliance | ✅ Good | - |
| Validation | ✅ Good | - |
| Sanitization | ⚠️ Partial | MEDIUM |
| Error Handling | ⚠️ Missing | MEDIUM |
| Input Validation | ⚠️ Missing | MEDIUM |
| Type Safety | ⚠️ Partial | MEDIUM |
| Caching | ❌ Missing | LOW |
| Data Completeness | ⚠️ Defaults | LOW |

---

## Action Items

- [ ] Add input validation for job_id
- [ ] Improve HTML sanitization for descriptions
- [ ] Add try-catch for database queries
- [ ] Add salary value validation
- [ ] Make country default configurable
- [ ] Implement caching for JSON-LD output
- [ ] Add return type hints to all methods
- [ ] Add comprehensive unit tests
- [ ] Validate generated JSON-LD with Google Rich Results Test
- [ ] Add logging for unmapped/defaulted values
