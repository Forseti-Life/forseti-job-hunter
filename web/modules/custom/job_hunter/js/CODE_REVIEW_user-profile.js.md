# Code Review: user-profile.js

## Overview
Module for user profile management with progress tracking, form validation, and auto-save capabilities.

**File Size**: ~10.7 KB | **Complexity**: High | **jQuery Usage**: Heavy

---

## ✅ Strengths

1. **Real-time Feedback**: Progress bar updates as user types
2. **Comprehensive Validation**: URL, salary range, character count validation
3. **Accessibility**: Uses Drupal.t() for translations and Drupal.announce() for alerts
4. **Form Organization**: Supports expandable fieldsets
5. **Modern Browser Features**: IntersectionObserver for scroll animations
6. **Good Documentation**: Extensive JSDoc and inline comments

---

## ⚠️ Issues & Recommendations

### 1. **Excessive Console.log in Production**
**Severity**: Low | **Priority**: Low

```javascript
console.log('user-profile.js loaded - version 1.2');
console.log('typeof once:', typeof once);
console.log('typeof $.fn.once:', typeof $.fn.once);
console.log('Drupal version:', Drupal.drupalSettings ? 'settings available' : 'no settings');

// In attach function:
console.log('jobApplicationUserProfile.attach called');
console.log('context:', context);
```

**Issue**: Debug logging should be removed before production.

**Recommendation**:
```javascript
// Development only
if (drupalSettings && drupalSettings.debug) {
  console.log('user-profile.js loaded - version 1.2');
}

// Remove other debug logs or wrap in conditional
```

---

### 2. **URL Validation Regex Too Simple**
**Severity**: Medium | **Priority**: Medium

```javascript
function isValidUrl(string) {
  try {
    new URL(string);
    return true;
  } catch (_) {
    return false;
  }
}
```

**Issue**: Accepts any valid URL, not checking for http/https. Example: `file://` would pass.

**Recommendation**:
```javascript
function isValidUrl(string) {
  try {
    const url = new URL(string);
    // Only allow http and https
    if (!['http:', 'https:'].includes(url.protocol)) {
      return false;
    }
    // Optionally restrict to known domains
    const allowedDomains = ['linkedin.com', 'github.com', 'portfolio domains'];
    return allowedDomains.some(domain => url.hostname.includes(domain));
  } catch (_) {
    return false;
  }
}
```

---

### 3. **Weak File Type Validation**
**Severity**: Medium | **Priority**: High

```javascript
if ($field.is('input[type="file"]')) {
  hasValue = $field[0].files && $field[0].files.length > 0;
}
```

**Issue**: No validation of file type, size, or actual content.

**Recommendation**:
```javascript
if ($field.is('input[type="file"]')) {
  if ($field[0].files && $field[0].files.length > 0) {
    const file = $field[0].files[0];
    
    // Validate file type
    const allowedTypes = ['application/pdf', 'application/msword'];
    if (!allowedTypes.includes(file.type)) {
      showFieldError($field, Drupal.t('Only PDF and Word documents are allowed.'));
      hasValue = false;
    } 
    // Validate file size (e.g., max 5MB)
    else if (file.size > 5 * 1024 * 1024) {
      showFieldError($field, Drupal.t('File size must be less than 5MB.'));
      hasValue = false;
    } else {
      hasValue = true;
    }
  }
}
```

---

### 4. **Missing CSRF Token for Auto-Save**
**Severity**: 🔴 CRITICAL | **Priority**: IMMEDIATE

```javascript
function initAutoSave($form) {
  let saveTimeout;
  const saveDelay = 5000; // 5 seconds

  $form.find('input, select, textarea').on('change', function() {
    // Here you would implement AJAX save
    // For now, just show a saved indicator
  });
}
```

**Issue**: Auto-save is commented out, but when implemented it needs CSRF token.

