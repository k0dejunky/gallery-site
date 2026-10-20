# Site-wide UI upgrade plan

**Status file for the UI refactor.** It is the recovery anchor: if a session dies,
this file says exactly where the work stopped, what is already shipped, and what
the next step is. Update it (and commit it) at the end of every phase.

Origin: the user asked "what UI upgrades should be made to the whole site
(admin and user)" on 2026-10-19 and then "do it". Phases 0-7 below are that plan.

**Sequencing:** fix what's broken -> unify the tokens -> extract the CSS ->
rebuild the admin tables -> add the admin JS layer -> member nav/mobile -> a11y
polish. Verification follows the house rule every time:
**local -> dev -> prod -> commit last** (one commit per phase, push at the end).

---

## Where the work stands (updated 2026-10-19 23:00)

| Phase | What | Status | Commit |
|---|---|---|---|
| 0 | Visual baseline (CDP screenshot harness) | done | - (harness lives in /tmp) |
| 1 | Fix what's broken (12 small fixes) | done, shipped to prod | `edcfbcb` |
| 2 | One design system (theme editor drives the member site) | done, shipped to prod | `7900c97` |
| 3 | CSS out of the views (no inline `<style>`/style attributes) | done, shipped to prod | `82fc4fa` |
| 4 | Shared admin table component on every list view | done on dev+prod, local commit only | `08092c8` |
| 5 | `public/assets/js/admin.js` layer | **done and verified on dev**, not on prod yet | `aad944f`, `76ec2d9` |
| 6 | Member nav, guest mobile landing, paginated listings | pending | - |
| 7 | Accessibility polish pass | pending | - |
| Final | local smoke/unit -> dev -> prod -> commit + push | pending | - |

### The 22 local commits are NOT pushed yet
`git log origin/main..main` = 22 commits, latest `08092c8`. The house rule is
"push last", so the push happens after the prod verification of the last phase.
If a crash keeps happening, push the already-verified phases sooner - do not
lose a second day of work to a dead session.

### Box state
- **local** `/media/webdev/Media/gallery-full` - `main` @ `08092c8` + 6 modified files (Phase 5).
- **dev** `192.168.1.110` (`k0dejunky`) - Phase 4 + a **partial, buggy** Phase 5 (deployed 21:22 and 21:33 on 2026-10-19). The delete->undo toast flow does not work there yet (see below).
- **prod** `amethyst2213.com` (`ubuntu`) - Phase 4 deployed, **no Phase 5**. Prod smoke needs `sudo rm -rf /tmp/gallery-analytics-rot` and must run as `www-data`, otherwise `smoke.analytics.rotated_discovery` fails on a root-owned temp dir.

---

## Phase 5 - the admin JS layer (IN FLIGHT)

Scope: a styled confirm modal, an undo toast for reversible actions, inline row
editing, the drawer focus trap, optimistic table updates.

### What is already written (uncommitted)
- `public/assets/js/admin.js` - `AdminConfirm.ask()` (styled modal, focus trap,
  Escape, focus handed back), `adminAsk()` (falls back to the native confirm),
  `adminToast()` (with an "Undo" action), `postForm()`, `initUndoableDeletes()`,
  `initInlineEdit()` (a role change waits for a Save).
- `public/assets/css/admin.css` - the modal, toast and inline-pending styles.
- `views/admin/layout.php` - the delegation now asks with the modal instead of
  `window.confirm`; the double-confirm is handled with a `__admConfirmed` flag;
  the shell publishes the CSRF token in a `meta` tag and defines a global
  `adminAsk()` fallback.
- `views/admin/galleries.php` - the delete form carries `data-undo-url`,
  `data-undo-label`, `data-undo-message`.
- `app/Controllers/GalleryController.php` - `restore()` (undoes a soft delete)
  and a JSON answer for the delete when the caller wants JSON.
- `app/Core/Request.php` - `wantsJson()`.
- `config/routes.php` - `POST /admin/galleries/{id}/restore`.
- The native `confirm()` calls left in the admin views (abandoned, galleries,
  site_editor x3, theme) now use the styled modal.

### The bug that was open when the session died (FIXED, needs the dev check)
The CDP run showed: the modal opens and cancels correctly, but **the delete
never removes the row and no toast appears** (rows stay at 28, "toast: none").

Root cause (confirmed by reading the code, not guessed):
- The admin layout had **no `<meta name="csrf-token">`**, so
  `csrfToken()` in `admin.js` returned `''`.
