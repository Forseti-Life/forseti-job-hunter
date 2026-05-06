/**
 * workday-wizard-advance.js — Playwright stealth script for Workday wizard steps 2-7.
 *
 * Handles:
 *   Step 2 — My Information     : Verify pre-filled fields, fill gaps, click Continue.
 *   Step 3 — My Experience      : Verify pre-filled fields, fill gaps, click Continue.
 *   Step 4 — Application Questions : Screenshot & flag for manual review (questions vary per job).
 *   Step 5 — Voluntary Disclosures : Fill EEO standard disclosures, click Continue.
 *   Step 6 — Self-Identify         : Fill disability self-identification, click Continue.
 *   Step 7 — Review & Submit       : Take review screenshot, click Submit.
 *
 * Usage:
 *   node workday-wizard-advance.js \
 *     --payload-file=/tmp/jh_wz_xyz.json \
 *     --output-file=/tmp/jh_wz_out.json \
 *     [--timeout=120] \
 *     [--executable-path=/usr/bin/google-chrome]
 *
 * Payload file format:
 *   {
 *     "username": "user@example.com",
 *     "password": "secretpass",
 *     "apply_url": "https://jj.wd5.myworkdayjobs.com/en-US/JJ/job/.../apply",
 *     "target_step": "my_information",   // one of: my_information, my_experience, application_questions,
 *                                        //         voluntary_disclosures, self_identify, review_submit
 *     "profile_data": {                  // optional — used for filling missing fields
 *       "full_name": "Keith Aumiller",
 *       "first_name": "Keith",
 *       "last_name": "Aumiller",
 *       "email": "keith.aumiller@example.com",
 *       "phone": "(314) 369-0811",
 *       "city": "Philadelphia",
 *       "state": "PA",
 *       "country": "United States",
 *       "linkedin": "https://www.linkedin.com/in/keithaumiller/",
 *       "eeo_gender": "<from user profile>",
 *       "eeo_ethnicity": "<from user profile>",
 *       "eeo_veteran": "<from user profile>",
 *       "disability_status": "<from user profile>",
 *       "work_authorized_us": "<from user profile, e.g. Yes/No>",
 *       "requires_sponsorship": "<from user profile, e.g. Yes/No>",
 *       "age_18_or_older": "<from user profile, e.g. Yes/No>"
 *     },
 *     "screenshot_dir": "/var/private/forseti/job_hunter/screenshots",
 *     "application_id": 1
 *     "prevent_submit": false
 *     "review_submit_mode": "submit" // submit | save_and_continue_later | skip
 *   }
 *
 * The payload file is deleted immediately after reading.
 *
 * Output JSON:
 *   {
 *     "ok": true/false,
 *     "target_step": "my_information",
 *     "detected_page": "My Information",
 *     "page_matched": true/false,
 *     "fields_filled": ["first_name", "last_name"],
 *     "fields_skipped": ["phone"],
 *     "continue_clicked": true/false,
 *     "post_continue_url": "...",
 *     "page_title": "...",
 *     "needs_manual_review": false,
 *     "evidence": "...",
 *     "screenshots": [],
 *     "error": ""
 *   }
 */

'use strict';

const fs = require('fs');
const path = require('path');
const minimist = require('minimist');
const { launchBrowser, humanType, humanDelay, sleep, takeScreenshot } = require('./utils/stealth');

const args = minimist(process.argv.slice(2));

const PAYLOAD_FILE = args['payload-file'] || '';
const OUTPUT_FILE  = args['output-file'] || '';
const TIMEOUT      = parseInt(args['timeout'] || '120', 10) * 1000;
const EXEC_PATH    = args['executable-path'] || '';
const HEADED_ARG   = args['headed'] === true || String(args['headed'] || '').toLowerCase() === 'true' || String(args['headed'] || '') === '1';
const HEADLESS_ARG = args['headless'] === true || String(args['headless'] || '').toLowerCase() === 'true' || String(args['headless'] || '') === '1';
const HEADLESS_ENV = process.env.JOB_HUNTER_HEADLESS;

function resolveHeadlessMode() {
  if (HEADED_ARG) {
    return false;
  }
  if (HEADLESS_ARG) {
    return true;
  }
  if (HEADLESS_ENV !== undefined) {
    const v = String(HEADLESS_ENV).trim().toLowerCase();
    if (v === '0' || v === 'false' || v === 'no') {
      return false;
    }
    if (v === '1' || v === 'true' || v === 'yes') {
      return true;
    }
  }
  return false;
}

// ── Step metadata ──────────────────────────────────────────────────────────────

const STEP_PAGE_HEADINGS = {
  my_information:        ['My Information'],
  my_experience:         ['My Experience'],
  application_questions: ['Application Questions', 'Job-Specific Information'],
  voluntary_disclosures: ['Voluntary Disclosures', 'EEO Self-Identification'],
  self_identify:         ['Self Identify', 'Self-Identify', 'Disability'],
  review_submit:         ['Review', 'Review & Submit', 'Summary'],
};

const AUTO_STEP_ORDER = [
  'my_information',
  'my_experience',
  'application_questions',
  'voluntary_disclosures',
  'self_identify',
  'review_submit',
];

const MAX_EDUCATION_ENTRIES = 3;
const STRICT_EDUCATION_ENTRY_LIMIT = 1;
const EDUCATION_SCHOOL_LABELS = [
  'School or University',
  'School',
  'University',
  'Institution',
  'School Name',
  'type your school name',
  'please type your school name',
  'please type your school name and click enter',
];
const EDUCATION_DEGREE_LABELS = ['Degree', 'Field of Study', 'Major'];

function mergeUniqueTags(target, incoming) {
  if (!Array.isArray(target) || !Array.isArray(incoming) || !incoming.length) {
    return;
  }
  for (const tag of incoming) {
    if (!target.includes(tag)) {
      target.push(tag);
    }
  }
}

function normalizeEducationValue(value) {
  return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
}

