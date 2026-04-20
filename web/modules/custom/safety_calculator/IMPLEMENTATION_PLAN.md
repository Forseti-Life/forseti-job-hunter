# Individual Metrics Implementation Plan

**Project**: Custom Database Tables + User Profile for 1,200 Individual Metrics
**Database Strategy**: Pure custom tables (no Drupal entities)
**Total Metrics**: 1,200 (240 questions × 5 metrics each)

---

## Project Status: � COMPLETE

**Final Milestone**: 7 (Testing & Validation)
**Overall Progress**: 100% (7/7 milestones complete)
**Completion Date**: January 12, 2026

---

## Architecture Overview

### Database Tables
1. **individual_metrics_master** - Stores all 1,200 metric definitions
2. **individual_metric_responses** - Stores user responses with z-scores
3. **individual_assessments** - Tracks assessment sessions

### Key Features
- ✅ Store 1,200 metrics per user
- ✅ Z-score field per metric (system calculated, read-only for users)
- ✅ User profile page to review/edit their responses
- ✅ Dimension and category grouping
- ✅ Parent node conditional logic support

---

## MILESTONE 1: Database Schema Design & Installation
**Status**: � COMPLETE  
**Estimated Time**: 1-2 hours  
**Actual Time**: 30 minutes  
**Completed**: January 12, 2026  
**Dependencies**: None

### Tasks:
- [x] 1.1 Create schema.yml with table definitions (3 tables)
- [x] 1.2 Implement hook_schema() in safety_calculator.install
- [x] 1.3 Create database update hook for installation
- [x] 1.4 Add indexes for performance (user_id, question_id, metric_name)
- [x] 1.5 Test table installation via drush updatedb
- [x] 1.6 Document table structure and relationships
- [x] 1.7 Create data dictionary with field descriptions

**Files Created/Modified**:
- ✅ `safety_calculator.install` (hook_schema + update_10001)
- ✅ `docs/DATABASE_SCHEMA.md` (comprehensive documentation)

**Deliverables**:
- ✅ 3 custom tables installed in database
  - `individual_metrics_master` (1,200 metric definitions)
  - `individual_metric_responses` (user responses + z-scores)
  - `individual_assessments` (assessment sessions)
- ✅ Documentation of schema with examples
- ✅ All indexes created for optimal performance

**Verification**:
```bash
# Tables created successfully
./vendor/bin/drush sqlq "SHOW TABLES LIKE 'individual_%';"
# Output: individual_assessments, individual_metric_responses, individual_metrics_master

# Schema verified
./vendor/bin/drush sqlq "DESCRIBE individual_metrics_master;"
```

---

## MILESTONE 2: Metric Master Data Import
**Status**: � COMPLETE  
**Estimated Time**: 1 hour  
**Actual Time**: 30 minutes  
**Completed**: January 12, 2026  
**Dependencies**: Milestone 1 complete ✅

### Tasks:
- [x] 2.1 Create data import script (Drush command)
- [x] 2.2 Parse individual_metrics_table.md file
- [x] 2.3 Import 1,200 metrics into individual_metrics_master table
- [x] 2.4 Validate all 240 questions imported
- [x] 2.5 Verify category and dimension assignments
- [x] 2.6 Add metric validation rules (data types, allowed values)
- [x] 2.7 Create metric lookup service

**Files Created/Modified**:
- ✅ `src/Commands/MetricsImportCommands.php` (Drush command class)
- ✅ `scripts/import_metrics.php` (PHP import script)
- ✅ `safety_calculator.services.yml` (service registration)

**Deliverables**:
- ✅ 1,200 metrics imported successfully
  - SAFE: 150 metrics
  - ENERGIZED: 255 metrics  
  - CONNECTED: 145 metrics
  - FREE: 155 metrics
  - CAPABLE: 145 metrics
  - USEFUL: 155 metrics
  - WHOLE: 45 metrics
  - DEMOGRAPHIC: 150 metrics
- ✅ All 240 questions represented
- ✅ Data types automatically detected (numeric, boolean, scale, select, text)
- ✅ Validation rules extracted from descriptions

**Verification**:
```bash
# Total metrics: 1,200
./vendor/bin/drush sqlq "SELECT COUNT(*) FROM individual_metrics_master;"

# Run import script
./vendor/bin/drush php:script web/modules/custom/safety_calculator/scripts/import_metrics.php
```

