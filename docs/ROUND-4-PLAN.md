# Round 4: the site-improvement programme

**This file is the recovery anchor for the 2026-10-21 programme.** It holds
the full item list, the evidence for every item and a status table, so a
crashed session can resume by reading the first `pending` row without
re-analysing a single line of code.

Companion documents: `docs/UI-ROUND-3-PLAN.md` (the site-wide UI refactor,
finished and shipped) and `docs/SCAN-PLAN.md` (the 2026-10-20 deep scan,
finished and shipped). **Both are closed.** This is the next programme.

Origin: the user asked "what other site improvements should be done" on
2026-10-20 and then "do it".

**Decisions taken (2026-10-21):**
- Scope: **everything** - the live defects, the capacity work, the
  discoverability work and the member-facing money work.
- **Apache serves the public media variants directly.** No CDN. The gated
  sizes keep going through PHP.
- **The PPV / tip / unlock-code surface stays dormant.** It is built and
  it works; it is not the problem. 0 galleries have a `ppv_price`, 0
  unlock codes, 1 purchase.
- **The site indexes its PREVIEWS, not its full media.** The paywall
  markup stays as it is.
- The member site stays dark, the admin stays light, the system font stays.

**Sequencing:** the live defects first, on their own -> the media path ->
the heavy admin pages -> the cache and the queries -> discoverability ->
the member-facing money -> ops hardening. Verification follows the house
rule every time: **local -> dev -> prod -> commit last** (one commit per
item, the check that locks it in travels in the same commit).

---

## Where the work stands (updated 2026-10-21)

| Phase | What | Status | Commit |
|---|---|---|---|
| 0 | The plan anchor (this file) | done | - |
| 1 | The dead cron, and the alerting that missed it | **done** | `1882ecb`, `6a8108d` |
| 2 | The money: cancel, refund, grace, accounting | pending | - |
| 3 | One access decision, not five | pending | - |
| 4 | The exposure: stream key, keys, health, default page | pending | - |
| 5 | Abuse: rate limits and moderation | pending | - |
| 6 | The media path | pending | - |
| 7 | The heavy admin pages | pending | - |
| 8 | The cache and the queries | pending | - |
| 9 | Discoverability | pending | - |
| 10 | The member-facing money | pending | - |
| 11 | Ops hardening | pending | - |
| Final | local smoke/unit -> dev -> prod verify -> commit + push | pending | - |

### Baseline

- **local** `/media/webdev/Media/gallery-full` - `main` @ `b8b2c60`, clean,
  in sync with `origin/main`.
- `php tests/smoke.php` -> **963 checks, all passed**.
- `php _suite_runner.php` -> 1006 tests with the **6 known
  environment-only failures** (4 orphan photos, the example.com routes,
  the missing Twitter key, the missing cron directory). Nothing here may
  add a seventh.

---

## Phase 1 - the dead cron, and the alerting that missed it

**A LIVE OUTAGE.** The housekeeping cron has failed on every 15-minute
tick since **2026-10-20 09:15** on BOTH boxes. 76 consecutive
`PDOException`s in prod's `storage/logs/php-error.log`, and
`storage/logs/cron.log`'s mtime has not moved since 09:15. The health
endpoint reports `housekeeping_age_sec: 67288` (18.7 hours).

The cause is one wrong column: `Housekeeping.php:379` deletes
`FROM category_views WHERE view_date`, but the column is `created_at`
(`schema.sql:313`). `content_views` genuinely HAS a `view_date`
(`schema.sql:636`) - only `category_views` is wrong.

**The damage is bigger than the one-word fix**, because of where the
throw lands - the last 5% of a 30-step function:

| | step | line |
|---|---|---|
| ok | expiry, renewal/winback/trial emails, the disk alert, the storage snapshot, the chat prune, the fatal alert, the gallery notification, the daily chat broadcast | 100-226 |
| **throws** | `pruneVisitHistory()` | 227 -> 379 |
| **never runs** | `rewardReferrals()` - the refer-a-friend **+7 days** | 231 |
| **never runs** | the cron log write | 233 |
| **never runs** | `LiveRecording::importOrphans()` (in `CronController.php:30`) | after `run()` |

