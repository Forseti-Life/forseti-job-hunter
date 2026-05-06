/**
 * resume-upload-workday.js — Playwright stealth script for Workday resume upload.
 *
 * Flow:
 *   1. Read payload from temp file (credentials, apply_url, resume path).
 *   2. Log in to Workday (same flow as verify-account.js).
 *   3. Navigate to the apply URL.
 *   4. Click "Autofill with resume" if present.
 *   5. Verify authentication — check email in utility button bar.
 *   6. Upload the tailored resume PDF via the file-upload drop zone.
 *   7. Wait for "Successfully Uploaded!" confirmation.
 *   8. Click "Continue" button.
 *   9. Take checkpoint screenshot and return result.
 *
 * Usage:
 *   node resume-upload-workday.js \
 *     --payload-file=/tmp/jh_ru_xyz.json \
 *     --output-file=/tmp/jh_ru_out.json \
 *     [--timeout=90] \
 *     [--executable-path=/usr/bin/google-chrome]
 *
 * Payload file format:
 *   {
 *     "username": "user@example.com",
 *     "password": "secretpass",
 *     "apply_url": "https://jj.wd5.myworkdayjobs.com/en-US/JJ/job/.../apply?...",
 *     "ats_platform": "myworkdayjobs",
 *     "expected_email": "user@example.com",
 *     "resume_pdf_path": "/abs/path/to/tailored-resume.pdf",
 *     "screenshot_dir": "/var/private/forseti/job_hunter/screenshots",
 *     "application_id": 1
 *   }
 *
 * The payload file is deleted immediately after reading.
 *
 * Output JSON:
 *   {
 *     "ok": true/false,
 *     "auth_verified": true/false,
 *     "verified_email": "...",
 *     "resume_uploaded": true/false,
 *     "upload_filename": "...",
 *     "continue_clicked": true/false,
 *     "post_continue_url": "...",
 *     "page_title": "...",
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
const TIMEOUT      = parseInt(args['timeout'] || '90', 10) * 1000;
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
    auth_verified: false,
    verified_email: '',
    resume_uploaded: false,
    upload_filename: '',
    continue_clicked: false,
    post_continue_url: '',
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
  apply_url = '',
  ats_platform = '',
  expected_email = '',
  resume_pdf_path = '',
  screenshot_dir = '',
  application_id = 0,
} = payload;

if (!username || !password || !apply_url) {
  fail('Payload must include username, password, and apply_url.');
}

if (!resume_pdf_path || !fs.existsSync(resume_pdf_path)) {
  fail('Resume PDF not found: ' + resume_pdf_path);
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
    userHome:  `${base}/${locale}/${siteId}/userHome`,
    signInUrl: `${base}/${locale}/${siteId}/login?redirect=%2F${locale}%2F${siteId}%2FuserHome`,
  };
}

// ── Main flow ──────────────────────────────────────────────────────────────────

async function run() {
  const result = {
    ok: false,
    auth_verified: false,
    verified_email: '',
    resume_uploaded: false,
    upload_filename: '',
    continue_clicked: false,
    post_continue_url: '',
    page_title: '',
    evidence: '',
    screenshots: [],
    error: '',
  };

  let browser;
  try {
    const launchOpts = { headless: true };
    if (EXEC_PATH) {
      launchOpts.executablePath = EXEC_PATH;
    }

    const launched = await launchBrowser(launchOpts);
    browser = launched.browser;
    const page = launched.page;
    const urls = parseWorkdayUrls(apply_url);

    const evidenceParts = [];

    // ── Step 1: Log in to Workday ──────────────────────────────────────────
    process.stderr.write('INFO: Logging in to Workday...\n');
    process.stderr.write(`INFO: Login URL: ${urls.signInUrl}\n`);

    await page.goto(urls.signInUrl, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
    await humanDelay(1500, 3000);

    // Workday sign-in form selectors.
    const emailSelector    = '[data-automation-id="email"]';
    const passwordSelector = '[data-automation-id="password"]';
    const signInButton     = '[data-automation-id="click_filter"]';

    // Some Workday sites require clicking a "Sign In" link first.
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
      const altEmail = 'input[type="email"], input[name="email"], input[autocomplete="username"]';
      try {
        await page.waitForSelector(altEmail, { timeout: 5000 });
      } catch (e2) {
        result.error = 'Login form not found. Could not locate email input field.';
        writeResult(result);
        return;
      }
    }

    // Fill credentials.
    process.stderr.write('INFO: Entering credentials...\n');
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
    process.stderr.write('INFO: Submitting login form...\n');
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
        evidenceParts.push('Login error: ' + errorText.trim());
        result.evidence = evidenceParts.join(' | ');
        writeResult(result);
        return;
      }
    } catch (_) {
      // No error banner — good.
    }

    evidenceParts.push('Login submitted successfully');

    // Take post-login screenshot.
    const ssLogin = await takeScreenshot(page, screenshot_dir, application_id, 'resume_upload_post_login');
    if (ssLogin) result.screenshots.push(ssLogin);

    // ── Step 2: Navigate to the apply URL ──────────────────────────────────
    process.stderr.write(`INFO: Navigating to apply URL: ${apply_url}\n`);
    await page.goto(apply_url, { waitUntil: 'domcontentloaded', timeout: TIMEOUT });
    await humanDelay(2000, 4000);

    const ssApplyPage = await takeScreenshot(page, screenshot_dir, application_id, 'resume_upload_apply_page');
    if (ssApplyPage) result.screenshots.push(ssApplyPage);

    // ── Step 3: Click "Autofill with resume" if present ────────────────────
    process.stderr.write('INFO: Looking for "Autofill with resume" button...\n');
    const autofillSelectors = [
      'button:has-text("Autofill with resume")',
      'button:has-text("Autofill with Resume")',
      'button:has-text("Autofill")',
      '[data-automation-id="autofillWithResume"]',
      'button:has-text("Upload Resume")',
    ];

    let autofillClicked = false;
    for (const sel of autofillSelectors) {
      try {
        const btn = page.locator(sel).first();
        await btn.waitFor({ state: 'visible', timeout: 5000 });
        await btn.click({ timeout: 3000 });
        autofillClicked = true;
        process.stderr.write(`INFO: Clicked autofill button via: ${sel}\n`);
        evidenceParts.push('Clicked "Autofill with resume" button');
        break;
      } catch (_) {
        continue;
      }
    }

    if (!autofillClicked) {
      process.stderr.write('WARN: "Autofill with resume" button not found — proceeding to look for upload zone directly.\n');
      evidenceParts.push('"Autofill with resume" button not found — checking for upload zone');
    }

    await humanDelay(1500, 3000);

    // ── Step 4: Verify authentication — check email in utility bar ─────────
    process.stderr.write('INFO: Verifying authentication in utility bar...\n');
    const accountMenuSelector = '[data-automation-id="utilityButtonAccountTasksMenu"] [data-automation-id="utilityMenuButton"], #accountSettingsButton';

    let verifiedEmail = '';
    try {
      await page.waitForSelector(accountMenuSelector, { timeout: 10000 });
      const allButtons = page.locator(accountMenuSelector);
      const count = await allButtons.count();

      for (let i = 0; i < count; i++) {
        const btnText = await allButtons.nth(i).textContent({ timeout: 3000 });
        const trimmed = (btnText || '').trim();
        const emailMatch = trimmed.match(/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/);
        if (emailMatch) {
          verifiedEmail = emailMatch[0];
          break;
        }
      }

      if (!verifiedEmail) {
        // Try broader search — the account button text might contain the email.
        const utilityBar = page.locator('[data-automation-id="utilityButtonBar"]');
        const barText = await utilityBar.textContent({ timeout: 5000 });
        const barMatch = (barText || '').match(/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/);
        if (barMatch) {
          verifiedEmail = barMatch[0];
        }
      }
    } catch (e) {
      process.stderr.write('WARN: Could not find utility bar account menu: ' + e.message + '\n');
    }

    // Fallback: check page body for expected email.
    if (!verifiedEmail && expected_email) {
      try {
        const bodyText = await page.locator('body').textContent({ timeout: 5000 });
        if (bodyText.includes(expected_email)) {
          verifiedEmail = expected_email;
        }
      } catch (_) {}
    }

    result.auth_verified = !!verifiedEmail;
    result.verified_email = verifiedEmail;

    if (verifiedEmail) {
      process.stderr.write(`INFO: Auth verified — found email: ${verifiedEmail}\n`);
      evidenceParts.push(`Auth verified: ${verifiedEmail} found in utility bar`);
    } else {
      process.stderr.write('WARN: Could not verify authentication — email not found in utility bar.\n');
      evidenceParts.push('Auth NOT verified — email not found in utility bar');
      // Continue anyway — user may still be logged in.
    }

    if (expected_email && verifiedEmail && verifiedEmail.toLowerCase() !== expected_email.toLowerCase()) {
      evidenceParts.push(`WARNING: Verified email "${verifiedEmail}" does not match expected "${expected_email}"`);
    }

    // ── Step 5: Upload resume PDF ──────────────────────────────────────────
    process.stderr.write(`INFO: Uploading resume: ${resume_pdf_path}\n`);

    // Workday uses a hidden <input type="file"> within the drop zone.
    // We need to find and set the file on it.
    const fileInputSelectors = [
      '[data-automation-id="file-upload-drop-zone"] input[type="file"]',
      'input[type="file"][data-automation-id="file-upload-input"]',
      'input[type="file"]',
    ];

    let fileInputFound = false;
    for (const sel of fileInputSelectors) {
      try {
        const fileInput = page.locator(sel).first();
        // File inputs are often hidden — use setInputFiles directly.
        await fileInput.setInputFiles(resume_pdf_path, { timeout: 10000 });
        fileInputFound = true;
        process.stderr.write(`INFO: File set via: ${sel}\n`);
        break;
      } catch (e) {
        process.stderr.write(`WARN: File input selector "${sel}" failed: ${e.message}\n`);
        continue;
      }
    }

    if (!fileInputFound) {
      // Fallback: try clicking the "Select file" button to trigger dialog,
      // then intercept the file chooser.
      process.stderr.write('INFO: Trying filechooser approach...\n');
      try {
        const [fileChooser] = await Promise.all([
          page.waitForEvent('filechooser', { timeout: 10000 }),
          page.click('[data-automation-id="select-files"], button:has-text("Select file")', { timeout: 5000 }),
        ]);
        await fileChooser.setFiles(resume_pdf_path);
        fileInputFound = true;
        process.stderr.write('INFO: File set via filechooser event.\n');
      } catch (e) {
        process.stderr.write('ERROR: All file upload methods failed: ' + e.message + '\n');
        result.error = 'Could not upload resume. File input not found.';
        result.evidence = evidenceParts.join(' | ');
        writeResult(result);
        return;
      }
    }

    const resumeFilename = path.basename(resume_pdf_path);
    result.upload_filename = resumeFilename;
    evidenceParts.push(`Resume file set: ${resumeFilename}`);

    // ── Step 6: Wait for upload confirmation ───────────────────────────────
    process.stderr.write('INFO: Waiting for upload confirmation...\n');
    await humanDelay(2000, 4000);

    let uploadConfirmed = false;
    const uploadSuccessSelectors = [
      '[data-automation-id="file-upload-successful"]',
      '[data-automation-id="ariaLiveMessage"]:has-text("successfully uploaded")',
      'text=Successfully Uploaded',
      'text=successfully uploaded',
    ];

    // Wait up to 30s for the upload to complete.
    for (let attempt = 0; attempt < 10; attempt++) {
      for (const sel of uploadSuccessSelectors) {
        try {
          const el = page.locator(sel).first();
          if (await el.isVisible({ timeout: 1000 })) {
            uploadConfirmed = true;
            process.stderr.write(`INFO: Upload confirmed via: ${sel}\n`);
            break;
          }
        } catch (_) {}
      }

      if (uploadConfirmed) break;

      // Also check for the uploaded file item with the filename.
      try {
        const fileItem = page.locator(`[data-automation-id="file-upload-item-name"]:has-text("${resumeFilename.replace('.pdf', '')}")`);
        if (await fileItem.isVisible({ timeout: 1000 })) {
          uploadConfirmed = true;
          process.stderr.write('INFO: Upload confirmed — file item with matching name visible.\n');
          break;
        }
      } catch (_) {}

      await sleep(2000);
    }

    result.resume_uploaded = uploadConfirmed;

    if (uploadConfirmed) {
      evidenceParts.push('Resume upload confirmed (Successfully Uploaded!)');
    } else {
      process.stderr.write('WARN: Upload confirmation not detected within timeout.\n');
      evidenceParts.push('Upload confirmation NOT detected (may still have succeeded)');
    }

    const ssUpload = await takeScreenshot(page, screenshot_dir, application_id, 'resume_upload_confirmed');
    if (ssUpload) result.screenshots.push(ssUpload);

    // ── Step 7: Click "Continue" ───────────────────────────────────────────
    process.stderr.write('INFO: Looking for "Continue" button...\n');
    await humanDelay(1000, 2000);

    const continueSelectors = [
      'button[data-automation-id="bottom-navigation-next-button"]',
      'button:has-text("Continue")',
      'button:has-text("Next")',
      'button:has-text("Submit")',
      '[data-automation-id="nextButton"]',
    ];

    let continueClicked = false;
    for (const sel of continueSelectors) {
      try {
        const btn = page.locator(sel).first();
        await btn.waitFor({ state: 'visible', timeout: 5000 });
        await humanDelay(500, 1000);
        await btn.click({ timeout: 5000 });
        continueClicked = true;
        process.stderr.write(`INFO: Clicked Continue via: ${sel}\n`);
        break;
      } catch (_) {
        continue;
      }
    }

    result.continue_clicked = continueClicked;

    if (continueClicked) {
      evidenceParts.push('Clicked "Continue" button');
      await humanDelay(3000, 5000);
    } else {
      process.stderr.write('WARN: "Continue" button not found.\n');
      evidenceParts.push('"Continue" button not found');
    }

    // ── Step 8: Take checkpoint screenshot ─────────────────────────────────
    const postContinueUrl = page.url();
    const postContinueTitle = await page.title();
    result.post_continue_url = postContinueUrl;
    result.page_title = postContinueTitle;
    evidenceParts.push(`Post-continue URL: ${postContinueUrl}`);
    evidenceParts.push(`Page title: "${postContinueTitle}"`);

    const ssCheckpoint = await takeScreenshot(page, screenshot_dir, application_id, 'resume_upload_checkpoint');
    if (ssCheckpoint) result.screenshots.push(ssCheckpoint);

    // ── Final result ───────────────────────────────────────────────────────
    result.ok = result.auth_verified && result.resume_uploaded && result.continue_clicked;
    result.evidence = evidenceParts.join(' | ');

    // Zero credentials before exit.
    payload.username = '';
    payload.password = '';

    writeResult(result);
  } catch (e) {
    writeResult({
      ok: false,
      auth_verified: false,
      verified_email: '',
      resume_uploaded: false,
      upload_filename: '',
      continue_clicked: false,
      post_continue_url: '',
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
}

run();
