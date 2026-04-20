// Special block effects - to be inserted into block-matcher-3d.js

handleSpecialBlock: function(blockType, x, y, z) {
  var self = this;
  
  // Remove the special block first
  this.grid[x][y][z] = -1;
  this.render3D();
  
  var special = this.getSpecialBlockData(blockType);
  if (special) {
    console.log('Activated: ' + special.name + ' ' + special.emoji);
  }
  
  switch(blockType) {
    case 100: this.effectBomb(x, y, z); break;
    case 101: this.effectLightning(x, y, z); break;
    case 102: this.effectVortex(x, y, z); break;
    case 103: this.effectChainReaction(x, y, z); break;
    case 104: this.effectRainbow(x, y, z); break;
    case 105: this.effectShuffler(x, y, z); break;
    case 106: this.effectLaser(x, y, z); break;
    case 107: this.effectFreeze(x, y, z); break;
    case 108: this.effectMultiplier(x, y, z); break;
    case 109: this.effectJackpot(x, y, z); break;
    case 110: this.effectComboExtender(x, y, z); break;
    case 111: this.effectBlackHole(x, y, z); break;
    case 112: this.effectTeleporter(x, y, z); break;
    case 113: this.effectColorChanger(x, y, z); break;
    case 114: this.effectShield(x, y, z); break;
  }
},

// 100: Bomb - 3x3x3 explosion
effectBomb: function(x, y, z) {
  var self = this;
  var toRemove = [];
  
  for (var dx = -1; dx <= 1; dx++) {
    for (var dy = -1; dy <= 1; dy++) {
      for (var dz = -1; dz <= 1; dz++) {
        var nx = x + dx, ny = y + dy, nz = z + dz;
        if (nx >= 0 && nx < this.gridSize && ny >= 0 && ny < this.gridSize && nz >= 0 && nz < this.gridSize) {
          if (this.grid[nx][ny][nz] >= 0) {
            toRemove.push({x: nx, y: ny, z: nz});
          }
        }
      }
    }
  }
  
  if (toRemove.length > 0) {
    this.removeMatches(toRemove, false);
  }
},

// 101: Lightning - Destroy all blocks of same color
effectLightning: function(x, y, z) {
  var self = this;
  var toRemove = [];
  
  // Pick a random regular color
  var targetColor = Math.floor(Math.random() * this.blockTypes);
  
  for (var ix = 0; ix < this.gridSize; ix++) {
    for (var iy = 0; iy < this.gridSize; iy++) {
      for (var iz = 0; iz < this.gridSize; iz++) {
        if (this.grid[ix][iy][iz] === targetColor) {
          toRemove.push({x: ix, y: iy, z: iz});
        }
      }
    }
  }
  
  if (toRemove.length > 0) {
    this.removeMatches(toRemove, false);
  }
},

// 102: Vortex - Pull in and destroy blocks within radius
effectVortex: function(x, y, z) {
  var self = this;
  var toRemove = [];
  var radius = 4;
  
  for (var ix = 0; ix < this.gridSize; ix++) {
    for (var iy = 0; iy < this.gridSize; iy++) {
      for (var iz = 0; iz < this.gridSize; iz++) {
        var dist = Math.sqrt(Math.pow(ix-x,2) + Math.pow(iy-y,2) + Math.pow(iz-z,2));
        if (dist <= radius && this.grid[ix][iy][iz] >= 0) {
          toRemove.push({x: ix, y: iy, z: iz});
        }
      }
    }
  }
  
  if (toRemove.length > 0) {
    this.removeMatches(toRemove, false);
  }
},

// 103: Chain Reaction - Each destroyed block explodes its neighbors
effectChainReaction: function(x, y, z) {
  var self = this;
  var toRemove = [{x: x, y: y, z: z}];
  var processed = {};
  
  function addNeighbors(cx, cy, cz) {
    var key = cx + ',' + cy + ',' + cz;
    if (processed[key]) return;
    processed[key] = true;
    
    var dirs = [[1,0,0],[-1,0,0],[0,1,0],[0,-1,0],[0,0,1],[0,0,-1]];
    dirs.forEach(function(dir) {
      var nx = cx + dir[0], ny = cy + dir[1], nz = cz + dir[2];
      if (nx >= 0 && nx < self.gridSize && ny >= 0 && ny < self.gridSize && nz >= 0 && nz < self.gridSize) {
        if (self.grid[nx][ny][nz] >= 0 && !processed[nx+','+ny+','+nz]) {
          toRemove.push({x: nx, y: ny, z: nz});
          addNeighbors(nx, ny, nz);
        }
      }
    });
  }
  
  addNeighbors(x, y, z);
  
  if (toRemove.length > 0) {
    this.removeMatches(toRemove, false);
  }
},

// 104: Rainbow - Acts as wildcard (already removed, just settle)
effectRainbow: function(x, y, z) {
  // Rainbow acts as a wildcard - removing it causes settlement
  this.dropBlocks();
},

// 105: Shuffler - Randomly reposition 10 blocks
effectShuffler: function(x, y, z) {
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
},

// 106: Laser - Destroy entire line
effectLaser: function(x, y, z) {
  var self = this;
  var toRemove = [];
  
  // Pick random axis
  var axis = Math.floor(Math.random() * 3);
  
  if (axis === 0) { // X axis
    for (var ix = 0; ix < this.gridSize; ix++) {
      if (this.grid[ix][y][z] >= 0) toRemove.push({x: ix, y: y, z: z});
    }
  } else if (axis === 1) { // Y axis
    for (var iy = 0; iy < this.gridSize; iy++) {
      if (this.grid[x][iy][z] >= 0) toRemove.push({x: x, y: iy, z: z});
    }
  } else { // Z axis
    for (var iz = 0; iz < this.gridSize; iz++) {
      if (this.grid[x][y][iz] >= 0) toRemove.push({x: x, y: y, z: iz});
    }
  }
  
  if (toRemove.length > 0) {
    this.removeMatches(toRemove, false);
  }
},

// 107: Freeze - No blocks drop for 2 turns
effectFreeze: function(x, y, z) {
  this.freezeTurnsLeft = 2;
  alert('❄️ Freeze activated! No new blocks for 2 turns.');
  this.dropBlocks();
},

// 108: Multiplier - Double points for 5 explosions
effectMultiplier: function(x, y, z) {
  this.pointMultiplier = 2;
  this.multiplierTurnsLeft = 5;
  alert('💎 2x Multiplier activated for 5 explosions!');
  this.dropBlocks();
},

// 109: Jackpot - Bonus points
effectJackpot: function(x, y, z) {
  var bonus = 100 * this.level;
  this.score += bonus;
  $('#score').text(this.score);
  alert('🎰 Jackpot! +' + bonus + ' points!');
  this.dropBlocks();
},

// 110: Combo Extender - Keep combo running
effectComboExtender: function(x, y, z) {
  alert('⭐ Combo extender activated!');
  // TODO: Implement combo timer system
  this.dropBlocks();
},

// 111: Black Hole - Pull all blocks to center
effectBlackHole: function(x, y, z) {
  alert('🕳️ Black Hole! All blocks pulled to center.');
  this.dropBlocks();
},

// 112: Teleporter - Swap 5 random block pairs
effectTeleporter: function(x, y, z) {
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
  alert('🎨 Color changed!');
},

// 114: Shield - Save from game over once
effectShield: function(x, y, z) {
  this.hasShield = true;
  alert('🛡️ Shield activated! One free continue.');
  this.dropBlocks();
},
