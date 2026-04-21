# Forseti Cluster

**Drupal Version:** 10, 11 | **License:** GPL-3.0-only | **Status:** Stable

## Overview

The Forseti Cluster module provides a comprehensive administration interface for managing local mesh daemon (forseti-meshd) cluster operations. It enables cluster administrators to manage peer registries, track cluster capabilities, monitor service requests, maintain audit logs, and ensure mission alignment across the distributed network.

This module bridges Drupal administration with decentralized cluster communication, offering a unified control plane for inspecting and managing cluster topology, peer activation, and capability negotiation in a federated network architecture.

## Features

- **Cluster Overview** - Dashboard displaying cluster status, peer count, and service health
- **Peer Registry Management** - Register, view, activate, and deactivate cluster peers
- **Peer Activation/Deactivation** - Control peer participation in the cluster network
- **Capabilities Tracking** - View and manage capabilities offered by cluster peers
- **Audit Logging** - Complete audit trail of cluster operations and state changes
- **Mission Alignment Monitoring** - Track peer adherence to organizational mission and values
- **Service Request Management** - Handle inter-peer service requests and dependencies
- **Cluster Settings** - Configure cluster-wide parameters and connectivity options
- **Real-time Status** - Current view of peer status and cluster health
- **Access Control** - Role-based permission system for cluster administration

## Installation

### Prerequisites

- Drupal 10.0+ or Drupal 11.0+
- PHP 8.1+
- MySQL/MariaDB or PostgreSQL
- Local `forseti-meshd` daemon installation (cluster communication layer)
- Network connectivity to other cluster peers
- The following Drupal core module: System

### Steps

```bash
# Navigate to Drupal root
cd /path/to/drupal/root

# Ensure forseti-meshd is running locally
# (Install per forseti-meshd documentation if needed)

# Enable the Forseti Cluster module
drush en forseti_cluster -y

# Run database updates
drush updatedb -y

# Clear caches
drush cache:rebuild
```

## Configuration

### Initial Setup

1. **Enable the Module**
   - Go to Administration → Extend
   - Search for "Forseti Cluster"
   - Check the box and click "Install"

2. **Configure Cluster Settings**
   - Navigate to Administration → Forseti → Cluster → Settings
   - Set local node identifier and descriptive name
   - Configure mesh daemon connection details (host, port)
   - Set cluster namespace and discovery mode
   - Configure timeout and retry parameters

3. **Register Local Node**
   - After initial configuration, the local node should appear in peer registry
   - Verify connectivity and status

4. **Set Up Permissions**
   - Go to Administration → People → Permissions
   - Assign "Administer forseti cluster" permission to cluster administrators
   - This is the primary permission controlling access to all cluster management features

5. **Initialize Capabilities**
   - Visit Administration → Forseti → Cluster → Capabilities
   - Register services/capabilities this node provides
   - Set capability versions and parameters

## Usage

### Accessing Cluster Management

All cluster administration is accessed through the Forseti menu:

```
Administration → Forseti → Cluster
```

Requires the "Administer forseti cluster" permission.

### Cluster Overview

Navigate to `/admin/forseti/cluster`:

- **Cluster Status:** Current operational state (healthy, degraded, offline)
- **Peer Statistics:** Count of active, inactive, and unreachable peers
- **Service Health:** Status of running services across the cluster
- **Recent Events:** Latest cluster events and changes
- **Connectivity Map:** Visual representation of cluster topology

**Reading the Dashboard:**
- Green indicators = operational and healthy
- Yellow indicators = degraded or at-risk
- Red indicators = offline or failed

### Peer Registry Management

Navigate to `/admin/forseti/cluster/peers`:

```
Peer List displays:
- Peer ID (unique identifier)
- Node Name (descriptive name)
- Status (active, inactive, unreachable)
- Capabilities (services offered)
- Last Seen (timestamp of last communication)
- Actions (activate, deactivate, details, remove)
```

