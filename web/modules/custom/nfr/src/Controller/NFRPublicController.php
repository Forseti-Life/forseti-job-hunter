<?php

declare(strict_types=1);

namespace Drupal\nfr\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for NFR public pages.
 */
class NFRPublicController extends ControllerBase {

  /**
   * Home/Landing page.
   *
   * @return array
   *   Render array.
   */
  public function home(): array {
    $current_user = $this->currentUser();
    $user_storage = $this->entityTypeManager()->getStorage('user');
    
    // Build role-specific welcome links
    $role_links = [];
    
    if ($current_user->isAuthenticated()) {
      $user = $user_storage->load($current_user->id());
      $roles = $user->getRoles(TRUE); // Exclude 'authenticated'
      
      // Determine primary role and redirect
      if (in_array('nfr_administrator', $roles)) {
        $role_links['primary'] = [
          'title' => 'Administrator Dashboard',
          'url' => '/admin/nfr',
          'description' => 'Manage participants, monitor data quality, and oversee registry operations.',
        ];
      }
      elseif (in_array('nfr_researcher', $roles)) {
        $role_links['primary'] = [
          'title' => 'Research Dashboard',
          'url' => '/admin/nfr/reports',
          'description' => 'Access research reports and export de-identified data.',
        ];
      }
      elseif (in_array('fire_dept_admin', $roles)) {
        $role_links['primary'] = [
          'title' => 'Department Dashboard',
          'url' => '/nfr/firefighters',
          'description' => 'View your department\'s participation and enrollment status.',
        ];
      }
      else {
        // Firefighter or authenticated user
        $role_links['primary'] = [
          'title' => 'My Dashboard',
          'url' => '/nfr/my-dashboard',
          'description' => 'View your enrollment status and manage your profile.',
        ];
      }
      
      // Common links for all authenticated users
      $role_links['enrollment'] = [
        'title' => 'Start Enrollment',
        'url' => '/nfr/welcome',
        'description' => 'Begin or continue your NFR enrollment process.',
      ];
    }

    return [
      '#theme' => 'nfr_home_page',
      '#authenticated' => $current_user->isAuthenticated(),
      '#role_links' => $role_links,
      '#attached' => [
        'library' => ['nfr/home'],
      ],
    ];
  }

  /**
   * About NFR page.
   *
   * @return array
   *   Render array.
   */
  public function about(): array {
    return [
      '#theme' => 'nfr_public_page',
      '#page_id' => 'about',
      '#content' => [
        '#markup' => '<h2>About the National Firefighter Registry</h2><p>Placeholder content for About page.</p>',
      ],
    ];
  }

  /**
   * How It Works page.
   *
   * @return array
   *   Render array.
   */
  public function howItWorks(): array {
    return [
      '#theme' => 'nfr_public_page',
      '#page_id' => 'how-it-works',
      '#content' => [
        '#markup' => '<h2>How It Works</h2><p>Placeholder content for How It Works page.</p>',
      ],
    ];
  }

  /**
   * Why Participate page.
   *
   * @return array
   *   Render array.
   */
  public function whyParticipate(): array {
    return [
      '#theme' => 'nfr_public_page',
      '#page_id' => 'why-participate',
      '#content' => [
        '#markup' => '<h2>Why Participate</h2><p>Placeholder content for Why Participate page.</p>',
      ],
    ];
  }

