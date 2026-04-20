# ⚠️ DEPRECATED - Forseti Safety Content Module

**STATUS**: This module has been deprecated and renamed to `forseti_content`.

**DO NOT USE**: This module is kept for reference only and should not be enabled.

**Replacement**: Use the `forseti_content` module instead, located at:
`/sites/forseti/web/modules/custom/forseti_content`

---

## Historical Documentation

# Forseti Safety Content Module (DEPRECATED)

**Last Updated:** February 6, 2026

## Overview

The **Forseti Safety Content** module provides safety-focused community content management for Forseti.Life, the AI-powered community safety platform for Philadelphia. This module extends the core Forseti platform with specialized safety features, crime awareness content, and community safety resources.

## Purpose

This module delivers safety-specific content and functionality:
- Safety-focused articles and resources
- Community safety tips and alerts
- Crime prevention information
- Safety education content
- Integration with AmISafe crime data

## Key Features

### Content Types
- Safety articles and blog posts
- Community safety alerts
- Crime prevention tips
- Safety education resources

### Service Integration
- Custom safety content services
- Integration with AmISafe crime data
- Safety score calculations
- Location-based safety information

## Technical Details

### Dependencies
Refer to `forseti_safety_content.info.yml` for complete dependency list.

### Module Structure
```
forseti_safety_content/
├── forseti_safety_content.info.yml   # Module definition
├── forseti_safety_content.module     # Hook implementations
├── forseti_safety_content.routing.yml # Routes (if any)
├── forseti_safety_content.services.yml # Service definitions
├── src/
│   ├── Controller/                   # Page controllers
│   ├── Service/                      # Custom services
│   └── Plugin/                       # Plugin implementations
└── templates/                        # Twig templates
    └── archive/                      # Archived templates
```

## Installation

Enable the module using Drush:

```bash
drush en forseti_safety_content -y
drush cr
```

## Integration Points

### AmISafe Module
- Crime data integration
- Safety scoring system
- Location-based alerts

### Forseti Core Module
- Content management integration
- Page routing coordination
- Shared service utilization

## Version

Check `forseti_safety_content.info.yml` for current version information.

**Drupal Compatibility:** ^10.3 || ^11  
**Package:** Forseti

## Maintenance Notes

- Safety content should be reviewed regularly for accuracy
- Crime data integrations should be monitored for performance
- Template changes should preserve safety information accessibility

## Support

For issues or questions about this module:
- Review the module code in `src/` directory
- Check integration with AmISafe module
- Consult the Forseti development team

---

**Module Author:** Keith Aumiller  
**Organization:** Forseti.Life  
**License:** Proprietary
