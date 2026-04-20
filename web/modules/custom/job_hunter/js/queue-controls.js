/**
 * @file
 * Enhanced queue controls with auto-refresh and real-time monitoring.
 */

(function ($, Drupal, once) {
  'use strict';

  // Track processing state
  let processingQueues = {};
  let lastActivityTimes = {}; // Store last activity timestamps by queue ID
  let autoRefreshEnabled = true;
  let autoRefreshInterval = null;
  let countdownInterval = null;
  let countdownValue = 5;
  const REFRESH_SECONDS = 5;
  const MAX_LOG_ENTRIES = 20;

  function getEndpoint(settingKey, fallbackPath) {
    return (drupalSettings.jobHunterQueueControls && drupalSettings.jobHunterQueueControls[settingKey])
      ? drupalSettings.jobHunterQueueControls[settingKey]
      : fallbackPath;
  }

  function csrfHeaders() {
    return {
      'X-CSRF-Token': drupalSettings.csrf_token || ''
    };
  }

  /**
   * Helper to get button text (works for both button and input elements).
   */
  function getButtonText(btn) {
    return btn.is('input') ? btn.val() : btn.text();
  }

  /**
   * Helper to set button text (works for both button and input elements).
   */
  function setButtonText(btn, text) {
    if (btn.is('input')) {
      btn.val(text);
    } else {
      btn.text(text);
    }
  }

  /**
   * Format timestamp for display.
   */
  function formatTime(date) {
    return date.toLocaleTimeString('en-US', {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit'
    });
  }

  /**
   * Add entry to activity log.
   */
  function addLogEntry(message, type) {
    const logContainer = $('#log-entries');
    if (!logContainer.length) return;

    const timestamp = formatTime(new Date());
    const typeClass = type || 'info';
    const icons = {
      'success': '✅',
      'error': '❌',
      'info': 'ℹ️',
      'processing': '⏳',
      'warning': '⚠️'
    };
    
    const entry = $('<div class="log-entry log-' + typeClass + '">' +
      '<span class="log-time">' + timestamp + '</span> ' +
      '<span class="log-icon">' + (icons[typeClass] || 'ℹ️') + '</span> ' +
      '<span class="log-message">' + Drupal.jobHunter.escapeHtml(message) + '</span>' +
      '</div>');
    
    // Remove "waiting" message if present
    logContainer.find('.log-entry:contains("Waiting for activity")').remove();
    
    // Add new entry at top
    logContainer.prepend(entry);
    
    // Limit entries
    const entries = logContainer.find('.log-entry');
    if (entries.length > MAX_LOG_ENTRIES) {
      entries.slice(MAX_LOG_ENTRIES).remove();
    }
  }

  /**
   * Load recent logs from Drupal watchdog.
   */
  function loadRecentLogs() {
    $.ajax({
      url: '/jobhunter/queue/logs',
      type: 'GET',
      dataType: 'json',
      success: function(response) {
        if (response.success && response.logs) {
          const logContainer = $('#log-entries');
          logContainer.empty();
          
          const icons = {
            'success': '✅',
            'error': '❌',
            'info': 'ℹ️',
            'processing': '⏳',
            'warning': '⚠️'
          };
          
          response.logs.forEach(function(log) {
            const date = new Date(log.timestamp * 1000);
            const timestamp = formatTime(date);
            const entry = $('<div class="log-entry log-' + log.type + '">' +
              '<span class="log-time">' + timestamp + '</span> ' +
              '<span class="log-icon">' + (icons[log.type] || 'ℹ️') + '</span> ' +
              '<span class="log-message">' + Drupal.jobHunter.escapeHtml(log.message) + '</span>' +
              '</div>');
            logContainer.append(entry);
          });
          
          if (response.logs.length === 0) {
            logContainer.html('<div class="log-entry log-info">No recent queue activity</div>');
          }
        }
      },
      error: function() {
        $('#log-entries').html('<div class="log-entry log-error">Failed to load logs</div>');
      }
    });
  }

  /**
   * Update queue status indicator.
   */
  function updateQueueStatus(queueId, status, text) {
    const row = $('.queue-row[data-queue-id="' + queueId + '"]');
    const indicator = row.find('[data-status]');
    
    indicator.removeClass('status-idle status-processing status-success status-error')
             .addClass('status-' + status);
    indicator.find('.status-text').text(text);
    
    if (status === 'processing') {
      processingQueues[queueId] = true;
    } else {
      delete processingQueues[queueId];
    }
    
    updateGlobalStatus();
  }

  /**
   * Update last activity timestamp.
   */
  function updateLastActivity(queueId) {
    const timestamp = formatTime(new Date());
    const row = $('.queue-row[data-queue-id="' + queueId + '"]');
    row.find('[data-last-activity]').text(timestamp);
    
    // Store in memory so it persists through refreshes
    lastActivityTimes[queueId] = timestamp;
  }

  /**
   * Update global processing status.
   */
  function updateGlobalStatus() {
    const isProcessing = Object.keys(processingQueues).length > 0;
    const panel = $('#queue-controls-panel');
    
    if (isProcessing) {
      panel.addClass('is-processing');
    } else {
      panel.removeClass('is-processing');
    }
  }

  /**
   * Start auto-refresh countdown.
   */
  function startAutoRefresh() {
    if (!autoRefreshEnabled) return;
    
    countdownValue = REFRESH_SECONDS;
    $('#auto-refresh-countdown').text(countdownValue);
    
    // Clear existing intervals
    if (countdownInterval) clearInterval(countdownInterval);
    if (autoRefreshInterval) clearTimeout(autoRefreshInterval);
    
    countdownInterval = setInterval(function() {
      countdownValue--;
      $('#auto-refresh-countdown').text(countdownValue);
      
      if (countdownValue <= 0) {
        clearInterval(countdownInterval);
      }
    }, 1000);
    
    autoRefreshInterval = setTimeout(function() {
      silentRefreshQueueStatus();
      loadRecentLogs();
      startAutoRefresh();
    }, REFRESH_SECONDS * 1000);
  }

  /**
   * Stop auto-refresh.
   */
  function stopAutoRefresh() {
    if (countdownInterval) clearInterval(countdownInterval);
    if (autoRefreshInterval) clearTimeout(autoRefreshInterval);
    $('#auto-refresh-countdown').text('-');
  }

  /**
   * Queue Controls behavior.
   */
  Drupal.behaviors.queueControls = {
    attach: function (context, settings) {
      
      // Initialize total count on page load
      once('queue-init-total', '#queue-controls-panel', context).forEach(function () {
        updateTotalCount();
        loadRecentLogs();
        startAutoRefresh();
        addLogEntry('Queue dashboard initialized', 'info');
      });
      
      // Auto-refresh toggle
      once('auto-refresh-toggle-init', '#auto-refresh-toggle', context).forEach(function (element) {
        $(element).on('change', function() {
          autoRefreshEnabled = $(this).is(':checked');
          if (autoRefreshEnabled) {
            startAutoRefresh();
            addLogEntry('Auto-refresh enabled', 'info');
          } else {
            stopAutoRefresh();
            addLogEntry('Auto-refresh disabled', 'info');
          }
        });
      });
      
      // Run individual queue button
      once('queue-run-init', '.btn-run-queue', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          
          const btn = $(this);
          const queueId = btn.data('queue');
          const row = btn.closest('.queue-row');
          
          runQueue(queueId, btn, row);
        });
      });
      
      // Run all queues button
      once('queue-run-all-init', '#run-all-queues', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          runAllQueues($(this));
        });
      });
      
      // Refresh status button
      once('queue-refresh-init', '#refresh-queue-status', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          refreshQueueStatus($(this));
        });
      });
      
      // Pause/Resume queue processing button
      once('queue-pause-resume-init', '#pause-resume-queue', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          toggleQueueProcessing($(this));
        });
      });
      
      // Load suspended items on page load
      once('suspended-items-init', '#suspended-items-panel', context).forEach(function () {
        loadSuspendedItems();
      });
      
      // Retry suspended item handler (delegated event)
      once('retry-suspended-init', '#suspended-items-container', context).forEach(function (element) {
        $(element).on('click', '.btn-retry-suspended', function(e) {
          e.preventDefault();
          retrySuspendedItem($(this));
        });
      });
    }
  };
  
  /**
   * Run a single queue.
   */
  function runQueue(queueId, btn, row) {
    const originalText = getButtonText(btn);
    const queueName = row.find('.queue-name strong').text();
    
    btn.addClass('running').prop('disabled', true);
    setButtonText(btn, '⏳ Running...');
    updateQueueStatus(queueId, 'processing', 'Processing...');
    addLogEntry('Started processing: ' + queueName, 'processing');
    
    $.ajax({
      url: getEndpoint('runUrl', '/jobhunter/queue/run'),
      type: 'POST',
      headers: csrfHeaders(),
      dataType: 'json',
      data: { queue_id: queueId },
      success: function(response) {
        btn.removeClass('running');
        updateLastActivity(queueId);
        
        if (response.success) {
          const processed = response.processed || 0;
          addLogEntry(queueName + ': Processed ' + processed + ' items, ' + response.remaining + ' remaining', 'success');
          
          // Update the count badge
          const badge = row.find('[data-count]');
          badge.text(response.remaining);
          
          if (response.remaining === 0) {
            badge.removeClass('queue-badge-pending').addClass('queue-badge-empty');
            btn.prop('disabled', true);
            updateQueueStatus(queueId, 'success', 'Complete');
          } else {
            badge.removeClass('queue-badge-empty').addClass('queue-badge-pending');
            btn.prop('disabled', false);
            updateQueueStatus(queueId, 'idle', 'Idle');
          }
          
          updateTotalCount();
          showMessage('success', response.message);
        } else {
          addLogEntry(queueName + ': ' + (response.message || 'Failed'), 'error');
          updateQueueStatus(queueId, 'error', 'Error');
          showMessage('error', response.message || 'Failed to run queue');
          btn.prop('disabled', false);
        }
        
        setButtonText(btn, originalText);
      },
      error: function(xhr) {
        btn.removeClass('running').prop('disabled', false);
        setButtonText(btn, originalText);
        updateQueueStatus(queueId, 'error', 'Error');
        updateLastActivity(queueId);
        
        let errorMsg = 'Failed to run queue';
        if (xhr.responseJSON && xhr.responseJSON.message) {
          errorMsg = xhr.responseJSON.message;
        }
        addLogEntry(queueName + ': ' + errorMsg, 'error');
        showMessage('error', errorMsg);
      }
    });
  }
  
  /**
   * Run all queues.
   */
  function runAllQueues(btn) {
    const originalText = getButtonText(btn);
    btn.prop('disabled', true).addClass('running');
    setButtonText(btn, '⏳ Running all queues...');
    addLogEntry('Started processing all queues', 'processing');
    
    // Mark all queues as processing
    $('.queue-row').each(function() {
      const queueId = $(this).data('queue-id');
      const badge = $(this).find('[data-count]');
      if (parseInt(badge.text()) > 0) {
        updateQueueStatus(queueId, 'processing', 'Processing...');
      }
    });
    
    $.ajax({
      url: getEndpoint('runAllUrl', '/jobhunter/queue/run-all'),
      type: 'POST',
      headers: csrfHeaders(),
      dataType: 'json',
      success: function(response) {
        if (response.success) {
          addLogEntry('Completed all queues: ' + response.total_processed + ' total items processed', 'success');
          showMessage('success', response.message);
          
          // Update all queue counts
          if (response.results) {
            Object.keys(response.results).forEach(function(queueId) {
              const result = response.results[queueId];
              const row = $('.queue-row[data-queue-id="' + queueId + '"]');
              const badge = row.find('[data-count]');
              const runBtn = row.find('.btn-run-queue');
              const queueName = row.find('.queue-name strong').text();
              
              updateLastActivity(queueId);
              
              if (result.error) {
                addLogEntry(queueName + ': Error - ' + result.error, 'error');
                updateQueueStatus(queueId, 'error', 'Error');
              } else if (result.remaining !== undefined) {
                badge.text(result.remaining);
                
                if (result.remaining === 0) {
                  badge.removeClass('queue-badge-pending').addClass('queue-badge-empty');
                  runBtn.prop('disabled', true);
                  updateQueueStatus(queueId, 'success', 'Complete');
                } else {
                  badge.removeClass('queue-badge-empty').addClass('queue-badge-pending');
                  runBtn.prop('disabled', false);
                  updateQueueStatus(queueId, 'idle', 'Idle');
                }
                
                if (result.processed > 0) {
                  addLogEntry(queueName + ': ' + result.processed + ' processed, ' + result.remaining + ' remaining', 'success');
                }
              }
            });
          }
          
          updateTotalCount();
        } else {
          addLogEntry('Failed to process queues: ' + (response.message || 'Unknown error'), 'error');
          showMessage('error', response.message || 'Failed to run queues');
          
          // Reset queue statuses
          $('.queue-row').each(function() {
            updateQueueStatus($(this).data('queue-id'), 'error', 'Error');
          });
        }
        
        btn.prop('disabled', false).removeClass('running');
        setButtonText(btn, originalText);
        updateTotalCount();
      },
      error: function(xhr) {
        btn.prop('disabled', false).removeClass('running');
        setButtonText(btn, originalText);
        
        // Reset queue statuses
        $('.queue-row').each(function() {
          updateQueueStatus($(this).data('queue-id'), 'idle', 'Idle');
        });
        
        let errorMsg = 'Failed to run queues';
        if (xhr.responseJSON && xhr.responseJSON.message) {
          errorMsg = xhr.responseJSON.message;
        }
        addLogEntry('Failed: ' + errorMsg, 'error');
        showMessage('error', errorMsg);
      }
    });
  }
  
  /**
   * Refresh queue status (with UI feedback).
   */
  function refreshQueueStatus(btn) {
    const originalText = getButtonText(btn);
    btn.prop('disabled', true);
    setButtonText(btn, '🔄 Refreshing...');
    
    doRefreshQueueStatus(function() {
      btn.prop('disabled', false);
      setButtonText(btn, originalText);
      showMessage('info', 'Queue status refreshed');
      addLogEntry('Status manually refreshed', 'info');
      
      // Restart auto-refresh countdown
      if (autoRefreshEnabled) {
        startAutoRefresh();
      }
    });
  }
  
  /**
   * Silent refresh (for auto-refresh).
   */
  function silentRefreshQueueStatus() {
    doRefreshQueueStatus(null);
  }
  
  /**
   * Actual refresh implementation.
   */
  function doRefreshQueueStatus(callback) {
    $.ajax({
      url: '/jobhunter/queue/status',
      type: 'GET',
      dataType: 'json',
      success: function(response) {
        if (response.success && response.queues) {
          let hasChanges = false;
          
          Object.keys(response.queues).forEach(function(queueId) {
            const queue = response.queues[queueId];
            const row = $('.queue-row[data-queue-id="' + queueId + '"]');
            const badge = row.find('[data-count]');
            const runBtn = row.find('.btn-run-queue');
            const lastActivityEl = row.find('[data-last-activity]');
            const currentCount = parseInt(badge.text()) || 0;
            
            if (currentCount !== queue.items) {
              hasChanges = true;
              badge.text(queue.items);
              
              // Add subtle flash animation for changes
              badge.addClass('queue-badge-changed');
              setTimeout(function() {
                badge.removeClass('queue-badge-changed');
              }, 1000);
            }
            
            if (queue.items === 0) {
              badge.removeClass('queue-badge-pending').addClass('queue-badge-empty');
              runBtn.prop('disabled', true);
            } else {
              badge.removeClass('queue-badge-empty').addClass('queue-badge-pending');
              // Only re-enable if not currently processing
              if (!processingQueues[queueId]) {
                runBtn.prop('disabled', false);
              }
            }
            
            // Restore last activity timestamp from memory
            if (lastActivityTimes[queueId]) {
              lastActivityEl.text(lastActivityTimes[queueId]);
            }
          });
          
          updateTotalCount();
        }
        
        if (callback) callback();
      },
      error: function(xhr) {
        if (callback) callback();
      }
    });
  }
  
  /**
   * Update the total count display and "Run All Queues" button.
   */
  function updateTotalCount() {
    let totalItems = 0;
    
    $('.queue-row [data-count]').each(function() {
      totalItems += parseInt($(this).text()) || 0;
    });
    
    $('#total-queue-items').text(totalItems);
    
    const btn = $('#run-all-queues');
    if (totalItems === 0) {
      btn.prop('disabled', true);
    } else if (!btn.hasClass('running')) {
      btn.prop('disabled', false);
    }
  }
  
  /**
   * Show a status message.
   */
  function showMessage(type, message) {
    const msgDiv = $('#queue-status-message');
    msgDiv.removeClass('success error info')
          .addClass(type)
          .text(message)
          .show();
    
    // Auto-hide after 5 seconds
    setTimeout(function() {
      msgDiv.fadeOut();
    }, 5000);
  }
  
  /**
   * Toggle queue processing pause/resume.
   */
  function toggleQueueProcessing(btn) {
    const isPaused = btn.data('paused') === 'true' || btn.data('paused') === true;
    const endpoint = isPaused
      ? getEndpoint('resumeUrl', '/jobhunter/queue/resume')
      : getEndpoint('pauseUrl', '/jobhunter/queue/pause');
    const action = isPaused ? 'Resuming' : 'Pausing';
    
    btn.prop('disabled', true);
    setButtonText(btn, '⏳ ' + action + '...');
    
    $.ajax({
      url: endpoint,
      type: 'POST',
      headers: csrfHeaders(),
      dataType: 'json',
      success: function(response) {
        if (response.success) {
          const newPaused = response.paused;
          btn.data('paused', newPaused);
          
          if (newPaused) {
            setButtonText(btn, '▶️ Resume Processing');
            btn.removeClass('btn-pause').addClass('btn-resume');
            addLogEntry('Queue processing has been paused', 'warning');
            showMessage('info', 'Queue processing is now PAUSED');
          } else {
            setButtonText(btn, '⏸️ Pause Processing');
            btn.removeClass('btn-resume').addClass('btn-pause');
            addLogEntry('Queue processing has been resumed', 'success');
            showMessage('success', 'Queue processing is now ACTIVE');
          }
          
          btn.prop('disabled', false);
        } else {
          addLogEntry('Failed to toggle queue processing: ' + (response.message || 'Unknown error'), 'error');
          showMessage('error', response.message || 'Failed to toggle queue processing');
          btn.prop('disabled', false);
        }
      },
      error: function(xhr) {
        addLogEntry('Error toggling queue processing', 'error');
        showMessage('error', 'Failed to communicate with server');
        btn.prop('disabled', false);
      }
    });
  }
  
  /**
   * Load and display suspended queue items.
   */
  function loadSuspendedItems() {
    const container = $('#suspended-items-container');
    container.html('<div class="loading-state">⏳ Loading suspended items...</div>');
    
    $.ajax({
      url: '/jobhunter/queue/suspended',
      type: 'GET',
      dataType: 'json',
      success: function(response) {
        if (response.success) {
          if (response.count === 0) {
            container.html('<div class="empty-state" style="padding: 2rem; text-align: center; color: #666;">' +
              '<div style="font-size: 3rem; margin-bottom: 0.5rem;">✓</div>' +
              '<p>No suspended items</p>' +
              '<p style="font-size: 0.9rem;">All queue items are processing normally</p>' +
              '</div>');
          } else {
            let html = '<div class="suspended-items-list">' +
              '<div class="list-header" style="margin-bottom: 1rem; padding: 0.5rem; background: #fffbea; border-left: 4px solid #f59e0b;">' +
              '<strong>' + response.count + '</strong> suspended item(s) require attention' +
              '</div>';
            
            response.items.forEach(function(item) {
              html += '<div class="suspended-item" style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; background: #fafafa;">' +
                '<div style="display: flex; justify-content: space-between; align-items: start;">' +
                  '<div style="flex: 1;">' +
                    '<div style="margin-bottom: 0.5rem;">' +
                      '<span class="queue-badge" style="background: #dc2626; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.875rem; font-weight: 500;">' +
                        item.queue_display_name +
                      '</span>' +
                      '<span style="margin-left: 0.5rem; color: #666; font-size: 0.875rem;">#' + item.id + '</span>' +
                    '</div>' +
                    '<div style="font-size: 0.875rem; color: #666; margin-bottom: 0.5rem;">' +
                      '<strong>Suspended:</strong> ' + item.suspended_at + ' | ' +
                      '<strong>Attempts:</strong> ' + item.retry_count +
                    '</div>' +
                    '<div style="font-size: 0.875rem; color: #dc2626; background: #fef2f2; padding: 0.5rem; border-radius: 4px;">' +
                      '<strong>Error:</strong> ' + item.last_error +
                    '</div>' +
                  '</div>' +
                  '<div style="margin-left: 1rem;">' +
                    '<button class="btn-retry-suspended button button-primary" data-id="' + item.id + '" style="white-space: nowrap;">' +
                      '🔄 Retry' +
                    '</button>' +
                  '</div>' +
                '</div>' +
              '</div>';
            });
            
            html += '</div>';
            container.html(html);
          }
        } else {
          container.html('<div class="error-state" style="padding: 1rem; color: #dc2626; background: #fef2f2; border-radius: 4px;">' +
            '❌ Failed to load suspended items: ' + (response.message || 'Unknown error') +
            '</div>');
        }
      },
      error: function(xhr) {
        container.html('<div class="error-state" style="padding: 1rem; color: #dc2626; background: #fef2f2; border-radius: 4px;">' +
          '❌ Error communicating with server' +
          '</div>');
      }
    });
  }
  
  /**
   * Retry a suspended queue item.
   */
  function retrySuspendedItem(btn) {
    const suspendedId = btn.data('id');
    const originalText = getButtonText(btn);
    
    btn.prop('disabled', true);
    setButtonText(btn, '⏳ Retrying...');
    
    $.ajax({
      url: getEndpoint('retrySuspendedUrl', '/jobhunter/queue/retry-suspended'),
      type: 'POST',
      headers: csrfHeaders(),
      dataType: 'json',
      data: JSON.stringify({ id: suspendedId }),
      contentType: 'application/json',
      success: function(response) {
        if (response.success) {
          addLogEntry('Successfully re-queued suspended item #' + suspendedId, 'success');
          showMessage('success', response.message || 'Item has been re-queued');
          
          // Remove the item from the UI
          btn.closest('.suspended-item').fadeOut(300, function() {
            $(this).remove();
            
            // Check if there are any items left
            const remaining = $('.suspended-item').length;
            if (remaining === 0) {
              loadSuspendedItems(); // Reload to show "no suspended items" message
            } else {
              // Update the count
              $('.suspended-items-list .list-header').html(
                '<strong>' + remaining + '</strong> suspended item(s) require attention'
              );
            }
          });
          
          // Refresh queue status to show the new item count
          refreshQueueStatus();
        } else {
          addLogEntry('Failed to retry suspended item #' + suspendedId + ': ' + (response.message || 'Unknown error'), 'error');
          showMessage('error', response.message || 'Failed to retry item');
          btn.prop('disabled', false);
          setButtonText(btn, originalText);
        }
      },
      error: function(xhr) {
        addLogEntry('Error retrying suspended item #' + suspendedId, 'error');
        showMessage('error', 'Failed to communicate with server');
        btn.prop('disabled', false);
        setButtonText(btn, originalText);
      }
    });
  }

})(jQuery, Drupal, once);
