# Site UI redesign (2026-10-28) — OLED member + modern admin

**Recovery anchor for this programme.** If a session dies, read this file first:
it says what shipped, what is in flight, and the exact next step. Update it at
the end of every phase (and commit it with that phase's work).

Companion docs: `UI-UPGRADE-PLAN.md` (round 1, closed), `SCAN-PLAN.md` (closed),
`UI-ROUND-3-PLAN.md` (round 3, closed). **This is round 4** — a visual
redesign, not another defect-fix programme. It SUPERSEDES round 3's
"system sans stays" decision: the user chose serif display + sans body
(Playfair Display + Inter), self-hosted, on 2026-10-28.

Origin: the user asked "how would you redesign the whole site" (2026-10-28),
then confirmed direction:
- **Scope:** member site AND admin console.
- **Member visual:** OLED-deep immersive (near-black canvas, purple as light).
- **Admin:** stays a light dense console, modernised (retint + type + polish).
- **Typography:** Playfair Display (display) + Inter (body/UI), self-hosted
  woff2, latin subset, `font-display: swap`.
- **CTA:** fuchsia `#d946ef` (kills the legacy indigo/yellow button pair).
- **Depth:** design system + components + key pages. Same PHP structure, no
  information-architecture rewrite.
- **Execution policy (user-mandated):** every phase deploys to **dev first**
  and is verified there. **Commits stay LOCAL ONLY** until the user grants
  production permission. No GitHub push until the prod gate. At the end I
  summarise the dev state and ASK for explicit prod permission.

---

## Design tokens (the source of truth)

All values live in `app/Models/Theme.php` (`MEMBER_COLORS` / `COLORS` /
`LAYOUT_DEFAULTS`). **Never fork hex literals into CSS** — the smoke suite
bites on that.

### Member palette — "Amethyst OLED" (`MEMBER_COLORS`)

| Role | Token | Value |
|---|---|---|
| Canvas | `page-bg` | `#050208` |
| Glow | `page-glow` | `#2a0a3f` @ 6% (subtle radial, top-centre, fixed) |
| Surface 1 | `surface-1` | `#0d0612` |
| Surface 2 | `surface-2` | `#150a1d` |
| Surface 3 | `surface-3` | `#1e0f2a` |
| Border | `border` | `#2a1638` |
| Border soft | `border-soft` | `#1c0f26` |
| Text | `text` | `#f5edf7` (≥7:1 on canvas) |
| Heading | `heading` | `#ffffff` (serif display only) |
| Muted | `muted` | `#b9a0c4` (≥4.5:1 on surfaces) |
| Link | `link` | `#e9aefc` |
| Brand | `purple-500` | `#a855f7` |
| CTA | `btn-bg` | `#d946ef` → hover `#e879f9` |
| On CTA | `btn-color` | `#0a0210` |
| Focus | `focus-ring` | `#c084fc` |

**Key names are PRESERVED** from the old palette (pink-*, purple-*, btn-*,
card-*, sidebar-*, table-*, pagination-*, …) so the 148 member views do not
break. Values change; names do not. New keys (`surface-1/2/3`) are added for
the new surface ladder.

**Deleted with the old palette:** the indigo `btn-bg #7375fa` + yellow
`btn-color #faf56e` legacy pair; the accidental blue `btn-danger-hover-bg`
in `storage/theme.json` (that override file is removed entirely in phase 1
so the built-in defaults win).

### Admin palette — "Console" (`COLORS`, stays light)

Modernised, not flipped: `adm-bg #f6f7fb`, surfaces `#ffffff/#f9fafc/#eef1f7`,
accent `#7c3aed`, sidebar deep purple-black gradient `#1a0b2e→#2e1065`,
status ok/warn/err/info pairs unified.

### Shared layout tokens (`LAYOUT_DEFAULTS`)

- Radii: `xs 6 / sm 10 / md 14 / lg 18 / xl 24 / pill 999`.
- Spacing: `2xs 4 / xs 8 / sm 12 / md 16 / lg 24 / xl 32 / 2xl 48 / 3xl 64`.
- Type scale: `2xs 11 / xs 12 / sm 13 / md 14 / base 15 / lg 17 / xl 21 / 2xl 28 / 3xl 36`.
- Shadows (dark = rim + glow, not drop): `shadow-card` (inner highlight +
  `0 8px 32px rgba(0,0,0,.5)`), `shadow-float`, `glow-brand`
  (`0 0 0 1px rgba(168,85,247,.35), 0 0 24px rgba(168,85,247,.15)`).
- Motion: `fast 140 / base 200 / slow 320 ms`, easing
  `cubic-bezier(.2,.8,.2,1)`, all behind `prefers-reduced-motion`.
- Breakpoints (kills the 14 scattered admin widths): `sm 480 / md 640 /
  lg 768 / xl 1067 / 2xl 1440`.

## Typography

- Display: **Playfair Display** 500/600/700 — `h1/h2`, gallery titles, hero,
  paywall headlines, wall-hero. Leading `1.1`, slight negative tracking large.
- Body/UI: **Inter** 400/500/600/700 — nav, cards, tables, forms, buttons.
- Self-hosted latin-subset woff2 under `public/assets/fonts/` (CSP-safe, no
  CDN). `@font-face` in `theme_base.css`, `font-display: swap`.
- Total added weight budget < 120 KB.

## Phases (each: implement → local smoke → dev deploy → dev verify)

| Phase | What ships | Files (typical) |
|---|---|---|
| 0 | This spec + design-system master persisted | `docs/REDESIGN.md` |
| 1 | Fonts + new tokens + theme_base refresh + drop `storage/theme.json` + new smoke checks | `public/assets/fonts/*`, `app/Models/Theme.php`, `app/Core/theme_base.css`, `app/Core/SmokeChecks.php` |
| 2 | Core components: button family, cards, chips, inputs, empty states, flash, paywall glass panel | `public/assets/css/{user,public,wall}.css`, `views/partials/{membership_gate,gallery_card}.php` |
| 3 | Member key pages: guest home hero, gallery show, membership, auth splash, error pages (kill the third light-pink skin) | `views/home.php`, `views/gallery/show.php`, `views/membership/index.php`, `views/partials/auth_shell.php`, `views/errors/*`, css |
| 4 | Wall retint + player chrome polish | `public/assets/css/wall.css`, `user.css` (player), `views/partials/wall_post.php` |
| 5 | Admin retint + type + KPI/table polish + fold breakpoints | `public/assets/css/admin.css` |
| 6 | Inline-style debt sweep (batched) + drift-guard smoke check | top views + `SmokeChecks.php` |

### Member-side redesign (phases 7-12 — approved Oct 28 2026)

The first six phases changed fonts, tokens and colours; the owner's report
was "all that changed was the font style." These phases restructure what
the user side LOOKS like: immersive media-first (cover heroes, cards with
title/meta over imagery behind scrims, glassy sticky header). Scope
decision: **full redesign incl. IA tweaks** — view markup and navigation
grouping may change; routes, controllers and PHP logic may not. Every
user-side surface is in scope.

| Phase | What ships | Files (typical) |
|---|---|---|
| 7 | Component kit: glass header, cover hero, media-led card, section head, filter bar, pills, stat chips, media frame + `--glass-bg`/`--scrim-*` tokens | `public/assets/css/user.css`, `app/Models/Theme.php`, `SmokeChecks.php` |
| 8 | Shell: sticky glass header (logo+nav), rail + bottom nav + footer polish; tour targets move with `tour_targets.php` | `views/layout.php`, css |
| 9 | Core browsing: home cover hero, media-first gallery cards, gallery show cover-hero header, listing filter toolbars | `views/home.php`, `views/gallery/*`, card partials |
| 10 | Membership pricing cards + immersive auth splash | `views/membership/index.php`, `views/auth/*` |
| 11 | Media viewing (image full, player), wall cards, favorites/collections/settings/support/chat | media views + secondary views |
| 12 | IA tweaks (nav grouping) + responsive/a11y hardening + full verification | `views/layout.php`, css |

## Verification (dev, extensive — user-mandated before prod)

- `php tests/smoke.php` on dev after every phase (all pass, count grows).
- md5 parity local↔dev for every deployed file.
- CDP screenshot journeys at 1280 + 390: guest home, login, gallery grid,
  locked-gallery paywall, wall, membership, player; admin dashboard,
  galleries list, chat, system.
- Real-content check on dev (existing galleries render with new cards).

## Prod gate

When all phases are green on dev: STOP, summarise (smoke counts + screenshots),
and **ask the user for explicit permission** to deploy to production. Only after
the yes: prod deploy, opcache wait (~75 s), prod smoke + parity + spot CDP,
then commit + push to GitHub.

**Prod post-steps (Theme.php changed in phases 1+5):** patch
`storage/admin-theme.json` pink-500/700/800 to the fuchsia pair (it overrides
the const), and flush Redis `*theme*` keys (password in `.env REDIS_PASSWORD`) -
otherwise the admin keeps serving the old palette from cache.

## Do-not-change list

Superseded in part by the Oct 28 "full redesign incl. IA tweaks" decision:

- Routes, controllers, PHP logic, the `Theme.php` pipeline, CSP headers,
  SEO markup (canonical/OG/JSON-LD), paywall honesty rules.
- View MARKUP may be restructured (phases 8-12), but every functional hook
  survives: `data-action`, `data-se-move-key`, `data-base/-user/-uid`, CSRF
  fields, lightbox/player hooks, and tour.js targets (move them in
  `partials/tour_targets.php` + `tour.js` together).
- `AdminTable` and the admin console stay as-is (admin out of scope;
  phases 0-6 already reskinned it).
- `membership_gate` / paywall semantics: restyle freely, never weaken.
- The admin stays light (a dark-admin pass is a future optional phase).

---

## Status table (updated at the end of every phase)

| Phase | Status | Commit | Dev smoke | Notes |
|---|---|---|---|---|
| 0 | **done** | (this commit) | - | spec + design-system master |
| 1 | **dev-deployed, dev-verified** | (phase 0-1 commit, local only) | 1036/1036 | fonts live, OLED tokens, override JSONs deleted, cache cleared; CDP 1280+390 green. Defer note: white cookie-consent banner — fixed in phase 2 |
| 2 | **dev-deployed, dev-verified** | (this commit, local only) | 1037/1037 | member component retint: consent banner (dark surface + fuchsia top rule), skip-link, support hover, labels/breadcrumbs/sort/nav/unread/progress text tokens, pressed-ctl chips (ink on purple), chat bubbles/link/image, dark bubble fallbacks, light-pink data-URI placeholders in gallery_card + guest_teaser. New guard `smoke.theme.member_components_off_the_light_skin` (12 selector assertions + blanket dark-purple-text ban + placeholder checks), proven to bite. CDP: banner bg rgb(21,10,29) text rgb(245,237,247) border rgb(217,70,239), sort/breadcrumbs muted, links #e9aefc — exact. FOUND (pre-existing, not phase 2): 390px phones scroll 63px horizontally — `.site-search-top` ends x452, `.title-logo img` 394, guest bottom-nav content 449 → fix in phase 3 |
| 3 | **dev-deployed, dev-verified** | (this commit, local only) | 1038/1038 | third light-pink skin killed (`.error-page` on OLED tokens); 390 phone no longer scrolls sideways (guest top search wraps to its own row, logo cap `min(380px,100%)` — bisect-proven: hiding `.site-search-top` dropped scrollWidth 453→394, the logo the last 4); standalone `auth/step_up` + `membership/braintree_checkout` retinted to dark literals (live dev 428 render verified); light-skin sweep zero hits on galleries/show/membership/login. Guards: `.error-page` + standalone-page needles inside `member_components_off_the_light_skin`, new `smoke.theme.mobile_no_horizontal_overflow` — both proven to bite. CDP: `sw=cw=iw=390`, banner whole at true 390, dark 404 card |
| 4 | **dev-deployed, dev-verified** | (this commit, local only) | 1039/1039 | admin console gets the self-hosted faces (`public/assets/css/fonts.css`, `../fonts/` urls, linked from `views/admin/layout.php`; body on `--font-family-body` Inter, page h1/.adm-h1 on `--font-family-display` Playfair, card h2/h3 stay Inter for density); player chrome retinted (#ff9fd4/#ffd0e8 → `var(--pink-500)`/`var(--purple-200)`, live-dot #ff4777 kept); wall confirmed already token-safe (hero purple-900→700 dark gradient, 0 light surfaces). Guards: `self_hosted_fonts` extended (fonts.css faces+urls+swap, admin layout link, admin.css token use), NEW `player_on_brand_tokens` — both proven to bite. CDP on dev: **both faces `loaded`**, h1 computed `"Playfair Display"`, played-bar+spinner `rgb(217,70,239)`, wall 0 light surfaces |
| 5 | **dev-deployed, dev-verified** | `7eb724b` | 1040/1040 | admin accent retinted to the brand fuchsia (`--adm-accent` → `var(--pink-500,#d946ef)`, strong → `--pink-700,#a21caf`; admin Theme.php palette pink-500/700/800 → fuchsia scale so the theme-editor pickers move with it); ink-on-accent everywhere text sits ON the fuchsia (btn-primary `#0a0210`, avatar initial ink — white is 3.3:1); KPI numbers (`.stat-card b` + `.kpi b`) on Playfair 600 with tabular-nums, labels stay Inter; tables already polished (sticky uppercase theads, hover rows, scroll wrappers) — no change needed; 390 fold verified (sw=cw=390, KPI grid 2-col). Guard `smoke.theme.admin_on_brand_tokens` proven to bite. **BOX TRAPS:** dev/prod hold `storage/admin-theme.json` (theme-editor snapshot) that OVERRIDES the const — patch its pink keys on the box too; and the theme CSS cache lives in **Redis** (`*theme*` keys), not storage/cache — flush with the REDIS_PASSWORD from .env after any Theme.php palette change. CDP: accent/pink-500 `#d946ef`, KPI font `"Playfair Display"` |
| 6 | **dev-deployed, dev-verified** | `8563216` | 1041/1041 | every inline `style="..."` attribute in the views now references a palette token instead of forking a value out of it. 18 files: admin views swept (input borders `#d1d5db` → `var(--adm-border-strong)`, status colours → `var(--adm-ok/warn/err)`, callouts → `--adm-*-bg/border`, the `#7c3aed` purge button → `var(--adm-accent)` — the old-accent drift case, `#000` veil → `--adm-media-veil`, `#fff` → `--adm-inverse`, impersonation banner → `--adm-err-deep`/`--adm-inverse`, `var(--text-muted)` → `--adm-text-dim`, legacy `var(--success/danger/red-600)` → `--adm-ok/err`), member `auth/partials/signup_form` error → `var(--flash-error-color)` (light pink on OLED; `#b91c1c` was near-invisible). admin.css gained 3 fixed-relationship tokens (`--adm-inverse`, `--adm-err-deep`, `--adm-media-veil`). TWO guards: new `smoke.theme.inline_styles_on_tokens` (recursive `views/**` walk, skips `views/emails/` — email HTML must inline colours — and allowlists `views/admin/site_editor.php`, a self-contained dark IDE tool; strips legal `var(--x,#hex)` fallbacks where the var IS defined, flags any surviving hex AND any legacy var that is undefined on the admin scope) and `smoke.ui.no_stray_colours`'s glob upgraded to the same recursive walk (the old `views/*/*.php` stopped at depth 2 and missed `auth/partials/signup_form` for months). Both guards bite-tested (bare hex, legacy `--danger`, nested member hex → named FAILs, restore → green). CDP on dev: input border `rgb(207,212,226)` == `--adm-border-strong`, 0 hex spans on galleries (30 on `--adm-ok` → `rgb(21,128,61)`), 0 hex left on auto-poster, new tokens + Playfair KPI regression green, guest `/signup` resolves `--flash-error-color #fecdd3`. Local 1041/1041, suite 1120/6 (known env-only), dev 1041/1041, deploy RC=0 md5 parity |
| 7 | **dev-deployed, dev-verified** | `4f6d391` | 1042/1042 | member component kit — primitives for media-first redesign. Tokens: glass-bg, scrims. Kit: page-shell, glass-header, hero-cover, card-led, media-first card, badges, pills, section-head, filter-bar, buttons, stat-chips, media-frame, grid-ratio. Guard `smoke.theme.member_component_kit` |
| 8 | **dev-deployed, dev-verified** | `3fe596b` | 1043/1043 | shell: sticky glass header (`views/layout.php`) replacing stacked `.title-header` block + pill nav; hamburger contract intact; error shell retains `.title-header`; `body.impersonating .glass-header` hidden. Guard `smoke.theme.glass_header_shell` proven to bite. Deployed dev RC=0 |
| 9 | **dev-deployed, dev-verified** | `3fe596b` | 1043/1043 | core browsing: home hero (`views/home.php`) on `.hero-cover` kit component, media-first gallery cards (`views/partials/gallery_card.php`) on `.card-led` with title-on-image scrim + hover zoom, listing filter toolbars (`views/gallery/index.php`) on `.filter-bar`. Deployed dev RC=0 |
| 10 | **dev-deployed, dev-verified** | `70b13ae` | 1044/1044 | membership pricing cards + immersive auth splash. `theme_base.css` `.auth-panel` retinted to the OLED card (surface-2 bg, `--border`, `--shadow-card`, heading token — `text-align:center` kept for the `one_alignment` pin); the panel wraps the login/signup splash AND the whole membership page, so both pages go immersive in one rule. auth_shell hero heading on Playfair `--font-family-display`; both auth submit buttons → `.btn.btn-grad`. Membership page: `.plans-grid` + `.plan-card` (kit tokens, `user.css` phase-10 section — a NEW view `<style>` block is forbidden by the `no_inline_style_blocks` ratchet), Playfair plan names + `.plan-price`, Gold tier gets `.plan-popular` + `pill pill-accent` "Most popular", Subscribe/Pay-by-card CTAs → `.btn-grad`; ALL payment markup untouched (PayPal `data-pp-button`, Braintree gating, `levelCounts`, `checkout_processor`, chat alias). New guard `smoke.theme.auth_splash_and_plans_on_tokens` (stylesheet + view needles + payment-string regression) proven to bite. TRAP found: `smoke.ui.no_inline_style_blocks` walks `views/*/*.php` (depth 2) — membership/index.php caught, kept the styles in user.css instead. Verified on dev: `/gallery/login` btn-grad; `/gallery/membership` 5 plan-cards + 1 Most popular pill; served theme.css carries the new `.auth-panel`; dev smoke 1044/1044. Local suite 1123/6 (known env-only) |
| 11 | **dev-deployed, dev-verified** | `0834f43` | 1045/1045 | media viewing + secondary pages on the kit. Image full + video player: the figure becomes `.media-viewer` (surface-2 stage card, radius, border), figcaption `.media-meta`/`.media-caption`, save-to-collection card `.save-form`, report link `.media-report`; player playlist count `.pl-count`, browse note `.pb-note`, "More like this" on `.section-head`. Favorites/collections (index + show + public share): `.page-shell` measure + `.section-head` rhythm, create card `.collection-create`, cards `.collection-card`, share/rename `.collection-panel`, media rows `.collection-media-*` (title/duration/actions/remove) — every one-off inline style gone. Settings: `.settings-card-accent` (fuchsia left rule), `.settings-fields-1/3`, `.settings-actions`, `.settings-hr`, `.settings-apply`, `.settings-h3`, `.settings-select`, `.settings-code`. Support contact/thread on `.page-shell`. Chat: `.chat-shell` measure, questionnaire panels `.qn-panel`/`.qn-*` (answered → `.qn-panel-done`), daily broadcast `.chat-daily`, attachment link/attach button/file input classes — `role=log`, the 25 MB guard and the typing ping untouched. NEW guard `smoke.theme.media_and_secondary_pages_on_kit` (9 views, required + banned inline needles, 12 stylesheet selectors) proven to bite. BUG FOUND LIVE: `body:not(.admin-theme) .card` (card polish, specificity 0,2,1) overrode the accent's `border-left` (0,1,0) — fixed to `body:not(.admin-theme) .settings-card-accent` and pinned in the guard; CDP now `rgb(217,70,239)`. FOUND (phase 8 carry-over, phase 12): `.glass-header` is 21px wider than the viewport at every width (negative margins vs body padding) → 10px horizontal scroll on member pages; dev photo 95 has no `web` rendition (data, not code). CDP dev: viewer bg `rgb(21,10,29)` radius 14, meta `rgb(185,160,196)`, page-shell max 1216px, chat-shell 992px, collections panel/card surface-2, settings accent fuchsia + 3-col fields. Local 1045/1045, suite 1124/6 (known env-only), dev 1045/1045, deploy RC=0 md5 parity |
| 12 | **dev-deployed, dev-verified** | (this commit, local only) | 1045/1045 | responsive/a11y hardening + IA. **CATCH-UP FIRST:** the phase 8/9 work was deployed and verified on dev but never committed (docs falsely said "(local commit)") — committed as `3fe596b` after re-verifying dev md5 parity for all 4 files; `views/partials/comment_thread.php` (one-line emoji decode fix, also dev-matched) left dirty on purpose. **THE PHASE-11 OVERFLOW (root cause + fix):** `body.site-compact` sets side padding `.85rem` (13.6px) while `.glass-header` hard-coded `margin:0 -1.5rem; padding:.15rem 1.5rem` (24px) → the bar overshot ~21px → every member page scrolled 10px sideways at every width (390: sw 400/cw 390; 1280: sw 1275/cw 1265). New body token `--page-pad-x` (1.5rem default, `.85rem` on site-compact) now feeds BOTH the body padding and the header's `calc(-1 * var(--page-pad-x))` margin / `var(--page-pad-x)` padding. Guard `smoke.theme.glass_header_shell` extended with the 3 token needles — bite-proven (margin reverted to `-1.5rem` → named FAIL). **IA:** the member rail's Browse group gains Videos + Safe for work (mirroring the guest rail; they flow into the phone's "More" sheet automatically); the Galleries entry now lights only on the unfiltered catalogue (`$currentType === ''`). CDP on dev: sw=cw at 390 (390/390) AND 1280 (1265/1265) on /galleries, /wall, /collections as a member, and on guest / + /galleries and auth /login (ml=-24px auth, -13.6px member, headerW==cw exactly); rail shows Dashboard/Galleries/Videos/Safe for work/Favorites/...; screenshots `/tmp/opencode/shot/p12-galleries-{1280,390}.png`. Local smoke 1045/1045, suite 1124/6 (known env-only), dev deploy RC=0 md5 parity, dev smoke 1045/1045 |
| Prod | **BLOCKED on user permission** | - | - | ask after all phases green on dev |
