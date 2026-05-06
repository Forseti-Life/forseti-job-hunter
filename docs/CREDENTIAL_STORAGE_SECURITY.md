# Credential Storage & Security Model

**Purpose**: Securely store employer site credentials (username/password, API tokens) needed for automated job application submission via browser automation.

**Status**: ✅ Phase 1.5 Complete - Core infrastructure implemented and deployed

---

## Overview

The credential storage system provides a secure, encrypted vault for storing employer-specific authentication credentials. All credentials are:

- ✅ **Encrypted at rest** using Drupal's encryption service (AES-256)
- ✅ **Never logged** in plaintext (audit logs contain metadata only)
- ✅ **User-isolated** (users can only access their own credentials)
- ✅ **Verified before use** (credential verification queue available)
- ✅ **Audit-logged** (all access tracked for compliance)

---

## Architecture

### Database Schema: `jobhunter_employer_credentials`

```sql
CREATE TABLE jobhunter_employer_credentials (
  id INT PRIMARY KEY AUTO_INCREMENT,
  uid INT NOT NULL,                           -- User ID (FK users.uid)
  company_id INT NOT NULL,                    -- Company ID (FK jobhunter_companies.id)
  credential_type VARCHAR(32),                -- 'basic' or 'api_token'
  encrypted_data MEDIUMTEXT NOT NULL,         -- Base64(Encrypt(JSON))
  submission_url VARCHAR(512),                -- URL where credentials are used
  created VARCHAR(19),                        -- Created timestamp
  updated VARCHAR(19),                        -- Last updated timestamp
  last_verified VARCHAR(19),                  -- Last successful verification
  verification_status VARCHAR(32),            -- unverified|verified|failed|expired
  
  UNIQUE KEY user_company_type (uid, company_id, credential_type),
  INDEXES: uid, company_id, credential_type, verification_status
);
```

### Data Types

#### Basic Authentication (`credential_type = 'basic'`)
```json
{
  "username": "john.doe@company.com",
  "password": "encrypted_stored_value"
}
```

#### API Token (`credential_type = 'api_token'`)
```json
{
  "token": "api_token_value",
  "token_type": "Bearer"  // or Basic, Custom
}
```

### Service: `CredentialManagementService`

**Class**: `Drupal\job_hunter\Service\CredentialManagementService`

**Accessible via**: `@job_hunter.credential_management_service`

#### Public Methods

##### `storeCredential(int $uid, int $company_id, string $credential_type, array $credential_data, string $submission_url = ''): array`

Stores or updates encrypted credentials.

**Parameters**:
- `$uid`: User ID
- `$company_id`: Company/employer ID (jobhunter_companies.id)
- `$credential_type`: 'basic' or 'api_token'
- `$credential_data`: Array with username/password or token
- `$submission_url`: Optional URL where credentials are used

**Returns**:
```php
[
  'success' => bool,
  'credential_id' => int|null,
  'message' => string,
  'error' => string|null,
]
```

**Example**:
```php
$result = $credential_service->storeCredential(
  uid: $user->id(),
  company_id: 42,
  credential_type: 'basic',
  credential_data: [
    'username' => 'john.doe@example.com',
    'password' => 'secure_password_here',
  ],
  submission_url: 'https://careers.example.com/login'
);

if ($result['success']) {
  // Credentials stored, ID: $result['credential_id']
}
```

---

##### `retrieveCredential(int $uid, int $company_id, string $credential_type): ?array`

Retrieves and decrypts credentials for a specific user/company/type combo.

⚠️ **SECURITY WARNING**: This method decrypts sensitive data. Only call when actually needed for automation. Clear returned data after use.

**Permission Check**: User can only retrieve their own credentials unless they have `administer job application automation` permission.

**Returns**:
```php
[
  'credential_id' => int,
  'type' => 'basic'|'api_token',
  'username' => string|null,       // basic auth only
  'password' => string|null,       // basic auth only
  'token' => string|null,          // api_token only
  'token_type' => string|null,     // api_token only
  'submission_url' => string,
] | null  // null if not found
```

**Example**:
```php
// Retrieve credentials for browser automation
$credentials = $credential_service->retrieveCredential(
  uid: $user->id(),
  company_id: 42,
  credential_type: 'basic'
);

if ($credentials) {
  // Pass to BrowserAutomationService for use
  $browser->login($credentials['username'], $credentials['password']);
  
  // Clear sensitive data from memory
  unset($credentials);
}
```

---

##### `deleteCredential(int $uid, int $company_id, string $credential_type): bool`

Permanently deletes stored credentials.

**Permission Check**: Same as `retrieveCredential()`.

**Example**:
```php
// User revokes credential storage
$deleted = $credential_service->deleteCredential(
  uid: $user->id(),
  company_id: 42,
  credential_type: 'basic'
);

if ($deleted) {
  // Credential removed
}
```

---

