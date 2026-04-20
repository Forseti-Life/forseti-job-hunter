(function (Drupal) {
  'use strict';

  Drupal.behaviors.sliderUpdate = {
    attach: function (context, settings) {
      // Find all rating sliders
      const sliders = context.querySelectorAll('.rating-slider');
      
      sliders.forEach(function(slider) {
        // Only attach once
        if (slider.dataset.listenerAttached) {
          return;
        }
        slider.dataset.listenerAttached = 'true';
        
        // Get the target display element
        const targetId = slider.dataset.displayTarget;
        if (!targetId) return;
        
        const displayElement = document.getElementById(targetId);
        if (!displayElement) return;
        
        // Update display on input (real-time as slider moves)
        slider.addEventListener('input', function() {
          displayElement.textContent = this.value;
        });
        
        // Also update on change (when slider is released)
        slider.addEventListener('change', function() {
          displayElement.textContent = this.value;
        });
      });
    }
  };
})(Drupal);
