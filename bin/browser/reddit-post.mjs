#!/usr/bin/env node
/**
 * Headless-browser Reddit auto poster ("share-button method").
 *
 * Uses a saved session (storage/reddit-browser/session.json) to open the real
 * `reddit.com/r/<sub>/submit` page — exactly what the on-site "Share on
 * Reddit" button does — fill the title + URL (a gallery link, so Reddit shows
 * the gallery's og:image thumbnail), click "Post", and return the new
 * permalink.
 *
 * NOTE on images: Reddit's web image editor does not accept synthesised file
 * uploads and /api/* is WAF-blocked for browser sessions, so native image
 * uploads are not reliable without the OAuth API (RedditClient::uploadImage,
 * already built). Gallery rows post as a LINK, which Reddit renders with the
 * gallery's thumbnail media card.
 *
 * Usage: node bin/browser/reddit-post.mjs <payload.json>
 *   payload { mode, subreddit, title, url?, username?, password? }
 * Output (stdout, JSON): {ok, url?, error?}
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
process.env.PLAYWRIGHT_BROWSERS_PATH = resolve(sessionDir, 'ms-playwright');
const require = createRequire(resolve(sessionDir, '.noop.js'));
const { chromium } = require('playwright');

const sleepy = (ms) => new Promise((r) => setTimeout(r, ms));

const arg = process.argv[2];
let payload = {};
if (arg && existsSync(arg)) payload = JSON.parse(readFileSync(arg, 'utf8'));
else if (arg) payload = JSON.parse(arg);
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
const username  = String(payload.username || '');
const password  = String(payload.password || '');

const out = (obj) => {
  try { appendFileSync(logFile, new Date().toISOString() + ' ' + JSON.stringify(obj).slice(0, 400) + '\n'); } catch (_) {}
  process.stdout.write(JSON.stringify(obj));
  process.exit(obj.ok ? 0 : 1);
};

if (!existsSync(sessionFile)) out({ ok: false, error: 'SESSION_MISSING: no reddit-browser session yet — run node bin/browser/reddit-login.mjs once.' });
if (!sub) out({ ok: false, error: 'No subreddit given.' });
if (!title) out({ ok: false, error: 'No title given.' });
if (!url) out({ ok: false, error: 'No url given for a link post.' });

let browser = null;
try {
  const session = JSON.parse(readFileSync(sessionFile, 'utf8'));
  const launchOpts = { headless: process.env.REDDIT_HEADFUL !== '1' };
  if (process.env.REDDIT_BROWSER_CHANNEL) launchOpts.channel = process.env.REDDIT_BROWSER_CHANNEL;
  browser = await chromium.launch(launchOpts);
  const context = await browser.newContext({ storageState: session, viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);

  // Warm up on the homepage so Reddit's js_challenge resolves.
  await page.goto('https://www.reddit.com/', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await sleepy(4000);

  // Image rows post as links (Reddit shows the gallery og:image thumbnail).
  const submitUrl = 'https://www.reddit.com/r/' + sub + '/submit?url=' + encodeURIComponent(url) + '&title=' + encodeURIComponent(title);
  await page.goto(submitUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await sleepy(2000);

  if (/\/login/.test(page.url())) {
    if (username && password) {
      const relog = await autoLogin(page, username, password);
      if (!relog.ok) out(relog);
      await page.goto(submitUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await sleepy(2000);
    } else {
      out({ ok: false, error: 'SESSION_EXPIRED: reddit login expired — re-run node bin/browser/reddit-login.mjs.' });
    }
    if (/\/login/.test(page.url())) out({ ok: false, error: 'SESSION_EXPIRED: still on /login after re-login.' });
  }

  // Title (prefilled from ?title= — verify it landed).
  const tf = await firstVisible(page, ['textarea[name="title"]', '[data-testid="post-title"] textarea', 'div[role="textbox"][aria-label*="Title"]']);
  if (tf) {
    const val = (await tf.inputValue().catch(() => '')) || (await tf.innerText().catch(() => ''));
    if (!String(val).trim()) await tf.fill(title).catch(() => {});
  }

  // URL field (prefilled from ?url= — fill if empty).
  const uf = await firstVisible(page, ['input[placeholder*="Url" i]', 'input[name="url"]', 'textarea[placeholder*="Url" i]']);
  if (uf) {
    const val = await uf.inputValue().catch(() => '');
    if (!String(val).trim()) await uf.fill(url).catch(() => {});
  }

  // Post button (waits to become enabled once the challenge resolves).
  const postBtn = page.locator('button').filter({ hasText: 'Post' }).last();
  let ready = false;
  for (let i = 0; i < 20; i++) {
    if ((await postBtn.count()) && await postBtn.isEnabled().catch(() => false)) { ready = true; break; }
    await sleepy(1000);
  }
  if (!ready) {
    const body = (await page.evaluate(() => document.body.innerText).catch(() => '')).replace(/\s+/g, ' ').slice(0, 200);
    out({ ok: false, error: (/blocked by network security/i.test(body) ? 'MANUAL_VERIFICATION: ' : 'Reddit rejected the post: ') + body });
  }
  await postBtn.click({ timeout: 15000 }).catch(() => {});
  await sleepy(1200);

  // Accept any duplicate/repost confirm dialog.
  const confirmB = await firstVisible(page, ['button:has-text("OK")', 'button:has-text("Post now")'], 2500);
  if (confirmB) await confirmB.click().catch(() => {});

  // Wait for navigation away from /submit.
  let finalUrl = '';
  for (let i = 0; i < 30; i++) {
    await sleepy(1000);
    const u = page.url();
    if (!/\/submit($|\?)/.test(u) && u.includes('www.reddit.com')) { finalUrl = u; break; }
  }

  if (finalUrl) {
    let permalink = finalUrl.split('?')[0];
    if (!/\/comments\//.test(permalink)) {
      try {
        const found = await page.evaluate((t) => {
          const tt = t.toLowerCase().slice(0, 120);
          const links = Array.from(document.querySelectorAll('a[href*="/comments/"]'));
          for (const a of links) {
            const box = (a.closest('article') || a.parentElement || a).innerText || '';
            if (box.toLowerCase().includes(tt)) return a.href;
          }
          return links[0] ? links[0].href : null;
        }, title);
        if (found && /\/comments\//.test(found)) permalink = found.split('?')[0];
      } catch (_) {}
    }
    out({ ok: true, url: permalink, approved: await approveInModQueue(page, sub, title) });
  }

  const body = (await page.evaluate(() => document.body.innerText).catch(() => '')).replace(/\s+/g, ' ').slice(0, 240);
  out({ ok: false, error: /blocked|verify|captcha|something went wrong/i.test(body) ? 'MANUAL_VERIFICATION: ' + body : 'Reddit rejected the post: ' + body });
} catch (e) {
  out({ ok: false, error: 'BROWSER_ERROR: ' + (e && e.message ? e.message : String(e)).slice(0, 300) });
} finally {
  if (browser) await browser.close().catch(() => {});
}

async function firstVisible(page, selectors, timeout = 8000) {
  for (const sel of selectors) {
    try {
      const locator = page.locator(sel).first();
      await locator.waitFor({ state: 'visible', timeout });
      return locator;
    } catch (_) {}
  }
  return null;
}

/**
 * Best-effort: approve the just-posted item in the subreddit's mod queue so it
 * is visible immediately even if Reddit/AutoMod held it. Gated by
 * REDDIT_APPROVE_OWN (default on). Never throws; returns true when clicked,
 * false when there was nothing to approve (already visible / no rights / no
 * matching queued row).
 */
