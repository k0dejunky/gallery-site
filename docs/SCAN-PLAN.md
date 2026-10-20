# Deep scan: bugs and upgrades - the working plan

**This file is the recovery anchor for the 2026-10-20 deep scan.** It holds
the full finding list (with the evidence and the intended fix) and a status
table, so a crashed session can resume by reading the first `pending` row
without re-analysing a single line of code.

Companion document: `docs/UI-UPGRADE-PLAN.md` (the site-wide UI refactor,
phases 0-7, finished and shipped). This file is the next programme.

**STATUS: THE WHOLE PROGRAMME IS DONE AND SHIPPED (2026-10-20).** Every
item in every batch is implemented, verified on dev and on prod, and pushed.
Smoke 921 checks on local, dev and prod; the full TestSuite is 991 tests with
**0 failures on prod**. The prod backups before the deploys are
`pre-auth-batchA-20261020-075102`, `pre-ui-batchB-20261020-083059`,
`pre-perf-batchC-20261020-085603`, `pre-data-batchD-20261020-091359` and
`pre-ui-batchE-20261020-100403` (all in /var/backups/gallery).

**THE WHOLE PROGRAMME IS DONE (2026-10-20).** Every item in every batch is
implemented, verified on dev and on prod, and pushed. The smoke suite is 928
checks on local, dev and prod, and the full TestSuite is 1000 tests with 0
failures on prod.

**Scope (decided by the user): everything, in order.**

## What the audit of this plan found (2026-10-20)

- **The dev box was broken**: the member search answered 500 there (a missing
  migration, a stale model and a stale view) while the prod was fine. The
  cause was that the E batch was deployed to prod but never to dev. Repaired,
  and the deploy now VERIFIES that every file it copied matches the repo.
- **A fresh install could not work at all**: schema.sql had a syntax error
  (a stray index line inside the collections table) and it hard-coded
  `USE gallery_mvc`, and 38 of the 77 migrations add something schema.sql
  already has - so the migrator stopped at the first one and no later
  migration could ever be applied. Fixed: the schema imports cleanly into
  any database, the migrator runs statement by statement and treats "it is
  already there" as satisfied, and a scratch database now ends up with the
  same shape as the live one.
- **The admin dashboard was a jumble**: an alert box was closed with `</p>`
  instead of `</div>`, so the quick actions and every section below it
  became a flex child of that alert.
- **The galleries list overflowed**: the column widths were written for a
  nine column table and the table has ten, so every column was off by one.
- **The auto-poster pager did nothing**: its links were built from a raw
  path, so on a site installed in /gallery every page link was a 404.
- Three new smoke checks lock this in: the views must balance their divs,
  they must build their links with url(), and the search must have every
  part it needs. Each one is proven to bite.

**Scope (decided by the user): everything, in order.**
**Paywall copy (decided by the user): show the locked count + the upsell.**

## How a batch runs (the house rules)

1. **Before the first edit of a batch**, the batch is written here and
   committed on its own. A crash then loses nothing: the plan, the status and
   the evidence are all in git.
2. One item per commit, with the smoke check that locks it in **in the same
   commit**. Every new smoke check is proven to bite (revert the fix, watch
   it fail, restore it) before the commit is made.
3. `php tests/smoke.php` (907 at the start of this programme) and
   `php _suite_runner.php` (971 tests, 6 known environment-only failures:
   4 orphan photos, the example.com routes, the missing Twitter key, the
   missing cron directory) must pass locally, then the batch goes to **dev**,
   is verified in a real browser (the CDP harness in `/tmp/opencode` - rebuild
   it after a reboot), then to **prod**, is verified again, and only then is
   it committed and pushed.
4. At the end of a batch this file is updated with the outcome and the next
   batch's detail is written here before that batch starts.

## Status legend

`todo` - not started. `wip` - in progress (a crash here means: re-do this
item from its description). `done` - implemented, checked, committed and
verified on dev + prod. `shipped` - done and pushed to GitHub.

---

## THE STATUS TABLE

### Batch A - authorization gates (security)

**Batch A is DONE and shipped (local + dev + prod, 2026-10-20).** Smoke 910 on
local, dev and prod. Verified on dev with a scratch moderator account (the
scratch accounts were deleted afterwards): every escalation attempt is refused
(grant `admin`, demote an admin, reset an admin's password, change an admin's
email, suspend, delete, impersonate, mint a gift code, mint an unlock code,
flip the maintenance mode, turn off the age gate, delete the pending uploads,
grant themselves `super_admin`, create a user as `admin`), the mail page and
the creator profile are closed to a moderator, and the engagement and the
system page only show the controls that admin may use. The super admin keeps
everything. The prod backup before the deploy is
`/var/backups/gallery/pre-auth-batchA-20261020-075102.sql.gz`.

