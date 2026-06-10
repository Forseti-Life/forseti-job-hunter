(function (Drupal, once) {
  Drupal.behaviors.jobHunterNavigationQueue = {
    attach(context) {
      once('job-hunter-nav-queue', '.job-hunter-queue-status', context).forEach((panel) => {
        const statusUrl = panel.dataset.statusUrl;
        const countEl = panel.querySelector('.queue-count-value');
        const labelEl = panel.querySelector('.queue-count-label');
        const checkedEl = panel.querySelector('.queue-last-checked');

        if (!statusUrl || !countEl || !labelEl || !checkedEl) {
          return;
        }

        const formatCheckedAt = (unixTimestamp) => {
          const date = unixTimestamp ? new Date(unixTimestamp * 1000) : new Date();
          return date.toLocaleTimeString([], {
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
          });
        };

        const updateCheckedAt = (unixTimestamp) => {
          checkedEl.textContent = `Last status checked: ${formatCheckedAt(unixTimestamp)}`;
        };

        const refreshStatus = () => {
          fetch(statusUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
              Accept: 'application/json',
            },
          })
            .then((response) => response.json())
            .then((data) => {
              if (!data || data.success !== true) {
                throw new Error('Queue status unavailable');
              }

              const count = Number.isFinite(data.total_items) ? data.total_items : 0;
              countEl.textContent = String(count);
              labelEl.textContent = count === 1 ? 'item in queue' : 'items in queue';
              updateCheckedAt(data.checked_at);
            })
            .catch(() => {
              countEl.textContent = '--';
              labelEl.textContent = 'items in queue';
              updateCheckedAt(null);
            });
        };

        refreshStatus();
        window.setInterval(refreshStatus, 15000);
      });
    },
  };
})(Drupal, once);
