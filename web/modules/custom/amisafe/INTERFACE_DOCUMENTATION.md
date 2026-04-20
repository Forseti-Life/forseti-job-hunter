# AmISafe Interface Documentation
## Crime Monitoring & Analytics - Complete Feature Guide

### 🏦️ **Overview**
The AmISafe module provides a comprehensive crime monitoring and spatial analysis interface. This document details every button, control, and feature available in the system.

---

## 🎯 **Main Interface Components**

### **📍 Page Header**
- **Title**: "CRIME MONITORING DASHBOARD" with professional styling
- **Subtitle**: "Real-time crime monitoring and spatial analysis"
- **Visual Effects**: Clean, professional interface with accessible colors

---

## 🎛️ **Control Panel (Left Side)**

### **🎯 SECTOR FILTERS Section**

#### **1. THREAT CATEGORIES (Crime Type Filter)**
- **Element**: Multi-select dropdown
- **ID**: `crime-type-selector` 
- **Behavior**: 
  - Dynamically populated from API endpoint `/api/amisafe/crime-types`
  - All crime types selected by default
  - Hold Ctrl/Cmd to select multiple types
  - Updates map data when changed (with 500ms debounce)
- **Expected Options**: Murder, Rape, Robbery, Assault, Burglary, Theft, Vandalism, Fraud, etc.
- **Visual**: Professional multi-select with clear borders

#### **2. DISTRICTS (Police District Filter)**
- **Element**: Multi-select dropdown
- **ID**: `district-selector`
- **Behavior**:
  - Dynamically populated from API endpoint `/api/amisafe/districts` 
  - All districts selected by default
  - Shows format: "DISTRICT XX"
  - Updates map data when changed
- **Expected Options**: Districts 01, 02, 03, 05, 07, 08, 09, 12, 14, 15, 16, 17
- **Visual**: Cyberpunk multi-select with neon borders

#### **3. THREAT SEVERITY (Severity Level Filter)**
- **Element**: Multi-select dropdown
- **ID**: `severity-selector`
- **Behavior**:
  - All levels selected by default
  - Filters incidents by severity rating
  - Real-time filtering when changed
- **Options**:
  - LEVEL 1 - LOW
  - LEVEL 2 - MODERATE  
  - LEVEL 3 - HIGH
  - LEVEL 4 - CRITICAL
  - LEVEL 5 - EXTREME
- **Visual**: Cyberpunk multi-select with severity color coding

#### **4. TIME PERIOD (Date Range Selector)**
- **Elements**: Two dropdown selectors
- **IDs**: `start-month`, `end-month`
- **Behavior**:
  - FROM MONTH: Starting month for data range
  - TO MONTH: Ending month for data range (default: December)
  - Filters data to specified date range
  - Auto-updates map when changed
- **Options**: All 12 months in cyberpunk styling
- **Visual**: Side-by-side month selectors in terminal green

#### **5. TIME OF DAY (Time Period Filter)**
- **Element**: Multi-select dropdown
- **ID**: `time-period-selector`
- **Behavior**:
  - All periods selected by default
  - Filters by time of incident occurrence
  - Real-time map updates
- **Options**:
  - EARLY MORNING (00:00-05:59)
  - MORNING (06:00-11:59) 
  - AFTERNOON (12:00-17:59)
  - EVENING (18:00-23:59)
- **Visual**: Multi-select with time-based color coding

### **⚡ QUICK PRESETS Section**

#### **Quick Filter Buttons**
- **Layout**: 2x2 grid of preset buttons
- **Behavior**: Instantly applies predefined filter combinations
- **Visual State**: Active preset highlighted with cyberpunk glow

##### **VIOLENT CRIMES Button**
- **ID**: `preset-violent`
- **Data Attribute**: `data-preset="violent"`
- **Behavior**:
  - Selects: Murder, Rape, Robbery, Aggravated Assault, Arson
  - Sets severity to levels 3, 4, 5 (HIGH, CRITICAL, EXTREME)
  - Clears other crime types
  - Immediately applies filter

##### **PROPERTY CRIMES Button**  
- **ID**: `preset-property`
- **Data Attribute**: `data-preset="property"`
- **Behavior**:
  - Selects: Burglary, Theft from Vehicle, All Other Larceny, Vandalism, Stolen Property
  - Sets severity to levels 1, 2, 3 (LOW, MODERATE, HIGH)
  - Clears other crime types
  - Immediately applies filter

