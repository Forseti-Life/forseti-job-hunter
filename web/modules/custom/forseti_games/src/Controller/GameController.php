<?php

namespace Drupal\forseti_games\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for game pages.
 */
class GameController extends ControllerBase {

  /**
   * Display the games home page with list of available games.
   */
  public function home() {
    $games = [
      [
        'title' => 'Block Matcher',
        'description' => 'Match colored blocks to clear the board. A classic puzzle game!',
        'image' => '/modules/custom/forseti_games/images/block-matcher-3d.png',
        'url' => '/games/block-matcher',
        'difficulty' => 'Easy',
        'plays' => 0,
      ],
    ];

    return [
      '#theme' => 'game_home',
      '#games' => $games,
      '#attached' => [
        'library' => [
          'forseti_games/game-home',
        ],
      ],
    ];
  }

  /**
   * Display the Block Matcher game.
   */
  public function blockMatcher() {
    $game_data = [
      'grid_size' => 8,
      'block_types' => 7,
      'min_match' => 3,
    ];

    // Get high scores
    $connection = \Drupal::database();
    $query = $connection->select('forseti_games_high_scores', 'h')
      ->fields('h', ['score', 'level', 'time', 'player_name', 'created'])
      ->condition('game_id', 'block-matcher')
      ->orderBy('score', 'DESC')
      ->orderBy('created', 'ASC')
      ->range(0, 10);
    
    $high_scores = $query->execute()->fetchAll();

    // Get current user information
    $current_user = \Drupal::currentUser();
    $user_data = [
      'is_authenticated' => $current_user->isAuthenticated(),
      'uid' => $current_user->id(),
      'name' => $current_user->getAccountName(),
    ];

    return [
      '#theme' => 'game_block_matcher',
      '#game_data' => $game_data,
      '#high_scores' => $high_scores,
      '#user_data' => $user_data,
      '#attached' => [
        'library' => [
          'forseti_games/block-matcher',
        ],
      ],
    ];
  }

}
