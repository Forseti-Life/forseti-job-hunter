/**
 * job_hunter Auth Path Identifier
 *
 * Uses a stealth Chromium browser to navigate to the resolved apply URL,
 * click the primary Apply/Start Application control, then classify the
 * authentication mechanism that appears.
 *
 * Called by AuthPathIdentificationService::identify() via proc_open().
 * Result JSON is written to --output-file to avoid pipe-buffer overflow.
 *
 * Usage:
 *   node identify-auth-path.js --url=https://... [--timeout=45] --output-file=/tmp/jh_ap_XXXX
 *
 * stdout JSON schema:
 *   {
 *     "ok":            bool,    // classification succeeded (auth_type != 'unknown')
 *     "auth_type":     string,  // see AUTH_TYPES below
 *     "sso_providers": string[], // detected SSO buttons (google, linkedin, etc.)
 *     "form_fields":   string[], // detected required field names
 *     "apply_url":     string,  // effective URL after clicking Apply
 *     "page_title":    string,
 *     "evidence":      string,  // human-readable classification rationale
 *     "html_excerpt":  string,  // first 4KB of page HTML after click
 *     "error":         string
 *   }
 *
 * AUTH_TYPES:
 *   direct           - form fills immediately, no account required
 *   email_password   - standard email + password login form
 *   email_only       - passwordless email/magic-link entry point
 *   sso_google       - primary path is Google OAuth
 *   sso_linkedin     - primary path is LinkedIn OAuth
 *   sso_microsoft    - primary path is Microsoft/Azure AAD
 *   sso_apple        - primary path is Apple Sign-In
 *   company_sso      - redirected to company/external IdP (SAML/OIDC, non-Google/LinkedIn)
 *   registration_first - must create account before applying
 *   captcha_blocked  - bot-detection/CAPTCHA prevented classification
 *   unknown          - could not determine
 */

'use strict';

const fs   = require('fs');
const args = require('minimist')(process.argv.slice(2));
const { launchBrowser } = require('./utils/stealth');

const applyUrl   = (args.url             || '').trim();
const timeout    = Math.max(10, Math.min(120, parseInt(args.timeout, 10) || 45)) * 1000;
const outputFile = (args['output-file']      || '').trim();
const execPath   = (args['executable-path']  || process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || '').trim();

function emit(data) {
  const json = JSON.stringify(data) + '\n';
  if (outputFile) {
    try { fs.writeFileSync(outputFile, json, { mode: 0o600 }); } catch (e) { /* best-effort */ }
    process.exit(0);
  }
  process.stdout.write(json);
  process.exit(0);
}

if (!applyUrl) {
  emit({ ok: false, auth_type: 'unknown', sso_providers: [], form_fields: [], apply_url: '', page_title: '', evidence: 'No --url provided.', html_excerpt: '', error: 'No --url argument.' });
}

// ── Selectors ─────────────────────────────────────────────────────────────────

const APPLY_BUTTON_SELECTORS = [
  'a:has-text("Apply Now")', 'a:has-text("Apply now")',
  'button:has-text("Apply Now")', 'button:has-text("Apply now")',
  'a:has-text("Start Application")', 'button:has-text("Start Application")',
  'a:has-text("Easy Apply")', 'button:has-text("Easy Apply")',
  'a:has-text("Quick Apply")', 'button:has-text("Quick Apply")',
  'a:has-text("Apply")', 'button:has-text("Apply")',
  '[data-testid="apply-button"]', '[data-automation="apply-button"]',
  '.apply-button', '#apply-button', '.btn-apply',
];

const SSO_SIGNATURES = [
  { id: 'google',    patterns: [/sign.?in with google/i, /continue with google/i, /google\.com\/accounts/i, /accounts\.google\.com/i, /auth\.google\./i] },
  { id: 'linkedin',  patterns: [/sign.?in with linkedin/i, /continue with linkedin/i, /linkedin\.com\/oauth/i, /linkedin\.com\/uas\/login/i] },
  { id: 'microsoft', patterns: [/sign.?in with microsoft/i, /continue with microsoft/i, /login\.microsoftonline\.com/i, /login\.live\.com/i, /microsoftazure/i, /azure.*aad/i] },
  { id: 'apple',     patterns: [/sign.?in with apple/i, /continue with apple/i, /appleid\.apple\.com/i] },
];

const CAPTCHA_SIGNATURES = [/cf-challenge/i, /captcha/i, /recaptcha/i, /hcaptcha/i, /turnstile/i, /cf_chl_/i];

