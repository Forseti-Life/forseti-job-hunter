# AmISafe Gold Layer Analytics - Complete Column Inventory & API Exposure

**Generated:** November 21, 2025  
**Database:** amisafe_h3_aggregated (Gold Layer)  
**Total Columns:** 104 columns across 3 temporal windows (All-time, 12-month, 6-month)

## Executive Summary

The Gold Layer contains **84 analytical columns** computed by stored procedures, organized into:
- **28 All-time Analytics** - Complete historical analysis
- **28 12-Month Windowed** - Recent trend analysis  
- **28 6-Month Windowed** - Current activity analysis
- **20 Core Infrastructure** - H3 indexing, metadata, JSON aggregations

## Column Status Legend

- ✅ **EXPOSED** - Currently returned by API
- ⚠️ **PARTIAL** - Exposed but not all windows/variants
- ❌ **MISSING** - Exists in DB but not exposed by API
- 📊 **CRITICAL** - High-value analytics for visualization

---

## 1. Core Infrastructure Columns (20 columns)

| Column | Type | Status | API Field | Notes |
|--------|------|--------|-----------|-------|
| `id` | bigint | ✅ EXPOSED | `id` | Primary key |
| `h3_index` | varchar(16) | ✅ EXPOSED | `h3_index` | H3 hexagon identifier |
| `h3_resolution` | tinyint | ✅ EXPOSED | `resolution` | 5-13 supported |
| `incident_count` | int | ✅ EXPOSED | `incident_count` | Total incidents all-time |
| `unique_incident_types` | int | ✅ EXPOSED | `unique_types` | Crime type diversity |
| `earliest_incident` | datetime | ✅ EXPOSED | `temporal.earliest` | First recorded incident |
| `latest_incident` | datetime | ✅ EXPOSED | `temporal.latest` | Most recent incident |
| `incidents_last_30_days` | int | ✅ EXPOSED | `temporal.last_30_days` | Rolling 30-day count |
| `incidents_last_year` | int | ✅ EXPOSED | `temporal.last_year` | Rolling 12-month count |
| `center_latitude` | decimal(10,7) | ✅ EXPOSED | `center.lat` | Hexagon center |
| `center_longitude` | decimal(11,7) | ✅ EXPOSED | `center.lng` | Hexagon center |
| `incident_type_counts` | longtext (JSON) | ✅ EXPOSED | `analytics.crime_types` | UCR code breakdown |
| `district_counts` | longtext (JSON) | ✅ EXPOSED | `analytics.districts` | Police district breakdown |
| `total_valid_records` | int | ✅ EXPOSED | `quality.valid_records` | Data quality metric |
| `last_aggregation` | timestamp | ✅ EXPOSED | `metadata.last_updated` | Processing timestamp |
| `incident_ids` | longtext (JSON) | ❌ MISSING | - | Resolution 13 granular filtering |
| `severity_avg` | decimal(4,2) | ❌ MISSING | - | Average crime severity |
| `severity_max` | tinyint | ❌ MISSING | - | Maximum severity in hexagon |
| `data_quality_avg` | decimal(3,2) | ✅ EXPOSED | `quality.avg_score` | Quality score (0-1) |
| `h3_parent` | varchar(16) | ❌ MISSING | - | Parent hexagon for hierarchy |

---

## 2. All-Time Analytics (28 columns) 📊

### 2.1 Basic Crime Statistics (5 columns)

| Column | Type | Status | API Field | Value for Map Coloring |
|--------|------|--------|-----------|------------------------|
| `top_crime_type` | varchar(10) | ✅ EXPOSED | `analytics.top_crime_type` | Most common UCR code |
| `crime_diversity_index` | decimal(3,2) | ✅ EXPOSED | `analytics.crime_diversity` | Shannon entropy (0-3) |
| `peak_hour` | tinyint | ❌ MISSING | - | Hour with most incidents (0-23) |
| `peak_dow` | tinyint | ❌ MISSING | - | Day of week (0=Sun, 6=Sat) |
| `date_range_start` | date | ❌ MISSING | - | Earliest incident date |
| `date_range_end` | date | ❌ MISSING | - | Latest incident date |

### 2.2 Violent Crime Analytics (6 columns) 📊 CRITICAL

