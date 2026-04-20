/**
 * @file
 * Target Companies page interactions.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Filter companies by name in the job postings table.
   */
  function filterCompanies() {
    const input = document.getElementById('company-filter');
    if (!input) return;
    
    const filter = input.value.toLowerCase();
    const rows = document.querySelectorAll('.company-row');
    let visibleCount = 0;
    
    rows.forEach(function (row) {
      const companyName = row.getAttribute('data-company-name');
      if (companyName && companyName.indexOf(filter) > -1) {
        row.classList.remove('hidden');
        visibleCount++;
      } else {
        row.classList.add('hidden');
      }
    });
    
    const visibleCountEl = document.getElementById('visible-count');
    if (visibleCountEl) {
      visibleCountEl.textContent = visibleCount;
    }
  }

  /**
   * Add a company quickly to target companies.
   */
  window.addCompanyQuick = function (btn) {
    const companyName = btn.getAttribute('data-company');
    
    fetch('/jobhunter/companies/add-quick', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': drupalSettings.csrf_token || ''
      },
      body: JSON.stringify({ company_name: companyName })
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        if (data.success) {
          btn.outerHTML = '<span class="already-added">✓ ' + Drupal.t('Added to targets') + '</span>';
          // Reload page to update the target companies table
          setTimeout(function () {
            location.reload();
          }, 1000);
        } else {
          alert(Drupal.t('Error adding company: ') + data.message);
        }
      })
      .catch(function (error) {
        console.error('Error:', error);
        alert(Drupal.t('An error occurred while adding the company.'));
      });
  };

  /**
   * Target Companies behavior.
   */
  Drupal.behaviors.targetCompanies = {
    attach: function (context, settings) {
      // Initialize filter functionality
      once('target-companies-filter', '#company-filter', context).forEach(function (filterInput) {
        filterInput.addEventListener('keyup', filterCompanies);
        filterInput.addEventListener('search', filterCompanies); // For clear button
      });
      
      // Log initialization
      once('target-companies-init', '.target-companies-page', context).forEach(function () {
        console.log('Target Companies page initialized');
      });
    }
  };

})(Drupal, drupalSettings, once);