**Recommendation**:
```javascript
function initAutoSave($form) {
  let saveTimeout;
  const saveDelay = 5000; // 5 seconds

  $form.find('input, select, textarea').on('change', function() {
    clearTimeout(saveTimeout);
    showSavingIndicator();
    
    saveTimeout = setTimeout(function() {
      // Get form data
      const formData = {
        // ... collect form fields
      };
      
      // Make AJAX save with CSRF protection
      $.ajax({
        url: '/api/profile/save',
        method: 'POST',
        contentType: 'application/json',
        headers: {
          'X-CSRF-Token': getCsrfToken()
        },
        data: JSON.stringify(formData),
        success: function(response) {
          if (response.success) {
            showSavedIndicator();
          } else {
            showSavingIndicator('error');
          }
        },
        error: function() {
          showSavingIndicator('error');
        }
      });
    }, saveDelay);
  });
}

function getCsrfToken() {
  return drupalSettings.csrf_token || 
         document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}
```

---

### 5. **No Debouncing on Form Field Monitoring**
**Severity**: Low | **Priority**: Medium

```javascript
$form.find('input, select, textarea').on('change keyup', function() {
  setTimeout(function() {
    updateProfileCompleteness($form, requiredFields);
  }, 300); // Debounce updates
});
```

**Issue**: Timer is cleared but could still have multiple calculations queued.

**Recommendation**:
```javascript
const debounceTimers = new Map();

$form.find('input, select, textarea').on('change keyup', function() {
  const fieldName = this.name;
  
  // Clear existing timer for this field
  if (debounceTimers.has(fieldName)) {
    clearTimeout(debounceTimers.get(fieldName));
  }
  
  // Set new timer
  const timer = setTimeout(function() {
    updateProfileCompleteness($form, requiredFields);
    debounceTimers.delete(fieldName);
  }, 300);
  
  debounceTimers.set(fieldName, timer);
});

// Cleanup on detach
Drupal.behaviors.jobApplicationUserProfile.detach = function() {
  debounceTimers.forEach(timer => clearTimeout(timer));
  debounceTimers.clear();
};
```

---

### 6. **No Memory Cleanup on Behavior Detach**
**Severity**: Medium | **Priority**: High

```javascript
Drupal.behaviors.jobApplicationUserProfile = {
  attach: function (context, settings) {
    // ... attaches handlers
  }
  // No detach function
};
```

**Issue**: Event listeners remain attached when behavior is detached. Memory leak risk.

**Recommendation**:
```javascript
Drupal.behaviors.jobApplicationUserProfile = {
  attach: function (context, settings) {
    // ... existing code ...
  },
  
  detach: function(context, settings, trigger) {
    if (trigger === 'unload') {
      // Remove event listeners
      $(context).find('input, select, textarea').off('change keyup');
      $(context).find('fieldset legend').off('click');
      $(context).find('input[type="url"]').off('blur');
      
      // Clear intervals/timeouts
      if (this.debounceTimer) clearTimeout(this.debounceTimer);
      
      // Cleanup IntersectionObserver if created
      if (this.observer) this.observer.disconnect();
    }
  }
};
```

---

### 7. **Accessibility: Missing ARIA Labels and Live Regions**
**Severity**: Medium | **Priority**: High

```javascript
// Progress bar updates but no announcement
$progressText.text(Drupal.t('Profile Completeness: @percent%', { '@percent': completeness }));

// Field errors added but not announced
showFieldError($field, Drupal.t('Please enter a valid URL.'));
```

**Issue**: Users with screen readers won't know about changes.

**Recommendation**:
```javascript
function updateProfileCompleteness($form, requiredFields) {
  // ... existing code ...
  
  const completeness = Math.round((completedWeight / totalWeight) * 100);
  const $progressFill = $form.find('.profile-progress-fill');
  const $progressText = $form.find('.profile-progress-text');
  
  if ($progressFill.length) {
    $progressFill.animate({ width: completeness + '%' }, 500);
    $progressFill.attr('aria-valuenow', completeness);
  }
  
  if ($progressText.length) {
    const message = Drupal.t('Profile Completeness: @percent%', { '@percent': completeness });
    $progressText.text(message);
    $progressText.attr('aria-label', message);
    
    // Announce major milestones
    if (completeness === 50 || completeness === 75 || completeness === 100) {
      Drupal.announce(message, 'polite');
    }
  }
}

function showFieldError($field, message) {
  clearFieldError($field);
  const $error = $('<div class="field-error" role="alert" aria-live="assertive">' + message + '</div>');
  $field.addClass('error').attr('aria-invalid', 'true').after($error);
  
  // Announce to screen readers
  Drupal.announce(message, 'assertive');
}
```

