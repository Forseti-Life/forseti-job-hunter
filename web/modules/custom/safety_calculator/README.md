# Safety Calculator Module

**Last Updated:** February 6, 2026  
**Version**: 1.0.0  
**Drupal**: 11.x  
**Package**: Forseti  
**Type**: Custom Module  
**Status**: 🟢 Active Development

---

## Overview

### What is the Safety Calculator?

The **Safety Calculator** is a real-time risk assessment engine that calculates **current safety scores** for any location to help individuals, families, and institutions make informed safety decisions.

**Who uses it?**
- 👤 **Individuals**: Check safety before walking, jogging, or visiting unfamiliar areas
- 👨‍👩‍👧‍👦 **Families**: Evaluate neighborhood safety for daily activities and commutes
- 🏢 **Institutions**: Assess risk for employee safety, facility locations, and operational planning

**What does it calculate?**
- **Current Safety Score** (0-100): Real-time assessment based on historical crime data
- **Risk Level**: Clear categorization (Low, Moderate, High, Critical)
- **Crime Patterns**: Detailed breakdown by crime type and severity
- **Area Analysis**: Multi-hexagon evaluation for comprehensive understanding

### Technical Foundation

Built following **Drupal 11 best practices**, this module integrates with the [H3 Geolocation Framework](../../../../h3-geolocation/README.md) and [AmISafe module](../amisafe/README.md) to deliver precision safety analytics.

**Data Source**: Leverages the complete 3-layer data warehouse with **3.4M+ crime incidents** and **413,173 H3 hexagon aggregations** spanning Philadelphia's entire metropolitan area.

---

## Features

### Core Capabilities

- ✅ **Real-Time Safety Scores**: Calculate current safety scores (0-100) for any location
- ✅ **Multi-User Types**: Optimized for individuals, families, and institutional use cases
- ✅ **Risk Level Assessment**: Automatic categorization (Low, Moderate, High, Critical)
- ✅ **Multi-Resolution Support**: Resolution 5-13 (251 km² city-wide to 44 m² building-level precision)
- ✅ **Time-based Analysis**: Evaluate safety patterns by time of day and historical periods
- ✅ **Crime Type Weighting**: Severity-weighted analysis (violent crimes weighted 3x)
- ✅ **Area Context**: Analyze surrounding hexagons for comprehensive neighborhood assessment
- ✅ **RESTful JSON API**: Modern API for mobile apps and third-party integration
- ✅ **Performance Optimized**: Configurable caching with sub-second response times
- ✅ **Admin Dashboard**: Full configuration UI following Drupal 11 patterns

### Use Case Examples

**For Individuals** 👤
- "Is it safe to walk home from work right now?"
- "Should I take the bus or walk to the store?"
- "Is this running route safe at 6 AM?"

**For Families** 👨‍👩‍👧‍👦
- "Is this neighborhood safe for our kids to play outside?"
- "What's the safety score near our children's school?"
- "Should we avoid this area during evening hours?"

**For Institutions** 🏢
- "What's the risk level for our new facility location?"
- "Do we need additional security at this office?"
- "Which employee routes have the highest safety concerns?"
- "Generate safety reports for compliance documentation"

---

## Architecture Integration

### Platform Integration

```
┌─────────────────────────────────────────────────────────┐
│                  Forseti.life Platform                  │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  ┌──────────────┐    ┌──────────────┐                 │
│  │   AmISafe    │───►│   Safety     │                 │
│  │   Module     │    │   Calculator │                 │
│  │              │    │              │                 │
│  └──────┬───────┘    └──────┬───────┘                 │
│         │                   │                          │
│         ▼                   ▼                          │
│  ┌──────────────────────────────────┐                │
│  │    MySQL Data Warehouse          │                │
│  │    - Bronze: 3.4M incidents      │                │
│  │    - Silver: H3-indexed data     │                │
│  │    - Gold: 413K aggregations     │                │
│  └──────────────────────────────────┘                │
│         ▲                                              │
│         │                                              │
│  ┌──────┴───────┐                                     │
│  │   H3 Python  │                                     │
│  │   Framework  │                                     │
│  └──────────────┘                                     │
└─────────────────────────────────────────────────────────┘
```

### Module Dependencies

- **drupal:node** - Content management
- **amisafe:amisafe** - Crime data access and H3 aggregations

---

## Installation & Setup

### Requirements

- Drupal 11.0+
- PHP 8.1+
- MySQL 8.0+
- AmISafe module enabled
- H3 Geolocation Framework with populated data warehouse

