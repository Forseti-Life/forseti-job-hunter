# US Government Power Page - Full Stack Analysis
## Accordion Collapse Issue Investigation

**Date:** January 7, 2026  
**Issue:** Accordions expand but won't collapse when clicked  
**Page:** `/agent-power-framework/us-government`

---

## 1. LIBRARY LOADING CHAIN

### Module Library: `forseti_safety_content/agent-power`
**Location:** `/web/modules/custom/forseti_safety_content/forseti_safety_content.libraries.yml`

```yaml
agent-power:
  version: 1.1
  css:
    theme:
      css/forseti-pages.css: {}
  js:
    js/agent-evaluation.js: {}
  dependencies:
    - core/drupal
    - core/once
    - forseti/style    # <-- THEME DEPENDENCY
```

### Theme Library: `forseti/style`
**Location:** `/web/themes/custom/forseti/forseti.libraries.yml`

```yaml
style:
  css:
    theme:
      build/css/main.style.css: {}        # Bootstrap 5.3.0 base + utilities
      build/css/forseti-theme.css: {}     # Custom theme overrides
      build/css/amisafe.css: {}           # Additional styles
  js:
    build/js/main.script.js: {}           # Bundled Bootstrap components
    https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js: 
      { type: external, minified: true }
  dependencies:
    - core/drupal
```

**FINDING:** Bootstrap 5.3.0 is loaded TWICE:
1. Via `build/js/main.script.js` (bundled/compiled version)
2. Via CDN external link

---

## 2. CSS FILES ANALYSIS

### A. `build/css/main.style.css`
- **Source:** Compiled Bootstrap 5.3.0 base
- **Size:** Minified single line
- **Contains:** Complete Bootstrap CSS including accordion styles
- **Key Classes:**
  - `.accordion`
  - `.accordion-button`
  - `.accordion-collapse`
  - `.collapsed`
  
**Accordion-specific CSS Variables:**
```css
--bs-accordion-btn-bg
--bs-accordion-btn-color
--bs-accordion-btn-icon
--bs-accordion-btn-active-icon
--bs-accordion-active-bg
--bs-accordion-active-color
```

### B. `build/css/forseti-theme.css`
**Location:** `/web/themes/custom/forseti/build/css/forseti-theme.css` (lines 125-155)

```css
/* CRITICAL OVERRIDE */
.accordion-forseti .accordion-item {
  background: #16213e;
  border: 1px solid rgba(0, 212, 255, 0.3);
  margin-bottom: 10px;
}

.accordion-forseti .accordion-button {
  background: #16213e;
  color: #00d4ff;
  border: none;
}

.accordion-forseti .accordion-button:not(.collapsed) {
  background: #1a1a2e;
  color: #00d4ff;
}

.accordion-forseti .accordion-button:focus {
  box-shadow: 0 0 0 0.2rem rgba(0, 212, 255, 0.25);
}

.accordion-forseti .accordion-button::after {
  filter: brightness(0) saturate(100%) invert(68%) sepia(82%) 
          saturate(1974%) hue-rotate(156deg);
}

.accordion-forseti .accordion-body {
  background: #1a1a2e;
  color: #e0e0e0;
  border-top: 1px solid rgba(0, 212, 255, 0.2);
}
```

**FINDING:** Theme provides `.accordion-forseti` wrapper class with full styling.

### C. `css/forseti-pages.css`
**Location:** `/web/modules/custom/forseti_safety_content/css/forseti-pages.css`
- **Lines:** 535 total
- **Content:** Hero sections, banners, page-specific styles
- **Accordion styles:** NONE found (searched for "accordion" - 0 matches)

**FINDING:** Module CSS file has no accordion overrides.

---

## 3. JAVASCRIPT FILES ANALYSIS

### A. Bootstrap JavaScript (DUAL LOAD ISSUE)

