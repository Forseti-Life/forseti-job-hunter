# Safety Calculator Module - Planning Document

**Date**: January 12, 2026  
**Status**: 🟡 Planning Phase  
**Purpose**: Define pages, entities, and data structures for the Safety Calculator module

---

## Module Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                   Safety Calculator Module                   │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Pages/UI          Entities            Services             │
│  ─────────         ────────            ────────             │
│  • Landing         • SafetyScore       • Calculator         │
│  • Individual      • UserProfile       • RiskAnalyzer       │
│  • Family          • FamilyGroup       • ReportGenerator    │
│  • Institution     • Institution       • AlertManager       │
│  • Reports         • Location          • CacheManager       │
│  • API Docs        • SafetyAlert                            │
│                    • SafetyReport                           │
└─────────────────────────────────────────────────────────────┘
```

---

## 📄 Pages Required

### 1. Public Pages (Anonymous/Authenticated Users)

#### 1.1 Landing Page
**Path**: `/safety-calculator`  
**Route**: `safety_calculator.landing`  
**Purpose**: Introduce the module and direct users to appropriate tool

**Content**:
- Overview of Safety Calculator
- Three user type cards (Individual, Family, Institution)
- Quick start guide
- Sample safety score visualization
- Call-to-action buttons

**Components**:
- Hero section with value proposition
- User type selector cards
- Live demo/sample calculation
- Trust indicators (data sources, methodology)
- FAQ section

---

#### 1.2 Individual Safety Check
**Path**: `/safety-calculator/check`  
**Route**: `safety_calculator.individual_check`  
**Purpose**: Quick safety score for a single location

**Form Elements**:
- Location input (address or coordinates)
- Current location button (geolocation)
- Time of day selector (optional)
- Calculate button

**Results Display**:
- Large safety score (0-100)
- Risk level badge
- Crime breakdown chart
- Map with hexagon visualization
- Nearby alternatives suggestions
- Share/save results

**Access**: Anonymous + Authenticated users

---

#### 1.3 Route Safety Analyzer
**Path**: `/safety-calculator/route`  
**Route**: `safety_calculator.route_check`  
**Purpose**: Analyze safety along a route (future phase)

**Form Elements**:
- Start location
- End location
- Add waypoints
- Mode of transport
- Departure time

**Results Display**:
- Overall route safety score
- Map with color-coded segments
- Risk hotspots highlighted
- Alternative route suggestions
- Estimated travel time by safety level

**Access**: Authenticated users

---

### 2. Family Dashboard Pages

#### 2.1 Family Dashboard Home
**Path**: `/safety-calculator/family`  
**Route**: `safety_calculator.family_dashboard`  
**Purpose**: Central hub for family safety management

**Sections**:
- Family members list with status
- Saved locations (home, school, work)
- Recent safety checks
- Active alerts
- Quick check widget

**Access**: Authenticated users (family account)

---

#### 2.2 Family Settings
**Path**: `/safety-calculator/family/settings`  
**Route**: `safety_calculator.family_settings`  
**Purpose**: Manage family members and locations

**Form Elements**:
- Add/remove family members
- Set member roles (adult, child)
- Add saved locations
- Set alert thresholds
- Notification preferences

**Access**: Family account owner/admin

---

#### 2.3 Family History
**Path**: `/safety-calculator/family/history`  
**Route**: `safety_calculator.family_history`  
**Purpose**: View past safety checks and patterns

**Display**:
- Timeline of safety checks
- Location history
- Trend charts
- Exported reports

**Access**: Family account members

---

### 3. Institutional Pages

#### 3.1 Institution Dashboard
**Path**: `/safety-calculator/institution`  
**Route**: `safety_calculator.institution_dashboard`  
**Purpose**: Organization-wide safety management

**Sections**:
- Facility locations overview
- Employee safety status
- Risk heatmap
- Compliance reports
- Analytics summary

**Access**: Institutional account users

---

#### 3.2 Facility Management
**Path**: `/safety-calculator/institution/facilities`  
**Route**: `safety_calculator.institution_facilities`  
**Purpose**: Manage organization facilities

**Features**:
- List all facilities
- Add/edit facility locations
- Set risk thresholds per facility
- Assign responsible staff
- View facility-specific reports

**Access**: Institution admins

---

#### 3.3 Employee Safety
**Path**: `/safety-calculator/institution/employees`  
**Route**: `safety_calculator.institution_employees`  
**Purpose**: Monitor employee location safety

**Features**:
- Employee location tracking (opt-in)
- Commute route analysis
- Alert management
- Safety notifications
- Incident reporting

**Access**: Institution safety managers

---

#### 3.4 Reports & Analytics
**Path**: `/safety-calculator/institution/reports`  
**Route**: `safety_calculator.institution_reports`  
**Purpose**: Generate compliance and analytics reports

**Reports Available**:
- Monthly safety summary
- Risk assessment by location
- Trend analysis
- Compliance documentation
- Executive dashboard
- Export to PDF/CSV

**Access**: Institution admins/managers

---

#### 3.5 Bulk Location Analysis
**Path**: `/safety-calculator/institution/bulk-check`  
**Route**: `safety_calculator.institution_bulk`  
**Purpose**: Analyze multiple locations at once

**Features**:
- CSV upload for bulk locations
- Batch processing
- Results table with sorting/filtering
- Export results
- Heatmap visualization

**Access**: Institution users

---

### 4. Administrative Pages

#### 4.1 Module Configuration
**Path**: `/admin/config/forseti/safety-calculator`  
**Route**: `safety_calculator.settings`  
**Purpose**: Configure module settings

**Settings**:
- Default H3 resolution
- Default search radius
- Cache TTL
- Base safety score
- Crime severity weights
- API rate limits

**Access**: Site administrators

---

#### 4.2 User Management
**Path**: `/admin/forseti/safety-calculator/users`  
**Route**: `safety_calculator.admin_users`  
**Purpose**: Manage family and institutional accounts

**Features**:
- List all accounts
- Account type filtering
- Usage statistics
- Subscription management
- Bulk operations

**Access**: Site administrators

---

#### 4.3 API Documentation
**Path**: `/safety-calculator/api`  
**Route**: `safety_calculator.api_docs`  
**Purpose**: Developer documentation for API

**Content**:
- API endpoints list
- Request/response examples
- Authentication guide
- Rate limiting info
- Code samples (PHP, JavaScript, cURL)

**Access**: Public (static content)

---

## 🗃️ Data Entities Required

### 1. Safety Score Entity

**Entity Type**: `safety_score`  
**Bundle**: N/A (single entity type)  
**Purpose**: Store calculated safety scores

**Fields**:
```yaml
id:
  type: serial
  description: Unique identifier

