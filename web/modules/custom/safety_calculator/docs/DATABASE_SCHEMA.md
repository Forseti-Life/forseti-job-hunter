# Individual Metrics Database Schema

**Version**: 1.0  
**Created**: January 12, 2026  
**Module**: safety_calculator

---

## Overview

This database schema supports the storage of **1,200 individual metrics** across **240 questions** organized into **8 dimensions** and **28 categories**. The design uses pure custom tables for maximum performance and flexibility.

---

## Database Tables

### Table 1: `individual_metrics_master`

**Purpose**: Stores the definition of all 1,200 metrics

**Record Count**: 1,200 (5 metrics × 240 questions)

#### Schema

| Field | Type | Null | Description |
|-------|------|------|-------------|
| `id` | INT (PK) | NO | Unique metric ID (auto-increment) |
| `question_id` | INT | NO | Question number (1-240) |
| `metric_name` | VARCHAR(255) | NO | Machine name (e.g., "age_in_years") |
| `metric_description` | TEXT | NO | Human-readable description |
| `category` | VARCHAR(100) | NO | One of 28 categories |
| `dimension` | VARCHAR(20) | NO | SAFE, ENERGIZED, CONNECTED, FREE, CAPABLE, USEFUL, WHOLE, DEMOGRAPHIC |
| `parent_node` | VARCHAR(100) | YES | Gateway question for conditional logic |
| `data_type` | VARCHAR(50) | NO | numeric, text, boolean, select, scale |
| `validation_rules` | TEXT | YES | JSON validation rules |
| `created` | INT | NO | Unix timestamp (creation date) |

#### Indexes

- **Primary Key**: `id`
- **Index**: `question_id`
- **Index**: `dimension`
- **Index**: `category`
- **Index**: `metric_name`
- **Unique**: `question_id + metric_name` (prevents duplicate metrics per question)

#### Example Record

```sql
INSERT INTO individual_metrics_master VALUES (
  1,                                    -- id
  211,                                  -- question_id (Age question)
  'age_in_years',                       -- metric_name
  'Exact age in years',                 -- metric_description
  'Demographic Information',            -- category
  'DEMOGRAPHIC',                        -- dimension
  NULL,                                 -- parent_node (universal question)
  'numeric',                            -- data_type
  '{"min": 18, "max": 120}',           -- validation_rules
  1736640000                            -- created (Unix timestamp)
);
```

---

### Table 2: `individual_metric_responses`

**Purpose**: Stores user responses to metrics with calculated z-scores