#### Load #1: `build/js/main.script.js`
- Bundled Bootstrap 5.3.0 components
- Includes: Collapse, Accordion, Tab, Modal, Dropdown, etc.
- Minified size: Large webpack bundle
- **Initialization:** Auto-initializes via data attributes

#### Load #2: CDN Bootstrap 5.3.0
```html
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
```
- External load
- Includes Popper.js
- **Initialization:** Auto-initializes via data attributes

**CRITICAL FINDING:** Bootstrap Collapse is loaded TWICE and may be initializing instances twice, causing conflicts.

### B. Module JavaScript: `js/agent-evaluation.js`
**Location:** `/web/modules/custom/forseti_safety_content/js/agent-evaluation.js`

```javascript
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.agentEvaluation = {
    attach: function (context, settings) {
      // Handles evaluation form submission
      // NO accordion-related code
    }
  };
})(Drupal, once);
```

**FINDING:** This JS file does NOT touch accordions. Only handles evaluation form.

### C. Template Inline JavaScript
**Location:** `forseti-us-government-power.html.twig` (lines 435-505)

```javascript
(function() {
  'use strict';
  
  function initUmbrellaControls() {
    if (typeof bootstrap === 'undefined') {
      console.error('Bootstrap is not loaded');
      return;
    }
    
    // Expand All Cards
    document.getElementById('expandAllCards').addEventListener('click', function(e) {
      e.preventDefault();
      const cardCollapses = document.querySelectorAll('.rank-collapse');
      cardCollapses.forEach(function(collapse) {
        const bsCollapse = bootstrap.Collapse.getInstance(collapse) 
                        || new bootstrap.Collapse(collapse, {toggle: false});
        bsCollapse.show();
      });
    });
    
    // Collapse All Cards
    document.getElementById('collapseAllCards').addEventListener('click', function(e) {
      e.preventDefault();
      const cardCollapses = document.querySelectorAll('.rank-collapse');
      cardCollapses.forEach(function(collapse) {
        const bsCollapse = bootstrap.Collapse.getInstance(collapse) 
                        || new bootstrap.Collapse(collapse, {toggle: false});
        bsCollapse.hide();
      });
    });
    
    // Similar for sub-dimensions...
  }
  
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initUmbrellaControls);
  } else {
    initUmbrellaControls();
  }
})();
```

**FINDING:** JavaScript creates Collapse instances with `{toggle: false}`. This may be registering event handlers that interfere with Bootstrap's default toggle behavior.

---

## 4. HTML MARKUP ANALYSIS

### Current Accordion Structure
```twig
<div class="accordion accordion-forseti" id="ranksAccordion">
  {% for rank in branches %}
    <div class="accordion-item">
      <h2 class="accordion-header" id="heading-{{ loop.index }}">
        <button class="accordion-button collapsed" 
                type="button" 
                data-bs-toggle="collapse" 
                data-bs-target="#collapse-card-{{ loop.index }}" 
                aria-expanded="false" 
                aria-controls="collapse-card-{{ loop.index }}" 
                aria-labelledby="heading-{{ loop.index }}">
          <div class="d-flex align-items-center w-100" style="pointer-events: none;">
            <div class="badge bg-info fs-5 p-2 me-3" style="min-width: 60px;">
              {{ rank.level_avg }}
            </div>
            <div class="flex-grow-1">
              <h5 class="text-cyan mb-0">{{ rank.position }}</h5>
              <div class="text-info small">{{ rank.title }}</div>
            </div>
          </div>
        </button>
      </h2>
      <div id="collapse-card-{{ loop.index }}" 
           class="accordion-collapse collapse rank-collapse" 
           aria-labelledby="heading-{{ loop.index }}">
        <div class="accordion-body p-4">
          <!-- Content -->
        </div>
      </div>
    </div>
  {% endfor %}
</div>
```

