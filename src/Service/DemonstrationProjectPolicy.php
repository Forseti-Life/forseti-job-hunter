<?php

namespace Drupal\job_hunter\Service;

/**
 * Enforces the resume demonstration-project inclusion contract.
 */
final class DemonstrationProjectPolicy {

  /**
   * Keep only projects with valid HTTP(S) URLs.
   *
   * When source projects are supplied, output URLs must already exist in that
   * source list. This prevents resume tailoring from turning professional
   * achievements into invented demonstration projects.
   *
   * @param mixed $projects
   *   Candidate demonstration projects.
   * @param array|null $source_projects
   *   Optional source-profile projects whose URLs form the allowlist.
   *
   * @return array
   *   Canonical projects keyed sequentially and deduplicated by URL.
   */
  public static function filter($projects, ?array $source_projects = NULL): array {
    if (!is_array($projects)) {
      return [];
    }

    $allowed_projects = NULL;
    if ($source_projects !== NULL) {
      $allowed_projects = [];
      foreach ($source_projects as $source_project) {
        if (!is_array($source_project)) {
          continue;
        }
        $source_url = self::normalizeUrl($source_project['url'] ?? NULL);
        if ($source_url !== NULL) {
          $source_candidate = self::normalizeProject($source_project, $source_url);
          $allowed_projects[$source_url] = isset($allowed_projects[$source_url])
            ? self::mergeDuplicate($allowed_projects[$source_url], $source_candidate)
            : $source_candidate;
        }
      }
    }

    $accepted = [];
    foreach ($projects as $project) {
      if (!is_array($project)) {
        continue;
      }

      $url = self::normalizeUrl($project['url'] ?? NULL);
      if ($url === NULL || ($allowed_projects !== NULL && !isset($allowed_projects[$url]))) {
        continue;
      }

      // When tailoring against a source profile, the model may select a
      // project by URL but may not rewrite it into a professional initiative.
      $candidate = $allowed_projects !== NULL
        ? $allowed_projects[$url]
        : self::normalizeProject($project, $url);

      if (!isset($accepted[$url])) {
        $accepted[$url] = $candidate;
        continue;
      }

      $accepted[$url] = self::mergeDuplicate($accepted[$url], $candidate);
    }

    return array_values($accepted);
  }

  /**
   * Normalize and validate an HTTP(S) project URL.
   */
  private static function normalizeUrl($url): ?string {
    if (!is_string($url)) {
      return NULL;
    }

    $url = trim($url);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
      return NULL;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      return NULL;
    }

    return rtrim($url, '/');
  }

  /**
   * Normalize a project's technology list.
   */
  private static function normalizeTechnologies($technologies): array {
    if (!is_array($technologies)) {
      return [];
    }

    $normalized = [];
    foreach ($technologies as $technology) {
      if (!is_string($technology)) {
        continue;
      }
      $technology = trim($technology);
      if ($technology !== '') {
        $normalized[mb_strtolower($technology)] = $technology;
      }
    }
    return array_values($normalized);
  }

  /**
   * Normalize one accepted project.
   */
  private static function normalizeProject(array $project, string $url): array {
    return [
      'name' => trim((string) ($project['name'] ?? '')),
      'description' => trim((string) ($project['description'] ?? '')),
      'url' => $url,
      'technologies' => self::normalizeTechnologies($project['technologies'] ?? []),
    ];
  }

  /**
   * Merge duplicate entries that point to the same project URL.
   */
  private static function mergeDuplicate(array $existing, array $candidate): array {
    if (mb_strlen($candidate['name']) > mb_strlen($existing['name'])) {
      $existing['name'] = $candidate['name'];
    }
    if (mb_strlen($candidate['description']) > mb_strlen($existing['description'])) {
      $existing['description'] = $candidate['description'];
    }

    $technologies = array_merge($existing['technologies'], $candidate['technologies']);
    $existing['technologies'] = self::normalizeTechnologies($technologies);
    return $existing;
  }

}