| Column | Type | Status | API Field | **IMPORTANCE FOR VISUALIZATION** |
|--------|------|--------|-----------|----------------------------------|
| `violent_crime_count` | int | ✅ EXPOSED | `analytics.violent_count` | Absolute count |
| `violent_crime_percentage` | decimal(5,2) | ❌ MISSING | - | % of total incidents |
| `violent_crime_mean` | decimal(10,2) | ❌ MISSING | - | Statistical mean for resolution |
| `violent_crime_std_dev` | decimal(10,2) | ❌ MISSING | - | Standard deviation |
| **`violent_crime_z_score`** | **decimal(6,3)** | **❌ MISSING** | **-** | **🔴 CRITICAL for heat map coloring** |
| `violent_crime_percentile` | tinyint | ✅ EXPOSED | `analytics.violent_percentile` | Ranking (0-100) |

### 2.3 Non-Violent Crime Analytics (6 columns)

| Column | Type | Status | API Field | Use Case |
|--------|------|--------|-----------|----------|
| `nonviolent_crime_count` | int | ✅ EXPOSED | `analytics.nonviolent_count` | Property crime count |
| `nonviolent_crime_percentage` | decimal(5,2) | ❌ MISSING | - | % of total |
| `nonviolent_crime_mean` | decimal(10,2) | ❌ MISSING | - | Statistical mean |
| `nonviolent_crime_std_dev` | decimal(10,2) | ❌ MISSING | - | Standard deviation |
| **`nonviolent_crime_z_score`** | **decimal(6,3)** | **❌ MISSING** | **-** | **Statistical significance** |
| `nonviolent_crime_percentile` | tinyint | ❌ MISSING | - | Ranking (0-100) |

### 2.4 Overall Incident Analytics (4 columns) 📊 CRITICAL

| Column | Type | Status | API Field | **IMPORTANCE** |
|--------|------|--------|-----------|----------------|
| `incident_mean` | decimal(10,2) | ❌ MISSING | - | Mean incidents per hexagon |
| `incident_std_dev` | decimal(10,2) | ❌ MISSING | - | Standard deviation |
| **`incident_z_score`** | **decimal(6,3)** | **❌ MISSING** | **-** | **🔴 PRIMARY metric for coloring** |
| `incident_percentile` | tinyint | ❌ MISSING | - | Ranking (0-100) |

### 2.5 Risk Assessment (3 columns) 📊 CRITICAL

| Column | Type | Status | API Field | Algorithm |
|--------|------|--------|-----------|-----------|
| **`risk_score`** | **decimal(6,3)** | **❌ MISSING** | **-** | **🔴 Composite risk metric (0-100)** |
| `risk_category` | enum | ✅ EXPOSED | `analytics.risk_level` | LOW/MODERATE/HIGH/CRITICAL |
| `hotspot_status` | enum | ❌ MISSING | - | COLD/WARM/HOT/EXTREME |

### 2.6 Temporal Distribution (4 columns)

| Column | Type | Status | API Field | Content |
|--------|------|--------|-----------|---------|
| `incidents_by_hour` | longtext (JSON) | ❌ MISSING | - | 24-hour distribution array |
| `incidents_by_dow` | longtext (JSON) | ❌ MISSING | - | 7-day weekly pattern |
| `incidents_by_month` | longtext (JSON) | ❌ MISSING | - | 12-month seasonal pattern |
| `boundary_geojson` | longtext (JSON) | ❌ MISSING | - | Hexagon boundary coordinates |

---

## 3. 12-Month Windowed Analytics (28 columns) ⚠️ PARTIAL

### 3.1 Basic Crime Statistics (6 columns)

| Column | Type | Status | API Field | Notes |
|--------|------|--------|-----------|-------|
| `incident_count_12mo` | int | ❌ MISSING | - | Incidents in last 12 months |
| `unique_incident_types_12mo` | int | ❌ MISSING | - | Crime type diversity |
| `top_crime_type_12mo` | varchar(10) | ✅ EXPOSED | `analytics.top_crime_type_12mo` | Most common UCR |
| `crime_diversity_index_12mo` | decimal(3,2) | ❌ MISSING | - | Shannon entropy |
| `peak_hour_12mo` | tinyint | ❌ MISSING | - | Peak activity hour |
| `peak_dow_12mo` | tinyint | ❌ MISSING | - | Peak day of week |

