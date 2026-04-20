# Mobile Navigation Analysis & Proper Configuration
## How Navigation SHOULD Be Set Up for Forseti Theme

**Date:** January 7, 2026  
**Theme:** Forseti (based on Radix 6.0.2)  
**Bootstrap Version:** 5.3.0

---

## 1. CURRENT ARCHITECTURE OVERVIEW

### Base Theme: Radix 6.0.2
- **Type:** Base theme (not a sub-theme itself)
- **Bootstrap Version:** 5.x compatible
- **Component System:** SDC (Single Directory Components)
- **Regions Defined:**
  - `navbar_branding` - Logo/site name
  - `navbar_left` - Main menu typically placed here
  - `navbar_right` - User account, search, secondary menu
  - `header` - Page header content
  - `content` - Main page content
  - `page_bottom` - Scripts, modals, etc.
  - `footer` - Footer content

### Forseti Theme (Sub-theme)
- **Extends:** Radix
- **Bootstrap JS:** Loaded once via `build/js/main.script.js` (includes Collapse component)
- **Custom Templates:** Overrides Radix defaults in `templates/` directories
- **Libraries:** `forseti/style` includes Bootstrap CSS and JS

---

## 2. HOW RADIX EXPECTS NAVIGATION TO WORK

### Radix Navbar Component Structure

Radix provides a complete Bootstrap 5 navbar component at:
```
web/themes/contrib/radix/components/navbar/navbar.twig
```

**Key Features:**
```twig
<nav class="navbar navbar-expand-lg">
  <div class="container">
    {# Branding block #}
    
    {# Navbar Toggler - Bootstrap's hamburger button #}
    <button class="navbar-toggler collapsed" 
            type="button" 
            data-bs-toggle="collapse" 
            data-bs-target=".navbar-collapse" 
            aria-controls="navbar-collapse" 
            aria-expanded="false" 
            aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    {# Collapsible content #}
    <div class="collapse navbar-collapse">
      {# Left block - main menu #}
      {# Right block - user menu #}
    </div>
  </div>
</nav>
```

**Critical Attributes:**
1. **Toggler Button:**
   - `data-bs-toggle="collapse"` - Tells Bootstrap this triggers collapse
   - `data-bs-target=".navbar-collapse"` - Targets ALL elements with this class
   - `aria-expanded="false"` - Accessibility state
   - `class="navbar-toggler collapsed"` - Bootstrap styling + initial state

2. **Collapse Container:**
   - `class="collapse navbar-collapse"` - Bootstrap collapse + navbar container
   - NO `id` needed when using class selector (`.navbar-collapse`)
   - Automatically controlled by Bootstrap's Collapse component

3. **Responsive Breakpoint:**
   - `navbar-expand-lg` = Collapses below 992px (tablets/mobile)
   - `navbar-expand-md` = Collapses below 768px
   - `navbar-expand-sm` = Collapses below 576px

### Radix Page Navigation Component

Radix provides `page-navigation.twig` which embeds the navbar:
```twig
{% embed 'radix:navbar' with {
  container: 'fixed',
  navbar_theme: 'light',
  navbar_utility_classes: ['justify-content-between'],
} %}
  {% block branding %}
    {% if page.navbar_branding %}{{ page.navbar_branding }}{% endif %}
  {% endblock %}
  
  {% block left %}
    {% if page.navbar_left %}
      <div class="me-auto">{{ page.navbar_left }}</div>
    {% endif %}
  {% endblock %}
  
  {% block right %}
    {% if page.navbar_right %}
      <div class="ms-auto">{{ page.navbar_right }}</div>
    {% endif %}
  {% endblock %}
{% endembed %}
```

---

## 3. CURRENT FORSETI IMPLEMENTATION (PROBLEMS)

### Location: `templates/page/page.html.twig` (lines 82-113)

**Current Code:**
```twig
{% if page.navbar_branding or page.navbar_left or page.navbar_right %}
  <nav class="navbar navbar-expand-lg navbar-light" 
       style="background-color: transparent; border-bottom: 2px solid #00d4ff;">
    <div class="container">
      {% if page.navbar_branding %}
        {{ page.navbar_branding }}
      {% endif %}
      
      <button class="navbar-toggler" 
              type="button" 
              data-bs-toggle="collapse" 
              data-bs-target=".navbar-collapse"
              aria-controls="navbar-collapse"
              aria-expanded="false"
              aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      
      <div class="collapse navbar-collapse">
        {% if page.navbar_left %}
          {{ page.navbar_left }}
        {% endif %}
        {% if page.navbar_right %}
          <div class="ms-auto">
            {{ page.navbar_right }}
          </div>
        {% endif %}
      </div>
    </div>
  </nav>
{% endif %}
```

### Problems Identified:

