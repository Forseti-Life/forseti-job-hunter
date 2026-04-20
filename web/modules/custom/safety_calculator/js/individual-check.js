/**
 * @file
 * JavaScript for individual safety check form.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.safetyCalculatorCheck = {
    attach: function (context, settings) {
      // Handle "Use Current Location" button
      $('.use-current-location-btn', context).once('geolocation').on('click', function(e) {
        e.preventDefault();
        
        if ('geolocation' in navigator) {
          $(this).prop('disabled', true).val('📍 Getting location...');
          
          navigator.geolocation.getCurrentPosition(
            function(position) {
              // Fill in the coordinates
              $('input[name="latitude"]').val(position.coords.latitude);
              $('input[name="longitude"]').val(position.coords.longitude);
              
              // Open the coordinates details
              $('.location-fieldset details').attr('open', true);
              
              // Reset button
              $('.use-current-location-btn')
                .prop('disabled', false)
                .val('✓ Location set!');
              
              setTimeout(function() {
                $('.use-current-location-btn').val('📍 Use My Current Location');
              }, 2000);
            },
            function(error) {
              alert('Error getting location: ' + error.message);
              $('.use-current-location-btn')
                .prop('disabled', false)
                .val('📍 Use My Current Location');
            }
          );
        } else {
          alert('Geolocation is not supported by your browser.');
        }
        
        return false;
      });

      // Auto-hide address field when coordinates are entered
      $('input[name="latitude"], input[name="longitude"]', context).on('input', function() {
        var lat = $('input[name="latitude"]').val();
        var lon = $('input[name="longitude"]').val();
        
        if (lat && lon) {
          $('input[name="address"]').attr('placeholder', 'Using coordinates instead...');
        } else {
          $('input[name="address"]').attr('placeholder', 'Enter address...');
        }
      });

      // Add loading state to submit button
      $('#safety-calculator-individual-check').on('submit', function() {
        var $btn = $(this).find('.calculate-btn');
        $btn.prop('disabled', true).val('⏳ Calculating...');
      });
    }
  };

})(jQuery, Drupal);
