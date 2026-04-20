<?php

namespace Drupal\job_hunter\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for opportunity management operations.
 * 
 * Handles deletion and management of jobs, search history, and cached results.
 * Uses batch operations for efficiency and proper transaction handling.
 * 
 * @package Drupal\job_hunter\Service
 */
class OpportunityManagementService {

  /**
   * Maximum number of records allowed for bulk delete operations.
   */
  const MAX_BULK_DELETE = 100;

  /**
   * Maximum number of search history records to retrieve.
   */
  const MAX_SEARCH_HISTORY = 500;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * Constructs an OpportunityManagementService object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->database = $database;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Get management statistics.
   *
   * @return array
   *   Array containing:
   *   - saved_jobs: int, count of jobs in main table
   *   - search_histories: int, count of search history records
   *   - cached_results: int, count of unimported cached results
   */
  public function getManagementStats(): array {
    try {
      $saved_jobs = (int) $this->database->select('jobhunter_job_requirements', 'j')
        ->countQuery()
        ->execute()
        ->fetchField();

      $search_histories = (int) $this->database->select('jobhunter_search_history', 'sh')
        ->countQuery()
        ->execute()
        ->fetchField();

      $cached_results = (int) $this->database->select('jobhunter_job_search_results', 'jr')
        ->condition('imported_to_job_id', NULL, 'IS NULL')
        ->countQuery()
        ->execute()
        ->fetchField();

      return [
        'saved_jobs' => $saved_jobs,
        'search_histories' => $search_histories,
        'cached_results' => $cached_results,
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('job_hunter')->error('Error fetching management stats: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [
        'saved_jobs' => 0,
        'search_histories' => 0,
        'cached_results' => 0,
      ];
    }
  }

  /**
   * Get search history records with filters.
   *
   * @param array $filters
   *   Optional filters:
   *   - date_range: string, 'today', 'week', 'month', 'all'
   *   - sources: string, comma-separated source names
   *
   * @return array
   *   Array of search history objects with result counts and usernames.
   */
  public function getSearchHistory(array $filters = []): array {
    try {
      $query = $this->database->select('jobhunter_search_history', 'sh');
      $query->fields('sh');
      
      // Join with users table to get username
      $query->leftJoin('users_field_data', 'u', 'sh.uid = u.uid');
      $query->addField('u', 'name', 'username');

      // Apply date range filter
      if (!empty($filters['date_range']) && $filters['date_range'] !== 'all') {
        $timestamp = match($filters['date_range']) {
          'today' => strtotime('today'),
          'week' => strtotime('-7 days'),
          'month' => strtotime('-30 days'),
          default => 0,
        };
        if ($timestamp > 0) {
          $query->condition('sh.created', $timestamp, '>=');
        }
      }

      // Apply sources filter
      if (!empty($filters['sources'])) {
        $query->condition('sh.sources', '%' . $this->database->escapeLike($filters['sources']) . '%', 'LIKE');
      }

      $query->orderBy('sh.created', 'DESC');
      $query->range(0, self::MAX_SEARCH_HISTORY);

      $results = $query->execute()->fetchAll();

      // Add cached results count and format data for each search
      foreach ($results as $search) {
        $search->cached_count = (int) $this->database->select('jobhunter_job_search_results', 'jr')
          ->condition('search_query_id', $search->id)
          ->countQuery()
          ->execute()
          ->fetchField();
        
        // Format search_date for template
        $search->search_date = $search->created;
        
        // Parse sources from CSV string to array for template
        $search->sources = !empty($search->sources) ? explode(',', $search->sources) : [];
        
        // Use search_query as query for template consistency
        $search->query = $search->search_query ?? '';
        
        // Fallback for username if user deleted
        if (empty($search->username)) {
          $search->username = 'Deleted User';
        }
      }

      return $results;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('job_hunter')->error('Error fetching search history: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get cached search results for a specific search.
   *
   * @param int $search_id
   *   The search history ID.
   *
   * @return array
   *   Array of cached result objects.
   */
  public function getSearchResults(int $search_id): array {
    try {
      return $this->database->select('jobhunter_job_search_results', 'jr')
        ->fields('jr')
        ->condition('search_query_id', $search_id)
        ->orderBy('created', 'DESC')
        ->execute()
        ->fetchAll();
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('job_hunter')->error('Error fetching search results: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Delete a job from the requirements table.
   *
   * @param int $job_id
   *   The job ID to delete.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function deleteJob(int $job_id): bool {
    try {
      $deleted = $this->database->delete('jobhunter_job_requirements')
        ->condition('id', $job_id)
        ->execute();

      if ($deleted) {
        $this->loggerFactory->get('job_hunter')->info('🗑️ Deleted job ID @id', ['@id' => $job_id]);
        return TRUE;
      }
      return FALSE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('job_hunter')->error('Error deleting job @id: @error', [
        '@id' => $job_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Delete search history and associated cached results.
   *
   * @param int $search_id
   *   The search history ID to delete.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function deleteSearchHistory(int $search_id): bool {
    try {
      $transaction = $this->database->startTransaction();

      // Delete cached results first
      $this->database->delete('jobhunter_job_search_results')
        ->condition('search_query_id', $search_id)
        ->execute();

      // Delete search history
      $deleted = $this->database->delete('jobhunter_search_history')
        ->condition('id', $search_id)
        ->execute();

      if ($deleted) {
        $this->loggerFactory->get('job_hunter')->info('🗑️ Deleted search history ID @id with cached results', ['@id' => $search_id]);
        return TRUE;
      }
      return FALSE;
    }
    catch (\Exception $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      $this->loggerFactory->get('job_hunter')->error('Error deleting search history @id: @error', [
        '@id' => $search_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Delete a single cached search result.
   *
   * @param int $result_id
   *   The cached result ID to delete.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function deleteSearchResult(int $result_id): bool {
    try {
      $deleted = $this->database->delete('jobhunter_job_search_results')
        ->condition('id', $result_id)
        ->execute();

      if ($deleted) {
        $this->loggerFactory->get('job_hunter')->info('🗑️ Deleted cached result ID @id', ['@id' => $result_id]);
        return TRUE;
      }
      return FALSE;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('job_hunter')->error('Error deleting cached result @id: @error', [
        '@id' => $result_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Bulk delete jobs using batch query for efficiency.
   *
   * @param array $job_ids
   *   Array of job IDs to delete.
   *
   * @return array
   *   Array with 'success' (int) and 'failed' (int) counts.
   */
  public function bulkDeleteJobs(array $job_ids): array {
    $job_ids = array_slice($job_ids, 0, self::MAX_BULK_DELETE);
    
    // Filter to valid integers only
    $job_ids = array_filter($job_ids, 'is_numeric');
    $count = count($job_ids);
    
    if ($count === 0) {
      return ['success' => 0, 'failed' => 0];
    }

    try {
      $deleted = $this->database->delete('jobhunter_job_requirements')
        ->condition('id', $job_ids, 'IN')
        ->execute();

      $this->loggerFactory->get('job_hunter')->info('🗑️ Bulk deleted @count jobs', ['@count' => $deleted]);
      
      return [
        'success' => $deleted,
        'failed' => $count - $deleted,
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('job_hunter')->error('Error in bulk delete jobs: @error', [
        '@error' => $e->getMessage(),
      ]);
      return ['success' => 0, 'failed' => $count];
    }
  }

  /**
   * Bulk delete search histories using batch query for efficiency.
   *
   * @param array $search_ids
   *   Array of search IDs to delete.
   *
   * @return array
   *   Array with 'success' (int) and 'failed' (int) counts.
   */
  public function bulkDeleteSearches(array $search_ids): array {
    $search_ids = array_slice($search_ids, 0, self::MAX_BULK_DELETE);
    
    // Filter to valid integers only
    $search_ids = array_filter($search_ids, 'is_numeric');
    $count = count($search_ids);
    
    if ($count === 0) {
      return ['success' => 0, 'failed' => 0];
    }

    try {
      $transaction = $this->database->startTransaction();

      // Delete all cached results for these searches
      $this->database->delete('jobhunter_job_search_results')
        ->condition('search_query_id', $search_ids, 'IN')
        ->execute();

      // Delete all search history records
      $deleted = $this->database->delete('jobhunter_search_history')
        ->condition('id', $search_ids, 'IN')
        ->execute();

      $this->loggerFactory->get('job_hunter')->info('🗑️ Bulk deleted @count search histories with cached results', ['@count' => $deleted]);
      
      return [
        'success' => $deleted,
        'failed' => $count - $deleted,
      ];
    }
    catch (\Exception $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      $this->loggerFactory->get('job_hunter')->error('Error in bulk delete searches: @error', [
        '@error' => $e->getMessage(),
      ]);
      return ['success' => 0, 'failed' => $count];
    }
  }

}
