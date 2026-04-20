/**
 * @file
 * Community Incident Layer for AmISafe crime map.
 *
 * Adds a toggleable "Community Reports" layer to the AmISafe Leaflet map.
 * Depends on window.AmISafeMap being exposed by amisafe/crime-map.
 * Layer is off by default (stored in sessionStorage).
 */
(function (Drupal, $) {
  'use strict';

  Drupal.behaviors.communityIncidentLayer = {
    attach: function (context, settings) {
      // Wait for AmISafe map to initialize.
      var maxAttempts = 20;
      var attempts = 0;
      var self = this;

      function tryAttach() {
        if (window.AmISafeMap && window.AmISafeMap.map) {
          self._attachLayer(window.AmISafeMap.map);
        } else if (attempts < maxAttempts) {
          attempts++;
          setTimeout(tryAttach, 300);
        }
      }

      // Only attach once per page load.
      if (!window._communityLayerAttached) {
        window._communityLayerAttached = true;
        tryAttach();
      }
    },

    _attachLayer: function (leafletMap) {
      var geojsonUrl = '/api/community-incidents/geojson';
      var storageKey = 'communityIncidentLayerOn';
      var isOn = sessionStorage.getItem(storageKey) === 'true';

      // Build GeoJSON layer (no actual geometry for v1 — pins shown at center
      // of map bounds as placeholder until geocoding is added).
      var communityLayer = L.layerGroup();

      function loadLayer() {
        fetch(geojsonUrl)
          .then(function (r) { return r.json(); })
          .then(function (data) {
            communityLayer.clearLayers();
            if (!data.features || data.features.length === 0) { return; }
            var bounds = leafletMap.getBounds();
            var center = bounds ? bounds.getCenter() : leafletMap.getCenter();
            data.features.forEach(function (f) {
              var props = f.properties;
              var marker = L.circleMarker([center.lat, center.lng], {
                radius: 8,
                fillColor: '#e53e3e',
                color: '#fff',
                weight: 2,
                opacity: 1,
                fillOpacity: 0.85,
              });
              marker.bindPopup(
                '<strong>' + (props.title || '') + '</strong><br/>' +
                'Type: ' + (props.type || '') + '<br/>' +
                'Location: ' + (props.location || 'Not provided') + '<br/>' +
                'Reported: ' + (props.created || '')
              );
              communityLayer.addLayer(marker);
            });
          })
          .catch(function (e) { console.error('Community layer load failed', e); });
      }

      // Build toggle button.
      var toggleBtn = L.control({ position: 'topright' });
      toggleBtn.onAdd = function () {
        var div = L.DomUtil.create('div', 'community-layer-toggle leaflet-bar');
        div.style.cssText = 'background:#fff;padding:6px 10px;cursor:pointer;font-size:13px;border:2px solid rgba(0,0,0,0.2);border-radius:4px;user-select:none;';
        function updateLabel() {
          div.innerHTML = isOn ? '🔴 Hide Community Reports' : '🔴 Community Reports';
          div.title = isOn ? 'Hide community safety reports' : 'Show community safety reports';
        }
        updateLabel();
        L.DomEvent.on(div, 'click', function (e) {
          L.DomEvent.stopPropagation(e);
          isOn = !isOn;
          sessionStorage.setItem(storageKey, isOn ? 'true' : 'false');
          if (isOn) {
            loadLayer();
            communityLayer.addTo(leafletMap);
          } else {
            leafletMap.removeLayer(communityLayer);
          }
          updateLabel();
        });
        return div;
      };
      toggleBtn.addTo(leafletMap);

      // Apply saved state.
      if (isOn) {
        loadLayer();
        communityLayer.addTo(leafletMap);
      }
    }
  };

})(Drupal, jQuery);
