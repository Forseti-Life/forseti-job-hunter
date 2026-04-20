/**
 * @file
 * JavaScript for Individual Metrics Edit Form.
 */

(function (Drupal) {
  'use strict';

  Drupal.behaviors.individualMetricsEdit = {
    attach: function (context, settings) {
      // Update range slider values in real-time.
      const rangeInputs = context.querySelectorAll('input[type="range"]');
      
      rangeInputs.forEach(function (input) {
        // Only attach once.
        if (input.dataset.processed) {
          return;
        }
        input.dataset.processed = 'true';
        
        // Find the output element.
        const output = input.parentElement.querySelector('.scale-value');
        
        if (output) {
          // Update on input change.
          input.addEventListener('input', function () {
            output.textContent = this.value;
          });
        }
      });

      // Add confirmation before leaving with unsaved changes.
      const form = context.querySelector('#individual-metrics-edit-form');
      if (form && !form.dataset.processed) {
        form.dataset.processed = 'true';
        
        let formChanged = false;
        
        // Track form changes.
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(function (input) {
          input.addEventListener('change', function () {
            formChanged = true;
          });
        });
        
        // Warn before leaving.
        window.addEventListener('beforeunload', function (e) {
          if (formChanged) {
            const message = 'You have unsaved changes. Are you sure you want to leave?';
            e.returnValue = message;
            return message;
          }
        });
        
        // Reset flag on submit.
        form.addEventListener('submit', function () {
          formChanged = false;
        });
      }
    }
  };

})(Drupal);