function normalizeEducationSchoolComparable(value) {
  return normalizeEducationValue(value)
    .replace(/\buniversity\b/g, 'univ')
    .replace(/\bcollege\b/g, 'col')
    .replace(/\binstitute\b/g, 'inst')
    .replace(/\bof\b/g, ' ')
    .replace(/\bat\b/g, ' ')
    .replace(/[^a-z0-9 ]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function getEducationEntryTagPrefix(index) {
  return index === 0 ? 'education' : `education_${index + 1}`;
}

function buildEducationEntryList(profile) {
  const allEntries = extractEducationEntries(profile || {});
  return {
    all: allEntries,
    effective: STRICT_VISUAL_FORM_FILL ? allEntries.slice(0, STRICT_EDUCATION_ENTRY_LIMIT) : allEntries,
  };
}

async function confirmEducationSchoolVisible(page, school) {
  const needle = String(school || '').trim();
  if (!needle) {
    return false;
  }

  try {
    return await page.evaluate(({ needle }) => {
      const normalize = (value) => String(value || '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase()
        .replace(/\buniversity\b/g, 'univ')
        .replace(/\bcollege\b/g, 'col')
        .replace(/\binstitute\b/g, 'inst')
        .replace(/\bof\b/g, ' ')
        .replace(/\bat\b/g, ' ')
        .replace(/[^a-z0-9 ]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

      const schoolMatch = (observedText, expectedText) => {
        const observed = normalize(observedText);
        const expected = normalize(expectedText);
        if (!observed || !expected) {
          return false;
        }
        if (observed.includes(expected) || expected.includes(observed)) {
          return true;
        }
        const expectedTokens = Array.from(new Set(expected.split(' ').map((x) => x.trim()).filter((x) => x.length >= 2)));
        if (!expectedTokens.length) {
          return false;
        }
        const matched = expectedTokens.filter((token) => observed.includes(token));
        return (matched.length / expectedTokens.length) >= 0.6;
      };

      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const sections = Array.from(document.querySelectorAll('section, div, fieldset, li')).filter((el) => {
        if (!isVisible(el)) return false;
        const txt = String(el.innerText || '').toLowerCase();
        return txt.includes('education') || txt.includes('school') || txt.includes('university') || txt.includes('institution');
      });

      const tokenSelectors = [
        '[data-automation-id*="token" i]',
        '[data-automation-id*="pill" i]',
        '[data-automation-id*="selection" i]',
        '[data-automation-id*="selected" i]',
        '[data-automation-id*="chip" i]',
        '[data-automation-id*="tag" i]',
        '[aria-label*="remove" i]',
        '[role="option"][aria-selected="true"]',
        'input',
        'button',
        'span',
        'div',
      ];

      for (const root of sections) {
        for (const sel of tokenSelectors) {
          const nodes = Array.from(root.querySelectorAll(sel)).filter(isVisible);
          for (const node of nodes) {
            const text = String(node.value || node.textContent || node.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim();
            if (!text || text.length > 260) continue;
            if (schoolMatch(text, needle)) {
              return true;
            }
          }
        }
      }

      return false;
    }, { needle });
  } catch (_) {
    return false;
  }
}

function addVisualFillTagResult(filled, skipped, fieldKey, result) {
  if (result?.ok) {
    mergeUniqueTags(filled, [fieldKey, `${fieldKey}_visual_verified`]);
    return;
  }
  mergeUniqueTags(skipped, [fieldKey, `${fieldKey}_visual_unconfirmed`]);
}

const TACTIC_REGISTRY = Object.freeze({
  field_visual_readback: 'Every write requires visible read-back match',
  click_post_action_confirmation: 'Every click requires post-action confirmation',
});

let ACTIVE_VISUAL_AUDIT = null;

function createVisualAudit() {
  return {
    started_at: new Date().toISOString(),
    tactics: { ...TACTIC_REGISTRY },
    field_checks: [],
    click_checks: [],
  };
}

function pushFieldAudit(field, expected, observed, ok, detail = '') {
  if (!ACTIVE_VISUAL_AUDIT) return;
  ACTIVE_VISUAL_AUDIT.field_checks.push({
    ts: Date.now(),
    field: String(field || ''),
    expected: String(expected || ''),
    observed: String(observed || ''),
    ok: !!ok,
    detail: String(detail || ''),
  });
}

function pushClickAudit(step, action, ok, detail = '', meta = {}) {
  if (!ACTIVE_VISUAL_AUDIT) return;
  ACTIVE_VISUAL_AUDIT.click_checks.push({
    ts: Date.now(),
    step: String(step || ''),
    action: String(action || ''),
    ok: !!ok,
    detail: String(detail || ''),
    ...meta,
  });
}

function attachVisualAuditToResult(result, evidenceParts = []) {
  if (!ACTIVE_VISUAL_AUDIT || !result || typeof result !== 'object') return;
  const fields = ACTIVE_VISUAL_AUDIT.field_checks;
  const clicks = ACTIVE_VISUAL_AUDIT.click_checks;
  const failedFields = fields.filter((x) => !x.ok);
  const failedClicks = clicks.filter((x) => !x.ok);

  result.visual_confirmation = {
    started_at: ACTIVE_VISUAL_AUDIT.started_at,
    summary: {
      field_checks_total: fields.length,
      field_checks_passed: fields.length - failedFields.length,
      field_checks_failed: failedFields.length,
      click_checks_total: clicks.length,
      click_checks_passed: clicks.length - failedClicks.length,
      click_checks_failed: failedClicks.length,
    },
    failed_field_checks: failedFields.slice(-50),
    failed_click_checks: failedClicks.slice(-50),
    tactics: ACTIVE_VISUAL_AUDIT.tactics,
  };

  evidenceParts.push(`Visual confirmation summary: fields=${fields.length - failedFields.length}/${fields.length}, clicks=${clicks.length - failedClicks.length}/${clicks.length}`);
}

function normalizeComparable(value) {
  return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
}

function visualMatch(actual, expected) {
  const a = normalizeComparable(actual);
  const e = normalizeComparable(expected);
  if (!e) return false;
  if (a === e) return true;
  if (a.includes(e)) return true;
  if (e.includes(a) && a.length >= 2) return true;
  return false;
}

async function confirmLocatorValue(page, locator, expectedValue, fieldName, mode = 'text') {
  if (!locator) {
    pushFieldAudit(fieldName, expectedValue, '', false, 'missing-locator');
    return false;
  }

  try {
    const observed = await locator.evaluate((el) => {
      const value = ('value' in el) ? String(el.value || '') : '';
      const text = String(el.textContent || '').trim();
      const aria = String(el.getAttribute('aria-valuetext') || el.getAttribute('aria-label') || '').trim();
      const role = String(el.getAttribute('role') || '').toLowerCase();
      const checked = !!el.checked || String(el.getAttribute('aria-checked') || '').toLowerCase() === 'true';
      return { value, text, aria, role, checked };
    });

    let actual = observed.value || observed.aria || observed.text;
    let ok = false;

    if (mode === 'radio' || mode === 'checkbox') {
      const yesLike = /^(yes|true|1|checked)$/i.test(String(expectedValue || '').trim());
      const noLike = /^(no|false|0|unchecked)$/i.test(String(expectedValue || '').trim());
      if (yesLike) {
        ok = !!observed.checked;
      } else if (noLike) {
        ok = !observed.checked;
      } else {
        ok = !!observed.checked || visualMatch(actual, expectedValue);
      }
    } else {
      ok = visualMatch(actual, expectedValue);
    }

    pushFieldAudit(fieldName, expectedValue, actual, ok, mode);
    return ok;
  } catch (_) {
    pushFieldAudit(fieldName, expectedValue, '', false, 'readback-exception');
    return false;
  }
}

async function confirmQuestionContainerAnswer(page, questionNeedle, expectedValue, fieldName) {
  try {
    const ok = await page.evaluate(({ questionNeedle, expectedValue }) => {
      const q = String(questionNeedle || '').toLowerCase();
      const e = String(expectedValue || '').replace(/\s+/g, ' ').trim().toLowerCase();
      const blocks = Array.from(document.querySelectorAll('[data-automation-id="formField"], [data-automation-id="questionField"], div, section, fieldset, li'));
      const target = blocks.find((el) => (el.innerText || '').toLowerCase().includes(q));
      if (!target) return false;

      const checked = Array.from(target.querySelectorAll('input[type="radio"], input[type="checkbox"], [role="radio"], [role="checkbox"]')).some((el) => {
        const on = !!el.checked || String(el.getAttribute('aria-checked') || '').toLowerCase() === 'true';
        if (!on) return false;
        const txt = ((el.closest('label, div, section, li')?.innerText || '') + ' ' + (el.getAttribute('value') || '') + ' ' + (el.getAttribute('aria-label') || '')).replace(/\s+/g, ' ').trim().toLowerCase();
        return txt.includes(e);
      });
      if (checked) return true;

      const values = Array.from(target.querySelectorAll('input, textarea, select, [role="combobox"], [contenteditable="true"]'))
        .map((el) => {
          const v = ('value' in el) ? String(el.value || '') : '';
          const t = String(el.textContent || '').trim();
          const a = String(el.getAttribute('aria-valuetext') || el.getAttribute('aria-label') || '').trim();
          return `${v} ${t} ${a}`.replace(/\s+/g, ' ').trim().toLowerCase();
        })
        .filter(Boolean);

      return values.some((v) => v.includes(e));
    }, { questionNeedle, expectedValue });

    pushFieldAudit(fieldName, expectedValue, ok ? 'container-confirmed' : 'container-not-confirmed', ok, 'question-container');
    return ok;
  } catch (_) {
    pushFieldAudit(fieldName, expectedValue, '', false, 'container-readback-exception');
    return false;
  }
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function writeResult(result) {
  const json = JSON.stringify(result, null, 2);
  if (OUTPUT_FILE) {
    fs.writeFileSync(OUTPUT_FILE, json, 'utf8');
  } else {
    process.stdout.write(json + '\n');
  }
}

function fail(msg) {
  writeResult({
    ok: false,
    target_step: '',
    detected_page: '',
    page_matched: false,
    fields_filled: [],
    fields_skipped: [],
    continue_clicked: false,
    post_continue_url: '',
    page_title: '',
    needs_manual_review: false,
    evidence: '',
    screenshots: [],
    error: msg,
  });
  process.exit(1);
}

// ── Read payload ───────────────────────────────────────────────────────────────

if (!PAYLOAD_FILE || !fs.existsSync(PAYLOAD_FILE)) {
  fail('Payload file not found: ' + PAYLOAD_FILE);
}

let payload;
try {
  payload = JSON.parse(fs.readFileSync(PAYLOAD_FILE, 'utf8'));
  fs.unlinkSync(PAYLOAD_FILE);
} catch (e) {
  try { fs.unlinkSync(PAYLOAD_FILE); } catch (_) {}
  fail('Failed to parse payload file: ' + e.message);
}

const {
  username = '',
  password = '',
  apply_url = '',
  target_step = '',
  start_step = 'my_information',
  profile_data = {},
  resume_pdf_path = '',
  screenshot_dir = '',
  application_id = 0,
  prevent_submit = false,
  review_submit_mode = '',
} = payload;

const PREVENT_SUBMIT = prevent_submit === true || String(prevent_submit || '').toLowerCase() === 'true' || String(prevent_submit || '') === '1';
const REVIEW_SUBMIT_MODE_RAW = String(review_submit_mode || '').trim().toLowerCase();
const REVIEW_SUBMIT_MODE = REVIEW_SUBMIT_MODE_RAW === 'save_and_continue_later' || REVIEW_SUBMIT_MODE_RAW === 'skip' || REVIEW_SUBMIT_MODE_RAW === 'submit'
  ? REVIEW_SUBMIT_MODE_RAW
  : (PREVENT_SUBMIT ? 'skip' : 'submit');
const STRICT_VISUAL_FORM_FILL = true;

if (!username || !password || !apply_url) {
  fail('Payload must include username, password, and apply_url.');
}

const VALID_TARGETS = [...Object.keys(STEP_PAGE_HEADINGS), 'wizard_auto', 'wizard_validate'];
if (!target_step || !VALID_TARGETS.includes(target_step)) {
  fail('Invalid target_step: ' + target_step + '. Must be one of: ' + VALID_TARGETS.join(', '));
}

if ((target_step === 'wizard_auto' || target_step === 'wizard_validate') && !AUTO_STEP_ORDER.includes(start_step)) {
  fail('Invalid start_step for ' + target_step + ': ' + start_step + '. Must be one of: ' + AUTO_STEP_ORDER.join(', '));
}

// ── Workday URL helpers ────────────────────────────────────────────────────────

function parseWorkdayUrls(applyUrl) {
  const url = new URL(applyUrl);
  const parts = url.pathname.split('/').filter(Boolean);
  const locale = parts[0] || 'en-US';
  const siteId = parts[1] || '';
  const base   = `${url.protocol}//${url.host}`;

  return {
    base,
    locale,
    siteId,
    loginUrl:  `${base}/${locale}/${siteId}/login`,
    signInUrl: `${base}/${locale}/${siteId}/login?redirect=%2F${locale}%2F${siteId}%2FuserHome`,
  };
}

// ── Field filling helpers ──────────────────────────────────────────────────────

/**
 * Try to fill a Workday form field by data-automation-id, only if currently empty.
 * Returns the field name if filled, null if skipped/failed.
 */
async function fillFieldIfEmpty(page, automationId, value, fieldName) {
  if (!value) return null;
  try {
    const selector = `[data-automation-id="${automationId}"]`;
    const el = page.locator(selector).first();
    await el.waitFor({ state: 'attached', timeout: 3000 });

    // Check if it's an input/textarea.
    const tagName = await el.evaluate(node => node.tagName.toLowerCase());
    if (tagName === 'input' || tagName === 'textarea') {
      const currentVal = await el.inputValue({ timeout: 2000 });
      if (currentVal && currentVal.trim().length > 0) {
        const isMatch = visualMatch(currentVal, value);
        if (isMatch) {
          pushFieldAudit(fieldName, value, currentVal, true, 'prepopulated-match');
          process.stderr.write(`INFO: Field "${fieldName}" already matches expected value — skipping.\n`);
          return null;
        }

        pushFieldAudit(fieldName, value, currentVal, false, 'prepopulated-mismatch-correcting');
        process.stderr.write(`WARN: Field "${fieldName}" has mismatched prefilled value "${currentVal.substring(0, 30)}..." — correcting.\n`);
      }
      await el.click({ timeout: 2000 });
      await humanDelay(200, 400);
      await el.fill('');
      await humanType(page, selector, value);
      const confirmed = await confirmLocatorValue(page, el, value, fieldName, 'text');
      if (confirmed) {
        process.stderr.write(`INFO: Filled field "${fieldName}" with "${value.substring(0, 30)}..." (verified)\n`);
        return fieldName;
      }
      process.stderr.write(`WARN: Field "${fieldName}" write did not visually confirm.\n`);
      return null;
    }
    pushFieldAudit(fieldName, value, '', false, 'unsupported-field-tag');
    process.stderr.write(`WARN: Field "${fieldName}" is not an input/textarea; visual verification unavailable.\n`);
  } catch (e) {
    pushFieldAudit(fieldName, value, '', false, `field-access-error:${automationId}`);
    process.stderr.write(`WARN: Could not fill field "${fieldName}" (${automationId}): ${e.message}\n`);
  }
  return null;
}

async function ensureFieldMatchesExpected(page, automationId, expectedValue, fieldName) {
  if (!expectedValue) return null;
  try {
    const selector = `[data-automation-id="${automationId}"]`;
    const el = page.locator(selector).first();
    await el.waitFor({ state: 'attached', timeout: 3000 });

    const tagName = await el.evaluate((node) => node.tagName.toLowerCase());
    if (tagName !== 'input' && tagName !== 'textarea') {
      return await fillFieldIfEmpty(page, automationId, expectedValue, fieldName);
    }

    const currentVal = await el.inputValue({ timeout: 2000 }).catch(() => '');
    if (currentVal && currentVal.trim().length > 0) {
      const matches = visualMatch(currentVal, expectedValue);
      if (matches) {
        pushFieldAudit(fieldName, expectedValue, currentVal, true, 'prepopulated-match');
        process.stderr.write(`INFO: Field "${fieldName}" already matches expected value.\n`);
        return null;
      }

      const likelyInvalid = /\d/.test(String(currentVal || '').trim()) || String(currentVal || '').trim().length < 2;
      if (!likelyInvalid) {
        pushFieldAudit(fieldName, expectedValue, currentVal, false, 'prepopulated-mismatch');
      }

      await el.click({ timeout: 2000 });
      await humanDelay(120, 260);
      await el.fill('');
      await humanType(page, selector, expectedValue);
      const corrected = await confirmLocatorValue(page, el, expectedValue, fieldName, 'text');
      if (corrected) {
        process.stderr.write(`INFO: Corrected prefilled field "${fieldName}" from "${String(currentVal).substring(0, 30)}" to expected value (verified).\n`);
        return fieldName;
      }
      process.stderr.write(`WARN: Failed to correct mismatched prefilled field "${fieldName}".\n`);
      return null;
    }

    return await fillFieldIfEmpty(page, automationId, expectedValue, fieldName);
  } catch (e) {
    pushFieldAudit(fieldName, expectedValue, '', false, `ensure-match-error:${automationId}`);
    process.stderr.write(`WARN: Could not validate/correct field "${fieldName}" (${automationId}): ${e.message}\n`);
    return null;
  }
}

async function ensureLocatorMatchesExpected(page, locator, expectedValue, fieldName) {
  if (!locator || !expectedValue) return null;
  try {
    await locator.waitFor({ state: 'visible', timeout: 2500 });
    const tagName = await locator.evaluate((node) => node.tagName.toLowerCase());
    if (tagName !== 'input' && tagName !== 'textarea') {
      pushFieldAudit(fieldName, expectedValue, '', false, 'unsupported-locator-tag');
      return null;
    }

    const currentVal = await locator.inputValue({ timeout: 1800 }).catch(() => '');
    if ((currentVal || '').trim().length > 0 && visualMatch(currentVal, expectedValue)) {
      pushFieldAudit(fieldName, expectedValue, currentVal, true, 'prepopulated-match');
      return null;
    }

    if ((currentVal || '').trim().length > 0) {
      pushFieldAudit(fieldName, expectedValue, currentVal, true, 'prepopulated-mismatch-detected-correcting');
    }

    await locator.click({ timeout: 1500 });
    await humanDelay(120, 260);
    await locator.fill('');
    await page.keyboard.type(String(expectedValue), { delay: 14 });
    const corrected = await confirmLocatorValue(page, locator, expectedValue, fieldName, 'text');
    if (corrected) {
      process.stderr.write(`INFO: Corrected legal name field "${fieldName}" (verified).\n`);
      return fieldName;
    }

    return null;
  } catch (e) {
    pushFieldAudit(fieldName, expectedValue, '', false, `ensure-locator-error:${e.message}`);
    return null;
  }
}

async function hasLegalNameFieldsVisible(page) {
  const probes = [
    '[data-automation-id="legalNameSection_firstName"]',
    '[data-automation-id="legalNameSection_lastName"]',
    'input[id*="firstName" i]',
    'input[id*="lastName" i]',
    'input[name*="first" i]',
    'input[name*="last" i]',
    'input[autocomplete="given-name"]',
    'input[autocomplete="family-name"]',
  ];
  for (const sel of probes) {
    try {
      if (await page.locator(sel).first().isVisible({ timeout: 350 })) {
        return true;
      }
    } catch (_) {}
  }
  return false;
}

async function attemptReachMyInformationForLegalName(page) {
  if (await hasLegalNameFieldsVisible(page)) {
    return true;
  }

  const evidenceSink = [];
  for (let i = 0; i < 4; i++) {
    const backed = await clickBackButton(page, evidenceSink);
    if (!backed) {
      break;
    }
    await humanDelay(700, 1300);
    if (await hasLegalNameFieldsVisible(page)) {
      process.stderr.write(`INFO: Reached legal name context after ${i + 1} Back action(s).\n`);
      return true;
    }
  }

  return false;
}

async function ensureLegalNameField(page, expectedValue, fieldName) {
  if (!expectedValue) return null;

  const selectors = fieldName === 'first_name'
    ? [
      '[data-automation-id="legalNameSection_firstName"]',
      'input[id*="firstName" i]',
      'input[name*="first" i]',
      'input[autocomplete="given-name"]',
      'input[aria-label*="first" i]',
    ]
    : [
      '[data-automation-id="legalNameSection_lastName"]',
      'input[id*="lastName" i]',
      'input[name*="last" i]',
      'input[autocomplete="family-name"]',
      'input[aria-label*="last" i]',
    ];

  for (const sel of selectors) {
    try {
      const locator = page.locator(sel).first();
      await locator.waitFor({ state: 'visible', timeout: 700 });
      return await ensureLocatorMatchesExpected(page, locator, expectedValue, fieldName);
    } catch (_) {}
  }

  const labelNeedle = fieldName === 'first_name' ? 'first name' : 'last name';
  try {
    const xpath = `xpath=(//label[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), "${labelNeedle}")]/following::input[1])[1]`;
    const locator = page.locator(xpath).first();
    await locator.waitFor({ state: 'visible', timeout: 900 });
    return await ensureLocatorMatchesExpected(page, locator, expectedValue, fieldName);
  } catch (_) {}

  pushFieldAudit(fieldName, expectedValue, '', false, 'legal-name-field-not-found');
  return null;
}

/**
 * Try to select a dropdown option in Workday's custom dropdown component.
 */
async function selectDropdownOption(page, automationId, optionText, fieldName) {
  if (!optionText) return null;
  try {
    const selector = `[data-automation-id="${automationId}"]`;
    const el = page.locator(selector).first();
    await el.waitFor({ state: 'visible', timeout: 3000 });

    // Check if already has the correct value.
    const currentText = await el.textContent({ timeout: 2000 });
    if (currentText && currentText.includes(optionText)) {
      pushFieldAudit(fieldName, optionText, currentText, true, 'prepopulated-match-dropdown');
      process.stderr.write(`INFO: Dropdown "${fieldName}" already set to "${optionText}" — skipping.\n`);
      return null;
    }

    // Click to open dropdown.
    await el.click({ timeout: 3000 });
    await humanDelay(500, 1000);

    // Select the option.
    const option = page.locator(`[data-automation-id="promptOption"]:has-text("${optionText}")`).first();
    await option.waitFor({ state: 'visible', timeout: 5000 });
    await option.click({ timeout: 3000 });
    await humanDelay(300, 600);
    const confirmed = await confirmLocatorValue(page, el, optionText, fieldName, 'dropdown');
    if (confirmed) {
      process.stderr.write(`INFO: Selected dropdown "${fieldName}" → "${optionText}" (verified)\n`);
      return fieldName;
    }
    process.stderr.write(`WARN: Dropdown "${fieldName}" selection did not visually confirm.\n`);
    return null;
  } catch (e) {
    pushFieldAudit(fieldName, optionText, '', false, `dropdown-access-error:${automationId}`);
    process.stderr.write(`WARN: Could not select dropdown "${fieldName}" (${automationId}): ${e.message}\n`);
    return null;
  }
}

/**
 * Select a radio button option in Workday.
 */
async function selectRadioOption(page, labelText, fieldName) {
  if (!labelText) return null;
  try {
    // Workday uses div/label combos with data-automation-id, or standard radio patterns.
    const selectors = [
      `label:has-text("${labelText}")`,
      `[data-automation-id="radioBtn"]:has-text("${labelText}")`,
      `div[role="radio"]:has-text("${labelText}")`,
      `input[type="radio"] + label:has-text("${labelText}")`,
    ];

    for (const sel of selectors) {
      try {
        const el = page.locator(sel).first();
        await el.waitFor({ state: 'visible', timeout: 3000 });
        await el.click({ timeout: 2000 });
        await humanDelay(300, 500);
        const confirmed = await confirmLocatorValue(page, el, labelText, fieldName, 'radio');
        if (confirmed) {
          process.stderr.write(`INFO: Selected radio "${fieldName}" → "${labelText}" via ${sel} (verified)\n`);
          return fieldName;
        }
      } catch (_) {
        continue;
      }
    }
  } catch (e) {
    process.stderr.write(`WARN: Could not select radio "${fieldName}": ${e.message}\n`);
  }
  pushFieldAudit(fieldName, labelText, '', false, 'radio-not-confirmed');
  return null;
}

/**
 * Click a checkbox by label text if not already checked.
 */
async function checkCheckboxByLabel(page, labelText, fieldName) {
  if (!labelText) return null;
  try {
    const checkbox = page.locator(`label:has-text("${labelText}") input[type="checkbox"], [data-automation-id="checkboxPanel"]:has-text("${labelText}") input[type="checkbox"]`).first();
    const isChecked = await checkbox.isChecked({ timeout: 3000 });
    if (!isChecked) {
      await checkbox.check({ timeout: 3000 });
      const confirmed = await confirmLocatorValue(page, checkbox, 'checked', fieldName, 'checkbox');
      if (confirmed) {
        process.stderr.write(`INFO: Checked checkbox "${fieldName}" (verified)\n`);
        return fieldName;
      }
      return null;
    }
    process.stderr.write(`INFO: Checkbox "${fieldName}" already checked — skipping.\n`);
    pushFieldAudit(fieldName, 'checked', 'checked', true, 'prepopulated-match-checkbox');
  } catch (e) {
    pushFieldAudit(fieldName, 'checked', '', false, 'checkbox-not-confirmed');
    process.stderr.write(`WARN: Could not check "${fieldName}": ${e.message}\n`);
  }
  return null;
}

async function checkRequiredAgreementCheckboxes(page, fieldPrefix = 'agreement_checkbox') {
  const filled = [];
  try {
    const agreementCheckboxes = page.locator('[data-automation-id="agreementCheckbox"] input[type="checkbox"], [data-automation-id*="agreement" i] input[type="checkbox"], label:has-text("I certify") input[type="checkbox"], label:has-text("I agree") input[type="checkbox"], label:has-text("Terms") input[type="checkbox"], label:has-text("Consent") input[type="checkbox"], input[type="checkbox"][required]');
    const count = await agreementCheckboxes.count();
    for (let i = 0; i < count; i++) {
      try {
        const box = agreementCheckboxes.nth(i);
        await box.scrollIntoViewIfNeeded({ timeout: 1800 }).catch(() => {});
        const isChecked = await box.isChecked({ timeout: 1200 }).catch(() => false);
        if (!isChecked) {
          await box.check({ timeout: 2200 }).catch(async () => {
            await box.click({ timeout: 2200, force: true });
          });
        }
        const after = await box.isChecked({ timeout: 1200 }).catch(() => false);
        if (after) {
          filled.push(`${fieldPrefix}_${i + 1}`);
        }
      } catch (_) {}
    }
  } catch (_) {}
  return filled;
}

async function completeSelfIdentifyRequiredControls(page, profile) {
  const filled = [];
  const fullName = String(profile.full_name || '').trim();
  const today = new Date();
  const mm = String(today.getMonth() + 1).padStart(2, '0');
  const dd = String(today.getDate()).padStart(2, '0');
  const yyyy = String(today.getFullYear());
  const dateText = `${mm}/${dd}/${yyyy}`;

  try {
    const chosen = await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const r = el.getBoundingClientRect();
        const s = window.getComputedStyle(el);
        return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
      };
      const candidates = Array.from(document.querySelectorAll('label, [role="radio"], [data-automation-id="radioBtn"], button'))
        .filter(isVisible);
      const find = (needles) => candidates.find((el) => {
        const t = (el.textContent || '').toLowerCase().replace(/\s+/g, ' ').trim();
        return needles.some((n) => t.includes(n));
      });
      const target = find(['i don\'t wish to answer', 'prefer not to answer', 'do not wish to answer', 'decline to answer'])
        || find(['no'])
        || null;
      if (target) {
        target.click();
        return true;
      }
      return false;
    }).catch(() => false);
    if (chosen) filled.push('self_identify_radio_fallback');
  } catch (_) {}

  const maybeFillByNeedle = async (needle, value, field) => {
    if (!value) return;
    const r = await answerQuestionSmart(page, needle, value, field, 'text');
    if (r && !filled.includes(field)) filled.push(field);
  };

  await maybeFillByNeedle('name', fullName, 'self_identify_name');
  await maybeFillByNeedle('signature', fullName, 'self_identify_signature');
  await maybeFillByNeedle('date', dateText, 'self_identify_date');

  const agreements = await checkRequiredAgreementCheckboxes(page, 'self_identify_agreement');
  for (const a of agreements) {
    if (!filled.includes(a)) filled.push(a);
  }

  return filled;
}

async function answerRadioInQuestion(page, questionText, answerText, fieldName) {
  if (!questionText || !answerText) return null;
  try {
    const container = page.locator(`[data-automation-id="formField"]:has-text("${questionText}")`).first();
    await container.waitFor({ state: 'visible', timeout: 2500 });
    const choices = [
      `label:has-text("${answerText}")`,
      `[data-automation-id="radioBtn"]:has-text("${answerText}")`,
      `div[role="radio"]:has-text("${answerText}")`,
    ];
    for (const sel of choices) {
      try {
        const el = container.locator(sel).first();
        await el.waitFor({ state: 'visible', timeout: 1200 });
        await el.click({ timeout: 1800, force: true });
        const confirmed = await confirmQuestionContainerAnswer(page, questionText, answerText, fieldName);
        if (confirmed) {
          process.stderr.write(`INFO: Answered question "${fieldName}" with "${answerText}" (verified)\n`);
          return fieldName;
        }
      } catch (_) {}
    }
  } catch (_) {}
  return null;
}

async function answerTextInQuestion(page, questionText, value, fieldName) {
  if (!questionText || !value) return null;
  try {
    const container = page.locator(`[data-automation-id="formField"]:has-text("${questionText}")`).first();
    await container.waitFor({ state: 'visible', timeout: 2500 });
    const field = container.locator('input[type="text"], input[type="email"], textarea, [contenteditable="true"]').first();
    await field.waitFor({ state: 'visible', timeout: 1500 });
    try {
      const tag = await field.evaluate((el) => el.tagName.toLowerCase());
      if (tag === 'input' || tag === 'textarea') {
        const currentVal = await field.inputValue({ timeout: 1000 }).catch(() => '');
        if ((currentVal || '').trim().length > 0 && visualMatch(currentVal, value)) {
          pushFieldAudit(fieldName, value, currentVal, true, 'prepopulated-match');
          return fieldName;
        }
        await field.fill('');
        await humanType(page, 'input:focus, textarea:focus', String(value));
      } else {
        await field.click({ timeout: 1200 });
        await page.keyboard.type(String(value), { delay: 15 });
      }
    } catch (_) {
      await field.click({ timeout: 1200 });
      await page.keyboard.type(String(value), { delay: 15 });
    }
    const confirmed = await confirmLocatorValue(page, field, value, fieldName, 'text');
    if (confirmed) {
      process.stderr.write(`INFO: Filled question "${fieldName}" (verified)\n`);
      return fieldName;
    }
    return null;
  } catch (_) {}
  return null;
}

async function answerDropdownInQuestion(page, questionText, optionText, fieldName) {
  if (!questionText || !optionText) return null;
  try {
    const container = page.locator(`[data-automation-id="formField"]:has-text("${questionText}")`).first();
    await container.waitFor({ state: 'visible', timeout: 2500 });
    const openers = [
      '[data-automation-id="promptIcon"]',
      '[data-automation-id="dropdown"]',
      '[role="combobox"]',
      'input[aria-haspopup="listbox"]',
      'button[aria-haspopup="listbox"]',
    ];
    for (const opener of openers) {
      try {
        const el = container.locator(opener).first();
        await el.waitFor({ state: 'visible', timeout: 1000 });
        await el.click({ timeout: 1500, force: true });
        break;
      } catch (_) {}
    }

    const optionCandidates = [
      `[data-automation-id="promptOption"]:has-text("${optionText}")`,
      `li[role="option"]:has-text("${optionText}")`,
      `div[role="option"]:has-text("${optionText}")`,
    ];
    for (const sel of optionCandidates) {
      try {
        const opt = page.locator(sel).first();
        await opt.waitFor({ state: 'visible', timeout: 1800 });
        await opt.click({ timeout: 1800, force: true });
        const confirmed = await confirmQuestionContainerAnswer(page, questionText, optionText, fieldName);
        if (confirmed) {
          process.stderr.write(`INFO: Selected question dropdown "${fieldName}" → "${optionText}" (verified)\n`);
          return fieldName;
        }
      } catch (_) {}
    }

    try {
      const input = container.locator('input').first();
      await input.waitFor({ state: 'visible', timeout: 1000 });
      await input.fill('');
      await input.type(String(optionText), { delay: 12 });
      await page.keyboard.press('Enter');
      const confirmed = await confirmQuestionContainerAnswer(page, questionText, optionText, fieldName);
      if (confirmed) {
        process.stderr.write(`INFO: Typed question dropdown "${fieldName}" → "${optionText}" (verified)\n`);
        return fieldName;
      }
    } catch (_) {}
  } catch (_) {}
  return null;
}

async function answerQuestionByDomFallback(page, questionNeedle, answerValue, fieldName) {
  if (!questionNeedle || !answerValue) return null;
  try {
    const ok = await page.evaluate(({ questionNeedle, answerValue }) => {
      const q = String(questionNeedle).toLowerCase();
      const a = String(answerValue);
      const blocks = Array.from(document.querySelectorAll('div, fieldset, section, li'));
      const target = blocks.find((el) => {
        const txt = (el.innerText || '').toLowerCase();
        if (!txt.includes(q)) return false;
        return !!el.querySelector('input, textarea, select, [role="radio"], [role="combobox"], [data-automation-id*="prompt"]');
      });
      if (!target) return false;

      const radios = Array.from(target.querySelectorAll('label, [role="radio"], [data-automation-id="radioBtn"]'));
      const radioMatch = radios.find((el) => (el.innerText || '').trim().toLowerCase() === a.trim().toLowerCase());
      if (radioMatch) {
        radioMatch.click();
        return true;
      }

      const select = target.querySelector('select');
      if (select) {
        const opt = Array.from(select.options || []).find((o) => (o.text || '').toLowerCase().includes(a.toLowerCase()) || String(o.value || '').toLowerCase().includes(a.toLowerCase()));
        if (opt) {
          select.value = opt.value;
          select.dispatchEvent(new Event('input', { bubbles: true }));
          select.dispatchEvent(new Event('change', { bubbles: true }));
          return true;
        }
      }

      const input = target.querySelector('input[type="text"], input[type="email"], textarea, input:not([type])');
      if (input) {
        if ((input.value || '').trim().length === 0) {
          input.focus();
          input.value = a;
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        return true;
      }

      const combo = target.querySelector('[role="combobox"], input[aria-haspopup="listbox"], [data-automation-id*="prompt"]');
      if (combo) {
        combo.click();
        return false;
      }

      return false;
    }, { questionNeedle, answerValue });

    if (ok) {
      const confirmed = await confirmQuestionContainerAnswer(page, questionNeedle, answerValue, fieldName);
      if (confirmed) {
        process.stderr.write(`INFO: Answered question via DOM fallback: ${fieldName} (verified)\n`);
        return fieldName;
      }
    }
  } catch (_) {}
  return null;
}

async function answerQuestionByXPathContainer(page, questionNeedle, answerValue, fieldName, mode = 'text') {
  if (!questionNeedle || !answerValue) return null;
  const needle = String(questionNeedle).toLowerCase();
  const value = String(answerValue).trim();
  const constrainedXpath = `xpath=(//*[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), "${needle}")]/ancestor::*[@data-automation-id='formField' or @data-automation-id='questionField'][1])[1]`;
  const fallbackXpath = `xpath=(//*[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), "${needle}")]/ancestor::*[.//input or .//*[@role='combobox'] or .//select][1])[1]`;

  try {
    let container = page.locator(constrainedXpath).first();
    let ok = await container.isVisible({ timeout: 1000 }).catch(() => false);
    if (!ok) {
      container = page.locator(fallbackXpath).first();
      await container.waitFor({ state: 'visible', timeout: 1800 });
    }

    if (mode === 'radio') {
      const radioCandidates = [
        `label:has-text("${value}")`,
        `[data-automation-id="radioBtn"]:has-text("${value}")`,
        `[role="radio"]:has-text("${value}")`,
        `button:has-text("${value}")`,
      ];
      for (const sel of radioCandidates) {
        try {
          const el = container.locator(sel).first();
          await el.waitFor({ state: 'visible', timeout: 1000 });
          await el.click({ timeout: 1500, force: true });
          const confirmed = await confirmQuestionContainerAnswer(page, questionNeedle, value, fieldName);
          if (confirmed) {
            process.stderr.write(`INFO: XPath-resolved radio answer for ${fieldName} (verified)\n`);
            return fieldName;
          }
        } catch (_) {}
      }
      return null;
    }

    if (mode === 'dropdown') {
      const comboOpeners = [
        '[role="combobox"]',
        'input[aria-haspopup="listbox"]',
        '[data-automation-id*="prompt"]',
        '[data-automation-id="promptIcon"]',
        'select',
      ];

      for (const sel of comboOpeners) {
        try {
          const opener = container.locator(sel).first();
          await opener.waitFor({ state: 'visible', timeout: 1000 });
          const tag = await opener.evaluate((el) => el.tagName.toLowerCase());
          if (tag === 'select') {
            const chosen = await opener.evaluate((el, val) => {
              const options = Array.from(el.options || []);
              const v = String(val).toLowerCase();
              const hit = options.find((o) => String(o.text || '').toLowerCase().includes(v) || String(o.value || '').toLowerCase().includes(v));
              if (!hit) return false;
              el.value = hit.value;
              el.dispatchEvent(new Event('input', { bubbles: true }));
              el.dispatchEvent(new Event('change', { bubbles: true }));
              return true;
            }, value);
            if (chosen) {
              const confirmed = await confirmQuestionContainerAnswer(page, questionNeedle, value, fieldName);
              if (confirmed) {
                process.stderr.write(`INFO: XPath-resolved select answer for ${fieldName} (verified)\n`);
                return fieldName;
              }
            }
            continue;
          }

          await opener.click({ timeout: 1500, force: true });
          await humanDelay(150, 350);

          const options = [
            `[data-automation-id="promptOption"]:has-text("${value}")`,
            `li[role="option"]:has-text("${value}")`,
            `div[role="option"]:has-text("${value}")`,
          ];
          for (const optSel of options) {
            try {
              const opt = page.locator(optSel).first();
              await opt.waitFor({ state: 'visible', timeout: 1000 });
              await opt.click({ timeout: 1500, force: true });
              const confirmed = await confirmQuestionContainerAnswer(page, questionNeedle, value, fieldName);
              if (confirmed) {
                process.stderr.write(`INFO: XPath-resolved dropdown answer for ${fieldName} (verified)\n`);
                return fieldName;
              }
            } catch (_) {}
          }

          // Type + Enter fallback for combobox inputs.
          try {
            const input = container.locator('input').first();
            await input.waitFor({ state: 'visible', timeout: 1000 });
            await input.click({ timeout: 1200 });
            await input.fill('');
            await input.type(value, { delay: 15 });
            await page.keyboard.press('Enter');
            const confirmed = await confirmQuestionContainerAnswer(page, questionNeedle, value, fieldName);
            if (confirmed) {
              process.stderr.write(`INFO: XPath-typed dropdown answer for ${fieldName} (verified)\n`);
              return fieldName;
            }
          } catch (_) {}
        } catch (_) {}
      }
      return null;
    }

    // text mode
    const input = container.locator('input[type="text"], input[type="email"], textarea, input:not([type])').first();
    await input.waitFor({ state: 'visible', timeout: 1000 });
    const currentVal = await input.inputValue({ timeout: 800 }).catch(() => '');
    if ((currentVal || '').trim().length === 0 || !visualMatch(currentVal, value)) {
      await input.click({ timeout: 1000 });
      await input.fill('');
      await input.type(value, { delay: 15 });
      await input.dispatchEvent('change');
    }
    const confirmed = await confirmLocatorValue(page, input, value, fieldName, 'text');
    if (confirmed) {
      process.stderr.write(`INFO: XPath-resolved text answer for ${fieldName} (verified)\n`);
      return fieldName;
    }
    return null;
  } catch (_) {
    return null;
  }
}

function deriveApplicationQuestionAnswers(profile) {
  const read = (...keys) => {
    for (const key of keys) {
      const value = profile?.[key];
      if (value !== undefined && value !== null && String(value).trim() !== '') {
        return String(value).trim();
      }
    }
    return '';
  };

  const yesNo = (value) => {
    const v = String(value || '').trim().toLowerCase();
    if (!v) return '';
    if (['yes', 'y', 'true', '1', 'us_citizen', 'authorized'].includes(v)) return 'Yes';
    if (['no', 'n', 'false', '0'].includes(v)) return 'No';
    return String(value).trim();
  };

  const workAuth = yesNo(read('work_authorized_us', 'us_work_authorized', 'work_authorization'));
  const sponsorship = yesNo(read('requires_sponsorship'));
  const relocate = yesNo(read('willing_to_relocate', 'relocation_willing'));
  const restrictive = yesNo(read('restrictive_agreement', 'non_compete_agreement', 'agreement_restriction')) || 'No';
  const salary = read('salary_expectation', 'salary_change_minimum', 'salary_min', 'expected_salary', 'desired_salary');
  const years = read('years_experience', 'experience_years', 'relevant_years_experience');
  const english = read('english_proficiency', 'language_proficiency_english', 'english_level') || 'Fluent';

  return {
    work_authorized_us: workAuth,
    requires_sponsorship: sponsorship,
    willing_to_relocate: relocate,
    restrictive_agreement: restrictive,
    salary_expectation: salary,
    years_experience: years,
    english_proficiency: english,
  };
}

async function answerQuestionSmart(page, questionNeedle, answerValue, fieldName, mode = 'text') {
  if (!questionNeedle || !answerValue) return null;

  const orderedModes = [mode, 'radio', 'dropdown', 'text'].filter((v, i, a) => a.indexOf(v) === i);

  for (const m of orderedModes) {
    await clickErrorLinkAndAnswer(page, questionNeedle, answerValue, m);

    if (m === 'radio') {
      const r = await answerRadioInQuestion(page, questionNeedle, answerValue, fieldName)
        || await answerQuestionByXPathContainer(page, questionNeedle, answerValue, fieldName, 'radio')
        || await answerQuestionByDomFallback(page, questionNeedle, answerValue, fieldName);
      if (r) return r;
      continue;
    }

    if (m === 'dropdown') {
      const r = await answerDropdownInQuestion(page, questionNeedle, answerValue, fieldName)
        || await answerQuestionByXPathContainer(page, questionNeedle, answerValue, fieldName, 'dropdown')
        || await answerTextInQuestion(page, questionNeedle, answerValue, fieldName)
        || await answerQuestionByDomFallback(page, questionNeedle, answerValue, fieldName);
      if (r) return r;
      continue;
    }

    const r = await answerTextInQuestion(page, questionNeedle, answerValue, fieldName)
      || await answerQuestionByXPathContainer(page, questionNeedle, answerValue, fieldName, 'text')
      || await answerQuestionByDomFallback(page, questionNeedle, answerValue, fieldName);
    if (r) return r;
  }

  return null;
}

async function captureApplicationQuestionSnapshot(page) {
  try {
    return await page.evaluate(() => {
      const visible = (el) => {
        if (!el) return false;
        const r = el.getBoundingClientRect();
        const s = window.getComputedStyle(el);
        return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
      };

      const fields = Array.from(document.querySelectorAll('[data-automation-id="formField"], [data-automation-id="questionField"]')).filter(visible);
      return fields.slice(0, 20).map((field) => {
        const text = (field.innerText || '').replace(/\s+/g, ' ').trim();
        const radios = Array.from(field.querySelectorAll('label, [role="radio"], [data-automation-id="radioBtn"]'))
          .filter(visible)
          .map((el) => (el.innerText || '').replace(/\s+/g, ' ').trim())
          .filter(Boolean)
          .slice(0, 10);
        const selects = Array.from(field.querySelectorAll('select')).map((s) => {
          const opts = Array.from(s.options || []).map((o) => (o.text || '').trim()).filter(Boolean).slice(0, 12);
          return { value: (s.value || '').trim(), options: opts };
        });
        const inputs = Array.from(field.querySelectorAll('input, textarea'))
          .filter(visible)
          .map((el) => ({
            name: el.getAttribute('name') || '',
            id: el.id || '',
            type: el.getAttribute('type') || '',
            role: el.getAttribute('role') || '',
            value: ('value' in el) ? String(el.value || '') : '',
            aria: el.getAttribute('aria-valuetext') || '',
          }))
          .slice(0, 12);

        return { text: text.slice(0, 360), radios, selects, inputs };
      });
    });
  } catch (_) {
    return [];
  }
}

async function fillPrimaryQuestionnaireCompositeInputs(page, profile) {
  const appAnswers = deriveApplicationQuestionAnswers(profile || {});
  const ordered = [
    { field: 'salary_expectation', value: appAnswers.salary_expectation },
    { field: 'requires_sponsorship', value: appAnswers.requires_sponsorship },
    { field: 'restrictive_agreement', value: appAnswers.restrictive_agreement },
    { field: 'english_proficiency', value: appAnswers.english_proficiency },
    { field: 'willing_to_relocate', value: appAnswers.willing_to_relocate },
    { field: 'work_authorized_us', value: appAnswers.work_authorized_us },
    { field: 'years_experience', value: appAnswers.years_experience },
  ];

  try {
    const filled = await page.evaluate(({ ordered }) => {
      const root = document.querySelector('[data-automation-id="applyFlowPrimaryQuestionsPage"]');
      if (!root) return [];

      const inputs = Array.from(root.querySelectorAll('input[type="text"], input:not([type])'));
      if (inputs.length < 2) return [];

      const out = [];
      const write = (el, value) => {
        if (!el) return false;
        const proto = Object.getPrototypeOf(el);
        const desc = Object.getOwnPropertyDescriptor(proto, 'value')
          || Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
        if (desc && typeof desc.set === 'function') {
          desc.set.call(el, String(value));
        } else {
          el.value = String(value);
        }
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
      };

      const count = Math.min(ordered.length, inputs.length);
      for (let i = 0; i < count; i++) {
        const q = ordered[i];
        if (!q || q.value === undefined || q.value === null || String(q.value).trim() === '') {
          continue;
        }
        if (write(inputs[i], q.value)) {
          out.push(q.field);
        }
      }
      return out;
    }, { ordered });

    return Array.isArray(filled) ? filled : [];
  } catch (_) {
    return [];
  }
}

async function answerRequiredQuestionsGlobalFallback(page, profile) {
  try {
    const appAnswers = deriveApplicationQuestionAnswers(profile || {});
    const result = await page.evaluate(({ profile, appAnswers }) => {
      const filled = [];

      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const setInput = (container, value) => {
        if (!container || !value) return false;
        const input = container.querySelector('input[type="text"], input[type="email"], textarea, input:not([type]), input[type="search"]');
        if (input && isVisible(input)) {
          if ((input.value || '').trim().length === 0) {
            input.focus();
            input.value = String(value);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
          }
          return true;
        }
        return false;
      };

      const setRadioByText = (container, value) => {
        if (!container || !value) return false;
        const target = String(value).trim().toLowerCase();
        const candidates = Array.from(container.querySelectorAll('label, [role="radio"], [data-automation-id="radioBtn"], button, div[role="button"]'));
        const hit = candidates.find((el) => {
          if (!isVisible(el)) return false;
          const txt = (el.innerText || '').trim().toLowerCase();
          return txt === target || txt.startsWith(target + ' ');
        });
        if (hit) {
          hit.click();
          return true;
        }
        return false;
      };

      const setSelectLike = (container, value) => {
        if (!container || !value) return false;
        const select = container.querySelector('select');
        if (select && isVisible(select)) {
          const opt = Array.from(select.options || []).find((o) => (o.text || '').toLowerCase().includes(String(value).toLowerCase()) || String(o.value || '').toLowerCase().includes(String(value).toLowerCase()));
          if (opt) {
            select.value = opt.value;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
          }
        }

        const comboInput = container.querySelector('input[aria-haspopup="listbox"], [role="combobox"] input, [role="combobox"]');
        if (comboInput && isVisible(comboInput)) {
          comboInput.click();
          if (comboInput.tagName && comboInput.tagName.toLowerCase() === 'input') {
            comboInput.value = String(value);
            comboInput.dispatchEvent(new Event('input', { bubbles: true }));
            comboInput.dispatchEvent(new Event('change', { bubbles: true }));
          }
          return true;
        }
        return false;
      };

      const findContainer = (needle) => {
        const n = String(needle).toLowerCase();
        const elems = Array.from(document.querySelectorAll('label, legend, p, div, span, h3, h4'));
        const textNode = elems.find((el) => {
          if (!isVisible(el)) return false;
          const txt = (el.innerText || '').toLowerCase();
          return txt.includes(n);
        });
        if (!textNode) return null;
        return textNode.closest('div, fieldset, section, li, form') || textNode.parentElement;
      };

      const hear = findContainer('how did you hear about us');
      if (hear && profile.hear_about_us) {
        if (setSelectLike(hear, profile.hear_about_us) || setInput(hear, profile.hear_about_us)) {
          filled.push('hear_about_us');
        }
      }

      const prior = findContainer('have you ever been employed');
      if (prior && profile.prior_company_employment) {
        if (setRadioByText(prior, profile.prior_company_employment)) {
          filled.push('prior_company_employment');
        }
      }

      const phoneType = findContainer('phone device type');
      if (phoneType && profile.phone_device_type) {
        if (setSelectLike(phoneType, profile.phone_device_type)) {
          filled.push('phone_device_type');
        }
      }

      const salary = findContainer('base salary expectation');
      if (salary && appAnswers.salary_expectation) {
        if (setInput(salary, appAnswers.salary_expectation)) {
          filled.push('salary_expectation');
        }
      }

      const sponsor = findContainer('require sponsorship');
      if (sponsor && appAnswers.requires_sponsorship) {
        if (setRadioByText(sponsor, appAnswers.requires_sponsorship)) {
          filled.push('requires_sponsorship');
        }
      }

      const auth = findContainer('legally authorized to work');
      if (auth && appAnswers.work_authorized_us) {
        if (setRadioByText(auth, appAnswers.work_authorized_us)) {
          filled.push('work_authorized_us');
        }
      }

      const relocate = findContainer('willing to relocate');
      if (relocate && appAnswers.willing_to_relocate) {
        if (setRadioByText(relocate, appAnswers.willing_to_relocate)) {
          filled.push('willing_to_relocate');
        }
      }

      const restrictive = findContainer('restrict your ability to perform');
      if (restrictive && appAnswers.restrictive_agreement) {
        if (setRadioByText(restrictive, appAnswers.restrictive_agreement)) {
          filled.push('restrictive_agreement');
        }
      }

      const english = findContainer('proficiency of the english language');
      if (english && appAnswers.english_proficiency) {
        if (setSelectLike(english, appAnswers.english_proficiency) || setInput(english, appAnswers.english_proficiency)) {
          filled.push('english_proficiency');
        }
      }

      const years = findContainer('years of experience');
      if (years && appAnswers.years_experience) {
        if (setInput(years, appAnswers.years_experience)) {
          filled.push('years_experience');
        }
      }

      return filled;
    }, { profile, appAnswers });

    return Array.isArray(result) ? result : [];
  } catch (_) {
    return [];
  }
}

// ── Step-specific handlers ─────────────────────────────────────────────────────

/**
 * Step 2: My Information — verify/fill personal info.
 * Workday typically pre-fills this from the resume upload. We verify and fill gaps.
 */
async function handleMyInformation(page, profile, result) {
  const filled = [];
  const skipped = [];

  await attemptReachMyInformationForLegalName(page);

  // Split full_name into first/last if not explicitly provided.
  let firstName = profile.first_name || '';
  let lastName  = profile.last_name || '';
  if (!firstName && !lastName && profile.full_name) {
    const parts = profile.full_name.trim().split(/\s+/);
    firstName = parts[0] || '';
    lastName  = parts.slice(1).join(' ') || '';
  }

  const legalNameFields = [
    { value: firstName, name: 'first_name' },
    { value: lastName, name: 'last_name' },
  ];

  for (const f of legalNameFields) {
    const r = await ensureLegalNameField(page, f.value, f.name);
    if (r) filled.push(r);
    else skipped.push(f.name);
  }

  // Common Workday My Information fields (data-automation-id).
  const fieldMap = [
    { automationId: 'addressSection_addressLine1', value: profile.address || '', name: 'address_line1' },
    { automationId: 'addressSection_city',         value: profile.city || '',    name: 'city' },
    { automationId: 'addressSection_postalCode',   value: profile.zip || '',     name: 'postal_code' },
    { automationId: 'phone-number',                value: profile.phone || '',   name: 'phone' },
    { automationId: 'email',                       value: profile.email || '',   name: 'email' },
  ];

  for (const f of fieldMap) {
    const r = await fillFieldIfEmpty(page, f.automationId, f.value, f.name);
    if (r) filled.push(r);
    else skipped.push(f.name);
  }

  // Country dropdown.
  if (profile.country) {
    const r = await selectDropdownOption(page, 'addressSection_countryRegion', profile.country, 'country');
    if (r) filled.push(r);
    else skipped.push('country');
  }

  // State dropdown.
  if (profile.state) {
    const r = await selectDropdownOption(page, 'addressSection_countryRegionStateProvince', profile.state, 'state');
    if (r) filled.push(r);
    else skipped.push('state');
  }

  // LinkedIn URL.
  if (profile.linkedin) {
    const r = await fillFieldIfEmpty(page, 'linkedinQuestion', profile.linkedin, 'linkedin');
    if (r) filled.push(r);
    else skipped.push('linkedin');
  }

  if (profile.phone_device_type) {
    const r = await answerDropdownInQuestion(page, 'Phone Device Type', profile.phone_device_type, 'phone_device_type');
    if (r) filled.push(r);
    else skipped.push('phone_device_type');
  }

  if (profile.hear_about_us) {
    let r = await answerDropdownInQuestion(page, 'How Did You Hear About Us', profile.hear_about_us, 'hear_about_us');
    if (!r) {
      r = await answerTextInQuestion(page, 'How Did You Hear About Us', profile.hear_about_us, 'hear_about_us');
    }
    if (!r) {
      r = await answerQuestionByDomFallback(page, 'How Did You Hear About Us', profile.hear_about_us, 'hear_about_us');
    }
    if (r) filled.push(r);
    else skipped.push('hear_about_us');
  }

  if (profile.prior_company_employment) {
    let r = await answerRadioInQuestion(page, 'Have you ever been employed', profile.prior_company_employment, 'prior_company_employment');
    if (!r) {
      r = await answerQuestionByDomFallback(page, 'Have you ever been employed', profile.prior_company_employment, 'prior_company_employment');
    }
    if (r) filled.push(r);
    else skipped.push('prior_company_employment');
  }

  if ((profile.prior_company_employment || '').toLowerCase() === 'yes') {
    if (profile.prior_company_wwid) {
      const r = await answerTextInQuestion(page, 'WWID', profile.prior_company_wwid, 'prior_company_wwid');
      if (r) filled.push(r);
      else skipped.push('prior_company_wwid');
    }
    if (profile.prior_company_email) {
      const r = await answerTextInQuestion(page, 'Email', profile.prior_company_email, 'prior_company_email');
      if (r) filled.push(r);
      else skipped.push('prior_company_email');
    }
  }

  const globalFallbackFilled = await answerRequiredQuestionsGlobalFallback(page, profile);
  for (const f of globalFallbackFilled) {
    if (!filled.includes(f)) {
      filled.push(f);
    }
    const ix = skipped.indexOf(f);
    if (ix >= 0) {
      skipped.splice(ix, 1);
    }
  }

  result.fields_filled = filled;
  result.fields_skipped = skipped;
}

/**
 * Step 3: My Experience — verify work history and education from resume.
 * Workday pre-fills most of this from the resume. We verify sections exist.
 */
async function handleMyExperience(page, profile, result) {
  const filled = [];
  const skipped = [];
  const educationEntrySet = buildEducationEntryList(profile || {});
  const effectiveEducationEntries = educationEntrySet.effective;
  const desiredEducationCount = Math.max(0, Math.min(MAX_EDUCATION_ENTRIES, effectiveEducationEntries.length));
  const hasExperienceFillTag = () => filled.some((x) => {
    const s = String(x || '');
    return s === 'experience_job_title'
      || s === 'experience_company'
      || s === 'experience_from'
      || s === 'experience_to'
      || s.startsWith('experience_2_')
      || s.startsWith('experience_3_')
      || s === 'experience_rows_ready_3'
      || s === 'experience_rows_ready_2';
  });

  // Check if work experience section has entries.
  try {
    const workEntries = page.locator('[data-automation-id="workExperienceSection"], [data-automation-id="experienceItem"]');
    const count = await workEntries.count();
    process.stderr.write(`INFO: Work experience entries found: ${count}\n`);
    if (count > 0) {
      filled.push('work_experience_present');
    } else {
      skipped.push('work_experience_empty');
    }
  } catch (_) {
    skipped.push('work_experience_check_failed');
  }

  // Validation/correction subflow: ensure required core experience fields are
  // present and align to profile data when fields are available.
  const correctedPrimary = await forceFillExperienceFields(page, profile);
  const correctedEducation = await forceFillEducationFields(page, profile);
  mergeUniqueTags(filled, correctedPrimary.filled || []);
  mergeUniqueTags(filled, correctedEducation.filled || []);
  mergeUniqueTags(skipped, correctedPrimary.skipped || []);
  mergeUniqueTags(skipped, correctedEducation.skipped || []);
  if (correctedPrimary.filled.length > 0 || correctedEducation.filled.length > 0) {
    const committed = await commitMyExperienceEditor(page);
    if (committed) {
      filled.push('experience_editor_saved');
    }
  }

  if (desiredEducationCount > 0) {
    let savedEducationCount = await countVisibleEducationEntries(page);
    let matchedEducationCount = await countMatchedEducationEntries(page, effectiveEducationEntries);
    if (savedEducationCount < desiredEducationCount) {
      const refillEducation = await forceFillEducationFields(page, profile);
      mergeUniqueTags(filled, refillEducation.filled || []);
      mergeUniqueTags(skipped, refillEducation.skipped || []);
      if (refillEducation.filled.length > 0) {
        const committed = await commitMyExperienceEditor(page);
        if (committed && !filled.includes('experience_editor_saved')) {
          filled.push('experience_editor_saved');
        }
      }
      savedEducationCount = await countVisibleEducationEntries(page);
      matchedEducationCount = await countMatchedEducationEntries(page, effectiveEducationEntries);
    }

    if (savedEducationCount >= desiredEducationCount && matchedEducationCount >= Math.max(1, Math.min(desiredEducationCount, effectiveEducationEntries.length))) {
      filled.push('education_saved_verified');
    } else {
      skipped.push(`education_not_saved_visible_${savedEducationCount}_of_${desiredEducationCount}`);
      skipped.push(`education_not_saved_matched_${matchedEducationCount}_of_${desiredEducationCount}`);
    }
  }

  // If no entry is detected, try opening the editor and applying corrections again.
  if (!filled.includes('work_experience_present') && !hasExperienceFillTag()) {
    const opened = await openMyExperienceEditor(page);
    if (opened) {
      filled.push('opened_experience_editor');
      const correctedAfterOpen = await forceFillExperienceFields(page, profile);
      const correctedEducationAfterOpen = await forceFillEducationFields(page, profile);
      mergeUniqueTags(filled, correctedAfterOpen.filled || []);
      mergeUniqueTags(filled, correctedEducationAfterOpen.filled || []);
      mergeUniqueTags(skipped, correctedAfterOpen.skipped || []);
      mergeUniqueTags(skipped, correctedEducationAfterOpen.skipped || []);
      if (correctedAfterOpen.filled.length > 0 || correctedEducationAfterOpen.filled.length > 0) {
        const committed = await commitMyExperienceEditor(page);
        if (committed) {
          filled.push('experience_editor_saved');
        }
      }
    }
  }

  // If the page demands a file upload, attempt to upload the tailored resume again.
  if (resume_pdf_path) {
    const errorText = (await page.locator('[data-automation-id="errorMessage"], [data-automation-id="inlineError"], .error-message-text, [data-automation-id*="error" i]').allTextContents().catch(() => [])).join(' ').toLowerCase();
    if (errorText.includes('upload a file') || errorText.includes('5mb max')) {
      const uploadEvidence = [];
      const uploaded = await uploadRequiredFileIfPresent(page, resume_pdf_path, uploadEvidence);
      if (uploaded) {
        filled.push('required_file_upload');
      } else {
        skipped.push('required_file_upload');
      }
    }
  }

  // Check if education section has entries.
  try {
    const count = await countVisibleEducationEntries(page);
    process.stderr.write(`INFO: Education entries found: ${count}\n`);
    if (count > 0) {
      filled.push('education_present');
    } else {
      skipped.push('education_empty');
    }
  } catch (_) {
    skipped.push('education_check_failed');
  }

  // Check for skills section.
  try {
    const skillEntries = page.locator('[data-automation-id="skillsSection"], [data-automation-id="skillItem"]');
    const count = await skillEntries.count();
    if (count > 0) filled.push('skills_present');
  } catch (_) {}

  result.fields_filled = filled;
  result.fields_skipped = skipped;
}

/**
 * Step 4: Application Questions — these are per-job custom questions.
 * We cannot automate these generically. Take a screenshot and flag for review.
 */
async function handleApplicationQuestions(page, profile, result) {
  result.needs_manual_review = false;
  result.fields_filled = [];
  result.fields_skipped = [];
  const appAnswers = deriveApplicationQuestionAnswers(profile || {});

  // Count how many questions are displayed.
  let questionCount = 0;
  try {
    const questions = page.locator('[data-automation-id="questionSection"] [data-automation-id="formField"], [data-automation-id="questionField"]');
    questionCount = await questions.count();
    process.stderr.write(`INFO: Found ${questionCount} application question fields.\n`);
    result.fields_skipped.push(`${questionCount}_questions_found`);
  } catch (_) {}

  if (questionCount > 0) {
    result.needs_manual_review = true;
    result.fields_skipped.push('application_questions_require_manual_review');
  }

  const requiredMappings = [
    { needle: 'base salary expectation', answer: appAnswers.salary_expectation, field: 'salary_expectation', mode: 'text' },
    { needle: 'require sponsorship', answer: appAnswers.requires_sponsorship, field: 'requires_sponsorship', mode: 'radio' },
    { needle: 'restrict your ability to perform', answer: appAnswers.restrictive_agreement, field: 'restrictive_agreement', mode: 'radio' },
    { needle: 'proficiency of the english language', answer: appAnswers.english_proficiency, field: 'english_proficiency', mode: 'dropdown' },
    { needle: 'willing to relocate', answer: appAnswers.willing_to_relocate, field: 'willing_to_relocate', mode: 'radio' },
    { needle: 'legally authorized to work', answer: appAnswers.work_authorized_us, field: 'work_authorized_us', mode: 'radio' },
    { needle: 'years of experience', answer: appAnswers.years_experience, field: 'years_experience', mode: 'text' },
    { needle: 'authorized to work in the united states', answer: appAnswers.work_authorized_us, field: 'work_authorization_alt', mode: 'radio' },
    { needle: 'future require sponsorship', answer: appAnswers.requires_sponsorship, field: 'sponsorship_alt', mode: 'radio' },
    { needle: '18 years of age', answer: profile.age_18_or_older || '', field: 'age_18', mode: 'radio' },
    { needle: 'at least 18', answer: profile.age_18_or_older || '', field: 'age_18_alt', mode: 'radio' },
  ].filter((qa) => qa.answer && String(qa.answer).trim().length > 0);

  for (const qa of requiredMappings) {
    const answered = await answerQuestionSmart(page, qa.needle, qa.answer, qa.field, qa.mode);
    if (answered && !result.fields_filled.includes(qa.field)) {
      result.fields_filled.push(qa.field);
    }
  }

  const compositeFilled = await fillPrimaryQuestionnaireCompositeInputs(page, profile);
  for (const field of compositeFilled) {
    if (!result.fields_filled.includes(field)) {
      result.fields_filled.push(field);
    }
  }

  // Profile-driven explicit answers for common required company-specific questions.
  if (profile.hear_about_us) {
    const r = await answerDropdownInQuestion(page, 'How Did You Hear About Us', profile.hear_about_us, 'hear_about_us')
      || await answerTextInQuestion(page, 'How Did You Hear About Us', profile.hear_about_us, 'hear_about_us');
    if (r) {
      result.fields_filled.push(r);
    }
  }

  if (profile.prior_company_employment) {
    const r = await answerRadioInQuestion(page, 'Have you ever been employed', profile.prior_company_employment, 'prior_company_employment');
    if (r) {
      result.fields_filled.push(r);
    }
  }

  if ((profile.prior_company_employment || '').toLowerCase() === 'yes') {
    if (profile.prior_company_wwid) {
      const r = await answerTextInQuestion(page, 'WWID', profile.prior_company_wwid, 'prior_company_wwid');
      if (r) result.fields_filled.push(r);
    }
    if (profile.prior_company_email) {
      const r = await answerTextInQuestion(page, 'Email', profile.prior_company_email, 'prior_company_email');
      if (r) result.fields_filled.push(r);
    }
  }
}

/**
 * Step 5: Voluntary Disclosures — EEO gender, race/ethnicity, veteran status.
 */
async function handleVoluntaryDisclosures(page, profile, result) {
  const filled = [];
  const skipped = [];

  const agreementChecks = await checkRequiredAgreementCheckboxes(page, 'voluntary_agreement');
  for (const field of agreementChecks) {
    if (!filled.includes(field)) {
      filled.push(field);
    }
  }

  // Gender.
  if (profile.eeo_gender) {
    const r = await selectDropdownOption(page, 'genderDropdown', profile.eeo_gender, 'gender');
    if (!r) {
      // Try radio button pattern.
      const r2 = await selectRadioOption(page, profile.eeo_gender, 'gender');
      if (r2) filled.push(r2);
      else skipped.push('gender');
    } else {
      filled.push(r);
    }
  }

  // Race / Ethnicity.
  if (profile.eeo_ethnicity) {
    // Workday often uses checkboxes for race/ethnicity.
    const r = await checkCheckboxByLabel(page, profile.eeo_ethnicity, 'ethnicity');
    if (!r) {
      const r2 = await selectDropdownOption(page, 'ethnicityDropdown', profile.eeo_ethnicity, 'ethnicity');
      if (r2) filled.push(r2);
      else skipped.push('ethnicity');
    } else {
      filled.push(r);
    }
  }

  // Veteran status.
  if (profile.eeo_veteran) {
    const r = await selectDropdownOption(page, 'veteranStatusDropdown', profile.eeo_veteran, 'veteran_status');
    if (!r) {
      const r2 = await selectRadioOption(page, profile.eeo_veteran, 'veteran_status');
      if (r2) filled.push(r2);
      else skipped.push('veteran_status');
    } else {
      filled.push(r);
    }
  }

  result.fields_filled = filled;
  result.fields_skipped = skipped;
}

/**
 * Step 6: Self-Identify — disability self-identification.
 */
async function handleSelfIdentify(page, profile, result) {
  const filled = [];
  const skipped = [];

  if (profile.disability_status) {
    // Common patterns for disability self-identification.
    const selectors = [
      `label:has-text("${profile.disability_status}")`,
      `[data-automation-id="radioBtn"]:has-text("${profile.disability_status}")`,
      `div[role="radio"]:has-text("${profile.disability_status}")`,
    ];

    let found = false;
    for (const sel of selectors) {
      try {
        const el = page.locator(sel).first();
        await el.waitFor({ state: 'visible', timeout: 3000 });
        await el.click({ timeout: 2000 });
        found = true;
        process.stderr.write(`INFO: Selected disability status: "${profile.disability_status}"\n`);
        filled.push('disability_status');
        break;
      } catch (_) {
        continue;
      }
    }

    if (!found) {
      skipped.push('disability_status');
    }
  }

  // Some Workday forms also ask for a name and date on this page.
  if (profile.full_name) {
    const r = await fillFieldIfEmpty(page, 'name', profile.full_name, 'signature_name');
    if (r) filled.push(r);
  }

  const completed = await completeSelfIdentifyRequiredControls(page, profile);
  for (const f of completed) {
    if (!filled.includes(f)) {
      filled.push(f);
    }
  }

  result.fields_filled = filled;
  result.fields_skipped = skipped;
}

/**
 * Step 7: Review & Submit — take a screenshot of the review page and click Submit.
 */
async function handleReviewSubmit(page, profile, result) {
  result.fields_filled = [];
  result.fields_skipped = [];

  const compositeFilled = await fillPrimaryQuestionnaireCompositeInputs(page, profile);
  for (const field of compositeFilled) {
    if (!result.fields_filled.includes(field)) {
      result.fields_filled.push(field);
    }
  }

  const globalResolved = await answerRequiredQuestionsGlobalFallback(page, profile);
  for (const field of globalResolved) {
    if (!result.fields_filled.includes(field)) {
      result.fields_filled.push(field);
    }
  }

  // Look for any required agreement checkboxes.
  const agreements = await checkRequiredAgreementCheckboxes(page, 'agreement_checkbox');
  for (const field of agreements) {
    if (!result.fields_filled.includes(field)) {
      result.fields_filled.push(field);
    }
  }
}

// ── Main flow ──────────────────────────────────────────────────────────────────

const STEP_HANDLERS = {
  my_information:        handleMyInformation,
  my_experience:         handleMyExperience,
  application_questions: handleApplicationQuestions,
  voluntary_disclosures: handleVoluntaryDisclosures,
  self_identify:         handleSelfIdentify,
  review_submit:         handleReviewSubmit,
};

async function detectPageHeading(page) {
  let detectedPage = '';
  const headingSelectors = [
    '[data-automation-id="pageHeaderTitle"]',
    '[data-automation-id="stepTitle"]',
    'h2[data-automation-id]',
    '.current-step-title',
  ];

  for (const sel of headingSelectors) {
    try {
      const heading = page.locator(sel).first();
      const text = await heading.textContent({ timeout: 2500 });
      if (text && text.trim()) {
        detectedPage = text.trim();
        break;
      }
    } catch (_) {}
  }

  if (!detectedPage) {
    try {
      const stepIndicator = page.locator('[data-automation-id="activeStep"], .active-step, [aria-current="step"]').first();
      const stepText = await stepIndicator.textContent({ timeout: 2000 });
      detectedPage = (stepText || '').trim();
    } catch (_) {}
  }

  if (!detectedPage) {
    detectedPage = await page.title();
  }
  return detectedPage;
}

async function inferCurrentWizardStep(page) {
  try {
    const inferred = await page.evaluate(() => {
      const visible = (el) => {
        if (!el) return false;
        const r = el.getBoundingClientRect();
        const s = window.getComputedStyle(el);
        return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
      };

      const has = (selector) => {
        const el = document.querySelector(selector);
        return !!(el && visible(el));
      };

      const hasAnyVisibleAction = (regex) => {
        const controls = Array.from(document.querySelectorAll('button, [role="button"], input[type="submit"], input[type="button"], a'))
          .filter(visible);
        return controls.some((el) => {
          const t = ((el.textContent || '') + ' ' + (el.getAttribute('value') || '') + ' ' + (el.getAttribute('aria-label') || '')).toLowerCase();
          return regex.test(t);
        });
      };

      const progressCurrent = Array.from(document.querySelectorAll('[data-automation-id="progressBarCurrentStep"], li[aria-current="step"], [aria-current="step"], .active-step'))
        .find(visible);
      const progressRaw = String(progressCurrent?.textContent || progressCurrent?.innerText || '').toLowerCase();
      const progressFallback = String(document.querySelector('[data-automation-id="progressBar"]')?.textContent || '').toLowerCase();
      const progressText = progressRaw || progressFallback;

      const fromCurrentStepText = (txt) => {
        const t = String(txt || '').toLowerCase();
        if (!t) return '';
        const currentSlice = t.includes('current step') ? t.slice(t.indexOf('current step')) : t;
        if (currentSlice.includes('my information')) return 'my_information';
        if (currentSlice.includes('my experience')) return 'my_experience';
        if (currentSlice.includes('application questions') || currentSlice.includes('job-specific information')) return 'application_questions';
        if (currentSlice.includes('voluntary disclosures')) return 'voluntary_disclosures';
        if (currentSlice.includes('self-identify') || currentSlice.includes('self identify') || currentSlice.includes('disability')) return 'self_identify';
        if (currentSlice.includes('review')) return 'review_submit';
        return '';
      };

      const stepFromProgress = fromCurrentStepText(progressText);
      if (stepFromProgress) {
        return stepFromProgress;
      }

      if (progressRaw.includes('my information')) {
        return 'my_information';
      }
      if (progressRaw.includes('my experience')) {
        return 'my_experience';
      }
      if (progressRaw.includes('application questions') || progressRaw.includes('job-specific information')) {
        return 'application_questions';
      }
      if (progressRaw.includes('voluntary disclosures')) {
        return 'voluntary_disclosures';
      }
      if (progressRaw.includes('self-identify') || progressRaw.includes('self identify') || progressRaw.includes('disability')) {
        return 'self_identify';
      }
      if (progressRaw.includes('review')) {
        return 'review_submit';
      }

      if (has('[data-automation-id="applyFlowMyExpPage"]') || document.querySelector('input[name="jobTitle"][id*="workExperience-"]')) {
        return 'my_experience';
      }

      if (document.querySelector('[id*="workExperience-"]') || document.querySelector('[id*="education-"]')) {
        return 'my_experience';
      }

      if (has('[data-automation-id="applyFlowPrimaryQuestionsPage"]') || document.querySelector('[id*="primaryQuestionnaire" i]')) {
        return 'application_questions';
      }

      if (has('[data-automation-id="applyFlowVoluntaryDisclosuresPage"]') || has('[data-automation-id="formField-acceptTermsAndAgreements"]')) {
        return 'voluntary_disclosures';
      }

      const pageText = (document.body?.innerText || '').toLowerCase();
      if (pageText.includes('self-identify') || pageText.includes('disability')) {
        return 'self_identify';
      }

      const hasSubmit = hasAnyVisibleAction(/submit/);
      const hasContinue = hasAnyVisibleAction(/save and continue|continue|next/);

      if (pageText.includes('job title') && (pageText.includes('school or university') || pageText.includes('work experience') || pageText.includes('education'))) {
        return 'my_experience';
      }

      if (hasContinue && !hasSubmit) {
        if (pageText.includes('phone device type')
          || (pageText.includes('country') && pageText.includes('state'))
          || pageText.includes('how did you hear about us')
          || pageText.includes('have you ever been employed')) {
          return 'my_information';
        }
      }

      if (hasSubmit && !hasContinue) {
        return 'review_submit';
      }

      if (has('[data-automation-id="legalNameSection_firstName"]') || has('[data-automation-id="addressSection_city"]') || has('[data-automation-id="phone-number"]')) {
        return 'my_information';
      }

      if (hasContinue && !hasSubmit) {
        return 'my_information';
      }

      return '';
    });

    return String(inferred || '').trim();
  } catch (_) {
    return '';
  }
}

async function isLikelyStillOnStep(page, stepKey) {
  try {
    const stillThere = await page.evaluate((stepKey) => {
      const visible = (el) => {
        if (!el) return false;
        const r = el.getBoundingClientRect();
        const s = window.getComputedStyle(el);
        return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
      };

      if (stepKey === 'my_experience') {
        const titleInputs = Array.from(document.querySelectorAll('input[name="jobTitle"][id*="workExperience-"]')).filter(visible);
        if (titleInputs.length > 0) {
          return true;
        }

        const workEduControls = Array.from(document.querySelectorAll('[id*="workExperience-"], [id*="education-"], [data-automation-id*="workExperience" i], [data-automation-id*="education" i], input[name="jobTitle"], input[id*="school" i], button[id*="degree" i]'))
          .filter(visible);
        if (workEduControls.length >= 4) {
          return true;
        }

        const expRoot = document.querySelector('[data-automation-id="applyFlowMyExpPage"]');
        if (expRoot && visible(expRoot)) {
          return true;
        }

        const pageText = (document.body?.innerText || '').toLowerCase();
        if (pageText.includes('job title') && (pageText.includes('school or university') || pageText.includes('work experience') || pageText.includes('education'))) {
          return true;
        }
        return pageText.includes('work experience') && pageText.includes('education');
      }

      if (stepKey === 'application_questions') {
        const qRoot = document.querySelector('[data-automation-id="applyFlowPrimaryQuestionsPage"]');
        if (qRoot && visible(qRoot)) {
          return true;
        }

        const qFields = Array.from(document.querySelectorAll('[data-automation-id="formField"], [data-automation-id="questionField"]')).filter(visible);
        if (qFields.length > 0) {
          return true;
        }

        const pageText = (document.body?.innerText || '').toLowerCase();
        if (pageText.includes('base salary expectation') || pageText.includes('how many years of experience')) {
          return true;
        }
        return false;
      }

      return true;
    }, stepKey);
    return !!stillThere;
  } catch (_) {
    return true;
  }
}

async function alignToTargetStepIfPossible(page, targetStep, evidenceParts = []) {
  if (!AUTO_STEP_ORDER.includes(targetStep)) {
    return false;
  }

  await enterApplyFlowIfNeeded(page, evidenceParts);

  for (let i = 0; i < 8; i++) {
    const inferred = await inferCurrentWizardStep(page);
    if (inferred === targetStep) {
      if (i > 0) {
        evidenceParts.push(`Single-step realignment reached ${targetStep} in ${i} hop(s)`);
      }
      return true;
    }

    if (!AUTO_STEP_ORDER.includes(inferred)) {
      return false;
    }

    const currentIdx = AUTO_STEP_ORDER.indexOf(inferred);
    const targetIdx = AUTO_STEP_ORDER.indexOf(targetStep);

    let moved = false;
    if (currentIdx < targetIdx) {
      moved = await clickContinueButton(page, evidenceParts);
    } else if (currentIdx > targetIdx) {
      moved = await clickBackButton(page, evidenceParts);
    }

    if (!moved) {
      return false;
    }

    await humanDelay(1000, 1800);
  }

  return (await inferCurrentWizardStep(page)) === targetStep;
}

async function bootstrapMyExperienceFromMyInformation(page, profile, evidenceParts = []) {
  try {
    const inferred = await inferCurrentWizardStep(page);
    if (inferred !== 'my_information') {
      return false;
    }

    const local = {
      fields_filled: [],
      fields_skipped: [],
      needs_manual_review: false,
    };

    await handleMyInformation(page, profile || {}, local);
    evidenceParts.push(`Bootstrap my_information fields filled: [${(local.fields_filled || []).join(', ')}]`);

    const moved = await clickContinueButton(page, evidenceParts);
    if (!moved) {
      return false;
    }

    await humanDelay(1200, 2200);
    const after = await inferCurrentWizardStep(page);
    if (after === 'my_experience') {
      evidenceParts.push('Bootstrapped my_information -> my_experience');
      return true;
    }
  } catch (_) {}

  return false;
}

async function isMyInformationFormVisible(page) {
  const probes = [
    '[data-automation-id="legalNameSection_firstName"]',
    '[data-automation-id="legalNameSection_lastName"]',
    '[data-automation-id="addressSection_city"]',
    '[data-automation-id="phone-number"]',
    '[data-automation-id="email"]',
    'input[autocomplete="given-name"]',
    'input[autocomplete="family-name"]',
  ];

  for (const sel of probes) {
    try {
      if (await page.locator(sel).first().isVisible({ timeout: 350 })) {
        return true;
      }
    } catch (_) {}
  }

  return false;
}

async function clickContinueButton(page, evidenceParts) {
  await dismissDiscardApplicationPopup(page, evidenceParts);

  const continueSelectors = [
    '[data-automation-id="bottom-navigation-next-button"]',
    'button[data-automation-id="bottom-navigation-next-button"]',
    '[data-automation-id="bottom-navigation"] [data-automation-id="bottom-navigation-next-button"]',
    '[data-automation-id="bottom-navigation"] button:has-text("Continue")',
    '[data-automation-id="bottom-navigation"] button:has-text("Next")',
    '[data-automation-id="bottom-navigation"] button:has-text("Save and Continue")',
    '[data-automation-id="bottom-navigation"] [role="button"]:has-text("Save and Continue")',
    '[data-automation-id="bottom-navigation"] [role="button"]:has-text("Continue")',
    '[data-automation-id="bottom-navigation"] [role="button"]:has-text("Next")',
    'button:has-text("Save and Continue")',
    '[role="button"]:has-text("Save and Continue")',
    'div[role="button"]:has-text("Save and Continue")',
    'button[data-automation-id="nextButton"]',
    '[data-automation-id="nextButton"]',
    'button[aria-label*="Continue" i]',
    '[role="button"][aria-label*="Continue" i]',
    '[role="button"][aria-label*="Next" i]',
    '[role="button"]:has-text("Continue")',
    '[role="button"]:has-text("Next")',
    'div[role="button"]:has-text("Continue")',
    'button:has-text("Continue")',
    'button:has-text("Next")',
  ];

  for (const sel of continueSelectors) {
    try {
      const btn = page.locator(sel).first();
      await btn.waitFor({ state: 'visible', timeout: 700 });
      await btn.scrollIntoViewIfNeeded({ timeout: 1000 });
      const disabled = await btn.evaluate((el) => {
        const aria = (el.getAttribute('aria-disabled') || '').toLowerCase();
        const klass = (el.getAttribute('class') || '').toLowerCase();
        const pointerEvents = (window.getComputedStyle(el).pointerEvents || '').toLowerCase();
        return !!(el.disabled || aria === 'true' || klass.includes('disabled') || pointerEvents === 'none');
      });
      if (disabled) {
        continue;
      }
      await humanDelay(150, 350);
      await btn.click({ timeout: 1800, force: true });
      await dismissDiscardApplicationPopup(page, evidenceParts);
      evidenceParts.push(`Clicked Continue via ${sel}`);
      return true;
    } catch (_) {}
  }

  try {
    const clicked = await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };
      const candidates = Array.from(document.querySelectorAll('button, [role="button"], a, input[type="button"], input[type="submit"]'));
      const match = candidates.find((el) => {
        if (!isVisible(el)) return false;
        const txt = ((el.textContent || '') + ' ' + (el.getAttribute('value') || '') + ' ' + (el.getAttribute('aria-label') || '')).toLowerCase();
        const disabled = el.disabled || (el.getAttribute('aria-disabled') || '').toLowerCase() === 'true';
        return !disabled && /save and continue|continue|next/.test(txt);
      });
      if (match) {
        match.scrollIntoView({ behavior: 'instant', block: 'center' });
        match.click();
        return true;
      }
      return false;
    });
    if (clicked) {
      await dismissDiscardApplicationPopup(page, evidenceParts);
      evidenceParts.push('Clicked Continue via DOM fallback');
      return true;
    }
  } catch (_) {}

  return false;
}

async function dismissDiscardApplicationPopup(page, evidenceParts = []) {
  const closeSelectors = [
    'button[data-automation-id="closeButton"]',
    '.workday-popup-close-container button[aria-label="Close"]',
    '.workday-popup-close-container .workday-popup-close-button',
    'button[title="Close"][data-automation-id="closeButton"]',
  ];

  for (let i = 0; i < 3; i++) {
    let closed = false;
    for (const sel of closeSelectors) {
      try {
        const btn = page.locator(sel).first();
        await btn.waitFor({ state: 'visible', timeout: 600 });
        await btn.scrollIntoViewIfNeeded({ timeout: 600 }).catch(() => {});
        await btn.click({ timeout: 1000, force: true });
        await humanDelay(140, 260);
        evidenceParts.push(`Dismissed popup via ${sel}`);
        closed = true;
        break;
      } catch (_) {}
    }

    if (!closed) {
      return false;
    }

    async function dismissDiscardApplicationPopup(page, evidenceParts = []) {
      try {
        const hasDiscardPrompt = await page.evaluate(() => {
          const txt = (document.body?.innerText || '').toLowerCase();
          return txt.includes('discard application') || txt.includes('do you want to discard') || txt.includes('keep applying');
        }).catch(() => false);

        const closeSelectors = [
          'button:has-text("Keep Applying")',
          'button:has-text("Cancel")',
          'button[data-automation-id="closeButton"]',
          '.workday-popup-close-container button',
          '.workday-popup-close-button',
          '[data-automation-id="popupClose"]',
        ];

        for (const sel of closeSelectors) {
          try {
            const btn = page.locator(sel).first();
            const visible = await btn.isVisible({ timeout: 300 }).catch(() => false);
            if (!visible) {
              continue;
            }
            await btn.click({ timeout: 1200, force: true });
            await humanDelay(160, 320);
            evidenceParts.push(`Dismissed discard popup via ${sel}`);
            return true;
          } catch (_) {}
        }

        if (hasDiscardPrompt) {
          try {
            await page.keyboard.press('Escape').catch(() => {});
            await humanDelay(120, 260);
            evidenceParts.push('Dismissed discard popup via Escape');
            return true;
          } catch (_) {}
        }
      } catch (_) {}

      return false;
    }
  }

      await dismissDiscardApplicationPopup(page, evidenceParts);

  return true;
}

async function clickBackButton(page, evidenceParts) {
  await dismissDiscardApplicationPopup(page, evidenceParts);

  const backSelectors = [
    'button[data-automation-id="pageFooterBackButton"]',
    '[data-automation-id="bottom-navigation"] button:has-text("Back")',
    'button:has-text("Back")',
    '[role="button"]:has-text("Back")',
  ];

  for (const sel of backSelectors) {
    try {
      const btn = page.locator(sel).first();
      await btn.waitFor({ state: 'visible', timeout: 700 });
      await btn.scrollIntoViewIfNeeded({ timeout: 1000 });
      const disabled = await btn.evaluate((el) => {
        const aria = (el.getAttribute('aria-disabled') || '').toLowerCase();
        return !!(el.disabled || aria === 'true');
      });
      if (disabled) {
        continue;
      }
      await humanDelay(120, 260);
      await btn.click({ timeout: 1800, force: true });
      evidenceParts.push(`Clicked Back via ${sel}`);
      return true;
    } catch (_) {}
  }

  return false;
}

async function acceptCookiesIfPresent(page, evidenceParts = []) {
  const selectors = [
    'button[data-automation-id="legalNoticeAcceptButton"]',
    'button:has-text("Accept Cookies")',
    'button:has-text("Accept All")',
    'button:has-text("Accept")',
  ];

  for (const sel of selectors) {
    try {
      const btn = page.locator(sel).first();
      await btn.waitFor({ state: 'visible', timeout: 1200 });
      await btn.click({ timeout: 1500, force: true });
      await humanDelay(250, 500);
      evidenceParts.push(`Accepted cookies via ${sel}`);
      return true;
    } catch (_) {}
  }
  return false;
}

async function enterApplyFlowIfNeeded(page, evidenceParts = []) {
  try {
    await dismissDiscardApplicationPopup(page, evidenceParts);

    const url = (page.url() || '').toLowerCase();
    if (url.includes('/apply/')) {
      return true;
    }

    const clickFirstEnabled = async (selectors, label) => {
      for (const sel of selectors) {
        try {
          await dismissDiscardApplicationPopup(page, evidenceParts);

          const btn = page.locator(sel).first();
          await btn.waitFor({ state: 'visible', timeout: 1200 });
          await btn.scrollIntoViewIfNeeded({ timeout: 1000 }).catch(() => {});
          const disabled = await btn.evaluate((el) => {
            const aria = (el.getAttribute('aria-disabled') || '').toLowerCase();
            return !!(el.disabled || aria === 'true');
          });
          if (disabled) {
            continue;
          }
          await humanDelay(120, 280);
          await btn.click({ timeout: 2200, force: true });
          evidenceParts.push(`Entered apply flow via ${label}:${sel}`);
          await humanDelay(1200, 2200);
          return true;
        } catch (_) {}
      }
      return false;
    };

    const applySelectors = [
      'a:has-text("Continue Application")',
      'button:has-text("Continue Application")',
      'a[href*="/apply/"]',
      'a[href*="autofillWithResume"]',
      'a[href*="/apply"]:has-text("Apply")',
      'a:has-text("Apply Now")',
      'button:has-text("Apply")',
      'a:has-text("Apply")',
      'button[data-automation-id*="apply" i]',
      'a[data-automation-id*="apply" i]',
      'button[data-automation-id="applyButton"]',
      'a[data-automation-id="applyButton"]',
    ];

    const entered = await clickFirstEnabled(applySelectors, 'apply-entry');
    if (entered) {
      const postUrl = (page.url() || '').toLowerCase();
      if (postUrl.includes('/apply/')) {
        return true;
      }
      const hasWizardSignal = await page.evaluate(() => {
        const txt = (document.body?.innerText || '').toLowerCase();
        return txt.includes('my information')
          || txt.includes('my experience')
          || txt.includes('application questions')
          || txt.includes('voluntary disclosures')
          || txt.includes('self-identify')
          || txt.includes('review')
          || !!document.querySelector('[data-automation-id="bottom-navigation"]')
          || !!document.querySelector('[data-automation-id="applyFlowMyExpPage"]');
      }).catch(() => false);
      return !!hasWizardSignal;
    }
  } catch (_) {}

  return false;
}

async function probeWorkExperienceDateControls(page) {
  try {
    return await page.evaluate(() => {
      const out = [];

      const firstTitle = document.querySelector('input[name="jobTitle"][id*="workExperience-"]');
      if (!firstTitle) {
        return out;
      }
      const m = String(firstTitle.id || '').match(/workExperience-(\d+)--/i);
      if (!m) {
        return out;
      }
      const key = m[1];

      const get = (id) => document.getElementById(id);
      const startMonthWrap = get(`workExperience-${key}--startDate-dateSectionMonth`);
      const startYearWrap = get(`workExperience-${key}--startDate-dateSectionYear`);
      const startMonthDisplay = get(`workExperience-${key}--startDate-dateSectionMonth-display`);
      const startYearDisplay = get(`workExperience-${key}--startDate-dateSectionYear-display`);
      const startMonthInput = get(`workExperience-${key}--startDate-dateSectionMonth-input`);
      const startYearInput = get(`workExperience-${key}--startDate-dateSectionYear-input`);

      const describe = (label, el) => {
        if (!el) {
          return { label, missing: true };
        }
        return {
          label,
          missing: false,
          tag: (el.tagName || '').toLowerCase(),
          id: el.id || '',
          role: el.getAttribute('role') || '',
          tabindex: el.getAttribute('tabindex'),
          aid: el.getAttribute('data-automation-id') || '',
          ariaLabel: el.getAttribute('aria-label') || '',
          ariaValueText: el.getAttribute('aria-valuetext') || '',
          ariaValueNow: el.getAttribute('aria-valuenow') || '',
          value: ('value' in el) ? String(el.value || '') : '',
          text: (el.textContent || '').trim(),
        };
      };

      out.push(describe('startMonthWrap', startMonthWrap));
      out.push(describe('startYearWrap', startYearWrap));
      out.push(describe('startMonthDisplay', startMonthDisplay));
      out.push(describe('startYearDisplay', startYearDisplay));
      out.push(describe('startMonthInput', startMonthInput));
      out.push(describe('startYearInput', startYearInput));

      const active = document.activeElement;
      out.push({
        label: 'activeElement',
        tag: active ? (active.tagName || '').toLowerCase() : '',
        id: active?.id || '',
        role: active?.getAttribute?.('role') || '',
        tabindex: active?.getAttribute?.('tabindex') || null,
      });

      return out;
    });
  } catch (_) {
    return [];
  }
}

async function clickSubmitButton(page, evidenceParts) {
  await dismissDiscardApplicationPopup(page, evidenceParts);

  const submitSelectors = [
    'button[data-automation-id="submitButton"]',
    'button[data-automation-id="bottom-navigation-next-button"]:has-text("Submit")',
    'button:has-text("Submit Application")',
    'button:has-text("Review and Submit")',
    'button:has-text("Submit")',
    '[role="button"][aria-label*="Submit" i]',
    'input[type="submit"]',
  ];

  const clickSubmitNow = async () => {
    for (const sel of submitSelectors) {
      try {
        const btn = page.locator(sel).first();
        await btn.waitFor({ state: 'visible', timeout: 700 });
        await btn.scrollIntoViewIfNeeded({ timeout: 1000 });
        const disabled = await btn.evaluate((el) => {
          const aria = (el.getAttribute('aria-disabled') || '').toLowerCase();
          return !!(el.disabled || aria === 'true');
        });
        if (disabled) {
          continue;
        }
        await humanDelay(150, 350);
        await btn.click({ timeout: 1800, force: true });
        evidenceParts.push(`Clicked Submit via ${sel}`);
        return true;
      } catch (_) {}
    }
    return false;
  };

  return await clickSubmitNow();
}

async function clickSaveAndContinueLaterButton(page, evidenceParts) {
  await dismissDiscardApplicationPopup(page, evidenceParts);

  const saveSelectors = [
    'button[data-automation-id="saveAndContinueLaterButton"]',
    'button[data-automation-id="bottom-navigation-save-button"]',
    '[data-automation-id="bottom-navigation"] button:has-text("Save and Continue Later")',
    'button:has-text("Save and Continue Later")',
    '[role="button"]:has-text("Save and Continue Later")',
    'a:has-text("Save and Continue Later")',
  ];

  for (const sel of saveSelectors) {
    try {
      const btn = page.locator(sel).first();
      await btn.waitFor({ state: 'visible', timeout: 700 });
      await btn.scrollIntoViewIfNeeded({ timeout: 1000 });
      const disabled = await btn.evaluate((el) => {
        const aria = (el.getAttribute('aria-disabled') || '').toLowerCase();
        return !!(el.disabled || aria === 'true');
      });
      if (disabled) {
        continue;
      }
      await humanDelay(150, 350);
      await btn.click({ timeout: 1800, force: true });
      evidenceParts.push(`Clicked Save and Continue Later via ${sel}`);
      return true;
    } catch (_) {}
  }

  const exitSelectors = [
    'button[aria-label*="close" i]',
    'button[aria-label*="exit" i]',
    '[role="button"][aria-label*="close" i]',
    'a:has-text("Back to Job Posting")',
    'button:has-text("Back to Job Posting")',
    'button:has-text("Cancel")',
    'button:has-text("Back")',
    '[role="button"]:has-text("X")',
    'button:has-text("X")',
  ];

  for (const sel of exitSelectors) {
    try {
      const btn = page.locator(sel).first();
      await btn.waitFor({ state: 'visible', timeout: 500 });
      await btn.scrollIntoViewIfNeeded({ timeout: 1000 }).catch(() => {});
      const disabled = await btn.evaluate((el) => {
        const aria = (el.getAttribute('aria-disabled') || '').toLowerCase();
        return !!(el.disabled || aria === 'true');
      });
      if (disabled) {
        continue;
      }
      await btn.click({ timeout: 1500, force: true });
      await humanDelay(300, 700);
      evidenceParts.push(`Opened exit/back flow via ${sel}`);

      for (const saveSel of saveSelectors) {
        try {
          const saveBtn = page.locator(saveSel).first();
          await saveBtn.waitFor({ state: 'visible', timeout: 900 });
          await saveBtn.scrollIntoViewIfNeeded({ timeout: 1000 }).catch(() => {});
          const saveDisabled = await saveBtn.evaluate((el) => {
            const aria = (el.getAttribute('aria-disabled') || '').toLowerCase();
            return !!(el.disabled || aria === 'true');
          });
          if (saveDisabled) {
            continue;
          }
          await humanDelay(120, 260);
          await saveBtn.click({ timeout: 1600, force: true });
          evidenceParts.push(`Clicked Save and Continue Later via exit modal ${saveSel}`);
          return true;
        } catch (_) {}
      }

      const inferredSaved = await isSaveAndContinueLaterConfirmed(page);
      if (inferredSaved) {
        evidenceParts.push('Inferred Save and Continue Later state after exit/back flow');
        return true;
      }
    } catch (_) {}
  }

  try {
    const clicked = await page.evaluate(() => {
      const candidates = Array.from(document.querySelectorAll('button, [role="button"], a, input[type="button"], input[type="submit"]'));
      const match = candidates.find((el) => {
        const txt = ((el.textContent || '') + ' ' + (el.getAttribute('value') || '') + ' ' + (el.getAttribute('aria-label') || '')).toLowerCase();
        const disabled = el.disabled || (el.getAttribute('aria-disabled') || '').toLowerCase() === 'true';
        return !disabled && /save and continue later/.test(txt);
      });
      if (match) {
        match.scrollIntoView({ behavior: 'instant', block: 'center' });
        match.click();
        return true;
      }
      return false;
    });
    if (clicked) {
      evidenceParts.push('Clicked Save and Continue Later via DOM fallback');
      return true;
    }
  } catch (_) {}

  return false;
}

async function isSaveAndContinueLaterConfirmed(page) {
  try {
    const url = (page.url() || '').toLowerCase();
    if (url.includes('/candidate-home') || url.includes('/userhome') || url.includes('/applications') || url.includes('/job-search') || url.includes('/dashboard')) {
      return true;
    }

    const state = await page.evaluate(() => {
      const text = (document.body?.innerText || '').toLowerCase();
      const hasSavedText = text.includes('saved')
        || text.includes('continue later')
        || text.includes('application has been saved')
        || text.includes('you can continue later');
      const hasContinueApplication = text.includes('continue application');

      const visible = (el) => {
        if (!el) return false;
        const r = el.getBoundingClientRect();
        const s = window.getComputedStyle(el);
        return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
      };

      const stillHasSaveLater = Array.from(document.querySelectorAll('button, [role="button"], a'))
        .filter(visible)
        .some((el) => {
          const t = ((el.textContent || '') + ' ' + (el.getAttribute('aria-label') || '')).toLowerCase();
          return /save and continue later/.test(t);
        });

      return { hasSavedText, stillHasSaveLater, hasContinueApplication };
    });

    if (state.hasSavedText) {
      return true;
    }

    if (state.hasContinueApplication && !url.includes('/apply')) {
      return true;
    }

    return !url.includes('/apply') && !state.stillHasSaveLater;
  } catch (_) {
    return false;
  }
}

async function isSubmissionConfirmed(page) {
  try {
    const url = (page.url() || '').toLowerCase();
    if (url.includes('/candidate-home') || url.includes('/userhome') || url.includes('/applications') || url.includes('/status')) {
      return true;
    }

    const state = await page.evaluate(() => {
      const text = (document.body?.innerText || '').toLowerCase();
      const hasSubmittedText = text.includes('application submitted')
        || text.includes('thank you for applying')
        || text.includes('thank you for your application')
        || text.includes('your application has been submitted')
        || text.includes('submission complete');

      const visible = (el) => {
        if (!el) return false;
        const r = el.getBoundingClientRect();
        const s = window.getComputedStyle(el);
        return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
      };

      const submitVisible = Array.from(document.querySelectorAll('button, [role="button"], input[type="submit"]'))
        .filter(visible)
        .some((el) => {
          const t = ((el.textContent || '') + ' ' + (el.getAttribute('value') || '') + ' ' + (el.getAttribute('aria-label') || '')).toLowerCase();
          return /submit/.test(t);
        });

      const pageError = text.includes('answer all required questions to submit this application') || text.includes('page error');
      return { hasSubmittedText, submitVisible, pageError };
    });

    if (state.pageError) {
      return false;
    }
    if (state.hasSubmittedText) {
      return true;
    }
    if (state.submitVisible && url.includes('/apply')) {
      return false;
    }

    return !url.includes('/apply');
  } catch (_) {
    return false;
  }
}

async function getVisibleActionLabels(page) {
  try {
    const labels = await page.evaluate(() => {
      const isVisible = (el) => {
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };
      return Array.from(document.querySelectorAll('button, a, [role="button"], input[type="submit"], input[type="button"]'))
        .filter((el) => isVisible(el))
        .map((el) => ((el.textContent || '') + ' ' + (el.getAttribute('value') || '') + ' ' + (el.getAttribute('aria-label') || '')).replace(/\s+/g, ' ').trim())
        .filter((text) => text.length > 0)
        .slice(0, 25);
    });
    return Array.isArray(labels) ? labels : [];
  } catch (_) {
    return [];
  }
}

async function resolveValidationErrorsFromProfile(page, profile, evidenceParts, resumePdfPath = '') {
  const resolved = [];
  try {
    const appAnswers = deriveApplicationQuestionAnswers(profile || {});
    let errorTexts = await page.locator('[data-automation-id="errorMessage"], [data-automation-id="inlineError"], .error-message-text, [data-automation-id*="error" i]')
      .allTextContents();
    let combined = (errorTexts || []).join(' ').toLowerCase();

    if (combined.includes('answer all required questions to submit this application') || combined.includes('page error')) {
      const opened = await openErrorsFoundSummary(page, evidenceParts);
      if (opened) {
        errorTexts = await page.locator('[data-automation-id="errorMessage"], [data-automation-id="inlineError"], .error-message-text, [data-automation-id*="error" i], [data-automation-id="errorHeading"] button, button.css-tgkpvs')
          .allTextContents()
          .catch(() => errorTexts);
        combined = (errorTexts || []).join(' ').toLowerCase();

        const headingResolved = await resolveErrorHeadingsFromProfile(page, profile, evidenceParts);
        for (const field of headingResolved) {
          if (!resolved.includes(field)) {
            resolved.push(field);
          }
        }
      }
    }
    const hasExperienceErrors = combined.includes('error-job title')
      || combined.includes('error-company')
      || combined.includes('error-from')
      || combined.includes('error-to');
    const hasEducationErrors = combined.includes('error-school or university')
      || combined.includes('error-degree')
      || combined.includes('error-end date')
      || combined.includes('error-graduation');

    if (hasExperienceErrors || hasEducationErrors) {
      let opened = await openExperienceEditorFromErrorLinks(page);
      if (!opened) {
        opened = await openMyExperienceEditor(page);
      }
      if (opened) {
        resolved.push('opened_experience_editor');
      }

      const editorVisible = await isExperienceEditorVisible(page);
      const inlineRowsPresent = await page.locator('input[name="jobTitle"][id*="workExperience-"]').count().catch(() => 0);
      if (editorVisible || inlineRowsPresent > 0 || hasEducationErrors) {
        let changed = false;

        const experienceEntries = extractExperienceEntries(profile || {});
        const firstExperience = (Array.isArray(experienceEntries) && experienceEntries.length > 0) ? experienceEntries[0] : null;
        if (hasExperienceErrors && firstExperience) {
          const expResolved =
            (await resolveRepeatedErrorLinks(page, 'Job Title', [firstExperience.job_title || profile?.experience_job_title || ''], 'text', 3)) +
            (await resolveRepeatedErrorLinks(page, 'Company', [firstExperience.company || profile?.experience_company || ''], 'text', 3)) +
            (await resolveRepeatedErrorLinks(page, 'From', [firstExperience.from || profile?.experience_from || ''], 'date', 3)) +
            (await resolveRepeatedErrorLinks(page, 'To', [firstExperience.to || profile?.experience_to || ''], 'date', 3));
          if (expResolved > 0) {
            changed = true;
            if (!resolved.includes('experience_error_links_resolved')) {
              resolved.push('experience_error_links_resolved');
            }
          }
        }

        const educationEntries = extractEducationEntries(profile || {});
        if (hasEducationErrors && educationEntries.length > 0) {
          const schools = educationEntries.map((e) => e?.school || '').filter(Boolean);
          const degrees = educationEntries.map((e) => e?.degree || '').filter(Boolean);
          const eduResolved =
            (await resolveRepeatedErrorLinks(page, 'School or University', schools, 'text', 6)) +
            (await resolveRepeatedErrorLinks(page, 'Degree', degrees, 'text', 6));
          if (eduResolved > 0) {
            changed = true;
            if (!resolved.includes('education_error_links_resolved')) {
              resolved.push('education_error_links_resolved');
            }
          }
        }

        const corrected = await forceFillExperienceFields(page, profile);
        for (const f of corrected.filled) {
          if (!resolved.includes(f)) {
            resolved.push(f);
          }
        }
        if (corrected.filled.length > 0) {
          changed = true;
        }

        const correctedEducation = await forceFillEducationFields(page, profile);
        for (const f of correctedEducation.filled) {
          if (!resolved.includes(f)) {
            resolved.push(f);
          }
        }
        if (correctedEducation.filled.length > 0) {
          changed = true;
        }

        if (changed) {
          const committed = await commitMyExperienceEditor(page);
          if (committed) {
            resolved.push('experience_editor_saved');
          }
        } else if (hasExperienceErrors || hasEducationErrors) {
          // Fallback: return to My Experience step explicitly, refill, and save.
          let reachedMyExperience = await isLikelyStillOnStep(page, 'my_experience');
          for (let hop = 0; hop < 4 && !reachedMyExperience; hop++) {
            const backed = await clickBackButton(page, evidenceParts);
            if (!backed) {
              break;
            }
            await humanDelay(900, 1700);
            reachedMyExperience = await isLikelyStillOnStep(page, 'my_experience');
          }

          if (reachedMyExperience) {
            const local = { fields_filled: [], fields_skipped: [], needs_manual_review: false };
            await handleMyExperience(page, profile, local);
            for (const f of (local.fields_filled || [])) {
              if (!resolved.includes(f)) {
                resolved.push(f);
              }
            }

            const committed = await commitMyExperienceEditor(page);
            if (committed && !resolved.includes('experience_editor_saved')) {
              resolved.push('experience_editor_saved');
            }

            for (let forward = 0; forward < 5; forward++) {
              const atReview = await isLikelyStillOnStep(page, 'review_submit');
              if (atReview) {
                break;
              }
              const moved = await clickContinueButton(page, evidenceParts);
              if (!moved) {
                break;
              }
              await humanDelay(900, 1700);
            }
          }
        }
      }
    }

    if (combined.includes('how did you hear about us') && profile.hear_about_us) {
      const linked = await clickErrorLinkAndAnswer(page, 'How Did You Hear About Us', profile.hear_about_us, 'dropdown');
      const r = (linked ? 'hear_about_us' : null)
        || await answerQuestionByXPathContainer(page, 'how did you hear about us', profile.hear_about_us, 'hear_about_us', 'dropdown')
        || await answerDropdownInQuestion(page, 'How Did You Hear About Us', profile.hear_about_us, 'hear_about_us')
        || await answerTextInQuestion(page, 'How Did You Hear About Us', profile.hear_about_us, 'hear_about_us')
        || await answerQuestionByDomFallback(page, 'How Did You Hear About Us', profile.hear_about_us, 'hear_about_us');
      if (r) {
        resolved.push(r);
      }
    }

    if (combined.includes('have you ever been employed') && profile.prior_company_employment) {
      const linked = await clickErrorLinkAndAnswer(page, 'Have you ever been employed', profile.prior_company_employment, 'radio');
      const r = (linked ? 'prior_company_employment' : null)
        || await answerQuestionByXPathContainer(page, 'have you ever been employed', profile.prior_company_employment, 'prior_company_employment', 'radio')
        || await answerRadioInQuestion(page, 'Have you ever been employed', profile.prior_company_employment, 'prior_company_employment')
        || await answerQuestionByDomFallback(page, 'Have you ever been employed', profile.prior_company_employment, 'prior_company_employment');
      if (r) {
        resolved.push(r);
      }

      if ((profile.prior_company_employment || '').toLowerCase() === 'yes') {
        if (profile.prior_company_wwid) {
          const ww = await answerQuestionByXPathContainer(page, 'wwid', profile.prior_company_wwid, 'prior_company_wwid', 'text')
            || await answerTextInQuestion(page, 'WWID', profile.prior_company_wwid, 'prior_company_wwid')
            || await answerQuestionByDomFallback(page, 'WWID', profile.prior_company_wwid, 'prior_company_wwid');
          if (ww) resolved.push(ww);
        }
        if (profile.prior_company_email) {
          const em = await answerQuestionByXPathContainer(page, 'if yes please provide wwid and email', profile.prior_company_email, 'prior_company_email', 'text')
            || await answerTextInQuestion(page, 'Email', profile.prior_company_email, 'prior_company_email')
            || await answerQuestionByDomFallback(page, 'Email', profile.prior_company_email, 'prior_company_email');
          if (em) resolved.push(em);
        }
      }
    }

    if (combined.includes('phone device type') && profile.phone_device_type) {
      const r = await answerQuestionByXPathContainer(page, 'phone device type', profile.phone_device_type, 'phone_device_type', 'dropdown')
        || await answerDropdownInQuestion(page, 'Phone Device Type', profile.phone_device_type, 'phone_device_type')
        || await answerQuestionByDomFallback(page, 'Phone Device Type', profile.phone_device_type, 'phone_device_type');
      if (r) {
        resolved.push(r);
      }
    }

    const requiredAppMappings = [
      { trigger: 'base salary expectation', needle: 'base salary expectation', answer: appAnswers.salary_expectation, field: 'salary_expectation', mode: 'text' },
      { trigger: 'require sponsorship', needle: 'require sponsorship', answer: appAnswers.requires_sponsorship, field: 'requires_sponsorship', mode: 'radio' },
      { trigger: 'restrict your ability to perform', needle: 'restrict your ability to perform', answer: appAnswers.restrictive_agreement, field: 'restrictive_agreement', mode: 'radio' },
      { trigger: 'proficiency of the english language', needle: 'proficiency of the english language', answer: appAnswers.english_proficiency, field: 'english_proficiency', mode: 'dropdown' },
      { trigger: 'willing to relocate', needle: 'willing to relocate', answer: appAnswers.willing_to_relocate, field: 'willing_to_relocate', mode: 'radio' },
      { trigger: 'legally authorized to work', needle: 'legally authorized to work', answer: appAnswers.work_authorized_us, field: 'work_authorized_us', mode: 'radio' },
      { trigger: 'years of experience', needle: 'years of experience', answer: appAnswers.years_experience, field: 'years_experience', mode: 'text' },
    ];

    for (const qa of requiredAppMappings) {
      if (!qa.answer || !combined.includes(qa.trigger)) {
        continue;
      }
      const r = await answerQuestionSmart(page, qa.needle, qa.answer, qa.field, qa.mode);
      if (r && !resolved.includes(r)) {
        resolved.push(r);
      }
    }

    if (combined.includes('base salary expectation')
      || combined.includes('require sponsorship')
      || combined.includes('restrict your ability to perform')
      || combined.includes('proficiency of the english language')
      || combined.includes('willing to relocate')
      || combined.includes('legally authorized to work')
      || combined.includes('years of experience')) {
      const compositeFilled = await fillPrimaryQuestionnaireCompositeInputs(page, profile);
      for (const field of compositeFilled) {
        if (!resolved.includes(field)) {
          resolved.push(field);
        }
      }
    }

    if (!hasExperienceErrors && combined.includes('error-job title') && profile.experience_job_title) {
      const r = await clickErrorLinkAndAnswer(page, 'Job Title', profile.experience_job_title, 'text')
        ? 'experience_job_title'
        : (await answerQuestionByXPathContainer(page, 'job title', profile.experience_job_title, 'experience_job_title', 'text'));
      if (r) resolved.push(r);
    }

    if (!hasExperienceErrors && combined.includes('error-company') && profile.experience_company) {
      const r = await clickErrorLinkAndAnswer(page, 'Company', profile.experience_company, 'text')
        ? 'experience_company'
        : (await answerQuestionByXPathContainer(page, 'company', profile.experience_company, 'experience_company', 'text'));
      if (r) resolved.push(r);
    }

    if (!hasExperienceErrors && combined.includes('error-from') && profile.experience_from) {
      const r = await clickErrorLinkAndAnswer(page, 'From', profile.experience_from, 'text')
        ? 'experience_from'
        : (await answerQuestionByXPathContainer(page, 'from', profile.experience_from, 'experience_from', 'text'));
      if (r) resolved.push(r);
    }

    if (!hasExperienceErrors && combined.includes('error-to') && profile.experience_to) {
      const r = await clickErrorLinkAndAnswer(page, 'To', profile.experience_to, 'text')
        ? 'experience_to'
        : (await answerQuestionByXPathContainer(page, 'to', profile.experience_to, 'experience_to', 'text'));
      if (r) resolved.push(r);
    }

    if (combined.includes('upload a file') && resumePdfPath) {
      const uploaded = await uploadRequiredFileIfPresent(page, resumePdfPath, evidenceParts);
      if (uploaded) {
        resolved.push('required_file_upload');
      }
    }

    if (combined.includes('i certify that i have read') || combined.includes('terms of the foregoing statement')) {
      const agreements = await checkRequiredAgreementCheckboxes(page, 'required_agreement');
      for (const field of agreements) {
        if (!resolved.includes(field)) {
          resolved.push(field);
        }
      }
    }

    if (combined.includes('answer all required questions to submit this application') || combined.includes('page error')) {
      const compositeFilled = await fillPrimaryQuestionnaireCompositeInputs(page, profile);
      for (const field of compositeFilled) {
        if (!resolved.includes(field)) {
          resolved.push(field);
        }
      }

      const globalFilled = await answerRequiredQuestionsGlobalFallback(page, profile);
      for (const field of globalFilled) {
        if (!resolved.includes(field)) {
          resolved.push(field);
        }
      }

      const selfIdentifyFilled = await completeSelfIdentifyRequiredControls(page, profile);
      for (const field of selfIdentifyFilled) {
        if (!resolved.includes(field)) {
          resolved.push(field);
        }
      }

      const agreements = await checkRequiredAgreementCheckboxes(page, 'required_agreement');
      for (const field of agreements) {
        if (!resolved.includes(field)) {
          resolved.push(field);
        }
      }
    }

    if (resolved.some((f) => f.startsWith('experience_'))) {
      const committed = await commitMyExperienceEditor(page);
      if (committed) {
        resolved.push('experience_editor_saved');
      }
    }

    const global = await answerRequiredQuestionsGlobalFallback(page, profile);
    for (const f of global) {
      if (!resolved.includes(f)) {
        resolved.push(f);
      }
    }

    if (resolved.length > 0) {
      evidenceParts.push(`Resolved validation fields: [${resolved.join(', ')}]`);
    }
  } catch (_) {}

  return resolved;
}

async function answerFocusedField(page, answerValue, mode = 'text') {
  if (!answerValue) return false;
  try {
    const normalized = normalizeMonthYear(answerValue);
    const isDateMode = mode === 'date' || (mode === 'text' && !!normalized);

    // Prefer true keyboard input on the actively focused field so Workday's
    // internal bindings see keystrokes/blur events.
    const activeReady = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el) return false;
      const tag = (el.tagName || '').toLowerCase();
      return tag === 'input' || tag === 'textarea' || el.isContentEditable || el.getAttribute('role') === 'combobox';
    }).catch(() => false);

    if (activeReady) {
      const before = await page.evaluate(() => {
        const el = document.activeElement;
        if (!el) return '';
        el.setAttribute('data-jh-focused-target', '1');
        return String(el.value || el.getAttribute('value') || el.textContent || '').trim();
      }).catch(() => '');

      if (isDateMode && normalized) {
        await page.keyboard.press('Control+A').catch(() => {});
        await page.keyboard.press('Backspace').catch(() => {});
        await page.keyboard.type(String(normalized.month), { delay: 12 }).catch(() => {});
        await page.keyboard.press('Tab').catch(() => {});
        await page.keyboard.press('Control+A').catch(() => {});
        await page.keyboard.press('Backspace').catch(() => {});
        await page.keyboard.type(String(normalized.year), { delay: 12 }).catch(() => {});
      } else {
        await page.keyboard.press('Control+A').catch(() => {});
        await page.keyboard.press('Backspace').catch(() => {});
        await page.keyboard.type(String(answerValue), { delay: 12 }).catch(() => {});
      }

      if (mode === 'dropdown') {
        await page.keyboard.press('Enter').catch(() => {});
      }
      await page.keyboard.press('Tab').catch(() => {});
      await humanDelay(120, 260);

      const after = await page.evaluate(() => {
        const tagged = document.querySelector('[data-jh-focused-target="1"]');
        const read = (el) => String(el?.value || el?.getAttribute?.('value') || el?.textContent || '').trim();
        const value = read(tagged || document.activeElement);
        if (tagged) tagged.removeAttribute('data-jh-focused-target');
        return value;
      }).catch(() => '');

      if (isDateMode && normalized) {
        const ok = visualMatch(String(after || ''), String(normalized.year)) || visualMatch(String(after || ''), String(normalized.month)) || String(after || '').length > 0;
        return !!ok;
      }
      return visualMatch(String(after || ''), String(answerValue || '')) || (!!after && !visualMatch(String(before || ''), String(after || '')));
    }

    return await page.evaluate(({ answerValue, mode }) => {
      const value = String(answerValue);
      const active = document.activeElement;
      const root = (active && active.closest && active.closest('div, fieldset, section, li, form')) || active || document.body;

      const dispatchSet = (el, val) => {
        el.focus();
        if ('value' in el) {
          el.value = val;
        }
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      };

      if (mode === 'radio') {
        const target = value.trim().toLowerCase();
        const radios = Array.from(root.querySelectorAll('label, [role="radio"], [data-automation-id="radioBtn"], button, div[role="button"]'));
        const hit = radios.find((el) => ((el.innerText || '').trim().toLowerCase() === target));
        if (hit) {
          hit.click();
          return true;
        }
        return false;
      }

      if (mode === 'dropdown') {
        const select = root.querySelector('select');
        if (select) {
          const opt = Array.from(select.options || []).find((o) => (o.text || '').toLowerCase().includes(value.toLowerCase()) || String(o.value || '').toLowerCase().includes(value.toLowerCase()));
          if (opt) {
            select.value = opt.value;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
          }
        }
        const input = root.querySelector('input[aria-haspopup="listbox"], [role="combobox"] input, input[type="text"], input[type="search"]');
        if (input) {
          dispatchSet(input, value);
          return true;
        }
        return false;
      }

      const input = root.querySelector('input[type="text"], input[type="email"], textarea, input:not([type]), input[type="search"]');
      if (input) {
        dispatchSet(input, value);
        return true;
      }
      return false;
    }, { answerValue, mode });
  } catch (_) {
    return false;
  }
}

