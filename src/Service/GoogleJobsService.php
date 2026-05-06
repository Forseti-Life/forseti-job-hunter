<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;

/**
 * Service for Google for Jobs integration via Schema.org structured data.
 */
class GoogleJobsService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructs a GoogleJobsService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(Connection $database, LoggerChannelFactoryInterface $logger_factory, FileUrlGeneratorInterface $file_url_generator) {
    $this->database = $database;
    $this->logger = $logger_factory->get('job_hunter');
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * Generate Schema.org JobPosting JSON-LD for a job.
   *
   * @param int $job_id
   *   The job ID from jobhunter_job_requirements table.
   *
   * @return array
   *   The JSON-LD structured data as an array.
   *
   * @throws \Exception
   *   If job not found or required data is missing.
   */
  public function generateJobPostingJsonLd($job_id) {
    // Get job data
    $job = $this->database->select('jobhunter_job_requirements', 'j')
      ->fields('j')
      ->condition('id', $job_id)
      ->execute()
      ->fetchObject();
    
    if (!$job) {
      throw new \Exception("Job not found with ID: $job_id");
    }
    
    // Get company data
    $company = $this->database->select('jobhunter_companies', 'c')
      ->fields('c')
      ->condition('id', $job->company_id)
      ->execute()
      ->fetchObject();
    
    if (!$company) {
      throw new \Exception("Company not found for job ID: $job_id");
    }
    
    // Parse extracted_json for job details
    $extracted_data = json_decode($job->extracted_json ?? '{}', TRUE);
    $skills_data = json_decode($job->skills_required_json ?? '{}', TRUE);
    
    // Build JSON-LD structure
    $json_ld = [
      '@context' => 'https://schema.org/',
      '@type' => 'JobPosting',
      'title' => $job->job_title,
      'description' => $this->sanitizeDescription($extracted_data['description'] ?? $job->job_description ?? ''),
      'identifier' => [
        '@type' => 'PropertyValue',
        'name' => 'Job Hunter ID',
        'value' => (string) $job_id,
      ],
      'datePosted' => date('Y-m-d', strtotime($job->created_at)),
    ];
    
    // Valid through date (30 days from creation by default)
    if (!empty($job->valid_through)) {
      $json_ld['validThrough'] = date('Y-m-d\TH:i:s\Z', strtotime($job->valid_through));
    }
    else {
      // Default to 30 days from now
      $json_ld['validThrough'] = date('Y-m-d\TH:i:s\Z', strtotime('+30 days'));
    }
    
    // Employment type
    $employment_types = $this->mapEmploymentType($extracted_data['employment_type'] ?? 'FULL_TIME');
    $json_ld['employmentType'] = $employment_types;
    
    // Hiring organization
    $json_ld['hiringOrganization'] = [
      '@type' => 'Organization',
      'name' => $company->company_name,
    ];
    
    if (!empty($company->website)) {
      $json_ld['hiringOrganization']['sameAs'] = $company->website;
    }
    
    if (!empty($company->logo_path)) {
      $json_ld['hiringOrganization']['logo'] = $this->fileUrlGenerator->generateAbsoluteString($company->logo_path);
    }
    
    // Job location
    $location_data = $extracted_data['location'] ?? [];
    $json_ld['jobLocation'] = $this->buildJobLocation($location_data, $extracted_data);
    
    // Remote work type
    if (!empty($extracted_data['is_remote']) && $extracted_data['is_remote']) {
      $json_ld['jobLocationType'] = 'TELECOMMUTE';
    }
    
    // Base salary
    $salary_data = $extracted_data['salary'] ?? [];
    if (!empty($salary_data['min']) || !empty($salary_data['max'])) {
      $json_ld['baseSalary'] = $this->buildSalaryData($salary_data);
    }
    
    // Skills (optional but recommended)
    $all_skills = array_merge(
      $skills_data['must_have'] ?? [],
      $skills_data['nice_to_have'] ?? [],
      $skills_data['tech_stack'] ?? []
    );
    if (!empty($all_skills)) {
      $json_ld['skills'] = implode(', ', array_unique($all_skills));
    }
    
    // Education requirements
    if (!empty($extracted_data['education'])) {
      $json_ld['educationRequirements'] = [
        '@type' => 'EducationalOccupationalCredential',
        'credentialCategory' => $extracted_data['education'],
      ];
    }
    
    // Experience requirements
    if (!empty($extracted_data['experience_years'])) {
      $json_ld['experienceRequirements'] = [
        '@type' => 'OccupationalExperienceRequirements',
        'monthsOfExperience' => $extracted_data['experience_years'] * 12,
      ];
    }
    
    // Direct application URL
    $json_ld['url'] = Url::fromRoute('job_hunter.job_view', ['job_id' => $job_id], ['absolute' => TRUE])->toString();
    
    return $json_ld;
  }

  /**
   * Sanitize job description for structured data.
   *
   * @param string $description
   *   The description text.
   *
   * @return string
   *   Sanitized description.
   */
  protected function sanitizeDescription($description) {
    // Strip excessive whitespace
    $description = preg_replace('/\s+/', ' ', $description);
    // Ensure minimum length (Google requires at least some description)
    if (strlen($description) < 50) {
      return $description . ' ' . 'Full job details available on our careers page.';
    }
    return trim($description);
  }