| id | what | files | status | commit |
|---|---|---|---|---|
| A1 | A moderator can mint a full admin (`Auth::isAdmin()` is true for every admin role) | `app/Controllers/UserController.php`, `app/Core/Auth.php` | done | `b998499` |
| A2 | The mail-server admin (a root script) is reachable by a `viewer` | `app/Controllers/EmailController.php`, `config/routes.php` | done | `41f399d` |
| A3 | The money actions (gift codes, mark-a-purchase-paid, unlock codes) are gated on `dashboard` | `app/Controllers/ModerationController.php`, `app/Core/Auth.php`, `config/routes.php` | done | `18cc516` |
| A4 | The site-wide creator profile is writable by a viewer; the avatar takes any URL | `app/Controllers/CreatorProfileController.php` | done | `baf642a` |
| A5 | A moderator can flip the maintenance flag (503 for every guest) or the age gate | `app/Controllers/SystemController.php`, `config/routes.php` | done | `5218707` |
| A6 | A replayed payment webhook re-activates a cancelled subscription with a fresh period | `app/Models/Subscription.php`, `app/Controllers/WebhookController.php` | done | `f0a5ddc` |
| A7 | The styled modal drops the form submitter (latent: a button's `formaction`/`name` would be lost) | `views/admin/layout.php` | done | `abdcb9e` |

### Batch B - the member-visible bugs

**Batch B is DONE and shipped (local + dev + prod, 2026-10-20).** Smoke 917 on
local, dev and prod; TestSuite 981 (6 known environment-only failures).
Verified in the browser on dev and prod: the operator's chat bubble is
14.98:1 (it was 1.16:1), two different guests now get two different CSRF
tokens from the cached pages (they used to get the producer's), the plan
cards say how many galleries a tier opens, the wall prints one date heading
per day, the chat is a log region, the guest bottom nav carries Sign in and
Join, and the lock chips and the "above your level" notes appear for a
member on a low tier. The prod backup before the deploy is
`/var/backups/gallery/pre-ui-batchB-20261020-083059.sql.gz`, and the prod
database got migration 071 (the multi-use unlock code).

**Found while doing B3:** the page cache's meta-tag rewrite used `'$1' . $token`
and a hex token that starts with a digit made PHP read it as the group 167 -
so the whole tag was replaced by nothing, about half the time. The braces
(`${1}`) fixed it; the new TestSuite case caught it.

| id | what | files | status | commit |
|---|---|---|---|---|
| B1 | The operator's chat bubble is 1.16:1 contrast (invisible); the welcome modal, support form and Silver badge share the defect | `public/assets/css/user.css`, `app/Models/Theme.php` | done | `af104af` |
| B2 | The page cache hands one guest another guest's CSRF token (the meta tag is not rewritten) | `app/Core/PageCache.php` | done | `19800c2` |
| B3 | A multi-use unlock code can only be redeemed once: the 2nd 500s and burns the use | `app/Controllers/PurchaseController.php`, `app/Models/Purchase.php`, `schema.sql` + a migration | done | `7ab4e58` |
| B4 | A fresh install cannot pause a live stream (`schema.sql` misses `live_sessions.paused_at`; `install.sh` never runs the migrator) | `schema.sql`, `install.sh` | done | `de54e18` |
| B5 | The site recommends galleries the member cannot open (`related()`/`recommended()` have no level filter) | `app/Models/Gallery.php` | done | `cbeefa9` |
| B6 | The paywall reads as "empty" -> the locked count + the upsell (copy decided) | `app/Models/Gallery.php`, `views/gallery/category.php`, `views/gallery/index.php`, `views/partials/gallery_card.php`, `views/membership/index.php` | done | `cbeefa9` |
| B7 | The wall wastes 20 COUNT queries, repeats the date heading per post, and the badge never clears | `app/Controllers/WallController.php`, `views/wall.php` | done | `c458bff` |
| B8 | The lightbox is dead code (every thumbnail is a full page load) | `public/assets/js/user.js`, `public/assets/css/user.css`, `views/partials/gallery_grid_item.php` | done | `f72548f` |
| B9 | The chat and the live chat are silent regions for screen readers | `views/chat/index.php`, `views/live.php` | done | `7d89033` |
| B10 | The leftovers of the 2026-10-19 batch (the duplicated guest nav, the tour's seen flag, the lost inline edit, the dead attribute, the count separator, `aria-modal`) | `public/assets/css/user.css`, `public/assets/js/tour.js`, `public/assets/js/admin.js`, `views/admin/galleries.php` | done | `e479848` |

### Batch C - performance

**Batch C is DONE and shipped (local + dev + prod, 2026-10-20).** Smoke 919 on
local, dev and prod; TestSuite 983 (6 known environment-only failures).
Measured on dev: the guest home page is 11 queries for 18 cards (it spent 24
queries on the card covers alone), the listing 5 for 28. On prod every card
now has its cover (the favourites page used to show placeholders, because the
cover was never preloaded). The wall feed and the notification inbox now use a
backward index scan instead of a filesort. The prod backup before the deploy is
`/var/backups/gallery/pre-perf-batchC-20261020-085603.sql.gz` and the prod
database got migration 072 (the eight indexes).

| id | what | files | status | commit |
|---|---|---|---|---|
| C1 | The card does 2 queries per card; the guest home page = 24 queries | `views/partials/gallery_card.php`, `views/home.php` | done | `0528d64` |
| C2 | `views/collections/show.php` calls `categoriesBulk()` per gallery | `views/collections/show.php` | done | `0528d64` |
| C3 | `Auth::hasActiveSubscription()` is not memoised (4 identical joins per page); `Notification::unreadCount()` is uncached | `app/Core/Auth.php`, `app/Models/Notification.php` | done | `f8245c1` |
| C4 | 7 missing indexes + the `email_queue` due index | a new migration | done | `8fa34fc` |
| C5 | The hot listing query builds a temp table + filesort (cannot use its index) | `app/Models/Gallery.php` | done | `1145a47` |
| C6 | `softDelete()`/`restore()` do not invalidate the `listings` bucket | `app/Models/Gallery.php` | done | `f8245c1` |
| C7 | `Purchase::userUnlocked()` per gallery in the wall loop (twice per page) | `app/Controllers/WallController.php`, `app/Models/Purchase.php` | done | `f8245c1` |
| C8 | `ServerOptimizations::all()` re-reads the file on every cache lookup | `app/Models/ServerOptimizations.php` | done | `f8245c1` |
| C9 | 5 unbatched writes per gallery view | `app/Models/Gallery.php`, `app/Models/Stats.php` | done | `4edaa11` |
| C10 | Unstable `ORDER BY` (no id tiebreak) -> rows skipped/duplicated between pages | `app/Models/Gallery.php` | done | `f8245c1` |

### Batch D - data integrity

**Batch D is DONE and shipped (local + dev + prod, 2026-10-20).** Smoke 920 on
local, dev and prod; the full TestSuite on **prod** is 990 tests with 0
failures. Migrations 073 (the soft delete, the wall author, the new
references) and 074 (a sold unlock code survives its gallery) are applied on
both boxes. The prod backup before the deploy is
`/var/backups/gallery/pre-data-batchD-20261020-091359.sql.gz`.

| id | what | files | status | commit |
|---|---|---|---|---|
| D1 | The unlock redemption is not transactional | `app/Controllers/PurchaseController.php` | done | `7ab4e58` |
| D2 | `sales.max_subscriptions` is an unlocked COUNT (oversell) and a pending subscription eats the slot | `app/Models/SaleCode.php`, `app/Models/Subscription.php` | done | `dbb3dc6` |
| D3 | Deleting a user cascade-deletes the financial ledger | `app/Models/User.php`, `schema.sql` + a migration | done | `dbb3dc6` |
| D4 | Deleting a gallery cascade-deletes the unlock codes that were sold | `schema.sql` + a migration, `app/Models/Gallery.php` | done | `dbb3dc6` |
| D5 | Missing FKs -> orphan rows (`wall_posts.gallery_id`, `content_views`, `comments`, `subscriptions.sale_id`) | `schema.sql` + a migration | done | `dbb3dc6` |
| D6 | `wall_posts` has no author column (`$userId` is silently dropped) | `app/Models/WallPost.php` + a migration | done | `dbb3dc6` |
| D7 | Retention: `user_activity`, `content_views`, `category_views`, `search_stats` are never pruned | `app/Core/Housekeeping.php` | done | `dbb3dc6` |
| D8 | `Subscription::approve()` grants the period from now, not from the purchase date | `app/Models/Subscription.php` | done | `e4a1b2c` |
| D9 | `schema.sql` re-enables `FOREIGN_KEY_CHECKS` 85 lines before the end of the file | `schema.sql` | done | `e4a1b2c` |
| D10 | `Purchase::settleByReference()` has no status guard | `app/Models/Purchase.php` | done | `e4a1b2c` |
| D11 | `Collection::addPhoto()` is a check-then-insert without a transaction | `app/Models/Collection.php` | done | `e4a1b2c` |
| D12 | `Gallery::movePhoto()` swaps two positions without a transaction | `app/Models/Gallery.php` | done | `e4a1b2c` |

### Batch E - the product upgrades (one commit each)

**Batch E is DONE and shipped (local + dev + prod, 2026-10-20).** Smoke 921 on
local, dev and prod; the full TestSuite on **prod** is 991 tests with 0
failures. Migrations 075 (the searches that hit), 076 (the caption search) and
077 (the watch position) are applied on both boxes. The prod backup before the
deploy is `/var/backups/gallery/pre-ui-batchE-20261020-100403.sql.gz`.

**E5 is now done:** the comments can be answered (one level of replies) and
reacted to (one reaction per member, toggled), on the gallery, the media and
the wall threads. Migration 078 + `db.comment_replies_and_reactions`.

| id | what | files | status | commit |
|---|---|---|---|---|
| E1 | Paginate the catalogue; lead with "For you" / recently added / trending | `app/Controllers/GalleryController.php`, `views/gallery/index.php`, `app/Models/Gallery.php` | done | `175f5cd` |
| E2 | The membership page says what a tier unlocks; an "Interests" card that feeds the recommendations | `app/Models/Gallery.php`, `views/membership/index.php`, `views/membership/dashboard.php` | done | `175f5cd` |
| E3 | Search the photo captions (a `photos` FULLTEXT index) | `app/Models/Gallery.php` + a migration | done | `264593c` |
| E4 | Fix the 1-2 character search; surface the site's own missed searches | `app/Models/Gallery.php`, `views/gallery/index.php` | done | `99f1488` |
| E5 | The wall / inbox model (two feeds, an unpaged inbox, a badge that only clears per click, flat comments) | `app/Controllers/WallController.php`, `views/wall.php`, `app/Models/Comment.php` + a migration | done | `0781e44`, `0305edc` |
| E6 | Collections: add one photo, reorder images/galleries, share | `views/gallery/image_full.php`, `views/collections/show.php`, `app/Models/Collection.php` | done | `053f024`, `0305edc` |
| E7 | Server-side watch progress + a "continue watching" rail | a new table, `public/assets/js/user.js`, `views/membership/dashboard.php` | done | `faf046a` |
| E8 | A "more like this" rail on the video page; seed the "Up next" panel | `app/Controllers/ImageController.php`, `views/video/player.php` | done | `99f1488` |
| E9 | The mobile / a11y small ones (the cookie banner reload, the grid item alt text, the play badge) | `views/partials/consent_banner.php`, `views/partials/gallery_grid_item.php` | done | `99f1488` |

---

## THE EVIDENCE (so nothing has to be re-analysed)

### Batch A

**A1 - a moderator can mint a full admin.**
`app/Controllers/UserController.php:16-23` (`requestedRole()`), `:137` (`bulk()`),
`:338-357` (`updateRole()`) all build the allowed-role list like this:
```php
$allowed = ['user', 'editor', 'moderator', 'viewer'];
if (Auth::isAdmin()) $allowed[] = 'admin';
if (Auth::can('manage_roles')) $allowed[] = 'super_admin';
```
`Auth::isAdmin()` (`app/Core/Auth.php:402-407`) is
`in_array($user['role'], self::ADMIN_ROLES, true)` - true for **every** admin
role, `moderator` included. `moderator` holds `users`
(`app/Core/Auth.php:25`), so it passes the router gate on
`/admin/users/{id}/role` and can POST `role=admin`, creating an account with
`autoposter, theme, site_editor, logs, analytics, payments, membership,
traffic, chat, support` (`app/Core/Auth.php:23`). The same works through
`POST /admin/users` and `POST /admin/users/bulk`. A moderator can also
impersonate any non-super_admin account.
**Fix:** only a permission that means "manage roles" may grant `admin`
(`Auth::can('manage_roles')` is super_admin only today - if a second role
should be able to grant `admin`, add an explicit `manage_admins` permission).
Re-check the bulk path with the same rule.

**A2 - the mail-server admin is reachable by a viewer.**
`app/Controllers/EmailController.php:20-26`:
```php
public function __construct($request)
{
    parent::__construct($request);
    Auth::requirePermission('dashboard');
```
`dashboard` is held by `viewer`, `editor`, `moderator` and `admin`
(`app/Core/Auth.php:23-26`). The controller then shells out to a **root**
script: `runScript()` executes `sudo -n /usr/bin/php .../bin/mail_admin.php`,
and `bin/mail_admin.php:281-289` rewrites `/etc/dovecot/users`:
```php
if ($command === 'password') {
    $passwd = readPasswd();
    abort(isset($passwd[$email]), "mailbox not found: {$email}");
    $passwd[$email] = hashPassword($password);
    writePasswd($passwd);
```
The routes (`config/routes.php:295-299`) also say `dashboard`.
**Impact:** a viewer re-passwords any mailbox - including the site's own
sender account - reads the operational mail and the account-reset mails that
land there, or deletes a mailbox and breaks all outbound mail.
**Fix:** `Auth::isSuperAdmin()` in the constructor and in every mutating
action, and the route permission.

**A3 - the money actions are gated on `dashboard`.**
`app/Controllers/ModerationController.php`: `giftStore()` (`:129-142`) mints a
`GIFT-...` code with `discount_type='percent', discount_value=100` - a free
top-tier membership; `purchaseStatus()` (`:155-170`) flips a pending purchase
to `paid`; `codeStore()`/`codeToggle()`/`codeDelete()` mint unlock codes for
paid galleries. The routes (`config/routes.php:385-388`) say `dashboard`.
`Subscription::create()` (`app/Models/Subscription.php:197-206`) applies the
100 % discount, and `Purchase::userUnlocked()`
(`app/Models/Purchase.php:120-130`) honours the paid unlock.
**Fix:** a `monetisation` permission (admin + super_admin), and super_admin
for the two actions that directly grant value (`giftStore`, `purchaseStatus`).

**A4 - the creator profile is writable by a viewer.**
`app/Controllers/CreatorProfileController.php:25,34` gate on `dashboard`. The
avatar is rendered as any absolute URL (`views/creator/profile.php:14-16`) -
a tracking pixel for every visitor - and the link list accepts any scheme.

**A5 - the system actions can take the site down.**
`config/routes.php:274-292` declares `dashboard`, but
`app/Controllers/SystemController.php` requires `logs` in its constructor -
`moderator` holds both. `saveMaintenance()` makes
`public/index.php` answer 503 to every guest and member. `saveAgeGate()`
toggles the 18+ gate. `saveCronSchedule()` (`:944-949`) already re-checks
`super_admin` internally, so the pattern is known but not applied.

**A6 - a replayed webhook resurrects a cancelled subscription.**
`app/Models/Subscription.php:298-325`:
```php
public static function approve(int $id): void
{
    ...
    $expires = date('Y-m-d H:i:s', strtotime('+1 month'));   // from NOW
    Database::run(
        'UPDATE subscriptions SET status = ?, starts_at = CURRENT_TIMESTAMP, expires_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
        ['active', $expires, $id]
    );
}
```
No status guard. The webhooks only skip an **active** subscription
(`app/Controllers/WebhookController.php:682-689` PayPal, `:466-471`
Braintree); a cancelled one has status `cancelled`
(`app/Models/Subscription.php:346-361`) and is therefore re-activated by a
retried postback - with a fresh, free billing period.

**A7 - the modal drops the form submitter (latent).**
`views/admin/layout.php:345`:
```js
if (typeof t.requestSubmit === 'function') { t.requestSubmit(); } else { t.submit(); }
```
`requestSubmit()` without a submitter submits the form "as if the submitter
were the form itself": the button's `formaction` and `name/value` are lost.
**VERIFIED not live today**: a script over every view shows that no form-level
`data-confirm` form has a button with `formaction`/`name`; the two buttons
that use `formaction` (`views/admin/manage.php:260,271`) carry `data-confirm`
on the **button**, and that path re-issues `t.click()`, which keeps the
submitter. It is a trap for the next form that mixes the two.

### Batch B

**B1 - the operator's chat bubble is invisible.**
`public/assets/css/user.css:467`:
```css
.chat-msg.model, .chat-msg.operator { background: var(--pink-100, #fdf2f8); color: var(--purple-900, #4a044e); ... }
```
The fallbacks are the LIGHT values, but the live member palette is
`app/Models/Theme.php:112` `'pink-100' => '#2b1123'` and `:126`
`'purple-800' => '#581c87'` with `'purple-900' => '#3b0764'` - and the
deployed `storage/theme.json` carries the same `pink-100: #2b1123`.
**VERIFIED contrast #3b0764 on #2b1123 = 1.16:1** (WCAG AA wants 4.5:1) - the
operator's reply is near-black on near-black. The same pairing breaks the
first-run welcome modal (`user.css:63`), the support form
(`user.css:83,:86,:91`) and the "Silver" nav badge (`user.css:419`).
**Fix:** own tokens for those surfaces instead of borrowing `--pink-100`.

**B2 - the page cache leaks one guest's CSRF token to another.**
`app/Core/PageCache.php:152-173` (`pageInject()`) rewrites three things in a
cached page: `nonce="..."`, `name="_token" value="..."` and
`name="return_to" value="..."`. It does **not** rewrite
`<meta name="csrf-token" content="...">`, which `views/layout.php:60` emits
with the session's token (`app/Core/Csrf.php:15-24`). The page cache covers
the guest home, listing, category and tag pages
(`app/Core/PageCache.php:115-128`, used at `app/Core/Router.php:148`).
**Impact:** guest B is served guest A's cached page and gets A's token; the
member JS posts with it (`public/assets/js/user.js:1634-1639`) -> 419 on
every AJAX POST, and A's token is disclosed to B.
**Fix:** rewrite the meta tag in `pageInject()`; look for any other
per-session value in a cached page.

**B3 - a multi-use unlock code can only be redeemed once.**
`app/Controllers/PurchaseController.php:81-89` writes the **code id** into
`purchases.gateway_ref`:
```php
Purchase::create($userId, Purchase::TYPE_GALLERY, $id, (float) $amount,
    'code', (string) $valid['id'], 'Unlocked "' . $gallery['title'] . '" with code ' . $valid['code']);
```
and `schema.sql:1025` has `UNIQUE KEY uq_purchases_gateway_ref (gateway, gateway_ref)`.
The second redemption of the same code violates the key -> PDOException ->
HTTP 500 - and `UnlockCode::redeem()` (`app/Models/UnlockCode.php:70-81`, an
atomic guarded UPDATE) has already incremented `used_count`, so the member
burns a use and gets no access. `unlock_codes.max_uses` is nullable
(`schema.sql:999`) - multi-use is a designed feature that cannot work.

**B4 - a fresh install cannot pause a live stream.**
`schema.sql:725` creates `live_sessions` without `paused_at`; the column only
exists in `database/migrations/044_live_session_pause.sql:4`. But
`app/Controllers/LiveController.php:238` runs
`UPDATE live_sessions SET paused_at = COALESCE(paused_at, CURRENT_TIMESTAMP) WHERE id = ?`
and `:257` sets it back to NULL. `install.sh:298` imports `schema.sql` only -
it never runs `scripts/migrate.php` (`scripts/deploy.sh:112-117` runs it only
with `DEPLOY_MIGRATE=1`).
**VERIFIED:** a script over all 69 migration files finds exactly one column
missing from `schema.sql` - this one.

**B5 - the site recommends content the member cannot open.**
`app/Models/Gallery.php:1449-1465` (`related()`) has no `min_level` filter,
and `recommended()` (`:1355`) calls
`inCategories($catIds, '', PHP_INT_MAX, $userId)` - `PHP_INT_MAX` switches
the level condition off (`app/Models/Gallery.php:448`). The card has no lock
chip, so the member clicks a recommendation and lands on the paywall.

**B6 - the paywall reads as "empty" (copy decided).**
`views/gallery/category.php:88-96` renders the empty state when the
level-filtered paginator is empty (the level filter is
`app/Models/Gallery.php:367-373`, wired at
`app/Controllers/GalleryController.php:451-464`), and the listing simply
omits a paid category's section. In prod 163 of 165 published galleries are
paid.
**The decided copy:** "12 Gold galleries are locked in this category - View
membership", a lock chip on the locked cards, and "Your Silver plan unlocks
42 galleries - Gold unlocks 118 - Platinum unlocks 165" on the plan pages.
Needs: `Gallery::countLocked(int $maxLevel, ?int $categoryId)` and
`Gallery::countByLevel()`.

**B7 - the wall wastes 20 queries and repeats every date.**
`app/Controllers/WallController.php:51`:
```php
$post['comment_count'] = Comment::countFor(Comment::TYPE_WALL, (int) $post['id']);
```
**VERIFIED: `comment_count` is written and never read** - one occurrence in
the whole tree. The view computes the count from the rows it already fetched
(`views/wall.php:173-175`). `views/wall.php:113-119` prints the date heading
inside the per-post loop. And nothing marks a notification read when the wall
is opened, so the nav badge (`views/layout.php:213`) never clears.

**B8 - the lightbox is dead code.**
`public/assets/js/user.js:9-167` still holds the whole lightbox (open/close,
arrow keys, swipe, pinch-zoom, counter, caption, focus restore) and exports
`window.GalleryLightbox` at `:163`; `public/assets/css/user.css:2-22` still
carries ~20 `.se-lightbox*` rules. But
`views/partials/gallery_grid_item.php:19-24` no longer emits
`data-lightbox*` (removed in commit `ded33e2`), so `user.js:52` matches
nothing and every thumbnail is a full page load to `/images/{id}`.

**B9 - the chat is a silent region.**
`views/chat/index.php:96` - the thread has no `role="log"` / `aria-live`; the
new messages are injected at `:195-219` from the SSE handler at `:359-368`.
`views/live.php:27` (`#live-chat-messages`) is the same.

**B10 - the leftovers of the 2026-10-19 batch.**
- the guest nav is duplicated: the rail's guest block is kept on phones by
  `public/assets/css/user.css:944-951` (with a comment that still says the
  bottom bar is member-only), and the new guest bottom bar says the same
  thing.
- `public/assets/js/tour.js:220` - `finish()` calls `markSeen()`, and
  `finish()` is reached from paths where no step was ever shown
  (`:248` `resume()` with an empty list). The admin panel loads the same
  `tour.js` (`views/admin/layout.php:880`) and `tour.js:234` only requires
  `document.body.dataset.user === '1'`, so skipping the tour in the admin
  permanently marks the **member** tour as seen for that browser.
- `public/assets/js/admin.js:423-484` - the inline role edit holds the new
  value only in the DOM until Save; navigating away loses it silently (no
  `beforeunload`, no dirty marker beyond the cell tint).
- `views/admin/galleries.php:364` - `data-undo-message` is written and never
  read.
- `public/assets/js/admin.js:367-376` - `bumpListCount()` turns "1,200" into
  "1199" (it strips the separator) and decrements every `<strong>` in the
  count line.
- `public/assets/js/tour.js:135-137` - the card claims `aria-modal="true"`
  but the page behind it is not inert.

### Batch C

**C1 - the card does 2 queries per card.**
`views/partials/gallery_card.php:2,4`:
```php
$cover = $cover ?? \App\Models\Gallery::firstPhoto((int) $gallery['id']);
$galleryCategories = $galleryCategories ?? \App\Models\Gallery::categories((int) $gallery['id']);
```
`??` fires on null, so a caller that sets `$cover = null` pays 2 queries per
card - `views/home.php:65,84` do exactly that (the guest home page = 12 cards
= 24 queries), as do `views/gallery/show.php:154`,
`views/gallery/tag.php:34-36` and `views/favorites/index.php:27`. The bulk
helpers exist (`Gallery::firstPhotos()`, `Gallery::categoriesBulk()`).

**C2 - `categoriesBulk()` per gallery.**
`views/collections/show.php:95`:
```php
$galleryCategories = \App\Models\Gallery::categoriesBulk([$gid])[$gid] ?? [];
```
A one-element array per row - 40 galleries = 40 queries.

**C3 - `hasActiveSubscription()` is not memoised.**
`app/Core/Auth.php:476-489` calls `Subscription::isActive()` on every call,
while `activeSubscriptionFor()` (`:346-353`) *is* memoised. Called 4+ times
per member page (`app/Core/Controller.php:85`, `views/layout.php:216,233,252`).
`Notification::unreadCount()` (`app/Models/Notification.php:40-48`) is an
uncached `COUNT(*)` on every member page.

**C4 - the missing indexes** (verified with EXPLAIN against the live schema):
```sql
CREATE INDEX idx_notifications_user_created ON notifications (user_id, created_at, id);
CREATE INDEX idx_wall_posts_feed ON wall_posts (deleted_at, pinned, created_at, id);
CREATE INDEX idx_gallery_photo_pos_photo ON gallery_photo (gallery_id, position, photo_id);
CREATE INDEX idx_collection_items_order ON collection_items (collection_id, position, id);
CREATE INDEX idx_gallery_favorites_user_created ON gallery_favorites (user_id, created_at);
CREATE INDEX idx_gallery_viewers_user_viewed_at ON gallery_viewers (user_id, viewed_at, gallery_id);
CREATE INDEX idx_purchases_user_item ON purchases (user_id, item_type, item_id, status);
CREATE INDEX idx_email_queue_status_id ON email_queue (status, id);
```
The `email_queue` due query (`app/Models/EmailQueue.php:428-441`) uses `OR`
predicates that defeat the current due index.

**C5 - the hot listing query cannot use its index.**
`app/Models/Gallery.php:402-411` (and the same in `all()` `:285-292`,
`featured()` `:1409`, `trending()` `:1433`, `recentlyViewed()` `:1237`,
`favoriteGalleries()` `:1309`) does `LEFT JOIN gallery_photo ... GROUP BY
g.id` -> a temp table + filesort, so `idx_galleries_listing` is unusable.
`inCategories()` (`:459-467`) already uses the correlated subquery form.

**C6 - the `listings` bucket is not invalidated on delete/restore.**
`app/Models/Gallery.php:635-656` bumps `gallery` and
`DuplicateGalleries::invalidate()`, but the rendered fragments live in the
`listings` bucket (`app/Core/PageCache.php:48`) with a 120 s TTL. Every other
gallery mutation calls `PageCache::invalidate()` (`:721,755,769,794,820`).

**C7 - `Purchase::userUnlocked()` per gallery.**
`app/Controllers/WallController.php:110` (inside `buildGalleryPreviews()`),
and that method runs twice per page (`:48` and `:143`).

**C8 - `ServerOptimizations::all()` hits the filesystem per call.**
`app/Models/ServerOptimizations.php:44-58` - `is_file()` +
`file_get_contents()` + `json_decode()` on every `cacheTtl()` call.

**C9 - 5 unbatched writes per gallery view.**
`app/Models/Gallery.php:662-698` + `app/Models/Stats.php:589-601` +
`app/Controllers/GalleryController.php:541-547`.

**C10 - unstable ordering.**
`ORDER BY g.created_at DESC` with no id tiebreak in `paginate()` (`:375`),
`related()` (`:1461`), `featured()` (`:1415`), `trending()` (`:1440`),
`all()` (`:290`). `Gallery::create()` stamps `CURRENT_TIMESTAMP`, and
`GalleryController::galleryImport()` creates galleries in a loop, so a CSV
import gives many galleries the same second -> rows skipped and duplicated
between pages.

### Batch D

**D1 - the unlock redemption is not transactional.** `redeem()` then
`Purchase::create()` then `Notification::add()` with no transaction
(`app/Controllers/PurchaseController.php:70-89`). The pattern to copy is
`Subscription::create()` (`app/Models/Subscription.php:175-230`).

**D2 - `sales.max_subscriptions` is an unlocked COUNT.**
`app/Models/SaleCode.php:22-24` counts `subscriptions` with
`status IN ('pending','active')` in a subquery with no lock, and
`Subscription::create()` inserts a **pending** row - so an abandoned checkout
consumes a sale slot forever. (`sale_codes.max_uses` is handled correctly:
`SaleCode::redeem()` is one atomic guarded UPDATE inside the transaction.)

**D3 - deleting a user destroys the financial ledger.**
`app/Models/User.php:225-228` hard-deletes; `subscriptions.user_id` and
`purchases.user_id` are `ON DELETE CASCADE` (`schema.sql:349,1026`).
`admin_logs.user_id` is `ON DELETE SET NULL` (`:282`), so the audit trail
survives but the money does not.

**D4 - deleting a gallery cascade-deletes the unlock codes that were sold.**
`schema.sql:1004` - `unlock_codes.gallery_id ... ON DELETE CASCADE`, while
`purchases.gateway_ref` holds the code id as a string (no FK), so the
purchases survive pointing at a code that no longer exists.

**D5 - the missing FKs.** `wall_posts.gallery_id` (`schema.sql:944`),
`content_views.entity_id` (`:606`), `comments.commentable_id` (`:969`),
`subscriptions.sale_id`/`sale_code_id` (`:338-339`).

**D6 - `wall_posts` has no author.** `app/Models/WallPost.php:43-57` takes
`$userId` and never uses it; the table has no `user_id` column
(`schema.sql:941-950`).

**D7 - the retention gaps.** `Housekeeping::pruneChatRetention()`
(`app/Core/Housekeeping.php:327-346`) prunes the chat tables and the read
notifications, but `user_activity` (one INSERT per gallery view),
`content_views`, `category_views` and `search_stats` are never pruned.

**D8 - `approve()` grants the period from now.** `app/Models/Subscription.php:313-317`.

**D9 - `schema.sql` re-enables the FK checks too early.** `schema.sql:1108`
with five tables after it (`:1111-1193`).

**D10 - `settleByReference()` has no status guard.**
`app/Models/Purchase.php:49-74` (its sibling `settleById()` at `:76-83` does).

**D11 - `Collection::addPhoto()` is a check-then-insert.**
`app/Models/Collection.php:326-357` (and `addGallery()` at `:297-322`).

**D12 - `Gallery::movePhoto()` swaps two positions unprotected.**
`app/Models/Gallery.php:1195-1202` (the `Collection` sibling at
`app/Models/Collection.php:442-450` does it correctly with a sentinel).

### Batch E - the product upgrades

**E1 - the catalogue has no pagination.**
`app/Controllers/GalleryController.php:254-322` builds every published
gallery into `$sections`; `:341-343` paginates only when there is a search
term. `views/gallery/index.php:80-81` says so in a comment. The
recommendation strip (`:124-143`) is drawn from a pool that is empty for a
member with no favourites (`app/Models/Gallery.php:1351-1353`) and is
rendered after the whole catalogue. `Gallery::trending(7,6)` already exists
but is only used on the public home (`app/Controllers/HomeController.php:73`).
**Do it after C4/C5.**

**E2 - the plan pages say nothing about what a tier unlocks.**
`views/membership/index.php:182-265` shows names and prices only. The only
way to favourite a category is an unlabelled star
(`views/gallery/index.php:92-98`), so most members have no favourites - and
therefore no recommendations and no pool.

**E3 - the photo captions are never searched.**
`app/Models/Gallery.php:316-338` searches `g.title`, `g.description` and
`categories.name` only. `photos` has no FULLTEXT index
(`schema.sql:132-146`); `galleries.ft_search` covers title+description
(`:121`). Captions are the descriptive text of every image (the grid item's
alt text, `views/partials/gallery_grid_item.php:23`).

**E4 - the 1-2 character search.**
`app/Models/Gallery.php:545-561` (`fulltextQuery()`) drops words under 3
characters, so the search falls back to a `%term%` LIKE scan over three
columns plus a correlated `EXISTS` per row (`:329-337`), and the matching
`COUNT(*)` (`:394-397`) doubles it. The site already records the misses
(`Gallery.php:346-348` -> `Stats::recordMissedSearch()`) and never uses them.

**E5 - the wall / inbox model.** `app/Controllers/WallController.php:60`
renders 30 notification cards above the feed, unpaginated
(`views/wall.php:9-103`); the creator posts sit below them. Reading the wall
does not mark anything read - only `NotificationController@show()` and
`readAll()` do - so the badge never clears. The comments are flat
(`views/partials/comments.php:23-35`): no replies, no reactions.

**E6 - collections.** There is no UI path to add a single image: the forms
are "save the whole gallery" (`views/gallery/show.php:61-73`) and "save this
video" (`views/video/player.php:120-132`). The image viewer already loads
the member's collections (`app/Controllers/ImageController.php:93,126`) but
shows no form. Only videos can be reordered
(`views/collections/show.php:71-77`).

**E7 - the watch position is local-only.**
`public/assets/js/user.js:259-306` stores it under
`localStorage['gallery-video-position-<id>']` - per device, never synced.
`views/membership/dashboard.php:71-96` has the room for a "Continue watching"
strip but shows galleries, not videos.

**E8 - the video page has no "more like this".**
`app/Controllers/ImageController.php:120-145` passes no related galleries to
the player view; the "Up next" panel is only filled when the video was opened
with `?playlist=` (`app/Controllers/ImageController.php:95-104`).

**E9 - the mobile / a11y small ones.** The consent banner is a
`role="dialog"` that reloads the page on accept
(`views/partials/consent_banner.php:8-22`); the grid item's alt is
`"Video thumbnail for item N"` (`views/partials/gallery_grid_item.php:4`)
and the play badge is not `aria-hidden` (`:5`).

---

## HOW TO RESUME AFTER A CRASH

1. `git -C /media/webdev/Media/gallery-full status` and `git log --oneline -5`
   - the uncommitted files are the item in flight; never throw them away.
2. Read **THE STATUS TABLE** above and find the first row that is not
   `done`. That row's entry in **THE EVIDENCE** section has everything
   needed: the file:line, the offending code, the fix and the check. No
   re-analysis is needed.
3. The CDP harness in `/tmp/opencode` is wiped by every reboot - rebuild it
   (headless Chrome + `--remote-debugging-port` + the CDP
   `Network.setCookie` / `Input.dispatchKeyEvent` / `Page.captureScreenshot`
   calls, driven by node 24 which has `fetch` and `WebSocket` built in).
   **Cookie trap:** `Network.setCookie` with a `url` sets the path to that
   URL's path, so a guest's host-only cookie is sent first and the member
   looks logged out - set `domain` + `path: '/'` and clear the cookies first.
4. Never `pkill -f <pattern>` from the ssh command line: the pattern matches
   the shell's own command line and kills the shell. Put the pattern in a
   `.sh`/`.bat` on the box instead.
5. The deploy script needs `DEPLOY_HOST`, `DEPLOY_PASS` and `DEPLOY_ROOT`,
   and it must run with a **>= 5 minute** timeout (a dev deploy takes 2-3
   minutes; a shorter timeout kills a healthy deploy and rolls it back).
6. Prod smoke must run as `www-data` after `sudo rm -rf /tmp/gallery-analytics-rot`.
