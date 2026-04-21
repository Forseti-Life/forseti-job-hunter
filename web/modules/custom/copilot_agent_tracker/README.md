# Copilot Agent Tracker

**Admin reporting dashboard for tracking Copilot agent status, work item progress, and event streams with LangGraph visualization and local LLM support.**

## Badges

[![License: GPL-3.0](https://img.shields.io/badge/License-GPL%203.0-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
![Drupal Version](https://img.shields.io/badge/Drupal-11-blue)
![Status: Stable](https://img.shields.io/badge/Status-Stable-brightgreen)

## Overview

The Copilot Agent Tracker module provides comprehensive admin reporting and monitoring for Copilot agents (GitHub Copilot CLI agents, LangGraph orchestrators, and other automated work execution engines). It collects sanitized telemetry events (no raw chat transcripts or sensitive data), tracks agent status and work item progress, displays real-time dashboards, and integrates with LangGraph for workflow visualization. Perfect for operations teams monitoring multiple agents across distributed systems, tracking work item throughput, and debugging agent behavior in production environments.

## Features

### 📊 LangGraph Dashboard Integration
- **Workflow Visualization**: Real-time LangGraph diagram showing agent state transitions
- **Agent Status Display**: Current state, last heartbeat, active task information
- **Event Timeline**: Chronological event stream with filtering and search
- **State History**: Track state changes with timestamps and context
- **Performance Metrics**: Throughput, latency, success rates by agent

### 🤖 Multi-Agent Monitoring
- **Agent Registry**: Centralized list of all active and historical agents
- **Agent Detail Pages**: Per-agent dashboards with full telemetry and performance stats
- **Status Indicators**: Real-time status (running, idle, error, completed)
- **Heartbeat Monitoring**: Track agent availability and responsiveness
- **Work Item Tracking**: Monitor assigned work, completed tasks, and failures

### 🛠️ Local LLM Management
- **Model Selection**: Choose from installed local LLM models
- **Prompt Testing**: Test prompts against selected model before deployment
- **Model Configuration**: Set parameters (temperature, context size, etc.)
- **Performance Monitoring**: Track model performance and inference times
- **Fallback Management**: Configure fallback models for redundancy

### 🔐 Telemetry API
- **Secure Event Endpoint**: `POST /api/copilot-agent-tracker/event` with token authentication
- **Token-Based Auth**: Auto-generated tokens stored in Drupal state
- **Event Sanitization**: Remove sensitive data (credentials, full chat transcripts)
- **Batch Submissions**: Accept multiple events in single request
- **Rate Limiting**: Protect against telemetry floods

### 📈 Data Models & Storage
- **Agent Registry**: `copilot_agent_tracker_agents` table
  - Agent ID, name, type, status, configuration
  - Last seen timestamp, performance metrics
  - Upsert logic for idempotent updates
  
- **Event Stream**: `copilot_agent_tracker_events` table (append-only)
  - Event type, agent ID, timestamp, context
  - Structured JSON payloads
  - Retention policies and archival

### 📋 Reporting & Analytics
- **Agent Performance Reports**: Success rates, task completion, error analysis
- **Work Item Analytics**: Throughput, latency, bottlenecks
- **Event Filtering**: By agent, type, date range, status
- **Export Capabilities**: CSV, JSON exports for external analysis
- **Trend Analysis**: Historical performance trends and predictions

## Installation

### Prerequisites
- Drupal 11 (core_version_requirement: ^11)
- Database support for custom tables (MySQL/PostgreSQL)
- Admin access to configure telemetry

### Installation Steps

```bash
# 1. Place module in custom modules directory
# Already located at: web/modules/custom/copilot_agent_tracker/

# 2. Enable the copilot_agent_tracker module
drush en copilot_agent_tracker -y

# 3. Install database schema
drush updatedb -y

# 4. Clear cache
drush cache:rebuild

# 5. Verify installation and telemetry token
drush php:eval "echo 'Token: ' . \Drupal::state()->get('copilot_agent_tracker.token', 'NOT SET');"
```

### Verify Installation

```bash
# 1. Confirm module is enabled
drush pm:list --type=module --status=enabled | grep copilot_agent_tracker

# 2. Confirm dashboard route responds
curl -sI http://localhost/admin/reports/copilot-agent-tracker | head -2
# Expected: HTTP/2 302 (redirect to login) or 200 (if already authenticated)

# 3. Confirm telemetry endpoint exists and requires auth
curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost/api/copilot-agent-tracker/event
# Expected: 403 (forbidden without token), NOT 404

# 4. Check database tables created
drush sql:query "SHOW TABLES LIKE '%copilot_agent_tracker%';"
# Expected: Two tables - copilot_agent_tracker_agents, copilot_agent_tracker_events
```

## Configuration

### Module Settings

**Navigate to:** `admin/config/copilot-agent-tracker`

#### Telemetry Settings
- **Enable Telemetry**: Accept incoming events from agents (default: TRUE)
- **Event Retention Days**: How long to keep events (default: 90 days)
- **Max Events Per Batch**: Limit events per API request (default: 100)
- **Event Sanitization**: Remove sensitive fields from events (default: TRUE)

#### Dashboard Settings
- **Dashboard Refresh Rate**: Auto-refresh interval in seconds (default: 30)
- **Max Agents Displayed**: Limit agent list pagination (default: 50)
- **Chart History**: Days of historical data to show (default: 30)
- **Status Update Timeout**: Mark agent offline after N seconds (default: 300)

#### LLM Configuration
- **LLM Provider**: Local model or external API
- **Model Path**: Path to local LLM (if using local models)
- **API Endpoint**: External LLM endpoint URL (if using remote)
- **Model Parameters**: Temperature (0-1), max_tokens, etc.
- **Fallback Model**: Secondary model for redundancy

#### Performance & Monitoring
- **Enable Debug Logging**: Detailed telemetry logging (disable in production)
- **Performance Monitoring**: Track API latency and throughput
- **Alert Threshold**: Alert when error rate exceeds % (default: 10%)
- **Notification Email**: Email for alerts (optional)

### Permission Configuration

**Navigate to:** `admin/people/permissions`

Grant these permissions as needed:

| Permission | Role | Description |
|-----------|------|-------------|
| Administer Copilot Agent Tracker | Admin | Full configuration and debug access |
| View Agent Dashboard | Manager | Can access main dashboard |
| View Agent Details | Manager | Can see per-agent details |
| Manage LLM Configuration | Admin | Can change local LLM settings |
| Export Agent Data | Analyst | Can export telemetry and reports |
| Receive Telemetry Events | System | For agent systems to submit events |

### Telemetry Token Configuration

The telemetry token is auto-generated on module install and stored in Drupal state (never in database or git):

```bash
# Retrieve current token
drush php:eval "echo \Drupal::state()->get('copilot_agent_tracker.token');"

# Regenerate token (invalidates old token)
drush php:eval "
  \$token = bin2hex(random_bytes(32));
  \Drupal::state()->set('copilot_agent_tracker.token', \$token);
  echo 'New token: ' . \$token;
"

# View token on dashboard (as admin)
# Navigate to: /admin/reports/copilot-agent-tracker/langgraph
# Look for "Telemetry Token" section at bottom
```

### API Configuration

**Configure in agent client code:**

```bash
# Set telemetry endpoint and token as environment variables
export COPILOT_TRACKER_URL=https://forseti.life/api/copilot-agent-tracker/event
export COPILOT_TRACKER_TOKEN=<your-token-from-dashboard>

# Or configure in client application
```

## Usage

### Accessing the Dashboard

#### Main Dashboard

Navigate to `/admin/reports/copilot-agent-tracker` (automatically redirects to LangGraph dashboard)

```
┌──────────────────────────────────────────────────────────────┐
│  Copilot Agent Tracker Dashboard                      Refresh │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  [LangGraph Workflow Diagram]                               │
│                                                              │
│  ┌──────┐     ┌──────┐     ┌──────┐                         │
│  │Agent1│────→│Queue │────→│Agent2│                         │
│  │ idle │     │ (3)  │     │ busy │                         │
│  └──────┘     └──────┘     └──────┘                         │
│                                                              │
├──────────────────────────────────────────────────────────────┤
│ Active Agents (4)    │ Events (Hourly)                      │
│ ├─ agent-pm-forseti  │ ░░░░░░░░████░░░░ 127 events         │
│ ├─ agent-ba-forseti  │ ░░░░░░░░░░░░░░░░ 0 events           │
│ ├─ agent-dev-forseti │ ░░░░░░░░████░░░░ 98 events          │
│ └─ agent-qa-forseti  │                                      │
└──────────────────────────────────────────────────────────────┘
```

#### Agent Detail Page

Navigate to `/admin/reports/copilot-agent-tracker/agent/{agent_id}`

```
┌────────────────────────────────────────────────────────────┐
│  Agent: pm-forseti                          Status: ACTIVE │
├────────────────────────────────────────────────────────────┤
│  Last Heartbeat:  2 minutes ago             Uptime: 14 days│
│  Version:         1.2.3                     Type: PM        │
│  Current Task:    process-job-postings (12/50 items)      │
│                                                            │
│  ┌─ Performance                                            │
│  │ Tasks Completed: 847        Error Rate: 2.1%           │
│  │ Avg Duration: 2.5m          Success Rate: 97.9%        │
│  │                                                        │
│  ├─ Recent Events                                          │
│  │ ├─ 2 min ago: task_completed (entity_id=12345)        │
│  │ ├─ 5 min ago: task_started (entity_id=12344)          │
│  │ └─ 8 min ago: heartbeat_received                      │
│  │                                                        │
│  └─ Configuration                                          │
│    Model: Claude 3.5 Sonnet                               │
│    Max Concurrent Tasks: 5                                │
│    Retry Policy: exponential backoff                      │
└────────────────────────────────────────────────────────────┘
```

#### Local LLM Management Page

Navigate to `/admin/reports/copilot-agent-tracker/llm-management`

```
┌──────────────────────────────────────────────────────────┐
│  Local LLM Management                                    │
├──────────────────────────────────────────────────────────┤
│                                                          │
│  Active Model: llama2-7b                                │
│  ┌─ Alternative Models                                  │
│  │ ⚪ mistral-7b                                        │
│  │ ⚪ neural-chat-7b                                    │
│  │ ⚪ dolphin-2.2                                       │
│  │ Fallback Model: mistral-7b                          │
│  │                                                     │
│  ┌─ Model Parameters                                    │
│  │ Temperature: 0.7                                    │
│  │ Max Tokens: 2048                                    │
│  │ Top P: 0.9                                          │
│  │ Top K: 40                                           │
│  │                                                     │
│  ┌─ Prompt Testing                                      │
│  │ Test Prompt:                                        │
│  │ [Summarize this project: ______________________]     │
│  │                [Test Prompt] [Copy Response]        │
│  │                                                     │
│  │ Response:                                           │
│  │ "This project focuses on building distributed      │
│  │  agent orchestration system..."                     │
│  │                                                     │
│  │ Inference Time: 847ms                              │
│  │ Tokens: 156                                         │
└──────────────────────────────────────────────────────────┘
```

### Submitting Telemetry Events

#### Python Client Example

```python
import requests
import json
import os

TRACKER_URL = os.getenv('COPILOT_TRACKER_URL')
TRACKER_TOKEN = os.getenv('COPILOT_TRACKER_TOKEN')

def submit_event(event_type, agent_id, payload):
    """Submit telemetry event to tracker"""
    headers = {
        'X-Copilot-Agent-Tracker-Token': TRACKER_TOKEN,
        'Content-Type': 'application/json'
    }
    
    event = {
        'type': event_type,
        'agent_id': agent_id,
        'timestamp': int(time.time()),
        'data': payload
    }
    
    response = requests.post(
        f'{TRACKER_URL}/event',
        headers=headers,
        json=event
    )
    
    return response.status_code == 200

# Example usage
submit_event('task_started', 'agent-pm-forseti', {
    'task_id': 'job-posting-12345',
    'item_count': 50,
    'estimated_duration_seconds': 150
})

submit_event('heartbeat', 'agent-pm-forseti', {
    'uptime_seconds': 1209600,
    'tasks_completed': 847,
    'current_task_id': None,
    'memory_mb': 512,
    'cpu_percent': 15.2
})
```

#### Bash/cURL Example

```bash
# Set environment
TOKEN=$(drush php:eval "echo \Drupal::state()->get('copilot_agent_tracker.token');")
URL="http://localhost/api/copilot-agent-tracker/event"

# Submit single event
curl -X POST "$URL" \
  -H "X-Copilot-Agent-Tracker-Token: $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "task_completed",
    "agent_id": "agent-pm-forseti",
    "timestamp": '$(date +%s)',
    "data": {
      "task_id": "job-posting-12345",
      "status": "success",
      "duration_seconds": 145,
      "items_processed": 50
    }
  }'

# Batch submit multiple events
curl -X POST "$URL" \
  -H "X-Copilot-Agent-Tracker-Token: $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "events": [
      {
        "type": "heartbeat",
        "agent_id": "agent-pm-forseti",
        "timestamp": '$(date +%s)',
        "data": {"uptime_seconds": 1209600}
      },
      {
        "type": "heartbeat",
        "agent_id": "agent-ba-forseti",
        "timestamp": '$(date +%s)',
        "data": {"uptime_seconds": 864000}
      }
    ]
  }'
```

### Event Types

#### Core Event Types

| Type | Payload | Example |
|------|---------|---------|
| `heartbeat` | uptime, memory, cpu, task_id | Agent periodic status |
| `task_started` | task_id, item_count, estimated_duration | Task begins execution |
| `task_completed` | task_id, status, duration, items_processed | Task finishes (success) |
| `task_failed` | task_id, error_code, error_message, duration | Task ends with error |
| `agent_online` | version, environment, config | Agent starts |
| `agent_offline` | uptime_seconds, tasks_completed | Agent stops |
| `model_change` | old_model, new_model, reason | Model switched |

#### Event Sanitization

The following fields are automatically removed from events:

```
- chat_transcript
- user_credentials
- api_keys
- auth_tokens
- private_data
- raw_responses (contains full text)
```

Safe fields included:

```
- task_id, entity_id (identifiers only)
- status, state (enums)
- metrics (counts, durations, percentages)
- timestamps
- error_codes (not full messages with sensitive data)
```

## Dependencies

### Required
- **Drupal System Module**: Core hooks and services
- **Drupal Database**: For custom tables (MySQL/PostgreSQL)

### Optional
- **Views Module**: For custom reporting views
- **REST API Module**: Already provides REST framework
- **Devel Module**: Debug telemetry events (dev only)

### External Services
- **LangGraph**: For workflow visualization (optional but recommended)
- **Local LLM**: Ollama, llama.cpp, or other compatible models (optional)
- **Agent Clients**: GitHub Copilot CLI, custom agents, orchestrators

## API Documentation

### REST Endpoints

#### Submit Single Event

```
POST /api/copilot-agent-tracker/event
X-Copilot-Agent-Tracker-Token: <token>
Content-Type: application/json

{
  "type": "task_completed",
  "agent_id": "agent-pm-forseti",
  "timestamp": 1704067200,
  "data": {
    "task_id": "job-12345",
    "status": "success",
    "duration_seconds": 145,
    "items_processed": 50
  }
}

Response: 200 OK
{
  "status": "accepted",
  "event_id": "evt_123456"
}
```

#### Submit Batch Events

```
POST /api/copilot-agent-tracker/event
X-Copilot-Agent-Tracker-Token: <token>
Content-Type: application/json

{
  "events": [
    {
      "type": "heartbeat",
      "agent_id": "agent-pm-forseti",
      "timestamp": 1704067200,
      "data": {"uptime_seconds": 1209600}
    },
    {
      "type": "heartbeat",
      "agent_id": "agent-ba-forseti",
      "timestamp": 1704067200,
      "data": {"uptime_seconds": 864000}
    }
  ]
}

Response: 200 OK
{
  "status": "accepted",
  "events_accepted": 2,
  "events_rejected": 0
}
```

#### List Agents

```
GET /api/copilot-agent-tracker/agents
Accept: application/json

Response: 200 OK
{
  "agents": [
    {
      "agent_id": "agent-pm-forseti",
      "name": "PM Agent",
      "status": "active",
      "last_seen": 1704067200,
      "uptime_seconds": 1209600,
      "version": "1.2.3"
    }
  ],
  "count": 4
}
```

#### Get Agent Details

```
GET /api/copilot-agent-tracker/agents/{agent_id}

Response: 200 OK
{
  "agent_id": "agent-pm-forseti",
  "status": "active",
  "last_heartbeat": 1704067200,
  "current_task": {
    "task_id": "job-12345",
    "started_at": 1704066900,
    "estimated_completion": 1704067050
  },
  "statistics": {
    "tasks_completed": 847,
    "tasks_failed": 18,
    "error_rate": 0.021,
    "average_duration_seconds": 150
  }
}
```

#### List Events

```
GET /api/copilot-agent-tracker/events?agent_id=agent-pm-forseti&type=task_completed&since=1704067200

Response: 200 OK
{
  "events": [
    {
      "event_id": "evt_123456",
      "type": "task_completed",
      "agent_id": "agent-pm-forseti",
      "timestamp": 1704067200,
      "data": {
        "task_id": "job-12345",
        "status": "success",
        "duration_seconds": 145
      }
    }
  ],
  "count": 50,
  "page": 1
}
```

### Drupal Hooks

#### Telemetry Processing Hook

```php
// Process incoming telemetry before storage
function my_module_copilot_agent_tracker_event_preprocess(&$event) {
  // Sanitize additional fields
  $event['data']['internal_id'] = NULL;
}
```

#### Agent Status Changed Hook

```php
// React to agent status changes
function my_module_copilot_agent_tracker_agent_status_changed($agent_id, $old_status, $new_status) {
  if ($new_status === 'offline') {
    \Drupal::logger('my_module')->warning('Agent %id went offline', [
      '%id' => $agent_id
    ]);
  }
}
```

## Development

### Module Architecture

```
copilot_agent_tracker/
├── src/
│   ├── Controller/
│   │   ├── DashboardController.php (Main dashboard)
│   │   ├── AgentDetailController.php (Per-agent pages)
│   │   ├── LLMManagementController.php (LLM config)
│   │   └── TelemetryController.php (API endpoint)
│   ├── Service/
│   │   ├── AgentTrackerService.php (Core service)
│   │   ├── TelemetryProcessorService.php (Event processing)
│   │   ├── LLMService.php (Local LLM management)
│   │   └── SanitizationService.php (Data sanitization)
│   ├── Repository/
│   │   ├── AgentRepository.php (Query agents)
│   │   └── EventRepository.php (Query events)
│   └── Form/
│       └── LLMConfigForm.php (LLM settings)
├── config/
│   ├── schema/
│   └── install/ (Default settings)
├── js/
│   ├── dashboard.js (AJAX refresh)
│   └── langgraph.js (Visualization)
├── css/
│   └── (Styling)
└── copilot_agent_tracker.module (Hooks & schema)
```

### Key Services

#### AgentTrackerService

```php
// Register agent
$service = \Drupal::service('copilot_agent_tracker.tracker_service');
$service->registerAgent('agent-pm-forseti', 'PM Agent');

// Update agent status
$service->updateAgentStatus('agent-pm-forseti', 'active');

// Record event
$service->recordEvent('task_completed', 'agent-pm-forseti', [
  'task_id' => 'job-12345',
  'duration_seconds' => 145
]);
```

#### EventRepository

```php
// Query recent events
$repo = \Drupal::service('copilot_agent_tracker.event_repository');
$events = $repo->getEventsByAgent('agent-pm-forseti', $limit = 50);

// Get events by type
$events = $repo->getEventsByType('task_completed', $since = time() - 86400);

// Count events
$count = $repo->countEvents('agent-pm-forseti', 'task_completed');
```

### Testing

```bash
# Run unit tests
cd web/modules/custom/copilot_agent_tracker
../../../vendor/bin/phpunit tests/Unit/

# Run functional tests with mock data
../../../vendor/bin/phpunit tests/Functional/

# Test telemetry API
../../../vendor/bin/phpunit tests/Integration/TelemetryTest.php
```

### Local Development

```bash
# Enable debug logging
drush config:set copilot_agent_tracker.settings debug_logging TRUE -y

# Verify database tables
drush sql:query "DESCRIBE copilot_agent_tracker_agents;"
drush sql:query "DESCRIBE copilot_agent_tracker_events;"

# View recent events
drush sql:query "SELECT * FROM copilot_agent_tracker_events ORDER BY timestamp DESC LIMIT 10;"

# Query agents
drush sql:query "SELECT agent_id, status, last_seen FROM copilot_agent_tracker_agents;"
```

## Contributing

### Contribution Guidelines

We welcome contributions! Please follow these guidelines:

1. **Fork & Branch**: Create a feature branch (`feature/my-feature`)
2. **Code Standards**: Follow Drupal coding standards (phpcs)
3. **Tests**: Add tests for new functionality
4. **Documentation**: Update this README for new features
5. **Commit Message**: Use descriptive messages with issue references

### Code Quality

```bash
# Check code standards
phpcs src/

# Fix formatting
phpcbf src/

# Run static analysis
phpstan --level=7 src/
```

### Reporting Issues

When reporting issues, please include:
- Drupal version
- Module version
- Steps to reproduce
- Expected vs. actual behavior
- Database tables query results (agents/events counts)

### Security Considerations

- **Token Security**: Tokens stored in Drupal state, never in code
- **Event Sanitization**: Automatic removal of sensitive fields
- **Access Control**: Admin-only dashboard access
- **Rate Limiting**: Telemetry endpoint rate-limited to prevent floods
- **Input Validation**: All incoming events validated

### Performance Optimization

```php
// Archive old events
$cutoff = time() - (90 * 86400);  // 90 days
\Drupal::database()->delete('copilot_agent_tracker_events')
  ->condition('timestamp', $cutoff, '<')
  ->execute();

// Optimize tables
\Drupal::database()->query('OPTIMIZE TABLE copilot_agent_tracker_agents;');
\Drupal::database()->query('OPTIMIZE TABLE copilot_agent_tracker_events;');

// Create indexes for common queries
\Drupal::database()->query('CREATE INDEX idx_agent_timestamp ON copilot_agent_tracker_events(agent_id, timestamp);');
```

## License

This module is licensed under the **GNU General Public License v3.0 (GPL-3.0-only)**.

See the LICENSE file for full details.

```
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, version 3 of the License.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

## Support

### Getting Help

- **Documentation**: See module comments and inline code documentation
- **Issues**: File bugs via issue tracker
- **Community**: Ask questions in Drupal forums
- **Commercial Support**: Contact module maintainers

### Common Issues

#### Telemetry Events Not Appearing

```bash
# Verify token is correct
TOKEN=$(drush php:eval "echo \Drupal::state()->get('copilot_agent_tracker.token');")
echo "Token: $TOKEN"

# Check if telemetry is enabled
drush config:get copilot_agent_tracker.settings enable_telemetry

# View recent events directly
drush sql:query "SELECT * FROM copilot_agent_tracker_events ORDER BY timestamp DESC LIMIT 5;"
```

#### Dashboard Not Loading

```bash
# Clear cache
drush cache:rebuild

# Check permissions
drush php:eval "
  \$user = \Drupal\user\Entity\User::load(1);
  echo \$user->hasPermission('view agent dashboard') ? 'Yes' : 'No';
"

# Check for PHP errors
drush watchdog:tail copilot_agent_tracker
```

#### High Database Size

```bash
# Check table sizes
drush sql:query "
  SELECT table_name, round(data_length/1024/1024, 2) as size_mb 
  FROM information_schema.tables 
  WHERE table_schema='drupal' 
  AND table_name LIKE '%copilot_agent_tracker%'
  ORDER BY data_length DESC;
"

# Archive old events (older than 180 days)
drush sql:query "
  DELETE FROM copilot_agent_tracker_events 
  WHERE timestamp < $(date -d '-180 days' +%s);
"

# Optimize tables
drush sql:query "OPTIMIZE TABLE copilot_agent_tracker_agents, copilot_agent_tracker_events;"
```

## Security

### Security Considerations

#### Authentication & Authorization
- **Admin-Only Access**: Dashboard restricted to administrators
- **Token Authentication**: Telemetry API requires valid token
- **Drupal Permissions**: Full integration with Drupal ACL system
- **CSRF Protection**: All forms use Drupal token validation

#### Data Protection
- **Telemetry Sanitization**: Sensitive fields removed automatically
- **Event Retention**: Old events automatically purged (configurable)
- **No Credential Storage**: Tokens only in Drupal state, never persisted
- **Input Validation**: All incoming events validated and sanitized

#### Audit Trail
- **Event Logging**: All submissions logged and timestamped
- **Agent Tracking**: Full history of agent status changes
- **Change Attribution**: Track which admin made configuration changes
- **Compliance Ready**: Suitable for HIPAA, SOC 2, and GDPR compliance

### Reporting Security Issues

Do not file public security issues. Instead:
1. Email security concerns to maintainers
2. Include detailed reproduction steps
3. Allow 90 days for response and patching

## Maintenance

### Upgrade Path

```bash
# Update module
cd web/modules/custom/copilot_agent_tracker
git pull origin main

# Run database updates
drush updatedb -y

# Clear cache
drush cache:rebuild

# Verify
drush pm:list --type=module | grep copilot_agent_tracker
```

### Database Maintenance

```bash
# Archive old events (older than 180 days)
drush sql:query "
  DELETE FROM copilot_agent_tracker_events 
  WHERE timestamp < $(date -d '-180 days' +%s)
"

# Optimize tables
drush sql:query "OPTIMIZE TABLE copilot_agent_tracker_agents, copilot_agent_tracker_events;"

# View database stats
drush sql:query "
  SELECT 
    'agents' as table_name, COUNT(*) as count 
  FROM copilot_agent_tracker_agents
  UNION ALL
  SELECT 
    'events', COUNT(*) 
  FROM copilot_agent_tracker_events;
"
```

### Monitoring & Health Checks

Run health check before QA handoff:

```bash
# 1. Confirm module is enabled
drush pm:list --type=module --status=enabled 2>/dev/null | grep copilot_agent_tracker

# 2. Confirm dashboard route responds (expect 200 or 302→login for anon)
curl -sI http://localhost/admin/reports/copilot-agent-tracker | head -2

# 3. Confirm telemetry endpoint exists (expect 403 with no token, not 404)
curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost/api/copilot-agent-tracker/event

# 4. Retrieve the telemetry token
drush php:eval "echo 'Token: ' . \Drupal::state()->get('copilot_agent_tracker.token', 'NOT SET');"
```

Expected results:
- Step 1: Module listed as enabled
- Step 2: HTTP/2 302 or 200 response
- Step 3: 403 response code (not 404)
- Step 4: Valid token string (64 hex characters)

### Version History

- **1.0.0** (Feb 2026): Initial release with LangGraph dashboard and telemetry API
- **1.1.0** (Future): Local LLM support and model switching
- **1.2.0** (Future): Advanced analytics and trend prediction
- **2.0.0** (Future): Multi-tenant agent orchestration
