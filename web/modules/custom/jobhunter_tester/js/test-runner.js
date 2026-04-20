/**
 * @file
 * Test runner functionality for Job Hunter testing dashboard.
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.jobhunterTestRunner = {
    attach: function (context, settings) {
      once('test-runner', '.test-run-btn', context).forEach(function(element) {
        $(element).on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var testFile = $button.data('test-file');
        var $resultsDiv = $('#test-results');
        var $output = $('#test-output');
        
        // Disable button and show loading
        $button.prop('disabled', true).text('Running...');
        $resultsDiv.show();
        $output.text('Running tests for ' + testFile + '...\n\n');
        
        // Make AJAX request
        $.ajax({
          url: '/jobhunter_testing/run-tests',
          method: 'POST',
          data: {
            test_file: testFile
          },
          success: function(response) {
            var $status;
            if (response.success) {
              $status = $('<span>').css({color: 'green', fontWeight: 'bold'}).text('✓ ALL TESTS PASSED');
            } else {
              $status = $('<span>').css({color: 'red', fontWeight: 'bold'}).text('✗ TESTS FAILED (Code: ' + response.return_code + ')');
            }
            $output.empty().append($status).append('\n\n').append(document.createTextNode(response.output));
            $button.prop('disabled', false).text('Run Tests');
          },
          error: function(xhr) {
            var errorMsg = 'Error running tests';
            if (xhr.responseJSON && xhr.responseJSON.error) {
              errorMsg = xhr.responseJSON.error;
            }
            var $status = $('<span>').css({color: 'red', fontWeight: 'bold'}).text('✗ ERROR');
            $output.empty().append($status).append('\n\n').append(document.createTextNode(errorMsg));
            $button.prop('disabled', false).text('Run Tests');
          }
        });
      });
      });
      
      // Add "Run All Tests" button
      once('test-run-all', '.test-run-btn', context).forEach(function() {
        // Only add once — check if button already exists
        if ($('.test-run-all-btn').length > 0) {
          return;
        }
        var $runAllBtn = $('<button class="button button--primary test-run-all-btn" style="margin: 20px 0;">Run All Tests</button>');
        $runAllBtn.insertBefore($('#test-results'));
        
        $runAllBtn.on('click', function(e) {
          e.preventDefault();
          
          var $testButtons = $('.test-run-btn[data-test-file]');
          var currentIndex = 0;
          
          function runNextTest() {
            if (currentIndex >= $testButtons.length) {
              var $done = $('<span>').css({color: 'blue', fontWeight: 'bold'}).text('✓ ALL TEST SUITES COMPLETED');
              $('#test-output').append('\n\n').append($done);
              return;
            }
            
            var $btn = $testButtons.eq(currentIndex);
            var testFile = $btn.data('test-file');
            
            $('#test-output').append('\n\n=== Running ' + testFile + ' ===\n');
            
            $.ajax({
              url: '/jobhunter_testing/run-tests',
              method: 'POST',
              data: { test_file: testFile },
              success: function(response) {
                $('#test-output').append(document.createTextNode(response.output + '\n'));
                currentIndex++;
                runNextTest();
              },
              error: function(xhr) {
                $('#test-output').append(document.createTextNode('ERROR: ' + (xhr.responseJSON ? xhr.responseJSON.error : 'Unknown error') + '\n'));
                currentIndex++;
                runNextTest();
              }
            });
          }
          
          $('#test-results').show();
          $('#test-output').text('Running all test suites...\n\n');
          runNextTest();
        });
      });
    }
  };

})(jQuery, Drupal, once);
