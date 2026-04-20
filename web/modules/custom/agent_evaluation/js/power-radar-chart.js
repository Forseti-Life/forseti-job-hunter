/**
 * @file
 * Power Radar Chart using Chart.js for evaluated entities.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.powerRadarChart = {
    attach: function (context, settings) {
      once('power-radar-chart', '.power-radar-chart', context).forEach(function (element) {
        const canvas = element.querySelector('canvas');
        const resetBtn = element.querySelector('.chart-reset-btn');
        if (!canvas) {
          console.error('Canvas not found');
          return;
        }

        // Check if Chart.js is loaded
        if (typeof Chart === 'undefined') {
          console.error('Chart.js is not loaded');
          return;
        }

        // Get data from data attributes
        const chartData = JSON.parse(element.dataset.chart || '{}');
        const entityName = element.dataset.entityName || 'Entity';
        
        console.log('Chart data:', chartData);
        console.log('Entity name:', entityName);
        
        let currentChart = null;
        let currentView = 'overview'; // 'overview' or dimension key
        
        // Define colors for each main dimension
        const dimensionColors = {
          'information_access': {
            primary: '#00d4ff',
            light: 'rgba(0, 212, 255, 0.6)'
          },
          'resource_control': {
            primary: '#4caf50',
            light: 'rgba(76, 175, 80, 0.6)'
          },
          'authority_&_permission': {
            primary: '#e91e63',
            light: 'rgba(233, 30, 99, 0.6)'
          },
          'network_position': {
            primary: '#9c27b0',
            light: 'rgba(156, 39, 176, 0.6)'
          },
          'synthesis_&_application': {
            primary: '#ffeb3b',
            light: 'rgba(255, 235, 59, 0.6)'
          }
        };

        // Color scheme
        const chartColor = 'rgba(0, 212, 255, 0.2)';
        const chartBorderColor = '#00d4ff';
        const gridColor = 'rgba(0, 212, 255, 0.2)';
        const textColor = '#00d4ff';
        const backgroundColor = '#16213e';
        
        // Helper function to convert hex to RGB
        function hexToRgb(hex) {
          const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
          return result ? {
            r: parseInt(result[1], 16),
            g: parseInt(result[2], 16),
            b: parseInt(result[3], 16)
          } : null;
        }
        
        // Helper function to create color variations
        function getColorVariation(hex, variation) {
          const rgb = hexToRgb(hex);
          if (!rgb) return hex;
          
          // Create 6 distinct variations by adjusting RGB values
          const variations = [
            `rgb(${rgb.r}, ${rgb.g}, ${rgb.b})`, // Original
            `rgb(${Math.min(255, rgb.r + 30)}, ${Math.min(255, rgb.g + 20)}, ${rgb.b})`, // Warmer
            `rgb(${Math.max(0, rgb.r - 20)}, ${Math.min(255, rgb.g + 30)}, ${Math.min(255, rgb.b + 20)})`, // Cooler
            `rgb(${Math.min(255, rgb.r + 40)}, ${rgb.g}, ${Math.min(255, rgb.b + 30)})`, // Brighter
            `rgb(${rgb.r}, ${Math.min(255, rgb.g + 25)}, ${Math.max(0, rgb.b - 20)})`, // Variant
            `rgb(${Math.max(0, rgb.r - 15)}, ${Math.max(0, rgb.g - 15)}, ${Math.min(255, rgb.b + 40)})` // Deeper
          ];
          
          return variations[variation % 6];
        }
        
        function createChart(labels, scores, types, dimensionKeys) {
          if (currentChart) {
            currentChart.destroy();
          }
          
          const labelColors = labels.map((label, index) => {
            const dimKey = dimensionKeys[index];
            const type = types ? types[index] : 'primary';
            const colorSet = dimensionColors[dimKey] || dimensionColors['information_access'];
            
            if (type === 'primary') {
              return colorSet.primary;
            } else {
              // For sub-dimensions, create distinct color variations
              return getColorVariation(colorSet.primary, index);
            }
          });
          
          const primaryFontSize = 14;
          const subFontSize = Math.round(primaryFontSize * 0.8);
          
          // For now, use a simple semi-transparent fill
          let fillColor;
          if (labels.length === 5 && (!types || types.length === 0)) {
            // Overview mode - blend all dimension colors
            fillColor = 'rgba(0, 212, 255, 0.15)';
          } else {
            // Sub-dimension mode - use the parent dimension color
            const mainDimKey = dimensionKeys[0];
            const colorSet = dimensionColors[mainDimKey] || dimensionColors['information_access'];
            fillColor = colorSet.light.replace('0.6', '0.25');
          }
          
          // Plugin for glow effects and animations
          const glowPlugin = {
            id: 'glowEffect',
            beforeDatasetsDraw: (chart) => {
              const ctx = chart.ctx;
              ctx.save();
              
              // Add shadow/glow to the filled area
              ctx.shadowColor = chartBorderColor;
              ctx.shadowBlur = 15;
              ctx.shadowOffsetX = 0;
              ctx.shadowOffsetY = 0;
            },
            afterDatasetsDraw: (chart) => {
              const ctx = chart.ctx;
              ctx.restore();
              
              // Add glow to points
              chart.data.datasets.forEach((dataset, datasetIndex) => {
                const meta = chart.getDatasetMeta(datasetIndex);
                meta.data.forEach((point, index) => {
                  ctx.save();
                  ctx.shadowColor = labelColors[index] || chartBorderColor;
                  ctx.shadowBlur = 10;
                  ctx.fillStyle = labelColors[index] || chartBorderColor;
                  ctx.beginPath();
                  ctx.arc(point.x, point.y, point.options.radius, 0, Math.PI * 2);
                  ctx.fill();
                  ctx.restore();
                });
              });
            }
          };
          
          const datasets = [{
            label: entityName,
            data: scores,
            backgroundColor: fillColor,
            borderColor: chartBorderColor,
            borderWidth: 3,
            pointBackgroundColor: labelColors,
            pointBorderColor: backgroundColor,
            pointBorderWidth: 2,
            pointHoverBackgroundColor: '#ffffff',
            pointHoverBorderColor: labelColors,
            pointRadius: 6,
            pointHoverRadius: 10,
            pointHitRadius: 15,
            tension: 0.2, // Curved lines
            order: 1
          }];
          currentChart = new Chart(canvas, {
            type: 'radar',
            data: {
              labels: labels,
              datasets: datasets
            },
            options: {
              responsive: true,
              maintainAspectRatio: true,
              animation: {
                duration: 800,
                easing: 'easeInOutQuart'
              },
              transitions: {
                active: {
                  animation: {
                    duration: 300
                  }
                }
              },
              onClick: function(evt, activeElements) {
                if (activeElements.length > 0) {
                  const index = activeElements[0].index;
                  
                  if (currentView === 'overview') {
                    // Clicking on primary dimension - expand to sub-dimensions
                    const dimensionKey = chartData.primary_keys[index];
                    expandDimension(dimensionKey);
                  } else {
                    // Clicking on sub-dimension - scroll to accordion
                    const subDimensionData = chartData.dimensions[currentView];
                    if (subDimensionData && subDimensionData.sub_ids && subDimensionData.sub_ids[index]) {
                      const subDimId = subDimensionData.sub_ids[index];
                      scrollToSubDimension(subDimId, currentView);
                    }
                  }
                }
              },
              scales: {
                r: {
                  beginAtZero: true,
                  min: 0,
                  max: 9,
                  ticks: {
                    stepSize: 1,
                    color: textColor,
                    backdropColor: 'transparent',
                    font: {
                      size: 9,
                      family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                    }
                  },
                  grid: {
                    color: gridColor,
                    circular: true,
                    lineWidth: 1
                  },
                  pointLabels: {
                    color: function(context) {
                      return labelColors[context.index] || textColor;
                    },
                    font: function(context) {
                      if (!types || types.length === 0) {
                        return { 
                          size: primaryFontSize, 
                          weight: '700',
                          family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                        };
                      }
                      const type = types[context.index];
                      return {
                        size: type === 'primary' ? primaryFontSize : subFontSize,
                        weight: type === 'primary' ? '700' : '500',
                        family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                      };
                    },
                    padding: 15
                  },
                  angleLines: {
                    color: gridColor,
                    lineWidth: 1
                  }
                }
              },
              plugins: {
                legend: {
                  display: false,
                  events: [] // Disable legend events to prevent errors
                },
                tooltip: {
                  enabled: true,
                  backgroundColor: 'rgba(22, 33, 62, 0.95)',
                  titleColor: textColor,
                  bodyColor: '#ffffff',
                  borderColor: textColor,
                  borderWidth: 1,
                  padding: 12,
                  displayColors: true,
                  callbacks: {
                    title: function(context) {
                      return context[0].label;
                    },
                    label: function(context) {
                      return 'Score: ' + context.parsed.r + ' / 9';
                    },
                    labelColor: function(context) {
                      return {
                        borderColor: labelColors[context.dataIndex] || textColor,
                        backgroundColor: labelColors[context.dataIndex] || textColor,
                        borderWidth: 2,
                        borderRadius: 2
                      };
                    }
                  }
                }
              }
            },
            plugins: [glowPlugin, {
              id: 'scoreLabels',
              afterDatasetsDraw: (chart) => {
                const ctx = chart.ctx;
                const meta = chart.getDatasetMeta(0);
                
                meta.data.forEach((point, index) => {
                  const score = scores[index];
                  ctx.save();
                  
                  // Position label slightly outside the point
                  const angle = point.angle;
                  const distance = 15;
                  const x = point.x + Math.cos(angle) * distance;
                  const y = point.y + Math.sin(angle) * distance;
                  
                  // Draw background circle for label
                  ctx.fillStyle = 'rgba(22, 33, 62, 0.9)';
                  ctx.beginPath();
                  ctx.arc(x, y, 11, 0, Math.PI * 2);
                  ctx.fill();
                  
                  // Draw score text
                  ctx.fillStyle = labelColors[index] || textColor;
                  ctx.font = 'bold 10px "Segoe UI"';
                  ctx.textAlign = 'center';
                  ctx.textBaseline = 'middle';
                  ctx.fillText(score, x, y);
                  
                  ctx.restore();
                });
              }
            }]
          });
        }
        
        function showOverview() {
          currentView = 'overview';
          resetBtn.style.display = 'none';
          createChart(
            chartData.primary_labels,
            chartData.primary_scores,
            [],
            chartData.primary_keys
          );
        }
        
        function expandDimension(dimensionKey) {
          currentView = dimensionKey;
          resetBtn.style.display = 'inline-block';
          const dimData = chartData.dimensions[dimensionKey];
          if (dimData) {
            createChart(
              dimData.labels,
              dimData.scores,
              dimData.types,
              dimData.keys
            );
          }
        }
        
        function scrollToSubDimension(subDimId, dimensionKey) {
          console.log('Scrolling to sub-dimension:', subDimId, 'in dimension:', dimensionKey);
          
          // Find the sub-dimension element
          const subDimElement = document.getElementById('subdim-' + subDimId);
          if (!subDimElement) {
            console.error('Sub-dimension element not found:', subDimId);
            return;
          }
          
          // Find the parent accordion item
          const accordionItem = document.querySelector('[data-dimension="' + dimensionKey + '"]');
          if (!accordionItem) {
            console.error('Accordion item not found for dimension:', dimensionKey);
            return;
          }
          
          // Get the collapse element
          const collapseElement = accordionItem.querySelector('.accordion-collapse');
          if (collapseElement) {
            // Use Bootstrap's Collapse API to open the accordion
            const bsCollapse = new bootstrap.Collapse(collapseElement, {
              toggle: false
            });
            bsCollapse.show();
            
            // Wait for accordion to open, then scroll
            setTimeout(function() {
              subDimElement.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'center' 
              });
              
              // Add a highlight effect
              subDimElement.style.transition = 'background-color 0.3s';
              subDimElement.style.backgroundColor = 'rgba(0, 212, 255, 0.2)';
              setTimeout(function() {
                subDimElement.style.backgroundColor = '';
              }, 2000);
            }, 350); // Wait for Bootstrap collapse animation
          }
        }
        
        // Reset button handler
        if (resetBtn) {
          resetBtn.addEventListener('click', showOverview);
        }
        
        // Initialize with overview
        showOverview();
      });
    }
  };

})(Drupal, once);
