# Forseti Games Module

**Last Updated:** February 6, 2026

## Overview

The **Forseti Games** module provides game development and hosting functionality for Forseti.Life. This module enables the creation and deployment of browser-based games as part of the community engagement features on the platform.

## Purpose

This module allows for:
- Building and hosting interactive games
- Engaging community members through gamification
- Educational game content
- Entertainment features for the platform

## Key Features

### Games Included

#### Block Matcher Game
- Match-3 style puzzle game
- Performance-optimized JavaScript implementation
- Responsive design for mobile and desktop
- Score tracking and leaderboards

See detailed documentation in `documentation/block-matcher/` for:
- `BLOCK_MATCHER_ARCHITECTURE.md` - Technical architecture
- `ARCHITECTURE_UPDATES_2026-01-19.md` - Recent updates
- `PERFORMANCE_ANALYSIS.md` - Performance metrics and optimization

### Game Development Framework
- Reusable game components
- Shared JavaScript libraries
- CSS styling system
- Template integration

## Technical Details

### Module Structure
```
forseti_games/
├── forseti_games.info.yml        # Module definition
├── forseti_games.module          # Hook implementations
├── forseti_games.routing.yml     # Game routes
├── forseti_games.libraries.yml   # JavaScript/CSS libraries
├── src/
│   ├── Controller/               # Game controllers
│   ├── Plugin/                   # Game plugins
│   └── Service/                  # Game services
├── js/                          # JavaScript game code
├── css/                         # Game stylesheets
├── templates/                   # Twig templates
└── documentation/               # Game-specific documentation
    └── block-matcher/           # Block Matcher game docs
```

## Installation

Enable the module using Drush:

```bash
drush en forseti_games -y
drush cr
```

## Game Development

### Adding New Games

1. Create game controller in `src/Controller/`
2. Define routes in `forseti_games.routing.yml`
3. Add JavaScript/CSS in respective directories
4. Register libraries in `forseti_games.libraries.yml`
5. Create Twig templates as needed
6. Document architecture in `documentation/`

### Best Practices
- Keep games lightweight for mobile performance
- Use responsive design principles
- Implement accessibility features
- Test across browsers and devices
- Document performance considerations

## Version

Check `forseti_games.info.yml` for current version information.

**Drupal Compatibility:** ^10.3 || ^11  
**Package:** Forseti

## Game Documentation

Individual games have detailed documentation in the `documentation/` directory:
- Architecture specifications
- Performance analysis
- Development updates
- User guides

## Maintenance Notes

- Games should be tested regularly for browser compatibility
- JavaScript libraries should be kept up to date
- Performance metrics should be monitored
- User feedback should inform game improvements

## Support

For issues or questions about this module:
- Review game documentation in `documentation/` directory
- Check JavaScript console for errors
- Test games in multiple browsers
- Consult the Forseti development team

---

**Module Author:** Keith Aumiller  
**Organization:** Forseti.Life  
**License:** Proprietary