#### ❌ Problem 1: Not Using Radix Components
- **Issue:** Manually reimplementing navbar HTML instead of using Radix components
- **Impact:** Misses Radix's tested navbar implementation
- **Why It Matters:** Radix components handle edge cases and Bootstrap integration properly

#### ❌ Problem 2: Inline Styles
- **Issue:** `style="background-color: transparent; border-bottom: 2px solid #00d4ff;"`
- **Impact:** Violates Drupal theming best practices
- **Should Be:** CSS classes in theme stylesheets

#### ❌ Problem 3: Missing `collapsed` Class on Toggler
- **Issue:** Button doesn't have initial `collapsed` class
- **Impact:** Bootstrap Collapse may not initialize properly on first page load
- **Should Be:** `<button class="navbar-toggler collapsed">`

#### ❌ Problem 4: Potential Menu Template Issues
- **Location:** `templates/menu/menu--main.html.twig`
- **Issue:** May not be generating proper Bootstrap nav classes
- **Critical Check:** Are menu items wrapped in `<ul class="navbar-nav">`?

#### ⚠️ Problem 5: Lack of Drupal Best Practices
- **Issue:** Not using Radix component system (SDC)
- **Impact:** Harder to maintain, test, and extend
- **Should Be:** Extend or override Radix components properly

---

## 4. PROPER MOBILE NAVIGATION SETUP

### Option A: Use Radix Components Properly (RECOMMENDED)

#### Step 1: Remove Custom Navbar from page.html.twig

**Delete lines 82-113** and replace with:
```twig
{% block page_navigation %}
  {% include 'radix:page-navigation' with {
    navbar_container_type: 'fluid',
    navbar_expand: 'lg',
    navbar_theme: 'light',
    navbar_utility_classes: [
      'forseti-navbar',
    ],
  } %}
{% endblock %}
```

#### Step 2: Create Custom CSS for Navbar Styling

**File:** `build/css/forseti-theme.css` or `src/scss/_navbar.scss`

```css
.forseti-navbar {
  background-color: transparent !important;
  border-bottom: 2px solid #00d4ff;
  padding-top: 0.25rem;
  padding-bottom: 0.25rem;
}

.forseti-navbar .navbar-toggler {
  border-color: #00d4ff;
}

.forseti-navbar .navbar-toggler-icon {
  background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='%2300d4ff' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
}

.forseti-navbar .navbar-toggler:focus {
  box-shadow: 0 0 0 0.25rem rgba(0, 212, 255, 0.25);
}

/* Mobile menu styles */
@media (max-width: 991.98px) {
  .forseti-navbar .navbar-collapse {
    background-color: #1a1a2e;
    padding: 1rem;
    margin-top: 0.5rem;
    border-radius: 0.25rem;
  }
}
```

#### Step 3: Verify Menu Template

**File:** `templates/menu/menu--main.html.twig`

**Ensure it outputs:**
```twig
<ul class="nav navbar-nav">
  <li class="nav-item">
    <a href="..." class="nav-link">Menu Item</a>
  </li>
  <li class="nav-item dropdown">
    <a href="..." class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
      Dropdown
    </a>
    <ul class="dropdown-menu">
      <li><a href="..." class="dropdown-item">Sub Item</a></li>
    </ul>
  </li>
</ul>
```

**Key Requirements:**
- Root `<ul>` must have `class="nav navbar-nav"`
- Top-level `<li>` must have `class="nav-item"`
- Links must have `class="nav-link"`
- Dropdowns need `dropdown` and `dropdown-toggle` classes
- Dropdown menus need `class="dropdown-menu"`

---

### Option B: Fix Existing Implementation (If can't use Radix components)

#### Minimal Fixes to Current Code:

1. **Add `collapsed` class to toggler button:**
```twig
<button class="navbar-toggler collapsed" 
        type="button" 
        data-bs-toggle="collapse" 
        data-bs-target=".navbar-collapse">
```

2. **Move inline styles to CSS:**
```css
/* In forseti-theme.css */
.navbar {
  background-color: transparent;
  border-bottom: 2px solid #00d4ff;
  padding-top: 0.25rem;
  padding-bottom: 0.25rem;
}
```

3. **Ensure menu template is correct** (see above)

4. **Add unique ID to collapse div (optional but safer):**
```twig
<button class="navbar-toggler collapsed"
        data-bs-target="#mainNavbarCollapse">
        
<div id="mainNavbarCollapse" class="collapse navbar-collapse">
```

---

## 5. BOOTSTRAP 5 NAVBAR COLLAPSE BEHAVIOR

### How It Works:

1. **On Desktop (≥992px with `navbar-expand-lg`):**
   - `.navbar-collapse` has `display: flex !important`
   - `.navbar-toggler` is hidden (`display: none`)
   - Menu items display horizontally
   - No collapse behavior needed

