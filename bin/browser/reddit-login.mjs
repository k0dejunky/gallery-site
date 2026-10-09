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

  const launchOpts = { headless: autoMode && process.env.REDDIT_HEADFUL !== '1' };
  if (process.env.REDDIT_BROWSER_CHANNEL) launchOpts.channel = process.env.REDDIT_BROWSER_CHANNEL;
  browser = await chromium.launch(launchOpts);
  const context = await browser.newContext({ storageState: existing, viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();

  if (autoMode) {
    const username = (process.env.REDDIT_USER || '').trim();
    const password = (process.env.REDDIT_PASS || '').trim();
    if (!username || !password) fail('REDDIT_USER and REDDIT_PASS env vars are required for --auto-login.');

    // Warm up on the homepage first: Reddit's js_challenge keeps the login
    // button disabled until its challenge resolves, and headful home content
    // proves we are past the network-security gate.
    await page.goto('https://www.reddit.com/', { waitUntil: 'domcontentloaded', timeout: 60000 });
    await sleepy(5000);
    await page.goto('https://www.reddit.com/login', { waitUntil: 'domcontentloaded', timeout: 60000 });
    await sleepy(2000);

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

    // The Log In button stays disabled until js_challenge fully resolves.
    const loginBtn = page.locator('button').filter({ hasText: 'Log In' }).first();
    let ready = false;
    for (let i = 0; i < 20; i++) {
      if (await loginBtn.isEnabled().catch(() => false)) { ready = true; break; }
      await sleepy(1000);
    }
    if (!ready) fail('The Reddit Log In button never became enabled (js_challenge failed).');
    await loginBtn.click().catch(() => {});
    await sleepy(6000);

    // Verify we are actually signed in: the reddit_session auth cookie only
    // exists for a logged-in session (the JSON endpoints stay anonymous).
    const cookieNames = (await context.cookies('https://www.reddit.com')).map((c) => c.name);
    if (!cookieNames.includes('reddit_session')) {
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
    const names = (await context.cookies('https://www.reddit.com')).map((c) => c.name);
    if (names.includes('reddit_session')) {
      await context.storageState({ path: sessionFile });
      await browser.close().catch(() => {});
      console.log('Existing session is still valid; refreshed ' + sessionFile);
      process.exit(0);
    }
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