**The log write is why nobody noticed.** And `alertOnPhpFatals()` IS
firing - prod's `storage/logs/alerts.state` shows `"php-fatal":1792555203`
(2026-10-21 04:00:03) - but the "cron is stale" alert only fires when a
human loads the admin dashboard, and the cron entry discards the exit
code (`curl -fsS ... >/dev/null 2>&1`). There is no autonomous watchdog.

| id | what | files | status | commit |
|---|---|---|---|---|
| 1.1 | The wrong column, and the **19 hours of missed referral rewards** backfilled | `app/Core/Housekeeping.php` | done | 1882ecb |
| 1.2 | Every step isolated: one failure must not abort the other 29 | `app/Core/Housekeeping.php` | done | 1882ecb |
| 1.3 | The cron log written in a `finally`, so a throw still records what happened | `app/Core/Housekeeping.php` | done | 1882ecb |
| 1.4 | The cron entry must not discard the exit code, and the cron file must live in the repo | `bin/apply_cron.php` | done | 6a8108d |
| 1.5 | An **autonomous** watchdog - the cron-stale alert must not need a human to open the dashboard | `app/Core/Housekeeping.php`, `app/Controllers/AdminController.php` | done | 6a8108d |
| 1.6 | A guard that a column rename cannot fool: assert every column a housekeeping query names exists, against the real schema | `app/Core/SmokeChecks.php` | done | 1882ecb |
| 1.7 | The whole housekeeping runs green on a real database | dev + prod | done | `6a8108d` |

### The evidence

**1.1** - `app/Core/Housekeeping.php:375-386`
```php
$out['content_views'] = (int) Database::run(
    'DELETE FROM content_views WHERE view_date < DATE_SUB(CURDATE(), INTERVAL 2 YEAR)'
)->rowCount();

$out['category_views'] = (int) Database::run(
    'DELETE FROM category_views WHERE view_date < DATE_SUB(CURDATE(), INTERVAL 2 YEAR)'  // <- the column is created_at
)->rowCount();
```
Prod's error log, 76 times:
```
[2026-10-20 09:30:03] Uncaught PDOException: SQLSTATE[42S22]: Column not found: 1054
  Unknown column 'view_date' in 'where clause' in app/Core/Database.php:128
#1 app/Core/Housekeeping.php(379): App\Core\Database::run()
```

### What phase 1 changed

The cron is alive again, and a failure can no longer hide.

| id | what it did |
|---|---|
| 1.1 | The wrong column (`view_date` -> `created_at` on `category_views`). `rewardReferrals()` is self-healing, so the next run pays the backlog - there were **0** pending, so no revenue was lost |
| 1.2 | Every step now runs through `self::step()`, which catches the throw, records it in `$out['errors']` and lets the other 29 steps carry on |
| 1.3 | The cron log is written in a `finally`, so a failing step can never stop the log from being written - the log is the only record that the run happened |
| 1.4 | The housekeeping runs as a CLI script. The old `curl ... ?key=SECRET > /dev/null 2>&1` put the key in a world-readable cron file and in `ps`, and discarded both the response and the exit code |
| 1.5 | `bin/watchdog.php` alerts the admin by email when a worker goes quiet. The stale-cron alert only lived on the admin dashboard, so it needed a human to load it |
| 1.6 | `smoke.housekeeping.columns_exist` reads the columns out of schema.sql and the column names out of the queries - a column rename now fails the build instead of the cron |
| 1.7 | The whole housekeeping runs green on a real database (`errors=[]`) |

**THE HEARTBEAT.** The first version of the watchdog watched the worker
LOGS' mtimes and immediately reported four perfectly healthy workers as
stale on the dev box: they only log when they have something to do, so an
idle worker looks exactly like a dead one. Every worker now touches a
heartbeat on every run (`app/Core/Heartbeat.php`), the auto-poster's own
heartbeat became the shared one so `/health` and the watchdog read the
same file, and the watchdog separates "has never run here" from "has
stopped" so a dev box with a worker unconfigured does not cry wolf.

