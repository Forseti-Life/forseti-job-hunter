<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;

/**
 * Builds normalized profile payload data for Workday Playwright automation.
 */
class WorkdayProfileDataMapper {

  private const MAX_EDUCATION_ENTRIES = 3;

  protected Connection $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Assemble profile data from the job_seeker table for form filling.
   */
  public function buildProfileData(int $uid): array {
    $data = $this->getDefaultProfileData();

    try {
      $row = $this->loadJobSeekerRow($uid);

      if ($row) {
        $data['full_name'] = (string) ($row['full_name'] ?? '');
        $data['email']     = (string) ($row['contact_email'] ?? '');
        $data['phone']     = (string) ($row['contact_phone'] ?? '');
        $data['city']      = (string) ($row['location_city'] ?? '');
        $data['state']     = (string) ($row['location_state'] ?? '');
        $data['country']   = (string) ($row['country'] ?? '');
        if (!empty($row['eeo_gender'])) {
          $data['eeo_gender'] = (string) $row['eeo_gender'];
        }
        if (!empty($row['eeo_ethnicity'])) {
          $data['eeo_ethnicity'] = (string) $row['eeo_ethnicity'];
        }
        if (!empty($row['eeo_veteran'])) {
          $data['eeo_veteran'] = (string) $row['eeo_veteran'];
        }
        if (!empty($row['eeo_disability'])) {
          $data['disability_status'] = (string) $row['eeo_disability'];
        }
        if (!empty($row['work_authorized_us'])) {
          $data['work_authorized_us'] = (string) $row['work_authorized_us'];
        }
        if (!empty($row['requires_sponsorship'])) {
          $data['requires_sponsorship'] = (string) $row['requires_sponsorship'];
        }
        if (!empty($row['age_18_or_older'])) {
          $data['age_18_or_older'] = (string) $row['age_18_or_older'];
        }

        $json = [];
        if (!empty($row['consolidated_profile_json'])) {
          $decoded = json_decode((string) $row['consolidated_profile_json'], TRUE);
          if (is_array($decoded)) {
            $json = $decoded;
          }
        }

        $contact = is_array($json['contact_info'] ?? NULL) ? $json['contact_info'] : [];
        $location = is_array($contact['location'] ?? NULL) ? $contact['location'] : [];
        $prefs = is_array($json['job_search_preferences'] ?? NULL) ? $json['job_search_preferences'] : [];
        $demographics = is_array($json['demographics'] ?? NULL) ? $json['demographics'] : [];
        $experience = is_array($json['professional_experience'] ?? NULL) ? $json['professional_experience'] : [];
        $education = is_array($json['education'] ?? NULL) ? $json['education'] : [];

        if ($data['full_name'] === '' && !empty($contact['full_name'])) {
          $data['full_name'] = (string) $contact['full_name'];
        }
        if ($data['email'] === '' && !empty($contact['email'])) {
          $data['email'] = (string) $contact['email'];
        }
        if ($data['phone'] === '' && !empty($contact['phone'])) {
          $data['phone'] = (string) $contact['phone'];
        }
        if ($data['city'] === '' && !empty($location['city'])) {
          $data['city'] = (string) $location['city'];
        }
        if ($data['state'] === '' && !empty($location['state'])) {
          $data['state'] = (string) $location['state'];
        }
        if ($data['country'] === '' && !empty($location['country'])) {
          $data['country'] = (string) $location['country'];
        }

        if ($data['work_authorized_us'] === '' && isset($prefs['us_work_authorized'])) {
          $data['work_authorized_us'] = (string) $prefs['us_work_authorized'];
        }
        if ($data['requires_sponsorship'] === '' && isset($prefs['requires_sponsorship'])) {
          $data['requires_sponsorship'] = (string) $prefs['requires_sponsorship'];
        }
        if ($data['age_18_or_older'] === '' && isset($prefs['age_18_or_older'])) {
          $data['age_18_or_older'] = (string) $prefs['age_18_or_older'];
        }
        if ($data['hear_about_us'] === '' && isset($prefs['hear_about_us'])) {
          $data['hear_about_us'] = (string) $prefs['hear_about_us'];
        }
        if ($data['prior_company_employment'] === '' && isset($prefs['prior_company_employment'])) {
          $data['prior_company_employment'] = (string) $prefs['prior_company_employment'];
        }
        if ($data['prior_company_wwid'] === '' && isset($prefs['prior_company_wwid'])) {
          $data['prior_company_wwid'] = (string) $prefs['prior_company_wwid'];
        }
        if ($data['prior_company_email'] === '' && isset($prefs['prior_company_email'])) {
          $data['prior_company_email'] = (string) $prefs['prior_company_email'];
        }
        if ($data['phone_device_type'] === '' && isset($prefs['phone_device_type'])) {
          $data['phone_device_type'] = (string) $prefs['phone_device_type'];
        }

        if ($data['salary_expectation'] === '') {
          foreach (['salary_expectation', 'salary_change_minimum', 'salary_min', 'expected_salary', 'desired_salary'] as $k) {
            if (!empty($prefs[$k])) {
              $data['salary_expectation'] = (string) $prefs[$k];
              break;
            }
          }
        }

        if ($data['years_experience'] === '') {
          foreach (['years_experience', 'experience_years', 'relevant_years_experience'] as $k) {
            if (!empty($prefs[$k])) {
              $data['years_experience'] = (string) $prefs[$k];
              break;
            }
          }
        }

        if ($data['willing_to_relocate'] === '' && array_key_exists('relocation_willing', $prefs)) {
          $v = $prefs['relocation_willing'];
          $data['willing_to_relocate'] = is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v;
        }

        if ($data['english_proficiency'] === '') {
          foreach (['english_proficiency', 'language_proficiency_english', 'english_level'] as $k) {
            if (!empty($prefs[$k])) {
              $data['english_proficiency'] = (string) $prefs[$k];
              break;
            }
          }
        }

        if ($data['restrictive_agreement'] === '') {
          foreach (['restrictive_agreement', 'non_compete_agreement', 'agreement_restriction'] as $k) {
            if (!empty($prefs[$k])) {
              $data['restrictive_agreement'] = (string) $prefs[$k];
              break;
            }
          }
        }

        $this->applyPrimaryExperienceData($data, $experience);
        $this->applyEducationData($data, $education);
        $this->finalizeProfileData($data, $demographics, $contact, $row);
      }
    }
    catch (\Throwable $e) {
      // Non-fatal — continue with defaults.
    }

    return $data;
  }

