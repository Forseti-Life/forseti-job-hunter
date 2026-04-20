/**
 * AmISafe Control Panel Test
 * Verify all control panel elements and functionality
 */

// Test function to verify control panel elements
function testControlPanelElements() {
  console.log('🧪 Testing AmISafe Control Panel Elements...');
  
  const requiredElements = [
    // Filter selectors
    'crime-type-selector',
    'district-selector', 
    'severity-selector',
    'start-month',
    'end-month',
    'time-period-selector',
    
    // Action buttons
    'apply-filters',
    'clear-filters',
    
    // View mode buttons
    'hexagon-view',
    'heatmap-view',
    'points-view',
    
    // Preset buttons
    'preset-violent',
    'preset-property',
    'preset-recent',
    'preset-high-severity',
    
    // Debug buttons
    'center-hexagons-btn',
    'refresh-data-btn',
    'toggle-overlays-btn',
    'performance-stats-btn',
    
    // Stats displays
    'total-incidents',
    'threat-level',
    'active-sectors',
    'citywide-total',
    'citywide-districts',
    'citywide-threat',
    'citywide-coverage',
    
    // H3 debug elements
    'h3-available',
    'h3-method-count',
    'h3-test-result'
  ];
  
  const results = {
    found: [],
    missing: []
  };
  
  requiredElements.forEach(elementId => {
    const element = document.getElementById(elementId);
    if (element) {
      results.found.push(elementId);
      console.log(`✅ Found: ${elementId}`);
    } else {
      results.missing.push(elementId);
      console.warn(`❌ Missing: ${elementId}`);
    }
  });
  
  console.log(`📊 Control Panel Test Results:`);
  console.log(`   Found: ${results.found.length}/${requiredElements.length}`);
  console.log(`   Missing: ${results.missing.length}`);
  
  if (results.missing.length > 0) {
    console.warn('Missing elements:', results.missing);
  }
  
  return results;
}

// Run test when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', testControlPanelElements);
} else {
  testControlPanelElements();
}