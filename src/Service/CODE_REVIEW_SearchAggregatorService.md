# Code Review: SearchAggregatorService.php

## Overview
The `SearchAggregatorService` is a high-level orchestration service that aggregates job search results from multiple sources (Forseti database and external APIs). It normalizes results, stores them for analytics, and provides diagnostics.

---

## ✅ Strengths

### 1. **Excellent Logging & Diagnostics**
- Emojis for visual distinction (useful in log viewers)
- Detailed info logs at each stage
- Comprehensive error logging with context
- Diagnostic information when no results found (lines 678-712)

### 2. **Unified Result Normalization**
- Results from different sources normalized to consistent format
- Each result includes: id, title, company, location, employment_type, salary_range, description, source, posted_date, url
- Rich metadata for SerpAPI results (lines 566-577)

### 3. **Proper Dependency Injection**
- Multiple service dependencies properly injected
- Clean constructor with type hints
- Services well-organized as class properties

### 4. **Comprehensive Search Parameters**
- Supports multiple filter types: query, location, employment_type, salary range, remote preference, date posted
- Parameters passed appropriately to each service
- Flexibility for future extensions

### 5. **Data Persistence**
- Stores search history and results for analytics (lines 603-667)
- Tracks individual job results for future imports
- Rank position tracking for relevance

---

## ⚠️ Issues & Recommendations

### 1. **Missing Rate Limiting at Aggregator Level** (HIGH)
**Current State:** No rate limiting on the orchestration level

**Issue:** External APIs have rate limits. Multiple searches can quickly exhaust quotas.
- SerpAPI: 100 searches/month free tier
- Adzuna: 250 calls/month free tier
- USAJobs: Unlimited but performance concerns
- Google Cloud: Depends on billing

**Recommendation:**
```php
private $rateLimiter = [];

public function searchJobs(array $params): array {
    // Check rate limits before searching
    $this->checkRateLimits($params['sources'] ?? []);
    
    // ... rest of method
}

private function checkRateLimits(array $sources): void {
    $config = $this->configFactory->get('job_hunter.settings');
    
    foreach ($sources as $source) {
        $key = 'rate_limit_' . $source;
        $limit_key = $source . '_rate_limit';
        
        if (!$config->get($limit_key)) {
            continue; // No limit configured
        }
        
        $store = \Drupal::keyValueExpirable('job_hunter');
        $count = $store->get($key) ?? 0;
        $limit = $config->get($limit_key);
        
        if ($count >= $limit) {
            throw new \Exception(
                "Rate limit exceeded for $source. Daily limit: $limit. Reset at midnight."
            );
        }
        
        $store->setWithExpire($key, $count + 1, 86400); // 24 hour TTL
    }
}
```

### 2. **No Deduplication of Results** (MEDIUM)
**Current State:** Job hash created but used only in SerpAPI results (line 562)

**Issue:** Same job from multiple sources appears multiple times in results.

**Recommendation:**
```php
public function searchJobs(array $params): array {
    $sources = $params['sources'] ?? ['forseti'];
    $all_results = [];
    $seen_hashes = [];

    // ... search each source ...
    
    // Deduplicate before returning
    $deduped_results = [];
    foreach ($all_results as $result) {
        $hash = $result['job_hash'] ?? md5(
            strtolower($result['company'] ?? '') . '|' .
            strtolower($result['title'] ?? '') . '|' .
            strtolower($result['location'] ?? '')
        );
        
        if (!isset($seen_hashes[$hash])) {
            $result['job_hash'] = $hash;
            $deduped_results[] = $result;
            $seen_hashes[$hash] = true;
        } else {
            $this->logger->info('Duplicate job detected: @title from @company', [
                '@title' => $result['title'],
                '@company' => $result['company'],
            ]);
        }
    }

    return [
        'results' => $deduped_results,
        'total' => count($deduped_results),
        'sources_searched' => $sources,
        'diagnostics' => $diagnostics,
    ];
}
```

