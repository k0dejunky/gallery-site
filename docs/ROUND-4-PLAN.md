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

**Decisions taken (2026-10-22, for phases 13 and 14):**
- **Scope: both, hardening first.** The hardening pass (phase 13) runs to
  completion, then the member money (phase 14) in the same programme.
- **The level numbering:** Yearly and Lifetime are the SAME tier as the
  monthly plan they extend (a yearly Silver is level 1). They become "a
  discount, not a tier". The tiers stay 0-3 (Free/Silver/Gold/Platinum),
  so the gallery `min_level` range and the 0-3 clamps stay valid.
- **The download claim:** the site stops promising what it cannot deliver.
  The tooltip and the terms say what is true. No `Content-Disposition`
  enforcement, no download allowance, no watermark tier.
- **`.env` stays out of the backup.** The vault stays the single source of
  truth for the secrets; the restore drill documents re-supplying them.
- **There is no creator programme.** Payout processing is dropped - no
  creator balance, no payout ledger, no transfer run. The earnings page
  keeps saying it does not ship.
- **There is no tax.** No VAT fields, no tax engine, no jurisdiction. Only
  the **receipt number** survives, and it is a deterministic
  `INV-<purchase id>` - no sequence table, no lock, no new concept.
- **A per-form CSRF token is not adopted.** The synchroniser pattern with
  a rotation on every privilege change is the OWASP recommendation, and
  per-form tokens would break every long-lived admin form (a form left
  open while other requests happen would come back as a 419).

**Sequencing:** the live defects first, on their own -> the media path ->
the heavy admin pages -> the cache and the queries -> discoverability ->
the member-facing money -> ops hardening -> the last hard edges -> the
member money the member can see. Verification follows the house rule every
time: **local -> dev -> prod -> commit last** (one commit per item, the
check that locks it in travels in the same commit).

---

## Where the work stands (updated 2026-10-22)

| Phase | What | Status | Commit |
|---|---|---|---|
| 0 | The plan anchor (this file) | done | - |
| 1 | The dead cron, and the alerting that missed it | **done** | `1882ecb`, `6a8108d` |
| 2 | The money: cancel, refund, grace, accounting | **done** | `47015d3`, `951454b`, `40abf0c`, `a51a9d3`, `627d117` |
| 3 | One access decision, not five | **done** | `9025020` |
| 4 | The exposure: stream key, keys, health, default page | **done** | `860ce5e`, `a768c6e`, `39ee933` |
| 5 | Abuse: rate limits and moderation | **done** | `b42881b`, `56b9738` |
| 6 | The media path | **partial** | `b73b21f` |
| 7 | The heavy admin pages | **done, except the incremental aggregator** | `b73b21f`, `208fa09`, `61ea184` |
| 8 | The cache and the queries | **done, except the N+1s in the media path** | `91d9f8b`, `b73b21f` |
| 9 | Discoverability | **done** | `c0137e7`, `61ea184` |
| 10 | The member-facing money | **not started** | - |
| 11 | Ops hardening | **not started** | - |
| 12 | The operator app, rebuilt | **done** | `40b4039`, `57ba5ff`, `ffa5db8` |
| 13 | The last hard edges | **planned, not started** | - |
| 14 | The member money, final scope | **planned, not started** | - |
| Final | local smoke/unit -> dev -> prod verify -> commit + push | **done for phases 1-9 and 12** | - |

### The last sweep (2026-10-21, after phase 12)

Nine more items closed, each with a check that was proven to bite:

| what | the number | commit |
|---|---|---|
| The chat SSE loop spun on the database while a member was typing | 2 queries x thousands of iterations per 30 s window | `dc4fd12` |
| The log aggregator re-read every rotated log to store two days | 304,523 lines -> 85,573 lines, 20.4 s -> 8.6 s | `367d3f7` |
| The media route asked the database the same five questions per file | 5 queries -> 1, 1.18 ms -> 0.53 ms | `02f8d2f` |
| The CSP report-uri was relative, the feeds were uncacheable, the pagination said nothing | the crawler had nothing to work with | `4ef42e1` |
| X-Sendfile could serve the whole storage tree | the error log and the database backups were in reach | `751b30a` |
| The CSRF token was never rotated | one leaked token was good for every POST | `c5ddb22` |
| The guest grid could not use AVIF or WebP | 450 blurred covers, 0 modern copies | `4189fd7` |
| The rate limiter serialised every request on one lock | 16 shards, 300 parallel reads -> 76 x 429 as before | `cd781f9` |

The smoke suite is **1005 checks** (was 989).

### Phase 12 - the operator app, rebuilt (2026-10-21)

The operator app was rebuilt on **Jetpack Compose** and shipped as **v3.0**
(versionCode 62, sha256 `3fa02e83f0cd1f89bfe44afff03bb347646b4274429dd290b92b6ec18805c85c`,
signed with the usual key `b6881e87...f89b`, so it installs over v2.50).

