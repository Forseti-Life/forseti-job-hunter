# Code Review: AbbVieJobScrapingService.php

## Overview
The `AbbVieJobScrapingService` provides web scraping functionality for AbbVie careers page using DOM parsing and HTML XPath queries. It includes fallback simulation data and relevance-based filtering.

---

## ✅ Strengths

### 1. **Robust Error Handling**
- Comprehensive try-catch blocks (lines 96-102)
- Graceful fallback to simulated data on errors
- Clear error logging with context

### 2. **Web Scraping Best Practices**
- User-Agent headers configured (lines 75-79)
- Accept language headers included
- Timeout configured (30 seconds)
- DOM parsing with proper null checks

### 3. **Relevance Algorithm**
- Weighted scoring system for job relevance (lines 299-330)
- Title match (weight: 10), function match (weight: 5), description match (weight: 2)
- Results sorted by relevance score
- Clear, maintainable scoring logic

### 4. **Dependency Injection**
- Proper injection of HTTP client, logger, and config services
- Clean constructor pattern

---

## ⚠️ Issues & Recommendations

### 1. **Rate Limiting Missing** (HIGH)
**Current State:** No rate limiting on HTTP requests

**Risk:** Multiple consecutive requests to AbbVie could result in:
- IP blocking
- Rate limit penalties
- Service disruption

**Recommendation:**
```php
private const REQUEST_DELAY_SECONDS = 2;
private $lastRequestTime = 0;

public function searchJobs(array $keywords, array $options = []) {
    // Implement throttling
    $timeSinceLastRequest = time() - $this->lastRequestTime;
    if ($timeSinceLastRequest < self::REQUEST_DELAY_SECONDS) {
        sleep(self::REQUEST_DELAY_SECONDS - $timeSinceLastRequest);
    }
    
    try {
        $response = $this->httpClient->request('GET', $search_url, [...]);
        $this->lastRequestTime = time();
        // ... rest of code
    }
}
```

### 2. **Security: Hard-coded URLs & Constants** (MEDIUM)
**Current State:**
- Base URL is hard-coded as constant
- Function mapping is hard-coded (lines 342-350)

**Issue:** Maintenance challenge if URLs change; no flexibility for configuration.

**Recommendation:**
```php
public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
) {
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
    // Load URLs and mappings from config
}

private function getBaseUrl(): string {
    return $this->configFactory->get('job_hunter.settings')
        ->get('abbvie_careers_base_url') ?? self::ABBVIE_CAREERS_BASE_URL;
}
```

### 3. **XPath Selectors Fragile** (MEDIUM)
**Current State:** XPath selectors depend on exact class names
```php
$job_nodes = $xpath->query("//div[contains(@class, 'attrax-vacancy-tile')]");
$title_node = $xpath->query(".//a[contains(@class, 'attrax-vacancy-tile__title')]");
```

**Issue:** HTML structure changes break parsing. No fallback selectors.

**Recommendation:**
```php
private function parseJobListings($html) {
    $jobs = [];
    
    $dom = new \DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new \DOMXPath($dom);
    
    // Try primary selector first
    $job_nodes = $xpath->query("//div[contains(@class, 'attrax-vacancy-tile')]");
    
    // Fallback to alternative selectors
    if ($job_nodes->length === 0) {
        $job_nodes = $xpath->query("//div[contains(@class, 'job-tile')]");
    }
    if ($job_nodes->length === 0) {
        $job_nodes = $xpath->query("//article[contains(@class, 'job')]");
    }
    
    // Log if no jobs found
    if ($job_nodes->length === 0) {
        $this->loggerFactory->get('job_hunter')
            ->warning('No job nodes found using any selector');
    }
    
    foreach ($job_nodes as $job_node) {
        // ... parsing logic
    }
    
    return $jobs;
}
```

### 4. **HTML Injection Risk** (HIGH)
**Current State:** No sanitization of scraped content

**Issue:** Malicious content in job postings could be stored/displayed unsanitized.

**Recommendation:**
```php
private function extractJobDetails(\DOMElement $job_node, \DOMXPath $xpath) {
    try {
        $title_node = $xpath->query(".//a[contains(@class, 'attrax-vacancy-tile__title')]", 
                                    $job_node)->item(0);
        if (!$title_node) {
            return NULL;
        }
        
        // Sanitize all extracted text
        $title = trim($title_node->textContent);
        $title = strip_tags($title); // Remove any HTML
        $title = htmlspecialchars($title); // Encode entities
        
        // ... more sanitization for other fields
        
        return [
            'title' => $title,
            'description' => strip_tags($description),
            // ...
        ];
    } catch (\Exception $e) {
        // ...
    }
}
```

