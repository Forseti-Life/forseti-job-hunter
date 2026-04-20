# Forseti Theme

**Last Updated:** February 6, 2026

## Overview

The **Forseti** theme is a custom Drupal 11 theme for the Forseti.Life platform - an AI-powered community safety platform for Philadelphia. Built on the Radix base theme, it provides a modern, responsive design with H3 hexagonal design elements and safety-focused branding.

## Description

"AI-powered community safety platform for Philadelphia - Building a safer tomorrow through intelligent monitoring and community engagement"

## Technical Details

### Base Theme
- **Base:** Radix 6.0.2
- **Drupal Version:** ^10.3 || ^11
- **Engine:** Twig
- **Type:** Custom starterkit theme

### Build System
- **Build Tool:** Webpack 
- **CSS Preprocessor:** Sass/SCSS
- **JavaScript:** Modern ES6+ with Babel transpilation
- **Package Manager:** npm or Yarn

## Installation

### Prerequisites
- Node.js and npm (or Yarn) installed
- Drupal 11 site with Radix theme installed
- NVM (Node Version Manager) recommended

### Step 1: Install Dependencies

Navigate to the theme directory and switch to the correct Node version:

```bash
cd sites/forseti/web/themes/custom/forseti
nvm use
```

Install npm packages:

```bash
npm install
# or
yarn install
```

### Step 2: Configure Environment

Create environment configuration:

```bash
cp .env.example .env.local
```

Update `DRUPAL_BASE_URL` in `.env.local` with your site URL.

### Step 3: Enable Theme

Enable and set as default theme:

```bash
drush theme:enable forseti -y
drush config-set system.theme default forseti -y
drush cr
```

### Step 4: Build Assets

For development with auto-compilation:

```bash
npm run watch
# or
yarn watch
```

For production build:

```bash
npm run production
# or
yarn production
```

## Theme Structure

```
forseti/
├── forseti.info.yml           # Theme configuration
├── forseti.theme              # PHP theme hooks
├── forseti.libraries.yml      # Asset libraries
├── forseti.breakpoints.yml    # Responsive breakpoints
├── package.json               # Node dependencies
├── webpack.mix.js             # Webpack configuration
├── src/                       # Source files (SCSS, JS)
│   ├── sass/                  # Sass source files
│   ├── js/                    # JavaScript source
│   └── images/                # Source images
├── build/                     # Compiled assets
│   ├── css/                   # Compiled CSS
│   ├── js/                    # Compiled JavaScript
│   └── images/                # Optimized images
├── templates/                 # Twig template overrides
├── components/                # Component templates
├── images/                    # Theme images and logos
│   └── logos/                 # Forseti brand logos
├── config/                    # Configuration overrides
└── translations/              # Translation files
```

## Theme Regions

The Forseti theme defines the following regions:

- **navbar_branding** - Brand logo and site name
- **navbar_left** - Left navigation menu
- **navbar_right** - Right navigation (user menu, search)
- **header** - Page header area
- **breadcrumb** - Breadcrumb navigation
- **tabs** - Admin tabs
- **content** - Main content area
- **page_bottom** - Bottom page scripts/elements
- **footer** - Site footer

## Key Features

### Design Elements
- H3 hexagonal visual theme
- Safety-focused color scheme
- Responsive mobile-first design
- Accessibility-compliant (WCAG 2.1)

### Custom Styling
- Custom Bootstrap 5 integration
- Animation and transition effects
- Crime map visualization styling
- AI conversation interface design

### JavaScript Features
- Interactive map components
- AJAX form enhancements
- Modal/dialog improvements
- Progress indicators
- jQuery validation extensions

## Development

### Available Scripts

```bash
# Development mode with watch
npm run watch

# Production build (minified)
npm run production

# Development build (non-minified)
npm run development

# Linting
npm run lint
npm run lint:css
npm run lint:js

# Clean build artifacts
npm run clean
```

### Code Standards

- **CSS/SCSS:** Follow BEM methodology and Drupal CSS standards
- **JavaScript:** ESLint configuration provided
- **Twig Templates:** Follow Drupal theming best practices
- **Accessibility:** Ensure WCAG 2.1 AA compliance

### Browser Support

Configured for modern browsers (last 2 versions):
- Chrome
- Firefox
- Safari
- Edge

See `.browserslistrc` for detailed configuration.

## Customization

### Extending Libraries

The theme extends core Drupal libraries:
- `core/drupal.ajax` - Custom AJAX handlers
- `core/drupal.checkbox` - Enhanced checkbox styling
- `core/drupal.message` - Custom message styling
- `core/drupal.progress` - Progress indicator styling
- `clientside_validation_jquery/cv.jquery.validate` - Form validation

### CKEditor Integration

Custom styles are available in the CKEditor through:
`build/css/main.style.css`

### Logo and Branding

Logo files located in `images/logos/`:
- `forseti_navbar.png` - Primary navbar logo
- See logos directory README for brand guidelines

## Translations

The theme supports internationalization with PO files in the `translations/` directory.

Translation project: `forseti`  
Pattern: `themes/custom/forseti/translations/%language.po`

## Dependencies

### Drupal Modules
- Base theme: Radix

### Node Packages
See `package.json` for complete list. Key dependencies:
- Webpack & Laravel Mix
- Sass
- Babel
- PostCSS
- Autoprefixer

## Troubleshooting

### Build Issues

If you encounter build errors:

```bash
# Clear node_modules and reinstall
rm -rf node_modules package-lock.json
npm install

# Clear Drupal cache
drush cr
```

### Asset Loading Issues

Ensure Drupal's asset aggregation is properly configured:

```bash
# Rebuild theme registry
drush cr

# Check library definitions
drush library:list forseti
```

## Performance

### Production Optimization

For production sites:
1. Run `npm run production` to minify assets
2. Enable Drupal CSS/JS aggregation
3. Configure CDN for static assets
4. Implement caching strategy

## Support

For theme issues or questions:
- Review build logs for errors
- Check Drupal logs: `/admin/reports/dblog`
- Inspect browser console for JavaScript errors
- Verify asset paths in page source

## Version History

**Version:** 6.0.2 (based on Radix version)  
**Date:** May 8, 2025  
**Project:** radix  
**Theme Name:** Forseti (custom implementation)

## Related Documentation

- Main README.mdx - Detailed installation guide
- README_DIALOG.mdx - Dialog component documentation
- Radix theme documentation: https://www.drupal.org/project/radix

---

**Theme Developer:** Keith Aumiller  
**Organization:** Forseti.Life  
**License:** Proprietary  
**Screenshot:** `screenshot.png`  
**Logo:** `images/logos/forseti_navbar.png`
