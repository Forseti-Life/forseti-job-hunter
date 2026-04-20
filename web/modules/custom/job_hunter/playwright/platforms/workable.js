/**
 * Workable ATS — Playwright handler
 *
 * Handles: jobs.workable.com application forms
 * Track: A (no login required)
 */
'use strict';

const fs = require('fs');
const {
  launchBrowser,
  humanDelay,
  takeScreenshot,
  extractConfirmationNumber,
} = require('../utils/stealth');

async function apply(payload, buildResult) {
  const {
    apply_url,
    personal_info = {},
    experience = {},
    education = {},
    work_auth = {},
    salary_expectations = {},
    resume_pdf_path,
    cover_letter,
    screenshot_dir,
    application_id,
    options = {},
  } = payload;

  if (!resume_pdf_path || !fs.existsSync(resume_pdf_path)) {
    return buildResult({
      outcome: 'manual_required',
      reason: 'no_resume_pdf',
      error: 'Resume PDF not found at: ' + resume_pdf_path,
      instructions: 'Upload your tailored resume PDF and try again.',
    });
  }

  const { browser, page } = await launchBrowser({ headless: options.headless !== false });
  const fields_filled = [];
  const fields_skipped = [];
  let screenshot_pre = null;
  let screenshot_post = null;

  try {
    await page.goto(apply_url, { waitUntil: 'networkidle', timeout: 45000 });
    await humanDelay(800, 1400);

    await dismissCookieBanner(page);
    await openApplicationForm(page);

    if (await page.$('iframe[src*="recaptcha"], .g-recaptcha, iframe[src*="hcaptcha"]')) {
      return buildResult({
        outcome: 'manual_required',
        reason: 'captcha_detected',
        error: 'CAPTCHA detected on Workable apply page.',
        instructions: 'This job requires a CAPTCHA. Please apply manually.',
        apply_url,
      });
    }

    await fillBasics(page, personal_info, experience, fields_filled, fields_skipped);
    await fillOptionalProfileSections(page, education, experience, fields_filled, fields_skipped);
    await uploadResume(page, resume_pdf_path, fields_filled, fields_skipped);
    await fillDetails(page, payload, cover_letter, work_auth, salary_expectations, fields_filled, fields_skipped);

    await humanDelay(600, 1100);
    screenshot_pre = await takeScreenshot(page, screenshot_dir, application_id, 'pre');

    if (options.dry_run) {
      return buildResult({
        success: true,
        outcome: 'submitted',
        reason: 'dry_run',
        instructions: 'Dry run — Workable form filled without submitting.',
        apply_url,
        fields_filled,
        fields_skipped,
        screenshot_pre,
      });
    }

    const submitBtn = await page.$('button[type=submit], button:has-text("Submit application")');
    if (!submitBtn) {
      screenshot_post = await takeScreenshot(page, screenshot_dir, application_id, 'post');
      return buildResult({
        outcome: 'manual_required',
        reason: 'selector_not_found',
        error: 'Submit button not found.',
        instructions: 'The Workable form was filled but the final submit button was not found.',
        apply_url,
        fields_filled,
        fields_skipped,
        screenshot_pre,
        screenshot_post,
        field_map: buildFieldMap(payload),
      });
    }

    await Promise.all([
      page.waitForNavigation({ timeout: 20000, waitUntil: 'networkidle' }).catch(() => {}),
      submitBtn.click(),
    ]);
    await humanDelay(1400, 2200);

    const pageText = await page.textContent('body').catch(() => '');
    screenshot_post = await takeScreenshot(page, screenshot_dir, application_id, 'post');
    const confirmed =
      /thank you|application submitted|application received|we.ll be in touch/i.test(pageText) ||
      /submitted/i.test(page.url());

    if (!confirmed) {
      return buildResult({
        outcome: 'manual_required',
        reason: 'ats_page_error',
        error: 'Submission did not reach a confirmation page.',
        apply_url,
        fields_filled,
        fields_skipped,
        screenshot_pre,
        screenshot_post,
        instructions: 'The Workable form was submitted but no confirmation page was detected.',
      });
    }

    return buildResult({
      success: true,
      outcome: 'submitted',
      apply_url,
      confirmation_text: pageText.slice(0, 500),
      confirmation_number: extractConfirmationNumber(pageText),
      fields_filled,
      fields_skipped,
      screenshot_pre,
      screenshot_post,
    });
  } finally {
    await browser.close().catch(() => {});
  }
}

async function dismissCookieBanner(page) {
  const accept = page.locator('button[data-ui="cookie-consent-accept"]');
  if (await accept.count()) {
    await accept.first().click().catch(() => {});
    await humanDelay(400, 800);
  }
}

