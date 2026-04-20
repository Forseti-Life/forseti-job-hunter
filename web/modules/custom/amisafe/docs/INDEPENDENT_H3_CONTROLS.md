# Independent H3 Resolution Controls

## Overview
The crime map now supports independent control of H3 hexagon resolution, decoupled from the map zoom level. This allows users to manually adjust the geographic granularity of crime data visualization regardless of how zoomed in or out the map is.

## Features

### Manual Resolution Control
- **Range**: H3 resolutions 5-13
- **Controls**: +/- buttons flanking the H3 resolution display
- **Mode**: Can be auto (zoom-based) or manual (user-controlled)

### Resolution Levels
| Resolution | Hexagon Size | Description |
|------------|--------------|-------------|
| 5 | ~251 km² | Metro districts |
| 6 | ~36 km² | City areas |
| 7 | ~5.2 km² | Neighborhoods |
| 8 | ~0.7 km² | Block groups |
| 9 | ~0.1 km² | Street blocks |
| 10 | ~15,047 m² | Building groups |
| 11 | ~2,150 m² | Buildings |
| 12 | ~307 m² | Rooms |
| 13 | ~44 m² | Ultra-precision |

## User Interface

### Controls Location
The resolution controls are located in the top-right corner of the map:

```
ZOOM: 12  H3: [-] 6 [+] ~36km² city areas
```

- **ZOOM**: Current map zoom level (read-only)
- **H3**: Current H3 resolution
- **[−]**: Decrease resolution button (larger hexagons)
- **[+]**: Increase resolution button (smaller hexagons)
- **Scale description**: Human-readable size of current hexagons

### Button Behavior
- **Decrease (−)**: 
  - Decreases H3 resolution by 1 (larger hexagons)
  - Minimum: Resolution 5
  - Disabled when at minimum
  
- **Increase (+)**:
  - Increases H3 resolution by 1 (smaller hexagons)
  - Maximum: Resolution 13
  - Disabled when at maximum

## Technical Implementation

### JavaScript API

#### Properties
```javascript
this.manualH3Resolution = null; // null = auto, 5-13 = manual override
```

#### Methods
```javascript
decreaseH3Resolution()  // Decrease resolution (larger hexagons)
increaseH3Resolution()  // Increase resolution (smaller hexagons)
```

#### Resolution Determination
All data loading functions now check for manual override:

```javascript
const resolution = this.manualH3Resolution !== null 
  ? this.manualH3Resolution 
  : this.getOptimalResolution(zoom);
```

### Event Handlers
Buttons are wired in `setupEventListeners()`:

```javascript
$('#h3-decrease').on('click', () => this.decreaseH3Resolution());
$('#h3-increase').on('click', () => this.increaseH3Resolution());
```

### Affected Functions
The following functions respect manual resolution override:
1. `updateZoomIndicator()` - Display current resolution
2. `loadHexagonData()` - Load hexagon data
3. `loadHexagonDataWithFilters()` - Load filtered data
4. `renderHexagons()` - Determine if incident markers should show

## Use Cases

### 1. Performance Optimization
**Scenario**: User zoomed far out but wants to see detailed data without loading millions of hexagons.

**Action**: Zoom to metro level (Z:9) but manually set H3:8 to see block-level data without overwhelming the browser.

### 2. Comparative Analysis
**Scenario**: User wants to compare different granularities at the same geographic view.

**Action**: Set zoom to comfortable viewing level, then cycle through H3 resolutions 6→7→8 to see how crime patterns change at different scales.

### 3. Targeted Investigation
**Scenario**: User investigating specific block but wants to see surrounding area context.

**Action**: Zoom to street level (Z:15) but use H3:7 (neighborhoods) to see crime patterns across multiple neighborhoods simultaneously.

### 4. Data Exploration
**Scenario**: User wants to explore what data exists at different resolutions.

**Action**: Fix zoom at city level, manually cycle through all resolutions to discover which granularity best shows the patterns they're investigating.

## Behavior Notes

### Auto Mode (Default)
- When user first loads map, resolution auto-adjusts based on zoom
- Zoom level determines resolution via `getOptimalResolution()`
- Map shows appropriate detail for current view

### Manual Mode (After Click)
- As soon as user clicks +/- button, manual mode activates
- Resolution stays fixed until user clicks +/- again
- Zoom changes no longer affect resolution
- Console logs show "(manual)" indicator

### Data Loading
- Each resolution change triggers new data load
- Existing hexagons cleared and replaced with new resolution
- Filters remain active (if any were applied)
- Statistics update to reflect new hexagon set

### Console Logging
All resolution changes log to console:
```
📐 Manual H3 resolution increased to 7
📊 Loading H3 Resolution 7 data (manual override)...
📊 Updating zoom indicator: zoom=12, resolution=7 (manual)
```

## Color Gradient Compatibility
Independent resolution control works seamlessly with the 18-grade z-score color gradient:
- Each hexagon colored based on its z-score (green=safe, red=dangerous)
- Color scale applies consistently across all resolutions
- Statistical accuracy maintained regardless of resolution selection

## Future Enhancements

### Potential Features
1. **Auto Toggle**: Button to re-enable automatic zoom-based resolution
2. **Keyboard Shortcuts**: [ ] keys to adjust resolution
3. **Resolution Presets**: Quick buttons (City=6, Block=9, Ultra=13)
4. **Persistence**: Remember user's last manual resolution in localStorage
5. **Resolution Tooltip**: Hover info explaining what each resolution shows
6. **Animation**: Smooth transition between resolutions
7. **Resolution Range**: Allow user to set min/max bounds for auto mode

## Testing Checklist

### Functional Tests
- [ ] Click + button increases resolution from 6→7
- [ ] Click − button decreases resolution from 7→6
- [ ] Cannot decrease below resolution 5
- [ ] Cannot increase above resolution 13
- [ ] Hexagons reload after each resolution change
- [ ] Scale label updates to match resolution
- [ ] Zoom changes don't affect manual resolution
- [ ] Console shows "(manual)" indicator

### Visual Tests
- [ ] Buttons render properly with minus/plus symbols
- [ ] Button hover shows descriptive tooltips
- [ ] Resolution display updates immediately
- [ ] Scale label changes to correct description
- [ ] Map re-centers appropriately after data load

### Performance Tests
- [ ] Resolution changes complete in <1 second
- [ ] No memory leaks from repeated resolution changes
- [ ] Browser remains responsive during data load
- [ ] Old hexagons properly cleared before new ones render

## Version History
- **v7.0** (2024): Added independent H3 resolution controls
  - Manual +/- buttons for resolution adjustment
  - Decoupled from automatic zoom-based resolution
  - Removed auto-refresh button
  - Range: H3 resolutions 5-13

- **v6.0** (2024): Z-score based 18-grade heat map coloring
- **v5.0** (2024): Initial crime map implementation

## Related Documentation
- [Gold Layer Inventory](GOLD_LAYER_INVENTORY.md) - Complete analytics data catalog
- [Crime Map Implementation](CRIME_MAP_IMPLEMENTATION.md) - Technical details
- [API Integration](API_INTEGRATION.md) - Backend API documentation
- [User Registration](USER_REGISTRATION.md) - User management flows