uuid:
  type: uuid
  description: Universal unique identifier

hexagon_id:
  type: string
  length: 255
  description: H3 hexagon identifier
  indexed: true

latitude:
  type: decimal
  precision: 10
  scale: 7
  description: Latitude coordinate

longitude:
  type: decimal
  precision: 10
  scale: 7
  description: Longitude coordinate

resolution:
  type: integer
  description: H3 resolution level
  default: 13

score:
  type: decimal
  precision: 5
  scale: 2
  description: Safety score (0-100)
  indexed: true

risk_level:
  type: string
  length: 20
  description: Risk category (low, moderate, high, critical)
  indexed: true

crime_count:
  type: integer
  description: Total crimes in analysis area

hexagons_analyzed:
  type: integer
  description: Number of hexagons included

crime_details:
  type: text_long
  description: JSON crime breakdown by type
  serialize: json

calculation_options:
  type: text_long
  description: JSON calculation parameters
  serialize: json

calculated_at:
  type: timestamp
  description: Calculation timestamp
  indexed: true

expires_at:
  type: timestamp
  description: Cache expiration time
  indexed: true

user_id:
  type: entity_reference
  target: user
  description: User who requested calculation (optional)

account_type:
  type: string
  length: 50
  description: Account type (individual, family, institution)

account_id:
  type: integer
  description: Related family or institution ID
  indexed: true
```

---

### 2. User Profile Extension

**Entity Type**: `user`  
**Bundle**: N/A (extend existing user entity)  
**Purpose**: Store user-specific safety preferences

**Additional Fields**:
```yaml
field_safety_home_location:
  type: geofield
  description: Home location coordinates

