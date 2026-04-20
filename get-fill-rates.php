#!/usr/bin/env php
<?php

// Simple database connection without Drupal bootstrap
$mysqli = new mysqli('127.0.0.1', 'drupal_user', 'drupal_secure_password', 'forseti_dev');

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$result = $mysqli->query("SELECT data FROM nfr_questionnaire WHERE uid > 2");

if (!$result) {
    die("Query failed: " . $mysqli->error);
}

$total_records = 0;
$field_counts = [];

while ($row = $result->fetch_assoc()) {
    $total_records++;
    $data = json_decode($row['data'], true);
    
    if (!$data) {
        continue;
    }
    
    // Demographics
    if (isset($data['demographics']['education_level']) && !empty($data['demographics']['education_level'])) {
        $field_counts['demographics.education_level'] = ($field_counts['demographics.education_level'] ?? 0) + 1;
    }
    if (isset($data['demographics']['marital_status']) && !empty($data['demographics']['marital_status'])) {
        $field_counts['demographics.marital_status'] = ($field_counts['demographics.marital_status'] ?? 0) + 1;
    }
    if (isset($data['demographics']['race_ethnicity'])) {
        $race_count = 0;
        foreach ($data['demographics']['race_ethnicity'] as $key => $val) {
            if ($val !== 0 && $val !== '0' && !empty($val)) {
                $race_count++;
            }
        }
        if ($race_count > 0) {
            $field_counts['demographics.race_ethnicity'] = ($field_counts['demographics.race_ethnicity'] ?? 0) + 1;
        }
    }
    
    // Work History
    if (isset($data['work_history']['num_departments']) && $data['work_history']['num_departments'] > 0) {
        $field_counts['work_history.num_departments'] = ($field_counts['work_history.num_departments'] ?? 0) + 1;
    }
    if (isset($data['work_history']['departments']) && is_array($data['work_history']['departments'])) {
        $field_counts['work_history.departments'] = ($field_counts['work_history.departments'] ?? 0) + 1;
        
        $dept = $data['work_history']['departments'][0] ?? null;
        if ($dept) {
            if (!empty($dept['department_name'])) {
                $field_counts['work_history.department_name'] = ($field_counts['work_history.department_name'] ?? 0) + 1;
            }
            if (!empty($dept['state'])) {
                $field_counts['work_history.state'] = ($field_counts['work_history.state'] ?? 0) + 1;
            }
            if (!empty($dept['city'])) {
                $field_counts['work_history.city'] = ($field_counts['work_history.city'] ?? 0) + 1;
            }
            if (!empty($dept['start_date'])) {
                $field_counts['work_history.start_date'] = ($field_counts['work_history.start_date'] ?? 0) + 1;
            }
            if (isset($dept['num_jobs']) && $dept['num_jobs'] > 0) {
                $field_counts['work_history.num_jobs'] = ($field_counts['work_history.num_jobs'] ?? 0) + 1;
            }
        }
    }
    
    // Exposure
    if (isset($data['exposure']['afff_used']) && !empty($data['exposure']['afff_used'])) {
        $field_counts['exposure.afff_used'] = ($field_counts['exposure.afff_used'] ?? 0) + 1;
    }
    if (isset($data['exposure']['diesel_exhaust']) && !empty($data['exposure']['diesel_exhaust'])) {
        $field_counts['exposure.diesel_exhaust'] = ($field_counts['exposure.diesel_exhaust'] ?? 0) + 1;
    }
    if (isset($data['exposure']['major_incidents']) && !empty($data['exposure']['major_incidents'])) {
        $field_counts['exposure.major_incidents'] = ($field_counts['exposure.major_incidents'] ?? 0) + 1;
    }
    if (isset($data['exposure']['chemical_activities']) && is_array($data['exposure']['chemical_activities'])) {
        $has_activity = false;
        foreach ($data['exposure']['chemical_activities'] as $val) {
            if ($val === 1 || $val === '1' || $val === true) {
                $has_activity = true;
                break;
            }
        }
        if ($has_activity) {
            $field_counts['exposure.chemical_activities'] = ($field_counts['exposure.chemical_activities'] ?? 0) + 1;
        }
    }
    
    // Military
    if (isset($data['military']['served']) && !empty($data['military']['served'])) {
        $field_counts['military.served'] = ($field_counts['military.served'] ?? 0) + 1;
    }
    if (isset($data['military']['branch']) && !empty($data['military']['branch']) && $data['military']['served'] === 'yes') {
        $field_counts['military.branch'] = ($field_counts['military.branch'] ?? 0) + 1;
    }
    if (isset($data['military']['start_date']) && !empty($data['military']['start_date']) && $data['military']['served'] === 'yes') {
        $field_counts['military.start_date'] = ($field_counts['military.start_date'] ?? 0) + 1;
    }
    
    // Other Employment
    if (isset($data['other_employment']['had_other_jobs']) && !empty($data['other_employment']['had_other_jobs'])) {
        $field_counts['other_employment.had_other_jobs'] = ($field_counts['other_employment.had_other_jobs'] ?? 0) + 1;
    }
    
    // PPE
    $ppe_items = ['scba', 'turnout_coat', 'turnout_pants', 'gloves', 'helmet', 'boots', 'nomex_hood', 'wildland_clothing'];
    foreach ($ppe_items as $item) {
        if (isset($data['ppe'][$item]['ever_used']) && $data['ppe'][$item]['ever_used'] !== null) {
            $field_counts["ppe.{$item}.ever_used"] = ($field_counts["ppe.{$item}.ever_used"] ?? 0) + 1;
        }
    }
    if (isset($data['ppe']['scba_during_suppression']) && !empty($data['ppe']['scba_during_suppression'])) {
        $field_counts['ppe.scba_during_suppression'] = ($field_counts['ppe.scba_during_suppression'] ?? 0) + 1;
    }
    if (isset($data['ppe']['scba_during_overhaul']) && !empty($data['ppe']['scba_during_overhaul'])) {
        $field_counts['ppe.scba_during_overhaul'] = ($field_counts['ppe.scba_during_overhaul'] ?? 0) + 1;
    }
    
    // Decontamination
    $decon_fields = ['washed_hands_face', 'changed_gear_at_scene', 'showered_at_station', 'laundered_gear', 'used_wet_wipes'];
    foreach ($decon_fields as $field) {
        if (isset($data['decontamination'][$field]) && !empty($data['decontamination'][$field])) {
            $field_counts["decontamination.{$field}"] = ($field_counts["decontamination.{$field}"] ?? 0) + 1;
        }
    }
    if (isset($data['decontamination']['department_had_sops']) && !empty($data['decontamination']['department_had_sops'])) {
        $field_counts['decontamination.department_had_sops'] = ($field_counts['decontamination.department_had_sops'] ?? 0) + 1;
    }
    
    // Health
    if (isset($data['health']['cancer_diagnosed']) && !empty($data['health']['cancer_diagnosed'])) {
        $field_counts['health.cancer_diagnosed'] = ($field_counts['health.cancer_diagnosed'] ?? 0) + 1;
    }
    if (isset($data['health']['other_conditions']) && is_array($data['health']['other_conditions'])) {
        $has_condition = false;
        foreach ($data['health']['other_conditions'] as $val) {
            if ($val === 1 || $val === '1' || $val === true) {
                $has_condition = true;
                break;
            }
        }
        if ($has_condition) {
            $field_counts['health.other_conditions'] = ($field_counts['health.other_conditions'] ?? 0) + 1;
        }
    }
    
    // Lifestyle
    if (isset($data['lifestyle']['smoking_status']) && !empty($data['lifestyle']['smoking_status'])) {
        $field_counts['lifestyle.smoking_status'] = ($field_counts['lifestyle.smoking_status'] ?? 0) + 1;
    }
    if (isset($data['lifestyle']['alcohol_frequency']) && !empty($data['lifestyle']['alcohol_frequency'])) {
        $field_counts['lifestyle.alcohol_frequency'] = ($field_counts['lifestyle.alcohol_frequency'] ?? 0) + 1;
    }
    if (isset($data['lifestyle']['physical_activity_days']) && $data['lifestyle']['physical_activity_days'] !== '') {
        $field_counts['lifestyle.physical_activity_days'] = ($field_counts['lifestyle.physical_activity_days'] ?? 0) + 1;
    }
}

