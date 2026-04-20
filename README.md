# Forseti.life - AI-Powered Community Safety Platform

**Last Updated:** February 6, 2026  
**Domain:** forseti.life  
**Created:** December 9, 2025  
**Platform:** Drupal 11.2+  
**Mission:** Building safer communities through AI-powered monitoring and intelligent safety systems

## Our Mission

**"AI Looking Out For Us"** - Forseti is a safety-focused community platform dedicated to maintaining and improving quality of life through intelligent monitoring, predictive analytics, and community engagement.

### Primary Focus
- **Physical Safety** in the Philadelphia metropolitan area
- **AI-Powered Monitoring** for real-time threat detection
- **Quality of Life** improvements for as many people as possible
- **Community Engagement** through transparent data and actionable insights

## About Forseti

Named after the Norse god of justice and peaceful resolution, Forseti represents our commitment to fair, intelligent, and proactive safety measures that protect communities while respecting individual rights.

### Core Values
- **Vigilance** - 24/7 AI monitoring for community safety
- **Transparency** - Open data and clear communication
- **Justice** - Fair and unbiased safety measures for all
- **Community** - Empowering residents with knowledge and tools
- **Prevention** - Proactive measures to stop issues before they escalate

### Technology Stack
- **AI Crime Mapping** - H3 geospatial analysis of incident data
- **Predictive Analytics** - Machine learning for pattern recognition
- **Real-time Alerts** - Immediate notifications for safety events
- **Mobile Access** - AmISafe mobile app for on-the-go safety
- **Data Visualization** - Interactive maps and dashboards

## Features

### 🛡️ Core Safety Features
1. **Live Crime Mapping**
   - Real-time incident tracking across Philadelphia
   - H3 hexagonal grid visualization for precise location data
   - Heat maps showing crime density and patterns
   - Historical trend analysis

2. **AI-Powered Alerts**
   - Intelligent pattern recognition for emerging threats
   - Predictive modeling for high-risk areas and times
   - Personalized safety notifications based on location
   - Community-sourced incident reporting

3. **Safety Dashboard**
   - Personal safety score for your neighborhood
   - Real-time safety status updates
   - Incident statistics and trends
   - Emergency resource locations (police, hospitals, shelters)

4. **Community Engagement**
   - Neighborhood watch coordination
   - Safety event calendar
   - Community forum for safety discussions
   - Resource sharing and mutual aid

### 📱 Mobile Integration
- **AmISafe Mobile App** - Native iOS and Android applications
- Location-based safety alerts
- One-touch emergency services
- Offline safety resources
- Community check-in features

### 🔐 Privacy & Security
- End-to-end encrypted communications
- Anonymous incident reporting options
- GDPR and privacy law compliant
- No sale or sharing of personal data
- Transparent data usage policies

## Development Testing (Resume Profile)

- `test-complete-profile-generation.php`: End-to-end resume upload → parse → consolidate workflow
- `verify-form-field-mapping.php`: Field-by-field form mapping verification and report generation

## Style Guide

### Brand Identity
**Forseti Life** - AI-powered community safety with a focus on vigilance, transparency, and justice.

### Color Palette

#### Primary Colors
```css
--primary-cyan: #00d4ff;        /* Forseti cyan - primary brand color */
--primary-cyan-dark: #0099cc;   /* Darker cyan for hover states */
--primary-cyan-light: #33e0ff;  /* Lighter cyan for highlights */
```

#### Background Colors
```css
--dark-bg: #1a1a2e;            /* Primary dark background */
--dark-bg-alt: #16213e;        /* Alternate dark background */
--dark-bg-light: #2a2a3e;      /* Lighter dark for cards/sections */
```

#### Neutral Colors
```css
--text-primary: #ffffff;        /* Primary text on dark backgrounds */
--text-secondary: #e0e0e0;      /* Secondary text */
--text-muted: #b0b0b0;         /* Muted/disabled text */
--border-color: rgba(0, 212, 255, 0.3);  /* Borders with cyan tint */
```

#### Status Colors
```css
--status-safe: #4caf50;        /* Safe/positive status */
--status-caution: #ff9800;     /* Warning/caution status */
--status-danger: #f44336;      /* Danger/alert status */
```

#### Maslow Hierarchy Dimension Colors
The 7 safety dimensions based on Maslow's Hierarchy of Needs, each with a distinct color as shown in their icons:

```css
/* Dimension Icon Colors - Canonical brand colors from icon files */
--dimension-safe: #3fe5e1;        /* SAFE - Cyan/Turquoise rgb(63, 229, 225) */
--dimension-energized: #ffa500;   /* ENERGIZED - Orange rgb(255, 165, 0) */
--dimension-connected: #28a745;   /* CONNECTED - Green rgb(40, 167, 69) */
--dimension-free: #17a2b8;        /* FREE - Cyan/Teal rgb(23, 162, 184) */
--dimension-capable: #6f42c1;     /* CAPABLE - Purple rgb(111, 66, 193) */
--dimension-useful: #e83e8c;      /* USEFUL - Pink/Magenta rgb(232, 62, 140) */
--dimension-whole: #ffc107;       /* WHOLE - Yellow/Gold rgb(255, 193, 7) */
```

**Dimension Icon Files** (407×462px PNG, RGBA):
```
/themes/custom/forseti/images/logos/originals/
├── forseti_safe.png        # SAFE dimension icon - Cyan/Turquoise
├── forseti_energized.png   # ENERGIZED dimension icon - Orange
├── forseti_connected.png   # CONNECTED dimension icon - Green
├── forseti_free.png        # FREE dimension icon - Cyan/Teal
├── forseti_capable.png     # CAPABLE dimension icon - Purple
├── forseti_useful.png      # USEFUL dimension icon - Pink/Magenta
├── forseti_whole.png       # WHOLE dimension icon - Yellow/Gold
└── forseti_demographic.png # DEMOGRAPHIC dimension icon - Main site logo
```

**Usage**: These colors and icons appear in:
- `/safety-factors` page dimension icons
- Population benchmarks pyramid chart (`/population-benchmarks`)
- Individual assessment visualizations
- Dimension score badges and indicators
- Questionnaire progress screens

**Icon Implementation**:
- Service: `SafetyDimensionsService.php` provides icon paths
- Template: `forseti-page-safety-factors.html.twig` displays icons
- Controllers: `QuestionnaireController.php`, `PopulationBenchmarksController.php`