field_safety_work_location:
  type: geofield
  description: Work location coordinates

field_safety_alert_threshold:
  type: list_integer
  options: [20, 40, 60, 80]
  default: 60
  description: Alert if score below this value

field_safety_notification_method:
  type: list_string
  options: [email, sms, push, none]
  multiple: true
  description: How to receive alerts

field_safety_account_type:
  type: list_string
  options: [individual, family, institution]
  default: individual
  description: Account type

field_safety_family_id:
  type: entity_reference
  target: family_group
  description: Associated family group

field_safety_institution_id:
  type: entity_reference
  target: institution
  description: Associated institution
```

---

### 3. Family Group Entity

**Entity Type**: `family_group`  
**Bundle**: N/A
**Purpose**: Manage family accounts

**Fields**:
```yaml
id:
  type: serial
  description: Unique identifier

uuid:
  type: uuid

name:
  type: string
  length: 255
  description: Family group name
  required: true

owner_uid:
  type: entity_reference
  target: user
  description: Family account owner

members:
  type: entity_reference
  target: user
  multiple: true
  description: Family members

saved_locations:
  type: text_long
  serialize: json
  description: |
    JSON array of saved locations:
    - name (home, school, work, etc.)
    - latitude
    - longitude
    - address
    - default_radius

alert_settings:
  type: text_long
  serialize: json
  description: Family-wide alert configuration

created:
  type: timestamp
  description: Creation timestamp

updated:
  type: timestamp
  description: Last update timestamp

status:
  type: boolean
  default: true
  description: Active status
```

---

### 4. Institution Entity

**Entity Type**: `institution`  
**Bundle**: N/A
**Purpose**: Manage institutional accounts

**Fields**:
```yaml
id:
  type: serial

uuid:
  type: uuid

name:
  type: string
  length: 255
  description: Institution name
  required: true

organization_type:
  type: list_string
  options: [business, school, hospital, government, nonprofit]
  description: Type of organization

account_owner:
  type: entity_reference
  target: user
  description: Primary account administrator

administrators:
  type: entity_reference
  target: user
  multiple: true
  description: Users with admin access

managers:
  type: entity_reference
  target: user
  multiple: true
  description: Users with manager access

employees:
  type: entity_reference
  target: user
  multiple: true
  description: Standard users

subscription_tier:
  type: list_string
  options: [basic, professional, enterprise]
  default: basic
  description: Subscription level

facilities:
  type: entity_reference
  target: facility_location
  multiple: true
  description: Associated facility locations

alert_thresholds:
  type: text_long
  serialize: json
  description: Institution-wide alert configuration

api_key:
  type: string
  length: 64
  description: API access key (hashed)

api_quota:
  type: integer
  default: 1000
  description: Monthly API request limit

api_usage:
  type: integer
  default: 0
  description: Current month API usage

created:
  type: timestamp

updated:
  type: timestamp

status:
  type: boolean
  default: true
```

---

### 5. Facility Location Entity

**Entity Type**: `facility_location`  
**Bundle**: N/A
**Purpose**: Store institution facility locations

**Fields**:
```yaml
id:
  type: serial

uuid:
  type: uuid

institution_id:
  type: entity_reference
  target: institution
  description: Parent institution
  required: true

name:
  type: string
  length: 255
  description: Facility name

address:
  type: string_long
  description: Full street address

location:
  type: geofield
  description: Coordinates

facility_type:
  type: list_string
  options: [office, warehouse, retail, factory, remote]
  description: Facility category

employee_count:
  type: integer
  description: Number of employees at location

operating_hours:
  type: text_long
  serialize: json
  description: |
    Operating schedule:
    - day_of_week
    - open_time
    - close_time

risk_threshold:
  type: integer
  default: 60
  description: Custom safety score threshold

responsible_manager:
  type: entity_reference
  target: user
  description: Safety manager for this facility

last_safety_check:
  type: timestamp
  description: Last safety score calculation

current_safety_score:
  type: decimal
  precision: 5
  scale: 2
  description: Most recent safety score

created:
  type: timestamp

updated:
  type: timestamp

status:
  type: boolean
  default: true