**Managing Peers:**

```bash
# View peer details
Click on a peer name to see:
- Full peer information and metadata
- Registered capabilities
- Service status
- Connection history
- Mission alignment score

# Activate a peer
Click "Activate" button to bring a peer into the cluster
(Peer must be registered and reachable)

# Deactivate a peer
Click "Deactivate" to remove peer from active cluster
(Peer remains in registry but stops participating)

# Remove a peer
Click "Remove" to completely delete from registry
(Use with caution - removes all peer history)
```

**Peer Activation Workflow:**

Navigate to `/admin/forseti/cluster/peers/{peer_id}/activate`:

```
1. Review peer information and proposed capabilities
2. Confirm peer meets mission alignment standards
3. Review any outstanding service requests
4. Click "Confirm Activation" to proceed
5. Peer begins accepting cluster assignments
```

### Capabilities Management

Navigate to `/admin/forseti/cluster/capabilities`:

```
Capabilities View displays:
- Capability Name (service identifier)
- Version (capability version)
- Providers (peers offering this capability)
- Consumers (peers requesting this capability)
- Status (available, deprecated, experimental)
- Last Updated (timestamp)
```

**Capability Operations:**

```bash
# Register new capability
Click "Add Capability"
- Set capability name and description
- Define version and API contract
- Set availability status
- Assign to responsible peer

# Update capability version
Click "Edit" on existing capability
- Update description, version, status
- Modify API parameters if needed
- Changes propagate to dependent services

# Deprecate capability
Mark capability as "deprecated" to signal pending removal
- Existing consumers notified
- New registrations prevented
- Full removal after transition period
```

### Service Request Management

Navigate to `/admin/forseti/cluster/service-requests`:

```
Service Requests display:
- Request ID (unique identifier)
- Requester (requesting peer)
- Service Needed (requested capability)
- Status (pending, assigned, completed, failed)
- Created Date (request timestamp)
- Priority (high, normal, low)
- Actions (approve, deny, view details, reassign)
```

**Handling Requests:**

```bash
# Review pending requests
List shows all unresolved service requests

# Approve request
Click "Approve" to allow service connection
- Select provider peer from available list
- Set timeout and retry parameters
- Request transitions to "assigned"

# Deny request
Click "Deny" to reject service request
- Provide reason for denial
- Requestor receives notification
- Request marked as "failed"

# Reassign to different provider
Click "Reassign" if current provider fails
- Select alternative provider
- Update delivery parameters
# Check request history and resolution time
```

### Audit Log Monitoring

Navigate to `/admin/forseti/cluster/audit`:

```
Audit Log displays chronological record:
- Timestamp (when operation occurred)
- Operation (action performed)
- Admin (who performed the action)
- Details (what was changed)
- Status (success, warning, error)
```

**Log Filtering:**

```bash
# Filter by operation type:
- Peer activation/deactivation
- Capability registration/update
- Service request approval
- Configuration changes
- System errors

# Filter by time range:
- Last 24 hours
- Last 7 days
- Last 30 days
- Custom date range

# Export audit log:
Click "Export" to download as CSV for external analysis
```

### Mission Alignment Tracking

Navigate to `/admin/forseti/cluster/mission`:

```
Mission Alignment view shows:
- Peer ID and name
- Alignment Score (percentage)
- Areas Aligned (verified criteria met)
- Areas at Risk (criteria not fully met)
- Actions (review details, request remediation)
```

**Alignment Criteria:**

```bash
# System evaluates peers on:
- Data handling practices
- Service availability commitments
- Security posture
- Community contribution history
- Terms of service compliance

# Alignment Score calculation:
- Excellent (90-100%): All criteria met
- Good (70-89%): Majority criteria met
- At Risk (50-69%): Several criteria not met
- Failed (<50%): Major criteria violations
```

**Remediation Workflow:**

