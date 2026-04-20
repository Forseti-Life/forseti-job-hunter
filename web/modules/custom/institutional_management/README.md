# Institutional Management Module

**Last Updated:** February 6, 2026  
**Version**: 1.0  
**Drupal**: 10.3+ / 11  
**Dependencies**: Group module

## Overview

The Institutional Management module provides enterprise-level features for managing organizational accounts within the Forseti safety platform. It integrates with the Drupal Group module to provide multi-facility management, employee safety monitoring, and compliance reporting.

## Features

### Core Functionality
- **Institutional Groups**: Create and manage organizational structures using Drupal Groups
- **Member Management**: Add, remove, and manage employee accounts with granular permissions
- **Multi-Facility Support**: Monitor multiple locations and facilities from a central dashboard
- **Compliance Reporting**: Generate comprehensive safety and compliance reports
- **API Access**: Enterprise API endpoints for system integration

## Feature Roadmap & Status

### ✅ Implemented Features

#### Location Tracking & Sharing
- **Location History API** (`/api/amisafe/location/update`, `/api/amisafe/location/history`)
  - Users can track their movements via mobile app
  - Location data stored in `user_location_tracking` table with H3 spatial indexing
  - Users can enable/disable location sharing in mobile app settings
  - GPS metadata: accuracy, altitude, heading, speed
  - Device info stored as JSON for debugging
  
#### Group Mapping
- **Group Location Map** (`/group/{group}/map`)
  - View real-time member locations on interactive map
  - Filter by time range
  - H3 hexagon grid visualization
  - Integrated with amisafe location tracking API

### 🚧 In Development

#### Admin Invite System
**Status**: Planning Phase  
**Priority**: High

**Requirements**:
- [ ] Email/SMS invitation system for non-members
  - [ ] Email template for institutional invites
  - [ ] SMS integration (Twilio/similar service)
  - [ ] Invitation tracking table (sent, pending, accepted, expired)
  - [ ] Configurable invitation expiry (default: 7 days)
  
- [ ] Automatic account creation workflow
  - [ ] Create disabled user account on invitation send
  - [ ] Generate secure one-time activation token
  - [ ] Account activation page with password reset
  - [ ] Email verification requirement
  - [ ] Assign invitee to institutional group upon activation
  
- [ ] Admin invite management dashboard
  - [ ] View all sent invitations
  - [ ] Resend expired invitations
  - [ ] Revoke pending invitations
  - [ ] Track invitation acceptance rates

**Technical Notes**:
- Use Drupal's email API for sending
- Store invitation tokens in custom table: `institutional_invitations`
- Integration with Group module role assignment
- Consider using Symfony Mailer for better email templating

#### Geofencing & Boundary Management
**Status**: Planning Phase  
**Priority**: High

**Requirements**:
- [ ] Interactive map-based geofence editor
  - [ ] Drag-and-draw polygon boundaries on safety map
  - [ ] Multiple geofence zones per member (home, work, school, etc.)
  - [ ] Named zones with color coding
  - [ ] Edit/delete existing boundaries
  
- [ ] Geofence storage & validation
  - [ ] Store polygon coordinates in database
  - [ ] Associate geofences with group members
  - [ ] Real-time location validation against boundaries
  - [ ] Efficient spatial queries using H3 or PostGIS
  
- [ ] Boundary templates
  - [ ] Pre-defined safe zones (school districts, neighborhoods)
  - [ ] Import boundaries from addresses (geocoding)
  - [ ] Share boundaries across family members

**Technical Notes**:
- Use Leaflet.draw or similar library for boundary editing
- Store as GeoJSON or polygon coordinates
- Consider PostGIS extension for spatial queries
- H3 hexagon containment checks for performance
- Need new table: `institutional_geofences` (id, group_id, member_uid, name, boundary_data, active)

#### Alert System & Safety Monitoring
**Status**: Planning Phase  
**Priority**: High

**Requirements**:
- [ ] Configurable alert triggers
  - [ ] Geofence exit/entry alerts (member leaves/enters safe zone)
  - [ ] Low safety score alerts (location safety below threshold)
  - [ ] Inactivity alerts (no location update for X hours)
  - [ ] Emergency button activation
  - [ ] Custom alert rules per member
  
- [ ] Alert level configuration
  - [ ] Info: Log only, no notification
  - [ ] Warning: Email notification to admins
  - [ ] Critical: Email + SMS to all administrators
  - [ ] Emergency: Immediate multi-channel notification
  
