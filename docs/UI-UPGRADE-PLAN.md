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
| 5 | `public/assets/js/admin.js` layer | **IN FLIGHT** (uncommitted, 6 files) | - |
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
  `adminToast()` (with an "Undo" action), `postForm()`, `initUndoableDeletes()`.
- `public/assets/css/admin.css` - the modal + toast styles.
- `views/admin/layout.php` - the delegation now asks with the modal instead of
  `window.confirm`; the double-confirm is handled with a `__admConfirmed` flag.
- `views/admin/galleries.php` - the delete form carries `data-undo-url`,
  `data-undo-label`, `data-undo-message`.
- `app/Controllers/GalleryController.php` - `restore()` (undoes a soft delete).
- `config/routes.php` - `POST /admin/galleries/{id}/restore`.

### The bug that was still open when the session died
The CDP run showed: the modal opens and cancels correctly, but **the delete
never removes the row and no toast appears** (rows stay at 28, "toast: none").

Root cause (confirmed by reading the code, not guessed):
- The admin layout has **no `<meta name="csrf-token">`**, so
  `csrfToken()` in `admin.js` returns `''`.
- `postForm()` therefore posts `_token=` (empty) -> the router's
  `Csrf::verify($request->post('_token'))` fails -> **419** -> `r.ok` is false
  -> `window.location.reload()` -> the row is back and there is no toast.
- Second problem: `GalleryController::destroy()` answers with a **302** to
  `/admin/galleries`. `fetch()` follows it and downloads the whole page (works
  by accident, wasteful, and it also drops the flash message semantics).

### Fix plan for Phase 5 (do these in order, commit after the checks pass)
1. Read the CSRF token from the form's own hidden `_token` input
   (`form.querySelector('input[name="_token"]')`), falling back to the meta tag.
   Do the same for the toast's Undo post (pass the token through).
2. Add `<meta name="csrf-token" content="...">` to `views/admin/layout.php`
   (and the member layout if the member JS ever needs it) so other scripts can
   use it.
3. Make `GalleryController::destroy()` answer JSON when the request wants JSON
   (add a small `wantsJson()` helper on the base `Controller`; there is no
   `Request::wantsJson()` today) - `{ok:true,id:N}` - and keep the 302 + flash
   for the plain form post.
4. Add the inline row editing (the plan's "a role change is not an arrow-key
   accident"): the users table role cell becomes a select that saves on change
   instead of a form submit.
5. Verify on dev with the CDP harness: delete -> row gone -> toast with Undo ->
   Undo -> row back; the modal traps focus and Escape cancels; inline role
   edit saves and reloads the row.
6. `php tests/smoke.php` (898 checks now) + `php _suite_runner.php`, deploy to
   dev, re-verify, then commit.

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
