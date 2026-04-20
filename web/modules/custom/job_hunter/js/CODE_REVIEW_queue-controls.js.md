# Code Review: queue-controls.js

## Overview
Complex module for queue control and monitoring with auto-refresh, batch processing, and suspended item management.

**File Size**: ~23 KB | **Complexity**: Very High | **jQuery Usage**: Heavy

---

## ✅ Strengths

1. **Comprehensive State Management**: Tracks processing queues, activity times, auto-refresh state
2. **CSRF Token Protection**: Properly includes tokens in AJAX requests
3. **User Feedback**: Status messages, activity logging, countdown display
4. **Auto-Refresh**: Implements periodic refresh with countdown
5. **Error Recovery**: Handles network errors and server errors gracefully
6. **Accessibility**: Uses Drupal.announce for updates (partial)

---

## ⚠️ Issues & Recommendations

### 1. **Duplicate Function Definition: loadRecentLogs**
**Severity**: Medium | **Priority**: High

Lines 87-125 and 175-214 contain identical `loadRecentLogs()` function definitions.

**Issue**: Code duplication creates maintenance burden.

**Recommendation**:
```javascript
// Remove duplicate at line 175-214
// Keep only one definition at module level

function loadRecentLogs() {
  $.ajax({
    url: '/jobhunter/queue/logs',
    type: 'GET',
    dataType: 'json',
    success: function(response) {
      // ... implementation
    }
  });
}

// Both init phases can call this single function
```

---

### 2. **Global State Variables Without Proper Scoping**
**Severity**: Medium | **Priority**: High

```javascript
// Track processing state
let processingQueues = {};
let lastActivityTimes = {}; // Store last activity timestamps by queue ID
let autoRefreshEnabled = true;
let autoRefreshInterval = null;
let countdownInterval = null;
let countdownValue = 5;
```

**Issues**:
- Module-level variables could be accessed/modified unexpectedly
- No encapsulation
- Difficult to test
- Could cause conflicts if file loaded multiple times

**Recommendation**:
```javascript
// Create proper module namespace
const QueueControlsState = {
  processingQueues: {},
  lastActivityTimes: {},
  autoRefreshEnabled: true,
  autoRefreshInterval: null,
  countdownInterval: null,
  countdownValue: 5,
  REFRESH_SECONDS: 5,
  MAX_LOG_ENTRIES: 20,
  
  reset: function() {
    this.processingQueues = {};
    this.lastActivityTimes = {};
    this.autoRefreshEnabled = true;
    this.autoRefreshInterval = null;
    this.countdownInterval = null;
    this.countdownValue = 5;
  }
};

// Usage:
QueueControlsState.processingQueues[queueId] = true;
```

---

### 3. **Race Conditions with Concurrent AJAX Requests**
**Severity**: Medium | **Priority**: High

```javascript
jobIds.forEach(function(jobId, index) {
  setTimeout(function() {
    $.ajax({
      // ... request
      success: function (response) {
        completed++;
        // ...
        if (completed === jobIds.length) {
          // Action
        }
      }
    });
  }, index * 300); // Stagger by 300ms
});
```

**Issues**:
- Staggered timeouts don't account for network delays
- If first request takes > 300ms, second starts before first completes
- Could cause race conditions in counter logic
- No concurrency limit

**Recommendation**:
```javascript
async function validateAllJobsWithLimit(jobIds, token, concurrency = 3) {
  let validCount = 0;
  let invalidCount = 0;
  const results = [];
  
  // Process in batches of concurrency
  for (let i = 0; i < jobIds.length; i += concurrency) {
    const batch = jobIds.slice(i, i + concurrency);
    
    const batchPromises = batch.map(jobId =>
      new Promise((resolve, reject) => {
        $.ajax({
          url: '/jobhunter/queue/run',
          type: 'POST',
          dataType: 'json',
          data: { queue_id: jobId },
          timeout: 30000,
          success: function(response) {
            if (response.status === 'valid') validCount++;
            else invalidCount++;
            results.push(response);
            resolve(response);
          },
          error: function(xhr) {
            invalidCount++;
            reject(xhr);
          }
        });
      })
    );
    
    try {
      await Promise.allSettled(batchPromises);
    } catch (e) {
      console.error('Batch processing error:', e);
    }
    
    // Update progress
    const progress = Math.round(((i + batch.length) / jobIds.length) * 100);
    updateProgressDisplay(progress);
  }
  
  return { validCount, invalidCount, results };
}
```

