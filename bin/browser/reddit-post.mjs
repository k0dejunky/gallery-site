#!/usr/bin/env node
/**
 * Headless-browser Reddit auto poster ("share-button method").
 *
 * Reuses a saved session (storage/reddit-browser/session.json) to open the
 * real `reddit.com/r/<sub>/submit` page — exactly what the on-site "Share on
 * Reddit" button does — fill the title (+ URL or image file), click "Post",
 * and return the new post URL.
 *
 * Usage:
 *   node bin/browser/reddit-post.mjs <payload.json>
 * Payload: {
 *   mode: "link" | "image",
 *   subreddit: "Amethyst2213NSFW",
 *   title: "...",
 *   url?: "https://...",          // link posts
 *   imagePath?: "/abs/path.jpg",  // image posts
 *   username?, password?          // optional auto re-login when session expired
 * }
 * Output (stdout, JSON): {ok:bool, url?:string, error?:string}
 */
import { createRequire } from 'node:module';
import { existsSync, readFileSync, appendFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '..', '..');
const sessionFile = resolve(root, 'storage', 'reddit-browser', 'session.json');
const sessionDir = resolve(root, 'storage', 'reddit-browser');
const logFile = resolve(sessionDir, 'posts.log');
// Playwright is installed under storage/reddit-browser (gitignored), with the
// downloaded browsers in ms-playwright/, so imports and the browser binary
// resolve regardless of which user / HOME runs the script.
process.env.PLAYWRIGHT_BROWSERS_PATH = resolve(sessionDir, 'ms-playwright');
const require = createRequire(resolve(sessionDir, '.noop.js'));
const { chromium } = require('playwright');

const arg = process.argv[2];
let payload = {};
if (arg && existsSync(arg)) {
  payload = JSON.parse(readFileSync(arg, 'utf8'));
} else if (arg) {
  payload = JSON.parse(arg);
}
// Also accept the payload on stdin (a line of JSON).
if (!Object.keys(payload).length && !process.stdin.isTTY) {
  const raw = await new Promise((ok) => {
    let buf = '';
    process.stdin.setEncoding('utf8');
    process.stdin.on('data', (c) => (buf += c));
    process.stdin.on('end', () => ok(buf));
  });
  if (raw.trim()) payload = JSON.parse(raw.trim());
}