---

### 8. **Inconsistent Variable Scoping**
**Severity**: Low | **Priority**: Low

Mix of function scope and form scope for tracking state.

**Issue**: Makes it harder to track state across the module.

**Recommendation**: Create a module-level state object:
```javascript
const ProfileState = {
  debounceTimer: null,
  observer: null,
  currentProgress: 0,
  
  cleanup: function() {
    if (this.debounceTimer) clearTimeout(this.debounceTimer);
    if (this.observer) this.observer.disconnect();
  }
};
```

---

### 9. **Incomplete Fieldset Collapse/Expand Logic**
**Severity**: Low | **Priority**: Medium

```javascript
function initSectionToggling($form) {
  // ... setup code ...
  
  $legend.on('click', function() {
    $fieldset.toggleClass('collapsed');
    
    // Animate the content
    const $content = $fieldset.find('.fieldset-wrapper');
    $content.slideToggle(300);
  });
}

// Initially collapse non-essential sections
$toggleFieldsets.not(':first').addClass('collapsed').find('.fieldset-wrapper').hide();
```

**Issues**:
- No keyboard support (Enter/Space on legend)
- No aria-expanded attribute
- Legend not marked as interactive

**Recommendation**:
```javascript
function initSectionToggling($form) {
  const $toggleFieldsets = $form
    .find('fieldset')
    .not('.no-toggle-fieldset')
    .not('.form-composite')
    .not('.fieldgroup');

  $toggleFieldsets.each(function() {
    const $fieldset = $(this);
    const $legend = $fieldset.find('legend');
    
    if ($legend.length) {
      // Mark as button-like
      $legend.addClass('clickable-legend')
             .attr({
               'tabindex': '0',
               'role': 'button',
               'aria-expanded': 'false'
             });
      
      // Handle click
      $legend.on('click keydown', function(e) {
        // Allow Space and Enter keys
        if (e.type === 'keydown' && e.keyCode !== 32 && e.keyCode !== 13) return;
        
        e.preventDefault();
        const isCollapsed = $fieldset.hasClass('collapsed');
        
        $fieldset.toggleClass('collapsed');
        $legend.attr('aria-expanded', !isCollapsed);
        
        const $content = $fieldset.find('.fieldset-wrapper');
        $content.slideToggle(300);
      });
    }
  });
  
  // Initially collapse non-essential sections
  $toggleFieldsets.not(':first').each(function() {
    $(this).addClass('collapsed').find('.fieldset-wrapper').hide();
    $(this).find('legend').attr('aria-expanded', 'false');
  });
}
```

---

### 10. **No Validation of Server Data**
**Severity**: Low | **Priority**: Medium

```javascript
// Check if server has already calculated completeness
const $progressText = $form.find('.profile-progress-text');
const serverProgress = $progressText.attr('data-progress');

if (serverProgress !== undefined && serverProgress !== null) {
  console.log('Using server-calculated progress:', serverProgress + '%');
  updateFormStyling($form, parseInt(serverProgress));
}
```

**Issue**: No validation that serverProgress is a valid number 0-100.

**Recommendation**:
```javascript
function getServerProgress($progressText) {
  const value = $progressText.attr('data-progress');
  
  if (value === undefined || value === null) return null;
  
  const parsed = parseInt(value, 10);
  
  // Validate range
  if (isNaN(parsed) || parsed < 0 || parsed > 100) {
    console.error('Invalid server progress value:', value);
    return null;
  }
  
  return parsed;
}
```