- `postForm()` therefore posted `_token=` (empty) -> the router's
  `Csrf::verify($request->post('_token'))` failed -> **419** -> `r.ok` is false
  -> `window.location.reload()` -> the row is back and there is no toast.
- Second problem: `GalleryController::destroy()` answered with a **302** to
  `/admin/galleries`. `fetch()` followed it and downloaded the whole page.

The fix: the token is read from the form's own hidden `_token` input (with the
meta tag as the fallback), the shell publishes the token in a meta tag, and the
delete answers JSON when the request wants JSON.

### The rest of the Phase 5 plan
1. ~~Fix the CSRF + JSON delete~~ (done, see above).
2. Inline row editing (done): the role select is `data-autosubmit="manual"`,
   the layout skips the auto-submit for it and the editor asks for a Save.
3. Verify on dev with the CDP harness: delete -> row gone -> toast with Undo ->
   Undo -> row back; the modal traps focus and Escape cancels; the inline role
   edit saves only on Save.
4. `php tests/smoke.php` (899 checks now) + `php _suite_runner.php`, deploy to
   dev, re-verify, then commit.

**Smoke check added:** `smoke.ui.admin_js_layer` (the modal, the toast, the
inline editor, the CSRF meta, the undo route, the manual role select, and no
native `confirm()` left in an admin view). Proven to bite: reverting the role
select to `data-autosubmit` fails the check.

### Phase 5 verified on dev (2026-10-19 23:10)
CDP (headless Chrome against 192.168.1.110):
- the modal opens with the right title and text, the focus lands on the answer
  button, Tab wraps inside the dialog (Cancel -> OK -> Cancel) and Escape
  cancels it with nothing changed (28 rows);
- a delete removes the row in place (28 -> 27) and the toast reads
  `Deleted "Announce me cc8000".` with an Undo button;
- Undo posts to the restore route and the gallery is back (27 -> 28, and the
  form for that gallery is in the list again);
- the role select is `data-autosubmit="manual"`: a change shows Save / Cancel
  and tints the cell, nothing is written, Cancel puts the value back, and
  Save really writes it (user -> moderator -> user, confirmed with a fresh
  page load and with the database).
- The toast was white on white in the light theme; it now inverts with the
  theme (dark background, white text).

**Left for later (noted, not done):** the member site still asks with the
native `confirm()` (views/layout.php). The modal lives in admin.js/admin.css;
giving the member site the same treatment belongs with Phase 6/7.

---

## Phase 6 - member nav, mobile and listing scale
- One nav definition feeding both the rail and the bottom nav.
- A working guest landing on a phone.
- Paginating the galleries listing and the wall feed.
- The tour as a proper dialog that stops re-arming on every tab.

## Phase 7 - accessibility and polish
- `inert` everywhere it is needed.
- Colour-only status gets an icon + text.
- The age gate gets a real focus target.
- The admin topbar search gets a mobile replacement.
- ~12 URL-only admin pages get nav affordances.
- The users CSV export moves next to the user list.

## New smoke checks (source-text assertions, the house style)
`smoke.ui.admin_bulk_confirm`, `smoke.ui.css_vars_defined`,
`smoke.ui.guest_mobile_nav`, `smoke.ui.no_inline_style_blocks`,
`smoke.ui.admin_tables_paginated`.

---

## How to resume after a crash
1. `git -C /media/webdev/Media/gallery-full status` + `git log --oneline -5` -
   the uncommitted files are the work in flight, never throw them away.
2. Read the "Where the work stands" table above and update it.
3. `/tmp/opencode` is **wiped by every reboot** - the CDP harness, `login.sh`
   and the probe scripts have to be recreated (the pattern is in this file's
   "Phase 0" notes: headless Chrome + `--remote-debugging-port` + the CDP
   `Network.setCookie` / `Input.dispatchTouchEvent` / `Page.captureScreenshot`
   calls, driven by node 24 which has `fetch` and `WebSocket` built in).
4. Never `pkill -f <pattern>` from the ssh command line: the pattern matches
   the shell's own command line and kills the shell. Put the pattern in a
   `.sh`/`.bat` on the box instead.
5. The deploy script needs `DEPLOY_HOST`, `DEPLOY_PASS` and `DEPLOY_ROOT`, and
   it must run with a **>= 5 minute** timeout (dev deploys take 2-3 minutes;
   a shorter timeout kills a healthy deploy and rolls it back).
