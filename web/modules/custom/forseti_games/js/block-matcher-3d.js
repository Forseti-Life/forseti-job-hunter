/**
 * @file
 * 3D Block Matcher game logic using Three.js.
 * @version 2.1.3
 * @updated 2026-01-19
 */

(function ($, Drupal, once) {
  'use strict';
  
  console.log('Block Matcher 3D v2.1.3 - Loaded');

  Drupal.behaviors.blockMatcher3D = {
    attach: function (context, settings) {
      once('block-matcher-3d', '#game-board', context).forEach(function(element) {
        var $gameBoard = $(element);
        
        // Wait for Three.js to load
        if (typeof THREE === 'undefined') {
          setTimeout(function() {
            Drupal.behaviors.blockMatcher3D.attach(context, settings);
          }, 100);
          return;
        }
        
        var game = new BlockMatcher3DGame($gameBoard);
        game.init();
      });
    }
  };

  /**
   * 3D Block Matcher Game Class
   */
  function BlockMatcher3DGame($board) {
    this.$board = $board;
    this.gridSize = 13; // Full grid size (13x13x13)
    this.level = 1; // Current level (starts at 1)
    this.maxLevel = 999; // No effective level cap (playable size caps at level 3)
    this.playableSize = 3; // Will be calculated based on level
    this.blockTypes = parseInt($board.data('block-types')) || 6;
    this.minMatch = parseInt($board.data('min-match')) || 3;
    this.grid = []; // 3D array [x][y][z]
    this.selectedBlock = null;
    
    // Logging configuration - DEBUG MODE DISABLED
    this.logLevel = 'ERROR'; // DEBUG, INFO, WARN, ERROR
    this.enableLogging = false; // Set to true to enable debug logging
    this.score = 0;
    this.moves = 0;
    this.startTime = null;
    this.timerInterval = null;
    
    // Combo tracking
    this.initialMatchCount = 0;
    this.comboMatchCount = 0;
    
    // Settlement lock
    this.isSettling = false;
    
    // Special block system
    this.pointMultiplier = 1;
    this.multiplierTurnsLeft = 0;
    this.freezeTurnsLeft = 0;
    this.hasShield = false;
    
    // Special blocks: 100-114 (with some numbers removed)
    this.specialBlocks = {
      100: { name: 'Bomb', emoji: '💣', color: 0xff0000, rarity: 30, unlockLevel: 1 },
      101: { name: 'Lightning', emoji: '⚡', color: 0xffff00, rarity: 30, unlockLevel: 2 },
      109: { name: 'Jackpot', emoji: '🎰', color: 0x00ff00, rarity: 30, unlockLevel: 3 },
      108: { name: 'Multiplier', emoji: '💎', color: 0xffd700, rarity: 30, unlockLevel: 4 },
      107: { name: 'Freeze', emoji: '⏸️', color: 0x66ccff, rarity: 30, unlockLevel: 5 },
      106: { name: 'Laser', emoji: '🎯', color: 0xff0066, rarity: 60, unlockLevel: 6 },
      105: { name: 'Shuffler', emoji: '🔄', color: 0x9900ff, rarity: 60, unlockLevel: 7 },
      113: { name: 'Color Changer', emoji: '🎨', color: 0x6699ff, rarity: 9, unlockLevel: 8 },
      112: { name: 'Teleporter', emoji: '🔮', color: 0xff66cc, rarity: 9, unlockLevel: 9 },
      110: { name: 'Combo Extender', emoji: '⭐', color: 0xc0c0c0, rarity: 9, unlockLevel: 10 },
      114: { name: 'Shield', emoji: '🛡️', color: 0x0099ff, rarity: 1, unlockLevel: 11 }
    };
    
    // Audio
    this.audioContext = null;
    this.initAudio();
    
    // Three.js objects
    this.scene = null;
    this.camera = null;
    this.renderer = null;
    this.blockMeshes = {};
    this.raycaster = new THREE.Raycaster();
    this.mouse = new THREE.Vector2();
    
    // Camera rotation
    this.cameraAngle = { theta: Math.PI / 4, phi: Math.PI / 4 };
    this.cameraDistance = this.playableSize * 3.5; // Focus on playable area
    
    // Drag state
    this.draggedBlock = null;
    this.validDropZones = [];
    this.dropZoneMeshes = [];
    
    // Get user authentication state from meta tags
    this.isAuthenticated = $('meta[name="user-authenticated"]').attr('content') === '1';
    this.userName = $('meta[name="user-name"]').attr('content') || '';
  }

  BlockMatcher3DGame.prototype = {
    // Centralized logging methods
    logDebug: function(message) {
      if (this.enableLogging && (this.logLevel === 'DEBUG')) {
        console.log('[DEBUG] ' + message);
      }
    },
    
    logInfo: function(message) {
      if (this.enableLogging && (this.logLevel === 'DEBUG' || this.logLevel === 'INFO')) {
        console.log('[INFO] ' + message);
      }
    },
    
    logWarn: function(message) {
      if (this.enableLogging && (this.logLevel === 'DEBUG' || this.logLevel === 'INFO' || this.logLevel === 'WARN')) {
        console.warn('[WARN] ' + message);
      }
    },
    
    logError: function(message) {
      // Critical errors always logged
      console.error('[ERROR] ' + message);
    },
    
    init: function() {
      var self = this;
      
      // Fetch CSRF token from Drupal
      $.ajax({
        url: '/session/token',
        method: 'GET',
        success: function(token) {
          self.csrfToken = token;
        },
        error: function() {
          console.warn('Failed to fetch CSRF token');
          self.csrfToken = '';
        }
      });
      
      this.updateLevel();
      this.precalculateDistances(); // Cache distances for settlement optimization
      this.createGrid();
      this.initThreeJS();
      this.render3D();
      this.startTimer();
      this.bindEvents();
      this.startCenterBlockShimmer();
      this.startSpawnBlockThrob();
    },
    
    startSpawnBlockThrob: function() {
      var self = this;
      var throbTime = 0;
      
      function throb() {
        throbTime += 0.05;
        var intensity = 0.5 + Math.sin(throbTime) * 0.3; // Pulse between 0.2 and 0.8
        
        // Update all blocks at spawn edges
        Object.keys(self.blockMeshes).forEach(function(key) {
          var mesh = self.blockMeshes[key];
          if (mesh && mesh.userData && mesh.userData.isSpawnEdge) {
            // Throb the emissive intensity
            if (Array.isArray(mesh.material)) {
              mesh.material.forEach(function(mat) {
                mat.emissiveIntensity = intensity;
              });
            } else if (mesh.material) {
              mesh.material.emissiveIntensity = intensity;
            }
          }
        });
        
        if (!self.gameEnded) {
          requestAnimationFrame(throb);
        }
      }
      
      throb();
    },

    initAudio: function() {
      try {
        this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
      } catch(e) {
        this.logWarn('Web Audio API not supported');
      }
    },

    playClickSound: function(count) {
      if (!this.audioContext) return;
      
      // Create a short click/tap sound
      var oscillator = this.audioContext.createOscillator();
      var gainNode = this.audioContext.createGain();
      
      oscillator.connect(gainNode);
      gainNode.connect(this.audioContext.destination);
      
      // Higher pitch for more blocks moving
      var baseFreq = 800;
      var freqVariation = Math.min(count * 5, 400);
      oscillator.frequency.value = baseFreq + freqVariation;
      oscillator.type = 'sine';
      
      // Quick attack and decay
      var now = this.audioContext.currentTime;
      gainNode.gain.setValueAtTime(0, now);
      gainNode.gain.linearRampToValueAtTime(0.1, now + 0.01);
      gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.05);
      
      oscillator.start(now);
      oscillator.stop(now + 0.05);
    },

    playExplosionSound: function(count) {
      if (!this.audioContext) return;
      
      // Throttle sound - minimum 100ms between explosions (±150ms randomization)
      if (!this.lastSoundTime) this.lastSoundTime = 0;
      var now = this.audioContext.currentTime;
      var throttleTime = 0.1 + (Math.random() - 0.5) * 0.3; // 100ms ± 150ms
      if (now - this.lastSoundTime < throttleTime) return; // Skip if too soon
      this.lastSoundTime = now;
      
      // Create a noise-based explosion sound
      var duration = 0.3;
      
      // Low frequency rumble
      var rumble = this.audioContext.createOscillator();
      var rumbleGain = this.audioContext.createGain();
      rumble.connect(rumbleGain);
      rumbleGain.connect(this.audioContext.destination);
      rumble.type = 'sawtooth';
      rumble.frequency.value = 50 + Math.min(count * 2, 100);
      
      rumbleGain.gain.setValueAtTime(0.2, now);
      rumbleGain.gain.exponentialRampToValueAtTime(0.01, now + duration);
      
      rumble.start(now);
      rumble.stop(now + duration);
      
      // High frequency crack
      var crack = this.audioContext.createOscillator();
      var crackGain = this.audioContext.createGain();
      crack.connect(crackGain);
      crackGain.connect(this.audioContext.destination);
      crack.type = 'square';
      crack.frequency.setValueAtTime(1200, now);
      crack.frequency.exponentialRampToValueAtTime(100, now + 0.1);
      
      crackGain.gain.setValueAtTime(0.15, now);
      crackGain.gain.exponentialRampToValueAtTime(0.01, now + 0.1);
      
      crack.start(now);
      crack.stop(now + 0.1);
    },

    playWhooshSound: function() {
      if (!this.audioContext) return;
      
      // Create a whooshing sound for rotation
      var duration = 0.15;
      var now = this.audioContext.currentTime;
      
      // Sweeping frequency for whoosh effect
      var whoosh = this.audioContext.createOscillator();
      var whooshGain = this.audioContext.createGain();
      whoosh.connect(whooshGain);
      whooshGain.connect(this.audioContext.destination);
      whoosh.type = 'sine';
      
      // Sweep from high to low for whoosh
      whoosh.frequency.setValueAtTime(600, now);
      whoosh.frequency.exponentialRampToValueAtTime(200, now + duration);
      
      whooshGain.gain.setValueAtTime(0.05, now);
      whooshGain.gain.linearRampToValueAtTime(0.08, now + 0.05);
      whooshGain.gain.exponentialRampToValueAtTime(0.01, now + duration);
      
      whoosh.start(now);
      whoosh.stop(now + duration);
    },

    playSelectSound: function() {
      if (!this.audioContext) return;
      
      // Create a bright click for first block selection
      var oscillator = this.audioContext.createOscillator();
      var gainNode = this.audioContext.createGain();
      
      oscillator.connect(gainNode);
      gainNode.connect(this.audioContext.destination);
      
      oscillator.frequency.value = 1200;
      oscillator.type = 'sine';
      
      var now = this.audioContext.currentTime;
      gainNode.gain.setValueAtTime(0.15, now);
      gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.08);
      
      oscillator.start(now);
      oscillator.stop(now + 0.08);
    },

    playDeselectSound: function() {
      if (!this.audioContext) return;
      
      // Create a lower click for second block selection (inverse of select)
      var oscillator = this.audioContext.createOscillator();
      var gainNode = this.audioContext.createGain();
      
      oscillator.connect(gainNode);
      gainNode.connect(this.audioContext.destination);
      
      // Lower frequency than select (inverse)
      oscillator.frequency.value = 600;
      oscillator.type = 'sine';
      
      var now = this.audioContext.currentTime;
      gainNode.gain.setValueAtTime(0.12, now);
      gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.1);
      
      oscillator.start(now);
      oscillator.stop(now + 0.1);
    },

    precalculateDistances: function() {
      // Pre-calculate all distances from center for settlement optimization
      // This eliminates ~58,000-116,000 distance calculations per turn
      this.logDebug('Precalculating block distances for settlement optimization...');
      
      var centerPos = Math.floor(this.gridSize / 2);
      this.blockDistances = {};  // Cache of distance info by position key
      this.blocksByDistance = []; // Pre-sorted array of all positions
      
      for (var x = 0; x < this.gridSize; x++) {
        for (var y = 0; y < this.gridSize; y++) {
          for (var z = 0; z < this.gridSize; z++) {
            var xDist = Math.abs(x - centerPos);
            var yDist = Math.abs(y - centerPos);
            var zDist = Math.abs(z - centerPos);
            var totalDist = Math.sqrt(xDist*xDist + yDist*yDist + zDist*zDist);
            
            var key = x + '_' + y + '_' + z;
            var distInfo = {
              x: x,
              y: y,
              z: z,
              dist: totalDist,
              xDist: xDist,
              yDist: yDist,
              zDist: zDist
            };
            
            this.blockDistances[key] = distInfo;
            this.blocksByDistance.push(distInfo);
          }
        }
      }
      
      // Sort once at initialization instead of every settlement iteration
      this.blocksByDistance.sort(function(a, b) {
        return b.dist - a.dist; // Sort descending (furthest first)
      });
      
      this.logDebug('Distance cache initialized: ' + this.blocksByDistance.length + ' positions pre-calculated and sorted');
    },

    showGameMessage: function(text) {
      var self = this;
      
      // Create text sprite with dynamic width
      var canvas = document.createElement('canvas');
      var ctx = canvas.getContext('2d');
      
      // Measure text first to determine canvas size
      ctx.font = 'bold 64px Arial';
      var metrics = ctx.measureText(text);
      var textWidth = metrics.width;
      
      // Set canvas size with padding
      canvas.width = Math.max(2048, textWidth + 200);
      canvas.height = 256;
      
      // Redraw with proper size
      ctx.font = 'bold 64px Arial';
      ctx.fillStyle = 'rgba(0, 0, 0, 0.85)';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      
      // Draw text
      ctx.fillStyle = '#ffffff';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(text, canvas.width / 2, canvas.height / 2);
      
      var texture = new THREE.CanvasTexture(canvas);
      var spriteMaterial = new THREE.SpriteMaterial({ 
        map: texture,
        transparent: true,
        depthTest: false,
        opacity: 1
      });
      var sprite = new THREE.Sprite(spriteMaterial);
      
      // Position in front of camera with aspect ratio based on text length
      var aspectRatio = canvas.width / canvas.height;
      sprite.scale.set(10 * aspectRatio / 4, 2.5, 1);
      sprite.position.set(0, 0, 0);
      sprite.renderOrder = 9999;
      
      this.scene.add(sprite);
      
      // Animate: expand and fade out
      var startTime = Date.now();
      var duration = 2000;
      
      function animate() {
        var elapsed = Date.now() - startTime;
        var progress = elapsed / duration;
        
        if (progress < 1) {
          var scale = 10 + progress * 5; // Expand from 10 to 15
          sprite.scale.set(scale * aspectRatio / 4, scale * 0.25, 1);
          sprite.material.opacity = 1 - progress;
          self.renderer.render(self.scene, self.camera);
          requestAnimationFrame(animate);
        } else {
          self.scene.remove(sprite);
          self.renderer.render(self.scene, self.camera);
        }
      }
      
      animate();
    },

    playSuccessSound: function() {
      if (!this.audioContext) return;
      
      // Trumpet fanfare: "Da da da da!" (short-short-short-long)
      var now = this.audioContext.currentTime;
      
      // Classic fanfare rhythm with trumpet-like tones
      var fanfare = [
        { note: 523.25, time: 0, duration: 0.15 },      // C5 - "Da"
        { note: 523.25, time: 0.18, duration: 0.15 },   // C5 - "da"
        { note: 659.25, time: 0.36, duration: 0.15 },   // E5 - "da"
        { note: 783.99, time: 0.54, duration: 0.4 }     // G5 - "DAAA!"
      ];
      
      fanfare.forEach(function(note) {
        // Use sawtooth wave for brass-like trumpet sound
        var osc = this.audioContext.createOscillator();
        var gain = this.audioContext.createGain();
        var filter = this.audioContext.createBiquadFilter();
        
        osc.connect(filter);
        filter.connect(gain);
        gain.connect(this.audioContext.destination);
        
        osc.frequency.value = note.note;
        osc.type = 'sawtooth'; // Brass-like timbre
        
        // Bandpass filter for trumpet character
        filter.type = 'bandpass';
        filter.frequency.value = note.note * 2;
        filter.Q.value = 1.5;
        
        var startTime = now + note.time;
        var endTime = startTime + note.duration;
        
        // Attack and decay envelope for trumpet articulation
        gain.gain.setValueAtTime(0, startTime);
        gain.gain.linearRampToValueAtTime(0.25, startTime + 0.02); // Quick attack
        gain.gain.linearRampToValueAtTime(0.2, startTime + note.duration * 0.3); // Sustain
        gain.gain.exponentialRampToValueAtTime(0.01, endTime); // Decay
        
        osc.start(startTime);
        osc.stop(endTime);
      }.bind(this));
    },

    playVictorySound: function() {
      if (!this.audioContext) return;
      
      // Create a celebratory fanfare
      var now = this.audioContext.currentTime;
      
      // Triumphant chord progression: C-E-G, then up to high C
      var sequence = [
        { notes: [523.25, 659.25, 783.99], time: 0 },      // C5, E5, G5
        { notes: [587.33, 739.99, 880.00], time: 0.15 },   // D5, F#5, A5
        { notes: [659.25, 783.99, 987.77], time: 0.3 },    // E5, G5, B5
        { notes: [1046.50], time: 0.5 }                     // C6 (high note)
      ];
      
      sequence.forEach(function(chord) {
        chord.notes.forEach(function(freq) {
          var osc = this.audioContext.createOscillator();
          var gain = this.audioContext.createGain();
          
          osc.connect(gain);
          gain.connect(this.audioContext.destination);
          
          osc.frequency.value = freq;
          osc.type = 'sine';
          
          var startTime = now + chord.time;
          var duration = chord.time === 0.5 ? 0.8 : 0.2; // Longer final note
          
          gain.gain.setValueAtTime(0.2, startTime);
          gain.gain.exponentialRampToValueAtTime(0.01, startTime + duration);
          
          osc.start(startTime);
          osc.stop(startTime + duration);
        }.bind(this));
      }.bind(this));
      
      // Add some shimmer with higher harmonics
      for (var i = 0; i < 5; i++) {
        var shimmer = this.audioContext.createOscillator();
        var shimmerGain = this.audioContext.createGain();
        
        shimmer.connect(shimmerGain);
        shimmerGain.connect(this.audioContext.destination);
        
        shimmer.frequency.value = 2000 + (i * 400);
        shimmer.type = 'sine';
        
        var shimmerTime = now + (i * 0.1);
        shimmerGain.gain.setValueAtTime(0.05, shimmerTime);
        shimmerGain.gain.exponentialRampToValueAtTime(0.01, shimmerTime + 0.3);
        
        shimmer.start(shimmerTime);
        shimmer.stop(shimmerTime + 0.3);
      }
    },

    updateLevel: function() {
      // Calculate playable size based on level: Level 1 = 5x5x5, Level 2 = 7x7x7, Level 3+ = 9x9x9
      // Cap playable size at level 3 (9x9x9 = 728 blocks), but level can continue to increase
      var targetSize = 2 * Math.min(this.level + 1, 4) + 1;
      this.playableSize = Math.min(targetSize, this.gridSize);
      $('#level').text(this.level);
    },

    createGrid: function() {
      this.grid = [];
      var centerPos = Math.floor(this.gridSize / 2); // Center at 6 for 13x13x13
      var halfSize = Math.floor(this.playableSize / 2); // How many blocks on each side of center
      var startIdx = centerPos - halfSize;
      var endIdx = centerPos + halfSize + 1; // +1 because center block itself
      
      // Initialize entire 18x18x18 grid as empty
      for (var x = 0; x < this.gridSize; x++) {
        this.grid[x] = [];
        for (var y = 0; y < this.gridSize; y++) {
          this.grid[x][y] = [];
          for (var z = 0; z < this.gridSize; z++) {
            // Only fill playable area centered around (9,9,9)
            if (x >= startIdx && x < endIdx && 
                y >= startIdx && y < endIdx && 
                z >= startIdx && z < endIdx) {
              // Center block always gets special type -3 (protected, never removed)
              if (x === centerPos && y === centerPos && z === centerPos) {
                this.grid[x][y][z] = -3; // Special center block marker (protected)
              } else {
                this.grid[x][y][z] = this.randomBlockType();
              }
            } else {
              this.grid[x][y][z] = -1; // Empty
            }
          }
        }
      }
      this.removeInitialMatches();
    },

    randomBlockType: function() {
      // Don't spawn special blocks if combo is over 500
      if (this.comboMatchCount > 500) {
        return Math.floor(Math.random() * this.blockTypes);
      }
      
      // 5% chance for special block
      if (Math.random() < 0.05) {
        return this.getRandomSpecialBlock();
      }
      return Math.floor(Math.random() * this.blockTypes);
    },

    getRandomSpecialBlock: function() {
      // Only include special blocks unlocked at current level or below
      var availableBlocks = {};
      var self = this;
      
      Object.keys(this.specialBlocks).forEach(function(key) {
        var block = self.specialBlocks[key];
        if (block.unlockLevel <= self.level) {
          availableBlocks[key] = block;
        }
      });
      
      // If no blocks available (shouldn't happen), return Bomb
      if (Object.keys(availableBlocks).length === 0) {
        return 100;
      }
      
      // Calculate total rarity weight from available blocks
      var totalRarity = 0;
      Object.keys(availableBlocks).forEach(function(key) {
        totalRarity += availableBlocks[key].rarity;
      });
      
      // Pick based on rarity
      var roll = Math.random() * totalRarity;
      var currentWeight = 0;
      
      for (var key in availableBlocks) {
        currentWeight += availableBlocks[key].rarity;
        if (roll <= currentWeight) {
          this.logDebug('Generated special block: ' + key + ' ' + availableBlocks[key].name + ' (level ' + this.level + ')');
          return parseInt(key);
        }
      }
      
      // Fallback to first available block
      var firstKey = Object.keys(availableBlocks)[0];
      this.logDebug('Using fallback special block: ' + firstKey);
      return parseInt(firstKey);
    },

    isSpecialBlock: function(type) {
      return type >= 100 && type <= 114;
    },

    getSpecialBlockData: function(type) {
      return this.specialBlocks[type] || null;
    },

    getBlockMatchColor: function(type) {
      // For special blocks, extract the base color (100-114 maps to 0-4)
      // This allows special blocks to match with regular blocks of the same color
      if (this.isSpecialBlock(type)) {
        // Map special blocks to colors 0-4 based on their ID
        return (type - 100) % this.blockTypes;
      }
      return type;
    },

    removeInitialMatches: function() {
      var hasMatches = true;
      var iterations = 0;
      while (hasMatches && iterations < 100) {
        hasMatches = false;
        for (var x = 0; x < this.gridSize; x++) {
          for (var y = 0; y < this.gridSize; y++) {
            for (var z = 0; z < this.gridSize; z++) {
              if (this.grid[x][y][z] !== -1 && this.checkMatchAt(x, y, z).length >= this.minMatch) {
                this.grid[x][y][z] = this.randomBlockType();
                hasMatches = true;
              }
            }
          }
        }
        iterations++;
      }
    },

    initThreeJS: function() {
      var canvas = document.getElementById('game-canvas');
      var width = canvas.parentElement.clientWidth;
      var height = canvas.parentElement.clientHeight;
      
      // Scene
      this.scene = new THREE.Scene();
      this.scene.background = new THREE.Color(0x2c3e50);
      
      // Camera
      this.camera = new THREE.PerspectiveCamera(50, width / height, 0.1, 1000);
      this.updateCameraPosition();
      
      // Renderer
      this.renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: true });
      this.renderer.setSize(width, height);
      this.renderer.setPixelRatio(window.devicePixelRatio);
      
      // Lighting
      var ambientLight = new THREE.AmbientLight(0xffffff, 0.6);
      this.scene.add(ambientLight);
      
      var directionalLight = new THREE.DirectionalLight(0xffffff, 0.8);
      directionalLight.position.set(10, 10, 10);
      this.scene.add(directionalLight);
      
      // Grid helper at center - show full grid
      var gridHelper = new THREE.GridHelper(this.gridSize, this.gridSize, 0x444444, 0x222222);
      gridHelper.position.y = -this.gridSize / 2;
      gridHelper.raycast = function() {}; // Disable raycasting so it doesn't block clicks
      this.scene.add(gridHelper);
    },

    updateCameraPosition: function() {
      var theta = this.cameraAngle.theta;
      var phi = this.cameraAngle.phi;
      var radius = this.cameraDistance;
      
      this.camera.position.x = radius * Math.sin(phi) * Math.cos(theta);
      this.camera.position.y = radius * Math.cos(phi);
      this.camera.position.z = radius * Math.sin(phi) * Math.sin(theta);
      this.camera.lookAt(0, 0, 0);
    },

    getBlockColor: function(type) {
      // Define base colors: maximally spaced on color wheel for distinction
      // Red, Orange, Yellow, Green, Cyan, Blue, Magenta (7 colors evenly distributed)
      var colors = [0xFF0000, 0xFF8000, 0xFFFF00, 0x00FF00, 0x00FFFF, 0x0000FF, 0xFF00FF];
      
      if (this.isSpecialBlock(type)) {
        // Special blocks use the color they will match with
        var matchColor = this.getBlockMatchColor(type);
        return colors[matchColor] || 0xffffff;
      }
      
      return colors[type] || 0xcccccc;
    },

    createEmojiTexture: function(emoji, blockColor) {
      var canvas = document.createElement('canvas');
      canvas.width = 128;
      canvas.height = 128;
      var ctx = canvas.getContext('2d');
      
      // Draw solid colored background (block color)
      ctx.fillStyle = '#' + blockColor.toString(16).padStart(6, '0');
      ctx.fillRect(0, 0, 128, 128);
      
      // Draw large white circle background for emoji (nearly full size)
      ctx.fillStyle = '#ffffff';
      ctx.beginPath();
      ctx.arc(64, 64, 58, 0, Math.PI * 2);
      ctx.fill();
      
      // Add thick dark border around white circle for definition
      ctx.strokeStyle = '#000000';
      ctx.lineWidth = 6;
      ctx.stroke();
      
      // Draw emoji on top - larger and with strong black outline
      ctx.font = 'bold 80px Arial';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      
      // Draw black outline for the emoji (make it stand out)
      ctx.strokeStyle = '#000000';
      ctx.lineWidth = 8;
      ctx.strokeText(emoji, 64, 64);
      
      // Draw the emoji itself
      ctx.fillText(emoji, 64, 64);
      
      var texture = new THREE.CanvasTexture(canvas);
      texture.needsUpdate = true;
      return texture;
    },

    render3D: function() {
      var self = this;
      var centerPos = Math.floor(this.gridSize / 2);
      var offset = this.gridSize / 2;
      
      // Clear existing meshes
      Object.keys(this.blockMeshes).forEach(function(key) {
        self.scene.remove(self.blockMeshes[key]);
      });
      this.blockMeshes = {};
      
      // Create block meshes
      var geometry = new THREE.BoxGeometry(0.95, 0.95, 0.95);
      
      for (var x = 0; x < this.gridSize; x++) {
        for (var y = 0; y < this.gridSize; y++) {
          for (var z = 0; z < this.gridSize; z++) {
            if (this.grid[x][y][z] === -1) continue; // Skip empty
            
            var blockType = this.grid[x][y][z];
            var color = (blockType === -2 || blockType === -3) ? 0x000000 : this.getBlockColor(blockType);
            
            // Special blocks: more opaque with brighter glow to show color clearly
            var isSpecial = this.isSpecialBlock(blockType);
            var material = new THREE.MeshStandardMaterial({
              color: color,
              emissive: (blockType === -2 || blockType === -3) ? 0xffaa00 : color,
              emissiveIntensity: (blockType === -2 || blockType === -3) ? 0.5 : (isSpecial ? 0.3 : 0.15),
              metalness: 0.2,
              roughness: 0.6,
              transparent: isSpecial,
              opacity: isSpecial ? 0.6 : 1.0
            });
            
            // Glow moving blocks brighter
            var key = x + ',' + y + ',' + z;
            if (self.movingBlocks && self.movingBlocks[key]) {
              material.emissiveIntensity = 0.8;
            }
            
            // Check if block is at spawn edge (game over risk position)
            var isSpawnEdge = (x === 0 || x === self.gridSize - 1 || 
                              y === 0 || y === self.gridSize - 1 || 
                              z === 0 || z === self.gridSize - 1);
            
            // Highlight spawn edge blocks with warning color (but not center block)
            if (isSpawnEdge && blockType !== -2 && blockType !== -3) {
              material.emissive.setHex(0xff0000); // Red warning
              material.emissiveIntensity = 0.6; // Will be animated by throb
            }
            
            var mesh = new THREE.Mesh(geometry, material);
            mesh.position.set(x - offset, y - offset, z - offset);
            mesh.userData = { x: x, y: y, z: z, isSpawnEdge: isSpawnEdge };
            
            // Highlight center block in black AFTER mesh is created
            if (x === centerPos && y === centerPos && z === centerPos) {
              material.color.setHex(0x000000);
              material.emissive.setHex(0xffaa00);
              material.emissiveIntensity = 0.5;
              material.metalness = 0.3;
              material.roughness = 0.7;
              mesh.userData.isCenter = true;
              self.centerBlockMesh = mesh;
            }
            
            this.scene.add(mesh);
            
            // Add emoji texture to all faces for special blocks
            if (isSpecial) {
              var special = this.getSpecialBlockData(blockType);
              if (special) {
                this.logDebug('Creating texture for special block: ' + blockType + ' ' + special.name + ' ' + special.emoji);
                var emojiTexture = this.createEmojiTexture(special.emoji, color);
                
                // Create materials array - 6 faces with emoji texture on all
                var materials = [];
                for (var i = 0; i < 6; i++) {
                  materials.push(new THREE.MeshStandardMaterial({
                    color: color,
                    emissive: color,
                    emissiveIntensity: 0.3,
                    metalness: 0.2,
                    roughness: 0.6,
                    map: emojiTexture
                  }));
                }
                mesh.material = materials;
              }
            }
            
            this.blockMeshes[x + '_' + y + '_' + z] = mesh;
          }
        }
      }
      
      this.renderer.render(this.scene, this.camera);
    },

    explodeAllBlocks: function() {
      var self = this;
      clearInterval(this.timerInterval);
      
      // Animate all blocks exploding outward
      var blocks = [];
      for (var x = 0; x < this.gridSize; x++) {
        for (var y = 0; y < this.gridSize; y++) {
          for (var z = 0; z < this.gridSize; z++) {
            if (this.grid[x][y][z] !== -1) {
              var key = x + '_' + y + '_' + z;
              var mesh = this.blockMeshes[key];
              if (mesh) {
                blocks.push({ mesh: mesh, x: x, y: y, z: z });
              }
            }
          }
        }
      }
      
      var centerPos = this.gridSize / 2;
      var startTime = Date.now();
      var duration = 2000;
      
      function animate() {
        var elapsed = Date.now() - startTime;
        var progress = Math.min(elapsed / duration, 1);
        
        blocks.forEach(function(block) {
          var dx = block.x - centerPos;
          var dy = block.y - centerPos;
          var dz = block.z - centerPos;
          var distance = Math.sqrt(dx * dx + dy * dy + dz * dz) || 1;
          
          // Explode outward
          var explosionDistance = progress * distance * 3;
          block.mesh.position.x = (block.x - centerPos) + (dx / distance) * explosionDistance;
          block.mesh.position.y = (block.y - centerPos) + (dy / distance) * explosionDistance;
          block.mesh.position.z = (block.z - centerPos) + (dz / distance) * explosionDistance;
          
          // Rotate and fade
          block.mesh.rotation.x += 0.1;
          block.mesh.rotation.y += 0.15;
          block.mesh.rotation.z += 0.08;
          block.mesh.material.opacity = 1 - progress;
          block.mesh.material.transparent = true;
        });
        
        self.renderer.render(self.scene, self.camera);
        
        if (progress < 1) {
          requestAnimationFrame(animate);
        } else {
          self.advanceLevel();
        }
      }
      
      animate();
    },

    dropRandomBlocks: function(count) {
      var self = this;
      var blocksToPlace = count || this.level;
      
      // Calculate playable area boundaries
      var centerPos = Math.floor(this.gridSize / 2);
      var halfSize = Math.floor(this.playableSize / 2);
      var minIndex = centerPos - halfSize;
      var maxIndex = centerPos + halfSize;
      
      // Spawn blocks at the grid edges (same as regenerateBlocks) using FULL grid range
      var spawnMin = 0;
      var spawnMax = this.gridSize - 1;
      
      var faces = [
        { name: 'top', axis: 'y', value: spawnMax, range: [0, this.gridSize - 1] },
        { name: 'bottom', axis: 'y', value: spawnMin, range: [0, this.gridSize - 1] },
        { name: 'left', axis: 'x', value: spawnMin, range: [0, this.gridSize - 1] },
        { name: 'right', axis: 'x', value: spawnMax, range: [0, this.gridSize - 1] },
        { name: 'front', axis: 'z', value: spawnMax, range: [0, this.gridSize - 1] },
        { name: 'back', axis: 'z', value: spawnMin, range: [0, this.gridSize - 1] }
      ];
      
      var blocksPlaced = 0;
      var maxAttempts = blocksToPlace * 10;
      var attempts = 0;
      
      // Try to place all requested blocks
      while (blocksPlaced < blocksToPlace && attempts < maxAttempts) {
        var face = faces[Math.floor(Math.random() * faces.length)];
        var targetX, targetY, targetZ;
        
        // Generate position based on face
        var randVal1 = Math.floor(Math.random() * (face.range[1] - face.range[0] + 1)) + face.range[0];
        var randVal2 = Math.floor(Math.random() * (face.range[1] - face.range[0] + 1)) + face.range[0];
        
        if (face.axis === 'x') {
          targetX = face.value;
          targetY = randVal1;
          targetZ = randVal2;
        } else if (face.axis === 'y') {
          targetX = randVal1;
          targetY = face.value;
          targetZ = randVal2;
        } else { // z
          targetX = randVal1;
          targetY = randVal2;
          targetZ = face.value;
        }
        
        // If position is empty, place the block
        if (this.grid[targetX][targetY][targetZ] === -1) {
          this.grid[targetX][targetY][targetZ] = this.randomBlockType();
          blocksPlaced++;
        }
        
        attempts++;
      }
      
      // Check if we couldn't place enough blocks (game might be full)
      if (blocksPlaced < count && attempts >= maxAttempts) {
        // Check if shield is active
        if (this.hasShield) {
          this.logInfo('SHIELD ACTIVATED: Removing 50% of blocks');
          this.hasShield = false; // Consume shield
          this.showGameMessage('🛡️ Shield saved you! Removing half the blocks.');
          
          // Remove 50% of regular blocks from grid
          var allBlocks = [];
          for (var ix = 0; ix < this.gridSize; ix++) {
            for (var iy = 0; iy < this.gridSize; iy++) {
              for (var iz = 0; iz < this.gridSize; iz++) {
                if (this.grid[ix][iy][iz] >= 0) {
                  allBlocks.push({x: ix, y: iy, z: iz});
                }
              }
            }
          }
          
          // Shuffle and remove half
          allBlocks.sort(function() { return Math.random() - 0.5; });
          var toRemove = allBlocks.slice(0, Math.floor(allBlocks.length * 0.5));
          
          toRemove.forEach(function(block) {
            self.grid[block.x][block.y][block.z] = -1;
          });
          
          this.render3D();
          this.dropBlocks();
          return;
        }
        
        this.logError('GAME OVER: Could not place blocks (grid too full)');
        this.gameOver(false, 'Game Over! No space for new blocks.');
        return;
      }
      
      // Blocks are now in grid, render and settle them
      if (blocksPlaced > 0) {
        this.render3D();
        this.dropBlocks(function() {
          // Check for matches after new blocks settle
          self.processMatchesWithoutDrop(function() {
            self.completeTurn();
          });
        });
      } else {
        self.completeTurn();
      }
    },

    startCenterBlockShimmer: function() {
      var self = this;
      var startTime = Date.now();
      
      function shimmer() {
        if (!self.centerBlockMesh) {
          requestAnimationFrame(shimmer);
          return;
        }
        
        var elapsed = Date.now() - startTime;
        var intensity = 0.5 + Math.sin(elapsed * 0.003) * 0.3; // Oscillate between 0.2 and 0.8
        var scale = 1 + Math.sin(elapsed * 0.005) * 0.1; // Pulse scale
        
        self.centerBlockMesh.material.emissiveIntensity = intensity;
        self.centerBlockMesh.scale.set(scale, scale, scale);
        self.renderer.render(self.scene, self.camera);
        
        requestAnimationFrame(shimmer);
      }
      
      shimmer();
    },

    bindEvents: function() {
      var self = this;
      var canvas = document.getElementById('game-canvas');
      var isCameraRotating = false;
      var previousMousePosition = { x: 0, y: 0 };
      var mouseDownTime = 0;
      var mouseDownPos = { x: 0, y: 0 };
      
      // Mouse down - prepare for click or drag
      canvas.addEventListener('mousedown', function(event) {
        isCameraRotating = false;
        mouseDownTime = Date.now();
        previousMousePosition = { x: event.clientX, y: event.clientY };
        mouseDownPos = { x: event.clientX, y: event.clientY };
      });
      
      // Mouse move - camera rotation or show drag preview
      canvas.addEventListener('mousemove', function(event) {
        if (event.buttons === 1) {
          var deltaX = event.clientX - previousMousePosition.x;
          var deltaY = event.clientY - previousMousePosition.y;
          var totalMove = Math.sqrt(
            Math.pow(event.clientX - mouseDownPos.x, 2) + 
            Math.pow(event.clientY - mouseDownPos.y, 2)
          );
          
          // If moved more than 5 pixels, it's camera rotation
          if (totalMove > 5) {
            if (!isCameraRotating) {
              self.playWhooshSound();
              self.clearDropZones();
            }
            isCameraRotating = true;
            
            self.cameraAngle.theta += deltaX * 0.01;
            self.cameraAngle.phi += deltaY * 0.01;
            self.cameraAngle.phi = Math.max(0.1, Math.min(Math.PI - 0.1, self.cameraAngle.phi));
            
            self.updateCameraPosition();
            self.renderer.render(self.scene, self.camera);
          }
          
          previousMousePosition = { x: event.clientX, y: event.clientY };
        }
      });
      
      // Mouse up - handle click
      canvas.addEventListener('mouseup', function(event) {
        if (!isCameraRotating) {
          self.handleCanvasClick(event);
        }
        isCameraRotating = false;
      });
      
      // Mouse wheel for zoom
      canvas.addEventListener('wheel', function(event) {
        event.preventDefault();
        self.cameraDistance += event.deltaY * 0.01;
        self.cameraDistance = Math.max(self.playableSize * 1.5, Math.min(self.gridSize * 4, self.cameraDistance));
        self.updateCameraPosition();
        self.renderer.render(self.scene, self.camera);
      });
      
      // Touch support for mobile devices
      var touchStartPos = { x: 0, y: 0 };
      var touchStartTime = 0;
      var isTouchRotating = false;
      
      canvas.addEventListener('touchstart', function(event) {
        if (event.touches.length === 1) {
          event.preventDefault();
          var touch = event.touches[0];
          touchStartPos = { x: touch.clientX, y: touch.clientY };
          previousMousePosition = { x: touch.clientX, y: touch.clientY };
          touchStartTime = Date.now();
          isTouchRotating = false;
        }
      });
      
      canvas.addEventListener('touchmove', function(event) {
        if (event.touches.length === 1) {
          event.preventDefault();
          var touch = event.touches[0];
          var deltaX = touch.clientX - previousMousePosition.x;
          var deltaY = touch.clientY - previousMousePosition.y;
          var totalMove = Math.sqrt(
            Math.pow(touch.clientX - touchStartPos.x, 2) + 
            Math.pow(touch.clientY - touchStartPos.y, 2)
          );
          
          // If moved more than 10 pixels, it's camera rotation
          if (totalMove > 10) {
            if (!isTouchRotating) {
              self.playWhooshSound();
              self.clearDropZones();
            }
            isTouchRotating = true;
            
            self.cameraAngle.theta += deltaX * 0.01;
            self.cameraAngle.phi += deltaY * 0.01;
            self.cameraAngle.phi = Math.max(0.1, Math.min(Math.PI - 0.1, self.cameraAngle.phi));
            
            self.updateCameraPosition();
            self.renderer.render(self.scene, self.camera);
          }
          
          previousMousePosition = { x: touch.clientX, y: touch.clientY };
        }
      });
      
      canvas.addEventListener('touchend', function(event) {
        if (!isTouchRotating && event.changedTouches.length === 1) {
          var touch = event.changedTouches[0];
          // Simulate a click event for block selection
          var fakeEvent = {
            clientX: touch.clientX,
            clientY: touch.clientY
          };
          self.handleCanvasClick(fakeEvent);
        }
        isTouchRotating = false;
      });

      $('#new-game-btn').on('click', function() {
        self.newGame();
      });

      $('#drop-blocks-btn').on('click', function() {
        // Prevent multiple clicks while settling
        if (self.isSettling) {
          return;
        }
        
        var count = parseInt($('#drop-blocks-count').val()) || 100;
        count = Math.max(1, Math.min(200, count)); // Clamp between 1 and 200
        
        // Lock interaction
        self.isSettling = true;
        $(this).prop('disabled', true);
        
        self.dropRandomBlocks(count);
        
        // Unlock after a delay
        setTimeout(function() {
          self.isSettling = false;
          $('#drop-blocks-btn').prop('disabled', false);
        }, 1000);
      });

      $('#settle-blocks-btn').on('click', function() {
        self.logDebug('=== MANUAL SETTLE TRIGGERED ===');
        self.settleAllBlocksWithCount(function(moveCount) {
          self.logDebug('=== MANUAL SETTLE COMPLETE - Total moves: ' + moveCount + ' ===');
        });
      });

      $('#play-again-btn').on('click', function() {
        self.newGame();
        $('#game-over-modal').hide();
      });
      
      // Rotation control buttons (especially useful for mobile)
      $('#rotate-left-btn').on('click', function() {
        self.cameraAngle.theta -= Math.PI / 4; // 45 degrees
        self.updateCameraPosition();
        self.renderer.render(self.scene, self.camera);
        self.playWhooshSound();
      });
      
      $('#rotate-right-btn').on('click', function() {
        self.cameraAngle.theta += Math.PI / 4; // 45 degrees
        self.updateCameraPosition();
        self.renderer.render(self.scene, self.camera);
        self.playWhooshSound();
      });
      
      $('#flip-vertical-btn').on('click', function() {
        self.cameraAngle.phi -= Math.PI / 6; // 30 degrees
        self.cameraAngle.phi = Math.max(0.1, Math.min(Math.PI - 0.1, self.cameraAngle.phi));
        self.updateCameraPosition();
        self.renderer.render(self.scene, self.camera);
        self.playWhooshSound();
      });
      
      $('#flip-horizontal-btn').on('click', function() {
        self.cameraAngle.phi += Math.PI / 6; // 30 degrees
        self.cameraAngle.phi = Math.max(0.1, Math.min(Math.PI - 0.1, self.cameraAngle.phi));
        self.updateCameraPosition();
        self.renderer.render(self.scene, self.camera);
        self.playWhooshSound();
      });
      
      $('#reset-orientation-btn').on('click', function() {
        self.cameraAngle.theta = Math.PI / 4;
        self.cameraAngle.phi = Math.PI / 3;
        self.cameraDistance = self.playableSize * 3.5;
        self.updateCameraPosition();
        self.renderer.render(self.scene, self.camera);
        self.playWhooshSound();
      });
      
      // Show orientation controls (useful for mobile devices)
      $('.orientation-controls').show();
    },

    advanceLevel: function() {
      var self = this;
      
      // Reset explosion flag
      this.isExploding = false;
      
      if (this.level >= this.maxLevel) {
        // Beat final level!
        setTimeout(function() {
          self.gameOver(true, 'ULTIMATE VICTORY! You completed all ' + self.maxLevel + ' levels!');
        }, 2000);
        return;
      }
      
      // Advance to next level
      this.level++;
      this.updateLevel();
      
      // Brief pause then restart with new level
      setTimeout(function() {
        self.createGrid();
        self.render3D();
        self.moves = 0;
        $('#moves').text(self.moves);
        self.isSettling = false; // Unlock interactions for new level
      }, 2000);
    },

    cleanupOrphanedMeshes: function() {
      var self = this;
      var offset = this.gridSize / 2;
      
      // Build a set of valid block positions from the grid
      var validPositions = new Set();
      for (var x = 0; x < this.gridSize; x++) {
        for (var y = 0; y < this.gridSize; y++) {
          for (var z = 0; z < this.gridSize; z++) {
            // Include regular blocks (>=0) AND center block (-3) AND boundary blocks (-2)
            if (this.grid[x][y][z] >= 0 || this.grid[x][y][z] === -3 || this.grid[x][y][z] === -2) {
              validPositions.add(x + '_' + y + '_' + z);
            }
          }
        }
      }
      
      // Remove orphaned meshes from blockMeshes tracking object
      var keysToDelete = [];
      Object.keys(this.blockMeshes).forEach(function(key) {
        if (!validPositions.has(key)) {
          var mesh = self.blockMeshes[key];
          if (mesh) {
            self.scene.remove(mesh);
            if (mesh.geometry) mesh.geometry.dispose();
            if (mesh.material) {
              if (Array.isArray(mesh.material)) {
                mesh.material.forEach(function(mat) {
                  mat.dispose();
                });
              } else {
                mesh.material.dispose();
              }
            }
          }
          keysToDelete.push(key);
        }
      });
      
      keysToDelete.forEach(function(key) {
        delete self.blockMeshes[key];
      });
      
      // Remove orphaned meshes from scene.children
      var meshesToRemove = [];
      this.scene.children.forEach(function(child) {
        if (child.geometry && child.geometry.type === 'BoxGeometry') {
          // Convert world position back to grid position
          var gridX = Math.round(child.position.x + offset);
          var gridY = Math.round(child.position.y + offset);
          var gridZ = Math.round(child.position.z + offset);
          var key = gridX + '_' + gridY + '_' + gridZ;
          
          if (!validPositions.has(key)) {
            meshesToRemove.push(child);
          }
        }
      });
      
      meshesToRemove.forEach(function(mesh) {
        self.scene.remove(mesh);
        if (mesh.geometry) mesh.geometry.dispose();
        if (mesh.material) {
          if (Array.isArray(mesh.material)) {
            mesh.material.forEach(function(mat) {
              mat.dispose();
            });
          } else {
            mesh.material.dispose();
          }
        }
      });
      
      if (keysToDelete.length > 0 || meshesToRemove.length > 0) {
        this.logDebug('Cleaned up ' + keysToDelete.length + ' orphaned blockMeshes and ' + meshesToRemove.length + ' orphaned scene meshes');
      }
    },

    ensureCenterBlock: function() {
      var centerPos = Math.floor(this.gridSize / 2);
      var currentValue = this.grid[centerPos][centerPos][centerPos];
      
      this.logDebug('ensureCenterBlock check: grid[' + centerPos + '][' + centerPos + '][' + centerPos + '] = ' + currentValue);
      
      // Check if center block exists and has correct value
      if (currentValue !== -3) {
        this.logInfo('!!! CRITICAL: Center block corrupted (value=' + currentValue + '), restoring it');
        this.logInfo('!!! Stack trace when detected: ' + new Error().stack);
        
        // Restore center block with special marker (-3)
        this.grid[centerPos][centerPos][centerPos] = -3; // -3 = center block marker (protected)
        
        // Check if mesh exists
        var key = centerPos + '_' + centerPos + '_' + centerPos;
        if (!this.blockMeshes[key]) {
          this.logInfo('Center block mesh missing, recreating it now');
          
          // Recreate just the center block immediately
          var offset = this.gridSize / 2;
          var geometry = new THREE.BoxGeometry(0.95, 0.95, 0.95);
          var material = new THREE.MeshStandardMaterial({
            color: 0x000000,
            emissive: 0xffaa00,
            emissiveIntensity: 0.5,
            metalness: 0.3,
            roughness: 0.7,
            transparent: false
          });
          
          var mesh = new THREE.Mesh(geometry, material);
          mesh.position.set(
            centerPos - offset,
            centerPos - offset,
            centerPos - offset
          );
          mesh.userData = { x: centerPos, y: centerPos, z: centerPos, isCenter: true };
          
          this.scene.add(mesh);
          this.blockMeshes[key] = mesh;
          this.centerBlockMesh = mesh;
          
          this.logInfo('Center block mesh restored');
        }
      }
    },

    completeTurn: function() {
      this.logDebug('Turn complete, unlocking (isSettling = false)');
      
      // FULL CLEANUP: Remove ALL meshes from scene to prevent explosion remnants
      var self = this;
      var meshesToRemove = [];
      
      // Collect all meshes that should be removed (everything except camera and lights)
      this.scene.children.forEach(function(child) {
        if (child.type === 'Mesh' || child.geometry) {
          meshesToRemove.push(child);
        }
      });
      
      // Remove and dispose all meshes
      meshesToRemove.forEach(function(mesh) {
        self.scene.remove(mesh);
        if (mesh.geometry) mesh.geometry.dispose();
        if (mesh.material) {
          if (Array.isArray(mesh.material)) {
            mesh.material.forEach(function(mat) { mat.dispose(); });
          } else {
            mesh.material.dispose();
          }
        }
      });
      
      // Clear mesh tracking
      this.blockMeshes = {};
      
      // Ensure center block exists in grid
      this.ensureCenterBlock();
      
      // Rebuild entire scene from grid state
      this.render3D();
      
      // Unlock for next turn
      this.isSettling = false;
    },

    handleCanvasClick: function(event) {
      var canvas = document.getElementById('game-canvas');
      var rect = canvas.getBoundingClientRect();
      
      this.mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
      this.mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
      
      this.raycaster.setFromCamera(this.mouse, this.camera);
      
      var intersects = this.raycaster.intersectObjects(this.scene.children);
      
      if (intersects.length > 0) {
        var mesh = intersects[0].object;
        if (mesh.userData.x !== undefined) {
          this.handleBlockClick(mesh.userData.x, mesh.userData.y, mesh.userData.z);
        }
      }
    },

    handleBlockClick: function(x, y, z) {
      // Block interaction during settlement
      if (this.isSettling) {
        this.logDebug('Click blocked: isSettling is true');
        return;
      }
      
      // Allow clicking on empty spaces if we have a selected block (for drop zones)
      if (this.grid[x][y][z] === -1) {
        if (this.selectedBlock) {
          // Check if this is a valid drop zone
          if (this.isValidDropZone(this.selectedBlock.x, this.selectedBlock.y, this.selectedBlock.z, x, y, z)) {
            this.moveBlockToEmpty(this.selectedBlock.x, this.selectedBlock.y, this.selectedBlock.z, x, y, z);
            this.clearSelection();
            this.moves++;
            $('#moves').text(this.moves);
          }
        }
        return;
      }
      
      var centerPos = Math.floor(this.gridSize / 2);
      
      // Check if center block was clicked
      if (x === centerPos && y === centerPos && z === centerPos) {
        // Prevent multiple clicks during explosion
        if (this.isExploding) {
          return;
        }
        this.isExploding = true;
        this.isSettling = true; // Lock all interactions
        this.playSuccessSound();
        this.explodeAllBlocks();
        return;
      }
      
      var blockType = this.grid[x][y][z];
      var self = this;
      
      // Lock interaction immediately
      this.logDebug('Setting isSettling = true');
      this.isSettling = true;
      
      /* DISABLED: Special blocks now match by color instead of triggering on click
      // Check if it's a special block
      if (this.isSpecialBlock(blockType)) {
        this.playExplosionSound(1);
        this.handleSpecialBlock(blockType, x, y, z);
        return;
      }
      */
      
      /* DIRECT ELIMINATION MODE - commented out
      // Regular block logic
      this.playExplosionSound(1);
      this.moves++;
      $('#moves').text(this.moves);
      
      // Check freeze status
      var movesToDrop = this.freezeTurnsLeft > 0 ? 0 : this.moves;
      if (this.freezeTurnsLeft > 0) {
        this.freezeTurnsLeft--;
      }
      
      // Remove the clicked block with explosion animation, then drop new blocks
      this.removeMatches([{x: x, y: y, z: z}], false, function() {
        // After settlement completes, drop blocks if not frozen
        if (movesToDrop > 0) {
          self.dropRandomBlocks(movesToDrop);
        } else {
          self.completeTurn();
        }
      });
      */
      
      // SWAP MODE - Select first block, then swap with adjacent block or move to empty space
      if (!this.selectedBlock) {
        this.selectedBlock = { x: x, y: y, z: z };
        this.playSelectSound();
        var mesh = this.blockMeshes[x + '_' + y + '_' + z];
        if (mesh) {
          mesh.material.emissive = new THREE.Color(0xffffff);
          mesh.material.emissiveIntensity = 0.5;
        }
        // Show valid drop zones
        this.showValidDropZones(x, y, z);
        this.renderer.render(this.scene, this.camera);
        // Unlock immediately after selection
        this.isSettling = false;
      } else {
        var prev = this.selectedBlock;
        
        // Check if clicked position is adjacent
        if (this.isAdjacent3D(prev.x, prev.y, prev.z, x, y, z)) {
          // If clicked another block, swap them
          this.swapBlocks(prev.x, prev.y, prev.z, x, y, z);
          this.clearSelection();
          this.moves++;
          $('#moves').text(this.moves);
        } else {
          // Not adjacent, deselect
          this.clearSelection();
          this.isSettling = false;
        }
        
        this.renderer.render(this.scene, this.camera);
      }
    },

    clearSelection: function() {
      if (this.selectedBlock) {
        var prevMesh = this.blockMeshes[this.selectedBlock.x + '_' + this.selectedBlock.y + '_' + this.selectedBlock.z];
        if (prevMesh) {
          prevMesh.material.emissive = new THREE.Color(0x000000);
          prevMesh.material.emissiveIntensity = 0;
        }
        this.selectedBlock = null;
      }
      this.clearDropZones();
      this.playDeselectSound();
    },

    clearDropZones: function() {
      var self = this;
      this.dropZoneMeshes.forEach(function(mesh) {
        self.scene.remove(mesh);
      });
      this.dropZoneMeshes = [];
      this.validDropZones = [];
    },

    getDistanceFromCenter: function(x, y, z) {
      var centerPos = Math.floor(this.gridSize / 2);
      var dx = x - centerPos;
      var dy = y - centerPos;
      var dz = z - centerPos;
      return Math.sqrt(dx*dx + dy*dy + dz*dz);
    },

    isValidDropZone: function(fromX, fromY, fromZ, toX, toY, toZ) {
      // Check bounds first
      if (toX < 0 || toX >= this.gridSize || 
          toY < 0 || toY >= this.gridSize || 
          toZ < 0 || toZ >= this.gridSize) {
        return false;
      }
      
      // Must be adjacent
      if (!this.isAdjacent3D(fromX, fromY, fromZ, toX, toY, toZ)) {
        return false;
      }
      
      // Must be empty
      if (this.grid[toX][toY][toZ] !== -1) {
        return false;
      }
      
      // Must not move away from center (can move toward or maintain distance)
      var fromDist = this.getDistanceFromCenter(fromX, fromY, fromZ);
      var toDist = this.getDistanceFromCenter(toX, toY, toZ);
      
      return toDist <= fromDist;
    },

    showValidDropZones: function(x, y, z) {
      var self = this;
      var offset = this.gridSize / 2;
      
      // Clear existing drop zones
      this.clearDropZones();
      
      // Check all 6 adjacent positions
      var adjacentPositions = [
        [x-1, y, z], [x+1, y, z],
        [x, y-1, z], [x, y+1, z],
        [x, y, z-1], [x, y, z+1]
      ];
      
      var geometry = new THREE.BoxGeometry(0.85, 0.85, 0.85);
      
      adjacentPositions.forEach(function(pos) {
        var ax = pos[0], ay = pos[1], az = pos[2];
        
        if (self.isValidDropZone(x, y, z, ax, ay, az)) {
          // Create a semi-transparent green box to show valid drop zone
          var material = new THREE.MeshStandardMaterial({
            color: 0x00ff00,
            emissive: 0x00ff00,
            emissiveIntensity: 0.4,
            transparent: true,
            opacity: 0.3,
            depthTest: true
          });
          
          var mesh = new THREE.Mesh(geometry, material);
          mesh.position.set(ax - offset, ay - offset, az - offset);
          mesh.userData = { x: ax, y: ay, z: az, isDropZone: true };
          
          self.scene.add(mesh);
          self.dropZoneMeshes.push(mesh);
          self.validDropZones.push({ x: ax, y: ay, z: az });
        }
      });
    },

    moveBlockToEmpty: function(x1, y1, z1, x2, y2, z2) {
      this.logDebug('>>> MOVE: Block from (' + x1 + ',' + y1 + ',' + z1 + ') to empty (' + x2 + ',' + y2 + ',' + z2 + ')');
      var self = this;
      var centerPos = Math.floor(this.gridSize / 2);
      
      // NEVER allow moving to center block position
      if (x2 === centerPos && y2 === centerPos && z2 === centerPos) {
        this.logInfo('!!! BLOCKED: Attempted to move block to CENTER position (' + x2 + ',' + y2 + ',' + z2 + ')');
        return;
      }
      
      // Move the block to the empty space
      this.grid[x2][y2][z2] = this.grid[x1][y1][z1];
      this.grid[x1][y1][z1] = -1;
      
      // Re-render to show the move
      this.render3D();
      
      // Settle blocks and spawn new blocks
      setTimeout(function() {
        self.dropBlocks(function() {
          // Spawn blocks equal to level
          var blocksToSpawn = self.level;
          self.regenerateBlocks(blocksToSpawn);
        });
      }, 300);
    },

    // Special Block Effect Functions
    handleSpecialBlock: function(blockType, x, y, z) {
      var self = this;
      
      // Remove the special block first
      this.grid[x][y][z] = -1;
      this.render3D();
      
      var special = this.getSpecialBlockData(blockType);
      if (special) {
        this.logInfo('Activated: ' + special.name + ' ' + special.emoji);
      }
      
      switch(blockType) {
        case 100: this.effectBomb(x, y, z); break;
        case 101: this.effectLightning(x, y, z); break;
        case 105: this.effectShuffler(x, y, z); break;
        case 106: this.effectLaser(x, y, z); break;
        case 107: this.effectFreeze(x, y, z); break;
        case 108: this.effectMultiplier(x, y, z); break;
        case 109: this.effectJackpot(x, y, z); break;
        case 110: this.effectComboExtender(x, y, z); break;
        case 112: this.effectTeleporter(x, y, z); break;
        case 113: this.effectColorChanger(x, y, z); break;
        case 114: this.effectShield(x, y, z); break;
      }
    },

    // 100: Bomb - 3x3x3 explosion
    effectBomb: function(x, y, z) {
      var self = this;
      var toRemove = [];
      var centerPos = Math.floor(this.gridSize / 2);
      
      self.logInfo('💣 BOMB activated at (' + x + ',' + y + ',' + z + ')');
      self.logInfo('effectBomb called from: ' + (new Error().stack.split('\n')[2] || 'unknown'));
      
      // Destroy all blocks in 3x3x3 cube around bomb position (excluding the bomb itself)
      for (var dx = -1; dx <= 1; dx++) {
        for (var dy = -1; dy <= 1; dy++) {
          for (var dz = -1; dz <= 1; dz++) {
            // Skip the bomb itself - it will be removed by removeMatches
            if (dx === 0 && dy === 0 && dz === 0) continue;
            
            var nx = x + dx, ny = y + dy, nz = z + dz;
            
            // CRITICAL: Never destroy the center block
            if (nx === centerPos && ny === centerPos && nz === centerPos) {
              self.logInfo('💣 Bomb: Skipping center block at (' + nx + ',' + ny + ',' + nz + ')');
              continue;
            }
            
            if (nx >= 0 && nx < this.gridSize && ny >= 0 && ny < this.gridSize && nz >= 0 && nz < this.gridSize) {
              var blockType = this.grid[nx][ny][nz];
              if (blockType >= 0) {
                self.logDebug('  Destroying block at (' + nx + ',' + ny + ',' + nz + ') type=' + blockType);
                toRemove.push({x: nx, y: ny, z: nz});
              }
            }
          }
        }
      }
      
      self.logInfo('💣 BOMB destroying ' + toRemove.length + ' additional blocks');
      
      // Add these blocks to be removed along with the normal matches
      if (toRemove.length > 0) {
        // Remove the blocks immediately from grid and scene
        var offset = this.gridSize / 2;
        toRemove.forEach(function(pos) {
          self.logDebug('  💣 Bomb removing block at (' + pos.x + ',' + pos.y + ',' + pos.z + ')');
          self.grid[pos.x][pos.y][pos.z] = -1;
          
          // Remove mesh
          var key = pos.x + '_' + pos.y + '_' + pos.z;
          var mesh = self.blockMeshes[key];
          if (mesh) {
            self.scene.remove(mesh);
            if (mesh.geometry) mesh.geometry.dispose();
            if (mesh.material) {
              if (Array.isArray(mesh.material)) {
                mesh.material.forEach(function(mat) { mat.dispose(); });
              } else {
                mesh.material.dispose();
              }
            }
            delete self.blockMeshes[key];
          }
          
          // Also remove from scene.children
          var worldX = pos.x - offset;
          var worldY = pos.y - offset;
          var worldZ = pos.z - offset;
          var orphaned = self.scene.children.find(function(child) {
            return child.position.x === worldX && 
                   child.position.y === worldY && 
                   child.position.z === worldZ &&
                   child.geometry && child.geometry.type === 'BoxGeometry';
          });
          if (orphaned) {
            self.scene.remove(orphaned);
            if (orphaned.geometry) orphaned.geometry.dispose();
            if (orphaned.material) {
              if (Array.isArray(orphaned.material)) {
                orphaned.material.forEach(function(mat) { mat.dispose(); });
              } else {
                orphaned.material.dispose();
              }
            }
          }
        });
      }
    },

    // 101: Lightning - Destroy all blocks of same color
    effectLightning: function(x, y, z) {
      var self = this;
      var toRemove = [];
      var centerPos = Math.floor(this.gridSize / 2);
      
      self.logInfo('⚡ LIGHTNING activated at (' + x + ',' + y + ',' + z + ')');
      self.logInfo('effectLightning called from: ' + (new Error().stack.split('\n')[2] || 'unknown'));
      
      // Pick a random regular color
      var targetColor = Math.floor(Math.random() * this.blockTypes);
      
      for (var ix = 0; ix < this.gridSize; ix++) {
        for (var iy = 0; iy < this.gridSize; iy++) {
          for (var iz = 0; iz < this.gridSize; iz++) {
            // CRITICAL: Never destroy the center block
            if (ix === centerPos && iy === centerPos && iz === centerPos) {
              self.logInfo('⚡ Lightning: Skipping center block at (' + ix + ',' + iy + ',' + iz + ')');
              continue;
            }
            
            if (this.grid[ix][iy][iz] === targetColor) {
              toRemove.push({x: ix, y: iy, z: iz});
            }
          }
        }
      }
      
      if (toRemove.length > 0) {
        this.removeMatches(toRemove, false, function() {
          self.completeTurn();
        });
      } else {
        this.completeTurn();
      }
    },

    // 105: Shuffler - Randomly reposition 10 blocks
    effectShuffler: function(x, y, z) {
      var self = this;
      var blocks = [];
      for (var ix = 0; ix < this.gridSize; ix++) {
        for (var iy = 0; iy < this.gridSize; iy++) {
          for (var iz = 0; iz < this.gridSize; iz++) {
            if (this.grid[ix][iy][iz] >= 0) {
              blocks.push({x: ix, y: iy, z: iz, type: this.grid[ix][iy][iz]});
            }
          }
        }
      }
      
      // Shuffle 10 random blocks
      for (var i = 0; i < Math.min(10, blocks.length); i++) {
        var idx1 = Math.floor(Math.random() * blocks.length);
        var idx2 = Math.floor(Math.random() * blocks.length);
        
        var temp = this.grid[blocks[idx1].x][blocks[idx1].y][blocks[idx1].z];
        this.grid[blocks[idx1].x][blocks[idx1].y][blocks[idx1].z] = this.grid[blocks[idx2].x][blocks[idx2].y][blocks[idx2].z];
        this.grid[blocks[idx2].x][blocks[idx2].y][blocks[idx2].z] = temp;
      }
      
      this.render3D();
      this.dropBlocks(function() {
        self.completeTurn();
      });
    },

    // 106: Laser - Destroy entire line
    effectLaser: function(x, y, z) {
      var self = this;
      var toRemove = [];
      var centerPos = Math.floor(this.gridSize / 2);
      
      self.logInfo('🎯 LASER activated at (' + x + ',' + y + ',' + z + ')');
      self.logInfo('effectLaser called from: ' + (new Error().stack.split('\n')[2] || 'unknown'));
      
      // Pick random axis
      var axis = Math.floor(Math.random() * 3);
      
      if (axis === 0) { // X axis
        for (var ix = 0; ix < this.gridSize; ix++) {
          // CRITICAL: Never destroy the center block
          if (ix === centerPos && y === centerPos && z === centerPos) continue;
          if (this.grid[ix][y][z] >= 0) toRemove.push({x: ix, y: y, z: z});
        }
      } else if (axis === 1) { // Y axis
        for (var iy = 0; iy < this.gridSize; iy++) {
          // CRITICAL: Never destroy the center block
          if (x === centerPos && iy === centerPos && z === centerPos) continue;
          if (this.grid[x][iy][z] >= 0) toRemove.push({x: x, y: iy, z: z});
        }
      } else { // Z axis
        for (var iz = 0; iz < this.gridSize; iz++) {
          // CRITICAL: Never destroy the center block
          if (x === centerPos && y === centerPos && iz === centerPos) continue;
          if (this.grid[x][y][iz] >= 0) toRemove.push({x: x, y: y, z: iz});
        }
      }
      
      if (toRemove.length > 0) {
        this.removeMatchesWithDisintegration(toRemove, false, function() {
          self.completeTurn();
        });
      } else {
        this.completeTurn();
      }
    },

    // 107: Freeze - No blocks drop for 2 turns
    effectFreeze: function(x, y, z) {
      var self = this;
      this.freezeTurnsLeft = 2;
      this.showGameMessage('❄️ Freeze activated! No new blocks for 2 turns.');
      this.dropBlocks(function() {
        self.completeTurn();
      });
    },

    // 108: Multiplier - Double points for 5 explosions
    effectMultiplier: function(x, y, z) {
      var self = this;
      this.pointMultiplier = 2;
      this.multiplierTurnsLeft = 5;
      this.showGameMessage('💎 2x Multiplier activated for 5 explosions!');
      this.dropBlocks(function() {
        self.completeTurn();
      });
    },

    // 109: Jackpot - Bonus points
    effectJackpot: function(x, y, z) {
      var self = this;
      var bonus = 100 * this.level;
      this.score += bonus;
      $('#score').text(this.score);
      this.showGameMessage('🎰 Jackpot! +' + bonus + ' points!');
      this.dropBlocks(function() {
        self.completeTurn();
      });
    },

    // 110: Combo Extender - Keep combo running
    effectComboExtender: function(x, y, z) {
      var self = this;
      this.showGameMessage('⭐ Combo extender activated!');
      // TODO: Implement combo timer system
      this.dropBlocks(function() {
        self.completeTurn();
      });
    },

    // 112: Teleporter - Swap 5 random block pairs
    effectTeleporter: function(x, y, z) {
      var self = this;
      var blocks = [];
      for (var ix = 0; ix < this.gridSize; ix++) {
        for (var iy = 0; iy < this.gridSize; iy++) {
          for (var iz = 0; iz < this.gridSize; iz++) {
            if (this.grid[ix][iy][iz] >= 0) {
              blocks.push({x: ix, y: iy, z: iz});
            }
          }
        }
      }
      
      for (var i = 0; i < 5 && blocks.length >= 2; i++) {
        var idx1 = Math.floor(Math.random() * blocks.length);
        var idx2 = Math.floor(Math.random() * blocks.length);
        
        var temp = this.grid[blocks[idx1].x][blocks[idx1].y][blocks[idx1].z];
        this.grid[blocks[idx1].x][blocks[idx1].y][blocks[idx1].z] = this.grid[blocks[idx2].x][blocks[idx2].y][blocks[idx2].z];
        this.grid[blocks[idx2].x][blocks[idx2].y][blocks[idx2].z] = temp;
      }
      
      this.render3D();
      this.dropBlocks(function() {
        self.completeTurn();
      });
    },

    // 113: Color Changer - Convert one color to another
    effectColorChanger: function(x, y, z) {
      var fromColor = Math.floor(Math.random() * this.blockTypes);
      var toColor = Math.floor(Math.random() * this.blockTypes);
      
      for (var ix = 0; ix < this.gridSize; ix++) {
        for (var iy = 0; iy < this.gridSize; iy++) {
          for (var iz = 0; iz < this.gridSize; iz++) {
            if (this.grid[ix][iy][iz] === fromColor) {
              this.grid[ix][iy][iz] = toColor;
            }
          }
        }
      }
      
      this.render3D();
      this.isSettling = false;
      this.showGameMessage('🎨 Color changed!');
    },

    // 114: Shield - Save from game over once
    effectShield: function(x, y, z) {
      var self = this;
      this.hasShield = true;
      this.showGameMessage('🛡️ Shield activated! One free continue.');
      this.dropBlocks(function() {
        self.isSettling = false;
      });
    },

    isAdjacent3D: function(x1, y1, z1, x2, y2, z2) {
      var dx = Math.abs(x1 - x2);
      var dy = Math.abs(y1 - y2);
      var dz = Math.abs(z1 - z2);
      return (dx + dy + dz) === 1;
    },

    updateComboDisplay: function() {
      var total = this.initialMatchCount + this.comboMatchCount;
      var display = '';
      
      if (this.initialMatchCount > 0) {
        display = 'Match: ' + this.initialMatchCount;
        if (this.comboMatchCount > 0) {
          display += ' | Combos: ' + this.comboMatchCount;
        }
        display += ' | Total: ' + total;
      }
      
      $('#combo-stats').text(display);
    },

    showComboCongrats: function() {
      var combo = this.comboMatchCount;
      
      // Only show for combos > 60
      if (combo <= 60) {
        return;
      }
      
      var message = '';
      var emoji = '';
      
      if (combo >= 1000) {
        message = 'LEGENDARY COMBO!';
        emoji = '🌟✨💫🔥';
      } else if (combo >= 950) {
        message = 'GODLIKE!';
        emoji = '👑🌟💫';
      } else if (combo >= 900) {
        message = 'TRANSCENDENT!';
        emoji = '🚀🌟💫';
      } else if (combo >= 850) {
        message = 'UNSTOPPABLE!';
        emoji = '⚡🔥💥';
      } else if (combo >= 800) {
        message = 'PHENOMENAL!';
        emoji = '💎✨';
      } else if (combo >= 750) {
        message = 'SPECTACULAR!';
        emoji = '🎆🌟';
      } else if (combo >= 700) {
        message = 'MAGNIFICENT!';
        emoji = '🏆✨';
      } else if (combo >= 650) {
        message = 'INCREDIBLE!';
        emoji = '🌟💥';
      } else if (combo >= 600) {
        message = 'OUTSTANDING!';
        emoji = '⭐🔥';
      } else if (combo >= 550) {
        message = 'MARVELOUS!';
        emoji = '✨💫';
      } else if (combo >= 500) {
        message = 'AMAZING!';
        emoji = '🎉💥';
      } else if (combo >= 450) {
        message = 'FANTASTIC!';
        emoji = '🔥⚡';
      } else if (combo >= 400) {
        message = 'SUPER!';
        emoji = '💫⭐';
      } else if (combo >= 350) {
        message = 'EXCELLENT!';
        emoji = '✨🌟';
      } else if (combo >= 300) {
        message = 'TERRIFIC!';
        emoji = '🎊💥';
      } else if (combo >= 250) {
        message = 'WONDERFUL!';
        emoji = '🌈✨';
      } else if (combo >= 200) {
        message = 'AWESOME!';
        emoji = '🎯🔥';
      } else if (combo >= 150) {
        message = 'GREAT!';
        emoji = '⭐💫';
      } else if (combo >= 100) {
        message = 'NICE!';
        emoji = '🎉';
      } else if (combo > 60) {
        message = 'COMBO!';
        emoji = '🔥';
      }
      
      this.showGameMessage(emoji + ' ' + message + ' ' + combo + 'x COMBO! ' + emoji);
    },

    swapBlocks: function(x1, y1, z1, x2, y2, z2, isUndo) {
      this.logDebug('>>> STEP 1: Player Swap - (' + x1 + ',' + y1 + ',' + z1 + ') <-> (' + x2 + ',' + y2 + ',' + z2 + ')');
      
      // Lock interaction during swap processing (only on initial swap, not undo)
      if (!isUndo) {
        this.isSettling = true;
      }
      
      // Reset combo tracking for new move
      this.initialMatchCount = 0;
      this.comboMatchCount = 0;
      this.updateComboDisplay();
      
      var temp = this.grid[x1][y1][z1];
      this.grid[x1][y1][z1] = this.grid[x2][y2][z2];
      this.grid[x2][y2][z2] = temp;
      
      this.render3D();
      
      // If this is an undo swap, don't check for matches
      if (isUndo) {
        this.isSettling = false; // Unlock after undo
        return;
      }
      
      var self = this;
      setTimeout(function() {
        var matches1 = self.checkMatchAt(x1, y1, z1);
        var matches2 = self.checkMatchAt(x2, y2, z2);
        
        if (matches1.length >= self.minMatch || matches2.length >= self.minMatch) {
          // Process matches and spawn new blocks
          self.processMatches();
        } else {
          // No match - settle and spawn new blocks anyway
          self.dropBlocks(function() {
            // Spawn blocks equal to level
            var blocksToSpawn = self.level;
            self.regenerateBlocks(blocksToSpawn);
          });
        }
      }, 300);
    },

    checkMatchAt: function(x, y, z) {
      var centerPos = Math.floor(this.gridSize / 2);
      
      // Never match the center block of the grid
      if (x === centerPos && y === centerPos && z === centerPos) {
        return [];
      }
      
      if (this.grid[x][y][z] === -1 || this.grid[x][y][z] === -2 || this.grid[x][y][z] === -3) return [];
      
      var color = this.getBlockMatchColor(this.grid[x][y][z]);
      
      // Use flood-fill to find all connected blocks of the same color
      var visited = {};
      var toCheck = [{x: x, y: y, z: z}];
      var matches = [];
      
      while (toCheck.length > 0) {
        var current = toCheck.pop();
        var key = current.x + ',' + current.y + ',' + current.z;
        
        // Skip if already visited
        if (visited[key]) continue;
        visited[key] = true;
        
        // Skip if out of bounds
        if (current.x < 0 || current.x >= this.gridSize ||
            current.y < 0 || current.y >= this.gridSize ||
            current.z < 0 || current.z >= this.gridSize) {
          continue;
        }
        
        // Skip if wrong color or empty
        var blockType = this.grid[current.x][current.y][current.z];
        if (blockType === -1 || blockType === -2 || blockType === -3) continue;
        if (this.getBlockMatchColor(blockType) !== color) continue;
        
        // Skip the center block of the entire grid (not the starting position)
        if (current.x === centerPos && current.y === centerPos && current.z === centerPos) {
          continue;
        }
        
        // This block matches - add it (including the starting block at x,y,z)
        matches.push({x: current.x, y: current.y, z: current.z});
        
        // Check all 6 adjacent positions (up, down, left, right, forward, back)
        toCheck.push({x: current.x - 1, y: current.y, z: current.z});
        toCheck.push({x: current.x + 1, y: current.y, z: current.z});
        toCheck.push({x: current.x, y: current.y - 1, z: current.z});
        toCheck.push({x: current.x, y: current.y + 1, z: current.z});
        toCheck.push({x: current.x, y: current.y, z: current.z - 1});
        toCheck.push({x: current.x, y: current.y, z: current.z + 1});
      }
      
      // Only return if we have enough blocks
      if (matches.length >= this.minMatch) {
        return matches;
      }
      
      return [];
    },

    processMatches: function() {
      this.logDebug('>>> STEP 2: Check Initial Matches');
      var allMatches = [];
      
      for (var x = 0; x < this.gridSize; x++) {
        for (var y = 0; y < this.gridSize; y++) {
          for (var z = 0; z < this.gridSize; z++) {
            var matches = this.checkMatchAt(x, y, z);
            if (matches.length >= this.minMatch) {
              allMatches = allMatches.concat(matches);
            }
          }
        }
      }
      
      if (allMatches.length > 0) {
        this.initialMatchCount = allMatches.length;
        this.updateComboDisplay();
        this.removeMatches(allMatches, false);
        var points = allMatches.length * 10 * this.pointMultiplier;
        this.score += points;
        $('#score').text(this.score);
        // Decrement multiplier turns
        if (this.multiplierTurnsLeft > 0) {
          this.multiplierTurnsLeft--;
          if (this.multiplierTurnsLeft === 0) {
            this.pointMultiplier = 1;
          }
        }
      }
    },

    processMatchesWithoutDrop: function(callback) {
      this.logDebug('>>> STEP 5: Check Chain Matches');
      var self = this;
      var allMatches = [];
      
      for (var x = 0; x < this.gridSize; x++) {
        for (var y = 0; y < this.gridSize; y++) {
          for (var z = 0; z < this.gridSize; z++) {
            var matches = this.checkMatchAt(x, y, z);
            if (matches.length >= this.minMatch) {
              allMatches = allMatches.concat(matches);
            }
          }
        }
      }
      
      if (allMatches.length > 0) {
        this.comboMatchCount += allMatches.length;
        this.updateComboDisplay();
        this.showComboCongrats();
        this.removeMatches(allMatches, true, callback);
        var points = allMatches.length * 10 * this.pointMultiplier;
        this.score += points;
        $('#score').text(this.score);
        // Decrement multiplier turns
        if (this.multiplierTurnsLeft > 0) {
          this.multiplierTurnsLeft--;
          if (this.multiplierTurnsLeft === 0) {
            this.pointMultiplier = 1;
          }
        }
      } else {
        // No more matches, trigger callback if provided
        if (callback) {
          setTimeout(callback, 100 + Math.floor((Math.random() - 0.5) * 300)); // 100ms ± 150ms
        }
      }
    },

    removeMatches: function(matches, skipDrop, callback) {
      var self = this;
      var eliminatedCount = matches.length;
      var offset = this.gridSize / 2;
      var centerPos = Math.floor(this.gridSize / 2);
      
      self.logInfo('>>> STEP 3: Explosion - ' + eliminatedCount + ' blocks (skipDrop=' + skipDrop + ')');
      self.logInfo('removeMatches called from: ' + (new Error().stack.split('\n')[2] || 'unknown'));
      
      // Log each block being removed
      matches.forEach(function(match) {
        var blockType = self.grid[match.x][match.y][match.z];
        self.logInfo('  Removing block at (' + match.x + ',' + match.y + ',' + match.z + ') type=' + blockType);
        
        // CRITICAL CHECK: Verify we're not removing center block
        if (match.x === centerPos && match.y === centerPos && match.z === centerPos) {
          self.logInfo('!!! ERROR: Attempt to remove CENTER BLOCK at (' + match.x + ',' + match.y + ',' + match.z + ')');
          self.logInfo('!!! Call stack: ' + new Error().stack);
        }
      });
      
      // Check for special blocks in the matches and trigger their effects
      var specialBlocksToTrigger = [];
      matches.forEach(function(match) {
        var blockType = self.grid[match.x][match.y][match.z];
        if (blockType >= 100) { // Special block
          specialBlocksToTrigger.push({
            type: blockType,
            x: match.x,
            y: match.y,
            z: match.z
          });
        }
      });
      
      // Trigger special block effects
      if (specialBlocksToTrigger.length > 0) {
        self.logDebug('Triggering ' + specialBlocksToTrigger.length + ' special block(s)');
        specialBlocksToTrigger.forEach(function(special) {
          self.handleSpecialBlock(special.type, special.x, special.y, special.z);
        });
      }
      
      // Play explosion sound
      this.playExplosionSound(eliminatedCount);
      
      // Animate explosion before removing
      // Scale animation timing based on chain size
      var animStepDelay = 160; // Base delay per frame (doubled)
      if (eliminatedCount > 100) animStepDelay = 240;
      if (eliminatedCount > 500) animStepDelay = 360;
      if (eliminatedCount > 1000) animStepDelay = 480;
      
      matches.forEach(function(match) {
        // Add ±150ms randomization per block for organic cascading effect
        var randomizedDelay = animStepDelay + Math.floor((Math.random() - 0.5) * 300);
        
        var worldX = match.x - offset;
        var worldY = match.y - offset;
        var worldZ = match.z - offset;
        
        var mesh = self.scene.children.find(function(child) {
          return child.position.x === worldX && 
                 child.position.y === worldY && 
                 child.position.z === worldZ &&
                 child.geometry && child.geometry.type === 'BoxGeometry';
        });
        
        if (mesh) {
          // Explosion animation: scale up, rotate, brighten and fade out
          var startScale = 1;
          var endScale = 4;
          var steps = 16; // Increased to 16 for very smooth animations
          var currentStep = 0;
          
          // Handle both single material and material array (for special blocks)
          var material = Array.isArray(mesh.material) ? mesh.material[0] : mesh.material;
          var originalColor = material.color.getHex();
          
          var explode = function() {
            currentStep++;
            var progress = currentStep / steps;
            var scale = startScale + (endScale - startScale) * progress;
            mesh.scale.set(scale, scale, scale);
            
            // Rotate for dramatic effect
            mesh.rotation.x += 0.2;
            mesh.rotation.y += 0.3;
            mesh.rotation.z += 0.1;
            
            // Brighten then fade - handle both material types
            var brightness = progress < 0.3 ? 1 + progress * 3 : 1 + (1 - progress) * 2;
            if (Array.isArray(mesh.material)) {
              mesh.material.forEach(function(mat) {
                mat.emissive.setHex(originalColor);
                mat.emissiveIntensity = brightness;
                mat.opacity = 1 - progress;
                mat.transparent = true;
              });
            } else {
              mesh.material.emissive.setHex(originalColor);
              mesh.material.emissiveIntensity = brightness;
              mesh.material.opacity = 1 - progress;
              mesh.material.transparent = true;
            }
            
            if (currentStep < steps) {
              setTimeout(explode, randomizedDelay);
            }
          };
          explode();
        }
      });
      
      // Calculate removal delay based on animation timing
      // 16 steps * base delay + max randomization (+150ms) + 5ms buffer = total animation time
      var removalDelay = (animStepDelay * 16) + 150 + 5;
      
      setTimeout(function() {
        // Remove matched blocks from grid and their meshes
        matches.forEach(function(match) {
          self.logDebug('  Setting grid[' + match.x + '][' + match.y + '][' + match.z + '] = -1 (was ' + self.grid[match.x][match.y][match.z] + ')');
          
          // CRITICAL CHECK before clearing - SKIP center block instead of removing it
          if (match.x === centerPos && match.y === centerPos && match.z === centerPos) {
            self.logInfo('!!! CRITICAL ERROR: CENTER BLOCK in matches array - SKIPPING removal!');
            self.logInfo('!!! Stack trace: ' + new Error().stack);
            return; // Skip this block - don't remove it
          }
          
          self.grid[match.x][match.y][match.z] = -1;
          
          var worldX = match.x - offset;
          var worldY = match.y - offset;
          var worldZ = match.z - offset;
          
          // Remove the specific mesh for this block from blockMeshes
          var key = match.x + '_' + match.y + '_' + match.z;
          var mesh = self.blockMeshes[key];
          if (mesh) {
            self.scene.remove(mesh);
            if (mesh.geometry) mesh.geometry.dispose();
            
            // Handle both single material and material array
            if (mesh.material) {
              if (Array.isArray(mesh.material)) {
                mesh.material.forEach(function(mat) {
                  mat.dispose();
                });
              } else {
                mesh.material.dispose();
              }
            }
            
            delete self.blockMeshes[key];
          }
          
          // Also search scene.children for any mesh at this position (in case it wasn't in blockMeshes)
          var orphanedMesh = self.scene.children.find(function(child) {
            return child.position.x === worldX && 
                   child.position.y === worldY && 
                   child.position.z === worldZ &&
                   child.geometry && child.geometry.type === 'BoxGeometry';
          });
          
          if (orphanedMesh) {
            self.scene.remove(orphanedMesh);
            if (orphanedMesh.geometry) orphanedMesh.geometry.dispose();
            if (orphanedMesh.material) {
              if (Array.isArray(orphanedMesh.material)) {
                orphanedMesh.material.forEach(function(mat) {
                  mat.dispose();
                });
              } else {
                orphanedMesh.material.dispose();
              }
            }
          }
        });
        // DO NOT call render3D() here - it would recreate all meshes at their current positions
        // Settlement will animate blocks to their new positions
      }, 250);
      
      setTimeout(function() {
        if (!skipDrop) {
          // Drop blocks toward center
          self.dropBlocks(function() {
            // After settling, check for chain matches without dropping
            self.processMatchesWithoutDrop(function() {
              // After all chain matches cleared, settle again before regenerating
              self.logDebug('>>> STEP 4b: Final Settle Before Regeneration');
              self.dropBlocks(function() {
                // Now regenerate blocks (handles its own completion via completeTurn)
                self.regenerateBlocks(eliminatedCount);
              });
            });
          });
        } else {
          // Just check for more matches without dropping, then callback
          setTimeout(function() {
            self.processMatchesWithoutDrop(callback);
          }, 300);
        }
      }, Math.max(100 / Math.pow(2, this.comboMatchCount > 0 ? Math.floor(this.comboMatchCount / 3) : 0), 10));
    },
    
    removeMatchesWithDisintegration: function(toRemove, eliminatedCount, callback, skipDrop) {
      var self = this;
      this.logDebug('Removing ' + toRemove.length + ' blocks with disintegration animation');
      
      // Prevent center block interactions during animation
      this.isExploding = true;
      
      // Create particle system for each block
      var animationSteps = 12; // More steps for smoother dissolve
      var stepDuration = 200; // ms per step (8x original for very slow animations)
      var currentStep = 0;
      
      // Store original properties
      toRemove.forEach(function(block) {
        if (block && block.mesh) {
          block.originalScale = block.mesh.scale.clone();
          block.originalOpacity = block.mesh.material.opacity;
          
          // Create particle effect - break block into smaller pieces
          var particleCount = 8;
          block.particles = [];
          
          for (var i = 0; i < particleCount; i++) {
            var particleGeometry = new THREE.BoxGeometry(0.1, 0.1, 0.1);
            var particleMaterial = new THREE.MeshLambertMaterial({
              color: block.mesh.material.color,
              transparent: true,
              opacity: 1
            });
            var particle = new THREE.Mesh(particleGeometry, particleMaterial);
            
            // Position particles around the block
            particle.position.copy(block.mesh.position);
            particle.position.x += (Math.random() - 0.5) * 0.3;
            particle.position.y += (Math.random() - 0.5) * 0.3;
            particle.position.z += (Math.random() - 0.5) * 0.3;
            
            // Random velocity for each particle
            particle.velocity = new THREE.Vector3(
              (Math.random() - 0.5) * 0.02,
              (Math.random() - 0.5) * 0.02,
              (Math.random() - 0.5) * 0.02
            );
            
            self.scene.add(particle);
            block.particles.push(particle);
          }
        }
      });
      
      // Animate disintegration
      var animateStep = function() {
        currentStep++;
        var progress = currentStep / animationSteps;
        
        toRemove.forEach(function(block) {
          if (block && block.mesh) {
            // Main block fades and shrinks
            block.mesh.scale.multiplyScalar(0.92);
            block.mesh.material.opacity = block.originalOpacity * (1 - progress);
            
            // Particles spread out and fade
            if (block.particles) {
              block.particles.forEach(function(particle) {
                particle.position.add(particle.velocity);
                particle.velocity.multiplyScalar(1.1); // Accelerate outward
                particle.material.opacity = 1 - progress;
                particle.scale.multiplyScalar(0.95);
              });
            }
          }
        });
        
        if (currentStep < animationSteps) {
          setTimeout(animateStep, stepDuration + Math.floor((Math.random() - 0.5) * 300)); // ± 150ms
        } else {
          // Animation complete - wait 5ms before cleanup
          setTimeout(function() {
          // Animation complete - clean up
          toRemove.forEach(function(block) {
            if (block && block.mesh) {
              self.scene.remove(block.mesh);
              block.mesh.geometry.dispose();
              block.mesh.material.dispose();
              
              // Remove particles
              if (block.particles) {
                block.particles.forEach(function(particle) {
                  self.scene.remove(particle);
                  particle.geometry.dispose();
                  particle.material.dispose();
                });
              }
              
              // Clear grid position
              var pos = block.gridPosition;
              if (pos && self.grid[pos.x] && self.grid[pos.x][pos.y]) {
                self.grid[pos.x][pos.y][pos.z] = null;
              }
            }
          });
          
          self.isExploding = false;
          
          // Continue with drop/match logic
          if (!skipDrop) {
            self.dropBlocks(function() {
              self.processMatchesWithoutDrop(function() {
                self.logDebug('>>> STEP 4b: Final Settle Before Regeneration');
                self.dropBlocks(function() {
                  self.regenerateBlocks(eliminatedCount);
                });
              });
            });
          } else {
            setTimeout(function() {
              self.processMatchesWithoutDrop(callback);
            }, 300);
          }
          }, 5); // 5ms delay after animation
        }
      };
      
      // Start animation
      var baseDelay = Math.max(100 / Math.pow(2, this.comboMatchCount > 0 ? Math.floor(this.comboMatchCount / 3) : 0), 10);
      setTimeout(animateStep, baseDelay + Math.floor((Math.random() - 0.5) * 300)); // ± 150ms
    },

    dropBlocks: function(callback) {
      var self = this;
      self.logDebug('>>> STEP 4: Drop & Settle Starting');
      this.movingBlocks = {};
      
      // No render needed - grid hasn't changed yet, settlement will animate moves
      self.settleAllBlocks(callback);
    },

    settleAllBlocks: function(callback) {
      var self = this;
      self.logDebug('  >> Settle: Beginning settlement');
      var centerPos = Math.floor(this.gridSize / 2);
      
      // Lock interaction during settlement
      this.isSettling = true;
      self.logDebug('  >> Settle: isSettling set to TRUE');
      
      function settleStep() {
        self.logDebug('  >> Settle: settleStep() iteration starting');
        // Use pre-calculated, pre-sorted distance cache (no calculations or sorting needed!)
        // This eliminates ~58,000-116,000 operations per turn
        var moveMap = {}; // Track old position -> new position
        var movedThisIteration = {}; // Track blocks that already moved this iteration
        var blocksChecked = 0;
        var blocksMoved = 0;
        
        // Process each position from pre-sorted cache (furthest to closest)
        // NOTE: blocksByDistance is already sorted by distance (furthest first)
        for (var i = 0; i < self.blocksByDistance.length; i++) {
          var pos = self.blocksByDistance[i];
          var x = pos.x;
          var y = pos.y;
          var z = pos.z;
          var posKey = x + '_' + y + '_' + z;
          
          // Skip if no block at this position
          if (self.grid[x][y][z] === -1) continue;
          
          // Skip if this position is a NEW destination from a move this iteration
          // (prevents moving the same block multiple times in one iteration)
          if (movedThisIteration[posKey]) continue;
          
          blocksChecked++;
          
          // Log blocks we're checking
          if (blocksChecked <= 5 || self.grid[x][y][z] >= 100) {
            self.logDebug('  >> Settle: Checking block at (' + x + ',' + y + ',' + z + ') type=' + self.grid[x][y][z]);
          }
          
          // Skip center block - it never moves
          if (x === centerPos && y === centerPos && z === centerPos) continue;
          
          // Use pre-calculated distances from cache (no sqrt or abs calculations!)
          var xDist = pos.xDist;
          var yDist = pos.yDist;
          var zDist = pos.zDist;
          
          var xDir = x < centerPos ? 1 : (x > centerPos ? -1 : 0);
          var yDir = y < centerPos ? 1 : (y > centerPos ? -1 : 0);
          var zDir = z < centerPos ? 1 : (z > centerPos ? -1 : 0);
          
          // Try to move one space on the axis with highest distance
          var blockMoved = false;
          var oldKey = x + '_' + y + '_' + z;
          
          if (xDist >= yDist && xDist >= zDist && xDir !== 0 && !blockMoved) {
            var newX = x + xDir;
            self.logDebug('    >> Try X: (' + x + ',' + y + ',' + z + ') -> (' + newX + ',' + y + ',' + z + ') - distances[x=' + xDist + ',y=' + yDist + ',z=' + zDist + '] target=' + (self.grid[newX] && self.grid[newX][y] ? self.grid[newX][y][z] : 'OOB'));
            if (newX >= 0 && newX < self.gridSize && self.grid[newX][y][z] === -1 && !(newX === centerPos && y === centerPos && z === centerPos)) {
              self.logDebug('  >> Settle: Moving block from (' + x + ',' + y + ',' + z + ') to (' + newX + ',' + y + ',' + z + ')');
              var newKey = newX + '_' + y + '_' + z;
              moveMap[oldKey] = { newX: newX, newY: y, newZ: z, oldX: x, oldY: y, oldZ: z };
              movedThisIteration[newKey] = true;  // Mark destination as occupied by a moved block
              self.grid[newX][y][z] = self.grid[x][y][z];
              self.grid[x][y][z] = -1;
              blockMoved = true;
              blocksMoved++;
            }
          }
          
          if (yDist >= xDist && yDist >= zDist && yDir !== 0 && !blockMoved) {
            var newY = y + yDir;
            self.logDebug('    >> Try Y: (' + x + ',' + y + ',' + z + ') -> (' + x + ',' + newY + ',' + z + ') - distances[x=' + xDist + ',y=' + yDist + ',z=' + zDist + '] target=' + (self.grid[x] && self.grid[x][newY] ? self.grid[x][newY][z] : 'OOB'));
            if (newY >= 0 && newY < self.gridSize && self.grid[x][newY][z] === -1 && !(x === centerPos && newY === centerPos && z === centerPos)) {
              self.logDebug('  >> Settle: Moving block from (' + x + ',' + y + ',' + z + ') to (' + x + ',' + newY + ',' + z + ')');
              var newKey = x + '_' + newY + '_' + z;
              moveMap[oldKey] = { newX: x, newY: newY, newZ: z, oldX: x, oldY: y, oldZ: z };
              movedThisIteration[newKey] = true;  // Mark destination as occupied by a moved block
              self.grid[x][newY][z] = self.grid[x][y][z];
              self.grid[x][y][z] = -1;
              blockMoved = true;
              blocksMoved++;
            }
          }
          
          if (zDist >= xDist && zDist >= yDist && zDir !== 0 && !blockMoved) {
            var newZ = z + zDir;
            self.logDebug('    >> Try Z: (' + x + ',' + y + ',' + z + ') -> (' + x + ',' + y + ',' + newZ + ') - distances[x=' + xDist + ',y=' + yDist + ',z=' + zDist + '] target=' + (self.grid[x] && self.grid[x][y] ? self.grid[x][y][newZ] : 'OOB'));
            if (newZ >= 0 && newZ < self.gridSize && self.grid[x][y][newZ] === -1 && !(x === centerPos && y === centerPos && newZ === centerPos)) {
              self.logDebug('  >> Settle: Moving block from (' + x + ',' + y + ',' + z + ') to (' + x + ',' + y + ',' + newZ + ')');
              var newKey = x + '_' + y + '_' + newZ;
              moveMap[oldKey] = { newX: x, newY: y, newZ: newZ, oldX: x, oldY: y, oldZ: z };
              movedThisIteration[newKey] = true;  // Mark destination as occupied by a moved block
              self.grid[x][y][newZ] = self.grid[x][y][z];
              self.grid[x][y][z] = -1;
              blockMoved = true;
              blocksMoved++;
            }
          }
        }
        
        self.logDebug('  >> Settle: Checked ' + blocksChecked + ' blocks, moved ' + blocksMoved + ' blocks');
        
        var moveCount = Object.keys(moveMap).length;
        self.logDebug('  >> Settle: moveMap contains ' + moveCount + ' moves');
        
        if (moveCount > 0) {
          // Animate blocks to new positions smoothly
          self.logDebug('  >> Settle: Calling animateBlockMovement() with ' + moveCount + ' blocks');
          self.animateBlockMovement(moveMap, function() {
            self.logDebug('  >> Settle: Animation callback - iteration complete, blocks moved');
            setTimeout(settleStep, 25 + Math.floor((Math.random() - 0.5) * 300)); // 25ms ± 150ms
          });
        } else {
          self.logDebug('  >> Settle: No blocks to move, settlement complete');
          // All blocks fully settled - always check for new matches
          // No render3D() needed - meshes already in correct positions from animation
          self.logDebug('  >> Settle: Complete - all blocks stable');
          self.logDebug('  >> Settle: Checking for new matches after settlement');
          
          // Always check for matches after settling
          var hadMatches = false;
          for (var x = 0; x < self.gridSize; x++) {
            for (var y = 0; y < self.gridSize; y++) {
              for (var z = 0; z < self.gridSize; z++) {
                var matches = self.checkMatchAt(x, y, z);
                if (matches.length >= self.minMatch) {
                  hadMatches = true;
                  break;
                }
              }
              if (hadMatches) break;
            }
            if (hadMatches) break;
          }
          
          if (hadMatches) {
            // Found matches after settling, process them then settle again
            self.logDebug('  >> Settle: Matches found after settlement, processing...');
            self.processMatchesWithoutDrop(function() {
              // After chain reactions, settle again to fill gaps
              self.logDebug('  >> Settle: Re-settling after matches...');
              self.settleAllBlocks(callback);
            });
          } else {
            // No matches, proceed with callback (don't unlock yet - let callback chain finish)
            self.logDebug('  >> Settle: Complete, calling callback');
            // Check if player has won (only 1 block remaining)
            self.checkWinCondition();
            if (callback) callback();
          }
        }
      }
      
      settleStep();
    },

    animateBlockMovement: function(moveMap, callback) {
      var self = this;
      self.logDebug('  >> ANIMATE: Starting animation for ' + Object.keys(moveMap).length + ' blocks');
      var startTime = performance.now();
      // Speed up by 2x for each 3 combos (200ms base → 100ms → 50ms → 25ms at 9 combos = 8x faster)
      var duration = 200 / Math.pow(2, this.comboMatchCount > 0 ? Math.floor(this.comboMatchCount / 3) : 0);
      duration = Math.max(duration, 25); // Cap at minimum 25ms for smoothness
      var offset = this.gridSize / 2;
      
      function animate(currentTime) {
        var elapsed = currentTime - startTime;
        var progress = Math.min(elapsed / duration, 1);
        self.logDebug('  >> ANIMATE: Frame - elapsed=' + elapsed.toFixed(0) + 'ms, progress=' + (progress * 100).toFixed(1) + '%');
        
        // Ease-out cubic for smooth deceleration
        var eased = 1 - Math.pow(1 - progress, 3);
        
        // Update each moving mesh position with interpolation
        var meshesUpdated = 0;
        var meshesMissing = 0;
        Object.keys(moveMap).forEach(function(oldKey) {
          var move = moveMap[oldKey];
          var mesh = self.blockMeshes[oldKey];
          
          if (mesh) {
            var oldX = move.oldX - offset;
            var oldY = move.oldY - offset;
            var oldZ = move.oldZ - offset;
            
            var newX = move.newX - offset;
            var newY = move.newY - offset;
            var newZ = move.newZ - offset;
            
            var oldPos = mesh.position.x + ',' + mesh.position.y + ',' + mesh.position.z;
            mesh.position.x = oldX + (newX - oldX) * eased;
            mesh.position.y = oldY + (newY - oldY) * eased;
            mesh.position.z = oldZ + (newZ - oldZ) * eased;
            var newPos = mesh.position.x.toFixed(2) + ',' + mesh.position.y.toFixed(2) + ',' + mesh.position.z.toFixed(2);
            
            if (progress === 0 || progress === 1) {
              self.logDebug('    >> MESH UPDATE: ' + oldKey + ' mesh position ' + oldPos + ' -> ' + newPos + ' (eased=' + eased.toFixed(2) + ')');
            }
            meshesUpdated++;
          } else {
            meshesMissing++;
            if (progress === 0) {
              self.logDebug('    >> MESH MISSING: No mesh found for key ' + oldKey + ' (grid has type ' + self.grid[move.oldX][move.oldY][move.oldZ] + ')');
            }
          }
        });
        
        if (progress === 0 || progress === 1) {
          self.logDebug('    >> RENDER: Updated ' + meshesUpdated + ' meshes, missing ' + meshesMissing + ', rendering scene');
        }
        self.renderer.render(self.scene, self.camera);
        
        if (progress < 1) {
          requestAnimationFrame(animate);
        } else {
          self.logDebug('  >> ANIMATE: Animation complete for ' + Object.keys(moveMap).length + ' blocks');
          // Animation complete - play click sound based on number of blocks moved
          var blockCount = Object.keys(moveMap).length;
          self.playClickSound(blockCount);
          
          // Update mesh keys to match new grid positions (no need to rebuild everything)
          Object.keys(moveMap).forEach(function(oldKey) {
            var move = moveMap[oldKey];
            var mesh = self.blockMeshes[oldKey];
            if (mesh) {
              var newKey = move.newX + '_' + move.newY + '_' + move.newZ;
              mesh.userData = { x: move.newX, y: move.newY, z: move.newZ };
              self.blockMeshes[newKey] = mesh;
              delete self.blockMeshes[oldKey];
            }
          });
          
          // Callback to continue settlement
          self.logDebug('  >> ANIMATE: Calling callback to continue settlement');
          setTimeout(function() {
            if (callback) callback();
          }, 5); // 5ms delay after animation
        }
      }
      
      self.logDebug('  >> ANIMATE: Starting requestAnimationFrame loop');
      requestAnimationFrame(animate);
    },

    settleAllBlocksWithCount: function(callback) {
      var self = this;
      self.logDebug('  >> Settle: Beginning settlement with move tracking');
      var centerPos = Math.floor(this.gridSize / 2);
      var totalMoves = 0;
      
      function settleStep() {
        // Collect all blocks with their positions and distances
        var blocks = [];
        for (var x = 0; x < self.gridSize; x++) {
          for (var y = 0; y < self.gridSize; y++) {
            for (var z = 0; z < self.gridSize; z++) {
              if (self.grid[x][y][z] !== -1) {
                var xDist = Math.abs(x - centerPos);
                var yDist = Math.abs(y - centerPos);
                var zDist = Math.abs(z - centerPos);
                var totalDist = Math.sqrt(xDist*xDist + yDist*yDist + zDist*zDist);
                blocks.push({ x: x, y: y, z: z, dist: totalDist });
              }
            }
          }
        }
        
        // Sort by distance: closest to center first
        blocks.sort(function(a, b) {
          return a.dist - b.dist;
        });
        
        var moved = false;
        var movesThisIteration = 0;
        
        // Process each block in order, moving one space if possible
        for (var i = 0; i < blocks.length; i++) {
          var block = blocks[i];
          var x = block.x;
          var y = block.y;
          var z = block.z;
          
          // Skip if block was already moved by another block
          if (self.grid[x][y][z] === -1) continue;
          
          // Calculate distances and directions
          var xDist = Math.abs(x - centerPos);
          var yDist = Math.abs(y - centerPos);
          var zDist = Math.abs(z - centerPos);
          
          var xDir = x < centerPos ? 1 : (x > centerPos ? -1 : 0);
          var yDir = y < centerPos ? 1 : (y > centerPos ? -1 : 0);
          var zDir = z < centerPos ? 1 : (z > centerPos ? -1 : 0);
          
          // Try to move one space on the axis with highest distance
          var blockMoved = false;
          
          if (xDist >= yDist && xDist >= zDist && xDir !== 0 && !blockMoved) {
            var newX = x + xDir;
            if (newX >= 0 && newX < self.gridSize && self.grid[newX][y][z] === -1) {
              self.grid[newX][y][z] = self.grid[x][y][z];
              self.grid[x][y][z] = -1;
              blockMoved = true;
              moved = true;
              movesThisIteration++;
            }
          }
          
          if (yDist >= xDist && yDist >= zDist && yDir !== 0 && !blockMoved) {
            var newY = y + yDir;
            if (newY >= 0 && newY < self.gridSize && self.grid[x][newY][z] === -1) {
              self.grid[x][newY][z] = self.grid[x][y][z];
              self.grid[x][y][z] = -1;
              blockMoved = true;
              moved = true;
              movesThisIteration++;
            }
          }
          
          if (zDist >= xDist && zDist >= yDist && zDir !== 0 && !blockMoved) {
            var newZ = z + zDir;
            if (newZ >= 0 && newZ < self.gridSize && self.grid[x][y][newZ] === -1) {
              self.grid[x][y][newZ] = self.grid[x][y][z];
              self.grid[x][y][z] = -1;
              blockMoved = true;
              moved = true;
              movesThisIteration++;
            }
          }
        }
        
        totalMoves += movesThisIteration;
        
        if (moved) {
          self.render3D();
          self.logDebug('  >> Settle: Iteration complete - ' + movesThisIteration + ' blocks moved (total: ' + totalMoves + ')');
          setTimeout(settleStep, 1500 + Math.floor((Math.random() - 0.5) * 300)); // 1500ms ± 150ms (6x original)
        } else {
          // All blocks fully settled
          self.render3D();
          self.logDebug('  >> Settle: Complete - all blocks stable');
          if (callback) callback(totalMoves);
        }
      }
      
      settleStep();
    },

    regenerateBlocks: function(eliminatedCount) {
      var self = this;
      // Number of blocks to regenerate equals current level (1-9)
      // Only regenerate if blocks were actually eliminated
      var newBlockCount = eliminatedCount > 0 ? this.level : 0;
      
      self.logDebug('>>> STEP 6: Regenerate Blocks - adding ' + newBlockCount + ' blocks (level ' + this.level + ') (eliminated: ' + eliminatedCount + ')');
      
      // If no blocks to add, complete turn immediately
      if (newBlockCount === 0) {
        self.logDebug('>>> STEP 6: No blocks to regenerate, completing turn');
        self.completeTurn();
        return;
      }
      
      // Calculate playable area boundaries
      var centerPos = Math.floor(this.gridSize / 2);
      var halfSize = Math.floor(this.playableSize / 2);
      var minIndex = centerPos - halfSize;
      var maxIndex = centerPos + halfSize;
      
      // Spawn blocks at the TRUE GRID EDGES (far from center) so they have a long journey inward
      var spawnMin = 0;  // Far edge of grid
      var spawnMax = this.gridSize - 1;  // Far edge of grid
      
      self.logDebug('>>> REGEN: Playable area is from ' + minIndex + ' to ' + maxIndex + ' (center=' + centerPos + ', playableSize=' + this.playableSize + ')');
      self.logDebug('>>> REGEN: Spawn positions will be at grid edges: ' + spawnMin + ' and ' + spawnMax);
      self.logDebug('>>> REGEN: Using FULL grid range for spawn coordinates: 0 to ' + (this.gridSize - 1));
      
      var faces = [
        { name: 'top', axis: 'y', value: spawnMax, range: [0, this.gridSize - 1] },
        { name: 'bottom', axis: 'y', value: spawnMin, range: [0, this.gridSize - 1] },
        { name: 'left', axis: 'x', value: spawnMin, range: [0, this.gridSize - 1] },
        { name: 'right', axis: 'x', value: spawnMax, range: [0, this.gridSize - 1] },
        { name: 'front', axis: 'z', value: spawnMax, range: [0, this.gridSize - 1] },
        { name: 'back', axis: 'z', value: spawnMin, range: [0, this.gridSize - 1] }
      ];
      
      // Place blocks directly at grid edges
      var blocksPlaced = [];
      for (var i = 0; i < newBlockCount; i++) {
        var face = faces[Math.floor(Math.random() * faces.length)];
        var x, y, z;
        
        // Place block at edge position
        if (face.axis === 'x') {
          x = face.value;  // Edge
          y = face.range[0] + Math.floor(Math.random() * (face.range[1] - face.range[0] + 1));
          z = face.range[0] + Math.floor(Math.random() * (face.range[1] - face.range[0] + 1));
        } else if (face.axis === 'y') {
          x = face.range[0] + Math.floor(Math.random() * (face.range[1] - face.range[0] + 1));
          y = face.value;  // Edge
          z = face.range[0] + Math.floor(Math.random() * (face.range[1] - face.range[0] + 1));
        } else {
          x = face.range[0] + Math.floor(Math.random() * (face.range[1] - face.range[0] + 1));
          y = face.range[0] + Math.floor(Math.random() * (face.range[1] - face.range[0] + 1));
          z = face.value;  // Edge
        }
        
        self.logInfo('>>> REGEN: Attempting to spawn block ' + (i+1) + '/' + newBlockCount + ' at (' + x + ',' + y + ',' + z + ')');
        self.logInfo('>>> REGEN: Position status: ' + (this.grid[x][y][z] === -1 ? 'EMPTY' : 'OCCUPIED (value: ' + this.grid[x][y][z] + ')'));
        
        // If spawn position is occupied - GAME OVER (no retries)
        if (this.grid[x][y][z] !== -1) {
          self.logError('>>> REGEN: SPAWN BLOCKED! Position (' + x + ',' + y + ',' + z + ') is occupied with block type ' + this.grid[x][y][z]);
          self.logError('>>> REGEN: Triggering GAME OVER');
          this.gameEnded = true;
          self.gameOver(false, 'Spawn area blocked!');
          return;
        }
        
        // Place block in empty position
        var blockType = this.randomBlockType();
        this.grid[x][y][z] = blockType;
        blocksPlaced.push({x: x, y: y, z: z, type: blockType});
        self.logInfo('>>> REGEN: Successfully placed block type ' + blockType + ' at (' + x + ',' + y + ',' + z + ')');
      }
      self.logDebug('>>> REGEN: Placed blocks at positions:', blocksPlaced);
      
      // Log each placed block for debugging
      blocksPlaced.forEach(function(block) {
        self.logDebug('>>> REGEN: Block placed at (' + block.x + ',' + block.y + ',' + block.z + ') type=' + block.type);
      });
      
      // Create meshes ONLY for new blocks (don't rebuild existing ones)
      self.logDebug('>>> REGEN: Creating meshes for ' + blocksPlaced.length + ' new blocks');
      blocksPlaced.forEach(function(block) {
        var key = block.x + '_' + block.y + '_' + block.z;
        var blockSize = 1;  // Must match render3D() scale: 1 world unit = 1 grid unit
        var center = self.gridSize / 2;
        var worldX = (block.x - center) * blockSize;
        var worldY = (block.y - center) * blockSize;
        var worldZ = (block.z - center) * blockSize;
        
        // Create mesh for this new block (0.95 to match render3D spacing)
        var geometry = new THREE.BoxGeometry(blockSize * 0.95, blockSize * 0.95, blockSize * 0.95);
        var color = self.getBlockColor(block.type);
        var material = new THREE.MeshPhongMaterial({ color: color });
        var mesh = new THREE.Mesh(geometry, material);
        mesh.position.set(worldX, worldY, worldZ);
        mesh.userData = { x: block.x, y: block.y, z: block.z };
        
        self.scene.add(mesh);
        self.blockMeshes[key] = mesh;
        
        // Add emoji texture to all faces for special blocks
        if (self.isSpecialBlock(block.type)) {
          var special = self.getSpecialBlockData(block.type);
          if (special) {
            self.logDebug('>>> REGEN: Adding texture for special block:', block.type, special.name, special.emoji);
            var emojiTexture = self.createEmojiTexture(special.emoji, color);
            
            // Create materials array - 6 faces with emoji texture on all
            var materials = [];
            for (var i = 0; i < 6; i++) {
              materials.push(new THREE.MeshPhongMaterial({
                color: color,
                map: emojiTexture
              }));
            }
            mesh.material = materials;
          }
        }
        
        self.logDebug('>>> REGEN: Created mesh at key=' + key + ' worldPos=(' + worldX + ',' + worldY + ',' + worldZ + ')');
      });
      self.logDebug('>>> REGEN: Mesh creation complete, calling dropBlocks() immediately');
      self.dropBlocks(function() {
        // Check for matches after settling
        self.processMatchesWithoutDrop(function() {
          // If there were chain reactions, settle again
          self.logDebug('>>> STEP 4c: Final Settle After Regeneration Chains');
          self.dropBlocks(function() {
            self.completeTurn();
          });
        });
      });
    },

    getRandomSpawnPosition: function(face) {
      var maxIndex = this.gridSize - 1;
      var attempts = 0;
      var maxAttempts = 50;
      
      while (attempts < maxAttempts) {
        var x, y, z, spawnX, spawnY, spawnZ;
        
        if (face.axis === 'x') {
          x = face.value;
          y = Math.floor(Math.random() * this.gridSize);
          z = Math.floor(Math.random() * this.gridSize);
          spawnX = face.value + (face.dir * 9);
          spawnY = y;
          spawnZ = z;
        } else if (face.axis === 'y') {
          x = Math.floor(Math.random() * this.gridSize);
          y = face.value;
          z = Math.floor(Math.random() * this.gridSize);
          spawnX = x;
          spawnY = face.value + (face.dir * 9);
          spawnZ = z;
        } else { // z axis
          x = Math.floor(Math.random() * this.gridSize);
          y = Math.floor(Math.random() * this.gridSize);
          z = face.value;
          spawnX = x;
          spawnY = y;
          spawnZ = face.value + (face.dir * 9);
        }
        
        if (this.grid[x][y][z] === -1) {
          return {
            spawn: { x: spawnX, y: spawnY, z: spawnZ },
            target: { x: x, y: y, z: z }
          };
        }
        
        attempts++;
      }
      
      return null;
    },

    animateNewBlocks: function(newBlocks) {
      var self = this;
      
      // Create temporary meshes for incoming blocks
      var geometry = new THREE.BoxGeometry(0.95, 0.95, 0.95);
      var tempMeshes = [];
      
      newBlocks.forEach(function(block) {
        var material = new THREE.MeshPhongMaterial({
          color: self.getBlockColor(block.color),
          shininess: 30,
          transparent: true,
          opacity: 0.7
        });
        
        var mesh = new THREE.Mesh(geometry, material);
        var offset = self.gridSize / 2;
        mesh.position.set(
          block.current.x - offset,
          block.current.y - offset,
          block.current.z - offset
        );
        
        // Store spawn position for interpolation
        block.spawn = { x: block.current.x, y: block.current.y, z: block.current.z };
        
        self.scene.add(mesh);
        tempMeshes.push({ mesh: mesh, block: block, added: false });
      });
      
      // Animate blocks moving inward smoothly
      var startTime = Date.now();
      var animationDuration = 2000; // 2 seconds for smooth travel
      
      function animateStep() {
        var elapsed = Date.now() - startTime;
        var progress = Math.min(elapsed / animationDuration, 1);
        var stillMoving = progress < 1;
        
        var offset = self.gridSize / 2;
        
        tempMeshes.forEach(function(item) {
          if (progress < 1) {
            // Smooth interpolation from spawn to target
            var startX = item.block.spawn.x;
            var startY = item.block.spawn.y;
            var startZ = item.block.spawn.z;
            var targetX = item.block.target.x;
            var targetY = item.block.target.y;
            var targetZ = item.block.target.z;
            
            // Ease-in-out interpolation
            var easeProgress = progress < 0.5 
              ? 2 * progress * progress 
              : 1 - Math.pow(-2 * progress + 2, 2) / 2;
            
            var currentX = startX + (targetX - startX) * easeProgress;
            var currentY = startY + (targetY - startY) * easeProgress;
            var currentZ = startZ + (targetZ - startZ) * easeProgress;
            
            item.mesh.position.set(
              currentX - offset,
              currentY - offset,
              currentZ - offset
            );
          } else if (!item.added) {
            // Animation complete, add to grid
            item.added = true;
            self.grid[item.block.target.x][item.block.target.y][item.block.target.z] = item.block.color;
            self.scene.remove(item.mesh);
          }
        });
        
        self.renderer.render(self.scene, self.camera);
        
        if (stillMoving) {
          requestAnimationFrame(animateStep);
        } else {
          // All blocks arrived, now drop them toward center
          setTimeout(function() {
            self.dropBlocks(function() {
              // Check for matches after new blocks settle
              self.processMatchesWithoutDrop(function() {
                self.completeTurn();
              });
            });
          }, 500);
        }
      }
      
      animateStep();
    },

    checkWinCondition: function() {
      var blockCount = 0;
      
      for (var x = 0; x < this.gridSize; x++) {
        for (var y = 0; y < this.gridSize; y++) {
          for (var z = 0; z < this.gridSize; z++) {
            if (this.grid[x][y][z] !== -1) {
              blockCount++;
            }
          }
        }
      }
      
      if (blockCount === 1) {
        this.gameOver(true, 'You Win! Only one block remains in 3D space!');
      }
    },

    gameOver: function(won, message) {
      clearInterval(this.timerInterval);
      var self = this;
      
      // Play victory sound if won
      if (won) {
        this.playVictorySound();
      }
      
      $('#final-score').text(this.score);
      $('#final-moves').text(this.moves);
      $('#final-time').text($('#timer').text());
      
      $('#game-over-modal h2').text(won ? 'Congratulations!' : 'Game Over');
      
      if (message) {
        var $message = $('<p>').addClass('game-message').text(message);
        $('#game-over-modal .modal-content p:first').remove();
        $('#game-over-modal .modal-content').prepend($message);
      }
      
      // Check if score qualifies for high score table
      var timeInSeconds = Math.floor((Date.now() - this.startTime) / 1000);
      
      // Only check high score if user is authenticated
      if (this.isAuthenticated) {
        this.checkHighScore(this.score, this.level, timeInSeconds, function(qualifies) {
          if (qualifies) {
            // Show high score modal with user's name pre-filled
            self.showHighScoreModal(self.score, self.level, timeInSeconds);
          } else {
            // Show regular game over modal
            $('#game-over-modal').show();
          }
        });
      } else {
        // Show game over modal with login prompt if score is good
        $('#game-over-modal').show();
        
        // Add login prompt for good scores
        if (this.score > 100) {
          var $loginPrompt = $('<div>').addClass('login-prompt').html(
            '<p style="margin-top: 15px; padding: 10px; background: #fff3cd; border-radius: 5px;">'
            + '<strong>🏆 Great score!</strong><br>'
            + '<a href="/user/login?destination=/games/block-matcher">Log in</a> or '
            + '<a href="/user/register?destination=/games/block-matcher">create an account</a> to save your high scores!'
            + '</p>'
          );
          $('#game-over-modal .modal-content').append($loginPrompt);
        }
      }
    },

    checkHighScore: function(score, level, time, callback) {
      $.ajax({
        url: '/api/games/check-score',
        method: 'POST',
        contentType: 'application/json',
        headers: {
          'X-CSRF-Token': this.csrfToken || ''
        },
        data: JSON.stringify({
          game_id: 'block-matcher',
          score: score
        }),
        success: function(response) {
          callback(response.qualifies);
        },
        error: function() {
          callback(false);
        }
      });
    },

    showHighScoreModal: function(score, level, time) {
      var self = this;
      var minutes = Math.floor(time / 60);
      var seconds = time % 60;
      
      $('#hs-score').text(score);
      $('#hs-level').text(level);
      $('#hs-time').text(minutes + ':' + (seconds < 10 ? '0' : '') + seconds);
      
      // Pre-fill with authenticated user's name
      $('#player-name-input').val(this.userName || '');
      
      $('#high-score-modal').show();
      $('#player-name-input').focus();
      
      // Handle submit
      $('#submit-score-btn').off('click').on('click', function() {
        var playerName = $('#player-name-input').val().trim() || 'Anonymous';
        self.submitHighScore(score, level, time, playerName);
      });
      
      // Handle skip
      $('#skip-score-btn').off('click').on('click', function() {
        $('#high-score-modal').hide();
        $('#game-over-modal').show();
      });
      
      // Allow Enter key to submit
      $('#player-name-input').off('keypress').on('keypress', function(e) {
        if (e.which === 13) {
          $('#submit-score-btn').click();
        }
      });
    },

    submitHighScore: function(score, level, time, playerName) {
      var self = this;
      
      $.ajax({
        url: '/api/games/submit-score',
        method: 'POST',
        contentType: 'application/json',
        headers: {
          'X-CSRF-Token': this.csrfToken || ''
        },
        data: JSON.stringify({
          game_id: 'block-matcher',
          score: score,
          level: level,
          time: time,
          player_name: playerName
        }),
        success: function(response) {
          if (response.success) {
            $('#high-score-modal').hide();
            $('#game-over-modal').show();
            // Refresh high scores display
            self.loadHighScores();
          }
        },
        error: function() {
          alert('Failed to submit score. Please try again.');
        }
      });
    },

    loadHighScores: function() {
      $.ajax({
        url: '/api/games/high-scores/block-matcher',
        method: 'GET',
        success: function(response) {
          var html = '<ol class="high-scores">';
          response.scores.forEach(function(score, index) {
            var minutes = Math.floor(score.time / 60);
            var seconds = score.time % 60;
            var timeStr = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
            
            html += '<li class="high-score-item">';
            html += '<span class="rank">' + (index + 1) + '.</span>';
            html += '<span class="player">' + score.player_name + '</span>';
            html += '<span class="score">' + score.score + '</span>';
            html += '<span class="details">L' + score.level + ' • ' + timeStr + '</span>';
            html += '</li>';
          });
          html += '</ol>';
          
          $('#high-scores-list').html(html);
        }
      });
    },

    startTimer: function() {
      this.startTime = Date.now();
      var self = this;
      this.timerInterval = setInterval(function() {
        var elapsed = Math.floor((Date.now() - self.startTime) / 1000);
        var minutes = Math.floor(elapsed / 60);
        var seconds = elapsed % 60;
        $('#timer').text(minutes + ':' + (seconds < 10 ? '0' : '') + seconds);
      }, 1000);
    },

    advanceLevel: function() {
      var self = this;
      
      // Reset explosion flag
      this.isExploding = false;
      
      if (this.level >= this.maxLevel) {
        // Beat final level!
        setTimeout(function() {
          self.gameOver(true, 'ULTIMATE VICTORY! You completed all ' + self.maxLevel + ' levels!');
        }, 2000);
        return;
      }
      
      // Advance to next level
      this.level++;
      this.updateLevel();
      
      // Brief pause then restart with new level
      setTimeout(function() {
        self.createGrid();
        self.render3D();
        self.moves = 0;
        $('#moves').text(self.moves);
        self.isSettling = false; // Unlock interactions for new level
      }, 2000);
    },

    newGame: function() {
      this.level = 1;
      this.updateLevel();
      this.score = 0;
      this.moves = 0;
      this.selectedBlock = null;
      $('#score').text(0);
      $('#moves').text(0);
      clearInterval(this.timerInterval);
      this.createGrid();
      this.render3D();
      this.startTimer();
    }
  };

})(jQuery, Drupal, once);