### 5. **Missing Caching Strategy** (MEDIUM)
**Current State:** No caching of results

**Issue:** Same search queries repeated unnecessarily.

**Recommendation:**
```php
private const CACHE_DURATION = 3600; // 1 hour

public function searchJobs(array $keywords, array $options = []) {
    $cache_key = 'abbvie_jobs:' . md5(json_encode($keywords) . json_encode($options));
    
    // Check cache
    if ($cache = \Drupal::cache('job_hunter')->get($cache_key)) {
        return $cache->data;
    }
    
    try {
        // ... fetch jobs
        
        // Store in cache
        \Drupal::cache('job_hunter')->set($cache_key, $filtered_jobs, 
                                          time() + self::CACHE_DURATION);
        
        return $filtered_jobs;
    } catch (RequestException $e) {
        // ...
    }
}
```

### 6. **No Logging for Successful Scrapes** (LOW)
**Current State:** Logs only count in success, no details

**Recommendation:**
```php
$this->loggerFactory->get('job_hunter')
    ->info('AbbVie scrape completed: @count jobs found, @relevance avg relevance score', [
        '@count' => count($filtered_jobs),
        '@relevance' => round(array_reduce($filtered_jobs, 
                       fn($c, $j) => $c + ($j['relevance_score'] ?? 0), 0) 
                       / count($filtered_jobs), 2),
    ]);
```

### 7. **Fallback Data Quality** (LOW)
**Current State:** Simulated data is hard-coded and outdated

**Recommendation:**
```php
private function getSimulatedJobs(array $keywords) {
    // Log use of simulated data
    $this->loggerFactory->get('job_hunter')
        ->warning('Using simulated AbbVie jobs (scraping failed)');
    
    // Consider loading from database/config instead
    $config = $this->configFactory->get('job_hunter.settings');
    $cache_key = 'abbvie_last_successful_scrape';
    
    if ($cached = \Drupal::cache('job_hunter')->get($cache_key)) {
        return $cached->data;
    }
    
    return []; // Return empty instead of stale data
}
```

### 8. **Type Hints Missing** (MEDIUM)
**Current State:** No return type declarations
```php
private function parseJobListings($html)
private function extractJobDetails(\DOMElement $job_node, \DOMXPath $xpath)
```

**Recommendation:**
```php
private function parseJobListings(string $html): array
private function extractJobDetails(\DOMElement $job_node, \DOMXPath $xpath): ?array
private function filterJobsByRelevance(array $jobs, array $keywords): array
```

### 9. **Response Timeout Too Long** (LOW)
**Current State:** 30-second timeout
```php
'timeout' => 30,
```

**Recommendation:** Use shorter timeout with exponential backoff:
```php
// First attempt: 10 seconds
// Retry attempts: 15 seconds
$timeout = $attempt === 1 ? 10 : 15;
```

---

## 🔍 Testing Considerations

1. **Unit Tests:**
   - Mock HTML responses with real AbbVie structure
   - Test XPath selector failures and fallbacks
   - Test relevance scoring algorithm
   - Test URL building

2. **Integration Tests:**
   - Test actual AbbVie site (rate-limited)
   - Capture real responses for offline testing

3. **Edge Cases:**
   - Empty search results
   - Malformed HTML
   - Missing optional fields in job postings
   - Network timeouts
   - Rate limit responses (429 status)

---

## 📋 Summary

| Category | Status | Priority |
|----------|--------|----------|
| Error Handling | ✅ Good | - |
| Rate Limiting | ❌ Missing | HIGH |
| Security | ⚠️ HTML Injection Risk | HIGH |
| Caching | ❌ Missing | MEDIUM |
| Resilience | ⚠️ XPath Brittle | MEDIUM |
| Type Safety | ⚠️ Partial | MEDIUM |
| Configuration | ⚠️ Hard-coded URLs | MEDIUM |

---

## Action Items

- [ ] Implement rate limiting/throttling
- [ ] Add HTML sanitization for all extracted content
- [ ] Add caching layer for job listings
- [ ] Make URLs configurable instead of hard-coded
- [ ] Add fallback XPath selectors
- [ ] Add comprehensive type hints
- [ ] Replace hard-coded simulated data with cached last-known-good data
- [ ] Create integration tests with real AbbVie responses