### 3. **No Timeout Protection at Aggregator Level** (MEDIUM)
**Current State:** No overall timeout for all searches combined

**Issue:** If multiple APIs are slow, search could take very long (multiple 30-second timeouts × 5 services = up to 150 seconds).

**Recommendation:**
```php
private const MAX_SEARCH_TIME = 60; // seconds

public function searchJobs(array $params): array {
    $start_time = microtime(true);
    $all_results = [];

    foreach ($sources as $source) {
        $elapsed = microtime(true) - $start_time;
        if ($elapsed > self::MAX_SEARCH_TIME) {
            $this->logger->warning(
                'Search aggregation timeout: @elapsed seconds exceeded',
                ['@elapsed' => round($elapsed, 2)]
            );
            break;
        }
        
        // Skip search if less than 5 seconds remaining
        if ((self::MAX_SEARCH_TIME - $elapsed) < 5) {
            $this->logger->info('Skipping @source due to time constraints', 
                               ['@source' => $source]);
            continue;
        }
        
        // ... perform search ...
    }
    
    return [...];
}
```

### 4. **Missing Input Validation** (MEDIUM)
**Current State:** No validation of search parameters

**Issue:** Invalid parameters silently fail or cause database errors.

**Recommendation:**
```php
public function searchJobs(array $params): array {
    // Validate required parameters
    if (empty($params['sources'])) {
        throw new \InvalidArgumentException('At least one source must be specified');
    }
    
    // Validate source names
    $valid_sources = ['forseti', 'google_cloud', 'adzuna', 'usajobs', 'serpapi'];
    foreach ($params['sources'] as $source) {
        if (!in_array($source, $valid_sources)) {
            throw new \InvalidArgumentException("Invalid source: $source");
        }
    }
    
    // Validate pagination
    if (isset($params['page']) && !is_int($params['page']) || $params['page'] < 1) {
        throw new \InvalidArgumentException('Page must be a positive integer');
    }
    
    // Validate salary range
    if (isset($params['salary_min']) && isset($params['salary_max'])) {
        if ($params['salary_min'] > $params['salary_max']) {
            throw new \InvalidArgumentException('Minimum salary cannot exceed maximum');
        }
    }
    
    // ... rest of method
}
```

### 5. **Error in Data Storage** (MEDIUM)
**Current State:** Line 628 assumes `$result['source'] !== 'Forseti Jobs'` to determine if external

**Issue:** Should be using a constant or more explicit check.

**Recommendation:**
```php
private const INTERNAL_SOURCE = 'Forseti Jobs';

protected function storeSearchResults(array $params, array $results): void {
    // ... 
    foreach ($results as $position => $result) {
        try {
            // Only store external API results (not Forseti DB results)
            if ($result['source'] !== self::INTERNAL_SOURCE) {
                $this->database->insert('jobhunter_job_search_results')
                    ->fields([...])
                    ->execute();
                $stored_count++;
            }
        }
        // ...
    }
}
```

### 6. **SQL Injection Risk in Forseti Search** (HIGH)
**Current State:** Lines 213-218 use `escapeLike()` but `query` parameter could be misused

**Issue:** While `escapeLike()` is used, relying on manual escaping is error-prone.

**Verification**: Actually, the code IS using `escapeLike()` correctly, which is good. However, should add comment:
```php
// Use escapeLike() to prevent injection attacks in LIKE queries
$query->condition('job_title', '%' . $this->database->escapeLike($params['query']) . '%', 'LIKE');
```

### 7. **Missing Result Sorting/Relevance** (MEDIUM)
**Current State:** Results returned in arbitrary order from concatenated arrays

**Recommendation:**
```php
protected function sortResults(array $results, array $params): array {
    $sort_by = $params['sort_by'] ?? 'relevance';
    
    switch ($sort_by) {
        case 'recent':
            usort($results, function($a, $b) {
                // Parse dates - need more robust date handling
                return strtotime($b['posted_date']) - strtotime($a['posted_date']);
            });
            break;
            
        case 'salary_high':
            usort($results, function($a, $b) {
                $a_salary = $this->extractMaxSalary($a['salary_range']);
                $b_salary = $this->extractMaxSalary($b['salary_range']);
                return $b_salary - $a_salary;
            });
            break;
            
        case 'relevance':
        default:
            // Already sorted by source-specific relevance
            break;
    }
    
    return $results;
}
```