### Installation Steps

1. **Place module in custom modules directory**:
```bash
cd /var/www/html/forseti/web/modules/custom/
# Module already in place: safety_calculator/
```

2. **Enable the module**:
```bash
drush en safety_calculator -y
drush cr
```

3. **Verify installation**:
```bash
drush pml --status=enabled | grep safety_calculator
```

4. **Configure settings**:
   - Navigate to: `/admin/config/forseti/safety-calculator`
   - Set default H3 resolution (recommended: 13)
   - Configure search radius (recommended: 1-2 rings)
   - Set cache TTL (recommended: 3600 seconds)

---

## API Usage

### Calculate Safety Score

**Endpoint**: `/api/safety/calculate`  
**Method**: GET or POST  
**Format**: JSON  
**Authentication**: Session-based (Drupal standard)  
**Rate Limiting**: Respects Drupal cache settings

#### Request Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `lat` | float | ✅ Yes | - | Latitude (-90 to 90) |
| `lon` | float | ✅ Yes | - | Longitude (-180 to 180) |
| `resolution` | integer | No | 13 | H3 resolution (5-13) |
| `radius` | integer | No | 1 | Hexagon rings to analyze (0-5) |
| `time_filter` | string | No | null | Time period: `last_30_days`, `last_year`, `custom` |

#### Example Request

**Using cURL**:
```bash
curl "https://forseti.life/api/safety/calculate?lat=39.9526&lon=-75.1652&radius=1" \
  -H "Accept: application/json"
```

**Using JavaScript**:
```javascript
fetch('https://forseti.life/api/safety/calculate?lat=39.9526&lon=-75.1652&radius=1')
  .then(response => response.json())
  .then(data => console.log(data));
```

**Using Drupal Service**:
```php
$safety_calculator = \Drupal::service('safety_calculator.calculator');
$result = $safety_calculator->calculateSafetyScore(39.9526, -75.1652, 13, ['radius' => 1]);
```

#### Example Response

**Success Response** (HTTP 200):
```json
{
  "status": "success",
  "data": {
    "score": 78.5,
    "risk_level": "low",
    "crime_count": 12,
    "hexagon": "8d2baad9c6e87ff",
    "hexagons_analyzed": 7,
    "details": {
      "theft": 5,
      "vandalism": 4,
      "assault": 3
    },
    "timestamp": 1705081234
  }
}
```

**Error Response** (HTTP 400):
```json
{
  "status": "error",
  "error": "Missing required parameters: lat and lon"
}
```

**Error Response** (HTTP 500):
```json
{
  "status": "error",
  "error": "Error calculating safety score"
}
```

---

## Safety Scoring System

### Score Interpretation

| Score Range | Risk Level | Color | Description | Recommendation |
|-------------|------------|-------|-------------|----------------|
| 80-100 | **Low** | 🟢 Green | Area is very safe | Normal activities |
| 60-79 | **Moderate** | 🟡 Yellow | Exercise normal caution | Standard awareness |
| 40-59 | **High** | 🟠 Orange | Be cautious | Increased vigilance |
| 0-39 | **Critical** | 🔴 Red | High danger | Avoid if possible |

### Scoring Algorithm

The safety score uses a **multi-factor weighted algorithm**:

1. **Base Score**: Starts at 100 (perfectly safe)
2. **Crime Density Penalty**: Logarithmic scale to account for crime concentration
3. **Severity Weighting**: Different crime types have appropriate severity multipliers
4. **Temporal Weighting**: Recent crimes weighted more heavily (if enabled)
5. **Spatial Aggregation**: Neighboring hexagons included based on radius setting

**Formula**:
```
Safety Score = Base Score - Crime Penalty - Weighted Severity Penalty
Crime Penalty = min(80, crime_count × 2 + log(crime_count + 1) × 5)
```

### Crime Severity Weights

Following FBI UCR (Uniform Crime Reporting) guidelines:

| Crime Type | Weight | Category | Notes |
|------------|--------|----------|-------|
| Violent Crime | 3.0x | Part I | Homicide, aggravated assault |
| Assault | 2.5x | Part I | Simple & aggravated |
| Robbery | 2.0x | Part I | Armed & unarmed |
| Burglary | 1.5x | Part I | Breaking & entering |
| Theft | 1.0x | Part I | Larceny, vehicle theft |
| Vandalism | 0.5x | Part II | Property damage |
| Other | 0.3x | Part II | Minor offenses |

