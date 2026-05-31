/**
 * @file
 * Job Discovery JavaScript functionality
 */

(function ($, Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Job Discovery behavior.
   */
  Drupal.behaviors.jobDiscovery = {
    attach: function (context, settings) {
      // Ensure we only attach once using Drupal 11's once utility
      once('job-discovery', '#start-discovery-btn', context).forEach(function(element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          startJobDiscovery();
        });
      });
    }
  };

  /**
   * Start the job discovery process.
   */
  function startJobDiscovery() {
    const $button = $('#start-discovery-btn');
    const $status = $('#discovery-status');
    const $results = $('#discovery-results');
    
    // Show loading state
    $button.prop('disabled', true).text('Searching...');
    $status.show();
    
    // Get the current user ID from the URL
    const pathParts = window.location.pathname.split('/');
    const userId = pathParts[2]; // /user/{id}/job-discovery/company/{company_id}
    
    // Get company ID from button data attribute
    const companyId = $button.data('company-id');
    
    // Make AJAX request to search for jobs
    const searchData = {
      user_id: userId,
      company_id: companyId
    };
    
    // Make AJAX request to search for jobs using Drupal AJAX
    $.ajax({
      url: '/job-discovery/search',
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': drupalSettings.csrf_token || ''
      },
      data: JSON.stringify(searchData),
      dataType: 'json',

      success: function(response) {
        console.log('AJAX success response:', response);
        console.log('Response type:', typeof response);
        console.log('Jobs found:', response.jobs ? response.jobs.length : 'No jobs property');
        
        if (response.jobs && response.jobs.length > 0) {
          console.log('Displaying', response.jobs.length, 'jobs');
          displayResults(response.jobs);
        } else {
          console.log('No jobs found, showing empty results');
          displayResults([]);
        }
      },
      error: function(xhr, status, error) {
        console.log('AJAX error:', xhr.status, error);
        console.log('Response text:', xhr.responseText);
        
        // Try to parse error response
        let errorMessage = 'Failed to search jobs. ';
        try {
          const errorResponse = JSON.parse(xhr.responseText);
          errorMessage += errorResponse.error || 'Unknown error';
        } catch (e) {
          errorMessage += 'Server error: ' + xhr.status;
        }
        
        // Show error message instead of simulated results
        displayError(errorMessage);
      },
      complete: function() {
        // Hide loading state
        $button.prop('disabled', false).html('<i class="fas fa-search"></i> Start New Search');
        $status.hide();
      }
    });
  }
  
  /**
   * Show simulated results based on the AbbVie HTML provided.
   */
  function showSimulatedResults() {
    const simulatedJobs = [
      {
        title: 'Key Account & Distributors Manager – Allergan Aesthetics',
        location: 'Bucharest, Romania',
        description: 'The Key Account & Distributors Manager – Romania will play a pivotal role in accelerating growth and expanding market presence for the Allergan Aesthetics portfolio across Romania.',
        jobId: 'R00131690',
        url: 'https://careers.abbvie.com/en/job/key-account-and-distributors-manager-allergan-aesthetics-in-bucharest-ro-jid-18035',
        function: 'Allergan Aesthetics',
        therapyArea: 'Aesthetics',
        experienceLevel: 'Entry Level',
        jobType: 'Full-time'
      },
      {
        title: 'Technical Writer',
        location: 'Westport, Ireland',
        description: 'People. Passion. Possibilities. It\'s who we are, what we do, and what we stand for. We are currently recruiting a Technical Writer as part of the overall Product Flow function within the Core 1 Business.',
        jobId: 'R00134197',
        url: 'https://careers.abbvie.com/en/job/technicial-writer-in-westport-mo-jid-20529',
        function: 'Operations',
        therapyArea: '',
        experienceLevel: 'Entry Level',
        jobType: 'Full-time'
      },
      {
        title: 'Key Account Specialist/Manager, Gastroenterology (Immunology)',
        location: 'Stara Zagora, Bulgaria',
        description: 'Performing all core job responsibilities of Medical Representative/Key Account Specialist at an expert level, plus: Identifies all key account direct and indirect stakeholders.',
        jobId: 'R00135217',
        url: 'https://careers.abbvie.com/en/job/key-account-specialist-manager-gastroenterology-immunology-in-stara-zagora-stara-zagora-jid-20528',
        function: 'Commercial',
        therapyArea: 'Immunology',
        experienceLevel: 'Entry Level',
        jobType: 'Full-time'
      },
      {
        title: 'Key Account Specialist/Manager, Gastroenterology (Immunology)',
        location: 'Burgas, Bulgaria',
        description: 'Performing all core job responsibilities of Medical Representative/Key Account Specialist at an expert level, plus: Identifies all key account direct and indirect stakeholders.',
        jobId: 'R00135216',
        url: 'https://careers.abbvie.com/en/job/key-account-specialist-manager-gastroenterology-immunology-in-burgas-burgas-jid-20527',
        function: 'Commercial',
        therapyArea: 'Immunology',
        experienceLevel: 'Entry Level',
        jobType: 'Full-time'
      }
    ];
    
    displayResults(simulatedJobs);
  }

  /**
   * Display error message.
   */
  function displayError(errorMessage) {
    const $results = $('#discovery-results');
    const $resultsContainer = $('#results-container');
    
    $resultsContainer.html('<div class="error-results"><div class="alert alert-danger"><strong>Error:</strong> ' + Drupal.jobHunter.escapeHtml(errorMessage) + '</div></div>');
    
    // Show results section
    $results.show();
    
    // Smooth scroll to results
    $('html, body').animate({
      scrollTop: $results.offset().top - 100
    }, 800);
  }

  /**
   * Display job search results.
   */
  function displayResults(jobs) {
    const $results = $('#discovery-results');
    const $resultsContainer = $('#results-container');
    
    // Store jobs globally for save functionality
    window.currentJobResults = jobs;
    
    if (jobs.length === 0) {
      $resultsContainer.html('<div class="no-results"><p>No matching opportunities found. Try updating your profile keywords or check back later.</p></div>');
    } else {
      let resultsHTML = '<div class="results-summary"><p>Found <strong>' + jobs.length + '</strong> matching opportunities:</p></div>';
      
      jobs.forEach(function(job) {
        resultsHTML += createJobCard(job);
      });
      
      $resultsContainer.html(resultsHTML);
    }
    
    // Show results section
    $results.show();
    
    // Smooth scroll to results
    $('html, body').animate({
      scrollTop: $results.offset().top - 100
    }, 800);
  }

  /**
   * Create HTML for a job card.
   */
  function createJobCard(job) {
    const tags = [];
    if (job.function) tags.push(job.function);
    if (job.therapyArea) tags.push(job.therapyArea);
    if (job.experienceLevel) tags.push(job.experienceLevel);
    if (job.jobType) tags.push(job.jobType);
    
    const tagsHTML = tags.length > 0 ? 
      '<div class="job-tags">' + tags.map(tag => '<span class="job-tag">' + Drupal.jobHunter.escapeHtml(tag) + '</span>').join('') + '</div>' : '';
    
    const safeUrl = Drupal.jobHunter.sanitizeUrl(job.url);
    const safeJobId = Drupal.jobHunter.escapeHtml(job.jobId);
    
    return `
      <div class="job-result">
        <div class="job-title">
          <a href="${safeUrl}" target="_blank" rel="noopener noreferrer">${Drupal.jobHunter.escapeHtml(job.title)}</a>
        </div>
        <div class="job-location">
          <i class="fas fa-map-marker-alt"></i> ${Drupal.jobHunter.escapeHtml(job.location)}
        </div>
        <div class="job-description">
          ${Drupal.jobHunter.escapeHtml(job.description)}
        </div>
        ${tagsHTML}
        <div class="job-actions">
          <a href="${safeUrl}" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm">
            <i class="fas fa-external-link-alt"></i> View Job
          </a>
          <button class="btn btn-outline-secondary btn-sm save-job-btn" data-job-id="${safeJobId}">
            <i class="fas fa-bookmark"></i> Save Job
          </button>
          <span class="job-id">Job ID: ${safeJobId}</span>
        </div>
      </div>
    `;
  }
  
  /**
   * Handle saving jobs to the user's dashboard.
   */
  $(document).on('click', '.save-job-btn', function(e) {
    e.preventDefault();
    const $btn = $(this);
    const jobId = $btn.data('job-id');
    
    // Find the job data from the current results
    let jobData = null;
    for (let job of window.currentJobResults || []) {
      if (job.jobId === jobId) {
        jobData = job;
        break;
      }
    }
    
    if (!jobData) {
      console.error('Job data not found for ID:', jobId);
      Drupal.announce('Error: Job data not found', 'assertive');
      return;
    }
    
    // Validate URL before sending to backend
    const sanitizedUrl = Drupal.jobHunter.sanitizeUrl(jobData.url);
    if (sanitizedUrl === '#') {
      console.error('Invalid URL for job:', jobData.url);
      Drupal.announce('Error: Job has invalid URL', 'assertive');
      return;
    }
    
    // Create sanitized copy of job data
    const sanitizedJobData = {
      ...jobData,
      url: sanitizedUrl
    };
    
    // Show loading state
    $btn.html('<i class="fas fa-spinner fa-spin"></i> Saving...').prop('disabled', true);
    
    // Send job data to save endpoint
    $.ajax({
      url: '/job-discovery/save',
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': drupalSettings.csrf_token || ''
      },
      data: JSON.stringify(sanitizedJobData),
      dataType: 'json',
      success: function(response) {
        if (response.success) {
          $btn.html('<i class="fas fa-check"></i> Saved')
              .removeClass('btn-outline-secondary')
              .addClass('btn-success')
              .prop('disabled', true);
          
          Drupal.announce(response.message || 'Job saved to your dashboard', 'polite');
        } else {
          $btn.html('<i class="fas fa-bookmark"></i> Save Job')
              .prop('disabled', false);
          
          Drupal.announce('Error: ' + (response.error || 'Failed to save job'), 'assertive');
        }
      },
      error: function(xhr, status, error) {
        console.error('Save job error:', xhr.responseText);
        
        $btn.html('<i class="fas fa-bookmark"></i> Save Job')
            .prop('disabled', false);
        
        let errorMessage = 'Failed to save job';
        try {
          const errorResponse = JSON.parse(xhr.responseText);
          errorMessage = errorResponse.error || errorMessage;
        } catch (e) {
          errorMessage += ' (Server error: ' + xhr.status + ')';
        }
        
        Drupal.announce('Error: ' + errorMessage, 'assertive');
      }
    });
  });

})(jQuery, Drupal, drupalSettings, once);