# AmISafe - Crime Monitoring & Analytics System

**Last Updated:** February 6, 2026  
**🎉 RESOLUTION 13 ULTRA-PRECISION DRUPAL INTEGRATION COMPLETE**

## Overview
The AmISafe module is a comprehensive crime monitoring and spatial analysis system that provides **ultra-fine spatial precision** crime data visualization using **Resolution 13 H3 geospatial analysis** with room-level (44m²) precision, interactive filtering, and professional analytics interface.

**🏆 H3 Ultra-Precision Data**: This module now leverages the complete [H3 Geolocation Framework](../../../../h3-geolocation/README.md) with **Resolution 13 ultra-precision capabilities**. The H3 framework has processed **3.4M+ incident records** and provides **413,172 hexagon aggregations** across 8 resolution levels for unprecedented spatial analytics.

**✅ PRODUCTION DATA READY**: The module now connects to the complete **3-layer data warehouse** (Bronze→Silver→Gold) with full **Resolution 13 support** and **real-time multi-resolution analytics**.

## 🚀 Module Integration Status - COMPLETE

✅ **CrimeDataService**: Optimized with Resolution 5 citywide hexagon for efficient statistics  
✅ **H3AggregatorService**: Complete Gold layer integration (amisafe_h3_aggregated)  
✅ **AmISafeController**: Resolution 13 dashboard with ultra-precision stats  
✅ **ApiController**: Full Resolution 5-13 with single-hexagon citywide optimization  
✅ **AmISafeConfigForm**: Complete admin configuration for all resolutions  
✅ **Routing**: Admin configuration routes and API endpoints ready  
✅ **API Bug Fixed**: Incident counting now uses single Resolution 5 hexagon (1.48M incidents)  
✅ **Testing Framework**: Comprehensive validation and reference documentation in `/testing/apitesting/`

## Features

### 🗺️ Interactive Crime Map
- **H3 Geospatial Analysis**: Crime data aggregated into hexagonal sectors for precise spatial analysis
- **Multiple Visualization Modes**: Hexagon view, heatmap, and individual points
- **Dynamic Resolution**: Automatically adjusts detail level based on zoom (supports 1-meter precision)
- **Real-time Updates**: Live filtering and data refresh capabilities

### 🎛️ Advanced Filtering System
- **Crime Type Filtering**: Filter by specific crime categories (Murder, Robbery, Theft, etc.)
- **District-Based Analysis**: Focus on specific Philadelphia police districts
- **Temporal Filtering**: Date ranges and time-of-day analysis
- **Severity Levels**: Filter by threat levels (Low → Extreme)
- **Quick Presets**: One-click filters for common analysis patterns

### 📊 Real-time Statistics Dashboard
- **Citywide Overview**: Total incidents, active districts, threat levels
- **Current View Stats**: Statistics for visible map area
- **Dynamic Updates**: Real-time recalculation as filters change
- **Threat Level Assessment**: AI-enhanced threat level calculations

### 🎨 Professional Analytics Interface
- **Clean Design**: Professional styling with clear data visualization
- **Interactive Elements**: Modern buttons, dropdowns, and controls  
- **Loading Animations**: Smooth progress indicators and data loading states
- **Responsive Design**: Optimized for desktop, tablet, and mobile

## Installation & Setup

### Requirements
- Drupal 9, 10, or 11
- MySQL database for crime data storage
- H3 JavaScript library for geospatial processing
- Leaflet.js for map rendering

### Module Installation
1. Place the `amisafe` module in `web/modules/custom/`
2. Enable the module: `drush en amisafe`
3. Import crime data using provided CSV files
4. Configure database connections if needed

## 🗄️ Complete Dataset Definition

### **Production Data Warehouse Architecture**
The AmISafe module operates on a **3-layer data warehouse** with **3.4M+ crime incidents** processed into **ultra-precision H3 spatial analytics**:

#### **Layer 1: Bronze (Raw Data)**
```sql
Table: amisafe_raw_incidents
Records: 3,406,192 incidents
Purpose: Raw CSV import from Philadelphia Police data (2015-2025)
Columns: the_geom, cartodb_id, objectid, dc_dist, psa, dispatch_date_time, 
         location_block, ucr_general, point_x, point_y, lat, lng
```

#### **Layer 2: Silver (H3 Indexed)**
```sql
Table: amisafe_clean_incidents  
Records: 3,406,175 incidents (99.995% success rate)
Purpose: Validated incidents with H3 spatial indexing (resolutions 1-15)
Enhancements: Data quality scoring, coordinate validation, H3 index assignment
```

#### **Layer 3: Gold (Analytics Ready)**
```sql
Table: amisafe_h3_aggregated
Records: 413,173 hexagon aggregations (including Resolution 5 citywide)
Purpose: Multi-resolution spatial analytics optimized for dashboard queries
Resolution Range: 5-13 (251 km² citywide down to 44 m² ultra-precision)
```