```

---

### 6. Saved Location Entity

**Entity Type**: `saved_location`  
**Bundle**: N/A
**Purpose**: Store user-saved locations

**Fields**:
```yaml
id:
  type: serial

uuid:
  type: uuid

user_id:
  type: entity_reference
  target: user
  description: Location owner

account_type:
  type: string
  length: 50
  description: individual, family, or institution

account_id:
  type: integer
  description: Family or institution ID if applicable

name:
  type: string
  length: 255
  description: Location nickname (Home, School, Work, etc.)

address:
  type: string_long
  description: Street address

location:
  type: geofield
  description: Coordinates

default_radius:
  type: integer
  default: 1
  description: Default analysis radius

alert_enabled:
  type: boolean
  default: false
  description: Enable alerts for this location

alert_threshold:
  type: integer
  default: 60
  description: Alert if score below this

last_checked:
  type: timestamp
  description: Last safety check timestamp

last_score:
  type: decimal
  precision: 5
  scale: 2
  description: Most recent safety score

check_frequency:
  type: list_string
  options: [manual, daily, weekly, monthly]
  default: manual
  description: Automatic check schedule

created:
  type: timestamp

status:
  type: boolean
  default: true
```

---

### 7. Safety Alert Entity

**Entity Type**: `safety_alert`  
**Bundle**: N/A
**Purpose**: Track and manage safety alerts

**Fields**:
```yaml
id:
  type: serial

uuid:
  type: uuid

alert_type:
  type: list_string
  options: [score_drop, threshold_breach, new_incident, trend_negative]
  description: Type of alert
  required: true

severity:
  type: list_string
  options: [info, warning, urgent, critical]
  default: warning
  description: Alert severity level

user_id:
  type: entity_reference
  target: user
  description: Alert recipient

account_type:
  type: string
  length: 50
  description: individual, family, institution

account_id:
  type: integer
  description: Related family/institution ID

location_name:
  type: string
  length: 255
  description: Location that triggered alert

latitude:
  type: decimal
  precision: 10
  scale: 7

longitude:
  type: decimal
  precision: 10
  scale: 7

hexagon_id:
  type: string
  length: 255

safety_score:
  type: decimal
  precision: 5
  scale: 2
  description: Current safety score

previous_score:
  type: decimal
  precision: 5
  scale: 2
  description: Previous score (for comparison)

message:
  type: text_long
  description: Alert message text

details:
  type: text_long
  serialize: json
  description: Additional alert data

sent_at:
  type: timestamp
  description: When alert was sent

read_at:
  type: timestamp
  nullable: true
  description: When user viewed alert

acknowledged_at:
  type: timestamp
  nullable: true
  description: When user acknowledged alert

delivery_method:
  type: list_string
  options: [email, sms, push, web]
  multiple: true
  description: How alert was delivered

status:
  type: list_string
  options: [pending, sent, read, acknowledged, dismissed]
  default: pending
```

---

### 8. Safety Report Entity

**Entity Type**: `safety_report`  
**Bundle**: N/A
**Purpose**: Store generated reports (primarily for institutions)

**Fields**:
```yaml
id:
  type: serial

uuid:
  type: uuid

report_type:
  type: list_string
  options: [daily, weekly, monthly, quarterly, annual, custom, compliance]
  description: Report type
  required: true

account_type:
  type: string
  length: 50
  description: family or institution

account_id:
  type: integer
  description: Family or institution ID
  required: true

title:
  type: string
  length: 255
  description: Report title

date_from:
  type: date
  description: Report start date

date_to:
  type: date
  description: Report end date

report_data:
  type: text_long
  serialize: json
  description: |
    Complete report data:
    - locations_analyzed
    - average_safety_score
    - risk_distribution
    - alerts_triggered
    - trend_analysis
    - recommendations

generated_by:
  type: entity_reference
  target: user
  description: User who generated report

generated_at:
  type: timestamp
  description: Generation timestamp

file_url:
  type: string
  length: 255
  description: URL to PDF/CSV export

format:
  type: list_string
  options: [html, pdf, csv, json]
  multiple: true
  description: Available formats

status:
  type: list_string
  options: [generating, completed, failed, archived]
  default: generating

views_count:
  type: integer
  default: 0
  description: Number of times viewed

last_viewed:
  type: timestamp
  nullable: true
  description: Last view timestamp