  private function getDefaultProfileData(): array {
    return [
      'full_name'         => '',
      'first_name'        => '',
      'last_name'         => '',
      'email'             => '',
      'phone'             => '',
      'city'              => '',
      'state'             => '',
      'country'           => '',
      'linkedin'          => '',
      'eeo_gender'        => '',
      'eeo_ethnicity'     => '',
      'eeo_veteran'       => '',
      'disability_status' => '',
      'work_authorized_us'    => '',
      'requires_sponsorship'  => '',
      'age_18_or_older'       => '',
      'hear_about_us'         => '',
      'prior_company_employment' => '',
      'prior_company_wwid'    => '',
      'prior_company_email'   => '',
      'phone_device_type'     => '',
      'experience_job_title'  => '',
      'experience_company'    => '',
      'experience_from'       => '',
      'experience_to'         => '',
      'experience_role_description' => '',
      'experience2_job_title' => '',
      'experience2_company'   => '',
      'experience2_from'      => '',
      'experience2_to'        => '',
      'experience2_role_description' => '',
      'experience3_job_title' => '',
      'experience3_company'   => '',
      'experience3_from'      => '',
      'experience3_to'        => '',
      'experience3_role_description' => '',
      'work_experience_entries' => [],
      'education_school'      => '',
      'education_degree'      => '',
      'education_end_date'    => '',
      'education2_school'     => '',
      'education2_degree'     => '',
      'education2_end_date'   => '',
      'education3_school'     => '',
      'education3_degree'     => '',
      'education3_end_date'   => '',
      'education_entries'     => [],
      'salary_expectation'    => '',
      'years_experience'      => '',
      'willing_to_relocate'   => '',
      'english_proficiency'   => '',
      'restrictive_agreement' => '',
    ];
  }

  private function loadJobSeekerRow(int $uid): ?array {
    $row = $this->database->select('jobhunter_job_seeker', 'j')
      ->fields('j', [
        'full_name', 'contact_email', 'contact_phone',
        'location_city', 'location_state', 'country', 'linkedin_url',
        'eeo_gender', 'eeo_ethnicity', 'eeo_veteran', 'eeo_disability',
        'work_authorized_us', 'requires_sponsorship', 'age_18_or_older',
        'consolidated_profile_json',
      ])
      ->condition('uid', $uid)
      ->execute()
      ->fetchAssoc();

    return $row ?: NULL;
  }

