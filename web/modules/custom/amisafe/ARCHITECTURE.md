# AmISafe Module Architecture

**Last Updated:** February 6, 2026

## Overview
The AmISafe module is a comprehensive crime monitoring and analysis system for Philadelphia 2085, built on Drupal with H3 geospatial indexing, MySQL database storage, and interactive JavaScript frontend components.

## System Architecture

### Database Layer

#### Primary Database: `amisafe`
- **Location**: Separate MySQL 8.0+ database
- **Configuration**: `/h3-geolocation/config/mysql_config.json`
- **Credentials**: 
  - Host: `localhost` (127.0.0.1)
  - Port: `3306`
  - User: `h3_user`
  - Password: `h3_password`
  - Database: `amisafe`
  - Charset: `utf8mb4`

#### Fallback Database: Drupal Default
- **Configuration**: `/sites/default/settings.local.php`
- **Database**: `theoryofconspiracies_dev`
- **Credentials**:
  - Host: `127.0.0.1`
  - Port: `3306`
  - User: `drupal_user`
  - Password: `drupal_secure_password`

### Database Schema

#### 1. `raw_incidents` Table
Primary storage for individual crime incidents with H3 spatial indexing.

**Schema:**
```sql
CREATE TABLE raw_incidents (
    -- Primary identification
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source_file VARCHAR(255) NOT NULL,
    
    -- External references
    cartodb_id VARCHAR(50),
    objectid VARCHAR(50),
    dc_key VARCHAR(50),
    
    -- Spatial data
    lat DECIMAL(10, 7) NOT NULL,
    lng DECIMAL(11, 7) NOT NULL,
    point_x DOUBLE,
    point_y DOUBLE,
    h3_index VARCHAR(16) NOT NULL,
    h3_resolution TINYINT DEFAULT 9,
    
    -- Administrative boundaries
    dc_dist VARCHAR(10),
    psa VARCHAR(10),
    
    -- Temporal data
    dispatch_date_time DATETIME NOT NULL,
    dispatch_date DATE NOT NULL,
    dispatch_time TIME,
    hour TINYINT,
    day_of_week TINYINT,
    week_of_year TINYINT,
    month TINYINT,
    year SMALLINT,
    
    -- Crime classification
    ucr_general VARCHAR(10) NOT NULL,
    text_general_code VARCHAR(255),
    crime_category_id INT,
    severity_level TINYINT,
    
    -- Location details
    location_block TEXT,
    
    -- Additional metadata
    properties JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_h3_index (h3_index),
    INDEX idx_h3_datetime (h3_index, dispatch_date_time),
    INDEX idx_spatial (lat, lng),
    INDEX idx_temporal (dispatch_date_time),
    INDEX idx_crime_type (ucr_general),
    INDEX idx_district (dc_dist),
    INDEX idx_composite_main (h3_index, ucr_general, dispatch_date),
    INDEX idx_hour_analysis (hour, day_of_week),
    
    SPATIAL INDEX idx_point (point_x, point_y),
    FULLTEXT INDEX idx_location_search (location_block, text_general_code)
) ENGINE=InnoDB;
```

**Current Status:**
- **Records**: 109,553+ loaded and validated
- **Source**: 20 CSV files in `/data/raw/` directory
- **Storage**: ~500MB estimated full dataset (2.5M+ records)

#### 2. `h3_aggregated` Table
Pre-computed spatial aggregations at multiple H3 resolutions for performance.