##### `listUserCredentials(int $uid): array`

Lists all credential metadata for a user (does NOT decrypt values).

**Returns**:
```php
[
  [
    'id' => 1,
    'company_id' => 42,
    'credential_type' => 'basic',
    'submission_url' => 'https://careers.example.com/login',
    'created' => '2025-02-18 10:30:00',
    'updated' => '2025-02-18 10:30:00',
  ],
  // ...
]
```

---

##### `testCredential(int $uid, int $company_id, string $credential_type, string $test_url): array`

Tests credentials against a URL via async browser automation (Phase 2).

**Returns**:
```php
[
  'success' => bool,
  'message' => string,
  'queued' => bool,  // True if testing was queued for background processing
]
```

---

## Security Model

### Encryption

**Algorithm**: AES-256-CBC (via OpenSSL)

**Key Derivation**: HKDF-SHA256 from Drupal's private key storage

**Flow**:
1. Input: credential data (JSON)
2. Serialize to JSON: `json_encode($credential_data)`
3. Generate random 16-byte IV
4. Encrypt: `openssl_encrypt(json_data, 'AES-256-CBC', key, OPENSSL_RAW_DATA, iv)`
5. Combine: IV + encrypted data
6. Base64 encode for database storage
7. Store in database: `encrypted_data` field

**Decryption** (reverse):
1. Retrieve from database: `$encrypted_data` (base64)
2. Base64 decode: `base64_decode($encrypted_data)`
3. Extract IV: first 16 bytes
4. Extract encrypted: remaining bytes
5. Derive key (same method)
6. Decrypt: `openssl_decrypt(encrypted, 'AES-256-CBC', key, OPENSSL_RAW_DATA, iv)`
7. Parse JSON: `json_decode(decrypted_json, true)`
8. Return credential data

**Why This Approach**:
- ✅ OpenSSL available in all PHP installations
- ✅ Random IV prevents patterns in encrypted output
- ✅ Key derived from Drupal's private key (portable across instances)
- ✅ No external Encrypt module dependency required
- ✅ Meet security standards for credential storage

### Access Control

#### User Isolation
- Users can only access/modify their own credentials
- Admin can access any user's credentials (for support/audit)
- Verified via `currentUser()->id()` vs `$uid` parameter
- Non-matching access attempts are logged as warnings

#### Permission System
- **Users**: Can manage their own credentials
- **Admins**: `administer job application automation` permission
- **Audit**: All credential operations logged (without plaintext)

### Logging & Audit Trail

**What IS logged**:
```
🔐 Credential stored for user @uid, company @company_id, type @type
🔐 Credential updated for user @uid, company @company_id, type @type
🔐 Credential retrieved for user @uid, company @company_id
🔐 Credential deleted for user @uid, company @company_id, type @type
⚠️ Unauthorized credential access attempt by user @current_uid for user @target_uid
```

**What is NOT logged**:
- ❌ Username/email/password
- ❌ API tokens
- ❌ Any plaintext credential values

### Verification Status

Credentials track verification state:

| Status | Meaning |
|--------|---------|
| `unverified` | Newly stored, not yet tested |
| `verified` | Successfully authenticated at least once |
| `failed` | Last test attempt failed |
| `expired` | Credential has expired (user action needed) |

---

## Integration with Application Submission

### Validation Flow

When user submits application:

```
submitApplication(uid, job_id)
  ↓
validateApplicationPrerequisites()
  ├─ Check profile 90%+ complete
  ├─ Check job still active
  ├─ Check no duplicate application
  ├─ Check required fields present
  └─ ✅ NEW: Check credentials exist for company
      ├─ Credentials found? → Mark as ready for automation
      └─ Credentials missing? → Mark for manual submission
  ↓
createApplicationRecord()
  ↓
queueApplicationForSubmission()
  ↓
ApplicationSubmitterQueueWorker.processItem()
  ├─ If credentials available:
  │   ├─ Retrieve credentials via CredentialManagementService
  │   ├─ Pass to BrowserAutomationService
  │   └─ Attempt automation
  └─ If credentials missing:
      └─ Queue for manual submission (admin review)
```

---

## Usage Examples

### Store User Credentials (from form submission)

```php
// In a form submission handler
$credential_service = \Drupal::service('job_hunter.credential_management_service');

$result = $credential_service->storeCredential(
  uid: \Drupal::currentUser()->id(),
  company_id: $form_state->getValue('company_id'),
  credential_type: 'basic',
  credential_data: [
    'username' => $form_state->getValue('username'),
    'password' => $form_state->getValue('password'),
  ],
  submission_url: $form_state->getValue('login_url'),
);

if ($result['success']) {
  \Drupal::messenger()->addMessage(
    'Credentials saved securely. Your account is ready for automated applications.'
  );
} else {
  \Drupal::messenger()->addError('Error saving credentials: ' . $result['error']);
}
```