async function openApplicationForm(page) {
  if (await page.$('input[name="firstname"], #firstname')) {
    return;
  }

  const applyButton = page.locator('button[data-ui="overview-apply-now"], button[data-ui="sticky-apply-now"]');
  if (await applyButton.count()) {
    await applyButton.first().click();
    await page.waitForSelector('input[name="firstname"], #firstname', { timeout: 15000 });
    await humanDelay(600, 1000);
  }
}

async function fillBasics(page, personal_info, experience, fields_filled, fields_skipped) {
  const headline = experience.current_title || personal_info.current_title || '';
  const address = [personal_info.city, personal_info.state, 'United States'].filter(Boolean).join(', ');

  await fillSelector(page, 'input[name="firstname"], #firstname', personal_info.first_name, 'first_name', fields_filled, fields_skipped);
  await fillSelector(page, 'input[name="lastname"], #lastname', personal_info.last_name, 'last_name', fields_filled, fields_skipped);
  await fillSelector(page, 'input[name="email"], #email', personal_info.email, 'email', fields_filled, fields_skipped);
  await fillSelector(page, 'input[name="headline"], #headline', headline, 'headline', fields_filled, fields_skipped);
  await fillSelector(page, 'input[name="phone"]', personal_info.phone, 'phone', fields_filled, fields_skipped);
  await fillSelector(page, 'input[name="address"], #address', address, 'address', fields_filled, fields_skipped);
  await fillSelector(page, 'input[name="city"], #city', personal_info.city, 'city', fields_filled, fields_skipped);
  await fillSelector(page, 'input[name="postcode"], #postcode', personal_info.zip, 'postcode', fields_filled, fields_skipped);
  await fillSelector(page, 'input[name="country"], #country', 'United States', 'country', fields_filled, fields_skipped);
}

async function fillOptionalProfileSections(page, education, experience, fields_filled, fields_skipped) {
  const firstEducation = Array.isArray(education.history) ? education.history[0] : null;
  const firstExperience = Array.isArray(experience.history) ? experience.history[0] : null;

  if (firstEducation) {
    const addEducation = page.locator('button[aria-label="Add Education"]');
    if (await addEducation.count()) {
      await addEducation.first().click().catch(() => {});
      await humanDelay(300, 700);
      await fillSelector(page, 'input[name="school"]', firstEducation.institution, 'education_school', fields_filled, fields_skipped);
      await fillSelector(page, 'input[name="field_of_study"]', firstEducation.field, 'education_field', fields_filled, fields_skipped);
      await fillSelector(page, 'input[name="degree"]', educationLabel(firstEducation), 'education_degree', fields_filled, fields_skipped);
      await fillNthSelector(page, 'input[name="start_date"]', 0, formatMonthYear(firstEducation.start_date), 'education_start_date', fields_filled, fields_skipped);
      await fillNthSelector(page, 'input[name="end_date"]', 0, formatMonthYear(firstEducation.end_date), 'education_end_date', fields_filled, fields_skipped);
    } else {
      fields_skipped.push('education_section');
    }
  } else {
    fields_skipped.push('education_section');
  }

  if (firstExperience) {
    const addExperience = page.locator('button[aria-label="Add Experience"]');
    if (await addExperience.count()) {
      await addExperience.first().click().catch(() => {});
      await humanDelay(300, 700);
      await fillSelector(page, 'input[name="title"]', firstExperience.title, 'experience_title', fields_filled, fields_skipped);
      await fillSelector(page, 'input[name="company"]', firstExperience.company, 'experience_company', fields_filled, fields_skipped);
      await fillSelector(page, 'input[name="industry"]', firstExperience.industry, 'experience_industry', fields_filled, fields_skipped);
      await fillNthSelector(page, 'textarea[name="summary"]', 0, summarizeExperience(firstExperience), 'experience_summary', fields_filled, fields_skipped);
      await fillNthSelector(page, 'input[name="start_date"]', 1, formatMonthYear(firstExperience.start_date), 'experience_start_date', fields_filled, fields_skipped);

      if (firstExperience.end_date) {
        await fillNthSelector(page, 'input[name="end_date"]', 1, formatMonthYear(firstExperience.end_date), 'experience_end_date', fields_filled, fields_skipped);
      } else if (await page.$('input[name="current"][type="checkbox"]')) {
        await page.click('input[name="current"][type="checkbox"]').catch(() => {});
        fields_filled.push('experience_current');
      } else {
        fields_skipped.push('experience_end_date');
      }
    } else {
      fields_skipped.push('experience_section');
    }
  } else {
    fields_skipped.push('experience_section');
  }
}

async function uploadResume(page, resume_pdf_path, fields_filled, fields_skipped) {
  const resumeInput = await page.$('input[type="file"]');
  if (!resumeInput) {
    fields_skipped.push('resume');
    return;
  }

  await resumeInput.setInputFiles(resume_pdf_path);
  fields_filled.push('resume');
  await humanDelay(1000, 1800);
}

