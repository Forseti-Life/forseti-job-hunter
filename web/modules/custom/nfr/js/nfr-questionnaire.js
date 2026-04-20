/**
 * @file
 * NFR Questionnaire form enhancements.
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.nfrQuestionnaire = {
    attach: function (context, settings) {
      // Make stepper steps clickable - only attach handlers to the div, not the link
      once('stepper-click', '.stepper-step', context).forEach(function(element) {
        const $step = $(element);
        const sectionNum = $step.data('section');
        
        // Only make completed and active steps clickable
        if ($step.hasClass('completed') || $step.hasClass('active')) {
          $step.css('cursor', 'pointer');
          
          // Check if we're on a section form (has submit buttons) or review page (no submit buttons)
          const $submitBtn = $('input[name="jump_to_' + sectionNum + '"]');
          
          if ($submitBtn.length) {
            // On section forms: intercept clicks on the div and trigger submit button
            $step.on('click', function(e) {
              // Only prevent default if clicking on something other than the link
              if (!$(e.target).closest('a.step-link').length) {
                e.preventDefault();
                $submitBtn.trigger('click');
              }
            });
          }
          // On review page: do nothing, let the <a> tag work naturally
        }
      });
      
      // Add visual feedback for form sections
      once('details-animation', '#nfr-questionnaire-form details', context).forEach(function(element) {
        $(element).on('toggle', function() {
          if (this.open) {
            $(this).find('summary').addClass('details-open');
          } else {
            $(this).find('summary').removeClass('details-open');
          }
        });
      });
      
      // Smooth scroll to top when section changes
      const steppers = once('scroll-to-top', '.nfr-process-stepper', context);
      if (steppers.length) {
        $('html, body').animate({
          scrollTop: $(steppers[0]).offset().top - 20
        }, 400);
      }
      
      // Show loading indicator on form submission
      once('submit-loading', '#nfr-questionnaire-form', context).forEach(function(element) {
        $(element).on('submit', function() {
          const $submitBtn = $(this).find('input[type="submit"]:focus, button[type="submit"]:focus');
          if ($submitBtn.length && !$submitBtn.hasClass('stepper-nav-btn')) {
            $submitBtn.prop('disabled', true);
            $submitBtn.after('<span class="form-loading-indicator">Processing...</span>');
          }
        });
      });
      
      // Handle "None of the above" mutual exclusivity in chemical activities checkboxes
      once('chemical-activities-none', 'input[name="exposure[chemical_activities][none]"]', context).forEach(function(noneCheckbox) {
        const $noneCheckbox = $(noneCheckbox);
        const $otherCheckboxes = $('input[name^="exposure[chemical_activities]"]').not($noneCheckbox);
        
        // When "None" is checked, uncheck all others
        $noneCheckbox.on('change', function() {
          if (this.checked) {
            $otherCheckboxes.prop('checked', false);
          }
        });
        
        // When any other checkbox is checked, uncheck "None"
        $otherCheckboxes.on('change', function() {
          if (this.checked) {
            $noneCheckbox.prop('checked', false);
          }
        });
      });
      
      // Handle "None of the above" mutual exclusivity in other health conditions checkboxes
      once('health-conditions-none', 'input[name="health[other_conditions][none]"]', context).forEach(function(noneCheckbox) {
        const $noneCheckbox = $(noneCheckbox);
        const $otherCheckboxes = $('input[name^="health[other_conditions]"]').not($noneCheckbox);
        
        // When "None" is checked, uncheck all others
        $noneCheckbox.on('change', function() {
          if (this.checked) {
            $otherCheckboxes.prop('checked', false);
          }
        });
        
        // When any other checkbox is checked, uncheck "None"
        $otherCheckboxes.on('change', function() {
          if (this.checked) {
            $noneCheckbox.prop('checked', false);
          }
        });
      });
    }
  };

})(jQuery, Drupal, once);


