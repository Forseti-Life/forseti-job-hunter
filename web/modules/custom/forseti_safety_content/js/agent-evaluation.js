/**
 * @file
 * Agent evaluation form handling and accordion controls for framework pages.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.agentEvaluation = {
    attach: function (context, settings) {
      once('agent-evaluation', '#startEvaluationBtn', context).forEach(function (button) {
        button.addEventListener('click', function(e) {
          e.preventDefault();
          submitEvaluation();
        });
      });
      
      // Allow Enter key to submit
      once('agent-evaluation-input', '#agentNameInput', context).forEach(function (input) {
        input.addEventListener('keypress', function(e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            submitEvaluation();
          }
        });
      });

      function submitEvaluation() {
        const agentName = document.getElementById('agentNameInput').value.trim();
        const button = document.getElementById('startEvaluationBtn');
        
        if (!agentName) {
          alert('Please enter an agent name.');
          return;
        }

        // Disable button and show loading state
        button.disabled = true;
        button.textContent = 'Creating Evaluation...';

        // Call the backend API to create the evaluation
        fetch('/api/agent-evaluation/create', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            entity_name: agentName
          })
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // Redirect to the entity node page
            window.location.href = data.entity_url;
          } else {
            alert('Error: ' + (data.error || 'Failed to create evaluation.'));
            button.disabled = false;
            button.textContent = 'Start Evaluation';
          }
        })
        .catch(error => {
          console.error('Error creating evaluation:', error);
          alert('An error occurred. Please try again.');
          button.disabled = false;
          button.textContent = 'Start Evaluation';
        });
      }
    }
  };

  /**
   * Accordion umbrella controls for expand/collapse all functionality.
   */
  Drupal.behaviors.accordionUmbrellaControls = {
    attach: function (context, settings) {
      // Wait for Bootstrap to be available
      function initAccordionControls() {
        if (typeof bootstrap === 'undefined') {
          console.warn('Bootstrap is not loaded yet, will retry...');
          setTimeout(initAccordionControls, 100);
          return;
        }

        // Don't manually handle accordion toggles - let Bootstrap do it
        // Bootstrap automatically handles [data-bs-toggle="collapse"] elements

        // Expand All Cards
        once('expand-all-cards', '#expandAllCards', context).forEach(function (button) {
          button.addEventListener('click', function(e) {
            e.preventDefault();
            const collapses = context.querySelectorAll('.rank-collapse');
            collapses.forEach(function(collapse) {
              const instance = bootstrap.Collapse.getInstance(collapse) || new bootstrap.Collapse(collapse, {toggle: false});
              instance.show();
            });
          });
        });

        // Collapse All Cards
        once('collapse-all-cards', '#collapseAllCards', context).forEach(function (button) {
          button.addEventListener('click', function(e) {
            e.preventDefault();
            const collapses = context.querySelectorAll('.rank-collapse');
            collapses.forEach(function(collapse) {
              const instance = bootstrap.Collapse.getInstance(collapse) || new bootstrap.Collapse(collapse, {toggle: false});
              instance.hide();
            });
          });
        });

        // Expand All Sub-Dimensions
        once('expand-all-subdims', '#expandAllSubDims', context).forEach(function (button) {
          button.addEventListener('click', function(e) {
            e.preventDefault();
            const collapses = context.querySelectorAll('[id^="collapse-subdim-"]');
            collapses.forEach(function(collapse) {
              const instance = bootstrap.Collapse.getInstance(collapse) || new bootstrap.Collapse(collapse, {toggle: false});
              instance.show();
            });
          });
        });

        // Collapse All Sub-Dimensions
        once('collapse-all-subdims', '#collapseAllSubDims', context).forEach(function (button) {
          button.addEventListener('click', function(e) {
            e.preventDefault();
            const collapses = context.querySelectorAll('[id^="collapse-subdim-"]');
            collapses.forEach(function(collapse) {
              const instance = bootstrap.Collapse.getInstance(collapse) || new bootstrap.Collapse(collapse, {toggle: false});
              instance.hide();
            });
          });
        });
      }

      // Start initialization
      initAccordionControls();
    }
  };

})(Drupal, once);
