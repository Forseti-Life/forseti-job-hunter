(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.nfrFillRates = {
    attach: function (context, settings) {
      if (typeof Chart === 'undefined') {
        console.error('Chart.js not loaded');
        return;
      }

      if (!settings.nfr_fill_rates || !settings.nfr_fill_rates.chart_data) {
        console.log('No chart data available');
        return;
      }

      const chartData = settings.nfr_fill_rates.chart_data;
      
      const colors = [
        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
        '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#FF6384',
        '#36A2EB', '#FFCE56', '#FF9F40', '#9966FF', '#C9CBCF'
      ];
      
      chartData.forEach((chart, index) => {
        const canvas = document.getElementById(chart.id);
        if (canvas && !canvas.chartInstance) {
          canvas.chartInstance = new Chart(canvas, {
            type: 'bar',
            data: {
              labels: chart.labels,
              datasets: [{
                label: 'Count',
                data: chart.data,
                backgroundColor: colors[index % colors.length] + '80',
                borderColor: colors[index % colors.length],
                borderWidth: 2
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { display: false },
                title: { display: false }
              },
              scales: {
                y: {
                  beginAtZero: true,
                  ticks: { stepSize: 1 },
                  title: { display: true, text: 'Count' }
                },
                x: {
                  title: { display: true, text: 'Value' }
                }
              }
            }
          });
        }
      });
    }
  };

})(Drupal, drupalSettings);