  /**
   * FAQ page.
   *
   * @return array
   *   Render array.
   */
  public function faq(): array {
    $html = '<div class="nfr-faq-page">';
    $html .= '<div class="container my-5">';
    
    // Header
    $html .= '<div class="row mb-5">';
    $html .= '<div class="col-12 text-center">';
    $html .= '<h1 class="display-4 mb-3">Frequently Asked Questions</h1>';
    $html .= '<p class="lead">Learn more about the National Firefighter Registry</p>';
    $html .= '</div></div>';
    
    // FAQ Items
    $html .= '<div class="row justify-content-center">';
    $html .= '<div class="col-lg-10">';
    
    // FAQ 1
    $html .= '<div class="faq-item mb-4">';
    $html .= '<h3 class="h4 mb-3">What is the NFR?</h3>';
    $html .= '<p>The National Firefighter Registry, or NFR, will be a large database of health and occupational information on firefighters that can be used to analyze and track cancer and identify occupational risk factors for cancer to help the public safety community, scientists, and public health and medical professionals find better ways to protect those who protect our communities and environment. With voluntary participation from firefighters, the NFR will include information about firefighter characteristics, work assignments and exposure, and relevant health details to monitor, track and improve our knowledge about cancer risks for firefighters.</p>';
    $html .= '</div>';
    
    // FAQ 2
    $html .= '<div class="faq-item mb-4">';
    $html .= '<h3 class="h4 mb-3">Who can enroll in the NFR?</h3>';
    $html .= '<p>We encourage anyone who is or ever has been a firefighter in the United States to join the NFR (provided they are 18 years of age or older). This includes all active and former firefighters, such as volunteer, paid-on-call, part time, seasonal, and career firefighters. It also includes wildland firefighters, fire-cause investigators, fire instructors, industrial firefighters, airport-rescue firefighters, and other subspecialties of the fire service. There is no minimum service time required to register in the NFR. The more firefighters who register, the more we can learn about the cancer risk in the fire service.</p>';
    $html .= '</div>';
    
    // FAQ 3
    $html .= '<div class="faq-item mb-4">';
    $html .= '<h3 class="h4 mb-3">Is a cancer diagnosis required to enroll?</h3>';
    $html .= '<p>No. In fact, firefighters without a cancer diagnosis are just as critical to making the NFR a success as those who have received a cancer diagnosis. NIOSH would like all firefighters to be part of the NFR, not just those with cancer or other illnesses.</p>';
    $html .= '</div>';
    
    // FAQ 4
    $html .= '<div class="faq-item mb-4">';
    $html .= '<h3 class="h4 mb-3">Do firefighters have to join the NFR?</h3>';
    $html .= '<p>No. Being part of the NFR is completely voluntary, and no one can make a firefighter join. NIOSH needs your consent for you to be part of the NFR. However, participation is strongly encouraged because it will help improve the health and safety of the firefighter community today and in the future. The NFR is your opportunity to leave a legacy for those who follow you.</p>';
    $html .= '</div>';
    
    // FAQ 5
    $html .= '<div class="faq-item mb-4">';
    $html .= '<h3 class="h4 mb-3">Do NFR participants need to contact NIOSH if they are diagnosed with cancer?</h3>';
    $html .= '<p>No. NIOSH will be able to track information related to cancer by linking information on individual firefighters enrolled in the NFR with state cancer registries. Providing the last 4 digits of your social security number will ensure that these linkages can be made accurately. Firefighters should consult with their doctor if they have any concerns about their health.</p>';
    $html .= '</div>';
    
    // FAQ 6
    $html .= '<div class="faq-item mb-4">';
    $html .= '<h3 class="h4 mb-3">How will we protect firefighter data?</h3>';
    $html .= '<p>Firefighter data is stored securely with multiple layers of encryption and is only accessible to NIOSH-approved staff with necessary training and security clearance. Firefighters\' identifying information is protected under the highest level of government protection (known as an Assurance of Confidentiality), and firefighters can be sure their information will never be given to fire departments, insurance companies, or anyone else not involved with the NFR program.</p>';
    $html .= '</div>';
    
    $html .= '</div></div>'; // col, row
    
    // Call to Action
    $html .= '<div class="row justify-content-center mt-5">';
    $html .= '<div class="col-lg-10">';
    $html .= '<div class="card bg-light border-0">';
    $html .= '<div class="card-body text-center p-5">';
    $html .= '<h3 class="mb-3">Still Have Questions?</h3>';
    $html .= '<p class="mb-4">Contact the NFR Help Desk for assistance with enrollment or technical support.</p>';
    $html .= '<a href="/nfr/contact" class="btn btn-primary btn-lg">Contact Us</a>';
    $html .= '</div></div>';
    $html .= '</div></div>';
    
    // Additional Resources
    $html .= '<div class="row justify-content-center mt-4">';
    $html .= '<div class="col-lg-10">';
    $html .= '<div class="card border-0">';
    $html .= '<div class="card-body text-center p-4">';
    $html .= '<h4 class="mb-3">Additional Resources</h4>';
    $html .= '<p class="mb-3">For more information about firefighter health and safety, visit the CDC NIOSH Firefighter Resources page.</p>';
    $html .= '<a href="https://www.cdc.gov/niosh/firefighters/resources/index.html" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary">';
    $html .= 'CDC NIOSH Firefighter Resources <i class="bi bi-box-arrow-up-right ms-1"></i>';
    $html .= '</a>';
    $html .= '</div></div>';
    $html .= '</div></div>';
    
    $html .= '</div></div>'; // container, nfr-faq-page
    
    return [
      '#markup' => $html,
      '#attached' => [
        'library' => ['nfr/nfr-styles'],
      ],
    ];
  }

