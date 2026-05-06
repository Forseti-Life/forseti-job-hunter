/**
 * verify-account.js — Playwright stealth script to verify ATS account authentication.
 *
 * Flow:
 *   1. Read credentials from a payload file (JSON with username, password, auth_url)
 *   2. Navigate to the ATS login page
 *   3. Enter credentials, submit login form
 *   4. Navigate to the user home page
 *   5. Confirm the logged-in user identity (e.g. email in top-right menu)
 *
 * Currently supports:
 *   - Workday (*.myworkdayjobs.com)
 *
 * Usage:
 *   node verify-account.js \
 *     --payload-file=/tmp/jh_verify_xyz.json \
 *     --output-file=/tmp/jh_verify_out.json \
 *     [--timeout=60] \
 *     [--executable-path=/usr/bin/google-chrome]
 *
 * Payload file format:
 *   {
 *     "username": "user@example.com",
 *     "password": "secretpass",
 *     "auth_url": "https://jj.wd5.myworkdayjobs.com/en-US/JJ/job/.../apply",
 *     "ats_platform": "myworkdayjobs",
 *     "expected_email": "user@example.com"
 *   }
 *
 * The payload file is deleted immediately after reading.
 *
 * Output JSON:
 *   {
 *     "ok": true/false,
 *     "verified": true/false,
 *     "verified_email": "user@example.com",
 *     "user_home_url": "https://...",
 *     "page_title": "...",
 *     "evidence": "Found email in account menu: ...",
 *     "screenshots": [],
 *     "error": ""
 *   }
 */

'use strict';

const fs = require('fs');
const path = require('path');
const minimist = require('minimist');
const { launchBrowser, humanType, humanDelay, sleep } = require('./utils/stealth');

const args = minimist(process.argv.slice(2));

const PAYLOAD_FILE = args['payload-file'] || '';
const OUTPUT_FILE  = args['output-file'] || '';
const TIMEOUT      = parseInt(args['timeout'] || '60', 10) * 1000;
const EXEC_PATH    = args['executable-path'] || '';

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
    verified: false,
    verified_email: '',
    user_home_url: '',
    page_title: '',
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
  // Immediately delete — credentials must not persist on disk.
  fs.unlinkSync(PAYLOAD_FILE);
} catch (e) {
  try { fs.unlinkSync(PAYLOAD_FILE); } catch (_) {}
  fail('Failed to parse payload file: ' + e.message);
}

const {
  username = '',
  password = '',
  auth_url = '',
  ats_platform = '',
  expected_email = '',
} = payload;

if (!username || !password || !auth_url) {
  fail('Payload must include username, password, and auth_url.');
}

// ── Platform-specific verification ─────────────────────────────────────────────

/**
 * Derive the Workday base URL and login/home URLs from the auth_url.
 *
 * auth_url examples:
 *   https://jj.wd5.myworkdayjobs.com/en-US/JJ/job/.../apply
 *   https://boehringer.wd3.myworkdayjobs.com/en-US/BI/job/.../apply
 *
 * Pattern: https://{tenant}.{dc}.myworkdayjobs.com/{locale}/{site}/...
 * Login:   https://{tenant}.{dc}.myworkdayjobs.com/{locale}/{site}/login
 * Home:    https://{tenant}.{dc}.myworkdayjobs.com/{locale}/{site}/userHome
 */
function parseWorkdayUrls(authUrl) {
  const url = new URL(authUrl);
  const parts = url.pathname.split('/').filter(Boolean);

  // Typical path: ["en-US", "JJ", "job", ... , "apply"]
  // We want the first two segments: locale + siteId.
  const locale = parts[0] || 'en-US';
  const siteId = parts[1] || '';
  const base   = `${url.protocol}//${url.host}`;

  return {
    base,
    loginUrl:  `${base}/${locale}/${siteId}/login`,
    userHome:  `${base}/${locale}/${siteId}/userHome`,
    signInUrl: `${base}/${locale}/${siteId}/login?redirect=%2F${locale}%2F${siteId}%2FuserHome`,
  };
}

