/**
 * @file
 * JavaScript for Safety Calculator landing page.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.safetyCalculatorLanding = {
    attach: function (context, settings) {
      // Smooth scroll for CTA buttons
      $('.cta-section .button', context).once('smooth-scroll').on('click', function(e) {
        // Smooth page transitions
        $('html, body').animate({
          scrollTop: 0
        }, 300);
      });

      // Add animation to cards on scroll
      var cards = $('.user-type-card', context).once('card-animation');
      if (cards.length) {
        cards.each(function(index) {
          $(this).css('opacity', 0).delay(index * 100).animate({
            opacity: 1
          }, 500);
        });
      }
    }
  };

})(jQuery, Drupal);