### 8. **Type Hints Missing** (MEDIUM)
**Current State:**
```php
protected function searchForsetiDatabase(array $params): array
protected function searchGoogleCloud(array $params): array
```

Good, but could be more specific:

**Recommendation:**
```php
/**
 * @return array{results: array, total: int, sources_searched: array, diagnostics: array}
 */
public function searchJobs(array $params): array
```

### 9. **Diagnostic Information Incomplete** (LOW)
**Current State:** Diagnostics only check Forseti and Google Cloud

**Recommendation:**
```php
protected function generateDiagnostics(array $sources): array {
    $diagnostics = [];
    
    // Check each requested source
    foreach ($sources as $source) {
        try {
            switch ($source) {
                case 'forseti':
                    // ... existing code ...
                    break;
                    
                case 'google_cloud':
                    // ... existing code ...
                    break;
                    
                case 'adzuna':
                    $diagnostics['adzuna_status'] = $this->checkAdzunaConnection() 
                        ? 'connected' : 'disconnected';
                    break;
                    
                case 'usajobs':
                    $diagnostics['usajobs_status'] = 'connected'; // Always available
                    break;
                    
                case 'serpapi':
                    $diagnostics['serpapi_status'] = $this->checkSerpApiConnection() 
                        ? 'connected' : 'disconnected';
                    break;
            }
        } catch (\Exception $e) {
            $diagnostics[$source . '_error'] = $e->getMessage();
        }
    }
    
    return $diagnostics;
}
```

### 10. **No Caching Strategy** (LOW)
**Current State:** Performs full search on every request

**Recommendation:** Cache aggregated results:
```php
private const CACHE_DURATION = 600; // 10 minutes

public function searchJobs(array $params): array {
    $cache_key = 'search_agg:' . md5(json_encode($params));
    
    if ($cache = \Drupal::cache('job_hunter_results')->get($cache_key)) {
        $this->logger->info('Returning cached search results');
        return $cache->data;
    }
    
    // ... perform searches ...
    
    \Drupal::cache('job_hunter_results')->set(
        $cache_key, 
        $results, 
        time() + self::CACHE_DURATION
    );
    
    return $results;
}
```

---

## 🔍 Testing Considerations

1. **Unit Tests:**
   - Mock all service dependencies
   - Test each search method independently
   - Test normalization logic
   - Test deduplication algorithm
   - Test rate limiting checks

2. **Integration Tests:**
   - Test with real API services (with low quotas)
   - Test storage of results
   - Test diagnostics generation

3. **Edge Cases:**
   - Empty results from all sources
   - Partial failures (1 of 5 sources fails)
   - Timeout scenarios
   - Database storage failures
   - Rate limit exceeded

---

## 📋 Summary

| Category | Status | Priority |
|----------|--------|----------|
| Logging & Diagnostics | ✅ Excellent | - |
| Result Normalization | ✅ Good | - |
| Architecture | ✅ Good | - |
| Rate Limiting | ❌ Missing | HIGH |
| Result Deduplication | ⚠️ Partial | MEDIUM |
| Timeout Protection | ⚠️ Partial | MEDIUM |
| Input Validation | ⚠️ Missing | MEDIUM |
| Caching Strategy | ❌ Missing | LOW |
| Result Sorting | ⚠️ Missing | LOW |

---

## Action Items

- [ ] Implement service-level rate limiting
- [ ] Add deduplication across sources
- [ ] Add timeout protection at aggregator level
- [ ] Add input validation for all parameters
- [ ] Implement result sorting/relevance ranking
- [ ] Add caching layer for aggregated results
- [ ] Complete diagnostics for all services
- [ ] Add comprehensive integration tests
- [ ] Document search parameter schema