  /**
   * Contact Us page.
   *
   * @return array
   *   Render array.
   */
  public function contact(): array {
    $html = '<div class="nfr-contact-page">';
    $html .= '<div class="container my-5">';
    
    // Header
    $html .= '<div class="row mb-4">';
    $html .= '<div class="col-12 text-center">';
    $html .= '<h1 class="display-4 mb-3">Contact the NFR Help Desk</h1>';
    $html .= '<p class="lead">We\'re here to assist you with enrollment, questions, and technical support.</p>';
    $html .= '</div></div>';
    
    // Contact Information Card
    $html .= '<div class="row justify-content-center">';
    $html .= '<div class="col-lg-8">';
    $html .= '<div class="card shadow-sm">';
    $html .= '<div class="card-body p-5">';
    
    // Phone
    $html .= '<div class="contact-method mb-4">';
    $html .= '<div class="d-flex align-items-center mb-2">';
    $html .= '<svg class="me-3" width="32" height="32" fill="currentColor" viewBox="0 0 16 16"><path d="M3.654 1.328a.678.678 0 0 0-1.015-.063L1.605 2.3c-.483.484-.661 1.169-.45 1.77a17.568 17.568 0 0 0 4.168 6.608 17.569 17.569 0 0 0 6.608 4.168c.601.211 1.286.033 1.77-.45l1.034-1.034a.678.678 0 0 0-.063-1.015l-2.307-1.794a.678.678 0 0 0-.58-.122l-2.19.547a1.745 1.745 0 0 1-1.657-.459L5.482 8.062a1.745 1.745 0 0 1-.46-1.657l.548-2.19a.678.678 0 0 0-.122-.58L3.654 1.328z"/></svg>';
    $html .= '<h3 class="mb-0">Phone</h3>';
    $html .= '</div>';
    $html .= '<p class="h4 text-primary mb-0"><a href="tel:833-489-1298" class="text-decoration-none">833-489-1298</a></p>';
    $html .= '<p class="text-muted small mb-0">Toll-free</p>';
    $html .= '</div>';
    
    // Email
    $html .= '<div class="contact-method mb-4">';
    $html .= '<div class="d-flex align-items-center mb-2">';
    $html .= '<svg class="me-3" width="32" height="32" fill="currentColor" viewBox="0 0 16 16"><path d="M.05 3.555A2 2 0 0 1 2 2h12a2 2 0 0 1 1.95 1.555L8 8.414.05 3.555zM0 4.697v7.104l5.803-3.558L0 4.697zM6.761 8.83l-6.57 4.027A2 2 0 0 0 2 14h12a2 2 0 0 0 1.808-1.144l-6.57-4.027L8 9.586l-1.239-.757zm3.436-.586L16 11.801V4.697l-5.803 3.546z"/></svg>';
    $html .= '<h3 class="mb-0">Email</h3>';
    $html .= '</div>';
    $html .= '<p class="h4 text-primary mb-0"><a href="mailto:NFRegistry@cdc.gov" class="text-decoration-none">NFRegistry@cdc.gov</a></p>';
    $html .= '</div>';
    
    // Hours
    $html .= '<div class="contact-method">';
    $html .= '<div class="d-flex align-items-center mb-2">';
    $html .= '<svg class="me-3" width="32" height="32" fill="currentColor" viewBox="0 0 16 16"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z"/></svg>';
    $html .= '<h3 class="mb-0">Hours of Operation</h3>';
    $html .= '</div>';
    $html .= '<p class="mb-1"><strong>Monday - Friday</strong></p>';
    $html .= '<p class="mb-0">8:30 AM - 5:00 PM EST</p>';
    $html .= '</div>';
    
    $html .= '</div></div>'; // card-body, card
    $html .= '</div></div>'; // col, row
    
    // Additional Information
    $html .= '<div class="row justify-content-center mt-4">';
    $html .= '<div class="col-lg-8">';
    $html .= '<div class="alert alert-info">';
    $html .= '<h5>Before You Contact Us</h5>';
    $html .= '<ul class="mb-0">';
    $html .= '<li>Check our <a href="/nfr/faq">FAQ page</a> for answers to common questions</li>';
    $html .= '<li>Have your Participant ID ready if you\'re already enrolled</li>';
    $html .= '<li>For technical issues, please describe the problem in detail</li>';
    $html .= '</ul>';
    $html .= '</div>';
    $html .= '</div></div>';
    
    $html .= '</div></div>'; // container, nfr-contact-page
    
    return [
      '#markup' => $html,
      '#attached' => [
        'library' => ['nfr/nfr-styles'],
      ],
    ];
  }

