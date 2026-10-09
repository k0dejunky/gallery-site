#!/usr/bin/env node
/**
 * One-time Reddit browser login for the auto poster's "share-button method".
 *
 * Opens a browser, lets the operator sign in to Reddit (handling any 2FA
 * manually), and saves the session (Playwright storageState) to
 * storage/reddit-browser/session.json. The post worker then reuses that
 * session and only signs in again when it expires.
 *
 *   node bin/browser/reddit-login.mjs            # headed (has a display)
 *   xvfb-run node bin/browser/reddit-login.mjs   # headless server login
 *
 * Run it on a machine with node + playwright installed (see
 * bin/browser/setup_reddit_browser.sh), then make sure session.json lands in
 * storage/reddit-browser/ on the server that runs the auto poster.
 */
import { createRequire } from 'node:module';
import { mkdirSync, existsSync, writeFileSync, readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '..', '..');
const sessionDir = resolve(root, 'storage', 'reddit-browser');
const sessionFile = resolve(sessionDir, 'session.json');
// Playwright is installed under storage/reddit-browser (gitignored); anchor
// module resolution there so imports resolve regardless of script location.
const require = createRequire(resolve(sessionDir, '.noop.js'));
const { chromium } = require('playwright');

const headless = process.argv.includes('--headless');
const existing = existsSync(sessionFile)
  ? JSON.parse(readFileSync(sessionFile, 'utf8'))
  : null;

mkdirSync(sessionDir, { recursive: true });

const browser = await chromium.launch({ headless });
const context = await browser.newContext({ storageState: existing, viewport: { width: 1280, height: 900 } });
const page = await context.newPage();

console.log('Navigating to reddit.com/login ...');
await page.goto('https://www.reddit.com/login', { waitUntil: 'domcontentloaded', timeout: 60000 });

// If a session already exists, visit the account page to confirm it is valid.
if (existing) {
  try {
    await page.goto('https://www.reddit.com/api/v1/me', { timeout: 30000 });
    const body = await page.evaluate(() => document.body.innerText);
    if (body.includes('"name"')) {
      await context.storageState({ path: sessionFile });
      await browser.close();
      console.log('Existing session is still valid; refreshed ' + sessionFile);
      process.exit(0);
    }
  } catch (_) { /* session stale — fall through to a fresh login */ }
  await page.goto('https://www.reddit.com/login', { waitUntil: 'domcontentloaded', timeout: 60000 });
}

console.log('');
console.log('============================================================');
console.log(' Sign in to Reddit in the browser that just opened.');
console.log(' Complete any 2FA / verification manually.');
console.log(' When you are signed in and see the Reddit home/feed,');
console.log(' PRESS ENTER HERE to save the session.');
console.log('============================================================');
await new Promise((resolve) => process.stdin.once('data', resolve));

await context.storageState({ path: sessionFile });
console.log('Saved session to ' + sessionFile);
await browser.close();