2. **On Mobile (<992px):**
   - `.navbar-collapse` has `display: none` (when collapsed)
   - `.navbar-toggler` is visible
   - Clicking toggler:
     - Toggles `.show` class on `.navbar-collapse`
     - Toggles `.collapsed` class on `.navbar-toggler`
     - Updates `aria-expanded` attribute
     - Animates height using CSS transitions

3. **Bootstrap's Collapse Component:**
   - Auto-initializes on elements with `data-bs-toggle="collapse"`
   - Requires `data-bs-target` to point to target element
   - Target must have `.collapse` class
   - Works via Bootstrap's JavaScript (already loaded in your theme)

### Common Issues That Break Mobile Nav:

❌ **Missing `navbar-expand-*` class** - Nav never collapses
❌ **Wrong `data-bs-target` selector** - Toggler doesn't know what to collapse
❌ **Missing `.collapse` class** - Bootstrap Collapse won't initialize
❌ **Missing `.navbar-collapse` class** - CSS media queries won't work
❌ **Bootstrap JS not loaded** - No collapse behavior at all (YOU'RE OK - it's loaded)
❌ **Multiple Bootstrap instances** - Conflicts (YOU FIXED THIS!)
❌ **Wrong menu CSS classes** - Menu doesn't style properly

---

## 6. REGION BLOCK PLACEMENT

### Required Block Configuration:

**Path:** `/admin/structure/block`

#### Navbar Branding Region:
- **Block:** Site Branding
- **Visibility:** Show on all pages
- **Order:** 1

#### Navbar Left Region:
- **Block:** Main navigation (system menu)
- **Menu:** Main navigation
- **Template:** menu--main.html.twig
- **Visibility:** Show on all pages
- **Order:** 1

#### Navbar Right Region (Optional):
- **Block:** User account menu
- **Menu:** Account menu
- **Visibility:** Show on all pages
- **Order:** 1

### Block Configuration Command:
```bash
# Via Drush (if you have configuration)
drush config:import --partial --source=/path/to/config

# Or manually via UI:
# 1. Go to /admin/structure/block
# 2. Find "Primary navigation" block
# 3. Set Region: "Navbar left"
# 4. Configure: Enable, set visibility
# 5. Save
```

---

## 7. TESTING CHECKLIST

### Desktop (≥992px):
- [ ] Logo/branding visible
- [ ] Main menu items display horizontally
- [ ] Hamburger button NOT visible
- [ ] Dropdowns work on hover/click
- [ ] User menu (if present) aligned right

### Tablet (768px - 991px):
- [ ] Hamburger button VISIBLE
- [ ] Menu collapsed by default
- [ ] Clicking hamburger expands menu
- [ ] Menu items stack vertically when expanded
- [ ] Clicking hamburger again collapses menu
- [ ] Clicking outside menu (if desired) collapses it

### Mobile (<768px):
- [ ] Same as tablet tests
- [ ] Menu doesn't overflow screen width
- [ ] Touch targets are large enough (44px minimum)
- [ ] Scrolling works if menu is tall

### Accessibility:
- [ ] Hamburger has `aria-label="Toggle navigation"`
- [ ] Button has `aria-expanded` that updates on toggle
- [ ] Menu items are keyboard accessible (Tab key)
- [ ] Screen readers announce menu state changes

---

## 8. COMMON DEBUGGING STEPS

### If Mobile Menu Won't Toggle:

1. **Check Browser Console:**
```javascript
// In browser console:
typeof bootstrap
// Should return: "object" (not "undefined")

typeof bootstrap.Collapse
// Should return: "function"
```

2. **Verify Toggler Target:**
```javascript
// Check if target exists
document.querySelector('.navbar-collapse')
// Should return: <div class="collapse navbar-collapse">...
```

3. **Test Manual Toggle:**
```javascript
// Get collapse element
const collapse = document.querySelector('.navbar-collapse');
const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapse);
bsCollapse.toggle(); // Should open/close menu
```

4. **Check CSS:**
```css
/* In DevTools, inspect .navbar-collapse */
/* At mobile size, should have: */
display: none; /* when collapsed */
display: block; /* when expanded with .show class */
```

5. **Verify Breakpoint:**
```javascript
// Check current screen width
window.innerWidth
// If < 992px with navbar-expand-lg, toggler should be visible
```

---

## 9. RECOMMENDED IMPLEMENTATION STEPS

### Step-by-Step Guide:

#### Phase 1: Prepare (No Code Changes)
1. Document current menu structure
2. Take screenshots of desktop/mobile views
3. List all menu items and nesting
4. Check which blocks are in navbar regions

#### Phase 2: Update Template
1. Backup current `page.html.twig`
2. Replace navbar section with Radix component include
3. Clear Drupal cache: `drush cr`
4. Test on desktop - should still work

