<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Links newly parsed jobs to a user's recent matching saved-job record.
 */
class RecentJobDuplicateLinker {

  private const DUPLICATE_WINDOW_SECONDS = 31536000;

  public function __construct(
    protected Connection $database,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Removes new saved-job mappings when a recent user/title/company match exists.
   *
   * Archived mappings are intentionally included so a previously archived role
   * is not presented to the user as a new application opportunity.
   *
   * @return array<int, array{uid:int, job_id:int, job_title:string, company:string, similarity_score:int, is_exact_match:bool, archived:bool}>
   *   Relink decisions keyed as a list for audit metadata and logging.
   */
  public function linkToRecentMatches(
    int $new_job_id,
    string $job_title,
    string $company_name,
  ): array {
    $normalized_title = self::normalize($job_title);
    $normalized_company = self::normalize($company_name);
    if ($normalized_title === '' || $normalized_company === '') {
      throw new \InvalidArgumentException('Duplicate linking requires both an AI-parsed job title and company name.');
    }

    $new_mappings = $this->database->select('jobhunter_saved_jobs', 'sj')
      ->fields('sj', ['id', 'uid', 'created'])
      ->condition('sj.job_id', $new_job_id)
      ->execute()
      ->fetchAll();

    $links = [];
    foreach ($new_mappings as $new_mapping) {
      $added_at = (int) $new_mapping->created;
      if ($added_at <= 0) {
        throw new \UnexpectedValueException("Saved-job mapping {$new_mapping->id} has no valid creation timestamp.");
      }

      $query = $this->database->select('jobhunter_saved_jobs', 'sj');
      $query->innerJoin('jobhunter_job_requirements', 'j', 'j.id = sj.job_id');
      $query->innerJoin('jobhunter_companies', 'c', 'c.id = j.company_id');
      $query->fields('sj', ['job_id', 'archived', 'created']);
      $query->addField('j', 'job_title');
      $query->addField('c', 'name', 'company_name');
      $query->condition('sj.uid', (int) $new_mapping->uid)
        ->condition('sj.job_id', $new_job_id, '<>')
        ->condition('sj.created', $added_at - self::DUPLICATE_WINDOW_SECONDS, '>=')
        ->condition('sj.created', $added_at, '<')
        ->orderBy('sj.created', 'DESC');

      foreach ($query->execute()->fetchAll() as $candidate) {
        if (self::normalize((string) $candidate->job_title) !== $normalized_title ||
            self::normalize((string) $candidate->company_name) !== $normalized_company) {
          continue;
        }

        $this->database->delete('jobhunter_saved_jobs')
          ->condition('id', (int) $new_mapping->id)
          ->execute();

        $link = [
          'uid' => (int) $new_mapping->uid,
          'job_id' => (int) $candidate->job_id,
          'job_title' => (string) $candidate->job_title,
          'company' => (string) $candidate->company_name,
          'similarity_score' => 100,
          'is_exact_match' => TRUE,
          'archived' => (bool) $candidate->archived,
        ];
        $links[] = $link;
        $this->logger->notice(
          'Linked duplicate job @new_job to prior job @prior_job for user @uid (archived: @archived).',
          [
            '@new_job' => $new_job_id,
            '@prior_job' => $link['job_id'],
            '@uid' => $link['uid'],
            '@archived' => $link['archived'] ? 'yes' : 'no',
          ]
        );
        break;
      }
    }

    return $links;
  }

  /**
   * Normalizes an identity component without applying fuzzy matching.
   */
  public static function normalize(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
  }

}
