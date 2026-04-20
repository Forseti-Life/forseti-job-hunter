# Code Review: AdzunaApiService.php

## Overview
The `AdzunaApiService` integrates with the Adzuna job API, which aggregates jobs from multiple sources. It handles authentication, parameter mapping, and response normalization.

---

## ✅ Strengths

### 1. **Clear Credential Management**
- Credentials fetched from config factory (not hard-coded)
- Validates credentials exist before making request
- Throws descriptive exception with help text (lines 79-81)

### 2. **Comprehensive Parameter Mapping**
- Maps employment types to Adzuna API format (lines 111-120)
- Handles salary ranges properly (lines 101-107)
- Query parameter building is clear and organized

### 3. **Good Error Handling**
- Catches `RequestException` specifically
- Extracts error body from response when available
- Includes error body in logging for debugging

### 4. **Simple, Focused Implementation**
- Single responsibility: search jobs via Adzuna
- No unnecessary complexity
- Clean constructor and method signatures

---

## ⚠️ Issues & Recommendations

### 1. **Missing Pagination Support** (MEDIUM)
**Current State:** Pagination handled by caller, but no validation

**Issue:** Page numbers could be invalid, leading to wasted API calls.

**Recommendation:**
```php
public function searchJobs(array $params) {
    $config = $this->configFactory->get('job_hunter.settings');
    $app_id = $config->get('adzuna_app_id');
    $app_key = $config->get('adzuna_app_key');

    if (empty($app_id) || empty($app_key)) {
        throw new \Exception('Adzuna API credentials not configured. 
            Get your free API keys at https://developer.adzuna.com/');
    }

    // Validate and set pagination
    $page = max(1, (int) ($params['page'] ?? 1));
    $results_per_page = min(50, max(1, (int) ($params['results_per_page'] ?? 10)));

    $query_params = [
        'app_id' => $app_id,
        'app_key' => $app_key,
        'results_per_page' => $results_per_page,
        'page' => $page,
    ];
    
    // ... rest of code
}
```

### 2. **No Rate Limiting** (HIGH)
**Current State:** No protection against exceeding 250 calls/month limit

**Issue:** Easy to exhaust free tier quota quickly.

**Recommendation:**
```php
private const FREE_TIER_MONTHLY_LIMIT = 250;

public function searchJobs(array $params) {
    // ... existing credential checks ...
    
    // Check rate limit
    $this->enforceRateLimit();
    
    // ... rest of code
}

private function enforceRateLimit(): void {
    $store = \Drupal::keyValueExpirable('job_hunter');
    $month_key = 'adzuna_calls_' . date('Y-m');
    
    $count = $store->get($month_key) ?? 0;
    
    if ($count >= self::FREE_TIER_MONTHLY_LIMIT) {
        throw new \Exception(
            'Adzuna API monthly limit (' . self::FREE_TIER_MONTHLY_LIMIT . 
            ' calls) exceeded. Limit resets on the 1st of next month.'
        );
    }
    
    // Increment counter (reset on 1st of month)
    $expiry = strtotime('first day of next month') - time();
    $store->setWithExpire($month_key, $count + 1, $expiry);
}
```

### 3. **Fragile API URL Construction** (MEDIUM)
**Current State:**
```php
$url = self::API_BASE_URL . '/1?' . http_build_query($query_params);
```

**Issue:** Hard-coded `/1` endpoint version is version-dependent.

**Recommendation:**
```php
private const API_VERSION = '1';

private function buildApiUrl(array $query_params): string {
    $version = $this->configFactory->get('job_hunter.settings')
        ->get('adzuna_api_version') ?? self::API_VERSION;
    
    return self::API_BASE_URL . '/' . $version . '?' . http_build_query($query_params);
}

public function searchJobs(array $params) {
    // ...
    $url = $this->buildApiUrl($query_params);
    // ...
}
```

### 4. **Missing Input Validation** (MEDIUM)
**Current State:** No validation of input parameters

**Issue:** Invalid parameters passed silently to API.

**Recommendation:**
```php
public function searchJobs(array $params) {
    // Validate required params
    if (empty($params['query']) && empty($params['location'])) {
        throw new \InvalidArgumentException(
            'At least one of query or location is required'
        );
    }
    
    // Validate optional params
    if (isset($params['salary_min']) && !is_numeric($params['salary_min'])) {
        throw new \InvalidArgumentException('salary_min must be numeric');
    }
    
    if (isset($params['salary_min']) && isset($params['salary_max'])) {
        if ($params['salary_min'] > $params['salary_max']) {
            throw new \InvalidArgumentException(
                'salary_min cannot be greater than salary_max'
            );
        }
    }
    
    // Validate employment type
    $valid_types = ['FULL_TIME', 'PART_TIME', 'CONTRACT', 'TEMPORARY'];
    if (isset($params['employment_type']) && 
        !in_array($params['employment_type'], $valid_types)) {
        throw new \InvalidArgumentException(
            'Invalid employment_type. Valid values: ' . implode(', ', $valid_types)
        );
    }
    
    // ... rest of method
}
```

### 5. **Missing Response Validation** (MEDIUM)
**Current State:** Assumes response is always valid JSON

**Issue:** API could return non-JSON responses (HTML error page, etc.)