### 3.2 Violent Crime Analytics (6 columns) 📊 CRITICAL

| Column | Type | Status | **CRITICAL MISSING** |
|--------|------|--------|----------------------|
| `violent_crime_count_12mo` | int | ❌ MISSING | Recent violent trend |
| `violent_crime_percentage_12mo` | decimal(5,2) | ❌ MISSING | Proportion analysis |
| `violent_crime_mean_12mo` | decimal(10,2) | ❌ MISSING | Statistical baseline |
| `violent_crime_std_dev_12mo` | decimal(10,2) | ❌ MISSING | Variance measure |
| **`violent_crime_z_score_12mo`** | **decimal(6,3)** | **❌ MISSING** | **🔴 Trending hotspots** |
| `violent_crime_percentile_12mo` | tinyint | ❌ MISSING | Current ranking |

### 3.3 Non-Violent Crime Analytics (6 columns)

| Column | Type | Status | API Field |
|--------|------|--------|-----------|
| `nonviolent_crime_count_12mo` | int | ❌ MISSING | - |
| `nonviolent_crime_percentage_12mo` | decimal(5,2) | ❌ MISSING | - |
| `nonviolent_crime_mean_12mo` | decimal(10,2) | ❌ MISSING | - |
| `nonviolent_crime_std_dev_12mo` | decimal(10,2) | ❌ MISSING | - |
| **`nonviolent_crime_z_score_12mo`** | **decimal(6,3)** | **❌ MISSING** | **Property crime trends** |
| `nonviolent_crime_percentile_12mo` | tinyint | ❌ MISSING | - |

### 3.4 Overall Incident Analytics (4 columns) 📊 CRITICAL

| Column | Type | Status | **IMPORTANCE** |
|--------|------|--------|----------------|
| `incident_mean_12mo` | decimal(10,2) | ❌ MISSING | Recent baseline |
| `incident_std_dev_12mo` | decimal(10,2) | ❌ MISSING | Variance |
| **`incident_z_score_12mo`** | **decimal(6,3)** | **❌ MISSING** | **🔴 Recent activity hotspots** |
| `incident_percentile_12mo` | tinyint | ❌ MISSING | Current ranking |

### 3.5 Risk Assessment (3 columns) ⚠️ PARTIAL

| Column | Type | Status | API Field |
|--------|------|--------|-----------|
| **`risk_score_12mo`** | **decimal(6,3)** | **❌ MISSING** | **Recent risk metric** |
| `risk_category_12mo` | enum | ✅ EXPOSED | `analytics.risk_level_12mo` |
| `hotspot_status_12mo` | enum | ❌ MISSING | - |

### 3.6 Temporal Distribution (3 columns)

| Column | Type | Status | API Field |
|--------|------|--------|-----------|
| `incidents_by_hour_12mo` | longtext (JSON) | ❌ MISSING | - |
| `incidents_by_dow_12mo` | longtext (JSON) | ❌ MISSING | - |
| `incidents_by_month_12mo` | longtext (JSON) | ❌ MISSING | - |

---

## 4. 6-Month Windowed Analytics (28 columns) ⚠️ PARTIAL

*Same structure as 12-month window, all with `_6mo` suffix*

### Key Missing Fields:
- ❌ `incident_z_score_6mo` - **Current hotspot identification**
- ❌ `violent_crime_z_score_6mo` - **Recent violent trends**
- ❌ `risk_score_6mo` - **Current risk assessment**
- ✅ `risk_category_6mo` - **EXPOSED** via `analytics.risk_level_6mo`
- ✅ `top_crime_type_6mo` - **EXPOSED** via `analytics.top_crime_type_6mo`

---

## 5. Critical Missing Data for Map Visualization 🔴

### 5.1 Z-Score Fields (PRIMARY for Heat Map Coloring)

**All-Time Window:**
- ❌ `incident_z_score` - Overall crime intensity (standard deviations from mean)
- ❌ `violent_crime_z_score` - Violent crime hotspot identification  
- ❌ `nonviolent_crime_z_score` - Property crime patterns