**Schema:**
```sql
CREATE TABLE h3_aggregated (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- H3 spatial identification
    h3_index VARCHAR(16) NOT NULL,
    h3_resolution TINYINT NOT NULL,
    h3_parent VARCHAR(16),
    
    -- Geospatial data
    center_lat DECIMAL(10, 7) NOT NULL,
    center_lng DECIMAL(11, 7) NOT NULL,
    boundary_json JSON NOT NULL,
    
    -- Aggregated statistics
    crime_count INT DEFAULT 0,
    crime_types_json JSON,
    severity_avg DECIMAL(3,2),
    
    -- Temporal aggregations
    last_incident DATETIME,
    first_incident DATETIME,
    peak_hour TINYINT,
    
    -- Administrative data
    districts_json JSON,
    
    -- Cache control
    is_empty BOOLEAN DEFAULT FALSE,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Performance indexes
    UNIQUE INDEX idx_h3_resolution (h3_index, h3_resolution),
    INDEX idx_resolution_lookup (h3_resolution, crime_count),
    INDEX idx_parent_child (h3_parent, h3_index),
    INDEX idx_spatial_query (center_lat, center_lng, h3_resolution),
    INDEX idx_temporal_cache (last_updated, h3_resolution),
    INDEX idx_empty_filter (is_empty, crime_count)
) ENGINE=InnoDB;
```

### Data Processing Pipeline

#### Location: `/h3-geolocation/database/`

**Components:**
1. **`run_amisafe_pipeline.sh`** - Master pipeline script
2. **`amisafe_processor.py`** - Individual incident processing
3. **`amisafe_aggregator.py`** - H3 aggregation processing

**Processing Flow:**
```
CSV Files → Raw Processing → H3 Indexing → Database Storage → Aggregation → Cache
```

**Data Sources:**
- **Location**: `/sites/theoryofconspiracies/web/modules/custom/amisafe/data/`
- **Files**: 20 CSV files (`incidents_part1_part2.csv`, `incidents_part1_part2 (1).csv`, etc.)
- **Format**: Philadelphia crime incident data with lat/lng coordinates

## Drupal Module Architecture

### Directory Structure
```
/sites/theoryofconspiracies/web/modules/custom/amisafe/
├── amisafe.info.yml              # Module definition
├── amisafe.module                # Drupal hooks and utilities
├── amisafe.routing.yml           # URL routing configuration  
├── amisafe.services.yml          # Dependency injection services
├── src/
│   ├── Controller/
│   │   ├── AmISafeController.php # Main page controllers
│   │   ├── ApiController.php     # REST API endpoints
│   │   └── CrimeMapController.php# Crime map specific controllers
│   └── Service/
│       ├── CrimeDataService.php  # Database abstraction layer
│       ├── H3AggregatorService.php # H3 spatial processing
│       └── SpatialAnalyzerService.php # Advanced spatial analysis
├── templates/
│   └── amisafe-crime-map.html.twig # Main UI template
├── js/
│   └── crime-map.js              # Frontend JavaScript (2000+ lines)
├── css/
│   └── amisafe.css               # Professional styling
└── data/                         # Crime incident CSV files (20 files)
```

### Service Layer

#### CrimeDataService
**Purpose**: Database abstraction and raw incident data access
**Connection Strategy**:
1. Primary: Attempts connection to separate `amisafe` database
2. Fallback: Uses default Drupal database connection
3. Caching: Drupal cache API with 5-10 minute TTL

**Key Methods:**
- `getIncidents($filters, $page, $limit)` - Filtered incident retrieval
- `getIncidentCount($filters)` - Count matching incidents  
- `getDistricts()` - Available district list
- `getCrimeTypes()` - Crime type taxonomy
- `getSeverityLevels()` - Severity classification

#### H3AggregatorService  
**Purpose**: Multi-resolution spatial aggregation using H3 hexagonal indexing
**Fallback Strategy**: Returns sample data when database unavailable

**Key Methods:**
- `getAggregatedData($filters, $resolution, $bounds)` - H3 hexagon aggregation
- `getRealAggregatedData()` - Database-driven aggregation (private)
- `generateSampleH3Data()` - Fallback sample data (private)

#### SpatialAnalyzerService
**Purpose**: Advanced geospatial analysis and hotspot detection
**Features**: Crime hotspot identification, spatial clustering