##### **RECENT (30 DAYS) Button**
- **ID**: `preset-recent`  
- **Data Attribute**: `data-preset="recent"`
- **Behavior**:
  - Selects all crime types
  - Sets date range to last 3 months from current
  - Selects all severity levels
  - Immediately applies filter

##### **HIGH SEVERITY Button**
- **ID**: `preset-high-severity`
- **Data Attribute**: `data-preset="high-severity"`  
- **Behavior**:
  - Selects all crime types
  - Sets severity to levels 4, 5 (CRITICAL, EXTREME only)
  - Immediately applies filter

### **🔧 Filter Action Buttons**

#### **SCAN SECTORS Button (Primary Action)**
- **ID**: `apply-filters`
- **Class**: `cyber-button primary`
- **Behavior**:
  - Manually triggers filter application
  - Shows loading overlay with "APPLYING FILTERS..." 
  - Calls `loadHexagonData()` with current filter values
  - Updates statistics panel
- **Visual**: Large neon blue button with cyberpunk glow

#### **RESET ALL Button (Secondary Action)**  
- **ID**: `clear-filters`
- **Class**: `cyber-button secondary`
- **Behavior**:
  - Selects all options in all multi-select filters
  - Resets date range to full year (January - December)
  - Clears all preset button active states
  - Immediately reloads all data
- **Visual**: Secondary styled button with orange accent

### **👁️ DISPLAY MODE Section**

#### **View Mode Toggle Buttons**
- **Layout**: Three-button toggle group
- **Behavior**: Exclusive selection (only one active at a time)
- **Visual**: Active button highlighted with cyberpunk active state

##### **HEXAGON Button (Default Active)**
- **ID**: `hexagon-view`
- **Behavior**:
  - Shows H3 hexagonal sector analysis
  - Displays crime data aggregated into hexagonal cells
  - Color-coded by incident density
  - Default and primary view mode
- **Visual**: Active by default with neon blue glow

##### **HEATMAP Button**
- **ID**: `heatmap-view` 
- **Behavior**:
  - Switches to heatmap visualization mode
  - Shows crime density as color gradient overlay
  - Continuous color representation of data
- **Expected**: Currently switches view mode but heatmap rendering may need implementation

##### **POINTS Button**
- **ID**: `points-view`
- **Behavior**:
  - Switches to individual incident point display
  - Shows each crime as a discrete point on map  
  - Allows detailed incident-level analysis
- **Expected**: Currently switches view mode but point rendering may need implementation

---

## 📊 **Statistics Panel (Bottom of Control Panel)**

### **🌐 PHILADELPHIA 2085 - CITYWIDE STATUS**

#### **Citywide Overview Grid**
- **Layout**: 2x2 grid of citywide statistics
- **Update Behavior**: Refreshes when any data loads via `/api/amisafe/citywide-stats`
- **Fallback**: Shows simulated data if API unavailable

##### **TOTAL CITYWIDE Statistic**
- **ID**: `citywide-total`
- **Display**: Total incidents across entire Philadelphia
- **Color**: Neon cyan (`neon-cyan`)
- **Format**: Number with thousands separators
- **Special Effect**: Pulses red if > 10,000 incidents

##### **ALL DISTRICTS Statistic**
- **ID**: `citywide-districts`  
- **Display**: Number of active police districts
- **Color**: Neon purple (`neon-purple`)
- **Expected Range**: 20-22 districts

##### **CITYWIDE THREAT Statistic**
- **ID**: `citywide-threat`
- **Display**: Overall city threat level assessment
- **Color**: Neon red (`neon-red`)
- **Values**: MODERATE → ELEVATED → HIGH → CRITICAL → EXTREME
- **Special Effect**: Pulses with critical-pulse animation if CRITICAL/EXTREME

##### **COVERAGE Statistic**
- **ID**: `citywide-coverage`
- **Display**: Surveillance network coverage percentage  
- **Color**: Neon yellow (`neon-yellow`)
- **Format**: Percentage with 1 decimal place
- **Expected Range**: 85-100%

