(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.nfrDashboard = {
    attach: function (context, settings) {
      $('.nfr-dashboard', context).once('nfrDashboard').each(function () {
        console.log('NFR Dashboard initialized');
      });
    }
  };

})(jQuery, Drupal);