echo "\n=== NFR QUESTIONNAIRE FILL RATE ANALYSIS ===\n";
echo "Total Records: $total_records\n\n";

// Sort fields by section
ksort($field_counts);

$sections = [
    'demographics' => 'DEMOGRAPHICS (Section 1)',
    'work_history' => 'WORK HISTORY (Section 2)',
    'exposure' => 'EXPOSURE (Section 3)',
    'military' => 'MILITARY SERVICE (Section 4)',
    'other_employment' => 'OTHER EMPLOYMENT (Section 5)',
    'ppe' => 'PERSONAL PROTECTIVE EQUIPMENT (Section 6)',
    'decontamination' => 'DECONTAMINATION (Section 7)',
    'health' => 'HEALTH CONDITIONS (Section 8)',
    'lifestyle' => 'LIFESTYLE (Section 9)'
];

foreach ($sections as $section_key => $section_name) {
    echo "\n--- $section_name ---\n";
    
    foreach ($field_counts as $field => $count) {
        if (strpos($field, $section_key . '.') === 0) {
            $pct = round(($count / $total_records) * 100, 1);
            printf("  %-40s %3d / %3d (%5.1f%%)\n", $field, $count, $total_records, $pct);
        }
    }
}

echo "\n=== SUMMARY ===\n";
$total_fields = count($field_counts);
$fields_at_100 = 0;
$fields_below_100 = [];

foreach ($field_counts as $field => $count) {
    $pct = round(($count / $total_records) * 100, 1);
    if ($pct >= 100.0) {
        $fields_at_100++;
    } else {
        $fields_below_100[] = ['field' => $field, 'count' => $count, 'pct' => $pct];
    }
}

echo "Total Fields Analyzed: $total_fields\n";
echo "Fields at 100% Fill Rate: $fields_at_100\n";
echo "Fields Below 100%: " . count($fields_below_100) . "\n";

if (count($fields_below_100) > 0) {
    echo "\nFIELDS WITH INCOMPLETE DATA:\n";
    foreach ($fields_below_100 as $item) {
        printf("  %-40s %3d / %3d (%5.1f%%)\n", $item['field'], $item['count'], $total_records, $item['pct']);
    }
}

$mysqli->close();