async function clickErrorLinkAndAnswer(page, errorTextNeedle, answerValue, mode = 'text') {
  if (!errorTextNeedle || !answerValue) return false;
  try {
    const linkCandidates = [
      `[data-automation-id="errorHeading"] button:has-text("${errorTextNeedle}")`,
      `button.css-tgkpvs:has-text("${errorTextNeedle}")`,
      `button:has-text("Error"):has-text("${errorTextNeedle}")`,
      `a:has-text("${errorTextNeedle}")`,
      `[role="link"]:has-text("${errorTextNeedle}")`,
      `text=/Error-.*${errorTextNeedle}.*/i`,
    ];

    for (const sel of linkCandidates) {
      try {
        const link = page.locator(sel).first();
        await link.waitFor({ state: 'visible', timeout: 1000 });
        await link.click({ timeout: 1200, force: true });
        await humanDelay(200, 450);
        try {
          // Workday sometimes moves focus after one tab from the error summary button.
          await page.keyboard.press('Tab');
          await humanDelay(80, 160);
        } catch (_) {}
        let resolvedMode = mode;
        if ((/from|to/i).test(String(errorTextNeedle))) {
          resolvedMode = 'date';
        }
        const ok = await answerFocusedField(page, answerValue, resolvedMode);
        if (ok) {
          if (resolvedMode === 'dropdown') {
            try { await page.keyboard.press('Enter'); } catch (_) {}
          }
          return true;
        }
      } catch (_) {}
    }
  } catch (_) {}
  return false;
}

