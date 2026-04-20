# Block Matcher 3D Architecture Updates
**Date**: January 19, 2026  
**Version**: 2.1.1

## Executive Summary

The Block Matcher 3D architecture documentation has been reviewed against the current codebase (v2.1.1). Several significant changes have been implemented that require documentation updates:

### Critical Changes
1. **Grid Size**: Changed from 18×18×18 to 13×13×13
2. **Color Blocks**: Increased from 5 to 7 colors
3. **Special Block Spawn Rate**: Reduced from 10% to 5%
4. **Special Block Activation**: Now activated by matching (not by clicking)
5. **Match Detection**: Uses flood-fill algorithm (any connected configuration)
6. **Spawn Mechanics**: Added spawn-blocking game over with red throbbing warnings
7. **Combo Limit**: Special blocks disabled when combo > 500
8. **Cleanup System**: Added automatic orphaned mesh cleanup at turn completion

---

## Detailed Changes

### 1. Grid Dimensions
**Documentation States**: 18×18×18 grid (5,832 blocks)  
**Current Implementation**: 13×13×13 grid (2,197 blocks)

**Impact**:
- 62% reduction in total blocks
- Performance improved significantly
- Playable area calculation: `playableSize = Math.min(1 + (level × 2), 9)` → caps at 9×9×9
- Center position: Changed from (9,9,9) to (6,6,6)

**Updates Needed**:
```diff
- gridSize: 18           // Full 18×18×18 cube
+ gridSize: 13           // Full 13×13×13 cube
- playableSize: 3-18     // Active region based on level
+ playableSize: 3-9      // Active region based on level (caps at level 3+)
```

### 2. Color Block System
**Documentation States**: 5 colors (0-4)  
**Current Implementation**: 7 colors (0-6)

