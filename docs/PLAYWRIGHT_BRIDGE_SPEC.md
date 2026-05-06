# Playwright Bridge — Interface Specification

**Module:** job_hunter  
**Status:** 🟠 Specification — Not Yet Implemented  
**Related:** [`PHASE2_BROWSER_AUTOMATION_REQUIREMENTS.md`](PHASE2_BROWSER_AUTOMATION_REQUIREMENTS.md)

This document defines the complete interface contract between the PHP `BrowserAutomationService` and the Node.js Playwright worker. Both sides must conform to this spec.

---

## 1. Invocation

PHP invokes the bridge as a subprocess:

```php
$node_script = DRUPAL_ROOT . '/../modules/custom/job_hunter/playwright/apply.js';
$payload_file = tempnam(sys_get_temp_dir(), 'jh_apply_');
chmod($payload_file, 0600);
file_put_contents($payload_file, json_encode($payload));

$cmd = sprintf('node %s --payload-file=%s 2>&1', escapeshellarg($node_script), escapeshellarg($payload_file));
$output = shell_exec($cmd);
unlink($payload_file); // Always delete after subprocess reads it
```

**Why payload file instead of CLI arg:**
- Credentials in `argv` are visible to `ps aux` — file with 600 permissions is safer
- Avoids shell escaping issues with large JSON payloads

**Timeout enforcement in PHP:**

```php
// Use proc_open for timeout control
$proc = proc_open($cmd, $descriptors, $pipes);
$start = time();
while (proc_get_status($proc)['running']) {
    if (time() - $start > 90) {
        proc_terminate($proc);
        return ['success' => FALSE, 'reason' => 'timeout', 'outcome' => 'manual_required'];
    }
    usleep(500000); // 500ms poll
}
$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);
proc_close($proc);
```

---

## 2. Input Payload Schema

Written by PHP to `$payload_file`. Node reads and deletes the file immediately on startup.

```json
{
  "application_id": 42,
  "job_id": 107,
  "uid": 3,
  "ats_platform": "greenhouse",
  "apply_url": "https://boards.greenhouse.io/acmecorp/jobs/12345",
  "resume_pdf_path": "/var/www/html/sites/default/files/private/resumes/tailored_107_3.pdf",
  "personal_info": {
    "first_name": "Jane",
    "last_name": "Smith",
    "full_name": "Jane Smith",
    "email": "jane@example.com",
    "phone": "555-867-5309",
    "city": "New York",
    "state": "NY",
    "zip": "10001",
    "country": "US"
  },
  "profile": {
    "linkedin_url": "https://linkedin.com/in/janesmith",
    "github_url": "",
    "portfolio_url": "",
    "work_authorization": "US Citizen",
    "visa_sponsorship_required": false,
    "years_experience": 12,
    "education_level": "Bachelor's Degree",
    "current_company": "Acme Corp",
    "current_title": "Senior Director, Technology"
  },
  "salary": {
    "min": 180000,
    "max": 220000,
    "currency": "USD"
  },
  "cover_letter": "Optional cover letter text or empty string",
  "credentials": {
    "username": "",
    "password": ""
  },
  "screenshot_dir": "/var/www/html/sites/default/files/private/job_hunter/screenshots",
  "options": {
    "timeout_ms": 90000,
    "human_delay_min_ms": 300,
    "human_delay_max_ms": 1200,
    "headless": true,
    "debug": false
  }
}
```

### Field Notes

| Field | Required | Notes |
|---|---|---|
| `application_id` | Yes | Used to name screenshot files |
| `ats_platform` | Yes | Must match a known platform key or `"generic"` |
| `apply_url` | Yes | Canonical apply URL from `ApplyUrlResolverService` |
| `resume_pdf_path` | Yes | Absolute filesystem path. Node validates existence before proceeding. |
| `credentials.username/password` | No | Empty string if not applicable. Node zeros from memory after use. |
| `screenshot_dir` | Yes | Must be writable by the process user |
| `options.debug` | No | If true, runs non-headless and writes verbose logs to stderr |

---

## 3. Output Schema

Node writes a **single JSON object to stdout** on exit. All other output goes to stderr (logs, debug).

### 3.1 Success

