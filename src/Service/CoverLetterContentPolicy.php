<?php

namespace Drupal\job_hunter\Service;

/**
 * Resolves cover-letter templates and rejects unresolved variables.
 */
final class CoverLetterContentPolicy {

  /**
   * Replace known inline variables and remove unavailable header fields.
   */
  public static function resolvePlaceholders(
    string $content,
    string $job_title,
    string $company,
    string $candidate_name,
  ): string {
    if ($company === '') {
      $content = preg_replace('/^[ \t]*\[Company Name\][ \t]*\R?/mi', '', $content);
    }
    $content = str_ireplace(
      ['the [Position Title] role', '[Position Title] role'],
      [
        $job_title !== '' ? "the {$job_title} role" : 'this role',
        $job_title !== '' ? "{$job_title} role" : 'this role',
      ],
      $content
    );
    $replacements = [
      '[Position Title]' => $job_title !== '' ? $job_title : 'role',
      '[Company Name]' => $company !== '' ? $company : 'the organization',
      '[Name]' => $candidate_name,
      '[Your Name]' => $candidate_name,
    ];
    $content = str_ireplace(array_keys($replacements), array_values($replacements), $content);

    // A date or recipient mailing block is optional and must never be emitted
    // as an unresolved variable.
    $content = preg_replace(
      '/^[ \t]*(?:\[Date\]|\[Hiring Manager Name\]|\[Company Address\])[ \t]*\R?/mi',
      '',
      $content
    );
    $content = preg_replace("/\R{3,}/", "\n\n", $content);
    return trim((string) $content);
  }

  /**
   * Fail when any bracketed template variable remains.
   */
  public static function assertNoPlaceholders(string $content): void {
    if (preg_match('/\[([^\]\r\n]+)\]/', $content, $match) === 1) {
      throw new \UnexpectedValueException(sprintf(
        'Cover letter contains unresolved placeholder [%s].',
        trim($match[1])
      ));
    }
  }

}
