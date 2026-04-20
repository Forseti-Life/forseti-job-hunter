/**
 * @file
 * JavaScript for AmISafe Log Management interface.
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.amisafeLogManagement = {
    attach: function (context, settings) {
      // View log button handler
      $('.view-log-btn', context).each(function() {
        if (!$(this).data('log-view-attached')) {
          $(this).data('log-view-attached', true);
          $(this).on('click', function() {
            var logId = $(this).data('log-id');
            loadAndDisplayLog(logId);
          });
        }
      });

      // Delete log button handler
      $('.delete-log-btn', context).each(function() {
        if (!$(this).data('log-delete-attached')) {
          $(this).data('log-delete-attached', true);
          $(this).on('click', function() {
            var logId = $(this).data('log-id');
            if (confirm(Drupal.t('Are you sure you want to delete this log?'))) {
              deleteLog(logId);
            }
          });
        }
      });

      // Show device info button handler
      $('.show-device-info', context).each(function() {
        if (!$(this).data('device-info-attached')) {
          $(this).data('device-info-attached', true);
          $(this).on('click', function() {
            var deviceInfo = $(this).data('device-info');
            showDeviceInfo(deviceInfo);
          });
        }
      });

      // Close modal handlers
      $('.close-modal', context).each(function() {
        if (!$(this).data('modal-close-attached')) {
          $(this).data('modal-close-attached', true);
          $(this).on('click', function() {
            $(this).closest('.log-modal').hide();
          });
        }
      });

      // Copy log button
      $('#copy-log-btn', context).each(function() {
        if (!$(this).data('copy-log-attached')) {
          $(this).data('copy-log-attached', true);
          $(this).on('click', function() {
            var logContent = $('#log-content-display').text();
            navigator.clipboard.writeText(logContent).then(function() {
              alert(Drupal.t('Log copied to clipboard!'));
            }).catch(function(err) {
              console.error('Failed to copy:', err);
            });
          });
        }
      });

      // Download log button
      $('#download-log-btn', context).each(function() {
        if (!$(this).data('download-log-attached')) {
          $(this).data('download-log-attached', true);
          $(this).on('click', function() {
            var logContent = $('#log-content-display').text();
            var userId = $('#log-user-id').text();
            var timestamp = $('#log-uploaded-at').text();
            var filename = 'console-log-' + userId + '-' + timestamp.replace(/[: ]/g, '-') + '.txt';
            
            var blob = new Blob([logContent], { type: 'text/plain' });
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = filename;
            a.click();
            window.URL.revokeObjectURL(url);
          });
        }
      });

      // Click outside modal to close
      $('.log-modal', context).each(function() {
        if (!$(this).data('modal-outside-attached')) {
          $(this).data('modal-outside-attached', true);
          $(this).on('click', function(e) {
            if ($(e.target).hasClass('log-modal')) {
              $(this).hide();
            }
          });
        }
      });
    }
  };

  /**
   * Load and display a log file.
   */
  function loadAndDisplayLog(logId) {
    $.ajax({
      url: '/api/amisafe/log/' + logId,
      method: 'GET',
      dataType: 'json',
      success: function(response) {
        if (response.success && response.log) {
          var log = response.log;
          
          // Populate modal
          $('#log-user-id').text(log.user_id);
          $('#log-app-version').text(log.app_version || 'N/A');
          $('#log-uploaded-at').text(log.uploaded_at);
          $('#log-content-display').text(log.log_content);
          
          // Show modal
          $('#log-viewer-modal').show();
        } else {
          alert(Drupal.t('Failed to load log: ') + (response.error || 'Unknown error'));
        }
      },
      error: function(xhr, status, error) {
        console.error('Log load error:', error);
        alert(Drupal.t('Failed to load log. Please try again.'));
      }
    });
  }

  /**
   * Delete a log file.
   */
  function deleteLog(logId) {
    $.ajax({
      url: '/api/amisafe/log/' + logId + '/delete',
      method: 'POST',
      dataType: 'json',
      success: function(response) {
        if (response.success) {
          // Remove the row from the table
          $('tr[data-log-id="' + logId + '"]').fadeOut(300, function() {
            $(this).remove();
            
            // Check if table is now empty
            if ($('.log-table tbody tr').length === 0) {
              location.reload();
            }
          });
        } else {
          alert(Drupal.t('Failed to delete log: ') + (response.error || 'Unknown error'));
        }
      },
      error: function(xhr, status, error) {
        console.error('Log delete error:', error);
        alert(Drupal.t('Failed to delete log. Please try again.'));
      }
    });
  }

  /**
   * Show device information modal.
   */
  function showDeviceInfo(deviceInfo) {
    try {
      var deviceData = typeof deviceInfo === 'string' ? JSON.parse(deviceInfo) : deviceInfo;
      var formatted = JSON.stringify(deviceData, null, 2);
      $('#device-info-display').text(formatted);
      $('#device-info-modal').show();
    } catch (e) {
      $('#device-info-display').text(deviceInfo);
      $('#device-info-modal').show();
    }
  }

})(jQuery, Drupal);
