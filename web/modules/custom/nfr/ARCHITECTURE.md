# National Firefighter Registry - Architecture Documentation

**Last Updated:** February 6, 2026

## System Overview

The National Firefighter Registry (NFR) is a Drupal-based cancer surveillance and health tracking system designed to support the CDC's research into cancer incidence among firefighters. The system facilitates participant registration, longitudinal data collection, state cancer registry linkages, and integration with external data sources.

## Architecture Goals

1. **Privacy & Security**: Protect participant health information while enabling research
2. **Scalability**: Support nationwide participant enrollment
3. **Interoperability**: Integrate with state cancer registries and USFA NERIS
4. **Flexibility**: Accommodate evolving research requirements
5. **Data Quality**: Ensure accurate, complete, and timely data collection

## System Components

### 1. Data Layer

#### Database Tables

**nfr_firefighters** (Primary Registry)
- Core participant demographic and career information
- State-level organization for registry linkages
- NERIS ID for external data integration
- Status tracking for active participation

**nfr_cancer_data** (Cancer Surveillance)
- Cancer incidence tracking
- Diagnosis information (type, stage, date)
- State registry linkage status and identifiers
- Privacy-protected health information

**nfr_longitudinal_data** (Follow-up Data)
- Time-series survey responses
- Flexible JSON storage for evolving questionnaires
- Survey versioning support
- Participant engagement tracking

### 2. Business Logic Layer

#### Services

**NERISIntegration Service**
- Handles communication with USFA NERIS API
- Data import and synchronization
- ID mapping and validation
- Error handling and logging

**CancerRegistryLinkage Service**
- Manages state cancer registry connections
- Batch processing for large-scale linkages
- Linkage consent verification
- Statistics and reporting

**DataExport Service**
- De-identification for research exports
- Multiple format support (CSV, JSON)
- Filtering and aggregation
- Public dashboard data preparation

### 3. Presentation Layer

#### Controllers

**NFRController**
- Dashboard: Summary statistics and key metrics
- Firefighter List: Searchable, paginated registry
- Data Dashboard: Public-facing analytics
- Cancer Data: Health surveillance reporting

#### Forms

**NFRRegistrationForm**
- Multi-section participant enrollment
- Consent management
- Validation and duplicate prevention
- HIPAA-compliant data collection

**NFRSettingsForm**
- System configuration
- Integration settings (NERIS, state registries)
- Privacy and export controls

## Data Flow

### Participant Registration Flow

```
User → Registration Form → Validation
  ↓
Create Firefighter Record
  ↓
  ├→ Optional: Create Cancer Data Record
  ↓
  └→ Generate Longitudinal Survey Schedule
  ↓
Send Confirmation Email
```

### State Registry Linkage Flow

```
Cancer Data Record Created
  ↓
Check Consent for Linkage
  ↓
  ├→ Consent Given → Queue for Batch Processing
  │     ↓
  │   State Registry API Call
  │     ↓
  │   Record Linkage ID
  │     ↓
  │   Mark as Linked
  │
  └→ No Consent → Store Unlinked
```

### NERIS Integration Flow

```
NERIS Import Triggered
  ↓
Fetch Data from NERIS API
  ↓
Check for Existing Record (by NERIS ID)
  ↓
  ├→ New → Create Firefighter Record
  │         ↓
  │       Store NERIS ID
  │
  └→ Existing → Update Record
                ↓
              Sync Modified Fields
```

## Privacy & Security Considerations

### Data Protection

1. **De-identification**: All exported data removes PII
2. **Access Control**: Role-based permissions for sensitive data
3. **Audit Logging**: Track access to cancer and health data
4. **Consent Management**: Explicit consent for registry linkages
5. **Encryption**: Sensitive fields encrypted at rest

### HIPAA Compliance

- Minimum necessary data collection
- Secure data transmission (HTTPS only)
- Access logs for protected health information
- Data retention and destruction policies
- Business Associate Agreements with state registries

## Integration Architecture

### State Cancer Registries

**Integration Model**: API-based or batch file exchange

```
NFR → State Registry Connector → State API/SFTP
  ↓
Linkage Results
  ↓
Update NFR Records
```

**Key Challenges**:
- 50+ different state systems
- Varying API capabilities
- Different data formats
- Consent law variations by state

### USFA NERIS

**Integration Model**: REST API with authentication

```
NFR ← API Request → NERIS
  ↓
Firefighter Data
  ↓
Map to NFR Schema
  ↓
Store with NERIS ID
```

## Scalability Strategy

### Expected Load
- Target: 1 million+ participants
- Daily registrations: 100-500
- State registry batches: Monthly (thousands per batch)
- Public dashboard: High traffic

### Performance Optimizations

1. **Database Indexing**: Key fields for common queries
2. **Caching**: Dashboard statistics, dropdown options
3. **Batch Processing**: State registry linkages off-peak
4. **CDN**: Public dashboard assets
5. **Query Optimization**: Pagination, limit result sets

## Future Enhancements

### Phase 2 (Years 1-2)
- Enhanced longitudinal surveys
- Mobile-friendly data entry
- Automated follow-up reminders
- Basic analytics dashboards

### Phase 3 (Years 2-3)
- Complete state registry coverage
- Advanced analytics and visualizations
- Machine learning for data quality
- Public API for researchers

### Phase 4 (Years 3+)
- Real-time data updates
- Predictive modeling
- Integration with additional health data sources
- International expansion

## Deployment & Operations

### Development Environment
- Local Drupal installation
- MySQL/MariaDB database
- PHP 8.1+
- Git version control

### Production Environment
- Cloud hosting (AWS/Azure)
- Load-balanced web servers
- Database replication
- Automated backups
- SSL/TLS encryption

### Monitoring
- Application performance monitoring
- Database query analysis
- Error logging and alerting
- User activity tracking
- Integration health checks

## Technical Dependencies

### Drupal Modules
- Core: System, User, Field, Node
- Contrib: (to be determined based on needs)

### External Services
- USFA NERIS API
- State cancer registry APIs
- Email service provider
- Analytics platform

### Libraries
- GuzzleHttp: HTTP client for API calls
- PHPMailer: Email notifications
- Chart.js: Data visualizations

## Testing Strategy

### Unit Tests
- Service layer logic
- Data validation
- Export functions

### Integration Tests
- API integrations
- Database operations
- Form submissions

### User Acceptance Testing
- Registration workflow
- Dashboard accuracy
- Data export formats

## Documentation

### User Documentation
- Registration guide
- Dashboard user manual
- FAQ

### Technical Documentation
- API documentation
- Database schema
- Deployment guide
- Troubleshooting guide

### Compliance Documentation
- Privacy policy
- HIPAA compliance report
- Security audit results
- Data governance policy
