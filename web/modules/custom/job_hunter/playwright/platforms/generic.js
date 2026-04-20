/**
 * Generic / Unknown career page — heuristic form fill
 *
 * Tries label-based field matching. Does NOT submit — returns
 * manual_required with reason=heuristic_fill_only and the field_map.
 */
'use strict';

const { launchBrowser, humanDelay, takeScreenshot } = require('../utils/stealth');

async function apply(payload, buildResult) {
  const { apply_url, personal_info, screenshot_dir, application_id, options = {} } = payload;
  const { browser, page } = await launchBrowser({ headless: options.headless !== false });
  const fields_filled = [], fields_skipped = [];
  let screenshot_pre = null;

  try {
    await page.goto(apply_url, { waitUntil: 'networkidle', timeout: 30000 });
    await humanDelay(1000, 1800);

    // Heuristic: match visible labels to profile data
    const labelMap = {
      'first name': personal_info.first_name,
      'last name':  personal_info.last_name,
      'full name':  personal_info.full_name,
      'name':       personal_info.full_name,
      'email':      personal_info.email,
      'phone':      personal_info.phone,
    };

    const inputs = await page.$$('input[type=text], input[type=email], input[type=tel]');
    for (const input of inputs) {
      const id    = await input.getAttribute('id') || '';
      const name  = await input.getAttribute('name') || '';
      const placeholder = (await input.getAttribute('placeholder') || '').toLowerCase();
      const label = await getInputLabel(page, id);
      const hint  = (label + ' ' + placeholder + ' ' + name).toLowerCase();

      for (const [key, value] of Object.entries(labelMap)) {
        if (value && hint.includes(key)) {
          await input.fill(String(value));
          fields_filled.push(key.replace(' ', '_'));
          break;
        }
      }
    }

    screenshot_pre = await takeScreenshot(page, screenshot_dir, application_id, 'pre');

    return buildResult({
      outcome:      'manual_required',
      reason:       'heuristic_fill_only',
      apply_url,
      error:        'Generic form — heuristic fill only. Form was NOT submitted.',
      instructions: 'This career page uses a custom form. Review the pre-filled fields and submit manually.',
      fields_filled,
      fields_skipped,
      screenshot_pre,
      field_map: Object.fromEntries(
        Object.entries(labelMap).filter(([, v]) => v).map(([k, v]) => [k.replace(' ', '_'), v])
      ),
    });

  } finally {
    await browser.close().catch(() => {});
  }
}

async function getInputLabel(page, inputId) {
  if (!inputId) return '';
  try {
    return await page.$eval(`label[for="${inputId}"]`, el => el.textContent.trim().toLowerCase());
  } catch { return ''; }
}

module.exports = { apply };