```json
{
  "success": true,
  "outcome": "submitted",
  "ats_platform": "greenhouse",
  "apply_url": "https://boards.greenhouse.io/acmecorp/jobs/12345",
  "confirmation_text": "Thank you! Your application to Acme Corp has been received.",
  "confirmation_number": "APP-2026-884321",
  "screenshot_pre": "/var/www/html/sites/default/files/private/job_hunter/screenshots/42_1_pre.png",
  "screenshot_post": "/var/www/html/sites/default/files/private/job_hunter/screenshots/42_1_post.png",
  "fields_filled": ["first_name", "last_name", "email", "phone", "resume", "linkedin"],
  "fields_skipped": ["cover_letter"],
  "duration_ms": 12450,
  "error": null,
  "reason": null
}
```

### 3.2 Manual Required (graceful fallback)

```json
{
  "success": false,
  "outcome": "manual_required",
  "ats_platform": "workday",
  "apply_url": "https://acme.myworkdayjobs.com/jobs/12345",
  "confirmation_text": null,
  "confirmation_number": null,
  "screenshot_pre": "/path/to/42_1_pre.png",
  "screenshot_post": null,
  "fields_filled": [],
  "fields_skipped": [],
  "duration_ms": 5200,
  "error": "MFA challenge detected on login page",
  "reason": "mfa_required",
  "instructions": "Multi-factor authentication was required. Please complete login manually.",
  "field_map": {
    "legalNameSection_firstName": "Jane",
    "legalNameSection_lastName": "Smith",
    "email": "jane@example.com"
  }
}
```

### 3.3 Hard Failure

```json
{
  "success": false,
  "outcome": "failed",
  "ats_platform": "greenhouse",
  "apply_url": "https://boards.greenhouse.io/acmecorp/jobs/12345",
  "confirmation_text": null,
  "confirmation_number": null,
  "screenshot_pre": null,
  "screenshot_post": null,
  "fields_filled": [],
  "fields_skipped": [],
  "duration_ms": 90001,
  "error": "Timeout exceeded waiting for form to load",
  "reason": "timeout",
  "instructions": null,
  "field_map": {}
}
```

### 3.4 Output Schema Reference

| Field | Type | Always Present |
|---|---|---|
| `success` | bool | Yes |
| `outcome` | string: `submitted` \| `manual_required` \| `failed` | Yes |
| `ats_platform` | string | Yes |
| `apply_url` | string | Yes |
| `confirmation_text` | string \| null | Yes |
| `confirmation_number` | string \| null | Yes |
| `screenshot_pre` | string (path) \| null | Yes |
| `screenshot_post` | string (path) \| null | Yes |
| `fields_filled` | string[] | Yes |
| `fields_skipped` | string[] | Yes |
| `duration_ms` | int | Yes |
| `error` | string \| null | Yes |
| `reason` | string \| null | Yes — see reason codes below |
| `instructions` | string \| null | Yes |
| `field_map` | object | Yes (empty `{}` on success) |

### 3.5 Reason Codes

| Code | Meaning |
|---|---|
| `captcha_detected` | CAPTCHA present, cannot proceed |
| `mfa_required` | MFA challenge presented during login |
| `auth_failed` | Credentials rejected by ATS |
| `no_resume_pdf` | Resume file path does not exist or unreadable |
| `missing_required_field` | Required ATS field has no value in profile |
| `selector_not_found` | Expected CSS/ARIA selector not found — form structure changed |
| `timeout` | 90s timeout exceeded |
| `network_error` | Navigation failed, DNS error, etc. |
| `ats_page_error` | ATS JS error / 500 on their end |
| `heuristic_fill_only` | Generic form — filled what could be found, did not submit |

---

## 4. Exit Codes

| Code | Meaning |
|---|---|
| `0` | Script ran to completion (check `outcome` in JSON for success vs manual_required) |
| `1` | Unhandled exception or crash — PHP should treat as `failed` / `timeout` |
| `2` | Payload file not found or invalid JSON |
| `3` | Node dependency missing (Playwright not installed) |

**PHP handling of non-zero exit:**
```php
$exit_code = proc_close($proc);
if ($exit_code !== 0) {
    // Node crashed — return hard failure, schedule retry
    return ['success' => FALSE, 'outcome' => 'failed', 'reason' => 'node_crash'];
}
```

---

## 5. Node.js apply.js Entry Point Contract

