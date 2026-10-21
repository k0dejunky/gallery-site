# Round 3: the site-wide UI plan (member + admin)

**This file is the recovery anchor for the 2026-10-20 third UI programme.** It
holds the full item list, the evidence for every item and a status table, so a
crashed session can resume by reading the first `pending` row without
re-analysing a single line of code.

Companion documents: `docs/UI-UPGRADE-PLAN.md` (the 2026-10-19 refactor,
phases 0-7, shipped) and `docs/SCAN-PLAN.md` (the 2026-10-20 deep scan,
batches A-E, shipped). **Both are closed.** This is round 3: what is left.

Origin: the user asked "what UI changes should be made to the whole site user
and admin sides" on 2026-10-20 and then "do it all".

**Decisions taken (2026-10-20):**
- Scope: **all five phases**, including the product gaps.
- The member site stays **dark only**. No light theme, no `prefers-color-scheme`.
- **System sans stays.** No web font, no `@font-face`. The type *scale* is
  still unified (item 3.9) - that is a token job, not a font job.

**Sequencing:** fix the measurable defects -> unify the tokens -> extract the
component layer -> the two product gaps. Verification follows the house rule
every time: **local -> dev -> prod -> commit last** (one commit per item, the
smoke check that locks it in travels in the same commit).

---

## Where the work stands (updated 2026-10-20)

| Phase | What | Status | Commit |
|---|---|---|---|
| 0 | The plan anchor (this file) | **done** | `6257c30` |
| 1 | Contrast and focus | **done** | `76e6aa0`, `2d0ba04`, `ab75dc0`, `8a0e512`, `5a45259`, `3b79b14` |
| 2 | Broken code that already shipped | **done** | `cfb4ab6`, `35bc5ca`, `c4429dc`, `29fdb9e`, `da0159f`, `ef3186f` |
| 3 | Token coherence | **done** | `4708322`, `0af1fdc`, `0672771`, `01e8470`, `dede9a4`, `0d7b087`, `bea22ee`, `bb7e3b0`, `9164dd9`, `b028d3e` |
| 4 | The component layer | **done, except 4.1** | `fa1b403`, `2602a7b`, `04277ba`, `40f0497`, `d84f2d9`, `c147426` |
| 5 | Member product UX | **done** | `8e81636`, `ec7e243` |
| Final | local smoke/unit -> dev -> prod verify -> commit + push | pending | - |

### Baseline

- **local** `/media/webdev/Media/gallery-full` - `main`, clean, in sync with
  `origin/main`.
- `php tests/smoke.php` -> **963 checks, all passed** (937 at the start).
- `php _suite_runner.php` -> 1006 tests with the **6 known environment-only
  failures** (4 orphan photos, the example.com routes, the missing Twitter
  key, the missing cron directory). Nothing here may add a seventh.

### The numbers this programme is measured against

| Measure | At the start |
|---|---|
| Inline `style=` attributes in the views | **850** (the ratchet limit is 853 - zero headroom) |
| Hardcoded hex colours in the views | **577** (189 member + 388 admin) |
| Distinct palettes in one product | **4** (member dark, admin light `--adm-*`, site-editor IDE, admin login page) |
| Member classes used in views but never defined | **13** |
| Admin classes used in views but never defined | **16** |
| Admin views using the shared component layer | **6 of ~40** |

**THE RATCHET (read this before touching a view).**
`smoke.ui.inline_style_ratchet` counts `style="` across `views/*.php` and
`views/*/*.php` (the `emails/` directory is exempt) and fails over **853**. It
is currently at **850**. So *any* new inline style fails the suite. Item 3.0
re-baselines it downward; until that lands, every style goes into a
stylesheet.

---

## Phase 1 - contrast and focus

Six measured contrast failures and one keyboard trap. The colours are read out
of the shipped member palette (`app/Models/Theme.php`, `MEMBER_COLORS`), so the
numbers are the ones the browser actually paints.