**Bootstrap 5.3.0 Required Attributes:**
- ✅ `data-bs-toggle="collapse"`
- ✅ `data-bs-target="#target"`
- ✅ `.accordion-collapse.collapse`
- ✅ `aria-expanded`
- ✅ `aria-controls`

**Issues Identified:**
1. ❌ No `data-bs-parent` attribute (removed intentionally)
2. ⚠️ `pointer-events: none` on inner content div
3. ⚠️ JavaScript creating Collapse instances manually
4. ⚠️ Nested `<div>` inside button may capture clicks

---

## 5. ROOT CAUSE ANALYSIS

### Problem: Accordion Expands but Won't Collapse

**Hypothesis 1: Dual Bootstrap Load Conflict** ⭐ LIKELY
- Bootstrap loaded twice (bundled + CDN)
- Two sets of Collapse instances may be created
- First instance handles expand, second can't find proper state
- **Test:** Remove one Bootstrap JS source

**Hypothesis 2: JavaScript Interference** ⭐ LIKELY
- Umbrella controls create Collapse instances with `{toggle: false}`
- These instances may prevent default toggle behavior
- Bootstrap may be confused about instance ownership
- **Test:** Remove umbrella control JavaScript entirely

**Hypothesis 3: Event Handler Conflict** ⭐ POSSIBLE
- Bootstrap's click handler may be overridden
- `pointer-events: none` on inner div should pass clicks through
- But nested structure might confuse event delegation
- **Test:** Simplify button content (no nested divs)

**Hypothesis 4: CSS Interference** ❌ UNLIKELY
- `.accordion-forseti` theme styles look correct
- No `pointer-events`, `z-index`, or `position` issues detected
- Arrow icons work via CSS filter (correct approach)

**Hypothesis 5: Missing data-bs-parent** ❌ NOT THE ISSUE
- `data-bs-parent` is for auto-closing other items
- Not required for basic toggle functionality
- Already removed per user request

---

## 6. RECOMMENDED FIXES (Priority Order)

### FIX #1: Remove Duplicate Bootstrap JS Load 🔥 CRITICAL
**Issue:** Bootstrap 5.3.0 loaded twice creates conflicting instances

**Action:** Edit `/web/themes/custom/forseti/forseti.libraries.yml`
```yaml
style:
  css:
    theme:
      build/css/main.style.css: {}
      build/css/forseti-theme.css: {}
      build/css/amisafe.css: {}
  js:
    build/js/main.script.js: {}
    # REMOVE THIS LINE:
    # https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js: 
    #   { type: external, minified: true }
  dependencies:
    - core/drupal
```

**Rationale:** The bundled `main.script.js` already includes Bootstrap. Loading it twice causes:
- Two separate `bootstrap.Collapse` constructor functions
- Two sets of event listeners on same buttons
- Instance conflicts where getInstance() returns wrong instance

### FIX #2: Remove Manual Collapse Instance Creation 🔥 HIGH PRIORITY
**Issue:** Creating Collapse instances in JavaScript interferes with Bootstrap's auto-init

**Action:** Modify template JavaScript to NOT create instances, only retrieve them:
```javascript
// Expand All Cards
document.getElementById('expandAllCards').addEventListener('click', function(e) {
  e.preventDefault();
  const cardCollapses = document.querySelectorAll('.rank-collapse');
  cardCollapses.forEach(function(collapse) {
    // ONLY get existing instance, don't create new one
    const bsCollapse = bootstrap.Collapse.getInstance(collapse);
    if (bsCollapse) {
      bsCollapse.show();
    }
  });
});
```

**Rationale:** Bootstrap auto-initializes Collapse on elements with `data-bs-toggle`. Creating manual instances causes ownership conflicts.

### FIX #3: Simplify Button HTML Structure 🟡 MEDIUM PRIORITY
**Issue:** Nested divs inside button might interfere with click event