```bash
# For peers "At Risk" or "Failed":
1. Review detailed alignment report
2. Identify specific criteria gaps
3. Send message requesting remediation
4. Set deadline for improvement
5. Re-evaluate on deadline date
6. Escalate if no improvement shown
```

### Cluster Settings

Navigate to `/admin/forseti/cluster/settings`:

```
Configurable parameters:

Local Node:
- Node Identifier: Unique cluster ID (auto-generated on first use)
- Node Name: Display name for this cluster node
- Description: What this node provides to cluster

Mesh Daemon:
- Daemon Host: IP/hostname of forseti-meshd (default: localhost)
- Daemon Port: Port forseti-meshd listens on (default: 9000)
- Reconnect Interval: Seconds between reconnection attempts
- Timeout: Max wait for daemon responses

Discovery:
- Discovery Mode: Manual, mDNS, or Kubernetes
- Namespace: Cluster namespace (for multi-cluster setups)
- Service Name: Kubernetes service name (if using K8s discovery)

Safety & Limits:
- Max Peers: Maximum peers allowed in cluster
- Max Service Requests: Queue limit for pending requests
- Cleanup Interval: How often to clean stale peer records
- Require Mission Alignment: Enforce alignment checks before activation

Notifications:
- Admin Email: Send alerts about cluster events
- Alert on Peer Loss: Notify when peer becomes unreachable
- Alert on Service Failure: Notify when service requests fail
```

**Saving Settings:**

```bash
# Settings changes are applied immediately
# Service restarts not required (graceful reload)
# All admins notified of configuration changes
# Changes logged in audit trail
```

## Dependencies

Required (automatically enabled with this module):
- `drupal:system` - Core system functionality

## API Documentation

### Hooks

The module implements hooks for cluster integration:

```php
// React to peer activation/deactivation
function mymodule_forseti_cluster_peer_activated(array $peer_info) {
  // Custom logic when peer joins cluster
}

function mymodule_forseti_cluster_peer_deactivated(array $peer_info) {
  // Custom logic when peer leaves cluster
}

// React to capability registration
function mymodule_forseti_cluster_capability_registered(array $capability) {
  // Custom logic for new capability
}

// Evaluate mission alignment
function mymodule_forseti_cluster_evaluate_alignment(array $peer) {
  // Custom alignment criteria
  return $alignment_score; // 0-100
}

// Handle service requests
function mymodule_forseti_cluster_service_request(array $request) {
  // Custom request handling logic
}
```

### Programmatic Access

```php
// Get cluster status
$cluster_status = Drupal::service('forseti_cluster.manager')
  ->getClusterStatus();

// List active peers
$peers = Drupal::service('forseti_cluster.manager')
  ->getActivePeers();

// Get peer details
$peer = Drupal::service('forseti_cluster.manager')
  ->getPeerInfo($peer_id);

// List available capabilities
$capabilities = Drupal::service('forseti_cluster.manager')
  ->getCapabilities();

// Get pending service requests
$requests = Drupal::service('forseti_cluster.manager')
  ->getPendingRequests();

// Approve service request
Drupal::service('forseti_cluster.manager')
  ->approveServiceRequest($request_id, $provider_peer_id);
```

### Event System

The module dispatches events that can be subscribed to:

```php
// Subscribe to cluster events
namespace Drupal\mymodule\EventSubscriber;

use Drupal\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ClusterEventSubscriber implements EventSubscriberInterface {
  public static function getSubscribedEvents() {
    return [
      'forseti_cluster.peer_activated' => 'onPeerActivated',
      'forseti_cluster.peer_deactivated' => 'onPeerDeactivated',
      'forseti_cluster.service_request' => 'onServiceRequest',
    ];
  }

  public function onPeerActivated(Event $event) {
    // Handle peer activation event
  }
}
```

## Development

### Local Setup