```javascript
// apply.js
const fs = require('fs');
const path = require('path');

// 1. Read and immediately delete payload file
const args = require('minimist')(process.argv.slice(2));
const payloadPath = args['payload-file'];
if (!payloadPath || !fs.existsSync(payloadPath)) {
    process.stderr.write('ERROR: payload file not found\n');
    process.exit(2);
}
const payload = JSON.parse(fs.readFileSync(payloadPath, 'utf8'));
fs.unlinkSync(payloadPath); // Delete immediately — credentials live here

// 2. Dispatch to platform handler
const platform = payload.ats_platform;
const handler = loadHandler(platform); // platforms/{platform}.js or platforms/generic.js

// 3. Run with timeout
const result = await Promise.race([
    handler.apply(payload),
    timeoutAfter(payload.options.timeout_ms || 90000)
]);

// 4. Zero credentials from memory
payload.credentials = { username: '', password: '' };

// 5. Write result to stdout (ONLY JSON, nothing else)
process.stdout.write(JSON.stringify(result));
process.exit(0);
```

---

## 6. PHP Integration Point in BrowserAutomationService

The existing `routeByPlatform()` method currently returns `phase2_pending` for automatable platforms.

In Phase 2, replace those branches with:

```php
// In BrowserAutomationService::routeByPlatform()
if (in_array($ats_platform, self::AUTOMATABLE_PLATFORMS)) {
    return $this->runPlaywrightBridge($uid, $job_id, $application_id, $app_data, $apply_url, $ats_platform, $ats_label);
}

if ($requires_credentials && $has_credentials) {
    return $this->runPlaywrightBridge($uid, $job_id, $application_id, $app_data, $apply_url, $ats_platform, $ats_label, $credentials);
}
```

New method signature:
```php
protected function runPlaywrightBridge(
    int $uid, int $job_id, int $application_id, array $app_data,
    string $apply_url, string $ats_platform, string $ats_label,
    array $credentials = []
): array
```

This method:
1. Builds the payload array (maps `$app_data` to the payload schema above)
2. Writes payload to temp file
3. Shell-execs `node apply.js --payload-file=...` with 90s timeout via `proc_open`
4. Reads stdout, parses JSON
5. Maps Node output to the `BrowserAutomationService::processApplication()` return format
6. Returns the result (caller logs the attempt)

---

## 7. Development and Testing

### 7.1 Local Testing

```bash
# Run against a real Greenhouse URL (non-headless, debug mode)
cd web/modules/custom/job_hunter/playwright
node apply.js --payload-file=/tmp/test_payload.json

# Test payload (debug=true, headless=false)
cat > /tmp/test_payload.json << 'EOF'
{
  "application_id": 9999,
  "ats_platform": "greenhouse",
  "apply_url": "https://boards.greenhouse.io/...",
  ...
  "options": { "headless": false, "debug": true, "timeout_ms": 120000 }
}
EOF
```

### 7.2 Integration Test Checklist (per platform)

Before marking a platform as Phase 2 complete:

- [ ] Script fills all standard fields without error
- [ ] Resume PDF uploads successfully
- [ ] Pre-submit screenshot captured
- [ ] Form submitted (or dry-run mode stops before submit)
- [ ] Post-submit screenshot captured
- [ ] `confirmation_text` parsed from page
- [ ] `confirmation_number` extracted if present
- [ ] Exit code 0
- [ ] `outcome = 'submitted'` in JSON output
- [ ] CAPTCHA test: inject mock CAPTCHA → `reason = 'captcha_detected'`
- [ ] Timeout test: set `timeout_ms = 1` → `reason = 'timeout'`, exit code 0
- [ ] Missing file test: bad `resume_pdf_path` → `reason = 'no_resume_pdf'`, exit code 0

### 7.3 Dry Run Mode

Add `"options": { "dry_run": true }` to payload. Node fills the form but stops before clicking Submit. Use for integration testing without creating real applications.

---

## 8. package.json

```json
{
  "name": "job-hunter-playwright",
  "version": "1.0.0",
  "description": "Playwright automation bridge for job_hunter Drupal module",
  "main": "apply.js",
  "scripts": {
    "install-browsers": "npx playwright install chromium"
  },
  "dependencies": {
    "playwright": "^1.41.0",
    "playwright-extra": "^4.3.6",
    "puppeteer-extra-plugin-stealth": "^2.11.2",
    "minimist": "^1.2.8"
  }
}
```

---

*Last updated: 2026-02-27*
