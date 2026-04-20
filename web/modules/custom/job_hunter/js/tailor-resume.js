/**
 * @file
 * JavaScript for tailor resume functionality.
 */

(function ($, Drupal, once) {
  'use strict';

  function getEndpoint(settingKey, fallbackPath) {
    return (drupalSettings.jobHunterTailorResume && drupalSettings.jobHunterTailorResume[settingKey])
      ? drupalSettings.jobHunterTailorResume[settingKey]
      : fallbackPath;
  }

  /**
   * Helper function to add messages.
   */
  function addMessage(message, type) {
    type = type || 'status';
    var messageClass = 'messages--' + type;
    var messageHtml = '<div class="messages ' + messageClass + '">' + message + '</div>';
    
    // Try to add to messages region first
    if ($('.region-messages').length) {
      $('.region-messages').prepend(messageHtml);
    } else if ($('.messages').length) {
      // Add after existing messages
      $('.messages').first().after(messageHtml);
    } else {
      // Add to the top of the main content area
      if ($('main').length) {
        $('main').prepend(messageHtml);
      } else if ($('#content').length) {
        $('#content').prepend(messageHtml);
      } else {
        $('body').prepend(messageHtml);
      }
    }
  }

  /**
   * Update the status header UI.
   */
  function updateStatusHeader(status, label, description) {
    var statusIndicator = $('#status-indicator');
    var statusLabel = $('#status-label');
    var statusDescription = $('#status-description');
    var header = $('.tailor-resume-status-header');
    
    // Update data attribute
    statusIndicator.attr('data-status', status);
    
    // Update label and description
    statusLabel.text(label);
    statusDescription.text(description);
    
    // Update icon
    var icons = {
      'pending': '📝',
      'queued': '⏳',
      'processing': '🤖',
      'completed': '✅',
      'failed': '❌'
    };
    statusIndicator.find('.status-icon').text(icons[status] || '📝');
    
    // Update header class
    header.removeClass('status-header--status-pending status-header--status-queued status-header--status-processing status-header--status-completed status-header--status-failed');
    header.addClass('status-header--status-' + status);
    
    // Update progress steps
    var steps = {
      'pending': 1,
      'queued': 2,
      'processing': 3,
      'completed': 4,
      'failed': 0
    };
    var currentStep = steps[status] || 1;
    
    $('.progress-step').each(function(index) {
      var stepNum = index + 1;
      $(this).removeClass('step-active step-complete');
      if (currentStep >= stepNum) {
        $(this).addClass('step-active');
      }
      if (currentStep > stepNum) {
        $(this).addClass('step-complete');
      }
    });
    
    $('.progress-line').each(function(index) {
      var lineNum = index + 2;
      $(this).removeClass('line-active');
      if (currentStep >= lineNum) {
        $(this).addClass('line-active');
      }
    });
  }

  /**
   * Poll for tailoring status.
   */
  function startStatusPolling(jobId, button) {
    var pollInterval = setInterval(function() {
      $.ajax({
        url: getEndpoint('statusUrl', '/jobhunter/tailor-resume/status'),
        type: 'GET',
        data: { job_id: jobId },
        success: function(response) {
          $('#tailoring-status-text').text('🔄 ' + response.message);
          
          // Update status header based on response
          if (response.status === 'processing') {
            updateStatusHeader('processing', 'Tailoring in Progress', 'AI is generating your tailored resume...');
          } else if (response.status === 'queued') {
            updateStatusHeader('queued', 'In Queue', 'Waiting for AI processing...');
          }
          
          if (response.status === 'completed') {
            clearInterval(pollInterval);
            $('#tailoring-status').hide();
            updateStatusHeader('completed', 'Tailored & Ready', 'Your resume has been tailored for this position.');
            
            // Reload page to show results with proper controls
            addMessage('✅ Resume tailoring completed! Reloading...', 'status');
            setTimeout(function() {
              location.reload();
            }, 1500);
          } else if (response.status === 'failed') {
            clearInterval(pollInterval);
            $('#tailoring-status').hide();
            updateStatusHeader('failed', 'Tailoring Failed', response.message || 'Please try again.');
            addMessage('❌ Tailoring failed. Please try again.', 'error');
            if (button.length) {
              button.prop('disabled', false);
            }
          }
          // Keep polling if queued or processing
        },
        error: function() {
          // Don't stop polling on error, try again
          console.log('Status check failed, retrying...');
        }
      });
    }, 3000); // Poll every 3 seconds
    
    // Stop polling after 5 minutes
    setTimeout(function() {
      clearInterval(pollInterval);
      if ($('#tailoring-status').is(':visible')) {
        $('#tailoring-status').hide();
        addMessage('⚠️ Tailoring is taking longer than expected. Please check back later.', 'warning');
        if (button.length) {
          button.prop('disabled', false);
        }
      }
    }, 300000);
  }

  /**
   * Tailor Resume behavior.
   */
  Drupal.behaviors.tailorResume = {
    attach: function (context, settings) {
      
      // Auto-start polling if page loads with queued/processing status
      once('auto-poll-init', '.tailor-resume-status-header', context).forEach(function (element) {
        var statusIndicator = $('#status-indicator');
        var currentStatus = statusIndicator.data('status');
        
        if (currentStatus === 'queued' || currentStatus === 'processing') {
          var jobId = $('#generate-tailored-resume').data('job-id') || 
                      $('#regenerate-resume-btn').data('job-id');
          if (jobId) {
            console.log('Auto-starting status polling for job ' + jobId);
            $('#tailoring-status').show();
            $('#tailoring-status-text').text('🔄 Checking tailoring status...');
            startStatusPolling(jobId, $());
          }
        }
      });
      
      once('tailor-resume-init', '#generate-tailored-resume', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          
          const button = $(this);
          const jobId = button.data('job-id');
          
          // Show loading status with spinner
          $('#tailoring-status').removeClass('hidden').show();
          $('#tailoring-status-text').html('<span class="spinner"></span> 🔄 Queuing resume tailoring...');
          $('#tailoring-results').hide();
          button.prop('disabled', true);
          
          // Update status header
          updateStatusHeader('queued', 'In Queue', 'Your tailoring request is queued...');
          
          // Queue the tailoring job
          $.ajax({
            url: getEndpoint('ajaxUrl', '/jobhunter/tailor-resume/ajax'),
            type: 'POST',
            headers: { 'X-CSRF-Token': drupalSettings.csrf_token || '' },
            data: {
              job_id: jobId
            },
            success: function(response) {
              if (response.success) {
                if (response.status === 'completed' && response.tailored_resume) {
                  // Already completed - show results
                  $('#tailoring-status').hide();
                  displayTailoredResume(response.tailored_resume);
                  addMessage(response.message, 'status');
                  button.prop('disabled', false);
                  updateStatusHeader('completed', 'Tailored & Ready', 'Your resume has been tailored for this position.');
                } else {
                  // Queued or processing - start polling
                  $('#tailoring-status-text').text('🔄 ' + response.message);
                  addMessage(response.message, 'status');
                  startStatusPolling(jobId, button);
                }
              } else {
                $('#tailoring-status').hide();
                addMessage('Error: ' + (response.error || 'Unknown error occurred'), 'error');
                button.prop('disabled', false);
                updateStatusHeader('failed', 'Tailoring Failed', response.error || 'Unknown error occurred');
              }
            },
            error: function(xhr, status, error) {
              $('#tailoring-status').hide();
              button.prop('disabled', false);
              
              let errorMessage = 'Failed to queue tailoring. Please try again.';
              if (xhr.responseJSON && xhr.responseJSON.error) {
                errorMessage = xhr.responseJSON.error;
              }
              addMessage(errorMessage, 'error');
              updateStatusHeader('failed', 'Tailoring Failed', errorMessage);
            }
          });
        });
      });

      // Regenerate button - force new generation (both old and new button IDs)
      once('regenerate-resume-init', '#regenerate-resume, #regenerate-resume-btn', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          
          const button = $(this);
          const jobId = button.data('job-id') || $('[data-job-id]').first().data('job-id');
          
          if (!confirm('This will regenerate your tailored resume. The previous version will be replaced. Continue?')) {
            return;
          }
          
          // Show loading status
          $('#tailoring-status').show();
          $('#tailoring-status-text').text('🔄 Queuing resume regeneration...');
          button.prop('disabled', true);
          
          // Update status header
          updateStatusHeader('queued', 'In Queue', 'Your regeneration request is queued...');
          
          // Queue the tailoring job with force flag
          $.ajax({
            url: getEndpoint('ajaxUrl', '/jobhunter/tailor-resume/ajax'),
            type: 'POST',
            headers: { 'X-CSRF-Token': drupalSettings.csrf_token || '' },
            data: {
              job_id: jobId,
              force: 1
            },
            success: function(response) {
              if (response.success) {
                $('#tailoring-status-text').text('🔄 ' + response.message);
                addMessage(response.message, 'status');
                startStatusPolling(jobId, button);
              } else {
                $('#tailoring-status').hide();
                addMessage('Error: ' + (response.error || 'Unknown error occurred'), 'error');
                button.prop('disabled', false);
                updateStatusHeader('failed', 'Tailoring Failed', response.error || 'Unknown error occurred');
              }
            },
            error: function(xhr, status, error) {
              $('#tailoring-status').hide();
              button.prop('disabled', false);
              
              let errorMessage = 'Failed to queue regeneration. Please try again.';
              if (xhr.responseJSON && xhr.responseJSON.error) {
                errorMessage = xhr.responseJSON.error;
              }
              addMessage(errorMessage, 'error');
              updateStatusHeader('failed', 'Tailoring Failed', errorMessage);
            }
          });
        });
      });

      // Display tailored resume in results area
      function displayTailoredResume(tailored) {
        var html = '<div class="tailored-resume-result">';
        html += '<h3>✅ Tailored Resume Generated</h3>';
        
        if (tailored.tailoring_metadata) {
          html += '<div class="tailoring-metadata">';
          if (tailored.tailoring_metadata.match_score) {
            html += '<p><strong>Match Score:</strong> ' + tailored.tailoring_metadata.match_score + '%</p>';
          }
          if (tailored.tailoring_metadata.emphasized_skills && tailored.tailoring_metadata.emphasized_skills.length) {
            html += '<p><strong>Emphasized Skills:</strong> ' + tailored.tailoring_metadata.emphasized_skills.join(', ') + '</p>';
          }
          if (tailored.tailoring_metadata.guidance && tailored.tailoring_metadata.guidance.length) {
            html += '<p><strong>Tailoring Guidance:</strong></p><ul>';
            tailored.tailoring_metadata.guidance.forEach(function(g) {
              html += '<li>' + g + '</li>';
            });
            html += '</ul>';
          }
          html += '</div>';
        }
        
        html += '<p>Refresh the page to see the full tailored resume and PDF generation options.</p>';
        html += '</div>';
        
        $('#tailoring-results').html(html).show();
      }
      
      // Handle resume action buttons
      once('resume-actions-init', '.resume-actions button', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          
          const action = $(this).find('i').hasClass('fa-download') ? 'download' :
                        $(this).find('i').hasClass('fa-edit') ? 'edit' : 'save';
          
          switch(action) {
            case 'download':
              handleDownloadResume();
              break;
            case 'edit':
              handleEditResume();
              break;
            case 'save':
              handleSaveResume();
              break;
          }
        });
      });
    }
  };
  
  /**
   * Generate mock tailored resume content.
   */
  function generateMockTailoredResume(jobId) {
    return `
      <div class="resume-header">
        <h2>John Doe</h2>
        <p>Data Science Professional | Analytics Expert</p>
        <p>Email: john.doe@email.com | Phone: (555) 123-4567</p>
      </div>
      
      <div class="resume-section">
        <h3>Professional Summary</h3>
        <p><strong>Tailored for this position:</strong> Experienced data science leader with 8+ years in oncology analytics and GPO data analysis. Proven track record in developing predictive models and driving data-driven decisions in healthcare settings.</p>
      </div>
      
      <div class="resume-section">
        <h3>Key Skills (Matched to Job Requirements)</h3>
        <ul>
          <li><strong>Data Science & Analytics</strong> - Python, R, SQL, Machine Learning</li>
          <li><strong>Healthcare Data</strong> - Oncology data analysis, Clinical datasets</li>
          <li><strong>Leadership</strong> - Team management, Cross-functional collaboration</li>
          <li><strong>GPO Analytics</strong> - Group purchasing organization data modeling</li>
        </ul>
      </div>
      
      <div class="resume-section">
        <h3>Professional Experience</h3>
        <div class="job-entry">
          <h4>Senior Data Scientist - Healthcare Analytics Co.</h4>
          <p><em>2020 - Present</em></p>
          <ul>
            <li><strong>Highlighted:</strong> Led oncology data analysis projects resulting in 25% improvement in treatment outcome predictions</li>
            <li>Developed machine learning models for GPO purchasing pattern analysis</li>
            <li>Managed team of 5 data analysts and scientists</li>
          </ul>
        </div>
      </div>
      
      <div class="resume-section">
        <h3>Education</h3>
        <p><strong>M.S. Data Science</strong> - Stanford University</p>
        <p><strong>B.S. Statistics</strong> - University of California, Berkeley</p>
      </div>
    `;
  }
  
  /**
   * Handle resume download.
   */
  function handleDownloadResume() {
    // Create a simple text file download
    const resumeContent = $('#resume-content').text();
    const blob = new Blob([resumeContent], { type: 'text/plain' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'tailored-resume.txt';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
    
    // Show success message
    addMessage('Resume downloaded successfully!', 'status');
  }
  
  /**
   * Handle resume editing.
   */
  function handleEditResume() {
    const resumeContent = $('#resume-content');
    if (resumeContent.attr('contenteditable') === 'true') {
      // Stop editing
      resumeContent.attr('contenteditable', 'false');
      resumeContent.removeClass('editing');
      $(this).html('<i class="fas fa-edit"></i> Edit Resume');
    } else {
      // Start editing
      resumeContent.attr('contenteditable', 'true');
      resumeContent.addClass('editing');
      resumeContent.focus();
      $(this).html('<i class="fas fa-check"></i> Done Editing');
    }
  }
  
  /**
   * Handle resume saving.
   */
  function handleSaveResume() {
    // Simulate saving
    const button = $(this);
    const originalText = button.html();
    
    button.html('<i class="fas fa-spinner fa-spin"></i> Saving...');
    button.prop('disabled', true);
    
    setTimeout(function() {
      button.html(originalText);
      button.prop('disabled', false);
      // Show success message
      addMessage('Resume changes saved successfully!', 'status');
    }, 1500);
  }

  /**
   * Delete PDF behavior.
   */
  Drupal.behaviors.deletePdf = {
    attach: function (context, settings) {
      once('delete-pdf-init', '.pdf-delete-btn', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          
          const button = $(this);
          const pdfId = button.data('pdf-id');
          const pdfItem = button.closest('.pdf-item');
          const filename = pdfItem.find('.pdf-filename').text();
          
          if (!confirm('Are you sure you want to delete "' + filename + '"?')) {
            return;
          }
          
          button.prop('disabled', true).text('...');
          
          $.ajax({
            url: '/jobhunter/resume/pdf/' + pdfId + '/delete',
            type: 'POST',
            dataType: 'json',
            success: function(response) {
              if (response.success) {
                pdfItem.fadeOut(300, function() {
                  $(this).remove();
                  
                  // Check if any PDFs left
                  if ($('.pdf-item').length === 0) {
                    $('.pdf-history').replaceWith(
                      '<div class="pdf-info pdf-info--empty"><p>No PDF generated yet. Click "Generate PDF" to create a downloadable resume.</p></div>'
                    );
                    $('#download-pdf-btn').addClass('disabled').attr('aria-disabled', 'true');
                  } else {
                    // Update the first item to be "latest"
                    $('.pdf-item').first().addClass('pdf-item--latest');
                    if (!$('.pdf-item').first().find('.pdf-badge').length) {
                      $('.pdf-item').first().prepend('<span class="pdf-badge">Latest</span>');
                    }
                  }
                  
                  // Update count
                  var count = $('.pdf-item').length;
                  if (count > 0) {
                    $('.pdf-history h4').text('Generated PDFs (' + count + ')');
                  }
                });
              } else {
                alert('Error: ' + response.message);
                button.prop('disabled', false).text('🗑️');
              }
            },
            error: function(xhr) {
              let errorMsg = 'Failed to delete PDF.';
              if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMsg = xhr.responseJSON.message;
              }
              alert('Error: ' + errorMsg);
              button.prop('disabled', false).text('🗑️');
            }
          });
        });
      });
    }
  };

  /**
   * Generate PDF behavior.
   */
  Drupal.behaviors.generatePdf = {
    attach: function (context, settings) {
      once('generate-pdf-init', '#generate-pdf-btn', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          
          const button = $(this);
          const statusDiv = $('#pdf-generation-status');
          
          // Extract job_id from current URL
          const pathMatch = window.location.pathname.match(/\/jobhunter\/tailor-resume\/(\d+)/);
          if (!pathMatch) {
            statusDiv.removeClass('success loading').addClass('error')
              .text('Unable to determine job ID.')
              .show();
            return;
          }
          const jobId = pathMatch[1];
          
          // Show loading status
          button.prop('disabled', true);
          button.html('<span class="button-icon">⏳</span> Generating...');
          statusDiv.removeClass('success error').addClass('loading')
            .text('Generating PDF...')
            .show();
          
          // Call the generate PDF endpoint
          $.ajax({
            url: '/jobhunter/jobs/' + jobId + '/resume/generate',
            type: 'POST',
            dataType: 'json',
            success: function(response) {
              button.html('<span class="button-icon">⚙️</span> Generate PDF');
              button.prop('disabled', false);
              
              if (response.success) {
                statusDiv.removeClass('loading error').addClass('success')
                  .html('PDF generated successfully: <strong>' + response.filename + '</strong>')
                  .show();
                
                // Enable download button
                $('#download-pdf-btn').removeClass('disabled').removeAttr('aria-disabled');
                
                // Reload page to show updated PDF info
                setTimeout(function() {
                  window.location.reload();
                }, 1500);
              } else {
                statusDiv.removeClass('loading success').addClass('error')
                  .text('Error: ' + response.message)
                  .show();
              }
            },
            error: function(xhr) {
              button.html('<span class="button-icon">⚙️</span> Generate PDF');
              button.prop('disabled', false);
              
              let errorMsg = 'Failed to generate PDF.';
              if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMsg = xhr.responseJSON.message;
              }
              
              statusDiv.removeClass('loading success').addClass('error')
                .text('Error: ' + errorMsg)
                .show();
            }
          });
        });
      });
    }
  };

  /**
   * Skills Gap Analysis - Add to Profile functionality.
   */
  Drupal.behaviors.skillsGapActions = {
    attach: function (context, settings) {
      
      // Handle individual "Add to Profile" button clicks
      once('add-skill-btn-init', '.add-skill-btn', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          
          const btn = $(this);
          const skill = btn.data('skill');
          const category = btn.data('category');
          const skillItem = btn.closest('.skill-gap-item');
          
          // Disable button and show loading
          btn.prop('disabled', true).text('Adding...');
          
          $.ajax({
            url: getEndpoint('addSkillUrl', '/jobhunter/profile/add-skill'),
            type: 'POST',
            headers: { 'X-CSRF-Token': drupalSettings.csrf_token || '' },
            dataType: 'json',
            data: {
              skill: skill,
              category: category
            },
            success: function(response) {
              if (response.success) {
                // Mark skill as added
                skillItem.addClass('skill-added');
                btn.removeClass('add-skill-btn').addClass('skill-added-indicator')
                   .html('✓ Added').prop('disabled', true);
                
                // Update counter if present
                updateSkillsGapCount();
              } else {
                btn.prop('disabled', false).text('+ Add to Profile');
                alert('Error: ' + (response.message || 'Failed to add skill'));
              }
            },
            error: function(xhr) {
              btn.prop('disabled', false).text('+ Add to Profile');
              let errorMsg = 'Failed to add skill to profile.';
              if (xhr.responseJSON && xhr.responseJSON.message) {
                errorMsg = xhr.responseJSON.message;
              }
              alert('Error: ' + errorMsg);
            }
          });
        });
      });
      
      // Handle "Add All Must-Have Skills" button
      once('add-all-must-have-init', '#add-all-must-have-skills', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          
          const btn = $(this);
          const mustHaveSkills = $('.skills-gap-group:first .add-skill-btn:not(.skill-added-indicator)');
          
          if (mustHaveSkills.length === 0) {
            alert('All must-have skills have already been added.');
            return;
          }
          
          btn.prop('disabled', true).text('Adding ' + mustHaveSkills.length + ' skills...');
          
          addSkillsSequentially(mustHaveSkills, 0, function() {
            btn.text('✓ All Must-Have Skills Added').addClass('btn-success');
            updateSkillsGapCount();
          });
        });
      });
      
      // Handle "Add All Missing Skills" button
      once('add-all-skills-init', '#add-all-skills', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          
          const btn = $(this);
          const allSkills = $('.add-skill-btn:not(.skill-added-indicator)');
          
          if (allSkills.length === 0) {
            alert('All missing skills have already been added.');
            return;
          }
          
          btn.prop('disabled', true).text('Adding ' + allSkills.length + ' skills...');
          
          addSkillsSequentially(allSkills, 0, function() {
            btn.text('✓ All Skills Added').addClass('btn-success');
            $('#add-all-must-have-skills').text('✓ All Must-Have Skills Added').addClass('btn-success').prop('disabled', true);
            updateSkillsGapCount();
          });
        });
      });

      // Handle "Refresh Skills Gap" button - re-calculates after adding skills
      once('refresh-skills-gap-init', '#refresh-skills-gap', context).forEach(function (element) {
        $(element).on('click', function(e) {
          e.preventDefault();
          
          const btn = $(this);
          const jobId = btn.data('job-id');
          
          btn.prop('disabled', true).html('🔄 Refreshing...');
          
          $.ajax({
            url: getEndpoint('refreshSkillsGapUrl', '/jobhunter/tailor-resume/refresh-skills-gap'),
            type: 'POST',
            headers: { 'X-CSRF-Token': drupalSettings.csrf_token || '' },
            dataType: 'json',
            data: { job_id: jobId },
            success: function(response) {
              if (response.success) {
                // Rebuild the skills gap section with fresh data
                rebuildSkillsGapSection(response.skills_gap);
                btn.prop('disabled', false).html('🔄 ' + Drupal.t('Refresh Skills Gap'));
                
                // Show success message
                const msg = response.must_have_count + ' must-have and ' + response.nice_to_have_count + ' nice-to-have skills missing';
                if (response.must_have_count === 0 && response.nice_to_have_count === 0) {
                  addMessage('✅ All skills now match! Your profile covers all job requirements.', 'status');
                } else {
                  addMessage('🔄 Skills gap refreshed: ' + msg, 'status');
                }
              } else {
                btn.prop('disabled', false).html('🔄 ' + Drupal.t('Refresh Skills Gap'));
                alert('Error: ' + (response.message || 'Failed to refresh'));
              }
            },
            error: function(xhr) {
              btn.prop('disabled', false).html('🔄 ' + Drupal.t('Refresh Skills Gap'));
              alert('Error refreshing skills gap');
            }
          });
        });
      });
    }
  };

  /**
   * Rebuild the skills gap section with fresh data from server.
   */
  function rebuildSkillsGapSection(skillsGap) {
    const mustHave = skillsGap.must_have || [];
    const niceToHave = skillsGap.nice_to_have || [];
    
    // If no gaps remain, show success message
    if (mustHave.length === 0 && niceToHave.length === 0) {
      $('.skills-gap-section').html(
        '<div class="card"><div class="card-body">' +
        '<div class="alert alert-success">' +
        '✅ <strong>' + Drupal.t('Great match!') + '</strong> ' + 
        Drupal.t('Your profile now contains all the required skills for this position.') +
        '</div></div></div>'
      );
      return;
    }
    
    // Rebuild must-have section
    const mustHaveGroup = $('.skills-gap-group:first');
    if (mustHave.length > 0) {
      mustHaveGroup.find('h4').html('🔴 ' + Drupal.t('Must Have Skills (Missing)'));
      let mustHaveHtml = '';
      mustHave.forEach(function(skill) {
        mustHaveHtml += '<div class="skill-gap-item" data-skill="' + skill.skill + '" data-category="' + skill.category + '">' +
          '<span class="skill-gap-name">' + skill.skill + '</span>' +
          '<span class="skill-gap-category">(' + skill.category + ')</span>' +
          '<button type="button" class="button button--small add-skill-btn" data-skill="' + skill.skill + '" data-category="' + skill.category + '">' +
          '+ ' + Drupal.t('Add to Profile') + '</button>' +
          '<span class="skill-added-indicator" style="display: none;">✅ ' + Drupal.t('Added!') + '</span>' +
          '</div>';
      });
      mustHaveGroup.find('.skills-gap-list').html(mustHaveHtml);
      mustHaveGroup.show();
    } else {
      mustHaveGroup.find('h4').html('✅ ' + Drupal.t('Must Have Skills (All Added)'));
      mustHaveGroup.find('.skills-gap-list').html('');
    }
    
    // Rebuild nice-to-have section
    const niceToHaveGroup = $('.skills-gap-group:last');
    if (niceToHave.length > 0) {
      niceToHaveGroup.find('h4').html('🟡 ' + Drupal.t('Nice to Have Skills (Missing)'));
      let niceToHaveHtml = '';
      niceToHave.forEach(function(skill) {
        niceToHaveHtml += '<div class="skill-gap-item" data-skill="' + skill.skill + '" data-category="' + skill.category + '">' +
          '<span class="skill-gap-name">' + skill.skill + '</span>' +
          '<span class="skill-gap-category">(' + skill.category + ')</span>' +
          '<button type="button" class="button button--small add-skill-btn" data-skill="' + skill.skill + '" data-category="' + skill.category + '">' +
          '+ ' + Drupal.t('Add to Profile') + '</button>' +
          '<span class="skill-added-indicator" style="display: none;">✅ ' + Drupal.t('Added!') + '</span>' +
          '</div>';
      });
      niceToHaveGroup.find('.skills-gap-list').html(niceToHaveHtml);
      niceToHaveGroup.show();
    } else {
      niceToHaveGroup.find('h4').html('✅ ' + Drupal.t('Nice to Have Skills (All Added)'));
      niceToHaveGroup.find('.skills-gap-list').html('');
    }
    
    // Re-enable bulk buttons
    $('#add-all-must-have-skills').prop('disabled', false).removeClass('btn-success').text(Drupal.t('Add All Must-Have Skills'));
    $('#add-all-skills').prop('disabled', false).removeClass('btn-success').text(Drupal.t('Add All Missing Skills'));
    
    // Rebind click handlers for new buttons
    Drupal.behaviors.tailorResume.attach(document, drupalSettings);
  }
  
  /**
   * Add skills one at a time to avoid overwhelming the server.
   */
  function addSkillsSequentially(skillButtons, index, callback) {
    if (index >= skillButtons.length) {
      if (callback) callback();
      return;
    }
    
    const btn = $(skillButtons[index]);
    const skill = btn.data('skill');
    const category = btn.data('category');
    const skillItem = btn.closest('.skill-gap-item');
    
    $.ajax({
      url: getEndpoint('addSkillUrl', '/jobhunter/profile/add-skill'),
      type: 'POST',
      headers: { 'X-CSRF-Token': drupalSettings.csrf_token || '' },
      dataType: 'json',
      data: {
        skill: skill,
        category: category
      },
      success: function(response) {
        if (response.success) {
          skillItem.addClass('skill-added');
          btn.removeClass('add-skill-btn').addClass('skill-added-indicator')
             .html('✓ Added').prop('disabled', true);
        }
        // Continue to next skill even if one fails
        addSkillsSequentially(skillButtons, index + 1, callback);
      },
      error: function() {
        // Continue to next skill even on error
        addSkillsSequentially(skillButtons, index + 1, callback);
      }
    });
  }
  
  /**
   * Update the skills gap count display.
   */
  function updateSkillsGapCount() {
    const remainingMustHave = $('.skills-gap-group:first .add-skill-btn:not(.skill-added-indicator)').length;
    const remainingNiceToHave = $('.skills-gap-group:last .add-skill-btn:not(.skill-added-indicator)').length;
    
    // Update section title counts if they exist
    const mustHaveHeader = $('.skills-gap-group:first h4');
    if (mustHaveHeader.length && remainingMustHave === 0) {
      mustHaveHeader.html('✅ ' + Drupal.t('Must Have Skills (All Added)'));
    }
    
    const niceToHaveHeader = $('.skills-gap-group:last h4');
    if (niceToHaveHeader.length && remainingNiceToHave === 0) {
      niceToHaveHeader.html('✅ ' + Drupal.t('Nice to Have Skills (All Added)'));
    }
    
    // Hide the entire section if all skills added
    if (remainingMustHave === 0 && remainingNiceToHave === 0) {
      $('.skills-gap-section .card-header h3').html('✅ ' + Drupal.t('Skills Gap Analysis - Complete'));
      $('.skills-gap-actions').hide();
    }
  }

})(jQuery, Drupal, once);