---

### 4. **Memory Leaks: Intervals Not Always Cleared**
**Severity**: Medium | **Priority**: High

```javascript
function startAutoRefresh() {
  if (!autoRefreshEnabled) return;
  
  countdownValue = REFRESH_SECONDS;
  $('#auto-refresh-countdown').text(countdownValue);
  
  // Clear existing intervals
  if (countdownInterval) clearInterval(countdownInterval);
  if (autoRefreshInterval) clearTimeout(autoRefreshInterval);
  
  countdownInterval = setInterval(function() {
    // ... countdown
  }, 1000);
  
  autoRefreshInterval = setTimeout(function() {
    silentRefreshQueueStatus();
    loadRecentLogs();
    startAutoRefresh(); // Recursive call - could create unbounded intervals
  }, REFRESH_SECONDS * 1000);
}
```

**Issues**:
- Recursive `startAutoRefresh()` could create interval leak
- No cleanup on page unload/detach
- Intervals persist after behavior detaches

**Recommendation**:
```javascript
function startAutoRefresh() {
  if (!QueueControlsState.autoRefreshEnabled) return;
  
  QueueControlsState.countdownValue = REFRESH_SECONDS;
  $('#auto-refresh-countdown').text(QueueControlsState.countdownValue);
  
  // Ensure cleanup before starting new intervals
  QueueControlsState.stopAutoRefresh();
  
  QueueControlsState.countdownInterval = setInterval(function() {
    QueueControlsState.countdownValue--;
    $('#auto-refresh-countdown').text(QueueControlsState.countdownValue);
    
    if (QueueControlsState.countdownValue <= 0) {
      clearInterval(QueueControlsState.countdownInterval);
    }
  }, 1000);
  
  QueueControlsState.autoRefreshInterval = setTimeout(function() {
    silentRefreshQueueStatus();
    loadRecentLogs();
    
    // Only restart if still enabled
    if (QueueControlsState.autoRefreshEnabled) {
      startAutoRefresh();
    }
  }, REFRESH_SECONDS * 1000);
}

// Add proper detach cleanup
Drupal.behaviors.queueControls = {
  attach: function(context, settings) {
    // ... existing code
  },
  
  detach: function(context, settings, trigger) {
    if (trigger === 'unload') {
      QueueControlsState.stopAutoRefresh();
      QueueControlsState.reset();
    }
  }
};
```

---

### 5. **String-Based Selectors Too Fragile**
**Severity**: Low | **Priority**: Medium

```javascript
const row = $('.queue-row[data-queue-id="' + queueId + '"]');
const badge = row.find('[data-count]');
const runBtn = row.find('.btn-run-queue');
const queueName = row.find('.queue-name strong').text();
```

**Issues**:
- Assumes specific HTML structure
- `.queue-name strong` breaks if HTML changes
- Multiple DOM queries less efficient

**Recommendation**:
```javascript
function getQueueRow(queueId) {
  return $('[data-queue-id="' + queueId + '"]');
}

function getQueueControls(row) {
  return {
    badge: row.find('[data-count]'),
    runBtn: row.find('[data-run-btn]'), // Use specific attribute
    nameEl: row.find('[data-queue-name]'), // Use attribute, not selector
    statusIndicator: row.find('[data-status]'),
    lastActivity: row.find('[data-last-activity]')
  };
}

// Usage:
const row = getQueueRow(queueId);
const controls = getQueueControls(row);
controls.badge.text(count);
```

---

### 6. **No Input Validation on Queue IDs**
**Severity**: Medium | **Priority**: High

```javascript
const queueId = btn.data('queue');

$.ajax({
  data: { queue_id: queueId }
});
```

**Issue**: `queueId` could be:
- Undefined
- Invalid format (non-numeric)
- Malicious value

**Recommendation**:
```javascript
function validateQueueId(queueId) {
  if (!queueId) throw new Error('Queue ID missing');
  
  const id = parseInt(queueId, 10);
  if (isNaN(id) || id <= 0) {
    throw new Error('Invalid queue ID: ' + queueId);
  }
  
  return id;
}

try {
  const queueId = validateQueueId(btn.data('queue'));
  runQueue(queueId, btn, row);
} catch (error) {
  showMessage('error', Drupal.t('Invalid queue: @error', { '@error': error.message }));
}
```

---

### 7. **No Timeout on AJAX Requests**
**Severity**: Medium | **Priority**: High