**Action:** Remove unnecessary wrapper div:
```twig
<button class="accordion-button collapsed" 
        type="button" 
        data-bs-toggle="collapse" 
        data-bs-target="#collapse-card-{{ loop.index }}">
  <span class="badge bg-info fs-5 p-2 me-3" style="min-width: 60px;">
    {{ rank.level_avg }}
  </span>
  <span class="flex-grow-1">
    <span class="d-block text-cyan">{{ rank.position }}</span>
    <span class="d-block text-info small">{{ rank.title }}</span>
  </span>
</button>
```

**Rationale:** Flatter structure reduces chance of event bubbling issues. Use `<span>` instead of `<div>` inside buttons.

### FIX #4: Add Debug Logging 🟢 LOW PRIORITY (for testing)
**Action:** Add console logging to diagnose instance conflicts:
```javascript
const button = document.querySelector('[data-bs-target="#collapse-card-1"]');
console.log('Button:', button);
console.log('Collapse instance:', bootstrap.Collapse.getInstance(
  document.getElementById('collapse-card-1')
));
console.log('All Bootstrap instances:', bootstrap);
```

---

## 7. TESTING PROTOCOL

### Test 1: Verify Single Bootstrap Load
```bash
# After removing duplicate from forseti.libraries.yml
drush cr
# Open browser console, check Network tab
# Should see ONLY main.script.js loading Bootstrap
# Should NOT see cdn.jsdelivr.net/npm/bootstrap
```

### Test 2: Check Console for Errors
```javascript
// Open browser console before clicking accordion
// Click to expand - should see no errors
// Click to collapse - check for errors like:
// "Uncaught TypeError: Cannot read property 'toggle' of null"
```

### Test 3: Inspect Collapse Instances
```javascript
// In browser console after page load:
const collapse = document.getElementById('collapse-card-1');
const instance = bootstrap.Collapse.getInstance(collapse);
console.log('Instance:', instance);
console.log('Instance config:', instance ? instance._config : 'NO INSTANCE');
```

### Test 4: Monitor Event Listeners
```javascript
// Check if multiple listeners attached:
const button = document.querySelector('[data-bs-toggle="collapse"]');
getEventListeners(button); // Chrome DevTools only
// Should see ONE 'click' listener for Bootstrap
```

---

## 8. SUMMARY

**Files Involved:**
1. `/web/themes/custom/forseti/forseti.libraries.yml` - Duplicate Bootstrap load
2. `/web/themes/custom/forseti/build/css/forseti-theme.css` - Accordion styling (CORRECT)
3. `/web/modules/custom/forseti_safety_content/templates/forseti-us-government-power.html.twig` - Markup & inline JS
4. `/web/modules/custom/forseti_safety_content/forseti_safety_content.libraries.yml` - Library deps

**Libraries Loading (Current):**
```
forseti_safety_content/agent-power
├── css/forseti-pages.css
├── js/agent-evaluation.js
└── depends on: forseti/style
    ├── build/css/main.style.css (Bootstrap 5.3.0 CSS)
    ├── build/css/forseti-theme.css (.accordion-forseti styles)
    ├── build/js/main.script.js (Bootstrap 5.3.0 JS BUNDLED)
    └── cdn: bootstrap@5.3.0 (Bootstrap 5.3.0 JS DUPLICATE) ❌
```

**Root Causes:**
1. **DUAL BOOTSTRAP JS LOAD** - Creating conflicting Collapse instances
2. **MANUAL INSTANCE CREATION** - Umbrella controls interfering with auto-init
3. **NESTED BUTTON CONTENT** - Possible click event interference (minor)

**Recommended Action Plan:**
1. Remove CDN Bootstrap from forseti.libraries.yml
2. Modify umbrella controls to only use getInstance (not create)
3. Clear cache and test
4. If still broken, simplify button HTML structure
5. If still broken, remove pointer-events: none and umbrella controls entirely

---

**Generated:** 2026-01-07  
**Analyst:** GitHub Copilot (Claude Sonnet 4.5)
