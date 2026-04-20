/**
 * @file
 * AmISafe Crime Map - Refactored Core JavaScript
 * 
 * Clean, organized implementation of the interactive crime map
 * Features: H3 hexagon visualization, zoom-based resolution switching, minimal mode
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * AmISafe Crime Map Drupal Behavior
   */
  Drupal.behaviors.amisafeCrimeMap = {
    attach: function (context, settings) {
      console.log('🔥 BEHAVIOR ATTACH CALLED');
      console.log('Settings:', settings);
      
      if (!settings.amisafe) {
        console.error('AmISafe settings not found');
        return;
      }

      $(context).find('#crime-map-container').addBack('#crime-map-container').each(function () {
        if (!this.hasAttribute('data-amisafe-initialized')) {
          this.setAttribute('data-amisafe-initialized', 'true');
          var crimeMap = new AmISafeCrimeMap(this, settings.amisafe);
          crimeMap.initialize();
          // Expose instance so external modules (e.g. community_incident_report) can attach layers.
          window.AmISafeMap = crimeMap;
        }
      });
    }
  };

  /**
   * AmISafe Crime Map Class
   */
  function AmISafeCrimeMap(container, settings) {
    // Core properties
    this.container = container;
    this.settings = settings;
    this.map = null;
    this.hexagonLayer = null;
    this.incidentLayer = null; // For individual incident points at high zoom
    this.currentFilters = {};
    this.debugMode = false;
    
    // Minimal mode configuration
    this.minimalMode = false; // Disabled: Use full z-score color gradient (green to red)
    
    // Performance optimization
    this.dataCache = new Map();
    this.currentRequest = null;
    this.apiCallCount = 0;
    
    // Timing controls
    this.loadTimeout = null;
    this.filterTimeout = null;
    this.zoomTimeout = null;
    
    // Auto-fit control - prevent constant re-centering
    this.shouldAutoFit = true; // Only auto-fit on initial load and zoom changes
    this.isInitialLoad = true; // Track if this is the first data load
    
    // Independent H3 resolution control (user-controlled, decoupled from zoom)
    this.manualH3Resolution = null; // null = auto (zoom-based), 5-13 = manual
  }

  /**
   * Class Methods
   */
  AmISafeCrimeMap.prototype = {
    
    /**
     * Initialize the crime map
     */
    initialize: function() {
      console.log('🚀 Initializing AmISafe Crime Map...');
      
      // Wait for container to have dimensions (critical on mobile)
      const container = document.getElementById('crime-map-container');
      if (!container) {
        console.error('❌ Map container not found');
        return;
      }
      
      const waitForContainer = () => {
        const rect = container.getBoundingClientRect();
        console.log('📏 Checking container dimensions:', {
          width: rect.width,
          height: rect.height,
          offsetWidth: container.offsetWidth,
          offsetHeight: container.offsetHeight
        });
        
        // Check if container has dimensions
        if (rect.width > 0 && rect.height > 0) {
          console.log('✅ Container has dimensions, proceeding with initialization');
          this.proceedWithInitialization();
        } else {
          console.log('⏳ Container has zero dimensions - forcing size on mobile');
          // Force dimensions on mobile
          container.style.width = '100%';
          container.style.height = '500px';
          container.style.minHeight = '500px';
          container.style.display = 'block';
          
          // Check one more time after forcing
          setTimeout(() => {
            const newRect = container.getBoundingClientRect();
            console.log('📏 After forcing dimensions:', {
              width: newRect.width,
              height: newRect.height
            });
            if (newRect.width > 0 && newRect.height > 0) {
              console.log('✅ Forced dimensions worked, proceeding');
              this.proceedWithInitialization();
            } else {
              console.error('❌ Still no dimensions after forcing - CSS issue');
            }
          }, 100);
        }
      };
      
      // Start checking for container dimensions
      waitForContainer();
    },
    
    /**
     * Proceed with initialization once container is ready
     */
    proceedWithInitialization: function() {
      console.log('🚀 Proceeding with map initialization...');
      
      // Template elements verified working - debug removed for cleaner output
      
      // Apply minimal mode styling
      if (this.minimalMode) {
        this.enableMinimalMode();
      }
      
      // Initialize map components
      this.createMap();
      this.setupEventListeners();
      this.initializeControls();
      this.initializeFilters();
      
      // Load initial data
      setTimeout(() => {
        this.loadInitialData();
      }, 500);
    },

    /**
     * Enable minimal mode for clean data visualization
     */
    enableMinimalMode: function() {
      // Apply CSS class after a delay to avoid interfering with map initialization
      setTimeout(() => {
        document.body.classList.add('minimal-mode');
        console.log('🔇 Minimal mode activated');
      }, 1000);
    },

    /**
     * Create and configure the Leaflet map
     */
    createMap: function() {
      if (!window.L) {
        console.error('Leaflet library not loaded');
        return;
      }

      // Check map container dimensions (especially important on mobile)
      const container = document.getElementById('crime-map-container');
      if (container) {
        const rect = container.getBoundingClientRect();
        console.log('🗺️ Map container dimensions:', {
          width: rect.width,
          height: rect.height,
          visible: rect.width > 0 && rect.height > 0
        });
        
        // If container has no dimensions, force them
        if (rect.width === 0 || rect.height === 0) {
          console.warn('⚠️ Map container has zero dimensions - forcing size');
          container.style.width = '100%';
          container.style.height = '500px';
          container.style.minHeight = '500px';
        }
      }

      // Create map with Philadelphia center
      const mapConfig = this.settings.mapConfig;
      this.map = L.map('crime-map-container', {
        center: mapConfig.center,
        zoom: mapConfig.zoom,
        minZoom: 5,  // Allow zoom out to Resolution 5 (Philadelphia metro area)
        maxZoom: 20,
        zoomControl: false,  // Disable default zoom control - we'll use custom controls
        attributionControl: false
      });

      // Add light tile layer
      const tileLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
        attribution: '',
        subdomains: 'abcd',
        maxZoom: 20,
        className: 'light-tiles'
      });
      
      tileLayer.on('tileerror', function(error) {
        console.error('❌ Tile loading error:', error);
      });
      
      tileLayer.on('tileload', function() {
        console.log('✅ Tile loaded successfully');
      });
      
      tileLayer.addTo(this.map);
      
      console.log('🗺️ Tile layer added to map');

      // Initialize layers (incident layer on top)
      this.hexagonLayer = L.layerGroup().addTo(this.map);
      this.incidentLayer = L.layerGroup().addTo(this.map); // For individual incident points
      
      // Force map to recognize its size on mobile devices
      setTimeout(() => {
        if (this.map) {
          this.map.invalidateSize();
          console.log('🔄 Map size invalidated after creation');
        }
      }, 100);
      
      console.log('🗺️ Map created successfully');
      
      // Check H3 library availability
      if (window.h3) {
        console.log('✅ H3 library available, version:', typeof h3.cellToBoundary === 'function' ? 'v4+' : 'unknown');
      } else {
        console.log('⚠️ H3 library not found - will use fallback circles');
      }
    },

    /**
     * Setup map event listeners
     */
    setupEventListeners: function() {
      this.map.on('zoomend', () => this.handleZoomChange());
      this.map.on('moveend', () => this.handleMapMove());
      
      // H3 resolution manual controls
      $('#h3-decrease').on('click', () => this.decreaseH3Resolution());
      $('#h3-increase').on('click', () => this.increaseH3Resolution());
      
      // Manual zoom controls
      $('#zoom-in').on('click', () => {
        this.map.zoomIn();
      });
      $('#zoom-out').on('click', () => {
        this.map.zoomOut();
      });
      
      // Hexagon detail panel close button
      $('#close-detail-panel').on('click', () => this.closeHexagonDetailPanel());
      
      // Close panel with Escape key
      $(document).on('keydown', (e) => {
        if (e.key === 'Escape' && $('#hexagon-detail-panel').is(':visible')) {
          this.closeHexagonDetailPanel();
        }
      });
      
      // Force map resize after initialization
      setTimeout(() => {
        this.map.invalidateSize();
        this.updateZoomIndicator(); // Initial zoom indicator update
      }, 1000);
      
      // Add additional resize on window resize for mobile
      let resizeTimeout;
      $(window).on('resize', () => {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
          if (this.map) {
            this.map.invalidateSize();
          }
        }, 250);
      });
    },

    /**
     * Initialize map controls
     */
    initializeControls: function() {
      const self = this;
      
      // Re-center map to fit hexagons
      $('#fit-hexagons-btn').on('click', function() {
        self.fitMapToHexagons();
      });
      
      // Test panel button - using delegated event handler
      $(document).on('click', '#test-panel-btn', function() {
        console.log('🧪 Test panel button clicked');
        self.showHexagonDetailPanel({});
      });
    },

    /**
     * Initialize filter controls
     */
    initializeFilters: function() {
      const self = this;
      
      // Initialize default filter state with 12-month preset
      this.currentFilters = {
        crimeTypes: [],
        districts: [],
        dateRange: 'alltime', // Use preset instead of specific dates
        timePeriods: ['early-morning', 'morning', 'afternoon', 'evening'],
        viewMode: 'hexagon'
      };
      
      // Load filter options from API
      this.loadFilterOptions();
      
      // Date range preset buttons
      $('.preset-btn').on('click', function() {
        const preset = $(this).data('preset');
        self.setDatePreset(preset);
        // Auto-apply on preset change
        setTimeout(() => self.applyFilters(), 100);
      });
      
      // Toggle crime filter dropdown
      $('#toggle-crime-filter').on('click', function() {
        $('#crime-type-dropdown').toggle();
      });
      
      // Close crime filter dropdown
      $('#close-crime-filter').on('click', function() {
        $('#crime-type-dropdown').hide();
      });
      
      // Apply crime type filter
      $('#apply-crime-filter').on('click', function() {
        self.applyFilters();
        $('#crime-type-dropdown').hide();
      });
      
      // Clear crime type filter
      $('#clear-crime-filter').on('click', function() {
        $('#crime-type-selector option').prop('selected', true);
        self.applyFilters();
        $('#crime-type-dropdown').hide();
      });
      
      console.log('✅ Filters initialized with date range: alltime');
      
      // View mode buttons
      $('#hexagon-view').on('click', function() {
        self.switchViewMode('hexagon');
        $('.view-options .cyber-button').removeClass('active');
        $(this).addClass('active');
      });
      
      $('#heatmap-view').on('click', function() {
        self.switchViewMode('heatmap');
        $('.view-options .cyber-button').removeClass('active');
        $(this).addClass('active');
      });
      
      $('#points-view').on('click', function() {
        self.switchViewMode('points');
        $('.view-options .cyber-button').removeClass('active');
        $(this).addClass('active');
      });
      
      // Quick preset buttons
      $('.preset-btn').on('click', function() {
        const preset = $(this).data('preset');
        self.applyPreset(preset);
        $('.preset-btn').removeClass('active');
        $(this).addClass('active');
      });
      
      // Map control buttons - fit-hexagons-btn is already handled in initializeControls()
      
      console.log('🔧 Filters initialized with full functionality');
    },

    /**
     * Handle zoom changes with debouncing
     */
    handleZoomChange: function() {
      // Clear existing timeout
      if (this.zoomTimeout) {
        clearTimeout(this.zoomTimeout);
      }
      
      // Update zoom indicator immediately
      this.updateZoomIndicator();
      
      // Allow auto-fit on zoom changes (user wants new data at different resolution)
      this.shouldAutoFit = true;
      
      // Debounce data loading
      this.zoomTimeout = setTimeout(() => {
        this.loadHexagonData();
      }, 300);
    },

    /**
     * Handle map movement
     */
    handleMapMove: function() {
      // Debounce map move events
      if (this.loadTimeout) {
        clearTimeout(this.loadTimeout);
      }
      
      // Prevent auto-fit on map moves (user is panning around)
      this.shouldAutoFit = false;
      
      this.loadTimeout = setTimeout(() => {
        this.loadHexagonData();
      }, 500);
    },

    /**
     * Update zoom level indicator with robust element waiting
     */
    updateZoomIndicator: function(zoom, resolution) {
      // Get current values if not provided
      if (zoom === undefined) zoom = this.map.getZoom();
      
      // Use manual H3 resolution if set, otherwise calculate from zoom
      if (resolution === undefined) {
        resolution = this.manualH3Resolution !== null 
          ? this.manualH3Resolution 
          : this.getOptimalResolution(zoom);
      }
      
      const scaleDescription = this.getResolutionDescription(resolution);
      const roundedZoom = Math.round(zoom * 10) / 10;
      
      console.log(`📊 Updating zoom indicator: zoom=${roundedZoom}, resolution=${resolution}${this.manualH3Resolution !== null ? ' (manual)' : ' (auto)'}`);
      
      // Use robust element waiting with multiple attempts
      this.waitForZoomElements(roundedZoom, resolution, scaleDescription, 0);
    },

    /**
     * Wait for zoom indicator elements with progressive retry
     */
    waitForZoomElements: function(zoom, resolution, scaleDesc, attempt) {
      const maxAttempts = 15; // More attempts
      const baseDelay = 50; // Start with shorter delay
      const delay = Math.min(baseDelay * Math.pow(1.2, attempt), 3000); // Progressive but capped
      
      // Try multiple selection methods
      const methods = [
        () => ({
          zoom: document.getElementById('zoom-level'),
          resolution: document.getElementById('h3-resolution'),
          scale: document.querySelector('.scale-label')
        }),
        () => ({
          zoom: document.querySelector('#zoom-level'),
          resolution: document.querySelector('#h3-resolution'),
          scale: document.getElementsByClassName('scale-label')[0]
        }),
        () => ({
          zoom: document.querySelector('#zoom-indicator #zoom-level'),
          resolution: document.querySelector('#zoom-indicator #h3-resolution'),
          scale: document.querySelector('#zoom-indicator .scale-label')
        })
      ];
      
      let elements = null;
      for (let method of methods) {
        elements = method();
        if (elements.zoom && elements.resolution && elements.scale) {
          // Success! Update elements
          elements.zoom.textContent = zoom;
          elements.resolution.textContent = resolution;
          elements.scale.textContent = scaleDesc;
          console.log(`✅ Zoom indicator updated (attempt ${attempt + 1}, method ${methods.indexOf(method) + 1}): ZOOM=${zoom} H3=${resolution} ${scaleDesc}`);
          return;
        }
      }
      
      // None of the methods worked
      if (attempt < maxAttempts) {
        console.log(`⏳ Zoom elements not ready (attempt ${attempt + 1}/${maxAttempts}), retrying in ${delay}ms...`);
        setTimeout(() => {
          this.waitForZoomElements(zoom, resolution, scaleDesc, attempt + 1);
        }, delay);
      } else {
        // Final failure - comprehensive debug
        console.log(`❌ Zoom indicator elements not found after ${maxAttempts} attempts`);
        console.log('🔍 Final DOM state:');
        console.log('  - zoom-indicator container:', !!document.getElementById('zoom-indicator'));
        console.log('  - crime-map-container:', !!document.getElementById('crime-map-container'));
        console.log('  - zoom-level element:', !!document.getElementById('zoom-level'));
        console.log('  - h3-resolution element:', !!document.getElementById('h3-resolution'));
        console.log('  - scale-label elements:', document.querySelectorAll('.scale-label').length);
        console.log('  - all zoom-level elements:', document.querySelectorAll('#zoom-level').length);
        
        // DEBUG: Show what's actually inside the zoom-indicator container
        const container = document.getElementById('zoom-indicator');
        if (container) {
          console.log('🔍 zoom-indicator innerHTML:', container.innerHTML);
          console.log('🔍 zoom-indicator children:', container.children.length);
          for (let i = 0; i < container.children.length; i++) {
            const child = container.children[i];
            console.log(`  child ${i}:`, child.tagName, child.id || 'no-id', child.className || 'no-class', child.textContent.substring(0, 50));
          }
        }
        
        // Store values for manual display
        console.log(`📊 Current values: ZOOM=${zoom} H3=${resolution} ${scaleDesc}`);
        
        // FALLBACK: Create missing elements if container exists but children don't
        if (container && container.children.length === 0) {
          console.log('🔧 Creating missing zoom indicator elements...');
          container.innerHTML = `
            <span class="zoom-label">ZOOM:</span>
            <span id="zoom-level">${zoom}</span>
            <span class="resolution-label">H3:</span>
            <span id="h3-resolution">${resolution}</span>
            <span class="scale-label">${scaleDesc}</span>
            <button id="refresh-zoom" style="margin-left: 10px; font-size: 10px;">REFRESH</button>
          `;
          console.log(`✅ Zoom indicator elements created and updated: ZOOM=${zoom} H3=${resolution} ${scaleDesc}`);
        }
      }
    },

    /**
     * Get optimal H3 resolution based on zoom level
     */
    getOptimalResolution: function(zoomLevel) {
      if (zoomLevel <= 9) return 4;        // ~1,770 km² - Complete metro area coverage
      else if (zoomLevel <= 10) return 5;  // ~251 km² - Metro districts
      else if (zoomLevel <= 11) return 6;  // ~36 km² - City areas
      else if (zoomLevel <= 12) return 7;  // ~5.2 km² - Neighborhoods
      else if (zoomLevel <= 13) return 8;  // ~0.7 km² - Block groups  
      else if (zoomLevel <= 14) return 9;  // ~0.1 km² - Street blocks
      else if (zoomLevel <= 15) return 10; // ~15,047 m² - Building groups
      else if (zoomLevel <= 16) return 11; // ~2,150 m² - Individual buildings
      else if (zoomLevel <= 17) return 12; // ~307 m² - Room-level precision
      else return 13;                      // ~44 m² - Ultra-precision
    },

    /**
     * Get human-readable description of H3 resolution
     */
    getResolutionDescription: function(resolution) {
      const descriptions = {
        4: '~1,770km² metro-wide',
        5: '~251km² districts',
        6: '~36km² city areas',
        7: '~5.2km² neighborhoods',
        8: '~0.7km² block groups', 
        9: '~0.1km² street blocks',
        10: '~15,047m² building groups',
        11: '~2,150m² buildings',
        12: '~307m² rooms',
        13: '~44m² ultra-precision'
      };
      return descriptions[resolution] || 'unknown';
    },

    /**
     * Get precision level name for resolution
     */
    getPrecisionLevel: function(resolution) {
      const levels = {
        4: 'Metro-wide',
        5: 'Multi-district',
        6: 'Metropolitan',
        7: 'District-wide',
        8: 'Block-level',
        9: 'Street-level',
        10: 'Property clusters',
        11: 'Building-level',
        12: 'Floor-level',
        13: 'Ultra-fine room-level'
      };
      return levels[resolution] || 'Unknown';
    },

    /**
     * Get hexagon coverage area in km² for resolution
     */
    getHexagonCoverageKm2: function(resolution) {
      const sizes = {
        4: 1770.3,    // ~1,770 km²
        5: 252.9,     // ~251 km²
        6: 36.13,     // ~36 km²
        7: 5.16,      // ~5.2 km²
        8: 0.737,     // ~737,000 m² = 0.737 km²
        9: 0.105,     // ~105,000 m² = 0.105 km²
        10: 0.015,    // ~15,048 m² = 0.015 km²
        11: 0.00215,  // ~2,150 m² = 0.00215 km²
        12: 0.000307, // ~307 m² = 0.000307 km²
        13: 0.000044  // ~44 m² = 0.000044 km²
      };
      return sizes[resolution] || 0;
    },

    /**
     * Decrease H3 resolution (larger hexagons)
     */
    decreaseH3Resolution: function() {
      const currentResolution = this.manualH3Resolution !== null 
        ? this.manualH3Resolution 
        : this.getOptimalResolution(this.map.getZoom());
      
      if (currentResolution > 5) {
        this.manualH3Resolution = currentResolution - 1;
        console.log(`📐 Manual H3 resolution decreased to ${this.manualH3Resolution}`);
        this.updateZoomIndicator();
        this.loadHexagonData();
      } else {
        console.log('⚠️ Cannot decrease resolution below 5');
      }
    },

    /**
     * Increase H3 resolution (smaller hexagons)
     */
    increaseH3Resolution: function() {
      const currentResolution = this.manualH3Resolution !== null 
        ? this.manualH3Resolution 
        : this.getOptimalResolution(this.map.getZoom());
      
      if (currentResolution < 13) {
        this.manualH3Resolution = currentResolution + 1;
        console.log(`📐 Manual H3 resolution increased to ${this.manualH3Resolution}`);
        this.updateZoomIndicator();
        this.loadHexagonData();
      } else {
        console.log('⚠️ Cannot increase resolution above 13');
      }
    },

    /**
     * Load initial safety data
     */
    loadInitialData: function() {
      this.showLoading('Loading Philadelphia Safety Data', 'Initializing map...');
      this.shouldAutoFit = true; // Allow auto-fit for initial load
      this.isInitialLoad = true; // Flag to skip filters on initial load
      
      // Set correct initial statistics
      console.log('📊 Setting initial statistics');
      
      // All City Wide is ALWAYS 3,406,192 (constant citywide total)
      $('#citywide-total').text('3,406,192');
      $('#total-incidents').text('0');
      $('#violent-crimes').text('0');
      $('#property-crimes').text('0');
      
      setTimeout(() => {
        this.showLoading('Loading Philadelphia Safety Data', 'Fetching hexagon data...');
      }, 100);
      
      this.loadHexagonData();
      
      // Load citywide stats via JavaScript 
      this.loadCitywideStats();
    },

    /**
     * Force update stats as fallback
     */
    forceUpdateStats: function() {
      console.log('🔧 Force updating stats...');
      
      // Try multiple approaches
      try {
        // Method 1: Direct jQuery
        $('#citywide-total').text('3,406,192');
        $('#total-incidents').text('0');
        $('#violent-crimes').text('0');
        $('#property-crimes').text('0');
        
        // Method 2: Vanilla JavaScript
        const elements = {
          'citywide-total': '3,406,192',
          'total-incidents': '0',
          'violent-crimes': '0',
          'property-crimes': '0'
        };
        
        Object.keys(elements).forEach(id => {
          const element = document.getElementById(id);
          if (element) {
            element.textContent = elements[id];
            console.log(`✅ Updated ${id} via vanilla JS`);
          } else {
            console.warn(`❌ Element ${id} not found`);
          }
        });
        
        console.log('🎯 Force update completed');
      } catch (error) {
        console.error('❌ Force update failed:', error);
      }
    },

    /**
     * Load hexagon safety data based on current view
     */
    loadHexagonData: function() {
      const zoom = this.map.getZoom();
      
      // Use manual H3 resolution if set, otherwise calculate from zoom
      const resolution = this.manualH3Resolution !== null 
        ? this.manualH3Resolution 
        : this.getOptimalResolution(zoom);
      
      let bounds = this.map.getBounds();
      
      // IMPORTANT: Apply current filters (especially dateRange) to all data loads
      let filters = this.getCurrentFilters();
      
      console.log(`📊 Loading H3 Resolution ${resolution} data${this.manualH3Resolution !== null ? ' (manual override)' : ' (zoom-based)'} with filters:`, filters);
      
      // DEBUG: For H3:5, try a much broader bounds to see if data exists
      if (resolution === 5) {
        console.log('🔍 H3:5 requested - using broader bounds for metro area coverage');
        // Expand bounds significantly for H3:5 since these are large hexagons (~251km² each)
        // Need to cover multiple H3:5 hexagons to encompass entire Philadelphia metro area
        const center = bounds.getCenter();
        const expandedBounds = L.latLngBounds(
          [center.lat - 2, center.lng - 2],  // Much larger area for multiple H3:5 hexagons
          [center.lat + 2, center.lng + 2]
        );
        console.log('🔍 Original bounds:', bounds.toString());
        console.log('🔍 Expanded bounds for H3:5 (metro area):', expandedBounds.toString());
        bounds = expandedBounds;
      }
      
      // Cancel previous request
      if (this.currentRequest) {
        this.currentRequest.abort();
      }
      
      // Build API URL
      const apiUrl = this.buildApiUrl(resolution, bounds, filters);
      
      // Make API request
      this.currentRequest = $.ajax({
        url: apiUrl,
        method: 'GET',
        timeout: 30000,
        beforeSend: () => {
          this.showLoading('Loading Safety Data', `Fetching H3 Resolution ${resolution} data...`);
        }
      })
      .done((data) => {
        this.showLoading('Loading Safety Data', 'Processing hexagons...');
        console.log('📊 Received filtered data:', {
          hexagons: data.hexagons ? data.hexagons.length : 0,
          resolution: data.meta ? data.meta.resolution : 'unknown',
          filters: data.meta ? data.meta.filters : 'none'
        });
        
        // Debug: Check for H3:5 hexagons in received data
        if (data.hexagons && data.hexagons.length > 0) {
          const h3_5_hexagons = data.hexagons.filter(hex => {
            if (window.h3 && hex.h3_index) {
              return h3.getResolution(hex.h3_index) === 5;
            }
            return false;
          });
          if (h3_5_hexagons.length > 0) {
            console.log('🔍 Found H3:5 hexagons in API data:', h3_5_hexagons.length);
          } else if (resolution === 5) {
            console.warn('⚠️ Expected H3:5 hexagons but none found in API response');
          }
        } else if (resolution === 5) {
          // Test if H3:5 data exists at all with minimal filters
          console.log('🧪 Testing if H3:5 data exists anywhere...');
          const testUrl = '/api/amisafe/aggregated?resolution=5&limit=10';
          fetch(testUrl)
            .then(response => response.json())
            .then(testData => {
              if (testData.hexagons && testData.hexagons.length > 0) {
                console.log('✅ H3:5 data EXISTS in database:', testData.hexagons.length, 'hexagons');
                console.log('🔍 Sample H3:5 hexagon:', testData.hexagons[0]);
              } else {
                console.error('❌ NO H3:5 data found in database - backend aggregation issue');
              }
            })
            .catch(error => {
              console.error('❌ Error testing H3:5 data:', error);
            });
        }
        
        this.renderHexagons(data);
        this.hideLoading();
        
        // Reset initial load flag after first successful data load
        if (this.isInitialLoad) {
          this.isInitialLoad = false;
          console.log('🔄 Initial load complete, filters now active');
        }
      })
      .fail((xhr, status, error) => {
        if (status !== 'abort') {
          console.error('API request failed:', error);
          this.hideLoading();
        }
      });
    },

    /**
     * Load hexagon data WITH filters applied (only called from applyFilters)
     */
    loadHexagonDataWithFilters: function() {
      this.showLoading('Applying Filters', 'Preparing filtered data...');
      const zoom = this.map.getZoom();
      
      // Use manual H3 resolution if set, otherwise calculate from zoom
      const resolution = this.manualH3Resolution !== null 
        ? this.manualH3Resolution 
        : this.getOptimalResolution(zoom);
      
      let bounds = this.map.getBounds();
      let filters = this.getCurrentFilters(); // Apply current filters
      
      console.log(`📊 Loading H3 Resolution ${resolution} data WITH FILTERS${this.manualH3Resolution !== null ? ' (manual override)' : ' (zoom-based)'}...`);
      
      // Cancel any ongoing request
      if (this.currentRequest) {
        this.currentRequest.abort();
      }
      
      const apiUrl = this.buildApiUrl(resolution, bounds, filters);
      
      this.currentRequest = $.get(apiUrl)
      .done((data) => {
        console.log('📊 Received filtered data:', data);
        
        if (!data || !data.hexagons) {
          console.warn('⚠️ No hexagon data in API response');
          this.hideLoading();
          return;
        }
        
        if (data.hexagons.length === 0) {
          console.log('📊 No hexagons match current filters');
        }
        
        this.renderHexagons(data);
        this.hideLoading();
      })
      .fail((xhr, status, error) => {
        if (status !== 'abort') {
          console.error('Filtered API request failed:', error);
          this.hideLoading();
        }
      });
    },

    /**
     * Build API URL for hexagon data
     */
    buildApiUrl: function(resolution, bounds, filters) {
      const baseUrl = '/api/amisafe/aggregated';
      const params = new URLSearchParams();
      
      // Add basic parameters
      params.append('resolution', resolution);
      params.append('bounds', bounds.getNorth() + ',' + bounds.getEast() + ',' + bounds.getSouth() + ',' + bounds.getWest());
      
      // Set limit based on resolution - higher resolution needs more hexagons
      // Resolution 5-7: Large hexagons, few needed (1000)
      // Resolution 8-10: Medium hexagons (5000)
      // Resolution 11-13: Small hexagons, many needed (20000)
      let limit = 1000;
      if (resolution >= 11) {
        limit = 20000; // Ultra-precision needs many hexagons
      } else if (resolution >= 8) {
        limit = 5000; // Medium precision
      }
      params.append('limit', limit);
      
      // IMPORTANT: Only send filters if they're actually filtering (not "all selected")
      // When crime types/districts arrays are empty OR contain all possible values,
      // don't send them - let the backend return all data
      
      // Add date range preset (uses pre-calculated DB values)
      if (filters.dateRange) {
        params.append('date_range', filters.dateRange);
      }
      
      const finalUrl = `${baseUrl}?${params.toString()}`;
      console.log('🔗 API URL:', finalUrl);
      return finalUrl;
    },

    /**
     * Render hexagons on the map
     */
    renderHexagons: function(data) {
      // Clear existing layers
      this.hexagonLayer.clearLayers();
      this.incidentLayer.clearLayers();
      
      if (!data.hexagons || data.hexagons.length === 0) {
        console.log('📊 No hexagon data received');
        return;
      }
      
      // Track hexagon bounds to fit map view
      const allBounds = L.latLngBounds();
      let successfulHexagons = 0;
      
      // Render each hexagon
      data.hexagons.forEach(hexagon => {
        const result = this.renderSingleHexagon(hexagon);
        if (result && result.getBounds) {
          allBounds.extend(result.getBounds());
          successfulHexagons++;
        }
      });
      
      console.log(`📊 Rendered ${data.hexagons.length} hexagons (${successfulHexagons} successful)`);
      
      // Update visible incidents count based on rendered hexagons
      this.updateVisibleIncidentsCount(data.hexagons);
      
      // Fit map to show all hexagons only when appropriate (not during user panning)
      if (successfulHexagons > 0 && allBounds.isValid() && this.shouldAutoFit) {
        setTimeout(() => {
          this.map.fitBounds(allBounds, {
            padding: [20, 20],
            maxZoom: 15
          });
          console.log(`📍 Map fitted to show ${successfulHexagons} hexagons`);
          
          // Disable auto-fit after initial load to prevent constant re-centering
          if (this.isInitialLoad) {
            this.isInitialLoad = false;
            console.log('🎯 Initial load complete - auto-fit disabled for user interaction');
          }
        }, 500);
      } else if (successfulHexagons > 0) {
        console.log(`📊 ${successfulHexagons} hexagons rendered without auto-fit (user is panning)`);
      }
    },



    /**
     * Render a single hexagon
     */
    renderSingleHexagon: function(hexagon) {
      const incidentCount = hexagon.incident_count || hexagon.incidentCount || 0;
      const h3Index = hexagon.h3_index;
      
      if (!h3Index) {
        console.warn('No H3 index found for hexagon:', hexagon);
        return;
      }
      
      // Debug logging for H3:5 hexagons
      const h3Resolution = window.h3 ? h3.getResolution(h3Index) : 'unknown';
      if (h3Resolution === 5) {
        console.log('🔍 Rendering H3:5 hexagon:', {
          h3Index: h3Index,
          incidentCount: incidentCount,
          resolution: h3Resolution
        });
      }
      
      // Use H3 library to get boundary if available
      if (window.h3 && h3.cellToBoundary) {
        try {
          // Get H3 boundary coordinates
          const boundary = h3.cellToBoundary(h3Index, true);
          
          // Convert from H3 [lng, lat] to Leaflet [lat, lng] format
          const leafletCoords = boundary.map(coord => [coord[1], coord[0]]);
          
          // Debug logging for large hexagons
          if (h3Resolution === 5) {
            console.log('🔍 H3:5 boundary coordinates:', leafletCoords);
          }
          
          // Calculate styling based on z-score from analytics
          const style = this.calculateHexagonStyle(hexagon);
          
          // Create and add polygon to map
          const polygon = L.polygon(leafletCoords, style);
          
          // Add hover tooltip with incident statistics
          polygon.bindTooltip(this.createHexagonTooltip(hexagon), {
            permanent: false,
            sticky: true,
            direction: 'top',
            className: 'hexagon-tooltip'
          });
          
          // Add click handler to show detail panel
          polygon.on('click', () => {
            console.log('🖱️ Hexagon clicked!', hexagon.h3_index);
            this.showHexagonDetailPanel(hexagon);
          });
          
          // Enhanced hover effects with visual feedback
          polygon.on('mouseover', function(e) {
            e.target.setStyle({ 
              weight: 3, 
              fillOpacity: 0.9,
              color: '#00ffff'
            });
          });
          
          polygon.on('mouseout', function(e) {
            e.target.setStyle({ 
              weight: 1, 
              fillOpacity: style.fillOpacity,
              color: style.color
            });
          });
          
          polygon.addTo(this.hexagonLayer);
          
          // Success logging for H3:5
          if (h3Resolution === 5) {
            console.log('✅ H3:5 hexagon successfully rendered');
          }
          
          return polygon; // Return polygon for bounds tracking
          
        } catch (error) {
          console.warn('Failed to render hexagon', h3Index, ':', error);
          if (h3Resolution === 5) {
            console.error('❌ H3:5 hexagon rendering failed:', error);
          }
          return this.createFallbackCircle(hexagon);
        }
      } else {
        // Fallback to circle if H3 library not available
        console.warn('H3 library not available, using fallback circle for:', h3Index);
        return this.createFallbackCircle(hexagon);
      }
    },

    /**
     * Create fallback circle when H3 is not available
     */
    createFallbackCircle: function(hexagon) {
      const incidentCount = hexagon.incident_count || hexagon.incidentCount || 0;
      const lat = hexagon.lat || 39.9526;
      const lng = hexagon.lng || -75.1652;
      const radius = Math.max(50, incidentCount * 5);
      
      const style = this.calculateHexagonStyle(incidentCount);
      const circle = L.circle([lat, lng], {
        radius: radius,
        ...style
      });
      
      // Add hover tooltip
      circle.bindTooltip(this.createHexagonTooltip(hexagon), {
        permanent: false,
        sticky: true,
        direction: 'top',
        className: 'hexagon-tooltip'
      });
      
      // Add click handler to show detail panel
      circle.on('click', () => this.showHexagonDetailPanel(hexagon));
      
      circle.addTo(this.hexagonLayer);
      return circle; // Return circle for bounds tracking
    },

    /**
     * Calculate hexagon styling based on z-score (statistical significance)
     * Uses incident_z_score for normalized heat map coloring across resolutions
     * Scale: -1 (green/safe) to 11+ (red/extreme danger)
     */
    calculateHexagonStyle: function(hexagonData) {
      // Extract z-score from analytics if available, otherwise use incident count
      let zScore = 0;
      let incidentCount = 0;
      
      if (typeof hexagonData === 'object') {
        // Get z-score from analytics (prioritize this for accurate coloring)
        if (hexagonData.analytics && hexagonData.analytics.z_scores) {
          zScore = hexagonData.analytics.z_scores.incident || 0;
        }
        incidentCount = hexagonData.incident_count || hexagonData.incidentCount || 0;
      } else {
        // Legacy: hexagonData is just an incident count number
        incidentCount = hexagonData;
        // Fallback: estimate z-score from count (not accurate but better than nothing)
        zScore = Math.log10(Math.max(1, incidentCount));
      }
      
      // Color gradient based on z-score using Forseti theme colors
      // Cyan (safe) → Orange (caution) → Red (danger)
      let fillColor, borderColor;
      let fillOpacity = 0.6;
      
      if (zScore >= 11.0) {
        // Z ≥ 11: EXTREME DANGER - Darkest Red
        fillColor = '#b71c1c';  // Dark red
        borderColor = '#f44336';
        fillOpacity = 0.95;
      } else if (zScore >= 10.0) {
        // Z 10-11: EXTREME HIGH - Very Dark Red
        fillColor = '#c62828';  // Very dark red
        borderColor = '#f44336';
        fillOpacity = 0.92;
      } else if (zScore >= 9.0) {
        // Z 9-10: CRITICAL - Red
        fillColor = '#d32f2f';  // Red
        borderColor = '#f44336';
        fillOpacity = 0.88;
      } else if (zScore >= 8.0) {
        // Z 8-9: VERY HIGH - Bright Red
        fillColor = '#f44336';  // Forseti danger red
        borderColor = '#ff5252';
        fillOpacity = 0.85;
      } else if (zScore >= 7.0) {
        // Z 7-8: HIGH - Red/Orange blend
        fillColor = '#ff5252';  // Bright red
        borderColor = '#ff6e40';
        fillOpacity = 0.82;
      } else if (zScore >= 6.0) {
        // Z 6-7: HIGH ELEVATED - Red-Orange
        fillColor = '#ff6e40';  // Red-orange
        borderColor = '#ff9800';
        fillOpacity = 0.78;
      } else if (zScore >= 5.0) {
        // Z 5-6: ELEVATED HIGH - Deep Orange
        fillColor = '#ff7043';  // Orange-red
        borderColor = '#ff9800';
        fillOpacity = 0.75;
      } else if (zScore >= 4.0) {
        // Z 4-5: ELEVATED - Orange (Forseti caution)
        fillColor = '#ff9800';  // Forseti caution orange
        borderColor = '#ffb74d';
        fillOpacity = 0.72;
      } else if (zScore >= 3.0) {
        // Z 3-4: MODERATE-HIGH - Light Orange
        fillColor = '#ffa726';  // Light orange
        borderColor = '#ffcc80';
        fillOpacity = 0.68;
      } else if (zScore >= 2.0) {
        // Z 2-3: MODERATE - Amber
        fillColor = '#ffb74d';  // Amber
        borderColor = '#ffe082';
        fillOpacity = 0.65;
      } else if (zScore >= 1.0) {
        // Z 1-2: MODERATE LOW - Orange/Cyan transition
        fillColor = '#26c6da';  // Light cyan
        borderColor = '#4dd0e1';
        fillOpacity = 0.62;
      } else if (zScore >= 0.5) {
        // Z 0.5-1: SLIGHTLY ELEVATED - Cyan
        fillColor = '#00bcd4';  // Cyan
        borderColor = '#4dd0e1';
        fillOpacity = 0.58;
      } else if (zScore >= 0) {
        // Z 0-0.5: SLIGHTLY ABOVE AVERAGE - Forseti Cyan
        fillColor = '#00d4ff';  // Forseti primary cyan
        borderColor = '#33e0ff';
        fillOpacity = 0.55;
      } else if (zScore >= -0.5) {
        // Z -0.5 to 0: NEAR AVERAGE - Bright Cyan
        fillColor = '#33e0ff';  // Light cyan
        borderColor = '#66e8ff';
        fillOpacity = 0.52;
      } else if (zScore >= -1.0) {
        // Z -1 to -0.5: BELOW AVERAGE - Very Light Cyan
        fillColor = '#66e8ff';  // Very light cyan
        borderColor = '#99f0ff';
        fillOpacity = 0.48;
      } else if (zScore >= -1.5) {
        // Z -1.5 to -1: LOW - Pale Cyan
        fillColor = '#99f0ff';  // Pale cyan
        borderColor = '#ccf7ff';
        fillOpacity = 0.45;
      } else if (zScore >= -2.0) {
        // Z -2 to -1.5: VERY LOW - Faint Cyan
        fillColor = '#0099cc';  // Forseti cyan dark
        borderColor = '#33e0ff';
        fillOpacity = 0.42;
      } else {
        // Z < -2: EXTREMELY LOW - Dark Cyan (safest)
        fillColor = '#00acc1';  // Dark cyan
        borderColor = '#26c6da';
        fillOpacity = 0.40;
      }
      
      // Minimal mode override (Forseti cyan theme)
      if (this.minimalMode) {
        if (zScore >= 7.0) {
          fillColor = '#00d4ff';  // Forseti cyan for extreme hotspots
          fillOpacity = 0.9;
        } else if (zScore >= 3.0) {
          fillColor = '#26c6da';  // Light cyan
          fillOpacity = 0.7;
        } else if (zScore >= 0) {
          fillColor = '#4dd0e1';  // Pale cyan
          fillOpacity = 0.5;
        } else {
          fillColor = '#0099cc';  // Dark cyan (safe)
          fillOpacity = 0.3;
        }
        borderColor = '#00d4ff';
      }
      
      return {
        fillColor: fillColor,
        weight: zScore >= 5.0 ? 2 : 1,  // Thicker borders for high crime areas
        opacity: 0.8,
        color: borderColor,
        fillOpacity: fillOpacity
      };
    },
    
    /**
     * Interpolate between two hex colors
     */
    interpolateColor: function(color1, color2, ratio) {
      const hex = (color) => {
        const c = color.substring(1);
        return parseInt(c, 16);
      };
      
      const r1 = (hex(color1) >> 16) & 0xff;
      const g1 = (hex(color1) >> 8) & 0xff;
      const b1 = hex(color1) & 0xff;
      
      const r2 = (hex(color2) >> 16) & 0xff;
      const g2 = (hex(color2) >> 8) & 0xff;
      const b2 = hex(color2) & 0xff;
      
      const r = Math.round(r1 + (r2 - r1) * ratio);
      const g = Math.round(g1 + (g2 - g1) * ratio);
      const b = Math.round(b1 + (b2 - b1) * ratio);
      
      return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
    },

    /**
     * Create hover tooltip for hexagon with incident statistics and z-score
     */
    createHexagonTooltip: function(hexagon) {
      const incidentCount = hexagon.incident_count || hexagon.incidentCount || 0;
      const zScore = hexagon.analytics && hexagon.analytics.z_scores ? 
                     hexagon.analytics.z_scores.incident : null;
      const riskLevel = hexagon.analytics && hexagon.analytics.risk_level ? 
                        hexagon.analytics.risk_level : 'UNKNOWN';
      
      let zScoreText = '';
      if (zScore !== null) {
        const zScoreFormatted = zScore.toFixed(2);
        const zScoreLabel = zScore >= 2 ? 'HOTSPOT' : zScore >= 1 ? 'ELEVATED' : zScore >= 0 ? 'AVERAGE' : 'BELOW AVG';
        zScoreText = `<br><span style="color: #FFD700;">Z-Score: ${zScoreFormatted} (${zScoreLabel})</span>`;
      }
      
      return `
        <div style="padding: 5px; min-width: 150px;">
          <strong style="color: #00ff41;">${incidentCount.toLocaleString()} Incidents</strong>${zScoreText}<br>
          <span style="color: #FFA500;">Risk: ${riskLevel}</span>
        </div>
      `;
    },



    /**
     * Create hover tooltip content for hexagon (compact format) - DEPRECATED
     */
    createHoverTooltip: function(hexagon) {
      const incidentCount = hexagon.incident_count || hexagon.incidentCount || 0;
      const h3Resolution = hexagon.resolution || hexagon.h3_resolution || 'Unknown';
      const uniqueTypes = hexagon.unique_incident_types || hexagon.unique_types || 0;
      const riskLevel = this.calculateRiskLevel(incidentCount);
      
      return `
        <div class="hexagon-tooltip-content">
          <div class="tooltip-header">H3:${h3Resolution} Sector</div>
          <div class="tooltip-stats">
            <span class="stat-item"><strong>${incidentCount.toLocaleString()}</strong> incidents</span>
            <span class="stat-item"><strong>${uniqueTypes}</strong> crime types</span>
            <span class="stat-item risk-${riskLevel.toLowerCase()}"><strong>${riskLevel}</strong> risk</span>
          </div>
        </div>
      `;
    },

    /**
     * Show hexagon details in fixed bottom panel
     */
    showHexagonDetailPanel: function(hexagon) {
      console.log('🔍 showHexagonDetailPanel called', hexagon);
      
      const panel = document.getElementById('hexagon-detail-panel');
      const contentDiv = document.getElementById('hexagon-detail-content');
      
      if (!panel || !contentDiv) {
        console.error('❌ Panel elements not found!');
        return;
      }
      
      const content = this.createHexagonDetailContent(hexagon);
      contentDiv.innerHTML = content;
      
      // Simply show the panel - CSS handles all the styling
      panel.style.display = 'block';
      
      console.log('✅ Panel opened for hex:', hexagon.hex_id);
    },

    /**
     * Close hexagon detail panel
     */
    closeHexagonDetailPanel: function() {
      const panel = document.getElementById('hexagon-detail-panel');
      if (panel) {
        panel.style.display = 'none';
      }
    },

    /**
     * Create comprehensive detail content for hexagon
     */
    createHexagonDetailContent: function(hexagon) {
      // Handle null/undefined hexagon
      if (!hexagon || typeof hexagon !== 'object') {
        hexagon = {};
      }
      
      const incidentCount = hexagon.incident_count || hexagon.incidentCount || 0;
      const h3Index = hexagon.h3_index || hexagon.h3Index || 'N/A';
      const h3Resolution = hexagon.resolution || hexagon.h3_resolution || 'N/A';
      const uniqueTypes = hexagon.unique_incident_types || hexagon.unique_types || 0;
      const centerLat = hexagon.center_latitude || hexagon.center?.lat || hexagon.lat || 0;
      const centerLng = hexagon.center_longitude || hexagon.center?.lng || hexagon.lng || 0;
      
      // Calculate precision and coverage from resolution (always accurate)
      const coverageArea = this.getHexagonCoverageKm2(h3Resolution);
      const precisionLevel = this.getPrecisionLevel(h3Resolution);
      
      // Use database-calculated risk category (based on z-scores)
      const riskLevel = hexagon.analytics?.risk_level || 'N/A';
      const riskScore = hexagon.analytics?.risk_score || 0;
      
      // Extract z-scores for display
      const zScore = hexagon.analytics?.z_scores?.incident || 0;
      const violentZScore = hexagon.analytics?.z_scores?.violent || 0;
      const nonviolentZScore = hexagon.analytics?.z_scores?.nonviolent || 0;
      
      // Hotspot status
      const hotspotStatus = hexagon.analytics?.hotspot_status || 'N/A';
      
      // Temporal data
      const earliestIncident = hexagon.earliest_incident || hexagon.temporal?.earliest || 'N/A';
      const latestIncident = hexagon.latest_incident || hexagon.temporal?.latest || 'N/A';
      const last30Days = hexagon.incidents_last_30_days || hexagon.temporal?.last_30_days || 0;
      const lastYear = hexagon.incidents_last_year || hexagon.temporal?.last_year || 0;
      
      // Crime type breakdown
      const crimeTypes = hexagon.incident_type_counts || hexagon.analytics?.crime_types || {};
      const districts = hexagon.district_counts || hexagon.analytics?.districts || {};
      
      // Quality metrics
      const avgScore = hexagon.avg_data_quality_score || hexagon.quality?.avg_score || 0;
      const validRecords = hexagon.total_valid_records || hexagon.quality?.valid_records || 0;
      
      // Format dates
      const formatDate = (dateStr) => {
        if (!dateStr || dateStr === 'N/A' || dateStr === 'Unknown') return 'N/A';
        try {
          return new Date(dateStr).toLocaleDateString();
        } catch {
          return dateStr;
        }
      };
      
      // Top crime types
      const topCrimeTypes = (crimeTypes && Object.keys(crimeTypes).length > 0) 
        ? Object.entries(crimeTypes)
            .sort(([,a], [,b]) => b - a)
            .slice(0, 3)
            .map(([code, count]) => `${this.getCrimeTypeName(code)}: ${count}`)
            .join('<br>')
        : 'N/A';
      
      // Top districts
      const topDistricts = (districts && Object.keys(districts).length > 0)
        ? Object.entries(districts)
            .sort(([,a], [,b]) => b - a)
            .slice(0, 3)
            .map(([dist, count]) => `District ${dist}: ${count}`)
            .join('<br>')
        : 'N/A';
      
      return `
        <div class="hexagon-detail-inner">
          <div class="detail-header">
            <h4>H3 Resolution ${h3Resolution} Sector Analysis</h4>
            <div class="h3-index-badge">${h3Index}</div>
          </div>
          
          <div class="detail-section">
            <h5>📊 Crime Statistics</h5>
            <div class="stat-grid">
              <div class="stat-item">
                <span class="stat-label">Total Incidents:</span>
                <span class="stat-value ${riskLevel.toLowerCase()}">${incidentCount.toLocaleString()}</span>
              </div>
              <div class="stat-item">
                <span class="stat-label">Crime Types:</span>
                <span class="stat-value">${uniqueTypes}</span>
              </div>
              <div class="stat-item">
                <span class="stat-label">Risk Level:</span>
                <span class="stat-value risk-${riskLevel.toLowerCase()}">${riskLevel}</span>
              </div>
              <div class="stat-item">
                <span class="stat-label">Risk Score:</span>
                <span class="stat-value">${riskScore ? riskScore.toFixed(2) : 'N/A'}</span>
              </div>
              <div class="stat-item">
                <span class="stat-label">Z-Score:</span>
                <span class="stat-value" style="color: ${this.getZScoreColor(zScore)};">${zScore ? zScore.toFixed(2) : 'N/A'}</span>
              </div>
              <div class="stat-item">
                <span class="stat-label">Hotspot Status:</span>
                <span class="stat-value hotspot-${hotspotStatus.toLowerCase()}">${hotspotStatus}</span>
              </div>
              <div class="stat-item">
                <span class="stat-label">Last 30 Days:</span>
                <span class="stat-value">${last30Days.toLocaleString()}</span>
              </div>
            </div>
          </div>
          
          <div class="detail-section">
            <h5>📈 Statistical Breakdown</h5>
            <div class="stat-grid">
              <div class="stat-item">
                <span class="stat-label">Violent Z-Score:</span>
                <span class="stat-value" style="color: ${this.getZScoreColor(violentZScore)};">${violentZScore ? violentZScore.toFixed(2) : 'N/A'}</span>
              </div>
              <div class="stat-item">
                <span class="stat-label">Nonviolent Z-Score:</span>
                <span class="stat-value" style="color: ${this.getZScoreColor(nonviolentZScore)};">${nonviolentZScore ? nonviolentZScore.toFixed(2) : 'N/A'}</span>
              </div>
            </div>
            <div class="z-score-explanation">
              <em>Z-Score Scale: -2 (much safer than average) to 11+ (extreme danger)</em>
            </div>
          </div>
          
          <div class="detail-section">
            <h5>🌍 Geographic Details</h5>
            <div class="geo-info">
              <div><strong>Precision:</strong> ${precisionLevel}</div>
              <div><strong>Coverage Area:</strong> ${coverageArea >= 1 ? coverageArea.toFixed(2) + ' km²' : (coverageArea * 1000000).toFixed(0) + ' m²'}</div>
              <div><strong>Center:</strong> ${centerLat.toFixed(6)}, ${centerLng.toFixed(6)}</div>
            </div>
          </div>
          
          <div class="detail-section">
            <h5>⏰ Temporal Analysis</h5>
            <div class="temporal-info">
              <div><strong>Date Range:</strong> ${formatDate(earliestIncident)} - ${formatDate(latestIncident)}</div>
              <div><strong>Recent Activity:</strong> ${last30Days} incidents (30 days)</div>
              <div><strong>Annual Total:</strong> ${lastYear.toLocaleString()} incidents</div>
            </div>
          </div>
          
          <div class="detail-section">
            <h5>🔍 Top Crime Types</h5>
            <div class="crime-breakdown">${topCrimeTypes}</div>
          </div>
          
          <div class="detail-section">
            <h5>🏛️ Police Districts</h5>
            <div class="district-breakdown">${topDistricts}</div>
          </div>
          
          <div class="detail-footer">
            <div class="data-quality">Data Quality: ${(avgScore * 100).toFixed(1)}% (${validRecords.toLocaleString()} valid records)</div>
          </div>
        </div>
      `;
    },

    /**
     * Get current filter values
     */
    getCurrentFilters: function() {
      // Return current filter settings
      return this.currentFilters;
    },

    /**
     * Load citywide statistics
     */
    loadCitywideStats: function() {
      const self = this;
      console.log('📊 Loading citywide statistics...');
      
      // Debug: Check if elements exist
      console.log('🔍 Element check:', {
        citywideTotal: $('#citywide-total').length,
        citywideDistricts: $('#citywide-districts').length, 
        activeSectors: $('#active-sectors').length,
        totalIncidents: $('#total-incidents').length
      });
      
      $.ajax({
        url: '/api/amisafe/citywide-stats',
        method: 'GET',
        dataType: 'json',
        timeout: 5000,
        success: function(response) {
          console.log('✅ Stats API Response:', response);
          
          if (response && response.stats) {
            const stats = response.stats;
            
            // Update all statistics from API response (new structure)
            if (stats.total_incidents) {
              $('#citywide-total').text(parseInt(stats.total_incidents).toLocaleString());
            }
            if (stats.total_visible !== undefined) {
              $('#total-incidents').text(parseInt(stats.total_visible).toLocaleString());
            }
            if (stats.violent_crimes) {
              $('#violent-crimes').text(parseInt(stats.violent_crimes).toLocaleString());
            }
            if (stats.property_crimes) {
              $('#property-crimes').text(parseInt(stats.property_crimes).toLocaleString());
            }
            
            // Store actual percentages from dataset for visible calculations
            self.crimePercentages = {
              violent: response.crime_percentages ? (response.crime_percentages.violent / 100) : 
                (parseInt(stats.violent_crimes) / parseInt(stats.total_incidents)),
              property: response.crime_percentages ? (response.crime_percentages.property / 100) : 
                (parseInt(stats.property_crimes) / parseInt(stats.total_incidents))
            };
            
            console.log('📊 All citywide stats updated from API:', {
              citywide: stats.total_incidents,
              visible: stats.total_visible,
              violent: stats.violent_crimes + ' (' + Math.round(self.crimePercentages.violent * 100) + '%)',
              property: stats.property_crimes + ' (' + Math.round(self.crimePercentages.property * 100) + '%)',
              property: stats.property_crimes
            });
          } else {
            console.warn('⚠️ Invalid stats response structure');
          }
        },
        error: function(xhr, status, error) {
          console.error('❌ Stats API Error:', status, error);
          
          // Use fallback data if API fails - but still show loading state
          $('#citywide-total').text('API Error');
          $('#total-incidents').text('0');
          $('#violent-crimes').text('0');
          $('#property-crimes').text('0');
          
          console.log('📊 Using fallback stats due to API error');
        }
      });
    },

    /**
     * Show loading overlay
     */
    showLoading: function(message, status) {
      const overlay = $('#loading-overlay');
      overlay.find('.loading-text').text(message || 'LOADING...');
      if (status) {
        overlay.find('.loading-status').text(status).show();
      } else {
        overlay.find('.loading-status').hide();
      }
      overlay.removeClass('d-none').show();
    },

    /**
     * Hide loading overlay
     */
    hideLoading: function() {
      // Check if map has been properly initialized
      if (!this.map) {
        console.error('❌ Map not initialized when hideLoading called');
      } else if (this.hexagonLayer && this.hexagonLayer.getLayers().length === 0) {
        console.warn('⚠️ No hexagons rendered when hideLoading called');
      }
      
      $('#loading-overlay').addClass('d-none').fadeOut(300);
    },

    /**
     * Reset map view to initial position
     */
    resetView: function() {
      const mapConfig = this.settings.mapConfig;
      this.map.setView(mapConfig.center, mapConfig.zoom);
      console.log('🏠 View reset to initial position');
    },

    /**
     * Manually fit map to show all hexagons
     */
    fitMapToHexagons: function() {
      if (this.hexagonLayer && this.hexagonLayer.getLayers().length > 0) {
        const allBounds = L.latLngBounds();
        let hexagonCount = 0;
        
        this.hexagonLayer.eachLayer(function(layer) {
          if (layer.getBounds) {
            allBounds.extend(layer.getBounds());
            hexagonCount++;
          }
        });
        
        if (hexagonCount > 0 && allBounds.isValid()) {
          this.map.fitBounds(allBounds, {
            padding: [20, 20],
            maxZoom: 15
          });
          console.log(`📍 Manually fitted map to show ${hexagonCount} hexagons`);
        } else {
          console.log('⚠️ No hexagons available to fit map view');
        }
      } else {
        console.log('⚠️ No hexagon layer available for fitting');
      }
    },

    /**
     * Load filter options from API
     */
    loadFilterOptions: function() {
      const self = this;
      
      // Load crime types
      $.ajax({
        url: '/api/amisafe/crime-types',
        method: 'GET',
        success: function(data) {
          // Handle API response format - extract crime_types array from response
          const crimeTypes = data.crime_types || data || [];
          console.log('📊 Crime types data received:', crimeTypes);
          self.populateCrimeTypes(crimeTypes);
        },
        error: function() {
          console.warn('Failed to load crime types, using defaults');
          self.populateCrimeTypes(self.getDefaultCrimeTypes());
        }
      });
      
      // Load districts
      $.ajax({
        url: '/api/amisafe/districts',
        method: 'GET',
        success: function(data) {
          // Handle API response format - extract districts array from response
          const districts = data.districts || data || [];
          console.log('🏘️ Districts data received:', districts);
          self.populateDistricts(districts);
        },
        error: function() {
          console.warn('Failed to load districts, using defaults');
          self.populateDistricts(self.getDefaultDistricts());
        }
      });
    },

    /**
     * Get default crime types if API fails
     */
    getDefaultCrimeTypes: function() {
      return [
        { value: '100', label: 'HOMICIDE' },
        { value: '200', label: 'ASSAULT' },
        { value: '300', label: 'ROBBERY' },
        { value: '400', label: 'BURGLARY' },
        { value: '500', label: 'THEFT' },
        { value: '600', label: 'AUTO THEFT' },
        { value: '700', label: 'VANDALISM' },
        { value: '800', label: 'DRUGS' },
        { value: '900', label: 'WEAPON OFFENSE' },
        { value: '1000', label: 'FRAUD' }
      ];
    },

    /**
     * Get default districts if API fails
     */
    getDefaultDistricts: function() {
      return [
        { value: '1', label: 'DISTRICT 1 - CENTER CITY' },
        { value: '2', label: 'DISTRICT 2 - SOUTH' },
        { value: '3', label: 'DISTRICT 3 - WEST' },
        { value: '4', label: 'DISTRICT 4 - NORTH' },
        { value: '5', label: 'DISTRICT 5 - NORTHEAST' },
        { value: '6', label: 'DISTRICT 6 - NORTHWEST' }
      ];
    },

    /**
     * Populate crime type selector
     */
    populateCrimeTypes: function(crimeTypes) {
      const selector = $('#crime-type-selector');
      selector.empty();
      
      let processedTypes = [];
      
      // Handle different data formats
      if (Array.isArray(crimeTypes)) {
        // Already an array of objects with value/label
        processedTypes = crimeTypes;
      } else if (typeof crimeTypes === 'object' && crimeTypes !== null) {
        // Object format: {100: 'Murder', 200: 'Rape', ...}
        processedTypes = Object.entries(crimeTypes).map(([key, value]) => ({
          value: key,
          label: value
        }));
      } else {
        console.warn('Crime types data format not recognized:', crimeTypes);
        processedTypes = this.getDefaultCrimeTypes();
      }
      
      processedTypes.forEach(type => {
        // Don't auto-select all - let user choose what to filter
        selector.append(`<option value="${type.value}">${type.label}</option>`);
      });
      
      // DON'T update currentFilters here - let it stay empty (meaning "show all")
      console.log('✅ Crime types populated:', processedTypes.length, 'types');
    },

    /**
     * Populate district selector
     */
    populateDistricts: function(districts) {
      const selector = $('#district-selector');
      selector.empty();
      
      let processedDistricts = [];
      
      // Handle different data formats
      if (Array.isArray(districts)) {
        // Check if first element has value/label structure
        if (districts.length > 0 && typeof districts[0] === 'object' && districts[0].value) {
          // Already an array of objects with value/label
          processedDistricts = districts;
        } else {
          // Simple array of strings/numbers: ['1', '2', '3', ...]
          processedDistricts = districts.map(district => ({
            value: district,
            label: `DISTRICT ${district}`
          }));
        }
      } else {
        console.warn('Districts data format not recognized:', districts);
        processedDistricts = this.getDefaultDistricts();
      }
      
      processedDistricts.forEach(district => {
        // Don't auto-select all - let user choose what to filter
        selector.append(`<option value="${district.value}">${district.label}</option>`);
      });
      
      // DON'T update currentFilters here - let it stay empty (meaning "show all")
      console.log('✅ Districts populated:', processedDistricts.length, 'districts');
    },

    /**
     * Apply current filters
     */
    applyFilters: function() {
      // Collect filter values
      this.currentFilters.crimeTypes = $('#crime-type-selector').val() || [];
      this.currentFilters.districts = $('#district-selector').val() || [];
      this.currentFilters.dateRange = $('#date-range-filter').val() || 'alltime';
      this.currentFilters.timePeriods = $('#time-period-selector').val() || [];
      
      console.log('🔍 Applying filters:', this.currentFilters);
      console.log('🎯 Filter summary:', {
        crimeTypes: this.currentFilters.crimeTypes.length,
        districts: this.currentFilters.districts.length,
        dateRange: this.currentFilters.dateRange,
        timePeriods: this.currentFilters.timePeriods.length
      });
      
      // Clear existing data to show filter changes
      if (this.hexagonLayer) {
        this.hexagonLayer.clearLayers();
      }
      
      // Show loading
      this.showLoading('APPLYING FILTERS...');
      
      // Reload data with filters - force fresh load
      this.loadHexagonDataWithFilters();
      
      // Update stats with filtered data
      this.updateStats();
    },

    /**
     * Clear all filters to default state (12 months, no filters)
     */
    clearAllFilters: function() {
      // Clear all selectors (deselect all options = show all data)
      $('#crime-type-selector option').prop('selected', false);
      $('#district-selector option').prop('selected', false);
      $('#date-range-filter').val('alltime');
      $('#time-period-selector option').prop('selected', true);
      
      // Reset preset button states to 12 months
      $('.preset-btn').removeClass('active');
      $('#preset-12-months').addClass('active');
      
      // Apply cleared filters
      this.applyFilters();
      
      console.log('🔄 All filters cleared - showing all data (last 12 months)');
    },

    /**
     * Set date range presets
     */
    setDatePreset: function(preset) {
      // Update hidden input value
      $('#date-range-filter').val(preset);
      
      // Update button states
      $('.preset-btn').removeClass('active');
      $(`.preset-btn[data-preset="${preset}"]`).addClass('active');
      
      // Update current filters
      this.currentFilters.dateRange = preset;
      
      const presetLabels = {
        '12months': 'Last 12 Months',
        '6months': 'Last 6 Months',
        'alltime': 'All Time'
      };
      
      console.log(`📅 Date preset applied: ${presetLabels[preset]} (using pre-calculated DB values)`);
    },

    /**
     * Apply quick filter presets
     */
    applyPreset: function(preset) {
      console.log('⚡ Applying preset:', preset);
      
      // Clear current selections first
      $('#crime-type-selector option').prop('selected', false);
      
      switch (preset) {
        case 'violent':
          // Select violent crime types
          $('#crime-type-selector option[value="100"], #crime-type-selector option[value="200"], #crime-type-selector option[value="300"], #crime-type-selector option[value="900"]').prop('selected', true);
          break;
          
        case 'property':
          // Select property crime types
          $('#crime-type-selector option[value="400"], #crime-type-selector option[value="500"], #crime-type-selector option[value="600"], #crime-type-selector option[value="700"]').prop('selected', true);
          break;
          
        case 'recent':
          // Select all crime types but limit to recent 30 days
          $('#crime-type-selector option').prop('selected', true);
          this.setDatePreset('lastMonth');
          break;
      }
      
      // Apply the preset filters
      this.applyFilters();
    },

    /**
     * Switch view mode
     */
    switchViewMode: function(mode) {
      console.log('🔄 Switching to view mode:', mode);
      this.currentFilters.viewMode = mode;
      
      // Hide all layers first
      if (this.hexagonLayer) this.map.removeLayer(this.hexagonLayer);
      if (this.heatmapLayer) this.map.removeLayer(this.heatmapLayer);
      if (this.incidentLayer) this.map.removeLayer(this.incidentLayer);
      
      // Show selected layer
      switch (mode) {
        case 'hexagon':
          if (this.hexagonLayer) this.map.addLayer(this.hexagonLayer);
          break;
        case 'heatmap':
          this.loadHeatmapData();
          break;
        case 'points':
          this.loadPointsData();
          break;
      }
    },

    /**
     * Load heatmap data
     */
    loadHeatmapData: function() {
      console.log('🔥 Loading heatmap data...');
      this.showLoading('GENERATING HEATMAP...');
      
      const self = this;
      const bounds = this.map.getBounds();
      
      // Prepare API parameters
      const apiData = {
        bounds: `${bounds.getNorth()},${bounds.getEast()},${bounds.getSouth()},${bounds.getWest()}`,
        limit: 2000,
        page: 0
      };

      // Add filters if they exist
      if (this.currentFilters.crimeTypes && this.currentFilters.crimeTypes.length > 0) {
        apiData.crime_types = this.currentFilters.crimeTypes.join(',');
      }
      if (this.currentFilters.districts && this.currentFilters.districts.length > 0) {
        apiData.districts = this.currentFilters.districts.join(',');
      }
      if (this.currentFilters.dateRange) {
        apiData.date_range = this.currentFilters.dateRange;
      }
      
      $.ajax({
        url: '/api/amisafe/incidents',
        method: 'GET',
        data: apiData,
        success: function(data) {
          const incidents = data.incidents || data || [];
          self.createHeatmapLayer(incidents);
          self.hideLoading();
          console.log('🔥 Heatmap data loaded:', incidents.length, 'points');
        },
        error: function(xhr, status, error) {
          self.hideLoading();
          console.warn('Failed to load heatmap data:', status, error);
          console.log('🔥 Using mock heatmap data instead');
          self.createMockHeatmap();
        }
      });
    },

    /**
     * Load points data
     */
    loadPointsData: function() {
      console.log('📍 Loading individual incident points...');
      this.showLoading('LOADING INCIDENT POINTS...');
      
      const self = this;
      const bounds = this.map.getBounds();
      const zoom = this.map.getZoom();
      
      // Only load individual points at ultra-precision zoom levels (19+)
      if (zoom < 19) {
        this.hideLoading();
        console.log('📍 Zoom too low for individual points (requires zoom 19+), showing aggregated data instead');
        this.switchViewMode('hexagon');
        return;
      }
      
      // Prepare API parameters
      const apiData = {
        bounds: `${bounds.getNorth()},${bounds.getEast()},${bounds.getSouth()},${bounds.getWest()}`,
        limit: 500,
        page: 0
      };

      // Add filters if they exist
      if (this.currentFilters.crimeTypes && this.currentFilters.crimeTypes.length > 0) {
        apiData.crime_types = this.currentFilters.crimeTypes.join(',');
      }
      if (this.currentFilters.districts && this.currentFilters.districts.length > 0) {
        apiData.districts = this.currentFilters.districts.join(',');
      }
      if (this.currentFilters.dateRange) {
        apiData.date_range = this.currentFilters.dateRange;
      }

      $.ajax({
        url: '/api/amisafe/incidents',
        method: 'GET',
        data: apiData,
        success: function(data) {
          const incidents = data.incidents || data || [];
          self.createPointsLayer(incidents);
          self.hideLoading();
          console.log('📍 Points data loaded:', incidents.length, 'incidents');
        },
        error: function(xhr, status, error) {
          self.hideLoading();
          console.warn('Failed to load points data:', status, error);
          console.log('📍 Using mock points data instead');
          self.createMockPoints();
        }
      });
    },





    /**
     * Update statistics display
     */
    updateStats: function() {
      // Update current view stats
      const totalIncidents = this.getCurrentIncidentCount();
      const threatLevel = this.calculateThreatLevel();
      const activeSectors = this.getActiveSectorCount();
      
      $('#total-incidents').text(totalIncidents.toLocaleString());
      $('#threat-level').text(threatLevel);
      $('#active-sectors').text(activeSectors);
      
      // Load citywide stats
      this.loadCitywideStats();
    },

    /**
     * Update visible incidents count and crime type breakdown from hexagon data
     */
    updateVisibleIncidentsCount: function(hexagons) {
      // Only update if statistics haven't been loaded from API yet
      const currentTotal = $('#total-incidents').text();
      if (currentTotal === '0' || currentTotal === 'Loading...') {
        let totalVisible = 0;
        
        if (hexagons && hexagons.length > 0) {
          hexagons.forEach(function(hexagon) {
            if (hexagon.incident_count) {
              totalVisible += parseInt(hexagon.incident_count, 10) || 0;
            }
          });
        }
        
        // Calculate crime type breakdown using actual dataset percentages
        const violentPercentage = this.crimePercentages ? this.crimePercentages.violent : 0.25;
        const propertyPercentage = this.crimePercentages ? this.crimePercentages.property : 0.70;
        
        const violentCrimes = Math.round(totalVisible * violentPercentage);
        const propertyCrimes = Math.round(totalVisible * propertyPercentage);
        
        // Update the visible statistics display (fallback when API not loaded)
        $('#total-incidents').text(totalVisible.toLocaleString());
        $('#violent-crimes').text(violentCrimes.toLocaleString());
        $('#property-crimes').text(propertyCrimes.toLocaleString());
        
        console.log(`📊 Updated visible stats from hexagons (using dataset percentages):`, {
          visible: totalVisible.toLocaleString(),
          violent: violentCrimes.toLocaleString() + ' (' + Math.round(violentPercentage * 100) + '%)',
          property: propertyCrimes.toLocaleString() + ' (' + Math.round(propertyPercentage * 100) + '%)'
        });
      } else {
        console.log(`📊 Skipping hexagon stats update - using API-loaded statistics`);
      }
    },

    /**
     * Get current incident count
     */
    getCurrentIncidentCount: function() {
      // Calculate from current hexagon data
      let total = 0;
      if (this.hexagonLayer) {
        this.hexagonLayer.eachLayer(function(layer) {
          if (layer.options && layer.options.incidentCount) {
            total += layer.options.incidentCount;
          }
        });
      }
      return total;
    },

    /**
     * Calculate threat level
     */
    calculateThreatLevel: function() {
      const incidentCount = this.getCurrentIncidentCount();
      const sectorCount = this.getActiveSectorCount();
      
      if (sectorCount === 0) return 'MINIMAL';
      
      const avgIncidentsPerSector = incidentCount / sectorCount;
      
      if (avgIncidentsPerSector > 50) return 'EXTREME';
      if (avgIncidentsPerSector > 30) return 'CRITICAL';
      if (avgIncidentsPerSector > 15) return 'HIGH';
      if (avgIncidentsPerSector > 5) return 'MODERATE';
      return 'LOW';
    },

    /**
     * Get active sector count
     */
    getActiveSectorCount: function() {
      return this.hexagonLayer ? this.hexagonLayer.getLayers().length : 0;
    },

    /**
     * Create heatmap layer from incident data
     */
    createHeatmapLayer: function(incidents) {
      // Remove existing heatmap layer
      if (this.heatmapLayer) {
        this.map.removeLayer(this.heatmapLayer);
      }
      
      // Check if Leaflet heatmap plugin is available
      if (typeof L.heatLayer === 'undefined') {
        console.warn('Leaflet heatmap plugin not available, using fallback visualization');
        this.createHeatmapFallback(incidents);
        return;
      }
      
      // Convert incidents to heatmap points
      const heatPoints = incidents.map(incident => [
        parseFloat(incident.latitude),
        parseFloat(incident.longitude),
        parseFloat(incident.severity || 1)
      ]);
      
      // Create heatmap layer
      this.heatmapLayer = L.heatLayer(heatPoints, {
        radius: 25,
        blur: 15,
        maxZoom: 17,
        gradient: {
          0.0: '#0099ff',
          0.3: '#00ff66', 
          0.5: '#ffaa00',
          0.7: '#ff6600',
          1.0: '#ff0000'
        }
      });
      
      this.map.addLayer(this.heatmapLayer);
    },

    /**
     * Fallback heatmap using circle markers
     */
    createHeatmapFallback: function(incidents) {
      this.heatmapLayer = L.layerGroup();
      
      incidents.forEach(incident => {
        const severity = parseInt(incident.severity || 1);
        const color = this.getSeverityColor(severity);
        
        const circle = L.circle([incident.latitude, incident.longitude], {
          radius: 50 + (severity * 20),
          fillColor: color,
          color: color,
          weight: 1,
          opacity: 0.3,
          fillOpacity: 0.2
        });
        
        this.heatmapLayer.addLayer(circle);
      });
      
      this.map.addLayer(this.heatmapLayer);
    },

    /**
     * Create mock heatmap for testing
     */
    createMockHeatmap: function() {
      // Check if Leaflet heatmap plugin is available
      if (typeof L.heatLayer === 'undefined') {
        console.warn('Leaflet heatmap plugin not available, using mock fallback');
        this.createMockHeatmapFallback();
        return;
      }
      
      const mockPoints = [];
      const center = this.map.getCenter();
      
      // Generate random points around the center
      for (let i = 0; i < 100; i++) {
        mockPoints.push([
          center.lat + (Math.random() - 0.5) * 0.02,
          center.lng + (Math.random() - 0.5) * 0.02,
          Math.random() * 5
        ]);
      }
      
      this.heatmapLayer = L.heatLayer(mockPoints, {
        radius: 25,
        blur: 15,
        maxZoom: 17
      });
      
      this.map.addLayer(this.heatmapLayer);
    },

    /**
     * Mock heatmap fallback using circles
     */
    createMockHeatmapFallback: function() {
      this.heatmapLayer = L.layerGroup();
      const center = this.map.getCenter();
      
      for (let i = 0; i < 50; i++) {
        const severity = Math.floor(Math.random() * 5) + 1;
        
        const circle = L.circle([
          center.lat + (Math.random() - 0.5) * 0.02,
          center.lng + (Math.random() - 0.5) * 0.02
        ], {
          radius: 50 + (severity * 20),
          fillColor: this.getSeverityColor(severity),
          color: this.getSeverityColor(severity),
          weight: 1,
          opacity: 0.3,
          fillOpacity: 0.2
        });
        
        this.heatmapLayer.addLayer(circle);
      }
      
      this.map.addLayer(this.heatmapLayer);
    },

    /**
     * Create points layer from incident data
     */
    createPointsLayer: function(incidents) {
      // Remove existing points layer
      if (this.incidentLayer) {
        this.map.removeLayer(this.incidentLayer);
      }
      
      this.incidentLayer = L.layerGroup();
      
      incidents.forEach(incident => {
        const severity = parseInt(incident.severity || 1);
        const color = this.getSeverityColor(severity);
        
        const marker = L.circleMarker([incident.latitude, incident.longitude], {
          radius: 4 + severity,
          fillColor: color,
          color: '#fff',
          weight: 1,
          opacity: 0.8,
          fillOpacity: 0.7
        });
        
        // Add popup with incident details
        marker.bindPopup(`
          <div class="incident-popup">
            <h4>${incident.crime_type || 'Unknown Crime'}</h4>
            <p><strong>Date:</strong> ${incident.incident_date || 'Unknown'}</p>
            <p><strong>District:</strong> ${incident.district || 'Unknown'}</p>
            <p><strong>Severity:</strong> Level ${severity}</p>
          </div>
        `);
        
        this.incidentLayer.addLayer(marker);
      });
      
      this.map.addLayer(this.incidentLayer);
    },

    /**
     * Create mock points for testing
     */
    createMockPoints: function() {
      this.incidentLayer = L.layerGroup();
      const center = this.map.getCenter();
      
      const crimeTypes = ['THEFT', 'ASSAULT', 'BURGLARY', 'VANDALISM', 'ROBBERY'];
      
      for (let i = 0; i < 50; i++) {
        const severity = Math.floor(Math.random() * 5) + 1;
        const crimeType = crimeTypes[Math.floor(Math.random() * crimeTypes.length)];
        
        const marker = L.circleMarker([
          center.lat + (Math.random() - 0.5) * 0.01,
          center.lng + (Math.random() - 0.5) * 0.01
        ], {
          radius: 4 + severity,
          fillColor: this.getSeverityColor(severity),
          color: '#fff',
          weight: 1,
          opacity: 0.8,
          fillOpacity: 0.7
        });
        
        marker.bindPopup(`
          <div class="incident-popup">
            <h4>${crimeType}</h4>
            <p><strong>Severity:</strong> Level ${severity}</p>
            <p><strong>Status:</strong> Mock Data</p>
          </div>
        `);
        
        this.incidentLayer.addLayer(marker);
      }
      
      this.map.addLayer(this.incidentLayer);
    },

    /**
     * Get severity color mapping
     */
    getSeverityColor: function(severity) {
      const colors = {
        1: '#0099ff', // Low - Blue
        2: '#00ff66', // Moderate - Green  
        3: '#ffaa00', // Medium - Orange
        4: '#ff6600', // High - Red-Orange
        5: '#ff0000'  // Critical - Red
      };
      return colors[severity] || '#888888';
    },

    /**
     * Clear all visualization layers
     */
    clearVisualizationLayers: function() {
      // Clear hexagon layer
      if (this.hexagonLayer) {
        this.map.removeLayer(this.hexagonLayer);
        this.hexagonLayer = null;
      }
      
      // Clear heatmap layer
      if (this.heatmapLayer) {
        this.map.removeLayer(this.heatmapLayer);
        this.heatmapLayer = null;
      }
      
      // Clear incident layer
      if (this.incidentLayer) {
        this.map.removeLayer(this.incidentLayer);
        this.incidentLayer = null;
      }
    },

    /**
     * Update layer visibility based on current mode
     */
    updateLayerVisibility: function() {
      const mode = this.currentViewMode;
      
      // Show/hide layers based on current mode
      if (this.hexagonLayer) {
        if (mode === 'hexagon') {
          this.map.addLayer(this.hexagonLayer);
        } else {
          this.map.removeLayer(this.hexagonLayer);
        }
      }
      
      if (this.heatmapLayer) {
        if (mode === 'heatmap') {
          this.map.addLayer(this.heatmapLayer);
        } else {
          this.map.removeLayer(this.heatmapLayer);
        }
      }
      
      if (this.incidentLayer) {
        if (mode === 'points') {
          this.map.addLayer(this.incidentLayer);
        } else {
          this.map.removeLayer(this.incidentLayer);
        }
      }
    },

    /**
     * Get color for z-score display (matches gradient scale)
     */
    getZScoreColor: function(zScore) {
      if (zScore >= 11) return '#8B0000'; // Dark red - EXTREME
      if (zScore >= 8) return '#DC143C';  // Crimson - HIGH
      if (zScore >= 5) return '#FF4500';  // Orange-red - ELEVATED
      if (zScore >= 2) return '#FFA500';  // Orange - MODERATE
      if (zScore >= 0) return '#FFFF00';  // Yellow - AVERAGE
      if (zScore >= -1) return '#99FF00'; // Light lime - BELOW AVERAGE
      return '#32CD32';                    // Green - SAFE
    },

    /**
     * Get human-readable crime type name from UCR code
     */
    getCrimeTypeName: function(code) {
      // Convert to string and handle different formats
      code = String(code).trim();
      
      // Philadelphia PD UCR General Codes
      const crimeTypeMap = {
        // Part I Crimes - Serious Offenses
        '100': 'Murder',
        '200': 'Rape',
        '300': 'Robbery',
        '400': 'Aggravated Assault',
        '500': 'Burglary',
        '600': 'Larceny/Theft',
        '700': 'Auto Theft',
        '800': 'Simple Assault',
        '900': 'Arson',
        
        // Part II Crimes
        '1000': 'Forgery',
        '1100': 'Fraud',
        '1200': 'Embezzlement',
        '1300': 'Stolen Property',
        '1400': 'Vandalism',
        '1500': 'Weapons Violation',
        '1600': 'Prostitution',
        '1700': 'Sex Offense',
        '1800': 'Drug Violation',
        '1900': 'Gambling',
        '2000': 'Offense Against Family',
        '2100': 'DUI',
        '2200': 'Liquor Law Violation',
        '2300': 'Drunkenness',
        '2400': 'Disorderly Conduct',
        '2500': 'Vagrancy',
        '2600': 'Other Offense',
        '2700': 'Suspicious Activity',
        
        // Legacy short codes
        'BURG': 'Burglary',
        'THEF': 'Theft',
        'ROBB': 'Robbery',
        'VIOL': 'Violence',
        'DRUG': 'Drug Offense',
        'VAND': 'Vandalism',
        'ASSA': 'Assault',
        'WEAP': 'Weapons',
        'FRAU': 'Fraud',
        'MISC': 'Other',
        'AUTO': 'Auto Theft',
        'PROS': 'Prostitution',
        'GAMB': 'Gambling',
        'LIQR': 'Liquor Law',
        'DISR': 'Disorderly Conduct',
        'TRAF': 'Traffic'
      };
      
      return crimeTypeMap[code] || `Code ${code}`;
    }

  };

})(jQuery, Drupal, drupalSettings);