**The bugs the rebuild fixed, and why they mattered:**

| what | why it mattered |
|---|---|
| The reply field measured **ZERO width** (the XML said `0dp` with no weight) | no reply could be typed at all - the single worst bug in the app |
| The broadcast lived in the Activity | the stream died when the operator switched apps or locked the phone |
| A rotation tore the encoder down and rebuilt it in a 400 ms window | the stream usually died, while the screen still said "Rotating…" |
| The quiet hours never silenced anything | the notification went to an `IMPORTANCE_HIGH` channel, and the CHANNEL decides the sound on Android 8+ |
| The server address and token could only be READ | a moved server or a rotated token meant reinstalling the app |
| `site_timezone` was read out of the preferences but never written | every timestamp was in a hardcoded zone |
| A failed connection still looked live | the operator could not tell a dead broadcast from a live one |

**The new screens:** inbox, conversation, live console, show schedule,
settings - all Compose, one design system (`ui/theme`, `ui/components`).
The broadcast is a **foreground service** (`live/LiveBroadcastService`) with
a real state machine (`live/BroadcastState`) and the encoder owned by the
service, not the Activity.

**The new server capability (commit `40b4039`):** live-show scheduling with
a **server-authoritative** countdown on the member's live page, and the
per-message actions the app never had (pin / unpin / delete a message,
block a member). Migrations `087_live_shows` and `088_chat_message_actions`.
The show time is converted to UTC in **one place** (`LiveShow::create()`),
so a second caller cannot shift it a second time.

**The tests:** the Compose screens are **rendered** in Robolectric (native
graphics), so the layout is measured and screenshotted instead of parsed as
XML - `app/src/testDebug/.../ComposerUiTest.kt` asserts the reply field is
wider than 100dp (the old bug), `ScreenshotTest.kt` writes
`app/build/screenshots/*.png` for every screen. The suite is **59 tests**
(55 JVM + 4 Compose UI), all green, `lintRelease` clean.

### Baseline

- **local** `/media/webdev/Media/gallery-full` - `main`, clean, in sync with
  `origin/main`.
- `php tests/smoke.php` -> **1005 checks, all passed** (963 at the start).
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

### What phases 2 to 5 changed

| phase | what it did |
|---|---|
| 2 | A Braintree cancel now cancels the billing; a refund now refunds the money; only collected money is revenue; a failed card is a bump with a 7-day grace and a card-update form, not a lockout |
| 3 | ONE place decides what a member can see: the photo's bytes are served at the STRICTEST level across its galleries, the gallery's grid is filtered by the member's level, the in-player browser filters by level, and a collection only accepts a published, visible gallery |
| 4 | A member can no longer publish into the operator's live stream (publishing needs a second secret only the operator has); the health detail needs a credential; the Apache "It works" page is gone from the domain root; robots.txt is served from the origin root by a config that is IN the repository |
| 5 | The live chat, comments, replies, reactions and ratings are rate limited |

**WHAT IS STILL OPEN.** The plan says so rather than pretending:

- **6.1** - Apache serving the public media variants directly. The
  biggest capacity win (77 FPM requests per guest page view) and the
  riskiest change, because the preview variants of a SECRET gallery must
  not become public. Not done.
- **6.2, 6.3, 6.4** - the session-bound media token, the nine queries
  per media request, and AVIF for guests. Not done.
- **7.1** - the admin dashboard's disk walk is fixed (7.1), but the
  ANALYTICS page is still 1.8s: its two GROUP BY url queries scan
  the table, because a day RANGE breaks the index order for the
  grouping. The fix is to read the rollup table instead, which needs
  two more columns in it.
- **7.4** - the log aggregator still re-parses the whole window every
  hour. An incremental tail-follow is the fix. Not done.
- **8.6** - the FPM pool. The media path is the lever.
- **9.3** - the duplicate gallery titles and the missing descriptions
  are CONTENT, not code.
- **9.11** - the crawlable surface is still `no-store` with a session
  cookie, which is correct for a page with a CSRF token.
- **Phase 10** (the member-facing money) and **phase 11** (ops
  hardening) are untouched.