```javascript
$.ajax({
  url: '/jobhunter/queue/run',
  type: 'POST',
  dataType: 'json',
  data: { queue_id: queueId },
  // ... no timeout
  success: function(response) {
```

**Issue**: Request could hang indefinitely.

**Recommendation**:
```javascript
$.ajax({
  url: '/jobhunter/queue/run',
  type: 'POST',
  dataType: 'json',
  data: { queue_id: queueId },
  timeout: 60000, // 60 seconds for long operations
  
  success: function(response) { /* ... */ },
  error: function(xhr, status, error) {
    if (status === 'timeout') {
      showMessage('error', Drupal.t('Request timed out. Please try again.'));
    }
  }
});
```

---

### 8. **XSS Vulnerability in Log Entry HTML**
**Severity**: Low | **Priority**: Medium

```javascript
function addLogEntry(message, type) {
  // ...
  const entry = $('<div class="log-entry log-' + typeClass + '">' +
    '<span class="log-time">' + timestamp + '</span> ' +
    '<span class="log-icon">' + (icons[typeClass] || 'ℹ️') + '</span> ' +
    '<span class="log-message">' + message + '</span>' +
    '</div>');
```

**Issue**: `message` is interpolated directly into HTML. If message contains user data, could be XSS vector.

**Recommendation**:
```javascript
function addLogEntry(message, type) {
  const logContainer = $('#log-entries');
  if (!logContainer.length) return;

  const timestamp = formatTime(new Date());
  const typeClass = type || 'info';
  const icons = {
    'success': '✅',
    'error': '❌',
    'info': 'ℹ️',
    'processing': '⏳',
    'warning': '⚠️'
  };
  
  // Use jQuery to safely create elements
  const entry = $('<div>')
    .addClass('log-entry log-' + typeClass)
    .append($('<span class="log-time">').text(timestamp))
    .append(' ')
    .append($('<span class="log-icon">').text(icons[typeClass] || 'ℹ️'))
    .append(' ')
    .append($('<span class="log-message">').text(message)); // text() escapes HTML
  
  logContainer.prepend(entry);
  
  // Limit entries
  const entries = logContainer.find('.log-entry');
  if (entries.length > MAX_LOG_ENTRIES) {
    entries.slice(MAX_LOG_ENTRIES).remove();
  }
}
```

---

### 9. **Accessibility: No Live Region for Log Entries**
**Severity**: Medium | **Priority**: High

```javascript
function addLogEntry(message, type) {
  const entry = $('<div class="log-entry log-' + typeClass + '">
    // ...
  );
  
  logContainer.prepend(entry);
}
```

**Issue**: Screen readers won't know about new log entries added via AJAX.

**Recommendation**:
```javascript
function addLogEntry(message, type) {
  // ... existing code to create entry ...
  
  const logContainer = $('#log-entries');
  
  // Ensure container is marked as live region
  logContainer.attr({
    'role': 'log',
    'aria-live': type === 'error' ? 'assertive' : 'polite',
    'aria-label': Drupal.t('Queue activity log')
  });
  
  logContainer.prepend(entry);
  
  // Also announce important messages
  if (type === 'success' || type === 'error') {
    Drupal.announce(message, type === 'error' ? 'assertive' : 'polite');
  }
}
```

---

### 10. **Potential Division by Zero**
**Severity**: Low | **Priority**: Low

```javascript
// Update progress
$btn.html('<span class="loading-spinner"></span> ' + completed + '/' + jobIds.length);

// When all complete
if (completed === jobIds.length) {
```

**Issue**: If `jobIds.length` is 0, division could occur in calculating percentage.

**Recommendation**:
```javascript
if (jobIds.length === 0) {
  showMessage('info', Drupal.t('No queues to process'));
  $btn.prop('disabled', false);
  $btn.html('Run All Queues');
  return;
}

// Now safe to use jobIds.length in calculations
```

---

### 11. **No Handling of CSRF Token Expiry**
**Severity**: Medium | **Priority**: High

The module uses `drupalSettings.csrf_token` directly without refresh mechanism.

**Issue**: If token expires during long auto-refresh session, all requests fail.

**Recommendation**:
```javascript
function getCsrfToken() {
  const token = drupalSettings.csrf_token || 
                document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  
  if (!token) {
    // Fetch fresh token
    return fetch('/session/token')
      .then(response => response.text())
      .then(newToken => {
        drupalSettings.csrf_token = newToken;
        return newToken;
      });
  }
  
  return Promise.resolve(token);
}

// Update AJAX calls to use this
getCsrfToken().then(token => {
  $.ajax({
    url: '/jobhunter/queue/run',
    headers: { 'X-CSRF-Token': token }
  });
});
```