| id | what | files | status | commit |
|---|---|---|---|---|
| 1.1 | The paywall headline is **1.60:1** - effectively invisible, and it is the upsell surface for 163 of the 165 published galleries | `views/partials/membership_gate.php` | done | 76e6aa0 |
| 1.2 | The global focus ring is **2.83:1** on the page background (WCAG 1.4.11 wants 3:1 for a non-text indicator) - and the `--focus-ring` token that would fix it already exists | `public/assets/css/user.css` | done | 2d0ba04 |
| 1.3 | `.btn:hover` (0,3,1) outranks `.btn-danger` (0,2,1), so hovering a destructive button paints the pink gradient under the danger text: **1.88:1** | `public/assets/css/user.css` | done | ab75dc0 |
| 1.4 | The chat online/away status is **3.93:1** at 12.8px (needs 4.5) | `views/chat/index.php` | done | 8a0e512 |
| 1.5 | The guest teaser statistic is **1.71:1** on the login and signup pages | `public/assets/css/user.css` | done | 8a0e512 |
| 1.6 | `.chip input[type="checkbox"] { display: none }` makes every e-mail-notification toggle and every favourite-category chip **unreachable by keyboard**, with no focus indicator | `app/Core/theme_base.css` | done | 5a45259 |
| 1.7 | Semantics: an `aria-label` on a role-less `<span>`, `aria-disabled` on a `<span>`, a `role="dialog"` consent banner with no `aria-modal` and no focus handling, a duplicate `nonce` attribute on the JSON-LD scripts, and an inline `oncontextmenu` that the site's own CSP blocks | `views/partials/star_rating.php`, `views/partials/media_nav.php`, `views/partials/consent_banner.php`, `views/layout.php`, `views/chat/index.php` | done | 3b79b14 |
| 1.8 | The check that locks the whole phase in: `smoke.ui.contrast_floor` reads the palette out of `Theme.php` and **computes** every text-on-surface ratio, so it can never regress | `app/Core/SmokeChecks.php` | done | 76e6aa0 |

### The evidence

**1.1** - `views/partials/membership_gate.php:15`
```php
<h2 style="margin:0 0 .5rem;color:var(--purple-800);">
```
`--purple-800` is `#581c87` and the panel's own background is `--pink-100`
= `#2b1123` (the member palette is dark, `Theme.php:119-253`). Contrast
**1.60:1**. The fix is `--heading-color` (`#fdf2f8`) -> **15.93:1**.

**1.2** - `public/assets/css/user.css:45`
```css
:where(a,button,input,textarea,select):focus-visible{outline:3px solid var(--purple-600)}
```
`--purple-600` is `#7e22ce` on `--page-bg` `#120711` -> **2.83:1**. The member
palette already defines `--focus-ring: #c084fc` (`Theme.php:229`) and it is
**7.47:1** on the page background and 7.05:1 on a card.

**1.3** - `public/assets/css/user.css:665` and `:675`
```css
body:not(.admin-theme) .btn:hover { background: linear-gradient(...); }   /* (0,3,1) */
body:not(.admin-theme) .btn-danger { background: var(--btn-danger-bg); }  /* (0,2,1) */
```
The hover rule wins, so a danger button becomes `#fecdd3` text on `#f472b6` ->
**1.88:1**. Affected: `views/settings.php` (Log out everywhere, Disable
two-factor), `views/layout.php` (Logout).

**1.6** - `app/Core/theme_base.css:68`
```css
.chip input[type="checkbox"] { display: none; }
```
There is no override anywhere, and the `<label class="chip">` wrapper is not
focusable either. Affected: `views/settings.php:45-53` (the three e-mail
notification toggles) and `views/settings.php:290-293` (every favourite
category).

### Verify

A contrast script that reads the shipped palette, plus a CDP keyboard run at
390x844: Tab through the settings page and confirm every chip toggle takes
focus and shows a ring; hover a danger button and confirm it stays the danger
colour; open a locked gallery and confirm the gate headline is legible.

### What phase 1 changed

Six measured failures, all of them real:

| pair | before | after |
|---|---|---|
| the paywall headline | 1.60:1 | 15.93:1 |
| the focus ring (a non-text indicator) | 2.83:1 | 7.47:1 |
| a danger button on hover | 1.88:1 | 10.73:1 |
| the chat presence line | 3.93:1 | 11.32:1 / 11.82:1 |
| the guest teaser statistic | 1.71:1 | 15.53:1 |
| the chip checkboxes | unreachable | focusable, with a ring |

The check that holds them, `smoke.ui.contrast_floor`, **computes** the ratio
out of the palette the site ships and also asserts the declaration in the
view, so neither a palette change nor a view regression can slip through.
It grew an optional "must come after" needle, because the danger button was
a cascade bug and not a missing colour.

