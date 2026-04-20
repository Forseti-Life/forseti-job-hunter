<?php

namespace Drupal\job_hunter\Service;

/**
 * Maps consolidated job-seeker profile data to ATS form fields.
 *
 * Each ATS platform uses different HTML field names/IDs. This service returns
 * a normalized map of fieldname => value pairs for a given platform, built
 * from the user's consolidated profile and tailored resume data.
 */
class ApplicationFormMapper {

  /**
   * Build a form-field map for the given ATS platform.
   *
   * @param array $profile
   *   Flat row from jobhunter_job_seeker (or array with same keys).
   * @param array $consolidated
   *   Decoded consolidated_profile_json.
   * @param array $job
   *   Row from jobhunter_job_requirements.
   * @param string $ats_platform
   *   ATS platform key (workday, greenhouse, lever, icims, usajobs, custom).
   *
   * @return array{
   *   personal: array,
   *   address: array,
   *   work_auth: array,
   *   experience: array,
   *   education: array,
   *   salary: array,
   *   platform_fields: array,
   * }
   */
  public function buildFieldMap(array $profile, array $consolidated, array $job, string $ats_platform): array {
    $contact = $consolidated['contact_info'] ?? [];
    $loc     = $contact['location'] ?? [];

    // Split full_name if first/last not separate.
    [$first, $last] = $this->splitName($profile['full_name'] ?? ($contact['name'] ?? ''));

    $personal = [
      'first_name' => $first,
      'last_name'  => $last,
      'full_name'  => $profile['full_name'] ?? ($contact['name'] ?? ''),
      'email'      => $profile['contact_email'] ?? ($contact['email'] ?? ''),
      'phone'      => $profile['contact_phone'] ?? ($contact['phone'] ?? ''),
    ];

    $address = [
      'city'  => $profile['location_city'] ?? ($loc['city'] ?? ''),
      'state' => $profile['location_state'] ?? ($loc['state'] ?? ''),
      'zip'   => $loc['zip'] ?? '',
    ];

    $prefs = $consolidated['job_search_preferences'] ?? [];
    $work_auth = [
      'authorized'       => TRUE, // Assume US authorized — adjust per profile.
      'sponsorship'      => FALSE,
      'work_auth_status' => $prefs['work_authorization'] ?? 'US_CITIZEN',
    ];

    $exp_entries = $consolidated['professional_experience'] ?? [];
    $years_exp = $profile['experience_years'] ?? count($exp_entries);
    $current_exp = !empty($exp_entries) ? $exp_entries[0] : [];
    $experience = [
      'years_of_experience' => $years_exp,
      'current_title'       => $current_exp['title'] ?? '',
      'current_company'     => $current_exp['company'] ?? '',
      'history'             => $exp_entries,
    ];

    $edu = $consolidated['education'] ?? [];
    $latest_edu = !empty($edu) ? $edu[0] : [];
    $education = [
      'highest_degree' => $profile['education_level'] ?? 'Bachelor\'s',
      'institution'    => $latest_edu['institution'] ?? '',
      'field_of_study' => $latest_edu['field'] ?? '',
      'graduation_year' => $latest_edu['year'] ?? '',
      'history'        => $edu,
    ];

    $salary = [
      'min'      => $profile['salary_min'] ?? '',
      'max'      => $profile['salary_max'] ?? '',
      'expected' => $prefs['salary_target'] ?? ($profile['salary_min'] ?? ''),
      'currency' => 'USD',
    ];

    // Platform-specific field name mappings.
    $platform_fields = $this->getPlatformFieldNames($ats_platform, $personal, $address, $work_auth, $salary);

    return compact('personal', 'address', 'work_auth', 'experience', 'education', 'salary', 'platform_fields');
  }

  /**
   * Returns platform-specific HTML field name => value pairs.
   *
   * These are the actual form input names used by each ATS.
   */
  protected function getPlatformFieldNames(string $platform, array $personal, array $address, array $work_auth, array $salary): array {
    switch ($platform) {
      case 'greenhouse':
        return [
          'job_application[first_name]'   => $personal['first_name'],
          'job_application[last_name]'    => $personal['last_name'],
          'job_application[email]'        => $personal['email'],
          'job_application[phone]'        => $personal['phone'],
          'job_application[location]'     => "{$address['city']}, {$address['state']}",
        ];

      case 'lever':
        return [
          'name'     => $personal['full_name'],
          'email'    => $personal['email'],
          'phone'    => $personal['phone'],
          'location' => "{$address['city']}, {$address['state']}",
        ];

      case 'workday':
        // Workday uses dynamic IDs; these are common label-based selectors.
        return [
          'Legal Name—First Name'         => $personal['first_name'],
          'Legal Name—Last Name'          => $personal['last_name'],
          'Email Address'                 => $personal['email'],
          'Phone Number'                  => $personal['phone'],
          'Address Line 1'                => $address['city'],
          'City'                          => $address['city'],
          'State'                         => $address['state'],
          'Postal Code'                   => $address['zip'],
        ];

      case 'icims':
        return [
          'applicantFirstName'  => $personal['first_name'],
          'applicantLastName'   => $personal['last_name'],
          'applicantEmail'      => $personal['email'],
          'applicantPhone'      => $personal['phone'],
          'applicantCity'       => $address['city'],
          'applicantState'      => $address['state'],
          'applicantZipCode'    => $address['zip'],
        ];

      case 'taleo':
        return [
          'firstName'           => $personal['first_name'],
          'lastName'            => $personal['last_name'],
          'emailAddress'        => $personal['email'],
          'phoneNumber'         => $personal['phone'],
          'city'                => $address['city'],
          'state'               => $address['state'],
          'postalCode'          => $address['zip'],
        ];

      case 'usajobs':
        // USAJobs uses a profile-based system; these are the questionnaire fields.
        return [
          'first_name'          => $personal['first_name'],
          'last_name'           => $personal['last_name'],
          'email'               => $personal['email'],
          'phone'               => $personal['phone'],
          'city'                => $address['city'],
          'state'               => $address['state'],
          'zip'                 => $address['zip'],
          'citizenship'         => $work_auth['work_auth_status'],
          'salary_min'          => $salary['min'],
        ];

      case 'bamboohr':
        return [
          'firstName'           => $personal['first_name'],
          'lastName'            => $personal['last_name'],
          'email'               => $personal['email'],
          'phone'               => $personal['phone'],
          'city'                => $address['city'],
          'state'               => $address['state'],
        ];

      case 'smartrecruiters':
        return [
          'firstName'           => $personal['first_name'],
          'lastName'            => $personal['last_name'],
          'email'               => $personal['email'],
          'phone'               => $personal['phone'],
          'location'            => "{$address['city']}, {$address['state']}",
        ];

      default:
        // Generic / custom — use common field name patterns.
        return [
          'first_name'          => $personal['first_name'],
          'last_name'           => $personal['last_name'],
          'email'               => $personal['email'],
          'phone'               => $personal['phone'],
          'city'                => $address['city'],
          'state'               => $address['state'],
          'zip'                 => $address['zip'],
        ];
    }
  }

  /**
   * Split "First Last" into [first, last].
   */
  protected function splitName(string $full_name): array {
    $parts = explode(' ', trim($full_name), 2);
    return [$parts[0] ?? '', $parts[1] ?? ''];
  }

}
