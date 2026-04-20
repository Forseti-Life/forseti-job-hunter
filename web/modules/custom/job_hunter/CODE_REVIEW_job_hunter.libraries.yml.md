# Code Review: job_hunter.libraries.yml

## Purpose
Defines CSS and JavaScript library assets for the Job Hunter module, managing dependencies and asset loading for various features and pages.

## Identified Issues

### Critical
None

### Major
None

### Minor
1. **Duplicate Dependencies** (Lines 111-113): The `company-research` library lists `core/once` twice (lines 112 and 113).

2. **Inconsistent Library Naming**: Some use hyphens (e.g., `job-hunter-home`) while others use underscores (e.g., `companies_table`, `user_profile`).

3. **Unused Global Styling** (Lines 1-6): The `global-styling` library may not be used - need to verify attachment points.

## Concerns

1. **Organization**: Libraries could be better grouped (UI vs functionality vs page-specific).

2. **Asset Loading**: Multiple small CSS files could impact performance - consider bundling related styles.

3. **Dependency Management**:
   - All libraries depend on jQuery
   - Multiple libraries depend on `once` utility
   - Some dependencies may be redundant

4. **Library Granularity**: Some libraries are page-specific while others are feature-specific - inconsistent approach.

5. **Missing Minification**: No indication of minified versions for production use.

## Overall Suggestions for Improvement

1. **Standardize Naming**:
   - Use consistent naming convention (prefer hyphens)
   - Group related libraries with clear prefixes

2. **Fix Duplicate Dependency**:
   ```yaml
   company-research:
     css:
       theme:
         css/company-research.css: {}
     js:
       js/company-research.js: {}
     dependencies:
       - core/drupal
       - core/jquery
       - core/once  # Remove duplicate
       - job_hunter/job-hunter-home
   ```

3. **Optimize Asset Loading**:
   - Consider combining related CSS files
   - Add minified versions for production
   - Use asset aggregation where possible
   - Consider critical CSS for above-fold content

4. **Improve Organization**:
   ```yaml
   # ==========================================
   # GLOBAL & LAYOUT
   # ==========================================
   global-styling:
     # ...
   
   job-hunter-home:
     # ...
   
   # ==========================================
   # FEATURE LIBRARIES
   # ==========================================
   queue-controls:
     # ...
   
   job-discovery:
     # ...
   ```

5. **Dependency Audit**:
   - Review if all libraries actually need jQuery
   - Consider vanilla JS where appropriate
   - Verify all dependencies are necessary

6. **Add Preprocessing**:
   - Consider SCSS/SASS for better CSS organization
   - Add build process for asset optimization
   - Implement CSS/JS linting

## Code Quality Assessment

**Score: 7.5/10**

**Strengths:**
- Proper dependency declarations
- Good separation of concerns
- Appropriate use of theme category for CSS
- Clear library purposes

**Weaknesses:**
- Duplicate dependency
- Inconsistent naming
- No asset optimization
- Could be better organized

## Compliance & Standards

✅ Valid YAML syntax
✅ Proper Drupal 9/10/11 library structure
⚠️ Inconsistent naming conventions
✅ Correct dependency format
⚠️ Missing minified versions

## Performance Considerations

⚠️ Multiple small CSS files - consider bundling
⚠️ jQuery dependency on all libraries - consider reducing
✅ Libraries are lazy-loaded by Drupal
⚠️ No indication of asset minification
⚠️ No critical CSS strategy

## Security Considerations

✅ No external CDN dependencies (good for security)
✅ All assets served from module
✅ No inline scripts or styles

## Recommended Immediate Actions

1. **Fix Duplicate Dependency**: Remove duplicate `core/once` from `company-research` library (line 113)

2. **Standardize Names**: Rename libraries to use consistent hyphenated format:
   - `companies_table` → `companies-table`
   - `user_profile` → `user-profile`

3. **Add Organization Comments**: Group libraries by category

4. **Audit Dependencies**: 
   - Review each library's actual jQuery usage
   - Remove unnecessary dependencies
   - Consider vanilla JS alternatives

5. **Add Build Process**:
   - Set up Webpack or similar for asset bundling
   - Add minification for production
   - Implement CSS preprocessing

## Example Improved Structure

```yaml
# ==========================================
# BASE LAYOUT & STYLING
# ==========================================

global-styling:
  version: 1.0
  css:
    theme:
      css/job-hunter.css: { minified: true }
  dependencies:
    - core/drupal

job-hunter-home:
  version: 1.0
  css:
    theme:
      css/job-hunter-home.css: { minified: true }
  dependencies:
    - core/drupal

# ==========================================
# DATA TABLES & LISTS
# ==========================================

companies-table:
  version: 1.0
  css:
    theme:
      css/companies-table.css: { minified: true }
  js:
    js/companies-table.js: { minified: true }
  dependencies:
    - core/drupal
    - core/once

# ... continue with clear grouping
```

## Additional Recommendations

1. **Version Assets**: Add version numbers to cache-bust when changes are made

2. **Consider Asset Library**: Use a library aggregation strategy for production

3. **Document Dependencies**: Add comments explaining why specific dependencies are needed

4. **Performance Budget**: Set and monitor asset size limits

5. **Lazy Loading**: Consider lazy-loading non-critical libraries