async function approveInModQueue(page, sub, title) {
  if (process.env.REDDIT_APPROVE_OWN === '0') return false;
  try {
    await page.goto('https://www.reddit.com/r/' + sub + '/about/modqueue/', { waitUntil: 'domcontentloaded', timeout: 45000 });
    await sleepy(2500);

    const titleNeedle = title.toLowerCase().slice(0, 80);

    for (let attempt = 0; attempt < 4; attempt++) {
      const res = await page.evaluate((needle) => {
        const anchors = Array.from(document.querySelectorAll('a[href*="/comments/"]'));
        for (const a of anchors) {
          const box = a.closest('shreddit-post, article, li, [class*="post"]') || a.parentElement || a;
          if (!((box.textContent || '').toLowerCase().includes(needle))) continue;
          const approve = box.querySelector('[aria-label*="Approve" i], [aria-label*=" approve" i], button[title*="pprove" i], [role="button"][aria-label*="pprove" i], [data-testid*="pprove" i]');
          if (approve) {
            approve.click();
            return { found: true };
          }
          return { found: true, noButton: true };
        }
        return { found: false };
      }, titleNeedle);

      if (res.found) {
        await sleepy(1500);
        if (res.noButton) return false;      // the post is queued but without an approve control (not ours / no rights)
        return true;                          // clicked
      }
      // Give the queue a moment to reflect the new post before giving up.
      await sleepy(3000);
    }
    return false;
  } catch (_) {
    return false;
  }
}

async function autoLogin(page, username, password) {
  try {
    await page.goto('https://www.reddit.com/', { waitUntil: 'domcontentloaded', timeout: 60000 });
    await sleepy(4000);
    await page.goto('https://www.reddit.com/login', { waitUntil: 'domcontentloaded', timeout: 60000 });
    await sleepy(2000);
    const userField = await firstVisible(page, ['input#loginUsername', 'input[name="username"]', 'input[autocomplete="username"]', 'input[type="text"]']);
    if (!userField) return { ok: false, error: 'SESSION_EXPIRED: could not reach the reddit login form to re-login.' };
    await userField.fill(username);
    await sleepy(400);
    let passField = await firstVisible(page, ['input[type="password"]', 'input#loginPassword', 'input[name="password"]'], 3000);
    if (!passField) {
      const cont = await firstVisible(page, ['button[type="submit"]', 'button:has-text("Continue")'], 2500);
      if (cont) await cont.click().catch(() => {});
      await sleepy(900);
      passField = await firstVisible(page, ['input[type="password"]', 'input#loginPassword'], 5000);
    }
    if (!passField) return { ok: false, error: 'SESSION_EXPIRED: no password field to re-login.' };
    await passField.fill(password);
    await sleepy(400);
    const loginBtn = page.locator('button').filter({ hasText: 'Log In' }).first();
    let readyEl = false;
    for (let i = 0; i < 20; i++) {
      if (await loginBtn.isEnabled().catch(() => false)) { readyEl = true; break; }
      await sleepy(1000);
    }
    if (!readyEl) return { ok: false, error: 'SESSION_EXPIRED: Log In button never enabled.' };
    await loginBtn.click().catch(() => {});
    await sleepy(6000);
    const names = (await page.context().cookies('https://www.reddit.com')).map((c) => c.name);
    if (!names.includes('reddit_session')) return { ok: false, error: 'SESSION_EXPIRED: re-login did not produce a session.' };
    await page.context().storageState({ path: sessionFile });
    return { ok: true };
  } catch (e) {
    return { ok: false, error: 'SESSION_EXPIRED: auto re-login error: ' + (e && e.message ? e.message : String(e)).slice(0, 200) };
  }
}