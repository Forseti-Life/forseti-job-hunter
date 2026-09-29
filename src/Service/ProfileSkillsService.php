<?php

namespace Drupal\job_hunter\Service;

/**
 * Single source of truth for profile skill lookup, skills gap, and skill adds.
 *
 * Consolidated profile technical expertise is stored in several shapes:
 * - Associative: {"Category Name": ["skill", ...]} (resume consolidation).
 * - Canonical list: {"categories": [{"name": ..., "skills": ["skill", ...]}]}.
 * - Numeric: {"0": {"category": ..., "skills": [{"name": ..., ...}]}}
 *   (skills added from the skills gap UI).
 * All shapes must be read so previously added/known skills are never
 * reported as gaps again.
 */
class ProfileSkillsService {

  /**
   * UI category key => stored category label for user-added skills.
   */
  private const CATEGORY_LABELS = [
    'technical' => 'Technical Skills',
    'soft' => 'Soft Skills',
    'domain' => 'Domain Expertise',
    'tools' => 'Tools & Platforms',
  ];

  /**
   * Returns every skill name in the profile, lowercased, trimmed, unique.
   */
  public function getProfileSkillNames(array $profile_json): array {
    $names = [];

    $technical = $profile_json['technical_expertise'] ?? [];
    if (is_array($technical)) {
      foreach ($technical as $key => $value) {
        if (!is_array($value)) {
          continue;
        }
        if ($key === 'categories') {
          foreach ($value as $category) {
            if (is_array($category)) {
              $this->collectNames($category['skills'] ?? [], $names);
            }
          }
        }
        elseif (array_key_exists('skills', $value)) {
          $this->collectNames($value['skills'], $names);
        }
        else {
          $this->collectNames($value, $names);
        }
      }
    }

    $this->collectNames($profile_json['skills'] ?? [], $names);
    $this->collectNames($profile_json['certifications'] ?? [], $names);

    return array_keys($names);
  }

  /**
   * Job skills not present in the profile, split into must/nice to have.
   *
   * @return array
   *   ['must_have' => [['skill', 'category']], 'nice_to_have' => [...]].
   */
  public function calculateSkillsGap(array $job_skills, array $profile_json): array {
    $user_skills = $this->getProfileSkillNames($profile_json);
    $gap = ['must_have' => [], 'nice_to_have' => []];
    $seen = [];

    $groups = [
      'must_have' => $job_skills['must_have'] ?? [],
      'nice_to_have' => array_merge(
        is_array($job_skills['nice_to_have'] ?? NULL) ? $job_skills['nice_to_have'] : [],
        is_array($job_skills['tech_stack'] ?? NULL) ? $job_skills['tech_stack'] : []
      ),
    ];

    foreach ($groups as $bucket => $items) {
      if (!is_array($items)) {
        continue;
      }
      foreach ($items as $item) {
        $name = trim((string) (is_array($item) ? ($item['skill'] ?? $item['name'] ?? '') : $item));
        $key = mb_strtolower($name);
        if ($name === '' || isset($seen[$key]) || $this->skillMatches($name, $user_skills)) {
          continue;
        }
        $seen[$key] = TRUE;
        $gap[$bucket][] = [
          'skill' => $name,
          'category' => is_array($item) ? ($item['category'] ?? 'technical') : 'technical',
        ];
      }
    }

    return $gap;
  }

  /**
   * TRUE if the profile already contains the skill (exact or fuzzy).
   */
  public function profileHasSkill(array $profile_json, string $skill_name): bool {
    return $this->skillMatches($skill_name, $this->getProfileSkillNames($profile_json));
  }

  /**
   * Adds a user-confirmed skill to the profile.
   *
   * @return bool
   *   TRUE if added, FALSE if the profile already had the skill.
   */
  public function addSkill(array &$profile_json, string $skill_name, string $category_key): bool {
    $skill_name = trim($skill_name);
    if ($skill_name === '') {
      throw new \InvalidArgumentException('Skill name is required.');
    }
    if ($this->profileHasSkill($profile_json, $skill_name)) {
      return FALSE;
    }

    if (!isset($profile_json['technical_expertise']) || !is_array($profile_json['technical_expertise'])) {
      $profile_json['technical_expertise'] = [];
    }
    $label = self::CATEGORY_LABELS[$category_key] ?? self::CATEGORY_LABELS['technical'];

    foreach ($profile_json['technical_expertise'] as $key => $entry) {
      if ($key !== 'categories' && is_array($entry) && ($entry['category'] ?? NULL) === $label) {
        $profile_json['technical_expertise'][$key]['skills'][] = [
          'name' => $skill_name,
          'proficiency' => 'intermediate',
        ];
        return TRUE;
      }
    }

    $profile_json['technical_expertise'][] = [
      'category' => $label,
      'skills' => [['name' => $skill_name, 'proficiency' => 'intermediate']],
    ];
    return TRUE;
  }

  /**
   * Adds skill names from a mixed list (strings or {name|skill}) to $names.
   */
  private function collectNames($list, array &$names): void {
    if (!is_array($list)) {
      return;
    }
    foreach ($list as $item) {
      $name = is_array($item) ? ($item['name'] ?? $item['skill'] ?? '') : $item;
      if (is_string($name) && ($name = mb_strtolower(trim($name))) !== '') {
        $names[$name] = TRUE;
      }
    }
  }

  /**
   * Exact or substring match in either direction.
   */
  private function skillMatches(string $skill_name, array $user_skills): bool {
    $normalized = mb_strtolower(trim($skill_name));
    if ($normalized === '') {
      return FALSE;
    }
    if (in_array($normalized, $user_skills, TRUE)) {
      return TRUE;
    }
    foreach ($user_skills as $user_skill) {
      // A more-specific profile skill satisfies a broader requirement
      // ("AI/ML" satisfies "AI"), but not the reverse.
      if ($this->containsWholeSkill($user_skill, $normalized)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * TRUE when $needle appears on letter/number boundaries in $haystack.
   */
  private function containsWholeSkill(string $haystack, string $needle): bool {
    if ($needle === '') {
      return FALSE;
    }
    return preg_match(
      '/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u',
      $haystack
    ) === 1;
  }

}