**Record Count**: Variable (users × metrics they've answered)

#### Schema

| Field | Type | Null | Description |
|-------|------|------|-------------|
| `id` | INT (PK) | NO | Unique response ID (auto-increment) |
| `user_id` | INT (FK) | NO | User ID (references users table) |
| `assessment_id` | INT (FK) | NO | Assessment session ID |
| `metric_id` | INT (FK) | NO | Metric ID (references individual_metrics_master) |
| `response_value` | TEXT | YES | User's response (flexible type) |
| `z_score` | DECIMAL(10,4) | YES | Calculated z-score (system only) |
| `z_score_calculated_at` | INT | YES | Unix timestamp of z-score calculation |
| `created` | INT | NO | Unix timestamp (first response) |
| `updated` | INT | NO | Unix timestamp (last update) |

#### Indexes

- **Primary Key**: `id`
- **Index**: `user_id` (fast user lookups)
- **Index**: `assessment_id` (fast assessment lookups)
- **Index**: `metric_id` (fast metric lookups)
- **Index**: `user_id + metric_id` (composite for user-metric queries)
- **Index**: `z_score` (for statistical queries)
- **Unique**: `user_id + assessment_id + metric_id` (one response per metric per assessment)

#### Example Record

```sql
INSERT INTO individual_metric_responses VALUES (
  1,                  -- id
  42,                 -- user_id
  1,                  -- assessment_id
  1,                  -- metric_id (age_in_years)
  '35',               -- response_value
  0.2500,             -- z_score (calculated)
  1736640000,         -- z_score_calculated_at
  1736640000,         -- created
  1736640000          -- updated
);
```

#### Z-Score Calculation

The z-score is calculated as:

```
z_score = (user_value - population_mean) / population_std_dev
```

**Example**:
- User age: 35
- Population mean age: 45
- Population std dev: 15
- Z-score: (35 - 45) / 15 = -0.6667

**Interpretation**:
- z = 0: User is at population average
- z > 0: User is above average
- z < 0: User is below average
- |z| > 2: User is in outer 5% of population

---

### Table 3: `individual_assessments`

**Purpose**: Tracks assessment sessions and overall scores

**Record Count**: Variable (one per assessment session per user)

#### Schema

| Field | Type | Null | Description |
|-------|------|------|-------------|
| `id` | INT (PK) | NO | Unique assessment ID (auto-increment) |
| `user_id` | INT (FK) | NO | User ID (references users table) |
| `started_at` | INT | NO | Unix timestamp when assessment started |
| `completed_at` | INT | YES | Unix timestamp when completed (NULL if in progress) |
| `status` | VARCHAR(20) | NO | "in_progress" or "completed" |
| `dimension_scores` | TEXT | YES | JSON: Scores per dimension (0-100) |
| `overall_score` | DECIMAL(5,2) | YES | Overall score (0-100) |
| `created` | INT | NO | Unix timestamp (record creation) |
| `updated` | INT | NO | Unix timestamp (last update) |

#### Indexes

- **Primary Key**: `id`
- **Index**: `user_id` (fast user lookups)
- **Index**: `status` (filter by completion status)
- **Index**: `completed_at` (temporal queries)

#### Example Record

```sql
INSERT INTO individual_assessments VALUES (
  1,                                      -- id
  42,                                     -- user_id
  1736640000,                             -- started_at
  1736643600,                             -- completed_at (1 hour later)
  'completed',                            -- status
  '{"SAFE":75,"ENERGIZED":82,"CONNECTED":68,"FREE":91,"CAPABLE":78,"USEFUL":85,"WHOLE":72,"DEMOGRAPHIC":100}', -- dimension_scores
  81.38,                                  -- overall_score (average of dimensions)
  1736640000,                             -- created
  1736643600                              -- updated
);
```

#### Dimension Scores JSON Format

```json
{
  "SAFE": 75.0,
  "ENERGIZED": 82.0,
  "CONNECTED": 68.0,
  "FREE": 91.0,
  "CAPABLE": 78.0,
  "USEFUL": 85.0,
  "WHOLE": 72.0,
  "DEMOGRAPHIC": 100.0
}
```

---

## Relationships

```
individual_assessments (1) ──< (many) individual_metric_responses
                │
                └──> (many) individual_metrics_master

users (1) ──< (many) individual_assessments
         └──< (many) individual_metric_responses
```

**Foreign Keys** (enforced at application level):
- `individual_metric_responses.user_id` → `users.uid`
- `individual_metric_responses.assessment_id` → `individual_assessments.id`
- `individual_metric_responses.metric_id` → `individual_metrics_master.id`
- `individual_assessments.user_id` → `users.uid`

---

## Data Types & Validation

### Supported Data Types

| Type | Description | Example |
|------|-------------|---------|
| `numeric` | Integer or decimal numbers | `35`, `75.5`, `-10` |
| `text` | Free-form text | `"Philadelphia, PA"` |
| `boolean` | Yes/No | `1` (yes), `0` (no) |
| `select` | Predefined choices | `"employed_ft"`, `"unemployed"` |
| `scale` | Rating scale | `0-10`, `1-5` |

### Validation Rules Format (JSON)

```json
{
  "type": "numeric",
  "min": 0,
  "max": 120,
  "required": true
}
```

```json
{
  "type": "select",
  "options": ["never", "rarely", "sometimes", "often"],
  "required": true
}
```

```json
{
  "type": "scale",
  "min": 0,
  "max": 10,
  "step": 1,
  "required": true
}
```

---

## Query Examples

### Get all metrics for a dimension

```sql
SELECT * FROM individual_metrics_master 
WHERE dimension = 'DEMOGRAPHIC' 
ORDER BY question_id, metric_name;
```

### Get user's responses for a specific assessment

```sql
SELECT 
  m.question_id,
  m.metric_name,
  m.metric_description,
  r.response_value,
  r.z_score,
  m.dimension
FROM individual_metric_responses r
JOIN individual_metrics_master m ON r.metric_id = m.id
WHERE r.user_id = 42 
  AND r.assessment_id = 1
ORDER BY m.question_id, m.metric_name;
```

### Get population statistics for a metric

```sql
SELECT 
  m.metric_name,
  COUNT(*) as response_count,
  AVG(CAST(r.response_value AS DECIMAL)) as mean,
  STDDEV(CAST(r.response_value AS DECIMAL)) as std_dev,
  MIN(CAST(r.response_value AS DECIMAL)) as min_value,
  MAX(CAST(r.response_value AS DECIMAL)) as max_value
FROM individual_metric_responses r
JOIN individual_metrics_master m ON r.metric_id = m.id
WHERE m.metric_name = 'age_in_years'
  AND m.data_type = 'numeric'
GROUP BY m.metric_name;
```

### Get user's latest assessment

```sql
SELECT * FROM individual_assessments
WHERE user_id = 42
ORDER BY created DESC
LIMIT 1;
```

### Get metrics needing z-score calculation

```sql
SELECT r.*, m.data_type
FROM individual_metric_responses r
JOIN individual_metrics_master m ON r.metric_id = m.id
WHERE r.z_score IS NULL
  AND m.data_type = 'numeric'
LIMIT 100;
```

---

## Installation

### Via Drush

```bash
# Install tables
drush updatedb

# Or specific update
drush updatedb --entity-updates
```

### Manual Installation

```bash
# Check schema
drush sql-query "SHOW TABLES LIKE 'individual_%';"

# Verify record counts
drush sql-query "SELECT COUNT(*) FROM individual_metrics_master;"
```

---

## Maintenance

### Backup

```bash
# Backup all individual metrics tables
drush sql-dump --tables-list=individual_metrics_master,individual_metric_responses,individual_assessments > individual_metrics_backup.sql
```

### Optimization

```bash
# Analyze tables
drush sql-query "ANALYZE TABLE individual_metrics_master, individual_metric_responses, individual_assessments;"

# Check indexes
drush sql-query "SHOW INDEX FROM individual_metric_responses;"
```

---

## Performance Considerations

### Expected Record Counts

| Table | Records per User | Total (10K users) |
|-------|------------------|-------------------|
| `individual_metrics_master` | N/A (static) | 1,200 |
| `individual_assessments` | 1-5 | 10,000 - 50,000 |
| `individual_metric_responses` | 1,200 | 12,000,000 |

### Index Usage

- All foreign keys are indexed
- Composite indexes for common query patterns
- Z-score indexed for statistical analysis

### Optimization Strategies

1. **Partitioning**: Consider partitioning `individual_metric_responses` by user_id for large datasets
2. **Archiving**: Move old assessments to archive tables after 2 years
3. **Caching**: Cache metric definitions (rarely change)
4. **Batch Processing**: Calculate z-scores in batches via cron

---

## Security

### Access Control

- Users can only view/edit their own responses
- Z-scores are read-only for users
- System calculates z-scores via service
- Admin permission required for bulk operations

### Data Privacy

- User responses contain sensitive demographic and health data
- Apply GDPR/HIPAA compliance measures
- Implement data retention policies
- Provide data export for users

---

## Migration Path

If migrating from existing safety_assessment entity:

1. Export existing assessment data
2. Map to new metric structure
3. Import into `individual_metric_responses`
4. Calculate z-scores
5. Archive old entity data

---

**Last Updated**: January 12, 2026  
**Schema Version**: 1.0  
**Next Review**: After Milestone 2 (data import)
