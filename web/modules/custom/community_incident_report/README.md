# Community Incident Report

**Drupal Version:** 10, 11 | **License:** GPL-3.0-only | **Status:** Stable

## Overview

The Community Incident Report module provides a decentralized, community-managed safety observation system for forseti.life. It enables community members to submit structured incident reports, view community observations, and provides administrators with moderation tools to ensure report quality and accuracy.

This module implements a transparent, democratic approach to safety reporting where the community collectively maintains a shared database of observations. The module includes GeoJSON API support for mapping incident locations, making it easy to visualize safety patterns across regions.

## Features

- **Submit Safety Reports** - Community members can submit structured incident reports through an accessible form
- **Community Viewing** - Browse and search all published community incident reports
- **Admin Moderation** - Administrators can review, publish, unpublish, and manage submitted reports
- **Incident Taxonomy** - Categorize incidents using a flexible taxonomy system
- **Geolocation Support** - Include location information with incident reports
- **GeoJSON API** - Export incident data as GeoJSON for mapping applications
- **Image Attachments** - Attach evidence photos or documentation to incident reports
- **Timestamp Tracking** - Automatic recording of report submission and update times
- **Permission Control** - Granular permissions for submission and viewing
- **Responsive Forms** - Mobile-friendly incident report submission

## Installation

### Prerequisites

- Drupal 10.0+ or Drupal 11.0+
- PHP 8.1+
- MySQL/MariaDB or PostgreSQL
- The following Drupal core modules must be enabled: Node, Field, Text, DateTime, Image, Taxonomy, User, System, Views

### Steps

```bash
# Navigate to Drupal root
cd /path/to/drupal/root

# Enable the Community Incident Report module
drush en community_incident_report -y

# Run database updates (if any)
drush updatedb -y

# Clear caches
drush cache:rebuild
```

## Configuration

### Initial Setup

1. **Enable the Module**
   - Go to Administration → Extend
   - Search for "Community Incident Report"
   - Check the box and click "Install"

2. **Configure Incident Taxonomy**
   - Navigate to Administration → Structure → Taxonomy
   - Add terms for incident categories (e.g., "Safety Concern", "Equipment Issue", "Policy Violation")
   - Assign appropriate term colors or descriptions

3. **Set Up Fields**
   - Visit Administration → Structure → Content Types → Community Incident
   - Review included fields: Title, Description, Location, Incident Category, Attachment, Date/Time
   - Customize field labels and help text as needed

4. **Configure Permissions**
   - Go to Administration → People → Permissions
   - Assign "Submit community incident reports" permission to community members
   - Assign "View community incident reports" permission to appropriate roles
   - Admin roles automatically receive all permissions

5. **Create Viewing Pages**
   - A default "Community Reports" page is provided at `/community-reports`
   - Customize the page display via Views UI if needed

## Usage

### Submitting an Incident Report

Community members access the report form at `/community/report`:

```
Title:          Brief incident summary (max 255 characters)
Description:    Detailed incident information, context, and observations
Location:       Geographic location or address where incident occurred
Incident Type:  Select from available taxonomy categories
Attachment:     (Optional) Upload supporting image or document
Date/Time:      When the incident occurred (auto-filled with current time if blank)
```

**Example Submission:**
- **Title:** "Unsafe working condition in warehouse area B"
- **Description:** "Storage rack in corner appears unstable, items slightly loose"
- **Location:** "Building 3, Warehouse Area B"
- **Type:** "Safety Concern"
- **Attachment:** Photo of the rack

### Viewing Community Reports

The community can view all published reports at `/community-reports`:

- Browse all submitted and published reports
- Filter by incident type using taxonomy filters
- Search reports by keywords
- View report details, location, and attachments
- No account required for viewing (based on permission configuration)

### Admin Moderation

Administrators access the moderation interface at `/admin/content/community-reports`:

```bash
# View all reports (published and unpublished)
# Use the publish/unpublish toggle to control visibility
# Edit report details or add moderation notes
# Delete reports if necessary (with appropriate caution)
```

**Moderation Workflow:**
1. New reports appear as "unpublished" or pending review
2. Admin reviews content for accuracy and appropriateness
3. Click "Publish" to make visible to the community
4. Click "Unpublish" to remove from public view (retained in archive)
5. Edit reports to add clarifications or corrections if needed

### Using the GeoJSON API

The module provides a GeoJSON endpoint at `/api/community-incidents/geojson`:

```bash
# Retrieve all published incidents as GeoJSON
curl https://yoursite.com/api/community-incidents/geojson

# Returns format suitable for mapping libraries (Leaflet, Mapbox, etc.)
```

