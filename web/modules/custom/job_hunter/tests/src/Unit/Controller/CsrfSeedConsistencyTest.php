<?php

namespace Drupal\Tests\job_hunter\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Static analysis test: CSRF token seeds in controllers must match route paths.
 *
 * Background: FR-RB-01 (2026-04-08) — controllers used custom seeds like
 * 'job_apply_{id}' instead of route-path seeds like
 * 'jobhunter/my-jobs/{id}/applied', causing CSRF validation failures and 7
 * commits of rework.
 *
 * Rule: any csrfToken()->get(...) call whose seed contains a '/' must match
 * a route path declared in job_hunter.routing.yml (leading slash stripped,
 * {param} placeholders allowed).
 *
 * Seeds without '/' are treated as intentional custom/form tokens and are
 * not validated here.
 *
 * @group job_hunter
 */
class CsrfSeedConsistencyTest extends TestCase {

  /** Routing YAML path relative to this file's module root. */
  private const ROUTING_FILE = __DIR__ . '/../../../../job_hunter.routing.yml';

  /** Controller source directory. */
  private const CONTROLLER_DIR = __DIR__ . '/../../../../src/Controller';

  /**
   * Returns all route paths from job_hunter.routing.yml, leading slash stripped.
   *
   * @return string[]
   */
  private function getRoutePaths(): array {
    $content = file_get_contents(self::ROUTING_FILE);
    preg_match_all("/^\s+path:\s+'([^']+)'/m", $content, $matches);
    return array_map(static fn($p) => ltrim($p, '/'), $matches[1]);
  }

  /**
   * Normalizes a route path for pattern comparison.
   *
   * Replaces {param} placeholders with the literal string '[{param}]' so we
   * can do exact-match comparisons without regex complexity.
   *
   * We use a canonical form: replace every {word} with '__PARAM__'.
   */
  private function normalizeRoutePath(string $routePath): string {
    return preg_replace('/\{[^}]+\}/', '__PARAM__', $routePath);
  }

  /**
   * Scans all controller files and returns lines that call csrfToken()->get().
   *
   * @return array<array{file: string, line: int, raw: string, literals: string[]}>
   */
  private function getControllerCsrfSeeds(): array {
    $seeds = [];
    $files = glob(self::CONTROLLER_DIR . '/*.php') ?: [];
    foreach ($files as $file) {
      $lines = file($file, FILE_IGNORE_NEW_LINES);
      foreach ($lines as $lineno => $line) {
        if (strpos($line, 'csrfToken()->get(') === FALSE) {
          continue;
        }
        // Only look at the substring starting at csrfToken()->get( to avoid
        // capturing array-key literals that appear earlier on the same line.
        $callStart = strpos($line, 'csrfToken()->get(');
        $seedSubstr = substr($line, $callStart);
        // Extract every single-quoted string literal in the seed argument.
        preg_match_all("/'([^'\\\\]*)'/", $seedSubstr, $stringMatches);
        $seeds[] = [
          'file'     => basename($file),
          'line'     => $lineno + 1,
          'raw'      => trim($line),
          'literals' => $stringMatches[1],
        ];
      }
    }
    return $seeds;
  }

  /**
   * Builds a normalized seed pattern from string literals found in the call.
   *
   * Joins the literal segments with '__PARAM__' (representing a dynamic value),
   * then trims any leading/trailing segment-joining artefacts.
   *
   * Returns NULL if no literal contains '/' (not a route-path seed).
   */
  private function buildSeedPattern(array $literals): ?string {
    $hasSlash = FALSE;
    foreach ($literals as $lit) {
      if (strpos($lit, '/') !== FALSE) {
        $hasSlash = TRUE;
        break;
      }
    }
    if (!$hasSlash) {
      return NULL;
    }
    // Join segments. If only one literal it becomes the whole pattern.
    // Multiple literals: literal[0] + __PARAM__ + literal[1] + ...
    return implode('__PARAM__', $literals);
  }

  /**
   * Asserts every route-path-like CSRF seed matches a route path in routing.yml.
   */
  public function testCsrfSeedsMatchRoutePaths(): void {
    $routePaths = $this->getRoutePaths();
    $this->assertNotEmpty($routePaths, 'job_hunter.routing.yml must contain at least one path.');

    $normalizedRoutes = array_map([$this, 'normalizeRoutePath'], $routePaths);

    $seeds = $this->getControllerCsrfSeeds();
    $this->assertNotEmpty($seeds, 'Expected to find at least one csrfToken()->get() call in src/Controller/.');

    $failures = [];
    foreach ($seeds as $info) {
      $pattern = $this->buildSeedPattern($info['literals']);
      if ($pattern === NULL) {
        // No '/' found — intentional custom/form token, skip.
        continue;
      }

      $matched = in_array($pattern, $normalizedRoutes, TRUE);
      if (!$matched) {
        $failures[] = sprintf(
          '%s line %d: seed pattern "%s" does not match any route path.%sRaw call: %s',
          $info['file'],
          $info['line'],
          $pattern,
          PHP_EOL . '  ',
          $info['raw']
        );
      }
    }

    $this->assertEmpty(
      $failures,
      "CSRF seeds with '/' must match a route path in job_hunter.routing.yml.\n\n"
        . implode("\n\n", $failures)
        . "\n\nFix: use the route path (without leading slash) as the seed, e.g., "
        . "'jobhunter/my-jobs/' . \$id . '/applied' for route '/jobhunter/my-jobs/{job_id}/applied'."
    );
  }

  /**
   * Asserts the routing file and controller directory exist and are readable.
   *
   * Guards against misconfigured paths silently producing vacuous passes.
   */
  public function testRequiredPathsExist(): void {
    $this->assertFileExists(self::ROUTING_FILE, 'job_hunter.routing.yml must exist at the expected path.');
    $this->assertDirectoryExists(self::CONTROLLER_DIR, 'src/Controller must exist at the expected path.');
    $this->assertNotEmpty(
      glob(self::CONTROLLER_DIR . '/*.php') ?: [],
      'src/Controller must contain at least one PHP file.'
    );
  }

}