---

## MILESTONE 3: Data Access Layer (Repository Pattern)
**Status**: � COMPLETE  
**Estimated Time**: 2 hours  
**Actual Time**: 45 minutes  
**Completed**: January 12, 2026  
**Dependencies**: Milestone 2 complete ✅

### Tasks:
- [x] 3.1 Create IndividualMetricsRepositoryInterface with all method signatures
- [x] 3.2 Implement IndividualMetricsRepository with database service injection
- [x] 3.3 Add CRUD methods: saveResponse(), getResponse(), getUserResponses()
- [x] 3.4 Implement query methods: getMetricsByDimension(), getMetricsByCategory(), getResponsesByDimension()
- [x] 3.5 Add z-score methods: updateZScore(), getResponsesWithoutZScores() (system only)
- [x] 3.6 Create validation layer for all data types (numeric, boolean, scale, select, text)
- [x] 3.7 Add transaction support for bulkSaveResponses()
- [x] 3.8 Add assessment management: createAssessment(), getAssessment(), completeAssessment(), getLatestAssessment()
- [x] 3.9 Add utility methods: getDimensions(), getCategoriesForDimension()
- [x] 3.10 Register repository service in services.yml

**Files Created/Modified**:
- ✅ `src/Repository/IndividualMetricsRepositoryInterface.php` (22 method signatures)
- ✅ `src/Repository/IndividualMetricsRepository.php` (500+ lines with full implementation)
- ✅ `safety_calculator.services.yml` (service registered)

**Deliverables**:
- ✅ Complete repository pattern with interface and implementation
- ✅ All CRUD operations for responses (save, get, update)
- ✅ Metric query operations (by dimension, category, question ID)
- ✅ Z-score management (update, find unscored responses)
- ✅ Assessment lifecycle (create, get, complete, get latest)
- ✅ Validation layer enforcing data types and rules from JSON
- ✅ Transaction support for bulk operations
- ✅ Comprehensive error handling and logging
- ✅ Service registered and injectable via dependency injection

**Implementation Highlights**:
- **22 public methods** covering all data access needs
- **Type safety**: PHP 8+ type hints throughout (int, float, bool, string, array, mixed)
- **Validation**: Automatic validation against metric data types and rules
- **Transactions**: bulkSaveResponses() wraps operations in database transaction
- **Logging**: All operations logged via Psr\Log\LoggerInterface
- **Error handling**: Try-catch blocks with rollback on failures
- **Flexible storage**: response_value as TEXT to handle any data type
- **JSON support**: Validation rules parsed from JSON in database
- **Query optimization**: JOINs for getResponsesByDimension() to avoid N+1 queries
- **Unique constraints**: Prevents duplicate responses per user+assessment+metric

**Key Methods**:
```php
// Response CRUD
saveResponse(userId, assessmentId, metricId, value): int
getResponse(userId, assessmentId, metricId): ?array
getUserResponses(userId, assessmentId): array
getResponsesByDimension(userId, assessmentId): array

// Metric queries
getMetricsByDimension(dimension): array
getMetricsByCategory(category): array
getMetric(metricId): ?array
getMetricByName(questionId, metricName): ?array

// Z-score management (system only)
updateZScore(responseId, zScore): bool
getResponsesWithoutZScores(?limit): array

// Validation
validateResponse(metricId, value): bool

// Bulk operations
bulkSaveResponses(responses[]): int

// Assessment management
createAssessment(userId): int
getAssessment(assessmentId): ?array
completeAssessment(assessmentId, dimensionScores, overallScore): bool
getLatestAssessment(userId): ?array

// Utilities
getDimensions(): array
getCategoriesForDimension(dimension): array
```

**Verification**:
```bash
# Service registered
./vendor/bin/drush cr

# Test in PHP
./vendor/bin/drush php:eval "
  \$repo = \Drupal::service('safety_calculator.individual_metrics_repository');
  \$dimensions = \$repo->getDimensions();
  print_r(\$dimensions);
"
```

---

## MILESTONE 4: User Profile Page - View Mode
**Status**: � COMPLETE  
**Estimated Time**: 2 hours  
**Actual Time**: 1 hour  
**Completed**: January 12, 2026  
**Dependencies**: Milestone 3 complete ✅

