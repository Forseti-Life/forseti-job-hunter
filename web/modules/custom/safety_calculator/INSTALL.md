# Safety Calculator - Installation & Testing Guide

**Date**: January 12, 2026  
**Status**: ✅ Phase 1 Complete - Ready for Testing

---

## What We Built

### ✅ Core Components

1. **SafetyScore Entity** (`src/Entity/SafetyScore.php`)
   - Content entity for storing calculated safety scores
   - Complete field definitions
   - Helper methods for score retrieval

2. **Landing Page** (`/safety-calculator`)
   - Controller: `src/Controller/LandingPageController.php`
   - Three user type cards (Individual, Family, Institution)
   - Features overview
   - How it works section
   - Call-to-action sections

3. **Individual Check Form** (`/safety-calculator/check`)
   - Form: `src/Form/IndividualCheckForm.php`
   - Address or coordinate input
   - Geolocation "Use Current Location" button
   - Advanced options (resolution, radius, time filter)
   - Live results display with score visualization

4. **Services** (existing)
   - SafetyCalculatorService - score calculation
   - API endpoint - `/api/safety/calculate`

5. **Styling & Interactivity**
   - CSS: `css/landing-page.css`, `css/individual-check.css`
   - JavaScript: `js/landing-page.js`, `js/individual-check.js`
   - Responsive design
   - Smooth animations

6. **Configuration**
   - Routing: 4 routes defined
   - Permissions: 3 permissions created
   - Libraries: 2 asset libraries defined

---

## Installation Steps

### On Development Environment

```bash
cd /home/keithaumiller/forseti.life/sites/forseti

# Module files are already in place
# No need to copy - they're in web/modules/custom/safety_calculator/

# Install the module (if not already enabled)
vendor/bin/drush en safety_calculator -y

# Clear cache
vendor/bin/drush cr

# Update entity schema (create safety_score table)
vendor/bin/drush updb -y

# Check status
vendor/bin/drush pml --status=enabled | grep safety
```

### On Production Environment

```bash
cd /var/www/html/forseti

# Enable module
vendor/bin/drush en safety_calculator -y

# Update database schema
vendor/bin/drush updb -y

# Clear cache
vendor/bin/drush cr

# Verify installation
vendor/bin/drush pml --status=enabled | grep safety_calculator
```

---

## Testing Checklist

### 1. Landing Page Test

**URL**: `https://forseti.life/safety-calculator`

- [ ] Page loads without errors
- [ ] Hero section displays
- [ ] Three user type cards visible (Individual, Family, Institution)
- [ ] Features section shows data statistics
- [ ] "How It Works" section displays
- [ ] CTA button links to individual check page
- [ ] CSS styles applied correctly
- [ ] Responsive on mobile/tablet

### 2. Individual Check Form Test

**URL**: `https://forseti.life/safety-calculator/check`

- [ ] Form loads successfully
- [ ] Address input field visible
- [ ] "Or use coordinates" expandable section works
- [ ] "Use My Current Location" button present
- [ ] Advanced options expandable
- [ ] Resolution selector has options (11-14)
- [ ] Radius selector has options (0-3)
- [ ] Time filter selector present

### 3. Geolocation Test

- [ ] Click "Use My Current Location" button
- [ ] Browser asks for location permission
- [ ] After approval, coordinates fill in lat/lon fields
- [ ] Coordinates details section opens automatically
- [ ] Button shows success message briefly

### 4. Safety Score Calculation Test

**Test with Philadelphia coordinates**:
- Latitude: `39.9526`
- Longitude: `-75.1652`

Steps:
1. Enter coordinates
2. Click "Calculate Safety Score"
3. Verify:
   - [ ] Loading state appears
   - [ ] Results section appears
   - [ ] Safety score displays (0-100)
   - [ ] Risk level shows with color
   - [ ] Crime count displayed
   - [ ] Hexagons analyzed count shown
   - [ ] Crime breakdown by type visible
   - [ ] Correct color coding (green/yellow/orange/red)

### 5. API Endpoint Test

