/**
 * SmartRecruiters ATS — Playwright handler
 *
 * Current focus:
 * - detect expired postings explicitly
 * - fail closed with a precise reason when the apply form is unavailable
 */
'use strict';

const { launchBrowser, humanDelay, takeScreenshot } = require('../utils/stealth');

async function apply(payload, buildResult) {
  const { apply_url, screenshot_dir, application_id, options = {} } = payload;
  const { browser, page } = await launchBrowser({ headless: options.headless !== false });

  let screenshot_pre = null;
  let screenshot_post = null;

  try {
    await page.goto(apply_url, { waitUntil: 'networkidle', timeout: 45000 });
    await humanDelay(800, 1400);

    const pageText = await page.textContent('body').catch(() => '');
    const normalized = String(pageText || '').toLowerCase();

    if (normalized.includes('sorry, this job has expired') || normalized.includes('this job has expired')) {
      screenshot_pre = await takeScreenshot(page, screenshot_dir, application_id, 'pre');
      return buildResult({
        outcome: 'manual_required',
        reason: 'job_expired',
        apply_url,
        error: 'This SmartRecruiters posting is expired.',
        instructions: 'The direct ATS posting has expired, so this opportunity cannot advance to submission.',
        screenshot_pre,
        field_map: buildFieldMap(payload),
      });
    }

    const formPresent = await page.$('form input, form textarea, form select, input[type="file"], button[type="submit"]');
    if (!formPresent) {
      screenshot_pre = await takeScreenshot(page, screenshot_dir, application_id, 'pre');
      return buildResult({
        outcome: 'manual_required',
        reason: 'apply_form_unavailable',
        apply_url,
        error: 'SmartRecruiters apply form was not available on the destination page.',
        instructions: 'The direct ATS page loaded, but the application form was not accessible for automation.',
        screenshot_pre,
        field_map: buildFieldMap(payload),
      });
    }

    screenshot_pre = await takeScreenshot(page, screenshot_dir, application_id, 'pre');
    screenshot_post = await takeScreenshot(page, screenshot_dir, application_id, 'post');

    return buildResult({
      outcome: 'manual_required',
      reason: 'phase2_pending',
      apply_url,
      error: 'SmartRecruiters form automation beyond availability checks is not yet implemented.',
      instructions: 'The direct ATS page is live, but this platform still needs field-level automation.',
      screenshot_pre,
      screenshot_post,
      field_map: buildFieldMap(payload),
    });
  } finally {
    await browser.close().catch(() => {});
  }
}

function buildFieldMap(payload) {
  const p = payload.personal_info || {};
  return {
    firstName: p.first_name || '',
    lastName: p.last_name || '',
    email: p.email || '',
    phone: p.phone || '',
  };
}

module.exports = { apply };