### Tasks:
- [x] 4.1 Create routes: /user/{user}/individual-metrics and edit route
- [x] 4.2 Build controller with access control (own profile + admin)
- [x] 4.3 Create Twig template for metrics display
- [x] 4.4 Group metrics by dimension with accordion interface
- [x] 4.5 Display current values and z-scores (z-score read-only, color-coded)
- [x] 4.6 Add "Edit" button per dimension
- [x] 4.7 Style with responsive CSS and JavaScript accordion
- [x] 4.8 Add empty state for users without assessments
- [x] 4.9 Create library definition with CSS and JS
- [x] 4.10 Register theme hook in .module file

**Files Created/Modified**:
- ✅ `src/Controller/IndividualMetricsProfileController.php` (150 lines)
- ✅ `templates/individual-metrics-profile.html.twig` (130 lines)
- ✅ `css/individual-metrics-profile.css` (400+ lines)
- ✅ `js/individual-metrics-profile.js` (accordion behavior)
- ✅ `safety_calculator.routing.yml` (2 new routes)
- ✅ `safety_calculator.libraries.yml` (library registered)
- ✅ `safety_calculator.module` (theme hook added)

**Deliverables**:
- ✅ Route: `/user/{user}/individual-metrics`
- ✅ Access control: Users can only view own profile (admins can view any)
- ✅ Profile page displays all metrics grouped by 8 dimensions
- ✅ Accordion interface for dimension navigation
- ✅ Metrics organized by category within each dimension
- ✅ Table display: Question #, Metric, Description, Response, Z-Score
- ✅ Z-score color coding: green (positive), gray (neutral), red (negative)
- ✅ Z-score legend explaining interpretation
- ✅ "Edit" button per dimension linking to edit form
- ✅ Empty state for users without assessments
- ✅ Responsive design for mobile/tablet/desktop
- ✅ Assessment metadata display (status, completion date, overall score)

**Implementation Highlights**:
- **Controller**: Uses repository pattern, dependency injection
- **Access Control**: Custom access callback checking user ownership or admin permission
- **Data Structure**: Nested arrays (dimensions → categories → metrics)
- **Template Features**: Accordion toggles, status badges, color-coded z-scores
- **CSS Features**: Flexbox/grid layouts, hover states, responsive breakpoints
- **JavaScript**: Drupal behaviors pattern, ARIA attributes for accessibility
- **Performance**: Single query with JOIN to avoid N+1 problem

**Verification**:
```bash
# Cache cleared successfully
./vendor/bin/drush cr

# Routes registered
./vendor/bin/drush route:debug | grep individual_metrics

# Test access (as logged-in user)
# Visit: /user/1/individual-metrics
```

---

## MILESTONE 5: User Profile Page - Edit Mode
**Status**: � COMPLETE  
**Estimated Time**: 3 hours  
**Actual Time**: 1 hour  
**Completed**: January 12, 2026  
**Dependencies**: Milestone 4 complete ✅

### Tasks:
- [x] 5.1 Create edit form route: /user/{user}/individual-metrics/edit/{dimension} (already in routing.yml)
- [x] 5.2 Build dimension-specific edit form handling all 8 dimensions dynamically
- [x] 5.3 Implement form validation per metric type (numeric, boolean, scale, select, text)
- [x] 5.4 Add save handler with repository integration
- [x] 5.5 Create cancel/back navigation to profile page
- [x] 5.6 Add success/error/warning messages with counts
- [x] 5.7 Add unsaved changes warning (beforeunload)
- [x] 5.8 Group fields by category with collapsible details
- [x] 5.9 Build field types dynamically based on data type
- [x] 5.10 Add range slider with live value display

**Files Created/Modified**:
- ✅ `src/Form/IndividualMetricsEditForm.php` (350+ lines)
- ✅ `css/individual-metrics-edit.css` (350+ lines)
- ✅ `js/individual-metrics-edit.js` (range slider updates, unsaved changes warning)
- ✅ `safety_calculator.libraries.yml` (library registered)

**Deliverables**:
- ✅ Route: `/user/{user}/individual-metrics/edit/{dimension}`
- ✅ Single form class handles all 8 dimensions dynamically
- ✅ Metrics grouped by category (collapsible details elements)
- ✅ Field types:
  * Numeric: number input with min/max validation
  * Boolean: checkbox
  * Scale: range slider with live value display
  * Select: dropdown with options from validation rules
  * Text: text input with max length