  /**
   * Map employment type to Schema.org standard values.
   *
   * @param string|array $type
   *   Employment type from extracted data.
   *
   * @return array
   *   Array of standard employment type values.
   */
  protected function mapEmploymentType($type) {
    $valid_types = ['FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER'];
    
    if (is_array($type)) {
      return array_intersect($type, $valid_types);
    }
    
    $type = strtoupper($type);
    if (in_array($type, $valid_types)) {
      return [$type];
    }
    
    // Default fallback
    return ['FULL_TIME'];
  }

  /**
   * Build job location structured data.
   *
   * @param array $location_data
   *   Location data from extracted_json.
   * @param array $extracted_data
   *   Full extracted data array.
   *
   * @return array
   *   Location structured data.
   */
  protected function buildJobLocation($location_data, $extracted_data) {
    $location = [
      '@type' => 'Place',
      'address' => [
        '@type' => 'PostalAddress',
      ],
    ];
    
    if (!empty($location_data['city'])) {
      $location['address']['addressLocality'] = $location_data['city'];
    }
    
    if (!empty($location_data['state'])) {
      $location['address']['addressRegion'] = $location_data['state'];
    }
    
    if (!empty($location_data['country'])) {
      $location['address']['addressCountry'] = $location_data['country'];
    }
    elseif (!empty($extracted_data['country'])) {
      $location['address']['addressCountry'] = $extracted_data['country'];
    }
    else {
      // Default to US
      $location['address']['addressCountry'] = 'US';
    }
    
    if (!empty($location_data['postal_code'])) {
      $location['address']['postalCode'] = $location_data['postal_code'];
    }
    
    if (!empty($location_data['street_address'])) {
      $location['address']['streetAddress'] = $location_data['street_address'];
    }
    
    return $location;
  }

  /**
   * Build salary structured data.
   *
   * @param array $salary_data
   *   Salary data from extracted_json.
   *
   * @return array
   *   Salary structured data.
   */
  protected function buildSalaryData($salary_data) {
    $salary = [
      '@type' => 'MonetaryAmount',
      'currency' => $salary_data['currency'] ?? 'USD',
      'value' => [
        '@type' => 'QuantitativeValue',
      ],
    ];
    
    $unit = strtoupper($salary_data['unit'] ?? 'YEAR');
    $salary['value']['unitText'] = $unit;
    
    if (!empty($salary_data['min']) && !empty($salary_data['max'])) {
      $salary['value']['minValue'] = $salary_data['min'];
      $salary['value']['maxValue'] = $salary_data['max'];
    }
    elseif (!empty($salary_data['min'])) {
      $salary['value']['value'] = $salary_data['min'];
    }
    elseif (!empty($salary_data['max'])) {
      $salary['value']['value'] = $salary_data['max'];
    }
    else {
      $salary['value']['value'] = $salary_data['amount'] ?? 0;
    }
    
    return $salary;
  }

  /**
   * Validate a job posting's structured data.
   *
   * @param int $job_id
   *   The job ID.
   *
   * @return array
   *   Validation result with status, errors, and warnings.
   */
  public function validateJobPosting($job_id) {
    $errors = [];
    $warnings = [];
    
    try {
      // Generate structured data
      $json_ld = $this->generateJobPostingJsonLd($job_id);
      
      // Required fields validation
      $required_fields = ['title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation'];
      foreach ($required_fields as $field) {
        if (empty($json_ld[$field])) {
          $errors[] = "Missing required field: $field";
        }
      }
      
      // Validate title length
      if (isset($json_ld['title']) && strlen($json_ld['title']) > 80) {
        $warnings[] = "Job title is longer than 80 characters. Consider shortening for better display.";
      }
      
      // Validate description length
      if (isset($json_ld['description']) && strlen($json_ld['description']) < 200) {
        $warnings[] = "Job description is less than 200 characters. Consider adding more details.";
      }
      
      // Check for recommended fields
      $recommended_fields = ['validThrough', 'employmentType', 'baseSalary'];
      foreach ($recommended_fields as $field) {
        if (empty($json_ld[$field])) {
          $warnings[] = "Missing recommended field: $field";
        }
      }
      
      // Validate date format
      if (isset($json_ld['datePosted'])) {
        $date = \DateTime::createFromFormat('Y-m-d', $json_ld['datePosted']);
        if (!$date) {
          $errors[] = "Invalid datePosted format. Must be YYYY-MM-DD.";
        }
      }
      
      // Validate employment type
      if (isset($json_ld['employmentType'])) {
        $valid_types = ['FULL_TIME', 'PART_TIME', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'VOLUNTEER', 'PER_DIEM', 'OTHER'];
        foreach ($json_ld['employmentType'] as $type) {
          if (!in_array($type, $valid_types)) {
            $errors[] = "Invalid employmentType: $type";
          }
        }
      }
      
      $status = empty($errors) ? 'valid' : 'invalid';
      
      return [
        'status' => $status,
        'errors' => $errors,
        'warnings' => $warnings,
        'structured_data' => $json_ld,
      ];
    }
    catch (\Exception $e) {
      return [
        'status' => 'error',
        'errors' => [$e->getMessage()],
        'warnings' => [],
      ];
    }
  }

}