/**
 * @file
 * Group Location Map JavaScript
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * Group Map Behavior
   */
  Drupal.behaviors.groupLocationMap = {
    attach: function (context, settings) {
      if (!settings.groupMap) {
        console.error('Group map settings not found');
        return;
      }

      $('#group-map-container', context).once('groupLocationMap').each(function () {
        var groupMap = new GroupLocationMap(this, settings.groupMap);
        groupMap.initialize();
      });
    }
  };

  /**
   * Group Location Map Class
   */
  function GroupLocationMap(container, settings) {
    this.container = container;
    this.settings = settings;
    this.map = null;
    this.markers = {};
    this.autoRefreshInterval = null;
  }

  /**
   * Initialize the map
   */
  GroupLocationMap.prototype.initialize = function() {
    console.log('Initializing Group Location Map...', this.settings);

    // Check if Leaflet is available
    if (typeof L === 'undefined') {
      console.error('Leaflet library not loaded');
      return;
    }

    // Create the map
    this.map = L.map('group-map-container', {
      center: this.settings.mapConfig.center,
      zoom: this.settings.mapConfig.zoom,
      zoomControl: true
    });

    // Add tile layer
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors',
      maxZoom: 19
    }).addTo(this.map);

    // Add member markers
    this.addMemberMarkers();

    // Set up controls
    this.setupControls();

    // Start auto-refresh if enabled
    if ($('#auto-refresh').is(':checked')) {
      this.startAutoRefresh();
    }

    console.log('Group map initialized successfully');
  };

  /**
   * Add markers for all members
   */
  GroupLocationMap.prototype.addMemberMarkers = function() {
    var self = this;
    
    if (!this.settings.members || this.settings.members.length === 0) {
      console.log('No member locations to display');
      return;
    }

    var bounds = [];

    this.settings.members.forEach(function(member) {
      var latLng = [member.latitude, member.longitude];
      bounds.push(latLng);

      // Create custom icon with member initials
      var initials = member.username.substring(0, 2).toUpperCase();
      var iconHtml = '<div class="member-location-marker" style="background: #00d9ff; color: #000; width: 40px; height: 40px; border-radius: 50%; border: 3px solid #fff; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">' + initials + '</div>';

      var icon = L.divIcon({
        html: iconHtml,
        className: 'custom-member-marker',
        iconSize: [40, 40],
        iconAnchor: [20, 20]
      });

      // Create marker
      var marker = L.marker(latLng, { icon: icon }).addTo(self.map);

      // Create popup content
      var popupContent = '<div style="min-width: 200px;">' +
        '<h6 style="margin: 0 0 10px 0; color: #00d9ff;">' + member.username + '</h6>' +
        '<p style="margin: 0 0 5px 0; font-size: 12px;"><strong>Roles:</strong> ' + (member.roles.length > 0 ? member.roles.join(', ') : 'Member') + '</p>' +
        '<p style="margin: 0 0 5px 0; font-size: 12px;"><strong>Updated:</strong> ' + member.updated + '</p>' +
        '<p style="margin: 0; font-size: 12px;"><strong>Accuracy:</strong> ±' + (member.accuracy || 'N/A') + 'm</p>' +
        '</div>';

      marker.bindPopup(popupContent);

      // Store marker reference
      self.markers[member.uid] = marker;
    });

    // Fit map to show all markers
    if (bounds.length > 0) {
      this.map.fitBounds(bounds, { padding: [50, 50] });
    }
  };

  /**
   * Setup map controls
   */
  GroupLocationMap.prototype.setupControls = function() {
    var self = this;

    // Refresh button
    $('#refresh-locations').on('click', function() {
      self.refreshLocations();
    });

    // Center button
    $('#center-map').on('click', function() {
      self.centerOnMembers();
    });

    // Auto-refresh toggle
    $('#auto-refresh').on('change', function() {
      if ($(this).is(':checked')) {
        self.startAutoRefresh();
      } else {
        self.stopAutoRefresh();
      }
    });

    // Legend item click to focus on member
    $('.member-marker').on('click', function() {
      var uid = $(this).data('uid');
      if (self.markers[uid]) {
        self.map.setView(self.markers[uid].getLatLng(), 16);
        self.markers[uid].openPopup();
      }
    });
  };

  /**
   * Refresh member locations from API
   */
  GroupLocationMap.prototype.refreshLocations = function() {
    var self = this;
    
    $('#group-map-loading').removeClass('d-none');

    $.ajax({
      url: this.settings.apiEndpoints.latestLocations,
      method: 'GET',
      dataType: 'json',
      success: function(response) {
        if (response.status === 'success' && response.data && response.data.locations) {
          // Clear existing markers
          Object.keys(self.markers).forEach(function(uid) {
            self.map.removeLayer(self.markers[uid]);
          });
          self.markers = {};

          // Update settings with new data
          self.settings.members = response.data.locations.map(function(loc) {
            return {
              uid: loc.uid,
              username: loc.username || 'Unknown',
              latitude: loc.latitude,
              longitude: loc.longitude,
              accuracy: loc.accuracy,
              timestamp: loc.timestamp,
              roles: loc.roles || [],
              updated: new Date(loc.timestamp * 1000).toLocaleString()
            };
          });

          // Re-add markers
          self.addMemberMarkers();
          
          console.log('Locations refreshed successfully');
        }
      },
      error: function(xhr, status, error) {
        console.error('Failed to refresh locations:', error);
      },
      complete: function() {
        $('#group-map-loading').addClass('d-none');
      }
    });
  };

  /**
   * Center map on all members
   */
  GroupLocationMap.prototype.centerOnMembers = function() {
    var bounds = [];
    
    Object.keys(this.markers).forEach(function(uid) {
      var marker = this.markers[uid];
      bounds.push(marker.getLatLng());
    }, this);

    if (bounds.length > 0) {
      this.map.fitBounds(bounds, { padding: [50, 50] });
    }
  };

  /**
   * Start auto-refresh timer
   */
  GroupLocationMap.prototype.startAutoRefresh = function() {
    var self = this;
    
    if (this.autoRefreshInterval) {
      clearInterval(this.autoRefreshInterval);
    }

    this.autoRefreshInterval = setInterval(function() {
      console.log('Auto-refreshing locations...');
      self.refreshLocations();
    }, 30000); // 30 seconds

    console.log('Auto-refresh started (30s interval)');
  };

  /**
   * Stop auto-refresh timer
   */
  GroupLocationMap.prototype.stopAutoRefresh = function() {
    if (this.autoRefreshInterval) {
      clearInterval(this.autoRefreshInterval);
      this.autoRefreshInterval = null;
      console.log('Auto-refresh stopped');
    }
  };

})(jQuery, Drupal, drupalSettings);