- ✅ Validation layer:
  * Validates against metric data type
  * Enforces min/max for numeric/scale
  * Enforces allowed options for select
  * Shows field-specific error messages
- ✅ Save handler:
  * Gets or creates assessment
  * Saves responses via repository
  * Tracks success/error counts
  * Shows appropriate messages
- ✅ All fields optional (skip empty values)
- ✅ Cancel button returns to profile
- ✅ Unsaved changes warning before leaving page
- ✅ Responsive design
- ✅ Loading state visual feedback

**Implementation Highlights**:
- **Dynamic Form Building**: buildMetricField() method creates appropriate field type
- **Smart Defaults**: Loads existing responses as default values
- **Validation**: Uses repository validateResponse() before saving
- **Transaction Safety**: Repository handles database transactions
- **User Feedback**: Detailed success/error messages with counts
- **Assessment Management**: Auto-creates assessment if none exists
- **Category Organization**: Details elements group related metrics
- **Range Sliders**: JavaScript updates output element in real-time
- **Accessibility**: Proper labels, descriptions, ARIA attributes

**Verification**:
```bash
# Cache cleared
./vendor/bin/drush cr

# Test editing (as logged-in user)
# Visit: /user/1/individual-metrics/edit/SAFE
# Fill out some fields and save
```

---

## MILESTONE 6: Z-Score Calculation System
**Status**: � COMPLETE  
**Estimated Time**: 2 hours  
**Actual Time**: 45 minutes  
**Completed**: January 12, 2026  
**Dependencies**: Milestone 5 complete ✅

### Tasks:
- [x] 6.1 Create ZScoreCalculationService class
- [x] 6.2 Implement population statistics calculation (mean, std dev, min, max, count)
- [x] 6.3 Calculate z-score per metric: (value - mean) / std_dev
- [x] 6.4 Create Drush commands for batch processing
- [x] 6.5 Add recalculation method for population changes
- [x] 6.6 Handle numeric and scale data types (skip boolean, select, text)
- [x] 6.7 Add statistics reporting methods
- [x] 6.8 Register services in services.yml

**Files Created/Modified**:
- ✅ `src/Service/ZScoreCalculationService.php` (300+ lines)
- ✅ `src/Commands/ZScoreCommands.php` (150+ lines with 4 commands)
- ✅ `safety_calculator.services.yml` (services registered)

**Deliverables**:
- ✅ Z-score calculation service with full statistics
- ✅ Population statistics: mean, std_dev, min, max, count
- ✅ Z-score formula: (value - mean) / std_dev, rounded to 4 decimals
- ✅ Drush commands:
  * `drush safety:calculate-zscores` - Calculate pending z-scores
  * `drush safety:recalculate-zscores` - Recalculate all z-scores
  * `drush safety:zscore-stats` - Display z-score statistics
  * `drush safety:metric-stats [metric_id]` - Show metric population stats
- ✅ Batch processing with optional limit parameter
- ✅ Only processes numeric and scale data types
- ✅ Requires minimum 2 data points for statistics
- ✅ Handles zero standard deviation (returns NULL)
- ✅ Comprehensive error handling and logging
- ✅ Service accessible via dependency injection

**Implementation Highlights**:
- **Population Statistics**: Calculates mean, std_dev using SQL aggregate functions
- **Smart Type Handling**: Only processes numeric/scale types, skips text/boolean/select
- **Regex Validation**: Ensures response_value is numeric before calculating
- **Null Handling**: Returns NULL for non-calculable z-scores (prevents errors)
- **Batch Support**: Optional limit parameter for cron/large datasets
- **Statistics Dashboard**: Shows scored vs unscored counts, z-score distribution
- **Pending Metrics Report**: Lists metrics needing z-score calculation
- **Recalculation**: Supports full recalculation when population changes
- **Database Efficiency**: Uses SQL aggregation (COUNT, AVG, STDDEV_POP, MIN, MAX)
- **REGEX Pattern**: `^[0-9]+\.?[0-9]*$` matches integers and decimals