Phase 1 also found, while measuring, that `<button>` does not inherit the
font: the UA sets a font shorthand, so every button on the site was
rendering in Arial while its anchor twin rendered in the page font. `.btn`
now sets `font-family` and `line-height`.

---

## Phase 2 - broken code that already shipped

| id | what | files | status | commit |
|---|---|---|---|---|
| 2.1 | `window.GalleryNav` is defined **twice** - `views/layout.php:513` (toggle/close) and `public/assets/js/user.js:1484` (advanceNext) - and the second **overwrites** the first, so `window.GalleryNav.toggle()` no longer exists and the guest hamburger is dead | `views/layout.php`, `public/assets/js/user.js` | done | cfb4ab6 |
| 2.2 | The share bar's "Copy link" uses only `navigator.clipboard`; the shell already has `window.__copyText` with an `execCommand` fallback at `views/layout.php:640`. On plain HTTP (the dev box) the button silently does nothing | `views/partials/share-bar.php` | done | 35bc5ca |
| 2.3 | `fitGrids()` in the guest teaser is a no-op: it reads `getComputedStyle(grid).maxHeight`, which is `none` -> `parseFloat('none')` is `NaN` -> it returns early. `$guestMaxRows` is set by no controller either | `views/partials/guest_teaser.php` | done | c4429dc |
| 2.4 | `.collection-order` is defined **only** inside `@media(max-width:820px)`, so the desktop reorder buttons in `views/collections/show.php` are unstyled | `public/assets/css/user.css` | done | 29fdb9e |
| 2.5 | `table td:nth-child(1){overflow-wrap:anywhere;word-break:break-word}` is a **global** rule that hits every table on the member site - it was written for the category table | `public/assets/css/user.css` | done | da0159f |
| 2.6 | The image right-click / dragstart / Ctrl+S blocker applies to **every** image on the site, is not scoped and announces nothing; it breaks a legitimate "Save image as" | `public/assets/js/user.js` | done | ef3186f |

### What phase 2 changed

Six defects that had already shipped:

| id | what it did |
|---|---|
| 2.1 | the guest hamburger could not open - two modules owned `window.GalleryNav` |
| 2.2 | "Copy link" did nothing on a plain-http setup |
| 2.3 | the guest teaser fit never ran, so the login page was 25 thumbnails tall |
| 2.4 | the collection reorder buttons had no layout on a desktop |
| 2.5 | a rule written for the category names reached every table, including the admin's |
| 2.6 | the image protection blocked the site logo and the creator's avatar |

Every one of them was measured in headless Chrome before and after, not
read and assumed. The guest teaser is the clearest: the old code hid
nothing and left the grid at 750px, the new code drops the extra row
and no row is clipped at any of the four heights measured.

Two of the new checks are positional rather than a plain substring -
`smoke.ui.guest_teaser_fits` insists the fallback sits BETWEEN reading
the budget and giving up on it, and `smoke.ui.rules_not_stranded`
measures the brace depth at a rule's position. Both are the only way to
catch the bug they were written for.

---

## Phase 3 - token coherence

The member site stays dark only, so `app/Core/theme_base.css` (authored
**light**) plus the 145-line `body:not(.admin-theme)` patch block in
`public/assets/css/user.css:620-764` is pure structural debt. **The fix is to
invert it: make the dark stylesheet the base.**

