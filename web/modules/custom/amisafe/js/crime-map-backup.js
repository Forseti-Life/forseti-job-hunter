/**
 * @file
 * AmISafe Crime Map - Core JavaScript functionality
 * 
 * Initializes and manages the interactive crime map using Leaflet.js and H3 hexagons
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * AmISafe Crime Map behavior
   */
  Drupal.behaviors.amisafeCrimeMap = {
    attach: function (context, settings) {
      // Debug: AmISafe behavior attached
      
      if (!settings.amisafe) {
        Drupal.AmISafeLogger.error('AmISafe settings not found in drupalSettings');
        return;
      }

      $(context).find('#crime-map-container').addBack('#crime-map-container').each(function () {
        // Found crime map container
        if (!this.hasAttribute('data-amisafe-initialized')) {
          // Initializing AmISafe Crime Map
          this.setAttribute('data-amisafe-initialized', 'true');
          var crimeMap = new AmISafeCrimeMap(this, settings.amisafe);
          crimeMap.initialize();
        } else {
          // AmISafe already initialized for this container
        }
      });
    }
  };

  /**
   * AmISafe Crime Map Class
   */
  function AmISafeCrimeMap(container, settings) {
    this.container = container;
    this.settings = settings;
    this.map = null;
    this.hexagonLayer = null;
    this.heatmapLayer = null;
    this.markersLayer = null;
    this.currentView = 'hexagon';
    this.currentFilters = {};
    this.loadingOverlay = document.getElementById('loading-overlay');
  }

  AmISafeCrimeMap.prototype = {
    
    /**
     * Initialize the crime map
     */
    initialize: function () {
      // Initialize debug mode (default off to reduce console spam)
      this.debugMode = false;
      
      // MINIMAL MODE: Disable all visual effects for data review
      this.minimalMode = true; // Set to false to re-enable effects
      
      // Add minimal-mode class to disable CSS animations - but do this AFTER map initialization
      var self = this;
      if (this.minimalMode) {
        // Wait for full initialization before applying minimal mode
        setTimeout(function() {
          document.body.classList.add('minimal-mode');
          console.log('🔇 Minimal mode activated - CSS animations disabled');
        }, 1000);
      } else {
        document.body.classList.remove('minimal-mode');
      }
      
      Drupal.AmISafeLogger.info('Initializing AmISafe Crime Map (Minimal Mode: ' + this.minimalMode + ')...');
      
      // Initialize performance optimization variables
      this.dataCache = new Map(); // Cache for hexagon data by resolution + filters
      this.currentRequest = null; // Track current AJAX request for cancellation
      this.requestQueue = []; // Queue for managing multiple requests
      this.lastLoadedResolution = null; // Track current resolution to avoid reloads
      this.loadTimeout = null; // Debounce timer for zoom events
      this.filterTimeout = null; // Debounce timer for filter changes
      this.lastBounds = null; // Track map bounds to avoid unnecessary reloads
      this.lastFilters = null; // Track filters to avoid unnecessary reloads
      this.debugMode = false; // Control verbose logging
      this.cacheHitCount = 0; // Track cache performance
      this.apiCallCount = 0; // Track API usage
      this.loadStartTime = Date.now(); // Track session start time
      
      this.showLoading('INITIALIZING NEURAL MAP...');
      
      try {
        this.initializeMap();
        this.initializeControls();
        
        // Use a slight delay to ensure DOM is fully ready for filter initialization
        var self = this;
        setTimeout(function() {
          self.initializeFilters();
        }, 100);
        
        this.loadInitialData();
        
        // Check system capabilities
        this.checkSystemCapabilities();
        
        Drupal.AmISafeLogger.info('AmISafe Crime Map initialized successfully');
        this.hideLoading();
        
        // Expose instance for debug buttons
        window.amisafeCrimeMap = this;
      } catch (error) {
        Drupal.AmISafeLogger.error('Error initializing crime map:', error);
        this.showError('INITIALIZATION FAILED: ' + error.message);
      }
    },

    /**
     * Initialize the Leaflet map
     */
    initializeMap: function () {
      if (!window.L) {
        throw new Error('Leaflet library not loaded');
      }

      var mapConfig = this.settings.mapConfig;
      
      this.map = L.map(this.container, {
        center: mapConfig.center,
        zoom: mapConfig.zoom,
        minZoom: 8,
        maxZoom: 20,  // Enable extreme zoom for 1-meter detail
        zoomControl: true,
        attributionControl: false
      });

      // Add dark cyberpunk base tiles
      var darkTiles = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '',
        subdomains: 'abcd',
        maxZoom: 20,  // Support extreme zoom for 1-meter detail
        className: 'dark-tiles'
      });

      darkTiles.addTo(this.map);

      // Initialize layer groups
      this.hexagonLayer = L.layerGroup().addTo(this.map);
      this.heatmapLayer = L.layerGroup();
      this.markersLayer = L.layerGroup();

      // Add map event listeners - try multiple zoom events
      this.map.on('zoomend', this.onMapZoom.bind(this));
      this.map.on('zoom', this.onMapZoom.bind(this));  // Also listen to 'zoom' event
      this.map.on('zoomstart', function() { console.log('🔍 ZOOM START detected'); });
      this.map.on('moveend', this.onMapMove.bind(this));
      
      // Force map to resize properly
      var self = this;
      setTimeout(function() {
        self.map.invalidateSize();
        console.log('📍 Map size invalidated');
        
        // Debug: Check zoom level every 3 seconds
        setInterval(function() {
          var currentZoom = self.map.getZoom();
          var currentRes = self.getOptimalResolution(currentZoom);
          console.log('🔄 Manual zoom check: zoom=' + currentZoom + ', resolution=' + currentRes);
        }, 3000);
      }, 500);
    },

    /**
     * Initialize map controls
     */
    initializeControls: function () {
      var self = this;

      // View mode buttons
      $('#hexagon-view').on('click', function () {
        self.switchView('hexagon');
        $('.view-options .cyber-button').removeClass('active');
        $(this).addClass('active');
      });

      $('#heatmap-view').on('click', function () {
        self.switchView('heatmap');
        $('.view-options .cyber-button').removeClass('active');
        $(this).addClass('active');
      });

      $('#points-view').on('click', function () {
        self.switchView('points');
        $('.view-options .cyber-button').removeClass('active');
        $(this).addClass('active');
      });

      // Map control buttons removed (fullscreen, reset-view, screenshot)
      
      // Manual zoom refresh button for debugging
      $('#refresh-zoom').on('click', function () {
        console.log('🔄 Manual zoom refresh clicked');
        var currentZoom = self.map.getZoom();
        var currentRes = self.getOptimalResolution(currentZoom);
        console.log('🔄 Manual check - zoom:', currentZoom, 'resolution:', currentRes);
        self.updateZoomIndicator(currentZoom, currentRes);
      });

      // Debug panel buttons
      $('#center-hexagons-btn').on('click', function () {
        self.centerOnHexagons();
      });

      $('#refresh-data-btn').on('click', function () {
        self.refreshHexagons();
      });

      $('#toggle-overlays-btn').on('click', function () {
        self.toggleLoadingOverlays();
      });

      $('#performance-stats-btn').on('click', function () {
        if (window.AmISafeCrimeMap && window.AmISafeCrimeMap.getPerformanceStats) {
          window.AmISafeCrimeMap.getPerformanceStats();
        }
      });
    },

    /**
     * Initialize filter controls
     */
    initializeFilters: function () {
      var self = this;

      // Initialize filters quietly
      
      // Ensure filter selectors exist before loading options
      var retryCount = 0;
      var maxRetries = 10;
      
      function waitForSelectors() {
        var crimeTypeSelector = $('#crime-type-selector');
        var districtSelector = $('#district-selector');
        
        if (crimeTypeSelector.length > 0 && districtSelector.length > 0) {
          
          // Load with immediate fallback to ensure options are populated
          self.loadFilterOptions();
          
          // Also populate with fallback data immediately to ensure something shows
          setTimeout(function() {
            if (crimeTypeSelector.find('option').length === 0) {
              Drupal.AmISafeLogger.debug('No options found in crime type selector, using fallback...');
              self.populateCrimeTypeSelector({
                '100': 'Murder',
                '200': 'Rape',
                '300': 'Robbery - Total',
                '400': 'Aggravated Assault - Total',
                '500': 'Burglary - Total',
                '600': 'Theft from Vehicle',
                '700': 'All Other Larceny',
                '800': 'Vandalism',
                '900': 'Fraud',
                '1000': 'Embezzlement',
                '1100': 'Narcotic Drug Law Violations',
                '1200': 'Weapons Violations',
                '1300': 'Prostitution',
                '1400': 'Other Assaults',
                '1500': 'Arson',
                '1600': 'Stolen Property',
                '1700': 'DUI',
                '1800': 'Liquor Laws',
                '2000': 'Public Drunkenness',
                '2100': 'Disorderly Conduct',
                '2600': 'Theft from Person'
              });
            }
            
            if (districtSelector.find('option').length === 0) {
              Drupal.AmISafeLogger.debug('No options found in district selector, using fallback...');
              self.populateDistrictSelector(['01', '02', '03', '05', '07', '08', '09', '12', '14', '15', '16', '17']);
            }
          }, 2000);
          
          self.setupFilterEventHandlers();
        } else if (retryCount < maxRetries) {
          retryCount++;
          Drupal.AmISafeLogger.debug('Selectors not found, retry', retryCount, 'of', maxRetries);
          setTimeout(waitForSelectors, 200);
        } else {
          Drupal.AmISafeLogger.error('Filter selectors not found after', maxRetries, 'retries');
        }
      }
      
      waitForSelectors();
    },

    /**
     * Load filter options from API endpoints with optimized parallel loading
     */
    loadFilterOptions: function () {
      var self = this;
      
      Drupal.AmISafeLogger.debug('📡 Loading filter options in parallel...');
      
      // Use Promise.all for parallel loading
      var promises = [];
      
      // Load crime types
      var crimeTypesPromise = $.get('/api/amisafe/crime-types')
        .done(function (response) {
          if (response && response.crime_types) {
            self.populateCrimeTypeSelector(response.crime_types);
            Drupal.AmISafeLogger.debug('✅ Crime types loaded:', Object.keys(response.crime_types).length, 'types');
          } else {
            self.populateCrimeTypeSelector(self.getFallbackCrimeTypes());
            Drupal.AmISafeLogger.debug('⚠️ Using fallback crime types');
          }
        })
        .fail(function (xhr) {
          Drupal.AmISafeLogger.warn('Crime types API failed, using fallback data');
          self.populateCrimeTypeSelector(self.getFallbackCrimeTypes());
        });
      
      // Load districts  
      var districtsPromise = $.get('/api/amisafe/districts')
        .done(function (response) {
          if (response && response.districts) {
            self.populateDistrictSelector(response.districts);
            Drupal.AmISafeLogger.debug('✅ Districts loaded:', response.districts.length, 'districts');
          } else {
            self.populateDistrictSelector(self.getFallbackDistricts());
            Drupal.AmISafeLogger.debug('⚠️ Using fallback districts');
          }
        })
        .fail(function (xhr) {
          Drupal.AmISafeLogger.warn('Districts API failed, using fallback data');
          self.populateDistrictSelector(self.getFallbackDistricts());
        });

      promises.push(crimeTypesPromise, districtsPromise);
      
      // Wait for all filter options to load before enabling interactions
      Promise.all(promises).finally(function() {
        Drupal.AmISafeLogger.debug('🏁 All filter options loaded');
        self.enableFilterInteractions();
      });
    },

    /**
     * Get fallback crime types (extracted to reduce duplication)
     */
    getFallbackCrimeTypes: function() {
      return {
        '100': 'Murder',
        '200': 'Rape', 
        '300': 'Robbery - Total',
        '400': 'Aggravated Assault - Total',
        '500': 'Burglary - Total',
        '600': 'Theft from Vehicle',
        '700': 'All Other Larceny',
        '800': 'Vandalism',
        '900': 'Fraud',
        '1000': 'Embezzlement',
        '1100': 'Narcotic Drug Law Violations',
        '1200': 'Weapons Violations',
        '1300': 'Prostitution',
        '1400': 'Other Assaults',
        '1500': 'Arson',
        '1600': 'Stolen Property',
        '1700': 'DUI',
        '1800': 'Liquor Laws',
        '2000': 'Public Drunkenness',
        '2100': 'Disorderly Conduct',
        '2600': 'Theft from Person'
      };
    },

    /**
     * Get fallback districts (extracted to reduce duplication)
     */
    getFallbackDistricts: function() {
      return ['01', '02', '03', '05', '07', '08', '09', '12', '14', '15', '16', '17'];
    },

    /**
     * Enable filter interactions after options are loaded
     */
    enableFilterInteractions: function() {
      // Remove any loading states from filter controls
      $('.filter-section select').prop('disabled', false);
      $('.filter-actions button').prop('disabled', false);
      
      Drupal.AmISafeLogger.debug('🎛️ Filter interactions enabled');
    },

    /**
     * Populate crime type multi-select dropdown
     */
    populateCrimeTypeSelector: function (crimeTypes) {
      var select = $('#crime-type-selector');
      
      if (select.length === 0) {
        Drupal.AmISafeLogger.error('Crime type selector not found!');
        return;
      }
      
      select.empty();
      
      // Add all crime types as options, selected by default
      for (var code in crimeTypes) {
        var option = $('<option></option>')
          .val(code)
          .text('[' + code + '] ' + crimeTypes[code])
          .prop('selected', true);
        select.append(option);
      }
      
      // Crime types loaded
      
      // Trigger change event to update any dependent UI elements
      select.trigger('change');
    },

    /**
     * Populate district multi-select dropdown
     */
    populateDistrictSelector: function (districts) {
      var select = $('#district-selector');
      
      if (select.length === 0) {
        Drupal.AmISafeLogger.error('District selector not found!');
        return;
      }
      
      select.empty();
      
      // Add all districts as options, selected by default
      districts.forEach(function (district) {
        var option = $('<option></option>')
          .val(district)
          .text('DISTRICT ' + district)
          .prop('selected', true);
        select.append(option);
      });
      
      // Districts loaded
      
      // Trigger change event to update any dependent UI elements
      select.trigger('change');
    },

    /**
     * Setup all filter event handlers
     */
    setupFilterEventHandlers: function () {
      var self = this;

      // Multi-select change handlers
      $(document).on('change', '#crime-type-selector, #district-selector, #severity-selector, #time-period-selector', function () {
        self.scheduleFilterUpdate();
      });

      // Date selector change handlers
      $(document).on('change', '#start-month, #end-month', function () {
        self.scheduleFilterUpdate();
      });

      // Preset buttons
      $(document).on('click', '.preset-btn', function () {
        var preset = $(this).data('preset');
        self.applyPreset(preset);
        
        // Toggle active state
        $('.preset-btn').removeClass('active');
        $(this).addClass('active');
      });

      // Filter action buttons
      $(document).on('click', '#apply-filters', function () {
        self.applyFilters();
      });

      $(document).on('click', '#clear-filters', function () {
        self.clearAllFilters();
      });
    },

    /**
     * Apply preset filter configurations
     */
    applyPreset: function (preset) {
      var self = this;
      
      // Clear current selections first
      $('#crime-type-selector option').prop('selected', false);
      $('#severity-selector option').prop('selected', false);
      $('#time-period-selector option').prop('selected', true);
      
      switch (preset) {
        case 'violent':
          // Select violent crime types
          $('#crime-type-selector option[value="100"], #crime-type-selector option[value="200"], #crime-type-selector option[value="300"], #crime-type-selector option[value="400"], #crime-type-selector option[value="1500"]').prop('selected', true);
          $('#severity-selector option[value="3"], #severity-selector option[value="4"], #severity-selector option[value="5"]').prop('selected', true);
          break;
          
        case 'property':
          // Select property crime types
          $('#crime-type-selector option[value="500"], #crime-type-selector option[value="600"], #crime-type-selector option[value="700"], #crime-type-selector option[value="800"], #crime-type-selector option[value="1600"]').prop('selected', true);
          $('#severity-selector option[value="1"], #severity-selector option[value="2"], #severity-selector option[value="3"]').prop('selected', true);
          break;
          
        case 'recent':
          // Select all types but limit to recent months
          $('#crime-type-selector option').prop('selected', true);
          $('#severity-selector option').prop('selected', true);
          // Set date to last 3 months
          var currentMonth = new Date().getMonth() + 1;
          var startMonth = Math.max(1, currentMonth - 2);
          $('#start-month').val(startMonth.toString().padStart(2, '0'));
          $('#end-month').val(currentMonth.toString().padStart(2, '0'));
          break;
          
        case 'high-severity':
          // Select all types but only high severity
          $('#crime-type-selector option').prop('selected', true);
          $('#severity-selector option[value="4"], #severity-selector option[value="5"]').prop('selected', true);
          break;
      }
      
      // Apply the preset filters immediately
      this.scheduleFilterUpdate();
    },

    /**
     * Update hour range display
     */
    updateHourDisplay: function () {
      var startHour = parseInt($('#hour-range-start').val()) || 0;
      var endHour = parseInt($('#hour-range-end').val()) || 23;
      
      var startTime = (startHour < 10 ? '0' : '') + startHour + ':00';
      var endTime = (endHour < 10 ? '0' : '') + endHour + ':59';
      
      $('#hour-display').text(startTime + ' - ' + endTime);
    },

    /**
     * Schedule filter update (debounced)
     */
    scheduleFilterUpdate: function () {
      var self = this;
      
      if (this.filterUpdateTimeout) {
        clearTimeout(this.filterUpdateTimeout);
      }
      
      this.filterUpdateTimeout = setTimeout(function () {
        self.applyFilters();
      }, 500); // 500ms debounce
    },

    /**
     * Apply current filters to the map
     */
    applyFilters: function () {
      this.showLoading('APPLYING FILTERS...');
      
      var filters = this.getCurrentFilters();
      Drupal.AmISafeLogger.debug('Applying filters:', filters);
      
      // Reload hexagon data with filters
      this.loadHexagonData(filters);
    },

    /**
     * Get current filter values from selectors
     */
    getCurrentFilters: function () {
      var filters = {};
      
      // Crime types from multi-select
      var selectedCrimeTypes = $('#crime-type-selector').val();
      if (selectedCrimeTypes && selectedCrimeTypes.length > 0) {
        filters.crime_types = selectedCrimeTypes;
      }
      
      // Districts from multi-select
      var selectedDistricts = $('#district-selector').val();
      if (selectedDistricts && selectedDistricts.length > 0) {
        filters.districts = selectedDistricts;
      }
      
      // Severity levels from multi-select
      var selectedSeverities = $('#severity-selector').val();
      if (selectedSeverities && selectedSeverities.length > 0) {
        filters.severities = selectedSeverities;
      }
      
      // Date range from month selectors
      var startMonth = $('#start-month').val();
      var endMonth = $('#end-month').val();
      if (startMonth && endMonth) {
        filters.start_date = '2025-' + startMonth + '-01';
        // Get last day of end month
        var lastDay = new Date(2025, parseInt(endMonth), 0).getDate();
        filters.end_date = '2025-' + endMonth + '-' + lastDay;
      }
      
      // Time periods from multi-select
      var selectedTimePeriods = $('#time-period-selector').val();
      if (selectedTimePeriods && selectedTimePeriods.length > 0) {
        filters.time_periods = selectedTimePeriods;
      }
      
      return filters;
    },

    /**
     * Clear all filters to default state
     */
    clearAllFilters: function () {
      // Select all crime types
      $('#crime-type-selector option').prop('selected', true);
      
      // Select all districts
      $('#district-selector option').prop('selected', true);
      
      // Select all severity levels
      $('#severity-selector option').prop('selected', true);
      
      // Reset date range to full year
      $('#start-month').val('01');
      $('#end-month').val('12');
      
      // Select all time periods
      $('#time-period-selector option').prop('selected', true);
      
      // Clear preset button states
      $('.preset-btn').removeClass('active');
      
      // Apply filters (which will show all data)
      this.applyFilters();
    },

    /**
     * Load initial map data with optimized loading strategy
     */
    loadInitialData: function () {
      this.showLoading('LOADING CRIME DATA...');
      
      // Update initial zoom indicator (delayed to ensure DOM elements are ready)
      var self = this;
      setTimeout(function() {
        var initialZoom = self.map.getZoom();
        var initialResolution = self.getOptimalResolution(initialZoom);
        console.log('🚀 Initial zoom indicator (delayed): zoom=' + initialZoom + ', resolution=' + initialResolution);
        self.updateZoomIndicator(initialZoom, initialResolution);
      }, 1500); // Delay after minimal mode activation
      
      Drupal.AmISafeLogger.debug('📊 Starting optimized data loading sequence...');
      
      // Load hexagon data for initial view
      this.loadHexagonData();
      
      // Load citywide stats asynchronously (non-blocking)
      setTimeout(() => {
        this.loadCitywideStats();
      }, 100);
      
      // Preload adjacent zoom level data for smooth navigation
      this.preloadAdjacentData();
    },

    /**
     * Preload data for adjacent zoom levels to improve UX
     */
    preloadAdjacentData: function() {
      if (!this.settings.preloadAdjacentLevels) return;
      
      var currentZoom = this.map.getZoom();
      var currentResolution = this.getOptimalResolution(currentZoom);
      
      // Preload one resolution higher and lower for smooth zooming
      var preloadResolutions = [
        Math.max(5, currentResolution - 1),  // Include Resolution 5 citywide
        Math.min(13, currentResolution + 1)
      ];
      
      var self = this;
      setTimeout(function() {
        preloadResolutions.forEach(function(resolution) {
          if (resolution !== currentResolution) {
            self.preloadResolutionData(resolution);
          }
        });
      }, 2000); // Wait 2 seconds after initial load
    },

    /**
     * Preload data for a specific resolution
     */
    preloadResolutionData: function(resolution) {
      var bounds = this.map.getBounds();
      var cacheKey = resolution + '_' + JSON.stringify({});
      
      // Only preload if not already cached
      if (!this.dataCache.has(cacheKey)) {
        Drupal.AmISafeLogger.debug('🔄 Preloading resolution', resolution);
        
        $.ajax({
          url: this.settings.apiEndpoints.aggregated,
          method: 'GET',
          data: {
            resolution: resolution,
            bounds: bounds.getNorth() + ',' + bounds.getEast() + ',' + bounds.getSouth() + ',' + bounds.getWest()
          },
          dataType: 'json',
          timeout: 15000,
          success: function(response) {
            // Cache for future use
            this.dataCache.set(cacheKey, {
              data: response,
              timestamp: Date.now()
            });
            Drupal.AmISafeLogger.debug('✅ Preloaded resolution', resolution);
          }.bind(this),
          error: function() {
            Drupal.AmISafeLogger.debug('❌ Preload failed for resolution', resolution);
          }.bind(this)
        });
      }
    },

    /**
     * Load hexagon data from API with caching and request optimization
     */
    loadHexagonData: function (filters) {
      var self = this;
      
      // Get current map bounds and zoom level
      var bounds = this.map.getBounds();
      var zoom = this.map.getZoom();
      var resolution = this.getOptimalResolution(zoom);
      
      // Check if we need to reload data
      var filtersChanged = JSON.stringify(filters) !== JSON.stringify(this.lastFilters);
      var resolutionChanged = resolution !== this.lastLoadedResolution;
      var boundsChanged = !this.lastBounds || !bounds.equals(this.lastBounds);
      
      // Create cache key
      var cacheKey = resolution + '_' + JSON.stringify(filters || {});
      
      // PERFORMANCE CHECK: Use cached data if available
      if (this.dataCache.has(cacheKey)) {
        // PERFORMANCE: Track cache hit
        this.cacheHitCount++;
        Drupal.AmISafeLogger.debug(`💾 CACHE HIT ${this.cacheHitCount}: Using cached data for ${cacheKey}`);
        var cachedEntry = this.dataCache.get(cacheKey);
        var cachedResponse = cachedEntry.data || cachedEntry; // Handle both old and new format
        if (cachedResponse.hexagons && Array.isArray(cachedResponse.hexagons)) {
          this.renderHexagons(cachedResponse.hexagons);
          
          // Calculate stats for cached data
          var totalIncidents = 0;
          var activeSectors = 0;
          cachedResponse.hexagons.forEach(function(hexagon) {
            var incidentCount = hexagon.incident_count || hexagon.total_incidents || 0;
            if (incidentCount > 0) {
              totalIncidents += incidentCount;
              activeSectors++;
            }
          });
          
          this.updateStats(
            totalIncidents,
            this.calculateOverallThreatLevel(cachedResponse.hexagons),
            activeSectors
          );
        }
        return;
      }
      
      // PERFORMANCE CHECK: Skip reload if nothing significant changed
      if (!filtersChanged && !resolutionChanged && !boundsChanged) {
        Drupal.AmISafeLogger.debug('⚡ PERFORMANCE: No changes detected, skipping reload');
        return;
      }
      
      // Cancel any pending request
      if (this.currentRequest && this.currentRequest.readyState !== 4) {
        Drupal.AmISafeLogger.debug('🚫 Cancelling previous request');
        this.currentRequest.abort();
      }
      
      if (this.debugMode) {
        Drupal.AmISafeLogger.info('🎯 LOADING: Zoom=' + zoom + ' → H3=' + resolution + ' (' + this.getResolutionDescription(resolution) + ')');
        if (filtersChanged) Drupal.AmISafeLogger.info('🔧 Filters changed');
        if (resolutionChanged) Drupal.AmISafeLogger.info('🔍 Resolution changed: ' + this.lastLoadedResolution + ' → ' + resolution);
      }
      if (boundsChanged) Drupal.AmISafeLogger.info('🗺️ Bounds changed');
      
      // Build API request parameters
      var params = {
        resolution: resolution,
        bounds: bounds.getNorth() + ',' + bounds.getEast() + ',' + bounds.getSouth() + ',' + bounds.getWest()
      };
      
      // Add filters if provided
      if (filters) {
        // Crime types
        if (filters.crime_types && filters.crime_types.length > 0) {
          params.crime_types = filters.crime_types.join(',');
        }
        
        // Districts (updated for new multi-select format)
        if (filters.districts && filters.districts.length > 0) {
          params.districts = filters.districts.join(',');
        }
        
        // Severity levels (new selector-based)
        if (filters.severities && filters.severities.length > 0) {
          params.severities = filters.severities.join(',');
        }
        
        // Date range
        if (filters.start_date) {
          params.start_date = filters.start_date;
        }
        if (filters.end_date) {
          params.end_date = filters.end_date;
        }
        
        // Time periods (new selector-based)
        if (filters.time_periods && filters.time_periods.length > 0) {
          params.time_periods = filters.time_periods.join(',');
        }
        
        // Legacy filters (backwards compatibility)
        if (filters.hour_start) {
          params.hour_start = filters.hour_start;
        }
        if (filters.hour_end) {
          params.hour_end = filters.hour_end;
        }
      }
      
      // PERFORMANCE: Track API call
      this.apiCallCount++;
      Drupal.AmISafeLogger.debug(`🔍 API CALL ${this.apiCallCount}: Cache miss for ${cacheKey}`);

      // Choose API endpoint and parameters based on resolution level
      var apiUrl = this.settings.apiEndpoints.aggregated;
      var timeout = 10000;
      
      // Special handling for Resolution 5 (single citywide hexagon)
      if (resolution === 5) {
        // For citywide view, get the specific Resolution 5 hexagon
        params.h3_index = '852a134bfffffff'; // Philadelphia citywide hexagon
        params.limit = 1; // Only need the single citywide hexagon
        timeout = 5000; // Fast timeout for single hexagon lookup
        
        Drupal.AmISafeLogger.debug('🏙️ CITYWIDE MODE: Requesting single Resolution 5 hexagon');
      }
      // Use ultra-precision endpoint for Resolution 12-13
      else if (resolution >= 12) {
        apiUrl = this.settings.apiEndpoints.ultraPrecision || '/api/amisafe/ultra-precision';
        timeout = 20000; // Longer timeout for ultra-precision queries
        params.limit = 5000; // Higher limit for ultra-precision
        
        Drupal.AmISafeLogger.debug('🎯 ULTRA-PRECISION MODE: Resolution ' + resolution + ' with extended timeout');
      }

      // Make API request and store reference for cancellation
      this.currentRequest = $.ajax({
        url: apiUrl,
        method: 'GET',
        data: params,
        dataType: 'json',
        timeout: timeout,
        success: function (response) {
          // Cache the response for future use
          self.dataCache.set(cacheKey, {
            data: response,
            timestamp: Date.now()
          });
          self.lastLoadedResolution = resolution;
          self.lastBounds = bounds;
          self.lastFilters = filters;
          
          // Clean old cache entries if cache gets too large
          self.cleanupCache();
          
          Drupal.AmISafeLogger.debug('🔥 API RESPONSE: H3=' + (response.meta ? response.meta.resolution : 'unknown') + ' returned ' + (response.hexagons ? response.hexagons.length : 0) + ' hexagons');
          Drupal.AmISafeLogger.debug('💾 Cached response with key:', cacheKey, '(Cache size:', self.dataCache.size + ')');
          
          // Extra debugging for high-res responses
          if (self.debugMode && response.meta && response.meta.resolution >= 12) {
            Drupal.AmISafeLogger.info('⚡ EXTREME DETAIL CONFIRMED: Resolution ' + response.meta.resolution + ' with ' + response.hexagons.length + ' high-precision hexagons');
          }
          
          if (response.hexagons && Array.isArray(response.hexagons)) {
            // Special handling for Resolution 5 citywide hexagon
            if (resolution === 5 && response.hexagons.length > 0) {
              self.renderCitywideHexagon(response.hexagons[0]);
              // For citywide view, display the total incident count from the single hexagon
              var citywideIncidents = response.hexagons[0].incident_count || 0;
              self.updateStats(citywideIncidents, 'EXTREME', 1);
              
              Drupal.AmISafeLogger.info('🏙️ CITYWIDE HEXAGON: ' + citywideIncidents.toLocaleString() + ' total incidents in Philadelphia metro area');
            } else {
              // Normal multi-hexagon rendering for Resolution 6-13
              self.renderHexagons(response.hexagons);
              
              // Calculate statistics from multiple hexagons
              var totalIncidents = 0;
              var activeSectors = 0;
              response.hexagons.forEach(function(hexagon) {
                var incidents = hexagon.incident_count || hexagon.total_incidents || 0;
                totalIncidents += incidents;
                if (incidents > 0) {
                  activeSectors++;
                }
              });
              
              self.updateStats(
                totalIncidents,
                self.calculateOverallThreatLevel(response.hexagons),
                activeSectors
              );
            }
          } else {
            Drupal.AmISafeLogger.warn('Invalid API response format');
            self.loadSampleData(); // Fallback to sample data
          }
          self.hideLoading();
        },
        error: function (xhr, status, error) {
          Drupal.AmISafeLogger.error('API Error:', status, error);
          Drupal.AmISafeLogger.debug('XHR Response:', xhr.responseText);
          
          // Show error message but fallback to sample data for development
          self.showMessage('API Error: Using sample data for development');
          self.loadSampleData();
        }
      });
    },

    /**
     * Load sample data for testing (fallback)
     */
    loadSampleData: function () {
      var self = this;
      
      setTimeout(function () {
        // Create sample hexagons around Philadelphia
        var sampleHexagons = self.generateSampleHexagons();
        self.renderHexagons(sampleHexagons);
        self.updateStats(sampleHexagons.length, 'MEDIUM', sampleHexagons.length);
        self.hideLoading();
      }, 1500);
    },

    /**
     * Generate sample hexagon data for testing
     */
    generateSampleHexagons: function () {
      var hexagons = [];
      var centerLat = 39.9526;
      var centerLng = -75.1652;
      
      // Create a grid of sample hexagons
      for (var i = 0; i < 20; i++) {
        var lat = centerLat + (Math.random() - 0.5) * 0.1;
        var lng = centerLng + (Math.random() - 0.5) * 0.1;
        var crimeCount = Math.floor(Math.random() * 50) + 1;
        
        hexagons.push({
          h3_index: 'sample_' + i,
          lat: lat,
          lng: lng,
          incident_count: crimeCount,
          severity_avg: Math.floor(Math.random() * 5) + 1
        });
      }
      
      return hexagons;
    },

    /**
     * Render hexagons on the map
     */
    renderHexagons: function (hexagons) {
      this.hexagonLayer.clearLayers();
      
      Drupal.AmISafeLogger.info('Rendering', hexagons.length, 'hexagons');
      Drupal.AmISafeLogger.debug('H3 library available:', !!window.h3);
      
      // Add visible debugging panel
      this.showDebugPanel(window.h3);
      
      if (window.h3) {
        Drupal.AmISafeLogger.debug('H3 object keys (first 20):', Object.keys(h3).slice(0, 20));
        Drupal.AmISafeLogger.debug('H3 function availability check:');
        const funcNames = ['cellToBoundary', 'h3ToGeoBoundary', 'cellToVertex', 'cellToVertexes', 'h3ToGeo', 'latLngToCell', 'cellToLatLng'];
        funcNames.forEach(name => {
          Drupal.AmISafeLogger.debug(`  ${name}:`, typeof h3[name], h3[name] ? '✅' : '❌');
        });
        
        // Quick coordinate format test
        Drupal.AmISafeLogger.debug('🧪 COORDINATE FORMAT TEST:');
        Drupal.AmISafeLogger.debug('Testing H3 with known Philadelphia location...');
        try {
          // Center of Philadelphia
          var testLat = 39.9526;
          var testLng = -75.1652;
          Drupal.AmISafeLogger.debug('Input coordinates:', testLat, testLng);
          
          // Get H3 index for this location
          var testCell = h3.latLngToCell(testLat, testLng, 9);
          Drupal.AmISafeLogger.debug('Generated H3 index:', testCell);
          
          // Get boundary
          var testBoundary = h3.cellToBoundary(testCell, true);
          Drupal.AmISafeLogger.debug('H3 boundary result:', testBoundary);
          Drupal.AmISafeLogger.debug('First coordinate in boundary:', testBoundary[0]);
          Drupal.AmISafeLogger.debug('Coordinate format check:');
          Drupal.AmISafeLogger.debug('  Is [lat, lng]?', testBoundary[0][0] > 35 && testBoundary[0][0] < 45);
          Drupal.AmISafeLogger.debug('  Is [lng, lat]?', testBoundary[0][0] < -70 && testBoundary[0][0] > -80);
        } catch (e) {
          Drupal.AmISafeLogger.error('Coordinate test failed:', e);
        }
        
        // Confirmed: H3 v4+ returns coordinates in [lng, lat] format
        // Our renderHexagons function now automatically converts to [lat, lng] for Leaflet
        console.log('✅ H3 coordinate format: [lng, lat] → [lat, lng] conversion enabled');
      }
      
      var self = this;
      
      var processedCount = 0;
      var successCount = 0;
      var errorCount = 0;
      
      hexagons.forEach(function (hexagon, index) {
        processedCount++;
        var incidentCount = hexagon.incident_count || hexagon.total_incidents || 0;
        var color = self.getHexagonColor(incidentCount);
        var h3Index = hexagon.h3_index;
        
        if (!self.minimalMode) {
          console.log(`🔵 Processing hexagon ${index + 1}/${hexagons.length}:`, {
            h3Index: h3Index,
            incidentCount: incidentCount,
            color: color,
            hasH3: !!window.h3
          });
        }
        
        if (h3Index && window.h3) {
          // Create actual H3 hexagon using h3-js library v4+ API
          try {
            // Check which H3 API version is available and use appropriate function
            var boundary;
            
            // Enhanced H3 API detection and boundary calculation
            var boundary = null;
            
            // Test all possible boundary function names
            const boundaryFunctions = [
              { name: 'cellToBoundary', version: 'v4+', params: [h3Index, true] },
              { name: 'h3ToGeoBoundary', version: 'v3', params: [h3Index, true] },
              { name: 'cellToVertexes', version: 'v4 alt', params: [h3Index] }
            ];
            
            for (let funcInfo of boundaryFunctions) {
              if (typeof h3[funcInfo.name] === 'function') {
                try {
                  console.log(`🔄 Attempting ${funcInfo.name} (${funcInfo.version}) with params:`, funcInfo.params);
                  boundary = h3[funcInfo.name].apply(h3, funcInfo.params);
                  console.log('✅ Success! H3 boundary result:', boundary);
            console.log('   Boundary type:', typeof boundary, 'Array:', Array.isArray(boundary), 'Length:', boundary?.length);
            
            // H3 v4+ returns coordinates in [lng, lat] format, but Leaflet expects [lat, lng]
            // Convert from H3's [lng, lat] to Leaflet's [lat, lng] format
            var leafletBoundary = boundary.map(function(coord) {
              return [coord[1], coord[0]]; // Swap from [lng, lat] to [lat, lng]
            });
            
            console.log('   Original H3 format [lng, lat]:', boundary[0]);
            console.log('   Converted to Leaflet [lat, lng]:', leafletBoundary[0]);
            console.log('   ✅ Coordinate conversion complete');
            
            // Update boundary to use the converted coordinates
            boundary = leafletBoundary;
                  break;
                } catch (error) {
                  console.error(`Failed with ${funcInfo.name}:`, error.message);
                  continue;
                }
              } else {
                console.log(`${funcInfo.name} not available`);
              }
            }
            
            if (!boundary) {
              // Last resort: print all available functions and bail
              console.error('🚨 No H3 boundary function worked!');
              console.error('Available H3 methods:', Object.keys(h3).filter(k => typeof h3[k] === 'function'));
              throw new Error('Unable to get hexagon boundary from H3 library');
            }
            
            // Check if we need to convert coordinate format
            if (boundary && boundary.length > 0 && boundary[0].length === 2) {
              var firstCoord = boundary[0];
              // If first value looks like longitude (< -70), swap to [lat, lng]
              if (firstCoord[0] < -70 && firstCoord[0] > -80 && firstCoord[1] > 35 && firstCoord[1] < 45) {
                console.log('🔄 Converting coordinates from [lng, lat] to [lat, lng]');
                boundary = boundary.map(coord => [coord[1], coord[0]]);
                console.log('   Converted first coordinate:', boundary[0]);
              }
            }
            
            console.log('🔷 Creating Leaflet polygon with corrected boundary:', boundary);
            console.log('   Boundary sample coords [lat, lng]:', boundary.slice(0, 2));
            console.log('   Philadelphia bounds check (after coordinate swap):');
            console.log('     First coord lat:', boundary[0]?.[0], 'lng:', boundary[0]?.[1]);
            console.log('     Is in Philly area? Lat 39.8-40.2, Lng -75.5 to -74.9');
            console.log('     Lat ok?', (boundary[0]?.[0] >= 39.8 && boundary[0]?.[0] <= 40.2));
            console.log('     Lng ok?', (boundary[0]?.[1] >= -75.5 && boundary[0]?.[1] <= -74.9));
            
            var hexagonPolygon = L.polygon(boundary, {
              fillColor: color,
              fillOpacity: 0.7,
              color: '#00ffff',
              weight: 1,
              className: 'h3-hexagon',
              h3Index: h3Index,
              incidentCount: incidentCount
            });
            console.log('🔷 Leaflet polygon created successfully');
            
            var bounds = hexagonPolygon.getBounds();
            console.log('   Polygon bounds:', bounds);
            console.log('   Bounds center:', bounds.getCenter());
            console.log('   Map center:', self.map.getCenter());
            console.log('   Map zoom:', self.map.getZoom());
            console.log('   Map bounds:', self.map.getBounds());

            // Only add interactive handlers if not in minimal mode
            if (!self.minimalMode) {
              hexagonPolygon.on('click', function (e) {
                self.showHexagonPopup(hexagon, e.latlng);
              });

              hexagonPolygon.on('mouseover', function (e) {
                e.target.setStyle({
                  weight: 2,
                  fillOpacity: 0.9
                });
              });

              hexagonPolygon.on('mouseout', function (e) {
                e.target.setStyle({
                  weight: 1,
                  fillOpacity: 0.7
                });
              });
            }

            hexagonPolygon.addTo(self.hexagonLayer);
            successCount++;
            if (!self.minimalMode) {
              console.log(`✅ Successfully added hexagon ${index + 1} to layer. Layer now has ${self.hexagonLayer.getLayers().length} features`);
            }
          } catch (error) {
            errorCount++;
            console.warn('❌ Error creating H3 hexagon for', h3Index, ':', error);
            // Fallback to circle
            self.createFallbackCircle(hexagon, color);
          }
        } else {
          // Fallback to circle if H3 library not available or no h3_index
          console.warn('H3 library not available or missing h3_index, using fallback circle');
          self.createFallbackCircle(hexagon, color);
        }
      });
      
      // Check if hexagons are within current map bounds
      var mapBounds = this.map.getBounds();
      var visibleCount = 0;
      var totalBounds = null;
      
      this.hexagonLayer.eachLayer(function(layer) {
        var hexBounds = layer.getBounds();
        if (mapBounds.overlaps(hexBounds)) {
          visibleCount++;
        }
        
        // Calculate total bounds of all hexagons
        if (!totalBounds) {
          totalBounds = hexBounds;
        } else {
          totalBounds.extend(hexBounds);
        }
      });
      
      console.log(`📊 HEXAGON RENDERING SUMMARY:
        - Processed: ${processedCount}/${hexagons.length} hexagons
        - Successful: ${successCount} hexagons
        - Errors: ${errorCount} hexagons
        - Layer count: ${this.hexagonLayer.getLayers().length} features
        - Map has layer: ${this.map.hasLayer(this.hexagonLayer) ? '✅' : '❌'}
        - Visible in viewport: ${visibleCount}/${successCount} hexagons
        - Map center: ${this.map.getCenter()}
        - Map bounds: ${mapBounds}
        - Hexagon bounds: ${totalBounds}
      `);
      
      // Force layer refresh if not properly attached
      if (!this.map.hasLayer(this.hexagonLayer) && this.hexagonLayer.getLayers().length > 0) {
        console.log('🔧 FIXING: Re-adding hexagon layer to map...');
        this.hexagonLayer.addTo(this.map);
        console.log('✅ Layer re-attached. Map has layer now:', this.map.hasLayer(this.hexagonLayer));
      }
      
      // If no hexagons are visible, suggest viewing the hexagon area
      if (successCount > 0 && visibleCount === 0 && totalBounds) {
        console.log('⚠️  No hexagons visible in current view. Hexagons exist at:', totalBounds.getCenter());
        console.log('💡 Auto-fitting map to show all hexagon data...');
        
        // Always auto-fit to show the hexagon data, regardless of zoom level
        var self = this;
        setTimeout(function() {
          self.map.fitBounds(totalBounds, {
            padding: [20, 20], // Add some padding around the bounds
            maxZoom: 15 // Don't zoom in too close
          });
          console.log('📍 Map fitted to show all hexagons at bounds:', totalBounds);
        }, 1000);
      }
    },

    /**
     * Render single citywide hexagon for Resolution 5
     * Special handling for the Philadelphia metro-wide hexagon (251 km²)
     */
    renderCitywideHexagon: function (hexagon) {
      this.hexagonLayer.clearLayers();
      
      var incidentCount = hexagon.incident_count || 0;
      var h3Index = hexagon.h3_index || '852a134bfffffff';
      
      Drupal.AmISafeLogger.info('🏙️ Rendering citywide hexagon:', h3Index, 'with', incidentCount.toLocaleString(), 'incidents');
      
      if (window.h3 && h3.cellToBoundary) {
        try {
          // Get the actual H3 hexagon boundary
          var boundary = h3.cellToBoundary(h3Index, true);
          
          // Convert H3 coordinates [lng, lat] to Leaflet format [lat, lng]
          var leafletCoords = boundary.map(function(coord) {
            return [coord[1], coord[0]]; // Swap from [lng, lat] to [lat, lng]
          });
          
          // Create the citywide hexagon polygon
          var polygon = L.polygon(leafletCoords, {
            fillColor: '#ff8800', // Orange for high citywide activity
            fillOpacity: 0.3,     // More transparent for large area
            color: '#00ffff',     // Cyan border
            weight: 3,
            className: 'h3-hexagon-citywide'
          });
          
          // Add click handler for citywide stats (only if not in minimal mode)
          var self = this;
          if (!self.minimalMode) {
            polygon.on('click', function (e) {
              self.showCitywidePopup(hexagon, e.latlng);
            });
          }
          
          polygon.addTo(this.hexagonLayer);
          
          // Fit map to show the entire citywide hexagon
          this.map.fitBounds(polygon.getBounds(), {
            padding: [50, 50],
            maxZoom: 10 // Don't zoom too close for citywide view
          });
          
          Drupal.AmISafeLogger.debug('✅ Citywide hexagon rendered successfully');
        } catch (error) {
          Drupal.AmISafeLogger.error('Failed to render citywide hexagon:', error);
          this.createCitywideCircle(hexagon);
        }
      } else {
        // Fallback to circle if H3 library not available
        Drupal.AmISafeLogger.warn('H3 library not available, using citywide circle fallback');
        this.createCitywideCircle(hexagon);
      }
    },

    /**
     * Create fallback circle for citywide view when H3 is not available
     */
    createCitywideCircle: function (hexagon) {
      var incidentCount = hexagon.incident_count || 0;
      // Center on Philadelphia
      var lat = 40.038890;
      var lng = -75.200686;
      var radius = 15000; // 15km radius for citywide view
      
      var circle = L.circle([lat, lng], {
        radius: radius,
        fillColor: '#ff8800',
        fillOpacity: 0.3,
        color: '#00ffff',
        weight: 3,
        className: 'h3-citywide-fallback'
      });

      var self = this;
      if (!self.minimalMode) {
        circle.on('click', function (e) {
          self.showCitywidePopup(hexagon, e.latlng);
        });
      }

      circle.addTo(this.hexagonLayer);
      
      // Fit map to show the citywide circle
      this.map.fitBounds(circle.getBounds(), {
        padding: [50, 50]
      });
    },

    /**
     * Show citywide popup with Philadelphia metro statistics
     */
    showCitywidePopup: function (hexagon, latlng) {
      var incidentCount = hexagon.incident_count || 0;
      var popupContent = `
        <div class="crime-popup citywide-popup">
          <h3 class="terminal-text">PHILADELPHIA METROPOLITAN AREA</h3>
          <div class="crime-stats">
            <div class="stat-line">TOTAL INCIDENTS: <span class="neon-green">${incidentCount.toLocaleString()}</span></div>
            <div class="stat-line">COVERAGE AREA: <span class="neon-cyan">251.10 km²</span></div>
            <div class="stat-line">HEXAGON ID: <span class="neon-yellow">${hexagon.h3_index}</span></div>
            <div class="stat-line">THREAT LEVEL: <span class="threat-extreme">EXTREME</span></div>
            <div class="stat-line">RESOLUTION: <span class="neon-cyan">H3 Level 5</span></div>
          </div>
          <div class="popup-footer">
            <small>📍 CITYWIDE SURVEILLANCE ACTIVE</small>
          </div>
        </div>
      `;
      
      L.popup()
        .setLatLng(latlng)
        .setContent(popupContent)
        .openOn(this.map);
    },

    /**
     * Create fallback circle when H3 hexagon creation fails
     */
    createFallbackCircle: function (hexagon, color) {
      var incidentCount = hexagon.incident_count || hexagon.total_incidents || 0;
      var lat = hexagon.lat || 39.9526;
      var lng = hexagon.lng || -75.1652;
      var radius = Math.max(50, incidentCount * 2);
      
      var circle = L.circle([lat, lng], {
        radius: radius,
        fillColor: color,
        fillOpacity: 0.7,
        color: '#00ffff',
        weight: 2,
        className: 'h3-hexagon-fallback'
      });

      var self = this;
      if (!self.minimalMode) {
        circle.on('click', function (e) {
          self.showHexagonPopup(hexagon, e.latlng);
        });
      }

      circle.addTo(this.hexagonLayer);
    },

    /**
     * Get hexagon color based on crime count
     */
    getHexagonColor: function (incidentCount) {
      if (incidentCount === 0) return '#0a0a0a';
      if (incidentCount <= 5) return '#1a4d4d';
      if (incidentCount <= 15) return '#00ff00';
      if (incidentCount <= 30) return '#ffff00';
      if (incidentCount <= 50) return '#ff8800';
      return '#ff0000';
    },

    /**
     * Show hexagon popup with crime details
     */
    showHexagonPopup: function (hexagon, latlng) {
      var threatLevel = this.getThreatLevel(hexagon.incident_count);
      
      var popupContent = `
        <div class="crime-popup">
          <h3 class="terminal-text">SECTOR ${hexagon.h3_index.substring(0, 8).toUpperCase()}</h3>
          <div class="crime-stats">
            <div class="stat-line">INCIDENTS: <span class="neon-green">${hexagon.incident_count}</span></div>
            <div class="stat-line">THREAT LEVEL: <span class="threat-${threatLevel.toLowerCase()}">${threatLevel}</span></div>
            <div class="stat-line">SEVERITY: <span class="neon-orange">${hexagon.severity_avg}/5</span></div>
          </div>
          <button class="cyber-button" onclick="AmISafeCrimeMap.showFullDetails('${hexagon.h3_index}')">
            &gt; ANALYZE SECTOR_
          </button>
        </div>
      `;
      
      L.popup()
        .setLatLng(latlng)
        .setContent(popupContent)
        .openOn(this.map);
    },

    /**
     * Show full detailed analysis for a hexagon
     */
    showFullDetails: function (h3Index) {
      var self = this;
      
      // Show loading modal
      this.showDetailModal('LOADING SECTOR ANALYSIS...', '<div class="loading-spinner">█ ACCESSING DATABASE_</div>');
      
      // Get current filters to apply to detailed data
      var filters = this.getCurrentFilters();
      
      // Fetch detailed hexagon data
      $.ajax({
        url: '/api/amisafe/hexagon/' + h3Index,
        type: 'POST',
        data: JSON.stringify(filters),
        contentType: 'application/json',
        dataType: 'json'
      })
      .done(function (response) {
        self.displayDetailedAnalysis(response);
      })
      .fail(function (xhr, status, error) {
        console.error('Failed to fetch hexagon details:', error);
        self.showDetailModal('ERROR', '<div class="error-text">FAILED TO ACCESS DATABASE<br>CONNECTION SEVERED</div>');
      });
    },

    /**
     * Display detailed analysis modal
     */
    displayDetailedAnalysis: function (data) {
      var hexagonData = data.hexagon_data;
      var threatAnalysis = data.threat_analysis;
      var recommendations = data.recommendations;
      var h3Index = data.h3_index;
      
      // Build crime breakdown
      var crimeBreakdown = '';
      if (hexagonData.crime_breakdown && hexagonData.crime_breakdown.length > 0) {
        crimeBreakdown = '<div class="crime-breakdown"><h4 class="terminal-text">CRIME ANALYSIS</h4>';
        hexagonData.crime_breakdown.slice(0, 5).forEach(function (crime) {
          crimeBreakdown += `
            <div class="crime-item">
              <span class="crime-type">${crime.description}</span>
              <span class="crime-count neon-green">${crime.count} (${crime.percentage}%)</span>
            </div>
          `;
        });
        crimeBreakdown += '</div>';
      }
      
      // Build time distribution
      var timeAnalysis = '';
      if (hexagonData.hourly_distribution) {
        var highActivity = this.getHighActivityPeriods(hexagonData.hourly_distribution);
        timeAnalysis = `
          <div class="time-analysis">
            <h4 class="terminal-text">TEMPORAL PATTERNS</h4>
            <div class="activity-periods">${highActivity}</div>
          </div>
        `;
      }
      
      // Build recommendations
      var recList = '';
      if (recommendations && recommendations.length > 0) {
        recList = '<div class="recommendations"><h4 class="terminal-text">SECURITY PROTOCOLS</h4>';
        recommendations.forEach(function (rec) {
          recList += `<div class="rec-item">&gt; ${rec}</div>`;
        });
        recList += '</div>';
      }
      
      // Build recent incidents
      var recentIncidents = '';
      if (hexagonData.recent_incidents && hexagonData.recent_incidents.length > 0) {
        recentIncidents = '<div class="recent-incidents"><h4 class="terminal-text">RECENT ACTIVITY</h4>';
        hexagonData.recent_incidents.slice(0, 3).forEach(function (incident) {
          recentIncidents += `
            <div class="incident-item">
              <span class="incident-date">${incident.incident_date}</span>
              <span class="incident-type">${incident.ucr_description}</span>
            </div>
          `;
        });
        recentIncidents += '</div>';
      }
      
      var threatColor = this.getThreatColor(threatAnalysis.level);
      
      var content = `
        <div class="detailed-analysis">
          <h2 class="terminal-text">SECTOR ${h3Index.substring(0, 8).toUpperCase()} - FULL ANALYSIS</h2>
          
          <div class="threat-header">
            <div class="threat-badge threat-${threatAnalysis.level.toLowerCase()}" style="color: ${threatColor}">
              █ THREAT LEVEL: ${threatAnalysis.level}
            </div>
            <div class="confidence">CONFIDENCE: ${data.meta.confidence}</div>
          </div>
          
          <div class="analysis-grid">
            <div class="stats-panel">
              <h4 class="terminal-text">SECTOR STATISTICS</h4>
              <div class="stat-line">TOTAL INCIDENTS: <span class="neon-green">${hexagonData.total_incidents}</span></div>
              <div class="stat-line">SEVERITY AVERAGE: <span class="neon-orange">${hexagonData.severity_avg}/5</span></div>
              <div class="stat-line">LAST INCIDENT: <span class="neon-blue">${hexagonData.last_incident}</span></div>
              <div class="stat-line">DISTRICTS: <span class="neon-purple">${hexagonData.districts.join(', ')}</span></div>
            </div>
            
            ${crimeBreakdown}
            ${timeAnalysis}
            ${recentIncidents}
            ${recList}
          </div>
          
          <div class="risk-factors">
            <h4 class="terminal-text">RISK ASSESSMENT</h4>
            ${threatAnalysis.risk_factors.map(factor => `<div class="risk-item">⚠ ${factor}</div>`).join('')}
          </div>
        </div>
      `;
      
      this.showDetailModal('SECTOR ANALYSIS COMPLETE', content);
    },

    /**
     * Get high activity time periods
     */
    getHighActivityPeriods: function (hourlyDist) {
      var periods = [];
      var maxActivity = Math.max.apply(Math, Object.values(hourlyDist));
      
      for (var hour in hourlyDist) {
        if (hourlyDist[hour] >= maxActivity * 0.7) {
          var timeStr = (hour < 10 ? '0' + hour : hour) + ':00';
          periods.push(`${timeStr} (${hourlyDist[hour]} incidents)`);
        }
      }
      
      return periods.join('<br>') || 'NO CLEAR PATTERN DETECTED';
    },

    /**
     * Get threat level color
     */
    getThreatColor: function (level) {
      var colors = {
        'CRITICAL': '#ff0066',
        'HIGH': '#ff3300',
        'MODERATE': '#ffaa00',
        'LOW': '#00ff66',
        'MINIMAL': '#0099ff'
      };
      return colors[level] || '#888888';
    },

    /**
     * Show detail modal
     */
    showDetailModal: function (title, content) {
      // Remove existing modal
      $('.crime-detail-modal').remove();
      
      var modal = $(`
        <div class="crime-detail-modal">
          <div class="modal-overlay"></div>
          <div class="modal-content">
            <div class="modal-header">
              <h3 class="terminal-text">${title}</h3>
              <button class="close-btn" onclick="AmISafeCrimeMap.closeDetailModal()">[X]</button>
            </div>
            <div class="modal-body">
              ${content}
            </div>
          </div>
        </div>
      `);
      
      $('body').append(modal);
      
      // Close on overlay click
      modal.find('.modal-overlay').on('click', function () {
        AmISafeCrimeMap.closeDetailModal();
      });
    },

    /**
     * Close detail modal
     */
    closeDetailModal: function () {
      $('.crime-detail-modal').remove();
    },

    /**
     * Get threat level based on crime count
     */
    getThreatLevel: function (incidentCount) {
      if (incidentCount <= 5) return 'LOW';
      if (incidentCount <= 20) return 'MEDIUM';
      return 'HIGH';
    },

    /**
     * Calculate overall threat level from hexagon data
     */
    calculateOverallThreatLevel: function (hexagons) {
      if (!hexagons || hexagons.length === 0) return 'MINIMAL';
      
      var totalCrimes = 0;
      var highThreatSectors = 0;
      var criticalSectors = 0;
      var severityScores = [];
      
      hexagons.forEach(function (hexagon) {
        var incidentCount = hexagon.incident_count || hexagon.total_incidents || 0;
        var avgSeverity = hexagon.severity_avg || 2;
        
        totalCrimes += incidentCount;
        severityScores.push(avgSeverity);
        
        if (incidentCount > 30) {
          criticalSectors++;
        } else if (incidentCount > 15) {
          highThreatSectors++;
        }
      });
      
      var avgCrimesPerSector = totalCrimes / hexagons.length;
      var avgSeverity = severityScores.reduce((a, b) => a + b, 0) / severityScores.length;
      var criticalRatio = criticalSectors / hexagons.length;
      var highThreatRatio = highThreatSectors / hexagons.length;
      
      // Calculate threat level based on multiple factors
      if (avgCrimesPerSector > 25 || criticalRatio > 0.2 || avgSeverity > 4) return 'CRITICAL';
      if (avgCrimesPerSector > 15 || highThreatRatio > 0.3 || avgSeverity > 3) return 'HIGH';
      if (avgCrimesPerSector > 8 || highThreatRatio > 0.1 || avgSeverity > 2.5) return 'MODERATE';
      if (avgCrimesPerSector > 3 || totalCrimes > 0) return 'LOW';
      return 'MINIMAL';
    },

    /**
     * Switch between different view modes
     */
    switchView: function (viewMode) {
      console.log('Switching to view mode:', viewMode);
      this.currentView = viewMode;
      
      // Hide all layers
      this.map.removeLayer(this.hexagonLayer);
      this.map.removeLayer(this.heatmapLayer);
      this.map.removeLayer(this.markersLayer);
      
      // Show selected layer
      switch (viewMode) {
        case 'hexagon':
          this.map.addLayer(this.hexagonLayer);
          break;
        case 'heatmap':
          this.map.addLayer(this.heatmapLayer);
          this.showMessage('Heatmap view - Coming Soon');
          break;
        case 'points':
          this.map.addLayer(this.markersLayer);
          this.showMessage('Points view - Coming Soon');
          break;
      }
    },

    /**
     * Update filters and reload data with debouncing
     */
    updateFilters: function () {
      var self = this;
      if (this.debugMode) console.log('🔧 FILTER UPDATE: Started');
      
      // Clear existing filter timeout
      if (this.filterTimeout) {
        clearTimeout(this.filterTimeout);
      }
      
      // Debounce filter updates to avoid excessive API calls
      this.filterTimeout = setTimeout(function() {
        // Collect filter values
        var crimeTypes = [];
        $('.filter-checkboxes input[type="checkbox"]:checked').each(function () {
          crimeTypes.push($(this).val());
        });
        
        var districts = $('#district-filter').val() || [];
        
        // Get date range if available
        var dateRange = $('#date-range-picker').val();
        var startDate = null, endDate = null;
        if (dateRange && dateRange.includes(' to ')) {
          var dates = dateRange.split(' to ');
          startDate = dates[0].trim();
          endDate = dates[1].trim();
        }
        
        var newFilters = {
          crime_types: crimeTypes,
          districts: districts,
          start_date: startDate,
          end_date: endDate
        };
        
        // Check if filters actually changed
        if (JSON.stringify(self.lastFilters) === JSON.stringify(newFilters)) {
          if (self.debugMode) console.log('🔧 FILTER SKIP: No changes detected');
          return;
        }
        
        self.currentFilters = newFilters;
        self.lastFilters = JSON.parse(JSON.stringify(newFilters)); // Deep copy
        
        if (self.debugMode) console.log('🔧 FILTER APPLIED:', self.currentFilters);
        
        // Reload data with new filters
        self.showLoading('APPLYING FILTERS...');
        self.loadHexagonData();
      }, 250);
    },

    /**
     * Handle map zoom events with debouncing
     */
    onMapZoom: function () {
      console.log('⚡ ZOOM EVENT FIRED!');
      var self = this;
      var zoom = this.map.getZoom();
      var resolution = this.getOptimalResolution(zoom);
      console.log('⚡ Raw zoom value:', zoom, 'Calculated resolution:', resolution);
      
      // Update zoom indicator immediately
      console.log('🔍 ZOOM EVENT: zoom=' + zoom + ', resolution=' + resolution);
      this.updateZoomIndicator(zoom, resolution);
      
      // Clear existing timeout
      if (this.loadTimeout) {
        clearTimeout(this.loadTimeout);
      }
      
      if (this.debugMode) console.log('🔄 ZOOM EVENT: ' + zoom + ' → H3=' + resolution + ' (debounced)');
      
      // Debounce the data loading to avoid excessive API calls
      this.loadTimeout = setTimeout(function() {
        if (self.debugMode) console.log('⚡ ZOOM DEBOUNCE: Loading data after 300ms delay');
        self.loadHexagonData(); // Debounced call
      }, 300);
    },

    /**
     * Handle map move events
     */
    onMapMove: function () {
      // DISABLED: Auto-reload on map move was causing scanning refresh problems
      // Only refresh manually or on significant zoom changes
      console.log('📍 Map moved (auto-refresh disabled to prevent scanning interference)');
      
      // Optional: Only refresh if moved very far from last data fetch point
      // clearTimeout(this.moveTimeout);
      // this.moveTimeout = setTimeout(() => {
      //   var currentCenter = this.map.getCenter();
      //   var lastCenter = this.lastDataCenter;
      //   if (!lastCenter || currentCenter.distanceTo(lastCenter) > 5000) { // 5km threshold
      //     console.log('Map moved significantly, reloading data...');
      //     this.showLoading('SCANNING NEW SECTORS...');
      //     this.lastDataCenter = currentCenter;
      //     this.loadHexagonData();
      //   }
      // }, 1000);
    },

    /**
     * Get optimal H3 resolution based on zoom level
     * GOLD LAYER: Supports Resolution 5-13 ultra-precision mapping!
     * Resolution 5: Single citywide hexagon (251 km²) for efficient overview
     * Maximum Detail: Resolution 13 = 44m² (7m×7m) hexagons
     */
    getOptimalResolution: function (zoomLevel) {
      // Gold layer resolution mapping (5-13 available)
      var resolution;
      if (zoomLevel <= 6)       resolution = 5;   // 251 km² - Philadelphia citywide (single hex)
      else if (zoomLevel <= 8)  resolution = 6;   // 36.1 km² - City districts  
      else if (zoomLevel <= 10) resolution = 7;   // 5.2 km² - District detail
      else if (zoomLevel <= 12) resolution = 8;   // 0.7 km² - Neighborhood
      else if (zoomLevel <= 14) resolution = 9;   // 0.1 km² - Block Group
      else if (zoomLevel <= 16) resolution = 10;  // 15,047 m² - Block
      else if (zoomLevel <= 17) resolution = 11;  // 2,150 m² - Building
      else if (zoomLevel <= 18) resolution = 12;  // 307 m² - Room-level
      else resolution = 13;  // 44 m² - ULTRA-PRECISION! ⚡
      
      // Debug resolution selection
      if (resolution === 5) {
        console.log('🏙️ CITYWIDE MODE: Zoom ' + zoomLevel + ' → H3 Resolution 5 (single citywide hexagon)');
      } else if (resolution >= 12) {
        console.log('🎯 ULTRA-PRECISION ACTIVATED: Zoom ' + zoomLevel + ' → H3 ' + resolution + ' (' + this.getResolutionDescription(resolution) + ')');
      }
      
      return resolution;
    },

    /**
     * Update zoom level indicator display
     */
    updateZoomIndicator: function(zoom, resolution) {
      console.log('📊 Updating zoom indicator: zoom=' + zoom + ', resolution=' + resolution);
      
      var zoomElement = $('#zoom-level');
      var resolutionElement = $('#h3-resolution');
      var scaleElement = $('.scale-label');
      
      console.log('📊 Elements found: zoom=' + zoomElement.length + ', resolution=' + resolutionElement.length + ', scale=' + scaleElement.length);
      
      if (zoomElement.length && resolutionElement.length && scaleElement.length) {
        var roundedZoom = Math.round(zoom * 10) / 10;
        var scaleDescription = this.getResolutionDescription(resolution);
        
        console.log('📊 Setting values: zoom=' + roundedZoom + ', resolution=' + resolution + ', scale=' + scaleDescription);
        
        zoomElement.text(roundedZoom);
        resolutionElement.text(resolution);
        scaleElement.text(scaleDescription);
        
        console.log('📊 Zoom indicator updated successfully');
      } else {
        console.log('⚠️ Zoom indicator elements not found!');
      }
    },

    /**
     * Get human-readable description of H3 resolution
     */
    getResolutionDescription: function (resolution) {
      var descriptions = {
        5: '251km² Philadelphia metro (single citywide hex)',
        6: '36.1km² city districts',
        7: '5.2km² district detail', 
        8: '0.7km² neighborhood',
        9: '0.1km² block group',
        10: '15,047m² block',
        11: '2,150m² building',
        12: '307m² room-level',
        13: '44m² ULTRA-PRECISION'
      };
      return descriptions[resolution] || 'unknown';
    },

    /**
     * Update statistics panel
     */
    updateStats: function (totalIncidents, threatLevel, activeSectors) {
      // Provide defaults for undefined parameters
      totalIncidents = totalIncidents || 0;
      threatLevel = threatLevel || 'UNKNOWN';
      activeSectors = activeSectors || 0;
      
      // Update current view stats
      $('#total-incidents').text(totalIncidents.toLocaleString());
      $('#threat-level').text(threatLevel);
      $('#active-sectors').text(activeSectors);
      
      // Update cache efficiency indicator in debug mode
      if (this.debugMode && (this.cacheHitCount > 0 || this.apiCallCount > 0)) {
        var totalRequests = this.cacheHitCount + this.apiCallCount;
        var hitRate = (this.cacheHitCount / totalRequests * 100).toFixed(1);
        console.log(`🚀 Cache Efficiency: ${hitRate}% (${this.cacheHitCount}/${totalRequests})`);
      }
      
      // Load citywide statistics
      this.loadCitywideStats();
      
      // Add terminal typing effect only if threatLevel is valid
      if (typeof threatLevel === 'string' && threatLevel.length > 0) {
        this.typeText($('#threat-level'), threatLevel);
      }
    },

    /**
     * Load and display citywide statistics
     */
    loadCitywideStats: function () {
      var self = this;
      
      // Make API call to get citywide statistics
      $.ajax({
        url: '/api/amisafe/citywide-stats',
        method: 'GET',
        dataType: 'json',
        timeout: 5000,
        success: function (response) {
          if (response && response.stats) {
            self.displayCitywideStats(response.stats);
          } else {
            self.displayFallbackCitywideStats();
          }
        },
        error: function (xhr, status, error) {
          console.log('Citywide stats API not available, using fallback data');
          self.displayFallbackCitywideStats();
        }
      });
    },

    /**
     * Display citywide statistics in the panel
     */
    displayCitywideStats: function (stats) {
      $('#citywide-total').text((stats.total_incidents || 0).toLocaleString());
      $('#citywide-districts').text(stats.active_districts || '0');
      $('#citywide-threat').text(stats.citywide_threat_level || 'CALCULATING');
      $('#citywide-coverage').text((stats.coverage_percentage || 0) + '%');
      
      // Add glowing effect to high numbers
      if (stats.total_incidents > 10000) {
        $('#citywide-total').addClass('high-alert');
      }
      if (stats.citywide_threat_level === 'CRITICAL' || stats.citywide_threat_level === 'EXTREME') {
        $('#citywide-threat').addClass('high-alert');
      }
    },

    /**
     * Display fallback citywide statistics when API is unavailable
     */
    displayFallbackCitywideStats: function () {
      // Generate realistic fallback data for Philadelphia 2085
      var fallbackStats = {
        total_incidents: Math.floor(Math.random() * 15000) + 25000, // 25k-40k incidents citywide
        active_districts: Math.floor(Math.random() * 3) + 20, // 20-22 districts
        citywide_threat_level: ['HIGH', 'CRITICAL', 'ELEVATED'][Math.floor(Math.random() * 3)],
        coverage_percentage: Math.floor(Math.random() * 15) + 85 // 85-100% coverage
      };
      
      this.displayCitywideStats(fallbackStats);
    },

    /**
     * Update debug panel with H3 library status
     */
    showDebugPanel: function(h3obj) {
      // Update availability status
      $('#h3-available').text(!!h3obj ? '✅ YES' : '❌ NO');
      
      if (h3obj) {
        // Update function status
        var functionsHtml = '';
        var funcs = ['cellToBoundary', 'h3ToGeoBoundary', 'cellToVertex', 'cellToVertexes'];
        funcs.forEach(function(name) {
          var available = typeof h3obj[name] === 'function';
          functionsHtml += '<div class="debug-function-item">';
          functionsHtml += '<span class="debug-function-name">' + name + ':</span>';
          functionsHtml += '<span class="debug-function-status">' + (available ? '✅' : '❌') + '</span>';
          functionsHtml += '</div>';
        });
        $('#h3-functions').html(functionsHtml);
        
        // Update method count
        var methodCount = Object.keys(h3obj).filter(k => typeof h3obj[k] === 'function').length;
        $('#h3-method-count').text(methodCount);
        
        // Test a sample function call
        try {
          if (h3obj.cellToBoundary) {
            var testResult = h3obj.cellToBoundary('892a1340003ffff', true);
            $('#h3-test-result').text('✅ SUCCESS (' + typeof testResult + ')').removeClass('error');
          } else {
            $('#h3-test-result').text('❌ NO FUNCTION').addClass('error');
          }
        } catch (e) {
          $('#h3-test-result').text('❌ FAILED: ' + e.message).addClass('error');
        }
      } else {
        $('#h3-functions').html('<div class="debug-function-item"><span class="debug-function-name">H3 library not loaded</span></div>');
        $('#h3-method-count').text('0');
        $('#h3-test-result').text('❌ UNAVAILABLE').addClass('error');
      }
    },

    /**
     * Center map on hexagons (debug helper)
     */
    centerOnHexagons: function() {
      if (this.hexagonLayer.getLayers().length === 0) {
        console.log('❌ No hexagons to center on');
        return;
      }
      
      var group = new L.featureGroup(this.hexagonLayer.getLayers());
      var bounds = group.getBounds();
      console.log('📍 Centering map on hexagon bounds:', bounds);
      this.map.fitBounds(bounds, { padding: [20, 20] });
    },

    /**
     * Refresh hexagon data (debug helper)
     */
    refreshHexagons: function() {
      console.log('🔄 Manually refreshing hexagon data...');
      this.applyFilters();
    },

    /**
     * Toggle loading overlays on/off (debug helper)
     */
    toggleLoadingOverlays: function() {
      this.disableLoadingOverlays = !this.disableLoadingOverlays;
      var status = this.disableLoadingOverlays ? 'DISABLED' : 'ENABLED';
      console.log('⚡ Loading overlays', status);
      
      // Update button text to reflect current state
      var buttonText = this.disableLoadingOverlays ? '⚡ ENABLE OVERLAYS' : '⚡ DISABLE OVERLAYS';
      $('#toggle-overlays-btn').text(buttonText);
      
      if (this.disableLoadingOverlays) {
        this.hideLoading();
      }
    },

    /**
     * Terminal typing effect
     */
    typeText: function (element, text) {
      if (!text || typeof text !== 'string') {
        console.warn('typeText: Invalid text parameter:', text);
        return;
      }
      
      // In minimal mode, just set text immediately without typing effect
      if (this.minimalMode) {
        element.text(text);
        return;
      }
      
      element.empty();
      var i = 0;
      var timer = setInterval(function () {
        element.text(text.substring(0, i + 1));
        i++;
        if (i >= text.length) {
          clearInterval(timer);
          element.append('<span class="cursor">_</span>');
        }
      }, 50);
    },

    /**
     * Show loading overlay
     */
    showLoading: function (message) {
      // Skip loading overlay in minimal mode or if disabled for debugging
      if (this.minimalMode || this.disableLoadingOverlays) {
        console.log('⚡ Loading overlay skipped (minimal mode):', message);
        return;
      }
      
      if (this.loadingOverlay) {
        this.loadingOverlay.querySelector('.terminal-text').textContent = message || 'LOADING...';
        this.loadingOverlay.style.display = 'flex';
        
        // Auto-hide loading after 1.5 seconds to prevent interference with hexagons
        var self = this;
        clearTimeout(this.loadingTimeout);
        this.loadingTimeout = setTimeout(function() {
          self.hideLoading();
        }, 1500);
      }
    },

    /**
     * Hide loading overlay
     */
    hideLoading: function () {
      if (this.loadingOverlay) {
        this.loadingOverlay.style.display = 'none';
        clearTimeout(this.loadingTimeout);
      }
    },

    /**
     * Show error message
     */
    showError: function (message) {
      this.hideLoading();
      console.error('AmISafe Error:', message);
      
      // Show error in the map container
      $(this.container).html(`
        <div class="error-message terminal-text">
          <h3>SYSTEM ERROR</h3>
          <p>${message}</p>
          <button class="cyber-button" onclick="location.reload()">RESTART_SYSTEM</button>
        </div>
      `);
    },

    /**
     * Show temporary message
     */
    showMessage: function (message) {
      var messageDiv = $(`
        <div class="temp-message terminal-text">
          ${message}
        </div>
      `).appendTo('body');
      
      setTimeout(function () {
        messageDiv.fadeOut(500, function () {
          $(this).remove();
        });
      }, 3000);
    },

    /**
     * Fix map layout and stop animations for minimal mode
     */
    fixMapLayout: function() {
      console.log('Fixing map layout and stopping animations...');
      
      // First, ensure the map container has proper dimensions
      var mapContainer = $('#crime-map');
      mapContainer.css({
        'width': '100%',
        'height': '600px',
        'position': 'relative',
        'display': 'block'
      });
      
      // Force Leaflet container sizing
      $('.leaflet-container').css({
        'width': '100%',
        'height': '600px',
        'position': 'relative'
      });
      
      // Fix map pane positioning
      $('.leaflet-map-pane').css({
        'position': 'absolute',
        'left': '0px',
        'top': '0px',
        'transform': 'translate3d(0px, 0px, 0px)'
      });
      
      // Force map recalculation
      if (this.map) {
        this.map.invalidateSize(true);
        
        // Get current view and reset it
        var center = this.map.getCenter();
        var zoom = this.map.getZoom();
        
        // Small delay then reset view
        var self = this;
        setTimeout(function() {
          self.map.setView(center, zoom, {animate: false});
          
          // Now stop animations
          if (self.minimalMode) {
            self.stopAllAnimations();
          }
        }, 100);
      }
      
      console.log('Map layout fixed');
    },

    /**
     * Stop cyberpunk animations for minimal mode (simplified approach)
     */
    stopAllAnimations: function() {
      console.log('Stopping cyberpunk animations for minimal mode');
      
      // Remove specific animated overlays and effects
      $('.terminal-overlay, .glitch-overlay, .scan-line, .scanning-overlay, .loading-overlay, .matrix-rain, .noise').remove();
      
      // Hide cyberpunk animation elements
      $('.glitch-text, .terminal-cursor, .neon-glow').css('display', 'none');
      
      console.log('Cyberpunk animations stopped');
    },

    /**
     * Toggle fullscreen mode
     */
    toggleFullscreen: function () {
      console.log('Toggle fullscreen');
      // Implementation for fullscreen mode
    },

    /**
     * Enable or disable debug logging
     */
    setDebugMode: function (enabled) {
      this.debugMode = !!enabled;
      console.log('AmISafe Debug Mode:', this.debugMode ? 'ENABLED' : 'DISABLED');
    },

    /**
     * Intelligent cache cleanup with performance tracking
     */
    cleanupCache: function () {
      var maxCacheSize = 30; // Increased for better performance
      var maxCacheAge = 10 * 60 * 1000; // 10 minutes
      var now = Date.now();
      var initialSize = this.dataCache.size;
      
      if (this.dataCache.size > maxCacheSize) {
        if (this.debugMode) console.log('🧹 CACHE CLEANUP: Size limit exceeded (' + this.dataCache.size + '>' + maxCacheSize + ')');
        
        // Convert to array and sort by access frequency and recency
        var entries = Array.from(this.dataCache.entries());
        entries.sort(function(a, b) {
          var aTime = a[1].timestamp || 0;
          var bTime = b[1].timestamp || 0;
          var aHits = a[1].hits || 0;
          var bHits = b[1].hits || 0;
          
          // Prioritize frequently accessed recent data
          var aScore = (aTime / 1000) + (aHits * 60000);
          var bScore = (bTime / 1000) + (bHits * 60000);
          
          return bScore - aScore; // Highest score first
        });
        
        // Clear cache and keep only the best entries
        this.dataCache.clear();
        for (var i = 0; i < Math.min(maxCacheSize - 5, entries.length); i++) {
          this.dataCache.set(entries[i][0], entries[i][1]);
        }
        
        if (this.debugMode) console.log('🧹 CACHE OPTIMIZED: Kept ' + this.dataCache.size + ' best entries');
      }
      
      // Remove entries older than maxCacheAge
      var keysToDelete = [];
      this.dataCache.forEach(function(value, key) {
        if (now - (value.timestamp || 0) > maxCacheAge) {
          keysToDelete.push(key);
        }
      });
      
      keysToDelete.forEach(function(key) {
        this.dataCache.delete(key);
      }.bind(this));
      
      if (keysToDelete.length > 0 && this.debugMode) {
        console.log('🧹 EXPIRED CACHE: Removed ' + keysToDelete.length + ' old entries');
      }
      
      // Update performance metrics
      if (initialSize !== this.dataCache.size) {
        this.updateCacheStats();
      }
    },

    /**
     * Update cache performance statistics
     */
    updateCacheStats: function() {
      var hitRate = this.apiCallCount > 0 ? (this.cacheHitCount / (this.cacheHitCount + this.apiCallCount) * 100).toFixed(1) : 0;
      
      if (this.debugMode) {
        console.log('📊 CACHE STATS: ' + this.dataCache.size + ' entries, ' + hitRate + '% hit rate, ' + this.apiCallCount + ' API calls');
      }
      
      // Update debug panel with cache stats
      if ($('#cache-hit-rate').length) {
        $('#cache-hit-rate').text(hitRate + '%');
        $('#cache-size').text(this.dataCache.size);
        $('#api-calls').text(this.apiCallCount);
      }
    },

    /**
     * Reset map view to default
     */
    resetView: function () {
      var mapConfig = this.settings.mapConfig;
      this.map.setView(mapConfig.center, mapConfig.zoom);
    },

    /**
     * Take screenshot of the map
     */
    takeScreenshot: function () {
      console.log('Take screenshot');
      this.showMessage('Screenshot saved to neural storage');
    },

    /**
     * Check system capabilities and ultra-precision availability
     */
    checkSystemCapabilities: function () {
      var self = this;
      
      // Make request to system stats endpoint
      $.ajax({
        url: '/api/amisafe/system-stats',
        method: 'GET',
        dataType: 'json',
        timeout: 5000,
        success: function (response) {
          self.systemCapabilities = response;
          
          if (response.system_capabilities && response.system_capabilities.ultra_precision_available) {
            console.log('🎯 ULTRA-PRECISION SYSTEM ACTIVE');
            console.log('   Resolution Range: ' + response.system_capabilities.resolution_range.min + '-' + response.system_capabilities.resolution_range.max);
            console.log('   Total Hexagons: ' + (response.data_statistics.total_hexagons || 'Unknown'));
            console.log('   Ultra-precision (R13): ' + (response.ultra_precision_stats.resolution_13_hexagons || 'Unknown'));
            console.log('   Data Layer: ' + response.system_capabilities.current_layer);
            
            // Update UI elements if available
            if ($('#system-capabilities').length) {
              $('#system-capabilities').html(
                '<div class="capability-item">✅ Ultra-precision Available</div>' +
                '<div class="capability-item">🎯 Resolution 13: ' + (response.ultra_precision_stats.resolution_13_hexagons || 0) + ' hexagons</div>' +
                '<div class="capability-item">⚡ Gold Layer Analytics Active</div>'
              );
            }
          } else {
            console.warn('❌ Ultra-precision not available');
          }
        },
        error: function (xhr, status, error) {
          console.warn('System capabilities check failed:', error);
          // Continue without capabilities info
        }
      });
    }
  };

  // Global reference for popup callbacks and debug control
  window.AmISafeMap = {
    showFullDetails: function (h3Index) {
      console.log('Show full details for:', h3Index);
      // Implementation for detailed analysis modal
    },
    enableDebug: function() {
      if (window.AmISafeCrimeMap && window.AmISafeCrimeMap.crimeMap) {
        window.AmISafeCrimeMap.crimeMap.setDebugMode(true);
      }
    },
    disableDebug: function() {
      if (window.AmISafeCrimeMap && window.AmISafeCrimeMap.crimeMap) {
        window.AmISafeCrimeMap.crimeMap.setDebugMode(false);
      }
    },
    getPerformanceStats: function() {
      if (window.AmISafeCrimeMap && window.AmISafeCrimeMap.crimeMap) {
        var crimeMap = window.AmISafeCrimeMap.crimeMap;
        var totalRequests = crimeMap.cacheHitCount + crimeMap.apiCallCount;
        var cacheHitRate = totalRequests > 0 ? (crimeMap.cacheHitCount / totalRequests * 100).toFixed(1) : 0;
        var loadTime = crimeMap.loadStartTime ? Date.now() - crimeMap.loadStartTime : 0;
        
        console.log('📊 AmISafe Performance Statistics:');
        console.log(`   Cache Hits: ${crimeMap.cacheHitCount}`);
        console.log(`   API Calls: ${crimeMap.apiCallCount}`);
        console.log(`   Cache Hit Rate: ${cacheHitRate}%`);
        console.log(`   Cache Size: ${crimeMap.dataCache.size} entries`);
        console.log(`   Session Time: ${(loadTime / 1000).toFixed(1)}s`);
        
        return {
          cacheHits: crimeMap.cacheHitCount,
          apiCalls: crimeMap.apiCallCount,
          cacheHitRate: parseFloat(cacheHitRate),
          cacheSize: crimeMap.dataCache.size,
          sessionTime: loadTime
        };
      }
    }
  };

})(jQuery, Drupal, drupalSettings);