**12-Month Window:**
- ❌ `incident_z_score_12mo` - Recent trending hotspots
- ❌ `violent_crime_z_score_12mo` - Current violent crime trends
- ❌ `nonviolent_crime_z_score_12mo` - Current property crime trends

**6-Month Window:**
- ❌ `incident_z_score_6mo` - Immediate activity hotspots
- ❌ `violent_crime_z_score_6mo` - Very recent violent patterns
- ❌ `nonviolent_crime_z_score_6mo` - Very recent property patterns

### 5.2 Risk Scores (Composite Metrics)

- ❌ `risk_score` - All-time risk composite (0-100 scale)
- ❌ `risk_score_12mo` - Recent risk trend
- ❌ `risk_score_6mo` - Current risk level

### 5.3 Statistical Context

- ❌ `incident_mean`, `incident_std_dev` - Baseline for z-score calculation
- ❌ `violent_crime_mean`, `violent_crime_std_dev` - Violent crime baseline
- ❌ `incident_percentile` - Ranking among all hexagons (0-100)

### 5.4 Hotspot Classification

- ❌ `hotspot_status` - COLD/WARM/HOT/EXTREME classification
- ❌ `hotspot_status_12mo` - Recent hotspot status
- ❌ `hotspot_status_6mo` - Current hotspot status

### 5.5 Temporal Patterns (JSON Arrays)

- ❌ `incidents_by_hour` - 24-hour activity pattern for all windows
- ❌ `incidents_by_dow` - Weekly pattern (Mon-Sun)
- ❌ `incidents_by_month` - Seasonal patterns (Jan-Dec)

### 5.6 Geographic Data

- ❌ `boundary_geojson` - Hexagon boundary for precise rendering
- ❌ `incident_ids` - Resolution 13 granular filtering (JSON array)

---

## 6. Recommended API Updates

### Phase 1: Critical Z-Scores (IMMEDIATE - Required for proper coloring)

```php
// Add to CrimeDataService.php getH3Aggregations() SELECT:
'incident_z_score', 'violent_crime_z_score', 'nonviolent_crime_z_score',
'incident_z_score_12mo', 'violent_crime_z_score_12mo',
'incident_z_score_6mo', 'violent_crime_z_score_6mo',
```

```php
// Add to processH3Aggregation() return array:
'analytics' => [
  // ... existing fields ...
  'z_scores' => [
    'incident' => floatval($aggregation['incident_z_score'] ?? 0),
    'violent' => floatval($aggregation['violent_crime_z_score'] ?? 0),
    'nonviolent' => floatval($aggregation['nonviolent_crime_z_score'] ?? 0),
  ],
  'z_scores_12mo' => [
    'incident' => floatval($aggregation['incident_z_score_12mo'] ?? 0),
    'violent' => floatval($aggregation['violent_crime_z_score_12mo'] ?? 0),
  ],
  'z_scores_6mo' => [
    'incident' => floatval($aggregation['incident_z_score_6mo'] ?? 0),
    'violent' => floatval($aggregation['violent_crime_z_score_6mo'] ?? 0),
  ],
],
```

### Phase 2: Risk Scores & Hotspot Status

```php
'risk_score', 'risk_score_12mo', 'risk_score_6mo',
'hotspot_status', 'hotspot_status_12mo', 'hotspot_status_6mo',
```

### Phase 3: Statistical Context

```php
'incident_mean', 'incident_std_dev', 'incident_percentile',
'violent_crime_mean', 'violent_crime_std_dev',
```

### Phase 4: Temporal Patterns

```php
'incidents_by_hour', 'incidents_by_dow', 'incidents_by_month',
'peak_hour', 'peak_dow',
```

### Phase 5: 12-Month & 6-Month Complete Windows

```php
'incident_count_12mo', 'incident_count_6mo',
'violent_crime_count_12mo', 'violent_crime_count_6mo',
'crime_diversity_index_12mo', 'crime_diversity_index_6mo',
```

---

## 7. Crime Map Color Coding Strategy 🎨

### Recommended Primary Metric: `incident_z_score`

**Why Z-Score is Superior to Raw Counts:**
- Normalized across different resolutions (H3:5 to H3:13)
- Accounts for hexagon size differences
- Statistical significance (how unusual is this hexagon?)
- Values typically range from -3 to +3 (can be mapped to colors)

