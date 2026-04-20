/**
 * @file
 * Block Matcher game logic.
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.blockMatcher = {
    attach: function (context, settings) {
      once('block-matcher', '#game-board', context).forEach(function(element) {
        var $gameBoard = $(element);
        var game = new BlockMatcherGame($gameBoard);
        game.init();
      });
    }
  };

  /**
   * Block Matcher Game Class
   */
  function BlockMatcherGame($board) {
    this.$board = $board;
    this.gridSize = parseInt($board.data('grid-size')) || 8;
    this.blockTypes = parseInt($board.data('block-types')) || 5;
    this.minMatch = parseInt($board.data('min-match')) || 3;
    this.grid = [];
    this.selectedBlock = null;
    this.score = 0;
    this.moves = 0;
    this.startTime = null;
    this.timerInterval = null;
    this.rotation = 0; // 0, 90, 180, 270
    this.flipH = false;
    this.flipV = false;
  }

  BlockMatcherGame.prototype = {
    init: function() {
      this.createGrid();
      this.render();
      this.startTimer();
      this.bindEvents();
    },

    createGrid: function() {
      this.grid = [];
      for (var i = 0; i < this.gridSize; i++) {
        this.grid[i] = [];
        for (var j = 0; j < this.gridSize; j++) {
          this.grid[i][j] = this.randomBlockType();
        }
      }
      // Ensure no initial matches
      this.removeInitialMatches();
    },

    randomBlockType: function() {
      return Math.floor(Math.random() * this.blockTypes);
    },

    removeInitialMatches: function() {
      var hasMatches = true;
      while (hasMatches) {
        hasMatches = false;
        for (var i = 0; i < this.gridSize; i++) {
          for (var j = 0; j < this.gridSize; j++) {
            if (this.checkMatchAt(i, j).length >= this.minMatch) {
              this.grid[i][j] = this.randomBlockType();
              hasMatches = true;
            }
          }
        }
      }
    },

    render: function() {
      var self = this;
      var centerPos = Math.floor(this.gridSize / 2);
      this.$board.empty();
      
      for (var i = 0; i < this.gridSize; i++) {
        for (var j = 0; j < this.gridSize; j++) {
          var $block = $('<div>')
            .addClass('block')
            .attr('data-row', i)
            .attr('data-col', j);
          
          if (this.grid[i][j] === -1) {
            $block.addClass('empty');
          } else {
            $block.addClass('color-' + this.grid[i][j]);
          }
          
          // Highlight center block
          if (i === centerPos && j === centerPos) {
            $block.addClass('center-block');
          }
          
          this.$board.append($block);
        }
      }
    },

    bindEvents: function() {
      var self = this;
      
      this.$board.on('click', '.block', function() {
        self.handleBlockClick($(this));
      });

      $('#new-game-btn').on('click', function() {
        self.newGame();
      });

      $('#hint-btn').on('click', function() {
        self.showHint();
      });

      $('#play-again-btn').on('click', function() {
        self.newGame();
        $('#game-over-modal').hide();
      });

      $('#rotate-left-btn').on('click', function() {
        self.rotateBoard(-90);
      });

      $('#rotate-right-btn').on('click', function() {
        self.rotateBoard(90);
      });

      $('#flip-horizontal-btn').on('click', function() {
        self.flipBoard('horizontal');
      });

      $('#flip-vertical-btn').on('click', function() {
        self.flipBoard('vertical');
      });

      $('#reset-orientation-btn').on('click', function() {
        self.resetOrientation();
      });
    },

    handleBlockClick: function($block) {
      if ($block.hasClass('empty')) {
        return;
      }
      
      var row = parseInt($block.attr('data-row'));
      var col = parseInt($block.attr('data-col'));

      if (!this.selectedBlock) {
        // First selection
        this.selectedBlock = {row: row, col: col};
        $block.addClass('selected');
      } else {
        // Second selection - attempt swap
        var prevRow = this.selectedBlock.row;
        var prevCol = this.selectedBlock.col;
        
        $('.block.selected').removeClass('selected');
        
        if (this.isAdjacent(prevRow, prevCol, row, col)) {
          this.swapBlocks(prevRow, prevCol, row, col);
          this.moves++;
          $('#moves').text(this.moves);
        }
        
        this.selectedBlock = null;
      }
    },

    isAdjacent: function(row1, col1, row2, col2) {
      return (Math.abs(row1 - row2) === 1 && col1 === col2) ||
             (Math.abs(col1 - col2) === 1 && row1 === row2);
    },

    swapBlocks: function(row1, col1, row2, col2) {
      var self = this;
      var $block1 = $('.block[data-row="' + row1 + '"][data-col="' + col1 + '"]');
      var $block2 = $('.block[data-row="' + row2 + '"][data-col="' + col2 + '"]');
      
      // Add swapping animation class
      $block1.addClass('swapping');
      $block2.addClass('swapping');
      
      setTimeout(function() {
        // Perform the swap
        var temp = self.grid[row1][col1];
        self.grid[row1][col1] = self.grid[row2][col2];
        self.grid[row2][col2] = temp;
        
        self.render();
        
        setTimeout(function() {
          var matches1 = self.checkMatchAt(row1, col1);
          var matches2 = self.checkMatchAt(row2, col2);
          
          if (matches1.length >= self.minMatch || matches2.length >= self.minMatch) {
            self.processMatches();
          } else {
            // Swap back if no matches
            setTimeout(function() {
              $block1 = $('.block[data-row="' + row1 + '"][data-col="' + col1 + '"]');
              $block2 = $('.block[data-row="' + row2 + '"][data-col="' + col2 + '"]');
              $block1.addClass('swapping');
              $block2.addClass('swapping');
              
              setTimeout(function() {
                self.swapBlocks(row1, col1, row2, col2);
              }, 300);
            }, 200);
          }
        }, 100);
      }, 300);
    },

    checkMatchAt: function(row, col) {
      var color = this.grid[row][col];
      var matches = [{row: row, col: col}];
      
      // Check horizontal
      var left = col - 1;
      while (left >= 0 && this.grid[row][left] === color) {
        matches.push({row: row, col: left});
        left--;
      }
      var right = col + 1;
      while (right < this.gridSize && this.grid[row][right] === color) {
        matches.push({row: row, col: right});
        right++;
      }
      
      // Check vertical
      var up = row - 1;
      while (up >= 0 && this.grid[up][col] === color) {
        matches.push({row: up, col: col});
        up--;
      }
      var down = row + 1;
      while (down < this.gridSize && this.grid[down][col] === color) {
        matches.push({row: down, col: col});
        down++;
      }
      
      return matches;
    },

    processMatches: function() {
      var allMatches = [];
      
      for (var i = 0; i < this.gridSize; i++) {
        for (var j = 0; j < this.gridSize; j++) {
          var matches = this.checkMatchAt(i, j);
          if (matches.length >= this.minMatch) {
            allMatches = allMatches.concat(matches);
          }
        }
      }
      
      if (allMatches.length > 0) {
        this.removeMatches(allMatches);
        this.score += allMatches.length * 10;
        $('#score').text(this.score);
      }
    },

    removeMatches: function(matches) {
      var self = this;
      
      // Mark matched blocks
      matches.forEach(function(match) {
        var $block = $('.block[data-row="' + match.row + '"][data-col="' + match.col + '"]');
        $block.addClass('matched');
      });
      
      setTimeout(function() {
        // Remove matched blocks
        matches.forEach(function(match) {
          self.grid[match.row][match.col] = -1;
        });
        
        // Drop blocks down
        self.dropBlocks();
        
        // Check for new matches after animation completes
        setTimeout(function() {
          self.processMatches();
          
          // Check win condition after matches processed
          setTimeout(function() {
            self.checkWinCondition();
          }, 100);
        }, 600);
      }, 600);
    },

    dropBlocks: function() {
      var self = this;
      var centerPos = Math.floor(this.gridSize / 2);
      var movements = [];
      var moved = true;
      
      // Keep moving blocks toward center until no more moves possible
      while (moved) {
        moved = false;
        
        for (var row = 0; row < this.gridSize; row++) {
          for (var col = 0; col < this.gridSize; col++) {
            if (this.grid[row][col] === -1) continue;
            
            // Calculate direction toward center
            var rowDir = row < centerPos ? 1 : (row > centerPos ? -1 : 0);
            var colDir = col < centerPos ? 1 : (col > centerPos ? -1 : 0);
            
            // Try to move toward center (row first, then col)
            if (rowDir !== 0) {
              var newRow = row + rowDir;
              if (this.grid[newRow][col] === -1) {
                movements.push({
                  fromRow: row,
                  fromCol: col,
                  toRow: newRow,
                  toCol: col
                });
                this.grid[newRow][col] = this.grid[row][col];
                this.grid[row][col] = -1;
                moved = true;
                continue;
              }
            }
            
            if (colDir !== 0) {
              var newCol = col + colDir;
              if (this.grid[row][newCol] === -1) {
                movements.push({
                  fromRow: row,
                  fromCol: col,
                  toRow: row,
                  toCol: newCol
                });
                this.grid[row][newCol] = this.grid[row][col];
                this.grid[row][col] = -1;
                moved = true;
              }
            }
          }
        }
      }
      
      // Re-render
      this.render();
      
      // Apply animations
      if (movements.length > 0) {
        setTimeout(function() {
          $('.block:not(.empty)').addClass('dropping');
          setTimeout(function() {
            $('.block').removeClass('dropping');
          }, 400);
        }, 50);
      }
    },

    showHint: function() {
      // Simple hint: find first possible move
      for (var i = 0; i < this.gridSize; i++) {
        for (var j = 0; j < this.gridSize - 1; j++) {
          // Try horizontal swap
          this.swapBlocks(i, j, i, j + 1);
          var matches = this.checkMatchAt(i, j).concat(this.checkMatchAt(i, j + 1));
          this.swapBlocks(i, j, i, j + 1); // Swap back
          
          if (matches.length >= this.minMatch) {
            var $hint = $('.block[data-row="' + i + '"][data-col="' + j + '"]');
            $hint.addClass('selected');
            setTimeout(function() { $hint.removeClass('selected'); }, 1000);
            return;
          }
        }
      }
      alert('No obvious moves available!');
    },

    startTimer: function() {
      this.startTime = Date.now();
      var self = this;
      this.timerInterval = setInterval(function() {
        self.updateTimer();
      }, 1000);
    },

    updateTimer: function() {
      var elapsed = Math.floor((Date.now() - this.startTime) / 1000);
      var minutes = Math.floor(elapsed / 60);
      var seconds = elapsed % 60;
      $('#timer').text(minutes + ':' + (seconds < 10 ? '0' : '') + seconds);
    },

    newGame: function() {
      this.score = 0;
      this.moves = 0;
      this.selectedBlock = null;
      $('#score').text(0);
      $('#moves').text(0);
      clearInterval(this.timerInterval);
      this.createGrid();
      this.render();
      this.startTimer();
    }
  };

  /**
   * Check if game is won.
   */
  BlockMatcherGame.prototype.checkWinCondition = function() {
    var blockCount = 0;
    
    for (var i = 0; i < this.gridSize; i++) {
      for (var j = 0; j < this.gridSize; j++) {
        if (this.grid[i][j] !== -1) {
          blockCount++;
        }
      }
    }
    
    // Win if only 1 block left
    if (blockCount === 1) {
      this.gameOver(true, 'You Win! Only one block remains!');
      return;
    }
    
    // Check if any moves are possible
    if (!this.hasValidMoves()) {
      this.gameOver(false, 'No more moves! Game Over.');
    }
  };

  /**
   * Check if any valid moves exist.
   */
  BlockMatcherGame.prototype.hasValidMoves = function() {
    for (var i = 0; i < this.gridSize; i++) {
      for (var j = 0; j < this.gridSize; j++) {
        if (this.grid[i][j] === -1) continue;
        
        // Check adjacent positions
        var adjacents = [
          [i-1, j], [i+1, j], [i, j-1], [i, j+1]
        ];
        
        for (var k = 0; k < adjacents.length; k++) {
          var nr = adjacents[k][0];
          var nc = adjacents[k][1];
          
          if (nr >= 0 && nr < this.gridSize && nc >= 0 && nc < this.gridSize && this.grid[nr][nc] !== -1) {
            // Simulate swap
            var temp = this.grid[i][j];
            this.grid[i][j] = this.grid[nr][nc];
            this.grid[nr][nc] = temp;
            
            // Check for matches
            var hasMatch = this.checkMatchAt(i, j).length >= this.minMatch ||
                          this.checkMatchAt(nr, nc).length >= this.minMatch;
            
            // Swap back
            this.grid[nr][nc] = this.grid[i][j];
            this.grid[i][j] = temp;
            
            if (hasMatch) {
              return true;
            }
          }
        }
      }
    }
    return false;
  };

  /**
   * Handle game over.
   */
  BlockMatcherGame.prototype.gameOver = function(won, message) {
    clearInterval(this.timerInterval);
    
    $('#final-score').text(this.score);
    $('#final-moves').text(this.moves);
    $('#final-time').text($('#timer').text());
    
    // Update modal title
    $('#game-over-modal h2').text(won ? 'Congratulations!' : 'Game Over');
    
    // Show custom message if provided
    if (message) {
      var $message = $('<p>').addClass('game-message').text(message);
      $('#game-over-modal .modal-content').prepend($message);
    }
    
    $('#game-over-modal').show();
  };

  /**
   * Rotate the board view.
   */
  BlockMatcherGame.prototype.rotateBoard = function(degrees) {
    this.rotation = (this.rotation + degrees + 360) % 360;
    this.updateBoardTransform();
  };

  /**
   * Flip the board view.
   */
  BlockMatcherGame.prototype.flipBoard = function(direction) {
    if (direction === 'horizontal') {
      this.flipH = !this.flipH;
    } else if (direction === 'vertical') {
      this.flipV = !this.flipV;
    }
    this.updateBoardTransform();
  };

  /**
   * Reset board orientation.
   */
  BlockMatcherGame.prototype.resetOrientation = function() {
    this.rotation = 0;
    this.flipH = false;
    this.flipV = false;
    this.updateBoardTransform();
  };

  /**
   * Update board transform classes.
   */
  BlockMatcherGame.prototype.updateBoardTransform = function() {
    this.$board.removeClass('rotate-90 rotate-180 rotate-270 flip-h flip-v');
    
    if (this.rotation === 90) {
      this.$board.addClass('rotate-90');
    } else if (this.rotation === 180) {
      this.$board.addClass('rotate-180');
    } else if (this.rotation === 270) {
      this.$board.addClass('rotate-270');
    }
    
    if (this.flipH) {
      this.$board.addClass('flip-h');
    }
    if (this.flipV) {
      this.$board.addClass('flip-v');
    }
  };

})(jQuery, Drupal, once);
