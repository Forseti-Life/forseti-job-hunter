/**
 * @file
 * Cluster Analysis interactive features.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.clusterAnalysis = {
    attach: function (context, settings) {
      const $form = $('#nfr-cluster-analysis-form', context).once('cluster-init');
      
      if ($form.length === 0) {
        return;
      }

      // Variable selection counter
      const updateSelectionCount = function() {
        const $checkboxes = $form.find('input[type="checkbox"][name^="clustering_variables"]');
        const selectedCount = $checkboxes.filter(':checked').length;
        
        let $counter = $form.find('.variable-counter');
        if ($counter.length === 0) {
          $counter = $('<div class="variable-counter" style="margin: 1rem 0; padding: 0.75rem; background: #e3f2fd; border-radius: 4px; font-weight: 600;"></div>');
          $checkboxes.first().closest('.form-checkboxes').before($counter);
        }
        
        $counter.html('Selected: ' + selectedCount + ' variables');
        
        if (selectedCount < 2) {
          $counter.css('background', '#ffebee').css('color', '#c62828');
          $counter.html('⚠️ Select at least 2 variables (currently: ' + selectedCount + ')');
        } else if (selectedCount > 5) {
          $counter.css('background', '#fff3cd').css('color', '#856404');
          $counter.html('⚠️ Selected: ' + selectedCount + ' variables (5 or fewer recommended for interpretability)');
        } else {
          $counter.css('background', '#e8f5e9').css('color', '#2e7d32');
          $counter.html('✓ Selected: ' + selectedCount + ' variables (good for clustering)');
        }
      };

      // Update count on checkbox change
      $form.find('input[type="checkbox"][name^="clustering_variables"]').on('change', updateSelectionCount);
      
      // Initial count
      updateSelectionCount();

      // Quick selection buttons
      const $checkboxWrapper = $form.find('.form-checkboxes').first();
      if ($checkboxWrapper.length && !$form.find('.quick-select').length) {
        const $quickSelect = $('<div class="quick-select" style="margin: 1rem 0; padding: 1rem; background: #f5f5f5; border-radius: 4px;"></div>');
        $quickSelect.append('<p style="margin: 0 0 0.5rem 0; font-weight: 600;">Quick Select:</p>');
        
        // Select all button
        const $selectAll = $('<button type="button" class="quick-btn" style="margin-right: 0.5rem; padding: 0.5rem 1rem; background: white; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">Select All</button>');
        $selectAll.on('click', function() {
          $form.find('input[type="checkbox"][name^="clustering_variables"]').prop('checked', true).trigger('change');
        });
        
        // Clear all button
        const $clearAll = $('<button type="button" class="quick-btn" style="margin-right: 0.5rem; padding: 0.5rem 1rem; background: white; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">Clear All</button>');
        $clearAll.on('click', function() {
          $form.find('input[type="checkbox"][name^="clustering_variables"]').prop('checked', false).trigger('change');
        });
        
        // Preset: Exposure profile
        const $exposurePreset = $('<button type="button" class="quick-btn" style="margin-right: 0.5rem; padding: 0.5rem 1rem; background: white; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">Exposure Profile</button>');
        $exposurePreset.on('click', function() {
          $form.find('input[type="checkbox"][name^="clustering_variables"]').prop('checked', false);
          const exposureVars = ['total_years_service', 'total_structure_fires', 'avg_structure_fires_per_year', 'incident_diversity_score'];
          exposureVars.forEach(function(varName) {
            $form.find('input[type="checkbox"][value="' + varName + '"]').prop('checked', true);
          });
          updateSelectionCount();
        });
        
        // Preset: Health profile
        const $healthPreset = $('<button type="button" class="quick-btn" style="padding: 0.5rem 1rem; background: white; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">Health Profile</button>');
        $healthPreset.on('click', function() {
          $form.find('input[type="checkbox"][name^="clustering_variables"]').prop('checked', false);
          const healthVars = ['bmi', 'smoking_status', 'exercise_frequency', 'age_at_enrollment'];
          healthVars.forEach(function(varName) {
            $form.find('input[type="checkbox"][value="' + varName + '"]').prop('checked', true);
          });
          updateSelectionCount();
        });
        
        $quickSelect.append($selectAll).append($clearAll).append($exposurePreset).append($healthPreset);
        $checkboxWrapper.before($quickSelect);
      }

      // K value guidance
      const $kInput = $form.find('input[name="num_clusters"]');
      $kInput.on('input', function() {
        const kValue = parseInt($(this).val());
        const $description = $(this).siblings('.description');
        
        if (kValue < 2) {
          $description.html('⚠️ Minimum 2 clusters required for meaningful analysis.');
        } else if (kValue <= 3) {
          $description.html('✓ Good choice for simple segmentation (e.g., low/medium/high risk).');
        } else if (kValue <= 5) {
          $description.html('✓ Good balance between detail and interpretability.');
        } else if (kValue <= 7) {
          $description.html('⚠️ Many clusters - may be hard to interpret. Consider using fewer.');
        } else {
          $description.html('⚠️ Too many clusters may not be meaningful. Consider 2-5 for most analyses.');
        }
      });

      // Form submission loading indicator
      $form.on('submit', function(e) {
        const $submit = $(this).find('input[value="Run Cluster Analysis"]');
        if ($submit.length) {
          $submit.val('Clustering...').prop('disabled', true);
          
          if (!$(this).find('.clustering-spinner').length) {
            $submit.after('<span class="clustering-spinner" style="margin-left: 1rem;">⏳ Running K-means algorithm...</span>');
          }
        }
      });

      // Cluster card highlighting
      $('.cluster-card').on('mouseenter', function() {
        $(this).css('transform', 'scale(1.02)');
        $(this).css('transition', 'transform 0.2s');
      }).on('mouseleave', function() {
        $(this).css('transform', 'scale(1)');
      });

      // Print results button
      if ($('.cluster-results').length && !$('.print-clusters-btn').length) {
        const $printBtn = $('<button type="button" class="print-clusters-btn" style="margin-top: 1rem; padding: 0.75rem 1.5rem; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">🖨️ Print Results</button>');
        $printBtn.on('click', function() {
          window.print();
        });
        $('.cluster-results h2').after($printBtn);
      }

      console.log('Cluster analysis interactive features initialized');
    }
  };

})(jQuery, Drupal);