**Current Colors** (maximally-spaced on color wheel):
- 0: Red (#FF0000)
- 1: Orange (#FF8000)
- 2: Yellow (#FFFF00)
- 3: Green (#00FF00)
- 4: Cyan (#00FFFF)
- 5: Blue (#0000FF)
- 6: Magenta (#FF00FF)

**Updates Needed**:
```diff
- 0-4: Regular color blocks (red, blue, green, yellow, purple)
+ 0-6: Regular color blocks (red, orange, yellow, green, cyan, blue, magenta)
```

### 3. Special Block Spawn Rate
**Documentation States**: 5% special block spawn chance  
**Current Implementation**: 5% special block spawn chance (recently reduced from 10%)  
**Additional Rule**: Special blocks disabled when `comboMatchCount > 500`

**Updates Needed**:
```javascript
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
}
```

### 4. Special Block Activation Method
**CRITICAL CHANGE**

**Documentation States**: Special blocks activate when clicked directly  
**Current Implementation**: Special blocks activate when matched with other blocks of the same color

**Code Location**: `handleBlockClick()` lines 1323-1330 (DISABLED section)

**Current Behavior**:
```javascript
/* DISABLED: Special blocks now match by color instead of triggering on click
if (blockType >= 100) { // Special block clicked
  this.handleSpecialBlock(blockType, x, y, z);
  return;
}
*/
```

**New Flow**:
1. Special blocks have an associated match color (via `getBlockMatchColor()`)
2. When matched with ≥3 blocks of same color, they're included in match set
3. `removeMatches()` detects special blocks in the matches array
4. Special block effects trigger before blocks are removed
5. Effect executes (bomb destroys 3×3×3, lightning destroys all same-color, etc.)

**Updates Needed**:
- Document that special blocks match by COLOR, not by click
- Update all special block flow diagrams to show match-activation instead of click-activation
- Add section explaining special block color assignment

### 5. Match Detection Algorithm
**Documentation States**: Directional checking (6 directions: up, down, left, right, forward, back)  
**Current Implementation**: Flood-fill algorithm (any connected configuration)

**Algorithm Details**:
```javascript
checkMatchAt: function(x, y, z) {
  // Uses flood-fill to find ALL connected blocks of same color
  var visited = {};
  var toCheck = [{x: x, y: y, z: z}];
  var matches = [];
  
  while (toCheck.length > 0) {
    var current = toCheck.pop();
    // Mark visited, check bounds, check color match
    // Add current to matches
    // Add all 6 adjacent positions to toCheck queue
  }
  
  return matches.length >= minMatch ? matches : [];
}
```

**Impact**:
- Matches any connected shape (L-shapes, T-shapes, clusters)
- More flexible than linear matching
- Can create larger chain reactions

**Updates Needed**:
- Replace all references to "3-in-a-row" or directional matching
- Document flood-fill algorithm in detail
- Add examples of valid match configurations

### 6. Minimum Match Count
**Documentation States**: Variable (mentioned as 3)  
**Current Implementation**: 3 blocks (configurable via `data-min-match` attribute)

**Status**: Documentation is correct

### 7. Spawn Mechanics & Game Over
**NEW FEATURE** - Not documented

**Current Implementation**:
- Blocks spawn only at grid edges (x=0, x=12, y=0, y=12, z=0, z=12)
- If spawn position is occupied: **Immediate game over** (no retries)
- Blocks at spawn edges show **red throbbing animation** to warn player

**Code Functions**:
- `regenerateBlocks()`: Checks if spawn positions are occupied
- `startSpawnBlockThrob()`: Pulses red emissive intensity (0.2-0.8)
- `render3D()`: Marks blocks with `isSpawnEdge` flag

**Updates Needed**:
- Add "Spawn System" section to documentation
- Document spawn edge warning animation
- Explain immediate game over condition

### 8. Emoji Rendering System
**NEW FEATURE** - Not documented

**Current Implementation**:
Special blocks render with emoji textures on all 6 cube faces:
- White circular background (58px radius)
- Black border (6px)
- Emoji (80px) with black stroke outline (8px)
- Applied to all 6 cube faces
- Block color tint still visible through emoji

**Code Location**: `createEmojiTexture()` function

**Updates Needed**:
- Add "Visual Rendering" section
- Document emoji texture generation
- Explain special block visual design

### 9. Mesh Cleanup System
**NEW FEATURE** - Not documented

**Current Implementation**:
At the start of every turn, `cleanupOrphanedMeshes()` is called to remove any leftover graphics:

```javascript
cleanupOrphanedMeshes: function() {
  // Build set of valid block positions from grid
  // Remove meshes from blockMeshes that don't match valid positions
  // Scan scene.children for orphaned BoxGeometry meshes
  // Properly dispose geometries and materials
}
```

**Called From**: `completeTurn()` function

**Impact**:
- Prevents visual artifacts from special block explosions
- Ensures rendering matches grid state
- Reduces memory leaks

**Updates Needed**:
- Add to "Performance Optimizations" section
- Document cleanup algorithm
- Explain when/why cleanup is needed

### 10. Bomb Block Behavior
**CRITICAL CHANGE**

**Documentation States**: Bomb destroys 3×3×3 cube including itself  
**Current Implementation**: Bomb destroys 26 surrounding blocks (3×3×3 minus center)

**Rationale**: 
- Bomb block itself is removed by `removeMatches()` (as part of the match)
- `effectBomb()` removes the surrounding 26 blocks
- Total destruction: 27 blocks (bomb + 26 neighbors)

**Code Update**:
```javascript
effectBomb: function(x, y, z) {
  // Destroy all blocks in 3x3x3 cube around bomb position (excluding the bomb itself)
  for (var dx = -1; dx <= 1; dx++) {
    for (var dy = -1; dy <= 1; dy++) {
      for (var dz = -1; dz <= 1; dz++) {
        // Skip the bomb itself - it will be removed by removeMatches
        if (dx === 0 && dy === 0 && dz === 0) continue;
        // ... remove block at (x+dx, y+dy, z+dz)
      }
    }
  }
}
```

**Updates Needed**:
- Clarify that bomb is removed separately by match system
- Update bomb effect documentation to show 26 blocks + bomb itself = 27 total

---

## Process Flow Updates

### Updated User Input Flow

```
User SWAPS two blocks (or moves block)
│
├─► Raycaster selects block
├─► Player selects target position (swap mode)
└─► handleBlockSwap(x1, y1, z1, x2, y2, z2)
    │
    ├─► CHECK: isSettling? → RETURN (block interaction)
    ├─► SET: isSettling = true ◄──────────────────┐ LOCK
    │                                               │
    ├─► Swap blocks in grid                        │
    ├─► Check if EITHER position now has a match   │
    │   ├─► matches1 = checkMatchAt(x1, y1, z1)    │
    │   └─► matches2 = checkMatchAt(x2, y2, z2)    │
    │                                               │
    ├─► IF matches found:                          │
    │   ├─► Scan all matches for special blocks    │
    │   ├─► Trigger special block effects          │
    │   └─► processMatches() → settlement chain    │
    │                                               │
    └─► ELSE: No matches                           │
        └─► Swap back (invalid move)               │
        └─► completeTurn() ────────────────────────┘ UNLOCK
```

### Updated Special Block Flow

```
Special block matched with ≥3 same-color blocks
│
└─► removeMatches(matches, skipDrop, callback)
    │
    ├─► Scan matches array for special blocks
    │   └─► FOR each match:
    │       └─► IF grid[x][y][z] >= 100:
    │           └─► Add to specialBlocksToTrigger[]
    │
    ├─► Trigger special block effects
    │   └─► FOR each special:
    │       └─► handleSpecialBlock(type, x, y, z)
    │           └─► SWITCH(type):
    │               ├─► 100: effectBomb() → destroy 26 neighbors
    │               ├─► 101: effectLightning() → destroy all same-color
    │               └─► ... (other effects)
    │
    └─► Continue with normal match removal
        └─► Explosion animation → settlement → chains
```

---

## Performance Impact Analysis

### Grid Size Reduction (18³ → 13³)

| Metric | Old (18³) | New (13³) | Improvement |
|--------|-----------|-----------|-------------|
| Total blocks | 5,832 | 2,197 | 62% reduction |
| Max playable | 5,832 | 729 | 87% reduction |
| Settlement checks | 35,000/iter | 13,000/iter | 63% reduction |
| Match detection | 210,000 ops | 78,000 ops | 63% reduction |

### Special Block Spawn Rate Reduction (10% → 5%)

- Fewer special block effects per game
- Reduced visual complexity
- More predictable gameplay
- Combined with combo limit (>500), prevents special block spam in late game

### Mesh Cleanup System

- Prevents memory leaks from special block effects
- Ensures visual accuracy (no "ghost blocks")
- Minimal performance impact (runs once per turn)
- Critical for bomb explosions and other destructive effects

---

## Testing Recommendations

### Core Functionality Tests

1. **Match Detection**: Verify flood-fill works for all connected shapes
   - L-shapes, T-shapes, clusters, linear
   - Edge cases: wrapping around center block

2. **Special Block Activation**: Confirm all 11 special blocks trigger on match
   - Test each special type with 3, 4, 5+ block matches
   - Verify effects execute correctly

3. **Spawn System**: Test game over conditions
   - Fill grid to edges → next spawn should game over
   - Verify red throbbing on spawn edge blocks

4. **Mesh Cleanup**: Confirm no visual artifacts
   - Trigger bomb, lightning, laser effects
   - Check scene.children count matches grid blocks

5. **Combo Limit**: Verify special blocks stop spawning at 500+ combo
   - Build high combo chain
   - Confirm only regular blocks spawn after threshold

### Performance Tests

1. **Full Grid Settlement**: Fill entire 9×9×9 area, trigger mass explosion
   - Should complete within 3 seconds
   - No lag or stutter

2. **Chain Reactions**: Create 10+ level chain reaction
   - All chains should complete
   - Game should unlock (isSettling = false) at end

3. **Memory Leaks**: Play for 100+ turns
   - Monitor scene.children count
   - Should not grow unbounded

---

## Documentation Update Checklist

### Files to Update

- [x] **ARCHITECTURE_UPDATES_2026-01-19.md** (this file)
- [ ] **BLOCK_MATCHER_ARCHITECTURE.md**
  - [ ] Update grid size (18 → 13)
  - [ ] Update colors (5 → 7)
  - [ ] Update special block activation method
  - [ ] Add spawn system section
  - [ ] Add mesh cleanup section
  - [ ] Add emoji rendering section
  - [ ] Update match detection algorithm
  - [ ] Update bomb effect description
  - [ ] Update performance metrics

- [ ] **PERFORMANCE_ANALYSIS.md**
  - [ ] Update grid size calculations
  - [ ] Update performance benchmarks
  - [ ] Document cleanup system impact

### Code Comments to Add

```javascript
// In randomBlockType():
// Special blocks spawn at 5% rate, disabled if combo > 500

// In checkMatchAt():
// Uses flood-fill algorithm to find any connected configuration

// In removeMatches():
// Check for special blocks in matches and trigger their effects

// In cleanupOrphanedMeshes():
// Remove any leftover meshes that don't correspond to grid blocks

// In effectBomb():
// Bomb itself removed by removeMatches, this destroys 26 neighbors
```

---

## Migration Notes

For anyone working with older versions of the game:

### Breaking Changes
1. **Grid size changed**: Code relying on 18×18×18 will break
2. **Special block activation**: Click handlers for special blocks no longer work
3. **Center position**: Changed from (9,9,9) to (6,6,6)

### Backward Compatibility
- `data-block-types` attribute still works (now supports up to 7)
- `data-min-match` attribute still works (default 3)
- Special block ID numbers unchanged (100-114)

### Migration Steps
1. Update any hardcoded references to grid size
2. Remove special block click handlers
3. Update center position calculations
4. Test match detection with new flood-fill algorithm
5. Verify performance with new grid size

---

## Future Enhancements

### Planned Features
1. **Center Block Protection**: Verify center block exists at start of each turn
2. **Progressive Unlocking**: Special blocks unlock at specific levels (already implemented)
3. **Difficulty Modes**: Easy (5 colors), Normal (7 colors), Hard (9 colors)
4. **Custom Grid Sizes**: Allow player to choose grid dimensions

### Technical Debt
1. Update Three.js from r160 to latest (deprecation warnings present)
2. Refactor settlement loop for better performance
3. Add Web Worker support for grid calculations
4. Implement spatial partitioning for match detection

---

## Conclusion

The current Block Matcher 3D implementation (v2.1.1) has evolved significantly from the documented architecture. The major changes improve performance, gameplay balance, and visual polish. This document serves as a guide for updating the architecture documentation to reflect the current state of the codebase.

**Key Takeaways**:
- Grid reduced to 13×13×13 for better performance
- 7 colors provide better variety and balance
- Special blocks now match-activated (not click-activated)
- Spawn system adds strategic depth
- Cleanup system prevents visual artifacts
- All changes maintain the core gameplay loop and state management principles

**Next Steps**:
1. Update BLOCK_MATCHER_ARCHITECTURE.md with all changes
2. Test all documented behaviors match implementation
3. Add visual diagrams for new features (spawn system, flood-fill matching)
4. Create player-facing documentation explaining new mechanics