- **4.1** - the AdminTable rollout is untouched: 33 admin views still
  carry a bare table.

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
| 2.1 | `BraintreeGateway::cancelSubscription()` + wire it into `Subscription::cancel()` beside the PayPal branch | `app/Core/BraintreeGateway.php`, `app/Models/Subscription.php` | done | 47015d3 |
| 2.2 | The refund path: `BraintreeGateway::refund()` exists and is **never called**; the admin's "refund" only flips a local DB status | `app/Controllers/ModerationController.php`, `app/Core/PayPalGateway.php` | done | 951454b |
| 2.3 | A grace period on past due (the code's own comment promises one) + a **card-update form** on `/membership/my` | `app/Models/Subscription.php`, `app/Controllers/WebhookController.php`, `views/membership/my.php` | done | 40abf0c |
| 2.4 | Offline tips must not be counted as revenue | `app/Models/Purchase.php`, `app/Controllers/EarningsController.php` | done | 951454b |
| 2.5 | An idempotency key on the one-off charges - a double-click today creates two Braintree sales | `app/Controllers/PurchaseController.php` | **not done** | - |
| 2.6 | An append-only money ledger; `purchases` rows are mutated in place and an admin can set any status on any row | a new model + a migration | **not done** | - |
| 2.7 | A member-facing refund request, an invoice/receipt, a pause, a dunning ladder, a chargeback state | phase 10 | done | a51a9d3 |

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
| 3.1 | ONE predicate, used by all five call sites | `app/Models/Photo.php` + the 3 controllers | done | 9025020 |
| 3.2 | The cross-level photo case decided deliberately (per-photo level, or per-gallery) | `app/Models/Photo.php`, `app/Models/Gallery.php` | done | 9025020 |
| 3.3 | `/browse/galleries` has **no level filter at all** - any logged-in member can enumerate every premium gallery's title and its real 400x300 cover | `app/Controllers/GalleryController.php` | done | 9025020 |
| 3.4 | The public collection share link leaks premium thumbnails: `Collection::addGallery()` never checks the gallery exists, is published, or is within the member's tier | `app/Models/Collection.php` | done | 9025020 |
| 3.5 | `/images/{id}` and the "Load more" AJAX path ignore `Purchase::userUnlocked()` | `app/Controllers/ImageController.php`, `app/Controllers/GalleryController.php` | done | 9025020 |

---

## Phase 4 - the exposure

| id | what | files | status | commit |
|---|---|---|---|---|
| 4.1 | **The RTMP publish key must never reach a member.** `/live/state` returns it and the recording is auto-published to a paid gallery | `app/Controllers/LiveController.php` | done | 860ce5e, fixed in 8b9a6d7 |
| 4.2 | The cron key is world-readable in `/etc/cron.d/` (0644) and visible in `ps` | `bin/apply_cron.php` | done | `6a8108d` |
| 4.3 | `/gallery/health` is public and discloses backup, cron and worker state | `app/Controllers/HealthController.php` | done | a768c6e |
| 4.4 | `https://amethyst2213.com/index.html` serves the **Apache "It works" default page** | a repo-owned Apache conf | done | a768c6e |
| 4.5 | `robots.txt` is **not in the repo** - `install.sh` never writes the root alias, so a fresh install has no robots.txt where it is honoured | `install.sh`, `config/` | done | a768c6e |
| 4.6 | Narrow the `GALLERY_CHAT_KEY` blast radius: per-device attribution, rotation, scope | `app/Controllers/ChatBridgeController.php` | **not done** | - |

---

## Phase 5 - abuse: rate limits and moderation

| id | what | files | status | commit |
|---|---|---|---|---|
| 5.1 | `POST /live/chat/send` has **no rate limit at all** | `app/Controllers/LiveController.php` | done | b42881b |
| 5.2 | Comments, reactions and ratings have no rate limit | `app/Controllers/CommentController.php`, `app/Controllers/GalleryRatingController.php` | done | b42881b |
| 5.3 | A moderation queue for live chat - `live_chat_messages` has no admin screen at all | a new admin view | **not done** | - |
| 5.4 | The bridge attachment endpoint is the only bridge route with no `throttle()` | `app/Controllers/ChatBridgeController.php` | done | b42881b |
| 5.5 | A per-content "report this" button + a report queue | a new model + a migration | done | 627d117 |

---

## Phase 6 - the media path

| id | what | files | status | commit |
|---|---|---|---|---|
| 6.1 | **`thumb` and `blur` go straight from Apache**, no PHP hop. A guest `/galleries` view drops from **77 FPM requests to ~5** | `app/Controllers/StorageController.php`, a repo-owned Apache conf | done | 39ee933 |
| 6.2 | The media token becomes **time-limited, not session-bound** - today every non-thumb URL is unique per session, so the `immutable` header is dead and a new session re-downloads the grid | `app/Core/helpers.php` | done | 56b9738 |
| 6.3 | 9 DB queries per media request -> **1** | `app/Controllers/StorageController.php`, `app/Models/Photo.php` | done | `02f8d2f` |
| 6.4 | Guests get AVIF/WebP too - the blurred cover had **no** modern-format copy at all | `views/partials/gallery_card.php`, `bin/avif_backfill.php` | done | `4189fd7` |
| 6.5 | The variant ladder backlog: 321 of 15,626 videos have a 480p rung | `bin/video_rendition_worker.php` | pending | - |

---

## Phase 7 - the heavy admin pages

| id | what | files | status | commit |
|---|---|---|---|---|
| 7.1 | The admin dashboard does **~280,000 `stat()` calls** per load (3.4-4.0s, 3.7-4.0s of FPM CPU) | `app/Controllers/AdminController.php` | pending | - |
| 7.2 | `/admin/system`: 83 `SHOW COLUMNS` queries + 20,500 file stats + a **synchronous 8.9s Ollama generation** in the request path | `app/Controllers/SystemController.php` | pending | - |
| 7.3 | The analytics page **full-scans `web_visits` (272,942 rows) four times** - 1.03s of its 1.44s. The covering indexes | `app/Models/WebStats.php` + a migration | done | b73b21f |
| 7.4 | The log aggregator **re-parses the whole 2-day window every hour** (19s/hour, 6.5M lines/day re-read) | `app/Core/AccessLogAggregator.php` | done - the files outside the window are skipped, not re-parsed | `367d3f7` |
| 7.5 | The admin re-parse runs up to 31 days **inside one HTTP request** (~5 min) with no lock of its own | `app/Controllers/AnalyticsController.php` | done | 61ea184 |

---

## Phase 8 - the cache and the queries

| id | what | files | status | commit |
|---|---|---|---|---|
| 8.1 | A lock on `Cache::remember` / `PageCache::fragment` - a cold cache makes N concurrent requests all run the producer, and `bump('listings')` invalidates the whole guest site at once | `app/Core/Cache.php`, `app/Core/PageCache.php` | done | b73b21f |
| 8.2 | The page cache is keyed on the **raw query string** - every `?utm_*` is a separate 393 KB entry | `app/Core/Router.php` | done | b73b21f |
| 8.3 | Redis has **no `maxmemory`** and `noeviction` | the Redis config on the box | pending | - |
| 8.4 | The N+1s: the wall's comments (20), `Gallery::categories()` inside `recommended()` (8), `Comment::countFor()` per wall post in the admin engagement table (30) | `views/wall.php`, `app/Models/Gallery.php`, `views/admin/engagement.php` | done | 91d9f8b |
| 8.5 | The operator app's SSE stream has a **busy loop** - the typing path re-queries with no sleep | `app/Controllers/ChatBridgeController.php` | done | `dc4fd12` |
| 8.6 | The FPM pool: `pm.max_children=30` and each SSE connection holds a worker for 30s | the FPM pool config | pending | - |

---

## Phase 9 - discoverability

Index the previews, not the full media. The paywall markup stays as it is.

| id | what | files | status | commit |
|---|---|---|---|---|
| 9.1 | The sitemap covers **178 of ~2,711 crawlable URLs**. Add `/galleries` and `/creator`, drop the 27 contentless category pages, paginate, and **cache it** (it is `no-store` + `Set-Cookie` today) | `app/Controllers/StaticPageController.php` | done | c0137e7 |
| 9.2 | The titles: the site name is appended to a title that already has it - "amethyst2213 - amethyst2213" on two pages, "Amethyst - amethyst2213 - amethyst2213" on a third | `views/layout.php`, `views/home.php`, `views/gallery/index.php`, `views/creator/profile.php` | done | c0137e7 |
| 9.3 | 8 duplicate-title groups over 16 published galleries, and 21 of 59 with no description | the admin gallery form + the listing | pending - **content**, not code | - |
| 9.4 | **0 of 7,058 photos have a caption** -> every image is `alt=""` and every `/images/{id}` page is titled "Full-size image". A caption or a filename fallback fixes both | `views/gallery/show.php`, `views/gallery/image_full.php`, `views/partials/gallery_card.php` | done | c0137e7 |
| 9.5 | `BreadcrumbList` - the breadcrumbs are VISIBLE on every gallery, category, tag, image and video page and none of them are in the JSON-LD | `views/layout.php` | done | c0137e7 |
| 9.6 | The og tags: `og:type` is "website" everywhere, there are no `og:video` tags, and the og:image is 400x300 under a `summary_large_image` card | `views/layout.php`, `views/video/player.php` | done | c0137e7 |
| 9.7 | `og:image` is **absent** on `/galleries`, `/galleries/category/*` and `/creator`, and **404s for scrapers** on `/membership` (relative + a session-bound token) | the 4 views | done | c0137e7 |
| 9.8 | The canonicals: `/creator` has none (so `og:url` falls back to the site root), the tag pages have none, and the category pagination canonicalises to page 1 | `views/creator/profile.php`, `views/gallery/tag.php`, `views/gallery/category.php` | done | c0137e7 |
| 9.9 | A video sitemap - there is none, and the video pages are in no sitemap at all | `app/Controllers/StaticPageController.php` | done | 61ea184 |
| 9.10 | The 51 paid galleries need internal links a crawler can follow | `app/Models/Gallery.php` | done - the rail follows the gallery's own level, verified on prod | `c0137e7` |
| 9.11 | `robots.txt` into the repo, `install.sh` writes the root alias, the `Sitemap:` line points at the real URL | `public/robots.txt`, `install.sh` | done - the Sitemap line is now rewritten from APP_URL | `751b30a` |
| 9.12 | The crawlable surface is uncacheable: every HTML page and both feeds are `no-store` + `Set-Cookie`. Plus a relative CSP `report-uri` and no `Link: rel=prev/next` | `public/index.php`, `app/Core/Router.php` | done - the feeds and the sitemaps are public, the report-uri is absolute, the pagination sends `Link` | `4ef42e1` |

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
| 11.1 | The CSRF token is **one per session for the session's life**, not per form and not rotated on login | `app/Core/Csrf.php`, `app/Core/Auth.php` | done - rotated on the login, the logout and the step-up | `c5ddb22` |
| 11.2 | `XSendfilePath /var/www/gallery/storage` is broader than it needs to be | `config/gallery-alias.conf`, `install.sh` | done - only the uploads and the APK | `751b30a` |
| 11.3 | The Braintree checkout page overrides the whole CSP with `unsafe-inline` | pending | - |
| 11.4 | The title image upload accepts an SVG with no content validation (admin-only, and currently unreachable) | pending | - |
| 11.5 | The restore-drill script is **not in the repo** - the restore path is partly outside version control | pending | - |
| 11.6 | `.env` is excluded from the backup - a full-box loss needs the secrets re-supplied by hand | pending | - |
| 11.7 | `RateLimiter` is a single global JSON file with an exclusive `flock` and a whole-file rewrite per request | `app/Core/RateLimiter.php` | done - sharded over 16 files | `cd781f9` |
| 11.8 | The dashboard's php-fatal alert is throttled to hourly and nobody acted on 76 of them | pending | - |

---

## Phase 13 - the last hard edges

The programme's last hardening pass: seven items, each with a check that
is proven to bite. Every one of them was measured on the live box first -
the numbers below are measurements, not guesses.

| id | what | files | status | commit |
|---|---|---|---|---|
| 13.1 | The video-rendition worker is **stalled**: it spends ~90 s of every 10-minute tick rebuilding ONE poster and produces nothing else. The poster is a **0-byte file**, so the build "succeeds", the poster is never "padded", and it is rebuilt forever | `app/Core/helpers.php`, `bin/video_rendition_worker.php` | planned | - |
| 13.2 | The level numbering: prod's Yearly is level 9 and Lifetime level 10 (hand-edited rows), the seed says 1, the tier labels only know 0-3, and **no gallery exceeds `min_level` 3** | `app/Models/Subscription.php`, `app/Models/TrialLink.php`, `app/Models/Stats.php`, `app/Controllers/PlanController.php`, a migration | planned | - |
| 13.3 | The theme title-image upload is **broken** (the file is served from a path nothing routes, and the watermark silently stops with it), it accepts an **SVG with no validation**, and `update()` is gated on `requireLogin()` - so a **member** can restyle the site | `app/Controllers/ThemeController.php`, `app/Models/Theme.php`, `views/layout.php`, `install.sh` | planned | - |
| 13.4 | The Braintree pages override the whole CSP with `'unsafe-inline'` and **no nonce** (the nonce the view emits is inert), and both the override AND the site policy are missing `https://assets.braintreegateway.com` from `frame-src` - the Hosted Fields iframe | `public/index.php`, `app/Controllers/MembershipController.php` | planned | - |
| 13.5 | The tooltip and the terms say saving is disabled; nothing enforces it (the media is served inline, no `Content-Disposition`) | `views/partials/gallery_card.php`, `views/terms.php` | planned | - |
| 13.6 | The php-fatal alert is mailed once an hour and **nobody acted on 76 of them** - the log is the only record | `app/Core/Housekeeping.php`, `app/Controllers/SystemController.php`, `views/admin/system.php` | planned | - |
| 13.7 | `.env` stays out of the backup. No code change; the restore drill's output and this plan say so | `scripts/restore-drill.sh` | decided - no change | - |

### 13.1 The worker that is burning the box right now

**Measured on prod (2026-10-22).** Every tick, every ten minutes:

```
[2026-10-22 00:40:01] AI worker busy (categorizer.lock); skipping this tick
[2026-10-22 00:30:38] poster g6abc06a18d7f32.79682750.mp4 1440x1920
[2026-10-22 00:31:29] tick done: built=1 failed=0 skipped=0 candidates=333 seconds=88
```

and on disk:

```
-rw-rw-r-- 1 www-data www-data       0 Oct 22 00:50 poster_g6abc06a18d7f32.79682750.mp4
-rw-rw-r-- 1 www-data www-data 2635918 Sep 29 18:42 g6abc06a18d7f32.79682750.mp4
-rw-rw-r-- 1 www-data www-data  110822 Sep 19 19:08 web_g6abc06a18d7f32.79682750.mp4
```

Three defects make one loop:

1. `create_video_frame()` returns success on `is_file($dest)` - a **0-byte**
   output counts as a built poster (`app/Core/helpers.php:2612`).
2. `video_poster_is_padded()` cannot read a 0-byte file, so it reports
   "not padded" - the poster is stale again, so it is rebuilt, so it is
   0 bytes again (`app/Core/helpers.php:701`).
3. The tick's whole budget goes into it, so the other 332 candidates are
   never reached. **The ladder rungs for that video are already complete** -
   the poster is the only thing left.

The work:

| step | where | what |
|---|---|---|
| a | `helpers.php:2612` | the capture must reject an **empty or undecodable** output, and delete the broken file |
| b | `helpers.php:2423` | `create_video_thumbnail()` must not leave a 0-byte destination behind |
| c | `video_rendition_worker.php:315-328` | a failed build counts as `failed`, not `built` - and a source that keeps failing is **backed off** (a state file next to `rendition.lock`) so one bad video cannot eat every tick |
| d | `helpers.php:701` | a 0-byte poster is *broken*, not "not padded" |
| e | `video_rendition_worker.php:308` | the poster is **JPEG bytes in a `.mp4` name**; a broken one must never reach a member as `video/mp4` |

**One diagnostic run is needed** to see WHY that one capture comes back
empty. The hardening is certain; the root cause is not, and the plan does
not pretend otherwise.

### 13.2 The level numbering

**Measured on prod.** The plans are `Silver 1 | Gold 2 | Platinum 3 | Chat
add-on 1 | Yearly 9 | Lifetime 10`; the galleries are `min_level` 0 x 13,
1 x 174, 2 x 12, **3 x 3** (nothing above 3); two subscriptions carry
`access_level` 9 and 10. So a yearly member is seven levels above
Platinum and can open exactly the same content, and their tier label
reads "Level 9" (`Subscription::levelLabel()`) or, worse, **"Platinum"**
(`TrialLink::levelLabel()`'s `default` arm).

There is **no check at all** today pinning the level range, and the plan
editor's level field has **no upper bound** (`PlanController.php:282-301`
only rejects a level below 1) - that is how prod got 9 and 10.

Under the decision (Yearly/Lifetime = the same tier as the monthly plan):

| step | where | what |
|---|---|---|
| a | a new `089_*.sql` + `schema.sql` | the Yearly and Lifetime plans go to level **1**; the two subscriptions at 9/10 come down to their plan's level. Idempotent SQL - an applied migration's checksum is immutable (`scripts/migrate.php:148-153`) |
| b | `PlanController.php:282-301` | cap the level at 3, with a message ("tiers are Free/Silver/Gold/Platinum") |
| c | `TrialLink.php:105-113` | delegate to the canonical `Subscription::levelLabel()`; the second label map is why level 9 reads "Platinum" |
| d | `Stats.php:101` | `$counts = [0..4]` - a gallery above 4 is invisible in the dashboard |
| e | the 0-3 clamps (`GalleryController.php:897`, `ImportController.php:118`, `VideoEditorController.php:478`, `ImportSettings.php:149`, `ModerationController.php:142`, `SubscriptionController.php:136`, `TrialLink.php:78`, `Subscription.php:271`) | they stay valid under this decision, but they must agree with (b) |

### 13.3 The title image: broken, an SVG, and a member's reach

Three findings, one item:

- **Broken.** The upload lands in `storage/uploads/` and the layout links
  `/gallery/storage/uploads/...` - a path **no route and no alias serves**.
  So an uploaded title image is a 404, and `watermark_logo_path()`
  (`helpers.php:1855-1874`) resolves it wrongly, so **the watermark
  silently stops working** the moment a custom title image is set.
- **An SVG with no validation.** The allowlist at
  `ThemeController.php:108` takes `svg` with no `finfo`, no
  `getimagesize` and no size cap. The house precedent is
  `smoke.sec.upload_no_active_content`, which already asserts that the
  *other* allowlists (`MediaUploader`, `ChatMessage`) contain no `svg` -
  this is the one that was missed.
- **A member can reach it.** `ThemeController`'s constructor only requires
  `Auth::requireLogin()` (`:17-21`), and a non-admin scope degrades to
  `site` (`:73-75`), so a logged-in member can POST the theme update and
  restyle the live site.

The work: require the **theme** permission on `update()`, validate the
upload, drop `svg`, write to `public/assets/images/` (the directory the
layout already links), version it with the existing `asset_url()` mtime
convention so a replaced file is not served stale for 30 days, make that
directory writable in `install.sh` (nothing writes into `public/` at
runtime today), and fix `watermark_logo_path()` for the new path.

### 13.4 The Braintree CSP

The override at `MembershipController.php:156` and `:333` is a whole-CSP
replacement with `'unsafe-inline'` in `script-src` and **no nonce** - so
the `nonce="<?= csp_nonce() ?>"` the views already emit
(`braintree_checkout.php:127-130`, `my.php:85-86`) is inert, and the
`report-uri` is lost.

The bigger finding: **both** the override and the site policy are missing
`https://assets.braintreegateway.com` from `frame-src`, and that is the
Hosted Fields iframe. `connect-src` is also missing
`client-analytics.braintreegateway.com` and `*.braintree-api.com`.
`views/partials/card_checkout.php:80-81` injects Braintree under the
STRICT site policy, so the same gap applies there.

The work is therefore in the **site policy** (`public/index.php:137-155`),
not in a per-controller override: add the origins Braintree actually
needs, then **delete both overrides** so the pages run on the site's
strict policy (nonce + `strict-dynamic`) and the `report-uri` survives.
**Whether the card-update form is broken today is a question this item
will answer** - it is not assumed either way.

### 13.6 The fatal alert nobody saw

`Housekeeping::alertOnPhpFatals()` (`:520-577`) tails
`storage/logs/php-error.log` from a byte offset
(`storage/logs/.php_fatal_alert_offset`), counts the fatals and mails
`Mailer::adminAlert('php-fatal', ..., 3600)` - once an hour, with the
throttle state in `storage/logs/alerts.state`. The log is the only
record, and 76 fatals were mailed and ignored.

The work: a read-only public accessor on `Housekeeping` (the same regex
as `:558`, **without** advancing the offset - the housekeeping owns the
offset), cached by the System page's own file cache
(`storage/cache/system-php-fatals.json`, 300 s - the `aiStatus()` pattern
at `SystemController.php:98-115`), and a `.sys-card` in the 2-up grid
(`views/admin/system.php:40-346`, the `:114-129` pattern). "Last alerted
at" comes for free from `alerts.state`.

---

## Phase 14 - the member money, final scope

The twelve open rows of phase 10, with the rows the 2026-10-22 decisions
close **removed** rather than left hanging:

| row | fate |
|---|---|
| 10.1 refund request | **14a** |
| 10.2 invoice / receipt | **14a** |
| 10.3 card update | **14a** |
| 10.4 membership pause | **14a** |
| 10.5 dunning ladder | **14b** |
| 10.6 chargeback / dispute | **14b** |
| 10.7 gift purchase | **14c** |
| 10.8 tax / receipt numbering | **the tax half is dropped** (no tax); the receipt number is `INV-<purchase id>` and rides with 14a |
| 10.9 payout processing | **DROPPED** - there is no creator programme |
| 10.10 level numbering | **done in 13.2** |
| 10.11 an honest answer to downloads | **done in 13.5** |
| 10.12 a download allowance / watermark tier | **DROPPED** - with no false promise there is nothing to enforce |

### 14a - what a member can see and do

| id | what | where it starts from |
|---|---|---|
| 14a.1 | An **invoice list** with a receipt number: the charges, the amount, the date, the gateway, the status and the ledger events. The receipt number is `INV-<purchase id>` - deterministic, no sequence table | reuse `ExportController.php:27-43`'s query shape (`purchases` joined to `users` and `galleries`) with `WHERE p.user_id = ?` instead of the date window |
| 14a.2 | A **refund request**: the member asks, the admin approves or declines, approval calls the existing `Purchase::refundAtGateway()` and appends the ledger event | `Purchase.php:218-271`; the request state rides in `purchase_events` (append-only, free `event` column) rather than a new table |
| 14a.3 | A **card update for an active subscription**. The form and the controller already work - the only thing gating it is `status = 'past_due'` (`MembershipController.php:135`). The other three blockers (a `BT-` `transaction_ref`, a Braintree processor, a client token) apply equally to an active subscription | `MembershipController.php:121-170` and `:236-308`. NOTE: the vaulted `payment_method_token` is **not stored locally**, so "which card is on my membership" needs a column - only added if that is wanted |
| 14a.4 | A **membership pause**: the expiry clock stops. A `paused_until` column beside the existing `PAST_DUE_GRACE_DAYS` mechanism, the access predicates exclude a paused subscription, and the expiry shifts by the paused duration. The status enum is **not** touched (no `ALTER ... ENUM`) | `Subscription.php:21-44` |

### 14b - what the site owns

| id | what | where it starts from |
|---|---|---|
| 14b.1 | A **dunning ladder the site drives**: retry days, a **downgrade** step (a Gold member whose payment fails drops to Silver instead of being cancelled), then an explicit cancel. Today the cadence is the gateway's | the existing sequence is `payment_failed` mail -> `past_due` mail -> 7-day grace -> expiry (`Lifecycle.php:50-78`, `Subscription.php:21-44`, `Housekeeping.php:414-441`); it needs a schedule table and a job |
| 14b.2 | A **dispute / chargeback state**: `purchase_events.event` already has the room (the column comment names `chargeback`) but **nothing writes it**. A `disputed` status on `purchases` (an enum ALTER) plus the ledger events, and a disputed purchase stops counting as revenue | `Purchase.php:14-20` (the status enum), `schema.sql:1118-1135` (the ledger) |

### 14c - gifting

| id | what | where it starts from |
|---|---|---|
| 14c.1 | A **member-facing gift purchase**, built on the mechanism that already exists: a sale code (`SaleCode::generate()`) pinned to a plan, 100 % off, one use. The member buys the plan as a gift, the site issues the code, the recipient redeems it at checkout through the existing `sale_code` field, and the member sees "gifts I sent" with each code's state. **No new recipient entity, no delivery token, no redemption audit** - the sale code IS the token, and the redemption is already recorded on the subscription (`sale_code_id`) and in the audit log | `SaleCode.php:48-70`, `MembershipController.php:364` and `:599-612` |

---

## New checks (the house style)

Every new check must be **proven to bite**: revert the fix, watch it fail,
restore the fix. A check that has never failed is not a check.

`smoke.housekeeping.columns_exist` (every column a housekeeping query
names must exist in the real schema) · `smoke.housekeeping.isolated` (one
step cannot abort the rest) · `smoke.braintree.can_cancel` ·
`smoke.money.ledger` · `smoke.access.one_predicate` ·
`smoke.access.browse_filtered` · `smoke.access.share_link` ·
`smoke.live.no_stream_key_for_members` ·
`smoke.live.publish_secret_reaches_mediamtx` (the real url builder run
through a port of the app's URL parser AND MediaMTX's path/query parser:
the path must stay the bare stream key and the secret must arrive as the
query - the two parsers between the app and the auth webhook) ·
`smoke.health.not_public` ·
`smoke.rate_limits.present` · `smoke.media.cacheable` ·
`smoke.sitemap.covers_the_site` · `smoke.seo.no_duplicate_titles` ·
`smoke.seo.alt_text` · `smoke.seo.breadcrumbs` · `smoke.seo.og_images` ·
`smoke.seo.canonicals` · `smoke.csrf.per_form` ·
`smoke.seo.crawlable_surface` (the CSP report-uri is absolute, the feeds
and the sitemaps are publicly cacheable, and a paginated page sends its
`Link` header - which must survive the page cache) ·
`smoke.seo.guest_covers_are_modern` (the blurred cover the guest and the
crawler see has an AVIF and a WebP copy) ·
`smoke.ops.rate_limiter_sharded` (the limiter's state is sharded, the one
global file is gone, and the limit is still enforced) ·
`smoke.exposure.csrf_rotated` (the token is rotated on the login, the
logout and the step-up) · `smoke.exposure.xsendfile_scope` (X-Sendfile
covers the published media and the APK, not the whole storage tree) ·
`smoke.chat_bridge.no_busy_loop` (the SSE loop sleeps on EVERY path and
rate limits its frames) · `smoke.analytics.only_the_window_files` (a log
file older than the window is not read) ·
`smoke.access.one_predicate` now also pins the ONE-query access decision
(`Photo::accessInfo()` and the five facts it must return) ·
**phase 13/14 (planned):** `smoke.media.poster_build_is_verified` ·
`smoke.money.levels_are_tiered` · `smoke.theme.title_image_upload` ·
`smoke.sec.braintree_csp` · `smoke.terms.no_false_claims` ·
`smoke.system.php_fatals_card`.

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
7. `php tests/smoke.php` -> 963 checks at the start of this programme, and
   **1005 now**. The count may only go up.
8. **A new smoke check must also be registered in `TestSuite::tests()`.**
   `tests/smoke.php:37+` asserts the smoke set is a SUBSET of the suite,
   so a check that is not registered **fails CI**.
9. **Wait ~75 s after a deploy to PROD before verifying anything.** Prod's
   `opcache.revalidate_freq` is **60** seconds (not the 2 in
   `90-gallery-opcache.ini` - the pool's php.ini wins), so the OLD code
   keeps running for up to a minute after the files change. A view file is
   included at runtime and changes at once; a class needs the window. This
   cost an hour of chasing a "the deploy did not work" that had worked.
10. A new migration must land in `schema.sql` as well
   (`smoke.schema.migrations_are_in_the_schema`), the SQL must be
   re-runnable, and an applied migration's checksum is immutable
   (`scripts/migrate.php:148-153`).

**A NOTE ON THE MEASUREMENTS.** The performance and SEO numbers came from
a read-only audit that measured the LIVE prod box: MySQL's `general_log`
and `long_query_time` were temporarily changed and then **restored**
(verified: `general_log=0`, `log_output=FILE`, `long_query_time=1.0`),
the diagnostic scripts were deleted, and the application tree was not
modified. No production data was touched.
