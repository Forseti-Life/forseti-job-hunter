# Users Metrics

Provides statistics for user registrations and logins with D3.js charts.

## Requirements

- Drupal 10.3+ or 11
- Views module (core)
- User module (core)
- Database Logging module (core) - **Required for login statistics**

## Installation

Install as you would normally install a contributed Drupal module.
See [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-modules) for further information.

## Configuration

1. Navigate to Administration > Configuration > People > Users Metrics Settings
2. Configure excluded roles and user IDs from statistics

## Usage

After installation, navigate to Administration > Reports > Users Metrics to view:

- **User Registrations**: Statistics on new user registrations over time
- **User Logins**: Login activity statistics (requires Database Logging module)
- Interactive D3.js charts with time-based filtering

### Login Statistics

Login statistics are tracked through Drupal's Database Logging (dblog) module. If dblog
is not enabled, the login statistics page will not be accessible. You can check the
status at Administration > Reports > Status.

## Permissions

- **Access users metrics**: View the users metrics reports
- **Administer users metrics**: Configure module settings

## Maintainers

- [Your Name](https://www.drupal.org/u/your-username)
