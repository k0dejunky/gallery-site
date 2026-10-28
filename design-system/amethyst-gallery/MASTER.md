# Design System Master — Amethyst Gallery

> **LOGIC:** When building a page, first check `design-system/amethyst-gallery/pages/[page-name].md`.
> If that file exists, its rules **override** this Master file.
> If not, strictly follow the rules below.

**Project:** Amethyst Gallery
**Updated:** 2026-10-28 (round-4 redesign; supersedes the skill's generic auto-output)
**Scope:** Member site (OLED-deep immersive) + admin console (light dense, modernised)

The authoritative token source of truth is `app/Models/Theme.php`
(`MEMBER_COLORS` / `COLORS` / `LAYOUT_DEFAULTS`). This file documents the
*design intent*; Theme.php holds the *values*. When they disagree, Theme.php
wins and this file gets updated. Full programme notes: `docs/REDESIGN.md`.

---

## 1. Direction

The member site is a **premium late-night media app**: near-black canvas with
a violet undertone, content floating on quiet surfaces, purple used as *light*
(glow, rim, accent) rather than as paint. Headlines carry editorial weight
from a serif; everything interactive stays clean sans. The admin console is a
**fast, dense, light working tool** — same design language (type, spacing,
radii, motion), different palette (two scopes, exactly like Theme.php).

**Do not** reintroduce a light theme on the member site, a second button
system, or hex literals forked into CSS.

## 2. Member palette — "Amethyst OLED"

Emitted on `:root` via `/theme.css`. Key names are stable; values are the
OLED ladder.

| Role | Token | Hex |
|---|---|---|
| Canvas | `page-bg` | `#050208` |
| Glow | `page-glow` | `#2a0a3f` @ 6% (radial, top-centre, fixed) |
| Surface 1 | `surface-1` | `#0d0612` |
| Surface 2 | `surface-2` | `#150a1d` |
| Surface 3 | `surface-3` | `#1e0f2a` |
| Border | `border` | `#2a1638` |
| Border soft | `border-soft` | `#1c0f26` |
| Text | `text` | `#f5edf7` |
| Heading | `heading` | `#ffffff` |
| Muted | `muted` | `#b9a0c4` |
| Link | `link` | `#e9aefc` |
| Link hover | `link-hover` | `#fbcfe8` |
| Brand | `purple-500` | `#a855f7` |
| Brand light | `purple-300` | `#c084fc` |
| CTA | `btn-bg` | `#d946ef` (hover `#e879f9`) |
| On CTA | `btn-color` | `#0a0210` |
| Focus ring | `focus-ring` | `#c084fc` |
| Status online | `status-online` | `#4ade80` |
| Danger | `btn-danger-bg` | `#3b1024` (border `#f43f5e`, text `#fecdd3`) |

Legacy pairs that are GONE: indigo `#7375fa` + yellow `#faf56e`; the blue
`btn-danger-hover-bg #3c58fa` override in `storage/theme.json` (file removed).

## 3. Admin palette — "Console" (light)

Emitted on `.admin-theme`.

| Role | Value |
|---|---|
| `adm-bg` | `#f6f7fb` |
| `adm-surface` / `-2` / `-3` | `#ffffff` / `#f9fafc` / `#eef1f7` |
| `adm-border` / `-soft` | `#e2e5ee` / `#cfd4e2` |
| Text / muted / dim | `#1e2330` / `#5c6478` / `#8b93a7` |
| `adm-accent` | `#7c3aed` |
| Sidebar gradient | `#1a0b2e → #2e1065` |
| Status | ok `#15803d`, warn `#b45309`, err `#b42318`, info `#175cd3` (+ -bg/-soft pairs) |

## 4. Typography

- **Display:** Playfair Display 500/600/700 — `h1/h2`, gallery titles, hero,
  paywall headlines, wall-hero. Line-height `1.1`, slight negative tracking
  at `2xl`+.
- **Body/UI:** Inter 400/500/600/700 — nav, cards, tables, forms, buttons,
  chips, everything interactive.
- **Mono:** system mono — logs, secrets, promo codes only.
- Self-hosted latin-subset **woff2** under `public/assets/fonts/`;
  `@font-face` in `theme_base.css` with `font-display: swap`. No CDN at
  runtime. Budget < 120 KB total.
- Scale (both scopes): `2xs 11 / xs 12 / sm 13 / md 14 / base 15 / lg 17 /
  xl 21 / 2xl 28 / 3xl 36`.

## 5. Layout tokens

- **Radii:** `xs 6 / sm 10 / md 14 / lg 18 / xl 24 / pill 999`.
- **Spacing:** `2xs 4 / xs 8 / sm 12 / md 16 / lg 24 / xl 32 / 2xl 48 / 3xl 64`.
- **Shadows** (dark = rim + glow, not heavy drop):
  - `shadow-card`: inset highlight `0 1px 0 rgba(255,255,255,.04)` +
    `0 8px 32px rgba(0,0,0,.5)`.
  - `shadow-float`: `0 24px 64px rgba(0,0,0,.6)`.
  - `glow-brand` (hover/active only): `0 0 0 1px rgba(168,85,247,.35),
    0 0 24px rgba(168,85,247,.15)`.
- **Motion:** `fast 140 / base 200 / slow 320 ms`,
  easing `cubic-bezier(.2,.8,.2,1)`; everything gated on
  `prefers-reduced-motion: reduce`.
- **Breakpoints:** `sm 480 / md 640 / lg 768 / xl 1067 / 2xl 1440` (replaces
  the 14 scattered one-off admin widths).

## 6. Component rules

| Component | Rule |
|---|---|
| Buttons | ONE family: solid (fuchsia CTA), soft, ghost/outline, danger, link. Min height 40px (`sm` 32). Visible `:focus-visible` ring. Press = `scale(.98)` + brightness, no layout shift. |
| Gallery cards | `surface-1` + 1px `border` + `radius-lg`. Cover full-bleed in a rounded clip with `aspect-ratio` (no reserved 400×300 boxes). Hover: border → `rgba(168,85,247,.4)`, cover `scale(1.03)` inside the clip. Title in Playfair. |
| Chips/filters | Pill, `surface-3` default, brand-tinted active. |
| Inputs | `surface-3` fill, 1px border, `radius-md`, focus = brand ring + border. Labels always above. Error = ring + inline text (never colour-only). |
| Paywall | Centred glass panel over a blurred cover (backdrop-blur), Playfair headline, one primary CTA. Not a pink box. |
| Wall card | Already unified (round < this); retint to tokens; title Playfair. |
| Player | `page-bg` chrome at rest, controls fade when idle, menus are `surface-2` floats with `glow-brand` on the active item. |
| Empty states | One pattern: small icon, one line, one CTA. |
| Flash/toasts | Soft tinted panel, `radius-md`, slide top-right, auto-dismiss. |
| Admin KPI/tables | Keep `AdminTable`/`.adm-*` structure; retint; Inter everywhere; Playfair only on dashboard/page H1. Table rows 36px, sticky headers, hover row highlight. |

## 7. Accessibility (every phase)

- Body text ≥ 4.5:1, large/non-text ≥ 3:1, measured on the real surfaces
  (the smoke suite computes ratios — extend it, don't eyeball).
- Visible `:focus-visible` on everything interactive; `scroll-padding-top`
  so sticky UI never obscures focus (WCAG 2.2 AA).
- `prefers-reduced-motion` kills transforms/parallax.
- No colour-only state; labels on all fields; alt text on all images.
- No autoplay without pause.

## 8. Performance

- Fonts: latin subset, self-hosted, `swap`, < 120 KB.
- Images: keep AVIF/WebP `<picture>` + lazy; `aspect-ratio` boxes so CLS = 0.
- No heavy chrome; subtle 150–300 ms transitions only.

## 9. Anti-patterns (never)

- A light member theme or `prefers-color-scheme` toggle (member is dark-only).
- A second button token set / the old indigo+yellow pair.
- Hex literals in CSS (tokens only — smoke bites).
- Emojis as structural icons (use `Icon::svg`).
- Per-page `<style>` blocks or new inline `style=""` (drift-guard in phase 6).
- Restructuring `AdminTable` / partial contracts (restyle only).

## 10. Page-building context prompt

When building a page for this project:

> Read `design-system/amethyst-gallery/MASTER.md`. If
> `design-system/amethyst-gallery/pages/[page].md` exists, its rules override
> the Master. Follow the Amethyst OLED palette, Playfair+Inter typography,
> component rules and anti-patterns above. Token values come from
> `app/Models/Theme.php` — never fork hex into CSS.