---

### 11. **Missing Internationalization for Character Counter**
**Severity**: Low | **Priority**: Low

```javascript
$counter.text(Drupal.t('@remaining characters remaining', { '@remaining': remaining }));
```

**Issue**: Should also show current character count for clarity.

**Recommendation**:
```javascript
$counter.text(Drupal.t('@current / @max characters', { 
  '@current': current, 
  '@max': maxLength 
}));
```

---

## 🔒 Security Assessment

| Aspect | Status | Notes | Priority |
|--------|--------|-------|----------|
| CSRF Protection | ⚠️ N/A | Auto-save not implemented | ⚠️ |
| URL Validation | ⚠️ PARTIAL | Allows any protocol | 🟠 |
| File Validation | ⚠️ WEAK | No type/size validation | 🟠 |
| Input Sanitization | ✅ SAFE | Using Drupal.t() | ✅ |
| XSS Prevention | ✅ SAFE | HTML not interpolated | ✅ |
| Debug Info Leakage | ⚠️ FAIR | Excessive console.log | 🟠 |

---

## 📊 Code Quality Metrics

| Metric | Score | Notes |
|--------|-------|-------|
| Security | 6/10 | URL validation weak, no CSRF in auto-save |
| Maintainability | 6/10 | Some inconsistency, good structure |
| Accessibility | 5/10 | Missing ARIA attributes |
| Performance | 7/10 | Good use of animations and debouncing |
| Error Handling | 6/10 | Decent but could be better |
| **OVERALL** | **6/10** | Good foundation, needs refinements |

---

## 🎯 Priority Actions

### HIGH PRIORITY (Before 1.0)
1. ❌ Add CSRF token to auto-save implementation
2. ❌ Improve URL validation (protocol/domain check)
3. ❌ Add file type and size validation
4. ❌ Add accessibility attributes (aria-expanded, role, aria-live)
5. ❌ Implement behavior detach cleanup

### MEDIUM PRIORITY
1. ⚠️ Remove debug console.log statements
2. ⚠️ Validate server progress data
3. ⚠️ Add keyboard support to fieldset toggle
4. ⚠️ Create module state object for clarity
5. ⚠️ Improve error handling consistency

### LOW PRIORITY
1. 📝 Add JSDoc comments to all functions
2. 📝 Improve character counter display format
3. 📝 Add performance profiling
4. 📝 Add unit tests

---

## Browser Compatibility

| Feature | Chrome | Firefox | Safari | IE 11 |
|---------|--------|---------|--------|-------|
| IntersectionObserver | 51+ | 55+ | 12.1+ | ❌ Needs polyfill |
| Array.forEach | All | All | All | 9+ |
| URL constructor | 32+ | 26+ | 10+ | ❌ Needs polyfill |
| classList | 22+ | 3.6+ | 5.1+ | ✅ 10+ |

---

## ✏️ Summary

### Positive Aspects
- ✅ Real-time progress feedback
- ✅ Comprehensive form validation
- ✅ Good Drupal integration
- ✅ Modern browser features
- ✅ Accessibility considerations

### Issues Found
- 🔴 Auto-save missing CSRF protection
- 🟠 URL validation too permissive
- 🟠 No file type/size validation
- 🟠 Missing accessibility attributes
- 🟠 Memory leak (no detach cleanup)

### Before Production Deployment

1. **CRITICAL**: Implement CSRF protection for auto-save
2. **HIGH**: Add accessibility attributes (aria-expanded, aria-live, etc.)
3. **HIGH**: Implement behavior detach cleanup
4. **HIGH**: Improve URL and file validation
5. **MEDIUM**: Remove debug console.log statements
6. **MEDIUM**: Add keyboard support to fieldset toggle

---

**Review Date**: 2024
**Overall Status**: ⚠️ GOOD - Functional with important security and accessibility gaps
**Severity**: 🟠 MEDIUM - Address CSRF and accessibility issues before production
