# Block Matcher 3D - Architecture Documentation

## Overview

Block Matcher 3D is a Three.js-based 3D puzzle game where players swap blocks in a 13×13×13 grid to create matches and chain reactions. The game features a progressive difficulty system (levels 1-999 with playable area capping at 9×9×9), special blocks with unique effects, flood-fill match detection, and complex asynchronous processing for smooth gameplay.

**Version**: 2.1.1  
**Last Updated**: January 19, 2026

## Table of Contents

1. [Core Architecture](#core-architecture)
2. [Nested Loop Process Flow](#nested-loop-process-flow)
3. [State Management](#state-management)
4. [Match Detection System](#match-detection-system)
5. [Special Block System](#special-block-system)
6. [Spawn System](#spawn-system)
7. [Visual Rendering](#visual-rendering)
8. [Performance Optimizations](#performance-optimizations)
9. [Settlement Algorithm](#settlement-algorithm)
10. [Chain Reaction System](#chain-reaction-system)

---

## Core Architecture

### Game Structure

```javascript
BlockMatcher3DGame {
  gridSize: 13           // Full 13×13×13 cube (2,197 blocks)
  level: 1-999          // Progressive difficulty (no effective cap)
  playableSize: 3-9     // Active region based on level (caps at level 3+)
  blockTypes: 7         // Number of color variations (0-6)
  minMatch: 3           // Minimum blocks needed to match
  isSettling: boolean   // Global lock flag
  comboMatchCount: int  // Current combo counter (disables specials at 500+)
  grid[x][y][z]        // 3D array: -2 (outside), -1 (empty), 0-6 (colors), 100-114 (special)
  blockMeshes{}        // Three.js mesh registry by "x_y_z" key
}
```

### Key Design Patterns

1. **Single Lock Point**: `isSettling = true` at turn start
2. **Single Unlock Point**: `completeTurn()` called after all processing with automatic cleanup
3. **Callback Chain**: All async operations use callbacks to maintain sequence
4. **In-Place Updates**: Mesh positions updated during animation, no recreation
5. **Recursive Chain Reactions**: Matches trigger settlements trigger matches
6. **Match-Activated Specials**: Special blocks trigger effects when matched (not clicked)
7. **Flood-Fill Matching**: Any connected configuration of same-color blocks triggers match
8. **Edge Spawning**: New blocks only spawn at grid edges with game-over on blocked spawn

---

## Nested Loop Process Flow

### 1. Initialization Flow

```
init()
│
├─► updateLevel()
│   └─► playableSize = Math.min(1 + (level × 2), 9)  // Level 1=3×3×3, Level 3+=9×9×9 (caps)
│
├─► createGrid()
│   └─► FOR x in 0..12
│       └─► FOR y in 0..12
│           └─► FOR z in 0..12
│               ├─► IF inside playableSize:
│               │   ├─► IF comboMatchCount > 500: Only regular colors
│               │   ├─► ELSE 95% chance: Random color (0-6)
│               │   └─► ELSE 5% chance: Random special block (100-114)
│               └─► ELSE: Mark as outside (-2)
│
├─► initThreeJS()
│   ├─► Create Scene, Camera, Renderer
│   ├─► Add lights (ambient + directional)
│   └─► Setup raycaster for mouse interaction
│
├─► render3D()
│   └─► FOR each block in grid (where value ≠ -1)
│       ├─► Create BoxGeometry mesh
│       ├─► Apply material (color, emissive, solid)
│       ├─► IF special block: Apply emoji texture to all 6 faces
│       │   └─► createEmojiTexture(): White circle bg + black border + emoji with outline
│       ├─► IF at spawn edge: Mark with isSpawnEdge flag
│       └─► Store in blockMeshes[x_y_z]
│
├─► startTimer() - setInterval(1000ms)
├─► bindEvents() - Mouse + keyboard handlers
└─► startCenterBlockShimmer() - setInterval(50ms)
```

### 2. User Input → Turn Processing Flow

```
User clicks block → onClick()
│
├─► Raycaster intersection test
├─► Extract (x, y, z) from mesh.userData
└─► handleBlockClick(x, y, z)
    │
    ├─► CHECK: isSettling? → RETURN (block interaction)
    │
    ├─► SET: isSettling = true ◄─────────────────┐ LOCK
    │                                              │
    ├─► CHECK: Center block (9,9,9)?              │
    │   └─► YES: explodeAllBlocks() → Game Over   │
    │                                              │
    ├─► CHECK: Special block (100-114)?           │
    │   ├─► YES: handleSpecialBlock()             │
    │   │   └─► (See Special Block Flow below)    │
    │   │                                          │
    │   └─► NO: Regular block processing          │
    │       ├─► Increment moves counter           │
    │       ├─► removeMatches([block], callback)  │
    │       └─► callback:                         │
    │           ├─► IF freeze: completeTurn()     │
    │           └─► ELSE: dropRandomBlocks() ─────┤
    │                                              │
    └─► (Eventually all paths converge...)        │
        └─► completeTurn()                        │
            ├─► Log "Turn complete, unlocking"    │
            └─► isSettling = false ◄──────────────┘ UNLOCK
```

### 3. Explosion & Chain Reaction Flow (removeMatches)

```
removeMatches(matches, skipDrop, callback)
│
├─► STEP 3: Explosion Animation (250ms)
│   ├─► FOR each match in matches[]
│   │   └─► Animate: scale up, rotate, fade out
│   ├─► After 250ms: Set grid[x][y][z] = -1 (empty)
│   └─► render3D() (rebuild scene without removed blocks)
│
├─► STEP 4: Drop & Settle (skipDrop determines behavior)
│   │
│   ├─► IF skipDrop == false:
│   │   └─► dropBlocks(callback) ──► settleAllBlocks()
│   │                                 │
│   │                                 ├─► SETTLEMENT LOOP ◄──┐
│   │                                 │   │                   │
│   │                                 │   ├─► Find blocks that can move
│   │                                 │   │   └─► FOR x in 0..17
│   │                                 │   │       └─► FOR y in 0..17
│   │                                 │   │           └─► FOR z in 0..17
│   │                                 │   │               └─► IF block exists
│   │                                 │   │                   └─► Check 6 directions toward center
│   │                                 │   │                       └─► IF empty space: add to moveMap
│   │                                 │   │
│   │                                 │   ├─► IF moveMap.length > 0:
│   │                                 │   │   ├─► animateBlockMovement(moveMap, callback)
│   │                                 │   │   │   ├─► Smooth animation (100ms)
│   │                                 │   │   │   ├─► Update mesh positions IN-PLACE
│   │                                 │   │   │   ├─► Update blockMeshes registry keys
│   │                                 │   │   │   └─► callback: settleStep() ─────┘ RECURSE
│   │                                 │   │   │
│   │                                 │   │   └─► Log "Iteration complete, blocks moved"
│   │                                 │   │
│   │                                 │   └─► ELSE: No blocks moved (stable)
│   │                                 │       └─► Check for NEW matches formed
│   │                                 │
│   │                                 └─► IF new matches found:
│   │                                     ├─► Log "Matches found after settlement"
│   │                                     ├─► processMatchesWithoutDrop() ◄─┐ CHAIN
│   │                                     │   │                              │
│   │                                     │   └─► FOR each block position    │
│   │                                     │       └─► checkMatchAt(x,y,z)    │
│   │                                     │           └─► Check 6 directions  │
│   │                                     │               └─► IF ≥3 match:   │
│   │                                     │                   └─► removeMatches() ─┘ RECURSIVE
│   │                                     │
│   │                                     └─► Re-settle after chain matches
│   │
│   └─► ELSE skipDrop == true:
│       └─► processMatchesWithoutDrop(callback) (skip dropping)
│
├─► STEP 5: Check Chain Matches
│   └─► (Handled within processMatchesWithoutDrop)
│
├─► STEP 4b: Final Settle Before Regeneration
│   └─► dropBlocks(callback)
│
├─► STEP 6: Regenerate Blocks
│   │
│   ├─► Calculate empty positions in playable area
│   │   └─► FOR x in 0..(playableSize-1)
│   │       └─► FOR y in 0..(playableSize-1)
│   │           └─► FOR z in 0..(playableSize-1)
│   │               └─► IF grid[x][y][z] == -1: add to emptyPositions[]
│   │
│   ├─► Generate new blocks
│   │   └─► WHILE blocksToAdd > 0 AND emptyPositions.length > 0
│   │       ├─► Pick random empty position
│   │       ├─► Assign random block type (95% color, 5% special)
│   │       └─► blocksToAdd--
│   │
│   └─► IF blocks added > 0:
│       ├─► render3D() (rebuild with new blocks)
│       ├─► STEP 4: dropBlocks() (settle new blocks)
│       └─► Check for matches formed by new blocks
│           └─► IF matches: processMatchesWithoutDrop() ◄── MORE CHAINS
│
├─► STEP 4c: Final Settle After Regeneration Chains
│   └─► dropBlocks(callback)
│
└─► callback() ──► (Eventually reaches completeTurn())
```

### 4. Special Block Processing

```
handleSpecialBlock(blockType, x, y, z)
│
└─► SWITCH(blockType):
    │
    ├─► 100: Bomb 💣 (effectBomb)
    │   ├─► Collect 3×3×3 cube around clicked block
    │   │   └─► FOR dx in [-1, 0, 1]
    │   │       └─► FOR dy in [-1, 0, 1]
    │   │           └─► FOR dz in [-1, 0, 1]
    │   │               └─► Add to toRemove[]
    │   │
    │   └─► removeMatches(toRemove, false, completeTurn)
    │
    ├─► 101: Lightning ⚡ (effectLightning)
    │   ├─► Pick random color (0-4)
    │   ├─► Scan entire grid for that color
    │   │   └─► FOR x in 0..17
    │   │       └─► FOR y in 0..17
    │   │           └─► FOR z in 0..17
    │   │               └─► IF grid[x][y][z] == targetColor: add to toRemove[]
    │   │
    │   └─► removeMatches(toRemove, false, completeTurn)
    │
    ├─► 105: Shuffler 🔄 (effectShuffler)
    │   ├─► Collect all non-empty blocks
    │   ├─► Pick random 10 blocks
    │   ├─► FOR each pair: swap grid positions
    │   ├─► render3D() (rebuild scene)
    │   └─► dropBlocks(completeTurn)
    │
    ├─► 106: Laser 🎯 (effectLaser)
    │   ├─► Pick random axis (X, Y, or Z)
    │   ├─► Collect all blocks along that line through clicked position
    │   │   └─► IF axis == X: FOR ix in 0..17 → toRemove[ix][y][z]
    │   │   └─► IF axis == Y: FOR iy in 0..17 → toRemove[x][iy][z]
    │   │   └─► IF axis == Z: FOR iz in 0..17 → toRemove[x][y][iz]
    │   │
    │   └─► removeMatches(toRemove, false, completeTurn)
    │
    ├─► 107: Freeze ⏸️ (effectFreeze)
    │   ├─► freezeTurnsLeft = 2 (no new blocks drop for 2 turns)
    │   ├─► Show message: "Freeze activated!"
    │   └─► dropBlocks(completeTurn)
    │
    ├─► 108: Multiplier 💎 (effectMultiplier)
    │   ├─► pointMultiplier = 2
    │   ├─► multiplierTurnsLeft = 5
    │   ├─► Show message: "2× points for 5 turns!"
    │   └─► dropBlocks(completeTurn)
    │
    ├─► 109: Jackpot 🎰 (effectJackpot)
    │   ├─► score += 100
    │   ├─► Show message: "Jackpot! +100 points!"
    │   └─► dropBlocks(completeTurn)
    │
    ├─► 110: Combo Extender ⭐ (effectComboExtender)
    │   ├─► Add 3 seconds to timer
    │   ├─► Show message: "+3 seconds!"
    │   └─► dropBlocks(completeTurn)
    │
    ├─► 112: Teleporter 🔮 (effectTeleporter)
    │   ├─► Collect all blocks
    │   ├─► Fisher-Yates shuffle algorithm
    │   │   └─► FOR i from blocks.length-1 down to 1
    │   │       └─► Swap with random block at index 0..i
    │   │
    │   ├─► render3D()
    │   └─► dropBlocks(check matches → completeTurn)
    │
    ├─► 113: Color Changer 🎨 (effectColorChanger)
    │   ├─► Pick fromColor and toColor (random 0-4)
    │   ├─► FOR x in 0..17
    │   │   └─► FOR y in 0..17
    │   │       └─► FOR z in 0..17
    │   │           └─► IF grid[x][y][z] == fromColor: set to toColor
    │   │
    │   ├─► render3D()
    │   ├─► Show message: "Color changed!"
    │   └─► isSettling = false (direct unlock - no drops)
    │
    └─► 114: Shield 🛡️ (effectShield)
        ├─► hasShield = true (one free continue)
        ├─► Show message: "Shield activated!"
        └─► dropBlocks(isSettling = false)
```

---

## State Management

### Global Lock: isSettling Flag

The entire game uses a single boolean flag to prevent race conditions and ensure sequential processing:

```javascript
// Lock at turn start
handleBlockClick(x, y, z) {
  if (this.isSettling) return; // Block re-entry
  this.isSettling = true;      // LOCK
  // ... process turn ...
}

// Unlock at turn end (single unlock point)
completeTurn() {
  console.log('Turn complete, unlocking');
  this.isSettling = false;      // UNLOCK
}
```

### State Transitions

```
IDLE (isSettling = false)
  └─► User clicks block
      └─► PROCESSING (isSettling = true)
          ├─► Remove matches
          ├─► Settle blocks (iterative loop)
          ├─► Check chain reactions (recursive)
          ├─► Regenerate blocks
          ├─► Settle again (iterative loop)
          ├─► Check more chains (recursive)
          └─► completeTurn()
              └─► IDLE (isSettling = false)
```

### Critical Paths to completeTurn()

All processing paths must eventually call `completeTurn()`:

1. **Regular blocks**: `removeMatches` → `dropRandomBlocks` → `completeTurn()`
2. **Explosive specials** (Bomb/Lightning/Laser): `removeMatches(callback: completeTurn)`
3. **Rearranging specials** (Shuffler/Teleporter): `dropBlocks(callback: completeTurn)`
4. **Buffing specials** (Freeze/Multiplier/Jackpot/ComboExtender/Shield): `dropBlocks(callback: completeTurn)`
5. **Direct specials** (ColorChanger): `completeTurn()` directly

---

## Special Block System

### Block Type Encoding

- **0-4**: Regular color blocks (red, blue, green, yellow, purple)
- **-1**: Empty space (block removed or dropped)
- **-2**: Outside playable area (gray boundary blocks)
- **100-114**: Special blocks (15 total, some numbers skipped)

### Special Block Rarity System

```javascript
specialBlocks = {
  100: { name: 'Bomb',          rarity: 30 },  // 30% of special drops
  101: { name: 'Lightning',     rarity: 30 },
  105: { name: 'Shuffler',      rarity: 60 },  // Less common
  106: { name: 'Laser',         rarity: 60 },
  107: { name: 'Freeze',        rarity: 30 },
  108: { name: 'Multiplier',    rarity: 30 },
  109: { name: 'Jackpot',       rarity: 30 },
  110: { name: 'Combo Extender', rarity: 9 },  // Rare
  112: { name: 'Teleporter',    rarity: 9 },
  113: { name: 'Color Changer', rarity: 9 },
  114: { name: 'Shield',        rarity: 1 }    // Very rare
}

Total rarity weight: 258
Special block generation chance: 5% of all blocks
```

### Special Block Categories

1. **Explosive** (Bomb, Lightning, Laser): Remove blocks → trigger chain reactions
2. **Rearranging** (Shuffler, Teleporter): Shuffle grid → potential new matches
3. **Time-based Buffs** (Multiplier, Freeze): Affect next N turns
4. **Instant Effects** (Jackpot, Combo Extender, Shield): Immediate benefit
5. **Grid Modifiers** (Color Changer): Transform colors

---

## Performance Optimizations

### 1. Sprite Creation Optimization (CRITICAL FIX)

**Problem**: Originally, `render3D()` was called after every settlement iteration, destroying and recreating ALL sprites 10-20+ times per turn.

**Solution**: 
```javascript
// OLD (in animateBlockMovement):
self.render3D();  // ❌ Destroys/recreates 5,832+ meshes
if (callback) callback();

// NEW:
// Update mesh keys to match new positions (no rebuild)
Object.keys(moveMap).forEach(function(oldKey) {
  var move = moveMap[oldKey];
  var mesh = self.blockMeshes[oldKey];
  if (mesh) {
    var newKey = move.newX + '_' + move.newY + '_' + move.newZ;
    mesh.userData = { x: move.newX, y: move.newY, z: move.newZ };
    self.blockMeshes[newKey] = mesh;  // ✅ Just update registry
    delete self.blockMeshes[oldKey];
  }
});
if (callback) callback();
```

**Impact**: 
- Before: 40+ "Creating sprite" logs per turn
- After: 1-5 "Creating sprite" logs per turn (only when new blocks added)
- Performance improvement: ~90% reduction in mesh operations

### 2. Settlement Iteration Optimization

```javascript
// Efficient: Only animate actual moves
if (Object.keys(moveMap).length > 0) {
  animateBlockMovement(moveMap, callback);  // Smooth 100ms animation
} else {
  // No moves needed, proceed immediately
  callback();
}
```

### 3. Match Detection Early Exit

```javascript
checkMatchAt(x, y, z) {
  // Check each direction until minimum match found
  for (each direction) {
    if (count >= this.minMatch) {
      return matches;  // Early exit, no need to check further
    }
  }
}
```

---

## Settlement Algorithm

### Gravity Simulation (Toward Center)

Blocks move toward the center point (9, 9, 9) along all three axes:

```javascript
centerPos = 9;  // Middle of 18×18×18 grid

// For each block, determine direction toward center
xDir = (x > centerPos) ? -1 : (x < centerPos) ? 1 : 0;
yDir = (y > centerPos) ? -1 : (y < centerPos) ? 1 : 0;
zDir = (z > centerPos) ? -1 : (z < centerPos) ? 1 : 0;

// Try to move in priority order: X > Y > Z
if (xDir !== 0 && grid[x+xDir][y][z] === -1) {
  moveBlock(x, y, z → x+xDir, y, z);
} else if (yDir !== 0 && grid[x][y+yDir][z] === -1) {
  moveBlock(x, y, z → x, y+yDir, z);
} else if (zDir !== 0 && grid[x][y][z+zDir] === -1) {
  moveBlock(x, y, z → x, y, z+zDir);
}
```

### Iterative Settlement Loop

```javascript
function settleStep() {
  var moveMap = {};
  
  // Find all blocks that can move (one step each)
  for (var x = 0; x < gridSize; x++) {
    for (var y = 0; y < gridSize; y++) {
      for (var z = 0; z < gridSize; z++) {
        if (canMoveTowardCenter(x, y, z)) {
          moveMap[oldKey] = {newX, newY, newZ, oldX, oldY, oldZ};
        }
      }
    }
  }
  
  if (Object.keys(moveMap).length > 0) {
    animateBlockMovement(moveMap, function() {
      setTimeout(settleStep, 25);  // ◄── RECURSE with delay
    });
  } else {
    // All stable, check for new matches
    checkForMatchesAfterSettlement();
  }
}
```

**Key characteristics**:
- Blocks move **one step per iteration**
- Each iteration is visually animated (100ms)
- Loop continues until no blocks can move
- Typical depth: 5-20 iterations per settlement
- Maximum possible: ~27 iterations (corner to center)

---

## Chain Reaction System

### Recursive Match Processing

```javascript
processMatchesWithoutDrop(callback) {
  var foundMatches = false;
  
  // Scan grid for matches
  for (var x = 0; x < gridSize; x++) {
    for (var y = 0; y < gridSize; y++) {
      for (var z = 0; z < gridSize; z++) {
        var matches = checkMatchAt(x, y, z);
        if (matches.length >= minMatch) {
          foundMatches = true;
          // RECURSIVE: Remove matches → settle → check again
          removeMatches(matches, skipDrop=true, function() {
            processMatchesWithoutDrop(callback);  // ◄── RECURSE
          });
          return;  // Process one match at a time
        }
      }
    }
  }
  
  if (!foundMatches && callback) {
    callback();  // No more matches, end chain
  }
}
```

### Chain Reaction Depth

**Observed behavior**:
- Simple turns: 0-1 chain reactions
- Complex Shuffler turns: 3-5 chain reactions
- Epic Lightning on full grid: 5-10+ chain reactions
- Maximum theoretical depth: Unlimited (until no matches remain)

### Chain Reaction Example

```
User clicks Lightning ⚡ (destroys all blue blocks)
│
├─► removeMatches(all blues)
│   └─► STEP 4: dropBlocks() → settleAllBlocks()
│       ├─► [15 iterations of blocks falling]
│       └─► Settlement complete, check for new matches
│           └─► Found: 5 new matches (formed during settlement)
│               │
│               ├─► removeMatches(match 1, skipDrop=true)
│               │   └─► Re-settle → Found: 2 new matches
│               │       │
│               │       ├─► removeMatches(match 1.1, skipDrop=true)
│               │       │   └─► Re-settle → No matches
│               │       │
│               │       └─► removeMatches(match 1.2, skipDrop=true)
│               │           └─► Re-settle → No matches
│               │
│               ├─► removeMatches(match 2, skipDrop=true)
│               │   └─► Re-settle → No matches
│               │
│               └─► ... (process remaining matches)
│
└─► All chains complete → regenerateBlocks()
    └─► Drop new blocks → settle → check matches again
        └─► (Potential for MORE chains)
            └─► Eventually → completeTurn()
```

---

## Performance Metrics

### Computational Complexity

| Operation | Complexity | Notes |
|-----------|-----------|-------|
| createGrid() | O(n³) | One time: 18³ = 5,832 blocks |
| Settlement iteration | O(n³) | Per iteration: 5,832 checks |
| Match detection | O(n³ × 6) | Per check: ~35,000 operations |
| Chain reaction | O(depth × n³) | Depth typically 2-5, max ~10 |
| Full turn (typical) | O(15 × n³) | ~87,480 grid operations |
| Full turn (complex) | O(50 × n³) | ~291,600 grid operations |

### Timing Benchmarks (Typical Turn)

| Phase | Duration | Notes |
|-------|----------|-------|
| Click → Lock | <1ms | Instant |
| Explosion animation | 250ms | Visual effect |
| Settlement iteration | 125ms | 100ms animation + 25ms delay |
| Match detection | ~5ms | After each settlement |
| Chain reaction | 250-500ms | Per recursive level |
| Regeneration | 100-300ms | Drop new blocks |
| Total turn time | 1-3 seconds | User perception |

---

## Debugging & Logging

### Console Log Flow

```
Setting isSettling = true
>>> STEP 3: Explosion - 1 blocks (skipDrop=false)
>>> STEP 4: Drop & Settle Starting
  >> Settle: Beginning settlement
  >> Settle: Iteration complete, blocks moved
  >> Settle: Iteration complete, blocks moved
  >> Settle: Complete - all blocks stable
  >> Settle: Checking for new matches after settlement
  >> Settle: Matches found after settlement, processing...
>>> STEP 5: Check Chain Matches
>>> STEP 3: Explosion - 3 blocks (skipDrop=true)
>>> STEP 5: Check Chain Matches
  >> Settle: Re-settling after matches...
  >> Settle: Beginning settlement
  >> Settle: Complete - all blocks stable
  >> Settle: Checking for new matches after settlement
  >> Settle: Complete, calling callback
>>> STEP 5: Check Chain Matches
>>> STEP 4b: Final Settle Before Regeneration
>>> STEP 4: Drop & Settle Starting
  >> Settle: Beginning settlement
  >> Settle: Complete - all blocks stable
  >> Settle: Checking for new matches after settlement
  >> Settle: Complete, calling callback
>>> STEP 6: Regenerate Blocks - adding 2 blocks
>>> STEP 4: Drop & Settle Starting
  >> Settle: Beginning settlement
  >> Settle: Iteration complete, blocks moved
  >> Settle: Complete - all blocks stable
  >> Settle: Checking for new matches after settlement
  >> Settle: Complete, calling callback
>>> STEP 5: Check Chain Matches
>>> STEP 4c: Final Settle After Regeneration Chains
>>> STEP 4: Drop & Settle Starting
  >> Settle: Beginning settlement
  >> Settle: Complete - all blocks stable
  >> Settle: Checking for new matches after settlement
  >> Settle: Complete, calling callback
Turn complete, unlocking (isSettling = false)
```

### Common Issues & Solutions

#### Issue: Game stuck, can't click
**Symptom**: "Click blocked: isSettling is true" repeated in console, no "Turn complete"
**Cause**: Missing `completeTurn()` call in some code path
**Solution**: Ensure ALL special block effects pass callback to `removeMatches()` or call `completeTurn()` directly

#### Issue: Sprites created hundreds of times
**Symptom**: "Creating sprite for special block" repeating 40+ times per turn
**Cause**: `render3D()` called inside settlement loop
**Solution**: Only update mesh positions during animation, rebuild scene only when blocks added/removed

#### Issue: Slow performance, lag
**Symptom**: Animations stuttering, clicks delayed
**Cause**: Too many full grid scans, excessive mesh recreation
**Solution**: 
- Use in-place mesh updates during animation
- Early exit from match detection when found
- Limit settlement iteration delay (25ms optimal)

---

## Extension Points

### Adding New Special Blocks

1. Choose unused block ID (115-199 available)
2. Add to `specialBlocks` object with rarity weight
3. Add case to `handleSpecialBlock()` switch statement
4. Create effect function (e.g., `effectNewBlock`)
5. Ensure effect calls `completeTurn()` via callback or directly

### Modifying Grid Size

Current: 18×18×18 (5,832 blocks)
To change: Update `this.gridSize` in constructor
Performance impact: O(n³) for all operations

### Adjusting Difficulty Curve

Current: `playableSize = 1 + (level × 2)` → 3, 5, 7, 9, 11, 13, 15, 17, 18
Customize: Modify `updateLevel()` function

---

## Files

- **Main game logic**: `js/block-matcher-3d.js` (2,270 lines)
- **Styles**: `css/block-matcher-3d.css`
- **Template**: `templates/game-board.html.twig`
- **Module definition**: `forseti_games.module`
- **Routing**: `forseti_games.routing.yml`
- **Libraries**: `forseti_games.libraries.yml` (Three.js r160)

---

## Future Optimizations

1. **Web Workers**: Move grid calculations to background thread
2. **Spatial Partitioning**: Only check nearby blocks for matches
3. **Dirty Flags**: Track which regions changed to avoid full grid scans
4. **Object Pooling**: Reuse Three.js geometries/materials
5. **Progressive Rendering**: Render playable region first, boundaries after
6. **Frustum Culling**: Only render visible blocks (already handled by Three.js)

---

## Conclusion

Block Matcher 3D uses a carefully orchestrated system of nested loops, recursive callbacks, and state management to provide smooth gameplay despite complex asynchronous processing. The single lock/unlock pattern ensures all operations complete in proper sequence, while optimizations like in-place mesh updates keep performance smooth even with 5,832 blocks in the grid.

Key takeaways:
- **Sequential Processing**: One turn at a time via `isSettling` flag
- **Callback Chains**: All async operations maintain proper sequencing
- **Recursive Chains**: Matches trigger settlements trigger matches (ad infinitum)
- **Performance First**: In-place updates, early exits, minimal rebuilds
- **Extensible Design**: Easy to add new special blocks and features