async function fillDetails(page, payload, cover_letter, work_auth, salary_expectations, fields_filled, fields_skipped) {
  await fillNthSelector(page, 'textarea[name="summary"]', 1, buildProfileSummary(payload), 'profile_summary', fields_filled, fields_skipped);

  if (cover_letter) {
    await fillSelector(page, 'textarea[name="cover_letter"], #cover_letter', cover_letter, 'cover_letter', fields_filled, fields_skipped);
  } else {
    fields_skipped.push('cover_letter');
  }

  const desiredSalary = resolveDesiredSalary(salary_expectations);
  await fillSelector(page, 'input[name^="CA_"]', desiredSalary, 'desired_salary', fields_filled, fields_skipped);
  await answerSponsorshipQuestion(page, work_auth, fields_filled, fields_skipped);
}

async function answerSponsorshipQuestion(page, work_auth, fields_filled, fields_skipped) {
  const wantsSponsorship = requiresSponsorship(work_auth);
  const answer = wantsSponsorship ? 'YES' : 'NO';
  const radio = page.locator(`label:has-text("${answer}")`).last();
  if (await radio.count()) {
    await radio.click().catch(() => {});
    fields_filled.push('requires_sponsorship');
    return;
  }

  const radioInput = page.locator(`input[type="radio"][name^="QA_"]`).nth(wantsSponsorship ? 0 : 1);
  if (await radioInput.count()) {
    await radioInput.click().catch(() => {});
    fields_filled.push('requires_sponsorship');
    return;
  }

  fields_skipped.push('requires_sponsorship');
}

async function fillSelector(page, selector, value, fieldName, fields_filled, fields_skipped) {
  if (!value) {
    fields_skipped.push(fieldName);
    return false;
  }

  const target = page.locator(selector).first();
  if (!(await target.count())) {
    fields_skipped.push(fieldName);
    return false;
  }

  await target.click().catch(() => {});
  await target.fill(String(value));
  fields_filled.push(fieldName);
  return true;
}

async function fillNthSelector(page, selector, index, value, fieldName, fields_filled, fields_skipped) {
  if (!value) {
    fields_skipped.push(fieldName);
    return false;
  }

  const target = page.locator(selector).nth(index);
  if (!(await target.count())) {
    fields_skipped.push(fieldName);
    return false;
  }

  await target.click().catch(() => {});
  await target.fill(String(value));
  fields_filled.push(fieldName);
  return true;
}

function buildProfileSummary(payload) {
  const years = payload.experience && payload.experience.years ? String(payload.experience.years) : '';
  const titles = [];
  if (payload.experience && payload.experience.current_title) {
    titles.push(payload.experience.current_title);
  }
  if (payload.job_title) {
    titles.push('Target role: ' + payload.job_title);
  }
  const skills = String(payload.skills || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
    .slice(0, 8)
    .join(', ');

  return [
    years ? `Executive technology leader with ${years}+ years of experience.` : '',
    titles.length ? titles.join(' | ') : '',
    skills ? `Core focus: ${skills}.` : '',
  ].filter(Boolean).join(' ');
}

function summarizeExperience(entry) {
  const categories = Array.isArray(entry.responsibility_categories) ? entry.responsibility_categories : [];
  const achievementTexts = categories
    .flatMap((category) => Array.isArray(category.achievements) ? category.achievements : [])
    .map((achievement) => String(achievement.text || '').trim())
    .filter(Boolean)
    .slice(0, 3);

  return achievementTexts.join(' ');
}

function educationLabel(entry) {
  return [entry.degree, entry.abbreviation].filter(Boolean).join(' ').trim();
}

function formatMonthYear(value) {
  if (!value) return '';
  const match = String(value).match(/^(\d{4})-(\d{2})/);
  if (match) {
    return `${match[2]}/${match[1]}`;
  }
  return '';
}

function resolveDesiredSalary(salary_expectations) {
  const preferred = normalizeMoney(salary_expectations.min || salary_expectations.max);
  if (preferred) {
    return preferred;
  }
  return '';
}

function normalizeMoney(value) {
  const digits = String(value || '').replace(/[^\d]/g, '');
  return digits || '';
}

function requiresSponsorship(work_auth) {
  const explicit = String(work_auth.requires_sponsorship || '').toLowerCase();
  if (explicit === 'yes' || explicit === 'true' || explicit === '1') {
    return true;
  }
  if (explicit === 'no' || explicit === 'false' || explicit === '0') {
    return false;
  }

  const status = String(work_auth.status || '').toLowerCase();
  return !['us_citizen', 'citizen', 'permanent_resident', 'green_card'].includes(status);
}

function buildFieldMap(payload) {
  const p = payload.personal_info || {};
  return {
    first_name: p.first_name || '',
    last_name: p.last_name || '',
    email: p.email || '',
    phone: p.phone || '',
    city: p.city || '',
    state: p.state || '',
    desired_salary: resolveDesiredSalary(payload.salary_expectations || {}),
  };
}

module.exports = { apply };