---

### 12. **Missing Error Recovery for Network Failures**
**Severity**: Low | **Priority**: Medium

```javascript
error: function() {
  // Silently fail - keep existing logs
}
```

**Issue**: Network errors not reported to user. Auto-refresh might be failing silently.

**Recommendation**:
```javascript
error: function(xhr) {
  let errorMsg = Drupal.t('Failed to load queue logs');
  
  if (xhr.status === 0) {
    errorMsg = Drupal.t('Network error - check connection');
  } else if (xhr.status === 401) {
    errorMsg = Drupal.t('Session expired - please reload page');
  } else if (xhr.status >= 500) {
    errorMsg = Drupal.t('Server error - try again later');
  }
  
  // Log error but don't replace all logs
  addLogEntry(errorMsg, 'error');
}
```

---

## 🔒 Security Assessment

| Aspect | Status | Notes | Priority |
|--------|--------|-------|----------|
| CSRF Protection | ✅ GOOD | Token in headers | ✅ |
| Input Validation | ⚠️ WEAK | No queue ID validation | 🟠 |
| XSS Prevention | ⚠️ RISKY | HTML string concatenation in logs | 🟠 |
| Token Expiry | ❌ NO | Token not refreshed | 🟠 |
| Rate Limiting | ⚠️ FAIR | Staggered but not limited | 🟠 |
| Memory Management | ⚠️ POOR | Intervals could leak | 🟠 |

---

## 📊 Code Quality Metrics

| Metric | Score | Notes |
|--------|-------|-------|
| Security | 6/10 | CSRF protected but gaps exist |
| Maintainability | 4/10 | Large file, code duplication |
| Error Handling | 5/10 | Inconsistent patterns |
| Accessibility | 5/10 | Partial support |
| Performance | 6/10 | Race conditions risk |
| **OVERALL** | **5.2/10** | Complex file needing refactor |

---

## 🎯 Priority Actions

### IMMEDIATE (Critical)
1. ❌ Fix race conditions in batch operations (use Promise.allSettled)
2. ❌ Add queue ID validation
3. ❌ Add AJAX request timeouts
4. ❌ Fix XSS in log entries (use text() not HTML)
5. ❌ Remove duplicate loadRecentLogs function

### HIGH PRIORITY (Before 1.0)
1. ❌ Fix interval memory leaks
2. ❌ Add behavior detach cleanup
3. ❌ Implement CSRF token refresh
4. ❌ Add accessibility live region for logs
5. ❌ Improve error recovery
6. ❌ Create state object for encapsulation

### MEDIUM PRIORITY
1. ⚠️ Use data attributes for selectors
2. ⚠️ Split into smaller modules
3. ⚠️ Add JSDoc comments
4. ⚠️ Add unit tests

---

## Browser Compatibility

| Feature | Chrome | Firefox | Safari | IE 11 |
|---------|--------|---------|--------|-------|
| jQuery 3.x | ✅ Full | ✅ Full | ✅ Full | ✅ Full |
| Promise/async | 32+ | 29+ | 8+ | ❌ Needs polyfill |
| Date.toLocaleTimeString | All | All | All | 11+ |

---

## ✏️ Summary

### Positive Aspects
- ✅ Comprehensive feature set
- ✅ CSRF token protection
- ✅ Auto-refresh with countdown
- ✅ Activity logging

### Critical Issues
- 🔴 Race conditions in concurrent requests
- 🔴 Memory leaks from uncleaned intervals
- 🔴 Potential XSS in log entries
- 🔴 No input validation
- 🔴 Code duplication

### Recommendations Before 1.0

1. **CRITICAL**: Fix race conditions using Promise.allSettled
2. **CRITICAL**: Add memory cleanup on detach
3. **CRITICAL**: Fix XSS vulnerability in logs
4. **HIGH**: Add input validation for all IDs
5. **HIGH**: Add AJAX timeouts
6. **HIGH**: Add CSRF token refresh
7. **MEDIUM**: Refactor into smaller modules
8. **MEDIUM**: Remove code duplication

---

**Review Date**: 2024
**Overall Status**: ⚠️ NEEDS REFACTORING - Complex logic with concurrency issues
**Severity**: 🔴 CRITICAL - Race conditions and memory leaks must be fixed before production