---

## Configuration

### Admin Interface

**Path**: Configuration → Forseti → Safety Calculator Settings  
**URL**: `/admin/config/forseti/safety-calculator`  
**Permission**: `administer site configuration`

### Configuration Options

#### Calculation Settings

- **Default H3 Resolution** (11-14)
  - 11: ~700m hexagons (neighborhood level)
  - 12: ~270m hexagons (block level)
  - 13: ~100m hexagons (building level) ⭐ Recommended
  - 14: ~40m hexagons (room level)

- **Default Search Radius** (0-5 rings)
  - 0: Single hexagon only
  - 1: Include immediate neighbors (7 hexagons total) ⭐ Recommended
  - 2: Extended radius (19 hexagons)
  - 3-5: Wide area analysis

- **Cache TTL** (seconds)
  - Recommended: 3600 (1 hour)
  - Set to 0 to disable caching
  - Balances performance vs. data freshness

#### Scoring Settings

- **Base Safety Score** (0-100)
  - Default: 100
  - Starting point before penalties

- **Enable Time-based Weighting**
  - Recent crimes weighted more heavily
  - Exponential decay over time
  - Recommended: Enabled

### Database Integration

The module uses Drupal's **Database API** following Drupal 11 best practices:

- ✅ Prepared statements (SQL injection prevention)
- ✅ Query builders for complex queries
- ✅ Transaction support
- ✅ Connection pooling
- ✅ Error handling with logging

**Data Sources**:
```php
// Gold Layer (Primary)
amisafe_h3_aggregated   // 413K hexagon aggregations (Resolution 5-13)

// Silver Layer (Fallback)
amisafe_clean_incidents // 3.4M individual incidents with H3 indexes

// Bronze Layer (Raw - Read Only)
amisafe_raw_incidents   // Original CSV imports
```

---

## Development Guide

### Service Architecture (Drupal 11 Patterns)

The module follows **Drupal 11 dependency injection** patterns:

```php
services:
  safety_calculator.calculator:
    class: Drupal\safety_calculator\SafetyCalculatorService
    arguments: ['@database', '@logger.factory']
    
  safety_calculator.risk_analyzer:
    class: Drupal\safety_calculator\RiskAnalyzerService
    arguments: ['@database', '@safety_calculator.calculator']
```

### Using the Service in Custom Code

**Dependency Injection (Recommended)**:
```php
namespace Drupal\my_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\safety_calculator\SafetyCalculatorService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MyController extends ControllerBase {

  protected $safetyCalculator;

  public function __construct(SafetyCalculatorService $safety_calculator) {
    $this->safetyCalculator = $safety_calculator;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('safety_calculator.calculator')
    );
  }

  public function myMethod() {
    $result = $this->safetyCalculator->calculateSafetyScore(
      39.9526,  // latitude
      -75.1652, // longitude
      13,       // resolution
      ['radius' => 2]
    );
    
    return [
      '#markup' => "Safety Score: {$result['score']} ({$result['risk_level']})",
    ];
  }
}
```

**Service Locator (Legacy Support)**:
```php
$safety_calculator = \Drupal::service('safety_calculator.calculator');
$result = $safety_calculator->calculateSafetyScore(39.9526, -75.1652);
```

### Time-based Safety Analysis

```php
// Analyze safety for specific times of day
$morning = $safety_calculator->calculateTimeBasedSafety(
  39.9526,
  -75.1652,
  'morning'  // Options: morning, afternoon, evening, night
);

// Custom time filtering
$result = $safety_calculator->calculateSafetyScore(
  39.9526,
  -75.1652,
  13,
  [
    'time_filter' => 'last_30_days',
    'radius' => 2,
  ]
);
```

### Extending the Module

**Custom Risk Analyzer**:
```php
namespace Drupal\my_module\Services;

use Drupal\safety_calculator\SafetyCalculatorService;

class CustomRiskAnalyzer {

  protected $safetyCalculator;

  public function __construct(SafetyCalculatorService $safety_calculator) {
    $this->safetyCalculator = $safety_calculator;
  }

  public function analyzeRoute(array $waypoints) {
    $scores = [];
    foreach ($waypoints as $point) {
      $scores[] = $this->safetyCalculator->calculateSafetyScore(
        $point['lat'],
        $point['lon']
      );
    }
    
    return [
      'average_score' => array_sum(array_column($scores, 'score')) / count($scores),
      'min_score' => min(array_column($scores, 'score')),
      'risk_segments' => $this->identifyRiskSegments($scores),
    ];
  }
}
```

