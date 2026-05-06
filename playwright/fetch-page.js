/**
 * job_hunter Playwright Page Fetcher
 *
 * Retrieves a page using a real stealth Chromium browser, bypassing bot-detection
 * layers (Cloudflare, etc.) that reject plain HTTP clients like Guzzle.
 *
 * Called by ApplicationLocationVerificationService::fetchPageViaBrowser() via
 * proc_open().  When --output-file is provided, result JSON is written to that
 * file (avoids pipe-buffer overflow on large HTML pages).  Otherwise stdout.
 * Exit code is always 0 unless the process crashes before emit().
 *
 * Usage:
 *   node fetch-page.js --url=https://example.com [--timeout=30] [--output-file=/tmp/out.json]
 *
 * stdout JSON schema:
 *   {
 *     "ok":           bool,    // page was retrieved and HTML is non-empty
 *     "status_code":  int,     // HTTP response status (or 0 if navigation failed)
 *     "html":         string,  // full rendered DOM HTML
 *     "effective_url":string,  // final URL after redirects
 *     "title":        string,  // <title> text
 *     "error":        string   // error message when ok=false
 *   }
 */

'use strict';

const fs   = require('fs');
const args = require('minimist')(process.argv.slice(2));
const { launchBrowser } = require('./utils/stealth');

const url        = (args.url         || '').trim();
const timeout    = Math.max(10, Math.min(120, parseInt(args.timeout, 10) || 30)) * 1000;
const outputFile = (args['output-file']      || '').trim();
const execPath   = (args['executable-path']  || process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || '').trim();

/**
 * Emit a JSON result — to output-file if provided, otherwise stdout.
 */
function emit(data) {
  const json = JSON.stringify(data) + '\n';
  if (outputFile) {
    try { fs.writeFileSync(outputFile, json, { mode: 0o600 }); } catch (e) { /* best-effort */ }
    process.exit(0);
  } else {
    process.stdout.write(json);
    process.exit(0);
  }
}

if (!url) {
  emit({ ok: false, status_code: 0, html: '', effective_url: '', title: '', error: 'No --url argument provided.' });
}

(async () => {
  let browser = null;

  // Global timeout guard — if the whole thing stalls, emit error after timeout + 5s.
  const gTimeout = setTimeout(() => {
    emit({ ok: false, status_code: 0, html: '', effective_url: url, title: '', error: 'Global timeout exceeded.' });
  }, timeout + 5000);

  try {
    const { browser: b, page } = await launchBrowser({ headless: true, executablePath: execPath || undefined });
    browser = b;

    let statusCode = 0;

    // Navigate — capture HTTP response status.
    let response = null;
    try {
      response = await page.goto(url, {
        waitUntil: 'domcontentloaded',
        timeout,
      });
      statusCode = response ? response.status() : 0;
    } catch (navErr) {
      // Navigation error (timeout, DNS failure, etc.)
      clearTimeout(gTimeout);
      if (browser) await browser.close().catch(() => {});
      emit({ ok: false, status_code: 0, html: '', effective_url: url, title: '', error: navErr.message });
      return;
    }

    // Give JS a moment to render dynamic content.
    await page.waitForTimeout(1500);

    const effectiveUrl = page.url();
    const html         = await page.content();
    const title        = await page.title().catch(() => '');

    clearTimeout(gTimeout);
    await browser.close().catch(() => {});

    emit({
      ok:           html.length > 200,
      status_code:  statusCode,
      html,
      effective_url: effectiveUrl,
      title,
      error:        (html.length <= 200 && statusCode !== 200) ? ('HTTP ' + statusCode + ' – page may be blocked.') : '',
    });

  } catch (e) {
    clearTimeout(gTimeout);
    if (browser) await browser.close().catch(() => {});
    emit({ ok: false, status_code: 0, html: '', effective_url: url, title: '', error: e.message });
  }
})();