  /**
   * Public Data/Statistics page.
   *
   * @return array
   *   Render array.
   */
  public function publicData(): array {
    $connection = \Drupal::database();
    
    // Get registered firefighter counts by state
    $state_counts = [];
    try {
      $query = $connection->select('nfr_work_history', 'wh');
      $query->addField('wh', 'department_state', 'state');
      $query->addExpression('COUNT(DISTINCT wh.uid)', 'count');
      $query->groupBy('wh.department_state');
      $results = $query->execute()->fetchAllKeyed();
      
      foreach ($results as $state => $count) {
        if (!empty($state)) {
          $state_counts[$state] = (int) $count;
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('nfr')->error('Error fetching state counts: @message', ['@message' => $e->getMessage()]);
    }
    
    // Get overall statistics
    $stats = $this->getPublicStatistics($connection);
    
    // Get demographic and cancer data for charts
    $demographic_data = $this->getDemographicData($connection);
    $cancer_data = $this->getCancerData($connection);
    
    // Build the dashboard HTML
    $html = $this->buildPublicDataDashboard($state_counts, $stats);
    
    return [
      '#theme' => 'nfr_public_page',
      '#page_id' => 'public-data',
      '#content' => [
        '#type' => 'markup',
        '#markup' => $html,
        '#allowed_tags' => ['canvas', 'div', 'h1', 'h2', 'h3', 'p', 'a', 'span', 'strong', 'small', 'i'],
      ],
      '#attached' => [
        'library' => [
          'nfr/public-data',
        ],
        'drupalSettings' => [
          'nfr' => [
            'stateData' => $state_counts,
            'demographicData' => $demographic_data,
            'cancerData' => $cancer_data,
          ],
        ],
      ],
    ];
  }
  
  /**
   * Get public statistics from database.
   */
  private function getPublicStatistics($connection): array {
    $stats = [
      'total_participants' => 0,
      'total_states' => 0,
      'total_departments' => 0,
      'consented_participants' => 0,
      'questionnaires_completed' => 0,
      'cancer_diagnoses' => 0,
      'avg_years_service' => 0,
      'career_firefighters' => 0,
      'volunteer_firefighters' => 0,
      'male_participants' => 0,
      'female_participants' => 0,
    ];
    
    try {
      // Total participants
      $stats['total_participants'] = (int) $connection->select('nfr_user_profile', 'p')
        ->countQuery()
        ->execute()
        ->fetchField();
      
      // Total states represented
      $stats['total_states'] = (int) $connection->select('nfr_work_history', 'wh')
        ->fields('wh', ['department_state'])
        ->distinct()
        ->condition('department_state', '', '!=')
        ->countQuery()
        ->execute()
        ->fetchField();
      
      // Total departments
      $stats['total_departments'] = (int) $connection->select('nfr_work_history', 'wh')
        ->fields('wh', ['department_name'])
        ->distinct()
        ->condition('department_name', '', '!=')
        ->countQuery()
        ->execute()
        ->fetchField();
      
      // Consented participants
      $consent_query = $connection->select('nfr_consent', 'c');
      $consent_query->condition('consented_to_participate', 1);
      $stats['consented_participants'] = (int) $consent_query->countQuery()->execute()->fetchField();
      
      // Completed questionnaires
      $questionnaire_query = $connection->select('nfr_questionnaire', 'q');
      $questionnaire_query->condition('questionnaire_completed', 1);
      $stats['questionnaires_completed'] = (int) $questionnaire_query->countQuery()->execute()->fetchField();
      
      // Cancer diagnoses
      $stats['cancer_diagnoses'] = (int) $connection->select('nfr_cancer_diagnoses', 'cd')
        ->countQuery()
        ->execute()
        ->fetchField();
      
      // Calculate average years of service from work history
      $years_query = $connection->select('nfr_work_history', 'wh')
        ->fields('wh', ['start_date', 'end_date', 'is_current']);
      $work_history = $years_query->execute()->fetchAll();
      
      $total_years = 0;
      $firefighter_count = 0;
      $current_year = date('Y');
      
      // Group by uid to calculate per firefighter
      $firefighter_years = [];
      foreach ($work_history as $record) {
        if (empty($record->start_date)) {
          continue;
        }
        
        $start_year = (int) substr($record->start_date, 0, 4);
        $end_year = $record->is_current ? $current_year : (int) substr($record->end_date, 0, 4);
        
        if ($end_year == 0) {
          $end_year = $current_year;
        }
        
        $years = max(0, $end_year - $start_year);
        $total_years += $years;
        $firefighter_count++;
      }
      
      $stats['avg_years_service'] = $firefighter_count > 0 ? round($total_years / $firefighter_count, 1) : 0;
      
      // Career vs Volunteer - field doesn't exist in current schema
      $stats['career_firefighters'] = 0;
      $stats['volunteer_firefighters'] = 0;
      
      // Gender distribution
      $stats['male_participants'] = (int) $connection->select('nfr_user_profile', 'p')
        ->condition('sex', 'male')
        ->countQuery()
        ->execute()
        ->fetchField();
      
      $stats['female_participants'] = (int) $connection->select('nfr_user_profile', 'p')
        ->condition('sex', 'female')
        ->countQuery()
        ->execute()
        ->fetchField();
      
      // Count "other" gender or any non-male/female values
      $stats['other_participants'] = (int) $connection->select('nfr_user_profile', 'p')
        ->condition('sex', 'male', '!=')
        ->condition('sex', 'female', '!=')
        ->isNotNull('sex')
        ->countQuery()
        ->execute()
        ->fetchField();
      
    } catch (\Exception $e) {
      \Drupal::logger('nfr')->error('Error fetching public statistics: @message', ['@message' => $e->getMessage()]);
    }
    
    return $stats;
  }
  
  /**
   * Get demographic data for charts.
   */
  private function getDemographicData($connection): array {
    $data = [];
    
    try {
      // Race/Ethnicity distribution (stored as JSON array)
      $race_query = $connection->select('nfr_questionnaire', 'q');
      $race_query->addField('q', 'race_ethnicity');
      $race_query->condition('race_ethnicity', '', '!=');
      $race_query->isNotNull('race_ethnicity');
      $race_results = $race_query->execute()->fetchCol();
      
      $race_labels = [
        'white' => 'White',
        'black' => 'Black/African American',
        'hispanic' => 'Hispanic/Latino',
        'asian' => 'Asian',
        'american_indian' => 'American Indian/Alaska Native',
        'pacific_islander' => 'Native Hawaiian/Pacific Islander',
        'middle_eastern' => 'Middle Eastern/North African',
        'other' => 'Other',
      ];
      
      $race_counts = [];
      foreach ($race_results as $race_json) {
        $races = json_decode($race_json, TRUE);
        if (is_array($races)) {
          foreach ($races as $race) {
            if (!isset($race_counts[$race])) {
              $race_counts[$race] = 0;
            }
            $race_counts[$race]++;
          }
        }
      }
      
      $data['race'] = [
        'labels' => [],
        'values' => [],
      ];
      
      foreach ($race_counts as $race => $count) {
        $data['race']['labels'][] = $race_labels[$race] ?? ucfirst(str_replace('_', ' ', $race));
        $data['race']['values'][] = (int) $count;
      }
      
      // Education Level distribution
      $edu_query = $connection->select('nfr_questionnaire', 'q');
      $edu_query->addField('q', 'education_level', 'education');
      $edu_query->addExpression('COUNT(*)', 'count');
      $edu_query->condition('education_level', '', '!=');
      $edu_query->groupBy('q.education_level');
      $edu_results = $edu_query->execute()->fetchAllKeyed();
      
      $edu_labels = [
        'never_attended' => 'Never Attended/Kindergarten',
        'elementary' => 'Elementary (Grades 1-8)',
        'some_hs' => 'Some High School (Grades 9-11)',
        'hs_ged' => 'High School Graduate/GED',
        'some_college' => 'Some College/Technical School (1-3 years)',
        'college_graduate' => 'College Graduate (4+ years)',
        'prefer_not_answer' => 'Prefer Not to Answer',
        // Legacy values for backwards compatibility
        'less_than_hs' => 'Less than High School',
        'associate' => 'Associate Degree',
        'bachelor' => 'Bachelor\'s Degree',
        'graduate' => 'Graduate Degree',
      ];
      
      $data['education'] = [
        'labels' => [],
        'values' => [],
      ];
      
      foreach ($edu_results as $edu => $count) {
        $data['education']['labels'][] = $edu_labels[$edu] ?? ucfirst($edu);
        $data['education']['values'][] = (int) $count;
      }
      
      // Marital Status distribution
      $marital_query = $connection->select('nfr_questionnaire', 'q');
      $marital_query->addField('q', 'marital_status', 'status');
      $marital_query->addExpression('COUNT(*)', 'count');
      $marital_query->condition('marital_status', '', '!=');
      $marital_query->groupBy('q.marital_status');
      $marital_results = $marital_query->execute()->fetchAllKeyed();
      
      $marital_labels = [
        'married' => 'Married',
        'living_with_partner' => 'Living with Partner',
        'never_married' => 'Never Married',
        'divorced' => 'Divorced',
        'separated' => 'Separated',
        'widowed' => 'Widowed',
        'prefer_not_answer' => 'Prefer Not to Answer',
        // Legacy value for backwards compatibility
        'single' => 'Never Married',
      ];
      
      $data['marital'] = [
        'labels' => [],
        'values' => [],
      ];
      
      foreach ($marital_results as $status => $count) {
        $data['marital']['labels'][] = $marital_labels[$status] ?? ucfirst($status);
        $data['marital']['values'][] = (int) $count;
      }
      
      // Age distribution (from height/weight as proxy - or calculate from birth year if available)
      // BMI distribution
      $bmi_query = $connection->select('nfr_questionnaire', 'q');
      $bmi_query->addField('q', 'height_inches');
      $bmi_query->addField('q', 'weight_pounds');
      $bmi_query->condition('height_inches', 0, '>');
      $bmi_query->condition('weight_pounds', 0, '>');
      $bmi_results = $bmi_query->execute()->fetchAll();
      
      $bmi_ranges = [
        'Underweight (<18.5)' => 0,
        'Normal (18.5-24.9)' => 0,
        'Overweight (25-29.9)' => 0,
        'Obese (30-34.9)' => 0,
        'Severely Obese (35+)' => 0,
      ];
      
      foreach ($bmi_results as $row) {
        $height_m = $row->height_inches * 0.0254;
        $weight_kg = $row->weight_pounds * 0.453592;
        $bmi = $weight_kg / ($height_m * $height_m);
        
        if ($bmi < 18.5) {
          $bmi_ranges['Underweight (<18.5)']++;
        } elseif ($bmi < 25) {
          $bmi_ranges['Normal (18.5-24.9)']++;
        } elseif ($bmi < 30) {
          $bmi_ranges['Overweight (25-29.9)']++;
        } elseif ($bmi < 35) {
          $bmi_ranges['Obese (30-34.9)']++;
        } else {
          $bmi_ranges['Severely Obese (35+)']++;
        }
      }
      
      $data['bmi'] = [
        'labels' => array_keys($bmi_ranges),
        'values' => array_values($bmi_ranges),
      ];
      
    } catch (\Exception $e) {
      \Drupal::logger('nfr')->error('Error fetching demographic data: @message', ['@message' => $e->getMessage()]);
    }
    
    return $data;
  }
  
  /**
   * Get cancer data for charts.
   */
  private function getCancerData($connection): array {
    $data = [];
    
    try {
      // Cancer types distribution
      $cancer_query = $connection->select('nfr_cancer_diagnoses', 'cd');
      $cancer_query->addField('cd', 'cancer_type', 'type');
      $cancer_query->addExpression('COUNT(*)', 'count');
      $cancer_query->condition('cancer_type', '', '!=');
      $cancer_query->groupBy('cd.cancer_type');
      $cancer_query->orderBy('count', 'DESC');
      $cancer_results = $cancer_query->execute()->fetchAllKeyed();
      
      $data['types'] = [
        'labels' => [],
        'values' => [],
      ];
      
      foreach ($cancer_results as $type => $count) {
        // Clean up the type name for display
        $display_type = ucwords(str_replace('_', ' ', $type));
        $data['types']['labels'][] = $display_type;
        $data['types']['values'][] = (int) $count;
      }
      
      // Age at diagnosis ranges - calculate from year_diagnosed and date_of_birth
      // For now, skip this since we'd need to join tables
      $data['age_at_diagnosis'] = [
        'labels' => [],
        'values' => [],
      ];
      
      // Family history counts
      $family_query = $connection->select('nfr_family_cancer_history', 'fch');
      $family_query->addExpression('COUNT(DISTINCT uid)', 'count');
      $family_count = $family_query->execute()->fetchField();
      
      // Total participants
      $total_query = $connection->select('nfr_user_profile', 'p');
      $total_query->addExpression('COUNT(*)', 'count');
      $total_participants = $total_query->execute()->fetchField();
      
      $data['family_history'] = [
        'labels' => ['Family History', 'No Family History'],
        'values' => [
          (int) $family_count,
          (int) ($total_participants - $family_count),
        ],
      ];
      
    } catch (\Exception $e) {
      \Drupal::logger('nfr')->error('Error fetching cancer data: @message', ['@message' => $e->getMessage()]);
    }
    
    return $data;
  }
  
  /**
   * Build the public data dashboard HTML.
   */
  private function buildPublicDataDashboard(array $state_counts, array $stats): string {
    $html = '<div class="nfr-public-data-dashboard">';
    
    // Header
    $html .= '<div class="container-fluid my-4">';
    $html .= '<div class="row mb-4">';
    $html .= '<div class="col-12">';
    $html .= '<div class="card card-forseti">';
    $html .= '<div class="card-body">';
    $html .= '<h1 class="display-4 text-white mb-3">National Firefighter Registry Statistics</h1>';
    $html .= '<p class="lead text-white mb-2">Aggregated data from the CDC National Firefighter Registry program</p>';
    $html .= '<p class="text-muted-light mb-0"><small>All statistics are aggregated and de-identified to protect participant privacy.</small></p>';
    $html .= '</div></div>';
    $html .= '</div></div>';
    
    // US Map Section
    $html .= '<div class="row mb-5">';
    $html .= '<div class="col-12">';
    $html .= '<div class="card card-forseti">';
    $html .= '<div class="card-body">';
    $html .= '<h2 class="h3 mb-3 text-white">Registered Firefighters by State</h2>';
    $html .= '<div id="us-map-container" style="min-height: 400px;">';
    $html .= '</div>';
    $html .= '<div id="map-legend" class="mt-3">';
    $html .= '<div class="legend-title text-white mb-2"><strong>Number of Registered Firefighters</strong></div>';
    $html .= '<div class="legend-scale d-flex align-items-center flex-wrap">';
    $html .= '<div class="legend-item me-3 mb-2"><span class="legend-color state-level-0"></span> 0</div>';
    $html .= '<div class="legend-item me-3 mb-2"><span class="legend-color state-level-1"></span> 1-10</div>';
    $html .= '<div class="legend-item me-3 mb-2"><span class="legend-color state-level-2"></span> 11-50</div>';
    $html .= '<div class="legend-item me-3 mb-2"><span class="legend-color state-level-3"></span> 51-100</div>';
    $html .= '<div class="legend-item me-3 mb-2"><span class="legend-color state-level-4"></span> 101-250</div>';
    $html .= '<div class="legend-item mb-2"><span class="legend-color state-level-5"></span> 250+</div>';
    $html .= '</div></div>';
    $html .= '</div></div>';
    $html .= '</div></div>';
    
    // Key Statistics Grid
    $html .= '<div class="row g-4 mb-5">';
    
    // Total Participants
    $html .= '<div class="col-md-6 col-lg-3">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body text-center">';
    $html .= '<div class="stat-icon mb-3" style="font-size: 3rem;">👥</div>';
    $html .= '<div class="stat-value text-cyan" style="font-size: 2.5rem; font-weight: bold;">' . number_format($stats['total_participants']) . '</div>';
    $html .= '<div class="stat-label text-white">Total Participants</div>';
    $html .= '</div></div></div>';
    
    // States Represented
    $html .= '<div class="col-md-6 col-lg-3">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body text-center">';
    $html .= '<div class="stat-icon mb-3" style="font-size: 3rem;">🗺️</div>';
    $html .= '<div class="stat-value text-cyan" style="font-size: 2.5rem; font-weight: bold;">' . $stats['total_states'] . '</div>';
    $html .= '<div class="stat-label text-white">States Represented</div>';
    $html .= '</div></div></div>';
    
    // Fire Departments
    $html .= '<div class="col-md-6 col-lg-3">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body text-center">';
    $html .= '<div class="stat-icon mb-3" style="font-size: 3rem;">🚒</div>';
    $html .= '<div class="stat-value text-cyan" style="font-size: 2.5rem; font-weight: bold;">' . number_format($stats['total_departments']) . '</div>';
    $html .= '<div class="stat-label text-white">Fire Departments</div>';
    $html .= '</div></div></div>';
    
    // Questionnaires Completed
    $html .= '<div class="col-md-6 col-lg-3">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body text-center">';
    $html .= '<div class="stat-icon mb-3" style="font-size: 3rem;">📋</div>';
    $html .= '<div class="stat-value text-cyan" style="font-size: 2.5rem; font-weight: bold;">' . number_format($stats['questionnaires_completed']) . '</div>';
    $html .= '<div class="stat-label text-white">Questionnaires Completed</div>';
    $html .= '</div></div></div>';
    
    $html .= '</div>'; // End key stats row
    
    // Additional Statistics
    $html .= '<div class="row g-4">';
    
    // Demographics Card
    $html .= '<div class="col-lg-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body">';
    $html .= '<h3 class="h4 mb-4 text-white"><i class="fas fa-users me-2"></i>Demographics</h3>';
    $html .= '<div class="mb-3">';
    
    // Calculate percentages
    $total_gender = $stats['male_participants'] + $stats['female_participants'] + $stats['other_participants'];
    $male_pct = $total_gender > 0 ? round(($stats['male_participants'] / $total_gender) * 100, 1) : 0;
    $female_pct = $total_gender > 0 ? round(($stats['female_participants'] / $total_gender) * 100, 1) : 0;
    $other_pct = $total_gender > 0 ? round(($stats['other_participants'] / $total_gender) * 100, 1) : 0;
    
    // Gender breakdown list
    $html .= '<div class="row g-3">';
    
    // Male
    $html .= '<div class="col-md-6">';
    $html .= '<div class="d-flex align-items-center p-3 rounded" style="background: rgba(13, 202, 240, 0.1); border-left: 4px solid #0dcaf0;">';
    $html .= '<div class="flex-grow-1">';
    $html .= '<div class="text-white-50 small">Male Firefighters</div>';
    $html .= '<div class="h3 text-white mb-0">' . number_format($stats['male_participants']) . '</div>';
    $html .= '<div class="text-info small">' . $male_pct . '%</div>';
    $html .= '</div>';
    $html .= '<div class="ms-3"><i class="fas fa-mars fa-2x text-info"></i></div>';
    $html .= '</div></div>';
    
    // Female
    $html .= '<div class="col-md-6">';
    $html .= '<div class="d-flex align-items-center p-3 rounded" style="background: rgba(25, 135, 84, 0.1); border-left: 4px solid #198754;">';
    $html .= '<div class="flex-grow-1">';
    $html .= '<div class="text-white-50 small">Female Firefighters</div>';
    $html .= '<div class="h3 text-white mb-0">' . number_format($stats['female_participants']) . '</div>';
    $html .= '<div class="text-success small">' . $female_pct . '%</div>';
    $html .= '</div>';
    $html .= '<div class="ms-3"><i class="fas fa-venus fa-2x text-success"></i></div>';
    $html .= '</div></div>';
    
    // Other (only if > 0)
    if ($stats['other_participants'] > 0) {
      $html .= '<div class="col-12">';
      $html .= '<div class="d-flex align-items-center p-3 rounded" style="background: rgba(255, 193, 7, 0.1); border-left: 4px solid #ffc107;">';
      $html .= '<div class="flex-grow-1">';
      $html .= '<div class="text-white-50 small">Other</div>';
      $html .= '<div class="h3 text-white mb-0">' . number_format($stats['other_participants']) . '</div>';
      $html .= '<div class="text-warning small">' . $other_pct . '%</div>';
      $html .= '</div>';
      $html .= '<div class="ms-3"><i class="fas fa-user fa-2x text-warning"></i></div>';
      $html .= '</div></div>';
    }
    
    $html .= '</div></div>';
    
    $html .= '<div class="mt-4 pt-3 border-top border-secondary">';
    $html .= '<div class="d-flex justify-content-between text-white">';
    $html .= '<span><strong>Average Years of Service</strong></span>';
    $html .= '<strong class="text-cyan">' . $stats['avg_years_service'] . ' years</strong>';
    $html .= '</div></div>';
    
    $html .= '</div></div></div>';
    
    // Service Type Card - Only show if data exists
    if ($stats['career_firefighters'] > 0 || $stats['volunteer_firefighters'] > 0) {
      $html .= '<div class="col-lg-6">';
      $html .= '<div class="card card-forseti h-100">';
      $html .= '<div class="card-body">';
      $html .= '<h3 class="h4 mb-4 text-white"><i class="fas fa-briefcase me-2"></i>Service Type</h3>';
      
      $html .= '<div class="mb-3">';
      $html .= '<div class="d-flex justify-content-between text-white mb-2">';
      $html .= '<span>Career Firefighters</span>';
      $html .= '<strong>' . number_format($stats['career_firefighters']) . '</strong>';
      $html .= '</div>';
      $html .= '<div class="progress" style="height: 25px;">';
      $total_type = $stats['career_firefighters'] + $stats['volunteer_firefighters'];
      $career_pct = $total_type > 0 ? round(($stats['career_firefighters'] / $total_type) * 100, 1) : 0;
      $html .= '<div class="progress-bar bg-warning" style="width: ' . $career_pct . '%">' . $career_pct . '%</div>';
      $html .= '</div></div>';
      
      $html .= '<div class="mb-3">';
      $html .= '<div class="d-flex justify-content-between text-white mb-2">';
      $html .= '<span>Volunteer Firefighters</span>';
      $html .= '<strong>' . number_format($stats['volunteer_firefighters']) . '</strong>';
      $html .= '</div>';
      $html .= '<div class="progress" style="height: 25px;">';
      $volunteer_pct = $total_type > 0 ? round(($stats['volunteer_firefighters'] / $total_type) * 100, 1) : 0;
      $html .= '<div class="progress-bar bg-primary" style="width: ' . $volunteer_pct . '%">' . $volunteer_pct . '%</div>';
      $html .= '</div></div>';
      
      $html .= '<div class="mt-4 pt-3 border-top border-secondary">';
      $html .= '<div class="d-flex justify-content-between text-white">';
      $html .= '<span><strong>Cancer Diagnoses Tracked</strong></span>';
      $html .= '<strong class="text-danger">' . number_format($stats['cancer_diagnoses']) . '</strong>';
      $html .= '</div></div>';
      
      $html .= '</div></div></div>';
    }
    
    $html .= '</div>'; // End additional stats row
    
    // Demographic Charts Section
    $html .= '<div class="row mt-5 mb-4">';
    $html .= '<div class="col-12">';
    $html .= '<h2 class="h3 text-white mb-4"><i class="fas fa-chart-pie me-2"></i>Participant Demographics</h2>';
    $html .= '</div></div>';
    
    $html .= '<div class="row g-4 mb-5">';
    
    // Race/Ethnicity Pie Chart
    $html .= '<div class="col-lg-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body">';
    $html .= '<h3 class="h5 mb-3 text-white">Race &amp; Ethnicity Distribution</h3>';
    $html .= '<canvas id="race-chart" style="max-height: 300px;"></canvas>';
    $html .= '</div></div></div>';
    
    // Education Level Pie Chart
    $html .= '<div class="col-lg-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body">';
    $html .= '<h3 class="h5 mb-3 text-white">Education Level</h3>';
    $html .= '<canvas id="education-chart" style="max-height: 300px;"></canvas>';
    $html .= '</div></div></div>';
    
    // Marital Status Pie Chart
    $html .= '<div class="col-lg-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body">';
    $html .= '<h3 class="h5 mb-3 text-white">Marital Status</h3>';
    $html .= '<canvas id="marital-chart" style="max-height: 300px;"></canvas>';
    $html .= '</div></div></div>';
    
    // BMI Distribution Bar Chart
    $html .= '<div class="col-lg-6">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body">';
    $html .= '<h3 class="h5 mb-3 text-white">BMI Distribution</h3>';
    $html .= '<canvas id="bmi-chart" style="max-height: 300px;"></canvas>';
    $html .= '</div></div></div>';
    
    $html .= '</div>'; // End demographics charts row
    
    // Cancer Data Charts Section
    $html .= '<div class="row mt-5 mb-4">';
    $html .= '<div class="col-12">';
    $html .= '<h2 class="h3 text-white mb-4"><i class="fas fa-chart-bar me-2"></i>Cancer Statistics</h2>';
    $html .= '</div></div>';
    
    $html .= '<div class="row g-4 mb-5">';
    
    // Cancer Types Bar Chart
    $html .= '<div class="col-lg-8">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body">';
    $html .= '<h3 class="h5 mb-3 text-white">Cancer Types Reported</h3>';
    $html .= '<canvas id="cancer-types-chart" style="max-height: 350px;"></canvas>';
    $html .= '</div></div></div>';
    
    // Family History Pie Chart
    $html .= '<div class="col-lg-4">';
    $html .= '<div class="card card-forseti h-100">';
    $html .= '<div class="card-body">';
    $html .= '<h3 class="h5 mb-3 text-white">Family Cancer History</h3>';
    $html .= '<canvas id="family-history-chart" style="max-height: 300px;"></canvas>';
    $html .= '</div></div></div>';
    
    $html .= '</div>'; // End cancer charts row
    
    // Call to Action
    $html .= '<div class="row mt-5">';
    $html .= '<div class="col-12">';
    $html .= '<div class="card bg-gradient text-white text-center" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">';
    $html .= '<div class="card-body py-5">';
    $html .= '<h2 class="mb-3">Join the National Firefighter Registry</h2>';
    $html .= '<p class="lead mb-4">Help protect firefighters by contributing to vital cancer research.</p>';
    $html .= '<a href="/user/register" class="btn btn-light btn-lg me-2">Register Now</a>';
    $html .= '<a href="/nfr/about" class="btn btn-outline-light btn-lg">Learn More</a>';
    $html .= '</div></div>';
    $html .= '</div></div>';
    
    $html .= '</div>'; // End container
    $html .= '</div>'; // End dashboard
    
    return $html;
  }

  /**
   * Privacy Policy page.
   *
   * @return array
   *   Render array.
   */
  public function privacy(): array {
    return [
      '#theme' => 'nfr_public_page',
      '#page_id' => 'privacy',
      '#content' => [
        '#markup' => '<h2>Privacy Policy</h2><p>Placeholder content for Privacy Policy.</p>',
      ],
    ];
  }

  /**
   * Terms of Service page.
   *
   * @return array
   *   Render array.
   */
  public function terms(): array {
    return [
      '#theme' => 'nfr_public_page',
      '#page_id' => 'terms',
      '#content' => [
        '#markup' => '<h2>Terms of Service</h2><p>Placeholder content for Terms of Service.</p>',
      ],
    ];
  }

}
