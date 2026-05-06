/**
 * @file
 * Company Research page interactions.
 */

(function ($, Drupal, once) {
  'use strict';

  /**
   * Company Research behavior.
   */
  Drupal.behaviors.companyResearch = {
    attach: function (context, settings) {
      // Initialize company research page
      once('company-research-init', '.company-research-page', context).forEach(function (element) {
        console.log('Company Research page initialized');

        // Add hover effects for company cards
        const cards = element.querySelectorAll('.company-card');
        cards.forEach(function (card) {
          card.addEventListener('mouseenter', function () {
            this.style.borderColor = '#667eea';
          });

          card.addEventListener('mouseleave', function () {
            if (!this.classList.contains('active')) {
              this.style.borderColor = '#e0e0e0';
            }
          });
        });

        // Add click-to-expand for long descriptions
        const descriptions = element.querySelectorAll('.company-description');
        descriptions.forEach(function (desc) {
          if (desc.textContent.includes('...')) {
            desc.style.cursor = 'pointer';
            desc.title = 'Click to expand';

            desc.addEventListener('click', function () {
              // Future: Could expand to show full description
              console.log('Description clicked');
            });
          }
        });
      });
    }
  };

})(jQuery, Drupal, once);