### **H3 Resolution Capabilities**
```
Resolution │ Hexagon Area │ Count     │ Precision Level    │ Use Case
-----------|--------------|-----------|-------------------|------------------
5          │ 251.1 km²    │     1     │ Philadelphia Metro│ Citywide statistics
6          │ 36.1 km²     │    22     │ City districts    │ District overview
7          │ 5.2 km²      │    93     │ District          │ Neighborhood analysis
8          │ 0.7 km²      │   545     │ Neighborhood      │ Large block analysis
9          │ 0.1 km²      │ 3,150     │ Block Group       │ Street-level detail
10         │ 15,047 m²    │16,739   │ Block             │ Building groups
11         │ 2,150 m²     │69,513   │ Building          │ Individual structures
12         │ 307 m²       │145,982  │ Room-level        │ Building sections
13         │ 44 m²        │177,128  │ ULTRA-PRECISION   │ Room/parking detail
-----------|--------------|---------|-------------------|------------------
TOTAL      │ Multi-Scale  │413,172  │ 20.1x Improvement │ Complete coverage
```

### **Gold Layer Schema (amisafe_h3_aggregated)**
```sql
CREATE TABLE amisafe_h3_aggregated (
  id                        INT AUTO_INCREMENT PRIMARY KEY,
  h3_index                  VARCHAR(20) NOT NULL,           -- H3 hexagon identifier
  h3_resolution             TINYINT NOT NULL,               -- Resolution (6-13)
  incident_count            INT DEFAULT 0,                  -- Total incidents
  unique_incident_types     INT DEFAULT 0,                  -- Crime type diversity
  earliest_incident         DATETIME,                       -- Temporal range start
  latest_incident           DATETIME,                       -- Most recent activity
  incidents_last_30_days    INT DEFAULT 0,                  -- Recent activity
  incidents_last_year       INT DEFAULT 0,                  -- Annual patterns
  center_latitude           DECIMAL(10,8),                  -- Precise coordinates
  center_longitude          DECIMAL(11,8),                  -- Precise coordinates
  incident_type_counts      JSON,                           -- {"1400": 5, "300": 12}
  district_counts           JSON,                           -- {"15": 8, "12": 4}
  avg_data_quality_score    DECIMAL(3,2),                   -- Validation score
  total_valid_records       INT DEFAULT 0,                  -- Quality metrics
  total_invalid_records     INT DEFAULT 0,                  -- Quality metrics
  last_aggregation          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  source_record_count       INT DEFAULT 0,                  -- Aggregation source
  aggregation_method        VARCHAR(50) DEFAULT 'standard'  -- Processing method
);
```

## Usage

### Main Interfaces
- **Dashboard**: `/amisafe` - Main "Am I Safe?" dashboard with threat overview
- **Crime Map**: `/amisafe/crime-map` - Interactive crime mapping interface

### **Ultra-Precision API Endpoints**
- `/api/amisafe/aggregated` - Multi-resolution H3 hexagon analytics (Resolutions 6-13)
- `/api/amisafe/ultra-precision` - Resolution 12-13 high-detail analytics
- `/api/amisafe/system-stats` - Complete system capabilities and dataset statistics
- `/api/amisafe/incidents` - Silver layer incident data with H3 indexing
- `/api/amisafe/hotspots` - Spatial hotspot analysis with resolution support
- `/api/amisafe/crime-types` - Crime category definitions and statistics
- `/api/amisafe/districts` - Police district boundaries and activity metrics

### **API Features**
- **Resolution Support**: Full range 6-13 with automatic precision metadata
- **Gold Layer Integration**: Direct access to 413K precomputed aggregations
- **Performance Optimization**: 30-minute caching for ultra-precision queries
- **Real-time Statistics**: Live dataset metrics and system capabilities
- **Spatial Filtering**: Bounds-based queries for map viewport optimization

## Detailed Documentation

### 📖 Complete Interface Guide
For comprehensive documentation of every button, control, and feature, see:
**[INTERFACE_DOCUMENTATION.md](./INTERFACE_DOCUMENTATION.md)**

This detailed guide covers:
- Every button and control with exact behavior
- All filter options and expected responses  
- Interactive map features and click behaviors
- Statistics panel explanations
- Keyboard shortcuts and mouse interactions
- Visual states, animations, and effects
- Expected user workflows and use cases
- Developer integration details

### 🎯 Key Features Documented
- **27 Interactive Controls**: Buttons, dropdowns, toggles with exact IDs
- **12 Filter Types**: Crime categories, districts, time periods, severity levels
- **6 Statistics Displays**: Both citywide and view-specific metrics
- **8 API Endpoints**: Complete endpoint documentation with expected responses
- **4 Visualization Modes**: Hexagon, heatmap, points, and fullscreen views