#### Phase 3: Add Styling
1. Create `_navbar.scss` in `src/scss/` (or add to existing)
2. Add forseti-navbar styles (see Option A above)
3. Compile SCSS: `npm run dev` or `npm run build`
4. Clear cache: `drush cr`
5. Test styling on desktop

#### Phase 4: Mobile Testing
1. Open DevTools responsive mode
2. Set width to 375px (mobile)
3. Verify hamburger button appears
4. Click hamburger - menu should expand
5. Click again - menu should collapse
6. Test on real mobile device

#### Phase 5: Menu Template Fix (If Needed)
1. Inspect menu HTML in browser DevTools
2. Verify `<ul class="nav navbar-nav">` on root
3. If wrong, update `menu--main.html.twig`
4. Clear cache and re-test

#### Phase 6: Polish
1. Add mobile-specific menu styles
2. Test dropdowns on mobile (may need different behavior)
3. Add transitions/animations if desired
4. Test all accessibility features
5. Cross-browser testing (Chrome, Firefox, Safari)

---

## 10. REFERENCE: CORRECT MENU TEMPLATE

### File: `templates/menu/menu--main.html.twig`

```twig
{#
/**
 * @file
 * Theme override for main navigation menu.
 */
#}
{% import _self as menus %}

{% macro menu_links(items, attributes, menu_level) %}
  {% import _self as menus %}
  {% if items %}
    {% if menu_level == 0 %}
      <ul{{ attributes.addClass('nav', 'navbar-nav') }}>
    {% else %}
      <ul class="dropdown-menu">
    {% endif %}
    
    {% for item in items %}
      {% set item_classes = [
        menu_level == 0 ? 'nav-item',
        item.below ? 'dropdown',
      ] %}
      
      <li{{ item.attributes.addClass(item_classes) }}>
        {% if item.below %}
          {# Dropdown link #}
          <a href="{{ item.url }}" 
             class="{{ menu_level == 0 ? 'nav-link dropdown-toggle' : 'dropdown-item dropdown-toggle' }}" 
             data-bs-toggle="dropdown"
             aria-expanded="false">
            {{ item.title }}
          </a>
          {{ menus.menu_links(item.below, attributes.removeClass('nav', 'navbar-nav'), menu_level + 1) }}
        {% else %}
          {# Regular link #}
          <a href="{{ item.url }}" 
             class="{{ menu_level == 0 ? 'nav-link' : 'dropdown-item' }}">
            {{ item.title }}
          </a>
        {% endif %}
      </li>
    {% endfor %}
    
    </ul>
  {% endif %}
{% endmacro %}

{{ menus.menu_links(items, attributes, 0) }}
```

### Key Points:
- Root `<ul>` gets `class="nav navbar-nav"`
- Top-level `<li>` gets `class="nav-item"`
- Top-level links get `class="nav-link"`
- Dropdowns get `class="dropdown"` on `<li>`
- Dropdown toggles get `data-bs-toggle="dropdown"`
- Sub-menus get `class="dropdown-menu"`
- Sub-items get `class="dropdown-item"`

---

## 11. SUMMARY: THE PROPER WAY

### ✅ Correct Setup (What You SHOULD Do):

1. **Use Radix Components:**
   - Include `radix:page-navigation` in page template
   - Pass configuration via Twig variables
   - Let Radix handle Bootstrap integration

2. **Style with CSS Classes:**
   - Add custom classes via `navbar_utility_classes`
   - Define styles in theme CSS files
   - No inline styles in templates

3. **Ensure Proper Menu Template:**
   - Output `<ul class="nav navbar-nav">`
   - Use correct Bootstrap nav classes
   - Handle dropdowns with proper markup

4. **Block Placement:**
   - Main navigation → Navbar Left region
   - Site branding → Navbar Branding region
   - User/secondary menus → Navbar Right region

5. **Test Thoroughly:**
   - Desktop, tablet, mobile viewports
   - Touch interactions on real devices
   - Keyboard navigation
   - Screen reader compatibility

### ❌ Current Issues:

1. Manually implementing navbar HTML (not using Radix components)
2. Inline styles in template
3. Missing `collapsed` class on toggler button
4. Not following Drupal/Radix best practices

### 🎯 Expected Result:

- **Desktop:** Horizontal menu, no hamburger
- **Mobile:** Collapsed menu, hamburger button visible
- **Click hamburger:** Menu slides down smoothly
- **Click again:** Menu slides up and collapses
- **All viewports:** Looks professional and works reliably

---

**Next Steps:**
1. Implement Option A (Radix components) - RECOMMENDED
2. Test on multiple devices/browsers
3. Adjust styling as needed
4. Document any customizations
5. Add to version control

**Generated:** 2026-01-07  
**Analyst:** GitHub Copilot (Claude Sonnet 4.5)