```bash
# Test API directly
curl "https://forseti.life/api/safety/calculate?lat=39.9526&lon=-75.1652" | jq

# Expected response:
{
  "status": "success",
  "data": {
    "score": 78.5,
    "risk_level": "low",
    "crime_count": 12,
    "hexagon": "...",
    "hexagons_analyzed": 7,
    "details": {...},
    "timestamp": 1705081234
  }
}
```

### 6. Permissions Test

- [ ] Anonymous users can access landing page
- [ ] Anonymous users can access individual check
- [ ] Anonymous users can use calculator
- [ ] Admin can access settings page (`/admin/config/forseti/safety-calculator`)

### 7. Database Test

```bash
# Verify safety_score table created
vendor/bin/drush sqlq "SHOW TABLES LIKE 'safety_score'"

# Check table structure
vendor/bin/drush sqlq "DESCRIBE safety_score"

# After a calculation, verify data saved
vendor/bin/drush sqlq "SELECT id, score, risk_level, crime_count FROM safety_score LIMIT 5"
```

---

## Known Limitations (Phase 1)

1. **Address Geocoding**: Not yet implemented
   - Users must use coordinates directly
   - Will add Google Maps API or similar in Phase 2

2. **Map Visualization**: Not included yet
   - Planned for Phase 2
   - Will show hexagon overlay on map

3. **Save Functionality**: Not available
   - Users can't save locations yet
   - Coming in Phase 2 with user profiles

4. **Family/Institution Features**: Coming in future phases

---

## Troubleshooting

### Module Won't Enable

```bash
# Check for PHP errors
vendor/bin/drush watchdog:show --severity=Error --count=20

# Verify file permissions
ls -la web/modules/custom/safety_calculator/

# Check module info file
cat web/modules/custom/safety_calculator/safety_calculator.info.yml
```

### Database Schema Not Created

```bash
# Force schema update
vendor/bin/drush entity:updates -y

# Or manually run update
vendor/bin/drush updb -y
```

### Styles Not Loading

```bash
# Clear cache
vendor/bin/drush cr

# Rebuild asset aggregates
vendor/bin/drush advagg:clear-all-caches

# Check library file
cat web/modules/custom/safety_calculator/safety_calculator.libraries.yml
```

### Calculation Returns Error

1. Check AmISafe module is enabled:
   ```bash
   vendor/bin/drush pml | grep amisafe
   ```

2. Verify database has crime data:
   ```bash
   vendor/bin/drush sqlq "SELECT COUNT(*) FROM amisafe_h3_aggregated"
   ```

3. Check error logs:
   ```bash
   vendor/bin/drush watchdog:show --type=safety_calculator
   ```

---

## Next Steps (Phase 2)

1. **Address Geocoding Integration**
   - Add Google Maps Geocoding API
   - Auto-complete address suggestions

2. **Map Visualization**
   - Leaflet.js integration
   - H3 hexagon overlay
   - Crime incident markers

3. **User Profiles**
   - Save favorite locations
   - History of past checks
   - Alert preferences

4. **Enhanced Results**
   - Trend analysis graphs
   - Nearby alternative suggestions
   - Share results feature

---

## File Structure

```
safety_calculator/
├── safety_calculator.info.yml
├── safety_calculator.module
├── safety_calculator.routing.yml
├── safety_calculator.services.yml
├── safety_calculator.libraries.yml
├── safety_calculator.permissions.yml
├── README.md
├── PLANNING.md
├── INSTALL.md (this file)
├── css/
│   ├── landing-page.css
│   └── individual-check.css
├── js/
│   ├── landing-page.js
│   └── individual-check.js
└── src/
    ├── Entity/
    │   └── SafetyScore.php
    ├── Controller/
    │   ├── LandingPageController.php
    │   └── SafetyCalculatorController.php
    ├── Form/
    │   ├── IndividualCheckForm.php
    │   └── SafetyCalculatorSettingsForm.php
    └── SafetyCalculatorService.php
```

---

## Support

If you encounter issues:

1. Check the troubleshooting section above
2. Review Drupal watchdog logs
3. Verify AmISafe module is functioning
4. Check database has crime data populated

---

**Installation Status**: ✅ Ready for Testing  
**Last Updated**: 2026-01-12