- [ ] Safety score thresholds
  - [ ] Set minimum acceptable safety score (1-100)
  - [ ] Different thresholds for different times (day/night)
  - [ ] Location-specific thresholds
  - [ ] Alert when member enters area below threshold
  
- [ ] Alert log & history
  - [ ] View all alerts for institution/family
  - [ ] Filter by member, type, date range, severity
  - [ ] Mark alerts as reviewed/resolved
  - [ ] Export alert reports (CSV/PDF)
  - [ ] Alert statistics and trends
  
- [ ] Notification delivery
  - [ ] Multi-channel: email, SMS, push notification
  - [ ] Configurable per admin (notification preferences)
  - [ ] Digest mode: summarize multiple alerts
  - [ ] Quiet hours configuration

**Technical Notes**:
- New table: `institutional_alerts` (id, group_id, member_uid, alert_type, severity, message, location_data, created, reviewed, resolved)
- New table: `institutional_alert_rules` (id, group_id, member_uid, rule_type, config, active)
- Cron job for periodic safety score checks
- Queue system for alert processing (Drupal Queue API)
- Integration with safety_calculator module for score thresholds
- Consider using Symfony Notifier for multi-channel notifications

### 📋 Planned Features

#### Advanced Member Management
- [ ] Bulk member import (CSV)
- [ ] Member groups/departments within institution
- [ ] Role-based access control (view-only, admin, super-admin)
- [ ] Member activity timeline
- [ ] Emergency contact management

#### Reporting & Analytics
- [ ] Member location heatmaps
- [ ] Safety score trends over time
- [ ] Alert frequency reports
- [ ] Compliance audit logs
- [ ] Custom report builder
- [ ] Scheduled report delivery

#### Mobile App Integration
- [ ] Push notification support for alerts
- [ ] In-app emergency button
- [ ] Quick check-in feature
- [ ] SOS with automatic location sharing
- [ ] Offline mode with sync

### Pages & Routes
- `/institutional` - Landing page with feature overview
- `/institutional/dashboard` - Main institutional dashboard (requires permission)
- `/admin/config/forseti/institutional` - Module settings (admin only)

### Permissions
- `access institutional dashboard` - View institutional dashboard
- `manage institution` - Create and manage institutional groups
- `manage institution members` - Add/remove members and assign roles
- `view institution reports` - Access compliance reports and analytics
- `administer institutional management` - Full administrative access (restricted)

## Installation

1. Enable the Group module and its dependencies:
   ```bash
   drush en group -y
   ```

2. Enable the Institutional Management module:
   ```bash
   drush en institutional_management -y
   ```

3. Clear cache:
   ```bash
   drush cr
   ```

4. Configure settings at `/admin/config/forseti/institutional`

## Configuration

### General Settings
- **Enable API access**: Allow institutions to access data via API
- **Maximum members per institution**: Set member limits per organization

### Compliance & Reporting
- **Enable compliance reporting**: Generate and provide compliance reports

## Usage

### Creating an Institution
1. Navigate to the institutional dashboard
2. Click "Create Institution"
3. Fill in organization details
4. Set up initial administrators

### Managing Members
1. Access the institution dashboard
2. Use "Add Member" to invite employees
3. Assign appropriate roles and permissions
4. Monitor member activity and assessments

### Viewing Reports
1. Navigate to institutional dashboard
2. Click "View Reports"
3. Select report type (compliance, safety, analytics)
4. Export or schedule automated reports

## Integration with Safety Calculator

This module integrates with the `safety_calculator` module to provide:
- Institutional safety assessments
- Employee safety monitoring
- Bulk location analysis
- Organizational safety benchmarks

## Development

### File Structure
```
institutional_management/
├── src/
│   ├── Controller/
│   │   └── InstitutionalController.php
│   └── Form/
│       └── InstitutionalSettingsForm.php
├── templates/
│   └── institutional-dashboard.html.twig
├── css/
│   └── institutional-dashboard.css
├── js/
│   └── institutional-dashboard.js
├── institutional_management.info.yml
├── institutional_management.module
├── institutional_management.routing.yml
├── institutional_management.permissions.yml
├── institutional_management.libraries.yml
└── README.md
```

### Extending Functionality

To add new institutional features:
1. Create controllers in `src/Controller/`
2. Add routes in `institutional_management.routing.yml`
3. Create templates in `templates/`
4. Add permissions in `institutional_management.permissions.yml`

## Support

For issues or feature requests, contact the Forseti development team.

## License

Proprietary - Forseti.life Platform
