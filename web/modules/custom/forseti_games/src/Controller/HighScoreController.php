<?php

namespace Drupal\forseti_games\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for high score API endpoints.
 */
class HighScoreController extends ControllerBase {

  /**
   * Get top high scores for a game.
   *
   * @param string $game_id
   *   The game identifier.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with high scores.
   */
  public function getHighScores($game_id) {
    $connection = \Drupal::database();
    
    $query = $connection->select('forseti_games_high_scores', 'h')
      ->fields('h', ['id', 'score', 'level', 'time', 'player_name', 'created'])
      ->condition('game_id', $game_id)
      ->orderBy('score', 'DESC')
      ->orderBy('created', 'ASC')
      ->range(0, 10);
    
    $results = $query->execute()->fetchAll();
    
    $scores = [];
    foreach ($results as $row) {
      $scores[] = [
        'id' => (int) $row->id,
        'score' => (int) $row->score,
        'level' => (int) $row->level,
        'time' => (int) $row->time,
        'player_name' => $row->player_name,
        'created' => (int) $row->created,
      ];
    }
    
    return new JsonResponse(['scores' => $scores]);
  }

  /**
   * Submit a new high score.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success status.
   */
  public function submitScore(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    
    if (empty($data['game_id']) || !isset($data['score'])) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Missing required fields'], 400);
    }
    
    $game_id = $data['game_id'];
    $score = (int) $data['score'];
    $level = (int) ($data['level'] ?? 1);
    $time = (int) ($data['time'] ?? 0);
    
    // Use authenticated user's name, fallback to submitted name
    $current_user = \Drupal::currentUser();
    $player_name = $current_user->getAccountName();
    
    // If somehow an anonymous user got through, reject
    if ($current_user->isAnonymous()) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Authentication required'], 403);
    }
    
    // Validate score is within reasonable bounds
    if ($score < 0 || $score > 1000000) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Invalid score value'], 400);
    }
    
    // Validate level is within reasonable bounds
    if ($level < 1 || $level > 100) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Invalid level value'], 400);
    }
    
    // Validate time is reasonable (max 24 hours in seconds)
    if ($time < 0 || $time > 86400) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Invalid time value'], 400);
    }
    
    // Sanitize player name more strictly
    $player_name = preg_replace('/[^a-zA-Z0-9 _-]/', '', $player_name);
    if (empty($player_name)) {
      $player_name = 'Anonymous';
    }
    
    $connection = \Drupal::database();
    
    // Rate limiting: Check for recent submissions from this IP
    $ip_address = $request->getClientIp();
    $recent_submissions = $connection->select('forseti_games_high_scores', 'h')
      ->fields('h', ['id'])
      ->condition('player_name', $player_name)
      ->condition('created', time() - 3600, '>') // Within last hour
      ->countQuery()
      ->execute()
      ->fetchField();
    
    if ($recent_submissions >= 5) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Rate limit exceeded. Please try again later.'], 429);
    }
    
    // Check if this score qualifies for top 10
    $query = $connection->select('forseti_games_high_scores', 'h')
      ->fields('h', ['score'])
      ->condition('game_id', $game_id)
      ->orderBy('score', 'DESC')
      ->range(9, 1);
    
    $tenth_score = $query->execute()->fetchField();
    
    if ($score > $tenth_score || $tenth_score === FALSE) {
      // Insert the new score
      $connection->insert('forseti_games_high_scores')
        ->fields([
          'game_id' => $game_id,
          'score' => $score,
          'level' => $level,
          'time' => $time,
          'player_name' => $player_name,
          'created' => time(),
        ])
        ->execute();
      
      // Delete scores beyond top 10
      $ids_to_keep = $connection->select('forseti_games_high_scores', 'h')
        ->fields('h', ['id'])
        ->condition('game_id', $game_id)
        ->orderBy('score', 'DESC')
        ->orderBy('created', 'ASC')
        ->range(0, 10)
        ->execute()
        ->fetchCol();
      
      if (!empty($ids_to_keep)) {
        $connection->delete('forseti_games_high_scores')
          ->condition('game_id', $game_id)
          ->condition('id', $ids_to_keep, 'NOT IN')
          ->execute();
      }
      
      return new JsonResponse(['success' => TRUE, 'is_high_score' => TRUE]);
    }
    
    return new JsonResponse(['success' => TRUE, 'is_high_score' => FALSE]);
  }

  /**
   * Check if a score qualifies for top 10.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response indicating if score qualifies.
   */
  public function checkScore(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    
    // Check authentication
    $current_user = \Drupal::currentUser();
    if ($current_user->isAnonymous()) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Authentication required'], 403);
    }
    
    if (empty($data['game_id']) || !isset($data['score'])) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Missing required fields'], 400);
    }
    
    $game_id = $data['game_id'];
    $score = (int) $data['score'];
    
    $connection = \Drupal::database();
    
    // Get the 10th place score
    $query = $connection->select('forseti_games_high_scores', 'h')
      ->fields('h', ['score'])
      ->condition('game_id', $game_id)
      ->orderBy('score', 'DESC')
      ->range(9, 1);
    
    $tenth_score = $query->execute()->fetchField();
    
    $qualifies = ($score > $tenth_score || $tenth_score === FALSE);
    
    return new JsonResponse([
      'success' => TRUE,
      'qualifies' => $qualifies,
      'tenth_score' => (int) $tenth_score,
    ]);
  }

}
