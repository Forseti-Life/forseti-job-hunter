/**
 * @file
 * Correlation Analysis interactive features.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.correlationAnalysis = {
    attach: function (context, settings) {
      // Variable selection helpers
      const $form = $('#nfr-correlation-analysis-form', context).once('correlation-init');
      
      if ($form.length === 0) {
        return;
      }

      // Add helpful tooltips
      const variableDescriptions = {
        'total_years_service': 'Total cumulative years of firefighting service across all positions',
        'total_structure_fires': 'Estimated total number of structure fire responses',
        'has_cancer_diagnosis': 'Binary indicator (0=No, 1=Yes) of cancer diagnosis',
        'age_first_cancer_diagnosis': 'Age when first diagnosed with cancer',
        'bmi': 'Body Mass Index calculated from height and weight',
        'data_quality_score': 'Score from 0-100 based on data completeness',
      };

      // Add descriptions on select change
      $('select[name="variable_x"], select[name="variable_y"]').on('change', function() {
        const $select = $(this);
        const value = $select.val();
        const $description = $select.closest('.form-item').find('.description');
        
        if (variableDescriptions[value]) {
          const originalDesc = $description.text();
          if (!originalDesc.includes('•')) {
            $description.text(originalDesc + ' • ' + variableDescriptions[value]);
          }
        }
      });

      // Validate that X and Y are different
      $('input[value="Run Analysis"]').on('click', function(e) {
        const varX = $('select[name="variable_x"]').val();
        const varY = $('select[name="variable_y"]').val();
        
        if (varX === varY) {
          e.preventDefault();
          alert('Please select different variables for X and Y axes. Correlating a variable with itself always produces r=1.0.');
          return false;
        }
      });

      // Add loading indicator
      $('#nfr-correlation-analysis-form').on('submit', function() {
        const $submit = $(this).find('input[value="Run Analysis"]');
        if ($submit.length) {
          $submit.val('Analyzing...').prop('disabled', true);
          
          // Add spinner
          if (!$(this).find('.analysis-spinner').length) {
            $submit.after('<span class="analysis-spinner" style="margin-left: 1rem;">⏳ Calculating correlation...</span>');
          }
        }
      });

      // Highlight strong correlations
      const $coefNumber = $('.coef-number');
      if ($coefNumber.length) {
        const coef = parseFloat($coefNumber.text());
        const absCoef = Math.abs(coef);
        
        if (absCoef >= 0.7) {
          $coefNumber.css('color', '#28a745'); // Green for strong
        } else if (absCoef >= 0.4) {
          $coefNumber.css('color', '#ffc107'); // Yellow for moderate
        } else {
          $coefNumber.css('color', '#6c757d'); // Gray for weak
        }
      }

      // Add quick filter buttons
      const $filtersDetail = $('details:contains("Filters")');
      if ($filtersDetail.length && !$('.quick-filters').length) {
        const quickFilters = $('<div class="quick-filters" style="margin-top: 1rem; padding: 1rem; background: #f0f0f0; border-radius: 4px;"></div>');
        quickFilters.append('<p style="margin-top: 0; font-weight: 600;">Quick Filters:</p>');
        
        const highQualityBtn = $('<button type="button" class="quick-filter-btn" style="margin-right: 0.5rem; padding: 0.5rem 1rem; background: white; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">High Quality Only (90+)</button>');
        highQualityBtn.on('click', function() {
          $('input[name="min_quality_score"]').val(90);
          $(this).css('background', '#c8102e').css('color', 'white');
        });
        
        const cancerOnlyBtn = $('<button type="button" class="quick-filter-btn" style="margin-right: 0.5rem; padding: 0.5rem 1rem; background: white; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">Cancer Cases Only</button>');
        cancerOnlyBtn.on('click', function() {
          $('select[name="cancer_filter"]').val('1');
          $(this).css('background', '#c8102e').css('color', 'white');
        });
        
        const resetBtn = $('<button type="button" class="quick-filter-btn" style="padding: 0.5rem 1rem; background: white; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">Reset Filters</button>');
        resetBtn.on('click', function() {
          $('input[name="min_quality_score"]').val(70);
          $('select[name="sex_filter"]').val('');
          $('select[name="cancer_filter"]').val('');
          $('.quick-filter-btn').css('background', 'white').css('color', 'inherit');
        });
        
        quickFilters.append(highQualityBtn).append(cancerOnlyBtn).append(resetBtn);
        $filtersDetail.find('summary').after(quickFilters);
      }

      // Print results button
      if ($('.correlation-results').length && !$('.print-results-btn').length) {
        const printBtn = $('<button type="button" class="print-results-btn" style="margin-top: 1rem; padding: 0.75rem 1.5rem; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">🖨️ Print Results</button>');
        printBtn.on('click', function() {
          window.print();
        });
        $('.correlation-results h2').after(printBtn);
      }

      console.log('Correlation analysis interactive features initialized');
    }
  };

})(jQuery, Drupal);