async function resolveReviewRequiredFieldsByErrorLinks(page, profile) {
  const resolved = [];
  const experienceEntries = extractExperienceEntries(profile || {});
  const firstExperience = (experienceEntries && experienceEntries.length > 0) ? experienceEntries[0] : null;
  const educationEntries = extractEducationEntries(profile || {});

  if (firstExperience) {
    if (String(firstExperience.job_title || '').trim()) {
      const n = await resolveRepeatedErrorLinks(page, 'Job Title', [firstExperience.job_title], 'text', 4);
      if (n > 0) resolved.push('experience_job_title_errorlink_visual');
    }
    if (String(firstExperience.company || '').trim()) {
      const n = await resolveRepeatedErrorLinks(page, 'Company', [firstExperience.company], 'text', 4);
      if (n > 0) resolved.push('experience_company_errorlink_visual');
    }
    if (String(firstExperience.from || '').trim()) {
      const n = await resolveRepeatedErrorLinks(page, 'From', [firstExperience.from], 'date', 4);
      if (n > 0) resolved.push('experience_from_errorlink_visual');
    }
    if (String(firstExperience.to || '').trim()) {
      const n = await resolveRepeatedErrorLinks(page, 'To', [firstExperience.to], 'date', 4);
      if (n > 0) resolved.push('experience_to_errorlink_visual');
    }
  }

  if (educationEntries.length > 0) {
    const schools = educationEntries.map((e) => String(e?.school || '').trim()).filter(Boolean);
    const degrees = educationEntries.map((e) => String(e?.degree || '').trim()).filter(Boolean);
    if (schools.length > 0) {
      const n = await resolveRepeatedErrorLinks(page, 'School or University', schools, 'dropdown', 8);
      if (n > 0) resolved.push('education_school_errorlink_visual');
    }
    if (degrees.length > 0) {
      const n = await resolveRepeatedErrorLinks(page, 'Degree', degrees, 'dropdown', 8);
      if (n > 0) resolved.push('education_degree_errorlink_visual');
    }
  }

  return resolved;
}