**Color Analysis**: 
- Colors extracted from actual icon files using PIL image analysis
- Each icon has a dominant color (29,355 pixels) with cyan accents (~1,389 pixels)
- The cyan accent color (#3fe5e1) appears in all icons as a secondary element

**Visualization Colors**: Note that the pyramid chart visualization may use different colors optimized for data visualization contrast and hierarchical representation. The colors listed above are the canonical brand colors from the actual icon files.

### Typography

#### Font Stack
```css
font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, 
             "Helvetica Neue", Arial, sans-serif;
```

#### Headings
- **H1**: 2.5rem (40px), font-weight: 700, letter-spacing: -0.02em
- **H2**: 2rem (32px), font-weight: 600, letter-spacing: -0.01em
- **H3**: 1.5rem (24px), font-weight: 600
- **H4**: 1.25rem (20px), font-weight: 600
- **H5**: 1rem (16px), font-weight: 600
- **H6**: 0.875rem (14px), font-weight: 600, text-transform: uppercase

#### Body Text
- **Base**: 1rem (16px), line-height: 1.6
- **Small**: 0.875rem (14px), line-height: 1.5
- **Large**: 1.125rem (18px), line-height: 1.7

### Design Patterns

#### Gradients
```css
/* Hero/Header gradient - dark to cyan */
background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #00d4ff 100%);

/* Card gradient - subtle dark variation */
background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);

/* Overlay gradient - for depth */
background: radial-gradient(circle at 20% 50%, rgba(0, 212, 255, 0.1) 0%, transparent 50%);
```

#### Shadows
```css
/* Subtle shadow */
box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);

/* Card shadow */
box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);

/* Elevated shadow */
box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);

/* Cyan glow effect */
box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3);

/* Hover glow */
box-shadow: 0 6px 20px rgba(0, 212, 255, 0.4);
```

#### Border Radius
```css
--radius-sm: 0.25rem (4px);    /* Small elements */
--radius-md: 0.5rem (8px);     /* Buttons, inputs */
--radius-lg: 1rem (16px);      /* Cards, panels */
--radius-xl: 1.5rem (24px);    /* Hero sections */
--radius-full: 50%;            /* Circular elements */
```

### UI Components

#### Buttons
```css
/* Primary Button */
.btn-primary {
  background: linear-gradient(45deg, #00d4ff, #0099cc);
  color: #ffffff;
  padding: 0.75rem 2rem;
  border-radius: 0.5rem;
  border: none;
  font-weight: 600;
  transition: all 0.3s ease;
  box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3);
}

.btn-primary:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0, 212, 255, 0.4);
}

/* Secondary Button */
.btn-secondary {
  background: transparent;
  color: #00d4ff;
  border: 2px solid #00d4ff;
  padding: 0.75rem 2rem;
  border-radius: 0.5rem;
  font-weight: 600;
  transition: all 0.3s ease;
}

.btn-secondary:hover {
  background: rgba(0, 212, 255, 0.1);
  transform: translateY(-2px);
}
```

#### Cards
```css
.card {
  background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
  border: 1px solid rgba(0, 212, 255, 0.2);
  border-radius: 1rem;
  padding: 2rem;
  transition: all 0.3s ease;
}

.card:hover {
  border-color: rgba(0, 212, 255, 0.4);
  transform: translateY(-4px);
  box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
}
```

#### Status Badges
```css
.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: 2rem;
  font-size: 0.9rem;
  font-weight: 600;
}

.status-safe {
  background: rgba(76, 175, 80, 0.1);
  color: #4caf50;
  border: 1px solid rgba(76, 175, 80, 0.3);
}

.status-caution {
  background: rgba(255, 152, 0, 0.1);
  color: #ff9800;
  border: 1px solid rgba(255, 152, 0, 0.3);
}

.status-danger {
  background: rgba(244, 67, 54, 0.1);
  color: #f44336;
  border: 1px solid rgba(244, 67, 54, 0.3);
}
```

### H3 Hexagonal Theme

Forseti uses H3 geospatial hexagonal grid system throughout the design:

- **Particle Animations**: Hexagons instead of circles in animated backgrounds
- **Icons**: Hexagonal icon frames and borders
- **Data Visualization**: Crime maps using H3 hexagonal tiles
- **UI Elements**: Hexagonal accents in cards and status indicators

#### Hexagon CSS
```css
.hexagon {
  clip-path: polygon(
    50% 0%, 
    100% 25%, 
    100% 75%, 
    50% 100%, 
    0% 75%, 
    0% 25%
  );
}
```

### Animation Guidelines

#### Transitions
```css
/* Standard transition */
transition: all 0.3s ease;

/* Smooth hover effect */
transition: transform 0.3s ease, box-shadow 0.3s ease;

/* Staggered animations */
animation-delay: calc(var(--index) * 0.1s);
```

#### Pulse Effect (for status indicators)
```css
@keyframes pulse {
  0%, 100% {
    opacity: 1;
    transform: scale(1);
  }
  50% {
    opacity: 0.8;
    transform: scale(1.05);
  }
}
```

### Accessibility Guidelines

- **Contrast Ratio**: Minimum 4.5:1 for normal text, 3:1 for large text
- **Focus States**: Visible focus indicators with cyan outline
- **Keyboard Navigation**: All interactive elements accessible via keyboard
- **Screen Readers**: Proper ARIA labels and semantic HTML
- **Color Independence**: Don't rely solely on color to convey information

### Responsive Breakpoints

```css
/* Mobile first approach */
--breakpoint-sm: 576px;   /* Small devices (phones) */
--breakpoint-md: 768px;   /* Medium devices (tablets) */
--breakpoint-lg: 992px;   /* Large devices (desktops) */
--breakpoint-xl: 1200px;  /* Extra large devices */
--breakpoint-xxl: 1400px; /* Extra extra large devices */
```

### Icon System

- **Primary Library**: Font Awesome 6 or Bootstrap Icons
- **Custom Icons**: SVG format, optimized for web
- **Icon Colors**: Use cyan (#00d4ff) for primary icons
- **Icon Sizes**: 16px, 24px, 32px, 48px, 64px

### Implementation Files

- **Main CSS**: `/modules/custom/forseti_safety_content/css/forseti-pages.css`
- **Theme CSS**: `/themes/custom/forseti/build/css/main.style.css`
- **Animated Header**: `/themes/custom/forseti/src/js/animated-header.js`
- **SCSS Variables**: `/themes/custom/forseti/src/scss/base/_variables.scss`

---

## Technical Details

### Platform
- **CMS:** Drupal 11
- **Server:** Apache 2.4+ with mod_rewrite
- **Database:** MySQL 8.0+ / MariaDB 10.5+
- **PHP:** 8.1+ (8.3 recommended)
- **SSL:** Required (Let's Encrypt recommended)

### AI/ML Stack
- **H3 Geospatial System** - Uber's H3 hexagonal hierarchical geospatial indexing
- **Python Analytics Engine** - pandas, numpy, scikit-learn
- **Data Pipeline** - ETL processing for crime data
- **Visualization** - Folium, Plotly, D3.js

### APIs & Integrations
- Philadelphia Police Department Open Data
- Emergency Services Integration
- Weather and Environmental Data
- Mobile Push Notifications

## Installation & Setup

### Quick Start (Development)

```bash
# Navigate to project root
cd /home/keithaumiller/stlouisintegration.com

# Run automated setup script (includes Forseti)
sudo bash script/setup.sh
```

The setup script automatically:
- Creates forseti_dev database
- Configures Apache on port 8080
- Installs Drupal 11.2.5
- Enables forseti theme
- Sets up private files directory
- Configures development settings

### Manual Setup

1. **Create Database**
```bash
mysql -u root -p
CREATE DATABASE forseti_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON forseti_dev.* TO 'drupal_user'@'127.0.0.1';
FLUSH PRIVILEGES;
```

2. **Configure Apache** (Port 8080)
```bash
sudo nano /etc/apache2/sites-available/forseti.conf
sudo a2ensite forseti.conf
sudo systemctl restart apache2
```

3. **Install Drupal**
```bash
cd /home/keithaumiller/stlouisintegration.com/sites/forseti
./vendor/bin/drush site:install standard \
  --db-url="mysql://drupal_user:password@127.0.0.1:3306/forseti_dev" \
  --site-name="Forseti" \
  --account-name="admin" \
  --yes
```

4. **Enable Theme**
```bash
./vendor/bin/drush theme:enable forseti -y
./vendor/bin/drush config:set system.theme default forseti -y
./vendor/bin/drush cache:rebuild
```

### Access URLs

- **Development:** http://localhost:8080 or http://100.115.92.203:8080
- **Alternative:** http://penguin.linux.test:8080 (Chrome OS Linux)
- **Production:** https://forseti.life (when configured)

### Build Theme Assets

```bash
cd /home/keithaumiller/stlouisintegration.com/sites/forseti/web/themes/custom/forseti
npm install
npm run build
```

## Site Structure

```
forseti/
├── composer.json              # PHP dependencies
├── web/                       # Drupal webroot
│   ├── sites/
│   │   └── default/
│   │       ├── settings.php
│   │       ├── settings.local.php
│   │       └── files/
│   │           └── amisafe/   # AmISafe mobile app files
│   ├── modules/
│   │   └── custom/            # Safety modules
│   ├── themes/
│   │   └── custom/
│   │       └── forseti/       # Safety community theme
│   └── core/                  # Drupal core
├── config/                    # Configuration management
│   └── sync/                  # Exported configs
└── vendor/                    # Composer dependencies
```

## Development Commands

```bash
# Navigate to Forseti site
cd /home/keithaumiller/stlouisintegration.com/sites/forseti

# Clear cache
./vendor/bin/drush cache:rebuild

# One-time login link
./vendor/bin/drush user:login

# Export configuration
./vendor/bin/drush config:export -y

# Import configuration
./vendor/bin/drush config:import -y

# Check site status
./vendor/bin/drush status
```

## Production Deployment

### DNS Configuration
```
A     forseti.life          -> [PRODUCTION_IP]
CNAME www.forseti.life      -> forseti.life
```

### SSL Certificate
```bash
sudo certbot --apache -d forseti.life -d www.forseti.life
```

### Apache Virtual Host (Production)
```apache
<VirtualHost *:443>
    ServerName forseti.life
    ServerAlias www.forseti.life
    DocumentRoot /var/www/forseti.life/web
    
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/forseti.life/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/forseti.life/privkey.pem
    
    <Directory /var/www/forseti.life/web>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Security Checklist

- [ ] Remove settings.local.php in production
- [ ] Set restrictive file permissions (644 for files, 755 for directories)
- [ ] Enable SSL/TLS with valid certificate
- [ ] Configure firewall rules
- [ ] Set up automated backups
- [ ] Enable security updates
- [ ] Configure fail2ban for brute force protection
- [ ] Set up monitoring and alerting

## Related Sites

- **stlouisintegration.com** (Port 80) - Main portfolio site
- **forseti.life** (Port 8080) - Safety community platform (this site)
- **theoryofconspiracies.com** (Port 8081) - AI/GenAI demonstration site

## Contact

Keith Aumiller  
Email: support@forseti.life  
Web: https://forseti.life

---

**Mission:** AI Looking Out For Us - Building safer communities through intelligent monitoring and community engagement.
