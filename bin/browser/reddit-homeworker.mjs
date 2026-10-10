#!/usr/bin/env node
/**
 * Home-browser Reddit worker ("share-button method").
 *
 * Runs on a machine with a RESIDENTIAL IP (Reddit blocks this site's cloud
 * server IP for browsers). It polls the site for queued reddit auto-posts,
 * posts each one through the exact same flow as the on-site "Share on Reddit"
 * button (bin/browser/reddit-post.mjs), and reports the result back.
 *
 * Requirements on this machine:
 *   - node 18+, and bin/browser/setup_reddit_browser.sh run once
 *   - a saved session (node bin/browser/reddit-login.mjs, headed) in
 *     storage/reddit-browser/session.json
 *   - env vars: REDDIT_SITE (https://<host>/gallery), REDDIT_BROWSER_KEY,
 *     optional REDDIT_USER/REDDIT_PASS for auto re-login.
 *
 *   node bin/browser/reddit-homeworker.mjs
 */
import { createRequire } from 'node:module';
import { mkdirSync, writeFileSync, rmSync, appendFileSync } from 'node:fs';
import { resolve, join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '..', '..');
const sessionDir = resolve(root, 'storage', 'reddit-browser');
const logFile = resolve(sessionDir, 'homeworker.log');
const jobDir = resolve(sessionDir, 'jobs');
process.env.PLAYWRIGHT_BROWSERS_PATH = resolve(sessionDir, 'ms-playwright');
process.env.REDDIT_SESSION_FILE = resolve(sessionDir, 'session.json');
const require = createRequire(resolve(sessionDir, '.noop.js'));
require('playwright'); // ensure it resolves + browsers path is set before child spawn

const site   = (process.env.REDDIT_SITE || '').replace(/\/+$/, '');
const key    = process.env.REDDIT_BROWSER_KEY || '';
const user   = process.env.REDDIT_USER || '';
const pass   = process.env.REDDIT_PASS || '';
const pollMs = Math.max(10, parseInt(process.env.REDDIT_POLL_SECONDS || '30', 10)) * 1000;
const postScript = resolve(__dirname, 'reddit-post.mjs');
// The child reddit-post.mjs uses system Chrome (channel) when configured, and
// runs headful on the desktop display (Reddit blocks headless signatures).
if (!process.env.REDDIT_BROWSER_CHANNEL) process.env.REDDIT_BROWSER_CHANNEL = 'chrome';
if (process.env.REDDIT_HEADFUL === undefined) process.env.REDDIT_HEADFUL = '1';

mkdirSync(jobDir, { recursive: true });

const log = (msg) => {
  const line = `${new Date().toISOString()} ${msg}`;
  console.log(line);
  try { appendFileSync(logFile, line + '\n'); } catch (_) {}
};

if (!site || !key) {
  log('FATAL: REDDIT_SITE and REDDIT_BROWSER_KEY env vars are required.');
  process.exit(1);
}

const headers = { Authorization: 'Bearer ' + key, 'Content-Type': 'application/json' };

async function poll() {
  let res;
  try {
    res = await fetch(site + '/webhooks/reddit/browser-jobs', { headers });
  } catch (e) {
    log('poll error: ' + (e.message || e));
    return;
  }
  if (res.status !== 200) { log('poll HTTP ' + res.status); return; }
  const data = await res.json().catch(() => ({}));
  const job = data?.job;
  if (!job) return; // empty queue

  const id = job.job_id;
  log(`job #${id}: ${job.mode} "${(job.title || '').slice(0, 60)}" -> r/${job.subreddit}`);

  const payload = {
    mode: job.mode,
    subreddit: job.subreddit,
    title: job.title,
    body: job.body || '',
    username: user,
    password: pass,
  };

  if (job.mode === 'image' && job.image_base64) {
    const ext = (job.image_type || 'image/jpeg').split('/')[1] || 'jpg';
    const imgPath = join(jobDir, `job-${id}.${/^[a-z]+$/i.test(ext) ? ext : 'jpg'}`);
    try { writeFileSync(imgPath, Buffer.from(job.image_base64, 'base64')); }
    catch (e) { await report(id, false, null, 'Could not write the image locally: ' + (e.message || e)); return; }
    payload.imagePath = imgPath;
  } else {
    payload.url = job.url || '';
  }

  const payloadFile = join(jobDir, `payload-${id}.json`);
  writeFileSync(payloadFile, JSON.stringify(payload));

  const run = spawnSync(process.execPath, [postScript, payloadFile], { encoding: 'utf8', timeout: 150000 });
  rmSync(payloadFile, { force: true });
  if (payload.imagePath) rmSync(payload.imagePath, { force: true });

  let result = null;
  try { result = JSON.parse((run.stdout || '').trim()); } catch (_) {}
  const ok = !!(result && result.ok);
  const approved = !!(result && result.approved);
  log(`post result #${id}: ${ok ? 'posted' : 'failed'} ${ok ? result.url || '' : (result.error || (run.stderr ? run.stderr.slice(0, 300) : 'browser worker failed'))}${ok && approved ? ' | approved in modqueue' : ''}`);
  await report(id, ok, result?.url || '', result?.error || (run.stderr ? run.stderr.slice(0, 300) : 'browser worker failed'));
}

async function report(id, ok, url, error) {
  log(`report #${id}: ${ok ? 'posted' : 'failed'} ${ok ? url : error}`);
  try {
    await fetch(site + `/webhooks/reddit/browser-jobs/${id}/report`, {
      method: 'POST',
      headers,
      body: JSON.stringify({ ok, url: ok ? url : '', error: ok ? '' : error }),
    });
  } catch (e) {
    log('report error: ' + (e.message || e));
  }
}

log(`Started. Polling ${site} every ${pollMs / 1000}s (redirecting to r/ posts via the share-button method).`);
while (true) {
  await poll();
  await new Promise((r) => setTimeout(r, pollMs));
}