# Code Review: target-companies.js

## Overview
Page interaction module for filtering and adding target companies to job application queue.

**File Size**: ~2.5 KB | **Complexity**: Low | **ES6 Usage**: Moderate

---

## ✅ Strengths

1. **Modern JavaScript**: Uses native DOM APIs (not jQuery-dependent)
2. **Once Pattern**: Correctly uses `once()` for safe re-attachment
3. **Clean Separation**: Filter logic separated from AJAX logic
4. **Decent Error Handling**: Try-catch in AJAX promise chain
5. **User Feedback**: Shows success/error messages and reloads page on success

---

## ⚠️ Issues & Recommendations

### 1. **Missing CSRF Token Protection**
**Severity**: 🔴 CRITICAL | **Priority**: IMMEDIATE

```javascript
fetch('/jobhunter/companies/add-quick', {
  method: 'POST',
  body: formData
})
```

**Issue**: No CSRF token in request headers. This is a security vulnerability.

**Recommendation**:
```javascript
// Get CSRF token from meta tag or Drupal
function getCsrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') 
    || drupalSettings.csrf_token;
}

// Use in fetch:
fetch('/jobhunter/companies/add-quick', {
  method: 'POST',
  headers: {
    'X-CSRF-Token': getCsrfToken(),
    'Content-Type': 'application/json'
  },
  body: formData
})
```

---

### 2. **Improper FormData Usage**
**Severity**: Medium | **Priority**: High

```javascript
const formData = new FormData();
formData.append('company_name', companyName);
```

**Issues**:
- `FormData` sets `Content-Type: multipart/form-data` automatically
- Server may expect `application/json`
- No CSRF token in FormData headers

**Recommendation**:
```javascript
fetch('/jobhunter/companies/add-quick', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': getCsrfToken()
  },
  body: JSON.stringify({ company_name: companyName })
})
```

---

### 3. **Page Reload Risk**
**Severity**: Medium | **Priority**: High

```javascript
setTimeout(function () {
  location.reload();
}, 1000);
```

**Issues**:
- User loses unsaved form data
- No scroll position restoration
- No indication to user that page is reloading
- Hard timeout (1000ms) might be too short on slow networks

**Recommendation**:
```javascript
// Option 1: Update DOM instead of reloading
if (data.success) {
  btn.outerHTML = '<span class="already-added">✓ ' + Drupal.t('Added to targets') + '</span>';
  
  // Update table if visible
  const tableRow = btn.closest('tr');
  if (tableRow) {
    tableRow.classList.add('newly-added');
  }
  
  // Show confirmation message
  Drupal.announce(Drupal.t('Company added successfully'), 'polite');
  
  // Optional: Refresh only necessary sections
  // updateTargetCompaniesList();
}

// Option 2: If reload necessary, show warning first
if (data.success) {
  Drupal.announce(Drupal.t('Company added. Page will refresh...'), 'assertive');
  setTimeout(() => { location.reload(); }, 1500);
}
```

---

### 4. **Global Window Function Pollution**
**Severity**: Medium | **Priority**: Medium

```javascript
window.addCompanyQuick = function (btn) {
```

**Issue**: Pollutes global namespace. Could conflict with other scripts.

**Recommendation**:
```javascript
// Keep in namespace
window.JobHunter = window.JobHunter || {};
window.JobHunter.addCompanyQuick = function (btn) {
  // implementation
};

// In HTML, use: onclick="JobHunter.addCompanyQuick(this)"
```

---

### 5. **Missing Input Validation**
**Severity**: Medium | **Priority**: High

```javascript
const companyName = btn.getAttribute('data-company');
const formData = new FormData();
formData.append('company_name', companyName);
```

**Issues**:
- No check if `companyName` is empty
- No sanitization
- No length validation

**Recommendation**:
```javascript
const companyName = (btn.getAttribute('data-company') || '').trim();

if (!companyName) {
  alert(Drupal.t('Company name is missing.'));
  return;
}

if (companyName.length > 255) {
  alert(Drupal.t('Company name is too long.'));
  return;
}

// Sanitize: remove any HTML
const sanitized = companyName.replace(/[<>\"']/g, '');
```

---

### 6. **Weak Error Handling**
**Severity**: Medium | **Priority**: Medium

```javascript
.catch(function (error) {
  console.error('Error:', error);
  alert(Drupal.t('An error occurred while adding the company.'));
});
```

**Issues**:
- Generic error message not helpful for debugging
- `console.error()` not suitable for production
- User doesn't know what went wrong

**Recommendation**:
```javascript
.catch(function (error) {
  // Log for debugging in dev, not in production
  if (drupalSettings && drupalSettings.debug) {
    console.error('addCompanyQuick error:', error);
  }
  
  // Show detailed error to user
  let message = Drupal.t('An error occurred while adding the company.');
  if (error.message) {
    message += ' (' + error.message + ')';
  }
  
  // Use Drupal status message instead of alert
  Drupal.announce(message, 'assertive');
  btn.disabled = false;
});
```