### Hook Implementation

**Alter Safety Calculations**:
```php
/**
 * Implements hook_safety_calculator_score_alter().
 */
function my_module_safety_calculator_score_alter(&$score, $context) {
  // Apply custom business logic
  if ($context['hexagon'] === 'special_area') {
    $score['score'] *= 1.2; // Boost score for special protected areas
  }
}
```

---

## Testing

### Manual Testing

**Test the API endpoint**:
```bash
# Valid request
curl "https://forseti.life/api/safety/calculate?lat=39.9526&lon=-75.1652" | jq

# Invalid coordinates
curl "https://forseti.life/api/safety/calculate?lat=999&lon=999" | jq

# With filters
curl "https://forseti.life/api/safety/calculate?lat=39.9526&lon=-75.1652&radius=2&time_filter=last_30_days" | jq
```

**Test the service directly**:
```bash
drush php:eval "
\$calc = \\Drupal::service('safety_calculator.calculator');
\$result = \$calc->calculateSafetyScore(39.9526, -75.1652, 13);
print_r(\$result);
"
```

### Unit Testing (PHPUnit)

```php
namespace Drupal\Tests\safety_calculator\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\safety_calculator\SafetyCalculatorService;

/**
 * @coversDefaultClass \Drupal\safety_calculator\SafetyCalculatorService
 * @group safety_calculator
 */
class SafetyCalculatorTest extends UnitTestCase {

  public function testScoreCalculation() {
    // Test implementation
  }
}
```

### Integration Testing

See: `/testing/apitesting/` for comprehensive API validation framework

---

## Performance Optimization

### Caching Strategy

The module implements **multi-layer caching** following Drupal 11 best practices:

1. **Application Cache** (Drupal Cache API)
   - Cached safety scores with configurable TTL
   - Tag-based cache invalidation
   - Per-hexagon cache bins

2. **Database Query Cache**
   - Prepared statement caching
   - Query result caching for aggregations

3. **H3 Resolution Cache**
   - Pre-computed hexagon relationships
   - Neighbor hexagon lookups

**Cache Implementation**:
```php
// Cache safety score for 1 hour
$cid = "safety_score:{$hexagon}:{$resolution}";
if ($cache = \Drupal::cache()->get($cid)) {
  return $cache->data;
}

$result = $this->calculateSafetyScore($lat, $lon, $resolution);

\Drupal::cache()->set($cid, $result, 
  time() + 3600, 
  ['safety_calculator', "hexagon:{$hexagon}"]
);
```

### Database Optimization

- ✅ Indexed columns: `hexagon_id`, `incident_date`, `crime_type`
- ✅ Query optimization using aggregated Gold layer
- ✅ Limit result sets with pagination
- ✅ Use specific resolution queries (avoid full table scans)

### Best Practices

1. **Use appropriate resolution**:
   - City overview: Resolution 7-9
   - Neighborhood: Resolution 11-12
   - Street level: Resolution 13
   - Building level: Resolution 14 (use sparingly)

2. **Limit radius**:
   - Default radius of 1 ring covers ~7 hexagons
   - Radius 2 covers ~19 hexagons (good balance)
   - Avoid radius > 3 for real-time queries

3. **Enable caching**:
   - Set TTL to at least 1 hour for production
   - Use cache tags for selective invalidation

---

## Security Considerations

### Data Privacy

- ✅ No personally identifiable information (PII) stored
- ✅ Aggregated crime data only (individual incidents anonymized)
- ✅ Location data not linked to user accounts
- ✅ API rate limiting via Drupal's built-in mechanisms

### Access Control

- ✅ API endpoint accessible to authenticated users
- ✅ Configuration form restricted to administrators
- ✅ CSRF protection on all form submissions
- ✅ Input validation on all parameters

### SQL Injection Prevention

All queries use **Drupal's Database API** with:
- ✅ Prepared statements
- ✅ Parameter binding
- ✅ Query builders (no raw SQL)
- ✅ Input sanitization

---

## Troubleshooting

### Common Issues

**Issue**: API returns empty hexagon ID
- **Cause**: No crime data for the coordinates
- **Solution**: Check if coordinates are within Philadelphia area, verify data warehouse is populated

**Issue**: Score always returns 0 or 100
- **Cause**: Crime weighting misconfigured
- **Solution**: Check severity weights in service, verify crime types in database

