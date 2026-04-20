(function usersMetricsTimeRange(Drupal, once) {
  /**
   * Calculate date range based on time range option.
   *
   * @param {string} range - The time range option (24h, 7d, 30d, etc).
   * @return {Object|null} Object with from and to dates, or null if invalid.
   */
  function getDateRange(range) {
    const today = new Date();
    const toDate = today.toISOString().split('T')[0];
    let fromDate;

    switch (range) {
      case '24h':
        fromDate = new Date(today.getTime() - 24 * 60 * 60 * 1000);
        break;
      case '7d':
        fromDate = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000);
        break;
      case '30d':
        fromDate = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);
        break;
      case '90d':
        fromDate = new Date(today.getTime() - 90 * 24 * 60 * 60 * 1000);
        break;
      case '180d':
        fromDate = new Date(today.getTime() - 180 * 24 * 60 * 60 * 1000);
        break;
      case '365d':
        fromDate = new Date(today.getTime() - 365 * 24 * 60 * 60 * 1000);
        break;
      default:
        return null;
    }

    return {
      from: fromDate.toISOString().split('T')[0],
      to: toDate,
    };
  }

  /**
   * Update wrapper class based on preset selection.
   *
   * @param {HTMLElement} wrapper - The wrapper element.
   * @param {boolean} isPreset - Whether a preset is selected.
   */
  function updateWrapperState(wrapper, isPreset) {
    if (isPreset) {
      wrapper.classList.add('preset-selected');
    } else {
      wrapper.classList.remove('preset-selected');
    }
  }

  /**
   * Handle time range select change.
   *
   * @param {HTMLSelectElement} select - The select element.
   * @param {HTMLInputElement} dateFromInput - Date from input.
   * @param {HTMLInputElement} dateToInput - Date to input.
   * @param {HTMLElement} wrapper - The wrapper element.
   */
  function handleSelectChange(select, dateFromInput, dateToInput, wrapper) {
    const range = select.value;

    if (range) {
      const dates = getDateRange(range);
      if (dates) {
        dateFromInput.value = dates.from;
        dateToInput.value = dates.to;
      }
    }

    if (wrapper) {
      updateWrapperState(wrapper, range !== '');
    }
  }

  /**
   * Handle date input change.
   *
   * @param {HTMLSelectElement} select - The select element.
   * @param {HTMLElement} wrapper - The wrapper element.
   */
  function handleDateChange(select, wrapper) {
    select.value = '';
    if (wrapper) {
      updateWrapperState(wrapper, false);
    }
  }

  /**
   * Initialize a single time range select.
   *
   * @param {HTMLSelectElement} select - The select element to initialize.
   */
  function initTimeRangeSelect(select) {
    const form = select.closest('form');
    if (!form) {
      return;
    }

    const dateFromWrapper = form.querySelector('.users-metrics-date-from');
    const dateToWrapper = form.querySelector('.users-metrics-date-to');

    // Support both registrations and logins views with different field names.
    let dateFromInput = form.querySelector('[name="created_from"]');
    let dateToInput = form.querySelector('[name="created_to"]');

    // Fallback for logins view which uses timestamp fields.
    if (!dateFromInput) {
      dateFromInput = form.querySelector('[name="timestamp_from"]');
    }
    if (!dateToInput) {
      dateToInput = form.querySelector('[name="timestamp_to"]');
    }

    if (!dateFromInput || !dateToInput || !dateFromWrapper || !dateToWrapper) {
      return;
    }

    // Reorganize DOM: wrap time range and dates together.
    const selectWrapper = select.closest('.form-item');
    if (
      selectWrapper &&
      !selectWrapper.parentElement.classList.contains(
        'users-metrics-time-range-wrapper',
      )
    ) {
      const wrapper = document.createElement('div');
      wrapper.className = 'users-metrics-time-range-wrapper';

      const dateFields = document.createElement('div');
      dateFields.className = 'users-metrics-date-fields';

      selectWrapper.parentNode.insertBefore(wrapper, selectWrapper);
      wrapper.appendChild(selectWrapper);
      wrapper.appendChild(dateFields);
      dateFields.appendChild(dateFromWrapper);
      dateFields.appendChild(dateToWrapper);

      // Set initial state.
      updateWrapperState(wrapper, select.value !== '');
    }

    const wrapper = select.closest('.users-metrics-time-range-wrapper');

    // Update dates when time range changes.
    select.addEventListener('change', () => {
      handleSelectChange(select, dateFromInput, dateToInput, wrapper);
    });

    // Set time range to custom when dates are manually changed.
    dateFromInput.addEventListener('change', () => {
      handleDateChange(select, wrapper);
    });

    dateToInput.addEventListener('change', () => {
      handleDateChange(select, wrapper);
    });
  }

  /**
   * Time range selector behavior.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.usersMetricsTimeRange = {
    attach(context) {
      const timeRangeSelects = once(
        'users-metrics-time-range',
        '.users-metrics-time-range',
        context,
      );

      timeRangeSelects.forEach(initTimeRangeSelect);
    },
  };
})(Drupal, once);
