# Code Review: SerpApiService.php

## Overview
The `SerpApiService` provides access to Google Jobs via SerpAPI for web scraping search results. It maps employment types, handles pagination, and includes fallback behavior when API key is missing.

---

## ✅ Strengths

### 1. **Graceful Fallback Behavior**
- Returns empty results instead of throwing exception if API key missing (lines 82-85)
- Good for degraded mode
- Logs warning when API key not configured

### 2. **Employment Type Mapping**
- Maps internal employment types to Google Jobs filters
- Comprehensive mapping (5 types covered)

### 3. **Flexible Pagination**
- Supports page-based pagination
- Calculates start offset for multi-page queries
- Configurable results per page

### 4. **Error Handling**
- Catches RequestException
- Includes response body in error messages
- Returns empty results on error (doesn't crash)

---

## ⚠️ Issues & Recommendations

### 1. **No Rate Limiting** (HIGH)
**Current State:** No protection against 100 searches/month free tier limit

**Issue:** Easy to exhaust free quota.

**Recommendation:**
```php
private const FREE_TIER_LIMIT = 100;

public function searchJobs(array $params): array {
    $config = $this->configFactory->get('job_hunter.settings');
    $api_key = $config->get('serpapi_api_key');

    if (empty($api_key)) {
        $this->loggerFactory->get('job_hunter')->warning('SerpAPI API key not configured');
        return ['jobs' => [], 'total' => 0, 'page' => 1];
    }

    // Check rate limit
    $this->enforceRateLimit();
    
    // ... rest of method
}

private function enforceRateLimit(): void {
    $store = \Drupal::keyValueExpirable('job_hunter');
    $month_key = 'serpapi_calls_' . date('Y-m');
    
    $count = $store->get($month_key) ?? 0;
    
    if ($count >= self::FREE_TIER_LIMIT) {
        throw new \Exception(
            'SerpAPI monthly limit (' . self::FREE_TIER_LIMIT . 
            ' searches) exceeded. Limit resets on 1st of next month.'
        );
    }
    
    // Increment counter (resets on 1st of month)
    $expiry = strtotime('first day of next month') - time();
    $store->setWithExpire($month_key, $count + 1, $expiry);
}
```

### 2. **Missing Input Validation** (MEDIUM)
**Current State:** No validation of search parameters

**Issue:** Invalid parameters silently passed to API.

**Recommendation:**
```php
public function searchJobs(array $params): array {
    // Validate query parameter (required)
    if (empty($params['query'])) {
        throw new \InvalidArgumentException('Query parameter is required');
    }
    
    if (!is_string($params['query']) || strlen($params['query']) > 200) {
        throw new \InvalidArgumentException('Query must be a non-empty string (max 200 chars)');
    }
    
    // Validate results_per_page
    if (isset($params['results_per_page'])) {
        $num = (int) $params['results_per_page'];
        if ($num < 1 || $num > 100) {
            throw new \InvalidArgumentException('results_per_page must be 1-100');
        }
    }
    
    // Validate page number
    if (isset($params['page'])) {
        $page = (int) $params['page'];
        if ($page < 1) {
            throw new \InvalidArgumentException('Page must be a positive integer');
        }
    }
    
    // Validate employment type if provided
    if (isset($params['employment_type'])) {
        $valid_types = ['FULL_TIME', 'PART_TIME', 'CONTRACT', 'TEMPORARY', 'INTERN'];
        if (!in_array($params['employment_type'], $valid_types)) {
            throw new \InvalidArgumentException(
                'Invalid employment_type. Valid: ' . implode(', ', $valid_types)
            );
        }
    }
    
    // ... rest of method
}
```

### 3. **Silent Error Suppression** (MEDIUM)
**Current State:** Returns empty results on error (lines 152-162)

**Issue:** Caller doesn't know if results are empty because no jobs exist or API failed.

**Recommendation:**
```php
} catch (RequestException $e) {
    $error_message = $e->getMessage();
    if ($e->hasResponse()) {
        $error_message .= ' - Response: ' . substr(
            $e->getResponse()->getBody()->getContents(), 0, 200
        );
    }
    
    // Distinguish between types of errors
    if ($e->getResponse()) {
        $status = $e->getResponse()->getStatusCode();
        if ($status === 429) {
            // Rate limited
            throw new \Exception('SerpAPI rate limit reached. Please wait before retrying.');
        } elseif ($status === 401 || $status === 403) {
            // Auth error
            throw new \Exception('SerpAPI authentication failed. Check API key.');
        }
    }
    
    $this->loggerFactory->get('job_hunter')->error(
        'SerpAPI request failed: @error',
        ['@error' => $error_message]
    );
    
    // Return error indicator instead of empty results
    return [
        'jobs' => [],
        'total' => 0,
        'page' => $params['page'] ?? 1,
        'error' => $error_message,
    ];
}
```

### 4. **No Response Validation** (MEDIUM)
**Current State:** Assumes response structure is correct

**Issue:** API could return unexpected response structure.

**Recommendation:**
```php
$data = json_decode($response->getBody()->getContents(), TRUE);

// Validate JSON decode
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    throw new \Exception('Invalid JSON from SerpAPI: ' . json_last_error_msg());
}

// Check for API errors in response
if (isset($data['error'])) {
    throw new \Exception('SerpAPI error: ' . $data['error']);
}

// Validate expected fields
if (!is_array($data)) {
    throw new \Exception('SerpAPI response is not an array');
}

$jobs = $data['jobs_results'] ?? [];

if (!is_array($jobs)) {
    throw new \Exception('SerpAPI jobs_results is not an array');
}

$this->loggerFactory->get('job_hunter')->info('✅ SerpAPI returned @count jobs', [
    '@count' => count($jobs),
]);

return [
    'jobs' => $jobs,
    'total' => count($jobs),
    'page' => $params['page'] ?? 1,
];
```

### 5. **Type Hints Missing** (MEDIUM)
**Current State:**
```php
public function searchJobs(array $params)
```

**Issue:** No return type hint.

**Recommendation:**
```php
/**
 * @return array{jobs: array, total: int, page: int, error?: string}
 */
public function searchJobs(array $params): array
```

### 6. **Debug Logging Print Output** (LOW)
**Current State:** Line 122 uses `print_r()`:
```php
'@params' => print_r($query_params, TRUE),
```

**Issue:** This should use `json_encode()` for consistency.

**Recommendation:**
```php
$this->loggerFactory->get('job_hunter')->info(
    '🔍 SerpAPI Google Jobs search: @query in @location',
    [
        '@query' => $params['query'],
        '@location' => $params['location'] ?? 'anywhere',
    ]
);
```

### 7. **No Caching** (LOW)
**Current State:** Repeats searches to API

**Recommendation:**
```php
private const CACHE_DURATION = 3600; // 1 hour

public function searchJobs(array $params): array {
    $cache_key = 'serpapi:' . md5(json_encode($params));
    
    if ($cache = \Drupal::cache('job_hunter_results')->get($cache_key)) {
        $this->loggerFactory->get('job_hunter')->info('Returning cached SerpAPI results');
        return $cache->data;
    }
    
    // ... perform search ...
    
    \Drupal::cache('job_hunter_results')->set(
        $cache_key,
        $result,
        time() + self::CACHE_DURATION
    );
    
    return $result;
}
```

### 8. **Timeout Not Configurable** (LOW)
**Current State:** Hard-coded 30-second timeout

**Recommendation:**
```php
private const DEFAULT_TIMEOUT = 15;

public function searchJobs(array $params): array {
    // ...
    
    $timeout = $this->configFactory->get('job_hunter.settings')
        ->get('serpapi_timeout') ?? self::DEFAULT_TIMEOUT;
    
    $response = $this->httpClient->get(self::API_BASE_URL, [
        'query' => $query_params,
        'timeout' => (int) $timeout,
    ]);
}
```

### 9. **No Retry Logic** (MEDIUM)
**Current State:** Single attempt fails

**Recommendation:**
```php
private const MAX_RETRIES = 2;

try {
    $attempt = 0;
    while ($attempt < self::MAX_RETRIES) {
        try {
            $response = $this->httpClient->get(self::API_BASE_URL, [
                'query' => $query_params,
                'timeout' => 15,
            ]);
            break; // Success
            
        } catch (ConnectException $e) {
            $attempt++;
            if ($attempt < self::MAX_RETRIES) {
                $this->loggerFactory->get('job_hunter')->warning(
                    'SerpAPI connection attempt @n failed, retrying',
                    ['@n' => $attempt]
                );
                sleep(2 ** $attempt); // Exponential backoff
                continue;
            }
            throw $e;
        }
    }
    
    // ... rest of code
} catch (RequestException $e) {
    // ...
}
```

---

## 🔍 Testing Considerations

1. **Unit Tests:**
   - Mock HTTP client
   - Test parameter validation
   - Test employment type mapping
   - Test response parsing
   - Test pagination

2. **Integration Tests:**
   - Test with real API (with quota awareness)
   - Test various query types
   - Test pagination

3. **Edge Cases:**
   - Missing API key
   - Empty search results
   - API errors (401, 429, 500)
   - Malformed JSON response
   - Missing jobs_results field
   - Network timeouts
   - Month boundary for quota reset

---

## 📋 Summary

| Category | Status | Priority |
|----------|--------|----------|
| Graceful Degradation | ✅ Good | - |
| Error Handling | ⚠️ Partial | MEDIUM |
| Rate Limiting | ❌ Missing | HIGH |
| Input Validation | ❌ Missing | MEDIUM |
| Response Validation | ⚠️ Weak | MEDIUM |
| Retry Logic | ❌ Missing | MEDIUM |
| Caching | ❌ Missing | LOW |
| Type Safety | ⚠️ Partial | LOW |

---

## Action Items

- [ ] Implement rate limiting for free tier quota
- [ ] Add comprehensive input validation
- [ ] Add response validation and error detection
- [ ] Implement retry logic with exponential backoff
- [ ] Add return type hints
- [ ] Implement caching layer
- [ ] Make timeout configurable
- [ ] Distinguish between empty results and API errors
- [ ] Clean up debug logging (use json_encode)
- [ ] Create comprehensive unit tests