### Use Credentials in Browser Automation (Phase 2)

```php
// In BrowserAutomationService
$credential_service = \Drupal::service('job_hunter.credential_management_service');

// Retrieve decrypted credentials
$creds = $credential_service->retrieveCredential(
  uid: $application['uid'],
  company_id: $job['company_id'],
  credential_type: 'basic',
);

if ($creds) {
  // Authenticate with browser automation
  $browser->navigateTo($creds['submission_url']);
  $browser->fillField('username', $creds['username']);
  $browser->fillField('password', $creds['password']);
  $browser->submitForm();
  
  // Clear sensitive data
  unset($creds);
} else {
  // No credentials - mark for manual submission
  $this->updateApplicationStatus($app_id, 'manual_required');
}
```

### List User's Stored Credentials

```php
$credential_service = \Drupal::service('job_hunter.credential_management_service');

$credentials = $credential_service->listUserCredentials(
  uid: \Drupal::currentUser()->id()
);

foreach ($credentials as $cred) {
  echo 'Company ' . $cred['company_id'] . ': ' 
    . $cred['credential_type'] . ' authentication'
    . ' (verified: ' . $cred['verification_status'] . ')';
}
```

---

## Database Migration

### Execution

```bash
# Create table (automatic on drush updatedb)
drush updatedb

# Manual verification
drush sqlc "DESCRIBE jobhunter_employer_credentials;"
```

### Update Hook: `job_hunter_update_9028()`

Location: [job_hunter.install](../job_hunter.install#L205)

Creates `jobhunter_employer_credentials` table with all security features:

```php
function job_hunter_update_9028() {
  _job_hunter_create_employer_credentials_table();
  return t('Created jobhunter_employer_credentials table for encrypted credential storage.');
}
```

---

## Roadmap

### Phase 1.5 (COMPLETE ✅)
- ✅ Database schema with encryption support
- ✅ CredentialManagementService with encryption/decryption
- ✅ Access control and permission checks
- ✅ Audit logging (no plaintext)
- ✅ Credential validation in ApplicationSubmissionService
- ✅ Service registration and dependency injection

### Phase 2 (TODO)
- 🟠 BrowserAutomationService integration
- 🟠 Credential retrieval in application submission workflow
- 🟠 Credential testing/verification queue worker
- 🟠 UI for storing/managing credentials

### Phase 3 (TODO)
- 🟠 Admin credential management interface
- 🟠 Credential expiration handling
- 🟠 OAuth token refresh support
- 🟠 Multi-factor authentication handling

---

## Security Considerations

### Threat Model

| Threat | Mitigation |
|--------|-----------|
| Database breach | All credentials encrypted, keyed to encryption profile |
| Logging plaintext | Service explicitly avoids logging credential values |
| Unauthorized access | Permission checks + user isolation checks |
| Credentials in memory | Recommend clearing with `unset()` after use |
| Credential theft during transmission | Use HTTPS only (enforced by Drupal SSL) |
| Expired credentials | Verification status tracking for lifecycle |

### Best Practices

1. **For Module Developers**:
   - Always verify you have proper permission before calling `retrieveCredential()`
   - Clear decrypted values from memory immediately after use
   - Log only metadata, never the credential values
   - Test credentials before using them in automation

2. **For Users**:
   - Never share your employer credentials
   - Use unique passwords for each ATS system
   - Update credentials if you change passwords
   - Delete credentials when no longer needed

3. **For Administrators**:
   - Monitor credential access logs for suspicious activity
   - Regularly review stored credentials
   - Implement credential rotation policies
   - Document credential management procedures

---

## Troubleshooting

### "Credential not found" after storage

**Cause**: Unique constraint violation (already exists)

**Solution**: The service updates existing credentials automatically. Check if a credential already exists:

```php
$existing = $credential_service->listUserCredentials($uid);
```

### Decryption fails

**Cause**: Encryption profile not properly configured

**Check**:
```bash
drush config:get "encrypt.encryption_profile" default_encryption_profile
drush php:eval '\Drupal::service("encryption_profile_manager")->getDefaultProfile();'
```

### "Authorization denied" during retrieval

**Cause**: Accessing another user's credentials without admin permission

**Fix**:
```bash
# Grant admin permission
drush user:add-role administrator <username>
```

---

## References

- **Service Class**: [CredentialManagementService.php](../src/Service/CredentialManagementService.php)
- **Database Schema**: [job_hunter.install](../job_hunter.install) - `_job_hunter_create_employer_credentials_table()`
- **Service Registration**: [job_hunter.services.yml](../job_hunter.services.yml) - `job_hunter.credential_management_service`
- **Integration**: [ApplicationSubmissionService.php](../src/Service/ApplicationSubmissionService.php) - `validateApplicationPrerequisites()`
- **Update Hook**: [job_hunter.install](../job_hunter.install#L205) - `job_hunter_update_9028()`
