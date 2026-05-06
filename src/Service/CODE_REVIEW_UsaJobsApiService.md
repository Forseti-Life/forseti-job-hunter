# Code Review: UsaJobsApiService.php

## Overview
The `UsaJobsApiService` integrates with the USAJobs API for U.S. federal government job positions. It handles authentication, parameter mapping, and response normalization with no rate limits.

---

## ✅ Strengths

### 1. **Clear, Focused Implementation**
- Single responsibility: search federal jobs
- Simple, readable code structure
- Appropriate use of HTTP client

### 2. **Good Credential Management**
- Credentials from config (not hard-coded)
- Requires both API key and email (standard USAJobs requirement)
- Helpful error message with documentation link

### 3. **Appropriate Headers**
- Authorization-Key header properly set
- User-Agent set to email (USAJobs requirement)
- Host header included for API routing

### 4. **Date Filtering**
- Maps friendly date strings to day offsets
- Flexible filtering (past 24 hours, week, month)

---

## ⚠️ Issues & Recommendations

### 1. **No Input Validation** (MEDIUM)
**Current State:** No validation of input parameters

**Issue:** Invalid parameters passed silently to API.

**Recommendation:**
```php
public function searchJobs(array $params): array {
    // Validate email credential
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new \Exception('USAJobs email is not a valid email address');
    }
    
    // Validate pagination
    $page = max(1, (int) ($params['page'] ?? 1));
    $results_per_page = min(500, max(1, (int) ($params['results_per_page'] ?? 25)));
    
    if ($results_per_page > 500) {
        $this->logger->warning(
            'USAJobs API max results_per_page is 500, requested @requested',
            ['@requested' => $params['results_per_page']]
        );
        $results_per_page = 500;
    }
    
    // Validate query/location requirement
    if (empty($params['query']) && empty($params['location'])) {
        throw new \InvalidArgumentException(
            'USAJobs search requires at least a query or location'
        );
    }
    
    // Validate date_posted if provided
    $valid_dates = ['past_24_hours', 'past_week', 'past_month'];
    if (isset($params['date_posted']) && 
        !in_array($params['date_posted'], $valid_dates)) {
        throw new \InvalidArgumentException(
            'Invalid date_posted. Valid values: ' . implode(', ', $valid_dates)
        );
    }
    
    // ... rest of code
}
```

### 2. **Missing Error Details** (MEDIUM)
**Current State:** Generic error logging

**Issue:** Hard to debug API issues without error details.

**Recommendation:**
```php
} catch (RequestException $e) {
    $status_code = $e->getResponse() ? $e->getResponse()->getStatusCode() : 'N/A';
    $error_body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
    
    // Specific handling for common errors
    if ($status_code === 401) {
        $this->logger->error('USAJobs API authentication failed. Check API key and email.');
    } elseif ($status_code === 429) {
        $this->logger->error('USAJobs API rate limit exceeded.');
    } else {
        $this->logger->error('USAJobs API failed: @error (@status). Response: @body', [
            '@error' => $e->getMessage(),
            '@status' => $status_code,
            '@body' => substr($error_body, 0, 500), // Limit length
        ]);
    }
    
    throw new \Exception('USAJobs API error: ' . $e->getMessage());
}
```

### 3. **No Timeout Protection** (MEDIUM)
**Current State:** 10-second timeout, no retry logic

**Issue:** Transient network issues cause failures.

**Recommendation:**
```php
private const TIMEOUT_SECONDS = 15;
private const MAX_RETRIES = 2;

public function searchJobs(array $params): array {
    // ... validation and parameter building ...
    
    $attempt = 0;
    while ($attempt < self::MAX_RETRIES) {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization-Key' => $api_key,
                    'User-Agent' => $email,
                    'Host' => 'data.usajobs.gov',
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            break; // Success
            
        } catch (ConnectException $e) {
            $attempt++;
            if ($attempt < self::MAX_RETRIES) {
                $this->logger->warning(
                    'USAJobs connection timeout, attempting retry @attempt of @max',
                    ['@attempt' => $attempt, '@max' => self::MAX_RETRIES]
                );
                sleep(2 ** $attempt); // Exponential backoff: 2s, 4s
                continue;
            }
            
            throw new \Exception(
                'USAJobs API connection failed after ' . self::MAX_RETRIES . 
                ' attempts: ' . $e->getMessage()
            );
            
        } catch (RequestException $e) {
            // Non-connection errors don't retry
            throw $e;
        }
    }
    
    // ... rest of code
}
```