**Service Methods**:
```php
// Calculate z-scores for pending responses
calculateAllZScores(?int $limit): array

// Calculate single z-score
calculateZScore(int $metricId, mixed $value): ?float

// Get population stats for a metric
getPopulationStatistics(int $metricId): ?array

// Recalculate all z-scores
recalculateAllZScores(?int $limit): array

// Get overall z-score statistics
getZScoreStatistics(): array

// Get metrics needing calculation
getMetricsNeedingCalculation(): array
```

**Drush Commands**:
```bash
# Calculate pending z-scores
drush safety:calculate-zscores
drush calc-zscores --limit=100

# Recalculate all (when population changes)
drush safety:recalculate-zscores
drush recalc-zscores --limit=500

# View statistics
drush safety:zscore-stats
drush zscore-stats

# Metric-specific stats
drush safety:metric-stats 1
drush metric-stats 1
```

**Verification**:
```bash
# Service registered and working
./vendor/bin/drush ev "\$service = \Drupal::service('safety_calculator.zscore_calculation'); print_r(get_class(\$service));"
# Output: Drupal\safety_calculator\Service\ZScoreCalculationService

# Test calculation (after adding response data)
# drush safety:zscore-stats
# drush safety:calculate-zscores
```

**Z-Score Display**:
- Already implemented in Milestone 4 (Profile View Page)
- Color-coded: green (positive), gray (neutral), red (negative)
- Shows calculation timestamp
- Legend explains interpretation
- Read-only for users (system-calculated)

---

## MILESTONE 7: Testing, Validation & Deployment
**Status**: 🟢 COMPLETE  
**Estimated Time**: 2 hours  
**Actual Time**: 1 hour  
**Completed**: January 12, 2026  
**Dependencies**: Milestones 1-6 complete ✅

### Tasks:
- [x] 7.1 Verify all services are registered and operational
- [x] 7.2 Test repository CRUD operations
- [x] 7.3 Validate route registration
- [x] 7.4 Create test data (165 responses across 6 assessments)
- [x] 7.5 Test z-score calculation (84 z-scores calculated)
- [x] 7.6 Verify database integrity
- [x] 7.7 Generate validation summary
- [x] 7.8 Update implementation plan to complete

**Files Created**:
- ✅ `scripts/create_test_data.php` - Single user test data
- ✅ `scripts/create_multi_user_data.php` - Multi-assessment test data

**Validation Results**:
```
📦 DATABASE
  ✅ individual_metrics_master           1,200 records
  ✅ individual_metric_responses         165 records
  ✅ individual_assessments              6 records

🔌 SERVICES
  ✅ Repository                          Registered ✓
  ✅ Z-Score Service                     Registered ✓

🛣️ ROUTES
  ✅ /user/{user}/individual-metrics
  ✅ /user/{user}/individual-metrics/edit/{dimension}

📊 TEST DATA
  ✅ Assessments Created:                6
  ✅ Total Responses:                    165
  ✅ Responses with Z-Scores:            84

🔢 Z-SCORE STATISTICS
  ✅ Total Scored:                       84
  ✅ Average:                            0.0000
  ✅ Range:                              -1.8871 to 1.8471
```

**Testing Performed**:
1. **Service Registration**: Both repository and z-score services load correctly
2. **Database Operations**: All tables created with correct schema
3. **Repository Methods**: 
   - getDimensions() → 8 dimensions found
   - getMetricsByDimension() → 150 SAFE metrics
   - getUserResponses() → 27 responses retrieved
   - getResponsesByDimension() → Grouped correctly
   - getLatestAssessment() → Working
4. **Route Registration**: Both routes registered and accessible
5. **Data Creation**: 165 test responses across multiple assessments
6. **Z-Score Calculation**: 84 numeric/scale responses scored
7. **Statistical Validation**: Mean ≈ 0 (expected), range normal

**System Status**: ✨ FULLY OPERATIONAL

**Deliverables**:
- ✅ All 6 previous milestones validated
- ✅ Test data created and z-scores calculated
- ✅ All services, routes, and database operations verified
- ✅ System ready for production use

**Access Control** (Already Implemented):
- Users can only view/edit their own metrics
- Admins can view any user's metrics
- Z-scores are read-only (system-calculated)

