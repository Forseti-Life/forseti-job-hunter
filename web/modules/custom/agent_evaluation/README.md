# Agent Evaluation

**Comprehensive AI-powered evaluation of entities using the Agent Power Framework, generating detailed power analyses across 30 sub-dimensions.**

## Badges

[![License: GPL-3.0](https://img.shields.io/badge/License-GPL%203.0-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
![Drupal Version](https://img.shields.io/badge/Drupal-9%20%7C%2010%20%7C%2011-blue)
![Status: Stable](https://img.shields.io/badge/Status-Stable-brightgreen)

## Overview

The Agent Evaluation module enables AI-powered evaluation of entities (AI systems, organizations, platforms, individuals) using the comprehensive **Agent Power Framework**. It leverages the AI Conversation module to create interactive, multi-turn evaluations across **5 main power dimensions** and **30 sub-dimensions**, generating detailed scores, reasoning, and visualizations. Each entity evaluation is stored as a permanent, queryable content node with full conversation history for refinement and audit trails.

This module transforms abstract power analysis into a structured, explorable database of entity capabilities and limitations.

## Features

### 🎯 Agent Power Framework
- **5 Main Dimensions** with 6 sub-dimensions each (30 total):
  - **Information Access**: Scope, Restriction, Classification, Temporal, Sources, Granularity
  - **Resource Control**: Computational Resources, Financial Capital, Data Storage, Network Bandwidth, API Access, Human Resources
  - **Authority & Permission**: Legal Authorization, Institutional Backing, Budget Authority, Policy Compliance, Override Capability, Audit & Accountability
  - **Network Position**: Connectivity, Centrality, Trust & Reputation, Information Flow Control, Coalition Building, Network Effects
  - **Synthesis & Application**: Reasoning, Creativity, Planning, Learning, Memory, Execution

### 📊 Evaluation Scoring
- **0-9 Scale** for each dimension:
  - 0 = No capability/access
  - 1-3 = Limited (Danger level)
  - 4-6 = Moderate (Warning level)
  - 7-9 = High/Approaching Infinite (Success level)
- **Calculated Aggregates**: Main dimensions auto-calculated from sub-dimensions
- **Total Power Score**: Average across all 5 main dimensions

### 🤖 AI-Driven Analysis
- **Known Entity Path**: Full immediate evaluation with all scores and reasoning
- **Unknown Entity Path**: Interactive multi-turn refinement with clarifying questions
- **Conversational Refinement**: Continue evaluation conversations to adjust scores
- **Context Preservation**: Complete evaluation context maintained across turns

### 📈 Data Visualization
- **Radar Charts**: Visualize 5-dimensional power profile
- **Expandable Sections**: View detailed reasoning for each sub-dimension
- **Comparison Mode**: Stack evaluations to compare entities side-by-side
- **Export Capabilities**: Share or export evaluation data as JSON/PDF

### 🔐 Full Audit Trail
- **Conversation History**: Complete multi-turn conversation preserved with evaluation
- **Version Control**: All score changes tracked with timestamps
- **Source Attribution**: Every score linked to AI reasoning and conversation
- **Refinement Log**: Track all updates and modifications to scores

## Installation

### Prerequisites
- Drupal 9, 10, or 11
- AI Conversation module enabled
- Forseti Content module (optional, for framework integration)

### Installation Steps

```bash
# 1. Place module in custom modules directory
# Already located at: web/modules/custom/agent_evaluation/

# 2. Enable dependencies first
drush en ai_conversation -y

# 3. Enable the agent_evaluation module
drush en agent_evaluation -y

# 4. Install database schema and create content types
drush updatedb -y

# 5. Clear cache
drush cache:rebuild
```

### Verify Installation

```bash
# Check module is enabled
drush pm:list --type=module --status=enabled | grep agent_evaluation

# Verify evaluation entry point is accessible
curl -sI http://localhost/agent-power-framework/evaluate | head -2

# Test database tables exist
drush sql:query "SHOW TABLES LIKE '%agent_evaluation%';"
```

## Configuration

### Module Settings

**Navigate to:** `admin/config/agent-evaluation`

#### Evaluation Defaults
- **Default AI Model**: Claude 3.5 Sonnet (recommended for complex analysis)
- **Entity Validation**: Enable/disable automatic entity recognition check
- **Score Rounding**: Enable to round scores to nearest integer

#### Conversation Settings
- **Initial Context**: Pre-populate evaluation context with framework methodology
- **Max Turns Before Summary**: Trigger rolling summary after N conversation turns
- **Refinement Timeout**: Auto-lock evaluation scores after X days of inactivity

#### Visualization Options
- **Chart Type**: Radar (default) or Scatter plot
- **Dimension Weighting**: Equal (default) or custom weights per dimension
- **Export Formats**: JSON, PDF, CSV support

### Permission Configuration

**Navigate to:** `admin/people/permissions`

Grant these permissions as needed:

| Permission | Role | Description |
|-----------|------|-------------|
| Administer Agent Evaluation | Admin | Full module configuration |
| Create Evaluated Entity | Content Creator | Can start new evaluations |
| Edit Own Evaluated Entity | Content Creator | Can refine own evaluations |
| View Evaluated Entity | Anonymous | Read-only evaluation access |
| Export Evaluation Data | Content Creator | Can export evaluation results |

### AWS Bedrock Configuration

Agent Evaluation relies on AI Conversation module's AWS Bedrock setup. Ensure the following environment variables are set:

```bash
# AWS credentials (use IAM roles in production)
export AWS_ACCESS_KEY_ID=your_key
export AWS_SECRET_ACCESS_KEY=your_secret
export AWS_DEFAULT_REGION=us-west-2

# Or use IAM instance role (recommended for AWS deployments)
```

**Configuration File:** `settings.php` or `.env`

```php
// Bedrock region
$config['ai_conversation.settings']['bedrock_region'] = 'us-west-2';

// Model configuration
$config['ai_conversation.settings']['default_model'] = 'anthropic.claude-3-5-sonnet-20240620-v1:0';
```

## Usage

### Starting an Evaluation

#### 1. **Entry Point - Simple Form**

Navigate to `/agent-power-framework/evaluate`

```
┌─────────────────────────────────────┐
│   Agent Power Framework Evaluation   │
├─────────────────────────────────────┤
│                                     │
│  Entity Name:  [_________________]  │
│                                     │
│              [Start Evaluation]      │
│                                     │
└─────────────────────────────────────┘
```

**Example entities:**
- "ChatGPT"
- "Amazon AWS"
- "NSA" (U.S. National Security Agency)
- "Google Search"
- "Tesla"

#### 2. **AI Conducts Evaluation**

System creates an `ai_conversation` node and provides:
- Complete Agent Power Framework context
- All 30 sub-dimension definitions
- Entity name and initial analysis prompt

**AI Response Flow:**

```
Known Entity Path:
├─ AI recognizes entity (ChatGPT)
├─ Provides comprehensive power analysis
├─ Scores all 30 sub-dimensions
└─ Generates reasoning for each score

Unknown Entity Path:
├─ AI asks clarifying questions
│  "Did you mean X, Y, or Z?"
│  "What industry/sector?"
├─ Gathers information through dialogue
└─ Progressively refines scores
```

#### 3. **View Evaluation Results**

User is redirected to `/node/{entity_nid}` showing:

```
┌──────────────────────────────────────────┐
│  ChatGPT - Total Power: 7.8/9            │
├──────────────────────────────────────────┤
│                                          │
│  [5-Dimensional Radar Chart]             │
│                                          │
│  Information Access:       8.2           │
│  Resource Control:         8.5           │
│  Authority & Permission:   6.1           │
│  Network Position:         8.9           │
│  Synthesis & Application:  7.6           │
│                                          │
│  [+] Information Access Sub-Dimensions   │
│      ├─ Scope: 9                         │
│      ├─ Restriction: 7                   │
│      └─ ... (6 sub-dimensions)           │
│                                          │
│  [Continue Conversation] [Export] [Share]│
└──────────────────────────────────────────┘
```

#### 4. **Refine Evaluation (Optional)**

Click **"Continue Conversation"** to:
- Ask AI to explain specific scores
- Request reconsideration of ratings
- Provide additional context
- Compare to other entities

```
User: "Why is 'Legal Authorization' only 6/9? ChatGPT operates in multiple countries..."

AI: "Excellent point. In the EU, ChatGPT faces GDPR restrictions that limit its 
     legal scope compared to purely US-based systems. However, you're right that 
     I may have underweighted its authorization in US/Asia-Pacific regions. 
     Adjusting to 7/9..."

Result: Score updates in real-time, with timestamp and reasoning
```

### Example Evaluation Outputs

#### Example 1: Technology Platform (ChatGPT)

```
Entity: ChatGPT
Total Power Score: 7.8/9

Information Access (8.2/9)
├─ Scope: 9/9 - Access to broad knowledge domains
├─ Restriction: 7/9 - Some content policies limit access
├─ Classification: 8/9 - Can process various data types
├─ Temporal: 6/9 - Training cutoff limits real-time knowledge
├─ Sources: 9/9 - Trained on vast text corpus
└─ Granularity: 8/9 - Can provide detailed analysis

Resource Control (8.5/9)
├─ Computational Resources: 9/9 - Massive OpenAI infrastructure
├─ Financial Capital: 9/9 - Multi-billion dollar backing
├─ Data Storage: 8/9 - Petabyte-scale data centers
├─ Network Bandwidth: 8/9 - Global CDN distribution
├─ API Access: 8/9 - Rich API for integrations
└─ Human Resources: 8/9 - Thousands of researchers
```

#### Example 2: Government Agency (NSA)

```
Entity: NSA (National Security Agency)
Total Power Score: 8.1/9

Authority & Permission (9/9) [HIGHEST]
├─ Legal Authorization: 9/9 - Congressional authority, executive powers
├─ Institutional Backing: 9/9 - Executive branch backing
├─ Budget Authority: 9/9 - Annual multi-billion dollar budget
├─ Policy Compliance: 8/9 - Operates within legal frameworks (with oversight gaps)
├─ Override Capability: 9/9 - Can bypass many restrictions
└─ Audit & Accountability: 5/9 - Limited public transparency

Information Access (8.7/9)
├─ Scope: 9/9 - Signals intelligence, human intelligence
├─ Restriction: 3/9 - Minimal restrictions on collection
├─ Classification: 9/9 - Access to all classification levels
├─ Temporal: 9/9 - Real-time signals monitoring
├─ Sources: 9/9 - Global surveillance apparatus
└─ Granularity: 9/9 - Granular signal intercepts and analysis
```

### Bulk Operations

#### Export All Evaluations

```bash
# Export as JSON (API)
curl -H "Accept: application/json" \
  http://localhost/api/evaluations/export \
  > evaluations.json

# Query evaluations by dimension (Drupal views integration)
# Navigate to: /admin/reports/evaluations
# Filter by: Score range, dimension, date range
```

#### Programmatic Access

```php
// Load an evaluation
$entity = \Drupal::entityTypeManager()
  ->getStorage('node')
  ->load($entity_nid);

// Access scores
$total_power = $entity->field_total_power->value;
$info_access = $entity->field_information_access->value;

// Access conversation
$conversation_nid = $entity->field_source_conversation->target_id;
$conversation = Node::load($conversation_nid);
```

## Dependencies

### Required
- **Drupal Node Module**: Core entity storage
- **Drupal Field Module**: Core field system
- **Drupal User Module**: Authentication and permissions
- **AI Conversation Module**: Conversational AI engine with AWS Bedrock integration

### Optional
- **Forseti Content Module**: Integration with Agent Power Framework pages
- **Views Module**: For evaluation reporting and filtering
- **REST API Module**: For programmatic access to evaluation data

### External Services
- **AWS Bedrock**: Claude 3.5 Sonnet model (anthropic.claude-3-5-sonnet-20240620-v1:0)
- **AWS Region**: us-west-2

## API Documentation

### REST Endpoints

#### Create New Evaluation

```
POST /api/evaluations/create
Content-Type: application/json
Accept: application/json

{
  "entity_name": "ChatGPT",
  "description": "Optional description of entity"
}

Response: 201 Created
{
  "nid": 123,
  "conversation_nid": 124,
  "status": "evaluating",
  "created": 1704067200
}
```

#### Retrieve Evaluation

```
GET /api/evaluations/{entity_nid}

Response: 200 OK
{
  "nid": 123,
  "title": "ChatGPT",
  "total_power": 7.8,
  "scores": {
    "information_access": 8.2,
    "resource_control": 8.5,
    "authority_permission": 6.1,
    "network_position": 8.9,
    "synthesis_application": 7.6
  },
  "conversation_nid": 124,
  "updated": 1704067200
}
```

#### Update Evaluation

```
PATCH /api/evaluations/{entity_nid}
Content-Type: application/json
Authorization: Bearer {token}

{
  "field_resource_control": 8.8,
  "notes": "Updated after reviewing financial data"
}

Response: 200 OK
```

#### List Evaluations

```
GET /api/evaluations?filter[total_power][min]=7&sort=created

Response: 200 OK
{
  "data": [
    {
      "nid": 123,
      "title": "ChatGPT",
      "total_power": 7.8
    }
  ],
  "count": 1,
  "page": 1
}
```

### Drupal Hooks

#### Custom Field Updates via Conversation

```php
// Hook when AI updates evaluation scores
function my_module_agent_evaluation_scores_updated(&$scores, $conversation_nid) {
  // Log score changes
  \Drupal::logger('my_module')->notice('Scores updated: %scores', [
    '%scores' => json_encode($scores)
  ]);
}
```

#### Post-Evaluation Hook

```php
// Hook after evaluation node is created
function my_module_node_insert(Drupal\node\NodeInterface $node) {
  if ($node->bundle() === 'evaluated_entity') {
    // Send notification, export data, etc.
  }
}
```

## Development

### Module Architecture

```
agent_evaluation/
├── src/
│   ├── Controller/
│   │   └── EvaluationController.php (Routes)
│   ├── Form/
│   │   └── EvaluationForm.php (Entry form)
│   ├── Service/
│   │   ├── AgentEvaluationService.php (Main logic)
│   │   ├── FieldUpdateParser.php (Score extraction)
│   │   └── ScoreCalculator.php (Aggregation)
│   └── Plugin/
│       └── ... (Drupal integrations)
├── config/
│   ├── schema/
│   └── install/ (Default configuration)
├── templates/
│   └── (Twig templates)
├── js/
│   └── (Visualization scripts)
├── css/
│   └── (Styling)
└── agent_evaluation.module (Hooks)
```

### Key Services

#### AgentEvaluationService

```php
// Create new evaluation
$service = \Drupal::service('agent_evaluation.service');
$conversation_nid = $service->createEvaluation('ChatGPT');
```

#### FieldUpdateParser

```php
// Extract scores from AI response
$parser = \Drupal::service('agent_evaluation.field_parser');
$scores = $parser->extractFieldValues($ai_response_text);
// Returns: ['field_resource_control' => 8, 'field_scope' => 9, ...]
```

#### ScoreCalculator

```php
// Recalculate aggregates
$calculator = \Drupal::service('agent_evaluation.calculator');
$main_scores = $calculator->calculateMainDimensions($sub_scores);
$total = $calculator->calculateTotalPower($main_scores);
```

### Custom Evaluation Contexts

Create specialized evaluation frameworks by overriding context:

```php
// In your module
function my_module_agent_evaluation_context_alter(&$context, $entity_name) {
  // Customize framework for specific entity types
  if (strpos($entity_name, 'Government') !== false) {
    $context .= "\n\nSpecial consideration for government entities...";
  }
}
```

### Testing

```bash
# Run unit tests
cd web/modules/custom/agent_evaluation
../../../vendor/bin/phpunit tests/Unit/

# Run functional tests
../../../vendor/bin/phpunit tests/Functional/

# Run with code coverage
../../../vendor/bin/phpunit --coverage-html=coverage/ tests/
```

### Local Development

```bash
# Enable debug mode
drush config:set agent_evaluation.settings debug_mode TRUE -y

# View debug logs
drush watchdog:list | tail -20

# Export current evaluations for testing
drush sql:dump --tables-list=node,node__field_total_power > test_data.sql
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
- Reproduction steps
- Expected vs. actual behavior
- Environment (local/staging/production)

### Security Considerations

- **No API Keys in Code**: Never commit AWS credentials
- **CSRF Protection**: All forms include token validation
- **Access Control**: Verify permissions before exposing data
- **Input Validation**: Sanitize all entity names and user inputs
- **Output Encoding**: Always escape output in templates

### Performance Optimization

For large-scale evaluations:

```php
// Enable caching for context
$context = \Drupal::cache('default')->get('evaluation_context');
if (!$context) {
  $context = $this->buildContext();
  \Drupal::cache('default')->set('evaluation_context', $context, CacheBackendInterface::CACHE_PERMANENT);
}

// Batch process bulk evaluations
$batch = [
  'title' => 'Evaluating entities...',
  'operations' => [
    ['agent_evaluation_batch_evaluate', [$entities]],
  ],
  'finished' => 'agent_evaluation_batch_finished',
];
batch_set($batch);
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

- **Documentation**: See ARCHITECTURE.md and FORSETI_CONTEXT.md
- **Issues**: File bugs via issue tracker
- **Community**: Ask questions in Drupal forums
- **Commercial Support**: Contact module maintainers

### Common Issues

#### AI Model Not Responding

```bash
# Check AWS Bedrock connectivity
drush php:eval "\Drupal::service('ai_conversation.bedrock_client')->testConnection();"

# Verify credentials
echo $AWS_ACCESS_KEY_ID
echo $AWS_SECRET_ACCESS_KEY
```

#### Scores Not Updating

```bash
# Check field parsing
drush sql:query "SELECT * FROM node__field_total_power WHERE entity_id IN (SELECT nid FROM node WHERE type='evaluated_entity');"

# Verify conversation is running
drush sql:query "SELECT * FROM node__field_messages WHERE entity_id=123 ORDER BY field_messages_delta DESC LIMIT 1;"
```

#### Performance Issues

```bash
# Check table sizes
drush sql:query "SELECT table_name, round(data_length/1024/1024, 2) FROM information_schema.tables WHERE table_schema='drupal';"

# Archive old evaluations
drush eval "
$count = \Drupal::entityQuery('node')
  ->condition('type', 'evaluated_entity')
  ->condition('created', time() - 2592000, '<')  // 30 days
  ->execute();
"
```

## Security

### Security Considerations

#### Authentication & Authorization
- **Evaluation Creation**: Requires authenticated user
- **Data Access**: Respects Drupal node access controls
- **API Tokens**: Required for telemetry endpoints
- **Session Security**: Uses Drupal's CSRF token validation

#### Data Protection
- **AWS Bedrock**: All data transmitted over HTTPS
- **Input Validation**: Entity names sanitized and validated
- **Output Encoding**: Scores and text properly escaped
- **No Sensitive Data**: Conversations do not store credentials

#### Audit Trail
- **Change Logging**: All score updates timestamped
- **Conversation History**: Full audit trail of reasoning
- **Admin Reporting**: View all evaluations and modifications
- **Compliance**: Suitable for SOC 2 and GDPR compliance

### Reporting Security Issues

Do not file public security issues. Instead:
1. Email security concerns to maintainers
2. Include reproduction steps and impact assessment
3. Allow 90 days for response and patching

## Maintenance

### Upgrade Path

```bash
# Update module
cd web/modules/custom/agent_evaluation
git pull origin main

# Run database updates
drush updatedb -y

# Clear cache
drush cache:rebuild

# Verify
drush pm:list --type=module | grep agent_evaluation
```

### Database Maintenance

```bash
# Optimize tables
drush sql:query "OPTIMIZE TABLE node, node__field_total_power, node__field_messages;"

# Archive old evaluations (optional)
drush sql:query "
  UPDATE node SET status=0 
  WHERE type='evaluated_entity' AND created < DATE_SUB(NOW(), INTERVAL 1 YEAR)
"

# View database stats
drush sql:query "
  SELECT COUNT(*) as total_evaluations FROM node WHERE type='evaluated_entity';
"
```

### Monitoring

Monitor these metrics:

| Metric | Target | Action if Exceeded |
|--------|--------|-------------------|
| Average Response Time | < 2s | Optimize AI context |
| Failed API Calls | < 1% | Check AWS Bedrock status |
| Conversation Length | < 50 turns | Recommend summary/new session |
| Database Size | < 10GB | Archive old evaluations |
| Storage Usage | < 80% | Increase capacity |

### Version History

- **1.0.0** (Feb 2026): Initial release with Agent Power Framework
- **1.1.0** (Future): Bulk evaluation API
- **1.2.0** (Future): Custom framework builder