const mode      = payload.mode === 'image' ? 'image' : 'link';
const sub       = String(payload.subreddit || '').replace(/^r\//i, '').replace(/[^A-Za-z0-9_]/g, '');
const title     = String(payload.title || '').trim();
const url       = String(payload.url || '').trim();
const imagePath = String(payload.imagePath || '');
const username  = String(payload.username || '');
const password  = String(payload.password || '');

const out = (obj) => {
  try { appendFileSync(logFile, new Date().toISOString() + ' ' + JSON.stringify(obj).slice(0, 400) + '\n'); } catch (_) {}
  process.stdout.write(JSON.stringify(obj));
  process.exit(obj.ok ? 0 : 1);
};

if (!sub) out({ ok: false, error: 'No subreddit given.' });
if (!title) out({ ok: false, error: 'No title given.' });
if (mode === 'link' && !url) out({ ok: false, error: 'No url given for a link post.' });
if (mode === 'image' && !existsSync(imagePath)) out({ ok: false, error: 'Image file not found: ' + imagePath });
if (!existsSync(sessionFile)) out({ ok: false, error: 'SESSION_MISSING: no reddit-browser session yet — run node bin/browser/reddit-login.mjs once.' });

const sleepy = (ms) => new Promise((r) => setTimeout(r, ms));

let browser = null;
try {
  const session = JSON.parse(readFileSync(sessionFile, 'utf8'));
  browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ storageState: session, viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(15000);

  const submitUrl = mode === 'link'
    ? 'https://www.reddit.com/r/' + sub + '/submit?url=' + encodeURIComponent(url) + '&title=' + encodeURIComponent(title)
    : 'https://www.reddit.com/r/' + sub + '/submit?title=' + encodeURIComponent(title);

  await page.goto(submitUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await sleepy(1500);

  // Redirected to login → session expired (optionally auto re-login).
  if (/\/login/.test(page.url())) {
    if (username && password) {
      const relog = await autoLogin(page, username, password);
      if (!relog.ok) out(relog);
      await page.goto(submitUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await sleepy(1500);
    } else {
      out({ ok: false, error: 'SESSION_EXPIRED: reddit login expired — re-run node bin/browser/reddit-login.mjs.' });
    }
  }

  // Fill the title (may already be prefilled from ?title=).
  const titleField = await firstVisible(page, [
    'textarea[name="title"]',
    'div[role="textbox"][aria-label*="Title"]',
    'textarea[placeholder*="title" i]',
    '[data-testid="post-title"] textarea',
  ]);
  if (titleField) {
    const current = (await titleField.inputValue().catch(() => '')) || (await titleField.innerText().catch(() => ''));
    if (!String(current).trim()) {
      await titleField.fill(title);
    }
  }

  if (mode === 'image') {
    // Switch to the Image tab (reveals the file input).
    const imgTab = await firstVisible(page, [
      'button[role="tab"]:has-text("Image")',
      '[role="tab"]:has-text("Image")',
      'div[role="button"]:has-text("Image")',
    ]);
    if (imgTab) await imgTab.click().catch(() => {});
    await sleepy(800);

    const fileInput = await firstVisible(page, ['input[type="file"]']);
    if (!fileInput) out({ ok: false, error: 'Could not show the image uploader on the submit page.' });
    await fileInput.setInputFiles(imagePath);
    await sleepy(2500); // wait for the thumbnail to render
  } else {
    // If the ?url= did not prefill the URL field, look for the Link URL input.
    const urlField = await firstVisible(page, [
      'input[name="url"]',
      'input[placeholder*="Url" i]',
      'textarea[placeholder*="Url" i]',
    ]);
    if (urlField) {
      const currentVal = await urlField.inputValue().catch(() => '');
      if (!String(currentVal).trim()) await urlField.fill(url);
    }
    // If there is a Link tab and the URL field is missing, switch to it first.
    if (!urlField) {
      const linkTab = await firstVisible(page, ['button[role="tab"]:has-text("Link")']);
      if (linkTab) await linkTab.click().catch(() => {});
      await sleepy(800);
      const urlField2 = await firstVisible(page, ['input[placeholder*="Url" i]', 'input[name="url"]']);
      if (urlField2) await urlField2.fill(url);
    }
  }

  // Click the main "Post" button.
  const postBtn = await firstVisible(page, [
    'button:has-text("Post")',
    'button[type="submit"]:has-text("Post")',
    '#createPostButton',
  ], 10000);
  if (!postBtn) out({ ok: false, error: 'MANUAL_VERIFICATION: could not find the Post button on the submit page.' });
  await postBtn.click({ timeout: 10000 }).catch(() => {});
  await sleepy(1200);

  // Any confirm/paste dialogs (e.g. duplicate-link repost warning) → accept.
  const confirmBtn = await firstVisible(page, ['button:has-text("Post")', 'button:has-text("OK")'], 3000);
  if (confirmBtn && !/submit/.test(page.url())) {
    await confirmBtn.click().catch(() => {});
  }

  // Wait for navigation away from /submit (success) or a verification trap.
  let finalUrl = '';
  for (let i = 0; i < 30; i++) {
    await sleepy(1000);
    const u = page.url();
    if (!/\/submit($|\?)/.test(u) && u.includes('www.reddit.com')) { finalUrl = u; break; }
  }

  if (finalUrl) {
    out({ ok: true, url: finalUrl.split('?')[0], mode });
  }

  // No navigation: surface a verification / error message instead of guessing.
  const body = await page.evaluate(() => document.body.innerText).catch(() => '');
  const excerpt = body.replace(/\s+/g, ' ').slice(0, 240);
  const trap = /verify|you'?re human|captcha|something went wrong|try again later|rate limit/i.test(excerpt);
  out({ ok: false, error: (trap ? 'MANUAL_VERIFICATION: ' : 'Reddit rejected the post: ') + excerpt });
} catch (e) {
  out({ ok: false, error: 'BROWSER_ERROR: ' + (e && e.message ? e.message : String(e)).slice(0, 300) });
} finally {
  if (browser) await browser.close().catch(() => {});
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

async function autoLogin(page, username, password) {
  try {
    await page.goto('https://www.reddit.com/login', { waitUntil: 'domcontentloaded', timeout: 60000 });
    // New reddit: username/email first, then continue; then password.
    const userField = await firstVisible(page, ['input#loginUsername', 'input[name="username"]', 'input[autocomplete="username"]']);
    if (!userField) return { ok: false, error: 'SESSION_EXPIRED: could not reach the reddit login form to re-login.' };
    await userField.fill(username);
    const passField = await firstVisible(page, ['input#loginPassword', 'input[type="password"]', 'input[name="password"]']);
    if (!passField) return { ok: false, error: 'SESSION_EXPIRED: reddit login needs a password to re-login.' };
    await passField.fill(password);
    const loginBtn = await firstVisible(page, ['button[type="submit"]:has-text("Log in")', 'button:has-text("Log in")']);
    if (!loginBtn) return { ok: false, error: 'SESSION_EXPIRED: could not click the reddit login button.' };
    await loginBtn.click();
    await sleepy(3000);
    if (/\/login/.test(page.url())) {
      return { ok: false, error: 'SESSION_EXPIRED: automatic re-login failed (likely a captcha or 2FA) — re-run node bin/browser/reddit-login.mjs.' };
    }
    const ctx = page.context();
    await ctx.storageState({ path: sessionFile });
    return { ok: true };
  } catch (e) {
    return { ok: false, error: 'SESSION_EXPIRED: auto re-login error: ' + (e && e.message ? e.message : String(e)).slice(0, 200) };
  }
}