### 4. **Type Hints Missing** (MEDIUM)
**Current State:**
```php
public function searchJobs(array $params)
```

**Issue:** No return type hint.

**Recommendation:**
```php
/**
 * @return array{jobs: array, total: int, page: int}
 */
public function searchJobs(array $params): array
```

### 5. **No Response Validation** (MEDIUM)
**Current State:** Assumes response is valid JSON with expected fields

**Issue:** API could return HTML error page, invalid JSON, etc.

**Recommendation:**
```php
$data = json_decode($response->getBody()->getContents(), TRUE);

// Validate JSON decode
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    throw new \Exception(
        'Invalid JSON response from USAJobs: ' . json_last_error_msg()
    );
}

// Validate expected structure
if (!is_array($data) || !isset($data['SearchResult'])) {
    throw new \Exception('Unexpected USAJobs response structure');
}

$search_result = $data['SearchResult'] ?? [];
$jobs = $search_result['SearchResultItems'] ?? [];

// Validate count field
$total = $search_result['SearchResultCount'] ?? 0;
if (!is_numeric($total)) {
    $this->logger->warning('USAJobs returned non-numeric count: @count', [
        '@count' => $total,
    ]);
    $total = count($jobs);
}

$this->logger->info('✅ USAJobs returned @count results (total: @total)', [
    '@count' => count($jobs),
    '@total' => (int) $total,
]);

return [
    'jobs' => $jobs,
    'total' => (int) $total,
    'page' => $page,
];
```

### 6. **Fallback Email Weak** (LOW)
**Current State:**
```php
if (empty($email)) {
    $email = 'noreply@forseti.life'; // Fallback
}
```

**Issue:** This email might not work with USAJobs API.

**Recommendation:**
```php
if (empty($email)) {
    throw new \Exception(
        'USAJobs email not configured. USAJobs API requires a valid email address. ' .
        'Configure it in Job Hunter settings.'
    );
}
```

### 7. **No Caching** (LOW)
**Current State:** Repeats queries to API

**Recommendation:**
```php
private const CACHE_DURATION = 1800; // 30 minutes

public function searchJobs(array $params): array {
    $cache_key = 'usajobs:' . md5(json_encode($params));
    
    if ($cache = \Drupal::cache('job_hunter_results')->get($cache_key)) {
        $this->logger->info('Returning cached USAJobs results');
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

### 8. **Status Code Not Checked** (MEDIUM)
**Current State:** Assumes 200 status code

**Recommendation:**
```php
$response = $this->httpClient->request('GET', $url, [
    'headers' => [...],
    'timeout' => 10,
]);

// Check status code
if ($response->getStatusCode() !== 200) {
    throw new \Exception(
        'USAJobs API returned status ' . $response->getStatusCode()
    );
}

$data = json_decode($response->getBody()->getContents(), TRUE);
```

### 9. **Content-Type Not Validated** (LOW)
**Current State:** Doesn't check Content-Type header

**Recommendation:**
```php
$content_type = $response->getHeader('Content-Type')[0] ?? '';
if (strpos($content_type, 'application/json') === false) {
    throw new \Exception(
        'USAJobs API returned non-JSON response: ' . $content_type
    );
}
```

---

## 🔍 Testing Considerations

1. **Unit Tests:**
   - Mock HTTP responses
   - Test parameter validation
   - Test date mapping
   - Test response parsing
   - Test error handling

2. **Integration Tests:**
   - Test with real API (no rate limits)
   - Test various query combinations
   - Test pagination

3. **Edge Cases:**
   - Empty search results
   - Missing SearchResult field
   - Invalid JSON response
   - Timeouts and retries
   - Missing credentials
   - API errors (401, 429, 500, etc.)

---

## 📋 Summary

| Category | Status | Priority |
|----------|--------|----------|
| Clarity | ✅ Good | - |
| Credential Management | ✅ Good | - |
| Input Validation | ❌ Missing | MEDIUM |
| Error Handling | ⚠️ Weak | MEDIUM |
| Response Validation | ⚠️ Missing | MEDIUM |
| Resilience | ⚠️ No Retries | MEDIUM |
| Type Safety | ⚠️ Partial | LOW |
| Caching | ❌ Missing | LOW |

---

## Action Items

- [ ] Add comprehensive input validation
- [ ] Add better error handling with specific status codes
- [ ] Add response structure validation
- [ ] Implement retry logic with exponential backoff
- [ ] Add status code checking
- [ ] Add Content-Type validation
- [ ] Implement caching layer
- [ ] Add return type hints
- [ ] Create comprehensive tests
- [ ] Document parameter requirements and limitations