| id | what | files | status | commit |
|---|---|---|---|---|
| 3.0 | Re-baseline `smoke.ui.inline_style_ratchet` **downward** (it is at 850 of a 853 limit, so there is no headroom for any new inline style) | `app/Core/SmokeChecks.php` | done | 4708322 |
| 3.1 | Extract the 22 `u-*` utilities into a new `public/assets/css/utilities.css` loaded by both shells. They are `body .x`-scoped and scope-agnostic. 30 of the ~40 admin views use them (96 times `u-right` alone) | `public/assets/css/utilities.css`, both layouts | done | 0af1fdc |
| 3.2 | **Stop loading `user.css` in the admin.** Move the Support Desk block (`public/assets/css/user.css:68-80`) into `admin.css` - the entire admin Support Desk is currently styled by the *member* stylesheet, wearing light-palette fallbacks by accident | `views/admin/layout.php`, `public/assets/css/admin.css`, `public/assets/css/user.css` | done | 0672771 |
| 3.3 | Remap `--table-border` / `--table-header-bg` for the admin scope. Unmapped, they stay `#f472b6` - **the System page's table headers are bright pink in a neutral grey console** | `public/assets/css/admin.css` | done | 01e8470 |
| 3.4 | Re-order the theme block so the **Theme editor's admin surface controls actually work**. `admin.css` loads *after* the inline theme block at equal specificity, so `--card-bg`, `--pink-100/200/300/400`, `--stat-bg` can never change | `views/admin/layout.php` | done | dede9a4 |
| 3.5 | One radius scale. `.sys-card` is 10px (`--adm-radius`) but `.page-system .sys-card` is **8px** (`var(--border-radius)`) - measured in headless Chrome | `public/assets/css/admin.css` | done | 0d7b087 |
| 3.6 | Delete `public/assets/admin-shared.css` (19 lines, ~90% dead - every rule is re-declared with different values in `admin.css`) | `public/assets/admin-shared.css` | done | bea22ee |
| 3.7 | Move the 577 hardcoded hexes in the views onto tokens, and delete the light-palette fallback literals (`var(--pink-100,#fdf4ff)`) that flash white on the dark skin | the views | done | bb7e3b0 |
| 3.8 | `app/Core/theme_base.css:5` centres **every** h1-h6. The member skin never overrides it, so ~15 `text-align:left` opt-outs in CSS and 7 inline in views fight it. Set the alignment in the base and delete the opt-outs | `app/Core/theme_base.css`, `public/assets/css/user.css`, the views | done | 9164dd9 |
| 3.9 | One type scale. The 6-step `u-fs-*` scale (hard-coded rem) duplicates `--font-size-*` with *different* values (`u-fs-085` = .85rem vs `--font-size-md` = .95rem). The system sans itself stays | `public/assets/css/user.css`, `app/Models/Theme.php` | done | b028d3e |

**3.2 is the load-bearing one** - it removes a whole class of leak bugs in a
single move. It is also the one that can bite: it changes the admin's entire
surface, so it gets its own commit and a visual diff over every admin page.

### What phase 3 changed

The token layer, in ten commits:

| id | what it did |
|---|---|
| 3.0 | the inline-style ratchet re-baselined at 847 (it was 850 of a limit of 853) |
| 3.1 | the `u-*` utilities moved into `utilities.css`, which both shells load |
| 3.2 | **the admin console no longer loads the member stylesheet**; the Support Desk became a shared `support.css` |
| 3.3 | the System page's rows were hot pink; the table tokens are remapped now |
| 3.4 | **the admin theme editor had 16 dead colour pickers**; the palette carries the console now |
| 3.5 | the admin had two radius scales; there is one now |
| 3.6 | the admin console has one stylesheet; `admin-shared.css` is gone |
| 3.7 | the member views carry no stray colours; two of them were unreadable |
| 3.8 | the headings were aligned in fifteen places at once |
| 3.9 | the font sizes come from one scale, so the editor moves them |

Two of these were verified by pixel diffing the dev box before and after
(phase 3.2), and two by driving the real form in a browser (3.4). The
rest were measured in headless Chrome.

**TWO CORRECTIONS TO THE 3.2 VERIFICATION.** Both were found later, in
phase 4, and both matter.

1. The first pixel diff showed the member pages 92% different and the
   admin 0.0000% different. The member pages were a measurement
   mistake: the scp that reverted the files copied two files with the
   same basename (`layout.php`) into one directory, so the ADMIN layout
   landed in the member slot. The admin result was valid; the member
   one was not. Re-run properly, the member's support page is 0.0000%
   identical and the member's gallery listing differs by less than two
   consecutive captures of the same state (it is personalised).

2. The claim that "the admin's layout is byte-identical with and
   without user.css" was **wrong**, and the test that produced it was
   broken. The test toggled the stylesheet link on the box and measured
   the page - but it did not reload php-fpm, so the opcache served the
   OLD layout both times and the two runs measured the same state.
   Re-run with the reload, the difference is real: the dashboard's
   table cells were 4.48px 6.08px with user.css and are 8.8px 12px
   without it. The member stylesheet's `body.site-compact th, td`
   rule was squeezing the admin's tables denser than the admin's own
   rule wanted, and the dashboard's tables were the only ones affected
   (the lists use .adm-list-table, which is more specific and won
   either way).

   The change is an improvement, not a regression: the admin's own
   padding is .55rem .75rem, the rest of the admin's tables use
   .6rem .75rem, and the dashboard's were the outliers. They now
   match. But the admin dashboard is TALLER than it was, and the
   original commit message said otherwise.

