/**
 * @file
 * JavaScript for Individual Metrics Profile page.
 */

(function (Drupal) {
  'use strict';

  Drupal.behaviors.individualMetricsProfile = {
    attach: function (context, settings) {
      // Handle dimension accordion toggle.
      const dimensionToggles = context.querySelectorAll('.dimension-toggle');
      
      dimensionToggles.forEach(function (toggle) {
        // Only attach once.
        if (toggle.dataset.processed) {
          return;
        }
        toggle.dataset.processed = 'true';
        
        toggle.addEventListener('click', function (e) {
          e.preventDefault();
          
          const expanded = this.getAttribute('aria-expanded') === 'true';
          const contentId = this.getAttribute('aria-controls');
          const content = document.getElementById(contentId);
          
          if (content) {
            if (expanded) {
              this.setAttribute('aria-expanded', 'false');
              content.hidden = true;
            } else {
              this.setAttribute('aria-expanded', 'true');
              content.hidden = false;
            }
          }
        });
      });
    }
  };

})(Drupal);
