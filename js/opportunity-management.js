/**
 * @file
 * JavaScript for Opportunity Management page.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.opportunityManagement = {
    attach: function (context, settings) {
      const page = $('.opportunity-management-page', context).once('opportunity-management');
      if (!page.length) {
        return;
      }

      // Tab switching
      $('.tab-button', context).on('click', function() {
        const tabName = $(this).data('tab');
        
        // Update button states and ARIA attributes
        $('.tab-button').removeClass('active').attr('aria-selected', 'false');
        $(this).addClass('active').attr('aria-selected', 'true');
        
        // Update tab content visibility
        $('.tab-content').removeClass('active');
        $('#' + tabName + '-tab').addClass('active');
        
        // Clear any selected checkboxes and messages
        $('input[type="checkbox"]').prop('checked', false);
        updateBulkButtons();
        clearMessages();
      });

      // Select All functionality for jobs
      $('#select-all-jobs, #select-all-jobs-checkbox', context).on('click', function() {
        const isChecked = $('#select-all-jobs-checkbox').prop('checked');
        $('.job-checkbox').prop('checked', isChecked);
        updateBulkButtons();
      });

      // Select All functionality for searches
      $('#select-all-searches, #select-all-searches-checkbox', context).on('click', function() {
        const isChecked = $('#select-all-searches-checkbox').prop('checked');
        $('.search-checkbox').prop('checked', isChecked);
        updateBulkButtons();
      });

      // Individual checkbox change
      $('.job-checkbox, .search-checkbox', context).on('change', function() {
        updateBulkButtons();
      });

      // Delete single job
      $('.delete-job', context).on('click', function() {
        const jobId = $(this).data('job-id');
        const row = $(this).closest('tr');
        const jobTitle = row.find('td:nth-child(2) a').text().trim();
        
        showConfirmModal(
          Drupal.t('Delete Job'),
          Drupal.t('Are you sure you want to delete "@title"?', {'@title': jobTitle}),
          function() {
            deleteJob(jobId, row);
          }
        );
      });

      // Delete single search
      $('.delete-search', context).on('click', function() {
        const searchId = $(this).data('search-id');
        const row = $(this).closest('tr');
        const query = row.find('td:nth-child(3) strong').text().trim();
        
        showConfirmModal(
          Drupal.t('Delete Search History'),
          Drupal.t('Are you sure you want to delete the search for "@query"? This will also delete all associated cached results.', {'@query': query}),
          function() {
            deleteSearch(searchId, row);
          }
        );
      });

      // Bulk delete jobs
      $('#bulk-delete-jobs', context).on('click', function() {
        const selectedIds = getSelectedJobIds();
        if (selectedIds.length === 0) {
          return;
        }

        if (selectedIds.length > 100) {
          showMessage('error', Drupal.t('Cannot delete more than 100 jobs at once. Please select fewer items.'));
          return;
        }
        
        showConfirmModal(
          Drupal.t('Bulk Delete Jobs'),
          Drupal.t('Are you sure you want to delete @count job(s)?', {'@count': selectedIds.length}),
          function() {
            bulkDeleteJobs(selectedIds);
          }
        );
      });

      // Bulk delete searches
      $('#bulk-delete-searches', context).on('click', function() {
        const selectedIds = getSelectedSearchIds();
        if (selectedIds.length === 0) {
          return;
        }

        if (selectedIds.length > 100) {
          showMessage('error', Drupal.t('Cannot delete more than 100 searches at once. Please select fewer items.'));
          return;
        }
        
        showConfirmModal(
          Drupal.t('Bulk Delete Search History'),
          Drupal.t('Are you sure you want to delete @count search(es)? This will also delete all associated cached results.', {'@count': selectedIds.length}),
          function() {
            bulkDeleteSearches(selectedIds);
          }
        );
      });

      // Modal close handlers
      $('.modal-close, #modal-cancel', context).on('click', function() {
        hideConfirmModal();
      });

      // Click outside modal to close
      $('#confirm-modal', context).on('click', function(e) {
        if ($(e.target).is('#confirm-modal')) {
          hideConfirmModal();
        }
      });

      /**
       * Get selected job IDs
       */
      function getSelectedJobIds() {
        const ids = [];
        $('.job-checkbox:checked').each(function() {
          ids.push($(this).val());
        });
        return ids;
      }

      /**
       * Get selected search IDs
       */
      function getSelectedSearchIds() {
        const ids = [];
        $('.search-checkbox:checked').each(function() {
          ids.push($(this).val());
        });
        return ids;
      }

      /**
       * Update bulk action button states
       */
      function updateBulkButtons() {
        const jobsSelected = $('.job-checkbox:checked').length;
        const searchesSelected = $('.search-checkbox:checked').length;
        
        $('#bulk-delete-jobs').prop('disabled', jobsSelected === 0);
        $('#bulk-delete-searches').prop('disabled', searchesSelected === 0);
      }

      /**
       * Delete a single job
       */
      function deleteJob(jobId, row) {
        showLoading(row);
        
        $.ajax({
          url: '/jobhunter/opportunity/delete-job',
          type: 'POST',
          dataType: 'json',
          data: { job_id: jobId },
          success: function(response) {
            hideLoading(row);
            if (response.success) {
              row.fadeOut(300, function() {
                $(this).remove();
                updateStats('saved_jobs', -1);
              });
              showMessage('success', response.message);
            } else {
              showMessage('error', response.message);
            }
          },
          error: function() {
            hideLoading(row);
            showMessage('error', Drupal.t('An error occurred while deleting the job.'));
          }
        });
      }

      /**
       * Delete a single search
       */
      function deleteSearch(searchId, row) {
        showLoading(row);
        
        $.ajax({
          url: '/jobhunter/opportunity/delete-search',
          type: 'POST',
          dataType: 'json',
          data: { search_id: searchId },
          success: function(response) {
            hideLoading(row);
            if (response.success) {
              row.fadeOut(300, function() {
                $(this).remove();
                updateStats('search_histories', -1);
              });
              showMessage('success', response.message);
            } else {
              showMessage('error', response.message);
            }
          },
          error: function() {
            hideLoading(row);
            showMessage('error', Drupal.t('An error occurred while deleting the search.'));
          }
        });
      }

      /**
       * Bulk delete jobs
       */
      function bulkDeleteJobs(jobIds) {
        const rows = jobIds.map(id => $(`tr[data-job-id="${id}"]`));
        rows.forEach(row => showLoading(row));
        
        $.ajax({
          url: '/jobhunter/opportunity/bulk-delete',
          type: 'POST',
          dataType: 'json',
          data: {
            type: 'jobs',
            ids: jobIds
          },
          success: function(response) {
            rows.forEach(row => hideLoading(row));
            if (response.success) {
              rows.forEach((row, index) => {
                setTimeout(() => {
                  row.fadeOut(300, function() {
                    $(this).remove();
                  });
                }, index * 50);
              });
              updateStats('saved_jobs', -jobIds.length);
              showMessage('success', response.message);
              $('#select-all-jobs-checkbox').prop('checked', false);
              updateBulkButtons();
            } else {
              showMessage('error', response.message);
            }
          },
          error: function() {
            rows.forEach(row => hideLoading(row));
            showMessage('error', Drupal.t('An error occurred during bulk delete.'));
          }
        });
      }

      /**
       * Bulk delete searches
       */
      function bulkDeleteSearches(searchIds) {
        const rows = searchIds.map(id => $(`tr[data-search-id="${id}"]`));
        rows.forEach(row => showLoading(row));
        
        $.ajax({
          url: '/jobhunter/opportunity/bulk-delete',
          type: 'POST',
          dataType: 'json',
          data: {
            type: 'searches',
            ids: searchIds
          },
          success: function(response) {
            rows.forEach(row => hideLoading(row));
            if (response.success) {
              rows.forEach((row, index) => {
                setTimeout(() => {
                  row.fadeOut(300, function() {
                    $(this).remove();
                  });
                }, index * 50);
              });
              updateStats('search_histories', -searchIds.length);
              showMessage('success', response.message);
              $('#select-all-searches-checkbox').prop('checked', false);
              updateBulkButtons();
            } else {
              showMessage('error', response.message);
            }
          },
          error: function() {
            rows.forEach(row => hideLoading(row));
            showMessage('error', Drupal.t('An error occurred during bulk delete.'));
          }
        });
      }

      /**
       * Update stats counter
       */
      function updateStats(statType, change) {
        const statCard = $(`.stat-card:has(.stat-label:contains("${getStatLabel(statType)}"))`);
        const valueElement = statCard.find('.stat-value');
        const currentValue = parseInt(valueElement.text()) || 0;
        const newValue = Math.max(0, currentValue + change);
        valueElement.text(newValue);
      }

      /**
       * Get stat label from type
       */
      function getStatLabel(statType) {
        const labels = {
          'saved_jobs': 'Saved Jobs',
          'search_histories': 'Search Histories',
          'cached_results': 'Cached Results'
        };
        return labels[statType] || '';
      }

      /**
       * Show loading state on row
       */
      function showLoading(row) {
        row.css('opacity', '0.5').addClass('loading');
        row.find('button').prop('disabled', true);
      }

      /**
       * Hide loading state on row
       */
      function hideLoading(row) {
        row.css('opacity', '1').removeClass('loading');
        row.find('button').prop('disabled', false);
      }

      /**
       * Show confirmation modal
       */
      function showConfirmModal(title, message, onConfirm) {
        $('#modal-title').text(title);
        $('#modal-message').text(message);
        $('#confirm-modal').removeClass('hidden').fadeIn(200);
        
        // Remove previous click handlers and add new one
        $('#modal-confirm').off('click').on('click', function() {
          hideConfirmModal();
          onConfirm();
        });
      }

      /**
       * Hide confirmation modal
       */
      function hideConfirmModal() {
        $('#confirm-modal').fadeOut(200, function() {
          $(this).addClass('hidden');
        });
        $('#modal-confirm').off('click');
      }

      /**
       * Show message
       */
      function showMessage(type, message) {
        const messageClass = type === 'error' ? 'message-error' : 'message-success';
        const icon = type === 'error' ? '❌' : '✅';
        
        const messageHtml = `
          <div class="management-message ${messageClass}">
            <span class="message-icon">${icon}</span>
            <span class="message-text">${message}</span>
            <button class="message-close">&times;</button>
          </div>
        `;
        
        const messageElement = $(messageHtml);
        $('#management-messages').append(messageElement);
        
        messageElement.find('.message-close').on('click', function() {
          $(this).closest('.management-message').fadeOut(200, function() {
            $(this).remove();
          });
        });
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
          messageElement.fadeOut(200, function() {
            $(this).remove();
          });
        }, 5000);
      }

      /**
       * Clear all messages
       */
      function clearMessages() {
        $('#management-messages').empty();
      }
    }
  };

})(jQuery, Drupal);
