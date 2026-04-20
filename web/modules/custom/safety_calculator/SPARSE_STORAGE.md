# Individual Metrics Sparse Storage Architecture

## Overview

The Individual Metrics system uses **sparse storage** to efficiently handle 1,200+ metrics per user assessment. Instead of storing all 1,200 metrics for every user (which would be 1,200 columns or rows), we only store metrics that users explicitly answer.

## Database Tables

### 1. `individual_metrics_master` (Master Data)
Contains definitions for all 1,200 metrics with population statistics.

**Key fields:**
- `id` - Unique metric ID
- `question_id` - Question number (1-240)
- `metric_name` - Machine name
- `dimension` - SAFE, ENERGIZED, CONNECTED, FREE, CAPABLE, USEFUL, WHOLE, DEMOGRAPHIC
- `category` - One of 28 categories
- `population_mean` - Population average (used as default)
- `population_stddev` - Population standard deviation
- `reference_url` - Research source URL

### 2. `individual_assessments` (User Sessions)
Tracks each user's assessment session.

**Key fields:**
- `id` - Assessment session ID
- `user_id` - User ID
- `status` - 'in_progress', 'completed', 'archived'
- `started_at` / `completed_at` - Timestamps
- `overall_score` - Calculated overall score
- `dimension_scores` - JSON of scores by dimension
- `metadata` - JSON for additional context

### 3. `individual_metric_responses` (Sparse Storage)
**Only stores explicitly answered metrics.**

**Key fields:**
- `id` - Response ID
- `assessment_id` - Links to assessment
- `user_id` - User ID  
- `metric_id` - Links to master metric
- `question_id` - Denormalized for quick lookups
- **`response_value`** - User's actual response (TEXT, flexible)
- **`is_explicit`** - BOOLEAN: 1 = user answered, 0 = calculated/defaulted
- `z_score` - Calculated z-score vs population
- `z_score_calculated_at` - Timestamp

**Unique constraint:** `(assessment_id, metric_id)` - one response per metric per assessment

### 4. `individual_dimension_scores` (Performance Cache)
Pre-calculated aggregations by dimension for quick retrieval.

**Key fields:**
- `assessment_id` / `user_id` / `dimension`
- `score` - Dimension score (0-100)
- `response_count` - # of explicit responses
- `avg_z_score` - Average z-score for dimension
- `calculated_at` - Cache timestamp

## Sparse Storage Benefits

### Storage Efficiency
- **Traditional approach**: 1,200 columns × 10,000 users = 12M cells (mostly empty)
- **Sparse approach**: Only store answered metrics
  - If user answers 200/1200 metrics: 200 rows × 100 bytes = 20KB
  - 83% storage savings vs storing all 1,200

### Data Integrity
- **Clear semantics**: NULL = "not answered" vs "answered and equals mean"
- **Audit trail**: Know exactly what user provided
- **Flexibility**: Can change population means without affecting old assessments

### Performance
- **Fewer rows** to insert/update/query
- **Indexed lookups** on sparse data
- **Dimension cache** for aggregations

## Query Patterns

### Get User's Responses with Defaults

```sql
-- Merge user responses with defaults from master table
SELECT 
  m.id as metric_id,
  m.question_id,
  m.metric_name,
  m.dimension,
  m.category,
  m.metric_description,
  COALESCE(r.response_value, m.population_mean) as response_value,
  COALESCE(r.is_explicit, 0) as is_explicit,
  COALESCE(r.z_score, 0.0) as z_score,
  m.population_mean,
  m.population_stddev,
  m.reference_url,
  r.z_score_calculated_at
FROM individual_metrics_master m
LEFT JOIN individual_metric_responses r 
  ON m.id = r.metric_id 
  AND r.assessment_id = :assessment_id
WHERE m.dimension = :dimension
ORDER BY m.question_id, m.id;
```

### Get Latest Assessment for User

```sql
SELECT a.*, 
       COUNT(DISTINCT r.id) as answered_count,
       (SELECT COUNT(*) FROM individual_metrics_master) as total_metrics
FROM individual_assessments a
LEFT JOIN individual_metric_responses r ON a.id = r.assessment_id
WHERE a.user_id = :user_id 
  AND a.status = 'completed'
GROUP BY a.id
ORDER BY a.completed_at DESC
LIMIT 1;
```

### Calculate Dimension Scores