### **📍 CURRENT VIEW STATUS**

#### **View-Specific Statistics**
- **Update Behavior**: Updates when map view or filters change
- **Purpose**: Shows statistics for currently visible map area

##### **VISIBLE INCIDENTS Counter**
- **ID**: `total-incidents` 
- **Display**: Number of incidents visible in current map viewport
- **Color**: Neon green (`neon-green`)
- **Format**: Number with thousands separators
- **Behavior**: Updates with filtering and map navigation

##### **VIEW THREAT LEVEL Indicator**
- **ID**: `threat-level`
- **Display**: Calculated threat level for visible area
- **Color**: Neon orange (`neon-orange`) 
- **Values**: Same as citywide (MODERATE → EXTREME)
- **Special Effect**: Terminal typing effect animation when updating

##### **ACTIVE SECTORS Counter**
- **ID**: `active-sectors`
- **Display**: Number of H3 hexagonal sectors with incidents  
- **Color**: Neon blue (`neon-blue`)
- **Behavior**: Updates when hexagon data loads

---

## 🗺️ **Map Container (Right Side)**

### **Interactive Crime Map**
- **ID**: `crime-map-container`
- **Technology**: Leaflet.js with H3 geospatial library
- **Base Map**: Dark cyberpunk tile layer
- **Zoom Range**: 8-20 (supports extreme detail down to 1-meter precision)

### **🎯 Map Controls (Floating Buttons)**

#### **Fullscreen Button**
- **ID**: `fullscreen-btn`
- **Icon**: Expand icon (fa-expand)
- **Behavior**: 
  - Toggles map to fullscreen mode
  - Hides control panel when active
  - Provides immersive map experience
- **Visual**: Floating control button with cyberpunk styling

#### **Reset View Button**  
- **ID**: `reset-view-btn`
- **Icon**: Home icon (fa-home)
- **Behavior**:
  - Returns map to default Philadelphia center view
  - Resets zoom to initial level
  - Cancels any custom pan/zoom positions
- **Default Position**: Philadelphia center coordinates

#### **Screenshot Button**
- **ID**: `screenshot-btn` 
- **Icon**: Camera icon (fa-camera)
- **Behavior**:
  - Captures current map view as image
  - Downloads screenshot file
  - Includes current data visualization state
- **Visual**: Floating control button with camera styling

### **🔷 Interactive Map Elements**

#### **H3 Hexagonal Sectors**
- **Technology**: H3 geospatial indexing system
- **Behavior**:
  - Click to show detailed sector information
  - Color-coded by incident density (green → red scale)
  - Hover effects with cyberpunk glow
  - Popup modal with detailed statistics
- **Color Scale**: 
  - Low incidents: Green tones
  - Medium incidents: Yellow/Orange tones  
  - High incidents: Red tones
- **Resolution**: Dynamically adjusts based on zoom level (8-15)

#### **Loading States**
- **Loading Overlay**: Shows during data fetching
- **Messages**: 
  - "INITIALIZING NEURAL MAP"
  - "LOADING CRIME DATA" 
  - "APPLYING FILTERS"
- **Visual**: Terminal-style loading with animated dots

---

## 📈 **Analytics Dashboard (Expandable)**

### **Analytics Toggle**
- **ID**: `toggle-analytics`
- **Behavior**: 
  - Expands/collapses additional analytics section
  - Changes text between "SHOW ANALYTICS" / "HIDE ANALYTICS"
  - Animated chevron icon rotation
- **Default State**: Collapsed

### **Analytics Content** 
- **ID**: `analytics-dashboard`
- **Content**: Charts and additional data visualizations
- **State**: Initially collapsed, expandable via toggle
- **Charts**: Crime trend analysis, temporal patterns

---

## ⌨️ **Keyboard & Mouse Interactions**

### **Map Navigation**
- **Mouse Wheel**: Zoom in/out
- **Click + Drag**: Pan map view  
- **Double Click**: Zoom in to clicked location
- **Shift + Drag**: Box zoom to selected area

### **Filter Interactions**
- **Ctrl/Cmd + Click**: Multi-select in dropdowns
- **Tab Navigation**: Navigate through form elements
- **Enter**: Apply current filters (same as SCAN SECTORS button)
- **Escape**: Close any open modals or overlays