**Recommendation:**
```php
try {
    $url = self::API_BASE_URL . '/1?' . http_build_query($query_params);
    
    $response = $this->httpClient->request('GET', $url, ['timeout' => 10]);
    
    // Validate response status
    if ($response->getStatusCode() !== 200) {
        throw new \Exception(
            'Adzuna API returned status ' . $response->getStatusCode()
        );
    }
    
    // Validate content type
    $content_type = $response->getHeader('Content-Type')[0] ?? '';
    if (strpos($content_type, 'application/json') === false) {
        throw new \Exception(
            'Adzuna API returned non-JSON response: ' . $content_type
        );
    }
    
    $data = json_decode($response->getBody()->getContents(), TRUE);
    
    // Validate JSON decode
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception(
            'Failed to decode Adzuna response: ' . json_last_error_msg()
        );
    }
    
    // Validate expected fields
    if (!isset($data['results'])) {
        throw new \Exception('Adzuna response missing "results" field');
    }
    
    $this->logger->info('📊 Adzuna returned @count results (total: @total)', [
        '@count' => count($data['results'] ?? []),
        '@total' => $data['count'] ?? 0,
    ]);

    return [
        'jobs' => $data['results'] ?? [],
        'total' => $data['count'] ?? 0,
        'page' => $query_params['page'],
    ];

} catch (RequestException $e) {
    // ... existing error handling ...
}
```

### 6. **No Type Hints** (MEDIUM)
**Current State:**
```php
public function searchJobs(array $params)
```

**Issue:** No return type declaration.

**Recommendation:**
```php
/**
 * @return array{jobs: array, total: int, page: int}
 * @throws \Exception
 */
public function searchJobs(array $params): array
```

### 7. **Employment Type Mapping Incomplete** (LOW)
**Current State:** Maps 4 types, but what about others?

**Issue:** Invalid employment types silently ignored.

**Recommendation:**
```php
private function mapEmploymentType(string $employment_type): ?string {
    $contract_map = [
        'FULL_TIME' => 'permanent',
        'PART_TIME' => 'part_time',
        'CONTRACT' => 'contract',
        'TEMPORARY' => 'temporary',
    ];
    
    if (!isset($contract_map[$employment_type])) {
        $this->logger->warning(
            'Unknown employment type: @type. Omitting from API request.',
            ['@type' => $employment_type]
        );
        return null;
    }
    
    return $contract_map[$employment_type];
}
```

### 8. **Missing Caching** (LOW)
**Current State:** No caching of results

**Recommendation:**
```php
private const CACHE_DURATION = 3600; // 1 hour

public function searchJobs(array $params): array {
    // Generate cache key from params
    $cache_key = 'adzuna:' . md5(json_encode($params));
    
    // Check cache first
    if ($cache = \Drupal::cache('job_hunter')->get($cache_key)) {
        $this->logger->info('Returning cached Adzuna results');
        return $cache->data;
    }
    
    // ... perform API request ...
    
    // Cache results
    \Drupal::cache('job_hunter')->set(
        $cache_key,
        $result,
        time() + self::CACHE_DURATION
    );
    
    return $result;
}
```

### 9. **Timeout Too Short** (LOW)
**Current State:** 10-second timeout
```php
'timeout' => 10,
```

**Issue:** May be too aggressive for slow connections; should allow retry.

**Recommendation:**
```php
private const TIMEOUT_SECONDS = 15;
private const MAX_RETRIES = 2;

public function searchJobs(array $params) {
    // ... prepare request ...
    
    $attempt = 0;
    while ($attempt < self::MAX_RETRIES) {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            break; // Success
        } catch (ConnectException $e) {
            $attempt++;
            if ($attempt < self::MAX_RETRIES) {
                $this->logger->warning(
                    'Adzuna API connection attempt @attempt of @max failed, retrying',
                    ['@attempt' => $attempt, '@max' => self::MAX_RETRIES]
                );
                sleep(2 ** $attempt); // Exponential backoff
                continue;
            }
            throw new \Exception('Adzuna API connection failed after ' . 
                               self::MAX_RETRIES . ' attempts: ' . $e->getMessage());
        } catch (RequestException $e) {
            // Non-connection errors should not retry
            throw $e;
        }
    }
    
    // ... rest of code
}
```

---

## 🔍 Testing Considerations

1. **Unit Tests:**
   - Mock HTTP client for various response scenarios
   - Test parameter validation
   - Test employment type mapping
   - Test rate limiting

2. **Integration Tests (with quota awareness):**
   - Test actual API calls (sparingly)
   - Test response parsing
   - Test pagination

3. **Edge Cases:**
   - Empty results
   - Invalid credentials
   - API rate limiting (429 status)
   - Malformed JSON responses
   - Network timeouts
   - Month boundary for rate limit reset

---

## 📋 Summary

| Category | Status | Priority |
|----------|--------|----------|
| Credential Management | ✅ Good | - |
| Error Handling | ✅ Good | - |
| Input Validation | ⚠️ Missing | MEDIUM |
| Rate Limiting | ❌ Missing | HIGH |
| Response Validation | ⚠️ Weak | MEDIUM |
| Caching | ❌ Missing | LOW |
| Type Safety | ⚠️ Partial | MEDIUM |
| Resilience | ⚠️ No Retries | LOW |

---

## Action Items

- [ ] Implement rate limiting for monthly quota
- [ ] Add comprehensive input validation
- [ ] Add response validation and status checking
- [ ] Implement caching layer
- [ ] Add retry logic with exponential backoff
- [ ] Add return type hints
- [ ] Add comprehensive unit tests
- [ ] Document API limitations in class docblock