**Response Example:**
```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": {
        "type": "Point",
        "coordinates": [-73.9857, 40.7484]
      },
      "properties": {
        "title": "Unsafe working condition",
        "description": "Storage rack appears unstable",
        "incident_type": "Safety Concern",
        "submitted_date": "2024-01-15",
        "url": "/community-incidents/123"
      }
    }
  ]
}
```

**Mapping Example (using Leaflet):**
```javascript
fetch('/api/community-incidents/geojson')
  .then(response => response.json())
  .then(data => {
    L.geoJSON(data, {
      onEachFeature: function(feature, layer) {
        layer.bindPopup(feature.properties.title);
      }
    }).addTo(map);
  });
```

## Dependencies

Required (automatically enabled with this module):
- `drupal:node` - Content entity framework
- `drupal:field` - Field system and field types
- `drupal:text` - Text field type
- `drupal:datetime` - Date/time field type
- `drupal:image` - Image field type and handling
- `drupal:taxonomy` - Categorization system
- `drupal:user` - User authentication and permissions
- `drupal:system` - Core system functionality
- `drupal:views` - Content display and filtering

## API Documentation

### Hooks

The module implements standard Drupal node hooks. Custom modules can interact via:

```php
// React to incident report creation/updates
function mymodule_node_insert(Drupal\node\NodeInterface $node) {
  if ($node->bundle() === 'community_incident') {
    // Custom logic for new reports
  }
}

function mymodule_node_update(Drupal\node\NodeInterface $node) {
  if ($node->bundle() === 'community_incident') {
    // Custom logic for updated reports
  }
}
```

### GeoJSON Endpoint

- **URL:** `/api/community-incidents/geojson`
- **Method:** GET
- **Authentication:** Public (respects published status)
- **Response:** GeoJSON FeatureCollection
- **Format:** JSON

### Event Hooks (if listeners implemented)

```php
// Hook into report submission
function mymodule_community_incident_report_submitted($incident) {
  // Handle new submission
}

function mymodule_community_incident_report_published($incident) {
  // Handle publication
}
```

## Development

### Local Setup

```bash
# Enable Devel module for debugging
drush en devel -y

# View database queries
drush ev "kint(\Drupal::database()->getQueryCount())"

# Debug reports programmatically
drush php
> $incidents = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties(['type' => 'community_incident'])
```

### Extending the Module

To create custom functionality:

```php
// Custom service example
namespace Drupal\mymodule\Services;

class IncidentAnalyzer {
  public function getIncidentsByType($type) {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'community_incident')
      ->condition('field_incident_type', $type);
    return $query->execute();
  }
}
```

### Testing

Module functionality can be tested via:
- Manual testing through web interface
- Drush commands for bulk operations
- Automated tests (if test suite exists)

## Contributing

We welcome contributions from the community! To contribute:

1. Fork the repository on GitHub
2. Create a feature branch for your work
3. Make focused, well-documented changes
4. Test thoroughly before submitting
5. Submit a pull request with clear description of changes

For detailed contribution guidelines, see [CONTRIBUTING.md](../../CONTRIBUTING.md) in the repository root.

## License

This module is licensed under the GNU General Public License v3.0 (GPL-3.0-only).

```
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, version 3.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
```

## Support

### Getting Help

- **Issues:** Report bugs and request features on GitHub Issues
- **Documentation:** See the [Drupal documentation](https://www.drupal.org/docs)
- **Community:** Ask questions in Drupal forums or community channels

### Reporting Issues

When reporting issues, please include:
- Drupal version and PHP version
- Steps to reproduce
- Expected behavior vs. actual behavior
- Any error messages from logs (Administration → Reports → Recent Log Messages)

## Security

### Data Handling

The module stores incident reports in the Drupal node system:
- Reports can contain location information that may be sensitive
- Images attached to reports are stored in the files system
- Access is controlled via Drupal permissions

### Privacy Considerations

- Administrator review recommended before publishing incident reports
- Consider configurable anonymization for sensitive incidents
- Location data accuracy may be modified or obfuscated for privacy
- Respect user privacy in moderation decisions

### Recommendations

- Regularly review and moderate incident reports
- Keep Drupal and all modules updated
- Use HTTPS to encrypt data in transit
- Implement regular backups
- Monitor access logs for suspicious activity

## Maintenance

**Last Updated:** January 2024

**Maintained By:** Forseti Community

**Compatibility:**
- Drupal 10.0+ ✓
- Drupal 11.0+ ✓
- PHP 8.1+ ✓
- PHP 8.2+ ✓
- PHP 8.3+ ✓

**Support Timeline:**
- Security updates: Provided for stable releases
- Bug fixes: Best effort for reported issues
- Feature requests: Reviewed by community

---

**Questions?** Check the GitHub repository or Drupal community resources.