// ── ATS URL patterns ──────────────────────────────────────────────────────────
// Recognised applicant tracking systems — keyed by URL pattern.
// auth_type is the *most common* auth mechanism for that ATS; the HTML
// classification below can still override if the page content says otherwise.
const ATS_URL_PATTERNS = [
  { re: /myworkdayjobs\.com/i,       name: 'Workday',     auth_type: 'email_password' },
  { re: /myworkday\.com/i,           name: 'Workday',     auth_type: 'email_password' },
  { re: /boards\.greenhouse\.io/i,   name: 'Greenhouse',  auth_type: 'email_password' },
  { re: /jobs\.lever\.co/i,          name: 'Lever',       auth_type: 'email_password' },
  { re: /icims\.com/i,               name: 'iCIMS',       auth_type: 'email_password' },
  { re: /successfactors\.com/i,      name: 'SAP SuccessFactors', auth_type: 'email_password' },
  { re: /taleo\.net/i,               name: 'Taleo',       auth_type: 'email_password' },
  { re: /smartrecruiters\.com/i,     name: 'SmartRecruiters', auth_type: 'email_password' },
  { re: /jobvite\.com/i,             name: 'Jobvite',     auth_type: 'email_password' },
  { re: /breezy\.hr/i,               name: 'Breezy HR',   auth_type: 'email_password' },
  { re: /bamboohr\.com/i,            name: 'BambooHR',    auth_type: 'email_password' },
  { re: /ashbyhq\.com/i,             name: 'Ashby',       auth_type: 'email_password' },
  { re: /apply\.workable\.com/i,     name: 'Workable',    auth_type: 'email_password' },
];

// ── Classification helper ─────────────────────────────────────────────────────