## File Structure
```
amisafe/
├── amisafe.info.yml              # Module definition
├── amisafe.routing.yml           # URL routing configuration  
├── amisafe.services.yml          # Dependency injection services
├── amisafe.libraries.yml         # CSS/JS library definitions
├── README.md                     # This overview document
├── INTERFACE_DOCUMENTATION.md    # Complete interface guide
├── css/                          # Professional styling
│   ├── crime-map.css            # Main map interface styles
│   ├── dashboard.css            # Dashboard styling  
│   ├── professional-theme.css   # Core professional theme
│   └── h3-hexagons.css          # H3 hexagon visualizations
├── js/
│   └── crime-map.js             # Interactive map functionality
├── src/
│   ├── Controller/              # Drupal controllers
│   │   ├── AmISafeController.php       # Main dashboard
│   │   ├── CrimeMapController.php      # Crime map interface
│   │   └── ApiController.php           # REST API endpoints
│   └── Service/                 # Business logic services
│       ├── CrimeDataService.php        # Database operations
│       ├── H3AggregatorService.php     # Geospatial processing
│       └── SpatialAnalyzerService.php  # Crime analysis
├── templates/                   # Twig templates
│   ├── amisafe-dashboard.html.twig     # Main dashboard UI
│   └── amisafe-crime-map.html.twig     # Crime map interface  
└── data/                        # Sample crime data
    └── incidents_part1_part2*.csv     # Philadelphia crime datasets
```

## Technical Details

### H3 Geospatial Integration
- **Library**: H3 JavaScript library v4+
- **Resolution Range**: 8-15 (city-wide to meter-level precision)
- **Coordinate Handling**: Automatic conversion between H3 [lng,lat] and Leaflet [lat,lng]
- **Performance**: Cached hexagon boundaries with intelligent cache management

### Database Schema
- **Raw Incidents Table**: Stores individual crime records
- **H3 Aggregation**: Pre-computed hexagon statistics for performance
- **Spatial Indexing**: Optimized queries for geographic data

### API Performance
- **Caching**: Intelligent response caching with cache invalidation
- **Debouncing**: 500ms debounce on filter changes to reduce API calls
- **Request Management**: Automatic cancellation of superseded requests
- **Fallback Data**: Graceful degradation with sample data when APIs fail

## Development

### Extending the Module
- **Add Crime Categories**: Update `CrimeDataService::getCrimeTypes()`
- **New Visualizations**: Extend map view modes in `crime-map.js`  
- **Custom Filters**: Add filter logic to `ApiController::parseFilters()`
- **Enhanced Analytics**: Expand the analytics dashboard section

### Debugging Tools
- **H3 Debug Panel**: Real-time H3 library status and function availability
- **Console Logging**: Comprehensive logging with debug mode toggle
- **API Test Endpoints**: `/api/amisafe/debug-test` for troubleshooting

## 🧪 Testing & Validation Framework

### Testing Documentation Location
All API testing, validation scripts, and reference data are maintained in:
```
/testing/apitesting/
├── database_statistics_reference.md    # Complete Silver/Gold layer statistics
├── api_validation_results.md          # API endpoint testing results  
├── api_bug_fixed_report.md            # Resolution 5 bug fix documentation
├── bug_verification_script.sh         # Automated bug verification
└── generate_resolution_5_citywide.py  # Resolution 5 hexagon generator
```

### Key Validation Queries
```sql
-- Verify Silver layer total
SELECT COUNT(*) FROM amisafe_clean_incidents;
-- Result: 3,406,175 incidents

-- Verify Resolution 5 citywide hexagon
SELECT incident_count FROM amisafe_h3_aggregated WHERE h3_resolution = 5;  
-- Result: 1,488,452 incidents (Philadelphia metro core)

-- Verify Gold layer resolution breakdown
SELECT h3_resolution, COUNT(*) as hexagon_count, SUM(incident_count) as total_incidents
FROM amisafe_h3_aggregated GROUP BY h3_resolution ORDER BY h3_resolution;
```

### API Testing Commands
```bash
# Test citywide statistics (should return 1,488,452)
curl -s "http://localhost:8080/api/amisafe/citywide-stats" | jq .stats.total_incidents

# Test system statistics
curl -s "http://localhost:8080/api/amisafe/system-stats" | jq .data_statistics.total_crime_incidents

# Test Resolution 13 ultra-precision
curl -s "http://localhost:8080/api/amisafe/aggregated?resolution=13&limit=5" | jq .
```

## Professional Analytics Theme

### Design Philosophy
- **Clean Interface**: Professional styling with clear data presentation
- **Data-Focused**: Emphasizes readability and analytical insights
- **Accessible Design**: High contrast and readable fonts for all users
- **Modern Analytics**: Clean dashboards with intuitive navigation

### Color Palette
- **Primary Text**: Dark text on light backgrounds for readability
- **Accent Blue**: Professional blue for interactive elements
- **Warning Orange**: Clear warning indicators for attention
- **Critical Red**: Alert colors for urgent information
- **Background**: Clean white/light gray professional backgrounds

## License
This module follows the same licensing terms as the parent Drupal installation.

## Support
For technical issues, feature requests, or questions about the AmISafe interface, refer to the detailed interface documentation or check the module's controller and service files for implementation details.