**Issue**: Slow API response
- **Cause**: Large radius or cache disabled
- **Solution**: Enable caching, reduce radius, optimize database indexes

**Issue**: "Unable to determine H3 hexagon" error
- **Cause**: Invalid coordinates or missing H3 data
- **Solution**: Validate lat/lon ranges, ensure H3 framework is initialized

### Debugging

**Enable debug logging**:
```php
// In settings.php
$config['system.logging']['error_level'] = 'verbose';
```

**Check service logs**:
```bash
drush watchdog:show --type=safety_calculator --count=50
```

**Verify database connection**:
```bash
drush sql:query "SELECT COUNT(*) FROM amisafe_h3_aggregated WHERE resolution = 13"
```

---

## Roadmap & Future Enhancements

### Phase 1 - Core Functionality ✅
- [x] Basic safety score calculation
- [x] H3 hexagon integration
- [x] RESTful API endpoint
- [x] Admin configuration form
- [x] Crime severity weighting
- [x] Time-based filtering

### Phase 2 - Advanced Analytics 🔄
- [ ] Machine learning-based predictions
- [ ] Historical trend analysis (6-month, 1-year)
- [ ] Seasonal pattern detection
- [ ] Weather correlation analysis
- [ ] Event-based risk assessment

### Phase 3 - Enhanced Features 📋
- [ ] Route safety calculation (point A to B)
- [ ] Real-time incident weighting
- [ ] Community safety reports integration
- [ ] Heatmap generation and export
- [ ] Bulk location analysis API
- [ ] WebSocket support for real-time updates

### Phase 4 - Mobile Integration 📋
- [ ] React Native SDK
- [ ] Background location monitoring
- [ ] Push notification for risk changes
- [ ] Offline mode with cached scores
- [ ] Voice alerts integration

### Phase 5 - Institutional Features 📋
- [ ] Multi-tenant support for organizations
- [ ] Custom risk thresholds per organization
- [ ] Compliance reporting (export to PDF/CSV)
- [ ] SLA monitoring and alerting
- [ ] White-label API for partners

---

## Contributing

### Development Workflow

1. **Create feature branch**: `git checkout -b feature/safety-calculator-enhancement`
2. **Follow Drupal coding standards**: Use `phpcs` with Drupal rules
3. **Write tests**: Unit tests for services, integration tests for API
4. **Document changes**: Update README and inline documentation
5. **Submit for review**: Create pull request with clear description

### Coding Standards

- ✅ Follow [Drupal Coding Standards](https://www.drupal.org/docs/develop/standards)
- ✅ Use PHPStan for static analysis
- ✅ Document all public methods with PHPDoc
- ✅ Use dependency injection (no `\Drupal::` in services)
- ✅ Write self-documenting code with clear variable names

### Code Review Checklist

- [ ] Follows Drupal 11 best practices
- [ ] Uses dependency injection
- [ ] Includes proper error handling
- [ ] Has appropriate logging
- [ ] Includes cache implementation
- [ ] Has input validation
- [ ] Follows security best practices
- [ ] Updates documentation
- [ ] Includes tests

---

## Support & Resources

### Documentation

- **Module README**: This file
- **AmISafe Module**: [../amisafe/README.md](../amisafe/README.md)
- **H3 Framework**: [../../../../h3-geolocation/README.md](../../../../h3-geolocation/README.md)
- **Platform Architecture**: [../../../../docs/ARCHITECTURE.md](../../../../docs/ARCHITECTURE.md)

### Related Modules

- **AmISafe**: Crime data and H3 aggregations
- **Google Tag**: Analytics tracking
- **Group**: Multi-tenant permissions (future)
- **Flexible Permissions**: Custom access control (future)

### Requirements

- Drupal 11.0+
- PHP 8.1+
- MySQL 8.0+ (or MariaDB 10.5+)
- AmISafe module
- H3 Geolocation Framework

### Author & Maintainers

**Forseti Safety Platform Development Team**

### License

Proprietary - All rights reserved  
© 2024-2026 Forseti.life

---

## Changelog

### Version 1.0.0 (2026-01-12)
- ✅ Initial release
- ✅ Core safety score calculation
- ✅ RESTful JSON API
- ✅ Admin configuration interface
- ✅ Integration with AmISafe module
- ✅ Multi-resolution H3 support (5-13)
- ✅ Crime severity weighting
- ✅ Time-based filtering
- ✅ Comprehensive documentation

---

**Last Updated**: 2026-01-12  
**Documentation Version**: 1.0.0