```

---

## 🔄 Data Relationships

```
User
  ├─→ UserProfile (1:1)
  ├─→ FamilyGroup (1:1 as owner, M:1 as member)
  ├─→ Institution (1:1 as owner, M:1 as admin/manager/employee)
  ├─→ SavedLocation (1:M)
  ├─→ SafetyAlert (1:M)
  └─→ SafetyReport (1:M as generator)

FamilyGroup
  ├─→ User (M:M members)
  ├─→ SavedLocation (1:M)
  ├─→ SafetyAlert (1:M)
  └─→ SafetyReport (1:M)

Institution
  ├─→ User (M:M admins, managers, employees)
  ├─→ FacilityLocation (1:M)
  ├─→ SafetyAlert (1:M)
  └─→ SafetyReport (1:M)

FacilityLocation
  ├─→ Institution (M:1)
  └─→ User (M:1 responsible manager)

SavedLocation
  ├─→ User (M:1)
  └─→ FamilyGroup (M:1, optional)

SafetyScore
  ├─→ User (M:1, optional)
  └─→ Can relate to FamilyGroup or Institution via account_id

SafetyAlert
  ├─→ User (M:1)
  └─→ FamilyGroup or Institution (M:1)

SafetyReport
  ├─→ User (M:1 generator)
  └─→ FamilyGroup or Institution (M:1)
```

---

## 📊 Database Tables Summary

Total new tables: **8**

1. `safety_score` - Calculated safety scores (high volume)
2. `family_group` - Family account management
3. `institution` - Institutional accounts
4. `facility_location` - Institution facilities
5. `saved_location` - User-saved locations
6. `safety_alert` - Alert history and tracking
7. `safety_report` - Generated reports
8. User table extensions (via field API)

**Estimated Storage**:
- safety_score: ~1-5GB (with TTL cleanup)
- Other tables: ~100-500MB combined

---

## 🎯 Implementation Phases

### Phase 1: Core Individual (Current)
- ✅ SafetyCalculatorService
- ✅ SafetyScore entity
- ✅ Individual check page
- ✅ Basic API endpoint
- ✅ Landing page

### Phase 2: User Management
- [ ] UserProfile extensions
- [ ] SavedLocation entity
- [ ] User dashboard
- [ ] Alert system basic

### Phase 3: Family Features
- [ ] FamilyGroup entity
- [ ] Family dashboard
- [ ] Family settings
- [ ] Multi-location monitoring

### Phase 4: Institutional Features
- [ ] Institution entity
- [ ] FacilityLocation entity
- [ ] Institution dashboard
- [ ] Bulk analysis
- [ ] Report generation

### Phase 5: Advanced Features
- [ ] Route analysis
- [ ] Trend analysis
- [ ] ML predictions
- [ ] Mobile app integration

---

## 🔐 Permissions Structure

```yaml
# Module permissions
permissions:
  access safety calculator:
    title: 'Access Safety Calculator'
    description: 'View and use basic safety calculator features'
    
  use individual calculator:
    title: 'Use Individual Calculator'
    description: 'Calculate safety scores for individual use'
    
  manage family account:
    title: 'Manage Family Account'
    description: 'Administer family group and members'
    
  view family dashboard:
    title: 'View Family Dashboard'
    description: 'Access family safety dashboard'
    
  manage institution account:
    title: 'Manage Institution Account'
    description: 'Administer institution settings and users'
    
  view institution dashboard:
    title: 'View Institution Dashboard'
    description: 'Access institutional dashboard and reports'
    
  generate safety reports:
    title: 'Generate Safety Reports'
    description: 'Create and export safety reports'
    
  access safety calculator api:
    title: 'Access API'
    description: 'Use REST API endpoints'
    
  administer safety calculator:
    title: 'Administer Safety Calculator'
    description: 'Configure module settings'
```

---

## 📋 Next Steps

1. **Review and approve** this planning document
2. **Prioritize features** for initial release
3. **Create entities** starting with Phase 1
4. **Build landing page** and individual calculator
5. **Test and iterate** before moving to family/institutional features

---

**Document Status**: 🟡 Draft - Awaiting Review  
**Last Updated**: 2026-01-12
