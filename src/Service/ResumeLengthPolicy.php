<?php

namespace Drupal\job_hunter\Service;

/**
 * Single authority for tailored-resume length and recency limits.
 *
 * Tailored resumes must fit within five pages and focus on the most recent
 * ten years of professional experience.
 */
class ResumeLengthPolicy {

  public const MAX_PAGES = 5;
  public const RECENT_EXPERIENCE_YEARS = 10;
  public const MAX_RECENT_EXPERIENCE_ENTRIES = 6;
  public const MAX_EARLIER_EXPERIENCE_ENTRIES = 2;
  public const MAX_RECENT_ACHIEVEMENTS = 4;
  public const MAX_EARLIER_ACHIEVEMENTS = 1;


  /**
   * Select the experience entries that fit the tailored resume history policy.
   */
  public function selectExperience(array $entries): array {
    $ranked = [];

    foreach ($entries as $index => $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $ranked[] = [
        'entry' => $entry,
        'index' => $index,
        'end_timestamp' => $this->experienceEndTimestamp($entry),
      ];
    }

    usort($ranked, static function (array $left, array $right): int {
      $date_order = $right['end_timestamp'] <=> $left['end_timestamp'];
      return $date_order !== 0 ? $date_order : $left['index'] <=> $right['index'];
    });

    $cutoff = strtotime('-' . self::RECENT_EXPERIENCE_YEARS . ' years');
    $recent = [];
    $earlier = [];

    foreach ($ranked as $item) {
      $is_recent = $item['end_timestamp'] === PHP_INT_MAX || $item['end_timestamp'] >= $cutoff;
      $selection = [
        'entry' => $item['entry'],
        'is_recent' => $is_recent,
      ];

      if ($is_recent && count($recent) < self::MAX_RECENT_EXPERIENCE_ENTRIES) {
        $recent[] = $selection;
      }
      elseif (count($earlier) < self::MAX_EARLIER_EXPERIENCE_ENTRIES) {
        // Recent roles beyond the recent cap still outrank older history, so
        // they take the concise earlier-history slots first.
        $selection['is_recent'] = FALSE;
        $earlier[] = $selection;
      }
    }

    return array_merge($recent, $earlier);
  }

  /**
   * Resolve an experience entry's latest end date for recency ordering.
   */
  private function experienceEndTimestamp(array $entry): int {
    $end_dates = [];

    if (!empty($entry['positions']) && is_array($entry['positions'])) {
      foreach ($entry['positions'] as $position) {
        if (is_array($position)) {
          $end_dates[] = $this->experienceEndDate($position);
        }
      }
    }

    $end_dates[] = $this->experienceEndDate($entry);
    if (in_array(PHP_INT_MAX, $end_dates, TRUE)) {
      return PHP_INT_MAX;
    }

    return max($end_dates);
  }

  /**
   * Convert an experience end date into a timestamp.
   */
  private function experienceEndDate(array $entry): int {
    $range = $this->parseDateRange(
      $entry['tenure'] ?? $entry['duration'] ?? NULL,
      $entry['start_date'] ?? NULL,
      $entry['end_date'] ?? NULL
    );
    $end_date = trim((string) ($range['end_date'] ?? ''));

    if ($end_date === '' || preg_match('/^(present|current|now)$/i', $end_date)) {
      return PHP_INT_MAX;
    }

    $timestamp = strtotime($end_date);
    if ($timestamp !== FALSE) {
      return $timestamp;
    }

    if (preg_match_all('/\b(?:19|20)\d{2}\b/', $end_date, $matches) && $matches[0] !== []) {
      $year = end($matches[0]);
      return strtotime($year . '-12-31');
    }

    return PHP_INT_MAX;
  }