**Color Scale Based on Z-Score:**
```
z < -1.0  : Blue (Below average, very safe)
-1.0 to 0 : Green (Below average, safe)
0 to 1.0  : Yellow (Average)
1.0 to 2.0: Orange (Above average, caution)
2.0 to 3.0: Red (High activity, 2σ above mean)
> 3.0     : Dark Red (Extreme hotspot, 3σ above mean)
```

**Current Implementation Issue:**
The crime map is likely using `incident_count` or `risk_category` for coloring, which doesn't normalize properly across resolutions. A Resolution 5 hexagon (251 km²) will always appear "hotter" than a Resolution 13 hexagon (44 m²) even if the smaller one has proportionally more crime.

---

## 8. Performance Impact Analysis

### Current API Response (Optimized):
- **Resolution 9:** 0.478s for 1000 hexagons
- **Resolution 13:** 0.251s for 1000 hexagons
- **Fields returned:** 25 core fields + 2 JSON fields

### After Adding Z-Scores (Estimated):
- **Additional fields:** +9 z-scores, +3 risk scores
- **Performance impact:** < 5% (all numeric types, no JSON parsing)
- **Response size:** +10% (12 additional decimal values)
- **Cache effectiveness:** Same (30-minute TTL)

### After Adding Temporal Patterns (Estimated):
- **Additional fields:** +9 JSON arrays (hourly/DOW/monthly patterns)
- **Performance impact:** +20-30% (JSON encoding overhead)
- **Response size:** +40% (large JSON arrays)
- **Recommendation:** Separate API endpoint `/api/amisafe/hexagon-details/{h3_index}`

---

## 9. Implementation Priority

### 🔴 **CRITICAL - Implement Immediately**
1. Add all z-score fields to API (9 fields)
2. Update crime map color logic to use `incident_z_score`
3. Add z-score legend to map UI

### 🟡 **HIGH - Implement Soon**
4. Add risk scores and hotspot status (6 fields)
5. Add statistical context (means, std_devs, percentiles)
6. Add windowed counts (12mo/6mo incident counts)

### 🟢 **MEDIUM - Implement Later**
7. Add temporal patterns (separate details endpoint)
8. Add boundary GeoJSON for precise hexagon rendering
9. Add incident_ids for Resolution 13 drill-down

### ⚪ **LOW - Optional Enhancement**
10. Add parent hexagon relationships for drill-up/down
11. Add severity metrics (avg/max severity)
12. Add data freshness indicators

---

## 10. Testing & Validation

### Before Changes:
```bash
curl "http://localhost/api/amisafe/aggregated?resolution=9&limit=1" | jq '.hexagons[0].analytics'
# Returns: 11 fields (no z-scores)
```

### After Phase 1 (Z-Scores):
```bash
curl "http://localhost/api/amisafe/aggregated?resolution=9&limit=1" | jq '.hexagons[0].analytics.z_scores'
# Should return: incident, violent, nonviolent z-scores for all 3 windows
```

### Validation Query:
```sql
SELECT 
  h3_index,
  incident_count,
  incident_z_score,
  risk_category,
  CASE 
    WHEN incident_z_score > 3 THEN 'EXTREME'
    WHEN incident_z_score > 2 THEN 'HIGH'  
    WHEN incident_z_score > 1 THEN 'MODERATE'
    ELSE 'LOW'
  END as z_score_category
FROM amisafe_h3_aggregated
WHERE h3_resolution = 9
ORDER BY incident_z_score DESC
LIMIT 10;
```

---

## Summary Statistics

- **Total Columns in Gold Layer:** 104
- **Currently Exposed by API:** 25 (24%)
- **Critical Missing for Visualization:** 9 z-score fields
- **Additional High-Value Fields:** 18 (risk scores, hotspot status, temporal)
- **Database Indexes:** 29 indexes (optimized for API queries)
- **API Performance:** Sub-second response times
- **Cache Strategy:** 30-minute TTL for aggregated data

**Recommendation:** Implement Phase 1 (z-scores) immediately to enable proper heat map visualization based on statistical significance rather than raw counts.
