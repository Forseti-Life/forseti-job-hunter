/**
 * Job Hunter Validation Dashboard JavaScript
 *
 * Copied from NFR nfr-validation.js — route testing functions only.
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.jhValidation = {
    attach: function (context, settings) {
      // Test individual route
      once('jh-validation-test', '.jh-test-btn', context).forEach(function (element) {
        $(element).on('click', function (e) {
          e.preventDefault();

          const $btn = $(this);
          const route = $btn.data('route');
          const path = $btn.data('path');
          const uid = $btn.data('uid');
          const user = $btn.data('user');
          const cellId = $btn.closest('td').find('.jh-test-result').attr('id');

          testRoute(route, path, uid, user, cellId, $btn);
        });
      });

      // Test all routes
      once('jh-validation-all', '#test-all-routes', context).forEach(function (element) {
        $(element).on('click', function (e) {
          e.preventDefault();
          testAllRoutes();
        });
      });

      // Clear results
      once('jh-validation-clear', '#clear-results', context).forEach(function (element) {
        $(element).on('click', function (e) {
          e.preventDefault();
          clearResults();
        });
      });

      // Check database tables
      once('jh-validation-tables', '#check-tables', context).forEach(function (element) {
        $(element).on('click', function (e) {
          e.preventDefault();
          checkTables();
        });
      });
    }
  };

  /**
   * Test a single route.
   */
  function testRoute(route, path, uid, user, cellId, $btn) {
    const $resultDiv = $('#' + cellId);
    const expected = $btn.data('expected');

    // Show loading
    $btn.prop('disabled', true).text('Testing...');
    $resultDiv.html('<div class="test-loading">⏳</div>');

    // Make AJAX request
    $.ajax({
      url: '/jobhunter_testing/validation/test-route',
      method: 'GET',
      data: {
        route: route,
        path: path,
        uid: uid,
        expected: expected
      },
      success: function (response) {
        displayResult(response, $resultDiv, $btn);
      },
      error: function (xhr, status, error) {
        $resultDiv.html('<div class="test-result-error">❌ Error: ' + error + '</div>');
        $btn.prop('disabled', false).text('Test');
      }
    });
  }

  /**
   * Display test result.
   */
  function displayResult(response, $resultDiv, $btn) {
    let html = '';
    let icon = '';
    let className = '';
    const expected = $btn.data('expected');
    let matchesExpected = false;

    if (response.status_code === 200) {
      icon = '✅';
      className = 'test-result-success';
      matchesExpected = (expected === 'allow');
    } else if (response.status_code === 403) {
      icon = '🚫';
      className = 'test-result-forbidden';
      matchesExpected = (expected === 'deny');
    } else {
      icon = '❌';
      className = 'test-result-error';
      matchesExpected = false;
    }

    if (!matchesExpected && (response.status_code === 200 || response.status_code === 403)) {
      className += ' unexpected-result';
      icon += ' ⚠️';
    }

    html += '<div class="test-result-display ' + className + '">';
    html += '<div class="result-icon">' + icon + '</div>';
    html += '<div class="result-code">' + response.status_code + '</div>';
    html += '<div class="result-text">' + response.status_text + '</div>';
    if (!matchesExpected && (response.status_code === 200 || response.status_code === 403)) {
      html += '<div class="result-unexpected">⚠️ Unexpected result!</div>';
    }
    if (response.error) {
      html += '<div class="result-error">' + response.error + '</div>';
    }
    html += '</div>';

    $resultDiv.html(html);
    $btn.prop('disabled', false).text('Retest');

    updateSummary();
  }

  /**
   * Test all routes.
   */
  function testAllRoutes() {
    const $btn = $('#test-all-routes');
    const $allTestBtns = $('.jh-test-btn');

    $btn.prop('disabled', true).text('Running All Tests...');

    let testCount = 0;
    const totalTests = $allTestBtns.length;

    $allTestBtns.each(function (index) {
      const $testBtn = $(this);

      setTimeout(function () {
        $testBtn.trigger('click');
        testCount++;

        $btn.text('Testing... (' + testCount + '/' + totalTests + ')');

        if (testCount === totalTests) {
          setTimeout(function () {
            $btn.prop('disabled', false).text('Run All Tests');
            showSummary();
          }, 500);
        }
      }, index * 100);
    });
  }

  /**
   * Clear all results.
   */
  function clearResults() {
    $('.jh-test-result').html('');
    $('.jh-test-btn').prop('disabled', false).text('Test');
    $('#test-summary').hide();
  }

  /**
   * Update summary statistics.
   */
  function updateSummary() {
    const total = $('.test-result-display').length;
    const success = $('.test-result-success').length;
    const forbidden = $('.test-result-forbidden').length;
    const errors = $('.test-result-error').length;

    if (total > 0) {
      let html = '<div style="display:flex; gap:15px;">';
      html += '<div style="padding:10px 15px; background:#f5f5f5; border-radius:5px; text-align:center; flex:1;">';
      html += '<div style="font-size:20px; font-weight:bold;">' + total + '</div>';
      html += '<div style="font-size:11px;">Tests Run</div></div>';
      html += '<div style="padding:10px 15px; background:#d4edda; border-radius:5px; text-align:center; flex:1;">';
      html += '<div style="font-size:20px; font-weight:bold;">' + success + '</div>';
      html += '<div style="font-size:11px;">✅ Success</div></div>';
      html += '<div style="padding:10px 15px; background:#fff3cd; border-radius:5px; text-align:center; flex:1;">';
      html += '<div style="font-size:20px; font-weight:bold;">' + forbidden + '</div>';
      html += '<div style="font-size:11px;">🚫 Forbidden</div></div>';
      html += '<div style="padding:10px 15px; background:#f8d7da; border-radius:5px; text-align:center; flex:1;">';
      html += '<div style="font-size:20px; font-weight:bold;">' + errors + '</div>';
      html += '<div style="font-size:11px;">❌ Errors</div></div>';
      html += '</div>';

      $('#test-summary').html(html);
    }
  }

  /**
   * Show summary panel.
   */
  function showSummary() {
    updateSummary();
    $('#test-summary').slideDown();
  }

  /**
   * Check database tables.
   */
  function checkTables() {
    var $btn = $('#check-tables');
    $btn.prop('disabled', true).text('Checking...');

    $.ajax({
      url: '/jobhunter_testing/validation/check-tables',
      method: 'GET',
      success: function (data) {
        var $section = $('#db-health-section');
        var $body = $('#db-health-body');
        var $summary = $('#db-health-summary');
        var $oldTables = $('#db-old-tables');

        $body.empty();

        // Summary bar
        var allOk = data.all_exist;
        var summaryColor = allOk ? '#d4edda' : '#f8d7da';
        var summaryIcon = allOk ? '✅' : '❌';
        var summaryText = summaryIcon + ' ' + data.existing + '/' + data.total + ' tables exist';
        if (data.old_tables && data.old_tables.length > 0) {
          summaryText += ' &nbsp;|&nbsp; ⚠️ ' + data.old_tables.length + ' old-convention tables found';
          summaryColor = '#fff3cd';
        }
        $summary.html('<div style="padding:10px 15px; background:' + summaryColor + '; border-radius:5px; font-weight:bold;">' + summaryText + '</div>');

        // Table rows
        $.each(data.tables, function (i, t) {
          var icon = t.exists ? '✅' : '❌';
          var rowBg = t.exists ? '' : 'background:#f8d7da;';
          var countText = t.exists ? t.row_count.toLocaleString() : 'MISSING';
          var countStyle = t.exists ? '' : 'color:#dc3545; font-weight:bold;';

          $body.append(
            '<tr style="' + rowBg + '">' +
            '<td style="padding:6px 8px; border-bottom:1px solid #eee;">' + icon + '</td>' +
            '<td style="padding:6px 8px; border-bottom:1px solid #eee;"><code>' + t.table + '</code></td>' +
            '<td style="padding:6px 8px; border-bottom:1px solid #eee;">' + t.description + '</td>' +
            '<td style="padding:6px 8px; border-bottom:1px solid #eee; text-align:right; ' + countStyle + '">' + countText + '</td>' +
            '</tr>'
          );
        });

        // Old tables warning
        if (data.old_tables && data.old_tables.length > 0) {
          var oldHtml = '<div style="padding:10px 15px; background:#fff3cd; border-radius:5px; border:1px solid #ffc107;">';
          oldHtml += '<strong>⚠️ Old-convention tables detected (should be renamed):</strong><br>';
          oldHtml += '<code>' + data.old_tables.join('</code>, <code>') + '</code>';
          oldHtml += '</div>';
          $oldTables.html(oldHtml).show();
        } else {
          $oldTables.hide();
        }

        $section.slideDown();
        $btn.prop('disabled', false).text('Check Database Tables');
      },
      error: function (xhr, status, error) {
        $('#db-health-summary').html('<div style="padding:10px 15px; background:#f8d7da; border-radius:5px;">❌ Error checking tables: ' + error + '</div>');
        $('#db-health-section').slideDown();
        $btn.prop('disabled', false).text('Check Database Tables');
      }
    });
  }

})(jQuery, Drupal, once);
