# Institutional Management - Template Files

This directory contains Twig template files for the Institutional Management module, focused on community governance, membership, and organizational structure.

## Templates

### page--front.html.twig
- **Purpose**: Community-focused home page for forseti.life
- **Created**: February 5, 2026
- **Content Focus**:
  - Community philosophy: Tolerant, scientific, technology-embracing
  - Member services and AI automation capabilities
  - Membership information and application process
  - Privately funded community structure
  
**Key Sections**:
1. **Hero Section**: Animated header with community tagline
2. **Community Philosophy**: Three pillars (Science & Technology, Community Care, Tolerance & Equity)
3. **What We Build**: AI-powered services and member benefits
4. **Membership**: Private funding, invitation/application process, value proposition

**Usage**: 
- Place this template in the theme templates directory to activate
- Copy to: `web/themes/custom/forseti/templates/page/page--front.html.twig`
- Clear cache: `drush cr`

## Template Deployment

To deploy templates from this module to the active theme:

```bash
# Copy front page template to theme
cp web/modules/custom/institutional_management/templates/page--front.html.twig \
   web/themes/custom/forseti/templates/page/page--front.html.twig

# Clear Drupal cache
cd /home/keithaumiller/forseti.life/sites/forseti
./vendor/bin/drush cr
```

## Template Management Philosophy

Templates are maintained in custom modules for:
- **Version control**: Track changes in module context
- **Feature grouping**: Keep related templates together
- **Safe backup**: Original versions preserved before deployment
- **Module association**: Clear ownership of template functionality