  private function applyPrimaryExperienceData(array &$data, array $experience): void {
    if (empty($experience)) {
      return;
    }

    $normalized = [];
    foreach ($experience as $row) {
      if (!is_array($row)) {
        continue;
      }
      $entry = $this->normalizeExperienceEntry($row);
      if (implode('', $entry) === '') {
        continue;
      }
      $normalized[] = $entry;
      if (count($normalized) >= 3) {
        break;
      }
    }

    if (empty($normalized)) {
      return;
    }

    $data['work_experience_entries'] = $normalized;

    $exp0 = $normalized[0];
    if (empty($data['experience_job_title']) && $exp0['job_title'] !== '') {
      $data['experience_job_title'] = $exp0['job_title'];
    }
    if (empty($data['experience_company']) && $exp0['company'] !== '') {
      $data['experience_company'] = $exp0['company'];
    }
    if (empty($data['experience_from']) && $exp0['from'] !== '') {
      $data['experience_from'] = $exp0['from'];
    }
    if (empty($data['experience_to']) && $exp0['to'] !== '') {
      $data['experience_to'] = $exp0['to'];
    }
    if (empty($data['experience_role_description']) && $exp0['role_description'] !== '') {
      $data['experience_role_description'] = $exp0['role_description'];
    }

    if (!empty($normalized[1])) {
      $exp1 = $normalized[1];
      $data['experience2_job_title'] = $exp1['job_title'];
      $data['experience2_company'] = $exp1['company'];
      $data['experience2_from'] = $exp1['from'];
      $data['experience2_to'] = $exp1['to'];
      $data['experience2_role_description'] = $exp1['role_description'];
    }

    if (!empty($normalized[2])) {
      $exp2 = $normalized[2];
      $data['experience3_job_title'] = $exp2['job_title'];
      $data['experience3_company'] = $exp2['company'];
      $data['experience3_from'] = $exp2['from'];
      $data['experience3_to'] = $exp2['to'];
      $data['experience3_role_description'] = $exp2['role_description'];
    }
  }

  private function normalizeExperienceEntry(array $row): array {
    $description = '';
    foreach (['role_description', 'description', 'summary', 'responsibilities', 'highlights'] as $k) {
      if (!empty($row[$k])) {
        $description = (string) $row[$k];
        break;
      }
    }

    return [
      'job_title' => (string) ($row['title'] ?? ''),
      'company' => (string) ($row['company'] ?? ''),
      'from' => (string) ($row['start_date'] ?? ''),
      'to' => (string) ($row['end_date'] ?? ''),
      'role_description' => $description,
    ];
  }

  private function applyEducationData(array &$data, array $education): void {
    if (empty($education)) {
      return;
    }

    $normalized = [];
    foreach ($education as $row) {
      if (!is_array($row)) {
        continue;
      }
      $entry = $this->normalizeEducationEntry($row);
      if (implode('', $entry) === '') {
        continue;
      }
      if ($this->hasDuplicateEducationEntry($normalized, $entry)) {
        continue;
      }
      $normalized[] = $entry;
      if (count($normalized) >= self::MAX_EDUCATION_ENTRIES) {
        break;
      }
    }

    if (empty($normalized)) {
      return;
    }

    $data['education_entries'] = $normalized;

    foreach ($normalized as $index => $entry) {
      $this->applyIndexedEducationEntry($data, $entry, $index);
    }
  }

  private function normalizeEducationEntry(array $row): array {
    return [
      'school' => trim((string) ($row['institution'] ?? $row['school'] ?? '')),
      'degree' => trim((string) ($row['degree'] ?? '')),
      'end_date' => trim((string) ($row['end_date'] ?? $row['graduation_date'] ?? '')),
    ];
  }

  private function applyIndexedEducationEntry(array &$data, array $entry, int $index): void {
    if ($index === 0) {
      $data['education_school'] = $entry['school'];
      $data['education_degree'] = $entry['degree'];
      $data['education_end_date'] = $entry['end_date'];
      return;
    }

    $suffix = $index + 1;
    $data["education{$suffix}_school"] = $entry['school'];
    $data["education{$suffix}_degree"] = $entry['degree'];
    $data["education{$suffix}_end_date"] = $entry['end_date'];
  }

