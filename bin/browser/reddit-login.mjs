#!/usr/bin/env node
/**
 * Reddit browser login for the auto poster's "share-button method".
 *
 * Two modes:
 *   --auto-login   Headless. Reads REDDIT_USER / REDDIT_PASS env, signs in
 *                  (no 2FA required), and saves storage/reddit-browser/
 *                  session.json. Prints JSON {ok, error?, note?}.
 *   (default)      Headed/manual. Opens a browser for the operator to sign in
 *                  (any 2FA) and press Enter to save the session. Use on a
 *                  machine with a display, or under xvfb-run --headless.
 *
 * Run from the server that posts (the prod VPS), as the user that runs the
 * worker (www-data):
 *   sudo -u www-data env REDDIT_USER=... REDDIT_PASS=... \
 *     node bin/browser/reddit-login.mjs --auto-login
 */
import { createRequire } from 'node:module';
import { mkdirSync, existsSync, readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '..', '..');
const sessionDir = resolve(root, 'storage', 'reddit-browser');
const sessionFile = resolve(sessionDir, 'session.json');
// Playwright is installed under storage/reddit-browser (gitignored), with the
// downloaded browsers in ms-playwright/, so imports and the browser binary
// resolve regardless of which user / HOME runs the script.
process.env.PLAYWRIGHT_BROWSERS_PATH = resolve(sessionDir, 'ms-playwright');
const require = createRequire(resolve(sessionDir, '.noop.js'));
const { chromium } = require('playwright');

mkdirSync(sessionDir, { recursive: true });

const autoMode = process.argv.includes('--auto-login');
const sleepy = (ms) => new Promise((r) => setTimeout(r, ms));

const fail = (msg) => {
  if (autoMode) process.stdout.write(JSON.stringify({ ok: false, error: msg }));
  else console.error('ERROR: ' + msg);
  process.exit(1);
};

let browser = null;
try {
  const existing = existsSync(sessionFile) ? JSON.parse(readFileSync(sessionFile, 'utf8')) : null;

  browser = await chromium.launch({ headless: autoMode });
  const context = await browser.newContext({ storageState: existing, viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();

  if (autoMode) {
    const username = (process.env.REDDIT_USER || '').trim();
    const password = (process.env.REDDIT_PASS || '').trim();
    if (!username || !password) fail('REDDIT_USER and REDDIT_PASS env vars are required for --auto-login.');

    await page.goto('https://www.reddit.com/login', { waitUntil: 'domcontentloaded', timeout: 60000 });
    await sleepy(1200);

    const userField = await firstVisible(page, ['input#loginUsername', 'input[name="username"]', 'input[autocomplete="username"]', 'input[type="text"]']);
    if (!userField) fail('Could not find the Reddit username/email field (possibly a captcha on this server IP).');
    await userField.fill(username);
    await sleepy(400);

    // New Reddit is two-step: username -> Continue -> password.
    let passField = await firstVisible(page, ['input#loginPassword', 'input[type="password"]', 'input[name="password"]'], 3000);
    if (!passField) {
      const cont = await firstVisible(page, ['button[type="submit"]', 'button:has-text("Continue")', 'button:has-text("Log in")'], 3000);
      if (cont) await cont.click().catch(() => {});
      await sleepy(900);
      passField = await firstVisible(page, ['input#loginPassword', 'input[type="password"]', 'input[name="password"]'], 5000);
    }
    if (!passField) fail('Could not find the Reddit password field.');
    await passField.fill(password);
    await sleepy(400);

    const loginBtn = await firstVisible(page, ['button[type="submit"]', 'button:has-text("Log in")'], 3000);
    if (!loginBtn) fail('Could not find the Reddit Log in button.');
    await loginBtn.click().catch(() => {});
    await sleepy(4500);

    // Verify we are actually signed in (a session could be invalidated, or a
    // captcha could have blocked the attempt).
    let loggedIn = false;
    try {
      const resp = await page.goto('https://www.reddit.com/api/v1/me', { timeout: 30000 });
      const body = resp.ok() ? await resp.text() : '';
      if (body.includes('"name"')) loggedIn = true;
    } catch (_) { /* fall through */ }

    if (!loggedIn) {
      const text = await page.evaluate(() => document.body.innerText).catch(() => '');
      const look = text.replace(/\s+/g, ' ').slice(0, 200);
      fail('Reddit login did not complete (captcha or credentials rejected). ' + look);
    }

    await context.storageState({ path: sessionFile });
    process.stdout.write(JSON.stringify({ ok: true, note: 'Signed in as ' + username + '; session saved to ' + sessionFile }));
    await browser.close().catch(() => {});
    process.exit(0);
  }

  // ------ Manual / headed mode ------
  await page.goto('https://www.reddit.com/login', { waitUntil: 'domcontentloaded', timeout: 60000 });

  if (existing) {
    try {
      await page.goto('https://www.reddit.com/api/v1/me', { timeout: 30000 });
      const body = await page.evaluate(() => document.body.innerText);
      if (body.includes('"name"')) {
        await context.storageState({ path: sessionFile });
        await browser.close().catch(() => {});
        console.log('Existing session is still valid; refreshed ' + sessionFile);
        process.exit(0);
      }
    } catch (_) { /* stale — fresh login below */ }
    await page.goto('https://www.reddit.com/login', { waitUntil: 'domcontentloaded', timeout: 60000 });
  }

  console.log('');
  console.log('============================================================');
  console.log(' Sign in to Reddit in the browser that just opened.');
  console.log(' Complete any 2FA / verification manually.');
  console.log(' When you see the Reddit feed,');
  console.log(' PRESS ENTER HERE to save the session.');
  console.log('============================================================');
  await new Promise((resolve) => process.stdin.once('data', resolve));
  await context.storageState({ path: sessionFile });
  console.log('Saved session to ' + sessionFile);
  await browser.close().catch(() => {});
} catch (e) {
  fail('BROWSER_ERROR: ' + (e && e.message ? e.message : String(e)).slice(0, 300));
}

async function firstVisible(page, selectors, timeout = 6000) {
  for (const sel of selectors) {
    try {
      const locator = page.locator(sel).first();
      await locator.waitFor({ state: 'visible', timeout });
      return locator;
    } catch (_) { /* try next */ }
  }
  return null;
}