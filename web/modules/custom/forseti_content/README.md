# Forseti Core Content Module

**Last Updated:** February 6, 2026

## Overview

The **Forseti Core** module provides essential content management and page routing for the Forseti.Life platform - a community platform for AI-powered services and member support. This module serves as the foundation for the Forseti website, managing core pages, navigation, and content presentation.

## Purpose

This module acts as the central content hub for Forseti.Life, providing:
- Homepage and main navigation structure
- Core informational pages (About, How It Works, Community, Contact)
- Safety-focused content integration
- AI conversation interface pages
- Member support pages
- Footer menu structure

## Key Features

### Page Controllers
- **ForsetiHomeController**: Manages homepage and safety map display
- **ForsetiPagesController**: Handles core informational pages
- **SafetyController**: Provides safety-related page content
- **AgentPowerFrameworkController**: Integrates AI evaluation framework
- **BatchEvaluationController**: Manages batch processing interfaces

### Core Routes
- `/` - Homepage (AI Looking Out For Us)
- `/home` - Alternative homepage route
- `/roadmap` - Portfolio roadmap synced from HQ `dashboards/PROJECTS.md`
- `/roadmap/{project_id}` - Individual project roadmap detail page
- `/safety-map` - Interactive crime safety map
- `/talk-with-forseti_content` - AI conversation interface
- `/about` - About Forseti platform
- `/how-it-works` - Platform explanation
- `/community` - Community engagement page
- `/contact` - Contact form and information
- `/privacy-policy` - Privacy policy
- `/terms-of-service` - Terms and conditions
- `/faqs` - Frequently asked questions

### Services
- Custom services for content management
- Integration with other Forseti modules (AmISafe, AI Conversation)
- Plugin system for extensibility

### Synced Portfolio Roadmap
- Renders the live Forseti project registry from `copilot-hq/dashboards/PROJECTS.md`
- Normalizes project cards into ascending `PROJ-###` order so roadmap presentation stays stable even if the backing registry table is edited out of sequence

## Technical Details

### Dependencies
- `drupal:node` - Content type management
- `drupal:field` - Field system
- `drupal:text` - Text field types
- `drupal:image` - Image handling
- `drupal:link` - Link field types
- `drupal:menu_ui` - Menu management

### Module Structure
```
forseti_content/
├── forseti_content.info.yml        # Module definition
├── forseti_content.module          # Hook implementations
├── forseti_content.routing.yml     # Page routes
├── forseti_content.services.yml    # Service definitions
├── forseti_content.install         # Installation/update hooks
├── forseti_content.libraries.yml   # CSS/JS libraries
├── forseti_content.links.menu.yml  # Main menu links
├── forseti_content.links.menu.footer.yml  # Footer menu links
├── config/                         # Configuration exports
├── src/
│   ├── Controller/                 # Page controllers
│   ├── Service/                    # Custom services
│   └── Plugin/                     # Plugin implementations
├── templates/                      # Twig templates
├── css/                           # Stylesheets
└── js/                            # JavaScript files
```

## Installation

This module is a core dependency for Forseti.Life and should be enabled on all instances:

```bash
drush en forseti_content -y
drush cr
```

## Configuration

Configuration is managed through the Drupal configuration system. Export changes with:

```bash
drush cex -y
```

## Integration Points

### AmISafe Module
- Safety map integration on homepage
- Crime data visualization

### AI Conversation Module
- "Talk with Forseti" interface
- AI-powered content assistance

### Menu System
- Main navigation menu
- Footer menu structure
- Mobile-responsive navigation

## Version

**Version:** 2.0  
**Drupal Compatibility:** ^10.3 || ^11  
**Package:** Forseti

## Maintenance Notes

- This is a **CORE MODULE** - do not uninstall without full backup
- Content types and fields are managed through this module
- Menu structure changes should be tested on staging first
- Page routes are hardcoded and should not conflict with other modules

## Support

For issues or questions about this module:
- Review the module code in `src/` directory
- Check route definitions in `forseti_content.routing.yml`
- Consult the Forseti development team

---

**Module Author:** Keith Aumiller  
**Organization:** Forseti.Life  
**License:** Proprietary
