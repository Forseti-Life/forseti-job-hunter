/**
 * @file
 * Handles loading state and polling for evaluations in progress.
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.entityEvaluationLoader = {
    attach: function (context, settings) {
      console.log('🔍 Evaluation Loader - Checking for loading state');
      console.log('Settings:', settings.evaluatedEntity);
      
      once('evaluation-loader', '.evaluated-entity-node', context).forEach(function (element) {
        console.log('✅ Found evaluated-entity-node element');
        const nodeId = settings.evaluatedEntity?.nodeId;
        const isUnpublished = settings.evaluatedEntity?.unpublished;
        const totalPower = settings.evaluatedEntity?.totalPower;
        const conversationNid = settings.evaluatedEntity?.conversationNid;
        
        console.log('Node ID:', nodeId, 'Unpublished:', isUnpublished, 'Total Power:', totalPower, 'Conversation:', conversationNid);

        // Only show loading state if node is unpublished (status = 0) and has no evaluation yet
        if (isUnpublished && totalPower === 0) {
          console.log('🎬 Showing loading overlay and starting polling');
          showLoadingOverlay(conversationNid);
          startPolling(nodeId, conversationNid);
        } else {
          console.log('ℹ️ Not showing loading overlay - conditions not met');
        }
      });

      function showLoadingOverlay(conversationNid) {
        const overlay = $('<div class="evaluation-loading-overlay"></div>');
        const content = $(`
          <div class="evaluation-loading-content">
            <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
              <span class="visually-hidden">Loading...</span>
            </div>
            <h3 class="text-primary">Evaluation in Progress...</h3>
            <p class="text-muted">
              The AI is currently evaluating this entity across all 30 sub-dimensions 
              of the Agent Power Framework. This typically takes 30-60 seconds.
            </p>
            <p class="text-muted">
              <small>This page will automatically refresh when the evaluation is complete.</small>
            </p>
          </div>
        `);
        
        overlay.append(content);
        $('.evaluated-entity-node').prepend(overlay);

        // Add styles dynamically
        if (!$('#evaluation-loader-styles').length) {
          $('head').append(`
            <style id="evaluation-loader-styles">
              .evaluation-loading-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(255, 255, 255, 0.95);
                z-index: 9999;
                display: flex;
                align-items: center;
                justify-content: center;
              }
              .evaluation-loading-content {
                text-align: center;
                max-width: 500px;
                padding: 2rem;
              }
              .evaluation-loading-content h3 {
                margin-bottom: 1rem;
              }
              .evaluation-loading-content p {
                margin-bottom: 0.5rem;
                line-height: 1.6;
              }
            </style>
          `);
        }
      }

      function startPolling(nodeId, conversationNid) {
        let pollCount = 0;
        const maxPolls = 22; // Poll for 45 seconds (22 polls * ~2 seconds)
        
        const pollInterval = setInterval(function() {
          pollCount++;
          
          // After 45 seconds, redirect to chat for help
          if (pollCount > maxPolls) {
            clearInterval(pollInterval);
            redirectToChat(conversationNid);
            return;
          }

          // Check node status
          $.ajax({
            url: '/node/' + nodeId + '?_format=json',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
              // Check if node is now published or has a non-zero total power
              if (data.status && data.status[0].value === true || 
                  (data.field_total_power && data.field_total_power[0].value > 0)) {
                clearInterval(pollInterval);
                // Reload the page to show the completed evaluation
                location.reload();
              }
            },
            error: function(xhr, status, error) {
              console.log('Polling error:', error);
              // Continue polling despite errors
            }
          });
        }, 2000); // Poll every 2 seconds
      }

      function redirectToChat(conversationNid) {
        if (conversationNid) {
          $('.evaluation-loading-content').html(`
            <div class="alert alert-info">
              <h4 class="alert-heading">Evaluation Needs Your Input</h4>
              <p>The AI may need more information to complete the evaluation.</p>
              <p class="mb-0">Redirecting you to the conversation...</p>
            </div>
          `);
          
          // Redirect after a brief delay
          setTimeout(function() {
            window.location.href = '/node/' + conversationNid + '/chat';
          }, 2000);
        } else {
          showTimeoutMessage();
        }
      }

      function showTimeoutMessage() {
        $('.evaluation-loading-content').html(`
          <div class="alert alert-warning">
            <h4 class="alert-heading">Evaluation Taking Longer Than Expected</h4>
            <p>The evaluation is still in progress but taking longer than usual.</p>
            <hr>
            <p class="mb-0">
              <a href="#" onclick="location.reload(); return false;" class="btn btn-primary">
                Refresh Page
              </a>
            </p>
          </div>
        `);
      }
    }
  };

})(jQuery, Drupal, once);
