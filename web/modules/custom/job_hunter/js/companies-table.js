/**
 * Companies Table JavaScript
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.companiesTable = {
    attach: function (context, settings) {
      // Set progress bar widths from data attributes
      $('.companies-table .progress-fill', context).once('progress-fill').each(function() {
        var width = $(this).data('width');
        if (width) {
          $(this).css('width', width);
        }
      });
    }
  };

})(jQuery, Drupal);