---

## 🔄 **Automatic Behaviors**

### **Real-time Updates**
- **Debounced Filtering**: 500ms delay after filter changes before applying
- **Auto-refresh**: Map updates when viewport changes significantly
- **Cache Management**: Intelligent caching of API responses for performance
- **Auto-fit**: Map automatically pans to show hexagons when they're out of view

### **Performance Optimizations**
- **Request Cancellation**: Cancels previous API calls when new ones are made  
- **Viewport Detection**: Only loads data for visible map areas
- **Resolution Scaling**: Uses appropriate H3 resolution based on zoom level
- **Cache Cleanup**: Automatic cleanup of old cached data

### **Error Handling**
- **API Fallbacks**: Shows sample/fallback data when APIs are unavailable
- **Graceful Degradation**: Interface remains functional even with missing data
- **Error Messages**: User-friendly error notifications in cyberpunk style
- **Recovery**: Automatic retry mechanisms for failed requests

---

## 🎨 **Visual States & Animations**

### **Button States**
- **Default**: Cyberpunk styling with neon borders
- **Hover**: Increased glow and brightness
- **Active**: Full neon glow with enhanced border
- **Disabled**: Dimmed appearance with reduced opacity

### **Loading States**
- **Map Loading**: Neural map initialization animation
- **Filter Loading**: "APPLYING FILTERS" overlay with terminal dots
- **Data Loading**: Hexagon rendering progress indicators

### **Alert States**
- **High Alert**: Red pulsing animation for critical statistics  
- **Threat Indicators**: Color-coded threat levels across all elements
- **Critical Pulse**: Special animation for extreme values

---

## 📱 **Responsive Design**

### **Desktop (Primary)**
- **Layout**: Side-by-side control panel and map
- **Optimized For**: Large screens with full feature access
- **Controls**: All buttons and interactions fully available

### **Tablet & Mobile**  
- **Layout**: Adaptive layout with collapsible panels
- **Touch Optimized**: Larger touch targets for mobile devices
- **Simplified**: Some advanced features may be condensed

---

## 🔧 **Developer Integration**

### **API Endpoints Used**
- `/api/amisafe/aggregated` - H3 hexagon data with crime statistics
- `/api/amisafe/crime-types` - Available crime type categories
- `/api/amisafe/districts` - Police district list
- `/api/amisafe/citywide-stats` - Overall city statistics
- `/api/amisafe/incidents` - Raw incident data
- `/api/amisafe/hotspots` - Crime hotspot analysis

### **JavaScript Events**
- **Map Events**: `zoomend`, `moveend` for map state tracking  
- **Filter Events**: `change` events on all filter controls
- **Button Events**: `click` events for all interactive elements
- **Custom Events**: Filter updates, data loading completion

### **CSS Classes**
- **Core**: `.amisafe-crime-map-page`, `.control-panel`, `.map-container`
- **Interactive**: `.cyber-button`, `.cyber-select`, `.cyber-select-multi`
- **Visual**: `.neon-*` classes for different neon colors
- **States**: `.active`, `.high-alert`, `.loading`

---

## 🚀 **Expected User Workflows**

### **Basic Crime Analysis**
1. User opens `/amisafe/crime-map`
2. Map loads with all data visible (hexagon view)
3. User can immediately see citywide and current view statistics
4. User applies filters to focus on specific crime types or areas
5. Map updates automatically with filtered data

### **Focused Investigation**
1. User clicks a preset button (e.g., "VIOLENT CRIMES")
2. Map filters to show only violent crime hexagons
3. User zooms into high-density areas for detail
4. User clicks individual hexagons for detailed statistics
5. User can screenshot or fullscreen for presentation

### **Temporal Analysis**
1. User adjusts date range selectors for specific time periods
2. User modifies time-of-day filters for pattern analysis  
3. Map updates to show filtered temporal data
4. User can compare different time periods by changing filters

### **District-Focused Analysis**
1. User selects specific districts from district filter
2. Map shows only selected districts' data
3. User can compare threat levels across districts
4. User applies additional filters for refined analysis

This interface provides a comprehensive, cyberpunk-themed crime analysis platform with professional-grade functionality while maintaining an immersive Philadelphia 2085 aesthetic.