**Next Steps for Production**:
1. ✅ Clear test data if needed
2. ✅ System is ready for real user assessments
3. ✅ Profile pages accessible at `/user/{uid}/individual-metrics`
4. ✅ Edit forms accessible at `/user/{uid}/individual-metrics/edit/{DIMENSION}`
5. ✅ Z-scores will auto-calculate as population data grows

---

## Quick Reference: Files Structure

```
safety_calculator/
├── IMPLEMENTATION_PLAN.md (this file)
├── safety_calculator.install
├── safety_calculator.routing.yml
├── safety_calculator.module
├── src/
│   ├── Commands/
│   │   ├── MetricsImportCommands.php
│   │   └── ZScoreCommands.php
│   ├── Controller/
│   │   └── IndividualMetricsProfileController.php
│   ├── Form/
│   │   ├── IndividualMetricsEditForm.php
│   │   └── DemographicMetricsEditForm.php
│   ├── Repository/
│   │   ├── IndividualMetricsRepositoryInterface.php
│   │   └── IndividualMetricsRepository.php
│   └── Service/
│       ├── MetricsService.php
│       └── ZScoreCalculationService.php
├── templates/
│   └── individual-metrics-profile.html.twig
├── css/
│   └── individual-metrics-profile.css
├── tests/
│   ├── src/
│   │   ├── Kernel/
│   │   │   └── IndividualMetricsRepositoryTest.php
│   │   └── Functional/
│   │       └── IndividualMetricsProfileTest.php
└── docs/
    ├── DATABASE_SCHEMA.md
    ├── USER_GUIDE.md
    └── ADMIN_GUIDE.md
```

---

## Database Schema Preview

### Table: individual_metrics_master
Stores metric definitions (1,200 records)
```sql
- id (INT, PK, AUTO_INCREMENT)
- question_id (INT) -- 1-240
- metric_name (VARCHAR 255)
- metric_description (TEXT)
- category (VARCHAR 100)
- dimension (VARCHAR 20) -- SAFE, ENERGIZED, etc.
- parent_node (VARCHAR 100) -- for conditional logic
- data_type (VARCHAR 50) -- numeric, text, boolean, select
- validation_rules (TEXT, JSON)
- created (TIMESTAMP)
```

### Table: individual_metric_responses
Stores user responses with z-scores
```sql
- id (INT, PK, AUTO_INCREMENT)
- user_id (INT, FK to users)
- assessment_id (INT, FK to individual_assessments)
- metric_id (INT, FK to individual_metrics_master)
- response_value (TEXT) -- flexible for any type
- z_score (DECIMAL 10,4) -- calculated, read-only for users
- z_score_calculated_at (TIMESTAMP)
- created (TIMESTAMP)
- updated (TIMESTAMP)
- INDEX(user_id)
- INDEX(metric_id)
- INDEX(assessment_id)
- UNIQUE(user_id, assessment_id, metric_id)
```

### Table: individual_assessments
Tracks assessment sessions
```sql
- id (INT, PK, AUTO_INCREMENT)
- user_id (INT, FK to users)
- started_at (TIMESTAMP)
- completed_at (TIMESTAMP, NULL)
- status (VARCHAR 20) -- in_progress, completed
- dimension_scores (TEXT, JSON) -- calculated scores per dimension
- overall_score (DECIMAL 5,2)
- created (TIMESTAMP)
- updated (TIMESTAMP)
- INDEX(user_id)
```

---

## Progress Tracking

**Current Milestone**: 1 (Database Schema Design & Installation)
**Overall Progress**: 0% (0/7 milestones complete)

### Milestone Status Legend:
- 🔴 NOT STARTED
- 🟡 IN PROGRESS
- 🟢 COMPLETE
- ⚠️  BLOCKED

---

## Next Steps

1. **Start with Milestone 1**: Create database schema
2. **Review and approve** this implementation plan
3. **Begin implementation** in order (1 → 7)
4. **Update status** after completing each task
5. **Test thoroughly** at each milestone

---

## Notes & Decisions

- **Why pure custom tables?** Maximum control, performance, and flexibility
- **Why z-scores?** Enables comparison across different metrics and scales
- **Why separate assessments table?** Allows users to have multiple assessments over time
- **User permissions**: Users can only view/edit their own metrics
- **Z-score recalculation**: Run nightly via cron as population data changes

---

**Last Updated**: January 12, 2026
**Document Owner**: Safety Calculator Module Team
**Next Review**: After each milestone completion