```sql
-- Calculate and cache dimension scores
INSERT INTO individual_dimension_scores 
  (assessment_id, user_id, dimension, score, response_count, avg_z_score, calculated_at)
SELECT 
  r.assessment_id,
  r.user_id,
  m.dimension,
  -- Convert z-scores to 0-100 scale (simplified)
  50 + (AVG(r.z_score) * 10) as score,
  COUNT(DISTINCT r.id) as response_count,
  AVG(r.z_score) as avg_z_score,
  UNIX_TIMESTAMP() as calculated_at
FROM individual_metric_responses r
JOIN individual_metrics_master m ON r.metric_id = m.id
WHERE r.assessment_id = :assessment_id
  AND r.is_explicit = 1
GROUP BY r.assessment_id, r.user_id, m.dimension;
```

## Application Logic

### Repository Pattern

```php
// IndividualMetricsRepository::getMetricsWithDefaults()

public function getMetricsWithDefaults(int $assessmentId, string $dimension): array {
  $query = $this->database->select('individual_metrics_master', 'm');
  $query->leftJoin('individual_metric_responses', 'r', 
    'm.id = r.metric_id AND r.assessment_id = :assessment_id',
    [':assessment_id' => $assessmentId]
  );
  
  $query->fields('m')
    ->fields('r', ['response_value', 'is_explicit', 'z_score', 'z_score_calculated_at'])
    ->condition('m.dimension', $dimension)
    ->orderBy('m.question_id')
    ->orderBy('m.id');
  
  $results = $query->execute()->fetchAll();
  
  // Process results: use response if exists, otherwise use population_mean
  foreach ($results as &$row) {
    if (empty($row->response_value)) {
      $row->response_value = $row->population_mean;
      $row->is_explicit = 0;
      $row->z_score = 0.0; // At mean = z-score of 0
    }
  }
  
  return $results;
}
```

### Saving Responses

```php
// Only store explicitly answered metrics
public function saveResponse(int $assessmentId, int $userId, int $metricId, $value): void {
  // Only insert/update if user provided a value
  if ($value !== null && $value !== '') {
    $this->database->merge('individual_metric_responses')
      ->keys([
        'assessment_id' => $assessmentId,
        'metric_id' => $metricId,
      ])
      ->fields([
        'user_id' => $userId,
        'question_id' => $this->getQuestionId($metricId),
        'response_value' => (string) $value,
        'is_explicit' => 1,
        'updated' => time(),
      ])
      ->execute();
  }
  // If value is null/empty, don't store anything (use default from master)
}
```

## When to Store vs. Default

### ✅ Store a Response When:
- User explicitly answered the question
- Value is not empty/null
- Want to track change history
- Need custom z-score calculation

### ❌ Use Default (Don't Store) When:
- User skipped/didn't answer
- Using population mean as placeholder
- Building initial empty assessment
- No user input provided

## Z-Score Calculation

```php
public function calculateZScore(int $assessmentId, int $metricId): void {
  $response = $this->getResponse($assessmentId, $metricId);
  $metric = $this->getMasterMetric($metricId);
  
  if ($response && $metric->population_stddev > 0) {
    // Z = (X - μ) / σ
    $z_score = ($response->response_value - $metric->population_mean) 
               / $metric->population_stddev;
    
    $this->database->update('individual_metric_responses')
      ->fields([
        'z_score' => round($z_score, 4),
        'z_score_calculated_at' => time(),
      ])
      ->condition('id', $response->id)
      ->execute();
  }
}
```

## Performance Considerations

### Indexes
- `(assessment_id, metric_id)` - Primary lookup
- `(user_id, dimension)` - Dimension filtering
- `is_explicit` - Distinguish user vs default values
- `question_id` - Quick question-based lookups

### Caching Strategy
1. **On assessment completion**: Calculate and store dimension scores
2. **On demand**: Merge responses with defaults from master
3. **Invalidate**: When user updates responses or metrics change

### Query Optimization
- Use `LEFT JOIN` to merge sparse responses with master defaults
- Index on `(assessment_id, metric_id)` for O(1) lookups
- Precompute dimension aggregations
- Batch z-score calculations

## Migration Notes

- **Backward compatible**: Existing data marked `is_explicit = 1`
- **No data loss**: All existing responses preserved
- **Gradual adoption**: New assessments use sparse storage
- **Fallback**: Query pattern handles both sparse and full data