Verified on the dev box: 16 workers checked, 0 stale, 4 correctly
reported as never having run there, and the new cron entries are live.

---

## Phase 2 - the money: cancel, refund, grace, accounting

**Members are being charged for a membership they cancelled.**
`Subscription::cancel()` cancels the local row and calls the gateway ONLY
if the transaction ref starts with `PAYPAL-`. `BraintreeGateway` has
`createSubscription` and **no `cancelSubscription` at all**. 5 active
subscriptions on prod.

| id | what | files | status | commit |
|---|---|---|---|---|
| 2.1 | `BraintreeGateway::cancelSubscription()` + wire it into `Subscription::cancel()` beside the PayPal branch | `app/Core/BraintreeGateway.php`, `app/Models/Subscription.php` | pending | - |
| 2.2 | The refund path: `BraintreeGateway::refund()` exists and is **never called**; the admin's "refund" only flips a local DB status | `app/Controllers/ModerationController.php`, `app/Core/PayPalGateway.php` | pending | - |
| 2.3 | A grace period on past due (the code's own comment promises one) + a **card-update form** on `/membership/my` | `app/Models/Subscription.php`, `app/Controllers/WebhookController.php`, `views/membership/my.php` | pending | - |
| 2.4 | Offline tips must not be counted as revenue | `app/Models/Purchase.php`, `app/Controllers/EarningsController.php` | pending | - |
| 2.5 | An idempotency key on the one-off charges - a double-click today creates two Braintree sales | `app/Controllers/PurchaseController.php` | pending | - |
| 2.6 | An append-only money ledger; `purchases` rows are mutated in place and an admin can set any status on any row | a new model + a migration | pending | - |
| 2.7 | A member-facing refund request, an invoice/receipt, a pause, a dunning ladder, a chargeback state | phase 10 | pending | - |

---

## Phase 3 - one access decision, not five

**The site tells members two contradictory things about the same asset.**
Five places decide "can this member see this" and they disagree:
`GalleryController` uses the gallery's own `min_level`; `ImageController`
and `StorageController` use `Photo::minimumGalleryLevel()` = the **MIN
across every gallery the photo belongs to**. 43 photos are attached to
galleries at *different* levels, so a Silver member sees a video the Gold
gallery shows as locked.

| id | what | files | status | commit |
|---|---|---|---|---|
| 3.1 | ONE predicate, used by all five call sites | `app/Models/Photo.php` + the 3 controllers | pending | - |
| 3.2 | The cross-level photo case decided deliberately (per-photo level, or per-gallery) | `app/Models/Photo.php`, `app/Models/Gallery.php` | pending | - |
| 3.3 | `/browse/galleries` has **no level filter at all** - any logged-in member can enumerate every premium gallery's title and its real 400x300 cover | `app/Controllers/GalleryController.php` | pending | - |
| 3.4 | The public collection share link leaks premium thumbnails: `Collection::addGallery()` never checks the gallery exists, is published, or is within the member's tier | `app/Models/Collection.php` | pending | - |
| 3.5 | `/images/{id}` and the "Load more" AJAX path ignore `Purchase::userUnlocked()` | `app/Controllers/ImageController.php`, `app/Controllers/GalleryController.php` | pending | - |

---

## Phase 4 - the exposure

| id | what | files | status | commit |
|---|---|---|---|---|
| 4.1 | **The RTMP publish key must never reach a member.** `/live/state` returns it and the recording is auto-published to a paid gallery | `app/Controllers/LiveController.php` | pending | - |
| 4.2 | The cron key is world-readable in `/etc/cron.d/` (0644) and visible in `ps` | `bin/apply_cron.php` | pending | - |
| 4.3 | `/gallery/health` is public and discloses backup, cron and worker state | `app/Controllers/HealthController.php` | pending | - |
| 4.4 | `https://amethyst2213.com/index.html` serves the **Apache "It works" default page** | a repo-owned Apache conf | pending | - |
| 4.5 | `robots.txt` is **not in the repo** - `install.sh` never writes the root alias, so a fresh install has no robots.txt where it is honoured | `install.sh`, `config/` | pending | - |
| 4.6 | Narrow the `GALLERY_CHAT_KEY` blast radius: per-device attribution, rotation, scope | `app/Controllers/ChatBridgeController.php` | pending | - |

---

## Phase 5 - abuse: rate limits and moderation

| id | what | files | status | commit |
|---|---|---|---|---|
| 5.1 | `POST /live/chat/send` has **no rate limit at all** | `app/Controllers/LiveController.php` | pending | - |
| 5.2 | Comments, reactions and ratings have no rate limit | `app/Controllers/CommentController.php`, `app/Controllers/GalleryRatingController.php` | pending | - |
| 5.3 | A moderation queue for live chat - `live_chat_messages` has no admin screen at all | a new admin view | pending | - |
| 5.4 | The bridge attachment endpoint is the only bridge route with no `throttle()` | `app/Controllers/ChatBridgeController.php` | pending | - |
| 5.5 | A per-content "report this" button + a report queue | a new model + a migration | pending | - |

---

## Phase 6 - the media path

| id | what | files | status | commit |
|---|---|---|---|---|
| 6.1 | **`thumb` and `blur` go straight from Apache**, no PHP hop. A guest `/galleries` view drops from **77 FPM requests to ~5** | `app/Controllers/StorageController.php`, a repo-owned Apache conf | pending | - |
| 6.2 | The media token becomes **time-limited, not session-bound** - today every non-thumb URL is unique per session, so the `immutable` header is dead and a new session re-downloads the grid | `app/Core/helpers.php` | pending | - |
| 6.3 | 9 DB queries per media request -> 1-2 | `app/Controllers/StorageController.php` | pending | - |
| 6.4 | Guests get AVIF/WebP/srcset too - members get the `<picture>` block, guests get a plain `<img>` | `views/partials/gallery_card.php` | pending | - |
| 6.5 | The variant ladder backlog: 321 of 15,626 videos have a 480p rung | `bin/video_rendition_worker.php` | pending | - |

---

## Phase 7 - the heavy admin pages

| id | what | files | status | commit |
|---|---|---|---|---|
| 7.1 | The admin dashboard does **~280,000 `stat()` calls** per load (3.4-4.0s, 3.7-4.0s of FPM CPU) | `app/Controllers/AdminController.php` | pending | - |
| 7.2 | `/admin/system`: 83 `SHOW COLUMNS` queries + 20,500 file stats + a **synchronous 8.9s Ollama generation** in the request path | `app/Controllers/SystemController.php` | pending | - |
| 7.3 | The analytics page **full-scans `web_visits` (272,942 rows) four times** - 1.03s of its 1.44s. The covering indexes | `app/Models/WebStats.php` + a migration | pending | - |
| 7.4 | The log aggregator **re-parses the whole 2-day window every hour** (19s/hour, 6.5M lines/day re-read). It needs an incremental tail | `app/Core/AccessLogAggregator.php` | pending | - |
| 7.5 | The admin re-parse runs up to 31 days **inside one HTTP request** (~5 min) with no lock of its own | `app/Controllers/AnalyticsController.php` | pending | - |

---

## Phase 8 - the cache and the queries

| id | what | files | status | commit |
|---|---|---|---|---|
| 8.1 | A lock on `Cache::remember` / `PageCache::fragment` - a cold cache makes N concurrent requests all run the producer, and `bump('listings')` invalidates the whole guest site at once | `app/Core/Cache.php`, `app/Core/PageCache.php` | pending | - |
| 8.2 | The page cache is keyed on the **raw query string** - every `?utm_*` is a separate 393 KB entry | `app/Core/Router.php` | pending | - |
| 8.3 | Redis has **no `maxmemory`** and `noeviction` | the Redis config on the box | pending | - |
| 8.4 | The N+1s: the wall's comments (20), `Gallery::categories()` inside `recommended()` (8), `Comment::countFor()` per wall post in the admin engagement table (30) | `views/wall.php`, `app/Models/Gallery.php`, `views/admin/engagement.php` | pending | - |
| 8.5 | The operator app's SSE stream has a **busy loop** - the typing path re-queries with no sleep | `app/Controllers/ChatBridgeController.php` | pending | - |
| 8.6 | The FPM pool: `pm.max_children=30` and each SSE connection holds a worker for 30s | the FPM pool config | pending | - |

---

## Phase 9 - discoverability

Index the previews, not the full media. The paywall markup stays as it is.

| id | what | files | status | commit |
|---|---|---|---|---|
| 9.1 | The sitemap covers **178 of ~2,711 crawlable URLs**. Add `/galleries` and `/creator`, drop the 27 contentless category pages, paginate, and **cache it** (it is `no-store` + `Set-Cookie` today) | `app/Controllers/StaticPageController.php` | pending | - |
| 9.2 | The titles: the site name is appended to a title that already has it - "amethyst2213 - amethyst2213" on two pages, "Amethyst - amethyst2213 - amethyst2213" on a third | `views/layout.php`, `views/home.php`, `views/gallery/index.php`, `views/creator/profile.php` | pending | - |
| 9.3 | 8 duplicate-title groups over 16 published galleries, and 21 of 59 with no description | the admin gallery form + the listing | pending | - |
| 9.4 | **0 of 7,058 photos have a caption** -> every image is `alt=""` and every `/images/{id}` page is titled "Full-size image". A caption or a filename fallback fixes both | `views/gallery/show.php`, `views/gallery/image_full.php`, `views/partials/gallery_card.php` | pending | - |
| 9.5 | `BreadcrumbList` - the breadcrumbs are VISIBLE on every gallery, category, tag, image and video page and none of them are in the JSON-LD | `views/layout.php` | pending | - |
| 9.6 | The og tags: `og:type` is "website" everywhere, there are no `og:video` tags, and the og:image is 400x300 under a `summary_large_image` card | `views/layout.php`, `views/video/player.php` | pending | - |
| 9.7 | `og:image` is **absent** on `/galleries`, `/galleries/category/*` and `/creator`, and **404s for scrapers** on `/membership` (relative + a session-bound token) | the 4 views | pending | - |
| 9.8 | The canonicals: `/creator` has none (so `og:url` falls back to the site root), the tag pages have none, and the category pagination canonicalises to page 1 | `views/creator/profile.php`, `views/gallery/tag.php`, `views/gallery/category.php` | pending | - |
| 9.9 | A video sitemap - there is none, and the video pages are in no sitemap at all | `app/Controllers/StaticPageController.php` | pending | - |
| 9.10 | The 51 paid galleries need internal links a crawler can follow - a guest's related rail can only reach the **8 free-tier** ones | `app/Models/Gallery.php` | pending | - |
| 9.11 | `robots.txt` into the repo, `install.sh` writes the root alias, the `Sitemap:` line points at the real URL | `public/robots.txt`, `install.sh` | pending | - |
| 9.12 | The crawlable surface is uncacheable: every HTML page and both feeds are `no-store` + `Set-Cookie`. Plus a relative CSP `report-uri` and no `Link: rel=prev/next` | `public/index.php`, `app/Core/Router.php` | pending | - |

---

## Phase 10 - the member-facing money

The PPV/tip/unlock surface stays dormant. This phase is about the
**existing subscription model**.

| id | what | status | commit |
|---|---|---|---|
| 10.1 | A member-facing refund request | pending | - |
| 10.2 | An invoice / receipt (the member can see their charges at all) | pending | - |
| 10.3 | A card update | pending | - |
| 10.4 | A membership pause / freeze | pending | - |
| 10.5 | A dunning ladder the site owns | pending | - |
| 10.6 | A chargeback / dispute state | pending | - |
| 10.7 | A gift purchase | pending | - |
| 10.8 | Tax / receipt numbering | pending | - |
| 10.9 | Payout processing - the earnings page says outright that it does not ship | pending | - |
| 10.10 | The level numbering: the yearly plan is level 9 and lifetime is 10, but **no gallery exceeds level 3** | pending | - |
| 10.11 | An honest answer to downloads - the terms forbid it and the tooltip says "saving is disabled", but a member can still save the 720p file they are served | pending | - |
| 10.12 | A download allowance or a watermark tier | pending | - |

---

## Phase 11 - ops hardening

| id | what | status | commit |
|---|---|---|---|
| 11.1 | The CSRF token is **one per session for the session's life**, not per form and not rotated on login. One leaked token is valid for every POST the session can make | pending | - |
| 11.2 | `XSendfilePath /var/www/gallery/storage` is broader than it needs to be | pending | - |
| 11.3 | The Braintree checkout page overrides the whole CSP with `unsafe-inline` | pending | - |
| 11.4 | The title image upload accepts an SVG with no content validation (admin-only, and currently unreachable) | pending | - |
| 11.5 | The restore-drill script is **not in the repo** - the restore path is partly outside version control | pending | - |
| 11.6 | `.env` is excluded from the backup - a full-box loss needs the secrets re-supplied by hand | pending | - |
| 11.7 | `RateLimiter` is a single global JSON file with an exclusive `flock` and a whole-file rewrite per request | pending | - |
| 11.8 | The dashboard's php-fatal alert is throttled to hourly and nobody acted on 76 of them | pending | - |

---

## New checks (the house style)

Every new check must be **proven to bite**: revert the fix, watch it fail,
restore the fix. A check that has never failed is not a check.

`smoke.housekeeping.columns_exist` (every column a housekeeping query
names must exist in the real schema) · `smoke.housekeeping.isolated` (one
step cannot abort the rest) · `smoke.braintree.can_cancel` ·
`smoke.money.ledger` · `smoke.access.one_predicate` ·
`smoke.access.browse_filtered` · `smoke.access.share_link` ·
`smoke.live.no_stream_key_for_members` · `smoke.health.not_public` ·
`smoke.rate_limits.present` · `smoke.media.cacheable` ·
`smoke.sitemap.covers_the_site` · `smoke.seo.no_duplicate_titles` ·
`smoke.seo.alt_text` · `smoke.seo.breadcrumbs` · `smoke.seo.og_images` ·
`smoke.seo.canonicals` · `smoke.csrf.per_form`.

---

## How to resume after a crash

1. `git -C /media/webdev/Media/gallery-full status` + `git log --oneline -5` -
   the uncommitted files are the work in flight, never throw them away.
2. Read the status table above and update it.
3. `/tmp/opencode` is **wiped by every reboot** - the CDP harness,
   `login.sh` and the probe scripts have to be recreated. The pattern:
   headless Chrome with `--remote-debugging-port`, driven by node 24
   (which has `fetch` and `WebSocket` built in), using
   `Network.setCookie` (with `domain` and `path: '/'` - a URL-only cookie
   takes the path of that URL and the server reads the wrong one),
   `Input.dispatchTouchEvent` for real taps (`.click()` does not honour
   overlays) and `Page.captureScreenshot`.
4. Never `pkill -f <pattern>` from the ssh command line: the pattern
   matches the shell's own command line and kills the shell. Put the
   pattern in a `.sh`/`.bat` on the box instead.
5. The deploy script needs `DEPLOY_HOST`, `DEPLOY_PASS` and `DEPLOY_ROOT`,
   and it must run with a **>= 5 minute** timeout.
6. Prod smoke runs as `www-data` and needs `sudo rm -rf
   /tmp/gallery-analytics-rot` first, or `smoke.analytics.rotated_discovery`
   fails on a root-owned dir.
7. `php tests/smoke.php` -> 963 checks at the start of this programme. The
   count may only go up.

**A NOTE ON THE MEASUREMENTS.** The performance and SEO numbers came from
a read-only audit that measured the LIVE prod box: MySQL's `general_log`
and `long_query_time` were temporarily changed and then **restored**
(verified: `general_log=0`, `log_output=FILE`, `long_query_time=1.0`),
the diagnostic scripts were deleted, and the application tree was not
modified. No production data was touched.
