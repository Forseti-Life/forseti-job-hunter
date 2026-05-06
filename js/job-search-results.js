/**
 * @file
 * JavaScript for Job Search Results page - handles pagination and interactions.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Behavior for job search results pagination.
   */
  Drupal.behaviors.jobSearchResultsPagination = {
    attach: function (context, settings) {
      // Handle pagination button clicks
      once('pagination-handler', '.btn-pagination', context).forEach(function (button) {
        button.addEventListener('click', function (e) {
          e.preventDefault();
          
          const page = this.dataset.page;
          const token = this.dataset.token;
          
          // Get current URL and update parameters
          const url = new URL(window.location.href);
          url.searchParams.set('page', page);
          
          // Add next_page_token if it exists
          if (token) {
            url.searchParams.set('next_page_token', token);
          } else {
            // Remove token param if going to previous page
            url.searchParams.delete('next_page_token');
          }
          
          // Show loading indicator
          const resultsContainer = document.querySelector('.job-results-list');
          if (resultsContainer) {
            resultsContainer.style.opacity = '0.5';
            resultsContainer.style.pointerEvents = 'none';
          }
          
          // Navigate to new page
          window.location.href = url.toString();
        });
      });

      // Scroll to top on page load if pagination was used
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.has('page') && parseInt(urlParams.get('page')) > 1) {
        window.scrollTo({ top: 0, behavior: 'smooth' });
      }
    }
  };

  /**
   * Behavior for job save buttons.
   */
  Drupal.behaviors.jobSearchResultsSave = {
    attach: function (context, settings) {
      // Handle save job button clicks
      once('save-job-handler', '.btn-save-job', context).forEach(function (button) {
        button.addEventListener('click', function (e) {
          e.preventDefault();

          const saveUrl = this.href;
          const originalText = this.textContent;
          const csrfToken = this.dataset.csrfToken || '';
          const parsedUrl = new URL(saveUrl, window.location.origin);
          const jobId = parsedUrl.searchParams.get('job_id') || '';

          if (!jobId || !csrfToken) {
            window.location.href = saveUrl;
            return;
          }

          this.classList.add('is-saving');
          this.setAttribute('aria-busy', 'true');
          this.textContent = '⏳ Saving...';

          fetch(saveUrl, {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json',
              'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
              'X-CSRF-Token': csrfToken
            },
            credentials: 'same-origin',
            body: new URLSearchParams({
              job_id: jobId
            }).toString()
          })
            .then((response) => {
              if (response.status === 403) {
                throw new Error('Security token expired');
              }

              if (response.status === 401) {
                return response.json().then((payload) => {
                  if (payload.redirect) {
                    window.location.href = payload.redirect;
                    return null;
                  }
                  throw new Error(payload.message || 'Login required');
                });
              }

              if (!response.ok) {
                if (response.status === 404) {
                  // Fall back to legacy GET flow in environments where POST is not routed.
                  window.location.href = saveUrl;
                  return null;
                }
                return response.text().then((rawBody) => {
                  let message = `Request failed (${response.status})`;
                  if (rawBody) {
                    try {
                      const payload = JSON.parse(rawBody);
                      if (payload && payload.message) {
                        message = payload.message;
                      }
                    } catch (e) {
                      const excerpt = rawBody.replace(/\s+/g, ' ').trim().slice(0, 180);
                      if (excerpt) {
                        message = `${message}: ${excerpt}`;
                      }
                    }
                  }
                  message = `${message} [${response.url}]`;
                  throw new Error(message);
                });
              }

              return response.json();
            })
            .then((payload) => {
              if (!payload) {
                return;
              }

              if (payload.success) {
                button.classList.remove('is-saving');
                button.classList.add('is-saved');
                button.setAttribute('aria-busy', 'false');
                button.setAttribute('aria-disabled', 'true');
                button.textContent = payload.already_saved ? '✅ Already Saved' : '✅ Saved';
                button.style.pointerEvents = 'none';
                return;
              }

              throw new Error(payload.message || 'Unable to save');
            })
            .catch((error) => {
              console.error('Save job failed:', error);
              button.classList.remove('is-saving');
              button.setAttribute('aria-busy', 'false');
              button.textContent = originalText;
              window.alert(error.message || 'Could not save this job right now. Please try again.');
            });
        });
      });
    }
  };

})(Drupal, once);