  /**
   * Apply deterministic content limits that keep tailored resumes near five pages.
   */
  public function apply(array $resume): array {
    if (!isset($resume['tailoring_metadata']) || !is_array($resume['tailoring_metadata'])) {
      $resume['tailoring_metadata'] = [];
    }
    $resume['tailoring_metadata']['document_constraints'] = [
      'max_pages' => self::MAX_PAGES,
      'recent_experience_years' => self::RECENT_EXPERIENCE_YEARS,
    ];

    $selected_experience = $this->selectExperience($resume['professional_experience'] ?? []);
    $resume['professional_experience'] = [];

    foreach ($selected_experience as $selection) {
      $entry = $selection['entry'];
      $is_recent = $selection['is_recent'];
      $achievement_limit = $is_recent
        ? self::MAX_RECENT_ACHIEVEMENTS
        : self::MAX_EARLIER_ACHIEVEMENTS;
      $category_limit = $is_recent ? 2 : 1;
      $remaining_achievements = $achievement_limit;
      $categories = [];

      foreach (array_slice($entry['responsibility_categories'] ?? [], 0, $category_limit) as $category) {
        if (!is_array($category) || $remaining_achievements === 0) {
          continue;
        }

        $achievements = array_slice(
          $category['achievements'] ?? [],
          0,
          $remaining_achievements
        );
        foreach ($achievements as &$achievement) {
          if (is_array($achievement) && isset($achievement['text'])) {
            $achievement['text'] = $this->limitWords((string) $achievement['text'], 32);
          }
        }
        unset($achievement);

        if ($achievements !== []) {
          $category['achievements'] = $achievements;
          $categories[] = $category;
          $remaining_achievements -= count($achievements);
        }
      }

      $entry['company_context'] = $this->limitWords(
        (string) ($entry['company_context'] ?? ''),
        $is_recent ? 45 : 20
      );
      $entry['responsibility_categories'] = $categories;
      $resume['professional_experience'][] = $entry;
    }

    $resume['strategic_differentiators'] = array_slice($resume['strategic_differentiators'] ?? [], 0, 4);
    foreach ($resume['strategic_differentiators'] as &$differentiator) {
      if (is_array($differentiator) && isset($differentiator['description'])) {
        $differentiator['description'] = $this->limitWords((string) $differentiator['description'], 25);
      }
    }
    unset($differentiator);

    $resume['demonstration_projects'] = array_slice($resume['demonstration_projects'] ?? [], 0, 2);
    foreach ($resume['demonstration_projects'] as &$project) {
      if (is_array($project) && isset($project['description'])) {
        $project['description'] = $this->limitWords((string) $project['description'], 30);
      }
    }
    unset($project);

    $resume['education'] = array_slice($resume['education'] ?? [], 0, 3);
    $resume['certifications'] = array_slice($resume['certifications'] ?? [], 0, 6);
    $resume['publications'] = array_slice($resume['publications'] ?? [], 0, 3);
    $resume['awards_and_honors'] = array_slice($resume['awards_and_honors'] ?? [], 0, 3);
    $resume['languages'] = array_slice($resume['languages'] ?? [], 0, 4);

    if (!empty($resume['executive_profile']['summary'])) {
      $resume['executive_profile']['summary'] = $this->limitWords(
        (string) $resume['executive_profile']['summary'],
        90
      );
    }

    if (!empty($resume['technical_expertise']['categories'])) {
      $resume['technical_expertise']['categories'] = array_slice(
        $resume['technical_expertise']['categories'],
        0,
        6
      );
      foreach ($resume['technical_expertise']['categories'] as &$category) {
        if (is_array($category)) {
          $category['skills'] = array_slice($category['skills'] ?? [], 0, 10);
        }
      }
      unset($category);
    }

    if (!empty($resume['consulting_practice']['engagements'])) {
      $resume['consulting_practice']['engagements'] = array_slice(
        $resume['consulting_practice']['engagements'],
        0,
        2
      );
      foreach ($resume['consulting_practice']['engagements'] as &$engagement) {
        if (is_array($engagement) && isset($engagement['description'])) {
          $engagement['description'] = $this->limitWords((string) $engagement['description'], 30);
        }
      }
      unset($engagement);
    }

    if (!empty($resume['leadership_philosophy']) && is_string($resume['leadership_philosophy'])) {
      $resume['leadership_philosophy'] = $this->limitWords(
        $resume['leadership_philosophy'],
        30
      );
    }

    return $resume;
  }

  /**
   * Keep whole sentences that fit a word budget.
   *
   * The first sentence is always kept intact so text stays readable; the
   * generation prompts own brevity and this only drops trailing sentences.
   */
  private function limitWords(string $text, int $limit): string {
    $text = trim($text);
    if ($this->wordCount($text) <= $limit) {
      return $text;
    }

    $kept = [];
    $count = 0;
    foreach (preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $sentence) {
      $words = $this->wordCount($sentence);
      if ($kept !== [] && $count + $words > $limit) {
        break;
      }
      $kept[] = $sentence;
      $count += $words;
    }

    return implode(' ', $kept);
  }

  /**
   * Count whitespace-separated words.
   */
  private function wordCount(string $text): int {
    return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
  }

  /**
   * Parse a date-range string into start_date/end_date values.
   */
  public function parseDateRange($tenure, $start_date = NULL, $end_date = NULL): array {
    $start = trim((string) ($start_date ?? ''));
    $end = trim((string) ($end_date ?? ''));

    if ($tenure !== NULL && $tenure !== '') {
      $tenure_text = trim((string) $tenure);
      if (preg_match('/^(.*?)(?:\s*[–-]\s*|\s+to\s+)(.*)$/u', $tenure_text, $matches)) {
        $start = trim((string) ($matches[1] ?? $start));
        $end = trim((string) ($matches[2] ?? $end));
      }
    }

    if ($start === '' && !empty($start_date)) {
      $start = trim((string) $start_date);
    }
    if ($end === '' && !empty($end_date)) {
      $end = trim((string) $end_date);
    }

    return [
      'start_date' => $start,
      'end_date' => $end === '' ? 'Present' : $end,
    ];
  }

}
