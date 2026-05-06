/**
 * Playwright stealth browser factory.
 *
 * Returns a configured Playwright browser + page with:
 *   - playwright-extra stealth plugin (anti-bot detection)
 *   - Realistic viewport and user agent
 *   - Human-like input helpers
 */

'use strict';

let chromium;
let stealth;

try {
  const { chromium: pwChromium } = require('playwright-extra');
  const StealthPlugin = require('puppeteer-extra-plugin-stealth');
  pwChromium.use(StealthPlugin());
  chromium = pwChromium;
} catch (e) {
  // Fallback to plain playwright if playwright-extra is not installed
  process.stderr.write('WARN: playwright-extra not available, using plain playwright. ' + e.message + '\n');
  ({ chromium } = require('playwright'));
}

const USER_AGENTS = [
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
];

/**
 * Launch a stealth browser and return { browser, page }.
 *
 * @param {object} options
 * @param {boolean} options.headless
 * @returns {Promise<{browser, page}>}
 */
async function launchBrowser(options = {}) {
  const headless = options.headless !== false;
  const userAgent = USER_AGENTS[Math.floor(Math.random() * USER_AGENTS.length)];

  const launchOptions = {
    headless,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
    ],
  };

  // Allow caller (or env) to override the browser executable — required when
  // the Playwright-managed browser is not accessible to the running user (e.g.
  // www-data in a web request cannot reach /home/user/.cache/ms-playwright/).
  if (options.executablePath) {
    launchOptions.executablePath = options.executablePath;
  } else if (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH) {
    launchOptions.executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH;
  }

  const browser = await chromium.launch(launchOptions);

  const context = await browser.newContext({
    userAgent,
    viewport: { width: 1440, height: 900 },
    locale: 'en-US',
    timezoneId: 'America/New_York',
  });

  const page = await context.newPage();

  // Mask navigator.webdriver
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => false });
  });

  return { browser, page };
}

/**
 * Type text into a field with human-like delays.
 *
 * @param {import('playwright').Page} page
 * @param {string} selector
 * @param {string} text
 * @param {{ minMs?: number, maxMs?: number }} options
 */
async function humanType(page, selector, text, options = {}) {
  const min = options.minMs || 40;
  const max = options.maxMs || 120;
  await page.click(selector);
  await page.fill(selector, ''); // clear first
  for (const char of String(text)) {
    await page.keyboard.type(char);
    await sleep(min + Math.random() * (max - min));
  }
}

/**
 * Random sleep between minMs and maxMs.
 */
function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Random delay between two values (for pacing between steps).
 */
async function humanDelay(min = 500, max = 1500) {
  await sleep(min + Math.random() * (max - min));
}

/**
 * Take a screenshot to the configured screenshots directory.
 *
 * @param {import('playwright').Page} page
 * @param {string} screenshotDir
 * @param {number} applicationId
 * @param {string} stage  'pre' or 'post'
 * @returns {string|null} file path or null on failure
 */
async function takeScreenshot(page, screenshotDir, applicationId, stage) {
  if (!screenshotDir) return null;
  try {
    const filename = `${applicationId}_${stage}.png`;
    const filepath = require('path').join(screenshotDir, filename);
    await page.screenshot({ path: filepath, fullPage: false });
    return filepath;
  } catch (e) {
    process.stderr.write('WARN: Screenshot failed: ' + e.message + '\n');
    return null;
  }
}

/**
 * Try to extract a confirmation number from page text.
 * Looks for common patterns like "Confirmation: APP-1234" or "Reference: 123456".
 */
function extractConfirmationNumber(text) {
  const patterns = [
    /confirmation[:\s#]+([A-Z0-9\-]{6,30})/i,
    /reference[:\s#]+([A-Z0-9\-]{6,30})/i,
    /application\s*(?:id|#|number)[:\s]+([A-Z0-9\-]{4,30})/i,
    /\b(APP[-_][A-Z0-9\-]{4,20})\b/i,
    /\b([A-Z]{2,4}-\d{4,10})\b/,
  ];
  for (const re of patterns) {
    const m = text.match(re);
    if (m) return m[1];
  }
  return null;
}

module.exports = { launchBrowser, humanType, humanDelay, sleep, takeScreenshot, extractConfirmationNumber };
