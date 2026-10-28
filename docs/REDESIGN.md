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

- PHP view structure, routes, controllers, `Theme.php` pipeline, CSP.
- `AdminTable`, `gallery_card` / `wall_post` / `membership_gate` contracts
  (restyle, don't restructure).
- Navigation model (rail + bottom bar + guest top nav) — skin only.
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
| 5 | **dev-deployed, dev-verified** | (this commit, local only) | 1040/1040 | admin accent retinted to the brand fuchsia (`--adm-accent` → `var(--pink-500,#d946ef)`, strong → `--pink-700,#a21caf`; admin Theme.php palette pink-500/700/800 → fuchsia scale so the theme-editor pickers move with it); ink-on-accent everywhere text sits ON the fuchsia (btn-primary `#0a0210`, avatar initial ink — white is 3.3:1); KPI numbers (`.stat-card b` + `.kpi b`) on Playfair 600 with tabular-nums, labels stay Inter; tables already polished (sticky uppercase theads, hover rows, scroll wrappers) — no change needed; 390 fold verified (sw=cw=390, KPI grid 2-col). Guard `smoke.theme.admin_on_brand_tokens` proven to bite. **BOX TRAPS:** dev/prod hold `storage/admin-theme.json` (theme-editor snapshot) that OVERRIDES the const — patch its pink keys on the box too; and the theme CSS cache lives in **Redis** (`*theme*` keys), not storage/cache — flush with the REDIS_PASSWORD from .env after any Theme.php palette change. CDP: accent/pink-500 `#d946ef`, KPI font `"Playfair Display"` |
| 6 | pending | - | - | inline-style debt sweep + drift guard |
| Prod | **BLOCKED on user permission** | - | - | ask after all phases green on dev |
