/**
 * NFR Public Data Dashboard - US Heat Map Visualization
 */

(function ($, Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.nfrPublicDataMap = {
    attach: function (context, settings) {
      const mapContainers = once('nfr-map', '#us-map-container', context);
      if (!mapContainers.length) {
        return;
      }

      const stateData = drupalSettings.nfr.stateData || {};
      
      // State abbreviations to full names mapping
      const stateNames = {
        'AL': 'Alabama', 'AK': 'Alaska', 'AZ': 'Arizona', 'AR': 'Arkansas',
        'CA': 'California', 'CO': 'Colorado', 'CT': 'Connecticut', 'DE': 'Delaware',
        'FL': 'Florida', 'GA': 'Georgia', 'HI': 'Hawaii', 'ID': 'Idaho',
        'IL': 'Illinois', 'IN': 'Indiana', 'IA': 'Iowa', 'KS': 'Kansas',
        'KY': 'Kentucky', 'LA': 'Louisiana', 'ME': 'Maine', 'MD': 'Maryland',
        'MA': 'Massachusetts', 'MI': 'Michigan', 'MN': 'Minnesota', 'MS': 'Mississippi',
        'MO': 'Missouri', 'MT': 'Montana', 'NE': 'Nebraska', 'NV': 'Nevada',
        'NH': 'New Hampshire', 'NJ': 'New Jersey', 'NM': 'New Mexico', 'NY': 'New York',
        'NC': 'North Carolina', 'ND': 'North Dakota', 'OH': 'Ohio', 'OK': 'Oklahoma',
        'OR': 'Oregon', 'PA': 'Pennsylvania', 'RI': 'Rhode Island', 'SC': 'South Carolina',
        'SD': 'South Dakota', 'TN': 'Tennessee', 'TX': 'Texas', 'UT': 'Utah',
        'VT': 'Vermont', 'VA': 'Virginia', 'WA': 'Washington', 'WV': 'West Virginia',
        'WI': 'Wisconsin', 'WY': 'Wyoming', 'DC': 'District of Columbia',
        'PR': 'Puerto Rico', 'GU': 'Guam', 'VI': 'Virgin Islands'
      };

      loadSVGMap();

      function loadSVGMap() {
        const container = document.getElementById('us-map-container');
        
        // Load the SVG map
        $.get('/modules/custom/nfr/images/us-map.svg', function(svgDoc) {
          const $svg = $(svgDoc).find('svg');
          
          // Set responsive attributes
          $svg.attr('width', '100%');
          $svg.attr('height', 'auto');
          $svg.attr('id', 'heat-map-svg');
          
          // Style all state paths
          $svg.find('path[data-id]').each(function() {
            const $path = $(this);
            const stateCode = $path.attr('data-id');
            const count = stateData[stateCode] || 0;
            const fillColor = getHeatColor(count);
            
            $path.css({
              'fill': fillColor,
              'stroke': 'rgba(255, 255, 255, 0.4)',
              'stroke-width': '1',
              'cursor': 'pointer',
              'transition': 'all 0.3s ease'
            });
            
            $path.attr('data-count', count);
            $path.attr('data-state-name', stateNames[stateCode] || stateCode);
          });
          
          // Clear container and append SVG
          container.innerHTML = '';
          container.appendChild($svg[0]);
          
          // Add tooltip
          const tooltip = $('<div class="map-tooltip"></div>').appendTo('body');
          
          // Add hover interactions
          $svg.find('path[data-id]').hover(
            function() {
              const $this = $(this);
              const stateName = $this.attr('data-state-name');
              const count = $this.attr('data-count');
              
              $this.css({
                'stroke': '#00d4ff',
                'stroke-width': '2',
                'filter': 'brightness(1.2)'
              });
              
              tooltip.html(`
                <strong>${stateName}</strong><br>
                ${count} ${count == 1 ? 'firefighter' : 'firefighters'}
              `).show();
            },
            function() {
              $(this).css({
                'stroke': 'rgba(255, 255, 255, 0.4)',
                'stroke-width': '1',
                'filter': 'none'
              });
              tooltip.hide();
            }
          );
          
          // Tooltip follows mouse
          $svg.on('mousemove', function(e) {
            tooltip.css({
              left: e.pageX + 15 + 'px',
              top: e.pageY + 15 + 'px'
            });
          });
          
        }).fail(function() {
          // Fallback: show error message
          container.innerHTML = '<div class="alert alert-warning">Unable to load heat map. Please refresh the page.</div>';
        });
      }

      function getHeatColor(count) {
        // Return color based on count (heat map gradient from light to dark)
        if (count === 0) return 'rgba(100, 100, 100, 0.3)'; // Gray for no data
        if (count <= 10) return 'rgba(153, 213, 255, 0.5)'; // Light blue
        if (count <= 50) return 'rgba(82, 183, 255, 0.7)'; // Medium blue
        if (count <= 100) return 'rgba(0, 153, 255, 0.8)'; // Bright blue
        if (count <= 250) return 'rgba(0, 102, 204, 0.9)'; // Dark blue
        return 'rgba(0, 51, 153, 1)'; // Deepest blue for 250+
      }
    }
  };

  /**
   * Initialize demographic and cancer charts.
   */
  Drupal.behaviors.nfrPublicDataCharts = {
    attach: function (context, settings) {
      if (typeof Chart === 'undefined') {
        console.error('Chart.js not loaded');
        return;
      }

      // Check if drupalSettings.nfr exists
      if (!settings.nfr) {
        console.error('drupalSettings.nfr not found');
        return;
      }

      const demographicData = settings.nfr.demographicData || {};
      const cancerData = settings.nfr.cancerData || {};
      
      console.log('Demographic data:', demographicData);
      console.log('Cancer data:', cancerData);
      console.log('Race data check:', demographicData.race);
      console.log('Race chart element:', document.getElementById('race-chart'));
      console.log('Education chart element:', document.getElementById('education-chart'));
      
      // Common chart options
      const chartDefaults = {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: {
            labels: {
              color: '#ffffff',
              font: {
                size: 12
              }
            }
          }
        }
      };
      
      const colorPalette = [
        'rgba(0, 212, 255, 0.8)',  // Cyan
        'rgba(124, 77, 255, 0.8)',  // Purple
        'rgba(255, 107, 107, 0.8)', // Red
        'rgba(78, 205, 196, 0.8)',  // Teal
        'rgba(255, 195, 0, 0.8)',   // Gold
        'rgba(199, 0, 57, 0.8)',    // Maroon
        'rgba(0, 184, 148, 0.8)',   // Green
        'rgba(253, 121, 168, 0.8)', // Pink
        'rgba(162, 155, 254, 0.8)', // Lavender
        'rgba(255, 159, 64, 0.8)',  // Orange
      ];
      
      // Race/Ethnicity Pie Chart
      const raceCharts = once('race-chart', '#race-chart', context);
      if (raceCharts.length && demographicData.race && demographicData.race.labels && demographicData.race.labels.length) {
        console.log('Creating race chart with data:', demographicData.race);
        const ctx = raceCharts[0].getContext('2d');
        new Chart(ctx, {
          type: 'pie',
          data: {
            labels: demographicData.race.labels,
            datasets: [{
              data: demographicData.race.values,
              backgroundColor: colorPalette,
              borderColor: '#1a1a2e',
              borderWidth: 2
            }]
          },
          options: {
            ...chartDefaults,
            plugins: {
              ...chartDefaults.plugins,
              tooltip: {
                callbacks: {
                  label: function(context) {
                    const label = context.label || '';
                    const value = context.parsed || 0;
                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                    const percentage = ((value / total) * 100).toFixed(1);
                    return `${label}: ${value} (${percentage}%)`;
                  }
                }
              }
            }
          }
        });
      } else {
        console.log('Race chart not created - missing element or data');
      }
      
      // Education Level Pie Chart
      const educationCharts = once('education-chart', '#education-chart', context);
      if (educationCharts.length && demographicData.education && demographicData.education.labels && demographicData.education.labels.length) {
        console.log('Creating education chart with data:', demographicData.education);
        const ctx = educationCharts[0].getContext('2d');
        new Chart(ctx, {
          type: 'pie',
          data: {
            labels: demographicData.education.labels,
            datasets: [{
              data: demographicData.education.values,
              backgroundColor: colorPalette,
              borderColor: '#1a1a2e',
              borderWidth: 2
            }]
          },
          options: {
            ...chartDefaults,
            plugins: {
              ...chartDefaults.plugins,
              tooltip: {
                callbacks: {
                  label: function(context) {
                    const label = context.label || '';
                    const value = context.parsed || 0;
                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                    const percentage = ((value / total) * 100).toFixed(1);
                    return `${label}: ${value} (${percentage}%)`;
                  }
                }
              }
            }
          }
        });
      } else {
        console.log('Education chart not created - missing element or data');
      }
      
      // Marital Status Pie Chart
      const maritalCharts = once('marital-chart', '#marital-chart', context);
      if (maritalCharts.length && demographicData.marital && demographicData.marital.labels && demographicData.marital.labels.length) {
        console.log('Creating marital chart');
        const ctx = maritalCharts[0].getContext('2d');
        new Chart(ctx, {
          type: 'pie',
          data: {
            labels: demographicData.marital.labels,
            datasets: [{
              data: demographicData.marital.values,
              backgroundColor: colorPalette,
              borderColor: '#1a1a2e',
              borderWidth: 2
            }]
          },
          options: {
            ...chartDefaults,
            plugins: {
              ...chartDefaults.plugins,
              tooltip: {
                callbacks: {
                  label: function(context) {
                    const label = context.label || '';
                    const value = context.parsed || 0;
                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                    const percentage = ((value / total) * 100).toFixed(1);
                    return `${label}: ${value} (${percentage}%)`;
                  }
                }
              }
            }
          }
        });
      }
      
      // BMI Distribution Bar Chart
      const bmiCharts = once('bmi-chart', '#bmi-chart', context);
      if (bmiCharts.length && demographicData.bmi && demographicData.bmi.labels && demographicData.bmi.labels.length) {
        console.log('Creating BMI chart');
        const ctx = bmiCharts[0].getContext('2d');
        new Chart(ctx, {
          type: 'bar',
          data: {
            labels: demographicData.bmi.labels,
            datasets: [{
              label: 'Number of Participants',
              data: demographicData.bmi.values,
              backgroundColor: 'rgba(0, 212, 255, 0.7)',
              borderColor: 'rgba(0, 212, 255, 1)',
              borderWidth: 1
            }]
          },
          options: {
            ...chartDefaults,
            scales: {
              y: {
                beginAtZero: true,
                ticks: {
                  color: '#ffffff',
                  stepSize: 1
                },
                grid: {
                  color: 'rgba(255, 255, 255, 0.1)'
                }
              },
              x: {
                ticks: {
                  color: '#ffffff'
                },
                grid: {
                  color: 'rgba(255, 255, 255, 0.1)'
                }
              }
            }
          }
        });
      }
      
      // Cancer Types Bar Chart
      const cancerTypesCharts = once('cancer-types-chart', '#cancer-types-chart', context);
      if (cancerTypesCharts.length && cancerData.types && cancerData.types.labels && cancerData.types.labels.length) {
        console.log('Creating cancer types chart');
        const ctx = cancerTypesCharts[0].getContext('2d');
        new Chart(ctx, {
          type: 'bar',
          data: {
            labels: cancerData.types.labels,
            datasets: [{
              label: 'Number of Diagnoses',
              data: cancerData.types.values,
              backgroundColor: 'rgba(255, 107, 107, 0.7)',
              borderColor: 'rgba(255, 107, 107, 1)',
              borderWidth: 1
            }]
          },
          options: {
            ...chartDefaults,
            indexAxis: 'y', // Horizontal bar chart
            scales: {
              x: {
                beginAtZero: true,
                ticks: {
                  color: '#ffffff',
                  stepSize: 1
                },
                grid: {
                  color: 'rgba(255, 255, 255, 0.1)'
                }
              },
              y: {
                ticks: {
                  color: '#ffffff'
                },
                grid: {
                  color: 'rgba(255, 255, 255, 0.1)'
                }
              }
            }
          }
        });
      }
      
      // Family History Pie Chart
      const familyHistoryCharts = once('family-history-chart', '#family-history-chart', context);
      if (familyHistoryCharts.length && cancerData.family_history && cancerData.family_history.labels && cancerData.family_history.labels.length) {
        console.log('Creating family history chart');
        const ctx = familyHistoryCharts[0].getContext('2d');
        new Chart(ctx, {
          type: 'doughnut',
          data: {
            labels: cancerData.family_history.labels,
            datasets: [{
              data: cancerData.family_history.values,
              backgroundColor: [
                'rgba(255, 107, 107, 0.8)',
                'rgba(78, 205, 196, 0.8)'
              ],
              borderColor: '#1a1a2e',
              borderWidth: 2
            }]
          },
          options: {
            ...chartDefaults,
            plugins: {
              ...chartDefaults.plugins,
              tooltip: {
                callbacks: {
                  label: function(context) {
                    const label = context.label || '';
                    const value = context.parsed || 0;
                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                    const percentage = ((value / total) * 100).toFixed(1);
                    return `${label}: ${value} (${percentage}%)`;
                  }
                }
              }
            }
          }
        });
      }
    }
  };

})(jQuery, Drupal, drupalSettings, once);