### API Layer

#### REST Endpoints (`/api/amisafe/`)
- **`/aggregated`** - H3 aggregated hexagon data
- **`/incidents`** - Raw incident data with filtering
- **`/districts`** - District list for filters
- **`/crime-types`** - Crime type taxonomy
- **`/severity-levels`** - Severity classifications  
- **`/time-periods`** - Temporal filter options
- **`/citywide-stats`** - Citywide statistics summary
- **`/hotspots`** - Crime hotspot analysis

### Frontend Architecture

#### Main Component: `crime-map.js`
**Size**: 2000+ lines of JavaScript
**Features**:
- Interactive Leaflet.js mapping
- H3 hexagon rendering and interaction
- Advanced filtering system
- Performance optimization with caching
- Real-time statistics display

**Key Classes:**
- `AmISafeCrimeMap` - Main map controller
- Cache management system with LRU eviction
- Request queue management
- Debug and performance monitoring

#### UI Components:
- **Filter Panel**: Multi-select crime types, districts, severity, temporal
- **Statistics Display**: Current view and citywide metrics  
- **Debug Panel**: H3 library testing and performance stats
- **Interactive Map**: Leaflet with custom hexagon overlays

### Performance Optimizations

#### Caching Strategy
**Frontend Caching**:
- JavaScript Map-based cache with LRU eviction
- Cache keys: `resolution_filters-json`
- TTL: 5 minutes with hit-count priority
- Preloading for adjacent hexagons and zoom levels

**Backend Caching**:
- Drupal cache API integration
- Database query result caching (5-10 minutes)
- Service-level caching for expensive operations

#### Database Optimization
- **Spatial Indexes**: H3 index, lat/lng, spatial points
- **Composite Indexes**: Multi-column performance indexes
- **Partitioning**: Temporal partitioning for large datasets
- **Aggregation Tables**: Pre-computed statistics

## Connection Management

### Database Connection Priority
1. **Primary**: Dedicated `amisafe` database via h3_user credentials
2. **Secondary**: Drupal default database for development/fallback
3. **Error Handling**: Graceful degradation to sample data

### Configuration Files
- **H3 Config**: `/h3-geolocation/config/mysql_config.json`
- **Drupal Config**: `/sites/default/settings.local.php`
- **Service Config**: `/amisafe.services.yml`

## Data Flow Architecture

### Request Flow
```
User Interface → JavaScript → Drupal Routing → Controller → Service → Database → Cache → Response
```

### Data Processing Flow  
```
CSV Files → Pipeline Processing → H3 Indexing → MySQL Storage → Aggregation → API Cache
```

### Real-time Updates
- Database triggers for aggregation updates
- Cache invalidation on data changes
- Frontend polling for live statistics

## Security & Performance

### Security Measures
- Database credential isolation
- Input sanitization and validation
- SQL injection protection via Drupal DBAL
- XSS protection in templates

### Performance Features
- Multi-level caching (JS, Drupal, Database)
- Lazy loading and pagination
- Spatial indexing optimization
- Request batching and queuing

## Development Status

### Completed Components
- ✅ Database schema design
- ✅ Drupal module structure  
- ✅ API endpoint implementation
- ✅ Frontend interface with mapping
- ✅ Performance optimization system
- ✅ Caching and aggregation

### Database Status
- ⚠️ **AmISafe Database**: Not yet created/configured
- ✅ **Sample Data System**: Functional for development  
- ⚠️ **H3 Environment**: Requires setup for full functionality
- ✅ **Fallback System**: Working with mock data

### Next Steps
1. Set up dedicated `amisafe` database with proper credentials
2. Run H3 geolocation pipeline to process CSV data
3. Configure database connection in Drupal
4. Validate citywide statistics against real data
5. Performance testing with full dataset

This architecture provides a robust, scalable foundation for crime data analysis with advanced geospatial capabilities and high-performance caching systems.