async function verifyWorkday(browser, page) {
  const urls = parseWorkdayUrls(auth_url);
  const screenshots = [];

  process.stderr.write(`INFO: Workday login URL: ${urls.loginUrl}\n`);
  process.stderr.write(`INFO: Workday userHome:  ${urls.userHome}\n`);

  // ── Navigate to login page ──────────────────────────────────────────────
  await page.goto(urls.signInUrl, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
  await humanDelay(1500, 3000);

  // Wait for the Sign In form to appear.
  // Workday uses data-automation-id attributes.
  const emailSelector    = '[data-automation-id="email"]';
  const passwordSelector = '[data-automation-id="password"]';
  const signInButton     = '[data-automation-id="click_filter"]';

  // Some Workday sites show a "Sign In" link first, then the form.
  // Try clicking a Sign In link if the form isn't visible yet.
  const signInLink = page.locator('a[data-automation-id="signInLink"], a:has-text("Sign In"), button:has-text("Sign In")');
  try {
    await signInLink.first().click({ timeout: 5000 });
    await humanDelay(1000, 2000);
  } catch (_) {
    // Form might already be visible.
  }

  // Wait for email field.
  try {
    await page.waitForSelector(emailSelector, { timeout: 15000 });
  } catch (_) {
    // Try alternative selectors
    const altEmail = 'input[type="email"], input[name="email"], input[autocomplete="username"]';
    try {
      await page.waitForSelector(altEmail, { timeout: 5000 });
    } catch (e2) {
      return {
        ok: false,
        verified: false,
        verified_email: '',
        user_home_url: urls.userHome,
        page_title: await page.title(),
        evidence: 'Could not find email input field on login page.',
        screenshots,
        error: 'Login form not found. Page may require different auth flow.',
      };
    }
  }

  // ── Fill credentials ────────────────────────────────────────────────────
  process.stderr.write('INFO: Entering credentials...\n');

  // Click and type email.
  try {
    await humanType(page, emailSelector, username);
  } catch (_) {
    // Try fallback selector.
    await humanType(page, 'input[type="email"], input[name="email"]', username);
  }
  await humanDelay(300, 800);

  // Click and type password.
  try {
    await humanType(page, passwordSelector, password);
  } catch (_) {
    await humanType(page, 'input[type="password"]', password);
  }
  await humanDelay(500, 1000);

  // ── Submit login form ───────────────────────────────────────────────────
  process.stderr.write('INFO: Submitting login form...\n');
  try {
    await page.click(signInButton, { timeout: 5000 });
  } catch (_) {
    // Fallback: try pressing Enter or clicking a visible submit button.
    try {
      await page.click('button[type="submit"], [data-automation-id="signInSubmitButton"]', { timeout: 3000 });
    } catch (_2) {
      await page.keyboard.press('Enter');
    }
  }

  // ── Wait for navigation to complete ─────────────────────────────────────
  await humanDelay(3000, 5000);

  // Check for login errors.
  const errorBanner = page.locator('[data-automation-id="errorMessage"], .error-message, [role="alert"]');
  try {
    const errorText = await errorBanner.first().textContent({ timeout: 3000 });
    if (errorText && errorText.trim().length > 0) {
      return {
        ok: false,
        verified: false,
        verified_email: '',
        user_home_url: urls.userHome,
        page_title: await page.title(),
        evidence: 'Login error: ' + errorText.trim(),
        screenshots,
        error: 'Login failed. Error from ATS: ' + errorText.trim(),
      };
    }
  } catch (_) {
    // No error banner — good.
  }

  // ── Navigate to userHome ────────────────────────────────────────────────
  process.stderr.write('INFO: Navigating to userHome...\n');
  try {
    await page.goto(urls.userHome, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
  } catch (e) {
    // If navigation failed, we might already be on the right page.
    process.stderr.write('WARN: userHome navigation issue: ' + e.message + '\n');
  }
  await humanDelay(2000, 4000);

  // ── Look for account menu with email ────────────────────────────────────
  process.stderr.write('INFO: Looking for account identity in upper-right menu...\n');

  // Workday puts the email in the utility button:
  // data-automation-id="utilityButtonAccountTasksMenu"
  //   → button[data-automation-id="utilityMenuButton"]
  //     → span with the email text.
  const accountMenuSelector = '[data-automation-id="utilityMenuButton"], #accountSettingsButton';
  let verifiedEmail = '';
  let evidence = '';

  try {
    await page.waitForSelector(accountMenuSelector, { timeout: 15000 });
    const menuText = await page.locator(accountMenuSelector).first().textContent({ timeout: 5000 });
    const trimmed = (menuText || '').trim();
    process.stderr.write('INFO: Account menu text: "' + trimmed + '"\n');

    if (trimmed) {
      // The text should contain the user's email.
      // E.g. "keith.aumiller@stlouisintegration.com"
      const emailMatch = trimmed.match(/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/);
      if (emailMatch) {
        verifiedEmail = emailMatch[0];
        evidence = `Found email in account menu: "${verifiedEmail}". Full menu text: "${trimmed}".`;
      } else {
        // No email pattern, but there's text — could be a name.
        verifiedEmail = trimmed;
        evidence = `Account menu found with text: "${trimmed}". No email pattern detected.`;
      }
    }
  } catch (e) {
    process.stderr.write('WARN: Could not find account menu button: ' + e.message + '\n');
  }

  // ── Fallback: check page for any identity indicators ────────────────────
  if (!verifiedEmail) {
    try {
      const bodyText = await page.locator('body').textContent({ timeout: 5000 });
      if (expected_email && bodyText.includes(expected_email)) {
        verifiedEmail = expected_email;
        evidence = `Expected email "${expected_email}" found in page body text.`;
      }
    } catch (_) {}
  }

  // ── Fallback: look for common profile indicators ────────────────────────
  if (!verifiedEmail) {
    const profileSelectors = [
      '[data-automation-id="userProfileName"]',
      '[data-automation-id="userName"]',
      '.user-name',
      '.profile-name',
    ];
    for (const sel of profileSelectors) {
      try {
        const txt = await page.locator(sel).first().textContent({ timeout: 2000 });
        if (txt && txt.trim()) {
          verifiedEmail = txt.trim();
          evidence = `Profile element ${sel} found with text: "${verifiedEmail}".`;
          break;
        }
      } catch (_) {}
    }
  }

  const currentUrl = page.url();
  const pageTitle  = await page.title();
  const isOnUserHome = currentUrl.includes('userHome');
  const verified = !!verifiedEmail && isOnUserHome;

  if (!evidence) {
    evidence = `Landed on: ${currentUrl}. Title: "${pageTitle}". ` +
      (isOnUserHome ? 'On userHome but could not extract identity.' : 'Did not reach userHome.');
  }

  return {
    ok: true,
    verified,
    verified_email: verifiedEmail,
    user_home_url: currentUrl,
    page_title: pageTitle,
    evidence,
    screenshots,
    error: verified ? '' : 'Authentication could not be fully confirmed.',
  };
}

// ── Generic fallback ───────────────────────────────────────────────────────────

async function verifyGeneric(browser, page) {
  // For non-Workday sites, attempt a basic login flow.
  await page.goto(auth_url, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
  await humanDelay(1500, 3000);

  // Try to find and fill email/username field.
  const emailInput = page.locator('input[type="email"], input[name="email"], input[name="username"], input[autocomplete="username"]');
  try {
    await emailInput.first().fill(username, { timeout: 5000 });
  } catch (_) {
    return {
      ok: false,
      verified: false,
      verified_email: '',
      user_home_url: page.url(),
      page_title: await page.title(),
      evidence: 'Could not find login form on page.',
      screenshots: [],
      error: 'Generic login form not detected. Manual verification required.',
    };
  }

  await humanDelay(300, 600);

  // Fill password.
  try {
    await page.locator('input[type="password"]').first().fill(password, { timeout: 3000 });
  } catch (_) {}

  await humanDelay(300, 600);

  // Submit.
  try {
    await page.locator('button[type="submit"], input[type="submit"]').first().click({ timeout: 3000 });
  } catch (_) {
    await page.keyboard.press('Enter');
  }

  await humanDelay(3000, 5000);

  const currentUrl = page.url();
  const pageTitle = await page.title();

  // Check if we appear to be logged in (no longer on login page).
  const onLogin = currentUrl.includes('login') || currentUrl.includes('signin');
  const evidence = `Post-login URL: ${currentUrl}. Title: "${pageTitle}".`;

  return {
    ok: true,
    verified: !onLogin,
    verified_email: !onLogin ? username : '',
    user_home_url: currentUrl,
    page_title: pageTitle,
    evidence,
    screenshots: [],
    error: onLogin ? 'Still on login page after submission. Credentials may be incorrect.' : '',
  };
}

// ── Main ───────────────────────────────────────────────────────────────────────

(async () => {
  let browser;
  try {
    const launchOpts = { headless: true };
    if (EXEC_PATH) {
      launchOpts.executablePath = EXEC_PATH;
    }

    const launched = await launchBrowser(launchOpts);
    browser = launched.browser;
    const page = launched.page;

    let result;
    if (ats_platform === 'myworkdayjobs' || auth_url.includes('myworkdayjobs.com')) {
      result = await verifyWorkday(browser, page);
    } else {
      result = await verifyGeneric(browser, page);
    }

    writeResult(result);
  } catch (e) {
    writeResult({
      ok: false,
      verified: false,
      verified_email: '',
      user_home_url: '',
      page_title: '',
      evidence: '',
      screenshots: [],
      error: 'Unhandled error: ' + e.message,
    });
  } finally {
    if (browser) {
      try { await browser.close(); } catch (_) {}
    }
  }
})();