---

## Phase 4 - the component layer

| id | what | files | status | commit |
|---|---|---|---|---|
| 4.1 | Extend `AdminTable` to every admin list view | `app/Core/AdminTable.php`, the admin views | **partial** | - |
| 4.2 | Fix the **nested `.stat-cards` grid** on the admin dashboard: the outer grid's flex child is itself a grid, so the KPI band lays out wrong | `views/admin/dashboard.php` | done | fa1b403 |
| 4.3 | Split `views/admin/settings.php` (2 lines that `require` the **member** settings page - a second `<h1>`, member CSS classes, and a "Back to dashboard" link to `/account` inside the admin) into a real admin view | `views/admin/settings.php` | done | 2602a7b |
| 4.4 | Give `views/admin/logs.php` the `.adm-section` / `.sys-card` structure every other admin page has | `views/admin/logs.php` | done | 04277ba |
| 4.5 | Member side: one card partial (3 near-identical hand-rolled templates today), one button/form kit, and de-duplicate the wall comment thread (`views/wall.php:203-273` re-implements `views/partials/comments.php` verbatim) | `views/partials/`, `views/wall.php`, `views/collections/` | done | d84f2d9 |
| 4.6 | Define or delete the **dead classes**: 13 member (`.btn-link`, `.settings-form`, `.home-section`, `.home-hero`, `.home-upsell`, `.hero-kicker`, `.site-footer`, `.membership-gate`, `.share-bar`, `.star-rating`, `.page-gap`, `.security-card`, `.player-retry`) and 16 admin (`.table-responsive`, `.stats-period`, `.text-muted`, `.adm-kpi-band`, `.mg-check`, `.mg-import-settings`, `.mg-scheduled`, `.type-switch*`, `.adm-field-label`, `.emoji-btn`, `.user-check`, `.ts-cbx`, `.ts-table`, `.ts-grp-pass`, `.ap-row`, `.ap-countdown`) | the views, the stylesheets | done | 40f0497 |

**New checks:** `smoke.ui.admin_classes_defined` and
`smoke.ui.member_classes_defined`, mirroring the existing
`smoke.ui.utility_classes_defined`.

### What phase 4 changed

| id | what it did |
|---|---|
| 4.1 | **PARTIAL - see below** |
| 4.2 | the dashboard's KPI band was a grid inside a grid; the `qa-live` class had no style |
| 4.3 | the admin settings page is the admin's own, not the member's page with a second heading |
| 4.4 | the logs page had no structure at all; it has the shared structure now |
| 4.5 | the wall's comment thread was a verbatim copy of the shared one; there is one copy now |
| 4.6 | fifty-one classes were used in a view and defined nowhere; every one is styled or scripted now |

**4.1 IS NOT FINISHED, AND IT IS A BIGGER JOB THAN THE PLAN SAID.** The
plan said "extend AdminTable to every admin list view". There are **33
admin views with a bare table** - the system page alone has 13. Converting
each one means its own columns, filter form, bulk actions and pager, and
it is a multi-day job with a real risk of breaking 33 admin pages. What
was done instead is the part that pays off without rewriting every
table:

- the **empty states**: 26 hand-rolled ones now use the shared
  `admin_empty()` helper, so the console has ONE empty state instead of
  two that look nothing alike;
- the **dead classes**: 51 classes that no stylesheet defined and no
  script used, several of which were real bugs (a table that pushed the
  page sideways, a "small" button at full size, an unswitchable type
  toggle);
- the **home page**: the same flex section header written out four
  times, and five classes that had never been defined;
- the **comment thread**: one copy instead of two;
- the **logs page** and the **admin settings page**: the shared
  structure.

**WHAT 4.1 STILL OWES:** the 33 bare tables are still bare. The
`AdminTable` component is used by 6 admin views. The next step there is
to give `AdminTable::shell()` the variants the other views need, and to
convert them a page at a time - not all at once.

---

## Phase 5 - member product UX

The admin has a global search **and** a `Ctrl K` command palette. The member
site has neither - search only exists on `/galleries` and the category pages.

