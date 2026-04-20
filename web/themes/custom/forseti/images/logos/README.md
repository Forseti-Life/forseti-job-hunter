# Forseti Logo Assets

## Overview
This directory contains all logo and branding assets for the Forseti community safety platform.

## Original Files (1024×1024px)
Located in `originals/` subdirectory:
- **forseti_site.png** (715KB) - Main site logo, square format
- **forseti_mobile.png** (705KB) - Mobile app icon, optimized for app stores
- **Fulllogosheet.jpg** (234KB) - Complete logo reference sheet with variants

## Web-Optimized Assets

### Navbar Logo
- **forseti_navbar.png** (2.6KB, 200×50px)
  - Optimized for website navigation bar
  - Maintains quality at small size
  - Fast loading for page headers

### Favicons
Located in `favicons/` subdirectory:
- **favicon-16x16.png** (751B) - Browser tab icon (small)
- **favicon-32x32.png** (1.5KB) - Browser tab icon (standard)
- **favicon-48x48.png** (2.5KB) - Browser tab icon (large)
- **favicon.ico** (5.6KB) - Multi-resolution .ico file (16×16 + 32×32)

### Mobile & PWA Icons
- **apple-touch-icon.png** (16KB, 180×180px) - iOS home screen icon
- **android-chrome-192x192.png** (18KB) - Android home screen (standard)
- **android-chrome-512x512.png** (170KB) - Android home screen (high-res)

## Integration

### Theme Configuration
Logo reference added to `forseti.info.yml`:
```yaml
logo: images/logos/forseti_navbar.png
```

### HTML Head
Favicon links in `templates/system/html.html.twig`:
- Standard .ico favicon
- PNG favicons (16×16, 32×32)
- Apple touch icon (180×180)
- Android Chrome icons (192×192, 512×512)
- PWA manifest link

### PWA Manifest
`manifest.json` in theme root:
- App name: "Forseti - AI Community Safety"
- Theme color: #00d4ff (cyan)
- Background: #1a1a2e (dark)
- Icons: 192×192 and 512×512

## Usage Guidelines

### Website
- Navbar: Use forseti_navbar.png (200×50px)
- Footer: Can use original forseti_site.png at appropriate size
- Favicons: Automatically loaded via HTML head

### Mobile App
- iOS App Store: Use forseti_mobile.png (1024×1024)
- Android Play Store: Use forseti_mobile.png (1024×1024)
- In-app icon: Generate from forseti_mobile.png at needed sizes

### Color Palette
Logos designed to work with Forseti theme:
- Primary: #00d4ff (cyan)
- Dark BG: #1a1a2e
- Alt BG: #16213e
- Orange: #ff9800
- Red: #f44336

## File Sizes Summary
| File | Size | Dimensions | Purpose |
|------|------|------------|---------|
| forseti_navbar.png | 2.6KB | 200×50 | Website navbar |
| favicon.ico | 5.6KB | 16×16 + 32×32 | Browser icon |
| favicon-16x16.png | 751B | 16×16 | Tiny icon |
| favicon-32x32.png | 1.5KB | 32×32 | Standard icon |
| favicon-48x48.png | 2.5KB | 48×48 | Large icon |
| apple-touch-icon.png | 16KB | 180×180 | iOS home screen |
| android-chrome-192x192.png | 18KB | 192×192 | Android standard |
| android-chrome-512x512.png | 170KB | 512×512 | Android hi-res |

## Notes
- All originals preserved in `originals/` subdirectory
- All web assets optimized for fast loading
- PNG format used for transparency support
- Multi-resolution favicon.ico for broad browser support
- PWA-ready with manifest and icons

## Generation Commands
Images created using ImageMagick:
```bash
# Navbar logo
convert forseti_site.png -resize 200x50 -quality 90 forseti_navbar.png

# Favicons
convert forseti_site.png -resize 16x16 -quality 100 favicons/favicon-16x16.png
convert forseti_site.png -resize 32x32 -quality 100 favicons/favicon-32x32.png
convert forseti_site.png -resize 48x48 -quality 100 favicons/favicon-48x48.png
convert forseti_site.png -resize 180x180 -quality 95 favicons/apple-touch-icon.png
convert forseti_site.png -resize 192x192 -quality 95 favicons/android-chrome-192x192.png
convert forseti_site.png -resize 512x512 -quality 95 favicons/android-chrome-512x512.png

# Multi-resolution .ico
convert favicon-16x16.png favicon-32x32.png ../favicon.ico
```

---
*Last updated: December 10, 2025*
*Forseti Community Safety Platform*
