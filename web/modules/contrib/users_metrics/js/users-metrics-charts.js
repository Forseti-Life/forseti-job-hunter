/* global d3 */
(function usersMetricsCharts(Drupal, drupalSettings, once) {
  /**
   * Color palette for chart series.
   *
   * @type {string[]}
   */
  const COLORS = [
    '#006fb0',
    '#f07c33',
    '#342e9c',
    '#579b17',
    '#3f067a',
    '#cbde67',
    '#e91e63',
    '#00bcd4',
    '#ff9800',
    '#9c27b0',
    '#795548',
    '#607d8b',
    '#8bc34a',
    '#ff5722',
    '#673ab7',
    '#2196f3',
    '#4caf50',
    '#f44336',
    '#ffeb3b',
    '#009688',
    '#3f51b5',
    '#cddc39',
    '#ff4081',
    '#00e5ff',
    '#76ff03',
    '#536dfe',
    '#ff6e40',
    '#69f0ae',
    '#7c4dff',
    '#ffd740',
    '#448aff',
    '#b388ff',
    '#64ffda',
    '#ff80ab',
    '#ccff90',
    '#82b1ff',
    '#ea80fc',
    '#a7ffeb',
    '#ff8a80',
    '#f4ff81',
    '#8c9eff',
    '#b9f6ca',
    '#ffe57f',
    '#84ffff',
    '#ffd180',
  ];

  /**
   * Get color for a series by index.
   *
   * @param {number} index - The series index.
   * @return {string} The color hex code.
   */
  function getColor(index) {
    return COLORS[index % COLORS.length];
  }

  /**
   * Strip HTML tags from a string.
   *
   * @param {string} html - The HTML string to strip.
   * @return {string} The plain text string.
   */
  function stripHtml(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    return tmp.textContent || tmp.innerText || '';
  }

  /**
   * Clean label by stripping HTML.
   *
   * @param {string} label - The label to clean.
   * @return {string} The cleaned label.
   */
  function cleanLabel(label) {
    return stripHtml(label).trim();
  }

  /**
   * Check if a date string represents a weekend (Saturday or Sunday).
   *
   * @param {string} dateString - Date string in format YYYY-MM-DD or similar.
   * @return {boolean} True if the date is a Saturday or Sunday.
   */
  function isWeekend(dateString) {
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) {
      return false;
    }
    const day = date.getDay();
    return day === 0 || day === 6;
  }

  /**
   * Show tooltip at mouse position.
   *
   * @param {Event} event - The mouse event.
   * @param {string} text - The tooltip text to display.
   */
  function showTooltip(event, text) {
    let tooltip = d3.select('.users-metrics-tooltip');
    if (tooltip.empty()) {
      tooltip = d3
        .select('body')
        .append('div')
        .attr('class', 'users-metrics-tooltip');
    }

    tooltip
      .style('display', 'block')
      .style('left', `${event.pageX + 10}px`)
      .style('top', `${event.pageY - 10}px`)
      .text(text);
  }

  /**
   * Hide the tooltip.
   */
  function hideTooltip() {
    d3.select('.users-metrics-tooltip').style('display', 'none');
  }

  /**
   * Handle legend item mouseenter.
   *
   * @param {string} name - Series name.
   * @param {Function} highlightCallback - Callback function.
   */
  function handleLegendMouseenter(name, highlightCallback) {
    highlightCallback(name, true);
  }

  /**
   * Handle legend item mouseleave.
   *
   * @param {string} name - Series name.
   * @param {Function} highlightCallback - Callback function.
   */
  function handleLegendMouseleave(name, highlightCallback) {
    highlightCallback(name, false);
  }

  /**
   * Handle legend item click.
   *
   * @param {string} name - Series name.
   * @param {Object} item - D3 legend item element.
   * @param {Function} toggleCallback - Callback function.
   */
  function handleLegendClick(name, item, toggleCallback) {
    toggleCallback(name, item);
  }

  /**
   * Render interactive legend.
   *
   * @param {Object} container - The D3 container element.
   * @param {string[]} seriesNames - Array of series names.
   * @param {Object} cleanNames - Map of original names to cleaned names.
   * @param {number} chartHeight - The chart height in pixels.
   * @param {number} legendWidth - The legend width in pixels.
   * @param {Function} highlightCallback - Callback for highlighting series.
   * @param {Function} toggleCallback - Callback for toggling series visibility.
   */
  function renderLegend(
    container,
    seriesNames,
    cleanNames,
    chartHeight,
    legendWidth,
    highlightCallback,
    toggleCallback,
  ) {
    const legendContainer = container
      .append('div')
      .attr('class', 'legend-container')
      .style('width', `${legendWidth - 20}px`)
      .style('height', `${chartHeight}px`)
      .style('overflow-y', 'auto')
      .style('overflow-x', 'auto');

    seriesNames.forEach((name, index) => {
      const displayName = cleanNames[name];
      const item = legendContainer.append('div').attr('class', 'legend-item');

      item
        .append('div')
        .attr('class', 'legend-color')
        .style('background-color', getColor(index));

      item
        .append('span')
        .attr('class', 'legend-text')
        .attr('title', displayName)
        .text(displayName);

      item.on('mouseenter', () =>
        handleLegendMouseenter(name, highlightCallback),
      );
      item.on('mouseleave', () =>
        handleLegendMouseleave(name, highlightCallback),
      );
      item.on('click', () => handleLegendClick(name, item, toggleCallback));
    });
  }

  /**
   * Handle bar mouseover event.
   *
   * @param {Event} event - Mouse event.
   * @param {Object} d - Data point.
   */
  function handleBarMouseover(event, d) {
    showTooltip(event, `${d.label}: ${d.value}`);
  }

  /**
   * Handle bar mouseout event.
   */
  function handleBarMouseout() {
    hideTooltip();
  }

  /**
   * Render a bar chart (horizontal) with scroll for many items.
   *
   * @param {HTMLElement} container - The container element.
   * @param {Object} data - The chart data with series.
   * @param {Object} options - Chart options including showDataLabels.
   */
  function renderBarChart(container, data, options) {
    const flatData = [];
    const seriesNames = Object.keys(data.series);

    seriesNames.forEach((seriesName, seriesIndex) => {
      const cleanName = cleanLabel(seriesName);
      data.series[seriesName].forEach((d) => {
        flatData.push({
          label: cleanName,
          value: d.value,
          series: cleanName,
          color: getColor(seriesIndex),
        });
      });
    });

    // Calculate maximum label width.
    const tempSvg = d3
      .select(container)
      .append('svg')
      .attr('class', 'temp-measure');
    let maxLabelWidth = 0;
    flatData.forEach((d) => {
      const text = tempSvg.append('text').text(d.label);
      const { width } = text.node().getBBox();
      if (width > maxLabelWidth) {
        maxLabelWidth = width;
      }
      text.remove();
    });
    tempSvg.remove();

    const labelMargin = Math.min(Math.max(maxLabelWidth + 20, 150), 385);
    const margin = { top: 20, right: 80, bottom: 40, left: labelMargin };
    const containerWidth = container.clientWidth;
    const barHeight = 35;
    const minHeight = 200;
    const calculatedHeight = flatData.length * barHeight;
    const chartHeight = Math.max(minHeight, calculatedHeight);
    const maxVisibleHeight = 500;
    const minChartWidth = 300;
    const chartWidth = Math.max(
      containerWidth - margin.left - margin.right,
      minChartWidth,
    );
    const totalWidth = margin.left + chartWidth + margin.right;

    d3.select(container).selectAll('*').remove();

    const wrapper = d3
      .select(container)
      .append('div')
      .attr('class', 'chart-wrapper')
      .style('max-height', `${maxVisibleHeight}px`)
      .style('overflow-y', chartHeight > maxVisibleHeight ? 'auto' : 'visible')
      .style('overflow-x', totalWidth > containerWidth ? 'auto' : 'visible');

    const svg = wrapper
      .append('svg')
      .attr('width', Math.max(totalWidth, containerWidth))
      .attr('height', chartHeight + margin.top + margin.bottom)
      .append('g')
      .attr('transform', `translate(${margin.left},${margin.top})`);

    const y = d3
      .scaleBand()
      .domain(flatData.map((d) => d.label))
      .range([0, chartHeight])
      .padding(0.2);

    const maxValue = d3.max(flatData, (d) => d.value) || 0;
    const x = d3
      .scaleLinear()
      .domain([0, maxValue * 1.1])
      .range([0, chartWidth]);

    svg.append('g').attr('class', 'y-axis').call(d3.axisLeft(y));

    svg
      .append('g')
      .attr('class', 'x-axis')
      .attr('transform', `translate(0,${chartHeight})`)
      .call(d3.axisBottom(x).ticks(5));

    svg
      .selectAll('.bar')
      .data(flatData)
      .enter()
      .append('rect')
      .attr('class', 'bar')
      .attr('y', (d) => y(d.label))
      .attr('x', 0)
      .attr('height', y.bandwidth())
      .attr('width', (d) => x(d.value))
      .attr('fill', (d) => d.color)
      .on('mouseover', handleBarMouseover)
      .on('mouseout', handleBarMouseout);

    if (options.showDataLabels) {
      svg
        .selectAll('.bar-data-label')
        .data(flatData)
        .enter()
        .append('text')
        .attr('class', 'bar-data-label')
        .attr('y', (d) => y(d.label) + y.bandwidth() / 2)
        .attr('x', (d) => x(d.value) + 5)
        .attr('dy', '.35em')
        .text((d) => d.value);
    }
  }

  /**
   * Create point mouseover handler.
   *
   * @param {Object} cleanNames - Map of original names to cleaned names.
   * @param {string} seriesName - Series name.
   * @param {boolean} hasMultipleSeries - Whether there are multiple series.
   * @return {Function} Event handler function.
   */
  function createPointMouseoverHandler(
    cleanNames,
    seriesName,
    hasMultipleSeries,
  ) {
    return function pointMouseoverHandler(event, d) {
      d3.select(this).attr('r', 6);
      // Show date and value, add series name only if multiple series exist.
      const tooltipText = hasMultipleSeries
        ? `${d.label} (${cleanNames[seriesName]}): ${d.value}`
        : `${d.label}: ${d.value}`;
      showTooltip(event, tooltipText);
    };
  }

  /**
   * Handle point mouseout event.
   */
  function handlePointMouseout() {
    d3.select(this).attr('r', 4);
    hideTooltip();
  }

  /**
   * Render a spline/line chart with interactive legend.
   *
   * @param {HTMLElement} container - The container element.
   * @param {Object} data - The chart data with series.
   * @param {Object} options - Chart options.
   * @param {string} chartType - The chart type (spline, line, area).
   */
  function renderSplineChart(container, data, options, chartType) {
    const seriesNames = Object.keys(data.series);
    const cleanNames = {};
    let maxCleanNameLength = 0;

    seriesNames.forEach((name) => {
      const cleanName = cleanLabel(name);
      cleanNames[name] = cleanName;
      if (cleanName.length > maxCleanNameLength) {
        maxCleanNameLength = cleanName.length;
      }
    });

    const hasMultipleSeries = seriesNames.length > 1;
    const visibleSeries = {};
    seriesNames.forEach((name) => {
      visibleSeries[name] = true;
    });

    const estimatedCharWidth = 7;
    const legendPadding = 40;
    const calculatedLegendWidth = Math.min(
      Math.max(maxCleanNameLength * estimatedCharWidth + legendPadding, 150),
      306,
    );
    const legendWidth =
      hasMultipleSeries && options.legendPosition === 'right'
        ? calculatedLegendWidth
        : 0;
    const margin = { top: 20, right: 30, bottom: 80, left: 60 };
    const containerWidth = container.clientWidth;
    const height = 350;

    d3.select(container).selectAll('*').remove();

    const mainContainer = d3
      .select(container)
      .append('div')
      .attr('class', 'chart-main-container');

    const chartContainer = mainContainer
      .append('div')
      .attr('class', 'chart-svg-container');

    const svg = chartContainer
      .append('svg')
      .attr('width', containerWidth - legendWidth)
      .attr('height', height + margin.top + margin.bottom)
      .append('g')
      .attr('transform', `translate(${margin.left},${margin.top})`);

    const chartWidth =
      containerWidth - legendWidth - margin.left - margin.right;

    const allLabels = [];
    seriesNames.forEach((name) => {
      data.series[name].forEach((d) => {
        if (!allLabels.includes(d.label)) {
          allLabels.push(d.label);
        }
      });
    });
    allLabels.sort();

    const x = d3
      .scalePoint()
      .domain(allLabels)
      .range([0, chartWidth])
      .padding(0.5);

    let maxValue = 0;
    seriesNames.forEach((name) => {
      data.series[name].forEach((d) => {
        if (d.value > maxValue) {
          maxValue = d.value;
        }
      });
    });

    const y = d3
      .scaleLinear()
      .domain([0, maxValue * 1.1])
      .range([height, 0]);

    // Calculate optimal tick count based on available width.
    const estimatedLabelWidth = 70;
    const maxTicks = Math.floor(chartWidth / estimatedLabelWidth);
    const tickInterval = Math.ceil(allLabels.length / Math.max(maxTicks, 1));
    const shouldReduceTicks = allLabels.length > maxTicks;

    // Filter tick values if there are too many labels.
    const tickValues = shouldReduceTicks
      ? allLabels.filter((_, i) => i % tickInterval === 0)
      : allLabels;

    const xAxis = svg
      .append('g')
      .attr('class', 'x-axis')
      .attr('transform', `translate(0,${height})`)
      .call(d3.axisBottom(x).tickValues(tickValues));

    // Apply rotation for better readability when there are many labels.
    const shouldRotate =
      options.xAxisLabelRotation > 0 || allLabels.length > 15;
    if (shouldRotate) {
      const rotation =
        options.xAxisLabelRotation > 0 ? options.xAxisLabelRotation : 45;
      xAxis
        .attr('class', 'x-axis x-axis-rotated')
        .selectAll('text')
        .attr('transform', `rotate(-${rotation})`)
        .attr('dx', '-.8em')
        .attr('dy', '.15em');
    }

    svg.append('g').attr('class', 'y-axis').call(d3.axisLeft(y).ticks(5));

    svg
      .append('g')
      .attr('class', 'grid')
      .call(d3.axisLeft(y).ticks(5).tickSize(-chartWidth).tickFormat(''));

    // Draw weekend bands behind the data.
    const weekendGroup = svg
      .insert('g', ':first-child')
      .attr('class', 'weekend-bands');

    const bandWidth = x.step ? x.step() : chartWidth / allLabels.length;

    allLabels.forEach((label) => {
      if (isWeekend(label)) {
        weekendGroup
          .append('rect')
          .attr('class', 'weekend-band')
          .attr('x', x(label) - bandWidth / 2)
          .attr('y', 0)
          .attr('width', bandWidth)
          .attr('height', height);
      }
    });

    let lineGenerator;
    if (chartType === 'spline') {
      lineGenerator = d3
        .line()
        .defined((d) => d !== null && d.value !== null)
        .x((d) => x(d.label))
        .y((d) => y(d.value))
        .curve(d3.curveCardinal.tension(0.5));
    } else {
      lineGenerator = d3
        .line()
        .defined((d) => d !== null && d.value !== null)
        .x((d) => x(d.label))
        .y((d) => y(d.value));
    }

    let areaGenerator;
    if (chartType === 'area') {
      areaGenerator = d3
        .area()
        .defined((d) => d !== null && d.value !== null)
        .x((d) => x(d.label))
        .y0(height)
        .y1((d) => y(d.value))
        .curve(d3.curveCardinal.tension(0.5));
    }

    const seriesElements = {};

    seriesNames.forEach((seriesName, seriesIndex) => {
      const seriesData = data.series[seriesName];
      const color = getColor(seriesIndex);

      const alignedData = allLabels
        .map((label) => {
          const found = seriesData.find((d) => d.label === label);
          return found ? { label, value: found.value } : null;
        })
        .filter((d) => d !== null);

      const seriesGroup = svg
        .append('g')
        .attr('class', 'series-group')
        .attr('data-series', seriesName);

      seriesElements[seriesName] = {
        group: seriesGroup,
        color,
        index: seriesIndex,
      };

      if (chartType === 'area') {
        seriesGroup
          .append('path')
          .datum(alignedData)
          .attr('class', 'area')
          .attr('fill', color)
          .attr('d', areaGenerator);
      }

      seriesGroup
        .append('path')
        .datum(alignedData)
        .attr('class', 'line')
        .attr('stroke', color)
        .attr('d', lineGenerator);

      seriesGroup
        .selectAll('.point')
        .data(alignedData)
        .enter()
        .append('circle')
        .attr('class', 'point')
        .attr('cx', (d) => x(d.label))
        .attr('cy', (d) => y(d.value))
        .attr('r', 4)
        .attr('fill', color)
        .on(
          'mouseover',
          createPointMouseoverHandler(
            cleanNames,
            seriesName,
            hasMultipleSeries,
          ),
        )
        .on('mouseout', handlePointMouseout);

      if (options.showDataLabels) {
        seriesGroup
          .selectAll('.data-label')
          .data(alignedData)
          .enter()
          .append('text')
          .attr('class', 'data-label')
          .attr('x', (d) => x(d.label))
          .attr('y', (d) => y(d.value) - 10)
          .text((d) => d.value);
      }
    });

    /**
     * Highlight or dim series based on legend interaction.
     *
     * @param {string} seriesName - The series name to highlight.
     * @param {boolean} highlight - Whether to highlight or reset.
     */
    function highlightSeries(seriesName, highlight) {
      if (!visibleSeries[seriesName]) {
        return;
      }

      seriesNames.forEach((name) => {
        if (!visibleSeries[name]) {
          return;
        }

        const elements = seriesElements[name];
        if (highlight && name !== seriesName) {
          elements.group.classed('dimmed', true).classed('highlighted', false);
        } else if (highlight && name === seriesName) {
          elements.group.classed('highlighted', true).classed('dimmed', false);
        } else {
          elements.group.classed('dimmed', false).classed('highlighted', false);
        }
      });
    }

    /**
     * Toggle series visibility.
     *
     * @param {string} seriesName - The series name to toggle.
     * @param {Object} legendItem - The D3 legend item element.
     */
    function toggleSeries(seriesName, legendItem) {
      visibleSeries[seriesName] = !visibleSeries[seriesName];
      const elements = seriesElements[seriesName];

      if (visibleSeries[seriesName]) {
        elements.group.classed('hidden', false);
        legendItem.classed('inactive', false);
      } else {
        elements.group.classed('hidden', true);
        legendItem.classed('inactive', true);
      }
    }

    if (hasMultipleSeries && options.legendPosition !== 'none') {
      renderLegend(
        mainContainer,
        seriesNames,
        cleanNames,
        height + margin.top + margin.bottom,
        calculatedLegendWidth,
        highlightSeries,
        toggleSeries,
      );
    }
  }

  /**
   * Check if data has any values.
   *
   * @param {Object} data - The chart data object.
   * @return {boolean} True if data contains values.
   */
  function hasData(data) {
    if (!data || !data.series) {
      return false;
    }
    const seriesNames = Object.keys(data.series);
    if (seriesNames.length === 0) {
      return false;
    }
    for (let i = 0; i < seriesNames.length; i++) {
      if (data.series[seriesNames[i]].length > 0) {
        return true;
      }
    }
    return false;
  }

  /**
   * Render no data message.
   *
   * @param {HTMLElement} container - The container element.
   */
  function renderNoData(container) {
    d3.select(container).selectAll('*').remove();
    d3.select(container)
      .append('div')
      .attr('class', 'users-metrics-no-data')
      .text(
        Drupal.t(
          'There is no data matching the selected criteria. Try adjusting the date range or filters.',
        ),
      );
  }

  /**
   * Render chart based on type.
   *
   * @param {HTMLElement} chartContainer - Container element.
   * @param {string} chartType - Chart type.
   * @param {Object} data - Chart data.
   * @param {Object} options - Chart options.
   */
  function renderChart(chartContainer, chartType, data, options) {
    if (chartType === 'bar') {
      renderBarChart(chartContainer, data, options);
    } else {
      renderSplineChart(chartContainer, data, options, chartType);
    }
  }

  /**
   * Create resize handler for a chart.
   *
   * @param {HTMLElement} chartContainer - Container element.
   * @param {string} chartType - Chart type.
   * @param {Object} data - Chart data.
   * @param {Object} options - Chart options.
   * @return {Function} Resize handler function.
   */
  function createResizeHandler(chartContainer, chartType, data, options) {
    let resizeTimeout;
    return function resizeHandler() {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(() => {
        renderChart(chartContainer, chartType, data, options);
      }, 250);
    };
  }

  /**
   * Store resize handlers for cleanup.
   *
   * @type {Map}
   */
  const resizeHandlers = new Map();

  /**
   * Process a single chart configuration.
   *
   * @param {string} chartId - Chart element ID.
   */
  function processChart(chartId) {
    const config = drupalSettings.usersMetricsCharts[chartId];
    const chartContainer = document.getElementById(chartId);

    if (!chartContainer) {
      return;
    }

    once('users-metrics-chart', chartContainer).forEach(() => {
      const chartType = config.type;
      const { data } = config;
      const { options } = config;

      // Check if there is data to display.
      if (!hasData(data)) {
        renderNoData(chartContainer);
        return;
      }

      renderChart(chartContainer, chartType, data, options);

      // Create and store resize handler for cleanup.
      const resizeHandler = createResizeHandler(
        chartContainer,
        chartType,
        data,
        options,
      );
      resizeHandlers.set(chartContainer, resizeHandler);
      window.addEventListener('resize', resizeHandler);
    });
  }

  /**
   * Initialize charts.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.usersMetricsCharts = {
    attach() {
      if (!drupalSettings.usersMetricsCharts) {
        return;
      }

      Object.keys(drupalSettings.usersMetricsCharts).forEach(processChart);
    },

    detach(context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }

      // Clean up resize handlers when elements are removed.
      resizeHandlers.forEach(function cleanupAllHandlers(handler, container) {
        if (context.contains(container) || context === container) {
          window.removeEventListener('resize', handler);
          resizeHandlers.delete(container);
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
