/**
 * @file
 * Population Benchmarks page behaviors.
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.populationBenchmarks = {
    attach: function (context, settings) {
      var funnelChart; // Store funnel chart instance
      
      // Initialize pyramid chart for dimension scores (proper funnel chart)
      once('funnel-chart', '#dimension-funnel-chart', context).forEach(function(canvas) {
        var dimensionOrder = ['WHOLE', 'USEFUL', 'CAPABLE', 'FREE', 'CONNECTED', 'ENERGIZED', 'SAFE'];
        var dimensionLabels = [];
        var dimensionScores = [];
        var dimensionColors = [
          'rgba(255, 193, 7, 0.8)',   // WHOLE - Yellow/Gold #ffc107
          'rgba(232, 62, 140, 0.8)',  // USEFUL - Pink/Magenta #e83e8c
          'rgba(111, 66, 193, 0.8)',  // CAPABLE - Purple #6f42c1
          'rgba(23, 162, 184, 0.8)',  // FREE - Cyan/Teal #17a2b8
          'rgba(40, 167, 69, 0.8)',   // CONNECTED - Green #28a745
          'rgba(255, 165, 0, 0.8)',   // ENERGIZED - Orange #ffa500
          'rgba(63, 229, 225, 0.8)'   // SAFE - Cyan/Turquoise #3fe5e1
        ];
        
        // Extract dimension scores from the page
        var rawScores = [];
        dimensionOrder.forEach(function(dimension) {
          var $scoreElement = $('[data-dimension-score="' + dimension + '"]');
          if ($scoreElement.length) {
            var score = parseFloat($scoreElement.text()) || 50;
            dimensionLabels.push(dimension);
            rawScores.push(score);
          }
        });
        
        // Scale scores for pyramid effect: bottom=100%, each layer up=85% of previous
        // SAFE (bottom) = 100%, ENERGIZED = 85%, CONNECTED = 72.25%, etc.
        // Convert to centered floating bars [start, end] around 50
        dimensionScores = rawScores.map(function(score, index) {
          // Index 0 = WHOLE (top), Index 6 = SAFE (bottom)
          // Bottom layer (SAFE) gets 100%, each layer up gets 85% more reduction
          var pyramidFactor = Math.pow(0.85, dimensionLabels.length - 1 - index);
          var scaledValue = score * pyramidFactor;
          // Center around 50: [50 - half, 50 + half]
          return [50 - (scaledValue / 2), 50 + (scaledValue / 2)];
        });
        
        // Calculate pyramid bar thicknesses (85% reduction per level from bottom to top)
        var baseThickness = 60;
        var barThicknesses = [];
        for (var i = dimensionLabels.length - 1; i >= 0; i--) {
          barThicknesses.push(baseThickness * Math.pow(0.85, dimensionLabels.length - 1 - i));
        }
        
        var ctx = canvas.getContext('2d');
        
        // Custom plugin to display value labels on bars
        var barLabelPlugin = {
          id: 'barLabels',
          afterDatasetsDraw: function(chart) {
            var ctx = chart.ctx;
            var xScale = chart.scales.x;
            
            // Get raw scores from canvas data (updated on recalculation)
            var currentRawScores = $(chart.canvas).data('rawScores') || rawScores;
            
            chart.data.datasets.forEach(function(dataset, datasetIndex) {
              var meta = chart.getDatasetMeta(datasetIndex);
              meta.data.forEach(function(bar, index) {
                var rawScore = currentRawScores[index];
                var displayValue = rawScore.toFixed(1);
                
                ctx.fillStyle = '#fff';
                ctx.font = 'bold 14px Arial';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                
                // Center of floating bars is always at data value 50
                var x = xScale.getPixelForValue(50);
                var y = bar.y;
                
                ctx.fillText(displayValue, x, y);
              });
            });
          }
        };
        
        funnelChart = new Chart(ctx, {
          type: 'bar',
          data: {
            labels: dimensionLabels,
            datasets: [{
              data: dimensionScores,
              backgroundColor: dimensionColors,
              borderColor: dimensionColors.map(c => c.replace('0.8', '1')),
              borderWidth: 2,
              barThickness: barThicknesses,
              borderRadius: 20,
              borderSkipped: false
            }]
          },
          options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 1.5,
            scales: {
              x: {
                display: false,
                min: 0,
                max: 100
              },
              y: {
                ticks: {
                  font: {
                    size: 14,
                    weight: 'bold'
                  }
                }
              }
            },
            plugins: {
              legend: {
                display: false
              },
              tooltip: {
                callbacks: {
                  label: function(context) {
                    var rawScore = rawScores[context.dataIndex];
                    return context.label + ': ' + rawScore.toFixed(2) + ' / 100';
                  }
                }
              }
            }
          },
          plugins: [barLabelPlugin]
        });
        
        // Store chart instance and metadata for updates
        $(canvas).data('funnelChart', funnelChart);
        $(canvas).data('dimensionOrder', dimensionLabels);
        $(canvas).data('rawScores', rawScores);
      });
      
      // Smooth scroll to expanded accordion
      once('accordion-scroll', '.accordion-button', context).forEach(function(element) {
        $(element).on('click', function() {
          var $button = $(this);
          setTimeout(function() {
            if (!$button.hasClass('collapsed')) {
              $('html, body').animate({
                scrollTop: $button.offset().top - 100
              }, 500);
            }
          }, 350);
        });
      });

      // Highlight dimension on hover
      once('dimension-highlight', '.dimension-slider', context).forEach(function(element) {
        $(element).hover(
          function() {
            $(this).closest('.card').addClass('border-primary shadow');
          },
          function() {
            $(this).closest('.card').removeClass('border-primary shadow');
          }
        );
      });

      // Print dimension scores summary
      once('print-summary', '.dimension-scores-section', context).forEach(function(element) {
        $(element).append(
          '<div class="text-center mt-4">' +
          '  <button class="btn btn-outline-primary btn-sm" id="print-summary">' +
          '    <i class="fas fa-print me-2"></i>Print Summary' +
          '  </button>' +
          '</div>'
        );
      });

      once('print-handler', '#print-summary', context).forEach(function(element) {
        $(element).on('click', function() {
          window.print();
        });
      });

      // Initialize Chart.js charts for distribution data
      once('chart-init', 'canvas[data-distribution]', context).forEach(function(canvas) {
        var $canvas = $(canvas);
        var distributionData = JSON.parse($canvas.attr('data-distribution'));
        
        if (!distributionData || typeof distributionData !== 'object') {
          return;
        }

        var labels = Object.keys(distributionData);
        var values = Object.values(distributionData);
        
        // Check if this is a bell curve (numeric keys) or categorical data (text keys)
        var isNumericDistribution = !isNaN(parseFloat(labels[0]));
        
        var ctx = canvas.getContext('2d');

        if (isNumericDistribution) {
          // Extract metadata if present
          var metadata = distributionData._metadata || {};
          var meanValue = metadata.mean;
          var minValue = metadata.min_value;
          var maxValue = metadata.max_value;
          
          // Remove metadata from display data
          var cleanLabels = labels.filter(function(l) { return l !== '_metadata'; });
          var cleanValues = values.slice(0, cleanLabels.length);
          
          // Bell curve visualization for normalized scores
          new Chart(ctx, {
            type: 'line',
            data: {
              labels: cleanLabels,
              datasets: [{
                label: 'Distribution',
                data: cleanValues,
                borderColor: 'rgba(13, 110, 253, 1)',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.4, // Smooth curve
                borderWidth: 2,
                pointRadius: 0, // Hide individual points for smooth line
                pointHoverRadius: 4
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: {
                  display: false
                },
                tooltip: {
                  callbacks: {
                    title: function(context) {
                      return 'Score: ' + context[0].label;
                    },
                    label: function(context) {
                      return 'Density: ' + context.parsed.y.toFixed(2);
                    }
                  }
                },
                annotation: {
                  annotations: {
                    label0: {
                      type: 'label',
                      xValue: 0,
                      xAdjust: 20,
                      yValue: 0,
                      yAdjust: -20,
                      backgroundColor: 'rgba(0,0,0,0.8)',
                      content: minValue !== undefined ? [minValue.toString()] : ['Min'],
                      font: { size: 10 },
                      color: 'white',
                      padding: 4
                    },
                    label50: {
                      type: 'label',
                      xValue: '50',
                      yValue: Math.max.apply(null, cleanValues),
                      yAdjust: 15,
                      backgroundColor: 'rgba(13, 110, 253, 0.9)',
                      content: meanValue !== undefined ? ['Mean: ' + meanValue] : ['Mean'],
                      font: { size: 11, weight: 'bold' },
                      color: 'white',
                      padding: 6
                    },
                    label100: {
                      type: 'label',
                      xValue: '100',
                      xAdjust: -20,
                      yValue: 0,
                      yAdjust: -20,
                      backgroundColor: 'rgba(0,0,0,0.8)',
                      content: maxValue !== undefined ? [maxValue.toString()] : ['Max'],
                      font: { size: 10 },
                      color: 'white',
                      padding: 4
                    }
                  }
                }
              },
              scales: {
                x: {
                  title: {
                    display: true,
                    text: 'Normalized Score (0-100)'
                  },
                  ticks: {
                    callback: function(value, index) {
                      // Show every other label to avoid crowding
                      return index % 2 === 0 ? this.getLabelForValue(value) : '';
                    }
                  }
                },
                y: {
                  beginAtZero: true,
                  title: {
                    display: true,
                    text: 'Probability Density'
                  },
                  ticks: {
                    callback: function(value) {
                      return value.toFixed(0);
                    }
                  }
                }
              }
            }
          });
        } else {
          // Bar chart for categorical data
          var percentages = values.map(function(v) {
            return typeof v === 'number' && v <= 1 ? v * 100 : v;
          });
          
          new Chart(ctx, {
            type: 'bar',
            data: {
              labels: labels,
              datasets: [{
                label: 'Distribution (%)',
                data: percentages,
                backgroundColor: [
                  'rgba(13, 110, 253, 0.7)',
                  'rgba(25, 135, 84, 0.7)',
                  'rgba(255, 193, 7, 0.7)',
                  'rgba(220, 53, 69, 0.7)',
                  'rgba(108, 117, 125, 0.7)',
                  'rgba(102, 16, 242, 0.7)',
                  'rgba(13, 202, 240, 0.7)',
                  'rgba(214, 51, 132, 0.7)'
                ],
                borderColor: [
                  'rgba(13, 110, 253, 1)',
                  'rgba(25, 135, 84, 1)',
                  'rgba(255, 193, 7, 1)',
                  'rgba(220, 53, 69, 1)',
                  'rgba(108, 117, 125, 1)',
                  'rgba(102, 16, 242, 1)',
                  'rgba(13, 202, 240, 1)',
                  'rgba(214, 51, 132, 1)'
                ],
                borderWidth: 1
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: {
                  display: false
                },
                tooltip: {
                  callbacks: {
                    label: function(context) {
                      return context.parsed.y.toFixed(1) + '%';
                    }
                  }
                }
              },
              scales: {
                y: {
                  beginAtZero: true,
                  max: 100,
                  ticks: {
                    callback: function(value) {
                      return value + '%';
                    }
                  }
                }
              }
            }
          });
        }
      });

      // Universal metric slider handler
      var sliderValues = {}; // Store adjusted values by metric name
      var dimensionTimers = {}; // Store recalc timers by dimension
      
      once('metric-sliders', '.metric-value-slider', context).forEach(function(slider) {
        var $slider = $(slider);
        var metricName = $slider.data('metric-name');
        var dimension = $slider.data('dimension');
        var mean = parseFloat($slider.data('mean'));
        var stddev = parseFloat($slider.data('stddev'));
        var $container = $slider.closest('.metric-slider-container');
        var $valueDisplay = $container.find('.metric-raw-value');
        var $normalizedDisplay = $container.find('.metric-normalized-score');
        
        $slider.on('input', function() {
          var rawValue = parseFloat(this.value);
          
          // Update raw value display
          $valueDisplay.text(rawValue.toFixed(2));
          
          // Calculate z-score: z = (value - mean) / stddev
          var zScore = (rawValue - mean) / stddev;
          
          // Convert to normalized 0-100 scale: normalized = 50 + (z * 16.67)
          var normalized = 50 + (zScore * 16.67);
          
          // Clamp to 0-100 range
          normalized = Math.max(0, Math.min(100, normalized));
          
          // Update normalized display
          $normalizedDisplay.text(normalized.toFixed(2));
          
          // Update badge color based on value
          $normalizedDisplay.removeClass('text-danger text-warning text-success text-primary');
          if (normalized < 25) {
            $normalizedDisplay.addClass('text-danger');
          } else if (normalized < 50) {
            $normalizedDisplay.addClass('text-warning');
          } else if (normalized < 75) {
            $normalizedDisplay.addClass('text-primary');
          } else {
            $normalizedDisplay.addClass('text-success');
          }
          
          // Store the normalized value for this metric
          sliderValues[metricName] = normalized;
          
          // Clear existing timer for this dimension
          if (dimensionTimers[dimension]) {
            clearTimeout(dimensionTimers[dimension]);
          }
          
          // Set 3-second delay before recalculating dimension score
          dimensionTimers[dimension] = setTimeout(function() {
            recalculateDimensionScore(dimension);
          }, 3000);
        });
      });
      
      // Function to recalculate any dimension score
      function recalculateDimensionScore(dimensionKey) {
        console.log('Recalculating', dimensionKey, 'dimension score');
        
        if (!drupalSettings || !drupalSettings.populationBenchmarks || !drupalSettings.populationBenchmarks.metrics) {
          console.error('drupalSettings.populationBenchmarks.metrics not available');
          return;
        }
        
        var metrics = drupalSettings.populationBenchmarks.metrics;
        
        // Filter metrics for this dimension (numeric and scale only)
        var dimensionMetrics = metrics.filter(function(m) {
          return m.dimension === dimensionKey;
        });
        
        console.log(dimensionKey, 'metrics found:', dimensionMetrics.length);
        
        if (dimensionMetrics.length === 0) {
          console.error('No metrics found for dimension', dimensionKey);
          return;
        }
        
        // Calculate new average
        var total = 0;
        var count = 0;
        
        dimensionMetrics.forEach(function(metric) {
          // Check if there's a slider-adjusted value
          if (sliderValues[metric.metric_name] !== undefined) {
            total += sliderValues[metric.metric_name];
            count++;
          } else if (metric.normalized_mean != null && !isNaN(metric.normalized_mean)) {
            // Use existing normalized mean
            total += parseFloat(metric.normalized_mean);
            count++;
          }
        });
        
        console.log('Total sum:', total, 'Count:', count);
        var newScore = count > 0 ? (total / count) : 50;
        
        console.log('New', dimensionKey, 'score:', newScore.toFixed(2));
        
        // Update dimension card display
        var $scoreDisplay = $('[data-dimension-score="' + dimensionKey + '"]');
        console.log('Score display element found:', $scoreDisplay.length);
        $scoreDisplay.text(newScore.toFixed(2));
        
        // Update accordion badge
        var $scoreBadge = $('[data-dimension-badge="' + dimensionKey + '"]');
        console.log('Badge element found:', $scoreBadge.length);
        $scoreBadge.text('Score: ' + newScore.toFixed(2));
        
        // Update slider position
        var $scoreSlider = $('.dimension-slider[data-dimension="' + dimensionKey + '"]');
        $scoreSlider.val(newScore);
        
        // Update funnel chart
        updateFunnelChart(dimensionKey, newScore);
        
        // Add visual feedback
        var $card = $scoreDisplay.closest('.card');
        $card.addClass('border-success shadow-lg');
        setTimeout(function() {
          $card.removeClass('border-success shadow-lg');
        }, 2000);
      }
      
      // Function to update funnel chart
      function updateFunnelChart(dimensionKey, newScore) {
        var $canvas = $('#dimension-funnel-chart');
        if ($canvas.length === 0) return;
        
        var chart = $canvas.data('funnelChart');
        var dimensionOrder = $canvas.data('dimensionOrder');
        var rawScores = $canvas.data('rawScores');
        if (!chart || !dimensionOrder || !rawScores) return;
        
        // Find the dimension index in the chart
        var dimensionIndex = dimensionOrder.indexOf(dimensionKey);
        if (dimensionIndex === -1) return;
        
        // Update raw scores array
        rawScores[dimensionIndex] = newScore;
        $canvas.data('rawScores', rawScores);
        
        // Calculate pyramid factor for this dimension (same as initialization)
        var pyramidFactor = Math.pow(0.85, dimensionOrder.length - 1 - dimensionIndex);
        var scaledValue = newScore * pyramidFactor;
        
        // Update the data with centered floating bar format
        chart.data.datasets[0].data[dimensionIndex] = [50 - (scaledValue / 2), 50 + (scaledValue / 2)];
        chart.update('none'); // Update without animation for smooth feel
      }
    }
  };

})(jQuery, Drupal, once);