async function resolveRepeatedErrorLinks(page, errorTextNeedle, values, mode = 'text', maxAttempts = 6) {
  const queue = Array.isArray(values)
    ? values.map((v) => String(v || '').trim()).filter(Boolean)
    : [String(values || '').trim()].filter(Boolean);
  if (!errorTextNeedle || !queue.length) {
    return 0;
  }

  let resolved = 0;
  for (let attempt = 0; attempt < maxAttempts && queue.length > 0; attempt++) {
    const needle = String(errorTextNeedle || '').replace(/"/g, '\\"');
    const pending = await page.locator(`[data-automation-id="errorHeading"] button:has-text("${needle}"), button.css-tgkpvs:has-text("${needle}"), button:has-text("Error"):has-text("${needle}"), a:has-text("${needle}"), [role="link"]:has-text("${needle}")`).count().catch(() => 0);
    if (pending <= 0) {
      break;
    }

    const answerValue = queue.shift();
    const ok = await clickErrorLinkAndAnswer(page, errorTextNeedle, answerValue, mode);
    if (!ok) {
      queue.unshift(answerValue);
      break;
    }
    resolved += 1;
    await humanDelay(180, 360);
  }

  return resolved;
}

async function fillByVisibleLabelWithVerification(page, {
  label,
  value,
  occurrence = 0,
  mode = 'text',
  fieldTag = '',
  pressEnter = false,
}) {
  const desiredValue = String(value || '').trim();
  if (!label || !desiredValue) {
    return { ok: false, reason: 'missing_label_or_value' };
  }

  const token = `jh-vis-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  const setup = await page.evaluate(({ label, occurrence, mode, token }) => {
    const normalize = (v) => String(v || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const needle = normalize(label);

    const isVisible = (el) => {
      if (!el) return false;
      const rect = el.getBoundingClientRect();
      const style = window.getComputedStyle(el);
      return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
    };

    const readValue = (el) => {
      if (!el) return '';
      const tag = String(el.tagName || '').toLowerCase();
      if (tag === 'select') {
        const opt = el.options && el.selectedIndex >= 0 ? el.options[el.selectedIndex] : null;
        return String(opt?.textContent || el.value || '').trim();
      }
      return String(el.value || el.getAttribute('value') || el.textContent || el.getAttribute('aria-label') || '').trim();
    };

    const nodes = Array.from(document.querySelectorAll('label, legend, span, div, p, strong, h3, h4, h5'))
      .filter(isVisible)
      .filter((el) => {
        const text = normalize(el.innerText || el.textContent || '');
        if (!text || text.length > 260) return false;
        if (text.startsWith('error-') || text.includes('the field') || text.includes('required and must have a value')) return false;
        return text.includes(needle);
      });

    const anchors = [];
    for (const node of nodes) {
      let current = node;
      let depth = 0;
      while (current && depth < 7) {
        const controls = Array.from(current.querySelectorAll('input, textarea, select, button, [role="combobox"], [role="button"]')).filter(isVisible);
        const text = normalize(current.innerText || current.textContent || '');
        if (controls.length > 0 && text.includes(needle) && text.length < 260) {
          if (!anchors.includes(current)) {
            anchors.push(current);
          }
          break;
        }
        current = current.parentElement;
        depth += 1;
      }
    }

    if (!anchors.length) {
      return { ok: false, reason: 'container_not_found' };
    }

    anchors.sort((a, b) => a.getBoundingClientRect().y - b.getBoundingClientRect().y);
    const container = anchors[Math.min(Math.max(0, Number(occurrence) || 0), anchors.length - 1)];

    const controls = Array.from(container.querySelectorAll('input, textarea, select, button, [role="combobox"], [role="button"]')).filter(isVisible);
    if (!controls.length) {
      return { ok: false, reason: 'controls_not_found' };
    }

    for (const el of document.querySelectorAll('[data-jh-visual-target]')) {
      el.removeAttribute('data-jh-visual-target');
      el.removeAttribute('data-jh-visual-part');
    }
    for (const el of document.querySelectorAll('[data-jh-visual-scope]')) {
      el.removeAttribute('data-jh-visual-scope');
    }

    const markScope = (el) => {
      const scope = el?.closest?.('div, section, li, fieldset, form') || el;
      if (scope && scope.setAttribute) {
        scope.setAttribute('data-jh-visual-scope', token);
      }
    };

    const meta = (el) => normalize(`${el.id || ''} ${el.getAttribute('name') || ''} ${el.getAttribute('aria-label') || ''} ${el.getAttribute('placeholder') || ''}`);
    const fieldMatchers = {
      'job title': [/jobtitle/i, /job title/i, /title/i],
      'company': [/company/i],
      'role description': [/roledescription/i, /role description/i, /description/i],
      'school or university': [/school/i, /university/i, /institution/i],
      'degree': [/degree/i, /fieldofstudy/i, /major/i],
      'from': [/startdate/i, /from/i],
      'to': [/enddate/i, /to/i],
    };
    const inferMatchers = () => {
      if (fieldMatchers[needle]) return fieldMatchers[needle];
      if (/school|university|institution|type your school/i.test(needle)) {
        return fieldMatchers['school or university'];
      }
      if (/degree|field of study|major/i.test(needle)) {
        return fieldMatchers.degree;
      }
      if (/job title|title/i.test(needle)) {
        return fieldMatchers['job title'];
      }
      if (/company|employer/i.test(needle)) {
        return fieldMatchers.company;
      }
      if (/from|start/i.test(needle)) {
        return fieldMatchers.from;
      }
      if (/to|end/i.test(needle)) {
        return fieldMatchers.to;
      }
      return [];
    };
    const matchers = inferMatchers();
    const dateLike = controls.filter((el) => {
      const tag = String(el.tagName || '').toLowerCase();
      if (tag === 'button') return false;
      const type = String(el.getAttribute('type') || '').toLowerCase();
      return !['hidden', 'checkbox', 'radio', 'file'].includes(type);
    });

    const textLike = dateLike.filter((el) => {
      const tag = String(el.tagName || '').toLowerCase();
      if (tag === 'textarea' || tag === 'select') return true;
      const m = meta(el);
      if (/datesection(month|year)|\bmonth\b|\byear\b|\bmm\b|\byyyy\b/.test(m)) return false;
      return true;
    });

    const payload = { ok: true, before: '', beforeMonth: '', beforeYear: '', controlType: mode, reason: '' };

    if (mode === 'date') {
      const dateScoped = controls.filter((el) => matchers.some((rx) => rx.test(meta(el))));
      const dateControls = (dateScoped.length ? dateScoped : dateLike).filter((el) => {
        const m = meta(el);
        const tag = String(el.tagName || '').toLowerCase();
        return tag === 'input' || /date|month|year|mm|yyyy/.test(m);
      });
      const monthControl = dateControls.find((el) => /month|\bmm\b/.test(meta(el))) || dateControls[0] || null;
      const yearControl = dateControls.find((el) => /year|\byyyy\b/.test(meta(el))) || dateControls.find((el) => el !== monthControl) || dateControls[1] || null;
      if (!monthControl || !yearControl) {
        return { ok: false, reason: 'date_controls_not_found' };
      }
      monthControl.setAttribute('data-jh-visual-target', token);
      monthControl.setAttribute('data-jh-visual-part', 'month');
      yearControl.setAttribute('data-jh-visual-target', token);
      yearControl.setAttribute('data-jh-visual-part', 'year');
      payload.beforeMonth = readValue(monthControl);
      payload.beforeYear = readValue(yearControl);
      return payload;
    }

    if (mode === 'dropdown') {
      const preferred = controls.find((el) => {
        const tag = String(el.tagName || '').toLowerCase();
        const m = meta(el);
        return (matchers.length === 0 || matchers.some((rx) => rx.test(m)))
          && (tag === 'select'
          || tag === 'button'
          || /select one|degree|dropdown|combobox|option/.test(m)
          || el.getAttribute('role') === 'combobox');
      }) || controls[0];
      preferred.setAttribute('data-jh-visual-target', token);
      preferred.setAttribute('data-jh-visual-part', 'single');
      markScope(preferred);
      payload.before = readValue(preferred);
      return payload;
    }

    const preferredText = textLike.find((el) => matchers.some((rx) => rx.test(meta(el))));
    const inputLike = controls.filter((el) => {
      const tag = String(el.tagName || '').toLowerCase();
      if (tag === 'button') return false;
      if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
      const role = String(el.getAttribute('role') || '').toLowerCase();
      if (role === 'combobox' || role === 'textbox' || role === 'searchbox') return true;
      const editable = String(el.getAttribute('contenteditable') || '').toLowerCase();
      return editable === 'true';
    });
    const schoolLike = /school|university|institution|type your school/i.test(needle);
    const schoolPreferred = schoolLike
      ? inputLike.find((el) => {
          const m = meta(el);
          return /school|university|institution|search|type your school|autocomplete|combobox|prompt/i.test(m);
        }) || textLike.find((el) => {
          const m = meta(el);
          return /school|university|institution|search|type your school|autocomplete|combobox|prompt/i.test(m);
        })
      : null;
    const input = preferredText || schoolPreferred || textLike[0] || inputLike[0] || controls.find((el) => String(el.tagName || '').toLowerCase() !== 'button') || controls[0];
    input.setAttribute('data-jh-visual-target', token);
    input.setAttribute('data-jh-visual-part', 'single');
    markScope(input);
    payload.before = readValue(input);
    return payload;
  }, { label, occurrence, mode, token });

  if (!setup?.ok) {
    pushFieldAudit(fieldTag || `${label}_${occurrence + 1}`, desiredValue, '', false, `visual-target-missing:${setup?.reason || 'unknown'}`);
    return { ok: false, reason: setup?.reason || 'setup_failed' };
  }

  let writeOk = false;
  if (mode === 'date') {
    const parsed = normalizeMonthYear(desiredValue);
    if (parsed && parsed.month && parsed.year) {
      const monthLocator = page.locator(`[data-jh-visual-target="${token}"][data-jh-visual-part="month"]`).first();
      const yearLocator = page.locator(`[data-jh-visual-target="${token}"][data-jh-visual-part="year"]`).first();
      try {
        await monthLocator.click({ timeout: 1200, force: true });
        await page.keyboard.press('Control+A').catch(() => {});
        await page.keyboard.press('Backspace').catch(() => {});
        await page.keyboard.type(String(parsed.month), { delay: 10 }).catch(() => {});
        await yearLocator.click({ timeout: 1200, force: true });
        await page.keyboard.press('Control+A').catch(() => {});
        await page.keyboard.press('Backspace').catch(() => {});
        await page.keyboard.type(String(parsed.year), { delay: 10 }).catch(() => {});
        await page.keyboard.press('Tab').catch(() => {});
        writeOk = true;
      } catch (_) {}
    }
  } else if (mode === 'dropdown') {
    const target = page.locator(`[data-jh-visual-target="${token}"]`).first();
    try {
      const tag = await target.evaluate((el) => String(el.tagName || '').toLowerCase()).catch(() => '');
      if (tag === 'select') {
        const selected = await target.evaluate((el, desiredValue) => {
          const options = Array.from(el.options || []);
          const normalize = (v) => String(v || '').trim().toLowerCase();
          const desired = normalize(desiredValue);
          const exact = options.find((o) => normalize(o.textContent || o.value) === desired || normalize(o.value) === desired);
          const partial = options.find((o) => normalize(o.textContent || o.value).includes(desired));
          const picked = exact || partial;
          if (!picked) return false;
          el.value = picked.value;
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
          el.dispatchEvent(new Event('blur', { bubbles: true }));
          return true;
        }, desiredValue).catch(() => false);
        writeOk = !!selected;
      } else {
        await target.click({ timeout: 1200, force: true });
        await humanDelay(120, 260);
        const optionsToTry = [
          desiredValue,
          desiredValue.split(/[\-,:]/)[0].trim(),
          desiredValue.split(/\s+/).slice(0, 2).join(' ').trim(),
        ].filter(Boolean);

        for (const optLabel of optionsToTry) {
          const selectors = [
            `[role="option"]:has-text("${optLabel}")`,
            `li[role="option"]:has-text("${optLabel}")`,
            `li[role="menuitem"]:has-text("${optLabel}")`,
            `[data-automation-id="promptOption"]:has-text("${optLabel}")`,
            `div[role="option"]:has-text("${optLabel}")`,
          ];
          for (const selector of selectors) {
            try {
              const option = page.locator(selector).first();
              await option.waitFor({ state: 'visible', timeout: 700 });
              await option.click({ timeout: 900, force: true });
              writeOk = true;
              break;
            } catch (_) {}
          }
          if (writeOk) break;
        }
      }
      await page.keyboard.press('Tab').catch(() => {});
    } catch (_) {}
  } else {
    const target = page.locator(`[data-jh-visual-target="${token}"]`).first();
    try {
      await target.click({ timeout: 1200, force: true });
      await page.keyboard.press('Control+A').catch(() => {});
      await page.keyboard.press('Backspace').catch(() => {});
      await page.keyboard.type(desiredValue, { delay: 10 }).catch(() => {});
      if (pressEnter) {
        await page.keyboard.press('Enter').catch(() => {});
        await humanDelay(90, 180);
      }
      await page.keyboard.press('Tab').catch(() => {});
      writeOk = true;
    } catch (_) {}
  }

  await humanDelay(140, 300);

  const after = await page.evaluate(({ token, mode, desiredValue }) => {
    const readValue = (el) => {
      if (!el) return '';
      const tag = String(el.tagName || '').toLowerCase();
      if (tag === 'select') {
        const opt = el.options && el.selectedIndex >= 0 ? el.options[el.selectedIndex] : null;
        return String(opt?.textContent || el.value || '').trim();
      }
      return String(el.value || el.getAttribute('value') || el.textContent || el.getAttribute('aria-label') || '').trim();
    };

    const normalize = (v) => String(v || '').replace(/\s+/g, ' ').trim().toLowerCase();

    if (mode === 'date') {
      const month = document.querySelector(`[data-jh-visual-target="${token}"][data-jh-visual-part="month"]`);
      const year = document.querySelector(`[data-jh-visual-target="${token}"][data-jh-visual-part="year"]`);
      return {
        month: readValue(month),
        year: readValue(year),
      };
    }

    const el = document.querySelector(`[data-jh-visual-target="${token}"]`);
    const scope = document.querySelector(`[data-jh-visual-scope="${token}"]`);
    const desired = normalize(desiredValue);

    const scanRoots = [];
    if (scope) scanRoots.push(scope);
    if (scope?.parentElement) scanRoots.push(scope.parentElement);
    if (scope?.parentElement?.parentElement) scanRoots.push(scope.parentElement.parentElement);
    if (scope?.parentElement?.parentElement?.parentElement) scanRoots.push(scope.parentElement.parentElement.parentElement);

    const formFieldRoot = el?.closest?.('[data-automation-id="formField"], [role="group"], fieldset, li, section, form, div');
    if (formFieldRoot) scanRoots.push(formFieldRoot);
    if (formFieldRoot?.parentElement) scanRoots.push(formFieldRoot.parentElement);

    const tokenSelectors = [
      '[data-automation-id*="token" i]',
      '[data-automation-id*="pill" i]',
      '[data-automation-id*="selection" i]',
      '[data-automation-id*="selected" i]',
      '[data-automation-id*="chip" i]',
      '[data-automation-id*="tag" i]',
      '[role="option"][aria-selected="true"]',
      '[aria-label*="remove" i]',
      'button[aria-label*="remove" i]',
      'li',
      'span',
      'div',
    ];

    const tokenTexts = [];
    for (const root of scanRoots) {
      if (!root || !root.querySelectorAll) continue;
      for (const sel of tokenSelectors) {
        const nodes = Array.from(root.querySelectorAll(sel));
        for (const node of nodes) {
          const txt = String(node.textContent || node.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim();
          if (!txt) continue;
          if (txt.length > 200) continue;
          if (normalize(txt).includes(desired) || desired.includes(normalize(txt))) {
            tokenTexts.push(txt);
          }
        }
      }
      if (tokenTexts.length > 0) break;
    }

    return {
      value: readValue(el),
      scopeText: String(scope?.innerText || scope?.textContent || '').replace(/\s+/g, ' ').trim(),
      tokenTexts,
    };
  }, { token, mode, desiredValue }).catch(() => ({}));

  const clearTempTargets = async () => {
    await page.evaluate(() => {
      for (const el of document.querySelectorAll('[data-jh-visual-target]')) {
        el.removeAttribute('data-jh-visual-target');
        el.removeAttribute('data-jh-visual-part');
      }
      for (const el of document.querySelectorAll('[data-jh-visual-scope]')) {
        el.removeAttribute('data-jh-visual-scope');
      }
    }).catch(() => {});
  };

  let confirmed = false;
  if (mode === 'date') {
    const parsed = normalizeMonthYear(desiredValue);
    if (parsed && parsed.month && parsed.year) {
      confirmed = visualMatch(String(after.month || ''), String(parsed.month)) && visualMatch(String(after.year || ''), String(parsed.year));
    }
    pushFieldAudit(fieldTag || `${label}_${occurrence + 1}`, desiredValue, `${after.month || ''}/${after.year || ''}`, writeOk && confirmed, writeOk && confirmed ? 'visual-before-after-confirmed' : 'visual-before-after-failed');
  } else if (mode === 'dropdown') {
    const afterValue = String(after.value || '');
    confirmed = !!afterValue && !/select one|0 items selected/i.test(afterValue) && (visualMatch(afterValue, desiredValue) || afterValue.length > 0);
    pushFieldAudit(fieldTag || `${label}_${occurrence + 1}`, desiredValue, afterValue, writeOk && confirmed, writeOk && confirmed ? 'visual-before-after-confirmed' : 'visual-before-after-failed');
  } else {
    const afterValue = String(after.value || '');
    const scopeText = String(after.scopeText || '');
    const tokenTexts = Array.isArray(after.tokenTexts) ? after.tokenTexts.map((x) => String(x || '')) : [];
    if (pressEnter) {
      const byValue = !!afterValue && !/select one|0 items selected/i.test(afterValue) && (visualMatch(afterValue, desiredValue) || afterValue.length > 0);
      const byScope = !!scopeText && visualMatch(scopeText, desiredValue);
      const byToken = tokenTexts.some((txt) => visualMatch(txt, desiredValue));
      const schoolField = /education(_\d+)?_school|school|university|institution/i.test(`${fieldTag || ''} ${label || ''}`);
      confirmed = schoolField ? (byToken || byValue) : (byValue || byScope || byToken);
    } else {
      confirmed = visualMatch(afterValue, desiredValue);
    }
    const observed = [afterValue, scopeText, tokenTexts.join(' | ')].filter(Boolean).join(' || ');
    pushFieldAudit(fieldTag || `${label}_${occurrence + 1}`, desiredValue, observed, writeOk && confirmed, writeOk && confirmed ? 'visual-before-after-confirmed' : 'visual-before-after-failed');
  }

  await clearTempTargets();

  return { ok: writeOk && confirmed, before: setup, after };
}

async function fillByVisibleLabelWithRetry(page, {
  labels,
  value,
  occurrence,
  mode,
  fieldTag,
  pressEnter = false,
}) {
  const labelList = Array.isArray(labels)
    ? labels.map((x) => String(x || '').trim()).filter(Boolean)
    : [String(labels || '').trim()].filter(Boolean);
  const valueText = String(value || '').trim();
  if (!labelList.length || !valueText) {
    return { ok: false };
  }

  const occ = Math.max(0, Number(occurrence) || 0);
  const tries = [occ, occ + 1, Math.max(0, occ - 1), occ + 2, occ + 3]
    .filter((v, i, arr) => arr.indexOf(v) === i)
    .slice(0, 4);

  for (const label of labelList) {
    for (const currentOccurrence of tries) {
      const result = await fillByVisibleLabelWithVerification(page, {
        label,
        value: valueText,
        occurrence: currentOccurrence,
        mode,
        fieldTag,
        pressEnter,
      });
      if (result?.ok) {
        return { ok: true, label, occurrence: currentOccurrence };
      }
    }
  }

  return { ok: false };
}

async function openErrorsFoundSummary(page, evidenceParts = null) {
  const selectors = [
    'button:has-text("Errors Found")',
    '[role="button"]:has-text("Errors Found")',
    '[data-automation-id="errorHeading"] button',
    'button.css-tgkpvs',
  ];

  for (const sel of selectors) {
    try {
      const btn = page.locator(sel).first();
      await btn.waitFor({ state: 'visible', timeout: 1000 });
      await btn.click({ timeout: 1500, force: true });
      await humanDelay(350, 900);
      if (Array.isArray(evidenceParts)) {
        evidenceParts.push(`Opened errors summary via ${sel}`);
      }
      return true;
    } catch (_) {}
  }
  return false;
}

async function resolveErrorHeadingsFromProfile(page, profile, evidenceParts = null) {
  const answers = deriveApplicationQuestionAnswers(profile || {});
  const mappings = [
    { key: 'base salary expectation', field: 'salary_expectation', value: answers.salary_expectation, mode: 'text' },
    { key: 'require sponsorship', field: 'requires_sponsorship', value: answers.requires_sponsorship, mode: 'radio' },
    { key: 'restrict your ability to perform', field: 'restrictive_agreement', value: answers.restrictive_agreement, mode: 'radio' },
    { key: 'proficiency of the english language', field: 'english_proficiency', value: answers.english_proficiency, mode: 'dropdown' },
    { key: 'willing to relocate', field: 'willing_to_relocate', value: answers.willing_to_relocate, mode: 'radio' },
    { key: 'legally authorized to work', field: 'work_authorized_us', value: answers.work_authorized_us, mode: 'radio' },
    { key: 'years of experience', field: 'years_experience', value: answers.years_experience, mode: 'text' },
    { key: 'i certify that i have read', field: 'required_agreement', value: 'Yes', mode: 'radio' },
  ];

  const resolved = [];
  try {
    const buttons = page.locator('[data-automation-id="errorHeading"] button, button.css-tgkpvs');
    const count = await buttons.count().catch(() => 0);
    for (let i = 0; i < count; i++) {
      const btn = buttons.nth(i);
      const raw = await btn.textContent().catch(() => '');
      const text = String(raw || '').toLowerCase().replace(/\s+/g, ' ').trim();
      const hit = mappings.find((m) => text.includes(m.key) && m.value);
      if (!hit) continue;

      await btn.click({ timeout: 1400, force: true }).catch(() => {});
      await humanDelay(180, 380);

      let ok = await answerFocusedField(page, hit.value, hit.mode);
      if (!ok) {
        ok = !!(await answerQuestionSmart(page, hit.key, hit.value, hit.field, hit.mode));
      }

      if (ok && !resolved.includes(hit.field)) {
        resolved.push(hit.field);
      }
    }
  } catch (_) {}

  if (resolved.length > 0 && Array.isArray(evidenceParts)) {
    evidenceParts.push(`Resolved error headings: [${resolved.join(', ')}]`);
  }
  return resolved;
}

async function uploadRequiredFileIfPresent(page, filePath, evidenceParts) {
  if (!filePath) return false;
  try {
    const fileInputs = [
      'input[type="file"]',
      '[data-automation-id*="file" i] input[type="file"]',
      '[data-automation-id*="upload" i] input[type="file"]',
    ];

    for (const sel of fileInputs) {
      try {
        const input = page.locator(sel).first();
        await input.waitFor({ state: 'attached', timeout: 1200 });
        await input.setInputFiles(filePath, { timeout: 3000 });
        evidenceParts.push('Uploaded required file via ' + sel);
        return true;
      } catch (_) {}
    }
  } catch (_) {}
  return false;
}

async function isExperienceEditorVisible(page) {
  try {
    const visible = await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const roots = Array.from(document.querySelectorAll('div[role="dialog"], [data-automation-id*="workexperience" i], [data-automation-id*="workExperience" i]'))
        .filter(isVisible);
      const root = roots[0] || document;
      const txt = (root.innerText || '').toLowerCase();
      return txt.includes('job title') && txt.includes('company') && txt.includes('from') && txt.includes('to');
    });
    return !!visible;
  } catch (_) {
    return false;
  }
}

async function openExperienceEditorFromErrorLinks(page) {
  const labels = ['Job Title', 'Company', 'From', 'To'];
  for (const label of labels) {
    const selectors = [
      `[data-automation-id="errorHeading"] button:has-text("${label}")`,
      `button.css-tgkpvs:has-text("${label}")`,
      `button:has-text("Error"):has-text("${label}")`,
      `a:has-text("${label}")`,
      `[role="link"]:has-text("${label}")`,
      `text=/Error-.*${label}.*/i`,
    ];
    for (const sel of selectors) {
      try {
        const link = page.locator(sel).first();
        await link.waitFor({ state: 'visible', timeout: 700 });
        await link.click({ timeout: 1100, force: true });
        await humanDelay(220, 450);
        if (await isExperienceEditorVisible(page)) {
          return true;
        }
      } catch (_) {}
    }
  }
  return false;
}

async function openMyExperienceEditor(page) {
  try {
    const inlineRows = await page.locator('input[name="jobTitle"][id*="workExperience-"]').count().catch(() => 0);
    if (inlineRows > 0) {
      return true;
    }
  } catch (_) {}

  if (await isExperienceEditorVisible(page)) {
    return true;
  }

  // Section-scoped path: click Add inside the Work Experience section.
  try {
    const sectionClicked = await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const sections = Array.from(document.querySelectorAll('section, div, fieldset')).filter((el) => {
        const txt = (el.innerText || '').toLowerCase();
        return txt.includes('work experience');
      });

      for (const section of sections) {
        const btn = section.querySelector('[data-automation-id="add-button"], button[data-automation-id="add-button"], button');
        if (!btn || !isVisible(btn)) {
          continue;
        }
        const txt = ((btn.textContent || '') + ' ' + (btn.getAttribute('aria-label') || '')).toLowerCase();
        if (txt.includes('add')) {
          btn.click();
          return true;
        }
      }
      return false;
    });

    if (sectionClicked) {
      await humanDelay(320, 650);
      if (await isExperienceEditorVisible(page)) {
        return true;
      }
    }
  } catch (_) {}

  const directSelectors = [
    'button:has-text("Add Work Experience")',
    'button:has-text("Edit Work Experience")',
    '[aria-label*="Add Work Experience" i]',
    '[data-automation-id*="workExperience" i] button:has-text("Add")',
    '[data-automation-id*="workExperience" i] button:has-text("Edit")',
    'button:has-text("Add Experience")',
  ];

  for (const sel of directSelectors) {
    try {
      const btn = page.locator(sel).first();
      await btn.waitFor({ state: 'visible', timeout: 1000 });
      await btn.scrollIntoViewIfNeeded({ timeout: 800 });
      await btn.click({ timeout: 1600, force: true });
      await humanDelay(350, 700);
      if (await isExperienceEditorVisible(page)) {
        return true;
      }
    } catch (_) {}
  }

  // Generic Add button fallback: click Add, then choose Work Experience option.
  const genericAddSelectors = [
    'button:has-text("Add")',
    '[aria-label*="Add" i]',
    '[data-automation-id*="add" i] button',
  ];

  for (const sel of genericAddSelectors) {
    try {
      const btn = page.locator(sel).first();
      await btn.waitFor({ state: 'visible', timeout: 900 });
      await btn.scrollIntoViewIfNeeded({ timeout: 800 });
      await btn.click({ timeout: 1400, force: true });
      await humanDelay(200, 450);

      const menuSelectors = [
        'li[role="menuitem"]:has-text("Work Experience")',
        '[role="option"]:has-text("Work Experience")',
        '[data-automation-id="promptOption"]:has-text("Work Experience")',
        'div[role="button"]:has-text("Work Experience")',
        'button:has-text("Work Experience")',
      ];
      for (const menuSel of menuSelectors) {
        try {
          const opt = page.locator(menuSel).first();
          await opt.waitFor({ state: 'visible', timeout: 900 });
          await opt.click({ timeout: 1300, force: true });
          await humanDelay(300, 600);
          if (await isExperienceEditorVisible(page)) {
            return true;
          }
        } catch (_) {}
      }

      if (await isExperienceEditorVisible(page)) {
        return true;
      }
    } catch (_) {}
  }

  return false;
}

async function commitMyExperienceEditor(page) {
  const saveSelectors = [
    'button:has-text("Save")',
    'button:has-text("Done")',
    'button:has-text("Apply")',
    'button[data-automation-id="wd-CommandButton_uic_okButton"]',
    '[data-automation-id*="save" i] button',
    'div[role="dialog"] button:has-text("Save")',
  ];

  for (const sel of saveSelectors) {
    try {
      const btn = page.locator(sel).first();
      await btn.waitFor({ state: 'visible', timeout: 1200 });
      const disabled = await btn.evaluate((el) => {
        const aria = (el.getAttribute('aria-disabled') || '').toLowerCase();
        return !!(el.disabled || aria === 'true');
      });
      if (disabled) continue;
      await btn.scrollIntoViewIfNeeded({ timeout: 800 });
      await btn.click({ timeout: 1800, force: true });
      await humanDelay(350, 700);
      return true;
    } catch (_) {}
  }
  return false;
}

function normalizeMonthYear(value) {
  const raw = String(value || '').trim();
  if (!raw) return null;

  const m1 = raw.match(/^(\d{1,2})\/(\d{4})$/);
  if (m1) {
    const month = Math.max(1, Math.min(12, parseInt(m1[1], 10)));
    return { month: String(month).padStart(2, '0'), year: m1[2] };
  }

  const m2 = raw.match(/^(\d{4})-(\d{1,2})(?:-\d{1,2})?$/);
  if (m2) {
    const month = Math.max(1, Math.min(12, parseInt(m2[2], 10)));
    return { month: String(month).padStart(2, '0'), year: m2[1] };
  }

  const m3 = raw.match(/^(\d{4})$/);
  if (m3) {
    return { month: '01', year: m3[1] };
  }

  const monthMap = {
    jan: '01', feb: '02', mar: '03', apr: '04', may: '05', jun: '06',
    jul: '07', aug: '08', sep: '09', sept: '09', oct: '10', nov: '11', dec: '12',
  };
  const m4 = raw.toLowerCase().match(/^([a-z]{3,9})\s+(\d{4})$/);
  if (m4) {
    const key = m4[1].slice(0, 4);
    const short = m4[1].slice(0, 3);
    const month = monthMap[key] || monthMap[short] || '01';
    return { month, year: m4[2] };
  }

  return null;
}

async function fillExperienceDateField(page, labelNeedle, value, fieldKey) {
  const normalized = normalizeMonthYear(value);
  if (!normalized) {
    const generic = await answerQuestionByXPathContainer(page, labelNeedle, value, fieldKey, 'text')
      || await answerQuestionByDomFallback(page, labelNeedle, value, fieldKey)
      || await clickErrorLinkAndAnswer(page, labelNeedle, value, 'text');
    return !!generic;
  }

  const didFill = await page.evaluate(({ labelNeedle, month, year }) => {
    const needle = String(labelNeedle || '').toLowerCase();

    const isVisible = (el) => {
      if (!el) return false;
      const rect = el.getBoundingClientRect();
      const style = window.getComputedStyle(el);
      return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
    };

    const setInput = (el, val) => {
      if (!el || !isVisible(el)) return false;
      el.focus();
      el.value = '';
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.value = String(val);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      el.dispatchEvent(new Event('blur', { bubbles: true }));
      return true;
    };

    const containers = Array.from(document.querySelectorAll('div, section, fieldset, li, form')).filter((el) => {
      const txt = (el.innerText || '').toLowerCase();
      return txt.includes(needle) && (el.querySelector('input, textarea, select') || null);
    });
    if (!containers.length) {
      return false;
    }

    const container = containers[0];
    const monthInput = container.querySelector('input[data-automation-id*="month" i], input[placeholder*="MM" i], input[aria-label*="month" i]');
    const yearInput = container.querySelector('input[data-automation-id*="year" i], input[placeholder*="YYYY" i], input[aria-label*="year" i]');

    if (monthInput && yearInput) {
      const a = setInput(monthInput, month);
      const b = setInput(yearInput, year);
      return a && b;
    }

    const textInputs = Array.from(container.querySelectorAll('input[type="text"], input:not([type]), input[type="search"]')).filter(isVisible);
    if (textInputs.length >= 2) {
      const a = setInput(textInputs[0], month);
      const b = setInput(textInputs[1], year);
      return a && b;
    }

    if (textInputs.length === 1) {
      return setInput(textInputs[0], `${month}/${year}`);
    }

    return false;
  }, { labelNeedle, month: normalized.month, year: normalized.year });

  if (didFill) {
    return true;
  }

  const generic = await answerQuestionByXPathContainer(page, labelNeedle, `${normalized.month}/${normalized.year}`, fieldKey, 'text')
    || await answerQuestionByDomFallback(page, labelNeedle, `${normalized.month}/${normalized.year}`, fieldKey)
    || await clickErrorLinkAndAnswer(page, labelNeedle, `${normalized.month}/${normalized.year}`, 'text');
  return !!generic;
}

async function fillExperienceDialogNative(page, profile) {
  const toParts = normalizeMonthYear(profile.experience_to || '');
  const fromParts = normalizeMonthYear(profile.experience_from || '');
  const jobTitle = String(profile.experience_job_title || '').trim();
  const company = String(profile.experience_company || '').trim();
  const roleDescription = String(profile.experience_role_description || '').trim();
  const toRaw = String(profile.experience_to || '').trim();

  try {
    return await page.evaluate(({ jobTitle, company, roleDescription, fromParts, toParts, toRaw }) => {
      const out = [];

      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const setInput = (el, val) => {
        if (!el || !isVisible(el)) return false;
        el.focus();
        el.value = '';
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.value = String(val);
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        el.dispatchEvent(new Event('blur', { bubbles: true }));
        return true;
      };

      const findFieldContainer = (root, needle) => {
        const n = String(needle).toLowerCase();
        const blocks = Array.from(root.querySelectorAll('div, section, fieldset, li')).filter((el) => {
          const txt = (el.innerText || '').toLowerCase();
          return txt.includes(n) && !!el.querySelector('input, textarea, select');
        });
        return blocks[0] || null;
      };

      const dialogs = Array.from(document.querySelectorAll('div[role="dialog"], [data-automation-id*="workexperience" i], [data-automation-id*="workExperience" i]'));
      const root = dialogs.find(isVisible) || document;

      if (jobTitle) {
        const c = findFieldContainer(root, 'job title') || findFieldContainer(root, 'title');
        if (c) {
          const input = c.querySelector('input[type="text"], input:not([type]), textarea');
          if (setInput(input, jobTitle)) out.push('experience_job_title');
        }
      }

      if (company) {
        const c = findFieldContainer(root, 'company');
        if (c) {
          const input = c.querySelector('input[type="text"], input:not([type]), textarea');
          if (setInput(input, company)) out.push('experience_company');
        }
      }

      if (roleDescription) {
        const needles = ['role description', 'job description', 'responsibilities', 'describe your role', 'description'];
        let hit = false;
        for (const needle of needles) {
          const c = findFieldContainer(root, needle);
          if (!c) continue;
          const input = c.querySelector('textarea, [contenteditable="true"], input[type="text"], input:not([type])');
          if (setInput(input, roleDescription)) {
            out.push('experience_role_description');
            hit = true;
            break;
          }
        }
        if (!hit) {
          const explicit = root.querySelector('[id*="roleDescription" i], [id*="jobDescription" i], textarea[id*="description" i], textarea[name*="description" i], textarea[aria-label*="description" i], textarea[placeholder*="description" i]');
          if (setInput(explicit, roleDescription)) {
            out.push('experience_role_description');
          }
        }
      }

      if (fromParts && fromParts.month && fromParts.year) {
        const c = findFieldContainer(root, 'from');
        if (c) {
          const monthInput = c.querySelector('input[data-automation-id*="month" i], input[placeholder*="MM" i], input[aria-label*="month" i]');
          const yearInput = c.querySelector('input[data-automation-id*="year" i], input[placeholder*="YYYY" i], input[aria-label*="year" i]');
          if (monthInput && yearInput) {
            const a = setInput(monthInput, fromParts.month);
            const b = setInput(yearInput, fromParts.year);
            if (a && b) out.push('experience_from');
          } else {
            const inputs = Array.from(c.querySelectorAll('input[type="text"], input:not([type]), input[type="search"]')).filter(isVisible);
            if (inputs.length >= 2) {
              const a = setInput(inputs[0], fromParts.month);
              const b = setInput(inputs[1], fromParts.year);
              if (a && b) out.push('experience_from');
            } else if (inputs.length === 1 && setInput(inputs[0], `${fromParts.month}/${fromParts.year}`)) {
              out.push('experience_from');
            }
          }
        }
      }

      const present = /present|current/i.test(toRaw);
      if (present) {
        const checks = Array.from(root.querySelectorAll('label, [role="checkbox"], [data-automation-id*="checkbox" i], [data-automation-id*="current" i]')).filter(isVisible);
        const hit = checks.find((el) => /currently work|present/i.test((el.innerText || '').toLowerCase()));
        if (hit) {
          hit.click();
          out.push('experience_to_current');
        }
      }

      if (!present && toParts && toParts.month && toParts.year) {
        const c = findFieldContainer(root, 'to');
        if (c) {
          const monthInput = c.querySelector('input[data-automation-id*="month" i], input[placeholder*="MM" i], input[aria-label*="month" i]');
          const yearInput = c.querySelector('input[data-automation-id*="year" i], input[placeholder*="YYYY" i], input[aria-label*="year" i]');
          if (monthInput && yearInput) {
            const a = setInput(monthInput, toParts.month);
            const b = setInput(yearInput, toParts.year);
            if (a && b) out.push('experience_to');
          } else {
            const inputs = Array.from(c.querySelectorAll('input[type="text"], input:not([type]), input[type="search"]')).filter(isVisible);
            if (inputs.length >= 2) {
              const a = setInput(inputs[0], toParts.month);
              const b = setInput(inputs[1], toParts.year);
              if (a && b) out.push('experience_to');
            } else if (inputs.length === 1 && setInput(inputs[0], `${toParts.month}/${toParts.year}`)) {
              out.push('experience_to');
            }
          }
        }
      }

      return out;
    }, { jobTitle, company, roleDescription, fromParts, toParts, toRaw });
  } catch (_) {
    return [];
  }
}

function extractExperienceEntries(profile) {
  const out = [];
  const normalizedFromArray = Array.isArray(profile?.work_experience_entries)
    ? profile.work_experience_entries
      .filter((x) => x && typeof x === 'object')
      .map((x) => ({
        job_title: String(x.job_title || x.title || '').trim(),
        company: String(x.company || '').trim(),
        from: String(x.from || x.start_date || '').trim(),
        to: String(x.to || x.end_date || '').trim(),
        role_description: String(x.role_description || x.description || '').trim(),
      }))
      .filter((x) => x.job_title || x.company || x.from || x.to || x.role_description)
    : [];

  out.push(...normalizedFromArray.slice(0, 3));

  const firstLegacy = {
    job_title: String(profile?.experience_job_title || '').trim(),
    company: String(profile?.experience_company || '').trim(),
    from: String(profile?.experience_from || '').trim(),
    to: String(profile?.experience_to || '').trim(),
    role_description: String(profile?.experience_role_description || '').trim(),
  };
  if ((firstLegacy.job_title || firstLegacy.company || firstLegacy.from || firstLegacy.to || firstLegacy.role_description)
    && !out.some((x) => x.job_title === firstLegacy.job_title && x.company === firstLegacy.company && x.from === firstLegacy.from && x.to === firstLegacy.to && x.role_description === firstLegacy.role_description)) {
    out.unshift(firstLegacy);
  }

  const secondLegacy = {
    job_title: String(profile?.experience2_job_title || '').trim(),
    company: String(profile?.experience2_company || '').trim(),
    from: String(profile?.experience2_from || '').trim(),
    to: String(profile?.experience2_to || '').trim(),
    role_description: String(profile?.experience2_role_description || '').trim(),
  };
  if (secondLegacy.job_title || secondLegacy.company || secondLegacy.from || secondLegacy.to || secondLegacy.role_description) {
    out.push(secondLegacy);
  }

  const thirdLegacy = {
    job_title: String(profile?.experience3_job_title || '').trim(),
    company: String(profile?.experience3_company || '').trim(),
    from: String(profile?.experience3_from || '').trim(),
    to: String(profile?.experience3_to || '').trim(),
    role_description: String(profile?.experience3_role_description || '').trim(),
  };
  if (thirdLegacy.job_title || thirdLegacy.company || thirdLegacy.from || thirdLegacy.to || thirdLegacy.role_description) {
    out.push(thirdLegacy);
  }

  return out.slice(0, 3);
}

async function getInlineExperienceRowKeys(page) {
  try {
    const keys = await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const inputs = Array.from(document.querySelectorAll('input[name="jobTitle"][id*="workExperience-"]')).filter(isVisible);
      const rows = [];
      for (const el of inputs) {
        const m = String(el.id || '').match(/workExperience-(\d+)--/i);
        if (m) {
          rows.push({ key: m[1], y: el.getBoundingClientRect().y });
        }
      }

      rows.sort((a, b) => a.y - b.y);
      return rows.map((r) => r.key).filter((v, i, a) => a.indexOf(v) === i);
    });

    return Array.isArray(keys) ? keys : [];
  } catch (_) {
    return [];
  }
}

async function clickAddWorkExperienceRow(page) {
  const selectors = [
    '[data-automation-id*="workExperience" i] button:has-text("Add")',
    'section:has-text("Work Experience") button:has-text("Add")',
    'button:has-text("Add Work Experience")',
    'button[aria-label*="Add Work Experience" i]',
    '[data-automation-id="add-button"]',
  ];

  for (const sel of selectors) {
    try {
      const btn = page.locator(sel).first();
      await btn.waitFor({ state: 'visible', timeout: 900 });
      const disabled = await btn.evaluate((el) => {
        const aria = (el.getAttribute('aria-disabled') || '').toLowerCase();
        return !!(el.disabled || aria === 'true');
      });
      if (disabled) {
        continue;
      }
      await btn.scrollIntoViewIfNeeded({ timeout: 700 }).catch(() => {});
      await btn.click({ timeout: 1300, force: true });
      await humanDelay(180, 340);

      const menuSelectors = [
        'li[role="menuitem"]:has-text("Work Experience")',
        '[role="option"]:has-text("Work Experience")',
        '[data-automation-id="promptOption"]:has-text("Work Experience")',
      ];
      for (const menuSel of menuSelectors) {
        try {
          const opt = page.locator(menuSel).first();
          await opt.waitFor({ state: 'visible', timeout: 500 });
          await opt.click({ timeout: 1000, force: true });
          await humanDelay(220, 420);
          break;
        } catch (_) {}
      }

      return true;
    } catch (_) {}
  }

  return false;
}

async function ensureInlineExperienceRowCount(page, desiredCount) {
  if (!desiredCount || desiredCount <= 1) {
    return true;
  }

  for (let attempt = 0; attempt < desiredCount + 2; attempt++) {
    const keys = await getInlineExperienceRowKeys(page);
    if (keys.length >= desiredCount) {
      return true;
    }

    const added = await clickAddWorkExperienceRow(page);
    if (!added) {
      return false;
    }
    await humanDelay(300, 620);
  }

  const keys = await getInlineExperienceRowKeys(page);
  return keys.length >= desiredCount;
}

function extractEducationEntries(profile) {
  const out = [];
  const fromArray = Array.isArray(profile?.education_entries)
    ? profile.education_entries
      .filter((x) => x && typeof x === 'object')
      .map((x) => ({
        school: String(x.school || x.institution || '').trim(),
        degree: String(x.degree || '').trim(),
        end_date: String(x.end_date || x.graduation_date || '').trim(),
      }))
      .filter((x) => x.school || x.degree || x.end_date)
    : [];
  out.push(...fromArray.slice(0, MAX_EDUCATION_ENTRIES));

  const legacy1 = {
    school: String(profile?.education_school || '').trim(),
    degree: String(profile?.education_degree || '').trim(),
    end_date: String(profile?.education_end_date || '').trim(),
  };
  if (legacy1.school || legacy1.degree || legacy1.end_date) {
    out.unshift(legacy1);
  }

  const legacy2 = {
    school: String(profile?.education2_school || '').trim(),
    degree: String(profile?.education2_degree || '').trim(),
    end_date: String(profile?.education2_end_date || '').trim(),
  };
  if (legacy2.school || legacy2.degree || legacy2.end_date) {
    out.push(legacy2);
  }

  const legacy3 = {
    school: String(profile?.education3_school || '').trim(),
    degree: String(profile?.education3_degree || '').trim(),
    end_date: String(profile?.education3_end_date || '').trim(),
  };
  if (legacy3.school || legacy3.degree || legacy3.end_date) {
    out.push(legacy3);
  }

  const unique = [];
  for (const e of out) {
    if (!e) continue;
    const school = normalizeEducationValue(e.school);
    const degree = normalizeEducationValue(e.degree);
    const endDate = normalizeEducationValue(e.end_date);
    const dup = unique.some((x) => {
      return normalizeEducationValue(x.school) === school
        && normalizeEducationValue(x.degree) === degree
        && normalizeEducationValue(x.end_date) === endDate;
    });
    if (!dup) unique.push(e);
  }
  return unique.slice(0, MAX_EDUCATION_ENTRIES);
}

async function getInlineEducationRowKeys(page) {
  try {
    const keys = await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const extractToken = (id) => {
        const s = String(id || '');
        if (!s || !s.includes('--')) return '';
        const token = s.split('--')[0] || '';
        if (!/education|school|institution|degree|graduation/i.test(s)) {
          return '';
        }
        return token;
      };

      const candidates = Array.from(document.querySelectorAll('input, select, textarea')).filter((el) => {
        if (!isVisible(el)) return false;
        const id = String(el.id || '').toLowerCase();
        return id.includes('education') || id.includes('school') || id.includes('institution') || id.includes('degree') || id.includes('graduation');
      });

      const rows = [];
      for (const el of candidates) {
        const token = extractToken(el.id);
        if (token) {
          rows.push({ key: token, y: el.getBoundingClientRect().y });
        }
      }

      rows.sort((a, b) => a.y - b.y);
      return rows.map((r) => r.key).filter((v, i, a) => a.indexOf(v) === i);
    });
    return Array.isArray(keys) ? keys : [];
  } catch (_) {
    return [];
  }
}

async function clickAddEducationRow(page) {
  try {
    const sectionScopedClicked = await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const sections = Array.from(document.querySelectorAll('section, div, fieldset')).filter((el) => {
        const txt = (el.innerText || '').toLowerCase();
        return txt.includes('education') && !txt.includes('work experience');
      });

      for (const section of sections) {
        const buttons = Array.from(section.querySelectorAll('button, [role="button"]')).filter(isVisible);
        const add = buttons.find((el) => {
          const t = ((el.textContent || '') + ' ' + (el.getAttribute('aria-label') || '')).toLowerCase();
          const disabled = el.disabled || (el.getAttribute('aria-disabled') || '').toLowerCase() === 'true';
          return !disabled && (t.includes('add education') || t.includes('add school') || t === 'add' || t.startsWith('add '));
        });
        if (add) {
          add.click();
          return true;
        }
      }
      return false;
    });
    if (sectionScopedClicked) {
      await humanDelay(180, 340);
      const menuSelectors = [
        'li[role="menuitem"]:has-text("Education")',
        '[role="option"]:has-text("Education")',
        '[data-automation-id="promptOption"]:has-text("Education")',
      ];
      for (const menuSel of menuSelectors) {
        try {
          const opt = page.locator(menuSel).first();
          await opt.waitFor({ state: 'visible', timeout: 500 });
          await opt.click({ timeout: 1000, force: true });
          await humanDelay(220, 420);
          break;
        } catch (_) {}
      }
      return true;
    }
  } catch (_) {}

  const selectors = [
    'button:has-text("Add Education")',
    'button[aria-label*="Add Education" i]',
    '[data-automation-id*="education" i] button:has-text("Add")',
    '[data-automation-id*="education" i][role="button"]:has-text("Add")',
  ];

  for (const sel of selectors) {
    try {
      const btn = page.locator(sel).first();
      await btn.waitFor({ state: 'visible', timeout: 900 });
      const disabled = await btn.evaluate((el) => {
        const aria = (el.getAttribute('aria-disabled') || '').toLowerCase();
        return !!(el.disabled || aria === 'true');
      });
      if (disabled) continue;
      await btn.scrollIntoViewIfNeeded({ timeout: 700 }).catch(() => {});
      await btn.click({ timeout: 1300, force: true });
      await humanDelay(180, 340);

      const menuSelectors = [
        'li[role="menuitem"]:has-text("Education")',
        '[role="option"]:has-text("Education")',
        '[data-automation-id="promptOption"]:has-text("Education")',
      ];
      for (const menuSel of menuSelectors) {
        try {
          const opt = page.locator(menuSel).first();
          await opt.waitFor({ state: 'visible', timeout: 500 });
          await opt.click({ timeout: 1000, force: true });
          await humanDelay(220, 420);
          break;
        } catch (_) {}
      }
      return true;
    } catch (_) {}
  }

  return false;
}

async function ensureInlineEducationRowCount(page, desiredCount) {
  if (!desiredCount || desiredCount <= 1) {
    return true;
  }

  for (let attempt = 0; attempt < desiredCount + 2; attempt++) {
    const keys = await getInlineEducationRowKeys(page);
    if (keys.length >= desiredCount) {
      return true;
    }

    const added = await clickAddEducationRow(page);
    if (!added) {
      return false;
    }
    await humanDelay(300, 620);
  }

  const keys = await getInlineEducationRowKeys(page);
  return keys.length >= desiredCount;
}

async function fillEducationByFieldNames(page, educationEntries) {
  const entryList = Array.isArray(educationEntries) ? educationEntries.filter(Boolean).slice(0, 3) : [];
  if (!entryList.length) {
    return [];
  }

  try {
    const rowKeys = await getInlineEducationRowKeys(page);
    if (!rowKeys.length) {
      return [];
    }

    const enriched = entryList.map((e) => {
      const parts = normalizeMonthYear(e.end_date || '');
      return {
        school: String(e.school || '').trim(),
        degree: String(e.degree || '').trim(),
        endMonth: parts?.month || '',
        endYear: parts?.year || (String(e.end_date || '').match(/(\d{4})/)?.[1] || ''),
      };
    });

    return await page.evaluate(({ rowKeys, entries }) => {
      const filled = [];

      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const setInput = (el, val) => {
        if (!el || !isVisible(el)) return false;
        const text = String(val || '');
        const tag = String(el.tagName || '').toLowerCase();

        if (tag === 'select') {
          try {
            const options = Array.from(el.options || []);
            const exact = options.find((o) => String(o.value || '').toLowerCase() === text.toLowerCase() || String(o.textContent || '').toLowerCase() === text.toLowerCase());
            const partial = options.find((o) => String(o.textContent || '').toLowerCase().includes(text.toLowerCase()));
            const picked = exact || partial;
            if (picked) {
              el.value = picked.value;
              el.dispatchEvent(new Event('input', { bubbles: true }));
              el.dispatchEvent(new Event('change', { bubbles: true }));
              el.dispatchEvent(new Event('blur', { bubbles: true }));
              return true;
            }
          } catch (_) {}
        }

        el.focus();
        try {
          const proto = Object.getPrototypeOf(el);
          const desc = Object.getOwnPropertyDescriptor(proto, 'value')
            || Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')
            || Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value');
          if (desc && typeof desc.set === 'function') {
            desc.set.call(el, '');
            el.dispatchEvent(new Event('input', { bubbles: true }));
            desc.set.call(el, text);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            el.dispatchEvent(new Event('blur', { bubbles: true }));
            return true;
          }
        } catch (_) {}

        try {
          el.value = '';
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.value = text;
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
          el.dispatchEvent(new Event('blur', { bubbles: true }));
          return true;
        } catch (_) {
          return false;
        }
      };

      const controlMeta = (el) => {
        const id = String(el.id || '').toLowerCase();
        const name = String(el.getAttribute('name') || '').toLowerCase();
        const aria = String(el.getAttribute('aria-label') || '').toLowerCase();
        const ph = String(el.getAttribute('placeholder') || '').toLowerCase();
        return `${id} ${name} ${aria} ${ph}`;
      };

      const pickControl = (controls, regexes) => {
        for (const re of regexes) {
          const hit = controls.find((el) => re.test(controlMeta(el)));
          if (hit) return hit;
        }
        return null;
      };

      for (let idx = 0; idx < rowKeys.length && idx < entries.length; idx++) {
        const rowToken = rowKeys[idx];
        const e = entries[idx] || {};
        const prefix = `${rowToken}--`;
        const controls = Array.from(document.querySelectorAll(`input[id^="${prefix}"], select[id^="${prefix}"], textarea[id^="${prefix}"]`)).filter(isVisible);

        const schoolControl = pickControl(controls, [/schoolname/i, /institutionname/i, /institution/i, /school/i]);
        const degreeControl = pickControl(controls, [/degree/i, /fieldofstudy/i, /major/i]);
        const endMonthControl = pickControl(controls, [/enddate.*month/i, /graduation.*month/i]);
        const endYearControl = pickControl(controls, [/enddate.*year/i, /graduation.*year/i]);
        const endDateSingle = pickControl(controls, [/enddate/i, /graduation/i]);

        if (e.school) {
          const ok = setInput(schoolControl, e.school);
          if (ok) {
            filled.push(idx === 0 ? 'education_school' : `education_${idx + 1}_school`);
          }
        }

        if (e.degree) {
          const ok = setInput(degreeControl, e.degree);
          if (ok) {
            filled.push(idx === 0 ? 'education_degree' : `education_${idx + 1}_degree`);
          }
        }

        if (e.endMonth && e.endYear) {
          let mOk = false;
          let yOk = false;
          if (endMonthControl && endYearControl) {
            mOk = setInput(endMonthControl, e.endMonth);
            yOk = setInput(endYearControl, e.endYear);
          } else if (endDateSingle) {
            mOk = setInput(endDateSingle, `${e.endMonth}/${e.endYear}`);
            yOk = mOk;
          }
          if (mOk && yOk) {
            filled.push(idx === 0 ? 'education_end_date' : `education_${idx + 1}_end_date`);
          }
        }
      }

      return filled;
    }, { rowKeys, entries: enriched });
  } catch (_) {
    return [];
  }
}

async function fillEducationEntryByVisibleControls(page, entry, index = 0) {
  if (!entry || typeof entry !== 'object') {
    return [];
  }

  const school = String(entry.school || '').trim();
  const degree = String(entry.degree || '').trim();
  const parsedEnd = normalizeMonthYear(entry.end_date || '');
  const endMonth = parsedEnd?.month || '';
  const endYear = parsedEnd?.year || (String(entry.end_date || '').match(/(\d{4})/)?.[1] || '');

  try {
    return await page.evaluate(({ school, degree, endMonth, endYear, index }) => {
      const filled = [];
      const tagPrefix = index <= 0 ? 'education' : `education_${index + 1}`;

      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const controlMeta = (el) => {
        const id = String(el.id || '').toLowerCase();
        const name = String(el.getAttribute('name') || '').toLowerCase();
        const aria = String(el.getAttribute('aria-label') || '').toLowerCase();
        const ph = String(el.getAttribute('placeholder') || '').toLowerCase();
        const label = (() => {
          if (id) {
            const l = document.querySelector(`label[for="${id}"]`);
            if (l) return String(l.textContent || '').toLowerCase();
          }
          const parentLabel = el.closest('label');
          if (parentLabel) return String(parentLabel.textContent || '').toLowerCase();
          const c = el.closest('div, section, li, fieldset');
          return String(c?.innerText || '').toLowerCase();
        })();
        return `${id} ${name} ${aria} ${ph} ${label}`;
      };

      const setValue = (el, val) => {
        if (!el || !isVisible(el)) return false;
        const text = String(val || '').trim();
        if (!text) return false;
        const tag = String(el.tagName || '').toLowerCase();

        if (tag === 'select') {
          try {
            const options = Array.from(el.options || []);
            const exact = options.find((o) => String(o.value || '').toLowerCase() === text.toLowerCase() || String(o.textContent || '').toLowerCase() === text.toLowerCase());
            const partial = options.find((o) => String(o.textContent || '').toLowerCase().includes(text.toLowerCase()));
            const picked = exact || partial;
            if (!picked) return false;
            el.value = picked.value;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            el.dispatchEvent(new Event('blur', { bubbles: true }));
            return true;
          } catch (_) {
            return false;
          }
        }

        try {
          el.focus();
          const proto = Object.getPrototypeOf(el);
          const desc = Object.getOwnPropertyDescriptor(proto, 'value')
            || Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')
            || Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value');
          if (desc && typeof desc.set === 'function') {
            desc.set.call(el, '');
            el.dispatchEvent(new Event('input', { bubbles: true }));
            desc.set.call(el, text);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            el.dispatchEvent(new Event('blur', { bubbles: true }));
            return true;
          }
          el.value = '';
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.value = text;
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
          el.dispatchEvent(new Event('blur', { bubbles: true }));
          return true;
        } catch (_) {
          return false;
        }
      };

      const allControls = Array.from(document.querySelectorAll('input, select, textarea')).filter(isVisible);
      const eduControls = allControls.filter((el) => /education|school|institution|degree|major|field of study|graduation|end date/.test(controlMeta(el)));

      const pickTarget = (regexes) => {
        const matches = eduControls.filter((el) => regexes.some((rx) => rx.test(controlMeta(el))));
        if (!matches.length) return null;
        const empties = matches.filter((el) => String(el.value || '').trim() === '');
        if (empties.length) {
          return empties[Math.min(index, empties.length - 1)] || empties[empties.length - 1];
        }
        return matches[Math.min(index, matches.length - 1)] || matches[matches.length - 1];
      };

      if (school) {
        const el = pickTarget([/schoolname/i, /institutionname/i, /institution/i, /school/i]);
        if (setValue(el, school)) {
          filled.push(`${tagPrefix}_school`);
        }
      }

      if (degree) {
        const el = pickTarget([/degree/i, /major/i, /fieldofstudy/i, /field of study/i]);
        if (setValue(el, degree)) {
          filled.push(`${tagPrefix}_degree`);
        }
      }

      if (endMonth && endYear) {
        const monthEl = pickTarget([/graduation.*month/i, /enddate.*month/i, /end date.*month/i]);
        const yearEl = pickTarget([/graduation.*year/i, /enddate.*year/i, /end date.*year/i]);
        let ok = false;
        if (monthEl && yearEl) {
          ok = setValue(monthEl, endMonth) && setValue(yearEl, endYear);
        }
        if (!ok) {
          const single = pickTarget([/graduation/i, /enddate/i, /end date/i]);
          ok = setValue(single, `${endMonth}/${endYear}`);
        }
        if (ok) {
          filled.push(`${tagPrefix}_end_date`);
        }
      }

      return filled;
    }, { school, degree, endMonth, endYear, index });
  } catch (_) {
    return [];
  }
}

async function fillEducationByRowKeyNative(page, educationEntries) {
  const entryList = Array.isArray(educationEntries) ? educationEntries.filter(Boolean).slice(0, 3) : [];
  if (!entryList.length) {
    return [];
  }

  const filled = [];
  const rowKeys = await getInlineEducationRowKeys(page);
  if (!rowKeys.length) {
    return filled;
  }

  const readSchoolSignal = async (key) => {
    try {
      return await page.evaluate((rowKey) => {
        const el = document.getElementById(`education-${rowKey}--school`);
        if (!el) return '';
        return String(el.value || el.getAttribute('value') || el.textContent || '').trim().toLowerCase();
      }, key);
    } catch (_) {
      return '';
    }
  };

  for (let index = 0; index < entryList.length && index < rowKeys.length; index++) {
    const key = rowKeys[index];
    const entry = entryList[index] || {};
    const school = String(entry.school || '').trim();
    const degree = String(entry.degree || '').trim();
    const tagPrefix = index === 0 ? 'education' : `education_${index + 1}`;

    if (school) {
      try {
        const schoolInput = page.locator(`[id="education-${key}--school"]`).first();
        await schoolInput.waitFor({ state: 'visible', timeout: 1200 });
        await schoolInput.click({ timeout: 900, force: true });
        await page.keyboard.press('Control+A').catch(() => {});
        await page.keyboard.press('Backspace').catch(() => {});
        await page.keyboard.type(school, { delay: 12 }).catch(() => {});
        await page.keyboard.press('Enter').catch(() => {});
        await page.keyboard.press('Tab').catch(() => {});
        await humanDelay(140, 300);
        const signal = await readSchoolSignal(key);
        if (signal && !signal.includes('0 items selected')) {
          if (!filled.includes(`${tagPrefix}_school`)) {
            filled.push(`${tagPrefix}_school`);
          }
        }
      } catch (_) {}
    }

    if (degree) {
      try {
        const degreeBtn = page.locator(`[id="education-${key}--degree"]`).first();
        await degreeBtn.waitFor({ state: 'visible', timeout: 1200 });
        await degreeBtn.click({ timeout: 1000, force: true });
        await humanDelay(120, 260);

        const labels = [degree];
        const firstToken = degree.split(/[\-,:]/)[0].trim();
        if (firstToken && firstToken.toLowerCase() !== degree.toLowerCase()) {
          labels.push(firstToken);
        }

        let selected = false;
        for (const label of labels) {
          const optionSelectors = [
            `[role="option"]:has-text("${label}")`,
            `li[role="option"]:has-text("${label}")`,
            `li[role="menuitem"]:has-text("${label}")`,
            `[data-automation-id="promptOption"]:has-text("${label}")`,
            `div[role="option"]:has-text("${label}")`,
          ];
          for (const sel of optionSelectors) {
            try {
              const option = page.locator(sel).first();
              await option.waitFor({ state: 'visible', timeout: 700 });
              await option.click({ timeout: 900, force: true });
              selected = true;
              break;
            } catch (_) {}
          }
          if (selected) break;
        }

        await humanDelay(120, 260);
        const degreeText = await degreeBtn.textContent().catch(() => '');
        const selectedText = String(degreeText || '').trim().toLowerCase();
        if ((selected && selectedText && !selectedText.includes('select one')) || selectedText.includes(degree.toLowerCase())) {
          if (!filled.includes(`${tagPrefix}_degree`)) {
            filled.push(`${tagPrefix}_degree`);
          }
        }
      } catch (_) {}
    }
  }

  return filled;
}

async function fillWorkExperienceByFieldNames(page, experienceEntries) {
  const entryList = Array.isArray(experienceEntries) ? experienceEntries.filter(Boolean) : [];
  if (!entryList.length) {
    return [];
  }

  const now = new Date();
  const fallbackFrom = { month: '01', year: String(now.getFullYear() - 1) };
  const fallbackTo = { month: String(now.getMonth() + 1).padStart(2, '0'), year: String(now.getFullYear()) };
  const filled = [];

  const valueMatches = (actual, expected) => {
    const a = String(actual || '').trim().toLowerCase();
    const e = String(expected || '').trim().toLowerCase();
    if (!a || !e) return false;
    if (a === e) return true;
    if ((a === '1' || a === '01') && (e === '1' || e === '01')) return true;
    if ((a === '2' || a === '02') && (e === '2' || e === '02')) return true;
    if ((a === '3' || a === '03') && (e === '3' || e === '03')) return true;
    if ((a === '4' || a === '04') && (e === '4' || e === '04')) return true;
    if ((a === '5' || a === '05') && (e === '5' || e === '05')) return true;
    if ((a === '6' || a === '06') && (e === '6' || e === '06')) return true;
    if ((a === '7' || a === '07') && (e === '7' || e === '07')) return true;
    if ((a === '8' || a === '08') && (e === '8' || e === '08')) return true;
    if ((a === '9' || a === '09') && (e === '9' || e === '09')) return true;
    return false;
  };

  const readById = async (id) => {
    try {
      const input = page.locator(`[id="${id}"]`).first();
      await input.waitFor({ state: 'visible', timeout: 700 });
      return await input.inputValue({ timeout: 700 });
    } catch (_) {
      return '';
    }
  };

  const setByNativeSetter = async (id, value) => {
    if (!id || value === undefined || value === null) return false;
    try {
      return await page.evaluate(({ id, value }) => {
        const el = document.getElementById(id);
        if (!el) return false;
        el.focus();
        const proto = Object.getPrototypeOf(el);
        const desc = Object.getOwnPropertyDescriptor(proto, 'value')
          || Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
        if (!desc || typeof desc.set !== 'function') return false;
        desc.set.call(el, String(value));
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        el.dispatchEvent(new Event('blur', { bubbles: true }));
        return true;
      }, { id, value: String(value) });
    } catch (_) {
      return false;
    }
  };

  const setCurrentHere = async (key, desired) => {
    const id = `workExperience-${key}--currentlyWorkHere`;
    try {
      const checked = await page.evaluate((id) => {
        const el = document.getElementById(id);
        return !!(el && el.checked);
      }, id).catch(() => false);

      if (checked === desired) {
        return true;
      }

      const checkbox = page.locator(`[id="${id}"]`).first();
      const label = page.locator(`label[for="${id}"]`).first();
      if (await label.isVisible({ timeout: 300 }).catch(() => false)) {
        await label.click({ timeout: 900, force: true });
      } else {
        await checkbox.click({ timeout: 900, force: true }).catch(async () => {
          await page.evaluate((id) => {
            const el = document.getElementById(id);
            if (!el) return;
            const container = el.closest('div, section, fieldset, li, form') || el.parentElement;
            const candidate = container?.querySelector('label, [role="checkbox"], div[role="checkbox"], span, button');
            if (candidate) {
              candidate.click();
            }
          }, id);
        });
      }
      await humanDelay(80, 180);

      const after = await page.evaluate((id) => {
        const el = document.getElementById(id);
        return !!(el && el.checked);
      }, id).catch(() => false);
      return after === desired;
    } catch (_) {
      return false;
    }
  };

  const setRoleDescriptionForRow = async (key, value) => {
    if (!key || !value) return false;
    try {
      return await page.evaluate(({ key, value }) => {
        const isVisible = (el) => {
          if (!el) return false;
          const rect = el.getBoundingClientRect();
          const style = window.getComputedStyle(el);
          return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
        };

        const setInput = (el, val) => {
          if (!el || !isVisible(el)) return false;
          const text = String(val || '');
          el.focus();

          if (el.isContentEditable) {
            el.textContent = '';
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.textContent = text;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            el.dispatchEvent(new Event('blur', { bubbles: true }));
            return true;
          }

          const proto = Object.getPrototypeOf(el);
          const desc = Object.getOwnPropertyDescriptor(proto, 'value')
            || Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')
            || Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value');

          if (desc && typeof desc.set === 'function') {
            desc.set.call(el, '');
            el.dispatchEvent(new Event('input', { bubbles: true }));
            desc.set.call(el, text);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            el.dispatchEvent(new Event('blur', { bubbles: true }));
            return true;
          }

          try {
            el.value = '';
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.value = text;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            el.dispatchEvent(new Event('blur', { bubbles: true }));
            return true;
          } catch (_) {
            return false;
          }
        };

        const explicitIds = [
          `workExperience-${key}--roleDescription`,
          `workExperience-${key}--jobDescription`,
          `workExperience-${key}--description`,
          `workExperience-${key}--responsibilities`,
          `workExperience-${key}--summary`,
        ];

        for (const id of explicitIds) {
          const el = document.getElementById(id);
          if (setInput(el, value)) return true;
        }

        const title = document.getElementById(`workExperience-${key}--jobTitle`);
        const rowRoot = title?.closest('div, section, li, fieldset, form') || document;
        const prefix = `workExperience-${key}--`;

        const candidates = Array.from(rowRoot.querySelectorAll(`textarea[id^="${prefix}"], input[id^="${prefix}"], [contenteditable="true"][id^="${prefix}"]`));
        const direct = candidates.find((el) => {
          if (!isVisible(el)) return false;
          const id = String(el.id || '').toLowerCase();
          const name = String(el.getAttribute('name') || '').toLowerCase();
          const aria = String(el.getAttribute('aria-label') || '').toLowerCase();
          const ph = String(el.getAttribute('placeholder') || '').toLowerCase();
          const joined = `${id} ${name} ${aria} ${ph}`;
          return /(role\s*description|job\s*description|responsibil|duties|summary|achievement)/i.test(joined);
        });
        if (setInput(direct, value)) return true;

        const fieldContainers = Array.from(rowRoot.querySelectorAll('div, section, li, fieldset')).filter((el) => {
          if (!isVisible(el)) return false;
          const txt = String(el.innerText || '').toLowerCase();
          if (!/(role description|job description|responsibilities|describe your role|description)/i.test(txt)) {
            return false;
          }
          return !!el.querySelector('textarea, input[type="text"], input:not([type]), [contenteditable="true"]');
        });
        for (const container of fieldContainers) {
          const input = container.querySelector('textarea, [contenteditable="true"], input[type="text"], input:not([type])');
          if (setInput(input, value)) return true;
        }

        return false;
      }, { key, value: String(value) });
    } catch (_) {
      return false;
    }
  };

  const typeById = async (id, value) => {
    if (!id || !value) return false;
    try {
      const input = page.locator(`[id="${id}"]`).first();
      await input.waitFor({ state: 'visible', timeout: 900 });
      await input.scrollIntoViewIfNeeded({ timeout: 700 });
      await input.click({ timeout: 900, force: true });
      await page.keyboard.press('Control+A').catch(() => {});
      await page.keyboard.press('Backspace').catch(() => {});
      await input.fill('').catch(() => {});
      await input.type(String(value), { delay: 12 }).catch(async () => {
        await page.keyboard.type(String(value), { delay: 12 });
      });
      await input.dispatchEvent('input').catch(() => {});
      await input.dispatchEvent('change').catch(() => {});
      await page.keyboard.press('Tab').catch(() => {});
      await humanDelay(80, 170);

      let after = await readById(id);
      if (!valueMatches(after, value) && /dateSection(Month|Year)-input$/i.test(id)) {
        const input = page.locator(`[id="${id}"]`).first();
        await input.click({ timeout: 800, force: true }).catch(() => {});
        await page.keyboard.press('ArrowUp').catch(() => {});
        await humanDelay(70, 140);
        after = await readById(id);
      }
      if (!valueMatches(after, value)) {
        await setByNativeSetter(id, value);
        await humanDelay(70, 140);
        after = await readById(id);
      }

      // Final fallback for stubborn spinbutton controls: keep nudging until non-empty.
      if (!valueMatches(after, value) && /dateSection(Month|Year)-input$/i.test(id)) {
        const input = page.locator(`[id="${id}"]`).first();
        for (let i = 0; i < 3; i++) {
          await input.click({ timeout: 700, force: true }).catch(() => {});
          await page.keyboard.press('ArrowUp').catch(() => {});
          await humanDelay(60, 120);
          after = await readById(id);
          if (String(after || '').trim() !== '') {
            break;
          }
        }
      }

      if (valueMatches(after, value)) {
        return true;
      }
      if (/dateSection(Month|Year)-input$/i.test(id) && String(after || '').trim() !== '') {
        return true;
      }
      return false;
    } catch (_) {
      return false;
    }
  };

  const monthAliases = (value) => {
    const v = String(value || '').trim().toLowerCase();
    const map = {
      '01': ['1', '01', 'jan', 'january'],
      '02': ['2', '02', 'feb', 'february'],
      '03': ['3', '03', 'mar', 'march'],
      '04': ['4', '04', 'apr', 'april'],
      '05': ['5', '05', 'may'],
      '06': ['6', '06', 'jun', 'june'],
      '07': ['7', '07', 'jul', 'july'],
      '08': ['8', '08', 'aug', 'august'],
      '09': ['9', '09', 'sep', 'sept', 'september'],
      '10': ['10', 'oct', 'october'],
      '11': ['11', 'nov', 'november'],
      '12': ['12', 'dec', 'december'],
    };
    if (map[v]) return map[v];
    if (/^\d{1,2}$/.test(v)) {
      const k = String(parseInt(v, 10)).padStart(2, '0');
      if (map[k]) return map[k];
    }
    return [v];
  };

  const readDateSegmentState = async (baseId, part) => {
    const wrapId = `${baseId}-dateSection${part}`;
    const inputId = `${wrapId}-input`;
    const displayId = `${wrapId}-display`;
    try {
      return await page.evaluate(({ inputId, displayId, wrapId }) => {
        const input = document.getElementById(inputId);
        const display = document.getElementById(displayId);
        const wrap = document.getElementById(wrapId);
        return {
          value: String(input?.value || '').trim(),
          ariaValueText: String(input?.getAttribute('aria-valuetext') || '').trim(),
          ariaValueNow: String(input?.getAttribute('aria-valuenow') || '').trim(),
          displayText: String(display?.textContent || '').trim(),
          wrapText: String(wrap?.textContent || '').trim(),
        };
      }, { inputId, displayId, wrapId });
    } catch (_) {
      return { value: '', ariaValueText: '', ariaValueNow: '', displayText: '', wrapText: '' };
    }
  };

  const dateSegmentMatches = (state, expected, part) => {
    const e = String(expected || '').trim().toLowerCase();
    const vals = [
      String(state?.value || '').trim().toLowerCase(),
      String(state?.ariaValueText || '').trim().toLowerCase(),
      String(state?.ariaValueNow || '').trim().toLowerCase(),
      String(state?.displayText || '').trim().toLowerCase(),
      String(state?.wrapText || '').trim().toLowerCase(),
    ].filter(Boolean);
    if (!e || !vals.length) return false;

    if (part === 'Month') {
      const aliases = monthAliases(e);
      return vals.some((v) => aliases.some((a) => v === a || v.includes(a)));
    }

    return vals.some((v) => v === e || v.includes(e));
  };

  const setDateSegment = async (baseId, part, value) => {
    const wrapId = `${baseId}-dateSection${part}`;
    const inputId = `${wrapId}-input`;

    const wrap = page.locator(`[id="${wrapId}"]`).first();
    const input = page.locator(`[id="${inputId}"]`).first();

    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        await wrap.waitFor({ state: 'visible', timeout: 900 });
        await wrap.scrollIntoViewIfNeeded({ timeout: 700 }).catch(() => {});
        await wrap.click({ timeout: 900, force: true }).catch(() => {});
      } catch (_) {}

      try {
        await input.waitFor({ state: 'visible', timeout: 900 });
        await input.click({ timeout: 900, force: true }).catch(() => {});
      } catch (_) {}

      await page.keyboard.press('Control+A').catch(() => {});
      await page.keyboard.press('Backspace').catch(() => {});
      const typed = part === 'Month'
        ? String(parseInt(String(value || '').trim(), 10) || String(value || '').trim())
        : String(value || '').trim();
      if (typed) {
        await page.keyboard.type(typed, { delay: 25 }).catch(() => {});
      }
      await input.dispatchEvent('input').catch(() => {});
      await input.dispatchEvent('change').catch(() => {});
      await page.keyboard.press('Tab').catch(() => {});
      await humanDelay(90, 180);

      const after = await readDateSegmentState(baseId, part);
      if (dateSegmentMatches(after, value, part)) {
        return true;
      }

      await setByNativeSetter(inputId, typed);
      await humanDelay(80, 160);
      const afterNative = await readDateSegmentState(baseId, part);
      if (dateSegmentMatches(afterNative, value, part)) {
        return true;
      }
    }

    return false;
  };

  const setDateByPrefix = async (key, prefix, parts) => {
    if (!parts || !parts.month || !parts.year) return false;
    const baseId = `workExperience-${key}--${prefix}`;
    const monthOk = await setDateSegment(baseId, 'Month', parts.month);
    const yearOk = await setDateSegment(baseId, 'Year', parts.year);
    return !!(monthOk && yearOk);
  };

  const looksLikeDateString = (value) => {
    const v = String(value || '').trim();
    if (!v) return false;
    return /^(\d{4}[-/]\d{1,2}([-/]\d{1,2})?|\d{1,2}[-/]\d{4}|\d{4})$/.test(v);
  };

  const readRawById = async (id) => {
    try {
      return await page.evaluate((id) => {
        const el = document.getElementById(id);
        if (!el) return '';
        return String(el.value || el.getAttribute('value') || el.textContent || '').trim();
      }, id);
    } catch (_) {
      return '';
    }
  };

  const ensureRowIntegrity = async (key, expectedTitle, expectedCompany) => {
    let repaired = false;
    const titleId = `workExperience-${key}--jobTitle`;
    const companyId = `workExperience-${key}--companyName`;

    const titleActual = await readRawById(titleId);
    if (expectedTitle && (!visualMatch(titleActual, expectedTitle) && (looksLikeDateString(titleActual) || titleActual.length < 3))) {
      const fixed = await typeById(titleId, expectedTitle);
      if (fixed) {
        repaired = true;
      }
    }

    const companyActual = await readRawById(companyId);
    if (expectedCompany && (!visualMatch(companyActual, expectedCompany) && looksLikeDateString(companyActual))) {
      const fixed = await typeById(companyId, expectedCompany);
      if (fixed) {
        repaired = true;
      }
    }

    return repaired;
  };

  try {
    const rowKeys = await getInlineExperienceRowKeys(page);

    process.stderr.write(`INFO: Inline workExperience row keys: ${JSON.stringify(rowKeys)}\n`);

    for (let rowIndex = 0; rowIndex < rowKeys.length; rowIndex++) {
      if (rowIndex >= entryList.length) {
        continue;
      }

      const key = rowKeys[rowIndex];
      const entry = entryList[rowIndex] || {};
      const parsedFrom = normalizeMonthYear(entry.from || '');
      const parsedTo = normalizeMonthYear(entry.to || '');
      const fromParts = parsedFrom || fallbackFrom;
      const toParts = parsedTo || fallbackTo;
      const toRaw = String(entry.to || '').trim();
      const present = /present|current/i.test(toRaw) || (!parsedTo && toRaw === '');
      const jobTitle = String(entry.job_title || '').trim();
      const company = String(entry.company || '').trim();
      const roleDescription = String(entry.role_description || '').trim();

      const perRow = { key, title: false, company: false, description: false, from: false, to: false, current: false };
      if (jobTitle) {
        const ok = await typeById(`workExperience-${key}--jobTitle`, jobTitle);
        perRow.title = ok;
        if (ok) filled.push(rowIndex === 0 ? 'experience_job_title' : `experience_${rowIndex + 1}_job_title`);
      }
      if (company) {
        const ok = await typeById(`workExperience-${key}--companyName`, company);
        perRow.company = ok;
        if (ok) filled.push(rowIndex === 0 ? 'experience_company' : `experience_${rowIndex + 1}_company`);
      }
      if (roleDescription) {
        const ok = await setRoleDescriptionForRow(key, roleDescription);
        perRow.description = ok;
        if (ok) filled.push(rowIndex === 0 ? 'experience_role_description' : `experience_${rowIndex + 1}_role_description`);
      }
      if (fromParts && fromParts.month && fromParts.year) {
        const dateOk = await setDateByPrefix(key, 'startDate', fromParts);
        const a = dateOk || await typeById(`workExperience-${key}--startDate-dateSectionMonth-input`, fromParts.month);
        const b = dateOk || await typeById(`workExperience-${key}--startDate-dateSectionYear-input`, fromParts.year);
        perRow.from = !!(dateOk || (a && b));
        if (perRow.from) filled.push(rowIndex === 0 ? 'experience_from' : `experience_${rowIndex + 1}_from`);
      }

      if (present) {
        const ok = await setCurrentHere(key, true);
        perRow.current = ok;
        if (ok) filled.push(rowIndex === 0 ? 'experience_to_current' : `experience_${rowIndex + 1}_to_current`);
      } else {
        await setCurrentHere(key, false);
      }

      if (!present && toParts && toParts.month && toParts.year) {
        const dateOk = await setDateByPrefix(key, 'endDate', toParts);
        const a = dateOk || await typeById(`workExperience-${key}--endDate-dateSectionMonth-input`, toParts.month);
        const b = dateOk || await typeById(`workExperience-${key}--endDate-dateSectionYear-input`, toParts.year);
        perRow.to = !!(dateOk || (a && b));
        if (perRow.to) filled.push(rowIndex === 0 ? 'experience_to' : `experience_${rowIndex + 1}_to`);
      }

      const repaired = await ensureRowIntegrity(key, jobTitle, company);
      if (repaired) {
        filled.push(rowIndex === 0 ? 'experience_row_repaired' : `experience_${rowIndex + 1}_row_repaired`);
      }

      process.stderr.write(`INFO: Inline row fill result: ${JSON.stringify(perRow)}\n`);
    }
  } catch (_) {}

  return filled;
}

async function cleanupEmptyWorkExperienceRows(page) {
  try {
    const rows = await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const extractRowKey = (id) => {
        const m = String(id || '').match(/workExperience-(\d+)--/i);
        return m ? m[1] : '';
      };

      const titleInputs = Array.from(document.querySelectorAll('input[name="jobTitle"][id*="workExperience-"]')).filter(isVisible);
      const map = {};
      for (const input of titleInputs) {
        const key = extractRowKey(input.id);
        if (!key) continue;
        const company = document.getElementById(`workExperience-${key}--companyName`);
        const titleVal = String(input.value || '').trim();
        const companyVal = String(company?.value || '').trim();
        map[key] = {
          key,
          titleVal,
          companyVal,
          y: input.getBoundingClientRect().y,
        };
      }

      return Object.values(map)
        .sort((a, b) => a.y - b.y)
        .map((r) => ({ key: r.key, empty: r.titleVal === '' && r.companyVal === '' }));
    });

    if (!Array.isArray(rows) || rows.length <= 1) {
      return [];
    }

    const nonEmpty = rows.filter((r) => !r.empty);
    const keepKey = (nonEmpty[0] && nonEmpty[0].key) || (rows[0] && rows[0].key) || '';
    const deleted = [];

    for (const row of rows) {
      if (!row.empty || row.key === keepKey) {
        continue;
      }

      try {
        const rowContainer = page.locator(`[id="workExperience-${row.key}--jobTitle"]`).locator('xpath=ancestor::*[.//button[contains(normalize-space(),"Delete")]][1]').first();
        await rowContainer.waitFor({ state: 'visible', timeout: 900 });
        const delBtn = rowContainer.locator('button:has-text("Delete")').first();
        await delBtn.click({ timeout: 1200, force: true });
        await humanDelay(220, 450);

        // Some Workday variants show confirmation dialogs.
        try {
          const confirm = page.locator('div[role="dialog"] button:has-text("Delete"), div[role="dialog"] button:has-text("Remove"), button:has-text("Delete"):visible').first();
          await confirm.waitFor({ state: 'visible', timeout: 500 });
          await confirm.click({ timeout: 900, force: true });
        } catch (_) {}

        deleted.push(`work_experience_row_${row.key}`);
        await humanDelay(250, 500);
      } catch (_) {}
    }

    return deleted;
  } catch (_) {
    return [];
  }
}

async function fillExperienceRowByVisualContainer(page, entry, rowIndex = 0) {
  const out = [];
  const jobTitle = String(entry?.job_title || '').trim();
  const company = String(entry?.company || '').trim();
  const roleDescription = String(entry?.role_description || '').trim();
  const fromParts = normalizeMonthYear(entry?.from || '');
  const toParts = normalizeMonthYear(entry?.to || '');
  const token = `jh-row-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

  const prepared = await page.evaluate(({ rowIndex, token }) => {
    const isVisible = (el) => {
      if (!el) return false;
      const rect = el.getBoundingClientRect();
      const style = window.getComputedStyle(el);
      return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
    };
    const normalize = (v) => String(v || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const meta = (el) => normalize(`${el.id || ''} ${el.getAttribute('name') || ''} ${el.getAttribute('aria-label') || ''} ${el.getAttribute('placeholder') || ''}`);

    const roots = Array.from(document.querySelectorAll('section, div, fieldset, li'))
      .filter(isVisible)
      .filter((el) => {
        const txt = normalize(el.innerText || '');
        if (!txt.includes('job title') || !txt.includes('company') || !txt.includes('from') || !txt.includes('to')) return false;
        const controls = el.querySelectorAll('input, textarea, select');
        return controls.length >= 5;
      });

    const deduped = [];
    for (const r of roots) {
      if (!deduped.some((d) => d.contains(r) || r.contains(d))) {
        deduped.push(r);
      }
    }
    deduped.sort((a, b) => a.getBoundingClientRect().y - b.getBoundingClientRect().y);
    const row = deduped[Math.min(Math.max(0, rowIndex), deduped.length - 1)];
    if (!row) return { ok: false };

    for (const el of document.querySelectorAll('[data-jh-row-target]')) {
      el.removeAttribute('data-jh-row-target');
      el.removeAttribute('data-jh-row-part');
    }

    const controls = Array.from(row.querySelectorAll('input, textarea, select, button')).filter(isVisible);
    const find = (matchers, fallback = null) => controls.find((el) => matchers.some((rx) => rx.test(meta(el)))) || fallback;
    const textInputs = controls.filter((el) => {
      const tag = (el.tagName || '').toLowerCase();
      const type = String(el.getAttribute('type') || '').toLowerCase();
      return (tag === 'input' || tag === 'textarea') && !['hidden', 'checkbox', 'radio', 'file'].includes(type);
    });

    const title = find([/jobtitle/i, /job title/i]);
    const company = find([/companyname/i, /company/i]);
    const desc = find([/roledescription/i, /role description/i, /description/i], controls.find((el) => (el.tagName || '').toLowerCase() === 'textarea') || null);
    const fromMonth = find([/startdate.*month/i]);
    const fromYear = find([/startdate.*year/i]);
    const toMonth = find([/enddate.*month/i]);
    const toYear = find([/enddate.*year/i]);

    const setMark = (el, part) => {
      if (!el) return;
      el.setAttribute('data-jh-row-target', token);
      el.setAttribute('data-jh-row-part', part);
    };
    setMark(title || textInputs[0], 'title');
    setMark(company || textInputs[1], 'company');
    setMark(desc, 'description');
    setMark(fromMonth, 'from-month');
    setMark(fromYear, 'from-year');
    setMark(toMonth, 'to-month');
    setMark(toYear, 'to-year');

    return { ok: true };
  }, { rowIndex, token }).catch(() => ({ ok: false }));

  if (!prepared?.ok) {
    return out;
  }

  const setText = async (part, value, fieldName) => {
    if (!value) return;
    const loc = page.locator(`[data-jh-row-target="${token}"][data-jh-row-part="${part}"]`).first();
    try {
      const before = await loc.inputValue({ timeout: 500 }).catch(() => loc.textContent({ timeout: 500 }).catch(() => ''));
      await loc.click({ timeout: 1000, force: true });
      await page.keyboard.press('Control+A').catch(() => {});
      await page.keyboard.press('Backspace').catch(() => {});
      await page.keyboard.type(String(value), { delay: 10 }).catch(() => {});
      await page.keyboard.press('Tab').catch(() => {});
      await humanDelay(120, 260);
      const after = await loc.inputValue({ timeout: 500 }).catch(() => loc.textContent({ timeout: 500 }).catch(() => ''));
      const ok = visualMatch(String(after || ''), String(value || ''));
      pushFieldAudit(fieldName, value, String(after || ''), ok, ok ? 'visual-before-after-confirmed' : 'visual-before-after-failed');
      if (ok) out.push(fieldName);
    } catch (_) {}
  };

  await setText('title', jobTitle, rowIndex === 0 ? 'experience_job_title' : `experience_${rowIndex + 1}_job_title`);
  await setText('company', company, rowIndex === 0 ? 'experience_company' : `experience_${rowIndex + 1}_company`);
  await setText('description', roleDescription, rowIndex === 0 ? 'experience_role_description' : `experience_${rowIndex + 1}_role_description`);

  const setDateParts = async (monthPart, yearPart, parsed, fieldName) => {
    if (!parsed?.month || !parsed?.year) return;
    const mLoc = page.locator(`[data-jh-row-target="${token}"][data-jh-row-part="${monthPart}"]`).first();
    const yLoc = page.locator(`[data-jh-row-target="${token}"][data-jh-row-part="${yearPart}"]`).first();
    try {
      await mLoc.click({ timeout: 1000, force: true });
      await page.keyboard.press('Control+A').catch(() => {});
      await page.keyboard.press('Backspace').catch(() => {});
      await page.keyboard.type(String(parsed.month), { delay: 10 }).catch(() => {});
      await yLoc.click({ timeout: 1000, force: true });
      await page.keyboard.press('Control+A').catch(() => {});
      await page.keyboard.press('Backspace').catch(() => {});
      await page.keyboard.type(String(parsed.year), { delay: 10 }).catch(() => {});
      await page.keyboard.press('Tab').catch(() => {});
      await humanDelay(120, 260);
      const am = await mLoc.inputValue({ timeout: 500 }).catch(() => '');
      const ay = await yLoc.inputValue({ timeout: 500 }).catch(() => '');
      const ok = visualMatch(String(am || ''), String(parsed.month)) && visualMatch(String(ay || ''), String(parsed.year));
      pushFieldAudit(fieldName, `${parsed.month}/${parsed.year}`, `${am}/${ay}`, ok, ok ? 'visual-before-after-confirmed' : 'visual-before-after-failed');
      if (ok) out.push(fieldName);
    } catch (_) {}
  };

  await setDateParts('from-month', 'from-year', fromParts, rowIndex === 0 ? 'experience_from' : `experience_${rowIndex + 1}_from`);
  await setDateParts('to-month', 'to-year', toParts, rowIndex === 0 ? 'experience_to' : `experience_${rowIndex + 1}_to`);

  await page.evaluate(() => {
    for (const el of document.querySelectorAll('[data-jh-row-target]')) {
      el.removeAttribute('data-jh-row-target');
      el.removeAttribute('data-jh-row-part');
    }
  }).catch(() => {});

  return out;
}

async function fillEducationRowByVisualContainer(page, entry, rowIndex = 0) {
  const out = [];
  const school = String(entry?.school || '').trim();
  const degree = String(entry?.degree || '').trim();
  const token = `jh-edu-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

  const prepared = await page.evaluate(({ rowIndex, token }) => {
    const isVisible = (el) => {
      if (!el) return false;
      const rect = el.getBoundingClientRect();
      const style = window.getComputedStyle(el);
      return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
    };
    const normalize = (v) => String(v || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const meta = (el) => normalize(`${el.id || ''} ${el.getAttribute('name') || ''} ${el.getAttribute('aria-label') || ''} ${el.getAttribute('placeholder') || ''}`);

    const roots = Array.from(document.querySelectorAll('section, div, fieldset, li'))
      .filter(isVisible)
      .filter((el) => {
        const txt = normalize(el.innerText || '');
        return txt.includes('school') && txt.includes('degree') && el.querySelectorAll('input, button, select').length >= 2;
      });
    roots.sort((a, b) => a.getBoundingClientRect().y - b.getBoundingClientRect().y);
    const row = roots[Math.min(Math.max(0, rowIndex), roots.length - 1)];
    if (!row) return { ok: false };

    for (const el of document.querySelectorAll('[data-jh-edu-target]')) {
      el.removeAttribute('data-jh-edu-target');
      el.removeAttribute('data-jh-edu-part');
    }

    const controls = Array.from(row.querySelectorAll('input, select, button')).filter(isVisible);
    const schoolControl = controls.find((el) => /school|university|institution/.test(meta(el))) || controls.find((el) => (el.tagName || '').toLowerCase() === 'input') || null;
    const degreeControl = controls.find((el) => /degree|fieldofstudy|major|select one/.test(meta(el)) || (el.tagName || '').toLowerCase() === 'button') || null;

    if (schoolControl) {
      schoolControl.setAttribute('data-jh-edu-target', token);
      schoolControl.setAttribute('data-jh-edu-part', 'school');
    }
    if (degreeControl) {
      degreeControl.setAttribute('data-jh-edu-target', token);
      degreeControl.setAttribute('data-jh-edu-part', 'degree');
    }
    return { ok: true };
  }, { rowIndex, token }).catch(() => ({ ok: false }));

  if (!prepared?.ok) {
    return out;
  }

  if (school) {
    try {
      const loc = page.locator(`[data-jh-edu-target="${token}"][data-jh-edu-part="school"]`).first();
      await loc.click({ timeout: 1000, force: true });
      await page.keyboard.press('Control+A').catch(() => {});
      await page.keyboard.press('Backspace').catch(() => {});
      await page.keyboard.type(school, { delay: 10 }).catch(() => {});
      await page.keyboard.press('Enter').catch(() => {});
      await page.keyboard.press('Tab').catch(() => {});
      await humanDelay(120, 260);
      const after = await loc.inputValue({ timeout: 500 }).catch(() => loc.textContent({ timeout: 500 }).catch(() => ''));
      const ok = !!String(after || '').trim() && !/0 items selected/i.test(String(after || ''));
      pushFieldAudit(rowIndex === 0 ? 'education_school' : `education_${rowIndex + 1}_school`, school, String(after || ''), ok, ok ? 'visual-before-after-confirmed' : 'visual-before-after-failed');
      if (ok) out.push(rowIndex === 0 ? 'education_school' : `education_${rowIndex + 1}_school`);
    } catch (_) {}
  }

  if (degree) {
    try {
      const loc = page.locator(`[data-jh-edu-target="${token}"][data-jh-edu-part="degree"]`).first();
      await loc.click({ timeout: 1000, force: true });
      await humanDelay(120, 240);
      const options = [degree, degree.split(/[\-,:]/)[0].trim(), degree.split(/\s+/).slice(0, 2).join(' ').trim()].filter(Boolean);
      let selected = false;
      for (const opt of options) {
        const sels = [`[role="option"]:has-text("${opt}")`, `li[role="option"]:has-text("${opt}")`, `li[role="menuitem"]:has-text("${opt}")`, `[data-automation-id="promptOption"]:has-text("${opt}")`];
        for (const s of sels) {
          try {
            const option = page.locator(s).first();
            await option.waitFor({ state: 'visible', timeout: 700 });
            await option.click({ timeout: 900, force: true });
            selected = true;
            break;
          } catch (_) {}
        }
        if (selected) break;
      }
      await page.keyboard.press('Tab').catch(() => {});
      await humanDelay(120, 260);
      const after = await loc.textContent().catch(() => '');
      const ok = (selected && !!String(after || '').trim() && !/select one/i.test(String(after || ''))) || visualMatch(String(after || ''), degree);
      pushFieldAudit(rowIndex === 0 ? 'education_degree' : `education_${rowIndex + 1}_degree`, degree, String(after || ''), ok, ok ? 'visual-before-after-confirmed' : 'visual-before-after-failed');
      if (ok) out.push(rowIndex === 0 ? 'education_degree' : `education_${rowIndex + 1}_degree`);
    } catch (_) {}
  }

  await page.evaluate(() => {
    for (const el of document.querySelectorAll('[data-jh-edu-target]')) {
      el.removeAttribute('data-jh-edu-target');
      el.removeAttribute('data-jh-edu-part');
    }
  }).catch(() => {});

  return out;
}

async function forceFillExperienceFields(page, profile) {
  if (STRICT_VISUAL_FORM_FILL) {
    const filled = [];
    const skipped = [];
    const experienceEntries = extractExperienceEntries(profile || {});
    const entries = (experienceEntries.length ? experienceEntries : [{
      job_title: String(profile?.experience_job_title || '').trim(),
      company: String(profile?.experience_company || '').trim(),
      from: String(profile?.experience_from || '').trim(),
      to: String(profile?.experience_to || '').trim(),
      role_description: String(profile?.experience_role_description || '').trim(),
    }]).slice(0, 3);

    if (entries.length > 1) {
      const rowReady = await ensureInlineExperienceRowCount(page, entries.length);
      if (rowReady) {
        filled.push(`experience_rows_ready_${entries.length}`);
      } else {
        skipped.push(`experience_rows_not_added_${entries.length}`);
      }
    }

    for (let index = 0; index < entries.length; index++) {
      const entry = entries[index] || {};
      const keyMap = {
        title: index === 0 ? 'experience_job_title' : `experience_${index + 1}_job_title`,
        company: index === 0 ? 'experience_company' : `experience_${index + 1}_company`,
        description: index === 0 ? 'experience_role_description' : `experience_${index + 1}_role_description`,
        from: index === 0 ? 'experience_from' : `experience_${index + 1}_from`,
        to: index === 0 ? 'experience_to' : `experience_${index + 1}_to`,
      };

      const visualWrites = [
        { labels: ['Job Title', 'Title'], value: String(entry.job_title || '').trim(), field: keyMap.title, mode: 'text' },
        { labels: ['Company', 'Employer'], value: String(entry.company || '').trim(), field: keyMap.company, mode: 'text' },
        { labels: ['Role Description', 'Job Description', 'Description'], value: String(entry.role_description || '').trim(), field: keyMap.description, mode: 'text' },
        { labels: ['From', 'Start Date'], value: String(entry.from || '').trim(), field: keyMap.from, mode: 'date' },
        { labels: ['To', 'End Date'], value: String(entry.to || '').trim(), field: keyMap.to, mode: 'date' },
      ];

      for (const write of visualWrites) {
        if (!write.value) {
          continue;
        }
        const r = await fillByVisibleLabelWithRetry(page, {
          labels: write.labels,
          value: write.value,
          occurrence: index,
          mode: write.mode,
          fieldTag: write.field,
        });
        if (r?.ok) {
          if (!filled.includes(write.field)) {
            filled.push(write.field);
          }
          if (!filled.includes(`${write.field}_visual_verified`)) {
            filled.push(`${write.field}_visual_verified`);
          }
        } else {
          if (!skipped.includes(write.field)) {
            skipped.push(write.field);
          }
          if (!skipped.includes(`${write.field}_visual_unconfirmed`)) {
            skipped.push(`${write.field}_visual_unconfirmed`);
          }
        }
      }
    }

    const visualErrorResolved = await resolveReviewRequiredFieldsByErrorLinks(page, profile);
    for (const tag of visualErrorResolved) {
      if (!filled.includes(tag)) {
        filled.push(tag);
      }
    }

    return { filled, skipped };
  }

  return {
    filled: [],
    skipped: ['deprecated_non_visual_execution_disabled_experience'],
  };

  const filled = [];
  const skipped = [];
  const experienceEntries = extractExperienceEntries(profile || {});
  const primaryExperience = experienceEntries[0] || {
    job_title: String(profile.experience_job_title || '').trim(),
    company: String(profile.experience_company || '').trim(),
    from: String(profile.experience_from || '').trim(),
    to: String(profile.experience_to || '').trim(),
    role_description: String(profile.experience_role_description || '').trim(),
  };

  const initialInlineRowCount = await page.locator('input[name="jobTitle"][id*="workExperience-"]').count().catch(() => 0);

  const deletedRows = await cleanupEmptyWorkExperienceRows(page);
  for (const d of deletedRows) {
    if (!filled.includes(d)) {
      filled.push(d);
    }
  }

  const wantedRows = Math.max(1, Math.min(3, experienceEntries.length || 1));
  if (initialInlineRowCount > 0 && wantedRows > 1) {
    const rowReady = await ensureInlineExperienceRowCount(page, wantedRows);
    if (rowReady) {
      filled.push(`experience_rows_ready_${wantedRows}`);
    } else {
      skipped.push(`experience_rows_not_added_${wantedRows}`);
    }
  }

  const inlineRowCount = await page.locator('input[name="jobTitle"][id*="workExperience-"]').count().catch(() => 0);

  const namedFilled = await fillWorkExperienceByFieldNames(page, experienceEntries.length ? experienceEntries : [primaryExperience]);
  for (const f of namedFilled) {
    if (!filled.includes(f)) {
      filled.push(f);
    }
  }

  if (inlineRowCount === 0) {
    const nativeFilled = await fillExperienceDialogNative(page, {
      ...profile,
      experience_job_title: primaryExperience.job_title,
      experience_company: primaryExperience.company,
      experience_from: primaryExperience.from,
      experience_to: primaryExperience.to,
      experience_role_description: primaryExperience.role_description,
    });
    for (const f of nativeFilled) {
      if (!filled.includes(f)) {
        filled.push(f);
      }
    }
  }

  let jobTitle = String(primaryExperience.job_title || '').trim();
  if (!jobTitle) {
    try {
      const heading = await detectPageHeading(page);
      const normalized = (heading || '').trim();
      if (normalized && !/my experience|careers|review|application/i.test(normalized)) {
        jobTitle = normalized;
      }
    } catch (_) {}
  }
  if (inlineRowCount === 0 && jobTitle && !filled.includes('experience_job_title')) {
    const solved = await answerQuestionByXPathContainer(page, 'job title', jobTitle, 'experience_job_title', 'text')
      || await answerQuestionByDomFallback(page, 'Job Title', jobTitle, 'experience_job_title')
      || await clickErrorLinkAndAnswer(page, 'Job Title', jobTitle, 'text');
    if (solved) filled.push('experience_job_title');
    else skipped.push('experience_job_title');
  } else {
    skipped.push('experience_job_title');
  }

  const company = String(primaryExperience.company || '').trim();
  if (inlineRowCount === 0 && company && !filled.includes('experience_company')) {
    const solved = await answerQuestionByXPathContainer(page, 'company', company, 'experience_company', 'text')
      || await answerQuestionByDomFallback(page, 'Company', company, 'experience_company')
      || await clickErrorLinkAndAnswer(page, 'Company', company, 'text');
    if (solved) filled.push('experience_company');
    else skipped.push('experience_company');
  } else {
    skipped.push('experience_company');
  }

  const fromVal = String(primaryExperience.from || '').trim();
  if (inlineRowCount === 0 && fromVal && !filled.includes('experience_from')) {
    const solved = await fillExperienceDateField(page, 'from', fromVal, 'experience_from');
    if (solved) filled.push('experience_from');
    else skipped.push('experience_from');
  } else {
    skipped.push('experience_from');
  }

  const toVal = String(primaryExperience.to || '').trim();
  if (inlineRowCount === 0 && toVal && !filled.includes('experience_to') && !filled.includes('experience_to_current')) {
    const isPresent = /present|current/i.test(toVal);
    let solved = false;
    if (isPresent) {
      solved = await answerQuestionByXPathContainer(page, 'currently work', 'Yes', 'experience_to_current', 'radio')
        || await answerQuestionByDomFallback(page, 'I currently work here', 'Yes', 'experience_to_current')
        || await clickErrorLinkAndAnswer(page, 'I currently work here', 'Yes', 'radio');
      if (solved) {
        filled.push('experience_to_current');
      }
    }
    if (!solved) {
      solved = await fillExperienceDateField(page, 'to', toVal, 'experience_to');
      if (solved) filled.push('experience_to');
      else skipped.push('experience_to');
    }
  } else {
    skipped.push('experience_to');
  }

  const roleDescription = String(primaryExperience.role_description || '').trim();
  if (inlineRowCount === 0 && roleDescription && !filled.includes('experience_role_description')) {
    const solved = await answerQuestionByXPathContainer(page, 'role description', roleDescription, 'experience_role_description', 'text')
      || await answerQuestionByXPathContainer(page, 'job description', roleDescription, 'experience_role_description', 'text')
      || await answerQuestionByXPathContainer(page, 'responsibilities', roleDescription, 'experience_role_description', 'text')
      || await answerQuestionByDomFallback(page, 'Role Description', roleDescription, 'experience_role_description')
      || await answerQuestionByDomFallback(page, 'Job Description', roleDescription, 'experience_role_description')
      || await clickErrorLinkAndAnswer(page, 'Role Description', roleDescription, 'text');
    if (solved) filled.push('experience_role_description');
    else skipped.push('experience_role_description');
  } else {
    skipped.push('experience_role_description');
  }

  const deletedRowsAfterFill = await cleanupEmptyWorkExperienceRows(page);
  for (const d of deletedRowsAfterFill) {
    if (!filled.includes(d)) {
      filled.push(d);
    }
  }

  return { filled, skipped };
}

async function forceFillEducationFields(page, profile) {
  if (STRICT_VISUAL_FORM_FILL) {
    const filled = [];
    const skipped = [];

    await dismissDiscardApplicationPopup(page);

    const allEducationEntries = extractEducationEntries(profile || {});
    if (!allEducationEntries.length) {
      skipped.push('education_entries_missing');
      return { filled, skipped };
    }

    const educationEntries = allEducationEntries.slice(0, STRICT_EDUCATION_ENTRY_LIMIT);
    if (allEducationEntries.length > 1) {
      skipped.push('education_multiple_schools_disabled');
    }

    filled.push('education_single_entry_mode');

    const existingRows = (await getInlineEducationRowKeys(page)).length;
    if (existingRows === 0) {
      const openedEducation = await clickAddEducationRow(page);
      if (openedEducation) {
        await humanDelay(220, 420);
        filled.push('education_editor_opened');
      } else {
        skipped.push('education_editor_not_opened');
      }
    }

    for (let index = 0; index < educationEntries.length; index++) {
      await dismissDiscardApplicationPopup(page);

      const entry = educationEntries[index] || {};
      const prefix = getEducationEntryTagPrefix(index);
      const school = String(entry.school || '').trim();
      const degree = String(entry.degree || '').trim();

      if (school) {
        const schoolKey = `${prefix}_school`;
        const r = await fillByVisibleLabelWithRetry(page, {
          labels: EDUCATION_SCHOOL_LABELS,
          value: school,
          occurrence: index,
          mode: 'text',
          fieldTag: schoolKey,
          pressEnter: true,
        });
        if (r?.ok) {
          addVisualFillTagResult(filled, skipped, schoolKey, r);
        } else {
          const schoolVisible = await confirmEducationSchoolVisible(page, school);
          if (schoolVisible) {
            mergeUniqueTags(filled, [schoolKey, `${schoolKey}_visual_verified`]);
          } else {
            addVisualFillTagResult(filled, skipped, schoolKey, r);
          }
        }
      }

      if (degree) {
        const degreeKey = `${prefix}_degree`;
        const r = await fillByVisibleLabelWithRetry(page, {
          labels: EDUCATION_DEGREE_LABELS,
          value: degree,
          occurrence: index,
          mode: 'dropdown',
          fieldTag: degreeKey,
        });
        addVisualFillTagResult(filled, skipped, degreeKey, r);
      }
    }

    const visualErrorResolved = await resolveReviewRequiredFieldsByErrorLinks(page, profile);
    mergeUniqueTags(filled, visualErrorResolved);

    return { filled, skipped };
  }

  return {
    filled: [],
    skipped: ['deprecated_non_visual_execution_disabled_education'],
  };

  const filled = [];
  const skipped = [];
  const startedAt = Date.now();
  const budgetMs = 35000;

  const educationEntries = extractEducationEntries(profile || {});
  if (!educationEntries.length) {
    skipped.push('education_entries_missing');
    return { filled, skipped };
  }

  let inlineEducationRowCount = (await getInlineEducationRowKeys(page)).length;
  const wantedRows = Math.max(1, Math.min(3, educationEntries.length));
  if (inlineEducationRowCount === 0) {
    const opened = await clickAddEducationRow(page);
    if (opened) {
      await humanDelay(280, 520);
      filled.push('education_editor_opened');
      inlineEducationRowCount = (await getInlineEducationRowKeys(page)).length;
    }
  }

  if (inlineEducationRowCount > 0 && wantedRows > 1) {
    const rowReady = await ensureInlineEducationRowCount(page, wantedRows);
    if (rowReady) {
      filled.push(`education_rows_ready_${wantedRows}`);
    } else {
      skipped.push(`education_rows_not_added_${wantedRows}`);
    }
  }

  const rowFilled = await fillEducationByFieldNames(page, educationEntries);
  for (const f of rowFilled) {
    if (!filled.includes(f)) {
      filled.push(f);
    }
  }

  for (let idx = 0; idx < educationEntries.length; idx++) {
    if (Date.now() - startedAt > budgetMs) {
      skipped.push('education_fill_budget_exceeded');
      break;
    }

    if (idx > 0) {
      const currentRows = (await getInlineEducationRowKeys(page)).length;
      if (currentRows < idx + 1) {
        const added = await clickAddEducationRow(page);
        if (added) {
          await humanDelay(200, 420);
        }
      }
    }

    const controlFilled = await fillEducationEntryByVisibleControls(page, educationEntries[idx], idx);
    for (const f of controlFilled) {
      if (!filled.includes(f)) {
        filled.push(f);
      }
    }
  }

  const rowNativeFilled = await fillEducationByRowKeyNative(page, educationEntries);
  for (const f of rowNativeFilled) {
    if (!filled.includes(f)) {
      filled.push(f);
    }
  }

  if (!filled.some((x) => String(x).startsWith('education_') || x === 'education_school' || x === 'education_degree' || x === 'education_end_date')) {
    skipped.push('education_fields_not_filled');
  }

  return { filled, skipped };
}

async function countVisibleEducationEntries(page) {
  try {
    const locatorCount = await page.locator('[data-automation-id="educationSection"], [data-automation-id="educationItem"], [data-automation-id*="education" i]').count().catch(() => 0);
    const rowKeyCount = (await getInlineEducationRowKeys(page)).length;
    const sectionCount = await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const sections = Array.from(document.querySelectorAll('section, div, fieldset')).filter((el) => {
        if (!isVisible(el)) return false;
        const txt = (el.innerText || '').toLowerCase();
        return txt.includes('education');
      });

      let maxRows = 0;
      for (const section of sections) {
        const rows = Array.from(section.querySelectorAll('input, select, textarea')).filter((el) => {
          if (!isVisible(el)) return false;
          const id = String(el.id || '').toLowerCase();
          return id.includes('education') || id.includes('school') || id.includes('institution') || id.includes('degree') || id.includes('graduation');
        });
        if (rows.length > maxRows) {
          maxRows = rows.length;
        }
      }

      if (maxRows === 0) {
        return 0;
      }

      return Math.max(1, Math.floor(maxRows / 3));
    }).catch(() => 0);

    return Math.max(locatorCount, rowKeyCount, sectionCount);
  } catch (_) {
    return 0;
  }
}

async function countMatchedEducationEntries(page, entries) {
  const expected = Array.isArray(entries) ? entries.filter(Boolean).slice(0, MAX_EDUCATION_ENTRIES) : [];
  if (!expected.length) {
    return 0;
  }

  try {
    return await page.evaluate((expected) => {
      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const normalize = (s) => String(s || '').replace(/\s+/g, ' ').trim().toLowerCase();
      const toTokens = (s) => normalize(s)
        .replace(/[^a-z0-9 ]+/g, ' ')
        .split(' ')
        .map((x) => x.trim())
        .filter((x) => x.length >= 2);
      const normalizeSchool = (s) => {
        const raw = normalize(s)
          .replace(/\buniversity\b/g, 'univ')
          .replace(/\bcollege\b/g, 'col')
          .replace(/\binstitute\b/g, 'inst')
          .replace(/\bof\b/g, ' ')
          .replace(/\bat\b/g, ' ')
          .replace(/\s+/g, ' ')
          .trim();
        return raw;
      };
      const schoolMatches = (observedText, expectedSchool) => {
        const observed = normalizeSchool(observedText);
        const expectedValue = normalizeSchool(expectedSchool);
        if (!observed || !expectedValue) return false;
        if (observed.includes(expectedValue) || expectedValue.includes(observed)) {
          return true;
        }
        const expectedTokens = Array.from(new Set(toTokens(expectedValue)));
        if (!expectedTokens.length) return false;
        const matchedTokens = expectedTokens.filter((token) => observed.includes(token));
        const ratio = matchedTokens.length / expectedTokens.length;
        return ratio >= 0.6;
      };
      const controls = Array.from(document.querySelectorAll('input, select, textarea')).filter(isVisible);
      const eduControls = controls.filter((el) => {
        const id = normalize(el.id);
        const name = normalize(el.getAttribute('name'));
        const aria = normalize(el.getAttribute('aria-label'));
        const combo = `${id} ${name} ${aria}`;
        return /education|school|institution|degree|graduation|field of study|major/.test(combo);
      });

      const values = eduControls
        .map((el) => {
          if (String(el.tagName || '').toLowerCase() === 'select') {
            const opt = el.options && el.selectedIndex >= 0 ? el.options[el.selectedIndex] : null;
            return normalize(opt?.textContent || el.value || '');
          }
          return normalize(el.value || el.getAttribute('value') || el.textContent || '');
        })
        .filter(Boolean);

      const tokenLike = [];
      const tokenSelectors = [
        '[data-automation-id*="token" i]',
        '[data-automation-id*="pill" i]',
        '[data-automation-id*="selection" i]',
        '[data-automation-id*="selected" i]',
        '[data-automation-id*="chip" i]',
        '[data-automation-id*="tag" i]',
        '[role="option"][aria-selected="true"]',
        '[aria-label*="remove" i]',
      ];
      for (const sel of tokenSelectors) {
        const nodes = Array.from(document.querySelectorAll(sel)).filter(isVisible);
        for (const node of nodes) {
          const txt = normalize(node.textContent || node.getAttribute('aria-label') || '');
          if (txt && txt.length <= 240) {
            tokenLike.push(txt);
          }
        }
      }

      const sections = Array.from(document.querySelectorAll('section, div, fieldset')).filter((el) => {
        if (!isVisible(el)) return false;
        const txt = normalize(el.innerText);
        return txt.includes('education');
      });
      const sectionText = normalize(sections.map((s) => s.innerText || '').join(' | '));
      const observedPool = [...values, ...tokenLike, sectionText].filter(Boolean);

      let matched = 0;
      for (const entry of expected) {
        const school = normalize(entry.school);
        const degree = normalize(entry.degree);

        const schoolOk = school && observedPool.some((v) => schoolMatches(v, school));
        const degreeOk = !degree || observedPool.some((v) => v.includes(degree) || degree.includes(v));

        if (schoolOk && degreeOk) {
          matched += 1;
        }
      }

      return matched;
    }, expected);
  } catch (_) {
    return 0;
  }
}

async function validateStepWithPlaywright(page, stepKey) {
  if (stepKey !== 'my_experience') {
    return { ok: true, issues: [] };
  }

  const stillOnStep = await isLikelyStillOnStep(page, stepKey);
  if (!stillOnStep) {
    return { ok: true, issues: [], rows: [] };
  }

  try {
    const details = await page.evaluate(() => {
      const out = { issues: [], rows: [] };
      const extractKey = (id) => {
        const m = String(id || '').match(/workExperience-(\d+)--/i);
        return m ? m[1] : '';
      };

      const visible = (el) => {
        if (!el) return false;
        const r = el.getBoundingClientRect();
        const s = window.getComputedStyle(el);
        return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
      };

      const titleInputs = Array.from(document.querySelectorAll('input[name="jobTitle"][id*="workExperience-"]')).filter(visible);
      const keys = [];
      for (const input of titleInputs) {
        const key = extractKey(input.id);
        if (key && !keys.includes(key)) keys.push(key);
      }

      if (!keys.length) {
        const allWorkIds = Array.from(document.querySelectorAll('[id*="workExperience-"]'));
        for (const el of allWorkIds) {
          const key = extractKey(el.id || '');
          if (key && !keys.includes(key)) {
            keys.push(key);
          }
        }
      }

      if (!keys.length) {
        out.issues.push('no_work_experience_rows_detected');
      }

      const readTextOrValue = (el) => {
        if (!el) return '';
        const byValue = ('value' in el) ? String(el.value || '').trim() : '';
        if (byValue) return byValue;
        const ariaValText = String(el.getAttribute?.('aria-valuetext') || '').trim();
        if (ariaValText) return ariaValText;
        const ariaValNow = String(el.getAttribute?.('aria-valuenow') || '').trim();
        if (ariaValNow) return ariaValNow;
        return String(el.textContent || '').trim();
      };

      for (const key of keys) {
        const job = document.getElementById(`workExperience-${key}--jobTitle`);
        const company = document.getElementById(`workExperience-${key}--companyName`);
        const startM = document.getElementById(`workExperience-${key}--startDate-dateSectionMonth-input`);
        const startY = document.getElementById(`workExperience-${key}--startDate-dateSectionYear-input`);
        const startMDisplay = document.getElementById(`workExperience-${key}--startDate-dateSectionMonth-display`);
        const startYDisplay = document.getElementById(`workExperience-${key}--startDate-dateSectionYear-display`);
        const endM = document.getElementById(`workExperience-${key}--endDate-dateSectionMonth-input`);
        const endY = document.getElementById(`workExperience-${key}--endDate-dateSectionYear-input`);
        const endMDisplay = document.getElementById(`workExperience-${key}--endDate-dateSectionMonth-display`);
        const endYDisplay = document.getElementById(`workExperience-${key}--endDate-dateSectionYear-display`);
        const current = document.getElementById(`workExperience-${key}--currentlyWorkHere`);

        const jobVal = readTextOrValue(job);
        const companyVal = readTextOrValue(company);
        const startMonthVal = readTextOrValue(startM) || readTextOrValue(startMDisplay);
        const startYearVal = readTextOrValue(startY) || readTextOrValue(startYDisplay);
        const endMonthVal = readTextOrValue(endM) || readTextOrValue(endMDisplay);
        const endYearVal = readTextOrValue(endY) || readTextOrValue(endYDisplay);
        const currentChecked = !!current?.checked;

        out.rows.push({ key, jobVal, companyVal, startMonthVal, startYearVal, endMonthVal, endYearVal, currentChecked });

        if (!jobVal) out.issues.push(`row_${key}_job_title_empty`);
        if (!companyVal) out.issues.push(`row_${key}_company_empty`);
        if (!startMonthVal || !startYearVal) out.issues.push(`row_${key}_from_empty`);
        if (!currentChecked && (!endMonthVal || !endYearVal)) out.issues.push(`row_${key}_to_empty`);
      }

      const errorButtons = Array.from(document.querySelectorAll('[data-automation-id="errorHeading"] button, button.css-tgkpvs')).filter(visible);
      for (const btn of errorButtons) {
        const t = (btn.textContent || '').toLowerCase();
        if (t.includes('job title') || t.includes('company') || t.includes('from') || t.includes('to')) {
          out.issues.push('experience_error_summary_present');
          break;
        }
      }

      return out;
    });

    const issues = Array.isArray(details.issues) ? details.issues : [];
    return { ok: issues.length === 0, issues, rows: details.rows || [] };
  } catch (_) {
    return { ok: false, issues: ['experience_validation_check_failed'] };
  }
}

async function captureExperienceFieldSnapshot(page) {
  try {
    const snap = await page.evaluate(() => {
      const out = {};
      const labels = [
        { key: 'job_title', needle: 'job title' },
        { key: 'company', needle: 'company' },
        { key: 'from', needle: 'from' },
        { key: 'to', needle: 'to' },
      ];

      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const roots = Array.from(document.querySelectorAll('div[role="dialog"], [data-automation-id*="workexperience" i], [data-automation-id*="workExperience" i]'));
      const root = roots.find(isVisible) || document;

      for (const l of labels) {
        const blocks = Array.from(root.querySelectorAll('div, section, fieldset, li')).filter((el) => {
          const txt = (el.innerText || '').toLowerCase();
          return txt.includes(l.needle) && !!el.querySelector('input, textarea, select');
        });
        const b = blocks[0] || null;
        if (!b) {
          out[l.key] = 'MISSING_CONTAINER';
          continue;
        }
        const vals = Array.from(b.querySelectorAll('input, textarea, select'))
          .filter(isVisible)
          .map((el) => {
            const tag = (el.tagName || '').toLowerCase();
            let v = '';
            if (tag === 'select') {
              v = (el.options && el.selectedIndex >= 0 && el.options[el.selectedIndex]) ? (el.options[el.selectedIndex].text || '') : '';
            } else {
              v = el.value || '';
            }
            return String(v).trim();
          })
          .filter((v) => v.length > 0);
        out[l.key] = vals.length ? vals.join('/') : 'EMPTY';
      }

      const fileInput = root.querySelector('input[type="file"]') || document.querySelector('input[type="file"]');
      out.upload_present = !!fileInput;
      return out;
    });
    return snap || null;
  } catch (_) {
    return null;
  }
}

async function captureExperienceActionSnapshot(page) {
  try {
    const data = await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const controls = Array.from(document.querySelectorAll('button, a, [role="button"], [role="link"], div[role="button"]'))
        .filter(isVisible)
        .map((el) => {
          const txt = ((el.textContent || '') + ' ' + (el.getAttribute('aria-label') || '')).replace(/\s+/g, ' ').trim();
          const aid = el.getAttribute('data-automation-id') || '';
          return { txt, aid };
        })
        .filter((x) => x.txt.length > 0)
        .filter((x) => {
          const t = x.txt.toLowerCase();
          const a = x.aid.toLowerCase();
          return t.includes('experience') || t.includes('add') || t.includes('job title') || t.includes('company') || t.includes('from') || t.includes('to') || a.includes('experience') || a.includes('add');
        })
        .slice(0, 30);

      return controls;
    });
    return Array.isArray(data) ? data : [];
  } catch (_) {
    return [];
  }
}

async function writeExperienceDebugDump(page, screenshotDir, applicationId, evidenceParts) {
  try {
    const dump = await page.evaluate(() => {
      const isVisible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const toNode = (el) => {
        if (!el) return null;
        const rect = el.getBoundingClientRect();
        const txt = ((el.textContent || '') + ' ' + (el.getAttribute('aria-label') || '')).replace(/\s+/g, ' ').trim();
        const outer = (el.outerHTML || '').slice(0, 2000);
        return {
          tag: (el.tagName || '').toLowerCase(),
          aid: el.getAttribute('data-automation-id') || '',
          role: el.getAttribute('role') || '',
          id: el.id || '',
          className: el.className || '',
          text: txt.slice(0, 250),
          rect: { x: Math.round(rect.x), y: Math.round(rect.y), w: Math.round(rect.width), h: Math.round(rect.height) },
          outerHTML: outer,
        };
      };

      const errors = Array.from(document.querySelectorAll('a, [role="link"], div, span'))
        .filter(isVisible)
        .filter((el) => /error-(job title|company|from|to)/i.test((el.textContent || '').trim()))
        .slice(0, 20)
        .map((el) => toNode(el));

      const addButtons = Array.from(document.querySelectorAll('[data-automation-id="add-button"], button, [role="button"], div[role="button"]'))
        .filter(isVisible)
        .filter((el) => {
          const txt = ((el.textContent || '') + ' ' + (el.getAttribute('aria-label') || '')).toLowerCase();
          const aid = (el.getAttribute('data-automation-id') || '').toLowerCase();
          return aid.includes('add-button') || txt.includes('add');
        })
        .slice(0, 30)
        .map((el) => {
          const parent = el.closest('section, div, fieldset, li, form');
          const trail = [];
          let cur = el;
          for (let i = 0; i < 6 && cur; i++) {
            const txt = (cur.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 120);
            trail.push({
              tag: (cur.tagName || '').toLowerCase(),
              aid: cur.getAttribute?.('data-automation-id') || '',
              role: cur.getAttribute?.('role') || '',
              cls: cur.className || '',
              text: txt,
            });
            cur = cur.parentElement;
          }

          let heading = '';
          try {
            let p = el.parentElement;
            while (p && !heading) {
              const h = p.querySelector('h1, h2, h3, h4, [data-automation-id*="section" i], label');
              if (h) {
                heading = (h.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 150);
              }
              p = p.parentElement;
            }
          } catch (_) {}

          const node = toNode(el);
          node.parentText = ((parent?.innerText || '') + '').replace(/\s+/g, ' ').trim().slice(0, 450);
          node.parentAid = parent?.getAttribute?.('data-automation-id') || '';
          node.nearHeading = heading;
          node.ancestorTrail = trail;
          return node;
        });

      const dialogs = Array.from(document.querySelectorAll('div[role="dialog"], [data-automation-id*="workexperience" i], [data-automation-id*="workExperience" i]'))
        .filter(isVisible)
        .slice(0, 8)
        .map((el) => toNode(el));

      const workGroups = Array.from(document.querySelectorAll('[data-automation-id="applyFlowMyExpPage"] [role="group"], [data-automation-id="applyFlowMyExpPage"], [role="group"]'))
        .filter(isVisible)
        .filter((el) => ((el.innerText || '').toLowerCase().includes('work experience')))
        .slice(0, 3);

      const workExpInputs = [];
      for (const group of workGroups) {
        const inputs = Array.from(group.querySelectorAll('input, textarea, select, [role="combobox"], button'))
          .filter(isVisible)
          .slice(0, 60)
          .map((el) => ({
            tag: (el.tagName || '').toLowerCase(),
            aid: el.getAttribute('data-automation-id') || '',
            role: el.getAttribute('role') || '',
            name: el.getAttribute('name') || '',
            id: el.id || '',
            ariaLabel: el.getAttribute('aria-label') || '',
            ariaValueText: el.getAttribute('aria-valuetext') || '',
            ariaValueNow: el.getAttribute('aria-valuenow') || '',
            placeholder: el.getAttribute('placeholder') || '',
            text: ((el.textContent || '') + '').replace(/\s+/g, ' ').trim().slice(0, 120),
            value: ('value' in el ? String(el.value || '') : '').slice(0, 120),
            checked: ('checked' in el) ? !!el.checked : null,
            disabled: ('disabled' in el) ? !!el.disabled : null,
            readOnly: ('readOnly' in el) ? !!el.readOnly : null,
          }));
        workExpInputs.push({
          heading: ((group.querySelector('h1,h2,h3,h4,label,[data-automation-id*="section" i]')?.textContent || '') + '').replace(/\s+/g, ' ').trim().slice(0, 120),
          inputs,
        });
      }

      return {
        url: location.href,
        title: document.title,
        timestamp: new Date().toISOString(),
        errors,
        addButtons,
        dialogs,
        workExpInputs,
      };
    });

    const stamp = Date.now();
    const fileName = `wd_exp_debug_${applicationId || 'na'}_${stamp}.json`;
    const targetDir = (screenshotDir && fs.existsSync(screenshotDir) && fs.statSync(screenshotDir).isDirectory())
      ? screenshotDir
      : '/tmp';
    const filePath = path.join(targetDir, fileName);
    fs.writeFileSync(filePath, JSON.stringify(dump, null, 2), 'utf8');
    evidenceParts.push(`Experience debug dump: ${filePath}`);
    return filePath;
  } catch (e) {
    try {
      const fallbackPath = path.join('/tmp', `wd_exp_debug_${applicationId || 'na'}_${Date.now()}_fallback_error.txt`);
      fs.writeFileSync(fallbackPath, String(e && e.message ? e.message : e), 'utf8');
      evidenceParts.push(`Experience debug dump failed: ${fallbackPath}`);
    } catch (_) {}
    return '';
  }
}

async function writeApplicationDebugDump(page, screenshotDir, applicationId, evidenceParts) {
  try {
    const dump = await page.evaluate(() => {
      const visible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const errorButtons = Array.from(document.querySelectorAll('[data-automation-id="errorHeading"] button, button.css-tgkpvs, [data-automation-id*="error" i] button'))
        .filter(visible)
        .map((el) => (el.textContent || '').replace(/\s+/g, ' ').trim())
        .filter(Boolean)
        .slice(0, 20);

      const promptOptions = Array.from(document.querySelectorAll('[data-automation-id="promptOption"], li[role="option"], div[role="option"]'))
        .filter(visible)
        .map((el) => (el.textContent || '').replace(/\s+/g, ' ').trim())
        .filter(Boolean)
        .slice(0, 30);

      const questionRoots = Array.from(document.querySelectorAll('[data-automation-id="formField"], [data-automation-id="questionField"], [data-automation-id*="question" i], [id*="questionnaire" i]'))
        .slice(0, 60)
        .map((root) => {
          const text = (root.innerText || '').replace(/\s+/g, ' ').trim();
          const inputs = Array.from(root.querySelectorAll('input, textarea, select, [role="combobox"], [role="radio"], [role="button"]'))
            .slice(0, 30)
            .map((el) => ({
              tag: (el.tagName || '').toLowerCase(),
              id: el.id || '',
              name: el.getAttribute('name') || '',
              type: el.getAttribute('type') || '',
              role: el.getAttribute('role') || '',
              aid: el.getAttribute('data-automation-id') || '',
              ariaLabel: el.getAttribute('aria-label') || '',
              ariaChecked: el.getAttribute('aria-checked') || '',
              visible: visible(el),
              checked: !!el.checked,
              value: ('value' in el) ? String(el.value || '') : '',
              text: (el.textContent || '').replace(/\s+/g, ' ').trim(),
            }));

          const radios = Array.from(root.querySelectorAll('label, [role="radio"], [data-automation-id="radioBtn"]'))
            .filter(visible)
            .map((el) => (el.textContent || '').replace(/\s+/g, ' ').trim())
            .filter(Boolean)
            .slice(0, 20);

          const selects = Array.from(root.querySelectorAll('select')).map((s) => ({
            value: String(s.value || ''),
            options: Array.from(s.options || []).map((o) => (o.text || '').trim()).filter(Boolean).slice(0, 30),
          }));

          return {
            visible: visible(root),
            text,
            aid: root.getAttribute('data-automation-id') || '',
            radios,
            selects,
            inputs,
          };
        });

      return {
        url: window.location.href,
        title: document.title,
        errorButtons,
        promptOptions,
        questionRoots,
      };
    });

    const stamp = Date.now();
    const fileName = `wd_app_debug_${applicationId || 'na'}_${stamp}.json`;
    const targetDir = (screenshotDir && fs.existsSync(screenshotDir) && fs.statSync(screenshotDir).isDirectory())
      ? screenshotDir
      : '/tmp';
    const filePath = path.join(targetDir, fileName);
    fs.writeFileSync(filePath, JSON.stringify(dump, null, 2), 'utf8');
    evidenceParts.push(`Application debug dump: ${filePath}`);
    return filePath;
  } catch (e) {
    try {
      const fallbackPath = path.join('/tmp', `wd_app_debug_${applicationId || 'na'}_${Date.now()}_fallback_error.txt`);
      fs.writeFileSync(fallbackPath, String(e && e.message ? e.message : e), 'utf8');
      evidenceParts.push(`Application debug dump failed: ${fallbackPath}`);
    } catch (_) {}
    return '';
  }
}

async function writeReviewDebugDump(page, screenshotDir, applicationId, evidenceParts) {
  try {
    const dump = await page.evaluate(() => {
      const visible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const errors = Array.from(document.querySelectorAll('[data-automation-id="errorMessage"], [data-automation-id="inlineError"], [data-automation-id*="error" i], .error-message-text, [role="alert"]'))
        .map((el) => (el.textContent || '').replace(/\s+/g, ' ').trim())
        .filter(Boolean)
        .slice(0, 40);

      const actions = Array.from(document.querySelectorAll('button, a, [role="button"], input[type="submit"], input[type="button"]'))
        .filter(visible)
        .map((el) => ({
          tag: (el.tagName || '').toLowerCase(),
          aid: el.getAttribute('data-automation-id') || '',
          aria: el.getAttribute('aria-label') || '',
          text: ((el.textContent || '') + ' ' + (el.getAttribute('value') || '')).replace(/\s+/g, ' ').trim(),
        }))
        .filter((x) => x.text)
        .slice(0, 60);

      const allErrorElements = Array.from(document.querySelectorAll('[data-automation-id*="error" i], button, a, [role="button"], [role="link"]'))
        .map((el) => ({
          tag: (el.tagName || '').toLowerCase(),
          id: el.id || '',
          aid: el.getAttribute('data-automation-id') || '',
          role: el.getAttribute('role') || '',
          visible: visible(el),
          text: (el.textContent || '').replace(/\s+/g, ' ').trim(),
          aria: el.getAttribute('aria-label') || '',
        }))
        .filter((x) => x.text || x.aid)
        .slice(0, 220);

      const checkboxes = Array.from(document.querySelectorAll('input[type="checkbox"]')).map((el) => ({
        id: el.id || '',
        name: el.getAttribute('name') || '',
        aid: el.getAttribute('data-automation-id') || '',
        checked: !!el.checked,
        required: !!el.required,
        visible: visible(el),
        text: (el.closest('label, div, section, li, fieldset')?.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 220),
      })).slice(0, 80);

      const textInputs = Array.from(document.querySelectorAll('input[type="text"], input:not([type]), textarea, select')).map((el) => ({
        tag: (el.tagName || '').toLowerCase(),
        id: el.id || '',
        name: el.getAttribute('name') || '',
        aid: el.getAttribute('data-automation-id') || '',
        value: ('value' in el) ? String(el.value || '') : '',
        visible: visible(el),
        text: (el.closest('div, section, li, fieldset')?.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 220),
      })).slice(0, 120);

      return {
        url: window.location.href,
        title: document.title,
        errors,
        actions,
        allErrorElements,
        checkboxes,
        textInputs,
      };
    });

    const stamp = Date.now();
    const fileName = `wd_review_debug_${applicationId || 'na'}_${stamp}.json`;
    const targetDir = (screenshotDir && fs.existsSync(screenshotDir) && fs.statSync(screenshotDir).isDirectory())
      ? screenshotDir
      : '/tmp';
    const filePath = path.join(targetDir, fileName);
    fs.writeFileSync(filePath, JSON.stringify(dump, null, 2), 'utf8');
    evidenceParts.push(`Review debug dump: ${filePath}`);
    return filePath;
  } catch (e) {
    try {
      const fallbackPath = path.join('/tmp', `wd_review_debug_${applicationId || 'na'}_${Date.now()}_fallback_error.txt`);
      fs.writeFileSync(fallbackPath, String(e && e.message ? e.message : e), 'utf8');
      evidenceParts.push(`Review debug dump failed: ${fallbackPath}`);
    } catch (_) {}
    return '';
  }
}

async function writeGenericStepDebugDump(page, screenshotDir, applicationId, evidenceParts, label = 'step') {
  try {
    const dump = await page.evaluate((label) => {
      const visible = (el) => {
        if (!el) return false;
        const rect = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
      };

      const controls = Array.from(document.querySelectorAll('input, textarea, select, button, [role="radio"], [role="combobox"], [role="checkbox"], [data-automation-id]'))
        .slice(0, 600)
        .map((el) => ({
          tag: (el.tagName || '').toLowerCase(),
          id: el.id || '',
          name: el.getAttribute('name') || '',
          type: el.getAttribute('type') || '',
          role: el.getAttribute('role') || '',
          aid: el.getAttribute('data-automation-id') || '',
          visible: visible(el),
          checked: !!el.checked,
          required: !!el.required,
          value: ('value' in el) ? String(el.value || '') : '',
          aria: (el.getAttribute('aria-label') || '') + ' ' + (el.getAttribute('aria-valuetext') || '') + ' ' + (el.getAttribute('aria-checked') || ''),
          text: (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 140),
        }));

      const errors = Array.from(document.querySelectorAll('[data-automation-id*="error" i], .error-message-text, [role="alert"]'))
        .map((el) => (el.textContent || '').replace(/\s+/g, ' ').trim())
        .filter(Boolean)
        .slice(0, 50);

      return {
        label,
        url: window.location.href,
        title: document.title,
        heading: (document.querySelector('[data-automation-id="pageHeaderTitle"], [data-automation-id="stepTitle"], h1, h2')?.textContent || '').trim(),
        errors,
        controls,
      };
    }, label);

    const stamp = Date.now();
    const safeLabel = String(label || 'step').replace(/[^a-z0-9_-]/gi, '_');
    const fileName = `wd_${safeLabel}_debug_${applicationId || 'na'}_${stamp}.json`;
    const targetDir = (screenshotDir && fs.existsSync(screenshotDir) && fs.statSync(screenshotDir).isDirectory())
      ? screenshotDir
      : '/tmp';
    const filePath = path.join(targetDir, fileName);
    fs.writeFileSync(filePath, JSON.stringify(dump, null, 2), 'utf8');
    evidenceParts.push(`Generic step debug dump (${label}): ${filePath}`);
    return filePath;
  } catch (e) {
    try {
      const fallbackPath = path.join('/tmp', `wd_generic_debug_${applicationId || 'na'}_${Date.now()}_fallback_error.txt`);
      fs.writeFileSync(fallbackPath, String(e && e.message ? e.message : e), 'utf8');
      evidenceParts.push(`Generic step debug dump failed (${label}): ${fallbackPath}`);
    } catch (_) {}
    return '';
  }
}

async function run() {
  ACTIVE_VISUAL_AUDIT = createVisualAudit();

  const result = {
    ok: false,
    target_step: target_step,
    detected_page: '',
    page_matched: false,
    fields_filled: [],
    fields_skipped: [],
    continue_clicked: false,
    post_continue_url: '',
    page_title: '',
    needs_manual_review: false,
    evidence: '',
    screenshots: [],
    error: '',
  };

  let browser;
  try {
    const launchOpts = { headless: resolveHeadlessMode() };
    if (EXEC_PATH) {
      launchOpts.executablePath = EXEC_PATH;
    }

    const launched = await launchBrowser(launchOpts);
    browser = launched.browser;
    const page = launched.page;
    const urls = parseWorkdayUrls(apply_url);
    const evidenceParts = [];

    // ── Step A: Log in to Workday ──────────────────────────────────────────
    process.stderr.write(`INFO: [${target_step}] Logging in to Workday...\n`);
    await page.goto(urls.signInUrl, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
    await humanDelay(1500, 3000);

    const emailSelector    = '[data-automation-id="email"]';
    const passwordSelector = '[data-automation-id="password"]';
    const signInButton     = '[data-automation-id="click_filter"]';

    // Click "Sign In" link if needed.
    const signInLink = page.locator('a[data-automation-id="signInLink"], a:has-text("Sign In"), button:has-text("Sign In")');
    try {
      await signInLink.first().click({ timeout: 5000 });
      await humanDelay(1000, 2000);
    } catch (_) {}

    // Wait for email field.
    try {
      await page.waitForSelector(emailSelector, { timeout: 15000 });
    } catch (_) {
      try {
        await page.waitForSelector('input[type="email"], input[name="email"]', { timeout: 5000 });
      } catch (e2) {
        result.error = 'Login form not found.';
        writeResult(result);
        return;
      }
    }

    // Fill credentials.
    try {
      await humanType(page, emailSelector, username);
    } catch (_) {
      await humanType(page, 'input[type="email"], input[name="email"]', username);
    }
    await humanDelay(300, 800);

    try {
      await humanType(page, passwordSelector, password);
    } catch (_) {
      await humanType(page, 'input[type="password"]', password);
    }
    await humanDelay(500, 1000);

    // Submit login.
    try {
      await page.click(signInButton, { timeout: 5000 });
    } catch (_) {
      try {
        await page.click('button[type="submit"], [data-automation-id="signInSubmitButton"]', { timeout: 3000 });
      } catch (_2) {
        await page.keyboard.press('Enter');
      }
    }
    await humanDelay(3000, 5000);

    // Check for login errors.
    const errorBanner = page.locator('[data-automation-id="errorMessage"], .error-message, [role="alert"]');
    try {
      const errorText = await errorBanner.first().textContent({ timeout: 3000 });
      if (errorText && errorText.trim().length > 0) {
        result.error = 'Login failed: ' + errorText.trim();
        writeResult(result);
        return;
      }
    } catch (_) {}

    evidenceParts.push('Login OK');
    const ssLogin = await takeScreenshot(page, screenshot_dir, application_id, `wd_${target_step}_login`);
    if (ssLogin) result.screenshots.push(ssLogin);

    // ── Step B: Navigate to the apply URL ──────────────────────────────────
    // Workday should restore wizard position when we navigate back to the apply URL.
    process.stderr.write(`INFO: [${target_step}] Navigating to apply URL: ${apply_url}\n`);
    await page.goto(apply_url, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
    await humanDelay(3000, 5000);
    await acceptCookiesIfPresent(page, evidenceParts);
    await enterApplyFlowIfNeeded(page, evidenceParts);

    if (target_step === 'wizard_auto' || target_step === 'wizard_validate') {
      const strictValidation = target_step === 'wizard_validate';
      let startIndex = AUTO_STEP_ORDER.indexOf(start_step);
      let stepsToRun = AUTO_STEP_ORDER.slice(startIndex >= 0 ? startIndex : 0);
      const stepResults = {};
      let lastUrl = page.url();
      let failedStep = '';

      const inferredStartStep = await inferCurrentWizardStep(page);
      if (inferredStartStep && AUTO_STEP_ORDER.includes(inferredStartStep)) {
        const inferredIndex = AUTO_STEP_ORDER.indexOf(inferredStartStep);
        if (inferredIndex >= 0 && inferredIndex !== startIndex) {
          startIndex = inferredIndex;
          stepsToRun = AUTO_STEP_ORDER.slice(startIndex);
          evidenceParts.push(`Runtime step realignment: requested=${start_step}, inferred=${inferredStartStep}`);
        }
      }

      for (const stepKey of stepsToRun) {
        const stepResult = {
          status: 'not_started',
          detected_page: '',
          page_title: '',
          post_continue_url: '',
          fields_filled: [],
          fields_skipped: [],
          needs_manual_review: false,
          error: '',
        };

        process.stderr.write(`INFO: [wizard_auto] Running ${stepKey}...\n`);
        await humanDelay(500, 1100);

        if (stepKey === 'my_experience') {
          const probe = await probeWorkExperienceDateControls(page);
          if (probe.length > 0) {
            evidenceParts.push('Date control probe before my_experience: ' + JSON.stringify(probe));
          }
        }

        try {
          const detectedPage = await detectPageHeading(page);
          stepResult.detected_page = detectedPage;
          evidenceParts.push(`${stepKey}: detected page "${detectedPage}"`);
        } catch (_) {}

        const local = {
          fields_filled: [],
          fields_skipped: [],
          needs_manual_review: false,
        };

        try {
          await STEP_HANDLERS[stepKey](page, profile_data, local);
          stepResult.fields_filled = local.fields_filled || [];
          stepResult.fields_skipped = local.fields_skipped || [];
          stepResult.needs_manual_review = !!local.needs_manual_review;

          if (strictValidation && stepKey === 'my_experience') {
            const check1 = await validateStepWithPlaywright(page, stepKey);
            if (!check1.ok) {
              const corrected = await forceFillExperienceFields(page, profile_data);
              for (const f of corrected.filled || []) {
                if (!stepResult.fields_filled.includes(f)) {
                  stepResult.fields_filled.push(f);
                }
              }
              if ((corrected.filled || []).length > 0) {
                await commitMyExperienceEditor(page);
              }
              const check2 = await validateStepWithPlaywright(page, stepKey);
              if (!check2.ok) {
                stepResult.error = 'Strict step validation failed: ' + check2.issues.join(', ');
                stepResult.status = 'failed';
                stepResults[stepKey] = stepResult;
                failedStep = stepKey;
                break;
              }
            }
          }
        } catch (e) {
          stepResult.error = 'Step handler failed: ' + e.message;
          stepResult.status = 'failed';
          stepResults[stepKey] = stepResult;
          failedStep = stepKey;
          break;
        }

        const preClickUrl = page.url();
        let clicked = false;
        if (stepKey === 'review_submit') {
          if (REVIEW_SUBMIT_MODE === 'skip') {
            clicked = true;
            evidenceParts.push('review_submit: review_submit_mode=skip; skipped review action click');
          } else if (REVIEW_SUBMIT_MODE === 'save_and_continue_later') {
            clicked = await clickSaveAndContinueLaterButton(page, evidenceParts);
          } else {
            clicked = await clickSubmitButton(page, evidenceParts);
          }
        } else {
          clicked = await clickContinueButton(page, evidenceParts);
        }

        await humanDelay(1400, 2600);
        stepResult.post_continue_url = page.url();
        stepResult.page_title = await page.title();
        if (stepResult.post_continue_url) {
          lastUrl = stepResult.post_continue_url;
        }

        try {
          const validationErrors = page.locator('[data-automation-id="errorMessage"], [data-automation-id="inlineError"], .error-message-text, [data-automation-id*="error" i]');
          const errorCount = await validationErrors.count();
          const sameUrl = stepResult.post_continue_url === preClickUrl;
          const stillOnStep = sameUrl ? await isLikelyStillOnStep(page, stepKey) : false;
          const noAdvanceYet = sameUrl && stillOnStep;
          if (errorCount > 0 && noAdvanceYet) {
            const firstError = await validationErrors.first().textContent({ timeout: 1000 }).catch(() => '');
            const firstErrorLower = String(firstError || '').toLowerCase();

            if (stepKey === 'review_submit' && (firstErrorLower.includes('error-') || firstErrorLower.includes('required'))) {
              clicked = false;
            }

            if (stepKey === 'review_submit' && (firstErrorLower.includes('answer all required questions') || firstErrorLower.includes('page error'))) {
              const backed = await clickBackButton(page, evidenceParts);
              if (backed) {
                await humanDelay(900, 1800);
                try {
                  const backHeading = await detectPageHeading(page);
                  evidenceParts.push(`review_backflow: landed on "${backHeading}" after Back`);
                } catch (_) {}
                await writeGenericStepDebugDump(page, screenshot_dir, application_id, evidenceParts, 'post_backflow');

                try {
                  const localVol = { fields_filled: [], fields_skipped: [], needs_manual_review: false };
                  await handleVoluntaryDisclosures(page, profile_data, localVol);
                  for (const fieldName of (localVol.fields_filled || [])) {
                    if (!stepResult.fields_filled.includes(fieldName)) {
                      stepResult.fields_filled.push(fieldName);
                    }
                  }
                } catch (_) {}

                const forwardAfterVoluntary = await clickContinueButton(page, evidenceParts);
                if (forwardAfterVoluntary) {
                  await humanDelay(900, 1800);
                }

                try {
                  const localSelf = { fields_filled: [], fields_skipped: [], needs_manual_review: false };
                  await handleSelfIdentify(page, profile_data, localSelf);
                  for (const fieldName of (localSelf.fields_filled || [])) {
                    if (!stepResult.fields_filled.includes(fieldName)) {
                      stepResult.fields_filled.push(fieldName);
                    }
                  }
                } catch (_) {}

                const forwardAfterSelf = await clickContinueButton(page, evidenceParts);
                if (forwardAfterSelf) {
                  await humanDelay(900, 1800);
                }

                const resolvedAfterBack = await resolveValidationErrorsFromProfile(page, profile_data, evidenceParts, resume_pdf_path);
                for (const fieldName of resolvedAfterBack) {
                  if (!stepResult.fields_filled.includes(fieldName)) {
                    stepResult.fields_filled.push(fieldName);
                  }
                }

                const forward = await clickContinueButton(page, evidenceParts);
                if (forward) {
                  await humanDelay(1000, 2000);
                  const retryPreUrl = page.url();
                  const retrySubmit = REVIEW_SUBMIT_MODE === 'save_and_continue_later'
                    ? await clickSaveAndContinueLaterButton(page, evidenceParts)
                    : await clickSubmitButton(page, evidenceParts);
                  await humanDelay(1200, 2200);

                  stepResult.post_continue_url = page.url();
                  stepResult.page_title = await page.title();
                  if (stepResult.post_continue_url) {
                    lastUrl = stepResult.post_continue_url;
                  }

                  const retryErrors = await page.locator('[data-automation-id="errorMessage"], [data-automation-id="inlineError"], .error-message-text, [data-automation-id*="error" i]').allTextContents().catch(() => []);
                  const retryCombined = (retryErrors || []).join(' ').toLowerCase();
                  const stillBlocked = REVIEW_SUBMIT_MODE === 'save_and_continue_later'
                    ? false
                    : (retryCombined.includes('answer all required questions') || retryCombined.includes('page error'));

                  const retrySameUrl = stepResult.post_continue_url === retryPreUrl;
                  const retryStillOnStep = retrySameUrl ? await isLikelyStillOnStep(page, stepKey) : false;
                  clicked = !!retrySubmit && !stillBlocked && (!retrySameUrl || !retryStillOnStep);

                  if (!clicked) {
                    stepResult.error = 'Validation blocked step progression after review backflow: ' + String(firstError || '').trim();
                  }
                }
              }
            }

            if (!clicked && stepKey === 'my_experience') {
              const actions = await captureExperienceActionSnapshot(page);
              if (actions.length > 0) {
                evidenceParts.push('Experience actions snapshot: ' + JSON.stringify(actions));
              }
              await writeExperienceDebugDump(page, screenshot_dir, application_id, evidenceParts);
            } else if (!clicked && stepKey === 'application_questions') {
              const appSnap = await captureApplicationQuestionSnapshot(page);
              if (appSnap.length > 0) {
                evidenceParts.push('Application questions snapshot: ' + JSON.stringify(appSnap));
              }
              await writeApplicationDebugDump(page, screenshot_dir, application_id, evidenceParts);
            } else if (!clicked && stepKey === 'review_submit') {
              await writeReviewDebugDump(page, screenshot_dir, application_id, evidenceParts);
            }

            if (!clicked) {
              let resolvedFields = [];
              if (stepKey === 'review_submit') {
                const reviewResolved = await resolveReviewRequiredFieldsByErrorLinks(page, profile_data);
                for (const fieldName of reviewResolved) {
                  if (!resolvedFields.includes(fieldName)) {
                    resolvedFields.push(fieldName);
                  }
                }
              }

              const genericResolved = await resolveValidationErrorsFromProfile(page, profile_data, evidenceParts, resume_pdf_path);
              for (const fieldName of genericResolved) {
                if (!resolvedFields.includes(fieldName)) {
                  resolvedFields.push(fieldName);
                }
              }

              if (resolvedFields.length === 0 && stepKey === 'review_submit' && (firstErrorLower.includes('job title') || firstErrorLower.includes('school or university') || firstErrorLower.includes('degree') || firstErrorLower.includes('error-'))) {
                const forceLocal = { fields_filled: [], fields_skipped: [], needs_manual_review: false };
                await handleMyExperience(page, profile_data, forceLocal);
                const postFixDeletedRows = await cleanupEmptyWorkExperienceRows(page);
                for (const rowTag of postFixDeletedRows) {
                  if (!forceLocal.fields_filled.includes(rowTag)) {
                    forceLocal.fields_filled.push(rowTag);
                  }
                }
                resolvedFields = forceLocal.fields_filled || [];
                if (resolvedFields.length > 0) {
                  evidenceParts.push('review_submit fallback: reran my_experience remediation on required-field errors');
                }
              }
              if (resolvedFields.length > 0) {
              for (const fieldName of resolvedFields) {
                if (!stepResult.fields_filled.includes(fieldName)) {
                  stepResult.fields_filled.push(fieldName);
                }
              }

              if (stepKey === 'my_experience') {
                const expSnap = await captureExperienceFieldSnapshot(page);
                if (expSnap) {
                  evidenceParts.push('Experience snapshot after resolver: ' + JSON.stringify(expSnap));
                }
                  await writeExperienceDebugDump(page, screenshot_dir, application_id, evidenceParts);
              } else if (stepKey === 'application_questions') {
                const appSnapAfter = await captureApplicationQuestionSnapshot(page);
                if (appSnapAfter.length > 0) {
                  evidenceParts.push('Application questions snapshot after resolver: ' + JSON.stringify(appSnapAfter));
                }
                await writeApplicationDebugDump(page, screenshot_dir, application_id, evidenceParts);
              } else if (stepKey === 'review_submit') {
                await writeReviewDebugDump(page, screenshot_dir, application_id, evidenceParts);
              }

                const retryPreUrl = page.url();
                let retryClicked = false;
                if (stepKey === 'review_submit') {
                  if (REVIEW_SUBMIT_MODE === 'skip') {
                    retryClicked = true;
                    evidenceParts.push('review_submit: review_submit_mode=skip during retry; skipped review action click');
                  } else if (REVIEW_SUBMIT_MODE === 'save_and_continue_later') {
                    retryClicked = await clickSaveAndContinueLaterButton(page, evidenceParts);
                  } else {
                    retryClicked = await clickSubmitButton(page, evidenceParts);
                  }
                } else {
                  retryClicked = await clickContinueButton(page, evidenceParts);
                }

                await humanDelay(1200, 2200);
                stepResult.post_continue_url = page.url();
                stepResult.page_title = await page.title();
                if (stepResult.post_continue_url) {
                  lastUrl = stepResult.post_continue_url;
                }

                const retrySameUrl = stepResult.post_continue_url === retryPreUrl;
                const retryStillOnStep = retrySameUrl ? await isLikelyStillOnStep(page, stepKey) : false;
                clicked = !!retryClicked && (!retrySameUrl || !retryStillOnStep);
                if (!clicked) {
                  stepResult.error = 'Validation blocked step progression after resolver: ' + String(firstError || '').trim();
                }
              } else {
                clicked = false;
                stepResult.error = 'Validation blocked step progression: ' + String(firstError || '').trim();
              }
            }
          }
        } catch (_) {}

        if (!clicked) {
          if (stepKey === 'self_identify') {
            const actions = await getVisibleActionLabels(page);
            const hasSubmit = actions.some((a) => /submit/i.test(String(a || '')));
            if (hasSubmit) {
              clicked = true;
              evidenceParts.push('self_identify: no Continue control; Submit action visible, proceeding to review_submit');
            }
          }
        }

        if (clicked && stepKey === 'review_submit') {
          if (REVIEW_SUBMIT_MODE === 'submit') {
            const confirmed = await isSubmissionConfirmed(page);
            if (!confirmed) {
              clicked = false;
              if (!stepResult.error) {
                stepResult.error = 'Submit click did not reach a confirmed submitted state.';
              }
              await writeReviewDebugDump(page, screenshot_dir, application_id, evidenceParts);
            }
          } else if (REVIEW_SUBMIT_MODE === 'save_and_continue_later') {
            const confirmedSave = await isSaveAndContinueLaterConfirmed(page);
            if (!confirmedSave) {
              clicked = false;
              if (!stepResult.error) {
                stepResult.error = 'Save and Continue Later click did not reach a confirmed saved/later state.';
              }
              await writeReviewDebugDump(page, screenshot_dir, application_id, evidenceParts);
            }
          }
        }

        if (!clicked) {
          const visibleActions = await getVisibleActionLabels(page);
          stepResult.status = 'failed';
          if (!stepResult.error) {
            stepResult.error = stepKey === 'review_submit'
              ? (REVIEW_SUBMIT_MODE === 'save_and_continue_later'
                ? 'Could not locate Save and Continue Later control in single-session flow. Visible actions: '
                : 'Could not locate Submit control in single-session flow. Visible actions: ') + (visibleActions.join(' | ') || 'none')
              : 'Could not locate Continue/Next control in single-session flow. Visible actions: ' + (visibleActions.join(' | ') || 'none');
          }
          stepResults[stepKey] = stepResult;
          pushClickAudit(stepKey, stepKey === 'review_submit' ? (REVIEW_SUBMIT_MODE === 'save_and_continue_later' ? 'save_and_continue_later' : (REVIEW_SUBMIT_MODE === 'skip' ? 'review_action_skipped' : 'submit')) : 'continue', false, stepResult.error, {
            pre_url: preClickUrl,
            post_url: stepResult.post_continue_url,
          });
          failedStep = stepKey;
          break;
        }

        stepResult.status = 'pass';
        stepResults[stepKey] = stepResult;
        pushClickAudit(stepKey, stepKey === 'review_submit' ? (REVIEW_SUBMIT_MODE === 'save_and_continue_later' ? 'save_and_continue_later' : (REVIEW_SUBMIT_MODE === 'skip' ? 'review_action_skipped' : 'submit')) : 'continue', true, stepKey === 'review_submit' && REVIEW_SUBMIT_MODE === 'skip' ? 'review action intentionally skipped' : 'post-click confirmation passed', {
          pre_url: preClickUrl,
          post_url: stepResult.post_continue_url,
        });

        const ssStep = await takeScreenshot(page, screenshot_dir, application_id, `wd_wizard_auto_${stepKey}`);
        if (ssStep) result.screenshots.push(ssStep);
      }

      const completedSteps = Object.keys(stepResults).filter((k) => stepResults[k].status === 'pass');
      const reviewSubmitPass = stepResults.review_submit && stepResults.review_submit.status === 'pass';
      result.ok = !!reviewSubmitPass;
      result.target_step = 'wizard_auto';
      result.fields_filled = [];
      result.fields_skipped = [];
      result.continue_clicked = completedSteps.length > 0;
      result.submit_blocked = REVIEW_SUBMIT_MODE === 'skip' || REVIEW_SUBMIT_MODE === 'save_and_continue_later';
      result.review_submit_mode = REVIEW_SUBMIT_MODE;
      result.post_continue_url = lastUrl;
      result.page_title = await page.title();
      result.detected_page = await detectPageHeading(page);
      result.page_matched = !!reviewSubmitPass;
      result.needs_manual_review = Object.values(stepResults).some((s) => !!s.needs_manual_review);
      result.step_results = stepResults;
      result.completed_steps = completedSteps;
      result.error = failedStep ? ((stepResults[failedStep] && stepResults[failedStep].error) || `Failed at ${failedStep}`) : '';
      evidenceParts.push(`wizard_auto completed: [${completedSteps.join(', ')}]`);
      attachVisualAuditToResult(result, evidenceParts);
      const visualSummaryAuto = (((result || {}).visual_confirmation || {}).summary || {});
      const visualFieldFailsAuto = Number(visualSummaryAuto.field_checks_failed || 0);
      const visualClickFailsAuto = Number(visualSummaryAuto.click_checks_failed || 0);
      if (visualFieldFailsAuto > 0 || visualClickFailsAuto > 0) {
        result.ok = false;
        if (!result.error) {
          result.error = `Visual verification failed (fields=${visualFieldFailsAuto}, clicks=${visualClickFailsAuto}).`;
        }
        evidenceParts.push(`Strict visual verification failed: fields=${visualFieldFailsAuto}, clicks=${visualClickFailsAuto}`);
      }
      result.evidence = evidenceParts.join(' | ');

      payload.username = '';
      payload.password = '';
      writeResult(result);
      return;
    }

    // ── Step C: Detect which page we're on ─────────────────────────────────
    process.stderr.write(`INFO: [${target_step}] Detecting current wizard page...\n`);

    let detectedPage = '';

    // Workday wizard pages have step indicators and headings.
    // Primary: look for the page heading using data-automation-id.
    const headingSelectors = [
      '[data-automation-id="pageHeaderTitle"]',
      '[data-automation-id="stepTitle"]',
      'h2[data-automation-id]',
      '.current-step-title',
    ];

    for (const sel of headingSelectors) {
      try {
        const heading = page.locator(sel).first();
        const text = await heading.textContent({ timeout: 5000 });
        if (text && text.trim()) {
          detectedPage = text.trim();
          process.stderr.write(`INFO: Detected page heading: "${detectedPage}" via ${sel}\n`);
          break;
        }
      } catch (_) {}
    }

    // Fallback: look for step progress indicators.
    if (!detectedPage) {
      try {
        const stepIndicator = page.locator('[data-automation-id="activeStep"], .active-step, [aria-current="step"]').first();
        const stepText = await stepIndicator.textContent({ timeout: 5000 });
        detectedPage = (stepText || '').trim();
        process.stderr.write(`INFO: Detected page from step indicator: "${detectedPage}"\n`);
      } catch (_) {}
    }

    // Fallback: check the page title.
    if (!detectedPage) {
      detectedPage = await page.title();
      process.stderr.write(`INFO: Using page title as fallback: "${detectedPage}"\n`);
    }

    result.detected_page = detectedPage;
    evidenceParts.push(`Detected page: "${detectedPage}"`);

    // Check if detected page matches the target step.
    const expectedHeadings = STEP_PAGE_HEADINGS[target_step] || [];
    const pageMatchesTarget = expectedHeadings.some(h =>
      detectedPage.toLowerCase().includes(h.toLowerCase())
    );

    result.page_matched = pageMatchesTarget;

    if (pageMatchesTarget) {
      process.stderr.write(`INFO: Page matches target step "${target_step}". Proceeding.\n`);
      evidenceParts.push(`Page matches target step: ${target_step}`);
    } else {
      process.stderr.write(`WARN: Page "${detectedPage}" may not match target "${target_step}". Attempting anyway.\n`);
      evidenceParts.push(`Page may not match target — detected "${detectedPage}", expected one of: ${expectedHeadings.join(', ')}`);

      const aligned = await alignToTargetStepIfPossible(page, target_step, evidenceParts);
      if (aligned) {
        const afterAlign = await inferCurrentWizardStep(page);
        result.detected_page = afterAlign || result.detected_page;
        result.page_matched = afterAlign === target_step;
        process.stderr.write(`INFO: Realigned to target step "${target_step}" before handler.\n`);
      } else if (target_step === 'my_experience') {
        let reached = false;
        for (let hop = 0; hop < 5; hop++) {
          const onExperience = await isLikelyStillOnStep(page, 'my_experience');
          if (onExperience) {
            reached = true;
            evidenceParts.push(`Forced pre-step progression reached my_experience in ${hop} hop(s)`);
            break;
          }

          const inferred = await inferCurrentWizardStep(page);
          if (inferred === 'my_experience') {
            reached = true;
            evidenceParts.push(`Forced pre-step progression inferred my_experience in ${hop} hop(s)`);
            break;
          }

          if (await isMyInformationFormVisible(page)) {
            const bootstrapped = await bootstrapMyExperienceFromMyInformation(page, profile_data, evidenceParts);
            if (bootstrapped) {
              reached = true;
              break;
            }
          }

          let moved = false;
          const targetIdx = AUTO_STEP_ORDER.indexOf('my_experience');
          const inferredIdx = AUTO_STEP_ORDER.indexOf(inferred);
          if (inferredIdx >= 0) {
            moved = inferredIdx > targetIdx
              ? await clickBackButton(page, evidenceParts)
              : await clickContinueButton(page, evidenceParts);
          } else {
            // Unknown inference: probe both directions safely, prefer Back to avoid overshooting.
            moved = await clickBackButton(page, evidenceParts);
            if (!moved) {
              moved = await clickContinueButton(page, evidenceParts);
            }
          }

          if (!moved) {
            break;
          }
          await humanDelay(900, 1600);
        }

        if (reached || await isLikelyStillOnStep(page, 'my_experience')) {
          result.detected_page = 'my_experience';
          result.page_matched = true;
          process.stderr.write('INFO: Forced progression reached my_experience context before handler.\n');
        }
      }
    }

    const ssPage = await takeScreenshot(page, screenshot_dir, application_id, `wd_${target_step}_page`);
    if (ssPage) result.screenshots.push(ssPage);

    if (target_step === 'my_experience') {
      const confirmedContext = await isLikelyStillOnStep(page, 'my_experience');
      const inferredContext = await inferCurrentWizardStep(page);
      const structuralContext = await page.evaluate(() => {
        const visible = (el) => {
          if (!el) return false;
          const r = el.getBoundingClientRect();
          const s = window.getComputedStyle(el);
          return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
        };
        const hasFlow = !!Array.from(document.querySelectorAll('[data-automation-id="applyFlowMyExpPage"], [data-automation-id*="workExperience" i], [id*="workExperience-"]')).find(visible);
        const jobTitleInputs = Array.from(document.querySelectorAll('input[id*="workExperience-"][id*="--jobTitle"], input[name="jobTitle"]')).filter(visible);
        return hasFlow || jobTitleInputs.length > 0;
      }).catch(() => false);

      const gatePass = !!confirmedContext || inferredContext === 'my_experience' || !!structuralContext;
      if (!gatePass) {
        const msg = 'Strict visual gate: my_experience context not confirmed; aborting fill to avoid writing wrong fields.';
        result.error = msg;
        result.ok = false;
        result.continue_clicked = false;
        await writeGenericStepDebugDump(page, screenshot_dir, application_id, evidenceParts, 'my_experience_gate_failed');
        evidenceParts.push(msg);
        attachVisualAuditToResult(result, evidenceParts);
        result.evidence = evidenceParts.join(' | ');
        writeResult(result);
        return;
      }
    }

    // ── Step D: Execute the step-specific handler ──────────────────────────
    process.stderr.write(`INFO: [${target_step}] Running step handler...\n`);
    const handler = STEP_HANDLERS[target_step];
    await handler(page, profile_data, result);

    evidenceParts.push(`Fields filled: [${result.fields_filled.join(', ')}]`);
    if (result.fields_skipped.length > 0) {
      evidenceParts.push(`Fields skipped: [${result.fields_skipped.join(', ')}]`);
    }

    const ssAfterFill = await takeScreenshot(page, screenshot_dir, application_id, `wd_${target_step}_filled`);
    if (ssAfterFill) result.screenshots.push(ssAfterFill);

    // ── Step E: Click Continue / Submit ─────────────────────────────────────
    // For application_questions with needs_manual_review, still try to continue
    // but the step may need manual intervention.
    const preActionUrl = page.url();

    if (target_step === 'review_submit') {
      if (REVIEW_SUBMIT_MODE === 'skip') {
        result.continue_clicked = true;
        result.error = '';
        evidenceParts.push('review_submit_mode=skip: reached review step and skipped review action click');
      } else {
      // On/near review page, click Submit; if not yet on review, advance through Continue.
      process.stderr.write('INFO: Looking for Submit button on review page...\n');
      const submitSelectors = [
        'button[data-automation-id="submitButton"]',
        'button[data-automation-id="bottom-navigation-next-button"]:has-text("Submit")',
        'button:has-text("Submit Application")',
        'button:has-text("Review and Submit")',
        'button:has-text("Submit")',
        '[role="button"][aria-label*="Submit" i]',
        'input[type="submit"]',
      ];
      const continueSelectors = [
        'button[data-automation-id="bottom-navigation-next-button"]',
        '[data-automation-id="bottom-navigation"] button:has-text("Continue")',
        '[data-automation-id="bottom-navigation"] button:has-text("Next")',
        '[data-automation-id="bottom-navigation"] button:has-text("Save and Continue")',
        'button[data-automation-id="nextButton"]',
        'button[aria-label*="Continue" i]',
      ];

      const clickFirstEnabled = async (selectors, label) => {
        for (const sel of selectors) {
          try {
            const btn = page.locator(sel).first();
            await btn.waitFor({ state: 'visible', timeout: 1200 });
            await btn.scrollIntoViewIfNeeded({ timeout: 1000 });
            const disabled = await btn.evaluate((el) => {
              const aria = (el.getAttribute('aria-disabled') || '').toLowerCase();
              return !!(el.disabled || aria === 'true');
            });
            if (disabled) {
              continue;
            }
            await humanDelay(150, 350);
            try {
              await btn.click({ timeout: 2500 });
            } catch (_) {
              await btn.click({ timeout: 2500, force: true });
            }
            process.stderr.write(`INFO: Clicked ${label} via: ${sel}\n`);
            return true;
          } catch (_) {
            continue;
          }
        }
        return false;
      };

      const hasSubmittedConfirmation = async () => {
        try {
          const confirmationLocators = [
            page.locator('text=/thank you for applying/i').first(),
            page.locator('text=/application submitted/i').first(),
            page.locator('[data-automation-id="applicationConfirmation"]').first(),
          ];
          for (const loc of confirmationLocators) {
            try {
              if (await loc.isVisible({ timeout: 1000 })) {
                return true;
              }
            } catch (_) {}
          }
        } catch (_) {}
        const u = page.url().toLowerCase();
        return u.includes('submitted') || u.includes('confirmation');
      };

      await enterApplyFlowIfNeeded(page, evidenceParts);

      for (let attempt = 0; attempt < 10 && !result.continue_clicked; attempt++) {
        try {
          await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
          await humanDelay(200, 500);
        } catch (_) {}

        await handleReviewSubmit(page, profile_data, result);

        const actionClicked = REVIEW_SUBMIT_MODE === 'save_and_continue_later'
          ? await clickSaveAndContinueLaterButton(page, evidenceParts)
          : await clickFirstEnabled(submitSelectors, 'Submit');

        if (actionClicked) {
          result.continue_clicked = true;
          evidenceParts.push(REVIEW_SUBMIT_MODE === 'save_and_continue_later' ? 'Clicked Save and Continue Later' : 'Clicked Submit');
          await humanDelay(3000, 5000);
          if (REVIEW_SUBMIT_MODE === 'save_and_continue_later') {
            if (await isSaveAndContinueLaterConfirmed(page)) {
              evidenceParts.push('Save and Continue Later confirmation detected');
            }
          } else if (await hasSubmittedConfirmation()) {
            evidenceParts.push('Submission confirmation detected');
          }
          break;
        }

        if (REVIEW_SUBMIT_MODE === 'submit' && await hasSubmittedConfirmation()) {
          result.continue_clicked = true;
          evidenceParts.push('Submission confirmation detected without explicit click');
          break;
        }

        const advanced = await clickFirstEnabled(continueSelectors, 'Continue');
        if (!advanced) {
          break;
        }
        evidenceParts.push('Advanced wizard while seeking Submit');
        await humanDelay(1300, 2200);
      }

      if (!result.continue_clicked) {
        let availableActions = [];
        try {
          availableActions = await page.evaluate(() => {
            const isVisible = (el) => {
              const rect = el.getBoundingClientRect();
              const style = window.getComputedStyle(el);
              return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
            };
            const nodes = Array.from(document.querySelectorAll('button, a, [role="button"], input[type="submit"], input[type="button"]'));
            return nodes
              .filter((el) => isVisible(el))
              .map((el) => ((el.textContent || '') + ' ' + (el.getAttribute('value') || '') + ' ' + (el.getAttribute('aria-label') || '')).trim())
              .map((t) => t.replace(/\s+/g, ' ').trim())
              .filter((t) => t.length > 0)
              .slice(0, 20);
          });
        } catch (_) {}
        result.error = REVIEW_SUBMIT_MODE === 'save_and_continue_later'
          ? 'Could not locate a Workday Save and Continue Later action from the current application flow state. Available actions: ' + (availableActions.join(' | ') || 'none')
          : 'Could not locate a Workday Submit action from the current application flow state. Available actions: ' + (availableActions.join(' | ') || 'none');
        if (REVIEW_SUBMIT_MODE === 'submit') {
          try {
            const clicked = await page.evaluate(() => {
              const candidates = Array.from(document.querySelectorAll('button, [role="button"], input[type="submit"], input[type="button"], a'));
              const match = candidates.find((el) => {
                const txt = ((el.textContent || '') + ' ' + (el.getAttribute('value') || '') + ' ' + (el.getAttribute('aria-label') || '')).toLowerCase();
                const disabled = el.disabled || (el.getAttribute('aria-disabled') || '').toLowerCase() === 'true';
                return !disabled && /submit application|review and submit|submit/.test(txt);
              });
              if (match) {
                match.scrollIntoView({ behavior: 'instant', block: 'center' });
                match.click();
                return true;
              }
              return false;
            });
            if (clicked) {
              result.continue_clicked = true;
              process.stderr.write('INFO: Clicked Submit via DOM evaluate fallback.\n');
              evidenceParts.push('Clicked Submit (DOM fallback)');
            }
          } catch (_) {}
        }
      }
      }
    } else {
      // Standard Continue/Next button.
      process.stderr.write('INFO: Looking for Continue/Next button...\n');
      await humanDelay(500, 1000);

      result.continue_clicked = await clickContinueButton(page, evidenceParts);
      if (result.continue_clicked) {
        process.stderr.write('INFO: Clicked Continue via robust selector flow.\n');
        evidenceParts.push('Clicked Continue');
      } else {
        const availableActions = await getVisibleActionLabels(page);
        if (availableActions.length > 0) {
          evidenceParts.push('Visible actions at continue-search: ' + availableActions.join(' | '));
        }
      }
    }

    if (!result.continue_clicked) {
      process.stderr.write('WARN: Could not find Continue/Submit button.\n');
      evidenceParts.push('Continue/Submit button NOT found');
    }

    await humanDelay(3000, 5000);

    // ── Step F: Capture post-action state ──────────────────────────────────
    const postUrl = page.url();
    const postTitle = await page.title();
    result.post_continue_url = postUrl;
    result.page_title = postTitle;
    evidenceParts.push(`Post-action URL: ${postUrl}`);
    evidenceParts.push(`Post-action title: "${postTitle}"`);

    const ssPost = await takeScreenshot(page, screenshot_dir, application_id, `wd_${target_step}_done`);
    if (ssPost) result.screenshots.push(ssPost);

    // Check for validation errors on the page.
    try {
      const validationErrors = page.locator('[data-automation-id="errorMessage"], [data-automation-id="inlineError"], .error-message-text');
      const errorCount = await validationErrors.count();
      if (errorCount > 0) {
        const firstError = await validationErrors.first().textContent({ timeout: 3000 });
        evidenceParts.push(`Validation error(s): ${errorCount}. First: "${(firstError || '').trim()}"`);
        process.stderr.write(`WARN: Found ${errorCount} validation error(s) on page.\n`);
        // If there are errors after clicking Continue, we didn't actually advance.
        // Check if URL changed.
        if (postUrl === apply_url || postUrl === page.url()) {
          result.continue_clicked = false;
          evidenceParts.push('Page did not advance (validation errors)');
        }
      }
    } catch (_) {}

    if (result.continue_clicked && target_step === 'review_submit') {
      if (REVIEW_SUBMIT_MODE === 'submit') {
        const confirmedSubmit = await isSubmissionConfirmed(page);
        if (!confirmedSubmit) {
          result.continue_clicked = false;
          const msg = 'Submit click did not reach a confirmed submitted state.';
          result.error = result.error || msg;
          evidenceParts.push(msg);
        }
      } else if (REVIEW_SUBMIT_MODE === 'save_and_continue_later') {
        const confirmedSave = await isSaveAndContinueLaterConfirmed(page);
        if (!confirmedSave) {
          result.continue_clicked = false;
          const msg = 'Save and Continue Later click did not reach a confirmed saved/later state.';
          result.error = result.error || msg;
          evidenceParts.push(msg);
        }
      }
      pushClickAudit(target_step, REVIEW_SUBMIT_MODE === 'save_and_continue_later' ? 'save_and_continue_later' : (REVIEW_SUBMIT_MODE === 'skip' ? 'review_action_skipped' : 'submit'), !!result.continue_clicked, result.error || (REVIEW_SUBMIT_MODE === 'save_and_continue_later' ? 'single-step save-and-later confirmed' : 'single-step submit confirmed'), {
        pre_url: preActionUrl,
        post_url: postUrl,
      });
    }

    if (result.continue_clicked && target_step !== 'review_submit') {
      const sameUrl = preActionUrl === postUrl;
      const stillOnStep = sameUrl ? await isLikelyStillOnStep(page, target_step) : false;
      if (sameUrl && stillOnStep) {
        const resolvedFields = await resolveValidationErrorsFromProfile(page, profile_data, evidenceParts, resume_pdf_path);
        if (Array.isArray(resolvedFields) && resolvedFields.length > 0) {
          mergeUniqueTags(result.fields_filled, resolvedFields);
          await humanDelay(400, 900);

          const retryClicked = await clickContinueButton(page, evidenceParts);
          if (retryClicked) {
            await humanDelay(1200, 2200);
            const retryUrl = page.url();
            const retryStillOnStep = retryUrl === preActionUrl ? await isLikelyStillOnStep(page, target_step) : false;
            if (!retryStillOnStep) {
              result.continue_clicked = true;
              result.post_continue_url = retryUrl;
              result.page_title = await page.title();
              evidenceParts.push(`Recovered continue progression from ${target_step} after resolving required fields.`);
            } else {
              result.continue_clicked = false;
              const msg = `Click did not visibly advance from ${target_step}.`;
              result.error = result.error || msg;
              evidenceParts.push(msg);
            }
          } else {
            result.continue_clicked = false;
            const msg = `Click did not visibly advance from ${target_step}.`;
            result.error = result.error || msg;
            evidenceParts.push(msg);
          }
        } else {
          result.continue_clicked = false;
          const msg = `Click did not visibly advance from ${target_step}.`;
          result.error = result.error || msg;
          evidenceParts.push(msg);
        }
      }
      pushClickAudit(target_step, 'continue', !!result.continue_clicked, result.error || 'single-step continue confirmed', {
        pre_url: preActionUrl,
        post_url: postUrl,
      });
    }

    // ── Final result ───────────────────────────────────────────────────────
    result.ok = result.continue_clicked;
    result.submit_blocked = REVIEW_SUBMIT_MODE === 'skip' || REVIEW_SUBMIT_MODE === 'save_and_continue_later';
    result.review_submit_mode = REVIEW_SUBMIT_MODE;
    attachVisualAuditToResult(result, evidenceParts);
    const visualSummarySingle = (((result || {}).visual_confirmation || {}).summary || {});
    const visualFieldFailsSingle = Number(visualSummarySingle.field_checks_failed || 0);
    const visualClickFailsSingle = Number(visualSummarySingle.click_checks_failed || 0);
    if (visualFieldFailsSingle > 0 || visualClickFailsSingle > 0) {
      result.ok = false;
      if (!result.error) {
        result.error = `Visual verification failed (fields=${visualFieldFailsSingle}, clicks=${visualClickFailsSingle}).`;
      }
      evidenceParts.push(`Strict visual verification failed: fields=${visualFieldFailsSingle}, clicks=${visualClickFailsSingle}`);
    }
    result.evidence = evidenceParts.join(' | ');

    // Zero credentials.
    payload.username = '';
    payload.password = '';

    writeResult(result);
  } catch (e) {
    const failedResult = {
      ok: false,
      target_step: target_step,
      detected_page: '',
      page_matched: false,
      fields_filled: [],
      fields_skipped: [],
      continue_clicked: false,
      post_continue_url: '',
      page_title: '',
      needs_manual_review: false,
      evidence: '',
      screenshots: [],
      error: 'Unhandled error: ' + e.message,
    };
    attachVisualAuditToResult(failedResult, []);
    writeResult(failedResult);
  } finally {
    if (browser) {
      try { await browser.close(); } catch (_) {}
    }
  }
}

run();
