/**
 * @file
 * JavaScript for Google Jobs Integration.
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.googleJobsIntegration = {
    attach: function (context, settings) {
      
      // Get CSRF token for AJAX requests
      let csrfToken = null;
      
      function getCsrfToken() {
        if (csrfToken) {
          return Promise.resolve(csrfToken);
        }
        return fetch('/session/token')
          .then(response => response.text())
          .then(token => {
            csrfToken = token;
            return token;
          });
      }
      
      // Initialize home page once
      once('google-jobs-init', '.google-jobs-integration-home', context).forEach(function (element) {
        const $element = $(element);
        
        // Refresh statistics
        $('#refresh-stats').on('click', function () {
          location.reload();
        });

        // Toggle job sync
        $('.btn-toggle').on('click', function () {
          const $btn = $(this);
          const jobId = $btn.data('job-id');
          const currentlyEnabled = $btn.data('enabled') == 1;
          const newEnabled = !currentlyEnabled;
          
          $btn.prop('disabled', true);
          
          getCsrfToken().then(token => {
            $.ajax({
              url: '/jobhunter/googlejobsintegration/toggle-sync',
              method: 'POST',
              contentType: 'application/json',
              headers: {
                'X-CSRF-Token': token
              },
              data: JSON.stringify({
                job_id: jobId,
                enabled: newEnabled ? 1 : 0
              }),
            success: function (response) {
              if (response.success) {
                showMessage('success', response.message);
                // Update button state
                $btn.data('enabled', newEnabled ? 1 : 0);
                $btn.removeClass('btn-outline-success btn-outline-danger');
                $btn.addClass(newEnabled ? 'btn-outline-danger' : 'btn-outline-success');
                $btn.attr('title', (newEnabled ? 'Disable' : 'Enable') + ' Google Jobs');
                $btn.find('i').removeClass('bi-toggle-on bi-toggle-off');
                $btn.find('i').addClass(newEnabled ? 'bi-toggle-on' : 'bi-toggle-off');
                
                // Update status badge in the row
                const $row = $btn.closest('tr');
                const $statusCell = $row.find('td').eq(3);
                if (newEnabled) {
                  $statusCell.html('<span class="badge badge-sync-pending">Pending</span>');
                } else {
                  $statusCell.html('<span class="badge bg-secondary">Disabled</span>');
                }
              } else {
                showMessage('error', 'Failed to toggle sync status');
              }
              $btn.prop('disabled', false);
            },
            error: function () {
              showMessage('error', 'Error communicating with server');
              $btn.prop('disabled', false);
            }
          });
        });
        });

        // Generate structured data
        $('.btn-generate').on('click', function () {
          const $btn = $(this);
          const jobId = $btn.data('job-id');
          
          $btn.prop('disabled', true);
          $btn.html('<span class="loading-spinner"></span>');
          
          getCsrfToken().then(token => {
            $.ajax({
              url: '/jobhunter/googlejobsintegration/generate',
              method: 'POST',
              contentType: 'application/json',
              headers: {
                'X-CSRF-Token': token
              },
            data: JSON.stringify({
              job_id: jobId
            }),
            success: function (response) {
              if (response.success) {
                showMessage('success', 'Structured data generated successfully');
                // Show structured data in modal or console
                console.log('Generated JSON-LD:', response.structured_data);
                // Optionally trigger validation automatically
                setTimeout(function() {
                  $('.btn-validate[data-job-id="' + jobId + '"]').trigger('click');
                }, 500);
              } else {
                showMessage('error', response.error || 'Failed to generate structured data');
              }
              $btn.prop('disabled', false);
              $btn.html('<i class="bi bi-code-square"></i>');
            },
            error: function (xhr) {
              const error = xhr.responseJSON ? xhr.responseJSON.error : 'Error generating structured data';
              showMessage('error', error);
              $btn.prop('disabled', false);
              $btn.html('<i class="bi bi-code-square"></i>');
            }
          });
        });
        });

        // Validate structured data
        $('.btn-validate').on('click', function () {
          const $btn = $(this);
          const jobId = $btn.data('job-id');
          
          $btn.prop('disabled', true);
          $btn.html('<span class="loading-spinner"></span>');
          
          getCsrfToken().then(token => {
            $.ajax({
              url: '/jobhunter/googlejobsintegration/validate',
              method: 'POST',
              contentType: 'application/json',
              headers: {
                'X-CSRF-Token': token
              },
            data: JSON.stringify({
              job_id: jobId
            }),
            success: function (response) {
              if (response.status === 'valid') {
                showMessage('success', 'Structured data is valid! ✓');
                // Update status badge
                const $row = $btn.closest('tr');
                const $statusCell = $row.find('td').eq(3);
                $statusCell.html('<span class="badge badge-sync-valid">Valid</span>');
              } else if (response.status === 'invalid') {
                const errorCount = response.errors ? response.errors.length : 0;
                showMessage('warning', 'Validation found ' + errorCount + ' error(s)');
                // Update status badge
                const $row = $btn.closest('tr');
                const $statusCell = $row.find('td').eq(3);
                $statusCell.html('<span class="badge badge-sync-invalid">Invalid</span>');
                // Log errors to console
                console.log('Validation errors:', response.errors);
                console.log('Validation warnings:', response.warnings);
              } else {
                showMessage('error', response.errors ? response.errors[0] : 'Validation error');
              }
              $btn.prop('disabled', false);
              $btn.html('<i class="bi bi-check2-square"></i>');
            },
            error: function (xhr) {
              const error = xhr.responseJSON ? xhr.responseJSON.error : 'Error validating structured data';
              showMessage('error', error);
              $btn.prop('disabled', false);
              $btn.html('<i class="bi bi-check2-square"></i>');
            }
          });
        });
        });

        // Validate all jobs
        $('#validate-all').on('click', function () {
          const $btn = $(this);
          $btn.prop('disabled', true);
          $btn.html('<span class="loading-spinner"></span> Validating...');
          
          // Get all job IDs
          const jobIds = [];
          $('.btn-validate').each(function() {
            jobIds.push($(this).data('job-id'));
          });
          
          if (jobIds.length === 0) {
            showMessage('info', 'No jobs to validate');
            $btn.prop('disabled', false);
            $btn.html('Validate All');
            return;
          }
          
          let completed = 0;
          let valid = 0;
          let invalid = 0;
          
          // Get CSRF token once for all requests
          getCsrfToken().then(token => {
            // Validate each job
            jobIds.forEach(function(jobId, index) {
              setTimeout(function() {
                $.ajax({
                  url: '/jobhunter/googlejobsintegration/validate',
                  method: 'POST',
                  contentType: 'application/json',
                  headers: {
                    'X-CSRF-Token': token
                  },
                  data: JSON.stringify({ job_id: jobId }),
                  success: function (response) {
                    completed++;
                    if (response.status === 'valid') {
                      valid++;
                    } else {
                      invalid++;
                    }

                    // Update progress
                    $btn.html('<span class="loading-spinner"></span> ' + completed + '/' + jobIds.length);

                    // When all complete
                    if (completed === jobIds.length) {
                      $btn.prop('disabled', false);
                      $btn.html('Validate All');
                      showMessage('success', 'Validation complete: ' + valid + ' valid, ' + invalid + ' invalid');
                      // Reload to show updated statuses
                      setTimeout(function() {
                        location.reload();
                      }, 2000);
                    }
                  },
                  error: function () {
                    completed++;
                    invalid++;
                    if (completed === jobIds.length) {
                      $btn.prop('disabled', false);
                      $btn.html('Validate All');
                      showMessage('warning', 'Validation complete with some errors');
                      setTimeout(function() {
                        location.reload();
                      }, 2000);
                    }
                  }
                });
              }, index * 300); // Stagger requests by 300ms
            });
          });
        });
      });

      // Initialize detail page once
      once('google-jobs-detail-init', '.google-jobs-job-detail', context).forEach(function (element) {
        const $element = $(element);

        // Validate job from detail page
        $element.find('#validate-job').on('click', function () {
          const $btn = $(this);
          const jobId = $btn.data('job-id');

          $btn.prop('disabled', true);
          $btn.html('<span class="loading-spinner"></span> Validating...');

          getCsrfToken().then(function (token) {
            $.ajax({
              url: '/jobhunter/googlejobsintegration/validate',
              method: 'POST',
              contentType: 'application/json',
              headers: { 'X-CSRF-Token': token },
              data: JSON.stringify({ job_id: jobId }),
              success: function (response) {
                if (response.status === 'valid') {
                  showMessage('success', 'Structured data is valid! ✓');
                } else {
                  var errorCount = response.errors ? response.errors.length : 0;
                  showMessage('warning', 'Validation found ' + errorCount + ' error(s)');
                }
                $btn.prop('disabled', false);
                $btn.html('<i class="bi bi-check2-square"></i> Validate Now');
              },
              error: function (xhr) {
                var error = xhr.responseJSON ? xhr.responseJSON.error : 'Error validating structured data';
                showMessage('error', error);
                $btn.prop('disabled', false);
                $btn.html('<i class="bi bi-check2-square"></i> Validate Now');
              }
            });
          });
        });

        // Generate / regenerate structured data from detail page
        $element.find('#generate-structured-data').on('click', function () {
          const $btn = $(this);
          const jobId = $btn.data('job-id');

          $btn.prop('disabled', true);
          $btn.html('<span class="loading-spinner"></span> Generating...');

          getCsrfToken().then(function (token) {
            $.ajax({
              url: '/jobhunter/googlejobsintegration/generate',
              method: 'POST',
              contentType: 'application/json',
              headers: { 'X-CSRF-Token': token },
              data: JSON.stringify({ job_id: jobId }),
              success: function (response) {
                if (response.success) {
                  showMessage('success', 'Structured data regenerated successfully');
                  if (response.structured_data) {
                    var $preview = $('#json-ld-preview code');
                    if ($preview.length) {
                      $preview.text(JSON.stringify(response.structured_data, null, 2));
                    }
                  }
                } else {
                  showMessage('error', response.error || 'Failed to generate structured data');
                }
                $btn.prop('disabled', false);
                $btn.html('<i class="bi bi-code-square"></i> Regenerate Structured Data');
              },
              error: function (xhr) {
                var error = xhr.responseJSON ? xhr.responseJSON.error : 'Error generating structured data';
                showMessage('error', error);
                $btn.prop('disabled', false);
                $btn.html('<i class="bi bi-code-square"></i> Regenerate Structured Data');
              }
            });
          });
        });

        // Enable integration
        $element.find('#enable-integration').on('click', function () {
          const $btn = $(this);
          const jobId = $btn.data('job-id');

          $btn.prop('disabled', true);

          getCsrfToken().then(function (token) {
            $.ajax({
              url: '/jobhunter/googlejobsintegration/toggle-sync',
              method: 'POST',
              contentType: 'application/json',
              headers: { 'X-CSRF-Token': token },
              data: JSON.stringify({ job_id: jobId, enabled: 1 }),
              success: function (response) {
                if (response.success) {
                  showMessage('success', response.message);
                  setTimeout(function () { location.reload(); }, 1000);
                } else {
                  showMessage('error', 'Failed to enable integration');
                  $btn.prop('disabled', false);
                }
              },
              error: function () {
                showMessage('error', 'Error communicating with server');
                $btn.prop('disabled', false);
              }
            });
          });
        });

        // Disable integration
        $element.find('#disable-integration').on('click', function () {
          const $btn = $(this);
          const jobId = $btn.data('job-id');

          $btn.prop('disabled', true);

          getCsrfToken().then(function (token) {
            $.ajax({
              url: '/jobhunter/googlejobsintegration/toggle-sync',
              method: 'POST',
              contentType: 'application/json',
              headers: { 'X-CSRF-Token': token },
              data: JSON.stringify({ job_id: jobId, enabled: 0 }),
              success: function (response) {
                if (response.success) {
                  showMessage('success', response.message);
                  setTimeout(function () { location.reload(); }, 1000);
                } else {
                  showMessage('error', 'Failed to disable integration');
                  $btn.prop('disabled', false);
                }
              },
              error: function () {
                showMessage('error', 'Error communicating with server');
                $btn.prop('disabled', false);
              }
            });
          });
        });

        // Copy JSON to clipboard
        $element.find('#copy-json').on('click', function () {
          const jsonText = $('#json-ld-preview code').text();
          if (navigator.clipboard) {
            navigator.clipboard.writeText(jsonText).then(function () {
              showMessage('success', 'JSON-LD copied to clipboard');
            });
          } else {
            // Fallback for older browsers.
            const $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(jsonText).select();
            document.execCommand('copy');
            $temp.remove();
            showMessage('success', 'JSON-LD copied to clipboard');
          }
        });
      });
    }
  };

  /**
   * Show a dismissable status message.
   *
   * @param {string} type
   *   Message type: success, error, warning, info.
   * @param {string} message
   *   The message text (plain text, no HTML).
   */
  function showMessage(type, message) {
    const alertClass = {
      'success': 'alert-success',
      'error': 'alert-danger',
      'warning': 'alert-warning',
      'info': 'alert-info'
    }[type] || 'alert-info';

    const icon = {
      'success': 'bi-check-circle-fill',
      'error': 'bi-exclamation-circle-fill',
      'warning': 'bi-exclamation-triangle-fill',
      'info': 'bi-info-circle-fill'
    }[type] || 'bi-info-circle-fill';

    const $icon = $('<i>').addClass('bi ' + icon);
    const $close = $('<button>')
      .attr('type', 'button')
      .addClass('btn-close')
      .attr('data-bs-dismiss', 'alert');

    const $alert = $('<div>')
      .addClass('alert ' + alertClass + ' alert-dismissible fade show')
      .attr('role', 'alert')
      .append($icon)
      .append(' ')
      .append(document.createTextNode(message))
      .append($close);

    $('#status-messages').append($alert);

    // Auto-dismiss after 5 seconds.
    setTimeout(function () {
      $alert.alert('close');
    }, 5000);
  }

})(jQuery, Drupal, once);