```bash
# Start forseti-meshd in development mode (if available)
# forseti-meshd --debug --listen 127.0.0.1:9000

# Enable Devel module for debugging
drush en devel -y

# Check cluster connectivity
drush ev "echo Drupal::service('forseti_cluster.manager')->getClusterStatus();"

# Debug peer information
drush php
> $manager = Drupal::service('forseti_cluster.manager');
> kint($manager->getActivePeers());
```

### Extending the Module

To create custom cluster integrations:

```php
// Custom cluster service example
namespace Drupal\mymodule\Services;

class CustomClusterHandler {
  protected $clusterManager;

  public function __construct($cluster_manager) {
    $this->clusterManager = $cluster_manager;
  }

  public function handleCustomLogic() {
    $peers = $this->clusterManager->getActivePeers();
    foreach ($peers as $peer) {
      // Custom processing
    }
  }
}

// Register in mymodule.services.yml
services:
  mymodule.custom_cluster_handler:
    class: Drupal\mymodule\Services\CustomClusterHandler
    arguments: ['@forseti_cluster.manager']
```

### Testing Cluster Operations

```bash
# Simulate peer activation
drush ev "Drupal::service('forseti_cluster.manager')->activatePeer('peer_id');"

# Simulate capability registration
drush ev "Drupal::service('forseti_cluster.manager')->registerCapability('service_name', '1.0');"

# Monitor cluster health
watch -n 5 'drush ev "echo Drupal::service(\"forseti_cluster.manager\")->getClusterStatus();"'
```

## Contributing

We welcome contributions from the community! To contribute:

1. Fork the repository on GitHub
2. Create a feature branch for your work
3. Make focused, well-documented changes
4. Test thoroughly with multiple peers in a test cluster
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
- **forseti-meshd:** For mesh daemon issues, see forseti-meshd documentation

### Reporting Issues

When reporting issues, please include:
- Drupal version and PHP version
- forseti-meshd version and configuration
- Number of peers in cluster
- Steps to reproduce
- Expected behavior vs. actual behavior
- Relevant logs from Administration → Reports → Recent Log Messages

## Security

### Cluster Communication

- All inter-peer communication via forseti-meshd daemon (security layer)
- Authentication and encryption handled by mesh daemon
- Drupal layer enforces additional permission controls

### Access Control

- "Administer forseti cluster" permission is highly privileged
- Only grant to trusted administrators
- All administrative actions logged in audit trail
- Regular review of audit logs recommended

### Peer Vetting

- Review mission alignment before activating peers
- Implement and monitor compliance criteria
- Remove non-compliant peers promptly
- Document remediation attempts for accountability

### Recommendations

- Keep Drupal and all modules updated
- Keep forseti-meshd daemon updated
- Use HTTPS for all Drupal administrative access
- Restrict network access to mesh daemon port
- Implement regular backups of cluster configuration
- Monitor audit logs for suspicious activity
- Review peer status regularly for unexpected changes

## Maintenance

**Last Updated:** January 2024

**Maintained By:** Forseti Community

**Compatibility:**
- Drupal 10.0+ ✓
- Drupal 11.0+ ✓
- PHP 8.1+ ✓
- PHP 8.2+ ✓
- PHP 8.3+ ✓
- forseti-meshd 0.8+ ✓

**Support Timeline:**
- Security updates: Provided for stable releases
- Bug fixes: Best effort for reported issues
- Feature requests: Reviewed by community and maintainers

**Common Issues:**

```bash
# Cannot connect to forseti-meshd
- Verify daemon is running: ps aux | grep forseti-meshd
- Check configured host/port in cluster settings
- Verify network connectivity: nc -zv localhost 9000
- Check daemon logs for errors

# Peer remains unreachable
- Verify peer's forseti-meshd is running
- Check network connectivity between nodes
- Review peer's mission alignment status
- Check firewall rules on both nodes

# Service requests timeout
- Increase timeout value in cluster settings
- Check provider peer's service availability
- Verify network latency between nodes
- Check for resource constraints on provider
```

---

**Questions?** Check the GitHub repository or Drupal community resources.
