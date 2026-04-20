/**
 * Lever ATS — Playwright handler
 *
 * Handles: jobs.lever.co apply forms
 * Track: A (no login required)
 * Priority: 2
 *
 * Lever has a clean minimal form: name, email, phone, resume, cover letter, LinkedIn.
 */

'use strict';

const { launchBrowser, humanDelay, takeScreenshot, extractConfirmationNumber } = require('../utils/stealth');

async function apply(payload, buildResult) {
  const { apply_url, personal_info, resume_pdf_path, cover_letter, screenshot_dir, application_id, options = {} } = payload;

  if (!resume_pdf_path || !require('fs').existsSync(resume_pdf_path)) {
    return buildResult({
      outcome: 'manual_required', reason: 'no_resume_pdf',
      error: 'Resume PDF not found at: ' + resume_pdf_path,
      instructions: 'Upload your tailored resume PDF and try again.',
    });
  }

  const { browser, page } = await launchBrowser({ headless: options.headless !== false });
  const fields_filled = [], fields_skipped = [];
  let screenshot_pre = null, screenshot_post = null;

  try {
    await page.goto(apply_url, { waitUntil: 'networkidle', timeout: 30000 });
    await humanDelay(800, 1500);

    // CAPTCHA check
    if (await page.$('iframe[src*="recaptcha"], .g-recaptcha, iframe[src*="hcaptcha"]')) {
      return buildResult({ outcome: 'manual_required', reason: 'captcha_detected', apply_url,
        error: 'CAPTCHA detected.', instructions: 'Apply manually via the link.' });
    }

    // Full name (Lever uses a single name field)
    if (await fill(page, 'input[name=name]', personal_info.full_name)) fields_filled.push('full_name');
    else fields_skipped.push('full_name');

    if (await fill(page, 'input[name=email]', personal_info.email)) fields_filled.push('email');
    else fields_skipped.push('email');

    if (await fill(page, 'input[name=phone]', personal_info.phone)) fields_filled.push('phone');
    else fields_skipped.push('phone');

    if (await fill(page, 'input[name=org]', personal_info.current_company || (payload.experience || {}).current_company || '')) fields_filled.push('current_company');
    else fields_skipped.push('current_company');

    if (personal_info.linkedin_url && await fill(page, 'input[name="urls[LinkedIn]"]', personal_info.linkedin_url)) fields_filled.push('linkedin_url');
    else fields_skipped.push('linkedin_url');

    // Resume upload
    const resumeInput = await page.$('input[type=file]');
    if (resumeInput) {
      await resumeInput.setInputFiles(resume_pdf_path);
      fields_filled.push('resume');
      await humanDelay(1000, 2000);
    } else {
      fields_skipped.push('resume');
    }

    // Cover letter (optional textarea)
    if (cover_letter) {
      const cl = await page.$('textarea[name=comments], textarea[name*=cover]');
      if (cl) { await cl.fill(cover_letter); fields_filled.push('cover_letter'); }
      else fields_skipped.push('cover_letter');
    }

    await humanDelay(500, 1000);
    screenshot_pre = await takeScreenshot(page, screenshot_dir, application_id, 'pre');

    if (options.dry_run) {
      return buildResult({ success: true, outcome: 'submitted', fields_filled, fields_skipped, screenshot_pre, reason: 'dry_run' });
    }

    const submitBtn = await page.$('button[data-qa=btn-submit-application], button[type=submit], input[type=submit]');
    if (!submitBtn) {
      return buildResult({ outcome: 'manual_required', reason: 'selector_not_found',
        error: 'Submit button not found.', fields_filled, fields_skipped, screenshot_pre, apply_url,
        instructions: 'Form filled but submit not found — apply manually.', field_map: buildFieldMap(payload) });
    }

    await Promise.all([
      page.waitForNavigation({ timeout: 20000, waitUntil: 'networkidle' }).catch(() => {}),
      submitBtn.click(),
    ]);
    await humanDelay(1500, 2500);

    const pageText = await page.textContent('body').catch(() => '');
    screenshot_post = await takeScreenshot(page, screenshot_dir, application_id, 'post');

    const confirmed = page.url().includes('/thanks') || /thank you|application submitted|we.ll be in touch/i.test(pageText);
    if (!confirmed) {
      return buildResult({ outcome: 'manual_required', reason: 'ats_page_error',
        error: 'No confirmation page detected.', fields_filled, fields_skipped, screenshot_pre, screenshot_post, apply_url });
    }

    return buildResult({
      success: true, outcome: 'submitted', apply_url,
      confirmation_text: pageText.slice(0, 500), confirmation_number: extractConfirmationNumber(pageText),
      fields_filled, fields_skipped, screenshot_pre, screenshot_post,
    });

  } finally {
    await browser.close().catch(() => {});
  }
}

async function fill(page, selector, value) {
  if (!value) return false;
  const el = await page.$(selector);
  if (!el) return false;
  await el.fill('');
  await el.type(String(value), { delay: 60 + Math.random() * 60 });
  return true;
}

function buildFieldMap(payload) {
  const p = payload.personal_info || {};
  return { name: p.full_name || '', email: p.email || '', phone: p.phone || '', linkedin: (payload.profile || {}).linkedin_url || '' };
}

module.exports = { apply };