| id | what | files | status | commit |
|---|---|---|---|---|
| 5.1 | **Member global search** - a field in the rail, in the phone "More" sheet, and a `/` keyboard shortcut, pointing at `GET /galleries?q=`. The endpoint and the `q` parameter already exist (`GalleryController@index`) | `views/layout.php`, `public/assets/js/user.js` | done | 8e81636 |
| 5.2 | A member results view - the query, the filters and a clear-all. Today the header drops straight into the listing | `views/gallery/index.php` | pending | - |
| 5.3 | Home hero cleanup: 19 inline styles, 4 classes with **no CSS at all**, and the same flex section-header row copy-pasted 4 times | `views/home.php` | pending | - |
| 5.4 | Mobile nav parity - the guest and the member rail/bottom-bar paths still differ, and the member's bottom bar has no way back to the full nav on a tablet | `views/layout.php` | done | ec7e243 |
| 5.5 | The 10 dead member classes from 4.6, with the home hero | the views, the stylesheets | pending | - |

### What phase 5 changed

| id | what it did |
|---|---|
| 5.1 | the member site has a site-wide search: the rail, the guest's top bar and the phone's sheet, plus a `/` shortcut |
| 5.2 | the listing already showed "Searching for ... (clear)" - the search box shows the query too |
| 5.3 | the home hero's 19 inline styles and its four dead classes |
| 5.4 | a guest on a phone could not reach the search at all; the More button and the sheet are for everyone now |

**A NOTE ON THE HOME PAGE.** A member never sees it: `Auth::homePath()`
sends a member to /wall and an admin to /admin. The home page is the
GUEST landing page, and the guest's top bar is where the new search
lives. That is also why the guest's phone bottom bar needed the search
button: the top bar is not on a phone.

---

## New smoke checks (the house style: source-text assertions)

`smoke.ui.contrast_floor` (computes the ratio out of the shipped
palette), `smoke.ui.chips_reachable`, `smoke.ui.guest_hamburger_works`,
`smoke.ui.clipboard_fallback`, `smoke.ui.nav_globals_unique`,
`smoke.ui.no_user_css_in_admin`, `smoke.ui.admin_tokens_remapped`,
`smoke.ui.one_radius_scale`, `smoke.ui.one_admin_stylesheet`,
`smoke.ui.no_stray_colours`, `smoke.ui.one_alignment`,
`smoke.ui.one_type_scale`, `smoke.ui.utilities_shared`,
`smoke.ui.rules_not_stranded`, `smoke.ui.no_global_table_rule`,
`smoke.ui.no_nested_grids`, `smoke.ui.admin_settings_is_its_own`,
`smoke.ui.admin_pages_have_structure`, `smoke.ui.no_dead_classes`,
`smoke.ui.member_search`, `smoke.ui.guest_reaches_the_search`,
`smoke.ui.one_empty_state`, `smoke.comments.one_thread`.

**Every new check must be proven to bite**: revert the fix, watch it fail,
restore the fix. A check that has never failed is not a check.

---

## How to resume after a crash

1. `git -C /media/webdev/Media/gallery-full status` + `git log --oneline -5` -
   the uncommitted files are the work in flight, never throw them away.
2. Read the status table above and update it.
3. `/tmp/opencode` is **wiped by every reboot** - the CDP harness, `login.sh`
   and the probe scripts have to be recreated. The pattern: headless Chrome
   with `--remote-debugging-port`, driven by node 24 (which has `fetch` and
   `WebSocket` built in), using `Network.setCookie` (with `domain` and
   `path: '/'` - a URL-only cookie sets the path to that URL's path and the
   server reads the wrong cookie), `Input.dispatchTouchEvent` for real taps
   (`.click()` does not honour overlays) and `Page.captureScreenshot`.
4. Never `pkill -f <pattern>` from the ssh command line: the pattern matches
   the shell's own command line and kills the shell. Put the pattern in a
   `.sh`/`.bat` on the box instead.
5. The deploy script needs `DEPLOY_HOST`, `DEPLOY_PASS` and `DEPLOY_ROOT`, and
   it must run with a **>= 5 minute** timeout (a dev deploy takes 2-3 minutes;
   a shorter timeout kills a healthy deploy and rolls it back).
6. Prod smoke runs as `www-data` and needs `sudo rm -rf /tmp/gallery-analytics-rot`
   first, or `smoke.analytics.rotated_discovery` fails on a root-owned dir.
7. `php tests/smoke.php` -> 937 checks at the start of this programme,
   941 after phase 1, 948 after phase 2, 955 after phase 3 and 963 after
   phases 4 and 5. The count may only go up.