  private function hasDuplicateEducationEntry(array $existing, array $candidate): bool {
    $normalize = static function (string $value): string {
      return strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    };

    foreach ($existing as $entry) {
      $sameSchool = $normalize((string) ($entry['school'] ?? '')) === $normalize((string) ($candidate['school'] ?? ''));
      $sameDegree = $normalize((string) ($entry['degree'] ?? '')) === $normalize((string) ($candidate['degree'] ?? ''));
      $sameEndDate = $normalize((string) ($entry['end_date'] ?? '')) === $normalize((string) ($candidate['end_date'] ?? ''));
      if ($sameSchool && $sameDegree && $sameEndDate) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function finalizeProfileData(array &$data, array $demographics, array $contact, array $row): void {
    if ($data['eeo_gender'] === '' && isset($demographics['gender'])) {
      $data['eeo_gender'] = (string) $demographics['gender'];
    }
    if ($data['eeo_ethnicity'] === '' && isset($demographics['race_ethnicity'])) {
      $data['eeo_ethnicity'] = (string) $demographics['race_ethnicity'];
    }
    if ($data['eeo_veteran'] === '' && isset($demographics['veteran_status'])) {
      $data['eeo_veteran'] = (string) $demographics['veteran_status'];
    }
    if ($data['disability_status'] === '' && isset($demographics['disability_status'])) {
      $data['disability_status'] = (string) $demographics['disability_status'];
    }

    if ($data['full_name']) {
      $parts = preg_split('/\s+/', trim($data['full_name']));
      $data['first_name'] = $parts[0] ?? '';
      $data['last_name']  = implode(' ', array_slice($parts, 1));
    }

    if (!empty($row['linkedin_url'])) {
      $data['linkedin'] = (string) $row['linkedin_url'];
    }
    elseif (!empty($contact['linkedin'])) {
      $data['linkedin'] = (string) $contact['linkedin'];
    }

    $data['work_authorized_us'] = $this->normalizeYesNo($data['work_authorized_us']);
    $data['requires_sponsorship'] = $this->normalizeYesNo($data['requires_sponsorship']);
    $data['age_18_or_older'] = $this->normalizeYesNo($data['age_18_or_older']);
    $data['prior_company_employment'] = $this->normalizeYesNo($data['prior_company_employment']);
    $data['willing_to_relocate'] = $this->normalizeYesNo($data['willing_to_relocate']);
    $data['restrictive_agreement'] = $this->normalizeYesNo($data['restrictive_agreement']);
    $data['phone_device_type'] = $this->normalizePhoneDeviceType($data['phone_device_type']);
    $data['eeo_gender'] = $this->normalizeGender($data['eeo_gender']);
    $data['eeo_ethnicity'] = $this->normalizeEthnicity($data['eeo_ethnicity']);
    $data['eeo_veteran'] = $this->normalizeVeteran($data['eeo_veteran']);
    $data['disability_status'] = $this->normalizeDisability($data['disability_status']);
  }

  private function normalizeYesNo(string $value): string {
    $v = strtolower(trim($value));
    if (in_array($v, ['yes', 'y', 'true', '1'], TRUE)) {
      return 'Yes';
    }
    if (in_array($v, ['no', 'n', 'false', '0'], TRUE)) {
      return 'No';
    }
    return trim($value);
  }

  private function normalizeGender(string $value): string {
    $v = strtolower(trim($value));
    return match ($v) {
      'male' => 'Male',
      'female' => 'Female',
      'non_binary', 'non-binary' => 'Non-binary',
      'prefer_not_to_say' => 'Prefer not to say',
      default => trim($value),
    };
  }

  private function normalizeEthnicity(string $value): string {
    $v = strtolower(trim($value));
    return match ($v) {
      'american_indian' => 'American Indian or Alaska Native',
      'asian' => 'Asian',
      'black' => 'Black or African American',
      'hispanic' => 'Hispanic or Latino',
      'native_hawaiian' => 'Native Hawaiian or Other Pacific Islander',
      'white' => 'White',
      'two_or_more' => 'Two or More Races',
      'prefer_not_to_say' => 'Prefer not to say',
      default => trim($value),
    };
  }

  private function normalizeVeteran(string $value): string {
    $v = strtolower(trim($value));
    return match ($v) {
      'not_veteran' => 'I am not a protected veteran',
      'veteran' => 'I identify as one or more of the classifications of protected veteran',
      'prefer_not_to_say' => 'Prefer not to say',
      default => trim($value),
    };
  }

  private function normalizeDisability(string $value): string {
    $v = strtolower(trim($value));
    return match ($v) {
      'no_disability' => 'No, I do not have a disability',
      'yes_disability' => 'Yes, I have a disability (or previously had a disability)',
      'prefer_not_to_say' => 'Prefer not to say',
      default => trim($value),
    };
  }

  private function normalizePhoneDeviceType(string $value): string {
    $v = strtolower(trim($value));
    return match ($v) {
      'mobile', 'cell', 'cell phone' => 'Mobile',
      'home', 'home phone' => 'Home',
      'work', 'office', 'work phone' => 'Work',
      'other' => 'Other',
      default => trim($value),
    };
  }

}
