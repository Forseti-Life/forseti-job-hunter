/**
 * job_hunter Playwright Bridge — Entry Point
 *
 * Called by PHP BrowserAutomationService::runPlaywrightBridge() as a subprocess.
 *
 * Usage:
 *   node apply.js --payload-file=/tmp/jh_apply_XXXX
 *
 * The payload file is read and immediately deleted (it contains credentials).
 * Result JSON is written to stdout. Exit code 0 = ran to completion (check
 * `outcome` in JSON). Non-zero = crash/missing deps.
 *
 * See: docs/PLAYWRIGHT_BRIDGE_SPEC.md for full interface contract.
 */

'use strict';

const fs      = require('fs');
const path    = require('path');
const args    = require('minimist')(process.argv.slice(2));

// ── 1. Read and immediately delete payload file ──────────────────────────────
const payloadPath = args['payload-file'];
if (!payloadPath || !fs.existsSync(payloadPath)) {
  process.stderr.write('ERROR: --payload-file not found: ' + payloadPath + '\n');
  process.exit(2);
}

let payload;
try {
  payload = JSON.parse(fs.readFileSync(payloadPath, 'utf8'));
} catch (e) {
  process.stderr.write('ERROR: Invalid JSON in payload file: ' + e.message + '\n');
  process.exit(2);
}

// Delete immediately — credentials live here
try { fs.unlinkSync(payloadPath); } catch (e) { /* best-effort */ }

// ── 2. Dispatch to platform handler ─────────────────────────────────────────
const platform = (payload.ats_platform || 'generic').toLowerCase();
const PLATFORM_MAP = {
  greenhouse:      './platforms/greenhouse',
  lever:           './platforms/lever',
  ashby:           './platforms/ashby',
  smartrecruiters: './platforms/smartrecruiters',
  workable:        './platforms/workable',
  workday:         './platforms/workday',
  icims:           './platforms/icims',
};

// Normalize resume_pdf_path — handlers expect a flat top-level key.
// PHP sends: { resume: { pdf_path: '/abs/path/file.pdf' } }
if (!payload.resume_pdf_path && payload.resume && payload.resume.pdf_path) {
  payload.resume_pdf_path = payload.resume.pdf_path;
}

const handlerPath = PLATFORM_MAP[platform] || './platforms/generic';
let handler;
try {
  handler = require(handlerPath);
} catch (e) {
  handler = require('./platforms/generic');
  process.stderr.write('WARN: No handler for platform "' + platform + '", using generic. ' + e.message + '\n');
}

// ── 3. Build result skeleton ─────────────────────────────────────────────────
function buildResult(overrides) {
  return Object.assign({
    success:            false,
    outcome:            'manual_required',
    ats_platform:       payload.ats_platform,
    apply_url:          payload.apply_url || '',
    confirmation_text:  null,
    confirmation_number: null,
    screenshot_pre:     null,
    screenshot_post:    null,
    fields_filled:      [],
    fields_skipped:     [],
    duration_ms:        0,
    error:              null,
    reason:             null,
    instructions:       null,
    field_map:          {},
  }, overrides);
}

// ── 4. Timeout wrapper ───────────────────────────────────────────────────────
function timeoutAfter(ms) {
  return new Promise((_, reject) =>
    setTimeout(() => reject(new Error('TIMEOUT')), ms)
  );
}

// ── 5. Run ───────────────────────────────────────────────────────────────────
const startMs = Date.now();
const timeoutMs = (payload.options && payload.options.timeout_ms) || 90000;

(async () => {
  let result;
  try {
    result = await Promise.race([
      handler.apply(payload, buildResult),
      timeoutAfter(timeoutMs),
    ]);
  } catch (e) {
    const isTimeout = e.message === 'TIMEOUT';
    process.stderr.write((isTimeout ? 'TIMEOUT' : 'ERROR') + ': ' + e.message + '\n');
    result = buildResult({
      outcome:  isTimeout ? 'manual_required' : 'failed',
      reason:   isTimeout ? 'timeout' : 'exception',
      error:    e.message,
      duration_ms: Date.now() - startMs,
    });
  }

  // Zero credentials from memory before exit
  if (payload.credentials) {
    payload.credentials.username = '';
    payload.credentials.password = '';
  }

  // Stamp duration if handler didn't set it
  if (!result.duration_ms) {
    result.duration_ms = Date.now() - startMs;
  }

  // Write result JSON to stdout (ONLY JSON here — nothing else)
  process.stdout.write(JSON.stringify(result));
  process.exit(0);
})();