function classifyPage(html, pageUrl, pageTitle) {
  const lower = html.toLowerCase() + ' ' + (pageUrl || '').toLowerCase() + ' ' + (pageTitle || '').toLowerCase();

  // CAPTCHA / bot-block
  if (CAPTCHA_SIGNATURES.some(re => re.test(lower))) {
    return { auth_type: 'captcha_blocked', evidence: 'Cloudflare/CAPTCHA challenge detected.', sso_providers: [] };
  }

  // ── ATS URL-based detection (high-confidence shortcut) ──────────────────
  for (const ats of ATS_URL_PATTERNS) {
    if (ats.re.test(pageUrl)) {
      return {
        auth_type: ats.auth_type,
        evidence:  `Recognised ATS platform: ${ats.name} (URL: ${pageUrl}).`,
        sso_providers: [],
      };
    }
  }

  // Detect SSO providers
  const sso_providers = [];
  for (const sig of SSO_SIGNATURES) {
    if (sig.patterns.some(re => re.test(lower))) {
      sso_providers.push(sig.id);
    }
  }
  if (sso_providers.length > 0) {
    const primary = sso_providers[0];
    return { auth_type: 'sso_' + primary, evidence: 'SSO provider buttons detected: ' + sso_providers.join(', '), sso_providers };
  }

  // Company SSO / external IdP redirect (not Google/LinkedIn)
  const extSsoRe = /\/sso\/|\/saml\/|\/oidc\/|\/idp\/|\/oauth\/|\/auth\/|shibboleth|okta\.com|onelogin\.com|ping(?:identity|federate)|adfs/i;
  if (extSsoRe.test(pageUrl)) {
    return { auth_type: 'company_sso', evidence: 'Redirected to external identity provider: ' + pageUrl, sso_providers: [] };
  }

  // Registration / create account page
  if (/create.{0,30}account|register.{0,20}account|sign.?up|join now|create.{0,10}profile/i.test(lower) &&
      !/sign.?in|log.?in|already have/i.test(lower)) {
    return { auth_type: 'registration_first', evidence: 'Registration/create-account form detected.', sso_providers: [] };
  }

  // Standard email+password login
  const hasEmailField = /type=["']email["']|name=["']email["']|placeholder=["'][^"']*email/i.test(html);
  const hasPasswordField = /type=["']password["']|name=["']password["']|name=["']pass["']/i.test(html);

  if (hasEmailField && hasPasswordField) {
    return { auth_type: 'email_password', evidence: 'Email + password login form detected.', sso_providers: [] };
  }

  // Passwordless / email-only (magic link)
  if (hasEmailField && /magic.?link|send.{0,10}link|email.{0,10}code|verify.{0,10}email/i.test(lower)) {
    return { auth_type: 'email_only', evidence: 'Email-only/magic-link form detected.', sso_providers: [] };
  }

  if (hasEmailField) {
    return { auth_type: 'email_password', evidence: 'Email field detected (password field may be in next step).', sso_providers: [] };
  }

  // Direct application form (no auth step)
  const hasNameField = /name=["'](?:first|last|full|firstname|lastname|name)["']|placeholder=["'][^"']*\bname\b/i.test(html);
  const hasResumeInput = /type=["']file["']|upload.{0,20}resume|attach.{0,20}resume|resume.*upload/i.test(lower);
  if (hasNameField || hasResumeInput) {
    return { auth_type: 'direct', evidence: 'Direct application form detected (name/resume fields, no login gate).', sso_providers: [] };
  }

  return { auth_type: 'unknown', evidence: 'Could not classify authentication mechanism from page content.', sso_providers: [] };
}

function extractFormFields(html) {
  const fields = [];
  const re = /(?:name|id)=["']([a-z][a-z0-9_\-]{1,40})["']/gi;
  const seen = new Set(['submit', 'button', 'csrf', 'token', '_token', 'utf8', 'authenticity_token']);
  let m;
  while ((m = re.exec(html)) !== null) {
    const f = m[1].toLowerCase();
    if (!seen.has(f)) {
      seen.add(f);
      fields.push(m[1]);
    }
    if (fields.length >= 20) break;
  }
  return fields;
}

// ── Main ──────────────────────────────────────────────────────────────────────

(async () => {
  let browser = null;
  const gTimeout = setTimeout(() => {
    emit({ ok: false, auth_type: 'unknown', sso_providers: [], form_fields: [], apply_url: applyUrl, page_title: '', evidence: 'Global timeout exceeded.', html_excerpt: '', error: 'Global timeout exceeded.' });
  }, timeout + 10000);

  try {
    const { browser: b, page } = await launchBrowser({ headless: true, executablePath: execPath || undefined });
    browser = b;
    const context = page.context();

    // ── Track new tabs / popups ───────────────────────────────────────────
    // Many career sites open the ATS in a new tab (target="_blank").
    let popupPage = null;
    context.on('page', async (newPage) => {
      popupPage = newPage;
    });

    // ── 1. Load the apply page ─────────────────────────────────────────────
    let navStatus = 0;
    try {
      const resp = await page.goto(applyUrl, { waitUntil: 'domcontentloaded', timeout });
      navStatus = resp ? resp.status() : 0;
    } catch (e) {
      clearTimeout(gTimeout);
      if (browser) await browser.close().catch(() => {});
      emit({ ok: false, auth_type: 'unknown', sso_providers: [], form_fields: [], apply_url: applyUrl, page_title: '', evidence: 'Navigation failed: ' + e.message, html_excerpt: '', error: e.message });
      return;
    }

    await page.waitForTimeout(2000);

    // ── 2. Try to click an Apply button ───────────────────────────────────
    let clicked = false;
    for (const sel of APPLY_BUTTON_SELECTORS) {
      try {
        const el = page.locator(sel).first();
        if (await el.isVisible({ timeout: 1500 })) {
          await el.click({ timeout: 3000 });
          clicked = true;
          break;
        }
      } catch (_) { /* try next selector */ }
    }

    if (clicked) {
      // Wait for either same-page navigation OR a new-tab popup.
      // Use Promise.race so we capture whichever happens first.
      const navPromise  = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 12000 }).catch(() => null);
      const popupPromise = new Promise((resolve) => {
        if (popupPage) { resolve(popupPage); return; }
        const check = setInterval(() => { if (popupPage) { clearInterval(check); resolve(popupPage); } }, 200);
        setTimeout(() => { clearInterval(check); resolve(null); }, 12000);
      });
      await Promise.race([navPromise, popupPromise]);

      // Give extra settle time for client-side JS redirects
      await page.waitForTimeout(3000);
    } else {
      // No apply button found — classify the page as-is (direct form, or already at auth)
      await page.waitForTimeout(1500);
    }

    // ── 3. Determine which page to classify ──────────────────────────────
    // If a new tab was opened (popup), switch classification target to that page.
    let targetPage = page;
    if (popupPage) {
      try {
        await popupPage.waitForLoadState('domcontentloaded', { timeout: 15000 }).catch(() => {});
        await popupPage.waitForTimeout(2000);
        targetPage = popupPage;
      } catch (_) {
        // Fall back to original page if popup fails to load
        targetPage = page;
      }
    }

    const effectiveUrl = targetPage.url();
    const html         = await targetPage.content();
    const pageTitle    = await targetPage.title().catch(() => '');

    clearTimeout(gTimeout);
    await browser.close().catch(() => {});

    const { auth_type, evidence, sso_providers } = classifyPage(html, effectiveUrl, pageTitle);
    const form_fields = extractFormFields(html);

    emit({
      ok:           auth_type !== 'unknown',
      auth_type,
      sso_providers,
      form_fields,
      apply_url:    effectiveUrl,
      page_title:   pageTitle,
      evidence:     evidence + (clicked ? ' (Apply button was clicked).' : ' (No Apply button found — classified from landing page).'),
      html_excerpt: html.substring(0, 4096),
      error:        '',
    });

  } catch (e) {
    clearTimeout(gTimeout);
    if (browser) await browser.close().catch(() => {});
    emit({ ok: false, auth_type: 'unknown', sso_providers: [], form_fields: [], apply_url: applyUrl, page_title: '', evidence: '', html_excerpt: '', error: e.message });
  }
})();