---

### 7. **Race Condition in Filter**
**Severity**: Low | **Priority**: Medium

```javascript
function filterCompanies() {
  const input = document.getElementById('company-filter');
  if (!input) return;
  // ...
}

// Attached to keyup, but no debouncing
filterInput.addEventListener('keyup', filterCompanies);
```

**Issue**: Filtering runs on every keystroke. Can cause performance issues with large tables.

**Recommendation**:
```javascript
function filterCompanies() {
  // implementation
}

// Debounce filtering
let filterTimeout;
filterInput.addEventListener('keyup', function() {
  clearTimeout(filterTimeout);
  filterTimeout = setTimeout(filterCompanies, 300);
});

// Also add to 'search' event for clear button
filterInput.addEventListener('search', filterCompanies);
```

---

### 8. **Missing Accessibility Features**
**Severity**: Medium | **Priority**: High

**Issues**:
- Filter input has no associated label
- Results count not announced to screen readers
- Buttons need aria-labels for icon-only buttons

**Recommendation**:
```javascript
// For filter:
const label = document.querySelector('label[for="company-filter"]');
if (label) {
  // Accessibility is good
}

// Announce filter results to screen readers
const visibleCountEl = document.getElementById('visible-count');
if (visibleCountEl) {
  Drupal.announce(Drupal.formatPlural(visibleCount, 
    '1 company matches your filter',
    '@count companies match your filter'
  ), 'polite');
}

// For buttons:
btn.setAttribute('aria-label', Drupal.t('Add @company to targets', { '@company': companyName }));
```

---

### 9. **No Dependency Injection**
**Severity**: Low | **Priority**: Low

The module doesn't properly handle the `once` parameter - it's not injected into the closure.

**Current**:
```javascript
(function (Drupal, once) {
```

**Better**:
```javascript
(function (Drupal, once, drupalSettings) {
  // Can now use drupalSettings for configuration
})(Drupal, once, drupalSettings);
```

---

## 🔒 Security Assessment

| Aspect | Status | Issues | Priority |
|--------|--------|--------|----------|
| CSRF Protection | ❌ FAILED | No token sent | 🔴 CRITICAL |
| XSS Prevention | ✅ Safe | Using FormData | ✅ |
| Input Validation | ⚠️ Partial | No sanitization | 🟠 HIGH |
| API Security | ❌ FAILED | No auth headers | 🔴 CRITICAL |
| DOM Mutation | ✅ Safe | Using innerHTML with Drupal.t() | ✅ |

---

## 📋 Code Quality Checklist

- ❌ CSRF token sent with request
- ⚠️ Input validation present but incomplete
- ❌ No FormData; use JSON instead
- ⚠️ Error handling basic
- ✅ Filter logic clean
- ❌ No debouncing on filter
- ❌ Accessibility limited
- ⚠️ Performance acceptable

**Overall Score: 5/10** (Security issues blocking use)

---

## 🎯 Critical Actions Required

### 1. IMMEDIATE (Security Blocker)
```javascript
// Add CSRF protection
headers: {
  'X-CSRF-Token': getCsrfToken(),
  'Content-Type': 'application/json'
}
```

### 2. HIGH PRIORITY
- Implement input validation
- Add debouncing to filter
- Improve error handling
- Add accessibility features

### 3. MEDIUM PRIORITY
- Avoid page reload (update DOM instead)
- Remove global function pollution
- Add JSDoc comments

---

## 📊 Browser Compatibility

| Feature | Chrome | Firefox | Safari | IE 11 |
|---------|--------|---------|--------|-------|
| fetch API | 42+ | 39+ | 10.1+ | ❌ |
| Promise | 32+ | 29+ | 8+ | ❌ |
| querySelector | 10+ | 3.5+ | 3.1+ | 8+ |
| FormData | 18+ | 4+ | 5+ | 10+ |

**Note**: fetch API not supported in IE 11. Consider polyfill or fallback.

---

## ✏️ Summary

### Positive Aspects
- Modern ES6 syntax
- Clean function organization
- Proper Drupal integration

### Critical Issues
- 🔴 **SECURITY**: No CSRF token protection
- 🔴 **SECURITY**: FormData/Content-Type mismatch
- 🟠 **UX**: Page reload loses user data
- 🟠 **PERFORMANCE**: No debouncing on filter

### Recommended Before Production
1. Add CSRF token handling
2. Implement input validation
3. Replace FormData with JSON
4. Add debouncing to filter
5. Improve error messages
6. Add accessibility attributes
7. Add JSDoc documentation

---

**Review Date**: 2024
**Severity Level**: 🔴 CRITICAL - Security issues found
**Status**: ⛔ BLOCKED - Requires security fixes before deployment
