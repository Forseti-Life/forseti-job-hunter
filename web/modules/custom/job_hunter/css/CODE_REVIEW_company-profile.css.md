# Code Review: company-profile.css

**File:** `/sites/forseti/web/modules/custom/job_hunter/css/company-profile.css`  
**Review Date:** 2024  
**Lines:** 379  

## Summary
Dark-themed, professional styling with good visual hierarchy. However, extensive use of `!important`, high specificity selectors, and hardcoded values create maintainability concerns. Browser compatibility issues and accessibility gaps need attention.

---

## ✅ Strengths

1. **Visual Design**: Professional dark theme with good contrast
2. **Hover States**: Interactive elements have smooth transitions
3. **Section Organization**: Clear use of fieldsets and grouping
4. **Icon Integration**: Creative use of emoji and content property
5. **Responsive**: Mobile breakpoint included

---

## ⚠️ Critical Issues

### 1. **Excessive !important Usage (CRITICAL)**
- **Issue**: `!important` used 18+ times throughout file
- **Lines**: 196, 199, 200, 201, 203-207, 212-218, 250, 253-256, 262-265, 334-344, 346, 348-350, 352-356, 369-378
- **Examples**:
  ```css
  .node--type-company .field__item a {
    color: #90caf9 !important;
    text-decoration: none !important;
    /* ... more !important rules */
  }
  ```
- **Why it's bad**: 
  - Makes CSS unmaintainable
  - Impossible to override without more `!important`
  - Indicates poor CSS architecture
- **Fix**: Use proper selector specificity instead
- **Impact**: CRITICAL - Refactor needed

### 2. **High Specificity Selectors (HIGH)**
- **Issue**: Multiple class selectors chain together
- **Examples**:
  - `.node--type-company .field__item a` (4 selectors)
  - `.node--type-company .field__label` (3 selectors)
- **Better**:
  ```css
  .company-link { /* instead of .node--type-company .field__item a */ }
  .company-field-label { /* instead of .node--type-company .field__label */ }
  ```
- **Impact**: Easier overrides, better performance

### 3. **Browser Compatibility Issues (MEDIUM)**
- **Issue**: `:contains()` pseudo-class doesn't exist in CSS
- **Lines**: 346, 348, 352, 354
  ```css
  .node--type-company .field--name-field-active .field__item:contains("Off") {
    /* :contains() is NOT valid CSS */
  }
  ```
- **Reality**: This selector won't work in any browser
- **Fix**: Use JavaScript or data attributes instead
- **Impact**: Status styling won't work

### 4. **Content Property Misuse (MEDIUM)**
- **Issue**: Using `content` property for visual design
- **Lines**: 220-226, 358-366
  ```css
  .node--type-company .field__item a::before {
    content: "🔗";
  }
  ```
- **Problem**: Screen readers will read emoji aloud
- **Better**: Use background images or icon fonts
- **Impact**: Accessibility issue

### 5. **Hardcoded Colors (MEDIUM)**
- **Issue**: All colors hardcoded, no variables
- **Repeated**: `#64b5f6` (8+ times), `#1a1a2e` (3+ times), `#e0e0e0` (multiple)
- **Fix**: Use CSS variables for theme consistency

### 6. **Accessibility Concerns (MEDIUM)**
- **Issue 1**: Emoji in `::before` pseudo-elements (lines 220, 226, 229, 358, 363)
  - Screen readers read emojis aloud
- **Issue 2**: No focus states for keyboard navigation
- **Issue 3**: Color-only status indication (line 334-356)
  - Rely on color alone to indicate status
- **Recommendation**: Add proper focus states:
  ```css
  .company-link:focus-visible {
    outline: 2px solid #64b5f6;
    outline-offset: 2px;
  }
  ```

### 7. **Gradient Duplication (MEDIUM)**
- **Issue**: Same gradient repeated 5+ times
- **Lines**: 8, 27, 76, 115
  ```css
  background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
  ```
- **Better**: CSS variable
  ```css
  --gradient-primary: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
  ```

### 8. **Shadow Inconsistency (LOW)**
- **Issue**: Multiple shadow values
- **Lines**: 11, 30, 79, 118, 145, 216
  - `0 4px 20px rgba(0, 0, 0, 0.3)` vs others
- **Recommendation**: Define shadow scale

### 9. **Text-shadow Usage (LOW)**
- **Issue**: `text-shadow` on colored text (lines 49, 92, 374)
- **Better**: Use `letter-spacing` or font-weight for emphasis
- **Impact**: Text may become unreadable

### 10. **Media Query Gap (LOW)**
- **Issue**: Only 768px breakpoint, no 900px or 480px
- **Lines**: 300-331
- **Recommendation**: Add intermediate breakpoints

---

## 🔴 Non-Functional CSS Selectors

```css
/* These selectors WON'T WORK: */
.node--type-company .field--name-field-active .field__item:contains("Off")
.node--type-company .field--name-field-active .field__item:contains("On")
/* :contains() is NOT valid CSS (maybe planned for CSS Selectors Level 4) */
```

---

## 📋 Code Quality Metrics

| Metric | Status | Issues |
|--------|--------|---------|
| **Specificity** | 🔴 High | Chains of 4+ selectors |
| **!important** | 🔴 Critical | Used 18+ times |
| **Accessibility** | 🟡 Poor | No focus states, emoji in content |
| **Browser Support** | 🔴 Failed | `:contains()` not valid |
| **Maintainability** | 🔴 Low | High duplication |
| **Performance** | 🟡 Fair | High specificity slows matching |

---

## 🔧 Refactoring Priority

1. **CRITICAL**: Remove all `!important` statements
2. **CRITICAL**: Fix/remove `:contains()` selectors (won't work)
3. **HIGH**: Add focus-visible states
4. **HIGH**: Replace emoji content with accessible alternatives
5. **MEDIUM**: Extract colors to CSS variables
6. **MEDIUM**: Reduce selector specificity

---

## 📝 Example Fixes

**Before (BROKEN):**
```css
.node--type-company .field--name-field-active .field__item:contains("Off") {
  background: rgba(229, 115, 115, 0.2) !important;
  color: #e57373 !important;
}
```

**After (WORKING):**
```css
.field-active-status {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: 20px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.field-active-status.status-off {
  background: rgba(229, 115, 115, 0.2);
  color: #e57373;
  border: 1px solid rgba(229, 115, 115, 0.3);
}

.field-active-status.status-on {
  background: rgba(129, 199, 132, 0.2);
  color: #81c784;
  border: 1px solid rgba(129, 199, 132, 0.3);
}

.field-active-status::before {
  content: attr(data-status);  /* Use data attribute instead */
}
```

---

## ✨ Conclusion

**Overall Grade: C+**

**Critical Issues**:
- 18+ instances of `!important` (must refactor)
- `:contains()` selector doesn't work (won't function)
- Missing focus states (accessibility failure)
- High specificity (performance concern)

**Required Actions**:
1. Remove all `!important` - redesign selectors properly
2. Replace `:contains()` with data attributes or JavaScript
3. Add focus-visible states to all interactive elements
4. Replace emoji content with accessible alternatives
5. Extract colors to CSS variables

This file needs significant refactoring before it can be considered production-ready.

